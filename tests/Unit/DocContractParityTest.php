<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Certificate Designer — CLIENT/SERVER MERGE-FIELD CONTRACT PARITY
 *
 * `assets/js/doctemplates/designer.js` validates a template against its
 * contract at DESIGN time. `application/config/doc_types.php` is what the
 * serializer and every print point resolve against at ISSUE time. They are two
 * copies of one contract.
 *
 * THE FAILURE THIS EXISTS TO CATCH, which produces no error on either side:
 * a template is designed and published against the client's list, then rendered
 * against a server list that has drifted. The document comes out with a
 * statutory field missing, stale, or bound to a key nothing resolves — and
 * nothing throws, because each side is internally consistent with itself. It
 * surfaces as a wrong certificate in a parent's hand, weeks later.
 *
 * This is the cross-system-contract class described in the repo CLAUDE.md:
 * "the seams where a one-sided change fails SILENTLY."
 *
 * The test parses the JS rather than trusting a comment, so it fails on the
 * next edit to either file — not on the next time someone remembers to look.
 *
 * Scope limit, stated so a green run is not over-read: this asserts the two
 * declarations AGREE. It does not assert either is legally correct. The CBSE
 * requiredKeys list is still illustrative until gate 0.3 transcription and 0.8
 * sign-off (EXECUTION_PLAN_v1.1 §2).
 *
 * EXECUTION_PLAN_v1.1 P1.8
 */
class DocContractParityTest extends TestCase
{
    private static string $js;
    /** @var array<string,mixed> */
    private static array $cfg;

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../..';

        $js = @file_get_contents($root . '/assets/js/doctemplates/designer.js');
        self::assertNotFalse($js, 'designer.js is unreadable — the client contract cannot be parsed');
        self::$js = $js;

