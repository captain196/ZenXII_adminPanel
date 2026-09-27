# PHASE 20.1c — Class/Section/Stream Dependency Integrity + Fail-Closed Audit

**Status:** COMPLETE · **NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Tenant probed:** `SCH_B56BB9A401`, session `2026-27`

---

## 1. Executive summary

| Finding | Outcome |
| --- | --- |
| **P20-F9** — `_dep_count_safe()` fail-open at 3 remaining callers | **FIXED** — one shared guard, collector untouched |
| **P20-F10** — `subjects.stream` dangling reference | **NOT A DEFECT — deliberately NOT fixed.** Evidence below. Phase 20.1b's registration is corrected. |
| **P20-F12 — NEW, HIGH** — phantom-success UI on 2 delete handlers | **FIXED** — the UI was reporting success while the server refused |
| **P20-F13 — NEW** — `attendance.session` missing on legacy docs | **FOUND — NOT FIXED**, registered |
| **P20-F14 — NEW, PROCESS** — `firestore.rules` changed by another session mid-phase | **REPORTED, NOT TOUCHED** |

### The two findings that matter most

**P20-F12 is the significant discovery of this phase.** Two delete handlers in the Academic Setup
view removed the row from local state *before* calling the server and then discarded every error.
So when the backend correctly refused — including via the fail-closed paths Phase 20.1 and 20.1b
built — **the operator watched the item disappear and was told nothing.**

That means the last two phases of hardening were, on those two paths, *invisible*. The server
protected the data; the UI reported the opposite. Backend correctness that the operator cannot see
is not safety.

**P20-F10 is a correction to the previous report.** Phase 20.1b registered it as a real dependency
gap. Tracing and live data prove it is not one, and implementing the "fix" would have been exactly
the kind of change the brief warns against — code that looks safer while protecting nothing.

---

## 2. Worktree safety (Part 0)

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged | 4 | **4** |
| Stashes | 3 | **3** |
| Modified | 117 | 117 (+2 already-modified files edited) |
| Untracked | 485 | 487 (+2 new files by me) |

No reset, clean, checkout, stash, rebase or revert was performed. Fees / ID Card / SRNO / HRMS work
untouched.

### Protected files

```
application/core/MY_Controller.php ......... OK  (byte-identical)
application/libraries/Firestore_service.php  OK  (byte-identical)
application/helpers/rbac_helper.php ........ OK  (byte-identical)
firebase-rules/firestore.indexes.json ...... OK  (byte-identical)
firebase-rules/firestore.rules ............. CHANGED — NOT BY ME  ← see P20-F14
```

### P20-F14 — `firestore.rules` changed by another session mid-phase

**REPORTED, NOT TOUCHED.** Stopping to report before continuing, per Part 0 rule 9.

- I never opened this file for editing. **The file contains zero Phase 20.x content** — verified by
  grepping its diff for every marker this programme introduced (0 matches).
- Its diff against HEAD is entirely the pre-existing **HRMS Phase 1 `locations`** rules — another
  workstream's uncommitted work.
- **mtime `2026-08-16 12:00:47`** — hours newer than the other protected files
  (`MY_Controller.php` 2026-08-15 15:02, `firestore.indexes.json` 2026-08-15 23:22), and inside this
  session's window.
- File is intact: UTF-8, LF, 0 CRLF lines — not a corruption or an encoding rewrite.

This is precisely the hazard `CLAUDE.md` documents: *"firestore.rules is edited concurrently by other
sessions… a deploy ships the whole file including anyone else's half-finished work."*

**Action taken: none.** I did not modify, revert or normalise it. New hash recorded for future
baselines: `b597cee2a782f4500469623c552aed470f2e17ee2a48a5417306a123d48a17f5`.

**Operator action recommended:** confirm the 12:00:47 edit was intentional before any rules deploy.

---

## 3. Phase 20.1b re-verification (Part 1)

Re-read from current code, not trusted from the report.

| Claim | Verified? | Evidence |
| --- | :-: | --- |
| `streamKey` never written to students | ✅ | Zero PHP occurrences outside `School_config.php`; the only other hits are view JS (`stream: streamKey` writing to **subjects**) and log lines |
| Stream membership via stream-named sections | ✅ | `_sectionBelongsToStream()` present; `normalizeClassSection():196-202` yields `"Section Science A"` / `"Science A"` |
| `subjects.stream` is real | ✅ | Written at `School_config.php:2388`; 30/30 documents carry it |
| `delete_stream()` is the only removal path | ✅ | `unset($allStreams[...])` occurs **once**, at `:3358`, inside `delete_stream()` |
| Helpers present and wired | ✅ | 8 occurrences of the three helper names |
| `delete_section` / `delete_stream` fail-closed intact | ✅ | Both inline `!empty($deps['errors'])` guards still present (asserted by test) |

