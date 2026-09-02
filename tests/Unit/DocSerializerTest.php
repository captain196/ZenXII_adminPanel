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
        foreach (array_filter(explode('}', $css)) as $rule) {
            if (trim($rule) === '') continue;
            $sel = trim(explode('{', $rule)[0]);
            $this->assertStringStartsWith('.zx-tpl-TPL0007', $sel,
                "bare or foreign selector leaked: '$sel'");
        }
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
}
