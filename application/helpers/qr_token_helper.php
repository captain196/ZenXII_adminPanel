<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * QR identity token + image helper.
 *
 * One module covers the whole QR lifecycle for the SIS ID-card / scan
 * flow:
 *   1. Token generation     — `qr_token_encode()`
 *   2. Token verification   — `qr_token_decode()`     (HMAC + legacy fallback)
 *   3. Local SVG rendering  — `qr_svg_data_uri()`     (no external API)
 *   4. Same-origin URL      — `qr_image_proxy_url()`  (back-compat shim)
 *
 *
 * TOKEN FORMAT
 * ------------
 *   New (signed)  : URL-safe base64 of `"{schoolId}|{studentId}|{sig}"`
 *                    where sig = first 16 hex chars of
 *                                HMAC-SHA256(_qr_secret(), "{schoolId}|{studentId}")
 *   Legacy (2-part): URL-safe base64 of `"{schoolId}|{studentId}"`
 *
 * The decoder accepts BOTH forms during the migration window. Legacy
 * 2-part tokens are flagged with `legacy => true` in the decoded array
 * so call sites can log usage. Signed tokens with a wrong / missing /
 * truncated signature are rejected as invalid.
 *
 * Why HMAC at all
 * ---------------
 * Plaintext base64 lets anyone craft a QR for any (schoolId, studentId)
 * pair and walk it past the attendance scanner — a teacher's phone or
 * a printed-on-paper QR would mark Present for that student. The
 * server-side tenant-isolation check in `Attendance::scan_qr` blocks
 * cross-school abuse but doesn't help against a malicious insider with
 * the school's own studentId list. HMAC closes that gap: only tokens
 * minted by *this server* (which holds the secret) verify cleanly.
 *
 *
 * IMAGE RENDERING
 * ---------------
 * Production-grade ERPs do NOT use external QR APIs (qrserver.com,
 * google-charts, etc.) for student ID flows — ad-blockers, firewalls,
 * privacy/compliance, and offline air-gapped exam mode all kill the
 * experience. We render locally via `chillerlan/php-qrcode` (composer)
 * straight to inline SVG, returned as a `data:image/svg+xml;base64,…`
 * URI — perfect for `<img src="…">` on the ID card AND embeddable in
 * the Dompdf-rendered admission receipt without a network round-trip.
 */

if (!function_exists('_qr_secret')) {
    /**
     * Resolve the HMAC secret. Read order:
     *   1. `config_item('qr_token_secret')`  — set this in production
     *   2. `config_item('encryption_key')`   — CodeIgniter standard
     *   3. Project-fixed fallback           — dev-only safety net
     *
     * Fallback exists so a fresh dev setup works without environment
     * setup; production deployments MUST set one of #1 or #2.
     */
    function _qr_secret(): string
    {
        $custom = (string) config_item('qr_token_secret');
        if ($custom !== '') return $custom;
        $ck = (string) config_item('encryption_key');
        if ($ck !== '') return $ck;
        return 'grader-qr-token-fallback-please-set-encryption-key';
    }
}

if (!function_exists('_qr_signature')) {
    /**
     * Compute the 16-hex-char (64-bit) signature for a (schoolId,
     * studentId) pair. Truncated for QR length — 64 bits of HMAC is
     * still well above any brute-force budget for an attacker who
     * can only make one server roundtrip per attempt.
     */
    function _qr_signature(string $schoolId, string $studentId): string
    {
        return substr(
            hash_hmac('sha256', $schoolId . '|' . $studentId, _qr_secret()),
            0, 16
        );
    }
}

if (!function_exists('qr_token_encode')) {
    /**
     * Mint a SIGNED token for (schoolId, studentId). Always emits the
     * 3-part signed form — we never write legacy tokens going forward.
     */
    function qr_token_encode(string $schoolId, string $studentId): string
    {
        $sig     = _qr_signature($schoolId, $studentId);
        $payload = $schoolId . '|' . $studentId . '|' . $sig;
        $b64     = base64_encode($payload);
        return rtrim(strtr($b64, '+/', '-_'), '=');
    }
}

