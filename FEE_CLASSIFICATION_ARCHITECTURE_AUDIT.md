# 1 · UAT SRNO-1 ADMISSION STATUS  ·  2 · FEE CLASSIFICATION ARCHITECTURE AUDIT

**1: preconditions PASS, harness ready — the admission itself needs your UI login. NOT executed.**
**2: read-only audit complete. No production edit made. Phase C stays blocked.**
2026-08-17 · UAT `SCH_B56BB9A401`

---

# 1. UAT SRNO-1 ADMISSION — BLOCKED ON AUTHENTICATION, NOT ON SAFETY

## 1.1 Preconditions — ALL PASS, verified read-only just now

```
SRNO pointer ABSENT · srNoIndex 0 docs · claim_1..25 ABSENT
23 existing students · 0 with srNo · feeDemands 26
USE_ENTITLEMENT_BILLING = false
```

Snapshot captured to `UAT_SRNO1_ADMISSION_SNAPSHOT.json` for the post-admission diff.

## 1.2 Why I did not execute it

Your brief says: *"Perform exactly ONE admission through the real Sis.php UI/canonical admission
path. Do NOT use a script to simulate the controller."* I agree with that constraint — it is the whole
point of the test, and it is what my own plan proposed.

**I cannot satisfy it.** The app is running (`localhost:8080` responds, `/` → `/dashboard/`), but
`/sis/admission` requires an authenticated admin session, and **I have no admin credentials for
`SCH_B56BB9A401`** — nor should I acquire or work around them. The only ways I could "execute" this
are (a) driving the authenticated UI, which I cannot do, or (b) POSTing to the controller from a
script, which you explicitly forbade and which would bypass the CSRF/RBAC/session guards the test
exists to validate. So this is a genuine blocker, not a safety refusal.

## 1.3 What is ready for you

1. `php scripts/uat_srno1_admission_verify.php --pre` — already run, **PASS**, snapshot written.
2. **You perform one admission** at `/sis/admission` (POST route `sis/save_admission`): tenant
   `SCH_B56BB9A401`, any class/section with capacity, a clearly-marked UAT name, **no SRNO typed in**,
   one student, not a bulk import.
3. `php scripts/uat_srno1_admission_verify.php --post` — runs all 24 assertions automatically and
   diffs against the snapshot: the 12-step SRNO chain (pointer = 1, `claim_1`, `srNoIndex/..._1` with
   `srNo=1`, `currentStudentId`, **no session field**, empty history, student `srNo=1`, exactly one
   index doc, `claim_2..25` still absent) plus the side-effect set (23 legacy students byte-unchanged
   and still SRNO-less, STU0012's 26 demands intact, every demand with a `dueDate`, no receipt /
   award / entitlement / journal, other tenant untouched).

Section strength +1 and `billing_state = billed` are read from the admission response/log — tell me
what it returns and I'll fold them in. **Rollback stays static** (code inspection + the 11
`PhaseSrnoF1AdmissionIdentityTest` tests); I did not induce a failure and did not pre-create claims.

---

# 2. FEE CLASSIFICATION ARCHITECTURE AUDIT (READ-ONLY)

## 2.1 The lifecycle, as it actually exists

```
feeStructures/{school}_{session}_titles          ← the ONLY place a fee head is an ENTITY
   .feeHeads[] = { name, type }                     (name + billing frequency. NO category.)
        │
feeStructures/{school}_{session}_{class}_{section}
   .feeHeads[] = { feeHeadId, name, amount, frequency[, billingType] }   ← pricing rows. NO category.
        │
Fee_lifecycle::buildAdmissionDemandSpecs → $entryData (:377)   ← NO category key
        │                          …or… Fees::_generateDemandsForMonth (:4949) → _resolveFeeCategory()
        │                                                                          ← name heuristic
Fee_generation_service (:147)  $cat = $data['category'] ?? ''
        │
Fee_firestore_txn (:895)  'category' => $pick($data, ['category'])
        │
feeDemands.category            ← 95.8% empty
        │
Fee_entitlement_reader::appliesTo()  — invariant I3 tested against that value
```

## 2.2 The 23 questions

