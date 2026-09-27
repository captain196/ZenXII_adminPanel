# Sibling / Family Relationship — Deep Architecture Audit

**Date:** 2026-08-14 · **Branch:** `integration/phase2` · **Type: AUDIT ONLY — zero code changed.**

No file edited, no rules/indexes touched, no data written, nothing staged/committed/pushed/deployed.

---

## 1. Executive Summary

**ZenX has no sibling relationship model, and no family or parent entity.** What exists is a
client-side heuristic in the Parent App that scans every student in the school and guesses at
siblinghood from a phone number and parent-name strings.

The audit found **three mutually inconsistent sibling definitions** in three different modules, none
of which agree with each other, plus **five defects** — two of them serious enough to be labelled
BLOCKER and HIGH:

- **SIB-B1 (BLOCKER, data loss, live):** bulk-importing two siblings on the same parent phone
  **silently discards the second child**, or — under the "update" policy — **overwrites the first
  child's record with the second child's data.** The system cannot tell "sibling" from "duplicate".
- **SIB-H1 (HIGH, security, live):** the `students` collection is readable by **any authenticated
  user in the school**. The sibling heuristic is a UI convenience, not a security boundary.
- **SIB-H2 (HIGH, live):** a re-admitted student appears **twice** in the Parent App — the old
  withdrawn record and the new one — as two separate children.

Industry standards (Ed-Fi, OneRoster, SIF) and every professional SIS examined model this as an
**explicit guardian/contact → student association**, with sibling *derived* from a shared
contact/household. Across **seven open-source products read at schema level**, not one stores a
student↔student sibling edge. Phone matching appears in only two verified products: **Aeries**, where
it is an admin-facing suggestion with a confidence score and mandatory confirmation that populates a
**stored** family key; and **MyClassboard** (India), where — as in ZenX — it is a live inference, and
its parent app links a sibling account **with no credential check at all**. That is a cautionary
parallel, not a precedent: it makes a phone number the authorization boundary. ZenX already has a
production incident proving why (§4, §16.5).

**Materially de-risking finding:** ZenX **already has a written design for exactly this**, unbuilt,
in `ZENX_ADMISSION_DOMAIN_SPEC.md:160-172` — a canonical parent record keyed by normalised phone
with true multi-child `childrenIds[]`, a `contacts[]` array for father/mother/guardian/emergency, and
`indexPhones` changed from overwrite to append. It independently arrives at essentially the industry
pattern, explicitly names the same root cause ("*cannot express siblings and mints duplicate
accounts*"), and preserves the frozen UID==studentId auth model. **Verified spec-only — no part of it
is implemented** (§25/§28).

**Verdict: BLOCKED** (§30) — not for the display work, but because the underlying relationship model
does not exist and one live defect destroys data.

---

## 2. Current ZenX Sibling Architecture

**There isn't one.** Confirmed by exhaustive repo-wide search: `familyId`, `family_id`, `siblingId`,
`siblingIds`, `guardianId`, `parentId` (as a relationship), `childIds`, `linkedStudents`,
`relatedStudents` — **zero hits as stored fields** anywhere in the Admin Panel, either app, Firestore
rules, or Cloud Functions.

Relationship data exists only as **per-student denormalised strings**: `Father Name`, `Mother Name`,
`Guard Contact`, `Guard Relation`, `Phone Number`.

## 3. Exact Source of the Current Child List

`StudentFirestoreRepository.findSiblings()` — `D:\Projects\SchoolSyncParent\...\StudentFirestoreRepository.kt:136-219`.

```kotlin
val fetched = firestoreService.queryDocumentsAs<StudentDoc>(STUDENTS) { ref ->
    ref.whereEqualTo("schoolId", schoolCode)          // :143 — ONLY filter
}
```

**It downloads every student document in the school**, then filters in Kotlin. No `status` filter,
no `limit`, no parent/family predicate. Called from three places: `DashboardViewModel.kt:225` (every
dashboard load), `TransportHomeScreen.kt:304`, `TransportEventsScreen.kt:197`.

## 4. Exact Basis Used to Identify Siblings

`StudentFirestoreRepository.kt:192-213`:

```
phone matches (normalised)                       ← hard prerequisite (:200-201)
AND (
     fatherName matches                          (:207-208)
  OR motherName matches                          (:209-210)
  OR primary's parent names are BOTH blank       (:212)
  OR candidate's parent names are BOTH blank     (:212)
)
```

**The last two clauses are the hole.** If *either* record has blank father *and* mother names, a
**phone match alone** makes them siblings. The code's own comment (`:159-162`) admits this is a
deliberate concession for legacy blank data.

**ZenX has already been burned by this.** The comment at `:154-158` records a real production
incident: *"shared/recycled phone numbers (e.g. grandparents, in-laws, extended family) caused
unrelated students to appear as siblings — the Mahendra (Brijesh Verma) / Hanuman Ji (Maharaj
Kesari) collision in prod was the trigger."* Name-matching was added as the fix, but the blank-name
escape hatch left the same class of bug open.

### The three contradictory definitions

| # | Where | Rule | Status |
|---|---|---|---|
| 1 | Parent App `findSiblings()` | phone AND (father OR mother OR either-blank) | **Live** |
| 2 | `Fee_management.php:893-919` | exact trimmed `fatherName` string, groups of 2+ | **Live** (sibling-discount eligibility) |
| 3 | `Fee_lifecycle::calculateSiblingDiscount` `:745-782` | `parentDbKey == $parentDbKey` | **DEAD — zero callers** |

**Definition 3 is a landmine.** `parentDbKey` **is the school code** (`Entity_firestore_sync.php:458`;
`Admin_login.php:237` calls it a "legacy alias of schoolCode"). Its query therefore matches **every
student in the school** — if it were ever wired up, every child would be every other child's sibling
and receive a sibling discount. Its own comment (`Fee_lifecycle.php:752-754`) asserts the opposite,
and is simply wrong.

## 5. Parent / Guardian Model — none

No parent or guardian entity exists. The `parents` collection is **one document per student**, keyed
`{schoolId}_{studentId}` (`Entity_firestore_sync.php:412`) — it is a *student-shaped* mirror, not a
parent record. Two siblings produce **two unrelated parent documents** for the same human parent.

`childrenIds` is hardcoded (`Entity_firestore_sync.php:461`):
```php
'childrenIds' => [$studentId],   // always overwritten with THIS student only
```
It sits in the "always written" block, so merge cannot preserve a longer array. The method docblock
(`:407`) claims *"For multi-child: childrenIds[] array accumulates student IDs"* — **the code
contradicts its own documentation.**

The collection is **write-only**: zero Admin Panel readers, and **no `match /parents/` rule exists**
in `firestore.rules` → default-deny → unreachable by both apps. It is dead data.

## 6. Family Model — none. ## 7. Sibling Model — none (inferred at runtime only).

## 8. Parent App Trace

| Step | What identifies it | Evidence |
|---|---|---|
| Login | Firebase Auth; **`auth.uid` == the student's ID** | `firestore.rules:2329-2332` "for parents, Firebase Auth uid == studentId" |
| Claims | `role`, `school_id`, `student_id`, `parent_db_key`, `school_code` | `Sis::_createFirebaseAuthStudent` |
| Child list | **runtime scan** (§3) | `findSiblings()` |
| Switch child | `switchToSibling()` rebuilds `User`, saves to DataStore | `DashboardViewModel.kt:248-318` |
| Every other screen | reads `tokenManager.user` | — |

**A parent is never identified as a parent.** They authenticate *as one of their children*. There is
no parent principal in the system.

### Consequence: sibling switching is only partly functional

`isOwnStudent()` (`firestore.rules`) checks `resource.data.studentId == request.auth.uid` — the
**single** logged-in child. It does **not** consult a `student_ids` array.

`student_ids` appears in only **three** rules (tripLogs `:1940`, transportAttendance `:2024`, one at
`:2696`) — all Transport. And critically: **no production code ever sets a `student_ids` claim.**
The only PHP references are a backfill that *preserves* one if already present
(`Auth_claims_backfill.php:242-261`) and a test probe (`Transport_probe_f10.php`). It is never
derived from family data.

**Therefore:** after switching to sibling B, collections gated by `isOwnStudent()` — `feeDemands`,
`feeReceipts`, `feeDefaulters`, `studentDiscounts`, `feeReminderLog` — evaluate
`B.studentId == auth.uid (still A)` → **false → PERMISSION_DENIED**.

**Predicted behaviour: the switcher shows sibling B, but B's Fees screen fails.** Classified **NOT
VERIFIED at runtime** (no device/session available) — but it follows directly from the rule text and
the absence of the claim. **This is the single most important thing to verify on a real device.**

## 9. Admin Panel Trace

- **No sibling/family UI exists anywhere** — no screen to view, link, unlink, or correct a
  relationship. Confirmed by search of `application/views/` and `application/controllers/`.
- Student 360's zone is titled *"Student & Family"* (`workspace.php:588-601`) but contains only the
  student's own parent-name fields — no sibling data (`grep sibling` in `student360/` → only a CSS
  comment).
