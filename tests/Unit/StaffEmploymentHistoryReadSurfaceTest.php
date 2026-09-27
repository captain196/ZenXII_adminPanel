<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Employment History Read Surface — source-regression tests, following this
 * repo's established convention for Firestore_service-backed controller
 * code (no behavioral mock exists — see StaffCreationAuditMetadataTest's
 * docblock). Covers Staff::get_employment_history()'s RBAC gate, the
 * two-tier sensitive-field payload shaping (the one genuinely new mechanism
 * this phase introduces), episode-derivation presence, and the
 * teacher_profile() Reporting Manager resolution addition.
 */
class StaffEmploymentHistoryReadSurfaceTest extends TestCase
{
    private string $staffSrc;
    private string $viewSrc;

    protected function setUp(): void
    {
        $this->staffSrc = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Staff.php');
        $this->viewSrc  = file_get_contents(__DIR__ . '/../../application/views/teacher_profile.php');
        $this->assertNotFalse($this->viewSrc);
    }

    private function readCodeOnly(string $path): string
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Could not read {$path}");
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
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

    private function getEmploymentHistoryBody(): string
    {
        $start = strpos($this->staffSrc, 'function get_employment_history(');
        $this->assertNotFalse($start, 'get_employment_history() must exist on Staff.php.');
        return $this->extractFunctionBody($this->staffSrc, $start);
    }

    // ── RBAC — reused, not new ───────────────────────────────────────────

    public function test_gated_sis_view_same_as_teacher_profile(): void
    {
        $body = $this->getEmploymentHistoryBody();
        $this->assertStringContainsString('$this->_require_role(self::VIEW_ROLES, "", "SIS", "view");', $body);
    }

    public function test_no_new_rbac_capability_introduced(): void
    {
        $body = $this->getEmploymentHistoryBody();
        $this->assertStringNotContainsString('token.role', $body);
        $this->assertStringNotContainsString("'staff.history'", $body);
        $this->assertStringNotContainsString("'staff.viewHistory'", $body);
    }

    public function test_reuses_has_permission_for_sensitive_gate_not_a_new_mechanism(): void
    {
        $body = $this->getEmploymentHistoryBody();
        $this->assertStringContainsString("has_permission('SIS', 'manage')", $body,
            'The sensitive-field gate must reuse the existing has_permission() helper, not a new authorization mechanism.');
    }

    // ── Two-tier payload shaping — the one genuinely new mechanism ──────

    public function test_reason_field_only_added_when_can_view_sensitive(): void
    {
        $body = $this->getEmploymentHistoryBody();
        $this->assertStringContainsString('if ($canViewSensitive) {', $body);
        // The reason assignment must be textually INSIDE that conditional block,
        // not a bare unconditional assignment elsewhere in the function.
        $ifPos = strpos($body, 'if ($canViewSensitive) {');
        $reasonPos = strpos($body, "\$evt['reason']");
        $this->assertNotFalse($reasonPos);
        $this->assertGreaterThan($ifPos, $reasonPos);
    }

    public function test_staff_separations_query_only_runs_when_can_view_sensitive(): void
    {
        $body = $this->getEmploymentHistoryBody();
        $sepQueryPos = strpos($body, "schoolWhere('staffSeparations'");
        $this->assertNotFalse($sepQueryPos, 'get_employment_history() must be the one issuing the staffSeparations read for this screen.');
        $precedingIf = strrpos(substr($body, 0, $sepQueryPos), 'if ($canViewSensitive) {');
        $this->assertNotFalse($precedingIf, 'The staffSeparations query must be nested inside the SIS/manage-only branch — a view-only caller must never trigger this read at all.');
    }

    public function test_response_includes_can_view_sensitive_flag(): void
    {
        $body = $this->getEmploymentHistoryBody();
        $this->assertStringContainsString("'canViewSensitive' => \$canViewSensitive,", $body);
    }

    // ── Episode derivation — no new collection ───────────────────────────

