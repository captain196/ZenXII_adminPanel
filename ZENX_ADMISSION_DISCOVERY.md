# ZenX ERP — Admission Module Modernization

## Production Discovery Audit (Read-Only Architecture Report)

**Mode:** READ-ONLY · architecture discovery · **no code, no commits, no modifications.**
**Branch:** ank-yug_b1 · **Repos:** Admin `C:\xampp\htdocs\ZenX\school` (canonical; Grader retired) · Parent `D:\Projects\SchoolSyncParent` · Teacher `D:\Projects\SchoolSyncTeacher`.
**Basis:** 4-investigator admission audit + 5-investigator document audit + ZenoxERP video (frame-by-frame) + 2 fresh discovery passes (file/dependency map, master-data inventory). Evidence cited `file:line`.

> **Reconciliation note (important):** the fresh dependency map shows `Sis::enroll_student` now carries an **idempotency claim** (`Accounting_idempotency`, key `ENROLL_{appId}`, `Sis.php:6463-6466`, `markSuccess` `:6636`), and a `ADMISSION_MODULE_FINAL_PRODUCTION_CERTIFICATION.md` exists at the repo root. The "duplicate-student race" raised in the earlier admission audit appears to have been **hardened on the CRM path since**. This report treats the CRM enroll path as idempotent and re-scopes the residual race to the **direct `save_admission` walk-in path**, which shows **no equivalent claim**.

---

## 1. CURRENT ADMISSION ARCHITECTURE

ZenX admission is a **three-stage funnel with three entry surfaces and three student-creation paths**, all Firestore-only.

**Stages (collections):** `crmInquiries` (INQ) → `crmApplications` (APP) → `students` (STU); plus `crmWaitlist`, `crmSettings` (pipeline stages), `crmRateLimits`, `admissionReceipts` (ADM), `admissionPayments`, `admissionPaymentIdempotency`.

**Entry surfaces (views):**
- `application/views/studentAdmission.php` — admin counter/walk-in (→ `Sis::save_admission` `:243`).
- `application/views/admission/public_form.php` — public paid online form (→ `Admission_public.php`).
- `application/views/admission_crm/*` — CRM leads/applications/pipeline (`inquiries/applications/pipeline/settings`).
- Legacy `admission_crm/online_form.php` — older public form, **redundant** (no payment, fewer fields).

**Creation paths (all in `Sis.php`):**
- `save_admission` `:243` — direct walk-in, immediate student, **fail-soft**, no approval, no idempotency claim.
- `enroll_student` `:6325` — CRM approval→student, **fail-loud + idempotent** (`ENROLL_{appId}`), fee-structure pre-flight.
- `_runStudentImport` `:3642` — bulk import.

**Shared primitives:** ID via `Id_generator::generate('STU')` (atomic claim+pointer, `feeCounters/_sys_STU`); Auth via `_createFirebaseAuthStudent` `:2743` (UID == studentId); fees via `Fee_lifecycle::assignInitialFees` `:354`; sync via `Entity_firestore_sync::syncStudent/syncParent`; receipts+accounting via `Admission_collection` (`JE_FEE_{receiptKey}`, idempotent).

---

## 2. CURRENT WORKFLOW (code-traced)

