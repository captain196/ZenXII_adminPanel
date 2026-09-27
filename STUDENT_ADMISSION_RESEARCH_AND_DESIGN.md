# Student Admission — Research, Forensic Audit & Advanced Target Design

**Feature under study:** Student Admission (enquiry → application → selection → enrolment)
**Surfaces:** Admin Web (CI3, `c:\xampp\htdocs\ZenX\school`) · Teacher App (`D:\Projects\SchoolSyncTeacher`) · Parent App (`D:\Projects\SchoolSyncParent`)
**Date:** 2026-08-06 · **Branch:** `ank-yug_b1`
**Status:** RESEARCH + DESIGN ONLY. No code written, no files mutated outside this report. Implementation is a separate, explicitly authorized step.

**Interactive prototype:** https://claude.ai/code/artifact/929f9324-c6fb-4586-8775-5c6f91a0c4dd

---

## Executive summary

1. ZenXII's admission **transaction core is genuinely strong** — arguably the most hardened module in the codebase. Idempotent enrolment, atomic ID allocation, CAS-atomic history, one shared receipt+accounting path, fail-closed session resolution, Document-Service-backed uploads.
2. What is weak is everything **around** the transaction: selection, seats, scheduling, communication, and the parent's view of their own application.
3. **Seat limits are configured but never enforced** — `class_limits` is written by the settings UI and read by nothing (`Sis.php:7618` is the only reference in the codebase). `enroll_student` (`Sis.php:6937`) checks fee structure but not section capacity.
4. **There is no admission/GR number.** `Certificates.php:383` prints `$student['Admission Number']` with a fallback to the internal `User Id`; nothing anywhere writes that field. Every TC in India therefore carries a fabricated admission number.
5. **The dashboard and analytics screens still full-scan.** C7 paginated the grids but `crm_dashboard` (`:6024`), `admission_analytics` (`:5939`) and `fetch_analytics` (`:7734`) all call `_crm_list()` — three unbounded session scans per load.
6. **Firestore index drift is a deployment landmine.** `firebase-rules/firestore.indexes.json` contains **zero** `crmApplications` entries; the nine indexes `_crm_page()` depends on exist only in the live project.
7. **The parent has no way to track their application.** Only `payment_status` (`Admission_public.php:1106`) is exposed, keyed by payment id. Everything else is SMS.
8. **Both Kotlin apps declare `admissionConfig` / `admissionApplications` / `admissionMeritLists` constants that point at collections which do not exist** and have zero consumers. Dead, misleading, and a trap for the next developer.
9. **Recommendation:** do not rebuild. Ship three additive phases — *Seats & Integrity* (closes the real defects), *Selection & Scheduling* (closes the competitive gap), *Parent Tracker* (the differentiator). Leave the transaction core untouched.
10. Cross-module verdict: **CLEAR to design.** No existing dependency is broken by the proposed shape; every change is additive. Details in Phase 6.

---

## Phase 0 — Clarifying questions

These would materially change the design. **I have not blocked on them** — working assumptions are boxed below and the design proceeds on them.

| # | Question | Why it changes the design |
|---|---|---|
| Q1 | Is **RTE / EWS 25% quota** administration in scope? | Quota needs a separate seat pool, a published lottery, and a state-reportable register. It roughly doubles the seat model. |
| Q2 | Is the **admission/GR register number** a statutory requirement for your target states? | If yes, it is a Phase-1 blocker, not an enhancement — it needs an immutable, gapless, per-school series and back-population. |
| Q3 | Should the **Parent App tracker authenticate pre-enrolment** (APP-id + OTP), or should tracking stay web-only on the public link? | Pre-enrolment auth in the app means a new claim shape and a new rules predicate. Web-only is far cheaper. |
| Q4 | Is **multi-session admission** required (accepting 2026-27 applications while 2025-26 runs)? | Today `_crm_list` and every filter hard-scope to `session_year`. Forward-session intake needs an explicit `intakeSession` field distinct from the operating session. |
| Q5 | Do you want **entrance-test scoring inside ZenXII**, or is an imported score sufficient? | In-app scoring pulls the Teacher App into admission for the first time (new screen, new permission, new offline queue). |

> ### Working assumptions (design proceeds on these)
> - **A1** RTE/EWS is **modelled but not automated** — a quota flag, a separate seat pool and an exportable register; no lottery engine in this scope.
> - **A2** The admission/GR number **is** required. It is included in Phase 1 as a defect fix, not an enhancement.
> - **A3** Parent tracking ships **web-first** on the existing public link (no new auth), with the Parent App screen reading the same endpoint once the parent is enrolled. Pre-enrolment in-app auth is deferred.
> - **A4** Single active intake session only, matching current behaviour.
> - **A5** Entrance-test scoring is **in scope** as a Teacher App screen, because it is the only credible reason for a teacher to touch admission at all.
> - **A6** Enterprise RBAC Model D does **not** exist. Permissions map onto today's role-name arrays (`Sis::MANAGE_ROLES` / `VIEW_ROLES`, `Sis.php:31-34`).

---

## Phase 1 — Competitive / market research

### Reference matrix

| ERP | How they model it | Notable capabilities | Admin UX pattern | Mobile / parent exposure | What they get wrong |
|---|---|---|---|---|---|
| **Edunext** | Enquiry → online/offline registration → selection → admission, as distinct tracked objects | Website enquiry-form integration with automated data transfer; automated SMS/email follow-up; class-wise **capacity / current / vacant seat view**; document-submission-pending report; fee-payment report incl. pending; applicant self-service status tracking | Report-led. Long tabular registers with heavy filtering; the selection screen is a criteria-driven list | Applicants track status online; parent-facing status page | Report-first UX means seat truth lives on a screen the operator must remember to open, separate from where the decision is made |
| **Fedena** | Admission enquiry + customisable admission form as first-class configurable objects | Customisable admission forms; digital document verification; enquiry tracking; subject-based registration; SMS/email contact from within the record; publishable online registration form | Configuration-led. Very deep form-builder; module breadth over workflow depth | Online form on the institute website; applicant communication by SMS/email | Configurability without opinion — schools can build a form the downstream SIS cannot consume cleanly `[inferred]` |
| **Classe365** | Admission as **CRM**: leads → opportunities → enrolment, with SIS/LMS in one platform | Lead nurture with one-click SMS/email/call; enquiry analysis by course/status/territory/custom field; conversion-rate reporting; paperless application with online fee payment; automatic SIS update on enrolment | Sales-pipeline UX. Kanban + lead detail, borrowed wholesale from sales CRM | Dedicated parent and student portals with real-time dashboards | Sales metaphor imported without adaptation. Lead scoring and nurture cadence are ethically wrong for K-12 child admission |
| **Teachmint** | Unified platform; admission alongside enquiries/complaints, timetable, hostel, HR | Admission management with enquiry and complaint handling in one flow | Mobile-first, teacher-centric | Strong app-side presence across roles | Admission depth is shallower than the SIS-first vendors `[inferred]` |
| **Vidyalaya** | Admission module with school-defined selection criteria | Schools define their own criteria — written exam, aptitude test, or interview | Form + criteria configuration | `[inferred]` | Criteria configuration without an execution surface (scheduling, capacity, scoring) `[inferred]` |
| **Edvio / category pattern** | 6-step parent-filled digital form | Enquiry capture → interview scheduling → 6-step form with document upload → fee collection → **auto-generated GR/ADM number**; claims 30 min → under 10 min per student | Wizard-led parent form, staff review queue | Parent fills the form themselves | Wizard abandonment is high when documents are demanded up-front `[inferred]` |
| **PowerSchool / Veracross** | Admission as a full enrolment-management product with rounds, decisions and yield | Decision rounds, offer/acceptance tracking, yield analytics `[inferred]` | Round-based, cohort-oriented | Family portal `[inferred]` | US independent-school model; rounds and yield do not map to Indian rolling admission |

**Regulatory context (India, verified):** RTE §12(1)(c) requires private unaided non-minority schools to reserve **25% of entry-level seats** for weaker/disadvantaged sections, allocated by a **transparent lottery**. Delhi operates a published **points system** (proximity, sibling, alumni) with a draw of lots for ties. The Supreme Court has directed that preference criteria and the draw process be laid out in **simple, clear, widely published terms**. Any credible Indian K-12 admission module must be able to *print the basis of every decision.*

### Capability inventory

**Table stakes** — absence is a defect
- Enquiry capture (walk-in + web) with source attribution
- Online application form, public, no login
- Document upload + verification with a pending-documents view
- Application fee / registration fee collection, online and at counter, with a receipt
- Approve / reject / waitlist with a recorded reason
- One-click conversion to an enrolled student, with fee structure and login provisioned
- **Class-wise seat capacity: sanctioned / filled / vacant** — and enforcement
- **Admission (GR) number** allocated at enrolment, immutable, printed on every certificate
- Duplicate detection
- Audit trail of every status transition
- Applicant status tracking for the parent

**Competitive** — expected in a paid product
- Configurable pipeline stages, per-class required documents, per-class fee
- Entrance test / interview **scheduling with slots and capacity**, plus score capture
- Merit list generation from published criteria, exportable
- Offer letter with expiry, accept/decline, and automatic seat release on lapse
- Automated reminders: pending documents, unpaid fee, unaccepted offer — with escalation
- Bulk operations (bulk shortlist, bulk offer, bulk reject) with partial-failure reporting
- Funnel analytics with stage-wise drop-off and source quality
- Sibling and alumni linkage
- Admission window open/close per class

**Differentiator** — where ZenXII can win
- **Publishable decision basis.** Merit list that prints the criteria and weights actually applied, court-direction-ready. No competitor in the reference set demonstrably ships this.
- **Pre-flight gates on every irreversible action.** ZenXII already blocks enrolment on a missing fee structure (`Sis.php:7004`). Generalising that into a visible pass/fail checklist *before* the button activates is a genuinely differentiated interaction, not a feature bullet.
- **Seat truth inside the decision.** Not a report — a hard block at the moment of offer, computed live from `students`, not a cached counter.
- **Anonymised assessment on the Teacher App.** Scores entered against roll numbers, never names or fee status. Removes the single biggest fairness objection to teacher-scored admission.
- **Offline-tolerant score entry.** Neither reference vendor demonstrably handles an invigilator on a dead-zone school Wi-Fi.
- **True cross-surface continuity.** The same application object visible to admin, panel teacher and parent, each seeing exactly their slice.