| # | Question | Answer |
| --- | --- | --- |
| 1 | Where is a fee head defined? | Two places, neither a collection: the per-chart row `feeStructures/{school}_{session}_{class}_{section}.feeHeads[]`, and the per-school+session registry doc `feeStructures/{school}_{session}_titles.feeHeads[]`. |
| 2 | Canonical identity? | `feeHeadId` (`FH_…`) on chart rows — **and it is NOT stable**. "Tuition Fee" has **8 distinct `feeHeadId`s across 8 charts**, "Transport Fee" 8, "Examination Fee" 7; several rows have none at all. It is a per-row id, not a head identity. No `feeHeads`/`canonicalFeeHeads`/`feeHeadMaster`/`feeMaster`/`feeTypes` collection exists (all **0 docs**). |
| 3 | Billing frequency? | Three spellings of one fact: `frequency` (`monthly`/`annual`) on chart rows, `billingType` (`Monthly`/`Yearly`) on newer rows, `type` (`Monthly`/`Yearly`) in the titles registry. |
| 4 | Category/classification? | **Stored nowhere on the head.** Only on the *demand*, and only sometimes. |
| 5 | Actual source of truth? | `feeStructures` for pricing + frequency. **For classification: nothing is.** |
| 6 | Is `feeCategories` the intended source of truth? | **No — it is a display grouping.** 0 docs platform-wide; keyed by free-text `name` + `fee_titles[]` *name* matching; its only consumer falls back to keyword guessing; and its own writer's vocabulary omits hostel and meals. It was built to group rows in the Fees UI, not to carry a financial invariant. |
| 7 | Head-level classification elsewhere? | **No.** The one adjacent thing is `Fee_lifecycle::createModuleFee` (:1137) writing `'category' => strtolower($moduleType)` — but that is *demand*-level and module-driven (Transport/Hostel), not head-level. |
| 8 | Can `canonicalFeeHeadId` resolve it? | **No.** Present on 1,092 of 1,242 demands, **absent on 150** — and it derives from the unstable per-row `feeHeadId` (Q2), so there is no stable entity to hang a classification on. |
| 9 | Do chart entries reference a category/type? | They carry `type`/`billingType`, but inspection of **every** row shows those hold only `Monthly`/`Yearly` — **billing frequency, not category**. |
| 10 | Transport/hostel/meals distinguishable without name heuristics? | **No.** The head name string is the only distinguishing datum in the data. |
| 11 | Where should it live? | The `_titles` registry is the only place a head is an entity — but it is **session-scoped**, and a head's *nature* is not a per-session fact. Recommend a **school-scoped fee-head registry** (the same scoping precedent SRNO just established), with pricing staying session-scoped. |
| 12 | Field into the canonical spec? | `category`, lowercase closed vocabulary, set in `$entryData` (`Fee_lifecycle:377`) — **the exact key `Fee_generation_service:147` already reads**, so no reader change is needed. |
| 13 | Field persisted to `feeDemands`? | `category` — **already in the schema and already picked up** by `Fee_firestore_txn:895`. The write path needs no schema change, only a populated value. |
| 14 | How to treat existing `category=''`? | **Grandfather them.** They cannot be reclassified deterministically (Q15) and rewriting them would silently move money (Q16). |
| 15 | Migration needed? | **The source data is insufficient for deterministic classification.** A demand stores the head *name* plus an unstable id; the chart stores no category. Any backfill would require a new operator-supplied name→category mapping — i.e. it is a *data-entry* task, not a migration. **No automatic migration is possible or proposed.** |
| 16 | Would changing classification alter balances? | **Not existing stored balances** — `category` is not an input to gross/net/paid/balance. But it gates whether a blanket concession *applies*, so populating it correctly **will change future generated net amounts** for any student holding a `scope:'all'` concession (today those 500 empty-category `Transport Fee` demands are wrongly nettable). That makes it a deliberate, flag-gated cutover — never a silent fix. |
| 17 | Immutable after generation? | **Yes.** Stamp at generation, immutable on the demand. Re-deriving at read time would let a later head rename retroactively change historic financial records. |
| 18 | Effect of a head rename? | **Today it silently reclassifies.** Rename "Transport Fee" → "Bus Charges" and the heuristic's `transport|bus` branch still catches it; rename to "Conveyance" and it becomes `General`, so **I3 stops protecting it** with no error. This is the single strongest argument against the name heuristic. |
| 19 | Deletion/archive? | There is no head entity to archive. Removing a chart row leaves existing demands referencing a name that no longer resolves — which again requires the classification to be **stamped on the demand**, not re-derived. |
| 20 | Session-specific charts? | The registry is per-session, so the same head can be classified differently in two sessions, or lose its classification at rollover. Reinforces Q11: classification school-scoped, pricing session-scoped. |
| 21 | Admission-time vs monthly regeneration? | **They diverge today.** Admission sets no category; monthly regeneration sets a heuristic one. Proven live: `Tuition Fee` exists as both `''` (501) and `Academic` (24); `Transport Fee` as `''` (500) and `Transport` (24). **The same head gets a different classification depending on which writer created the demand.** |
| 22 | Preserved during recalculation? | **Not guaranteed.** `Fee_firestore_txn:895` re-picks `category` from incoming data; a recalculating path that doesn't supply it can blank a previously-set value on merge. Must be explicitly preserved. |
| 23 | Bulk generation? | Uses the same `_generateDemandsForMonth` path, so it inherits the heuristic — and therefore the Q21 divergence from the canonical path. |

