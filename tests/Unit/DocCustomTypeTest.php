<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_contract;
use InvalidArgumentException;

/**
 * Custom document types — the ones a school invents.
 *
 * A custom type is `custom:{slug}` and each is its OWN document type rather than
 * a shared "Custom" bucket. That choice is load-bearing: the module's central
 * invariant is exactly one ACTIVE template per (school, docType), so a shared
 * bucket would make activating a school's Sports Day certificate silently
 * deactivate its Fee Concession letter. These tests pin the shape of the id,
 * because the id is what every template, active slot and print point is keyed
 * on — a sloppy one cannot be corrected later without abandoning the documents.
 */
class DocCustomTypeTest extends TestCase
{
    private Doc_contract $svc;
    /** @var array<string,mixed> */
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

        $this->svc = new Doc_contract([
            'fields'    => $config['doc_merge_fields'],
            'contracts' => $config['doc_contracts'],
            'types'     => $config['doc_types'],
        ]);
    }

    /* ---------------------------------------------------------------- *
     * The id
     * ---------------------------------------------------------------- */

    public function test_a_title_becomes_a_readable_slug(): void
    {
        $this->assertSame('custom:sports_day_participation',
            Doc_contract::customTypeFor('Sports Day Participation'));
        $this->assertSame('custom:fee_concession_letter',
            Doc_contract::customTypeFor('  Fee Concession Letter  '));
        $this->assertSame('custom:no_dues_2026_27',
            Doc_contract::customTypeFor('No-Dues (2026-27)'));
    }

    /**
     * A title with nothing usable in it must be REFUSED, not turned into
     * `custom:`. Every such title would mint the same id, so two unrelated
     * documents would quietly become one type — and share one active slot.
     */
    public function test_a_title_with_no_letters_or_digits_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no letters or digits/');
        Doc_contract::customTypeFor('—  ***  —');
    }

    public function test_the_id_shape_is_enforced_not_merely_documented(): void
    {
        $this->assertTrue(Doc_contract::isCustom('custom:sports_day'));
        $this->assertTrue(Doc_contract::isCustom('custom:a'));

        foreach (['custom:', 'custom:_x', 'custom:x_', 'custom:Sports_Day', 'custom:a b',
                  'custom:' . str_repeat('a', 41), 'transfer_certificate', 'custom', ''] as $bad) {
            $this->assertFalse(Doc_contract::isCustom($bad), "'$bad' should not be a custom type");
        }
    }

    /**
     * PHP and JavaScript must mint the SAME id for the same typed name.
     *
     * They did not. PHP's strtolower() is byte-only and JavaScript's
     * toLowerCase() is Unicode-aware, so a Turkish dotted capital İ (U+0130)
     * produced `custom:stanbul_public_school` on the server and
     * `custom:i_stanbul_public_school` in the client — two document types from
     * one name, and the id is what every template, active slot and print point
     * is keyed on. Every ASCII case agreed, which is why it survived review.
     *
     * The expectations below are the CLIENT's actual output, executed in node.
     */
    public function test_the_slug_matches_the_client_on_unicode_special_casing(): void
    {
        $cases = [
            'İstanbul Public School' => 'custom:i_stanbul_public_school',
            'İİİ'                    => 'custom:i_i_i',
            'ÄÖÜ School'             => 'custom:school',
            'ß Schule'               => 'custom:schule',
            'Sports Day'             => 'custom:sports_day',
        ];
        foreach ($cases as $title => $expected) {
            $this->assertSame($expected, Doc_contract::customTypeFor($title),
                "PHP and the client disagree on the type id for '$title'");
        }
    }

    /** A slug cannot reproduce what was typed, so the title is stored separately. */
    public function test_the_slug_is_lossy_which_is_why_a_title_is_stored(): void
    {
        $id = Doc_contract::customTypeFor('Sports Day');
        $this->assertSame('Sports day', Doc_contract::customTitle($id));
        $this->assertNotSame('Sports Day', Doc_contract::customTitle($id),
            'if the slug could reproduce the title, storing docTitle would be redundant');
    }

    /* ---------------------------------------------------------------- *
     * The contract
     * ---------------------------------------------------------------- */

    /**
     * A contract records somebody else's prescription. A document the school
     * invented has no such author, so every field is on offer.
     */
    public function test_a_custom_type_offers_every_declared_field(): void
    {
        $keys = $this->svc->keysFor('custom:sports_day');
        $this->assertSame(array_keys(self::$cfg['doc_merge_fields']), $keys);
        $this->assertGreaterThan(
            count(self::$cfg['doc_contracts']['transfer_certificate']),
            count($keys),
            'a custom document should not be more constrained than a statutory one'
        );
    }

    public function test_a_custom_contract_resolves_to_real_field_definitions(): void
    {
        $c = $this->svc->get('custom:gate_pass');
        $this->assertArrayHasKey('student.fullName', $c);
        $this->assertSame('Student name', $c['student.fullName']['label']);
        $this->assertArrayHasKey('receipt.items', $c, 'a custom document may itemise too');
    }

    /** Nothing prescribes it, so no state can withhold it. */
    public function test_a_custom_type_is_available_in_every_state(): void
    {
        foreach ([null, '', 'Kerala', 'Jharkhand'] as $state) {
            $this->assertTrue($this->svc->typeAvailable('custom:sports_day', $state));
        }
    }

    /** But it is not a built-in: it must never appear in the shipped catalogue. */
    public function test_custom_types_are_not_in_the_shipped_catalogue(): void
    {
        $ids = array_column($this->svc->catalogue('Kerala'), 'id');
        foreach ($ids as $id) {
            $this->assertFalse(Doc_contract::isCustom($id),
                "the catalogue is the SHIPPED types; '$id' is a school's own");
        }
        $this->assertArrayNotHasKey('custom:sports_day', $this->svc->typesForState('Kerala'));
    }

    /** A real unknown type is still an error — custom does not mean anything goes. */
    public function test_an_unknown_non_custom_type_still_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->keysFor('not_a_type');
    }

    /* ---------------------------------------------------------------- *
     * The gate that blocked five types
     * ---------------------------------------------------------------- */

    /**
     * The controller's `_safe_type` was a hardcoded list of three while the
     * catalogue declared seven, so Kerala's two forms, the A.P. Study
     * Certificate and the Fee Receipt could not be created AT ALL — and the
     * error blamed a missing school id. This pins the rule the fix restores:
     * whatever the catalogue offers a school, that school can create.
     */
    public function test_every_type_the_catalogue_offers_is_creatable(): void
    {
        foreach (['Kerala', 'Jharkhand', 'Andhra Pradesh'] as $state) {
            foreach ($this->svc->typesForState($state) as $id => $t) {
                $this->assertTrue(
                    $this->svc->typeAvailable($id, $state),
                    "'$id' is offered in $state but would not pass the create gate"
                );
                $this->assertNotEmpty($this->svc->keysFor($id),
                    "'$id' is offered but declares no contract");
            }
        }
    }

    /** And the gate must still be fail-closed. */
    public function test_a_type_from_another_state_is_not_creatable_here(): void
    {
        $this->assertFalse($this->svc->typeAvailable('leaving_certificate_5a', 'Jharkhand'));
        $this->assertFalse($this->svc->typeAvailable('migration', 'Kerala'), 'disabled');
        $this->assertFalse($this->svc->typeAvailable('not_a_type', 'Kerala'));
    }
}
