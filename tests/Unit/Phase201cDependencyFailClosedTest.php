<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 20.1c — P20-F9 (dependency fail-open) and P20-F12 (phantom-success UI).
 *
 * P20-F9: `_dep_count_safe()` catches each check's exception, records
 * `errors[$key]`, and then leaves `has_any` false and `by_key[$key]` unset. A
 * dependency query that FAILED is therefore indistinguishable from one that
 * found nothing, so every caller's `if (!empty($deps['has_any']))` is false and
 * the delete proceeds. Phase 20.1 closed this inline at delete_section() and
 * Phase 20.1b at delete_stream(); this phase closes the remaining three —
 * soft_delete_class(), delete_subject() and delete_session() — via one shared
 * guard, WITHOUT modifying _dep_count_safe() itself.
 *
 * P20-F12: two delete handlers in the Academic Setup view mutated local state
 * BEFORE calling the server and then discarded every error. When the server
 * correctly refused, the operator watched the row disappear and was told
 * nothing — the fail-closed backend was invisible on exactly the paths this
 * programme hardened.
 *
 * SRNO discipline: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47.
 */
class Phase201cDependencyFailClosedTest extends TestCase
{
    private const CTRL = __DIR__ . '/../../application/controllers/School_config.php';
    private const VIEW = __DIR__ . '/../../application/views/school_config/index.php';

    /** PHP source with comments stripped — comments describe the banned patterns. */
    private function phpSrc(): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(self::CTRL)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    /**
     * View source with BOTH PHP and JS comments stripped. token_get_all leaves
     * JS inside T_INLINE_HTML untouched, and this file's new comments quote the
     * very patterns being asserted against — without stripping them the
     * assertions below match their own documentation.
     */
    private function viewSrc(): string
    {
        $raw = file_get_contents(self::VIEW);
        $raw = preg_replace('!/\*.*?\*/!s', '', $raw);      // /* block */
        $raw = preg_replace('!^\s*//.*$!m', '', $raw);       // // line
        return $raw;
    }

    /** Body of a named JS handler, from its declaration to the next window.* */
    private function jsHandler(string $name): string
    {
        $src = $this->viewSrc();
        $start = strpos($src, 'window.' . $name . ' = function');
        $this->assertNotFalse($start, "JS handler {$name} not found.");
        $end = strpos($src, 'window.', $start + 20);
        return substr($src, $start, ($end === false ? 4000 : $end - $start));
    }

    // ── P20-F9: the guard itself ─────────────────────────────────────────

    /** Mirrors School_config::_dep_fail_closed()'s decision. */
    private function guardFires(array $deps): bool
    {
        return !empty($deps['errors']);
    }

    public function test_F9_guard_fires_when_a_check_errored(): void
    {
        $deps = ['has_any' => false, 'total' => 0, 'by_key' => [], 'human' => [],
                 'errors' => ['students' => 'index missing']];
        $this->assertTrue($this->guardFires($deps),
            'A failed dependency check must refuse the delete, not permit it.');
    }

    public function test_F9_guard_does_not_fire_on_a_clean_empty_result(): void
    {
        $deps = ['has_any' => false, 'total' => 0, 'by_key' => ['students' => 0], 'human' => [], 'errors' => []];
        $this->assertFalse($this->guardFires($deps),
            'A genuinely empty dependency set must still permit deletion.');
    }

    public function test_F9_the_fail_open_is_real_not_theoretical(): void
    {
        // Reproduces _dep_count_safe()'s catch path: errors set, has_any and
        // by_key untouched. Without the guard the caller sees "no dependencies".
        $deps = ['has_any' => false, 'total' => 0, 'by_key' => [], 'human' => [],
                 'errors' => ['students' => 'boom']];
        $this->assertFalse((bool) $deps['has_any'],
            'Precondition: a thrown check leaves has_any false — the fail-open.');
        $this->assertArrayNotHasKey('students', $deps['by_key'],
            'Precondition: a thrown check records no count at all.');
        $this->assertTrue($this->guardFires($deps), 'The guard is what closes it.');
    }

    // ── P20-F9: every delete endpoint is fail-closed ─────────────────────

    public function test_F9_all_five_delete_endpoints_are_fail_closed(): void
    {
        $src = $this->phpSrc();

        // Three closed by the shared helper introduced in this phase…
        $this->assertSame(3, substr_count($src, '$this->_dep_fail_closed('),
            'soft_delete_class, delete_subject and delete_session must each call the shared guard.');
        $this->assertSame(1, substr_count($src, 'private function _dep_fail_closed('),
            'Exactly one shared guard definition.');

        // …and three closed inline, which must remain.
        //
        // Was 2 (delete_section, delete_stream) when this census was written at
        // Phase 20.1b. save_bulk_subjects joined them on 2026-08-29 when it
        // stopped being write-only and started reconciling deletions: a subject
        // dropped from the editor is now actually removed, so it consults the
        // same subjectAssignments guard delete_subject does.
        //
        // It handles the undetermined case INLINE rather than through
        // _dep_fail_closed() on purpose: that helper exits the request, which in
        // a batch would abort after some subjects had already been written and
        // leave the operator with a half-applied save. Inline, one unverifiable
        // subject is KEPT and reported in kept[] while the rest of the batch
        // completes — the same fail-closed property, per row.
        $this->assertSame(3, preg_match_all("/if\s*\(\s*!empty\(\s*\\\$deps\['errors'\]\s*\)\s*\)/", $src),
            'delete_section(), delete_stream() and save_bulk_subjects() must keep '
            . 'their inline fail-closed checks.');
    }