**Anti-features** — deliberately not copied
| Anti-feature | Who ships it | Why we refuse |
|---|---|---|
| Lead scoring on demographic/behavioural signals | Classe365-style education CRM | Legally and ethically indefensible for child admission in India. Scoring is permitted **only** on published criteria. |
| Automated nurture cadences / drip campaigns | Education CRM vendors | Turns parents into a marketing funnel. Reminders are transactional and capped. |
| Auto-reject on document or response timeout | Common CRM automation | Silently ends a child's application over a slow scan. Must escalate to a human instead. |
| Unbounded form builder | Fedena-style configurability | Produces applications the SIS cannot consume. Fields are extensible from a governed catalogue, not free-form. |
| Decision rounds / yield management | PowerSchool, Veracross | US independent-school model. Indian K-12 is rolling; rounds add ceremony with no benefit. |
| In-module chat with applicants | Several | A second inbox nobody monitors. SMS + a phone number is the honest answer. |

---

## Phase 2 — Forensic audit of what ZenXII has

All claims below were read from source in this session.

### 2.1 Entry points

**Admin Web** — the module is consolidated into `Sis.php` (7,925 lines) plus one public controller.

| Surface | File:line |
|---|---|
| Routes — CRM | `application/config/routes.php:520-616` |
| Routes — public admission | `application/config/routes.php:617-628` |
| Routes — admission payment config | `application/config/routes.php:372-373` |
| CRM dashboard | `application/controllers/Sis.php:6024` |
| Inquiries (list/save/delete/convert) | `Sis.php:6079`, `:6089`, `:6105`, `:6149`, `:6162` |
| Applications (list/fetch/save/get) | `Sis.php:6197`, `:6207`, `:6227`, `:6284` |
| Audit trail for one application | `Sis.php:6303` |
| Offline fee collection | `Sis.php:6323` |
| Receipt / poster | `Sis.php:6454`, `:6491` |
| Pipeline (Kanban) + per-column paging | `Sis.php:6584`, `:6596`, `:6620`, `:6647` |
| Stage / approve / reject | `Sis.php:6660`, `:6686`, `:6711` |
| Document verify / replace / delete | `Sis.php:6819`, `:6854`, `:6899` |
| **Enrolment** | `Sis.php:6937` |
| Auth repair | `Sis.php:7335` |
| Section capacity lookup | `Sis.php:7417` |
| Waitlist | `Sis.php:7496`–`:7600` |
| CRM settings | `Sis.php:7602`, `:7613`, `:7631` |
| Legacy logged-in online form | `Sis.php:7640`, `:7651` |
| Analytics | `Sis.php:5939`, `:7734` |
| Leads views | `Sis.php:5810`–`:5937` |
| Public form render / submit | `application/controllers/Admission_public.php:91`, `:174` |
| Public uploads | `Admission_public.php:576` |
| Offline-payment choice | `Admission_public.php:696` |
| Payment initiate / callback / webhook / status | `Admission_public.php:780`, `:971`, `:1301`, `:1106` |
| Receipt endpoints | `Admission_public.php:1799`, `:1836` |
| Admission fee config | `application/controllers/School_config.php:4758`, `:4816` |
| Shared receipt + accounting | `application/libraries/Admission_collection.php:79` |
| Fee resolver, history caps | `application/helpers/admission_crm_helper.php:31`, `:21-29` |
| Search tokenizer | `application/helpers/admission_search_helper.php` |

Views: `application/views/admission_crm/{index,inquiries,applications,pipeline,waitlist,settings,online_form,poster}.php`, `application/views/sis/{admission_leads,admission_analytics}.php`, `application/views/admission/{public_form,receipt_pdf,fee_receipt}.php`, `application/views/school_config/admission_payment.php`.

**Teacher App** — no admission entry point exists. Matches are the student field `admissionDate` only (`StudentDoc.kt:59`, `StudentsScreen.kt:621`).

**Parent App** — no admission entry point exists. Matches are `admissionDate` only (`StudentDoc.kt:66`, `ProfileScreen.kt:323`) and a fee-name string test (`FeesScreen.kt:629`).

### 2.2 Data model in use

| Collection | Doc id | Written by | Read by |
|---|---|---|---|
| `crmInquiries` | `{schoolId}_{INQ#####}` | `Sis::save_inquiry`, `convert_to_application` | `_crm_list` (dashboard, analytics, inquiries grid) |
| `crmApplications` | `{schoolId}_{APP#####}` (admin) / `{schoolId}_APP_{hex}_{rand5}` (public) | `Sis::save_application`, `convert_to_application`, all CAS transitions, `Admission_public::submit`, `Admission_collection::append_timeline` | `_crm_page`, `_crm_list`, `_crm_get`, pipeline columns, `fs->count` aggregations |
| `crmWaitlist` | `{schoolId}_{WL#####}` | `add_to_waitlist` | `fetch_waitlist`, dashboard |
| `crmSettings` | `{schoolId}_config` | `save_crm_settings` | `_crm_get_settings` (stages only) |
| `crmRateLimits` | `{schoolId}_{ipKey}` | `Admission_public::submit` | same |
| `rateLimits` | `online_form_{ipKey}` | `Sis::submit_online_form` | same |
| `admissionReceipts` | `{schoolId}_ADM######` | `Admission_collection::record_payment` | receipt endpoints, accounting reconciler |
| `admissionPayments` | `{schoolId}_{paymentId}` | payment callback/webhook | `payment_status` |
| `admissionSlugs` | `{slug}` (global) | `ensure_admission_slug` | `_resolve_admission_ref` |
| `documents` / `documentLinks` | Document Service | Document Service only | Document Service only |
| `students`, `indexPhones`, `studentHistory`, `feeDemands`, `parents` | — | written by `enroll_student` downstream | SIS, Fees, both apps |

`crmApplications` document shape (from `Admission_public.php:431-514` and `Sis.php:6270-6273`): ~40 flat fields plus `history[]` (capped 50), `timeline[]` (capped 20), `documents[]`, `documentRefs[]`, `searchTokens[]`. Tenant field `schoolId` is injected by `Firestore_service::setEntity` and the doc id is prefixed — **double-scoped**, which is correct.

**Notable schema honesty problems**
- `status` conflates lifecycle position with terminal outcome. Legacy spellings (`new`/`pending`, `contacted`/`interested`, `enrolled`/`admitted`) have to be folded back together at read time — see the bucket map at `Sis.php:5850-5856` and the duplicate counting at `:5873-5876`.
- `stage` and `status` are written together on every transition (`:6704`, `:6729`, `:7180`) so they cannot diverge — but they encode the same fact twice.
- `possible_duplicate` (`Admission_public.php:492`) is set once at submission and never recomputed or cleared by any code path.
- Public-form app ids (`APP_{hex}_{rand5}`, `Admission_public.php:411`) and admin app ids (`APP00001`, `_crm_next_id`) are **two different id shapes in one collection**. The search regex at `:5665` handles both, but they sort differently and read differently to an operator.

### 2.3 Read / write trail

**Writers into `crmApplications`:** `Sis::save_application`, `convert_to_application`, `update_stage`, `approve_application`, `reject_application`, `enroll_student`, `add_to_waitlist`, `remove_from_waitlist`, `promote_from_waitlist`, `update_lead_status`, the three document-lifecycle actions, `Admission_public::submit`, `_apply_admission_payment_paid`, `Admission_collection::append_timeline`. **Every mutation after M7-B routes through `casAppendBounded`** — concurrency-safe by construction.

**Readers:** admin grids/pipeline/dashboard/analytics/leads, `admission_audit_trail`, `Admission_collection`, `Admission_public` receipt endpoints. **No Kotlin reader on either app** (verified by grep across both repos — matches are `admissionDate` only).

**Downstream of enrolment** (`Sis.php:7113-7264`): `students` → `documentLinks` (link, not copy) → `indexPhones` → `Students_Index` → `monthFee` → `crmApplications` (mark enrolled) → `auditLogs` → Firebase Auth → SMS → `Fee_lifecycle::assignInitialFees` → `entity_sync.syncStudent`/`syncParent` → `studentHistory`. Both Kotlin apps consume the *tail* of this chain (`students`, fee demands), never the head.

**Cron:** none. `scripts/cron.txt` schedules only `reconcile` and `integrity` for fees. No admission job exists.

**Rules:** `crmApplications`, `crmInquiries`, `crmWaitlist`, `crmSettings`, `crmRateLimits`, `admissionReceipts`, `admissionPayments` have **no `match` block** in `firebase-rules/firestore.rules`, so they fall to the catch-all `allow read, write: if false` at `firestore.rules:2009-2011`. Server-only via Admin SDK. **This is correct and is the strongest tenant guarantee in the module** — a client literally cannot reach these collections.

### 2.4 Current capability level vs Phase 1 inventory

Strong: enquiry capture, public form, uploads via Document Service, fee collection (online + counter, one shared path), approve/reject/waitlist, one-click enrolment, duplicate detection, full audit trail, configurable stages, server-side pagination on grids.

Absent: seat enforcement, GR number, test/interview scheduling, merit list, offer letter + expiry, reminders, bulk operations, sibling/alumni linkage, admission window, parent tracker, source quality analytics.

### 2.5 Known defects / drift