    /**
     * The derivation MOVED on 2026-08-22 — it did not change.
     *
     * It now lives in `Staff_employment_history_service::episodesFromEvents()`
     * so the Full & Final settlement resolves service duration from the same
     * derivation this screen shows. Duplicating it would have created a second
     * source of truth about how long somebody worked here — the one number a
     * gratuity calculation turns on.
     *
     * The rules asserted here are unchanged; only their home moved. The "no new
     * collection" guard still applies to the controller.
     */
    public function test_episode_derivation_uses_joined_rehired_separated_only(): void
    {
        $svc = file_get_contents(
            __DIR__ . '/../../application/libraries/Staff_employment_history_service.php'
        );
        $this->assertNotFalse($svc);

        $i = strpos($svc, 'function episodesFromEvents');
        $this->assertNotFalse($i, 'the extracted derivation must exist');
        $body = substr($svc, (int) $i, 1400);

        $this->assertStringContainsString("['JOINED', 'REHIRED']", $body);
        $this->assertStringContainsString("=== 'SEPARATED'", $body);
        $this->assertStringNotContainsString('employmentEpisodes', $this->staffSrc, 'No employmentEpisodes collection may be introduced — episodes remain derived, not stored.');
    }

    /** The screen must consume the shared derivation, not keep its own copy. */
    public function test_the_history_screen_uses_the_shared_derivation(): void
    {
        $body = $this->getEmploymentHistoryBody();

        $this->assertStringContainsString(
            'Staff_employment_history_service::episodesFromEvents($events)',
            $body,
            'a second inline copy would let the screen and the settlement disagree'
        );
    }

    // ── Legacy staff — no fabrication ────────────────────────────────────

    public function test_has_history_flag_reflects_actual_event_presence_not_fabricated(): void
    {
        $body = $this->getEmploymentHistoryBody();
        $this->assertStringContainsString("'hasHistory'       => !empty(\$events),", $body);
        $this->assertStringNotContainsString('createdAt', $body, 'Legacy history must never be inferred from createdAt/updatedAt.');
    }

    // ── No write, no schema change ───────────────────────────────────────

    public function test_action_never_writes_to_staff_employment_events_or_timeline(): void
    {
        $body = $this->getEmploymentHistoryBody();
        $this->assertStringNotContainsString('commitBatch', $body);
        $this->assertStringNotContainsString("fs->set(", $body);
        $this->assertStringNotContainsString('updateEntity(', $body);
    }

    // ── teacher_profile() Reporting Manager resolution ───────────────────

    public function test_teacher_profile_resolves_reporting_manager_name(): void
    {
        $start = strpos($this->staffSrc, 'function teacher_profile(');
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('reportingManagerName', $body);
        $this->assertStringContainsString("reportingManagerId", $body);
    }

    public function test_teacher_profile_rbac_gate_unchanged(): void
    {
        $start = strpos($this->staffSrc, 'function teacher_profile(');
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('$this->_require_role(self::VIEW_ROLES, "", "SIS", "view");', $body);
    }

    // ── View wiring ───────────────────────────────────────────────────────

    public function test_view_has_history_tab_and_reporting_manager_row(): void
    {
        $this->assertStringContainsString('data-target="tp-history"', $this->viewSrc);
        $this->assertStringContainsString('id="tp-history"', $this->viewSrc);
        $this->assertStringContainsString('Reporting Manager', $this->viewSrc);
    }

    public function test_view_fetches_the_new_endpoint(): void
    {
        $this->assertStringContainsString("staff/get_employment_history/", $this->viewSrc);
    }

    public function test_view_history_fetch_is_lazy_not_on_page_load(): void
    {
        // The fetch must be wired to the tab's click handler, not invoked
        // unconditionally at script-load time — avoids an extra read for
        // every profile view when the History tab is never opened.
        $addListenerPos = strpos($this->viewSrc, "historyTab.addEventListener('click', load);");
        $this->assertNotFalse($addListenerPos, 'History must be lazy-loaded on first tab click, not fetched unconditionally on page load.');
    }
}
