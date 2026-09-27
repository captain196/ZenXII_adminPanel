<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FNF-5 / FNF-6 — the settlement statement, and employee visibility.
 *
 * The statement REUSES the shipped document architecture: build HTML, hand it
 * to `Pdf_generator`, exactly as `download_payslip()` does. A second PDF stack
 * would drift from the first, and a leaver's statement disagreeing with their
 * payslip is the failure that matters.
 *
 * What it reports and what it refuses to invent: the settlement holds no
 * amounts by design, so the statement reads them from the SLIP the linked
 * off-cycle run produced. Where there is no computation there is no statement.
 */
final class FnfSettlementDocumentTest extends TestCase
{
    private static string $ctrl = '';
    private static string $view = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$ctrl = (string) file_get_contents($root . 'application/controllers/Compensation.php');
        self::$view = (string) file_get_contents($root . 'application/views/compensation/index.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$ctrl = self::$view = '';
    }

    // ── One document stack, not two ──────────────────────────────────────

    /** The statement uses the same generator as payslips and report cards. */
    public function test_it_reuses_the_shipped_pdf_architecture(): void
    {
        $this->assertStringContainsString("\$this->load->library('pdf_generator');", self::$ctrl);
        $this->assertStringContainsString('$this->pdf_generator->download($html, $name);', self::$ctrl);

        // The same library payroll already uses — one stack.
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertStringContainsString("\$this->load->library('pdf_generator');", $hr);

        // And no parallel generator was introduced.
        foreach (['new Dompdf', 'require_once.*dompdf', 'class .*PdfBuilder'] as $forbidden) {
            $this->assertDoesNotMatchRegularExpression('~' . $forbidden . '~', self::$ctrl,
                'the statement must not stand up a second PDF stack');
        }
    }

    // ── It reports; it does not calculate ────────────────────────────────

    /**
     * The amounts come from the slip the linked run wrote — the same doc-id
     * shape payroll uses everywhere. The statement formats them.
     */
    public function test_the_amounts_come_from_the_payroll_slip(): void
    {
        $this->assertStringContainsString('$this->fs->docId("SLIP_{$runId}_{$staffId}")', self::$ctrl);

        foreach (["\$slip['gross']", "\$slip['total_deductions']", "\$slip['net_pay']"] as $field) {
            $this->assertStringContainsString($field, self::$ctrl);
        }

        $this->assertStringContainsString('does not recalculate', self::$ctrl,
            'the document should say so on its face');
    }

    /** No computation, no statement — a document with blanks where money goes. */
    public function test_an_uncomputed_settlement_produces_no_statement(): void
    {
        $this->assertStringContainsString("if (empty(\$comp) || empty(\$comp['runId'])) {", self::$ctrl);
        $this->assertStringContainsString('has not been computed yet', self::$ctrl);
    }

    /**
     * An unreadable slip is SAID, not silently rendered as zero. A statement
     * showing 0.00 because a read failed is worse than no statement.
     */
    public function test_an_unreadable_slip_is_flagged_on_the_document(): void
    {
        $this->assertStringContainsString('should not be issued', self::$ctrl);
    }

    // ── Honesty about provisional figures ────────────────────────────────

    /**
     * A settlement that is not yet approved is LABELLED. A draft that looks
     * final is how a leaver ends up holding a figure nobody agreed.
     */
    public function test_a_provisional_settlement_is_labelled(): void
    {
        $this->assertStringContainsString(
            "\$final  = in_array(\$status, ['approved', 'paid', 'closed'], true);", self::$ctrl);
        $this->assertStringContainsString('PROVISIONAL — not yet approved', self::$ctrl);
        $this->assertStringContainsString('not a final settlement statement', self::$ctrl);
    }

    /** The pinned policy is on the document, including when none applied. */
    public function test_the_pinned_policy_appears_on_the_statement(): void
    {
        $this->assertStringContainsString("\$comp['policyVersion']", self::$ctrl);
        $this->assertStringContainsString("\$comp['policyEffective']", self::$ctrl);
        $this->assertStringContainsString('No Full &amp; Final policy was in force', self::$ctrl);
    }

