# H1 — Login History Remediation Package (AccessHistory → security_events)

**Status:** ANALYSIS ONLY — no implementation / commit / push / merge
**Date:** 2026-06-06 · **HEAD:** `3311fac` · branch `ank-yug_b1`
**Rules:** Firestore-canonical · `security_events` is the authority · no RTDB · no Mongo · fail-closed · no dual-read · no dual-write.

---

## 1. EXACT FILES & METHODS

| File | Method | Lines | Role |
|---|---|---|---|
| `application/controllers/AdminUsers.php` | `_normalize_admin` | :228 (`'lastLogin' => $a['AccessHistory']['LastLogin']`) | per-admin Last Login (admins list) |
| `application/controllers/AdminUsers.php` | `get_dashboard` | :372-385 (recent logins from `AccessHistory`) | dashboard "recent logins" |
| `application/controllers/AdminUsers.php` | `get_login_logs` | :1146-1175 (aggregates `AccessHistory`) | "Login Activity" tab |
| `application/views/admin_users/index.php` | Login Activity tab + Last Login column | (table render) | display |
| *(canonical source)* `application/libraries/Security_telemetry.php` | `emit` | :165-233 | writes `security_events` |
| *(emitter)* `application/controllers/Admin_login.php` | `_try_firebase_admin_login` / success emit | :334 | emits `ADMIN_LOGIN_SUCCESS` |

No change to the write/auth path — read-path only.

---

## 2. CURRENT BROKEN FLOW
- Wave C retired the RTDB `AccessHistory.LastLogin` writer; nothing updates it anymore.
- The 3 readers above still read `$a['AccessHistory']['LastLogin']` / `LoginIP` / `IsLoggedIn` → **always empty/stale**.
- Effect: "Last Login" column blank, dashboard "recent logins" empty, **"Login Activity" tab shows nothing**. No visibility into account usage, dormant accounts, or anomalous logins.

## 3. TARGET FLOW (Firestore-canonical, fail-closed)
- Read login history exclusively from **`security_events`**.
- Login success events: `event_type = 'ADMIN_LOGIN_SUCCESS'`; the **real school is in `detail.schoolId`** (top-level `schoolId` = `'ADMIN_PANEL'` because `Admin_login` inits telemetry with the synthetic panel id — **must filter on `detail.schoolId`, not top-level**).
- Optionally include `ADMIN_LOGIN_FAILED` / `ADMIN_LOGIN_LOCKED` for a richer security view (recommended for ERP parity; success-only is the minimum).
- **Remove the `AccessHistory` reads entirely** (no dual-read; no fallback to the retired field).
- Fail-closed: on query error or no events → render **empty** + log; never fabricate or fall back to stale data.

### Field mapping (security_events → UI)
| UI field | security_events source |
|---|---|
| loginTime | `ts` (sort key, DESC) |
| adminId | `subject.id` (`subject.type='school_admin'`) or `detail.admin_id` |
| adminName | resolve `subject.id` → name via `admins`/`staff` (event has no name) |
| ipAddress | `context.ip` (fallback `detail.ip`) |
| status | derive from `event_type` (success/failed/locked) |
| isOnline | drop (or derive "active in last N min" from latest `ts`; no `IsLoggedIn` field exists) |

### Query shape
`security_events` where `event_type == 'ADMIN_LOGIN_SUCCESS'` AND `detail.schoolId == {schoolId}` orderBy `ts` DESC limit N.
- `get_dashboard`: limit 10.
- `get_login_logs`: limit ~100–200 (or paginated — ties to M4).
- `get_admins`/`_normalize_admin`: **one** school-scoped query for recent successes → build `adminId → latest ts` map (avoid N-per-admin queries); attach `lastLogin` from the map.

---

