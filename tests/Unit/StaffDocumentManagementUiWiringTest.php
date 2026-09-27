<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase A UI wiring, 2026-08-18.
 *
 * Adds a "HR Documents" section to edit_staff.php, deliberately SEPARATE
 * from the pre-existing "Documents & Photo" section — which was discovered
 * mid-implementation to be an unrelated, older, ad-hoc mechanism
 * (uploadFile() direct into the staff doc's Doc map, for Photo/Aadhar
 * only, never going through Document_service.php). The new section only
 * covers the genuinely-new document types this phase added
 * (appointment_letter/qualification_certificate/experience_certificate/
 * employment_contract/staff_id_proof) via the canonical Document Service
 * endpoints — it must never touch or rename the legacy Photo/Aadhar markup,
 * since that's live, working, unrelated code.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code; the view is HTML+JS — comment-stripping convention
 * matches every other view-touching test in this suite).
 */
class StaffDocumentManagementUiWiringTest extends TestCase
{
    private string $viewSrc;

    protected function setUp(): void
    {
        $this->viewSrc = file_get_contents(__DIR__ . '/../../application/views/edit_staff.php');
        $this->assertNotFalse($this->viewSrc);
    }

    private function viewCode(): string
    {
        $v = $this->viewSrc;
        $v = preg_replace('/<!--.*?-->/s', '', $v);
        $v = preg_replace('~/\*.*?\*/~s', '', $v);
        $v = preg_replace('~^\s*//.*$~m', '', $v);
        return (string) $v;
    }

    public function test_new_section_exists_and_is_navigable(): void
    {
        $this->assertStringContainsString('id="sec-hr-docs"', $this->viewSrc);

        // Navigability moved with the screen (IA-D2): edit_staff became a
        // wizard and its sidebar jump-nav — which never listed every section
        // anyway — was replaced by the stepper. Reachable now means the
        // section is a step panel carrying a step index, which the stepper
        // renders a clickable entry for. Asserted rather than dropped: the
        // point of this test is that HR Documents cannot become orphaned.
        $this->assertMatchesRegularExpression(
            '~id="sec-hr-docs" data-step="(\d+)"~', $this->viewSrc,
            'HR Documents must sit on a wizard step or nothing can reach it');
        $this->assertStringContainsString('nsa-step-panel" id="sec-hr-docs"', $this->viewSrc);
        $this->assertStringContainsString('id="editStepper"', $this->viewSrc,
            'the stepper is what makes the step clickable');
    }

    /**
     * The legacy Photo markup is still untouched — that half of the original
     * guarantee stands.
     *
     * The Aadhar CARD upload is deliberately GONE (UX-D3). It was uploadable
     * both here and under HR Documents > Identity Proof, which gave one
     * document two homes, two states, and no single answer to "do we hold this
     * person's ID?". The NUMBER stays in Statutory Identification beside PAN,
     * PF and ESI; the CARD is collected once, under HR Documents.
     *
     * Inverted rather than deleted, so the removal stays deliberate and cannot
     * quietly regress back into a second upload control.
     */
    public function test_the_duplicate_aadhar_upload_is_gone_but_photo_is_untouched(): void
    {
        $this->assertStringContainsString('id="sec-docs"', $this->viewSrc);
        $this->assertStringContainsString('id="wrap_Photo"', $this->viewSrc);

        $this->assertStringNotContainsString("name=\"Aadhar\" id=\"aadhar\"", $this->viewSrc,
            'the Aadhar card is collected under HR Documents, not twice');
        $this->assertStringNotContainsString('id="wrap_Aadhar"', $this->viewSrc);

        // The number is statutory data and must NOT have gone with it.
        $this->assertStringContainsString('name="aadhar_number"', $this->viewSrc,
            'the Aadhar NUMBER belongs in Statutory Identification');

        // And the operator is told where the document went.
        $this->assertStringContainsString('nsa-doc-pointer', $this->viewSrc);
    }

    public function test_five_new_document_types_all_present_in_the_ui(): void
    {
        // The type list is a PHP array driving a foreach that renders the
        // data-type attribute from a loop variable — the literal rendered
        // attribute only exists post-render, so check the source array
        // keys instead.
        $this->assertStringContainsString("'appointment_letter'        =>", $this->viewSrc);
        $this->assertStringContainsString("'staff_id_proof'            =>", $this->viewSrc);
        $this->assertStringContainsString("'qualification_certificate' =>", $this->viewSrc);
        $this->assertStringContainsString("'experience_certificate'    =>", $this->viewSrc);
        $this->assertStringContainsString("'employment_contract'       =>", $this->viewSrc);
        $this->assertStringContainsString('hr-doc-field', $this->viewSrc);
        $this->assertStringContainsString('htmlspecialchars($hrDocType)', $this->viewSrc);
    }

    public function test_uploads_are_independent_ajax_not_the_big_form_submit(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString("url: '", $code);
        $uploadCallPos = strpos($code, "staff/upload_staff_document");
        $this->assertNotFalse($uploadCallPos);
        // The upload call must be inside its own $.ajax, not inside the
        // existing updateStaffBtn click handler.
        $updateBtnPos = strpos($code, "id: 'updateStaffBtn'") ?: strpos($code, 'updateStaffBtn');
        $this->assertNotFalse($updateBtnPos);
        $this->assertLessThan($uploadCallPos, $updateBtnPos,
            'the HR document upload wiring must be defined after (separate from) the main form-submit handler.');
    }

    public function test_endpoint_urls_match_the_controller_method_names_exactly(): void
    {
        $this->assertStringContainsString("base_url(\"staff/get_staff_documents\")", $this->viewSrc);
        $this->assertStringContainsString("base_url(\"staff/upload_staff_document\")", $this->viewSrc);
    }

    public function test_verification_status_rendered_with_distinct_states(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('verified:', $code);
        $this->assertStringContainsString('rejected:', $code);
        $this->assertStringContainsString('pending:', $code);
    }

    public function test_reloads_status_after_upload_success_and_failure(): void
    {
        $code = $this->viewCode();
        // loadHrDocuments() must be called on both branches so the UI never
        // shows a stale "uploading..." spinner or a stale prior state.
        $successBranch = substr($code, strpos($code, 'success: function (res) {'), 400);
        $this->assertStringContainsString('loadHrDocuments();', $successBranch);
        $errorBranch = substr($code, strpos($code, 'error: function () {', strpos($code, 'success: function (res) {')), 200);
        $this->assertStringContainsString('loadHrDocuments();', $errorBranch);
    }
}