| # | Finding | Class | Evidence |
|---|---|---|---|
| D1 | `class_limits` written by settings UI, read by nothing | Bug (dead config) | Only reference outside the view is `Sis.php:7618` |
| D2 | `notifications` settings likewise never read | Bug (dead config) | Only references are in `settings.php:183-191` |
| D3 | No admission/GR number; certificates print the internal STU id | Bug | `Certificates.php:383`, `:459` read `Admission Number`; no writer exists anywhere |
| D4 | `enroll_student` does not check section capacity | Weakness | `Sis.php:6937-7011` checks fee structure only; `get_class_sections` (`:7417`) is advisory UI |
| D5 | Dashboard + both analytics endpoints full-scan the session | Weakness (perf) | `_crm_list` at `:6029-6031`, `:5945`, `:7737-7739` |
| D6 | `_get_crm_classes` scans `sections` across **all** sessions | Weakness (perf) | `Sis.php:7902` — `schoolWhere('sections', [])`, no session filter |
| D7 | Zero `crmApplications` composite indexes in the repo index file | Weakness (deploy) | `grep crmApplications firebase-rules/firestore.indexes.json` → no match; code depends on indexes #1–#9 (`Sis.php:5648`, `:5689`, `:6602`) |
| D8 | Two public-submission implementations | Weakness | `Admission_public::submit` (`:174`) and `Sis::submit_online_form` (`:7651`) — divergent validation, divergent rate-limit collection (`crmRateLimits` vs `rateLimits`) |
| D9 | Public app-id generation is time+random, not allocator-backed | Weakness | `Admission_public.php:411` — comment concedes "collision-safe at public-form rates" |
| D10 | `crmRateLimits` / `rateLimits` docs accumulate forever | Weakness | No GC anywhere; one doc per IP per school, permanently |
| D11 | Two id shapes in one collection | Improvement | `APP00001` vs `APP_1A2B3C_04711` |
| D12 | `status` conflates stage and outcome; legacy spellings folded at read | Improvement | `Sis.php:5850-5856`, `:5873-5876` |
| D13 | `possible_duplicate` never recomputed or clearable | Improvement | Set at `Admission_public.php:492`; no writer clears it |
| D14 | Enrolment returns success when Firebase Auth creation fails | Weakness (accepted) | `Sis.php:7278-7289`; explicitly documented as preserved at `:7200-7201`, mitigated by `repair_student_auth` |
| D15 | Both Kotlin apps declare admission collection constants that do not exist and are unused | Weakness (trap) | Parent `Constants.kt:295-298`, Teacher `Constants.kt:84-87` — zero consumers in either repo |
| D16 | `submit_online_form` fails **open** on rate-limit error | Weakness | `Sis.php:7670` — "Fail-open: allow submission if rate-limit check fails" |
| D17 | No parent-facing application status endpoint | Missing capability | Only `payment_status` (`Admission_public.php:1106`), keyed by payment id |
| D18 | Section resolution at enrolment picks *first alphabetical*, not emptiest | Improvement | `Sis.php:6970-6990` sorts and takes `[0]`; the smarter suggestion at `:7474-7486` is used only by the UI |

**RTDB residue: none.** Every path in the module is Firestore-only. The legacy `$this->crm_base` string at `Sis.php:51` is assigned and never used.

### 2.6 Coverage matrix

| Capability | Admin Web | Teacher App | Parent App | Firestore / rules | Verdict |
|---|---|---|---|---|---|
| Enquiry capture + source | Present-and-solid | n/a | n/a | Server-only, scoped | **Present-and-solid** |
| Public online form | Present-and-solid | n/a | Missing | Server-only | **Present-and-solid** (admin), **Missing** (app) |
| Duplicate detection | Present-but-weak | n/a | n/a | `phone_norm` equality | **Present-but-weak** |
| Document upload + verify | Present-and-solid | n/a | Missing | Document Service, deny-all | **Present-and-solid** |
| Application fee (online + counter) | Present-and-solid | n/a | Missing | `admissionReceipts` scoped | **Present-and-solid** |
| Approve / reject / waitlist | Present-and-solid | n/a | n/a | CAS-atomic | **Present-and-solid** |
| Enrolment → student | Present-and-solid | n/a | n/a | Idempotent, fail-loud | **Present-and-solid** |
| Audit trail | Present-and-solid | n/a | n/a | `auditLogs` + archive | **Present-and-solid** |
| Configurable stages | Present-and-solid | n/a | n/a | `crmSettings` | **Present-and-solid** |
| **Seat capacity enforcement** | Broken (config exists, unread) | n/a | n/a | none | **Broken** |
| **Admission / GR number** | Missing (read, never written) | n/a | n/a | none | **Broken** |
| Test / interview scheduling | Missing (stage label only) | Missing | Missing | none | **Missing** |
| Merit list / criteria | Missing | n/a | n/a | none | **Missing** |
| Offer letter + expiry | Missing | n/a | Missing | none | **Missing** |
| Automated reminders | Missing (config unread) | n/a | Missing | no cron | **Missing** |
| Bulk operations | Missing | n/a | n/a | none | **Missing** |
| Funnel analytics | Present-but-weak (full-scan) | n/a | n/a | no aggregates | **Present-but-weak** |
| Sibling / alumni linkage | Missing | n/a | n/a | none | **Missing** |
| Admission window per class | Missing | n/a | n/a | none | **Missing** |
| Parent application tracking | n/a | n/a | Missing | none | **Missing** |
| Index deployment parity | Broken | n/a | n/a | 0 of 9 in repo | **Broken** |

---

## Phase 3 — Gap analysis and classification

Ranked: production risk → user impact → effort.

| Rank | Gap | Class | Risk | Why |
|---|---|---|---|---|
| 1 | Index drift (D7) | **Weakness** | High | A clean-environment `firebase deploy --only firestore:indexes` breaks admission search, pagination and the Kanban. Fix is additive and near-zero effort. |
| 2 | No GR/admission number (D3) | **Bug** | High | Every TC and certificate prints the wrong number today. Statutory exposure. Fix is small but must be done before more data accumulates. |
| 3 | Seat limits configured but unenforced (D1, D4) | **Bug** | High | Schools believe they have set a cap. They have not. Over-enrolment is discovered in the classroom. |
| 4 | Rate-limit fail-open (D16) | **Weakness** | Medium | Only on the legacy logged-in path, so exposure is low, but it is a fail-open in a spam guard. |
| 5 | Dashboard/analytics full-scan (D5, D6) | **Weakness** | Medium | Fine at 30 students, painful at 300, a cost and latency problem at 3,000. |
| 6 | Duplicate public-form path (D8) | **Weakness** | Medium | Two validators, two rate-limit stores, one collection. Divergence is a matter of time. |
| 7 | Public app-id collision window (D9) | **Weakness** | Medium | Low probability, silent data loss if it happens. |
| 8 | No parent tracking (D17) | **Enhancement** | Medium | Every untracked application becomes a phone call to the front office. |
| 9 | No test/interview scheduling | **Enhancement** | Medium | The single largest competitive gap against Edunext/Fedena. |
| 10 | No offer letter + expiry | **Enhancement** | Medium | Seats are held indefinitely by non-responding parents today. |
| 11 | No reminders / no cron (D2) | **Enhancement** | Medium | The verified biggest funnel leak (documents) has no automated nudge. |
| 12 | No merit list / criteria | **Enhancement** | Medium | Required to make decisions publishable; RTE-adjacent. |
| 13 | No bulk operations | **Improvement** | Low | Real pain at 200+ applications, invisible below that. |
| 14 | `status` / `stage` conflation (D12) | **Improvement** | Low | Cosmetic today because both are written together. |
| 15 | Dead Kotlin constants (D15) | **Weakness** | Low | Zero runtime impact; high misdirection cost. |
| 16 | Rate-limit doc accumulation (D10) | **Weakness** | Low | Bounded by distinct IPs per school. |
| 17 | Section pick = first alphabetical (D18) | **Improvement** | Low | Superseded by seat enforcement. |

### Gaps NOT worth closing, and why

- **Two id shapes (D11).** Renaming ids on live applications risks breaking every printed acknowledgement, receipt link and SMS in circulation. Cost is real, benefit is aesthetic. Standardise for *new* ids only if the counter is touched for another reason.
- **Full `status`/`stage` normalisation (D12).** A migration over live applications to split one field into two, for a distinction the code already maintains correctly, is pure risk. Add `outcome` as a **new optional field** on new writes and let it back-fill naturally.
- **Auth-failure enrolment semantics (D14).** Deliberately preserved, and `repair_student_auth` exists. Changing it now means changing enrolment's failure contract for no user-visible gain.
- **Retiring `Sis::online_form` / `submit_online_form` (D8) in the same phase as new work.** It is behind login and low-traffic. Deprecate with a banner; delete in a later cleanup so a rollback never has to reason about two changes at once.
- **RTE lottery automation.** Rules vary by state and change by court direction. Model the quota, export the register, let the state portal run the lottery.

---

## Phase 4 — Advanced target design

### 4.1 Concept

> **One application object, three honest views.** The admin sees the whole ladder and every gate. The panel teacher sees one anonymised score box on the day. The parent sees where they are and what is being asked of them. Nothing irreversible happens without its preconditions visible first.

### 4.2 User stories by persona

**School admin / Principal**
- As a principal I set the sanctioned intake per class and the system **refuses** to issue offers beyond it, so I never discover over-enrolment in September.
- As a principal I publish the selection criteria and their weights, and the generated merit list prints them, so I can defend any decision.
- As a principal I see stage-wise drop-off and know that documents are where I lose applicants.

**Front office / clerk**
- As a clerk I open one applicant and see every blocker to enrolment in one list, with the fix for each, before I press anything.
- As a clerk I shortlist 40 applicants in one action and get told exactly which three failed and why.
- As a clerk I book an applicant into a test slot and the parent is notified without me writing an SMS.

**Accountant**
- As an accountant every admission rupee posts to the configured income account through the same journal path as fees, with a receipt in the ADM series. *(Already true — `Admission_collection.php:191-229`.)*

**Class teacher**
- As a class teacher I see students newly added to my class and their joining date, read-only.

**Panel teacher**
- As a panel teacher I open the app on test day, see only my slot's candidates by roll number, enter a score and a one-line remark, and it uploads when I get signal.

**Parent**
- As a parent I track my child's application from submission to enrolment without calling the school.
- As a parent I see exactly which documents are outstanding and upload them from my phone.
- As a parent I accept or decline a seat, and I know the date the offer lapses.

**Super Admin**
- As a super admin I see admission volume and conversion per tenant, from aggregates, never by reading tenant application documents. *(Binding: the SA↔SIS domain boundary — no SA surface reads SIS collections at runtime.)*

### 4.3 Capability spec, tiered

