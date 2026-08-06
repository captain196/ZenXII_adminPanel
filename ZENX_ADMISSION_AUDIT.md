# ZenX ERP — Student Admission & Registration System

## Enterprise Front-Office Workflow Audit · ZenoxERP Benchmark · Gap Analysis · Target Architecture

**Status:** AUDIT ONLY — no code written, no files modified, no commits. Awaiting your approval before implementation.
**Audited:** Admin `C:\xampp\htdocs\ZenX\school` (canonical) · Parent `D:\Projects\SchoolSyncParent` · Teacher `D:\Projects\SchoolSyncTeacher` · Rules `…\firebase-rules\`.
**Method:** 4 parallel read-only investigators (3 code-tracing, 1 web benchmark). Evidence cited as `file:line`; competitor claims graded VERIFIED / PARTIALLY VERIFIED / NOT PUBLICLY VERIFIED.

---

## 1. EXECUTIVE SUMMARY

**ZenX Admission is more complete than a "basic form" — it already has a real three-stage funnel, a canonical registration-fee/accounting engine, and a working CRM-to-student conversion. But it is NOT yet a professional admission system, because the *integrity spine* is broken in several places that directly violate your stated production principles.**

Maturity ≈ **55–60%** of a commercial K-12 admission system. It can carry a walk-in from registration to an enrolled student with fees and parent SMS — but with defects that a professional ERP would not ship: **mid-session fee over-billing, no parent-dedup / no sibling model, a duplicate-student race on enroll, weak counter-side duplicate detection, and intra-tenant document exposure.**

| Pillar | Maturity | Verdict |
|---|---|---|
| Enquiry→Registration→Admission staging | **Good** | Three distinct collections (`crmInquiries`→`crmApplications`→`students`) + waitlist. |
| Registration/admission fee + accounting | **Production-ready** | One canonical engine, server-resolved amount, idempotent `JE_FEE_{receiptKey}`, online+offline shared path. |
| Application→student conversion | **Partial / unsafe** | Approval-gated CRM path exists, but **not idempotent** — a double-click can mint duplicate students/Auth/fees. |
| Parent identity & siblings | **Broken model** | One login **per child**; second child = new account; **no sibling linking**; phone-index clobber. |
| Mid-session fees | **Defective** | Bills **all 12 months** regardless of join date; `proRateFees` exists but is never called on admission. |
| Duplicate detection | **Missing outside public form** | Admin create/convert/enroll/walk-in have **no** person-level dedup. |
| Document upload | **Partial** | Secure storage/MIME/quota ✅; no verify-status/checklist/versioning; **parent channel can't upload at all**. |
| Class/section capacity | **Partial** | Rich picker in CRM enroll; **absent in the counter form** (the common walk-in). |
| Admission PDFs | **Partial** | Receipts strong; **no filled final admission form / confirmation letter**. |
| Operator UX | **Partial** | Good preview/validation; weak dedup, no capacity, **dead-ends after save**. |
| Communication | **Partial** | Bespoke SMS only — **not** the canonical Communication inbox. |
| RBAC | **Weak** | One coarse role set for create/approve/enroll/collect — **no separation of duties**. |
| Multi-tenant isolation | **Mostly sound; 2 leaks** | Cross-tenant blocked; **intra-tenant** any login reads all students + all admission documents. |
| Firestore-only | **Compliant (admission)** | Admission core is Firestore; `Ats.php` (HR ATS, out of scope) still uses RTDB-path reads. |

---

## 2. CURRENT ZENX WORKFLOW (code-traced)

**Three admission surfaces coexist** (one is redundant):
- `application/views/admission/public_form.php` — current parent-facing online form **with payment** (`Admission_public::form/submit`).
- `application/views/admission_crm/online_form.php` — **legacy** parent form, no payment, fewer fields, DOB/gender not required (`Admission_crm::submit_online_form`). **Redundant/divergent.**
- `application/views/studentAdmission.php` — operator counter form (`Sis::admission/save_admission`).

**Three back-end paths create students** (two are near-duplicates):

```
(a) APPROVAL / CRM PATH  — the "professional" funnel
  save_inquiry (Sis.php:5694) → crmInquiries [INQ00001]
    → convert_to_application (:5751) → crmApplications [APP00001] status=pending, stage=document_collection
    → update_stage (:6249, CAS)
    → approve_application (:6275) status=approved            ← NO student yet
    → enroll_student (:6325, requires status==approved)      ← the ONLY approval→student path
        → _nextStudentId STU#### → class/section resolve → fee-structure preflight (abort if none)
        → saveStudent→students (fail-loud) → phone index → student index → monthFee
        → app status=enrolled → Firebase Auth (NOT fail-loud) → credentials SMS
        → assignInitialFees → entity sync → ADMISSION history

