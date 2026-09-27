<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Concession_journal_mapper.php';

/**
 * P7 — ACCOUNTING INTEGRATION: mapper output through the REAL resolver.
 *
 * `P7ConcessionJournalMapperTest` proves the mapper emits the documented SHAPE
 * and that its labels contain the right keywords. That is necessary and NOT
 * sufficient: it checks the labels against a grep of the resolver's table, not
 * against the resolver itself. If `_resolveConcessionAccount()` ever changed its
 * matching ORDER, its usability rules, or its fallbacks, those tests would still
 * pass while real money routed to the wrong ledger account.
 *
 * This file closes that gap. It drives the **actual private
 * `Operations_accounting::_resolveConcessionAccount()`** by reflection, with a
 * realistic chart of accounts, and asserts every label this programme can emit
 * resolves to the account intended for it.
 *
 * It is the closest thing to an end-to-end accounting proof that is possible
 * before allocation rows exist in production: the money path is
 *   allocation row → concessionType → label → THIS RESOLVER → account code
 * and every step except the last was already covered.
 *
 * Nothing is wired, nothing is written, and `concessions_enabled` is untouched.
 */
class P7AccountingIntegrationTest extends TestCase
{
    /** A realistic chart of accounts: the 8 contra-revenue codes + fallbacks. */
    private function coa(array $omit = []): array
    {
        $codes = ['4990', '4991', '4992', '4993', '4994', '4995', '4996', '4997', '4998', '5190'];
        $coa = [];
        foreach ($codes as $c) {
            if (in_array($c, $omit, true)) continue;
            $coa[$c] = ['status' => 'active', 'is_group' => false, 'name' => 'Concession ' . $c];
        }
        return $coa;
    }

    /** Call the real private resolver. */
    private function resolve(string $type, array $coa, array $configMap = []): ?string
    {
        static $loaded = false;
        if (!$loaded) {
            require_once APPPATH . 'libraries/Operations_accounting.php';
            $loaded = true;
        }
        $obj = (new ReflectionClass('Operations_accounting'))->newInstanceWithoutConstructor();
        $m   = new ReflectionMethod('Operations_accounting', '_resolveConcessionAccount');
        $m->setAccessible(true);
        return $m->invoke($obj, $type, $configMap, $coa);
    }

    // ══ EVERY LABEL THIS PROGRAMME EMITS ROUTES CORRECTLY ═══════════════

    /**
     * THE HEADLINE. Each `concessionType` the entitlement model supports maps to
     * a label that the REAL resolver routes to the intended account.
     */
    public function test_every_concession_type_routes_to_its_intended_account(): void
    {
        $expected = [
            'scholarship' => '4991',   // merit / scholarship
            'sibling'     => '4993',   // sibling
            'staff_ward'  => '4994',   // staff
            'rte_ews'     => '4997',   // government / rte / ews / waiver
            'other'       => '4990',   // generic contra-revenue
        ];
        $coa = $this->coa();
        foreach ($expected as $type => $account) {
            $label = Concession_journal_mapper::typeLabel($type);
            $this->assertSame($account, $this->resolve($label, $coa),
                "'{$type}' → '{$label}' must resolve to {$account} in the REAL resolver");
        }
    }

    /** Every label must resolve to SOMETHING — an unroutable one is skipped silently. */
    public function test_no_label_is_unroutable(): void
    {
        $coa = $this->coa();
        foreach (Concession_journal_mapper::TYPE_LABELS as $type => $label) {
            $this->assertNotNull($this->resolve($label, $coa),
                "'{$label}' resolved to NULL — the engine would skip BOTH legs and "
                . 'the concession would silently vanish from the journal');
        }
    }

    // ══ THE OPERATOR OVERRIDE STILL WINS ════════════════════════════════

    /** An explicit `concession:<type>` mapping outranks the keyword heuristics. */
    public function test_an_explicit_operator_mapping_overrides_the_keywords(): void
    {
        $label = Concession_journal_mapper::typeLabel('scholarship');
        $map   = ['concession:' . strtolower($label) => '4992'];
        $this->assertSame('4992', $this->resolve($label, $this->coa(), $map),
            "the school's own mapping must win — this programme only chooses a default");
    }

    // ══ DEGRADED CHARTS — the failure directions ════════════════════════

