<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Timetables_verify — Academic Planner Tier 1.4 timetables canonical verification.
 *
 * READ-ONLY CLI tool. Verifies canonical schema per Timetable_service.php:412-431
 * (auto-gen) + :795-808 (single-cell edit):
 *
 *   Collection: timetables
 *   Doc-id:     {schoolId}_{session}_{sectionKey}_{day}
 *
 *   Required canonical fields:
 *     schoolId, session, className, section, classOrder, sectionCode,
 *     sectionKey, day, periods (array), manuallyEdited (bool),
 *     updatedAt, updatedByUid, version
 *
 *   Auto-gen only:
 *     generationId, generatedAt, generatedByUid, generatedByName
 *
 *   Period sub-doc canonical:
 *     periodNumber, subject, teacher, teacherId, startTime, endTime,
 *     room, type ('class' | 'break' | 'lunch' | ...)
 *
 *   Day enum: Monday | Tuesday | Wednesday | Thursday | Friday | Saturday | Sunday
 *
 *   className / section follow cross-system T1.1 canon ("Class N(th|st|...)" + "Section X")
 *
 * INVOCATION:
 *   php index.php timetables_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Timetables_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const VALID_DAYS = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    private const REQUIRED_TT_FIELDS = [
        'schoolId', 'session', 'className', 'section', 'classOrder', 'sectionCode',
        'sectionKey', 'day', 'periods', 'updatedAt', 'version',
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Timetables_verify is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
    }

    /** CLI: php index.php timetables_verify verify */
    public function verify(): void
    {
        echo "=== Academic Planner Tier 1.4 timetables canonical verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('timetables', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR loading timetables: " . $e->getMessage() . "\n";
            return;
        }

        $total = count($rows);
        echo "Total timetables docs: {$total}\n\n";
        if ($total === 0) {
            echo "=== T1.4 TRIVIAL PASS — no timetables in scope ===\n";
            return;
        }

        $sectionKeyTally = [];
        $dayTally        = [];
        $classNameTally  = [];
        $sectionTally    = [];
        $manualTally     = [0 => 0, 1 => 0];
        $genIdTally      = [];
        $versionDist     = [];

        $missingFields    = [];
        $docIdMismatch    = [];
        $badDay           = [];
        $classNameDrift   = [];
        $sectionDrift     = [];
        $emptyPeriods     = [];
        $periodGap        = [];   // doc => periods array length vs expected sequence
        $periodFieldMissing = []; // periods array element missing canonical sub-fields
        $periodTeacherMissing = [];

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');

            // Field presence
            $missing = [];
            foreach (self::REQUIRED_TT_FIELDS as $f) {
                if (!array_key_exists($f, $data) || ($f !== 'periods' && ($data[$f] === null || $data[$f] === ''))) {
                    $missing[] = $f;
                }
            }
            if (!empty($missing)) $missingFields[$docId] = $missing;

            // Doc-id pattern: {schoolFs}_{session}_{sectionKey}_{day}
            $expectedPrefix = "{$this->schoolFs}_{$this->sessionYear}_";
            if (strpos($docId, $expectedPrefix) !== 0) {
                $docIdMismatch[] = "{$docId} — expected prefix \"{$expectedPrefix}\"";
            }

            // Day enum
            $day = (string)($data['day'] ?? '');
            if ($day !== '') {
                $dayTally[$day] = ($dayTally[$day] ?? 0) + 1;
                if (!in_array($day, self::VALID_DAYS, true)) {
                    $badDay[] = "{$docId}: day=\"{$day}\"";
                }
            }

            // Class/section canonical
            $cn = (string)($data['className'] ?? '');
            if ($cn !== '') {
                $classNameTally[$cn] = ($classNameTally[$cn] ?? 0) + 1;
                if (!preg_match('/^Class\s+\S+/', $cn)) {
                    $classNameDrift[] = "{$docId}: className=\"{$cn}\"";
                }
            }
            $sec = (string)($data['section'] ?? '');
            if ($sec !== '') {
                $sectionTally[$sec] = ($sectionTally[$sec] ?? 0) + 1;
                if (!preg_match('/^Section\s+\S+/', $sec)) {
                    $sectionDrift[] = "{$docId}: section=\"{$sec}\"";
                }
            }

            $sk = (string)($data['sectionKey'] ?? '');
            if ($sk !== '') $sectionKeyTally[$sk] = ($sectionKeyTally[$sk] ?? 0) + 1;

            // manuallyEdited bool
            $me = $data['manuallyEdited'] ?? null;
            if ($me === true) $manualTally[1]++;
            elseif ($me === false) $manualTally[0]++;

            // generationId tally
            $gid = (string)($data['generationId'] ?? '');
            if ($gid !== '') $genIdTally[$gid] = ($genIdTally[$gid] ?? 0) + 1;

            // version
            $ver = (int)($data['version'] ?? 0);
            $versionDist[$ver] = ($versionDist[$ver] ?? 0) + 1;

            // periods array structure
            $periods = $data['periods'] ?? null;
            if (!is_array($periods) || count($periods) === 0) {
                $emptyPeriods[] = "{$docId}: periods empty or missing";
            } else {
                // Check periodNumber sequence
                $expectedNums = [];
                $teacherless = 0;
                $fieldMiss = 0;
                foreach ($periods as $idx => $p) {
                    if (!is_array($p)) continue;
                    $pn = $p['periodNumber'] ?? null;
                    if (is_numeric($pn)) $expectedNums[] = (int)$pn;
                    foreach (['periodNumber','subject','teacher','teacherId','type'] as $f) {
                        if (!array_key_exists($f, $p)) { $fieldMiss++; break; }
                    }
                    // class-type period with empty teacherId
                    $type = (string)($p['type'] ?? 'class');
                    $tid  = (string)($p['teacherId'] ?? '');
                    if ($type === 'class' && $tid === '' && (string)($p['subject'] ?? '') !== '') {
                        $teacherless++;
                    }
                }
                sort($expectedNums);
                $contig = true;
                foreach ($expectedNums as $i => $n) {
                    if ($n !== $i + 1) { $contig = false; break; }
                }
                if (!$contig && !empty($expectedNums)) {
                    $periodGap[] = "{$docId}: periodNumbers not contiguous (got " . implode(',', $expectedNums) . ")";
                }
                if ($fieldMiss > 0) $periodFieldMissing[] = "{$docId}: {$fieldMiss} periods missing canonical sub-fields";
                if ($teacherless > 0) $periodTeacherMissing[] = "{$docId}: {$teacherless} class-type periods with subject but no teacherId";
            }
        }

        // ── Distribution report ─────────────────────────────────────────
        echo "─── Distribution ───\n";
        echo "Day:\n";
        foreach (self::VALID_DAYS as $vd) {
            $c = $dayTally[$vd] ?? 0;
            if ($c > 0) printf("  %-12s x %d\n", $vd, $c);
        }
        echo "\nclassName:\n";
        ksort($classNameTally);
        foreach ($classNameTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nsection:\n";
        ksort($sectionTally);
        foreach ($sectionTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nsectionKey distribution:\n";
        ksort($sectionKeyTally);
        foreach ($sectionKeyTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nmanuallyEdited:\n";
        printf("  true=%d  false=%d\n", $manualTally[1], $manualTally[0]);
        echo "\ngenerationId distribution:\n";
        echo "  distinct count: " . count($genIdTally) . "\n";
        echo "\nVersion:\n";
        ksort($versionDist);
        foreach ($versionDist as $v => $c) echo "  v{$v} x {$c}\n";

        // ── Conformance ─────────────────────────────────────────────────
        echo "\n─── Conformance ───\n";
        echo "Docs missing required fields: " . count($missingFields) . "\n";
        foreach ($missingFields as $id => $miss) echo "  - {$id}: " . implode(',', $miss) . "\n";
        echo "\nDoc-id prefix mismatches: " . count($docIdMismatch) . "\n";
        foreach ($docIdMismatch as $row) echo "  - {$row}\n";
        echo "\nInvalid day values: " . count($badDay) . "\n";
        foreach ($badDay as $row) echo "  - {$row}\n";
        echo "\nclassName canonical drift: " . count($classNameDrift) . "\n";
        foreach ($classNameDrift as $row) echo "  - {$row}\n";
        echo "\nsection canonical drift: " . count($sectionDrift) . "\n";
        foreach ($sectionDrift as $row) echo "  - {$row}\n";
        echo "\nEmpty/missing periods array: " . count($emptyPeriods) . "\n";
        foreach ($emptyPeriods as $row) echo "  - {$row}\n";
        echo "\nperiodNumber sequence gaps: " . count($periodGap) . "\n";
        foreach ($periodGap as $row) echo "  - {$row}\n";
        echo "\nPeriod sub-doc missing canonical fields: " . count($periodFieldMissing) . "\n";
        foreach ($periodFieldMissing as $row) echo "  - {$row}\n";
        echo "\nClass-type periods with subject but missing teacherId: " . count($periodTeacherMissing) . "\n";
        foreach ($periodTeacherMissing as $row) echo "  - {$row}\n";

        // ── Verdict ─────────────────────────────────────────────────────
        $criticalDrift = count($missingFields) + count($badDay) + count($classNameDrift) + count($sectionDrift) + count($emptyPeriods) + count($periodGap);
        $minorDrift = count($docIdMismatch) + count($periodFieldMissing) + count($periodTeacherMissing);

        echo "\n─── Verdict ───\n";
        if ($criticalDrift > 0) {
            echo "🛑 FREEZE_REQUIRED candidate — {$criticalDrift} critical drift indicators (note: legacy compensation may apply; cross-check before escalation)\n";
        } elseif ($minorDrift > 0) {
            echo "⚠ WATCH — {$minorDrift} minor drift indicators\n";
        } else {
            echo "✅ T1.4 NORMAL — all {$total} timetables fully canonical\n";
        }

        echo "\n=== End verification ===\n";
    }
}
