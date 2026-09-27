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
 *   V2 (versioned) : URL-safe base64 of `"{schoolId}|{studentId}|v{N}|{sig}"`
 *                    sig = HMAC-SHA256(secret, "{schoolId}|{studentId}|v{N}")
 *   V1 (signed)    : URL-safe base64 of `"{schoolId}|{studentId}|{sig}"`
 *                    sig = HMAC-SHA256(secret, "{schoolId}|{studentId}")
 *   Legacy (2-part): URL-safe base64 of `"{schoolId}|{studentId}"`   — UNSIGNED
 *
 * All three are accepted during the migration window (Phase 19E). The decoder
 * reports which it saw via `tokenVersion` ('v2'|'v1'|'legacy'), and legacy
 * tokens additionally carry `legacy => true` so existing call sites — which
 * all branch on that flag — keep working unchanged. Signed tokens with a
 * wrong / missing / truncated signature are rejected outright, never demoted
 * to legacy acceptance.
 *
 * Why a version at all (Phase 19E)
 * --------------------------------
 * V1 signs only (schoolId, studentId) — both permanent — so a V1 credential is
 * effectively immortal: reprinting a lost student's card yields the identical
 * token, and there is no way to invalidate the lost one short of rotating the
 * secret and killing every card in every school. Binding a card version makes
 * a single card retirable. The version is signed, not merely carried, so it
 * cannot be edited by the holder.
 *
 * ⚠ THE VERSION IS NOT YET ENFORCED. Decoding returns `cardVersion`; nothing
 * compares it to the student's active card. See qr_token_decode()'s contract
 * note — enforcement is Phase 19G, after every student has been reissued.
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

if (!function_exists('_qr_signature_v2')) {
    /**
     * V2 signature — binds the CARD VERSION as well as the identity.
     *
     * This is the whole point of V2 (Phase 19E). V1 signs only
     * "{schoolId}|{studentId}", both of which are immutable for the life of
     * the student, which is why a V1 credential can never be revoked or
     * superseded: reprinting a card produces byte-identical bits. Including
     * the version in the SIGNED MATERIAL (not merely in the payload) means a
     * card can be retired by advancing the student's active version — the old
     * token's signature is still valid for the old version and simply stops
     * matching the active one.
     *
     * Version is stamped as `v{N}` so a 4-part payload can never be confused
     * with a 3-part V1 one, and so the segment is self-describing on sight.
     */
    function _qr_signature_v2(string $schoolId, string $studentId, int $cardVersion): string
    {
        return substr(
            hash_hmac('sha256', $schoolId . '|' . $studentId . '|v' . $cardVersion, _qr_secret()),
            0, 16
        );
    }
}

if (!function_exists('qr_token_encode_v2')) {
    /**
     * Mint a VERSIONED (V2) token — `"{schoolId}|{studentId}|v{N}|{sig}"`.
     *
     * ADDITIVE. Nothing calls this on the live ID-card render path yet: Phase
     * 19E builds the foundation only, and switching the printed card over is
     * Phase 19G's controlled migration step. Minting a V2 token today and
     * scanning it works everywhere a V1 token works, because every consumer
     * branches on `legacy` (false for both V1 and V2) and NOTHING yet compares
     * `cardVersion` against the student's active card. That non-enforcement is
     * deliberate — see qr_token_decode()'s contract note.
     */
    function qr_token_encode_v2(string $schoolId, string $studentId, int $cardVersion): string
    {
        if ($cardVersion < 1) $cardVersion = 1;
        $sig     = _qr_signature_v2($schoolId, $studentId, $cardVersion);
        $payload = $schoolId . '|' . $studentId . '|v' . $cardVersion . '|' . $sig;
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }
}

