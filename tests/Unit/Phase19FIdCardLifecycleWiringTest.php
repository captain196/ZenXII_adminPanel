<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 19F — the ID card operational lifecycle, as actually wired.
 *
 * 19E built the model but nothing could reach it: there was no endpoint, no
 * UI, and no lifecycle hook. This suite covers the wiring — authorization,
 * the terminal-exit revoke, search, the printed identity hierarchy, and the
 * fail-closed panel contract.
 *
 * Controller and view behaviour is asserted by parsing the real source with
 * comments stripped. That is deliberate, not a shortcut: these paths need a
 * booted CI instance plus a live Firestore, neither of which exists in this
 * suite, and a test that merely asserted "the method exists" would pass while
 * the endpoint was ungated. Every scan below targets a specific security or
 * correctness property.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19FIdCardLifecycleWiringTest extends TestCase
{
    private static function root(): string { return dirname(__DIR__, 2); }

    private static function read(string $rel): string
    {
        $p = self::root() . '/' . $rel;
        return is_readable($p) ? (string) file_get_contents($p) : '';
    }

    /** Strip comments so prose can neither satisfy nor defeat a scan. */
    private static function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else $out .= $t;
        }
        return str_replace("\r\n", "\n", $out);
    }

    private static function methodBody(string $src, string $name): ?string
    {
        $at = strpos($src, 'function ' . $name . '(');
        if ($at === false) return null;
        $open = strpos($src, '{', $at);
        if ($open === false) return null;
        $depth = 0;
        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') { $depth--; if ($depth === 0) return substr($src, $open, $i - $open + 1); }
        }
        return null;
    }

    private static function sisMethod(string $name): string
    {
        $body = self::methodBody(self::codeOnly(self::read('application/controllers/Sis.php')), $name);
        return $body ?? '';
    }

    // ── A. Endpoints exist and are routed ───────────────────────────────

    /** @dataProvider endpoints */
    public function test_endpoint_exists(string $method): void
    {
        $this->assertNotSame('', self::sisMethod($method), "Sis::{$method}() missing");
    }

    /** @dataProvider endpoints */
    public function test_endpoint_is_explicitly_routed(string $method): void
    {
        // This repo routes every SIS endpoint explicitly rather than relying
        // on CI's default controller/method fallback.
        $routes = self::read('application/config/routes.php');
        $this->assertStringContainsString("'sis/{$method}'", $routes);
        $this->assertStringContainsString("'Sis/{$method}'", $routes);
    }

    public static function endpoints(): array
    {
        return [
            ['id_card_register'], ['id_card_issue'],
            ['id_card_revoke'], ['id_card_history'],
        ];
    }

    // ── B. RBAC — the security core of this phase ───────────────────────

    /** @dataProvider mutations */
    public function test_mutations_require_sis_manage(string $method): void
    {
        $this->assertMatchesRegularExpression(
            "/_require_role\(\s*\[\s*\]\s*,\s*'[a-z_]+'\s*,\s*'SIS'\s*,\s*'manage'\s*\)/",
            self::sisMethod($method),
            "{$method}() must gate on the graded SIS:manage capability"
        );
    }

    /**
     * The EMPTY allow-list is the security property, not a style choice.
     *
     * _require_role() falls through to a legacy ROLE-NAME allow-list when the
     * graded check does not grant. Passing a non-empty list there would let
     * someone named e.g. "Principal" mutate credentials without holding
     * SIS:manage at all. `[]` removes that path entirely, making the graded
     * capability the sole authority (the pattern Entitlements.php set).
     */
    /** @dataProvider mutations */
    public function test_mutations_have_no_legacy_role_name_bypass(string $method): void
    {
        $body = self::sisMethod($method);
        $this->assertStringNotContainsString('self::MANAGE_ROLES', $body);
        $this->assertStringNotContainsString('self::VIEW_ROLES', $body);
        $this->assertStringContainsString('_require_role([]', str_replace(' ', '', $body));
    }

    public static function mutations(): array
    {
        return [['id_card_issue'], ['id_card_revoke']];
    }

    public function test_configuration_manage_is_never_used_for_card_lifecycle(): void
    {
        // A student-lifecycle operation must not be gated by a settings module.
        foreach (['id_card_issue', 'id_card_revoke', 'id_card_register', 'id_card_history'] as $m) {
            $this->assertStringNotContainsString('Configuration', self::sisMethod($m),
                "{$m}() must not be gated on Configuration");
        }
    }

    public function test_reads_require_only_sis_view(): void
    {
        // Printing/reading must stay at 'view' — an operator who may print a
        // class set must not thereby be able to revoke a credential.
        foreach (['id_card_register', 'id_card_history'] as $m) {
            $body = self::sisMethod($m);
            $this->assertStringContainsString("'SIS', 'view'", $body);
            $this->assertStringNotContainsString("'SIS', 'manage'", $body);
        }
    }

    public function test_the_print_page_itself_is_unchanged_at_view_level(): void
    {
        $this->assertStringContainsString("'id_card', 'SIS', 'view'", self::sisMethod('id_card'));
    }

    // ── C. Validation and honest failure ────────────────────────────────

    /** @dataProvider mutations */
    public function test_mutations_validate_the_reason_against_the_service_enum(string $method): void
    {
        $body = self::sisMethod($method);
        $this->assertStringContainsString('Id_card_service::validReasons()', $body,
            'reason must be validated against the single source of truth, not a local list');
    }

    /** @dataProvider mutations */
    public function test_mutations_require_a_student_id(string $method): void
    {
        $this->assertStringContainsString('json_error', self::sisMethod($method));
        $this->assertMatchesRegularExpression("/studentId\s*===\s*''/", self::sisMethod($method));
    }

    public function test_issue_refuses_a_non_active_student(): void
    {
        // A card is a credential for a CURRENT student; scan_qr rejects
        // non-Active anyway, so issuing one would be a fake capability.
        $body = self::sisMethod('id_card_issue');
        $this->assertStringContainsString('strcasecmp($status', $body);
        $this->assertStringContainsString("'Active'", $body);
    }

    public function test_issue_surfaces_the_services_actual_refusal(): void
    {
        // The operator must learn WHICH rule stopped them, not just "failed".
        $body = self::sisMethod('id_card_issue');
        foreach (['card_already_issued', 'no_existing_card_to_replace', 'version_claim_exhausted'] as $r) {
            $this->assertStringContainsString($r, $body);
        }
    }

    public function test_revoke_reports_a_noop_truthfully(): void
    {
        // Re-revoking an already-dead card must not claim a revocation just
        // happened — that is a fake success state.
        $body = self::sisMethod('id_card_revoke');
        $this->assertStringContainsString("'noop'", $body);
        $this->assertStringContainsString('already', $body);
    }

    /** @dataProvider allFour */
    public function test_every_endpoint_catches_throwables_and_fails_loudly(string $method): void
    {
        $body = self::sisMethod($method);
        $this->assertStringContainsString('catch (\Throwable', $body, "{$method}() must not leak an exception");
        $this->assertStringContainsString('log_message(\'error\'', $body,
            "{$method}() must log at 'error' — the only level CI3 writes at log_threshold=1");
        $this->assertStringContainsString('json_error', $body, "{$method}() must not fail silently");
    }

    public static function allFour(): array
    {
        return [['id_card_register'], ['id_card_issue'], ['id_card_revoke'], ['id_card_history']];
    }

    // ── D. Terminal-exit revoke (Part 7) ────────────────────────────────

    public function test_tc_issue_revokes_the_active_card(): void
    {
        $this->assertStringContainsString("_revoke_id_card_on_exit(\$userId, 'issue_tc')", self::sisMethod('issue_tc'));
    }

    public function test_withdrawal_revokes_the_active_card(): void
    {
        $this->assertStringContainsString("_revoke_id_card_on_exit(\$userId, 'withdraw_student')",
            self::sisMethod('withdraw_student'));
    }

    /**
     * THE trap Part 7 warns about. `change_status` toggles Active⇄Inactive —
     * a REVERSIBLE administrative action. A revocation cannot be undone (only
     * superseded by a fresh issue), so revoking here would strand the card of
     * anyone temporarily deactivated and then reactivated. Wiring the hook to
     * the status VALUE rather than the OPERATION's intent would have been the
     * bug; scan_qr's Active gate already covers the temporary case.
     */
    public function test_reversible_status_changes_do_not_revoke(): void
    {
        $this->assertStringNotContainsString('_revoke_id_card_on_exit', self::sisMethod('change_status'));
        $this->assertStringNotContainsString('_revoke_id_card_on_exit', self::sisMethod('cancel_tc'));
    }

    public function test_the_exit_hook_is_best_effort_and_cannot_abort_the_exit(): void
    {
        $body = self::sisMethod('_revoke_id_card_on_exit');
        $this->assertNotSame('', $body);
        $this->assertStringContainsString('catch (\Throwable', $body);
        $this->assertStringNotContainsString('json_error', $body,
            'the hook must never abort a TC or withdrawal');
        $this->assertStringContainsString('currentVersion', $body,
            'must no-op when the student holds no card');
    }

    public function test_the_exit_hook_uses_a_valid_service_reason(): void
    {
        $this->assertStringContainsString('Id_card_service::REASON_ADMIN', self::sisMethod('_revoke_id_card_on_exit'));
    }

    // ── E. SRNO discipline (Part 8) ─────────────────────────────────────

    public function test_the_identity_payload_carries_srno_and_admission_no(): void
    {
        $body = self::sisMethod('_idCardIdentity');
        $this->assertStringContainsString("'srNo'", $body);
        $this->assertStringContainsString("'admissionNo'", $body);
    }

    public function test_roll_no_is_never_sent_to_a_card(): void
    {
        // Roll No is session/class-scoped and reassigned on promotion; it must
        // never become card identity.
        $body = self::sisMethod('_idCardIdentity');
        $this->assertStringNotContainsString('rollNo', $body);
        $this->assertStringNotContainsString('Roll No', $body);
    }

    public function test_the_card_page_passes_both_permanent_identifiers_to_the_view(): void
    {
        $body = self::sisMethod('id_card');
        $this->assertStringContainsString("\$s['srNo']", $body);
        $this->assertStringContainsString("\$s['admissionNo']", $body);
    }

    // ── F. Printed card hierarchy — SRNO-5 ──────────────────────────────

    /**
     * IDC-SRNO-1 (2026-09-19) — SUPERSEDES SRNO-5.
     *
     * SRNO-5 ruled "SR No. first, Student ID kept but subordinate", and this
     * test pinned that ORDER. The card no longer prints the internal key at
     * all when an SR No. exists: a school does not call a child STU0014, the
     * QR already encodes the studentId for support, and two ID-looking numbers
     * on one card invites the wrong one being quoted on a form.
     *
     * The assertion is now STRICTER than the one it replaces — an order can be
     * satisfied while still printing the key; absence cannot.
     */
    public function test_srno_is_the_printed_identity_and_student_id_is_not(): void
    {
        $view = self::read('application/views/sis/id_card.php');

        $this->assertStringContainsString('<span class="idc-info-lbl">SR No.</span>', $view,
            'SR No. is the school\'s own register number and must be on the card');

        $this->assertStringNotContainsString('<span class="idc-info-lbl">Student ID</span>', $view,
            'the internal Student ID must not be printed as a labelled row — it is a '
            . 'foreign key, and the QR on the same card already carries it');
    }

    /**
     * A student admitted before SR numbers became mandatory (Phase 9.1) would
     * otherwise carry no human-readable identifier at all. They fall back to the
     * Student ID — labelled "ID", never "SR No.", so nobody transcribes an
     * internal key into a register believing it is the school's own number.
     */
    public function test_a_student_without_an_sr_number_still_gets_an_identifier(): void
    {
        $view = self::read('application/views/sis/id_card.php');

        $this->assertMatchesRegularExpression(
            "~if \\(\\\$srNo === '' && \\\$uid\\)~", $view,
            'the fallback must be conditional on the SR No. being ABSENT, or both '
            . 'numbers print again'
        );
        $this->assertStringContainsString('<span class="idc-info-lbl">ID</span>', $view,
            'the fallback row must be labelled "ID", not "SR No."');

        // The accent treatment stays on SR No.: it is the primary identity.
        $this->assertMatchesRegularExpression(
            '/SR No\.<\/span>\s*\n\s*<span class="idc-info-val" style="color:var\(--idc\);font-weight:700"/',
            $view
        );
    }
    public function test_admission_number_is_printed(): void
    {
        $this->assertStringContainsString('<span class="idc-info-lbl">Adm No.</span>',
            self::read('application/views/sis/id_card.php'));
    }

    public function test_the_credential_still_binds_only_student_id(): void
    {
        // Mutable class/section/roll must never enter the signed credential.
        $view = self::read('application/views/sis/id_card.php');
        $this->assertStringContainsString('qr_token_encode($qrSchoolId, $uid)', $view);
        // Exactly one mint call, and its subject is the internal FK. Asserting
        // on the call itself rather than on the mere presence of $rollNo/$srNo
        // in the file — both legitimately appear as PRINTED fields, so a
        // blanket "not contains" would fail against correct code.
        $this->assertSame(1, substr_count($view, 'qr_token_encode('));
        $this->assertDoesNotMatchRegularExpression(
            '/qr_token_encode\(\s*\$qrSchoolId\s*,\s*\$(srNo|admNo|rollNo|cls|sec)\b/', $view,
            'the credential must bind studentId, never a display or session-scoped field'
        );
    }

    // ── G. Search — SRNO-4 ──────────────────────────────────────────────

    public function test_search_matches_srno_and_admission_no(): void
    {
        $view = self::read('application/views/sis/id_card.php');
        $this->assertStringContainsString('data-srno=', $view);
        $this->assertStringContainsString('data-admno=', $view);
        $this->assertStringContainsString('sr.indexOf(q)', $view);
        $this->assertStringContainsString('adm.indexOf(q)', $view);
    }

    public function test_the_search_placeholder_tells_the_operator_what_works(): void
    {
        $this->assertStringContainsString('Search by name, SR No., Adm No. or Student ID',
            self::read('application/views/sis/id_card.php'));
    }

    public function test_search_is_client_side_over_already_loaded_rows(): void
    {
        // No per-keystroke request — search must not become an N+1.
        $view = self::read('application/views/sis/id_card.php');
        $this->assertStringContainsString("search.addEventListener('input', applyFilters)", $view);
        $this->assertStringNotContainsString("search.addEventListener('input', function", $view);
    }

    // ── H. Panel honesty (the "phantom success" trap) ───────────────────

    public function test_the_panel_checks_both_http_status_and_payload_status(): void
    {
        // fetch() does NOT reject on 403/500. Without BOTH checks a denied
        // action renders as completed.
        $view = self::read('application/views/sis/id_card.php');
        $this->assertStringContainsString('!r.ok', $view);
        $this->assertStringContainsString("j.status !== 'success'", $view);
    }

    public function test_the_panel_sends_the_csrf_token(): void
    {
        // sis/* is NOT in csrf_exclude_uris, so a missing token means a blank
        // 403 with no console error.
        $view = self::read('application/views/sis/id_card.php');
        $this->assertStringContainsString("meta[name=\"csrf-name\"]", $view);
        $this->assertStringContainsString('fd.append(csrfName, csrfToken)', $view);
    }

    public function test_the_card_endpoints_are_not_csrf_excluded(): void
    {
        $cfg = self::read('application/config/config.php');
        $this->assertStringNotContainsString('sis/id_card', $cfg,
            'these endpoints must keep CI global CSRF protection');
    }

    public function test_duplicate_clicks_are_prevented(): void
    {
        // Issue is not idempotent — a second click would claim another card
        // version. This is a correctness guard, not cosmetics.
        $view = self::read('application/views/sis/id_card.php');
        $this->assertStringContainsString("strip.dataset.busy === '1'", $view);
    }

    public function test_the_register_is_fetched_once_not_per_student(): void
    {
        $view = self::read('application/views/sis/id_card.php');
        $this->assertSame(1, substr_count($view, "idcPost('sis/id_card_register'"),
            'one query for the whole school, never one per student');
    }

    public function test_a_failed_register_load_says_so_instead_of_showing_no_card(): void
    {
        // Empty strips would read as "no card issued" — a false state.
        $this->assertStringContainsString('Card status unavailable',
            self::read('application/views/sis/id_card.php'));
    }

    public function test_controls_are_hidden_when_the_operator_cannot_manage(): void
    {
        // Server-side gating is authoritative; this only avoids offering a
        // button that would be refused.
        $view = self::read('application/views/sis/id_card.php');
        $this->assertStringContainsString("has_permission('SIS', 'manage')", $view);
        $this->assertStringContainsString('IDC_CAN_MANAGE', $view);
    }

    // ── I. Scope guards ─────────────────────────────────────────────────

    public function test_no_fee_is_raised_in_this_phase(): void
    {
        foreach (['id_card_issue', 'id_card_revoke', '_revoke_id_card_on_exit'] as $m) {
            $this->assertStringNotContainsString('createModuleFee', self::sisMethod($m),
                'replacement charging is Phase 19I');
        }
    }

    public function test_qr_consumers_still_do_not_enforce_card_version(): void
    {
        // Enforcement is 19G, after reissue. Turning it on now would reject
        // every legitimate holder.
        foreach ([
            'application/controllers/Attendance.php',
            'application/libraries/Transport_qr_attendance_service.php',
            'application/libraries/Visitor_service.php',
        ] as $rel) {
            $this->assertStringNotContainsString('isCurrentVersion', self::read($rel), $rel);
        }
    }

    public function test_no_rtdb_introduced(): void
    {
        foreach (['application/views/sis/id_card.php', 'application/libraries/Id_card_service.php'] as $rel) {
            $src = self::codeOnly(self::read($rel));
            foreach (['getDatabase', 'firebaseio', 'database()->'] as $n) {
                $this->assertStringNotContainsString($n, $src, "{$rel} must stay Firestore-only");
            }
        }
    }
}
