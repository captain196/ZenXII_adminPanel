<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';

/**
 * Phase 19I — one credential authority + the replacement-fee policy.
 *
 * TWO THINGS ARE BUILT HERE AND BOTH ARE INERT BY DEFAULT.
 *
 * 1. `Id_card_service::validateCredential()` — the single lifecycle authority
 *    every QR consumer must reach its verdict through. Before it, three
 *    consumers each decided independently what to do with a legacy token, which
 *    is three copies of a security policy that can drift.
 *
 * 2. `Id_card_fee_policy` — decides whether a replacement is chargeable and
 *    under which fee head. It raises nothing; the caller hands the payload to
 *    the existing `Fee_lifecycle::createModuleFee()`.
 *
 * ⚠ Enforcement flags default OFF and every fee amount defaults to ZERO,
 * because SEC-ID-3 telemetry is undeployed and no evidence exists about
 * legacy cards in circulation. Turning enforcement on now would reject every
 * legitimate holder on the first scan.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19ICredentialAuthorityAndFeeTest extends TestCase
{
    private const SCH   = 'SCH_TEST01';
    private const SCH_B = 'SCH_TEST02';
    private const STU_A = 'STU0012';  private const SR_A = '47';
    private const STU_B = 'STU0047';

    private $fb;
    private $fs;
    private \Id_card_service $svc;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/application/helpers/qr_token_helper.php';
        foreach (['Id_card_service', 'Id_card_fee_policy'] as $c) {
            if (!class_exists($c)) require_once dirname(__DIR__, 2) . "/application/libraries/{$c}.php";
        }
    }

    protected function setUp(): void
    {
        $this->fb  = new FakeIdCardFirebase();
        $this->fs  = new FakeIdCardFs(self::SCH, $this->fb);
        $this->svc = (new \Id_card_service())->init($this->fb, $this->fs, self::SCH, 'ADM001');
    }

    private function issueFirst(string $stu = self::STU_A): array
    {
        return $this->svc->issue($stu, \Id_card_service::REASON_FIRST_ISSUE, ['srNo' => self::SR_A]);
    }
    private function dec(string $tok): ?array { return qr_token_decode($tok); }

    /**
     * A PHP file with comments removed, for "must NOT contain X" scans.
     * Without this, a docblock that EXPLAINS why the class defers to
     * createModuleFee fails the very test asserting it does not call it — the
     * documentation defeating its own guard.
     */
    private static function codeOnly(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else $out .= $t;
        }
        return $out;
    }
    private function legacyToken(string $sch, string $stu): string
    {
        return rtrim(strtr(base64_encode($sch . '|' . $stu), '+/', '-_'), '=');
    }
    private const ENFORCE = ['enforceV2' => true, 'rejectLegacy' => false, 'rejectV1' => false];

    // ══ A. Default posture — nothing is enforced ════════════════════════

    public function test_with_flags_off_legacy_v1_and_v2_are_all_accepted(): void
    {
        $this->issueFirst();
        $this->assertSame('legacy_accepted',
            $this->svc->validateCredential($this->dec($this->legacyToken(self::SCH, self::STU_A)))['code']);
        $this->assertSame('v1_accepted',
            $this->svc->validateCredential($this->dec(qr_token_encode(self::SCH, self::STU_A)))['code']);
        $this->assertSame('v2_unenforced',
            $this->svc->validateCredential($this->dec(qr_token_encode_v2(self::SCH, self::STU_A, 1)))['code']);
    }

    public function test_with_flags_off_even_a_superseded_card_still_passes(): void
    {
        // Today's behaviour, preserved exactly. Changing it silently would be
        // the school-wide outage this phase exists to avoid.
        $this->issueFirst();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, ['srNo' => self::SR_A]);
        $r = $this->svc->validateCredential($this->dec(qr_token_encode_v2(self::SCH, self::STU_A, 1)));
        $this->assertTrue($r['valid']);
        $this->assertSame('v2_unenforced', $r['code']);
    }

    public function test_unenforced_validation_costs_no_firestore_read(): void
    {
        $before = $this->fs->getCalls;
        $this->svc->validateCredential($this->dec(qr_token_encode_v2(self::SCH, self::STU_A, 3)));
        $this->assertSame($before, $this->fs->getCalls, 'the flags must short-circuit before any read');
    }

    // ══ B. SEC-ID-1 — enforcement, when switched on ════════════════════

    public function test_enforced_a_current_v2_card_is_valid(): void
    {
        $this->issueFirst();
        $r = $this->svc->validateCredential($this->dec(qr_token_encode_v2(self::SCH, self::STU_A, 1)), self::ENFORCE);
        $this->assertTrue($r['valid']);
        $this->assertSame('v2_current', $r['code']);
    }

    public function test_enforced_a_superseded_card_is_rejected(): void
    {
        // THE point of the whole programme: a lost card stops working.
        $this->issueFirst();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, ['srNo' => self::SR_A]);
        $r = $this->svc->validateCredential($this->dec(qr_token_encode_v2(self::SCH, self::STU_A, 1)), self::ENFORCE);
        $this->assertFalse($r['valid']);
        $this->assertSame('superseded', $r['code']);
    }

    public function test_enforced_the_replacement_card_is_valid(): void
    {
        $this->issueFirst();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, ['srNo' => self::SR_A]);
        $this->assertTrue($this->svc->validateCredential(
            $this->dec(qr_token_encode_v2(self::SCH, self::STU_A, 2)), self::ENFORCE)['valid']);
    }

    public function test_enforced_a_revoked_card_is_rejected(): void
    {
        $this->issueFirst();
        $this->svc->revoke(self::STU_A, \Id_card_service::REASON_STOLEN);
        $r = $this->svc->validateCredential($this->dec(qr_token_encode_v2(self::SCH, self::STU_A, 1)), self::ENFORCE);
        $this->assertFalse($r['valid']);
        $this->assertSame('no_active_card', $r['code']);
    }

    public function test_enforced_a_student_with_no_managed_card_is_rejected(): void
    {
        // A valid signature must not stand in for a live card. This is exactly
        // why enforcement cannot be switched on before everyone is reissued.
        $r = $this->svc->validateCredential($this->dec(qr_token_encode_v2(self::SCH, self::STU_B, 1)), self::ENFORCE);
        $this->assertFalse($r['valid']);
        $this->assertSame('no_active_card', $r['code']);
    }

    public function test_enforcement_does_not_affect_v1_or_legacy(): void
    {
        // Neither names a card version, so IDCARD_ENFORCE_V2 cannot apply.
        // Only their own dedicated flags can retire them.
        $this->issueFirst();
        $this->assertTrue($this->svc->validateCredential(
            $this->dec(qr_token_encode(self::SCH, self::STU_A)), self::ENFORCE)['valid']);
        $this->assertTrue($this->svc->validateCredential(
            $this->dec($this->legacyToken(self::SCH, self::STU_A)), self::ENFORCE)['valid']);
    }

    // ══ C. SEC-ID-2 — legacy / V1 retirement ═══════════════════════════

    public function test_legacy_is_rejected_only_when_its_own_flag_is_set(): void
    {
        $r = $this->svc->validateCredential($this->dec($this->legacyToken(self::SCH, self::STU_A)),
            ['rejectLegacy' => true]);
        $this->assertFalse($r['valid']);
        $this->assertSame('legacy_rejected', $r['code']);
    }

    public function test_retiring_legacy_does_not_retire_v1(): void
    {
        // A V1 card is cryptographically sound and merely predates versioning.
        // Conflating the two would force a school to reprint sound cards to
        // close an unrelated hole.
        $this->assertTrue($this->svc->validateCredential(
            $this->dec(qr_token_encode(self::SCH, self::STU_A)), ['rejectLegacy' => true])['valid']);
    }

    public function test_v1_is_rejected_only_by_its_own_flag(): void
    {
        $r = $this->svc->validateCredential($this->dec(qr_token_encode(self::SCH, self::STU_A)),
            ['rejectV1' => true]);
        $this->assertFalse($r['valid']);
        $this->assertSame('v1_rejected', $r['code']);
    }

    public function test_full_retirement_still_accepts_a_current_v2_card(): void
    {
        $this->issueFirst();
        $all = ['enforceV2' => true, 'rejectLegacy' => true, 'rejectV1' => true];
        $this->assertTrue($this->svc->validateCredential(
            $this->dec(qr_token_encode_v2(self::SCH, self::STU_A, 1)), $all)['valid']);
        $this->assertFalse($this->svc->validateCredential(
            $this->dec($this->legacyToken(self::SCH, self::STU_A)), $all)['valid']);
        $this->assertFalse($this->svc->validateCredential(
            $this->dec(qr_token_encode(self::SCH, self::STU_A)), $all)['valid']);
    }

    // ══ D. Always-on invariants ════════════════════════════════════════

    public function test_a_null_or_malformed_decode_is_always_invalid(): void
    {
        $this->assertFalse($this->svc->validateCredential(null)['valid']);
        $this->assertSame('malformed', $this->svc->validateCredential(null)['code']);
        $this->assertFalse($this->svc->validateCredential(['studentId' => '', 'schoolId' => ''])['valid']);
    }

    public function test_a_cross_tenant_credential_is_rejected_even_with_all_flags_off(): void
    {
        // Tenant isolation is a property of the credential, not a per-consumer
        // policy, so no flag can switch it off.
        $r = $this->svc->validateCredential($this->dec(qr_token_encode_v2(self::SCH_B, self::STU_A, 1)));
        $this->assertFalse($r['valid']);
        $this->assertSame('cross_tenant', $r['code']);
    }

    public function test_an_unknown_token_version_fails_closed(): void
    {
        $r = $this->svc->validateCredential(
            ['schoolId' => self::SCH, 'studentId' => self::STU_A, 'tokenVersion' => 'v9', 'legacy' => false]);
        $this->assertFalse($r['valid']);
        $this->assertSame('unknown_token_version', $r['code']);
    }

    public function test_a_forged_token_never_reaches_validation(): void
    {
        // Authenticity is qr_token_decode()'s job; this method only decides
        // whether an AUTHENTIC token names a live card.
        $good = qr_token_encode_v2(self::SCH, self::STU_A, 1);
        $pay  = base64_decode(strtr($good, '-_', '+/') . str_repeat('=', (4 - strlen($good) % 4) % 4));
        [$s, $u, $v, $sig] = explode('|', $pay);
        $bad = rtrim(strtr(base64_encode("{$s}|{$u}|{$v}|" . str_repeat('0', 16)), '+/', '-_'), '=');
        $this->assertNull($this->dec($bad));
        $this->assertFalse($this->svc->validateCredential($this->dec($bad))['valid']);
    }

    public function test_one_students_card_never_validates_for_another(): void
    {
        $this->issueFirst(self::STU_A);
        $this->issueFirst(self::STU_B);
        $r = $this->svc->validateCredential($this->dec(qr_token_encode_v2(self::SCH, self::STU_A, 1)), self::ENFORCE);
        $this->assertSame(self::STU_A, $r['studentId'], 'the subject must be the token holder, never the scanner context');
    }

    // ══ E. Migration readiness — evidence, not a deadline ══════════════

    public function test_readiness_is_false_while_any_student_lacks_a_card(): void
    {
        $this->issueFirst();
        $r = $this->svc->migrationReadiness(10);
        $this->assertSame(1, $r['withManagedCard']);
        $this->assertSame(9, $r['neverIssued']);
        $this->assertFalse($r['safeToEnforceV2']);
    }

    public function test_readiness_is_true_once_every_student_holds_a_card(): void
    {
        $this->issueFirst(self::STU_A);
        $this->issueFirst(self::STU_B);
        $r = $this->svc->migrationReadiness(2);
        $this->assertSame(0, $r['neverIssued']);
        $this->assertTrue($r['safeToEnforceV2']);
    }

    public function test_readiness_never_claims_to_know_physical_legacy_circulation(): void
    {
        // The server can only know who has been ISSUED a card, not what a
        // family carries. Only SEC-ID-3 telemetry can show that, and it is
        // undeployed — so this must not imply otherwise.
        $r = $this->svc->migrationReadiness(1);
        $this->assertFalse($r['legacyHoldersKnown']);
        $this->assertStringContainsString('SEC-ID-3', $r['note']);
    }

    public function test_readiness_costs_one_query(): void
    {
        $this->issueFirst();
        $before = $this->fs->queryCalls;
        $this->svc->migrationReadiness(1);
        $this->assertSame(1, $this->fs->queryCalls - $before);
    }

    // ══ F. Replacement fee policy ══════════════════════════════════════

    private function policy(array $amounts = []): \Id_card_fee_policy
    {
        return (new \Id_card_fee_policy())->init($this->fs, $amounts, 'ID Card Replacement', 'IDCard');
    }

    public function test_every_amount_defaults_to_zero_so_nothing_is_charged(): void
    {
        $p = $this->policy();
        foreach (['lost', 'stolen', 'damaged', 'wear', 'data_correction', 'admin'] as $r) {
            $this->assertSame(0.0, $p->amountFor($r), "{$r} must default to free");
            $this->assertNull($p->demandPayloadFor($r, 2));
        }
    }

    public function test_first_issue_can_never_be_charged(): void
    {
        // Not merely zero by default — structurally excluded, so no config
        // value can make an admission card chargeable.
        $p = $this->policy(['first_issue' => 500.0]);
        $this->assertSame(0.0, $p->amountFor('first_issue'));
        $this->assertNull($p->demandPayloadFor('first_issue', 1));
        $this->assertNotContains('first_issue', \Id_card_fee_policy::chargeableReasons());
    }

    public function test_wear_and_school_error_are_never_chargeable(): void
    {
        $p = $this->policy(['wear' => 300.0, 'data_correction' => 300.0, 'admin' => 300.0]);
        foreach (['wear', 'data_correction', 'admin'] as $r) {
            $this->assertSame(0.0, $p->amountFor($r), "{$r} is not the family's fault");
        }
    }

    public function test_a_configured_amount_is_used_for_a_chargeable_reason(): void
    {
        $p = $this->policy(['lost' => 150.0]);
        $this->assertSame(150.0, $p->amountFor('lost'));
        $this->assertTrue($p->isChargeable('lost'));
    }

    public function test_a_school_override_beats_the_config_fallback(): void
    {
        $p = $this->policy(['lost' => 150.0]);
        $this->assertSame(250.0, $p->amountFor('lost', ['lost' => 250.0]));
    }

    public function test_a_negative_or_non_numeric_amount_can_never_charge(): void
    {
        $p = $this->policy(['lost' => -500.0, 'stolen' => 'free', 'damaged' => null]);
        foreach (['lost', 'stolen', 'damaged'] as $r) {
            $this->assertSame(0.0, $p->amountFor($r));
        }
    }

    // ══ G. ID-D2 — the demand-identity defect this design exists to avoid ══

    /**
     * UPDATED 2026-08-17 — Fee Head Registry phase. The REQUIREMENT is
     * unchanged (two replacements must remain two charges); the MECHANISM is
     * deliberately retired.
     *
     * WAS: the fee head itself was version-scoped ("ID Card Replacement
     * (Card v2)"), so the head name was what separated the two demands.
     * That minted an UNBOUNDED family of head names — impossible to register
     * in the school-scoped Fee Head Registry, so the second replacement would
     * have failed closed once module fees must resolve a registered head —
     * and it made a mutable DISPLAY STRING load-bearing for financial
     * identity.
     *
     * NOW: ONE stable head ("ID Card Replacement", classified administrative)
     * plus an explicit charge instance, `V{cardVersion}`, taken from the
     * atomically claimed, strictly monotonic, never-reused version minted by
     * Id_card_service::issue().
     */
    public function test_the_fee_head_is_stable_across_card_versions(): void
    {
        $p = $this->policy(['lost' => 100.0]);
        $this->assertSame('ID Card Replacement', $p->feeHeadFor(2));
        $this->assertSame('ID Card Replacement', $p->feeHeadFor(3));
        $this->assertSame($p->feeHeadFor(2), $p->feeHeadFor(3),
            'one head, many instances — the version must NOT be in the name');
        $this->assertStringNotContainsString('Card v', $p->feeHeadFor(2));
    }

    public function test_each_card_version_gets_a_distinct_charge_instance(): void
    {
        $p = $this->policy(['lost' => 100.0]);
        $this->assertSame('V2', $p->instanceKey(2));
        $this->assertSame('V3', $p->instanceKey(3));
        $this->assertNotSame($p->instanceKey(2), $p->instanceKey(3));
    }

    public function test_two_replacements_in_one_session_produce_two_distinct_demand_ids(): void
    {
        // THE regression this design exists for, unchanged in substance:
        // Fee_lifecycle hardcodes the period to the session's April, so if two
        // replacements shared a demand id the second would rewrite the first —
        // and if the first was paid, recompute to balance 0 / status paid,
        // making the second card silently free.
        //
        // The separation now comes from the charge instance, not the head name.
        $p = $this->policy(['lost' => 100.0, 'damaged' => 60.0]);
        $mk = function (string $head, string $instance) {
            $key  = trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($head)), '_');
            $inst = trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($instance)), '_');
            return "DEM_STU0012_202604_{$key}" . ($inst === '' ? '' : "_{$inst}");
        };
        $first  = $mk($p->feeHeadFor(2) . '_IDCard', $p->instanceKey(2));
        $second = $mk($p->feeHeadFor(3) . '_IDCard', $p->instanceKey(3));
        $this->assertNotSame($first, $second);
        $this->assertStringEndsWith('_V2', $first);
        $this->assertStringEndsWith('_V3', $second);
    }

    public function test_resubmitting_the_same_replacement_is_idempotent(): void
    {
        // A double-click or retry carries the SAME cardVersion → same head AND
        // same instance → same demand id → rewrite of identical values, never
        // a duplicate charge.
        $p = $this->policy(['lost' => 100.0]);
        $this->assertSame($p->feeHeadFor(2), $p->feeHeadFor(2));
        $this->assertSame($p->instanceKey(2), $p->instanceKey(2));
        $this->assertEquals($p->demandPayloadFor('lost', 2), $p->demandPayloadFor('lost', 2));
    }

    public function test_the_payload_carries_the_charge_instance(): void
    {
        $pl = $this->policy(['lost' => 100.0])->demandPayloadFor('lost', 7);
        $this->assertSame('ID Card Replacement', $pl['fee_head']);
        $this->assertSame('V7', $pl['chargeInstance'],
            'the version must reach the demand as data, not inside a display string');
    }

    public function test_the_payload_carries_auditable_provenance(): void
    {
        $pl = $this->policy(['stolen' => 200.0])->demandPayloadFor('stolen', 4);
        $this->assertSame('stolen', $pl['reason']);
        $this->assertSame(4, $pl['cardVersion']);
        $this->assertSame(200.0, $pl['amount']);
        $this->assertSame('Annual', $pl['frequency'], 'a one-off event, not a monthly charge');
    }

    public function test_the_policy_raises_no_fee_itself(): void
    {
        // Comment-stripped: the class docblock legitimately EXPLAINS why it
        // defers to createModuleFee, so scanning raw text would fail against
        // its own documentation.
        $src = self::codeOnly(dirname(__DIR__, 2) . '/application/libraries/Id_card_fee_policy.php');
        foreach (['createModuleFee', 'firestoreSet', 'writeDemand', 'feeDemands'] as $n) {
            $this->assertStringNotContainsString($n, $src, 'the policy decides; the canonical generator writes');
        }
    }

    // ══ H. Boundaries still held ═══════════════════════════════════════

    public function test_fee_lifecycle_was_not_modified_for_id_cards(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/application/libraries/Fee_lifecycle.php');
        foreach (['IDCard', 'Id_card', 'idCard'] as $n) {
            $this->assertStringNotContainsString($n, $src, 'no ID-card special case may leak into the fee engine');
        }
    }

    /**
     * SUPERSEDED PREMISE, updated in Phase 19L rather than deleted.
     *
     * 19I asserted the authority was wired nowhere — true then, because the
     * flags did not yet exist as a deployable gate. 19L wired all three
     * consumers, which is safe precisely BECAUSE the flags ship off: the
     * gate, not the absence of wiring, is what keeps enforcement inert.
     * That is the invariant now asserted.
     */
    public function test_enforcement_remains_inert_by_configuration(): void
    {
        foreach ([
            'application/controllers/Attendance.php',
            'application/libraries/Transport_qr_attendance_service.php',
            'application/libraries/Visitor_service.php',
        ] as $rel) {
            $this->assertStringContainsString('validateCredential',
                (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel), $rel);
        }

        $cfg = (string) file_get_contents(dirname(__DIR__, 2) . '/application/config/id_card_flags.php');
        foreach (['IDCARD_ENFORCE_V2', 'IDCARD_REJECT_LEGACY', 'IDCARD_REJECT_V1'] as $f) {
            $this->assertMatchesRegularExpression("/'{$f}'\]\s*=\s*false/", $cfg);
        }

        // And the authority itself still accepts everything with flags off.
        $this->issueFirst();
        $this->assertSame('v2_unenforced',
            $this->svc->validateCredential($this->dec(qr_token_encode_v2(self::SCH, self::STU_A, 1)))['code']);
    }

    public function test_all_flags_ship_off_and_all_amounts_ship_zero(): void
    {
        $cfg = (string) file_get_contents(dirname(__DIR__, 2) . '/application/config/id_card_flags.php');
        foreach (['IDCARD_ENFORCE_V2', 'IDCARD_REJECT_LEGACY', 'IDCARD_REJECT_V1'] as $f) {
            $this->assertMatchesRegularExpression("/'{$f}'\]\s*=\s*false/", $cfg, "{$f} must ship off");
        }
        $this->assertDoesNotMatchRegularExpression("/=>\s*[1-9][0-9.]*\s*,/", $cfg,
            'no non-zero default fee amount may ship');
    }
}