- The only sibling-ish surface is the Fees discount criteria dropdown
  (`fee_management/discounts.php:101`), backed by heuristic #2.
- `Admission_public.php:429-430` stores a self-declared `sibling` boolean + `siblingName` free text
  from the public form — **an unverified parent claim, never linked to a real student record**.

## 10. Admission Path Trace

| Path | Parent data captured | Sibling detection | Explicit link | Auto link | Manual link | Risk |
|---|---|---|---|---|---|---|
| Admission CRM | father/mother name+occupation, guardian phone+relation | **None** | None | None | None | Silent duplicate-family records |
| Direct/walk-in | same + phone, email, address | **None** | None | None | None | Same |
| Bulk new import | same | **Mis-detected as duplicate** | None | None | None | **SIB-B1 data loss** |
| Historical import | same | **Mis-detected as duplicate** | None | None | None | **SIB-B1 data loss** |
| Re-admission | inherited | **None** | `previousStudentId` only (not a family link) | None | None | **SIB-H2 duplicate child** |

Comment at `Admission_public.php:336-346` explicitly acknowledges siblings share a phone and lets
them "pass cleanly" through the duplicate check — for the *public form*. **Bulk import does not.**

### SIB-B1 — verified data loss (BLOCKER)

`Sis::_runStudentImport()` `:4605-4631`. Two siblings, same phone, in one file:

- Row 1 → created; `$seenPhones[phone] = STU0001`
- Row 2 → `isset($seenPhones[$phone10])` **true** → `$existingId = STU0001`
  - `dupPolicy='skip'` (**the default**) → `$duplicates[]` + `continue` → **second child never created**
  - `dupPolicy='update'` → `_updateExistingStudent(STU0001, row2)` → **first child's record is
    overwritten with the second child's data** — one student effectively destroyed

Cross-file is identical via `_existingStudentIdByPhone()` (`:5049-5063`), which reads `indexPhones`.

**`indexPhones` is structurally 1:1 and clobbering:** doc id `{schoolId}_{phone}` holding a single
scalar `userId`, written with `Firestore_service::set()` whose `$merge` defaults to **false**
(`Firestore_service.php:330`) and no caller passes `true` (`Sis.php:904, 4898, 5130, 8343`). The
second sibling's write **replaces** the first. A phone can never map to two students.

## 11. Re-admission Interaction

| Question | Answer | Evidence |
|---|---|---|
| A. Parent relationship after re-admission? | None exists to preserve | §5 |
| B. Does STU0047 appear under the same parent? | Yes — same phone/parents satisfy the heuristic | §4 |
| C. Does STU0001 remain visible? | **Yes** — `findSiblings()` has **no status filter** | `:140-144` |
| D. Both shown? | **Yes** | §3 |
| E. Old record excluded? | **No** | — |
| F. Based on Student ID? | No — phone + names | §4 |
| G. Does `previousStudentId` affect discovery? | **No** — never consulted by the heuristic | §4 |
| H. **Duplicate children in Parent App?** | **YES — SIB-H2** | below |
| I. Parent loses access to re-admitted child? | Likely for Fees (§8) | rules |
| J. Old student appears active? | **Yes**, visually | — |

