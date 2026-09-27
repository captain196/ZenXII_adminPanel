# Student Lifecycle — Phase 1 Identity Decision

**Date:** 2026-08-13 · **Status: ARCHITECTURAL PLANNING ONLY. Nothing implemented.**
**Supersedes nothing** — this document refines Part L/M of
`STUDENT_LIFECYCLE_PHASE0_TO_PHASE1_READINESS.md` under one clarified constraint: **Student ID and
SRNO are both permanent, coexisting identifiers with different jobs. Neither replaces the other.**

**Working tree: HELD.** No code, data, Firestore, rules, indexes, RBAC, or test data changed. No
commits, staging, or push. Evidence below comes from two new read-only audit passes run in this
session (module-by-module identifier display/search; Student 360 identity assumptions) plus the
already-completed Phase 0 audit and `SR_NUMBER_AND_APAAR_ID_DESIGN.md`.

Every claim is tagged **CONFIRMED** (repo evidence, file:line), **NOT VERIFIED** (would need
production data or a check not performable read-only), **DESIGN DECISION** (a choice, not a fact),
or **RECOMMENDATION** (this document's advice, not yet authorized).

**2026-08-13 update — APAAR clarification.** A fifth identifier, APAAR ID, has been added to the
model at the operator's explicit instruction: it is a government-provided student attribute, never
a ZenX identity. See the new **"APAAR ID — Non-Identity Identifier"** section (inserted after Part
F) and the updated matrix (Part M). No other part of this document changed in substance — the
Student ID / SRNO architecture below is unaffected by this addition.

---

## Final required answers (read first)

1. **Is Student ID the canonical ZenX internal identity?** **CONFIRMED, yes** — unchanged by this
   update. See Part B.
2. **Is SRNO the human-facing operational identifier?** **DESIGN DECISION, already made** in
   `SR_NUMBER_AND_APAAR_ID_DESIGN.md`, reaffirmed here. See Part C.
3. **Is APAAR merely an additional government identifier stored on the student record?**
   **CONFIRMED as the correct framing** — nothing in the codebase or the existing design treats it
   as anything else; see the new APAAR section below.
4. **Can APAAR replace Student ID?** **No — explicitly ruled out.** APAAR is never a Firestore
   doc-id, never a Firebase Auth identity, never a foreign key.
5. **Can APAAR replace SRNO?** **No — explicitly ruled out.** APAAR is not searchable/operational
   in the way SRNO is designed to be; it is a controlled, audience-restricted attribute.
6. **Should SRNO resolve to Student ID?** **CONFIRMED, yes** — unchanged. See Part G.
7. **Should APAAR normally resolve to Student ID?** **No.** APAAR is not a lookup key in normal
   operation. Where APAAR needs to be *matched against* a student (e.g., a government-reporting
   reconciliation), that is an explicitly-authorized, narrow operation — never the default resolution
   path a staff member or Trail/Student 360 uses to find a student.
8. **Should APAAR be used as a Student Trail join key?** **No — explicitly ruled out.** The
   `previousStudentId` link (Part H) is the only sanctioned re-admission join mechanism, and it is
   always human-confirmed. APAAR being identical across two Student IDs is, at most, a **candidate
   signal** a human reviewer may consider — never an automatic merge trigger.
9. **What should staff normally use to identify a student?** **SRNO**, per the existing design and
   this document's Part M matrix — Student ID only where operational/admin troubleshooting requires
   it, APAAR essentially never for lookup.
10. **What should backend modules use internally?** **Student ID, universally, with no exception** —
    unchanged from the original decision.
11. **What should Student 360 display?** Name, **SRNO** (primary human-facing number), Student ID
    (visible for admin/troubleshooting context), and APAAR **masked by default, role-gated, audited
    on reveal** — see the updated Part L.
12. **What should Student Trail display?** SRNO and Student ID for both the current and any linked
    historical admission, with APAAR shown as a profile attribute where authorized — never as part of
    the mechanism that connects historical records. See the updated Part K.
13. **What must never be changed?** Part T, unchanged, plus one addition: APAAR must never be
    promoted into a resolution/lookup/foreign-key role anywhere in the system.

**Stated exactly as required:**
> Student ID = permanent internal ZenX identity
> SRNO = permanent school operational/human-facing identifier
> APAAR ID = government-provided identifier stored as an additional student attribute
> SRNO → Student ID → canonical student
> APAAR does NOT replace either Student ID or SRNO.

---

## A. Official identity model

**CONFIRMED as the target — matches your framing exactly, and matches what the existing
`SR_NUMBER_AND_APAAR_ID_DESIGN.md` already independently specifies (that design was written
2026-08-06/07, before this conversation, and already states the identical principle: "SR number and
APAAR ID are additive display and lookup identifiers layered on top" of an untouched Student ID).**

```
APPLICATION NUMBER  → pre-admission / application lifecycle
ADMISSION NUMBER     → admission-lifecycle / document-facing number
SRNO                  → school's primary human-facing operational student number
STUDENT ID            → permanent internal ZenX system identity
ROLL NUMBER            → class/section-level academic identifier
```

No architectural conflict was found between this model and anything in the current repository —
the gap is entirely that SRNO doesn't exist in code yet (**CONFIRMED**, Phase 0 §3/§6), not that
something contradicts it.

---

## B. Student ID invariants

**CONFIRMED, unchanged from Phase 0/Readiness:**
- Firestore doc-id component (`{schoolId}_{studentId}`).
- Firebase Auth uid/email local-part (`strtolower($userId).'@schoolsync.app'`).
- Custom-claims payload.
- Foreign key on every dependent collection (63 controller files reference `studentId`).
- Global atomic counter, claim-doc CAS allocation (`Id_generator.php`), never per-school.

**Invariant, restated as a hard rule for this document:** Student ID is never reassigned, never
regenerated, never migrated to SRNO, never replaced as a Firestore doc-id component or Auth
identity. Nothing in this document proposes touching any of this.

---

## C. SRNO invariants

**DESIGN DECISION, already made in `SR_NUMBER_AND_APAAR_ID_DESIGN.md` (2026-08-06), reaffirmed
here:**
- Digits only, `^[0-9]{1,9}$`, no prefix, no session segment.
- School-scoped series (each school runs its own numbering).
- Lifetime by default (`resetPerSession:false`) — not reissued per session.
- Immutable once set; Principal-only correction via a single atomic release-and-reclaim transaction.
- Atomic claim doc `srNoIndex/{schoolId}_{srNoNorm}`, create-if-not-exists — never read-then-write.
- **Never** the Firestore doc-id, never the Firebase Auth identity, never a foreign key anywhere —
  purely a resolution/display layer that terminates at `studentId`.

This document adds exactly one refinement the original design did not address (because
re-admission wasn't in scope when it was written — see Part H/I): **what happens to SRNO across a
re-admission event.**

---

## D. Application Number semantics

**CONFIRMED (Phase 0):** real field (`application_id`/`app_id`), two divergent generators
(`APP#####` atomic counter in `Sis.php:6256-6284` vs. `APP_<hex>_<rand>` in
`Admission_public.php:400-401`), doc-id-keyed in `crmApplications`, survives onto the enrolled
student only as an unlabeled internal back-link (`Sis.php:7633`).

**Role in this model:** pre-admission only. It is not, and should not become, a human-facing
operational identifier. **RECOMMENDATION** (unchanged from the Readiness doc, not re-litigated
here): unify on the atomic-counter scheme; this is independent of the SRNO/Student-ID work and
carries no identity risk either way.

---

## E. Admission Number semantics

**CONFIRMED (Phase 0):** read-only everywhere it appears (`Certificates.php:384,460`,
`Student_context.php:446`), **no writer exists anywhere in the codebase**. Currently falls back to
the Student ID when printed, meaning every certificate today prints either a fabricated register
number or the raw internal `STU…` id.

**Role in this model:** admission-lifecycle / document-facing number — distinct from SRNO
conceptually, but the existing design's chosen implementation is that `Admission Number` **dual-
emits `srNo`** (`SR_NUMBER_AND_APAAR_ID_DESIGN.md §6.2`: *"`Admission Number` = Dual-emit of `srNo`
... makes `Certificates.php:383` and `:459` print the real register number with zero change to that
file"*). This is **DESIGN DECISION, already made**, not something this document is re-opening. The
practical effect: Admission Number becomes SRNO's certificate-facing alias, not a fourth
independent number requiring its own generator.

---

## F. Roll Number semantics

**CONFIRMED (Phase 0):** fully implemented, persistent, editable, class/section-scoped, never reset
by promotion, never used as a query key (display/sort field only). No change proposed anywhere in
this document — Roll Number is already correctly scoped and does not interact with the SRNO/Student
ID question.

---

## APAAR ID — Non-Identity Identifier

*(Inserted between Part F and Part G. Not lettered into the existing A–T sequence, to avoid
renumbering every cross-reference elsewhere in this document — treat it as sitting between F and G.)*

**CONFIRMED (re-verified this pass, consistent with Phase 0):** APAAR does not exist in live code.
A repo-wide case-insensitive grep for `apaar|abc_id|abcId` returns exactly two files — both dead
scaffolding, not a real feature: `Student_context.php:463` (`'apaarId' => $pick($stu, ['apaarId', ...])`,
a defensive read of a field nothing writes) and `workspace.php` (rendering whatever that read
returns, i.e. nothing today). No writer, no claim mechanism, no masking logic, no RBAC gate exists
anywhere in `application/`.

**Explicit statement, per your instruction:**

1. APAAR is stored in the student record (once implemented — currently the field is read but never
   written, per the above).
2. APAAR is **not** the ZenX canonical identity.
3. APAAR **does not** replace Student ID.
4. APAAR **does not** replace SRNO.
5. APAAR is **not** a Firestore document identity.
6. APAAR is **not** a Firebase Auth identity.
7. APAAR is **not** a foreign key.
8. APAAR is **not** a session identity.
9. APAAR is **not** a tenant identity.
10. APAAR is **not** the normal Student Trail resolution key.
11. APAAR is **not** the normal Student 360 resolution key.
12. **SRNO** remains the human-facing operational identifier.
13. **Student ID** remains the internal canonical identity.
14. APAAR is an additional government identifier/attribute — nothing more.

**Conceptual structure, restated exactly as instructed:**

```
Student record
    |
    +-- Student ID = STU0001        (internal canonical identity)
    |
    +-- SRNO = 1025                 (human-facing operational identifier)
    |
    +-- APAAR ID = 123456789012     (government-provided attribute)
    |
    +-- Admission Number            (admission-lifecycle, dual-emits SRNO per existing design)
    |
    +-- Application Number          (pre-admission lifecycle)
    |
    +-- Roll Number = 17            (class/section academic identifier)
```

The canonical relationship is `SRNO → Student ID → canonical student`, with APAAR hanging off the
canonical student as an attribute — **never** `APAAR → Student` and **never** `APAAR → Student ID`
as a resolution path.

### RBAC / privacy verification against the actual repository

**NOT VERIFIED — no permission model exists in code today**, because APAAR itself doesn't exist in
code today. There is nothing to verify a runtime RBAC gate against. What exists is:

- **The design's stated intent** (`SR_NUMBER_AND_APAAR_ID_DESIGN.md §4.1`): masked display by
  default (`XXXX XXXX 9012`), full-value reveal gated to Admin/Principal/School Super Admin only,
  every reveal audited, **never** sent to the Teacher App in any payload or listing, Parent App shows
  it for their own child only (masked with reveal), never on public surfaces (receipts, posters,
  offer letters, SMS), export restricted to an audited Admin CSV path.
- **No corresponding code exists to verify this against** — `Student_context.php:463`'s current read
  is unconditional and un-role-gated (it's dead scaffolding, so this isn't a live security gap today,
  but it means the design's privacy model is **entirely aspirational** until implemented, not
  something this audit can confirm as "already enforced."

**Role-by-role assessment, per your checklist — all NOT VERIFIED (no implementation exists), with
the design's stated intent noted for each:**

| Role | Design intent (not yet implemented) | Verified in code? |
|---|---|---|
| Admin | Full reveal, audited | NOT VERIFIED (no code) |
| Principal | Full reveal, audited | NOT VERIFIED (no code) |
| School Super Admin | Full reveal, audited | NOT VERIFIED (no code) |
| Front Office | Masked only | NOT VERIFIED (no code) |
| Teacher | **Never see it — not in any payload** | NOT VERIFIED (no code), but the *intent* is unambiguous and consistent with the existing "Teacher App never gets APAAR" pattern already established for other sensitive fields (e.g., salary data per HR memory) |
| Parent | Own child only, masked with reveal | NOT VERIFIED (no code) |
| Student | Not addressed by the existing design document — **DESIGN DECISION REQUIRED**, not previously specified | NOT VERIFIED |
| Accountant | Not addressed by the existing design document | NOT VERIFIED — **DESIGN DECISION REQUIRED**: an accountant has no obvious operational need for a government student ID; default should be no access unless justified |
| Transport staff | Not addressed | NOT VERIFIED — same reasoning as Accountant, default should be no access |
| Hostel staff | Not addressed | NOT VERIFIED — same reasoning |

**RECOMMENDATION:** before APAAR is implemented, close the two gaps the existing design left open
(Student and Accountant/Transport/Hostel access) explicitly, rather than defaulting them silently —
the safest default for all three is **no access**, consistent with the design's existing "narrow the
audience" philosophy, until a specific operational need is named.

### APAAR and re-admission

**CONFIRMED architecturally sound, consistent with Part H/I's re-admission model:** APAAR is stored
per-Student-ID, exactly like every other attribute in this model (name, DOB, address). A returning
student's new record (`STU0047`) would carry its own `apaarId` field, entered independently at that
admission (or carried forward if the operator has it on hand) — **not** inherited automatically from
`STU0001`.

**Explicit prohibition, per your instruction:** same APAAR value appearing on two Student IDs is
**never**, by itself, a trigger to merge or auto-link records. It is, at most, one additional
candidate signal a human reviewer may weigh alongside name/phone/DOB/guardian/address/email when
manually confirming a `previousStudentId` link (Part H) — and arguably a **stronger** signal than
those others, since APAAR is nationally unique to one child for life (per the existing design's own
framing) rather than name/phone/DOB's known collision risk (Readiness doc Part M §4-7). But
"stronger candidate signal" is not "automatic match" — the human-confirmation requirement in Part H
§8 applies to APAAR exactly as it applies to every other signal, no exception.

**Data-model question you asked to be resolved by audit, not assumption:** should APAAR be stored
on both admission-specific Student IDs, or should a separate government-identifier-linkage mechanism
exist?

**RECOMMENDATION:** store it per-Student-ID (the first option), for one concrete reason found in
this audit: the existing design's uniqueness enforcement (`apaarIndex/{schoolId}_{apaarId}`,
create-if-not-exists claim doc, §4 of the design) is **school-scoped**, exactly like SRNO. Introducing
a separate cross-Student-ID linkage mechanism for APAAR specifically would mean APAAR has a different
resolution shape than every other attribute in this model, for no benefit — the `previousStudentId`
link (Part H) already provides the cross-Student-ID relationship generically, and Student Trail (Part
K) already aggregates attributes across that link at read time. A second, APAAR-specific linkage
mechanism would duplicate that machinery for one field. The safer, simpler architecture is: APAAR
lives on each Student ID's own record exactly like today's design already specifies, and re-admission
linkage (once built) makes both values visible in Trail without needing APAAR to have its own special
cross-reference system.

---

## G. SRNO → Student ID resolution architecture

**CONFIRMED as feasible, no conflicting evidence found:**

```
SRNO 1025
   │
   ▼
srNoIndex/{schoolId}_{srNoNorm}   ← atomic claim doc, deterministic doc-id fetch, no query needed
   │  { studentId, srNo, claimedAt, claimedBy }
   ▼
studentId = STU0001
   │
   ▼
students/{schoolId}_{studentId}   ← canonical student record (existing, untouched)
   │
   ▼
all existing lifecycle collections (unchanged foreign key: studentId)
```

This is exactly the shape `SR_NUMBER_AND_APAAR_ID_DESIGN.md §6.1` already specifies. **No new
architecture is being invented here** — this document confirms the existing design already
satisfies your Part 11 requirement ("SRNO RESOLUTION... must NOT replace Student ID. It only
resolves the human-facing identifier to the canonical internal identifier") and identifies the one
gap the existing design has: it does not yet define resolution behavior across a re-admission
boundary (Part I below).

---

## H. Re-admission model

Restating SL-36 precisely under your clarified framing, with the additional Student-ID/SRNO
distinction now folded in.

**CONFIRMED problem (Phase 0 §24, Readiness §M):** `enroll_student()` always calls
`_nextStudentId()` with **zero lookup** against prior withdrawn/TC'd/deleted students
(`Sis.php:7486` onward). A returning real-world student gets a brand-new Student ID with no
relationship to their old one.

**DESIGN DECISION, reaffirmed from the Readiness doc, now explicitly framed against your hard
constraints (§6 of your message):** **Model A — linked records.** Keep "one Student ID per
admission event" structurally unchanged (no rewrite, no merge, no migration of any existing
document), and add a new, explicit, human-confirmed relationship field on the *new* record pointing
back to the *old* one.

This is the only model compatible with every constraint in your §6 ("do not solve by replacing
Student ID," "do not rewrite historical foreign keys," "do not migrate existing collections," "do
not merge historical documents physically," "do not change Firebase Auth identity"). Model B from
the earlier Readiness doc (one permanent identity with subordinate admission sub-records) is now
explicitly ruled out by your own constraints, not merely deprioritized — it would require either
rewriting the Auth-email-to-Student-ID coupling or making a compound identity object, both of which
your message forbids. **This document formally retires Model B as an option** given the constraints
you've now stated; Model A is the recommended and only compliant path.

**Concretely (RECOMMENDATION, not implemented):**
- New record: `STU0047` (freshly, correctly allocated by the existing, untouched counter).
- New field on `STU0047`'s record: `previousStudentId: "STU0001"` (singular is sufficient for one
  re-admission; **RECOMMENDATION**: use `linkedStudentIds: ["STU0001"]` as an array from day one,
  since a student could in principle leave and return more than once, and an array avoids a second
  migration later).
- **No write to `STU0001`'s record is required** — the link is one-directional from new→old, which
  satisfies "do not rewrite historical foreign keys" completely: nothing about the old record
  changes.
- The link is set **only** after an authorized operator manually confirms the match — never
  automatically, per your §8. Candidate signals (name, phone, DOB, guardian name, address, email)
  surface a *suggested* match for human review; none of them, singly or combined, auto-links.

---

## I. SRNO behavior on re-admission — Option A vs. Option B, with a recommendation

You asked this to be studied, not decided casually. Here is the study.

**What the existing design says today:** `SR_NUMBER_AND_APAAR_ID_DESIGN.md §3.4` — *"Gaps are
allowed; reuse is not... A gap (failed enrolment, a number reserved on paper) is a warning in the
integrity verifier. A duplicate is always an error."* This rule was written for a different
scenario (a number never claimed), not for "a number whose claimant has left and returned," so it
doesn't directly answer this question — it only confirms the general philosophy: **numbers are
never silently reused for a different person**, but re-admission is the same person, which changes
the analysis.

**How real school registers actually work (the basis your message asked me to consider):** A
physical school register (the paper artifact SRNO is modeling, per the design doc's own framing —
*"the identifier schools actually operate on... roll calls, fee registers, TCs"*) is fundamentally a
**chronological ledger of enrollment events**, not a ledger of people. A register entry corresponds
to *this admission*, at *this point in the book*. When a student leaves and returns, real schools
overwhelmingly give them a **new register entry** — a new line in the book, at the current date —
while the school office still knows (informally, or via a cross-reference note) that it's the same
child. This is exactly the "new SRNO, explicitly linked to the old Student ID" shape.

**Option A (same SRNO retained across re-admission) — assessed:**
- Would require **SRNO to become a property of the *person*, not the admission event** — a
  structural redefinition of what SRNO means, contradicting the existing design's own framing of it
  as tied to the register/admission act.
- Creates an immediate technical conflict: the existing claim mechanism
  (`srNoIndex/{schoolId}_{srNoNorm} → {studentId}`) is a 1:1 mapping. If `STU0047` re-claims `1025`
  from `STU0001`, either the index gets **rewritten to point at the new Student ID** (which touches
  a historical claim record — arguably a soft version of the "do not rewrite" constraint, since the
  index itself is metadata, not a lifecycle collection, but still a change to something that existed
  under the old identity) or the school must have released `1025` at withdrawal and now re-claims it
  at re-admission (functionally identical to Option B's "new number," just with the same digits by
  coincidence — this only works cleanly if the number was released and nobody else was assigned it
  in the meantime, which is not guaranteed and would need its own conflict-resolution rule).
- Also breaks the "gaps are allowed, reuse is not" philosophy in spirit: if a different family
  legitimately expects register continuity ("the register doesn't repeat numbers"), silently
  reactivating a released number for a returning student — while technically not *creating* a
  duplicate — still reads as a number "coming back to life," which is a stranger behavior for staff
  to reason about than a clean new entry.

**Option B (new SRNO on re-admission, explicitly linked to the old Student ID) — assessed:**
- **Fully compatible** with the existing design's claim mechanism with zero changes to it — a new
  `srNoIndex` doc is created for `STU0047` exactly the same way as any first-time admission. No
  historical index record is touched.
- Matches how a physical register actually behaves (new line, new date, same known child).
- Composes cleanly with the Part H recommendation: `STU0047.previousStudentId = "STU0001"` and
  `STU0047.srNo` is a fresh claim — the *old* SRNO (`1025`, still attached to `STU0001`'s historical
  record) remains permanently, correctly attached to the historical admission it was actually issued
  for. Nothing about `STU0001`'s SRNO ever changes — satisfying your "historical records must remain
  attached to the Student ID under which they were originally created."
- The only added requirement: Student Trail (Part K) must know to walk the `previousStudentId` link
  and show **both** SRNOs when reconstructing one person's full institutional history — this is a
  Trail-side aggregation concern, not an identity-architecture complication.

**RECOMMENDATION: Option B.** A returning student receives a new SRNO tied to their new admission
event, explicitly linked via `previousStudentId` to their prior Student ID (which retains its
original SRNO permanently, untouched). This is lower-risk (zero change to the existing claim
mechanism), matches real-register semantics, and is the only option that requires no change
whatsoever to already-issued historical `srNoIndex` records.

**This is a recommendation for your authorization, not a decision this document makes unilaterally
— flagging per your Part 9 instruction not to choose casually.**

---

## J. Historical identity model

**RECOMMENDATION, consistent with Part H/I:**
- One real person may correspond to **more than one Student ID** over their lifetime with this
  school (each representing a distinct admission event) — this was already true in principle before
  today's conversation (nothing currently prevents a second enrollment), the only change is making
  the relationship **explicit and queryable** via `previousStudentId`/`linkedStudentIds[]`.
- Each Student ID's own SRNO, Admission Number, application record, fees, attendance, results,
  discipline, certificates, and documents remain **permanently and exclusively attached to the
  Student ID under which they were created** — this is not new, it's simply preserved by construction
  since Model A never rewrites anything.
- The link field itself is the **only** new piece of state this model introduces.

---

## K. Student Trail implications

**RECOMMENDATION**, refining Part U of the Readiness document with the SRNO layer folded in:

```
HUMAN LOOKUP                          INTERNAL AGGREGATION
─────────────                          ────────────────────
SRNO 1025 (current admission)         Student ID STU0047 (current)
    │                                       │
    ▼                                       ├── own history (fees, attendance,
srNoIndex resolution                        │    results, discipline, docs)
    │                                       │
    ▼                                       └── previousStudentId → STU0001
Student ID STU0047                               │
    │                                            └── STU0001's own history
    ▼                                                 (including STU0001's own SRNO,
Student Trail entry point                             now historical, e.g. 1025)
```

- Staff searching **SRNO 1025** (the *current* number) resolves straight to `STU0047` and its Trail.
- Staff searching an *old* SRNO the family remembers (e.g., they still say "1025") would resolve to
  `STU0001` — Trail should recognize this is a **linked/historical** record and offer to jump to the
  current one (`STU0047`), rather than presenting `STU0001` as if it were the active record. This is
  a UX requirement for Trail, not an identity-architecture requirement.
- Trail **never** copies `STU0001`'s records into `STU0047`, never rewrites `STU0001`'s foreign
  keys, never physically merges documents — exactly as your §12 requires. It performs a
  **read-time aggregation**: resolve the link, issue the same per-collection queries it would for a
  single Student ID, once per linked ID, and present them as one continuous timeline.
- Every identifier that touched `STU0001` (its own SRNO, its own Admission Number, its own
  application record) is displayed **in its own historical context** — Trail shows "under STU0001,
  SRNO was 1025, admitted 2023" as a historical fact, distinct from "currently, under STU0047, SRNO
  is 1188."
- **APAAR, added this pass:** displayed as an attribute of whichever Student ID it's attached to
  (current or historical), subject to the same role-gating as everywhere else it appears (see the
  APAAR section above) — **never** used by Trail as the mechanism that connects `STU0001` and
  `STU0047`. That mechanism is exclusively `previousStudentId`. If both records happen to carry the
  same APAAR value, Trail may display that as a corroborating fact ("both records show the same
  government ID") but this is informational, not load-bearing — the link already exists via
  `previousStudentId` before Trail ever looks at APAAR.

```
CURRENT ADMISSION                      HISTORICAL ADMISSION
SRNO       = 1188                      SRNO       = 1025
Student ID = STU0047                   Student ID = STU0001
APAAR      = XXXX XXXX 9012 (masked)   APAAR      = XXXX XXXX 9012 (masked, if present)
```

---

## L. Student 360 implications

**CONFIRMED evidence gathered this pass** (Student 360 was explicitly out of scope for Phase 0; this
is now a first targeted look, still not a full re-audit):

1. **Entry point is Student-ID-only today.** Routes `student360/view/(:any)` etc.
   (`routes.php:1568-1574`) map straight to `Student360::view($studentId)`
   (`Student360.php:183-190`), and `Student_context::build()` does exactly one
   `$this->fs->getEntity('students', $studentId)` call (`Student_context.php:88`) — **no SRNO,
   roll-number, or admission-number resolution exists anywhere in this stack today.**
2. **The raw Student ID is already shown to the operator** directly under the student's name in the
   Student 360 header (`workspace.php:316`, `.s360-idmain`) and again as a JS constant
   (`workspace.php:1051`). This is exactly the kind of internal-identifier-shown-where-a-human-facing-
   one-belongs pattern your Part 13 asks me to flag.
3. **Every navigation entry point into Student 360 passes the raw Student ID** as the URL segment —
   from the SIS list (`views/sis/students.php:498`), the fee-defaulter dashboard widget
   (`home.php:1769`), and the absentee widget (`home.php:1812`). None currently pass or resolve
   SRNO.
4. **Hard single-document assumption, confirmed.** `build()` returns `null` on a miss with no
   fallback, no "try a linked ID," no merge logic (`Student_context.php:88-96`). Every panel/timeline
   reader is independently invoked with the same raw `$studentId` — there is currently no shared
   resolution point where introducing "this ID has a linked predecessor" could be inserted once and
   propagate everywhere.
5. **No linked/previous/duplicate-student concept exists anywhere in the codebase** — confirmed by a
   repo-wide grep this pass. The only place these terms appear at all is inside the Readiness
   document's own (not-yet-implemented) proposal.
6. `Student_context.php` already defensively reads `srNumber`/`apaarId` off the student doc
   (`Student_context.php:462-463`) into the `academic{}` projection — this is dead-but-harmless
   scaffolding today (nothing populates those fields), but it means **the read path for SRNO already
   exists in Student 360's context builder**; only the write path (SRNO generation itself) and the
   entry-point resolution (SRNO → Student ID before `build()` is called) are missing.

**RECOMMENDATION for what Student 360 should do once SRNO exists (not implemented):**
- **Display:** SRNO should appear in the identity header **alongside**, not instead of, the Student
  ID — consistent with your model that both are permanent and both matter, just to different
  audiences. Exact prominence (which is visually primary) is a UI decision outside this document's
  scope, but the raw Student ID should not be the *only* number an operator sees.
- **Search/entry:** a new resolution step should sit **before** `Student_context::build()` — if the
  route segment matches an SRNO shape (digits-only) rather than a Student ID shape (`STU\d+`), resolve
  it via `srNoIndex` first, then proceed with the resolved Student ID exactly as today. This is
  additive — no existing call site needs to change its own logic, only gain one resolution step
  upstream of it.
- **Linked history:** once Part H/J's `previousStudentId` field exists, `Student_context::build()`
  (or a new reader alongside it, following the existing pattern of `Student_timeline_reader.php`
  being a separate read-only fan-out rather than a rewrite of `build()`) would need to detect the
  link and offer the aggregation described in Part K. **This is new work, not a modification of
  existing single-doc logic** — the existing `build()` continues to serve exactly one Student ID; a
  new layer resolves "does this Student ID have linked history" on top.
- **Conflict check: does current Student 360 conflict with this model?** **No direct conflict found**
  — nothing in the current implementation assumes SRNO doesn't exist or actively prevents adding it;
  the gap is purely additive absence, not contradictory design. The one thing to note: the
  `academic{}` projection at `Student_context.php:462-463` reading `srNumber` (camelCase, no "No")
  is a **different field name** than the design doc's chosen `srNo`/`srNoNorm`/`srNoSeq` — this is a
  naming inconsistency between dead scaffolding and the actual design, not a conflict, but worth
  resolving (use the design doc's field names, not the scaffold's) when implementation begins.
- **APAAR, added this pass:** Student 360's header should show Name, SRNO (primary), and Student ID
  (secondary, admin-context) as established above. APAAR does **not** belong in that same header row
  — it should sit in the profile/detail panel where other sensitive PII (DOB, address, guardian
  contact) already lives, **masked by default** (`XXXX XXXX 9012`), with an explicit reveal action
  that is role-gated and audited, per the APAAR section above. `Student_context.php:463` already
  reads the raw value with no masking applied at the data layer — masking must happen either at
  render time in `workspace.php` or as a deliberate second field (`apaarIdMasked`) the context builder
  emits alongside the raw value, with the raw value only included in the payload for roles authorized
  to see it. **This is a real gap to close during implementation, not a cosmetic detail** — the
  current dead-scaffolding read pattern, if activated as-is without adding masking, would leak the
  full APAAR to every role that can load a Student 360 page, contradicting the design's own stated
  privacy intent from day one of implementation.
- **Lookup, restated for this module specifically:** Student 360 lookup remains `SRNO → Student ID →
  Student 360`. **Never** `APAAR → Student 360` — no evidence in the codebase suggests this exists or
  was ever proposed, and this document explicitly rules it out going forward.

---

## M. Module-by-module identifier matrix

Built from this pass's fresh evidence (9 modules) plus Phase 0 findings for the remainder.

| Module | Staff sees SRNO? (future) | Should search by SRNO? | Should query internally by Student ID? | Uses Student ID correctly today? | Incorrectly uses Admission Number? | Incorrectly uses Application Number? | STU ID shown where SRNO belongs? | Needs SRNO resolution layer? | Historical-data risk of changing? |
|---|---|---|---|---|---|---|---|---|---|
| **SIS** (list/search) | Yes | Yes | Yes (unchanged) | Yes | No | No | **Yes — confirmed** (`students.php:484`, raw badge in every row) | Yes — add SRNO as a search field and as the primary displayed column | Low — additive UI change only |
| **Student 360** | Yes | Yes (entry-point) | Yes (unchanged) | Yes | Reads only, no writer (Phase 0) | No | **Yes — confirmed** (`workspace.php:316`, header) | Yes — resolution step before `build()` (Part L) | Low if additive; Medium if `previousStudentId` aggregation is added at the same time |
| **Fees** | Optional (nice-to-have) | Optional | Yes (unchanged — money must stay keyed to the immutable ID) | Yes | No | No | Yes, in search results (`Fees.php:2870`) | Low priority — Fees search already has name/father/class; SRNO would be one more field, not a replacement | Low |
| **Attendance** | No (roster-driven, no search box) | No | Yes (unchanged) | Yes | No | No | No (no free-text search exists at all) | No — not applicable to this module's UX pattern | None |
| **Examination / Results** | No (roster-driven) | No | Yes (unchanged) | Yes | Not checked this pass; Phase 0 found no Admission-Number use in Result.php's core logic | No | No | No | None |
| **Report Cards** | Optional (could print SRNO alongside/instead of the confirmed-wrong Roll No source, SL-18) | N/A | Yes (unchanged) | Partial (SL-18: wrong roll-number source, unrelated to SRNO) | No | No | No | No | Low — SL-18 is a separate, already-scoped fix |
| **Certificates** | **Yes — this is the primary beneficiary** | N/A (single-student action, not search-driven) | Yes (unchanged) | Fabricated fallback today (SL-24) | **Yes — confirmed** (`Certificates.php:384,460` prints Admission Number, currently blank/fabricated) | No | No (dropdown shows name only, `certificates/index.php:838`) | Yes — this is exactly what the design's dual-emit (`Admission Number = srNo`) fixes with zero file changes | Low — additive field, no existing logic touched |
| **Documents** | Optional | Optional | Yes (unchanged) | Yes (Phase 0: Document_service correctly delegates) | Not applicable | No | Not checked this pass | Low priority | None |
| **Communication** | No (group-targeted only, no per-student surface exists — confirmed `search_recipients()` retired, `Communication.php:564-568`) | No | Yes (unchanged, where per-student sends occur) | Yes | No | No | No | No — module has no per-student search surface to add SRNO to | None |
| **Parent** | Yes (parents already see it in their own app profile per the existing SRNO design §3.5) | N/A | Yes (unchanged) | Yes (Phase 0: per-student, not per-family, but correctly keyed) | Not applicable | No | Not checked this pass | Yes, per existing design §3.5 | Low — additive |
| **Teacher** | Yes (per existing design §3.5, "it is what a teacher is told over the phone") | Optional | Yes (unchanged) | Not checked this pass | Not applicable | No | Not checked this pass | Yes, per existing design | Low |
| **Transport** | No, by deliberate current design | Optional | Yes (unchanged) | Yes | No | No | **No — deliberately stripped** (`Transport.php:1022` unsets `user_id` before returning search results) | Low priority — module already avoids exposing internal ID; adding SRNO as an alternative would be consistent with that instinct, not urgent | None |
| **Hostel** | Optional | Optional | Yes (unchanged) | Yes (Phase 0: real module, correctly built core) | No | No | **Yes — confirmed** (`hostel/index.php:241` shows raw ID in autocomplete despite controller stripping `user_id` elsewhere — inconsistent, `id` field survives) | Low priority | Low |
| **Discipline** | Optional | Optional | Yes (unchanged) | Yes (Phase 0) | Not applicable | No | Not checked this pass | Low priority | None |
| **Promotion** | No (batch action, not per-student search) | No | Yes (unchanged) | Yes, except SL-12's canonicalization bypass (unrelated to identity) | No | No | No | No | None (SL-12 is a separate, already-scoped issue) |
| **Withdrawal / TC** | Yes (TC print should show SRNO once Admission-Number dual-emit lands — this is literally SL-24's fix) | N/A | Yes (unchanged) | Yes | **Yes — confirmed** (same SL-24 evidence) | No | Confirmed elsewhere: `tc_print.php` mislabels Roll No as "Adm No." (Phase 0 Q8) — a separate, pre-existing mislabel bug, not something this identity model changes | Yes, via Admission Number dual-emit | Low |
| **Restore** | N/A (SL-33 is an unrelated reachability defect) | N/A | Yes (unchanged) | Yes (underlying logic sound, unreachable — SL-33) | N/A | N/A | N/A | No | None |
| **APIs** | Not checked this pass | Not checked this pass | Yes (unchanged — APIs must keep using Student ID as the stable foreign key) | Not checked this pass | Not checked this pass | Not checked this pass | Not checked this pass | Depends on which APIs are staff-facing vs. system-to-system | Needs its own pass before any API-surface change |
| **Firebase Auth** | No — never | No — never | Yes, exclusively (unchanged, hard constraint) | Yes | N/A | N/A | N/A | **No — must never resolve through SRNO** | **None permitted — this is the one identifier this document forbids touching** |
| **Firestore relationships** | No | No | Yes, exclusively (unchanged) | Yes | N/A | N/A | N/A | No | **None permitted** |
| **Accounting (student linkage)** | Optional (display only, e.g. on a ledger printout) | No | Yes (unchanged — journals must stay keyed to Student ID) | Not independently re-checked this pass; Phase 0 found the journal-posting mechanism itself sound | Not checked | Not checked | Not checked | Low priority | None |

**Where SRNO should be displayed:** SIS list/search, Student 360 header, Certificates/TC print,
Parent app, Teacher app, and optionally Fees/Report-Cards search results — everywhere a staff member
or parent currently has to read or type the internal ID.

**Where Student ID must continue to be used, with no SRNO substitution ever:** Firebase Auth,
Firestore document ids and every foreign key, custom claims, all internal API contracts between
Admin/Parent/Teacher, and every existing lifecycle collection's `studentId` field.

**Where APAAR should be displayed, per this pass's role analysis:** Student profile/detail panel
(masked, role-gated reveal), Parent App (own child, masked with reveal, per existing design),
Admin CSV export (audited). **Nowhere else** — specifically not SIS list columns, not search
results, not the Student 360 header row alongside SRNO/Student ID, not Report Cards, not TC/
Certificates, not Transport/Hostel/Communication surfaces, and never in any Teacher App payload.

### Five-identifier summary matrix

Per your required format, with CURRENT IMPLEMENTATION vs. INTENDED DESIGN marked explicitly wherever
they differ.

| Identifier | Owner | Purpose | Human-facing | Internal canonical | Lookup | Foreign Key | Current vs. Intended |
|---|---|---|---|---|---|---|---|
| Application No | ZenX/School | Application lifecycle | Yes | No | Application only | No | **CURRENT**: implemented, but two divergent generator shapes (Part D) — matches your row for purpose/human-facing/canonical/FK, differs only on internal consistency, which is a separate, already-scoped fix (unification recommendation, Part D), not a conflict with this row |
| Admission No | School/ZenX | Admission lifecycle | Yes | No | Admission/document context | No | **CURRENT**: read-only, no writer, fabricated fallback (Part E). **INTENDED**: dual-emit of SRNO, per existing design — matches your row once implemented; does not match it today |
| SRNO | School | Operational student number | **YES** | **NO** | **YES** | **NO** | **CURRENT**: does not exist in code (design-only). **INTENDED**: exactly matches your row, per `SR_NUMBER_AND_APAAR_ID_DESIGN.md` and Part C/G of this document |
| Student ID | ZenX | Permanent internal identity | Limited | **YES** | Internal | **YES** | **CURRENT and INTENDED are identical** — this row is already fully correct in the live codebase today, confirmed Part B |
| APAAR ID | Government | Government student identifier | Controlled | **NO** | **NOT normal lookup** | **NO** | **CURRENT**: does not exist in code beyond two dead-scaffolding reads (see APAAR section). **INTENDED**: exactly matches your row, per the existing design's §4 and this document's APAAR section |
| Roll Number | School/Class | Academic class identifier | Yes | No | No | No | **CURRENT and INTENDED are identical** — already fully correct and implemented, confirmed Part F |

**The only two rows requiring new implementation work to reach their intended state: SRNO and
APAAR — both currently design-only.** Application Number and Admission Number are implemented but
need the fixes already scoped in Parts D/E. Student ID and Roll Number require no change at all.

---

## N. Existing-data risk assessment

Per your §16, this is read-only and could not query production Firestore data (no service-account
credentials in this repository, no script executed against live data). Every item below is marked
per your required convention.

1. **Existing Student ID integrity** — **CONFIRMED** sound at the code level (Phase 0); actual
   production data was not queried. Code-level: **CONFIRMED**. Data-level: **NOT VERIFIED**.
2. **Existing SRNO field values** — **CONFIRMED** the field (`srNumber`) is read defensively in
   `Student_context.php:462` but **NOT VERIFIED** whether any production student document actually
   has a non-empty value in it (would require a data probe).
3. **Existing Admission Number field values** — **CONFIRMED** no writer exists in code; whether any
   production document nonetheless has a manually-entered value in an `Admission Number`/
   `admissionNo` field is **NOT VERIFIED**.
4. **Existing Application Number field values** — **CONFIRMED** populated (both generator shapes
   exist and are actively written); actual production distribution between the two shapes is **NOT
   VERIFIED**.
5. **Existing Roll Number field values** — **CONFIRMED** populated and actively maintained per
   Phase 0; data quality (duplicates within a section, etc.) **NOT VERIFIED**.
6. **Whether any SRNO values already exist** — **NOT VERIFIED** (same as #2).
7. **Whether duplicate SRNO values exist** — **NOT VERIFIED**; moot until #6 is answered, since no
   claim mechanism exists yet to have prevented duplicates even if values were manually entered
   somewhere.
8. **Whether Admission Number is being used as SRNO today** — **CONFIRMED at the code level**:
   `Certificates.php`'s fallback chain treats it as the register-number stand-in by design intent
   (the field's very name), even though nothing writes it. Whether any operator has manually typed a
   value into it anywhere reachable is **NOT VERIFIED**.
9. **Whether STU IDs are exposed incorrectly to operators** — **CONFIRMED** in this pass: SIS list
   (`students.php:484`), Student 360 header (`workspace.php:316`), Hostel autocomplete
   (`hostel/index.php:241`). "Incorrectly" is a judgment call under your new model — these aren't
   defects under the *old* model (there was no SRNO to prefer), but under this model they're exactly
   the surfaces that should gain an SRNO alternative/primary display.
10. **Whether re-admitted students already exist under multiple Student IDs** — **NOT VERIFIED**;
    would require a fuzzy match (name+phone+DOB) across the full `students` collection, which is a
    data-query operation outside this document's read-only, code-only scope. **This is the single
    most operationally important unknown in this entire document** — flagged for a dedicated,
    explicitly-authorized read-only data probe before Phase 2 implementation begins.
11. **Whether historical records can be linked safely** — **CONFIRMED architecturally safe** (Part
    H/J — the link is additive metadata, never a rewrite); whether any *specific* candidate match in
    production data is a *correct* real-world link is **NOT VERIFIED** and must always go through
    human confirmation (never assume a fuzzy match is correct).
12. **Whether any existing data migration is required** — **CONFIRMED not required** for Student
    ID/Firebase Auth/foreign keys (nothing about them changes). A **backfill is optional, not
    required**, for #10's linking (existing possible-duplicates could be identified and linked as a
    one-time assisted exercise, but the architecture doesn't require it to function correctly for new
    re-admissions going forward).

---

## O. SL-12 plan (canonicalization bypass) — planning only, not implemented

- **Current path:** `execute_promotion()`/`batchMoveStudents()` build class/section keys via
  `Firestore_service::classKey()`/`sectionKey()` (`Firestore_service.php:247-260`) — a bare
  `"Class "`/`"Section "` prefix helper with no ordinal-suffix normalization and no class-order
  lookup.
- **Canonical path (used by every other writer):** `Entity_firestore_sync::normalizeClassSection()`
  (`Entity_firestore_sync.php:164-193`) — resolves `"10"` → `"Class 10th"` via `CLASS_ORDER_MAP`.
- **Affected files:** `Sis.php` (promotion write site), `Dual_write.php` (`batchMoveStudents`/
  `syncStudent`), `Firestore_service.php` (the bypassed helper itself, or its call sites).
- **Affected modules:** every module that queries students by canonical class/section key —
  Attendance, Academic, Homework, Timetable, Subject_assignment, Lesson_plan, Gate_pass, Substitute,
  and (per Part L) Student 360's future class/section-based navigation if any is added.
- **Historical-data implications:** any student promoted through the buggy path already has a
  non-canonical `className` value sitting in production — **NOT VERIFIED** how many, requires a data
  probe (count `students` docs whose `className` doesn't match the `CLASS_ORDER_MAP` canonical
  shape).
- **Backfill requirement:** **RECOMMENDATION** — yes, a one-time normalization pass over existing
  drifted values, in addition to fixing the write path going forward; the fix itself is not
  sufficient to repair already-drifted data.
- **Regression tests (for whenever this is implemented, not now):** promote a student into every
  class-name format currently in production; confirm Attendance/Academic/Homework/etc. queries find
  the student immediately after promotion under the canonical key.

**This remains independent of Part H/I/J's identity work — no dependency in either direction.**

---

## P. SL-13 plan (mid-year admission proration) — planning only, not implemented

**Authoritative date field — CONFIRMED, resolved this pass** (this was "NOT VERIFIED" in the
Readiness document; now traced):

`admission_date` is a real, captured POST field at enrollment (`Sis.php:639`), stored dual-key as
`'Admission Date'`/`admissionDate` (mapping table confirmed at `Sis.php:3824,3855`, and independently
at `Sis.php:5543-5551` for the edit path). This is the **existing, already-populated candidate
field** — no new field needs to be invented; the gap is purely that `buildAdmissionDemandSpecs()`
never reads it (Phase 0, `Fee_lifecycle.php:220-350`).

**RECOMMENDATION:** use `admissionDate`/`'Admission Date'` (already dual-keyed, already populated at
every enrollment) as the canonical join-date field for proration purposes. This avoids introducing a
sixth date-field candidate into a codebase that already has this one working correctly for display
purposes — it simply isn't consumed by fee generation yet.

**Effect on downstream systems, per your §18 checklist:**
- **Fee demands:** would stop generating demands for months before `admissionDate`. Scope of the
  fix itself (add a filter condition inside the existing month-iteration loop) is small; the harder
  question is what to do about demands **already generated incorrectly** for existing mid-year
  admissions (see Existing-Data note below).
- **Receipts:** unaffected for demands that were never paid (they simply wouldn't have existed under
  a fixed generator going forward). For demands **already paid** against a pre-join-date period, a
  receipt exists for a legitimately-collected payment against an illegitimately-generated demand —
  this is a reconciliation question, not a receipt-integrity question (the receipt itself is not
  wrong; what it was collected *for* was wrong).
- **Accounting:** journals derive from receipts (Phase 0: `create_fee_journal` is idempotent and
  correct), so accounting is only affected transitively, through the same paid-demand subset above.
- **Defaulter status:** directly affected — an inflated demand set inflates outstanding balance,
  which can incorrectly flag a mid-year admission as a defaulter for periods before they even
  enrolled.
- **Result withholding:** directly affected, transitively through defaulter status — confirmed chain
  from Phase 0 (defaulter flag → `result_withheld`). A student wrongly flagged a defaulter due to
  this bug could have results wrongly withheld.
- **Historical Trail:** every mid-year-admitted student's financial Trail entry would show incorrect
  historical demands unless a decision is made on data repair (see below) — this is exactly why the
  Readiness document listed SL-13 as a "must-fix-before-trusted" item, not merely a "must-fix-
  eventually" item.

**Classification, per your requirement — DESIGN GAP + DATA GAP combination, not a pure regression
bug:** the function was built without join-date logic from the start (no evidence of prior-correct
behavior that regressed); the join-date field itself already exists and is populated, so this is not
a data-model gap requiring new fields, but the **decision of how to treat already-generated,
possibly-already-paid, incorrect historical demands is a genuine data-repair-strategy gap** that
must be resolved before implementation, not merely a code change.

**This remains independent of Part H/I/J's identity work — no dependency in either direction, aside
from both being financial/Trail-trust-relevant.**

---

## Q. Dependency graph

```
[Student ID — untouched, permanent]
        │
        ├── SRNO design (already complete) ──► SRNO implementation
        │         │
        │         └── SOFT DEP ──► Re-admission Option A/B decision (Part I)
        │                          (soft, not hard: SRNO's core claim mechanism
        │                           works identically either way; only the
        │                           re-admission EDGE CASE behavior depends on
        │                           which option is chosen — recommend deciding
        │                           together, but SRNO for first-time admissions
        │                           could technically ship before this is settled)
        │
        ├── HARD DEP ──► previousStudentId link field (Part H)
        │                          │
        │                          └── HARD DEP ──► Student Trail linked-history
        │                                            aggregation (Part K)
        │                                                    │
        │                                                    └── HARD DEP ──► Student 360
        │                                                                     linked-history
        │                                                                     display (Part L)
        │
        ├── INDEPENDENT ──► SL-12 canonicalization fix (Part O)
        │
        └── INDEPENDENT ──► SL-13 mid-year proration fix (Part P)

[Admission Number dual-emit] ── HARD DEP on SRNO implementation landing first
                                 (per existing design — zero change to Certificates.php,
                                  only possible once srNo exists to emit from)

[Application Number unification] ── fully INDEPENDENT of everything above
```

**Blocking:** the Part I recommendation (Option B) should be formally authorized before SRNO
implementation begins, since SRNO's claim/index behavior around re-admission would otherwise be
built against an undecided assumption — but this blocks only the *re-admission edge case* of SRNO,
not first-time-admission SRNO allocation, which has no dependency on this decision at all.
**Hard dependency:** `previousStudentId` (Part H) is required before Student Trail's linked-history
feature (Part K) can be built, which is in turn required before Student 360 can display it (Part L).
**Independent:** SL-12, SL-13, Application Number unification — none of these depend on or block the
identity/SRNO work.

---

## R. Phase 1 → Phase 2 roadmap

**Phase 1 (this document + Readiness doc's Phase 1/2, now merged in effect):**
1. Authorize the identity model in Part A (formal sign-off, no code).
2. Authorize Option B for re-admission SRNO behavior (Part I) — or explicitly choose Option A with
   the tradeoffs in Part I understood.
3. Authorize the `previousStudentId`/`linkedStudentIds[]` field shape (Part H).
4. Authorize (or defer) the canonical join-date field for SL-13 (Part P — recommendation given,
   `admissionDate` already exists and is populated).

**Phase 2 (implementation, not covered by this planning document, requires separate authorization
per feature):**
1. Implement SRNO exactly per `SR_NUMBER_AND_APAAR_ID_DESIGN.md`, informed by the Part I decision.
2. Implement the SRNO resolution step ahead of Student 360's entry point (Part L).
3. Implement `previousStudentId` capture at re-admission, human-confirmed only (Part H).
4. Implement SL-12's canonicalization fix (independent, can run in parallel with 1-3).
5. Implement SL-13's date-filter fix, once the data-repair-strategy question (Part P) is answered.
6. Implement Student Trail's linked-history aggregation (Part K) — depends on 3.
7. Implement Student 360's display/search integration (Part L) — depends on 1, 2, and (for
   linked-history display specifically) 6.

---

## S. Implementation gates

Before Phase 2 item 1 (SRNO) starts: Part I decision authorized.
Before Phase 2 item 3 (`previousStudentId`) starts: Part H model authorized; a UX/workflow decision
on how an operator triggers and confirms a match (not designed in this document — out of scope,
this document only establishes that it must be human-confirmed, not the click-path).
Before Phase 2 item 5 (SL-13) starts: the data-repair-strategy decision in Part P (forward-fix only
vs. targeted repair of already-paid wrong demands) — recommend a read-only production data probe
first to know the actual scope (per Part N item 10, this is unknown today).
Before Phase 2 item 6 (Trail linked-history) starts: item 3 shipped and at least one real linked
pair exists to test against.

---

## T. Explicit list of things that MUST NOT be changed

- Student ID generation, format, or allocation mechanism (`Id_generator.php`).
- Any already-issued Student ID value.
- The Firestore doc-id scheme (`{schoolId}_{studentId}`) for any existing collection.
- Firebase Auth's email-from-Student-ID coupling (`firebase.php:1137-1140`).
- Any existing foreign key currently pointing at a Student ID, anywhere.
- Any historical `srNoIndex` claim, once SRNO exists (Option B specifically avoids ever needing to
  touch one).
- The existing marks-entry audit trail, TC counter atomicity, Document_service API, or any other
  mechanism confirmed sound in Phase 0 that this document does not touch.
- Custom claims structure or content, except through the existing, already-documented claim-refresh
  mechanism (re-login), never a bulk rewrite.
- Any existing student's history being reattributed from one Student ID to another — Model A's
  entire point is that this never happens.

---

## Final decision questions

1. **Is Student ID still the canonical internal identity?** **CONFIRMED, yes** — nothing in this
   document changes this, and every recommendation here was constrained to preserve it exactly.
2. **Is SRNO now formally defined as the human-facing operational identifier?** **DESIGN DECISION,
   already made in `SR_NUMBER_AND_APAAR_ID_DESIGN.md` and reaffirmed here** — yes.
3. **Can SRNO safely resolve to Student ID?** **CONFIRMED architecturally sound** — the existing
   claim-doc mechanism already does exactly this, with no conflict found anywhere in the codebase.
4. **Should staff normally search by SRNO?** **RECOMMENDATION: yes**, once implemented — in SIS,
   Student 360, and Certificates at minimum (Part M).
5. **Should backend modules continue using Student ID?** **CONFIRMED, yes — universally, with no
   exception found anywhere in the 21 modules audited.**
6. **Should existing Student ID relationships remain untouched?** **CONFIRMED, yes** — nothing in
   this document's recommendations requires touching any existing relationship.
7. **What should happen to SRNO when a student is re-admitted?** **RECOMMENDATION: Option B** — a
   new SRNO is issued for the new admission event; the old SRNO remains permanently attached to the
   old (now-historical) Student ID.
8. **What should happen to Student ID when a student is re-admitted?** **RECOMMENDATION:** a new
   Student ID is issued (via the existing, untouched counter), with a new `previousStudentId` field
   linking it to the prior one, set only after human confirmation.
9. **How should Student Trail combine historical Student IDs?** **RECOMMENDATION:** read-time
   aggregation across the linked chain (Part K) — never a physical merge, never a foreign-key
   rewrite.
10. **What is the minimum architecture required before SRNO can be implemented?** Exactly what
    `SR_NUMBER_AND_APAAR_ID_DESIGN.md` already specifies (§6), **plus** this document's Part I
    decision on re-admission behavior, authorized before implementation begins so the claim logic is
    built against a settled assumption.
11. **What must be fixed before Student Trail begins?** Per the Readiness document's Part J,
    unchanged: SL-36/re-admission decision (now resolved as Model A + Option B in this document) and
    SL-12 (canonicalization). This document adds no new pre-Trail blocker.
12. **What MUST NOT be changed under any circumstances?** Part T, in full — Student ID's generation,
    format, every issued value, the Firestore doc-id scheme, the Firebase Auth coupling, every
    existing foreign key, and every already-shipped-sound mechanism from Phase 0.

---

**Confirmed, restated exactly as required:** Student ID and SRNO are both required. Student ID is
the permanent internal ZenX canonical identity. SRNO is the school's primary human-facing
operational student number. APAAR ID is a government-provided identifier stored as an additional
student attribute. SRNO does not replace Student ID. Student ID does not replace SRNO. APAAR
replaces neither — it is not a ZenX identity, not a resolution key, and not a Student Trail join
mechanism. For re-admission, historical Student IDs are linked via a new, additive, human-confirmed
field (candidate signals may include a matching APAAR value, but never as the sole or automatic
trigger) — existing Student IDs and their historical records are never rewritten or destroyed.

**Nothing in this document has been implemented. Working tree HELD. No code, data, Firestore,
rules, indexes, RBAC, or test-data changes were made. No files staged, committed, reset, stashed, or
pushed.**
