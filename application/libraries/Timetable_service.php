<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Service_exception.php';
require_once APPPATH . 'libraries/Entity_firestore_sync.php';
require_once APPPATH . 'libraries/Time_helper.php';

/**
 * Timetable_service — owns timetableSettings + auto-generation.
 *
 * Phase 5: extracted from Academic.php with ZERO behavior change.
 * Phase 0 invariants preserved:
 *   - Canonical schoolId in writes (B-1 fix)
 *   - Atomic batched commit on auto-gen (B-2 fix)
 *   - generationId / generatedByUid / version stamps (B-7)
 *   - Manual-edit guard with force-overwrite escape hatch (B-4)
 *   - canonical camelCase + legacy mirror keys for settings (B-22)
 * Phase 4 audit logging.
 *
 * NOTE — Phase 5 scope:
 *   This service owns: getSettings, saveSettings, autoGenerate, getMasterTimetable.
 *   Single-cell editing (save_period), bulk section save (save_section_timetable)
 *   and conflict detection (detect_conflicts) remain in the controller for now —
 *   they touch many cross-domain helpers and are slated for a follow-up sweep.
 */
class Timetable_service
{
    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var string */ private $schoolId  = '';
    /** @var string */ private $session   = '';
    /** @var string */ private $adminId   = '';
    /** @var string */ private $adminName = '';
    /** @var object|null */ private $audit = null;
    /** @var bool   */ private $ready = false;

    const COLLECTION_TT  = 'timetables';
    const COLLECTION_SET = 'timetableSettings';

    public function init(
        $firebase, $fs,
        string $schoolId, string $session,
        string $adminId, string $adminName,
        $auditLogService = null
    ): self {
        $this->firebase  = $firebase;
        $this->fs        = $fs;
        $this->schoolId  = $schoolId;
        $this->session   = $session;
        $this->adminId   = $adminId;
        $this->adminName = $adminName;
        $this->audit     = $auditLogService;
        $this->ready     = ($firebase !== null && $schoolId !== '');
        return $this;
    }

    public function isReady(): bool { return $this->ready; }

    // ══════════════════════════════════════════════════════════════════
    //  SETTINGS (Period Scheduling)
    // ══════════════════════════════════════════════════════════════════

    public function getSettings(): array
    {
        $fsDocId = "{$this->schoolId}_{$this->session}";
        $settings = [];
        try {
            $fsDoc = $this->firebase->firestoreGet(self::COLLECTION_SET, $fsDocId);
            if (is_array($fsDoc) && !empty($fsDoc)) $settings = $fsDoc;
        } catch (\Throwable $e) {
            log_message('error', 'getSettings read failed: ' . $e->getMessage());
        }
        if (!is_array($settings)) $settings = [];

        $startTime    = $settings['startTime']      ?? $settings['Start_time']      ?? '9:00AM';
        $endTime      = $settings['endTime']        ?? $settings['End_time']        ?? '3:00PM';
        $noOfPeriods  = (int)($settings['periodsPerDay']  ?? $settings['No_of_periods']    ?? 6);
        $periodLength = (float)($settings['lengthOfPeriod'] ?? $settings['Length_of_period'] ?? 45);

        $recesses = [];
        $recessSrc = $settings['recesses'] ?? $settings['Recesses'] ?? null;
        if (is_array($recessSrc)) {
            foreach ($recessSrc as $r) {
                if (!is_array($r)) continue;
                $after = $r['afterPeriod']  ?? $r['after_period'] ?? null;
                $dur   = $r['durationMin']  ?? $r['duration']     ?? null;
                if ($dur === null) continue;
                $recesses[] = [
                    'after_period' => $after !== null ? (int)$after : null,
                    'duration'     => (int)$dur,
                ];
            }
        } elseif (isset($settings['Recess_breaks']) && is_array($settings['Recess_breaks'])) {
            foreach ($settings['Recess_breaks'] as $range) {
                if (!is_string($range) || strpos($range, '-') === false) continue;
                $parts = array_map('trim', explode('-', $range));
                $fromMin = Time_helper::timeToMinutes($parts[0]);
                $toMin   = Time_helper::timeToMinutes($parts[1]);
                if ($toMin > $fromMin) {
                    $recesses[] = ['after_period' => null, 'duration' => $toMin - $fromMin];
                }
            }
        }

        $defaultDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $workingDays = $settings['workingDays']  ?? $settings['Working_days'] ?? $defaultDays;
        if (!is_array($workingDays) || empty($workingDays)) $workingDays = $defaultDays;

        return [
            'start_time'       => $startTime,
            'end_time'         => $endTime,
            'no_of_periods'    => $noOfPeriods,
            'length_of_period' => $periodLength,
            'recesses'         => $recesses,
            'working_days'     => $workingDays,
        ];
    }

