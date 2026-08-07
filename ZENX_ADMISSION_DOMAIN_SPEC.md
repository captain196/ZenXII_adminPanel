# ZenX ERP — Admission Domain Specification

## Official Implementation Blueprint (Business-Domain & Architecture, Read-Only)

**Project:** ZenX ERP · **Branch:** ank-yug_b1 · **Mode:** architecture/domain design — no code, no collections created, no modifications.
**Basis:** approved Discovery Audit + Admission Audit + Document-Engine Audit + master-data inventory + dependency map + ZenoxERP benchmark (reference only).
**Design invariants:** reuse existing modules · Firestore-only · backward compatible · never duplicate functionality · **the global `STU`/`STA` counter and student-first Firebase Auth (UID == studentId) are load-bearing and NOT re-architected** (per `identity_architecture_decision`). All identity work below is **additive**.

---

## SECTION 1 — DOMAIN ENTITIES

Legend — Firestore ownership: **[reuse]** existing collection · **[extend]** add fields to existing · **[new]** additive collection (only where no home exists).

| Entity | Purpose | Owner module | Firestore | Lifecycle | Key relationships | Core validation |
|---|---|---|---|---|---|---|
| **Enquiry** | First interest capture (walk-in/call/online) | CRM | `crmInquiries` **[reuse]** | created→converted/closed | →Application; →Counsellor; →LeadSource | phone required; session-scoped |
| **CRM Lead** | Enquiry surfaced in pipeline (a view of an application, not a new record) | CRM | `crmApplications` (source=lead) **[reuse]** | new→…→enrolled/rejected | ↔Application; →LeadStage | — |
| **Applicant / Application (=Registration)** | The formal registration record for a child under consideration | CRM/Admission | `crmApplications` **[reuse/extend]** | draft→submitted→docs→approved→enrolled / rejected / cancelled | →Student(on confirm); →Documents; →FeePackage; →Counsellor | name+DOB; class; phone; session |
| **Admission** | The confirmation event that turns an approved application into a student | Admission | (event; recorded on `crmApplications` + `studentHistory`) **[reuse]** | one-shot, idempotent | →Student; →Fees; →Receipt | requires status=approved (or direct-mode) |
| **Student** | Canonical enrolled child (SIS record of truth) | SIS | `students` **[reuse]** | Active→(TC/withdrawn/promoted) | ←Parent; →Class/Section; →Fees; →Documents | STU id; class/section normalized; status |
| **Parent** | Canonical guardian identity that owns the Parent-app account and links siblings | SIS/Identity | `parents` **[extend]** (key → normalized phone) | created→linked children | →Students (childrenIds[]); →Communication | phone normalized; ≥1 child |
| **Guardian / Emergency Contact** | Non-primary contacts (mother/guardian/emergency) | SIS | on `students`/`parents` sub-map **[extend]** | with student | ←Student | relation; phone |
| **Sibling link** | Derived relationship (same parent) enabling sibling discount/switcher | SIS/Identity | derived from `parents.childrenIds[]` **[extend]** | maintained on admit/withdraw | Student↔Student via Parent | — |
| **Class · Section** | Academic placement + capacity | Master/SIS | `sections` **[reuse]** | per session | ←Student; →Timetable | maxStrength; session |
| **Academic Session** | Year context `YYYY-YY` | Master | `schools.currentSession` + `sessions[]` **[reuse]** | annual | scopes everything | must exist (fail-closed) |
| **Fee Package** | Named bundle of fee heads + installment template, assignable to classes | Fees | `feePackages` **[new]** (references `feeStructures`) | per session | →Classes (M:N); →Concessions | ≥1 head; class map |
| **Installment Plan** | The scheduled dues derived from a package for a student | Fees | `feeDemands` **[reuse]** | generated on admit | ←Package; ←Student | proration by admit date |
| **Transport Request** | Opt-in route/stop chosen at/after admission | Transport | `studentRoutes` **[reuse]** | optional | ←Student; →Route/Stop; →Fees | route+stop; monthly fee |
| **Document Checklist** | Required-doc set per admission-type + per-doc status | Admission/Documents | on `crmApplications`/`students.Doc` **[extend]** + `documentTypes` **[new master]** | Required→Received→Verified/Rejected | ←Application/Student | type; MIME; size |
| **Admission Batch** | Optional grouping/cohort (coaching-style) — **K-12: map to Section** | Master | (map to `sections`) — **not a new entity** | — | — | — |
| **Admission Type** | Classification (New / Transfer / Re-admission / RTE) | Master | `masters/admissionType` **[new master]** | config | →Application | code; label |
| **Admission Status** | Configurable application lifecycle labels | Master/CRM | `crmSettings.stages` **[reuse/extend]** | config | →Application | required New/Approved/Rejected |
| **Lead Source** | Where the enquiry came from | Master | `masters/leadSource` **[new master]** (currently hardcoded) | config | →Enquiry | code |
| **Lead Stage** | Pipeline stage | CRM | `crmSettings.stages` **[reuse]** | config | →Lead | — |
| **Counsellor** | Staff member owning a lead (attribution) | HR/CRM | reference to `staff` **[reuse]** | assignment | ←Lead | staffId |
| **Document Requirements** | Which docs a given admission-type/class demands | Master | `documentTypes` + per-type requirement flags **[new master]** | config | →Checklist | — |
| **Communication event** | Admission-lifecycle notifications | Communication | `messageQueue`/`pushRequests` + canonical events **[reuse]** | per trigger | ←stages | template |
| **Credentials** | Parent/student login issuance | Auth | Firebase Auth + `students` **[reuse]** | on confirm | ←Student/Parent | UID==studentId (unchanged) |

