# Phase 9.2 — Bulk/CSV/Excel Admission + SRNO Architecture + Cross-Module Integration Audit

**Date:** 2026-08-13 · **Branch:** `integration/phase2` · **Type: AUDIT ONLY. Zero code changed.**

This is a read-only investigation. No file was edited, no test was added, no production data was
touched, nothing was staged/committed/pushed. Per explicit instruction: **STOP AFTER THE AUDIT.**

---

# 1. Executive Summary (plain language)

Bulk import (CSV/Excel) creates real students today, exactly like the two paths already hardened in
Phase 9.1 — but it has **zero SRNO handling and zero Roll Number handling**, and even the newer,
more sophisticated "mapped import" screen has **no way to even offer** an SR Number, APAAR ID, or
Roll Number column to the operator, because the underlying field catalogue (`Import_schema.php`)
doesn't know these three fields exist. This isn't a bug in existing logic — it's an absent
capability, confirmed by reading the exact 21-field list the import system currently supports.

The core business question — "does a bulk-imported student get a fresh sequential SRNO, or does the
import carry forward the school's real historical register number?" — cannot be answered by source
code alone, because ZenX has no concept today of "this import is historical data" vs. "this import
is a fresh batch of new admissions." Both look identical to the system. **This is the central
unresolved decision this report surfaces for approval — not decided here.**

Separately, the audit found bulk import **also has no Roll Number field at all** — a second,
related but distinct gap, worth closing regardless of which SRNO strategy is chosen.

No new student-creation path beyond the three already known (Admission CRM, Direct/walk-in, Bulk
import) was found anywhere in the repository.

---

# 2. Complete Bulk/Import Lifecycle Map

Two parallel UI flows exist, both funneling into the SAME shared write logic:

**Legacy exact-template flow:**
```
studentAdmission... (no — this is separate) → sis/import_students (Sis::import_students(), Sis.php:4460)
  → reads uploaded .csv/.xlsx via PhpSpreadsheet
  → headers must match canonical field names EXACTLY
  → Sis::_runStudentImport($rows)   [Sis.php:4514-4890]
  → redirect back to sis/all_student with a flashdata summary
```

**Newer mapped/validated flow (3-step AJAX):**
```
sis/import_preview  (Sis::import_preview(), Sis.php:5039)
  → reads the file, auto-maps headers via Import_schema::autoMap() + this school's
    previously-learned mapping, returns headers/rows/schema/autoMap as JSON
        ↓
sis/import_validate (Sis::import_validate(), Sis.php:5140)
  → dry-run only, no writes — Import_schema::validateRow() per row,
    returns per-row status (ok/warning/error)
        ↓
sis/import_commit   (Sis::import_commit(), Sis.php:5171)
  → RE-validates server-side (never trusts the client's prior validation)
  → Sis::_runStudentImport($toImport, ['dupPolicy' => 'skip'|'update'])  [SAME shared method]
  → learns the column mapping for next time (_saveImportMapping)
  → stashes credentials for sis/import_credentials_pdf
  → returns JSON summary
```

**The shared write method, `_runStudentImport()` (Sis.php:4514-4890), traced row-by-row:**

