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
 * This deliberately REVERSES the legacy Certificates.php, which substitutes ''
 * and prints a blank. Do not "improve" this back toward leniency.
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
        $objects = $this->visible((array) ($template['objects'] ?? []), $opts);

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
                $html .= $this->emitChain($chain, $data, $lang, $opts, $margins);
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /* ================================================================== *
     *  Visibility (showWhen) — §9A duplicate marking
     * ================================================================== */

    /**
     * `showWhen: "doc.isDuplicate"` drives the statutory duplicate mark, which
     * KER r.22 / TNER r.44 / CBSE r.8(vi) require on a reissue. It is issuance
     * state rather than merge data, but it resolves like a field.
     */
    private function visible(array $objects, array $opts): array
    {
        $dup = !empty($opts['isDuplicate']);
        return array_values(array_filter($objects, function ($o) use ($dup) {
            $when = $o['showWhen'] ?? null;
            if ($when === null)              return true;
            if ($when === 'doc.isDuplicate') return $dup;
            if ($when === '!doc.isDuplicate') return !$dup;
            throw new RuntimeException("Doc_serializer: unknown showWhen '$when' on {$o['id']}");
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

    private function emitChain(array $chain, array $data, string $lang, array $opts, array $margins): string
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
        $html .= $this->emitObject($root, $data, $lang, $opts, true);
        foreach ($chain['members'] as $m) {
            $html .= $this->emitObject($m, $data, $lang, $opts, false);
        }
        return $html . '</div>';
    }

    /**
     * @param bool $isRoot the root's own box is the container; members are
     *                     block children carrying only their gap as margin-top.
     */
    private function emitObject(array $o, array $data, string $lang, array $opts, bool $isRoot): string
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
        if (isset($st['colour'])) {
            $css .= 'color:' . $this->colour($st['colour']) . ';';
        }

        $inner = $this->inner($o, $type, $data, $lang, $opts);

        return '<div class="zx-o zx-' . $this->esc($type) . '" style="' . $css . '">' . $inner . '</div>';
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
        if (!empty($st['fontFamily'])) {
            $css .= 'font-family:' . $this->esc((string) $st['fontFamily']) . ';';
        }
        if (isset($st['track'])) {
            $css .= 'letter-spacing:' . $this->esc((string) $st['track']) . ';';
        }
        return $css;
    }

    private function inner(array $o, string $type, array $data, string $lang, array $opts): string
    {
        switch ($type) {
            case 'text':
                return $this->runs($o, $data, $lang, $opts);

            case 'pageNumber':
                // Rule P2.4: never an absolute object. mPDF substitutes {PAGENO}
                // in the footer band, which is the only way it repeats per page.
                return '{PAGENO}';

            case 'shape':
                return '';

            case 'image':
                $src = (string) (($o['content']['src'] ?? '') ?: '');
                if ($src === '') {
                    throw new RuntimeException("Doc_serializer: image '{$o['id']}' has no src");
                }
                return '<img src="' . $this->esc($src) . '" style="width:100%;">';

            case 'qr':
                $src = (string) (($o['content']['src'] ?? '') ?: '');
                return $src === '' ? '' : '<img src="' . $this->esc($src) . '" style="width:100%;">';

            case 'table':
                return $this->table($o, $data, $lang, $opts);
        }
        throw new RuntimeException("Doc_serializer: unknown object type '$type' on '{$o['id']}'");
    }

    /* ================================================================== *
     *  Runs → HTML  (rules 7 + 8, and the fail-closed rule)
     * ================================================================== */

    private function runs(array $o, array $data, string $lang, array $opts): string
    {
        $runs = $o['content']['i18n'][$lang]['runs'] ?? null;
        if ($runs === null) {
            throw new RuntimeException(
                "Doc_serializer: object '{$o['id']}' has no '$lang' content. "
                . 'The template declares that language, so this is a gap, not a fallback.'
            );
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
        $rows = (array) ($o['content']['rows'] ?? []);
        if (!$rows) {
            return '';
        }
        // Rule 6: tables are permitted; flex and grid are not. mPDF supports
        // neither, and a template that used them would render as a broken stack.
        $html = '<table class="zx-t" cellspacing="0" cellpadding="0" style="width:100%;">';
        foreach ($rows as $row) {
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

        return
            ".$ns{position:relative;width:{$w}mm;min-height:{$h}mm;margin:0;padding:0;"
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

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