**Phase 20.1b's model is what the code implements.** No correction required to its stream-membership
findings.

---

## 4. P20-F10 — investigated, and deliberately NOT implemented (Part 2)

### What the source says

| Question | Answer | Evidence |
| --- | --- | --- |
| Is `stream` required on a subject? | **No — explicitly OPTIONAL** | `Subject_master_verify::OPTIONAL_FIELDS = ['category','stream']`; REQUIRED is `classKey, subjectCode, name` |
| Does anything filter/query subjects by stream? | **No** | No `where`/filter on `subjects.stream` anywhere |
| Is it used in the subject UI? | **No — dead** | `populateStreamDropdown()` is `/* legacy compat, now a no-op */` with an empty body |
| Is it rendered anywhere? | **No** | In `views/academic/index.php` the word `stream` appears **once**, inside a shape comment (`{code,name,category,stream}`); never rendered |
| Does any other module depend on it? | **No** | Only readers are `Academic::_getSubjectCatalogFromFirestore()` (copies it into a shape) and the CLI verifier |
| Mobile? | **No** | Zero stream-domain references in either app (§8) |

### What the live data says

```
subjects total : 30
  stream="common" : 30          ← ALL of them
live stream keys defined : (none)
values matching a LIVE stream : NONE
sample: {classKey:"10", subjectCode:1001, name:"English", category:"Language", stream:"common"}
```

**Every subject carries the placeholder `"common"`.** Not one references a real stream, and the
tenant defines no streams at all. `"common"` is the hard-coded default written at
`School_config.php:2233` and `:2734`.

### Decision — option C: existing canonical behaviour, unchanged

Of the three options in the brief:

- **A (block deletion)** — would invent a policy the application does not have. It would also fire on
  the placeholder value: a school that ever named a stream `common` could never delete it. And with
  30/30 subjects carrying a stream value, blocking would make stream deletion *harder* while
  protecting nothing that any reader consumes.
- **B (detach/update subjects)** — writes data to fix a problem no reader experiences.
- **C (leave as-is)** — ✅ **chosen.** `subjects.stream` is a classification attribute with a default,
  not a foreign key. A stream key that no longer resolves has no consumer to break.

> **This corrects Phase 20.1b**, which registered P20-F10 as *"deleting a stream leaves subjects with
> a dangling stream reference"*. The dangling reference is real in the abstract and inert in practice.
> Implementing a block would have been exactly what the brief forbids: *a fix that makes the code look
> safer without protecting real data.*

The decision is **pinned by a test** (`test_F10_stream_deletion_does_NOT_block_on_subjects`) so a
future edit is deliberate rather than accidental.

---

## 5. P20-F9 — complete caller inventory (Part 3)

Exhaustive search: **`_dep_count_safe()` has exactly 5 callers**, all deletion endpoints, all in
`School_config.php`.

| # | Caller | Entity | Dependency checks | Bounded | On throw (before) | Classification | Rank |
| --: | --- | --- | ---: | :-: | --- | --- | --- |
| 1 | `delete_session():5053` | academic session | 9 | 200 | **counts as zero → deletes** | **FAIL-OPEN** | **CRITICAL** |
| 2 | `soft_delete_class():1313` | class | 5 | 200 | **counts as zero → deletes** | **FAIL-OPEN** | HIGH |
| 3 | `delete_subject():2635` | subject | 2 | 200 | **counts as zero → deletes** | FAIL-OPEN | MEDIUM |
| 4 | `delete_section():1739` | section | 5 | 200 | refuses (Phase 20.1) | SAFE | — |
| 5 | `delete_stream():3257` | stream | 1 | 200 | refuses (Phase 20.1b) | SAFE | — |

*(A sixth match at `:276` is the doc-comment example inside `_dep_count_safe()` itself, not a caller.)*

### The fail-open, proven from source

`_dep_count_safe()` (`:597-599`):

```php
} catch (\Throwable $e) {
    $out['errors'][$key] = $e->getMessage();
    log_message('error', "ACC_DEP_CHECK_FAILED …");
}
```

