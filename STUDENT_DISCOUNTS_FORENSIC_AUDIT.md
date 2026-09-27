# `studentDiscounts` — Forensic Audit

**Date:** 2026-08-10 · **Branch:** `integration/phase2` · **Working tree HELD — nothing modified.**
**Scope:** dependency investigation for the old Student Profile page retirement decision.
Audit only. No code, data, Firestore, RBAC, pricing or entitlement change was made.

---

## A · EXECUTIVE VERDICT

### **B — RETIREMENT BLOCKED. `studentDiscounts` must be resolved first.**

`studentDiscounts` is **not** a display artefact and **not** legacy. It is a **live, money-affecting
mechanism operating at a layer neither `studentConcessions` nor `studentEntitlements` touches.**

The three mechanisms are not three versions of the same thing:

| Layer | Mechanism | What it changes |
|---|---|---|
| **Generation** | `studentConcessions` (authority) / `studentEntitlements` (Gate 8 successor) | `feeDemands.netAmount` — what is **billed** |
| **Collection** | **`studentDiscounts`** | `netDue` at the counter, and the **authorization cap** on a cashier's discount |

They **stack**. A student may hold both, and the second is applied on top of the first.

Four findings, in severity order:

| # | Finding | Severity |
|---|---|---|
| **SD-1** | **Student 360's Outstanding figure can overstate what the counter will collect**, because it sums `feeDemands.balance` and never subtracts `onDemandDiscount`. | **High** |
| **SD-2** | The old profile page and the fees counter write the **same field with different semantics** (additive vs overwrite) through two different endpoints. | **High** |
| **SD-3** | The discount write-audit log (`ACC_DISCOUNT_WRITE`) is emitted at **INFO**, and `log_threshold = 1` (errors only). **The reconciliation trail the code depends on is never written.** | **Medium** |
| **SD-4** | `Fee_lifecycle`'s docblocks claim it reads `studentDiscounts` for sibling discounts. **It does not** — it reads `students` + `feeSettings`. Two stale comments pointing at the wrong collection. | **Low** |

---

## B · COMPLETE LIFECYCLE

```
GRANT
 ├─ Fees::set_student_discount      (fees_counter.php)      OVERWRITES onDemandDiscount
 ├─ Fees::submit_discount           (student_profile.php)   OVERWRITES onDemandDiscount,
 │                                                          ADDS to totalDiscount
 ├─ Fee_management::apply_discount  (bulk/rule-based)       OVERWRITES onDemandDiscount
 ├─ Fee_management  scholarship award                       writes ScholarshipDiscount
 └─ Fee_management  scholarship revoke                      rewrites ScholarshipDiscount
                              ↓
                 studentDiscounts/{schoolId}_{studentId}      ← Firestore, NOT session-scoped
                              ↓
        ┌─────────────────────┼──────────────────────────────┐
        ↓                     ↓                              ↓
  Fees::fetch_months    FeeCollectionService::submit    Sis::student_profile
  netDue = remaining    discount > cap → REJECT 422      display only
      − onDemandDisc              ↓
        ↓                  receiptDoc.discount
   counter shows                  ↓
   the reduced figure     accounting posting
```

**It never reaches `feeDemands`.** No writer updates a demand; no generator reads the collection.

---

## C · EVERY WRITER (5)

| # | File · method | Trigger | Fields written | Semantics |
|---|---|---|---|---|
| W1 | `Fees::set_student_discount` (~1837) | Fees counter inline grant | `onDemandDiscount`, `scholarshipDiscount`, `totalDiscount`, `validUntil`, `valid_until`, `reason`, `appliedAt`, `appliedBy`, `source='fees_counter_inline'` | **OVERWRITE** — "what you SET is what's stored" |
| W2 | `Fees::submit_discount` (1922) | **Old profile page** (`student_profile.php:632`) | `onDemandDiscount`, `scholarshipDiscount`, `totalDiscount`, `appliedAt`, `appliedBy`, `source='profile_managerial_grant'`, `reason` | **HYBRID** — overwrites `onDemandDiscount`, but `totalDiscount = prev + delta` |
| W3 | `Fee_management::apply_discount` (1084) | Rule/bulk apply, `%` or fixed, honours `max_discount` cap | `onDemandDiscount`, `totalDiscount`, `applied{}` history map, `schoolId`, `studentId` | OVERWRITE + history append |
| W4 | `Fee_management` scholarship award (1414) | Scholarship granted | `ScholarshipDiscount`, `totalDiscount`, `scholarships{}` | merge |
| W5 | `Fee_management` scholarship revoke (1549) | Scholarship revoked | `ScholarshipDiscount`, `totalDiscount`, `scholarships{}` | merge |

