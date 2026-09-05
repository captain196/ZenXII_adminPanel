<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_template_service;
use RuntimeException;

/**
 * Certification rows T0-10 and T0-12–T0-15, converted from human UAT to machine checks.
 *
 * They were written as "open two sessions and fire both requests as close to
 * simultaneously as you can" — a test that is hard to schedule, hard to repeat, and
 * impossible to run on every commit. What each of them is really asserting is that a
 * SECOND caller, arriving with state the first has already invalidated, is REFUSED.
 *
 * That is deterministic. The store double below models the losing side of each race
 * exactly as Firestore does: a write whose precondition no longer holds returns false, and
 * the caller must treat that as a conflict rather than a retry. A real two-session run is
 * still worth doing once, to confirm the window exists as modelled — but it is no longer
 * the only evidence, and these run every time anybody changes the file.
 */
class DocConcurrencyTest extends TestCase
{
    private array $docs;
    /** @var list<array> */ private array $commits;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_template_service.php';
    }

    protected function setUp(): void
    {
        $this->commits = [];
        $this->docs = ['documentTemplates' => [
            'SCH1_TPL1' => [
                'schoolId' => 'SCH1', 'templateId' => 'TPL1', 'docType' => 'bonafide',
                'name' => 'A', 'status' => 'draft', 'version' => 3, 'lockVersion' => 7,
                'publishedVersion' => 2, 'activeVersion' => null,
                '__updateTime' => '2026-09-05T10:00:00Z',
                'page' => [], 'header' => [], 'footer' => [], 'objects' => [['id' => 'a']],
                'languages' => ['en'], 'defaultLanguage' => 'en',
            ],
            'SCH1_TPL2' => [
                'schoolId' => 'SCH1', 'templateId' => 'TPL2', 'docType' => 'bonafide',
                'name' => 'B', 'status' => 'draft', 'version' => 1, 'lockVersion' => 0,
                'publishedVersion' => 1, 'activeVersion' => 1,
                'page' => [], 'header' => [], 'footer' => [], 'objects' => [['id' => 'b']],
                'languages' => ['en'], 'defaultLanguage' => 'en',
            ],
            /* A DIFFERENT SCHOOL's template. Present in the store, as it would be in a
               shared collection, so a tenant check has something real to refuse. */
            'SCH2_TPL9' => [
                'schoolId' => 'SCH2', 'templateId' => 'TPL9', 'docType' => 'bonafide',
                'name' => 'Someone else\'s', 'status' => 'draft', 'version' => 1,
                'lockVersion' => 0, 'publishedVersion' => 1, 'activeVersion' => 1,
                'page' => [], 'header' => [], 'footer' => [], 'objects' => [['id' => 'x']],
                'languages' => ['en'], 'defaultLanguage' => 'en',
            ],
        ], 'documentTemplateVersions' => [
            'SCH1_TPL1_v2' => ['version' => 2],
            /* activate() refuses a version whose frozen snapshot is absent — correctly,
               since nothing could be reproduced from it. The fixture has to satisfy that
               guard for the activation tests to reach the behaviour they are about. */
            'SCH1_TPL2_v1' => ['version' => 1],
        ]];
    }

    /**
     * @param bool $preconditionsHold false models the LOSING side of a race: the document
     *        moved between this caller's read and its write, so the database refuses.
     */
    private function svc(string $schoolId = 'SCH1', bool $preconditionsHold = true): Doc_template_service
    {
        return new Doc_template_service([
            'schoolId' => $schoolId,
            'store' => [
                'get'    => fn($c, $id) => $this->docs[$c][$id] ?? null,
                'set'    => function ($c, $id, $d) { $this->docs[$c][$id] = $d; return true; },
                'update' => function ($c, $id, $d) { $this->docs[$c][$id] = array_merge($this->docs[$c][$id] ?? [], $d); return true; },
                'exists' => fn($c, $id) => isset($this->docs[$c][$id]),
                'query'  => fn() => $this->docs['documentTemplates'],
                'delete' => function ($c, $id) { unset($this->docs[$c][$id]); return true; },
                'commit' => function (array $ops) use ($preconditionsHold) {
                    $this->commits[] = $ops;
                    if (!$preconditionsHold) {
                        return false;   // somebody else got there first
                    }
                    foreach ($ops as $op) {
                        if (($op['precondition']['exists'] ?? null) === false
                            && isset($this->docs[$op['collection']][$op['docId']])) {
                            return false;
                        }
                    }
                    foreach ($ops as $op) {
                        $c = $op['collection']; $id = $op['docId'];
                        $this->docs[$c][$id] = !empty($op['merge'])
                            ? array_merge($this->docs[$c][$id] ?? [], $op['data'])
                            : $op['data'];
                    }
                    return true;
                },
            ],
            'audit' => fn() => null,
        ]);
    }

    private function proof(string $id): void
    {
        $svc = $this->svc();
        $svc->recordProof($id, [
            'hash' => 'sha256:a', 'contentHash' => $svc->contentHash($this->docs['documentTemplates'][$id]),
            'fontManifest' => ['x' => 'y'], 'mpdfVersion' => '8.3.1', 'pages' => 1,
            'validation' => ['blocking' => [], 'warnings' => []],
        ], 'STA1');
    }

    /* ================================================================== *
     *  T0-10 — cross-tenant WRITE
     *
     *  The read half was confirmed live against a real second tenant. The write
     *  half could not be: attempting it against another school's live template
     *  risks damaging a tenant that never consented to being tested. Here the
     *  refusal is provable without that risk.
     * ================================================================== */

    /** @dataProvider mutatingCalls */
    public function test_no_mutating_call_can_touch_another_schools_template(string $label, callable $call): void
    {
        $before = $this->docs['documentTemplates']['SCH2_TPL9'];

        try {
            $call($this->svc('SCH1'));
            $this->fail("$label reached another school's template");
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/no template/i', $e->getMessage(),
                "$label failed for the wrong reason — the refusal must be the tenant check");
        }

        $this->assertSame($before, $this->docs['documentTemplates']['SCH2_TPL9'],
            "$label modified another school's template");
    }

    public static function mutatingCalls(): array
    {
        $id = 'SCH2_TPL9';
        return [
            'save'       => ['save',       fn($s) => $s->save($id, ['name' => 'hijacked'], 0)],
            'publish'    => ['publish',    fn($s) => $s->publish($id, 'ATTACKER')],
            'activate'   => ['activate',   fn($s) => $s->activate($id, 'ATTACKER')],
            'deactivate' => ['deactivate', fn($s) => $s->deactivate($id, 'ATTACKER')],
            'archive'    => ['archive',    fn($s) => $s->archive($id, 'ATTACKER')],
            'delete'     => ['delete',     fn($s) => $s->delete($id, 'ATTACKER')],
        ];
    }

    /** The tenant refusal must not reveal that the id exists elsewhere. */
    public function test_a_foreign_id_and_a_missing_id_are_indistinguishable(): void
    {
        $svc = $this->svc('SCH1');
        $foreign = $missing = '';

        try { $svc->save('SCH2_TPL9', ['name' => 'x'], 0); } catch (RuntimeException $e) { $foreign = $e->getMessage(); }
        try { $svc->save('SCH1_TPL_NOPE', ['name' => 'x'], 0); } catch (RuntimeException $e) { $missing = $e->getMessage(); }

        $this->assertNotSame('', $foreign);
        $this->assertSame(
            preg_replace('/SCH\w+/', 'ID', $foreign),
            preg_replace('/SCH\w+/', 'ID', $missing),
            'the message differs, which tells an attacker the id exists in another tenant'
        );
    }

    /* ================================================================== *
     *  T0-11 / T0-12 — the losing side of a save() and create() race
     * ================================================================== */

    public function test_the_loser_of_a_save_race_is_refused_not_silently_dropped(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/E_CONFLICT.*NOT saved/s');
        $this->svc('SCH1', false)->save('SCH1_TPL1', ['name' => 'second writer'], 7);
    }

    public function test_a_lost_save_race_leaves_the_stored_document_untouched(): void
    {
        $before = $this->docs['documentTemplates']['SCH1_TPL1'];
        try { $this->svc('SCH1', false)->save('SCH1_TPL1', ['name' => 'second writer'], 7); }
        catch (RuntimeException $e) { /* expected */ }

        $this->assertSame($before, $this->docs['documentTemplates']['SCH1_TPL1'],
            'the losing writer still modified the document');
    }

    /** A stale lockVersion is refused before any write is attempted at all. */
    public function test_a_stale_lock_version_never_reaches_the_database(): void
    {
        try { $this->svc('SCH1')->save('SCH1_TPL1', ['name' => 'stale'], 6); }
        catch (RuntimeException $e) {
            $this->assertStringContainsString('E_CONFLICT', $e->getMessage());
        }
        $this->assertSame([], $this->commits, 'a stale save reached the database');
    }

    /* ================================================================== *
     *  T0-13 — publishing the same version twice
     * ================================================================== */

    public function test_publishing_the_same_version_twice_is_refused(): void
    {
        $this->proof('SCH1_TPL1');
        $this->svc('SCH1')->publish('SCH1_TPL1', 'STA1');

        $this->expectException(RuntimeException::class);
        $this->svc('SCH1')->publish('SCH1_TPL1', 'STA1');
    }

    /** The frozen snapshot survives a second attempt untouched. */
    public function test_a_second_publish_cannot_overwrite_the_frozen_snapshot(): void
    {
        $this->proof('SCH1_TPL1');
        $this->svc('SCH1')->publish('SCH1_TPL1', 'STA1');
        $frozen = $this->docs['documentTemplateVersions']['SCH1_TPL1_v3'];

        $this->docs['documentTemplates']['SCH1_TPL1']['version'] = 3;   // force a collision
        try { $this->svc('SCH1')->publish('SCH1_TPL1', 'STA1'); } catch (RuntimeException $e) { /* expected */ }

        $this->assertSame($frozen, $this->docs['documentTemplateVersions']['SCH1_TPL1_v3'],
            'a second publish rewrote a frozen version — the record of what was issued');
    }

    /* ================================================================== *
     *  T0-14 — two activations at once
     * ================================================================== */

    public function test_activation_is_one_commit_carrying_the_whole_assignment(): void
    {
        $this->svc('SCH1')->activate('SCH1_TPL2', 'STA1', 1);

        $this->assertCount(1, $this->commits, 'activation split across more than one write');
        $ops = end($this->commits);
        $this->assertNotEmpty($ops);
        foreach ($ops as $op) {
            $this->assertSame('documentTemplates', $op['collection']);
        }
    }

    public function test_a_lost_activation_race_changes_nothing(): void
    {
        $before = $this->docs['documentTemplates'];
        try { $this->svc('SCH1', false)->activate('SCH1_TPL2', 'STA1', 1); }
        catch (RuntimeException $e) { /* expected */ }

        $this->assertSame($before, $this->docs['documentTemplates'],
            'a failed activation left a partial assignment — two active templates, or none');
    }
}
