# PHASE A — Observability & Audit Backbone

**Project:** Staff Security Hardening Programme
**Phase:** A — Observability & Audit Backbone
**Status:** Implementation plan + foundational patches ready. No security enforcement modified. Additive only.

---

## Investigation Grounding (Pre-Plan)

Before drafting the plan, the following infrastructure was inspected to ensure proposals are anchored in actual code state:

- `Audit_log_service` (`application/libraries/Audit_log_service.php`) exists with a clean API but is **whitelist-scoped to academic-planner entities** (`ENTITY_TYPES = ['curriculum', 'curriculumTopic', 'timetable', …]`). Adding staff entity types is **additive** — existing callers untouched.
- Codebase telemetry convention is **`[PREFIX] key=value …`** structured `log_message()` lines (see `ACC_LOCK_*`, `PAYROLL_*`, `ACC_DENORM_DIVERGENCE`, `[PERIOD_LOCK BLOCKED]`). Phase A follows this convention.
- `uploadStaffFile` (`Staff.php:292`) already has MIME validation via `finfo` (line 305) but **silently returns `false`** on 3 distinct failure modes (lines 295, 306, 321) — Phase A instruments these, does not change them.
- `_require_role` (`MY_Controller.php:677`) already emits `log_message('error', 'RBAC denied: …')` — Phase A layers structured telemetry on top.
- **No existing `security_events` or `Security_telemetry` infrastructure.** New collections + library required.
- **Server-side writes bypass Firestore rules** (Admin SDK service account). New collections `staffAuditLog`, `security_events`, `staff_metrics_daily` can be added without rule changes as long as access stays server-side. This satisfies the "DO NOT modify Firestore rules" constraint.

---

## 1. Impact Analysis

### 1.1 What This Phase Adds

| Layer | Addition | User-Visible Behavior Change |
|---|---|---|
| PHP backend | Structured audit-log calls on 9 Staff endpoints | None — observable to admins via future dashboard only |
| PHP backend | New `Security_telemetry` library emitting `[STAFF_SECURITY] …` log lines + `security_events` Firestore doc | None |
| PHP backend | Structured failure logs in 7 silent-failure zones | None — failure path now logs |
| Firestore | New collections: `staffAuditLog`, `security_events`, `cascade_failures`, `staff_metrics_daily` | None — server-only access |
| Firestore | Whitelist extension in `Audit_log_service::ENTITY_TYPES` to accept staff entity types | None — additive |
| Mobile apps (Teacher / Parent) | **Not touched in this phase.** Mobile rule-denial catcher is a Phase A-2 follow-up | None |
| Firestore rules | **Not modified.** New collections written by service account, server-only | None |
| Storage rules | **Not modified.** | None |
| Auth | **Not modified.** | None |

### 1.2 What This Phase Explicitly Does Not Do

- No authentication changes
- No rule changes (Firestore or Storage)
- No encryption
- No password-rotation enforcement
- No mobile app changes (deferred to Phase A-2 as a separate authorisation)
- No refactor of `Audit_log_service` internals — only whitelist + collection name extension
- No removal of existing `log_message()` calls — telemetry is layered on, not replaced

### 1.3 Blast Radius

- **Server PHP:** Staff.php (~15 insertion points), MY_Controller.php (1 insertion), new library file. All changes are additive.
- **Firestore:** 4 new collections, all server-only write. No reads from any client app.
- **Performance:** Each new audit/telemetry write costs 1 Firestore write per write endpoint invocation. New cost per staff create: +2 writes (audit + telemetry-on-failure-only). Negligible at observed staff-create rates (low single-digit per school per day).
- **Latency:** Audit/telemetry writes are best-effort and wrapped in try/catch. Failure of audit write does not block the user action (this is the existing `Audit_log_service` semantic; preserved).
- **Storage:** Zero — no new files written.

### 1.4 Reversibility

100% reversible. Every change is additive. Rollback is `git revert` per patch, plus optionally a one-shot delete of the 4 new collections.

---

## 2. Files Affected

### 2.1 Created (Net New)

| File | Purpose |
|---|---|
| `application/libraries/Security_telemetry.php` | Emits structured log line + Firestore doc to `security_events`; thin wrapper around `firebase->firestoreSet()` |
| `application/controllers/Security_metrics_cron.php` | Scheduled aggregator: rolls daily counts of staff create/edit/deactivate/role-change/denial-events into `staff_metrics_daily` |
| `application/config/security_telemetry.php` | Thresholds + sampling config (read-only, declarative) |

### 2.2 Modified (Additive Edits)

| File | Edits | Risk |
|---|---|---|
| `application/libraries/Audit_log_service.php` | Add staff entity types to whitelist; add optional `collection` param to `init()` so the same service can target `staffAuditLog` | Very low — additive whitelist |
| `application/controllers/Staff.php` | Add audit-log calls + telemetry calls at 9 endpoints + 7 silent-failure zones | Low — best-effort wraps |
| `application/core/MY_Controller.php` | Add `Security_telemetry` emission in `_require_role` failure path (line 680) | Very low — additive in already-failure path |
| `application/config/autoload.php` | Add `Security_telemetry` to autoload (or load on-demand in controllers) | Trivial |

### 2.3 Untouched (Explicit Negative List)

- `firebase-rules/firestore.rules` — not opened
- `firebase-rules/storage.rules` — not opened
- `application/libraries/Firebase.php` — not opened
- `application/views/new_staff.php` — not opened (no UI changes)
- `application/views/edit_staff.php` — not opened
- All Teacher app Kotlin files — not opened
- All Parent app Kotlin files — not opened

---

## 3. Telemetry Architecture

