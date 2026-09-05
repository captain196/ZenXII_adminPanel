<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Doc_serializer — template model → HTML. ONE serializer, two sinks.
 *
 * The same output feeds the browser preview and mPDF. There is deliberately no
 * second "preview CSS": if the two ever diverge, the preview is lying about what
 * will be printed, and a certificate is a legal record. Divergence is a bug.
 *
 * EXECUTION_PLAN_v1.1 Phase 2 · IMPLEMENTATION_ARCHITECTURE §5.3
 *
 * ---------------------------------------------------------------------------
 * FAIL CLOSED. THIS IS THE WHOLE DESIGN.
 * ---------------------------------------------------------------------------
 * An unresolved merge field or an off-contract key THROWS. It is never
 * substituted with '', a dash, or the key itself. A document that prints a
 * literal {student_name} is both an embarrassment and a forgery vector, and a
 * blank where a statutory field belongs is a defective legal record.
 *
 * This deliberately REVERSES the legacy Certificates.php (retired 2026-09-04),
 * which substituted '' and printed a blank. Do not "improve" this back toward
 * leniency — the reason it existed outlives the file it was contrasted with.
 *
 * ---------------------------------------------------------------------------
 * CONTENT FORMAT — runs, not Quill Deltas
 * ---------------------------------------------------------------------------
 * IMPLEMENTATION_ARCHITECTURE §5.3 rule 7 says "Quill Delta → HTML". That is
 * STALE. The shipped client (`assets/js/doctemplates/designer.js`) stores:
 *
 *     content.i18n[lang].runs = [ {t:"literal", b?,i?,u?} | {f:"merge.key"} ]
 *
 * with "\n" inside a text run meaning <br>. This serializer matches the client's
 * `runsHTML()` exactly, because that function is what the designer previews with
 * — matching the spec instead of the code would guarantee the divergence rule 1
 * exists to prevent.
 */
class Doc_serializer
{
    /** Object types that carry no text and therefore no line-height duty. */
    private const NON_TEXT = ['image', 'shape', 'qr', 'pageNumber'];

    /**
     * P7.2 — the SAME faces the renderer registers, declared for the browser.
     *
     * Without these the preview is a lie. The serializer emits
     * `font-family:lohitdeva`; mPDF has that family registered, the BROWSER does
     * not, so the preview silently reflows in a system font while the PDF sets
     * in Lohit — different metrics, different line breaks, different page
     * count. G0.5 measured exactly this class of divergence at up to 2x on
     * Tamil, and the whole "one serializer, two sinks" rule exists to stop it.
     *
     * Mirrors Doc_renderer::fontData(). DocFontParityTest asserts the two stay
     * identical, because a family registered on one side only is the same
     * silent fallback in a new disguise.
     */
    private const FONT_FACES = [
        'lohitdeva' => 'Lohit-Devanagari.ttf',
        'lohitbeng' => 'Lohit-Bengali.ttf',
        'lohitgujr' => 'Lohit-Gujarati.ttf',
        'lohitknda' => 'Lohit-Kannada.ttf',
        'lohitmlym' => 'Lohit-Malayalam.ttf',
        'lohittaml' => 'Lohit-Tamil.ttf',
        'lohittelu' => 'Lohit-Telugu.ttf',
    ];

    /** Web root for the faces above. Overridable for a CDN or a sub-path deploy. */
    private string $fontBase = '/assets/fonts/lohit';

    public function setFontBase(string $base): self
    {
        $this->fontBase = rtrim($base, '/');
        return $this;
    }

    /** @var array<string,array> merge-field contract for the active docType */
    private array $contract = [];

    /* ================================================================== *
     *  Entry point
     * ================================================================== */

