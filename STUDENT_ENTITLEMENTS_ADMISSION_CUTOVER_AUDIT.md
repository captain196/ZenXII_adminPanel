# STUDENT ENTITLEMENTS / SERVICES + CONCESSIONS — ADMISSION CUTOVER SAFETY AUDIT

**`REAL_ADMISSION_READY = FALSE`**
**READ-ONLY AUDIT — nothing was modified, no flag changed, no live data touched**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Executive verdict

**Do not perform the first real admission yet.**

The A–F entitlement work is real, well-built and largely complete. It is also **billing-inert**. The
admission page captures entitlements into `studentEntitlements`; the fee generator that bills the
student reads `studentConcessions`. **Nothing connects the two**, and the fee demands are generated
*before* the capture UI is even mounted.

A real admission today would produce a student billed at **full price** with an **approved concession
on file that no billing code will ever read**. The operator would see the concession in the UI and a
full-price invoice at the counter, with no error anywhere.

Three stop conditions from the brief are met:

| Stop condition | Evidence |
| --- | --- |
| The new entitlement system is not actually canonical | `Fee_entitlement_reader` has exactly ONE caller — the shadow block at `Fee_generation_service:221`, gated by `ENTITLEMENT_SHADOW_COMPARE = false` |
| There is a hidden second source of truth | THREE discount sources exist: `studentConcessions`, `studentEntitlements`, and `students.exemptedFees` / `students.discountHeads` / `scholarshipAwards` |
| A real admission would create mixed legacy/new state | Demands from legacy `studentConcessions`; capture into new `studentEntitlements`; no bridge, no regeneration |

---

## 2. What A–F actually implemented

Traced by caller, not by filename or comment.

| Component | File | Firestore | Write path | Read path | Real callers | Activation | Canonical? |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **Entitlement domain service** | `libraries/Entitlement_service.php` (409 ln, **untracked**) | `studentEntitlements` | `propose` / `approve` / `reject` / `discontinue` | `get` / `pendingQueue` / `forStudent` | `Entitlements.php:57`, `Entitlement_requests.php:119,179`, `Student360.php:109` | **LIVE** (routes 500–506, 515–516) | Canonical for the *log*; **not** for billing |
| **Entitlement billing reader** | `libraries/Fee_entitlement_reader.php` (166 ln, **untracked**) | `studentEntitlements` | — (read-only) | `activeConcessionsForStudent`, `appliesTo`, `deductionType/Value` | **ONE**: `Fee_generation_service:221` | **OFF** — `ENTITLEMENT_SHADOW_COMPARE = false` | Shadow only |
| **Service pricing (Phase D)** | `libraries/Service_entitlement_pricing.php` (138 ln, **untracked**) | via the reader above | — | `priceFor()` | `Fee_lifecycle:518–531` (`_priceServiceFee`) | **OFF** — `USE_ENTITLEMENT_SERVICE_PRICING = false` | Inert; bills GROSS |
| **Capture UI (shared partial)** | `views/_entitlements_capture.php` (333 ln) | via `entitlements/*` | `POST entitlements/propose` | `entitlements/for_student`, `/preview` | `studentAdmission.php:554` (`direct_admission`), `admission_crm/applications.php:1164` (`crm_walkin`), `student360/workspace.php:507` | **LIVE on all three surfaces** | Canonical capture surface |
| **Approval queue / self-service** | `controllers/Entitlements.php`, `controllers/Entitlement_requests.php` (**both untracked**) | `studentEntitlements` | maker-checker | queue / mine | routed | **LIVE** | Canonical |
| **Legacy concession capture** | `controllers/Fee_concessions.php` (committed) | `studentConcessions` + `studentServiceEnrollments` | `firestoreSet:249`, `firestoreUpdate:268` | — | routed, `CONCESSION_UI_ENABLED = true` | **LIVE** | **Legacy — and the only billing-effective one** |
| **Unified generator (Phase 1/2)** | `libraries/Fee_generation_service.php` (committed, modified) | reads `studentConcessions` | writes `netAmount`, `concessionApplied`, `discountAmount` | — | `Fee_lifecycle:446` via `_routedBuildAdmissionSpecs` | **LIVE** — `USE_UNIFIED_FEE_GEN = true` | Canonical generator, **legacy discount source** |
| **Migration** | `scripts/migrate_student_entitlements.js` | `studentConcessions` → `studentEntitlements` | one-way, idempotent, non-destructive | — | run with `--commit` (1 inert doc) | done | one-time seed |

