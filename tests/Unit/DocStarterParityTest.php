<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The generated server-side starter catalogue must equal the client's.
 *
 * The standard templates are AUTHORED in `assets/js/doctemplates/designer.js` and
 * generated into `application/config/doc_starters.php` so a school can be
 * provisioned without a human opening the designer.
 *
 * A generated file is only safe while something proves it was regenerated. This
 * is that proof: it re-runs the generator and diffs. Edit a starter, forget to
 * regenerate, and this fails — instead of two schools quietly receiving two
 * different versions of the same standard certificate.
 *
 * `_patterns.md` names sibling-path parity drift as the highest-leverage defect
 * shape in this codebase, and this module has already produced three instances
 * of it. This test is the reason a fourth copy of the starters is not one more.
 */
class DocStarterParityTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        if (!defined('APPPATH')) {
            define('APPPATH', self::$root . '/application/');
        }
    }

    private function generated(): array
    {
        $config = [];
        $path   = self::$root . '/application/config/doc_starters.php';
        $this->assertFileExists($path,
            'the generated catalogue is missing — run: node tools/gen_doc_starters.js');
        require $path;
        return $config['doc_starters'] ?? [];
    }

    private function regeneratedFromSource(): array
    {
        $tool = self::$root . '/tools/gen_doc_starters.js';
        $this->assertFileExists($tool);

        $json = shell_exec('node ' . escapeshellarg($tool) . ' --stdout 2>&1');
        $out  = json_decode((string) $json, true);
        $this->assertIsArray($out,
            "the generator could not run — node may be unavailable, or designer.js changed shape.\n"
            . substr((string) $json, 0, 400));
        return $out;
    }

    /** The committed file is exactly what the generator produces today. */
    public function test_the_generated_catalogue_matches_the_designer_source(): void
    {
        $this->assertSame(
            $this->regeneratedFromSource(),
            $this->generated(),
            "application/config/doc_starters.php is stale.\n"
            . 'A starter was edited in designer.js without regenerating. Run: '
            . 'node tools/gen_doc_starters.js'
        );
    }

    /** Every starter must actually build something. */
    public function test_no_starter_is_empty(): void
    {
        foreach ($this->generated() as $s) {
            $this->assertNotEmpty($s['id']);
            $this->assertNotEmpty($s['docType']);
            $this->assertNotEmpty($s['template']['objects'] ?? [],
                "starter '{$s['id']}' has no objects — it would seed a blank page");
        }
    }

    /**
     * Every seeded type must be one the contract knows, or the seeded template
     * can never be validated, proofed or published.
     */
    public function test_every_starter_type_is_a_declared_document_type(): void
    {
        $config = [];
        require self::$root . '/application/config/doc_types.php';

        foreach ($this->generated() as $s) {
            $this->assertArrayHasKey($s['docType'], $config['doc_types'],
                "starter '{$s['id']}' builds docType '{$s['docType']}', which doc_types.php does not declare");
            $this->assertArrayHasKey($s['docType'], $config['doc_contracts'],
                "starter '{$s['id']}' builds a type with no contract");
        }
    }

    /**
     * A disabled type must not be seedable. `migration` is declared but not
     * buildable; seeding it would hand schools a document the engine refuses.
     */
    public function test_no_starter_builds_a_disabled_type(): void
    {
        $config = [];
        require self::$root . '/application/config/doc_types.php';

        foreach ($this->generated() as $s) {
            $this->assertEmpty($config['doc_types'][$s['docType']]['disabled'] ?? false,
                "starter '{$s['id']}' builds the disabled type '{$s['docType']}'");
        }
    }

    /**
     * A starter's gate must agree with its type's own `requiresState`.
     *
     * If `doc_types.php` gates a type to Kerala but the starter carries no state
     * gate, the seeder would provision it everywhere and every school outside
     * Kerala would hold a template the engine refuses to let them create.
     */
    public function test_a_state_gated_type_has_a_state_gated_starter(): void
    {
        $config = [];
        require self::$root . '/application/config/doc_types.php';

        foreach ($this->generated() as $s) {
            $needs = $config['doc_types'][$s['docType']]['requiresState'] ?? null;
            if ($needs === null) {
                continue;
            }
            $this->assertNotEmpty($s['states'] ?? null,
                "type '{$s['docType']}' requires state '$needs', but starter '{$s['id']}' "
                . 'carries no state gate — the seeder would provision it everywhere');
            $this->assertContains($needs, $s['states']);
        }
    }
}