`has_any` is initialised `false` and set **only** inside the success branch; `by_key[$key]` is
likewise never assigned on the error path. So a thrown check contributes nothing, every caller's
`if (!empty($deps['has_any']))` evaluates false, and the delete proceeds. A transient Firestore
error was sufficient.

### Fix — per-caller guard, collector untouched

Added `School_config::_dep_fail_closed()` and called it at the three unsafe callers.

**`_dep_count_safe()` itself was NOT modified**, and the brief's caution is why:

- Changing it centrally would alter behaviour at five endpoints in one edit, including two already
  correct and covered by tests.
- The partial-result contract is deliberate: `errors[]` exists precisely so a caller *can*
  distinguish the states. **The defect was never that the collector hides failures — it reports them.
  It was that callers ignored the report.**

So the guard makes honouring that report a one-line call. Verified by test: 3 shared-guard calls,
1 guard definition, 2 surviving inline checks, 1 collector.

> **Residual (cosmetic):** the two Phase 20.1/20.1b paths still use inline checks rather than the
> shared helper. Consolidating them is a safe follow-up, deliberately **not** done here to avoid
> touching proven-working code. Registered as **P20-F15 (LOW)**.

---

## 6. `delete_session()` deep audit (Part 4)

**Highest-risk caller.** Removes a session from `schools.sessions[]` / `archivedSessions` under a CAS
precondition. It correctly refuses to delete the **active** session.

### Dependency matrix

| Collection | Field | Query | Bounded | Failure behaviour (before) | Field real? |
| --- | --- | --- | :-: | --- | --- |
| `students` | `session` | `== $session` | 200 | zero → proceed | ✅ 23/23 |
| `sections` | `session` | `== $session` | 200 | zero → proceed | ✅ 35/35 |
| `staff` | `session` | `== $session` | 200 | zero → proceed | ✅ 54/54 |
| `attendance` | `session` | `== $session` | 200 | zero → proceed | ⚠️ **0/2 — see P20-F13** |
| `marks` | `session` | `== $session` | 200 | zero → proceed | ✅ 1/1 |
| `feeDemands` | `session` | `== $session` | 200 | zero → proceed | ✅ 26/26 |
| `feeReceipts` | `session` | `== $session` | 200 | zero → proceed | ⚪ 0 docs — unproven |
| `timetables` | `session` | `== $session` | 200 | zero → proceed | ✅ 18/18 |
| `subjectAssignments` | `session` | `== $session` | 200 | zero → proceed | ✅ 17/17 |

### Can a failed query delete a session? — **Yes, and it is now proven and closed**

Before this phase: yes. Nine checks, any of which could throw, all collapsing to "no dependencies".

**Blast radius — stated precisely, not dramatised.** `delete_session()` does **not** cascade-delete
student, fee, attendance or marks data. It removes the session from the sessions list. The records
survive but become unreachable through the session picker — a governance, reporting and access
failure, recoverable only by re-adding the session by hand. **Not data destruction; data
disappearance.**

**Now:** `_dep_fail_closed()` runs before the `has_any` branch and returns **409**
`dependencies_undetermined` with `failed_checks`, logging `ACC_DEP_UNDETERMINED`.

### P20-F13 — NEW, FOUND — NOT FIXED

`attendance.session` was absent on **both** attendance documents on this tenant.

**I initially recorded this as "field never written" — that was wrong, and I corrected it before
reporting.** Source proves the opposite: `session` is in
`Attendance_canonical_verify::REQUIRED_CANONICAL_FIELDS`, and
`Firestore_service::saveAttendance():1331` stamps `'session' => $this->session`.

So the honest finding is: **the writer is correct; those 2 documents predate the canonical shape.**
`delete_session()`'s attendance check would not see them. This is the same *class* of defect as
P20-F8 but far narrower — a legacy-data gap, not an inert field.

Not fixed: it needs the tolerant-filter treatment applied to attendance, which is Attendance-module
work and explicitly out of scope. The probe's label was corrected to
`"absent on all sampled — CHECK WRITER"` so it cannot mislead a future run.

---

## 7. Dependency model matrix (Part 5)

Every field below has a verified **writer**, **reader**, and a **real observed document shape**.