```
DIRECT WALK-IN  (Sis::save_admission)
  operator opens studentAdmission.php  (no search-existing step)
   → RBAC MANAGE_ROLES (:245)
   → normalize class/section (:257)   [no capacity check]
   → STU# (:322)  → dup check on generated ID only (:329, never a person)
   → upload photo+3 docs (:351)  [fail returns false, not surfaced]
   → syncStudent/syncParent (:430, FAIL-SOFT — warning only)
   → recompute section strength (:439)  [AFTER create]
   → indexPhones set (:443, OVERWRITE by phone)
   → monthFee init (:464) → subjects (:484)
   → Firebase Auth (:527) → assignInitialFees (:533, feeStructures→feeDemands)
   → defaulter status (:547) → history ADMISSION (:552) → audit (:556)
   → optional CRM lead back-link (:562)
   → SMS notify_admission_confirmed (:575, NO credentials)
   → UI: toast + location.reload()  [DEAD-END: no print, no next action]

CRM APPROVAL  (inquiry→application→approve→enroll)
  save_inquiry (:5694, INQ) → convert_to_application (:5751, APP, pending/document_collection)
   → update_stage (:6249 CAS) → approve_application (:6275, approved)   [NO student yet]
   → enroll_student (:6325, requires approved):
       RBAC → read crmApplications (:6331) → STU# (:6339)
       → auto-pick first section (:6360) → feeStructures pre-flight abort-if-empty (:6391)
       → IDEMPOTENCY claim ENROLL_{appId} (:6463)
       → saveStudent FAIL-LOUD (:6490) → indexPhones (:6504) → student index (:6513)
       → monthFee (:6528) → crm status=enrolled (:6542) → audit (:6549)
       → Auth (:6570) → SMS credentials (:6590) → assignInitialFees (:6607)
       → syncStudent/syncParent (:6615) → history (:6626) → markSuccess (:6636)

PUBLIC ONLINE  (Admission_public.php)
  form (:91) → submit (:165, crmApplications APP_{hex}_{rand5}, dup-scan :337)
   → initiate_payment (Razorpay→admissionPayments) OR choose_offline
   → payment_callback/webhook → _apply_admission_payment_paid (:993):
       payment=paid → ADM receipt (Admission_collection) → crm payment_status=paid
       (does NOT advance status — admin still approves)
   → later: admin runs enroll_student   [public payment NEVER creates a student]
```

---

## 3. DEPENDENCY GRAPH

```
ADMISSION (Sis::save_admission / enroll_student)
├─ RBAC ................ rbac_helper (require_permission/MANAGE_ROLES)         Sis:245 / :6327
├─ MASTER DATA ......... Firestore_service classKey/sectionKey; `sections`     Sis:257 / :6360
├─ NUMBERING ........... Id_generator STU (feeCounters/_sys_STU + claim docs)  Sis:322 / :6339
├─ STUDENT MASTER ...... fs->saveStudent / _getStudent; `students`             Sis:430 / :6490
├─ SEARCH/INDEX ........ indexPhones; searchTokens; admission_search_helper    Sis:443 / :6504
├─ DOCUMENTS ........... _uploadStudentFile → Firebase Storage; students.Doc   Sis:351 / :2854
├─ AUTH ................ Firebase createFirebaseUser + claims (UID==studentId) Sis:527 / :6570
├─ FEES ................ Fee_lifecycle assignInitialFees; feeStructures→feeDemands  Sis:533 / :6607
│    └─ DEFAULTER ...... Fee_defaulter_check; feeDefaulters                    Sis:547
├─ ACCOUNTING ......... (registration fee only) Admission_collection JE_FEE_{key}; accounting/accountingLedger
├─ PARENT-APP SYNC .... Entity_firestore_sync syncStudent/syncParent; students, parents  Sis:430 / :6615
├─ TEACHER ROSTER ..... (indirect) Teacher app reads students by class/section+status=Active
├─ COMMUNICATION ...... notification_helper notify_admission_confirmed / notify_enrollment_credentials (SMS)
├─ CRM ................ crmApplications casAppendBounded (status transitions) Sis:562 / :6542
├─ AUDIT .............. audit_helper log_audit; _log_history → studentHistory  Sis:552 / :6626
└─ IDEMPOTENCY ........ Accounting_idempotency ENROLL_{appId}  (enroll only)   Sis:6463
NOT wired at admission: Transport (routes exist, opt-in at enroll not admit) · Timetable (section-inherited) · Library (eligibility by existence) · Hostel · Certificates/Result (downstream).
```

---

## 4. MASTER DATA AUDIT

| Master | Status | Where / Note |
|---|---|---|
| Class · Section · Session | **EXISTS (configurable)** | School_config tabs → Firestore `sections` + `schools.currentSession`. Only fully master-driven admission dropdowns. Public+CRM forms **don't collect Section** (set at enroll). |
| Lead Stage | **EXISTS (configurable)** | CRM Settings → `crmSettings.stages` (editable). |
| Affiliation/Board | **EXISTS (configurable)** | School_config Board tab + profile free-text (two places — mild dup). |
| Fee Heads | **EXISTS (configurable)** | `feeStructures.feeHeads[]` (Fee_management). |
| Lead Source · Admission Status · Blood Group · Gender · Fee Type | **EXISTS (hardcoded)** | enums in views/controllers; Fee Type locked Monthly\|Yearly. |
| Category · Religion · Document Types | **NEEDS EXTENSION** | hardcoded with **cross-surface DRIFT** (admin dropdown vs public/CRM free-text; admin Category=General/OBC/SC/ST, public adds EWS). Doc types = fixed slots + quota only. |
| Nationality · Occupation | EXISTS (free-text) | no master list. |
| **Fee Packages (bundles)** | **MISSING** | no bundled fee-plan entity; admission selects no package. |
| **Admission Type** | **MISSING** | no new/transfer/RTE classification. |
| **Fee Groups** | **MISSING** | no grouping layer. |
| House · Medium/Language · Mother Tongue · Caste/Sub-Caste · Reference Scheme | **MISSING** | not collected anywhere. |
| Transport Routes/Stops | EXISTS (configurable) | Transport module; **not wired into admission**. |

