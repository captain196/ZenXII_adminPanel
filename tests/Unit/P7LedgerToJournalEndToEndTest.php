<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Entitlement_allocation_service.php';
require_once __DIR__ . '/../../application/libraries/Concession_journal_mapper.php';

/**
 * P7 — LEDGER → JOURNAL, THE WHOLE CHAIN, THROUGH THE REAL ENGINE.
 *
 * `P7AccountingIntegrationTest` proves the last hop (label → account code) using
 * the real resolver. What neither existing suite proves is the hop that actually
 * moves money: what `Operations_accounting::_buildGranularLines()` *emits* when
 * it is handed a `concessions` key.
 *
 * This file drives that private builder directly and asserts on the resulting
 * journal lines. The full chain under test:
 *
 *     P4 allocation row  →  Concession_journal_mapper::attach()
 *                        →  Operations_accounting::_buildGranularLines()
 *                        →  balanced Dr/Cr journal lines
 *
 * Every step is the production implementation. Nothing is re-implemented here,
 * and no source-text assertion stands in for behaviour.
 *
 * **The contra-revenue model, stated as the arithmetic it must produce:** when a
 * family pays 800 against a 1000 charge carrying a 200 concession, income is
 * credited at the GROSS 1000 and the 200 is debited to a contra-revenue account.
 * Revenue therefore reads as what was charged, not what was collected, and the
 * concession is visible as its own expense rather than vanishing into a smaller
 * number. If that model is ever silently changed, this file fails.
 *
 * Nothing is wired and `concessions_enabled` is not modified in production
 * config — the flag is forced ON only inside this test's CI double, which is the
 * point: it proves the engine is correct *before* anyone turns it on.
 */
class P7LedgerToJournalEndToEndTest extends TestCase
{
    private const SCHOOL  = 'SCH_TESTTENANT1';
    private const SESSION = '2026-27';

    /**
     * Configure the SHARED CI stub (`tests/Support/audit_helper_stub.php`).
     *
     * That stub owns `get_instance()` process-wide and hands out one singleton
     * whose `$config` is null by default. Its own docblock states the protocol:
     * a test needing a flag ON assigns a config double and MUST reset it
     * afterwards. This follows that protocol rather than defining a competing
     * `get_instance()`, which would lose the `function_exists()` race anyway.
     */
    private function configureCI(bool $concessionsEnabled): void
    {
        $CI = get_instance();

        $CI->config = new class($concessionsEnabled) {
            private bool $on;
            public function __construct(bool $on) { $this->on = $on; }
            public function item($key, $group = null)
            {
                // Forced here ONLY, inside a double. Production config untouched.
                return $key === 'concessions_enabled' ? $this->on : null;
            }
        };

        // Pre-set so _acctFsSync() never reaches `$CI->load`, and so the operator
        // fee-account map is empty — forcing the engine down its keyword
        // resolution path, which is the one most schools actually use.
        $CI->acctFsSync = new class {
            public function readFeeAccountMap() { return []; }
        };
    }

    protected function setUp(): void
    {
        $this->configureCI(true);
        if (!class_exists('Operations_accounting', false)) {
            require_once APPPATH . 'libraries/Operations_accounting.php';
        }
    }

    /** Restore the shared stub, per its documented contract. */
    protected function tearDown(): void
    {
        $CI = get_instance();
        $CI->config = null;
    }

    /** A realistic chart of accounts. Keys are strings — account codes, not ints. */
    private function coa(array $omit = []): array
    {
        $spec = [
            '1010' => ['Cash in Hand',                 'Asset'],
            '4010' => ['Tuition Income',               'Income'],
            '4060' => ['Late Fee Income',              'Income'],
            '4990' => ['Concession - General',         'Income'],
            '4991' => ['Merit Scholarship Concession', 'Income'],
            '5190' => ['Discount Allowed',             'Expense'],
        ];
        $coa = [];
        foreach ($spec as $code => $v) {
            // PHP silently casts numeric-string array keys to INT, so `$code`
            // arrives here as 4990, not '4990'. A strict in_array() against the
            // string list would never match and would omit nothing — which is
            // exactly the trap that made this fixture lie on its first run.
            $code = (string) $code;
            if (in_array($code, $omit, true)) continue;
            $coa[$code] = ['status' => 'active', 'is_group' => false,
                           'name' => $v[0], 'category' => $v[1]];
        }
        return $coa;
    }