**SIB-H2 (HIGH):** after re-admission the parent sees **two children** — "Rahul (withdrawn record
STU0001)" and "Rahul (STU0047)" — indistinguishable in the switcher, which shows only
name/class/section/roll (`DashboardViewModel.kt:227-233`). Neither `status` nor `previousStudentId`
is consulted anywhere in the path.

## 12. Multi-Tenant Isolation — **PASS**

`findSiblings()` filters `whereEqualTo("schoolId", schoolCode)` (`:143`) with `schoolCode` from Auth
claims, not client input. **Cross-school sibling contamination is not possible via this path**, even
with an identical phone number in two schools. The three identity indexes are likewise
`{schoolId}_…` prefixed and deny-all.

**But cross-*family*, same-school leakage is possible** — see §19.

## 13. Fees Interaction

- Sibling discount **eligibility** uses heuristic #2 (exact father-name string) —
  `Fee_management.php:893-919`. Two siblings whose records spell the father's name differently
  ("Rajesh Sharma" vs "Rajesh sharma " — note it is **not** normalised, unlike the Parent App) are
  **not** eligible.
- `Fee_lifecycle::calculateSiblingDiscount` is **dead code** with a school-wide bug (§4).
- There is **no family-level fee statement, no combined invoice, no family payment**. All fee
  documents are per-student.
- **A future family model would need Fees to consume it** — but nothing must be built until §27 is
  decided.

## 14. Communication Interaction

Always **per-student fan-out**; no parent or family recipient exists.
`Communication.php:1463-1466` → `push_service->sendToUser($recipientId)` where the uid **is** the
student id. `notification_helper.php:203-234` takes a raw `$phone` + `student_id`.
`Transport_notifier.php:65-67` scopes are `parents_of_students` — still per-student.

**Consequence:** a family with three children receives **three identical** fee reminders /
announcements, to the same phone. No dedup exists anywhere. (Severity: MEDIUM — annoyance and SMS
cost, not a correctness failure.)

## 15. Documents / Student 360 Interaction

No document, certificate, TC, receipt, report card or export references siblings or family. Student
360 shows per-student parent names only (§9). **No change is required by any document today.**

## 16. Professional ERP Research

**Evidence grade:** **[S]** = read from the product's own **schema / source code** (strongest).
**[V]** = read in vendor/standards documentation. **[P]** = search-snippet level (JS-rendered or
403-blocked vendor page that could not be fully fetched). Systems with **no** obtainable
documentation — Alma, FACTS/RenWeb, Sycamore, Teachmint, Classplus, Campus 365, Vidyalaya — are
**excluded rather than guessed at**. Ellucian is excluded as higher-ed (no K-12 guardian model).

Coverage was extended after the first draft with source-level reads of seven open-source products
and a targeted India-market pass; §16.5 and the §17 matrix carry those results, and one claim in the
first draft was **wrong and is corrected there** (see §16.5, MyClassboard).

### The three interchange standards — all converge on the same shape [V]

**None of the three defines a Family entity. None defines a Sibling entity.**

- **Ed-Fi** — `Contact` entity + `StudentContactAssociation` join. Design rule is explicit: *"If
  extending the Contact entity, be careful to keep any information dealing with the relationship on
  the StudentContactAssociation entity record."* Association attributes: `relationDescriptor`,
  `contactPriority`, `primaryContactStatus`, `livesWith`, `emergencyContactStatus`,
  `contactRestrictions`. `Parent` was renamed `Contact` in v5.0 so it could carry non-parent
  emergency/pick-up contacts.
- **OneRoster (1EdTech)** — thinnest of the three: one flat `users` table; parents are users linked
  via the `agents`/`agentSourcedIds` field, which must be maintained **bidirectionally**. Too thin
  to carry custody or billing semantics.
- **SIF (A4L)** — richest: `StudentContactPersonal` + `StudentContactRelationship`, with a
  `ContactFlags` Y/N set including `ParentLegalGuardian`, `HasCustody`, `PickupRights`, `LivesWith`,
  `AccessToRecords`, `EmergencyContact`, **`FeesBilling`**, **`FamilyMail`** — note billing and
  mailing are **flags on the relationship**, not inferred. `HouseholdList` cleanly handles split
  households.

### Two dominant product architectures — mutually exclusive

1. **Association-flag model, no container** — PowerSchool [V], Blackbaud [V]. A person holds N
   independent student associations, each with its own type + permission flags. Family is an
   *emergent property* of shared associations. Flexible for messy custody; no object to bill.
2. **Container model** — Skyward `Family` [V], Infinite Campus `Household` [P], Veracross
   `Household` [P], Gibbon `gibbonFamily` [P], Aeries `STU.FK` [V]. Gives a natural billing/mailing
   unit; requires an explicit split workflow on divorce (Skyward's *"Split from Family/Create New
   Family"*, IC's *"secondary household"*).

### Four findings that directly bear on ZenX's defects

- **Container ≠ authorization — universal.** Infinite Campus is emphatic: *"there are no 'household'
  accounts in the Portal"* — access follows a per-relationship **Guardian** checkbox. Veracross puts
  **Parent Portal Access** on the relationship; Skyward puts **Allow Family Access** on the
  guardian. **The family object is for addressing and billing; a per-guardian, per-student
  permission flag is for access.** This is exactly the distinction ZenX collapses (§8/§19).
- **PowerSchool decouples the portal entirely from the relationship graph:** a parent self-assembles
  their child list using a school-issued per-student **Access ID + password**. **A shared phone
  number grants nothing.** [V]
- **Blackbaud stores sibling as a first-class relationship type — and deliberately denies it
  parental access** (the flag is restricted to parent/guardian/grandparent/step-parent/aunt/uncle;
  sibling is excluded). [V] A sibling link is *not* an authorization edge.
- **Two custody primitives worth stealing:** Blackbaud's **`No contact`** flag (explicit
  no-contact-order), and PowerSchool's **date-effective relationship records** (only the record
  active on a given date counts, so custody changes are historical rather than destructive). [V]

### Answers to the seven questions