### 3.1 Data Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│  ADMIN PANEL (PHP)                                                   │
│                                                                      │
│  Staff.php endpoint                                                  │
│       │                                                              │
│       ├─► On success:                                                │
│       │     ├─► Audit_log_service::log(...)                          │
│       │     │       └─► firestoreSet('staffAuditLog', logId, doc)    │
│       │     │                                                        │
│       │     └─► log_message('info', '[STAFF_AUDIT] action=… …')      │
│       │                                                              │
│       ├─► On failure / suspicious pattern:                           │
│       │     ├─► Security_telemetry::emit(...)                        │
│       │     │       ├─► firestoreSet('security_events', evtId, doc)  │
│       │     │       └─► log_message('warning', '[STAFF_SECURITY] …') │
│       │     │                                                        │
│       │     └─► (continues with existing failure response)           │
│       │                                                              │
│       └─► On silent failure (cascade row, FCM cleanup, upload):      │
│             └─► log_message('error', '[STAFF_CASCADE row=… …]')      │
│             └─► firestoreSet('cascade_failures', …) [non-blocking]   │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼ (server-side Firebase Admin SDK)
┌─────────────────────────────────────────────────────────────────────┐
│  FIRESTORE — Server-Only Collections                                 │
│                                                                      │
│  staffAuditLog/{logId}        — operational truth (who/what/when)    │
│  security_events/{evtId}      — adversarial-signal stream            │
│  cascade_failures/{cfId}      — silent-failure dead-letter queue     │
│  staff_metrics_daily/{date}   — rolled daily counts                  │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼ (daily cron)
┌─────────────────────────────────────────────────────────────────────┐
│  Security_metrics_cron::aggregate()                                  │
│       │                                                              │
│       ├─► Reads yesterday's security_events                          │
│       ├─► Aggregates by event_type, school_id, actor_id              │
│       └─► Writes staff_metrics_daily/{date} with counts + thresholds │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼ (alert hooks — read-only consumer)
┌─────────────────────────────────────────────────────────────────────┐
│  Admin Panel — future operational dashboard (NOT BUILT IN PHASE A)   │
│  Slack/email/PagerDuty — future integration (NOT BUILT IN PHASE A)   │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.2 Collection Schemas

#### `staffAuditLog/{logId}` — Operational Audit Truth

Reuses `Audit_log_service`'s existing doc shape (already battle-tested) with the entity-type whitelist extended.

```json
{
  "logId":      "{schoolId}_{YYYYMMDDTHHMMSS}_{8hex}",
  "schoolId":   "SCH_XXXXXX",
  "session":    "2026-27",
  "ts":         "2026-05-17T14:23:11+05:30",
  "createdAt":  "2026-05-17T14:23:11+05:30",
  "action":     "create | update | delete | status_change | role_change",
  "entityType": "staff | staffRole | staffDocument | staffPassword",
  "entityId":   "SSA0042",
  "actor": {
    "uid":      "ADMIN_001",
    "name":     "Asha Kulkarni",
    "role":     "School Super Admin"
  },
  "before":     { "primary_role": "ROLE_TEACHER" },
  "after":      { "primary_role": "ROLE_HOD" },
  "metadata": {
    "ip":             "203.0.113.4",
    "user_agent":     "Mozilla/5.0 …",
    "correlation_id": "req_a1b2c3d4",
    "masked_fields":  ["panNumber", "aadharNumber", "bankAccount", "password"]
  }
}
```

Doc ID format inherits from `Audit_log_service::_buildLogId()`. Naturally sortable.

#### `security_events/{evtId}` — Adversarial Signal Stream

```json
{
  "evtId":           "{YYYYMMDDTHHMMSS}_{actor_uid}_{8hex}",
  "ts":              "2026-05-17T14:23:11+05:30",
  "schoolId":        "SCH_XXXXXX",
  "event_type":      "RBAC_DENIED | BULK_STAFF_READ | DUPLICATE_PHONE_RACE | RULE_DENIAL | ESCALATION_ATTEMPT | DEACTIVATED_LOGIN | UPLOAD_REJECTED | CASCADE_PARTIAL | STALE_SESSION_WRITE",
  "severity":        "info | warning | error | critical",
  "actor": {
    "uid":           "USER_XYZ",
    "role":          "Teacher",
    "school_claim":  "SCH_XXXXXX"
  },
  "subject":         { "type": "staff", "id": "SSA0042" },
  "context": {
    "endpoint":      "Staff::new_staff",
    "ip":            "203.0.113.4",
    "user_agent":    "…",
    "correlation_id":"req_…",
    "payload_hash":  "sha256:…"
  },
  "detail": {
    "attempted_action": "POST /staff/save_staff",
    "denied_reason":    "role=Teacher not in [School Super Admin, Admin]"
  },
  "resolved":        false
}
```

#### `cascade_failures/{cfId}` — Silent-Failure Dead Letter

```json
{
  "cfId":           "{YYYYMMDDTHHMMSS}_{operation}_{8hex}",
  "ts":             "…",
  "schoolId":       "…",
  "operation":      "cascade_subject_assignments | fcm_token_cleanup | disable_firebase_user | thumbnail_generation | phone_index_write",
  "subject":        { "type": "staff", "id": "SSA0042" },
  "step":           "subjectAssignments_update",
  "step_index":     3,
  "total_steps":    5,
  "error_class":    "FirestoreException",
  "error_message":  "PERMISSION_DENIED: …",
  "retry_count":    0,
  "resolved":       false,
  "correlation_id": "req_…"
}
```

#### `staff_metrics_daily/{YYYY-MM-DD}` — Rolled Daily Counts

```json
{
  "date":           "2026-05-17",
  "schoolId":       "SCH_XXXXXX",
  "generated_at":   "2026-05-18T00:30:00+05:30",
  "counts": {
    "staff_create":        4,
    "staff_edit":          17,
    "staff_role_change":   2,
    "staff_deactivate":    1,
    "staff_reactivate":    0,
    "document_upload":     12,
    "pan_update_attempt":  0,
    "cascade_failure":     0,
    "rbac_denied":         3,
    "duplicate_phone":     1,
    "upload_rejected":     0
  },
  "thresholds_exceeded": [],
  "top_actors_by_writes": [ { "uid": "…", "count": 12 } ],
  "top_endpoints":        [ { "ep": "edit_staff", "count": 14 } ]
}
```

### 3.3 Cardinality & Cost Estimate