**Verdict:** academic masters (class/section/session) and CRM pipeline stages are solid; **the admission-specific masters a commercial ERP expects (Admission Type, Fee Package, shared Category/Religion/Document-Type) are absent or drifting.** No master should be *removed*; the work is *shared masters + extension*, not replacement.

---

## 5. ZENOXERP FEATURE COMPARISON (from the video + benchmark)

*(ZenoxERP is a verified real product; its admission transactional core is well-evidenced, but approval/dedup/sibling/verification are NOT publicly verified — see benchmark.)*

| Feature | ZenX status | Note / better-fit alternative |
|---|---|---|
| Search Existing Student | **Partially exists** | `search_student` `:2463` + `admission_search_helper` exist; **not wired as dedup-first on the counter form.** |
| CRM Enquiry | **Already exists** | `crmInquiries`. |
| Lead Management | **Already exists** | `crmApplications` + leads + pipeline. |
| Counselling / Followup log | **Missing** | pipeline stages exist; no dated interaction log. |
| Campus Visit | **Missing** | no visit scheduling. |
| Registration | **Already exists** | application stage. |
| Fee Package | **Missing** | no bundle master. |
| Installment Builder | **Implemented differently** | monthly `feeDemands`; no per-admission configurable plan. |
| Billing Summary (live totals) | **Missing** | not on admission form. |
| Transport (at admission) | **Implemented differently** | module exists, opt-in at enroll not admit. |
| Registration Number | **Partially exists** | APP# exists; not surfaced as a distinct register no on the record. |
| Admission Number | **Already exists** | STU (atomic global). |
| Admission Type | **Missing** | — |
| Admission Status | **Partially exists** | hardcoded application statuses; not a configurable master. |
| Admission Taken By | **Missing (implicit)** | derivable from auth/audit — **better: auto-stamp from session, no manual field.** |
| Counsellor assignment | **Missing** | no lead→counsellor. |
| Parent Email | **Partially exists** | public form yes; **counter form only student email.** |
| Save & Send | **Partially** | CRM enroll SMS credentials; manual add sends confirm only, no creds. |
| Document Checklist | **Missing** | fixed slots, no status. |
| Document Verification | **Missing** | store-and-forget. |
| Lead Source | **Exists (hardcoded)** | — |
| Lead Stage | **Exists (configurable)** | — |
| Affiliation | **Exists (configurable)** | — |
| Seat Capacity | **Partially exists** | `get_class_sections` in CRM enroll; **absent on counter form.** |
| Fee Collection | **Implemented differently** | registration fee canonical; tuition = demands; not inline on the admission form. |
| Student Creation | **Already exists** | 3 paths (unify). |
| Parent Login | **Implemented differently** | Auth UID==studentId; **one login per child (no sibling)** — needs extension. |
| ID Card | **Already exists** | `Sis::id_card` + signed QR. |
| Timetable | **Already exists (section-inherited)** | no per-student action needed at admission. |
| Transport Assignment | **Missing at admission** | module exists — add opt-in. |
| Library Activation | **Should NOT be copied** | ZenX library works by student existence; a per-student activation step is redundant. |
| Communication | **Partially exists** | bespoke SMS; **not** canonical inbox events. |

---

## 6. WORKFLOW ANALYSIS — stage classification

