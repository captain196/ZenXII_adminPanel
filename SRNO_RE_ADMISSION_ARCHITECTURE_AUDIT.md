# SRNO Re-Admission Architecture Audit — Strategy A (Same SRNO) vs. Strategy B (New SRNO)

**Date:** 2026-08-13 · **Status: READ-ONLY ARCHITECTURAL COMPARISON. Nothing implemented.**
**Working tree: HELD.** No code, data, Firestore, rules, indexes, or RBAC changed. No commits,
staging, or push.

**Note on process:** your prior message stated Strategy A (same SRNO preserved across re-admission)
as your intended business decision, then was interrupted before I acted on it. Your follow-up
message explicitly asked me not to assume either strategy is correct and to produce an independent,
evidence-based comparison. This document does that — it reaches its recommendation through the
analysis below, not by deferring to the earlier message. Where the conclusion happens to match what
you'd already indicated, that is stated plainly in Part Q, not hidden.

---

## A. Executive Summary

**Recommendation: Strategy A (same SRNO preserved across re-admission), implemented via a
current-pointer-plus-history model on `srNoIndex`, mutated only through a Principal-gated,
human-confirmed re-link transaction.**

The deciding factor is not staff convenience alone — it is that Strategy A is the only option that
keeps SRNO and Admission Number semantically distinct (Part 13 of your prompt asks this directly: does
Strategy B make SRNO redundant with Admission Number? **Yes, it does** — see Part F). Strategy A also
turns out to be **architecturally cheaper than it first appears**, because the existing (unimplemented)
design already specifies a near-identical primitive for a different purpose — the Principal-level SRNO
*correction* path (`SR_NUMBER_AND_APAAR_ID_DESIGN.md §3.4`: "releases the old claim and takes the new
one in a single transaction"). Re-admission re-linking is that same primitive with a different trigger,
not a new mechanism.

The real cost of Strategy A is not technical complexity — it is **trust surface**. The safety of the
entire model rests on one gate: the SRNO current-pointer may only move to a new Student ID through the
same confirmed-re-admission workflow that sets `previousStudentId` (Part H of
`STUDENT_LIFECYCLE_PHASE1_IDENTITY_DECISION.md`), never through the ordinary "claim a free number"
path. Get that gate wrong and the model can silently reassign a real child's operational number to
the wrong person. This is the risk this document asks you to accept knowingly, not one it hides.

---

## B. Current `srNoIndex` forensic findings

**CONFIRMED — design-only, zero live code.** A repository-wide grep for `srNoIndex` across
`application/` returns **no matches**. A broader grep for the whole SRNO field family
(`srNo|srNumber|srNoNorm|srNoSeq`) across every `.php` file in the repository returns exactly **one
file**: `application/libraries/Student_context.php:462` (`'srNumber' => $pick($stu, ['srNumber',
'sr_number', 'SR Number'])`) — a defensive read of a field nothing writes, already identified in
Phase 0 and the Phase 1 document. There is no `srNoIndex` collection, no claim function, no
allocation UI, no correction endpoint, and no index definition anywhere in `application/` or
`firebase-rules/`.

**Everything else in this Part is therefore sourced from `SR_NUMBER_AND_APAAR_ID_DESIGN.md`**
(2026-08-06/07), the one place `srNoIndex` is actually specified, cited exactly:

1. **Where defined:** `SR_NUMBER_AND_APAAR_ID_DESIGN.md §6.1` — "New collections (both server-only,
   deny-all, `schoolId`-prefixed doc ids): `srNoIndex/{schoolId}_{srNoNorm} → { studentId, srNo,
   claimedAt, claimedBy }`".
2. **Exists in live code?** No — design document only.
3. **Proposed doc-id:** `{schoolId}_{srNoNorm}` (deterministic, no query needed to fetch).
4. **Fields:** `studentId`, `srNo` (display-padded string), `claimedAt`, `claimedBy` — **exactly
   four fields**, all singular, no array, no current/historical distinction.
5. **Intended mapping:** `SRNO → exactly one Student ID` — **CONFIRMED, and this is the crux of the
   whole comparison.** The doc-id itself encodes the SRNO; the doc holds one `studentId` field. This
   is structurally a 1:1 mapping by construction, not by convention — there is nowhere in the shape
   to put a second owner.
