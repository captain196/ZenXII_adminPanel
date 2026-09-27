<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A login failure must say WHICH failure it was.
 *
 * ── THE DEFECT (observed live, 2026-09-01) ──────────────────────────────────
 * Firebase::signInWithEmail() returns null for two unrelated outcomes:
 *
 *   1. Firebase judged the credential  → "INVALID_LOGIN_CREDENTIALS"
 *   2. The request never reached Google → "cURL error 28: ... Timeout was reached"
 *
 * Admin_login collapsed both into one branch, so four consecutive DNS/TLS
 * timeouts to oauth2.googleapis.com were reported to the operator as
 * "Invalid credentials" AND consumed four of their five lockout attempts. The
 * operator spent the evening checking a password that was never wrong, and a
 * flaky link could have locked the account outright.
 *
 * ── WHY THIS IS NOT A WEAKER GATE ───────────────────────────────────────────
 * Nothing is granted on a transport failure — the request still ends at the
 * login form. Only two things change: the message stops lying, and the attempt
 * is not counted. Classification is POSITIVE-RECOGNITION ONLY, so an unfamiliar
 * error still takes the credential path and can still lock the account.
 *
 * The transport signatures describe THIS server's outbound connection to
 * Google. A client cannot influence them, so they are not a lockout bypass.
 */
class LoginTransportVsCredentialsTest extends TestCase
{
    private const FIREBASE = __DIR__ . '/../../application/libraries/Firebase.php';
    private const LOGIN    = __DIR__ . '/../../application/controllers/Admin_login.php';
    private const TELEM    = __DIR__ . '/../../application/libraries/Security_telemetry.php';

    /** Invoke the private classifier without constructing Firebase (it is static). */
    private function classify(string $message): string
    {
        require_once self::FIREBASE;
        $m = new \ReflectionMethod('Firebase', '_classifySignInFailure');
        $m->setAccessible(true);
        return (string) $m->invoke(null, $message);
    }

    private function src(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    // ── classification ───────────────────────────────────────────────────────

    /** Verbatim from application/logs — the messages that caused the incident. */
    public function test_real_transport_failures_are_recognised(): void
    {
        $real = [
            'Sign in failed: cURL error 28: Failed to connect to oauth2.googleapis.com port 443 '
                . 'after 5015 ms: Timeout was reached (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) '
                . 'for https://oauth2.googleapis.com/token',
            'Sign in failed: cURL error 28: SSL connection timeout (see …) for https://oauth2.googleapis.com/token',
            'Sign in failed: cURL error 28: Resolving timed out after 5003 milliseconds',
            'Sign in failed: cURL error 6: Could not resolve host: oauth2.googleapis.com',
        ];
        foreach ($real as $msg) {
            $this->assertSame('transport', $this->classify($msg),
                "A connection failure was classified as a credential judgement:\n{$msg}");
        }
    }

    /** Firebase's own credential verdicts must keep the credential path. */
    public function test_real_credential_rejections_stay_credential_failures(): void
    {
        foreach ([
            'INVALID_LOGIN_CREDENTIALS',
            'INVALID_PASSWORD',
            'EMAIL_NOT_FOUND',
            'USER_DISABLED',
            'A password must be a string with at least 6 characters.',
        ] as $msg) {
            $this->assertSame('credentials', $this->classify($msg),
                "A credential rejection was excused as a network failure: {$msg}");
        }
    }

    /**
     * The safety property. An unrecognised error must NOT be excused — it keeps
     * today's behaviour: counted as an attempt, able to lock the account.
     */
    public function test_an_unfamiliar_error_fails_closed_to_the_credential_path(): void
    {
        foreach ([
            'Something nobody has seen before',
            '',
            'QUOTA_EXCEEDED',
            'TOO_MANY_ATTEMPTS_TRY_LATER',
        ] as $msg) {
            $this->assertSame('credentials', $this->classify($msg),
                'An unrecognised failure was treated as transport, which would let an unknown '
                . "error class silently bypass the lockout counter: \"{$msg}\"");
        }
    }

    public function test_the_flag_is_cleared_before_each_attempt(): void
    {
        $src = $this->src(self::FIREBASE);
        $at  = strpos($src, 'function signInWithEmail(');
        $this->assertNotFalse($at, 'signInWithEmail() is gone.');
        $body = substr($src, $at, 700);
        $this->assertMatchesRegularExpression('/lastSignInFailure\s*=\s*\'\'/', $body,
            'signInWithEmail() does not reset the failure kind, so a stale "transport" from an '
            . 'earlier call could excuse a later genuine credential failure.');
    }

    // ── wiring: the caller must act on it, in the right order ────────────────

    public function test_the_login_checks_transport_before_counting_a_failed_attempt(): void
    {
        $src = $this->src(self::LOGIN);

        $check = strpos($src, 'lastSignInFailureWasTransport()');
        $this->assertNotFalse($check,
            'Admin_login no longer distinguishes a connection failure from a wrong password.');

        $ipFail  = strpos($src, '_record_admin_ip_fail(');
        $accFail = strpos($src, '_record_admin_account_fail(');
        $this->assertNotFalse($ipFail);
        $this->assertNotFalse($accFail);

        $this->assertLessThan($ipFail, $check,
            'the transport check runs AFTER the IP failure is recorded — a network outage '
            . 'would still consume rate-limit budget');
        $this->assertLessThan($accFail, $check,
            'the transport check runs AFTER the account failure is recorded — a network outage '
            . 'would still consume a lockout attempt and could lock the account');
    }

    public function test_the_transport_branch_grants_nothing(): void
    {
        $src   = $this->src(self::LOGIN);
        $at    = strpos($src, 'lastSignInFailureWasTransport()');
        $this->assertNotFalse($at);
        $brace = strpos($src, '{', $at);
        $depth = 0; $i = $brace; $len = strlen($src);
        while ($i < $len) {
            if ($src[$i] === '{') { $depth++; }
            elseif ($src[$i] === '}') { $depth--; if ($depth === 0) break; }
            $i++;
        }
        $branch = substr($src, $brace, $i - $brace);

        $this->assertStringContainsString("redirect('admin_login')", $branch,
            'the transport branch must end at the login form');
        foreach (['set_userdata', 'ADMIN_LOGIN_SUCCESS', 'logged_in'] as $banned) {
            $this->assertStringNotContainsString($banned, $branch,
                "the transport branch establishes a session ({$banned}) — a connection failure "
                . 'must never grant access');
        }
    }

    public function test_the_telemetry_mark_is_registered_or_the_event_is_dropped(): void
    {
        // Security_telemetry::emit() rejects unknown marks and logs them as
        // invalid, so an unregistered mark would make this incident invisible.
        $this->assertStringContainsString("'ADMIN_LOGIN_UNAVAILABLE'", $this->src(self::TELEM),
            'ADMIN_LOGIN_UNAVAILABLE is not in the mark registry, so the event is dropped.');
        $this->assertStringContainsString('ADMIN_LOGIN_UNAVAILABLE', $this->src(self::LOGIN),
            'the login does not emit the unavailability mark, so the outage leaves no trace.');
    }
}
