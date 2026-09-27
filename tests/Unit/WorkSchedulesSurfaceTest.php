<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PHASE 1 — the schedules + assignments admin surface.
 *
 * `Work_schedules` extends MY_Controller and cannot be instantiated under
 * PHPUnit, so these are literal-source-regression guards — the same style as
 * HomeworkSchoolIdPredicateTest. They hold the properties that cannot be
 * checked by reading the file once and hoping it stays that way:
 *
 *   B8  every read and write is tenant-scoped
 *   B4  the surface never invents a schedule to unblock anyone
 *   D4  weeklyOffs is offered as a suggestion, never adopted automatically
 *       fail-closed panel JS (r.ok AND status)
 */
class WorkSchedulesSurfaceTest extends TestCase
{
    private const CTRL = __DIR__ . '/../../application/controllers/Work_schedules.php';
    private const VIEW = __DIR__ . '/../../application/views/work_schedules/index.php';

    private function controller(): string { return (string) file_get_contents(self::CTRL); }
    private function view(): string       { return (string) file_get_contents(self::VIEW); }

    /**
     * The view is HTML + CSS + JS, so token_get_all() cannot strip its comments.
     * Guards that assert a literal is ABSENT must read this, not view() — a
     * comment explaining why something is banned otherwise trips its own guard.
     */
    private function viewCode(): string
    {
        $v = (string) file_get_contents(self::VIEW);
        $v = preg_replace('/<!--.*?-->/s', '', $v);      // HTML
        $v = preg_replace('~/\*.*?\*/~s', '', $v);       // JS + CSS block
        $v = preg_replace('~^\s*//.*$~m', '', $v);       // JS line
        return (string) $v;
    }

