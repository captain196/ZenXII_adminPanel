<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_entitlement_reader.php';

/**
 * P0 — head-scoped entitlements resolve on the school-scoped feeHeadRef.
 *
 * Why this matters: `feeHeadId` is a PER-CHART-ROW id — live data holds 28
 * distinct ids for 6 logical heads — so `head:<feeHeadId>` binds an
 * entitlement to one class/section chart only, and the name fallback breaks on
 * rename. `feeHeadRef` is school-scoped and survives rename, repricing and
 * session rollover.
 *
 * This is a COMPATIBILITY TRANSITION: both formats resolve simultaneously and
 * no stored record is rewritten.
 */
class P0FeeHeadRefScopeTest extends TestCase
{
    private Fee_entitlement_reader $r;

    protected function setUp(): void
    {
        $this->r = new Fee_entitlement_reader();
    }

    /** An always-in-window entitlement with the given scope. */
    private function ent(string $scope): array
    {
        return ['scope' => $scope, 'valueType' => 'percent', 'value' => 10,
                'effectiveFrom' => '2020-01-01', 'effectiveTo' => null, 'status' => 'active'];
    }

    private function applies(array $ent, string $headId, string $name, string $cat = 'academic', string $ref = ''): bool
    {
        return $this->r->appliesTo($ent, '2026-04', $headId, $name, $cat, $ref);
    }

    // ══ 1-3 · the new authority ═══════════════════════════════════════════

    public function test_1_a_feeHeadRef_scope_is_accepted(): void
    {
        $this->assertTrue($this->applies($this->ent('head:FH_DF3C200439E2'),
            'FH_ROWID', 'Tuition Fee', 'academic', 'FH_DF3C200439E2'));
    }

    public function test_2_registry_demand_with_matching_ref_applies(): void
    {
        $this->assertTrue($this->applies($this->ent('head:FH_TUI'),
            'FH_chartrow_9A', 'Tuition Fee', 'academic', 'FH_TUI'));
    }

    public function test_3_registry_demand_with_a_different_ref_does_not_apply(): void
    {
        $this->assertFalse($this->applies($this->ent('head:FH_TUI'),
            'FH_chartrow_9A', 'Library Fee', 'academic', 'FH_LIB'));
    }

    // ══ 4 · THE point of the change ═══════════════════════════════════════

    /**
     * One logical head appears in two class charts with DIFFERENT per-row
     * feeHeadIds but the SAME school-scoped ref. A single head-scoped
     * entitlement must cover both — impossible under the legacy id form.
     */
    public function test_4_one_entitlement_covers_the_same_head_across_two_charts(): void
    {
        $e = $this->ent('head:FH_TUI');
        $this->assertTrue($this->applies($e, 'FH_ROW_CLASS9', 'Tuition Fee', 'academic', 'FH_TUI'));
        $this->assertTrue($this->applies($e, 'FH_ROW_CLASS8', 'Tuition Fee', 'academic', 'FH_TUI'));

        // Under the legacy binding it would have covered only one chart.
        $legacy = $this->ent('head:FH_ROW_CLASS9');
        $this->assertTrue($this->applies($legacy,  'FH_ROW_CLASS9', 'Tuition Fee', 'academic', 'FH_TUI'));
        $this->assertFalse($this->applies($legacy, 'FH_ROW_CLASS8', 'Tuition Fee', 'academic', 'FH_TUI'));
    }

    /** 5 — rename is the hazard the ref exists to remove. */
    public function test_5_a_rename_does_not_break_a_ref_scoped_entitlement(): void
    {
        $e = $this->ent('head:FH_TUI');
        $this->assertTrue($this->applies($e, 'FH_ROW', 'Tuition Fee', 'academic', 'FH_TUI'));
        $this->assertTrue($this->applies($e, 'FH_ROW', 'School Tuition Fee', 'academic', 'FH_TUI'),
            'the ref is unchanged by a rename, so the entitlement must still apply');
    }

    // ══ 6-7 · legacy compatibility preserved ══════════════════════════════

    public function test_6_a_legacy_feeHeadId_scope_still_resolves(): void
    {
        $this->assertTrue($this->applies($this->ent('head:FH_ROWID'),
            'FH_ROWID', 'Tuition Fee', 'academic', ''),
            'existing head:<feeHeadId> records must keep working — no migration');
    }

    public function test_6b_a_legacy_scope_resolves_even_when_a_ref_is_present(): void
    {
        $this->assertTrue($this->applies($this->ent('head:FH_ROWID'),
            'FH_ROWID', 'Tuition Fee', 'academic', 'FH_TUI'));
    }

    public function test_7_the_legacy_name_fallback_still_resolves(): void
    {
        $this->assertTrue($this->applies($this->ent('head:Tuition Fee'),
            '', 'Tuition Fee', 'academic', ''),
            'kept only for chart heads that never had a stable id');
    }

    // ══ 8-9 · fail closed ═════════════════════════════════════════════════

    public function test_8_an_unknown_target_fails_closed(): void
    {
        $this->assertFalse($this->applies($this->ent('head:FH_NOT_A_HEAD'),
            'FH_ROW', 'Tuition Fee', 'academic', 'FH_TUI'));
    }

