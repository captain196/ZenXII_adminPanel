<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_resolver;

/**
 * Doc_resolver — the seam that must stay a seam.
 *
 * Two things are under test. The ordinary one is resolution: which template is
 * active, which frozen version, and is this school ready.
 *
 * The important one is the ABSENCE. This class must never gain the ability to
 * issue a document. Issuance needs number allocation, a register and an audit
 * trail; a resolver that could quietly render one would be that engine built
 * by accident, without any of them. The tempting next commit is a small
 * helper here, so the guard is structural rather than a comment.
 */
class DocResolverTest extends TestCase
{
    private array $docs;
    private array $targets;
    private Doc_resolver $r;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) { define('BASEPATH', __DIR__); }
        if (!defined('APPPATH'))  { define('APPPATH', __DIR__ . '/../../application/'); }
        require_once __DIR__ . '/../../application/libraries/Doc_resolver.php';
    }

    protected function setUp(): void
    {
        $this->docs = [
            'documentTemplates' => [
                'SCH1_TPL0007' => ['schoolId' => 'SCH1', 'docType' => 'transfer_certificate',
                                   'activeVersion' => 2, 'status' => 'draft'],
                'SCH1_TPL0011' => ['schoolId' => 'SCH1', 'docType' => 'transfer_certificate',
                                   'activeVersion' => null],
                'SCH1_TPL0009' => ['schoolId' => 'SCH1', 'docType' => 'bonafide',
                                   'activeVersion' => null],
            ],
            'documentTemplateVersions' => [
                'SCH1_TPL0007_v2' => ['version' => 2, 'proofPdfHash' => 'sha256:abc'],
            ],
        ];
        $this->targets = [
            'transfer_certificate' => ['docType' => 'transfer_certificate', 'module' => 'Students',
                                       'surface' => 'panel', 'wired' => false],
            'bonafide'             => ['docType' => 'bonafide', 'module' => 'Students',
                                       'surface' => 'panel', 'wired' => false],
            'fee_receipt'          => ['docType' => 'fee_receipt', 'module' => 'Fee_management',
                                       'surface' => 'panel', 'wired' => false],
        ];
        $this->r = $this->make();
    }

    private function make(): Doc_resolver
    {
        return new Doc_resolver([
            'targets' => $this->targets,
            'store' => [
                'get'   => fn($c, $id) => $this->docs[$c][$id] ?? null,
                'query' => function ($c, $where) {
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
            ],
        ]);
    }

    /* ---------------------------------------------------------------- *
     * The absence — the point of the class
     * ---------------------------------------------------------------- */

    public function test_the_resolver_cannot_issue_or_render_anything(): void
    {
        $forbidden = array_filter(
            get_class_methods(Doc_resolver::class),
            fn($m) => preg_match('/issue|render|print|generate|allocate|number|pdf|emit|create/i', $m)
        );

        $this->assertSame([], array_values($forbidden),
            'Doc_resolver gained a method that looks like issuing. It RESOLVES. Issuing needs '
            . 'number allocation, an issued-document register and an audit trail — building it '
            . 'here means building it without any of those.');
    }

    public function test_the_resolver_never_writes(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Doc_resolver.php');

        foreach (["'set'", "'update'", "'delete'", '->set(', '->update(', '->delete('] as $w) {
            $this->assertStringNotContainsString($w, $src,
                "Doc_resolver references a write ($w). Its store is read-only on purpose.");
        }
    }

    /* ---------------------------------------------------------------- *
     * Resolution
     * ---------------------------------------------------------------- */

    public function test_it_finds_the_one_active_template(): void
    {
        $t = $this->r->activeTemplate('SCH1', 'transfer_certificate');
        $this->assertSame('SCH1_TPL0007', $t['_id']);
        $this->assertSame(2, $t['activeVersion']);
    }

    public function test_no_active_template_resolves_to_null_not_to_a_draft(): void
    {
        $this->assertNull($this->r->activeTemplate('SCH1', 'bonafide'));
    }

    /**
     * If the data ever holds two active templates, refusing is safer than
     * picking: an arbitrary choice between two "official" certificates cannot
     * be distinguished by the caller from a considered one.
     */
    public function test_two_active_templates_resolve_to_null_rather_than_a_guess(): void
    {
        $this->docs['documentTemplates']['SCH1_TPL0011']['activeVersion'] = 5;
        $this->assertNull($this->make()->activeTemplate('SCH1', 'transfer_certificate'));
    }

    public function test_another_school_never_resolves_this_school_s_template(): void
    {
        $this->assertNull($this->r->activeTemplate('SCH2', 'transfer_certificate'));
    }

    public function test_it_resolves_the_frozen_version_not_the_live_head(): void
    {
        $v = $this->r->activeVersion('SCH1', 'transfer_certificate');
        $this->assertSame(2, $v['version']);
        $this->assertSame('sha256:abc', $v['proofPdfHash'],
            'the frozen snapshot is what an issued document must be reproducible from');
    }

    /* ---------------------------------------------------------------- *
     * Readiness — a reason in every case, including success
     * ---------------------------------------------------------------- */

    public function test_a_ready_template_still_reports_that_issuance_is_not_wired(): void
    {
        $r = $this->r->readiness('SCH1', 'transfer_certificate');

        $this->assertFalse($r['ready']);
        $this->assertSame('ISSUANCE_NOT_WIRED', $r['code']);
        $this->assertSame('SCH1_TPL0007', $r['templateId'], 'the template is still named');
        $this->assertSame(2, $r['version']);
    }

    public function test_no_active_template_is_distinguished_from_no_print_point(): void
    {
        $this->assertSame('NO_ACTIVE_TEMPLATE', $this->r->readiness('SCH1', 'bonafide')['code']);
        $this->assertSame('NO_TARGET', $this->r->readiness('SCH1', 'not_a_type')['code']);
    }

    /** The pointer survived, the snapshot did not — the UI would otherwise look ready. */
    public function test_a_dangling_active_version_is_reported_not_ignored(): void
    {
        unset($this->docs['documentTemplateVersions']['SCH1_TPL0007_v2']);
        $r = $this->make()->readiness('SCH1', 'transfer_certificate');

        $this->assertSame('MISSING_SNAPSHOT', $r['code']);
        $this->assertStringContainsString('cannot be reproduced', $r['reason']);
    }

    public function test_every_readiness_answer_carries_a_reason(): void
    {
        foreach (['transfer_certificate', 'bonafide', 'not_a_type'] as $t) {
            $r = $this->r->readiness('SCH1', $t);
            $this->assertNotEmpty($r['reason'], "readiness('$t') gave no reason");
            $this->assertNotEmpty($r['code']);
        }
    }

    /* ---------------------------------------------------------------- *
     * The registry
     * ---------------------------------------------------------------- */

    public function test_a_module_can_ask_only_for_what_it_owns(): void
    {
        $acc = $this->r->targetsForModule('Fee_management');
        $this->assertCount(1, $acc);
        $this->assertSame('fee_receipt', $acc[0]['docType']);
    }

    public function test_nothing_is_wired_in_this_build(): void
    {
        $this->assertFalse($this->r->issuanceAvailable(),
            'CON-NO_PRINT_IMPL: no module print button is wired in this build');
    }

    /* ---------------------------------------------------------------- *
     * The real registry file, not the fixture
     * ---------------------------------------------------------------- */

    public function test_the_shipped_registry_is_complete_and_entirely_unwired(): void
    {
        $targets = include __DIR__ . '/../../application/config/document_targets.php';
        $this->assertIsArray($targets);
        $this->assertNotEmpty($targets);

        foreach ($targets as $key => $t) {
            foreach (['docType', 'module', 'surface', 'mountHint', 'entity',
                      'capability', 'audience', 'wired', 'note'] as $f) {
                $this->assertArrayHasKey($f, $t, "print point '$key' is missing '$f'");
            }
            $this->assertFalse($t['wired'], "print point '$key' claims to be wired — nothing is");
            $this->assertContains($t['surface'], ['panel', 'teacher_app', 'parent_app'],
                "print point '$key' names an unknown surface");
            $this->assertContains($t['capability'], ['view', 'edit', 'manage'],
                "print point '$key' names an ungraded capability");
        }
    }

    /**
     * Distinct series must never share a counter. A receipt number and a TC
     * number are different legal sequences; merging them corrupts both, and
     * the corruption is only visible years later in an audit.
     */
    public function test_every_issuing_print_point_declares_its_own_numbering_series(): void
    {
        $targets = include __DIR__ . '/../../application/config/document_targets.php';
        $series = [];

        foreach ($targets as $key => $t) {
            if (($t['entity'] ?? null) === 'issuedDocument') {
                continue;                       // read-only consumer, allocates nothing
            }
            $this->assertNotEmpty($t['numbering'] ?? null,
                "print point '$key' issues a document but declares no numbering series");
            $clash = $series[$t['numbering']] ?? '';
            $this->assertArrayNotHasKey($t['numbering'], $series,
                "print points '$key' and '$clash' share the numbering series "
                . "'{$t['numbering']}'. Distinct document types are distinct legal "
                . 'sequences and must never share a counter.');
            $series[$t['numbering']] = $key;
        }
    }
}
