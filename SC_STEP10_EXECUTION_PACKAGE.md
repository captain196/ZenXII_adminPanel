# SC-Step10 — Fail-Closed Hardening (G1 + G2) — Execution Package

**Status:** ANALYSIS / EXECUTION PACKAGE ONLY — no implementation / commit / push
**Date:** 2026-06-06 · **HEAD:** `3311fac` · branch `ank-yug_b1`
**Scope:** remove the two session fail-open fallbacks so `schools/{schoolId}.currentSession` is the single authority and the system fails closed when it is absent.

**Architecture (unchanged & enforced):** Firestore canonical · `schools/{id}.currentSession` = single authority · no `activeSession` authority · no `schoolControl` session authority · no RTDB session authority · **fail closed if currentSession unavailable** · no dual-read · no dual-write · no compatibility layer.

---

## 1. EXACT FILES AFFECTED
- `application/controllers/Admin_login.php` (G1)
- `application/controllers/Attendance.php` (G2)

## 2. EXACT METHODS AFFECTED
- **G1:** `Admin_login::_hydrate_admin_session_from_school()` (lines 583-624; fallback at **600-602**).
- **G2:** `Attendance::api_punch()` session-resolution block (lines 2658-2688; sessions[0] fallback **2667-2676**, synthetic fallback **2683-2687**).

---

## 3. CURRENT RUNTIME FLOW (broken — fail-open)

### G1 — Admin_login (post-auth session hydration)
```
586  $schoolDoc = firestoreGet('schools', $schoolId)
594-597 $sessions = filter+rsort(schoolDoc.sessions)
599  $currentSession = schoolDoc.currentSession ?? ''
600-602  if ($currentSession === '' && !empty($sessions))
             $currentSession = $sessions[0];        // ← FAIL-OPEN: silently auto-activate most-recent
615-623  session->set_userdata(session/current_session/session_year = $currentSession, available_sessions=$sessions)
```
Effect: a tenant with `sessions[]` but no `currentSession` silently logs admins into `sessions[0]` — non-canonical, no audit, violates single-authority + fail-closed.

### G2 — Attendance::api_punch (device attendance)
```
2658 $activeSession = ''
2661 $schoolDoc = firestoreGet('schools', school_id)
2663-2666 if currentSession non-empty → $activeSession = currentSession
2667-2676 elseif schoolDoc.sessions → rsort → $activeSession = sessions[0]   // ← FAIL-OPEN #1
2683-2687 if ($activeSession === '') $activeSession = date('Y').'-'.(date('Y')+1)  // ← FAIL-OPEN #2 (synthetic)
2688 $session = $activeSession
```
Effect: device punches land on an inferred or *fabricated* session when `currentSession` is missing — biometric/session integrity risk.

---

## 4. TARGET RUNTIME FLOW (fail-closed; canonical only)

### G1 — target
```
599  $currentSession = (string)(schoolDoc.currentSession ?? '')
     if ($currentSession === '') {
        // FAIL-CLOSED: no sessions[0] fallback. No usable session ⇒ block login.
        log_message('error', "SC10 fail-closed: schoolId={$schoolId} has no currentSession at login")
        $this->session->unset_userdata(self::SESSION_KEYS)        // clear partial session
        $this->session->set_flashdata('error', 'Your school has no active academic session configured. Contact your administrator.')
        redirect('admin_login')                                    // exits
     }
     // else: set session/current_session/session_year = $currentSession (unchanged)
```
- Delete lines 600-602. `available_sessions = $sessions` (the validated list) is still set for the MY_Controller whitelist — that is NOT a currentSession fallback and stays.
- (Optional, no scope creep) emit telemetry via existing `sec_telem`; if a new event type is desired it requires an EVENT_TYPES allowlist edit — left out to keep scope tight; `log_message` suffices.

### G2 — target
```
2658 $activeSession = ''
2659 try { $schoolDoc = firestoreGet('schools', school_id)
         if (is_array && currentSession is non-empty string) $activeSession = currentSession }
     catch { log }
     if ($activeSession === '') {
        // FAIL-CLOSED: no sessions[0], no synthetic. Reject the punch.
        log_message('error', "SC10 fail-closed: api_punch no currentSession school={$schoolName}")
        return $this->json_error('No active academic session configured for this school. Punch rejected.', 409)
     }
     $session = $activeSession
```
- Delete lines 2667-2676 (sessions[0]) and 2683-2687 (synthetic). Keep the canonical `currentSession` read + the try/catch log.

---

## 5. FIRESTORE COLLECTIONS INVOLVED
- **`schools/{schoolId}`** — READ `currentSession` (canonical authority); `sessions[]` still read in G1 only for the `available_sessions` whitelist (not as a currentSession source).
- No writes. No RTDB. No Mongo. No `activeSession`/`schoolControl` reads.

---

## 6. BLAST RADIUS ANALYSIS
- **G1 — every admin login.** Healthy tenants (currentSession set — guaranteed by SC-Step8 atomic onboarding seed + School_config writers) are **unaffected**. A tenant missing `currentSession` now **blocks login** (fail-closed) instead of silently using `sessions[0]`. That tenant is genuinely misconfigured; blocking is intended. → **Pre-deploy census required** (see §8) to confirm no live tenant has empty `currentSession`, else its admins lock out.
- **G2 — device attendance punches only.** Healthy tenants unaffected. Missing `currentSession` → punch rejected `409` (was: inferred/synthetic). Authenticated web attendance is unaffected (uses `session_year` from login, which G1 now guarantees is canonical or login is blocked).
- **Unaffected:** onboarding, promotion, fee generation, web attendance, RBAC, all write paths. Other mobile endpoints (Fee_management, Ptm) already fail-closed on `currentSession`.