    public function test_8b_an_empty_target_fails_closed(): void
    {
        $this->assertFalse($this->applies($this->ent('head:'), 'FH_ROW', 'Tuition Fee', 'academic', 'FH_TUI'));
    }

    public function test_8c_an_unrecognised_scope_fails_closed(): void
    {
        foreach (['nonsense', 'head', 'ref:FH_TUI', ''] as $bad) {
            $this->assertFalse($this->applies($this->ent($bad), 'FH_ROW', 'Tuition Fee', 'academic', 'FH_TUI'),
                "scope '{$bad}' must never apply");
        }
    }

    public function test_9_an_empty_ref_never_matches_an_empty_target(): void
    {
        // A demand with no ref must not accidentally match on ''.
        $this->assertFalse($this->applies($this->ent('head:'), '', '', 'academic', ''));
    }

    // ══ 10-11 · other scopes untouched ════════════════════════════════════

    public function test_10_category_scope_is_unchanged(): void
    {
        $this->assertTrue($this->applies($this->ent('category:academic'), 'FH_ROW', 'Tuition Fee', 'academic', 'FH_TUI'));
        $this->assertFalse($this->applies($this->ent('category:transport'), 'FH_ROW', 'Tuition Fee', 'academic', 'FH_TUI'));
    }

    public function test_11_all_scope_and_invariant_I3_are_unchanged(): void
    {
        $this->assertTrue($this->applies($this->ent('all'), 'FH_ROW', 'Tuition Fee', 'academic', 'FH_TUI'));
        foreach (['transport', 'hostel', 'meals'] as $service) {
            $this->assertFalse($this->applies($this->ent('all'), 'FH_ROW', 'Transport Fee', $service, 'FH_TRA'),
                'I3: a blanket entitlement never nets against a service head');
        }
    }

    // ══ 12-15 · scope, wiring and lifecycle ═══════════════════════════════

    public function test_12_no_double_authority_between_legacy_and_registry(): void
    {
        // A ref-scoped entitlement must NOT also match an unrelated legacy
        // demand that merely shares the chart-row id space.
        $this->assertFalse($this->applies($this->ent('head:FH_TUI'), 'FH_TUI_LOOKALIKE', 'Tuition Fee', 'academic', ''),
            'no ref on the demand and no id match → must not apply');
    }

    /** Executable code only — both files DISCUSS entitlements in comments. */
    private function codeOnly(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(APPPATH . $rel)) as $t) {
            $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        return $out;
    }

    public function test_13_public_admission_cannot_create_an_entitlement(): void
    {
        $code = $this->codeOnly('controllers/Admission_public.php');
        $this->assertStringNotContainsString('Entitlement_service', $code,
            'the unauthenticated public form captures declarations only, never grants');
        $this->assertStringNotContainsString('studentEntitlements', $code);
    }

    /**
     * Student 360 reads entitlements THROUGH the canonical service (that is
     * correct and intended) but holds no authority of its own: it may not
     * mutate, and it may not query the collection directly.
     */
    public function test_14_student360_reads_only_and_holds_no_authority(): void
    {
        $code = $this->codeOnly('controllers/Student360.php');

        $this->assertStringContainsString("\$this->load->library('Entitlement_service'", $code,
            'reads go through the one service — no second reader');
        $this->assertStringNotContainsString("firestoreQuery('studentEntitlements'", $code,
            'Student 360 must never query the collection itself');

        foreach (['propose', 'approve', 'reject', 'discontinue'] as $write) {
            $this->assertStringNotContainsString("entitlementSvc->{$write}(", $code,
                "Student 360 is read-only — it must not call {$write}()");
        }
    }

    public function test_15_maker_checker_lifecycle_is_untouched(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Entitlement_service.php');
        $this->assertStringContainsString("'status'       => 'active'", $src);
        $this->assertStringContainsString("'rejectedBy'", $src);
        $this->assertStringContainsString('Only a pending entitlement can be approved', $src);
        $this->assertStringContainsString("'approvedBy'   => \$this->actorId", $src);
    }

    /** Scope validation accepts both forms; no second identity field added. */
    public function test_validation_accepts_both_forms_and_adds_no_field(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Entitlement_service.php');
        $this->assertStringContainsString("strpos(\$scope, 'head:') !== 0", $src,
            'validation still keys on the head: prefix — both target forms pass');
        $this->assertSame(0, substr_count($src, "'feeHeadRef'     =>"),
            'no redundant feeHeadRef field may be stored on the entitlement record');
    }

    /** Resolution order must prefer the ref. */
    public function test_the_reader_prefers_ref_then_id_then_name(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');
        $this->assertMatchesRegularExpression(
            '/\$feeHeadRef !== .{2} && \$target === \$feeHeadRef.*\$feeHeadId\s+!== .{2} && \$target === \$feeHeadId.*\$feeHead\s+!== .{2} && \$target === \$feeHead/s',
            $src, 'ref first, then legacy id, then legacy name');
    }
}
