<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_qa_f12 — adversarial QA harness for F12.1 (CBSE Compliance).
 *
 * READ-ONLY. Performs **zero writes** to Firestore. This is deliberate and is
 * the whole design constraint: the Transport module is frozen, and an earlier
 * F12.1 probe run left 114 orphaned `vehicleCompliance` rows behind when its
 * teardown missed them. This harness therefore never calls a code path that
 * reaches a `setEntity`.
 *
 * ─── HOW THE WRITE PATHS ARE TESTED WITHOUT WRITING ──────────────────────
 * `attestRule()` / `waiveRule()` validate in a fixed order and throw before
 * they persist anything:
 *
 *     _assertRuleTarget(vehicle, rule)  →  input validation  →  setEntity
 *
 * So every REJECT case can be driven against a real vehicle safely — the
 * exception fires before the write.
 *
 * Proving the ACCEPT side of a boundary is the harder half, because a fully
 * valid call WOULD write. The harness uses **paired isolation**: make the bound
 * under test valid and some *other* bound invalid, then assert which error
 * surfaced. If the "other" error is raised, the bound under test necessarily
 * passed. Example — to prove a 20-character reason is accepted, send a
 * 20-character reason with a +91-day expiry and require the 90-DAY error.
 * Nothing is persisted either way.
 *
 * Pure logic (`_statusFactor`, `_tierFor`) is exercised directly through
 * reflection, so scoring and tier boundaries are tested as functions rather
 * than inferred from fixture data.
 *
 * CLI-only:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_qa_f12 run
 */