All five are **Firestore**, all reachable from the current UI, all **production functionality**.

**SD-2 in detail.** W1 and W2 write the same governing field by different rules, and W2's own comment
concedes the drift: *"the cross-writer equality `total == onDemand + schol` is NOT an invariant —
`submit_discount`, `apply_discount`, and the receipt path all break it BY CONSTRUCTION."*
So `totalDiscount` (reported) and `onDemandDiscount` (billed) are knowingly allowed to disagree.
A grant from the profile page and a grant from the counter produce **different stored state**.

---

## D · EVERY READER (6)

| # | File · method | Purpose | Fields | Affects money? |
|---|---|---|---|---|
| R1 | `Fees::fetch_months` (2493) | Counter month grid | `onDemandDiscount`, `validUntil` | ✅ **YES** — `netDue = totalRemaining − discAmt` |
| R2 | `Fees::fetch_fee_details` (2660) | Counter detail | `onDemandDiscount`, `validUntil` | ✅ **YES** |
| R3 | `FeeCollectionService::submit` (147) | **Server-side authorization cap** | `onDemandDiscount`, `validUntil` | ✅ **YES** — over-cap → **HTTP 422**, fails **closed** |
| R4 | `Sis::student_profile` (5835) | Old profile "Discount" section | `totalDiscount`, `onDemandDiscount` | ❌ display only |
| R5 | `Fee_management` (4384) | School-wide discount report | `totalDiscount` | ❌ reporting |
| R6 | `Fee_management` (1022/1394/1535) | Read-before-write | all | — |

**Not read by:** fee generation, fee preview, `feeDemands`, defaulter computation, Student 360.

---

## E · STORAGE

```
Collection : studentDiscounts          (Firestore — no RTDB anywhere in this mechanism)
Doc id     : {schoolId}_{studentId}    e.g. SCH_D94FE8F7AD_STU0159
Rules      : firestore.rules:901  read: isStaffOrOwnStudent()   write: isAdmin()
Index      : none (equality-only queries)
```

⚠️ **The doc id has no session component.** Unlike `feeDemands`
(`{schoolId}_{session}_{studentId}_{demandId}`) and `feeDefaulters`
(`{schoolId}_{session}_{studentId}`), a discount is **one document per student, forever**. A discount
granted in 2025-26 is still live in 2026-27 unless `validUntil` expires it. `validUntil` is the only
temporal control, and it is optional.

⚠️ **The rule permits parent reads** — `isStaffOrOwnStudent()`. **But the Parent app does not use
it.** `Constants.kt:233` declares `STUDENT_DISCOUNTS = "studentDiscounts"` and **nothing references
that constant** — a declared-but-unused collection name. The Teacher app has no reference at all.

**So this is admin-surface-only in practice, despite the rule allowing more.** Worth knowing for the
retirement decision: no app release has to be coordinated. Worth knowing for security: the rule is
broader than the actual need.

---

## F · REAL DATA MODEL — actual fields only

| Field | Type | Meaning |
|---|---|---|
| `onDemandDiscount` | number | **The governing value.** Fixed rupee amount. |
| `scholarshipDiscount` / `ScholarshipDiscount` | number | Scholarship component. **Both casings exist** (W1 vs W4/W5). |
| `totalDiscount` | number | Reported total. **Not guaranteed** to equal the sum of its parts. |
| `validUntil` / `valid_until` | `YYYY-MM-DD` | Optional expiry, dual-emitted. Empty = never expires. |
| `reason` | string | Free text. |
| `appliedAt`, `appliedBy` | ISO ts, name | Who/when — last writer only. |
| `source` | string | `fees_counter_inline` / `profile_managerial_grant`. |
| `applied{}` | map | History, **written only by W3**. |
| `scholarships{}` | map | Award map. |
| `schoolId`, `studentId` | string | Tenant + subject. |