**Correction to the project memory:** `USE_UNIFIED_FEE_GEN` and `CONCESSION_UI_ENABLED` are **already
`true` in committed HEAD** (`dfe3487`), not false. The working-tree diff only *adds* the three Phase
C/D/3 flags, which are all false. Phase 2 is live; Phases C, D and 3 are not.

**Rules and indexes are correct and deployed.** `studentEntitlements`, `studentConcessions` and
`studentServiceEnrollments` are all `allow read, write: if false` (server-only, `firestore.rules:995,
996, 1016`), and the approval-queue composite index exists.

---

## 3. Current Admission execution path — `Sis::save_admission()` (`Sis.php:680`)

Traced end to end. Order is load-bearing and is shown exactly as it executes.

| Step | Line | What happens |
| --- | --- | --- |
| 1 | 682 | RBAC `sis_save_admission` / SIS manage |
| 2 | 726 | **Section capacity** — `_sectionCapacityExceeded(…, 1)`, before any write |
| 3 | 740 | **Roll uniqueness** — `_rollNoConflict()` |
| 4 | 805 | **Fee-structure pre-flight** — `_fee_structure_missing()` (BUG-076 guard) |
| 5 | 812 | **Admission-fee pre-check** — `_admission_fee_precheck()` |
| 6 | 819 | **Student ID mint** — `_nextStudentId()` |
| 7 | 1005 | **Student write** — `_placeStudentGuarded()` (atomic-capacity path, flag now ON) |
| 8 | 1016 | `syncParent()` → parent record |
| 9 | 1022 | `_recompute_section_strength()` |
| 10 | 1026 | Phone index |
| 11 | 1039 | `monthFee` init — **hard fail** if it doesn't stick |
| 12 | 1059 | Core + additional subjects |
| 13 | 1114 | **SRNO + APAAR** — `_claimIdentityAttributes()` |
| 14 | 1129 | Firebase Auth user |
| 15 | **1136** | **`feeLifecycle->assignInitialFees()` → FEE DEMANDS GENERATED** |
| 16 | 1150 | `feeDefaulter->updateDefaulterStatus()` |
| 17 | 1157 | `_log_history('ADMISSION')` + `log_audit()` |
| 18 | 1165 | CRM lead conversion (CAS-atomic) |
| 19 | 1180 | `_admission_fee_apply()` → receipt + accounting |
| 20 | 1190 | Parent SMS |
| — | **after HTTP 200** | **`ENTCAP.mount()` — entitlement capture becomes available** (`studentAdmission.php:1087`) |

Step 15 happens **five steps and one HTTP response before** the operator can record a concession.

The generator chain at step 15:

```
assignInitialFees()                      Fee_lifecycle:541
  → _routedBuildAdmissionSpecs()         Fee_lifecycle:428
      USE_UNIFIED_FEE_GEN = true         → unified path
  → Fee_concession_reader->init()        Fee_lifecycle:444   ← reads studentConcessions
  → Fee_service_enrollment_reader        Fee_lifecycle:445   ← reads studentServiceEnrollments
  → Fee_generation_service->generateDemandsForStudent()      Fee_lifecycle:449
```

`Fee_entitlement_reader` — the new log's reader — is **not in this chain**. It appears only inside
`_shadowCompare()` at `Fee_generation_service:221`, behind a flag that is off.