(b) PUBLIC / ONLINE PATH
  form (Admission_public.php:91) → submit (:165) → crmApplications [APP_{hex}_{rand5}] status=pending, payment_status=pending
    → initiate_payment (:619, Razorpay→admissionPayments) OR choose_offline (:535)
    → payment_callback/webhook → _apply_admission_payment_paid (:993): payment=paid, ADM receipt, payment_status=paid
    → (explicitly does NOT advance status — admin still approves)
    → later: admin runs enroll_student (path a)              ← public payment NEVER creates a student

(c) DIRECT WALK-IN PATH — bypasses the entire funnel
  admission (Sis.php:213) → save_admission (:243): STU#### → students (fail-SOFT) → Auth → fees → history
    (no inquiry, no application, no approval; optional lead back-link if lead_id posted)
```

**The problem:** paths (a) and (c) are **not reconciled** — the direct walk-in path has no approval gate, no config toggle, no person-level dedup, and a *fail-soft* student write; while the CRM enroll path, though safer, is *not idempotent*.

---

## 3. CURRENT ARCHITECTURE

| Concern | Implementation | Notes |
|---|---|---|
| Collections | `crmInquiries`, `crmApplications`, `crmWaitlist`, `students`, `admissionReceipts`, `admissionPayments`, `indexPhones`, `parents` | Firestore-only. |
| Controllers | `Sis.php` (CRM + counter + enroll), `Admission_public.php` (online + payment), `School_config.php` (admission-fee config) | `Ats.php` = **HR staff ATS**, not students (out of scope; uses RTDB-path reads). |
| Libraries | `Id_generator` (STU, atomic global), `Admission_collection` (receipt + accounting), `Fee_lifecycle` (demand build), `Entity_firestore_sync` (student/parent sync, class/section normalize) | |
| Numbering | `INQ####`/`APP####` per-school (`nextSchoolCounter`); public app id `APP_{hex}_{rand5}` (random); `STU####` **global atomic**; `ADM######` receipts per-school | Dual APP schemes; `ADM` overloaded (staff-id vs receipt-no, different counters). |
| Payment | Razorpay + mock; server-side signature + captured-amount enforcement; offline "pay at school" | Shared `Admission_collection::record_payment` → `admissionReceipts` + journal `JE_FEE_{receiptKey}`. |
| Storage | `schools/{schoolId}/students/{studentId}/documents/{label}_{ts}_{rand}.{ext}` | MIME via `finfo`, per-file/aggregate/count quota. |
| Parent identity | Firebase Auth **UID == studentId**; `parents/{schoolId}_{studentId}`, `childrenIds=[studentId]` | **One login per child.** |

---

## 4. ZENOXERP BENCHMARK (publicly verifiable only)

**ZenoxERP is a real product** — Zenoxit Pvt Ltd, Thane; live site, i-Genius parent app, IndiaMART listing. Evidence is mostly the vendor's own marketing (correct bar for a feature benchmark).

**VERIFIED:** enquiry/leads + multi-source capture; enquiry follow-ups (dated log, auto SMS/voice reminders); admission form (fees/installment/batch); Registration No + Roll No + Invoice No + ID card + academic year; document upload (leaving cert/aadhaar/pan/caste); student photos; family info; previous-school details; admission-status field; class/division/standard + batch allocation; fee/package selection at admission; receipt/invoice (barcode); paperless student-record creation; parent login (i-Genius); **login credentials emailed on admission**; SMS/email/WhatsApp on admission events; admission reports.

**PARTIALLY VERIFIED:** online self-service registration + payment gateway (no parent-facing submission screenshot); application-tracking pipeline.

**NOT PUBLICLY VERIFIED** (do not assume ZenoxERP has these): a distinct admission-number vs registration-number scheme; per-document verification checklist; sibling linking; approval/merit state machine; **duplicate detection**.

**Net:** ZenoxERP's *transactional core* is strong and matches ZenX's ambitions; its *governance layer* (approval/dedup/sibling/verification) is unproven publicly. Those governance features therefore justify themselves from **broader commercial practice**, not from ZenoxERP.

