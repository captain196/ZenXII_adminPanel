# Phase 15.4 — Correctness-Chain Research + Pre-Implementation Analysis

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**NO CODE WRITTEN. Nothing committed, staged, pushed, merged, rebased or deployed.
No production data read or written. No Firestore rules or indexes touched. UAT NOT EXECUTED.**

---

# EXECUTIVE SUMMARY

Re-verifying every prior claim against live source — as instructed, treating my own earlier reports as
evidence rather than truth — **overturned three of my own conclusions.** Two of ZenX's fee subsystems
are in materially better shape than I reported, and one defect is materially worse.

**Corrections to my own prior reports:**

| # | My earlier claim | Verified reality |
|---|---|---|
| **C-1** | *"Fine income is probably mixed into fee revenue"* (K-1, INFERENCE) | **WRONG. Fine is correctly credited to a dedicated ledger `4060 Late Fee`** with a `4010` fallback — `Operations_accounting.php:1403-1414`. Double-entry is already correct. **K-1 needs no fix.** |
| **C-2** | *"Grace period ≥15 days is compliance-driven / non-optional"* | **OVERSTATED — exactly as you warned.** That was higher-**education** legal-advisory content. **Fee regulation in India is STATE jurisdiction**, varying by state. There is **no national K-12 grace-period rule.** |
| **C-3** | *"No fee scheduler / background infrastructure exists"* | **IMPRECISE.** `controllers/FeeWorker.php` is a hardened background job processor (claim → idempotency → reap-stuck → structured logging). It is not a *late-fee* scheduler, but **the pattern to build one already exists and is battle-tested in this module.** |

**And one defect is worse than reported. G-3 is not a UX gap — it is an internal-control defect:**

> **The cashier types the fine. Nothing validates it.** A COUNTER-role user can type any
> `fineAmount` — including `0` — and nothing compares it to the computed `fine_amount`, caps it, or
> records that a divergence occurred. **Meanwhile a discount requires MANAGE_ROLES**
> (`Fees.php:1925`). So the lowest-privileged fee role can waive a penalty by typing zero, while the
> financially equivalent act of granting a discount requires a higher role. **That asymmetry is a
> revenue-leakage path with no audit trail.**

**Net position: the correctness chain is 4 links, and 3 of them are safe to fix together.**
Accounting (K-1) drops out — it was never broken.

---

# RESEARCH PERFORMED

**External:** Indian fee-regulation jurisdiction and statutes; late-fee/fine models; fee reminders and
schedulers; roll-number assignment; ID-card replacement. Sources listed at the end with labels.

**ZenX source:** exhaustive `feeDemands` producer sweep; `Fee_lifecycle` generator trace;
`proRateFees` formula; `FeeCollectionService` fine sourcing; `Operations_accounting` journal
construction and ledger mapping; `FeeWorker` job pattern; RBAC gates across Fees; `Entity_firestore_sync`
write path; capacity/roll validators.

---

# SOURCES AND EVIDENCE LABELS

## ⚠ C-2 — CORRECTING MY OWN LEGAL OVERSTATEMENT

