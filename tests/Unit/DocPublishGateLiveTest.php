<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The publish gate must be capable of firing.
 *
 * Both halves of it were dead:
 *
 *   - `_boundKeys()` read `$run['field']`, a key the designer has never
 *     written — designer.js emits `f:` 70 times and `field:` not once — so
 *     `$bound` came back empty for every template.
 *   - The required-field loop iterated `keysFor()`, which returns a LIST, as
 *     though it were a map: `$key` was 0, 1, 2… and `$def` was the key string,
 *     so the guard read `!empty('school.name'['required'])`, false on every
 *     iteration. The `required` flag it tested appears ZERO times in
 *     doc_types.php — it never existed.
 *
 * Together those meant the endpoint documented as the one publish is gated on
 * enforced a line-height rule and nothing else.
 */
final class DocPublishGateLiveTest extends TestCase
{
    private static string $ctl;
    private static string $cfg;

    public static function setUpBeforeClass(): void
    {
        $base = __DIR__ . '/../../application/';
        self::$ctl = (string) file_get_contents($base . 'controllers/Doc_templates.php');
        self::$cfg = (string) file_get_contents($base . 'config/doc_types.php');
    }

    public function test_bound_keys_reads_the_key_the_designer_actually_writes(): void
    {
        $at  = strpos(self::$ctl, 'private function _boundKeys');
        $php = substr(self::$ctl, $at, 1400);

        $this->assertStringContainsString("\$run['f']", $php,
            'The designer persists runs under `f`; reading only `field` returns an empty '
            . 'bound-key set and silently disables every check built on it.');
    }

    public function test_the_required_loop_iterates_the_contract_as_a_map_of_definitions(): void
    {
        $at  = strpos(self::$ctl, 'public function validate');
        $php = substr(self::$ctl, $at, 2600);

        $this->assertStringContainsString('$this->_contract()->get($docType)', $php,
            'get() returns key => definition. keysFor() returns a LIST, and iterating a '
            . 'list as a map makes the guard unreachable.');
        $this->assertStringNotContainsString("!empty(\$def['required'])", $php,
            "There is no `required` flag in doc_types.php — testing one guarantees the "
            . 'check never fires. The contract list IS the required set.');
    }

    /** The premise of the fix: no field definition carries a `required` flag. */
    public function test_no_field_definition_declares_required(): void
    {
        $this->assertSame(0, substr_count(self::$cfg, "'required'"),
            "A `required` flag now exists in the config, so the gate's assumption that "
            . 'every contract key is required needs revisiting.');
    }

    /**
     * validateBundle() is the second reader of the same contract, and it already
     * treats every key as required. The gate must agree with it.
     */
    public function test_the_gate_agrees_with_validate_bundle(): void
    {
        $lib = (string) file_get_contents(__DIR__ . '/../../application/libraries/Doc_contract.php');
        $this->assertStringContainsString('$required = $boundKeys === null ? array_keys($contract)', $lib,
            'validateBundle no longer treats the whole contract as required, so the two '
            . 'readers of this contract have diverged.');
    }
}