    /**
     * A school without the specific contra-revenue accounts falls back to the
     * generic 4990, NOT to nothing. A null here means the engine skips both the
     * Dr and the income boost — the concession disappears from the ledger.
     */
    public function test_a_chart_without_specific_accounts_falls_back_to_generic(): void
    {
        $coa = $this->coa(['4991', '4992', '4993', '4994', '4995', '4996', '4997', '4998']);
        foreach (['scholarship', 'sibling', 'staff_ward', 'rte_ews', 'other'] as $type) {
            $this->assertSame('4990', $this->resolve(Concession_journal_mapper::typeLabel($type), $coa),
                'every type must still land somewhere when only the generic exists');
        }
    }

    /** With no contra-revenue at all, the legacy discount-expense account is used. */
    public function test_a_legacy_chart_falls_back_to_the_discount_expense_account(): void
    {
        $coa = $this->coa(['4990', '4991', '4992', '4993', '4994', '4995', '4996', '4997', '4998']);
        $this->assertSame('5190',
            $this->resolve(Concession_journal_mapper::typeLabel('scholarship'), $coa),
            'preserves behaviour for schools that never configured contra-revenue');
    }

    /**
     * A school with NO usable account at all resolves to null. The engine then
     * skips both legs, preserving Dr=Cr — correct, but it means the concession
     * is invisible in the ledger. Asserted so the consequence is understood, not
     * discovered later.
     */
    public function test_a_chart_with_no_usable_account_resolves_to_null(): void
    {
        $this->assertNull(
            $this->resolve(Concession_journal_mapper::typeLabel('scholarship'), []),
            'the engine skips both legs — Dr=Cr holds, but the concession is unrecorded');
    }

    /** An INACTIVE or GROUP account is not usable and must be skipped. */
    public function test_inactive_and_group_accounts_are_not_used(): void
    {
        $label = Concession_journal_mapper::typeLabel('scholarship');

        $inactive = ['4991' => ['status' => 'inactive', 'is_group' => false],
                     '4990' => ['status' => 'active',   'is_group' => false]];
        $this->assertSame('4990', $this->resolve($label, $inactive),
            'an inactive account must fall through, not be posted to');

        $group = ['4991' => ['status' => 'active', 'is_group' => true],
                  '4990' => ['status' => 'active', 'is_group' => false]];
        $this->assertSame('4990', $this->resolve($label, $group),
            'a group/header account must never receive a posting');
    }

    // ══ NO MISROUTING BETWEEN TYPES ═════════════════════════════════════

    /**
     * The five labels must resolve to FIVE DISTINCT accounts on a full chart.
     * A collision would silently merge two different concession kinds into one
     * ledger line, and no invariant elsewhere would notice.
     */
    public function test_the_labels_do_not_collide_with_each_other(): void
    {
        $coa = $this->coa();
        $seen = [];
        foreach (['scholarship', 'sibling', 'staff_ward', 'rte_ews', 'other'] as $type) {
            $acct = $this->resolve(Concession_journal_mapper::typeLabel($type), $coa);
            $this->assertArrayNotHasKey((string) $acct, $seen,
                "'{$type}' collided with '" . ($seen[(string) $acct] ?? '?') . "' on account {$acct}");
            $seen[(string) $acct] = $type;
        }
        $this->assertCount(5, $seen);
    }

    /** The generic label must not be captured by a specific keyword. */
    public function test_the_generic_label_is_not_captured_by_a_specific_account(): void
    {
        $this->assertSame('4990',
            $this->resolve(Concession_journal_mapper::typeLabel('other'), $this->coa()),
            'a generic concession must not be misfiled as merit, sibling or government');
    }

    // ══ THE ENGINE CONTRACT IS UNCHANGED ════════════════════════════════

    /** The engine still reads the key the mapper writes, and is still gated. */
    public function test_the_engine_contract_and_flag_are_untouched(): void
    {
        $eng = file_get_contents(APPPATH . 'libraries/Operations_accounting.php');
        $this->assertStringContainsString("\$alloc['concessions']", $eng);
        $this->assertStringContainsString("config->item('concessions_enabled')", $eng,
            'live accounting output must remain flag-gated');
        $this->assertStringContainsString('_resolveConcessionAccount', $eng);
    }

    /** And nothing wired the mapper in — this remains preparation. */
    public function test_the_mapper_is_still_unwired(): void
    {
        $callers = [];
        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php'),
                             glob(APPPATH . 'services/*.php')) as $f) {
            if (basename($f) === 'Concession_journal_mapper.php') continue;
            if (strpos((string) file_get_contents($f), 'Concession_journal_mapper') !== false) {
                $callers[] = basename($f);
            }
        }
        $this->assertSame([], $callers,
            'wiring changes live accounting output: ' . implode(', ', $callers));
    }
}
