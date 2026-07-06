# Session Convergence Audit — ZenX branch `ank-yug_b1`

**Status:** ANALYSIS ONLY — no code changes
**Date:** 2026-06-05
**HEAD:** `5e2499f` · branch `ank-yug_b1`
**Method:** static forensic audit across core + 11 consuming modules (5 parallel read-only passes), reconciled.

**Locked architecture being measured against:**
`schools/{schoolId}.currentSession` is the ONLY canonical session authority · no activeSession/currentSession split · no RTDB session authority · Firestore-first · fail-closed if canonical session missing · all create/activate/switch/promote/consume converge on the same authority.

---

## 1. CURRENT IMPLEMENTATION STATUS

**~90% converged.** Canonical authority is correctly established as **`schools/{schoolId}.currentSession`** (Firestore), with `schools/{id}.sessions[]` as the validated session list. Core CRUD is locked + CAS-guarded; onboarding seed is atomic; most consumers read canonical and fail closed.

**This branch is BETTER aligned than the Grader memory recorded.** Grader had `currentSession` split as `schoolControl/{id}.currentSession` (write) + `schools/{id}.sessions[]` (read). ZenX consolidates BOTH onto `schools/{id}.currentSession`, exactly matching the locked decision. `schoolControl/{id}` holds **no** session field here (subscription/lifecycle only).

**Residual non-convergence:** 3 fail-open fallbacks, 1 side-channel writer of the canonical field, and 1 residual RTDB session writer in a legacy migration path. None breaks the happy path; all violate the strict mandates.

---

## 2. WHAT HAS ALREADY SHIPPED (verified on this branch)

| Item | Evidence |
|---|---|
| Canonical `schools/{id}.currentSession` (read + write) | School_config.php:3135 (write), :109/:2656/:3097 (read) |
| Atomic onboarding seed (`sessions[]` + `currentSession` in tenant-create batch) — SC-Step8 | B2_registry_service.php:1421-1427, commit :1527 |
| Session CRUD with flock + Firestore CAS (`__updateTime`) + `merge=true` | School_config.php add_session :2967/:3019, set_active :3078/:3140, delete :3889/:3952, archive :4009/:4072, rollover :3357/:3401/:3674/:3752 |
| Cross-controller session library (shared lock path) used by promotion | Session_lifecycle.php:77-167; Sis.php:912-932 |
| MY_Controller Firestore whitelist refresh (SC-Step5) | MY_Controller.php:199-224 |
| Admin_login Firestore session hydration (SW2) | Admin_login.php:590-614 |
| Mobile/API canonical reads — Fee parent app, PTM app (SC-0b) | Fee_management.php:2916-2931; Ptm.php:86-101 |
| Attendance device API cut to Firestore (SW3) | Attendance.php:2658-2666 |
| Promotion invariant: writes ONLY `sessions[]`, never `currentSession` | Sis.php:906-932 (comment + Session_lifecycle call) |
| No `activeSession` writers anywhere; legacy-`activeSession` health warning exists | School_config.php:4678 |
| RTDB `Config/ActiveSession` + onboard `Sessions` writes retired (canonical path) | Superadmin_schools.php:486-493, :845-848 |

**Converged consumers:** SIS (admin), Fees (auth + webhook + parent), Attendance (auth), Examination, Result/Report cards, PTM (admin + app), Academic, Reports, Promotion.

---

## 3. WHAT REMAINS INCOMPLETE (gaps)

| ID | Gap | Severity | Location | Mandate violated |
|---|---|---|---|---|
| **G1** | Login hydration falls back to `sessions[0]` if `currentSession` missing; silent, no audit; if `sessions[]` also empty → `''` | HIGH | Admin_login.php:595-598 | Fail-closed |
| **G2** | Device `api_punch` double fallback: `currentSession` → `sessions[0]` → **synthetic** `date('Y')-(Y+1)`. Biometric punches can land on an inferred session | HIGH | Attendance.php:2667-2686 | Fail-closed; single authority |
| **G3** | Public admission form reads legacy fields (`activeSession`/`active_session`/`session`) AND **writes `currentSession`** as a side-channel when missing | HIGH | Admission_public.php:983-985 (read), :1009 (write) | No legacy reads; single write authority |
| **G4** | Residual RTDB session writes in legacy migration/re-onboard path: copies `Schools/{name}/Sessions → Schools/{id}/Sessions`, and writes academic blob incl. sessions to RTDB | MEDIUM | Superadmin_schools.php:1530-1532, :1608 | No RTDB session writes |
| **G5** | MY_Controller fail-closed whitelist only triggers when `available_sessions` is already cached non-empty → first-request-after-login stale `session_year` not caught here | LOW | MY_Controller.php:199-224 | Fail-closed (edge) |
| (info) | Rollover Stage B (student/section promotion) runs unlocked | accepted (D1=B) | School_config.php:3437-3643 | — (architectural decision, not a convergence gap) |

