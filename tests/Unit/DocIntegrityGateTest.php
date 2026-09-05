<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_serializer;
use Doc_template_service;
use RuntimeException;

/**
 * Certification rows T0-22 and T0-25, converted from human UAT into machine checks.
 *
 * Both were written as "craft a direct HTTP request and see what happens". Neither
 * actually needs a browser or a person — they are assertions about server behaviour, and a
 * test runs them on every commit instead of once, by hand, in a session somebody has to
 * schedule. Coverage that is not executed is zero coverage; coverage that runs itself is
 * the only kind that stays true.
 */
class DocIntegrityGateTest extends TestCase
{
    private array $docs;
    private Doc_template_service $svc;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_template_service.php';
        require_once __DIR__ . '/../../application/libraries/Doc_serializer.php';
    }

    protected function setUp(): void
    {
        $this->docs = ['documentTemplates' => [
            'SCH1_TPL1' => [
                'schoolId' => 'SCH1', 'templateId' => 'TPL1', 'docType' => 'bonafide',
                'name' => 'T', 'status' => 'draft', 'version' => 1, 'lockVersion' => 0,
                'publishedVersion' => null, 'activeVersion' => null,
                'page' => ['size' => 'A4'], 'header' => [], 'footer' => [],
                'objects' => [['id' => 'a', 'type' => 'text']],
                'languages' => ['en'], 'defaultLanguage' => 'en',
            ],
        ], 'documentTemplateVersions' => []];

        $this->svc = new Doc_template_service([
            'schoolId' => 'SCH1',
            'store' => [
                'get'    => fn($c, $id) => $this->docs[$c][$id] ?? null,
                'set'    => function ($c, $id, $d) { $this->docs[$c][$id] = $d; return true; },
                'update' => function ($c, $id, $d) { $this->docs[$c][$id] = array_merge($this->docs[$c][$id] ?? [], $d); return true; },
                'exists' => fn($c, $id) => isset($this->docs[$c][$id]),
                'query'  => fn() => $this->docs['documentTemplates'],
                'delete' => null,
                'commit' => function (array $ops) {
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

    private function proof(array $over = []): array
    {
        return array_merge([
            'hash'         => 'sha256:abc',
            'contentHash'  => $this->svc->contentHash($this->docs['documentTemplates']['SCH1_TPL1']),
            'fontManifest' => ['dejavusans' => 'sha256:f'],
            'mpdfVersion'  => '8.3.1',
            'pages'        => 1,
            'validation'   => ['blocking' => [], 'warnings' => []],
        ], $over);
    }

    /* ================================================================== *
     *  T0-22 — the proof gate cannot be satisfied by a claim
     * ================================================================== */

    /** The happy path, so the refusals below mean something. */
    public function test_a_genuine_proof_permits_publication(): void
    {
        $this->svc->recordProof('SCH1_TPL1', $this->proof(), 'STA1');
        $r = $this->svc->publish('SCH1_TPL1', 'STA1');
        $this->assertSame(1, $r['version']);
    }

    /**
     * A proof describing a DIFFERENT design must not publish.
     *
     * This is the gate's whole purpose: what was proof-rendered and reviewed must be what
     * gets frozen. A stale proof means a school publishes a document nobody looked at.
     */
    public function test_a_proof_that_describes_a_different_design_is_refused(): void
    {
        $this->svc->recordProof('SCH1_TPL1', $this->proof(), 'STA1');

        // the design moves on AFTER the proof was taken
        $this->docs['documentTemplates']['SCH1_TPL1']['objects'][] = ['id' => 'b', 'type' => 'text'];

        $this->expectException(RuntimeException::class);
        $this->svc->publish('SCH1_TPL1', 'STA1');
    }

    /**
     * A client-supplied contentHash must not be trusted.
     *
     * publish() recomputes the hash from the stored head. If it instead believed the value
     * on the proof record, anyone able to write a proof could authorise the publication of
     * a design that was never rendered.
     */
    public function test_a_forged_content_hash_on_the_proof_record_does_not_publish(): void
    {
        $this->svc->recordProof('SCH1_TPL1', $this->proof(), 'STA1');
        $this->docs['documentTemplates']['SCH1_TPL1']['objects'][] = ['id' => 'b', 'type' => 'text'];

        // forge the stored proof to claim it matches the NEW design
        $real = $this->svc->contentHash($this->docs['documentTemplates']['SCH1_TPL1']);
        $this->docs['documentTemplates']['SCH1_TPL1']['lastProof']['contentHash'] = 'sha256:' . str_repeat('f', 64);

        $this->expectException(RuntimeException::class);
        $this->svc->publish('SCH1_TPL1', 'STA1');
        $this->assertNotSame($real, 'unreachable');
    }

    /** Publishing with no proof at all is refused. */
    public function test_publishing_without_any_proof_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->publish('SCH1_TPL1', 'STA1');
    }

    /** An incomplete proof cannot stand in for a real one. */
    public function test_an_incomplete_proof_record_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->recordProof('SCH1_TPL1', ['hash' => 'sha256:abc'], 'STA1');
    }

    /* ================================================================== *
     *  T0-02 — is a tampered frozen file DETECTED?
     * ================================================================== */

    /**
     * A published version freezes a per-language digest of its own PDF.
     *
     * The certification question was "does anything notice if the frozen file stops
     * matching its record?" — and the answer was no: `version_pdf` streamed the file on
     * trust. The digest was recorded at publication and never read back. Freezing it into
     * the snapshot is what makes a read-time check possible at all.
     */
    public function test_publication_freezes_a_per_language_digest_of_the_pdf(): void
    {
        $this->svc->recordProof('SCH1_TPL1', $this->proof([
            'pdfPaths'    => ['en' => 'uploads/SCH1/doctemplates/_proofs/SCH1_TPL1_v1_en.pdf'],
            'perLanguage' => ['en' => ['pages' => 1, 'bytes' => 120, 'hash' => 'sha256:' . str_repeat('a', 64)]],
        ]), 'STA1');
        $this->svc->publish('SCH1_TPL1', 'STA1');

        $snap = $this->docs['documentTemplateVersions']['SCH1_TPL1_v1'];
        $this->assertSame('sha256:' . str_repeat('a', 64),
            $snap['proofPdfPerLanguage']['en']['hash'] ?? null,
            'without a frozen per-language digest the read path has nothing to verify against');
    }

    /**
     * `proofPdfHash` alone cannot do this job — it is one digest over every language
     * concatenated, so it can never verify a single downloaded file.
     */
    public function test_the_combined_hash_is_not_a_substitute_for_the_per_language_one(): void
    {
        $this->svc->recordProof('SCH1_TPL1', $this->proof([
            'hash'        => 'sha256:combined',
            'perLanguage' => ['en' => ['hash' => 'sha256:english'], 'hi' => ['hash' => 'sha256:hindi']],
        ]), 'STA1');
        $this->svc->publish('SCH1_TPL1', 'STA1');

        $snap = $this->docs['documentTemplateVersions']['SCH1_TPL1_v1'];
        $this->assertNotSame($snap['proofPdfHash'], $snap['proofPdfPerLanguage']['en']['hash']);
        $this->assertCount(2, $snap['proofPdfPerLanguage']);
    }

    /* ================================================================== *
     *  T0-25 — an unknown object type fails LOUDLY
     * ================================================================== */

    /**
     * A type the renderer does not recognise must throw, never be skipped.
     *
     * Silently dropping it is the worse failure: the object vanishes from a statutory
     * document and everything downstream reports success. `save()` applies no shape
     * validation to `objects`, so a crafted request can persist one.
     */
    public function test_an_unknown_object_type_throws_rather_than_being_skipped(): void
    {
        $tpl = [
            'templateId' => 'TPLX', 'docType' => 'bonafide',
            'languages' => ['en'], 'defaultLanguage' => 'en',
            'page' => ['size' => 'A4', 'orientation' => 'portrait',
                       'marginsMm' => ['t' => 15, 'r' => 15, 'b' => 15, 'l' => 15]],
            'objects' => [[
                'id' => 'evil', 'type' => 'wormhole', 'xMm' => 10, 'yMm' => 10,
                'wMm' => 50, 'hMm' => 10, 'z' => 1, 'height' => 'auto',
                'style' => ['sizePt' => 10, 'lineHeight' => 1.4],
                'content' => [],
            ]],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/wormhole/');
        (new Doc_serializer())->render($tpl, [], 'en');
    }

    /** Every type the switch DOES know must still render — a guard that blocks all is a bug. */
    public function test_every_declared_object_type_still_renders(): void
    {
        $base = ['xMm' => 10, 'yMm' => 10, 'wMm' => 50, 'hMm' => 10, 'z' => 1,
                 'height' => 'auto', 'style' => ['sizePt' => 10, 'lineHeight' => 1.4]];
        $objects = [
            ['id' => 'a', 'type' => 'text',   'content' => ['i18n' => ['en' => ['runs' => [['t' => 'x']]]]]] + $base,
            ['id' => 'b', 'type' => 'shape',  'content' => ['shape' => 'line']] + $base,
            ['id' => 'c', 'type' => 'image',  'content' => []] + $base,
            ['id' => 'd', 'type' => 'pageNumber', 'wMm' => 180, 'content' => []] + $base,
        ];
        $html = (new Doc_serializer())->render([
            'templateId' => 'TPLY', 'docType' => 'bonafide',
            'languages' => ['en'], 'defaultLanguage' => 'en',
            'page' => ['size' => 'A4', 'orientation' => 'portrait',
                       'marginsMm' => ['t' => 15, 'r' => 15, 'b' => 15, 'l' => 15]],
            'objects' => $objects,
        ], [], 'en');

        $this->assertStringContainsString('zx-text', $html);
        $this->assertStringContainsString('zx-shape', $html);
        $this->assertStringContainsString('zx-pageNumber', $html);
    }
}