    public function test_F9_dep_count_safe_itself_was_not_modified(): void
    {
        $src = $this->phpSrc();
        // The collector still reports partial results; the fix lives in the
        // callers. Changing it centrally would alter five endpoints at once.
        $this->assertStringContainsString("\$out['errors'][\$key]=\$e->getMessage();",
            preg_replace('/\s+/', '', $src),
            '_dep_count_safe() must still record per-check errors rather than throwing.');
        $this->assertSame(1, substr_count($src, 'private function _dep_count_safe('),
            'Exactly one collector.');
    }

    // ── P20-F12: UI must not report success when the server refuses ──────

    public function test_F12_deleteStream_posts_before_mutating_local_state(): void
    {
        $h = $this->jsHandler('deleteStream');
        $postAt = strpos($h, "post('school_config/delete_stream'");
        $mutAt  = strpos($h, 'delete CFG.streams[key]');
        $this->assertNotFalse($postAt);
        $this->assertNotFalse($mutAt);
        $this->assertLessThan($mutAt, $postAt,
            'The server call must precede any local removal, or a refusal still looks like success.');
    }

    public function test_F12_softDeleteClass_posts_before_mutating_local_state(): void
    {
        $h = $this->jsHandler('softDeleteClass');
        $postAt = strpos($h, "post('school_config/soft_delete_class'");
        $mutAt  = strpos($h, 'CFG.classes = (CFG.classes || []).filter');
        $this->assertNotFalse($postAt);
        $this->assertNotFalse($mutAt);
        $this->assertLessThan($mutAt, $postAt,
            'The server call must precede any local removal.');
    }

    public function test_F12_both_handlers_surface_failures(): void
    {
        foreach (['deleteStream', 'softDeleteClass'] as $name) {
            $h = $this->jsHandler($name);
            $this->assertStringContainsString('dependencies_undetermined', $h,
                "{$name} must distinguish 'could not verify' from 'in use'.");
            $this->assertStringContainsString('d.dependencies', $h,
                "{$name} must surface a dependency-block refusal.");
            $this->assertStringContainsString(', false)', $h,
                "{$name} must raise at least one failure toast.");
        }
    }

    public function test_F12_both_handlers_have_duplicate_click_protection(): void
    {
        foreach (['deleteStream', 'softDeleteClass'] as $name) {
            $this->assertStringContainsString('_busy', $this->jsHandler($name),
                "{$name} must guard against a double submit.");
        }
    }

    public function test_F12_no_best_effort_error_swallowing_remains(): void
    {
        $src = $this->viewSrc();
        $this->assertSame(0, preg_match_all('/Also delete from server \(best-effort/', $src),
            'The "best-effort, ignore errors" delete pattern must be gone.');
    }

    // ── P20-F10: recorded as a decision, not a silent omission ───────────

    public function test_F10_stream_deletion_does_NOT_block_on_subjects(): void
    {
        $src = $this->phpSrc();
        $start = strpos($src, 'function delete_stream(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 4000);

        // subjects.stream is a classification attribute with a "common"
        // default — OPTIONAL in the canonical contract
        // (Subject_master_verify::OPTIONAL_FIELDS), never filtered on, and on
        // the probed tenant 30/30 subjects carry "common" while 0 reference a
        // live stream. Blocking on it would invent a policy the application
        // does not have. This test pins that decision so a future edit is
        // deliberate rather than accidental.
        $this->assertStringNotContainsString("'collection' => 'subjects'", $body,
            'P20-F10 decision: stream deletion must NOT block on subjects.');
    }

    // ── SRNO (mandatory) ─────────────────────────────────────────────────

    public function test_SRNO_no_delete_endpoint_touches_student_identity(): void
    {
        $src = $this->phpSrc();
        foreach (['soft_delete_class', 'delete_section', 'delete_subject',
                  'delete_stream', 'delete_session'] as $fn) {
            $start = strpos($src, "function {$fn}(");
            $this->assertNotFalse($start, "{$fn} not found.");
            $body = substr($src, $start, 4500);
            $this->assertSame(0, preg_match_all('/srNo|sr_no|admissionNo|rollNo/i', $body),
                "{$fn} must not reference student identity fields.");
        }
    }

    public function test_SRNO_guard_helper_is_identity_blind_and_read_only(): void
    {
        $src   = $this->phpSrc();
        $start = strpos($src, 'private function _dep_fail_closed(');
        $body  = substr($src, $start, 1200);
        $this->assertSame(0, preg_match_all('/srNo|sr_no|admissionNo|rollNo|studentId/i', $body));
        $this->assertStringNotContainsString('->update(', $body, 'Guard must not write.');
        $this->assertStringNotContainsString('->remove(', $body, 'Guard must not delete.');
    }

    // ── response shape preserved ─────────────────────────────────────────

    public function test_response_shape_preserved_for_the_UI(): void
    {
        $src = $this->phpSrc();
        $this->assertStringContainsString("'reason'=>'dependencies_undetermined'",
            preg_replace('/\s+/', '', $src),
            'Refusals must carry a machine-readable reason the UI can branch on.');
        $this->assertStringContainsString("'failed_checks'", $src,
            'Refusals must name which checks could not be evaluated.');
    }
}
