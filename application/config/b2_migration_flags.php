<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Wave B2 — migration control flags (B2.2-F F5)
|--------------------------------------------------------------------------
| All declared OFF as no-op. Each is independently reversible (flip back to
| false = instant revert to the prior RTDB-authoritative behavior). Nothing
| reads these yet at B2.2-F; declaring them is inert until the B2.3α/B2.3/B2.5
| writers/readers reference them.
|
| DISTINCT $config['b2_migration_flags'] array — NOT inside sa_migration_flags
| (which holds Wave A sa.* + Wave B1 onboard.* keys), and NOT colliding with
| CI3's built-in $config['migration_*'] namespace.
|
| All b2.* flags are MIGRATION SCAFFOLDING — removed entirely at B2.8 when the
| Firestore-only end-state retires the bridge. After B2.8 every flag-fork
| collapses to the Firestore-only path, leaving no residue.
*/
$config['b2_migration_flags'] = [
    'b2.billing_harden'          => true,  // B2.3α: claim-first via Billing_integrity on the legacy billing path. ACTIVATED 2026-05-30.
    // B2.3.2 single atomic co-cutover flag.
    // ACTIVATED 2026-05-30 — single-flag flip across A + D + B-1 + B-2 + B-3 + C + E.
    // ROLLED BACK 2026-05-31 (#1) — query-layer defects → B2.3.2-FIX R1-R4.
    // REACTIVATED 2026-05-31 — R1-R4 + read-side runtime verifier passed.
    // ROLLED BACK 2026-05-31 (#2) — BUG E: REST client treats dotted keys as
    // literal field names instead of nested-path updates.
    // REACTIVATED 2026-05-31 — R5 fix at Firestore_rest_client::updateDocument
    // + encodeFieldPath (single point of repair benefits every dotted-key
    // caller). Runtime verifier now PASS 18/18 including 4 round-trip write
    // probes (update_school_profile, write_lifecycle_state, set_admin_disabled
    // composite, update_stats_cache); zero warnings; existing structural +
    // foundation + JS data verifiers all green.
    'b2.registry_firestore'      => true,
    'b2.billing_firestore'       => false, // DEPRECATED: billing is part of the single-flag B2.3.2 co-cutover; this flag is retained as scaffolding until B2.8 cleanup but is not read.
    'b2.registry_read_firestore' => false, // DEPRECATED: rejected (read-fallback architecture). Retained as scaffolding until B2.8 cleanup but is not read.
    // B2.3.4-E: Reports module cutover flag.
    // History:
    //   ROLLED BACK 2026-05-31 (#1) — runtime defect (PHP REST client
    //   {id,data} response shape + condition-shape mismatch + orderBy on
    //   missing field) caused all 4 reports to return empty. Defects were
    //   in B2_registry_service, not reports-specific.
    //   REACTIVATED 2026-05-31 — same R1-R5 fixes that brought B2.3.2 back
    //   to green also fix the reports paths (they share list_tenants_summary,
    //   list_plans, list_paid_payments, list_recent_activity). Runtime
    //   verifier PASS 18/18. With this flag OFF the legacy RTDB path runs
    //   and finds an empty `System/Schools` tree (post-migration) — every
    //   report shows "No data found". Turning the flag ON routes all 4
    //   reports through canonical Firestore.
    'b2.reports_firestore'       => true,
    'b2.trials'                  => false, // deferred: trial subscriptions framework
    'b2.addons'                  => false, // deferred: priced add-on catalog
    'b2.flags_global'            => false, // deferred: global feature-flag cohort layer
];
