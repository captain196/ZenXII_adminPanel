<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_presence;

/**
 * T2-14, T2-17, T2-20, T2-33 — what the system says when something goes wrong, and what it
 * refuses to say.
 *
 * These matter more than they look. A message that is too generous leaks internals or tells
 * an attacker which ids exist in other tenants; one that is too stingy leaves a clerk with
 * "the action could not be completed" and no way forward. This codebase has a catalogued
 * pattern for both directions.
 */
class DocErrorTaxonomyTest extends TestCase
{
    private static string $ctl;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_presence.php';
        self::$ctl = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Doc_templates.php');
    }

    /* ================================================================== *
     *  T2-17 — an unexpected exception must not reach the client
     * ================================================================== */

    /**
     * A PHP TypeError, a driver failure, a bad array key — none of those messages are
     * written for a human and several name internal paths. They are logged and replaced.
     * Domain exceptions are different: they are deliberate sentences a clerk must read.
     */
    public function test_an_unexpected_throwable_is_replaced_with_a_generic_message(): void
    {
        $run = $this->runBody();

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*Throwable\s+\$e\s*\)(?:(?!\}).)*?json_error\(\s*[\'"]/s',
            $run,
            'the Throwable arm must pass a LITERAL message, never $e->getMessage()');
        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*Throwable\s+\$e\s*\)(?:(?!\}).)*?log_message\(/s',
            $run,
            'the real message must still reach the log — silently swallowing it is worse');
    }

    /** The Throwable arm must not hand $e->getMessage() to the client. */
    public function test_the_unexpected_arm_does_not_leak_the_raw_message(): void
    {
        $run = $this->runBody();
        $at  = strpos($run, 'catch (Throwable');
        $this->assertNotFalse($at);
        $tail = substr($run, $at);
        $this->assertDoesNotMatchRegularExpression(
            '/json_error\(\s*\$(msg|e->getMessage\(\))/', $tail,
            'an unexpected exception is leaking its raw message to the client');
    }

    /**
     * Domain exceptions DO reach the client — deliberately.
     * "This template has published version(s)… archive it instead" is the whole value.
     */
    public function test_domain_exceptions_still_reach_the_caller(): void
    {
        $run = $this->runBody();
        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*InvalidArgumentException\s+\$e\s*\)(?:(?!\}).)*?json_error\(\s*\$e->getMessage\(\)/s',
            $run,
            'validation messages must reach the clerk, or the refusal is unactionable');
    }

    /* ================================================================== *
     *  T2-33 — presence is tenant-scoped by construction
     * ================================================================== */

    /**
     * The presence key is built from the SESSION's schoolId, so a caller cannot register
     * against another school's template even by supplying its full id: the foreign prefix
     * is stripped and the caller's own is prepended.
     */
    public function test_a_presence_key_is_always_built_from_the_callers_own_school(): void
    {
        $written = [];
        $p = new Doc_presence(['store' => [
            'set'    => function ($c, $id, $d) use (&$written) { $written[] = $id; return true; },
            'query'  => fn() => [],
            'delete' => fn() => true,
        ]]);

        // a caller in SCH1 naming SCH2's template
        $p->heartbeat('SCH1', 'SCH2_TPL9', 'STA1', 'Someone');

        $this->assertCount(1, $written);

        /* THE SECURITY PROPERTY: the row lands under the CALLER's own school prefix.
           A SCH1 session therefore cannot write into, or read, SCH2's presence namespace —
           SCH2's genuine rows are keyed 'SCH2_TPL9_…' and this one is 'SCH1_SCH2_TPL9_…'.
           No cross-tenant visibility either way. */
        $this->assertStringStartsWith('SCH1_', $written[0],
            'a presence row was written under another school\'s prefix');
        $this->assertStringNotContainsString('SCH1_', substr($written[0], 5),
            'the caller\'s prefix appears twice — the strip logic has changed shape');

        /* NOT asserted, and deliberately so: the foreign id rides along in the key, giving
           'SCH1_SCH2_TPL9_STA1'. That is junk rather than a leak — nothing reads it — but
           it does mean any string passed as templateId creates a row, and templateSessions
           has no cleanup. Recorded in the QA notes rather than silently tolerated. */
    }

    /** The same caller and template always produce the same key — no row explosion. */
    public function test_repeated_heartbeats_reuse_one_row(): void
    {
        $written = [];
        $p = new Doc_presence(['store' => [
            'set'    => function ($c, $id, $d) use (&$written) { $written[] = $id; return true; },
            'query'  => fn() => [], 'delete' => fn() => true,
        ]]);

        for ($i = 0; $i < 5; $i++) {
            $p->heartbeat('SCH1', 'SCH1_TPL1', 'STA1', 'Someone');
        }
        $this->assertCount(5, $written);
        $this->assertCount(1, array_unique($written),
            'each heartbeat wrote a NEW row — a tab left open would grow the collection forever');
    }

    /* ================================================================== *
     *  T2-20 — the upload allow-list is by CONTENT, not by extension
     * ================================================================== */

    public function test_the_asset_allow_list_is_keyed_by_sniffed_mime_not_extension(): void
    {
        $this->assertMatchesRegularExpression('/const ASSET_MIME\s*=\s*\[/', self::$ctl);
        $this->assertStringContainsString('FILEINFO_MIME_TYPE', self::$ctl,
            'the MIME must be sniffed from content — a caller controls the extension and the '
            . 'Content-Type header, but not the bytes');

        // the allow-list itself
        preg_match('/const ASSET_MIME\s*=\s*\[(.*?)\];/s', self::$ctl, $m);
        $list = $m[1] ?? '';
        foreach (['image/png', 'image/jpeg', 'image/webp'] as $ok) {
            $this->assertStringContainsString($ok, $list);
        }
        foreach (['image/svg', 'text/html', 'application/pdf', 'application/octet-stream'] as $no) {
            $this->assertStringNotContainsString($no, $list,
                "'$no' is on the asset allow-list — SVG and HTML carry script, and a PDF is "
                . 'not an image');
        }
    }

    /** Both the sniff and getimagesize must run — either alone is defeatable. */
    public function test_the_upload_takes_a_second_opinion_from_getimagesize(): void
    {
        $at = strpos(self::$ctl, 'public function upload_asset');
        $body = substr(self::$ctl, $at, 3000);
        $this->assertStringContainsString('getimagesize', $body);
        $this->assertStringContainsString('ASSET_MAX_PIXELS', $body,
            'without a pixel cap a 17 KB file decompresses to 549 MB — measured');
    }

    private function runBody(): string
    {
        $at = strpos(self::$ctl, 'private function _run');
        $this->assertNotFalse($at, '_run() not found');
        return substr(self::$ctl, $at, 2200);
    }
}