| Entity deleted | Canonical id | Dependent collection | Dependency field | Writer | Legacy shapes | Session-scoped | Safe-delete condition | Failure-safe |
| --- | --- | --- | --- | --- | --- | :-: | --- | :-: |
| **Section** | `{school}_{sess}_{class}_{sec}` | `students` | `className`+`section` (**not** `sectionKey`) | Sis/import/transfer | `Class`/`Section`/`Status` | ✅ blank-tolerant | canonical roster = 0 **and** indexed = 0 | ✅ 20.1 |
| | | feeDemands, attendance, marks, timetables, subjectAssignments | `sectionKey` | module writers | — | ✅ | count = 0 | ✅ 20.1c |
| **Class** | `classKey` | per-section roster | as above | — | — | per section doc | every section empty | ✅ 20.1 + 20.1c |
| **Stream** | `schools.streams{}` key | `students` | **stream-named section** — no student field exists | section writers | none | ✅ blank-tolerant | membership = 0 | ✅ 20.1b |
| | | `sections` | stream-named section (`streamKey` **never written**) | — | — | ✅ | count = 0 | ✅ 20.1b |
| | | `subjectAssignments` | `stream` | Academic | — | ✅ | count = 0 | ✅ 20.1c |
| | | ~~`subjects`~~ | `stream` = `"common"` 30/30 | School_config:2388 | — | ❌ | **not a dependency** (§4) | n/a |
| **Subject** | `{classKey}_{code}` | `subjectAssignments` | subject code | Academic | — | ✅ | count = 0 | ✅ 20.1c |
| **Session** | `YYYY-YY` | 9 collections | `session` | module writers | ⚠️ attendance legacy | ✅ | all = 0 | ✅ 20.1c |

**Inert fields identified and never trusted again:** `students.streamKey`, `sections.streamKey`
(P20-F8) — both confirmed written by nobody.

---

## 8. Cross-platform audit (Part 6)

Both Kotlin repos read directly at `D:/Projects/SchoolSync{Parent,Teacher}`.

| Question | Answer |
| --- | --- |
| Does mobile read/write **stream**? | **No.** Every `stream` match is Kotlin Flow/reactive language (`InputStream`, `"PaymentHistory stream crashed"`, `notices stream`). **Zero academic-stream references.** |
| Does mobile read/write **subjects.stream**? | **No.** |
| Does mobile read the **`sections`** collection? | **No** (established Phase 20.1b, re-confirmed). |
| Does mobile depend on **`sectionKey`**? | **Yes** — the composite `"Class 9th/Section A"`, across 8–9 doc types in both apps. **Unchanged by this phase.** |
| Does deleting a stream/class/section break an app contract? | **No.** No format, field or document id was changed. |
| Hard-coded identifier format? | **Yes** — `sectionKey`, and it is preserved. |

> ### NO MOBILE CHANGE REQUIRED.
> Based on reading both repositories, not inference.

---

## 9. UI/UX audit (Part 8) — where the real finding was

| Handler | Endpoint | Verdict |
| --- | --- | --- |
| `deleteSession` `:2279` | `delete_session` | ✅ correct — toasts status, updates only on success |
| `deleteSubject` `:3533` | `delete_subject` | ✅ correct |
| `deleteSection` `:3215` | `delete_section` | ✅ correct |
| **`deleteStream`** | `delete_stream` | ❌ **P20-F12 — FIXED** |
| **`softDeleteClass`** | `soft_delete_class` | ❌ **P20-F12, 2nd instance — FIXED** |

### P20-F12 — phantom success (NEW, HIGH)

Both defective handlers did this:

```js
// Remove from local UI immediately
delete CFG.streams[key];            // ← mutate FIRST
renderStreams(CFG.streams);
// Also delete from server (best-effort — may not exist if not yet saved)
post('…/delete_stream', …, function(d) {
    if (d.status === 'success') { toast('Stream deleted.'); }
    // Don't show error if server says "not found"   ← swallow EVERYTHING else
});
```

**Consequence:** on a 409 — dependency block, or the fail-closed `dependencies_undetermined` path
built in Phases 20.1/20.1b — the row vanished from the screen and **no message was shown at all**.
The operator would reasonably believe the deletion succeeded.

The `soft_delete_class` instance is the more serious of the two: Phase 20.1 hardened that exact
endpoint to refuse while any section still holds active students. The UI hid the refusal.

**Fix (both handlers):**
1. **Server first** — local state mutates only on confirmed success.
2. Refusals leave the row exactly where it is.
3. **"Cannot delete" and "could not verify" are distinguished** for the operator, per the brief.
4. Genuine `not_found` still clears locally (preserves the original UI-only-stream intent) — matched
   by `reason` *or* message text, so **no server response shape was changed**.
5. **Duplicate-click protection** added (`_busy` map).

`post()` was verified to support this: a 409 with `Content-Type: application/json` flows through
`r.json()` to the callback with `reason` and `dependencies` intact (`:1296-1320`), and
`_json_error_with_data()` emits exactly that.