1. **Is phone-based inference standard?** **No — with two exceptions, one instructive and one
   cautionary (see §16.5 for MyClassboard, which is the closer analogue to ZenX).** Aeries is the
   only verified product using phone in sibling logic, and only inside its **Sibling Lookup / Mass
   Assign Family Keys** *tool*: it matches on last name, phone, email and address, shows a **0–100
   match score** and a `Contact Match` Y/N column, offers a strictness slider, and **requires the
   admin to click "Match to Selected Family Key" or "Create New Family Key"**. Aeries even publishes
   a support article about its **false positives**. So phone matching is legitimate **only as a
   staging heuristic that populates an explicit stored key** — never the relationship itself, never
   a read-time join, never for authorization. Veracross similarly *materialises* relationships via a
   nightly job — but infers from **existing relationships**, not weak signals. **No product infers
   siblings from a matching phone number at read time.**
2. **Explicit entity universal?** **Yes — unanimously.** 3 standards, 7 products, zero
   counterexamples.
3. **Dominant model?** **(a) is universal; (b) is a common second layer; (c) essentially doesn't
   exist.**
4. **Same phone, unrelated students?** Structurally impossible to conflate — phone is an *attribute
   of a contact record*, not a join key. Defence in depth via PowerSchool's duplicate-contact
   prevention/consolidation and per-student portal credentials.
5. **Real siblings, different numbers?** Trivial — the link never depends on contact data; the admin
   sets the family key/household/shared contact directly.
6. **Standards to model against?** **Ed-Fi and SIF.** OneRoster's `agents` array is too thin.
7. **Is sibling first-class?** Only in Blackbaud — and pointedly without portal rights. Everywhere
   else it is **derived** from co-parentage or co-household membership.

## 16.5 Open-source and India-market evidence *(added after the first draft — schema-level)*

Seven open-source products were read at **schema/source level [S]**, and the Indian market was swept
separately. Three results change or sharpen the conclusions above.

### Correction to §16 answer 1 — ZenX's heuristic is not unprecedented, and that is the bad news

**MyClassboard** (India, K-12, parent app) is a **second confirmed phone-inference product**, and
unlike Aeries it is not a staging tool. Its own KB states: *"MCB identifies the students as sibling
when the mobile number of Father or Mother are the same in student details"*, and the parent app's
**"Add sibling"** flow lists students sharing that mobile and links them **without asking for the
other child's credentials**. [P — `kb.myclassboard.com` returns 403 to fetch; snippet-level only]

This is the closest analogue to ZenX found anywhere, and it is a **security anti-pattern, not a
precedent to lean on**: the phone number *is* the authorization boundary, so one mistyped father's
mobile exposes an unrelated child's record. It is the same failure ZenX has (§19), shipped by a
competitor. It does not validate the design; it shows the blast radius.

Two further India-market patterns, both of which sidestep the data model entirely:
- **Entab CampusCare** [V] — *"Check siblings' information from the same log-in ID and password"*.
  Family = one shared credential.
- **Edunext** [V] — a *"Family login ID"* whose username is the children's admission numbers
  concatenated (`76942.43592`). Family is a **provisioned string**, not a table.

Teachmint, Classplus, Campus 365 and Vidyalaya publish **no** data-model documentation
(two Teachmint feature URLs are outright broken) — **COULD NOT CONFIRM**, excluded.

### Gibbon upgrades from [P] to [S] — and is the strongest reference implementation found

Read from source (`FamilyGateway.php`, `StudentGateway.php`, `family_manage_edit.php`), Gibbon is the
**only** product examined with a genuine family container in code:
`gibbonFamily` → `gibbonFamilyAdult` (`contactPriority`, **`childDataAccess`**, `contactCall/SMS/Email/Mail`)
+ `gibbonFamilyChild` + **`gibbonFamilyRelationship` (`gibbonPersonID1` adult, `gibbonPersonID2` child,
`relationship`)** — i.e. relationship is **per adult-child pair**, which is what step-parent and
mixed-custody cases require. Siblings are computed, never stored:
`(SELECT COUNT(gibbonFamilyID) … GROUP BY gibbonFamilyID) - 1 AS siblings`.

Decisively, **`childDataAccess='Y'` appears in the `WHERE` clause of every parent→students query**
(`selectActiveStudentsByFamilyAdult`, `getStudentByFamilyAdult`). The child switcher and the
authorization gate are **the same predicate**. This is independent confirmation of the §25 delta-2
recommendation, now from source rather than from vendor prose.

### Three more patterns worth copying

- **ERPNext Education [S]** — the only product that stores siblings explicitly (`Student Sibling`
  child table, incl. siblings *outside* the school), **and** the only one that makes the reverse
  index provably safe: `Guardian.students` is **never hand-edited**, it is rebuilt on every save
  (`self.students = []` then re-query `Student Guardian`). If ZenX ever adds a `childrenIds[]`, this
  is the discipline that keeps it from drifting.
- **RosarioSIS [S]** — the most complete link row in the field:
  `students_join_people (student_id, person_id, address_id, custody, emergency, student_relation)`.
  Custody is **per link**, so "has custody of child A but not child B" is representable.
  openSIS Classic [S] puts `custody` on the *person* instead — the same bug in miniature.
  RosarioSIS also uses a shared `address_id` as a de-facto household ("ganged address"), and its own
  code comment says so.
- **SchoolTool [S, discontinued 2016]** — the only product with a literal `'Sibling'` token, and it
  is a **contact-relationship state, not a student↔student edge**. Note the flags:
  `Parent/Step-parent/Foster parent/Guardian` carry `ACTIVE+PARENT`; **`Sibling` carries `ACTIVE`
  only — deliberately no portal access.** Its relationships are **temporal** (dated, state-stamped,
  with `Inactive` distinct from `Added in error`), which is the cleanest custody-change model seen.

### The anti-pattern, stated plainly

**Fedena [S]** — the India-origin reference product — derives the parent login from the child:
`u.username = "p" + student.admission_no`, and reads it back with
`Student.find_by_admission_no(self.username[1..])`. A parent with three children gets **three
separate logins**, and multi-child support is impossible without a data migration. ZenX must not
tie guardian identity to any student-scoped identifier (SRNO, Admission Number, or student ID) —
this is a concrete, shipped example of that mistake's cost.

### Cross-cutting confirmations

- **No open-source SIS stores a student↔student sibling edge.** Sibling is derived from a shared
  guardian (openSIS, Canvas, Moodle, Gibbon) or a shared address (RosarioSIS). ERPNext's
  `Student Sibling` is a per-student *list*, not a symmetric edge.