---

## 4. THE MOST IMPORTANT QUESTION — what one real admission creates today

| Record | Created? | By which system |
| --- | --- | --- |
| Student document (`students`) | **YES** | Neither-specific — shared `Entity_firestore_sync` |
| Student ID (`STU####`) | **YES** | Shared `Id_generator` (CAS claim doc) |
| Admission number | **YES** | Shared allocator |
| SRNO | **YES** | Shared `Sr_no_service` |
| APAAR | if entered | Shared `Apaar_service` |
| Class / Section | **YES** | Shared |
| Roll No | if entered | Shared |
| Section CAS marker (`sections.seatCasAt`) | **YES** | Atomic capacity (now enabled) |
| `sections.currentStrength` | **YES** | Shared |
| Phone index | **YES** | Shared |
| `monthFee` markers | **YES** | Shared |
| Subjects | **YES** | Shared |
| **Fee demands (`feeDemands`)** | **YES — AT FULL PRICE** | **LEGACY discount source** (`studentConcessions`, empty for a new student) |
| `feeDefaulters` projection | **YES** | Shared |
| Parent record | **YES** | Shared |
| Firebase Auth user | **YES** | Shared |
| History (`ADMISSION`) + audit | **YES** | Shared |
| Admission-fee receipt + accounting journal | **YES** | Shared |
| CRM lead conversion | if from a lead | Shared |
| **Entitlements (`studentEntitlements`)** | **ONLY IF the operator captures them AFTER the response** | **NEW system** |
| **Services (`studentServiceEnrollments`)** | **NO** — nothing on the admission path writes it | Legacy collection, no admission writer |
| **Concessions (`studentConcessions`)** | **NO** — the admission page does not post here | Legacy collection, separate UI |

### The three defects this exposes

**D1 — ORDERING (Critical).** Demands are generated at step 15; capture is possible only after the
response. Any concession or service captured at admission time **cannot** affect the demands that were
just written. Nothing re-generates them.

**D2 — DISCONNECTED SOURCE (Critical).** Even with perfect ordering, a `studentEntitlements` row is
invisible to billing. The authoritative reader is `Fee_concession_reader` → `studentConcessions`.
`Entitlement_service::approve()` (`:160`) writes **only** `studentEntitlements` — no bridge write, no
demand regeneration, no event. Verified: zero occurrences of `studentConcessions` in
`Entitlement_service.php` or `Entitlements.php`.

**D3 — SERVICES NEVER CREATED (High).** `Fee_service_enrollment_reader` reads
`studentServiceEnrollments`, but nothing in `save_admission()` writes it, and the capture partial posts
only to `entitlements/*`. A transport/hostel service captured at admission lands in
`studentEntitlements` as `kind='service'` and is billed by **neither** path —
`USE_ENTITLEMENT_SERVICE_PRICING` is false, so `createModuleFee` bills **gross**.

---

## 5. THE HIDDEN THIRD SOURCE OF TRUTH

`Fees::recalculate_demands()` (`Fees.php:7006`) — the operator-facing "Recalculate" button — does
**not** use `Fee_lifecycle` or `Fee_generation_service` at all. It uses:

```
_getClassFeeChart()  +  _getStudentDiscounts()  →  _recalculateStudentUnpaidDemands()
```

and `_getStudentDiscounts()` (`Fees.php:4729`) reads a **completely different** set of sources:

- `scholarshipAwards` (via `fsTxn->readScholarshipAwards`)
- `students.exemptedFees` — the legacy field Phase 0-b removed from the admission write
- `students.discountHeads`

So the system has **three** discount authorities:

| # | Source | Consumed by | Status |
| --- | --- | --- | --- |
| 1 | `studentConcessions` | `Fee_generation_service` (admission, generate) | **authoritative for billing** |
| 2 | `students.exemptedFees` / `discountHeads` / `scholarshipAwards` | `Fees::recalculate_demands` | **live, third authority** |
| 3 | `studentEntitlements` | shadow only | **inert** |

