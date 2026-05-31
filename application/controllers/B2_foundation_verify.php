<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Wave B2.2-F — Foundation Verifier (Batch 1).
 *
 * CLI-only. Runs the three Batch-1 verifiers:
 *   f1()         — B2_derive table-driven unit assertions
 *   telemetry()  — Security_telemetry::EVENT_TYPES allowlist contains B2_* (F4)
 *   flags()      — b2_migration_flags loads, all OFF, no key collision (F5)
 *   all()        — runs all three; exits 0 if every gate PASSes, 2 otherwise
 *
 * Usage: php index.php b2_foundation_verify all
 */
class B2_foundation_verify extends CI_Controller
{
    private $pass = 0;
    private $fail = 0;
    private $errors = [];

    public function __construct() { parent::__construct(); if (!defined('STDIN')) { exit("CLI only\n"); } }

    public function all()
    {
        $this->f1(false);
        $this->telemetry(false);
        $this->flags(false);
        $this->billing(false);
        $this->rules(false);
        $this->jobsmoke(false);
        $this->billing_harden(false);
        echo "\n=== B2.2-F ===\n";
        echo "PASS: {$this->pass}  FAIL: {$this->fail}\n";
        foreach ($this->errors as $e) echo "  ! $e\n";
        $gate = ($this->fail === 0) ? 'PASS' : 'REVIEW';
        echo "FOUNDATION GATE: $gate\n";
        exit($this->fail === 0 ? 0 : 2);
    }

    // ── F1: B2_derive table-driven assertions ──
    public function f1($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }
        require_once APPPATH . 'libraries/B2_derive.php';
        $now = strtotime('2026-05-30');
        $D = 86400;

        // computeLifecycle cases
        $L = function ($name, $sub, $opts, $expected) use ($now) {
            $r = B2_derive::computeLifecycle($now, $sub, $opts);
            $this->_assert($r['state'] === $expected, "F1/lifecycle:$name expected=$expected got=" . $r['state']);
        };
        $L('active_long',     ['periodEnd' => $now + 365 * $D, 'graceEnd' => $now + 365 * $D + 7 * $D], [], 'active');
        $L('expiring_soon',   ['periodEnd' => $now + 10  * $D, 'graceEnd' => $now + 10  * $D + 7 * $D], [], 'expiring_soon');
        $L('grace',           ['periodEnd' => $now - 1   * $D, 'graceEnd' => $now + 5   * $D],         [], 'grace');
        $L('expired',         ['periodEnd' => $now - 30  * $D, 'graceEnd' => $now - 20  * $D],         [], 'expired');
        $L('trialing',        ['periodEnd' => $now + 90  * $D, 'graceEnd' => $now + 90  * $D + 7 * $D, 'trialEnd' => $now + 14 * $D], [], 'trialing');
        $L('past_due_active', ['periodEnd' => $now + 60  * $D, 'graceEnd' => $now + 60  * $D + 7 * $D], ['billingOverdue' => true], 'past_due');
        $L('suspended_wins',  ['periodEnd' => $now + 60  * $D, 'graceEnd' => $now + 60  * $D + 7 * $D], ['billingSuspended' => true], 'suspended');

        // computeEntitlements cases
        $plan = ['modules' => ['fees' => true, 'attendance' => true, 'gallery' => false]];
        $e1 = B2_derive::computeEntitlements($plan, [], [], $now);
        $this->_assert(!empty($e1['fees']) && !empty($e1['attendance']) && empty($e1['gallery']), "F1/ent:base set wrong: " . json_encode($e1));
        $e2 = B2_derive::computeEntitlements($plan, [['module' => 'gallery', 'kind' => 'priced']], [], $now);
        $this->_assert(!empty($e2['gallery']), "F1/ent:addon_union gallery should be enabled");
        $e3 = B2_derive::computeEntitlements($plan, [], [['module' => 'fees', 'mode' => 'revoke']], $now);
        $this->_assert(empty($e3['fees']), "F1/ent:revoke active should drop fees");
        $e4 = B2_derive::computeEntitlements($plan, [], [['module' => 'fees', 'mode' => 'revoke', 'expiresAt' => $now - 1]], $now);
        $this->_assert(!empty($e4['fees']), "F1/ent:revoke expired must NOT drop fees");
        $e5 = B2_derive::computeEntitlements($plan, [['module' => 'gallery', 'kind' => 'priced', 'effectiveUntil' => $now - 1]], [], $now);
        $this->_assert(empty($e5['gallery']), "F1/ent:expired addOn must NOT enable gallery");

