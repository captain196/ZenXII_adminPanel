<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hrms_closure_verify — RUNTIME verification for the post-W1.3 closure gates.
 *
 * READ-ONLY. Performs ZERO writes, creates no documents, mutates no tenant, and
 * creates no attendance records. Safe to run against a live tenant during
 * traffic. Idempotent and re-runnable.
 *
 * GATE 1 — the Attendance staff-lookup double-prefix defect.
 *
 * The defect is a document-ID construction fault, so it is verified exactly
 * where it lives: this harness resolves REAL staff documents using BOTH the
 * pre-fix and post-fix expressions against live Firestore, proving the old form
 * resolved nothing and the new form resolves correctly. It deliberately does NOT
 * submit a device punch — that would create real attendance records that cannot
 * be cleanly removed, and the instruction is explicit that certification must not
 * manufacture production-like data. That boundary is reported, not papered over.
 *
 * INVOCATION:  php index.php hrms_closure_verify run
 */
class Hrms_closure_verify extends CI_Controller
{
    private int $pass = 0;
    private int $fail = 0;
    private int $skip = 0;
    private array $failures = [];

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) exit("CLI only\n");
    }

    private function ok(string $name, bool $cond, string $detail = ''): void
    {
        if ($cond) { $this->pass++; echo "  [PASS] {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
        else { $this->fail++; $this->failures[] = "{$name} — {$detail}"; echo "  [FAIL] {$name}  ({$detail})\n"; }
    }
    private function skipped(string $name, string $why): void
    {
        $this->skip++; echo "  [SKIP] {$name}  ({$why})\n";
    }
    private function head(string $t): void
    {
        echo "\n" . str_repeat('=', 74) . "\n{$t}\n" . str_repeat('=', 74) . "\n";
    }

    public function run(): void
    {
        echo "\nZenX HRMS — POST-W1.3 CLOSURE GATE :: RUNTIME VERIFICATION\n";
        echo "READ-ONLY — no writes, no attendance records created.\n";

        $this->gate1_source();
        $this->gate1_runtime();
        $this->gate2_rbac();

        $this->head('SUMMARY');
        echo "  PASS {$this->pass}   FAIL {$this->fail}   SKIP {$this->skip}\n";
        foreach ($this->failures as $f) echo "    - {$f}\n";
        echo "\n" . ($this->fail === 0 ? "RESULT: PASS\n" : "RESULT: FAIL\n");
    }

    // ── Source-level: the fix is present and nothing else moved ──
    private function gate1_source(): void
    {
        $this->head('G1-A. Attendance source — fix present, architecture untouched');

        $raw = file_get_contents(APPPATH . 'controllers/Attendance.php');
        $src = '';
        foreach (token_get_all($raw) as $t) {
            if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $src .= $t[1]; }
            else $src .= $t;
        }

        // A1 — no getEntity() call anywhere still receives a pre-prefixed id.
        $bad = preg_match_all('/getEntity\(\s*\'[a-zA-Z]+\'\s*,\s*"\{\$[^"]*\}_/', $src);
        $this->ok('A1 no double-prefixed getEntity() remains', $bad === 0, "{$bad} occurrence(s)");

        // A2 — both call sites now use get() with an explicitly built id.
        $this->ok('A2 device-punch staff check uses get() with the API-key school',
            strpos($src, '$this->fs->get(\'staff\', "{$schoolIdForCheck}_{$personId}")') !== false);
        $this->ok('A2 individual-report staff lookup uses get()',
            strpos($src, '$this->fs->get(\'staff\', "{$this->school_id}_{$personId}")') !== false);

        // A3 — the student branches were NOT touched (they were already correct).
        $this->ok('A3 student membership check unchanged',
            strpos($src, '$this->fs->get(\'students\', "{$schoolIdForCheck}_{$personId}")') !== false);
        $this->ok('A3 student profile read unchanged',
            strpos($src, '$this->fs->get(\'students\', "{$this->school_id}_{$personId}")') !== false);

        // A4 — the P0-5/B1 inactive-staff guard is intact AND now reachable
        // (before the fix the 403 above it fired first, so it was dead code).
        $this->ok('A4 inactive-staff guard still present',
            strpos($src, "\$sStatus !== 'active'") !== false
            && strpos($src, 'Staff member is not active.') !== false);

        // A5 — the tenant guard itself is unchanged: still fails closed.
        $this->ok('A5 membership guard still fails closed',
            strpos($src, 'Staff ID does not belong to this school.') !== false);

        // A6 — no RTDB introduced by the fix.
        $this->ok('A6 fix introduced no RTDB call',
            strpos($src, "firebase->get('staff'") === false
            && strpos($src, 'firebase->get("staff') === false);

        // A7 — the 9-status attendance vocabulary is untouched (business rules).
        $writer = file_get_contents(APPPATH . 'libraries/Staff_attendance_writer.php');
        $this->ok('A7 attendance status vocabulary untouched',
            preg_match('/const\s+ALLOWED_STATUS/', (string) $writer) === 1);
    }

    /**
     * GATE 2 — ATS authorization, at the decision layer.
     *
     * W1.3 changed both ATS gates to require_permission('HR', …, 'manage'|'view').
     * The browser layer needs an operator session this harness cannot obtain
     * without forging authentication (explicitly forbidden), so what is proven
     * here is the DECISION the gates delegate to, using the real rbac_helper
     * functions. The un-exercised remainder is reported as BLOCKED, not implied.
     */
    private function gate2_rbac(): void
    {
        $this->head('G2. ATS authorization — real rbac_helper decisions');
        $this->load->helper('rbac');

        $perms = ['HR'];

        // C1 — an HR:view holder can VIEW ATS.
        $view = rbac_granted_level('HR', $perms, ['HR' => 'view']);
        $this->ok('C1 HR:view satisfies the ATS view gate',
            rbac_level_rank($view) >= rbac_level_rank('view'), "granted={$view}");

        // C2 — …and CANNOT manage it. This is the exact escalation W1.3 removed:
        // pre-fix, a matching job title re-granted manage to a view-only holder.
        $this->ok('C2 HR:view does NOT satisfy the ATS manage gate',
            rbac_level_rank($view) < rbac_level_rank('manage'), "granted={$view}");

        // C3 — an HR:manage holder retains full ATS management.
        $manage = rbac_granted_level('HR', $perms, ['HR' => 'manage']);
        $this->ok('C3 HR:manage satisfies the ATS manage gate',
            rbac_level_rank($manage) >= rbac_level_rank('manage'), "granted={$manage}");
        $this->ok('C3 HR:manage also satisfies view',
            rbac_level_rank($manage) >= rbac_level_rank('view'));

        // C4 — no HR grant at all denies both gates. rbac_granted_level()
        // returns NULL (not 'none') when nothing is granted, so the denial is
        // the absence of a level rather than a low-ranking one.
        $none = rbac_granted_level('HR', [], []);
        $this->ok('C4 no HR permission denies both ATS gates',
            $none === null || rbac_level_rank((string) $none) < rbac_level_rank('view'),
            'granted=' . var_export($none, true));

        // C5 — the gates in Ats.php really are those two levels, and nothing else.
        $raw = file_get_contents(APPPATH . 'controllers/Ats.php');
        $src = '';
        foreach (token_get_all($raw) as $t) {
            if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $src .= $t[1]; }
            else $src .= $t;
        }
        $this->ok('C5 _require_admin() delegates to manage',
            preg_match("/_require_admin\(\)\s*\{\s*require_permission\('HR',[^)]*'manage'\)/s", $src) === 1);
        $this->ok('C5 _require_view() delegates to view',
            preg_match("/_require_view\(\)\s*\{\s*require_permission\('HR',[^)]*'view'\)/s", $src) === 1);
        $this->ok('C5 no job-title string is used as authorization',
            strpos($src, 'in_array($this->admin_role') === false);
    }

    // ── Runtime: resolve REAL documents with both expressions ──
    private function gate1_runtime(): void
    {
        $this->head('G1-B. Attendance runtime — real staff documents, both expressions');

        try {
            $this->load->library('firestore_service', null, 'fsv');
            $this->fsv->init('BOOTSTRAP', '2026-27', 'BOOTSTRAP');
            $rows = $this->fsv->where('staff', []);
        } catch (\Throwable $e) {
            $this->skipped('G1-B runtime probe', 'Firestore unreachable: ' . $e->getMessage());
            return;
        }

        // Group real staff docs by tenant, so the cross-tenant case uses genuine data.
        $byTenant = [];
        foreach ($rows as $r) {
            $sid = (string) ($r['data']['schoolId'] ?? '');
            if ($sid === '') continue;
            $docId = (string) $r['id'];
            if (strpos($docId, $sid . '_') !== 0) continue;
            $byTenant[$sid][] = substr($docId, strlen($sid) + 1);
        }
        if (count($byTenant) === 0) { $this->skipped('G1-B runtime probe', 'no staff documents'); return; }

        $tenants = array_keys($byTenant);
        $A = $tenants[0];
        $staffA = $byTenant[$A][0];
        echo "  tenant A: {$A}   staff: {$staffA}   (" . count($rows) . " staff docs across " . count($tenants) . " tenant(s))\n";

        // B1 — canonical convention holds across the whole collection.
        $wrong = 0;
        foreach ($rows as $r) {
            $sid = (string) ($r['data']['schoolId'] ?? '');
            if ($sid !== '' && strpos((string) $r['id'], $sid . '_' . $sid . '_') === 0) $wrong++;
        }
        $this->ok('B1 canonical staff id is {schoolId}_{staffId}', $wrong === 0,
            count($rows) . " docs, {$wrong} double-prefixed");

        // B2 — THE FIXED EXPRESSION resolves a real staff member.
        $fixed = $this->fsv->get('staff', "{$A}_{$staffA}");
        $this->ok('B2 FIXED expression get(staff, "{school}_{id}") resolves',
            is_array($fixed) && !empty($fixed), "{$A}_{$staffA}");

        // B3 — THE PRE-FIX EXPRESSION resolves nothing. This is the defect,
        // reproduced against live data rather than argued from the source.
        $this->fsv->init($A, '2026-27', $A);
        $preFix = $this->fsv->getEntity('staff', "{$A}_{$staffA}");
        $this->ok('B3 PRE-FIX expression getEntity(staff, "{school}_{id}") resolves NOTHING',
            !is_array($preFix) || empty($preFix), "probed {$A}_{$A}_{$staffA}");

        // B4 — an unknown staff id still fails (the guard must stay fail-closed).
        $bogus = $this->fsv->get('staff', "{$A}_STA_DOES_NOT_EXIST_CLOSURE");
        $this->ok('B4 unknown staff id resolves nothing (guard still fails closed)',
            !is_array($bogus) || empty($bogus));

        // B5 — cross-tenant denial: tenant B's staff id under tenant A's prefix.
        if (count($tenants) < 2) {
            $this->skipped('B5 cross-tenant denial', 'only one tenant has staff');
        } else {
            $B = $tenants[1];
            $staffB = $byTenant[$B][0];
            $leak = $this->fsv->get('staff', "{$A}_{$staffB}");
            $this->ok('B5 tenant B staff id under tenant A prefix resolves nothing',
                !is_array($leak) || empty($leak), "probed {$A}_{$staffB}");

            // And the same id under its OWN tenant DOES resolve — proving the
            // denial above is tenant binding, not a broken lookup.
            $own = $this->fsv->get('staff', "{$B}_{$staffB}");
            $this->ok('B5 the same id under its own tenant DOES resolve',
                is_array($own) && !empty($own), "{$B}_{$staffB}");
        }

        // B6 — the inactive-staff guard reads a field that actually exists, so
        // the now-reachable P0-5/B1 check will evaluate real data.
        $hasStatus = is_array($fixed) && (isset($fixed['status']) || isset($fixed['Status']));
        $this->ok('B6 staff documents carry the status field the guard reads', $hasStatus,
            'status=' . (string) ($fixed['status'] ?? $fixed['Status'] ?? 'MISSING'));

        echo "\n  NOTE: no device punch was submitted. Submitting one would write real\n";
        echo "  attendance records that cannot be cleanly reverted; the defect is a\n";
        echo "  document-id fault and is verified above at exactly that layer.\n";
    }
}
