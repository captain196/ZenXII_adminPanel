<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CSRF-D1 — every JSON-bodied Compensation endpoint returned a blank 403.
 *
 * Found by running the real UAT: assigning a salary through the shipped
 * `postJson()` helper failed with CI's "The action you have requested is not
 * allowed." The same request, form-encoded, succeeded — so the server was
 * right and the client transport was wrong.
 *
 * WHY IT COULD NEVER WORK
 *
 *   CI3 verifies CSRF from $_POST alone:
 *
 *       system/core/Security.php, csrf_verify()
 *       $valid = isset($_POST[$token], $_COOKIE[$cookie]) && hash_equals(...)
 *
 *   PHP populates $_POST only for urlencoded/multipart bodies. A
 *   `Content-Type: application/json` request leaves $_POST empty, so the token
 *   cannot arrive — putting it in the query string, as the helper did, changes
 *   nothing.
 *
 * WHAT IT COST
 *
 *   Salary assignment, statutory configuration, salary templates, component
 *   classification, the F&F "Save policy" button and the settlement preview
 *   were all unreachable from the Admin UI. The authorised test tenant's empty
 *   component catalogue and empty structure list are what that looked like from
 *   the outside.
 *
 * THE FIX
 *
 *   Every one of these controllers already declares a named form field for its
 *   payload (`assignment`, `policy`, `config`, `template`, `classification`) —
 *   built for exactly this reason. `postJson()` now uses it, so the token
 *   travels in $_POST beside the payload and CSRF protection stays ON.
 *   Excluding these routes from CSRF instead would have switched protection off
 *   for endpoints that write salary and statutory data.
 */
final class CompensationPostJsonCsrfTest extends TestCase
{
    private static string $view = '';
    private static string $ctrl = '';

    /** endpoint => the form field its controller accepts. */
    private const CONTRACT = [
        'compensation/save_salary_assignment'        => 'assignment',
        'compensation/preview_assignment'            => 'assignment',
        'compensation/save_fnf_policy'               => 'policy',
        'compensation/save_statutory_config'         => 'config',
        'compensation/save_template'                 => 'template',
        'compensation/save_component_classification' => 'classification',
    ];

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$view = (string) file_get_contents($root . 'application/views/compensation/index.php');
        self::$ctrl = (string) file_get_contents($root . 'application/controllers/Compensation.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$view = self::$ctrl = '';
    }

    // ── The client must send something CI3 can actually read ─────────────

    /**
     * THE CENTRAL TEST. A raw JSON body cannot carry the CSRF token, so
     * postJson must route through the FormData transport.
     */
    public function test_postjson_sends_form_data_so_the_csrf_token_reaches_php(): void
    {
        $i = strpos(self::$view, 'function postJson(path, obj)');
        $this->assertNotFalse($i, 'postJson must exist');
        $body = substr(self::$view, (int) $i, 900);

        $this->assertStringContainsString('return post(path, fields);', $body,
            'postJson must delegate to post(), which builds FormData and appends the CSRF '
            . 'token — a JSON body leaves $_POST empty and CI3 rejects it with a blank 403');
    }

    /** Every endpoint the UI posts JSON to must have a mapped field name. */
    public function test_every_json_endpoint_has_a_mapped_form_field(): void
    {
        $i = strpos(self::$view, 'var JSON_FIELD = {');
        $this->assertNotFalse($i, 'the endpoint→field map must exist');
        $map = substr(self::$view, (int) $i, 900);

        foreach (self::CONTRACT as $endpoint => $field) {
            $this->assertStringContainsString("'{$endpoint}'", $map,
                "{$endpoint} is posted as JSON but has no mapped form field — it would 403");
            $this->assertStringContainsString("'{$field}'", $map,
                "{$endpoint} must map to the field its controller reads: {$field}");
        }
    }

    /**
     * The map must agree with the SERVER. A field renamed in the controller
     * without updating the map silently reintroduces the 403.
     */
    public function test_the_mapped_field_is_the_one_each_controller_reads(): void
    {
        foreach (self::CONTRACT as $endpoint => $field) {
            $method = substr($endpoint, strlen('compensation/'));
            $i = strpos(self::$ctrl, "public function {$method}(");
            $this->assertNotFalse($i, "Compensation::{$method}() must exist");

            $body = substr(self::$ctrl, (int) $i, 700);
            $this->assertStringContainsString("\$this->input->post('{$field}')", $body,
                "Compensation::{$method}() does not read a '{$field}' form field, but the "
                . 'client sends one — writer and reader disagree');
        }
    }

    /**
     * CSRF protection must remain ON. The tempting shortcut was to add these
     * routes to csrf_exclude_uris, which would disable it for endpoints that
     * write salary and statutory data.
     */
    public function test_csrf_protection_is_not_switched_off_for_compensation(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../application/config/config.php');

        $this->assertStringContainsString("\$config['csrf_protection']  = TRUE;", $config,
            'global CSRF protection must stay on');

        $i = strpos($config, "\$config['csrf_exclude_uris']");
        $excl = substr($config, (int) $i, 2500);

        $this->assertStringNotContainsString('compensation/', $excl,
            'no compensation route may be CSRF-excluded — these endpoints write salary and '
            . 'statutory data, and the transport fix makes an exclusion unnecessary');
    }

    /** An unmapped endpoint must warn rather than fail silently. */
    public function test_an_unmapped_endpoint_is_reported_not_silently_broken(): void
    {
        $i = strpos(self::$view, 'function postJson(path, obj)');
        $body = substr(self::$view, (int) $i, 1400);

        $this->assertStringContainsString('console.warn', $body,
            'the next endpoint added without a field mapping should say so, not 403 quietly');
    }
}