**One mitigation exists and is real:** `Fees.php:6944` skips any demand carrying `concessionApplied`,
so recalc cannot overwrite a concession the unified generator already applied. That guard protects
*existing* concession demands — it does **not** make source 2 agree with source 1, and it does not help
a newly admitted student whose demands carry no `concessionApplied` yet.

---

## 6. Part 5 — the twenty cutover-safety questions

| # | Question | Answer (source-level) |
| --- | --- | --- |
| 1 | Can Admission create the student then assign entitlements? | Yes, and that is exactly what it does — but **after** demands are generated. Capture works; billing does not follow. |
| 2 | Are entitlements required before fee generation? | **They should be, and are not.** Generation is step 15; capture is post-response. |
| 3 | Are services/concessions required before demand generation? | Same — no, and this is the ordering defect D1. |
| 4 | Does concession affect the initial demand? | **NO.** `studentConcessions` is empty at step 15 for a new student, and `studentEntitlements` is not read. |
| 5 | Can an entitlement be assigned after admission without corrupting fee state? | It can be assigned safely (it writes only its own log) but it has **no effect** — the demands stay full-price. Not corruption; silent non-application. |
| 6 | Are entitlements session-specific? | Yes — `session` field, and the reader filters on it. |
| 7 | Are services session-specific? | Yes in `studentEntitlements`; `studentServiceEnrollments` also carries `effectiveFrom/To`. |
| 8 | Are concessions session-specific? | Yes, both collections. |
| 9 | Mid-session admission? | Demands are generated from the class chart for the whole session. Proration is a known open gate (5 Transport sites re-derive `netAmount`) — untouched here. |
| 10 | No entitlement/service? | Correct, normal behaviour — full-price demands. This is the only case that behaves correctly today. |
| 11 | Multiple services? | `Entitlement_service` supports N rows; billing applies none of them (flag off). |
| 12 | Student receives a concession? | Captured, approved, audited — **and not billed against**. The core defect. |
| 13 | Admission fails halfway? | Partial state is possible: `monthFee` failure at `:1055` returns an error while the student, phone index and section strength already exist. The message is honest ("Do NOT re-add the student"), but the state is mixed. |
| 14 | Entitlement assignment fails after student creation? | Safe — separate endpoint, separate document; the student is unaffected. |
| 15 | Fee generation fails? | `assignInitialFees` is wrapped in try/catch at `Sis.php:1136` and **swallows the error** — admission returns success with zero demands. A `warning` is logged at `Fee_lifecycle:557`. |
| 16 | Rollback / idempotency? | Student ID release is handled on the Import path only. `save_admission` has **no rollback** past step 7. |
| 17 | Can retry duplicate entitlements? | Yes — `propose()` mints a new `entitlementId` per call with **no dedupe key**. Two clicks = two active concessions = double deduction once billing is wired. |
| 18 | Can retry duplicate demands? | Demand ids are deterministic, so re-generation overwrites rather than duplicates. |
| 19 | Can retry duplicate concessions? | Yes — see 17. |
| 20 | One canonical source of truth? | **NO — three.** See §5. |

---

## 7. Part 6 — the real-school admission scenario (DESIGNED, NOT EXECUTED)

**Do not run this until the blockers in §11 are cleared.** It is written so it will *fail visibly* on
today's code, which is the point.

> **Student:** Aarav Sharma · DOB 2015-04-12 · Class 5th / Section A · Session 2026-27
> **Parents:** Rajesh Sharma (father) / Sunita Sharma (mother) · phone 98xxxxxx01
> **Roll:** 12 · **Admission date:** today
> **Concession:** sibling, 25% on tuition (elder sibling already enrolled)
> **Service:** transport, Route 3, monthly
> **Fee structure:** must exist for Class 5th / Section A before starting

**What must be observed, and what today's code will actually do:**

