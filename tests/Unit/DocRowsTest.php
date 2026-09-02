<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_rows;

/**
 * Doc_rows — the shape the DATABASE actually returns.
 *
 * This class exists because of a defect found on the first ever run against a
 * live session, and the fixture below is a REAL captured response, trimmed —
 * not another guess.
 *
 * `Firestore_service::where()` returns a list of `['id'=>…, 'data'=>…]`
 * envelopes. Every Document Engine consumer was written against a map of
 * `docId => fields`, because that is what the unit-test doubles returned. The
 * doubles had been built to match the assumption instead of the database, so
 * nothing failed until a real query ran — and then it failed SILENTLY:
 * `$row['activeVersion']` is simply absent on an envelope, so `activate()`
 * would never displace an incumbent and `Doc_resolver` would never find an
 * active template. No error, no log, just the wrong answer.
 */
class DocRowsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) { define('BASEPATH', __DIR__); }
        require_once __DIR__ . '/../../application/libraries/Doc_rows.php';
    }

    /** Captured from GET /doc_templates/get_templates on 2026-09-03. */
    private function realResponse(): array
    {
        return [
            ['id' => 'SCH_B56BB9A401_TPL0001', 'data' => [
                'schoolId' => 'SCH_B56BB9A401', 'templateId' => 'TPL0001',
                'docType' => 'transfer_certificate', 'name' => 'Annexure-I form (copy)',
                'status' => 'draft', 'version' => 1, 'lockVersion' => 0,
                'publishedVersion' => null, 'activeVersion' => null,
            ]],
            ['id' => 'SCH_B56BB9A401_TPL0002', 'data' => [
                'schoolId' => 'SCH_B56BB9A401', 'templateId' => 'TPL0002',
                'docType' => 'transfer_certificate', 'status' => 'draft',
                'publishedVersion' => 2, 'activeVersion' => 2,
            ]],
        ];
    }

    public function test_the_envelope_list_becomes_a_map_keyed_by_document_id(): void
    {
        $m = Doc_rows::map($this->realResponse());

        $this->assertSame(
            ['SCH_B56BB9A401_TPL0001', 'SCH_B56BB9A401_TPL0002'],
            array_keys($m),
            'the keys must be document ids — they were 0 and 1 before this fix'
        );
        $this->assertSame('transfer_certificate', $m['SCH_B56BB9A401_TPL0001']['docType']);
    }

    /**
     * The field whose absence was silent. Reading `activeVersion` off an
     * envelope yields null, so activate() displaced nobody and the resolver
     * found no active template — with nothing to indicate either.
     */
    public function test_the_fields_consumers_read_are_reachable_at_the_top_level(): void
    {
        $m = Doc_rows::map($this->realResponse());

        foreach (['schoolId', 'templateId', 'docType', 'status',
                  'publishedVersion', 'activeVersion'] as $f) {
            $this->assertArrayHasKey($f, $m['SCH_B56BB9A401_TPL0002'],
                "consumers read '$f' directly; on a raw envelope it is absent and reads as null");
        }
        $this->assertSame(2, $m['SCH_B56BB9A401_TPL0002']['activeVersion']);
    }

    public function test_the_document_id_is_folded_in_as_underscore_id(): void
    {
        $m = Doc_rows::map($this->realResponse());
        $this->assertSame('SCH_B56BB9A401_TPL0001', $m['SCH_B56BB9A401_TPL0001']['_id']);
    }

    /**
     * Tolerant of the map form too. Store doubles already produce it, and a
     * normaliser that broke the doubles would only move the problem.
     */
    public function test_an_already_mapped_result_passes_through_unharmed(): void
    {
        $m = Doc_rows::map([
            'SCH1_TPL0007' => ['schoolId' => 'SCH1', 'activeVersion' => 3],
        ]);
        $this->assertSame(3, $m['SCH1_TPL0007']['activeVersion']);
        $this->assertSame('SCH1_TPL0007', $m['SCH1_TPL0007']['_id']);
    }

    /** @dataProvider emptyish */
    public function test_nothing_useful_becomes_an_empty_map($raw): void
    {
        $this->assertSame([], Doc_rows::map($raw));
    }

    public static function emptyish(): array
    {
        return [[[]], [null], ['not an array'], [0], [false]];
    }

    public function test_a_row_with_no_identifiable_id_is_dropped_not_keyed_by_position(): void
    {
        $m = Doc_rows::map([['name' => 'orphan with no id']]);
        $this->assertSame([], $m,
            'keying by position is exactly the bug this class exists to prevent');
    }

    /**
     * Structural: every Document Engine store adapter that queries must go
     * through the normaliser. Adding a raw query is how this regresses, and it
     * regresses silently.
     */
    public function test_every_query_adapter_normalises(): void
    {
        $libs = ['Doc_template_service', 'Doc_resolver', 'Doc_compliance'];

        foreach ($libs as $lib) {
            $src = file_get_contents(__DIR__ . "/../../application/libraries/$lib.php");
            /* Match the ADAPTER, not the docblock that also says 'query'=>fn.
               My first version of this check matched the comment and failed
               against correct code. */
            if (!preg_match_all("/'query'\\s*=>\\s*fn\\(string[^\\n]*/", $src, $m)) {
                $this->fail("$lib has no 'query' store adapter to check");
            }
            $this->assertStringContainsString('Doc_rows::map', implode("\n", $m[0]),
                "$lib queries Firestore without normalising the envelope shape. The rows it "
                . 'reads will have no schoolId, no docType and no activeVersion, and nothing '
                . 'will say so.');
        }
    }
}
