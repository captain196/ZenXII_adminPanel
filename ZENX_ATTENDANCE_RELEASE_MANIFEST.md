# ZenX — Attendance Migration — RELEASE MANIFEST

**Date:** 2026-07-01 · **Branch:** `ank-yug_b1` (local; **not pushed** — `ahead 7, behind 1` vs origin).
**No push. No deploy.** Awaiting approval.

---

## 1. Commit sequence (this migration, in order)

| # | SHA | Subject | Scope |
|---|---|---|---|
| 1 | `92951e5` | Firestore-only Attendance migration + GPS staff attendance + RTDB elimination | 36 attendance-domain files (backend, views, GPS libs, diagnostics, docs) |
| 2 | `55f7149` | Teacher-app Bearer auth bridge + `_cs_norm` (MY_Controller) | Teacher-app attendance auth + teacher-assignment matching |
| 3 | `4043af6` | Firestore composite index for `attendancePunches` (GPS audit reads) | `firestore.indexes.json` |
| 4 | `2d858d6` | Report-card attendance % from Firestore `attendanceSummary` (Result) | `Result.php` |

All four are clean, isolated, attendance-only commits. `php -l` clean throughout; coverage `attendance↔attendanceSummary` 0 gaps (all schools).

> Note: the branch also carries 3 **unrelated** prior local commits (Fees V7 `24f7b7f`, numbering `ffefafd`, …) and is **behind origin by 1** — a pull/rebase is required before any push. Those are out of scope for this migration.

## 2. NOT committed — decision/handling required

| Item | Reason | Action needed |
|---|---|---|
| `firebase-rules/firestore.rules` | **MIXED** — attendance staff-rules (`staffAttendance`/`staffAttendanceSummary`/`attendancePunches`: deny client-write, read-own) **+ unrelated Exam/Result rules** (`examTemplates`, `examResultMeta`, `marksAudit`, `resultsAudit`, `resultsStaging`) | **BLOCKER for a clean rules commit.** Decide: commit whole (bundles exam rules) or hunk-split. **Rules must be *deployed* regardless** (security — see §5). |
| `Hr.php`, `Exam.php` | Holiday-convergence *consumers* of the shared `Holiday_service` in their **own** modules (HR/Exam) | Not attendance dependencies → commit with HR/Exam module work |
| `academic/index.php` | Academic-Calendar deep-link UX supporting the attendance holiday page | Not a true attendance dependency → commit with Academic |
| `Entity_firestore_sync.php`, misc cache/untracked | Non-attendance / generated | Exclude |

## 3. Deployment order (when GO)
1. **Pull/rebase** `ank-yug_b1` on origin (branch is behind 1), resolve, keep the 4 commits.
2. **Push** the 4 attendance commits (+ prior local commits per operator).
3. **Deploy Firestore indexes:** `firebase deploy --only firestore:indexes` (adds `attendancePunches` composite; wait for BUILD → ENABLED).
4. **Deploy Firestore rules:** `firebase deploy --only firestore:rules` — **only after `firestore.rules` is committed/resolved** (§2). Enforces staff-attendance client-write-deny + read-own.
5. **Deploy backend code** (PHP) to the app server.
6. **Ship Teacher app + Parent app builds** (§7–8).
7. **Live validation** (device + admin browser) per the QA execution guide.

## 4. Rollback order (reverse)
1. Revert app builds to prior APKs (Teacher/Parent).
2. Roll back backend code deploy.
3. `firebase deploy` prior `firestore.rules` (revert to pre-migration rules) — note: reverting reopens client-write on staff attendance; prefer forward-fix.
4. Indexes: leaving the extra index is harmless; remove only if required.
5. Git: `git revert 2d858d6 4043af6 55f7149 92951e5` (reverse order). **Code-only — no RTDB data deleted**; historical RTDB nodes remain frozen for instant behavioral rollback.

## 5. Required Firebase **Rules** deployment
**YES — security-critical.** New `firestore.rules` hardens staff attendance: `staffAttendance` / `staffAttendanceSummary` / `attendancePunches` → **client writes denied** (Admin-SDK/PHP only), **reads = own records or same-school admin**. Pre-migration rules allowed `write: if isStaff()` (client-writable). **Until the new rules are deployed, staff attendance is client-writable.** Blocked on the §2 `firestore.rules` decision.

## 6. Required Firebase **Index** deployment
**YES.** `attendancePunches` composite `(schoolId ASC, staffId ASC, date ASC)` (committed `4043af6`). Without it, `Staff_attendance::me` punch queries fail with an index error. Deploy via `firebase deploy --only firestore:indexes`.

## 7. Teacher App version requirement
Build that: calls the backend attendance endpoints `attendance/save` · `attendance/lock` · `attendance/correction/submit` · `attendance/correction/list` with a **Firebase Bearer token** (AuthInterceptor), reads `attendanceSummary` (Firestore), and points `BASE_URL` at the deployed backend. GPS staff self-attendance via `staff_attendance/punch|me`. *(Separate repo `D:\Projects\SchoolSyncTeacher`; requires the bearer-bridge backend — commit `55f7149` — deployed first.)* On-device happy-path = Requires Live Validation.

## 8. Parent App version requirement
Build that reads attendance from **Firestore only** (`attendance` + `attendanceSummary`), no RTDB attendance path (Gate-2 certified). *(Separate repo `D:\Projects\SchoolSyncParent`.)* On-device = Requires Live Validation.

## 9. Final GO / NO-GO

# ⛔ NO-GO for production deploy — GO for pushing the code commits

- **Code commits (1–4): READY** — clean, isolated, verified; safe to push after pull/rebase.
- **Production deploy is NO-GO until:**
  1. `firestore.rules` committed/resolved (§2) **and deployed** (§5) — otherwise staff attendance is client-writable (security gap).
  2. `firestore.indexes.json` deployed (§6).
  3. Teacher app (bearer build) + Parent app (Firestore build) shipped (§7–8).
  4. On-device + admin-browser **live validation** executed (per QA guide).

**Recommendation:** push the 4 attendance commits (operator's call on the 3 unrelated prior local commits + the behind-1 rebase), then schedule the Firebase rules/index deploy (after resolving `firestore.rules`) and app rollout with live validation before flipping production.

*No push. No deploy. Awaiting approval.*
