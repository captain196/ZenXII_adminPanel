<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Wave A — Developer SA → Firebase Auth single source : feature flags
|--------------------------------------------------------------------------
| DECLARED in Phase A0, all OFF. Nothing reads these yet — declaring them
| is a no-op until the A2+ wiring references them. Each flag is flipped
| ONLY per the Wave A choreography and is independently reversible
| (flip back to false = instant revert to the prior RTDB/Mongo behavior).
|
| Authoritative state for the migration program will live in a Firestore
| registry; this file is the local default + intent record for the SA wave.
*/
$config['sa_migration_flags'] = [
    'sa.login_firebase_jit'  => true,  // A2: Firebase-Auth-first SA login (ENABLED 2026-05-28 — Auth API down; SUP0001 login via Firebase Auth). Legacy Mongo fallback kept.
    'sa.authz_firestore'        => true,  // A2.1: SA-login authorization READ via Firestore superAdmins first, RTDB Our Panel fallback (dual-read). ENABLED 2026-05-28.
    'sa.authz_firestore_strict' => true,  // A2.3: Firestore-only authorization (no RTDB read/fallback, no RTDB access-history). SA login RTDB-free. ENABLED 2026-05-29.
    'sa.mgmt_firebase'       => false, // A3: SA create/list/reset/delete via Firebase Auth + Firestore superAdmins
    'sa.ratelimit_firestore' => true,  // A4: SA IP rate-limit RTDB RateLimit/SA -> Firestore saIpRateLimit. ENABLED 2026-05-29. SA login now fully RTDB-free.
    'sa.bootstrap_enabled'   => false, // A1: guarded first-SA bootstrap (acts only when zero SAs exist)
    'sa.retire_legacy'       => false, // A6: remove RTDB Our Panel + Mongo/auth_client SA paths

    // ── Wave B1: onboarding (Superadmin_schools::onboard) Mongo removal ──
    // Both OFF = current behavior (auth_client/Mongo). Flip ON only per the B1.2
    // cutover gates + B1.3 soak. Each independently reversible.
    'onboard.schcode_firestore' => true,  // B1: school_code via Id_generator (Firestore atomic). ENABLED 2026-05-29 for B1.3 soak.
    'onboard.ssa_firebase'      => true,  // B1: SSA id via Id_generator + createFirebaseUser + setFirebaseClaims. ENABLED 2026-05-29 for B1.3 soak.
];
