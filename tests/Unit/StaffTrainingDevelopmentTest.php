<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 7: Training / Development
 * (2026-08-19).
 *
 * Confirmed green-field by a dedicated research pass: no training,
 * certification, or skill-development concept existed anywhere for staff.
 * Modeled directly on this SAME controller's Appraisals section — the
 * closest existing precedent for "an independently CRUD-able, per-staff HR
 * record type" — but deliberately WITHOUT Appraisals' Draft→Submitted→
 * Reviewed workflow: a training/certification record is a factual log
 * entry, not a graded evaluation requiring a review gate.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller/view code).
 */
class StaffTrainingDevelopmentTest extends TestCase
{
    private string $hrSrc;
    private string $viewSrc;
    private string $routesSrc;

    protected function setUp(): void
    {
        foreach ([
            'hrSrc'     => __DIR__ . '/../../application/controllers/Hr.php',
            'viewSrc'   => __DIR__ . '/../../application/views/hr/index.php',
            'routesSrc' => __DIR__ . '/../../application/config/routes.php',
        ] as $prop => $path) {
            $raw = file_get_contents($path);
            $this->assertNotFalse($raw);
            $out = '';
            foreach (token_get_all($raw) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                    $out .= $token[1];
                } else {
                    $out .= $token;
                }
            }
            $this->$prop = $out;
        }
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function fn(string $name): string
    {
        $start = strpos($this->hrSrc, 'function ' . $name . '(');
        $this->assertNotFalse($start, "{$name}() must exist in Hr.php.");
        return $this->extractFunctionBody($this->hrSrc, $start);
    }

    // ── Routing ────────────────────────────────────────────────────────

    public function test_routes_registered_for_all_three_endpoints_and_the_tab(): void
    {
        $this->assertStringContainsString("\$route['hr/get_training_records']", $this->routesSrc);
        $this->assertStringContainsString("\$route['hr/save_training_record']", $this->routesSrc);
        $this->assertStringContainsString("\$route['hr/delete_training_record']", $this->routesSrc);
        $this->assertStringContainsString("\$route['hr/training']", $this->routesSrc);
    }

