# ATOMIC CAPACITY — FLAG ENABLEMENT RESULT (FINAL)

**`atomic_capacity_enabled = TRUE` · `capacity_group_max_students = 499` · 5/5 wired · 0 new failures**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. What changed

Two files, both authorized, nothing else.

**1 — `application/config/capacity_cas.php`** — one line:

```diff
@@ line 40 @@
-$config['atomic_capacity_enabled'] = false;
+$config['atomic_capacity_enabled'] = true;
```

`capacity_group_max_students` untouched at **499**.

> `git diff` on this file is empty because it is **untracked** — created in Phase 20.3H, never
> committed. The diff above is against the byte-identical pre-flip copy held outside the repo.

**2 — `tests/Unit/Phase203hAtomicCapacityWiringTest.php`** — one test method replaced:

`test_flag_default_is_false` → `test_approved_shipped_state_is_enabled_AND_all_five_paths_wired`

It no longer asserts a bare value. It asserts the **contract**, as two coupled assertions:

1. the shipped value is `true` — the approved state, with the authorisation date recorded in the
   failure message so a silent rollback is impossible;
2. and, inseparably, `array_diff(all five paths, wiredPaths())` is **empty** — failing loudly and
   **naming the missing paths** if any were unwired while the flag is on.

The two are asserted together on purpose: an enabled flag is only safe as a pair with complete wiring.
Flipping the literal alone — `false` → `true`, uncoupled — is exactly the change this file exists to
make impossible.

**No production code was touched.** Verified by digest in §7.

---

## 2. The wiring lock is intact and now doubly guarded

`test_FLAG_cannot_be_enabled_until_all_five_paths_are_wired` is **unmodified** and passes. With the
flag true it evaluates its enabled branch and asserts the unwired set is empty — so it is now
load-bearing in a way it could not be while the flag was off.

The updated test guards the same invariant from the other direction (shipped-state posture rather than
flag-vs-wiring). **Two independent tests, one invariant.** Nothing was weakened, bypassed or removed.

*Why this is not a vacuous check:* the `wiredPaths()` detector has been observed returning **4** in
Phase 20.3K, before Import was wired, and the expectation test failed then. Its markers were chosen to
have zero pre-existing occurrences in the source precisely so an unwired path cannot be mistaken for a
protected one.

---

## 3. Test results

| Suite | Result |
| --- | --- |
| Wiring-lock suite, flag TRUE | **OK — 14 tests, 45 assertions** |
| Phase 20.3K–20.3M suites | **OK — 44 tests, 145 assertions** |
| Full Unit suite | **1696 tests, 5355 assertions, 10 failures** |

---

## 4. Baseline vs new failures

| | Count |
| --- | --- |
| Documented baseline | **10** |
| New | **0** |
| Total | **10** |

The ten are exactly the documented baseline names, unchanged: `BackupTest`,
`HomeworkInputLengthValidationTest`, `HomeworkRosterFailClosedTest`, `HomeworkSchoolIdPredicateTest`,
`HomeworkSilentCatchTest`, `ParentHomeworkRepoSilentCatchTest` (×3),
`TeacherExecuteDeleteCascadeTest` (×2).

**ZERO additional failures.** The single failure introduced by the flip is resolved.

---

## 5. Final state confirmations

| Check | Result |
| --- | --- |
| `atomic_capacity_enabled` | **TRUE** |
| `capacity_group_max_students` | **499** |
| Wiring lock | **5/5** — Admission · Enroll · Import · Promotion · Transfer |
| Partial-enablement protection | **ACTIVE**, two independent tests |
| Rules / indexes changed | **NO** |
| RTDB changes | **NO** — the mechanism is Firestore-only |
| Unrelated files changed | **NO** |
| UNKNOWN-outcome retention policy | **UNCHANGED** (approved) |
| Live UAT after enabling | **NOT PERFORMED** — not required for a test-file change |
| Commit / Push / Deploy | **NO / NO / NO** |

---

## 6. Files changed

| File | Change |
| --- | --- |
| `application/config/capacity_cas.php` | one line — flag false → true |
| `tests/Unit/Phase203hAtomicCapacityWiringTest.php` | one test method replaced with the coupled shipped-state contract |

---

## 7. Production digests — unchanged, verified

| File | Digest |
| --- | --- |
| `application/core/MY_Controller.php` | `ecc08e75` |
| `application/libraries/Firestore_service.php` | `ed0750f0` |
| `application/helpers/rbac_helper.php` | `d6ca2adf` |
| `firebase-rules/firestore.rules` | `4f9ed6e1` |
| `firebase-rules/firestore.indexes.json` | `c473c914` |
| `application/controllers/Sis.php` | `9222e572` |
| `application/libraries/Section_seat_guard.php` | `312c01f4` |
| `application/libraries/Student_write_service.php` | `83a06c1b` |
| `application/controllers/Classes.php` | `adf75756` |

All identical to the readiness/enablement report. `firestore.rules` and `firestore.indexes.json` show
as `M` in `git status`, but that dirt is **pre-existing from other sessions** and their digests are
unchanged — this session did not touch either.

---

## 8. Observations

1. **The suite total fluctuates because another session is editing `tests/Unit/` concurrently.**
   It read 1696 before the flip, 1716 mid-run, and 1696 now.
   `MapsPlatformInheritanceTest` (45 tests) and `MapsProductionPathTest` (22 tests) were added by that
   session today; both pass in isolation. The **failure set** has been stable and identical across
   every run — the ten baseline names — which is the measure that matters here. Recorded, not acted on.
2. **The flag is live for any process reading this config**, including a local dev server if one is
   running. No live placement was exercised by this session.

---

## 9. OPERATOR COMMUNICATION — before anyone uses the feature

**1. A CAS conflict means RETRY, not failure.**
*"Another change to this section completed while your request was in progress"* / *"the section changed
while the import was in progress"* means **nothing was saved** — the write rolled back completely.
Reload and submit again. It is not an error to report.

**2. Import spanning multiple destination sections is processed PER DESTINATION SECTION.**
A file is split into one atomic group per class+section. **One section may succeed while another is
refused** in the same run. Each group is all-or-nothing: within a refused section no student is
imported, and every affected row is reported individually with its reason. This is a granularity
change from the previous row-by-row behaviour.

**3. An UNKNOWN Firestore outcome intentionally retains Student IDs.**
When a commit's result cannot be determined, claimed Student IDs are deliberately **not** released — a
student document may already exist, and reusing the number could create a duplicate identity. This can
leave gaps in the Student ID sequence. `ACC_IMPORT_ID_RETAINED_UNKNOWN` is logged with destination and
count for later reconciliation via `Id_generator::reconcile('STU')`.
**A non-gapless Student ID sequence is acceptable; a duplicate Student identity is not.**

---

## 10. State

```
atomic_capacity_enabled = TRUE          capacity_group_max_students = 499
5/5 paths wired · partial-enablement lock ACTIVE (two independent tests)
Full suite: 10 baseline failures · 0 new
Production code unchanged · rules, indexes and RTDB untouched
Two files changed, both authorized

NO COMMIT · NO PUSH · NO DEPLOY
```

**Awaiting your separate authorization for commit / push / deploy.**