- **Family-level fees are near-universally absent.** ERPNext `Fees` has no family/guardian/sibling
  field; RosarioSIS `billing_fees` is keyed to `student_id`; Gibbon's `invoiceTo='Family'` only
  *routes* the invoice. Skolaro and MyClassboard advertise sibling discounts with **no published
  sibling model** — almost certainly manual concessions. This directly informs §27-Q6.
- **VSware [V] explicitly refuses to auto-merge on similar details** — *"small discrepancies (e.g.
  'Mary Smith' vs. 'M. Smith') cause duplicates"* — and requires an admin to link. That is the
  opposite of ZenX's `primaryNamesBlank || sNamesBlank` fallback (§4).

## 17. ERP Comparison Matrix

| System | Relationship model | Sibling | Family/Household entity | 1 guardian → N students | Admin-correctable | Portal child list from | Family-level fees |
|---|---|---|---|---|---|---|---|
| **Ed-Fi** [V] | `Contact` + `StudentContactAssociation` | Derived | **No** | Yes | Yes | Association | n/a (standard) |
| **OneRoster** [V] | `users` + `agents` | Derived | **No** | Yes | Yes | `agents` | n/a |
| **SIF** [V] | `StudentContactPersonal` + `StudentContactRelationship` | Derived | Household list on relationship | Yes | Yes | Relationship | `FeesBilling` flag |
| **PowerSchool** [V] | `S_Contacts_S` + `S_Contact_Relationship_C` | **Derived, never stored** | **No** | Yes (one canonical contact, dedup tooling) | Yes | **Per-student Access ID + password** | Not confirmed |
| **Blackbaud** [V] | Person↔person relationship graph | **EXPLICIT** — but denied parental access | **No** | Yes | Yes | `Parental access` flag | `Responsible signer` flag |
| **Skyward** [V] | **`Family`** container | Derived from shared Family | **Yes** | Yes | Yes — *Split from Family* | `Allow Family Access` | Not confirmed |
| **Aeries** [V] | Per-student `CON` contacts **+ stored `STU.FK` family key** | **Explicit stored key**, populated by scored suggestion tool | Family Key = container | Yes | Yes (manual `STU.FK`) | `CON.AP` | `STU.FK` drives "1 Per Family" |
| **Infinite Campus** [P] | `Household` + separate `Relationships` | Derived from household | **Yes** (multi-household) | Yes | Yes | **Guardian checkbox**, not household | Per-person ("People Fees") |
| **Veracross** [P] | `Household` + person relationships | Explicit records, **generated nightly by inference** | **Yes** | Yes | Yes | `Parent Portal Access` on relationship | **Yes — Household Account invoices** |
| **Gibbon** [S] | `gibbonFamily` + `gibbonFamilyAdult`/`Child` + **`gibbonFamilyRelationship`** (per adult-child pair) | Derived (`COUNT(child)−1`) | **Yes** | Yes | Yes — Manage Families | **`childDataAccess='Y'`** — same predicate as the auth gate | Routing only (`invoiceTo='Family'`) |
| **ERPNext Education** [S] | `Guardian` + `Student Guardian` (`relation`) | **EXPLICIT** `Student Sibling` (one-way list, incl. out-of-school) | **No** | Yes | Yes | **No guardian portal at all** | **No** — `Fees` has no family field |
| **RosarioSIS** [S] | `students_join_people` (`custody`, `emergency`, `student_relation`, `address_id`) | Derived — shared `person_id` **or** `address_id` | De-facto: shared `address` row | Yes (`students_join_users`) | Yes — admin associates | `<select name="student_id">` | **No** — `billing_fees.student_id` |
| **openSIS Classic** [S] | `students_join_people` (`relationship`) | Derived — shared `person_id` | **No** (address is per-student) | Yes | Yes — `ParentLookup.php` | `<SELECT name=student_id>` | No fee module in CE |
| **SchoolTool** [S, dead 2016] | Temporal `ContactRelationship` w/ dated states | **EXPLICIT `'Sibling'` state — and denied portal access** | **No** | Yes | Yes | `ChildrenOverview` via `ACTIVE+PARENT` | n/a |
| **Canvas LMS** [S] | `user_observers` + per-course `ObserverEnrollment` | Derived — shared `observer_id` | **No** | Yes (auto course fan-out) | Yes — idempotent, un-deletes | `observed_students` picker | n/a |
| **Fedena** [S] | `guardians.ward_id` → **exactly one** student | **Not modelled at all** | **No** | **NO — one login per child** (`"p"+admission_no`) | Partial | **None possible** | Per student; discount by batch/category |
| **MyClassboard** [P] | **Shared father/mother mobile** | **Inferred from phone**, linked in-app **with no credential check** | **No** | Yes | Edit the phone number | Phone match | "Sibling concession" advertised, no model |
| **Entab CampusCare** [V] | Shared login credential | Provisioned into one login | **No** | Yes | Not confirmed | Tap ward name | Not confirmed |
| **Edunext** [V] | **"Family ID"** = concatenated admission numbers | Provisioned into the username string | **No** (a username, not a table) | Yes | School-provisioned | "Switch Ward" | Not confirmed |
| **ZenX (today)** | **None — runtime heuristic** | **Inferred from phone + name strings at read time** | **No** | **No parent entity at all** | **No UI** | **Full-collection scan** | Heuristic #2 only |

**ZenX and MyClassboard are the only two rows where the relationship is computed from a phone number
with no stored link — and ZenX is the only row in the entire table with no admin correction path of
any kind.** Every other product, including both credential-as-family Indian products, has *some*
authoritative place a school can go to fix a wrong sibling grouping. ZenX has none.

Also note what the extended table does **not** contain: **no product in it stores a student↔student
sibling edge**, and **no product in it has family-level fee calculation** — the two things most
likely to be assumed "obviously needed" (§27-Q6).

## 18. False Positive / False Negative Analysis

