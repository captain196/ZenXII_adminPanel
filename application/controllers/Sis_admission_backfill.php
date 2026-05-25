<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Sis_admission_backfill — P2.1.7 bounded historical ADMISSION backfill.
 *
 * Synthesizes canonical ADMISSION History events into studentHistory for
 * students who pre-date the P2.1.3 (CARRY-006) writer fix and therefore
 * have no ADMISSION audit entry.
 *
 * Target population: students with no ADMISSION event in studentHistory.
 * Source data per student:
 *   • Admission Date / admissionDate field   → changed_at
 *   • Class / className                       → metadata.class
 *   • Section / section                       → metadata.section
 *   • application_id                          → metadata.application_id (CRM-path)
 *   • inferred changed_by = "System (P2.1.7 backfill)"
 *
 * Doc-ID convention:
 *   studentHistory/{schoolFs}_{studentId}_{histKey}
 * where histKey = YmdHis_<random> with YmdHis derived from admission date
 * (provides chronological ordering matching forward writes).
 *
 * Idempotency:
 *   createDocument fails-if-exists naturally guards against double-backfill.
 *   Additionally, per-student dry-run check: if ANY ADMISSION event already
 *   exists in studentHistory for that student, skip (don't add a duplicate).
 *
 * INVOCATION:
 *   php index.php sis_admission_backfill dry_run
 *   php index.php sis_admission_backfill apply
 *   php index.php sis_admission_backfill verify
 *   Env: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 */
class Sis_admission_backfill extends CI_Controller
{
    private string $schoolFs = '';
    private string $sessionYear = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Sis_admission_backfill is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');
        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR env vars.\n";
            exit(1);
        }
    }

    public function dry_run(): void { $this->_run(false); }
    public function apply(): void   { $this->_run(true);  }

    public function verify(): void
    {
        echo "=== Sis_admission_backfill verify ===\n";
        echo "schoolFs={$this->schoolFs}\n\n";

        $students = $this->_fetch_students();
        $history  = $this->_fetch_history_by_student();

        $with = 0;
        $without = [];
        foreach ($students as $sid => $sd) {
            $h = $history[$sid] ?? [];
            $hasAdmission = false;
            foreach ($h as $entry) {
                if (is_array($entry) && (string)($entry['action'] ?? '') === 'ADMISSION') {
                    $hasAdmission = true; break;
                }
            }
            if ($hasAdmission) $with++;
            else $without[] = $sid;
        }
        echo "students with ADMISSION event: {$with} / " . count($students) . "\n";
        echo "students MISSING ADMISSION event: " . count($without) . "\n";
        foreach ($without as $sid) echo "    - {$sid}\n";

        if (empty($without)) {
            echo "\n✅ All students have canonical ADMISSION events. Backfill complete.\n";
        } else {
            echo "\nℹ " . count($without) . " student(s) still missing ADMISSION events.\n";
        }
        echo "=== End verify ===\n";
    }

    // ── Internals ────────────────────────────────────────────────────

    private function _fetch_students(): array
    {
        $out = [];
        try {
            $rows = $this->firebase->firestoreQuery('students', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $sid = (string)($d['studentId'] ?? $d['User ID'] ?? $d['userId'] ?? $r['id'] ?? '');
                if ($sid !== '') $out[$sid] = $d;
            }
        } catch (\Throwable $e) {
            echo "ERROR fetching students: " . $e->getMessage() . "\n";
        }
        return $out;
    }

    private function _fetch_history_by_student(): array
    {
        $out = [];
        try {
            $rows = $this->firebase->firestoreQuery('studentHistory', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 5000);
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $sid = (string)($d['studentId'] ?? '');
                $hk  = (string)($d['histKey']   ?? '');
                if ($sid === '' || $hk === '') continue;
                if (!isset($out[$sid])) $out[$sid] = [];
                $out[$sid][$hk] = $d;
            }
        } catch (\Throwable $e) {
            echo "ERROR fetching studentHistory: " . $e->getMessage() . "\n";
        }
        return $out;
    }

    private function _admission_ts_from_student(array $studentData): array
    {
        // Returns [ymdhis, changed_at]
        // Prefers admissionDate / Admission Date; falls back to createdAt.
        $cands = [
            $studentData['admissionDate']    ?? null,
            $studentData['Admission Date']   ?? null,
            $studentData['createdAt']        ?? null,
        ];
        $raw = '';
        foreach ($cands as $c) {
            if (is_string($c) && $c !== '') { $raw = $c; break; }
        }
        if ($raw === '') {
            // Fall back to current time minus a known historical offset
            $raw = '2026-04-01';
        }
        // Parse various formats: "03-04-2026", "2026-04-03", "2026-04-03T..."
        $ts = false;
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $raw, $m)) {
            $ts = strtotime("{$m[3]}-{$m[2]}-{$m[1]}");
        } else {
            $ts = strtotime($raw);
        }
        if ($ts === false) $ts = strtotime('2026-04-01');
        return [date('YmdHis', $ts), date('Y-m-d H:i:s', $ts)];
    }

    private function _run(bool $apply): void
    {
        $mode = $apply ? 'APPLY' : 'DRY-RUN';
        echo "=== Sis_admission_backfill {$mode} ===\n";
        echo "schoolFs={$this->schoolFs}  session={$this->sessionYear}\n\n";

        $students = $this->_fetch_students();
        $history  = $this->_fetch_history_by_student();

        echo "students fetched: " . count($students) . "\n";
        echo "students with existing studentHistory entries: " . count($history) . "\n\n";

        $stats = [
            'eligible'      => 0,
            'skipped_has'   => 0,
            'would_create'  => 0,
            'created'       => 0,
            'skipped_exists'=> 0,
            'failed'        => 0,
        ];

        foreach ($students as $sid => $sd) {
            $h = $history[$sid] ?? [];
            $hasAdmission = false;
            foreach ($h as $entry) {
                if (is_array($entry) && (string)($entry['action'] ?? '') === 'ADMISSION') {
                    $hasAdmission = true; break;
                }
            }
            if ($hasAdmission) {
                $stats['skipped_has']++;
                continue;
            }
            $stats['eligible']++;

            [$ymdhis, $changedAt] = $this->_admission_ts_from_student($sd);
            $histKey = $ymdhis . '_' . bin2hex(random_bytes(3));
            $docId = $this->schoolFs . '_' . $sid . '_' . $histKey;

            $className = (string)($sd['className'] ?? $sd['Class']   ?? '');
            $section   = (string)($sd['section']   ?? $sd['Section'] ?? '');
            $appId     = (string)($sd['application_id'] ?? '');

            $description = "Student admitted to {$className} / {$section} ({$this->sessionYear}) [P2.1.7 historical backfill]";

            $data = [
                'schoolId'    => $this->schoolFs,
                'studentId'   => $sid,
                'histKey'     => $histKey,
                'action'      => 'ADMISSION',
                'description' => $description,
                'changed_by'  => 'System (P2.1.7 backfill)',
                'changed_at'  => $changedAt,
                'metadata'    => [
                    'class'    => $className,
                    'section'  => $section,
                    'session'  => $this->sessionYear,
                    'application_id' => $appId,
                    'source'   => 'p2_1_7_historical_backfill',
                ],
                'backfilledAt' => date('c'),
                'backfillSource' => 'sis_admission_backfill_p2_1_7',
            ];

            if (!$apply) {
                echo "  [DRY-RUN] {$sid}: would create studentHistory/{$docId}\n";
                echo "    class={$className} section={$section} changed_at={$changedAt}\n";
                $stats['would_create']++;
                continue;
            }

            try {
                // Idempotency: check if a doc at this exact docId already exists (unlikely
                // since histKey has random suffix, but defensive). Since createDocument
                // fails-if-exists handles uniqueness, simpler to just create.
                $ok = $this->firebase->firestoreCreate('studentHistory', $docId, $data);
                if ($ok) {
                    echo "  [CREATED] studentHistory/{$docId}  ({$sid} ADMISSION at {$changedAt})\n";
                    $stats['created']++;
                } else {
                    echo "  [SKIP] {$docId} createDocument returned false\n";
                    $stats['skipped_exists']++;
                }
            } catch (\Throwable $e) {
                echo "  [FAIL] {$sid}: " . $e->getMessage() . "\n";
                $stats['failed']++;
            }
        }

        echo "\n── Summary ──\n";
        foreach ($stats as $k => $v) echo "  {$k}: {$v}\n";
        echo "=== End {$mode} ===\n";
    }
}
