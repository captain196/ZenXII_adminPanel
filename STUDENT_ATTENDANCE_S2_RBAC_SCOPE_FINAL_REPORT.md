# S2 — Class/Section Authorization Scope — Final Report

**Date:** 2026-09-15 · **Admin branch:** `integration/phase2` · **Env:** local
Firestore + Auth emulator. **No commit / push / deploy. No production data touched.**

## 1. Exact S2 root cause
`MY_Controller::_teacher_can_access()` decided "is this user confined to their
assigned class/sections?" by matching the role **display name** against the
literal list `['Teacher','Class Teacher']`. Any role whose name was not one of
those two — **including any custom role** an admin created and granted the
Attendance module — skipped the section restriction entirely and could
read/write **every** section in the school. Student attendance is
`allow write: if false` in firestore.rules, so this PHP gate was the *entire*
section boundary. The boundary was a display string, which admins can rename and
custom-create — not an authorization property.

## 2. Existing authorization architecture (forensically established)
- **Graded RBAC** (`rbac_helper.php`): a role carries `permissions[]` (module
  presence), `permissionLevels{}` (view/edit/manage), `tier` (1–9 management
  hierarchy), `surface`, `ownership` (own-data: "My Attendance" etc.), and
  `is_system`. `has_permission(module, level)` answers **only** "may this user
  use this module, at what level." Resolved from the **union of `staff_roles[]`**
  via `resolve_role_access` → `resolve_effective_access`, keyed by the **immutable
  `ROLE_*` id** (not the label), cached in the session. **No class/section scope
  concept exists anywhere in it.**
- **Section access** is a *separate* layer: `_teacher_can_access` +
  `subjectAssignments`/`isClassTeacher`. RBAC never expressed section scope.
- Consumers of `_teacher_can_access`: **Attendance, Result, Red_flags** (all
  student section-scoped; same latent bug) — **not Staff Attendance**.

## 3. Was `subjectAssignments/isClassTeacher` sufficient? — No.
Proven from the model: assignments are *operational teaching* records (tenant- and
session-scoped, revocable, multi-section, written when a teacher is timetabled).
They cannot serve as the authorization scope because, per the security objective:
having an assignment must not *prove* a role is classroom-scoped (a teaching
Vice-Principal has assignments yet is legitimately school-wide), and lacking one
must not *prove* school-wide (a brand-new teacher role with no timetable yet).
So assignment presence is the wrong signal for *scope*; it remains the right
signal for *which* sections a section-scoped role may touch.

## 4. Final architecture selected
Introduced an explicit, first-class **`accessScope` ('school' | 'section')**,
resolved in the RBAC single-source-of-truth by **role identity, never name**:
1. explicit `accessScope` on the role entry wins;
2. else canonical scope of a **system role by its immutable `ROLE_*` id**
   (`ROLE_ADMIN/PRINCIPAL/VICE_PRINCIPAL/ACADEMIC_COORDINATOR` → school;
   `ROLE_TEACHER/CLASS_TEACHER` → section);
3. else **default 'section'** — least privilege; a custom/ambiguous role is
   never silently school-wide.
Bypass roles (Super/School Super Admin) → school. Effective scope = the broadest
across the role union (matches module/level union semantics). This is a genuine
authorization property (stored/resolved in RBAC + enforced server-side), not a
UI hint, and reuses the existing resolver — **no second authorization system.**

## 5. Exact implementation changes
- `application/helpers/rbac_helper.php`: `RBAC_SCHOOLWIDE_ROLE_IDS` +
  `RBAC_SECTION_ROLE_IDS` + `rbac_role_scope($entry,$rid)`; `resolve_role_access`
  now accumulates + returns `scope` (bypass → 'school'; broadest wins);
  `resolve_effective_access` carries `scope` through (per-person extra/deny never
  widen section→school).
- `application/core/MY_Controller.php`: login RBAC-refresh captures `scope` into
  session `rbac_scope` (bypass + fold-safety-net → 'school'); new
  `_effective_access_scope()` (session read, authoritative resolve on miss, fails
  to 'section'); `_teacher_can_access` now gates on scope, **role name removed**.