**SOURCE-VERIFIED:** *"Fee-related matters of private schools come under the jurisdiction of State
Governments and UT administrations"* — the Centre clarified this in the Rajya Sabha
([Free Press Journal](https://www.freepressjournal.in/education/who-regulates-private-school-fees-centre-clarifies-in-rajya-sabha-that-states-and-uts-have-jurisdiction)).

State statutes exist and **differ**:

| State | Instrument | Relevant provision | Label |
|---|---|---|---|
| Delhi | [Delhi School Education (Transparency in Fixation and Regulation of Fees) Act, 2025](https://foxandmandal.co.in/the-delhi-school-education-(transparency-in-fixation-and-regulation-of-fees)-act,-2025) | Penalties ₹1–5 L first offence, ₹2–10 L repeat — **on the school for fee violations** | SOURCE-VERIFIED |
| Maharashtra | Maharashtra Educational Institutions (Regulation of Fee) Act | Governs penalty/interest a school may charge | SOURCE-VERIFIED |
| Gujarat | Gujarat Self-Financed Schools (Regulation of Fees) Act, 2017, **Rule 7(7)** | **No school shall collect more than one quarter's fees at a time** | SOURCE-VERIFIED |

**What I must retract:** the "≥15 days grace" figure came from higher-education legal-advisory
content and **is NOT established as Indian K-12 law.**
**NOT ESTABLISHED BY SOURCES:** any national K-12 minimum grace period, maximum late-fee percentage,
or mandated cap.

**ZENX RECOMMENDATION (unchanged conclusion, corrected reasoning):** grace and cap must be
**per-school configurable with safe defaults** — *not* because a national rule demands it, but
because **the governing rule is state-specific and ZenX is multi-tenant.** Hardcoding any penalty
policy would be wrong for some tenants by construction. Gujarat Rule 7(7) additionally constrains any
future installment design — flagged for that phase, not this one.

## Other external findings

| Claim | Label | Source |
|---|---|---|
| Late-fee config axes: flat vs per-day, cap, grace, current-month vs total-pending basis | SOURCE-VERIFIED | [Campus24x7](https://campus24x7.in/blogs/school-fee-structure-erp-setup-guide-india), [Knwdle](https://knwdle.com/tools/late-fee-calculator) |
| Fine rules + exemptions + escalation + transaction record are standard ERP features | INDUSTRY PRACTICE | [schoolerp.co.in](https://schoolerp.co.in/fees-and-fine-management.html) |
| Reminders ~3 days pre-due on a daily scheduled trigger; escalation tiers | SOURCE-VERIFIED | [n8n](https://n8n.io/workflows/7039-send-school-fee-reminders-with-payment-links-via-email-and-whatsapp/), [SchoolDeck](https://databus.co/schooldeck/features/auto-due-reminders/) |
| **Payers must be scrubbed from the reminder queue on payment** | SOURCE-VERIFIED | SchoolDeck |
| Roll assignment = explicit class+section bulk screen; separate Exam Roll Number | SOURCE-VERIFIED | [VirtueSkool](https://virtueskool.com/HelpAndManual/HTML/assign-roll-numbers.html) |
| Silent auto-roll at admission; ordering rules; alphanumeric conventions; house/range/reserved rolls | **NOT ESTABLISHED** | — |
| ID replacement: charge lost · damaged free on surrender · stolen+report waived · aged free | SOURCE-VERIFIED **(US higher-ed only)** | [Berkeley](https://bsa.berkeley.edu/news/notification-fee-replace-lost-cal-1-card), [Illinois State](https://redbirdcard.illinoisstate.edu/card-security/), [CampusIDNews](https://www.campusidnews.com/lost-id-card-it-will-cost-you/) |
| Those practices apply to Indian K-12 | **NOT ESTABLISHED** | — |

---

# ZENX SOURCE VERIFICATION

## Exhaustive demand-producer sweep (§3 — "do not assume the known list is exhaustive")

Swept every `feeDemands` reference repo-wide. **Writers** (excluding probes/verifiers/tests):

| Producer | Location | Stamps `dueDate`? |
|---|---|---|
| `buildAdmissionDemandSpecs()` | `Fee_lifecycle.php:220` | ❌ |
| `assignInitialFees()` | `Fee_lifecycle.php:472` | ❌ |
| `reassignFeesOnPromotion()` | `Fee_lifecycle.php:533` | ❌ |
| `previewDemandSpecs()` | `Fee_lifecycle.php:397` | ❌ (must match admission or preview lies) |
| `proRateFees()` | `Fee_lifecycle.php:662` | ❌ (updates existing — must **preserve**) |
| `createModuleFee()` | `Fee_lifecycle.php:897` | ❌ — **FROZEN v2, excluded** |
| `cancelSessionDemands()` | `Fee_lifecycle.php:835` | n/a (cancels) |
| Bulk generator | `Fees.php:5028` | ✅ **only one that does** |
| Fine writer | `Fees.php:4569` (`updateDemand`) | n/a (updates fine only) |
| Collection allocation | `Fees.php:3306`, `FeeWorker.php` | n/a (updates paid/balance) |
| Re-admission | via `enroll_student` → admission path | ❌ inherited |

**ZENX-VERIFIED: `Fee_lifecycle.php` contains ZERO `dueDate`/`due_date` occurrences (grep count 0).**
Additional readers found in `Fee_management.php:2230/4373/4464`, `Classes.php:620`,
`Communication_helper.php` — **read-only, unaffected.**
**No previously-unknown demand producer was found.** The list is now exhaustive.

## K-1 ACCOUNTING TRACE — RESOLVED, my inference was wrong

`FeeCollectionService.php:1065-1076` passes to the journal builder:
```php
'allocations'     => $allocationsForBatch,   // per-fee-head credit lines
'fine_amount'     => round((float) ($fineAmount ?? 0), 2),
'discount_amount' => round((float) ($discountFees ?? 0), 2),
```
with the in-code rationale: *"Fine + discount are also passed for proper double-entry — discount
becomes Dr Discount-Allowed expense rather than reducing revenue."*

`Operations_accounting.php`:
- **:1164** — keyword map `'4060' => ['late','fine','penalty','overdue']`
- **:1262** — documented entry shape: `Cr 4060 Late Fee ₹Z (if fine > 0)`
- **:1403-1414** — *"Late fee / fine: separate credit to 4060 (or 4010 fallback)"*

| Question (§8) | Answer |
|---|---|
| Fine separately classified? | **✅ YES — ledger 4060 "Late Fee"** |
| Which ledger? | `4060`, falling back to `4010` only if the school's chart lacks 4060 |
| Mixed with tuition income? | **No** — only via the explicit 4060-absent fallback |
| Visible in reports? | Yes, as a distinct revenue account |
| Idempotent? | ✅ deterministic `journal_entry_id = JE_FEE_{receiptKey}` |
| Cash/Bank correct? | ✅ `chartOfAccounts` is the single source of truth; bank ledger validated as existing/active/`is_bank`/postable-leaf (`FeeCollectionService.php:86-97`) |
| Behind a flag? | **No.** `concessions_enabled` (default false) gates **only** the additive `concessions[]` payload — **not** the fine line |

**VERDICT: K-1 requires NO FIX and NO architectural decision. It drops out of the correctness chain.**
Residual: whether refund/reversal correctly reverses the 4060 credit is **NOT ESTABLISHED** — but it is
**unreachable today** (no fines exist) and belongs with the reversal/waiver design, not this release.

## C-3 — FeeWorker correction

`FeeWorker.php` is a Phase 7C/7D background processor for **payment allocation**: polls `feeJobs`,
reaps stuck jobs (>5 min), claims queued→processing, idempotency-checks, executes the stashed batch,
releases locks in `finally`, structured `FEE_JOB_*` logging.

It is **not** a due-date/late-fee/reminder scheduler — my "late fees are manual per-student" finding
stands. **But "no fee background infrastructure exists" was wrong**, and this materially de-risks the
future scheduler: **reuse this proven queue/claim/idempotency/reap pattern rather than invent one.**

---

# ISSUES FOUND

### G-3 · Cashier-controlled fine — **INTERNAL CONTROL DEFECT** 🔴 HIGH
- **Evidence:** ZENX-VERIFIED. `FeeCollectionService.php:128-130` sources fine from
  `$controller->input->post('fineAmount')`. `fine_amount` appears in `views/` **only** as read-only
  display (`student_ledger.php:422/543`). No form prefills it; nothing validates it.
- **Business:** Computed fines are uncollectable without retyping; typed fines can over- or
  under-charge with no reconciliation. **Revenue leakage with no audit trail.**
- **Technical:** Two disconnected fine mechanisms.
- **Security:** **Authorization asymmetry** — `submit_discount` requires MANAGE_ROLES
  (`Fees.php:1925`); an equivalent-effect fine waiver requires only COUNTER_ROLES
  (`Fees.php:2976`) and a typed `0`.
- **Cross-module:** Receipt, Accounting (4060 line is only as correct as the typed number), Parent App.
- **Performance:** none.
- **Recommendation:** Make the computed fine the **canonical** value; allow override only as an
  **explicit, authorized, reason-coded, audited** act. **Action: FIX NOW (A/B — required + safe).**

### AB · `dueDate` never stamped 🔴 HIGH
ZENX-VERIFIED, 6 generator paths. Blocks late fees entirely. **FIX NOW**, excluding frozen
`createModuleFee`.

### F-1 · Proration erases fines 🔴 HIGH
ZENX-VERIFIED. `Fee_lifecycle.php:694` `$newBal = round($newNet - $paidAmt, 2)` omits `fine_amount`;
engine uses `netAmount + fine − paidAmount`. Inert today; **AB makes it live financial loss.**
**FIX NOW — indivisible from AB.**

### L-1 · Promotion cache staleness 🟡 MEDIUM
ZENX-VERIFIED. `syncStudent` → `_write` → raw `firestoreSet` (`Entity_firestore_sync.php:1040`)
bypasses `_bustDashboard`; 90 s TTL (`MY_Controller.php:1325`). Promotion is the **only** affected
flow. Worst impact: roll-collision misses. **FIX NOW at the caller**, plus correct the false comment
at `Firestore_service.php:393`.

### H-1 · Misleading `auto_remind` 🔴 HIGH
ZENX-VERIFIED dead setting; `FEE_DUE` registered (`functions/index.js:123`), never emitted.
**FIX NOW by disabling the control** (not by building fake automation).

### G-1 · No grace / cap 🟡 MEDIUM
SOURCE-VERIFIED that these are real config axes; **state-specific, not national law** (C-2).
**SHOULD FIX with the late-fee policy release.**

### K-1 · Fine accounting — ✅ **NO DEFECT** (corrected)

---

# IMPLEMENTATION DETAILS

**None. No code written this phase**, per the instruction to establish the dependency chain first.

---

# CLASS / SECTION REGRESSION

| Item | Status |
|---|---|
| Direct admission / CRM / import capacity | ✅ enforced (Ph15) |
| Promotion / transfer / re-admission capacity | ✅ guarded — **promotion degraded by L-1** |
| Session isolation | ✅ `sectionDocId()` embeds session |
| Active vs Withdrawn occupancy | ✅ withdrawn frees seat |
| Full-section behaviour · most-headroom suggestion | ✅ **keep — no balancing algorithm invented** |
| Import preview capacity | ❌ gap (🟢 LOW) |
| Full-section override | ❌ absent — needs business decision (D3) |
| Concurrency | ⚠ detection-grade, not atomic |
| Promotion cache invalidation | ❌ **L-1** |

# ROLL NUMBER REGRESSION

Scoping (School+Session+Class+Section) ✅ · manual ✅ · atomic Suggest claim ✅ · duplicate detection ✅
(Active-only) · alphanumeric-safe ✅ (string compare, **no numeric cast**) · promotion carries +
collision-checks ⚠ **degraded by L-1** · transfer ✅ · import ✅ · re-admission ✅ · historical
duplicates ❓ (survey blocked, D5) · **bulk assign screen ❌ — the one SOURCE-VERIFIED gap** ·
Exam Roll Number ❌ absent (flag only).

**No silent auto-assignment introduced. No global uniqueness introduced. No renumbering proposed.**

# FEES REGRESSION

Setup ✅ · generation ✅ · **due date ❌ (AB)** · payment ✅ · **penalty ⚠ unreachable + G-3** ·
**proration ⚠ F-1** · collection ✅ (but G-3) · receipt ✅ identity correct, ⚠ fine value ·
**accounting ✅ (K-1 resolved)** · refund/reversal ✅ exists, fine-reversal unverified ·
waiver ❌ absent · parent display ⚠ pending due dates · **reminders ❌ H-1**.

# ACCOUNTING TRACE
See K-1 above. **Correct as built.**

# CROSS-PLATFORM TRACE

| Path | Impact |
|---|---|
| READ | Apps already download whole demand docs — `dueDate` costs **zero** extra reads |
| WRITE | Admin only |
| SYNC | none |
| CACHE | L-1 fix removes a stale-read path |
| ACCOUNTING | unchanged (already correct) |
| NOTIFICATION | `FEE_DUE` payload becomes satisfiable; **no second dispatcher** |
| CROSS-PLATFORM | Parent: field appears where `null` was tolerated — non-breaking for Kotlin `String?`. **Teacher App: no fee surface, zero impact.** |

**Student ID remains the sole canonical FK.** SRNO/Roll introduced as FK nowhere. **ZENX-VERIFIED.**

# TEST RESULTS
**Not run — no code changed.** Baseline remains **10 pre-existing failures** (Backup ×1,
Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2); the campaign docs' "4 failures + 27
skipped" is a different, older baseline. Comparison will be against the 10.

# PERFORMANCE RESULTS
Proposed changes add **no per-student read**. `getDueDay()` **must be hoisted/memoised** — in-loop it
is a 12-reads-per-student N+1. Proration fix is pure arithmetic (0 I/O). L-1 fix is 2 cache deletes,
**net-negative latency**. Future scheduler `where dueDate <=` would need a composite index —
**not this release**.

# SECURITY / AUTHORIZATION RESULTS

| Action | Gate | Verdict |
|---|---|---|
| Collect fees | COUNTER_ROLES + `Fees:edit` | ✅ |
| Submit discount | MANAGE_ROLES + `Fees:edit` | ✅ |
| Compute fines | MANAGE_ROLES + `Fees:edit` | ✅ |
| Reminder settings | FINANCE_ROLES + `Fees:edit` | ✅ |
| **Set/waive fine at counter** | **COUNTER_ROLES only, unvalidated** | ❌ **G-3** |

All gates are **server-side** via `_require_role(..., module, level)` → RBAC — not hidden-UI reliance.
**The single defect is G-3's asymmetry.** No privilege escalation proposed.

# UAT STATUS
**UAT NOT EXECUTED.** No code changed. Matrix (§19) prepared for post-implementation.

# REMAINING ISSUES — with reasons, not "future work"

| Issue | Why it remains |
|---|---|
| `createModuleFee` due dates | **BLOCKED BY FROZEN ARCHITECTURE.** `fees_exemption_v2_flags.php` states Phase 3 converges service generation off it. Touching it corrupts parked uncommitted work. Must ride the entitlements cutover. |
| ID-card replacement | **BLOCKED** — same frozen surface. Also a **security** workflow: ID cards carry signed campus-access tokens (`Attendance.php:4259-4263`), so replacement must invalidate the old credential. Design documented; not built. |
| Fine reversal / waiver accounting | **INSUFFICIENT EVIDENCE** — NOT ESTABLISHED BY SOURCES; also unreachable until fines exist. Belongs with the waiver design. |
| Per-day penalties | **NOT SAFE NOW** — breaks the existing idempotency guard (`Fees.php:4562`), and needs a cap + scheduler. |
| Fee scheduler / reminders | **INTENTIONALLY SEQUENCED** — requires due dates first. Will reuse the `FeeWorker` pattern. |
| Installments | **INDEPENDENT + greenfield.** Also constrained by Gujarat Rule 7(7). Own phase. |
| Full-section override, promotion-roll policy, duplicate-roll survey | **BUSINESS DECISIONS** (D3/D4/D5) — not engineering calls. |
| Atomic seat reservation | **DEFERRED BY DESIGN** — should be one shared primitive for capacity+roll+promotion+transfer, not bolted onto one path. |
| Exam Roll Number | **INSUFFICIENT EVIDENCE** for ZenX need; flagged only. |
| Import capacity preview | Safe and cheap, but **not part of this correctness chain** — deliberately not bundled. |
| AI placement / auto-roll / global roll uniqueness | **DO NOT BUILD** — unevidenced; AI placement additionally carries discrimination risk on gender/academic attributes. |

# GIT STATUS

```
Branch:   integration/phase2
HEAD:     758c924
Modified: unchanged from session start (Admin ~442 dirty, Teacher 31, Parent 23)
Untracked: +1 — this report only
Rules/Indexes: UNTOUCHED (firestore.rules 2026-08-13, firestore.indexes.json 2026-08-12)
Commit status: NOT COMMITTED     Push status: NOT PUSHED     Deploy: NOT DEPLOYED
```

---

# IMPLEMENTATION BOUNDARY — the complete dependency chain

```
┌── RELEASE 1 · "Fee Correctness Chain" — INDIVISIBLE ──────────────────┐
│  1. AB   stamp dueDate     (5 paths; createModuleFee EXCLUDED-frozen) │
│  2. F-1  fine-aware proration    ← without it, AB destroys money      │
│  3. G-3  computed fine → collection → receipt, override authorized    │
│           + audited          ← without it, fines are uncollectable    │
│  4. L-1  promotion cache bust + correct the false comment             │
│  5. H-1  disable the misleading auto_remind control                   │
└───────────────────────────────────────────────────────────────────────┘
        ↓   K-1 removed — verified already correct
R2  Late-fee policy: grace + cap + basis (per-tenant; state-specific law)
R3  Automation: scheduler (reuse FeeWorker pattern) → payment re-check/scrub
    → dedupe → emit_push('FEE_DUE') → history
R4  Section closure: import preview · override+audit design
R5  Roll: bulk assign screen · duplicate survey
R6  Blocked/future: installments · ID-card (post-v2) · atomic seats · per-day
```

**Why 1–5 are one release:** 1 without 2 destroys money; 1+2 without 3 computes fines nobody can
collect; 5 without the rest leaves a control that lies. 4 is independent in code but shares the
release because it degrades the roll/capacity guarantees this chain depends on.

**Files:** `libraries/Fee_lifecycle.php` · `services/FeeCollectionService.php` ·
`controllers/Fees.php` · `controllers/Sis.php` · `libraries/Firestore_service.php` (comment) ·
`views/fee_management/reminders.php` · `tests/Unit/*`.
**Data model:** populate the existing `dueDate` — no new field, no new collection.
**Rules/indexes:** none. **Migration:** none. **Backfill:** required but SEPARATE (**D7**).
**Rollback:** clean — additive fields + formula changes, no destructive write.

---

# CAN A REAL SCHOOL SAFELY DEPEND ON THIS TODAY?

**No — and precisely three things are why.**

**One.** Fees created at admission carry no due date, so nothing can ever tell a payment is late. Late
fees are effectively switched off across the product.

**Two.** If we fixed only that, a second routine would start quietly deleting fines from family
balances — proration recalculates what's owed using a formula that forgets penalties. That's real
money vanishing with no record.

**Three, and this is the one I'd escalate.** The penalty your system calculates never reaches the
payment screen. Your cashier types the fine by hand. Nothing checks what they type against what was
computed — no comparison, no ceiling, no record that the two differed. A cashier can waive any penalty
by typing zero. Yet giving a *discount* — financially the same act — requires a manager-level role.
**Your lowest-privileged fee role can do, unlogged, what your discount policy deliberately restricts.**

**What I got wrong, and you were right to make me check.** I previously told you fine income was
probably being lumped into general fee revenue. **It isn't.** Late fees are credited to their own
ledger, `4060 Late Fee`, with proper double-entry. That part was built correctly and needs nothing.
I also called a 15-day grace period a compliance requirement. It isn't — that was higher-education
guidance, not K-12 law. School fee regulation in India sits with **state** governments and genuinely
differs between Delhi, Maharashtra and Gujarat. The practical conclusion is unchanged but the reason
is stronger: because the rule is state-specific and ZenX is multi-tenant, **penalty policy must be
per-school configuration and must never be hardcoded.**

**What's genuinely solid:** student identity across every module, receipt presentation, atomic receipt
numbering, duplicate-payment protection, fee accounting, and the class/section capacity work. The
late-fee calculation is already safe to run twice.

**The good news on effort.** Almost none of this is new construction. The due-date setting, its
reader, the field on the record, and the background-job pattern a future scheduler needs — all already
exist and are already working. The fixes are wiring, not architecture.

**No code has been written.** The chain is five items that must ship together; accounting has dropped
out of it. **Awaiting your authorization to implement Release 1.**

---

**Sources:** [Rajya Sabha / state jurisdiction](https://www.freepressjournal.in/education/who-regulates-private-school-fees-centre-clarifies-in-rajya-sabha-that-states-and-uts-have-jurisdiction) ·
[Delhi Fees Act 2025](https://foxandmandal.co.in/the-delhi-school-education-(transparency-in-fixation-and-regulation-of-fees)-act,-2025) ·
[NCPCR fee-regulation framework](http://opaf.in/img/documents/FEE_Regulation_Framework_NCPCR.pdf) ·
[EROCON fee norms](https://erocon.in/fee-regulation-norms-for-the-fixation-of-the-fee-for-schools/) ·
[FeeMonk — missed deadlines](https://www.feemonk.com/blog/what-happens-if-students-miss-fee-deadlines-in-india) ·
[Campus24x7](https://campus24x7.in/blogs/school-fee-structure-erp-setup-guide-india) ·
[Knwdle late-fee calculator](https://knwdle.com/tools/late-fee-calculator) ·
[schoolerp.co.in](https://schoolerp.co.in/fees-and-fine-management.html) ·
[n8n reminder workflow](https://n8n.io/workflows/7039-send-school-fee-reminders-with-payment-links-via-email-and-whatsapp/) ·
[SchoolDeck](https://databus.co/schooldeck/features/auto-due-reminders/) ·
[IVR Solutions](https://www.ivrsolutions.in/solutions/education/fee-reminder) ·
[VirtueSkool roll numbers](https://virtueskool.com/HelpAndManual/HTML/assign-roll-numbers.html) ·
[SFT Roll Call](https://shyamfuture.com/sft-rollcall/) ·
[Berkeley Cal 1 Card](https://bsa.berkeley.edu/news/notification-fee-replace-lost-cal-1-card) ·
[Illinois State Redbird](https://redbirdcard.illinoisstate.edu/card-security/) ·
[CampusIDNews](https://www.campusidnews.com/lost-id-card-it-will-cost-you/) ·
[Columbia](https://ssc.columbia.edu/replace-id-card) · [UCSC](https://idcards.ucsc.edu/general-information/replacement-cards/)
