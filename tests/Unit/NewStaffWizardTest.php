<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * new_staff.php — Multi-step onboarding wizard redesign, 2026-08-18.
 *
 * Reorganizes the single long scrollable form into 8 logically-grouped
 * steps (Personal, Contact & Guardian, Employment, Statutory ID, Address,
 * Qualification, Compensation & Banking, Review & Create) ending in an
 * inline Review & Create step (no longer a popup modal). Every field
 * `name=`/`id=` attribute the backend (Staff::new_staff()) and existing
 * JS logic depend on is preserved verbatim — only DOM grouping changed.
 *
 * Source-regression tests (the view is HTML+JS — comment-stripping
 * convention matches every other view-touching test in this suite;
 * Firestore_service has no behavioral mock for controller code).
 */
class NewStaffWizardTest extends TestCase
{
    private string $viewSrc;

    protected function setUp(): void
    {
        $this->viewSrc = file_get_contents(__DIR__ . '/../../application/views/new_staff.php');
        $this->assertNotFalse($this->viewSrc);
    }

    // ── Backend contract preserved ──────────────────────────────────────

    public function test_every_backend_post_field_name_still_present(): void
    {
        // Staff::new_staff() reads these exact POST keys — a redesign must
        // never rename them, only regroup where they appear in the DOM.
        $names = [
            'Name', 'dob', 'email', 'gender', 'blood_group', 'religion', 'category',
            'father_name', 'phone_number', 'alt_phone', 'marital_status',
            'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
            'staff_roles', 'primary_role', 'department', 'designation',
            'reporting_manager_id', 'employment_type', 'date_of_joining', 'teacher_experience',
            'teaching_subjects',
            'pan_number', 'aadhar_number', 'pf_number', 'esi_number',
            'street', 'state', 'city', 'postal_code', 'same_as_current',
            'perm_street', 'perm_state', 'perm_city', 'perm_postal_code',
            'qualification', 'university', 'year_of_passing',
            'basicSalary', 'allowances',
            'bank_name', 'account_holder', 'account_number', 'bank_ifsc',
            'Photo',
        ];
        foreach ($names as $n) {
            $this->assertStringContainsString('name="' . $n . '"', $this->viewSrc, "Backend-required field name=\"{$n}\" must be preserved.");
        }

        // UX-D3: `Aadhar` (the file) is deliberately NOT in that list any more.
        // The card was uploadable both here and under HR Documents > Identity
        // Proof, giving one document two homes and two states. The NUMBER stays
        // (aadhar_number is asserted above); the CARD is collected once, after
        // joining, where issue and replacement are tracked per document type.
        //
        // Safe to drop: Staff::new_staff() guards the read with
        // `if (!empty($_FILES['Aadhar']['tmp_name']))`, so the branch simply
        // never fires. Asserted as ABSENT so a second upload control cannot
        // quietly reappear.
        $this->assertStringNotContainsString('name="Aadhar"', $this->viewSrc,
            'the Aadhar card belongs to HR Documents, not the hire form');
        $this->assertStringContainsString('nsa-doc-pointer', $this->viewSrc,
            'the form must say where documents are collected instead');
    }

    public function test_form_action_and_csrf_unchanged(): void
    {
        $this->assertStringContainsString('action="<?= base_url(\'staff/new_staff\') ?>"', $this->viewSrc);
        $this->assertStringContainsString('id="add_staff_form"', $this->viewSrc);
        $this->assertStringContainsString('enctype="multipart/form-data"', $this->viewSrc);
        $this->assertStringContainsString($this->securityTokenSnippet(), $this->viewSrc);
    }

    private function securityTokenSnippet(): string
    {
        return "get_csrf_token_name()";
    }

    // ── Organization fixes ──────────────────────────────────────────────

    public function test_designation_field_lives_in_employment_step_not_guardian(): void
    {
        $empStart = strpos($this->viewSrc, 'id="step-employment"');
        $empEnd   = strpos($this->viewSrc, 'id="step-statutory"');
        $this->assertNotFalse($empStart);
        $this->assertNotFalse($empEnd);
        $employmentChunk = substr($this->viewSrc, $empStart, $empEnd - $empStart);
        $this->assertStringContainsString('id="designation"', $employmentChunk, 'Designation must live in the Employment Details step.');

        $contactStart = strpos($this->viewSrc, 'id="step-contact"');
        $contactEnd   = strpos($this->viewSrc, 'id="step-employment"');
        $contactChunk = substr($this->viewSrc, $contactStart, $contactEnd - $contactStart);
        $this->assertStringNotContainsString('id="designation"', $contactChunk, 'Designation must NOT be inside Contact & Guardian anymore.');
    }