| Expected | Today |
| --- | --- |
| Student created, ID/SRNO/admission number allocated | ✅ will happen |
| Section capacity respected, roll unique | ✅ will happen (atomic capacity now on) |
| Tuition demands at **75%** | ❌ will be **100%** |
| Transport fare discounted per the service entitlement | ❌ full fare (`USE_ENTITLEMENT_SERVICE_PRICING = false`) |
| `studentServiceEnrollments` row for transport | ❌ never written |
| Concession visible to the fee counter | ❌ visible in the entitlement UI only |
| Parent app shows the discounted amount | ❌ shows full price (apps read `feeDemands`) |

---

## 8. Part 7 — cutover classification

| Item | Class | Note |
| --- | --- | --- |
| `Entitlement_service` + approval workflow | **A** | Complete and live |
| Capture UI on all three admission surfaces | **A** | Complete and live |
| Rules + indexes for `studentEntitlements` | **A** | Deployed, deny-all |
| Migration `studentConcessions` → `studentEntitlements` | **A** | Run with `--commit`, idempotent |
| `Fee_entitlement_reader` (tuition) | **B** | Built, shadow-only, flag off |
| `Service_entitlement_pricing` (services) | **B** | Built, wired into `Fee_lifecycle:518`, flag off |
| **Admission ordering: capture before generation** | **C** | Requires a real change to `save_admission()` — see §10 |
| **Regeneration on entitlement approval** | **C** | `Entitlement_service::approve()` has no fee side-effect |
| **`kind='service'` → `studentServiceEnrollments`** | **C** | Nothing writes the collection the enrollment reader reads |
| **Reader swap** (`Fee_concession_reader` → `Fee_entitlement_reader`) | **C** | The actual cutover |
| Existing students' concessions | **D/E** | Migration ran; needs A/B verification (`ENTITLEMENT_SHADOW_COMPARE` cycle) before the swap |
| `Fees::_getStudentDiscounts()` third authority | **E** | Must be reconciled or fenced before the swap |
| A/B shadow verification cycle | **F** | Turn on, read `ENTITLEMENT_AB_DIVERGENCE`, turn off |
| `Fee_concessions` legacy capture UI | **G** | Safe to keep during transition — it is currently the only billing-effective capture |
| Dual capture UI (two collections, two screens) | **H** | Must be resolved before first real admission, or operators will enter concessions in the wrong place |

---

## 9. Part 8 — legacy retirement

| Legacy dependency | Switchable? | Assessment |
| --- | --- | --- |
| `Fee_concession_reader` → `studentConcessions` | Yes, once A/B passes | `Fee_entitlement_reader` is a drop-in shape (`appliesTo`/`deductionType`/`deductionValue` mirror it exactly). **Do not retire the collection** — it is the migration source and the audit trail. |
| `Fee_service_enrollment_reader` → `studentServiceEnrollments` | Needs an adapter | Either write service entitlements into this collection, or teach the generator to read `kind='service'` rows. **Nothing writes it today.** |
| `Fee_concessions` controller | Keep temporarily | It is the only capture whose data currently bills. Retire **after** the reader swap, not before. |
| `students.exemptedFees` / `discountHeads` | Do NOT delete | Still read by `Fees::_getStudentDiscounts()` for existing students. Reconcile first. |
| `Fees::recalculate_demands()` | Requires change | Should route through the same generator, or be fenced to non-concession demands only (it is partially fenced already at `:6944`). |
| Parent / Teacher apps | **No impact** | Verified: 0 references to `studentEntitlements` or `studentConcessions` across 254 + 247 Kotlin files. Both read `feeDemands` (6 and 3 files). **The apps see whatever `netAmount` we write** — so the cutover is invisible to them if and only if the demands are correct. |

---

## 10. Part 12 — exact files/functions requiring modification

