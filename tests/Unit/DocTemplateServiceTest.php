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
    private array $commits = [];
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
        $this->commits = [];
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
            /* An atomic-commit double. It applies every op or none, which is
               what `:commit` guarantees, and RECORDS the ops so a test can
               assert the batch was a COMPLETE assignment — that is the
               property activation's safety rests on, and it is checkable here
               even though Firestore's atomicity itself is not. */
            'commit' => $withTransaction ? function (array $ops) {
                $this->commits[] = $ops;
                foreach ($ops as $op) {
                    $c = $op['collection']; $id = $op['docId'];
                    if (($op['precondition']['exists'] ?? null) === true && !isset($this->docs[$c][$id])) {
                        return false;                       // all-or-nothing
                    }
                }
                foreach ($ops as $op) {
                    $c = $op['collection']; $id = $op['docId'];
                    $this->docs[$c][$id] = array_merge($this->docs[$c][$id] ?? [], $op['data']);
                }
                return true;
            } : null,
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

    /**
     * Put a proof ON RECORD the way the server does.
     *
     * publish() no longer accepts a proof argument. It used to, and that made
     * the gate decorative — a caller could hand it any hash. These tests now
     * go through the same door production does.
     */
    private function recordProof(string $id = 'SCH1_TPL0007', array $over = []): array
    {
        return $this->svc->recordProof($id, array_merge($this->proof(), $over), 'STA1');
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
        $this->recordProof('SCH1_TPL0007');
        $r = $this->svc->publish('SCH1_TPL0007', 'STA1');

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
        $this->recordProof('SCH1_TPL0007');
        $this->svc->publish('SCH1_TPL0007', 'STA1');
        $snap = $this->docs['documentTemplateVersions']['SCH1_TPL0007_v3'];

        $this->assertSame('8.3.1', $snap['mpdfVersion']);
        $this->assertArrayHasKey('lohitdeva', $snap['fontManifest']);
        $this->assertSame('sha256:abc', $snap['proofPdfHash']);
    }

    /** @dataProvider missingProofParts */
    public function test_a_proof_without_the_reproducibility_metadata_is_never_recorded(string $missing): void
    {
        $p = $this->proof();
        unset($p[$missing]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/refusing to record a proof without $missing/");
        $this->svc->recordProof('SCH1_TPL0007', $p, 'STA1');
    }

    /* ---------------------------------------------------------------- *
     * P6.2 — the proof is the SERVER'S, not the caller's
     *
     * publish() used to take the proof as an argument, which meant the gate
     * only stopped a client that chose to be stopped: a POST carrying an
     * invented hash published happily, and the immutable snapshot recorded a
     * hash no PDF had ever produced. The snapshot exists to make a
     * byte-identical re-render possible years later; one built on an invented
     * hash cannot be verified against anything.
     * ---------------------------------------------------------------- */

    public function test_publish_refuses_when_no_proof_was_ever_rendered(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no proof on record/');
        $this->svc->publish('SCH1_TPL0007', 'STA1');
    }

    public function test_publish_cannot_be_handed_a_proof_by_its_caller(): void
    {
        $r = new \ReflectionMethod(Doc_template_service::class, 'publish');
        $params = array_map(fn($p) => $p->getName(), $r->getParameters());

        $this->assertSame(['docId', 'by'], $params,
            'publish() must take no proof parameter. If one returns, a caller can '
            . 'publish a snapshot whose hash was never produced by a real render.');
    }

    /**
     * The gate is CONTENT-based, not a flag. A `proofed` boolean cannot tell a
     * stale proof from a fresh one; a hash of what prints can.
     */
    public function test_publish_refuses_after_the_design_changed_under_the_proof(): void
    {
        $this->recordProof('SCH1_TPL0007');
        $this->docs['documentTemplates']['SCH1_TPL0007']['objects'][] = ['id' => 'added-after-the-proof'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/design changed after the proof/');
        $this->svc->publish('SCH1_TPL0007', 'STA1');
    }

    public function test_publish_refuses_a_proof_rendered_for_an_earlier_version(): void
    {
        $this->recordProof('SCH1_TPL0007');
        $this->docs['documentTemplates']['SCH1_TPL0007']['lastProof']['version'] = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/proof on record is for v2 but this draft is v3/');
        $this->svc->publish('SCH1_TPL0007', 'STA1');
    }

    /**
     * Moving status or lockVersion changes no printed pixel. If they counted,
     * every save would invalidate the proof and people would learn to click
     * through the gate — which is how a gate stops being one.
     */
    public function test_the_content_hash_ignores_fields_that_change_nothing_printed(): void
    {
        $head = $this->docs['documentTemplates']['SCH1_TPL0007'];
        $before = $this->svc->contentHash($head);

        $head['lockVersion'] = 999;
        $head['status']      = 'archived';
        $head['updatedAt']   = '2099-01-01T00:00:00Z';

        $this->assertSame($before, $this->svc->contentHash($head));
    }

    public function test_the_content_hash_is_stable_across_key_order_and_float_precision(): void
    {
        $a = ['page' => ['size' => 'A4', 'marginMm' => 12.5],
              'objects' => [['id' => 'x', 'xMm' => 45.5, 'yMm' => 10.0]]];
        // Same design, keys inserted in a different order — PHP preserves
        // insertion order, so an unsorted hash would differ here.
        $b = ['objects' => [['yMm' => 10.0, 'xMm' => 45.5, 'id' => 'x']],
              'page' => ['marginMm' => 12.5, 'size' => 'A4']];

        $this->assertSame($this->svc->contentHash($a), $this->svc->contentHash($b));
        // ...and a real change still moves it, or the hash proves nothing.
        $c = $a; $c['objects'][0]['xMm'] = 45.6;
        $this->assertNotSame($this->svc->contentHash($a), $this->svc->contentHash($c));
    }

    public function test_a_recorded_proof_carries_the_design_it_was_rendered_from(): void
    {
        $rec = $this->recordProof('SCH1_TPL0007');
        $head = $this->docs['documentTemplates']['SCH1_TPL0007'];

        $this->assertSame($this->svc->contentHash($head), $rec['contentHash']);
        $this->assertSame(3, $rec['version']);
        $this->assertSame('STA1', $rec['renderedBy']);
        $this->assertSame($rec, $head['lastProof'], 'the record lands on the head');
    }

    public static function missingProofParts(): array
    {
        return [['hash'], ['fontManifest'], ['mpdfVersion']];
    }

    /** The layers that APPLIED are frozen, not referenced. */
    public function test_publish_freezes_the_compliance_layers(): void
    {
        $this->recordProof('SCH1_TPL0007');
        $this->svc->publish('SCH1_TPL0007');
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
        $this->recordProof('SCH1_TPL0007');
        $this->svc->publish('SCH1_TPL0007');
    }

    /** P6.3 — editing a published template never touches the snapshot. */
    public function test_editing_after_publish_touches_the_head_and_not_the_snapshot(): void
    {
        $this->recordProof('SCH1_TPL0007');
        $this->svc->publish('SCH1_TPL0007');
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
        $r = $this->recordProof('SCH1_TPL0007');
        $this->svc->publish('SCH1_TPL0007');
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
        $this->recordProof('SCH1_TPL0007');
        $this->svc->publish('SCH1_TPL0007');
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
    public function test_activate_refuses_to_run_without_an_atomic_write(): void
    {
        $svc = $this->make(false);
        $this->recordProof('SCH1_TPL0007');
        $svc->publish('SCH1_TPL0007');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires an atomic multi-document write/');
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
        $this->recordProof('SCH1_TPL0007');
        $this->svc->publish('SCH1_TPL0007');
        $r = $this->svc->activate('SCH1_TPL0007');

        $this->assertCount(2, $r['displaced']);
        $active = array_filter($this->docs['documentTemplates'], fn($t) => ($t['activeVersion'] ?? null) !== null);
        $this->assertCount(1, $active);
    }

    /* ---------------------------------------------------------------- *
     * P6.4 / P9.2 — activation is ONE COMPLETE ASSIGNMENT
     *
     * The old implementation ran a closure inside runTransaction() and wrote
     * through the plain, non-transactional helpers — so the writes were never
     * in the transaction. Worse, raw_client() returns a FirestoreRestClient,
     * which has no runTransaction() at all, so every real activate would have
     * fatalled. Neither showed up here, because the double supplied a
     * transaction the production adapter could not.
     *
     * Safety now comes from COMPLETENESS, not from a lock: every batch names
     * the winner and nulls every other template, so two concurrent activates
     * cannot interleave into a half-state.
     * ---------------------------------------------------------------- */

    public function test_activation_is_a_single_atomic_commit(): void
    {
        $this->docs['documentTemplates']['SCH1_TPL0007']['publishedVersion'] = 2;
        $this->svc->activate('SCH1_TPL0007', 'STA1');

        $this->assertCount(1, $this->commits,
            'activation must be ONE all-or-nothing write. Two writes can interleave.');
    }

    public function test_the_batch_names_the_winner_and_nulls_every_incumbent(): void
    {
        $this->docs['documentTemplates']['SCH1_TPL0007']['publishedVersion'] = 2;
        $this->svc->activate('SCH1_TPL0007', 'STA1');

        $ops = $this->commits[0];
        $byId = array_column($ops, null, 'docId');

        $this->assertSame(2, $byId['SCH1_TPL0007']['data']['activeVersion'], 'the winner is set');
        $this->assertNull($byId['SCH1_TPL0009']['data']['activeVersion'], 'the incumbent is nulled');
        $this->assertSame(['exists' => true], $byId['SCH1_TPL0007']['precondition'],
            'never activate a template that has since been deleted');
    }

    /**
     * Whichever of two concurrent activates commits second is the final one,
     * and exactly one template is active either way. Run both orderings.
     */
    public function test_two_concurrent_activates_leave_exactly_one_active(): void
    {
        foreach ([['SCH1_TPL0007', 'SCH1_TPL0009'], ['SCH1_TPL0009', 'SCH1_TPL0007']] as [$first, $second]) {
            $this->setUp();
            $this->docs['documentTemplates']['SCH1_TPL0007']['publishedVersion'] = 2;
            $this->docs['documentTemplates']['SCH1_TPL0009']['publishedVersion'] = 1;

            $this->svc->activate($first, 'STA1');
            $this->svc->activate($second, 'STA2');

            $active = array_keys(array_filter(
                $this->docs['documentTemplates'],
                fn($t) => ($t['docType'] ?? null) === 'transfer_certificate'
                          && ($t['activeVersion'] ?? null) !== null
            ));
            $this->assertSame([$second], $active,
                "after $first then $second, exactly one template is active and it is the last one");
        }
    }

    /** A rejected commit must change NOTHING — the incumbent stays active. */
    public function test_a_rejected_activation_leaves_the_incumbent_alone(): void
    {
        $this->docs['documentTemplates']['SCH1_TPL0009']['activeVersion'] = 1;
        $this->docs['documentTemplates']['SCH1_TPL0007']['publishedVersion'] = 2;
        unset($this->docs['documentTemplates']['SCH1_TPL0007']);   // deleted under us

        try {
            $this->svc->activate('SCH1_TPL0007', 'STA1');
            $this->fail('activating a deleted template should not succeed');
        } catch (RuntimeException $e) {
            // head() refuses first, which is the earlier and better refusal.
        }

        $this->assertSame(1, $this->docs['documentTemplates']['SCH1_TPL0009']['activeVersion'],
            'the previously active template is untouched');
    }

    /* ---------------------------------------------------------------- *
     * Archive + P6.7 audit
     * ---------------------------------------------------------------- */

    public function test_archiving_clears_active_so_no_print_point_resolves_it(): void
    {
        $this->recordProof('SCH1_TPL0007');
        $this->svc->publish('SCH1_TPL0007');
        $this->svc->activate('SCH1_TPL0007');
        $this->svc->archive('SCH1_TPL0007');

        $h = $this->docs['documentTemplates']['SCH1_TPL0007'];
        $this->assertSame('archived', $h['status']);
        $this->assertNull($h['activeVersion']);
    }

    public function test_every_lifecycle_action_is_audited(): void
    {
        $this->recordProof('SCH1_TPL0007');
        $this->svc->publish('SCH1_TPL0007');
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