Based on assumed mid-size institution (1,500 staff, 50 admin users):

- `staffAuditLog`: ~20–50 docs/day per school
- `security_events`: ~5–30 docs/day per school (most days near zero; spikes on probe attempts)
- `cascade_failures`: target zero per day (every entry = action item)
- `staff_metrics_daily`: 1 doc per school per day

Cumulative storage growth: < 100 MB / year / school. Well within free-tier budgets.

---

## 4. Audit Log Schema

Already covered structurally in §3.2. Key normalisations:

### 4.1 Masking Discipline (Strict)

Fields that must be **masked** before any audit-log or telemetry write:

| Field | Mask Rule |
|---|---|
| `password` / `Password` | Always replace with `"[REDACTED]"` |
| `panNumber` | Last 4 digits with leading mask: `"XXXXX1234X"` |
| `aadharNumber` | Last 4 digits: `"XXXX XXXX 1234"` |
| `bankDetails.accountNumber` | Last 4: `"****1234"` |
| `pfNumber`, `esiNumber` | Last 4: `"****1234"` |
| `Credentials` (the whole sub-object) | Strip from before/after diff |
| Any field with key `*_token`, `*_secret` | `"[REDACTED]"` |

A central `_mask_sensitive($map)` helper in `Security_telemetry` will be used by all callers; never reimplement inline.

### 4.2 Before/After Diff Rule

Only changed fields are stored. The diff helper:

1. Compare old map and new map key-by-key
2. Emit only keys whose value changed
3. For sensitive keys, replace value with `"[MASKED]"` on both sides
4. Cap to 50 keys total (existing `Audit_log_service::MAX_ARR_ITEMS`)

### 4.3 Correlation ID

Every web request gets a `correlation_id` injected at the controller boundary (e.g., `req_` + 8 hex). Logged on every audit and telemetry event for the request. Propagated to cascade failure docs so partial failures can be reconstructed end-to-end.

---

## 5. Logging Strategy

### 5.1 Log Prefix Catalog

| Prefix | Severity Default | Where Emitted |
|---|---|---|
| `[STAFF_AUDIT]` | info | Staff.php success boundaries |
| `[STAFF_SECURITY]` | warning | Security_telemetry — every emit |
| `[STAFF_CASCADE]` | error | Cascade per-row failures |
| `[STAFF_UPLOAD]` | warning/error | uploadStaffFile failure paths |
| `[STAFF_AUTH]` | error | _disable_firebase_user / _enable_firebase_user partial states |
| `[STAFF_PHONE_INDEX]` | warning | Phone index TOCTOU detection |
| `[STAFF_METRICS]` | info | Security_metrics_cron output |

Each log line is **structured kv format**:

```
[STAFF_AUDIT] action=create entity=staff id=SSA0042 actor=ADMIN_001 school=SCH_ABC123 correlation_id=req_a1b2c3d4 ms=124
[STAFF_SECURITY] type=RBAC_DENIED actor=USER_XYZ role=Teacher endpoint=Staff::new_staff school=SCH_ABC123 correlation_id=req_…
[STAFF_CASCADE] op=subjectAssignments_archive subject=SSA0042 step=3/5 result=fail err="PERMISSION_DENIED"
[STAFF_UPLOAD] result=rejected reason=mime_blacklist mime=application/x-msdownload ext=jpg staff=SSA0042
```

Format is parseable by stdlib log scrapers (Loki, Datadog, Cloud Logging structured-log auto-detect).

### 5.2 Severity Classification

| Class | Severity | Examples |
|---|---|---|
| **Information** | info | Normal staff create/edit/deactivate audit lines |
| **Warning** | warning | RBAC denied (single occurrence), upload rejected, phone-index collision detected, cascade row retried |
| **Error** | error | Cascade row terminal failure after retries, FCM cleanup failed, audit log write failed |
| **Critical** | critical | Cross-tenant write attempt blocked by server-side check, password leakage detected, suspected escalation chain |

Severity drives downstream alerting (Phase A-3 / Phase B+). In Phase A we **emit** severity but do **not** page on it.

### 5.3 Sampling

No sampling in Phase A. Every event is logged. Reasoning:

- Volume is low (<100 events/day/school combined)
- Phase A's purpose is calibration — we need full-fidelity baseline data before tuning thresholds
- Sampling can be added later (Phase A-3) once baseline is established

---

## 6. Alert Strategy

### 6.1 Alert Hooks — Schema Only, No Paging in Phase A

Each alert is a *named threshold* declared in `config/security_telemetry.php` and evaluated by `Security_metrics_cron`. Output goes into `staff_metrics_daily.thresholds_exceeded[]`. **No external paging integration in Phase A.**

```php
// application/config/security_telemetry.php  (declarative)
$config['security_thresholds'] = [
    'bulk_staff_read_per_hour'        => 50,
    'cross_tenant_rule_denials_per_day' => 3,
    'rbac_denied_per_user_per_day'    => 10,
    'cascade_failures_per_day'        => 1,    // any cascade failure trips
    'staff_create_per_school_per_day' => 20,   // anomaly: bulk create
    'role_change_per_user_per_day'    => 5,    // anomaly: rapid role churn
    'storage_downloads_per_user_per_hour' => 100,
    'duplicate_phone_per_day'         => 3,
    'pan_update_attempt_per_day'      => 1,    // any attempt warrants review
    'escalation_attempt_per_day'      => 1,    // any attempt is critical
];
```

### 6.2 Alert Lifecycle (Phase A → later)

```
Phase A:    Threshold breach → write entry in staff_metrics_daily.thresholds_exceeded[]
              ↓
              (Phase A-3 — not in scope) → emit to a new alerts/{alertId} collection
              ↓
              (Phase B+) → paging integration (Slack/PagerDuty/email)
              ↓
              (Phase B+) → operational dashboard
```

Phase A's job is to **make alerts observable in Firestore**. Phase A-3 introduces the alert collection. Paging comes after Phase A is stable for one week.

### 6.3 Alert Suppression

