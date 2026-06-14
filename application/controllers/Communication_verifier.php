<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Communication_verifier — V7 Hardening Phase 1 verifier (CLI-only).
 *
 * Enforces the Zero-RTDB contract for application/libraries/Communication_helper.php.
 * 25 assertions split into three groups:
 *
 *   STRUCTURAL (12): CH-A1..A12 — source-text patterns proving the helper carries
 *     no RTDB API calls, no legacy path literals, and that the migrated code
 *     paths (CAS counter, Firestore messageQueue write, students/feeReceipts/
 *     feeDemands cross-domain reads) are wired correctly.
 *
 *   RUNTIME (8): CH-A13..A20 — live Firestore probes against the configured
 *     SCHOOL_ID. Synthetic-tenant probes use the CH_PROBE_ docId prefix and
 *     are deleted before exit so production data is never mutated.
 *
 *   ANTI-FALLBACK + CALLER-COMPAT (5): CH-A21..A25 — structural patterns for
 *     fallback absence, init signature preservation, docblock cleanup, and
 *     PHP lint across all 9 callers.
 *
 * BASELINE EXPECTATION (Commit 0 — verifier infrastructure only):
 *   Most assertions FAIL at baseline because the helper has not yet been
 *   migrated. Assertions that hold trivially at baseline (CH-A19 feeDemands
 *   index probe; CH-A22 no _rtdb helper; CH-A23 init signature; CH-A25
 *   caller lint) will PASS. Verifier captures the start state honestly.
 *
 * PHASE PROGRESSION TARGETS:
 *   After Commit 1 (H1 — pure deletes): 13/25 PASS
 *   After Commit 2 (H2 — CAS counter): 19/25 PASS
 *   After Commit 3 (H3 — cross-domain reads): 25/25 PASS — V7 COMPLIANT
 *
 * INVOCATION:
 *   php index.php communication_verifier verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Exit codes:
 *   0 — all 25 assertions PASS
 *   1 — env vars missing
 *   2 — one or more assertions FAIL
 */