    /**
     * @param array  $template the head (COLLECTION_SHAPES §3)
     * @param array  $data     key => resolved value; ignored when opts['sample']
     * @param string $lang     language to render
     * @param array  $opts     sample: false|'typical'|'p95'
     *                         contract: key => definition (required for sample mode)
     *                         isDuplicate: bool — drives showWhen
     * @throws RuntimeException on any unresolved or off-contract field
     */
    public function render(array $template, array $data, string $lang, array $opts = []): string
    {
        $this->contract = (array) ($opts['contract'] ?? []);

        $langs = (array) ($template['languages'] ?? ['en']);
        if (!in_array($lang, $langs, true)) {
            // Rendering a language the template does not declare produces a
            // silently empty document — every run lookup misses.
            throw new RuntimeException(
                "Doc_serializer: template declares [" . implode(',', $langs) . "], asked for '$lang'"
            );
        }

        $tplId   = (string) ($template['templateId'] ?? 'TPL');
        $ns      = 'zx-tpl-' . preg_replace('/[^A-Za-z0-9_-]/', '', $tplId);
        $page    = (array) ($template['page'] ?? []);
        $objects = $this->visible((array) ($template['objects'] ?? []), $opts, $data);

        [$w, $h]  = $this->pageMm($page);
        $margins  = (array) ($page['marginsMm'] ?? ['t' => 15, 'r' => 15, 'b' => 15, 'l' => 15]);

        // Rule 4: anchor chains collapse into one absolute container each, so
        // roots are resolved before anything is emitted.
        $chains = $this->chains($objects);

        $html  = '<style>' . $this->css($ns, $page) . '</style>';
        $html .= '<div class="' . $ns . '">';

        foreach (['header', 'body', 'footer'] as $region) {
            $inRegion = array_filter($chains, fn($c) => $this->regionOf($c['root']) === $region);
            if (!$inRegion && $region !== 'body') {
                continue;
            }
            $html .= '<div class="zx-region zx-' . $region . '">';
            foreach ($inRegion as $chain) {
                $html .= $this->emitChain($chain, $data, $lang, $opts, $margins, $template);
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /* ================================================================== *
     *  P2.7 — the TWO-TIER page-overflow gate
     * ================================================================== */

    /**
     * Find content that will not fit the page. Run at PROOF and PUBLISH time,
     * never on every preview — tier 2 renders a scratch document per chain.
     *
     * WHY TWO TIERS. Gate G0.4 proved `$mpdf->page` NEVER fires for absolutely
     * positioned content: mPDF silently clips it instead of paginating. On a
     * Transfer Certificate that means losing the signature block with no error
     * at all. So tier 1 alone is not merely incomplete, it is blind to the
     * common case — almost every certificate object is absolute.
     *
     *   TIER 1 — the flow region. `pageMode: 'single'` plus a rendered page
     *            count above 1 means the flowing body spilled. This is the only
     *            tier `$mpdf->page` can answer.
     *   TIER 2 — absolute chains. Measure the chain in normal flow on a scratch
     *            un-paginatable page, then compare its origin plus its measured
     *            height against the usable page height.
     *
     * @param object $renderer anything exposing measureBlock()/wouldOverflow()
     *                         — injected so this is testable without mPDF.
     * @return list<array{tier:int,object:string,type:string,message:string}>
     */
    public function overflowFindings(
        array $template,
        array $data,
        string $lang,
        $renderer,
        array $opts = []
    ): array {
        $this->contract = (array) ($opts['contract'] ?? []);

        $page     = (array) ($template['page'] ?? []);
        $margins  = (array) ($page['marginsMm'] ?? ['t' => 15, 'r' => 15, 'b' => 15, 'l' => 15]);
        $objects  = $this->visible((array) ($template['objects'] ?? []), $opts, $data);
        $chains   = $this->chains($objects);
        $findings = [];

        foreach ($chains as $chain) {
            $root  = $chain['root'];
            $wMm   = (float) ($root['wMm'] ?? 180);
            $html  = $this->emitChain($chain, $data, $lang, $opts, $margins, $template);

            if (!empty($root['flowRegion'])) {
                // TIER 1. Only meaningful when the template pins itself to one
                // page; a multi-page template is allowed to flow.
                // NOT method_exists(): a renderer that cannot answer must fail
                // LOUDLY. Skipping silently is how tier 1 would have been dead
                // code in production while the gate still reported "no findings".
                if (($template['pageMode'] ?? 'flow') === 'single') {
                    if (!method_exists($renderer, 'pageCount')) {
                        throw new RuntimeException(
                            'Doc_serializer: pageMode "single" needs a renderer with pageCount(); '
                            . get_class($renderer) . ' has none, so tier 1 could not be evaluated.'
                        );
                    }
                    if ($renderer->pageCount($html, $page) > 1) {
                        $findings[] = [
                            'tier' => 1, 'object' => (string) $root['id'], 'type' => 'E_PAGE_OVERFLOW',
                            'message' => 'The flowing body spills onto a second page, but this template '
                                       . 'is set to a single page.',
                        ];
                    }
                }
                continue;
            }

            // TIER 2.
            if ($renderer->wouldOverflow($html, $page, (float) ($root['yMm'] ?? 0), $wMm)) {
                $findings[] = [
                    'tier' => 2, 'object' => (string) $root['id'], 'type' => 'E_PAGE_OVERFLOW',
                    'message' => "The chain starting at '{$root['id']}' extends past the bottom "
                               . 'margin. Absolute content does not paginate — mPDF would clip it '
                               . 'silently, so this blocks rather than warns.',
                ];
            }
        }

        return $findings;
    }

    /**
     * Convenience wrapper for callers that want the plan's fail-closed shape.
     * @throws RuntimeException when anything overflows.
     */
    public function assertFits(array $template, array $data, string $lang, $renderer, array $opts = []): void
    {
        $f = $this->overflowFindings($template, $data, $lang, $renderer, $opts);
        if ($f) {
            throw new RuntimeException('E_PAGE_OVERFLOW: ' . implode(' | ', array_column($f, 'message')));
        }
    }

    /* ================================================================== *
     *  Visibility (showWhen) — §9A duplicate marking
     * ================================================================== */

    /**
     * `showWhen: "doc.isDuplicate"` drives the statutory duplicate mark, which
     * KER r.22 / TNER r.44 / CBSE r.8(vi) require on a reissue. It is issuance
     * state rather than merge data, but it resolves like a field.
     */
    private function visible(array $objects, array $opts, array $data = []): array
    {
        $dup = !empty($opts['isDuplicate']);

        return array_values(array_filter($objects, function ($o) use ($dup, $opts, $data) {
            $when = $o['showWhen'] ?? null;
            if ($when === null)               return true;
            if ($when === 'doc.isDuplicate')  return $dup;
            if ($when === '!doc.isDuplicate') return !$dup;

            /* A CONTRACT FIELD: show this object only when that field resolves
               to something.
               
               This branch was missing, and the designer offers it on every
               contract field — the inspector literally reads "Only when
               '<label>' has a value". The shipped Annexure-I starter uses it
               (the "Checked by" signature appears only where dues are
               recorded), so the default Transfer Certificate starter could not
               be proofed AT ALL: the render threw
               "unknown showWhen 'tc.duesPaidUpto'".
               
               Preview and proof disagreeing is the one thing this serializer
               exists to prevent — the preview showed the object, the PDF path
               refused the document. */
            if (isset($this->contract[$when])) {
                $def = $this->contract[$when];
                $sample = $opts['sample'] ?? false;

                if ($sample !== false) {
                    $v = ($sample === 'p95')
                        ? ($def['p95'] ?? $def['sample'] ?? null)
                        : ($def['sample'] ?? null);
                } else {
                    $v = $data[$when] ?? null;
                }
                return $v !== null && $v !== '';
            }

            /* Still fail closed on a genuinely unknown condition. An object
               that silently defaulted to visible could put a signature block on
               a certificate that was never meant to carry one. */
            throw new RuntimeException(
                "Doc_serializer: unknown showWhen '$when' on {$o['id']}. It is neither "
                . 'doc.isDuplicate nor a field this document type declares.'
            );
        }));
    }

    /* ================================================================== *
     *  Anchor chains  (rule 4)
     * ================================================================== */

    /**
     * An anchored object holds a GAP below its anchor rather than a coordinate,
     * so a chain must be emitted as ONE absolutely-positioned container whose
     * members are block children. Emitting each member absolutely would freeze
     * the gaps at design-time heights and break the moment text grows.
     *
     * @return list<array{root:array,members:list<array>}>
     */
    private function chains(array $objects): array
    {
        $byId = [];
        foreach ($objects as $o) {
            $byId[$o['id']] = $o;
        }

        $children = [];
        $roots    = [];
        foreach ($objects as $o) {
            $to = $o['anchorTo'] ?? null;
            if ($to === null || !isset($byId[$to])) {
                if ($to !== null && !isset($byId[$to])) {
                    // A dangling anchor silently reverts the object to (x,y),
                    // which looks like "it moved on its own" weeks later.
                    throw new RuntimeException(
                        "Doc_serializer: {$o['id']} anchors to missing object '$to'"
                    );
                }
                $roots[] = $o;
            } else {
                $children[$to][] = $o;
            }
        }

        usort($roots, fn($a, $b) => ($a['z'] ?? 0) <=> ($b['z'] ?? 0));

        $out     = [];
        $reached = [];
        foreach ($roots as $r) {
            $members = $this->walk($r, $children, [$r['id'] => true]);
            $reached[$r['id']] = true;
            foreach ($members as $m) {
                $reached[$m['id']] = true;
            }
            $out[] = ['root' => $r, 'members' => $members];
        }

        /* A cycle (a -> b -> a) gives every member a valid anchor, so NONE of
         * them becomes a root and the walk never visits them. Without this
         * check they are not merely un-ordered — they are silently DROPPED from
         * the document, which on a certificate means a statutory field quietly
         * not printing. Caught by DocSerializerTest::test_an_anchor_cycle_is_rejected,
         * which failed on the first run for exactly this reason. */
        $lost = array_values(array_diff(array_keys($byId), array_keys($reached)));
        if ($lost) {
            throw new RuntimeException(
                'Doc_serializer: anchor cycle — these objects are unreachable from any root and '
                . 'would have been dropped silently: ' . implode(', ', $lost)
            );
        }

        return $out;
    }

    /** Depth-first chain walk with cycle detection. */
    private function walk(array $node, array $children, array $seen): array
    {
        $members = [];
        foreach ($children[$node['id']] ?? [] as $child) {
            if (isset($seen[$child['id']])) {
                throw new RuntimeException("Doc_serializer: anchor cycle at '{$child['id']}'");
            }
            $seen[$child['id']] = true;
            $members[] = $child;
            $members   = array_merge($members, $this->walk($child, $children, $seen));
        }
        return $members;
    }

    /* ================================================================== *
     *  Emission
     * ================================================================== */

    private function emitChain(array $chain, array $data, string $lang, array $opts, array $margins, array $template = []): string
    {
        $root = $chain['root'];

        // Rule: the flow region is emitted in NORMAL FLOW between the mPDF
        // header and footer, so it can paginate. Everything else is absolute.
        $flow = !empty($root['flowRegion']);

        $style = $flow
            ? 'width:' . $this->mm($root['wMm'] ?? 180) . ';'
            : 'position:absolute;left:' . $this->mm($root['xMm'] ?? 0) . ';'
              . 'top:' . $this->mm($root['yMm'] ?? 0) . ';'
              . 'width:' . $this->mm($root['wMm'] ?? 180) . ';';

        $style .= 'z-index:' . (int) ($root['z'] ?? 1) . ';';

        $html = '<div class="zx-chain" style="' . $style . '">';
        $html .= $this->emitObject($root, $data, $lang, $opts, true, $template);
        foreach ($chain['members'] as $m) {
            $html .= $this->emitObject($m, $data, $lang, $opts, false, $template);
        }
        return $html . '</div>';
    }

    /**
     * @param bool $isRoot the root's own box is the container; members are
     *                     block children carrying only their gap as margin-top.
     */
    private function emitObject(array $o, array $data, string $lang, array $opts, bool $isRoot, array $template = []): string
    {
        $type = (string) ($o['type'] ?? 'text');
        $st   = (array) ($o['style'] ?? []);

        $css = $isRoot ? '' : 'margin-top:' . $this->mm($o['anchorGapMm'] ?? 0) . ';';
        if (!$isRoot) {
            $css .= 'width:' . $this->mm($o['wMm'] ?? 0) . ';';
        }

        // Rule 5: height auto => omit entirely; fixed => explicit.
        if (($o['height'] ?? 'auto') === 'fixed') {
            $css .= 'height:' . $this->mm($o['hMm'] ?? 0) . ';';
        }
        // §3.5 — auto-grow needs a ceiling, or an over-long statutory field
        // pushes the signature block off the page.
        if (isset($o['maxHMm'])) {
            $css .= 'max-height:' . $this->mm($o['maxHMm']) . ';overflow:hidden;';
        }

        if (!in_array($type, self::NON_TEXT, true)) {
            $css .= $this->textCss($o, $st);
        }
        /* A page number is a PLACEHOLDER, and mPDF substitutes it by finding
           the exact contiguous string "{PAGENO}" in the page content. Let it
           wrap and the substitution silently does not happen — the document
           prints the token instead of a number. Found on a 20mm-wide box that
           broke it after "{PAGENO"; a 180mm box on identical markup was fine,
           so nothing in the output distinguishes working from broken. */
        if ($type === 'pageNumber') {
            $css .= 'white-space:nowrap;';
            if (isset($st['lineHeight']) && is_numeric($st['lineHeight'])) {
                $css .= 'line-height:' . (float) $st['lineHeight'] . ';';
            }
            if (isset($st['sizePt'])) {
                $css .= 'font-size:' . (float) $st['sizePt'] . 'pt;';
            }
            if (isset($st['align'])) {
                $css .= 'text-align:' . $this->align($st['align']) . ';';
            }
        }
        if (isset($st['colour'])) {
            $css .= 'color:' . $this->colour($st['colour']) . ';';
        }

        $inner = $this->inner($o, $type, $data, $lang, $opts, $template);

        // Omit an empty style attribute rather than emitting style="". Cosmetic,
        // but golden files lock output in — cheaper to be tidy before they exist.
        $attr = $css === '' ? '' : ' style="' . $css . '"';
        return '<div class="zx-o zx-' . $this->esc($type) . '"' . $attr . '>' . $inner . '</div>';
    }

    /**
     * Rule 6a (G0.5, BLOCKING): every text object must emit an explicit
     * line-height. Without it mPDF and Chrome each use their own font-derived
     * default leading and agreement collapses — Tamil measured 18.03 mm in mPDF
     * against 9.53 mm in Chrome on one block, and the error compounds down an
     * anchor chain. A template may not leave it unset.
     */
    private function textCss(array $o, array $st): string
    {
        if (!isset($st['lineHeight']) || !is_numeric($st['lineHeight'])) {
            throw new RuntimeException(
                "Doc_serializer: object '{$o['id']}' has no style.lineHeight. "
                . 'It is mandatory on every text object (G0.5) — without it mPDF and '
                . 'the browser disagree by up to 2x and the error compounds down the chain.'
            );
        }
        $css  = 'line-height:' . (float) $st['lineHeight'] . ';';
        $css .= 'font-size:' . (float) ($st['sizePt'] ?? 10) . 'pt;';
        $css .= 'font-weight:' . (int) ($st['weight'] ?? 400) . ';';
        $css .= 'text-align:' . $this->align($st['align'] ?? 'left') . ';';
        /* CSS CONTEXT, NOT HTML CONTEXT.
           These two were passed through esc() — htmlspecialchars — which is
           correct for HTML text and wrong here: it does not escape `:` or `;`,
           so a value could close the declaration and add its own. A font family
           of "Arial;background:url(http://…)" emitted verbatim, and mPDF renders
           this HTML SERVER-SIDE, so a remote url in it is an outbound request
           from the production host. `guardImages()` exists for exactly that
           threat but only inspects `<img src=`, never `style=`.
           Both are now validated by shape, the way align() and colour() beside
           them already were. */
        if (!empty($st['fontFamily'])) {
            $ff = $this->cssFontFamily((string) $st['fontFamily']);
            if ($ff !== '') {
                $css .= 'font-family:' . $ff . ';';
            }
        }
        if (isset($st['track'])) {
            $tr = $this->cssLength((string) $st['track']);
            if ($tr !== '') {
                $css .= 'letter-spacing:' . $tr . ';';
            }
        }
        return $css;
    }

    private function inner(array $o, string $type, array $data, string $lang, array $opts, array $template = []): string
    {
        switch ($type) {
            case 'text':
                return $this->runs($o, $data, $lang, $opts, $template);

            case 'pageNumber':
                /* Rule P2.4: never an absolute object. mPDF substitutes
                   {PAGENO} in the footer band, which is the only way it repeats
                   per page.
                   
                   THE BROWSER DOES NOT. It has no idea what {PAGENO} means, so
                   the preview printed the literal characters "{PAGENO}" in the
                   footer where the PDF shows a number — one serializer, two
                   sinks, and one of them lying about what prints. Caught by
                   diffing the preview text against the text extracted from the
                   proof PDF, not by looking at either one alone.
                   
                   The preview renders a single page, so "1" is what that page
                   would genuinely carry.
                   DEFAULTS TO THE PDF. The PDF is the legal artifact; a
                   caller that forgets the flag must get the correct document,
                   not the correct preview. */
                if (!($opts['forPdf'] ?? true)) {
                    return '1';   // the preview is one page, and 1 is what it carries
                }
                /* mPDF substitutes {PAGENO} by scanning the page's content for
                   that exact contiguous string. A narrow box breaks it: the
                   receipt's 20mm page-number box wrapped the placeholder after
                   "{PAGENO", and the printed receipt carried the literal token
                   across two lines while the 180mm-wide Transfer Certificate
                   printed a number correctly from IDENTICAL markup. Nothing in
                   the HTML distinguishes the two cases, so nowrap is emitted on
                   the element itself rather than left to the designer's width. */
                /* And REFUSE a box the placeholder cannot survive.
                   nowrap is not enough: mPDF hard-breaks text that cannot fit
                   its box, so a 12mm box still split "{PAGEN / O}" and printed
                   the pieces. There is no output to inspect afterwards that
                   would reveal it — the token simply appears where a number
                   should be — so it is caught here, at design time, on a
                   deliberately conservative width estimate. */
                $pt  = (float) ($o['style']['sizePt'] ?? 10);
                $min = 1.8 * $pt;                       // ≈ 8 characters, generously
                $w   = (float) ($o['wMm'] ?? 0);
                if ($w > 0 && $w < $min) {
                    throw new RuntimeException(sprintf(
                        "Doc_serializer: page-number object '%s' is %.1fmm wide, too narrow for "
                        . 'the page-number placeholder at %.1fpt (needs about %.0fmm). mPDF would '
                        . 'break the placeholder across lines and print it literally instead of a '
                        . 'number — widen the box or reduce the type size.',
                        $o['id'] ?? '?', $w, $pt, $min
                    ));
                }
                return '{PAGENO}';

            case 'shape':
                return '';

            case 'image':
                $src = (string) (($o['content']['src'] ?? '') ?: '');
                if ($src === '') {
                    /* SKIP, do not throw.
                    
                       An image object with no picture chosen is an ABSENT
                       decoration, not a correctness failure — unlike an
                       unresolved merge field, which would print a blank where a
                       statutory value belongs and must always throw.
                    
                       Throwing here made every shipped starter unproofable: all
                       of them carry a School crest placeholder, so no school
                       could publish a Transfer Certificate until it uploaded a
                       crest, and the only symptom was a proof that failed after
                       the design work was done. A certificate that cannot be
                       produced at all is a worse outcome than one without a
                       decorative crest.
                    
                       It is still surfaced: validate() reports it as a warning,
                       so nobody publishes a blank crest box unknowingly. */
                    return '';
                }
                return '<img src="' . $this->esc($this->guardSrc($src, $o)) . '" style="width:100%;">';

            case 'qr':
                $src = (string) (($o['content']['src'] ?? '') ?: '');
                return $src === '' ? '' : '<img src="' . $this->esc($this->guardSrc($src, $o)) . '" style="width:100%;">';

            case 'table':
                return $this->table($o, $data, $lang, $opts);
        }
        throw new RuntimeException("Doc_serializer: unknown object type '$type' on '{$o['id']}'");
    }

    /**
     * P9.6 — reject an image reference that is not a plain storage path.
     *
     * `Doc_renderer::guardImages()` already does this on the PDF path, and this
     * is NOT redundant with it: THE BROWSER PREVIEW NEVER PASSES THROUGH THE
     * RENDERER. Without a guard here, a template carrying
     * `content.src = "https://tracker.example/p.gif"` renders that image in the
     * designer — a request to a third party from the school's browser, made by
     * a document nobody thought was networked — and `data:text/html,…` would be
     * worse. mPDF fetches remote images server-side, so on the PDF path the same
     * value is an SSRF primitive; the renderer catches that. This catches the
     * half the renderer never sees.
     *
     * Enforced HERE rather than in the UI because the UI is not a security
     * boundary: a template can arrive from an import, a starter, or another
     * session's write.
     */
    private function guardSrc(string $src, array $o): string
    {
        $s = html_entity_decode(trim($src), ENT_QUOTES, 'UTF-8');

        // Any scheme at all. `javascript:` and `data:` carry no "//" so a
        // "scheme://" test alone misses them, which is how these usually slip.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $s)) {
            throw new RuntimeException(
                "Doc_serializer: image '{$o['id']}' uses a scheme-qualified src ("
                . substr($s, 0, 40) . '). Only plain storage paths are permitted.'
            );
        }
        if (str_contains($s, '..')) {
            throw new RuntimeException(
                "Doc_serializer: image '{$o['id']}' src traverses parent directories"
            );
        }
        if (str_starts_with($s, '//')) {
            throw new RuntimeException(
                "Doc_serializer: image '{$o['id']}' uses a protocol-relative src"
            );
        }
        return $s;
    }

    /* ================================================================== *
     *  Runs → HTML  (rules 7 + 8, and the fail-closed rule)
     * ================================================================== */

    private function runs(array $o, array $data, string $lang, array $opts, array $template = []): string
    {
        $runs = $o['content']['i18n'][$lang]['runs'] ?? null;

        if ($runs === null) {
            /* P7.5 — languageFallback: 'block' | 'default'.
             *
             * STATUTORY DOCUMENTS USE 'block', and the default here is 'block'
             * rather than 'default' on purpose. Falling back silently prints a
             * Hindi transfer certificate with English sentences in it and tells
             * nobody — the reader cannot know a translation was missing, and the
             * document still carries the school's seal. An error at design time
             * is recoverable; a bilingual legal record in a parent's hand is not.
             *
             * 'default' exists for non-statutory documents where a partial
             * translation genuinely beats no document, and it is opt-IN.
             */
            $policy = (string) ($template['languageFallback'] ?? 'block');
            $fallbackLang = (string) ($template['defaultLanguage'] ?? 'en');

            if ($policy === 'default' && $fallbackLang !== $lang) {
                $runs = $o['content']['i18n'][$fallbackLang]['runs'] ?? null;
            }

            if ($runs === null) {
                throw new RuntimeException(
                    "Doc_serializer: object '{$o['id']}' has no '$lang' content"
                    . ($policy === 'default' ? " and no '$fallbackLang' fallback either" : '')
                    . ". The template declares that language, so this is a gap, not a fallback"
                    . ($policy === 'block' ? " (languageFallback is 'block')" : '') . '.'
                );
            }
        }

        $out = '';
        foreach ((array) $runs as $r) {
            if (isset($r['f'])) {
                $out .= $this->esc($this->resolve((string) $r['f'], $o, $data, $opts));
                continue;
            }
            // Matches the client's runsHTML() exactly: escape, then \n => <br>,
            // then u/i/b nesting in that order.
            $h = str_replace("\n", '<br>', $this->esc((string) ($r['t'] ?? '')));
            if (!empty($r['u'])) $h = '<u>' . $h . '</u>';
            if (!empty($r['i'])) $h = '<i>' . $h . '</i>';
            if (!empty($r['b'])) $h = '<b>' . $h . '</b>';
            $out .= $h;
        }
        return $out;
    }

    /**
     * Resolve one merge field, or throw.
     *
     * Three distinct failures, kept distinct because they have different owners:
     *   off-contract  — the TEMPLATE binds a key its docType never declared
     *   unknown       — the key is not in the field universe at all
     *   unresolved    — the contract is fine; the DATA did not arrive
     */
    private function resolve(string $key, array $o, array $data, array $opts): string
    {
        $sample = $opts['sample'] ?? false;

        if ($this->contract !== [] && !isset($this->contract[$key])) {
            throw new RuntimeException(
                "Doc_serializer: object '{$o['id']}' binds '$key', which this document "
                . "type's contract does not declare. This is the mail-merge failure the "
                . 'append-only contract rule exists to prevent.'
            );
        }

        if ($sample !== false) {
            $def = $this->contract[$key] ?? null;
            if ($def === null) {
                throw new RuntimeException("Doc_serializer: no contract entry for '$key' in sample mode");
            }
            $v = ($sample === 'p95') ? ($def['p95'] ?? $def['sample'] ?? null) : ($def['sample'] ?? null);
            if ($v === null || $v === '') {
                throw new RuntimeException("Doc_serializer: contract entry '$key' carries no sample value");
            }
            return (string) $v;
        }

        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RuntimeException(
                "Doc_serializer: no value resolved for '$key' (object '{$o['id']}'). "
                . 'Refusing to print a blank where a field belongs.'
            );
        }
        return (string) $data[$key];
    }

    /* ================================================================== *
     *  Table
     * ================================================================== */

    private function table(array $o, array $data, string $lang, array $opts): string
    {
        /* The repeating check must come FIRST. A repeating table has no `rows`
           of its own — its rows are the data — so the empty-rows guard below
           returned an empty string and the receipt rendered as a blank box. */
        $repeatFirst = (string) ($o['content']['repeatOver'] ?? '');
        if ($repeatFirst !== '') {
            return $this->repeatingTable($o, $repeatFirst, $data, $opts);
        }

        $rows = (array) ($o['content']['rows'] ?? []);
        if (!$rows) {
            return '';
        }
        // Rule 6: tables are permitted; flex and grid are not. mPDF supports
        // neither, and a template that used them would render as a broken stack.
        /* ---------------------------------------------------------------
           A REPEATING TABLE: one row per item in a list field.
           
           This is the one thing a fee receipt needs that no certificate does.
           A certificate's body is a fixed set of particulars — fourteen rows,
           always fourteen. A receipt's body is a LIST whose length is a
           property of the payment: one pupil pays tuition, another pays
           tuition, transport, hostel, lab and two arrears. That is why this
           document type was declared and left unbuildable.
           
           The rows are DATA, not design. The template declares the columns and
           the list to walk; how many rows appear is decided when the document
           is issued, which is also why the p95 sample carries seven items —
           the overflow gate has to measure the receipt that actually breaks the
           page, not the flattering two-line one.
           --------------------------------------------------------------- */
        $html = '<table class="zx-t" cellspacing="0" cellpadding="0" style="width:100%;">';
        $n = 0;
        foreach ($rows as $row) {
            /* ---------------------------------------------------------------
               A NUMBERED PARTICULAR: {"key": "student.admissionNo"}.
               
               This is the model the designer actually produces, and this method
               did not understand it. It expected every row to be an ARRAY OF
               CELLS carrying their own i18n runs, so `foreach ((array) $row as
               $cell)` walked {"key": "..."} and got the STRING back; a string
               has no ['i18n'], the run list came out empty, and each row
               rendered as <td></td>.
               
               The consequence was as bad as it gets here: the canvas drew the
               particulars and THE PDF DID NOT CONTAIN THEM. A Transfer
               Certificate printed without admission number, student name,
               parentage, date of birth or date of leaving is not a Transfer
               Certificate — and nothing failed, because empty cells are valid
               HTML. Found by reading the text out of a proof PDF rather than by
               looking at the screen.
               
               Mirrors the designer exactly — number, label, value — because
               preview and proof disagreeing is the one thing this serializer
               exists to prevent.
               --------------------------------------------------------------- */
            if (isset($row['key'])) {
                $n++;
                $key   = (string) $row['key'];
                $label = (string) ($this->contract[$key]['label'] ?? $key);
                $value = $this->resolve($key, $o, $data, $opts);

                $html .= '<tr>'
                    . '<td style="width:6%;vertical-align:top;">' . $n . '.</td>'
                    . '<td style="width:46%;vertical-align:top;">' . $this->esc($label) . '</td>'
                    . '<td style="vertical-align:top;border-bottom:1px dotted #2A1C14;">'
                    . $this->esc($value) . '</td>'
                    . '</tr>';
                continue;
            }

            $html .= '<tr>';
            foreach ((array) $row as $cell) {
                $w     = isset($cell['wPct']) ? 'width:' . (float) $cell['wPct'] . '%;' : '';
                $runs  = (array) ($cell['i18n'][$lang]['runs'] ?? []);
                $inner = '';
                foreach ($runs as $r) {
                    $inner .= isset($r['f'])
                        ? $this->esc($this->resolve((string) $r['f'], $o, $data, $opts))
                        : str_replace("\n", '<br>', $this->esc((string) ($r['t'] ?? '')));
                }
                $html .= '<td style="' . $w . 'vertical-align:top;">' . $inner . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /**
     * Render one row per item of a list field.
     *
     * @param string $key the list field, e.g. `receipt.items`
     */
    private function repeatingTable(array $o, string $key, array $data, array $opts): string
    {
        $def = $this->contract[$key] ?? null;
        if ($def === null) {
            throw new RuntimeException(
                "Doc_serializer: object '{$o['id']}' repeats over '$key', which this document "
                . "type's contract does not declare."
            );
        }
        if (($def['type'] ?? null) !== 'list') {
            throw new RuntimeException(
                "Doc_serializer: '$key' is not a list, so '{$o['id']}' cannot repeat over it. "
                . 'A repeating table needs a field whose value is many rows.'
            );
        }

        $sample = $opts['sample'] ?? false;
        if ($sample !== false) {
            $items = ($sample === 'p95') ? ($def['p95'] ?? $def['sample'] ?? []) : ($def['sample'] ?? []);
        } else {
            $items = $data[$key] ?? null;
            /* An empty list is a real answer — a receipt with no items is not a
               receipt — and it must not print as a silently empty box. */
            if (!is_array($items) || !$items) {
                throw new RuntimeException(
                    "Doc_serializer: no rows resolved for '$key' (object '{$o['id']}'). "
                    . 'Refusing to print an empty table where the document\'s substance belongs.'
                );
            }
        }

        $cols = (array) ($o['content']['columns'] ?? []);
        if (!$cols) {
            /* No columns chosen: use every field the contract declares for an
               item, in declaration order. A template that repeats over a list
               and shows nothing is a mistake, not a layout choice. */
            $cols = array_map(fn($k) => ['key' => $k], array_keys((array) ($def['itemFields'] ?? [])));
        }

        $itemDefs = (array) ($def['itemFields'] ?? []);
        $html = '<table class="zx-t" cellspacing="0" cellpadding="0" style="width:100%;">';

        if (!empty($o['content']['showHeader'])) {
            $html .= '<tr>';
            foreach ($cols as $c) {
                $ck    = (string) ($c['key'] ?? '');
                $align = $c['align'] ?? ($itemDefs[$ck]['align'] ?? 'left');
                $w     = isset($c['wPct']) ? 'width:' . (float) $c['wPct'] . '%;' : '';
                $html .= '<td style="' . $w . 'text-align:' . $this->align((string) $align)
                       . ';font-weight:700;border-bottom:1px solid #2A1C14;">'
                       . $this->esc((string) ($itemDefs[$ck]['label'] ?? $ck)) . '</td>';
            }
            $html .= '</tr>';
        }

        foreach ($items as $row) {
            $html .= '<tr>';
            foreach ($cols as $c) {
                $ck    = (string) ($c['key'] ?? '');
                $align = $c['align'] ?? ($itemDefs[$ck]['align'] ?? 'left');
                $w     = isset($c['wPct']) ? 'width:' . (float) $c['wPct'] . '%;' : '';
                $val   = is_array($row) ? ($row[$ck] ?? '') : '';
                $html .= '<td style="' . $w . 'text-align:' . $this->align((string) $align)
                       . ';vertical-align:top;">' . $this->esc((string) $val) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</table>';
    }

    /* ================================================================== *
     *  CSS  (rules 2, 5, 6)
     * ================================================================== */

    /**
     * Every selector is namespaced under the template root. Bare element
     * selectors are forbidden: `.att-grid` collided with a card-grid utility in
     * this codebase before, and a certificate injected into a panel page must
     * neither inherit nor leak style. Zero flex, zero grid — mPDF supports
     * neither, so a template using them would preview fine and print broken.
     */
    private function css(string $ns, array $page): string
    {
        [$w, $h] = $this->pageMm($page);
        $m = (array) ($page['marginsMm'] ?? ['t' => 15, 'r' => 15, 'b' => 15, 'l' => 15]);

        return $this->fontFaceCss()
          . ".$ns{position:relative;width:{$w}mm;min-height:{$h}mm;margin:0;padding:0;"
          . 'box-sizing:border-box;font-family:dejavusans;color:#000;}'
          . ".$ns .zx-region{position:relative;}"
          . ".$ns .zx-chain{box-sizing:border-box;}"
          . ".$ns .zx-o{box-sizing:border-box;}"
          . ".$ns .zx-t{border-collapse:collapse;}"
          . ".$ns .zx-t td{padding:0;}"
          . ".$ns .zx-shape{background:currentColor;}";
    }

    /* ================================================================== *
     *  Helpers
     * ================================================================== */

    /**
     * `@font-face` for every Lohit family, with `font-display: block`.
     *
     * `block` and not `swap` on purpose: swap paints the text in a fallback
     * face first and reflows when the real one arrives, which on a certificate
     * means the designer briefly sees a layout that will never be printed. Block
     * shows nothing until the real face is there — for a legal document, a
     * short blank is better than a confident wrong rendering.
     */
    private function fontFaceCss(): string
    {
        $out = '';
        foreach (self::FONT_FACES as $family => $file) {
            $out .= "@font-face{font-family:'{$family}';"
                  . "src:url('{$this->fontBase}/{$file}') format('truetype');"
                  . 'font-weight:400;font-style:normal;font-display:block;}';
        }
        return $out;
    }

    private function pageMm(array $page): array
    {
        $sizes = ['A4' => [210, 297], 'A5' => [148, 210], 'Letter' => [215.9, 279.4], 'Legal' => [215.9, 355.6]];
        $size  = $page['size'] ?? 'A4';
        $dims  = is_array($size) ? [(float) $size[0], (float) $size[1]] : ($sizes[$size] ?? $sizes['A4']);
        if (($page['orientation'] ?? 'portrait') === 'landscape') {
            $dims = [$dims[1], $dims[0]];
        }
        return $dims;
    }

    private function regionOf(array $o): string
    {
        $r = $o['region'] ?? 'body';
        return in_array($r, ['header', 'body', 'footer'], true) ? $r : 'body';
    }

    /** Trims trailing zeros so golden files stay stable across float noise. */
    private function mm($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') . 'mm';
    }

    private function align(string $a): string
    {
        return in_array($a, ['left', 'right', 'center', 'justify'], true) ? $a : 'left';
    }

    private function colour(string $c): string
    {
        return preg_match('/^#[0-9A-Fa-f]{3,8}$/', $c) ? $c : '#000';
    }

    /**
     * A font-family list, or nothing.
     *
     * Permits family names, quotes, commas and spaces — and nothing that could
     * end a declaration or open a function call. Rejecting outright rather than
     * stripping: a half-sanitised font stack that silently loses its fallback is
     * a worse outcome than falling back to the document default.
     */
    private function cssFontFamily(string $v): string
    {
        $v = trim($v);
        if ($v === '' || mb_strlen($v) > 120) {
            return '';
        }
        return preg_match('/^[A-Za-z0-9 ,\'"_-]+$/u', $v) ? $v : '';
    }

    /**
     * A CSS length — a number with an optional unit, or nothing.
     *
     * Covers what `track` legitimately holds (".18em", "0", "-0.5px").
     */
    private function cssLength(string $v): string
    {
        $v = trim($v);
        return preg_match('/^-?(?:\d+\.?\d*|\.\d+)(?:em|rem|px|pt|%)?$/', $v) ? $v : '';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