**Entities we deliberately do NOT create:** Admission Batch as a first-class entity (map to Section), a separate Registration entity (Application *is* the registration), a separate Parent-ID numbering entity (parent keyed by phone), and any coaching batch/course-package entity.

---

## SECTION 2 — ENTITY RELATIONSHIP DIAGRAM (with cardinality)

```
                         AcademicSession (1) ───scopes──▶ everything
LeadSource (1)─┐
Counsellor (1)─┤
               ▼
         Enquiry (M)──1:1─▶ Application/Registration (M) ──M:1──▶ AdmissionType
                                   │  │  │
                 (0..1 per child)  │  │  └──M:N──▶ FeePackage ──M:N──▶ Class
                                   │  │                 │
                                   │  │                 └──1:M──▶ InstallmentPlan(feeDemands)
                                   │  └──1:M──▶ DocumentChecklist ──M:1──▶ DocumentType
                                   ▼
                        CONFIRM ADMISSION (idempotent event)
                                   │ 1:1
                                   ▼
   Parent (1) ──1:M(childrenIds[])──▶ Student (M) ──M:1──▶ Section ──M:1──▶ Class
      │                                  │  │  │  │
      │ owns 1:1                         │  │  │  └──1:M──▶ feeDemands ──1:M──▶ feeReceipts ──1:1──▶ AccountingJournal
      ▼                                  │  │  └──0:M──▶ Documents(Storage) 
   ParentApp account                     │  └──0:1──▶ TransportRequest ──M:1──▶ Route/Stop
   (login UID==studentId,                │
    sibling switcher via Parent)         ├──appears in──▶ TeacherRoster (by class/section/status)
                                         ├──▶ Attendance eligibility
                                         ├──▶ Examination eligibility
                                         └──▶ Communication (SMS/push/inbox)
```

**Cardinality highlights:** Parent 1↔M Students (siblings); Application 1↔1 Student (on confirm); FeePackage M↔N Class; Package 1↔M Installments; Student 1↔M feeDemands 1↔M receipts 1↔1 journal; Student 0↔1 TransportRequest.

---

## SECTION 3 — COMPLETE ADMISSION LIFECYCLE

Per stage: **[M]** mandatory · **[O]** optional · **[C]** school-configurable.

