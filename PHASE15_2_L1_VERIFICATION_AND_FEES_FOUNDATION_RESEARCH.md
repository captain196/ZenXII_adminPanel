# Phase 15.2 — L-1 Verification + Fees Foundation Research

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924` · **RESEARCH + PRE-IMPLEMENTATION ANALYSIS.**

**No source code modified. Nothing committed, pushed or deployed. UAT NOT EXECUTED.**
Per the standing PRODUCTION STABILITY FIRST rule, the 7-field pre-implementation analysis (§3)
is delivered **before** any code is written. **Awaiting go on §3 before implementing.**

---

## 1. L-1 VERIFIED — CONFIRMED DEFECT, correctly scoped to promotion only

**Verdict: REAL. 🟡 MEDIUM.** Phase 15.1 flagged this as unconfirmed; it is now traced end to end.

### The chain

```
Sis::execute_promotion()
  └→ Student_write_service::batchMoveStudents()        Sis.php:1600
       └→ writeToFirestore('syncStudent', …)           Student_write_service.php:406
            └→ Entity_firestore_sync::syncStudent()    Entity_firestore_sync.php:260
                 └→ $this->_write('students', …)       Entity_firestore_sync.php:409
                      └→ firebase->firestoreSet(…)     Entity_firestore_sync.php:1040  ← RAW
                                                        ✗ never reaches Firestore_service
                                                        ✗ _bustDashboard() never fires