---

## 5. COMMERCIAL K-12 BENCHMARK (Fedena, Entab, PowerSchool, Teachmint)

- **Three-stage funnel** with explicit lifecycle (Fedena: New/Qualified/Processed/Rejected + convert-to-registration; Teachmint: Enquiry/Interested/Confirmed/Rejected/Shortlisted).
- **Two numbers:** registration/application no (custom+auto-increment) **and** admission no (sequential, allocated after verification) — Entab, Fedena.
- **Verify-before-live approval gate** (Entab "registration verification"; PowerSchool staff-routed approvals).
- **Per-document checklist with statuses** (Entab).
- **Duplicate detection** on name+DOB+phone (higher-integrity pipelines).
- **Class/section seat capacity + waitlist** (Teachmint filled/vacant).
- **Admission PDFs:** application form + acknowledgement + receipt (Entab certificate-setting).
- **Dashboard + conversion-rate reporting** (Fedena eagle-eye, source/counsellor-wise).
- **Online self-service** (fill/upload/pay/download acknowledgement without visiting).
- **India specifics:** session `YYYY-YY`; category/quota (SC/ST/OBC/EWS) + caste cert; TC from previous school; **Aadhaar = data-minimization risk → store masked/last-4 or gated image, never as key, avoid Aadhaar-anchored dedup (DPDP).**

---

## 6. FEATURE COMPARISON MATRIX

| Capability | Current ZenX | ZenoxERP | Commercial practice | Gap | Priority |
|---|---|---|---|---|---|
| Enquiry stage | ✅ `crmInquiries` | ✅ VERIFIED | Standard | none | — |
| Registration/Application stage | ✅ `crmApplications` | ✅ VERIFIED | Standard | none | — |
| Approval gate before student | ✅ (CRM) / ❌ (walk-in) | NOT VERIFIED | Standard | walk-in bypass, no toggle | **P0** |
| Distinct admission no | ⚠ STU = de-facto | NOT VERIFIED | Two numbers | no separate admission-no | P2 |
| Idempotent app→student conversion | ❌ race | n/a | Expected | **duplicate-student race** | **P0** |
| Parent dedup / sibling linking | ❌ one login/child | NOT VERIFIED | SHOULD-HAVE | **no sibling model** | **P0** |
| Mid-session proration | ❌ bills 12 mo | OPTIONAL | Advanced | **over-billing** | **P0** |
| Duplicate detection | ⚠ public only | NOT VERIFIED | SHOULD-HAVE | none at counter/admin | **P1** |
| Registration fee + receipt | ✅ canonical | ✅ VERIFIED | MUST-HAVE | refund/void only | P2 |
| Document upload | ✅ counter only | ✅ VERIFIED | MUST-HAVE | parent can't upload; no verify status | **P1** |
| Document checklist + verify status | ❌ | NOT VERIFIED | SHOULD-HAVE | missing | P2 |
| Class/section capacity at admit | ⚠ CRM only | NOT VERIFIED | SHOULD-HAVE | absent in counter | **P1** |
| Final admission form / confirmation PDF | ❌ | ✅ (Entab) | SHOULD-HAVE | missing | **P1** |
| Parent app onboarding on admission | ✅ CRM / ❌ manual | ✅ VERIFIED | SHOULD-HAVE | no creds on walk-in | **P1** |
| Canonical Communication events | ⚠ SMS only | ✅ VERIFIED | SHOULD-HAVE | no in-app inbox | P2 |
| Admission dashboard/pipeline | ⚠ leads list | PARTIAL | SHOULD-HAVE | thin | P2 |
| Admission reports (register/class-wise/doc-pending/conversion) | ⚠ partial | ✅ VERIFIED | MUST/SHOULD | gaps | P2 |
| Separation of duties (RBAC) | ❌ one role set | NOT VERIFIED | SHOULD-HAVE | none | **P1** |
| Intra-tenant doc/PII isolation | ❌ over-broad | n/a | MUST-HAVE | **any login reads all** | **P0 (security)** |
| Online self-service intake | ✅ (form+pay) | PARTIAL | SHOULD-HAVE | no doc upload online | P2 |

---