| # | Case | ZenX today | Correct behaviour |
|---|---|---|---|
| 1 | Two brothers share father's mobile | Linked ✔ (if names match/blank) | Linked, via shared guardian |
| 2 | **Unrelated students, shared guardian phone** | **FALSE POSITIVE if either record has blank parent names** | Not linked |
| 3 | Real siblings, different numbers | **FALSE NEGATIVE — never linked** (phone is a hard prerequisite `:189`) | Linked |
| 4 | Father's number changes | **Relationship silently breaks** on one record | Unaffected — link is to the contact |
| 5 | Mother and father separate accounts | Only one is a login (uid == student) | Both guardians linked, both see children |
| 6 | Deceased/absent/separated parent | No model | Per-guardian link + custody flags |
| 7 | Step / blended family | **Wrongly links step-siblings** if phone+one name matches | Explicit per-guardian links |
| 8 | Guardian of unrelated children | **FALSE POSITIVE** (the prod incident, §4) | Linked to guardian, *not* to each other as siblings |
| 9 | Same surname, unrelated | Safe (surname unused) | Safe |
| 10 | Different surnames, real siblings | Works if phone+parent name matches | Works |
| 11 | Twins | Linked (same phone+parents) — but **SIB-B1 destroys one at import** | Both retained |
| 12 | **Re-admission** | **Duplicate child (SIB-H2)** | One child, one history |
| 13 | Transfer to another ZenX school | Not linked (school-scoped) | Business decision (§27-Q5) |
| 14 | Children in different ZenX schools | Not linked | Business decision (§27-Q5) |

**Net: both failure directions are live.** False positives (2, 7, 8) leak data; false negatives (3, 4)
hide real siblings.

## 19. Security Analysis

**Q: "Could Parent A ever see Student B merely because a phone number happens to match?"**
**A: Yes — and the exact path is:**

1. `findSiblings()` matches B into A's child list (blank-names branch, §4).
2. The switcher displays B's **name, class, section, roll number** (`DashboardViewModel.kt:227-233`)
   — already a disclosure to an unrelated adult.
3. `switchToSibling(B)` fetches B's **full student document** and writes it into DataStore
   (`:248-318`) — exposing DOB, gender, parents' names, phone, address, photo, admission date.

**Step 3 is permitted by the rules**, because:
```
match /students/{docId} { allow read: if isSameSchool(); }
```
**`isOwnStudent()` exists but is NOT applied to `students`.** Any authenticated user in a school —
any parent, any student — may read **every student record in that school** via the SDK, independent
of the app UI. **SIB-H1, HIGH.** This is pre-existing and not introduced by any recent phase, but it
is what turns a UI heuristic bug into a data-disclosure path.

**Mitigating factor:** the *downstream* collections (`feeDemands`, `feeReceipts`, `feeDefaulters`,
`studentDiscounts`) **are** gated by `isOwnStudent()`, so fees/financial data of a false-positive
sibling should be denied. That same gate is what likely breaks legitimate sibling switching (§8) —
**the security control and the feature bug are the same line of code.**

**Withdrawn / deleted students:** no status filter (§11) → withdrawn students remain visible.
Hard-deleted students disappear (document gone).

## 20. Performance / Latency Analysis

**Current implementation — the worst pattern in the codebase for latency:**

| Metric | Value |
|---|---|
| Reads per call | **N = every student in the school** (no `limit`, no predicate beyond `schoolId`) |
| Calls per session | ≥1 dashboard + up to 2 transport screens |
| 500-student school | ~500 doc reads per dashboard load |
| 2,000-student school | ~2,000 doc reads per dashboard load |
| Filtering | **client-side, in Kotlin** — the network cost is paid in full before filtering |
| Index required | none (single equality) — which is exactly why it scales badly |

Given ZenX's documented elevated Firestore latency, this is a **first-order performance defect**
(SIB-M1, MEDIUM–HIGH) independent of correctness. Every proposed architecture in §23 improves it,
because any explicit relationship reduces this to a **1–2 document read**.

## 21. Current Defects

| ID | Sev | Defect |
|---|---|---|
| **SIB-B1** | **BLOCKER** | Bulk import silently drops the 2nd sibling (skip) or overwrites the 1st with the 2nd (update). §10 |
| **SIB-H1** | **HIGH** | `students` readable school-wide by any authenticated user; `isOwnStudent()` not applied. §19 |
| **SIB-H2** | **HIGH** | Re-admitted student appears twice in the Parent App (no status/`previousStudentId` filter). §11 |
| **SIB-M1** | MED-HIGH | Full-collection student scan per dashboard load. §20 |
| **SIB-M2** | MEDIUM | Sibling switching likely PERMISSION_DENIED on Fees (no `student_ids` claim; `isOwnStudent()` single-uid). **NOT VERIFIED at runtime.** §8 |
| **SIB-M3** | MEDIUM | Three contradictory sibling definitions; one dead landmine that would match the whole school. §4 |
| **SIB-L1** | LOW | `parents` collection is per-student, `childrenIds` hardcoded to self, write-only, deny-all — dead data contradicting its own docblock. §5 |
| **SIB-L2** | LOW | Duplicate notifications per family. §14 |

## 22. Missing Capabilities

Guardian/contact entity · family grouping · explicit student↔guardian association · relationship
types (father/mother/guardian/custody) · multiple guardians per student · admin UI to link/unlink/
correct · sibling detection at admission · family-level fees · family-level communication dedup ·
multi-guardian login.

## 23–24. Architecture Options

| | Model | Advantages | Disadvantages | Reads to list children | Fixes |
|---|---|---|---|---|---|
| **A** | Pure automatic inference (status quo, hardened) | No migration; no new UI | Cannot fix false positives/negatives structurally; keeps full-collection scan; contradicts every standard | N | none of B1/H2 |
| **B** | Suggestion + human confirmation | Safe; admin controls truth; incremental | Needs UI + admin effort; still needs a store for the confirmed link | 1 | B1, H2 |
| **C** | **Explicit Guardian/Contact → Students association** | **Industry standard** (Ed-Fi/OneRoster/SIF); handles multi-guardian, custody, differing numbers; sibling *derived*; enables family fees + comms dedup; multi-guardian login | Largest change; needs migration + admin UI + app changes | 1–2 | **all** |
| **D** | Explicit Family ID on each student | Simple; one field; cheap lookup | Cannot express multi-guardian, custody, or a child in two households; a blunt grouping | 1 | B1, H2 |
| **E** | **Hybrid: C's data model + B's confirmation workflow** | Standards-correct **and** safe to roll out; heuristic becomes a *suggestion engine*, never truth | Most design work up front | 1–2 | **all** |