## 4. FIRESTORE COLLECTIONS INVOLVED
- **`security_events`** — READ (canonical login authority). Collection name resolved from `security_telemetry` config (`security_collections.security_events`, default `security_events`) — do **not** hardcode if overridden.
- **`admins`** / **`staff`** — READ (name resolution only; already loaded in `get_admins`).
- No writes. No RTDB. No Mongo.
- **New composite index required:** `security_events (event_type ASC, detail.schoolId ASC, ts DESC)` (and, if including failed/locked via `IN`, an equivalent index). Nested-field (`detail.schoolId`) index is supported by Firestore.

---

## 5. BLAST RADIUS
- **Scope:** 3 read methods in `AdminUsers` + the Login Activity tab / Last Login column rendering. Display-only.
- **No impact** on auth, RBAC enforcement, claims, role management, or any write path.
- **Performance:** adds 1 `security_events` query per dashboard / list / logs view (indexed). `get_admins` gains 1 extra query (the lastLogin map) — bounded by limit.
- **Dependency to verify:** the Firestore query helper (`fs->where` / `firestoreQuery`) supports nested-field equality (`detail.schoolId`) + `orderBy` + `limit`. If `schoolWhere` only filters top-level `schoolId`, use the lower-level query API (the events' top-level `schoolId` is `ADMIN_PANEL`, so `schoolWhere` is unusable here — confirm the helper before build).
- **Data dependency:** `detail.schoolId` must be present and unmasked in stored events (Admin_login passes it; `_maskSensitive` should not strip it — verify on a real event).

---

## 6. ROLLBACK PLAN
- Pure read-path code; **no data migration, no schema/data change** (only a new index, which is additive and harmless).
- `git revert` the commit → restores the `AccessHistory` reads (i.e., back to the current empty/stale display). Instant, no cleanup.
- The new composite index can remain (unused) or be deleted later — no runtime dependency after revert.

## 7. VERIFICATION PLAN
1. **Static:** grep — zero `AccessHistory`/`LastLogin`/`LoginIP`/`IsLoggedIn` reads remain in `AdminUsers` (no dual-read). `php -l` clean.
2. **Index:** composite index `(event_type, detail.schoolId, ts)` built and `READY`.
3. **Data probe (read-only):** query `security_events` for `event_type=ADMIN_LOGIN_SUCCESS` + `detail.schoolId=SCH_D94FE8F7AD` → confirm real login events exist with `ts`, `subject.id`, `context.ip`, `detail.schoolId` populated. (Live logins this session — e.g. SSA0001 + the ephemeral proof logins — should appear.)
4. **Functional:** Login Activity tab + dashboard recent-logins + per-admin Last Login now populate from real events; ordering DESC correct; name resolution works.
5. **Fail-closed:** simulate query error / empty result → UI shows empty (no stale `AccessHistory` values, no fabricated rows) + error logged.
6. **No regression:** auth/login + RBAC enforcement unchanged (read-path only).

## 8. EXPECTED DIFF SIZE
- `AdminUsers.php`: ~60–90 LoC (a shared `_recent_admin_logins(schoolId, limit)` helper querying `security_events` + adminId→ts map; rewrite the 3 readers; drop AccessHistory reads).
- `views/admin_users/index.php`: ~10–20 LoC (column/tab label tweaks; optional status column for failed/locked).
- +1 Firestore composite index (config/deploy, not code LoC).
- **Total ~80–110 LoC, 2 files + 1 index.** Rollback complexity: **low**.

---

## NOTES / TIES
- Pairs with **M5** (audit viewer) and **M4** (pagination) — `get_login_logs` is the natural place to add server-side pagination + actor/date filters later; keep this package minimal (success history) and let M5 expand it.
- `isOnline` has no canonical source post-Wave-C; drop it or redefine as "active within N minutes" from latest `ts` (recommend drop in this package to avoid scope creep).
- Including `ADMIN_LOGIN_FAILED`/`LOCKED` materially improves security visibility (dormant/anomaly detection) — recommended but optional; gated behind the same index.

*Analysis only. No files changed; no commits; nothing pushed or merged.*