## 7. MISSING FEATURES (confirmed gaps)
1. **Idempotent/atomic student creation** — no anti-double-submit or transaction boundary on `enroll_student`/`save_admission`.
2. **Parent-account dedup + sibling model** — no reuse of an existing parent; no multi-child aggregation.
3. **Mid-session fee proration / "Fee Applicable From"** on admission.
4. **Person-level duplicate detection** at admin create/convert/enroll and walk-in.
5. **Configurable Direct-vs-Approval admission mode.**
6. **Parent-side document upload** (online applicants cannot attach anything).
7. **Document verification lifecycle + configurable checklist** (Required/Received/Verified/Rejected).
8. **Live seat capacity in the counter form** + full-section guardrail.
9. **Final Admission Form PDF + Confirmation/offer letter** (filled, photo, declaration, checklist, signatures).
10. **Canonical Communication events** (application-submitted / payment-received / admission-confirmed / parent-invite in-app).
11. **Separation of duties** in admission RBAC.
12. **Admission dashboard/pipeline + conversion reporting**, plus register / class-wise / document-pending reports.
13. **Receipt void/cancel** path for erroneous admission receipts.

## 8. FEATURES TO RETAIN (do not redesign)
- The **three-stage collection separation** (`crmInquiries`→`crmApplications`→`students`) — clean and correct.
- The **registration/admission fee engine** — server-resolved amount, online+offline shared `record_payment`, idempotent accounting `JE_FEE_{receiptKey}`, bank-ledger validation, period-lock, `ADM######` atomic numbering.
- The **public online path's payment idempotency** (atomic claim) and its decision **not** to auto-create a student on payment.
- The **CRM enroll path's fail-loud primary write + fee-structure preflight** (make this the standard).
- The **`get_class_sections` capacity picker** (already built — just unused at the counter).
- **Document storage security** (MIME via `finfo`, quota, tenant path).
- **Global atomic `STU` counter** and **class/section normalization** (`Entity_firestore_sync`).
- **Lead→admission prefill continuity** and the **preview-before-submit modal**.
- **Teacher roster link** (works when roster fields are written).

## 9. FEATURES NEEDING IMPROVEMENT (why)
- **Two student-creation paths** → collapse to one idempotent primitive (removes the fail-soft/fail-loud asymmetry and the race).
- **Parent model** → introduce a real parent entity keyed by phone/email so siblings share one account (fixes duplicate accounts + phone-index clobber).
- **`buildAdmissionDemandSpecs`** → honor admission month / `proRateFees` (fixes over-billing).
- **Counter form** → wire capacity, add person-dedup, add post-save next-action, add missing professional fields, reconcile the two public forms.
- **Document module** → add verify-status/checklist + parent upload.
- **RBAC** → add a capability layer (approve ≠ create).
- **Security rules** → restrict `students` reads and admission-document reads to owner/staff.

## 10. FEATURES TO **NOT** ADD YET (avoid overengineering)
- Multi-round **merit lists / lottery / school-choice** engines.
- **Aadhaar-anchored deterministic dedup** and **full-Aadhaar plaintext** storage (DPDP risk).
- **AI application-fraud / lead-scoring.**
- **Heavy counselor CRM automation** (multi-portal lead sync, campaign sequences).
- A distinct **admission-number series** separate from STU — only if a school actually needs a register number distinct from the student ID; otherwise keep STU as the admission number.

## 11. DATA MODEL FINDINGS
- Collections are Firestore-only and tenant-scoped by exclusion (`crmApplications`/`admissionReceipts`/`parents`/`indexPhones` have no client rules → deny-all → server-only).
- **`parents/{schoolId}_{studentId}` with `childrenIds=[studentId]` is the root cause** of the no-sibling / duplicate-parent problem. Target: a parent doc keyed by a stable parent identity (normalized phone/email hash), `childrenIds[]` truly multi-valued, student→parent reference, and a **parent-scoped** `indexPhones` that appends rather than overwrites.
- `STU` is the de-facto admission number; adding a separate admission-number field is optional (P2), not required.

## 12. SECURITY FINDINGS
| Severity | Finding | Evidence |
|---|---|---|
| **HIGH** | `students` readable by **any** same-school login (parent/student), not just own child — intra-tenant PII (name/phone/address/parents). | `firestore.rules:264-276` (`isSameSchool()` only) |
| **HIGH** | **Any** same-school user can read **any** student's admission documents (Aadhaar, birth cert) under `schools/{schoolId}/students/{otherUid}/documents/`. | `storage.rules:192-197` (subtree-wide) |
| **MEDIUM** | No separation of duties — one `MANAGE_ROLES` set for create/approve/enroll/collect; doc verification has no dedicated gate. | `Sis.php:31` + action gates |
| **MEDIUM** | Public app id `APP_{hex}_{rand5}` relies on `microtime+rand` (weak, low-rate). | `Admission_public.php:399-403` |
| LOW | `Ats.php` (HR ATS, not students) still uses RTDB-path reads — Firestore-only violation in a sibling module. | `Ats.php:176,205` |
Good: cross-tenant isolation holds everywhere; payment verification is server-side; receipt/accounting idempotent.