    public function test_training_tab_is_in_the_allowed_list(): void
    {
        $start = strpos($this->hrSrc, 'function index(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $this->assertStringContainsString("'training'", $body);
    }

    // ── Constants ──────────────────────────────────────────────────────

    public function test_statuses_and_categories_defined(): void
    {
        $this->assertStringContainsString("TRAINING_STATUSES = ['Planned', 'In Progress', 'Completed'];", $this->hrSrc);
        $this->assertStringContainsString("TRAINING_CATEGORIES = ['Training', 'Certification', 'Workshop', 'Course'];", $this->hrSrc);
    }

    // ── get_training_records() ────────────────────────────────────────

    public function test_get_training_records_is_view_gated_and_read_only(): void
    {
        $body = $this->fn('get_training_records');
        $this->assertStringContainsString("'HR', 'view'", $body);
        $this->assertStringNotContainsString('setEntity', $body);
        $this->assertStringNotContainsString('removeEntity', $body);
    }

    public function test_get_training_records_supports_the_three_filters(): void
    {
        $body = $this->fn('get_training_records');
        $this->assertStringContainsString("['staff_id', '==', \$filterStaffId]", $body);
        $this->assertStringContainsString("['status', '==', \$filterStatus]", $body);
        $this->assertStringContainsString("['category', '==', \$filterCategory]", $body);
    }

    // ── save_training_record() ────────────────────────────────────────

    public function test_save_training_record_is_edit_gated(): void
    {
        $body = $this->fn('save_training_record');
        $this->assertStringContainsString("'HR', 'edit'", $body);
    }

    public function test_save_training_record_validates_category_and_status_enums(): void
    {
        $body = $this->fn('save_training_record');
        $this->assertStringContainsString('in_array($category, self::TRAINING_CATEGORIES, true)', $body);
        $this->assertStringContainsString('in_array($status, self::TRAINING_STATUSES, true)', $body);
    }

    public function test_save_training_record_requires_completed_date_only_when_status_is_completed(): void
    {
        $body = $this->fn('save_training_record');
        $this->assertStringContainsString("\$status === 'Completed' && \$completedDate === ''", $body);
        // Must NOT require it unconditionally — Planned/In Progress records
        // legitimately have no completed date yet.
        $this->assertStringNotContainsString("if (\$completedDate === '') \$this->json_error", $body);
    }

    public function test_save_training_record_blocks_inactive_staff_same_as_appraisals(): void
    {
        $body = $this->fn('save_training_record');
        $this->assertStringContainsString("strtolower(trim(\$rawStatus)) !== 'active'", $body);
    }

    public function test_save_training_record_has_no_submitted_reviewed_workflow(): void
    {
        // The deliberate design boundary vs. Appraisals: a training record
        // is a factual log, editable any time, no review-gate states.
        $body = $this->fn('save_training_record');
        $this->assertStringNotContainsString('Submitted', $body);
        $this->assertStringNotContainsString('Reviewed', $body);
        $this->assertStringNotContainsString('Draft', $body);
    }

    public function test_save_training_record_dual_emits_camelcase_mirror(): void
    {
        $body = $this->fn('save_training_record');
        $this->assertStringContainsString("'staffId'         => \$staffId,", $body);
        $this->assertStringContainsString("'completedDate'   => \$completedDate,", $body);
    }

    // ── delete_training_record() ──────────────────────────────────────

    public function test_delete_training_record_is_manage_gated(): void
    {
        $body = $this->fn('delete_training_record');
        $this->assertStringContainsString("'HR', 'manage'", $body);
    }

    public function test_delete_training_record_has_no_draft_only_restriction(): void
    {
        // Unlike delete_appraisal() (Draft-only), any training record can
        // be deleted by an HR:manage holder — there's no review state to
        // protect.
        $body = $this->fn('delete_training_record');
        $this->assertStringNotContainsString("!== 'Draft'", $body);
    }

    // ── No second writer / no entanglement ────────────────────────────

    public function test_training_endpoints_never_touch_unrelated_collections(): void
    {
        foreach (['get_training_records', 'save_training_record', 'delete_training_record'] as $f) {
            $body = $this->fn($f);
            $this->assertStringNotContainsString('staffScheduleAssignments', $body);
            $this->assertStringNotContainsString('staffEmploymentTimeline', $body);
            $this->assertStringNotContainsString('salarySlips', $body);
            $this->assertStringNotContainsString("'appraisals'", $body);
        }
    }

    public function test_no_certificate_upload_logic_reinvented_here(): void
    {
        // Certificates attach via the EXISTING canonical Document Service
        // (Staff::upload_staff_document(), Phase A) — never a second,
        // parallel upload path.
        $body = $this->fn('save_training_record');
        $this->assertStringNotContainsString('$_FILES', $body);
        $this->assertStringNotContainsString('upload_staff_document', $body);
        $this->assertStringNotContainsString('Document_service', $body);
    }

    // ── View wiring ────────────────────────────────────────────────────

    public function test_training_panel_and_tab_registered_in_the_view(): void
    {
        $this->assertStringContainsString("'training'    => ['panel'=>'panelTraining',", $this->viewSrc);
        $this->assertStringContainsString('id="panelTraining"', $this->viewSrc);
        $this->assertStringContainsString("case 'training':     loadTrainingRecords(); break;", $this->viewSrc);
    }

    public function test_training_js_calls_the_correct_three_backend_endpoints(): void
    {
        $this->assertStringContainsString("'hr/get_training_records'", $this->viewSrc);
        $this->assertStringContainsString("post('hr/save_training_record'", $this->viewSrc);
        $this->assertStringContainsString("post('hr/delete_training_record'", $this->viewSrc);
    }

    public function test_training_functions_exposed_on_the_hr_namespace(): void
    {
        $start = strpos($this->viewSrc, 'window.HR = {');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->viewSrc, $start);
        foreach (['openTrainingModal', 'editTrainingRecord', 'saveTrainingRecord', 'deleteTrainingRecord', 'loadTrainingRecords'] as $name) {
            $this->assertStringContainsString($name, $body);
        }
    }

    // ── No DOM-id-shadows-global-function-name regression, scoped to the
    // Phase 7 additions themselves — hr/index.php is a large pre-existing
    // file with its own established id/function surface; auditing the
    // WHOLE file for every pre-existing pair is out of scope for this
    // change (and at least one such pair, deptName, is a true hoisted
    // function DECLARATION, which safely overwrites a DOM-clobbered global
    // the moment its script block runs — a different risk profile from the
    // previewStaffPhoto bug this pattern was originally written to catch,
    // which involved a function EXPRESSION assignment, not a declaration).
    public function test_new_training_ids_do_not_shadow_new_training_function_names(): void
    {
        $newIds = [
            'panelTraining', 'filterTrainingStaff', 'filterTrainingCategory', 'filterTrainingStatus',
            'tblTraining', 'modalTraining', 'modalTrainingTitle', 'trainingId', 'trStaff', 'trTitle',
            'trProvider', 'trCategory', 'trStatus', 'trHours', 'trCompletedDate', 'trExpiryDate', 'trNotes',
        ];
        $newFns = [
            'loadTrainingRecords', 'onTrStatusChange', 'openTrainingModal', 'editTrainingRecord',
            'saveTrainingRecord', 'deleteTrainingRecord',
        ];
        $collisions = array_intersect($newIds, $newFns);
        $this->assertEmpty($collisions, 'New Phase 7 id(s) shadow new Phase 7 function name(s): ' . implode(', ', $collisions));
        foreach ($newIds as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $this->viewSrc, "expected id={$id} to exist");
        }
        foreach ($newFns as $fnName) {
            $this->assertStringContainsString('function ' . $fnName . '(', $this->viewSrc, "expected function {$fnName}() to exist");
        }
    }
}
