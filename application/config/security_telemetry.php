<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Security Telemetry — Phase A configuration
|--------------------------------------------------------------------------
|
| Read-only, declarative settings consumed by:
|   - application/libraries/Security_telemetry.php   (event emission)
|   - application/controllers/Security_metrics_cron.php  (daily aggregation)
|
| Phase A posture: OBSERVE-ONLY.
|   - No paging integration.
|   - No external alerts.
|   - Threshold breaches land in `staff_metrics_daily.thresholds_exceeded[]`
|     and are reviewed during the 7-day soak window before Phase B.
|
| Tuning rule:
|   These thresholds are *initial guesses*. After 1 week of baseline data
|   they MUST be re-tuned against actual observed distributions before
|   Phase A-3 (alert collection) ships. Do not connect paging until tuned.
|
*/

// ── DEDUPE WINDOW ───────────────────────────────────────────────────────────
// Repeats of the same (event_type, actor_uid) tuple within this window
// count once against thresholds. Prevents alert storms during sustained
// probing. 4 hours = 14_400 seconds.
$config['security_dedupe_window_seconds'] = 14400;

// ── DAILY THRESHOLDS ────────────────────────────────────────────────────────
// Breach = the named counter exceeds the threshold for a (school_id, day)
// or (actor, day) tuple. Breach writes an entry into
//   staff_metrics_daily/{YYYY-MM-DD}_{schoolId}.thresholds_exceeded[]
//
// Phase A: NO paging. Phase A-3 writes to a separate alerts/{id} collection.
// Phase B+: external paging integration.
$config['security_thresholds'] = [
    'bulk_staff_read_per_hour'            => 50,
    'cross_tenant_rule_denials_per_day'   => 3,
    'rbac_denied_per_user_per_day'        => 10,
    'cascade_failures_per_day'            => 1,    // any cascade failure trips
    'staff_create_per_school_per_day'     => 20,   // anomaly: bulk create
    'role_change_per_user_per_day'        => 5,    // anomaly: rapid role churn
    'storage_downloads_per_user_per_hour' => 100,
    'duplicate_phone_per_day'             => 3,
    'pan_update_attempt_per_day'          => 1,    // any attempt warrants review
    'escalation_attempt_per_day'          => 1,    // any attempt is critical
    'deactivated_login_per_day'           => 1,
    'upload_rejected_per_user_per_day'    => 5,
];

// ── SAMPLING ────────────────────────────────────────────────────────────────
// 1.0 = log every event (Phase A baseline — we want full-fidelity data).
// Lower to e.g. 0.1 once volume justifies + thresholds are tuned.
$config['security_event_sample_rate'] = 1.0;

// ── COLLECTION NAMES ────────────────────────────────────────────────────────
// All four collections are SERVER-ONLY in Phase A (no mobile/client reads).
// Firestore rules are unchanged; service-account writes bypass rules.
$config['security_collections'] = [
    'audit_log'         => 'staffAuditLog',
    'security_events'   => 'security_events',
    'cascade_failures'  => 'cascade_failures',
    'metrics_daily'     => 'staff_metrics_daily',
];

// ── SENSITIVE KEY PATTERN ───────────────────────────────────────────────────
// Regex matched against keys (case-insensitive). Matching keys have their
// values replaced with '[MASKED]' before being written to either log line
// or Firestore doc. Security_telemetry::_maskSensitive() uses this.
//
// Add new patterns deliberately, never casually.
$config['security_sensitive_key_pattern'] =
    '/(password|panNumber|aadhar|account|bankDetails|pfNumber|esiNumber|credentials|token|secret)/i';
