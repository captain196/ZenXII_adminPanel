<?php
defined('BASEPATH') or exit('No direct script access allowed');

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Doc_renderer — PDF rendering for the Document Engine.
 *
 * DELIBERATELY SEPARATE FROM Pdf_generator. Do not "consolidate" them.
 * `Pdf_generator::render()` injects ~184 lines of report-card CSS
 * (.rc-* .cb-* .mn-* .md-* .el-*) plus `body{font-size:11px}` into EVERY pdf it
 * produces, and hardcodes setPaper('A4','portrait'). A certificate rendered
 * through it inherits 11px body text and a pile of foreign selectors. Class-name
 * collision is a known recurring bug class in this codebase (the .att-grid
 * incident). See IMPLEMENTATION_ARCHITECTURE.md constraint C2.
 *
 * Engine is mPDF, not dompdf: dompdf has no complex-text shaping and renders
 * Devanagari matras in the wrong order — wrong words on a legal document.
 * Existing dompdf paths (Result, Accounting, Sis, Admission_public) are
 * untouched by this library.
 *
 * Verified in Gate 0:
 *   G0.2  Lohit registered per script, 8 distinct embedded subsets
 *   G0.3  conjuncts and matra reordering correct in all 8 scripts
 *   G0.4  block-flow inside absolute containers; $mpdf->page canNOT detect
 *         absolute overflow -> measureBlock() exists for that
 *   G0.5  explicit line-height is MANDATORY for preview/proof parity
 *   G0.6  26MB peak, 151ms p95, no accumulation across renders
 *
 * Usage:
 *   $this->load->library('doc_renderer', null, 'docpdf');
 *   $pdf = $this->docpdf->render($html, ['size'=>'A4','orientation'=>'portrait']);
 */
class Doc_renderer
{
    /** Resource caps derived from the G0.6 measurement, not guessed. */
    const MAX_MEMORY      = '96M';   // 26MB measured x ~3.7 headroom
    const MAX_SECONDS     = 15;      // 151ms measured x 10 CPU penalty x 10 safety
    const MAX_PAGES       = 20;      // a worst-case TC is 2 pages
    const OTL_ALL_SCRIPTS = 0xFF;    // bit 0x80 drives complex-script shaping

    /** Paper sizes the engine accepts. Anything else must be explicit [w,h] mm. */
    const PAPER = ['A4' => 'A4', 'A5' => 'A5', 'LETTER' => 'Letter', 'LEGAL' => 'Legal'];

    /** @var CI_Controller */
    private $ci;

    /** @var string */
    private $fontDir;

    /** @var string */
    private $tempDir;

    /** @var array Storage roots an <img> may resolve under. Anything else is rejected. */
    private $imageRoots = [];

    public function __construct(array $config = [])
    {
        $this->ci = &get_instance();

        $this->fontDir = rtrim($config['fontDir'] ?? (FCPATH . 'assets/fonts/lohit'), '/');
        $this->tempDir = rtrim($config['tempDir'] ?? (APPPATH . 'cache/mpdf'), '/');

        if (!is_dir($this->tempDir) && !@mkdir($this->tempDir, 0755, true) && !is_dir($this->tempDir)) {
            throw new RuntimeException('Doc_renderer: temp dir not writable: ' . $this->tempDir);
        }

        // Default image root. Callers scope this per tenant via allowImageRoot().
        $this->imageRoots[] = rtrim(FCPATH . 'uploads', '/');
    }

    /**
     * Permit an additional filesystem root for <img> resolution.
     * Scope this to the tenant's own storage; never to a user-supplied path.
     */
    public function allowImageRoot(string $absolutePath): self
    {
        $real = realpath($absolutePath);
        if ($real !== false) {
            $this->imageRoots[] = rtrim($real, '/');
        }
        return $this;
    }

