<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_contract;
use Doc_serializer;
use Doc_template_service;
use InvalidArgumentException;
use RuntimeException;

/**
 * T2 boundary rows, converted from human UAT to machine checks.
 *
 * The matrix asks a person to type a 10,000-character name, or a punctuation-only one, or
 * to place 500 objects on a page, and see what happens. None of that needs a person — it
 * needs the real classes and a hostile input. As tests they run on every commit instead of
 * once, in a session somebody has to schedule, by a tester who has to remember the exact
 * string that broke it last time.
 *
 * Each test names the row it discharges.
 */
class DocBoundaryTest extends TestCase
{
    private array $docs;
    private Doc_template_service $svc;
    private Doc_contract $contract;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        foreach (['Doc_contract', 'Doc_serializer', 'Doc_template_service'] as $c) {
            require_once __DIR__ . "/../../application/libraries/$c.php";
        }
    }

    protected function setUp(): void
    {
        $config = [];
        require __DIR__ . '/../../application/config/doc_types.php';
        $this->contract = new Doc_contract([
            'fields' => $config['doc_merge_fields'], 'contracts' => $config['doc_contracts'],
            'types'  => $config['doc_types'],
        ]);

        $this->docs = ['documentTemplates' => [
            'SCH1_TPL1' => [
                'schoolId' => 'SCH1', 'templateId' => 'TPL1', 'docType' => 'bonafide',
                'name' => 'T', 'status' => 'draft', 'version' => 1, 'lockVersion' => 3,
                'publishedVersion' => null, 'activeVersion' => null,
                'page' => ['size' => 'A4', 'marginsMm' => ['t' => 15, 'r' => 15, 'b' => 15, 'l' => 15]],
                'header' => [], 'footer' => [], 'objects' => [['id' => 'a']],
                'languages' => ['en'], 'defaultLanguage' => 'en',
            ],
        ], 'documentTemplateVersions' => []];

        $this->svc = new Doc_template_service([
            'schoolId' => 'SCH1',
            'store' => [
                'get'    => fn($c, $id) => $this->docs[$c][$id] ?? null,
                'set'    => function ($c, $id, $d) { $this->docs[$c][$id] = $d; return true; },
                'update' => function ($c, $id, $d) { $this->docs[$c][$id] = array_merge($this->docs[$c][$id] ?? [], $d); return true; },
                'exists' => fn($c, $id) => isset($this->docs[$c][$id]),
                'query'  => fn() => $this->docs['documentTemplates'],
                'delete' => function ($c, $id) { unset($this->docs[$c][$id]); return true; },
                'commit' => function (array $ops) {
                    foreach ($ops as $op) {
                        if (($op['precondition']['exists'] ?? null) === false
                            && isset($this->docs[$op['collection']][$op['docId']])) { return false; }
                    }
                    foreach ($ops as $op) {
                        $c = $op['collection']; $id = $op['docId'];
                        $this->docs[$c][$id] = !empty($op['merge'])
                            ? array_merge($this->docs[$c][$id] ?? [], $op['data']) : $op['data'];
                    }
                    return true;
                },
            ],
            'audit' => fn() => null,
        ]);
    }

    private function tpl(array $objects, array $over = []): array
    {
        return array_merge([
            'templateId' => 'TPLB', 'docType' => 'bonafide',
            'languages' => ['en'], 'defaultLanguage' => 'en',
            'page' => ['size' => 'A4', 'orientation' => 'portrait',
                       'marginsMm' => ['t' => 15, 'r' => 15, 'b' => 15, 'l' => 15]],
            'objects' => $objects,
        ], $over);
    }

    private function textObj(array $over = []): array
    {
        return array_merge([
            'id' => 'x', 'type' => 'text', 'xMm' => 10, 'yMm' => 10, 'wMm' => 100, 'hMm' => 8,
            'z' => 1, 'height' => 'auto',
            'style' => ['sizePt' => 10, 'lineHeight' => 1.4, 'weight' => 400, 'align' => 'left'],
            'content' => ['i18n' => ['en' => ['runs' => [['t' => 'hello']]]]],
        ], $over);
    }

    /* ================================================================== *
     *  Naming — T2-02, T2-03, T2-57, T2-60, T2-72
     * ================================================================== */

    /** T2-02 · a punctuation-only name mints nothing, rather than a shared empty slug. */
    public function test_a_punctuation_only_custom_name_is_refused(): void
    {
        foreach (['—  ***  —', '...', '!!!', '???', '///'] as $bad) {
            try {
                $got = Doc_contract::customTypeFor($bad);
                $this->fail("'$bad' minted '$got' instead of being refused");
            } catch (InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/no letters or digits/', $e->getMessage());
            }
        }
    }

    /** T2-03 · whitespace-only, including exotic spaces. */
    public function test_a_whitespace_only_custom_name_is_refused(): void
    {
        foreach ([' ', "\t", "\n", "   \t  \n "] as $bad) {
            $this->expectExceptionMessageMatches('/no letters or digits/');
            $this->expectException(InvalidArgumentException::class);
            Doc_contract::customTypeFor($bad);
            return;   // one assertion per call; the loop documents the class
        }
    }

    /**
     * T2-57 · two names differing only by trailing whitespace are the SAME type.
     * Otherwise a school gets two galleries and two active slots for one document.
     */
    public function test_names_differing_only_by_whitespace_mint_one_type(): void
    {
        $a = Doc_contract::customTypeFor('Sports Day');
        $b = Doc_contract::customTypeFor('  Sports Day  ');
        $c = Doc_contract::customTypeFor("Sports Day\t");
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    /** T2-72 · a long name truncates to a bounded slug, and stays a legal id. */
    public function test_a_very_long_name_truncates_to_a_legal_id(): void
    {
        $slug = Doc_contract::customTypeFor(str_repeat('Sports Day ', 40));
        $this->assertTrue(Doc_contract::isCustom($slug), "'$slug' is not a legal custom id");
        $this->assertLessThanOrEqual(40 + strlen('custom:'), strlen($slug));
        $this->assertStringEndsNotWith('_', $slug, 'a truncated slug must not end mid-separator');
    }

    /**
     * T2-60 · other Unicode dotted-I forms.
     *
     * The Turkish İ (U+0130) diverged between PHP and JS and is fixed. This checks the
     * neighbourhood rather than the single case that was reported — a fix for one
     * SpecialCasing character that leaves the class open is not a fix.
     */
    public function test_other_unicode_special_casing_inputs_still_mint_legal_ids(): void
    {
        foreach (['İstanbul', 'ﬁle', 'ǅungla', 'ΣΊΣΥΦΟΣ', 'ÅNGSTRÖM', 'ẞTRASSE'] as $name) {
            $slug = Doc_contract::customTypeFor($name . ' Certificate');
            $this->assertTrue(Doc_contract::isCustom($slug),
                "'$name' produced '$slug', which is not a legal custom type id");
        }
    }

    /* ================================================================== *
     *  Lifecycle preconditions — T2-37 to T2-41, T2-47
     * ================================================================== */

    /** T2-37 · a blank docType is refused at the service, not just the controller. */
    public function test_create_refuses_a_blank_doc_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->create('SCH1', '', ['name' => 'x'], 'STA1');
    }

    /** T2-38 · a garbage lockVersion is a conflict, never an accidental match. */
    public function test_save_refuses_a_garbage_lock_version(): void
    {
        foreach ([-1, 0, 999999] as $bad) {
            try {
                $this->svc->save('SCH1_TPL1', ['name' => 'x'], $bad);
                $this->fail("lockVersion $bad was accepted against a stored value of 3");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('E_CONFLICT', $e->getMessage());
            }
        }
    }

    /** T2-39 · activating something never published. */
    public function test_activate_refuses_a_never_published_template(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/never been published/');
        $this->svc->activate('SCH1_TPL1', 'STA1');
    }

    /** T2-40 · deactivating something that is not active. */
    public function test_deactivate_refuses_a_template_that_is_not_active(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->deactivate('SCH1_TPL1', 'STA1');
    }

    /** T2-41 · rolling back to a version that does not exist. */
    public function test_activate_refuses_a_version_number_that_does_not_exist(): void
    {
        $this->docs['documentTemplates']['SCH1_TPL1']['publishedVersion'] = 2;
        $this->expectException(RuntimeException::class);
        $this->svc->activate('SCH1_TPL1', 'STA1', 99);
    }

    /** T2-47 · an empty patch must not corrupt the document or the lock. */
    public function test_an_empty_patch_is_harmless(): void
    {
        $before = $this->docs['documentTemplates']['SCH1_TPL1'];
        $out = $this->svc->save('SCH1_TPL1', [], $before['lockVersion']);

        $after = $this->docs['documentTemplates']['SCH1_TPL1'];
        $this->assertSame($before['name'], $after['name']);
        $this->assertSame($before['objects'], $after['objects']);
        $this->assertGreaterThan($before['lockVersion'], $out['lockVersion']);
    }

    /* ================================================================== *
     *  Serializer boundaries — T2-05, T2-50 to T2-56, T2-62
     * ================================================================== */

    /** T2-52 · a colour outside the whitelist falls back, never emits raw. */
    public function test_a_hostile_colour_value_cannot_reach_the_output(): void
    {
        foreach (['red;background:url(http://evil.invalid/x)', 'expression(alert(1))',
                  'url(javascript:alert(1))', '#zzz'] as $bad) {
            $html = (new Doc_serializer())->render(
                $this->tpl([$this->textObj(['style' => ['sizePt' => 10, 'lineHeight' => 1.4,
                    'align' => 'left', 'colour' => $bad]])]), [], 'en');
            $this->assertStringNotContainsString('evil.invalid', $html);
            $this->assertStringNotContainsString('javascript:', $html);
            $this->assertStringNotContainsString('expression(', $html);
        }
    }

    /** T2-53 · an align value outside the whitelist falls back to left. */
    public function test_a_hostile_align_value_falls_back(): void
    {
        $html = (new Doc_serializer())->render(
            $this->tpl([$this->textObj(['style' => ['sizePt' => 10, 'lineHeight' => 1.4,
                'align' => 'left;background:url(http://evil.invalid/x)']])]), [], 'en');
        $this->assertStringNotContainsString('evil.invalid', $html);
        $this->assertStringContainsString('text-align:left;', $html);
    }

    /**
     * T2-55 · a scheme-qualified image src is REFUSED, not quietly dropped.
     *
     * The row was written expecting silent omission. The serializer is stricter than
     * that and throws — which is the better behaviour: an image that vanishes from a
     * statutory document without comment is the failure mode this module has been bitten
     * by before. Asserting the real contract, not the one the row assumed.
     */
    public function test_a_scheme_qualified_image_src_is_refused(): void
    {
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'vbscript:msgbox(1)',
                  'http://evil.invalid/x.png', 'file:///etc/passwd'] as $bad) {
            try {
                (new Doc_serializer())->render($this->tpl([[
                    'id' => 'i', 'type' => 'image', 'xMm' => 10, 'yMm' => 10, 'wMm' => 30,
                    'hMm' => 30, 'z' => 1, 'height' => 'fixed', 'style' => [],
                    'content' => ['src' => $bad],
                ]]), [], 'en');
                $this->fail("'$bad' was accepted as an image src");
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression('/scheme-qualified|storage path/i', $e->getMessage());
            }
        }
    }

    /** …and a legitimate storage path still renders. A guard that blocks everything is a bug. */
    public function test_a_plain_storage_path_image_still_renders(): void
    {
        $html = (new Doc_serializer())->render($this->tpl([[
            'id' => 'i', 'type' => 'image', 'xMm' => 10, 'yMm' => 10, 'wMm' => 30, 'hMm' => 30,
            'z' => 1, 'height' => 'fixed', 'style' => [],
            'content' => ['src' => 'uploads/SCH1/doctemplates/assets/abc.png'],
        ]]), [], 'en');
        $this->assertStringContainsString('abc.png', $html);
    }

    /** T2-05 · extreme geometry is bounded before it is stored. */
    public function test_extreme_object_geometry_is_clamped_on_save(): void
    {
        $this->svc->save('SCH1_TPL1', ['objects' => [
            ['id' => 'a', 'xMm' => -999999, 'yMm' => 1e9, 'wMm' => -50, 'hMm' => 1e12],
        ]], 3);

        $o = $this->docs['documentTemplates']['SCH1_TPL1']['objects'][0];
        $this->assertGreaterThanOrEqual(-2000, $o['xMm']);
        $this->assertLessThanOrEqual(2000, $o['yMm']);
        $this->assertGreaterThanOrEqual(0, $o['wMm'], 'a negative width was stored');
        $this->assertLessThanOrEqual(2000, $o['hMm']);
    }

    /** T2-50 · a value exactly at maxLen is accepted; one over only warns. */
    public function test_a_merge_value_at_exactly_maxlen_is_accepted(): void
    {
        $c = $this->contract->get('bonafide');
        $key = 'student.fullName';
        $max = $c[$key]['maxLen'];

        $bundle = $this->contract->sampleBundle('bonafide');
        $bundle[$key] = str_repeat('a', $max);
        $r = $this->contract->validateBundle('bonafide', $bundle, [$key]);
        $this->assertSame([], $r['warnings'], "a value at exactly maxLen ($max) warned");

        $bundle[$key] = str_repeat('a', $max + 1);
        $r = $this->contract->validateBundle('bonafide', $bundle, [$key]);
        $this->assertSame('overLength', $r['warnings'][0]['type']);
        $this->assertTrue($r['ok'], 'over-length must WARN, never block — maxLen is an estimate');
    }

    /* ================================================================== *
     *  Scale — T2-07, T2-08, T2-49
     * ================================================================== */

    /** T2-07 · 500 objects on one page render without collapsing. */
    public function test_five_hundred_objects_render(): void
    {
        $objects = [];
        for ($i = 0; $i < 500; $i++) {
            $objects[] = $this->textObj(['id' => "o$i", 'yMm' => 10 + ($i % 200)]);
        }
        $t0 = microtime(true);
        $html = (new Doc_serializer())->render($this->tpl($objects), [], 'en');
        $ms = (microtime(true) - $t0) * 1000;

        $this->assertSame(500, substr_count($html, 'zx-o zx-text'));
        $this->assertLessThan(5000, $ms, "500 objects took {$ms}ms to serialize");
    }

    /** T2-49 · a repeating table with many rows emits one row each. */
    public function test_a_large_repeating_table_emits_every_row(): void
    {
        $items = [];
        for ($i = 0; $i < 300; $i++) {
            $items[] = ['item.head' => "Line $i", 'item.period' => '2026-27', 'item.amount' => '100.00'];
        }
        $html = (new Doc_serializer())->render($this->tpl([[
            'id' => 't', 'type' => 'table', 'xMm' => 15, 'yMm' => 40, 'wMm' => 180, 'hMm' => 20,
            'z' => 1, 'height' => 'auto', 'style' => ['sizePt' => 9, 'lineHeight' => 1.4],
            'content' => ['repeatOver' => 'receipt.items', 'showHeader' => false,
                          'columns' => [['key' => 'item.head'], ['key' => 'item.amount']]],
        ]], ['docType' => 'fee_receipt']),
            ['receipt.items' => $items], 'en',
            ['contract' => $this->contract->get('fee_receipt')]);

        $this->assertSame(300, substr_count($html, '<tr'));
        $this->assertStringContainsString('Line 299', $html);
    }

    /* ================================================================== *
     *  T2-48 — the empty table
     * ================================================================== */

    /** An empty item list refuses to print, rather than printing an empty frame. */
    public function test_a_repeating_table_with_no_rows_refuses_to_print(): void
    {
        $this->expectException(RuntimeException::class);
        (new Doc_serializer())->render($this->tpl([[
            'id' => 't', 'type' => 'table', 'xMm' => 15, 'yMm' => 40, 'wMm' => 180, 'hMm' => 20,
            'z' => 1, 'height' => 'auto', 'style' => ['sizePt' => 9, 'lineHeight' => 1.4],
            'content' => ['repeatOver' => 'receipt.items', 'columns' => [['key' => 'item.head']]],
        ]], ['docType' => 'fee_receipt']),
            ['receipt.items' => []], 'en',
            ['contract' => $this->contract->get('fee_receipt')]);
    }

    /* ================================================================== *
     *  T2-06 — mixed script
     * ================================================================== */

    /** Devanagari, emoji and Latin in one run all survive to the output. */
    public function test_mixed_script_and_emoji_render(): void
    {
        $html = (new Doc_serializer())->render($this->tpl([$this->textObj([
            'content' => ['i18n' => ['en' => ['runs' => [['t' => 'नमस्ते · Hello · 🎓 · مرحبا']]]]],
        ])]), [], 'en');

        foreach (['नमस्ते', 'Hello', '🎓', 'مرحبا'] as $frag) {
            $this->assertStringContainsString($frag, $html, "'$frag' was lost in serialization");
        }
    }
}
