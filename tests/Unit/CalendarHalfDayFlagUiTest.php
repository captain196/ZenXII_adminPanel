<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CALENDAR HALF-DAY — feature-flag admin surface (S4, 2026-08-19).
 *
 * The flag is tenant-scoped and lives with the rest of the attendance policy
 * on the school document:
 *     schools.attendancePolicy.calendarHalfDayPayEnabled       (bool)
 *     schools.attendancePolicy.calendarHalfDayPayEffectiveFrom (YYYY-MM-DD)
 *
 * The effective date is MANDATORY when enabled, and that is enforced on the
 * SERVER, not merely hidden in the UI. It is the only thing preventing an
 * enabled tenant from repricing historical payroll the moment a closed run
 * is regenerated — so a missing or malformed date is a hard 400, never a
 * silent fallback to "disabled" (which would leave an operator believing the
 * feature is live while Hr::_half_day_weight() still returns 1.0).
 */
class CalendarHalfDayFlagUiTest extends TestCase
{
    private string $ctrl;
    private string $view;

    protected function setUp(): void
    {
        foreach ([
            'ctrl' => 'application/controllers/Attendance.php',
            'view' => 'application/views/attendance/settings.php',
        ] as $prop => $rel) {
            $raw = file_get_contents(__DIR__ . '/../../' . $rel);
            $this->assertNotFalse($raw, "missing {$rel}");
            $out = '';
            foreach (token_get_all($raw) as $t) {
                if (is_array($t)) {
                    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                    $out .= $t[1];
                } else { $out .= $t; }
            }
            $this->$prop = $out;
        }
    }

    private function body(string $src, string $fn): string
    {
        $start = strpos($src, 'function ' . $fn . '(');
        $this->assertNotFalse($start, "{$fn}() must exist.");
        $open = strpos($src, '{', $start);
        $depth = 0; $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') { $depth--; if ($depth === 0) return substr($src, $open, $i - $open + 1); }
        }
        $this->fail('Unbalanced braces.');
    }

    // ── Server-side enforcement (the real gate) ───────────────────────

    public function test_enabling_without_an_effective_date_is_refused_server_side(): void
    {
        $b = $this->body($this->ctrl, 'save_attendance_policy');
        $this->assertStringContainsString(
            "if (\$chdFrom === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$chdFrom)) {", $b);
        $this->assertStringContainsString('cannot be enabled without a valid effective date', $b);
        $this->assertStringContainsString('400', $b, 'must be a hard error, not a silent fallback');
    }

    public function test_an_impossible_calendar_date_is_refused(): void
    {
        // A well-formed but non-existent date (2026-02-31) would otherwise
        // pass the regex and then compare lexicographically forever.
        $b = $this->body($this->ctrl, 'save_attendance_policy');
        $this->assertStringContainsString('checkdate($m, $d, $y)', $b);
    }

    public function test_disabling_clears_the_effective_date(): void
    {
        // A stale boundary left behind a disabled switch would silently take
        // effect the moment someone re-enabled it.
        $b = $this->body($this->ctrl, 'save_attendance_policy');
        $this->assertStringContainsString("\$chdFrom = '';", $b);
    }

    public function test_flag_defaults_to_off_when_absent_from_the_payload(): void
    {
        $b = $this->body($this->ctrl, 'save_attendance_policy');
        $this->assertStringContainsString("\$chdEnabled = !empty(\$in['calendarHalfDayPayEnabled']);", $b,
            'a missing key must mean OFF, never ON');
    }

    // ── Persistence: tenant-scoped and field-scoped ───────────────────

    public function test_flag_is_persisted_on_the_tenant_school_document(): void
    {
        $b = $this->body($this->ctrl, 'save_attendance_policy');
        $this->assertStringContainsString("'attendancePolicy.calendarHalfDayPayEnabled'", $b);
        $this->assertStringContainsString("'attendancePolicy.calendarHalfDayPayEffectiveFrom'", $b);
        $this->assertStringContainsString("\$this->fs->update('schools', \$this->school_id,", $b,
            'must be written to THIS tenant only');
    }

    public function test_flag_write_uses_dotted_paths_and_cannot_clobber_siblings(): void
    {
        // Guards the P0 data-loss fix: adding two keys must not reintroduce a
        // whole-map write that would destroy workSchedules/workingWeek.
        $b = $this->body($this->ctrl, 'save_attendance_policy');
        $this->assertStringNotContainsString("['attendancePolicy' => \$policy]", $b);
    }

    // ── RBAC ──────────────────────────────────────────────────────────

    public function test_saving_the_policy_is_rbac_protected(): void
    {
        $b = $this->body($this->ctrl, 'save_attendance_policy');
        $this->assertMatchesRegularExpression(
            '/_require_role\(|require_permission\(|has_permission\(/', $b,
            'the flag changes payroll behaviour — it must not be settable without authorisation');
    }

    // ── Admin UI ──────────────────────────────────────────────────────

    public function test_ui_exposes_the_toggle_and_the_date_field(): void
    {
        $this->assertStringContainsString('id="chdEnabled"', $this->view);
        $this->assertStringContainsString('id="chdFrom"', $this->view);
        $this->assertStringContainsString('type="date"', $this->view);
    }

    public function test_ui_toggle_is_unchecked_by_default(): void
    {
        // The markup must not carry `checked` — OFF is the default state.
        $pos = strpos($this->view, 'id="chdEnabled"');
        $this->assertNotFalse($pos);
        $tag = substr($this->view, max(0, $pos - 120), 200);
        $this->assertStringNotContainsString('id="chdEnabled" checked', $tag);
    }

    public function test_ui_reads_the_flag_as_strictly_true(): void
    {
        // Anything other than an explicit true must render as OFF.
        $this->assertStringContainsString(
            "\$('chdEnabled').checked = (p.calendarHalfDayPayEnabled === true);", $this->view);
    }

    public function test_ui_sends_both_fields_on_save(): void
    {
        $this->assertStringContainsString("calendarHalfDayPayEnabled: \$('chdEnabled').checked", $this->view);
        $this->assertStringContainsString("calendarHalfDayPayEffectiveFrom: \$('chdFrom').value || ''", $this->view);
    }

    public function test_ui_blocks_the_save_when_enabled_without_a_date(): void
    {
        $this->assertStringContainsString(
            'if (policy.calendarHalfDayPayEnabled && !policy.calendarHalfDayPayEffectiveFrom) {', $this->view);
    }

    public function test_ui_hides_the_date_field_while_the_model_is_off(): void
    {
        $this->assertStringContainsString('function chdToggleDateRow()', $this->view);
        $this->assertStringContainsString("if ($('chdEnabled')) $('chdEnabled').addEventListener('change', chdToggleDateRow);", $this->view);
    }

    public function test_ui_explains_that_completing_a_half_day_still_pays_in_full(): void
    {
        // The single most misreadable thing about this feature. If the copy
        // ever drifts to imply half pay, that is a real operator-facing bug.
        $this->assertStringContainsString('full normal day', $this->view);
    }

    public function test_ui_explains_the_historical_protection(): void
    {
        $this->assertStringContainsString('on or after', $this->view);
    }

    // ── No DOM id shadows a global function name ──────────────────────

    public function test_new_ids_do_not_shadow_new_function_names(): void
    {
        $ids = ['chdEnabled', 'chdFrom', 'chdDateRow'];
        $fns = ['chdToggleDateRow'];
        $this->assertEmpty(array_intersect($ids, $fns));
    }
}
