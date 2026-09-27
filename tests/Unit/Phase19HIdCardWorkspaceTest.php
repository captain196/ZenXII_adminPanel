<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';

/**
 * Phase 19H — ID card professional workspace (ID-UI-1 + ID-UI-2).
 *
 * SCOPE NOTE, stated plainly. The operator note is new BACKEND behaviour and
 * is tested behaviourally against the real service with in-memory Firestore
 * doubles. The modal, checkbox and double-click guards are BROWSER JS, and
 * this repository has no DOM test runner for admin views (`firebase-rules/
 * tests` runs Jest against the Firestore emulator only). Adding one is a new
 * toolchain dependency, not a 19H deliverable — so those contracts are pinned
 * by source-scan here and carried into the UAT matrix, which is where a
 * double-click on a real browser is actually proven. Each scan targets a
 * specific correctness property, never mere existence.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19HIdCardWorkspaceTest extends TestCase
{
    private const SCH   = 'SCH_TEST01';
    private const STU_A = 'STU0012';  private const SR_A = '47';

    private $fb;
    private $fs;
    private \Id_card_service $svc;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/application/helpers/qr_token_helper.php';
        if (!class_exists('Id_card_service')) {
            require_once dirname(__DIR__, 2) . '/application/libraries/Id_card_service.php';
        }
    }

    protected function setUp(): void
    {
        // Reuses the doubles defined by the 19E suite — one set of fakes for
        // the whole ID-card programme rather than a divergent copy.
        $this->fb  = new FakeIdCardFirebase();
        $this->fs  = new FakeIdCardFs(self::SCH, $this->fb);
        $this->svc = (new \Id_card_service())->init($this->fb, $this->fs, self::SCH, 'ADM001');
    }

    private static function view(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/application/views/sis/id_card.php');
    }

    /**
     * The view with comments removed.
     *
     * Needed for any "must NOT contain X" scan, because the comments in this
     * file legitimately DISCUSS the things being banned — e.g. the note
     * explaining that `alert()` was replaced, and the one explaining why
     * `$('#m').modal('show')` cannot be used here. Scanning raw text makes the
     * documentation fail the test that documents it. `token_get_all()` alone
     * is not enough: the JavaScript lives inside T_INLINE_HTML, so JS comments
     * survive it and must be stripped textually.
     */
    private static function viewCode(): string
    {
        $v = self::view();
        $v = preg_replace('#/\*.*?\*/#s', ' ', $v);        // block comments (PHP + JS)
        $v = preg_replace('#^\s*//.*$#m', ' ', $v);        // whole-line // comments
        return (string) $v;
    }

    private function issueFirst(): array
    {
        return $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, ['srNo' => self::SR_A]);
    }

    // ══ BEHAVIOURAL — operator note ═════════════════════════════════════

    public function test_a_note_is_stored_alongside_the_reason_not_instead_of_it(): void
    {
        $this->issueFirst();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_STOLEN, ['srNo' => self::SR_A], 'FIR 123/2026');
        $v = $this->fs->get('idCards', $this->svc->versionDocId(self::STU_A, 2));
        $this->assertSame('stolen', $v['reason'], 'the canonical reason must stay the enum');
        $this->assertSame('FIR 123/2026', $v['reasonNote']);
    }

    public function test_a_revoke_note_is_stored_separately(): void
    {
        $this->issueFirst();
        $this->svc->revoke(self::STU_A, \Id_card_service::REASON_DAMAGED, 'surrendered at front desk');
        $v = $this->fs->get('idCards', $this->svc->versionDocId(self::STU_A, 1));
        $this->assertSame('damaged', $v['revokedReason']);
        $this->assertSame('surrendered at front desk', $v['revokedNote']);
    }

    public function test_a_note_can_never_become_the_reason(): void
    {
        // Typing a bogus "reason" into the note must not affect the enum that
        // drives audit, reporting and (19I) fee eligibility.
        $this->issueFirst();
        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_WEAR, ['srNo' => self::SR_A], 'lost');
        $this->assertTrue($r['ok']);
        $this->assertSame('wear', $this->fs->get('idCards', $this->svc->versionDocId(self::STU_A, 2))['reason']);
    }

    public function test_an_invalid_reason_is_still_refused_regardless_of_the_note(): void
    {
        $this->issueFirst();
        $r = $this->svc->issue(self::STU_A, 'because_i_said_so', ['srNo' => self::SR_A], 'lost');
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_reason', $r['reason']);
    }

    public function test_note_is_optional_and_defaults_to_empty(): void
    {
        $this->issueFirst();
        $this->assertSame('', $this->fs->get('idCards', $this->svc->versionDocId(self::STU_A, 1))['reasonNote']);
    }

    public function test_note_control_characters_are_stripped(): void
    {
        // A pasted newline must not break a log line or a CSV export.
        $this->assertSame('a b', \Id_card_service::normalizeNote("a\r\n\tb"));
        $this->assertSame('a b', \Id_card_service::normalizeNote('a     b'));
        $this->assertSame('', \Id_card_service::normalizeNote("   \n  "));
    }

    public function test_note_is_length_bounded(): void
    {
        $long = str_repeat('x', 500);
        $this->assertSame(300, mb_strlen(\Id_card_service::normalizeNote($long)));
        $this->assertSame(300, \Id_card_service::NOTE_MAX);
    }

    // ══ BEHAVIOURAL — lifecycle still correct after the 19H change ══════

    public function test_replacement_still_supersedes_correctly_with_a_note(): void
    {
        $this->issueFirst();
        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, ['srNo' => self::SR_A], 'left on the bus');
        $this->assertSame(2, $r['version']);
        $this->assertSame('replaced', $this->fs->get('idCards', $this->svc->versionDocId(self::STU_A, 1))['status']);
        $this->assertTrue($this->svc->isCurrentVersion(self::STU_A, 2));
        $this->assertFalse($this->svc->isCurrentVersion(self::STU_A, 1));
    }

    public function test_an_already_revoked_card_reports_noop_not_a_new_revocation(): void
    {
        $this->issueFirst();
        $this->svc->revoke(self::STU_A, \Id_card_service::REASON_LOST);
        $again = $this->svc->revoke(self::STU_A, \Id_card_service::REASON_LOST);
        $this->assertTrue($again['ok']);
        $this->assertSame('noop', $again['reason']);
    }

    public function test_replacement_changes_the_credential(): void
    {
        $a = $this->issueFirst()['credential'];
        $b = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, ['srNo' => self::SR_A])['credential'];
        $this->assertNotSame($a, $b);
        $this->assertSame(1, qr_token_decode($a)['cardVersion']);
        $this->assertSame(2, qr_token_decode($b)['cardVersion']);
    }

    // ══ BEHAVIOURAL — credential compatibility unchanged (Part 11) ══════

    public function test_legacy_v1_and_v2_all_still_decode(): void
    {
        $legacy = rtrim(strtr(base64_encode(self::SCH . '|' . self::STU_A), '+/', '-_'), '=');
        $this->assertTrue(qr_token_decode($legacy)['legacy']);
        $this->assertSame('v1', qr_token_decode(qr_token_encode(self::SCH, self::STU_A))['tokenVersion']);
        $this->assertSame('v2', qr_token_decode(qr_token_encode_v2(self::SCH, self::STU_A, 3))['tokenVersion']);
    }

    public function test_the_credential_subject_is_still_the_student_id(): void
    {
        $d = qr_token_decode($this->issueFirst()['credential']);
        $this->assertSame(self::STU_A, $d['studentId']);
        $this->assertNotSame(self::SR_A, $d['studentId'], 'SRNO must never be the credential subject');
    }

    // ══ SOURCE-SCAN — ID-UI-1 (browser JS; see class note) ══════════════

    public function test_no_native_dialog_remains_anywhere_in_the_workspace(): void
    {
        // The whole point of ID-UI-1. Scanned against comment-stripped source
        // so the note explaining the replacement does not fail this test.
        $v = self::viewCode();
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])alert\s*\(/', $v);
        $this->assertDoesNotMatchRegularExpression('/window\.prompt\s*\(/', $v);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])confirm\s*\(/', $v);
    }

    public function test_reason_capture_is_a_controlled_selection(): void
    {
        $v = self::view();
        $this->assertStringContainsString('id="idcReasonSelect"', $v);
        foreach (['lost', 'stolen', 'damaged', 'wear', 'data_correction', 'admin'] as $r) {
            $this->assertStringContainsString('value="' . $r . '"', $v);
        }
        // first_issue is derived by the service, never operator-selectable.
        $this->assertStringNotContainsString('value="first_issue"', $v);
    }

    public function test_the_note_field_is_separate_from_the_reason(): void
    {
        $v = self::view();
        $this->assertStringContainsString('id="idcReasonNote"', $v);
        $this->assertStringContainsString('maxlength="300"', $v);
    }

    public function test_an_unselected_reason_is_refused_client_side_too(): void
    {
        $this->assertStringContainsString("VALID_REASONS.indexOf(reason) === -1", self::view());
    }

    public function test_the_replace_dialog_states_what_will_happen(): void
    {
        $v = self::view();
        $this->assertStringContainsString('marked <b>replaced</b>', $v);
        $this->assertStringContainsString('become <b>active</b>', $v);
    }

    public function test_the_revoke_dialog_warns_it_is_irreversible(): void
    {
        $this->assertStringContainsString('This cannot be undone.', self::view());
    }

    public function test_the_dialog_shows_the_identity_the_operator_needs(): void
    {
        $v = self::view();
        foreach (['idcRmName', 'idcRmSr', 'idcRmAdm', 'idcRmUid', 'idcRmCard'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $v);
        }
    }

    public function test_confirm_is_disabled_while_in_flight(): void
    {
        $v = self::view();
        $this->assertStringContainsString('if (btn.disabled) return;', $v);
        $this->assertStringContainsString('btn.disabled = true;', $v);
    }

    public function test_a_failed_operation_keeps_the_dialog_open_for_retry(): void
    {
        $this->assertStringContainsString('btn.disabled = false;', self::view());
    }

    // ══ SOURCE-SCAN — history modal (Part 3) ═══════════════════════════

    public function test_history_renders_every_required_column(): void
    {
        $v = self::view();
        foreach (['Ver', 'Event', 'Status', 'Reason', 'Issued', 'Issued by', 'Replaced', 'Revoked'] as $c) {
            $this->assertStringContainsString('<th>' . $c . '</th>', $v);
        }
    }

    public function test_history_has_distinct_loading_error_and_empty_states(): void
    {
        $v = self::view();
        foreach (['idcHistLoading', 'idcHistError', 'idcHistEmpty', 'idcHistWrap', 'idcHistRetry'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $v);
        }
    }

    public function test_a_malformed_history_response_is_an_error_not_an_empty_state(): void
    {
        // Showing "no history" for a broken endpoint would hide the fault.
        $v = self::view();
        $this->assertStringContainsString('!Array.isArray(rows)', $v);
        $this->assertStringContainsString('Malformed response', $v);
    }

    public function test_history_offers_an_explicit_retry(): void
    {
        $this->assertStringContainsString("idcHistRetry').addEventListener('click', loadHistory)", self::view());
    }

    // ══ SOURCE-SCAN — ID-UI-2 selective print (Part 4) ═════════════════

    public function test_selection_controls_exist(): void
    {
        $v = self::view();
        foreach (['idcSelectAll', 'idcClearAll', 'idcPrintSelected', 'idcSelCount'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $v);
        }
        $this->assertStringContainsString('class="idc-sel-box"', $v);
    }

    public function test_print_selected_starts_disabled_and_guards_zero_selection(): void
    {
        $v = self::view();
        $this->assertStringContainsString('id="idcPrintSelected" disabled', $v);
        $this->assertStringContainsString('printSel.disabled = (n === 0)', $v);
        $this->assertStringContainsString('Select at least one card to print.', $v);
    }

    public function test_select_all_only_selects_what_is_visible(): void
    {
        // Selecting hidden rows would print a set the operator never reviewed.
        $this->assertStringContainsString('visibleBoxes().forEach', self::view());
    }

    public function test_duplicate_print_initiation_is_prevented(): void
    {
        $this->assertStringContainsString("printSel.dataset.busy === '1'", self::view());
    }

    public function test_selective_print_costs_no_firestore_read(): void
    {
        $v = self::view();
        // It clones already-rendered DOM; the only server call on this page
        // remains the single register query.
        $this->assertStringContainsString("w.querySelector('.idc-card')", $v);
        $this->assertSame(1, substr_count($v, "idcPost('sis/id_card_register'"));
    }

    public function test_existing_print_modes_are_preserved(): void
    {
        $v = self::view();
        $this->assertStringContainsString('idcPrintOne', $v);
        $this->assertStringContainsString('idcPrintFiltered', $v);
        $this->assertStringContainsString('idcPrintAll', $v);
    }

    public function test_a_print_failure_is_reported_not_silently_swallowed(): void
    {
        $this->assertStringContainsString('Could not open the print view', self::view());
    }

    // ══ SOURCE-SCAN — async UX (Part 6) ════════════════════════════════

    public function test_responses_are_checked_for_both_http_and_payload_status(): void
    {
        $v = self::view();
        $this->assertStringContainsString('!r.ok', $v);
        $this->assertStringContainsString("j.status !== 'success'", $v);
    }

    public function test_a_non_json_response_is_handled(): void
    {
        // A 500 HTML error page must not throw an unhandled parse error.
        $this->assertStringContainsString('r.json().catch(', self::view());
    }

    public function test_state_is_refreshed_from_the_server_after_a_mutation(): void
    {
        // Patching local state would show an outcome the server never confirmed.
        $this->assertStringContainsString('return refreshRegistry().then(', self::view());
    }

    public function test_the_modal_does_not_depend_on_jquery(): void
    {
        // jQuery loads in include/footer.php, AFTER this view's inline script,
        // and every other sis/* view is vanilla. A jQuery modal call here
        // would throw at parse time. Comment-stripped for the same reason as
        // the native-dialog scan.
        $v = self::viewCode();
        $this->assertStringNotContainsString(".modal('show')", $v);
        $this->assertStringNotContainsString('$(document)', $v);
        $this->assertStringContainsString("modalShow(", $v);
    }

    // ══ SRNO / identity hierarchy (Parts 7–9) ══════════════════════════

    /**
     * IDC-SRNO-1 — was a position comparison against the Student ID row, which
     * the card no longer prints when an SR No. exists. strpos() then returned
     * false and the test read "27040 is less than false", which is not a
     * sentence about identity hierarchy at all.
     */
    public function test_srno_remains_the_primary_printed_identity(): void
    {
        $v = self::view();
        $this->assertStringContainsString('<span class="idc-info-lbl">SR No.</span>', $v);
        $this->assertStringContainsString('<span class="idc-info-lbl">Adm No.</span>', $v);
        $this->assertStringNotContainsString('<span class="idc-info-lbl">Student ID</span>', $v,
            'the internal key is not a card identity');
    }

    public function test_the_credential_encode_call_binds_only_student_id(): void
    {
        $v = self::view();
        $this->assertSame(1, substr_count($v, 'qr_token_encode('));
        $this->assertStringContainsString('qr_token_encode($qrSchoolId, $uid)', $v);
        $this->assertDoesNotMatchRegularExpression(
            '/qr_token_encode\(\s*\$qrSchoolId\s*,\s*\$(srNo|admNo|rollNo|cls|sec)\b/', $v
        );
    }

    public function test_srno_is_still_never_a_document_key(): void
    {
        $this->issueFirst();
        $this->assertArrayHasKey(self::SCH . '_' . self::STU_A, $this->fb->docs['idCards']);
        $this->assertArrayNotHasKey(self::SCH . '_' . self::SR_A, $this->fb->docs['idCards']);
    }

    public function test_roll_no_still_cannot_reach_a_card(): void
    {
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE,
            ['srNo' => self::SR_A, 'rollNo' => '12', 'Roll No' => '12']);
        foreach ($this->fb->docs['idCards'] as $d) {
            $this->assertArrayNotHasKey('rollNo', $d);
            $this->assertArrayNotHasKey('Roll No', $d);
        }
    }

    public function test_search_still_covers_all_four_identifiers(): void
    {
        $v = self::view();
        foreach (['data-srno=', 'data-admno=', 'sr.indexOf(q)', 'adm.indexOf(q)',
                  'w.dataset.name.indexOf(q)', 'w.dataset.uid.indexOf(q)'] as $needle) {
            $this->assertStringContainsString($needle, $v);
        }
    }

    public function test_lifecycle_transitions_never_write_srno(): void
    {
        // SRNO survives promotion / transfer / rollover by construction: none
        // of those methods references it at all.
        $sis   = (string) file_get_contents(dirname(__DIR__, 2) . '/application/controllers/Sis.php');
        $cls   = (string) file_get_contents(dirname(__DIR__, 2) . '/application/controllers/Classes.php');
        $cfg   = (string) file_get_contents(dirname(__DIR__, 2) . '/application/controllers/School_config.php');
        foreach ([[$sis, 'execute_promotion'], [$cls, 'transfer_students'], [$cfg, 'rollover_session']] as [$src, $fn]) {
            $at = strpos($src, 'function ' . $fn . '(');
            $this->assertNotFalse($at, "{$fn}() not found");
            $open = strpos($src, '{', $at);
            $depth = 0; $body = '';
            for ($i = $open, $n = strlen($src); $i < $n; $i++) {
                if ($src[$i] === '{') $depth++;
                elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $body = substr($src, $open, $i - $open + 1); break; } }
            }
            $this->assertStringNotContainsString('srNo', $body, "{$fn}() must not touch SRNO");
        }
    }

    // ══ Boundaries (Parts 11–12) ═══════════════════════════════════════

    public function test_credential_enforcement_is_still_off(): void
    {
        foreach ([
            'application/controllers/Attendance.php',
            'application/libraries/Transport_qr_attendance_service.php',
            'application/libraries/Visitor_service.php',
        ] as $rel) {
            $this->assertStringNotContainsString('isCurrentVersion',
                (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel), $rel);
        }
    }

    public function test_no_fee_is_raised(): void
    {
        $svcSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/application/libraries/Id_card_service.php');
        $this->assertStringNotContainsString('createModuleFee', $svcSrc);
        $this->assertStringNotContainsString('feeDemands', $svcSrc);
    }

    public function test_no_rtdb(): void
    {
        foreach (['application/views/sis/id_card.php', 'application/libraries/Id_card_service.php'] as $rel) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
            foreach (['getDatabase', 'firebaseio', 'database()->'] as $n) {
                $this->assertStringNotContainsString($n, $src, $rel);
            }
        }
    }
}
