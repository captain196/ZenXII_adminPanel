<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Payment_settlement_route.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  WHERE GATEWAY MONEY IS BOOKED MUST BE A DECISION, NOT AN ACCIDENT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT WAS WRONG ───────────────────────────────────────────────────────
 * An online payment with no operator-selected ledger debited "the first
 * active bank ledger the chart iteration happened to reach". Three separate
 * consequences, all of them financial rather than cosmetic:
 *
 *   1. NON-DETERMINISM — the same chart delivered in a different order
 *      selects a different bank. AccountingReconciler's repair path re-derives
 *      the ledger this way and states it "must debit the SAME bank ledger the
 *      primary posting used". It could not guarantee that.
 *   2. NO CONFIGURATION — a school running two gateways into two banks had no
 *      way to say which was which. Provider names cannot go in core
 *      accounting, so the mapping has to be data.
 *   3. WRONG ACCOUNT ENTIRELY — at capture the money is with the GATEWAY, not
 *      the bank, and the bank will later receive NET of MDR and its GST.
 *      Debiting Bank gross at capture cannot reconcile against a statement.
 *
 * These tests assert the resolution BEHAVIOURALLY. The resolver is pure, so
 * there is no excuse for asserting the shape of its source instead.
 */
final class PaymentSettlementRouteTest extends TestCase
{
    private function coa(): array
    {
        return [
            '1010' => ['name' => 'Cash in Hand',      'is_bank' => false, 'status' => 'active'],
            '1020' => ['name' => 'SBI Bank A/c',      'is_bank' => true,  'status' => 'active'],
            '1050' => ['name' => 'HDFC Bank A/c',     'is_bank' => true,  'status' => 'active'],
            '1210' => ['name' => 'Razorpay Clearing', 'is_bank' => false, 'status' => 'active'],
            '5310' => ['name' => 'Gateway Fees',      'is_bank' => false, 'status' => 'active'],
            '1310' => ['name' => 'Input GST',         'is_bank' => false, 'status' => 'active'],
        ];
    }

    private function route(array $over = []): array
    {
        return array_merge([
            'schoolId'                 => 'SCH_A',
            'provider'                 => 'razorpay',
            'environment'              => '',
            'active'                   => true,
            'clearingLedgerCode'       => '1210',
            'settlementBankLedgerCode' => '1020',
            'gatewayFeeLedgerCode'     => '5310',
            'gatewayTaxLedgerCode'     => '1310',
        ], $over);
    }

    // ── DETERMINISM ──────────────────────────────────────────────────────

    public function test_chart_order_cannot_change_the_answer(): void
    {
        $a = $this->coa();
        $b = array_reverse($a, true);
        $this->assertSame(
            Payment_settlement_route::firstActiveBankDeterministic($a),
            Payment_settlement_route::firstActiveBankDeterministic($b),
            'the same chart in a different order selected a different bank'
        );
    }

    public function test_the_lowest_account_code_wins_by_natural_order(): void
    {
        // Natural order, so '1020' beats '1100' rather than losing to it on a
        // string comparison.
        $coa = [
            '1100' => ['name' => 'B', 'is_bank' => true, 'status' => 'active'],
            '1020' => ['name' => 'A', 'is_bank' => true, 'status' => 'active'],
        ];
        $this->assertSame('1020', Payment_settlement_route::firstActiveBankDeterministic($coa));
    }

    public function test_a_group_heading_is_never_postable(): void
    {
        $coa = [
            '1000' => ['name' => 'Bank Accounts', 'is_bank' => true, 'status' => 'active', 'is_group' => true],
            '1020' => ['name' => 'SBI',           'is_bank' => true, 'status' => 'active'],
        ];
        $this->assertSame('1020', Payment_settlement_route::firstActiveBankDeterministic($coa));
    }

    public function test_an_inactive_bank_is_never_selected(): void
    {
        $coa = [
            '1015' => ['name' => 'Closed', 'is_bank' => true, 'status' => 'inactive'],
            '1020' => ['name' => 'SBI',    'is_bank' => true, 'status' => 'active'],
        ];
        $this->assertSame('1020', Payment_settlement_route::firstActiveBankDeterministic($coa));
    }

    public function test_no_bank_at_all_returns_null_rather_than_a_guess(): void
    {
        $this->assertNull(Payment_settlement_route::firstActiveBankDeterministic([]));
        $this->assertNull(Payment_settlement_route::firstActiveBankDeterministic([
            '1010' => ['name' => 'Cash', 'is_bank' => false, 'status' => 'active'],
        ]));
    }

    // ── THE CLEARING MODEL ───────────────────────────────────────────────

    public function test_a_captured_gateway_payment_debits_clearing_not_bank(): void
    {
        // The correction that makes bank reconciliation possible: at capture
        // the money is with the gateway.
        $d = Payment_settlement_route::resolveDebit($this->coa(), $this->route(), '', 'online', true);
        $this->assertSame('1210', $d['debit_account']);
        $this->assertSame(Payment_settlement_route::SRC_CONFIGURED, $d['source']);
        $this->assertTrue($d['deferred_settlement'],
            'a clearing debit means settlement is still owed — the flag drives that follow-up');
    }

