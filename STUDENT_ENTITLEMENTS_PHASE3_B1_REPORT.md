# STUDENT ENTITLEMENTS — PHASE 3 / B1: ADMISSION ORDERING

**B1 = BLOCKED — STOPPED ON A DECLARED STOP CONDITION: the required change crosses into B2.**
**No production code changed. No live data touched. Nothing committed, pushed or deployed.**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Executive summary

B1's objective is:

> When a student is admitted and an approved entitlement/concession applies, the fee demand generated
> by that admission must be based on the entitlement that was captured/approved for that admission.

**That objective is unreachable by ordering alone.** Admission has *two* independent breaks, and only
one of them is ordering:

| # | Break | Phase that owns it |
| --- | --- | --- |
| 1 | Demands are generated before capture is possible | **B1** |
| 2 | The generator reads `studentConcessions`; entitlements live in `studentEntitlements` | **B2** |

Reordering fixes (1) and changes **no money**, because (2) still routes the price through a collection
the entitlement never populates. A student would still be billed gross — with the added danger that the
system now *looks* correct.

The phase brief says: *"If a required change crosses into B2, STOP and report it."* It does. I stopped
at that boundary, and used the phase to make the boundary provable rather than asserted.

**Delivered:** the complete traced sequence, thirteen executing tests that pin the defect and the
scenarios, the chosen architecture with alternatives rejected, the exact change list — **and one new
B2 prerequisite that would have broken B2 silently had it not been found now (§7).**

---

## 2. Exact current admission sequence — proven from source

```
Operator submits the Admission form  (views/studentAdmission.php)
        ↓
POST sis/save_admission                                  Sis.php:680
        ↓
RBAC · capacity :726 · roll uniqueness :740
        ↓
Fee-structure pre-flight :805        ─ blocks BEFORE any id is minted
        ↓
Admission-fee pre-check :812
        ↓
Student ID minted  _nextStudentId()  :819   ← exactly once
        ↓
Student document written  _placeStudentGuarded()  :1005   (atomic capacity)
        ↓
syncParent :1016 · section strength :1022 · phone index :1026
        ↓
monthFee init :1039                  ─ HARD FAIL if it does not stick
        ↓
Subjects :1059
        ↓
SRNO + APAAR  _claimIdentityAttributes()  :1114
        ↓
Firebase Auth user :1129
        ↓
★ FEE DEMANDS GENERATED  feeLifecycle->assignInitialFees()  :1136
        │     → _routedBuildAdmissionSpecs()        Fee_lifecycle:428
        │     → USE_UNIFIED_FEE_GEN = true
        │     → Fee_concession_reader (studentConcessions)   :444
        │     → Fee_generation_service::generateDemandsForStudent()
        │     → studentConcessions is EMPTY for a new student ⇒ NET = GROSS
        ↓
defaulter projection :1150 · history + audit :1157 · lead conversion :1165
        ↓
admission fee receipt + accounting :1180 · parent SMS :1190
        ↓
HTTP 200  { status:'success', user_id }
        ↓
★ ENTCAP.mount({studentId})   studentAdmission.php:1087   ← capture becomes possible HERE
        ↓
Operator adds a concession → POST entitlements/propose
        ↓
Entitlements::_approval_required() :139
   Fees-manage user  → status 'active'  IMMEDIATELY
   otherwise         → status 'pending_approval' → Entitlements::approve()
        ↓
Entitlement_service::propose()/approve() write studentEntitlements ONLY
        ↓
✗ NO demand regeneration. ✗ NO write to studentConcessions. ✗ NO callback into admission.
```

**Answers to the ten Step-0 questions:**