| # | Stage | M/O | Who | Business rules | Depends on | Outputs | Rollback |
|---|---|---|---|---|---|---|---|
| 1 | Enquiry | [O/C] | Reception/Counsellor | phone required; dup-scan by phone | LeadSource | `crmInquiries` INQ# | delete draft |
| 2 | Counselling / Followup | [O] | Counsellor | dated interaction log | Enquiry | followup entries | — |
| 3 | Campus Visit | [O] | Reception | schedule/attend flag | Enquiry | visit record | — |
| 4 | Registration / Application | [M] | Reception/Officer | name+DOB+class+phone; **dup-scan (name+DOB+phone)**; session set | Session, Class master | `crmApplications` APP# | cancel app |
| 5 | Document Verification | [O/C] | Officer | checklist per admission-type; per-doc status | DocumentTypes master | checklist statuses | re-request |
| 6 | Seat Availability | [M] | System/Officer | capacity check vs `sections`; block/warn if full | sections capacity | seat decision | release |
| 7 | Fee Package Selection | [O/C] | Officer/Accounts | package↔class valid; concessions applied | feePackages | package on app | change pkg |
| 8 | Installment Plan | [O/C] | Accounts | derived from package; proration by admit date | Fees engine | plan preview | recompute |
| 9 | Admission Approval | [C] | **Principal/Officer** (separation of duties) | direct-mode auto-approves; approval-mode gated | status machine | status=approved | reject/reopen |
| 10 | Fee Collection (registration/initial) | [M] | Accounts | server-resolved amount; **canonical receipt + JE_FEE_{key}** | Admission_collection, Accounting | ADM receipt | refund/void (P) |
| 11 | **CONFIRM ADMISSION** | [M] | Officer | **idempotent claim**; capacity; dedup; parent-link; proration | all above | see §11 flow | idempotent no-op on retry |
| 12 | Student Creation | [M] | System | STU# (atomic); fail-loud; normalized class/section | Id_generator, students | `students` doc | delete_student (guarded) |
| 13 | Parent Creation / Sibling Linking | [M] | System | resolve parent by phone; attach child or create | parents identity | `parents` + childrenIds[] | detach child |
| 14 | Accounting | [M] | System | tuition demands + registration journal (idempotent) | Fees, Accounting | feeDemands, journal | reverse (period-lock aware) |
| 15 | Communication | [O/C] | System | canonical event + SMS (submitted/paid/confirmed/invite) | Communication | messages | — |
| 16 | ID Card | [O] | Officer/Parent | STU + signed QR | qr_token_helper, Pdf | ID card PDF | reissue |
| 17 | Parent Login | [M] | System | Auth UID==studentId (unchanged); parent app sees child(ren) | Firebase Auth | credentials (SMS all paths) | disable |
| 18 | Teacher Visibility | [M] | System | roster by class/section/status=Active | Entity sync | roster entry | resync |
| 19 | Transport | [O] | Transport Mgr/Parent | route+stop opt-in; transport fee | Transport, Fees | studentRoutes | unassign |
| 20 | Library | [not a stage] | — | eligibility by student existence | — | — | — |
| 21 | Document Center | [O] | System | final admission form/confirmation (Document Service) | Document Service | issued PDFs | supersede |
| 22 | Completion | [M] | System | mark application enrolled; audit | audit | studentHistory | — |

---

## SECTION 4 — MASTER DATA DESIGN

| Master | Decision | Design |
|---|---|---|
| Admission Type | **NEW** | `masters/admissionType` {code,label,requiresApproval?,docRequirements[]}. New/Transfer/Re-admission/RTE. |
| Admission Status | **REUSE/EXTEND** | keep `crmSettings.stages`; standardize required New/Approved/Rejected/Enrolled/Cancelled; retire the divergent `update_lead_status` allowlist. |
| Lead Source | **NEW master** (from hardcoded) | `masters/leadSource`. |
| Lead Stage | **REUSE** | `crmSettings.stages` (already configurable). |
| Fee Package | **NEW** | `feePackages` referencing `feeStructures.feeHeads`; class map; installment template. |
| Fee Group | **DO NOT ADD (yet)** | no production need; heads suffice. Revisit only if reporting demands grouping. |
| Mother Tongue | **NEW master** (optional field) | `masters/motherTongue`; optional on form. |
| Religion | **EXTEND → shared master** | `masters/religion`; **fixes cross-surface drift** (admin dropdown vs public/CRM free-text). |
| Category | **EXTEND → shared master** | `masters/category` (General/OBC/SC/ST/EWS + config); one source for all 3 forms. |
| Caste / Sub-Caste | **NEW (optional, gated)** | only if a school enables reserved-quota; store minimally. |
| House | **NEW (optional)** | `masters/house`; K-12 optional. |
| Affiliation | **REUSE** | School_config Board tab; consolidate the two current places. |
| Document Types | **NEW master** | `documentTypes` {code,label,required?,allowedMime[],maxSize}; replaces hardcoded slots. |
| Transport Requirement | **REUSE** | Transport routes/stops; add opt-in flag at admission. |
| Reference Scheme | **DO NOT ADD** | franchise/referral-reward complexity not needed. |
| Counsellor | **REUSE** | reference `staff`; no new master. |
| Admission Batch | **DO NOT ADD** | map to Section. |