**MVP (Phase 1 — Seats & Integrity)**
- Sanctioned intake per class, enforced at offer and at enrolment
- Admission/GR number: gapless per-school series, allocated at enrolment, immutable, printed on certificates
- Backfill GR numbers for already-enrolled students, in admission-date order
- Seat matrix screen: intake / enrolled / offers held / uncommitted
- Repo/live index reconciliation
- Dashboard + analytics served from counters, not scans
- Rate-limit fail-closed; rate-limit doc TTL

**Advanced (Phase 2 — Selection & Scheduling)**
- Assessment slots with capacity, booking, and panel assignment
- Teacher App panel screen: anonymised roll numbers, score + remark, offline queue
- Selection criteria with weights; merit list generation + PDF export printing the criteria
- Offer letter with expiry; accept/decline; automatic seat release on lapse
- Bulk shortlist / offer / reject with per-item outcome reporting
- Reminder job: pending documents (day 3, 7), unpaid fee (day 3, 7), unaccepted offer (day −2), with escalation
- Admission window per class

**Differentiator (Phase 3 — Parent Tracker)**
- Public tracker page on the existing admission link, authenticated by APP-id + OTP to the registered phone
- Parent App screen reading the same endpoint
- Parent-side document upload into the Document Service
- Parent-side accept/decline of the offer
- Sibling / alumni auto-linkage from `indexPhones` with an admin confirmation step

### 4.4 State machine

Two orthogonal axes. `stage` = position on the ladder. `outcome` = terminal disposition. This is the shape the code already maintains informally; the design makes it explicit and **additive** — `outcome` is a new optional field, `status` keeps being written exactly as today for backward compatibility.

```
                                  ┌──────────────► withdrawn (parent) ─┐
                                  │                                     │
draft ──► submitted ──► verified ──► assessed ──► shortlisted ──► offered ──► enrolled ──► archived
  │           │            │            │              │             │            │
  │           └────────────┴────────────┴──────────────┴─────────────┘            │
  │                                   │                                            │
  │                                   ├──────► waitlisted ◄──► shortlisted         │
  │                                   │                                            │
  │                                   └──────► rejected ───────────────────────────┘
```

| Transition | Who may trigger | Preconditions | Effect on dependents |
|---|---|---|---|
| `draft → submitted` | Parent (public form), Front Office | Required fields, consent, session open, rate limit, hard-dup window | `crmApplications` created; ack SMS; audit |
| `submitted → verified` | Front Office, Admin | All class-required documents verified **or** an explicit waiver with reason | Sets `documentsVerifiedAt`; unblocks assessment |
| `verified → assessed` | System, on score entry | A score exists for the applicant's slot | Writes `assessmentScore`; audit |
| `assessed → shortlisted` | Admin, Principal | — | Merit position recomputed |
| `* → waitlisted` | Admin, Principal | — | `crmWaitlist` entry; **no seat held** |
| `shortlisted → offered` | Admin, Principal | **Uncommitted seats > 0**, fee structure exists, no unresolved duplicate | `offerExpiresAt` set; **seat committed**; offer letter generated; SMS |
| `offered → enrolled` | Admin, Front Office | All existing gates + seat still available + fee received (if configured mandatory) | Full existing enrolment chain, unchanged; seat commitment converts to occupancy; **GR number allocated** |
| `offered → shortlisted` | System (offer lapse) or Admin | `offerExpiresAt` passed | **Seat released**; parent + admin notified; audit records auto-release |
| `* → rejected` | Admin, Principal | Reason ≥ 10 chars; not already enrolled | Any committed seat released; parent notified |
| `* → withdrawn` | Parent, or Admin on parent's behalf | Not enrolled | Seat released; audit records who withdrew |
| `enrolled → archived` | Session rollover job | Session closed | Application frozen read-only; GR number and student record untouched |

**Guarded illegal transitions (already enforced today, preserved):** approving an enrolled application (`Sis.php:6696`), rejecting an enrolled application (`:6721`), enrolling a non-approved application (`:6945`), double enrolment (idempotency claim, `:7089`).

### 4.5 Automation & intelligence layer

| Concern | Design |
|---|---|
| Scheduling | Assessment slots as first-class docs with capacity; booking is a counter increment, not an array append |
| Triggers | Offer-lapse sweep, document reminder, fee reminder — one daily job, one pass, all three |
| Notifications | SMS only, transactional, hard-capped at 2 reminders per concern per application. No campaigns. |
| Escalation | Third miss creates a front-office task, not a third SMS |
| Bulk operations | Per-item, independently committed, per-item outcome reported. Never a single 500-op batch. |
| Templating | Offer / rejection / acknowledgement letters with merge fields, rendered server-side to PDF, school letterhead |
| Approvals | Offer above sanctioned intake requires Principal role **and** a recorded justification; the over-offer allowance is a configured percentage |
| Audit | Unchanged — `auditLogs` + `auditArchive`, correlated by `appId`. Every new action logs through `log_audit`. |
| Analytics | Counter documents updated on transition; screens read counters. No scan at any size. |

### 4.6 Configurability (no code change)

Admission window per class · pipeline stages · required documents per class · sanctioned intake and over-offer allowance per class · selection criteria and weights · reminder day offsets and escalation target · registration/admission fee per class · offer validity in days · letter templates · whether an offer may precede payment · quota pools.

### 4.7 Permissions

Mapped onto **today's** role arrays (`Sis.php:31-34`), not Model D.

| Action | Super Admin | School Super Admin | Admin | Principal | Vice Principal | Front Office | Academic Coord. | Class Teacher | Teacher |
|---|---|---|---|---|---|---|---|---|---|
| View applications | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Create / edit application | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — |
| Verify document | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — |
| Book assessment slot | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — |
| **Enter assessment score** | — | — | — | — | — | — | — | **assigned panel only** | **assigned panel only** |
| Shortlist | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — | — |
| **Issue offer** | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| **Over-offer beyond intake** | ✓ | ✓ | — | ✓ | — | — | — | — | — |
| Reject | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — | — |
| Enrol | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — |
| Configure intake / criteria | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

Note the deliberate narrowing: `VIEW_ROLES` today includes Teacher and Class Teacher (`Sis.php:34`), so a teacher can already open the applications grid. The design keeps that (changing it is a separate RBAC decision) but grants **no new** teacher capability beyond scoring their own assigned slot.

## 4.8 Student identity model — STU id · SR number · APAAR ID

> **Full specification: [`SR_NUMBER_AND_APAAR_ID_DESIGN.md`](SR_NUMBER_AND_APAAR_ID_DESIGN.md)** — that document is authoritative for this section and supersedes the "GR number" treatment in the first draft. It carries the config, claim design, form placement, schema, indexes, rules, backfill, cross-module impact, edge cases and test plan.
>
> **Restored 2026-08-07.** The long-form §4.8 written into this file on 2026-08-06 was uncommitted and was lost when the tree moved to `integration/phase2`; it is not recoverable from git. This summary replaces it — the standalone document is complete.

### 4.8.1 Three identifiers, three different jobs

| | **STU id** | **SR number** | **APAAR ID** |
|---|---|---|---|
| Example | `STU00418` | `0147` — **digits only** | `123456789012` |
| Owner | ZenXII (system) | **The school** | Government of India |
| Scope | Unique per tenant | **Unique per school; every school runs its own series** | Nationally unique, one per child for life |
| Purpose | Primary key. Doc ids, Firebase Auth, claims, app payloads, every foreign key | **The identifier the school operates on** — roll calls, fee registers, TCs, staff conversation | Statutory / reporting identity; record portability |
| Assigned | Automatically at enrolment | At enrolment — **auto, manual or hybrid, per school config** | Never by us; the parent supplies a number issued elsewhere |
| Mutable | **Never** | Immutable once set; Principal-only correction, audited | Editable (parents often obtain it after admission) |
| Changes on this design | **None whatsoever** | New | New |

**The load-bearing rule: `STU…` is untouched.** No doc id, no Firebase Auth uid, no custom claim, no `User Id` field, no Kotlin payload, no foreign key changes. SR number and APAAR ID are **additive display and lookup identifiers layered on top**. The existing identity decision (global STU/STA counter is load-bearing; migration deferred to SaaS scale) stands unchanged and is not reopened.

### 4.8.2 The SR number is digits only

**DECIDED 2026-08-06 (operator).** No prefix, no session segment, no letters, slashes or spaces — validated `^[0-9]{1,9}$`. Prefix and session-in-number are **removed** from the design, not made optional. Per-school config is `mode` (auto/manual/hybrid, default hybrid), `width` (display padding only, default 4), `startFrom`, `resetPerSession` (default false — a lifetime number).

Padding is presentation: `147` ≡ `0147` ≡ `00147` are the same register entry, colliding on save and matching each other on search, because `srNoNorm` strips leading zeros. Uniqueness is an atomic create-if-not-exists claim on `srNoIndex/{schoolId}_{srNoNorm}` — never a read-then-write.

### 4.8.3 The admission-form change — two different SR fields, do not conflate

At application time the school's own register number does not exist, so **the public form cannot ask for it**. It gains two other fields: `previous_sr_no` (the applicant's *former* school's number, free text — that format belongs to them) under Previous schooling, and `apaar_id` (optional, 12 digits) under Student details. The admin application form takes the same two. The school's own `sr_no` appears **only in the enrolment panel**, and both stay editable on the student profile forever.

### 4.8.4 APAAR ID

The government's 12-digit NEP-2020 student ID. **We store it; we never generate, verify or transmit it.** Optional at every stage, unique within the school only, masked by default with an audited reveal for Admin/Principal, and **never sent to the Teacher App**. The public-form consent copy must be amended to name APAAR before the field goes live.

### 4.8.5 What this changes elsewhere

`students` gains `srNo`, `srNoNorm`, `srNoSeq`, `srNoSource`, `apaarId`, plus a dual-emit of `Admission Number` = `srNo` that repairs `Certificates.php:383`/`:459` with **zero change to that file**. `crmApplications` gains `previous_sr_no` and `apaar_id`. Two new server-only claim collections (`srNoIndex`, `apaarIndex`), one new index (`students × schoolId, srNoNorm`), no index on `apaarId`. Backfill is **import-first** — auto-assigning by admission date is explicitly wrong. Parent App shows SR plainly and APAAR masked; Teacher App shows SR only.