### Registered, not fixed

**P20-F16 (LOW)** — `deleteSession`'s confirm text says *"This only removes it from the sessions
list"*, which is accurate, but the other handlers' dialogs do not state consequences as clearly.
Phase 20.2 UI work.

---

## 10. SRNO audit (Part 7 — mandatory)

| Check | Result |
| --- | --- |
| SRNO used as class/section/stream foreign key | ❌ none |
| Roll No treated as permanent identity | ❌ none |
| Admission No substituted for SRNO | ❌ none |
| Student ID substituted for SRNO | ❌ none |
| Stream/class/section deletion modifies SRNO | ❌ **none** |
| Promotion/transfer/rollover modifies SRNO | ❌ none (re-confirmed) |
| Identity substitution on a print/document surface | ❌ none found this phase |

**Machine-verified:** all five delete endpoints scanned for `srNo|sr_no|admissionNo|rollNo|studentId`
— **0 references in every one**. The new `_dep_fail_closed()` helper is identity-blind and cannot
write (asserted by tests).

**No SRNO issue found.**

---

## 11. Performance (Part 10)

**`_dep_fail_closed()` performs no queries.** It inspects the `errors` array already produced by
`_dep_count_safe()`.

| Metric | Before | After |
| --- | --- | --- |
| Reads per delete | 9 (session) / 5 (class) / 2 (subject) | **identical** |
| Query shape | unchanged | unchanged |
| Index requirement | unchanged | **none added** |
| Result limit | 200/check | unchanged |
| Cache | n/a | n/a |
| Per-request or per-item | per request | unchanged |
| N+1 | none | **none introduced** |

**Zero read cost.** The UI fix removes no request and adds none — it reorders existing work.

> `firebase-rules/firestore.indexes.json` **NOT modified** — verified byte-identical.

---

## 12. Tests and exact baseline comparison (Part 9)

**New:** `tests/Unit/Phase201cDependencyFailClosedTest.php` — **14 tests, 48 assertions, all passing.**

Covers: guard fires on error / not on clean-empty · the fail-open reproduced as a precondition ·
all five endpoints fail-closed · collector unmodified · both UI handlers post **before** mutating ·
both surface failures and distinguish the two refusal kinds · duplicate-click protection ·
best-effort swallowing gone · P20-F10 decision pinned · SRNO on all five endpoints · guard is
identity-blind and read-only · response shape preserved.

The view-source tests strip **both PHP and JS comments** — without that, the assertions match the
very comments documenting the banned patterns (a mistake made earlier in this programme).

### Exact baseline comparison

| Run | Tests | Failures |
| --- | ---: | ---: |
| Phase 20 baseline | 1486 | **10** |
| After Phase 20.1 | 1486 → 1503 | **10** |
| After Phase 20.1b | 1512 | **10** |
| **After Phase 20.1c** | **1539** | **10** |

**Failures identical, failure-for-failure**: `BackupTest::size_human` ×1, `Homework*` ×4,
`ParentHomeworkRepoSilentCatch` ×3, `TeacherExecuteDeleteCascade` ×2.

**Zero new failures. I am NOT claiming all tests pass — 10 pre-existing failures remain**, exactly as
documented in `CLAUDE.md`.

**Other checks:** `php -l` clean on both modified PHP files · **RTDB scan clean** (no `firebaseio`,
`getReference`, or RTDB usage in either modified file) · static sweeps in §5 and §9.

---

## 13. Files modified / not modified

### Modified
| File | Change |
| --- | --- |
| `application/controllers/School_config.php` | `_dep_fail_closed()` added; wired at `soft_delete_class`, `delete_subject`, `delete_session` |
| `application/views/school_config/index.php` | `deleteStream` + `softDeleteClass` rewritten server-first (P20-F12) |

### New
`tests/Unit/Phase201cDependencyFailClosedTest.php` · `scripts/p20f9_f10_dependency_probe.js` ·
this report.

### Intentionally NOT modified
`_dep_count_safe()` (§5) · `delete_section()` / `delete_stream()` inline guards · `MY_Controller.php`
· `Firestore_service.php` · `rbac_helper.php` · `firestore.rules` (**changed by another session —
P20-F14**) · `firestore.indexes.json` · `Classes.php` and all legacy views · Fees / ID Card / HRMS /
Attendance / Examination / Library · `subjects` collection (P20-F10 decision).

---

## 14. Status ledger