if (!function_exists('qr_token_decode')) {
    /**
     * Decode and verify a token.
     *
     * @return array{schoolId:string,studentId:string,legacy:bool}|null
     *
     * Return shape:
     *   - Signed token, signature OK : ['schoolId'=>..., 'studentId'=>..., 'legacy'=>false]
     *   - Legacy 2-part token        : ['schoolId'=>..., 'studentId'=>..., 'legacy'=>true]
     *   - Anything else              : null
     *
     * Callers that care about migration progress should check `legacy`
     * and log a warning when it's true so we know when to flip the
     * acceptance flag off.
     */
    function qr_token_decode(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 256) return null;
        if (!preg_match('/^[A-Za-z0-9_\-]+={0,2}$/', $token)) return null;

        $b64     = strtr($token, '-_', '+/');
        $padded  = $b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $payload = base64_decode($padded, true);
        if ($payload === false || $payload === '') return null;

        // 3-part signed — preferred. 2-part legacy — accepted with flag.
        $parts = explode('|', $payload);
        $count = count($parts);
        if ($count !== 2 && $count !== 3) return null;

        $schoolId  = $parts[0];
        $studentId = $parts[1];
        if ($schoolId === '' || $studentId === '') return null;

        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $schoolId))  return null;
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $studentId)) return null;

        if ($count === 3) {
            $sig = $parts[2];
            // Constant-time compare — `hash_equals` blocks timing
            // oracles. Mismatch ⇒ invalid token, no fallback.
            if (!preg_match('/^[a-f0-9]{16}$/', $sig)) return null;
            $expected = _qr_signature($schoolId, $studentId);
            if (!hash_equals($expected, $sig)) return null;
            return ['schoolId' => $schoolId, 'studentId' => $studentId, 'legacy' => false];
        }

        // 2-part legacy — accepted during the migration window.
        return ['schoolId' => $schoolId, 'studentId' => $studentId, 'legacy' => true];
    }
}

if (!function_exists('qr_svg_data_uri')) {
    /**
     * Render the given token as an inline SVG QR, returned as a
     * `data:image/svg+xml;base64,…` URI suitable for an `<img src>`
     * (browser HTML or Dompdf PDF — both handle data URIs).
     *
     * Renderer: chillerlan/php-qrcode 5.x.
     *   - SVG output (vector — scales for poster-size print).
     *   - eccLevel L (default Low) is plenty for short tokens — keeps
     *     the QR small enough that even a 60px on-screen render is
     *     sharp on a high-DPI camera.
     *   - addQuietZone true (default) — required for reliable scans.
     *
     * Returns an empty string only when the underlying renderer
     * throws (extremely rare; we'd lose the QR, not the page).
     */
    function qr_svg_data_uri(string $token): string
    {
        try {
            $options = new \chillerlan\QRCode\QROptions([
                'outputType'   => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
                'outputBase64' => true,                               // emit `data:` URI
                'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::L,
                'scale'        => 5,
                'addQuietzone' => true,
            ]);
            $qr = new \chillerlan\QRCode\QRCode($options);
            return (string) $qr->render($token);
        } catch (\Throwable $e) {
            log_message('error', 'qr_svg_data_uri render failed: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('qr_image_url')) {
    /**
     * @deprecated post-Tier-A QR upgrade — use `qr_svg_data_uri()` for
     *             inline rendering. Preserved for the admission PDF
     *             receipt path which already routes through Dompdf
     *             (which now happily accepts the same data URI). Will
     *             be removed once all call sites are migrated.
     *
     * Returns a same-origin `/sis/qr_image/{token}` URL — `Sis::qr_image`
     * now generates the QR locally too, so this URL never reaches an
     * external service. Kept for parity with the prior public API.
     */
    function qr_image_url(string $token, int $sizePx = 120): string
    {
        return qr_image_proxy_url($token, $sizePx);
    }
}

if (!function_exists('qr_image_proxy_url')) {
    /**
     * Build a same-origin URL that streams a QR PNG via our
     * `Sis::qr_image` endpoint. Now backed by local generation
     * (no external API). Use `qr_svg_data_uri()` instead whenever
     * you can — this URL form is kept only because some legacy
     * call sites pass an `<img src="…">` to a context that doesn't
     * easily accept a data URI (e.g. background-image hacks).
     */
    function qr_image_proxy_url(string $token, int $sizePx = 120): string
    {
        $size = max(60, min(512, $sizePx));
        return base_url('sis/qr_image/' . rawurlencode($token) . '?size=' . $size);
    }
}
