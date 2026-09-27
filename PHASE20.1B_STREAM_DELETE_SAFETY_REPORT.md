# PHASE 20.1b — Stream Delete Safety

**Status:** COMPLETE · **NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Tenant probed:** `SCH_B56BB9A401`, session `2026-27`

---

## 1. Worktree safety

| Check | Result |
| --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` — unchanged |
| Staged | **4 before, 4 after** — `Admin_login.php`, `Staff.php`, `deptrole_helper.php`, `rbac_helper.php` |
| Stashes | **3 before, 3 after** — untouched |
| Reset / revert / stash / rebase / clean | **none performed** |

### Protected files — verified byte-identical (SHA-256, before and after)

```
application/core/MY_Controller.php ......... OK
application/libraries/Firestore_service.php  OK
application/helpers/rbac_helper.php ........ OK
firebase-rules/firestore.rules ............. OK
firebase-rules/firestore.indexes.json ...... OK
```

**Rules and indexes were not opened for modification.** Phase 20.1's work
(`_section_active_students`, its 3 call sites, `Phase201SectionDeleteSafetyTest`,
`p20f1_section_delete_exposure_probe.js`) was re-verified present and intact before starting.
Fees / ID Card / SRNO / HRMS / Class-Section work untouched.

---

## 2. Source-traced stream membership model (Part 1)

Answered from source and confirmed on live data — **not** assumed.

| # | Question | Answer |
| --: | --- | --- |
| 1 | Is `streamKey` the canonical student↔stream relationship? | **No. It does not exist on students.** No writer anywhere stamps it. |
| 2 | Another canonical stream identifier on students? | **None.** No `stream`, `Stream`, `streamName`, `stream_key` either. |
| 3 | Class-scoped? | Stream *definitions* are school-level (`schools.streams{}`), applied in practice to senior classes. |
| 4 | Section-scoped? | **Yes — this is the only real link.** Via stream-named sections. |
| 5 | Session-scoped? | Stream **definitions are not** session-scoped. The dependency checks are. |
| 6 | Student with a stream but no section? | **Impossible** — the stream is carried *by* the section. |
| 7 | Student with a section but no stream? | **Yes, the normal case.** |
| 8 | Legacy students missing `streamKey`? | **All of them — 23/23 (100%).** |
| 9 | Legacy `Stream`/`stream` shapes on students? | **None exist.** |
| 10 | What does the system consider an active stream member? | **Nothing did.** No implemented notion of student stream membership existed before this phase. |

### The one real link

`bulk_save_sections()` accepts stream-named section labels — its regex
`/^(?:[A-Za-z]+(?: [A-Za-z]+)* )?[A-Z]$/` allows `"Science A"`, not just `"A"`.
`Entity_firestore_sync::normalizeClassSection()` (`:196-202`) normalises that to:

```
section     = "Section Science A"
sectionCode = "Science A"
```

So **a student is in stream K when their section token is `"{K} {letter}"`.** That is the entire
student↔stream data model.

### Where `stream` genuinely is written

| Collection | Field | Written by |
| --- | --- | --- |
| `subjects` | `stream` | `School_config.php:2388` ✅ |
| `students` | — | **nothing** |
| `sections` | — | **nothing** (`saveSection` does not stamp it; `Academic.php:847` and `Classes.php:340/595` do not either) |

---

## 3. Read-only exposure probe (Part 3)

**Probe:** `scripts/p20f8_stream_delete_exposure_probe.js` — new, read-only. Contains no `.set()`,
`.update()`, `.delete()`, `.add()`, `.create()`, batch or transaction, and no `--commit`/`--apply`.
Verified by grep before execution. `firebase-admin` **12.7.0**.

### Actual counts — `SCH_B56BB9A401`, session `2026-27`

| Metric | Count |
| --- | ---: |
| **Streams defined** (`schools.streams`) | **0** |
| Students | 23 |
| with `streamKey` | **0** |
| with `stream` / `Stream` / `streamName` / `StreamKey` / `stream_key` | **0 each** |
| **ANY stream field at all** | **0** |
| camelCase `status` | 23 |
| PascalCase `Status` | 19 |
| Sections | 35 |
| with `streamKey` | **0** |
| with `stream` | **0** |
| **stream-named sections** (`"Section X A"`) | **0** |
| `subjectAssignments` total / with `stream` | 17 / **0** |
| **`subjects` total / with `stream`** | 30 / **30** ← *not checked by the guard* |
| Falsely-empty streams | **0** (no streams exist to delete) |
| Cross-session disagreements | **0** |

### Verdict — stated plainly

> **This tenant has zero streams defined, so `delete_stream()` is currently unreachable here.**
> There is **no live exposure**, and I am not manufacturing one.

**Other tenants were not probed.** Doing so would require enumerating tenants and reading their
student collections; that was not authorised and is not claimed.

**The structural finding stands regardless of data**, and is stronger than a data finding: the
student check could never have worked on *any* tenant, because the field it queries is never written.

---

## 4. Exact vulnerable path and root cause (Parts 4–5)

`School_config::delete_stream()` blocked deletion with three checks:

```php
sections            [['streamKey','==',$k], ['session','==',$s]]     // field never written
subjectAssignments  [['stream','==',$k],    ['session','==',$s]]     // field IS written
students            [['streamKey','==',$k], ['status','==','Active']] // field never written
```

### Root cause — different in kind from what Phase 20.1 predicted

Phase 20.1 registered P20-F8 as *another single-shape `status` predicate*. **That framing was
wrong, and correcting it matters.**

**No code anywhere in this application writes `streamKey` — or any stream field — onto a student
document.** Verified by exhaustive grep (`streamKey` appears only in `School_config.php`, the
`school_config` view, and log lines) and confirmed on live data (0 of 23 students).

So that predicate **always returned 0**. It was **inert, not shape-sensitive.** Changing
`status` → `Status` would not have altered its result by a single row. Had this phase applied the
Phase 20.1 pattern mechanically, it would have produced a fix that looked correct and protected
nothing.

The same is true of the `sections` check: `sections.streamKey` is written by nobody and was found on
0 of 35 documents. **Two of the three dependency checks were inert.**

### Second defect — carried over

`_dep_count_safe()` swallows per-check exceptions and leaves `has_any` false, so a failed check reads
as "no dependencies" and the delete proceeds. Same fail-open closed for section deletion in Phase 20.1.

---

## 5. Canonical membership rule and fix (Parts 6–7)

### 5.1 One rule, one definition

Added to `School_config.php`:

| Member | Role |
| --- | --- |
| `_sectionBelongsToStream()` | **the single membership primitive** — `"{stream} {letter}"`, accepting `"Section Science A"` or `"Science A"`, case-insensitive |
| `_stream_active_students()` | student membership, tri-state |
| `_stream_live_sections()` | section membership, tri-state — restores the *intended* behaviour of the inert `sections` check |

Both consumers call the **same** primitive (asserted by test **T13**: exactly 2 call sites of
`self::_sectionBelongsToStream(`).

Status and session semantics are transcribed from the canonical roster rule — both field shapes,
**absent status means Active** (`MY_Controller:1280`), **blank session kept as legacy**
(`MY_Controller:1288`). **No new session policy was invented** (Part 5).

### 5.2 One signal, not two — deliberately (Part 6)

Phase 20.1 used two signals because two sources existed with different blind spots. **Here a second
indexed signal is impossible: there is no student-level stream field to index on.** Copying that
architecture would have meant inventing an index over data that does not exist.

The snapshot's own failure mode is still handled. `_cachedStudentDocsForRollCheck()` returns `[]` on
error (`MY_Controller:1404`), indistinguishable from "school has no students". A **bounded
one-document existence probe** separates the two:

| Snapshot | Probe | Result |
| --- | --- | --- |
| non-empty | — | membership computed |
| empty | finds ≥1 student | **UNDETERMINED** (snapshot is broken) |
| empty | finds 0 | **EMPTY** (school genuinely has no students) |
| throws | — | **UNDETERMINED** |

That probe applies no membership rule; it is an existence check, not a second roster query.

### 5.3 Tri-state, fail closed (Part 4)

`EMPTY | NON_EMPTY | UNDETERMINED`. Deletion requires an explicit `EMPTY`. A boolean was deliberately
not used, so uncertainty cannot collapse into "zero students".

`delete_stream()` returns **HTTP 409** with `reason: dependencies_undetermined` and logs
`ACC_STREAM_DELETE_UNDETERMINED` when either check is undetermined, and on any surviving
`_dep_count_safe` error.

### 5.4 Cap saturation — a fail-open I introduced and then closed

`_stream_live_sections()` scans sections bounded at 500. A saturated scan that finds nothing is **not
proof of emptiness** — it is exactly the fail-open this phase exists to remove. Saturation now
returns `UNDETERMINED` and logs `ACC_STREAM_SECTIONS_SCAN_SATURATED` (test **T11b/T11c**).

### 5.5 Response shape preserved

`dependencies.students` and `dependencies.sections` are still populated, so the UI needs no change.

### 5.6 Not changed

Stream naming, class/section identity, capacity logic, roll logic, `subjectAssignments` check (its
`stream` field **is** real, so its predicate is correct), `save_stream`/`save_streams`/`seed_streams`.

---

## 6. Tests (Part 10)

**New:** `tests/Unit/Phase201bStreamDeleteSafetyTest.php` — **26 tests, 62 assertions, all passing.**

| ID | Test | Result |
| --- | --- | --- |
| T1 | Empty stream → deletable | ✅ |
| T2 | camelCase Active member → blocked | ✅ |
| **T2b** | **Old guard returned 0 even for a perfect camelCase Active student** — proves inertness | ✅ |
| T3 | PascalCase `Status` member → blocked | ✅ |
| T4 | Mixed shapes → both counted | ✅ |
| T4b | Absent status entirely → counts as Active | ✅ |
| T5 | Bare `"Science A"` and prefixed forms both recognised | ✅ |
| T6 | Membership holds with **no** stream field present | ✅ |
| T6b | Other streams / plain sections / prefix-bleed do not match | ✅ |
| T7 | Withdrawn-only → deletable | ✅ |
| T8 | Other-session student → does not block | ✅ |
| T9 | Blank-session legacy student → blocks | ✅ |
| T10 | Snapshot exception → UNDETERMINED | ✅ |
| T10b | Blank stream key → UNDETERMINED | ✅ |
| T11 | Malformed rows → no fatal, no false-empty | ✅ |
| T11b | **Saturated sections scan → UNDETERMINED** | ✅ |
| T11c | Saturation guard present in source | ✅ |
| T12 | Empty snapshot while students exist → UNDETERMINED | ✅ |
| T12b | Genuinely empty school → EMPTY (not blocked forever) | ✅ |
| T12c | Probe exception → UNDETERMINED | ✅ |
| T13 | All paths use the canonical helpers; one definition each | ✅ |
| T13b | Inert `streamKey`+`status` predicate gone entirely | ✅ |
| T13c | `delete_stream` is the only removal endpoint | ✅ |
| T14 | Decision mutates no student document | ✅ |
| T15 | SRNO/Roll intact **and** unreferenced by the helper | ✅ |
| T16 | No capacity/roll logic in the helper; helper cannot write | ✅ |

**T2b is the test that matters most.** It asserts the old guard's inertness against a *fully
canonical* student — demonstrating that a status-shape-only fix would have been worthless.

### Results

```
Phase 20.1b file : OK (26 tests, 62 assertions)
Full Unit suite  : Tests: 1512, Assertions: 4157, Failures: 10
```

**10 failures = the unchanged pre-existing baseline**, verified failure-for-failure (Backup ×1,
Homework ×4, ParentHomeworkRepo ×3, TeacherExecuteDeleteCascade ×2). Suite grew 1486 → 1512 (+26).
**Zero new failures. The suite is not green and was not green before.**

> **NOT TESTED AT RUNTIME:** `delete_stream()` was **not** invoked via browser or HTTP client. The
> `UNDETERMINED` branches are proven by unit test, not by inducing a real Firestore failure.
> `ACC_STREAM_DELETE_UNDETERMINED` and `ACC_STREAM_SECTIONS_SCAN_SATURATED` have never fired against
> live data. **This tenant has 0 streams, so the endpoint could not be exercised end-to-end at all.**

---

## 7. Performance (Part 11)

| | Before | After |
| --- | --- | --- |
| `sections` | 1 indexed query (`streamKey`+`session`), cap 200 | 1 unfiltered scan, cap 500, filtered in PHP |
| `subjectAssignments` | 1 indexed query, cap 200 | **unchanged** |
| `students` | 1 indexed query (`streamKey`+`status`), cap 200 | 0 reads if snapshot warm; 1 collection read if cold; +1 one-document probe only when the snapshot is empty |

**Reads: 3 before → 2–3 after.** Roughly neutral.

- **Cache reused:** the students snapshot is `static`-memoised per request and file-cached 90 s under
  the same key `Sis::_cached_student_docs()` uses — a warm cache from the student list, ID card or
  fee search serves it free.
- **No N+1 introduced.** No per-student and no per-section query. Membership is decided in memory.
- **Max query size:** students unbounded (whole-school snapshot, pre-existing pattern); sections
  capped at 500 **with saturation failing closed**; subjectAssignments 200.

### Index requirement — none, and strictly reduced

The fix **removes** two indexed predicate pairs (`sections.streamKey`+`session`,
`students.streamKey`+`status`) and **adds none**. New queries carry no field predicates at all.

> **`firebase-rules/firestore.indexes.json` was NOT modified and no new index is required.**
> Verified byte-identical by SHA-256.

---

## 8. Static sweep (Part 14)

Swept `streamKey`, `Stream`, `stream`, `status`, `Status`, `delete_stream`, `remove_stream`,
`_dep_count_safe`. No blind replacement performed.

### Stream deletion paths — all enumerated

| Endpoint | Deletes a stream? | Action |
| --- | :-: | --- |
| `delete_stream():3200` | **Yes** | **FIXED** |
| `save_stream():2960` | No | Upsert only — verified non-destructive |
| `save_streams():3075` | No | *"Upsert incoming set into the existing map (non-destructive)"* — verified |
| `seed_streams():1475` | No | Seeds defaults |

**`delete_stream()` is the only stream removal path.** It removes the key from `schools.streams{}`
under a CAS precondition; it does not cascade into sections or alter student records.

### FOUND BUT NOT FIXED — registered

**P20-F9 — `_dep_count_safe()` fail-open remains live at three call sites.**

| Site | Guards | Severity |
| --- | --- | --- |
| `delete_session():5007` (dep at `:5060`) | deletion of an **entire academic session** across 10+ collections, including `students` by `session` | **HIGH** |
| `soft_delete_class():1082` (class-level sweep at `:1377`) | non-section-keyed records orphaning against a deleted class | MEDIUM |
| `delete_subject():2589` (dep at `:2613`) | `subjectAssignments` referencing a subject | LOW |

Not fixed here: each guards a different membership model (session-wide, class-wide, subject-wide).
Fixing them needs a different architecture, and Part 8 says register rather than expand scope
silently. `delete_section()` and `delete_stream()` — the two in scope — **are** fail-closed.

> **Recommended:** a scoped phase that makes `_dep_count_safe()` itself return an explicit
> `undetermined` state all five callers must honour. That is a single central change, but it alters
> behaviour at five endpoints at once and should be authorised deliberately.

**P20-F10 — `subjects.stream` is referenced but unguarded.** `subjects` is the one collection that
genuinely carries `stream` (30/30 documents on this tenant), and `delete_stream()` does **not** check
it. Deleting a stream leaves subjects tagged with a dangling stream key. **Not fixed:** adding a new
block is a behavioural change beyond "restore the intended guard", and the operator's stated
invariant concerns students.

**P20-F11 — single-shape student predicates outside delete guards.** `Communication.php:1625`
(`students` by `status`) and `Sis.php:1590` (`className`+`section`+`status` in the promotion path).
Neither is a deletion guard; both are read/notify paths. Registered only.

---

## 9. SRNO audit (Part 9 — mandatory)

| Check | Result |
| --- | --- |
| SRNO used as stream membership | ❌ **none** — membership is section-derived |
| SRNO used as a foreign key | ❌ none |
| Stream deletion modifies SRNO | ❌ **none** — decision is read-only (T14) |
| Stream deletion modifies Roll No | ❌ none |
| Admission No / Student ID / Roll No substitution | ❌ none |
| Identity independent of stream/class/section | ✅ preserved |
| Helper references identity fields | ❌ **none** — asserted by T15 on the helper body |

**No SRNO issue found.** No unrelated SRNO finding was fixed (scope discipline).

---

## 10. Status ledger

### FIXED
- **P20-F8** — `delete_stream()` student check: replaced an **inert** predicate with the canonical
  stream-membership rule, tri-state and fail-closed.
- `delete_stream()` **sections** check — also inert; intended behaviour restored via the same rule.
- `_dep_count_safe` fail-open **at `delete_stream()`** — now returns 409.
- Cap-saturation fail-open in the new sections scan — closed before reporting.

### FOUND BUT NOT FIXED (registered)
P20-F9 (fail-open at 3 remaining `_dep_count_safe` sites, `delete_session` HIGH) ·
P20-F10 (`subjects.stream` unguarded) · P20-F11 (single-shape predicates outside delete guards) ·
P20-F2, P20-F3, P20-F4, P20-F5 from Phase 20 — all untouched.

### INSPECTED ONLY
`save_stream`, `save_streams`, `seed_streams` (verified non-destructive, unmodified) ·
`subjectAssignments` check (predicate verified correct, unmodified).

### NOT TESTED AT RUNTIME
`delete_stream()` end-to-end · all `UNDETERMINED` branches · both new log signals.
**No UAT executed. No production deployment.**

---

## 11. Remaining Class/Section issues

Unchanged from Phase 20: **P20-F2** (unlimited unreachable — D2 = NO), **P20-F3** (`maxStrength`
never stamped; 35/35 sections confirmed without capacity — D3 = NO, deliberately not backfilled),
**P20-F4** (three creation paths, three validation rules), **P20-F5** (`fetch_class_sections`
2,092 ms).

---

## 12. Legacy retirement status

**NOTHING DELETED.** `Classes.php`, its routes and views, and all legacy `School_config` stream /
section / class views remain in place and functional.

**The Sections & Seats module is NOT complete. The legacy module is NOT retired.**

---

## 13. Phase 20.2 readiness

**Not started, as instructed.** No capacity UI, occupancy UI, strength editing, section-creation
redesign, bulk roll assignment or legacy retirement was implemented.

Before Phase 20.2 the operator should decide:

1. **P20-F9** — authorise the central `_dep_count_safe` fail-closed change? `delete_session()` is the
   sharpest edge still open.
2. **P20-F10** — should `delete_stream()` block on `subjects`?
3. Whether browser UAT is wanted on the delete paths first — noting **this tenant has 0 streams**, so
   exercising `delete_stream()` at all would require creating one.

---

## 14. Files changed

| File | Change |
| --- | --- |
| `application/controllers/School_config.php` | **modified** — `_stream_active_students()`, `_stream_live_sections()`, `_sectionBelongsToStream()`; `delete_stream()` rerouted, tri-state, fail-closed |
| `tests/Unit/Phase201bStreamDeleteSafetyTest.php` | **new** — 26 tests |
| `scripts/p20f8_stream_delete_exposure_probe.js` | **new** — read-only probe |
| `PHASE20.1B_STREAM_DELETE_SAFETY_REPORT.md` | **new** — this report |

`php -l` clean on every modified PHP file.

---

```
PHASE 20.1B COMPLETE — STREAM DELETE SAFETY
NOT COMMITTED
NOT PUSHED
NOT DEPLOYED
UAT NOT EXECUTED
```

Stopping here. **Phase 20.2 will not be started without review.**