## 25. Recommended Architecture

**Option E — the Ed-Fi/SIF-shaped Guardian→Student association (C), populated through a
suggest-then-confirm workflow (B).**

Rationale: it is the only option matching the industry standard; it fixes every defect structurally
rather than patching a heuristic; it reduces the child list from N reads to 1–2; and it can roll out
without a big-bang migration — the existing phone/name matcher is **demoted from "truth" to
"suggestion"**, which is precisely what Aeries does with its scored Sibling Lookup tool (§16-Q1).

**Strong corroboration — ZenX already designed this.** `ZENX_ADMISSION_DOMAIN_SPEC.md:160-172`
independently specifies almost exactly this model, and its own rationale names the same root cause
this audit found from source:

> *"the current `parents/{schoolId}_{studentId}` with `childrenIds=[studentId]` **cannot express
> siblings** and mints duplicate accounts. The fix is a **relationship layer**, not an auth change."*

Its design: canonical parent keyed by normalised phone (`parents/{schoolId}_{phoneHash}`) with true
multi `childrenIds[]`, a `contacts[]` array (father/mother/guardian/emergency, each
`{relation, name, phone, email, isPrimary}`), `primaryContactStudentId` to preserve the frozen
**UID==studentId** auth model, `students.parentKey` back-reference, **`indexPhones` changed from
overwrite to append** (which is the direct fix for SIB-B1), and a staged idempotent backfill.

Two deltas I would flag against that spec, from the industry evidence:
- **Keying the parent by phone hash makes the phone number the identity.** Aeries/PowerSchool keep
  phone as a *matchable attribute* of a contact, not its key — so a parent changing their number
  doesn't fracture the family (§18 case 4). Worth revisiting at design time.
- The spec derives portal visibility from `childrenIds[]`. Every container product surveyed
  separates these: **container for billing/mailing, per-relationship flag for access** (§16). ZenX
  should carry an explicit per-child access flag rather than treating family membership as
  authorization — this is the same conflation behind SIB-H1/SIB-M2.

**Verified spec-only: none of it is implemented** (checked for `phoneHash`/`primaryContactStudentId`/
`contacts[]` in `application/` — the only `phoneHash` hits belong to an unrelated
`crmDupChecks` duplicate-submission guard).

**Presented as a recommendation only. Not selected on your behalf — §27 must be answered first.**

## 26. What Should NOT Be Implemented

- ❌ Do **not** "fix" the heuristic by tightening the match — it cannot become correct.
- ❌ Do **not** auto-create family links from existing data without human confirmation — the prod
  incident (§4) shows what that produces.
- ❌ Do **not** add sibling concessions to Fees until the relationship exists.
- ❌ Do **not** wire up `Fee_lifecycle::calculateSiblingDiscount` — it would discount the entire
  school (§4).
- ❌ Do **not** widen Firestore rules to make the current scan "work" — narrow them instead.
- ❌ Do **not** redesign the Parent App UI in the same pass as the data model.

## 27. Required Business Decisions

- **Q1** Adopt Option E (recommended), C, D, B, or keep A?
- **Q2** **SIB-B1 is destroying data today.** Fix immediately as a standalone hotfix (make bulk
  import treat a shared phone as *possible sibling* rather than *duplicate*), or wait for the full
  model? *(Recommend: immediate hotfix.)*
- **Q3** **SIB-H1**: restrict `students` read to staff-or-own-student? This is correct but **will
  break the current sibling scan** — so it must be sequenced with Q1.
- **Q4** Should a guardian get their **own login** (a real parent principal), or continue logging in
  as a child? This is the deepest question — it determines whether `student_ids` claims are needed
  and whether SIB-M2 is fixed properly.
- **Q5** Should families span schools (one guardian, children in two ZenX schools)?
- **Q6** Is a sibling discount actually a business requirement? It shapes whether Fees needs
  family-level aggregation. *Evidence for the decision (§16.5): **no** product examined —
  open-source or commercial — has family-level fee calculation. ERPNext's `Fees` has no family
  field, RosarioSIS bills per `student_id`, Gibbon only **routes** the invoice to a family, and the
  Indian vendors that advertise "sibling concession" publish no sibling model at all, implying a
  manual concession. If ZenX wants this, it is building ahead of the field, and it needs the family
  entity first.*

## 28. Implementation Roadmap *(for a future, separately-authorised phase)*

Sequenced so each step is independently safe and reversible. Steps 3-6 should start from
`ZENX_ADMISSION_DOMAIN_SPEC.md:160-172` rather than a blank page (§25), with the two deltas noted
there.

1. **Hotfix SIB-B1** — stop destroying siblings at import. Smallest and most urgent; the spec's
   *"`indexPhones` becomes append, not overwrite"* is the same fix. Can ship alone.
2. **Hotfix SIB-H2** — filter withdrawn/inactive out of the child list (one predicate).
3. Introduce the guardian/contact entity + association (write-path only, no behaviour change).
4. Backfill *suggestions* from existing data — **admin-confirmed, never auto-applied** (the Aeries
   pattern, §16-Q1).
5. Admin UI: view / link / unlink / correct — ZenX has **no such screen today**.
6. Repoint the Parent App child list at the association (N reads → 1) **and add an explicit
   per-child access flag** rather than deriving access from family membership (§25 delta 2).
7. Then tighten SIB-H1 rules — safe only once step 6 lands, since narrowing `students` reads breaks
   the current scan.
8. Fees / Communication family features — last, and only if §27-Q6 says a sibling discount is a real
   requirement.

## 29. Test Plan (for that future phase)

Cases 1–14 of §18; multi-tenant isolation; re-admission producing exactly one child; import of a
2-sibling family creating 2 students; permission checks after switching; read-count regression
(assert child list ≤ 2 reads); withdrawn/graduated exclusion; blank-parent-name records; twins.

## 30. Final Verdict

# BLOCKED

Not blocked on the *display* work from Phase 11 — that remains safe and free. **Blocked on treating
"siblings" as a working feature**, because:

1. **SIB-B1 is actively losing data** in production bulk imports today.
2. **There is no relationship model to build on** — sibling is a runtime guess, contradicted by
   three different definitions.
