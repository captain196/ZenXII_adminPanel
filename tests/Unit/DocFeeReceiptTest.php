<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_contract;
use Doc_renderer;
use Doc_serializer;

/**
 * The fee receipt, end to end — the SHIPPED starter, printed and read back.
 *
 * This module's most expensive defect was a Transfer Certificate that printed
 * without its particulars. It survived 486 tests because every one of them
 * asked whether a render SUCCEEDED, and none asked what came out. It was found
 * by extracting the text of a PDF.
 *
 * The receipt is the first document type whose substance is a REPEATING table,
 * so it is the first whose body can be structurally empty while everything
 * around it renders perfectly — the same failure with more room to hide. So the
 * central test here takes the starter out of designer.js (the one users get,
 * not a fixture that can drift from it), renders it through the real serializer
 * and real mPDF, and asserts every line item's ink is on the page.
 */
class DocFeeReceiptTest extends TestCase
{
    private static string $tmp;
    /** @var array<string,mixed> */
    private static array $cfg;
    private Doc_contract $contract;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['BASEPATH' => __DIR__, 'FCPATH' => $root . '/', 'APPPATH' => $root . '/application/'] as $k => $v) {
            if (!defined($k)) {
                define($k, $v);
            }
        }
        require_once $root . '/vendor/autoload.php';
        require_once $root . '/application/libraries/Doc_contract.php';
        require_once $root . '/application/libraries/Doc_renderer.php';
        require_once $root . '/application/libraries/Doc_serializer.php';

        $config = [];
        require $root . '/application/config/doc_types.php';
        self::$cfg = $config;

        self::$tmp = sys_get_temp_dir() . '/zxdt_rct_' . getmypid();
        @mkdir(self::$tmp, 0755, true);
    }

    protected function setUp(): void
    {
        $this->contract = new Doc_contract([
            'fields'    => self::$cfg['doc_merge_fields'],
            'contracts' => self::$cfg['doc_contracts'],
            'types'     => self::$cfg['doc_types'],
        ]);
    }

    /**
     * The starter as designer.js actually defines it.
     *
     * Extracted with node rather than reimplemented here. A PHP copy of the
     * template would be a second source of truth, and the first thing it would
     * do is disagree with the one users get.
     */
    private function starter(): array
    {
        $js = file_get_contents(dirname(__DIR__, 2) . '/assets/js/doctemplates/designer.js');
        $this->assertNotFalse($js);

        $at = strpos($js, 'function starterFeeReceipt(){');
        $this->assertNotFalse($at, 'starterFeeReceipt() is missing from designer.js');
        $end = strpos($js, "\n}\n", $at);
        $this->assertNotFalse($end);
        $fn = substr($js, $at, $end - $at + 3);

        $script = "const T=(...runs)=>({runs});\n$fn\nconsole.log(JSON.stringify(starterFeeReceipt()));";
        $file   = self::$tmp . '/starter.js';
        file_put_contents($file, $script);

        $out = shell_exec('node ' . escapeshellarg($file) . ' 2>&1');
        $tpl = json_decode((string) $out, true);
        $this->assertIsArray($tpl, "node could not evaluate starterFeeReceipt(): $out");
        return $tpl;
    }

    /** The fee-receipt contract as field definitions, which is what the serializer wants. */
    private function contractFields(): array
    {
        return $this->contract->get('fee_receipt');
    }

    private function bundle(bool $p95 = false): array
    {
        return $this->contract->sampleBundle('fee_receipt', $p95);
    }

    private function pdfText(string $pdf): string
    {
        $f = self::$tmp . '/r_' . md5($pdf) . '.pdf';
        file_put_contents($f, $pdf);
        $txt = shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
        return (string) $txt;
    }

    /* ================================================================ *
     *  The ink
     * ================================================================ */

    public function test_every_line_item_reaches_the_printed_page(): void
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            $this->markTestSkipped('mPDF is not available');
        }
        $bundle = $this->bundle(true);
        $html   = (new Doc_serializer())->render($this->starter(), $bundle, 'en', ['contract' => $this->contractFields()]);
        $pdf    = (new Doc_renderer(['tempDir' => self::$tmp]))
            ->render($html, ['size' => 'A4', 'orientation' => 'portrait']);
        $text   = $this->pdfText($pdf);

        $items = $bundle['receipt.items'];
        $this->assertCount(7, $items, 'the p95 receipt should be the crowded one');

        foreach ($items as $n => $row) {
            // Normalise the spacing pdftotext -layout introduces between columns.
            $flat = preg_replace('/\s+/u', ' ', $text);
            $this->assertStringContainsString(
                preg_replace('/\s+/u', ' ', $row['item.head']),
                $flat,
                "line item " . ($n + 1) . " ('{$row['item.head']}') never reached the page — "
                . 'the receipt printed without the payment it records'
            );
            $this->assertStringContainsString(
                $row['item.amount'],
                $flat,
                "the amount for '{$row['item.head']}' is missing from the printed receipt"
            );
        }
    }

    public function test_the_totals_print_below_the_items_not_over_them(): void
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            $this->markTestSkipped('mPDF is not available');
        }
        $bundle = $this->bundle(true);
        $html   = (new Doc_serializer())->render($this->starter(), $bundle, 'en', ['contract' => $this->contractFields()]);
        $pdf    = (new Doc_renderer(['tempDir' => self::$tmp]))
            ->render($html, ['size' => 'A4', 'orientation' => 'portrait']);
        $text   = $this->pdfText($pdf);

        $lastItem = strrpos($text, 'Arrears carried forward');
        $net      = strpos($text, $bundle['receipt.netPaid']);
        $words    = strpos($text, 'Rupees Ninety Five Thousand');

        $this->assertNotFalse($lastItem, 'the last line item is missing');
        $this->assertNotFalse($net, 'the net amount is missing');
        $this->assertNotFalse($words, 'the amount in words is missing');
        $this->assertGreaterThan($lastItem, $net,
            'the net amount printed above the items — the anchor chain is not following the table');
        $this->assertGreaterThan($net, $words);
    }

    /**
     * The p95 receipt is more than twice as tall as the typical one, and that
     * has to be true in the OUTPUT, not just in the data. If it is not, the
     * table is being drawn at a fixed height and the extra items went nowhere.
     */
    public function test_a_longer_item_list_produces_a_longer_document(): void
    {
        $s = new Doc_serializer();
        $small = $s->render($this->starter(), $this->bundle(false), 'en', ['contract' => $this->contractFields()]);
        $large = $s->render($this->starter(), $this->bundle(true), 'en', ['contract' => $this->contractFields()]);

        $rows = fn(string $h) => substr_count($h, '<tr');
        $this->assertGreaterThan($rows($small), $rows($large),
            'seven items emitted no more rows than two — the table is not repeating');
        $this->assertSame(2 + 1, $rows($small), 'two items plus a heading row');
        $this->assertSame(7 + 1, $rows($large), 'seven items plus a heading row');
    }

    /* ================================================================ *
     *  Refusals
     * ================================================================ */

    public function test_an_empty_item_list_refuses_to_print(): void
    {
        $bundle = $this->bundle();
        $bundle['receipt.items'] = [];

        $this->expectException(\RuntimeException::class);
        (new Doc_serializer())->render($this->starter(), $bundle, 'en', ['contract' => $this->contractFields()]);
    }

    /**
     * A list is rows, not a string. Casting one gives "Array" — five characters,
     * inside every maxLen there is — so a malformed list would validate clean
     * and print a table of nothing.
     */
    public function test_the_contract_checks_a_list_by_its_columns_not_as_a_string(): void
    {
        $bundle = $this->bundle();
        $bundle['receipt.items'] = 'Array';
        $r = $this->contract->validateBundle('fee_receipt', $bundle);

        $this->assertFalse($r['ok']);
        $this->assertSame('badType', $r['errors'][0]['type']);
        $this->assertSame('receipt.items', $r['errors'][0]['key']);
    }

    public function test_a_row_missing_a_printed_column_is_an_error(): void
    {
        $bundle = $this->bundle();
        $bundle['receipt.items'] = [
            ['item.head' => 'Tuition fee', 'item.period' => 'Apr–Jun 2026', 'item.amount' => '12,600.00'],
            ['item.head' => 'Transport fee', 'item.period' => 'Apr–Jun 2026'],   // no amount
        ];
        $r = $this->contract->validateBundle('fee_receipt', $bundle);

        $this->assertFalse($r['ok'], 'a fee row with no amount must not print');
        $keys = array_column($r['errors'], 'key');
        $this->assertContains('receipt.items/item.amount', $keys);
        $this->assertStringContainsString('row 2', $r['errors'][0]['message']);
    }

    public function test_an_empty_list_is_unresolved_rather_than_silently_accepted(): void
    {
        $bundle = $this->bundle();
        $bundle['receipt.items'] = [];
        $r = $this->contract->validateBundle('fee_receipt', $bundle);

        $this->assertFalse($r['ok']);
        $this->assertSame('unresolved', $r['errors'][0]['type']);
    }

    /** Over-length stays a warning here too — maxLen is our estimate, not a statute. */
    public function test_an_over_long_cell_warns_and_does_not_block(): void
    {
        $bundle = $this->bundle();
        $bundle['receipt.items'] = [[
            'item.head'   => str_repeat('Tuition and transport and hostel ', 4),
            'item.period' => 'Apr–Jun 2026',
            'item.amount' => '12,600.00',
        ]];
        $r = $this->contract->validateBundle('fee_receipt', $bundle);

        $this->assertTrue($r['ok'], 'a long but lawful particular must reach the overflow gate, not be blocked here');
        $this->assertSame('overLength', $r['warnings'][0]['type']);
        $this->assertSame('receipt.items/item.head', $r['warnings'][0]['key']);
    }

    /* ================================================================ *
     *  Findings this type turned up
     * ================================================================ */

    /**
     * A page number must print a NUMBER, however narrow its box.
     *
     * mPDF substitutes {PAGENO} by finding that exact contiguous string in the
     * page content. The receipt's first page-number box was 20mm wide, the
     * placeholder wrapped after "{PAGENO", and the printed receipt carried the
     * literal token — while the Transfer Certificate, from identical markup in
     * a 180mm box, printed "1". Nothing in the HTML distinguished them, and no
     * assertion about the HTML could have: only the extracted PDF text showed
     * it. The serializer now emits nowrap, and this test prints the narrow case.
     */
    public function test_a_narrow_page_number_box_still_prints_a_number(): void
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            $this->markTestSkipped('mPDF is not available');
        }
        $tpl = [
            'templateId' => 'TPLPG', 'docType' => 'fee_receipt',
            'languages' => ['en'], 'defaultLanguage' => 'en',
            'page' => ['size' => 'A4', 'orientation' => 'portrait',
                       'marginsMm' => ['t' => 15, 'r' => 15, 'b' => 16, 'l' => 15]],
            'objects' => [[
                'id' => 'pg', 'name' => 'Page number', 'region' => 'footer',
                'type' => 'pageNumber', 'xMm' => 95, 'yMm' => 285, 'wMm' => 20,
                'hMm' => 5, 'z' => 1, 'height' => 'fixed', 'content' => [],
                'style' => ['sizePt' => 8, 'lineHeight' => 1.2, 'align' => 'center'],
            ]],
        ];
        $html = (new Doc_serializer())->render($tpl, [], 'en');
        $this->assertStringContainsString('white-space:nowrap', $html);

        $pdf  = (new Doc_renderer(['tempDir' => self::$tmp]))
            ->render($html, ['size' => 'A4', 'orientation' => 'portrait']);
        $text = $this->pdfText($pdf);

        $this->assertStringNotContainsString('PAGENO', $text,
            'the placeholder printed instead of a page number');
        $this->assertStringContainsString('1', $text);
    }

    /**
     * And a box that is genuinely too narrow is refused at design time.
     *
     * nowrap alone does not save it: mPDF hard-breaks text that cannot fit, so a
     * 12mm box printed "{PAGEN" and "O}" on two lines. There is nothing to
     * notice afterwards, so the refusal has to happen before the PDF exists.
     */
    public function test_a_page_number_box_too_narrow_to_work_is_refused(): void
    {
        $tpl = [
            'templateId' => 'TPLPG2', 'docType' => 'fee_receipt',
            'languages' => ['en'], 'defaultLanguage' => 'en',
            'page' => ['size' => 'A4', 'orientation' => 'portrait',
                       'marginsMm' => ['t' => 15, 'r' => 15, 'b' => 16, 'l' => 15]],
            'objects' => [[
                'id' => 'pg', 'name' => 'Page number', 'region' => 'footer',
                'type' => 'pageNumber', 'xMm' => 95, 'yMm' => 285, 'wMm' => 12,
                'hMm' => 5, 'z' => 1, 'height' => 'fixed', 'content' => [],
                'style' => ['sizePt' => 8, 'lineHeight' => 1.2, 'align' => 'center'],
            ]],
        ];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/too narrow/');
        (new Doc_serializer())->render($tpl, [], 'en');
    }

    /**
     * The p95 receipt's own numbers must add up.
     *
     * They did not: the stress sample listed items totalling 95,550 beside a net
     * of 16,100 carried over from the typical case. A designer stress-testing a
     * receipt layout would have proofed, and approved, a document whose total
     * contradicted its items — and every assertion about it would have passed,
     * because no test compared one sample field against another.
     */
    public function test_the_receipt_samples_add_up(): void
    {
        $n = fn(string $s) => (float) str_replace(',', '', $s);

        foreach ([false, true] as $p95) {
            $b     = $this->bundle($p95);
            $items = array_sum(array_map(fn($r) => $n($r['item.amount']), $b['receipt.items']));
            $which = $p95 ? 'p95' : 'typical';

            $fields = self::$cfg['doc_merge_fields'];
            $pick   = fn(string $k) => $n($p95 ? ($fields[$k]['p95'] ?? $fields[$k]['sample'])
                                               : $fields[$k]['sample']);

            $this->assertSame($items, $pick('receipt.gross'),
                "the $which items total " . number_format($items, 2)
                . ' but the gross says ' . number_format($pick('receipt.gross'), 2));
            $this->assertSame(
                $pick('receipt.gross') - $pick('receipt.discount') + $pick('receipt.fine'),
                $pick('receipt.netPaid'),
                "the $which net does not follow from gross − concession + late fee"
            );
        }
    }

    /* ================================================================ *
     *  The seam stays closed
     * ================================================================ */

    /**
     * Designing a receipt is not issuing one. The receipt's print point in
     * document_targets.php must stay unwired, exactly like every other.
     */
    public function test_the_receipt_has_a_print_point_and_it_is_still_unwired(): void
    {
        $targets = include dirname(__DIR__, 2) . '/application/config/document_targets.php';

        $this->assertArrayHasKey('fee_receipt', $targets,
            'the receipt can be designed but nothing records where it would print from');
        $this->assertFalse((bool) ($targets['fee_receipt']['wired'] ?? false),
            'a print point became wired — CON-NO_PRINT_IMPL says no module issues from this build');
    }
}