    /**
     * Font registration.
     *
     * Latin resolves to mPDF's bundled dejavusans — Lohit has NO Latin coverage.
     * Lohit ships Regular only, so no 'B' key: mPDF synthesises bold. That is a
     * known limitation, recorded in assets/fonts/lohit/NOTICE.md.
     */
    private function fontData(): array
    {
        return [
            'lohitdeva' => ['R' => 'Lohit-Devanagari.ttf', 'useOTL' => self::OTL_ALL_SCRIPTS],
            'lohitbeng' => ['R' => 'Lohit-Bengali.ttf',    'useOTL' => self::OTL_ALL_SCRIPTS],
            'lohitgujr' => ['R' => 'Lohit-Gujarati.ttf',   'useOTL' => self::OTL_ALL_SCRIPTS],
            'lohitknda' => ['R' => 'Lohit-Kannada.ttf',    'useOTL' => self::OTL_ALL_SCRIPTS],
            'lohitmlym' => ['R' => 'Lohit-Malayalam.ttf',  'useOTL' => self::OTL_ALL_SCRIPTS],
            'lohittaml' => ['R' => 'Lohit-Tamil.ttf',      'useOTL' => self::OTL_ALL_SCRIPTS],
            'lohittelu' => ['R' => 'Lohit-Telugu.ttf',     'useOTL' => self::OTL_ALL_SCRIPTS],
        ];
    }

    /** Map a template page spec to mPDF constructor config. */
    private function pageConfig(array $page): array
    {
        $size = $page['size'] ?? 'A4';
        if (is_array($size)) {
            // Custom [width, height] in mm.
            $format = [(float) $size[0], (float) $size[1]];
        } else {
            $key = strtoupper((string) $size);
            if (!isset(self::PAPER[$key])) {
                throw new InvalidArgumentException('Doc_renderer: unsupported paper size: ' . $key);
            }
            $format = self::PAPER[$key];
        }

        $m = $page['marginsMm'] ?? [];
        return [
            'format'        => $format,
            'orientation'   => (($page['orientation'] ?? 'portrait') === 'landscape') ? 'L' : 'P',
            'margin_left'   => (float) ($m['l'] ?? 15),
            'margin_right'  => (float) ($m['r'] ?? 15),
            'margin_top'    => (float) ($m['t'] ?? 15),
            'margin_bottom' => (float) ($m['b'] ?? 15),
        ];
    }

    /** Construct a configured mPDF instance. */
    private function make(array $page, array $overrides = []): Mpdf
    {
        $defaults = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fonts    = (new \Mpdf\Config\FontVariables())->getDefaults();

        return new Mpdf(array_merge([
            'mode'     => 'utf-8',
            'tempDir'  => $this->tempDir,
            'fontDir'  => array_merge($defaults['fontDir'], [$this->fontDir]),
            'fontdata' => $fonts['fontdata'] + $this->fontData(),
            'useOTL'   => self::OTL_ALL_SCRIPTS,
        ], $this->pageConfig($page), $overrides));
    }

    /**
     * Render template HTML to PDF bytes.
     *
     * @param array $page  Template page spec: size, orientation, marginsMm.
     * @param array $opts  header, footer, pageMode ('single'|'flow'), maxPages.
     *
     * @throws RuntimeException on page overflow (fail closed — never a truncated
     *         legal document) or renderer failure.
     */
    public function render(string $html, array $page = [], array $opts = []): string
    {
        $this->guardImages($html);

        $prevMem  = ini_get('memory_limit');
        $prevTime = (int) ini_get('max_execution_time');
        @ini_set('memory_limit', self::MAX_MEMORY);
        @set_time_limit(self::MAX_SECONDS);

        try {
            $mpdf = $this->make($page);

            if (!empty($opts['header'])) {
                $mpdf->SetHTMLHeader($opts['header']);
            }
            if (!empty($opts['footer'])) {
                $mpdf->SetHTMLFooter($opts['footer']);
            }

            $mpdf->WriteHTML($html);
            $pages = (int) $mpdf->page;

            // Tier 1 overflow gate (G0.4). Works for FLOW content only:
            // absolutely-positioned content never increments $mpdf->page, which
            // is why measureBlock() exists for the absolute case.
            $maxPages = (int) ($opts['maxPages'] ?? self::MAX_PAGES);
            $single   = (($opts['pageMode'] ?? 'flow') === 'single');
            if (($single && $pages > 1) || $pages > $maxPages) {
                throw new RuntimeException(sprintf(
                    'E_PAGE_OVERFLOW: rendered %d page(s); mode=%s max=%d',
                    $pages,
                    $single ? 'single' : 'flow',
                    $single ? 1 : $maxPages
                ));
            }

            $pdf = $mpdf->Output('', Destination::STRING_RETURN);
            unset($mpdf);
            return $pdf;
        } finally {
            @ini_set('memory_limit', $prevMem);
            @set_time_limit($prevTime);
        }
    }

