<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 4 (Exit Documents),
 * 2026-08-18.
 *
 * Follows the EXISTING closest precedent — Sis::print_tc() — rather than
 * inventing a new PDF architecture: a standalone HTML view, no header/
 * footer chrome, browser print-to-PDF. This codebase's Dompdf usage
 * (Pdf_generator.php) is for report cards only, a different document
 * class — exit documents are shaped like the TC, not a report card.
 *
 * Uses ONLY verified employment facts — NO settlement figures of any kind.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code; the view is a print template, checked structurally).
 */
class StaffExitDocumentsTest extends TestCase
{
    private string $controllerSrc;
    private string $viewSrc;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->controllerSrc = $out;

        $this->viewSrc = file_get_contents(__DIR__ . '/../../application/views/staff/exit_document_print.php');
        $this->assertNotFalse($this->viewSrc);
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function printFnBody(): string
    {
        $start = strpos($this->controllerSrc, 'public function print_exit_document(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->controllerSrc, $start);
    }

    public function test_gated_at_view_tier_matching_print_tc(): void
    {
        $body = $this->printFnBody();
        $this->assertStringContainsString('_require_role(self::VIEW_ROLES, "", "SIS", "view")', $body);
    }

    public function test_uses_a_standalone_view_not_dompdf(): void
    {
        $body = $this->printFnBody();
        $this->assertStringContainsString("\$this->load->view('staff/exit_document_print', \$data);", $body);
        $this->assertStringNotContainsString('Dompdf', $this->controllerSrc);
        $this->assertStringNotContainsString('Pdf_generator', $body);
    }

    public function test_refuses_for_active_staff_or_missing_exit_date(): void
    {
        $body = $this->printFnBody();
        $this->assertStringContainsString(
            "if (strtolower(trim(\$rawStatus)) !== 'inactive' || \$exitDate === '') {",
            $body
        );
        $this->assertStringContainsString('409', $body);
    }

    public function test_document_type_restricted_to_the_known_three(): void
    {
        $body = $this->printFnBody();
        $this->assertStringContainsString('self::EXIT_DOCUMENT_TYPES', $body);
        $this->assertStringContainsString(
            "private const EXIT_DOCUMENT_TYPES = ['relieving', 'experience', 'service'];",
            $this->controllerSrc
        );
    }

    public function test_unknown_type_falls_back_to_relieving_not_an_error(): void
    {
        $body = $this->printFnBody();
        $this->assertStringContainsString("if (!in_array(\$docType, self::EXIT_DOCUMENT_TYPES, true)) \$docType = 'relieving';", $body);
    }

    public function test_staff_id_validated_before_use(): void
    {
        $body = $this->printFnBody();
        $this->assertStringContainsString("preg_match('/^[A-Za-z0-9_\\-]+\$/', \$staffId)", $body);
    }

    // ── View: no financial figures, uses verified facts only ────────────

    public function test_view_contains_no_settlement_or_financial_wording(): void
    {
        $lower = strtolower($this->viewSrc);
        foreach (['salary', 'payroll', 'settlement', 'amount due', 'encashment', 'gratuity'] as $term) {
            $this->assertStringNotContainsString($term, $lower, "'{$term}' must never appear on an exit document — no settlement figures, per the standing instruction.");
        }
    }

    public function test_view_covers_all_three_document_types(): void
    {
        $this->assertStringContainsString("'relieving'  => 'Relieving Letter',", $this->viewSrc);
        $this->assertStringContainsString("'experience' => 'Experience Certificate',", $this->viewSrc);
        $this->assertStringContainsString("'service'    => 'Service Certificate',", $this->viewSrc);
    }

    public function test_view_uses_verified_employment_fields_only(): void
    {
        foreach (["\$st['position']", "\$st['department']", "\$st['dateOfJoining']", "\$st['exitDate']"] as $field) {
            $this->assertStringContainsString($field, $this->viewSrc);
        }
    }

    public function test_view_has_print_button_and_print_media_query(): void
    {
        $this->assertStringContainsString('onclick="window.print()"', $this->viewSrc);
        $this->assertStringContainsString('@media print', $this->viewSrc);
    }

    public function test_missing_dates_render_a_blank_line_never_a_guess(): void
    {
        // Mirrors tc_print.php's own convention: a blank/fillable line is
        // correct on a document the school can't retract once issued;
        // guessing a date would be worse.
        $this->assertStringContainsString('if (empty($raw)) return \'_______________\';', $this->viewSrc);
    }
}
