<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_template_service;

/**
 * The list query must never read `objects`.
 *
 * That field is the largest on a template document and the reason a query for 89 of them
 * transferred 4.96 MB and took long enough to cross PHP's execution ceiling, terminating
 * the request mid-flight. Projecting it away at the DATABASE — not merely trimming the
 * response — took the same query to 93 KB.
 *
 * The thumbnail geometry the list needs is denormalised onto the document at write time so
 * the projection is possible at all. That denormalisation is the fragile part: a copy that
 * can drift from its source is worse than no copy, because the gallery would draw a
 * template that no longer looks like that and nothing would say so. These tests exist to
 * keep the two in step.
 */
class DocProjectionTest extends TestCase
{
    private array $docs;
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
        $this->docs = ['documentTemplates' => [], 'documentTemplateVersions' => []];
        $this->svc = new Doc_template_service([
            'schoolId' => 'SCH1',
            'store' => [
                'get'    => fn($c, $id) => $this->docs[$c][$id] ?? null,
                'set'    => function ($c, $id, $d) { $this->docs[$c][$id] = $d; return true; },
                'update' => function ($c, $id, $d) { $this->docs[$c][$id] = array_merge($this->docs[$c][$id] ?? [], $d); return true; },
                'exists' => fn($c, $id) => isset($this->docs[$c][$id]),
                'query'  => fn() => $this->docs['documentTemplates'],
                'delete' => null, 'commit' => null,
            ],
            'audit' => fn() => null,
        ]);
    }

    private function objects(): array
    {
        return [
            ['id' => 'a', 'type' => 'text',  'xMm' => 10, 'yMm' => 20, 'wMm' => 100, 'hMm' => 8,
             'requiredKey' => 'school.name', 'region' => 'header',
             'content' => ['i18n' => ['en' => ['runs' => [['t' => 'SECRET WORDING']]]]]],
            ['id' => 'b', 'type' => 'shape', 'xMm' => 15, 'yMm' => 40, 'wMm' => 180, 'hMm' => 1,
             'content' => ['shape' => 'seal']],
        ];
    }

    public function test_creating_a_template_denormalises_its_thumbnail_geometry(): void
    {
        $r = $this->svc->create('SCH1', 'bonafide', ['name' => 'T', 'objects' => $this->objects()], 'STA1');
        $shapes = $r['head']['shapes'] ?? null;

        $this->assertIsArray($shapes);
        $this->assertCount(2, $shapes);
        $this->assertSame(10.0, $shapes[0]['x']);
        $this->assertTrue($shapes[0]['r'], 'a required object must be drawn in the statutory colour');
        $this->assertSame('header', $shapes[0]['g']);
        $this->assertTrue($shapes[1]['s'], 'a seal is drawn as a circle');
    }

    /**
     * GEOMETRY ONLY. This field is returned to every list caller, and a screen that draws
     * grey boxes has no business receiving a template's wording.
     */
    public function test_the_thumbnail_geometry_carries_no_content(): void
    {
        $r = $this->svc->create('SCH1', 'bonafide', ['name' => 'T', 'objects' => $this->objects()], 'STA1');
        $json = json_encode($r['head']['shapes']);

        $this->assertStringNotContainsString('SECRET WORDING', $json);
        $this->assertStringNotContainsString('i18n', $json);
        $this->assertStringNotContainsString('school.name', $json,
            'a merge binding leaked into the list payload');
    }

    /** A stale copy is worse than no copy — the two must move together. */
    public function test_editing_the_objects_updates_the_thumbnail_geometry(): void
    {
        $r = $this->svc->create('SCH1', 'bonafide', ['name' => 'T', 'objects' => $this->objects()], 'STA1');
        $id = $r['templateId'];
        $before = $this->docs['documentTemplates'][$id]['shapes'];

        $moved = $this->objects();
        $moved[0]['xMm'] = 55;
        $this->svc->save($id, ['objects' => $moved], $this->docs['documentTemplates'][$id]['lockVersion']);

        $after = $this->docs['documentTemplates'][$id]['shapes'];
        $this->assertNotSame($before, $after, 'the geometry did not follow the edit');
        $this->assertSame(55.0, $after[0]['x']);
    }

    /** Removing an object removes its rectangle. */
    public function test_deleting_an_object_shrinks_the_thumbnail_geometry(): void
    {
        $r = $this->svc->create('SCH1', 'bonafide', ['name' => 'T', 'objects' => $this->objects()], 'STA1');
        $id = $r['templateId'];
        $this->svc->save($id, ['objects' => [$this->objects()[0]]],
                         $this->docs['documentTemplates'][$id]['lockVersion']);

        $this->assertCount(1, $this->docs['documentTemplates'][$id]['shapes']);
    }

    /**
     * The list endpoint must ask for a projection, and that projection must include
     * `shapes` while excluding `objects`.
     */
    public function test_the_list_endpoint_projects_objects_away(): void
    {
        $ctl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/application/controllers/Doc_templates.php');
        $at = strpos($ctl, 'public function get_templates');
        $this->assertNotFalse($at);
        $body = substr($ctl, $at, 2600);

        $this->assertStringContainsString("'shapes'", $body,
            'the projection must include the denormalised geometry, or thumbnails break');
        preg_match('/schoolWhere\((.*?)\)\);/s', $body, $m);
        $call = $m[1] ?? '';
        $this->assertNotSame('', $call, 'the schoolWhere call could not be parsed');
        $this->assertStringNotContainsString("'objects'", $call,
            "the list query is reading `objects` again — that field is why the query took "
            . '15-17s and could terminate the request');
    }

    /** A template saved before the field existed must not break the list. */
    public function test_a_template_with_no_denormalised_geometry_is_tolerated(): void
    {
        $this->docs['documentTemplates']['SCH1_TPLOLD'] = [
            'schoolId' => 'SCH1', 'templateId' => 'TPLOLD', 'docType' => 'bonafide',
            'name' => 'Predates the field', 'status' => 'draft', 'version' => 1,
            'lockVersion' => 0, 'publishedVersion' => null, 'activeVersion' => null,
            'objects' => $this->objects(), 'languages' => ['en'], 'defaultLanguage' => 'en',
        ];
        $this->assertArrayNotHasKey('shapes', $this->docs['documentTemplates']['SCH1_TPLOLD']);

        $ctl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/application/controllers/Doc_templates.php');
        $this->assertStringContainsString("\$t['shapes'] ?? null", $ctl,
            'an older template must yield null, not a warning — the client falls back to '
            . 'the starter outline it drew before any of this existed');
    }
}