| # | File · function | Change |
| --- | --- | --- |
| 1 | `Sis.php::save_admission()` ~`:1136` | Move/duplicate demand generation so it runs **after** entitlement capture, or make the capture a pre-submit step whose payload is applied inside the same request |
| 2 | `Entitlement_service.php::approve()` `:160` | Emit a regeneration signal (or return one) so an approved concession re-charts the student's unpaid demands |
| 3 | `Entitlement_service.php::propose()` `:95` | Add a dedupe key — today two clicks create two active concessions |
| 4 | `Fee_lifecycle.php::_routedBuildAdmissionSpecs()` `:428` | The reader swap: `Fee_concession_reader` → `Fee_entitlement_reader`, behind a new flag |
| 5 | `Fee_generation_service.php` | Consume `kind='service'` entitlements, or write them to `studentServiceEnrollments` |
| 6 | `Fees.php::_getStudentDiscounts()` `:4729` | Reconcile or fence the third authority |
| 7 | `fees_exemption_v2_flags.php` | `ENTITLEMENT_SHADOW_COMPARE` on for the A/B cycle; then `USE_ENTITLEMENT_SERVICE_PRICING` |
| 8 | `studentAdmission.php` ~`:554` | Resolve the dual capture surface so an operator cannot enter a concession where it will not bill |

**No rewrite of A–F is required or recommended.** Every component traced is correctly built; what is
missing is the wiring between them.

---

## 11. REAL_ADMISSION_READY

```
REAL_ADMISSION_READY = FALSE
```

### Blockers, in priority order

| # | Blocker | Severity |
| --- | --- | --- |
| **B1** | Entitlements are captured **after** demands are generated — a concession recorded at admission cannot affect the invoice | **CRITICAL** |
| **B2** | `studentEntitlements` is not read by any billing path — approval has no fee effect | **CRITICAL** |
| **B3** | Three discount authorities coexist (`studentConcessions`, `exemptedFees`/`discountHeads`/`scholarshipAwards`, `studentEntitlements`) | **CRITICAL** |
| **B4** | `kind='service'` entitlements bill GROSS; `studentServiceEnrollments` is never written by admission | **HIGH** |
| **B5** | `propose()` has no dedupe — retry creates duplicate active concessions | **HIGH** |
| **B6** | `assignInitialFees` failure is swallowed at `Sis.php:1136` — admission reports success with zero demands | **HIGH** |
| **B7** | Two capture UIs write two different collections; only the legacy one bills | **HIGH** |
| **B8** | No rollback past step 7 of `save_admission` | **MEDIUM** |
| **B9** | A/B shadow verification cycle never run (`ENTITLEMENT_SHADOW_COMPARE` has always been false) | **MEDIUM** — gates B2 |

### What is genuinely READY

Atomic capacity (5/5 wired, flag on, live-verified), the identity lifecycle (Student ID / SRNO /
admission number, all CAS-claimed with release-on-refusal), roll uniqueness, section capacity,
fee-structure pre-flight, parent linkage, history/audit, the admission-fee receipt path, and the
entitlement capture + approval workflow itself. **None of the atomic-capacity work is implicated in
any blocker above.**

---

## 12. Recommended order of work

1. **B9 first** — run the A/B shadow cycle. It is read-only, costs one query per student, and tells you
   whether `studentEntitlements` already agrees with `studentConcessions` for existing students. Nothing
   else should be decided before that answer exists.
2. **B3** — decide the single authority and fence the other two.
3. **B1 + B2 together** — ordering and reader swap are one cutover; shipping either alone leaves a
   half-connected system.
4. **B4, B5, B7** — services, dedupe, capture surface.
5. **B6, B8** — failure semantics.
6. Only then design the first real admission UAT.

---

## 13. Audit constraints honoured

No production code changed · no flag flipped · no data migrated · no student, demand, concession or
entitlement created · no rules or indexes touched · no live tenant mutation · nothing committed,
pushed or deployed. `atomic_capacity_enabled` remains `true` as authorized; every other flag is as
found.
