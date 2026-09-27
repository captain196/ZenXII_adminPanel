<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Per-school leave-accrual toggle (2026-09-19) — steps 2 & 3 of making accrual
 * a school choice: the school decides via schools.leavePolicy.accrualEnabled,
 * accrual actually limits what staff can take (no longer inert), and OFF (the
 * default for every existing and brand-new school) is byte-identical to before.
 *
 * Controller paths can't be instantiated offline, so these are source
 * assertions with comments STRIPPED (a comment naming a removed call must not
 * read as the call). Reachability (route + view toggle) is asserted end to end
 * per the UI-reachability rule.
 */
final class LeaveAccrualPerSchoolToggleTest extends TestCase
{
    private static string $hr = '';
    private static string $svc = '';
    private static string $view = '';
    private static string $routes = '';

    public static function setUpBeforeClass(): void
    {
        self::$hr     = self::stripComments((string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php'));
        self::$svc    = self::stripComments((string) file_get_contents(__DIR__ . '/../../application/libraries/Leave_accrual_service.php'));
        self::$view   = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');
        self::$routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$hr = self::$svc = self::$view = self::$routes = '';
    }

    private static function stripComments(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return (string) preg_replace('/\s+/', ' ', $out);
    }

    // ── The per-school setting is the authority (now in Leave_accrual_service) ──
    public function test_school_setting_helper_reads_leave_policy_fail_closed(): void
    {
        // The engine + its per-school gate were extracted to Leave_accrual_service
        // (2026-09-20); Hr delegates. The authoritative read lives in the service.
        $this->assertStringContainsString('public function schoolAccrualEnabled(', self::$svc);
        $this->assertStringContainsString("\$doc['leavePolicy']", self::$svc,
            'the toggle must live on schools.leavePolicy');
        $this->assertMatchesRegularExpression("~schoolAccrualEnabled[^{]*\{.*catch[^}]*\\\$enabled\s*=\s*false~s", self::$svc,
            'a read error must fail closed (accrual off), never accidentally drip-gate leave');
        // Hr keeps a thin delegate so its existing callers are unchanged.
        $this->assertStringContainsString('return $this->_accrualSvc()->schoolAccrualEnabled();', self::$hr,
            'Hr must delegate the per-school gate to the single service authority');
    }

    public function test_accrual_engine_is_gated_on_the_school_setting_not_the_flag(): void
    {
        // The engine (accrueOneType) lives in the service and gates on the
        // per-school setting, not the fleet-wide LEAVE_ACCRUAL_ENABLED flag.
        $this->assertStringContainsString('if (!$this->schoolAccrualEnabled()) return $none;', self::$svc,
            'the accrual engine now keys off the per-school setting');
        $this->assertStringNotContainsString("_leave_flag('LEAVE_ACCRUAL_ENABLED')", self::$svc,
            'the fleet-wide flag must no longer gate the per-school engine');
    }

    public function test_type_accrues_uses_the_same_version_source_as_the_engine(): void
    {
        // What an employee may take (Hr::_type_accrues, gating apply_leave/derive)
        // must never disagree with what the engine credits: both resolve the
        // type's accrual_mode via Leave_policy_service::effectiveVersion.
        $this->assertStringContainsString('private function _type_accrues(', self::$hr);
        $this->assertStringContainsString("accrual_mode'] ?? 'lump_sum') === 'monthly'", self::$hr);
        $this->assertStringContainsString('Leave_policy_service::effectiveVersion(', self::$hr);
        // The engine side uses the exact same version source + accrual_mode test.
        $this->assertStringContainsString("accrual_mode'] ?? 'lump_sum') !== 'monthly'", self::$svc);
        $this->assertStringContainsString('Leave_policy_service::effectiveVersion(', self::$svc);
    }

    // ── Accrual is no longer inert: consumption + view are capped ──────────
    public function test_apply_leave_caps_availability_at_accrued_so_far(): void
    {
        $this->assertStringContainsString('$this->_type_accrues($leaveType', self::$hr,
            'apply_leave must consult the accrual gate');
        $this->assertStringContainsString('$currentBalance = min($currentBalance, $accrualAvail);', self::$hr,
            'consumption must be capped at accrued-so-far when accrual is on');
        $this->assertStringContainsString("accruedToDate'] ?? 0)", self::$hr);
    }

    public function test_derive_live_balances_shows_the_accrued_availability(): void
    {
        $this->assertStringContainsString('$this->_type_accrues($lt, $resolveDate)', self::$hr,
            'the view must reflect the same accrual cap the consumption path enforces');
        $this->assertStringContainsString("\$allocated = min(\$allocated, (float) (\$s['accruedToDate'] ?? 0));", self::$hr);
    }

    // ── The save endpoint is safe (RBAC + audit) and covers the WHOLE policy set ──
    public function test_save_endpoint_is_manage_gated_validated_and_audited(): void
    {
        $this->assertStringContainsString('public function save_leave_policy(', self::$hr);
        $this->assertStringContainsString("_require_role(self::HR_ROLES, 'save_leave_policy', 'HR', 'manage')", self::$hr);
        // Generalized (2026-09-20): writes each submitted per-school field via a
        // dotted path, and covers the full leave-policy switch set.
        $this->assertStringContainsString('$patch["leavePolicy.{$field}"] = $val;', self::$hr,
            'writes each submitted per-school leavePolicy field');
        $this->assertStringContainsString("'action' => 'leave_policy_updated'", self::$hr,
            'the policy change is audited');
        foreach (["'accrualEnabled'", "'LEAVE_EFFECTIVE_DATING_ENABLED'", "'LEAVE_PRORATA_ENABLED'",
                  "'LEAVE_WAITING_PERIOD_ENABLED'", "'LEAVE_MAX_BALANCE_ENABLED'", "'LEAVE_YEAR_CLOSURE_ENABLED'"] as $field) {
            $this->assertStringContainsString($field, self::$hr,
                "the school-configurable policy set must include {$field}");
        }
    }

    // ── Every leave-policy flag resolves PURELY PER-SCHOOL (legacy fallback cut) ──
    public function test_leave_flags_resolve_purely_per_school(): void
    {
        // The single chokepoint _leave_flag() reads ONLY schools.leavePolicy —
        // every LEAVE_* capability is the school's own choice. The legacy
        // fleet-wide platform-flag fallback has been CUT (2026-09-20).
        $this->assertMatchesRegularExpression(
            "~private function _leave_flag[^{]*\{.*leavePolicy.*\\\$lpBySchool\[\\\$sid\]\[\\\$name\] \?\? false~s",
            self::$hr,
            'the flag must resolve only from schools.leavePolicy, defaulting OFF when absent');
        // The legacy platform-flag read must be gone from the resolver.
        $body = self::functionSlice(self::$hr, 'private function _leave_flag(');
        $this->assertStringNotContainsString("config->item(\$name, 'leave_policy_v2_flags')", $body,
            'the legacy fleet-wide leave_policy_v2_flags fallback must be cut from _leave_flag');
        // Same in the service engine.
        $svcBody = self::functionSlice(self::$svc, 'protected function leaveFlag(');
        $this->assertStringNotContainsString("leave_policy_v2_flags", $svcBody,
            'the service engine must also read only per-school leavePolicy');
    }

    /** Return the brace-balanced body of the first function matching $needle. */
    private static function functionSlice(string $src, string $needle): string
    {
        $i = strpos($src, $needle);
        if ($i === false) return '';
        $open = strpos($src, '{', $i);
        if ($open === false) return '';
        $depth = 0; $len = strlen($src);
        for ($j = $open; $j < $len; $j++) {
            if ($src[$j] === '{') $depth++;
            elseif ($src[$j] === '}') { $depth--; if ($depth === 0) return substr($src, $open, $j - $open + 1); }
        }
        return substr($src, $open);
    }

    // ── Reachability: route + view toggle actually wired ──────────────────
    public function test_route_is_registered(): void
    {
        $this->assertStringContainsString("\$route['hr/save_leave_policy']", self::$routes);
    }

    public function test_get_leave_types_exposes_the_toggle_state(): void
    {
        $this->assertStringContainsString("'accrualEnabled' => \$this->_school_accrual_enabled()", self::$hr);
    }

    public function test_view_toggle_is_present_and_wired_to_the_endpoint(): void
    {
        $this->assertStringContainsString('id="chkAccrualMode"', self::$view, 'the toggle control exists');
        $this->assertStringContainsString('HR.saveAccrualMode(this.checked)', self::$view, 'onchange posts the change');
        $this->assertStringContainsString("post('hr/save_leave_policy'", self::$view, 'the handler hits the real endpoint');
        $this->assertStringContainsString('saveAccrualMode:  saveAccrualMode', self::$view, 'the method is exposed on HR');
        // The checkbox reflects the saved state on load (never lies).
        $this->assertStringContainsString('_acc.checked = !!(r && r.accrualEnabled===true)', self::$view);
    }
}