---

## 4. READER / WRITER MATRIX (canonical fields)

### `schools/{id}.currentSession`
| Access | Where | Locked/CAS | Canonical? |
|---|---|---|---|
| WRITE — activate | School_config::set_active_session :3135 | flock + CAS | ✅ canonical writer |
| WRITE — rollover set-active | School_config::rollover_session :3666-3674 | flock + CAS (best-effort) | ✅ |
| WRITE — onboarding seed | B2_registry_service::create_tenant :1423 | atomic batch | ✅ (creation) |
| WRITE — legacy onboard seed | Superadmin_schools :859-868 | non-atomic, non-fatal | ✅ (creation, flag-off path) |
| WRITE — **side-channel** | **Admission_public :1009** | none | ❌ **G3 violation** |
| READ — login hydrate | Admin_login :595 (`?? sessions[0]`) | — | ⚠️ G1 fallback |
| READ — UI/config | School_config :109/:2656/:3097 | — | ✅ |
| READ — mobile/API | Fee_management :2920, Ptm :90, Attendance :2663 | — | ✅ (Attendance has G2 fallback) |
| READ — public admission | Admission_public :983 (`?? activeSession ?? …`) | — | ⚠️ G3 legacy reads |

### `schools/{id}.sessions[]`
| Access | Where | Locked/CAS |
|---|---|---|
| WRITE — add | School_config :3001-3020 ; Session_lifecycle :121-135 | flock + CAS |
| WRITE — rollover append / delete / archive | School_config :3387 / :3950 / :4070 | flock + CAS |
| READ — validation / whitelist / hydrate | School_config :2981/:3094/:3313 ; MY_Controller :205 ; Admin_login :590 | partial |

### `schools/{id}.activeSession` (legacy split)
| Access | Status |
|---|---|
| WRITE | **none** ✅ |
| READ | only as fallback in Admission_public :984 (G3) |

### `schoolControl/{id}` — **no session field** ✅ (subscription/lifecycle only)

### RTDB session paths
| Path | Access | Status |
|---|---|---|
| `Schools/{id}/Sessions` | WRITE — Superadmin migration :1532, :1608 | ❌ G4 residual writer |
| `Schools/{name}/Config/ActiveSession` | — | retired (no reader/writer) ✅ |
| `Schools/{name}/Sessions` | READ — migration source :1530 | legacy migration only |

---

## 5. DUPLICATE AUTHORITIES STILL PRESENT

1. **Second writer of canonical `currentSession`** — `Admission_public::_resolve_active_session` :1009 writes `currentSession` outside `School_config::set_active_session`. A public, unauthenticated form can silently activate a session. (G3)
2. **Legacy read fields** — `activeSession` / `active_session` / `session` still consulted in Admission_public :983-985 (and named in its docblock :15, :970). No other module reads them.
3. **RTDB `Schools/{id}/Sessions`** — still written by the SA legacy migration/re-onboard path (:1532, :1608); not read at runtime but is a live RTDB session writer. (G4)

No `activeSession`-vs-`currentSession` split. No `schoolControl` session authority. No Firestore-vs-RTDB split in the runtime read path.

---

## 6. MODULES STILL NOT CONVERGED

| Module | State | Reason |
|---|---|---|
| Admission (public form) | ❌ not converged | legacy-field reads + side-channel `currentSession` write (G3) |
| Attendance (device `api_punch`) | ⚠️ partial | fail-open fallback incl. synthetic session (G2) |
| Login bootstrap | ⚠️ partial | fail-open fallback to `sessions[0]` (G1) |
| SA onboarding (legacy migration) | ⚠️ partial | residual RTDB session writes (G4) |
| SIS-admin, Fees, Examination, Result, PTM, Academic, Reports, Promotion | ✅ converged | canonical read, fail-closed (see §2) |

---

## 7. CROSS-SYSTEM IMPACT