    public function test_statutory_identification_is_its_own_step_separate_from_banking(): void
    {
        $statStart = strpos($this->viewSrc, 'id="step-statutory"');
        $statEnd   = strpos($this->viewSrc, 'id="step-address"');
        $this->assertNotFalse($statStart);
        $this->assertNotFalse($statEnd);
        $statChunk = substr($this->viewSrc, $statStart, $statEnd - $statStart);
        foreach (['pan_number', 'aadhar_number', 'pf_number', 'esi_number'] as $f) {
            $this->assertStringContainsString('id="' . $f . '"', $statChunk);
        }
        // Must not be merged into Compensation & Banking.
        $bankStart = strpos($this->viewSrc, 'id="step-compensation"');
        $bankEnd   = strpos($this->viewSrc, 'id="step-review"');
        $bankChunk = substr($this->viewSrc, $bankStart, $bankEnd - $bankStart);
        $this->assertStringNotContainsString('id="pan_number"', $bankChunk);
    }

    public function test_sidebar_removed_no_stale_references(): void
    {
        foreach (['nsa-sidebar', 'nsa-nav-item', 'nsa-layout', 'preview_button', 'staffPreviewModalOverlay'] as $stale) {
            $this->assertStringNotContainsString($stale, $this->viewSrc, "Stale reference '{$stale}' from the old single-page layout must be fully removed.");
        }
    }

    // ── Wizard structure ────────────────────────────────────────────────

    public function test_eight_step_panels_present_in_order(): void
    {
        $expected = [
            'step-personal', 'step-contact', 'step-employment', 'step-statutory',
            'step-address', 'step-qualification', 'step-compensation', 'step-review',
        ];
        $lastPos = -1;
        foreach ($expected as $i => $id) {
            $pos = strpos($this->viewSrc, 'id="' . $id . '"');
            $this->assertNotFalse($pos, "Step '{$id}' must exist.");
            $this->assertGreaterThan($lastPos, $pos, "Step '{$id}' must come after the previous step in DOM order.");
            $lastPos = $pos;
            $this->assertStringContainsString('data-step="' . $i . '"', $this->viewSrc);
        }
    }

    public function test_wizard_steps_js_array_matches_eight_panels(): void
    {
        $this->assertStringContainsString("{ id: 'step-personal',      title: 'Personal Info',    icon: 'fa-user' },", $this->viewSrc);
        $this->assertStringContainsString("{ id: 'step-review',        title: 'Review & Create',  icon: 'fa-check-circle' }", $this->viewSrc);
        $this->assertEquals(8, substr_count($this->viewSrc, "{ id: 'step-"));
    }

    public function test_no_forward_skip_past_furthest_unlocked(): void
    {
        $this->assertStringContainsString('function wizardGoTo(i) {', $this->viewSrc);
        $this->assertStringContainsString('if (i > wizardFurthestUnlocked) return; // no skipping ahead', $this->viewSrc);
    }

    public function test_back_is_always_allowed(): void
    {
        $this->assertStringContainsString('function wizardBack() {', $this->viewSrc);
        $this->assertStringContainsString('if (wizardCurrentStep > 0) showStep(wizardCurrentStep - 1);', $this->viewSrc);
    }

    public function test_footer_has_back_save_continue_and_create(): void
    {
        $this->assertStringContainsString('id="wizardBackBtn"', $this->viewSrc);
        $this->assertStringContainsString('id="wizardNextBtn"', $this->viewSrc);
        $this->assertStringContainsString('id="wizardCreateBtn"', $this->viewSrc);
        $this->assertStringContainsString('Save &amp; Continue', $this->viewSrc);
        $this->assertStringContainsString('Create Staff', $this->viewSrc);
    }

    public function test_last_step_shows_create_not_next(): void
    {
        $this->assertStringContainsString("var isLast = (i === WIZARD_STEPS.length - 1);", $this->viewSrc);
        $this->assertStringContainsString("document.getElementById('wizardNextBtn').style.display = isLast ? 'none' : 'inline-flex';", $this->viewSrc);
        $this->assertStringContainsString("document.getElementById('wizardCreateBtn').style.display = isLast ? 'inline-flex' : 'none';", $this->viewSrc);
    }

    // ── Per-step validation ─────────────────────────────────────────────