---

## Phase 5 — Data & platform design

### 5.1 New collections

**`admissionSeats/{schoolId}_{session}_{classKey}`**

| Field | Type | Req | Notes |
|---|---|---|---|
| `schoolId`, `session`, `className` | string | ✓ | `className` via `Entity_firestore_sync::normalizeClassSection` → `"Class 8th"` |
| `sanctionedIntake` | int | ✓ | The number a school actually believes |
| `overOfferPct` | int | — | Default 0; expected-decline allowance |
| `enrolledCount` | int | ✓ | Denormalized; authoritative recount available |
| `offersOutstanding` | int | ✓ | Committed but unconverted |
| `quotaPools` | map | — | `{ rte: {intake, filled}, staff: {...} }` |
| `windowOpensAt` / `windowClosesAt` | string (ISO) | — | Empty = always open |
| `requiredDocuments` | array\<string\> | — | Overrides the school default |
| `updatedAt`, `updatedBy` | string | ✓ | |

One doc per class per session. Ceiling: trivially under 1 MB.
**Denormalization rationale:** `enrolledCount` + `offersOutstanding` must be readable in **one** get during the offer gate. Recomputing from `students` on every offer is an aggregation query in a hot path. Drift is repaired by the nightly integrity job.

**`admissionSlots/{schoolId}_{slotId}`**

`schoolId`, `session`, `className`, `kind` (`written|interview|interaction`), `startsAt`, `endsAt`, `venue`, `capacity`, `bookedCount`, `panelStaffIds[]`, `status` (`draft|published|closed`), `createdAt/By`.
`bookedCount` is a counter, not an array — a slot never grows toward the 1 MB ceiling.

**`admissionBookings/{schoolId}_{appId}_{slotId}`**

`schoolId`, `session`, `appId`, `slotId`, `className`, `rollNo` (anonymisation key), `status` (`booked|attended|absent|scored`), `score` (0–100), `remark` (≤200), `scoredBy`, `scoredAt`, `deviceQueuedAt`.
Deterministic doc id = **the idempotency key** for score submission from an offline device.

**`admissionCounters/{schoolId}_{session}`**

`byStage` map, `byOutcome` map, `bySource` map, `byClass` map, `updatedAt`. One doc. Every dashboard and funnel read is **1 document read at any tenant size**, replacing today's three full scans.

### 5.2 Additive fields on existing collections

`crmApplications` (all optional, all backward compatible):
`outcome` · `offerIssuedAt` · `offerExpiresAt` · `offerAcceptedAt` · `meritScore` · `meritRank` · `criteriaBreakdown` (map) · `quotaCategory` · `siblingStudentId` · `alumniParentId` · `documentsVerifiedAt` · `remindersSent` (map of concern → count) · `withdrawnAt/By`.

`students`: **`admissionNo`** (string, e.g. `GR/2025-26/00418`) and `admissionNoSeq` (int). Written once at enrolment, never updated. `Certificates.php:383` starts resolving correctly with **no change to that file** — it already reads `Admission Number` first. *(Field-name decision: write **both** `Admission Number` and `admissionNo`, matching the HR dual-emit convention, so the existing certificate reader works untouched and new readers get a canonical camelCase key.)*

`schools`: `admissionConfig` map — window defaults, offer validity days, reminder offsets, criteria weights, letter templates.

### 5.3 Tenant scoping

Every new collection follows the established double-scope: doc id prefixed `{schoolId}_` via `Firestore_service::setEntity`/`docId`, **and** a `schoolId` field on the document, **and** every query built through `schoolWhere` / `schoolList` / `cursorQuery` which inject the tenant predicate inside the service. No new collection is client-readable; all fall to the catch-all deny at `firestore.rules:2009`.

The one place needing care: the **public parent tracker**. It must resolve `{schoolId, appId}` from a signed token, exactly as `Admission_public::_receipt_token` (`:1753`) already does, and must never accept a raw `appId` from the client without the token.

### 5.4 Composite indexes

**First, reconcile what already exists.** Nine `crmApplications` indexes are live but absent from the repo. They must be added to `firebase-rules/firestore.indexes.json` before anything else, or a clean deploy breaks admission.

```json
{ "collectionGroup": "crmApplications", "queryScope": "COLLECTION", "fields": [
  {"fieldPath":"schoolId","order":"ASCENDING"},{"fieldPath":"session","order":"ASCENDING"},
  {"fieldPath":"created_at","order":"DESCENDING"},{"fieldPath":"__name__","order":"DESCENDING"}]}
```
…and the same base with, respectively, `status` · `class` · `class`+`status` · `source` · `source`+`status` · `stage` · `class`+`stage` inserted before `created_at`, plus:
```json
{ "collectionGroup": "crmApplications", "queryScope": "COLLECTION", "fields": [
  {"fieldPath":"schoolId","order":"ASCENDING"},{"fieldPath":"session","order":"ASCENDING"},
  {"fieldPath":"searchTokens","arrayConfig":"CONTAINS"},
  {"fieldPath":"created_at","order":"DESCENDING"},{"fieldPath":"__name__","order":"DESCENDING"}]}
```

**New:**
```json
{ "collectionGroup": "admissionSlots", "queryScope": "COLLECTION", "fields": [
  {"fieldPath":"schoolId","order":"ASCENDING"},{"fieldPath":"session","order":"ASCENDING"},
  {"fieldPath":"className","order":"ASCENDING"},{"fieldPath":"startsAt","order":"ASCENDING"}]}

{ "collectionGroup": "admissionBookings", "queryScope": "COLLECTION", "fields": [
  {"fieldPath":"schoolId","order":"ASCENDING"},{"fieldPath":"slotId","order":"ASCENDING"},
  {"fieldPath":"rollNo","order":"ASCENDING"}]}

{ "collectionGroup": "admissionBookings", "queryScope": "COLLECTION", "fields": [
  {"fieldPath":"schoolId","order":"ASCENDING"},{"fieldPath":"session","order":"ASCENDING"},
  {"fieldPath":"status","order":"ASCENDING"},{"fieldPath":"scoredAt","order":"DESCENDING"}]}

{ "collectionGroup": "crmApplications", "queryScope": "COLLECTION", "fields": [
  {"fieldPath":"schoolId","order":"ASCENDING"},{"fieldPath":"session","order":"ASCENDING"},
  {"fieldPath":"outcome","order":"ASCENDING"},{"fieldPath":"offerExpiresAt","order":"ASCENDING"}]}
```
The last one backs the offer-lapse sweep — bounded, indexed, never a scan.

`admissionSeats` and `admissionCounters` need **no index**: both are always fetched by deterministic doc id.

### 5.5 Security rules

**Delta: none required for the new collections.** All of `admissionSeats`, `admissionSlots`, `admissionBookings`, `admissionCounters` are server-only and fall to the existing catch-all deny. Adding explicit deny-alls is nonetheless recommended as an intent-lock, matching the established `feeLocks` / `documents` pattern:

```
// ── Admission platform (Phase 1-3) — SERVER-ONLY ──────────────
// Written exclusively by the PHP backend via the Admin SDK. The Teacher
// App reaches assessment scores through an authenticated API endpoint,
// never by a direct Firestore read, so one school can never enumerate
// another's applicants, slots or seat counts.
match /admissionSeats/{docId}     { allow read, write: if false; }
match /admissionSlots/{docId}     { allow read, write: if false; }
match /admissionBookings/{docId}  { allow read, write: if false; }
match /admissionCounters/{docId}  { allow read, write: if false; }
```

**Predicate per role:** all reads and writes are mediated by PHP, which enforces the Phase-4.7 matrix through `_require_role`. The Teacher App score submission goes through an authenticated endpoint that verifies `panelStaffIds` contains the caller's staff id **and** that `schoolId` matches the caller's claim — the same shape `AttendanceApiRepository` already uses for attendance.

### 5.6 Read/write budget

| Operation | Today | Designed | @30 | @300 | @3000 |
|---|---|---|---|---|---|
| CRM dashboard | 3 × full session scan | 1 counter get | 1 | 1 | 1 |
| Funnel analytics | 3 × full session scan | 1 counter get | 1 | 1 | 1 |
| Applications grid, page 1 | 25 (cursor) | 25 | 25 | 25 | 25 |
| Pipeline board | 6 × 50 | 6 × 50 | ~40 | 300 | 300 |
| Class dropdown | all-session `sections` scan | 1 seats query (≤15) | 15 | 15 | 15 |
| **Offer gate** | n/a | 1 seat get + 1 app get | 2 | 2 | 2 |
| Enrolment | ~12 reads + ~10 writes | +1 seat read, +1 seat write, +1 counter write | ~25 | ~25 | ~25 |
| Slot list | n/a | ≤20 | 20 | 20 | 20 |
| Score submission | n/a | 1 write, deterministic id | 1 | 1 | 1 |
| Bulk shortlist ×50 | n/a | 50 independent writes, chunk 100 | 50 | 50 | 50 |
| Offer-lapse sweep | n/a | 1 indexed query, ≤100/page | ≤5 | ≤20 | ≤100 |

**N+1 audit of the design:** the merit-list build is the only candidate. It reads one page of applications and one seats doc — criteria are evaluated in memory from fields already on the application document. No per-applicant lookup.
**Batch ceiling:** no operation writes more than 100 documents in one commit. Bulk actions chunk at 100 and report per-item outcomes.
**Latency target:** offer gate < 200 ms server-side (2 gets). Dashboard < 150 ms (1 get).

### 5.7 Migration / backfill

Five backfills, each with a dry-run flag, each idempotent, each resumable, all under `scripts/`.

1. **`admissionSeats` seed** — one doc per class per session from existing `sections` (sum of `maxStrength`) and a live count of active students. Dry-run prints the table; nothing is written.
2. **GR number backfill** — enrolled students ordered by `Admission Date` then `User Id`, assigned sequentially from 1. Runs **once** per school, guarded by a marker on `schools`. Dry-run prints the first and last 20 assignments.
3. **`admissionCounters` seed** — one pass over the current session's applications, writing one counter doc. Safe to re-run; it recomputes.
4. **`phone_norm` backfill** — for applications predating the field, so duplicate detection covers history.
5. **`outcome` backfill** — derived from existing `status`. Purely additive; `status` untouched.

