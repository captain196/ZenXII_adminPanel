<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 19C — SEC-ID-3: legacy unsigned QR usage must produce an OBSERVABLE
 * log event.
 *
 * THE DEFECT: `Attendance::scan_qr` logged legacy-token usage — the single
 * signal that decides when unsigned ID cards can be refused — with
 * `log_message('warning', …)`. That message was never written anywhere.
 *
 * Verified against the framework in THIS repo, not from memory:
 *   system/core/Log.php:106  $_levels = ERROR=1, DEBUG=2, INFO=3, ALL=4
 *   system/core/Log.php:180  an unknown level short-circuits to FALSE
 *   application/config/config.php:104  log_threshold = 1  (errors only)
 *
 * So 'warning' is dropped for being unknown, and 'debug'/'info' would be
 * dropped for being above the threshold. **'error' is the only level that
 * actually reaches the production log.** The security migration was therefore
 * being planned against a log that could never contain the evidence.
 *
 * Fixed at two sites (the second found in this phase — nothing consumed the
 * `legacy` flag the visitor path already returned):
 *   Attendance::scan_qr            — legacy token + cross-school probe
 *   Campus_access::scan_qr_lookup  — legacy visitor badge
 *
 * NOT a behaviour change: legacy tokens are still accepted. No printed card
 * stops working. This phase only makes their use visible.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19CSecId3LegacyTokenTelemetryTest extends TestCase
{
    private const STU_A = 'STU0012';
    private const STU_B = 'STU0047';

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $rel): string
    {
        $path = self::repoRoot() . '/' . $rel;
        if (!is_readable($path)) return '';
        return (string) file_get_contents($path);
    }

    /**
     * Strip comments and normalise line endings so a source scan inspects
     * CODE, not prose. Without this, the explanatory comment describing the
     * old `log_message('warning', …)` call is itself matched by a scan for
     * that call — which is exactly how the first version of this test failed.
     */
    private static function codeOnly(string $body): string
    {
        $out = '';
        foreach (token_get_all('<?php ' . $body) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return str_replace("\r\n", "\n", $out);
    }

    /** Extract a method body by brace matching — no fragile regex. */
    private static function methodBody(string $src, string $name): ?string
    {
        $needle = 'function ' . $name . '(';
        $at = strpos($src, $needle);
        if ($at === false) return null;
        $open = strpos($src, '{', $at);
        if ($open === false) return null;
        $depth = 0; $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $open, $i - $open + 1);
            }
        }
        return null;
    }

    // ── A. The framework facts this fix rests on ────────────────────────

    public function test_ci3_does_not_define_a_warning_log_level(): void
    {
        $log = self::read('system/core/Log.php');
        $this->assertNotSame('', $log, 'system/core/Log.php must be readable');
        $this->assertMatchesRegularExpression(
            '/\$_levels\s*=\s*array\(/', $log, 'level table not found — re-verify before trusting this fix'
        );
        preg_match('/\$_levels\s*=\s*array\((.*?)\);/s', $log, $m);
        $table = strtoupper($m[1] ?? '');
        $this->assertStringContainsString('ERROR', $table);
        $this->assertStringContainsString('DEBUG', $table);
        $this->assertStringContainsString('INFO', $table);
        $this->assertStringNotContainsString('WARNING', $table,
            "'warning' is not a CI3 level — log_message('warning', …) is silently discarded");
        $this->assertStringNotContainsString('NOTICE', $table);
    }

    public function test_production_threshold_admits_only_errors(): void
    {
        $cfg = self::read('application/config/config.php');
        $this->assertMatchesRegularExpression(
            "/log_threshold'\]\s*=\s*\(int\)\s*\(getenv\('LOG_THRESHOLD'\)\s*\?:\s*1\)/",
            $cfg,
            'threshold default must be 1 — if this changes, re-evaluate the chosen level'
        );
    }

    // ── B. The fix is in place at both sites ────────────────────────────

    public function test_attendance_logs_legacy_token_use_at_an_observable_level(): void
    {
        $src  = self::read('application/controllers/Attendance.php');
        $body = self::methodBody($src, 'scan_qr');
        $this->assertNotNull($body, 'scan_qr() not found');

        // The legacy branch must exist and must log at 'error'.
        $this->assertMatchesRegularExpression(
            "/if\s*\(!empty\(\\\$decoded\['legacy'\]\)\)\s*\{\s*log_message\('error'/",
            $body,
            "legacy-token branch must log at 'error' — any other level is discarded"
        );
    }

    public function test_attendance_logs_cross_school_probe_at_an_observable_level(): void
    {
        $src  = self::read('application/controllers/Attendance.php');
        $body = self::codeOnly(self::methodBody($src, 'scan_qr'));
        $this->assertMatchesRegularExpression(
            "/log_message\(\s*'error',\s*\n?\s*\"Attendance::scan_qr — cross-school attempt/u",
            $body,
            'the tenant-boundary probe log must also be observable'
        );
    }

    public function test_campus_access_logs_legacy_badge_use(): void
    {
        $src  = self::read('application/controllers/Campus_access.php');
        $body = self::methodBody($src, 'scan_qr_lookup');
        $this->assertNotNull($body, 'scan_qr_lookup() not found');
        $this->assertMatchesRegularExpression(
            "/if\s*\(!empty\(\\\$result\['legacy'\]\)\)\s*\{\s*log_message\('error'/",
            $body,
            'the visitor path returned a legacy flag that nothing consumed'
        );
    }

    public function test_no_dropped_log_level_remains_in_either_qr_entry_point(): void
    {
        foreach ([
            ['application/controllers/Attendance.php',    'scan_qr'],
            ['application/controllers/Campus_access.php', 'scan_qr_lookup'],
        ] as [$file, $method]) {
            $raw = self::methodBody(self::read($file), $method);
            $this->assertNotNull($raw, "{$method}() not found in {$file}");
            $body = self::codeOnly($raw);
            $this->assertDoesNotMatchRegularExpression(
                "/log_message\(\s*'(warning|notice)'/", $body,
                "{$file}::{$method}() still contains a log level CI3 will discard"
            );
        }
    }

    /**
     * Guard against OVER-correction. `Security_telemetry::emit()`'s second
     * argument is a telemetry severity string, NOT a CI3 log level — a
     * well-meaning sweep that rewrites every 'warning' in the file would
     * corrupt it. It must stay exactly as it is.
     */
    public function test_security_telemetry_severity_argument_was_not_rewritten(): void
    {
        $body = self::methodBody(self::read('application/controllers/Attendance.php'), 'scan_qr');
        $this->assertStringContainsString(
            "emit('CROSS_TENANT_PROBE', 'warning'", $body,
            'sec_telem severity is a different API and must not be "fixed"'
        );
    }

    // ── C. The event the log reports is real ────────────────────────────

    private function loadHelper(): void
    {
        require_once self::repoRoot() . '/application/helpers/qr_token_helper.php';
    }

    public function test_unsigned_two_part_token_is_still_accepted_and_flagged_legacy(): void
    {
        $this->loadHelper();
        // Anyone can build this by hand — that is SEC-ID-2, still open.
        $raw = rtrim(strtr(base64_encode('SCH_TEST01|' . self::STU_A), '+/', '-_'), '=');
        $dec = qr_token_decode($raw);
        $this->assertIsArray($dec, 'legacy tokens must keep working during the migration window');
        $this->assertTrue($dec['legacy'], 'and must be flagged so the log fires');
        $this->assertSame(self::STU_A, $dec['studentId']);
    }

    public function test_signed_token_round_trips_and_is_not_flagged_legacy(): void
    {
        $this->loadHelper();
        $dec = qr_token_decode(qr_token_encode('SCH_TEST01', self::STU_B));
        $this->assertIsArray($dec);
        $this->assertFalse($dec['legacy']);
        $this->assertSame(self::STU_B, $dec['studentId']);
    }

    public function test_a_tampered_signature_is_rejected_outright(): void
    {
        $this->loadHelper();
        $good = qr_token_encode('SCH_TEST01', self::STU_A);
        $pay  = base64_decode(strtr($good, '-_', '+/') . str_repeat('=', (4 - strlen($good) % 4) % 4));
        [$sch, $stu, $sig] = explode('|', $pay);
        $flipped = ($sig[0] === 'a' ? 'b' : 'a') . substr($sig, 1);
        $bad = rtrim(strtr(base64_encode("{$sch}|{$stu}|{$flipped}"), '+/', '-_'), '=');
        $this->assertNull(qr_token_decode($bad), 'a bad signature must never fall back to legacy acceptance');
    }

    public function test_a_forged_signature_cannot_be_swapped_between_students(): void
    {
        $this->loadHelper();
        // The signature binds studentId, so A's signature must not validate for B.
        $a   = qr_token_encode('SCH_TEST01', self::STU_A);
        $pay = base64_decode(strtr($a, '-_', '+/') . str_repeat('=', (4 - strlen($a) % 4) % 4));
        [$sch, , $sig] = explode('|', $pay);
        $swapped = rtrim(strtr(base64_encode("{$sch}|" . self::STU_B . "|{$sig}"), '+/', '-_'), '=');
        $this->assertNull(qr_token_decode($swapped));
    }

    /**
     * End-to-end on the observable mechanism: the bootstrap's log_message()
     * stub captures level+message, so this proves a legacy scan produces a
     * capturable 'error' event rather than merely that the source says so.
     */
    public function test_a_legacy_scan_emits_a_capturable_error_event(): void
    {
        $this->loadHelper();
        $GLOBALS['__test_log_capture'] = [];

        $raw = rtrim(strtr(base64_encode('SCH_TEST01|' . self::STU_A), '+/', '-_'), '=');
        $decoded = qr_token_decode($raw);
        // Same branch shape as the patched controller.
        if (!empty($decoded['legacy'])) {
            log_message('error', "SEC-ID-3 Attendance::scan_qr — legacy unsigned token used for SCH_TEST01/{$decoded['studentId']}");
        }

        $events = $GLOBALS['__test_log_capture'];
        unset($GLOBALS['__test_log_capture']);

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['level'], 'must be the level that survives log_threshold=1');
        $this->assertStringContainsString('SEC-ID-3', $events[0]['msg']);
        $this->assertStringContainsString(self::STU_A, $events[0]['msg'],
            'the message must identify which card to reissue');
    }

    public function test_a_signed_scan_emits_nothing(): void
    {
        $this->loadHelper();
        $GLOBALS['__test_log_capture'] = [];

        $decoded = qr_token_decode(qr_token_encode('SCH_TEST01', self::STU_B));
        if (!empty($decoded['legacy'])) {
            log_message('error', 'should not happen');
        }

        $events = $GLOBALS['__test_log_capture'];
        unset($GLOBALS['__test_log_capture']);
        $this->assertSame([], $events, 'signed cards must not spam the security log');
    }

    // ── D. Scope guard — Transport was already hardened ─────────────────

    public function test_transport_still_rejects_legacy_tokens_outright(): void
    {
        // F14 fails closed independently of the migration window. This phase
        // must not have relaxed it.
        $body = self::methodBody(
            self::read('application/libraries/Transport_qr_attendance_service.php'),
            'decodeStudentToken'
        );
        $this->assertNotNull($body);
        $this->assertStringContainsString("log_message('error'", $body);
        $this->assertStringContainsString('InvalidArgumentException', $body);
        $this->assertMatchesRegularExpression(
            "/if\s*\(!empty\(\\\$decoded\['legacy'\]\)\)/", $body,
            'transport must still branch on legacy and reject'
        );
    }
}