    public function test_step_scoped_validation_exists(): void
    {
        $this->assertStringContainsString('function validateStepFields(sectionEl, stepIndex) {', $this->viewSrc);
        $this->assertStringContainsString('if (scopeEl && !scopeEl.contains(el)) return;', $this->viewSrc);
    }

    public function test_employment_step_requires_at_least_one_role(): void
    {
        $start = strpos($this->viewSrc, "WIZARD_STEPS[stepIndex].id === 'step-employment'");
        $this->assertNotFalse($start);
        $chunk = substr($this->viewSrc, $start, 500);
        $this->assertStringContainsString("if (!rolesHidden || !rolesHidden.value) {", $chunk);
    }

    public function test_teacher_role_requires_at_least_one_subject(): void
    {
        $start = strpos($this->viewSrc, "WIZARD_STEPS[stepIndex].id === 'step-employment'");
        $chunk = substr($this->viewSrc, $start, 700);
        $this->assertStringContainsString('isTeacherSelected()', $chunk);
        $this->assertStringContainsString("Add at least one subject this teacher can teach.", $chunk);
    }

    public function test_full_form_validator_exists_for_review_step_safety_net(): void
    {
        $this->assertStringContainsString('function validateStaffForm() {', $this->viewSrc);
        $this->assertStringContainsString('// Jump to the first step that actually has an error', $this->viewSrc);
    }

    // ── Draft autosave ──────────────────────────────────────────────────

    public function test_draft_autosave_never_persists_file_inputs(): void
    {
        // DRAFT_FIELD_IDS must not include the file input ids — a File object
        // cannot round-trip through localStorage/JSON.
        $start = strpos($this->viewSrc, 'var DRAFT_FIELD_IDS');
        $end   = strpos($this->viewSrc, '];', $start);
        $this->assertNotFalse($start);
        $chunk = substr($this->viewSrc, $start, $end - $start);
        $this->assertStringNotContainsString("'photo'", $chunk);
        $this->assertStringNotContainsString("'aadhar'", $chunk);
    }

    public function test_draft_save_is_debounced(): void
    {
        $this->assertStringContainsString('function wizardSaveDraft() {', $this->viewSrc);
        $this->assertStringContainsString('clearTimeout(_draftSaveTimer);', $this->viewSrc);
    }

    public function test_draft_cleared_on_successful_submit_and_start_over(): void
    {
        $submitStart = strpos($this->viewSrc, 'function submitStaffFinalForm()');
        $submitEnd   = strpos($this->viewSrc, "if (res.status === 'success')", $submitStart);
        $chunk = substr($this->viewSrc, $submitEnd, 200);
        $this->assertStringContainsString('wizardDiscardDraft();', $chunk);

        $startOverStart = strpos($this->viewSrc, 'function wizardStartOver()');
        $startOverChunk = substr($this->viewSrc, $startOverStart, 600);
        $this->assertStringContainsString('wizardDiscardDraft();', $startOverChunk);
    }

    public function test_draft_banner_offers_restore_and_discard(): void
    {
        $this->assertStringContainsString('id="draftBanner"', $this->viewSrc);
        $this->assertStringContainsString('onclick="wizardRestoreDraft()"', $this->viewSrc);
        $this->assertStringContainsString('onclick="wizardDiscardDraft()"', $this->viewSrc);
    }

    /**
     * Regression for a real bug (2026-08-18): showStep(0) — called during
     * page boot right after wizardCheckForDraft() — itself calls
     * wizardSaveDraft(). Since that save is debounced 350ms, it fired from
     * the still-blank form and silently overwrote the very draft the
     * banner was about to offer, before a human could ever click
     * "Restore". autosave must stay suppressed until the user has
     * actually decided (or there was nothing to decide).
     */
    public function test_autosave_is_suppressed_until_the_draft_decision_is_made(): void
    {
        $this->assertStringContainsString('var _draftDecided = true;', $this->viewSrc);
        $saveStart = strpos($this->viewSrc, 'function wizardSaveDraft()');
        $this->assertNotFalse($saveStart);
        $saveChunk = substr($this->viewSrc, $saveStart, 150);
        $this->assertStringContainsString('if (!_draftDecided) return;', $saveChunk);
    }

    public function test_checking_for_a_draft_marks_it_undecided_when_a_banner_is_shown(): void
    {
        $start = strpos($this->viewSrc, 'function wizardCheckForDraft()');
        $this->assertNotFalse($start);
        $end = strpos($this->viewSrc, "style.display = 'flex';", $start);
        $chunk = substr($this->viewSrc, $start, $end - $start);
        $this->assertStringContainsString('_draftDecided = false;', $chunk);
    }