    /** An unset notice basis is stated rather than shown as a silent zero. */
    public function test_an_unset_notice_basis_is_stated(): void
    {
        $this->assertStringContainsString(
            'Notice recovery basis was not configured, so no recovery was applied.', self::$ctrl);
    }

    /** A clearance override is disclosed on the document. */
    public function test_a_clearance_override_is_disclosed(): void
    {
        $this->assertStringContainsString('Overridden by an administrator', self::$ctrl);
    }

    /** The lifecycle history is printed — who moved it, when, and why. */
    public function test_the_history_is_printed(): void
    {
        $this->assertStringContainsString("\$doc['statusHistory']", self::$ctrl);
    }

    // ── Safety ───────────────────────────────────────────────────────────

    /** Every interpolated value is escaped — this is operator-authored text. */
    public function test_values_are_escaped(): void
    {
        $this->assertStringContainsString("\$e = static function (\$v): string { return html_escape(", self::$ctrl);

        $i = strpos(self::$ctrl, 'private function _render_settlement_html');
        $body = substr(self::$ctrl, (int) $i, 6000);

        // Assert the PROPERTY, not a count. An arbitrary threshold passes or
        // fails on how the renderer happens to be written; what matters is
        // that nothing reaches the HTML unescaped.
        preg_match_all(
            '~\.\s*(\$(?:doc|comp|slip|profile|school)\[[^\]]*\][^.
]*)~', $body, $m);

        $raw = [];
        foreach ($m[1] as $x) {
            if (strpos($x, '$e(') === false && strpos($x, '$money(') === false) {
                $raw[] = trim($x);
            }
        }