        // The config file is a plain CI3 $config[...] script with a BASEPATH guard.
        $path = $root . '/application/config/doc_types.php';
        self::assertFileExists($path, 'application/config/doc_types.php is missing (P1.8)');
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        $config = [];
        require $path;
        self::$cfg = $config;
    }

    /* ------------------------------------------------------------------ *
     * Parsers — deliberately strict. A parse that silently returns an
     * empty set would make every assertion below pass vacuously, which is
     * the "lint reporting 0 violations" failure. Each parser asserts it
     * found something before returning.
     * ------------------------------------------------------------------ */

    /** @return list<string> keys of the client CONTRACT array, in order */
    private function clientFieldKeys(): array
    {
        $i = strpos(self::$js, 'const CONTRACT = [');
        $this->assertNotFalse($i, 'CONTRACT array not found in designer.js');
        $j = strpos(self::$js, "\n];", $i);
        $this->assertNotFalse($j, 'CONTRACT array is unterminated in designer.js');

        preg_match_all('/\{key:"([^"]+)"/', substr(self::$js, $i, $j - $i), $m);
        $keys = $m[1];
        $this->assertNotEmpty($keys, 'parsed zero fields from CONTRACT — the parser is wrong, not the file');
        return $keys;
    }

    /** @return array<string,list<string>> docType => declared keys */
    private function clientContracts(): array
    {
        $i = strpos(self::$js, 'const CONTRACTS = {');
        $this->assertNotFalse($i, 'CONTRACTS map not found in designer.js');
        $j = strpos(self::$js, "\n};", $i);
        $this->assertNotFalse($j, 'CONTRACTS map is unterminated in designer.js');
        $blk = substr(self::$js, $i, $j - $i);

        // Each entry is  name:[ "a","b", ... ]  possibly spanning lines.
        preg_match_all('/(\w+)\s*:\s*\[(.*?)\]/s', $blk, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $entry) {
            preg_match_all('/"([^"]+)"/', $entry[2], $k);
            $out[$entry[1]] = $k[1];
        }
        $this->assertNotEmpty($out, 'parsed zero contracts from CONTRACTS — the parser is wrong, not the file');
        return $out;
    }

    /** @return array<string,array{disabled:bool,requiresState:?string}> */
    private function clientTypes(): array
    {
        $i = strpos(self::$js, 'const TYPES = [');
        $this->assertNotFalse($i, 'TYPES array not found in designer.js');
        $j = strpos(self::$js, "\n];", $i);
        $this->assertNotFalse($j, 'TYPES array is unterminated in designer.js');
        $blk = substr(self::$js, $i, $j - $i);

        preg_match_all('/\{id:"([^"]+)"(.*?)\}/s', $blk, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $t) {
            preg_match('/requiresState:"([^"]+)"/', $t[2], $rs);
            $out[$t[1]] = [
                'disabled'      => (bool) preg_match('/disabled:\s*true/', $t[2]),
                'requiresState' => $rs[1] ?? null,
            ];
        }
        $this->assertNotEmpty($out, 'parsed zero types from TYPES — the parser is wrong, not the file');
        return $out;
    }

    /* ------------------------------------------------------------------ *
     * The parity assertions
     * ------------------------------------------------------------------ */

    /** The universe of bindable keys must be identical on both sides. */
    public function test_merge_field_key_sets_are_identical(): void
    {
        $client = $this->clientFieldKeys();
        $server = array_keys(self::$cfg['doc_merge_fields']);

        sort($client);
        sort($server);

        $this->assertSame(
            $client,
            $server,
            "Merge-field key sets have drifted.\n"
            . 'Only in designer.js: ' . implode(', ', array_diff($client, $server)) . "\n"
            . 'Only in doc_types.php: ' . implode(', ', array_diff($server, $client))
        );
    }

    /**
     * maxLen must agree too, not just the key set.
     *
     * The client shows a capacity hint from maxLen (P4.5) while the server uses
     * it for the over-length warning (P2.6). If the two drift, the designer
     * tells a clerk "≈400 characters fit" for a field the server measures
     * against a different ceiling — advice that is confidently wrong.
     */
    public function test_maxlen_agrees_between_client_and_server(): void
    {
        preg_match_all(
            '/\{key:"([\w.]+)"(?:(?!\}).)*?maxLen:(\d+)/s',
            self::$js,
            $m,
            PREG_SET_ORDER
        );
        $client = [];
        foreach ($m as $row) {
            $client[$row[1]] = (int) $row[2];
        }
        $this->assertNotEmpty($client, 'parsed zero maxLen values from designer.js — parser is wrong');

        $server = [];
        foreach (self::$cfg['doc_merge_fields'] as $k => $f) {
            if (isset($f['maxLen'])) {
                $server[$k] = (int) $f['maxLen'];
            }
        }

        ksort($client);
        ksort($server);
        $this->assertSame(
            $server,
            $client,
            "maxLen has drifted between designer.js and doc_types.php.\n"
            . 'Only in one side: ' . json_encode(array_merge(
                array_diff_assoc($server, $client), array_diff_assoc($client, $server)
            ))
        );
    }

    /** Same document types, and the same ones disabled / state-gated. */
    public function test_document_type_sets_and_gating_match(): void
    {
        $client = $this->clientTypes();
        $server = self::$cfg['doc_types'];

        $ck = array_keys($client);
        $sk = array_keys($server);
        sort($ck);
        sort($sk);
        $this->assertSame($ck, $sk, 'Document type sets have drifted between designer.js and doc_types.php');

        foreach ($client as $id => $c) {
            $this->assertSame(
                $c['disabled'],
                (bool) ($server[$id]['disabled'] ?? false),
                "Type '$id' disagrees on `disabled` — one side would offer a type the other cannot build"
            );
            $this->assertSame(
                $c['requiresState'],
                $server[$id]['requiresState'] ?? null,
                "Type '$id' disagrees on `requiresState` — a state-gated document would be offered to the wrong schools"
            );
        }
    }

    /** Per-type contracts must match key for key, including order. */
    public function test_per_type_contracts_are_identical(): void
    {
        $client = $this->clientContracts();
        $server = self::$cfg['doc_contracts'];

        $ck = array_keys($client);
        $sk = array_keys($server);
        sort($ck);
        sort($sk);
        $this->assertSame($ck, $sk, 'The set of types carrying a contract has drifted');

        foreach ($client as $type => $keys) {
            $this->assertSame(
                $keys,
                $server[$type],
                "Contract for '$type' has drifted.\n"
                . 'Only in designer.js: ' . implode(', ', array_diff($keys, $server[$type])) . "\n"
                . 'Only in doc_types.php: ' . implode(', ', array_diff($server[$type], $keys))
            );
        }
    }

    /**
     * Every key any contract declares must exist in the field universe.
     *
     * This is the `offContract` defect from the other direction: a contract
     * naming a key nothing defines resolves to nothing at issue time, and the
     * field simply does not appear on the certificate.
     */
    public function test_no_contract_declares_an_undefined_field(): void
    {
        $fields = array_keys(self::$cfg['doc_merge_fields']);
        foreach (self::$cfg['doc_contracts'] as $type => $keys) {
            foreach ($keys as $k) {
                $this->assertContains(
                    $k,
                    $fields,
                    "Contract '$type' declares '$k', which no merge field defines"
                );
            }
        }
    }

    /** Every declared type is either disabled or has a contract — no silent gaps. */
    public function test_every_enabled_type_has_a_contract(): void
    {
        foreach (self::$cfg['doc_types'] as $id => $t) {
            if (!empty($t['disabled'])) {
                continue;
            }
            $this->assertArrayHasKey(
                $id,
                self::$cfg['doc_contracts'],
                "Type '$id' is enabled but declares no contract — its field picker would be unscoped"
            );
        }
    }

    /**
     * maxLen must accommodate the worst realistic value we hold.
     *
     * A maxLen below its own p95 sample guarantees the overflow gate fires on
     * ordinary data, which trains people to ignore it.
     */
    public function test_maxlen_accommodates_the_p95_sample(): void
    {
        foreach (self::$cfg['doc_merge_fields'] as $key => $f) {
            if (!isset($f['maxLen'])) {
                $this->assertContains(
                    $f['type'] ?? 'text',
                    ['image', 'flag'],
                    "Field '$key' has no maxLen and is not an image or flag"
                );
                continue;
            }
            $worst = $f['p95'] ?? $f['sample'];
            $this->assertLessThanOrEqual(
                $f['maxLen'],
                mb_strlen($worst),
                "Field '$key' has maxLen {$f['maxLen']} but its own worst-case sample is "
                . mb_strlen($worst) . ' characters'
            );
        }
    }

    /** Non-text fields must not carry a length constraint that cannot apply. */
    public function test_image_and_flag_fields_declare_no_maxlen(): void
    {
        foreach (self::$cfg['doc_merge_fields'] as $key => $f) {
            if (in_array($f['type'] ?? 'text', ['image', 'flag'], true)) {
                $this->assertArrayNotHasKey(
                    'maxLen',
                    $f,
                    "Field '$key' is an image/flag — a character limit is meaningless on it"
                );
            }
        }
    }
}
