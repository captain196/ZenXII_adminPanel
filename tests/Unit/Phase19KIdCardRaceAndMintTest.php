<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';

/**
 * Phase 19K — two defects found by independently re-verifying 19I/19J.
 *
 * ID-RACE-1 (HIGH) — `issue()` had no server-side idempotency. Two rapid
 * Replace submissions each read the same prior version and each atomically
 * claimed the NEXT one, producing v2 AND v3: two cards and, once amounts are
 * configured, TWO replacement fee demands for one real event. The panel's
 * busy-lock is client-side only and cannot stop a submit that beats it, a
 * retry, or a second operator. Fixed with the optimistic-concurrency
 * assertion Sr_no_service::relink() already establishes.
 *
 * ID-MINT-1 (HIGH) — the printed card always minted a V1 token while
 * `issue()` recorded a versioned V2 card. An operator could reissue a
 * student, see card v2 in the register, and print a card whose QR was still
 * un-versioned. `migrationReadiness()` would then report "safe to enforce"
 * while not one physical card was V2 — the migration could never complete,
 * and the failure was invisible. Fixed by minting from the card record.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19KIdCardRaceAndMintTest extends TestCase
{
    private const SCH   = 'SCH_TEST01';
    private const STU_A = 'STU0012';  private const SR_A = '47';

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

    private function ident(): array { return ['srNo' => self::SR_A]; }

    private function first(): array
    {
        return $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, $this->ident());
    }

    private function activeVersionCount(): int
    {
        $n = 0;
        foreach ($this->fb->docs['idCards'] ?? [] as $d) {
            if (($d['kind'] ?? '') === \Id_card_service::KIND_VERSION) $n++;
        }
        return $n;
    }

    // ══ ID-RACE-1 ═══════════════════════════════════════════════════════

    public function test_the_defect_without_the_assertion_a_double_submit_makes_two_cards(): void
    {
        // Documents the behaviour the fix exists to stop. Both calls omit the
        // assertion, exactly as a pre-19K caller would.
        $this->first();
        $a = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident());
        $b = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident());
        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertSame(2, $a['version']);
        $this->assertSame(3, $b['version'], 'one real event, two cards — the defect');
    }

    public function test_a_double_submit_is_refused_when_the_version_is_asserted(): void
    {
        $this->first();
        $a = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);
        $b = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);

        $this->assertTrue($a['ok']);
        $this->assertSame(2, $a['version']);
        $this->assertFalse($b['ok'], 'the second submit must not mint another card');
        $this->assertSame('version_conflict', $b['reason']);
        $this->assertSame(2, $this->svc->currentVersion(self::STU_A));
    }

    public function test_a_refused_race_writes_nothing_at_all(): void
    {
        $this->first();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);
        $before = $this->activeVersionCount();

        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);
        $this->assertSame($before, $this->activeVersionCount(), 'a losing race must leave no partial state');
    }

    public function test_a_refused_race_reports_the_real_current_version(): void
    {
        // So the panel can resync instead of the operator guessing.
        $this->first();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);
        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_DAMAGED, $this->ident(), '', 1);
        $this->assertSame(2, $r['version']);
    }

    public function test_a_correctly_sequenced_second_replacement_still_succeeds(): void
    {
        // The guard must stop duplicates, not legitimate repeat replacements.
        $this->first();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);
        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_DAMAGED, $this->ident(), '', 2);
        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['version']);
    }

    public function test_omitting_the_assertion_preserves_the_previous_behaviour(): void
    {
        $this->first();
        $this->assertTrue($this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident())['ok']);
    }

    public function test_a_first_issue_can_assert_version_zero(): void
    {
        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, $this->ident(), '', 0);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['version']);
    }

    public function test_a_duplicate_first_issue_is_still_refused_by_the_reason_guard(): void
    {
        // The more specific refusal must win over version_conflict.
        $this->first();
        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, $this->ident(), '', 0);
        $this->assertSame('card_already_issued', $r['reason']);
    }

    public function test_a_refused_race_cannot_produce_a_second_fee_demand(): void
    {
        // The money consequence. One real event must yield at most one
        // chargeable card version.
        $policy = (new \Id_card_fee_policy())->init($this->fs, ['lost' => 100.0], 'ID Card Replacement', 'IDCard');
        $this->first();

        $a = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);
        $b = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);

        $heads = []; $instances = [];
        foreach ([$a, $b] as $r) {
            if (!empty($r['ok'])) {
                $heads[]     = $policy->feeHeadFor((int) $r['version']);
                $instances[] = $policy->instanceKey((int) $r['version']);
            }
        }
        $this->assertCount(1, $heads, 'only one chargeable version may result');
        // UPDATED 2026-08-17 (Fee Head Registry): the head is now STABLE across
        // versions and the version travels as the explicit charge instance, so the
        // refused race is proven by there being ONE instance, not one head name.
        $this->assertSame('ID Card Replacement', $heads[0]);
        $this->assertSame(['V2'], $instances, 'exactly one chargeable instance');
    }

    // ══ Part 10 — multiple legitimate replacements ═════════════════════

    public function test_two_legitimate_replacements_produce_two_separate_demands(): void
    {
        $policy = (new \Id_card_fee_policy())->init($this->fs, ['lost' => 100.0, 'damaged' => 60.0], 'ID Card Replacement', 'IDCard');
        $this->first();

        $v2 = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);
        $v3 = $this->svc->issue(self::STU_A, \Id_card_service::REASON_DAMAGED, $this->ident(), '', 2);

        $p2 = $policy->demandPayloadFor('lost', $v2['version']);
        $p3 = $policy->demandPayloadFor('damaged', $v3['version']);

        // UPDATED 2026-08-17 (Fee Head Registry). The REQUIREMENT is unchanged —
        // the second replacement must not overwrite the first — but separation is
        // now carried by the charge INSTANCE, not by a version-scoped head name.
        // The head is deliberately shared: one head, many instances.
        $this->assertSame($p2['fee_head'], $p3['fee_head'], 'one stable head');
        $this->assertNotSame($p2['chargeInstance'], $p3['chargeInstance'],
            'the second must not overwrite the first');
        $this->assertSame(100.0, $p2['amount']);
        $this->assertSame(60.0,  $p3['amount']);
        $this->assertSame('V2', $p2['chargeInstance']);
        $this->assertSame('V3', $p3['chargeInstance']);
    }

    public function test_the_earlier_card_record_survives_a_later_replacement(): void
    {
        // Nothing about replacement v3 may disturb the v2 record a paid
        // receipt refers to.
        $this->first();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);
        $v2Before = $this->fs->get('idCards', $this->svc->versionDocId(self::STU_A, 2));

        $this->svc->issue(self::STU_A, \Id_card_service::REASON_DAMAGED, $this->ident(), '', 2);
        $v2After = $this->fs->get('idCards', $this->svc->versionDocId(self::STU_A, 2));

        $this->assertSame($v2Before['reason'], $v2After['reason'], 'the original reason must be preserved');
        $this->assertSame(2, $v2After['version']);
        $this->assertSame(3, $v2After['supersededByVersion']);
    }

    // ══ ID-MINT-1 ═══════════════════════════════════════════════════════

    public function test_a_managed_card_mints_a_v2_credential_naming_its_version(): void
    {
        $this->first();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->ident(), '', 1);
        $tok = qr_token_encode_v2(self::SCH, self::STU_A, $this->svc->currentVersion(self::STU_A));
        $d   = qr_token_decode($tok);
        $this->assertSame('v2', $d['tokenVersion']);
        $this->assertSame(2, $d['cardVersion']);
    }

    public function test_the_printed_card_selects_its_token_from_the_card_record(): void
    {
        // The view must not hardcode one mint. Comment-stripped so the note
        // explaining the old behaviour cannot satisfy the scan.
        $v = self::viewCode();
        $this->assertStringContainsString('$card_versions[$uid]', $v);
        $this->assertStringContainsString('qr_token_encode_v2($qrSchoolId, $uid, $__cardVer)', $v);
        $this->assertStringContainsString('qr_token_encode($qrSchoolId, $uid)', $v,
            'students with no managed card must still get a working V1 token');
    }

    public function test_the_controller_supplies_only_active_card_versions(): void
    {
        // A revoked or replaced version must never be printed as current.
        $src = self::codeOnly(dirname(__DIR__, 2) . '/application/controllers/Sis.php');
        $this->assertStringContainsString("\$data['card_versions']", $src);
        $this->assertStringContainsString('Id_card_service::STATUS_ACTIVE', $src);
    }

    public function test_the_card_version_map_costs_one_query(): void
    {
        $this->first();
        $before = $this->fs->queryCalls;
        $this->svc->registerForSchool();
        $this->assertSame(1, $this->fs->queryCalls - $before, 'never one read per student');
    }

    public function test_a_revoked_student_is_not_given_a_printable_version(): void
    {
        $this->first();
        $this->svc->revoke(self::STU_A, \Id_card_service::REASON_STOLEN);
        $reg = $this->svc->registerForSchool();
        $this->assertSame(\Id_card_service::STATUS_REVOKED, $reg[self::STU_A]['status']);
        // The controller filters on STATUS_ACTIVE, so this student falls back
        // to V1 rather than printing a revoked version as if it were live.
        $this->assertNotSame(\Id_card_service::STATUS_ACTIVE, $reg[self::STU_A]['status']);
    }

    public function test_the_credential_still_binds_only_student_id(): void
    {
        $v = self::viewCode();
        $this->assertDoesNotMatchRegularExpression(
            '/qr_token_encode(_v2)?\(\s*\$qrSchoolId\s*,\s*\$(srNo|admNo|rollNo|cls|sec)\b/', $v
        );
    }

    // ══ Boundaries still held ══════════════════════════════════════════

    /**
     * SUPERSEDED PREMISE, updated in Phase 19L rather than deleted.
     * The authority is now wired into all three consumers; what keeps
     * enforcement inert is the flag gate, which is what this asserts.
     */
    public function test_enforcement_is_wired_but_still_off(): void
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
        foreach (['IDCARD_ENFORCE_V2', 'IDCARD_REJECT_LEGACY', 'IDCARD_REJECT_V1', 'IDCARD_V1_TELEMETRY'] as $f) {
            $this->assertMatchesRegularExpression("/'{$f}'\]\s*=\s*false/", $cfg);
        }
    }

    public function test_v2_minting_does_not_break_legacy_or_v1_acceptance(): void
    {
        // Printing V2 changes what NEW cards carry; it must not change what
        // existing cards are worth.
        $legacy = rtrim(strtr(base64_encode(self::SCH . '|' . self::STU_A), '+/', '-_'), '=');
        $this->assertTrue($this->svc->validateCredential(qr_token_decode($legacy))['valid']);
        $this->assertTrue($this->svc->validateCredential(
            qr_token_decode(qr_token_encode(self::SCH, self::STU_A)))['valid']);
    }

    // ── helpers ─────────────────────────────────────────────────────────

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

    /** JS lives in T_INLINE_HTML, so JS comments need stripping textually. */
    private static function viewCode(): string
    {
        $v = (string) file_get_contents(dirname(__DIR__, 2) . '/application/views/sis/id_card.php');
        $v = preg_replace('#/\*.*?\*/#s', ' ', $v);
        return (string) preg_replace('#^\s*//.*$#m', ' ', $v);
    }
}