| Stage | Classification |
|---|---|
| Enquiry | **Optional / school-configurable** (small schools skip) |
| Counselling / Followup | Optional — coaching-heavy |
| Campus Visit | Optional — K-12 premium |
| Registration | **Mandatory** (or merged into direct admit) |
| Document Verification | **Should-have**, school-configurable |
| Seat Availability | **Mandatory** |
| Fee Package | **School-configurable** |
| Admission Approval | **School-configurable** (direct vs approval mode) |
| Admission Confirmation / Student Creation | **Mandatory** |
| Fee Collection | **Mandatory** (at least registration) |
| ID Card | Optional / on-demand |
| Parent Login | **Mandatory** (auto) |
| Transport | Optional (opt-in) |
| Library | **Not a stage** (implicit by existence) |
| Communication | Should-have |

**Country-specific (India):** session `YYYY-YY`, category/quota, TC from prev school, SATS/Aadhaar (**data-minimization** — masked/gated, never a key). **Coaching-only (skip):** batch/course-package, royalty, job-required. **K-12:** section/house/report-card downstream.

---

## 7. BUSINESS-RULE ANALYSIS (per proposed feature — condensed)

| Feature | Key rule / dependency / cross-module impact |
|---|---|
| Unify + idempotent creation | one primitive both paths call; idempotency key = APP id / walk-in draft id; reuse `Accounting_idempotency` (already used by enroll). Impact: SIS, Fees, Auth, sync. Migration: none. Rollback: per-path. |
| Mid-session proration | pass admission month into `buildAdmissionDemandSpecs`; call `proRateFees` (exists, only Transport uses it). Impact: Fees demands, Accounting (correct amounts). Needs school policy for annual/one-time heads. |
| Parent dedup + siblings | parent doc keyed by normalized phone/email; `childrenIds[]` multi; `indexPhones` append not overwrite. Impact: Auth, Parent app child-switcher, sync. Migration: staged merge of per-child parent docs. Rollback: careful. |
| Read-isolation rules | `students`→owner/staff; storage docs→per-student owner. Impact: Firestore/Storage rules, both apps. Must not break legit reads. |
| Capacity on counter | wire existing `get_class_sections`; block/warn on full. Impact: UI only. |
| Fee Package + installment + billing | new master `feePackages`; reuse Fees engine for demand+installment; live totals client-side. Impact: Fees, Accounting. No new payment engine. |
| Document checklist + verify + parent upload | `documentType` master; per-doc status; parent upload path + storage rules. Impact: Storage, rules, both apps. |
| Admission PDFs + Save & Send | via the Document Service (see Document audit); snapshot+number+QR. Impact: Communication. |
| Canonical Communication events | emit via `Communication_helper::fire_event` alongside SMS. Impact: Communication only. |

---

## 8. SECURITY ANALYSIS
| Sev | Finding | Evidence |
|---|---|---|
| HIGH | `students` readable by **any** same-school login (not just own child) — intra-tenant PII. | `firestore.rules` `isSameSchool()` on `students` |
| HIGH | Any same-school user can read **any** student's admission documents (Aadhaar/birth cert). | `storage.rules:192-197` subtree-wide |
| MEDIUM | No separation of duties — one `MANAGE_ROLES` set for create/approve/enroll/collect; doc verification ungated. | `Sis.php:31` + action gates |
| MEDIUM | Direct `save_admission` bypasses approval with no config gate; fail-soft write can create roster-invisible student. | `Sis.php:243,430` |
| LOW | Public app id `APP_{hex}_{rand5}` weak (microtime+rand). | `Admission_public.php:399` |
**Sound:** cross-tenant isolation; server-side payment verification; receipt/accounting idempotency; CRM/receipt/parent collections server-only (deny-all catch-all).

## 9. FIRESTORE IMPACT (reuse-first)
**Reuse:** `crmInquiries/crmApplications/crmWaitlist/students/admissionReceipts/admissionPayments/feeDemands/feeStructures/sections/parents/indexPhones/feeCounters/auditLogs/studentHistory`.
**Extend (fields, not new collections):** admission-type/status onto `crmApplications`; branding/pkg onto fee flow; `childrenIds[]` on `parents`.
**New only if approved:** `feePackages` (bundle master), `documentTypes` (checklist master), shared `masters/{category,religion}` — small, additive. **No new numbering collection** (reuse `Id_generator`/`nextSchoolCounter`).

## 10. STORAGE IMPACT
Reuse `schools/{schoolId}/students/{studentId}/documents/...` (MIME/finfo/quota already enforced). Add: **parent-side upload path** for online applicants; **per-student read restriction** in `storage.rules`. No generated-PDF storage unless the Document Service phase adds it.