## 13. ADMISSION → SIS INTEGRATION
Approved application → `enroll_student` → `saveStudent()`/`syncStudent()` writes the canonical `students` doc (normalized class/section, `status='Active'`, `schoolId`). This is the same collection manual-add and import write to — but via **three different functions**, non-transactional, and (for CRM) non-idempotent. **Target:** one `Admission_service::confirm()` primitive that all paths call, guarded by an idempotency key derived from the application id (or a walk-in draft id) so re-runs/double-clicks are safe.

## 14. FEES & ACCOUNTING INTEGRATION
- **Registration/admission fee:** canonical, correct, idempotent (`Admission_collection::record_payment` → `admissionReceipts` + `JE_FEE_{receiptKey}`). Retain as-is; add void/refund (P2).
- **Tuition fee demands on enrollment:** reuse the canonical Fees module (good), but **mid-session join billing is wrong** — bills all months. This is the single most financially material defect. Fix by passing admission month into `buildAdmissionDemandSpecs` and applying `proRateFees`/`Fee Applicable From`.

## 15. PARENT APP INTEGRATION
- Child resolves by `students/{schoolCode}_{UID}` where UID==studentId → each login shows exactly one child. **No sibling aggregation.** Activation is **automated on the CRM path** (credentials SMS) but **not on manual walk-in** (no credentials sent; admin hand-delivers). Target: shared parent account across siblings + automated invite on every path.

## 16. TEACHER APP INTEGRATION
Roster query matches on `schoolId + className + section + status=="Active"`; admin writes those fields, so a newly admitted+placed student appears. **Latent gap:** on the fail-soft `save_admission` path, a `syncStudent` failure yields a student with no `className/section/status` → invisible in the roster with no hard error. Teachers correctly do **not** get financial/document access.

## 17. COMMUNICATION INTEGRATION
Admission fires **bespoke SMS only** (`notify_admission_*`), not the canonical Communication inbox — parents get no in-app message for submitted/payment/confirmed. Target: emit canonical Communication events (reusing the existing infra) alongside SMS; no admission-specific messaging engine.

## 18. DOCUMENT / PDF INTEGRATION
Application acknowledgement + admission fee receipt exist and are decent (logo/meta/QR/PAID/signatory). **Missing:** blank printable application form, admission confirmation/offer letter, and a **filled Final Admission Form** (photo + declaration + document checklist + parent/principal signatures). This should be built on the **central Document Service** proposed in the separate Document-Engine audit — snapshot-immutable, numbered, QR-verifiable.

---

## 19. RECOMMENDED TARGET WORKFLOW

```
ADMISSION DASHBOARD (pipeline: enquiries / applications / docs-pending / approved / confirmed)
  │
  ├─ NEW WALK-IN ─────────────────────────────────────────────┐
  │    → SEARCH first (existing student / parent / application by name+DOB / phone / email)
  │        → match? → open existing / add-sibling to existing parent
  │        → no match → CREATE REGISTRATION (draft)
  │                                                            │
  ├─ ONLINE APPLICATION (parent self-service) ────────────────┤
  │    → form + document upload + consent → pay/registration fee
  │                                                            ▼
  │                                          crmApplications (draft/submitted)
  │                                          [APP number allocated]  ← duplicate-scan runs here
  │                                                            │
  │                        ┌──────── SCHOOL CONFIG ────────────┤
  │                        │  Mode A: Approval-based           │  Mode B: Direct admission
  │                        ▼                                    ▼
  │              Review → Docs verify (checklist)      (auto-approve under the hood)
  │              → Assessment (optional) → Approve            │
  │                        └──────────────┬─────────────────── ┘
  │                                       ▼
  │                         CONFIRM ADMISSION  (ONE idempotent primitive)
  │                          idempotency key = APP id / walk-in draft id
  │                            → allocate STU (admission no)
  │                            → capacity check (block/warn if section full)
  │                            → create canonical student (fail-loud)
  │                            → link/create ONE parent (dedup by phone/email; sibling-aware)
  │                            → apply fee rules (proration / Fee-Applicable-From)
  │                            → collect initial fee → receipt (Document Service)
  │                            → Firebase Auth + Parent App invite (all paths)
  │                            → canonical Communication event + SMS
  │                            → Final Admission Form PDF (snapshot, numbered, QR)
  │                            → entity sync → Teacher roster / Attendance / Exam eligibility
  ▼
DONE → next action: Print Final Form · Collect Fee · Open Student Profile · Admit Another
```

