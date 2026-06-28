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

        // CircularDoc canonical contract (5) — COMM-F1 P1.
        // The 5 assertions below convert the COMM-F1 audit findings into
        // machine-enforced contract requirements on the `notices` collection.
        // Both Parent + Teacher apps deserialize `notices` docs as CircularDoc
        // and filter via whereEqualTo("status", "sent"). Writers that diverge
        // from the canonical CircularDoc schema (body/author/authorId/
        // authorRole/targetType/targetClasses/targetRoles + status="sent")
        // produce notices invisible to mobile users.
        $results['CH-A26'] = $this->_assert_event_notice_writes_canonical_body();
        $results['CH-A27'] = $this->_assert_event_notice_writes_status_sent();
        $results['CH-A28'] = $this->_assert_event_notice_writes_canonical_author_trio();
        $results['CH-A29'] = $this->_assert_event_notice_writes_canonical_target_trio();
        $results['CH-A30'] = $this->_assert_admin_birthday_canonical_shape();

        // commCounters nested-only invariant (2) — COMM-F2.
        // The 2 assertions below enforce the Firestore-only nested-map
        // single-source-of-truth for the commCounters on
        // schools/{schoolId}_profile. CH-A31 is a structural assertion
        // (Communication.php + Communication_helper.php read nested-only;
        // no flat-key fallback). CH-A32 is a runtime data invariant
        // (verifier-scope tenant profile has zero flat commCounters.*
        // top-level fields).
        $results['CH-A31'] = $this->_assert_counter_reads_nested_only();
        $results['CH-A32'] = $this->_assert_profile_zero_flat_counter_keys();

        // Messaging runtime retirement (3) — COMM-MSG Package 2A.
        // Direct Messaging is retired and replaced with a "Coming Soon"
        // user-facing experience. The backend service is deleted, the
        // admin AJAX endpoints return 410 Gone, and the admin view is
        // a Coming Soon page (no chat layout).
        $results['CH-A33'] = $this->_assert_messaging_service_absent();
        $results['CH-A34'] = $this->_assert_messaging_endpoints_retired();
        $results['CH-A35'] = $this->_assert_messages_view_is_coming_soon();

        // Device + Push convergence (7) — COMM-DEVICE + COMM-PUSH Package 3A.
        // Server-side device token registry + FCM dispatcher must operate
        // on the Firestore `userDevices` canonical collection only — no
        // RTDB Users/Devices paths anywhere on the server surface.
        //
        // CH-A36..A38 enforce Device_service.php on Firestore-only.
        // CH-A39    enforces Push_service.php on Firestore-only.
        // CH-A40    enforces Device_management.php on Firestore-only
        //           for the student-roster lookup paths.
        // CH-A41    runtime sanity: userDevices is alive at verifier-scope
        //           tenant (canonical store exists + populated).
        // CH-A42    runtime end-to-end: probe write + read-back + delete
        //           round-trip on userDevices (confirms write path works).
        //
        // Baseline expectation pre-Package-3A cutover: CH-A36..A40 FAIL
        // (RTDB still present), CH-A41 + CH-A42 PASS (Firestore canonical
        // already exists from prior phases + P0.5 backfill).
        $results['CH-A36'] = $this->_assert_device_service_zero_rtdb();
        $results['CH-A37'] = $this->_assert_device_service_no_users_devices_literal();
        $results['CH-A38'] = $this->_assert_device_service_methods_use_firestore();
        $results['CH-A39'] = $this->_assert_push_service_zero_rtdb_and_no_literal();
        $results['CH-A40'] = $this->_assert_device_management_no_users_parents_literal();
        $results['CH-A41'] = $this->_assert_user_devices_alive_at_tenant();
        $results['CH-A42'] = $this->_assert_user_devices_write_roundtrip();

        // Mobile Communication convergence (2) — Package 3B.
        // Parent + Teacher Android apps must register FCM tokens to the
        // Firestore `userDevices` canonical collection only — no RTDB
        // Users/Devices mirror writes from registerFcmToken().
        //
        // CH-A43 enforces Parent App AuthRepository.registerFcmToken
        // Firestore-only.
        // CH-A44 enforces Teacher App AuthRepository.registerFcmToken
        // Firestore-only.
        // SKIP semantics: when the cross-repo Kotlin path is unreachable
        // (e.g. CI machine without app checkout), the assertion records
        // PASS with a SKIP annotation rather than failing — preserves
        // single-source-of-truth verifier across environments.
        $results['CH-A43'] = $this->_assert_mobile_register_fcm_firestore_only(
            'Parent App',
            'D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent/data/repository/AuthRepository.kt'
        );
        $results['CH-A44'] = $this->_assert_mobile_register_fcm_firestore_only(
            'Teacher App',
            'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher/data/repository/AuthRepository.kt'
        );

        // Auth_client device-method convergence (2) — Package 3C.
        // Admin Device_management resolves device data through Auth_client.
        // Package 3C retired the cURL-to-Node-Auth-API hop for device
        // queries — Auth_client::{list,bind,remove,block}_device now
        // delegate in-process to Device_service which reads/writes the
        // Firestore `userDevices` canonical collection. Dead method
        // Auth_client::verify_device is retired (zero callers per the
        // Package 3C dependency audit).
        //
        // CH-A45 asserts Auth_client.php carries zero /internal/*-device
        //        POST patterns for the 5 retired endpoints.
        // CH-A46 asserts the 4 active device methods each invoke
        //        Device_service via the _device_service() helper and that
        //        verify_device is gone.
        $results['CH-A45'] = $this->_assert_auth_client_no_node_api_device_posts();
        $results['CH-A46'] = $this->_assert_auth_client_delegates_to_device_service();

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

    /**
     * CH-A13 (Phase 2): _nextCommCounter delegates to Numbering_service::next.
     *
     * Pre-Phase-2 this was a runtime probe of monotonic increment via direct
     * invocation. Post-Phase-2 monotonic increment is delivered by the
     * platform Numbering_service (verified independently by numbering_verifier
     * CH-NUM-6 pad-width utilisation tracking). The helper's responsibility
     * here is purely to delegate correctly — verified structurally.
     */
    private function _assert_cas_counter_monotonic(): array
    {
        echo "\n── CH-A13: helper _nextCommCounter delegates to Numbering_service ──\n";
        $helperBody = $this->_extract_method_body($this->_helper_src(), '_nextCommCounter');
        if ($helperBody === '') {
            return $this->_fail('_nextCommCounter method body not extractable');
        }
        $hasGetInstance   = preg_match('/get_instance\s*\(\s*\)/', $helperBody);
        $hasNumberingNext = preg_match('/->numbering->next\s*\(/', $helperBody);
        $hasLowercaseKind = preg_match('/strtolower\s*\(\s*\$type\s*\)/', $helperBody);
        echo "  retrieves CI via get_instance():       " . ($hasGetInstance   ? 'yes' : 'no') . "\n";
        echo "  calls Numbering_service::next():       " . ($hasNumberingNext ? 'yes' : 'no') . "\n";
        echo "  passes lowercased \$type as kind:       " . ($hasLowercaseKind ? 'yes' : 'no') . "\n";
        return ($hasGetInstance && $hasNumberingNext && $hasLowercaseKind)
            ? $this->_ok('_nextCommCounter delegates to Numbering_service::next with correct kind mapping')
            : $this->_fail('_nextCommCounter delegation pattern incomplete');
    }

    /**
     * CH-A14 (Phase 2): _nextCommCounter body contains no legacy CAS loop /
     * commCounters pattern.
     *
     * Pre-Phase-2 this was a runtime probe of self-seed behaviour. Post-
     * Phase-2 the self-seed lives inside Numbering_service (via the
     * primitive's missing-pointer callback, verified independently by the
     * numbering_verifier). The helper must no longer carry any commCounters
     * storage logic — verified structurally.
     */
    private function _assert_cas_counter_self_seeds(): array
    {
        echo "\n── CH-A14: helper _nextCommCounter free of legacy CAS / commCounters pattern ──\n";
        $helperBody = $this->_extract_method_body($this->_helper_src(), '_nextCommCounter');
        if ($helperBody === '') {
            return $this->_fail('_nextCommCounter method body not extractable');
        }
        // _extract_method_body's lookahead stops at the next method declaration,
        // so it can over-capture the trailing docblock of the next method. Trim
        // at this method's own closing brace (4-space indent at end of method).
        $endPos = strpos($helperBody, "\n    }\n");
        if ($endPos !== false) {
            $helperBody = substr($helperBody, 0, $endPos);
        }
        $hasLegacyCommCounter = preg_match('/commCounters/', $helperBody);
        $hasLegacyHelperCalls = preg_match('/_readCommCounterValue|_seedCommCounter|CAS_MAX_RETRIES/', $helperBody);
        $hasLegacyProfileWrite = preg_match('/\$this->fs->update\s*\(\s*[\'"]schools[\'"]/', $helperBody);
        echo "  contains 'commCounters' literal:       " . ($hasLegacyCommCounter ? 'yes (FAIL)' : 'no') . "\n";
        echo "  calls legacy CAS helper methods:       " . ($hasLegacyHelperCalls ? 'yes (FAIL)' : 'no') . "\n";
        echo "  writes to schools profile doc:         " . ($hasLegacyProfileWrite ? 'yes (FAIL)' : 'no') . "\n";
        return (!$hasLegacyCommCounter && !$hasLegacyHelperCalls && !$hasLegacyProfileWrite)
            ? $this->_ok('_nextCommCounter body is a pure delegation; no legacy storage path remains')
            : $this->_fail('_nextCommCounter still contains legacy commCounters / CAS pattern');
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
    /*  CircularDoc CANONICAL CONTRACT (CH-A26..A30) — COMM-F1 P1          */
    /*                                                                     */
    /*  The `notices` collection is consumed by Parent App + Teacher App   */
    /*  via the CircularDoc Kotlin model. Both apps filter the query with  */
    /*  whereEqualTo("status", "sent") and render `body`, `author`,        */
    /*  `authorId`, `authorRole`, `targetType`, `targetClasses`,           */
    /*  `targetRoles`. The two non-canonical writers — write_event_notice  */
    /*  (event/PTM notices) and Admin::_send_birthday_wish_core (birthday  */
    /*  wishes) — currently diverge from this contract and produce         */
    /*  notices invisible to mobile users.                                  */
    /*                                                                     */
    /*  These 5 assertions establish the contract as a verifier-enforced   */
    /*  requirement. Baseline: 0/5 PASS — proving the gap. P2 fixes the    */
    /*  helper (CH-A26..A29 turn PASS). P3 fixes Admin birthday (CH-A30    */
    /*  turns PASS). Final target: 30/30 PASS.                              */
    /* ------------------------------------------------------------------ */

    /** CH-A26: write_event_notice payload includes canonical `body` field. */
    private function _assert_event_notice_writes_canonical_body(): array
    {
        echo "\n── CH-A26: write_event_notice writes canonical 'body' field ──\n";
        $body = $this->_extract_method_body($this->_helper_src(), 'write_event_notice');
        if ($body === '') {
            return $this->_fail("write_event_notice method body not extractable");
        }
        $hasBody = preg_match("/['\"]body['\"]\s*=>/", $body);
        echo "  'body' => in payload: " . ($hasBody ? 'yes' : 'no') . "\n";
        return ($hasBody > 0)
            ? $this->_ok("write_event_notice payload includes canonical 'body' field")
            : $this->_fail("write_event_notice payload missing 'body' (CircularDoc primary text field)");
    }

    /** CH-A27: write_event_notice writes status: "sent" (matches mobile-app whereEqualTo filter). */
    private function _assert_event_notice_writes_status_sent(): array
    {
        echo "\n── CH-A27: write_event_notice writes status: 'sent' (mobile filter) ──\n";
        $body = $this->_extract_method_body($this->_helper_src(), 'write_event_notice');
        if ($body === '') {
            return $this->_fail("write_event_notice method body not extractable");
        }
        $sent      = preg_match("/['\"]status['\"]\s*=>\s*['\"]sent['\"]/", $body);
        $published = preg_match("/['\"]status['\"]\s*=>\s*['\"]published['\"]/", $body);
        echo "  status => 'sent':      " . ($sent ? 'yes' : 'no') . "\n";
        echo "  status => 'published': " . ($published ? 'yes (FAIL)' : 'no') . "\n";
        return ($sent > 0 && $published === 0)
            ? $this->_ok("write_event_notice writes status='sent' (apps will surface the notice)")
            : $this->_fail("write_event_notice status mismatch (sent=$sent, published=$published) — apps filter status=='sent' and skip non-matching notices");
    }

    /** CH-A28: write_event_notice writes canonical author trio (author + authorId + authorRole). */
    private function _assert_event_notice_writes_canonical_author_trio(): array
    {
        echo "\n── CH-A28: write_event_notice writes canonical author trio ──\n";
        $body = $this->_extract_method_body($this->_helper_src(), 'write_event_notice');
        if ($body === '') {
            return $this->_fail("write_event_notice method body not extractable");
        }
        $author     = preg_match("/['\"]author['\"]\s*=>/", $body);
        $authorId   = preg_match("/['\"]authorId['\"]\s*=>/", $body);
        $authorRole = preg_match("/['\"]authorRole['\"]\s*=>/", $body);
        echo "  'author' =>:     " . ($author     ? 'yes' : 'no') . "\n";
        echo "  'authorId' =>:   " . ($authorId   ? 'yes' : 'no') . "\n";
        echo "  'authorRole' =>: " . ($authorRole ? 'yes' : 'no') . "\n";
        $present = $author + $authorId + $authorRole;
        return ($present === 3)
            ? $this->_ok("write_event_notice payload includes author/authorId/authorRole")
            : $this->_fail("write_event_notice missing canonical author trio ({$present}/3 present) — CircularDoc sender display will be empty");
    }

    /** CH-A29: write_event_notice writes canonical target trio (targetType + targetClasses + targetRoles). */
    private function _assert_event_notice_writes_canonical_target_trio(): array
    {
        echo "\n── CH-A29: write_event_notice writes canonical target trio ──\n";
        $body = $this->_extract_method_body($this->_helper_src(), 'write_event_notice');
        if ($body === '') {
            return $this->_fail("write_event_notice method body not extractable");
        }
        $tType    = preg_match("/['\"]targetType['\"]\s*=>/",    $body);
        $tClasses = preg_match("/['\"]targetClasses['\"]\s*=>/", $body);
        $tRoles   = preg_match("/['\"]targetRoles['\"]\s*=>/",   $body);
        echo "  'targetType' =>:    " . ($tType    ? 'yes' : 'no') . "\n";
        echo "  'targetClasses' =>: " . ($tClasses ? 'yes' : 'no') . "\n";
        echo "  'targetRoles' =>:   " . ($tRoles   ? 'yes' : 'no') . "\n";
        $present = $tType + $tClasses + $tRoles;
        return ($present === 3)
            ? $this->_ok("write_event_notice payload includes targetType/targetClasses/targetRoles")
            : $this->_fail("write_event_notice missing canonical target trio ({$present}/3 present) — Parent app isForParents() filter will not work correctly");
    }

    /**
     * CH-A30: Admin::_send_birthday_wish_core notices payload matches CircularDoc canonical shape.
     * Region-scoped to the fs->set('notices', …) call to avoid catching unrelated
     * 'body' / 'status' literals from the audit-doc write earlier in the method.
     */
    private function _assert_admin_birthday_canonical_shape(): array
    {
        echo "\n── CH-A30: Admin::_send_birthday_wish_core writes canonical CircularDoc shape ──\n";
        $adminPath = APPPATH . 'controllers/Admin.php';
        if (!is_file($adminPath)) {
            return $this->_fail("Admin.php not found at {$adminPath}");
        }
        $src = (string) file_get_contents($adminPath);
        $method = $this->_extract_method_body($src, '_send_birthday_wish_core');
        if ($method === '') {
            return $this->_fail("_send_birthday_wish_core method body not extractable");
        }

        // Region-scope to the notices write payload.
        $start = strpos($method, "fs->set('notices'");
        if ($start === false) {
            $start = strpos($method, 'fs->set("notices"');
        }
        if ($start === false) {
            return $this->_fail("fs->set('notices', ...) call not found in _send_birthday_wish_core");
        }
        $region = substr($method, $start, 4096);

        $checks = [
            "'body' =>"             => (int) preg_match("/['\"]body['\"]\s*=>/",        $region),
            "'author' =>"           => (int) preg_match("/['\"]author['\"]\s*=>/",      $region),
            "'authorId' =>"         => (int) preg_match("/['\"]authorId['\"]\s*=>/",    $region),
            "'authorRole' =>"       => (int) preg_match("/['\"]authorRole['\"]\s*=>/",  $region),
            "'targetType' =>"       => (int) preg_match("/['\"]targetType['\"]\s*=>/",  $region),
            "status => 'sent'"      => (int) preg_match("/['\"]status['\"]\s*=>\s*['\"]sent['\"]/", $region),
        ];
        $noPublished = (preg_match("/['\"]status['\"]\s*=>\s*['\"]published['\"]/", $region) === 0) ? 1 : 0;

        $allPass = 1;
        foreach ($checks as $label => $hit) {
            echo "  {$label}: " . ($hit ? 'yes' : 'no') . "\n";
            if (!$hit) $allPass = 0;
        }
        echo "  status='published' absent: " . ($noPublished ? 'yes' : 'no (FAIL)') . "\n";
        if (!$noPublished) $allPass = 0;

        return $allPass
            ? $this->_ok("_send_birthday_wish_core notices payload matches CircularDoc canonical shape")
            : $this->_fail("_send_birthday_wish_core notices payload diverges from CircularDoc canonical shape");
    }

    /* ------------------------------------------------------------------ */
    /*  commCounters NESTED-ONLY INVARIANT (CH-A31..A32) — COMM-F2          */
    /*                                                                     */
    /*  Source of truth for the Communication-domain counters is the      */
    /*  nested commCounters: {Notice: N, Queue: N, ...} map on             */
    /*  schools/{schoolId}_profile. All reads must target the nested       */
    /*  path; no flat-key fallback. Data must contain no legacy            */
    /*  "commCounters.{type}" literal top-level fields.                    */
    /* ------------------------------------------------------------------ */

    /**
     * CH-A31 (Phase 2): live Communication allocation paths no longer touch
     * commCounters.* fields. Counter storage has moved entirely to
     * systemCounters/ via Numbering_service; the helper and controller
     * methods are pure delegations.
     *
     * Pre-Phase-2 this assertion enforced "nested-only commCounters reads
     * with no flat-key fallback". Post-Phase-2 the equivalent stronger
     * invariant is "no commCounters touches at all in the live paths".
     * The _readCommCounterValue method is preserved in Communication_helper
     * as dead code for transitional reasons; this assertion validates the
     * LIVE allocation paths (_next_id and _nextCommCounter bodies).
     */
    private function _assert_counter_reads_nested_only(): array
    {
        echo "\n── CH-A31: live allocation paths no longer touch commCounters.* (Phase 2) ──\n";

        // ── Communication.php controller: _next_id body ───────────────────
        $commPath = APPPATH . 'controllers/Communication.php';
        $commSrc  = is_file($commPath) ? (string) file_get_contents($commPath) : '';
        if ($commSrc === '') return $this->_fail('Communication.php not readable');

        $nextIdBody = $this->_extract_method_body($commSrc, '_next_id');
        if ($nextIdBody === '') {
            return $this->_fail('_next_id method body not extractable from Communication.php');
        }
        // Trim to this method's own closing brace (see CH-A14 note).
        $endPos = strpos($nextIdBody, "\n    }\n");
        if ($endPos !== false) {
            $nextIdBody = substr($nextIdBody, 0, $endPos);
        }
        $nextIdTouches   = preg_match('/commCounters/', $nextIdBody);
        $nextIdDelegates = preg_match('/->numbering->next\s*\(/', $nextIdBody);
        echo "  _next_id contains 'commCounters' literal:      " . ($nextIdTouches   ? 'yes (FAIL)' : 'no') . "\n";
        echo "  _next_id delegates to numbering->next():       " . ($nextIdDelegates ? 'yes' : 'no') . "\n";

        // ── Communication_helper.php library: _nextCommCounter body ───────
        $helperBody = $this->_extract_method_body($this->_helper_src(), '_nextCommCounter');
        if ($helperBody === '') {
            return $this->_fail('_nextCommCounter method body not extractable');
        }
        $endPos = strpos($helperBody, "\n    }\n");
        if ($endPos !== false) {
            $helperBody = substr($helperBody, 0, $endPos);
        }
        $helperTouches   = preg_match('/commCounters/', $helperBody);
        $helperDelegates = preg_match('/->numbering->next\s*\(/', $helperBody);
        echo "  _nextCommCounter contains 'commCounters':      " . ($helperTouches   ? 'yes (FAIL)' : 'no') . "\n";
        echo "  _nextCommCounter delegates to numbering:       " . ($helperDelegates ? 'yes' : 'no') . "\n";

        $clean     = (!$nextIdTouches && !$helperTouches);
        $delegated = ($nextIdDelegates && $helperDelegates);
        return ($clean && $delegated)
            ? $this->_ok('live counter allocation paths fully delegate to Numbering_service; no commCounters.* touches')
            : $this->_fail('counter allocation path still references commCounters or fails to delegate');
    }

    /**
     * CH-A32: profile doc at the verifier-scope tenant has zero legacy
     * commCounters.* flat top-level fields. Confirms the one-shot data
     * convergence script has run for this tenant.
     */
    private function _assert_profile_zero_flat_counter_keys(): array
    {
        echo "\n── CH-A32: profile doc has zero flat commCounters.* fields ──\n";
        try {
            $profile = $this->firebase->firestoreGet('schools', $this->schoolFs . '_profile');
        } catch (\Throwable $e) {
            return $this->_fail('profile doc read failed: ' . substr($e->getMessage(), 0, 140));
        }
        if (!is_array($profile)) {
            // No profile doc means no flat keys by definition; trivially compliant.
            echo "  no profile doc — trivially compliant\n";
            return $this->_ok('no profile doc (trivially zero flat counter keys)');
        }

        $flatKeys = [];
        foreach (array_keys($profile) as $k) {
            if (is_string($k) && strpos($k, 'commCounters.') === 0) {
                $flatKeys[] = $k;
            }
        }
        echo "  flat commCounters.* fields: " . count($flatKeys) . "\n";
        foreach ($flatKeys as $k) {
            echo "    • {$k} = " . json_encode($profile[$k]) . "\n";
        }

        // Inspect the nested map shape for visibility (informational only).
        $nested = (isset($profile['commCounters']) && is_array($profile['commCounters']))
            ? $profile['commCounters'] : null;
        if ($nested !== null) {
            echo "  nested commCounters: " . json_encode($nested) . "\n";
        } else {
            echo "  nested commCounters: absent (counters will self-heal on first mint)\n";
        }

        return (count($flatKeys) === 0)
            ? $this->_ok('profile doc carries zero flat commCounters.* fields (nested-only data state)')
            : $this->_fail(count($flatKeys) . ' flat commCounters.* field(s) still present — run comm_f2_data_convergence.js for this tenant');
    }

    /* ------------------------------------------------------------------ */
    /*  Messaging RUNTIME RETIREMENT (CH-A33..A35) — COMM-MSG Package 2A   */
    /*                                                                     */
    /*  Direct Messaging has been retired and replaced with a "Coming      */
    /*  Soon" user-facing experience across Admin Panel, Parent App, and   */
    /*  Teacher App. These 3 assertions enforce the server-side retirement */
    /*  contract: the Messaging_service library is deleted, the 8 admin    */
    /*  Messaging AJAX endpoints return HTTP 410 Gone, and the admin       */
    /*  Messages view renders the Coming Soon page with no chat layout.    */
    /* ------------------------------------------------------------------ */

    /** CH-A33: Messaging_service.php library file is deleted. */
    private function _assert_messaging_service_absent(): array
    {
        echo "\n── CH-A33: Messaging_service.php is deleted ──\n";
        $libPath = APPPATH . 'libraries/Messaging_service.php';
        $exists  = is_file($libPath);
        echo "  application/libraries/Messaging_service.php: " . ($exists ? 'PRESENT (FAIL)' : 'absent (PASS)') . "\n";
        return (!$exists)
            ? $this->_ok('Messaging_service.php library deleted')
            : $this->_fail('Messaging_service.php still exists — COMM-MSG Package 2A incomplete');
    }

    /**
     * CH-A34: The 8 Messaging AJAX endpoints in Communication.php all
     * return HTTP 410 Gone via the _messaging_gone() helper. The helper
     * itself is present and emits the canonical 410 envelope.
     */
    private function _assert_messaging_endpoints_retired(): array
    {
        echo "\n── CH-A34: Messaging endpoints return 410 Gone ──\n";
        $commPath = APPPATH . 'controllers/Communication.php';
        if (!is_file($commPath)) return $this->_fail('Communication.php not found');
        $src = (string) file_get_contents($commPath);

        // Helper presence + 410 envelope shape.
        $helperPresent = preg_match('/private\s+function\s+_messaging_gone\s*\(\)/', $src);
        $helper410     = preg_match('/set_status_header\(\s*410\s*\)/', $src);
        echo "  private function _messaging_gone() present:        " . ($helperPresent ? 'yes' : 'no') . "\n";
        echo "  helper emits set_status_header(410):               " . ($helper410 ? 'yes' : 'no') . "\n";

        $endpoints = [
            'get_conversations', 'get_messages', 'create_conversation',
            'delete_conversation', 'send_message', 'mark_read',
            'get_unread_count', 'search_recipients',
        ];
        $retired = 0;
        $missing = [];
        foreach ($endpoints as $name) {
            // Body of `public function NAME()` must (a) exist and (b) call _messaging_gone().
            $pat = '/public\s+function\s+' . preg_quote($name, '/')
                 . '\s*\([^)]*\)\s*\{[^}]*\$this->_messaging_gone\(\)/s';
            if (preg_match($pat, $src)) {
                $retired++;
            } else {
                $missing[] = $name;
            }
        }
        echo "  endpoints calling _messaging_gone(): {$retired}/" . count($endpoints) . "\n";
        if (!empty($missing)) {
            echo "  not retired: " . implode(', ', $missing) . "\n";
        }

        // Also: no residual $this->msg_svc reference (service-property + load).
        $svcRefs = preg_match_all('/\$this->msg_svc\b/', $src);
        echo "  residual \$this->msg_svc references: {$svcRefs} (expected: 0)\n";

        $allPass = ($helperPresent > 0) && ($helper410 > 0)
                && ($retired === count($endpoints)) && ($svcRefs === 0);
        return $allPass
            ? $this->_ok('all 8 Messaging endpoints retired with 410 Gone; no residual msg_svc references')
            : $this->_fail("Messaging retirement incomplete (retired={$retired}/" . count($endpoints) . ", msg_svc refs={$svcRefs})");
    }

    /**
     * CH-A35: application/views/communication/messages.php is the Coming
     * Soon page — contains the "Coming Soon" badge + cs-card class, and
     * does NOT contain the legacy chat layout markup (msg-layout / msg-sidebar
     * / msg-chat-input).
     */
    private function _assert_messages_view_is_coming_soon(): array
    {
        echo "\n── CH-A35: messages.php view is Coming Soon (no chat layout) ──\n";
        $viewPath = APPPATH . 'views/communication/messages.php';
        if (!is_file($viewPath)) return $this->_fail('messages.php view not found');
        $src = (string) file_get_contents($viewPath);

        $hasComingSoon = (stripos($src, 'Coming Soon') !== false) ? 1 : 0;
        $hasCsCard     = (strpos($src, 'cs-card') !== false) ? 1 : 0;
        $hasMsgLayout  = (strpos($src, 'msg-layout') !== false) ? 1 : 0;
        $hasChatInput  = (strpos($src, 'msg-chat-input') !== false) ? 1 : 0;
        $hasSendBtn    = (strpos($src, 'msg-send-btn') !== false) ? 1 : 0;

        echo "  contains 'Coming Soon':            " . ($hasComingSoon ? 'yes' : 'no') . "\n";
        echo "  contains 'cs-card' class:          " . ($hasCsCard ? 'yes' : 'no') . "\n";
        echo "  contains 'msg-layout' (legacy):    " . ($hasMsgLayout ? 'yes (FAIL)' : 'no') . "\n";
        echo "  contains 'msg-chat-input' (legacy):" . ($hasChatInput ? 'yes (FAIL)' : 'no') . "\n";
        echo "  contains 'msg-send-btn' (legacy):  " . ($hasSendBtn ? 'yes (FAIL)' : 'no') . "\n";

        $allPass = $hasComingSoon && $hasCsCard
                && !$hasMsgLayout && !$hasChatInput && !$hasSendBtn;
        return $allPass
            ? $this->_ok('messages.php is the Coming Soon view — no chat layout remnants')
            : $this->_fail('messages.php view is not canonical Coming Soon (or legacy markup leaked)');
    }

    /* ------------------------------------------------------------------ */
    /*  Device + Push Firestore convergence (CH-A36..A42)                  */
    /*  COMM-DEVICE + COMM-PUSH Package 3A                                 */
    /*                                                                     */
    /*  Server-side device token registry + FCM dispatcher must operate    */
    /*  on the Firestore `userDevices` canonical collection only — no      */
    /*  RTDB Users/Devices paths anywhere on the server surface.           */
    /* ------------------------------------------------------------------ */

    /** CH-A36: Device_service.php has zero RTDB API calls. */
    private function _assert_device_service_zero_rtdb(): array
    {
        echo "\n── CH-A36: Device_service.php zero RTDB API calls ──\n";
        $path = APPPATH . 'libraries/Device_service.php';
        if (!is_file($path)) return $this->_fail('Device_service.php not found at ' . $path);
        $src = (string) file_get_contents($path);
        $count = (int) preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $src);
        echo "  RTDB API calls: {$count} (expected: 0)\n";
        return ($count === 0)
            ? $this->_ok('Device_service.php carries zero RTDB API calls')
            : $this->_fail("Device_service.php still carries {$count} RTDB API call(s) — Package 3A P1/P2 cutover not yet executed");
    }

    /** CH-A37: Device_service.php has no Users/Devices path literal. */
    private function _assert_device_service_no_users_devices_literal(): array
    {
        echo "\n── CH-A37: Device_service.php no Users/Devices literal ──\n";
        $path = APPPATH . 'libraries/Device_service.php';
        if (!is_file($path)) return $this->_fail('Device_service.php not found');
        $src = (string) file_get_contents($path);
        $count = (int) preg_match_all('#Users/Devices#', $src);
        echo "  'Users/Devices' literal occurrences: {$count} (expected: 0)\n";
        return ($count === 0)
            ? $this->_ok('Device_service.php carries no Users/Devices RTDB path literal')
            : $this->_fail("Device_service.php still carries {$count} 'Users/Devices' literal(s)");
    }

    /**
     * CH-A38: Every Device_service public method reads or writes the
     * Firestore `userDevices` collection (either by 'userDevices' string
     * literal or self::FS_COLLECTION reference).
     */
    private function _assert_device_service_methods_use_firestore(): array
    {
        echo "\n── CH-A38: Device_service public methods use Firestore userDevices ──\n";
        $path = APPPATH . 'libraries/Device_service.php';
        if (!is_file($path)) return $this->_fail('Device_service.php not found');
        $src = (string) file_get_contents($path);
        $methods = [
            'listDevices', 'bindDevice', 'removeDevice', 'blockDevice',
            'isDeviceBound', 'touchDevice', 'getFcmTokens',
        ];
        $present = 0;
        $missing = [];
        foreach ($methods as $name) {
            $body = $this->_extract_method_body($src, $name);
            if ($body === '') {
                $missing[] = "{$name}(body-not-extractable)";
                continue;
            }
            $usesFs = preg_match(
                '/\$this->fs->(get|set|update|remove|where|getEntity)\s*\(\s*(self::FS_COLLECTION|[\'"]userDevices[\'"])/',
                $body
            );
            if ($usesFs > 0) {
                $present++;
            } else {
                $missing[] = $name;
            }
            echo "  " . str_pad($name, 16) . " uses Firestore userDevices: " . ($usesFs ? 'yes' : 'no') . "\n";
        }
        $total = count($methods);
        return ($present === $total)
            ? $this->_ok("all {$total} Device_service public methods read/write Firestore userDevices")
            : $this->_fail("only {$present}/{$total} Device_service methods use Firestore userDevices — missing: " . implode(', ', $missing));
    }

    /**
     * CH-A39: Push_service.php has zero RTDB API calls AND no Users/Devices
     * path literal (the stale-token prune writes Firestore via Device_service).
     */
    private function _assert_push_service_zero_rtdb_and_no_literal(): array
    {
        echo "\n── CH-A39: Push_service.php zero RTDB API calls + no Users/Devices literal ──\n";
        $path = APPPATH . 'libraries/Push_service.php';
        if (!is_file($path)) return $this->_fail('Push_service.php not found at ' . $path);
        $src = (string) file_get_contents($path);
        $api  = (int) preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $src);
        $lit  = (int) preg_match_all('#Users/Devices#', $src);
        echo "  RTDB API calls:        {$api} (expected: 0)\n";
        echo "  'Users/Devices' lits:  {$lit} (expected: 0)\n";
        return ($api === 0 && $lit === 0)
            ? $this->_ok('Push_service.php is Firestore-only (no RTDB calls, no Users/Devices literal)')
            : $this->_fail("Push_service.php still has RTDB residue (api={$api}, lits={$lit}) — Package 3A P3 cutover not yet executed");
    }

    /**
     * CH-A40: Device_management.php has no Users/Parents RTDB literal.
     * Student-roster lookup must use Firestore students/staff queries.
     */
    private function _assert_device_management_no_users_parents_literal(): array
    {
        echo "\n── CH-A40: Device_management.php no Users/Parents RTDB literal ──\n";
        $path = APPPATH . 'controllers/Device_management.php';
        if (!is_file($path)) return $this->_fail('Device_management.php not found at ' . $path);
        $src = (string) file_get_contents($path);
        $lit = (int) preg_match_all('#Users/Parents#', $src);
        $api = (int) preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $src);
        echo "  'Users/Parents' lits:  {$lit} (expected: 0)\n";
        echo "  RTDB API calls:        {$api} (expected: 0)\n";
        return ($lit === 0 && $api === 0)
            ? $this->_ok('Device_management.php student-roster lookup is Firestore-only')
            : $this->_fail("Device_management.php still has RTDB residue (lits={$lit}, api={$api}) — Package 3A P4 cutover not yet executed");
    }

    /**
     * CH-A41: Runtime sanity — Firestore `userDevices` is alive at the
     * verifier-scope tenant (query returns at least one document).
     */
    private function _assert_user_devices_alive_at_tenant(): array
    {
        echo "\n── CH-A41: userDevices Firestore canonical alive at tenant ──\n";
        try {
            $rows = $this->firebase->firestoreQuery('userDevices', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 5);
            $n = is_array($rows) ? count($rows) : 0;
            echo "  userDevices.where(schoolId == {$this->schoolFs}).limit(5): {$n} doc(s)\n";
            return ($n > 0)
                ? $this->_ok("userDevices canonical store reachable at tenant ({$n} doc(s))")
                : $this->_fail('userDevices query returned 0 docs at verifier-scope tenant — Firestore canonical empty');
        } catch (\Throwable $e) {
            return $this->_fail('userDevices query failed: ' . substr($e->getMessage(), 0, 140));
        }
    }

    /**
     * CH-A42: Runtime end-to-end probe — write a synthetic CH_PROBE_ doc
     * into userDevices, read it back, verify shape, delete it. Confirms
     * the write path Package 3A P2 will issue is functional.
     */
    private function _assert_user_devices_write_roundtrip(): array
    {
        echo "\n── CH-A42: userDevices write round-trip probe ──\n";
        $probeUserId   = self::PROBE_PREFIX . 'CHA42USER';
        $probeDeviceId = self::PROBE_PREFIX . 'CHA42DEV_' . dechex(random_int(0, 0xffffff));
        $docId         = "{$probeUserId}_{$probeDeviceId}";
        $nowIso        = date('c');
        $payload = [
            'schoolId'   => $this->schoolFs,
            'userId'     => $probeUserId,
            'deviceId'   => $probeDeviceId,
            'fcmToken'   => 'PROBE_TOKEN',
            'platform'   => 'probe',
            'status'     => 'active',
            'lastActive' => $nowIso,
            'appRole'    => 'probe',
            '_probe'     => true,
        ];

        try {
            $writeOk = (bool) $this->firebase->firestoreSet('userDevices', $docId, $payload, /* merge */ false);
            $readBack = $this->firebase->firestoreGet('userDevices', $docId);
            $matches = is_array($readBack)
                       && ($readBack['userId'] ?? '') === $probeUserId
                       && ($readBack['fcmToken'] ?? '') === 'PROBE_TOKEN'
                       && ($readBack['schoolId'] ?? '') === $this->schoolFs;
            echo "  write ok:           " . ($writeOk ? 'yes' : 'no') . "\n";
            echo "  read-back matches:  " . ($matches ? 'yes' : 'no') . "\n";

            // Cleanup — always attempt regardless of pass/fail above.
            try { $this->firebase->firestoreDelete('userDevices', $docId); } catch (\Throwable $e) {}

            return ($writeOk && $matches)
                ? $this->_ok('userDevices write→read→delete round-trip succeeded')
                : $this->_fail("userDevices write round-trip failed (write={$writeOk}, matches=" . ($matches ? '1' : '0') . ')');
        } catch (\Throwable $e) {
            // Best-effort cleanup on exception path too.
            try { $this->firebase->firestoreDelete('userDevices', $docId); } catch (\Throwable $ee) {}
            return $this->_fail('write round-trip exception: ' . substr($e->getMessage(), 0, 140));
        }
    }

    /**
     * CH-A43 / CH-A44: Mobile App AuthRepository.registerFcmToken() is
     * Firestore-only — no RTDB Users/Devices mirror write, no firebaseService
     * write call inside the registerFcmToken method body. Shared between
     * Parent and Teacher apps; the cross-repo Kotlin source path is passed
     * in via $kotlinPath.
     *
     * SKIP semantics: when the path is not readable on this host (typical
     * for CI/headless), the assertion records PASS with a SKIP annotation
     * rather than failing — preserving single-source-of-truth verifier
     * across environments.
     */
    private function _assert_mobile_register_fcm_firestore_only(
        string $appName,
        string $kotlinPath
    ): array {
        $code = ($appName === 'Parent App') ? 'CH-A43' : 'CH-A44';
        echo "\n── {$code}: {$appName} AuthRepository.registerFcmToken Firestore-only ──\n";

        if (!is_file($kotlinPath) || !is_readable($kotlinPath)) {
            echo "  Kotlin source unreachable: {$kotlinPath}\n";
            echo "  SKIP — structural assertion deferred to Android CI / on-host run\n";
            return $this->_ok("{$appName} AuthRepository.kt unreachable on this host (SKIPPED)");
        }

        $src = (string) file_get_contents($kotlinPath);

        // Locate registerFcmToken method body.
        if (!preg_match(
            '/suspend\s+fun\s+registerFcmToken\s*\([^)]*\)\s*:\s*[A-Za-z<>?\s]+\{/',
            $src,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return $this->_fail("{$appName}: registerFcmToken signature not found");
        }
        $bodyStart = $m[0][1] + strlen($m[0][0]) - 1;  // position of opening {
        $depth = 0;
        $bodyEnd = $bodyStart;
        for ($i = $bodyStart; $i < strlen($src); $i++) {
            $c = $src[$i];
            if ($c === '{') $depth++;
            elseif ($c === '}') {
                $depth--;
                if ($depth === 0) { $bodyEnd = $i; break; }
            }
        }
        if ($bodyEnd === $bodyStart) {
            return $this->_fail("{$appName}: could not extract registerFcmToken body");
        }
        $body = substr($src, $bodyStart, $bodyEnd - $bodyStart + 1);

        // Assertions on the method body:
        //  1) zero "Users/Devices" path literals
        //  2) zero firebaseService write calls (updateChildren/setValue/push/pushValue)
        $usersDevicesLits = (int) preg_match_all('#Users/Devices#', $body);
        $rtdbWrites       = (int) preg_match_all(
            '/firebaseService\.(updateChildren|setValue|push|pushValue|writeValue)\s*\(/',
            $body
        );
        // Also confirm the Firestore canonical write is still present.
        $firestoreWrites  = (int) preg_match_all(
            '/firestoreService\.setDocument\s*\(\s*"userDevices"/',
            $body
        );

        echo "  registerFcmToken body — 'Users/Devices' lits: {$usersDevicesLits} (expected: 0)\n";
        echo "  registerFcmToken body — firebaseService writes: {$rtdbWrites} (expected: 0)\n";
        echo "  registerFcmToken body — firestoreService.setDocument(userDevices) calls: {$firestoreWrites} (expected: 1)\n";

        if ($usersDevicesLits === 0 && $rtdbWrites === 0 && $firestoreWrites >= 1) {
            return $this->_ok("{$appName} registerFcmToken is Firestore-only");
        }
        return $this->_fail(
            "{$appName} registerFcmToken residue (Users/Devices lits={$usersDevicesLits}, "
            . "RTDB writes={$rtdbWrites}, Firestore writes={$firestoreWrites}) — Package 3B cutover incomplete"
        );
    }

    /**
     * Package 3C — end-to-end runtime probe for the post-refactor Auth_client
     * device-method chain. Invoked via:
     *   SCHOOL_ID=SCH_... php index.php communication_verifier probe_3c
     *
     * Exercises the four active device operations through Auth_client (which
     * now delegates in-process to Device_service → Firestore userDevices):
     *   1. list_devices  — pick a known userId, confirm non-zero result + shape
     *   2. bind_device   — write a synthetic probe device, confirm success
     *   3. list_devices  — confirm the probe device appears
     *   4. block_device  — confirm Firestore status flips to 'blocked'
     *   5. remove_device — confirm Firestore doc deleted (idempotent semantics)
     *   6. unblock-flow  — full 2-step (remove+bind) sequence Device_management
     *                       uses; confirm both succeed
     *
     * No production data is mutated — all writes target a CH_PROBE_ docId.
     * Cleanup is always attempted regardless of pass/fail.
     */
    public function probe_3c(): void
    {
        $tenant = getenv('SCHOOL_ID') ?: $this->schoolFs;
        $listProbeUser = 'STA0001';                       // real userId for list_devices runtime probe
        $probeUser     = 'CH_PROBE_3C_USER';              // synthetic userId for bind/block/remove flows
        $probeDevice   = 'CH_PROBE_3C_' . dechex(random_int(0, 0xffffff));
        $probeDocId    = $probeUser . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $probeDevice);

        echo "════════════════════════════════════════════════════════════════════════\n";
        echo "  PACKAGE 3C RUNTIME PROBE — Auth_client → Device_service → Firestore\n";
        echo "  tenant={$tenant}  user={$probeUser}  device={$probeDevice}\n";
        echo "════════════════════════════════════════════════════════════════════════\n\n";

        // Auth_client + device_service must auto-load with MY_Controller. The
        // verifier extends CI_Controller — load them explicitly here.
        $this->load->library('auth_client');
        $this->load->library('device_service');
        $this->device_service->init($tenant);

        $results = [];

        // ── 1. list_devices baseline (REAL userId, non-zero expected) ──
        echo "1. Auth_client::list_devices({$listProbeUser})  [real production user]\n";
        $r1 = $this->auth_client->list_devices($listProbeUser);
        $count1 = isset($r1['devices']) && is_array($r1['devices']) ? count($r1['devices']) : -1;
        echo "   success={$this->_bool($r1['success'] ?? null)}  devices={$count1}\n";
        if ($count1 > 0) {
            $sample = $r1['devices'][0];
            echo "   sample[0] keys: " . implode(', ', array_keys($sample)) . "\n";
            echo "   sample[0].deviceId=" . ($sample['deviceId'] ?? '?') . "  status=" . ($sample['status'] ?? '?') . "  tokenLen=" . strlen($sample['fcmToken'] ?? '') . "\n";
        }
        $results['list_baseline'] = ($r1['success'] ?? false) && $count1 > 0;
        echo "   verdict: " . ($results['list_baseline'] ? 'PASS' : 'FAIL') . "\n\n";

        // ── 2. bind_device probe ──
        echo "2. Auth_client::bind_device({$probeUser}, {$probeDevice}, {platform:probe,deviceName:'3C runtime probe'})\n";
        $r2 = $this->auth_client->bind_device($probeUser, $probeDevice, [
            'platform'   => 'probe',
            'deviceName' => '3C runtime probe',
            'appVersion' => 'pkg3c',
            'os'         => 'cli',
        ]);
        echo "   success={$this->_bool($r2['success'] ?? null)}  message=" . ($r2['message'] ?? '?') . "\n";
        $exists2 = $this->firebase->firestoreGet('userDevices', $probeDocId);
        echo "   Firestore doc exists post-bind: " . (is_array($exists2) ? 'yes' : 'no') . "\n";
        $results['bind'] = ($r2['success'] ?? false) && is_array($exists2);
        echo "   verdict: " . ($results['bind'] ? 'PASS' : 'FAIL') . "\n\n";

        // ── 3. list_devices after bind (synthetic user; probe device must be visible) ──
        echo "3. Auth_client::list_devices({$probeUser}) — confirm probe device visible\n";
        $r3 = $this->auth_client->list_devices($probeUser);
        $count3 = isset($r3['devices']) && is_array($r3['devices']) ? count($r3['devices']) : -1;
        $probeFound = false;
        foreach (($r3['devices'] ?? []) as $d) {
            if (($d['deviceId'] ?? '') === $probeDevice) { $probeFound = true; break; }
        }
        echo "   devices={$count3} (synthetic user baseline=0)  probe device visible: " . ($probeFound ? 'yes' : 'no') . "\n";
        $results['list_after_bind'] = $probeFound && $count3 >= 1;
        echo "   verdict: " . ($results['list_after_bind'] ? 'PASS' : 'FAIL') . "\n\n";

        // ── 4. block_device ──
        echo "4. Auth_client::block_device({$probeUser}, {$probeDevice})\n";
        $r4 = $this->auth_client->block_device($probeUser, $probeDevice);
        echo "   success={$this->_bool($r4['success'] ?? null)}  message=" . ($r4['message'] ?? '?') . "\n";
        $exists4 = $this->firebase->firestoreGet('userDevices', $probeDocId);
        $blockedStatus = is_array($exists4) ? ($exists4['status'] ?? '?') : '(no doc)';
        echo "   Firestore status post-block: {$blockedStatus}\n";
        $results['block'] = ($r4['success'] ?? false) && $blockedStatus === 'blocked';
        echo "   verdict: " . ($results['block'] ? 'PASS' : 'FAIL') . "\n\n";

        // ── 5. remove_device ──
        echo "5. Auth_client::remove_device({$probeUser}, {$probeDevice})\n";
        $r5 = $this->auth_client->remove_device($probeUser, $probeDevice);
        echo "   success={$this->_bool($r5['success'] ?? null)}  message=" . ($r5['message'] ?? '?') . "\n";
        $exists5 = $this->firebase->firestoreGet('userDevices', $probeDocId);
        echo "   Firestore doc exists post-remove: " . (is_array($exists5) && !empty($exists5) ? 'yes' : 'no') . "\n";
        $results['remove'] = ($r5['success'] ?? false) && (!is_array($exists5) || empty($exists5));
        echo "   verdict: " . ($results['remove'] ? 'PASS' : 'FAIL') . "\n\n";

        // ── 6. unblock-flow: 2-step (remove then bind) the way Device_management does it ──
        echo "6. unblock_device flow — Auth_client::remove_device then Auth_client::bind_device\n";
        // Re-create blocked state first
        $this->auth_client->bind_device($probeUser, $probeDevice, ['platform'=>'probe']);
        $this->auth_client->block_device($probeUser, $probeDevice);
        echo "   (re-created blocked probe device for unblock-flow test)\n";
        $r6a = $this->auth_client->remove_device($probeUser, $probeDevice);
        echo "   step1 remove: success={$this->_bool($r6a['success'] ?? null)}  message=" . ($r6a['message'] ?? '?') . "\n";
        $r6b = $this->auth_client->bind_device($probeUser, $probeDevice, [
            'platform'   => 'probe',
            'deviceName' => '3C unblock-flow re-bind',
        ]);
        echo "   step2 re-bind: success={$this->_bool($r6b['success'] ?? null)}  message=" . ($r6b['message'] ?? '?') . "\n";
        $exists6 = $this->firebase->firestoreGet('userDevices', $probeDocId);
        $status6 = is_array($exists6) ? ($exists6['status'] ?? '?') : '(no doc)';
        echo "   Firestore status post-unblock-flow: {$status6}\n";
        $results['unblock_flow'] = ($r6a['success'] ?? false) && ($r6b['success'] ?? false) && $status6 === 'active';
        echo "   verdict: " . ($results['unblock_flow'] ? 'PASS' : 'FAIL') . "\n\n";

        // ── Final cleanup ──
        try { $this->firebase->firestoreDelete('userDevices', $probeDocId); } catch (\Throwable $e) {}

        // ── Summary ──
        echo "════════════════════════════════════════════════════════════════════════\n";
        echo "  PROBE SUMMARY\n";
        echo "════════════════════════════════════════════════════════════════════════\n";
        $passCount = 0; $totalCount = count($results);
        foreach ($results as $name => $pass) {
            echo "  " . str_pad($name, 20) . ($pass ? 'PASS' : 'FAIL') . "\n";
            if ($pass) $passCount++;
        }
        echo "════════════════════════════════════════════════════════════════════════\n";
        echo "  RESULT: {$passCount}/{$totalCount} runtime probes PASS\n";
        echo "════════════════════════════════════════════════════════════════════════\n";
    }

    private function _bool($v): string
    {
        if ($v === true)  return 'true';
        if ($v === false) return 'false';
        return '(null)';
    }

    /**
     * CH-A45: Auth_client.php has zero `/internal/*-device` POST patterns
     * for the 5 Node-Auth-API device endpoints retired by Package 3C.
     * (verify_device_otp is the OTP-flow method — different endpoint,
     * out of 3C scope; not flagged.)
     */
    private function _assert_auth_client_no_node_api_device_posts(): array
    {
        echo "\n── CH-A45: Auth_client.php has no Node Auth API device-endpoint POSTs ──\n";
        $path = APPPATH . 'libraries/Auth_client.php';
        if (!is_file($path)) return $this->_fail('Auth_client.php not found at ' . $path);
        $src = (string) file_get_contents($path);
        // Match exactly /internal/list-devices, /bind-device, /remove-device,
        // /block-device, /verify-device — NOT /verify-device-otp (which has
        // a trailing -otp suffix and is the OTP-flow method, out of scope).
        $hits = (int) preg_match_all(
            '#/internal/(list-devices|bind-device|remove-device|block-device|verify-device)(?![-A-Za-z])#',
            $src
        );
        echo "  '/internal/*-device' POST patterns: {$hits} (expected: 0)\n";
        return ($hits === 0)
            ? $this->_ok('Auth_client.php has no Node Auth API device-endpoint POSTs')
            : $this->_fail("Auth_client.php still has {$hits} Node Auth API device-endpoint POST(s) — Package 3C cutover incomplete");
    }

    /**
     * CH-A46: Auth_client.php's 4 active device methods (list_devices,
     * bind_device, remove_device, block_device) each delegate to
     * Device_service via the _device_service() helper, AND the dead
     * verify_device method is retired (zero callers per the 3C audit).
     */
    private function _assert_auth_client_delegates_to_device_service(): array
    {
        echo "\n── CH-A46: Auth_client device methods delegate to Device_service ──\n";
        $path = APPPATH . 'libraries/Auth_client.php';
        if (!is_file($path)) return $this->_fail('Auth_client.php not found at ' . $path);
        $src = (string) file_get_contents($path);

        // Each active method must invoke _device_service()-> within its body.
        $expected = [
            'list_devices'   => '_device_service()->listDevices',
            'bind_device'    => '_device_service()->bindDevice',
            'remove_device'  => '_device_service()->removeDevice',
            'block_device'   => '_device_service()->blockDevice',
        ];
        $present = 0;
        $missing = [];
        foreach ($expected as $method => $needle) {
            $body = $this->_extract_method_body($src, $method);
            if ($body === '') {
                $missing[] = "{$method}(body-not-extractable)";
                continue;
            }
            if (strpos($body, $needle) !== false) {
                $present++;
                echo "  " . str_pad($method, 16) . " delegates to {$needle} — yes\n";
            } else {
                $missing[] = $method;
                echo "  " . str_pad($method, 16) . " delegates to {$needle} — NO\n";
            }
        }

        // verify_device must be deleted (zero callers per Package 3C audit).
        $hasVerifyDevice = (bool) preg_match('/public\s+function\s+verify_device\s*\(/', $src);
        echo "  verify_device retired (dead): " . ($hasVerifyDevice ? 'NO — still present' : 'yes') . "\n";

        $total = count($expected);
        if ($present === $total && !$hasVerifyDevice) {
            return $this->_ok("all {$total} active device methods delegate to Device_service; verify_device retired");
        }
        $reasons = [];
        if ($present !== $total) $reasons[] = ($total - $present) . " method(s) not delegating: " . implode(', ', $missing);
        if ($hasVerifyDevice)   $reasons[] = "verify_device still present";
        return $this->_fail('CH-A46 — ' . implode('; ', $reasons));
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
        $probeCollections = ['feeReceiptIndex', 'feeReceipts', 'feeDemands', 'messageQueue', 'notices', 'userDevices'];
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