**Rollback:** every backfill writes only new fields or new documents. Rollback = stop reading the new field. The GR backfill is the only one that is semantically hard to reverse, which is why it is marker-guarded and dry-run-first. No existing field is ever overwritten by any backfill.

---

## Phase 6 — Cross-module impact verification

| Check | Verdict | Evidence / reasoning |
|---|---|---|
| **Inbound dependencies** | Clear | Callers into the changed code are the CRM views, `Admission_public`, and `School_config::admission_payment_config`. Every proposed change adds a method or a field; no existing signature changes. |
| **Outbound dependencies** | Clear, one watch item | `enroll_student` calls `_nextStudentId`, `saveStudent`, `Document_service::link`, `_update_student_index`, `Accounting_idempotency`, `_createFirebaseAuthStudent`, `notify_enrollment_credentials`, `Fee_lifecycle::assignInitialFees`, `entity_sync::syncStudent/syncParent`, `_log_history`. The design adds two steps **inside the existing idempotency claim** (seat commit, GR allocation) and touches none of the above. **Watch item:** GR allocation must sit *after* `saveStudent` succeeds and *before* `markSuccess`, so a failed enrolment never burns a GR number. |
| **Shared collections** | Clear | `students`, `feeDemands`, `sections`, `auditLogs`, `documents`/`documentLinks` are read-or-append-only. The only new write to a shared collection is two additive fields on `students`. |
| **Shared services** | Clear | `Firestore_service`, `Accounting_idempotency`, `Admission_collection`, `Document_service`, `Fee_lifecycle`, `Entity_firestore_sync` are **consumed unchanged**. In particular the Canonical Document Service is consumed, never modified — parent-side upload uses the existing `Document_service::store` + `link` path exactly as `Admission_public::_handle_admission_uploads` does today. |
| **Teacher App** | **Affected — new screen (Phase 2)** | Today: zero admission code (`Constants.kt:84-87` are dead constants with no consumers). The design adds one screen reading an authenticated API endpoint. It does **not** add a Firestore listener on any admission collection. The dead constants should be deleted in the same change so no future developer wires them to non-existent collections. |
| **Parent App** | **Affected — new screen (Phase 3)** | Today: zero admission code (`Constants.kt:295-298` dead). Adds one tracker screen reading an authenticated API endpoint. `StudentDoc.admissionDate` and `ProfileScreen.kt:323` are untouched. Recommend surfacing the new `admissionNo` on the profile row in the same change. |
| **Cron / scheduled jobs** | **New job** | One new daily job under the existing `run_scheduled.php` harness, so it inherits `scheduled_job_runs/{runId}` observability and `fee_alerts` failure escalation. No existing job is modified. |
| **Reports / exports** | **One improves, silently** | `Certificates.php:383` and `:459` read `$student['Admission Number']` with a fallback to `User Id`. Writing that field makes both **start printing the correct number with no code change**. This is a behaviour change on a printed statutory document and must be called out in release notes and UAT. |
| **Firestore rules** | Additive only | Four deny-all intent-locks. No existing `match` block is altered. Catch-all deny already covers the new collections. |
| **Indexes** | Additive only, plus a **required reconciliation** | Nine existing live indexes must be written into the repo file. Adding an index never breaks a running query. |
| **Tenant isolation** | Strengthened | Every new collection is doc-id-prefixed, field-scoped and server-only. The parent tracker uses the existing signed-token pattern (`Admission_public::_receipt_token`), never a raw client-supplied `appId`. No new query can cross a tenant boundary. |
| **16 production subsystems** | No regression | Fees (untouched, `assignInitialFees` call unchanged) · Accounting (untouched, `Admission_collection` consumed as-is) · Attendance (reads `students`, unaffected by two additive fields) · Examination · Result · Report Card · Communication · Messaging · HR/Payroll · Transport · Library · Hostel · Inventory · Academic Planner · PTM · Homework — **none reads `crmApplications`**, verified by the reader inventory in §2.3. The only shared-surface touch is two additive `students` fields, and every student writer in the codebase merges. |

> ### Cross-module verdict
> **CLEAR — no existing dependency is broken.** Two surfaces gain new screens (Teacher App scoring, Parent App tracking); one report (`Certificates`) silently starts printing correct data and must be flagged in UAT; everything else is strictly additive.

---

## Phase 7 — UI/UX

**Prototype:** https://claude.ai/code/artifact/929f9324-c6fb-4586-8775-5c6f91a0c4dd

### Screen inventory & navigation map

```
ADMIN WEB
├── Pipeline (default)          ← stage board, 6 columns, class filter, bulk shortlist
│    └── Application drawer     ← shared by every admin screen
│         ├── Applicant details
│         ├── Documents (verify / request / waive)
│         ├── Seat & section picker (live counts)
│         ├── Pre-enrolment gate checklist
│         ├── Activity timeline → full audit trail
│         └── Actions: advance · waitlist · reject (reason required)
├── Applications                ← grid, search + 3 filters, cursor pagination
├── Seat matrix                 ← intake / enrolled / offers held / uncommitted
├── Tests & interviews          ← slots, booking, panel, capacity
├── Funnel analytics            ← stage drop-off, source quality
└── Admission setup             ← 8 configuration cards

TEACHER APP
├── Panel (tab)                 ← today's slot, anonymised candidates, score entry
└── Classes → New joiners       ← read-only, post-enrolment

PARENT APP
├── Apply (tab) → Track         ← 6-step progress, offer accept/decline, letter PDF
└── Apply (tab) → Pending       ← outstanding documents, unpaid fee
```

### Field-level spec

**Offer issue**

| Field | Type | Req | Default | Validation | Help | Error |
|---|---|---|---|---|---|---|
| Section | radio (live seat bars) | ✓ | Emptiest non-full | Must have headroom | "Counts are live from enrolled students, not a cached field." | "This section is full. Pick another or raise its capacity." |
| Offer valid until | date | ✓ | today + configured days | Future, ≤ 60 days | "The seat is released automatically after this date." | "Pick a date in the future, within 60 days." |
| Over-offer justification | textarea | conditional | — | ≥ 15 chars when beyond intake | "Required because this exceeds the sanctioned intake." | "Explain why this offer exceeds the sanctioned intake." |
| Notify parent | checkbox | — | on | — | "Sends the offer letter link by SMS." | — |

**Reject**

| Field | Type | Req | Default | Validation | Help | Error |
|---|---|---|---|---|---|---|
| Reason | textarea | ✓ | — | ≥ 10 chars | "Recorded permanently in the audit trail against your name." | "A reason is required, and must be at least 10 characters." |
| Offer waitlist | select | ✓ | Yes | — | — | — |

**Assessment slot**

| Field | Type | Req | Default | Validation | Help | Error |
|---|---|---|---|---|---|---|
| Class | select | ✓ | — | Must have an open window | — | "Admissions for this class are closed. Reopen the window first." |
| Type | select | ✓ | Written test | — | — | — |
| Date & time | datetime | ✓ | — | Future, no panel clash | "Panel members are checked for conflicts." | "Mrs. Rao is already on a panel at this time." |
| Capacity | number | ✓ | 30 | 1–200 | "Bookings stop at this number." | "Capacity must be between 1 and 200." |
| Panel | multiselect (staff) | ✓ | — | ≥ 1 | "Only these staff can enter scores, and only on the day." | "Assign at least one panel member." |

**Score entry (Teacher App)**

| Field | Type | Req | Default | Validation | Help | Error |
|---|---|---|---|---|---|---|
| Score | number | ✓ | — | 0–100, integer | "Out of 100." | "Enter a whole number between 0 and 100." |
| Remark | text | — | — | ≤ 200 chars | "One line. Visible to admissions, never to the parent." | "Keep the remark under 200 characters." |

**Seat configuration**

| Field | Type | Req | Default | Validation | Help | Error |
|---|---|---|---|---|---|---|
| Sanctioned intake | number | ✓ | Sum of section capacities | ≥ current enrolled | "The number the school is approved for." | "Cannot be below the 86 students already enrolled." |
| Over-offer allowance | number (%) | — | 0 | 0–25 | "Extra offers to cover expected declines." | "Allowance must be between 0 and 25 percent." |
| Window opens / closes | date | — | — | Close after open | "Leave blank to accept applications year-round." | "The closing date must be after the opening date." |

### Copy deck

**Notifications (SMS)**
- Acknowledgement — `{School}: Application {APP} for {Child} received. Track it at {link}`
- Document reminder — `{School}: {n} document(s) still needed for application {APP}. Upload at {link} or bring copies to the office.`
- Fee reminder — `{School}: Admission fee Rs {amt} for application {APP} is pending. Pay at {link} or at the school counter.`
- Offer issued — `{School}: A seat in {Class} has been offered to {Child}. Accept by {date} at {link}. After that the seat is released.`
- Offer lapsing — `{School}: The seat offered to {Child} is released tomorrow. Accept now at {link}.`
- Offer lapsed — `{School}: The seat offered to {Child} has been released. Call 011-4455 2200 if this is a mistake.`
- Rejected — `{School}: Application {APP} for {Child} was not successful. {reason} You can call the office on {phone}.`
- Enrolled — `{School}: {Child} is enrolled in {Class} {Section}. Admission no {GR}. Login {id} / {pwd}`

**Empty states**
- No applications — *"No applications yet for 2025-26."* / "Share the admission link or QR from Admission setup, or add a walk-in enquiry at the counter. Applications appear here the moment a parent submits."
- No filter match — *"No applications match these filters."* / "Try clearing the flag filter, or search by application number instead of name — spellings often differ between the form and the certificate."
- No slots — *"No assessment slots published."* / "Publish slots to let the front office book applicants and to notify parents by SMS. Classes without slots skip straight from document verification to shortlisting."
- No classes — *"No classes configured for 2025-26."* / "Seat capacity comes from your class and section setup. Create sections for this session first — admissions cannot open without them."
- Teacher, no panel — *"Not on your panel."* / "You have not been assigned to any admission assessment. If you were expecting a slot, ask the front office to add you."
- Parent, no application — *"No applications yet."* / "Once you submit an admission form, you can follow it here from start to finish."