## 11. CROSS-MODULE IMPACT
- **Fees/Accounting:** preserve `defer_accounting` + `JE_FEE_{receiptKey}` idempotency; fix mid-session amounts.
- **Parent app:** child-switcher for siblings; display server-issued receipts (kill on-device receipt generation — from Document audit).
- **Teacher app:** roster already works via `students` (class/section/status) — protect the fail-soft roster-invisible case.
- **Transport/Timetable/Library/Hostel:** unaffected by admission today; add transport opt-in only.
- **Communication:** add canonical events; keep SMS.

## 12. PRODUCTION RISKS (current, re-scoped)
1. **Mid-session fee over-billing** (`Fee_lifecycle` bills all 12 months; `proRateFees` never called on admit) — **financial, live**.
2. **Duplicate parent / no sibling model** + phone-index clobber — **identity, live**.
3. **Intra-tenant read exposure** of student records + documents — **security, live**.
4. **Direct `save_admission`**: no approval gate, fail-soft write, no idempotency claim, no credentials SMS — **integrity, live** (CRM path already hardened).
5. **No person-level dedup / no capacity on the counter form** — operational.
6. **Category/Religion/Document-type master drift** across surfaces — data quality.

## 13. RECOMMENDED ARCHITECTURE
One **`Admission_service::confirm(applicationRef, actor)`** primitive that BOTH direct and approval paths call — idempotency-claimed (reuse `Accounting_idempotency`), capacity-checked, dedup-aware, parent-sibling-aware, proration-aware — composing the **existing** Id_generator, Fee_lifecycle, Entity_firestore_sync, Admission_collection, Communication, and (for the final form) the proposed Document Service. Direct admission = an auto-approved application feeding the same primitive → **exactly one student-creation code path**. Masters (Admission Type/Status, Fee Package, shared Category/Religion/Doc-Type) added additively via School_config. Nothing replaced; everything reused.

## 14. RECOMMENDED IMPLEMENTATION PHASES
> Each compiles/deploys/rolls back independently; reuses existing collections/services; preserves Firestore-only + backward compat.

- **P1 — Master-data foundation (LOW):** Admission Type/Status masters + shared Category/Religion/Document-Type (fix cross-surface drift). School_config + additive Firestore. No app impact.
- **P2 — Counter-form parity (LOW–MED):** wire existing `get_class_sections` capacity; add parent email + missing fields; **search-existing-first** dedup; post-save next-action (print/collect/profile); reconcile/retire legacy public form.
- **P3 — Fee correctness + unify creation (MED, financial):** mid-session proration; give `save_admission` the same idempotency/fail-loud guarantees as `enroll_student` via the shared primitive.
- **P4 — Parent identity + siblings (HIGH):** phone/email-keyed parent, `childrenIds[]`, append-not-overwrite index; Parent-app child switcher; staged migration.
- **P5 — Security rules (MED):** restrict `students` + admission-document reads to owner/staff.
- **P6 — Fee Package + installment + billing at admission (MED):** new `feePackages` master; reuse Fees engine; live billing summary. No new payment engine.
- **P7 — Documents: parent upload + verification checklist (MED).**
- **P8 — Admission PDFs + Save & Send (MED):** final admission form + confirmation via Document Service (snapshot/number/QR).
- **P9 — Communication canonical events + counsellor/followup/campus-visit (MED).**
- **P10 — Admission dashboard + reports + certification (LOW–MED).**

## 15. WHAT SHOULD **NEVER** BE IMPLEMENTED
- **Royalty Payable / Job Required / batch-course package** — coaching/franchise-specific; alien to K-12.
- **Full Aadhaar/SATS as a key or plaintext store; Aadhaar-anchored dedup** — DPDP/data-minimization risk (use masked/gated image + name+DOB+phone dedup).
- **Per-student Library "activation"** — redundant; ZenX library works by student existence.
- **Multi-round merit list / lottery / school-choice engine** — solves a problem ZenX schools don't have.
- **A second numbering engine** — reuse `Id_generator`/`nextSchoolCounter`.
- **A parallel parent-account entity or a second payment/communication engine** — extend existing sync/Fees/Communication only.
- **AI application-fraud / lead-scoring.**

**Nothing will be implemented until you review and approve this discovery report and the proposed architecture.**