| System | Session source | Converged? | Impact / risk |
|---|---|---|---|
| **Admissions** | Admission_public `_resolve_active_session` (legacy cascade + side-channel write) | ❌ | Public form can write canonical `currentSession`; legacy fields could resolve a wrong session. **G3** |
| **Students (SIS)** | `$this->session_year` (canonical via login) | ✅ | admission/enroll writes scoped correctly (Sis.php:251) |
| **Promotion** | `Session_lifecycle::add_session` (sessions[] only) | ✅ | never touches `currentSession`; lock+CAS; invariant preserved (Sis.php:906-932) |
| **Fees** | auth: `session_year`; webhook/parent: `schools.currentSession` | ✅ | fail-closed HTTP 400 on missing (Fee_management.php:116-120, 2920-2925) |
| **Attendance** | auth: `session_year`; device: canonical + fallback | ⚠️ | device punches may land on inferred session if `currentSession` lost. **G2** |
| **Examination** | `session_year` → Exam_engine | ✅ | fail-closed; no RTDB session read (Examination.php:44) |
| **Results / Report cards** | `session_year` → Exam_engine | ✅ | scoped reads; stale flags cleared (Result.php:49, 997) |
| **PTM** | admin: `session_year`; app: `schools.currentSession` (SC-0b) | ✅ | fail-closed HTTP 400 (Ptm.php:55-60, 86-101) |
| **Reports** | system-level (not session-scoped) | ✅ (n/a) | no academic-session dependency |
| **Mobile APIs** | canonical Firestore read | ⚠️ | Fee + PTM fail-closed ✅; Attendance device fail-open ❌ (G2) |

---

## 8. REMAINING EXECUTION PHASES (proposed)

- **SC-Step10 — Fail-closed hardening (G1 + G2).** Make login and device `api_punch` reject (no `sessions[0]`/synthetic fallback) and audit-log when `currentSession` is missing. Smallest, highest-value.
- **SC-Step11 — Admission convergence (G3).** Remove legacy-field reads; remove the side-channel `currentSession` write; admission resolves canonical only and fails closed (form shows "session not configured").
- **SC-Step12 — RTDB session-writer retirement (G4).** Remove RTDB `Schools/{id}/Sessions` writes in the SA migration/re-onboard path (POST-CLEAN-2). Confirm no mobile/legacy reader depends on them first.
- **SC-Step13 (optional) — First-request fail-closed (G5).** Seed `available_sessions` at login so the whitelist guard catches a stale `session_year` on the first authenticated request.

Strict ordering: Step10 → Step11 → Step12 → Step13. Each independently revertable.

---

## 9. VERIFICATION PLAN

- **L0:** A/B 9/9 PASS preserved per step.
- **Grep gates:** after Step11, zero reads of `activeSession`/`active_session` for session resolution; after Step12, zero RTDB `Schools/*/Sessions` writes; only `School_config::set_active_session` (+ creation seed) writes `currentSession`.
- **Fail-closed probes (read-only):** simulate a tenant with missing `currentSession` → login, `api_punch`, and admission form all reject with an explicit error + audit log (not a silent default).
- **Promotion invariant:** run a promotion → `sessions[]` grows, `currentSession` unchanged.
- **Live single-authority check:** activate a session via School_config → all modules (admin + mobile) observe the change on next request; no module reads a divergent value.
- **Concurrency:** two concurrent `set_active_session` → one wins, other gets 409 CAS-rejected.

---

## 10. RECOMMENDED NEXT IMPLEMENTATION PACKAGE

**Package SC-Step10 (fail-closed hardening) — recommended first.** Smallest blast radius, closes the two HIGH fail-open gaps (G1 login, G2 device punch), pure read-path/guard logic, no schema change, independently revertable.

- Files: `Admin_login.php` (G1), `Attendance.php` (G2).
- Est. ~20-30 LoC; add explicit reject + audit log; remove `sessions[0]`/synthetic fallbacks.
- Gating: independent of the RBAC cleanup package; can proceed in parallel.

**Then** SC-Step11 (Admission, G3 — removes the only non-canonical `currentSession` writer + legacy reads) as the convergence-critical follow-up, and SC-Step12 (G4 RTDB writer retirement) as cleanup.

Pre-flight (files, exact methods, diff size, rollback, soak) to be produced per step **before** any code, on authorization.

---

*Audit only. No files modified except this report. RBAC cleanup package remains isolated and untouched.*