### Regression verification (required confirmations)
- **Onboarding:** `B2_registry_service::create_tenant` seeds `sessions[]` + `currentSession` atomically (SC-Step8) → new tenants always have currentSession → G1/G2 never fire for them. **No regression.**
- **Promotion:** writes `sessions[]` only, never `currentSession` (invariant); active session changes only via `School_config::set_active_session`. currentSession persists across promotion. **No regression.**
- **Fee generation:** resolves session from `currentSession` (already fail-closed); SC-Step10 does not touch fee paths. **No regression.**
- **Attendance:** only `api_punch` device path changes (intended fail-closed); web attendance unchanged. **No regression beyond intended.**
- **Mobile API:** `api_punch` fail-closed on missing currentSession (intended); no other mobile endpoint touched. **No regression beyond intended.**

---

## 7. ROLLBACK STRATEGY
- Pure control-flow change; **no data, no schema, no index.** `git revert` either/both edits → restores the fallbacks (current fail-open behavior). Instant, no cleanup. Per-file revertable (G1 and G2 are independent files/commits).

---

## 8. VERIFICATION PLAN
1. **PRE-DEPLOY SAFETY CENSUS (gating, read-only):** enumerate all `schools/*` docs; assert **every active tenant has a non-empty `currentSession`**. Any tenant with empty currentSession must be remediated (set its currentSession) BEFORE G1 ships, or its admins will be locked out. (Test tenant `SCH_D94FE8F7AD` = `2026-27` ✅.)
2. **Static / grep gates** (see §11) — zero `sessions[0]` + zero synthetic in the affected paths.
3. **`php -l`** both files clean.
4. **Functional G1:** login on a tenant with currentSession → success (session_year = currentSession). Simulate empty currentSession (ephemeral test tenant) → login **blocked** with the configured error, session cleared (no partial auth). Fail-closed proven.
5. **Functional G2:** `api_punch` with currentSession → punch processes against canonical session. Empty currentSession → `409` reject, **no** attendance write, **no** synthetic session. Fail-closed proven.
6. **Regression:** onboard a tenant (currentSession seeded → login works); run a promotion (currentSession unchanged → login works); a fee-generation smoke (unchanged); web attendance load (unchanged).
7. **Negative-integrity:** confirm no attendance doc is ever written under a `date('Y')-(Y+1)` synthetic key after the change (grep storage + observe).

---

## 9. SOAK PLAN
- **24–72h** post-deploy (SC convention).
- Monitor logs / `security_events` for: `SC10 fail-closed` login blocks and `api_punch` `409` rejections.
- **Expected:** near-zero. Any occurrence = a tenant genuinely missing `currentSession` → investigate + remediate that tenant's session config (not revert).
- Daily: confirm no spike; confirm normal admin logins + device punches succeed for healthy tenants.
- Gate to "done": clean soak + zero unexplained fail-closed events.

---

## 10. EXPECTED DIFF SIZE
- **G1** `Admin_login.php`: remove 3 lines (600-602) + add ~6-line fail-closed block ≈ **net +3 / ~9 changed**.
- **G2** `Attendance.php`: remove ~14 lines (2667-2676 + 2683-2687) + add ~4-line reject ≈ **net −10 / ~18 changed**.
- **Total ≈ 25-30 LoC, 2 files.** No new files, no index, no data migration. Rollback complexity: **low**.
- Commit boundary: two independent commits (G1, G2) or one SC-Step10 commit — recommend **two** (independent files, independent revert).

---

## 11. GREP GATES (post-implementation proof)
```
# G1 — zero sessions[0] fallback in _hydrate_admin_session_from_school
grep -n "sessions\[0\]" application/controllers/Admin_login.php            # expect: no match
grep -n "currentSession = \$sessions" application/controllers/Admin_login.php  # expect: no match

# G2 — zero sessions[0] fallback AND zero synthetic session in api_punch
grep -n "sessions\[0\]\|sessionsList\[0\]" application/controllers/Attendance.php   # expect: no match in api_punch block (2640-2690)
grep -n "date('Y') . '-' . (date('Y') + 1)" application/controllers/Attendance.php  # expect: no match

# both — fail-closed markers present
grep -n "fail-closed\|Punch rejected\|no active academic session" application/controllers/Admin_login.php application/controllers/Attendance.php  # expect: matches
```
Note: `date('Y')` legitimately remains elsewhere in `Attendance.php` (other methods compute year for keys); the gate targets ONLY the synthetic `date('Y').'-'.(date('Y')+1)` session-construction in `api_punch`.

---

## SUMMARY
G1 + G2 remove the only two remaining session fail-open paths, making `schools/{id}.currentSession` the strict single authority with fail-closed behavior (login blocked / punch rejected) when absent. No dual-read/dual-write, no RTDB, no compat layer. The single operational prerequisite is the **pre-deploy census** confirming all live tenants have `currentSession` set. ~25-30 LoC, 2 files, low rollback.

*Analysis only. No files changed; no commits; nothing pushed.*