    public function test_the_settlement_accounts_travel_with_the_decision(): void
    {
        // Settlement later needs all three: where the net lands, where MDR is
        // expensed, and where its GST is claimed.
        $d = Payment_settlement_route::resolveDebit($this->coa(), $this->route(), '', 'online', true);
        $this->assertSame('1020', $d['bank']);
        $this->assertSame('5310', $d['fee_account']);
        $this->assertSame('1310', $d['tax_account']);
    }

    public function test_a_school_without_a_clearing_account_debits_its_configured_bank(): void
    {
        // Choosing the simpler T+0 model is legitimate; it must still be the
        // school's CONFIGURED bank, not a scan result.
        $r = $this->route(['clearingLedgerCode' => '']);
        $d = Payment_settlement_route::resolveDebit($this->coa(), $r, '', 'online', true);
        $this->assertSame('1020', $d['debit_account']);
        $this->assertSame(Payment_settlement_route::SRC_CONFIGURED, $d['source']);
        $this->assertFalse($d['deferred_settlement']);
    }

    public function test_two_gateways_can_settle_into_two_different_banks(): void
    {
        // The requirement that forbids provider names in core accounting.
        $rz = $this->route(['clearingLedgerCode' => '', 'settlementBankLedgerCode' => '1020']);
        $cf = $this->route(['clearingLedgerCode' => '', 'settlementBankLedgerCode' => '1050']);
        $this->assertSame('1020', Payment_settlement_route::resolveDebit($this->coa(), $rz, '', 'online', true)['debit_account']);
        $this->assertSame('1050', Payment_settlement_route::resolveDebit($this->coa(), $cf, '', 'online', true)['debit_account']);
    }

    // ── PRECEDENCE + FAIL-SOFT ───────────────────────────────────────────

    public function test_an_operator_at_a_till_outranks_configuration(): void
    {
        $d = Payment_settlement_route::resolveDebit($this->coa(), $this->route(), '1050', 'cheque', true);
        $this->assertSame('1050', $d['debit_account']);
    }

    public function test_an_operator_code_absent_from_the_chart_is_not_trusted(): void
    {
        // A ledger deleted since collection must not be debited blindly.
        $d = Payment_settlement_route::resolveDebit($this->coa(), $this->route(), '9999', 'online', true);
        $this->assertSame('1210', $d['debit_account']);
    }

    public function test_an_unconfigured_school_still_resolves_but_says_it_is_a_fallback(): void
    {
        // This chart has TWO active banks, so the pick is a guess and must be
        // labelled AMBIGUOUS rather than presented as a safe fallback. The
        // single-bank case — where there is no choice to get wrong — is
        // covered separately below.
        $d = Payment_settlement_route::resolveDebit($this->coa(), null, '', 'online', true);
        $this->assertSame('1020', $d['debit_account']);
        $this->assertSame(Payment_settlement_route::SRC_AMBIGUOUS, $d['source'],
            'the fallback must be labelled, so unconfigured schools are reportable');
    }

    public function test_an_online_payment_with_no_bank_resolves_to_nothing_not_to_cash(): void
    {
        // ACC-PM1: booking an online payment to Cash in Hand misstates cash on
        // hand. Nothing is a safer answer than a wrong something.
        $coaNoBank = ['1010' => ['name' => 'Cash in Hand', 'is_bank' => false, 'status' => 'active']];
        $d = Payment_settlement_route::resolveDebit($coaNoBank, null, '', 'online', true);
        $this->assertNull($d['debit_account']);
        $this->assertSame(Payment_settlement_route::SRC_NONE, $d['source']);
    }

    public function test_cash_still_reaches_cash_in_hand(): void
    {
        $d = Payment_settlement_route::resolveDebit($this->coa(), $this->route(), '', 'cash', false);
        $this->assertSame('1010', $d['debit_account']);
        $this->assertSame(Payment_settlement_route::SRC_CASH, $d['source']);
    }

    // ── TENANT + LIFECYCLE SAFETY ON THE STORED ROUTE ────────────────────

    public function test_a_route_belonging_to_another_tenant_is_refused(): void
    {
        $fb = new class {
            public function firestoreGet($c, $id) {
                return ['schoolId' => 'SCH_OTHER', 'active' => true, 'settlementBankLedgerCode' => '1050'];
            }
        };
        $this->assertNull(Payment_settlement_route::load($fb, 'SCH_A', 'razorpay'),
            'a route whose schoolId does not match must never be used — that is a cross-tenant debit');
    }

