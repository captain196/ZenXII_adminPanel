<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_block_service;
use RuntimeException;
use InvalidArgumentException;

/**
 * Doc_block_service — reusable blocks, and the OFFER model (Phase 8).
 *
 * The plan's P8.2 accept reads "editing a letterhead updates every template
 * that references it". `FIGMA_ARCHITECTURE_STUDY.md` found that this
 * CONTRADICTS `COLLECTION_SHAPES.md` §4 — "published versions: no update, no
 * delete — ever" — and resolved it with Figma's library model: an update is
 * OFFERED, never pushed.
 *
 * These tests pin the resolution rather than the stale accept, because pushing
 * would silently alter a template a principal already approved, and on a
 * statutory document that is a change of legal content nobody authorised.
 */
class DocBlockServiceTest extends TestCase
{
    private array $docs;
    private array $audit;
    private Doc_block_service $svc;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_block_service.php';
    }

    protected function setUp(): void
    {
        $this->docs = [
            'reusableBlocks' => [
                'SCH1_BLK0001' => [
                    'schoolId' => 'SCH1', 'blockId' => 'BLK0001',
                    'blockType' => 'letterhead', 'name' => 'Main letterhead',
                    'version' => 3,
                    'objects' => [[
                        'id' => 'h_name', 'type' => 'text',
                        'content' => ['i18n' => [
                            'en' => ['runs' => [['f' => 'school.name'], ['t' => ' · '], ['f' => 'school.affiliationNo']]],
                            'hi' => ['runs' => [['f' => 'school.name']]],
                        ]],
                    ]],
                ],
            ],
            'documentTemplates' => [
                'SCH1_TPL0007' => [
                    'schoolId' => 'SCH1', 'status' => 'draft', 'lockVersion' => 5,
                    'blockRefs' => ['BLK0001' => 3], 'blockIgnored' => [],
                ],
                'SCH1_TPL0008' => [   // behind
                    'schoolId' => 'SCH1', 'status' => 'draft', 'lockVersion' => 2,
                    'blockRefs' => ['BLK0001' => 2], 'blockIgnored' => [],
                ],
                'SCH1_TPL0009' => [   // behind AND published
                    'schoolId' => 'SCH1', 'status' => 'published', 'lockVersion' => 9,
                    'blockRefs' => ['BLK0001' => 1], 'blockIgnored' => [],
                ],
                'SCH1_TPL0010' => [   // references nothing
                    'schoolId' => 'SCH1', 'status' => 'draft', 'lockVersion' => 1,
                ],
            ],
        ];
        $this->audit = [];

        $store = [
            'get'    => fn($c, $id) => $this->docs[$c][$id] ?? null,
            'set'    => function ($c, $id, $d) { $this->docs[$c][$id] = $d; return true; },
            'update' => function ($c, $id, $p) {
                $this->docs[$c][$id] = array_merge($this->docs[$c][$id] ?? [], $p);
                return true;
            },
            'query'  => function ($c, $where) {
                $out = [];
                foreach ($this->docs[$c] as $id => $row) {
                    $ok = true;
                    foreach ($where as [$f, , $v]) {
                        if (($row[$f] ?? null) !== $v) { $ok = false; break; }
                    }
                    if ($ok) { $out[$id] = $row; }
                }
                return $out;
            },
        ];
        $this->svc = new Doc_block_service([
            'store' => $store,
            'audit' => function ($a, $e, $d) { $this->audit[] = [$a, $e, $d]; },
        ]);
    }

    /* ---------------------------------------------------------------- *
     * P8.1 — save and list
     * ---------------------------------------------------------------- */

    public function test_a_block_saves_and_lists_for_its_school(): void
    {
        $this->svc->save('SCH1_BLK0002', [
            'schoolId' => 'SCH1', 'blockId' => 'BLK0002',
            'blockType' => 'signature', 'name' => 'Principal signature',
        ]);

        $all = $this->svc->listFor('SCH1');
        $this->assertCount(2, $all);
        $this->assertCount(1, $this->svc->listFor('SCH1', 'signature'));
        $this->assertSame([], $this->svc->listFor('SCH2'), 'blocks are school-scoped');
    }

    /** An unscoped block would be offered to every tenant. */
    public function test_a_block_without_a_school_or_type_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->save('X', ['blockType' => 'letterhead']);
    }

    public function test_a_new_block_starts_at_v1_and_an_edit_bumps_the_version(): void
    {
        $new = $this->svc->save('SCH1_BLK0003', [
            'schoolId' => 'SCH1', 'blockId' => 'BLK0003', 'blockType' => 'seal',
        ]);
        $this->assertSame(1, $new['version']);

        $edited = $this->svc->save('SCH1_BLK0001', [
            'schoolId' => 'SCH1', 'blockId' => 'BLK0001',
            'blockType' => 'letterhead', 'name' => 'Main letterhead v4',
        ]);
        $this->assertSame(4, $edited['version'], 'an edit bumps the version, which is what makes the offer visible');
    }

    /* ---------------------------------------------------------------- *
     * P8.2 — OFFERED, never pushed
     * ---------------------------------------------------------------- */

    /** THE guarantee. A version bump must not touch a single template. */
    public function test_bumping_a_block_version_mutates_no_template(): void
    {
        $before = $this->docs['documentTemplates'];

        $this->svc->save('SCH1_BLK0001', [
            'schoolId' => 'SCH1', 'blockId' => 'BLK0001',
            'blockType' => 'letterhead', 'name' => 'edited',
        ]);

        $this->assertSame($before, $this->docs['documentTemplates'],
            'publishing a new block version must not alter a template a principal already approved');
    }

    public function test_offers_lists_only_templates_that_are_behind(): void
    {
        $this->svc->save('SCH1_BLK0001', [
            'schoolId' => 'SCH1', 'blockId' => 'BLK0001', 'blockType' => 'letterhead',
        ]);   // -> v4

        $ids = array_column($this->svc->offersFor('SCH1_BLK0001'), 'templateId');
        sort($ids);

        $this->assertSame(['SCH1_TPL0007', 'SCH1_TPL0008', 'SCH1_TPL0009'], $ids);
        $this->assertNotContains('SCH1_TPL0010', $ids, 'a template referencing no block has no offer');
    }

    /** The report must carry the numbers, or "behind" is unactionable. */
    public function test_an_offer_states_the_pinned_and_available_versions(): void
    {
        $this->svc->save('SCH1_BLK0001', ['schoolId' => 'SCH1', 'blockId' => 'BLK0001', 'blockType' => 'letterhead']);
        $offers = $this->svc->offersFor('SCH1_BLK0001');

        $byId = array_column($offers, null, 'templateId');
        $this->assertSame(2, $byId['SCH1_TPL0008']['pinned']);
        $this->assertSame(4, $byId['SCH1_TPL0008']['available']);
        $this->assertSame('published', $byId['SCH1_TPL0009']['status']);
    }

    public function test_accepting_an_offer_moves_only_that_templates_pin(): void
    {
        $this->svc->save('SCH1_BLK0001', ['schoolId' => 'SCH1', 'blockId' => 'BLK0001', 'blockType' => 'letterhead']);
        $this->svc->acceptOffer('SCH1_TPL0008', 'BLK0001');

        $this->assertSame(4, $this->docs['documentTemplates']['SCH1_TPL0008']['blockRefs']['BLK0001']);
        $this->assertSame(3, $this->docs['documentTemplates']['SCH1_TPL0007']['blockRefs']['BLK0001'],
            'accepting for one template must not move another');
    }

    /**
     * Accepting is an EDIT. An edit to a published template goes through a new
     * draft (P6.3) — silently mutating an approved document is exactly the
     * failure the offer model exists to prevent.
     */
    public function test_accepting_into_a_published_template_is_refused(): void
    {
        $this->svc->save('SCH1_BLK0001', ['schoolId' => 'SCH1', 'blockId' => 'BLK0001', 'blockType' => 'letterhead']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/never edited in place/');
        $this->svc->acceptOffer('SCH1_TPL0009', 'BLK0001');
    }

    public function test_accepting_when_nothing_is_pending_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no pending update/');
        $this->svc->acceptOffer('SCH1_TPL0007', 'BLK0001');   // already at v3
    }

    /** Declining must stick, or the badge nags and the signal is learned away. */
    public function test_declining_is_remembered_and_accepting_clears_it(): void
    {
        $this->svc->save('SCH1_BLK0001', ['schoolId' => 'SCH1', 'blockId' => 'BLK0001', 'blockType' => 'letterhead']);

        $this->svc->declineOffer('SCH1_TPL0008', 'BLK0001');
        $this->assertTrue($this->docs['documentTemplates']['SCH1_TPL0008']['blockIgnored']['BLK0001']);

        $offer = array_column($this->svc->offersFor('SCH1_BLK0001'), null, 'templateId')['SCH1_TPL0008'];
        $this->assertTrue($offer['ignored'], 'the report still lists it, flagged as declined');

        $this->svc->acceptOffer('SCH1_TPL0008', 'BLK0001');
        $this->assertArrayNotHasKey('BLK0001',
            $this->docs['documentTemplates']['SCH1_TPL0008']['blockIgnored']);
    }

    /* ---------------------------------------------------------------- *
     * Coupling
     * ---------------------------------------------------------------- */

    /**
     * A block imposes its bound keys on every contract that uses it, and the
     * coupling runs one way. The shared letterhead binds school.affiliationNo,
     * so every doc type whose starter includes it must declare that key — a
     * fact worth being able to CHECK rather than discovering at publish.
     */
    public function test_bound_keys_reports_what_the_block_imposes_across_all_languages(): void
    {
        $keys = $this->svc->boundKeys('SCH1_BLK0001');
        sort($keys);

        $this->assertSame(['school.affiliationNo', 'school.name'], $keys);
    }

    public function test_bound_keys_on_an_unknown_block_is_empty_not_an_error(): void
    {
        $this->assertSame([], $this->svc->boundKeys('SCH1_NOPE'));
    }

    public function test_block_lifecycle_actions_are_audited(): void
    {
        $this->svc->save('SCH1_BLK0004', ['schoolId' => 'SCH1', 'blockId' => 'BLK0004', 'blockType' => 'seal']);
        $this->svc->save('SCH1_BLK0001', ['schoolId' => 'SCH1', 'blockId' => 'BLK0001', 'blockType' => 'letterhead']);
        $this->svc->declineOffer('SCH1_TPL0008', 'BLK0001');
        $this->svc->acceptOffer('SCH1_TPL0008', 'BLK0001');

        $this->assertSame(
            ['block_create', 'block_edit', 'block_decline', 'block_accept'],
            array_column($this->audit, 0)
        );
    }
}
