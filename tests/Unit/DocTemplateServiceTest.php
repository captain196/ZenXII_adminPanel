<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_template_service;
use RuntimeException;

/**
 * Doc_template_service — the four lifecycle guarantees (Phase 6).
 *
 * These are not "does the method run" tests. Each one pins a guarantee whose
 * absence is invisible until it costs a school a real document:
 *
 *   P6.5  a lost edit — two clerks, one silently overwritten
 *   P6.1  a rewritten snapshot — the record of what was actually issued, changed
 *   P6.3  a published template edited in place — head and snapshot disagree
 *   P6.4  two active templates — two different certificates both "the" one
 *   P6.6  a published head reverted to draft server-side
 *   P6.7  a transition nobody can find afterwards
 *
 * The store is injected, so all of it runs with no emulator and no network.
 */
class DocTemplateServiceTest extends TestCase
{
    private array $docs;
    private array $audit;
    private Doc_template_service $svc;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_template_service.php';
    }

    protected function setUp(): void
    {
        $this->docs = [
            'documentTemplates' => [
                'SCH1_TPL0007' => [
                    'schoolId' => 'SCH1', 'templateId' => 'TPL0007',
                    'docType'  => 'transfer_certificate', 'name' => 'TC',
                    'status'   => 'draft', 'version' => 3,
                    'publishedVersion' => 2, 'activeVersion' => null,
                    'lockVersion' => 17, 'objects' => [['id' => 'a']],
                    'languages' => ['en'], 'defaultLanguage' => 'en',
                    'complianceLayers' => [['authorityId' => 'cbse', 'version' => 4]],
                ],
                'SCH1_TPL0009' => [
                    'schoolId' => 'SCH1', 'templateId' => 'TPL0009',
                    'docType'  => 'transfer_certificate', 'status' => 'draft',
                    'version'  => 1, 'publishedVersion' => 1, 'activeVersion' => 1,
                    'lockVersion' => 4,
                ],
            ],
            'documentTemplateVersions' => [],
        ];
        $this->audit = [];
        $this->svc   = $this->make();
    }

    private function make(bool $withTransaction = true): Doc_template_service
    {
        $d = function () { return $this->docs; };
        $store = [
            'get'    => fn($c, $id) => $this->docs[$c][$id] ?? null,
            'set'    => function ($c, $id, $data) { $this->docs[$c][$id] = $data; return true; },
            'update' => function ($c, $id, $patch) {
                $this->docs[$c][$id] = array_merge($this->docs[$c][$id] ?? [], $patch);
                return true;
            },
            'exists' => fn($c, $id) => isset($this->docs[$c][$id]),
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
            // A transaction double: runs the closure. Enough to prove the code
            // goes THROUGH the transaction path; the atomicity itself is
            // Firestore's and cannot be asserted here — see the note on
            // test_activate_refuses_to_run_without_a_transaction.
            'transact' => $withTransaction ? fn(callable $fn) => $fn() : null,
        ];
        return new Doc_template_service([
            'store' => $store,
            'audit' => function ($a, $e, $desc) { $this->audit[] = [$a, $e, $desc]; },
        ]);
    }

    private function proof(): array
    {
        return [
            'hash'         => 'sha256:abc',
            'fontManifest' => ['lohitdeva' => 'sha256:f1', 'dejavusans' => 'sha256:f2'],
            'mpdfVersion'  => '8.3.1',
            'pdfPaths'     => ['en' => 'schools/SCH1/_proofs/TPL0007_v3_en.pdf'],
        ];
    }

    /* ---------------------------------------------------------------- *
     * P6.6 — the state machine
     * ---------------------------------------------------------------- */

    public function test_the_legal_transitions_are_exactly_these(): void
    {
        $this->assertTrue($this->svc->canTransition('draft', 'published'));
        $this->assertTrue($this->svc->canTransition('draft', 'archived'));
        $this->assertTrue($this->svc->canTransition('published', 'archived'));

        $this->assertFalse($this->svc->canTransition('published', 'draft'),
            'a published head must never revert to draft — the snapshot would outlive its own head');
        $this->assertFalse($this->svc->canTransition('archived', 'draft'));
        $this->assertFalse($this->svc->canTransition('archived', 'published'),
            'archived is terminal');
    }

    public function test_an_illegal_transition_names_both_states_and_the_legal_set(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/illegal transition 'archived' → 'draft'/");
        $this->svc->assertTransition('archived', 'draft');
    }

    /* ---------------------------------------------------------------- *
     * P6.5 — optimistic concurrency
     * ---------------------------------------------------------------- */

    public function test_a_save_with_the_current_lockversion_succeeds_and_bumps_it(): void
    {
        $out = $this->svc->save('SCH1_TPL0007', ['name' => 'TC renamed'], 17);
        $this->assertSame(18, $out['lockVersion']);
        $this->assertSame('TC renamed', $this->docs['documentTemplates']['SCH1_TPL0007']['name']);
    }

    /**
     * THE guarantee: the second writer is REFUSED, and the first writer's work
     * is still there. A silent overwrite is the failure this exists to prevent.
     */
    public function test_two_concurrent_saves_produce_exactly_one_conflict_and_no_lost_edit(): void
    {
        $both = 17;                                    // both clerks read v17
        $this->svc->save('SCH1_TPL0007', ['name' => 'first writer'], $both);

        try {
            $this->svc->save('SCH1_TPL0007', ['name' => 'second writer'], $both);
            $this->fail('the second save must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('E_CONFLICT', $e->getMessage());
            $this->assertStringContainsString('NOT saved', $e->getMessage());
        }

        $this->assertSame('first writer', $this->docs['documentTemplates']['SCH1_TPL0007']['name'],
            "the first writer's edit must survive the refused second write");
    }

    /** save() must not be a back door into the lifecycle fields. */
    public function test_save_cannot_move_lifecycle_fields(): void
    {
        $this->svc->save('SCH1_TPL0007', [
            'name' => 'ok', 'status' => 'published', 'activeVersion' => 99,
            'publishedVersion' => 99, 'templateId' => 'HACKED',
        ], 17);

        $h = $this->docs['documentTemplates']['SCH1_TPL0007'];
        $this->assertSame('draft', $h['status']);
        $this->assertNull($h['activeVersion']);
        $this->assertSame(2, $h['publishedVersion']);
        $this->assertSame('TPL0007', $h['templateId']);
    }

    /* ---------------------------------------------------------------- *
     * P6.1 / P6.2 / P6.3 — publish
     * ---------------------------------------------------------------- */

    public function test_publish_freezes_a_snapshot_and_opens_the_next_draft(): void
    {
        $r = $this->svc->publish('SCH1_TPL0007', $this->proof(), 'STA1');

        $this->assertSame('SCH1_TPL0007_v3', $r['versionId']);
        $snap = $this->docs['documentTemplateVersions']['SCH1_TPL0007_v3'];
        $this->assertSame(3, $snap['version']);
        $this->assertSame([['id' => 'a']], $snap['snapshot']['objects']);

        $head = $this->docs['documentTemplates']['SCH1_TPL0007'];
        $this->assertSame('draft', $head['status'], 'the head opens the NEXT draft');
        $this->assertSame(3, $head['publishedVersion']);
        $this->assertSame(4, $head['version']);
    }

    /** P6.2 — a snapshot that cannot name its faces and engine cannot be re-rendered. */
    public function test_publish_records_the_font_manifest_and_engine_version(): void
    {
        $this->svc->publish('SCH1_TPL0007', $this->proof(), 'STA1');
        $snap = $this->docs['documentTemplateVersions']['SCH1_TPL0007_v3'];

        $this->assertSame('8.3.1', $snap['mpdfVersion']);
        $this->assertArrayHasKey('lohitdeva', $snap['fontManifest']);
        $this->assertSame('sha256:abc', $snap['proofPdfHash']);
    }

    /** @dataProvider missingProofParts */
    public function test_publish_refuses_without_the_reproducibility_metadata(string $missing): void
    {
        $p = $this->proof();
        unset($p[$missing]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/proof\.$missing/");
        $this->svc->publish('SCH1_TPL0007', $p);
    }

    public static function missingProofParts(): array
    {
        return [['hash'], ['fontManifest'], ['mpdfVersion']];
    }

    /** The layers that APPLIED are frozen, not referenced. */
    public function test_publish_freezes_the_compliance_layers(): void
    {
        $this->svc->publish('SCH1_TPL0007', $this->proof());
        $snap = $this->docs['documentTemplateVersions']['SCH1_TPL0007_v3'];

        $this->assertSame([['authorityId' => 'cbse', 'version' => 4]], $snap['complianceLayers'],
            'a later authority revision must not retroactively change what an issued '
            . 'certificate was validated against');
    }

    /** P6.1 — create-only. Re-publishing over a version would rewrite history. */
    public function test_publishing_over_an_existing_version_is_refused(): void
    {
        $this->docs['documentTemplateVersions']['SCH1_TPL0007_v3'] = ['version' => 3];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/create-only/');
        $this->svc->publish('SCH1_TPL0007', $this->proof());
    }

    /** P6.3 — editing a published template never touches the snapshot. */
    public function test_editing_after_publish_touches_the_head_and_not_the_snapshot(): void
    {
        $this->svc->publish('SCH1_TPL0007', $this->proof());
        $before = $this->docs['documentTemplateVersions']['SCH1_TPL0007_v3'];

        $lock = $this->docs['documentTemplates']['SCH1_TPL0007']['lockVersion'];
        $this->svc->save('SCH1_TPL0007', ['name' => 'edited after publish'], $lock);

        $this->assertSame($before, $this->docs['documentTemplateVersions']['SCH1_TPL0007_v3'],
            'the frozen snapshot must be byte-identical after a head edit');
    }

    /* ---------------------------------------------------------------- *
     * P6.4 — activate
     * ---------------------------------------------------------------- */

    public function test_activate_displaces_the_incumbent_leaving_exactly_one_active(): void
    {
        $r = $this->svc->publish('SCH1_TPL0007', $this->proof());
        $this->svc->activate('SCH1_TPL0007');

        $active = array_filter(
            $this->docs['documentTemplates'],
            fn($t) => ($t['docType'] ?? '') === 'transfer_certificate' && ($t['activeVersion'] ?? null) !== null
        );
        $this->assertCount(1, $active, 'exactly one active per (school, docType)');
        $this->assertArrayHasKey('SCH1_TPL0007', $active);
        $this->assertNull($this->docs['documentTemplates']['SCH1_TPL0009']['activeVersion']);
    }

    /** Publishing is not activating — that is a separate, deliberate act. */
    public function test_publish_does_not_activate(): void
    {
        $this->svc->publish('SCH1_TPL0007', $this->proof());
        $this->assertNull($this->docs['documentTemplates']['SCH1_TPL0007']['activeVersion']);
        $this->assertSame(1, $this->docs['documentTemplates']['SCH1_TPL0009']['activeVersion'],
            'the incumbent keeps serving until someone activates the new one');
    }

    public function test_activating_a_never_published_template_is_refused(): void
    {
        $this->docs['documentTemplates']['SCH1_TPL0007']['publishedVersion'] = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/never been published/');
        $this->svc->activate('SCH1_TPL0007');
    }

    /**
     * Refusing beats degrading. A non-transactional activate looks identical
     * when it works and produces two active templates when it races — silent,
     * rare, and legally consequential.
     */
    public function test_activate_refuses_to_run_without_a_transaction(): void
    {
        $svc = $this->make(false);
        $svc->publish('SCH1_TPL0007', $this->proof());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a transaction/');
        $svc->activate('SCH1_TPL0007');
    }

    /** If a past bug left two active, activate heals rather than assuming one. */
    public function test_activate_displaces_every_incumbent_not_just_the_first(): void
    {
        $this->docs['documentTemplates']['SCH1_TPL0010'] = [
            'schoolId' => 'SCH1', 'docType' => 'transfer_certificate',
            'status' => 'draft', 'version' => 1, 'publishedVersion' => 1,
            'activeVersion' => 1, 'lockVersion' => 1,
        ];
        $this->svc->publish('SCH1_TPL0007', $this->proof());
        $r = $this->svc->activate('SCH1_TPL0007');

        $this->assertCount(2, $r['displaced']);
        $active = array_filter($this->docs['documentTemplates'], fn($t) => ($t['activeVersion'] ?? null) !== null);
        $this->assertCount(1, $active);
    }

    /* ---------------------------------------------------------------- *
     * Archive + P6.7 audit
     * ---------------------------------------------------------------- */

    public function test_archiving_clears_active_so_no_print_point_resolves_it(): void
    {
        $this->svc->publish('SCH1_TPL0007', $this->proof());
        $this->svc->activate('SCH1_TPL0007');
        $this->svc->archive('SCH1_TPL0007');

        $h = $this->docs['documentTemplates']['SCH1_TPL0007'];
        $this->assertSame('archived', $h['status']);
        $this->assertNull($h['activeVersion']);
    }

    public function test_every_lifecycle_action_is_audited(): void
    {
        $this->svc->publish('SCH1_TPL0007', $this->proof());
        $this->svc->activate('SCH1_TPL0007');
        $this->svc->archive('SCH1_TPL0007');

        $actions = array_column($this->audit, 0);
        $this->assertSame(['publish', 'activate', 'archive'], $actions);
        foreach ($this->audit as [$a, $entity, $desc]) {
            $this->assertSame('SCH1_TPL0007', $entity);
            $this->assertNotSame('', $desc, "the '$a' event must say what happened");
        }
    }
}