---

## 20. PRIORITIZED GAP LIST

**P0 — Production critical (integrity/security/financial):**
- Idempotent/atomic `CONFIRM ADMISSION` primitive (kills the duplicate-student race).
- Parent dedup + sibling model (kills duplicate parent accounts + phone-index clobber).
- Mid-session fee proration / Fee-Applicable-From (kills over-billing).
- Restrict `students` + admission-document reads to owner/staff (kills intra-tenant PII/doc leak).
- Direct-vs-Approval config gate (stop silent funnel bypass).

**P1 — Strong commercial requirement:**
- Person-level duplicate detection at counter + admin paths.
- Live seat capacity + full-section guard in the counter form.
- Final Admission Form PDF + Confirmation letter (via Document Service).
- Parent-app credentials/invite on **all** admission paths.
- Parent-side document upload + verification checklist/status.
- Separation of duties (capability layer: approve ≠ create ≠ collect).

**P2 — Valuable enhancement:**
- Admission dashboard/pipeline + conversion & document-pending reports.
- Canonical Communication events (in-app inbox).
- Reconcile/retire the legacy public form.
- Missing professional fields (permanent address, siblings, transport/hostel opt-in, medical, referral).
- Receipt void/cancel; distinct admission-number (only if register-number needed).

**P3 — Future / optional:**
- Assessment/interview scheduling; merit list/waitlist rounds; multi-source lead capture; template-designer for admission forms.

---

## 21. IMPLEMENTATION ROADMAP (production-safe, incremental)

> Ordered so integrity/security/financial correctness land first; UX and reporting later. Each phase is independently shippable and reversible. Phase 0 (this audit) done.

**Phase 1 — Unify + idempotent CONFIRM ADMISSION.** *Problem:* duplicate-student race + fail-soft/fail-loud asymmetry. *Files:* `Sis.php` (`save_admission`, `enroll_student`), new `Admission_service`/reuse `Fee_lifecycle`. *Firestore:* idempotency-claim doc keyed by application/draft id; no schema change to `students`. *Storage/Rules:* none. *Admin:* both paths call one primitive. *Parent/Teacher:* none (behaviour-preserving). *Fees/Accounting:* unchanged. *Tests:* concurrent/double-click enroll → exactly one student. *Accept:* no duplicate student/Auth/fees under double submit. *Rollback:* revert to per-path. *Risk:* **MEDIUM.**

**Phase 2 — Mid-session fee correctness.** *Problem:* all-months over-billing. *Files:* `Fee_lifecycle.php` (`buildAdmissionDemandSpecs`, wire `proRateFees`). *Firestore:* none (demand shape unchanged). *Admin:* admission passes join month / "Fee Applicable From". *Fees/Accounting:* correct demands. *Tests:* Nov admission bills Nov→Mar (+ annual per policy), not Apr→Mar. *Accept:* no prior-month demands unless policy says so. *Risk:* **MEDIUM** (financial — needs school-policy config for annual/one-time heads).

**Phase 3 — Parent identity + siblings.** *Problem:* duplicate parent accounts, phone-index clobber, no sibling view. *Files:* `Entity_firestore_sync.php` (parent doc), `Sis.php` creation paths, Parent app child-list. *Firestore:* parent doc keyed by normalized phone/email; `childrenIds[]` multi; `indexPhones` append not overwrite. *Parent app:* multi-child switcher. *Migration:* backfill/merge existing per-child parent docs (careful, staged). *Tests:* 2nd child reuses parent; siblings both resolve. *Risk:* **HIGH** (identity + migration + Parent app).

**Phase 4 — Security rule tightening.** *Problem:* intra-tenant PII/doc reads. *Files:* `firestore.rules` (`students` → owner/staff), `storage.rules` (per-student owner on documents). *Apps:* verify Parent/Teacher reads still authorized (staff + own child). *Tests:* parent A cannot read student B's doc/record. *Risk:* **MEDIUM** (must not break legitimate reads).