    public function test_a_deactivated_route_is_refused(): void
    {
        $fb = new class {
            public function firestoreGet($c, $id) {
                return ['schoolId' => 'SCH_A', 'active' => false, 'settlementBankLedgerCode' => '1050'];
            }
        };
        $this->assertNull(Payment_settlement_route::load($fb, 'SCH_A', 'razorpay'));
    }

    public function test_a_test_environment_route_does_not_apply_to_live_money(): void
    {
        $fb = new class {
            public function firestoreGet($c, $id) {
                return ['schoolId' => 'SCH_A', 'active' => true, 'environment' => 'test',
                        'settlementBankLedgerCode' => '1050'];
            }
        };
        $this->assertNull(Payment_settlement_route::load($fb, 'SCH_A', 'razorpay', 'live'),
            'a route scoped to TEST must not route LIVE money');
        $this->assertNotNull(Payment_settlement_route::load($fb, 'SCH_A', 'razorpay', 'test'));
    }

    public function test_an_unscoped_route_applies_to_any_environment(): void
    {
        // Back-compat for schools that never ran anything but live.
        $fb = new class {
            public function firestoreGet($c, $id) {
                return ['schoolId' => 'SCH_A', 'active' => true, 'settlementBankLedgerCode' => '1050'];
            }
        };
        $this->assertNotNull(Payment_settlement_route::load($fb, 'SCH_A', 'razorpay', 'live'));
        $this->assertNotNull(Payment_settlement_route::load($fb, 'SCH_A', 'razorpay', 'test'));
    }

    public function test_a_transport_failure_is_not_a_decision(): void
    {
        // A Firestore outage must not silently mean "this school has no route"
        // in a way that differs from a real absence — both return null, and the
        // caller falls back deterministically rather than refusing the payment.
        $fb = new class {
            public function firestoreGet($c, $id) { throw new \RuntimeException('transport'); }
        };
        $this->assertNull(Payment_settlement_route::load($fb, 'SCH_A', 'razorpay'));
    }

    public function test_a_client_without_the_read_method_is_tolerated(): void
    {
        $this->assertNull(Payment_settlement_route::load(new stdClass(), 'SCH_A', 'razorpay'));
    }

    public function test_a_blank_provider_never_loads_someone_elses_route(): void
    {
        $fb = new class {
            public function firestoreGet($c, $id) {
                return ['schoolId' => 'SCH_A', 'active' => true, 'settlementBankLedgerCode' => '1050'];
            }
        };
        $this->assertNull(Payment_settlement_route::load($fb, 'SCH_A', ''),
            'an unknown provider must skip the lookup, not match an arbitrary document');
    }

    // ── AMBIGUITY MUST BE NAMED, NOT ABSORBED ────────────────────────────

    public function test_one_bank_with_no_route_is_a_fallback_not_a_guess(): void
    {
        // With a single active bank there is no choice to get wrong, so this
        // is an ordinary fallback and must not be flagged as a risk.
        $coa = [
            "1010" => ["name" => "Cash in Hand", "is_bank" => false, "status" => "active"],
            "1020" => ["name" => "SBI",          "is_bank" => true,  "status" => "active"],
        ];
        $d = Payment_settlement_route::resolveDebit($coa, null, "", "online", true);
        $this->assertSame("1020", $d["debit_account"]);
        $this->assertSame(Payment_settlement_route::SRC_DETERMINISTIC, $d["source"]);
    }

    public function test_two_banks_with_no_route_is_flagged_ambiguous(): void
    {
        // A school whose gateway settles into HDFC would have payments
        // attributed to SBI purely because SBI sorts first. The payment still
        // posts — refusing it over bookkeeping config would be worse, and the
        // books must balance — but the attribution is a GUESS and says so.
        $d = Payment_settlement_route::resolveDebit($this->coa(), null, "", "online", true);
        $this->assertNotNull($d["debit_account"], "the payment must still post");
        $this->assertSame(Payment_settlement_route::SRC_AMBIGUOUS, $d["source"],
            "an unconfigured multi-bank school must not be reported as a safe fallback");
    }

    public function test_a_configured_route_is_never_ambiguous_however_many_banks_exist(): void
    {
        // Control: configuration is exactly what removes the ambiguity.
        $d = Payment_settlement_route::resolveDebit($this->coa(), $this->route(), "", "online", true);
        $this->assertSame(Payment_settlement_route::SRC_CONFIGURED, $d["source"]);
    }

    public function test_the_bank_count_ignores_groups_cash_and_inactive(): void
    {
        $coa = [
            "1000" => ["name" => "Bank Accounts", "is_bank" => true,  "status" => "active", "is_group" => true],
            "1010" => ["name" => "Cash in Hand",  "is_bank" => false, "status" => "active"],
            "1015" => ["name" => "Closed",        "is_bank" => true,  "status" => "inactive"],
            "1020" => ["name" => "SBI",           "is_bank" => true,  "status" => "active"],
        ];
        $this->assertSame(1, Payment_settlement_route::activeBankCount($coa));
    }
}