**Answers to the direct questions:** Fixed amount, **not** a percentage (W3 converts % to rupees
before storing). **Not** fee-head-specific. **Not** session-scoped. Permanent unless `validUntil`.
No `status`/`active` field. **No approval workflow** — the writer's own RBAC (`Fees:edit`) is the
only gate. Manually applied (W1/W2) or rule-applied (W3). Editable and overwritable; **removable only
by setting it to 0** — there is no delete path. **Audit trail: partial** — `applied{}` from W3 only,
plus `appliedBy`/`appliedAt` for the last writer. See **SD-3**.

---

## G · DOES IT AFFECT BILLING? — **YES, at the collection layer**

| Question | Answer |
|---|---|
| Affects what the school actually collects? | ✅ **Yes** |
| Affects `feeDemands.netAmount`? | ❌ **No** — never written by any of the 5 writers |
| Affects outstanding balance in `feeDemands`? | ❌ No |
| Affects the figure the counter asks for? | ✅ **Yes** — `netDue = totalRemaining − onDemandDiscount` |
| Affects collection amount? | ✅ **Yes** — caps it; over-cap is refused 422 |
| Affects receipts? | ✅ Yes — `receiptDoc.discount` |
| Affects accounting postings? | ✅ Yes — flows through the receipt |
| Affects fee preview? | ❌ No |
| Affects fee generation? | ❌ No |
| Affects defaulter / result-withheld? | ❌ No — computed from `feeDemands` |
| Affects reports? | ✅ Yes — school-wide discount total |

**The precise statement:** it does not change what is **billed**; it changes what is **asked for and
accepted** at the counter. That is still money.

---

## H · THE THREE MECHANISMS

| | `studentConcessions` | `studentEntitlements` | `studentDiscounts` |
|---|---|---|---|
| **Storage** | `{schoolId}_{session}_…` | `studentEntitlements` | `{schoolId}_{studentId}` — **no session** |
| **Writer** | Fee_concessions | `Entitlement_service` (sole path) | **5 writers, 3 controllers** |
| **Reader** | `Fee_concession_reader` → generator | `Fee_entitlement_reader` (shadow) | counter, collection, profile, report |
| **Billing authority** | ✅ **current** | ⏳ after Gate 8 | ✅ **live, different layer** |
| **Layer** | Generation | Generation | **Collection** |
| **Scope** | fee-head / scope rules | fee-head / scope rules | flat rupee amount |
| **Lifecycle** | propose → approve → active | propose → approve → active → discontinue | set / overwrite / expire |
| **Approval** | maker-checker | maker-checker + self-approval block | **none** |
| **Audit** | audit logs | audit logs | **partial, see SD-3** |
| **Status** | **live** | flag-off, shadow only | **live** |

**Can all three exist for one student? YES.**

* **Which wins?** None — they are not in competition. Concession/entitlement reduce the demand;
  the discount reduces the collection on that already-reduced demand.
* **Additive?** ✅ **Yes, across layers, by construction.**
* **Double-discount possible?** ✅ **Yes** — and it is not obviously a bug. A standing 10% sibling
  concession plus a one-off ₹500 hardship waiver is a legitimate school scenario.
* **Precedence logic?** **None exists, because none is needed at a single layer.** No file reconciles
  the two. The only cross-layer guard found is `Fees.php:6896`, which refuses to recompute a
  concession-bearing demand from the legacy discount map because doing so *"would zero the concession
  and OVERCHARGE the student (net→gross)"* — evidence the interaction was hit before and fenced off
  case-by-case rather than modelled.

---

## I · GATE 8 IMPACT — **completely outside Gate 8**

> *"If Gate 8 switches the fee generator from `studentConcessions` to `studentEntitlements`, what
> happens to an existing `studentDiscounts` record?"*

**Nothing. It keeps working exactly as it does today.** Traced, not inferred:

| Library | `studentDiscounts` references |
|---|---|
| `Fee_generation_service` | **0** |
| `Fee_entitlement_reader` | **0** |
| `Service_entitlement_pricing` | **0** |
| `Fee_lifecycle` | **2 — both comments, no code** (SD-4) |

The generator never reads the collection, so swapping its reader cannot affect it.

**Classification: another live billing authority, at a layer Gate 8 does not address.**
Not bypassing Gate 8 — **orthogonal to it.**