Same alert from the same actor within a deduplication window (default 4 hours) is suppressed at the threshold-evaluation layer. Prevents alert storms during sustained probe attempts. Configured in `config/security_telemetry.php`.

---

## 7. Operational Risks

### 7.1 Risks Introduced by Phase A

| Risk | Severity | Mitigation |
|---|---|---|
| Audit-log write failure blocks user action | Critical if it happens | Wrapped in try/catch; failure logged via `log_message('error', …)`; user action proceeds — preserves `Audit_log_service` existing semantic (line 145-148) |
| Telemetry write storm under attack | Medium | Server-side dedup at threshold-evaluation layer; no per-request external call |
| Firestore cost spike | Low | Estimated <100 MB/year/school; well within free tier |
| Sensitive data accidentally logged | High | Centralised `_mask_sensitive()` helper; mandatory call from every audit/telemetry emitter; PHP unit test covers known sensitive keys |
| Audit-log collection growth unbounded | Medium | Phase A-3 introduces a 365-day TTL via Cloud Function or batch cleanup; until then, low write rate makes growth tolerable |
| Performance regression on Staff endpoints | Low | Audit/telemetry adds 1-2 Firestore writes per endpoint (~50-100ms p99); acceptable for admin workflows |
| Correlation ID collision | Negligible | 8 hex chars random + timestamp — collision space ~10^-9 per request |
| `Audit_log_service` whitelist extension breaks academic-planner | Low | Whitelist is append-only; no removal of existing types |

### 7.2 Risks NOT Introduced by Phase A

