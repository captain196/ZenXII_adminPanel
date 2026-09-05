<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_contract;
use InvalidArgumentException;
use RuntimeException;

/**
 * Doc_contract behaviour — the fail-closed rules, not the data.
 *
 * `DocContractParityTest` asserts the contract DATA agrees with the client.
 * This asserts the SERVICE behaves correctly given that data: what it blocks,
 * what it merely warns about, and where it refuses outright.
 *
 * The distinction that matters most here, and the one a future change is most
 * likely to get wrong: an UNRESOLVED field is an ERROR (a blank on a statutory
 * document), while an OVER-LENGTH field is a WARNING (maxLen is our own
 * Level-D rendering estimate, so blocking on it would reject lawful data).
 * Collapsing those two into one severity breaks the module in one direction or
 * the other.
 *
 * EXECUTION_PLAN_v1.1 P1.9
 */
class DocContractServiceTest extends TestCase
{
    private Doc_contract $svc;
    /** @var array<string,mixed> the raw config, so checks can be driven by it */
    private static array $cfg;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_contract.php';
    }

    protected function setUp(): void
    {
        $config = [];
        require __DIR__ . '/../../application/config/doc_types.php';
        self::$cfg = $config;

        // The injected-params seam exists precisely so this suite needs no
        // CodeIgniter bootstrap. See the constructor's docblock.
        $this->svc = new Doc_contract([
            'fields'    => $config['doc_merge_fields'],
            'contracts' => $config['doc_contracts'],
            'types'     => $config['doc_types'],
        ]);
    }

    /* ---------------------------------------------------------------- *
     * Construction
     * ---------------------------------------------------------------- */

    public function test_refuses_to_construct_on_a_partial_config(): void
    {
        $this->expectException(RuntimeException::class);
        new Doc_contract(['fields' => ['a' => []], 'contracts' => [], 'types' => []]);
    }

    /* ---------------------------------------------------------------- *
     * Reads
     * ---------------------------------------------------------------- */

    public function test_get_returns_fields_in_declared_order(): void
    {
        $keys = array_keys($this->svc->get('character'));
        $this->assertSame($this->svc->keysFor('character'), $keys,
            'Declared order is the field-picker order and, for a statutory form, the printed order');
    }

    public function test_unknown_document_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->keysFor('not_a_real_certificate');
    }

    /**
     * The scoping rule the whole per-type contract exists for: a Kerala
     * school-education certificate must not be offered CBSE attendance and
     * promotion fields its own rule text never declares.
     */
    public function test_contracts_are_scoped_per_type(): void
    {
        $sec = $this->svc->keysFor('school_education_certificate');

        $this->assertNotContains('attendance.workingDays', $sec);
        $this->assertNotContains('attendance.daysPresent', $sec);
        $this->assertNotContains('result.promotionEligible', $sec);

        // …while the TC, which does declare them, keeps them.
        $this->assertContains('attendance.workingDays', $this->svc->keysFor('transfer_certificate'));
    }

    /* ---------------------------------------------------------------- *
     * State gating
     * ---------------------------------------------------------------- */

    public function test_state_gated_types_are_hidden_outside_their_state(): void
    {
        $jharkhand = $this->svc->typesForState('Jharkhand');
        $this->assertArrayNotHasKey('school_education_certificate', $jharkhand);
        $this->assertArrayNotHasKey('leaving_certificate_5a', $jharkhand);
        $this->assertArrayHasKey('transfer_certificate', $jharkhand);

        $kerala = $this->svc->typesForState('Kerala');
        $this->assertArrayHasKey('school_education_certificate', $kerala);
        $this->assertArrayHasKey('leaving_certificate_5a', $kerala);
    }

    /**
     * Read from the config rather than from a hand-kept list.
     *
     * The hand-kept version named fee_receipt, and went on asserting it was
     * hidden after the type was built — a test that passes by describing the
     * past. Whatever the config marks disabled is what must not be offered.
     */
    public function test_disabled_types_are_never_offered_in_any_state(): void
    {
        $disabled = array_keys(array_filter(self::$cfg['doc_types'], fn($t) => !empty($t['disabled'])));
        $this->assertNotEmpty($disabled, 'no type is marked disabled — this check would pass vacuously');

        foreach ([null, 'Kerala', 'Jharkhand', 'Andhra Pradesh'] as $state) {
            $offered = $this->svc->typesForState($state);
            foreach ($disabled as $id) {
                $this->assertArrayNotHasKey($id, $offered, "disabled type '$id' was offered");
            }
        }
    }

    /** A fee receipt is not state-gated: every school issues one. */
    public function test_the_fee_receipt_is_offered_everywhere(): void
    {
        foreach ([null, 'Kerala', 'Jharkhand', 'Andhra Pradesh'] as $state) {
            $this->assertArrayHasKey('fee_receipt', $this->svc->typesForState($state));
        }
    }

    public function test_type_available_agrees_with_types_for_state(): void
    {
        $this->assertTrue($this->svc->typeAvailable('leaving_certificate_5a', 'Kerala'));
        $this->assertFalse($this->svc->typeAvailable('leaving_certificate_5a', 'Jharkhand'));
        $this->assertFalse($this->svc->typeAvailable('migration', 'Kerala'));
    }

    /* ---------------------------------------------------------------- *
     * catalogue — what the hub renders, including what it parks
     * ---------------------------------------------------------------- */

    /**
     * Every declared type must appear. The hub PARKS what it cannot offer
     * rather than hiding it: a type that silently vanishes reads to an
     * administrator as "this product does not support my state", which is both
     * wrong and unactionable.
     */
    public function test_catalogue_lists_every_type_including_the_unavailable(): void
    {
        $ids = array_column($this->svc->catalogue('Jharkhand'), 'id');

        $this->assertContains('transfer_certificate', $ids);
        $this->assertContains('school_education_certificate', $ids, 'a state-gated type must still be listed');
        $this->assertContains('migration', $ids, 'a disabled type must still be listed');
        $this->assertCount(8, $ids);
    }

    public function test_every_catalogue_row_is_either_available_or_carries_a_reason(): void
    {
        foreach ($this->svc->catalogue('Kerala') as $row) {
            if ($row['available']) {
                $this->assertNull($row['reason'], "{$row['id']} is available and should carry no reason");
            } else {
                $this->assertNotEmpty($row['reason'], "{$row['id']} is unavailable and must say why");
            }
        }
    }

    /** The reason must name both states, so the reader can check it. */
    public function test_a_state_gated_reason_names_both_states(): void
    {
        $row = $this->rowFor($this->svc->catalogue('Jharkhand'), 'leaving_certificate_5a');

        $this->assertFalse($row['available']);
        $this->assertStringContainsString('Kerala', $row['reason']);
        $this->assertStringContainsString('Jharkhand', $row['reason']);
    }

    /** A school with no state set must be told that, not silently denied. */
    public function test_an_unset_state_produces_a_reason_that_says_so(): void
    {
        $row = $this->rowFor($this->svc->catalogue(''), 'leaving_certificate_5a');

        $this->assertFalse($row['available']);
        $this->assertStringContainsString('not set', $row['reason']);
    }

    public function test_disabled_types_are_unavailable_in_every_state(): void
    {
        foreach ([null, '', 'Kerala', 'Jharkhand'] as $state) {
            $row = $this->rowFor($this->svc->catalogue($state), 'migration');
            $this->assertFalse($row['available']);
            $this->assertStringContainsString('Not buildable', $row['reason']);
        }
    }

    public function test_catalogue_field_count_matches_the_contract(): void
    {
        foreach ($this->svc->catalogue('Kerala') as $row) {
            $expected = $row['disabled'] ? 0 : count($this->svc->keysFor($row['id']));
            $this->assertSame($expected, $row['fieldCount'], "{$row['id']} fieldCount is wrong");
        }
    }

    /** catalogue() and typesForState() must never disagree about availability. */
    public function test_catalogue_availability_agrees_with_types_for_state(): void
    {
        foreach (['Kerala', 'Jharkhand', 'Andhra Pradesh'] as $state) {
            $offered = $this->svc->typesForState($state);
            foreach ($this->svc->catalogue($state) as $row) {
                $this->assertSame(
                    isset($offered[$row['id']]),
                    $row['available'],
                    "{$row['id']} disagrees between catalogue() and typesForState() in $state"
                );
            }
        }
    }

    private function rowFor(array $catalogue, string $id): array
    {
        foreach ($catalogue as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        $this->fail("catalogue has no row for '$id'");
    }

    /* ---------------------------------------------------------------- *
     * validateBundle — the fail-closed core
     * ---------------------------------------------------------------- */

    public function test_a_complete_sample_bundle_validates_clean(): void
    {
        foreach (array_keys($this->svc->typesForState('Kerala')) as $type) {
            $r = $this->svc->validateBundle($type, $this->svc->sampleBundle($type));
            $this->assertTrue($r['ok'], "$type should validate against its own sample bundle: "
                . json_encode($r['errors']));
            $this->assertSame([], $r['warnings'], "$type sample bundle should raise no warnings");
        }
    }

    /** A missing value is an ERROR. A blank on a statutory field is a defect. */
    public function test_a_missing_value_blocks(): void
    {
        $b = $this->svc->sampleBundle('character');
        unset($b['tc.conductRemark']);

        $r = $this->svc->validateBundle('character', $b);
        $this->assertFalse($r['ok']);
        $this->assertSame('unresolved', $r['errors'][0]['type']);
        $this->assertSame('tc.conductRemark', $r['errors'][0]['key']);
    }

    /** An empty string is not a value — this is the "renders as blank" path. */
    public function test_an_empty_string_blocks_exactly_like_a_missing_key(): void
    {
        $b = $this->svc->sampleBundle('character');
        $b['tc.conductRemark'] = '';

        $r = $this->svc->validateBundle('character', $b);
        $this->assertFalse($r['ok']);
        $this->assertSame('unresolved', $r['errors'][0]['type']);
    }

    /** Binding a key the type does not declare is the mail-merge failure. */
    public function test_an_off_contract_bound_key_blocks(): void
    {
        $r = $this->svc->validateBundle(
            'character',
            $this->svc->sampleBundle('character') + ['attendance.workingDays' => '221'],
            ['school.name', 'attendance.workingDays']
        );

        $this->assertFalse($r['ok']);
        $this->assertSame('offContract', $r['errors'][0]['type']);
        $this->assertSame('attendance.workingDays', $r['errors'][0]['key']);
    }

    public function test_a_non_numeric_int_field_blocks(): void
    {
        $b = $this->svc->sampleBundle('leaving_certificate_5a');
        $b['student.ageAtLeaving'] = 'sixteen';

        $r = $this->svc->validateBundle('leaving_certificate_5a', $b);
        $this->assertFalse($r['ok']);
        $this->assertSame('badType', $r['errors'][0]['type']);
    }

    /**
     * THE SEVERITY BOUNDARY. Over-length must WARN, never block: maxLen is our
     * Level-D estimate, and the P2.7 overflow gate — which measures the real
     * rendered block — is what decides whether it actually fits.
     */
    public function test_over_length_warns_but_does_not_block(): void
    {
        $b = $this->svc->sampleBundle('transfer_certificate');
        $b['tc.reasonForLeaving'] = str_repeat('x', 500); // maxLen 400

        $r = $this->svc->validateBundle('transfer_certificate', $b);

        $this->assertTrue($r['ok'], 'a long but lawful value must not block a document');
        $this->assertSame('overLength', $r['warnings'][0]['type']);
        $this->assertSame(500, $r['warnings'][0]['len']);
        $this->assertSame(400, $r['warnings'][0]['maxLen']);
    }

    /** A value for a key nothing prints usually means the wrong docType. */
    public function test_an_extraneous_value_warns(): void
    {
        $r = $this->svc->validateBundle(
            'character',
            $this->svc->sampleBundle('character') + ['sec.outcome' => 'left']
        );

        $this->assertTrue($r['ok']);
        $this->assertSame('extraneous', $r['warnings'][0]['type']);
        $this->assertSame('sec.outcome', $r['warnings'][0]['key']);
    }

    /** Passing boundKeys narrows the check to what the template prints. */
    public function test_bound_keys_narrow_the_requirement(): void
    {
        $partial = ['school.name' => 'DPS Ranchi'];

        $this->assertFalse($this->svc->validateBundle('character', $partial)['ok'],
            'without boundKeys the whole contract is required');
        $this->assertTrue($this->svc->validateBundle('character', $partial, ['school.name'])['ok'],
            'with boundKeys only the printed fields are required');
    }

    /* ---------------------------------------------------------------- *
     * diff
     * ---------------------------------------------------------------- */

    public function test_removing_a_field_is_breaking_and_adding_one_is_not(): void
    {
        $from = ['school.name', 'student.fullName', 'tc.conductRemark'];

        $removed = $this->svc->diff('character', $from, ['school.name', 'student.fullName']);
        $this->assertTrue($removed['breaking']);
        $this->assertSame(['tc.conductRemark'], $removed['removed']);
        $this->assertStringContainsString('no longer appear', $removed['impact'][0]['message']);

        $added = $this->svc->diff('character', ['school.name'], ['school.name', 'student.fullName']);
        $this->assertFalse($added['breaking'], 'an added field cannot invalidate an issued document');
        $this->assertSame(['student.fullName'], $added['added']);
    }

    public function test_an_identical_version_pair_reports_no_change(): void
    {
        $keys = ['school.name', 'student.fullName'];
        $d = $this->svc->diff('character', $keys, $keys);

        $this->assertSame([], $d['added']);
        $this->assertSame([], $d['removed']);
        $this->assertSame($keys, $d['unchanged']);
        $this->assertFalse($d['breaking']);
        $this->assertSame([], $d['impact']);
    }

    /** An unknown key is a hard error — never a silently dropped impact row. */
    public function test_diff_rejects_a_key_the_contract_does_not_declare(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->diff('character', ['school.name'], ['school.name', 'attendance.daysPresent']);
    }

    /* ---------------------------------------------------------------- *
     * sampleBundle / p95
     * ---------------------------------------------------------------- */

    /**
     * p95 mode must actually differ where a p95 value exists, and fall back to
     * the ordinary sample where one does not. A "stress" mode that silently
     * returns the short strings is worse than no stress mode: it reports green.
     */
    public function test_p95_mode_uses_worst_case_values_and_falls_back_otherwise(): void
    {
        $typical = $this->svc->sampleBundle('transfer_certificate', false);
        $stress  = $this->svc->sampleBundle('transfer_certificate', true);

        $this->assertGreaterThan(
            mb_strlen($typical['tc.reasonForLeaving']),
            mb_strlen($stress['tc.reasonForLeaving']),
            'p95 mode must lengthen the field most likely to overflow'
        );
        $this->assertSame($typical['doc.issueDate'], $stress['doc.issueDate'],
            'a field with no p95 value falls back to its ordinary sample');
    }

    public function test_sample_bundle_covers_every_declared_key(): void
    {
        foreach (array_keys($this->svc->typesForState('Kerala')) as $type) {
            $this->assertSame(
                $this->svc->keysFor($type),
                array_keys($this->svc->sampleBundle($type)),
                "$type sample bundle must cover its contract exactly"
            );
        }
    }
}