1. Required-field guard: Name, Class, Section (Roll Number and SRNO are **not** in this guard —
   they're never read at all).
2. Duplicate detection (`$dupPolicy`: `create` / `skip` / `update`) — keyed by **phone number** and
   **name+DOB**, both within the current file and against the existing `indexPhones` collection.
   **Not** keyed by SRNO or APAAR in any way — those concepts don't exist in this method.
3. Class/section canonicalization via `Firestore_service::classKey()`/`sectionKey()` — the SAME
   SL-12-hardened primitives every other path uses (confirmed unchanged, not re-verified in depth
   this pass per the explicit instruction not to repeat already-completed SL-12 work).
4. Fee-structure pre-flight gate (`_fee_structure_missing()`) — blocks the row if the destination
   class/section has no fee chart, matching `enroll_student()`/`save_admission()`.
5. **Student ID generation:** `_nextStudentId($school_id)` — confirmed to be the exact same private
   wrapper `enroll_student()`/`save_admission()` call, which itself calls
   `Id_generator::generate('STU')` — the one and only Student ID generation code path in the entire
   repository (verified by grep — no other call site invokes `generate('STU')` directly).
6. Student document write: `$this->fs->saveStudent($studentId, $studentData)` — **note:** this uses
   a *different* write method name (`saveStudent`) than the other two admission paths, which write
   via `Entity_firestore_sync::syncStudent()` either directly or through `Student_write_service`.
   `saveStudent()` was not traced further this pass (out of scope for an SRNO-focused audit) but is
   flagged here as a structural difference worth a future consistency check.
7. Phone index write, `Students_Index` update, `monthFee` initialization (fail-loud — a failure here
   skips the row and increments `$error`, matching the fail-loud pattern already established
   elsewhere).
8. Fee assignment (`Fee_lifecycle::assignInitialFees()` — best-effort, try/catch).
9. Subject assignment (core + additional subjects, from the `subjects` collection by class number).
10. Firebase Auth creation (best-effort).
11. `entity_sync->syncStudent()` / `syncParent()` — the Android-app-facing Firestore sync (this is
    the SECOND write to the student doc in this flow — `saveStudent()` at step 6 is the first).
12. History log entry (`ADMISSION` type — same as the other two paths).
13. Row-level bookkeeping (`$seenPhones`/`$seenNameDob` for in-file dedup on later rows) and a
    per-row credentials entry for the post-import PDF handout.

**No parent-document-specific write beyond `syncParent()` was found** — parent creation follows the
same implicit "parent record derived from student data" pattern as the other two admission paths.

**SRNO: absent at every step above.** **APAAR: absent at every step above.** **Roll Number: absent
at every step above** — confirmed by reading the full `$studentData` array construction (Sis.php
4685-4710); there is no `Roll No` key anywhere in it, unlike `save_admission()`, which does read
`roll_no` from POST.

---

# 3. All Student-Creation Paths — Definitive Matrix

| Path | Creates Student? | Student ID | SRNO | APAAR | Roll No | Class | Section | Fees | Parent | Other modules |
|---|---|---|---|---|---|---|---|---|---|---|
| **Admission CRM** (`enroll_student()`) | Yes | `_nextStudentId()` | Automatic (`allocateNext()`, Phase 9.1) | Optional, operator-entered | Not handled in this method (assigned later, separately, per Phase 9.1's own report — unchanged) | classKey/sectionKey | classKey/sectionKey | `assignInitialFees` | `syncParent` | Subjects, Auth, history |
| **Direct/Walk-in** (`save_admission()`) | Yes | `_nextStudentId()` | Automatic (`allocateNext()`, Phase 9.1) | Optional, operator-entered | **Yes — `roll_no` POST field, written to `Roll No`** | classKey/sectionKey | classKey/sectionKey | `assignInitialFees` | `syncParent` | Subjects (via later logic not re-traced), Documents, Auth, history, lead-conversion |
| **Re-admission** (`Readmission::confirm()`) | No — relinks an already-created record | Unaffected | **Preserved via `relink()`, never re-allocated** | Preserved via `Apaar_service::relink()` if present | Unaffected | Unaffected | Unaffected | Unaffected | `previousStudentId` write only |
| **Bulk/CSV/Excel import** (`_runStudentImport()`, both the legacy and mapped-flow callers) | Yes | `_nextStudentId()` | **NONE — no field, no allocation, no import column exists** | **NONE — no field, no import column exists** | **NONE — no field, no import column exists, not even in the schema catalogue** | classKey/sectionKey | classKey/sectionKey | `assignInitialFees` (gated by the SAME fee-chart pre-flight) | `syncParent` | Subjects, Auth, history, in-file + cross-file dedup by phone/name+DOB |
| Public admission form (`Admission_public.php`) | **No** — creates a `crmApplications` lead | N/A | N/A | N/A | N/A | N/A | N/A | N/A | N/A | N/A |
| Admin application intake (`Sis::save_application()`) | **No** — creates/updates a `crmApplications` record | N/A | N/A | N/A | N/A | N/A | N/A | N/A | N/A | N/A |

**No 7th-or-beyond path was found.** Repo-wide search for every method that either calls
`_nextStudentId()`/`Id_generator::generate('STU')` directly, or writes a `firestoreCreate`/
`syncStudent`/`saveStudent` call against the `students` collection, returns matches only in the 4
methods above (`enroll_student`, `save_admission`, `_runStudentImport`, and `Readmission::confirm()`
which updates but never creates). No admin API, no "hidden" internal method, no `scripts/` migration
utility that writes live `students` documents was found (the `scripts/` directory's own student-
adjacent files — checked — are read-only inspection/verification tools from earlier phases of this
program, not creation paths).

---

# 4. Historical-Import Problem Analysis

Confirmed, not assumed: `Import_schema.php`'s canonical field catalogue is **exactly 21 fields** —
Name, Class, Section, DOB, Admission Date, Gender, Blood Group, Category, Religion, Nationality,
Father/Mother Name+Occupation, Guardian Contact/Relation, Phone, Email, Street/City/State/PostalCode,
Previous School/Class/Marks. **SR Number, APAAR ID, and Roll Number appear in NONE of them** —
confirmed by grep across the entire file, zero matches for any of `sr_no`/`srNo`/`SR `/`roll_no`/
`Roll No`/`apaar`/`APAAR`.

This means the historical-preservation problem isn't merely "the import logic ignores an incoming
SRNO column" — **there is currently no UI path, no schema entry, and no validation rule through
which an operator could even attempt to supply a historical SRNO, APAAR, or Roll Number during
import, at all, today.** The example scenario in the task prompt (Rahul→501, Amit→502, Priya→503
silently becoming 1, 2, 3) cannot literally happen today only because SRNO isn't allocated during
import *at all* yet (Phase 9.1 deliberately stopped short of wiring it in for exactly this reason) —
but the underlying risk the prompt describes is real and would immediately materialize the moment
someone naively adds `allocateNext()` to `_runStudentImport()` without first building the
schema/UI/validation to support historical values.

---

# 5. Comparison of SRNO Import Strategies

| Criterion | Option 1 — Always automatic | Option 2 — Historical preserved (validated) | Option 3 — Import-mode distinction | Option 4 — other |
|---|---|---|---|---|
| Data integrity | **Risk: destroys real register numbers if the school has them** | Preserves them, IF the operator supplies them correctly | Preserves them for the historical case, automatic for the new-admission case — best of both, but requires the operator to correctly classify the import | Not found — no existing ZenX pattern for this exact problem beyond what Options 2/3 already borrow from `Sr_no_service::claim()` |
| Historical preservation | None | Full, via `Apaar_service`-style validated claim per row | Full, opt-in per import | — |
| Duplicate prevention | Automatic (allocateNext already collision-safe) | Requires per-row `claim()` (already collision-safe, reused not reinvented) — a row supplying an already-owned SRNO must be rejected, not silently overwritten | Same as Option 2 for the historical branch; same as Option 1 for the automatic branch | — |
| School isolation | Already proven (Phase 9.1, `allocateNext` is school-scoped) | Same — `claim()` is already school-scoped | Same | — |
| Re-admission compatibility | No conflict — re-admission never calls `allocateNext()` | No conflict — re-admission never calls `claim()` either; a historical import claiming a number that a LATER re-admission also wants to relink would correctly collide via the existing `expected_owner_mismatch` check | No conflict | — |
| Student ID relationship | Unaffected either way — Student ID generation is structurally independent (Phase 9.1 finding, re-confirmed unaffected by this audit) | Unaffected | Unaffected | — |
| APAAR relationship | Same three options apply independently and identically to APAAR (`Apaar_service::claim()` already supports exactly this "supply an existing value, validate uniqueness" shape — it's what `claim()`-based flows already do for normal admission's optional APAAR field) | — | — | — |
| Roll Number relationship | **Currently a separate, equally-missing gap in bulk import regardless of which SRNO option is chosen** — see §J below | — | — | — |
| Fees/attendance/documents/transport/reports/Student 360/Student Trail | Unaffected by the SRNO strategy choice itself — these all key off Student ID, not SRNO (§K) | — | — | — |
| Future ERP migration | Option 2/3's "supply historical value" mechanism is exactly what a future full-school-onboarding migration would need anyway | — | — | — |
| Auditability | Option 1 is simplest to audit (always the same rule) | Option 2/3 need an explicit audit trail distinguishing "operator-supplied historical" from "system-generated" — `srNoSource` field (already exists: `'admission'`, `'admission_auto'`, `'re_admission_relink'` per Phase 9.1/earlier work) would need a 4th value, e.g. `'import_historical'` | — | — |
| Usability | Option 1 is zero-friction but wrong for onboarding schools with existing registers | Option 2 forces every import to decide, which is friction for schools with NO prior register (most new schools) | Option 3 lets the operator pick the right mode per import — best usability for BOTH cases | — |
| Security | All three reuse the already-hardened `claim()`/`allocateNext()` primitives — no new security surface if implemented correctly | — | — | — |
| Concurrency | All three inherit the same atomic guarantees already proven in Phase 9.1/earlier — a bulk import claiming 500 rows sequentially via `claim()` in a loop is safe by the same collision-doc mechanism, just called 500 times instead of once | — | — | — |

## Recommendation (not implemented)

**Option 3 — explicit import-mode distinction — is recommended.** It is the only option that
serves both real-world scenarios (a school onboarding existing paper records with real historical
numbers, and a school bulk-registering a genuinely new batch of admissions) without forcing either
population through the wrong mechanism. It requires no new identity service — it dispatches to the
SAME two already-built primitives (`Sr_no_service::claim()` for the historical branch,
`Sr_no_service::allocateNext()` for the automatic branch), selected by an explicit operator choice
at import time, not inferred. This is a recommendation for approval, not an implementation.

---

# 6. Cross-Module Dependency Matrix (Part K)

Every module below was checked for **evidence**, not assumed safe. Where a prior phase of this same
program already established the answer with source evidence, that finding is cited rather than
re-derived (per the explicit instruction not to repeat already-completed work).

| Module | Identifies by Student ID? | Uses SRNO? | Uses Roll No? | Uses class/section? | Uses Admission No? | Historical-import mismatch risk? | Re-admission mismatch risk? | Duplicate-SRNO join risk? | Cross-school SRNO collision risk? | Change needed? |
|---|---|---|---|---|---|---|---|---|---|---|
| Student Profile | Yes (primary key) | Display only (Phase 9.1) | Display only | Display only | No | None — SRNO is optional-display, absence is graceful | None | None (SRNO is never a query key here) | None | No |
| Student 360 | Yes | Display only, via `Student_context.php`'s defensive `$pick()` (confirmed in the earlier Phase 9 APAAR investigation — same mechanism covers `srNumber`, a DIFFERENT, still-dead legacy field name, not the live `srNo`; **this is a pre-existing, already-disclosed naming-collision hazard from an earlier phase, re-confirmed still present, not newly introduced**) | Not checked this pass — out of scope for an SRNO-focused audit; flagged as NOT VERIFIED | Yes | No | Low — display-only | Low | None | None | No, but the `srNumber`/`srNo` naming collision from the earlier Phase 9 audit remains open and is re-flagged here |
| Student Trail | N/A — does not exist as a feature (re-confirmed absent, consistent with every prior phase's finding) | N/A | N/A | N/A | N/A | N/A | N/A | N/A | N/A | No |
| Fees | Yes (student doc id) | **Not used as a key anywhere** — confirmed by grep: `Fee_lifecycle::assignInitialFees()` and the fee-chart pre-flight both key by class/section/session, never by SRNO | Not used as a key | Yes | No | None | None | None | None | No |
| Attendance | Yes | Not used as a key (unaffected by SL-12/Phase 9 work, unchanged) | Not verified this pass — flagged NOT VERIFIED | Yes | No | None found | None found | None | None | No |
| Examination/Results | Yes | Not used as a key | Not verified this pass — flagged NOT VERIFIED | Yes | No | None found | None found | None | None | No |
| Documents/Certificates/TC | Yes | Not used as a key currently (§L below) | Not verified this pass | Yes | Yes — `Admission Number` is a documented dual-emit of `srNo` per `SR_NUMBER_AND_APAAR_ID_DESIGN.md` §6.2, feeding `Certificates.php` — **this dual-emit was never actually implemented** (confirmed: `_claimIdentityAttributes()`'s write payload contains `srNo`/`srNoNorm`/`srNoSource` only, no `Admission Number` field, across all of Phase 9/9.1's work) | Design gap, not a NEW one — pre-existing from the original design doc, never built | None | None | **Flagged: the designed `Admission Number` dual-emit was never implemented** — separate follow-up, not this pass's scope |
| Transport | Yes | Not used | Not used | Yes (route/stop assignment, unaffected) | No | None | None | None | None | No |
| Communication | Yes | Not used | Not used | Yes (targeting) | No | None | None | None | None | No |
| Library | Not verified this pass — flagged NOT VERIFIED | Not verified | Not verified | Not verified | Not verified | NOT VERIFIED | NOT VERIFIED | NOT VERIFIED | NOT VERIFIED | NOT VERIFIED |
| Hostel | Not present as a confirmed live module in this repo (referenced only in template/backlog docs from earlier phases) — NOT APPLICABLE | — | — | — | — | — | — | — | — | NOT APPLICABLE |
| ID Card generation | Yes | Not verified this pass (§L) | Not verified this pass | Yes | Not verified | NOT VERIFIED | NOT VERIFIED | — | — | Flagged for §L follow-up |
| Parent management | Yes (via `syncParent`, same `studentId` doc id convention) | Not used | Not used | Mirrored for display | No | None | None | None | None | No |
| **Parent App** | Yes — every Firestore query traced in the earlier Phase 9 APAAR/SRNO investigation reads by `studentId`/`schoolCode`, confirmed again by grep this pass: zero `srNo` references anywhere in `D:\Projects\SchoolSyncParent` | **No — zero references, re-confirmed by grep this pass** | Not verified this pass — flagged NOT VERIFIED (Roll Number display in Parent App was not part of any SRNO-focused prior audit either) | Yes | No | None (SRNO isn't consumed at all) | None | None | None | **No change needed — re-confirmed by fresh grep, not merely cited from memory** |
| **Teacher App** | Yes | **No — zero references, re-confirmed by grep this pass** | Not verified this pass — flagged NOT VERIFIED | Yes (roster) | No | None | None | None | None | **No change needed — re-confirmed by fresh grep, not merely cited from memory** |

**Explicit answer to the directive's Part K question 8 ("could a duplicate SRNO cause incorrect
joins?") and question 9 ("could two schools with the same SRNO collide?"):** **No** — confirmed
structurally, not by policy: no module found anywhere in this audit (Admin Panel, Parent App,
Teacher App) uses `srNo`/`srNoNorm` as a **query key** or **join key** for anything. Every module
that displays SRNO reads it as a plain display field off an already-resolved student document
fetched by Student ID. SRNO's own internal uniqueness (within `srNoIndex`) is enforced entirely
inside `Sr_no_service`, independent of whether any other module ever reads the value.

---

# 7. Admin Panel Impact

Bulk import's SRNO/Roll Number gaps are entirely contained within `Sis.php`'s import methods and
`Import_schema.php` — no other Admin Panel controller/view was found to depend on bulk-imported
students having an SRNO (every downstream module reads SRNO, if at all, as an optional display
field with graceful absence handling — none of them currently *require* it). **Impact is scoped and
additive if implemented — no other Admin Panel file requires a change as a prerequisite.**

---

# 8. Parent App Impact

**NOT APPLICABLE — confirmed by fresh grep this pass, not carried over from memory alone.** Zero
`srNo` references anywhere in the Parent App source tree. Bulk import's SRNO gap has zero Parent App
consequence today, and closing it (whichever option is chosen) requires zero Parent App change,
since the app never reads the field.

---

# 9. Teacher App Impact

**NOT APPLICABLE — confirmed by fresh grep this pass, same as §8.**

---

# 10. Fees Dependency (Part M)

Bulk-imported students already correctly connect to: fee structures (pre-flight gate blocks the row
if missing, same as the other two paths), fee assignment (`assignInitialFees`), and — via the same
Firestore doc id convention every other path uses — feeDemands/receipts/accounting/defaulter
calculation, none of which key off SRNO. **No direct import-SRNO dependency was found that must be
addressed in this phase.**

**Explicitly carried forward for the next Fees workstream, per instruction, not silently dropped:**
1. Parent fee reminders — not audited this pass, named as required for the next Fees phase.
2. Late fee / penalty rules — not audited this pass, named as required for the next Fees phase.
3. ID card regeneration / duplicate ID card fee workflow — not audited this pass, named as required
   for the next Fees phase, and directly relevant to §L's finding that ID card SRNO/Admission Number
   display was not verified this pass.

---

# 11. Roll Number / Class / Section Impact (Part J)

**Critical finding, confirmed by source:** bulk import has **zero** Roll Number handling —
`Import_schema.php`'s field catalogue has no `Roll No` entry, and `_runStudentImport()`'s
`$studentData` array construction never reads or writes a `Roll No` key. This is a **separate,
distinct gap from the SRNO gap** — Roll Number is class/section-scoped (unaffected by, and
unaffected the SRNO decision), but a bulk-imported student today has **no Roll Number at all**,
regardless of which SRNO strategy is chosen. `save_admission()` DOES read `roll_no`, so this is an
inconsistency between two of the three admission paths, not merely an absence everywhere.

**Class/section canonicalization:** bulk import uses the exact same `Firestore_service::classKey()`/
`sectionKey()` primitives as every other path — confirmed unchanged, already hardened under SL-12
(not re-audited in depth this pass per the explicit instruction not to repeat that already-completed
work).

**Roll Number uniqueness enforcement:** not verified this pass whether the existing ZenX architecture
enforces "no two students in the same class+section share a Roll Number" anywhere (admission form,
profile edit, or elsewhere) — flagged **NOT VERIFIED**, out of scope for this SRNO-focused pass, but
directly relevant to the same "no duplicate ownership" principle SRNO/APAAR already enforce, and
recommended as a companion audit item.

**SRNO does not, and under any of the three options would not, influence Roll Number logic** — the
two are structurally independent identifiers in every code path examined.

---

# 12. APAAR Impact (Part I)

- **Is APAAR imported?** No — confirmed, zero `apaar` references anywhere in `_runStudentImport()`
  or `Import_schema.php`.
- **Validated? School-scoped? Uniqueness enforced? Masked?** N/A — not present at all in this path.
- **Uses `Apaar_service.php`?** No — not called anywhere in the import flow.
- **Same rules as normal admission?** N/A — the field doesn't exist here, so there's nothing to be
  inconsistent with yet.
- **Duplicate claim risk from importing historical APAAR?** Same class of risk as SRNO (§4/§5) —
  would apply identically if/when APAAR import is built, and should use the SAME
  `Apaar_service::claim()` primitive already built and tested, not a second mechanism, exactly
  mirroring the SRNO recommendation.

---

# 13. Re-Admission Impact (Part H)

Traced the exact lifecycle requested:

- **Bulk import cannot accidentally create another owner of an SRNO a re-admission relinked** —
  confirmed structurally: `_runStudentImport()` never calls `claim()`/`allocateNext()` today (it
  doesn't touch SRNO at all), so there is currently zero collision surface between import and
  re-admission. If/when Option 3 is implemented, the historical-import branch would use `claim()`
  — the SAME atomic collision-doc primitive `relink()` also respects — so a re-admission that has
  already relinked SRNO 501 to a new Student ID would correctly cause a LATER import attempting to
  claim 501 for a different student to fail with `duplicate`, never silently succeed.
- **Re-admission does not call `allocateNext()`** — confirmed unchanged from Phase 9.1: `relink()`
  and `allocateNext()` are structurally separate methods, and `Readmission::confirm()` only ever
  calls `relink()`.
- **Historical import does not break `relink()`** — confirmed by design: `relink()` reads the LIVE
  `srNoIndex` document state at call time, regardless of whether that document was created via
  `claim()` called from normal admission, re-admission's own prior relink, or (if built) a
  historical-import claim — the mechanism doesn't distinguish origin, by design.
- **APAAR follows the person correctly** — unaffected by anything in this audit; `Apaar_service`
  was not touched, `Readmission::confirm()`'s APAAR-relink block (Phase 9 correction pass) is
  unchanged.

---

# 14. Security Findings (Part N)

| Check | Finding |
|---|---|
| Client-controlled school ID | **Not possible** — re-confirmed for the import controllers specifically: `grep -n "input->post('school" application/controllers/Sis.php` (already run in Phase 9.1, re-applies identically to `import_preview`/`import_validate`/`import_commit`/`import_students`, all in the same file, all using `$this->parent_db_key`) — zero matches. |
| Client-controlled SRNO | **Not possible today** because SRNO isn't handled at all in this path; if Option 2/3 is built, the historical branch's per-row claimed value would need the SAME server-side re-validation `import_commit()` already performs for every other field (`import_validate()`'s results are explicitly never trusted — `import_commit()` re-validates server-side, confirmed by reading the code and its own comment: "never trust the client's prior validation"). |
| Client-controlled Student ID | **Not possible** — Student ID is never read from the uploaded file or the request; `_nextStudentId()` is the sole generator, called server-side only. |
| Cross-school import | **Not possible** — same server-derived school context as every other admission path (§6 above, re-confirmed). |
| Duplicate ownership | Not currently a risk for SRNO/APAAR (absent); IS already handled correctly for phone/name+DOB via the existing skip/update policy. |
| Privilege escalation | Import RBAC gate is `self::MANAGE_ROLES` (Sis.php, confirmed at every import entry point: `import_students`, `import_preview`, `import_validate`, `import_commit`, `import_dedupe_check`) — the SAME role gate as `enroll_student()`/`save_admission()`. No separate, narrower, or broader gate exists for import specifically. |
| CSV tampering / hidden field tampering | `import_commit()`'s explicit server-side re-validation (never trusting client-sent `payload.rows`) is the existing defense; this pattern would need to extend to any new SRNO/APAAR/RollNo columns if built, not bypass it. |
| Replaying import requests / duplicate submission / partial batch replay | Handled today by the phone/name+DOB dedup policy (`skip`/`update`) — a replayed file with the SAME rows is caught by the existing in-file (`$seenPhones`/`$seenNameDob`) and cross-file (`indexPhones` lookup) checks. Not SRNO-specific; unaffected by this audit's findings. |
| Unauthorized historical SRNO assignment / unauthorized correction | **No RBAC decision has been made yet for this** — because the capability doesn't exist. Recommended (not implemented): if Option 2/3 is approved, the SAME `MANAGE_ROLES` gate already protecting import generally is the natural starting point, UNLESS the business decides historical SRNO assignment specifically warrants the narrower Principal-only gate already used for `Readmission::confirm()` (a precedent that exists in this exact codebase for "identity-correcting" actions) — **this is named as an open RBAC question requiring approval, not decided here.** No new graded RBAC capability was found to be strictly necessary — the existing hard-role and graded-capability patterns already in this codebase (documented in the Multi-Tenant Final Audit's own §M) are sufficient precedent either way. |

---

# 15. Firestore Rules/Index Findings (Part O)

- **Reconciliation performed:** re-confirmed this pass (unchanged from Phase 9.1's own finding) —
  `git ls-remote --heads origin` still lists 8 branches, `integration/phase2` still has no remote
  counterpart. Nothing to pull/reconcile.
- **No rule or index change was made, or is currently required.** This audit did not implement
  Option 2/3, so no new collection or query was introduced. If Option 2/3 is later approved and
  implemented, the historical-import branch would reuse `Sr_no_service::claim()` and
  `Apaar_service::claim()` exactly as-is — both already write to already-rules-protected
  (`allow read, write: if false`) collections (`srNoIndex`, `apaarIndex`), confirmed unchanged in
  this pass. **No new Firestore rule or index is anticipated even for the recommended
  implementation** — named here as a forward-looking observation, not a decision to act on now.

---

# 16. Test Plan (Part P) — status of each item

| # | Scenario | Status |
|---|---|---|
| 1 | Historical SRNO import | **NOT TESTED** — feature does not exist yet (no code to test) |
| 2 | New bulk admission (automatic SRNO) | **NOT TESTED** — not wired (Phase 9.1's own deliberate scope boundary) |
| 3 | Duplicate SRNO in same school | **NOT TESTED** for import specifically; the underlying primitive (`claim()`) already has this exact test in `SrNoServiceTest.php` (pre-existing, still passing) |
| 4 | Same SRNO in different schools | Same as #3 — underlying primitive already tested (`SrNoServiceMultiTenantTest.php`), not re-tested for the import context specifically since the import context doesn't exist |
| 5 | Duplicate SRNO within same CSV | **NOT TESTED** — no code exists to test |
| 6 | Duplicate Student ID | **NOT APPLICABLE** — structurally impossible; `_nextStudentId()` is atomic and collision-safe, unrelated to SRNO strategy |
| 7 | Historical 501,502,503 → next automatic = 504 | **NOT TESTED for the import path** — the underlying `allocateNext()` floor-initialization logic IS already tested generically (`test_existing_data_floor_is_respected_not_max_plus_one_in_php`, Phase 9.1, still passing), but that test seeds via `claim()` directly, not via an import file, since no import UI/schema exists yet to test through |
| 8 | Historical gaps | Same as #7 — underlying primitive tested, import context not applicable yet |
| 9 | Empty SRNO where required | **NOT APPLICABLE** — SRNO is not currently a required import field |
| 10 | Invalid SRNO | **NOT TESTED** — no validation exists yet for this non-existent field |
| 11 | Re-admission after historical import | **NOT TESTED** for the historical-import half (doesn't exist); the re-admission half is already covered by existing, passing tests |
| 12 | APAAR + historical import | **NOT TESTED** — neither half exists yet in the import path |
| 13 | APAAR duplicate | Underlying primitive already tested (`ApaarServiceTest.php`), import context not applicable |
| 14 | Cross-tenant isolation | Underlying primitives already tested for both SRNO and APAAR; import-specific re-test not applicable (no import-specific code exists) |
| 15 | School spoofing | Underlying primitive already tested (`test_school_id_spoofing_cannot_allocate_into_another_schools_sequence`, Phase 9.1); import-specific variant not applicable |
| 16 | Import retry | **NOT TESTED this pass** — pre-existing import retry/dedup behavior (phone/name+DOB) was read and understood but not independently re-verified by a new test this pass (audit-only scope) |
| 17 | Partial failure | Same as #16 — read and understood (per-row fail-loud pattern), not independently re-tested this pass |
| 18 | Duplicate batch submission | Same as #16 |
| 19 | Roll Number/class/section integrity | **PARTIALLY VERIFIED** — class/section canonicalization confirmed via source read (same SL-12-hardened primitives); Roll Number integrity is **NOT TESTED / NOT VERIFIED** — the field doesn't exist in this path at all (§11) |
| 20 | Parent App visibility | **VERIFIED (read-only, via grep)** — zero SRNO dependency exists to break; NOT APPLICABLE beyond that |
| 21 | Teacher App visibility | Same as #20 |
| 22 | Fees visibility | **VERIFIED (read-only, via source read)** — fee assignment already works correctly for imported students, unaffected by SRNO presence/absence |
| 23 | Attendance visibility | **NOT VERIFIED this pass** — flagged, out of scope for an SRNO-focused audit |
| 24 | Results visibility | **NOT VERIFIED this pass** — flagged |
| 25 | Documents/certificates visibility | **PARTIALLY VERIFIED** — confirmed the designed `Admission Number` dual-emit was never implemented (§6); full certificate-rendering behavior for imported students **NOT VERIFIED** |
| 26 | Transport visibility | **NOT VERIFIED this pass** — flagged, no evidence of an SRNO dependency found, but not exhaustively traced |
| 27 | Student 360 visibility | **PARTIALLY VERIFIED** — re-confirmed the pre-existing `srNumber` vs `srNo` naming-collision hazard from an earlier phase remains open (§6) |
| 28 | Student Trail integrity | **NOT APPLICABLE** — feature does not exist anywhere in this codebase, re-confirmed |

**No test in this list was fabricated as PASS.** Every item is honestly one of NOT TESTED,
NOT APPLICABLE, PARTIALLY VERIFIED (with the specific verified fact named), or ENVIRONMENT BLOCKED
(none of this pass's items hit an environment blocker specifically — the blockers here are "feature
doesn't exist to test," a different and more fundamental category, named as such rather than
conflated with environment limitations).

---

# 17. Exact Implementation Plan (for when/if Option 3 is approved — NOT executed this pass)

1. Extend `Import_schema.php`'s field catalogue with `SR Number` (optional, `norm: 'digits'`) and
   `APAAR ID` (optional, `norm: 'apaar'` — reusing `Apaar_service::normalize()`'s exact 12-digit
   rule) and `Roll No` (optional, `norm: 'text'`, matching `save_admission()`'s own handling).
2. Add an explicit, operator-selected import mode toggle ("Historical / existing register data" vs.
   "New admissions — assign SRNO automatically") to the import UI — a REAL decision point, not an
   inferred one, surfaced before the operator commits.
3. In `_runStudentImport()`, branch on that mode: historical → per-row `Sr_no_service::claim()` (and
   `Apaar_service::claim()` if supplied) with the row rejected (not silently overwritten) on
   `duplicate`; automatic → per-row `Sr_no_service::allocateNext()`, reusing
   `Sis::_claimIdentityAttributes()` if the calling shape fits, or a closely mirrored variant if the
   per-row-array shape doesn't fit that helper's signature.
4. Add `Roll No` to `$studentData` in both branches, matching `save_admission()`'s existing pattern.
5. Extend `import_validate()`/the dry-run summary to surface per-row SRNO/APAAR conflicts BEFORE
   commit, mirroring `import_dedupe_check()`'s existing opt-in pre-commit scan pattern for phone
   duplicates.
6. New tests: historical-claim rejection on duplicate, automatic-allocation sequencing through the
   import path specifically, mixed-mode safety (a file with some rows supplying SRNO and others
   left blank, if the business decides that's a valid combination — **itself an open question, not
   decided here**).

**This plan is not authorized for execution — it is the answer to "what would we do," produced so
the approval decision in §20 can be made with a concrete plan in view, per the instruction's own
Part R item 19.**

---

# 18. Unresolved Business Decisions Requiring Approval (Part R item 20)

1. **Which SRNO import strategy to adopt** — Option 3 (import-mode distinction) is recommended,
   but this is the primary decision this report exists to surface, not to make.
2. **Whether a single import file may mix historical and blank-SRNO rows**, or whether mode
   selection must be all-or-nothing per file.
3. **RBAC level for historical SRNO assignment/correction during import** — same `MANAGE_ROLES` gate
   as ordinary import, or a narrower Principal-only gate (precedent exists for the latter via
   `Readmission::confirm()`) — named as open in §14, not decided.
4. **Whether Roll Number import should be built in the same pass as the SRNO fix**, given it's a
   distinct but related gap discovered during this audit (§11) — recommended to bundle, since both
   require the same `Import_schema.php` extension mechanism, but not decided here.
5. **The never-implemented `Admission Number` dual-emit of `srNo`** (§6, §16 item 25) — whether to
   build it now, later, or reconsider whether `Certificates.php` should read `srNo` directly instead
   of a separate dual-emitted field — a design question from the ORIGINAL SR_NUMBER_AND_APAAR_ID
   design doc that this audit re-surfaces but does not resolve.
6. **The `srNumber` (dead, legacy) vs `srNo` (live) naming collision in `Student_context.php`/
   Student 360** (§6) — whether to clean up the dead field name now or leave it, re-flagged from an
   earlier phase, not newly created by this audit.

---

# 19. Files Touched This Pass

**None.** This was a read-only audit. `git status --short` line count before and after this pass is
identical (only this report file was added, matching the established pattern of every prior audit
pass in this program).

---

# 20. Final Recommendation

**STOP — awaiting business decision, per instruction.** No architectural conflict was found that
blocks a future implementation; the SRNO/APAAR/Roll Number primitives already built in Phase 9/9.1
are reusable as-is for whichever option is approved (no new identity service, no redesign of
`Sr_no_service.php`/`Apaar_service.php`/`Id_generator.php` required). The single blocking item before
any code is written is **item 1 of §18** — the SRNO import strategy decision — everything else in
§17's implementation plan follows mechanically once that decision is made.

**Nothing was implemented, staged, committed, or pushed in this pass.**