        $this->assertSame([], $raw,
            'these values reach the document unescaped: ' . implode(' | ', $raw));
    }

    /** It is tenant-scoped and permission-gated like everything else here. */
    public function test_it_is_gated_and_tenant_scoped(): void
    {
        $i = strpos(self::$ctrl, 'public function download_settlement');
        $body = substr(self::$ctrl, (int) $i, 900);

        $this->assertStringContainsString('$this->_require_view();', $body);
        $this->assertStringContainsString('$this->_fnf_load($sid);', $body,
            'the loader is what refuses a settlement from another tenant');
    }

    // ── Reachability ─────────────────────────────────────────────────────

    /** The screen offers it, and the route exists. */
    public function test_the_statement_is_reachable(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');
        $this->assertStringContainsString("\$route['compensation/download_settlement']", $routes);

        $this->assertStringContainsString('compensation/download_settlement?settlement_id=', self::$view);
        $this->assertStringContainsString("'statement'", self::$view);
    }

    /**
     * A download opens in a new tab rather than going through the AJAX helper —
     * the PDF is streamed, so a spinner would never clear.
     */
    public function test_the_download_is_not_an_ajax_call(): void
    {
        $i = strpos(self::$view, 'download_settlement');
        $near = substr(self::$view, max(0, (int) $i - 300), 500);

        $this->assertStringContainsString('window.open(', $near);
    }

    /** A cancelled settlement offers no statement — there is nothing to issue. */
    public function test_a_cancelled_settlement_has_no_statement(): void
    {
        $i = strpos(self::$view, 'var FNF_ACTIONS = {');
        $map = substr(self::$view, (int) $i, 1400);

        $this->assertStringContainsString('cancelled:  []', $map);
    }

    // ── FNF-6: the visibility patch ──────────────────────────────────────

    /** The patch exists, is applied to a COPY, and never edits the rules file. */
    public function test_the_visibility_patch_is_applied(): void
    {
        $root = __DIR__ . '/../../firebase-rules/';

        // The patch and its evidence stay in the repo after deployment: they
        // are how the block is re-applied if the rules file is ever rebuilt.
        $this->assertFileExists($root . 'patches/fnf_settlement_visibility.js');
        $this->assertFileExists($root . 'patches/apply_fnf_visibility.js');
        $this->assertFileExists($root . 'tests/fnf_settlement_visibility.test.js');

        // FNF-6 was DEPLOYED on 2026-08-25 and verified against the live
        // ruleset fetched back from the Rules API (27/27). This assertion was
        // previously its inverse — "must NOT contain it, this programme does
        // not deploy" — and is flipped rather than deleted so the working tree
        // cannot silently drift back behind production.
        $rules = (string) file_get_contents($root . 'firestore.rules');
        $this->assertStringContainsString('match /staffSettlements/{docId}', $rules,
            'the working tree must not fall behind the deployed ruleset');

        // The security shape that matters, not merely the block's presence.
        $this->assertStringContainsString('resource.data.staffId == request.auth.uid', $rules);
        $this->assertMatchesRegularExpression(
            '~match /staffSettlements/\{docId\}\s*\{[^}]*allow write:\s*if false;~s', $rules,
            'settlements must stay server-write-only');
    }

    /** Writes stay closed: a client-writable settlement could self-approve. */
    public function test_the_patch_keeps_writes_closed(): void
    {
        $patch = (string) file_get_contents(
            __DIR__ . '/../../firebase-rules/patches/fnf_settlement_visibility.js');

        $this->assertStringContainsString('allow write: if false;', $patch);
        $this->assertStringContainsString('resource.data.staffId == request.auth.uid', $patch);
    }

    /**
     * The commentary-exposure decision is RECORDED in the patch rather than
     * discovered later: opening an owner arm makes rejectNote and
     * cancelledReason readable by the employee.
     */
    public function test_the_commentary_exposure_is_recorded_as_a_decision(): void
    {
        $patch = (string) file_get_contents(
            __DIR__ . '/../../firebase-rules/patches/fnf_settlement_visibility.js');

        $this->assertStringContainsString('rejectNote', $patch);
        $this->assertStringContainsString('PRODUCT DECISION', $patch);
        $this->assertStringContainsString('rules cannot filter fields', $patch,
            'the alternative must be stated, not implied');
    }

    // ── Found by RUNTIME execution, not by reading ───────────────────────

    /**
     * DEFECT FIXED. Executing the renderer emitted "Undefined array key
     * policyEffective": the optional keys were read without a guard, so a
     * settlement whose computation predates a field — or never recorded one —
     * produced warnings instead of a clean document.
     *
     * Static reading did not catch this. Running it did, because every earlier
     * fixture happened to carry the key.
     */
    public function test_optional_keys_are_guarded(): void
    {
        foreach ([
            "(\$comp['policyEffective'] ?? '')",
            "!empty(\$comp['clearanceOverridden'] ?? null)",
            "!empty(\$doc['paymentRef'])",
            "!empty(\$doc['accountingEntryId'])",
            "is_array(\$doc['statusHistory'] ?? null)",
        ] as $guarded) {
            $this->assertStringContainsString($guarded, self::$ctrl,
                'an optional key read without a guard warns when it is absent');
        }
    }

    /**
     * DEFECT FIXED. A negative net means the recovery exceeded what was owed —
     * the employee owes the school. The document printed "Net payable:
     * -21,500.00" and left the reader to work out the sign, which on a money
     * document handed to a departing employee is how a figure gets misread.
     *
     * Naming the number is not a policy choice; it is saying what it is.
     */
    public function test_a_negative_net_is_named_not_left_as_a_minus_sign(): void
    {
        $this->assertStringContainsString('Net recoverable from employee', self::$ctrl);
        $this->assertStringContainsString('$money(abs($net))', self::$ctrl,
            'the label carries the sign, so the figure itself reads positive');

        // The positive case still reads as payable.
        $this->assertStringContainsString('<td>Net payable</td>', self::$ctrl);
    }
}