3. **SIB-H1** means the heuristic is not a security boundary, and the one control that *is*
   (`isOwnStudent()`) probably breaks the feature itself (SIB-M2).

Nothing here can be resolved by a code tweak; it needs the architecture decision in §27-Q1.

---

# PLAIN LANGUAGE EXPLANATION

**1. How does ZenX currently know two students are siblings?**
It guesses. When a parent opens the app, it downloads *every student in the school* and keeps the
ones with the same phone number **and** a matching father or mother name. If either record has blank
parent names, the phone number alone is enough.

**2. Is that reliable?** No. It fails in both directions, and it has already failed in production —
there's a comment in the code naming two real unrelated students who were wrongly shown as siblings
because they shared a phone.

**3. Is there a real family or parent record?** No. ZenX has never stored "these two children belong
to the same parent". There's a `parents` collection, but it stores **one row per child**, its
"children list" field always contains just that one child, nothing ever reads it, and the security
rules block it entirely. It's dead data whose own documentation describes something the code doesn't
do.

**4. Can unrelated students accidentally become siblings?** **Yes** — if either record is missing
parent names, sharing a phone is enough. And because any logged-in user can read every student
record in the school, the app is the only thing preventing a parent from seeing another family's
child.

**5. Can real siblings fail to be recognised?** **Yes** — a phone match is mandatory, so siblings
listed under mum's number and dad's number are never connected. If a parent changes their phone
number, the family silently splits.

**6. What do professional systems do?** They store an explicit link between a **guardian** and each
of their children, with a relationship type (mother/father/guardian). "Sibling" is then simply
*"these two children share a guardian"*. Every major standard — Ed-Fi, OneRoster, SIF — works this
way, and so does every serious product I could verify — including every open-source school system I
read the actual database schema of. Not one of them stores "student A is the sibling of student B";
they all work it out from the shared parent.

*One correction to what I said earlier:* I first wrote that **nobody** uses phone matching. That was
too strong. **Aeries does** — but only as an admin tool that shows a **match score out of 100** and
makes a human click "link", after which the link is **stored permanently**. It never matches live,
never on the fly, and never to decide who can see what. Aeries even publishes a help article about
that tool producing wrong matches. So the real rule is narrower and more useful: *phone matching is
fine for **suggesting** a link a human confirms; it is never the link itself.* That is exactly the
approach I'm recommending — and it's what ZenX does wrong today, because it matches live and treats
the guess as truth.

*A second, harder correction, from later research:* there **is** one product that does what ZenX
does — **MyClassboard**, an Indian school ERP. Its documentation says outright that it treats
students as siblings when the father's or mother's mobile number matches, and its parent app lets
you tap **"Add sibling"** and link another child **without entering that child's password**. So
ZenX's approach is not unheard of in this market. But that is a warning, not a defence: it means a
single mistyped phone number hands a stranger another family's child, in a shipped product, with no
credential standing between them. It is the same hole ZenX has. Meanwhile **VSware** (Ireland) does
the opposite on purpose — it refuses to auto-merge families on similar-looking details, because
"Mary Smith" vs "M. Smith" creates duplicates, and makes an admin link them by hand.

There is also a clear example of the mistake *not* to make. **Fedena** — another India-origin school
ERP — built the parent's login out of the child's admission number (`p` + admission number). A
parent with three children therefore gets three separate logins, and the product cannot support a
child switcher at all without rebuilding its database. The lesson for ZenX: never tie a parent's
identity to SRNO, Admission Number, or a student ID.

And the closest thing to a model answer: **Gibbon**, an open-source school system, stores a real
family with a per-parent, per-child permission flag — and that same flag is what its code checks
*every single time* it decides which children a parent may see. The list you see and the list you're
allowed to see are literally the same query. That is the separation ZenX is missing.

Two other things worth knowing. **PowerSchool** doesn't use family data for the parent app at all —
the school hands the parent a separate password per child, so a shared phone number gets you
nothing. And **Blackbaud** is the one system that does store "sibling" as a real link — but it
deliberately **refuses** to let a sibling link grant portal access. Across every system: *family is
for billing and letters; a separate per-child permission decides who sees what.* ZenX currently
merges those two ideas, which is the root of both the security hole and the broken fee screen.

**7. What should ZenX do?** Create a real guardian record linked to each child, and use the existing
phone/name matching only to *suggest* links that a school admin confirms.

**Good news: you already designed this.** `ZENX_ADMISSION_DOMAIN_SPEC.md` (lines 160-172) contains
almost exactly this plan — a proper parent record holding a real list of children, a contacts list
for mother/father/guardian, and a fix changing `indexPhones` from "overwrite" to "append", which is
precisely the bug destroying siblings at import. It was written, never built. It also correctly
notes the fix needs **no change to how anyone logs in**. So this isn't a from-scratch design — it's
finishing something already specified, and it lines up with the industry pattern.

**8. Parent App experience?** Largely the same switcher — but showing the *right* children, without
withdrawn or re-admitted duplicates, and loading from 1 read instead of hundreds.

**9. Admin Panel experience?** A "Family / Guardians" section on the student profile: see the
guardian, see their other children, and *"Possible sibling found: Rahul Sharma, Class 5-A — [Link]"*.
Today there is **no screen anywhere** to see or fix this.

**10. Fees?** Once a real relationship exists, sibling discounts and one combined family bill become
straightforward. Today the discount uses an exact father-name string match — different spelling or a
stray space and the family misses out.

**11. Re-admission?** Right now a re-admitted child shows up **twice** — the old withdrawn record and
the new one — because nothing filters by status. With a guardian link, it's one child with a
history.

**12. Extra latency?** **Negative — it gets faster.** Today's child list costs one read per student
in the whole school (2,000 students = 2,000 reads, every dashboard load). A proper link costs 1–2
reads.

**13. What must you decide?** The six questions in §27 — most urgently **Q2**: bulk import is
silently deleting the second child of every family that shares a phone number. That one is worth
fixing on its own, immediately, regardless of which architecture you choose.

---

**Nothing was implemented, modified, staged, committed, pushed, or deployed. Awaiting your decision
on §27 before any implementation plan is produced.**