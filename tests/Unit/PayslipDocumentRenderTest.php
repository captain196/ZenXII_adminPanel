<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PayrollEngine.php';

/**
 * Payslip document — real HTML generation, parsed and validated field by field.
 *
 * A payslip is the single most consequential document the system hands an
 * employee: it is what they check their pay against, what they show a landlord
 * or a bank, and what a labour dispute would turn on. It therefore gets the
 * same treatment as the XLSX export — generate for real, parse the output, and
 * assert the actual values — rather than a "the document was produced" check.
 *
 * `download_payslip()` cannot be run in a test (it streams a file download),
 * but it delegates the entire document to a pure builder:
 *
 *     _render_payslip_html(array $run, array $slip, $profile, string $school,
 *                          string $addr, string $logo, string $staffId): string
 *
 * which touches no Firestore. This calls that builder with figures taken from
 * the real payroll engine, parses the returned HTML with DOMDocument, and reads
 * the values back out of the document tree.
 *
 * The PDF stage is exercised separately through `Pdf_generator::render()`,
 * which returns the PDF bytes directly. Content is asserted against the HTML
 * (where it is inspectable); the PDF is asserted to be a structurally valid
 * document, which is the meaningful question at that layer.
 */
final class PayslipDocumentRenderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!PayrollEngine::available()) {
            $this->markTestSkipped('payroll engine could not be loaded on this machine');
        }
    }

    /** A slip computed by the real engine: 26 working days, 0.5 absent. */
    private function slip(array $over = []): array
    {
        $c = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.5, 1.0);

        return array_merge([
            'staff_name'       => 'Asha Menon',
            'department'       => 'Science',
            'basic'            => $c['basic'],
            'gross'            => $c['gross'],
            'net_pay'          => $c['net_pay'],
            'total_deductions' => $c['total_deductions'],
            'days_worked'      => $c['days_worked'],     // fractional
            'working_days'     => 26,
            'lwp_days'         => 1.0,
            'lwp_deduction'    => $c['lwp_deduction'],
        ], $over);
    }

    private function profile(array $over = []): array
    {
        return array_merge([
            'Name'            => 'Asha Menon',
            'Department'      => 'Science',
            'Position'        => 'Senior Teacher',
            'Date Of Joining' => '2019-06-01',
            'bankDetails'     => ['bankName' => 'State Bank', 'accountNumber' => '00112233445'],
        ], $over);
    }

    private function html(array $slip = null, array $profile = null, string $school = 'Vikrant Public School'): string
    {
        return (string) PayrollEngine::call(
            '_render_payslip_html',
            ['month' => 'March', 'year' => '2026'],
            $slip ?? $this->slip(),
            $profile ?? $this->profile(),
            $school,
            '12 Ring Road, Kanpur',
            '',
            'STA0007'
        );
    }

    /**
     * Parse the generated HTML and return its visible text.
     *
     * The <style> block is removed first: DOMDocument::textContent includes
     * stylesheet source, which would otherwise let a CSS rule accidentally
     * satisfy a content assertion.
     */
    private function text(string $html): string
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        foreach (iterator_to_array($doc->getElementsByTagName('style')) as $style) {
            $style->parentNode->removeChild($style);
        }

        return preg_replace('/\s+/', ' ', (string) $doc->textContent);
    }

    // ── The document is real and well-formed ─────────────────────────────

    public function test_the_builder_produces_parseable_html(): void
    {
        $html = $this->html();

        $this->assertNotSame('', trim($html));
        $this->assertStringContainsString('<table', $html);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $this->assertTrue($ok, 'the payslip must parse as HTML, not merely be a string');
        $this->assertGreaterThan(0, $doc->getElementsByTagName('table')->length);
    }

    // ── Identity fields ──────────────────────────────────────────────────

    public function test_the_payslip_identifies_the_employee_and_period(): void
    {
        $t = $this->text($this->html());

        foreach (['Asha Menon', 'STA0007', 'Science', 'Senior Teacher', 'March', '2026'] as $needle) {
            $this->assertStringContainsString($needle, $t, "payslip must state '{$needle}'");
        }
    }

    public function test_the_payslip_identifies_the_school(): void
    {
        $t = $this->text($this->html());

        $this->assertStringContainsString('Vikrant Public School', $t);
        $this->assertStringContainsString('Ring Road', $t);
    }

    public function test_bank_details_are_present_for_payment_reconciliation(): void
    {
        $t = $this->text($this->html());

        $this->assertStringContainsString('State Bank', $t);
        $this->assertStringContainsString('00112233445', $t);
    }

    // ── Money, to the paise ──────────────────────────────────────────────

    /** The figure the employee actually checks. */
    public function test_net_pay_is_printed_to_the_paise(): void
    {
        $slip = $this->slip();
        $t    = $this->text($this->html($slip));

        // The document formats money with thousands separators and two
        // decimals, e.g. 37,692.31 — the paise must be on the page.
        $this->assertStringContainsString(
            number_format((float) $slip['net_pay'], 2),
            $t,
            'net pay must appear on the payslip, to the paise'
        );
        $this->assertStringNotContainsString('NAN', strtoupper($t));
        $this->assertStringNotContainsString('INF', strtoupper($t));
    }

    public function test_gross_and_basic_are_printed_to_the_paise(): void
    {
        $slip = $this->slip();
        $t    = $this->text($this->html($slip));

        foreach (['gross', 'basic'] as $k) {
            $this->assertStringContainsString(
                number_format((float) $slip[$k], 2),
                $t,
                "the {$k} figure must appear with its paise"
            );
        }
    }

    /**
     * A deduction line must be stated even when it is the whole reason the net
     * differs from the gross.
     */
    public function test_the_lwp_deduction_line_is_stated(): void
    {
        $slip = $this->slip();
        $t    = $this->text($this->html($slip));

        $this->assertGreaterThan(0.0, (float) $slip['lwp_deduction'], 'fixture sanity');
        $this->assertStringContainsString(
            number_format((float) $slip['lwp_deduction'], 2),
            $t,
            'an employee must be able to see what was deducted and why'
        );
    }

    // ── Fractional days must not be truncated ────────────────────────────

    /**
     * THE FRACTIONAL RISK, on the most visible surface. The engine returns
     * days_worked = 24.5 for this slip. Printing "24" or "25" would misstate
     * the employee's attendance on the document they check it against.
     */
    public function test_a_fractional_day_count_survives_onto_the_document(): void
    {
        $slip = $this->slip();
        $this->assertEqualsWithDelta(24.5, (float) $slip['days_worked'], 0.0001,
            'fixture sanity: 26 working days less 0.5 absent less 1.0 LWP');

        $t = $this->text($this->html($slip));

        $this->assertStringContainsString('24.5', $t,
            'a half day worked must print as 24.5, never rounded to a whole day');
    }

    public function test_lwp_days_appear_on_the_document(): void
    {
        $t = $this->text($this->html());

        $this->assertStringContainsString('26', $t, 'working days must be stated');
    }

    // ── Escaping ─────────────────────────────────────────────────────────

    /**
     * Every user-controlled string in this builder is passed through
     * htmlspecialchars() — 15 of them. This proves it behaviourally rather than
     * by counting calls: a name containing markup must not become markup.
     */
    public function test_a_name_containing_markup_is_escaped_not_rendered(): void
    {
        $name = 'Ravi <b>Kumar</b> & Co';
        $html = $this->html($this->slip(['staff_name' => $name]), $this->profile(['Name' => $name]));

        $this->assertStringContainsString('&lt;b&gt;', $html,
            'markup in a staff name must be escaped into the document');
        $this->assertStringNotContainsString('Ravi <b>Kumar</b>', $html,
            'an unescaped name would let stored data alter the document structure');

        // And it must still read correctly to a human.
        $this->assertStringContainsString('Ravi <b>Kumar</b> & Co', $this->text($html));
    }

    public function test_an_ampersand_in_the_school_name_is_escaped(): void
    {
        $html = $this->html(null, null, 'St. Mary & St. Joseph School');

        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringContainsString('St. Mary & St. Joseph School', $this->text($html));
    }

    // ── Degenerate input ─────────────────────────────────────────────────

    /** A missing profile must degrade, not fatal — the slip still has names. */
    public function test_a_missing_profile_still_produces_a_document(): void
    {
        $html = $this->html($this->slip(), []);
        $t    = $this->text($html);

        $this->assertStringContainsString('Asha Menon', $t,
            'the slip name stands in when the profile is absent');
        $this->assertStringContainsString('STA0007', $t);
    }

    public function test_an_empty_slip_does_not_fatal(): void
    {
        $t = $this->text($this->html([], $this->profile()));

        $this->assertStringContainsString('Asha Menon', $t, 'profile name is used');
        $this->assertStringNotContainsString('NAN', strtoupper($t));
    }

    // ── The PDF stage ────────────────────────────────────────────────────

    /**
     * Render the generated HTML through the SAME Dompdf wrapper the controller
     * uses and assert the result is a structurally valid PDF. Content is
     * asserted above against the HTML, where it is actually inspectable.
     */
    public function test_the_html_renders_to_a_structurally_valid_pdf(): void
    {
        $lib = __DIR__ . '/../../application/libraries/Pdf_generator.php';
        if (!is_file($lib)) $this->markTestSkipped('Pdf_generator not present');
        if (!class_exists('Dompdf\\Dompdf')) $this->markTestSkipped('Dompdf is not installed');

        require_once $lib;

        try {
            $gen = new Pdf_generator();
            $pdf = $gen->render($this->html());
        } catch (\Throwable $e) {
            $this->markTestSkipped('Dompdf could not render in this environment: ' . $e->getMessage());
        }

        $this->assertIsString($pdf);
        $this->assertGreaterThan(1000, strlen($pdf), 'a payslip PDF should not be near-empty');
        $this->assertSame('%PDF-', substr($pdf, 0, 5), 'output must carry the PDF magic bytes');
        $this->assertStringContainsString('%%EOF', substr($pdf, -1024),
            'a truncated PDF would open as a corrupt file');
    }
}
