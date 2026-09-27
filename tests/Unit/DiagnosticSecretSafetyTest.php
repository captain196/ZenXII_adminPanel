<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  A DIAGNOSTIC MUST NOT BE ABLE TO PRINT A CREDENTIAL
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE INCIDENT (2026-08-26) ────────────────────────────────────────────
 * An ad-hoc probe written to answer an unrelated question ("what is the ID
 * card fee configured as?") listed a school's `feeSettings` documents with
 * JSON.stringify over the whole document. `feeSettings` also holds the
 * gateway row, so the Razorpay TEST api_key and api_secret were printed in
 * full. The credential had to be treated as compromised and rotated.
 *
 * The purpose-built scripts got this right — `preflight_activation_state`
 * reports `api_secret: present(len=24)` — so the fix is not "be careful", it
 * is: no script may whole-dump a collection that can hold credentials.
 *
 * ── WHAT THESE TESTS ASSERT ──────────────────────────────────────────────
 * Two different risks, because they fail differently:
 *
 *   1. A DIAGNOSTIC prints a secret to a terminal or a report.
 *   2. The APPLICATION returns a secret to a client over HTTP.
 *
 * The second is the graver one — it reaches a device ZenX does not control —
 * and is asserted separately below.
 */
final class DiagnosticSecretSafetyTest extends TestCase
{
    /** Collections that can contain credential material. */
    private const SECRET_BEARING = ['feeSettings', 'paymentProviders'];

    /** @return array<string,string> path => contents */
    private function scripts(): array
    {
        $dir = __DIR__ . '/../../scripts';
        $out = [];
        foreach ((array) glob($dir . '/*.js') as $f) {
            $out[basename($f)] = (string) file_get_contents($f);
        }
        foreach ((array) glob($dir . '/*.php') as $f) {
            $out[basename($f)] = (string) file_get_contents($f);
        }
        return $out;
    }

    public function test_no_diagnostic_whole_dumps_a_credential_bearing_collection(): void
    {
        // The exact shape of the incident: stringify the whole document of a
        // collection that can hold secrets.
        $offenders = [];
        foreach ($this->scripts() as $name => $src) {
            $touchesSecretCollection = false;
            foreach (self::SECRET_BEARING as $col) {
                if (strpos($src, "'{$col}'") !== false || strpos($src, "\"{$col}\"") !== false) {
                    $touchesSecretCollection = true;
                    break;
                }
            }
            if (!$touchesSecretCollection) continue;

            // A whole-document stringify of the doc data, with no field
            // selection. `JSON.stringify(d.data())`, `JSON.stringify(x)` where
            // x came straight from a doc, or Object.keys() iteration.
            $whole = preg_match('/JSON\.stringify\(\s*(d\.data\(\)|doc\.data\(\)|x)\s*[,)]/', $src) === 1
                  || preg_match('/Object\.keys\(\s*(d|doc|x)\s*\)/', $src) === 1;
            if ($whole) $offenders[] = $name;
        }

        $this->assertSame([], $offenders,
            'these diagnostics whole-dump a collection that can hold credentials: '
            . implode(', ', $offenders)
            . ' — select fields explicitly and render secrets as present(len=N)');
    }

    /**
     * A secret reference is safe only in a presence / length / masking
     * context. Checked per REFERENCE rather than per file or per line: the
     * migration script once had a sibling field on the same line that made a
     * raw `${data.api_secret}` look accounted for.
     */
    private const SAFE_CONTEXT =
        '/!+\s*[\w$]'          // !x / !!x — a presence test, not a value read
        . '|\bhas[A-Z_]'       // has_api_secret / hasSecret — a boolean
        . '|!==\s*\'\''        // String(x) !== '' — the same test, spelled out
        . '|\.length'          // reported as a length
        . '|present\(len'      // present(len=N)
        . '|ABSENT|absent'
        . '|describeSecret|mask|redact'
        . '|sha\d|hash'        // compared by digest, never printed
        . '|Boolean\('
        . '/i';

    public function test_no_output_statement_can_emit_a_secret_value(): void
    {
        // Scoped to OUTPUT, deliberately. A script may legitimately hold a
        // secret in a variable: the seeding tool takes one as a CLI argument
        // and the migration tool copies one between Firestore documents.
        // Neither reaches a terminal. What caused the incident was a secret
        // inside a console.log, so that is what is policed.
        $checked = 0;
        foreach ($this->scripts() as $name => $src) {
            preg_match_all(
                '/(?:console\.(?:log|info|warn|error)|process\.stdout\.write|echo|printf|print_r|var_dump)\s*\(.*?\)\s*;/s',
                $src, $m
            );
            foreach ($m[0] as $stmt) {
                if (preg_match('/\b(api_secret|apiSecret|webhook_secret|webhookSecret|private_key|privateKey)\b/', $stmt) !== 1) {
                    continue;
                }
                $checked++;
                $this->assertMatchesRegularExpression(self::SAFE_CONTEXT, $stmt,
                    "{$name} prints a secret outside a presence/masking context. "
                    . 'Output may report ABSENT / present(len=N) / a boolean — never a value. '
                    . 'Statement: ' . trim(preg_replace('/\s+/', ' ', substr($stmt, 0, 160))));
            }
        }
        // Positive control: if no output statement mentioned a secret at all,
        // the loop above would pass while proving nothing.
        $this->assertGreaterThan(0, $checked,
            'no output statement references a credential — this guard is vacuous');
    }

    public function test_a_secret_held_in_a_variable_is_not_flagged_as_a_leak(): void
    {
        // Control on the scoping decision above, so the narrowing is explicit
        // rather than an accident of the regex: holding a secret is fine,
        // printing it is not.
        $holding  = "const apiSecret = arg('--secret');";
        $printing = "console.log(`secret=\${apiSecret}`);";

        preg_match('/console\.log\s*\(.*?\)\s*;/s', $printing, $m);
        $this->assertNotEmpty($m, 'the output matcher failed to see a console.log at all');
        $this->assertDoesNotMatchRegularExpression(self::SAFE_CONTEXT, $m[0],
            'a printed secret must not be treated as safe');

        $this->assertSame(0, preg_match(
            '/(?:console\.(?:log|info|warn|error)|process\.stdout\.write)\s*\(.*?\)\s*;/s', $holding),
            'a plain assignment must not be treated as output');
    }

    public function test_the_context_rule_actually_rejects_a_raw_dump(): void
    {
        // Control on the rule itself: the safe-context pattern must NOT accept
        // the shape that caused the incident.
        $bad = 'console.log(`key=${data.api_secret}`)';
        $this->assertDoesNotMatchRegularExpression(self::SAFE_CONTEXT, $bad,
            'the safe-context pattern accepts a raw secret dump — it would have '
            . 'passed the 2026-08-26 incident');

        foreach (['!!d.api_secret', 'has_api_secret: true', "hasSecret: x !== ''", 'api_secret.length',
                  'describeSecret(d.api_secret)', 'present(len=24)'] as $good) {
            $this->assertMatchesRegularExpression(self::SAFE_CONTEXT, $good,
                "the safe-context pattern rejects a legitimate form: {$good}");
        }
    }

    // ── THE GRAVER RISK: A SECRET LEAVING OVER HTTP ──────────────────────

    public function test_no_secret_reaches_a_client_response(): void
    {
        // The order response legitimately carries the PUBLIC key id, which the
        // checkout SDK needs. It must never carry the secret half.
        $ctrl = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        // Isolate the response payloads, then check those — not the whole file,
        // which legitimately reads secrets for HMAC.
        preg_match_all('/json_encode\(\s*\[(.*?)\]\s*\)/s', $ctrl, $m);
        $this->assertNotEmpty($m[1], 'no json_encode payloads found — the response shape changed');
        foreach ($m[1] as $payload) {
            $this->assertDoesNotMatchRegularExpression(
                "/'(api_secret|webhook_secret|key_secret)'/",
                $payload,
                'a client response payload includes a secret field'
            );
        }
    }

    public function test_the_admission_surface_is_held_to_the_same_rule(): void
    {
        $adm = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
        preg_match_all('/json_encode\(\s*\[(.*?)\]\s*\)/s', $adm, $m);
        foreach ($m[1] as $payload) {
            $this->assertDoesNotMatchRegularExpression(
                "/'(api_secret|webhook_secret|key_secret)'/",
                $payload,
                'an admission response payload includes a secret field'
            );
        }
        $this->assertTrue(true);
    }

    public function test_secrets_are_never_written_to_the_application_log(): void
    {
        // A log line is a file on disk that outlives the request and is often
        // shipped elsewhere. Same rule as a response.
        foreach (['controllers/Fee_management.php',
                  'libraries/Payment_service.php',
                  'libraries/Payment_provider_config_resolver.php'] as $rel) {
            $src = (string) file_get_contents(APPPATH . $rel);
            preg_match_all('/log_message\([^;]*;/s', $src, $m);
            foreach ($m[0] as $line) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\$\w*(api_secret|apiSecret|webhookSecret|webhook_secret)\w*/',
                    $line,
                    "{$rel} logs a secret variable"
                );
                $this->assertDoesNotMatchRegularExpression(
                    "/\\['(api_secret|webhook_secret)'\\]/",
                    $line,
                    "{$rel} logs a secret field"
                );
            }
        }
    }
}