**The consequence to plan for:** after Gate 8, the ERP still has **two** discount systems, not one.
Retiring `studentConcessions` will look like consolidation while `studentDiscounts` quietly survives.
Any statement that Gate 8 "unifies discounting" would be **false**.

---

## J · PRODUCTION DATA — what could and could not be verified

**Could verify (from `application/logs/perf_probe.log`):**
* The doc is **read on real production requests** — `studentDiscounts/SCH_D94FE8F7AD_STU0159` and
  `…_STU0001` appear in `sis/profile`, `fees/fetch_months` and `fees/fetch_fee_details` traces.
* Reads occurred as recently as **2026-08-10 17:59**.

**Could verify (from `recovery/STU0001_fees_backup_1781510146523.json` — a LOCAL file, no production
read performed):**
* A full per-student fee backup taken **2026-06-15 09:55** records
  `collections.studentDiscounts: []` — **STU0001 had no discount document at that moment.**
* That is one student at one point in time. It is genuine evidence and it is **not** a sample.

**Could NOT verify — stated plainly:**
* **Whether any document actually exists**, or holds a non-zero `onDemandDiscount`. A Firestore read
  returning null is indistinguishable from a hit in these traces.
* Record count, recency of *writes*, or whether values are test or real.
* **Why the write log is empty is not evidence of no writes** — `ACC_DISCOUNT_WRITE` is emitted at
  `log_message('info', …)` and `log_threshold = 1` discards INFO. **SD-3.**

**Blocking reason:** the Firebase service account is not in the repository (by design). A read-only
probe would need it, and running one is outside this audit's authorization. **No script was
executed.**

Four existing scripts already touch this collection and could be adapted read-only —
`audit_stu0001.js`, `check_stu0001_dues.js`, `check_active_session.js`, `inspect_reminder_full.js`.
⚠️ Two others (`reset_stu0001_fees.js`, `wallet_removal_migration.js`) **mutate** and must not be
used for this.

**To settle it, one read-only probe is required:** count `studentDiscounts` docs where
`onDemandDiscount > 0`, grouped by `validUntil` expiry and by whether the student is still active.
**Not run. Awaiting authorization.**

---

## K · OLD PROFILE PAGE — capability comparison

| Capability | Old page | Student 360 | Elsewhere | Missing after retirement? |
|---|---|---|---|---|
| Personal / Academic / Guardian / Address / Previous school | ✅ | ✅ Records → Profile | SIS Edit | ❌ No |
| Documents | ✅ | ✅ Records → Documents | `sis/documents` | ❌ No |
| History link | ✅ | ✅ Records → History | `sis/history` | ❌ No |
| Edit student | link | ❌ | `sis/edit_student` | ❌ No |
| **Yearly / class fee chart** | ✅ | ❌ | **nowhere** | ⚠️ **YES** |
| **Monthly fee summary** | ✅ | ❌ | **nowhere** | ⚠️ **YES** |
| **View `studentDiscounts`** | ✅ | ❌ | fees counter (partial) | ⚠️ **YES** |
| **Grant discount** (`fees/submit_discount`) | ✅ | ❌ | counter uses the **other** endpoint | ⚠️ **YES — see SD-2** |
| **Repair auth** (`sis/repair_auth`) | ✅ | ❌ | **nowhere** | ⚠️ **YES** |

**Five capabilities have no replacement.** The page is **not** dead, and cannot be retired as-is.

---

## L · STUDENT 360 IMPACT

### SD-1 — the finding that matters most

`Student_context::_fees()` sums `feeDemands.balance`. It **never reads `studentDiscounts`**
(verified: 0 references across all three Student 360 libraries).

```
Student 360 shows :  Outstanding = Σ feeDemands.balance
Counter shows     :  netDue      = Σ feeDemands.balance − onDemandDiscount
```

**For any student holding a live `onDemandDiscount`, Student 360's Outstanding — and its red alert,
and its folded Money headline — is HIGHER than what the counter will actually ask for.**

Severity **High**: it is money, on the most prominent figure on the screen, and it is wrong in the
direction that makes a family look like it owes more than it does.

*(Distinct from D-02, which is about archived demands. Different cause, same symptom.)*

### Where should it appear?

**Money → Fees**, as a line beneath Outstanding — not a new panel and not inside Entitlements.