(Stated explicitly for the operator's confidence.)

- No risk of locking out users — no auth changes
- No risk of breaking writes — no rule changes
- No risk of file-access regression — no storage rule changes
- No risk of mobile-app crash — no mobile changes
- No risk of data corruption — no schema migration, no field renames, no encryption rollout
- No risk of credential rotation lockout — no password flow changes

### 7.3 Pre-Existing Risks Phase A Does Not Solve

Phase A makes the following risks **observable** but does NOT mitigate them. These are addressed in later phases per the Phase 0 plan:

- PII still stored plaintext (Phase D)
- Password still predictable (Phase B)
- Tenant boundary still has gaps (Phase C)
- Force-rotate still not enforced (Phase E)
- Cascade still not atomic (Phase F)

---

## 8. Exact Code Changes

### 8.1 Patch Plan Summary

| # | Patch | File | Risk | Lines | Ready in This Phase |
|---|---|---|---|---|---|
| 1 | Generalise `Audit_log_service` (collection + entity types) | `application/libraries/Audit_log_service.php` | Very low | ~20 | ✅ Code in §8.2 |
| 2 | Create `Security_telemetry` library | `application/libraries/Security_telemetry.php` (new) | Low | ~150 | ✅ Code in §8.3 |
| 3 | Create `config/security_telemetry.php` | `application/config/security_telemetry.php` (new) | Trivial | ~40 | ✅ Code in §8.4 |
| 4 | `MY_Controller::_require_role` failure telemetry | `application/core/MY_Controller.php` | Very low | ~10 | ✅ Code in §8.5 |
| 5 | `MY_Controller` — correlation ID helper | `application/core/MY_Controller.php` | Very low | ~12 | ✅ Code in §8.6 |
| 6 | Staff.php — `_staff_audit()` helper | `application/controllers/Staff.php` | Low | ~30 | Awaiting auth |
| 7 | Staff.php — audit log in `new_staff` | `application/controllers/Staff.php` | Low | ~25 | Awaiting auth |
| 8 | Staff.php — audit log in `edit_staff` (with diff) | `application/controllers/Staff.php` | Low | ~40 | Awaiting auth |
| 9 | Staff.php — audit log in `set_status` (convert existing `log_message`) | `application/controllers/Staff.php` | Low | ~15 | Awaiting auth |
| 10 | Staff.php — audit log in `save_staff_role`, `delete_staff_role` | `application/controllers/Staff.php` | Low | ~20 | Awaiting auth |
| 11 | Staff.php — structured cascade-row logs in `_cascade_subject_assignments` | `application/controllers/Staff.php` | Low | ~25 | Awaiting auth |
| 12 | Staff.php — structured logs in `_disable_firebase_user` / `_enable_firebase_user` | `application/controllers/Staff.php` | Low | ~20 | Awaiting auth |
| 13 | Staff.php — `uploadStaffFile` failure-mode logs | `application/controllers/Staff.php` | Low | ~15 | Awaiting auth |
| 14 | Staff.php — phone-index TOCTOU detection telemetry | `application/controllers/Staff.php` | Low | ~10 | Awaiting auth |
| 15 | `Security_metrics_cron` controller (daily aggregator) | `application/controllers/Security_metrics_cron.php` (new) | Low | ~120 | Awaiting auth |

**Proposed execution order: 1 → 2 → 3 → 4 → 5 (foundation), pause for review, then 6 → 14 (Staff.php instrumentation in 3 sub-batches), then 15 (cron).**

---

### 8.2 Patch 1 — Generalise `Audit_log_service` (foundation)

**Intent:** Extend the existing service to accept a configurable collection name and additional entity types. Existing academic-planner callers untouched (default collection unchanged).

**Edit target:** `application/libraries/Audit_log_service.php`

Two minimal changes:

1. `ENTITY_TYPES` constant becomes a class property loadable from init parameters (or remains constant + whitelist is layered).
2. `init()` accepts an optional `$collectionName` parameter; falls back to `self::COLLECTION` ('academicAuditLog') if not supplied.

**Proposed change (additive):**

```php
class Audit_log_service
{
    /** @var object Firebase library instance (has firestoreSet, etc.) */
    private $firebase = null;
    /** @var string */
    private $schoolId = '';
    /** @var string */
    private $session = '';
    /** @var array{uid:string,name:string,role:string} */
    private $defaultActor = ['uid' => '', 'name' => '', 'role' => ''];
    /** @var bool */
    private $ready = false;

    // ── PHASE A ADDITION ─────────────────────────────────────────────────────
    /** @var string  Per-instance collection target (defaults to COLLECTION) */
    private $collection = self::COLLECTION;
    /** @var array  Per-instance entity-type allowlist union (academic + extras) */
    private $entityTypes = self::ENTITY_TYPES;
    // ─────────────────────────────────────────────────────────────────────────

    const COLLECTION    = 'academicAuditLog';
    const ACTIONS       = ['create', 'update', 'delete', 'status_change', 'rollover', 'generation', 'role_change'];
    const ENTITY_TYPES  = ['curriculum', 'curriculumTopic', 'timetable', 'timetableSettings',
                           'substitute', 'calendarEvent', 'subjectAssignment'];

    // ── PHASE A ADDITION ─────────────────────────────────────────────────────
    /** Allowlist of entity types valid in the staff audit context */
    const STAFF_ENTITY_TYPES = ['staff', 'staffRole', 'staffDocument', 'staffPassword', 'staffStatus'];
    // ─────────────────────────────────────────────────────────────────────────

    const MAX_STR_LEN   = 500;
    const MAX_ARR_ITEMS = 50;

    /**
     * Bind dependencies. Idempotent — safe to re-init.
     *
     * @param object $firebase       Firebase library instance
     * @param string $schoolId
     * @param string $session
     * @param array  $defaultActor   ['uid'=>..., 'name'=>..., 'role'=>...]
     * @param string $collectionName Optional — defaults to academicAuditLog. Phase A: pass
     *                               'staffAuditLog' for staff-audit usage.
     * @param array  $extraEntityTypes Optional — additional entity types to accept
     *                                  beyond the academic defaults. Phase A: pass
     *                                  Audit_log_service::STAFF_ENTITY_TYPES.
     */
    public function init(
        $firebase,
        string $schoolId,
        string $session,
        array $defaultActor = [],
        string $collectionName = '',
        array $extraEntityTypes = []
    ): self {
        $this->firebase = $firebase;
        $this->schoolId = (string) $schoolId;
        $this->session  = (string) $session;
        $this->defaultActor = [
            'uid'  => (string) ($defaultActor['uid']  ?? ''),
            'name' => (string) ($defaultActor['name'] ?? ''),
            'role' => (string) ($defaultActor['role'] ?? ''),
        ];
        // ── PHASE A ADDITION ─────────────────────────────────────────────────
        $this->collection = $collectionName !== '' ? $collectionName : self::COLLECTION;
        $this->entityTypes = empty($extraEntityTypes)
            ? self::ENTITY_TYPES
            : array_values(array_unique(array_merge(self::ENTITY_TYPES, $extraEntityTypes)));
        // ─────────────────────────────────────────────────────────────────────
        $this->ready = ($firebase !== null && $this->schoolId !== '');
        return $this;
    }
```

Then change two lookups:

- Line 109: `if (!in_array($entityType, self::ENTITY_TYPES, true))` → `if (!in_array($entityType, $this->entityTypes, true))`
- Line 143: `$this->firebase->firestoreSet(self::COLLECTION, …)` → `$this->firebase->firestoreSet($this->collection, …)`

That's it. The full diff is ~18 lines added, 2 lines modified. Zero risk to existing academic-planner callers.

---

### 8.3 Patch 2 — `Security_telemetry` Library

**Intent:** Provide a single emission point for security-signal events. Writes both a structured log line and a Firestore doc to `security_events/{evtId}`. Best-effort.

**File:** `application/libraries/Security_telemetry.php` (new)

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Security_telemetry — emits adversarial-signal events.
 *
 *  USAGE
 *    $this->load->library('security_telemetry', null, 'sec_telem');
 *    $this->sec_telem->init($this->firebase, $this->school_id, [
 *        'uid' => $this->admin_id, 'role' => $this->admin_role,
 *    ]);
 *
 *    $this->sec_telem->emit('RBAC_DENIED', 'warning', [
 *        'endpoint'       => 'Staff::new_staff',
 *        'attempted_role' => 'School Super Admin',
 *        'denied_reason'  => 'role=Teacher not in MANAGE_ROLES',
 *    ]);
 *
 *  SEMANTICS
 *    - Best-effort: any error in the Firestore write is swallowed and
 *      logged via log_message('error', ...). Never blocks the caller.
 *    - Always emits a structured log_message line first; Firestore write
 *      is secondary.
 *    - Auto-masks any keys matching SENSITIVE_KEY_PATTERN.
 *    - Adds correlation_id from the parent controller if present.
 *
 *  EVENT TYPES (allowlist — extend deliberately, not casually)
 *    RBAC_DENIED            — _require_role rejected the actor
 *    RULE_DENIAL            — Firestore rule denied a server write (rare)
 *    BULK_STAFF_READ        — actor read N+ staff docs in one window
 *    DUPLICATE_PHONE_RACE   — TOCTOU detected in phone-index write
 *    ESCALATION_ATTEMPT     — actor tried to assign a role they cannot grant
 *    DEACTIVATED_LOGIN      — disabled user attempted login (server-side)
 *    UPLOAD_REJECTED        — staff document upload rejected at server
 *    CASCADE_PARTIAL        — deactivation cascade completed with row failures
 *    STALE_SESSION_WRITE    — pre-deactivation session attempted write post-revoke
 *    PAN_UPDATE_ATTEMPTED   — PAN field edit detected
 *    CROSS_TENANT_PROBE     — server-side cross-tenant read/write blocked
 */
class Security_telemetry
{
    private $firebase = null;
    private $schoolId = '';
    private $defaultActor = ['uid' => '', 'role' => '', 'school_claim' => ''];
    private $ready = false;
    private $correlationId = '';

    const COLLECTION = 'security_events';

    const EVENT_TYPES = [
        'RBAC_DENIED', 'RULE_DENIAL', 'BULK_STAFF_READ', 'DUPLICATE_PHONE_RACE',
        'ESCALATION_ATTEMPT', 'DEACTIVATED_LOGIN', 'UPLOAD_REJECTED',
        'CASCADE_PARTIAL', 'STALE_SESSION_WRITE', 'PAN_UPDATE_ATTEMPTED',
        'CROSS_TENANT_PROBE',
    ];

    const SEVERITIES = ['info', 'warning', 'error', 'critical'];

    /** Keys whose values are masked before being included in `detail`. */
    const SENSITIVE_KEY_PATTERN = '/(password|panNumber|aadhar|account|bankDetails|pfNumber|esiNumber|credentials|token|secret)/i';

    public function init($firebase, string $schoolId, array $defaultActor = [], string $correlationId = ''): self
    {
        $this->firebase = $firebase;
        $this->schoolId = (string) $schoolId;
        $this->defaultActor = [
            'uid'          => (string) ($defaultActor['uid']  ?? ''),
            'role'         => (string) ($defaultActor['role'] ?? ''),
            'school_claim' => (string) ($defaultActor['school_claim'] ?? $schoolId),
        ];
        $this->correlationId = $correlationId;
        $this->ready = ($firebase !== null && $this->schoolId !== '');
        return $this;
    }

    public function isReady(): bool { return $this->ready; }

    /**
     * Emit a security event.
     *
     * @param string $eventType   one of EVENT_TYPES
     * @param string $severity    one of SEVERITIES
     * @param array  $detail      event-specific context (will be masked)
     * @param array  $subject     optional ['type'=>'staff', 'id'=>'SSA0042']
     * @return bool true on success (log+firestore), false on partial/total failure
     */
    public function emit(string $eventType, string $severity, array $detail = [], array $subject = []): bool
    {
        if (!$this->ready) {
            log_message('error', 'security_telemetry not initialised; dropped event ' . $eventType);
            return false;
        }
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            log_message('error', 'security_telemetry: invalid event_type=' . $eventType);
            return false;
        }
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'warning';
        }

        $maskedDetail = $this->_maskSensitive($detail);
        $now = date('c');
        $evtId = $this->_buildEvtId($now);
        $logLevel = $this->_severityToLogLevel($severity);

        // 1) STRUCTURED LOG LINE — always fires, even if Firestore write fails
        $kv = [
            'type=' . $eventType,
            'severity=' . $severity,
            'actor=' . $this->defaultActor['uid'],
            'role=' . $this->defaultActor['role'],
            'school=' . $this->schoolId,
            'correlation_id=' . $this->correlationId,
        ];
        if (!empty($subject)) {
            $kv[] = 'subject_type=' . ($subject['type'] ?? '');
            $kv[] = 'subject_id=' . ($subject['id'] ?? '');
        }
        foreach ($maskedDetail as $k => $v) {
            if (is_scalar($v)) {
                $kv[] = $k . '=' . str_replace(' ', '_', (string)$v);
            }
        }
        log_message($logLevel, '[STAFF_SECURITY] ' . implode(' ', $kv));

        // 2) FIRESTORE WRITE — best-effort
        $doc = [
            'evtId'      => $evtId,
            'ts'         => $now,
            'schoolId'   => $this->schoolId,
            'event_type' => $eventType,
            'severity'   => $severity,
            'actor'      => $this->defaultActor,
            'subject'    => empty($subject) ? null : $subject,
            'context'    => [
                'ip'             => $this->_clientIp(),
                'user_agent'     => $this->_userAgent(),
                'correlation_id' => $this->correlationId,
            ],
            'detail'     => $maskedDetail,
            'resolved'   => false,
        ];

        try {
            $this->firebase->firestoreSet(self::COLLECTION, $evtId, $doc);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'security_telemetry firestore write failed: ' . $e->getMessage());
            return false;
        }
    }

    private function _buildEvtId(string $isoTs): string
    {
        $compact = preg_replace('/[^0-9T]/', '', $isoTs);
        if (strlen($compact) > 15) $compact = substr($compact, 0, 15);
        $rand = function_exists('random_bytes')
            ? bin2hex(random_bytes(4))
            : substr(md5(uniqid('', true)), 0, 8);
        $actor = $this->defaultActor['uid'] !== '' ? $this->defaultActor['uid'] : 'anon';
        return "{$compact}_{$actor}_{$rand}";
    }

    /**
     * Mask any value whose key matches the sensitive-key pattern.
     * Nested arrays recurse. Non-sensitive scalars pass through unchanged.
     */
    private function _maskSensitive(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string)$k)) {
                $out[$k] = '[MASKED]';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $this->_maskSensitive($v);
            } elseif (is_string($v) && strlen($v) > 500) {
                $out[$k] = substr($v, 0, 500) . '...';
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function _severityToLogLevel(string $severity): string
    {
        switch ($severity) {
            case 'info':     return 'info';
            case 'warning':  return 'info';   // CI3 has no 'warning' level; emit as info
            case 'error':    return 'error';
            case 'critical': return 'error';
        }
        return 'info';
    }

    private function _clientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $ip) {
            if ($ip !== '') {
                $first = trim(explode(',', $ip)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
            }
        }
        return '';
    }

    private function _userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 256);
    }
}
```

---

### 8.4 Patch 3 — `config/security_telemetry.php`

**File:** `application/config/security_telemetry.php` (new)

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Phase A — Security telemetry thresholds & sampling config.
 * Read-only at runtime. Tuned during soak before Phase B.
 */

// ── DEDUPE WINDOW ───────────────────────────────────────────────────────────
// Repeats of the same (event_type, actor_uid) within this window count once
// against thresholds. Prevents alert storms during sustained probing.
$config['security_dedupe_window_seconds'] = 14400; // 4 hours

// ── DAILY THRESHOLDS — breach = entry in staff_metrics_daily.thresholds_exceeded[]
// Phase A: observe-only. No paging. Tune after 1-week baseline.
$config['security_thresholds'] = [
    'bulk_staff_read_per_hour'            => 50,
    'cross_tenant_rule_denials_per_day'   => 3,
    'rbac_denied_per_user_per_day'        => 10,
    'cascade_failures_per_day'            => 1,
    'staff_create_per_school_per_day'     => 20,
    'role_change_per_user_per_day'        => 5,
    'storage_downloads_per_user_per_hour' => 100,
    'duplicate_phone_per_day'             => 3,
    'pan_update_attempt_per_day'          => 1,
    'escalation_attempt_per_day'          => 1,
    'deactivated_login_per_day'           => 1,
    'upload_rejected_per_user_per_day'    => 5,
];

// ── SAMPLING ────────────────────────────────────────────────────────────────
// 1.0 = log every event (Phase A baseline). Lower to 0.1 once volume justifies.
$config['security_event_sample_rate'] = 1.0;

// ── COLLECTION NAMES ────────────────────────────────────────────────────────
$config['security_collections'] = [
    'audit_log'         => 'staffAuditLog',
    'security_events'   => 'security_events',
    'cascade_failures'  => 'cascade_failures',
    'metrics_daily'     => 'staff_metrics_daily',
];
```

