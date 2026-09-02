<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_compliance;
use RuntimeException;

/**
 * Doc_compliance — the re-validation REPORT (P5.6).
 *
 * The accept is "a new profile version produces an affected-school report;
 * NOTHING AUTO-INVALIDATES". These tests pin both halves, and the second half
 * matters more: auto-invalidating would take a school's active certificate away
 * without anyone deciding to. A clerk who printed a Transfer Certificate
 * yesterday would find the print button dead this morning with no human in the
 * loop — worse, on a statutory document, than being slightly out of date.
 */
class DocComplianceTest extends TestCase
{
    private array $docs;
    private Doc_compliance $svc;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_compliance.php';
    }

    protected function setUp(): void
    {
        $this->docs = [
            'complianceAuthorities' => [
                'cbse' => ['label' => 'CBSE', 'version' => 5, 'evidence' => 'A',
                           'verifiedOn' => '2026-08-18', 'reviewMonths' => 12],
                'ker'  => ['label' => 'Kerala', 'version' => 2, 'evidence' => 'A',
                           'verifiedOn' => '2026-08-16'],
            ],
            'documentTemplates' => [
                // behind AND active — must sort first
                'SCH1_TPL1' => ['schoolId' => 'SCH1', 'docType' => 'transfer_certificate',
                                'status' => 'draft', 'activeVersion' => 2,
                                'complianceLayers' => [['authorityId' => 'cbse', 'version' => 4, 'applied' => true]]],
                // behind, not active
                'SCH2_TPL1' => ['schoolId' => 'SCH2', 'docType' => 'transfer_certificate',
                                'status' => 'draft', 'activeVersion' => null,
                                'complianceLayers' => [['authorityId' => 'cbse', 'version' => 3, 'applied' => true]]],
                // current — not affected
                'SCH3_TPL1' => ['schoolId' => 'SCH3', 'docType' => 'transfer_certificate',
                                'status' => 'draft', 'activeVersion' => 1,
                                'complianceLayers' => [['authorityId' => 'cbse', 'version' => 5, 'applied' => true]]],
                // EXCLUDED layer — a documented decision not to follow it
                'SCH4_TPL1' => ['schoolId' => 'SCH4', 'docType' => 'transfer_certificate',
                                'status' => 'draft', 'activeVersion' => 1,
                                'complianceLayers' => [['authorityId' => 'cbse', 'version' => 2, 'applied' => false,
                                                        'reason' => 'written exemption on file']]],
                // different authority entirely
                'SCH5_TPL1' => ['schoolId' => 'SCH5', 'docType' => 'leaving_certificate_5a',
                                'status' => 'draft', 'activeVersion' => 1,
                                'complianceLayers' => [['authorityId' => 'ker', 'version' => 1, 'applied' => true]]],
            ],
        ];

        $this->svc = new Doc_compliance(['store' => [
            'get'   => fn($c, $id) => $this->docs[$c][$id] ?? null,
            'query' => fn($c, $w) => $this->docs[$c],
        ]]);
    }

    /* ---------------------------------------------------------------- *
     * The report
     * ---------------------------------------------------------------- */

    public function test_the_report_lists_only_templates_behind_the_current_version(): void
    {
        $r = $this->svc->affectedByAuthority('cbse');
        $ids = array_column($r['affected'], 'templateId');

        $this->assertContains('SCH1_TPL1', $ids);
        $this->assertContains('SCH2_TPL1', $ids);
        $this->assertNotContains('SCH3_TPL1', $ids, 'already current');
        $this->assertNotContains('SCH5_TPL1', $ids, 'a different authority');
    }

    /**
     * An excluded layer is not affected. The school recorded a written reason
     * for not applying it, and revising a rule you are documented as not
     * following changes nothing for you.
     */
    public function test_an_excluded_layer_is_not_reported_as_affected(): void
    {
        $ids = array_column($this->svc->affectedByAuthority('cbse')['affected'], 'templateId');
        $this->assertNotContains('SCH4_TPL1', $ids);
    }

    /**
     * ACTIVE first. They are what print points resolve today; a stale draft
     * harms nobody until someone publishes it.
     */
    public function test_active_templates_are_listed_first(): void
    {
        $r = $this->svc->affectedByAuthority('cbse');
        $this->assertTrue($r['affected'][0]['active']);
        $this->assertSame('SCH1_TPL1', $r['affected'][0]['templateId']);
        $this->assertSame(1, $r['activeCount']);
    }

    public function test_the_report_carries_the_numbers_needed_to_act(): void
    {
        $r = $this->svc->affectedByAuthority('cbse');

        $this->assertSame(5, $r['currentVersion']);
        $this->assertSame('CBSE', $r['label']);
        $this->assertSame('A', $r['evidence']);
        $this->assertSame('2026-08-18', $r['verifiedOn']);

        $byId = array_column($r['affected'], null, 'templateId');
        $this->assertSame(4, $byId['SCH1_TPL1']['appliedVersion'], 'behind by 1');
        $this->assertSame(3, $byId['SCH2_TPL1']['appliedVersion'], 'behind by 2');
        $this->assertSame(['SCH1', 'SCH2'], $r['schools']);
    }

    /** "0 affected" for an id that does not exist reads as reassurance. */
    public function test_an_unknown_authority_throws_rather_than_reporting_zero(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/reads as reassurance/');
        $this->svc->affectedByAuthority('does_not_exist');
    }

    /* ---------------------------------------------------------------- *
     * NOTHING AUTO-INVALIDATES — the half that matters most
     * ---------------------------------------------------------------- */

    public function test_producing_the_report_mutates_nothing(): void
    {
        $before = $this->docs;
        $this->svc->affectedByAuthority('cbse');
        $this->assertSame($before, $this->docs,
            'the report must not touch a template — auto-invalidating would take a '
            . "school's active certificate away without anyone deciding to");
    }

    /**
     * Structural, not incidental: the class must expose NO method that could
     * change a template. A future "helpful" convenience method is exactly how
     * a report becomes an auto-action.
     */
    public function test_the_class_exposes_no_method_that_can_change_a_template(): void
    {
        $methods = get_class_methods(Doc_compliance::class);
        $mutating = array_filter($methods, fn($m) =>
            preg_match('/^(set|save|update|apply|invalidate|archive|activate|delete|remove)/i', $m));

        $this->assertSame([], array_values($mutating),
            'Doc_compliance gained a mutating method. It REPORTS; acting on the report is a '
            . 'person\'s job, one template at a time, through the normal draft/publish/activate '
            . 'path that already carries its own gates and audit trail.');
    }

    /* ---------------------------------------------------------------- *
     * Staleness and evidence
     * ---------------------------------------------------------------- */

    public function test_an_authority_never_verified_is_stale(): void
    {
        $this->assertTrue($this->svc->isStale(['label' => 'X']));
        $this->assertTrue($this->svc->isStale(['verifiedOn' => 'not-a-date']));
    }

    public function test_staleness_respects_the_per_authority_review_interval(): void
    {
        $now = strtotime('2026-09-02');

        $this->assertFalse($this->svc->isStale(['verifiedOn' => '2026-08-18', 'reviewMonths' => 12], $now));
        $this->assertTrue($this->svc->isStale(['verifiedOn' => '2024-01-01', 'reviewMonths' => 12], $now));
        // A short interval makes a recent check stale — the point of it being
        // per-authority rather than one global constant.
        $this->assertTrue($this->svc->isStale(['verifiedOn' => '2026-06-01', 'reviewMonths' => 1], $now));
    }

    /**
     * BEST, never averaged. Averaging would let two Level-C citations present
     * as a Level-B fact.
     */
    public function test_evidence_is_the_best_across_applied_layers_never_an_average(): void
    {
        $this->assertSame('A', $this->svc->bestEvidence([
            ['evidence' => 'C'], ['evidence' => 'A'], ['evidence' => 'D'],
        ]));
        $this->assertSame('C', $this->svc->bestEvidence([['evidence' => 'C'], ['evidence' => 'D']]));
        $this->assertNull($this->svc->bestEvidence([]));
    }

    public function test_an_excluded_layer_does_not_lend_its_evidence(): void
    {
        $this->assertSame('C', $this->svc->bestEvidence([
            ['evidence' => 'A', 'applied' => false],
            ['evidence' => 'C', 'applied' => true],
        ]));
    }
}