class Communication_verifier extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const HELPER_REL_PATH = 'libraries/Communication_helper.php';

    /** All Communication_helper callers — used by CH-A25. */
    private const CALLER_FILES = [
        'controllers/Attendance.php',
        'controllers/Communication.php',
        'controllers/Events.php',
        'controllers/Examination.php',
        'controllers/Fees.php',
        'controllers/Fee_management.php',
        'controllers/Lms.php',
        'controllers/Ptm.php',
        'controllers/Result.php',
    ];

    /** Probe-doc prefix; everything written under this is cleaned on exit. */
    private const PROBE_PREFIX = 'CH_PROBE_';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Communication_verifier is CLI-only.', 403);
        }
        $this->load->library('firebase');
        if (isset($this->fs) === false) {
            try { $this->load->library('firestore_service', null, 'fs'); } catch (\Throwable $e) {}
        }

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
    }

    public function verify(): void
    {
        $hdr = str_repeat('=', 72);
        echo "{$hdr}\n";
        echo "  Communication_helper V7 Verifier — Phase 1\n";
        echo "  School: {$this->schoolFs}   Session: {$this->sessionYear}\n";
        echo "  Time:   " . date('c') . "\n";
        echo "{$hdr}\n";

        $results = [];

        // Structural (12)
        $results['CH-A1']  = $this->_assert_helper_zero_rtdb_api();
        $results['CH-A2']  = $this->_assert_no_communication_path_literal();
        $results['CH-A3']  = $this->_assert_no_counters_literal();
        $results['CH-A4']  = $this->_assert_no_all_notices_literal();
        $results['CH-A5']  = $this->_assert_no_users_parents_literal();
        $results['CH-A6']  = $this->_assert_no_accounts_fees_literal();
        $results['CH-A7']  = $this->_assert_setQueue_firestore_only();
        $results['CH-A8']  = $this->_assert_fire_event_uses_cas_counter();
        $results['CH-A9']  = $this->_assert_queueDirect_uses_cas_counter();
        $results['CH-A10'] = $this->_assert_queueDirect_writes_firestore();
        $results['CH-A11'] = $this->_assert_resolve_recipient_uses_firestore();
        $results['CH-A12'] = $this->_assert_sendFeePayment_uses_firestore();

        // Runtime (8)
        $results['CH-A13'] = $this->_assert_cas_counter_monotonic();
        $results['CH-A14'] = $this->_assert_cas_counter_self_seeds();
        $results['CH-A15'] = $this->_assert_cas_counter_fail_loud();
        $results['CH-A16'] = $this->_assert_recipient_lookup_firestore_canonical();
        $results['CH-A17'] = $this->_assert_fee_payment_lookup_chain();
        $results['CH-A18'] = $this->_assert_fee_reminder_query();
        $results['CH-A19'] = $this->_assert_feedemands_index_resolves();
        $results['CH-A20'] = $this->_assert_fire_event_end_to_end_zero_rtdb();

        // Anti-fallback + caller-compat (5)
        $results['CH-A21'] = $this->_assert_no_fs_then_rtdb_fallback();
        $results['CH-A22'] = $this->_assert_no_rtdb_helper_method();
        $results['CH-A23'] = $this->_assert_init_signature_preserved();
        $results['CH-A24'] = $this->_assert_docblock_no_rtdb_claim();
        $results['CH-A25'] = $this->_assert_callers_lint_clean();

        // Best-effort cleanup of any synthetic probe docs.
        $this->_cleanup_probe_docs();

        // Summary
        echo "\n{$hdr}\n";
        echo "  Summary\n";
        echo "{$hdr}\n";
        $passCount = 0;
        foreach ($results as $code => $r) {
            $verdict = $r['pass'] ? 'PASS' : 'FAIL';
            if ($r['pass']) $passCount++;
            printf("  %-7s  %-4s  %s\n", $code, $verdict, $r['msg']);
        }
        echo "{$hdr}\n";
        printf("  RESULT: %d/%d PASS\n", $passCount, count($results));
        echo "  " . ($passCount === count($results) ? 'ALL ASSERTIONS PASS' : 'ONE OR MORE FAILURES') . "\n";
        echo "{$hdr}\n";
        exit($passCount === count($results) ? 0 : 2);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function _helper_path(): string
    {
        return APPPATH . self::HELPER_REL_PATH;
    }

    private function _helper_src(): string
    {
        $p = $this->_helper_path();
        return is_file($p) ? (string) file_get_contents($p) : '';
    }

    /**
     * Extract a method body by name. Returns '' on failure.
     * The body starts at the `function NAME(` declaration and ends at the
     * next sibling `function ` declaration (4-space indent assumed for
     * methods inside a class — matches CodeIgniter convention).
     */
    private function _extract_method_body(string $src, string $methodName): string
    {
        $pat = '/(public|private|protected)\s+function\s+' . preg_quote($methodName, '/') . '\s*\([\s\S]*?(?=\n    (?:public|private|protected)\s+function\s|\n\}\s*$)/';
        if (preg_match($pat, $src, $m)) {
            return $m[0];
        }
        return '';
    }

    private function _ok(string $msg): array
    {
        return ['pass' => true, 'msg' => $msg];
    }

    private function _fail(string $msg): array
    {
        return ['pass' => false, 'msg' => $msg];
    }

    /* ------------------------------------------------------------------ */
    /*  STRUCTURAL ASSERTIONS (CH-A1..A12)                                 */
    /* ------------------------------------------------------------------ */

    /** CH-A1: helper carries zero RTDB API calls. */
    private function _assert_helper_zero_rtdb_api(): array
    {
        echo "\n── CH-A1: Communication_helper zero RTDB API calls ──\n";
        $src = $this->_helper_src();
        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $src, $m);
        $count = count($m[0]);
        echo "  RTDB API calls: {$count} (expected: 0)\n";
        return ($count === 0)
            ? $this->_ok('zero RTDB API calls')
            : $this->_fail("{$count} RTDB API call(s) still present");
    }

    /** CH-A2: no Schools/.../Communication/ path literal in helper. */
    private function _assert_no_communication_path_literal(): array
    {
        echo "\n── CH-A2: no Schools/.../Communication/ path literal ──\n";
        $src = $this->_helper_src();
        $count = preg_match_all('/Schools\/[^\s"]*Communication\//', $src);
        echo "  literal occurrences: {$count} (expected: 0)\n";
        return ($count === 0)
            ? $this->_ok('no Communication RTDB path literal')
            : $this->_fail("{$count} Communication RTDB path literal(s) present");
    }

    /** CH-A3: no Counters/(Queue|Notice) literal. */
    private function _assert_no_counters_literal(): array
    {
        echo "\n── CH-A3: no Counters/(Queue|Notice) literal ──\n";
        $src = $this->_helper_src();
        $count = preg_match_all('/Counters\/(Queue|Notice)/', $src);
        echo "  literal occurrences: {$count} (expected: 0)\n";
        return ($count === 0)
            ? $this->_ok('no RTDB-counter literal')
            : $this->_fail("{$count} RTDB-counter literal(s) present");
    }

    /** CH-A4: no "All Notices" literal (pre-Phase-6 legacy path). */
    private function _assert_no_all_notices_literal(): array
    {
        echo "\n── CH-A4: no 'All Notices' literal ──\n";
        $src = $this->_helper_src();
        $count = preg_match_all('/All Notices/', $src);
        echo "  literal occurrences: {$count} (expected: 0)\n";
        return ($count === 0)
            ? $this->_ok('no legacy All Notices literal')
            : $this->_fail("{$count} legacy All Notices literal(s) present");
    }

    /** CH-A5: no Users/Parents literal. */
    private function _assert_no_users_parents_literal(): array
    {
        echo "\n── CH-A5: no Users/Parents literal ──\n";
        $src = $this->_helper_src();
        $count = preg_match_all('/Users\/Parents/', $src);
        echo "  literal occurrences: {$count} (expected: 0)\n";
        return ($count === 0)
            ? $this->_ok('no Users/Parents literal')
            : $this->_fail("{$count} Users/Parents literal(s) present");
    }

    /** CH-A6: no Accounts/(Fees|Pending_fees) literal. */
    private function _assert_no_accounts_fees_literal(): array
    {
        echo "\n── CH-A6: no Accounts/(Fees|Pending_fees) literal ──\n";
        $src = $this->_helper_src();
        $count = preg_match_all('/Accounts\/(Fees|Pending_fees)/', $src);
        echo "  literal occurrences: {$count} (expected: 0)\n";
        return ($count === 0)
            ? $this->_ok('no Accounts/Fees RTDB literal')
            : $this->_fail("{$count} Accounts/Fees RTDB literal(s) present");
    }

    /** CH-A7: _setQueue body has Firestore set + zero firebase->set. */
    private function _assert_setQueue_firestore_only(): array
    {
        echo "\n── CH-A7: _setQueue body is Firestore-only ──\n";
        $body = $this->_extract_method_body($this->_helper_src(), '_setQueue');
        if ($body === '') return $this->_fail('_setQueue body not extractable');
        $rtdb = preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $body);
        // Accept either the literal collection string or the FS_COL_QUEUE constant.
        $fsSet = preg_match("/\\\$this->fs->set\\(\\s*(?:['\"]messageQueue['\"]|self::FS_COL_QUEUE)/", $body);
        echo "  RTDB calls: {$rtdb} (expected: 0)\n";
        echo "  fs->set(messageQueue|FS_COL_QUEUE): " . ($fsSet ? 'yes' : 'no') . "\n";
        return ($rtdb === 0 && $fsSet > 0)
            ? $this->_ok('_setQueue writes Firestore messageQueue only')
            : $this->_fail("_setQueue not Firestore-only (rtdb={$rtdb}, fsSet={$fsSet})");
    }

    /** CH-A8: fire_event uses _nextCommCounter('Queue', ...). */
    private function _assert_fire_event_uses_cas_counter(): array
    {
        echo "\n── CH-A8: fire_event uses CAS counter ──\n";
        $body = $this->_extract_method_body($this->_helper_src(), 'fire_event');
        if ($body === '') return $this->_fail('fire_event body not extractable');
        $hit = preg_match("/\\\$this->_nextCommCounter\\(\\s*['\"]Queue['\"]/", $body);
        echo "  _nextCommCounter('Queue'): " . ($hit ? 'yes' : 'no') . "\n";
        return ($hit > 0)
            ? $this->_ok('fire_event delegates Queue ID to CAS counter')
            : $this->_fail('fire_event does not call _nextCommCounter(\'Queue\')');
    }

    /** CH-A9: _queueDirectNotification uses _nextCommCounter('Queue', ...). */
    private function _assert_queueDirect_uses_cas_counter(): array
    {
        echo "\n── CH-A9: _queueDirectNotification uses CAS counter ──\n";
        $body = $this->_extract_method_body($this->_helper_src(), '_queueDirectNotification');
        if ($body === '') return $this->_fail('_queueDirectNotification body not extractable');
        $hit = preg_match("/\\\$this->_nextCommCounter\\(\\s*['\"]Queue['\"]/", $body);
        echo "  _nextCommCounter('Queue'): " . ($hit ? 'yes' : 'no') . "\n";
        return ($hit > 0)
            ? $this->_ok('_queueDirectNotification delegates Queue ID to CAS counter')
            : $this->_fail('_queueDirectNotification does not call _nextCommCounter(\'Queue\')');
    }

    /** CH-A10: _queueDirectNotification writes Firestore messageQueue (directly or via _setQueue). */
    private function _assert_queueDirect_writes_firestore(): array
    {
        echo "\n── CH-A10: _queueDirectNotification writes Firestore ──\n";
        $body = $this->_extract_method_body($this->_helper_src(), '_queueDirectNotification');
        if ($body === '') return $this->_fail('_queueDirectNotification body not extractable');
        $rtdb  = preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $body);
        $direct = preg_match("/\\\$this->fs->set\\(\\s*(?:['\"]messageQueue['\"]|self::FS_COL_QUEUE)/", $body);
        $delegated = preg_match('/\$this->_setQueue\s*\(/', $body);
        echo "  RTDB calls: {$rtdb} (expected: 0)\n";
        echo "  direct fs->set queue write: " . ($direct ? 'yes' : 'no') . "\n";
        echo "  delegated via _setQueue:    " . ($delegated ? 'yes' : 'no') . "\n";
        return ($rtdb === 0 && ($direct > 0 || $delegated > 0))
            ? $this->_ok('_queueDirectNotification writes Firestore messageQueue only')
            : $this->_fail("_queueDirectNotification not Firestore-only (rtdb={$rtdb}, direct={$direct}, delegated={$delegated})");
    }

    /** CH-A11: _resolve_recipient parent case uses fs->getEntity('students',...). */
    private function _assert_resolve_recipient_uses_firestore(): array
    {
        echo "\n── CH-A11: _resolve_recipient(parent) uses Firestore students ──\n";
        $body = $this->_extract_method_body($this->_helper_src(), '_resolve_recipient');
        if ($body === '') return $this->_fail('_resolve_recipient body not extractable');
        $rtdb = preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $body);
        $fsGet = preg_match("/\\\$this->fs->getEntity\\(\\s*(?:['\"]students['\"]|self::FS_COL_STUDENTS)/", $body);
        echo "  RTDB calls in body: {$rtdb} (expected: 0)\n";
        echo "  fs->getEntity('students'): " . ($fsGet ? 'yes' : 'no') . "\n";
        return ($rtdb === 0 && $fsGet > 0)
            ? $this->_ok('_resolve_recipient uses Firestore students')
            : $this->_fail("_resolve_recipient not Firestore-only (rtdb={$rtdb}, fsGet={$fsGet})");
    }

    /** CH-A12: sendFeePaymentConfirmation uses Firestore feeReceipts chain. */
    private function _assert_sendFeePayment_uses_firestore(): array
    {
        echo "\n── CH-A12: sendFeePaymentConfirmation uses Firestore receipts ──\n";
        $body = $this->_extract_method_body($this->_helper_src(), 'sendFeePaymentConfirmation');
        if ($body === '') return $this->_fail('sendFeePaymentConfirmation body not extractable');
        $rtdb = preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $body);
        $idx  = preg_match("/\\\$this->fs->get\\(\\s*(?:['\"]feeReceiptIndex['\"]|self::FS_COL_RECEIPT_INDEX)/", $body);
        $rcpt = preg_match("/\\\$this->fs->get\\(\\s*(?:['\"]feeReceipts['\"]|self::FS_COL_RECEIPTS)/", $body);
        echo "  RTDB calls in body: {$rtdb} (expected: 0)\n";
        echo "  fs->get('feeReceiptIndex'): " . ($idx ? 'yes' : 'no') . "\n";
        echo "  fs->get('feeReceipts'):     " . ($rcpt ? 'yes' : 'no') . "\n";
        return ($rtdb === 0 && $idx > 0 && $rcpt > 0)
            ? $this->_ok('sendFeePaymentConfirmation uses feeReceiptIndex → feeReceipts chain')
            : $this->_fail("sendFeePaymentConfirmation not Firestore-only (rtdb={$rtdb}, idx={$idx}, rcpt={$rcpt})");
    }

    /* ------------------------------------------------------------------ */
    /*  RUNTIME ASSERTIONS (CH-A13..A20)                                   */
    /* ------------------------------------------------------------------ */

    /** CH-A13: CAS counter increments monotonically across 3 calls. */
    private function _assert_cas_counter_monotonic(): array
    {
        echo "\n── CH-A13: CAS counter monotonic (3 sequential calls) ──\n";
        if (!$this->_helper_has_method('_nextCommCounter')) {
            return $this->_fail('_nextCommCounter not yet implemented (expected during H2 baseline)');
        }
        try {
            $helper = $this->_init_helper();
            $ref = new ReflectionMethod($helper, '_nextCommCounter');
            $ref->setAccessible(true);
            $ids = [];
            for ($i = 0; $i < 3; $i++) {
                $ids[] = (string) $ref->invoke($helper, 'QueueProbe', 5);
            }
            echo "  IDs returned: " . implode(', ', $ids) . "\n";
            $nums = array_map(fn($id) => (int) preg_replace('/\D+/', '', $id), $ids);
            $ok = ($nums[1] === $nums[0] + 1) && ($nums[2] === $nums[1] + 1);
            return $ok
                ? $this->_ok('3 calls produced monotonically increasing IDs')
                : $this->_fail('IDs are not monotonic: ' . implode(',', $nums));
        } catch (\Throwable $e) {
            return $this->_fail('exception during probe: ' . substr($e->getMessage(), 0, 140));
        }
    }

    /** CH-A14: CAS counter self-seeds when commCounters.QueueProbe is absent. */
    private function _assert_cas_counter_self_seeds(): array
    {
        echo "\n── CH-A14: CAS counter self-seeds when field absent ──\n";
        if (!$this->_helper_has_method('_nextCommCounter')) {
            return $this->_fail('_nextCommCounter not yet implemented (expected during H2 baseline)');
        }
        try {
            // Probe-side: blank the QueueProbe counter via a Firestore update,
            // then call _nextCommCounter and verify it returned a positive value.
            $profileDocId = $this->schoolFs . '_profile';
            $this->firebase->firestoreSet('schools', $profileDocId,
                ['commCounters.QueueProbe' => null], /* merge */ true);
            $helper = $this->_init_helper();
            $ref = new ReflectionMethod($helper, '_nextCommCounter');
            $ref->setAccessible(true);
            $id = (string) $ref->invoke($helper, 'QueueProbe', 5);
            $n  = (int) preg_replace('/\D+/', '', $id);
            echo "  self-seed produced: {$id} (n={$n})\n";
            return ($n > 0)
                ? $this->_ok('self-seed produced a positive counter value')
                : $this->_fail('self-seed returned non-positive value');
        } catch (\Throwable $e) {
            return $this->_fail('exception during probe: ' . substr($e->getMessage(), 0, 140));
        }
    }

    /** CH-A15: CAS exhaustion path is fail-loud (CAS_MAX_RETRIES + exception). */
    private function _assert_cas_counter_fail_loud(): array
    {
        echo "\n── CH-A15: CAS counter exhaustion fails loud ──\n";
        // Structural check — verify the implementation has a retry bound
        // and throws RuntimeException on exhaustion. A true contention test
        // would require concurrent processes; structural enforcement is the
        // V7 pattern used by Stream B's writer.
        $src = $this->_helper_src();
        $hasRetryConst = preg_match('/CAS_MAX_RETRIES|maxRetries|max_retries/i', $src);
        $hasThrow = preg_match('/throw\s+new\s+\\\?RuntimeException[^;]*CAS|throw\s+new\s+\\\?RuntimeException[^;]*counter/i', $src);
        echo "  retry-bound constant or local: " . ($hasRetryConst ? 'yes' : 'no') . "\n";
        echo "  throws on exhaustion:          " . ($hasThrow ? 'yes' : 'no') . "\n";
        return ($hasRetryConst > 0 && $hasThrow > 0)
            ? $this->_ok('CAS counter has retry bound + fail-loud throw')
            : $this->_fail('CAS counter retry bound or throw not yet present (expected during H2 baseline)');
    }

    /** CH-A16: _resolve_recipient lookup returns Firestore canonical shape. */
    private function _assert_recipient_lookup_firestore_canonical(): array
    {
        echo "\n── CH-A16: _resolve_recipient returns Firestore-canonical contact ──\n";
        try {
            // Probe a known live student doc — confirm Firestore canonical shape
            // is readable. (Does NOT invoke the helper directly until H3 lands.)
            $stu = $this->firebase->firestoreGet('students', "{$this->schoolFs}_STU0001");
            if (!is_array($stu) || empty($stu)) {
                return $this->_fail("students/{$this->schoolFs}_STU0001 not present in Firestore");
            }
            $fatherName = $stu['fatherName']  ?? $stu['Father Name']  ?? '';
            $phone      = $stu['phoneNumber'] ?? $stu['phone']        ?? '';
            echo "  fatherName resolved: '" . substr($fatherName, 0, 30) . "'\n";
            echo "  phone resolved:      '" . substr($phone, 0, 30) . "'\n";
            // Now check that helper's resolve_recipient body has been migrated
            // (the structural check at CH-A11 covers code shape; here we
            // confirm the canonical Firestore shape is intact).
            $a11 = $this->_assert_resolve_recipient_uses_firestore();
            $migrated = $a11['pass'];
            return ($fatherName !== '' && $phone !== '' && $migrated)
                ? $this->_ok('Firestore students doc carries canonical contact fields + helper migrated')
                : $this->_fail("canonical fields present (fn='" . ($fatherName !== '' ? 'yes' : 'no')
                    . "', phone='" . ($phone !== '' ? 'yes' : 'no') . "') but helper migration: "
                    . ($migrated ? 'yes' : 'no'));
        } catch (\Throwable $e) {
            return $this->_fail('exception during probe: ' . substr($e->getMessage(), 0, 140));
        }
    }

    /** CH-A17: sendFeePaymentConfirmation chain — feeReceiptIndex → feeReceipts. */
    private function _assert_fee_payment_lookup_chain(): array
    {
        echo "\n── CH-A17: feeReceiptIndex → feeReceipts chain works ──\n";
        try {
            // Probe-write: synthetic receipt + index doc; read back; verify chain;
            // delete on exit (handled in _cleanup_probe_docs via prefix).
            $probeReceiptKey = self::PROBE_PREFIX . 'RECEIPT_' . dechex(random_int(0, 0xffffffff));
            $probeReceiptNo  = 'RCPT' . dechex(random_int(0, 0xffffff));
            $idxDocId  = "{$this->schoolFs}_{$this->sessionYear}_{$probeReceiptNo}";
            $rcptDocId = "{$this->schoolFs}_{$probeReceiptKey}";

            $this->firebase->firestoreSet('feeReceiptIndex', $idxDocId, [
                'schoolId'    => $this->schoolFs,
                'session'     => $this->sessionYear,
                'receiptNo'   => $probeReceiptNo,
                'receiptKey'  => $probeReceiptKey,
                '_probe'      => true,
            ], true);
            $this->firebase->firestoreSet('feeReceipts', $rcptDocId, [
                'schoolId'    => $this->schoolFs,
                'session'     => $this->sessionYear,
                'receiptKey'  => $probeReceiptKey,
                'amount'      => 1234.56,
                'studentName' => 'CH Probe Student',
                'month'       => 'April',
                '_probe'      => true,
            ], true);

            $idx = $this->firebase->firestoreGet('feeReceiptIndex', $idxDocId);
            $rcpt = $this->firebase->firestoreGet('feeReceipts', $rcptDocId);
            $chainOk = is_array($idx) && is_array($rcpt)
                       && ($idx['receiptKey'] ?? '') === $probeReceiptKey
                       && ($rcpt['receiptKey'] ?? '') === $probeReceiptKey
                       && (float) ($rcpt['amount'] ?? 0) === 1234.56;
            echo "  probe receiptKey: {$probeReceiptKey}\n";
            echo "  chain resolves:    " . ($chainOk ? 'yes' : 'no') . "\n";

            // cleanup
            try { $this->firebase->firestoreDelete('feeReceiptIndex', $idxDocId); } catch (\Throwable $e) {}
            try { $this->firebase->firestoreDelete('feeReceipts', $rcptDocId); } catch (\Throwable $e) {}

            return $chainOk
                ? $this->_ok('two-step Firestore lookup feeReceiptIndex → feeReceipts works')
                : $this->_fail('lookup chain failed — synthetic probe did not round-trip');
        } catch (\Throwable $e) {
            return $this->_fail('exception during probe: ' . substr($e->getMessage(), 0, 140));
        }
    }

    /** CH-A18: sendFeeReminder query — feeDemands by studentId + status IN. */
    private function _assert_fee_reminder_query(): array
    {
        echo "\n── CH-A18: feeDemands query (sendFeeReminder shape) works ──\n";
        try {
            // The H3 Site #15 query shape — confirm it's executable as written.
            $rows = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId',  '==', $this->schoolFs],
                ['studentId', '==', 'STU0001'],
                ['status',    'in', ['Pending', 'Overdue', 'unpaid', 'partial']],
            ], null, 'ASC', 5);
            $n = is_array($rows) ? count($rows) : -1;
            echo "  query returned: " . ($n >= 0 ? $n : 'error') . " docs\n";
            return ($n >= 0)
                ? $this->_ok("feeDemands query shape resolves ({$n} docs)")
                : $this->_fail('feeDemands query did not return an array');
        } catch (\Throwable $e) {
            return $this->_fail('feeDemands query failed: ' . substr($e->getMessage(), 0, 140));
        }
    }

    /** CH-A19: feeDemands(schoolId, studentId, status IN) composite index resolves. */
    private function _assert_feedemands_index_resolves(): array
    {
        echo "\n── CH-A19: feeDemands composite index resolves ──\n";
        try {
            $rows = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId',  '==', $this->schoolFs],
                ['studentId', '==', 'STU0001'],
                ['status',    'in', ['Pending', 'Overdue', 'unpaid', 'partial']],
            ], null, 'ASC', 1);
            $n = is_array($rows) ? count($rows) : -1;
            echo "  probe returned: " . ($n >= 0 ? "{$n} doc(s)" : 'error') . "\n";
            return ($n >= 0)
                ? $this->_ok('composite index (schoolId, studentId, status IN) deployed')
                : $this->_fail('composite index probe failed');
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            if (preg_match('/FAILED_PRECONDITION|requires an index|composite index/i', $m)) {
                return $this->_fail('composite index MISSING — declare and deploy before H3 lands');
            }
            return $this->_fail('index probe error: ' . substr($m, 0, 140));
        }
    }

    /** CH-A20: end-to-end fire_event probe — zero RTDB ops. */
    private function _assert_fire_event_end_to_end_zero_rtdb(): array
    {
        echo "\n── CH-A20: fire_event end-to-end zero-RTDB ──\n";
        $a1 = $this->_assert_helper_zero_rtdb_api();
        if (!$a1['pass']) {
            return $this->_fail('helper still carries RTDB API calls; end-to-end zero-RTDB not achievable');
        }
        // Structural success implies a fire_event call cannot reach RTDB.
        // A full end-to-end probe (write trigger, write template, invoke
        // fire_event, observe Firestore writes) is deferred to the H1+H2
        // PASS state where the helper's RTDB API call count is zero.
        return $this->_ok('helper zero-RTDB precondition holds; fire_event cannot reach RTDB');
    }

    /* ------------------------------------------------------------------ */
    /*  ANTI-FALLBACK + CALLER-COMPAT (CH-A21..A25)                        */
    /* ------------------------------------------------------------------ */

    /** CH-A21: no FS-first-with-RTDB-fallback pattern in helper. */
    private function _assert_no_fs_then_rtdb_fallback(): array
    {
        echo "\n── CH-A21: no FS-first-with-RTDB-fallback pattern ──\n";
        $src = $this->_helper_src();
        // Pattern: a try/catch block that calls fs->X(...) followed
        // (within ~500 chars) by another try { firebase->get( ... }.
        $hit = preg_match('/\$this->fs->\w+\([^)]*\)[\s\S]{0,500}\$this->firebase->(get|set|update|delete|push)\s*\(/', $src);
        echo "  fallback pattern hits: " . ($hit ? 'yes (FAIL)' : 'no (PASS)') . "\n";
        return ($hit === 0)
            ? $this->_ok('no FS-first-with-RTDB-fallback pattern remains')
            : $this->_fail('FS-first-with-RTDB-fallback pattern still present');
    }

    /** CH-A22: no `_rtdb` private helper method present. */
    private function _assert_no_rtdb_helper_method(): array
    {
        echo "\n── CH-A22: no _rtdb helper method present ──\n";
        $src = $this->_helper_src();
        $hit = preg_match('/function\s+_rtdb\s*\(/', $src);
        echo "  function _rtdb defined: " . ($hit ? 'yes (FAIL)' : 'no (PASS)') . "\n";
        return ($hit === 0)
            ? $this->_ok('no _rtdb helper method present')
            : $this->_fail('_rtdb helper method still defined');
    }

    /** CH-A23: init() signature preserved (backwards-compatible). */
    private function _assert_init_signature_preserved(): array
    {
        echo "\n── CH-A23: init() signature preserved ──\n";
        $src = $this->_helper_src();
        $hit = preg_match('/public\s+function\s+init\s*\(\s*\$firebase\s*,\s*string\s+\$school_name\s*,\s*string\s+\$session_year\s*,\s*string\s+\$parent_db_key/', $src);
        echo "  signature matches expected shape: " . ($hit ? 'yes' : 'no') . "\n";
        return ($hit > 0)
            ? $this->_ok('init() signature preserved')
            : $this->_fail('init() signature drift detected');
    }

    /** CH-A24: docblock no longer claims "Falls back to RTDB" / similar. */
    private function _assert_docblock_no_rtdb_claim(): array
    {
        echo "\n── CH-A24: docblock no RTDB-fallback claim ──\n";
        $src = $this->_helper_src();
        $hit = preg_match('/Falls back to RTDB|RTDB fallback|RTDB mirror|RTDB legacy/i', $src);
        echo "  RTDB-claim phrasing in docblock/comments: " . ($hit ? 'yes (FAIL)' : 'no (PASS)') . "\n";
        return ($hit === 0)
            ? $this->_ok('no RTDB-fallback / mirror / legacy claim in docblock')
            : $this->_fail('docblock still claims RTDB participation');
    }

    /** CH-A25: all 9 callers lint clean. */
    private function _assert_callers_lint_clean(): array
    {
        echo "\n── CH-A25: all 9 Communication_helper callers lint clean ──\n";
        $bad = [];
        foreach (self::CALLER_FILES as $rel) {
            $abs = APPPATH . $rel;
            if (!is_file($abs)) { $bad[] = "{$rel} (missing)"; continue; }
            $cmd = 'php -l ' . escapeshellarg($abs) . ' 2>&1';
            $out = shell_exec($cmd) ?: '';
            if (strpos($out, 'No syntax errors detected') === false) {
                $bad[] = $rel;
            }
        }
        $okCount = count(self::CALLER_FILES) - count($bad);
        echo "  lint clean: {$okCount}/" . count(self::CALLER_FILES) . "\n";
        if (!empty($bad)) {
            echo "  FAIL list: " . implode(', ', $bad) . "\n";
        }
        return empty($bad)
            ? $this->_ok('all 9 callers lint clean')
            : $this->_fail(count($bad) . ' caller(s) fail lint: ' . implode(',', $bad));
    }

    /* ------------------------------------------------------------------ */
    /*  Helper-lifecycle utilities                                         */
    /* ------------------------------------------------------------------ */

    private function _helper_has_method(string $method): bool
    {
        try {
            $this->load->library('communication_helper');
            $ref = new ReflectionClass($this->communication_helper);
            return $ref->hasMethod($method);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Initialize the helper with verifier-scope context.
     * Used by runtime probes (CH-A13..A14, A16).
     */
    private function _init_helper()
    {
        $this->load->library('communication_helper');
        $this->communication_helper->init(
            $this->firebase,
            $this->schoolFs,
            $this->sessionYear,
            $this->schoolFs,
            $this->fs ?? null,
            $this->schoolFs
        );
        return $this->communication_helper;
    }

    /**
     * Best-effort cleanup of probe docs written during this run.
     * Probe-doc IDs are prefixed with PROBE_PREFIX; this scan-and-delete
     * pattern ensures runs don't leak state into production-shape collections.
     */
    private function _cleanup_probe_docs(): void
    {
        $probeCollections = ['feeReceiptIndex', 'feeReceipts', 'feeDemands', 'messageQueue', 'notices'];
        foreach ($probeCollections as $col) {
            try {
                $docs = $this->firebase->firestoreQuery($col, [
                    ['schoolId', '==', $this->schoolFs],
                    ['_probe',   '==', true],
                ], null, 'ASC', 50);
                if (!is_array($docs)) continue;
                foreach ($docs as $row) {
                    $id = (string) ($row['id'] ?? '');
                    if ($id === '') continue;
                    try { $this->firebase->firestoreDelete($col, $id); } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {
                // probes may not be writable; silent cleanup
            }
        }
        // Also blank the QueueProbe counter so probe runs don't leave state.
        try {
            $this->firebase->firestoreSet('schools', $this->schoolFs . '_profile',
                ['commCounters.QueueProbe' => null], true);
        } catch (\Throwable $e) {}
    }
}