---

### 8.5 Patch 4 — `_require_role` Failure Telemetry

**Intent:** Layer `Security_telemetry::emit('RBAC_DENIED', ...)` into the existing failure path. No behavior change — the existing `log_message` + `json_error`/redirect flow is preserved.

**File:** `application/core/MY_Controller.php` (insertion at line ~680)

```php
    protected function _require_role(array $allowed, string $action = ''): void
    {
        $role = $this->admin_role ?? '';

        // Super Admin and School Super Admin always pass
        if (strcasecmp($role, 'Super Admin') === 0) return;
        if (strcasecmp($role, 'School Super Admin') === 0) return;

        // Case-insensitive role match (Firebase role values may vary in casing)
        foreach ($allowed as $a) {
            if (strcasecmp($role, $a) === 0) return;
        }

        $label = $action ? " ({$action})" : '';
        log_message('error',
            "RBAC denied: role=[{$role}] admin=[{$this->admin_id}]"
            . " school=[{$this->school_name}]{$label}"
        );

        // ── PHASE A ADDITION ─────────────────────────────────────────────────
        // Structured telemetry — best-effort, never blocks the deny response.
        try {
            $this->load->library('security_telemetry', null, 'sec_telem');
            if (!$this->sec_telem->isReady()) {
                $this->sec_telem->init(
                    $this->firebase,
                    $this->school_id,
                    ['uid' => $this->admin_id, 'role' => $role, 'school_claim' => $this->school_id],
                    $this->_correlation_id()
                );
            }
            $this->sec_telem->emit('RBAC_DENIED', 'warning', [
                'endpoint'        => $this->router->fetch_class() . '::' . $this->router->fetch_method(),
                'requested_role'  => $action !== '' ? $action : implode(',', $allowed),
                'denied_reason'   => "role=[{$role}] not in allowed list",
            ]);
        } catch (\Throwable $e) {
            // never break the deny path
            log_message('error', '_require_role telemetry failed: ' . $e->getMessage());
        }
        // ─────────────────────────────────────────────────────────────────────

        if ($this->input->is_ajax_request()) {
            $this->json_error('You do not have permission to perform this action.', 403);
        }

        $this->session->set_flashdata('error', 'You do not have access to that page.');
        redirect('admin/index');
    }
```

