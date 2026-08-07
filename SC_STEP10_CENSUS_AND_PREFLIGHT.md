# SC-Step10 — Pre-Execution Census + Implementation Pre-Flight

**Status:** ANALYSIS ONLY — no implementation / commit / push / merge
**Date:** 2026-06-06 · **HEAD:** `3311fac` · branch `ank-yug_b1`

---

## PART 1 — TENANT CENSUS (read-only)

Enumerated the `schools` collection via `firestoreQuery('schools', [])`, applying the canonical tenant filter (mirrors `B2_registry_service.php:597-600`: real tenant = `schoolId` matching `^SCH_[A-Z0-9]+$`).

| Metric | Value |
|---|---|
| Total tenants (after non-tenant filter) | **1** |
| Active tenants | 1 |
| **Passing (currentSession non-empty)** | **1** |
| Failing | **0** |
| Failing schoolIds | (none) |

| schoolId | status | currentSession | verdict |
|---|---|---|---|
| `SCH_D94FE8F7AD` | active | `2026-27` | ✅ PASS |

**Note on a false-positive:** the raw `schools` collection also contains `SCH_D94FE8F7AD_profile` — a **non-tenant counter doc** (commCounters/acctCounters, written via `fs->docId('profile')`; `Communication_helper.php:337`, `Accounting.php:354`). The platform already excludes these (`B2_registry_service.php:599` "Skip non-tenant docs"). The first census run mis-flagged it; re-running with the canonical `^SCH_[A-Z0-9]+$` filter correctly excludes it. It is **not** a tenant and is irrelevant to G1/G2 (login/api_punch read `schools/{SCH_id}` from claims, never the `_profile` doc).

### CENSUS RESULT: ✅ PASS — all active tenants have `currentSession`. SC-Step10 safe to proceed.

---

## PART 2 — STATIC VERIFICATIONS (checks 4–6)

### Check 4 — Can any onboarding path create a tenant without currentSession? → **PASS (production), with a library caveat**
- SA onboarding (`Superadmin_schools::onboard`) **requires** `session_year`: `empty($session_yr)` rejected (:230) and format-validated `^\d{4}-\d{2}$` (:292). It is then seeded atomically:
  - B2 path: `B2_registry_service::create_tenant` writes `sessions[]` + `currentSession` (:1421-1423) when `sessionYear !== ''`.
  - Legacy path: `Superadmin_schools` :861-862 writes `sessions[]` + `currentSession`.
- ✅ No production onboarding path can create a tenant without `currentSession`.
- ⚠️ **Caveat (defensive, not a live gap):** `create_tenant` only seeds when `sessionYear !== ''` (guard :1421), so a *non-UI* caller invoking the library without `sessionYear` would create a session-less tenant. The only current caller (SA onboard) always passes a validated value. *Recommend (separate, low): make `create_tenant` reject empty `sessionYear` to harden the library contract.*

### Check 5 — Can any production workflow clear/null currentSession? → **PASS**
- No `currentSession => ''` / `=> null` writes exist anywhere.
- `Firestore_service::saveSchool` (:775-792) **DEMOTE** logic: preserves canonical currentSession — includes it in the write **only if absent** (defensive seed = `$this->session`); on read failure it omits currentSession (fail-safe). **Never clears.**
- `delete_session` / `archive_session` read currentSession but do **not** write/clear it (not among the writers). Dangling-currentSession inconsistency is *surfaced* (School_config :164-179), not nulled.
- `merge=true` (DOC-MERGE-FIX, :3008) prevents session-mutation PATCHes from wiping currentSession.
- ✅ No clearer.

### Check 6 — Is School_config the sole canonical currentSession writer? → **QUALIFIED FAIL (1 side-channel)**
Writers of `schools/{id}.currentSession`:
| Writer | Type | Verdict |
|---|---|---|
| `School_config::set_active_session` (:3135) | canonical | ✅ authority |
| `School_config::rollover_session` (:3671) | canonical | ✅ authority |
| `Superadmin_schools` onboard (:862) + `B2_registry_service::create_tenant` (:1423) | onboarding seed | ✅ acceptable (creation) |
| `Firestore_service::saveSchool` (:786) | defensive write-if-absent (never overrides) | ✅ acceptable (fail-safe seed) |
| **`Admission_public::_resolve_active_session` (:1009)** | **public-form side-channel** | ❌ **VIOLATION (G3)** |
- A **public admission form** writes `currentSession` (derived from `sections`) when it's missing. This is **not** School_config, and it is a **fail-OPEN auto-seed** that could silently re-populate a missing `currentSession` — partially undermining SC-Step10's fail-closed intent on the *write* side.
- **Coupling note:** G1/G2 harden the **read** paths and are unaffected by this writer. But for full single-authority + fail-closed integrity, `Admission_public:1009` must be neutralized. This is exactly the audit's **G3 / SC-Step11** item. **Recommend sequencing SC-Step11 (remove the Admission_public side-channel write) alongside or immediately after SC-Step10.**

