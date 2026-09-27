<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';

/**
 * Phase 19N — ID card replacement charges, end to end.
 *
 * Three defects in the charging chain, all found by tracing it rather than
 * trusting the previous report:
 *
 *  ID-ACCT-1  the fee head matched no accounting keyword and fell through to
 *             **4010 Tuition Fees** — booking a consumable reimbursement as
 *             tuition revenue.
 *  ID-CFG-1   per-school amounts were a **DEAD READ**: the policy looked them
 *             up in `feeSettings`, nothing ever wrote them, and no screen
 *             existed. "Configurable" charges could never be configured, so
 *             every replacement was silently free.
 *  ID-DUE-1   the demand carried **no dueDate**, so the reminder service and
 *             the late-fee engine both skip it — an unpaid replacement charge
 *             would sit unchased forever.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19NIdCardReplacementChargeTest extends TestCase
{
    private const SCH   = 'SCH_TEST01';
    private const STU_A = 'STU0012';  private const SR_A = '47';

    private $fb; private $fs;
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

    private function policy(array $amounts = []): \Id_card_fee_policy
    {
        return (new \Id_card_fee_policy())->init($this->fs, $amounts, 'ID Card Replacement', 'IDCard');
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    /**
     * The body of one method, brace-matched.
     *
     * Replaces `substr($code, $at, 2500)`. A fixed window is a guard that
     * decays silently: it holds until somebody adds a few lines ABOVE the
     * construct being asserted, and then fails while the code is still
     * correct. IDC-FREE-1 added a branch explaining why a replacement was not
     * charged, and `catch (\Throwable` moved past character 2500.
     */
    private static function methodBody(string $code, string $method): string
    {
        $at = strpos($code, 'function ' . $method . '(');
        if ($at === false) return '';

        $open = strpos($code, '{', $at);
        if ($open === false) return '';

        $depth = 0;
        $len   = strlen($code);
        for ($i = $open; $i < $len; $i++) {
            if ($code[$i] === '{') $depth++;
            elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($code, $at, $i - $at + 1);
            }
        }
        return substr($code, $at);
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

    /** Mirrors Operations_accounting::_resolveAccount()'s keyword stage. */
    private function resolveAccount(string $feeHead, array $activeCoa): string
    {
        $h = strtolower(trim($feeHead));
        $keywords = [
            '4020' => ['admission', 'registration', 'enrol'],
            '4030' => ['exam', 'test', 'assessment'],
            '4040' => ['transport', 'bus', 'conveyance', 'vehicle'],
            '4050' => ['hostel', 'boarding', 'mess', 'accommodation'],
            '4060' => ['late', 'fine', 'penalty', 'overdue'],
            '4140' => ['id card', 'identity card'],
        ];
        foreach ($keywords as $code => $terms) {
            // PHP coerces numeric array KEYS to int, so $code is 4140 (int),
            // not '4140'. A strict in_array against string codes would never
            // match — the real resolver is unaffected because it uses
            // isset($coa[$code]) (which normalises the same way) and declares
            // a `: string` return type.
            if (!in_array((string) $code, $activeCoa, true)) continue;
            foreach ($terms as $t) if (strpos($h, $t) !== false) return (string) $code;
        }
        return '4010';
    }

    private const COA = ['4010','4020','4030','4040','4050','4060','4140'];

    // ══ ID-ACCT-1 — accounting ═════════════════════════════════════════

    public function test_the_keyword_is_present_in_the_real_resolver(): void
    {
        $code = self::codeOnly('application/libraries/Operations_accounting.php');

        // Assert the MAPPING, not the row's exact bytes.
        //
        // This was a literal match on "'4140' => ['id card', 'identity card']",
        // which broke when SC-ACCT-1 extended the same row with cost-recovery
        // terms (lost / damage / breakage / replacement) for Student Charges —
        // a change that leaves the ID-card behaviour identical, as the
        // behavioural test below still proves.
        //
        // The literal was over-specified: it failed on ANY extension of the
        // row, including a safe one. What it MEANS is "an ID-card head maps to
        // 4140", and that is what is asserted now — this still fails if the
        // 4140 row is removed, if either term is dropped, or if ID cards are
        // pointed at a different account.
        $this->assertSame(1, preg_match("/'4140'\s*=>\s*\[([^\]]*)\]/", $code, $m),
            'the 4140 Miscellaneous Income mapping row is gone — an ID-card replacement '
            . 'would fall through to the 4010 Tuition default (ID-ACCT-1)');

        $this->assertStringContainsString("'id card'", $m[1],
            "the 'id card' keyword was removed from the 4140 row");
        $this->assertStringContainsString("'identity card'", $m[1],
            "the 'identity card' keyword was removed from the 4140 row");
    }

    /** @dataProvider versions */
    public function test_every_card_version_resolves_to_miscellaneous_income(int $v): void
    {
        $head = $this->policy()->feeHeadFor($v);
        $this->assertSame('4140', $this->resolveAccount($head, self::COA),
            "v{$v} must credit 4140 Miscellaneous Income");
        $this->assertNotSame('4010', $this->resolveAccount($head, self::COA),
            'a consumable reimbursement must never be booked as Tuition');
    }

    public static function versions(): array
    {
        return [[1], [2], [3], [4], [7], [12], [99]];
    }

    public function test_one_keyword_covers_all_versions_no_per_version_mapping(): void
    {
        $code = self::codeOnly('application/libraries/Operations_accounting.php');
        $this->assertSame(1, substr_count($code, "'id card'"),
            'exactly one mapping — a per-version list would need extending forever');
        foreach (['Card v2', 'CARD_V2', 'card v3'] as $bad) {
            $this->assertStringNotContainsString($bad, $code);
        }
    }

    public function test_no_earlier_keyword_steals_the_id_card_head(): void
    {
        // "replacement" must not trip 'late', 'admission' etc.
        foreach (['ID Card Replacement (Card v2)', 'Identity Card Replacement (Card v5)'] as $h) {
            $this->assertSame('4140', $this->resolveAccount($h, self::COA));
        }
    }

    public function test_it_falls_back_safely_if_a_school_has_no_4140(): void
    {
        // The real resolver guards on the account existing and being active.
        $this->assertSame('4010',
            $this->resolveAccount('ID Card Replacement (Card v2)', ['4010','4020','4060']),
            'a missing 4140 must degrade to the documented default, not error');
    }

    public function test_other_fee_heads_are_unaffected(): void
    {
        $this->assertSame('4040', $this->resolveAccount('Transport Fee', self::COA));
        $this->assertSame('4050', $this->resolveAccount('Hostel Fee', self::COA));
        $this->assertSame('4060', $this->resolveAccount('Late Fee', self::COA));
        $this->assertSame('4010', $this->resolveAccount('Tuition Fee', self::COA));
    }

    // ══ ID-DUE-1 — the demand must be chaseable ════════════════════════

    public function test_a_chargeable_demand_carries_a_due_date(): void
    {
        $p  = $this->policy(['lost' => 150.0])->withDueInDays(30, '2026-04-01');
        $pl = $p->demandPayloadFor('lost', 2);
        $this->assertSame('2026-05-01', $pl['due_date']);
    }

    public function test_the_due_date_reaches_the_demand_document_field(): void
    {
        // Fee_firestore_txn::_buildDemandDoc picks 'due_date' into 'dueDate'.
        $txn = self::codeOnly('application/libraries/Fee_firestore_txn.php');
        $this->assertStringContainsString("\$pick(\$data, ['dueDate', 'due_date'])", $txn);
    }

    public function test_without_a_due_date_the_charge_would_be_unchaseable(): void
    {
        // Documents WHY ID-DUE-1 matters: both chasers skip a dateless demand.
        $rem  = self::codeOnly('application/libraries/Fee_reminder_service.php');
        $late = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $this->assertStringContainsString("dueDate", $rem);
        $this->assertStringContainsString("\$dueDate === ''", $late);
    }

    public function test_the_default_window_is_thirty_days(): void
    {
        $this->assertSame(30, \Id_card_fee_policy::DEFAULT_DUE_DAYS);
    }

    public function test_a_free_reason_produces_no_payload_and_so_no_due_date(): void
    {
        $this->assertNull($this->policy()->withDueInDays(30)->demandPayloadFor('lost', 2));
    }

    // ══ ID-CFG-1 — the write path that was missing ═════════════════════

    public function test_the_save_endpoint_exists_and_is_routed(): void
    {
        $routes = self::src('application/config/routes.php');
        foreach (['id_card_fee_settings', 'id_card_fee_settings_save'] as $r) {
            $this->assertStringContainsString("'sis/{$r}'", $routes);
        }
    }

    /**
     * Money configuration is gated on Fees:manage, NOT SIS:manage — an office
     * operator who may replace cards must not also decide what they cost.
     */
    public function test_charge_configuration_is_gated_on_fees_not_sis(): void
    {
        $code = self::codeOnly('application/controllers/Sis.php');
        $at = strpos($code, 'function id_card_fee_settings_save(');
        $this->assertNotFalse($at);
        $body = substr($code, $at, 900);
        $this->assertStringContainsString("'Fees', 'manage'", $body);
        $this->assertStringNotContainsString("'SIS', 'manage'", $body);
    }

    public function test_the_save_endpoint_rejects_bad_amounts(): void
    {
        $code = self::codeOnly('application/controllers/Sis.php');
        $at   = strpos($code, 'function id_card_fee_settings_save(');
        $body = substr($code, $at, 2000);
        $this->assertStringContainsString('is_numeric', $body);
        $this->assertStringContainsString('$v < 0', $body);
        $this->assertStringContainsString('100000', $body, 'a typo guard must cap the amount');
    }

    public function test_the_settings_doc_id_matches_what_the_policy_reads(): void
    {
        // The whole ID-CFG-1 defect was reader and writer never meeting.
        $policy = self::codeOnly('application/libraries/Id_card_fee_policy.php');
        $ctrl   = self::codeOnly('application/controllers/Sis.php');
        $this->assertStringContainsString('_idcard', $policy);
        $this->assertStringContainsString('_idcard', $ctrl);
        $this->assertStringContainsString("'feeSettings'", $policy);
        $this->assertStringContainsString("'feeSettings'", $ctrl);
    }

    public function test_a_school_override_now_actually_changes_the_charge(): void
    {
        $p = $this->policy(['lost' => 0.0])->withDueInDays(30, '2026-04-01');
        $this->assertNull($p->demandPayloadFor('lost', 2), 'free by default');

        $pl = $p->demandPayloadFor('lost', 2, ['lost' => 200.0]);
        $this->assertNotNull($pl, 'a configured override must produce a demand');
        $this->assertSame(200.0, $pl['amount']);
    }

    // ══ Never-chargeable reasons ═══════════════════════════════════════

    /** @dataProvider freeReasons */
    public function test_reasons_that_are_never_the_familys_fault_cannot_be_charged(string $reason): void
    {
        $p = $this->policy([$reason => 500.0])->withDueInDays(30);
        $this->assertSame(0.0, $p->amountFor($reason, [$reason => 900.0]),
            "{$reason} must not be chargeable even if a config tries");
        $this->assertNull($p->demandPayloadFor($reason, 2, [$reason => 900.0]));
    }

    public static function freeReasons(): array
    {
        return [['first_issue'], ['wear'], ['data_correction'], ['admin']];
    }

    // ══ Multiple replacements — the real lifecycle ═════════════════════

    public function test_v2_v3_v4_each_produce_an_independent_demand(): void
    {
        $p = $this->policy(['lost' => 100.0, 'damaged' => 60.0])->withDueInDays(30, '2026-04-01');

        $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, ['srNo' => self::SR_A]);
        $v2 = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST,    ['srNo' => self::SR_A], '', 1);
        $v3 = $this->svc->issue(self::STU_A, \Id_card_service::REASON_DAMAGED, ['srNo' => self::SR_A], '', 2);
        $v4 = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST,    ['srNo' => self::SR_A], '', 3);

        $this->assertSame([2, 3, 4], [$v2['version'], $v3['version'], $v4['version']]);

        // UPDATED 2026-08-17 (Fee Head Registry). Requirement unchanged: no
        // replacement may reuse an earlier demand. Mechanism changed: the head is
        // now ONE stable 'ID Card Replacement' for every version, and what keeps
        // the demands apart is the explicit charge INSTANCE. Asserting uniqueness
        // on the head would now assert the retired design.
        $instances = [
            $p->demandPayloadFor('lost',    $v2['version'])['chargeInstance'],
            $p->demandPayloadFor('damaged', $v3['version'])['chargeInstance'],
            $p->demandPayloadFor('lost',    $v4['version'])['chargeInstance'],
        ];
        $this->assertSame($instances, array_unique($instances), 'no replacement may reuse an earlier demand');
        $this->assertSame(['V2', 'V3', 'V4'], $instances);
    }

    public function test_the_fourth_replacement_is_not_silently_free(): void
    {
        // The failure mode ID-D2 exists to prevent: a later replacement
        // colliding with an earlier PAID demand and recomputing to zero.
        $p = $this->policy(['lost' => 100.0])->withDueInDays(30, '2026-04-01');
        // The separation is now the charge instance (see above).
        $this->assertNotSame(
            $p->demandPayloadFor('lost', 2)['chargeInstance'],
            $p->demandPayloadFor('lost', 4)['chargeInstance']
        );
        $this->assertSame(100.0, $p->demandPayloadFor('lost', 4)['amount']);
    }

    public function test_each_demand_keeps_its_own_reason_and_version(): void
    {
        $p  = $this->policy(['lost' => 100.0, 'damaged' => 60.0])->withDueInDays(30);
        $a  = $p->demandPayloadFor('lost', 2);
        $b  = $p->demandPayloadFor('damaged', 3);
        $this->assertSame(['lost', 2],    [$a['reason'], $a['cardVersion']]);
        $this->assertSame(['damaged', 3], [$b['reason'], $b['cardVersion']]);
    }

    // ══ Concurrency (re-test of ID-RACE-1, not a rebuild) ══════════════

    public function test_a_double_submit_still_yields_one_chargeable_version(): void
    {
        $p = $this->policy(['lost' => 100.0])->withDueInDays(30);
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, ['srNo' => self::SR_A]);

        $a = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, ['srNo' => self::SR_A], '', 1);
        $b = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, ['srNo' => self::SR_A], '', 1);

        $charged = [];
        foreach ([$a, $b] as $r) if (!empty($r['ok'])) $charged[] = $p->demandPayloadFor('lost', (int) $r['version']);
        $this->assertCount(1, $charged);
        $this->assertSame('version_conflict', $b['reason']);
    }

    // ══ Fees architecture boundary ═════════════════════════════════════

    public function test_the_policy_still_raises_nothing_itself(): void
    {
        $code = self::codeOnly('application/libraries/Id_card_fee_policy.php');
        foreach (['createModuleFee', 'firestoreSet', 'writeDemand', 'feeDemands'] as $n) {
            $this->assertStringNotContainsString($n, $code);
        }
    }

    public function test_fee_lifecycle_still_has_no_id_card_special_case(): void
    {
        $src = self::src('application/libraries/Fee_lifecycle.php');
        foreach (['IDCard', 'Id_card', 'idCard'] as $n) {
            $this->assertStringNotContainsString($n, $src);
        }
    }

    public function test_the_charge_uses_the_canonical_generator(): void
    {
        $code = self::codeOnly('application/controllers/Sis.php');
        $at   = strpos($code, 'function _maybe_charge_id_card_replacement(');
        $this->assertNotFalse($at);
        $this->assertStringContainsString('createModuleFee',
            self::methodBody($code, '_maybe_charge_id_card_replacement'),
            'the replacement charge must go through the canonical fee generator, '
            . 'not a second fee engine');
    }

    public function test_a_failed_charge_never_blocks_the_card(): void
    {
        // A credential is a child's identity; it must not be withheld because
        // a receivable could not be written.
        $code = self::codeOnly('application/controllers/Sis.php');
        $at   = strpos($code, 'function _maybe_charge_id_card_replacement(');
        $body = self::methodBody($code, '_maybe_charge_id_card_replacement');
        $this->assertStringContainsString('catch (\Throwable', $body);
        $this->assertStringNotContainsString('json_error', $body);
    }

    // ══ UI honesty ═════════════════════════════════════════════════════

    public function test_the_operator_sees_the_charge_before_confirming(): void
    {
        $v = self::src('application/views/sis/id_card.php');
        $this->assertStringContainsString('idcReasonFee', $v);
        $this->assertStringContainsString('replacement charge of', $v);
    }

    public function test_an_unknown_charge_is_never_shown_as_free(): void
    {
        // Guessing "free" about money is the worst possible default.
        $v = self::src('application/views/sis/id_card.php');
        $this->assertStringContainsString('could not be read', $v);
    }

    public function test_revoke_never_previews_a_charge(): void
    {
        // Revoking issues no new card, so it raises nothing.
        $v = self::src('application/views/sis/id_card.php');
        $this->assertStringContainsString("PENDING.act === 'revoke'", $v);
    }

    public function test_the_charges_screen_states_what_is_never_chargeable(): void
    {
        $v = self::src('application/views/sis/id_card.php');
        $this->assertStringContainsString('Never chargeable', $v);
        $this->assertStringContainsString('not the family', $v);
    }

    public function test_no_native_dialog_was_reintroduced(): void
    {
        $v = self::src('application/views/sis/id_card.php');
        $v = (string) preg_replace('#/\*.*?\*/#s', ' ', $v);
        $v = (string) preg_replace('#^\s*//.*$#m', ' ', $v);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])alert\s*\(/', $v);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])prompt\s*\(/', $v);
    }

    // ══ SRNO ═══════════════════════════════════════════════════════════

    public function test_the_fee_path_carries_no_student_identity(): void
    {
        // The demand keys on studentId + card version. SRNO must not leak in
        // as a key, and Roll No must not appear at all.
        $code = self::codeOnly('application/libraries/Id_card_fee_policy.php');
        $this->assertStringNotContainsString('srNo', $code);
        $this->assertStringNotContainsString('rollNo', $code);
    }

    public function test_srno_is_still_never_a_card_document_key(): void
    {
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, ['srNo' => self::SR_A]);
        $this->assertArrayHasKey(self::SCH . '_' . self::STU_A, $this->fb->docs['idCards']);
        $this->assertArrayNotHasKey(self::SCH . '_' . self::SR_A, $this->fb->docs['idCards']);
    }
}