---

### 8.6 Patch 5 — Correlation-ID Helper

**Intent:** A single per-request correlation ID, accessible to all controllers. Generated lazily on first call.

**File:** `application/core/MY_Controller.php` (add as a new protected helper)

```php
    // ── PHASE A ADDITION ─────────────────────────────────────────────────────
    /** @var string  Cached per-request correlation id */
    private $_correlation_id_cache = '';

    /**
     * Per-request correlation ID. Stable for the lifetime of the HTTP request.
     * Lazy-generated; safe to call from any controller.
     *
     * Format: "req_" + 8 lowercase hex chars.
     */
    protected function _correlation_id(): string
    {
        if ($this->_correlation_id_cache !== '') {
            return $this->_correlation_id_cache;
        }
        $rand = function_exists('random_bytes')
            ? bin2hex(random_bytes(4))
            : substr(md5(uniqid('', true)), 0, 8);
        $this->_correlation_id_cache = 'req_' . $rand;
        return $this->_correlation_id_cache;
    }
    // ─────────────────────────────────────────────────────────────────────────
```

---

### 8.7 Patches 6–14 — Staff.php Instrumentation

Held back from full code presentation pending review of patches 1–5. Each follows the same pattern:

```php
// Patch 6: _staff_audit() helper (one-time setup at top of Staff.php)
private function _staff_audit(string $action, string $entityType, string $entityId,
                              ?array $before = null, ?array $after = null,
                              array $metadata = []): void {
    try {
        $this->load->library('audit_log_service', null, 'audit');
        if (!$this->audit->isReady()) {
            $this->audit->init(
                $this->firebase, $this->school_id, $this->session_year,
                ['uid' => $this->admin_id, 'name' => $this->admin_name, 'role' => $this->admin_role],
                'staffAuditLog',
                Audit_log_service::STAFF_ENTITY_TYPES
            );
        }
        $maskedBefore = $before === null ? null : $this->_mask_staff_diff($before);
        $maskedAfter  = $after  === null ? null : $this->_mask_staff_diff($after);
        $meta = array_merge($metadata, [
            'correlation_id' => $this->_correlation_id(),
            'ip'             => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        $this->audit->log($action, $entityType, $entityId, $maskedBefore, $maskedAfter, $meta);
    } catch (\Throwable $e) {
        log_message('error', '_staff_audit failed: ' . $e->getMessage());
    }
}
```

Patches 7–14 are 1–4 line insertions at success/failure boundaries of each Staff.php endpoint, calling `_staff_audit()` or `_sec_telem()->emit()`. Concrete insertion points by line are documented in §2.2 but the code is held until patches 1–5 are reviewed and approved, so that any feedback on the foundation propagates.

---

### 8.8 Patch 15 — `Security_metrics_cron`

Held back pending review. Skeleton:

