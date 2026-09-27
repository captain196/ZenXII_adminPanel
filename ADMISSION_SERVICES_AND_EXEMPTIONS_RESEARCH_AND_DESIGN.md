# Admission-Time Services & Fee Exemptions — Research, Forensic Audit & Target Design

**Scope:** Everything the earlier `STUDENT_ADMISSION_RESEARCH_AND_DESIGN.md` did NOT cover — what happens to transport, hostel, meals and fee concessions/exemptions *at the moment of admission*, and how a family changes any of that mid-year. That document covered the funnel (OTP, seats, communication); this one covers **what gets attached to a student besides tuition, and how it stays correct over time.**

**Status:** Research + design complete. **No code changed.** Working simulation: https://claude.ai/code/artifact/26e49cd7-99ab-4415-9e91-21f07c024c4b (§7)
**Date:** 2026-08-07
**⚠️ Final architecture review complete — see `STUDENT_ENTITLEMENTS_FINAL_ARCHITECTURE_REVIEW.md`. Conditionally approved, NOT ready to implement.** Phase C is blocked until two things are verified: (1) whether `assignInitialFees` generates a student's whole remaining session upfront or incrementally — §5.2's fix for G1/G2 does not demonstrably work until this is confirmed; (2) a credit-note mechanism for paid demands affected by a retroactive entitlement change, which this document does not yet specify. Do not begin Phase C without reading that review's Phase 0 first.

---

## 0. The three admission pathways — and where payment fits (added after operator follow-up, verified 2026-08-07)

The first pass of this document treated "admission" as a single pipeline. It isn't. Three genuinely distinct paths reach `students` today, and the payment/admission-fee module behaves differently on each:

| # | Path | Entry point | Creates | Admission-fee payment |
|---|---|---|---|---|
| **1** | **Public online form** | `Admission_public.php::submit()` (`public_form.php`) | `crmApplications` doc | **Yes** — Razorpay online (`initiate_payment()`/`payment_callback()`, HMAC-verified, idempotent, `Admission_public.php:774,1042`) **or** "pay at school" deferral (`choose_offline()`, `:690`) |
| **2** | **Admin-entered CRM application** (walk-in typed into the modern pipeline) | `Sis::save_application()`/`convert_to_application()` (`admission_crm/applications`, `admission_crm/inquiries`) | Same `crmApplications` shape as #1 | **Yes** — front office collects at the counter via `Sis::collect_admission_fee()` (`Sis.php:6464`) |
| **3** | **The "Admission" screen** — sidebar link, "New Admission" button on the SIS dashboard, and the target of every "Convert to Student" link in `admission_leads.php` | `Sis::admission()`/`save_admission()` (`sis/studentAdmission`, `Sis.php:251,281`) | `students` doc **directly** — no `crmApplications` intermediate unless opened with `?lead_id=` | **No payment step exists.** Verified by grep across the full function body (`Sis.php:281-694`) and the entire view (`studentAdmission.php`) — zero references to payment, Razorpay, `collect_admission_fee`, or `fee_amount` |

**Paths 1 and 2 are genuinely unified**, and unified well: both resolve the amount through the *same* canonical function, `admission_resolve_fee()` (`admission_crm` helper, called from `Admission_public.php:1805-1813` and `Sis.php:6491-6492`) — a comment at `Admission_public.php:1808` states this explicitly ("so both charge the exact same class-specific admission fee"). Both post through the *same* accounting path, `Admission_collection::record_payment()` (`Admission_collection.php:79`) — one receipt series (`ADM…`), one idempotent journal (`JE_FEE_{receiptKey}`), online and offline collection converging on one function. This is good architecture and needed no correction.

