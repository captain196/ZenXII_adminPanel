<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * B2_xlsx_export — minimal, dependency-free XLSX writer for B2.3.4-A exports.
 *
 * Locked design baseline: B2.3.4-A Phase 1D — School Search spoke export
 * pathway. Multi-sheet support (per-tenant deep dive bundle) deferred to
 * Phase 1H per the design package §3.4.
 *
 * Produces a valid Office Open XML SpreadsheetML (.xlsx) workbook with a
 * single worksheet. Uses PHP's bundled ZipArchive (always available in
 * Windows PHP / XAMPP) to assemble the OOXML package. No composer deps.
 *
 * Limitations (intentional — Phase 1H polishes if needed):
 *   • Single sheet per workbook (multi-sheet → Phase 1H)
 *   • No cell styling beyond inline strings and numbers
 *   • No formulas
 *   • No images / charts / hyperlinks
 *   • Shared strings table not used (cells written as inline strings) —
 *     keeps the writer simple at small-row cost. Acceptable for the
 *     analytics export volumes we anticipate (<10k rows).
 *
 * Usage:
 *   $this->load->library('b2_xlsx_export');
 *   $this->b2_xlsx_export->open('Schools');
 *   $this->b2_xlsx_export->write_header(['School ID', 'Name', 'Code', ...]);
 *   foreach ($rows as $r) {
 *       $this->b2_xlsx_export->write_row([$r['schoolId'], $r['schoolName'], ...]);
 *   }
 *   $path = $this->b2_xlsx_export->save_to_temp();      // returns tempfile path
 *   // or
 *   $this->b2_xlsx_export->stream_to_browser('schools.xlsx'); // sends + exits
 */
class B2_xlsx_export
{
    /** Column header strings (row 1). */
    private array $headers = [];

    /** Row data, each row = list<string|int|float|null>. */
    private array $rows = [];

    /** Worksheet name (sanitised; Excel limits to 31 chars; forbids /\\?*[]:). */
    private string $sheetName = 'Sheet1';

    /** Whether open() has been called. */
    private bool $opened = false;

    public function __construct()
    {
        // CI3 libraries are auto-loaded with no args.
    }

    /**
     * Initialise a fresh workbook with one named worksheet.
     * Idempotent: calling again resets internal state.
     */
    public function open(string $sheetName = 'Sheet1'): void
    {
        $this->headers = [];
        $this->rows    = [];
        $this->sheetName = self::sanitize_sheet_name($sheetName);
        $this->opened  = true;
    }

    /** Set the column header row (row 1). */
    public function write_header(array $cols): void
    {
        if (!$this->opened) $this->open();
        $this->headers = array_values(array_map(fn($v) => (string) $v, $cols));
    }

    /** Append a data row. Scalars (string/int/float/bool/null) accepted. */
    public function write_row(array $cells): void
    {
        if (!$this->opened) $this->open();
        $this->rows[] = array_values($cells);
    }

