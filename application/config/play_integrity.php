<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Play Integrity configuration for GPS staff-attendance anti-spoofing.
 *
 * INERT BY DEFAULT (enabled = false). While disabled, the punch flow behaves
 * exactly as before — the server-authoritative geofence still runs; this only
 * ADDS a hardware-backed "is this a genuine app on a genuine device" check.
 *
 * ── OPS SETUP (do all four to turn it on) ─────────────────────────────────
 *   1. Google Play Console → your app (com.schoolsync.teacher) → Release →
 *      App integrity → enable the Play Integrity API. Note the linked Google
 *      Cloud project number.
 *   2. Google Cloud Console (that project) → enable the "Play Integrity API".
 *   3. Create/reuse a service-account JSON granted the scope
 *      https://www.googleapis.com/auth/playintegrity. Put its path in
 *      'service_account_json' below (keep the file OUTSIDE the web root).
 *   4. Ship the Teacher-app build that requests an integrity token and sends
 *      it as `integrityToken` on the punch (the app change is config-gated too).
 *   Then set 'enabled' => true and optionally 'require' => true.
 *
 * 'require' semantics:
 *   false → verify when a token is present, but DON'T reject a punch that omits
 *           one (safe rollout: old app builds keep working).
 *   true  → a missing/invalid token is rejected (enforce once all clients ship
 *           the token-sending build).
 */
$config['play_integrity'] = [
    'enabled'               => false,   // master switch (ops step 1–4 must be done)
    'require'               => false,   // true = reject punches without a valid token
    'strict'                => false,   // true = a Google/verify OUTAGE rejects (else fail-open)
    'package_name'          => 'com.schoolsync.teacher',
    'service_account_json'  => '',      // absolute path to the Play Integrity SA JSON
    'allow_unrecognized_app'=> false,   // true only for internal/sideloaded test builds
];