if (!function_exists('qr_token_decode')) {
    /**
     * Decode and verify a token.
     *
     * @return array{schoolId:string,studentId:string,legacy:bool,tokenVersion:string,cardVersion:?int}|null
     *
     * Return shape:
     *   - V2, signature OK : [..., 'legacy'=>false, 'tokenVersion'=>'v2',     'cardVersion'=>N]
     *   - V1, signature OK : [..., 'legacy'=>false, 'tokenVersion'=>'v1',     'cardVersion'=>null]
     *   - Legacy 2-part    : [..., 'legacy'=>true,  'tokenVersion'=>'legacy', 'cardVersion'=>null]
     *   - Anything else    : null
     *
     * `cardVersion` is null for V1/legacy because those tokens name no card at
     * all — do NOT default it to 1, which would make an unmanaged card look
     * like version 1 of a managed one.
     *
     * `legacy` is preserved exactly as before so the three existing consumers
     * (Attendance::scan_qr, Transport_qr_attendance_service, Visitor_service)
     * keep working with no change: V2 reads as non-legacy, so it is accepted
     * everywhere V1 is, including by Transport's fail-closed check.
     *
     * Callers that care about migration progress should check `legacy` and log
     * at 'error' (the only level CI3 actually writes here — see SEC-ID-3).
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

        // 4-part versioned (V2) — preferred. 3-part signed (V1) — accepted.
        // 2-part legacy — accepted with flag during the migration window.
        $parts = explode('|', $payload);
        $count = count($parts);
        if ($count < 2 || $count > 4) return null;

        $schoolId  = $parts[0];
        $studentId = $parts[1];
        if ($schoolId === '' || $studentId === '') return null;

        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $schoolId))  return null;
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $studentId)) return null;

        if ($count === 4) {
            // ── CREDENTIAL V2 (Phase 19E) ────────────────────────────────
            // "{schoolId}|{studentId}|v{N}|{sig}" — the version is part of
            // the SIGNED material, so editing it invalidates the signature
            // rather than silently promoting/demoting a card.
            //
            // ⚠ CONTRACT NOTE — READ BEFORE ADDING ENFORCEMENT.
            // This function verifies that the token is AUTHENTIC and reports
            // which card version it names. It deliberately does NOT check
            // that version against the student's currently-active card,
            // because that requires a Firestore read and this helper is pure.
            // Comparing `cardVersion` to the active version is what actually
            // revokes a superseded card, and it is Phase 19G's controlled
            // step (see Id_card_service::isCurrentVersion()). Enabling it
            // before every student has been reissued would invalidate cards
            // school-wide — the exact outcome the migration plan forbids.
            $ver = $parts[2];
            if (!preg_match('/^v([0-9]{1,4})$/', $ver, $vm)) return null;
            $cardVersion = (int) $vm[1];
            if ($cardVersion < 1) return null;

            $sig = $parts[3];
            if (!preg_match('/^[a-f0-9]{16}$/', $sig)) return null;
            $expected = _qr_signature_v2($schoolId, $studentId, $cardVersion);
            if (!hash_equals($expected, $sig)) return null;

            return [
                'schoolId'     => $schoolId,
                'studentId'    => $studentId,
                'legacy'       => false,
                'tokenVersion' => 'v2',
                'cardVersion'  => $cardVersion,
            ];
        }

        if ($count === 3) {
            $sig = $parts[2];
            // Constant-time compare — `hash_equals` blocks timing
            // oracles. Mismatch ⇒ invalid token, no fallback.
            if (!preg_match('/^[a-f0-9]{16}$/', $sig)) return null;
            $expected = _qr_signature($schoolId, $studentId);
            if (!hash_equals($expected, $sig)) return null;
            // `cardVersion => null` (not 1): a V1 token names NO card. Callers
            // must not mistake it for "version 1 of a managed card".
            return [
                'schoolId'     => $schoolId,
                'studentId'    => $studentId,
                'legacy'       => false,
                'tokenVersion' => 'v1',
                'cardVersion'  => null,
            ];
        }

        // 2-part legacy — accepted during the migration window.
        return [
            'schoolId'     => $schoolId,
            'studentId'    => $studentId,
            'legacy'       => true,
            'tokenVersion' => 'legacy',
            'cardVersion'  => null,
        ];
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