**Errors**
- Index missing — *"Could not load applications."* / "The query needs an index that is not deployed for this project. Nothing has been changed. Retry, and if it keeps failing send code IDX-CRM-09 to support."
- Permission — *"You do not have access to admissions."* / "Your role is {role}. Viewing applicant records needs Front Office, Admin or Principal. Ask your school admin to grant admission_view."
- Seat full — *"Cannot issue an offer."* / "Class 8th has 86 enrolled and 9 offers outstanding against 90 sanctioned seats. Withdraw an offer or raise the intake."
- Missing fee structure — *(existing copy, kept)* "No fee structure exists for {class}/{section} in session {session}. Enrolment aborted to prevent the student being created without fee demands."
- Concurrent enrolment — *"Enrolment is already in progress for this application. Please wait a moment and refresh."*
- Offline score queue — *"{n} scores queued."* / "They will upload automatically when you reconnect. Do not re-enter them."
- Parent, network — *"We could not reach the school right now."* / "Your application is safe. Try again in a few minutes, or call the school office on {phone}."

**Success**
- *"Enrolled as {STU}."* / "Fee demands generated · login SMS sent to {phone}"
- *"Bulk shortlist finished."* / "13 of 14 succeeded. APP00236 was skipped — a possible duplicate must be resolved first."
- *"Offer issued."* / "Seat held in {Class} until {date}. Parent notified."

---

## Phase 8 — Correctness

### 8.1 Edge-case matrix

| Case | Behaviour |
|---|---|
| Two admins offer the last seat simultaneously | Seat commit is a **transaction** on `admissionSeats`, read-modify-write on `offersOutstanding`. The loser gets "no seats available", not a silent over-offer. |
| Two admins enrol the same application | Existing `Accounting_idempotency` claim `ENROLL_{appId}` (`Sis.php:7089`). Second caller gets `already_enrolled` with the original student id. **Unchanged.** |
| Bulk shortlist, item 7 of 40 fails | Items are independent writes. The response lists exactly which failed and why. No partial-batch ambiguity. |
| Duplicate parent submission (double-tap) | 2-minute hard block on phone+class+name (`Admission_public.php:382-388`) — unchanged. |
| Twins, same phone and class | Soft-flagged, **not** blocked. Admin dismisses the flag. |
| Class/section changes mid-session for an offered applicant | Offer holds the **class** seat, not a section. Section is chosen at enrolment. No repair needed. |
| Applicant enrols then takes a TC in the same session | GR number persists. `crmApplications.outcome` stays `enrolled`; the TC is a student-lifecycle event, correctly separate. |
| Panel teacher deactivated between slot creation and test day | Score submission checks `panelStaffIds` **and** staff active status. Fails with "You are no longer on this panel" and the slot shows an unassigned warning to admin. |
| Session rollover with live applications | Applications carry `session`; the rollover job archives closed applications and leaves open ones. Open applications for the **new** session require A4 to be revisited. |
| Offer expires at 00:00 across a DST-free but timezone-ambiguous boundary | All timestamps stored as UTC ISO-8601 (`gmdate`, as `Admission_collection.php:328` already does). Expiry evaluated in **Asia/Kolkata** at the job's run time, with the school's timezone read from config. Never a naive local-string comparison. |
| Parent account deleted after offer | The tracker authenticates on APP-id + OTP to the phone on the application, not on a parent account. Deletion does not orphan the application. |
| Teacher App offline, scores entered, then reconnects | Deterministic booking doc id makes the write idempotent. Re-sending the same score is a no-op; a **different** score from the same panel member overwrites and is audited as a correction. |
| School with zero configured data | Every screen has a specific empty state naming the missing prerequisite. The public form already fails closed on a missing session (`Admission_public.php:202-206`). |
| Firestore index missing at runtime | `cursorQuery` throws, caught, surfaced as a named error code — **fail loud**, never a silent full scan. Already the design at `Sis.php:5716-5721`. |
| GR allocation succeeds but enrolment then fails | Allocation sits after `saveStudent` and before `markSuccess`. A gap is possible only if the process dies in that window; the integrity job reports gaps and never re-issues a used number. |

### 8.2 Idempotency & concurrency

| Write | Must be idempotent | Dedupe key | Primitive |
|---|---|---|---|
| Enrolment | ✓ | `ENROLL_{appId}` | `Accounting_idempotency` — **existing, unchanged** |
| Admission payment | ✓ | `ADM_COLLECT_{appId}` | `Accounting_idempotency` — **existing, unchanged** |
| Seat commit / release | ✓ | `{schoolId}_{session}_{classKey}` | **Transaction** (read-modify-write) |
| GR allocation | ✓ | `admissionNo` per school | `Firestore_service::nextSchoolCounter` — same allocator as TC/APP/STU |
| Score submission | ✓ | `{schoolId}_{appId}_{slotId}` | Deterministic doc id + `set` |
| Slot booking | ✓ | `{schoolId}_{appId}_{slotId}` | **Transaction** on `bookedCount` |
| Counter update | ✗ (converges) | — | Incremental update; nightly recompute repairs drift |
| Stage transition | ✓ | — | `casAppendBounded` — **existing, unchanged** |
| Reminder dispatch | ✓ | `remindersSent[concern]` counter on the application | Checked before send |

**Transaction vs batch:** transactions for seat commit and slot booking (contended counters). Batches never exceed 100 documents. Everything else is a single-document CAS.

### 8.3 Failure semantics

| Failure | User sees | Logged | Self-heals | Manual repair |
|---|---|---|---|---|
| Seat commit conflict | "No seats available" | `warning` | ✓ retry | — |
| Index missing | Named error + code | `error` | ✗ | Deploy the index |
| GR allocator exhausted | "Could not allocate an admission number. Retry." | `error` | ✓ retry | Integrity job reports |
| Firebase Auth fails at enrolment | Existing warning modal | `error` | ✗ | `repair_student_auth` — **existing** |
| SMS dispatch fails | Toast notes it | `info`/`error` | ✗ | Resend from the drawer |
| Counter drift | — | — | ✓ nightly recompute | — |
| Seat count drift | — | `warning` when detected | ✓ nightly recompute | — |
| Offline score never syncs | "n scores queued" persists | device-local | ✓ on reconnect | Admin enters manually |
| Reminder job fails | — | `error` + `fee_alerts` | ✓ next run | — |

**No silent failures.** Every path either surfaces to the user, writes a greppable `error` line, or is repaired by a named job that itself reports.

### 8.4 Data integrity invariants

1. For every class/session: `enrolledCount + offersOutstanding ≤ sanctionedIntake × (1 + overOfferPct/100)`.
2. `admissionSeats.enrolledCount` equals the count of active `students` in that class/session. *(Repaired nightly.)*
3. Every `students` doc with `Status = Active` has a non-empty `admissionNo`.
4. `admissionNo` is unique per school and never reused, including after a TC.
5. Every enrolled application has exactly one `students` doc, and that doc's `application_id` points back.
6. `crmApplications.outcome = enrolled` ⟺ `student_id` is non-empty **and** the idempotency slot is `success`.
7. Every `crmApplications` doc has `schoolId` matching its doc-id prefix.
8. `admissionBookings.score` is null unless `status = scored`.
9. `admissionSlots.bookedCount` equals the count of non-cancelled bookings for that slot. *(Repaired nightly.)*
10. Every stage transition has a corresponding `auditLogs` entry with a matching `appId`.
11. No application has `offerExpiresAt` in the past while `stage = offered`. *(The sweep enforces it.)*
12. `admissionReceipts` total for a session equals the admission-tagged credit in `accounting` + `accountingLedger` for that session.

### 8.5 Test plan

**Unit** — seat arithmetic incl. over-offer allowance; criteria weighting and tie-break; GR formatting and sequence; offer-expiry date maths across month and session boundaries; stage-transition legality table; reminder-eligibility predicate.

**Integration** — concurrent offer on the last seat (two processes, expect exactly one success); concurrent enrolment (expect `already_enrolled`); offline score replay (same doc id twice, expect one document); bulk shortlist with a seeded failure (expect per-item outcomes); offer-lapse sweep releasing a seat and notifying; backfill dry-run vs apply parity.

**Manual QA per surface**
- *Admin:* configure intake 2 → offer to 2 applicants → confirm the third is blocked → withdraw one → confirm the third unblocks → enrol → confirm GR allocated and printed on a TC → confirm the seat matrix reconciles.
- *Teacher App:* assigned panel sees only their slot; unassigned sees the empty state; airplane-mode score entry then reconnect shows one score, not two; a deactivated panel member is refused.
- *Parent App / tracker:* track with a valid APP-id + OTP; wrong phone is refused; upload a document and see it reflected; accept an offer and see the stage advance; let an offer lapse and see the released state and the SMS.

**CLI verifier sketch** — `scripts/admission_integrity_verify.php`
```
--schoolId= --session= [--fix] [--dry-run]

CHECK 1  seat reconciliation      admissionSeats.enrolledCount vs live students count
CHECK 2  over-commitment          enrolled + offers vs sanctioned × allowance
CHECK 3  GR coverage              active students with empty admissionNo
CHECK 4  GR uniqueness + gaps     duplicates (FAIL) / gaps (WARN, never re-issue)
CHECK 5  enrolment linkage        outcome=enrolled ⇄ students.application_id
CHECK 6  tenant scoping           every doc's schoolId matches its id prefix
CHECK 7  stale offers             stage=offered with offerExpiresAt in the past
CHECK 8  slot booking counts      bookedCount vs booking documents
CHECK 9  counter accuracy         admissionCounters vs a recount
CHECK 10 index presence           probe every _crm_page combo; FAIL LOUD on missing

Exit 0 clean · 1 warnings · 2 failures.  --fix repairs only CHECK 1, 8, 9
(derived counters). It never touches GR numbers, seats or applications.
```
Registered under `run_scheduled.php` so it inherits `scheduled_job_runs` + `fee_alerts`.

### 8.6 Observability

**Log** — every seat commit/release with before/after counts; every GR allocation with the sequence; every blocked action with the failing gate; every reminder dispatched with the concern and attempt number; every offer auto-release; every offline score replay collision.

