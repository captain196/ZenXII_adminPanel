<?php
defined('BASEPATH') or exit('No direct script access allowed');

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF Generator Library — Wraps Dompdf for report card PDF generation.
 *
 * Usage:
 *   $this->load->library('pdf_generator');
 *   $html = $this->load->view('result/report_card', $data, true);
 *   $this->pdf_generator->download($html, 'report.pdf');
 *
 * Architecture decisions:
 *   - Dompdf chosen over TCPDF (pure PHP, no binaries) and wkhtmltopdf
 *     (requires system binary). Dompdf handles CSS well enough for our
 *     report card templates (no CSS grid, but flexbox fallbacks work).
 *   - SVG gradient for Modern template's circular gauge works in Dompdf 2.x.
 *   - For batch: renders one PDF per student, zips them. This keeps memory
 *     bounded (each PDF is generated and written to disk, then freed).
 */
class Pdf_generator
{
    /** @var CI_Controller */
    private $CI;

    /** @var array Default Dompdf options */
    private $defaultOptions = [
        'defaultFont'            => 'sans-serif',
        'isRemoteEnabled'        => true,   // Allow external images (school logos)
        'isHtml5ParserEnabled'   => true,
        'isFontSubsettingEnabled'=> true,   // Smaller PDFs
        'defaultPaperSize'       => 'A4',
        'defaultPaperOrientation'=> 'portrait',
        'dpi'                    => 96,
        'debugKeepTemp'          => false,
    ];

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    /**
     * Create a configured Dompdf instance.
     */
    private function _create_dompdf(): Dompdf
    {
        $options = new Options();
        foreach ($this->defaultOptions as $key => $val) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($options, $setter)) {
                $options->$setter($val);
            }
        }

        // Temp + font cache directory
        $tempDir = APPPATH . 'cache/dompdf';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $options->setTempDir($tempDir);
        $options->setFontDir($tempDir);
        $options->setFontCache($tempDir);

        // Chroot to allow loading local assets
        $options->setChroot([FCPATH, APPPATH]);

        return new Dompdf($options);
    }

    /**
     * Wrap raw template HTML in a proper HTML5 document for PDF rendering.
     * Templates output bare HTML (no <html>/<head> in single mode),
     * so we wrap them with proper doctype + print-optimized CSS.
     */
    private function _wrap_html(string $html): string
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<style>
  /* ══════════════════════════════════════════════════════════════════════
     PDF-SPECIFIC CSS OVERRIDES FOR DOMPDF
     Dompdf has LIMITED flexbox and ZERO CSS grid support.
     All flex/grid layouts are converted to table/float/inline-block.
     ══════════════════════════════════════════════════════════════════════ */
  body { margin: 0; padding: 0; background: #fff !important; font-size: 11px; }
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

  /* Hide screen-only elements */
  .rc-toolbar, .cb-toolbar, .mn-toolbar, .md-toolbar, .el-toolbar,
  .batch-toolbar, .batch-summary-banner { display: none !important; }

  /* Override screen backgrounds / sizing */
  .rc-wrapper, .cb-wrapper, .mn-wrapper, .md-wrapper, .el-wrapper { padding: 0 !important; }
  .rc-page, .cb-page, .el-page { max-width: 100% !important; margin: 0 !important; }
  .mn-page { max-width: 100% !important; margin: 0 !important; padding: 28px 36px !important; border: none !important; }
  .md-wrapper { max-width: 100% !important; padding: 0 8px !important; }

  /* Card shadows off */
  .md-card { box-shadow: none !important; border: 1px solid #e2e8f0 !important; }
  .md-header { box-shadow: none !important; border-radius: 0 !important; }

  /* Page break support */
  .batch-page-break { page-break-before: always; }

  /* ═══════════════════════════════════════════════════════
     CLASSIC TEMPLATE (rc-) — Already table-based, minimal overrides
     ═══════════════════════════════════════════════════════ */
  .rc-page { width: 100% !important; max-width: 100% !important; margin: 0 !important; }
  .rc-toolbar { display: none !important; }
  .rc-footer { display: none !important; }
  .rc-seal { line-height: 60px; }
  .rc-seal-text { line-height: 1.4; display: inline-block; vertical-align: middle; }

  /* ═══════════════════════════════════════════════════════
     CBSE TEMPLATE (cb-) — Flex/Grid → Table conversions
     ═══════════════════════════════════════════════════════ */
  /* CBSE header — already table-based, minimal overrides */
  .cb-hdr-tbl { background: #1a237e !important; }
  .cb-exam-strip { background: #283593 !important; }

  /* Student panel: details | right panel */
  .cb-student { display: table !important; width: 100%; }
  .cb-stu-details { display: table-cell !important; vertical-align: top; padding: 12px 16px; }
  .cb-stu-right { display: table-cell !important; width: 140px; vertical-align: top; text-align: center; padding: 10px; border-left: 2px solid #1a237e; background: #f5f5ff; }
  .cb-stu-row { display: block !important; margin-bottom: 4px; font-size: 11px; }
  .cb-stu-k { font-weight: 700; color: #1a237e; }
  .cb-stu-v { color: #1a1a2e; }
  .cb-stu-pair { display: table !important; width: 100%; }
  .cb-stu-half { display: table-cell !important; width: 50%; }
  .cb-photo-box { width: 76px; height: 86px; margin: 0 auto 6px; border: 2px solid #1a237e; overflow: hidden; }
  .cb-photo { width: 76px; height: 86px; }
  .cb-photo-ph { text-align: center; padding: 10px; }
  .cb-class-badge { text-align: center; margin-bottom: 4px; }
  .cb-roll-badge { display: inline-block; font-size: 10px; padding: 2px 8px; background: #e8eaf6; border-radius: 8px; }

  /* Part headers */
  .cb-part-hdr { display: block !important; background: #1a237e; padding: 5px 14px; }
  .cb-part-num { display: inline-block; width: 22px; height: 22px; border-radius: 50%; background: #fff; color: #1a237e; font-weight: 900; font-size: 12px; text-align: center; line-height: 22px; margin-right: 8px; vertical-align: middle; }
  .cb-part-title { color: #fff; font-weight: 700; font-size: 11px; letter-spacing: 1px; vertical-align: middle; }

  /* Grade pills */
  .cb-grade-pill { display: inline-block; padding: 2px 10px; border-radius: 12px; font-weight: 900; font-size: 12px; }
  .cb-grade-big { font-size: 14px; padding: 3px 14px; }

  /* Result strip: horizontal stats */
  .cb-result-strip { display: table !important; width: 100%; table-layout: fixed; background: #f5f5ff; }
  .cb-rs-item { display: table-cell !important; text-align: center; padding: 10px 4px; border-right: 1px solid #c5cae9; vertical-align: middle; }
  .cb-rs-item:last-child { border-right: none; }
  .cb-rs-val { font-size: 18px; font-weight: 900; color: #1a237e; }
  .cb-rs-val span { font-size: 11px; color: #5c6bc0; }
  .cb-rs-grade .cb-rs-val { color: #1a237e; background: none !important; -webkit-text-fill-color: #1a237e !important; }

  /* Co-Scholastic Grid (was display:grid 2-col) → simple list */
  .cb-coscho-grid { display: block !important; }
  .cb-coscho-card { display: block !important; padding: 8px 14px; border-bottom: 1px solid #e0e0e0; overflow: hidden; }
  .cb-coscho-icon { display: none !important; }
  .cb-coscho-name { display: inline-block; font-weight: 700; font-size: 11px; color: #1a237e; width: 55%; vertical-align: middle; }
  .cb-coscho-grade { display: inline-block; font-size: 14px; font-weight: 900; color: #9e9e9e; width: 20%; text-align: center; vertical-align: middle; }
  .cb-coscho-note { display: inline-block; font-size: 9px; color: #bdbdbd; writing-mode: horizontal-tb !important; transform: none !important; vertical-align: middle; }
  .cb-coscho-desc { display: none; }

  /* Attendance row */
  .cb-att-row { display: table !important; width: 100%; table-layout: fixed; }
  .cb-att-item { display: table-cell !important; padding: 8px 10px; border-right: 1px solid #e0e0e0; vertical-align: top; }
  .cb-att-item:last-child { border-right: none; }
  .cb-att-k { display: block; font-size: 9px; font-weight: 700; color: #1a237e; text-transform: uppercase; }
  .cb-att-v { display: block; font-size: 11px; font-weight: 600; margin-top: 2px; }

  /* Result declaration */
  .cb-result { display: block !important; text-align: center; padding: 12px 16px; }
  .cb-result-icon { display: inline; font-size: 18px; margin-right: 8px; }
  .cb-result-text { display: inline; }

  /* Signatures */
  .cb-sigs { display: table !important; width: 100%; table-layout: fixed; min-height: 90px; }
  .cb-sig { display: table-cell !important; vertical-align: bottom; text-align: center; padding: 14px 10px 10px; border-right: 1px solid #bdbdbd; }
  .cb-sig:last-child { border-right: none; }
  .cb-sig-space { height: 30px; display: block; }
  .cb-seal { width: 54px; height: 54px; margin: 0 auto 4px; border: 2px dashed #bdbdbd; border-radius: 50%; text-align: center; line-height: 54px; font-size: 8px; color: #bdbdbd; }

  /* ═══════════════════════════════════════════════════════
     MINIMAL TEMPLATE (mn-) — Flex → Table/Block
     ═══════════════════════════════════════════════════════ */
  .mn-wrapper { padding: 0 !important; }

  /* Exam tag row */
  .mn-exam { display: table !important; width: 100%; }
  .mn-exam-name { display: table-cell !important; text-align: left; }
  .mn-exam-year { display: table-cell !important; text-align: right; }

  /* Info grid (Father / Mother / DOB) */
  .mn-info-grid { display: block !important; }
  .mn-kv { display: inline-block !important; margin-right: 24px; margin-bottom: 4px; vertical-align: top; }

  /* Column header row */
  .mn-col-hdr { display: table !important; width: 100%; border-bottom: 2px solid #18181b; padding-bottom: 6px; margin-bottom: 4px; }
  .mn-col-subj { display: table-cell !important; text-align: left; }
  .mn-col-marks { display: table-cell !important; width: 110px; text-align: right; padding-right: 16px; }
  .mn-col-grade { display: table-cell !important; width: 60px; text-align: center; }
  .mn-col-status { display: table-cell !important; width: 60px; text-align: center; }

  /* Subject rows */
  .mn-subj-row { display: table !important; width: 100%; padding: 8px 0; border-bottom: 1px solid #f4f4f5; }
  .mn-subj-name { display: table-cell !important; vertical-align: middle; }
  .mn-subj-marks { display: table-cell !important; width: 110px; text-align: right; padding-right: 16px; vertical-align: middle; }
  .mn-subj-grade { display: table-cell !important; width: 60px; text-align: center; vertical-align: middle; }
  .mn-subj-status { display: table-cell !important; width: 60px; text-align: center; vertical-align: middle; }

  /* Component breakdown */
  .mn-comp-row { display: block !important; padding: 2px 0 8px 16px; border-bottom: 1px solid #f4f4f5; }
  .mn-comp { display: inline-block; margin-right: 6px; margin-bottom: 2px; }

  /* Summary */
  .mn-summary { display: table !important; width: 100%; padding: 16px 0; }
  .mn-sum-primary { display: table-cell !important; vertical-align: baseline; width: 50%; }
  .mn-sum-total { font-size: 30px; font-weight: 800; }
  .mn-sum-pct { font-size: 24px; font-weight: 700; color: #2563eb; margin-left: 12px; }
  .mn-sum-secondary { display: table-cell !important; vertical-align: baseline; text-align: right; }
  .mn-sum-tag { display: inline-block; margin-left: 6px; padding: 3px 10px; border-radius: 16px; font-size: 11px; }

  /* Signatures */
  .mn-sigs { display: table !important; width: 100%; table-layout: fixed; }
  .mn-sig { display: table-cell !important; vertical-align: bottom; text-align: center; padding: 36px 8px 6px; }
  .mn-sig-line { width: 70%; border-top: 1px solid #d4d4d8; margin: 0 auto 5px; }

  /* ═══════════════════════════════════════════════════════
     MODERN TEMPLATE (md-) — Flex/Grid → Table/Float
     ═══════════════════════════════════════════════════════ */
  /* Header — already table-based, gradient fallback */
  .md-header { background: #667eea !important; }
  .md-hdr-tbl { width: 100%; }
  .md-pill { display: inline-block; margin: 0 2px; }
  .md-exam-badge { background: #667eea !important; display: inline-block; }

  /* Student card */
  .md-student-card { display: table !important; width: 100%; }
  .md-stu-left { display: table-cell !important; vertical-align: middle; width: 50%; }
  .md-stu-photo { display: inline-block; width: 52px; height: 52px; border-radius: 50%; overflow: hidden; vertical-align: middle; margin-right: 12px; }
  .md-stu-photo img { width: 52px; height: 52px; }
  .md-photo-ph { width: 52px; height: 52px; text-align: center; }
  .md-stu-info { display: inline-block; vertical-align: middle; }
  .md-stu-right { display: table-cell !important; vertical-align: middle; text-align: right; }
  .md-stu-field { display: inline-block; margin-left: 16px; vertical-align: top; }

  /* Dashboard: gauge card + metrics */
  .md-dashboard { display: table !important; width: 100%; margin-bottom: 14px; }
  .md-gauge-card { display: table-cell !important; width: 170px; vertical-align: top; text-align: center; padding: 16px; position: relative; }
  .md-gauge { width: 110px; height: 110px; }
  .md-gauge-label { position: absolute; top: 50%; left: 50%; margin-top: -14px; margin-left: -40px; width: 80px; text-align: center; }
  .md-gauge-pct { font-size: 24px; font-weight: 800; display: block; }
  .md-gauge-sub { font-size: 9px; display: block; }

  /* Metrics grid (was display:grid 2-col) → 2x2 inline-blocks */
  .md-metrics { display: table-cell !important; vertical-align: top; padding-left: 10px; }
  .md-metric { display: inline-block !important; width: 48%; margin-bottom: 8px; margin-right: 1%; padding: 12px 8px; text-align: center; vertical-align: top; border: 1px solid #e2e8f0; border-radius: 8px; }
  .md-metric-val { font-size: 22px; }
  .md-metric-accent { background: #f0f0ff !important; border: 1px solid #e0e0ff !important; }

  /* Subject grid (was display:grid 2-col) → 2-col inline-blocks */
  .md-subj-grid { display: block !important; }
  .md-subj-card { display: inline-block !important; width: 48%; margin-right: 1%; margin-bottom: 10px; vertical-align: top; border-radius: 10px; padding: 14px; }

  /* Subject card internals */
  .md-sc-header { display: table !important; width: 100%; margin-bottom: 4px; }
  .md-sc-name { display: table-cell !important; vertical-align: middle; font-size: 13px; }
  .md-badge { display: inline-block; vertical-align: middle; }
  .md-badge-grade { background: #667eea !important; }
  .md-sc-marks { display: block !important; margin-bottom: 4px; }
  .md-sc-big { font-size: 22px; }
  .md-sc-footer { display: table !important; width: 100%; }
  .md-sc-pct { display: table-cell !important; text-align: left; }
  .md-sc-pf { display: table-cell !important; text-align: right; }

  /* SVG gauge: solid stroke fallback (url(#md-grad) may fail in Dompdf) */
  .md-gauge-fill { stroke: #667eea !important; }

  /* Progress bars — Dompdf can handle these with basic block */
  .md-sc-track { height: 5px; background: #e2e8f0; overflow: hidden; margin-bottom: 5px; }
  .md-sc-bar { height: 5px; background: #667eea !important; }
  .md-bar-fail { background: #dc2626 !important; }
  .md-bar-muted { background: #cbd5e1 !important; }

  /* Component mini bars */
  .md-sc-comps { display: block !important; margin-top: 8px; padding-top: 8px; border-top: 1px solid #f1f5f9; }
  .md-sc-comp-item { display: block !important; margin-bottom: 4px; }
  .md-sc-comp-hdr { display: table !important; width: 100%; font-size: 9px; }
  .md-sc-comp-name { display: table-cell !important; text-align: left; }
  .md-sc-comp-val { display: table-cell !important; text-align: right; }
  .md-sc-comp-track { height: 3px; background: #f1f5f9; overflow: hidden; }
  .md-sc-comp-bar { height: 3px; background: #667eea !important; }

  /* Result banner */
  .md-result { display: block !important; text-align: center; padding: 14px; border-radius: 10px; }
  .md-result-icon { display: inline; margin-right: 8px; }
  .md-result-pass { background: #dcfce7 !important; border: 1px solid #86efac; }
  .md-result-fail { background: #fef2f2 !important; border: 1px solid #fca5a5; }

  /* Legend */
  .md-legend-card { background: #f8fafc !important; }

  /* Signatures */
  .md-sigs { display: table !important; width: 100%; table-layout: fixed; padding-top: 24px; }
  .md-sig { display: table-cell !important; vertical-align: bottom; text-align: center; min-height: 70px; padding-bottom: 4px; }
  .md-seal { width: 48px; height: 48px; margin: 0 auto 8px; border: 2px dashed #cbd5e1; border-radius: 50%; text-align: center; line-height: 48px; font-size: 8px; color: #94a3b8; }
  .md-sig-line { width: 55%; border-top: 1.5px solid #94a3b8; margin: 0 auto 5px; }

  /* ═══════════════════════════════════════════════════════
     ELEGANT TEMPLATE (el-) — Flex → Table/Block
     ═══════════════════════════════════════════════════════ */
  .el-wrapper { max-width: 100% !important; }
  .el-page { outline: none !important; }

  /* Header — already table-based */
  .el-hdr-tbl { width: 100%; }

  /* Title gradient fallback */
  .el-title { background: #FFF8E7 !important; }

  /* Student photo */
  .el-photo { display: block; width: 86px; height: 106px; margin: 0 auto 12px; border: 2px solid #8B6914; overflow: hidden; }
  .el-photo img { width: 86px; height: 106px; }

  /* Dotted-leader fields */
  .el-field { display: table !important; width: 100%; margin-bottom: 4px; }
  .el-field-k { display: table-cell !important; width: 130px; font-weight: 700; color: #5D4037; white-space: nowrap; vertical-align: baseline; }
  .el-field-dots { display: table-cell !important; border-bottom: 1px dotted #c4a35a; vertical-align: baseline; }
  .el-field-v { display: table-cell !important; white-space: nowrap; font-weight: 600; color: #3E2723; text-align: right; vertical-align: baseline; }

  /* Field pair (DOB | Roll) */
  .el-field-pair { display: table !important; width: 100%; }
  .el-field-half { display: table-cell !important; width: 50%; vertical-align: top; }
  .el-field-half .el-field-k { width: 90px; }

  /* Subject header row */
  .el-subj-hdr { display: table !important; width: 100%; padding: 5px 20px; }
  .el-sh-name { display: table-cell !important; text-align: left; padding-left: 20px; }
  .el-sh-comp { display: table-cell !important; width: 70px; text-align: center; }
  .el-sh-marks { display: table-cell !important; width: 90px; text-align: center; }
  .el-sh-grade { display: table-cell !important; width: 60px; text-align: center; }

  /* Subject items */
  .el-subj-item { display: table !important; width: 100%; padding: 6px 20px; border-bottom: 1px solid #efe4ce; }
  .el-si-marker { display: table-cell !important; width: 18px; vertical-align: middle; color: #c4a35a; font-size: 8px; }
  .el-si-name { display: table-cell !important; vertical-align: middle; font-weight: 700; }
  .el-si-comp { display: table-cell !important; width: 70px; text-align: center; vertical-align: middle; }
  .el-si-marks { display: table-cell !important; width: 90px; text-align: center; vertical-align: middle; font-weight: 700; }
  .el-si-grade { display: table-cell !important; width: 60px; text-align: center; vertical-align: middle; font-weight: 900; color: #8B6914; }

  /* Overall summary rows */
  .el-ov-row { display: block !important; text-align: center; margin-bottom: 8px; }
  .el-ov-item { display: inline-block !important; margin: 0 6px 6px; padding: 8px 18px; border: 1px solid #c4a35a; border-radius: 6px; text-align: center; vertical-align: top; min-width: 90px; }
  .el-ov-wide { min-width: 140px; }
  .el-ov-label { display: block; font-size: 9px; font-weight: 700; color: #8B6914; text-transform: uppercase; margin-bottom: 2px; }
  .el-ov-value { display: block; font-size: 16px; font-weight: 900; color: #3E2723; }

  /* Result frame — keep border, remove ::before/::after (Dompdf partial pseudo support) */
  .el-result-frame { margin: 14px 20px; padding: 14px; border: 2px solid #8B6914; text-align: center; position: relative; }
  .el-result-frame::before, .el-result-frame::after { display: none !important; }
  .el-result-corners { display: none; }

  /* Signatures */
  .el-sigs { display: table !important; width: 100%; table-layout: fixed; min-height: 100px; }
  .el-sig-col { display: table-cell !important; vertical-align: bottom; text-align: center; padding: 14px 10px 8px; }
  .el-sig-space { height: 30px; display: block; }
  .el-seal { width: 68px; height: 68px; margin: 0 auto; border: 3px double #8B6914; border-radius: 50%; text-align: center; line-height: 68px; }
  .el-seal-inner { font-size: 9px; color: #c4a35a; line-height: 1.4; vertical-align: middle; display: inline-block; }
  .el-sig-line { width: 55%; border-top: 1.5px solid #3E2723; margin: 0 auto 4px; }

  /* Watermark — very light in PDF */
  .el-watermark { opacity: 0.02 !important; }

  /* Ornamental border — simplify for PDF */
  .el-page { outline: none !important; outline-offset: 0 !important; }
  .el-inner { border: 1px solid #c4a35a; margin: 5px; }

  /* Dividers */
  .el-divider { height: 4px; border-top: 2px solid #8B6914; border-bottom: 1px solid #c4a35a; margin: 0; }
  .el-div-thin { height: 2px; border-top: 1px solid #c4a35a; border-bottom: none; }
</style>
</head>
<body>' . $html . '</body></html>';
    }

    /**
     * Render HTML to a PDF string (binary).
     *
     * @param string $html  Raw HTML content
     * @return string       PDF binary data
     */
    public function render(string $html): string
    {
        $dompdf = $this->_create_dompdf();
        $dompdf->loadHtml($this->_wrap_html($html));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdf = $dompdf->output();

        // Explicitly free memory
        unset($dompdf);

        return $pdf;
    }

    /**
     * Generate PDF and stream it as a download.
     *
     * @param string $html     Raw HTML content
     * @param string $filename Download filename (e.g. "John_Doe_9th_A.pdf")
     */
    public function download(string $html, string $filename = 'report_card.pdf'): void
    {
        $pdf = $this->render($html);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $this->_safe_filename($filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');

        echo $pdf;
        exit;
    }

    /**
     * Generate PDF and display inline in browser.
     */
    public function inline(string $html, string $filename = 'report_card.pdf'): void
    {
        $pdf = $this->render($html);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $this->_safe_filename($filename) . '"');
        header('Content-Length: ' . strlen($pdf));

        echo $pdf;
        exit;
    }

    /**
     * Save a single PDF to disk.
     *
     * @param string $html     Raw HTML content
     * @param string $filepath Absolute path to write the PDF
     * @return bool
     */
    public function save(string $html, string $filepath): bool
    {
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdf = $this->render($html);
        $written = file_put_contents($filepath, $pdf);
        unset($pdf);

        return $written !== false;
    }

    /**
     * Batch generate: render multiple HTML strings to PDFs, zip them, download.
     *
     * Processes one student at a time to keep memory bounded.
     *
     * @param array  $items    [{html: string, filename: string}, ...]
     * @param string $zipName  Download filename for the ZIP
     */
    public function batch_download(array $items, string $zipName = 'report_cards.zip'): void
    {
        // Create temp directory for PDFs
        $tmpDir = APPPATH . 'cache/pdf_batch_' . uniqid();
        if (!mkdir($tmpDir, 0755, true)) {
            show_error('Cannot create temp directory for PDF batch.', 500);
        }

        $pdfFiles = [];

        try {
            // Generate each PDF one at a time (memory-safe)
            foreach ($items as $idx => $item) {
                $filename = $this->_safe_filename($item['filename']);
                $filepath = $tmpDir . '/' . $filename;

                $this->save($item['html'], $filepath);
                $pdfFiles[] = $filepath;

                // Free memory after each PDF
                if ($idx % 10 === 0) {
                    gc_collect_cycles();
                }
            }

            // Create ZIP
            $zipPath = $tmpDir . '/' . $this->_safe_filename($zipName);
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
                show_error('Cannot create ZIP archive.', 500);
            }

            foreach ($pdfFiles as $pdfFile) {
                $zip->addFile($pdfFile, basename($pdfFile));
            }
            $zip->close();

            // Stream the ZIP
            $zipSize = filesize($zipPath);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $this->_safe_filename($zipName) . '"');
            header('Content-Length: ' . $zipSize);
            header('Cache-Control: private, max-age=0, must-revalidate');

            readfile($zipPath);

        } finally {
            // Cleanup: remove all temp files
            $this->_rmdir_recursive($tmpDir);
        }

        exit;
    }

    /**
     * Make a filename safe for filesystem + HTTP headers.
     */
    private function _safe_filename(string $name): string
    {
        // Replace unsafe chars with underscore
        $safe = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $name);
        // Collapse multiple underscores
        $safe = preg_replace('/_+/', '_', $safe);
        // Trim underscores from edges
        $safe = trim($safe, '_');
        return $safe ?: 'document';
    }

    /**
     * Recursively delete a directory.
     */
    private function _rmdir_recursive(string $dir): void
    {
        if (!is_dir($dir)) return;

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->_rmdir_recursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