**Phase 5 — Duplicate detection + Direct/Approval config.** *Files:* `Sis.php` create/convert/enroll (add name+DOB+phone soft-match warning), `School_config.php` (`admission_mode` flag). *Firestore:* config field on `schools`. *Admin:* dedup warning UI; mode toggle. *Risk:* **MEDIUM.**

**Phase 6 — Counter form UX.** *Problem:* no capacity, dead-end, missing fields. *Files:* `studentAdmission.php` (+ wire existing `get_class_sections`), post-save next-action, add fields, reconcile/retire legacy public form. *Risk:* **LOW–MEDIUM.**

**Phase 7 — Documents: parent upload + verification checklist.** *Files:* `Admission_public.php` (+file inputs, server-side upload), `Sis.php` doc-status, views. *Storage/Rules:* applicant-scoped path + rules. *Risk:* **MEDIUM.**

**Phase 8 — Admission PDFs via Document Service.** *Problem:* no final form/confirmation. *Depends on:* the Document-Engine audit's `Document_service`. *Files:* new templates + issuance. *Risk:* **MEDIUM.**

**Phase 9 — Communication + RBAC separation of duties.** *Files:* emit canonical Communication events in admission paths; capability layer for approve/create/collect. *Risk:* **MEDIUM.**

**Phase 10 — Dashboard + reports + certification.** *Files:* admission pipeline view, register/class-wise/doc-pending/conversion reports; full cross-surface certification. *Risk:* **LOW–MEDIUM.**

*(Each phase at kickoff gets the full gate: files, Firestore/Storage/Rules deltas, per-app impact, Fees/Accounting/Communication impact, migration + backward-compat, tests, acceptance, rollback.)*

---

## FINAL QUESTIONS

**Q1 — Can current ZenX professionally handle a walk-in end-to-end? → PARTIALLY.**
It *can* carry a parent from registration → (approval) → enrolled student → fee demands → registration-fee receipt → parent SMS, and the online path + fee/accounting engine are genuinely production-grade. But it is **not** professional-safe because: mid-session joins are **over-billed**; a second child creates a **duplicate parent account** with a clobbered phone index; `enroll_student` can create **duplicate students** on a double-click; the counter has **no capacity view, weak duplicate detection, and dead-ends after save**; and any same-school login can read **other students' records and documents**. These are exactly the integrity guarantees a commercial ERP must not miss.

**Q2 — Top 10 gaps, ranked by business importance:**
1. Mid-session fee over-billing (financial correctness).
2. Duplicate-student race on enroll (data integrity).
3. Duplicate parent accounts / no sibling model (identity integrity + parent UX).
4. Intra-tenant over-broad reads of student records & documents (security/DPDP).
5. No person-level duplicate detection at counter/admin (data integrity).
6. Direct admission bypasses approval with no config gate (governance).
7. No final admission form / confirmation letter (professional output).
8. Counter UX: no capacity, dead-end, missing fields (operator efficiency).
9. Parent-app credentials not sent on manual admission (onboarding).
10. No separation of duties in admission RBAC (control).

**Q3 — ZenoxERP-observed capabilities worth adopting (verified only):** enquiry **follow-up CRM** (dated interaction log + auto reminders); **credentials issued on admission** (ZenX does this only on the CRM path — extend to all); **WhatsApp** as a channel alongside SMS; **admission reports** (register/receipts/stats). *Governance features (approval/dedup/sibling/verification) are NOT verified in ZenoxERP — adopt them from commercial practice, not as "ZenoxERP has it."*

**Q4 — Competitor features NOT to copy (unnecessary complexity):** multi-round merit lists / lottery / school-choice engines; multi-source ad-portal lead sync; Aadhaar-anchored deterministic dedup + full-Aadhaar storage (DPDP risk); heavy counselor-CRM campaign automation; AI application-fraud scoring.

**Q5 — Separate Enquiry / Registration / Admission stages? → YES, keep all three — ZenX already has them.** The correct architecture is to **retain the three stages** but make progression **optional/config-driven**: a school can run the full funnel (approval mode) or a one-screen direct admission (walk-in mode). The problem is not missing stages — it's that the direct path *bypasses* them with no gate.