**Path 3 is the gap.** It is the screen your office actually reaches for a same-day walk-in — linked from the sidebar as "Admission," not buried — and it charges **no admission fee at all**. The `assignInitialFees` tuition chart still runs (so the student isn't un-billed forever), but the one-time registration/admission fee that paths 1 and 2 both collect is simply never asked for. **This is separate money** — `Admission_collection::record_payment()` writes an ADM receipt + accounting journal directly and never touches `feeDemands` (confirmed: zero references to `feeDemands` in `Admission_collection.php`). So a school relying on path 3 for walk-ins is not "billing it later through tuition" — it is not collecting that fee at all, silently, every time.

**G10 (new):** Path 3 (`save_admission`) has no admission-fee collection step. **Classification: Weakness** (works as a fast-admit tool; the gap is real revenue leakage for any school that uses it for walk-ins expecting a registration fee) — not a Bug, because it may be intentional design for a school that doesn't charge a distinct walk-in registration fee. Needs an operator decision (§11), not a silent fix.

**None of the three paths capture transport/hostel/concessions at admission** — this was already established in §2 for paths 1/2 (CRM `enroll_student()`); confirmed identical for path 3 by the same grep (zero service/concession references in `save_admission()`). So the Services & Concessions step (§6) is needed on **all three**, and the entitlement log (§5) is the substrate all three should read/write, not just the CRM pipeline. This revises §9's delivery plan — see the updated Phase E.

**What the admission-fee/payment module does NOT need to change:** concessions and services (§5) net against the recurring **tuition** chart (`feeDemands`), not the one-time **admission fee** (`Admission_collection`/`ADM…` receipts) — these are two different pieces of money, resolved by two different functions, on two different schedules. A sibling discount or RTE/EWS waiver should not, by default, touch the admission-fee amount charged at submission — unless the school explicitly wants RTE/EWS applicants exempted from the registration fee too, which is an open operator decision (§11), not something to assume.

---

## Executive summary

1. **Your fee-concession engine (`studentConcessions`) went live since the last time anyone looked at it** — `Fee_lifecycle → Fee_generation_service` now auto-applies concessions at admission billing. This is good and undocumented in memory; it's now corrected (§2.2).
2. **But there is still no field on the admission form to *create* a concession**, and now there are **three more** parallel discount mechanisms besides it — `studentConcessions`, `feeDiscountPolicies`/`studentDiscounts`, `feeScholarships`/`scholarshipAwards`, and a dead `students.exemptedFees` field — only the first is wired to admission billing; the legacy pair feeds a *different* generator used by manual/bulk screens. Admission-time and manual-recalc billing can silently disagree.
3. **Transport and hostel are entirely disconnected from admission.** Zero POST fields, zero views, zero notifications reference them anywhere in the admission path. A parent finds out transport exists only if they separately discover the Transport screen exists.
4. **A real, previously-undiscovered bug**: suspending a transport assignment never stops billing, and the one proration function that IS called on unassignment is a structural no-op — it only prorates `annual`/`yearly` demands, but transport demands are always written as `monthly`. Families have been billed in full for transport they suspended or removed, silently, since this shipped (§2.2, §4).
5. **External research confirms the target**: no mature ERP researched puts transport selection *inside* the admission form either — but the best ones (Veracross) apply financial aid *before* the first invoice generates, and treat add/drop as a request→approval workflow with monthly-bucket proration, not instant self-service (§1).
6. **The fix is one architecture, not four patches**: a single dated **entitlement log** — one collection, one state machine, for transport/hostel/meals AND concessions alike — replacing all four current mechanisms and structurally eliminating the proration bug (§5). This was already the target design from May; it just needed the external validation and the bug evidence to make the case concrete.
7. **There are three admission pathways, not one** (§0) — the public online form, an admin-entered CRM application, and a third "Admission" screen (sidebar-linked, the actual walk-in tool) that **charges no admission fee at all**, verified by reading its full function body and view. The first two pathways share a genuinely well-built payment/receipt/accounting path; the third bypasses it entirely. None of the three capture transport/hostel/concessions.

---

## 1. Phase 1 — Market research (condensed)

Full sourced report available on request; the operative findings:

| Tier | Capability |
|---|---|
| **Table stakes** | Route/stop/vehicle definition; per-stop fare; student→route assignment as a *separate post-admission step*; sibling discount as a rule against the family record; scholarship/concession as a named, trackable module (never a manual ledger edit); hostel as its own room/fee module |
| **Competitive** | **Parent-initiated request→approval** for transport add/drop/change (Edunext explicitly documents this pattern by name — "Online Request and Approval for Availing, Withdrawing, or Changing Transport"); **monthly, not daily, proration** with a notice period; **maker-checker** on fee waivers with an audit trail; RTE/EWS tracked as a **government receivable**, not a zero-fee flag |
| **Differentiator** | Transport/hostel/meal selectable **inline on the admission form itself** — not verified as existing in any of 8 researched ERPs (Edunext, Fedena, Teachmint, Classe365, Vidyalaya, Skolaro, PowerSchool, Veracross); **concession applied before the first invoice generates**, so invoice #1 is already correct (Veracross-verified, not seen at the Indian SMB tier); a **dated entitlement-log** architecture so a mid-year change never corrupts historical fee records; a **two-tier exemption model** (org-wide kill-switch + per-student override, as PowerSchool does) |
| **Anti-patterns to explicitly avoid** | A single boolean `transport_enabled`/`discount_percent` field with no history; free-text unbounded discount amounts with no approval step; silent re-billing or silent waiver with no logged reason+approver; **folding transport into a general fee waiver** — CBSE's own single-girl-child fee-waiver guidance explicitly *excludes* meal and transport fees, i.e. the regulator itself treats transport as a separate ledger line from tuition concessions |

**Regulatory grounding (India-specific, verified sources):**
- **RTE Act §12(1)(c)** — 25% EWS/disadvantaged quota at entry classes in private unaided schools. **Reimbursement flows government→school**, not through the parent, and is the *lower of* per-child government-school cost or the school's actual fee. Delhi and Maharashtra both cap EWS income eligibility at ₹1 lakh/year.
- **Documented ~6-month+ reimbursement delays** in several states — meaning an RTE/EWS seat is not simply "free," it's a **receivable from government** that needs its own aging, distinct from a parent-fee receivable.
- **CBSE guidance** explicitly separates transport/meal fees from general tuition-waiver policy (the single-girl-child waiver circular excludes them by name) — direct precedent for keeping transport on its own ledger scope in any concession engine.
- **DPDP Act, 2023** — all under-18 data requires verifiable parental consent; RTE/EWS/scholarship fields are a proxy for household income (and sometimes caste-category for RTE) and should be access-scoped to front-office/accounts roles, not visible to every staff member who can view the roster.

---

## 2. Phase 2 — Forensic audit of what ZenXII has today

All citations verified against the live `integration/phase2` working tree, `file_path:line`.

### 2.1 Coverage matrix

| Capability | Exists? | Where | Connected to admission? |
|---|---|---|---|
| Fee assignment at admission | Yes | `Fee_lifecycle.php:354` `assignInitialFees()` | **Direct** — called from `Sis.php:641` and `Sis.php:7419` |
| Concessions (`studentConcessions`, Phase 2) | Yes, **live** | `Fee_concessions.php` create/revoke; consumed by `Fee_generation_service.php` | **Auto-applied** at generation time IF a record already exists — but **no capture field on either admission endpoint** |
| Service enrollments (`studentServiceEnrollments`) | Exists, **inert** | `Fee_concessions.php:253-340` | **Dead.** Feature flag OFF; even if on, the reader is constructed but never called by the generator |
| Transport assignment | Yes, live | `Transport_service.php:2517` `assignStudent()`, own collection `studentRoutes` | **Not connected** — never called from `Sis.php`; own screen, own fee mechanism |
| Hostel allotment | Yes, live | `Hostel.php:276` `save_allocation()` | **Not connected** — same pattern as Transport |
| Legacy discounts (`feeDiscountPolicies`/`studentDiscounts`) | Yes, live | `Fee_management.php` | **Not connected to admission** — separate screen, feeds only the legacy manual/bulk generator |
| Legacy scholarships (`feeScholarships`/`scholarshipAwards`) | Yes, live | `Fee_management.php` | Same as above — shares the `studentDiscounts` doc with the discount mechanism |
| Legacy `students.exemptedFees`/`discountHeads` | **Dead** (read-only) | `Fees.php:4700-4712` | No writer exists anywhere; two explicit removal comments confirm this (`Sis.php:621`, `Sis.php:5267`) |
| Parent "opt into transport?" prompt at admission | **No** | — | Zero references in `public_form.php` or `studentAdmission.php` |
| Mid-year transport add/remove | Admin-only, partial | `Transport.php:747-827` | No parent-initiated path found anywhere in the codebase |
| Proration on transport change | **Broken** | see §2.2 | Runs, logs success, changes nothing |

### 2.2 The mechanics, precisely

**Admission billing is real but concession-blind at the point of capture.** `save_admission()` (`Sis.php:281-694`) and `enroll_student()` (`Sis.php:7078-7472`) were read in full — neither references transport, hostel, or any concession/exemption mechanism anywhere in their bodies, confirmed by the code's own comment:
> `Sis.php:621-624`: *"Fees Exemption v2 (P0-b): the exempted_fees_multiple admission write was removed. Concessions are captured via Fee_concessions (Phase 0+), applied by the unified generator (Phase 2+). Admission billing here is unchanged — assignInitialFees still runs for this student."*

`application/config/fees_exemption_v2_flags.php` currently ships:
```php
$config['USE_UNIFIED_FEE_GEN']          = true;  // Phase 2 ACTIVATED
$config['CONCESSION_UI_ENABLED']         = true;  // Phase 2 ACTIVATED
$config['SERVICE_ENROLLMENT_UI_ENABLED'] = false; // Phase 3 cutover: true
```
So `Fee_generation_service::generateDemandsForStudent()` (`Fee_generation_service.php:64-125`) **does** read `Fee_concession_reader::activeConcessionsForStudent()` and apply `fullExempt`/`percent`/`fixed` deductions per demand — this part is real, live, working. But it receives an `enrollmentReader` in its constructor (`Fee_generation_service.php:35`) and **never calls a method on it** — confirmed by reading the entire 127-line file. Its own docblock says so: *"Service-enrollment-driven generation (Phase 3; reader exists but is not consumed here)."*

**Four non-unified concession mechanisms, three role gates, only one wired to admission:**

| # | Mechanism | Collections | Role gate | Feeds admission billing? |
|---|---|---|---|---|
| 1 | `studentConcessions` (Phase 2) | `studentConcessions` | `Fee_concessions.MANAGE_ROLES` = `['School Super Admin','School Admin']` | **Yes** |
| 2 | Discounts | `feeDiscountPolicies` + `studentDiscounts` | `Fee_management.FINANCE_ROLES` | No — legacy generator only |
| 3 | Scholarships | `feeScholarships` + `scholarshipAwards` (shares doc with #2) | `Fee_management.FINANCE_ROLES` | No — legacy generator only |
| 4 | Legacy per-student fields | `students.exemptedFees`/`discountHeads` | none — dead, read-only | Legacy generator only, and only for pre-existing data |

Mechanisms 2 and 3 feed `Fees.php::_getStudentDiscounts()` (`Fees.php:4684-4716`), used by the **manual/bulk/recalc** screens — a completely different code path from the one admission uses. **A student can have an active legacy discount that the admission-time invoice never sees, and a `studentConcessions` record that the manual-recalc screen never sees.** These are not two views of the same truth; they are two truths.

### 2.3 The RBAC system itself — checked directly, and it is NOT the naive three-list problem it first appears to be

`application/helpers/rbac_helper.php` + `application/helpers/deptrole_helper.php` (the latter is new to this branch) implement a genuine **unified, graded capability catalogue**: every school has `schools.staffRoles[ROLE_*]`, one entity per role serving both HR attributes and RBAC — `permissions[]` (which modules) + `permissionLevels{module: view|edit|manage}` (at what grade). `RBAC_MODULES` (`rbac_helper.php:43`) is a fixed enum including `SIS`, `Fees`, `Transport`, `Hostel`, `Accounting`, `HR`, and others — a school can grant a custom role (e.g. "Front Office Lead") exactly the modules and levels it needs, with a stable `ROLE_*` id (`derive_role_id()`, `deptrole_helper.php:28`) so the label can be renamed without breaking the grant.

`MY_Controller::_require_role($allowed, $action, $module, $level)` (`MY_Controller.php:1178+`) checks the graded grant **first** — `has_permission($module, $level)` — and only falls through to the hardcoded `$allowed` name list for roles with **no resolved permissions at all** (unmigrated/legacy). Critically, if a role *holds* the module at a lower level than required, the graded map is authoritative and denies — it does **not** fall back to the name list, so a deliberately-lowered custom grant can't be silently re-granted by a coincidentally-matching legacy role name.

`Sis.php` and `Attendance.php` additionally define their own thin override (`Sis.php:224-245`) that checks `has_permission('SIS', $level)` before delegating to the same base logic — functionally identical, just avoids one extra call. `Transport.php`, `Hostel.php`, and `Fee_concessions.php` all rely on the base implementation directly and pass their own module string (`'Transport'`, `'Hostel'`, `'Fees'` respectively).

**The actual gap, once the system is understood correctly:** admission (`SIS`), transport (`Transport`), hostel (`Hostel`), and concessions (`Fees`) are **four separate module capabilities in an otherwise well-designed system.** A school wanting one "Admissions Officer" role that can enrol a student, assign their bus route, allot a hostel room, and apply a sibling discount must grant that role **four separate module capabilities** — because those four actions were never conceived as one workflow. This is not a flaw in the RBAC system; it's a consequence of the four mechanisms this document is about never having been unified. §5.5 addresses it correctly, in that light.

**Correction after checking the RBAC system directly (§2.4): this is a real but minor gap, not the hard lockout it first looked like.** `Fee_concessions.MANAGE_ROLES` gates on the literal string `'School Admin'`, and `MY_Controller.php:701-710` treats `'Admin'` as a distinct label elsewhere — but `Fee_concessions.php` calls the *base* `_require_role($allowed, $action, 'Fees', 'manage')` (`Fee_concessions.php:276,322`), and that base implementation checks the **graded module capability first** (`has_permission('Fees', 'manage')`) — a custom role holding `Fees:manage` in the unified catalogue passes regardless of its label. The hardcoded name list is only the **fallback for unmigrated/legacy roles with no resolved permissions**. So the bug only bites a tenant whose `Admin` role hasn't been migrated into the graded catalogue yet — real, but narrower than initially stated.

**Transport and hostel bill through a third, entirely separate mechanism**, `Fee_lifecycle::createModuleFee()` (`Fee_lifecycle.php:753-825`), called directly from `Transport_service.php:2648-2663` and `Hostel.php:360`. It writes one lump-sum demand per student per session, keyed to a fixed April period, and is idempotent by `demandId` re-invocation. This is the one that actually bills money today — `studentServiceEnrollments` does not.

**The proration bug, traced exactly:**
```php
// Transport_service.php:2652-2660 — assignStudent() never sets 'frequency'
$demandRef = $this->ci->feeLifecycle->createModuleFee($studentId, 'Transport', [
    'route_id' => $routeId, 'amount' => $monthlyFee, 'period' => 'Monthly', ...
    // no 'frequency' key
]);
// Fee_lifecycle.php:761 — so it defaults:
strtolower($feeData['frequency'] ?? 'Monthly')   // → persisted as 'monthly'

// Fee_lifecycle.php:559-562 — proRateFees() only prorates annual/yearly demands:
$freq = strtolower((string) ($d['frequency'] ?? 'monthly'));
if ($freq !== 'annual' && $freq !== 'yearly') { $skipped++; continue; }
```
`unassignStudent()` and `changeRoute()` both call `proRateFees($studentId, date('Y-m-d'), 'Transport')` before completing — but because the demand's own `frequency` field was written as `'monthly'` at creation, every such call hits the skip branch. It logs `fees_prorated` as if it succeeded and adjusts nothing. **Worse:** `suspendAssignment()`/`resumeAssignment()` (`Transport.php:747-784`) never call `Fee_lifecycle` at all — a suspended assignment keeps billing exactly as if it were active. Neither of these is a hypothetical edge case; they are the two most common real-world transport changes a school handles (temporary suspension, permanent removal).

### 2.4 What is NOT connected to admission today (verified facts, not inference)

1. `enroll_student()`/`save_admission()` contain zero calls into `Fee_concessions.php` or `Transport_service.php`.
2. `studentServiceEnrollments` is functionally dead — flag OFF, and even flagged on, unconsumed by the generator.
3. `studentRoutes` (Transport's real collection) has zero linkage to `studentServiceEnrollments` or `studentConcessions`.
4. Transport/Hostel billing (`createModuleFee`) is triggered only from Transport/Hostel controllers — never from `Sis.php`, never from `Fee_concessions.php`.
5. The admission form and enrolment endpoint accept **no** transport/hostel/service POST fields (confirmed: full ~30-field list in `Sis.php:293-350` has none).
6. Zero references to `transport|hostel|meal|bus|route` in `public_form.php` or `studentAdmission.php`.
7. Neither admission notification (`notify_admission_confirmed`, `notify_enrollment_credentials`) mentions any optional service.
8. Concession capture requires a pre-existing `studentId` — it is structurally impossible to have a concession applied to the *very first* demand generated at a first-time admission, because the record can't exist before the student does, and `assignInitialFees` runs in the same request that creates the student.

---

## 3. Phase 3 — Gap classification

| # | Gap | Class | Why |
|---|---|---|---|
| G1 | Suspending transport never stops billing | **Bug** | Families are billed for a service they explicitly suspended. Production financial-correctness defect. |
| G2 | Transport proration is a structural no-op | **Bug** | `proRateFees` logs success and changes nothing, because the demand's `frequency` field is always wrong at creation. Silent, undetectable without reading the code. |
| G3 | `Fee_concessions` hardcoded fallback list excludes the `'Admin'` role label | **Weakness** (downgraded after §2.4 — the graded module grant is checked first and is authoritative for any migrated role; this only affects roles that fall through to the legacy name list) | Real, but narrow — a migrated custom role granted `Fees:manage` is unaffected. Worth a one-line fix regardless. |
| G4 | Four non-unified concession/discount mechanisms | **Weakness** | Works individually; produces two different discount truths depending on which screen generated the invoice. |
| G5 | No admission-time concession capture field | **Weakness** | Structurally can't apply a concession to the first-ever demand; forces a "generate wrong, then fix" pattern the whole industry (per Phase 1 research) has moved past. |
| G6 | No admission-time transport/hostel/meal selection | **Improvement** | Consistent with the market (not table stakes elsewhere either) — but zero notification or discoverability afterward means families simply don't find these modules. |
| G7 | No parent-initiated mid-year service request | **Enhancement** | Genuinely new capability; Edunext explicitly ships this by name. |
| G8 | RTE/EWS modeled (if at all) as a flag, not a government receivable | **Enhancement** | No RTE/EWS mechanism found in the codebase at all today — greenfield, not a fix. |
| G9 | Four separate module-capability grants required for one conceptual workflow (admission + transport + hostel + concession) | **Weakness** | The RBAC engine itself is sound (§2.4) — this is a consequence of the underlying mechanisms never being unified, not an RBAC defect. Fixed by giving the unified entitlement action its own module capability (§5.5), not by touching the RBAC engine. |
| G10 | The "Admission" walk-in screen (path 3, `save_admission`) collects no admission fee at all — the same class-specific fee paths 1/2 both charge is simply never asked for on this screen | **Weakness** (real revenue leakage if unintentional; needs an operator decision, not a silent patch — §0, §11) | Verified by full-body grep of both the controller function and the view — zero payment references. Not classified as a Bug because it may be deliberate (some schools don't charge walk-ins a separate registration fee). |

**Not worth closing on their own:** G4-G9 individually could each be patched in place, but patching four mechanisms separately multiplies the audit surface instead of shrinking it — see §5 for why they're solved together, once. G10 is independent of that decision and should be resolved on its own regardless of which way §5's unification goes.

---

## 4. What "in real-world use" looks like today vs. the target — all three pathways

**Today, walking each pathway as an operator (§0):**

*Path 1 — parent submits the public form.* Parent fills it, is asked for the flat admission fee (Razorpay or "pay at school"), submits. No transport/hostel/concession field exists to fill. Front Office later reviews the application, verifies documents, and enrols via the CRM pipeline (`enroll_student`) — same gaps as below apply from here on.

*Path 2 — Front Office types a walk-in into the CRM.* Same `crmApplications` shape, same admission-fee collection at the counter (`collect_admission_fee`), same downstream `enroll_student` gap.

*Path 3 — Front Office uses the "Admission" screen directly (the common case for a same-day walk-in).* **No admission fee is ever asked for** (G10). No transport/hostel/concession field exists here either.

**From enrolment onward, all three converge on the same gaps:**
1. `assignInitialFees` runs immediately — full, un-concessioned fee demand generated (unless a concession happened to be created earlier for this exact `studentId`, which is impossible on a first admission).
2. If the family qualifies for a sibling/EWS/scholarship discount, Front Office must remember to go to a *different screen* (`Fee_concessions` or `Fee_management`, depending which one they know about) and create it — after the wrong invoice already exists.
3. If the family wants transport, they must separately discover the Transport module exists, and an Operations/Transport Manager must assign a route — again, after enrolment, on a screen the parent never sees mentioned anywhere.
4. If the family later suspends transport (child gets a lift with a neighbour for two months), the fee keeps generating in full, silently, because suspension never touches billing.
5. If the family permanently drops transport, the system *believes* it prorated the fee — the audit log says so — but it did not, because of the frequency mismatch.

**Target, same three entry points, one shared destination:**
1. Whichever pathway is used — public form, CRM walk-in, or the direct Admission screen — the same **Services & Concessions** step (§6) appears before submission: optional services (transport route/stop, hostel room type, meal plan) and a concession picker (sibling/staff-ward/RTE-EWS/scholarship), each producing a **proposed entitlement**, not an immediate write. Path 3 additionally gains the admission-fee step it's missing today, resolved through the same `admission_resolve_fee()` paths 1/2 already share (§0) — closing G10 without inventing a second fee mechanism.
2. A **live fee preview** shows the actual first invoice, net of every selection, before anything is submitted — separately itemizing the one-time admission fee (if applicable to that pathway/school config) from the recurring tuition-plus-services total, since these are genuinely different money (§0).
3. Enrolment creates the student **and** commits the proposed entitlements in the same flow, regardless of which of the three pathways reached it. Where a concession needs sign-off (RTE/EWS, scholarship, anything over a configurable threshold), the demand still generates on time but is flagged `pending-approval`; the Principal/Accountant approves within the school's own SLA, and correction is a top-up/credit, not a redo.
4. Mid-year, a parent (or Front Office on their behalf) raises a **service change request** — add, drop, or change transport/hostel/meal. It goes to the relevant approver (Transport Manager for route capacity, Accountant for the fee side), and on approval the entitlement log closes the old row and opens a new one at the effective date. Proration is computed from the entitlement's own dates, not bolted onto a boolean flip — the bug in §2.2 cannot recur because there is no boolean to forget to check.
5. Every change is visible in one **audit trail**, keyed to the student, readable by role — regardless of which of the three pathways originated it.

---

## 5. Phase 4/5 — Target design: one entitlement log, not four mechanisms

### 5.1 Concept

Replace `studentServiceEnrollments` (dead), `studentConcessions` (Phase 2, keep the shape, extend it), `feeDiscountPolicies`/`studentDiscounts`/`feeScholarships`/`scholarshipAwards` (retire, migrate), and the dead `exemptedFees`/`discountHeads` fields with **one collection**: `studentEntitlements`. An entitlement is anything that changes what a student is billed — a paid optional service (transport/hostel/meals) or a fee reduction (concession/scholarship/RTE-EWS) — modeled identically as a **dated, approved, append-only row**, never a mutable flag.

```
studentEntitlements/{schoolId}_{studentId}_{seq}
├── kind:            'service' | 'concession'
├── serviceType:      transport|hostel|meals|null        (kind=service)
├── concessionType:    sibling|staff_ward|rte_ews|scholarship|other|null   (kind=concession)
├── scope:            'all' | 'category:<cat>' | 'head:<feeHeadId>'
│                       — 'all' NEVER includes transport/meals unless
│                         explicitly widened (CBSE precedent, §1)
├── valueType:         percent|fixed|fullExempt|amountDue   (amountDue = services)
├── value:             number
├── frequency:         monthly|annual                        — REQUIRED, no default
├── effectiveFrom:      date
├── effectiveTo:        date|null                              — open-ended = current
├── status:            proposed|active|pending_approval|approved|rejected|discontinued
├── approvalRequired:   bool
├── approvedBy/At:      string|null, timestamp|null
├── requestedBy/At:      string, timestamp                    — parent or staff
├── reason:             string
├── govtReceivable:      {amountExpected, status, lastFollowUp}|null   (RTE/EWS only)
├── linkedRouteId/roomId: string|null                          (service only)
└── auditRef:           auto — every write appends to auditLogs
```

**State machine** (shared by both kinds):
```
proposed → pending_approval → active → discontinued
              ↓ (reject)
           rejected
```
- `proposed`: created inline during admission or via a mid-year request; not yet billing.
- `pending_approval`: needs sign-off (RTE/EWS, scholarship, any concession above a configurable threshold, or any service the school marks approval-required). The demand for the *current* period still generates on schedule using the last-approved state — approval never blocks a family's ability to be billed on time; it only gates the *change*.
- `active`: billing-effective from `effectiveFrom`.
- `discontinued`: `effectiveTo` set; historical demands untouched; future generation stops reading this row past that date.

### 5.2 Generation rule (fixes G1/G2 structurally)

`Fee_generation_service` reads `studentEntitlements` **as of the billing period being generated**, not as of "now." A period's demand is computed once, from the entitlement rows whose `[effectiveFrom, effectiveTo)` window covers that period. There is no `proRateFees()` call to forget, because there is nothing to retroactively adjust — each month's demand was already generated correctly at generation time, from whatever was active *for that month*. Suspending or discontinuing a service **closes the entitlement row** (`effectiveTo` = today or the notice-period date); the generator simply stops reading it for future periods. Billing correctness becomes a read-time property of the log, not a write-time patch someone has to remember to run.

### 5.3 Scope guard (fixes the CBSE precedent from §1)

`scope: 'all'` concessions apply only to fee heads NOT flagged `serviceType != null` (transport/hostel/meals), unless a concession explicitly lists those categories. This is enforced at generation time, not by operator discipline — mirrors the regulator's own separation.

### 5.4 RTE/EWS as a receivable, not a flag

A `kind=concession, concessionType=rte_ews` entitlement still reduces the parent's demand to zero (or the RTE-mandated amount) exactly like any other concession — but additionally opens `govtReceivable: {amountExpected, status: pending|claimed|received, lastFollowUp}`, tracked separately from the parent-facing ledger. This is the concrete fix for the documented 6-month+ reimbursement delay pattern (§1) — the school's own books show what's actually owed to them and by whom, instead of quietly writing off the amount as a waiver with no counterpart.

### 5.5 RBAC — extend the existing unified catalogue, don't build a parallel one (fixes G9; incidentally fixes G3)

The graded capability system already in place (§2.4) is the right foundation — `schools.staffRoles[ROLE_*]` with per-module `view/edit/manage` grants, resolved through `has_permission($module, $level)`. The gap isn't the mechanism, it's that admission/transport/hostel/concessions never had a shared module to grant. Two additions, following the exact pattern `Sis.php` and `Attendance.php` already use:

1. **Add `Entitlements` to `RBAC_MODULES`** (`rbac_helper.php:43`) — the single capability that governs every `studentEntitlements` write, at two levels:
   - `edit` — **propose** an entitlement (Front Office, Transport Manager, Operations Manager, or a parent-facing request that always lands as `proposed` regardless of who can approve)
   - `manage` — **approve/reject/discontinue** an entitlement (School Admin, Principal, Accountant)
2. Every entitlement-touching endpoint (in `Sis.php`'s admission wizard, `Transport.php`'s assign/suspend, `Hostel.php`'s allocate, and the retired `Fee_concessions.php`'s replacement) calls `_require_role([...], $action, 'Entitlements', $level)` — the same call shape already proven at four call sites today, just one shared module instead of four.

This means a school can grant one role — say "Admissions Officer" — `SIS:manage` + `Entitlements:edit` and have it propose everything the new wizard needs, while a **separate** `Entitlements:manage` grant (Principal/Accountant) is required to actually commit money. That maker-checker split (§1, §5) falls directly out of the existing `view/edit/manage` grade, with zero new RBAC machinery. `Fee_concessions.php`'s literal `'School Admin'`-vs-`'Admin'` fallback mismatch (G3) is fixed as a one-line addition to its legacy list while this migration happens, but stops mattering once the tenant's roles are on the graded system, which they already are for anyone using custom roles today.

### 5.6 Configurability (no code change)

Per school: which services require approval vs auto-activate; approval threshold (₹ or % above which any concession needs sign-off regardless of type); notice period for transport drop (days); whether transport/hostel selection appears on the *public* admission form or only in the admin enrolment panel; RTE/EWS quota percentage and income ceiling (state-specific).

### 5.7 Migration

Greenfield for services (nothing to migrate — `studentServiceEnrollments` never held real data). For concessions: **import, don't invent** — same discipline as the SR-number backfill. Read existing `studentConcessions` rows as-is (compatible shape, extend don't replace). Read `studentDiscounts`/`scholarshipAwards` and propose a **dry-run mapping** into `studentEntitlements`, reporting every row that can't be auto-mapped (ambiguous scope, missing dates) for manual review — never auto-migrate silently. Legacy `exemptedFees`/`discountHeads` fields already have no writer; leave them read-only during transition, retire after one full session with zero remaining reads.

---

## 6. Phase 6 — Cross-module impact verification (mandatory)

Explicit verdict on every required checkpoint — "not applicable" justified, not assumed.

| Dependency | Verdict |
|---|---|
| Inbound (what calls into changed code) | `save_admission()`/`enroll_student()` (`Sis.php`) — **now all three admission pathways of §0**, not just the CRM one — gain one new call into the entitlement service; `Transport.php`/`Hostel.php`'s assign/suspend endpoints are re-pointed from `createModuleFee` direct calls to entitlement-log writes |
| Admission-fee/payment module (§0) | **Confirmed unaffected in its mechanics** — `admission_resolve_fee()` and `Admission_collection::record_payment()` are untouched; Phase E.1 (G10) only adds a *call* to the existing shared path from a screen that currently skips it. No change to Razorpay integration, HMAC verification, idempotency keys, or the ADM receipt/journal shape |
| Outbound (what changed code calls) | `Fee_generation_service` gains a real read of entitlements (replacing the currently-dead `enrollmentReader` call site); `Fee_lifecycle::proRateFees()` is superseded for entitlement-sourced demands, not deleted (still used by any remaining legacy annual/yearly demand) |
| Shared collections/services/libraries | `studentEntitlements` (new) sits alongside, not replacing, `feeDemands` (still authoritative for billing) and `auditLogs` (every entitlement write appends). `Fee_concession_reader`/`Fee_service_enrollment_reader` are merged into one `Fee_entitlement_reader` |
| Both Kotlin apps | **Parent App**: new "Request a change" screen (transport/hostel/meal) + fee-breakdown screen shows concessions applied — genuinely new surface, not a change to `TransportPreferencesScreen.kt` (confirmed that screen is unrelated — notification-mute toggles, not service enrollment, verified by reading it directly). **Teacher App**: confirmed **no impact** — teachers already see only paid/unpaid fee status; entitlements never surface there |
| Cron / scheduled jobs | None found referencing `studentServiceEnrollments`, `studentConcessions`, or `createModuleFee` in any scheduler file — a future recurring job to flag SLA-breached `pending_approval` entitlements is new, not a change to an existing job |
| Reports / exports | Fee reports reading `feeDemands` are unaffected (entitlements change what generates *into* `feeDemands`, not its shape). A new Entitlements/Concessions report is additive |
| Firestore rules / indexes | New: `studentEntitlements` rules (school-scoped read for own-school staff by role, write server-only) + composite index `(schoolId, studentId, status)` for the approval queue and `(schoolId, status, effectiveFrom)` for SLA-breach scanning |
| Tenant isolation | `studentEntitlements` docId is `{schoolId}_{studentId}_{seq}` — same doc-id-prefix pattern already proven by `srNoIndex`/`apaarIndex` design; no cross-school query path |
| RTE receivable | New `govtReceivable` sub-object is per-student, per-school — no shared/global receivable ledger exists yet; explicitly out of scope to build a government-facing reconciliation module in this phase (flagged in §8 "what I would NOT build") |

**Cross-module verdict:** every dependency identified is either additive (new collection, new screens, new module capability) or a redirection of an existing call site to a corrected read/write path — no existing collection's *shape* changes, and no currently-working billing path (core tuition demands) is touched. The one real behavior change to already-shipped code is intentional: `suspendAssignment()`/`unassignStudent()` will actually affect billing once they read from `studentEntitlements`, which is the entire point of Phase A (§8) — today they don't, and that's the bug.

## 7. Phase 7 — UI/UX

### Screen inventory

| # | Screen | Surface | New/Changed |
|---|---|---|---|
| 1 | Admission wizard — **Services & Concessions** step | Admin (+ optional public form, config-gated) | New |
| 2 | Live fee preview | Same step | New |
| 3 | Enrolment panel — entitlement summary | Admin | Changed |
| 4 | Entitlements & Approvals queue | Admin | New |
| 5 | Transport — assign/suspend/discontinue, now entitlement-driven | Admin | Changed |
| 6 | Student profile — Entitlements tab | Admin | New |
| 7 | Parent App — "Request a change" (transport/hostel/meal) | Parent | New |
| 8 | Parent App — fee breakdown showing concessions applied | Parent | Changed |
| 9 | Teacher App | — | **Not applicable** — confirmed no teacher-facing surface for this feature; teachers already see fee status only as paid/unpaid, unchanged |

### Working simulation

**https://claude.ai/code/artifact/26e49cd7-99ab-4415-9e91-21f07c024c4b** — drive the real flow: run **New admission** end to end (student → Services & Concessions → live fee preview → submit), switch **Acting as** to Principal and clear the approval queue, then go to **Transport** and suspend/remove that same student's route with the behavior toggle on **Current** — billing keeps going, exactly matching production. Flip the toggle to **Fixed** and repeat — the entitlement closes and the next period's demand is correct. The **Audit trail** view shows every write either way, including the exact `proRateFees` no-op message on the buggy path. Themed with the real panel's terracotta tokens (`tools/css/header-inline.css`), day/night both supported.

---

## 8. Phase 8 — Correctness

### Key invariants

- I1: Every `active` entitlement has non-null `frequency` — no code path may default it.
- I2: A period's demand is fully reconstructable from `studentEntitlements` rows whose window covers that period — no retroactive mutation of an already-generated demand for a past period.
- I3: `scope:'all'` never nets against a `serviceType`-bearing fee head unless explicitly widened.
- I4: An RTE/EWS entitlement always carries a non-null `govtReceivable`.
- I5: No entitlement can move `pending_approval → active` without `approvedBy` set.

### Edge cases (abbreviated)

Concurrent proposal + approval on the same entitlement; family requests transport drop mid-notice-period then reverses before it takes effect; sibling entitlement whose anchor sibling withdraws (recalculate, don't orphan); RTE/EWS approved after the admission-session fee chart was already regenerated; approval-required concession never actioned (SLA breach — escalate, don't silently keep billing full amount indefinitely); transport route deleted while active entitlements reference it.

### Test plan (abbreviated)

Reproduce G1/G2 against current code (prove the bug), then against the entitlement-log generator (prove the fix) — this is the one test that matters most, since it's a real production financial-correctness defect. Concurrency test on simultaneous entitlement creation for the same student+service. Migration dry-run idempotency. Scope-guard test: a `scope:'all'` concession must not reduce a transport demand.

---

## 9. Phase 9 — Delivery plan

**Phase A (independent, ships first):** Fix G1/G2/G3 as isolated patches — set `frequency` correctly at `createModuleFee` call sites; make `suspendAssignment` call `Fee_lifecycle`; add `'Admin'` to `Fee_concessions.MANAGE_ROLES`. This alone stops the money bleed without waiting for the entitlement-log rebuild.

**Phase B:** `studentEntitlements` collection + rules + indexes + dry-run migration tool (no generation change yet — additive, parallel to existing).

**Phase C:** Route `Fee_generation_service` through `studentEntitlements` for concessions (supersedes `studentConcessions` reads) — A/B against legacy for one cycle.

**Phase D:** Route Transport/Hostel `createModuleFee` calls through the same entitlement log — retires the separate mechanism, closes G4.

**Phase E:** Services & Concessions step + live fee preview + approval queue, added to **all three admission pathways** (§0) — the public form, the CRM walk-in application, and the direct Admission screen — not just the CRM pipeline. One shared component, three entry points, so a fix here doesn't need repeating per pathway.

**Phase E.1 (independent, can ship alongside or before E):** Close G10 — add the admission-fee step to `save_admission()`/`studentAdmission.php`, resolved through the existing `admission_resolve_fee()`/`Admission_collection::record_payment()` shared path (§0), once the operator confirms whether walk-ins admitted through this screen should be charged the fee at all (§11).

**Phase F:** Parent-facing mid-year request flow.

All 8 release gates apply; gate 8 (performance) is the one to watch on Phase C/D — entitlement-window reads must stay a single indexed query per student per generation run, not N+1 across periods.

**What I would NOT build:** a parent-facing instant self-service toggle for transport (every mature ERP researched gates this behind approval, for route-capacity reasons alone); a generic "any fee head, any discount, no approval" free-text concession (the exact anti-pattern flagged in §1); merging RTE/EWS into the general concession UI without the receivable tracking (defeats the entire point of modeling it separately).

---

## 11. Open decisions for the operator

1. **Should the "Admission" walk-in screen (path 3) charge the admission fee at all?** (G10, §0). Two legitimate answers: (a) yes — it's an oversight, wire it through the same `admission_resolve_fee()`/`Admission_collection` path paths 1/2 already share; (b) no — this screen is deliberately the "no registration fee, tuition-only" fast lane for a category of admission (e.g. mid-year transfers, staff wards, or a school that simply doesn't charge a separate registration fee for walk-ins) and should stay as-is. **This blocks Phase E.1** — do not silently add a payment step without this answer.
2. **Should an RTE/EWS or scholarship concession also waive the one-time admission fee**, or only the recurring tuition chart? The design in §0/§5 defaults to "no — they're different money" unless told otherwise; confirm before Phase E ships the fee-preview screen, since the preview's framing (separate line vs combined total) depends on this answer.
3. **Does the public form (path 1) need the Services & Concessions step**, or should transport/hostel/concession selection stay admin-only (Front Office adds it when reviewing the application, before enrolling)? Letting a parent self-declare "we qualify for RTE/EWS" on the public form is very different, trust-wise, from Front Office capturing it after seeing documents. Table-stakes research (§1) didn't find any ERP doing this on the public form either — leaning toward admin-only is consistent with the market, but it's the operator's call.
4. **Approval threshold** (§5.6, currently proposed at 30%) and **notice period for transport drop** (§5.6, not yet defaulted) are both school-configurable — pick real starting defaults before Phase E, or leave them as an onboarding-time setting with no hardcoded default.
