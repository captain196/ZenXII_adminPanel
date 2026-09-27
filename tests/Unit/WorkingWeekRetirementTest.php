<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * N-36 (Workforce Policy Programme Phase C) — retire the interim
 * `workingWeek` policy screen. Superseded by Work Schedules (Phase 1), which
 * every consumer (Payroll/Phase A, Staff Attendance punch+finaliser+grid/
 * N-35) now resolves through via Policy_assignment_service.
 *
 * Deliberately conservative: nothing is deleted. `Working_week.php`'s body,
 * `Working_calendar_service::effectiveVersion()`/`resolveForMonth()`, and any
 * `attendancePolicy.workingWeek` data a tenant already wrote are left fully
 * intact — only reachability is cut (a guard in the controller constructor)
 * and discoverability (the settings-page card is now a pointer, not a form).
 * This keeps the retirement a small, fully reversible change with zero data
 * risk, consistent with this repo's "don't delete legacy data merely because
 * the code no longer uses it" convention.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code — see StaffCreationAuditMetadataTest's docblock).
 */
class WorkingWeekRetirementTest extends TestCase
{
    private string $controllerSrc;
    private string $settingsViewSrc;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Working_week.php');
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
        $this->controllerSrc = $out;

        $this->settingsViewSrc = file_get_contents(__DIR__ . '/../../application/views/attendance/settings.php');
        $this->assertNotFalse($this->settingsViewSrc);
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

    // ── Controller: guarded, not deleted ───────────────────────────────────

    public function test_constructor_guards_every_endpoint_closed(): void
    {
        $start = strpos($this->controllerSrc, 'function __construct(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->controllerSrc, $start);
        $this->assertStringContainsString("\$this->json_error(", $body,
            'The constructor must refuse every request before any endpoint logic runs.');
        $this->assertStringContainsString('410', $body);
    }

    public function test_file_still_exists_not_deleted(): void
    {
        $this->assertFileExists(__DIR__ . '/../../application/controllers/Working_week.php');
        // The endpoints themselves remain in source (unreachable, not erased)
        // — confirms this is a guard, not a deletion.
        $this->assertStringContainsString('function get_policy', $this->controllerSrc);
        $this->assertStringContainsString('function save_version', $this->controllerSrc);
        $this->assertStringContainsString('function preview', $this->controllerSrc);
    }

    // ── Calendar maths library: untouched ──────────────────────────────────

    public function test_working_calendar_service_methods_not_removed(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Working_calendar_service.php');
        // Left intact deliberately — Working_week.php's (now-unreachable) body
        // still calls these; removing them would be a second, unnecessary
        // change riding along with the retirement.
        $this->assertStringContainsString('function effectiveVersion(', $src);
        $this->assertStringContainsString('function resolveForMonth(', $src);
        $this->assertStringContainsString('function legacyRuleDescription(', $src);
    }

    // ── Settings view: card is a pointer, JS wiring removed ────────────────

    public function test_settings_card_points_to_work_schedules_not_a_form(): void
    {
        $this->assertStringContainsString("base_url('work_schedules')", $this->settingsViewSrc);
        $this->assertStringNotContainsString('id="wwWeeklyOffs"', $this->settingsViewSrc);
        $this->assertStringNotContainsString('id="wwSave"', $this->settingsViewSrc);
    }

    public function test_settings_view_js_wiring_removed(): void
    {
        foreach (['function wwInit(', 'function wwLoad(', 'function wwPreview(',
                  'function wwDraft(', "getData('working_week/get_policy')",
                  "postData('working_week/save_version'"] as $needle) {
            $this->assertStringNotContainsString($needle, $this->settingsViewSrc, $needle);
        }
    }

    public function test_rest_days_extra_work_card_untouched(): void
    {
        // A DIFFERENT, still-live legacy field (root attendancePolicy.weeklyOffs,
        // used only as the Work Schedules create-form pre-fill suggestion,
        // D4) — out of N-36's scope, must not be touched by this retirement.
        $this->assertStringContainsString('id="weeklyOffsGroup"', $this->settingsViewSrc);
    }

    // ── No new architecture, no data touched ───────────────────────────────

    public function test_no_new_rbac_or_collection_introduced(): void
    {
        $this->assertStringNotContainsString('token.role', $this->controllerSrc);
        $this->assertStringNotContainsString('workingWeekArchive', $this->controllerSrc);
    }
}
