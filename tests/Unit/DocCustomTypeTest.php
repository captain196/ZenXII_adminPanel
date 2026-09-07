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
        /* Each value here was produced by BOTH runtimes and compared: a 34-name
           corpus was run through Doc_contract::customTypeFor() and through the
           client's customTypeFor() extracted from designer.js, with zero
           disagreements. These are the cases worth pinning in the suite.

           Two values changed when the lossy-name suffix was added, and both
           changed because they were WRONG before: 'ÄÖÜ School' and 'ß Schule'
           each lost a whole word to transliteration, so every name of the shape
           "<unrepresentable word> School" minted the same custom:school. */
        $cases = [
            'İstanbul Public School' => 'custom:i_stanbul_public_school',
            'İİİ'                    => 'custom:i_i_i',
            'Sports Day'             => 'custom:sports_day',
            'ÄÖÜ School'             => 'custom:school_40605994',
            'ß Schule'               => 'custom:schule_aac72bbf',
        ];
        foreach ($cases as $title => $expected) {
            $this->assertSame($expected, Doc_contract::customTypeFor($title),
                "PHP and the client disagree on the type id for '$title'");
        }
    }

    /**
     * A name written in a script transliteration cannot represent must still
     * name a document.
     *
     * Devanagari, Bengali, Gujarati, Japanese, Arabic and Armenian names were
     * REFUSED outright — with a message claiming they contained no letters or
     * digits, which for `प्रमाण पत्र` is plainly false. ZenXii ships Hindi and
     * DocRenderIntegrationTest proves each Indic script renders with its own
     * embedded font, so the engine could print a Devanagari certificate and not
     * name one.
     */
    public function test_a_name_in_a_non_latin_script_can_name_a_document(): void
    {
        foreach (['प्रमाण पत्र', 'खेल दिवस', 'শংসাপত্র', 'સર્ટિફિકેટ', '日本語の証明書', 'شهادة عربية'] as $name) {
            $id = Doc_contract::customTypeFor($name);
            $this->assertTrue(Doc_contract::isCustom($id), "'$name' minted an illegal id: '$id'");
        }
    }

    /**
     * The collision this was really about.
     *
     * `प्रमाण पत्र 2026` (certificate 2026) and `वार्षिक समारोह 2026` (annual
     * function 2026) both minted `custom:2026`. Exactly one template is active
     * per docType, so activating either would silently deactivate the other —
     * two unrelated documents fighting over one slot, while the hub showed the
     * right names because docTitle is stored separately.
     */
    public function test_different_names_never_share_an_id(): void
    {
        $pairs = [
            ['प्रमाण पत्र 2026', 'वार्षिक समारोह 2026'],
            ['खेल दिवस 2026',    'पुरस्कार 2026'],
            ['Hindi प्रमाण',      'Hindi वार्षिक'],
            ['ÄÖÜ School',        'ÑÑÑ School'],
            [str_repeat('Sports Day ', 6) . 'One', str_repeat('Sports Day ', 6) . 'Two'],
        ];
        foreach ($pairs as [$a, $b]) {
            $this->assertNotSame(
                Doc_contract::customTypeFor($a), Doc_contract::customTypeFor($b),
                "'$a' and '$b' mint the same document type id"
            );
        }
    }

    /**
     * And nothing that already worked may change.
     *
     * The id is what every template, active slot and print point is keyed on, so
     * renaming a type orphans stored documents. Checked against every custom
     * type in the project before shipping — all three were unchanged.
     */
    public function test_names_that_already_worked_mint_exactly_what_they_did(): void
    {
        $unchanged = [
            'Sports Day Participation' => 'custom:sports_day_participation',
            'Fee Concession Letter'    => 'custom:fee_concession_letter',
            'sports certificate'       => 'custom:sports_certificate',
            'Sports Day'               => 'custom:sports_day',
            'Sports-Day'               => 'custom:sports_day',
            'SPORTS DAY'               => 'custom:sports_day',
            'Sports — Day'             => 'custom:sports_day',   // an em dash is punctuation, not a lost word
            '2026'                     => 'custom:2026',
        ];
        foreach ($unchanged as $title => $expected) {
            $this->assertSame($expected, Doc_contract::customTypeFor($title),
                "'$title' changed id — every stored template of that type is now orphaned");
        }
    }

    /** Punctuation alone still names nothing — that refusal was always right. */
    public function test_a_name_with_no_letters_or_digits_is_still_refused(): void
    {
        foreach (['!!!', '---', '   ', '@#$%'] as $bad) {
            try {
                Doc_contract::customTypeFor($bad);
                $this->fail("'$bad' should not mint a document type");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('no letters or digits', $e->getMessage());
            }
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