```

`_bustDashboard()` is reachable **only** from `Firestore_service::set/update/remove/batch`
(lines 337, 356, 416, 716). `Entity_firestore_sync::_write()` calls `$this->firebase->firestoreSet()`
directly, bypassing it.

**The in-code comment at `Firestore_service.php:393` is factually wrong.** It states: *"Every write
that touches these collections routes through set/update/remove (incl. the \*Entity wrappers) → here,
so all of their mutation endpoints are covered with no per-endpoint edits."* The Entity wrappers do
**not** route through there. That comment is why this went unnoticed.

### Scope — I checked every student-lifecycle flow rather than assuming

| Flow | Busts cache? | Mechanism |
|---|---|---|
| **Promotion** | **✗ NO** | `batchMoveStudents` → `syncStudent` → raw `firestoreSet` **only** |
| Admission | ✓ yes | `fs->updateEntity('students', …)` ×3 (monthFee, subjects) — `Sis.php:956/988/1001` |
| Bulk import | ✓ yes | `fs->update('students', …)` for SRNO/APAAR — `Sis.php:5226+` |
| Deletion | ✓ yes | `fs->removeEntity('students', …)` — `Sis.php:6755` |
| Section transfer / status change | ✓ yes | routes through `fs->updateEntity` |

**Correction to my own working note during this trace:** I initially scored import and deletion as
stale too. Both were scan-window artifacts — a 400-line window that did not reach the SRNO write.
Widened to full-function scans, both bust correctly. **Promotion is the only affected flow.**

### Why it matters

For up to **90 s** (`MY_Controller.php:1325`) after a promotion, the shared `students_raw` snapshot
still shows every promoted student in their **old** class/section. Consequences, in severity order:

1. **Roll-number collision checks can miss a real collision.** `_rollNoConflict()` reads that
   snapshot. Promoted students are not yet visible in the destination class, so a roll already taken
   there reads as free. **This is a 90-second hole in the Phase 14.1 uniqueness guarantee — and it
   opens at exactly the moment it matters most**, since bulk roll assignment for the new session is
   the first thing an admin does after promoting.
2. **Capacity checks under-count the destination.** A section could be pushed over `maxStrength`.
3. Student List / SIS dashboard shows stale class for the same window.

### Recommended fix (not yet implemented)

One line at the end of `Sis::execute_promotion()`, after `batchMoveStudents()` returns, dropping
`listCacheKey('students')` + `listCacheKey('students_raw')`.

I deliberately do **not** recommend changing `Entity_firestore_sync::_write()` to route through
`Firestore_service` — that would alter the write path of **every** entity sync in the ERP (staff,
routes, sections…) and is far too broad a blast radius for this defect. **Fix the caller, and
correct the false comment at `Firestore_service.php:393`** so the next reader is not misled.

---

## 2. FEES FOUNDATION RESEARCH

### 2.1 Due-date model — the pieces already exist; only the wiring is missing

This is much better news than Phase 15.1 implied. The due-date infrastructure is **already built and
already live** — `Fee_lifecycle` simply never calls it.

| Piece | Status | Evidence |
|---|---|---|
| School configures a due day | **✅ EXISTS + LIVE** | `Fee_management.php:2154/2184`, UI at `views/fee_management/reminders.php:54` |
| Server-side reader | **✅ EXISTS + LIVE** | `Fee_firestore_txn::getDueDay()` — clamps **1–28**, defaults **10** |
| Demand carries a period | **✅ EXISTS** | `periodKey` = `YYYY-MM` — `Fee_lifecycle.php:302` |
| Demand doc has a `dueDate` field | **✅ EXISTS** | `Fee_firestore_txn.php:907` |
| camel→snake normalisation | **✅ CORRECT** | `normalizeDemandDoc()`: `dueDate → due_date`, line 691 |
| Late-fee engine reads it | **✅ EXISTS** | `Fees.php:4551`, compares against `date('Y-m-d')` |
| **`Fee_lifecycle` sets it** | **❌ MISSING** | **grep count of `dueDate` in `Fee_lifecycle.php` = 0** |

**Important distinction from F-1:** `due_day_of_month` is **not** a dead setting — unlike
`auto_remind`, it has a real reader. A school that sets "fees due on the 10th" has configured
something the code can honour. It is simply never applied at demand-generation time.

**Derivation is trivially safe:**
```
dueDate = sprintf('%s-%02d', $periodKey, $fsTxn->getDueDay())     // "2026-04" + 10 → "2026-04-10"
```
`getDueDay()`'s 1–28 clamp means **no month-length edge cases** — no 30 Feb, no leap-year branch.
`YYYY-MM-DD` compares correctly under the engine's lexicographic `>=`. **ZENX-VERIFIED.**

**The only real writer today** is `Fees.php:5028`, the separate bulk generator — confirmed by
repo-wide grep (the other `dueDate` hits are Homework, Library, LMS and a B2 test fixture, all
unrelated modules).

### 2.2 ⚠ THE HARD COUPLING — setting `dueDate` alone will silently erase fines

**This is the most important finding in this phase and it changes the implementation plan.**

Memory recorded this as a suspected coupled gate. **It is now ZENX-VERIFIED from source:**

`Fee_lifecycle::proRateFees()` — line 694:
```php
$newNet = round($grossAmt * $factor, 2);
$paidAmt = (float) ($d['paidAmount'] ?? 0);
$newBal  = round($newNet - $paidAmt, 2);      // ← fine_amount is NOT in this formula
...
$this->fsTxn->updateDemand($did, ['netAmount' => $newNet, 'balance' => $newBal, …]);
```

The late-fee engine writes `fine_amount` and folds it into `balance`
(`Fees.php:4566`: `$newBalance = $netAmount + $fine - $paidAmount`). **`proRateFees()` then
re-derives `balance` from `netAmount − paidAmount`, dropping the fine entirely.**

Today this is **inert** — no dueDate means no fine means nothing to erase. **The moment `dueDate` is
set, this becomes live financial data loss:** a family charged a late fee has it silently removed the
next time proration touches that demand (mid-session admission, service suspension, entitlement change).

> **Therefore AB must NOT ship alone.** `proRateFees()` must become fine-aware in the same change.
> Shipping the due date by itself converts a dormant bug into an active one.

### 2.3 Fee demand lifecycle — mapped

`Fee_lifecycle.php` public surface, all ZENX-VERIFIED:

| Stage | Method | Line |
|---|---|---|
| Build specs at admission | `buildAdmissionDemandSpecs()` | 220 |
| Preview before commit | `previewDemandSpecs()` | 397 |
| Assign at admission | `assignInitialFees()` | 472 |
| Reassign on promotion | `reassignFeesOnPromotion()` | 533 |
| **Mid-session proration** | `proRateFees()` | 662 |
| Sibling discount | `calculateSiblingDiscount()` | 745 |
| Freeze on soft-delete | `freezeFeesOnSoftDelete()` | 788 |
| Cancel on rollover | `cancelSessionDemands()` | 835 |
| Ad-hoc module fee | `createModuleFee()` | 897 |
| Reverse admission | `reverseAdmissionFees()` | 1022 |
| Consolidate | `consolidateFeeRecords()` | 1053 |

Duplicate protection is real: demands are keyed `periodKey|canonicalFeeHeadId` with an active-demand
skip (`Fee_lifecycle.php:308-315`). **Re-running generation does not double-charge.**

### 2.4 Installments — DO NOT EXIST (greenfield, not a gap)

**ZENX-VERIFIED:** repo-wide, the only file matching `installment` is
`services/PayrollAccountingService.php` — **staff payroll, unrelated to student fees.**

There is **no** student-fee installment model anywhere: no schedule, no part-payment plan, no
instalment doc. What exists instead is **monthly demand generation** (one demand per `periodKey`),
which covers the common Indian-school monthly-fee case but is **not** the same thing as a
term-fee split into 3 instalments with independent due dates.

**This is a new feature, not a defect.** It should be scoped and designed separately, and it is
**dependent on the due-date model landing first** (an instalment without a due date is meaningless).
I recommend it move out of Phase 15.2 into its own phase.

### 2.5 Mid-session admission

`proRateFees()` + `_remainingAcademicMonths()` (line 1101) exist and work. **One known open issue,
already logged in-code, not mine to reopen:** proration can push `netAmount` below what a family has
already paid; balance floors at 0 and **the credit owed is not recorded anywhere**
(`FEE_PRORATE_OVERCOLLECTION`, deferred to Phase C/AC4). Correctly made observable via an error log.

### 2.6 Exemption / concession

Governed by `fees_exemption_v2_flags.php`, **all flags FALSE**, per the parked Student Entitlements
work. **Out of scope for this phase and must stay that way** — the standing instruction is that
Gates 6+8 are one coordinated cutover, never partial. **I have not touched it.**

### 2.7 Withdrawal / re-admission and 2.8 Session rollover

`freezeFeesOnSoftDelete()` and `reverseAdmissionFees()` cover withdrawal;
`reassignFeesOnPromotion()` and `cancelSessionDemands()` cover rollover, the latter session-scoped
with a reason code. **No defect found in this pass** — but note both call sites will inherit the
`dueDate` change, and `reassignFeesOnPromotion` must stamp due dates for the new session's demands
or the gap simply reappears next year.

---

## 3. PRE-IMPLEMENTATION ANALYSIS — AB (due dates) + fine-aware proration

*Delivered before code, per PRODUCTION STABILITY FIRST. Awaiting your go.*

| Field | Analysis |
|---|---|
| **1. Classification** | **BUG** (financial correctness — demands are legally incomplete without a due date), not an enhancement. |
| **2. Root cause** | `Fee_lifecycle` was written before `getDueDay()` existed and was never retro-wired. The reader, the setting and the UI all exist. |
| **3. Change surface** | (a) `Fee_lifecycle`: stamp `dueDate` in `buildAdmissionDemandSpecs` + `reassignFeesOnPromotion` + `createModuleFee`. (b) `proRateFees`: include `fine_amount` in `$newBal`. **Two files. No controller, view, rule or index change.** |
| **4. Preserves the 16 subsystems?** | Additive on write. `dueDate` is a **new field on new demands** — no existing field's meaning changes. The proration fix corrects a formula that is currently unreachable, so it cannot regress live behaviour. Fees search, receipts, accounting, defaulters, Parent/Teacher apps read none of this at generation time. |
| **5. Cross-module impact** | **Parent app:** `FEE_DUE` push declares `dataKeys: ['amount','dueDate']` — this change is what finally makes that payload populatable. Nothing app-side breaks: a field appearing where `null` was tolerated. **Teacher app:** no fee surface. **Accounting:** unaffected — no ledger field changes. |
| **6. Rules / indexes** | **None required.** No new collection, no new query predicate. Late-fee computation is a per-student in-memory loop, not a `where dueDate <` query. *(A future scheduler that queries by `dueDate` **would** need a composite index — flag for Phase 15.3, not now.)* |
| **7. Rollback** | Clean. Both changes are formula/field additions with no migration and no destructive write. Reverting stops stamping new due dates; already-stamped demands remain valid and simply keep working. |

**Backfill question you must answer before I code (D7 below):** existing demands generated before
this change will still have no `dueDate` and will therefore stay permanently exempt from late fees.
Fixing them is a **separate, explicitly-authorized migration** — I will not touch production data.

**Latency:** `getDueDay()` is one `feeSettings` read. It must be **hoisted out of the per-demand
loop** and read once per generation run, or a 12-month generation becomes 12 extra reads per student.
I will memoise it.

**Test plan:** extend the unit suite with the derivation (`periodKey` + day → `YYYY-MM-DD`), the
1–28 clamp, lexicographic ordering vs `date('Y-m-d')`, and a regression test asserting
`proRateFees` **preserves** `fine_amount` in `balance`. Baseline stays 10 pre-existing failures.

---

## 4. DECISIONS REQUIRED

Carried forward and still open: **D1** (fix or remove `auto_remind`), **D2** (flat vs per-day fine),
**D3–D6** (override, promotion roll, survey script, AI placement).

**New this phase:**

| # | Decision |
|---|---|
| **D7** | **Backfill existing demands with due dates?** Without it, every demand created before the fix stays permanently immune to late fees. Needs explicit authorization + a dry-run script. |
| **D8** | **Confirm AB ships together with the proration fix.** I recommend refusing to ship them separately — see §2.2. |
| **D9** | **Move Installments out of 15.2 into its own phase?** It is greenfield, not a repair, and depends on due dates landing first. |

---

## 5. Git Status

```
Branch: integration/phase2   HEAD: 758c924   (unchanged)
Admin dirty +1 = this report only · Teacher 31 · Parent 23 — both UNCHANGED
firestore.rules / firestore.indexes.json — untouched
```

---

## WHAT THIS MEANS IN PLAIN TERMS

**The L-1 cache question is answered: it's real, but smaller than feared.** Only promotion is
affected — admission, import, deletion and transfers all clear the cache correctly. The catch is
*when* it bites: for about 90 seconds after promoting a class, the system can't see students in their
new class yet, so it may not notice that a roll number is already taken there. That's precisely the
moment an admin sits down to assign roll numbers for the new session. The fix is one line in the
promotion routine, plus correcting a comment in the code that confidently states something untrue and
is the reason nobody caught this earlier.

**On fees, there's genuinely good news.** The due-date machinery is already built. Your schools can
already set "fees are due on the 10th", there's already code that reads that setting, and the demand
records already have a place to put the date. The only missing piece is that the routine which
creates fees at admission never asks for it. That's a much smaller repair than it looked.

**But there's a trap, and I want to be clear about it before writing any code.** If I add due dates
on their own, late fees start working — and the moment they do, a different routine will begin
silently wiping them out. Fee proration recalculates what a family owes, and its formula doesn't
include late fees. Right now that's harmless, because no late fees exist to erase. Turn on due dates
alone and it becomes real money quietly disappearing from families' balances. **These two must be
fixed in the same change. I'd push back on any plan that separates them.**

**One scope correction.** Installments don't exist anywhere in the fees system — the only match in
the whole codebase is in staff payroll. What you have is monthly fee generation, which is not the
same as splitting a term fee into three parts with their own due dates. That's a new feature to
design, not a bug to fix, and it can't sensibly be built before due dates exist. I'd move it to its
own phase rather than carry it in this one.

**I've stopped before writing code**, as your standing rule requires, and put the full impact
analysis in §3. The main thing I need from you is D8 — confirmation that the due-date fix and the
proration fix ship together — and D7, whether existing fee records should be backfilled.
