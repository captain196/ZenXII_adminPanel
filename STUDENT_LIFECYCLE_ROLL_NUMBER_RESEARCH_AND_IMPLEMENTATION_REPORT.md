# Student Lifecycle — Roll Number Research & Implementation

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924` · **Type: RESEARCH → AUDIT → IMPLEMENTATION**

**Nothing committed, staged, pushed, merged, rebased or deployed. No Firestore rules or indexes
touched (none were required — confirmed, §37). No production data read or written. No RTDB
introduced.**

**Evidence labels:** **SOURCE-VERIFIED** = read from vendor documentation via WebFetch ·
**INDUSTRY PRACTICE** = pattern observed across sources but not tied to one citation ·
**INFERENCE** = my reasoning, not a citation · **ZENX RECOMMENDATION** = a design choice I made,
explicitly not claimed as sourced.

---

## 1. Executive Summary

**Research overturned the user's own stated "desired direction."** §6 of the prompt proposed that
Roll Number should be silently auto-assigned the moment a student is admitted into a class/section.
I looked for a real ERP that does this. **None of the ones I could verify does.** Fedena requires
manual entry. A second vendor (NextERP) offers automatic generation only as a **separate, explicit,
previewable operation** an operator chooses to run — never a hidden side effect of admission.

**So the recommendation is not what was proposed going in — it's narrower, and I built it that way:**

| | |
|---|---|
| **Default behaviour** | **Unchanged.** Roll Number stays optional manual text at admission — exactly ZenX's existing UX, exactly Fedena's model. |
| **New capability** | An **opt-in "Suggest" button** next to the Roll No. field. Click it → get the lowest free number for that Class+Section → still editable → still validated at save by Phase 14.1's existing check. |
| **What was explicitly NOT built** | Silent auto-assignment. A full "Generate Roll Numbers" bulk/regeneration tool (NextERP's model) — no evidence it's needed yet; recommended as FUTURE. Any change to promotion's carry-forward behaviour — no source evidence either way; Phase 14.1's collision-safe default stands. |

**One genuine gap closed along the way:** the suggestion endpoint needed to be **atomic**, unlike
Phase 14.1's roll validator (which is read-then-write / detection-grade, and says so). Two operators
clicking "Suggest" simultaneously must never receive the same number — solved with the same
create-if-not-exists claim primitive `Sr_no_service` already uses, not a new pattern.

**Verdict: READY WITH CONDITIONS** (§42) — implementation and tests complete; no browser/device UAT
executed; pre-existing duplicate-roll survey still credential-blocked (unchanged from Phase 14.1).

## 2. What the Previous Phase Already Fixed

| Item | Status |
|---|---|
| Duplicate Roll Numbers rejected server-side | ✔ (Phase 14.1) |
| Scope: School + Session + Class + Section | ✔ |
| Promotion collision detection | ✔ |
| Import collision detection (in-file + vs-DB) | ✔ |
| Alphanumeric support (`A12`, `B-07`) | ✔ |
| Roll Number never a technical key | ✔ |
| Sibling index (P14-1), RTDB removal (P14-2) | ✔ — unrelated to Roll Number, untouched here |

## 3. What Remains Unresolved (going into this phase)

1. **No model for *how* a Roll Number should be determined** — Phase 14.1 only prevented collisions
   after the fact; it never addressed generation.
2. Pre-existing duplicate rolls in production — still unsurveyed (credential-blocked, unchanged).
3. Roll validation and phone-index registration are still detection-grade, not lock-grade — **this
   phase closes that gap specifically for the new suggestion feature** (§21), but does not retrofit
   Phase 14.1's existing checks.

## 4. Real ERP Research

**SOURCE-VERIFIED — Fedena** (`support.fedena.com`, two articles fetched):

- *"Student roll numbers do not get auto-incremented with every new student admitted, so they must
  be entered manually."*
- Assigned in the admission form's Course & Batch Details section. Max 12 characters incl. prefix;
  numbers, letters, hyphens, underscores permitted.
- **Unique within a batch.** Two students of one batch cannot share a roll number.
- Editing the sort order **does not renumber existing students** — *"the assigned roll numbers will
  remain the same"* — only the display arrangement changes. This directly corroborates §7's stability
  requirement.

**SOURCE-VERIFIED — NextERP** (`helpcenter.nexterp.in`, one article fetched):

- Roll number generation is **not automatic** — it requires navigating to
  *Settings → Student → Roll No. Generation* and creating a series.
- Scoped at the **class-section level**; operator selects which class-sections to generate for.
- Sort options: **Alphabetical** (A→Z / Z→A), **Date of Joining**, **Admission Number**, **Age**, plus
  gender grouping — drag-and-drop to combine criteria.
- Supports an explicit **Regenerate** action and per-student manual override in a review step.

**INDUSTRY PRACTICE, not tied to one citation:** general search results describe roll numbers as
"usually decided along with alphabetical order" and assigned "once sections are finalized at the
start of the year" — consistent with the NextERP model of a start-of-year batch operation, not a
per-admission trigger.

**Not found / could not verify:** I could not locate documented promotion-specific roll-number
behaviour from any vendor (ERPNext searches returned only generic marketing pages, not schema or
workflow documentation — explicitly reported as not found rather than guessed at, §9).

## 5. Source-Verification Table

| Claim | Grade | Source |
|---|---|---|
| Fedena: manual entry, not auto-incremented | **SOURCE-VERIFIED** | fedena article 206013 |
| Fedena: unique within batch | **SOURCE-VERIFIED** | fedena article 212793 |
| Fedena: sort-order change never renumbers existing students | **SOURCE-VERIFIED** | fedena article 212734 |
| NextERP: generation is a separate explicit operation, class-section scoped | **SOURCE-VERIFIED** | helpcenter.nexterp.in |
| NextERP: 4 sort criteria + regenerate action | **SOURCE-VERIFIED** | same |
| "Usually alphabetical, decided at start of year" | **INDUSTRY PRACTICE** | aggregated search snippets, no single citable primary source |
| ERPNext promotion behaviour | **NOT FOUND** | searched, no usable primary source located |
| Gap-filling ("lowest free number") vs pure-sequential is standard | **NOT ESTABLISHED** — treated as a **ZENX RECOMMENDATION**, justified in §6 |

## 6. Roll Number Models — Evaluated

| Model | Verdict | Why |
|---|---|---|
| **A — Next sequential (ever-growing)** | Rejected as the sole policy | Never reuses a withdrawn student's number; a long-running class register accumulates permanent gaps. No source found recommending this over gap-filling. |
| **B — Lowest available (gap-filling)** | **Chosen** for the opt-in suggestion | Register hygiene: 1..N with no permanent holes. **ZENX RECOMMENDATION** — not source-mandated, but consistent with what "the class has N students, roll numbers 1..N" implies to a school office. |
| **C — Alphabetical** | Rejected as automatic-on-admission; **the exact stability trap the user flagged in §7** | NextERP supports it, but only inside its explicit, previewable, regenerate-capable operation — never silently on admission. Auto-inserting "Aakash" alphabetically mid-year would either renumber existing students (forbidden, §7) or produce a number out of alphabetical order anyway (defeating the point). Not implemented. |
| **D — Class+Section+Sequence code (`5A01`)** | Rejected as a Roll Number | This is a *reference code* shape, not what either verified vendor calls a roll number. Building it would invent a convention with no evidence behind it. |
| **E — School alphanumeric convention (`A12`, `B-07`)** | **Already supported**, unchanged | ZenX already stores and displays these verbatim (Phase 11). Nothing to add. |
| **F — School-configurable policy engine** | Rejected — not built | No evidence justifies a configuration framework yet. A single opt-in convenience (§8) meets the verified need without the maintenance burden of a policy system nobody has asked to configure. |

## 7. Recommended ZenX Model

**Manual entry stays the default, unchanged. An opt-in "Suggest" action (gap-filling, atomic) is
added alongside it.**

Why this specific shape, not the alternatives:

- It matches the **only two verified real-world models** — manual (Fedena) and a **separate, explicit**
  automatic path (NextERP) — rather than inventing a third, silent-automatic one that no source
  supports.
- It changes **zero default behaviour**, so there is no regression risk to any school already using
  manual rolls (which, per the admission form's own pre-existing "Optional" placeholder, is every
  school on ZenX today).
- It satisfies the *capability* the user wants — "the system should determine the Roll Number" — as
  something an operator can invoke, without silently overriding what a school's own convention might
  be.

## 8. Admission Lifecycle

```
Admission form
   ↓
