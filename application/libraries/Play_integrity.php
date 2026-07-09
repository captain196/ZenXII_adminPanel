<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Play_integrity — server-side Google Play Integrity verification for GPS
 * staff-attendance anti-spoofing.
 *
 * The geofence is already server-authoritative, but it judges CLIENT-SUPPLIED
 * coordinates and the only spoof signal is the client `mock` flag — defeatable
 * by a rooted device / fake-GPS app / repacked APK reporting mock=false. Play
 * Integrity adds a hardware-backed attestation that the punch came from a
 * genuine, unmodified app on a genuine device.
 *
 * INERT BY DEFAULT — see application/config/play_integrity.php for the four
 * ops steps. Until enabled + configured, available() is false and verify()
 * fail-opens, so punch() behaves exactly as before.
 */
class Play_integrity
{
    /** @var \CI_Controller */
    private $ci;
    private array $cfg = [];

    public function __construct()
    {
        $this->ci =& get_instance();
        // Optional config file; absent → feature stays disabled.
        try { $this->ci->config->load('play_integrity', true, true); } catch (\Throwable $e) {}
        $c = $this->ci->config->item('play_integrity');
        $this->cfg = is_array($c) ? $c : [];
    }

    /** Master gate: true only when fully configured (ops steps 1–4 done). */
    public function available(): bool
    {
        $sa = (string) ($this->cfg['service_account_json'] ?? '');
        return !empty($this->cfg['enabled'])
            && !empty($this->cfg['package_name'])
            && $sa !== '' && is_file($sa);
    }

    /** Should a punch WITHOUT a valid token be rejected? (Only meaningful when available.) */
    public function isRequired(): bool
    {
        return $this->available() && !empty($this->cfg['require']);
    }

    /**
     * Verify an integrity token.
     *
     * @return array ['ok'=>bool, 'reason'=>string, 'verdict'=>array]
     *   When the feature is disabled, returns ok=true reason='disabled' (fail-open
     *   by design — the caller decides whether to require a token via isRequired()).
     */
    public function verify(string $token, array $ctx = []): array
    {
        if (!$this->available()) return ['ok' => true, 'reason' => 'disabled', 'verdict' => []];
        if ($token === '')       return ['ok' => false, 'reason' => 'missing_token', 'verdict' => []];

        try {
            $access = $this->_access_token();
            if ($access === '') {
                // Can't reach Google's auth — treat as a transient outage.
                return $this->_soft_fail('auth_unavailable');
            }

            $pkg  = rawurlencode((string) $this->cfg['package_name']);
            $url  = "https://playintegrity.googleapis.com/v1/{$pkg}:decodeIntegrityToken";
            $resp = $this->_http_post_json($url, ['integrity_token' => $token], $access);
            if (!is_array($resp)) return $this->_soft_fail('decode_failed');

            $payload  = $resp['tokenPayloadExternal'] ?? [];
            $appV     = (string) ($payload['appIntegrity']['appRecognitionVerdict'] ?? '');
            $devV     = (array)  ($payload['deviceIntegrity']['deviceRecognitionVerdict'] ?? []);
            $reqHash  = $payload['requestDetails']['requestPackageName'] ?? '';

            // App must be a genuine Play-recognised binary (unless a test build
            // is explicitly allowed) and the device must meet basic integrity.
            $appOk = ($appV === 'PLAY_RECOGNIZED') || !empty($this->cfg['allow_unrecognized_app']);
            $devOk = in_array('MEETS_DEVICE_INTEGRITY', $devV, true)
                  || in_array('MEETS_STRONG_INTEGRITY', $devV, true);
            // Defence-in-depth: the token's package must be ours.
            $pkgOk = ($reqHash === '') || ($reqHash === (string) $this->cfg['package_name']);

            $ok = $appOk && $devOk && $pkgOk;
            return [
                'ok'      => $ok,
                'reason'  => $ok ? '' : 'verdict_failed',
                'verdict' => ['app' => $appV, 'device' => $devV],
            ];
        } catch (\Throwable $e) {
            log_message('error', 'Play_integrity::verify failed: ' . $e->getMessage());
            return $this->_soft_fail('verify_error');
        }
    }

    /* ─── private ──────────────────────────────────────────────────────── */

    /**
     * On a transient error (Google outage, network), fail-OPEN unless 'strict'
     * is set — so an outage never locks every staff member out of attendance.
     */
    private function _soft_fail(string $reason): array
    {
        $strict = !empty($this->cfg['strict']);
        return ['ok' => !$strict, 'reason' => $strict ? $reason : $reason . '_softpass', 'verdict' => []];
    }

    /**
     * Mint a Google OAuth access token for the Play Integrity scope from the
     * configured service-account JSON, reusing the google/auth library the
     * panel already ships (same mechanism as the Firestore/Storage clients).
     */
    private function _access_token(): string
    {
        $saPath = (string) $this->cfg['service_account_json'];
        if ($saPath === '' || !is_file($saPath)) return '';
        if (!class_exists('\\Google\\Auth\\Credentials\\ServiceAccountCredentials')) {
            log_message('error', 'Play_integrity: google/auth not installed; cannot mint token.');
            return '';
        }
        try {
            $creds = new \Google\Auth\Credentials\ServiceAccountCredentials(
                'https://www.googleapis.com/auth/playintegrity',
                $saPath
            );
            $tok = $creds->fetchAuthToken();
            return (string) ($tok['access_token'] ?? '');
        } catch (\Throwable $e) {
            log_message('error', 'Play_integrity token mint failed: ' . $e->getMessage());
            return '';
        }
    }

    /** Minimal JSON POST with a Bearer token. Returns the decoded array or null. */
    private function _http_post_json(string $url, array $body, string $bearer): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $bearer,
            ],
        ]);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out === false || $code < 200 || $code >= 300) {
            log_message('error', "Play_integrity decode HTTP {$code}: " . substr((string) $out, 0, 300));
            return null;
        }
        $decoded = json_decode($out, true);
        return is_array($decoded) ? $decoded : null;
    }
}