**Q6 — Support both Direct Admission and Application→Approval→Admission? → YES, without duplicating student logic** — by routing **both** through **one `Admission_service::confirm(applicationRef)` primitive**. Direct admission simply creates an application record that is **auto-approved** in the same transaction, then calls the identical confirm primitive. There is exactly one student-creation code path; "direct vs approval" only changes whether a human approval step precedes the shared confirm.

**Q7 — What should `CONFIRM ADMISSION` do technically?** (target idempotent cross-module transaction)
```
CONFIRM ADMISSION(applicationRef, actor)
  0. AuthZ: actor has capability admission.confirm; tenant + session scope check
  1. Idempotency: claim doc admissionConfirm/{schoolId}_{applicationRef} (CAS create-if-absent)
       → if already claimed/enrolled → return existing studentId (NO new writes)
  2. Guard: application.status == approved (or direct-mode auto-approve within this txn)
  3. Capacity: check section seats → block/warn per policy
  4. Allocate admission no: STU#### via atomic Id_generator (only after claim)
  5. Parent resolve: find existing parent by normalized phone/email
       → exists → attach child to childrenIds[]  |  else → create ONE parent
  6. Create canonical student (fail-loud): students doc, normalized class/section, status=Active
  7. Indexes: append (not overwrite) phone→children; student index
  8. Fees: apply structure with Fee-Applicable-From / proration → demands (idempotent by periodKey)
  9. Initial fee (if collected now): Admission_collection.record_payment → receipt + JE_FEE_{key}
 10. Auth + Parent App invite (all paths); canonical Communication event + SMS
 11. Final Admission Form PDF (Document Service: snapshot, numbered, QR)
 12. Entity sync → Teacher roster / Attendance / Exam eligibility
 13. Mark application enrolled + student_id; release claim as committed
  ── any step failure after 6: fail-loud, surface to operator, safe to re-run (idempotent)
```

**Q8 — After successful admission, does the student appear everywhere? (with broken links flagged)**
| Surface | Appears? | Note |
|---|---|---|
| SIS (`students`) | ✅ | canonical doc written |
| Class/Section | ✅ | normalized fields — **but** fail-soft `save_admission` can leave them blank (roster-invisible) |
| Fees | ✅ demands generated | **but wrong amounts for mid-session joins** |
| Accounting | ✅ (registration fee) | idempotent journal; tuition demands post per Fees |
| Parent App | ✅ per child | **no sibling aggregation**; **no credentials on manual add** |
| Teacher roster | ✅ | latent gap on fail-soft path |
| Attendance eligibility | ✅ | via `status=Active` + class/section |
| Transport eligibility | ⚠ | **not captured at admission** (no transport opt-in field) — separate setup |
| Examination eligibility | ✅ | via class/section |
**Broken/weak links:** duplicate parent + phone-index clobber (§2); mid-session fee amounts (§3); `save_admission` roster-invisible risk (§1/§16); credentials not sent on manual add (§5); transport not captured at admission.

---

## PRODUCTION PRINCIPLES — COMPLIANCE SCORECARD
| Principle | Status |
|---|---|
| One Student — One Canonical Identity | ⚠ at risk (enroll race) → **fix Phase 1** |
| One Parent — Multiple Children | ❌ violated (one login/child) → **fix Phase 3** |
| One Approved Admission — One Idempotent Creation Path | ❌ violated (3 paths, non-idempotent) → **fix Phase 1** |
| One Canonical Firestore Source of Truth | ✅ (admission core) |
| Zero RTDB | ✅ admission (⚠ `Ats.php` HR module out of scope) |
| No Duplicate Students | ⚠ race → Phase 1 |
| No Duplicate Parent Accounts | ❌ → Phase 3 |
| No Duplicate Admission Numbers | ✅ atomic STU |
| No Duplicate Fee Demands | ✅ re-run dedup (⚠ wrong amounts) → Phase 2 |
| No Admission-Specific Duplicate Payment Engine | ✅ shared engine |
| No Admission-Specific Duplicate Communication Engine | ⚠ bespoke SMS → Phase 9 |
| Professional Historical Documents | ⚠ receipts ok, no final form → Phase 8 |
| Secure Multi-Tenant Isolation | ⚠ intra-tenant leak → Phase 4 |
| Least-Privilege Access | ❌ no separation of duties → Phase 9 |
| Cross-Platform Consistency | ⚠ manual vs CRM asymmetry → Phase 1/5 |
| Incremental Production-Safe Implementation | ✅ (this roadmap) |

**Nothing will be implemented until you review and approve this audit and the proposed architecture.**