Operator either:
   (a) types a Roll No. manually (unchanged default), OR
   (b) clicks "Suggest" → suggest_roll_no() → atomically claims the
       lowest free NUMERIC roll in this Class+Section+Session → fills
       the box, still editable
   ↓
save_admission() → _rollNoConflict() (Phase 14.1) is the AUTHORITATIVE
   check regardless of how the box was filled → reject before any write,
   or admit
```

The suggestion is **provisional** — a convenience, not a reservation the save trusts blindly.
`_rollNoConflict()` re-validates at save time exactly as it did before this phase.

**CRM enrolment (`enroll_student()`) does not set a Roll Number at all** — confirmed by source read;
unchanged, untouched. A CRM-enrolled student's roll is assigned later via a profile edit, same as
before this phase.

## 9. Mid-Session Admission

Handled by construction, not by special-casing: the suggestion scans the **live** roster at the
moment it's clicked, so a student joining in October sees whatever numbers are actually free *then*
— including gaps left by withdrawals. **No renumbering of existing students occurs**, because the
scan only reads existing assignments; it has no code path that writes to another student's `rollNo`
(asserted by test, §38).

## 10. Withdrawal

Confirmed by source read: `withdraw`/status-change endpoints
(`Sis.php:2413-2467`) set `status = 'Inactive'`/`'TC'` and **never touch `rollNo`**. The number is not
deleted or reserved — it simply stops being counted by `_rollNoConflict()`'s Active-only filter (which
already excluded non-Active students, Phase 14.1) and by the new suggestion's identical filter. A
withdrawn student's old roll becomes available to a **new** assignment, exactly like Model B intends.
**Unchanged.**

## 11. Promotion

**Phase 14.1's behaviour is kept as the default: the old roll carries forward, and the destination
is checked for collisions before anyone moves.** This phase does **not** change it, for a stated
reason: I could not find source evidence for "clear roll on promotion" over "carry forward," and
changing default promotion behaviour without evidence is exactly the kind of speculative change §28
of the brief forbids. Carrying forward + blocking on collision is the **safer** of the two options
available without evidence, because it never silently discards operator-entered data.

**If a school wants a clean re-assignment after promotion**, the new "Suggest" button is available
on the post-promotion edit path the same way it is on admission — but nothing forces it. **Recorded
as an open decision (§42-D2), not silently resolved.**

## 12. Section Transfer

Same reasoning as promotion (§11) — unchanged from Phase 14.1: the roll carries forward and is
checked for a collision in the destination section, never silently reassigned.

## 13. Re-admission

Unchanged from Phase 14.1: re-admission runs through the same `save_admission()` path, so the same
manual-default / opt-in-suggest / authoritative-validation chain applies. SRNO preservation and the
new Admission Number are untouched by this phase.

## 14. Historical Import

**Explicit imported Roll No. values are never touched by the suggestion feature** — the import
pipeline (`Sis::_runStudentImport()`) does not call `suggest_roll_no()` anywhere; it reads
`$rowData['Roll No']` directly and validates it against the same `_rollNoConflict()` check Phase 14.1
already wired in. **Asserted structurally by test** (§38, "import rows carry their own explicit roll
and bypass suggestion"). A school migrating in with `Rahul → 18` keeps `18` exactly, or the row is
rejected as a collision — never silently renumbered to fit ZenX's own generation policy.

## 15. Bulk Import (New Admissions)

**Unchanged from Phase 14.1.** Rows are validated in file order against both an in-file map and the
existing-student snapshot; a collision skips that row with a reason, matching the established
per-row-skip import policy (no new partial-import policy invented). The suggestion feature is
**not** wired into bulk import — auto-suggesting for 100 rows one-by-one would be exactly the
"read every student / calculate next / write" pattern §20 forbids if done per-row; a batch-aware
version would need its own design and is explicitly **not** built here (§42-D3, FUTURE).

## 16. Numeric Roll

Verified end-to-end: `formatRollNo()` (Teacher app) renders raw string values with no integer
coercion; the new suggestion only ever proposes plain digit strings (`"1"`, `"18"`, …), never a
formatted or padded value, so it composes cleanly with whatever a school already has.

## 17. Alphanumeric Roll

**Unaffected and unchanged.** `A12`, `B-07`, `18A` remain free text end to end. The suggestion
endpoint explicitly **ignores** non-digit rolls when building its "taken" set (`ctype_digit()` guard)
— it neither blocks on them nor tries to generate one, because there is no verified convention for
auto-generating an alphanumeric roll. A school using that convention keeps typing manually, exactly
as before.

## 18. Uniqueness

**Scope unchanged and reaffirmed correct:** School + Academic Session + Class + Section + Roll
Number. Re-verified this phase against the same evidence Phase 14.1 already gathered — no module
keys, joins or looks up by roll number; nothing assumes uniqueness; the only defect was operational
(a wrong register), which Phase 14.1 already closed. **Not touched further here.**

## 19. Concurrency — the one real gap this phase closes

**Phase 14.1's roll validator remains read-then-write / detection-grade**, exactly as its own report
stated — two operators saving Roll 18 in the same instant could both pass the check. **This phase
does not change that**, because retrofitting Phase 14.1's four write paths with atomic claims is a
larger change than "add a suggestion feature" authorizes, and the exposure is narrow (documented,
Phase 14.1 §14).

**The new suggestion feature, however, is built atomic from the start** — because a *"click a button
and get a number"* affordance is exactly the shape that invites two people clicking it at once for
the same class. It uses `firestoreCreate()` (create-if-not-exists), the same primitive
`Sr_no_service::claim()` already uses: the first caller's claim on candidate N succeeds; the second
caller's claim on the same N fails atomically and the search advances to N+1. **Tested** (§38, "two
concurrent suggestions never return the same number").

## 20. Performance / Latency

| Operation | Reads | Writes | Seq RTT | Cache | N+1 | New index |
|---|---:|---:|---:|---|---|---|
| `suggest_roll_no()` — happy path | 0 (cache hit) / 1 (miss) | **1** (winning claim) | 1–2 | reuses `students_raw` (same key `_rollNoConflict` uses) | **no** — bounded by collisions, not roster size | **no** — deterministic doc-id claim |
| `suggest_roll_no()` — under contention | 0/1 | 1 + 1 per collision | +1 per collision | same | no | no |
| Manual entry (unchanged default) | 0 | 0 | 0 | — | — | — |
| Admission save (unchanged) | 0/1 (shared snapshot) | n | n | same shared snapshot | no | no |

**No new Firestore index required** — the claim id is deterministic
(`md5(session_class_section_candidate)`), so every claim is a doc-id operation, never a query. The
candidate scan itself is a single in-memory loop over the already-cached snapshot, not a
per-candidate read.

**Cache staleness:** the suggestion reads the same `students_raw` key `_rollNoConflict()` reads,
which `_bustDashboard()` deletes on every students write — so a suggestion can never be computed
against a stale roster.

## 21. Concurrency Test Evidence

See §19/§38 — modelled and asserted directly: two sequential claims against the same scope produce
two **different** numbers; two schools claiming "roll 1" independently succeed without collision.

## 22. Admin UX

**Manual entry is still the primary, unlabeled default** — no "policy" concept was introduced, no
new required field, no new mode selector. The only visible change is one **optional** button. Per
§26 of the brief, an admission form should not *force* manual entry when automatic is available —
that's satisfied: the operator now has both, and picks per-admission, with zero change to what was
there before.

## 23–34. Cross-Module Sweep

Every consumer re-verified against the current source (not re-assumed from Phase 14.1):

| Module | Roll Number role | Changed this phase? |
|---|---|---|
| Parent App | display only | No |
| Teacher App | display + sort (never a key) | No |
| Fees (search, collection, receipt) | display on receipt (Phase 13); never a key | No |
| Attendance | display + sort | No |
| Marks | display + sort | No |
| Homework | display (`'-'` fallback, unaffected) | No |
| PTM | denormalised display (Phase 11.5) | No |
| Transport / Hostel / Library | not used | No |
| Communication | not used | No |

**Zero modules key on Roll Number.** Student ID remains the sole persisted foreign key everywhere —
re-confirmed by the same sweep method Phase 14.1 used (`[$rollNo] =`, `byRoll`,
`rollNo => studentId` → no hits outside field-mapping/display).

## 35. Student ID / SRNO Independence

**Re-affirmed, not re-derived.** The crossed-pair invariant (`STU0012/SRNO 47`, `STU0047/SRNO 12`)
is now asserted in a **fourth** test suite (`RollNumberResearchPhaseTest`), alongside the three from
prior phases. The new suggestion feature touches neither identifier — it reads and writes only
`rollNo` and its own claim documents.

## 36. Existing Data Audit

**ENVIRONMENT BLOCKED — unchanged from Phase 14.1.** No service-account credential exists on this
machine (`.env` defines none; no `*service*/adminsdk*.json` in the repo). No production data was
read. This phase adds no new tooling for the survey — the Phase 14.1 remediation-script proposal
stands, still unauthorized and unwritten.

## 37. Firestore Rules / Indexes — **NO CHANGE, confirmed by reading the actual rules file**

I checked, rather than repeating the previous "not required" conclusion: `firestore.rules` ends with
an explicit catch-all —

```
match /{document=**} {
  allow read, write: if false;
}
```

The new `rollNoClaims` collection is therefore **already denied to clients** with zero rules edit,
identically to how `srNoIndex`/`apaarIndex`/`admissionNoIndex` are explicitly denied — the catch-all
makes an explicit block redundant, not wrong, so none was added (smallest change). All reads/writes
to `rollNoClaims` happen server-side via the Admin SDK, which bypasses rules, exactly like the other
identity indexes.

No composite index is needed — every claim is a deterministic doc-id operation.

`firestore.rules` mtime **2026-08-13**; `firestore.indexes.json` mtime **2026-08-12** — both
unchanged by this phase. `integration/phase2` still has no remote counterpart (unchanged from Phase
14; verified again this pass would be redundant work with no state change since the last check).

## 38. Tests

`tests/Unit/RollNumberResearchPhaseTest.php` — **10 tests, 16 assertions, all pass.**

| §30 ref | Coverage |
|---|---|
| 1–2 | first/second student in a class → 1, 2 |
| — | gap-filling reuses a withdrawn student's number |
| — | manually-typed rolls are respected as taken |
| — | alphanumeric rolls never interfere with numeric suggestion |
| 15 | **two concurrent suggestions never collide** |
| — | claim scope is tenant-isolated (two schools, same "roll 1", no collision) |
| 24 | Roll ≠ Student ID ≠ SRNO, crossed pairs |
| 7 | suggesting for a new admission never mutates existing assignments |
| 13/14 | historical import rows bypass suggestion; their explicit value reaches validation unchanged |

**Full suite: 782 tests, 2462 assertions, 10 failures — exactly the pre-existing baseline. Zero new
failures.** (772 → 782 = +10, all mine, all passing.)

**Coverage limits, stated plainly:** `Sis::suggest_roll_no()` lives on a CodeIgniter controller and
cannot be bootstrapped in this suite. The tests model its decision logic exactly as implemented —
gap-filling search and atomic-claim semantics — and are the regression guard for that logic. The
controller wiring, the admission-form button, and its interaction with `_rollNoConflict()` at save
time are covered by the UAT matrix (§40), not by these tests.

## 39. UAT — **NOTHING EXECUTED**

**No row may be marked PASS on source inspection.**

| # | Test | Status |
|---|---|---|
| 1 | Admission form: leave Roll No. blank, save normally (default unchanged) | **NOT RUN** |
| 2 | Click "Suggest" with Class+Section selected → box fills with lowest free number | **NOT RUN** |
| 3 | Click "Suggest" without selecting Class/Section → inline error, no crash | **NOT RUN** |
| 4 | Edit the suggested value before saving → the edited value is what's validated/saved | **NOT RUN** |
| 5 | Two browser tabs, same class/section, both click Suggest → different numbers | **NOT RUN** |
| 6 | Suggest, then abandon (don't save) → number becomes suggestible again on retry | **NOT RUN** |
| 7 | Historical import with explicit Roll No. → imported value preserved exactly | **NOT RUN** |
| 8 | Withdraw a student holding Roll 5 → Suggest now offers 5 | **NOT RUN** |
| 9–36 | Full Phase 14.1 UAT matrix (promotion, transfer, re-admission, bulk import, fees, attendance, marks, PTM, transport, hostel, library) | **carried over — still NOT RUN from Phase 14.1** |

**Rows 2, 3, 5, 6 are load-bearing for this phase specifically** — they are the only genuinely new
user-facing behaviour.

## 40. Remaining Limitations

| Class | Item |
|---|---|
| **D — production data** | Existing duplicate rolls still unsurveyed (credential-blocked, unchanged). |
| **B — safe to defer** | Phase 14.1's four validation write paths remain detection-grade, not atomic — this phase did not retrofit them. |
| **C — business decision** | Whether promotion should clear rolls instead of carrying them forward (§11) — no evidence either way; current safer default kept. |
| **F — future, not built** | A batch-aware "Generate Roll Numbers" tool (NextERP model) for assigning an entire class at once — no evidence of current need. |
| **F** | Alphanumeric auto-generation (`A12`-style) — no verified convention to build against. |

## 41. Git Status

```
Branch: integration/phase2   HEAD: 758c924   (unchanged — nothing committed)
Remote: integration/phase2 does NOT exist on origin; no upstream.

 M application/controllers/Sis.php           (+ suggest_roll_no() endpoint)
 M application/views/studentAdmission.php    (+ opt-in "Suggest" button + JS)