    /**
     * Natural rendered height of a block, in millimetres.
     *
     * TIER 2 OVERFLOW DETECTION (G0.4). $mpdf->page cannot detect overflow of
     * absolutely-positioned content — it silently clips, which on a Transfer
     * Certificate means losing the signature block with no error. So we measure
     * the block by rendering it in normal flow on a scratch document whose page
     * is too tall to paginate, and read the y-delta.
     *
     * This is NOT the layout pass that architecture §0 removed: positioning
     * still comes from flow containers and the renderer. mPDF is used here purely
     * as a measuring device, for validation, at proof/publish time.
     */
    public function measureBlock(string $html, float $widthMm): float
    {
        $mpdf = $this->make(
            ['size' => [$widthMm, 5000], 'marginsMm' => ['l' => 0, 'r' => 0, 't' => 0, 'b' => 0]]
        );
        $y0 = $mpdf->y;
        $mpdf->WriteHTML($html);
        $h = $mpdf->y - $y0;
        unset($mpdf);
        return round((float) $h, 3);
    }

    /**
     * Would this absolutely-positioned chain overflow the page?
     *
     * @param array $page      Template page spec.
     * @param float $topMm     Chain origin from the page top.
     * @param float $widthMm   Chain width.
     */
    public function wouldOverflow(string $chainHtml, array $page, float $topMm, float $widthMm): bool
    {
        $cfg      = $this->pageConfig($page);
        $heightMm = is_array($cfg['format'])
            ? (float) $cfg['format'][1]
            : ($cfg['orientation'] === 'L' ? 210.0 : 297.0);   // A4 default

        return ($topMm + $this->measureBlock($chainHtml, $widthMm))
             > ($heightMm - (float) $cfg['margin_bottom']);
    }

    /**
     * Reject any <img> that does not resolve under an allowed root.
     *
     * mPDF fetches remote images server-side, so an unvalidated src is an SSRF
     * primitive. Enforced HERE rather than in the UI: the renderer is the last
     * line, and the UI is not a security boundary.
     */
    private function guardImages(string $html): void
    {
        if (!preg_match_all('/<img\b[^>]*\bsrc\s*=\s*("|\')(.*?)\1/i', $html, $m)) {
            return;
        }
        foreach ($m[2] as $src) {
            $src = html_entity_decode(trim($src), ENT_QUOTES, 'UTF-8');

            if (preg_match('#^(https?|ftp|file|php|data)://#i', $src) || str_starts_with($src, 'data:')) {
                throw new RuntimeException('E_IMAGE_SOURCE: remote or inline image rejected: '
                    . substr($src, 0, 60));
            }

            $real = realpath($src);
            if ($real === false) {
                throw new RuntimeException('E_IMAGE_SOURCE: unresolvable image path');
            }

            $ok = false;
            foreach ($this->imageRoots as $root) {
                if (str_starts_with($real, $root . DIRECTORY_SEPARATOR) || $real === $root) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                throw new RuntimeException('E_IMAGE_SOURCE: image outside permitted roots');
            }
        }
    }
}
