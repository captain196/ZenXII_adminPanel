# Release Integration — Validation Report (re-baselined)

**Date:** 2026-08-07 · **Branch:** `integration/phase2` · **Tenant:** `SCH_D94FE8F7AD` / `2026-27`
**Supersedes:** `PHASE1_VALIDATION_REPORT_2026_08_06.md` (baseline no longer current)
**Constraints observed:** no commits · no rebases · no release branch · no push · no deployment.

---

## 0. What changed since the last report

The pull from main moved the ground substantially:

| | Before (2026-08-06) | Now (2026-08-07) |
|---|---|---|
| Branch | `integration/phase1` | **`integration/phase2`** |
| Phase 1 | validation in progress | **CLOSED as NO-GO** (`d4e0e3b`), merged into local `ank-yug_b1` (`eb24334`, ahead 36, unpushed) |
| New work | — | **10 commits from main**: RBAC Phase A, Staff & Roles RBAC hardening, Admin Users, attendance fixes, red-flags push, media/gallery, migration controllers, Events fix |
| Working tree | 7 modified | **318 staged** (256 A / 60 M / 2 D) |
| Dual_write RTDB removal | applied | **REVERTED by the pull** (§4) |

`integration/phase2` is **behind `ank-yug_b1` by 36** — it carries main's new RBAC work but not yet the
Phase 1 body. The 256 staged additions are that integration in progress.

---

## 1. Executed validation — new baseline

| # | Check | Result | vs previous |
|---|---|---|---|
| 1 | `php -l`, staged + working tree | **134 files, 0 failures** | was 139 / 0 |
| 2 | Rules structure | **404 = 404 balanced**, 162 match blocks | was 377 / 153 |
| 3 | Indexes | **252**, 0 duplicates, 0 malformed | was 251 |
| 4 | **Rules unit suite** (local emulator) | **9 suites, 211/211 PASS** | was 8 / 205 |
| 5 | **Transport verifier** | **93 PASS / 6 FAIL** | was 94 / 5 |
| 6 | RBAC ↔ Transport claim seam | **no orphaned `user_id` deps** (§3) | new check |

The rules suite gained a suite and 6 tests with the RBAC work, and all pass. Rules grew by 27 braces
and 9 match blocks — consistent with RBAC Phase A additions, structurally sound.

---

## 2. Verifier delta — one new failure, and it is a false positive

**93 PASS / 6 FAIL.** Five failures are the same test-tenant data artifacts carried since the TMS
certification (`F1-3`, `F2-2`, `F3-2`, `F5-3`, `F7-3` — seeded rows and `PROBE_CON1_*` / `TEXP0000*`
fixtures). Unchanged, still not code defects.

### The sixth is new: `CH-TRN-SEC1-1`

```
CH-TRN-SEC1-1 FAIL  source  attendance create bound to trip driver:
  SEC-1: attendance create not bound to trip driver
  (get(tripLogs).driver_staff_id == user_id missing) — any staff can forge a safety event
```

**The security control is INTACT. The verifier gate is stale.**

`firestore.rules:1805-1818` still binds non-admin `transportAttendance` creates to the driver of the
referenced trip:

```
get(/databases/$(database)/documents/tripLogs/$(...schoolId + '_' + ...tripId))
  .data.driver_staff_id == request.auth.uid
```

The gate at `Transport_verifier.php:4111` scans for the **old literal**:

```php
$bindsDriver = strpos($block, 'driver_staff_id == request.auth.token.user_id') !== false;
```

RBAC Phase A migrated the whole codebase from the custom `user_id` claim to `request.auth.uid`
(§3). The rule moved; the gate's pattern did not.

| | |
|---|---|
| Classification | **Weakness** — broken safety net, not a production defect |
| Severity | **Medium** |
| Production impact | **None.** Forged safety events are still blocked |
| Fix | One line — `Transport_verifier.php:4111` → match `driver_staff_id == request.auth.uid` |
| Why it matters | A permanently-red gate gets ignored, and the next *real* SEC-1 regression hides behind it |

`CH-TRN-SEC2-1` (tripLogs create bound to driver) still passes because it scans for `driverIs(`,
which survived the migration — confirming the drift is isolated to SEC1-1's pattern.

---

## 3. RBAC Phase A ↔ Transport `staff_id` seam — CLEAR

This was the highest-risk seam in the pull, because Transport F9's driver-scope rules were built on a
custom `user_id` claim minted from `staffId`, and RBAC Phase A rewrote claim minting.

**It was handled correctly.** Findings:

- **`driverIs()` was rewritten** (`firestore.rules:235-239`) to `request.auth.uid == staffId`, dropping
  the custom-claim dependency. The rules even document it: *"No custom `user_id`/`staffId` claim is
  required, so no Teacher-app change is needed."*