- `application/controllers/Staff_access.php`: role editor persists `accessScope`
  (create defaults 'section'; update honours explicit value) and `_role_out`
  returns the resolved scope — so scope is settable + auditable per role.

## 6. Affected modules
Attendance, Result, Red_flags (all consume `_teacher_can_access` → now uniformly
scope-based; the same latent bug is fixed in all three). Staff_access role editor
(scope now settable). **Staff Attendance untouched** — it does not use this
primitive and its authorization semantics are unchanged.

## 7. Migration / backfill
**None required.** The resolver's precedence (explicit → system-id map →
least-privilege default) classifies every EXISTING role correctly with no data
write: system leadership/teacher roles via the immutable-id map; existing custom
roles fall to the safe 'section' default (which *closes* the hole). Admins opt a
custom role into school-wide explicitly via the role editor. No ambiguous role is
guessed school-wide; legitimate school-wide (system + bypass) access is preserved.

## 8. Security test matrix (emulator, live Bearer auth) — `sa_s2_e2e.php` 4/4
- custom SECTION-scoped role → ASSIGNED section → **ALLOW (200)**
- custom SECTION-scoped role → UNASSIGNED section → **DENY (403)** ← the fix
- same role granted `accessScope='school'` → unassigned section → **ALLOW (200)**
- reverted to section → unassigned → **DENY (403)** (authoritative, live)
Plus `rbac_role_scope` unit checks **7/7** (system school/section ids, custom
default section, explicit overrides — including explicit 'section' overriding a
system id). Built-in Class-Teacher section behaviour + bypass-admin school-wide
behaviour re-proven via C2 (11/11) and C1 (6/6). Server-side throughout; role
display name never consulted; direct-API (Bearer) path exercised.

## 9. RBAC regression
`rbac_role_scope` 7/7; full unit suite — see §14 (no new failures vs baseline).

## 10. Attendance regression
**328/328** (`--filter Attendance`) after the MY_Controller/_teacher_can_access change.

## 11. C1 results
C1 unit **43/43** (unchanged — S2 is PHP-only); C1 backend E2E **6/6** (Bearer +
CSRF + date-pin) after re-keying the SATTCHA uid fixture.

## 12. C2 results
C2 coverage E2E **11/11** (section-scoped Class Teacher + bypass admin + coverage).

## 13. Teacher App build/test
Unaffected by S2 (no Kotlin change); last state BUILD SUCCESSFUL, 43/43.

## 14. Cross-module regression
Full `--testsuite Unit`: 8194 tests, **35 non-passing (17 errors + 18 failures)** —
**one fewer than the pre-work baseline of 36**, i.e. **zero new failures**; the
remaining non-passing are the documented pre-existing decision-gates from other
workstreams (PF wage base, salary cutover, regularization, punch audit, dead
consumer). The two other `_teacher_can_access` consumers verified green:
`--filter 'Result|RedFlag'` → **15/15**. Attendance filter → 328/328.

## 15. Accepted findings (unchanged, per decision)
S4 (holiday fail-open, ACAD-D18), S5 (lock TOCTOU — check is fail-closed), S6
(shared cron token — platform-scheduling), K3 (overnight→correction governance),
K6 (client session-pin optional; server authoritative). No new features created.

## 16. Remaining C1 real-device/network UAT
Still parked (interactive offline→reconnect loop, environment-blocked). Unchanged.

## 17. Git / worktree
Modified (surgical/additive): `rbac_helper.php`, `MY_Controller.php`,
`Staff_access.php`. All **UNCOMMITTED**. No reset/clean/restore/stash/checkout;
parallel work preserved (large MY_Controller/Staff_access stat = pre-existing
CRLF/LF noise + other workstreams; my content delta is the scope additions). No
commit/push/deploy.

## 18. Remaining blocker
None for S2. The only outstanding attendance item is the parked C1 interactive
device UAT.
