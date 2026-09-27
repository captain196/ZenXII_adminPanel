# PHASE 20.1 — Section Delete Safety + Exposure Probe

**Status:** COMPLETE · **NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Tenant probed:** `SCH_B56BB9A401`, session `2026-27`

Authorized decisions applied: **P20-D4 = YES** (fix now) · **P20-D9 = YES** (probe) ·
**P20-D2 = NO** (capacity engine untouched) · **P20-D3 = NO** (no backfill) · **P20-D5 = YES** (policy recorded, **not implemented** — that is Phase 20.2+).

---

## 1. Worktree safety

| Check | Result |
| --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` — unchanged |
| Staged files | **4, before and after** — `Admin_login.php`, `Staff.php`, `deptrole_helper.php`, `rbac_helper.php` |
| Staged content inspected | Zero references to `sectionKey`, `maxStrength`, `delete_section`, `_filterSectionRoster`, `_sectionCapacity` — **unrelated to this phase** |
| Stashes | 3, untouched. None contains section/capacity/classes work (grep count 0 on all three) |
| Reset / revert / stash / rebase | **None performed** |

### Protected files — verified byte-identical

SHA-256 recorded before any edit and re-verified after all work:

```
application/core/MY_Controller.php ......... OK
application/libraries/Firestore_service.php  OK
application/helpers/rbac_helper.php ........ OK
firebase-rules/firestore.rules ............. OK
firebase-rules/firestore.indexes.json ...... OK
```

**Rules and indexes were not opened for modification and were not deployed.** They remain dirty from
earlier unrelated work, exactly as found.

`School_config.php` already carried substantial uncommitted work (Phase 1 ZenX HRMS
organisation/terminology). All edits were exact-string replacements; that work is intact and still
present in the diff.

---

## 2. P20-D9 — read-only exposure probe

**Probe:** `scripts/p20f1_section_delete_exposure_probe.js` — new, read-only.
Contains **no** `.set()`, `.update()`, `.delete()`, `.add()`, `.create()`, batch or transaction, and
takes no `--commit`/`--apply` flag. Verified by grep before execution.
`firebase-admin` **12.7.0** (never v14).

### Actual counts — tenant `SCH_B56BB9A401`, session `2026-27`

| Metric | Count |
| --- | ---: |
| Sections (all sessions) | **35** |
| Sections in 2026-27 | **35** |
| Sections with `maxStrength > 0` | **0** |
| Sections **without** capacity (resolver → 40) | **35** |
| Students (tenant-wide) | **23** |
| camelCase `status` present | **23** |
| PascalCase `Status` present | **19** |
| **Both** shapes present | **19** |
| Neither (absent → canonical treats as Active) | **0** |
| Has `sectionKey` | **23** |
| **Missing `sectionKey`** | **0** |
| Sections holding students (canonical) | **12** |
| **Falsely empty (data-loss exposure)** | **0** |
| Over-blocked (cross-session) | **0** |
| Otherwise divergent | **0** |

### Verdict — stated plainly

> **There is NO live exposure on this tenant today.** Every one of the 23 students carries both a
> camelCase `status` and a `sectionKey`, so the vulnerable predicate happens to find all of them.
> The vulnerable and canonical rules agreed on all 35 sections.

**I am not manufacturing exposure that does not exist.** The defect is real *in code*; the current
*data* does not trigger it. It was fixed because the guard's correctness must not depend on a
coincidence of the current data — 19 of 23 students already carry the legacy `Status` shape in
parallel, and any import, older tenant, or writer that omits `sectionKey` reintroduces the gap.

**Scope limit:** the probe covered **one tenant, one session**. Other tenants were not probed.

**Incidental confirmation:** `35/35` sections have no `maxStrength` — Phase 20's **P20-F3** confirmed
empirically at 100%. Nothing was written to correct this (P20-D3 = NO).

---

## 3. Exact vulnerable paths

Three sites, all in `application/controllers/School_config.php`, all using the same predicate:

```php
schoolWhere('students', [['sectionKey','==',$k], ['status','==','Active']])
```

| # | Site | Blast radius on false "empty" |
| --- | --- | --- |
| 1 | `delete_section()` | Deletes one section document |
| 2 | `bulk_save_sections()` — remove branch | Deletes sections via matrix toggle |
| 3 | **`soft_delete_class()`** — *found in the Part 8 sweep, not in the original brief* | Removes the **whole class** from `schools.classes[]` while students remain enrolled |

Site 3 was not in the Phase 20 findings. It was found by the post-fix static sweep, has the largest
blast radius of the three, and is fixed here.

---

## 4. Root cause

A Firestore equality predicate binds to **one exact field name**. The `students` collection carries
**both** legacy PascalCase (`Status`, `Class`, `Section`) and canonical camelCase shapes, and legacy
documents may carry no `sectionKey` composite at all. Such a student is invisible to that predicate,
so the guard saw zero students, called the section empty, and proceeded to delete.

This is the same anti-pattern already documented at `Classes.php:245-255`, which caused a production
incident (`Attendance.php:1218` — *"students disappear from parent/teacher views after admin bulk
saves"*).

### Second root cause found during tracing — fail-open on error

`_dep_count_safe()` (`:292`) catches per-check exceptions, records `errors[key]`, and **leaves
`has_any` false**. A failed students query therefore counted as *zero students* and the delete
proceeded. That is a fail-**open** on the one check that protects children — independent of the
predicate-shape defect, and equally fatal.

`bulk_save_sections()` was *not* affected by this second defect: its `try/catch` wraps the
`fs->remove()` too, so a throwing query skips the delete. `delete_section()` was affected.

---

## 5. The fix

### 5.1 One canonical definition

Added `School_config::_section_active_students()` — **the single definition of "does this section
hold active students?"** It creates no new roster query, no new status normalisation, no new section
filter, and no capacity logic. It delegates to the existing spine primitives.

### 5.2 Why two signals — and why that is still ONE rule

Both signals are passed through the **same** `MY_Controller::_filterSectionRoster()`. There is one
membership rule; the signals differ only in **where the documents come from**, because each source
has a failure mode the other does not:

| Signal | Source | Catches | Blind spot |
| --- | --- | --- | --- |
| **A** | `_cachedStudentDocsForRollCheck()` snapshot | students with **no `sectionKey`**; both field shapes | returns `[]` on error (`MY_Controller:1404`) — indistinguishable from "no students" |
| **B** | indexed `schoolWhere` (cap 200), then re-filtered canonically | snapshot staleness/failure | needs `sectionKey` |

A section is deletable only when **both** agree it is empty (`max(A,B) === 0`). Signal A alone would
have replaced one fail-open with another; that is why B is retained rather than deleted.

Because **both** results pass through the session-aware filter, a 2025-26 student cannot block a
2026-27 section (Part 4). Disagreement therefore indicates snapshot staleness — not a legitimate
cross-session student — and is logged as `ACC_SECTION_ROSTER_DIVERGENCE` with both counts.

### 5.3 Fail closed

`determined` is false if **either** source throws. All three call sites refuse the delete and return
HTTP 409 with `reason: roster_undetermined`, plus an `ACC_SECTION_DELETE_UNDETERMINED` error log.
**Uncertainty is never treated as an empty section.**

`delete_section()` additionally now refuses when **any** of the five remaining dependency checks
errored (`reason: dependencies_undetermined`), closing the `_dep_count_safe` fail-open.

The other five checks (feeDemands, attendance, marks, timetables, subjectAssignments) keep their
`sectionKey` predicate: those collections are canonically written *with* `sectionKey`, so the
predicate is correct for them. Only the `students` check moved out.

### 5.4 Response shape preserved

The canonical count is folded back into `deps['by_key']['students']`, so `dependencies.students` in
the JSON response is unchanged for the UI. No client change required.

---

## 6. Canonical helpers reused — nothing duplicated

| Reused | From |
| --- | --- |
| `_filterSectionRoster()` | `MY_Controller:1258` |
| `_cachedStudentDocsForRollCheck()` | `MY_Controller:1388` |
| `sectionDocId()` | `Firestore_service:1286` (existing call sites, unchanged) |

**Not created:** no second roster query, no second status normaliser, no second section filter, no
second capacity engine. `_sectionCapacity()` and D17 semantics were **not touched** (P20-D2 = NO).

Test `T-9` asserts exactly **three** call sites and exactly **one** definition.

---

## 7. Session safety (Part 4)

- Section identity is still resolved **only** through `sectionDocId()`. No hand-built ids were
  introduced.
- Both signals are session-filtered by the canonical rule; blank session = legacy document, kept.
- `soft_delete_class()` uses **each section document's own session**, because that loop walks a
  class's sections across sessions and a student in any of them must block removal of the class
  definition.
- Verified by tests **T-7** (other session does not block) and **T-7b** (blank session still blocks).

---

## 8. Section identity safety (Part 5)

**Storage contract unchanged.** No field added, removed or renamed. `section = "Section A"` /
`sectionCode = "A"` relationship untouched. **No rename functionality was added or exposed.**
`schoolId`, `session`, `className`, `section`, `sectionKey`, `sectionCode` all unmodified.

---

## 9. SRNO audit (Part 6 — mandatory)

| Check | Result |
| --- | --- |
| `srNo` substituted with Roll No | ❌ none |
| `srNo` used as foreign key | ❌ none |
| `srNo` modified by section deletion | ❌ **none** — the decision is read-only over student docs |
| `srNo` present in deletion logic | ❌ **none** — asserted by test **T-10b** on the helper body |
| Identity independent of class/section/roll | ✅ preserved |

`School_config` references `rollNo` at `:4265`, `:4306`, `:4324` — all inside the **rollover** path,
carrying roll numbers forward. Legitimate, pre-existing, **not touched**, and outside the delete
guards. `T-10b` is scoped to the helper body precisely so it asserts something true rather than
something convenient.

**No SRNO issue found. No unrelated SRNO finding was fixed** (scope discipline per Part 6).

---

## 10. Tests

**New:** `tests/Unit/Phase201SectionDeleteSafetyTest.php` — 17 tests, 38 assertions.

**Extended, not duplicated:** `Phase166SectionRosterAndDeadFallbackTest` already covers the roster
*rule* (session scoping, blank-session tolerance, withdrawn exclusion, dual shapes). This file tests
the **delete decision** built on top of it, and says so in its header.

| ID | Test | Result |
| --- | --- | --- |
| T-1 | Empty section → deletable | ✅ |
| T-2 | camelCase Active student → blocked | ✅ |
| T-3 | **PascalCase `Status` student → blocked** (asserts old guard saw 0) | ✅ |
| T-4 | Mixed shapes → both counted, blocked (old guard saw 1 of 2) | ✅ |
| T-5 | **Missing `sectionKey` → blocked** (old guard saw 0) | ✅ |
| T-6 | Withdrawn/Inactive only → deletable | ✅ |
| T-7 | Other-session student → does **not** block | ✅ |
| T-7b | Blank-session legacy student → blocks | ✅ |
| T-8 | Snapshot failure → fail closed | ✅ |
| T-8b | Indexed failure → fail closed | ✅ |
| T-8c | Both fail → fail closed | ✅ |
| T-8d | Silently-empty snapshot rescued by signal B | ✅ |
| T-8e | Malformed rows → no fatal, no false-empty | ✅ |
| T-9 | All 3 paths use the one helper; exactly 1 definition | ✅ |
| T-9b | Only 1 `sectionKey`+`status` predicate survives (signal B) | ✅ |
| T-10 | Delete decision mutates no student doc; SRNO/Roll intact | ✅ |
| T-10b | Helper body is identity-blind and cannot write | ✅ |

T-3, T-4 and T-5 **prove the regression** by asserting the old predicate's blindness as a
precondition before asserting the fix — they would pass trivially otherwise.

### Results

```
Phase 20.1 file : OK (17 tests, 38 assertions)
Full Unit suite : Tests: 1486, Assertions: 4095, Failures: 10
```

**10 failures = the pre-existing baseline, unchanged.** Verified failure-for-failure:
`BackupTest::size_human` ×1, `Homework*` ×4, `ParentHomeworkRepoSilentCatch` ×3,
`TeacherExecuteDeleteCascade` ×2 — all cross-repo/format drift documented in `CLAUDE.md`.
**Zero new failures. I am not claiming the suite is green — it is not, and was not before.**

> **Honest limitation:** these are unit tests over a faithful mirror of the helper, in the pattern
> the existing Phase15–Phase18 section tests already use. They do **not** execute CodeIgniter's
> request stack or hit Firestore. Runtime behaviour of the endpoints is **not** proven — see §13.

---

## 11. Performance (Part 9)

Measured from the implementation, not claimed as an improvement.

Reads in the helper: **exactly two sources** — `_cachedStudentDocsForRollCheck()` (`:346`) and one
bounded `schoolWhere` (`:356`, cap 200). **No per-student query. No N+1.**

| Path | Before | After |
| --- | --- | --- |
| `delete_section` (1 section) | 1 unbounded students query | 1 **bounded** (200) query + snapshot (0 reads if warm, 1 if cold) |
| `bulk_save_sections` (N removals) | N unbounded queries | N bounded queries + **1** snapshot for the whole request |
| `soft_delete_class` (M sections) | M unbounded queries | M bounded queries + **1** snapshot |

The snapshot is `static`-memoised per request and file-cached for 90 s under the key
`Sis::_cached_student_docs()` uses, so a warm cache from the student list, ID card or fee search
serves it for free.

**Net: +1 collection read per request in the worst (cold) case, +0 when warm.** Each per-section
query is now capped at 200 where it previously was not.

**No performance improvement is claimed.** This is a correctness fix with a bounded, small cost.

---

## 12. Static regression sweep (Part 8) — new finding registered

Swept `sectionKey`, `status`, `Status`, `delete_section`, `remove_section`, `maxStrength`,
`_sectionCapacity`, `_filterSectionRoster`. No blind global replacement was performed.

### P20-F8 — `delete_stream()` repeats the pattern — **REGISTERED, NOT FIXED**

`School_config::delete_stream():3026` blocks stream deletion with:

```php
['streamKey', '==', $streamKey], ['status', '==', 'Active']   // :3095, collection 'students'
```

Same single-shape `status` defect: a PascalCase-`Status` student would not block deletion of a stream.

**Deliberately not fixed in this phase.** It filters by `streamKey`, not `className`/`section`, so
`_filterSectionRoster()` — which matches on class and section — **cannot express it**. Fixing it
correctly requires a stream-membership rule that does not exist, and Part 3 explicitly forbids
creating new roster logic here. Fixing it by copying the section rule would be wrong.

**Recommend a scoped Phase 20.1b.** Blast radius is lower than the section guards (stream deletion,
not class/section deletion), and the same `_dep_count_safe` fail-open applies to it.

No other site repeating the pattern was found.

---

## 13. What was only inspected, and what remains open

### Fixed and tested
- P20-F1 at all three section/class delete sites
- `_dep_count_safe` fail-open in `delete_section`

### Inspected only — **not** exercised at runtime
- The three endpoints were **not** invoked through a browser or HTTP client. No UAT was executed.
- The `determined === false` branch is proven by unit test, **not** by inducing a real Firestore
  failure.
- `ACC_SECTION_ROSTER_DIVERGENCE` has never fired against live data.

### Registered, not fixed
| ID | Item |
| --- | --- |
| **P20-F8** | `delete_stream()` single-shape predicate (§12) |
| P20-F2 | "Unlimited" is unreachable dead code — **P20-D2 = NO**, untouched |
| P20-F3 | `saveSection()` never stamps `maxStrength` — confirmed 35/35; **P20-D3 = NO**, untouched |
| P20-F4 | Three creation paths, three validation rules |
| P20-F5 | `fetch_class_sections` at 2,092 ms |

### Not done, by instruction
No capacity UI, no occupancy UI, no strength editing, no bulk roll UI, no section-creation redesign,
**no legacy deletion**. `Classes.php`, its routes and its views are **untouched**. **P20-D5 policy is
recorded in the Phase 20 design, not implemented.**

---

## 14. Legacy retirement status

**NOT RETIRED. NOTHING DELETED.**

`Classes.php` (981 lines), `class_profile.php`, `section_students.php` and `manage_classes.php` all
remain in place and functional. The Phase 20 plan — migrate capacity into the Sections tab, soak,
then retire — is unchanged and unstarted.

**The Sections & Seats module is NOT complete. The legacy module is NOT retired.**

---

## 15. Files changed

| File | Change |
| --- | --- |
| `application/controllers/School_config.php` | **modified** — new `_section_active_students()`; 3 guards rerouted; fail-closed on undetermined roster and on dependency-check errors |
| `tests/Unit/Phase201SectionDeleteSafetyTest.php` | **new** — 17 tests |
| `scripts/p20f1_section_delete_exposure_probe.js` | **new** — read-only probe |
| `PHASE20.1_SECTION_DELETE_SAFETY_REPORT.md` | **new** — this report |

`php -l` clean on every modified PHP file.

---

## 16. Final status

```
PHASE 20.1 COMPLETE — SECTION DELETE SAFETY
NOT COMMITTED
NOT PUSHED
NOT DEPLOYED
UAT NOT EXECUTED
```

Stopping here as instructed. **Phase 20.2 not started and will not be started without review.**