**Net:** census + checks 4 & 5 are clean; check 6 confirms the previously-documented G3 side-channel (does not block G1/G2 read-hardening, but is a coupled write-side residual).

---

## PART 3 — SC-Step10 IMPLEMENTATION PRE-FLIGHT

### Exact lines affected
**G1 — `application/controllers/Admin_login.php` · `_hydrate_admin_session_from_school()`**
- Modify: lines **599-602** (currentSession read + delete the `sessions[0]` fallback at 600-602; insert fail-closed block).
- Unchanged: 594-597 (`sessions` list → `available_sessions`), 615-623 (`set_userdata` for the success case).

**G2 — `application/controllers/Attendance.php` · `api_punch()`**
- Keep: lines **2663-2666** (canonical `currentSession` read).
- Delete: **2667-2676** (`sessions[0]` fallback) and **2683-2687** (synthetic `date('Y').'-'.(date('Y')+1)`).
- Insert: fail-closed reject (`json_error(..., 409)`) where the synthetic block was.

### Expected diff size
- G1: −3 / +~6 (net +3), 1 file.
- G2: −~14 / +~4 (net −10), 1 file.
- **Total ≈ 25-30 LoC, 2 files.** No new files, no index, no data migration.

### Verification gates (must all pass post-implementation)
1. `php -l` clean on both files.
2. Grep gates:
   - `grep -n "sessions\[0\]" application/controllers/Admin_login.php` → **no match**.
   - `grep -n "sessions\[0\]\|sessionsList\[0\]" application/controllers/Attendance.php` → **no match in api_punch (2640-2690)**.
   - `grep -n "date('Y') . '-' . (date('Y') + 1)" application/controllers/Attendance.php` → **no match**.
   - `grep -n "fail-closed\|Punch rejected\|no active academic session" Admin_login.php Attendance.php` → **present**.
3. Functional fail-closed proof (ephemeral): tenant with currentSession → login OK / punch OK; tenant with empty currentSession → login **blocked** (session cleared, flashdata error) / punch **409** (no write, no synthetic).
4. Regression smoke: onboarding (currentSession seeded → login OK), promotion (currentSession unchanged), fee-generation, web attendance — all unaffected.

### Rollback gates
- Pure control-flow; **no data/schema/index change.** `git revert` per file restores the prior fallback. Independent per file (G1 ≠ G2). Instant; no cleanup.
- Rollback trigger during soak: any spike in `SC10 fail-closed` login blocks or `api_punch` 409s tied to a *healthy* tenant → revert + investigate (but census shows all healthy, so none expected).

### Commit boundaries (recommended)
- **Commit G1** — `fix(session): fail-closed login hydration — remove sessions[0] fallback (SC-Step10/G1)` · `Admin_login.php` only.
- **Commit G2** — `fix(session): fail-closed device api_punch — remove sessions[0] + synthetic fallback (SC-Step10/G2)` · `Attendance.php` only.
- Two independent commits (independent files, independent revert). Do not bundle with the unrelated working-tree files.

---

## GO / NO-GO
- **Tenant census: PASS** → SC-Step10 is safe to implement (no tenant will be locked out).
- **Checks 4 & 5: PASS.** **Check 6: G3 side-channel confirmed** — does **not** block G1/G2 (read-hardening), but **strongly recommend** scheduling SC-Step11 (Admission_public:1009 removal) so the write side can't silently defeat fail-closed.
- **Recommendation:** proceed to G1+G2 implementation on authorization; track SC-Step11 (G3) as the immediate follow-on.

*Analysis only. No files changed; the census probe was created, run, and removed (zero net change); no commits; nothing pushed.*