    public function saveSettings(
        string $startRaw, string $endRaw,
        int $periods,
        $recessRaw, $daysRaw
    ): array {
        $recesses = is_string($recessRaw) ? json_decode($recessRaw, true) : $recessRaw;
        if (!is_array($recesses)) $recesses = [];

        $workingDays = is_string($daysRaw) ? json_decode($daysRaw, true) : $daysRaw;
        $allDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        if (!is_array($workingDays) || empty($workingDays)) {
            $workingDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        }
        $workingDays = array_values(array_intersect($workingDays, $allDays));

        if ($startRaw === '' || $endRaw === '' || $periods <= 0) {
            throw new Service_exception('Start time, end time, and number of periods are required', 'validation');
        }

        $startMin = Time_helper::timeToMinutes($startRaw);
        $endMin   = Time_helper::timeToMinutes($endRaw);
        if ($endMin <= $startMin) {
            throw new Service_exception('End time must be after start time', 'validation');
        }

        $cleanRecesses = [];
        $recessMinutes = 0;
        foreach ($recesses as $r) {
            if (!is_array($r)) continue;
            $dur   = (int)($r['duration'] ?? 0);
            $after = (int)($r['after_period'] ?? 0);
            if ($dur > 0 && $after > 0 && $after < $periods) {
                $cleanRecesses[] = ['after_period' => $after, 'duration' => $dur];
                $recessMinutes += $dur;
            }
        }
        $available = $endMin - $startMin - $recessMinutes;
        if ($available <= 0) {
            throw new Service_exception('Recess duration exceeds available time', 'validation');
        }
        $periodLength = round($available / $periods, 1);

        $startAmPm = Time_helper::toAmpm($startRaw);
        $endAmPm   = Time_helper::toAmpm($endRaw);

        $cleanRecessesCanonical = array_map(
            fn($r) => ['afterPeriod' => (int)$r['after_period'], 'durationMin' => (int)$r['duration']],
            $cleanRecesses
        );

        $now = date('c');
        $data = [
            'startTime'        => $startAmPm,
            'endTime'          => $endAmPm,
            'periodsPerDay'    => $periods,
            'lengthOfPeriod'   => $periodLength,
            'recesses'         => array_values($cleanRecessesCanonical),
            'workingDays'      => $workingDays,
            'Start_time'       => $startAmPm,
            'End_time'         => $endAmPm,
            'No_of_periods'    => $periods,
            'Length_of_period' => $periodLength,
            'Recesses'         => array_values($cleanRecesses),
            'Working_days'     => $workingDays,
        ];

        $fsDocId = "{$this->schoolId}_{$this->session}";
        $fsData = array_merge($data, [
            'schoolId'      => $this->schoolId,
            'session'       => $this->session,
            'updatedAt'     => $now,
            'updatedByUid'  => $this->adminId,
            'updatedByName' => $this->adminName,
        ]);
        $this->firebase->firestoreSet(self::COLLECTION_SET, $fsDocId, $fsData);

        $this->_audit('update', 'timetableSettings', $fsDocId, null, [
            'startTime' => $startAmPm, 'endTime' => $endAmPm,
            'periodsPerDay' => $periods, 'lengthOfPeriod' => $periodLength,
        ], []);

        return ['length_of_period' => $periodLength, 'settings' => $data];
    }

    // ══════════════════════════════════════════════════════════════════
    //  AUTO-GENERATE TIMETABLE (Phase 0 hardened)
    // ══════════════════════════════════════════════════════════════════