**Principle:** every drifting/hardcoded admission dropdown becomes a **single shared master** consumed by all three surfaces; no master is deleted.

---

## SECTION 5 — NUMBERING STRATEGY

**Reuse `Id_generator` (atomic claim+pointer) and `nextSchoolCounter` (CAS). No second numbering engine.**

| Number | Series | Scope | When | Reset | Cancellation | Note |
|---|---|---|---|---|---|---|
| Inquiry No | `INQ#####` | per-school | at enquiry | session (C) | retained | reuse nextSchoolCounter('crm_Inquiry') |
| Registration/Application No | `APP#####` | per-school | at application | session (C) | **retained** on reject/cancel | **fix public random `APP_{hex}_{rand5}` → atomic** so both surfaces share one series |
| Admission No = **Student ID** | `STU####` | **global (unchanged)** | at confirm only | **never** | number consumed only on real create | load-bearing; do not change |
| Invoice No | reuse Receipt or `INV` per-school | per-school | at fee collection | session (C) | void marks status | prefer reusing ADM receipt; add INV only if finance requires separate |
| Receipt No | `ADM######` (admission) / `F{n}` (fees) | per-school | at payment | none | void (P) | existing, atomic, gap-free |
| Student ID | `STU####` | global | at confirm | never | — | = Admission No |
| Parent ID | **none — keyed by normalized phone** | tenant | at first child | — | — | no numeric parent series |
| Roll Number | school-defined per class-section | per section+session | at/after admit | session | — | not an ID; optional |
| ID Card No | derive from STU (or `IDC` per-school) | per-school | on issue | none | reissue increments suffix | prefer derive-from-STU |
| Transport ID | reuse `studentRoutes` docId | tenant | at opt-in | — | — | no new series |

**Rules:** allocation only after the idempotency claim (never before); atomic CAS; rejected/cancelled applications keep their APP# (audit trail); STU never reused/reset.

---

## SECTION 6 — IDENTITY MODEL (critical)

**Recommendation: HYBRID — student-first authentication (unchanged) + parent-first relationship layer (additive).**

Rationale: ZenX auth is student-credential based (Firebase Auth **UID == studentId**), and the global STU counter + this login model are explicitly load-bearing and frozen (`identity_architecture_decision`). Re-architecting to parent-first auth would be a high-risk rewrite with no proportional benefit. But the current `parents/{schoolId}_{studentId}` with `childrenIds=[studentId]` **cannot express siblings** and mints duplicate accounts. The fix is a **relationship layer**, not an auth change.