        // computeBillingSummary
        $invs = [
            ['invoiceId' => 'INV_1', 'status' => 'paid',    'amount' => 1000, 'amountPaid' => 1000, 'periodEnd' => $now - 30 * $D],
            ['invoiceId' => 'INV_2', 'status' => 'pending', 'amount' => 1200, 'amountPaid' => 0,    'dueDate'   => $now + 10 * $D],
            ['invoiceId' => 'INV_3', 'status' => 'partial', 'amount' => 500,  'amountPaid' => 200,  'dueDate'   => $now + 5  * $D],
        ];
        $pays = [['date' => $now - 25 * $D, 'amount' => 1000], ['date' => $now - 1 * $D, 'amount' => 200]];
        $bs = B2_derive::computeBillingSummary($invs, $pays, $now);
        $this->_assert($bs['lastInvoiceId'] === 'INV_1', "F1/billing:lastInvoiceId got " . json_encode($bs['lastInvoiceId']));
        $this->_assert(abs($bs['outstandingBalance'] - 1500.0) < 0.01, "F1/billing:outstanding got {$bs['outstandingBalance']}");
        $this->_assert($bs['nextDueDate'] === $now + 5 * $D, "F1/billing:nextDueDate got {$bs['nextDueDate']}");
        $this->_assert($bs['lastPaymentDate'] === $now - 1 * $D, "F1/billing:lastPaymentDate got {$bs['lastPaymentDate']}");
        $this->_assert(abs($bs['lastPaymentAmount'] - 200.0) < 0.01, "F1/billing:lastPaymentAmount got {$bs['lastPaymentAmount']}");

        // computeEffectiveAccess
        $ea1 = B2_derive::computeEffectiveAccess(['adminDisabled' => ['value' => true], 'lifecycle' => ['state' => 'active']]);
        $this->_assert(!$ea1['allowed'] && $ea1['blockedBy'] === 'admin_disabled', "F1/EA:adminDisabled wrong: " . json_encode($ea1));
        $ea2 = B2_derive::computeEffectiveAccess(['adminDisabled' => ['value' => false], 'lifecycle' => ['state' => 'expired']]);
        $this->_assert(!$ea2['allowed'] && $ea2['blockedBy'] === 'lifecycle_expired', "F1/EA:expired wrong: " . json_encode($ea2));
        $ea3 = B2_derive::computeEffectiveAccess(['adminDisabled' => ['value' => false], 'lifecycle' => ['state' => 'grace']]);
        $this->_assert($ea3['allowed'], "F1/EA:grace must allow");
        $ea4 = B2_derive::computeEffectiveAccess(['adminDisabled' => ['value' => false], 'lifecycle' => ['state' => 'past_due']]);
        $this->_assert(!$ea4['allowed'] && $ea4['blockedBy'] === 'lifecycle_past_due', "F1/EA:past_due must block: " . json_encode($ea4));