6. **Assumes one SRNO = one Student ID forever?** **Yes, as written.** §3.3: "A claim document
   `srNoIndex/{schoolId}_{srNoNorm}` is created with create-if-not-exists... Never a `get()`-then-
   `set()`." Create-if-not-exists is explicitly a *permanent* claim primitive — nothing in the design
   as written contemplates transferring an existing claim to a different `studentId` except via one
   narrow path (see #7).
7. **Conflicts with Strategy A?** **Not fundamentally, but requires an addition.** The design *already*
   contains one sanctioned exception to "permanent claim": §3.4, the Principal-level
   `correct_sr_number` action — *"releases the old claim and takes the new one in a single
   transaction."* This precedent is a **release-and-reclaim of the SAME srNoIndex doc**, already
   designed for a *different* trigger (a data-entry correction). Strategy A needs the identical
   mechanism (release old owner, claim new owner, single transaction, audited) with a *different*
   trigger (confirmed re-admission). This is evidence, not assumption: **the primitive Strategy A
   needs already exists in the design's own §3.4, just not yet generalized to this second trigger.**
8. **Works naturally with Strategy B?** **Yes, trivially — zero change required.** Strategy B never
   needs to move an existing claim; a new admission simply claims a fresh, never-before-issued
   `srNoNorm`, exactly as the design already specifies for first-time admissions.
9. **School-scoped?** **Yes, confirmed** — the doc-id is `{schoolId}_{srNoNorm}`, so no cross-tenant
   collision is possible by construction (§6.5 of the design states this explicitly).
10. **Session-scoped?** **No, confirmed** — §3.1: `resetPerSession: false` is the stated default and
    recommended setting ("Most schools: false. A lifetime register number, not a yearly one").
11. **Lifetime-scoped?** **Yes, by design** — see #10.
12. **Can numbers be released?** **Yes, for two documented reasons**: withdrawal/deletion ("the SR
    number is **not** re-issued (gap, not reuse)" §9.2) and Principal correction (§3.4). Neither
    documented release path currently contemplates *re-claiming the same number for the same
    student's new admission* — that third trigger is what this document is evaluating.
13. **Can numbers be reused?** **Explicitly no, as a general rule** — §3.4: "Gaps are allowed; reuse
    is not... A duplicate is always an error." **This rule was written before re-admission was in
    scope** (confirmed: the design document's own §11 open-decisions list does not mention
    re-admission at all), so it does not yet distinguish "reuse by an unrelated new student" (which
    the rule correctly forbids) from "reuse by the same real-world student returning" (which it never
    considered).
14. **Historical ownership represented?** **No — not in the current 4-field shape.** This is the
    concrete gap Strategy A must close (see Part R).
15. **Current ownership represented?** Yes, trivially — the single `studentId` field *is* current
    ownership today, precisely because there's no historical concept to distinguish it from.
16. **Can the design distinguish same-real-world-student from different-real-world-student?**
    **No — confirmed, this capability doesn't exist anywhere in the design or the code.** This is
    exactly what Part H/I of the Phase 1 document already addresses for Student ID
    (`previousStudentId`, always human-confirmed) — SRNO currently has no equivalent, and under
    Strategy A it would need to plug into that exact same confirmation event rather than invent a
    second one.

---

## C. Current SRNO implementation status

**CONFIRMED, consistent with Phase 0 and the Phase 1 document, re-verified this pass:** every
read/write/claim/search/correction path described in Part B is **DESIGN ONLY**. The single live-code
touchpoint (`Student_context.php:462`) is **DEAD SCAFFOLDING** — it reads a field, the field is never
written by any code path, and no `srNoIndex` lookup precedes or follows that read. There is no LIVE
or NOT IMPLEMENTED-with-partial-wiring state to report; it is uniformly DESIGN ONLY / DEAD
SCAFFOLDING across the board.

---

## D. Strategy A — Same SRNO

**Mechanism required (not implemented, described for comparison purposes only):**

```
Confirmed re-admission event (single transaction, Principal/authorized-role gated):
  1. Verify operator has explicitly confirmed STU0047 = the same real-world person as STU0001
     (same confirmation event that sets previousStudentId, per Phase 1 Part H — not a separate gate)
  2. Read srNoIndex/{schoolId}_1025norm  → currently { currentStudentId: "STU0001", ... }
  3. Transactionally update:
       currentStudentId  → "STU0047"
       historicalStudentIds → append "STU0001"
       history[] → append { from: "STU0001", to: "STU0047", actor, reason: "re-admission",
                             timestamp }
  4. STU0001's own record is never written.
  5. STU0047's record stores srNo = "1025" (same digits) at enrolment, sourced from this transaction,
     not freshly claimed.
```

This is a genuine, non-trivial addition to the design (new fields, a new transaction type, a new
authorization gate distinct from ordinary claim), but it reuses the exact transactional shape already
specified for `correct_sr_number` (§3.4) — same "release old, take new, single transaction, audited"
pattern, different trigger and different data shape on the doc (current/historical instead of a flat
overwrite).

---

## E. Strategy B — New SRNO

**Mechanism required:** none — this is the significant finding. Under Strategy B, a re-admission is,
from `srNoIndex`'s perspective, indistinguishable from any other first-time admission: a fresh
`srNoNorm` is claimed via the exact create-if-not-exists path already fully specified in §3.3. The
old `srNoIndex/{schoolId}_1025norm` doc is simply never touched again — it continues, forever,
pointing at `STU0001`.

**Zero implementation risk on the `srNoIndex` mechanism itself** is the entire case for Strategy B.

---

## F. SRNO uniqueness analysis

**CONFIRMED, per Part B:** today, nothing in code enforces SRNO uniqueness at all, because SRNO
doesn't exist in code. The *design's* enforcement mechanism (once built) is the create-if-not-exists
claim doc — Firestore's own atomic document-creation guarantee is the enforcement primitive, not a
security rule, not PHP-side validation, not a Firestore index. (Confirmed from the design text:
§3.3, "the same primitive already used by `indexPhones` and `Accounting_idempotency`" — both of
which this session did not independently re-verify, but the *pattern* — atomic create as the
uniqueness gate — is consistent with how `Id_generator.php`'s own STU-counter claim mechanism works,
which **was** independently verified in Phase 0.)

**Strategy A's effect on uniqueness:** uniqueness is preserved for the *general* case (an unrelated
student can never claim an already-owned SRNO) but is **deliberately, narrowly weakened** for one
specific, gated case: a confirmed-same-person re-admission is allowed to move ownership. This is not
a uniqueness violation in the sense the design's §3.4 rule cares about ("a duplicate is always an
error") — at every point in time, exactly one Student ID is the *current* owner; the model adds a
concept of *historical* owners without ever creating two *simultaneous* current owners.

**Strategy B's effect on uniqueness:** uniqueness is absolute and requires no new reasoning — the
existing create-if-not-exists primitive, unmodified, already guarantees it completely.

**Directly answering your specific question (from the earlier interrupted message and repeated in
the follow-up):** yes, Strategy B does make duplicate prevention trivially and permanently simpler.
That is real, not overstated. The question this document exists to answer is whether that simplicity
is worth the semantic cost in Part 13/Part Q.

---

## G. Duplicate prevention analysis

Comparing your two scenarios directly:

**A. Same real-world student:** `STU0001 → SRNO 1025`, later `STU0047 → SRNO 1025`. Under Strategy
A, this is **valid by construction only if** the re-link transaction (Part D) was invoked — i.e.,
only if an authorized operator already confirmed `STU0047.previousStudentId = STU0001` through the
same workflow. There is no path where SRNO 1025 moves to STU0047 *without* that confirmation having
already happened, because the re-link transaction and the `previousStudentId` write should be the
**same atomic operation**, not two independent ones an operator could get out of sync. This is the
mechanism that answers your Part 6 question directly: **the distinguishing mechanism is
`previousStudentId`/the confirmed re-admission event — not APAAR, not name/phone/DOB matching, and
not a separate "person-level identity record."** Those signals (per Phase 1 Part H §8) are inputs a
human uses to *decide* to confirm; the confirmation event itself, already required for
`previousStudentId`, is what authorizes the SRNO move. No new identity primitive needs to be
invented — SRNO re-linking should be a side effect of the *existing* re-admission-confirmation
workflow, not a parallel decision.

**B. Different real-world students:** `STU0001 → SRNO 1025`, and an unrelated `STU0099` attempts to
claim `1025`. Under Strategy A, this remains **blocked exactly as today's design already specifies**
— `STU0099`'s claim attempt is an *ordinary* claim (not a re-admission-confirmation transaction), so
it hits the unmodified create-if-not-exists path, finds the doc already exists, and gets the existing
named-collision error (§3.3: *"SR number 0147 is already used by Neel Kulkarni (STU00418)"*). **The
two scenarios are distinguished entirely by which code path is invoked, not by any new runtime
check** — ordinary claim vs. re-admission-relink are structurally different operations with
different authorization requirements, so a same-SRNO-different-person collision cannot occur through
the ordinary path, and the relink path is gated on the confirmation event that Strategy A requires
regardless.

---

## H. Re-admission analysis

**What must be stored:** per Phase 1 Part H (unchanged by this document): a new Student ID
(`STU0047`, freshly and normally allocated — no change to `Id_generator.php`), a
`previousStudentId`/`linkedStudentIds[]` field on the new record, and — **new to this analysis** —
under Strategy A, the `srNoIndex` current-pointer update described in Part D.

**What must NOT be copied — CONFIRMED, unchanged from your explicit list:** fee demands, receipts,
accounting journals, attendance, examination records, results, discipline records, transport
transactions, hostel transactions, certificates, documents, concessions, discounts, entitlements.
Every one of these remains permanently attached to the Student ID under which it was created,
**identically under both Strategy A and Strategy B** — the SRNO strategy choice has **zero effect**
on this list. This is worth stating plainly: the two strategies differ *only* in what happens to the
SRNO value and its index doc. Nothing about historical-transaction handling changes based on which
strategy is chosen.

**Profile data (name, DOB, guardian, address, phone, email, photo):** may pre-fill from the
historical record into the new one, remains independently editable, and editing the new record
**must not** write back to the old one — this is unaffected by the SRNO strategy choice and was
already established in the Phase 1 document.

---

## I. Historical data preservation model

Per Part H's explicit checklist:

| Category | MUST PRESERVE | MUST NOT COPY | MAY PRE-FILL | MUST BE NEW | MUST REMAIN HISTORICAL |
|---|---|---|---|---|---|
| Student ID | Old value, untouched | — | — | New value for the new admission | Old Student ID and all its data |
| SRNO | **Strategy A: the digits themselves, ownership relinked. Strategy B: the old value stays historical, a new value is issued.** | — | — | Strategy B only: a new SRNO | Strategy B: old SRNO permanently; Strategy A: the *history of who held it when*, not the number itself |
| Admission Number | — | Old value | — | New value (new admission event, dual-emits the new/relinked SRNO per Part E of Phase 1) | Old value stays attached to the old record |
| Application Number | — | Old value | — | New value (new application, if one is filed) | Old value |
| Roll Number | — | Old value | — | New value (class/section-scoped, resets naturally) | Old value |
| Class/Session | — | Old value | — | New value | Old value |
| Fees/receipts/accounting/concessions/entitlements/discounts | Old, unchanged | **Never copied, either strategy** | — | New lifecycle starts fresh under STU0047 | Fully historical under STU0001 |
| Attendance/Exams/Results/Discipline/Transport/Hostel/Certificates/Documents | Old, unchanged | **Never copied, either strategy** | — | New lifecycle starts fresh | Fully historical under STU0001 |
| Parent relationship | Old, unchanged | Old parent doc(s) | Contact details may pre-fill | New `parents/{schoolId}_{studentId}` doc for STU0047 (per Phase 0's confirmed per-student, not per-family, linkage) | Old parent doc under STU0001 |
| Notifications / audit history | Old, unchanged | Never copied | — | New history starts under STU0047 | Fully historical |

**Strategy choice affects exactly one row: SRNO.** Every other row is identical regardless of which
strategy is chosen — this table is the clearest evidence that the SRNO decision is a narrowly scoped
one, not a decision that ripples into financial/academic data handling.

---

## J. Student Trail impact

Directly answering your six questions:

1. **Which is easier for Student Trail to build?** Strategy B, marginally — Trail never needs to
   reason about "this SRNO has a history"; each SRNO belongs to exactly one Student ID, forever.
2. **Which gives staff a clearer history?** Strategy A — a staff member searching the number a family
   has always known finds the *current* record directly, with the historical admission visible as
   linked context, rather than finding a dead-end historical record with no forward pointer (Strategy
   B does technically support forward navigation too, since `previousStudentId`/`nextStudentId` chain
   traversal is identical either way — but staff would need to search the *new* number to land on the
   current record on the first try, which they don't know if it's a Strategy B world).
3. **Which makes current-student resolution easier?** Roughly equal — both require Trail to resolve
   "given this SRNO, what's the current Student ID," just via a different index doc shape
   (Strategy A: read `currentStudentId` off one doc; Strategy B: the doc IS the answer, no
   current/historical distinction needed, but Trail still needs the `previousStudentId` chain to show
   linked history regardless of which SRNO strategy is used).
4. **Which makes historical admission display clearer?** Roughly equal — both display "Admission
   2023-24: Student ID STU0001, SRNO [old-or-same value]" identically; the only difference is whether
   that historical SRNO value equals or differs from the current one.
5. **Which creates fewer ambiguous lookups?** Strategy B, marginally — under Strategy A, a stale
   cached UI or an out-of-date printed document showing "SRNO 1025 = STU0001" becomes wrong the moment
   a relink happens (the same document later printed would show "SRNO 1025 = STU0047"), whereas under
   Strategy B, a historical document showing "SRNO 1025 = STU0001" remains true forever. **This is a
   real, concrete cost of Strategy A worth naming explicitly** — anything that was ever printed or
   cached with an SRNO-to-Student-ID assumption baked in (an old ID card, an old fee receipt someone
   photographs) could show a now-stale association after a relink. This doesn't corrupt any actual
   data (the *document itself*, e.g. a receipt, remains permanently and correctly tied to STU0001 as
   its own record — only a *casual read* of "SRNO 1025" on that old paper, checked against today's
   live system, would need the context "this was issued under a prior admission").
6. **Which requires less special logic?** Strategy B, clearly — this is the same finding as #1, and
   is the honest core of Strategy B's appeal.

---

## K. Student 360 impact

**Strategy A:** `SRNO 1025 → current STU0047 → Student 360, with STU0001 visible as linked
history.` One search, one number, always resolves to the live record. **This directly matches the
usability goal already stated in the existing SR design's own §3.5** ("Parent App profile — Shown —
parents recognise this number, not STU00418") — extended consistently, a *family* recognizes their
child's number across their whole relationship with the school, not just within one enrollment stint.

**Strategy B:** `SRNO 2025 → current STU0047` (correct, works fine) but `SRNO 1025 → historical
STU0001` (a dead-end unless Student 360 explicitly detects "this Student ID has a
`nextStudentId`/is linked-from" and redirects/offers a jump — which is buildable either way, but is
an *extra* UX requirement Strategy B specifically needs to avoid confusing an operator who searches
the number they remember and lands on a record that reads as inactive/historical).

**Does Strategy A create unacceptable ambiguity in Student 360?** **No — not found.** The only
ambiguity risk identified is the stale-document risk in Part J §5, which is a real but narrow,
non-corrupting, well-understood tradeoff (equivalent in kind to a person's old business card showing
a phone number they've since ported to a new provider — the old card isn't "wrong," it's dated).

---

## L. Fees/accounting impact

**CONFIRMED: neither strategy affects fee demands, receipts, concessions, entitlements, discounts,
defaulters, refunds, accounting journals, or result withholding, at all.** Every one of these systems
is, and remains, keyed exclusively to Student ID (Phase 0, re-confirmed unchanged by this analysis).
SRNO — under either strategy — is display/search-only with respect to money. **Proven, not assumed:**
Phase 0's forensic pass through `Fee_lifecycle.php`, `Operations_accounting.php`, and
`Fee_generation_service.php` found `studentId` as the sole foreign key throughout, with zero
reference to any SRNO-shaped field anywhere in that code (and SRNO doesn't exist in code today to
even be referenced). This holds regardless of which SRNO re-admission strategy is chosen because the
strategy only changes what `srNoIndex` and the student profile display — it never touches the
financial write path.

---

## M. Cross-module impact

Per Part M of the Phase 1 document (module matrix), re-examined specifically for the A-vs-B question:
**no module identified there becomes unsafe under either strategy.** The modules that would display
or search SRNO (SIS, Student 360, Certificates, Parent, Teacher per that matrix) work identically
under both strategies from their own code's perspective — each just calls "resolve SRNO → Student ID"
and gets an answer; which strategy produced that answer is invisible to the calling module. The
*only* place strategy choice has any code-shape consequence is the `srNoIndex` claim/relink mechanism
itself (Part B/D/E) and the re-admission confirmation workflow (Part H). No module in the audited set
— Attendance, Examination, Results, Report Cards, Documents, Transport, Hostel, Discipline,
Communication, Promotion, Withdrawal, TC, Restore, APIs, Firebase Auth, Firestore relationships —
becomes unsafe under either strategy, because none of them resolve through SRNO at all today or in
either design (Part M confirmed this for every module already).

---

## N. Security/concurrency impact

| Scenario | Strategy A | Strategy B |
|---|---|---|
| Two admins assign the same SRNO to two different new students simultaneously | Blocked identically to today's design — ordinary claim path, create-if-not-exists, one wins | Identical |
| Simultaneous admission (unrelated students, different SRNOs) | No interaction, no risk, either strategy | Identical |
| Simultaneous re-admission-confirmation attempts on the same old Student ID by two operators | **New risk surface, Strategy A only** — two operators simultaneously trying to relink SRNO 1025 from STU0001 to two *different* new Student IDs (e.g., a data-entry duplicate-application race) must be resolved by the same transactional CAS pattern already used elsewhere (`__updateTime` precondition, per the marks-entry pattern Phase 0 confirmed sound) — technically solvable with existing patterns already proven in this codebase, but it is a genuinely new scenario that doesn't exist under Strategy B at all | Not applicable — no relink operation exists to race |
| Duplicate browser submission / retry after timeout | Needs idempotency on the relink transaction (same `Accounting_idempotency`-style claim-doc pattern already used elsewhere in this codebase, per Phase 0's confirmed idempotent-journal pattern) | Needs idempotency only on the ordinary claim (already specified in the design, §3.3) |
| Partial/transaction failure mid-relink | **New failure mode, Strategy A only** — if the relink transaction fails after moving `currentStudentId` but before writing `previousStudentId` on the new Student ID (or vice versa), the two records could disagree about the relationship. Mitigated by making both writes part of one Firestore transaction (Firestore multi-document transactions support this natively — the pattern Phase 0 confirmed working correctly elsewhere, e.g. TC-issue/cancel's atomic status flips) | Not applicable |
| Stale UI showing an old SRNO-to-student mapping | Possible (Part J §5) — a genuine, understood, non-corrupting tradeoff | Not possible — mappings never change |
| Concurrent SRNO correction (the *existing* §3.4 mechanism) racing a re-admission relink (Strategy A's *new* mechanism) | **Must be explicitly serialized** — both mutate the same `srNoIndex` doc; a Principal correcting a typo at the same moment a re-admission is being confirmed is a real edge case requiring one lock/transaction discipline covering both operation types, not two independent ones | Not applicable — correction remains the only mutator |

**Which strategy provides the stronger concurrency safety model?** **Strategy B, unambiguously** —
it has no new failure modes because it has no new mutating operation. **Strategy A's added
concurrency surface is real and must be engineered carefully** (single-transaction, CAS-guarded,
same discipline already proven elsewhere in this codebase for TC issue/cancel and marks-entry), but
every pattern it needs already has a working precedent in this repository — this is an argument for
"buildable safely with known patterns," not "unsafe."

---

## O. Data-integrity comparison

| Dimension | Strategy A | Strategy B |
|---|---|---|
| Identity integrity | MEDIUM — correct by design, but depends entirely on the human-confirmation gate never being bypassed | HIGH — nothing to get wrong, no relink exists |
| Historical integrity | HIGH — old records genuinely never touched (confirmed, Part I) | HIGH — identical |
| Financial integrity | HIGH — unaffected either way (Part L) | HIGH — identical |
| Academic integrity | HIGH — unaffected either way | HIGH — identical |
| Operational integrity | HIGH — matches how staff/families actually think about "this child's number" | MEDIUM — a returning family's remembered number goes dead, a real (if minor) operational friction |
| Search integrity | HIGH — one number always finds the current record | MEDIUM — the old number now correctly, but unhelpfully, only finds a historical dead-end without extra UX work |
| Reporting integrity | MEDIUM — a report generated before vs. after a relink could show different SRNOs for the same historical event if not carefully timestamped/versioned | HIGH — reports are stable over time by construction |
| Auditability | HIGH — the relink *is* an audit event by design (Part D step 3); arguably **more** auditable than Strategy B, since Strategy B has no equivalent event to audit (nothing happens, so nothing needs auditing, but also nothing documents "we recognized this as the same person" as a first-class event tied to the SRNO specifically — that fact only lives in `previousStudentId`) | MEDIUM — the same-person recognition still gets audited via `previousStudentId`, but SRNO itself carries no trace of the relationship |
| Security | MEDIUM — the relink authorization gate is a new, sensitive capability that must be scoped correctly (Part A's stated risk) | HIGH — no new sensitive capability introduced |
| Maintainability | MEDIUM — one more transaction type, one more edge case set to test and maintain | HIGH — the design ships exactly as already written, nothing new to maintain |

---

## P. Full A-vs-B comparison matrix

| Dimension | SAME SRNO (A) | NEW SRNO (B) | Winner | Reason |
|---|---|---|---|---|
| Human usability | Number persists for the child's whole relationship with the school | Number resets, old one goes dead | **A** | Matches the design's own stated value proposition (§3.5, "parents recognise this number") extended consistently |
| Staff search | One search always finds current record | Old number is a dead-end without extra UX | **A** | Direct outcome of the above |
| SRNO uniqueness | Preserved, with one narrow gated exception | Absolute, no exceptions | **B** | Simplicity has real value; not disputed |
| Duplicate prevention | Solid, gated correctly (Part G) | Trivially solid | **B**, narrowly | A is not weaker in practice, but requires more careful engineering to be equally strong |
| Re-admission | Requires new relink transaction | Requires nothing new | **B**, for implementation cost | A's cost is real but bounded and precedented (Part B §7) |
| Student Trail | Slightly more logic (resolve current-vs-historical pointer) | Slightly less logic | **B**, marginally | Both fully buildable; difference is small |
| Student 360 | Correctly resolves the remembered number | Requires extra "this is historical, jump to current" UX for the old number | **A** | Direct usability win |
| Historical records | Fully preserved either way | Fully preserved either way | **Tie** | Confirmed identical, Part I |
| Fees | Unaffected either way | Unaffected either way | **Tie** | Confirmed, Part L |
| Accounting | Unaffected either way | Unaffected either way | **Tie** | Confirmed, Part L |
| Attendance | Unaffected either way | Unaffected either way | **Tie** | Confirmed, Part M |
| Results | Unaffected either way | Unaffected either way | **Tie** | Confirmed, Part M |
| Documents | Unaffected either way | Unaffected either way | **Tie** | Confirmed, Part M |
| Transport | Unaffected either way | Unaffected either way | **Tie** | Confirmed, Part M |
| Hostel | Unaffected either way | Unaffected either way | **Tie** | Confirmed, Part M |
| Reporting | Needs point-in-time care (Part O) | Stable by construction | **B** | Real, if manageable, cost for A |
| Security | New, sensitive relink capability to scope correctly | No new capability | **B** | Real, named cost for A (Part A) |
| Concurrency | New race conditions, all with known-pattern mitigations | No new races | **B** | Real cost for A, but solvable with existing codebase patterns |
| Implementation complexity | Higher — new transaction type, new authorization gate, new doc shape | Lower — ships exactly as already designed | **B** | Not disputed |
| Long-term maintainability | One more subsystem to maintain and test | Nothing new to maintain | **B** | Not disputed |
| Scalability | 9-digit cap easily sufficient regardless (Part 13 answer below) | Identical — not a differentiator | **Tie** | See below |
| Alignment with the stated ZenX identity model | SRNO represents "the same real-world student," matching your explicit, repeated framing across three messages in this conversation | SRNO becomes "one number per admission event" — **functionally identical in scope and lifecycle to Admission Number** | **A** | This is the deciding dimension — see Part Q |

**Tally, for what it's worth (not the basis of the recommendation — see Part 17's instruction not
to decide on simplicity/count alone):** B wins more *individual* dimensions (mostly implementation-
cost and safety-margin dimensions), A wins the *usability* and *semantic-alignment* dimensions. The
recommendation in Part Q is not a vote count — it is that the semantic-alignment dimension is not one
dimension among equals, it is the dimension that determines whether SRNO is doing a job distinct from
Admission Number at all.

---

## Q. Recommended strategy

**Strategy A**, with the implementation shape described in Part D/R, and with the security/
concurrency costs in Part N/O explicitly accepted and engineered against, not glossed over.

**Direct answer to Part 13 of your prompt — does Strategy B make SRNO redundant with Admission
Number?** **Yes.** Under Strategy B, SRNO's scope becomes exactly "one number, issued once, per
admission event, never reused" — which is precisely how Admission Number is already defined in this
model (Part E of the Phase 1 document: admission-lifecycle, document-facing, one per admission
event). The two identifiers would differ only in *numbering scheme* (SR's own digit series vs.
Admission Number's dual-emit of it), not in *what they represent*. That is a real, not hypothetical,
redundancy — **this document does not recommend building a five-identifier model where two of the
five collapse into the same semantic role under one implementation choice.**

**This recommendation is reached independently of your earlier stated preference** — the evidence
that tips it is the semantic-collapse finding above, plus the discovery in Part B §7 that Strategy
A's core mechanism is not new engineering effort but a generalization of a transaction the design
already specifies for a different trigger. That said, it is worth being transparent: this conclusion
does align with what you'd already indicated before the interruption. It is presented here because
the analysis supports it, not because you said it first.

---

## R. Required `srNoIndex` architecture

**Not implemented — description only, for your authorization before Phase 2.**

```
srNoIndex/{schoolId}_{srNoNorm}  →  {
  currentStudentId:      "STU0047",           // who holds this number today
  historicalStudentIds:  ["STU0001"],          // append-only, oldest-first or newest-first — pick one convention
  srNo:                  "1025",               // display-padded value, unchanged by relink
  claimedAt:              <original first-claim timestamp, never overwritten>,
  claimedBy:               <original claimant, never overwritten>,
  currentSince:            <timestamp of most recent relink, or claimedAt if never relinked>,
  history: [
    { studentId: "STU0001", from: null,        to: "claimed",  actor, timestamp, reason: "admission" },
    { studentId: "STU0047", from: "STU0001",   to: "current",  actor, timestamp, reason: "re-admission-confirmed" }
  ]
}
```

**Design constraints this shape must satisfy, per your instruction not to choose silently:**
- `currentStudentId` is the *only* field any ordinary lookup path reads (SRNO search, Student 360
  entry resolution) — this keeps every consumer identical to a Strategy-B-shaped lookup; they just
  read one field instead of the whole doc's only field.
- `historicalStudentIds` exists purely for Student Trail's linked-history display and for integrity
  verification (detecting, e.g., a Student ID that appears in `historicalStudentIds` for two
  *different* SRNOs, which would indicate a data-entry error worth flagging).
- The `history[]` array is the audit trail for the relink itself — this event does **not** need a
  separate `auditLogs` write if this array is itself treated as the audit record for this specific
  doc, though a mirrored `auditLogs` entry (matching how every other sensitive action in this
  codebase is audited, per Phase 0's confirmed pattern) is the safer, more consistent choice and is
  recommended.
- **The relink transaction is the only code path permitted to mutate `currentStudentId` /
  `historicalStudentIds`.** The ordinary claim path (§3.3) only ever runs when the doc does not yet
  exist. The correction path (§3.4) only ever runs when `currentStudentId` doesn't change (a typo
  fix to `srNo`'s digits, not an ownership change) — **or**, if a correction is later found to
  actually be a mislabeled re-admission, it should be re-routed through the relink transaction, not
  handled as an ordinary correction. This distinction (correction vs. relink) should be an explicit
  choice presented to the Principal at the point of action, not inferred by the system.

---

## S. Required minimum changes

Not implemented — scope only, for Phase 2 planning:
1. Add `currentStudentId`, `historicalStudentIds`, `currentSince`, `history[]` to the `srNoIndex`
   shape in `SR_NUMBER_AND_APAAR_ID_DESIGN.md §6.1` (a documentation update to the design itself,
   before any code).
2. Specify the relink transaction as a new, explicitly-named operation (e.g.
   `Entitlement_service`-style dedicated method, following the existing codebase convention of one
   service owning one class of mutation) — triggered exclusively by the same confirmed-re-admission
   workflow that sets `previousStudentId` (Phase 1 Part H), never independently invokable.
3. Update every SRNO *read* path (once built) to read `currentStudentId`, not a flat `studentId`
   field — a one-field-name change from what §6.1 currently specifies, with no behavioral change to
   ordinary (non-re-admission) lookups.
4. Specify RBAC for the relink action — **RECOMMENDATION**: same gate as `correct_sr_number`
   (Principal-level, per the existing design §3.4), since both are sensitive, low-frequency,
   audited mutations of the same document.

---

## T. What MUST NOT change

Unchanged from the Phase 1 document, restated for this document's scope:
- Student ID generation, format, every issued value, the Firestore doc-id scheme, the Firebase Auth
  coupling, every existing foreign key.
- SRNO must never become the Firestore document id, the Firebase Auth identity, or a foreign key on
  any lifecycle collection — Strategy A does not change this; `currentStudentId` inside `srNoIndex`
  is a *pointer field*, not a foreign key relied upon by Fees/Attendance/Results/etc., exactly as
  `studentId` was the sole pointer under the original single-field design.
- No historical Student ID's own record is ever written by a relink — confirmed throughout this
  document (Part D step 4, Part I).
- APAAR remains excluded from the identity/resolution mechanism entirely, per the prior document —
  this analysis introduces no change to that.

---

## U. Open questions

1. **No-old-record-exists case (your Part 12) — genuinely unresolved by either strategy, and this
   document does not invent an answer.** If ZenX has no `STU0001` record at all (never captured, or
   the school is recalling a student from before this system existed), there is nothing to link to
   under **either** strategy. Strategy A cannot preserve "the same SRNO" in any *system-verified*
   sense in this case — at best, an operator could **manually type the remembered SRNO digits** into
   the new admission's `srNo` field (the design already supports manual entry per §3.2's `manual`
   mode), with **no** `previousStudentId` link and **no** relink transaction, because there is no
   prior Student ID to relink from. This is functionally identical to a fresh claim under Strategy B
   — the number just happens to match what the family remembers. **This is not a gap unique to
   Strategy A; it is a shared, irreducible limitation of not having the old data**, and should be
   named as such rather than treated as a flaw in Strategy A specifically.
2. **Numbering convention for `history[]` and `historicalStudentIds`** (oldest-first vs.
   newest-first) — a display-ordering decision, not resolved here.
3. **Whether a relink should be reversible** (an accidental/erroneous relink undone) — the existing
   `correct_sr_number` precedent implies yes, symmetrically, but this document does not design that
   specific undo path.
4. **Whether Strategy A's relink should be a fully separate RBAC capability from
   `correct_sr_number`**, or the same one — Part S recommends the same one; this is a recommendation,
   not a closed decision.

---

## V. Final architectural decision

Restating Part 18 of your prompt directly:

1. **Which strategy is safer for ZenX? A. SAME SRNO.**
2. **Why?** It is the only option that keeps SRNO semantically distinct from Admission Number (Part
   Q), it reuses an already-designed transactional precedent (Part B §7) rather than inventing new
   architecture, and it delivers the usability outcome the existing SR design already commits to for
   the ordinary case (§3.5) extended consistently to the re-admission case.
3. **Biggest technical risk of the recommended strategy?** The relink transaction's authorization
   gate — if it is ever invoked outside the confirmed-re-admission workflow (a UI bug, an
   over-permissioned role, a bypassed confirmation step), it can silently move a real child's
   operational number to the wrong Student ID. This must be engineered as a single, tightly-scoped,
   Principal-gated operation, never a general-purpose "edit srNoIndex" capability.
4. **Biggest business risk of the alternative (Strategy B)?** A returning family's remembered number
   goes dead with no automatic forward-resolution, degrading exactly the "staff/parents recognize
   this number" value proposition the SRNO feature exists to deliver — and the identifier
   functionally duplicates Admission Number's role, weakening the argument for maintaining five
   distinct identifiers rather than four.
5. **Can `srNoIndex` safely support the recommended strategy?** Yes, with the shape in Part R — not a
   redesign, an additive extension of the existing 4-field shape.
6. **If not, what minimum architecture change is required?** N/A — see #5. The required change is
   additive (Part S), not a redesign.
7. **Does the recommended strategy require any historical data migration?** **No** — since SRNO does
   not exist in production data today (Part C: zero implementation), there is nothing to migrate.
   This recommendation only shapes how the *first* implementation of `srNoIndex` should be built —
   it is not a retrofit onto existing data.
8. **What must remain untouched?** Part T, in full.
9. **What must be stored for re-admission?** Per Part H/S: the new Student ID, `previousStudentId`,
   and (Strategy A specifically) the `srNoIndex` current-pointer relink with its `history[]` entry.
10. **What must NOT be copied?** Every item in Part I's "MUST NOT COPY" column — unaffected by
    strategy choice.
11. **What must be newly generated?** A new Student ID (always, both strategies), a new Admission
    Number/application record for the new admission event, a new Roll Number at enrollment. **Not**
    a new SRNO, under the recommended Strategy A.
12. **What must be human-confirmed?** The re-admission relationship itself (`previousStudentId`) —
    and under Strategy A, that same confirmation event is what authorizes the SRNO relink; there is
    no separate confirmation step for SRNO specifically.
13. **What happens when no old ZenX record exists?** Per Part U §1 — neither strategy can verify
    continuity in this case; a manually-entered SRNO with no system-verified link is the honest
    fallback, functionally equivalent to a fresh claim.
14. **What happens when the student re-admits multiple times?** `historicalStudentIds` accumulates
    (`STU0001`, then `STU0047`, ... as each prior current-holder is superseded), `currentStudentId`
    always points to the latest — the array shape in Part R already accommodates this without
    modification; no cardinality limit is needed at any realistic re-admission count.
15. **How should Student Trail resolve the complete history?** Read `currentStudentId` to find the
    live record, walk `historicalStudentIds` (equivalently, walk each linked Student ID's own
    `previousStudentId` chain — the two should always agree, and reconciling them is a useful
    integrity check) to aggregate every prior admission's records at read time, exactly as specified
    in Phase 1 Part K, now with the SRNO history folded in as one more attribute displayed per
    historical entry rather than a separate mechanism.
16. **How should Student 360 resolve the current student?** `SRNO → srNoIndex.currentStudentId →
    students/{schoolId}_{currentStudentId} → Student 360`, exactly as specified in Phase 1 Part L,
    unchanged except that the resolution step now reads `currentStudentId` instead of a flat
    `studentId` field.

---

**Nothing in this document has been implemented. Working tree HELD. No code, data, Firestore, rules,
indexes, or RBAC changes were made. No files staged, committed, reset, stashed, or pushed.**
