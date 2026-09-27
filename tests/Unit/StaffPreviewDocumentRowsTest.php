<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DOC-D1 — the Review step lists a document the form cannot collect.
 *
 * UX-D3 deliberately removed the Aadhaar CARD upload from new_staff:
 *
 *     "the NUMBER belongs here; the CARD does not. Uploading it here as well
 *      as under HR Documents > Identity Proof gave the same document two
 *      homes, two states and no single answer to 'do we hold this person's
 *      ID?'"
 *
 * The input went; the Review step's "Uploaded Documents" row did not. Its
 * label is hardcoded markup —
 *
 *     <span id="previewAadharName">Aadhar Card</span>
 *
 * — and the updater that would have corrected it bails out first:
 *
 *     var input = document.getElementById('aadhar');
 *     if (!input || !nameEl || !viewBtn) return;      // input is gone
 *
 * so the hardcoded text survives to the screen. An operator creating a staff
 * member sees "Uploaded Documents: Aadhar Card" having uploaded nothing, on a
 * form that can no longer accept that document at all. On a staff record that
 * is a claim about held identity proof, which makes it worse than cosmetic.
 *
 * The invariant asserted here is the general one, not the single instance:
 * every preview document row must correspond to a file input that exists.
 */
final class StaffPreviewDocumentRowsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/views/new_staff.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    /** The "Uploaded Documents" block of the Review step. */
    private function docsBlock(): string
    {
        $i = strpos($this->src, 'Uploaded Documents');
        $this->assertNotFalse($i, 'the Review step no longer lists documents');
        $end = strpos($this->src, '</div>', strpos($this->src, 'nsa-prev-docs', $i));
        $this->assertNotFalse($end);
        // Widen to the close of the docs container.
        return substr($this->src, $i, max(0, strpos($this->src, '</form>', $i) - $i));
    }

    /** @return string[] file-input ids that actually exist in the form */
    private function fileInputIds(): array
    {
        preg_match_all('~<input[^>]*type="file"[^>]*id="([A-Za-z0-9_]+)"~', $this->src, $m);
        return $m[1];
    }

    public function test_the_form_still_has_a_photo_upload(): void
    {
        $this->assertContains('photo', $this->fileInputIds(),
            'the photo upload is gone — the preview row for it must go too');
    }

    /**
     * Premise: UX-D3 really did remove the Aadhaar upload. If this ever fails,
     * the card was reinstated and DOC-D1 should be re-evaluated rather than
     * silently kept.
     */
    public function test_the_aadhaar_card_upload_is_still_absent(): void
    {
        $this->assertNotContains('aadhar', $this->fileInputIds(),
            'the Aadhaar CARD upload was reinstated — revisit UX-D3 and DOC-D1');
    }

    /**
     * DEFECT DOC-D1 — no preview row may advertise a document the form cannot
     * collect.
     */
    public function test_every_preview_document_row_has_a_real_input(): void
    {
        $block = $this->docsBlock();
        $inputs = $this->fileInputIds();

        // Each row is identified by its <span id="preview{Name}Name">.
        preg_match_all('~<span id="preview([A-Za-z0-9]+)Name"~', $block, $m);
        $this->assertNotEmpty($m[1], 'no document rows found to check');

        $orphans = [];
        foreach ($m[1] as $rowName) {
            $candidate = strtolower($rowName);            // Photo -> photo, Aadhar -> aadhar
            if (!in_array($candidate, $inputs, true)) {
                $orphans[] = $rowName;
            }
        }

        $this->assertSame([], $orphans,
            "These Review-step rows advertise documents with no upload input, so\n"
            . "their hardcoded label is what the operator sees:\n  "
            . implode("\n  ", $orphans));
    }

    /**
     * Defence in depth: even if a row outlives its input again, the updater
     * must degrade to "Not Uploaded" rather than leaving stale markup.
     */
    public function test_the_updater_does_not_leave_a_stale_label_when_the_input_is_gone(): void
    {
        $i = strpos($this->src, 'function nsaHandleDocPreview');
        $this->assertNotFalse($i, 'nsaHandleDocPreview() is gone');
        $fn = substr($this->src, $i, 900);

        $this->assertDoesNotMatchRegularExpression(
            '~if\s*\(\s*!input\s*\|\|\s*!nameEl\s*\|\|\s*!viewBtn\s*\)\s*return;~', $fn,
            'a missing input returns early, so the hardcoded label survives — '
            . 'set "Not Uploaded" instead');

        // Removing the guard is only half the fix. Without an explicit
        // missing-input branch the function falls through to `input.files`
        // and throws on null — worse than the stale label it replaced.
        $this->assertMatchesRegularExpression(
            "~if\s*\(\s*!input\s*\)\s*\{[^}]*Not Uploaded[^}]*\}~", $fn,
            'a missing input must be handled explicitly and set "Not Uploaded"; '
            . 'otherwise the function dereferences a null input');
    }
}