**Design:**
- **Canonical Parent record** keyed by **normalized primary phone** (fallback email): `parents/{schoolId}_{phoneHash}` with `childrenIds[]` (true multi), `contacts[]` (father/mother/guardian/emergency, each {relation, name, phone, email, isPrimary}), `primaryContactStudentId` (which child's login is the app entry — preserves UID==studentId).
- **Student→Parent reference** on `students.parentKey`.
- **Sibling link** = derived (`childrenIds[]`); powers the Parent-app child switcher and **sibling fee concession** (reuse `Fee_concessions`).
- **`indexPhones`** becomes **append** (list of children), not overwrite — fixes the current clobber where two siblings on one phone lose each other.
- **Guardians / emergency contacts / multiple mobiles** = entries in `contacts[]`, not new logins.
- **Step-parents / split custody** = multiple contacts with relation; one primary owns the app.

**Backward compatibility:** existing per-child parent docs remain valid; a **staged, idempotent backfill** merges children sharing a normalized phone into one canonical parent (P4). No student ID or login changes.

**Verdict:** Parent-first *identity*, student-first *auth* — the only model that fixes siblings without touching the frozen ID/auth scheme.

---

## SECTION 7 — FEE PACKAGE DESIGN (reuse Fees; never duplicate)

- **A package references, never re-implements, fees:** `feePackages/{schoolId}_{code}` = {label, session, headRefs[] (→ `feeStructures.feeHeads`), installmentTemplate, classMap[]}.
- **Class ↔ Package = Many-to-Many** (one package → many classes; one class → many packages, e.g. day-scholar vs hosteller). Selection at admission picks the applicable package for that class.
- **Discounts as concession overlays** (reuse `Fee_concessions`/`studentDiscounts`): Scholarship, RTE, Staff-Child, Sibling, Hosteller, Transport — applied on top of the package, never baked into it.
- **Taxes:** per-head, package-level "tax inclusive" flag (mirrors the video's model) — but only if a school actually charges tax; default none (Indian K-12 tuition is largely tax-exempt).
- **Installments:** template on the package → materialized into `feeDemands` by the **existing** `Fee_lifecycle`/`Fee_generation_service`.
- **Mid-session / proration:** **the key fix** — pass admission month into demand generation and apply the existing (currently unused-on-admit) `proRateFees`; annual/one-time heads governed by a per-head policy flag.
- **Accounting:** unchanged — demands and the registration journal keep `JE_FEE_{receiptKey}` idempotency and `defer_accounting`.

**Answers:** one package↔many classes ✅; one class↔many packages ✅; scholarship/RTE/staff-child/hosteller/transport/sibling = concessions ✅; proration ✅ via existing engine; **zero new fee/payment logic**.

---

## SECTION 8 — INSTALLMENT MODEL

- **Frequencies:** Monthly / Quarterly / Half-Yearly / Yearly / **Custom** (per-head due schedule) — expressed in the package's `installmentTemplate`.
- **One-time heads:** Admission Fee, Registration Fee, Security Deposit — single due, not recurring; excluded from proration.
- **Due-date rules:** relative ("N days after admission") or absolute (calendar), mirroring commercial behavior; auto-generate on confirm.
- **Proration:** recurring heads prorated from admission month; one-time heads full.
- **Late fee:** reuse the existing fines/defaulter mechanism (`feeDefaulters`, fines) — no new late-fee engine.
- **Accounting impact:** each installment = a `feeDemand`; payment = `feeReceipt` + journal; all via existing paths.

---

## SECTION 9 — SEAT MANAGEMENT

Reuse `sections` (`maxStrength`, `currentStrength`) + existing `get_class_sections` (already computes current/available/full/suggested).
- **Available seats** = maxStrength − currentStrength (live).
- **Reserved / Blocked seats:** additive section fields `reservedSeats`, `blockedSeats` (e.g. RTE quota, management quota) → effective available excludes them.
- **Waiting list:** reuse `crmWaitlist` when a section is full.
- **Transfer / mid-session:** allowed against available seats; same capacity rule.
- **Approval when full:** hard-block by default; **Principal override** with mandatory reason → audit entry (no silent over-fill). **Wire capacity into the counter form** (today it's only in CRM enroll).

---

## SECTION 10 — DOCUMENT MODEL (integrate with Certificates / Document Service)

- **Checklist** driven by `documentTypes` master + admission-type requirements: each doc = {type, required?, status ∈ Required/Received/Verified/Rejected, uploadedBy, verifiedBy}.
- **Storage:** reuse `schools/{schoolId}/students/{studentId}/documents/...` (MIME/finfo/quota already enforced); **add parent-side upload** for online applicants (currently impossible) + **per-student read restriction** in storage rules.
- **Issued documents** (Admission Form, Acknowledgement, Confirmation, Final Admission Form, Receipt, ID Card) → produced by the **central Document Service** from the Document-Engine audit: snapshot-immutable, numbered, QR-verifiable. Admission does **not** build its own PDF stack.
- **Verification lifecycle:** who uploads (parent/officer), who verifies (officer), status transitions audited.

---

## SECTION 11 — CROSS-MODULE INTEGRATION

| Module | Trigger | Input | Output | Rules | Rollback |
|---|---|---|---|---|---|
| CRM | application create/stage/approve | applicant data | `crmApplications` status | status machine; dedup | cancel/reopen |
| SIS | CONFIRM | approved application | `students` (STU, normalized) | fail-loud; idempotent | delete_student (guarded) |
| Fees | CONFIRM + package | package+admit date | `feeDemands` (prorated) | reuse Fee_lifecycle; no dup logic | recompute/void |
| Accounting | fee payment | receipt | journal `JE_FEE_{key}` | idempotent; period-lock; defer_accounting | reverse (lock-aware) |
| Transport | opt-in | route+stop | `studentRoutes` + transport fee | seat/route rules | unassign |
| Communication | each stage | event+template | SMS/push/**inbox** | canonical events; no dup engine | — |
| HR | counsellor assign | staffId | lead attribution | staff must be active | reassign |
| Library | — | student exists | eligibility | none (implicit) | — |
| Hostel | opt-in | building/room | allocation | capacity | deallocate |
| Examination | student Active | class/section | exam eligibility | — | — |
| Parent App | CONFIRM | parent link | child visible + credentials | UID==studentId; sibling switcher | disable |
| Teacher App | CONFIRM | class/section/Active | roster entry | protect fail-soft blank fields | resync |
| Certificates/Document Svc | issue | snapshot | final form/receipt PDF | immutable+numbered+QR | supersede |
| Timetable | placement | section | inherited schedule | none | — |
| Attendance | student Active | class/section | eligibility | — | — |

---

## SECTION 12 — SECURITY MODEL

- **RBAC with separation of duties** (new capability layer over existing roles): `admission.create` (Reception/Officer), `admission.verifyDocs` (Officer), `admission.approve` (**Principal/Officer — distinct from create**), `admission.collectFee` (Accounts), `admission.confirm` (Officer), `transport.assign` (Transport Mgr). Today one `MANAGE_ROLES` set does all — split it.
- **Firestore rules:** `students` read → **owner/staff only** (fix intra-tenant PII leak); `crmApplications`/`admissionReceipts`/`parents` stay server-only (deny-all catch-all); new masters read=same-school, write=admin.
- **Storage rules:** admission documents → **per-student owner + staff** (fix subtree-wide read); MIME/size on all upload paths.
- **Approval workflow:** direct-mode (auto-approve, gated by config + capability) vs approval-mode (Principal approves before confirm); every approve/reject/override/cancel **audited** (`auditLogs` + `studentHistory`).

---

## SECTION 13 — WORKFLOW / RESPONSIBILITY MATRIX

| Action | Reception | Counsellor | Adm. Officer | Principal | Accountant | Transport Mgr | Class Teacher | Parent | System |
|---|---|---|---|---|---|---|---|---|---|
| Create enquiry/application | ✎ | ✎ | ✎ | – | – | – | – | ✎(online) | – |
| Edit application | ✎ | ✎ | ✎ | – | – | – | – | – | – |
| Verify documents | – | – | ✅ | – | – | – | – | upload | – |
| Approve admission | – | – | (✅) | **✅** | – | – | – | – | – |
| Collect fee | – | – | – | – | ✅ | – | – | pay(online) | – |
| Confirm admission (create student) | – | – | ✅ | – | – | – | – | – | executes |
| Cancel/withdraw | – | – | request | **✅** | – | – | – | request | – |
| Assign transport | – | – | – | – | – | ✅ | – | request | – |
| View student record | own | leads | ✅ | ✅ | fees view | transport view | **own class only** | **own child only** | – |
| Download admission docs | – | – | ✅ | ✅ | receipts | – | – | own child | issues |

(✎ create/edit · ✅ authorized · parentheses = configurable.)

---

## SECTION 14 — EDGE CASES

| Case | Handling |
|---|---|
| **Duplicate parent** | resolve by normalized phone at confirm → attach child to existing parent; never mint a second account. |
| **Duplicate student** | idempotency claim (APP id / walk-in draft id) + person dedup (name+DOB+phone) → soft-warn, hard-block exact match; STU allocated only post-claim. |
| **Twins** | same parent + same DOB + different name → allowed (both children under one parent); dedup warns, operator confirms. |
| **Sibling admission** | second child reuses the parent (phone match), gets sibling concession; appears in Parent-app switcher. |
| **Mid-session** | proration from admission month (one-time heads full); explicit "Fee Applicable From" honored. |
| **Cancelled admission** | before confirm → application status=cancelled, APP# retained, no student. After confirm → withdrawal flow. |
| **Rejected application** | status=rejected, APP# retained, no student, no fee; audit. |
| **Seat full** | block; offer waitlist (`crmWaitlist`) or Principal override (reason+audit). |
| **Refund** | via existing `Fee_refund_service` (reuse); reverses journal within period-lock; receipt void/superseded. |
| **Admission withdrawal (TC)** | existing `Sis::issue_tc` flow (dues gate, atomic TC#, roster removal, Auth disable); student status=TC. |
| **Transfer (in)** | admission-type=Transfer; capture prev-school + TC; same confirm path. |
| **Academic session change** | new application scoped to new session; STU persists; promotion handles continuing students (existing promote flow). |
| **Re-admission** | admission-type=Re-admission; reuse existing STU/parent if returning; no duplicate identity. |
| **Migration (legacy data)** | staged backfill: merge per-child parent docs by phone; normalize masters; idempotent, reversible, audited. |

---

## SECTION 15 — IMPLEMENTATION READINESS

**Business rules needing OWNER confirmation (blocking before build):**
1. **Admission mode default** — should new schools default to **Direct** or **Approval** admission? (config either way; need the default.)
2. **Proration policy** — for mid-session joins, which heads prorate vs charge-full (tuition prorate; admission/registration full)? Confirm annual-fee handling (full vs prorated).
3. **Sibling concession** — automatic on parent-link, or manual per case? Amount/rule.
4. **Roll number** — school-assigned manually, or auto per class-section? Reset each session?
5. **Approval authority** — is "approve" strictly Principal, or also Admission Officer (configurable)?
6. **Invoice vs Receipt** — does finance need a separate Invoice number, or is the ADM receipt sufficient?
7. **Capacity override** — who may admit past a full section (Principal only?) and is a reason mandatory?
8. **Aadhaar/SATS** — store masked/last-4 + gated document image only (recommended), or full number (not recommended)? Owner must accept the data-minimization stance.
9. **Category/quota** — do target schools use reserved categories (drives Caste/RTE fields), or keep it minimal?

**Migration required:** Parent identity (merge per-child docs by phone — P4); master normalization (Category/Religion/Document-type — P1); public APP-id → atomic series (P2). All staged, idempotent, reversible.

**No changes required (reuse unchanged):** Id_generator/STU counter; Accounting engine + `JE_FEE_{key}`; Razorpay verification + `Admission_collection`; Fee_lifecycle demand/receipt engine; Transport routes/stops; Timetable; Library; Attendance; Examination; Certificates/Document Service; Firebase Auth (UID==studentId).

**Reused-but-extended:** `parents` (childrenIds/contacts); `sections` (reserved/blocked seats); `crmApplications` (admission-type/status fields); storage/Firestore rules (read isolation); Communication (canonical events); counter form (capacity + fields).

**Still open / recommend a short owner workshop:** items 1–9 above are the only true unknowns; everything else is architecturally determined by this spec and the prior audits.

---

**This specification is the official implementation blueprint. Once the nine owner decisions in §15 are answered, implementation can proceed phase-by-phase (Discovery §14 roadmap: P1 masters → P2 counter parity → P3 fee correctness + unify → P4 identity → P5 rules → P6 packages/installments → P7 documents → P8 PDFs → P9 comms/RBAC → P10 dashboard) without changing these business rules or architecture. No code will be written until you approve.**
