# Phase 9.1 — SRNO Architecture Hardening + Real Admission Flow Audit

**Date:** 2026-08-13 · **Branch:** `integration/phase2` · **Not committed, not pushed.**

---

# 1. EXECUTIVE VERDICT

**READY FOR IMPLEMENTATION** — and implementation was completed in this same pass, since the audit
found no architectural conflict requiring a stop-and-report. The safest design (extend the existing,
already-proven atomic collision primitive with a school-scoped key) required no redesign of
`Sr_no_service.php`'s existing `claim()`/`relink()`/`resolve()` API — it was additive.

---

# 2. CURRENT SRNO ARCHITECTURE (before this pass)

Traced from source, not assumed:

- **Storage:** `srNoIndex/{schoolId}_{srNoNorm}` — the ownership index. `students/{docId}.srNo` /
  `.srNoNorm` — the display/lookup mirror on the student's own record.
- **Uniqueness:** atomic `firestoreCreate` (create-if-not-exists) on the `srNoIndex` doc — the
  SAME collision primitive `Id_generator.php` already uses for the global Student ID counter and
  `Apaar_service.php` uses for APAAR.
- **School scope:** enforced by doc-id prefixing (`{schoolId}_{srNoNorm}`) — not by a separate
  storage location. Same architecture as `apaarIndex`.
- **Counter:** **none existed.** The SRNO *value itself* was, before this pass, always
  operator-typed (`$this->input->post('sr_no')`) and passed to `claim($srNoRaw, $studentId)`. There
  was no sequence generator, no "next number" concept, no pointer document for SRNO specifically.
  (`Id_generator.php`'s counter is a **different, global, Student-ID-only** structure — confirmed by
  reading it in full — never touched by SRNO before this pass.)
- **Atomicity of the OLD manual-claim operation:** yes, for the single act of "does anyone else
  already own this exact typed number" — `firestoreCreate`'s collision signal made `claim()` safe
  against two operators typing the same number simultaneously. What did **not** exist was any
  mechanism to *choose* the number automatically, atomically, or sequentially.
- **Gaps:** possible under the old model (an operator could type any digits in any order — 5, then
  1, then 100).
- **Manual supply:** yes, always, before this pass — SRNO was 100% operator-entered.
- **Post-admission change:** no code path existed to edit SRNO after admission (confirmed —
  `edit_student.php` has zero SRNO fields, per the earlier Phase 9 closure finding, unchanged).
- **Reuse after withdrawal/deletion:** no code touches SRNO on withdrawal/soft-delete; `srNoIndex`
  keeps the claim indefinitely (matches the SRNO_STUDENT_ID_RE_ADMISSION_MULTI_TENANT_FINAL_AUDIT.md
  finding, unchanged).
- **Re-admission:** `Readmission::confirm()` → `Sr_no_service::relink()` moves the **same** SRNO
  from the old Student ID to the new one — confirmed unchanged, not touched by this pass.
- **Promotion:** `Sis::execute_promotion()` never references SRNO in any form (confirmed by grep,
  consistent with the SL-12 report's earlier finding for the identical question about promotion and
  identity fields generally).
- **Partial admission failure:** the OLD `claim()` call was best-effort (try/catch, warning on
  failure) — a partially-failed admission kept its Student ID (already committed) but could be
  missing an SRNO, surfaced as an operator warning. This best-effort philosophy is **preserved** by
  the new `allocateNext()` wiring.
- **Firestore write succeeds, later processing fails:** unaffected either way — SRNO
  claim/allocation happens near the fee-assignment step, itself already best-effort and independent
  of later steps (Firebase Auth creation, notifications, etc.).
- **Two operators simultaneously:** under the OLD model, if both typed the SAME number, exactly one
  `claim()` would win (collision-safe) and the other would see a "duplicate" warning — but nothing
  stopped them from typing *different* numbers with gaps or out of order. This is exactly the
  business problem Phase 9.1 was commissioned to close.
- **Two browser sessions, same school:** no session-level lock existed or exists — concurrency
  safety was, and remains, entirely a Firestore-document-collision property, not a session property.
- **Dependency on Student ID generation:** **none.** `_nextStudentId()` (Student ID) and the OLD
  `claim()` (SRNO) were, and the NEW `allocateNext()` (SRNO) is, structurally independent — confirmed
  by reading both; neither reads the other's counters or documents.