* Not **Entitlements**: that panel is `studentEntitlements`. Mixing a collection-layer rupee waiver
  into a generation-layer entitlements list would misrepresent both.
* Not a **new sub-panel**: it is one number with an expiry, not a domain.
* Not **nowhere**: it is live and it changes the figure directly above it.

### Does Entitlements become misleading?

**Yes — mildly, and it already is.** An operator reading *"2 active entitlements"* reasonably
concludes they are seeing every concession this student holds. They are not: a ₹5,000
`onDemandDiscount` is invisible there. The panel is not wrong, but it is **incomplete in a way the
label does not admit.**

---

## M · CLASS FEE STRUCTURE — a separate, confirmed gap

Student 360 answers **"what does this student owe?"** — it reads `feeDemands`.
It does **not** answer **"why is this student charged this amount?"** — `feeStructures` is read
**0 times** across all Student 360 libraries.

The old page answers the second question via `Sis::_getFees(class, section)` → `feeStructures/{schoolId}_{session}_{class}_{section}`.

**This is a genuine Student 360 gap, independent of `studentDiscounts`.** Documented, not
implemented, per instruction.

---

## N · SEVERITY OF NEW DEFECTS

| ID | Defect | Severity | Owner | Blocks production? |
|---|---|---|---|---|
| **SD-1** | Student 360 Outstanding overstates collectable dues | **High** | Student 360 | Blocks the "single source of truth" claim; not a crash |
| **SD-2** | Two endpoints write the governing field with different semantics | **High** | Fees | No — but reconciliation is unreliable |
| **SD-3** | Discount write-audit emitted at INFO; threshold discards it | **Medium** | Fees | No — but there is **no** money audit trail |
| **SD-4** | `Fee_lifecycle` docblocks name the wrong collection | **Low** | Fees | No |
| **S360-G1** | No class fee structure — "why this amount?" unanswerable | **Medium** | Student 360 | No |

None was introduced by the zone work. All are pre-existing and were surfaced by asking whether one
page could be deleted.

---

## O · RETIREMENT RECOMMENDATION

### **B — RETIREMENT BLOCKED. Resolve `studentDiscounts` first.**

**Exact prerequisites, in order:**

1. **Run one read-only probe** (needs your authorization): how many `studentDiscounts` docs hold
   `onDemandDiscount > 0`, how many are unexpired, how many belong to active students. **Everything
   below depends on this answer** — if the count is zero, most of it collapses to cleanup.
2. **Decide SD-2:** one grant endpoint, or two with documented distinct semantics. Two endpoints
   writing one money field by different rules is not a stable resting state.
3. **Fix SD-1** — Student 360 must either subtract `onDemandDiscount` or state that Outstanding is
   pre-discount. Either is acceptable; silence is not.
4. **Rehome the five orphaned capabilities** — class fee chart, monthly summary, discount view,
   discount grant, repair auth.
5. **Then** repoint the 7 inbound links and retire the page.

**Do not** retire the page as a side effect of the Student 360 UAT. It is currently the only surface
for two operations, and the only place a `studentDiscounts` value is visible in context.

---

## P · RECOMMENDED NEXT PHASE

**Do not fold this into the Student 360 UAT.** The UAT tests a presentation layer; this is a
billing-authority question with its own blast radius.

Suggested sequence:

1. **Now:** authorize the read-only probe. One command, no writes, and it decides everything else.
2. **Before the UAT:** decide SD-1 only — whether Student 360's Outstanding should be pre- or
   post-discount. It changes what a tester records as correct, so it must be settled first.
3. **After the UAT, with the other verified defects:** SD-2, SD-3, S360-G1 and the page retirement.
4. **Independently of Gate 8** — `studentDiscounts` is orthogonal to it, and bundling them would
   entangle two unrelated cutovers.

---

**Audit complete. Working tree HELD. Nothing modified. Awaiting instruction.**

---
---

# ADDENDUM · READ-ONLY PROBE RESULTS

**Run:** 2026-08-10 · **Authorized:** read-only data probe, one execution.
**Method:** a probe script written to the **scratchpad, not the repository**, so the working tree
stayed held. Verified before execution to contain **zero** write primitives — no `set`, `update`,
`delete`, `create`, `add`, `batch`, `commit`, `runTransaction`, `bulkWriter` or `FieldValue`. The
only Firestore calls present were `collection` / `doc` / `where` / `get`.
Expiry semantics copied verbatim from `Fees::fetch_months`; no rule invented.
`firebase-admin@12.7.0` (never v14).