```php
class Security_metrics_cron extends CI_Controller
{
    /** Invoked daily via existing backup_cron mechanism or system cron. */
    public function aggregate(string $date = ''): void
    {
        // 1) Restrict to CLI / authorised IP (existing cron auth pattern)
        // 2) Iterate active schools
        // 3) For each school: query yesterday's security_events
        // 4) Aggregate counts by event_type
        // 5) Compare against config thresholds
        // 6) Write staff_metrics_daily/{date}_{schoolId} doc
        // 7) Emit [STAFF_METRICS] log line summarising
    }
}
```

---

## 9. Testing Strategy

### 9.1 Pre-Deployment

| Test | Tool | Scope |
|---|---|---|
| Unit test: `_mask_sensitive()` masks every sensitive key | PHPUnit / manual | `Security_telemetry` |
| Unit test: `_buildEvtId` and `_buildLogId` produce sortable + unique IDs | PHPUnit | `Audit_log_service`, `Security_telemetry` |
| Manual: write to `staffAuditLog` from extended `Audit_log_service` succeeds on canary tenant | manual smoke | end-to-end |
| Manual: `_require_role` failure on a test endpoint emits exactly 1 `security_events` doc | manual | `MY_Controller` |
| Manual: invalid event type rejected by `Security_telemetry::emit` | manual | library |
| Static: grep for any direct password/PAN references in `Security_telemetry` callers | grep | site-wide |
| Static: ensure no `firestoreSet('staffAuditLog'…)` outside the library | grep | site-wide |
| Performance: time `new_staff` before and after; assert p95 delta < 100ms | manual stopwatch | endpoint |

### 9.2 Post-Deployment Verification (Canary Tenant, 24 hours)

| Check | Expected Result |
|---|---|
| Create test staff member | 1 entry in `staffAuditLog` with `action=create`, masked sensitive fields |
| Edit test staff (change name) | 1 entry with `action=update`, before/after diff containing only `name` |
| Edit test staff (change `panNumber`) | 1 entry with `action=update`, before/after showing `panNumber: [MASKED]` only |
| Deactivate test staff | 1 entry with `action=status_change`, existing `[STAFF_STATUS …]` log line unchanged |
| Attempt to call Staff endpoint as wrong-role user | 1 entry in `security_events` with `event_type=RBAC_DENIED`, masked detail |
| Upload bad-MIME file | 1 entry in `security_events` with `event_type=UPLOAD_REJECTED`, no password leak |
| Verify no Firestore writes to `academicAuditLog` from staff endpoints | zero |
| Verify zero behaviour regression on staff endpoints | manual smoke |

### 9.3 Soak Verification (7 days, all production tenants)

| Metric | Target |
|---|---|
| Audit-log coverage | ≥99% of staff write endpoints produce a `staffAuditLog` entry |
| Telemetry coverage | RBAC denials in `log_message` match `security_events` count ±1% |
| Sensitive-field leak in `security_events` | 0 (verified via grep of collection export for known PII patterns) |
| Performance regression on Staff endpoints (p95) | < 100 ms |
| Failed audit writes per day | < 5 (any spike investigated) |

---

## 10. Rollback Plan

### 10.1 Per-Patch Rollback

| Patch | Rollback Action | Recovery Time | Data Loss |
|---|---|---|---|
| 1 (`Audit_log_service`) | `git revert <sha>`, deploy | < 5 min | None — staff entries remain in `staffAuditLog`; future staff writes silently skip (whitelist rejects) |
| 2 (`Security_telemetry` library) | Delete file or comment out callers | < 5 min | None — future events not emitted |
| 3 (`config`) | Delete file | < 5 min | None |
| 4 (`_require_role` telemetry) | `git revert <sha>` | < 5 min | RBAC denials no longer emit `security_events` |
| 5 (correlation id) | `git revert <sha>` | < 5 min | None |
| 6–14 (Staff.php instrumentation) | `git revert <sha>` per patch | < 5 min each | None — audit-log entries already written remain valid |
| 15 (cron) | Disable cron schedule | < 1 min | `staff_metrics_daily` stops rolling forward; raw `security_events` still queryable |

### 10.2 Full-Phase Rollback

If Phase A as a whole needs to be reverted:

1. `git revert` the merge commit covering patches 1–15.
2. Optionally delete the 4 new Firestore collections (`staffAuditLog`, `security_events`, `cascade_failures`, `staff_metrics_daily`) — these are append-only and contain no data referenced elsewhere; safe to drop. Retain a `gcloud firestore export` of each first for forensic value.
3. Disable the daily cron.

Recovery time: 30 minutes.

### 10.3 Irreversible Operations

**None.** Every change is additive, every Firestore write is to a net-new collection, every code path preserves existing behavior. Phase A has no irreversible operations.

### 10.4 Forensic Snapshot Before Cutover

Before applying patches:

- `gcloud firestore export gs://<bucket>/staff-pre-phase-a-{timestamp}` on the relevant collections (so post-cutover diffs are auditable)
- Note: Phase A doesn't change existing collections; this snapshot is paranoid insurance only

---

## Proposed Execution Path

**Foundational patches (1–5) are presented above in ready-to-apply form. They are:**

- Pure additive
- Zero risk to existing behavior
- Reversible in < 5 minutes each
- Independent of mobile, rules, encryption, auth

**Request: authorisation to apply patches 1–5 now**, in this order:

1. Patch 1 (`Audit_log_service` generalisation)
2. Patch 3 (config file — no callers yet, safe to land first)
3. Patch 2 (`Security_telemetry` library — depends on config)
4. Patch 5 (correlation ID helper)
5. Patch 4 (`_require_role` telemetry — depends on patch 2 and 5)

After patches 1–5 land and a 24-hour smoke window passes on a canary tenant, patches 6–14 (Staff.php instrumentation) will be presented for review, then patch 15 (cron aggregator).

**Awaiting one of:**

- "Authorised — proceed to apply patches 1–5"
- "Revise patch X with [direction]"
- "Hold — answer questions on Y"

---

*End of Phase A document.*