**Audit trail** — every stage transition (existing), plus offer issue/accept/decline/lapse, over-offer justification with the approving role, score entry and correction, seat configuration changes, document waivers with reason, bulk action summaries.

**Alert** — over-commitment detected (`error`); GR duplicate (`error`, immediate); reminder job failure (`error`); counter drift beyond 5% (`warning`); index probe failure (`error`); admission payment journal unposted (`error` — **already exists**, `Admission_collection.php:240`).

---

## Phase 9 — Delivery plan

### Phase 1 — Seats & Integrity *(independently shippable)*

Closes every Bug and the two highest-risk Weaknesses. No new UI paradigm.

| File | Change | LoC |
|---|---|---|
| `firebase-rules/firestore.indexes.json` | Add the 9 existing `crmApplications` indexes + 1 new | ~120 |
| `firebase-rules/firestore.rules` | 4 deny-all intent-locks | ~10 |
| `application/libraries/Admission_seats.php` | **New** — seat read, transactional commit/release, over-offer check | ~180 |
| `application/helpers/admission_crm_helper.php` | GR number formatter + allocator wrapper | ~40 |
| `application/controllers/Sis.php` | Seat gate in `enroll_student`; GR allocation; counter updates; dashboard/analytics read counters; `_get_crm_classes` session-scoped | ~150 |
| `application/controllers/School_config.php` | Seat/intake configuration endpoints | ~90 |
| `application/views/school_config/admission_seats.php` | **New** — seat matrix + configuration | ~260 |
| `application/views/admission_crm/applications.php` | Gate checklist in the drawer | ~120 |
| `application/config/routes.php` | 4 routes | ~6 |
| `scripts/admission_seats_backfill.php` | **New** | ~110 |
| `scripts/admission_gr_backfill.php` | **New** | ~130 |
| `scripts/admission_counters_backfill.php` | **New** | ~90 |
| `scripts/admission_integrity_verify.php` | **New** | ~340 |
| **Teacher App** `Constants.kt` | Delete 3 dead constants | −3 |
| **Parent App** `Constants.kt` | Delete 3 dead constants; surface `admissionNo` on the profile row | ~8 |

**≈ 1,650 LoC admin, ~10 LoC apps.**

### Phase 2 — Selection & Scheduling *(depends on Phase 1 seats)*

| File | Change | LoC |
|---|---|---|
| `application/libraries/Admission_scheduling.php` | **New** — slots, bookings, panel checks | ~260 |
| `application/libraries/Admission_merit.php` | **New** — criteria evaluation, ranking, PDF export | ~220 |
| `application/controllers/Sis.php` | Slot CRUD, booking, offer issue/accept/lapse, bulk actions | ~420 |
| `application/controllers/Admission_api.php` | **New** — authenticated Teacher App score endpoint | ~140 |
| `application/views/admission_crm/slots.php` | **New** | ~300 |
| `application/views/admission_crm/merit.php` | **New** | ~240 |
| `application/views/admission_crm/pipeline.php` | Bulk action bar | ~90 |
| `application/views/admission_crm/settings.php` | Criteria, reminders, offer validity, windows | ~200 |
| `scripts/admission_daily_job.php` | **New** — offer lapse + 3 reminder types | ~200 |
| `scripts/cron.txt` | 1 entry | ~4 |
| **Teacher App** | New `admission/` package: repository, viewmodel, panel screen, offline queue, nav entry | ~520 |

**≈ 2,070 LoC admin, ~520 LoC Teacher App.**

### Phase 3 — Parent Tracker *(depends on Phase 2 offers)*

| File | Change | LoC |
|---|---|---|
| `application/controllers/Admission_public.php` | Tracker page, OTP auth, document upload, accept/decline | ~380 |
| `application/views/admission/tracker.php` | **New** | ~420 |
| `application/controllers/Admission_api.php` | Parent-authenticated tracker endpoint | ~90 |
| `application/libraries/Admission_letters.php` | **New** — offer/rejection PDF from templates | ~180 |
| **Parent App** | New `admission/` package: repository, viewmodel, tracker + pending screens, nav entry | ~560 |

**≈ 1,070 LoC admin, ~560 LoC Parent App.**

### Sequencing & held dependencies

Phase 1 → Phase 2 → Phase 3, strictly. Phase 2 needs seats to gate offers; Phase 3 needs offers to track.

**Held / frozen items that touch this work:**
- **Canonical Document Service — frozen baseline.** Consumed only. Parent-side upload uses the existing store+link path. If any part of the design appears to need a service change, the design changes instead.
- **Enterprise RBAC Model D — design-frozen.** Permissions map onto today's role arrays. Do not assume Model D.
- **TMS module release policy / stabilization sprint** — unrelated, but shares `Firestore_service`. Phase 1 changes no shared-service behaviour.
- **Fees Exemption v2 Phase 0** — shares `Fee_lifecycle::assignInitialFees`, which this design calls unchanged.
- **History canonicalization (Slot 4)** — `repair_student_auth` still writes to legacy `students.History`. Unchanged by this work.

### The 8 release gates

| Gate | How it is satisfied |
|---|---|
| **1. Functional correctness** | Unit + integration suites per §8.5; manual QA script per surface; every state machine transition exercised |
| **2. Cross-module impact** | Phase 6 verdict re-run at implementation against the actual diff; reader/writer inventory refreshed; explicit sign-off that no subsystem regresses |
| **3. Tenant isolation** | CHECK 6 of the verifier over every new collection; code review that every query goes through `schoolWhere`/`schoolList`/`cursorQuery`; tracker token never accepts a raw `appId` |
| **4. Firestore rules + indexes** | The 9 existing indexes reconciled into the repo **before** any new work; new indexes deployed and probed by CHECK 10; 4 deny-all locks added |
| **5. Both apps** | Phase 1: dead-constant deletion compile-verified in both repos. Phase 2/3: new screens compile-verified, offline path tested, no new Firestore listener on any admission collection |
| **6. Regression audit** | Full audit before commit; STOP on any Critical or High. Particular attention to `Certificates.php` now printing real GR numbers — a printed-document behaviour change |
| **7. Rollback path** | Every change additive. Rollback = stop reading the new field; seat gate behind a per-school config flag defaulting off until backfill verifies clean; GR backfill marker-guarded and dry-run first |
| **8. Performance** | Read/write budget per §5.6 measured at 30/300/3000; dashboard 3 scans → 1 get; no N+1; every query index-backed and fail-loud; no batch over 100 |

### Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Seat gate blocks a legitimate enrolment on drifted counts | Medium | High | Per-school flag, default off. Verifier CHECK 1 must run clean for 7 days before enabling. Principal override with justification always available. |
| GR backfill assigns numbers a school disputes | Medium | High | Dry-run mandatory. Ordered by admission date. One school at a time. Marker prevents re-run. Numbers are never reused. |
| Index deploy from repo drops a live index | Low | Critical | Reconcile is **purely additive** — read the live set first, union, never subtract. Verify with a live diff before deploying. |
| `Certificates.php` starts printing different numbers | High | Medium | Intended, but must be announced. Include in release notes and UAT with a printed sample. |
| Teacher App scope creep into admissions administration | Medium | Medium | Hard rule in the API: a teacher can write a score for a slot they are on, and nothing else. |
| Parent tracker leaks another applicant's data | Low | Critical | Signed-token pattern reused verbatim from `_receipt_token`; OTP to the phone on the application; CHECK 6; explicit pen-test item. |
| Counter drift makes the dashboard wrong | Medium | Low | Nightly recompute; drift beyond 5% alerts. |
| Offer-lapse sweep releases a seat a school meant to hold | Low | Medium | Configurable validity, default generous; lapse warning SMS at −2 days; audit records auto-release; admin can reinstate. |

### What I would NOT build

- **An RTE lottery engine.** State rules differ and change by court direction. Model the quota, export the register, let the state portal run the draw. Building it means owning legal correctness in 28 states.
- **Lead scoring or nurture campaigns.** Ethically wrong for child admission and a support liability. Reminders stay transactional and capped at two per concern.
- **A form builder.** Fedena-grade configurability produces applications the SIS cannot consume. Fields extend from a governed catalogue.
- **A second inbox / applicant chat.** Nobody staffs it. A phone number and SMS are the honest answer.
- **Decision rounds and yield management.** US independent-school model; Indian K-12 admission is rolling.
- **Renaming existing application ids or migrating `status` into `stage`+`outcome` for live data.** Real risk, aesthetic benefit. New writes only.
- **Changing enrolment's Auth-failure contract.** Deliberately preserved, `repair_student_auth` exists, and altering a certified failure path for no user-visible gain is exactly the kind of change that breaks production.
- **Super-admin cross-tenant admission analytics reading `crmApplications`.** Violates the ratified SA↔SIS domain boundary. Aggregate counters only.

---

## Sources

- [Edunext — Admission Management](https://edunexttechnologies.com/admission-management.php)
- [Edunext — School ERP](https://edunexttechnologies.com/school-erp.php)
- [Fedena — Student Admission Management System](https://fedena.com/feature-tour/student-admission-management-system)
- [Fedena — Feature tour](https://fedena.com/feature-tour)
- [Classe365 — Education CRM](https://www.classe365.com/customer-relationship-management)
- [Classe365 on GetApp](https://www.getapp.com/education-childcare-software/a/classe365/)
- [Vidyalaya — Functionality of the Admission Management module](https://www.vidyalayaschoolsoftware.com/blog/2016/12/component-of-admission-management-module-in-school-erp/)
- [Edvio — Digital School Admission Management for Indian K-12](https://edvio.quartoloom.com/blog/digital-school-admission-management-india)
- [Skoodos — Understanding RTE admission quotas in India](https://skoodos.com/en/blog/understanding-rte-admission-quotas-in-india)
- [Supreme Court directions on the 25% RTE quota](https://educationforallinindia.com/supreme-court-directs-framing-of-rules-for-25-rte-quota-in-private-schools-a-push-for-equitable-access/)

---

*Prepared as research and design only. No application code was written, no files were mutated outside this report, nothing was committed or pushed. Implementation awaits explicit authorization.*