---

## Q · THE ANSWER

### **SD-1 = B — CONFIRMED LIVE DATA.** One real student, one real school, ₹1,800 discrepancy.

| # | Question | Result |
|---|---|---|
| **A** | Total `studentDiscounts` documents | **1** — across the **entire collection, all tenants** |
| **B** | `onDemandDiscount > 0` | **1** |
| | `onDemandDiscount = 0` | 0 |
| | missing / null | 0 |
| **C** | Active + unexpired | **1** |
| **D** | Expired | **0** |
| **E** | Never-expiring | **1** (the same one) |
| **F** | Active student / inactive student | **1 / 0** · unresolved: 0 |

### G · The one record

```
Tenant     SCH_218AAF5C23 — "RVS Science & Sports Academy"
           status: active · created 2026-07-04 · current session 2026-27
Student    STU0032 "ARYA" · Class 1st / Section A · status Active · session 2026-27

onDemandDiscount    ₹1,800.00
totalDiscount       ₹1,800.00        (consistent — no drift in this record)
scholarshipDiscount ₹0.00
validUntil          (none — NEVER EXPIRES)
source              (none)
appliedAt           (none)
appliedBy           (none)
```

**This is not test data.** A live school, an active student, a current session.

### H · SD-1 reproducibility — **CONFIRMED**

### I · The exact numbers

```
STU0032 · 52 fee demands counted (0 archived/cancelled skipped)

  Student 360 Outstanding  =  ₹23,600.00     ← Σ feeDemands.balance
  onDemandDiscount         =   ₹1,800.00
  Counter netDue           =  ₹21,800.00     ← what the family is actually asked for

  DIFFERENCE               =   ₹1,800.00     ← SD-1, measured
```

Student 360 would show this family owing **₹1,800 more than the school will collect** — on the
headline figure, the red alert, and the folded Money headline.

---

## R · THREE THINGS THE PROBE CHANGED

**1 · The mechanism is barely used — one document in the whole collection.**
That materially lowers the *scale* of SD-1 while leaving its *severity* intact. Five writers, a
server-side authorization cap, a Firestore rule, an app constant and a full section of the old
profile page all exist to serve **one record**.

**2 · SD-3 is no longer theoretical — this record has no provenance at all.**
`source`, `appliedAt` and `appliedBy` are all empty. **Nobody can say who granted this ₹1,800, or
when.** All five writers stamp at least `appliedBy`/`appliedAt`, so this document was written by a
path that predates them — or by one no longer in the code. Combined with SD-3 (the
`ACC_DISCOUNT_WRITE` log discarded at INFO), there is **no way to attribute a live money decision.**

**3 · It never expires.** No `validUntil`. Absent intervention, this ₹1,800 reduction persists into
every future session — the doc id has no session component, so it survives rollover silently.

---

## S · IMPACT ON THE UAT

**The UAT tenant is `SCH_D94FE8F7AD`. The only discount lives in `SCH_218AAF5C23`.**

* SD-1 **cannot be reproduced during the UAT** — no student in the tested tenant holds a discount.
  This is consistent with the local backup showing `studentDiscounts: []` for STU0001.
* So SD-1 **will not corrupt UAT results** — and equally, **the UAT will not catch it.** A clean UAT
  pass would not be evidence that SD-1 is absent.

**J · Must SD-1 be resolved before the UAT?**

**No — but the *semantic decision* should be made before it.** The fix can wait; the definition
cannot, because it determines what a tester writes down as correct in test 3.1.1.

> Is Student 360's "Outstanding" **pre-discount** (what is billed) or **post-discount** (what will be
> collected)?

Both are defensible. Pre-discount matches `feeDemands` and the ledger. Post-discount matches the
counter and the family's experience. What is not defensible is the current state: the label says
"Outstanding" and means neither consistently.

**Recommendation:** decide the semantic now, implement in the stabilization phase with the other
verified defects.

---

## T · REVISED RETIREMENT POSITION

**Still B — RETIREMENT BLOCKED**, but the reason has narrowed usefully.