    /**
     * Assemble the .xlsx package and save to a system temp file.
     * Returns the absolute file path. Caller is responsible for unlink().
     *
     * @throws \RuntimeException if ZipArchive cannot open the temp file.
     */
    public function save_to_temp(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'b2xlsx_');
        if ($path === false) throw new \RuntimeException('Could not allocate temp file for XLSX.');
        $this->save_to_file($path);
        return $path;
    }

    /**
     * Assemble the .xlsx package and write to the given path.
     *
     * @throws \RuntimeException if any zip step fails.
     */
    public function save_to_file(string $filePath): void
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension is required for XLSX export.');
        }
        $zip = new \ZipArchive();
        // OVERWRITE flag: tempnam already created the file; ZipArchive::CREATE
        // alone refuses to write into an existing file with content semantics.
        $rc = $zip->open($filePath, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        if ($rc !== true) {
            throw new \RuntimeException("ZipArchive::open failed (rc={$rc}) for {$filePath}");
        }
        // Order matters only for the central directory record; Excel reads
        // any order, but keeping the manifest-ish files first is conventional.
        $zip->addFromString('[Content_Types].xml',            $this->build_content_types());
        $zip->addFromString('_rels/.rels',                    $this->build_root_rels());
        $zip->addFromString('docProps/app.xml',               $this->build_doc_props_app());
        $zip->addFromString('docProps/core.xml',              $this->build_doc_props_core());
        $zip->addFromString('xl/_rels/workbook.xml.rels',     $this->build_workbook_rels());
        $zip->addFromString('xl/workbook.xml',                $this->build_workbook_xml());
        $zip->addFromString('xl/styles.xml',                  $this->build_styles_xml());
        $zip->addFromString('xl/worksheets/sheet1.xml',       $this->build_sheet1_xml());
        if (!$zip->close()) {
            throw new \RuntimeException("ZipArchive::close failed for {$filePath}");
        }
    }

    /**
     * Stream the .xlsx to the browser as a download and exit.
     * Use from a controller action.
     */
    public function stream_to_browser(string $filename = 'export.xlsx'): void
    {
        $tmp = $this->save_to_temp();
        $size = filesize($tmp);
        // Clean filename: replace any control chars / quotes.
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        if ($safe === '' || $safe === null) $safe = 'export.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Length: ' . (int) $size);
        header('Cache-Control: max-age=0, no-cache, must-revalidate');
        header('Pragma: no-cache');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────
    // OOXML PART BUILDERS
    // ─────────────────────────────────────────────────────────────────

    private function build_content_types(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
            '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
            '</Types>';
    }

    private function build_root_rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
            '</Relationships>';
    }

    private function build_workbook_rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>';
    }

    private function build_workbook_xml(): string
    {
        $name = self::xml_escape($this->sheetName);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"' .
            ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>';
    }

    private function build_styles_xml(): string
    {
        // Minimal but valid styles table. Excel requires at least one font,
        // fill, border and cellXfs entry.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>' .
            '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
            '<borders count="1"><border/></borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>' .
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
            '</styleSheet>';
    }

    private function build_doc_props_app(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"' .
            ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' .
            '<Application>SchoolERP B2.3.4-A</Application>' .
            '</Properties>';
    }

    private function build_doc_props_core(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"' .
            ' xmlns:dc="http://purl.org/dc/elements/1.1/"' .
            ' xmlns:dcterms="http://purl.org/dc/terms/"' .
            ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
            '<dc:creator>SchoolERP Super Admin</dc:creator>' .
            '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>' .
            '</cp:coreProperties>';
    }

    private function build_sheet1_xml(): string
    {
        $out  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $out .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"' .
                ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $out .= '<sheetData>';
        $rowIdx = 1;
        if (!empty($this->headers)) {
            $out .= $this->build_row_xml($rowIdx, $this->headers);
            $rowIdx++;
        }
        foreach ($this->rows as $row) {
            $out .= $this->build_row_xml($rowIdx, $row);
            $rowIdx++;
        }
        $out .= '</sheetData></worksheet>';
        return $out;
    }

    private function build_row_xml(int $rowIdx, array $cells): string
    {
        $r = '<row r="' . $rowIdx . '">';
        $colIdx = 0;
        foreach ($cells as $val) {
            $cellRef = self::col_letter($colIdx) . $rowIdx;
            if ($val === null || $val === '') {
                $r .= '<c r="' . $cellRef . '" t="inlineStr"><is><t></t></is></c>';
            } elseif (is_int($val) || is_float($val)) {
                $r .= '<c r="' . $cellRef . '"><v>' . $val . '</v></c>';
            } elseif (is_bool($val)) {
                $r .= '<c r="' . $cellRef . '" t="b"><v>' . ($val ? 1 : 0) . '</v></c>';
            } else {
                $r .= '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">' .
                      self::xml_escape((string) $val) . '</t></is></c>';
            }
            $colIdx++;
        }
        $r .= '</row>';
        return $r;
    }

    // ─────────────────────────────────────────────────────────────────
    // STATIC HELPERS
    // ─────────────────────────────────────────────────────────────────

    /** Convert 0-indexed column number to Excel column letter (A, B, ..., AA). */
    public static function col_letter(int $colIdx): string
    {
        $colIdx = max(0, $colIdx);
        $letters = '';
        $n = $colIdx + 1;
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $n = (int) (($n - 1) / 26);
        }
        return $letters;
    }

    public static function xml_escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Excel sheet name constraints: <=31 chars, no /\\?*[]: characters. */
    public static function sanitize_sheet_name(string $name): string
    {
        $clean = preg_replace('#[\\\\/\\?\\*\\[\\]:]#', '_', $name);
        if ($clean === null || $clean === '') $clean = 'Sheet1';
        if (strlen($clean) > 31) $clean = substr($clean, 0, 31);
        return $clean;
    }
}