    /** Code only — prose must neither satisfy nor trip a guard. */
    private function code(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    public function test_files_exist(): void
    {
        $this->assertFileExists(self::CTRL);
        $this->assertFileExists(self::VIEW);
    }

    /**
     * REGRESSION: CI's loader does not resolve STATIC calls, so a class used as
     * `X_service::CONST` must be required explicitly. Missing one produces a
     * fatal only on the endpoint that touches it — `save_schedule` fatalled on
     * `Working_calendar_service::DAYS` while every other endpoint worked.
     */
    /**
     * REGRESSION: `$this->fs->delete()` does not exist — Firestore_service
     * exposes remove()/removeEntity(). A non-existent method is a fatal only on
     * the endpoint that calls it, so delete_assignment 500'd while every other
     * endpoint worked. Assert every method called on $this->fs is real.
     */
    public function test_every_firestore_method_called_actually_exists(): void
    {
        $svc = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Firestore_service.php');

        preg_match_all('/\$this->fs->([a-zA-Z_]+)\(/', $this->code($this->controller()), $m);

        foreach (array_unique($m[1]) as $method) {
            $this->assertMatchesRegularExpression(
                '/public function ' . preg_quote($method, '/') . '\s*\(/', $svc,
                "Firestore_service has no {$method}() — that endpoint will fatal at runtime");
        }
    }

    public function test_every_statically_used_service_is_loaded(): void
    {
        // Comment-stripped: a class NAMED in a docblock is not a static call.
        $src = $this->code($this->controller());
        preg_match_all('/\b([A-Z][A-Za-z_]*_service)::/', $src, $m);

        foreach (array_unique($m[1]) as $cls) {
            $required = str_contains($src, "libraries/{$cls}.php");
            $loaded   = str_contains($src, "load->library('{$cls}')");
            $this->assertTrue($required || $loaded,
                $cls . ' is used statically but never required — that endpoint will fatal');
        }
    }

    // ── B8 · tenant isolation ────────────────────────────────────────────

    /** Every collection query must be tenant-scoped. A raw query() is the bug. */
    public function test_no_unscoped_collection_query(): void
    {
        $code = $this->code($this->controller());
        $this->assertSame(0, substr_count($code, '$this->fs->query('),
            'raw query() is never tenant-scoped — use schoolWhere()');
    }

    public function test_reads_use_school_scoped_helpers(): void
    {
        $code = $this->code($this->controller());
        $this->assertGreaterThan(0, substr_count($code, 'schoolWhere('));
    }

    /** Assignment documents must be written through docId(), never a raw id. */
    public function test_writes_are_doc_id_scoped(): void
    {
        $code = $this->code($this->controller());
        $this->assertSame(0, substr_count($code, '->set(self::COLLECTION, $assignmentId'),
            'a bare id would collide across tenants — docId() prefixes the school');
        $this->assertGreaterThan(0, substr_count($code, '$this->fs->docId('));
    }

    /** The school document itself is addressed by the controller's own school_id. */
    public function test_school_doc_reads_are_scoped(): void
    {
        $code = $this->code($this->controller());
        $this->assertSame(0, substr_count($code, "get('schools', \$id"));
        $this->assertGreaterThan(0, substr_count($code, "'schools', \$this->school_id"));
    }

    // ── RBAC ─────────────────────────────────────────────────────────────

    public function test_every_write_endpoint_requires_manage(): void
    {
        $code = $this->code($this->controller());
        // save_schedule, delete_schedule, save_assignment, delete_assignment
        $this->assertSame(4, substr_count($code, '$this->_require_manage()'));
    }

    public function test_read_endpoints_require_permission(): void
    {
        $code = $this->code($this->controller());
        $this->assertGreaterThan(0, substr_count($code, '$this->_require_view()'));
        $this->assertGreaterThan(0, substr_count($code, "require_permission('HR'"));
    }

    // ── B4 · no invented schedule ────────────────────────────────────────

    public function test_surface_never_invents_a_schedule(): void
    {
        $code = $this->code($this->controller());
        $this->assertSame(0, substr_count($code, 'DEFAULT_SCHEDULE'));
        $this->assertSame(0, substr_count($code, 'defaultSchedule'));
        $this->assertSame(0, substr_count($code, 'satCount'));
        $this->assertSame(0, substr_count($code, "date('w'"));
    }

    /**
     * D4 — the controller must not copy weeklyOffs into a schedule. It may READ
     * the field to offer it; writing it into workSchedules without a human
     * saving the form is precisely the inference N-23 was caused by.
     */
    public function test_weekly_offs_is_never_written_into_a_schedule(): void
    {
        $code = $this->code($this->controller());
        $this->assertSame(0, substr_count($code, "'weeklyOffs' => \$policy['weeklyOffs']"));
        $this->assertSame(0, substr_count($code, "'workSchedules' => [\$policy"));
    }

    // ── Delete guards ────────────────────────────────────────────────────

    public function test_schedule_delete_is_guarded_by_usage(): void
    {
        $code = $this->code($this->controller());
        $this->assertGreaterThan(0, substr_count($code, '$inUse'),
            'deleting an in-use schedule would silently block every employee on it');
    }

    public function test_assignment_save_refuses_overlaps_before_writing(): void
    {
        $code = $this->code($this->controller());
        $this->assertGreaterThan(0, substr_count($code, 'findOverlaps('));
        // The refusal must come before the write, not after.
        $this->assertLessThan(
            strpos($code, '$this->fs->set(self::COLLECTION'),
            strpos($code, 'findOverlaps('),
            'overlap check must precede the write so an ambiguous state is never persisted');
    }

    public function test_assignment_save_validates_through_the_engine(): void
    {
        $code = $this->code($this->controller());
        $this->assertGreaterThan(0,
            substr_count($code, 'Policy_assignment_service::validateAssignment('));
    }

    // ── Panel JS must fail closed ────────────────────────────────────────

    public function test_panel_js_checks_both_http_and_payload_status(): void
    {
        $v = $this->view();
        $this->assertGreaterThan(0, substr_count($v, 'if (!r.ok)'),
            'fetch() does not reject on 403/500 — r.ok must be checked');
        $this->assertGreaterThan(0, substr_count($v, "j.status === 'error'"),
            'a denied action must not render as success');
    }

    /**
     * REGRESSION: throwing on `!r.ok` before reading the body discarded every
     * server-side explanation. json_error() replies 400 WITH a message, so the
     * overlap refusal, the delete-in-use count and every validation reason were
     * replaced on screen by "Request failed (400)".
     */
    public function test_server_error_messages_are_read_from_the_body(): void
    {
        $v = $this->viewCode();
        $this->assertSame(0, substr_count($v, "if (!r.ok) throw new Error('Request failed"),
            'a non-2xx body still carries the reason — read it before throwing');
        $this->assertGreaterThan(0, substr_count($v, 'r.text()'),
            'the response body must be read on every status, not only 2xx');
        $this->assertGreaterThan(0, substr_count($v, 'j.message'));
    }

    /** Destructive actions confirm in-theme, never via the browser's chrome box. */
    public function test_no_native_browser_dialogs(): void
    {
        $v = $this->viewCode();
        foreach (['window.confirm(', '!confirm(', 'window.alert(', 'window.prompt('] as $native) {
            $this->assertSame(0, substr_count($v, $native),
                $native . ' renders browser chrome, not the product');
        }
        $this->assertGreaterThan(0, substr_count($v, 'confirmAction('));
    }

    public function test_panel_sends_the_csrf_token(): void
    {
        $this->assertGreaterThan(0, substr_count($this->view(), 'csrf-token'));
    }

    /**
     * REGRESSION: `.wsx-modal` sets `display:flex`, which overrides the UA
     * stylesheet's `[hidden] { display:none }`. Without an explicit rule the
     * overlays stay on screen at `inset:0` and swallow every click on the page
     * — the whole screen looks loaded but nothing is clickable.
     */
    public function test_modal_overlays_are_actually_hidden_when_hidden(): void
    {
        $v = $this->view();
        $this->assertMatchesRegularExpression(
            '/\.wsx-modal\[hidden\]\s*\{\s*display\s*:\s*none/',
            $v,
            'a fixed-position overlay with an author display rule needs an explicit [hidden] rule');
    }

    /**
     * Any element the script toggles via `.hidden` must not carry a class whose
     * CSS sets `display`, or the same trap returns somewhere else.
     */
    public function test_no_other_toggled_element_overrides_hidden(): void
    {
        $v = $this->view();
        foreach (['wsx-card', 'wsx-alert', 'wsx-suggest'] as $cls) {
            $this->assertSame(0, preg_match('/\.' . $cls . '\{[^}]*display\s*:/', $v),
                $cls . ' sets display, so [hidden] would stop working on it');
        }
        // Containers that DO set display must carry their own [hidden] rule.
        foreach (['wsx-modal', 'wsx-groups'] as $cls) {
            $this->assertMatchesRegularExpression(
                '/\.' . $cls . '\[hidden\]\s*\{\s*display\s*:\s*none/', $v,
                $cls . ' sets display and is toggled via .hidden — it needs an explicit [hidden] rule');
        }
    }

    // ── The blocked-employee panel is the point of the screen ────────────

    /**
     * REGRESSION: staff docs default `status` to 'Active' — CAPITAL A
     * (Firestore_service.php:1072). A case-sensitive `!== 'active'` skipped
     * every employee, so the blocked panel rendered EMPTY and read as "nobody
     * is blocked" when in truth nobody was configured. A silent wrong answer,
     * worse than an error.
     */
    public function test_staff_status_comparison_is_case_insensitive(): void
    {
        $code = $this->code($this->controller());
        $this->assertSame(0, substr_count($code, "!== 'active'"),
            "staff status is stored as 'Active' — a case-sensitive compare hides everyone");
        $this->assertGreaterThan(0, substr_count($code, 'strtolower'));
    }

    /** Staff docs carry camelCase and capitalised variants of the same field. */
    public function test_staff_field_variants_are_handled(): void
    {
        $code = $this->code($this->controller());
        foreach (["\$s['Department']", "\$s['staffId']", "\$s['Status']"] as $variant) {
            $this->assertStringContainsString($variant, $code,
                'missing a field variant means silently dropping records');
        }
    }

    public function test_blocked_employees_are_surfaced_with_reasons(): void
    {
        $code = $this->code($this->controller());
        $this->assertGreaterThan(0, substr_count($code, '_blockedStaff('));
        $this->assertGreaterThan(0, substr_count($code, 'Policy_assignment_service::explain('),
            'a blocked employee must come with the reason, not just the fact');
    }

    /** Legacy staff-only assignments must still be readable — no migration. */
    /**
     * Originally asserted the normalisation literal directly in this
     * controller — superseded by the Payroll resolver convergence
     * (2026-08-18), which needed the identical normalisation and moved it to
     * `Work_schedule_service::normaliseAssignment()` so the logic lives once,
     * not twice. The controller still normalises every legacy record (it now
     * delegates), which is what this guard actually cares about.
     */
    public function test_legacy_assignment_shape_is_normalised(): void
    {
        $code = $this->code($this->controller());
        $this->assertGreaterThan(0, substr_count($code, '_normaliseAssignment('));
        $this->assertStringContainsString('Work_schedule_service::normaliseAssignment(', $code);

        $libCode = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Work_schedule_service.php');
        $out = '';
        foreach (token_get_all($libCode) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= is_array($t) ? $t[1] : $t;
        }
        $this->assertGreaterThan(0, substr_count($out, "\$a['staffId']"),
            'pre-Phase-1 records carry only staffId and must keep working');
    }

    // ── Routes ───────────────────────────────────────────────────────────

    public function test_all_endpoints_are_routed(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');
        foreach (['get_data', 'preview', 'save_schedule', 'delete_schedule',
                  'save_assignment', 'delete_assignment'] as $ep) {
            $this->assertStringContainsString('work_schedules/' . $ep, $routes);
        }
    }

    // ── Firestore rules ──────────────────────────────────────────────────

    public function test_assignment_collection_is_server_write_only(): void
    {
        $rules = (string) file_get_contents(__DIR__ . '/../../firebase-rules/firestore.rules');
        $this->assertStringContainsString('match /staffScheduleAssignments/{docId}', $rules);

        $pos = strpos($rules, 'match /staffScheduleAssignments/{docId}');
        $block = substr($rules, $pos, 260);
        $this->assertStringContainsString('allow write: if false;', $block,
            'the payroll denominator must never be client-writable');
        $this->assertStringContainsString('isSameSchool()', $block);
    }
}
