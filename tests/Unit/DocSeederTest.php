<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_seeder;
use InvalidArgumentException;
use RuntimeException;

/**
 * Doc_seeder — every school starts with the documents it should already have.
 *
 * Two things these tests exist to stop, and they pull in opposite directions:
 *   1. a school being handed a statutory form its state does not prescribe, and
 *   2. a school being handed a second copy of a document it already has.
 * A seeder that is careless about either is worse than no seeder.
 */
class DocSeederTest extends TestCase
{
    /** @var array<int,array> */
    private array $created;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_template_service.php';
        require_once __DIR__ . '/../../application/libraries/Doc_seeder.php';
    }

    protected function setUp(): void
    {
        $this->created = [];
    }

    /** Records what WOULD be created, without a Firestore anywhere near it. */
    private function fakeCreate(): callable
    {
        return function (string $schoolId, string $docType, array $seed, string $by): array {
            $this->created[] = ['schoolId' => $schoolId, 'docType' => $docType,
                                'seed' => $seed, 'by' => $by];
            return ['templateId' => $schoolId . '_TPL' . count($this->created), 'head' => []];
        };
    }

    private function starters(): array
    {
        $t = ['page' => ['size' => 'A4'], 'objects' => [['id' => 'a']], 'languages' => ['en'],
              'defaultLanguage' => 'en', 'header' => [], 'footer' => []];
        return [
            ['id' => 'tc_cbse',  'docType' => 'transfer_certificate', 'name' => 'Annexure-I',
             'boards' => ['CBSE'], 'states' => null, 'template' => $t],
            ['id' => 'tc_plain', 'docType' => 'transfer_certificate', 'name' => 'Plain',
             'boards' => null, 'states' => null, 'template' => $t],
            ['id' => 'lc_5a',    'docType' => 'leaving_certificate_5a', 'name' => 'Form 5A',
             'boards' => null, 'states' => ['Kerala'], 'template' => $t],
            ['id' => 'bonafide', 'docType' => 'bonafide', 'name' => 'Bonafide',
             'boards' => null, 'states' => null, 'template' => $t],
        ];
    }

    private function svc(): Doc_seeder
    {
        return new Doc_seeder(['starters' => $this->starters(), 'create' => $this->fakeCreate()]);
    }

    /* ---------------------------------------------------------------- *
     * Gating — the half that protects the school from us
     * ---------------------------------------------------------------- */

    /**
     * A Kerala-prescribed form must never be seeded outside Kerala.
     *
     * This is the failure that would matter most: handing a school in another
     * state a statutory instrument its own state does not prescribe, and doing
     * it automatically, at provisioning time, without anyone choosing it.
     */
    public function test_a_state_gated_form_is_never_seeded_outside_its_state(): void
    {
        $r = $this->svc()->seed('SCH1', 'CBSE', 'Uttar Pradesh', [], 'STA1');

        $this->assertNotContains('lc_5a', $r['seeded'], 'a Kerala form was seeded into Uttar Pradesh');
        $this->assertContains('lc_5a', $r['ineligible']);
        $this->assertNotContains('leaving_certificate_5a', array_column($this->created, 'docType'));
    }

    public function test_a_state_gated_form_IS_seeded_inside_its_state(): void
    {
        $r = $this->svc()->seed('SCH1', 'CBSE', 'Kerala', [], 'STA1');
        $this->assertContains('lc_5a', $r['seeded']);
    }

    /** A board-gated starter follows the same rule. */
    public function test_a_board_gated_starter_is_not_seeded_to_another_board(): void
    {
        $r = $this->svc()->seed('SCH1', 'ICSE', 'Uttar Pradesh', [], 'STA1');
        $this->assertContains('tc_cbse', $r['ineligible']);
        // …but the school still gets a transfer certificate, via the ungated one.
        $this->assertContains('transfer_certificate', array_column($this->created, 'docType'));
    }

    /**
     * Live data holds "madhya pradesh" and "UTTAR PRADESH"; the catalogue says
     * "Kerala". Gating on an exact string would silently depend on casing.
     */
    public function test_gating_is_insensitive_to_case_and_whitespace(): void
    {
        $r = $this->svc()->seed('SCH1', 'cbse', '  kerala ', [], 'STA1');
        $this->assertContains('lc_5a', $r['seeded']);
        $this->assertContains('tc_cbse', $r['seeded']);
    }

    /* ---------------------------------------------------------------- *
     * Idempotency — the half that protects the school from repetition
     * ---------------------------------------------------------------- */

    public function test_seeding_twice_creates_nothing_the_second_time(): void
    {
        $svc   = $this->svc();
        $first = $svc->seed('SCH1', 'CBSE', 'Kerala', [], 'STA1');
        $this->assertNotEmpty($first['seeded']);

        // feed the first run's output back in as the school's existing library
        $existing = array_map(fn($c) => ['docType' => $c['docType']], $this->created);
        $countAfterFirst = count($this->created);

        $second = $svc->seed('SCH1', 'CBSE', 'Kerala', $existing, 'STA1');

        $this->assertSame([], $second['seeded'], 'a second run seeded again');
        $this->assertCount($countAfterFirst, $this->created, 'a second run wrote templates');
    }

    /** A school that already built its own TC must not be handed another. */
    public function test_a_type_the_school_already_has_is_skipped(): void
    {
        $existing = [['docType' => 'transfer_certificate']];
        $r = $this->svc()->seed('SCH1', 'CBSE', 'Uttar Pradesh', $existing, 'STA1');

        $this->assertNotContains('transfer_certificate', array_column($this->created, 'docType'),
            'the school already had a transfer certificate and was given a second');
        $this->assertContains('bonafide', $r['seeded'], 'unrelated types should still be seeded');
    }

    /**
     * Two starters share `transfer_certificate`. A school must get ONE of each
     * TYPE, not one of each starter — otherwise a CBSE school is seeded both
     * Annexure-I and the plain form and has to work out which is real.
     */
    public function test_two_starters_of_one_type_seed_only_one_template(): void
    {
        $this->svc()->seed('SCH1', 'CBSE', 'Uttar Pradesh', [], 'STA1');

        $tcs = array_filter($this->created, fn($c) => $c['docType'] === 'transfer_certificate');
        $this->assertCount(1, $tcs, 'the school was seeded two transfer certificates');
    }

    /* ---------------------------------------------------------------- *
     * What a seeded template is
     * ---------------------------------------------------------------- */

    /**
     * Seeded templates are DRAFTS. Publishing freezes a legal record and
     * activating is what every print point resolves; neither may happen because
     * a page was loaded.
     */
    public function test_seeding_never_publishes_or_activates(): void
    {
        $this->svc()->seed('SCH1', 'CBSE', 'Kerala', [], 'STA1');

        foreach ($this->created as $c) {
            foreach (['status', 'publishedVersion', 'activeVersion', 'version', 'lockVersion'] as $k) {
                $this->assertArrayNotHasKey($k, $c['seed'],
                    "seeding tried to set lifecycle field '$k' — that is create()'s to decide");
            }
        }
    }

    /** The starter it came from is recorded, so an update offer can find it later. */
    public function test_the_originating_starter_is_recorded(): void
    {
        $this->svc()->seed('SCH1', 'CBSE', 'Uttar Pradesh', [], 'STA1');
        foreach ($this->created as $c) {
            $this->assertNotEmpty($c['seed']['starterId'] ?? '',
                'without starterId a later revision cannot tell which schools to offer it to');
        }
    }

    public function test_the_acting_user_is_carried_through(): void
    {
        $this->svc()->seed('SCH1', 'CBSE', 'Uttar Pradesh', [], 'STA_SEED');
        $this->assertSame('STA_SEED', $this->created[0]['by']);
    }

    /* ---------------------------------------------------------------- *
     * Refusals
     * ---------------------------------------------------------------- */

    public function test_an_empty_catalogue_is_refused_rather_than_reported_as_success(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/indistinguishable from a school that is already/');
        new Doc_seeder(['starters' => [], 'create' => $this->fakeCreate()]);
    }

    public function test_a_missing_school_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc()->seed('', 'CBSE', 'Kerala', [], 'STA1');
    }
}