    /** Drive the REAL private line builder. */
    private function build(array $allocations, array $coa, float $cash,
                           float $fine = 0.0, float $discount = 0.0): array
    {
        $obj = (new ReflectionClass('Operations_accounting'))->newInstanceWithoutConstructor();
        $m   = new ReflectionMethod('Operations_accounting', '_buildGranularLines');
        $m->setAccessible(true);
        $res = $m->invoke($obj, $coa, '1010', $coa['1010'], $cash,
                          'Receipt R1', 'STU9001', $allocations, $fine, $discount);
        return is_array($res[0] ?? null) ? $res[0] : [];
    }

    private function totals(array $lines): array
    {
        $dr = 0.0; $cr = 0.0;
        foreach ($lines as $l) { $dr += (float) $l['dr']; $cr += (float) $l['cr']; }
        return [round($dr, 2), round($cr, 2)];
    }

    private function lineFor(array $lines, string $code): ?array
    {
        foreach ($lines as $l) if ((string) $l['account_code'] === $code) return $l;
        return null;
    }

    /** A real P4 ledger row, produced by the real service. */
    private function ledgerRow(float $amount, string $type, string $entId = 'SE_0001'): array
    {
        $svc = (new Entitlement_allocation_service())
            ->init(null, null, self::SCHOOL, self::SESSION, 'SSA_TEST');
        $built = $svc->build(
            ['demandId' => 'FD_0001', 'schoolId' => self::SCHOOL, 'session' => self::SESSION,
             'studentId' => 'STU9001', 'grossAmount' => 1000.0, 'discountAmount' => $amount],
            ['entitlementId' => $entId, 'schoolId' => self::SCHOOL, 'session' => self::SESSION,
             'status' => 'active', 'valueType' => 'amount', 'value' => $amount, 'scope' => 'head'],
            $amount
        );
        $this->assertTrue($built['ok'], 'fixture build failed: ' . ($built['reason'] ?? ''));
        $row = $built['row'];
        $row['concessionType'] = $type;   // the field the mapper routes on
        return $row;
    }

    // ══ THE HEADLINE — the whole chain, end to end ═════════════════════════

    /**
     * A real allocation row becomes a balanced contra-revenue journal.
     * 800 collected against a 1000 charge with a 200 merit concession.
     */
    public function test_an_allocation_row_becomes_a_balanced_contra_revenue_journal(): void
    {
        $rows  = [$this->ledgerRow(200.0, 'scholarship')];
        $allocs = Concession_journal_mapper::attach(
            [['fee_head' => 'Tuition Fee', 'amount' => 800.0, 'demandId' => 'FD_0001']],
            $rows,
            ['FD_0001' => ['netAmount' => 800.0]]
        );

        $this->assertArrayHasKey('concessions', $allocs[0], 'the mapper must attach the concession');

        $lines = $this->build($allocs, $this->coa(), 800.0);
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr, 'a journal that does not balance must never post');
        $this->assertSame(1000.0, $cr, 'income must be credited at GROSS, not at what was collected');

        $cash = $this->lineFor($lines, '1010');
        $this->assertNotNull($cash);
        $this->assertSame(800.0, (float) $cash['dr'], 'cash is debited with what actually arrived');

        $income = $this->lineFor($lines, '4010');
        $this->assertNotNull($income);
        $this->assertSame(1000.0, (float) $income['cr']);