| # | Question | Answer |
| --- | --- | --- |
| 1 | Student document created | `Sis.php:1005` via `_placeStudentGuarded()` |
| 2 | Student ID / SRNO / admission no. | ID `:819`; SRNO + APAAR `:1114`; admission fee/number `:812` + `:1180` |
| 3 | Initial fee generation | `Sis.php:1136` → `Fee_lifecycle::assignInitialFees():541` |
| 4 | When demands are created | Inside that call, **before** the HTTP response |
| 5 | When capture becomes available | `studentAdmission.php:1087`, in the success branch **after** HTTP 200 |
| 6 | Proposed or approved? | **Both paths exist.** Fees-manage ⇒ `active` immediately; otherwise `pending_approval` |
| 7 | Approval synchronous? | Only for a Fees-manage operator, and still **after** the admission request has completed |
| 8 | Callback / re-entry path | **None.** No endpoint re-enters admission or regenerates demands |
| 9 | Regeneration after approval | **No.** `approve():160` writes the entitlement row and nothing else |
| 10 | Atomic submit of admission + entitlement | **No.** Two separate operations, two HTTP requests |

---

## 3. B1 root cause, and why a full-price demand results

**Root cause (ordering).** `save_admission()` contains no reference to the entitlement system at all —
verified by assertion, not by reading: `Entitlement_service`, `entitlementSvc`, `studentEntitlements`
and `entitlements/propose` are all **absent** from the method body. So when `assignInitialFees()` runs
at `:1136`, no entitlement can exist for that student, because the only surface that creates one has
not been mounted yet.

**Why the bill is gross even after capture (authority).** The generator resolves concessions through
`Fee_concession_reader` → `studentConcessions`. `Entitlement_service` writes only
`studentEntitlements`. There is no bridge in either direction. So the record is created, approved,
audited and displayed — and is invisible to billing.

`test_B1_02` proves this numerically with production code: demands at admission = **1000**; then an
**active, applicable** 20% entitlement is created; regeneration still yields **1000**.
`test_B1_02b` isolates the cause — the *same* entitlement, priced through the new reader's accessors,
yields **800**. The data is fine. The authority is the gap.

---

## 4. Chosen architecture

**Chosen: Option C — one server-side workflow that resolves entitlements before pricing.**

```
POST sis/save_admission  (form now carries an optional entitlement payload)
   1. validate  ·  capacity  ·  roll  ·  fee-structure pre-flight
   2. mint Student ID
   3. write student (atomic capacity, unchanged)
   4. identity: SRNO / APAAR / admission number      ← unchanged position
   5. NEW — resolve entitlements for this admission:
        • persist the submitted entitlement(s) via Entitlement_service
        • auto-approve only where _approval_required() already says so
        • on refusal/failure: DO NOT generate — return an explicit
          "admission created, billing pending" state
   6. generate demands  ← now sees the entitlement
   7. defaulter · history · audit · receipt · notify
   8. respond with an explicit billing state
```

**Why C over the alternatives:**

- **A (student → capture → generate)** splits one financial decision across two HTTP requests. Between
  them the student exists with **no demands**, which is precisely the STU0004-11 incident the
  fee-structure pre-flight was added to prevent. It also has no way to guarantee step 3 ever happens.
- **B (generate, then regenerate on approval)** is the most dangerous: it bills twice, and regeneration
  after a payment has been taken is a reconciliation problem, not a fix. It also fails the brief's
  NEVER-list item 4 (*fee demands generated twice*) unless a further idempotency layer is built.
- **D** — no other architecture is supported by the existing code: there is no callback, no queue and no
  workflow engine on this path.

**C preserves everything B1 must not disturb:** identity minting stays at `:819` (once, before any
write); the atomic-capacity placement is untouched; the fee-structure pre-flight still blocks before an
id is burned; history, audit, parent sync, receipt and accounting keep their positions; and demand ids
stay deterministic, so a retry overwrites rather than duplicates.