class Transport_qa_f12 extends CI_Controller
{
    private int $pass = 0;
    private int $fail = 0;
    /** @var array<int,string> */
    private array $failures = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
    }

    // ── assertion helpers ────────────────────────────────────────────────

    private function ok(string $id, string $what): void
    {
        $this->pass++;
        printf("  PASS  %-12s %s\n", $id, $what);
    }

    private function bad(string $id, string $what, string $detail): void
    {
        $this->fail++;
        $this->failures[] = "{$id} — {$what}: {$detail}";
        printf("  FAIL  %-12s %s\n          → %s\n", $id, $what, $detail);
    }

    private function eq(string $id, $expected, $actual, string $what): void
    {
        if ($expected === $actual) { $this->ok($id, $what); return; }
        $this->bad($id, $what, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    private function truthy(string $id, bool $cond, string $what, string $detail = ''): void
    {
        $cond ? $this->ok($id, $what) : $this->bad($id, $what, $detail !== '' ? $detail : 'condition false');
    }

    /**
     * Call $fn and require it to throw with a message matching $needle.
     * Used for every reject case AND for the paired-isolation accept cases.
     */
    private function throws(string $id, string $needle, callable $fn, string $what): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, $needle) !== false) { $this->ok($id, $what); return; }
            $this->bad($id, $what, "threw the WRONG error — wanted “{$needle}”, got “{$msg}”");
            return;
        }
        $this->bad($id, $what, 'did NOT throw (input was accepted, and may have been persisted)');
    }

    // ── entry point ──────────────────────────────────────────────────────

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }

        echo "==================================================\n";
        echo " F12.1 CBSE Compliance — adversarial QA (READ-ONLY)\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        // REQUIRED: _assertRuleTarget() references Transport_service::COL_VEHICLES.
        // Without this load every attest/waive call dies with
        // 'Class "Transport_service" not found' before any validation runs.
        $this->load->library('transport_service', null, 'trn');
        $this->load->library('transport_compliance_service', null, 'cmp');
        $this->cmp->init($this->fs, $sid, 'QA_F12_READONLY');

        $this->sectionA_rulePack();
        $this->sectionB_scoring();
        $this->sectionC_tiers();
        $this->sectionD_waiverBounds($sid);
        $this->sectionE_attestation($sid);
        $this->sectionF_evidenceDiscipline();
        $this->sectionG_tenantIsolation();
        $this->sectionH_orphanAudit();

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        if ($this->failures !== []) {
            echo "\n DEFECTS:\n";
            foreach ($this->failures as $f) { echo "   • {$f}\n"; }
        }
        echo "==================================================\n";
        exit($this->fail === 0 ? 0 : 2);
    }

    // ── A. rule-pack integrity (pure constant, no I/O) ───────────────────

    private function sectionA_rulePack(): void
    {
        echo "\n[A] CBSE rule-pack integrity\n";
        $pack = Transport_compliance_service::CBSE_RULE_PACK;

        $this->eq('A1', 19, count($pack), '19 rules in the pack');

        $weight = 0;
        foreach ($pack as $r) { $weight += (int) ($r['severity_weight'] ?? 0); }
        $this->eq('A2', 144, $weight, 'total severity weight is 144 (spec §2.4 as corrected)');

        $blocking = 0;
        foreach ($pack as $r) { if (!empty($r['blocks_dispatch'])) { $blocking++; } }
        $this->eq('A3', 8, $blocking, '8 dispatch-blocking rules');

        $codes = array_map(static fn($r) => (string) ($r['code'] ?? ''), $pack);
        $this->eq('A4', count($codes), count(array_unique($codes)), 'every rule code is unique');
        $this->truthy('A5', !in_array('', $codes, true), 'no rule has an empty code');

        $missing = [];
        foreach ($pack as $i => $r) {
            foreach (['code', 'label', 'category', 'severity_weight', 'evaluation', 'blocks_dispatch'] as $k) {
                if (!array_key_exists($k, $r)) { $missing[] = "#{$i}.{$k}"; }
            }
        }
        $this->truthy('A6', $missing === [], 'every rule carries all 6 required keys',
            'missing: ' . implode(', ', $missing));

        $badEval = [];
        foreach ($pack as $r) {
            $e = (string) ($r['evaluation'] ?? '');
            if (!in_array($e, Transport_compliance_service::EVALUATIONS, true)) {
                $badEval[] = ($r['rule_code'] ?? '?') . "={$e}";
            }
        }
        $this->truthy('A7', $badEval === [], 'every evaluation mode is in the declared enum',
            'invalid: ' . implode(', ', $badEval));

        $nonPositive = [];
        foreach ($pack as $r) {
            $w = $r['severity_weight'] ?? null;
            if (!is_int($w) || $w <= 0) { $nonPositive[] = (string) ($r['rule_code'] ?? '?'); }
        }
        $this->truthy('A8', $nonPositive === [], 'all severity weights are positive integers',
            'offenders: ' . implode(', ', $nonPositive));

        // A11 — the constant→persisted field mapping. The pack declares 'code';
        // seedRulePack() writes it as 'rule_code'. Every consumer (posture,
        // attest, waive, the admin tab) reads 'rule_code', so a regression that
        // persisted 'code' verbatim would break the whole feature silently.
        $persisted = $this->cmp->listRules();
        $pCodes = [];
        foreach ($persisted as $r) {
            $c = (string) ($r['rule_code'] ?? '');
            if ($c !== '') { $pCodes[] = $c; }
        }
        $unmapped = array_diff($codes, $pCodes);
        $this->truthy('A11', $persisted !== [] && $unmapped === [],
            'every pack `code` is persisted as `rule_code` (' . count($pCodes) . ' rules read back)',
            $persisted === [] ? 'listRules() returned nothing' : 'not persisted: ' . implode(', ', $unmapped));

        $absent = array_diff(Transport_compliance_service::DEFERRED_DERIVED_RULES, $codes);
        $this->truthy('A9', $absent === [], 'both DEFERRED_DERIVED_RULES exist in the pack',
            'not in pack: ' . implode(', ', $absent));

        // A deferred rule must not be silently auto-evaluated.
        $deferredNotDerived = [];
        foreach ($pack as $r) {
            if (in_array((string) ($r['code'] ?? ''), Transport_compliance_service::DEFERRED_DERIVED_RULES, true)
                && (string) ($r['evaluation'] ?? '') !== 'derived') {
                $deferredNotDerived[] = (string) $r['code'];
            }
        }
        $this->truthy('A10', $deferredNotDerived === [], 'deferred rules are declared evaluation=derived',
            'mismatched: ' . implode(', ', $deferredNotDerived));
    }

    // ── B. scoring factors (reflection on the pure function) ─────────────

    private function sectionB_scoring(): void
    {
        echo "\n[B] Status → score factor\n";
        $m = new ReflectionMethod(Transport_compliance_service::class, '_statusFactor');
        $m->setAccessible(true);
        $f = fn(string $s) => $m->invoke($this->cmp, $s);

        $this->eq('B1', 1.0,  $f(Transport_compliance_service::STATUS_COMPLIANT),     'Compliant = 1.0');
        $this->eq('B2', 0.75, $f(Transport_compliance_service::STATUS_EXPIRING_SOON), 'Expiring_Soon = 0.75');
        $this->eq('B3', 0.5,  $f(Transport_compliance_service::STATUS_WAIVED),        'Waived = 0.5 (a waiver is partial credit, never full)');
        $this->eq('B4', 0.0,  $f(Transport_compliance_service::STATUS_NON_COMPLIANT), 'Non_Compliant = 0.0');
        $this->eq('B5', 0.0,  $f(Transport_compliance_service::STATUS_NOT_ASSESSED),  'Not_Assessed = 0.0');
        $this->eq('B6', 0.0,  $f('Totally_Unknown_Status'),                            'unknown status fails closed at 0.0');
        $this->eq('B7', 0.0,  $f(''),                                                  'empty status fails closed at 0.0');
        $this->eq('B8', 0.0,  $f('compliant'),                                         'status match is case-SENSITIVE (lowercase ≠ Compliant)');
    }

    // ── C. tier boundaries (reflection on the pure function) ─────────────

    private function sectionC_tiers(): void
    {
        echo "\n[C] Score → tier boundaries\n";
        $m = new ReflectionMethod(Transport_compliance_service::class, '_tierFor');
        $m->setAccessible(true);
        $t = fn(int $s, bool $b) => $m->invoke($this->cmp, $s, $b);

        $C = Transport_compliance_service::TIER_COMPLIANT;
        $MI = Transport_compliance_service::TIER_MINOR_GAPS;
        $MA = Transport_compliance_service::TIER_MAJOR_GAPS;
        $AR = Transport_compliance_service::TIER_AT_RISK;

        $this->eq('C1',  $C,  $t(100, false), 'score 100 → Compliant');
        $this->eq('C2',  $MI, $t(99,  false), 'score 99 → Minor_Gaps (100 is exclusive below)');
        $this->eq('C3',  $MI, $t(85,  false), 'score 85 → Minor_Gaps (lower bound inclusive)');
        $this->eq('C4',  $MA, $t(84,  false), 'score 84 → Major_Gaps (boundary -1)');
        $this->eq('C5',  $MA, $t(65,  false), 'score 65 → Major_Gaps (lower bound inclusive)');
        $this->eq('C6',  $AR, $t(64,  false), 'score 64 → At_Risk (boundary -1)');
        $this->eq('C7',  $AR, $t(0,   false), 'score 0 → At_Risk');

        // The safety-critical override: a dispatch-blocking failure outranks score.
        $this->eq('C8',  $AR, $t(100, true),  'blocked overrides a PERFECT score → At_Risk');
        $this->eq('C9',  $AR, $t(85,  true),  'blocked overrides Minor_Gaps → At_Risk');
        $this->eq('C10', $AR, $t(0,   true),  'blocked at score 0 → At_Risk');
    }

    // ── D. waiver bounds (real vehicle, zero writes) ─────────────────────

    private function sectionD_waiverBounds(string $sid): void
    {
        echo "\n[D] Waiver bounds — WAIVER_REASON_MIN=" . Transport_compliance_service::WAIVER_REASON_MIN
            . ", WAIVER_MAX_DAYS=" . Transport_compliance_service::WAIVER_MAX_DAYS . "\n";

        [$veh, $rule] = $this->pickTarget();
        if ($veh === '') { $this->bad('D0', 'waiver bounds', 'no vehicle+rule pair available in this tenant'); return; }
        echo "      target: vehicle={$veh} rule={$rule}\n";

        $good20 = str_repeat('x', 20);                       // exactly at the minimum
        $short  = str_repeat('x', 19);                       // one short
        $d90    = date('Y-m-d', strtotime('+90 days'));      // exactly at the ceiling
        $d91    = date('Y-m-d', strtotime('+91 days'));      // one over
        $past   = date('Y-m-d', strtotime('-1 day'));

        // Reject cases — each throws before any write.
        $this->throws('D1', 'at least', fn() => $this->cmp->waiveRule($veh, $rule,
            ['reason' => $short, 'waiver_expires_on' => $d90]), '19-char reason rejected');
        $this->throws('D2', 'required', fn() => $this->cmp->waiveRule($veh, $rule,
            ['reason' => $good20, 'waiver_expires_on' => '']), 'missing expiry rejected (waivers are never permanent)');
        $this->throws('D3', 'not a valid date', fn() => $this->cmp->waiveRule($veh, $rule,
            ['reason' => $good20, 'waiver_expires_on' => 'not-a-date']), 'unparseable expiry rejected');
        $this->throws('D4', 'past', fn() => $this->cmp->waiveRule($veh, $rule,
            ['reason' => $good20, 'waiver_expires_on' => $past]), 'back-dated expiry rejected');
        $this->throws('D5', 'may not exceed', fn() => $this->cmp->waiveRule($veh, $rule,
            ['reason' => $good20, 'waiver_expires_on' => $d91]), '+91-day expiry rejected');

        // Paired isolation — prove each bound's ACCEPT side without persisting.
        // A 20-char reason paired with a +91d expiry must fail on the DATE,
        // which can only happen if the reason check already passed.
        $this->throws('D6', 'may not exceed', fn() => $this->cmp->waiveRule($veh, $rule,
            ['reason' => $good20, 'waiver_expires_on' => $d91]),
            'exactly 20 chars ACCEPTED (fails on date, not reason)');
        // A 19-char reason paired with a +90d expiry must fail on the REASON,
        // which can only happen if the +90d date already passed.
        $this->throws('D7', 'at least', fn() => $this->cmp->waiveRule($veh, $rule,
            ['reason' => $short, 'waiver_expires_on' => $d90]),
            'exactly +90 days ACCEPTED (fails on reason, not date)');

        // Multi-byte safety: 20 emoji are 20 CHARACTERS but 80 bytes. If the
        // length check used strlen() instead of mb_strlen() this would wrongly
        // pass a 5-character reason. Sending 5 emoji (20 bytes, 5 chars) must
        // still be rejected as too short.
        $this->throws('D8', 'at least', fn() => $this->cmp->waiveRule($veh, $rule,
            ['reason' => str_repeat('🚌', 5), 'waiver_expires_on' => $d90]),
            'reason length is counted in CHARACTERS not bytes (5 emoji = 20 bytes rejected)');

        // Unknown rule code must be rejected regardless of otherwise-valid input.
        $this->throws('D9', 'Unknown compliance rule', fn() => $this->cmp->waiveRule($veh, 'CBSE_NOT_A_REAL_RULE',
            ['reason' => $good20, 'waiver_expires_on' => $d90]), 'unknown rule_code rejected');

        // Cross-tenant / missing vehicle must be rejected.
        $this->throws('D10', 'Vehicle not found', fn() => $this->cmp->waiveRule('VH_DOES_NOT_EXIST_QA', $rule,
            ['reason' => $good20, 'waiver_expires_on' => $d90]), 'nonexistent vehicle rejected');
    }

    // ── E. attestation (real vehicle, zero writes) ───────────────────────

    private function sectionE_attestation(string $sid): void
    {
        echo "\n[E] Attestation — ATTESTABLE_STATUSES = "
            . implode('|', Transport_compliance_service::ATTESTABLE_STATUSES) . "\n";

        [$veh, $rule] = $this->pickTarget();
        if ($veh === '') { $this->bad('E0', 'attestation', 'no vehicle+rule pair available'); return; }

        // Statuses that exist in the model but are NOT attestable.
        $this->throws('E1', 'status must be one of', fn() => $this->cmp->attestRule($veh, $rule,
            ['status' => Transport_compliance_service::STATUS_WAIVED]),
            'Waived rejected via attest (must use waiveRule)');
        $this->throws('E2', 'status must be one of', fn() => $this->cmp->attestRule($veh, $rule,
            ['status' => Transport_compliance_service::STATUS_NOT_ASSESSED]), 'Not_Assessed rejected');
        $this->throws('E3', 'status must be one of', fn() => $this->cmp->attestRule($veh, $rule,
            ['status' => Transport_compliance_service::STATUS_EXPIRING_SOON]),
            'Expiring_Soon rejected (derived, not attestable)');
        $this->throws('E4', 'status must be one of', fn() => $this->cmp->attestRule($veh, $rule,
            ['status' => '']), 'empty status rejected');
        $this->throws('E5', 'status must be one of', fn() => $this->cmp->attestRule($veh, $rule,
            ['status' => 'compliant']), 'lowercase "compliant" rejected (case-sensitive)');

        // Paired isolation for the ACCEPT side: a VALID status plus a bogus
        // evidence document must fail on the DOCUMENT, proving the status passed.
        $this->throws('E6', 'Evidence document not found', fn() => $this->cmp->attestRule($veh, $rule,
            ['status' => Transport_compliance_service::STATUS_COMPLIANT,
             'evidence_document_id' => 'DOC_DOES_NOT_EXIST_QA']),
            'Compliant ACCEPTED (fails on evidence, not status)');
        $this->throws('E7', 'Evidence document not found', fn() => $this->cmp->attestRule($veh, $rule,
            ['status' => Transport_compliance_service::STATUS_NON_COMPLIANT,
             'evidence_document_id' => 'DOC_DOES_NOT_EXIST_QA']),
            'Non_Compliant ACCEPTED (fails on evidence, not status)');

        // A cross-tenant evidence doc is indistinguishable from a missing one
        // by design (getMetadata enforces schoolId) — both must be refused.
        $this->throws('E8', 'Evidence document not found', fn() => $this->cmp->attestRule($veh, $rule,
            ['status' => Transport_compliance_service::STATUS_COMPLIANT,
             'evidence_document_id' => 'SCH_OTHER_TENANT_DOC_QA']),
            'cross-tenant evidence document refused');

        $this->throws('E9', 'Vehicle not found', fn() => $this->cmp->attestRule('VH_DOES_NOT_EXIST_QA', $rule,
            ['status' => Transport_compliance_service::STATUS_COMPLIANT]), 'nonexistent vehicle rejected');
        $this->throws('E10', 'Unknown compliance rule', fn() => $this->cmp->attestRule($veh, 'CBSE_NOT_A_REAL_RULE',
            ['status' => Transport_compliance_service::STATUS_COMPLIANT]), 'unknown rule_code rejected');
    }

    // ── F. evidence discipline (static source scan) ──────────────────────

    private function sectionF_evidenceDiscipline(): void
    {
        echo "\n[F] Evidence: link-not-copy via the Canonical Document Service\n";
        $src = (string) @file_get_contents(APPPATH . 'libraries/Transport_compliance_service.php');

        $this->truthy('F1', strpos($src, '->link(') !== false && strpos($src, '->getMetadata(') !== false,
            'uses the Document Service link()/getMetadata() API');

        $storage = [];
        foreach (['uploadStorageFile', 'deleteStorageFile', 'putStorageFile', 'StorageClient'] as $bad) {
            if (strpos($src, $bad) !== false) { $storage[] = $bad; }
        }
        $this->truthy('F2', $storage === [], 'never touches Storage directly',
            'found: ' . implode(', ', $storage));

        $direct = [];
        foreach (["'documents'", '"documents"', "'documentLinks'", '"documentLinks"'] as $bad) {
            if (strpos($src, $bad) !== false) { $direct[] = trim($bad, '\'"'); }
        }
        $this->truthy('F3', $direct === [], 'never writes the document collections directly',
            'found: ' . implode(', ', $direct));

        $stripped = (string) preg_replace(['~/\*.*?\*/~s', '~//[^
]*~'], '', $src);
        $this->truthy('F4', strpos($stripped, 'has_permission(') === false
                         && strpos($stripped, 'session->userdata') === false,
            'performs NO authorization of its own (spec §3 — authz stays at the §4 controller boundary)');
    }

    // ── G. tenant isolation (static + contract) ──────────────────────────

    private function sectionG_tenantIsolation(): void
    {
        echo "\n[G] Multi-tenant isolation\n";
        $src = (string) @file_get_contents(APPPATH . 'libraries/Transport_compliance_service.php');

        $scoped = preg_match_all('/schoolWhere|querySchool/', $src);
        $raw    = preg_match_all('/->query\(/', $src);
        $this->truthy('G1', $scoped > 0, "reads are school-scoped ({$scoped} scoped calls)");
        $this->eq('G2', 0, $raw, 'zero unscoped ->query() calls');

        // Compliance rows are keyed {vehicleId}_{ruleCode}; the RC-3 convention
        // is a SINGLE schoolId prefix applied by docId(), never hand-doubled.
        $this->truthy('G3', strpos($src, '$vehicleId . \'_\' . $ruleCode') !== false,
            'compliance row id is {vehicleId}_{ruleCode} (no hand-rolled school prefix → no RC-3 double-prefix)');
    }

    // ── H. orphan audit (read-only; reports, never deletes) ──────────────

    /**
     * Compliance rows and posture docs are keyed by vehicleId. When a vehicle
     * is deleted — or a probe tears down a fixture vehicle — its compliance
     * children must go with it, or they accumulate as invisible orphans that
     * still feed the fleet dashboard.
     *
     * This section only REPORTS. Deleting tenant data is an operator decision.
     */
    private function sectionH_orphanAudit(): void
    {
        echo "\n[H] Orphan audit — compliance children vs live vehicles\n";

        $live = [];
        foreach ((array) $this->fs->schoolWhere('vehicles', []) as $row) {
            $d  = is_array($row['data'] ?? null) ? $row['data'] : $row;
            $id = (string) ($d['vehicleId'] ?? $d['vehicle_id'] ?? $row['id'] ?? '');
            if ($id !== '') { $live[$id] = true; }
        }
        echo '      live vehicles: ' . count($live) . "\n";

        $orphanRows = [];
        foreach ((array) $this->fs->schoolWhere(Transport_compliance_service::COL_VEHICLE_COMPLIANCE, []) as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : $row;
            $v = (string) ($d['vehicleId'] ?? '');
            if ($v !== '' && !isset($live[$v])) { $orphanRows[$v] = ($orphanRows[$v] ?? 0) + 1; }
        }
        $orphanPosture = [];
        foreach ((array) $this->fs->schoolWhere(Transport_compliance_service::COL_VEHICLE_COMPLIANCE_POSTURE, []) as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : $row;
            $v = (string) ($d['vehicleId'] ?? '');
            if ($v !== '' && !isset($live[$v])) { $orphanPosture[] = $v; }
        }

        $rowTotal = array_sum($orphanRows);
        $this->truthy('H1', $rowTotal === 0,
            'no orphaned vehicleCompliance rows',
            $rowTotal . ' row(s) across ' . count($orphanRows) . ' dead vehicle(s): ' . implode(', ', array_keys($orphanRows)));
        $this->truthy('H2', $orphanPosture === [],
            'no orphaned vehicleCompliancePosture docs',
            count($orphanPosture) . ' doc(s): ' . implode(', ', $orphanPosture));
    }

    // ── fixture discovery (read-only) ────────────────────────────────────

    /** @return array{0:string,1:string} [vehicleId, ruleCode] — '' if none. */
    private function pickTarget(): array
    {
        static $cached = null;
        if ($cached !== null) { return $cached; }

        $veh = '';
        foreach ((array) $this->fs->schoolWhere('vehicles', []) as $row) {
            $d  = is_array($row['data'] ?? null) ? $row['data'] : $row;
            $id = (string) ($d['vehicleId'] ?? $d['vehicle_id'] ?? $row['id'] ?? '');
            if ($id !== '') { $veh = $id; break; }
        }
        // NOTE the field-name shift: the CBSE_RULE_PACK constant declares
        // 'code', but seedRulePack() PERSISTS it as 'rule_code'. Rules read
        // back from Firestore therefore use 'rule_code' — reading 'code' here
        // silently yields an empty target and every downstream assertion fails
        // with "ruleCode is required." rather than testing what it claims to.
        // Contract test A11 pins this mapping.
        $rules = $this->cmp->listRules();
        $rule  = '';
        foreach ($rules as $r) {
            $c = (string) ($r['rule_code'] ?? '');
            // Avoid the deferred derived rules — they are not attest targets.
            if ($c !== '' && !in_array($c, Transport_compliance_service::DEFERRED_DERIVED_RULES, true)) {
                $rule = $c; break;
            }
        }
        return $cached = [$veh, $rule];
    }
}
