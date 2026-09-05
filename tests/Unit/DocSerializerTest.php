<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_serializer;
use RuntimeException;

/**
 * Doc_serializer — Phase 2 emission rules.
 *
 * The serializer feeds BOTH the browser preview and mPDF from one output. So
 * these tests are not about "does it produce HTML" — they pin the specific
 * emission rules whose violation is invisible until a real certificate prints
 * wrong:
 *
 *   • an unresolved merge field must THROW, never print blank or a literal
 *     {key} — the legacy Certificates.php substitutes '' and this reverses it;
 *   • every text object must carry an explicit line-height, because without it
 *     mPDF and Chrome disagree by up to 2x (Tamil: 18.03mm vs 9.53mm) and the
 *     error compounds down an anchor chain (G0.5, blocking);
 *   • an anchor chain must emit as ONE absolute container with block children,
 *     because emitting members absolutely freezes design-time gaps;
 *   • no flex, no grid, ever — mPDF supports neither, so a template using them
 *     previews fine and prints as a broken stack.
 *
 * EXECUTION_PLAN_v1.1 P2.1–P2.8
 */
class DocSerializerTest extends TestCase
{
    private Doc_serializer $s;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_serializer.php';
    }

    protected function setUp(): void
    {
        $this->s = new Doc_serializer();
    }

    /* ---------------------------------------------------------------- *
     * Fixtures
     * ---------------------------------------------------------------- */

    private function contract(): array
    {
        return [
            'school.name'         => ['label' => 'School name', 'sample' => 'DPS Ranchi',
                                      'p95' => 'Shri Guru Harkrishan Public Senior Secondary School, Ranchi'],
            'student.fullName'    => ['label' => 'Student name', 'sample' => 'Aarav Sharma'],
            'tc.reasonForLeaving' => ['label' => 'Reason', 'sample' => 'Parent transferred'],
            'doc.issueDate'       => ['label' => 'Issue date', 'sample' => '04/04/2026'],
        ];
    }

    private function tpl(array $objects, array $over = []): array
    {
        return array_merge([
            'templateId'  => 'TPL0007',
            'docType'     => 'transfer_certificate',
            'languages'   => ['en'],
            'page'        => ['size' => 'A4', 'orientation' => 'portrait',
                              'marginsMm' => ['t' => 15, 'r' => 15, 'b' => 15, 'l' => 15]],
            'objects'     => $objects,
        ], $over);
    }

    private function text(string $id, array $runs, array $over = []): array
    {
        return array_merge([
            'id' => $id, 'type' => 'text', 'xMm' => 15, 'yMm' => 40, 'wMm' => 180, 'hMm' => 8,
            'z' => 1, 'height' => 'auto',
            'style' => ['sizePt' => 10, 'lineHeight' => 1.4, 'weight' => 400, 'align' => 'left'],
            'content' => ['i18n' => ['en' => ['runs' => $runs]]],
        ], $over);
    }

    private function render(array $tpl, array $data = [], array $opts = []): string
    {
        return $this->s->render($tpl, $data, 'en', array_merge(['contract' => $this->contract()], $opts));
    }

    /* ---------------------------------------------------------------- *
     * P2.1 — absolute object emission in mm
     * ---------------------------------------------------------------- */

    public function test_an_unanchored_object_is_absolutely_positioned_in_mm(): void
    {
        $html = $this->render($this->tpl([
            $this->text('a', [['t' => 'Hello']], ['xMm' => 20, 'yMm' => 45, 'wMm' => 170]),
        ]));

        $this->assertStringContainsString('position:absolute', $html);
        $this->assertStringContainsString('left:20mm', $html);
        $this->assertStringContainsString('top:45mm', $html);
        $this->assertStringContainsString('width:170mm', $html);
    }

    /** height:auto must OMIT height entirely; fixed must state it. */
    public function test_auto_height_omits_height_and_fixed_states_it(): void
    {
        $auto = $this->render($this->tpl([$this->text('a', [['t' => 'x']], ['height' => 'auto', 'hMm' => 9])]));
        $this->assertStringNotContainsString('height:9mm', $auto);

        $fixed = $this->render($this->tpl([$this->text('a', [['t' => 'x']], ['height' => 'fixed', 'hMm' => 9])]));
        $this->assertStringContainsString('height:9mm', $fixed);
    }

    /** §3.5 — auto-grow without a ceiling pushes the signature block off the page. */
    public function test_maxhmm_emits_a_ceiling(): void
    {
        $html = $this->render($this->tpl([$this->text('a', [['t' => 'x']], ['maxHMm' => 34])]));
        $this->assertStringContainsString('max-height:34mm', $html);
    }

    /* ---------------------------------------------------------------- *
     * P2.2 — anchor chains
     * ---------------------------------------------------------------- */

    /**
     * The chain is ONE absolute container. Members carry only their gap as
     * margin-top — emitting them absolutely would freeze the design-time gaps
     * and break the instant the text above grows.
     */
    public function test_an_anchor_chain_emits_one_container_with_block_children(): void
    {
        $html = $this->render($this->tpl([
            $this->text('a', [['t' => 'first']], ['xMm' => 15, 'yMm' => 40]),
            $this->text('b', [['t' => 'second']], ['anchorTo' => 'a', 'anchorGapMm' => 4]),
            $this->text('c', [['t' => 'third']],  ['anchorTo' => 'b', 'anchorGapMm' => 6]),
        ]));

        $this->assertSame(1, substr_count($html, 'position:absolute'),
            'a three-deep chain must produce exactly ONE absolutely-positioned box');
        $this->assertStringContainsString('margin-top:4mm', $html);
        $this->assertStringContainsString('margin-top:6mm', $html);
    }

    public function test_a_dangling_anchor_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/anchors to missing object/');
        $this->render($this->tpl([$this->text('b', [['t' => 'x']], ['anchorTo' => 'ghost'])]));
    }

    public function test_an_anchor_cycle_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/anchor cycle/');
        $this->render($this->tpl([
            $this->text('a', [['t' => 'x']], ['anchorTo' => 'b']),
            $this->text('b', [['t' => 'y']], ['anchorTo' => 'a']),
        ]));
    }

    /* ---------------------------------------------------------------- *
     * P2.3 — flow region
     * ---------------------------------------------------------------- */

    /** The flow region paginates, so it must NOT be absolutely positioned. */
    public function test_the_flow_region_is_emitted_in_normal_flow(): void
    {
        $html = $this->render($this->tpl([
            $this->text('f', [['t' => 'long body']], ['flowRegion' => true]),
        ]));
        $this->assertStringNotContainsString('position:absolute', $html);
        $this->assertStringContainsString('width:180mm', $html);
    }

    /* ---------------------------------------------------------------- *
     * P2.4 — pageNumber goes to the footer band
     * ---------------------------------------------------------------- */

    public function test_pagenumber_emits_the_mpdf_token_not_a_literal(): void
    {
        $html = $this->render($this->tpl([
            ['id' => 'pn', 'type' => 'pageNumber', 'region' => 'footer',
             'xMm' => 100, 'yMm' => 280, 'wMm' => 20, 'z' => 1, 'height' => 'auto'],
        ]));
        $this->assertStringContainsString('{PAGENO}', $html);
        $this->assertStringContainsString('zx-footer', $html);
    }

    /* ---------------------------------------------------------------- *
     * P2.5 — namespacing, and the no-flex/no-grid ban
     * ---------------------------------------------------------------- */

    public function test_every_selector_is_namespaced_under_the_template_root(): void
    {
        $html = $this->render($this->tpl([$this->text('a', [['t' => 'x']])]));
        preg_match('/<style>(.*?)<\/style>/s', $html, $m);
        $css = $m[1] ?? '';

        $this->assertNotSame('', $css);

        /* @font-face is an AT-RULE, not a selector. It declares a font family
           by name and matches no elements, so it cannot leak style into a host
           page or inherit from one — the two failures this test exists to
           catch. It is skipped explicitly rather than by loosening the
           assertion, so a genuinely bare selector still fails. */
        $checked = 0;
        foreach (array_filter(explode('}', $css)) as $rule) {
            if (trim($rule) === '') continue;
            $sel = trim(explode('{', $rule)[0]);
            if ($sel === '' || str_starts_with($sel, '@') || str_starts_with($sel, 'src:')) {
                continue;                        // at-rule, or its descriptors
            }
            $checked++;
            $this->assertStringStartsWith('.zx-tpl-TPL0007', $sel,
                "bare or foreign selector leaked: '$sel'");
        }
        $this->assertGreaterThan(0, $checked,
            'no selectors were checked — the parser skipped everything, which would '
            . 'make this test pass vacuously');
    }

    /** mPDF supports neither. A template using them previews fine and prints broken. */
    public function test_no_flex_and_no_grid_is_ever_emitted(): void
    {
        $html = $this->render($this->tpl([
            $this->text('a', [['t' => 'x']]),
            ['id' => 't', 'type' => 'table', 'xMm' => 15, 'yMm' => 60, 'wMm' => 180, 'z' => 1,
             'height' => 'auto', 'style' => ['lineHeight' => 1.5],
             'content' => ['rows' => [[['wPct' => 40, 'i18n' => ['en' => ['runs' => [['t' => 'Name']]]]],
                                       ['wPct' => 60, 'i18n' => ['en' => ['runs' => [['f' => 'student.fullName']]]]]]]]],
        ]), [], ['sample' => 'typical']);

        $this->assertStringNotContainsString('display:flex', $html);
        $this->assertStringNotContainsString('display:grid', $html);
        $this->assertStringNotContainsString('display: flex', $html);
        $this->assertStringNotContainsString('display: grid', $html);
    }

    /* ---------------------------------------------------------------- *
     * P2.6 — merge resolution, FAIL CLOSED
     * ---------------------------------------------------------------- */

    public function test_a_field_resolves_from_real_data(): void
    {
        $html = $this->render(
            $this->tpl([$this->text('a', [['t' => 'Name: '], ['f' => 'student.fullName']])]),
            ['student.fullName' => 'Aarav Sharma']
        );
        $this->assertStringContainsString('Name: Aarav Sharma', $html);
    }

    /** THE central rule. A blank on a statutory field is a defective record. */
    public function test_an_unresolved_field_throws_rather_than_printing_blank(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no value resolved/i');
        $this->render($this->tpl([$this->text('a', [['f' => 'student.fullName']])]), []);
    }

    public function test_an_empty_string_is_treated_as_unresolved(): void
    {
        $this->expectException(RuntimeException::class);
        $this->render($this->tpl([$this->text('a', [['f' => 'student.fullName']])]),
                      ['student.fullName' => '']);
    }

    /** A literal {key} reaching print is a forgery vector, not a cosmetic bug. */
    public function test_a_literal_placeholder_can_never_reach_the_output(): void
    {
        $html = $this->render(
            $this->tpl([$this->text('a', [['f' => 'student.fullName']])]),
            ['student.fullName' => 'Aarav Sharma']
        );
        $this->assertStringNotContainsString('{student.fullName}', $html);
        $this->assertStringNotContainsString('data-key', $html, 'a design-mode chip must never print');
    }

    public function test_an_off_contract_key_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/contract does not declare/');
        $this->render($this->tpl([$this->text('a', [['f' => 'attendance.workingDays']])]),
                      ['attendance.workingDays' => '221']);
    }

    /* ---------------------------------------------------------------- *
     * Sample + p95 modes
     * ---------------------------------------------------------------- */

    /**
     * p95 must actually lengthen the worst-case field. A stress mode that
     * silently renders the short sample reports green and proves nothing.
     */
    public function test_p95_mode_renders_the_worst_case_value(): void
    {
        $tpl     = $this->tpl([$this->text('a', [['f' => 'school.name']])]);
        $typical = $this->render($tpl, [], ['sample' => 'typical']);
        $stress  = $this->render($tpl, [], ['sample' => 'p95']);

        $this->assertStringContainsString('DPS Ranchi', $typical);
        $this->assertStringContainsString('Shri Guru Harkrishan', $stress);
        $this->assertGreaterThan(strlen($typical), strlen($stress));
    }

    /* ---------------------------------------------------------------- *
     * Rule 6a — mandatory line-height (G0.5, blocking)
     * ---------------------------------------------------------------- */

    public function test_a_text_object_without_line_height_is_rejected(): void
    {
        $o = $this->text('a', [['t' => 'x']]);
        unset($o['style']['lineHeight']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/lineHeight/');
        $this->render($this->tpl([$o]));
    }

    public function test_line_height_is_emitted_verbatim(): void
    {
        $html = $this->render($this->tpl([
            $this->text('a', [['t' => 'x']], ['style' => ['sizePt' => 9.5, 'lineHeight' => 1.45]]),
        ]));
        $this->assertStringContainsString('line-height:1.45', $html);
        $this->assertStringContainsString('font-size:9.5pt', $html);
    }

    /** Non-text objects carry no text, so the rule must not fire on them. */
    public function test_shapes_and_images_need_no_line_height(): void
    {
        $html = $this->render($this->tpl([
            ['id' => 'r', 'type' => 'shape', 'xMm' => 15, 'yMm' => 33, 'wMm' => 180, 'hMm' => 0.6,
             'z' => 1, 'height' => 'fixed', 'content' => ['shape' => 'line'], 'style' => ['colour' => '#14100D']],
        ]));
        $this->assertStringContainsString('zx-shape', $html);
    }

    /* ---------------------------------------------------------------- *
     * Escaping, language, and showWhen
     * ---------------------------------------------------------------- */

    public function test_text_and_resolved_values_are_escaped(): void
    {
        $html = $this->render(
            $this->tpl([$this->text('a', [['t' => '<b>x</b>'], ['f' => 'student.fullName']])]),
            ['student.fullName' => '<script>alert(1)</script>']
        );
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    /** Matches the client's runsHTML(): \n => <br>, then u/i/b nesting. */
    public function test_newlines_become_br_and_formatting_nests_like_the_client(): void
    {
        $html = $this->render($this->tpl([
            $this->text('a', [['t' => "one\ntwo", 'b' => true, 'i' => true]]),
        ]));
        $this->assertStringContainsString('<b><i>one<br>two</i></b>', $html);
    }

    public function test_rendering_an_undeclared_language_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/asked for/');
        $this->s->render($this->tpl([$this->text('a', [['t' => 'x']])]), [], 'ta',
                         ['contract' => $this->contract()]);
    }

    public function test_a_missing_translation_for_a_declared_language_throws(): void
    {
        $tpl = $this->tpl([$this->text('a', [['t' => 'x']])], ['languages' => ['en', 'hi']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no .hi. content/');
        $this->s->render($tpl, [], 'hi', ['contract' => $this->contract()]);
    }

    /**
     * KER r.22 / TNER r.44 / CBSE r.8(vi) prescribe a duplicate mark on
     * reissue. It is the one place a statute specifies RENDERING.
     */
    public function test_showwhen_gates_the_duplicate_mark(): void
    {
        $tpl = $this->tpl([
            $this->text('dup', [['t' => 'DUPLICATE']], ['showWhen' => 'doc.isDuplicate']),
        ]);
        $this->assertStringNotContainsString('DUPLICATE', $this->render($tpl));
        $this->assertStringContainsString('DUPLICATE', $this->render($tpl, [], ['isDuplicate' => true]));
    }

    /* ---------------------------------------------------------------- *
     * Page geometry
     * ---------------------------------------------------------------- */

    public function test_page_size_comes_from_the_template_not_a_hardcoded_a4(): void
    {
        $a5 = $this->render($this->tpl([$this->text('a', [['t' => 'x']])],
                            ['page' => ['size' => 'A5', 'orientation' => 'portrait']]));
        $this->assertStringContainsString('width:148mm', $a5);

        $land = $this->render($this->tpl([$this->text('a', [['t' => 'x']])],
                              ['page' => ['size' => 'A4', 'orientation' => 'landscape']]));
        $this->assertStringContainsString('width:297mm', $land);
    }

    /* ---------------------------------------------------------------- *
     * P2.7 — the two-tier overflow gate
     *
     * A fake renderer is injected so both tiers are exercised without mPDF.
     * That is the point of the injection: the gate's LOGIC is what these pin —
     * that tier 2 fires for absolute chains and tier 1 only for the flow
     * region — while the measurement itself belongs to Doc_renderer and is
     * covered by DocRendererPageGeometryTest.
     * ---------------------------------------------------------------- */

    public function test_tier2_blocks_an_absolute_chain_that_runs_past_the_page(): void
    {
        $r = new class {
            public function wouldOverflow($h, $p, $top, $w): bool { return $top > 250; }
            public function pageCount($h, $p): int { return 1; }
        };

        $tpl = $this->tpl([
            $this->text('ok',  [['t' => 'near the top']], ['yMm' => 40]),
            $this->text('bad', [['t' => 'far down']],     ['yMm' => 280]),
        ]);

        $f = $this->s->overflowFindings($tpl, [], 'en', $r, ['contract' => $this->contract()]);

        $this->assertCount(1, $f);
        $this->assertSame(2, $f[0]['tier']);
        $this->assertSame('bad', $f[0]['object']);
        $this->assertSame('E_PAGE_OVERFLOW', $f[0]['type']);
    }

    /**
     * G0.4: $mpdf->page NEVER fires for absolute content, so tier 1 must not be
     * asked about it. A tier-1-only gate is blind to almost every certificate
     * object, which is exactly how a signature block goes missing silently.
     */
    public function test_tier1_is_not_consulted_for_absolute_chains(): void
    {
        $r = new class {
            public int $pageCountCalls = 0;
            public function wouldOverflow($h, $p, $top, $w): bool { return false; }
            public function pageCount($h, $p): int { $this->pageCountCalls++; return 9; }
        };

        $tpl = $this->tpl([$this->text('a', [['t' => 'x']])], ['pageMode' => 'single']);
        $f   = $this->s->overflowFindings($tpl, [], 'en', $r, ['contract' => $this->contract()]);

        $this->assertSame([], $f);
        $this->assertSame(0, $r->pageCountCalls,
            'an absolute chain must never be judged by page count');
    }

    public function test_tier1_blocks_a_single_page_template_whose_flow_region_spills(): void
    {
        $r = new class {
            public function wouldOverflow($h, $p, $top, $w): bool { return false; }
            public function pageCount($h, $p): int { return 2; }
        };

        $tpl = $this->tpl([$this->text('body', [['t' => 'long']], ['flowRegion' => true])],
                          ['pageMode' => 'single']);
        $f   = $this->s->overflowFindings($tpl, [], 'en', $r, ['contract' => $this->contract()]);

        $this->assertCount(1, $f);
        $this->assertSame(1, $f[0]['tier']);
    }

    /** A template that does not pin itself to one page is allowed to flow. */
    public function test_a_multipage_template_may_flow_onto_a_second_page(): void
    {
        $r = new class {
            public function wouldOverflow($h, $p, $top, $w): bool { return false; }
            public function pageCount($h, $p): int { return 3; }
        };

        $tpl = $this->tpl([$this->text('body', [['t' => 'long']], ['flowRegion' => true])]);
        $this->assertSame([], $this->s->overflowFindings($tpl, [], 'en', $r, ['contract' => $this->contract()]));
    }

    public function test_assertfits_throws_with_the_planned_error_code(): void
    {
        $r = new class {
            public function wouldOverflow($h, $p, $top, $w): bool { return true; }
            public function pageCount($h, $p): int { return 1; }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/E_PAGE_OVERFLOW/');
        $this->s->assertFits($this->tpl([$this->text('a', [['t' => 'x']])]), [], 'en', $r,
                             ['contract' => $this->contract()]);
    }

    /**
     * A renderer that cannot answer tier 1 must FAIL LOUDLY. The first draft
     * used method_exists() and skipped silently — Doc_renderer had no
     * pageCount() at the time, so tier 1 was dead code in production while the
     * gate still cheerfully reported "no findings".
     */
    public function test_a_renderer_that_cannot_answer_tier1_fails_loudly(): void
    {
        $blind = new class {
            public function wouldOverflow($h, $p, $top, $w): bool { return false; }
        };

        $tpl = $this->tpl([$this->text('body', [['t' => 'x']], ['flowRegion' => true])],
                          ['pageMode' => 'single']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pageCount/');
        $this->s->overflowFindings($tpl, [], 'en', $blind, ['contract' => $this->contract()]);
    }

    /** The real renderer must satisfy the contract the gate depends on. */
    public function test_doc_renderer_exposes_both_gate_primitives(): void
    {
        require_once __DIR__ . '/../../application/libraries/Doc_renderer.php';
        foreach (['pageCount', 'wouldOverflow', 'measureBlock'] as $m) {
            $this->assertTrue(method_exists(\Doc_renderer::class, $m),
                "Doc_renderer::$m() is missing — the overflow gate silently loses a tier without it");
        }
    }

    /* ---------------------------------------------------------------- *
     * P7.2 — @font-face parity, P7.5 — languageFallback
     * ---------------------------------------------------------------- */

    /**
     * Without @font-face the browser has no `lohitdeva`, so the preview reflows
     * in a system font while mPDF sets in Lohit. The preview would be lying
     * about what prints — the exact divergence "one serializer, two sinks"
     * exists to prevent.
     */
    public function test_font_faces_are_declared_for_the_browser(): void
    {
        $html = $this->render($this->tpl([$this->text('a', [['t' => 'x']])]));

        $this->assertStringContainsString('@font-face', $html);
        $this->assertStringContainsString("font-family:'lohitdeva'", $html);
        $this->assertStringContainsString('/assets/fonts/lohit/Lohit-Devanagari.ttf', $html);
    }

    /**
     * `block`, not `swap`. Swap paints a fallback face first and reflows when
     * the real one arrives — on a certificate that means the designer briefly
     * sees a layout that will never be printed.
     */
    public function test_font_display_is_block_so_no_fallback_is_ever_painted(): void
    {
        $html = $this->render($this->tpl([$this->text('a', [['t' => 'x']])]));

        $this->assertStringContainsString('font-display:block', $html);
        $this->assertStringNotContainsString('font-display:swap', $html);
    }

    public function test_the_font_base_path_is_overridable_for_a_subpath_deploy(): void
    {
        $this->s->setFontBase('https://cdn.example.com/f/');
        $html = $this->render($this->tpl([$this->text('a', [['t' => 'x']])]));
        $this->assertStringContainsString("https://cdn.example.com/f/Lohit-Tamil.ttf", $html);
    }

    /**
     * P7.5 — the default is `block`, deliberately. Falling back silently prints
     * a Hindi certificate with English sentences in it and tells nobody, while
     * the document still carries the school's seal.
     */
    public function test_a_missing_translation_blocks_by_default(): void
    {
        $o = $this->text('a', [['t' => 'english only']]);
        $tpl = $this->tpl([$o], ['languages' => ['en', 'hi'], 'defaultLanguage' => 'en']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/languageFallback is 'block'/");
        $this->s->render($tpl, [], 'hi', ['contract' => $this->contract()]);
    }

    /** `default` is opt-IN, for non-statutory documents. */
    public function test_language_fallback_default_falls_back_to_the_default_language(): void
    {
        $o = $this->text('a', [['t' => 'english only']]);
        $tpl = $this->tpl([$o], ['languages' => ['en', 'hi'], 'defaultLanguage' => 'en',
                                 'languageFallback' => 'default']);

        $html = $this->s->render($tpl, [], 'hi', ['contract' => $this->contract()]);
        $this->assertStringContainsString('english only', $html);
    }

    /** Even under `default`, a gap with no fallback content still throws. */
    public function test_fallback_default_still_throws_when_the_default_language_is_also_missing(): void
    {
        $o = $this->text('a', [['t' => 'x']]);
        unset($o['content']['i18n']['en']);
        $o['content']['i18n']['ta'] = ['runs' => [['t' => 'tamil']]];
        $tpl = $this->tpl([$o], ['languages' => ['en', 'hi'], 'defaultLanguage' => 'en',
                                 'languageFallback' => 'default']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/no 'en' fallback either/");
        $this->s->render($tpl, [], 'hi', ['contract' => $this->contract()]);
    }

    /* ================================================================== *
     *  showWhen on a CONTRACT FIELD  (found live, 2026-09-03)
     *
     *  The designer offers this on every contract field — the inspector reads
     *  "Only when '<label>' has a value" — and the shipped Annexure-I starter
     *  uses it for the "Checked by" signature. The serializer only understood
     *  doc.isDuplicate and threw on everything else, so the DEFAULT Transfer
     *  Certificate starter could not be proofed at all.
     * ================================================================== */

    public function test_an_object_gated_on_a_field_appears_when_that_field_resolves(): void
    {
        $html = $this->s->render($this->tplWithShowWhen('tc.duesPaidUpto'), [], 'en', [
            'contract' => $this->gateContract(), 'sample' => 'typical',
        ]);
        $this->assertSame(1, substr_count($html, 'zx-o zx-text'),
            'the gated object must be rendered when its field has a value');
    }

    public function test_an_object_gated_on_an_empty_field_is_omitted(): void
    {
        $contract = $this->gateContract();
        $contract['tc.duesPaidUpto']['sample'] = '';      // nothing recorded

        $html = $this->s->render($this->tplWithShowWhen('tc.duesPaidUpto'), [], 'en', [
            'contract' => $contract, 'sample' => 'typical',
        ]);
        $this->assertSame(0, substr_count($html, 'zx-o zx-text'),
            'the gated object must be omitted when its field resolves to nothing');
    }

    /**
     * Still fail closed on a condition nobody declared. An object that silently
     * defaulted to visible could put a signature block on a certificate that
     * was never meant to carry one.
     */
    public function test_a_showWhen_naming_nothing_the_contract_declares_still_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown showWhen/');
        $this->s->render($this->tplWithShowWhen('tc.notAThing'), [], 'en', [
            'contract' => $this->gateContract(), 'sample' => 'typical',
        ]);
    }

    private function tplWithShowWhen(string $when): array
    {
        return [
            'templateId' => 'TPL1', 'languages' => ['en'], 'defaultLanguage' => 'en',
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'objects' => [[
                'id' => 'gated-object', 'type' => 'text', 'name' => 'Gated',
                'xMm' => 10, 'yMm' => 10, 'wMm' => 100, 'hMm' => 8,
                'showWhen' => $when,
                'style' => ['sizePt' => 9, 'lineHeight' => 1.4],
                'content' => ['i18n' => ['en' => ['runs' => [['text' => 'gated-object']]]]],
            ]],
        ];
    }

    private function gateContract(): array
    {
        return ['tc.duesPaidUpto' => ['label' => 'Fees paid up to',
                                      'sample' => 'March 2026', 'p95' => 'March 2026']];
    }

    /* ================================================================== *
     *  The particulars table  (found live, 2026-09-03)
     *
     *  The designer stores rows as {"key": "..."}; this method only understood
     *  rows that were arrays of cells, so every row rendered as <td></td> and
     *  THE PDF CAME OUT WITHOUT THE PARTICULARS — no admission number, no
     *  student name, no parentage, no dates — while the canvas showed them all.
     *  Nothing failed, because empty cells are valid HTML.
     * ================================================================== */

    public function test_a_keyed_table_row_renders_its_number_label_and_value(): void
    {
        $html = $this->s->render($this->tplWithTable(), [], 'en', [
            'contract' => $this->tableContract(), 'sample' => 'typical',
        ]);

        $this->assertStringContainsString('Admission number', $html, 'the LABEL must print');
        $this->assertStringContainsString('DPSR/2019/0412', $html, 'the VALUE must print');
        $this->assertStringContainsString('Student name', $html);
        $this->assertStringContainsString('Aarav Sharma', $html);
    }

    public function test_keyed_rows_are_numbered_in_order(): void
    {
        $html = $this->s->render($this->tplWithTable(), [], 'en', [
            'contract' => $this->tableContract(), 'sample' => 'typical',
        ]);
        $this->assertMatchesRegularExpression('/>1\.<.*Admission number/s', $html);
        $this->assertMatchesRegularExpression('/>2\.<.*Student name/s', $html);
    }

    /**
     * The regression that let this ship: a table with rows produced HTML with
     * no cell content at all. Any future model mismatch fails here.
     */
    public function test_a_table_never_renders_rows_with_no_content(): void
    {
        $html = $this->s->render($this->tplWithTable(), [], 'en', [
            'contract' => $this->tableContract(), 'sample' => 'typical',
        ]);
        $this->assertSame(0, substr_count($html, '<td></td>'),
            'a table row rendered with no content — this is exactly how the particulars '
            . 'vanished from the PDF while the canvas showed them');
    }

    /** The richer explicit-cell model must keep working. */
    public function test_an_explicit_cell_row_still_renders(): void
    {
        $tpl = $this->tplWithTable();
        $tpl['objects'][0]['content']['rows'] = [[
            ['wPct' => 50, 'i18n' => ['en' => ['runs' => [['t' => 'Left cell']]]]],
            ['wPct' => 50, 'i18n' => ['en' => ['runs' => [['t' => 'Right cell']]]]],
        ]];
        $html = $this->s->render($tpl, [], 'en', [
            'contract' => $this->tableContract(), 'sample' => 'typical',
        ]);
        $this->assertStringContainsString('Left cell', $html);
        $this->assertStringContainsString('Right cell', $html);
    }

    private function tplWithTable(): array
    {
        return [
            'templateId' => 'TPL1', 'languages' => ['en'], 'defaultLanguage' => 'en',
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'objects' => [[
                'id' => 'tbl', 'type' => 'table', 'name' => 'Particulars',
                'xMm' => 15, 'yMm' => 60, 'wMm' => 180, 'hMm' => 80,
                'style' => ['sizePt' => 9, 'lineHeight' => 1.55],
                'content' => ['rows' => [
                    ['key' => 'student.admissionNo'],
                    ['key' => 'student.fullName'],
                ]],
            ]],
        ];
    }

    private function tableContract(): array
    {
        return [
            'student.admissionNo' => ['label' => 'Admission number',
                                      'sample' => 'DPSR/2019/0412', 'p95' => 'DPSR/2019/0412'],
            'student.fullName'    => ['label' => 'Student name',
                                      'sample' => 'Aarav Sharma', 'p95' => 'Aarav Sharma'],
        ];
    }

    /**
     * One serializer, two sinks — and only one of them understands {PAGENO}.
     *
     * mPDF substitutes it per page; the browser prints the characters. So the
     * preview footer read "{PAGENO}" where the PDF shows a number. Found by
     * diffing preview text against text extracted from the proof PDF, which is
     * a check neither one alone can make.
     */
    public function test_the_page_number_is_a_placeholder_for_mpdf_and_a_number_for_the_browser(): void
    {
        $tpl = ['templateId'=>'TPL1','languages'=>['en'],'defaultLanguage'=>'en',
                'page'=>['size'=>'A4','orientation'=>'portrait'],
                'objects'=>[['id'=>'pn','type'=>'pageNumber','name'=>'Page number',
                             'xMm'=>15,'yMm'=>280,'wMm'=>180,'hMm'=>5,
                             'style'=>['sizePt'=>8,'lineHeight'=>1.2],'content'=>[]]]];

        $pdf = $this->s->render($tpl, [], 'en', ['contract'=>[], 'sample'=>'typical', 'forPdf'=>true]);
        $this->assertStringContainsString('{PAGENO}', $pdf, 'mPDF needs the placeholder');

        $web = $this->s->render($tpl, [], 'en', ['contract'=>[], 'sample'=>'typical', 'forPdf'=>false]);
        $this->assertStringNotContainsString('{PAGENO}', $web,
            'the browser prints the characters literally — the preview must not show them');
        $this->assertStringContainsString('1', $web);
    }

    /* ================================================================== *
     *  Repeating tables — what a fee receipt needs and no certificate does
     *
     *  A certificate's body is a fixed set of particulars. A receipt's body is
     *  a LIST whose length is a property of the payment. This is the capability
     *  the type was declared and left unbuildable for.
     * ================================================================== */

    public function test_a_repeating_table_renders_one_row_per_item(): void
    {
        $html = $this->s->render($this->tplRepeat(), [], 'en', [
            'contract' => $this->listContract(), 'sample' => 'typical',
        ]);
        $this->assertSame(2, substr_count($html, '<tr>'), 'two sample items, two rows');
        $this->assertStringContainsString('Tuition fee', $html);
        $this->assertStringContainsString('12,600.00', $html);
    }

    /** p95 is the receipt that decides whether the page holds. */
    public function test_p95_renders_the_long_list(): void
    {
        $html = $this->s->render($this->tplRepeat(), [], 'en', [
            'contract' => $this->listContract(), 'sample' => 'p95',
        ]);
        $this->assertSame(7, substr_count($html, '<tr>'));
        $this->assertStringContainsString('Arrears', $html);
    }

    public function test_real_data_drives_the_row_count(): void
    {
        $html = $this->s->render($this->tplRepeat(), ['receipt.items' => [
            ['item.head' => 'Tuition', 'item.amount' => '100.00'],
            ['item.head' => 'Bus',     'item.amount' => '50.00'],
            ['item.head' => 'Lab',     'item.amount' => '25.00'],
        ]], 'en', ['contract' => $this->listContract()]);
        $this->assertSame(3, substr_count($html, '<tr>'));
        $this->assertStringContainsString('Bus', $html);
    }

    /**
     * A receipt with no items is not a receipt. Printing an empty box would
     * produce a document that looks valid and proves nothing was paid.
     */
    public function test_an_empty_list_throws_rather_than_printing_an_empty_table(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to print an empty table/');
        $this->s->render($this->tplRepeat(), ['receipt.items' => []], 'en',
            ['contract' => $this->listContract()]);
    }

    public function test_repeating_over_a_field_that_is_not_a_list_throws(): void
    {
        $tpl = $this->tplRepeat();
        $tpl['objects'][0]['content']['repeatOver'] = 'receipt.no';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not a list/');
        $this->s->render($tpl, [], 'en', ['contract' => $this->listContract(), 'sample' => 'typical']);
    }

    public function test_repeating_over_an_undeclared_field_throws(): void
    {
        $tpl = $this->tplRepeat();
        $tpl['objects'][0]['content']['repeatOver'] = 'receipt.nothing';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/contract does not declare/');
        $this->s->render($tpl, [], 'en', ['contract' => $this->listContract(), 'sample' => 'typical']);
    }

    /** Amounts right-aligned, per the contract, without the template saying so. */
    public function test_column_alignment_comes_from_the_contract(): void
    {
        $html = $this->s->render($this->tplRepeat(), [], 'en', [
            'contract' => $this->listContract(), 'sample' => 'typical',
        ]);
        $this->assertStringContainsString('text-align:right', $html);
    }

    public function test_a_header_row_is_optional_and_labelled_from_the_contract(): void
    {
        $tpl = $this->tplRepeat();
        $tpl['objects'][0]['content']['showHeader'] = true;
        $html = $this->s->render($tpl, [], 'en', [
            'contract' => $this->listContract(), 'sample' => 'typical',
        ]);
        $this->assertStringContainsString('Particulars', $html);
        $this->assertSame(3, substr_count($html, '<tr>'), 'header plus two items');
    }

    private function tplRepeat(): array
    {
        return [
            'templateId' => 'TPL1', 'languages' => ['en'], 'defaultLanguage' => 'en',
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'objects' => [[
                'id' => 'items', 'type' => 'table', 'name' => 'Fee items',
                'xMm' => 15, 'yMm' => 80, 'wMm' => 180, 'hMm' => 60,
                'style' => ['sizePt' => 9, 'lineHeight' => 1.5],
                'content' => ['repeatOver' => 'receipt.items', 'columns' => [
                    ['key' => 'item.head', 'wPct' => 70],
                    ['key' => 'item.amount', 'wPct' => 30],
                ]],
            ]],
        ];
    }

    private function listContract(): array
    {
        return ['receipt.no' => ['label' => 'Receipt number', 'sample' => 'R1'],
                'receipt.items' => [
            'label' => 'Fee items', 'type' => 'list',
            'itemFields' => [
                'item.head'   => ['label' => 'Particulars'],
                'item.amount' => ['label' => 'Amount', 'align' => 'right'],
            ],
            'sample' => [
                ['item.head' => 'Tuition fee',   'item.amount' => '12,600.00'],
                ['item.head' => 'Transport fee', 'item.amount' => '4,500.00'],
            ],
            'p95' => array_map(fn($i) => ['item.head' => 'Arrears ' . $i, 'item.amount' => '1,000.00'], range(1, 7)),
        ]];
    }
}
