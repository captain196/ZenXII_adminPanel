<?php
/**
 * Shared brand kit for the report prototypes — one palette, one type system, one set of
 * hand-drawn SVG ornaments (laurel wreath, ribbon seal, corner flourish), reused across
 * every template. This is what separates a "system" from six one-off pages: every document
 * shares the same restrained color language and the same vector artwork, just recolored.
 *
 * dompdf-safe: plain inline <svg> with <path>/<circle>/<polygon> only — no filters, no
 * tiled pattern backgrounds (dompdf's tiling support is unreliable), no clip-path. Chrome
 * (ID card) is not bound by this and gets its own richer treatment in that template.
 */

function bk_palette(): array {
    return [
        'ink'     => '#12181f',   // near-black, softer than pure black — body text
        'ink2'    => '#3a4552',   // secondary text
        'muted'   => '#6b7684',   // labels, meta
        'faint'   => '#9aa4b1',   // placeholders, hairline text
        'line'    => '#e4e8ec',   // hairlines / table dividers
        'line2'   => '#d8dee3',   // slightly stronger rule
        'tint'    => '#f6f8f9',   // subtle panel fill (near-white, not colored)
        'paper'   => '#ffffff',
        'teal'    => '#0e5c53',   // deep, muted brand teal (desaturated from the bright UI teal)
        'teal_d'  => '#0a423b',   // darker teal for text-on-light
        'teal_t'  => '#eaf3f1',   // faint teal tint for panels
        'gold'    => '#8a6d2e',   // antique/muted gold — certificates, seals
        'gold_l'  => '#b89550',   // lighter gold for gradients/rules
        'danger'  => '#a3281c',
        'success' => '#166a3c',
    ];
}

/** Display serif (headlines only — no Devanagari in the same text run) vs. body sans (Unicode-safe). */
function bk_fonts(): array {
    return [
        'serif' => "'Times New Roman', Times, serif",
        'sans'  => "'DejaVu Sans', sans-serif",
        'mono'  => "'DejaVu Sans Mono', monospace",
    ];
}

/**
 * Hand-drawn laurel wreath (two mirrored branches). Pure path data, no filters.
 * $spread = half-angle in degrees the branches open to (visual only, kept fixed).
 */
function bk_laurel(string $color, int $size = 120, float $opacity = 1.0): string {
    $leaf = static function ($cx, $cy, $rot, $s) use ($color) {
        return '<g transform="translate(' . $cx . ',' . $cy . ') rotate(' . $rot . ') scale(' . $s . ')">'
             . '<path d="M0,0 C 4,-10 4,-22 0,-30 C -4,-22 -4,-10 0,0 Z" fill="' . $color . '"/></g>';
    };
    $branch = '';
    // 9 leaves climbing a gentle arc, shrinking as they rise
    for ($i = 0; $i < 9; $i++) {
        $t = $i / 8;
        $x = 6 + $t * 34;
        $y = 110 - $t * 92;
        $rot = -70 + $t * 46;
        $s = 1.05 - $t * 0.42;
        $branch .= $leaf($x, $y, $rot, $s);
    }
    $stem = '<path d="M8,112 C 4,80 10,40 26,14" stroke="' . $color . '" stroke-width="2.2" fill="none" stroke-linecap="round"/>';
    $left  = '<g opacity="' . $opacity . '">' . $stem . $branch . '</g>';
    $right = '<g opacity="' . $opacity . '" transform="translate(' . (2 * 60) . ',0) scale(-1,1)">' . $stem . $branch . '</g>';
    return '<svg width="' . $size . '" height="' . round($size * 1.05) . '" viewBox="0 0 120 126" xmlns="http://www.w3.org/2000/svg">'
         . $left . $right . '</svg>';
}

/** Ribbon + medallion seal. Text is overlaid separately in HTML (dompdf textPath support is unreliable). */
function bk_seal(string $color, int $size = 100): string {
    $r = $size / 2;
    return '<svg width="' . $size . '" height="' . round($size * 1.55) . '" viewBox="0 0 100 155" xmlns="http://www.w3.org/2000/svg">'
         . '<path d="M30,86 L14,150 L37,138 L50,152 L44,90 Z" fill="' . $color . '" opacity="0.92"/>'
         . '<path d="M70,86 L86,150 L63,138 L50,152 L56,90 Z" fill="' . $color . '" opacity="0.92"/>'
         . '<circle cx="50" cy="46" r="44" fill="none" stroke="' . $color . '" stroke-width="3"/>'
         . '<circle cx="50" cy="46" r="36" fill="none" stroke="' . $color . '" stroke-width="1.2"/>'
         . '<circle cx="50" cy="46" r="44" fill="' . $color . '" opacity="0.06"/>'
         . '</svg>';
}

/** A single corner flourish — rotate/mirror via CSS transform per corner. */
function bk_corner_flourish(string $color, int $size = 46): string {
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 46 46" xmlns="http://www.w3.org/2000/svg">'
         . '<path d="M2,44 C2,20 20,2 44,2" stroke="' . $color . '" stroke-width="1.6" fill="none"/>'
         . '<path d="M2,44 C2,26 8,10 24,4" stroke="' . $color . '" stroke-width="1" fill="none" opacity="0.55"/>'
         . '<circle cx="44" cy="2" r="2.6" fill="' . $color . '"/>'
         . '<circle cx="2" cy="44" r="2.6" fill="' . $color . '"/>'
         . '</svg>';
}

/** Small diamond/rosette divider glyph used between hairline rules. */
function bk_rosette(string $color, int $size = 14): string {
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg">'
         . '<path d="M7,0 L10,7 L7,14 L4,7 Z" fill="' . $color . '"/></svg>';
}

/** Tabular-figure ₹ formatter shared across money-bearing templates. */
function bk_money(float $n): string { return inr($n); }