**The UI contract** (option 3 of the brief's list): the operator must never see a bare *"Admission
successful"*. The response carries an explicit billing state — `billed` / `billing_pending` /
`billing_failed` — and the page states it. Entitlement capture stays where it is for the *post*-hoc
case, but an entitlement known at admission time is submitted **with** the form.

**This design is not implemented.** It cannot be, without B2 (§5).

---

## 5. Why implementation stopped — the declared stop condition

Step 6 of the design says "generate demands ← now sees the entitlement". It only sees it if the
generator reads `studentEntitlements`. That is the reader swap, which the brief forbids in this phase:

> Do NOT switch `Fee_concession_reader` → `Fee_entitlement_reader` in this phase.
> Do NOT make `studentEntitlements` the billing authority yet.

Implementing the ordering alone would produce a system that:

- reorders admission and changes **zero rupees**;
- makes the operator-facing flow *look* like the concession was applied;
- and satisfies none of the acceptance tests B1-03/04/05, whose literal requirement — *"fee generation
  **reads** the approved entitlement"* — **is** B2.

The only way to satisfy B1 without B2 would be to have admission write the concession into the legacy
`studentConcessions` as well. I did **not** do that: it creates a second write authority that Phase 4
would have to unwind, and it walks straight into NEVER-list item 5 (*old concession and new entitlement
both discount the same demand*) the moment B2 lands. It is offered in §10 as a decision for you, not
taken unilaterally.

---

## 6. Files and functions that must change (when B1+B2 are authorized together)

| File · function | Change |
| --- | --- |
| `Sis.php::save_admission()` ~`:1136` | accept an optional entitlement payload; resolve it after identity and **before** `assignInitialFees()`; return an explicit billing state |
| `Sis.php::save_admission()` ~`:1136` | stop swallowing an `assignInitialFees` failure (blocker **B6**) — currently `catch` + log, no `json_error` |
| `views/studentAdmission.php` | submit a known entitlement with the form; render the billing state; keep `ENTCAP` for the post-hoc case |
| `Fee_lifecycle::_routedBuildAdmissionSpecs()` `:428` | **B2** — reader selection behind its own flag |
| `Fee_generation_service::generateDemandsForStudent()` `:101-102` | **B2 prerequisite** — see §7 |
| `Entitlement_service::propose()` `:95` | dedupe key (blocker **B5**) so an admission retry cannot create two active concessions |

---

## 7. New finding — a B2 prerequisite that would have failed silently

Found while building the tests, and pinned by `test_B2_PREREQ_generator_hardcodes_legacy_accessors`.

`generateDemandsForStudent()` does **not** ask the reader for the deduction type and value. It hardcodes
the legacy field names inline:

```php
fn(array $c): string => (string) ($c['type']  ?? ''),   // Fee_generation_service:101
fn(array $c): float  => (float)  ($c['value'] ?? 0)     // :102
```

An entitlement stores those as `valueType` / `value` and has **no `type` field at all**. So swapping
only the reader would fetch the right rows through the wrong fields: `type` resolves to `''`, no branch
matches, and **every entitlement would deduct zero** — the exact "approved concession, gross bill"
failure this programme exists to close, reintroduced by the fix meant to close it.

The shadow path already does it correctly (`:233-238` calls `deductionType()` / `deductionValue()`), so
the remedy is to use the reader's accessors on the authoritative path too. **Not done here — it is B2.**

This is the single most valuable output of the phase: it converts a latent B2 landmine into a test that
fails the moment anyone attempts the swap without it.

---

## 8. Tests

`tests/Unit/Phase3B1AdmissionOrderingTest.php` — **13 tests, 43 assertions, all passing.** They execute
the real `Fee_generation_service`, the real `Fee_concession_reader` / `Fee_entitlement_reader`, and the
real deduction arithmetic via reflection. No fee arithmetic is re-implemented; the doubles supply the
chart and the candidate rows only.

| Test | Status | What it establishes |
| --- | --- | --- |
| B1-01 | ✅ | demands are generated before capture is possible (source-order, comments stripped) |
| **B1-02** | ✅ | **the defect** — active applicable entitlement leaves the bill at 1000 |
| **B1-02b** | ✅ | the same entitlement via the new authority bills 800 — data is fine, authority is the gap |
| B1-05 | ✅ | Scenarios 2/3/4 — percent 20 → 800, fixed 500 → 500, fullExempt → demand dropped |
| B1-06 | ✅ | Scenario 1 — no entitlement → gross |
| B1-07 | ✅ | Scenario 7 — rejected entitlement → gross |
| B1-08 | ✅ | not-yet-effective entitlement → gross |
| B1-09 | ✅ | Scenario 6 — academic concession does **not** discount transport (approved F2 rule) |
| B1-10/12 | ✅ | regeneration is idempotent — same ids, same money, one demand per head/period |
| B1-11 | ✅ | pins blocker **B6**: a fee-generation failure is swallowed, admission still reports success |
| B1-13 | ✅ | Student ID minted exactly once, before the student write; duplicate refused |
| B1-14/15 | ✅ | history, audit and parent sync remain in the admission path |
| B2-PREREQ | ✅ | §7 — the hardcoded legacy accessors |

**Not written, because they cannot pass without B2:** B1-03 (approved entitlement exists before
generation), B1-04 (generation reads it), and the *positive* half of B1-05. Writing them to fail would
have added three permanent red tests to a suite whose baseline the operator tracks; writing them to pass
would have required the B2 change. Both are recorded here instead.

---

## 9. Test results

| | Result |
| --- | --- |
| B1 suite | **OK — 13 tests, 43 assertions** |
| Full Unit suite, before this phase | 1716 tests / 10 failures |
| Full Unit suite, after | **1729 tests, 5449 assertions, 10 failures** |
| Baseline failures | **10** — the documented set, unchanged |
| **New failures** | **0** |

1716 → 1729 is exactly the 13 tests added.

---

## 10. Before/after fee calculation, and the decision you now hold

Class 5th tuition **₹1000/month**, student with an approved **20% academic** concession:

| | Billed | Correct? |
| --- | --- | --- |
| Today (B1 + B2 both open) | **1000** | ✗ |
| B1 alone (ordering fixed, reader unchanged) | **1000** | ✗ — no change, but now *looks* right |
| B1 + B2 together | **800** | ✓ |

**The decision:** B1 has no standalone value. I recommend authorizing **B1 + B2 as one phase**, in this
order — (a) the §7 accessor fix, (b) the reader swap behind its own flag, (c) the admission reordering,
(d) the UI billing-state contract. The estate is still empty (0 records platform-wide), so this remains
the cheapest possible moment, and no reconciliation is required.

If you prefer to keep them separate, the alternative is the legacy dual-write described in §5 — I do not
recommend it, and would want that instruction explicitly.

---

## 11. Live data, flags and untouched scope

**Zero live writes.** No admission performed, no student, demand, receipt, entitlement or concession
created or altered. The B1 suite opens no Firestore connection at all.

**Zero production changes this phase.** Verified by digest — every file identical to the Phase 2
end-state: `Sis.php 9222e572` · `Fee_lifecycle.php facbbcf3` · `Fee_generation_service.php 1ee335a9` ·
`Entitlement_service.php 7bccb08b` · `studentAdmission.php d9cfd934` · `firestore.rules 4f9ed6e1` ·
`firestore.indexes.json c473c914`.

**No flag changed.** All six entitlement flags and both capacity flags are as found. No cutover flag
enabled. No RTDB. No rules or index changes. Atomic-capacity code untouched. Parent and Teacher apps
untouched.

Only one new file: `tests/Unit/Phase3B1AdmissionOrderingTest.php`.

---

## 12. Status

```
F1 = PASS
F2 = PASS — APPROVED (operator sign-off received; rule unchanged this phase)
F3 = PASS
B1 = BLOCKED — design complete, defect proven, implementation requires B2
B2 = UNTOUCHED
B3 = UNTOUCHED

REAL_ADMISSION_READY = FALSE
```

**Stopping here for your review, as instructed. No real admission. No commit, no push, no deploy.**
