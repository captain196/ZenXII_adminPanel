<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Service_exception.php';
require_once APPPATH . 'libraries/Entity_firestore_sync.php';
require_once APPPATH . 'libraries/Time_helper.php';
require_once APPPATH . 'libraries/Timetable_metrics.php';

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

    /**
     * ACAD-D10: how many of a day's timetable docs the conflict guard reads.
     * One doc exists per class-section per day, so this is the number of
     * sections the guard can see. If a school has MORE, a silent truncation
     * would hide the very row that proves a double-booking — so hitting the
     * limit is treated as "cannot evaluate", not as "no conflict".
     */
    const CONFLICT_SCAN_LIMIT = 200;

    /**
     * Page size for the master-timetable read. One doc per (class-section, day):
     * at 6 working days this is ~83 class-sections. Saturation is REPORTED to the
     * caller as incomplete rather than silently truncated — see getMasterTimetable.
     */
    const MASTER_SCAN_LIMIT = 500;

    /**
     * Page size for autoGenerate's existing-day preload. That map is what protects
     * manually edited days, so saturating it is "cannot evaluate" and REFUSES the
     * run rather than silently overwriting — see autoGenerate.
     */
    const GENERATE_PRELOAD_LIMIT = 1000;

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
                    'name'         => (string) ($r['name'] ?? $r['label'] ?? ''),
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
                // What the school calls this break -- Lunch, Recess, Tiffin,
                // Interval, Short break. Optional: a school that names nothing
                // keeps the generic word, and every existing school already has
                // no name, so this is purely additive.
                $name = trim((string) preg_replace('~\s+~u', ' ',
                    (string) ($r['name'] ?? $r['label'] ?? '')));
                if (mb_strlen($name) > self::BREAK_NAME_MAX) {
                    $name = trim(mb_substr($name, 0, self::BREAK_NAME_MAX));
                }
                $cleanRecesses[] = ['after_period' => $after, 'duration' => $dur,
                                    'name' => $name];
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
            fn($r) => ['afterPeriod' => (int) $r['after_period'],
                       'durationMin' => (int) $r['duration'],
                       'name'        => (string) ($r['name'] ?? '')],
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

        // PHASE 7 — inputs are loaded and GUARDED in one place so the candidate
        // destination shares exactly the same safety, rather than copying it.
        // ACAD-D25 (refuse to generate from nothing), the settings guard and the
        // sections guard all live inside _loadGenerationInputs().
        $inputs             = $this->_loadGenerationInputs($session, $scopeClass);
        $periodsPerDay      = $inputs['periodsPerDay'];
        $workingDays        = $inputs['workingDays'];
        $periodTimes        = $inputs['periodTimes'];
        $sections           = $inputs['sections'];
        $sectionAssignments = $inputs['sectionAssignments'];
        // 3. Greedy placement — PHASE 6 STEP 1: computed by _placeGrid() so the
        //    same single algorithm can later target a candidate as well as the
        //    live path. Behaviour is unchanged; this is a pure extraction.
        $grid = $this->_placeGrid($sections, $sectionAssignments, $workingDays, $periodsPerDay);

        // 4. Build output + atomic batch
        $result = [
            'sections_generated' => 0, 'conflicts' => 0, 'unallocated' => 0,
            'manual_skipped' => 0, 'manual_overwritten' => 0, 'manual_cells' => [],
            'timetable' => [],
        ];

        $generationId  = 'GEN_' . date('YmdHis') . '_' . substr(uniqid(), -6);
        $now           = date('c');

        // ACAD-D23 — this preload is the ONLY thing protecting hand-built timetable
        // days from being overwritten. Below, a day is preserved when
        //     $manuallyEdited = is_array($existing) && !empty($existing['manuallyEdited'])
        // and $existing comes from this map. Both of the old failure modes made
        // that read FALSE for days that really were hand-edited:
        //
        //   1. firestoreQuery() returns [] for a FAILED read exactly as it does for
        //      "no existing timetable" (ACAD-D12), so a transient failure emptied
        //      the map;
        //   2. the 1000-doc cap silently truncated it — roughly 166 class-sections
        //      at six working days — so the lexicographic tail lost its protection.
        //
        // Either way the guard did not fire and the generator replaced the day with
        // merge = false (whole-document overwrite), destroying manual work the
        // operator had NOT authorised overwriting. There is no rollback.
        //
        // We cannot prove a day was not hand-edited from an incomplete map, so an
        // unreadable or saturated preload must refuse the whole run — the same rule
        // the assignment delete-set follows in ACAD-D14.
        $existingByDocId = [];
        try {
            $existingDocs = $this->firebase->firestoreQueryOrFail(self::COLLECTION_TT, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $session],
            ], null, 'ASC', self::GENERATE_PRELOAD_LIMIT + 1);

            if (count($existingDocs) > self::GENERATE_PRELOAD_LIMIT) {
                log_message('error', 'autoGenerate: existing-doc preload saturated at '
                    . self::GENERATE_PRELOAD_LIMIT . ' (school=' . $this->schoolId
                    . ' session=' . $session . ') — cannot prove which days were '
                    . 'manually edited.');
                throw new Service_exception(
                    'This school has more timetable data than the generator can check '
                    . 'in one pass, so nothing was generated. Running now could have '
                    . 'overwritten manually edited days. Please contact support.',
                    'preload'
                );
            }

            foreach ($existingDocs as $doc) {
                $d  = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                $idx = $doc['id'] ?? $d['id'] ?? '';
                if ($idx !== '') $existingByDocId[$idx] = $d;
            }
        } catch (Service_exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            log_message('error', 'autoGenerate existing-doc preload failed: ' . $e->getMessage());
            throw new Service_exception(
                'The existing timetable could not be read, so nothing was generated. '
                . 'Generating now could have overwritten days that were edited by hand. '
                . 'Please try again in a moment.',
                'preload'
            );
        }

        // PHASE 6 STEP 2 — COMPOSITION / DESTINATION separation.
        //
        // Composition decides WHAT each generated day contains and whether it may
        // be written at all. It is destination-neutral: it never builds a document
        // id and never names a collection.
        //
        // The one piece it genuinely needs from a destination is "what is already
        // stored for this class-section and day", because that is what the D23
        // manual-edit preservation decision reasons over. That arrives as an
        // explicit lookup closure rather than a map keyed by live document ids, so
        // a different destination supplies its own lookup without composition
        // changing at all.
        //
        // IMPORTANT — the live semantics are preserved exactly: the closure below
        // rebuilds the SAME live document id from the SAME canonical section key
        // and reads the SAME preload map. "Preserve a manually edited LIVE day" has
        // not been generalised into "preserve because a destination contains
        // something"; those are different rules and only the first exists today.
        $liveExisting = function (string $sectionKey, string $day) use ($existingByDocId, $session) {
            $safeKey = str_replace('/', '_', $sectionKey);
            $docId   = "{$this->schoolId}_{$session}_{$safeKey}_{$day}";
            return $existingByDocId[$docId] ?? null;
        };

        $composed = $this->_composeGeneratedDays(
            $grid, $periodTimes, $liveExisting, $forceOverwrite, $result);

        // DESTINATION ADAPTER — the only place that knows about live document ids,
        // the live collection and the live document shape.
        // Only the CONFIRMED run writes; a preview must stay usable after
        // cutover so an administrator can still see what the old generator
        // would have produced.
        if ($confirm) { $this->_refuseLegacyWrite('This timetable'); }

        $batchOps = $confirm
            ? $this->_liveBatchOpsFrom($composed, $session, $generationId, $now)
            : [];

        // PHASE 6 STEP 1 — the WRITE side of the boundary. The live persistence
        // behaviour (chunk size, ordering, stop-on-failure, message) is moved
        // verbatim into _commitLiveBatch(). A future candidate path substitutes
        // a different adapter here without touching the compute above.
        if ($confirm && !empty($batchOps)) {
            $this->_commitLiveBatch($batchOps, $generationId);
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

    /**
     * PHASE 7 — CANDIDATE GENERATION (Option E).
     *
     * Generates into a NEWLY CREATED, isolated candidate version. Shares
     * _loadGenerationInputs(), _placeGrid() and _composeGeneratedDays() with the
     * live path; only the destination differs.
     *
     * ── WHY THIS STRUCTURALLY REMOVES ACAD-D26 FROM THIS PATH ────────────
     *
     * D26 exists because the live generator preloads, computes for up to 300
     * seconds, then writes back over the artifact an administrator may have
     * edited meanwhile. Here the destination is a version created moments ago
     * that nothing else references, so a concurrent live edit cannot be
     * overwritten — there is no shared artifact to overwrite. The window is not
     * narrowed; it does not exist on this path.
     *
     * The OLD live generator is untouched and still has the window. D26 is not
     * globally closed.
     *
     * ── PRESERVATION ────────────────────────────────────────────────────
     *
     * The existing-state lookup returns null for every day: a candidate created
     * moments ago contains nothing, so there is nothing to preserve and no
     * preservation policy is applied. Manual edits are protected by NOT being
     * regenerated into — the source candidate is never written to.
     *
     * @throws Service_exception on any refusal; nothing is left half-usable
     */
    public function generateIntoCandidate(
        $planStore, string $planId, ?string $fromVersionId = null, string $scopeClass = ''
    ): array {
        @set_time_limit(300);

        $session = $this->session;

        // Same inputs, same guards (settings, sections, ACAD-D25) as the live path.
        $inputs = $this->_loadGenerationInputs($session, $scopeClass);

        // A NEW version. The source candidate is never opened for writing.
        $created   = $planStore->createGenerationCandidate($planId, $fromVersionId);
        $versionId = $created['versionId'];

        $runId = $planStore->beginGenerationRun($planId, $versionId, [
            'scopeClass'      => $scopeClass,
            'regeneratedFrom' => $fromVersionId,
        ]);

        $result = [
            'sections_generated' => 0, 'unallocated' => 0,
            'manual_skipped' => 0, 'manual_overwritten' => 0,
            'manual_cells' => [], 'timetable' => [],
        ];

        $grid = $this->_placeGrid(
            $inputs['sections'], $inputs['sectionAssignments'],
            $inputs['workingDays'], $inputs['periodsPerDay']);

        // A brand-new candidate holds nothing, so the composition lookup answers
        // "nothing stored" for every day. No preservation rule is exercised, and
        // none is implied for any other destination.
        $nothingStored = fn(string $sectionKey, string $day) => null;

        $composed = $this->_composeGeneratedDays(
            $grid, $inputs['periodTimes'], $nothingStored, false, $result);

        $written = 0;
        foreach ($composed as $c) {
            try {
                $planStore->saveCandidateDay(
                    $planId, $versionId, $c['sectionKey'], $c['day'],
                    $c['periods'], false, $runId);
            } catch (\Throwable $e) {
                // ACAD-D24 contained: the run is marked FAILED, the days already
                // written stay for diagnosis, and readCompleteCandidate() will
                // refuse this candidate. The previous candidate and the live
                // timetable were never touched.
                $planStore->failGenerationRun($planId, $versionId, $runId, $written,
                    'candidate day write failed: ' . $e->getMessage());

                throw new Service_exception(
                    'Generation stopped part-way and the incomplete candidate has been '
                    . 'marked unusable. Your existing timetable and any previous '
                    . 'candidate were not changed. Please try generating again.',
                    'generation_failed'
                );
            }
            $written++;
        }

        $planStore->completeGenerationRun($planId, $versionId, $runId, $written);

        $result['mode']        = 'candidate';
        $result['planId']      = $planId;
        $result['versionId']   = $versionId;
        $result['generationId'] = $runId;
        $result['days_written'] = $written;
        $result['regeneratedFrom'] = $fromVersionId;

        return $result;
    }
    /**
     * PHASE 7 — GENERATION INPUTS, with their guards.
     *
     * Reads and validates everything placement needs, moved verbatim from
     * autoGenerate(). Extracted so the candidate destination shares the SAME
     * guards rather than carrying a copy that could drift:
     *
     *   - settings: an unreadable read falls through to periodsPerDay <= 0 and
     *     refuses (fail-closed);
     *   - sections: an empty set refuses;
     *   - ACAD-D25: zero resolved assignments refuses, because the pre-sized grid
     *     would otherwise write an empty period into every cell of every day.
     *
     * A candidate is isolated from live data, but a blank candidate is still a
     * wrong answer, so the same refusals apply on both destinations.
     */
    // protected, not private, so the candidate orchestrator can be driven in a
    // test with fixed inputs. Mutation testing showed that while this method did
    // real I/O, generateIntoCandidate() could not be executed at all — and four
    // mutations inside it survived unnoticed. Still not public API.
    protected function _loadGenerationInputs(string $session, string $scopeClass): array
    {
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

        // ACAD-D25 — refuse to generate from nothing.
        //
        // getAllForSession() routes to sessionWhere() -> firestoreQuery(), which
        // returns [] for a FAILED read exactly as it does for "this school has no
        // subject assignments" (ACAD-D12). Nothing downstream objected: the grid
        // below is pre-initialised for EVERY section and day, an unfilled cell is
        // written as an empty period, and every day document is then committed
        // with merge = false. So one failed read blanked the entire school's
        // timetable in a single run and reported success.
        //
        // Only days flagged manuallyEdited survived, which in an auto-generated
        // school is none of them. There is no rollback.
        //
        // This guard closes the path whatever the cause: a run that has no
        // assignment to place has nothing to generate, and writing blank days is
        // never what the operator asked for.
        $totalAssignments = 0;
        foreach ($sectionAssignments as $matchedList) {
            $totalAssignments += count($matchedList);
        }
        if ($totalAssignments === 0) {
            log_message('error', 'autoGenerate: no subject assignments resolved (school='
                . $this->schoolId . ' session=' . $session . ' sections=' . count($sections)
                . ') — refusing to write a blank timetable.');
            throw new Service_exception(
                'No subject assignments could be found for these classes, so nothing '
                . 'was generated. Assign teachers to subjects first. (If assignments '
                . 'do exist, the list could not be read just now — please try again in '
                . 'a moment rather than re-running, which would have cleared the '
                . 'existing timetable.)',
                'not_found'
            );
        }
        return [
            'periodsPerDay'      => $periodsPerDay,
            'workingDays'        => $workingDays,
            'periodTimes'        => $periodTimes,
            'sections'           => $sections,
            'sectionAssignments' => $sectionAssignments,
        ];
    }
    /**
     * PHASE 6 STEP 2 — COMPOSITION (destination-neutral).
     *
     * Turns the placed grid into the list of days that may be written, applying
     * the ACAD-D23 manual-edit preservation rule and maintaining the operator
     * counters ACAD-D27 surfaces before a destructive run.
     *
     * Knows nothing about document ids, collections or document shape. What it
     * needs from a destination is a single question — "what is already stored for
     * this class-section on this day?" — supplied as $existingLookup.
     *
     * D23 SEMANTICS ARE UNCHANGED. The rule is still "preserve a manually edited
     * LIVE day unless force-overwrite is set". It has NOT been generalised into
     * "preserve because the destination contains something": for a candidate
     * destination those are different rules, and choosing the candidate rule is a
     * decision for the phase that adds candidate generation, not this extraction.
     *
     * @param callable $existingLookup fn(string $sectionKey, string $day): ?array
     * @param array    $result         BY REFERENCE — counters must stay identical
     * @return array[] composed days, in the original iteration order
     */
    private function _composeGeneratedDays(
        array $grid, array $periodTimes, callable $existingLookup,
        bool $forceOverwrite, array &$result
    ): array {
        $composed = [];
        foreach ($grid as $sk => $days) {
            $cs           = $this->_canonSection($sk);
            $canonClass   = $cs['canonClass'];
            $canonSection = $cs['canonSection'];
            $sectionKey   = $cs['key'];

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

                // The ONE destination-shaped question composition asks. Building a
                // document id here is what previously forced the candidate path to
                // duplicate this whole block.
                $existing       = $existingLookup($sectionKey, $day);
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

                // Destination-neutral: record THAT this day may be written and the
                // version it supersedes. Turning that into a document is the
                // adapter's job.
                $composed[] = [
                    'sectionKey'   => $sectionKey,
                    'canonClass'   => $canonClass,
                    'canonSection' => $canonSection,
                    'classOrder'   => $cs['classOrder'],
                    'sectionCode'  => $cs['sectionCode'],
                    'day'          => $day,
                    'periods'      => $periodDocs,
                    'prevVersion'  => is_array($existing) ? (int)($existing['version'] ?? 0) : 0,
                ];
            }
            $result['sections_generated']++;
            $result['timetable'][$sk] = $sectionData;
        }
        return $composed;
    }

    /**
     * PHASE 6 STEP 2 — LIVE DESTINATION ADAPTER.
     *
     * The only place that knows the live collection, the live document id format
     * and the live document shape. Everything here is moved verbatim from
     * autoGenerate(); a candidate destination will be a sibling of this method,
     * not a second composition.
     *
     * Tenant and session stay explicit: the id is built from $this->schoolId and
     * the caller-supplied $session exactly as before, so no destination can
     * accidentally widen scope.
     */
    /**
     * ── LEGACY DIRECT WRITE — DO NOT EXTEND ─────────────────────────
     *
     * Writes directly to the legacy `timetables` collection, which is no
     * longer the canonical source of timetable truth. The canonical path is
     * a timetable version: draft or candidate, validated, published, then
     * projected into this collection for consumers that still read it.
     *
     * Builds the live write batch for autoGenerate(). Superseded by Timetable_service::generateIntoCandidate(), which generates into an isolated candidate version instead. ACAD-D26 and ACAD-D24 both remain open on THIS path and are structurally absent from the candidate one.
     *
     * This is retained because it is still the only working timetable path
     * in production and the Teacher app reads the collection it writes.
     * New feature work must target the version architecture instead; a new
     * direct writer here would recreate the second-source-of-truth problem
     * the version architecture exists to remove.
     */
    private function _liveBatchOpsFrom(array $composed, string $session, string $generationId, string $now): array
    {
        $batchOps = [];
        foreach ($composed as $c) {
            $safeKey = str_replace('/', '_', $c['sectionKey']);
            $docId   = "{$this->schoolId}_{$session}_{$safeKey}_{$c['day']}";

            $batchOps[] = [
                'op' => 'set', 'collection' => self::COLLECTION_TT, 'docId' => $docId, 'merge' => false,
                'data' => [
                    'schoolId'        => $this->schoolId,
                    'session'         => $session,
                    'className'       => $c['canonClass'],
                    'section'         => $c['canonSection'],
                    'classOrder'      => $c['classOrder'],
                    'sectionCode'     => $c['sectionCode'],
                    'sectionKey'      => $c['sectionKey'],
                    'day'             => $c['day'],
                    'periods'         => $c['periods'],
                    'manuallyEdited'  => false,
                    'generationId'    => $generationId,
                    'generatedAt'     => $now,
                    'generatedByUid'  => $this->adminId,
                    'generatedByName' => $this->adminName,
                    'updatedAt'       => $now,
                    'updatedByUid'    => $this->adminId,
                    'version'         => $c['prevVersion'] + 1,
                ],
            ];
        }
        return $batchOps;
    }
    /**
     * PHASE 6 STEP 1 — COMPUTE.
     *
     * Greedy placement, extracted verbatim from autoGenerate(). Pure: no reads,
     * no writes, no knowledge of where the result will be stored. This is the
     * ONE placement algorithm; the candidate path must call this and not carry
     * a second copy.
     *
     * Deliberately NOT improved during extraction — same heuristic, same
     * candidate scoring (dayCount * 100 + period), same hard-subject ordering,
     * same two hard constraints (cell free, teacher free). Changing any of that
     * here would make the equivalence claim untestable.
     */
    /**
     * The canonical form of a "Class/Section" key, exactly as day documents
     * store it.
     *
     * Extracted so that a curriculum target and a written day are keyed the
     * same way. They were not, and a shortfall computed against raw assignment
     * keys would have reported every subject as missing.
     */
    private function _canonSection(string $sk): array
    {
        $parts = explode('/', $sk);
        $className = $parts[0] ?? ''; $sectionName = $parts[1] ?? '';
        $cs = \Entity_firestore_sync::normalizeClassSection($className, $sectionName);
        $cs['canonClass']   = $cs['className'] ?: $className;
        $cs['canonSection'] = $cs['section'] ?: $sectionName;
        $cs['key']          = $cs['canonClass'] . '/' . $cs['canonSection'];
        return $cs;
    }

    private function _canonSectionKey(string $sk): string
    {
        return $this->_canonSection($sk)['key'];
    }

    /**
     * What the school asked for, keyed the way days are stored.
     *
     * Summed across assignment rows, because the placer expands each row into
     * its own periodsPerWeek slots — two teachers sharing a subject means the
     * class wants both their periods.
     */
    private function _expectedFrom(array $sectionAssignments): array
    {
        $out = [];
        foreach ($sectionAssignments as $sk => $items) {
            $key = $this->_canonSectionKey((string) $sk);
            foreach ($items as $it) {
                $sub = (string) ($it['subject'] ?? '');
                if ($sub === '') { continue; }
                $out[$key][$sub] = ($out[$key][$sub] ?? 0) + (int) ($it['periodsPerWeek'] ?? 0);
            }
        }
        return $out;
    }

    /** A seed that is derived from the batch, so a run can be re-created. */
    private function _seedFor(string $batchId, int $attempt): int
    {
        return (int) (crc32($batchId . ':' . $attempt) & 0x7FFFFFFF);
    }

    /**
     * ── MULTI-CANDIDATE GENERATION ──────────────────────────────────────
     *
     * One request, several genuinely different candidate timetables, each one a
     * complete and independent canonical version.
     *
     * Nothing about the existing lifecycle changes. Every candidate is created
     * through createGenerationCandidate() exactly as a single generation would
     * be, gets its own generation run, is written day by day, is validated on
     * its own, and can be edited, discarded or published without reference to
     * any other. The batch is a RECORD of one request, not an owner of the
     * candidates it produced: deleting or publishing one has no effect on the
     * rest.
     *
     * ── HOW THEY DIFFER ─────────────────────────────────────────────────
     *
     * Each attempt seeds the placer differently. The seed only decides which of
     * several EQUALLY GOOD placements is taken (see _placeGrid) — no constraint
     * is relaxed, so every candidate is as correct as a single generation would
     * have been. The seed is derived from the batch id and attempt number and
     * stored on the version, so any candidate can be reproduced exactly.
     *
     * ── DUPLICATES ──────────────────────────────────────────────────────
     *
     * A tightly constrained school may have few real alternatives. When an
     * attempt produces a timetable identical to one already kept, it is
     * discarded BEFORE being written and another seed is tried, up to a bounded
     * number of extra attempts. If the school simply has no more distinct
     * timetables, fewer candidates are returned and the summary says so — it
     * never reports five when it built two.
     *
     * @throws Service_exception if the request cannot be satisfied honestly
     */
    public function generateCandidates(
        $planStore, string $planId, int $count = 1, string $scopeClass = ''
    ): array {
        @set_time_limit(300);

        $count = max(1, $count);
        if ($count > self::MAX_CANDIDATES) {
            throw new Service_exception(
                'At most ' . self::MAX_CANDIDATES . ' timetables can be built in one '
                . 'go. Building more takes longer than a web request can safely run.',
                'validation'
            );
        }

        // Inputs are read ONCE and shared by every candidate: they cannot change
        // mid-batch, and re-reading them per candidate would be the dominant
        // cost of the whole request.
        $inputs = $this->_loadGenerationInputs($this->session, $scopeClass);

        // ── refuse honestly rather than time out half-way ────────────────
        $dayDocs      = count($inputs['sections']) * count($inputs['workingDays']);
        $perCandidate = $dayDocs * 2 + 3;      // a read and a write per day, plus run bookkeeping
        $safe = max(1, (int) floor(self::REQUEST_BUDGET / max(1, $perCandidate)));
        if ($count > $safe) {
            throw new Service_exception(
                'This school is too large to build ' . $count . ' timetables in one '
                . 'request. Up to ' . $safe . ' can be built at a time. Build them in '
                . 'smaller batches, or limit the request to one class.',
                'too_large'
            );
        }

        // ── refuse to make timetable resolution impossible ───────────────
        // Past VERSION_SCAN_LIMIT the store cannot prove which timetable is in
        // force and correctly refuses to resolve ANY of them. Creating versions
        // that would cross that line would break the live timetable to make a
        // proposal, so it is refused here instead.
        $existingVersions = $planStore->listVersions();
        $existing         = count($existingVersions);
        $limit    = Timetable_plan_store::VERSION_SCAN_LIMIT;
        if ($existing + $count > $limit) {
            throw new Service_exception(
                'This session already holds ' . $existing . ' timetable versions, and '
                . 'building ' . $count . ' more would pass the limit of ' . $limit
                . ' — past which no timetable can be resolved at all. Discard some '
                . 'unpublished proposals first.',
                'at_limit'
            );
        }

        $batchId  = 'BATCH_' . date('YmdHis') . '_' . substr(uniqid(), -6);
        $expected = $this->_expectedFrom($inputs['sectionAssignments']);

        $summary = [
            'batchId'    => $batchId,
            'requested'  => $count,
            'built'      => 0,
            'duplicates' => 0,
            'failed'     => 0,
            'attempts'   => 0,
            'candidates' => [],
            'notes'      => [],
        ];

        $kept        = [];   // versionId => placements map
        $signatures  = [];   // signature => versionId

        // ── a repeat of an EARLIER batch is still a repeat ────────────────
        //
        // Every batch begins with the unseeded, fully deterministic timetable,
        // so an administrator who disliked the first three and pressed Generate
        // again was handed the very same timetable back as though it were a new
        // option. Duplicate detection therefore starts from the proposals that
        // already exist, not from an empty set.
        //
        // Only proposals are considered, and only those whose measurements are
        // current: a hand-edited candidate's stored signature describes the
        // timetable before the edit, so trusting it could reject a genuinely new
        // alternative. Published versions are excluded deliberately \u2014 a proposal
        // that matches what is already live is worth SEEING, and refusing it
        // would leave an operator with nothing and no way to act on the refusal.
        foreach ($existingVersions as $v) {
            if (!is_array($v)) { continue; }
            $st = (string) ($v['status'] ?? '');
            if ($st !== Timetable_plan_service::STATUS_DRAFT
                && $st !== Timetable_plan_service::STATUS_CANDIDATE) { continue; }
            if (!empty($v['factsStale'])) { continue; }
            $sigPrev = (string) ($v['facts']['signature'] ?? '');
            if ($sigPrev !== '') { $signatures[$sigPrev] = (string) ($v['versionId'] ?? ''); }
        }
        $attempt     = 0;
        $maxAttempts = $count + self::EXTRA_ATTEMPTS;

        while (count($kept) < $count && $attempt < $maxAttempts) {
            $attempt++;
            $summary['attempts'] = $attempt;

            $seed = $this->_seedFor($batchId, $attempt);

            // The FIRST candidate of every batch is the unseeded timetable, so a
            // batch always contains the one a single generation would have
            // produced. Variation is offered alongside it, never instead of it.
            $useSeed = ($attempt === 1) ? 0 : $seed;

            $result = ['sections_generated' => 0, 'unallocated' => 0,
                       'manual_skipped' => 0, 'manual_overwritten' => 0,
                       'manual_cells' => [], 'timetable' => []];

            $grid = $this->_placeGrid(
                $inputs['sections'], $inputs['sectionAssignments'],
                $inputs['workingDays'], $inputs['periodsPerDay'], $useSeed);

            $nothingStored = fn(string $sectionKey, string $day) => null;
            $composed = $this->_composeGeneratedDays(
                $grid, $inputs['periodTimes'], $nothingStored, false, $result);

            $asDays = [];
            foreach ($composed as $c) {
                $asDays[] = ['classSection' => $c['sectionKey'], 'day' => $c['day'],
                             'periods' => $c['periods']];
            }

            // Reject a repeat BEFORE writing it: an identical timetable written
            // and then discarded would leave a dead version behind and consume
            // part of the version budget for nothing.
            $sig = Timetable_metrics::signature($asDays);
            if (isset($signatures[$sig])) {
                $summary['duplicates']++;
                continue;
            }

            try {
                $created   = $planStore->createGenerationCandidate($planId, null);
                $versionId = $created['versionId'];

                $runId = $planStore->beginGenerationRun($planId, $versionId, [
                    'scopeClass'      => $scopeClass,
                    'regeneratedFrom' => null,
                    'mode'            => 'multi',
                    'batchId'         => $batchId,
                    'attempt'         => $attempt,
                    'seed'            => $useSeed,
                ]);

                $written = 0;
                foreach ($composed as $c) {
                    $planStore->saveCandidateDay(
                        $planId, $versionId, $c['sectionKey'], $c['day'],
                        $c['periods'], false, $runId);
                    $written++;
                }
                $planStore->completeGenerationRun($planId, $versionId, $runId, $written);
            } catch (\Throwable $e) {
                // One candidate failing does not fail the batch. The failed run
                // is already marked by the machinery below it, the candidate is
                // refused by readCompleteCandidate(), and the candidates already
                // built are untouched and still usable.
                if (isset($versionId, $runId)) {
                    try {
                        $planStore->failGenerationRun($planId, $versionId, $runId,
                            $written ?? 0, 'multi-candidate write failed: ' . $e->getMessage());
                    } catch (\Throwable $ignored) { /* already logged below */ }
                }
                log_message('error', 'Timetable_service: candidate ' . $attempt
                    . ' of batch ' . $batchId . ' failed: ' . $e->getMessage());
                $summary['failed']++;
                $summary['notes'][] = 'One timetable could not be built and was discarded.';
                continue;
            }

            // Each candidate is validated ON ITS OWN. A verdict is never copied
            // from a sibling: two candidates that differ by one period can
            // differ by a hard violation.
            $verdict = ['hardCount' => null, 'softCount' => null, 'publishable' => null];
            try {
                $v = $planStore->validateCandidate($planId, $versionId);
                $verdict = [
                    'hardCount'   => $v['hardCount'] ?? null,
                    'softCount'   => $v['softCount'] ?? null,
                    'publishable' => $v['publishable'] ?? null,
                ];
            } catch (\Throwable $e) {
                log_message('error', 'Timetable_service: candidate ' . $versionId
                    . ' could not be validated: ' . $e->getMessage());
                $summary['notes'][] = 'One timetable was built but could not be checked; '
                    . 'open it and press Check.';
            }

            $signatures[$sig]   = $versionId;
            $kept[$versionId]   = Timetable_metrics::placements($asDays);
            $summary['built']++;

            $summary['candidates'][] = [
                'versionId' => $versionId,
                'attempt'   => $attempt,
                'seed'      => $useSeed,
                'signature' => $sig,
                'metrics'   => Timetable_metrics::from($asDays, $expected),
                'verdict'   => $verdict,
            ];
        }

        // ── how different are they, really? ──────────────────────────────
        // Distance is measured between every pair, and each candidate carries
        // its distance to its NEAREST sibling — the honest figure, because a
        // candidate that is far from one timetable and identical to another is
        // not a third alternative.
        $ids = array_keys($kept);
        foreach ($summary['candidates'] as $i => $cand) {
            $nearest = null;
            foreach ($ids as $other) {
                if ($other === $cand['versionId']) { continue; }
                $d = Timetable_metrics::distance(
                    self::_daysFromPlacements($kept[$cand['versionId']]),
                    self::_daysFromPlacements($kept[$other]));
                if ($nearest === null || $d['ratio'] < $nearest['ratio']) {
                    $nearest = $d + ['versionId' => $other];
                }
            }
            $summary['candidates'][$i]['distinctness'] = $nearest ?: [
                'differing' => 0, 'cells' => 0, 'ratio' => null, 'identical' => false,
                'versionId' => null,
            ];
        }

        // Store the facts on each version so the comparison screen does not have
        // to recompute them from every candidate's days on every page load.
        foreach ($summary['candidates'] as $cand) {
            $planStore->recordCandidateFacts($planId, $cand['versionId'], [
                'batchId'      => $batchId,
                'attempt'      => $cand['attempt'],
                'seed'         => $cand['seed'],
                // Persisted so a LATER generation can tell it would be repeating
                // this timetable, without re-reading anybody's days.
                'signature'    => $cand['signature'],
                'metrics'      => $cand['metrics'],
                'distinctness' => $cand['distinctness'],
                'computedAt'   => date('c'),
            ]);
        }

        if ($summary['built'] < $summary['requested']) {
            $summary['notes'][] = $summary['built'] === 0
                ? 'No timetable could be built.'
                : 'Only ' . $summary['built'] . ' of the ' . $summary['requested']
                  . ' timetables asked for turned out to be different from each other. '
                  . 'This timetable is tightly constrained, so there may be few real '
                  . 'alternatives.';
        }

        return $summary;
    }

    /** Turn a flat placement map back into day rows, for distance comparison. */
    private static function _daysFromPlacements(array $placements): array
    {
        $byDay = [];
        foreach ($placements as $key => $val) {
            $parts = explode('|', $key);
            if (count($parts) < 3) { continue; }
            [$sec, $day, $idx] = $parts;
            [$subject, $teacherId] = array_pad(explode('|', $val, 2), 2, '');
            $byDay[$sec . '|' . $day]['classSection'] = $sec;
            $byDay[$sec . '|' . $day]['day'] = $day;
            $byDay[$sec . '|' . $day]['periods'][(int) $idx] =
                ['subject' => $subject, 'teacherId' => $teacherId];
        }
        foreach ($byDay as $k => $row) {
            ksort($byDay[$k]['periods']);
        }
        return array_values($byDay);
    }
    /**
     * Every slot, empty -- the shape of a week with nothing placed in it.
     *
     * Deliberately UNLIKE _placeGrid in one respect: that skips a section with
     * no subject assignments, because there is nothing to place. Here a section
     * with no assignments is exactly the case that matters, since a school
     * typing its timetable in by hand may not have filled in assignments at
     * all. Skipping them would produce a blank timetable missing the very
     * classes the operator opened it to fill.
     */
    private function _blankGrid(array $sections, array $workingDays, int $periodsPerDay): array
    {
        $grid = [];
        foreach ($sections as $sec) {
            $sk = $sec['key'];
            foreach ($workingDays as $day) {
                for ($p = 0; $p < $periodsPerDay; $p++) {
                    $grid[$sk][$day][$p] = null;
                }
            }
        }
        return $grid;
    }

    /**
     * Create an empty timetable for the school to fill in by hand.
     *
     * Same inputs and same guards as generation -- working days, periods and
     * their times all come from Period Scheduling, so a hand-built timetable
     * has exactly the shape a generated one would. Nothing is placed: the
     * automatic generator is one way to fill a week and typing is the other,
     * and a school that has not set up subject assignments can still produce a
     * timetable.
     *
     * Written through the same candidate machinery, so a part-written blank
     * fails the same way (ACAD-D24): the run is marked failed, the candidate is
     * refused, and nothing already published is touched.
     *
     * @throws Service_exception on any refusal
     */
    public function createBlankCandidate($planStore, string $planId, string $scopeClass = ''): array
    {
        @set_time_limit(300);

        $inputs = $this->_loadGenerationInputs($this->session, $scopeClass);

        $created   = $planStore->createGenerationCandidate($planId, null);
        $versionId = $created['versionId'];

        $runId = $planStore->beginGenerationRun($planId, $versionId, [
            'scopeClass'      => $scopeClass,
            'regeneratedFrom' => null,
            'mode'            => 'blank',
        ]);

        $result = [
            'sections_generated' => 0, 'unallocated' => 0,
            'manual_skipped' => 0, 'manual_overwritten' => 0,
            'manual_cells' => [], 'timetable' => [],
        ];

        $grid          = $this->_blankGrid($inputs['sections'], $inputs['workingDays'],
                                           $inputs['periodsPerDay']);
        $nothingStored = fn(string $sectionKey, string $day) => null;
        $composed      = $this->_composeGeneratedDays(
            $grid, $inputs['periodTimes'], $nothingStored, false, $result);

        $written = 0;
        foreach ($composed as $c) {
            try {
                $planStore->saveCandidateDay(
                    $planId, $versionId, $c['sectionKey'], $c['day'],
                    $c['periods'], false, $runId);
            } catch (\Throwable $e) {
                $planStore->failGenerationRun($planId, $versionId, $runId, $written,
                    'blank day write failed: ' . $e->getMessage());
                throw new Service_exception(
                    'The blank timetable could not be created and the incomplete one '
                    . 'has been marked unusable. Your existing timetable was not '
                    . 'changed. Please try again.',
                    'generation_failed'
                );
            }
            $written++;
        }

        $planStore->completeGenerationRun($planId, $versionId, $runId, $written);

        return [
            'mode'      => 'blank',
            'versionId' => $versionId,
            'days'      => $written,
            'sections'  => count($inputs['sections']),
        ];
    }
    /**
     * ── A LOCAL RANDOM SOURCE, SEEDED PER RUN ───────────────────────────
     *
     * Never the global mt_rand(): seeding that would change unrelated
     * randomness elsewhere in the same request, and reading it would make a
     * generation run depend on whatever else had consumed numbers first. A run
     * must be reproducible from its seed alone, and that is only true if the
     * generator is private to the run.
     *
     * xorshift32 is small, has no dependencies, and is far better than adequate
     * for choosing between equally good placements. It is not used for anything
     * security-related.
     */
    /** A break's name is a word or two, not a sentence. */
    const BREAK_NAME_MAX = 24;

    /** Absolute ceiling, before school size is even considered. */
    const MAX_CANDIDATES = 5;

    /**
     * Firestore round trips one request may spend on generation.
     *
     * Derived, not chosen: generation runs under set_time_limit(300), and at a
     * conservative 100 ms per round trip 2000 requests is about 200 seconds —
     * inside the limit with real headroom. A request that would exceed it is
     * refused up front, with the number that WOULD fit, rather than timing out
     * part-way and leaving the operator to guess what happened.
     */
    const REQUEST_BUDGET = 2000;

    /** Extra seeds tried when an attempt repeats a timetable already kept. */
    const EXTRA_ATTEMPTS = 4;

    private int $rngState = 1;

    private function _seedRng(int $seed): void
    {
        $s = $seed & 0x7FFFFFFF;
        // 0 is a fixed point of xorshift, so it can never be the state.
        $this->rngState = $s !== 0 ? $s : 0x2545F491;
    }

    /** A number in [0, $n). */
    private function _rngNext(int $n): int
    {
        if ($n <= 1) { return 0; }
        $x = $this->rngState;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5)  & 0xFFFFFFFF;
        $this->rngState = $x & 0xFFFFFFFF;
        return (int) ($this->rngState % $n);
    }

    /**
     * ── HOW HARD IS THIS ASSIGNMENT TO PLACE? ───────────────────────────
     *
     * REPLACES a hardcoded list of English subject names
     * (Mathematics, Math, Science, Physics, Chemistry, Biology, English) that
     * decided which subjects were placed first, and therefore which subjects
     * lost their periods when a week ran out of room.
     *
     * That list was indefensible: a school teaching Sanskrit, Social Science,
     * Maths (with an s) or गणित got no priority at all, and its subjects were
     * the ones silently dropped. Subject IDENTITY is also a matter of school
     * policy — which subject matters most is not the engine's judgement to make.
     *
     * What replaces it is the standard constraint-satisfaction heuristic that
     * every serious timetabler uses: MOST CONSTRAINED FIRST. FET sorts
     * activities "most difficult first" and reports it speeds the search by an
     * order of magnitude. Difficulty here is measured, not asserted:
     *
     *   1. a teacher who must be fitted into more periods across the whole
     *      school has fewer free slots left for each of them;
     *   2. a subject needing more periods a week has more to fit;
     *   3. subject name, purely so the order is total and a run is repeatable.
     *
     * Every term is computed from the school's own data. No subject is
     * privileged by what it is called, and no educational preference is
     * expressed — only which placements are hardest, which is arithmetic.
     */
    private function _difficultyOrder(array $sectionAssignments): array
    {
        $teacherLoad = [];
        foreach ($sectionAssignments as $items) {
            foreach ($items as $it) {
                $tid = (string) ($it['teacherId'] ?? '');
                if ($tid === '') { continue; }
                $teacherLoad[$tid] = ($teacherLoad[$tid] ?? 0) + (int) ($it['periodsPerWeek'] ?? 0);
            }
        }
        return $teacherLoad;
    }

    /**
     * Greedy placement, one section at a time, with teacher availability shared
     * across every section so the generator cannot double-book anybody.
     *
     * ── SLOT ORDERING: WHY THE 100 IS GONE ──────────────────────────────
     *
     * This previously scored each free slot as `dayCount * 100 + periodIndex`
     * and took the lowest. That constant was never a weight. Because a period
     * index is always far below 100, multiplying by 100 guaranteed that
     * dayCount decided the outcome and periodIndex only broke its ties — an
     * ordering, expressed as arithmetic.
     *
     * Timefold's documentation names this pattern SCORE FOLDING and warns
     * against it, and here it also carried a latent defect: a school with 100
     * or more periods in a day would have seen the period index spill into the
     * dayCount rank and silently invert the priority.
     *
     * The comparison below is the same ordering stated outright — spread a
     * subject across days first, prefer earlier periods second. Behaviour is
     * unchanged for every real school; the folding, and the defect, are gone.
     *
     * NOTE FOR THE OWNER: that ordering is itself a policy — it says spreading
     * a subject matters more than teaching it early. This change does not alter
     * it, only stops hiding it. Whether it is the right priority is a decision
     * recorded in the decision pack, not one taken here.
     *
     * ── VARIATION ───────────────────────────────────────────────────────
     *
     * With $seed = 0 the first-ranked slot is taken, which reproduces the
     * previous behaviour exactly. With a non-zero seed, a slot is chosen at
     * random from those that TIE at the best rank — never from worse ones. No
     * constraint is relaxed and no preference is overridden: the only thing
     * that changes is which of several equally good placements is used, which
     * is exactly how FET produces different timetables from one input.
     */
    private function _placeGrid(array $sections, array $sectionAssignments, array $workingDays, int $periodsPerDay, int $seed = 0): array
    {
        $this->_seedRng($seed);
        $teacherLoad = $this->_difficultyOrder($sectionAssignments);

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
            usort($subjectSlots, function ($a, $b) use ($teacherLoad) {
                $al = $teacherLoad[(string) ($a['teacherId'] ?? '')] ?? 0;
                $bl = $teacherLoad[(string) ($b['teacherId'] ?? '')] ?? 0;
                if ($al !== $bl) { return $bl - $al; }                       // busiest teacher first
                $ap = (int) ($a['periodsPerWeek'] ?? 0);
                $bp = (int) ($b['periodsPerWeek'] ?? 0);
                if ($ap !== $bp) { return $bp - $ap; }                       // most periods first
                return strcmp((string) ($a['subject'] ?? ''),                // total order
                              (string) ($b['subject'] ?? ''));
            });
            foreach ($workingDays as $day) {
                $grid[$sk][$day] = array_fill(0, $periodsPerDay, null);
            }
            $daySubjectCount = [];
            foreach ($workingDays as $day) $daySubjectCount[$day] = [];
            foreach ($subjectSlots as $slot) {
                $subj = $slot['subject']; $tid = $slot['teacherId'];

                $bestDay = null; $bestPeriod = null; $tied = [];
                foreach ($workingDays as $day) {
                    $dayCount = $daySubjectCount[$day][$subj] ?? 0;
                    for ($p = 0; $p < $periodsPerDay; $p++) {
                        if ($grid[$sk][$day][$p] !== null) continue;
                        if ($tid !== '' && isset($teacherBusy[$tid][$day][$p])) continue;

                        if ($bestDay === null
                            || $dayCount < $bestDay
                            || ($dayCount === $bestDay && $p < $bestPeriod)) {
                            $bestDay = $dayCount; $bestPeriod = $p;
                            $tied = [['day' => $day, 'period' => $p]];
                        } elseif ($dayCount === $bestDay && $p === $bestPeriod) {
                            $tied[] = ['day' => $day, 'period' => $p];
                        }
                    }
                }

                // Nothing free anywhere: this period of this subject is not
                // placed. The shortfall is counted and reported rather than
                // being quietly absorbed — see _shortfallFrom().
                if ($bestDay === null) { continue; }

                $best = ($seed === 0 || count($tied) === 1)
                    ? $tied[0]
                    : $tied[$this->_rngNext(count($tied))];

                $grid[$sk][$best['day']][$best['period']] = [
                    'subject' => $subj, 'teacherId' => $tid, 'teacherName' => $slot['teacherName'], 'type' => 'class',
                ];
                if ($tid !== '') $teacherBusy[$tid][$best['day']][$best['period']] = $sk;
                $daySubjectCount[$best['day']][$subj] = ($daySubjectCount[$best['day']][$subj] ?? 0) + 1;
            }
        }
        return $grid;
    }

    /**
     * PHASE 6 STEP 1 — WRITE (live adapter).
     *
     * The existing live persistence path, moved verbatim. Chunk size, ordering,
     * stop-on-first-failure and the operator message are unchanged.
     *
     * ACAD-D24 lives here and is NOT fixed: chunks still commit sequentially, so
     * a failure at chunk 3 of 7 still leaves the school partly regenerated. The
     * candidate path is structurally immune; this one is not, and stays open
     * until it is retired.
     *
     * ACAD-D22 IS fixed here, and only in the message: it used to tell the
     * operator to use the generation ID to "roll back any committed chunks",
     * naming a rollback that has never existed anywhere in this codebase. It now
     * says what actually happened — a partial write, nothing undone — and what
     * the generation ID is actually good for. The behaviour is unchanged; a
     * message that promises recovery an operator cannot perform is worse than
     * one that admits the timetable is half-updated.
     */
    private function _commitLiveBatch(array $batchOps, string $generationId): void
    {
        $chunks = array_chunk($batchOps, 450);
        foreach ($chunks as $i => $chunk) {
            $ok = false;
            try { $ok = $this->firebase->firestoreCommitBatch($chunk); }
            catch (\Throwable $e) { log_message('error', "autoGenerate batch chunk {$i} failed: " . $e->getMessage()); }
            if (!$ok) {
                throw new Service_exception(
                    "Auto-generate write failed at chunk " . ($i + 1) . " of " . count($chunks) . ". " . $i . " of " . count($chunks) . " groups of changes were already saved, so some classes now show the new timetable and others still show the old one. Nothing was undone — this path has no automatic rollback. Generation ID {$generationId} identifies the timetables that were changed. Run generation again to finish.",
                    'internal'
                );
            }
        }
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
        // ACAD-D11 — completeness is tracked across EVERY read this method
        // makes, not just the timetable one. See the contract in the return.
        $complete = true;
        $loaded   = 0;

        // 1. Timetable settings (via the existing service method, then add
        //    the legacy keys the master-timetable view expects).
        $settings = [];
        try {
            $fsSettings = $this->firebase->firestoreGet(self::COLLECTION_SET, "{$this->schoolId}_{$this->session}");
            if (is_array($fsSettings)) $settings = $fsSettings;
        } catch (\Throwable $e) {
            // A failed settings read silently falls back to the defaults below
            // (6 periods, 9:00AM-3:00PM, Mon-Sat). For a school configured with
            // 8 periods that renders a 6-period grid, hiding periods 7 and 8
            // with no indication that anything is missing.
            $complete = false;
            log_message('error', 'getMasterTimetable: settings read failed (school='
                . $this->schoolId . ' session=' . $this->session . '): ' . $e->getMessage());
        }

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
        // ACAD-D11 — completeness contract.
        //
        // One doc per (class-section, day), so this set is class-sections x
        // working days. At 6 working days a 500-doc cap silently stops at ~83
        // class-sections, which a large K-12 school reaches (14 grades x 6
        // sections = 504 docs). No orderBy is sent, so Firestore orders by
        // __name__, and docIds are {school}_{session}_{classSection}_{day} —
        // the loss is therefore not a random scatter but WHOLE class-sections
        // at the lexicographic tail, dropped identically on every request.
        //
        // This payload feeds the Master Timetable grid AND the Teacher Workload
        // tab, which is computed client-side by walking exactly these cells. A
        // truncated read under-counts a teacher's periods and then presents the
        // under-count as an authoritative number.
        //
        // Fetching cap+1 detects saturation for the cost of one extra doc. The
        // read is fail-loud because firestoreQuery() returns [] for a FAILED
        // read as well as for an empty school — reporting that as a complete,
        // empty timetable is the same silent-completeness bug in another dress.
        $timetables = [];
        try {
            $fsDocs = $this->firebase->firestoreQueryOrFail(self::COLLECTION_TT, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
            ], null, 'ASC', self::MASTER_SCAN_LIMIT + 1);

            if (count($fsDocs) > self::MASTER_SCAN_LIMIT) {
                $complete = false;
                $fsDocs   = array_slice($fsDocs, 0, self::MASTER_SCAN_LIMIT);
                log_message('error', 'getMasterTimetable: result truncated at '
                    . self::MASTER_SCAN_LIMIT . ' docs (school=' . $this->schoolId
                    . ' session=' . $this->session . ') — grid and workload totals '
                    . 'are incomplete.');
            }
            $loaded = count($fsDocs);

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
            // A failed read is INCOMPLETE, not empty. Without this the UI would
            // render a blank grid as though the school had no timetable at all.
            $complete = false;
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
            // ACAD-D11 completeness contract. The UI must not present an
            // incomplete grid — nor workload totals derived from one — as
            // though they were complete. `complete` is false both when the
            // read saturated the cap and when it failed outright.
            'completeness'        => [
                'complete' => $complete,
                'loaded'   => $loaded,
                'cap'      => self::MASTER_SCAN_LIMIT,
            ],
        ];
    }

    /**
     * Save (or clear) a single (class, section, day, periodIdx) cell.
     * Returns warnings array; throws Service_exception('conflict') on
     * teacher hard-conflict.
     */
    /**
     * ── LEGACY DIRECT WRITE — DO NOT EXTEND ─────────────────────────
     *
     * Writes directly to the legacy `timetables` collection, which is no
     * longer the canonical source of timetable truth. The canonical path is
     * a timetable version: draft or candidate, validated, published, then
     * projected into this collection for consumers that still read it.
     *
     * Single-period edit written straight to the live collection. Superseded by Timetable_plan_store::saveCandidateDay() on a draft or candidate version, which a published version refuses.
     *
     * This is retained because it is still the only working timetable path
     * in production and the Teacher app reads the collection it writes.
     * New feature work must target the version architecture instead; a new
     * direct writer here would recreate the second-source-of-truth problem
     * the version architecture exists to remove.
     */
    public function savePeriod(
        string $classKey, string $section, string $day, int $periodIdx,
        string $subject, string $teacherId, string $teacherName
    ): array {
        $this->_refuseLegacyWrite('This period');

        $validDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        if ($classKey === '' || $section === '' || !in_array($day, $validDays, true) || $periodIdx < 0) {
            throw new Service_exception('Missing or invalid parameters', 'validation');
        }

        $warnings = [];

        // Pre-fetch all timetable docs for this day (single query).
        $allDayDocs = [];
        try {
            $allDayDocs = $this->firebase->firestoreQueryOrFail(self::COLLECTION_TT, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
                ['day',      '==', $day],
            ], null, 'ASC', self::CONFLICT_SCAN_LIMIT);
        } catch (\Throwable $e) {
            // ACAD-D5: the teacher double-booking guard reasons over these docs.
            // Swallowing the failure left the day map EMPTY, which the guard cannot
            // tell apart from "no conflict" — so a save proceeded and silently put
            // one teacher in two sections at the same period. A guard that cannot
            // evaluate must DENY, matching Curriculum_service::_canEdit(), which
            // returns false on Throwable.
            log_message('error', 'Timetable_service: conflict-evidence query failed '
                . '(school=' . $this->schoolId . ' session=' . $this->session
                . ' day=' . $day . '): ' . $e->getMessage());
            throw new Service_exception(
                'Could not verify teacher availability just now, so nothing was saved. '
                . 'Please try again in a moment.',
                'conflict'
            );
        }

        // ACAD-D10: a full page means we may not have seen the whole day, and the
        // row that proves a double-booking could be the one we missed. Truncation
        // is "cannot evaluate", not "no conflict" — the same rule the
        // query-failure path above follows.
        if (count($allDayDocs) >= self::CONFLICT_SCAN_LIMIT) {
            log_message('error', 'Timetable_service: conflict scan hit its limit of '
                . self::CONFLICT_SCAN_LIMIT . ' (school=' . $this->schoolId
                . ' session=' . $this->session . ' day=' . $day
                . ') — cannot prove the absence of a clash.');
            throw new Service_exception(
                'Too many timetable entries to check for clashes on this day, so '
                . 'nothing was saved. Please contact support.',
                'conflict'
            );
        }

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
        $prevVersion = 0;
        $casUpdateTime = '';   // ACAD-D20, see the write below
        try {
            // ACAD-D19: firestoreGet() returns null for a FAILED read just as it
            // does for a genuine 404, so the preservation catch below was
            // UNREACHABLE for every realistic failure mode. The guard read as
            // "no existing day", $existingPeriods stayed empty, and the rebuild
            // blanked every period except the edited one — the exact data loss
            // that catch was written to prevent.
            $existingDoc = $this->firebase->firestoreGetOrFail(self::COLLECTION_TT, $fsDocId);
            if (is_array($existingDoc)) {
                $prevVersion = (int) ($existingDoc['version'] ?? 0);
                // ACAD-D20 — CAS token. decodeDocument() surfaces Firestore's
                // server-assigned updateTime as __updateTime precisely so a
                // caller can use it as a currentDocument.updateTime precondition.
                $casUpdateTime = (string) ($existingDoc['__updateTime'] ?? '');
                if (isset($existingDoc['periods'])) {
                    foreach ($existingDoc['periods'] as $p) {
                        $pi2 = ($p['periodNumber'] ?? 1) - 1;
                        $existingPeriods[$pi2] = $p;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ACAD-D6: this read decides which periods are PRESERVED when the
            // day document is rebuilt below. Swallowing it left $existingPeriods
            // empty, so every period except the edited one was rewritten blank —
            // one cell edit wiped the section's whole day, and the save still
            // reported success. If we cannot see what is stored, we must not
            // overwrite it.
            log_message('error', 'Timetable_service::savePeriod existing-day read failed '
                . '(school=' . $this->schoolId . ' session=' . $this->session
                . ' day=' . $day . '): ' . $e->getMessage());
            throw new Service_exception(
                'Could not read the existing timetable for this day, so nothing was '
                . 'saved. Please try again in a moment.',
                'conflict'
            );
        }

        $fsSettings = [];
        try { $fsSettings = $this->firebase->firestoreGet(self::COLLECTION_SET, "{$this->schoolId}_{$this->session}") ?? []; }
        catch (\Throwable $e) {
            // ACAD-D7: $maxP sizes the rebuilt day. Defaulting it to 8 after a
            // failed read TRUNCATED schools running 9-10 periods, dropping their
            // trailing periods from the document.
            log_message('error', 'Timetable_service::savePeriod settings read failed '
                . '(school=' . $this->schoolId . ' session=' . $this->session . '): '
                . $e->getMessage());
            throw new Service_exception(
                'Could not read the period settings, so nothing was saved. '
                . 'Please try again in a moment.',
                'conflict'
            );
        }
        // ACAD-D7: $maxP sizes the rebuilt day. A literal fallback silently
        // TRUNCATED schools running more periods than the default. If the day
        // length is unknown we cannot safely rebuild the document — refuse,
        // exactly as autoGenerate() does when periodsPerDay is unset.
        $maxP = (int) ($fsSettings['No_of_periods'] ?? $fsSettings['periodsPerDay'] ?? 0);
        if ($maxP <= 0) {
            throw new Service_exception(
                'Period Scheduling is not configured, so the timetable could not be '
                . 'saved. Set periods per day first.',
                'validation'
            );
        }

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

        // ACAD-D20 — LOST UPDATE. This rewrites the day's ENTIRE `periods` array
        // from the snapshot read above, so two admins editing DIFFERENT periods of
        // the same day silently overwrote one another: whoever saved second based
        // their array on a pre-edit snapshot, and the first edit vanished. Both
        // saw "saved". `version` was written as prevVersion + 1 but never checked,
        // so it recorded the collision without preventing it.
        //
        // Fixed with the CAS primitive the platform already provides: the read
        // above captured __updateTime, and the commit below refuses if the stored
        // document has moved since. Nothing is invented here — this is the same
        // currentDocument.updateTime precondition the accounting CAS loop uses.
        $writeOp = [
            'op'         => 'set',
            'collection' => self::COLLECTION_TT,
            'docId'      => $fsDocId,
            'merge'      => true,
            'data'       => [
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
                'version'        => $prevVersion + 1,
                'updatedAt'      => date('c'),
                'updatedByUid'   => $this->adminId,
            ],
        ];
        // An existing day must not have moved; a new one must not have appeared
        // (two admins creating the same day-doc at once is the same race).
        $writeOp['precondition'] = $casUpdateTime !== ''
            ? ['updateTime' => $casUpdateTime]
            : ['exists' => false];

        $commit = $this->firebase->firestoreCommitBatchStatus([$writeOp]);
        $status = (string) ($commit['status'] ?? '');

        if ($status === 'PRECONDITION_CONFLICT') {
            log_message('error', 'Timetable_service::savePeriod CAS conflict (doc='
                . $fsDocId . ' admin=' . $this->adminId . ')');
            throw new Service_exception(
                'Someone else changed this day\'s timetable while you were editing, '
                . 'so your change was not saved — saving it would have undone theirs. '
                . 'Reload to see the current timetable, then make your change again.',
                'conflict'
            );
        }
        if ($status !== 'SUCCESS') {
            log_message('error', 'Timetable_service::savePeriod commit failed (doc='
                . $fsDocId . '): ' . json_encode($commit));
            throw new Service_exception(
                'The timetable could not be saved. Nothing was changed — please try '
                . 'again in a moment.',
                'write_failed'
            );
        }

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
    /**
     * ── LEGACY DIRECT WRITE — DO NOT EXTEND ─────────────────────────
     *
     * Writes directly to the legacy `timetables` collection, which is no
     * longer the canonical source of timetable truth. The canonical path is
     * a timetable version: draft or candidate, validated, published, then
     * projected into this collection for consumers that still read it.
     *
     * Whole-week write straight to the live collection. Superseded by editing a candidate version and publishing it. ACAD-D21 (whole-week stale overwrite) lives here.
     *
     * This is retained because it is still the only working timetable path
     * in production and the Teacher app reads the collection it writes.
     * New feature work must target the version architecture instead; a new
     * direct writer here would recreate the second-source-of-truth problem
     * the version architecture exists to remove.
     */
    public function saveSectionTimetable(string $class, string $section, $timetableRaw): array
    {
        $this->_refuseLegacyWrite('This timetable');

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

        $written = 0;
        try {
            foreach ($clean as $day => $periods) {
                $safeKey = str_replace('/', '_', $sectionKey);
                $docId = "{$this->schoolId}_{$this->session}_{$safeKey}_{$day}";

                // Read the existing day-doc so we can (a) bump the version stamp
                // (required by the integrity verifier) and (b) preserve period
                // times the bulk payload may have omitted instead of wiping them.
                $existing = null;
                // ACAD-D19: same unreachable-catch defect as savePeriod above.
                try { $existing = $this->firebase->firestoreGetOrFail(self::COLLECTION_TT, $docId); }
                catch (\Throwable $e) {
                    // ACAD-D6: this read supplies the version stamp the integrity
                    // verifier depends on, and the period times the bulk payload may
                    // omit. On a swallowed failure the version reset to 0 and those
                    // times were wiped, while the save reported success.
                    log_message('error', 'Timetable_service::saveSectionTimetable '
                        . 'existing-doc read failed (doc=' . $docId . '): ' . $e->getMessage());
                    throw new Service_exception(
                        'Could not read the existing timetable, so nothing was saved. '
                        . 'Please try again in a moment.',
                        'conflict'
                    );
                }
                $prevVersion   = is_array($existing) ? (int) ($existing['version'] ?? 0) : 0;
                $existingByNum = [];
                if (is_array($existing) && is_array($existing['periods'] ?? null)) {
                    foreach ($existing['periods'] as $ep) {
                        if (isset($ep['periodNumber'])) $existingByNum[(int) $ep['periodNumber']] = $ep;
                    }
                }

                $periodDocs = [];
                $periodNum = 1;

                foreach ($periods as $key => $entry) {
                    if (!is_array($entry)) continue;
                    $type = strtolower(trim($entry['type'] ?? 'class'));
                    $isBreak = ($type === 'break' || $type === 'lunch'
                        || stripos((string)$key, 'Break') === 0 || strcasecmp((string)$key, 'Lunch') === 0);

                    $pn = is_numeric($key) ? intval($key) : $periodNum;
                    $startTime = $entry['startTime'] ?? $entry['start_time'] ?? '';
                    $endTime   = $entry['endTime']   ?? $entry['end_time']   ?? '';
                    if ($startTime === '') $startTime = $existingByNum[$pn]['startTime'] ?? '';
                    if ($endTime === '')   $endTime   = $existingByNum[$pn]['endTime']   ?? '';

                    $periodDocs[] = [
                        'periodNumber' => $pn,
                        'subject'      => $isBreak ? '' : ($entry['subject']  ?? $entry['Subject'] ?? ''),
                        'teacher'      => $isBreak ? '' : ($entry['teacher']  ?? $entry['Teacher'] ?? ''),
                        'teacherId'    => $entry['teacherId'] ?? $entry['teacher_id'] ?? '',
                        'startTime'    => $startTime,
                        'endTime'      => $endTime,
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
                    'version'        => $prevVersion + 1,
                    'updatedAt'      => date('c'),
                    'updatedByUid'   => $this->adminId,
                ], true);
                $written++;
            }
        } catch (\Throwable $e) {
            // Do NOT report success on a write failure — surface it to the caller.
            log_message('error', "saveSectionTimetable: Firestore sync failed after {$written} day(s): " . $e->getMessage());
            throw new Service_exception('Failed to save timetable: ' . $e->getMessage(), 'write_failed');
        }

        return ['message' => 'Timetable saved successfully', 'days_written' => $written];
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
            $allDayDocs = $this->firebase->firestoreQueryOrFail(self::COLLECTION_TT, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
                ['day',      '==', $day],
            ], null, 'ASC', self::CONFLICT_SCAN_LIMIT);
        } catch (\Throwable $e) {
            // ACAD-D5: the teacher double-booking guard reasons over these docs.
            // Swallowing the failure left the day map EMPTY, which the guard cannot
            // tell apart from "no conflict" — so a save proceeded and silently put
            // one teacher in two sections at the same period. A guard that cannot
            // evaluate must DENY, matching Curriculum_service::_canEdit(), which
            // returns false on Throwable.
            log_message('error', 'Timetable_service: conflict-evidence query failed '
                . '(school=' . $this->schoolId . ' session=' . $this->session
                . ' day=' . $day . '): ' . $e->getMessage());
            throw new Service_exception(
                'Could not verify teacher availability just now, so nothing was saved. '
                . 'Please try again in a moment.',
                'conflict'
            );
        }

        // ACAD-D10: a full page means we may not have seen the whole day, and the
        // row that proves a double-booking could be the one we missed. Truncation
        // is "cannot evaluate", not "no conflict" — the same rule the
        // query-failure path above follows.
        if (count($allDayDocs) >= self::CONFLICT_SCAN_LIMIT) {
            log_message('error', 'Timetable_service: conflict scan hit its limit of '
                . self::CONFLICT_SCAN_LIMIT . ' (school=' . $this->schoolId
                . ' session=' . $this->session . ' day=' . $day
                . ') — cannot prove the absence of a clash.');
            throw new Service_exception(
                'Too many timetable entries to check for clashes on this day. '
                . 'Please contact support.',
                'conflict'
            );
        }

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
    /**
     * RETIRED — this path no longer writes timetables.
     *
     * The canonical version architecture is the timetable system for every ZenX
     * school. There is no per-school switch: a school that was onboarded this
     * morning and one that has been running for a year both build, check and
     * publish a timetable the same way, and both reach teachers through the same
     * compatibility projection.
     *
     * A flag here would have been a second source of truth spread across
     * tenants — "School A on the old system, School B on the new" — and would
     * have required somebody to enable a timetable before a new school could use
     * one. Neither is the product.
     *
     * The code below this guard is deliberately KEPT. It is the read model, the
     * conflict analysis and the document shape that the projection reproduces,
     * and it is how a rollback would be reasoned about. What it has lost is
     * authority, not existence.
     *
     * @throws Service_exception always
     */
    private function _refuseLegacyWrite(string $what = 'This timetable'): void
    {
        log_message('info', 'Timetable_service: refused a retired legacy direct '
            . 'write (school=' . $this->schoolId . ' session=' . $this->session . ')');
        throw new Service_exception(
            $what . ' is now built and published from Master Timetable, so it can '
            . 'no longer be edited here. Open Master Timetable, make your changes '
            . 'on a proposal, check it, and publish it.',
            'legacy_retired'
        );
    }
    private function _classToFirebaseKey(string $key): string
    {
        return str_replace(['.', '$', '#', '[', ']', '/'], '_', trim($key));
    }

    /** Normalized (case/space-insensitive) match key. */
    private function _nk(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }

    /**
     * Propagate subject-matrix teacher changes into already-generated
     * timetable periods for a class. Called right after a subjectAssignments
     * save so a reassigned teacher shows up on the timetable (and in the app,
     * which matches period.teacherId == myId) IMMEDIATELY, instead of drifting
     * until someone runs the reconcile CLI.
     *
     * Idempotent + surgical: only class-type periods whose subject matches an
     * assignment carrying a teacher, and only when teacherId/name actually
     * differ, are rewritten. Layout, breaks, times and blank/unassigned slots
     * are untouched. Never flips manuallyEdited (this is a system sync, not a
     * human edit). Best-effort: failures are logged, never thrown, so a matrix
     * save never fails because of timetable propagation.
     *
     * @param string $classKey   e.g. "Class 9th" (any casing/format accepted)
     * @param string $sectionKey optional "Section A" to scope to one section;
     *                           empty = every section of the class.
     * @return array ['docsUpdated'=>int,'periodsChanged'=>int,'skippedManual'=>int]
     */
    /**
     * ── LEGACY DIRECT WRITE — DO NOT EXTEND ─────────────────────────
     *
     * Writes directly to the legacy `timetables` collection, which is no
     * longer the canonical source of timetable truth. The canonical path is
     * a timetable version: draft or candidate, validated, published, then
     * projected into this collection for consumers that still read it.
     *
     * Repair path that rewrites live documents from a matrix. Superseded by correcting a candidate and re-projecting.
     *
     * This is retained because it is still the only working timetable path
     * in production and the Teacher app reads the collection it writes.
     * New feature work must target the version architecture instead; a new
     * direct writer here would recreate the second-source-of-truth problem
     * the version architecture exists to remove.
     */
    public function reconcileClassFromMatrix(string $classKey, string $sectionKey = ''): array
    {
        // ── RETIRED 2026-08-29 — no longer writes anything ──────────────
        //
        // This used to run automatically whenever subject assignments were
        // saved. It read the assignment matrix and rewrote the `teacher` and
        // `teacherId` on every LIVE timetable period whose subject matched, so a
        // change to who teaches what silently edited the timetable teachers were
        // already looking at.
        //
        // Under the canonical architecture that is not permissible twice over:
        //   - a PUBLISHED timetable is immutable, and a record of what was
        //     actually in force cannot be edited after the fact;
        //   - a DRAFT or CANDIDATE is somebody's unfinished work, and changing
        //     it under them is exactly the silent-overwrite class this
        //     programme exists to remove.
        //
        // The approved replacement (Option B) is
        // Academic::_timetable_assignments_changed(), which marks the affected
        // draft and candidate verdicts STALE with the reason recorded, so
        // publishing refuses until someone checks them again. Nothing is
        // regenerated, nothing is rewritten, and nothing is deleted.
        //
        // The method is kept, with its signature and return shape, so any
        // caller that reappears gets a truthful zero rather than a fatal error —
        // and a truthful zero is correct: nothing was updated.
        log_message('info', 'Timetable_service::reconcileClassFromMatrix is retired '
            . 'and performed no write (class=' . $classKey . ' section=' . $sectionKey
            . '). Assignment changes now mark affected candidate verdicts stale.');

        return ['docsUpdated' => 0, 'periodsChanged' => 0, 'skippedManual' => 0,
                'retired' => true];
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