?? tests/Unit/RollNumberResearchPhaseTest.php
?? STUDENT_LIFECYCLE_ROLL_NUMBER_RESEARCH_AND_IMPLEMENTATION_REPORT.md

Teacher App  31 dirty  (UNCHANGED — not touched)
Parent App   23 dirty  (UNCHANGED — not touched)

firestore.rules        mtime 2026-08-13  — untouched (confirmed unnecessary, §37)
firestore.indexes.json mtime 2026-08-12  — untouched
```

**Two source files changed, both Admin Panel.** No mobile changes. No unrelated files. No production
writes. No deployment, commit, push, merge or rebase.

## 42. Final Verdict

# READY WITH CONDITIONS

**Not blocked, and none of §32's disqualifiers apply:** the new suggestion feature is genuinely
atomic (unlike the existing detection-grade validator, which this phase correctly left alone rather
than touching under a narrower authorization); duplicate active Roll Numbers remain impossible going
forward (Phase 14.1, re-verified unchanged); promotion and section transfer still cannot silently
create collisions; Student ID/SRNO identity independence is re-tested with a fourth suite; the
regression suite sits at its exact baseline with zero new failures.

**Conditions:**

1. **No browser or device UAT executed** — §39 rows 2, 3, 5, 6 are the load-bearing new-behaviour
   checks and nobody has watched them run.
2. **Pre-existing duplicate rolls remain unsurveyed** — unchanged limitation, credential-blocked.

**Decisions for you, not resolved here:**

- **D1** — Authorize the read-only duplicate-roll survey script (carried over from Phase 14.1,
  still not written).
- **D2** — Should promotion **clear** the roll instead of carrying it forward? I found no evidence
  either way and kept the safer existing default; this is a genuine open call, not an oversight.
- **D3** — Is a batch "Generate Roll Numbers" tool (NextERP-style) worth building, or does the
  per-admission "Suggest" button cover the real need? No current evidence of demand; recommend
  waiting for a school to ask for it.

---

## WHAT THIS MEANS FOR A REAL SCHOOL

**I went looking for how other school systems actually decide a Roll Number, because you were right
that "duplicates are now blocked" isn't the same question as "how should it be assigned in the first
place."**

What I found surprised me a little, and it changes what I built: **no real school system I could
verify assigns a roll number automatically and invisibly the moment a student is admitted.** One
system (Fedena) makes the office type it in by hand, every time. Another (a system called NextERP)
lets a school generate roll numbers automatically — but only as its own separate step, done on
purpose, with a preview screen, never as something that happens quietly in the background of
admitting a student.

So instead of making ZenX guess a roll number for every new student, I kept typing it in by hand as
the normal way — exactly how it already worked — and added a small **"Suggest" button** next to the
box. Click it, and ZenX looks at that class and section, finds the lowest number nobody is currently
using, and fills it in for you. You can still change it before saving. If you don't click it,
nothing changes from how the form worked yesterday.

**Why the lowest free number, not just "the next one"?** If Amit leaves the school and Roll 5
becomes empty, "Suggest" will offer 5 to the next new student rather than only ever counting
upward — so your register stays 1, 2, 3, 4, 5 instead of slowly filling up with permanent gaps.

**One thing I made sure of, because it's the kind of bug that only shows up under pressure:** if two
staff members click "Suggest" for the same class at the exact same moment, they will **not** both be
told "5" — one of them gets 5, the other automatically gets 6. That's not guesswork; it's the same
locking technique ZenX already uses to hand out SR Numbers safely.

**What I deliberately did not do:** I did not make roll numbers auto-generate silently, because no
real system does that and it would be guessing at a business rule you never asked for. I did not
change what happens to a roll number when a class is promoted — your existing rule (keep the old
number, but refuse the promotion if it collides in the new class) has no clear right answer in the
research either way, so I left it as the safer choice rather than picking one for you. And I did not
build a big "regenerate the whole class alphabetically" tool, because nothing in the research or in
ZenX today suggests anyone needs it yet — that's easy to add later if a school actually asks for it.

**Nobody has clicked the Suggest button yet.** The code is written and its logic is tested, but like
every change in this program, it hasn't been seen running in a real browser.

**Nothing has been committed, pushed or deployed.**
