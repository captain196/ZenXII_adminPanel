<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';

/**
 * Phase 19L — the canonical credential authority, wired.
 *
 * `validateCredential()` is now called by every runtime QR consumer, so the
 * three of them can no longer drift apart on what a legacy or superseded card
 * is worth. **Behaviour today is unchanged**: every flag ships false, so all
 * three accept exactly what they accepted before. Wiring now means enabling
 * enforcement later is a config flip that has already been exercised in code,
 * rather than a code change made under time pressure at the gate.
 *
 * ⚠ ID-VIS-1 — Campus Access is wired DIFFERENTLY, on purpose. A visitor badge
 * carries a passId, is minted V1, and has no `idCards` record; it is not a
 * student card. It therefore gets all-false flags explicitly, never
 * `configuredFlags()`, so a student-card retirement decision can never brick
 * every visitor badge in the school.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19LCredentialWiringTest extends TestCase
{
    private const SCH   = 'SCH_TEST01';
    private const STU_A = 'STU0012';  private const SR_A = '47';

    private $fb; private $fs;
    private \Id_card_service $svc;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/application/helpers/qr_token_helper.php';
        if (!class_exists('Id_card_service')) {
            require_once dirname(__DIR__, 2) . '/application/libraries/Id_card_service.php';
        }
    }

    protected function setUp(): void
    {
        $this->fb  = new FakeIdCardFirebase();
        $this->fs  = new FakeIdCardFs(self::SCH, $this->fb);
        $this->svc = (new \Id_card_service())->init($this->fb, $this->fs, self::SCH, 'ADM001');
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    private static function codeOnly(string $rel): string
    {
        $out = '';
        foreach (token_get_all(self::src($rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else $out .= $t;
        }
        return $out;
    }

    private const CONSUMERS = [
        'application/controllers/Attendance.php',
        'application/libraries/Transport_qr_attendance_service.php',
        'application/libraries/Visitor_service.php',
    ];

    // ══ A. Wired — all three ═══════════════════════════════════════════

    public function test_every_consumer_now_uses_the_canonical_authority(): void
    {
        foreach (self::CONSUMERS as $rel) {
            $this->assertStringContainsString('validateCredential', self::codeOnly($rel),
                "{$rel} must defer credential validity to the one authority");
        }
    }

    public function test_student_card_consumers_read_the_shared_flags(): void
    {
        foreach ([self::CONSUMERS[0], self::CONSUMERS[1]] as $rel) {
            $this->assertStringContainsString('configuredFlags()', self::codeOnly($rel));
        }
    }

    /**
     * ID-VIS-1 — the visitor path must NEVER inherit student-card retirement
     * policy. Enabling IDCARD_REJECT_V1 to retire student cards would
     * otherwise reject every visitor badge, which is minted V1 by design.
     */
    public function test_the_visitor_path_never_inherits_student_card_flags(): void
    {
        $code = self::codeOnly(self::CONSUMERS[2]);
        $this->assertStringContainsString('validateCredential', $code);
        $this->assertStringNotContainsString('configuredFlags', $code,
            'a student-card policy flip must not brick visitor badges');
        $this->assertMatchesRegularExpression(
            "/'enforceV2'\s*=>\s*false.*'rejectLegacy'\s*=>\s*false.*'rejectV1'\s*=>\s*false/s",
            $code
        );
    }

    public function test_no_consumer_reimplements_credential_policy(): void
    {
        // The whole point: one authority, not three copies. No consumer may
        // decide for itself what a legacy or versioned token is worth.
        foreach ([self::CONSUMERS[0], self::CONSUMERS[2]] as $rel) {
            $code = self::codeOnly($rel);
            $this->assertStringNotContainsString("cardVersion'] !==", $code);
            $this->assertStringNotContainsString('currentVersion(', $code);
        }
    }

    // ══ B. Nothing changes today ═══════════════════════════════════════

    public function test_with_shipped_flags_legacy_v1_and_v2_are_all_still_accepted(): void
    {
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, ['srNo' => self::SR_A]);
        $off = ['enforceV2' => false, 'rejectLegacy' => false, 'rejectV1' => false];

        $legacy = rtrim(strtr(base64_encode(self::SCH . '|' . self::STU_A), '+/', '-_'), '=');
        $this->assertTrue($this->svc->validateCredential(qr_token_decode($legacy), $off)['valid']);
        $this->assertTrue($this->svc->validateCredential(
            qr_token_decode(qr_token_encode(self::SCH, self::STU_A)), $off)['valid']);
        $this->assertTrue($this->svc->validateCredential(
            qr_token_decode(qr_token_encode_v2(self::SCH, self::STU_A, 1)), $off)['valid']);
    }

    public function test_all_flags_including_v1_telemetry_ship_off(): void
    {
        $cfg = self::src('application/config/id_card_flags.php');
        foreach (['IDCARD_ENFORCE_V2', 'IDCARD_REJECT_LEGACY', 'IDCARD_REJECT_V1', 'IDCARD_V1_TELEMETRY'] as $f) {
            $this->assertMatchesRegularExpression("/'{$f}'\]\s*=\s*false/", $cfg, "{$f} must ship off");
        }
    }

    public function test_configured_flags_fails_safe_not_closed(): void
    {
        // A missing config must never silently start rejecting cards.
        $code = self::codeOnly('application/libraries/Id_card_service.php');
        $this->assertStringContainsString("catch (\\Throwable", $code);
        $this->assertMatchesRegularExpression(
            "/\\\$off\s*=\s*\['enforceV2'\s*=>\s*false/", $code
        );
    }

    // ══ C. Transport keeps its stricter rule ═══════════════════════════

    public function test_transport_still_rejects_legacy_before_deferring(): void
    {
        // F14 has always been stricter than school-wide policy — a bus is an
        // unsupervised boarding decision. Deferring to the shared authority
        // must not relax it.
        $code = self::codeOnly(self::CONSUMERS[1]);
        $this->assertMatchesRegularExpression("/if\s*\(!empty\(\\\$decoded\['legacy'\]\)\)/", $code);
        $this->assertLessThan(
            strpos($code, 'validateCredential'),
            strpos($code, "\$decoded['legacy']"),
            'the stricter legacy rejection must run FIRST'
        );
    }

    public function test_transport_takes_firebase_from_ci_not_a_missing_property(): void
    {
        // Transport_qr_attendance_service has NO `firebase` property. Passing
        // one would leave the card service not-ready, which is silent today
        // but would make currentVersion() return 0 for every student the
        // moment enforcement is enabled — rejecting the whole school at the
        // bus door.
        $code = self::codeOnly(self::CONSUMERS[1]);
        $this->assertStringNotContainsString('init($this->firebase', $code);
        $this->assertStringContainsString('$CI->firebase', $code);
        $this->assertStringNotContainsString('private $firebase', self::src(self::CONSUMERS[1]));
    }

    public function test_transport_falls_through_rather_than_returning_early_when_unavailable(): void
    {
        // An early return here would skip F14's own tenant check below it.
        $code = self::codeOnly(self::CONSUMERS[1]);
        $tenantAt = strpos($code, "!== \$this->schoolId");
        $validAt  = strpos($code, 'validateCredential');
        $this->assertNotFalse($tenantAt);
        $this->assertLessThan($tenantAt, $validAt, 'tenant isolation must still run afterwards');
    }

    // ══ D. Telemetry ═══════════════════════════════════════════════════

    public function test_v1_telemetry_is_gated_by_its_own_flag(): void
    {
        $code = self::codeOnly(self::CONSUMERS[0]);
        $this->assertStringContainsString('IDCARD_V1_TELEMETRY', $code);
        $this->assertStringContainsString("'v1'", $code);
    }

    public function test_v1_telemetry_logs_at_an_observable_level(): void
    {
        $this->assertStringContainsString("SEC-ID-3b", self::src(self::CONSUMERS[0]));
        $this->assertMatchesRegularExpression(
            "/log_message\('error',\s*\n?\s*\"SEC-ID-3b/", self::src(self::CONSUMERS[0])
        );
    }

    public function test_no_credential_material_is_ever_logged(): void
    {
        // schoolId + studentId are enough to reissue; the token, signature and
        // secret must never reach a log file.
        foreach (self::CONSUMERS as $rel) {
            $code = self::codeOnly($rel);
            foreach (['$token', '$sig', '_qr_secret'] as $needle) {
                $this->assertDoesNotMatchRegularExpression(
                    '/log_message\([^)]*' . preg_quote($needle, '/') . '/', $code,
                    "{$rel} must not log credential material"
                );
            }
        }
    }

    public function test_telemetry_never_blocks_a_scan(): void
    {
        $code = self::codeOnly(self::CONSUMERS[0]);
        $at = strpos($code, 'IDCARD_V1_TELEMETRY');
        $this->assertNotFalse($at);
        $this->assertStringContainsString('catch (\Throwable', substr($code, $at, 600));
    }

    // ══ E. Rejection messages leak nothing ═════════════════════════════

    public function test_rejection_messages_do_not_reveal_why(): void
    {
        // The difference between forged / superseded / revoked is exactly the
        // oracle an attacker probes for.
        $superseded = \Id_card_service::rejectionMessage('superseded');
        $noCard     = \Id_card_service::rejectionMessage('no_active_card');
        $this->assertSame($superseded, $noCard, 'these must be indistinguishable at the scanner');
        foreach (['superseded', 'revoked', 'forged', 'signature'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $superseded);
        }
    }

    public function test_cross_tenant_may_be_named_because_it_is_not_an_oracle(): void
    {
        // Telling a holder their card is from another school reveals nothing
        // they do not already know, and is the actionable message.
        $this->assertStringContainsString('different school', \Id_card_service::rejectionMessage('cross_tenant'));
    }

    // ══ F. Enforcement still gated ═════════════════════════════════════

    public function test_enforcement_when_enabled_rejects_a_superseded_card(): void
    {
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, ['srNo' => self::SR_A]);
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, ['srNo' => self::SR_A], '', 1);
        $r = $this->svc->validateCredential(
            qr_token_decode(qr_token_encode_v2(self::SCH, self::STU_A, 1)),
            ['enforceV2' => true, 'rejectLegacy' => false, 'rejectV1' => false]
        );
        $this->assertFalse($r['valid']);
        $this->assertSame('superseded', $r['code']);
    }

    public function test_retiring_legacy_still_does_not_retire_v1(): void
    {
        $this->assertTrue($this->svc->validateCredential(
            qr_token_decode(qr_token_encode(self::SCH, self::STU_A)),
            ['rejectLegacy' => true])['valid']);
    }

    // ══ G. Boundaries ══════════════════════════════════════════════════

    public function test_attendance_working_day_logic_was_not_touched(): void
    {
        // ORIGINAL INTENT, PRESERVED: Phase 19L wired credentials only and must
        // not have invented its own working-day resolution.
        //
        // UPDATED BY THE CALENDAR PROGRAMME (Phase 5B/5C, authorised). This test
        // pinned the literal `date('w'` and the `_get_sundays` symbol as proof
        // that nothing had moved. The Calendar programme deliberately replaced
        // that hardcoded rule with an effective-dated, per-tenant authority — a
        // school can now run a five-day week — so pinning the literal would now
        // assert the defect rather than the invariant.
        //
        // What Phase 19L actually needs is unchanged and is asserted instead:
        // Attendance resolves student working days through ONE canonical
        // authority and does not carry a credential-phase implementation of its
        // own. The legacy literal survives only as the documented fallback when
        // the calendar cannot be read, which is why it is still permitted here.
        $code = self::codeOnly(self::CONSUMERS[0]);
        $this->assertStringContainsString('student_calendar_reader', $code,
            'working-day resolution must go through the canonical student calendar');
        $this->assertStringContainsString('_weekly_closure_days', $code,
            'the weekly pattern must have exactly one entry point');
        $this->assertStringNotContainsString('_get_sundays', $code,
            'the duplicate Sunday implementation must be gone');
    }

    public function test_no_rtdb_introduced(): void
    {
        foreach (array_merge(self::CONSUMERS, ['application/libraries/Id_card_service.php']) as $rel) {
            foreach (['getDatabase', 'firebaseio', 'database()->'] as $n) {
                $this->assertStringNotContainsString($n, self::codeOnly($rel), $rel);
            }
        }
    }
}