`studentDiscounts` is **not** a large live system to migrate — it is **one record**. The blocker is
no longer data volume; it is that the old profile page remains the **only** surface for five
capabilities (class fee chart, monthly summary, discount view, discount grant, repair auth), and the
only place that ₹1,800 is visible in a student's context.

**K · Does the old page still need to remain available? YES** — unchanged by this probe.

---

## U · WHAT REMAINS UNRESOLVED

| Item | Status |
|---|---|
| Provenance of the STU0032 ₹1,800 | **Unknown and unknowable from code** — no source/appliedBy/appliedAt, and the write log is discarded at INFO |
| Is that ₹1,800 intended? | **Not determinable technically.** Only RVS Science & Sports Academy can say |
| SD-2 (two writers, different semantics) | Untouched, as instructed |
| SD-3 (audit log discarded at INFO) | Untouched — now demonstrated by a real record |
| S360-G1 (no class fee structure) | Untouched |
| Whether other tenants will start using it | Unknowable — the grant UI is live in every tenant |

**One observation worth your judgement, not mine:** a live school has a permanent, unattributed
₹1,800 reduction on a child's fees. Whether that is a legitimate waiver someone forgot to document,
or an error, is a question for that school — but nothing in this system can currently answer it.

---

**Probe complete. One read-only execution. No data, code, rules, flags or UI modified.
Working tree HELD. Awaiting further authorization.**

---
---

# V · LOCKED SEMANTIC DECISION — 2026-08-10

> **SEMANTIC DECISION ONLY. NOT IMPLEMENTED.**
> SD-1 is **not fixed**. `studentDiscounts` is **not consolidated**. Gate 8 does **not** handle it.
> This section records what the numbers will mean once the stabilization phase is authorized.

**The statement of record:**

> *Student 360's primary operational money figure represents the currently collectable amount, while
> gross fee-demand outstanding remains separately visible.*

### Terminology (locked)

| Role | Label | Value for STU0032 |
|---|---|---|
| **Primary figure** | **Amount Due** | ₹21,800.00 |
| Gross generated demand | **Demand Outstanding** | ₹23,600.00 |
| Collection-layer reduction | **On-demand Discount** | −₹1,800.00 |

Neither number may appear unlabelled. The operator must be able to tell at a glance which one the
counter will accept.

### Formula (locked)

```
Demand Outstanding = Σ feeDemands.balance
                     where schoolId  == tenant
                       and studentId == student
                       and status ∉ { archived, cancelled }

Applicable Discount = studentDiscounts/{schoolId}_{studentId}.onDemandDiscount
                      where vu = trim(validUntil || valid_until)
                      → 0 if the document is absent
                      → 0 if (vu !== '' AND vu < today)        ← expired
                      → onDemandDiscount otherwise

Amount Due = max(0, round(Demand Outstanding − Applicable Discount, 2))
```

The expiry test and the `max(0, …)` floor are **copied from `Fees::fetch_months` (~2495, 2507) and
`FeeCollectionService::submit` (~150)**. No new validity rule is introduced. The floor matters: a
discount larger than the balance must render ₹0, never a negative.

### Layer boundary preserved

```
GENERATION   studentConcessions / studentEntitlements → feeDemands.netAmount → .balance
COLLECTION   studentDiscounts.onDemandDiscount → counter netDue → FCS authorization
                                               → receipt.discount → accounting
```

Student 360 **combines these for presentation only.** The domains do not merge.

### Open detail for the stabilization spec

⚠️ **`collect_payment`'s precondition currently keys off `fees.hasDues` (Demand Outstanding > 0).**
Under this decision it should key off **Amount Due**. Otherwise a student whose discount fully covers
the balance shows Amount Due ₹0 while the rail still offers *Collect Payment* — sending an operator
to a counter that will ask for nothing. Not a defect today (no such record exists); it becomes one
the moment a discount ≥ balance is granted. **Flagged, not implemented.**

### UAT consequence

The UAT tenant (`SCH_D94FE8F7AD`) holds **no** `studentDiscounts` record, so
**Amount Due == Demand Outstanding** for every student under test. Existing expected values are
unaffected. **Do not manufacture a discount to exercise this** — the semantic is recorded, not
runtime-tested, and SD-1 remains unreproducible in this tenant.