    public function autoGenerate(bool $confirm, bool $forceOverwrite, string $scopeClass = ''): array
    {
        @set_time_limit(300);

        $session = $this->session;

        // 1. Read settings
        $settings = null;
        try {
            $settings = $this->firebase->firestoreGet(self::COLLECTION_SET, "{$this->schoolId}_{$session}");
        } catch (\Throwable $e) {}
        if (!is_array($settings)) $settings = [];

        $periodsPerDay = (int)($settings['periodsPerDay'] ?? $settings['No_of_periods'] ?? 0);
        if ($periodsPerDay <= 0) {
            throw new Service_exception('Period Scheduling not configured. Set periods per day first.', 'validation');
        }
        $workingDays = $settings['workingDays'] ?? $settings['Working_days'] ?? null;
        if (!is_array($workingDays)) $workingDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $startTime  = $settings['startTime']      ?? $settings['Start_time']      ?? '09:00AM';
        $periodLen  = (float)($settings['lengthOfPeriod'] ?? $settings['Length_of_period'] ?? 45);
        $recessSrc  = $settings['recesses'] ?? $settings['Recesses'] ?? [];
        if (!is_array($recessSrc)) $recessSrc = [];
        // Normalise recess shape for _computePeriodTimes (legacy form expected).
        $recesses = [];
        foreach ($recessSrc as $r) {
            if (!is_array($r)) continue;
            $after = $r['afterPeriod'] ?? $r['after_period'] ?? null;
            $dur   = $r['durationMin'] ?? $r['duration']     ?? null;
            if ($dur === null) continue;
            $recesses[] = ['after_period' => $after !== null ? (int)$after : null, 'duration' => (int)$dur];
        }

        $periodTimes = Time_helper::computePeriodTimes($startTime, $periodsPerDay, $periodLen, $recesses);

        // 2. Read all subject assignments via SAS
        $ci = function_exists('get_instance') ? get_instance() : null;
        $sas = null;
        if ($ci) {
            $ci->load->library('subject_assignment_service', null, 'sas');
            if (!$ci->sas->isReady()) {
                $ci->sas->init($this->fs, $this->firebase, $this->schoolId, $this->schoolId, $session);
            }
            $sas = $ci->sas;
        }

        $sectionDocs = $this->fs->schoolWhere('sections', [['session', '==', $session]]);
        $sections = [];
        foreach ($sectionDocs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $cn = $d['className'] ?? '';
            $sc = $d['section']   ?? '';
            if ($cn === '' || $sc === '') continue;
            if ($scopeClass !== '' && $cn !== $this->_normalizeClassLabel($scopeClass)) continue;
            $sections[] = ['className' => $cn, 'section' => $sc, 'key' => "{$cn}/{$sc}"];
        }
        if (empty($sections)) {
            throw new Service_exception('No sections found. Create classes and sections in School Config first.', 'not_found');
        }

        $allByClass = $sas ? $sas->getAllForSession() : [];
        $sectionAssignments = [];
        foreach ($sections as $sec) {
            $cn = $sec['className']; $sc = $sec['section']; $sk = $sec['key'];
            $matched = [];
            foreach (($allByClass[$cn] ?? []) as $a) {
                $aSec = $a['section'] ?? '';
                if ($aSec === $sc || $aSec === '') {
                    $pw = (int)($a['periodsPerWeek'] ?? 0);
                    if ($pw <= 0) continue;
                    $matched[] = [
                        'subject'        => ($a['subjectName'] ?? '') ?: ($a['subjectCode'] ?? ''),
                        'code'           => $a['subjectCode'] ?? '',
                        'teacherId'      => $a['teacherId'] ?? '',
                        'teacherName'    => $a['teacherName'] ?? '',
                        'periodsPerWeek' => $pw,
                    ];
                }
            }
            $sectionAssignments[$sk] = $matched;
        }

        // 3. Greedy placement
        $hardSubjects = ['Mathematics','Math','Science','Physics','Chemistry','Biology','English'];
        $grid = [];
        $teacherBusy = [];
        foreach ($sections as $sec) {
            $sk = $sec['key'];
            $items = $sectionAssignments[$sk] ?? [];
            if (empty($items)) continue;
            $subjectSlots = [];
            foreach ($items as $item) {
                for ($i = 0; $i < $item['periodsPerWeek']; $i++) $subjectSlots[] = $item;
            }
            usort($subjectSlots, function ($a, $b) use ($hardSubjects) {
                $aHard = in_array($a['subject'], $hardSubjects) ? 0 : 1;
                $bHard = in_array($b['subject'], $hardSubjects) ? 0 : 1;
                if ($aHard !== $bHard) return $aHard - $bHard;
                return $b['periodsPerWeek'] - $a['periodsPerWeek'];
            });
            foreach ($workingDays as $day) {
                $grid[$sk][$day] = array_fill(0, $periodsPerDay, null);
            }
            $daySubjectCount = [];
            foreach ($workingDays as $day) $daySubjectCount[$day] = [];
            foreach ($subjectSlots as $slot) {
                $subj = $slot['subject']; $tid = $slot['teacherId'];
                $candidates = [];
                foreach ($workingDays as $di => $day) {
                    $dayCount = $daySubjectCount[$day][$subj] ?? 0;
                    for ($p = 0; $p < $periodsPerDay; $p++) {
                        if ($grid[$sk][$day][$p] !== null) continue;
                        if ($tid !== '' && isset($teacherBusy[$tid][$day][$p])) continue;
                        $candidates[] = ['day' => $day, 'period' => $p, 'score' => $dayCount * 100 + $p];
                    }
                }
                usort($candidates, fn($a, $b) => $a['score'] - $b['score']);
                if (!empty($candidates)) {
                    $best = $candidates[0];
                    $grid[$sk][$best['day']][$best['period']] = [
                        'subject' => $subj, 'teacherId' => $tid, 'teacherName' => $slot['teacherName'], 'type' => 'class',
                    ];
                    if ($tid !== '') $teacherBusy[$tid][$best['day']][$best['period']] = $sk;
                    $daySubjectCount[$best['day']][$subj] = ($daySubjectCount[$best['day']][$subj] ?? 0) + 1;
                }
            }
        }

        // 4. Build output + atomic batch
        $result = [
            'sections_generated' => 0, 'conflicts' => 0, 'unallocated' => 0,
            'manual_skipped' => 0, 'manual_overwritten' => 0, 'manual_cells' => [],
            'timetable' => [],
        ];

        $generationId  = 'GEN_' . date('YmdHis') . '_' . substr(uniqid(), -6);
        $now           = date('c');

        $existingByDocId = [];
        try {
            $existingDocs = $this->firebase->firestoreQuery(self::COLLECTION_TT, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $session],
            ], null, 'ASC', 1000);
            foreach ($existingDocs as $doc) {
                $d  = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $idx = $doc['id'] ?? $d['id'] ?? '';
                if ($idx !== '') $existingByDocId[$idx] = $d;
            }
        } catch (\Throwable $e) {
            log_message('error', 'autoGenerate existing-doc preload failed: ' . $e->getMessage());
        }

        $batchOps = [];
        foreach ($grid as $sk => $days) {
            $parts = explode('/', $sk);
            $className = $parts[0] ?? ''; $sectionName = $parts[1] ?? '';
            $cs = \Entity_firestore_sync::normalizeClassSection($className, $sectionName);
            $canonClass = $cs['className'] ?: $className;
            $canonSection = $cs['section'] ?: $sectionName;
            $sectionKey = "{$canonClass}/{$canonSection}";

            $sectionData = [];
            foreach ($days as $day => $periods) {
                $periodDocs = []; $freeCount = 0;
                foreach ($periods as $pi => $cell) {
                    $time = $periodTimes[$pi] ?? ['start' => '', 'end' => ''];
                    if ($cell === null) {
                        $freeCount++;
                        $periodDocs[] = [
                            'periodNumber' => $pi + 1, 'subject' => '', 'teacher' => '', 'teacherId' => '',
                            'startTime' => $time['start'], 'endTime' => $time['end'], 'room' => '', 'type' => 'class',
                        ];
                    } else {
                        $periodDocs[] = [
                            'periodNumber' => $pi + 1, 'subject' => $cell['subject'], 'teacher' => $cell['teacherName'],
                            'teacherId' => $cell['teacherId'], 'startTime' => $time['start'], 'endTime' => $time['end'],
                            'room' => '', 'type' => $cell['type'],
                        ];
                    }
                }
                $result['unallocated'] += $freeCount;
                $sectionData[$day] = $periodDocs;

                $safeKey = str_replace('/', '_', $sectionKey);
                $docId   = "{$this->schoolId}_{$session}_{$safeKey}_{$day}";
                $existing       = $existingByDocId[$docId] ?? null;
                $manuallyEdited = is_array($existing) && !empty($existing['manuallyEdited']);
                $cellLabel      = "{$sectionKey}/{$day}";

                if ($manuallyEdited && !$forceOverwrite) {
                    $result['manual_skipped']++;
                    $result['manual_cells'][] = $cellLabel . ' (preserved)';
                    if (isset($existing['periods'])) $sectionData[$day] = $existing['periods'];
                    continue;
                }
                if ($manuallyEdited && $forceOverwrite) {
                    $result['manual_overwritten']++;
                    $result['manual_cells'][] = $cellLabel . ' (overwritten)';
                }

                if ($confirm) {
                    $prevVersion = is_array($existing) ? (int)($existing['version'] ?? 0) : 0;
                    $batchOps[] = [
                        'op' => 'set', 'collection' => self::COLLECTION_TT, 'docId' => $docId, 'merge' => false,
                        'data' => [
                            'schoolId'        => $this->schoolId,
                            'session'         => $session,
                            'className'       => $canonClass,
                            'section'         => $canonSection,
                            'classOrder'      => $cs['classOrder'],
                            'sectionCode'     => $cs['sectionCode'],
                            'sectionKey'      => $sectionKey,
                            'day'             => $day,
                            'periods'         => $periodDocs,
                            'manuallyEdited'  => false,
                            'generationId'    => $generationId,
                            'generatedAt'     => $now,
                            'generatedByUid'  => $this->adminId,
                            'generatedByName' => $this->adminName,
                            'updatedAt'       => $now,
                            'updatedByUid'    => $this->adminId,
                            'version'         => $prevVersion + 1,
                        ],
                    ];
                }
            }
            $result['sections_generated']++;
            $result['timetable'][$sk] = $sectionData;
        }

        if ($confirm && !empty($batchOps)) {
            $chunks = array_chunk($batchOps, 450);
            foreach ($chunks as $i => $chunk) {
                $ok = false;
                try { $ok = $this->firebase->firestoreCommitBatch($chunk); }
                catch (\Throwable $e) { log_message('error', "autoGenerate batch chunk {$i} failed: " . $e->getMessage()); }
                if (!$ok) {
                    throw new Service_exception(
                        "Auto-generate write failed at batch chunk " . ($i + 1) . " of " . count($chunks) . ". No further writes attempted. Generation ID {$generationId} (use to roll back any committed chunks).",
                        'internal'
                    );
                }
            }
        }

        $result['mode']            = $confirm ? 'saved' : 'preview';
        $result['generationId']    = $generationId;
        $result['force_overwrite'] = $forceOverwrite;

        if ($confirm) {
            $this->_audit('generation', 'timetable', $generationId, null, null, [
                'sectionsGenerated' => $result['sections_generated'],
                'unallocated'       => $result['unallocated'],
                'manualSkipped'     => $result['manual_skipped'],
                'manualOverwritten' => $result['manual_overwritten'],
                'forceOverwrite'    => $forceOverwrite,
            ]);
        }
        return $result;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PHASE 5.5 — Methods moved from Academic.php controller
    //
    //  These are direct ports of the original controller logic; only
    //  difference is they take parameters explicitly instead of reading
    //  $this->input->post(). Behaviour byte-identical to pre-Phase-5.5.
    // ══════════════════════════════════════════════════════════════════

    /**
     * Read the master timetable view: settings + all class-section grids
     * + subject assignments. Caller (controller) supplies $classes (which
     * MY_Controller derives from RTDB shallow_get).
     */
    public function getMasterTimetable(array $classes): array
    {
        // 1. Timetable settings (via the existing service method, then add
        //    the legacy keys the master-timetable view expects).
        $settings = [];
        try {
            $fsSettings = $this->firebase->firestoreGet(self::COLLECTION_SET, "{$this->schoolId}_{$this->session}");
            if (is_array($fsSettings)) $settings = $fsSettings;
        } catch (\Throwable $e) {}

        $recesses = [];
        $recessSrc = $settings['Recesses'] ?? $settings['recesses'] ?? null;
        if (is_array($recessSrc)) {
            foreach ($recessSrc as $r) {
                if (!is_array($r)) continue;
                $after = $r['after_period'] ?? $r['afterPeriod'] ?? null;
                $dur   = $r['duration']     ?? $r['durationMin'] ?? null;
                if ($after === null || $dur === null) continue;
                $recesses[] = ['after_period' => (int)$after, 'duration' => (int)$dur];
            }
        }

        $defaultDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $workingDays = $settings['Working_days'] ?? $settings['workingDays'] ?? $defaultDays;
        if (!is_array($workingDays) || empty($workingDays)) $workingDays = $defaultDays;

        $settingsClean = [
            'start_time'       => $settings['Start_time']      ?? $settings['startTime']      ?? '9:00AM',
            'end_time'         => $settings['End_time']        ?? $settings['endTime']        ?? '3:00PM',
            'no_of_periods'    => (int)  ($settings['No_of_periods']    ?? $settings['periodsPerDay']  ?? 6),
            'length_of_period' => (float)($settings['Length_of_period'] ?? $settings['lengthOfPeriod'] ?? 45),
            'recesses'         => $recesses,
            'working_days'     => $workingDays,
        ];

        // 2. All class-section timetables
        $timetables = [];
        try {
            $fsDocs = $this->firebase->firestoreQuery(self::COLLECTION_TT, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
            ], null, 'ASC', 500);
            foreach ($fsDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $cn  = $d['className'] ?? '';
                $sec = $d['section']   ?? '';
                $day = $d['day']       ?? '';
                if ($cn === '' || $sec === '' || $day === '') continue;
                $label = "{$cn} ({$sec})";
                if (!isset($timetables[$label])) $timetables[$label] = [];
                $dayData = [];
                foreach (($d['periods'] ?? []) as $p) {
                    $pi = ($p['periodNumber'] ?? 1) - 1;
                    $dayData[$pi] = [
                        'subject'    => $p['subject']   ?? '',
                        'teacher'    => $p['teacher']   ?? '',
                        'teacher_id' => $p['teacherId'] ?? '',
                        'teacherId'  => $p['teacherId'] ?? '',
                        'startTime'  => $p['startTime'] ?? '',
                        'endTime'    => $p['endTime']   ?? '',
                        'type'       => $p['type']      ?? 'class',
                    ];
                }
                $timetables[$label][$day] = $dayData;
            }
        } catch (\Throwable $e) {
            log_message('error', 'getMasterTimetable: timetable query failed: ' . $e->getMessage());
        }

        // 3. Subject assignments via SAS
        $assignments = [];
        try {
            $ci = function_exists('get_instance') ? get_instance() : null;
            if ($ci) {
                $ci->load->library('subject_assignment_service', null, 'sas');
                if (!$ci->sas->isReady()) {
                    $ci->sas->init($this->fs, $this->firebase, $this->schoolId, $this->schoolId, $this->session);
                }
                $allByClass = $ci->sas->getAllForSession();
                foreach ($allByClass as $className => $docs) {
                    $fbKey = $this->_classToFirebaseKey($className);
                    $assignments[$fbKey] = [];
                    foreach ($docs as $info) {
                        $code = $info['subjectCode'] ?? '';
                        $assignments[$fbKey][$code] = [
                            'teacher_id'   => $info['teacherId']      ?? '',
                            'teacher_name' => $info['teacherName']    ?? '',
                            'periods_week' => (int)($info['periodsPerWeek'] ?? 0),
                            'subject_name' => $info['subjectName']    ?? '',
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'getMasterTimetable: subjectAssignments fetch failed: ' . $e->getMessage());
        }

        return [
            'settings'            => $settingsClean,
            'timetables'          => $timetables,
            'classes'             => $classes,
            'subject_assignments' => $assignments,
        ];
    }

    /**
     * Save (or clear) a single (class, section, day, periodIdx) cell.
     * Returns warnings array; throws Service_exception('conflict') on
     * teacher hard-conflict.
     */
    public function savePeriod(
        string $classKey, string $section, string $day, int $periodIdx,
        string $subject, string $teacherId, string $teacherName
    ): array {
        $validDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        if ($classKey === '' || $section === '' || !in_array($day, $validDays, true) || $periodIdx < 0) {
            throw new Service_exception('Missing or invalid parameters', 'validation');
        }

        $warnings = [];

        // Pre-fetch all timetable docs for this day (single query).
        $allDayDocs = [];
        try {
            $allDayDocs = $this->firebase->firestoreQuery(self::COLLECTION_TT, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
                ['day',      '==', $day],
            ], null, 'ASC', 200);
        } catch (\Throwable $e) {}

        $dayTimetables = [];
        foreach ($allDayDocs as $doc) {
            $d   = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $cn  = $d['className'] ?? '';
            $s2  = $d['section']   ?? '';
            $sk  = "{$cn}|{$s2}";
            $dayTimetables[$sk] = [];
            foreach (($d['periods'] ?? []) as $p) {
                $pi = ($p['periodNumber'] ?? 1) - 1;
                $dayTimetables[$sk][$pi] = [
                    'subject'      => $p['subject']   ?? '',
                    'teacher_id'   => $p['teacherId'] ?? '',
                    'teacher_name' => $p['teacher']   ?? '',
                ];
            }
        }

        // Pre-fetch all timetable docs for this section (across all days).
        $allSectionDocs = [];
        $csNorm = \Entity_firestore_sync::normalizeClassSection($classKey, "Section {$section}");
        $normSectionKey = ($csNorm['className'] ?: $classKey) . '/' . ($csNorm['section'] ?: "Section {$section}");
        try {
            $allSectionDocs = $this->firebase->firestoreQuery(self::COLLECTION_TT, [
                ['schoolId',   '==', $this->schoolId],
                ['session',    '==', $this->session],
                ['sectionKey', '==', $normSectionKey],
            ], null, 'ASC', 7);
        } catch (\Throwable $e) {}

        if ($subject !== '') {
            // 1. Teacher hard-conflict
            if ($teacherId !== '') {
                foreach ($dayTimetables as $sk => $periods) {
                    $parts = explode('|', $sk);
                    $skClass = $parts[0] ?? '';
                    $skSec   = $parts[1] ?? '';
                    if ($this->_classToFirebaseKey($skClass) === $this->_classToFirebaseKey($classKey)
                        && (str_replace('Section ', '', $skSec) === $section || $skSec === "Section {$section}")) continue;

                    $otherCell = $periods[$periodIdx] ?? [];
                    $otherTid  = $otherCell['teacher_id'] ?? '';
                    if ($otherTid !== '' && $otherTid === $teacherId) {
                        $label = "{$skClass} ({$skSec})";
                        throw new Service_exception(
                            "Teacher conflict: {$teacherName} is already assigned to {$label} on {$day} period " . ($periodIdx + 1) . ". Remove that assignment first.",
                            'conflict'
                        );
                    }
                }
            }

            // 2. Duplicate subject same day (warning)
            $dayTt = [];
            foreach ($dayTimetables as $sk => $periods) {
                $parts = explode('|', $sk);
                $skClass = $parts[0] ?? '';
                $skSec   = $parts[1] ?? '';
                if ($this->_classToFirebaseKey($skClass) === $this->_classToFirebaseKey($classKey)
                    && (str_replace('Section ', '', $skSec) === $section || $skSec === "Section {$section}")) {
                    $dayTt = $periods; break;
                }
            }
            $subjectCountToday = 0;
            foreach ($dayTt as $i => $cell) {
                if ((int)$i === $periodIdx) continue;
                $cellSub = is_array($cell) ? ($cell['subject'] ?? '') : (string)$cell;
                if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) $subjectCountToday++;
            }
            if ($subjectCountToday > 0) {
                $warnings[] = "{$subject} already appears {$subjectCountToday} other time(s) on {$day}";
            }

            // 2.5 Validate teacher-subject assignment exists
            if ($teacherId !== '') {
                try {
                    $sas = $this->_sas();
                    $fbClassKeyForSas = $this->_classToFirebaseKey($classKey);
                    $sectionKeyForSas = 'Section ' . $section;
                    $secAssigns = $sas ? $sas->getAssignmentsForClass($fbClassKeyForSas, $sectionKeyForSas) : [];
                    $clsAssigns = $sas ? $sas->getAssignmentsForClass($fbClassKeyForSas, '') : [];
                    $merged = array_merge($secAssigns, $clsAssigns);
                    $matchFound = false;
                    foreach ($merged as $a) {
                        $aTeacher = $a['teacherId'] ?? '';
                        if ($aTeacher === $teacherId &&
                            (strcasecmp(($a['subjectName'] ?? ''), $subject) === 0 || strcasecmp(($a['subjectCode'] ?? ''), $subject) === 0)) {
                            $matchFound = true; break;
                        }
                    }
                    if (!$matchFound) {
                        $warnings[] = "No subject assignment found for {$teacherName} → {$subject} in {$classKey}. Add it in Academic Planner → Subject Assignments first.";
                    }
                } catch (\Throwable $e) {
                    log_message('error', 'savePeriod assignments check failed: ' . $e->getMessage());
                }
            }

            // 3. Weekly period-limit
            $limit = 0;
            try {
                $sas = $this->_sas();
                if ($sas) {
                    $fbClassKey = $this->_classToFirebaseKey($classKey);
                    $sectionKeyForSas = 'Section ' . $section;
                    $merged = array_merge(
                        $sas->getAssignmentsForClass($fbClassKey, $sectionKeyForSas),
                        $sas->getAssignmentsForClass($fbClassKey, '')
                    );
                    foreach ($merged as $a) {
                        if (strcasecmp(($a['subjectName'] ?? ''), $subject) === 0
                            || strcasecmp(($a['subjectCode'] ?? ''), $subject) === 0) {
                            $limit = (int)($a['periodsPerWeek'] ?? 0); break;
                        }
                    }
                }
            } catch (\Throwable $e) {}
            if ($limit > 0) {
                $weekCount = 0;
                foreach ($allSectionDocs as $doc) {
                    $d2 = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $docDay = $d2['day'] ?? '';
                    foreach (($d2['periods'] ?? []) as $p) {
                        $pi2 = ($p['periodNumber'] ?? 1) - 1;
                        if ($docDay === $day && $pi2 === $periodIdx) continue;
                        $cellSub = $p['subject'] ?? '';
                        if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) $weekCount++;
                    }
                }
                $newTotal = $weekCount + 1;
                if ($newTotal > $limit) {
                    $warnings[] = "{$subject} will have {$newTotal} periods/week (limit: {$limit})";
                }
            }
        }

        // ── WRITE ──
        $canonClass   = $csNorm['className'] ?: $classKey;
        $canonSection = $csNorm['section']   ?: "Section {$section}";
        $sectionKeyWrite = "{$canonClass}/{$canonSection}";
        $safeKey = str_replace('/', '_', $sectionKeyWrite);
        $fsDocId = "{$this->schoolId}_{$this->session}_{$safeKey}_{$day}";

        $existingPeriods = [];
        try {
            $existingDoc = $this->firebase->firestoreGet(self::COLLECTION_TT, $fsDocId);
            if (is_array($existingDoc) && isset($existingDoc['periods'])) {
                foreach ($existingDoc['periods'] as $p) {
                    $pi2 = ($p['periodNumber'] ?? 1) - 1;
                    $existingPeriods[$pi2] = $p;
                }
            }
        } catch (\Throwable $e) {}

        $fsSettings = [];
        try { $fsSettings = $this->firebase->firestoreGet(self::COLLECTION_SET, "{$this->schoolId}_{$this->session}") ?? []; }
        catch (\Throwable $e) {}
        $maxP = (int) ($fsSettings['No_of_periods'] ?? $fsSettings['periodsPerDay'] ?? 8);

        $periodDocs = [];
        for ($p = 0; $p < max($maxP, $periodIdx + 1); $p++) {
            if ($p === $periodIdx) {
                $periodDocs[] = [
                    'periodNumber' => $p + 1,
                    'subject'      => $subject,
                    'teacher'      => $teacherName,
                    'teacherId'    => $teacherId,
                    'startTime'    => $existingPeriods[$p]['startTime'] ?? '',
                    'endTime'      => $existingPeriods[$p]['endTime']   ?? '',
                    'room'         => '',
                    'type'         => 'class',
                ];
            } elseif (isset($existingPeriods[$p])) {
                $periodDocs[] = $existingPeriods[$p];
            } else {
                $periodDocs[] = [
                    'periodNumber' => $p + 1,
                    'subject'      => '', 'teacher' => '', 'teacherId' => '',
                    'startTime'    => '', 'endTime' => '', 'room' => '', 'type' => 'class',
                ];
            }
        }

        $this->firebase->firestoreSet(self::COLLECTION_TT, $fsDocId, [
            'schoolId'       => $this->schoolId,
            'session'        => $this->session,
            'className'      => $canonClass,
            'section'        => $canonSection,
            'classOrder'     => $csNorm['classOrder'],
            'sectionCode'    => $csNorm['sectionCode'],
            'sectionKey'     => $sectionKeyWrite,
            'day'            => $day,
            'periods'        => $periodDocs,
            'manuallyEdited' => true,
            'updatedAt'      => date('c'),
            'updatedByUid'   => $this->adminId,
        ], true);

        return [
            'day'          => $day,
            'period_index' => $periodIdx,
            'subject'      => $subject,
            'teacher_id'   => $teacherId,
            'teacher_name' => $teacherName,
            'warnings'     => $warnings,
        ];
    }

    /**
     * Bulk import a full week's grid for one section. POST shape: a map
     * day → array of period entries. Each existing day-doc is overwritten
     * with manuallyEdited:true. Missing days are not touched.
     */
    public function saveSectionTimetable(string $class, string $section, $timetableRaw): array
    {
        if ($class === '' || $section === '') {
            throw new Service_exception('Class and section are required', 'validation');
        }
        $timetable = is_string($timetableRaw) ? json_decode($timetableRaw, true) : $timetableRaw;
        if (!is_array($timetable)) {
            throw new Service_exception('Invalid timetable data', 'validation');
        }
        $validDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $clean = [];
        foreach ($timetable as $day => $periods) {
            if (!in_array($day, $validDays, true)) continue;
            $clean[$day] = is_array($periods) ? $periods : [];
        }

        $cs = \Entity_firestore_sync::normalizeClassSection($class, $section);
        $canonClass   = $cs['className'] !== '' ? $cs['className'] : $class;
        $canonSection = $cs['section']   !== '' ? $cs['section']   : $section;
        $sectionKey = ($canonClass !== '' && $canonSection !== '')
            ? "{$canonClass}/{$canonSection}" : "{$class}/{$section}";

        try {
            foreach ($clean as $day => $periods) {
                $safeKey = str_replace('/', '_', $sectionKey);
                $docId = "{$this->schoolId}_{$this->session}_{$safeKey}_{$day}";
                $periodDocs = [];
                $periodNum = 1;

                foreach ($periods as $key => $entry) {
                    if (!is_array($entry)) continue;
                    $type = strtolower(trim($entry['type'] ?? 'class'));
                    $isBreak = ($type === 'break' || $type === 'lunch'
                        || stripos((string)$key, 'Break') === 0 || strcasecmp((string)$key, 'Lunch') === 0);

                    $periodDocs[] = [
                        'periodNumber' => is_numeric($key) ? intval($key) : $periodNum,
                        'subject'      => $isBreak ? '' : ($entry['subject']  ?? $entry['Subject'] ?? ''),
                        'teacher'      => $isBreak ? '' : ($entry['teacher']  ?? $entry['Teacher'] ?? ''),
                        'teacherId'    => $entry['teacherId'] ?? $entry['teacher_id'] ?? '',
                        'startTime'    => $entry['startTime'] ?? $entry['start_time'] ?? '',
                        'endTime'      => $entry['endTime']   ?? $entry['end_time']   ?? '',
                        'room'         => $entry['room']      ?? $entry['Room'] ?? '',
                        'type'         => $isBreak ? ($type === 'lunch' ? 'lunch' : 'break') : 'class',
                    ];
                    $periodNum++;
                }

                $this->firebase->firestoreSet(self::COLLECTION_TT, $docId, [
                    'schoolId'       => $this->schoolId,
                    'session'        => $this->session,
                    'className'      => $canonClass,
                    'section'        => $canonSection,
                    'classOrder'     => $cs['classOrder'],
                    'sectionCode'    => $cs['sectionCode'],
                    'sectionKey'     => $sectionKey,
                    'day'            => $day,
                    'periods'        => $periodDocs,
                    'manuallyEdited' => true,
                    'updatedAt'      => date('c'),
                    'updatedByUid'   => $this->adminId,
                ], true);
            }
        } catch (\Throwable $e) {
            log_message('error', 'saveSectionTimetable: Firestore sync failed: ' . $e->getMessage());
        }

        return ['message' => 'Timetable saved successfully'];
    }

    /**
     * Pre-save conflict check for a single cell. Returns
     *   {conflict:bool, type, message, severity}
     * on hard conflict, otherwise {conflict:false, warnings:[]}.
     */
    public function detectConflicts(
        string $subject, string $teacherId, string $day, int $periodIdx,
        string $excludeClass, string $excludeSection
    ): array {
        if (($subject === '' && $teacherId === '') || $day === '' || $periodIdx < 0) {
            return ['conflict' => false, 'warnings' => []];
        }
        $warnings = [];

        $allDayDocs = [];
        try {
            $allDayDocs = $this->firebase->firestoreQuery(self::COLLECTION_TT, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
                ['day',      '==', $day],
            ], null, 'ASC', 200);
        } catch (\Throwable $e) {}

        // 1. Teacher conflict (hard)
        if ($teacherId !== '') {
            foreach ($allDayDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $cn  = $d['className'] ?? '';
                $sec = $d['section']   ?? '';
                if ($this->_classToFirebaseKey($cn) === $this->_classToFirebaseKey($excludeClass)
                    && (str_replace('Section ', '', $sec) === $excludeSection
                        || $sec === "Section {$excludeSection}" || $sec === $excludeSection)) continue;

                foreach (($d['periods'] ?? []) as $p) {
                    $pi = ($p['periodNumber'] ?? 1) - 1;
                    if ($pi !== $periodIdx) continue;
                    $cellTid = $p['teacherId'] ?? '';
                    if ($cellTid !== '' && $cellTid === $teacherId) {
                        return [
                            'conflict' => true,
                            'type'     => 'teacher',
                            'severity' => 'error',
                            'message'  => "Teacher conflict: already assigned to {$cn} ({$sec}) on {$day} P" . ($periodIdx + 1),
                        ];
                    }
                }
            }
        }

        // 2. Duplicate subject same day (soft)
        if ($excludeClass !== '' && $subject !== '') {
            foreach ($allDayDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $cn  = $d['className'] ?? '';
                $sec = $d['section']   ?? '';
                if ($this->_classToFirebaseKey($cn) !== $this->_classToFirebaseKey($excludeClass)) continue;
                if (str_replace('Section ', '', $sec) !== $excludeSection
                    && $sec !== "Section {$excludeSection}" && $sec !== $excludeSection) continue;

                $dupCount = 0;
                foreach (($d['periods'] ?? []) as $p) {
                    $pi = ($p['periodNumber'] ?? 1) - 1;
                    if ($pi === $periodIdx) continue;
                    $cellSub = $p['subject'] ?? '';
                    if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) $dupCount++;
                }
                if ($dupCount > 0) {
                    $warnings[] = ['type' => 'duplicate', 'message' => "{$subject} already has {$dupCount} other period(s) on {$day}"];
                }
                break;
            }
        }

        // 3. Weekly period-limit
        if ($excludeClass !== '' && $subject !== '') {
            $limit = 0;
            try {
                $sas = $this->_sas();
                if ($sas) {
                    $fbKey = $this->_classToFirebaseKey($excludeClass);
                    $merged = array_merge(
                        $sas->getAssignmentsForClass($fbKey, "Section {$excludeSection}"),
                        $sas->getAssignmentsForClass($fbKey, '')
                    );
                    foreach ($merged as $a) {
                        if (strcasecmp(($a['subjectName'] ?? ''), $subject) === 0
                            || strcasecmp(($a['subjectCode'] ?? ''), $subject) === 0) {
                            $limit = (int)($a['periodsPerWeek'] ?? 0); break;
                        }
                    }
                }
            } catch (\Throwable $e) {}

            if ($limit > 0) {
                $weekCount = 0;
                try {
                    $csN = \Entity_firestore_sync::normalizeClassSection($excludeClass, "Section {$excludeSection}");
                    $normSK = ($csN['className'] ?: $excludeClass) . '/' . ($csN['section'] ?: "Section {$excludeSection}");
                    $weekDocs = $this->firebase->firestoreQuery(self::COLLECTION_TT, [
                        ['schoolId',   '==', $this->schoolId],
                        ['session',    '==', $this->session],
                        ['sectionKey', '==', $normSK],
                    ], null, 'ASC', 7);
                    foreach ($weekDocs as $doc) {
                        $d2 = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                        $docDay = $d2['day'] ?? '';
                        foreach (($d2['periods'] ?? []) as $p) {
                            $pi2 = ($p['periodNumber'] ?? 1) - 1;
                            if ($docDay === $day && $pi2 === $periodIdx) continue;
                            $cellSub = $p['subject'] ?? '';
                            if ($cellSub !== '' && strcasecmp($cellSub, $subject) === 0) $weekCount++;
                        }
                    }
                } catch (\Throwable $e) {}
                $newTotal = $weekCount + 1;
                if ($newTotal > $limit) {
                    $warnings[] = ['type' => 'period_limit', 'message' => "{$subject}: {$newTotal} periods/week exceeds limit of {$limit}"];
                }
            }
        }

        return ['conflict' => false, 'warnings' => $warnings];
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════

    /** Same as Academic::_class_to_firebase_key — Firebase keys can't contain . $ # [ ] / */
    private function _classToFirebaseKey(string $key): string
    {
        return str_replace(['.', '$', '#', '[', ']', '/'], '_', trim($key));
    }

    /** Lazy-load Subject_assignment_service via CI singleton. */
    private function _sas()
    {
        $ci = function_exists('get_instance') ? get_instance() : null;
        if (!$ci) return null;
        $ci->load->library('subject_assignment_service', null, 'sas');
        if (!$ci->sas->isReady()) {
            $ci->sas->init($this->fs, $this->firebase, $this->schoolId, $this->schoolId, $this->session);
        }
        return $ci->sas;
    }

    private function _audit(string $action, string $entityType, string $entityId, ?array $before, ?array $after, array $metadata): void
    {
        if (!$this->audit) return;
        try { $this->audit->log($action, $entityType, $entityId, $before, $after, $metadata); }
        catch (\Throwable $e) { log_message('error', 'timetable audit failed: ' . $e->getMessage()); }
    }
}