        // moduleVisible
        $ent = ['fees' => true, 'attendance' => true];
        $this->_assert(B2_derive::moduleVisible('fees', $ent), "F1/mv:entitled fees should be visible");
        $this->_assert(!B2_derive::moduleVisible('gallery', $ent), "F1/mv:not-entitled gallery should be hidden");
        $this->_assert(!B2_derive::moduleVisible('fees', $ent, ['fees' => false]), "F1/mv:globalFlag=false must hide");
        $this->_assert(!B2_derive::moduleVisible('fees', $ent, [], ['fees' => false]), "F1/mv:perSchoolFlag=false must hide");
        $this->_assert(B2_derive::moduleVisible('fees', $ent, ['fees' => true], ['fees' => true]), "F1/mv:both true must show");
        // Flag cannot GRANT an unpaid module
        $this->_assert(!B2_derive::moduleVisible('gallery', $ent, ['gallery' => true], ['gallery' => true]), "F1/mv:flag cannot grant non-entitled module");

        if ($solo) $this->_finish('F1');
    }

    // ── F4: Security_telemetry::EVENT_TYPES contains every B2_* type ──
    public function telemetry($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }
        $this->load->library('security_telemetry');
        $required = [
            // permanent
            'B2_BILLING_CLAIM', 'B2_BILLING_RECLAIM', 'B2_BILLING_WRITE',
            'B2_LIFECYCLE_TRANSITION', 'B2_ENTITLEMENT_RECOMPUTE',
            'B2_RECONCILE', 'B2_RULES_DEPLOY', 'B2_READ_PARITY',
            // scaffolding (retired at B2.8)
            'B2_BRIDGE_COMPENSATE', 'B2_BACKFILL',
            'B2_REGISTRY_WRITE', 'B2_REGISTRY_READ', 'B2_ROLLBACK',
        ];
        $present = (array) Security_telemetry::EVENT_TYPES;
        foreach ($required as $t) {
            $this->_assert(in_array($t, $present, true), "F4/telemetry: missing event type $t in EVENT_TYPES");
        }
        if ($solo) $this->_finish('F4');
    }

    // ── F5: b2_migration_flags load + all OFF + no key collision ──
    public function flags($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $this->config->load('sa_migration_flags', FALSE, TRUE);
        $b2 = $this->config->item('b2_migration_flags');
        $sa = $this->config->item('sa_migration_flags');

        $this->_assert(is_array($b2) && !empty($b2), 'F5/flags: $config[b2_migration_flags] must be a non-empty array');
        if (is_array($b2)) {
            foreach ($b2 as $k => $v) {
                // b2.billing_harden is permitted to be ON post-activation (2026-05-30);
                // require boolean only. All other b2.* flags remain required-OFF in B2.3α.
                if ($k === 'b2.billing_harden') {
                    $this->_assert(is_bool($v), "F5/flags: $k must be boolean (got " . var_export($v, true) . ")");
                    continue;
                }
                $this->_assert($v === false, "F5/flags: $k must be FALSE on declaration (got " . var_export($v, true) . ")");
            }
            $expected = ['b2.billing_harden', 'b2.registry_firestore', 'b2.billing_firestore',
                         'b2.registry_read_firestore', 'b2.trials', 'b2.addons', 'b2.flags_global'];
            foreach ($expected as $k) {
                $this->_assert(array_key_exists($k, $b2), "F5/flags: missing key $k");
            }
        }

        // No key collision with sa_migration_flags (sa.* / onboard.*)
        if (is_array($sa) && is_array($b2)) {
            $overlap = array_intersect_key($b2, $sa);
            $this->_assert(empty($overlap), 'F5/flags: KEY COLLISION with sa_migration_flags: ' . implode(',', array_keys($overlap)));
        }

        if ($solo) $this->_finish('F5');
    }

    private function _assert(bool $cond, string $msg): void
    {
        if ($cond) { $this->pass++; }
        else       { $this->fail++; $this->errors[] = $msg; }
    }

    private function _finish(string $tag): void
    {
        echo "=== B2.2-F $tag verifier ===\n";
        echo "PASS: {$this->pass}  FAIL: {$this->fail}\n";
        foreach ($this->errors as $e) echo "  ! $e\n";
        $gate = ($this->fail === 0) ? 'PASS' : 'REVIEW';
        echo "$tag GATE: $gate\n";
        exit($this->fail === 0 ? 0 : 2);
    }

    // ── F2: Billing_integrity claim/lock probes (throwaway namespace) ──
    public function billing($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }
        $this->load->library('firebase');
        $this->load->library('billing_integrity');

        // Self-cleaning throwaway namespace — never billingClaims.
        $throwaway = '_b2_probe_claims_' . time() . '_' . bin2hex(random_bytes(4));
        $this->billing_integrity->init($this->firebase, ['uid' => 'B2_verifier', 'role' => 'developer'], $throwaway);

        $k1   = 'probe_idem_' . bin2hex(random_bytes(4));
        $s1   = 'PROBE_SCH_' . bin2hex(random_bytes(3));
        $invA = 'INV_A_' . bin2hex(random_bytes(3));
        $created = [];

        try {
            // T1: First beginPayment → claimed
            $r1 = $this->billing_integrity->beginPayment($k1, ['schoolId' => $s1, 'amount' => 1000]);
            $created[] = 'pay_' . $k1;
            $this->_assert(!empty($r1['claimed']), 'F2/T1: first beginPayment should claim, got=' . json_encode($r1));

            // T2: Re-claim while processing-fresh → in_progress
            $r2 = $this->billing_integrity->beginPayment($k1, ['schoolId' => $s1, 'amount' => 1000]);
            $this->_assert(!empty($r2['in_progress']), 'F2/T2: re-claim while fresh-processing must be in_progress, got=' . json_encode($r2));

            // T3: completePayment → true
            $r3 = $this->billing_integrity->completePayment($k1, ['txnId' => 'TXN_PROBE_T3']);
            $this->_assert($r3 === true, 'F2/T3: completePayment should return true');

            // T4: Re-claim after success → dedup with cached result
            $r4 = $this->billing_integrity->beginPayment($k1, ['schoolId' => $s1, 'amount' => 1000]);
            $this->_assert(!empty($r4['dedup'])
                && isset($r4['result']['txnId'])
                && $r4['result']['txnId'] === 'TXN_PROBE_T3',
                'F2/T4: dedup with cached result expected, got=' . json_encode($r4));

            // T5: claimOpenInvoice → locked
            $r5 = $this->billing_integrity->claimOpenInvoice($s1, '2026-06-01', $invA);
            $created[] = 'openInv_' . $s1;
            $this->_assert(!empty($r5['locked']), 'F2/T5: claimOpenInvoice should lock, got=' . json_encode($r5));

            // T6: Second claim for same school → conflict, surfaces existing invoiceId
            $r6 = $this->billing_integrity->claimOpenInvoice($s1, '2026-06-01', 'INV_B_probe');
            $this->_assert(!empty($r6['conflict']) && ($r6['existingInvoiceId'] ?? null) === $invA,
                'F2/T6: second claim must conflict + surface existingInvoiceId=' . $invA . ', got=' . json_encode($r6));

            // T7: releaseOpenInvoice with CORRECT id → true
            $r7 = $this->billing_integrity->releaseOpenInvoice($s1, $invA);
            $this->_assert($r7 === true, 'F2/T7: releaseOpenInvoice should succeed for owner');

            // T8: claimOpenInvoice again → locked (free after release)
            $r8 = $this->billing_integrity->claimOpenInvoice($s1, '2026-06-01', 'INV_C_probe');
            $this->_assert(!empty($r8['locked']), 'F2/T8: claim after release must lock, got=' . json_encode($r8));

            // T9: invoiceDocId determinism
            $d1 = $this->billing_integrity->invoiceDocId('SCH_X', '2026-06-01');
            $d2 = $this->billing_integrity->invoiceDocId('SCH_X', '2026-06-01');
            $this->_assert($d1 === $d2 && strpos($d1, 'INV_SCH_X_') === 0,
                'F2/T9: invoiceDocId must be deterministic; got d1=' . $d1 . ' d2=' . $d2);

        } finally {
            foreach ($created as $docId) {
                try { $this->firebase->firestoreDelete($throwaway, $docId); } catch (\Throwable $e) {}
            }
        }

        if ($solo) $this->_finish('F2');
    }

    // ── B2.3α: billing-hardening Tier A static check ──
    // Confirms every billing-mutation entry point gates through Billing_integrity
    // when b2.billing_harden is ON. Mechanical bypass-grep over Superadmin_plans.php
    // ensures no ungated System/Payments write remains.
    public function billing_harden($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        // 1. Flag must be DECLARED (presence-only post-activation 2026-05-30).
        //    The pre-activation "must be false" assertion is retired now that
        //    Tier-B probes have been authorized; the flag is permitted to be ON.
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $flags = $this->config->item('b2_migration_flags');
        $this->_assert(is_array($flags) && array_key_exists('b2.billing_harden', $flags),
            'B2.3α/flag: b2.billing_harden must be declared in b2_migration_flags');

        // 2. Source-text checks on Superadmin_plans.php
        $path = APPPATH . 'controllers/Superadmin_plans.php';
        $this->_assert(file_exists($path), 'B2.3α/src: Superadmin_plans.php must exist');
        if (!file_exists($path)) { if ($solo) $this->_finish('B2.3α'); return; }
        $src = (string) file_get_contents($path);

        // 3. Helpers present
        $this->_assert((bool) preg_match('/private\s+function\s+_bi_active\s*\(/', $src),
            'B2.3α/helpers: _bi_active() must be defined');
        $this->_assert((bool) preg_match('/private\s+function\s+_bi_idem_key\s*\(/', $src),
            'B2.3α/helpers: _bi_idem_key() must be defined');
        $this->_assert((bool) preg_match('/private\s+function\s+_bi_telem\s*\(/', $src),
            'B2.3α/helpers: _bi_telem() must be defined');

        // 4. Each integrated method calls _bi_active() and the expected Billing_integrity primitive
        //    (we don't try to parse PHP — text-presence + method-scoped existence is sufficient
        //    for the static gate; the live behavior is covered by Tier B post-activation).
        $methods = [
            'generate_invoice' => ['claimOpenInvoice', 'releaseOpenInvoice'],
            'collect_payment'  => ['beginPayment', 'completePayment', 'failPayment', 'releaseOpenInvoice'],
            'add_payment'      => ['beginPayment', 'completePayment', 'failPayment', 'releaseOpenInvoice'],
            'update_payment'   => ['releaseOpenInvoice', 'B2_BILLING_WRITE'],
            'delete_payment'   => ['releaseOpenInvoice', 'B2_BILLING_WRITE'],
        ];
        foreach ($methods as $m => $needles) {
            // Find this method's start
            if (!preg_match('/public\s+function\s+' . $m . '\s*\(/', $src, $sm, PREG_OFFSET_CAPTURE)) {
                $this->_assert(false, "B2.3α/$m: method not found");
                continue;
            }
            $start = (int) $sm[0][1];
            // Find the next (public|private) function or end-of-file
            $rest  = substr($src, $start + strlen($sm[0][0]));
            $end   = strlen($src);
            if (preg_match('/(public|private)\s+function\s+\w+\s*\(/', $rest, $nm, PREG_OFFSET_CAPTURE)) {
                $end = $start + strlen($sm[0][0]) + (int) $nm[0][1];
            }
            $bodyText = substr($src, $start, $end - $start);

            $this->_assert(strpos($bodyText, '_bi_active(') !== false || strpos($bodyText, '$hardenOn') !== false,
                "B2.3α/$m: must check the b2.billing_harden flag (via _bi_active or \$hardenOn)");
            foreach ($needles as $needle) {
                $this->_assert(strpos($bodyText, $needle) !== false,
                    "B2.3α/$m: must call/emit $needle");
            }
        }

        // 5. Bridge tags present (mechanical retirement marker)
        $this->_assert(substr_count($src, 'B2.3α-BRIDGE') >= 10,
            'B2.3α/tags: at least 10 B2.3α-BRIDGE markers must be present (one per integration block)');
        $this->_assert(substr_count($src, '@b23a-BRIDGE') >= 1,
            'B2.3α/tags: at least one @b23a-BRIDGE docblock marker (helpers)');
        $this->_assert(substr_count($src, '@b23a-PERMANENT') >= 2,
            'B2.3α/tags: at least 2 @b23a-PERMANENT docblock markers (helpers)');

        // 6. Mechanical bypass-grep — every System/Payments write inside a public method must
        //    be preceded by either an _bi_active() check OR an allowlisted internal helper
        //    (fetch_payments overdue-on-read, _migrate_payment shape-fix — neither create a
        //    new payment/transaction, so they are excluded from the bypass gate).
        $writeLines = [];
        if (preg_match_all('/^.{0,8}\$this->firebase->(set|update|delete)\(\s*["\']System\/Payments[^\)]*\)/m', $src, $hits, PREG_OFFSET_CAPTURE)) {
            foreach ($hits[0] as $hit) {
                $offset = (int) $hit[1];
                $lineNum = substr_count(substr($src, 0, $offset), "\n") + 1;
                // Identify which method this write is in (look back for nearest `function`).
                $upto = substr($src, 0, $offset);
                if (preg_match_all('/(public|private)\s+function\s+(\w+)\s*\(/', $upto, $fnHits)) {
                    $methodName = end($fnHits[2]);
                } else { $methodName = '?'; }
                $writeLines[] = ['line' => $lineNum, 'method' => $methodName];
            }
        }
        // Allowlist of methods where a write is read-time/idempotent and creates no transaction.
        $allowlist = ['fetch_payments', '_migrate_payment', 'expire_check'];
        foreach ($writeLines as $w) {
            if (in_array($w['method'], $allowlist, true)) continue;
            // Must be inside an integrated method
            $this->_assert(in_array($w['method'], array_keys($methods), true),
                "B2.3α/bypass: System/Payments write at line {$w['line']} is in method {$w['method']} — must be one of: " . implode(',', array_keys($methods)) . ' (or allowlisted)');
        }

        if ($solo) $this->_finish('B2.3α');
    }

    // ── F6: scheduled-job framework scaffold smoke ──
    public function jobsmoke($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        // 1. F6 file present + class/methods defined
        $jobPath = APPPATH . 'controllers/B2_lifecycle_job.php';
        $this->_assert(file_exists($jobPath), 'F6/jobsmoke: B2_lifecycle_job.php must exist');
        if (file_exists($jobPath)) {
            @require_once $jobPath;
            $this->_assert(class_exists('B2_lifecycle_job', false),
                'F6/jobsmoke: B2_lifecycle_job class must be defined');
            $this->_assert(method_exists('B2_lifecycle_job', 'run'),
                'F6/jobsmoke: run() method must exist (permanent lifecycle pass)');
            $this->_assert(method_exists('B2_lifecycle_job', 'reconcile'),
                'F6/jobsmoke: reconcile() method must exist (temporary forward-reconciler)');
            $this->_assert(method_exists('B2_lifecycle_job', 'smoke'),
                'F6/jobsmoke: smoke() method must exist');
        }

        // 2. Confirm the run()/reconcile() gate is OFF (no-op state) so the
        //    framework is INERT in production — matches the user-locked
        //    "no flag activation / no B2.3+ work" constraint.
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $flags = $this->config->item('b2_migration_flags');
        $this->_assert(is_array($flags)
            && array_key_exists('b2.registry_firestore', $flags)
            && $flags['b2.registry_firestore'] === false,
            'F6/jobsmoke: b2.registry_firestore must be OFF (run()/reconcile() must short-circuit)');

        // 3. In-process heartbeat probe — mirrors smoke() behavior so the
        //    primitive is exercised without instantiating a second
        //    CI_Controller. Net-zero: self-cleans the throwaway doc.
        $this->load->library('firebase');
        $col = '_b2_probe_jobsmoke_v_' . time() . '_' . bin2hex(random_bytes(4));
        $doc = 'heartbeat';
        $created = false;
        try {
            $created = (bool) $this->firebase->firestoreCreate($col, $doc, [
                'job'       => 'B2_lifecycle_job_verifier',
                'mode'      => 'smoke',
                'startedAt' => date('c'),
            ]);
        } catch (\Throwable $e) {}
        $this->_assert($created, 'F6/jobsmoke: heartbeat firestoreCreate must succeed (Admin SDK reaches Firestore)');

        if ($created) {
            $read = null;
            try { $read = $this->firebase->firestoreGet($col, $doc); } catch (\Throwable $e) {}
            $this->_assert(is_array($read) && !empty($read),
                'F6/jobsmoke: heartbeat readback (firestoreGet) must return the doc');
            try { $this->firebase->firestoreDelete($col, $doc); } catch (\Throwable $e) {}
        }

        if ($solo) $this->_finish('F6');
    }

    // ── F3: firestore.rules static-text check ──
    public function rules($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }
        $rulesPath = FCPATH . 'firebase-rules/firestore.rules';
        $this->_assert(file_exists($rulesPath), "F3/rules: firestore.rules not found at $rulesPath");
        if (!file_exists($rulesPath)) { if ($solo) $this->_finish('F3'); return; }
        $rules = (string) file_get_contents($rulesPath);

        // 1. schools/{docId} write tightened to isSuperAdmin()
        $this->_assert(
            (bool) preg_match('/match\s*\/schools\/\{docId\}[\s\S]*?allow\s+write:\s*if\s+isSuperAdmin\(\)/', $rules),
            'F3/rules: schools/{docId} write must be tightened to isSuperAdmin()'
        );

        // 2. B2 server-only collections — explicit deny-all
        $denyAlls = ['schoolControl','plans','subscriptions','invoices','payments',
                     'revenueByPeriod','schoolSsa','tenantAudit','schoolCodeIndex',
                     'schoolNameIndex','billingClaims','_b2_pending'];
        foreach ($denyAlls as $c) {
            $pat = '/match\s*\/' . preg_quote($c, '/') . '\/\{docId\}\s*\{\s*allow\s+read,\s*write:\s*if\s+false;\s*\}/';
            $this->_assert((bool) preg_match($pat, $rules), "F3/rules: $c must have explicit deny-all block");
        }

        // 3. tenantPublic — client read + server-only write
        $this->_assert(
            (bool) preg_match('/match\s*\/tenantPublic\/\{docId\}[\s\S]*?allow\s+read:\s*if\s+isAuth\(\)[\s\S]*?allow\s+write:\s*if\s+false/', $rules),
            'F3/rules: tenantPublic must have client-read + server-only write'
        );

        // 4. isSuperAdmin() helper unchanged (sanity)
        $this->_assert((bool) preg_match('/function\s+isSuperAdmin\s*\(\s*\)/', $rules),
            'F3/rules: isSuperAdmin() helper must still exist');

        // 5. Catch-all still deny-all (no accidental opening)
        $this->_assert(
            (bool) preg_match('/match\s*\/\{document=\*\*\}\s*\{\s*allow\s+read,\s*write:\s*if\s+false;\s*\}/', $rules),
            'F3/rules: catch-all match /{document=**} must remain deny-all'
        );

        if ($solo) $this->_finish('F3');
    }
}
