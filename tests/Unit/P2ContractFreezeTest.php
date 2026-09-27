<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Entitlement_service.php';

/**
 * P2 — CONTRACT FREEZE.
 *
 * Two of the three authorized changes; the third (grossAmount immutability)
 * hit its documented STOP condition and is deliberately absent — see the P2-B
 * report. Nothing here asserts anything about grossAmount.
 *
 *  B.1  entitlement-level `frequency` retired. It was written by three
 *       callers, validated, and folded into the document fingerprint — and
 *       read by NO pricing path. The billing period belongs to the demand,
 *       where Transport_service and Id_card_fee_policy already stamp it.
 *       Retired now because the P2-A census proved the collection empty
 *       across all nine tenants; after the first record exists, dropping a
 *       fingerprint input becomes a migration.
 *
 *  B.3  `Fees::_auto_generate_student_demands()` deleted. A third generator
 *       with the pre-Phase-1 lookup asymmetry and zero executable callers.
 */
class P2ContractFreezeTest extends TestCase
{
    private const SVC  = APPPATH . 'libraries/Entitlement_service.php';
    private const FEES = APPPATH . 'controllers/Fees.php';

    /** Executable code only — these files DISCUSS the retired field at length. */
    private function codeOnly(string $file): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($file)) as $t) {
            $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        return $out;
    }

    /** A valid proposal, with an extra `frequency` an older client might post. */
    private function input(array $over = []): array
    {
        return array_merge([
            'studentId'     => 'STU0001',
            'kind'          => 'concession',
            'concessionType'=> 'sibling',
            // DEC-004 UPDATE: a HEAD scope now requires the target to resolve in
            // feeHeadRegistry, which needs a registry handle this fixture has no
            // reason to carry — its subject is the retired `frequency`, not head
            // registration. A category scope exercises the same validation path
            // without an unrelated dependency; head-scope registration is
            // covered in full by Dec004HeadScopeRegistryTest.
            'scope'         => 'category:academic',
            'valueType'     => 'percent',
            'value'         => 10,
            'effectiveFrom' => '2026-04-01',
            'reason'        => 'sibling of STU0002',
        ], $over);
    }

    private function svc(): Entitlement_service
    {
        $s = (new ReflectionClass(Entitlement_service::class))->newInstanceWithoutConstructor();
        (function () { $this->schoolId = 'SCH_B56BB9A401'; $this->session = '2026-27'; $this->actorId = 'SSA0011'; })
            ->call($s);
        return $s;
    }

    private function call(Entitlement_service $s, string $m, array $args)
    {
        $r = new ReflectionMethod(Entitlement_service::class, $m);
        $r->setAccessible(true);
        return $r->invokeArgs($s, $args);
    }

    // ══ 1-2 · the fingerprint no longer depends on frequency ══════════════

    public function test_1_fingerprint_ignores_frequency_entirely(): void
    {
        $s = $this->svc();
        $a = $this->call($s, '_fingerprintId', ['STU0001', $this->input(['frequency' => 'monthly'])]);
        $b = $this->call($s, '_fingerprintId', ['STU0001', $this->input(['frequency' => 'annual'])]);
        $c = $this->call($s, '_fingerprintId', ['STU0001', $this->input()]);

        $this->assertSame($a, $b, 'monthly vs annual must be the SAME entitlement');
        $this->assertSame($a, $c, 'absent must be the same as either');
    }

    /**
     * 2 — the guard that makes clause 1 meaningful: fields that DO change what
     * a student is billed must still separate two entitlements.
     */
    public function test_2_priced_fields_still_separate_entitlements(): void
    {
        $s = $this->svc();
        $base = $this->call($s, '_fingerprintId', ['STU0001', $this->input()]);
        foreach ([
            'scope'         => 'head:FH_LIB',
            'valueType'     => 'fixed',
            'value'         => 20,
            'effectiveFrom' => '2026-05-01',
            'effectiveTo'   => '2026-09-30',
            'session'       => '2027-28',
            'kind'          => 'service',
            'concessionType'=> 'staff_ward',
        ] as $field => $val) {
            $this->assertNotSame($base,
                $this->call($s, '_fingerprintId', ['STU0001', $this->input([$field => $val])]),
                "a different {$field} must remain a distinguishable entitlement");
        }
    }

    // ══ 3-4 · the field is neither stored nor required ════════════════════

    public function test_3_no_frequency_is_stored_and_the_vocabulary_is_gone(): void
    {
        $code = $this->codeOnly(self::SVC);
        $this->assertStringNotContainsString("'frequency'", $code,
            'no frequency may be written into an entitlement payload');
        $this->assertStringNotContainsString('FREQUENCIES', $code,
            'the monthly|annual vocabulary is retired — it was a Billing V2 hazard');
    }

    public function test_4_the_api_neither_requires_nor_reads_frequency(): void
    {
        $s = $this->svc();

        // Validation accepts a proposal with no frequency at all …
        $this->assertNull($this->call($s, '_validate', [$this->input()]));
        // … and does not reject a nonsense one, because it no longer looks.
        $this->assertNull($this->call($s, '_validate', [$this->input(['frequency' => 'fortnightly'])]));

        $this->assertStringNotContainsString('frequency',
            $this->codeOnly(APPPATH . 'controllers/Entitlements.php'),
            'the controller must not read the POST field');
        $this->assertStringNotContainsString('frequency',
            $this->codeOnly(APPPATH . 'controllers/Entitlement_requests.php'),
            'the parent-request flow must not hardcode one');
    }

    /** Everything else validation guards must still fire. */
    public function test_4b_the_other_validation_rules_are_untouched(): void
    {
        $s = $this->svc();
        $cases = [
            [['studentId' => ''],               'student_id'],
            [['kind' => 'nonsense'],            'kind must be'],
            [['scope' => 'nonsense'],           'scope must be'],
            [['scope' => 'head:'],              'needs a target'],
            [['valueType' => 'amountDue'],      'valueType must be'],
            [['value' => 150],                  'between 0 and 100'],
            [['effectiveFrom' => '01-04-2026'], 'effectiveFrom must be'],
            [['effectiveTo' => '2026-03-01'],   'cannot be earlier'],
            [['reason' => ''],                  'reason is required'],
            [['concessionType' => 'rte_ews'],   'govtReceivableAmount'],
        ];
        foreach ($cases as [$over, $needle]) {
            $err = $this->call($s, '_validate', [$this->input($over)]);
            $this->assertIsString($err, 'expected a rejection for ' . json_encode($over));
            $this->assertStringContainsString($needle, $err);
        }
    }

    // ══ 5-6 · capture surface and migration ══════════════════════════════

    public function test_5_the_capture_ui_no_longer_asks_for_frequency(): void
    {
        $view = file_get_contents(APPPATH . 'views/_entitlements_capture.php');
        // Target the MARKUP, not the prose — the explanatory comment left in
        // place names the removed field, and a test must not dictate wording.
        $this->assertStringNotContainsString('ecFrequency', $view);
        $this->assertStringNotContainsString('<label>Billing frequency', $view);
        $this->assertStringNotContainsString('<option value="monthly">', $view);
        $this->assertStringNotContainsString('<option value="annual">', $view);

        // …and nothing unrelated was collateral damage.
        //
        // `ecScopeTarget` was in this list until P6. It is NOT missing by
        // accident: F-3 deliberately replaced the free-text target with the
        // registry-backed `ecScopeHead` + `ecScopeCat` pickers, so the guard
        // now names those instead. The list is the same length and the same
        // strength — one field was superseded, none was dropped.
        foreach (['ecScopeKind', 'ecScopeHead', 'ecScopeCat', 'ecValueType', 'ecValue',
                  'ecFrom', 'ecTo', 'ecReason', 'ecGovt', 'ecKind', 'ecConcType'] as $field) {
            $this->assertStringContainsString($field, $view, "{$field} must survive");
        }
    }

    public function test_6_the_migration_script_no_longer_emits_frequency(): void
    {
        $js = file_get_contents(__DIR__ . '/../../scripts/migrate_student_entitlements.js');
        $this->assertStringNotContainsString("frequency:", $js);
    }

    // ══ 11 · G-1 deletion ════════════════════════════════════════════════

    public function test_11_G1_is_gone_and_has_no_executable_reference(): void
    {
        $code = $this->codeOnly(self::FEES);
        $this->assertStringNotContainsString('_auto_generate_student_demands', $code,
            'the third generator and every executable reference to it must be gone');
    }

    /** The two REAL generators, and the phases that hardened them, survive. */
    public function test_11b_the_surviving_generation_paths_are_intact(): void
    {
        $src = file_get_contents(self::FEES);
        $this->assertStringContainsString('function _generateDemandsForMonth', $src);
        $this->assertStringContainsString(
            '($haveKeys[$dedupKey] ?? []) + ($haveKeys[$dedupKeyByName] ?? [])', $src);  // Phase 1
        $this->assertStringContainsString('ACC_FEE_DEMAND_IDENTITY_CONFLICT', $src);      // Phase 2
        $this->assertStringContainsString(
            "'balance'         => round(\$netAmount + \$fineSoFar - \$paidSoFar, 2),", $src); // fine-aware
    }

    // ══ 12 · byte identity + scope ═══════════════════════════════════════

    /**
     * 12 — ZERO-ENTITLEMENT BYTE IDENTITY. Nothing in P2-B touches pricing:
     * the deduction kernel, the authority boundary and the demand identity
     * rules are all unchanged, so a student with no entitlement is billed
     * exactly as before.
     */
    public function test_12_zero_entitlement_byte_identity_holds(): void
    {
        $gen = file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
        $this->assertStringContainsString('$net = max(0.0, round($gross - $deduction, 2));', $gen);
        $this->assertStringContainsString('if (empty($concessions))', $gen,
            'the zero-rule short circuit must remain');

        $lif = file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $this->assertStringContainsString('$useEntitlementAuthority', $lif);   // XOR boundary
        $this->assertStringContainsString('DEM_{$sid}_{$ym}_{$key}', $lif);    // demand identity
    }

    /** P2-B.2 was NOT implemented — its STOP condition fired. Pin that. */
    public function test_grossAmount_guard_was_deliberately_NOT_added(): void
    {
        $txn = $this->codeOnly(APPPATH . 'libraries/Fee_firestore_txn.php');
        $this->assertStringNotContainsString('GROSS_AMOUNT_IMMUTABLE', $txn,
            'P2-B.2 stopped: Transport changeRoute/changeSubscription legitimately '
            . 'rewrite grossAmount on the same demandId. Do not add this guard '
            . 'without resolving that workflow first — see the P2-B report.');
    }

    /** Nothing outside the authorized P2-B scope moved. */
    public function test_scope_held(): void
    {
        // P4 BOUNDARY MOVED (2026-08-18). At P2 the proof that nothing had run
        // ahead was that the allocation ledger did not exist. P4 is now
        // separately authorized, so that is obsolete by design. The guard is
        // kept in its current form: the ledger may exist, but only the
        // generator may reference it, and only behind USE_ALLOCATION_LEDGER
        // (which ships false). See P4AllocationLedgerTest for the full set.
        if (file_exists(APPPATH . 'libraries/Entitlement_allocation_service.php')) {
            $callers = [];
            foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                                 glob(APPPATH . 'libraries/*.php')) as $f) {
                if (basename($f) === 'Entitlement_allocation_service.php') continue;
                if (strpos(file_get_contents($f), 'Entitlement_allocation_service') !== false) {
                    $callers[] = basename($f);
                }
            }
            sort($callers);
                        // P5-BF (2026-08-29): widened for the gated historical backfill.
            // The planner is PURE and the controller persists through
            // Entitlement_allocation_service, so the one-writer property this
            // freeze protects is intact — see
            // P4AllocationLedgerTest::test_only_the_allocation_service_writes_ledger_rows,
            // which asserts that behaviourally and does not need widening when a
            // legitimate READER is added. This same list is duplicated across three
            // freeze tests; that duplication is why all three failed at once.
            $this->assertSame(['Fee_generation_service.php', 'Fee_lifecycle.php', 'Ledger_backfill.php', 'Ledger_backfill_planner.php'], $callers,
                'only the generator may reference the ledger: ' . implode(', ', $callers));
            $this->assertStringContainsString("\$config['USE_ALLOCATION_LEDGER'] = true",
                file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php'),
                'and it must still ship disabled');
        }

        $reader = file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');
        $this->assertStringContainsString('$feeHeadRef !== \'\' && $target === $feeHeadRef', $reader,
            'P0 stands; DD-1 closure is P3 and must NOT be here');
        $this->assertStringNotContainsString('ENTITLEMENT_HEAD_SCOPE_SHADOWED', $reader,
            'DD-1 is not authorized yet');

        $fees = file_get_contents(self::FEES);
        $this->assertStringContainsString('function recalc_unpaid_discounts', $fees,
            'RC-1 retirement is P5, not P2');
    }
}