**FIXED** — P20-F9 (3 callers) · P20-F12 (2 UI handlers)
**FOUND — NOT FIXED** — P20-F13 (`attendance.session` legacy gap) · P20-F15 (2 inline guards not
consolidated, LOW) · P20-F16 (confirm-dialog wording, LOW) · P20-F11 (unchanged, registered) ·
P20-F2 / F3 / F4 / F5 (Phase 20, unchanged)
**NOT A DEFECT** — P20-F10, with Phase 20.1b's registration corrected
**INSPECTED ONLY** — `deleteSession` / `deleteSubject` / `deleteSection` UI handlers (verified
correct) · `save_stream` / `save_streams` / `seed_streams`
**NOT TESTED AT RUNTIME** — all five delete endpoints; both rewritten UI handlers; every
`dependencies_undetermined` branch; `ACC_DEP_UNDETERMINED`
**REQUIRES UAT** — both rewritten UI handlers (browser)
**BLOCKED BY DECISION** — P20-F14 (operator must confirm the concurrent `firestore.rules` edit)

### Risk classification

| ID | Risk | Rank | State |
| --- | --- | --- | --- |
| P20-F9 / `delete_session` | session disappears from picker on transient query failure | CRITICAL | **FIXED** |
| P20-F12 | operator believes a refused deletion succeeded | HIGH | **FIXED** |
| P20-F9 / `soft_delete_class` | class removed despite enrolled students | HIGH | **FIXED** |
| P20-F14 | concurrent rules edit shipped unintentionally on next deploy | MEDIUM | **REPORTED** |
| P20-F13 | legacy attendance rows do not block session deletion | LOW | registered |

---

## 15. Final answers

1. **Is P20-F10 fixed?** — **No, and it should not be.** Source and live data prove
   `subjects.stream` is an optional classification attribute holding `"common"` on 30/30 documents,
   referencing no live stream, read by nothing. Blocking would invent policy and protect nothing.
   Phase 20.1b's registration is corrected. Decision pinned by test.
2. **Is P20-F9 fixed?** — **Yes**, at all three remaining callers, via one shared guard.
   `_dep_count_safe()` deliberately unmodified.
3. **If not fixed, why?** — n/a.
4. **Is `delete_session` safe against dependency-query failure?** — **Yes, now.** It was **not**:
   nine checks could each throw and collapse to "no dependencies". It now returns 409
   `dependencies_undetermined`.
5. **Are class/section/stream deletion paths fail-closed?** — **Yes — all five**, and now visibly so
   in the UI, which was the missing half.
6. **Remaining deletion data-loss risks?** — **None known in the admin deletion paths.** One narrow
   gap: **P20-F13**, legacy attendance rows without `session` do not block session deletion. That is
   a *visibility* risk, not destruction — `delete_session()` deletes no records.
7. **Is Sections & Seats ready for capacity UI work?** — **Yes.** The dependency-safety boundary is
   closed. Capacity work can proceed on a foundation that no longer silently deletes.
8. **Is legacy retirement still premature?** — **Yes, emphatically.** `Classes.php` still holds the
   only capacity editor and the only student-transfer path. Nothing was retired.
9. **What should Phase 20.2 contain?** — §16.
10. **Any SRNO / Roll No / Admission No / Student ID issue?** — **No. None found.** All five delete
    endpoints are identity-free; SRNO and Roll are untouched by every path audited.

---

## 16. Exact recommendation for Phase 20.2

1. **UAT first (small).** Exercise both rewritten handlers in a browser: refuse a class delete with
   enrolled students and confirm the class **stays visible** with a clear message. That validates
   three phases of fail-closed work at the only layer the operator sees. *(Streams cannot be tested
   here — this tenant defines none.)*
2. **Then capacity.** Per the Phase 20 design: `saveSection()` accepts optional `maxStrength`
   (closes P20-F3 at creation) → `get_all_sections` returns capacity + occupancy → capacity/occupancy
   table and edit drawer with the **P20-D5 warn-and-confirm** policy → port transfer under
   `SIS:manage` → soak → clean commit → retire legacy.
3. **Carry forward, do not fix in 20.2:** P20-F13, P20-F15, P20-F16, P20-F11.
4. **Resolve P20-F14 before any rules deploy.**

---

```
PHASE 20.1C COMPLETE — DEPENDENCY INTEGRITY + FAIL-CLOSED
NOT COMMITTED
NOT PUSHED
NOT DEPLOYED
UAT NOT EXECUTED
```

Phase 20.2 not started and will not be started without review.