## 2.3 Data census (§F) — read-only, nothing altered

**1,242 demands platform-wide. 1,190 (95.8%) carry `category=''`.**

| category | count | | `category=''` by tenant | count |
| --- | --- | --- | --- | --- |
| **(empty)** | **1,190** | | `SCH_218AAF5C23` | 1,066 |
| `Academic` | 24 | | `(no schoolId)` | 98 |
| `Transport` | 24 | | `SCH_B56BB9A401` | 26 |
| `General` | 4 | | | |

**`category=''` by fee head:** Tuition Fee 501 · **Transport Fee 500** · (no head) 98 ·
Examination Fee 41 · Registration Fee 41 · Library Fee 9.

**500 `Transport Fee` demands are currently unclassified** — every one of them nettable by a blanket
concession, in direct violation of I3.

`canonicalFeeHeadId`: present 1,092 / **absent 150**.

**Determinism check — the same head, two different answers:**

```
Tuition Fee      (empty)=501   Academic=24
Transport Fee    (empty)=500   Transport=24
Examination Fee  (empty)=41    General=2
Registration Fee (empty)=41    General=2
Library Fee      (empty)=9
```

Classification is **non-deterministic for identical heads** — it records which code path happened to
write the demand, not what the fee is. Note also that `Examination Fee` and `Registration Fee`
resolve to `General`: the heuristic has no branch for them either.

## 2.4 Proposed closed vocabulary (§D) — design only

Derived from what the product actually has, not from what sounds useful. **The key recommendation is
to separate two things the code currently conflates: financial classification and display grouping.**

| Category | Means | Includes | Excludes | Concession/scholarship by default | Service? |
| --- | --- | --- | --- | --- | --- |
| `academic` | the school's own instructional charge | tuition, examination, registration, admission, library, lab, sports/activity levies | anything separately opted into | **Applies** | No |
| `transport` | vehicle-based conveyance | bus/van route fees, per-km or slab fares | staff travel, trips billed ad hoc | **Excluded** unless explicitly scoped | Yes |
| `hostel` | boarding and lodging | hostel/room/boarding fees | meals billed separately | **Excluded** unless explicitly scoped | Yes |
| `meals` | food service | mess, canteen, midday-meal plans | hostel room charge | **Excluded** unless explicitly scoped | Yes |
| `unknown` | classification absent or unrecognised | legacy `category=''`, retired heads | — | **Fails closed** — never silently academic | n/a |

**On `extra_curricular` and `other`:** I recommend **not** making them financial categories. They
exist today only as display groupings (`_resolveFeeCategory` returns `Extra-curricular`/`General`;
`save_category` offers `Extra-curricular`/`Other`). For the only financial question that matters —
*does a concession apply?* — they behave exactly like `academic`, so adding them creates two ways to
express one rule and a new way to get it wrong. Keep them in `feeCategories` as **display grouping**,
which is what that structure is actually good at.

**One open question I could not resolve from the code:** `SERVICE_CATEGORIES` includes `meals`, but I
found no meals module or writer — `createModuleFee` documents itself as "Transport + Hostel". So
`meals` may be aspirational rather than shipped. **Confirm whether ZenX bills meals today** before
the vocabulary is locked; I did not assume either way.

## 2.5 Why the name heuristic must not become the authority (§E)

Beyond your standing rule, the evidence: it silently reclassifies on rename (Q18); it cannot express
hostel or meals at all; it returns `General` for Examination and Registration; and it produces a
*different* answer from the canonical path for the identical head (Q21). It is legacy behaviour worth
documenting, and it is not an authority.

---

## State

Read-only audit. **No production edit. No migration. No backfill. No `feeCategories` change. No
canonical spec change.** Nothing implemented from §2.4 — it is a proposal for your review.

Not done and not started: scholarship/entitlement code · B3 · N+1 · SRNO-F2 · SRNO-F3 · the UAT
admission · any student modification. `USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY =
FALSE` · rules/indexes untouched (`5b0f86fc`, concurrent Staff-Lifecycle/HRMS work untouched) · no
RTDB · no commit, push or deploy.

**Awaiting: your UI login to run the one admission, and your review of the classification design.**