- **Zero `user_id` claim reads remain in the rules.** Verified by grep.
- `Firebase.php` emits `staffId` / `roleTier` **additively** for staff/admin tokens only, and
  `setCanonicalClaims` injects `staff_id = uid` — preserving the `uid == staffId` convention the rules
  now rely on.
- `Staff.php`'s `user_id` hits are a **form field** (the staff business ID), unrelated to claims.
- Kotlin `user_id` hits are local `TokenManager` preference keys — not claims.
- **New `staffCapabilities/{uid}` collection is correctly gated**: `allow read: if isAuth() && uid ==
  request.auth.uid; allow write: if false;` — owner-read, server-only write.

**Verdict: no cross-module break. The only casualty was the verifier gate in §2.**

### Watch item — rules document-access budget
The RBAC helpers (`staffCapsExist`, `hasCapability`, `hasCapabilityLevel`) add `get()`/`exists()` calls
against `staffCapabilities`. Total rules `get()`/`exists()` sites: **15**, across 6 helpers. Firestore
allows 10 document accesses per single-document request. Repeated access to the *same* path is cached
within one evaluation, so this is very likely fine — but it is now close enough to the ceiling that any
new `get()`-based helper needs a deliberate check. Not a defect; a budget to watch.

### Cosmetic — three stale comments
`firestore.rules:1742` and `:1795`, and `DriverHomeScreen.kt:54`, still describe the `user_id` claim
that no longer exists. Code is correct; comments mislead. Low.

---

## 4. Dual_write — the RTDB removal was reverted

The removal applied on 2026-08-06 (692 → 553 lines, 175 deletions) is **gone**. `Dual_write.php` is
staged as `M` and once again contains the full RTDB engine — `writeToRTDB`, `multiPathUpdate` with
`getDatabase()->getReference('/')->update()`, `_retriedRemove`, `rtdbOnly`, the 8 unused `writeX`
wrappers — and the class docstring again declares *"RTDB = primary source of truth (must succeed)."*

14 RTDB references are present in the file. The analysis behind the removal has not changed: all
RTDB-reaching methods had zero callers, and every live entry point is Firestore-only post-R7.

**Decision required** — see §6.

---

## 5. Findings summary

| ID | Finding | Class | Severity | Blocks release? |
|---|---|---|---|---|
| P2-1 | `CH-TRN-SEC1-1` gate pattern stale vs RBAC claim migration | Weakness | Medium | No — fix before certifying |
| P2-2 | Dual_write RTDB engine restored by the pull | Weakness | Medium | No — policy violation, not a defect |
| P2-3 | Rules `get()` budget at 15 sites after RBAC | Watch | Low | No |
| P2-4 | 3 stale `user_id` comments in rules + Teacher app | Cosmetic | Low | No |
| P2-5 | 5 test-tenant data artifacts (carried) | Data | Low | No |
| P2-6 | `noticeRecipients` still has no explicit rules block | Weakness | Low | No |

**No new production defects introduced by the pull.**

---

## 6. Still blocked — unchanged from the last report

| Gate | Status | Blocker |
|---|---|---|
| **5** — deploy rules + indexes to staging | **BLOCKED** | Is a deployment (forbidden), and **no staging project exists** (`H_lifecycle_l2_probe.php:6-8`) |
| **6** — Parent/Teacher apps vs CSRF / Play Integrity / rules | **PARTIAL** | Static analysis done; device builds + live backend not available here |
| **9** — full cross-module regression | **PARTIAL** | Transport done (93/6). Payroll, RBAC, Attendance, Exam, Docs/Admission, Auth have **no automated suites**. The ~30 `Transport_probe_*` runs **mutate production** (no staging) — not run without authorization |

Gate 9 now carries more weight than before: **RBAC Phase A is the largest untested surface in the
tree.** It rewrote permission resolution, claim minting and session keys across every module, and there
is no automated suite for it. Manual RBAC regression is now the single most important outstanding item.

---

## 7. Verdict

| | |
|---|---|
| New defects from the pull | **None** |
| RBAC ↔ Transport `staff_id` seam | **CLEAR** — migrated correctly |
| Verifier delta | 94/5 → 93/6, the new failure is a **false positive** (stale gate) |
| Rules suite | **211/211 PASS** |
| PHP lint | **134/134 clean** |
| Gates 5 / 6 / 9 | **Incomplete — environment-blocked** |
| **Production-ready** | **NOT CERTIFIED** |

Two items should be closed before any certification attempt: fix the `CH-TRN-SEC1-1` gate pattern
(P2-1) so the safety net works, and decide on Dual_write (P2-2).

Working tree otherwise untouched. No commits, no rebases, no release branch, no push, no deployment.
F12.1 remains design-frozen and unstarted.

*Working artifact; not committed to git.*