        // The concession must land on its OWN account, not net against income.
        $conc = $this->lineFor($lines, '4991');
        $this->assertNotNull($conc, 'a merit scholarship must route to its specific contra-revenue account');
        $this->assertSame(200.0, (float) $conc['dr']);
    }

    /** Two stacked grants both post, and the journal still balances. */
    public function test_two_stacked_concessions_both_post_and_balance(): void
    {
        $rows = [
            $this->ledgerRow(200.0, 'scholarship', 'SE_0001'),
            $this->ledgerRow(100.0, 'sibling',     'SE_0002'),
        ];
        $allocs = Concession_journal_mapper::attach(
            [['fee_head' => 'Tuition Fee', 'amount' => 700.0, 'demandId' => 'FD_0001']],
            $rows, ['FD_0001' => ['netAmount' => 700.0]]
        );
        $this->assertCount(2, $allocs[0]['concessions']);

        $lines = $this->build($allocs, $this->coa(), 700.0);
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr);
        $this->assertSame(1000.0, $cr, '700 collected + 300 conceded = 1000 gross income');
    }

    // ══ REVERSAL — the ledger's whole point ════════════════════════════════

    /**
     * A REVERSED allocation must not post contra-revenue. If it did, a withdrawn
     * grant would keep inflating revenue forever.
     */
    public function test_a_reversed_allocation_posts_nothing(): void
    {
        $row = $this->ledgerRow(200.0, 'scholarship');
        $row['status'] = 'reversed';

        $allocs = Concession_journal_mapper::attach(
            [['fee_head' => 'Tuition Fee', 'amount' => 800.0, 'demandId' => 'FD_0001']],
            [$row], ['FD_0001' => ['netAmount' => 800.0]]
        );
        $this->assertArrayNotHasKey('concessions', $allocs[0]);

        $lines = $this->build($allocs, $this->coa(), 800.0);
        list($dr, $cr) = $this->totals($lines);
        $this->assertSame($dr, $cr);
        $this->assertSame(800.0, $cr, 'income must fall back to what was collected');
        $this->assertNull($this->lineFor($lines, '4991'));
    }

    // ══ PARTIAL PAYMENT — proportional recognition ═════════════════════════

    /**
     * Half the net paid recognises half the concession. The alternative —
     * recognising the whole concession on a part payment — would credit revenue
     * the school has not yet earned.
     */
    public function test_a_part_payment_recognises_a_proportional_share(): void
    {
        $allocs = Concession_journal_mapper::attach(
            [['fee_head' => 'Tuition Fee', 'amount' => 400.0, 'demandId' => 'FD_0001']],
            [$this->ledgerRow(200.0, 'scholarship')],
            ['FD_0001' => ['netAmount' => 800.0]]
        );
        $this->assertSame(100.0, $allocs[0]['concessions'][0]['amount'],
            'half of an 800 net settled recognises half of the 200 concession');

        $lines = $this->build($allocs, $this->coa(), 400.0);
        list($dr, $cr) = $this->totals($lines);
        $this->assertSame($dr, $cr);
        $this->assertSame(500.0, $cr);
    }

    // ══ FAIL-CLOSED — an unroutable concession skips BOTH legs ═════════════

    /**
     * With no concession account available the engine must skip the credit boost
     * AND the debit line. Skipping only one would silently unbalance the journal.
     */
    public function test_an_unroutable_concession_skips_both_legs_and_stays_balanced(): void
    {
        $allocs = Concession_journal_mapper::attach(
            [['fee_head' => 'Tuition Fee', 'amount' => 800.0, 'demandId' => 'FD_0001']],
            [$this->ledgerRow(200.0, 'scholarship')],
            ['FD_0001' => ['netAmount' => 800.0]]
        );

        // Remove every account the concession could route to, including fallbacks.
        $lines = $this->build($allocs, $this->coa(['4990', '4991', '5190']), 800.0);
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr, 'Dr=Cr must hold by construction even when routing fails');
        $this->assertSame(800.0, $cr, 'no phantom gross-up without a place to debit it');
    }

    // ══ THE FLAG IS LOAD-BEARING ═══════════════════════════════════════════

    /**
     * With `concessions_enabled` OFF the journal must be byte-identical to the
     * pre-concession engine — backward compatibility is the stated invariant.
     */
    public function test_with_the_flag_off_the_journal_is_unchanged(): void
    {
        $allocs = Concession_journal_mapper::attach(
            [['fee_head' => 'Tuition Fee', 'amount' => 800.0, 'demandId' => 'FD_0001']],
            [$this->ledgerRow(200.0, 'scholarship')],
            ['FD_0001' => ['netAmount' => 800.0]]
        );

        $this->configureCI(false);

        $lines = $this->build($allocs, $this->coa(), 800.0);
        list($dr, $cr) = $this->totals($lines);

        $this->assertSame($dr, $cr);
        $this->assertSame(800.0, $cr, 'the concessions[] key must be inert while the flag is off');
        $this->assertNull($this->lineFor($lines, '4991'));

        // tearDown() restores the shared stub; no per-test restore needed.
    }

    /** Production config must NOT have concessions enabled by default. */
    public function test_production_config_still_gates_this_behind_an_env_flag(): void
    {
        $cfg = (string) file_get_contents(APPPATH . 'config/config.php');
        $this->assertMatchesRegularExpression(
            "/\\\$config\\['concessions_enabled'\\]\\s*=\\s*\\(string\\)\\s*\\(getenv\\(/",
            $cfg,
            'concessions must stay env-gated — this suite forces it on only in a double');
    }
}