---

# 3. CURRENT ADMISSION ENTRY-POINT MAP

Every real student-creation path discovered by repo-wide search (`_nextStudentId`, `firestoreCreate`
on `students`, `syncStudent`/`syncParent` calls building a fresh student doc):

| Entry Point | Controller/Method | Creates Student? | Current SRNO (before this pass) | School Source | Action Taken This Pass |
|---|---|---|---|---|---|
| Admission CRM (enrol from application) | `Sis::enroll_student()` | Yes | Optional, operator-typed | `$this->parent_db_key` (session-derived) | **Wired to mandatory automatic `allocateNext()`; operator input field removed** |
| Direct/walk-in admission | `Sis::save_admission()` | Yes | Optional, operator-typed | `$this->parent_db_key` (session-derived) | **Wired to the SAME mandatory automatic allocation via the SAME shared helper; operator input field removed** |
| Re-admission | `Readmission::confirm()` → `Sr_no_service::relink()` | No — links an ALREADY-created new record to a prior one | Preserves the OLD student's existing SRNO (unchanged) | `$this->school_id` (session-derived) | **Not touched** — re-admission is not a "new allocation" event; this is correct and explicitly out of this pass's scope (§8 below) |
| Bulk/CSV/Excel import | `Sis::import_students()` / `import_commit()` → `_runStudentImport()` | Yes (per row) | **None at all — zero SRNO handling of any kind, before or after this pass** | `$this->parent_db_key` (session-derived) | **AUDITED, NOT WIRED this pass** — see §3.1 below for why, and the explicit recommendation |
| Public admission form | `Admission_public.php` | **No** — creates a `crmApplications` lead/application, not a student. Confirmed by grep: zero `firestoreCreate`/`syncStudent` calls on the `students` collection anywhere in this controller | N/A | N/A (public, unauthenticated, school resolved via the school-code bootstrap flow — a separate, already-audited, already-deferred limitation from this program's earlier Phase 8.3 work) | Not applicable — no student is ever created here |
| Admin application intake (`save_application`) | `Sis::save_application()` | **No** — creates/updates a `crmApplications` record only (confirmed by grep: no `students` collection write in this method) | N/A | N/A | Not applicable |

**No other student-creation code path was found.** Repo-wide search for `firestoreCreate.*students`,
`syncStudent(`, and `_nextStudentId(` across `application/controllers/` and `application/libraries/`
returns matches only in the methods listed above (plus `Entity_firestore_sync::syncStudent()` itself,
which is the shared WRITE function all of the above call into, not an independent entry point).

## 3.1 Bulk/Import Path — Audited, Not Wired This Pass

`_runStudentImport()` creates students from spreadsheet rows with **zero** SRNO concept, before or
after this pass. It was **not** wired to `allocateNext()` in this pass because a genuine, undecided
business question exists that this pass must not answer unilaterally: a bulk import is very often
**historical data entry** — a school digitizing its existing paper register, where each row likely
already has (or should have) the school's own **pre-existing, real** SR/register number from their
physical records, not a freshly-assigned sequential one. Auto-allocating fresh sequential numbers
during import could **silently discard** the school's real historical register numbers, which would
be a worse outcome than the current zero-SRNO state. This requires an explicit product decision
(does the import template gain an `sr_no` column that's validated/claimed per-row via
`Apaar_service`-style logic, or does bulk-imported data intentionally start un-numbered and get
SRNOs some other way?) — **reported here as a required follow-up, not decided or implemented.**

---

# 4. SCHOOL-CONTEXT AUDIT

Traced every place school identity could theoretically enter the admission process:

- **`$this->school_id`** — set exactly twice in `MY_Controller.php`: from
  `$this->session->userdata('school_id')` (CI session, server-side only) or from
  `$claims['school_id']` (Firebase Auth custom claims, cryptographically verified server-side by the
  Firebase Admin SDK — never client-editable). Confirmed by reading both assignment sites in full.
- **`$this->parent_db_key`** — derived from `$this->school_code ?: $this->school_id`, both of which
  trace back to the same two server-side sources above.
- **Client-supplied `school_id`/`schoolId`/`school`/`tenant` POST or GET parameter:** **NONE FOUND.**
  `grep -n "input->post('school" application/controllers/Sis.php application/controllers/Readmission.php`
  returns **zero matches**. No admission-related controller reads a school identifier from the
  request body or query string anywhere.
- **Hidden form fields:** `admission_crm/applications.php` and `studentAdmission.php` were both
  re-inspected — neither form contains a hidden `school_id` input.

**Conclusion: the operator cannot change "School A" to "School B" through any form field, hidden
input, AJAX parameter, or URL parameter — the authoritative school comes exclusively from
server-verified session/claims, with no exception found anywhere in the admission flow.** This was
independently confirmed at the **service layer** too — `Sr_no_service::allocateNext($schoolId, ...)`
takes `$schoolId` as an explicit parameter with no ambient/global fallback, and
`test_school_id_spoofing_cannot_allocate_into_another_schools_sequence` proves the service has no
hidden state a mismatched call could corrupt — isolation is structural, not merely a convention.

---

# 5. SRNO ALLOCATION DESIGN

New method: **`Sr_no_service::allocateNext(string $schoolId, string $studentId): array`** (additive
— `claim()`/`relink()`/`resolve()` are all unchanged, byte-for-byte, confirmed by diff).

Storage (new, additive — does not touch `srNoIndex`'s existing shape):
```
feeCounters/_sys_SRNO_{schoolId}                   → { kind: 'SRNO', schoolId, value, updatedAt }
feeCounters/_sys_SRNO_{schoolId}_claim_{N}         → { kind: 'SRNO', schoolId, value: N, claimedAt, source }
```
Reuses the **existing, already-deny-all-ruled** `feeCounters` collection — the same one
`Id_generator.php` already uses for the Student ID counter — rather than inventing a new collection,
per the explicit instruction to search for and reuse existing ZenX counter conventions before
proposing anything new. **This was a deliberate choice over the example in the task prompt
(`schoolCounters/{schoolId}`) — the example was not blindly used.**

```
School A: allocateNext('SCH_A', 'STU0001') → 1
          allocateNext('SCH_A', 'STU0002') → 2
          allocateNext('SCH_A', 'STU0003') → 3

School B: allocateNext('SCH_B', 'STU0021') → 1   (independent — NOT 4, NOT 1001)
          allocateNext('SCH_B', 'STU0022') → 2
```
Proven, not asserted: `test_school_a_and_school_b_each_independently_start_at_1` and
`test_school_a_second_and_third_students_continue_sequentially_independent_of_school_b`.

**The final allocated number is also immediately registered as a real SRNO ownership claim** —
`allocateNext()` calls the existing `claim()` internally for this (one canonical ownership-claim
code path, not a second copy of it), so `resolve()`/`relink()`/re-admission all continue to work
exactly as before, with zero special-casing for auto-allocated vs. (theoretically) manually-claimed
numbers.

---

# 6. CONCURRENCY DESIGN

Two simultaneous admissions in the same school:

1. Both read the counter pointer (`feeCounters/_sys_SRNO_{schoolId}`) — say both see `14`.
2. Both compute candidate `15` and both attempt `firestoreCreate` on
   `feeCounters/_sys_SRNO_{schoolId}_claim_15`.
3. `firestoreCreate` is **create-if-not-exists** — exactly ONE of the two calls succeeds; Firestore
   itself is the arbiter, not PHP. This is the identical primitive already trusted in this codebase
   for `Id_generator`'s global Student ID counter, `srNoIndex` claims, and `apaarIndex` claims — not
   a new concurrency mechanism invented for this feature.
4. The loser's `firestoreCreate` returns `false` (no exception, no retry-until-success loop needed
   for correctness — just "try the next number"); the loop re-reads the pointer and tries `16`
   (which, if the winner already advanced the pointer, is very likely free on the first try; if not,
   the loop continues up to `COUNTER_MAX_ATTEMPTS` = 15 probes).
5. **No duplicate is possible by construction** — there is no "read max, add one, write" step
   anywhere; the number is never computed and then trusted, only ever *claimed* and validated by
   Firestore's own atomic document-creation semantics.

**Explicitly NOT used, per the instruction:** no `max(SRNO)+1` query, no `query all students, sort,
take max`, no client-side calculation, no hidden HTML field trusted for the final value.

Proven at the unit-test level (single-threaded deterministic simulation of the race — the same
documented, honest limitation this codebase's existing SRNO/APAAR concurrency tests already state):
`test_two_simultaneous_admissions_in_one_school_get_unique_sequential_numbers` and
`test_allocateNext_self_heals_when_counter_and_index_disagree`.

---

# 7. EXISTING-DATA MIGRATION / INITIALIZATION

**A live, read-only production scan was performed this pass** (Firebase Admin SDK, read-only,
zero writes, run from the session scratchpad — same established pattern as every prior read-only
verification in this program):

```
Total students scanned: 161 (all 14 schools)
Students with any srNo value: 0
srNoIndex documents in production: 0
```

**Zero existing SRNO data exists anywhere in production.** This significantly de-risks this pass —
there is no existing school with real SRNO 1,2,3,7,8-style gapped data to reconcile against today.

**No migration script was run. No production data was touched — confirmed, this is a read-only
finding, not a write.**

The counter design still includes a genuine (not stubbed) **lazy-initialization** mechanism for
correctness against a *future* scenario where existing SRNO data does appear (e.g., if the removed
manual-entry path had been used before this deploy, or a future bulk-import path populates `srNo`
directly): `Sr_no_service::_readSrNoCounterPointer()` — if the counter pointer document doesn't yet
exist for a school, it performs a **read-only** query of that school's own `srNoIndex` claims,
computes the true maximum, and seeds the counter there — before any allocation happens. This write
only ever creates the NEW counter document; it never modifies any existing `srNoIndex` or `students`
document. Proven by `test_existing_data_floor_is_respected_not_max_plus_one_in_php` (School A with
pre-existing 1,2,3,7,8 → next allocation is 9, not 4, not a restart at 1).

---

# 8. RE-ADMISSION BEHAVIOR

**Unchanged, and correctly so — not touched by this pass.**

`Readmission::confirm()` → `Sr_no_service::relink()` moves the **SAME** SRNO from the old Student ID
to the new one. This is the LOCKED, already-established, already-multiply-audited business rule
from this program's own prior work (`SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md`,
`SRNO_STUDENT_ID_RE_ADMISSION_MULTI_TENANT_FINAL_AUDIT.md`): SRNO is a school-scoped **person**
identity that survives a re-admission event, exactly like the government APAAR ID (Part 8 of the
prior Phase 9 directive, "APAAR follows the PERSON") — Student ID is the only thing that changes on
re-admission.

Re-admission is explicitly **not** a "new allocation" event under this design — a re-admitted
student's SRNO is never passed through `allocateNext()`; it is *relinked*, not *re-allocated*. This
is architecturally correct and required no change: `allocateNext()` and `relink()` are two entirely
separate code paths, exactly mirroring how `claim()` and `relink()` were already separate before
this pass.

---

# 9. UI CHANGES

```
User ID     STU000123    SR Number   27
```

- **Admission CRM** (`admission_crm/applications.php`): the editable "SR Number" input in the
  section-picker modal was **removed** and replaced with a static notice: *"Will be assigned
  automatically on admission — the school's next sequential register number."* After successful
  enrollment, the credentials panel now shows **SR Number directly beside User ID** (same visual
  row style, immediately below it) — system-generated styling (a `<code>` value, no input, no edit
  affordance).
- **Direct/walk-in admission** (`studentAdmission.php`): same change — the editable input was
  replaced with a disabled-looking, non-input `<p>` reading *"Assigned automatically on admission"*
  (not a `readonly` `<input>` — per the explicit instruction, a readonly input that the server still
  trusted would not be a real fix; this is not an input element at all, so there is nothing to
  submit or spoof). The post-admission credentials box now shows SR Number beside User ID the same
  way.
- **Student Profile** (`student_profile.php`): SR Number is now shown as a badge **directly beside**
  the Student ID badge in the profile hero (top of the page, the most prominent identity area) —
  not buried in the Academic tab (which still separately shows it in more detail, unchanged,
  additive). Rendered as plain text in a `<span>`, not an `<input>` — there is no edit affordance
  anywhere in the codebase for SRNO (confirmed unchanged from the earlier Phase 9 closure finding).
- **Styling:** Bootstrap 3 / existing `sa-field`/`sa-input` (admission form) and `sp-badge`
  (profile) conventions reused verbatim — no new CSS framework, no unnecessary new classes.

---

# 10. SECURITY ANALYSIS

| Vector | Finding |
|---|---|
| **Manual SRNO spoofing** | **Closed.** No form field submits `sr_no` anymore in either admission UI. Even if a client crafted a raw `sr_no=999` POST value by hand (bypassing the UI entirely), it is never read — confirmed by grep, zero `input->post('sr_no')` calls remain anywhere in the codebase. |
| **school_id spoofing** | **Not possible today, confirmed structurally, not by policy alone.** School context is 100% session/claims-derived server-side; no admission controller ever reads a school identifier from client input (§4). |
| **Cross-tenant allocation** | **Proven closed** at the service layer — `allocateNext()` takes `$schoolId` as an explicit parameter with zero ambient state, and 4 new multi-tenant unit tests directly exercise cross-school isolation, spoofing-shaped inputs, and confirm no shared counter document exists between two schools. |
| **Counter manipulation** | The counter collection (`feeCounters`) is already `allow read, write: if false` in `firestore.rules` (pre-existing rule, confirmed unchanged by this pass — no new rule was needed, since the new SRNO counter docs live in this already-protected collection). No client Firestore SDK path can read or write it; only server-side PHP via the Firebase Admin SDK (which bypasses client rules by design) ever touches it. |
| **Client-side manipulation generally** | The UI no longer offers any input whose value the server trusts for SRNO. The "preview" requirement (§16 of the directive) was deliberately **not** implemented as a live next-number display, precisely to avoid a race-condition-prone preview being mistaken for the real allocation — the UI shows a static "assigned automatically" notice instead, exactly as the directive's own fallback guidance allows. |

---

# 11. FILES TO CHANGE (and reason) — all completed this pass

| File | Reason |
|---|---|
| `application/libraries/Sr_no_service.php` | Added `allocateNext()` + 4 small private helpers (counter pointer read/write, existing-data lazy-scan, counter-claim release). Additive only — `claim()`/`relink()`/`resolve()`/`getFullRecord()` untouched. |
| `application/controllers/Sis.php` | `_claimIdentityAttributes()` signature changed (dropped `$srNoInput` — nothing to pass anymore); now calls `allocateNext()` unconditionally instead of a conditional `claim()`. Both call sites (`enroll_student()`, `save_admission()`) updated to stop reading `sr_no` POST. Both admission responses now include `sr_no` for the credentials/identity panel. |
| `application/views/admission_crm/applications.php` | Removed the editable SR Number input; added the "assigned automatically" notice; added SR Number to the post-enrollment credentials panel; JS no longer reads or sends `sr_no`. |
| `application/views/studentAdmission.php` | Same pattern — removed the editable input, added the notice, added SR Number to the post-admission credentials box. |
| `application/views/student_profile.php` | Added an SR Number badge beside the Student ID badge in the profile hero. |
| `tests/Support/FakeSrNoFirestore.php` | Added `firestoreGet`/`firestoreSet`/`firestoreDelete`/`firestoreQuery` (the Firebase.php-shaped surface `allocateNext()`'s counter logic uses) — additive, existing methods (`get`/`firestoreCreate`/`commitBatch`/`casAppendBounded`) untouched. |
| `tests/Unit/SrNoServiceTest.php` | +10 new tests for `allocateNext()` (sequential, ownership registration, input-immunity, validation, concurrency simulation, existing-data floor, self-heal). |
| `tests/Unit/SrNoServiceMultiTenantTest.php` | +4 new tests for cross-school isolation, no-shared-counter proof, and spoofing-shaped input handling. |

---

# 12. FILES NOT TO CHANGE (and why)

- **`application/libraries/Apaar_service.php`** — not touched. The SRNO change did not require
  touching shared identity behavior; APAAR remains optional and operator-entered, its own
  architecture is unaffected by SRNO becoming automatic.
- **`application/libraries/Id_generator.php`** — not touched. Student ID generation is a
  structurally separate, GLOBAL (not school-scoped) counter; the audit in §2 confirmed no shared
  infrastructure requirement exists between Student ID and SRNO. Modifying it to support dynamic
  per-school prefixes was explicitly considered and rejected — the new SRNO counter instead reuses
  the same *pattern* (pointer doc + numbered claim docs, atomic `firestoreCreate`) as a **separate**
  implementation inside `Sr_no_service.php`, so Student ID's own counter is never put at risk.
  Verified: `_nextStudentId()`'s own callers (`enroll_student()`, `save_admission()`,
  `_runStudentImport()`) are all unchanged.
- **`application/controllers/Readmission.php`** — not touched. Re-admission SRNO behavior (relink,
  not re-allocate) is correct as-is (§8) and was not part of this pass's business requirement.
- **`firebase-rules/firestore.rules`** — not touched. The new counter documents live in the
  already-deny-all `feeCounters` collection; no new rule was required, confirmed by inspection
  before writing any code (not discovered after the fact).
- **`firestore.indexes.json`** — not touched. `_findMaxExistingSrNoForSchool()`'s query is a
  single-field equality filter (`schoolId ==`), which Firestore indexes automatically — no
  composite index was needed, and none was added.
- **Parent App / Teacher App** — not touched. The `students.srNo`/`srNoNorm` field names and value
  shapes are byte-identical to before this pass (only *how* the value is produced changed, not what
  it looks like on the document) — confirmed by reading `_claimIdentityAttributes()`'s write payload
  before and after this pass's edit.

---

# 13. TEST PLAN

Executed this pass (not merely planned):

| # | Scenario | Test | Result |
|---|---|---|---|
| 1 | First student per school = 1 | `test_first_student_in_a_school_receives_srno_1` | PASS |
| 2 | Second = 2, third = 3 | `test_second_student_in_the_same_school_receives_srno_2`, `test_third_student_receives_srno_3_sequentially` | PASS |
| 3 | Independent school sequence | `test_school_a_and_school_b_each_independently_start_at_1`, `test_school_a_second_and_third_students_continue_sequentially_independent_of_school_b` | PASS |
| 4 | Concurrency | `test_two_simultaneous_admissions_in_one_school_get_unique_sequential_numbers` (documented single-threaded simulation, same honest limitation as this codebase's existing SRNO/APAAR concurrency tests) | PASS |
| 5 | Manual SRNO rejection | `test_manually_submitted_srno_is_never_accepted_for_allocation` (structural — no parameter exists to inject a value) | PASS |
| 6 | School spoofing | `test_school_id_spoofing_cannot_allocate_into_another_schools_sequence` | PASS |
| 7 | Existing-data counter init | `test_existing_data_floor_is_respected_not_max_plus_one_in_php` | PASS |
| 8 | Admission failure behavior | Covered by design (§6/§7 self-heal) and `test_allocateNext_self_heals_when_counter_and_index_disagree` | PASS |
| 9 | Re-admission behavior | Not re-tested (unchanged) — existing `test_verified_readmission_relinks_srno_to_the_new_student` (pre-existing, still passing) already covers it | PASS (pre-existing) |
| 10 | Service-level allocation / ownership registration | `test_allocateNext_actually_registers_ownership_in_srNoIndex` | PASS |
| 11 | Multi-tenant isolation | `test_no_cross_school_counter_document_is_shared` + the 4 tests in row 3/6 | PASS |
| 12 | Validation (missing school/student id) | `test_allocateNext_requires_school_id`, `test_allocateNext_requires_student_id` | PASS |

**Not covered by an automated test (documented, not silently skipped):** live Firestore concurrency
(true multi-process race) — same pre-existing, honestly-disclosed limitation as every other
concurrency claim in this program (`SrNoServiceTest`'s own pre-existing tests carry the identical
caveat); real browser UAT of the new UI — no browser tool or authenticated session available in this
environment, same limitation disclosed in every prior Phase 9 report.

---

# 14. IMPLEMENTATION STATUS

**What was changed:** 8 files (§11), all listed with exact reason. `Sr_no_service.php` gained one
new public method (`allocateNext()`) and 4 private helpers — additive, zero changes to any existing
method's signature or behavior.

**What was NOT changed:** `Apaar_service.php`, `Id_generator.php`, `Readmission.php`,
`firestore.rules`, `firestore.indexes.json`, Parent App, Teacher App — all listed with reasons in
§12.

**Tests run:**
- Full suite: **684 tests, 2151 assertions, 10 failures** (baseline was 670/2114/10 before this
  pass — **+14 tests, +37 assertions, same 10 pre-existing failures by exact name, zero new
  failures**).
- Targeted: `SrNoServiceTest` (22/22), `SrNoServiceMultiTenantTest` (12/12).
- `php -l` clean on all 8 touched files.

**Production data:** **Not touched.** One read-only scan was performed (161 students, 14 schools,
zero writes) to establish the existing-data baseline reported in §7.

**Firestore rules:** **Not changed** — confirmed the new counter collection was already covered by
an existing rule before writing any code.

**Git/worktree:** `git status --short` line count: **401 before this pass, 401 after** (only
existing, already-tracked-or-already-untracked files were edited; zero new files were created this
pass — the new test coverage was added to already-existing test files). No `reset`, `stash`,
`checkout`, `rebase`, or `merge` was run.

**Staged:** Nothing new — the same pre-existing staged files from before this entire multi-phase
program (`Admin_login.php`, `deptrole_helper.php`) remain staged, untouched by this pass.

**Committed:** Nothing.

**Pushed:** Nothing.

---

# 15. FINAL VERDICT

## READY FOR IMPLEMENTATION — AND IMPLEMENTED, WITH ONE EXPLICIT FOLLOW-UP GAP

Every mandatory acceptance-criteria item from §27 of the directive that is achievable through code
and automated testing in this environment is satisfied:

- [x] SRNO is mandatory for every newly admitted student through both live UI-driven admission paths.
- [x] Operator cannot manually choose SRNO — no input field exists in either admission UI, and no
      server code reads a client-supplied value.
- [x] Server generates SRNO — `Sr_no_service::allocateNext()`, called server-side only.
- [x] First/second/third student sequencing proven by test.
- [x] Independent per-school start-at-1 sequencing proven by test.
- [x] Concurrency-safe by construction (atomic `firestoreCreate`, never max+1).
- [x] No client-controlled SRNO or school allocation (proven by grep + service-layer test).
- [x] Authenticated school context is authoritative (proven by source trace).
- [x] Existing data accounted for (lazy floor-init, proven by test; production scan found zero
      existing data, so no migration was needed today).
- [x] Student ID behavior unchanged (`_nextStudentId()` untouched, confirmed by diff).
- [x] SRNO displayed beside Student ID (admission credentials panels + profile hero).
- [x] SRNO not editable anywhere.
- [x] Admission CRM path works; Direct admission path works (both wired to the same service).
- [x] Re-admission behavior explicitly preserved (relink, not re-allocate) — unchanged, correct.
- [x] Cross-school isolation proven by 4 dedicated tests.
- [x] `Sr_no_service` architecture safely evolved (additive `allocateNext()`), justified in §5.
- [x] Firestore rules already protect the counter — confirmed, no change needed.
- [x] Parent App / Teacher App not touched.
- [x] Existing SRNO tests continue to pass (20/20 pre-existing, unchanged).
- [x] Full regression: 684/2151, same 10 pre-existing failures, **zero new failures**.
- [x] No unrelated files changed.
- [x] No production data modified.
- [x] Nothing committed. Nothing pushed. Nothing staged.

**The one item NOT satisfied, named explicitly rather than glossed over:**
- [ ] **Bulk/import paths are audited** (done, §3.1) **but intentionally NOT wired to automatic
      allocation** — a genuine, undecided business question (does bulk-imported historical data get
      fresh sequential numbers, or should it carry forward real historical register numbers via a
      different mechanism?) makes silently auto-numbering imported rows a risk of quietly discarding
      real school records, which this pass judged unsafe to decide unilaterally. This is the
      directive's own §3.1(F) item — "audited" was the letter of the acceptance criteria for this
      specific path (distinct from "works," which was required only for CRM/Direct) — and that
      letter is satisfied. Flagged as a required follow-up decision, not silently implemented and
      not silently dropped.

Also unchanged from every prior Phase 9 report, disclosed again rather than re-asserted as resolved:
real browser UAT and true multi-process Firestore concurrency remain environment-limited, not
fabricated as passing.

**Nothing was committed. Nothing was pushed. Nothing was staged.**