    public function test_restore_and_discard_both_resolve_the_decision(): void
    {
        $restoreStart = strpos($this->viewSrc, 'function wizardRestoreDraft()');
        $this->assertNotFalse($restoreStart);
        $restoreEnd = strpos($this->viewSrc, 'function wizardCheckForDraft()');
        $restoreChunk = substr($this->viewSrc, $restoreStart, $restoreEnd - $restoreStart);
        $this->assertStringContainsString('_draftDecided = true;', $restoreChunk);

        $discardStart = strpos($this->viewSrc, 'function wizardDiscardDraft()');
        $discardEnd = strpos($this->viewSrc, 'function wizardRestoreDraft()');
        $discardChunk = substr($this->viewSrc, $discardStart, $discardEnd - $discardStart);
        $this->assertStringContainsString('_draftDecided = true;', $discardChunk);
    }

    public function test_department_restore_deferred_when_options_not_yet_loaded(): void
    {
        // teacher_department's <option>s load async (hr/get_departments) —
        // restoring must not silently no-op on a <select> with no matching
        // option yet; it must defer via the same _pendingDeptValue
        // mechanism the ATS-prefill path already uses.
        $start = strpos($this->viewSrc, 'function wizardRestoreDraft()');
        $end   = strpos($this->viewSrc, "if (data._sameAsCurrent)", $start);
        $chunk = substr($this->viewSrc, $start, $end - $start);
        $this->assertStringContainsString('if (_deptLoaded && deptSel) {', $chunk);
        $this->assertStringContainsString('_pendingDeptValue = data.teacher_department;', $chunk);
    }

    public function test_pending_dept_value_is_a_shared_global_not_scope_locked(): void
    {
        // Must be declared once, outside DOMContentLoaded, so both the
        // department-fetch resolver and wizardRestoreDraft() (defined at
        // top level) read/write the same variable.
        $this->assertEquals(1, substr_count($this->viewSrc, 'var _pendingDeptValue'));
        $declPos = strpos($this->viewSrc, 'var _pendingDeptValue');
        $domReadyPos = strpos($this->viewSrc, "addEventListener('DOMContentLoaded'");
        $this->assertLessThan($domReadyPos, $declPos, '_pendingDeptValue must be declared before DOMContentLoaded, not inside it.');
    }

    // ── DOM-clobbering safety ────────────────────────────────────────────

    public function test_no_element_id_shadows_a_global_function_name(): void
    {
        // A photo-upload regression (2026-08-18): the Review step's photo
        // <img id="previewStaffPhoto"> collided with the global
        // function previewStaffPhoto(event) used by the photo input's
        // onchange handler — browsers expose ids as window properties,
        // and the DOM element shadowed the function, throwing
        // "previewStaffPhoto is not a function" on upload. Guard against
        // any id in this file ever colliding with a top-level function name.
        preg_match_all('/^function ([a-zA-Z0-9_]+)/m', $this->viewSrc, $fnMatches);
        preg_match_all('/id="([a-zA-Z0-9_]+)"/', $this->viewSrc, $idMatches);
        $collisions = array_intersect($fnMatches[1], $idMatches[1]);
        $this->assertEmpty($collisions, 'Element id(s) collide with global function name(s): ' . implode(', ', $collisions));
    }

    public function test_review_photo_uses_the_non_colliding_id(): void
    {
        $this->assertStringContainsString('id="reviewStaffPhotoImg"', $this->viewSrc);
        $this->assertStringNotContainsString('id="previewStaffPhoto"', $this->viewSrc);
        $this->assertStringContainsString("getElementById('reviewStaffPhotoImg')", $this->viewSrc);
    }

    // ── Review & Create step ────────────────────────────────────────────

    public function test_review_step_is_inline_not_a_modal(): void
    {
        $reviewStart = strpos($this->viewSrc, 'id="step-review"');
        $this->assertNotFalse($reviewStart);
        $this->assertStringNotContainsString('nsa-overlay', substr($this->viewSrc, $reviewStart, 6000));
        $this->assertStringContainsString('id="previewStaffName"', $this->viewSrc);
    }

    public function test_review_step_populated_on_entry(): void
    {
        $this->assertStringContainsString('if (isLast) {', $this->viewSrc);
        $this->assertStringContainsString('fillStaffPreviewData();', $this->viewSrc);
    }

    public function test_create_staff_button_calls_final_submit_directly(): void
    {
        $this->assertStringContainsString('id="wizardCreateBtn" style="display:none;" onclick="submitStaffFinalForm()"', $this->viewSrc);
    }
}
