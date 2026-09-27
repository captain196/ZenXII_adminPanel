# Phase 15.3 — Student Lifecycle + Fees Professional Hardening
## Deep Research → Cross-Module Audit → Pre-Implementation Analysis

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**NO CODE WRITTEN. Nothing committed, pushed, merged or deployed. No production data read or written.
No Firestore rules or indexes touched. UAT NOT EXECUTED.**

**Evidence labels:** **SOURCE-VERIFIED** (external doc) · **ZENX-VERIFIED** (this repo's source)
· **INDUSTRY PRACTICE** (multiple independent sources) · **INFERENCE** · **ZENX RECOMMENDATION**
· **NOT ESTABLISHED BY SOURCES**

---

## 0. THE HEADLINE — a third coupled gate, previously unknown

Phase 15.2 established two coupled gates. **This phase found a third, and it changes the sequencing.**

**G-3 · The computed fine never reaches the receipt. 🔴 HIGH · ZENX-VERIFIED**

The late-fee engine writes `fine_amount` onto the demand doc (`Fees.php:4569`). But the collection
path reads the fine from **operator keyboard input**:

```php
// services/FeeCollectionService.php:128-130
$fineAmount = isset($data['fine'])
    ? …
    : floatval(str_replace(',', '', $controller->input->post('fineAmount') ?? '0'));
```

`fine_amount` appears in the views **only** in `views/fees/student_ledger.php:422/543` — as a
**read-only display**. **No collection form prefills it.** ZENX-VERIFIED by grep across `views/`.

**So there are two disconnected fine mechanisms in ZenX:** one the system computes, and one the
cashier types. They never meet. Even after due dates land and fines compute correctly, **the fine
will not appear on the receipt unless a human retypes it** — and nothing reconciles the two.

> **The full chain is therefore four gates, not two:**
> **(1)** stamp `dueDate` → **(2)** make `proRateFees` fine-aware → **(3)** bridge computed fine into
> collection → **(4)** schedule it. Shipping 1 alone erases money (Phase 15.2 §2.2). Shipping 1+2
> computes fines nobody can collect. **All four are one release.**

---

# PART B — L-1 PROMOTION CACHE DEFECT (re-verified)

**CONFIRMED. 🟡 MEDIUM.** Re-traced independently this phase; Phase 15.2's finding holds exactly.

```
Sis::execute_promotion()
 └→ Student_write_service::batchMoveStudents()   Student_write_service.php:406
     └→ Entity_firestore_sync::syncStudent()     Entity_firestore_sync.php:260
         └→ _write('students', …)                Entity_firestore_sync.php:409
             └→ firebase->firestoreSet(…)        Entity_firestore_sync.php:1040 ← RAW, bypasses service
```

`_bustDashboard()` is reachable **only** from `Firestore_service::set/update/remove/batch`
(lines 337/356/416/716). **Confirmed ~90 s staleness** — TTL is set at `MY_Controller.php:1325`
(`$this->cache->save($key, $docs, 90)`).

### Impact, tested per the five surfaces you named

| # | Surface | Impact | Severity |
|---|---|---|---|
| 1 | **Roll collision detection** | `_rollNoConflict()` reads the stale snapshot. Promoted students invisible in the destination class → **a taken roll reads as free**. Opens exactly when admins bulk-assign new-session rolls. | 🔴 **worst** |
| 2 | **Capacity validation** | `_sectionCapacityExceeded()` under-counts destination, over-counts source → section can exceed `maxStrength`. | 🟡 MED |
| 3 | **Student list** | Shows old class/section ≤90 s. Cosmetic, self-healing. | 🟢 LOW |
| 4 | **Fee operations reading the roster** | Fee **search** uses the same `students_raw` key (`Fees.php:2895`) → stale class/section in results. **Financially harmless** — fee demands key off `studentId`, never the cached class. | 🟢 LOW |
| 5 | **Other consumers** | SIS dashboard, ID-card page, enrolled-ID lookups — same snapshot, same cosmetic staleness. | 🟢 LOW |

### Scope — every lifecycle flow checked, not assumed

| Flow | Busts? | Mechanism |
|---|---|---|
| **Promotion** | **✗ NO** | `syncStudent` → raw `firestoreSet` only |
| Admission | ✓ | `fs->updateEntity('students', …)` ×3 — `Sis.php:956/988/1001` |
| Bulk import | ✓ | `fs->update('students', …)` SRNO/APAAR |
| Deletion | ✓ | `fs->removeEntity('students', …)` |
| Transfer / status change | ✓ | via `fs->updateEntity` |

### Narrowest safe fix — ZENX RECOMMENDATION

**Do NOT modify `Entity_firestore_sync::_write()`.** It is the write path for **every** entity sync in
the ERP (staff, sections, routes, vehicles…). Rerouting it through `Firestore_service` would change
the behaviour of dozens of unaudited call sites to fix one — an unacceptable blast radius.

**Fix the caller:** after `batchMoveStudents()` returns in `Sis::execute_promotion()`, delete
`listCacheKey('students')` and `listCacheKey('students_raw')`. Bounded, one function, trivially
reversible.

**Also correct the false comment** at `Firestore_service.php:393`, which states *"Every write that
touches these collections routes through set/update/remove (incl. the \*Entity wrappers) → here."*
**This is factually untrue** and is the most likely reason the defect went unnoticed. Leaving it
would guarantee the next engineer repeats the mistake.

---

# PART C — CLASS / SECTION / CAPACITY (re-verified)

| # | Item | Status |
|---|---|---|
| 1 | Direct admission capacity | ✅ enforced (Ph15) |
| 2 | CRM enrolment capacity | ✅ enforced (Ph15) |
| 3 | Bulk import capacity | ✅ enforced + running-seat projection |
| 4 | Promotion capacity | ✅ pre-existing guard — **but see L-1 staleness** |
| 5 | Section transfer capacity | ✅ pre-existing guard |
| 6 | Re-admission capacity | ✅ inherited via `enroll_student` |
| 7 | Session-scoped | ✅ `sectionDocId()` embeds session; memo key fixed in Ph15 |
| 8 | Active vs withdrawn occupancy | ✅ withdrawn frees the seat |
| 9 | Full-section behaviour | ✅ blocks with concrete numbers |
| 10 | Most-headroom suggestion | ✅ **KEEP** — see below |
| 11 | Import preview capacity | ❌ **GAP** (🟢 LOW) |
| 12 | Full-section override | ❌ **ABSENT** (🟡 MED) — `get_class_sections()`'s comment promises an override that was never built |
| 13 | Concurrency / seat reservation | ⚠ detection-grade, not atomic (🟡 MED) |
| 14 | **Cache invalidation after promotion** | ❌ **BROKEN — L-1** |
| 15 | New admission paths elsewhere | ✅ swept — none found beyond the three |

**Most-headroom suggestion: KEEP.** External research established that ERPs offer *both* auto and
manual section assignment ([SFT Roll Call](https://shyamfuture.com/sft-rollcall/)) but **NOT
ESTABLISHED BY SOURCES** that any product uses a balancing algorithm beyond capacity. **No balancing
algorithm invented, per your instruction.**

---

# PART D — ROLL NUMBER (re-researched — and I was partly wrong)

## ⚠ CORRECTION to my own three prior research passes

My earlier reports concluded *"no ERP auto-assigns roll numbers."* **That was too strong.**

**SOURCE-VERIFIED:** [VirtueSkool](https://virtueskool.com/HelpAndManual/HTML/assign-roll-numbers.html)
ships a dedicated **"Assign Roll Numbers"** screen: select **Class & Section** → Fetch → enter Roll
Number **and a separate Exam Roll Number** per student → Update. There is also a standalone
[RollNumber Assigner](https://schoolerp.taxtribute.com/rollnumberassigner) tool.
**INDUSTRY PRACTICE:** ERPs advertise "auto/manual section and roll number assignment"
([SFT Roll Call](https://shyamfuture.com/sft-rollcall/)).

**What the evidence does and does not support:**

| Claim | Verdict |
|---|---|
| Rolls assigned by a **separate, explicit, class+section-scoped bulk operation** | ✅ **SOURCE-VERIFIED** |
| Manual per-student entry supported | ✅ **SOURCE-VERIFIED** |
| Rolls scoped to **class + section** | ✅ **SOURCE-VERIFIED** (VirtueSkool requires it) |
| Schools maintain a **separate Exam Roll Number** distinct from class roll | ✅ **SOURCE-VERIFIED** — *ZenX has no such concept* |
| **Silent auto-assignment at admission** | ❌ **NOT ESTABLISHED BY SOURCES** |
| Ordering rule (alphabetical vs admission order) | ❌ **NOT ESTABLISHED** — VirtueSkool docs do not state it |
| Alphanumeric rolls (A12, 12C) | ❌ **NOT ESTABLISHED** — no source addresses format |
| House/group conventions, roll ranges, reserved rolls | ❌ **NOT ESTABLISHED BY SOURCES** |
| Carry-forward on promotion / re-assign on transfer | ❌ **NOT ESTABLISHED BY SOURCES** |

**So the honest position:** research supports **explicit bulk assignment as a first-class feature**,
which ZenX lacks. It does **not** support automatic assignment, ordering rules, or any of the
convention questions (house rolls, ranges, reserved numbers) — those remain unevidenced and **must
not be invented**.

## ZenX comparison and recommendation

| Aspect | ZenX today | Verdict |
|---|---|---|
| Scope: School+Session+Class+Section | ✅ ZENX-VERIFIED | **Correct, matches sources. Keep. No global uniqueness.** |
| Manual entry default | ✅ | Matches sources |
| Opt-in atomic "Suggest" | ✅ (gap-filling, `rollNoClaims` create-if-not-exists) | **Exceeds** what sources document |
| Duplicate blocking | ✅ Active-only, session-scoped | Correct |
| Alphanumeric storage | ✅ stored/compared as **string** ZENX-VERIFIED | **Safe** — no numeric cast anywhere in the validator |
| Concurrency | ✅ atomic claim for Suggest; ⚠ detection-grade for manual entry | Acceptable |
| **Bulk "Assign Roll Numbers" screen** | ❌ **MISSING** | **Only evidence-backed gap** — 🟡 MED |
| **Exam Roll Number** | ❌ absent | 🟢 LOW — flag only, do not build unauthorized |
| Promotion carry-forward | carries + collision-checks | **NOT ESTABLISHED** — business decision (D4) |
| Historical duplicates | unknown — survey blocked | needs D5 |

**ZENX RECOMMENDATION:** keep manual + Suggest exactly as built; **do not** add automatic
assignment at admission (unevidenced). **Add the class+section bulk assignment screen** — it is the
one roll-number feature real ERPs demonstrably ship that ZenX does not have. Do not silently renumber
anyone. Do not invent ordering rules.

---

# PART E — DUE DATE FOUNDATION (all generators traced)

**Confirmed: `Fee_lifecycle.php` contains ZERO `dueDate`/`due_date` references** (ZENX-VERIFIED, grep
count 0). Every supporting piece already exists and is live:

| Piece | Status | Location |
|---|---|---|
| School sets a due day | ✅ live | `Fee_management.php:2154/2184`, UI `views/fee_management/reminders.php:54` |
| `getDueDay()` reader — clamps **1–28**, default **10** | ✅ live | `Fee_firestore_txn.php:1215` |
| `periodKey` = `YYYY-MM` | ✅ | `Fee_lifecycle.php:302` |
| `dueDate` field on demand doc | ✅ | `Fee_firestore_txn.php:907` |
| camel→snake normalisation | ✅ correct | `Fee_firestore_txn.php:691` |
| Late-fee engine reads it | ✅ | `Fees.php:4551` vs `date('Y-m-d')` |

**Critically, `due_day_of_month` is NOT a dead setting** — unlike `auto_remind`, it has a real reader.

**Derivation is safe:** `sprintf('%s-%02d', $periodKey, getDueDay())` → `"2026-04-10"`. The **1–28
clamp removes every month-length edge case** (no 30 Feb, no leap-year branch), and `YYYY-MM-DD`
compares correctly under the engine's lexicographic `>=`.

### All demand generators — where `dueDate` must be stamped

| # | Path | Method | Stamps today? |
|---|---|---|---|
| 1 | Admission spec build | `buildAdmissionDemandSpecs()` :220 | ❌ |
| 2 | Initial assignment | `assignInitialFees()` :472 | ❌ |
| 3 | Promotion reassign | `reassignFeesOnPromotion()` :533 | ❌ |
| 4 | Preview | `previewDemandSpecs()` :397 | ❌ (must match #1 or preview lies) |
| 5 | Mid-session proration | `proRateFees()` :662 | ❌ (period unchanged → date should persist) |
| 6 | Ad-hoc / module fee | `createModuleFee()` :897 | ❌ |
| 7 | Session rollover | `cancelSessionDemands()` :835 | n/a (cancels) |
| 8 | Re-admission | via `enroll_student` → #1/#2 | ❌ inherited |
| 9 | **Bulk generator** | `Fees.php:5028` | ✅ **the only one that does** |

**Fixing one path is not a fix.** Paths 1–3, 6 and 8 all produce dateless demands today.

⚠ **`createModuleFee()` (path 6) is inside the FROZEN Fees Exemption v2 surface** — `config/fees_exemption_v2_flags.php`
explicitly notes Phase 3 "converges service generation off `createModuleFee`". Touching it collides
with parked, uncommitted work. **Recommend excluding path 6 from the first release** and handling it
inside the entitlements cutover.

---

# PART F — PRORATION / LATE-FEE COUPLING (confirmed, with test matrix)

**CONFIRMED — financial data loss. 🔴 HIGH · ZENX-VERIFIED.**

```php
// Fee_lifecycle::proRateFees() — line 692-694, 715
$newNet  = round($grossAmt * $factor, 2);
$paidAmt = (float) ($d['paidAmount'] ?? 0);
$newBal  = round($newNet - $paidAmt, 2);          // ← fine_amount absent
$this->fsTxn->updateDemand($did, ['netAmount' => $newNet, 'balance' => $newBal, …]);
```
vs the engine (`Fees.php:4566`): `$newBalance = $netAmount + $fine - $paidAmount`.

**Proration silently erases any applied fine.** Inert today (no dueDate → no fine). **Setting dueDate
makes it live.**

### Safe formula — ZENX RECOMMENDATION

```
newBalance = max(0, newNet + fine_amount − paidAmount)      // fine survives proration
```
Fines are **not prorated** — a penalty for lateness is not a service charge and does not scale with
service months. **INFERENCE, explicitly labelled**; no source addresses fine-under-proration.

### Test matrix (to be written before implementation)

Base: Fee ₹10,000 · Fine ₹200 · Paid ₹5,000 → **expected balance ₹5,200** ✔ your scenario.

| # | Scenario | Expected |
|---|---|---|
| 1 | Fine, then proration ×0.5 | net 5,000 + 200 − 5,000 = **200** (fine survives) |
| 2 | Proration ×0.5, then fine | net 5,000 + 200 − 5,000 = **200** (order-independent) |
| 3 | Partial payment then fine | fine added on top, balance grows |
| 4 | Full payment then proration | balance floors at 0; **over-collection logged** (`FEE_PRORATE_OVERCOLLECTION`) |
| 5 | Multiple proration events | idempotent — fine applied once, never multiplied |
| 6 | Re-run late-fee engine | **no-op** — `abs($existingFine-$fine)<0.01` guard, `Fees.php:4562` |
| 7 | Withdrawal (freeze) | fine preserved, demand frozen |
| 8 | Promotion | new-session demands unaffected by prior-session fines |
| 9 | Re-admission | prior fines stay on the prior admission's demands |
| 10 | Session rollover (cancel) | cancelled demands carry no live fine |

**Order-independence (1 vs 2) is the core invariant** and is exactly what today's code violates.

---

# PART G — LATE FEE / PENALTY RESEARCH

## Comparison matrix

| Source | Model | Evidence | Advantages | Risks | ZenX fit |
|---|---|---|---|---|---|
| [Campus24x7](https://campus24x7.in/blogs/school-fee-structure-erp-setup-guide-india) | Configurable: **flat vs per-day**, cap, grace, and whether charged on **total pending** vs **current month** | SOURCE-VERIFIED — states these as the explicit config decisions | Matches how schools actually think | Config surface grows | **Best fit** |
| [Knwdle calculator](https://knwdle.com/tools/late-fee-calculator) | Three models: flat after grace; per-day × chargeable days; monthly % = fee × (rate/100) × (days/30) | SOURCE-VERIFIED | Formulae explicit and auditable | % model needs day-count care | Strong |
| [schoolerp.co.in](https://schoolerp.co.in/fees-and-fine-management.html) | Fine rules + exemptions + escalation; fines auto-added; transaction record kept | SOURCE-VERIFIED | Confirms **audit trail is standard** | Vendor marketing — capability claims unverified | Directional |
| [FeeMonk](https://www.feemonk.com/blog/what-happens-if-students-miss-fee-deadlines-in-india) / [LawRato](https://lawrato.com/civil-legal-advice/is-there-any-law-which-regulates-college-fines-for-delayed-fees-245502) | **Grace ≥15 days** expected; fines must be "reasonable and proportionate" | SOURCE-VERIFIED (legal-advisory, higher-ed framing) | Establishes grace + cap as **compliance**, not preference | Guidance, not statute; higher-ed context | **Grace + cap are non-optional** |

**INDUSTRY PRACTICE (multi-source): grace period, per-day accrual, and a maximum cap are all
standard.** ZenX today has **none of the three** — it is flat, immediate, uncapped
(`Fees.php:4556-4560`).

**NOT ESTABLISHED BY SOURCES:** penalty on outstanding-vs-original after partial payment; penalty
reversal mechanics; how penalties present on receipts; their accounting classification. **Not invented.**

## ZENX RECOMMENDATION — configurable, grace-and-cap-first

Answering your **D2** (flat vs per-day) with evidence rather than preference: **build the
configurable model, defaulting to flat.**

```
late_fee_enabled        bool     (exists)
late_fee_type           fixed | percentage | per_day     (fixed|percentage exist; per_day is new)
late_fee_value          number   (exists)
late_fee_grace_days     int   NEW  — default 15   (compliance-driven)
late_fee_max_cap        number NEW  — default 0 = uncapped
late_fee_basis          current_demand | total_outstanding   NEW — default current_demand
```

Against your ten required properties:

| Requirement | How met |
|---|---|
| Explainable | fine = f(value, type, days_late, cap) — reproducible from stored fields |
| Auditable | persist `fine_computed_at` (exists), plus basis/grace/cap **as applied** |
| **Idempotent** | ✅ already — absolute-value write + equality guard, `Fees.php:4562` |
| Financially safe | requires the **Part F** fix; without it, unsafe |
| School-configurable | via `feeSettings`, per school+session |
| Safe with partial payments | fine on `net_amount`; `balance` recomputed including fine |
| Safe with proration | **only after Part F** |
| Reversal / waiver | **NOT ESTABLISHED BY SOURCES** — needs its own design; do not improvise |
| Accounting-compatible | see Part K — **currently is not** |
| Receipt / Parent App | **blocked by G-3** — see §0 |

**Per-day accrual is deliberately NOT recommended for the first release.** It breaks the current
idempotency guard (the fine legitimately changes every day, so `abs(existing−new)<0.01` no longer
short-circuits), and it demands a cap and a scheduler to be safe. **Ship flat + grace + cap first;
add per-day once the scheduler exists.**

---

# PART H — AUTOMATIC FEE REMINDERS

## What actually exists — ZENX-VERIFIED

| Component | Reality |
|---|---|
| `auto_remind` setting | **DEAD.** Written by `Fee_management.php:2181`, rendered `reminders.php:44/45/315`. **Zero server-side consumers.** |
| `days_before_due` `[7,3,1]` | **DEAD** — stored, never read |
| `due_day_of_month` | **LIVE** — `getDueDay()` reads it |
| `FEE_DUE` push mark | **REGISTERED, NEVER EMITTED.** `functions/index.js:123`, complete with `dataKeys:['amount','dueDate']`. Zero `emit_push('FEE_DUE')` call sites. |
| SMS / Email / WhatsApp | queue-only, **no dispatcher in-repo** |
| Scheduler | **NONE** — crons exist for Transport, Backup, Campus Access, B2; **none for Fees** |
| History logging | not established this pass |

**A school can switch automatic reminders ON, watch it persist, and nothing will ever happen.**

## Industry practice — SOURCE-VERIFIED

- Reminders **3 days before** due date, on a **daily scheduled trigger (~8 AM)** — [n8n template](https://n8n.io/workflows/7039-send-school-fee-reminders-with-payment-links-via-email-and-whatsapp/)
- Multi-channel SMS + WhatsApp + IVR — [IVR Solutions](https://www.ivrsolutions.in/solutions/education/fee-reminder)
- **Escalating** reminders past a configurable threshold; auto-categorised "Forgot to Pay" vs "Critical Defaulters" — [SchoolDeck](https://databus.co/schooldeck/features/auto-due-reminders/)
- **If a parent pays by cash they are automatically scrubbed from the reminder queue** — SchoolDeck. *This is a hard correctness requirement, not a nicety: dunning a family that already paid is the single most damaging failure mode of a reminder system.*

ZenX's dead `days_before_due = [7,3,1]` is **consistent with** the evidenced 3-day pattern — the
intended design was right; only the executor is missing.

## ZENX RECOMMENDATION — Option A, but staged

Answering **D1**: **Option A (make it functional)** — but the setting must be **disabled in the UI
until the dispatcher exists.** Leaving a live-looking control with no executor is the actual defect;
a disabled control with "coming in a future release" is honest.

Required, in order: due dates (Part E) → scheduler → **payment-state re-check immediately before
send** (the scrub rule) → dedupe key per `(studentId, demandId, reminderStage)` → `emit_push('FEE_DUE')`
→ history log. **Duplicate prevention must use the existing `emit_push` dedupeKey mechanism** — per
CLAUDE.md, `emit_push` is the only sanctioned dispatcher and a second sender must never be added.

**NOT ESTABLISHED BY SOURCES:** parent-level reminder preferences / opt-out. Flagged, not designed.

---

# PART I — ID CARD REPLACEMENT CHARGES

## ⚠ This is a SECURITY operation in ZenX, not a print job

**ZENX-VERIFIED, and it changes the design:** ZenX ID cards carry **signed tokens used for campus
access scanning**. `Attendance.php:4259-4263`:

> *"we can flip acceptance off once every active card is reissued"* …
> *"legacy unsigned token used for {schoolId}/{studentId} (reissue this student's ID card to mint a signed token)"*

**A lost ID card is a live credential in circulation.** Any replacement design that does not
invalidate the old token leaves a working access card in unknown hands. This outranks the fee.

**Current state:** `Sis::id_card()` (line 3275) generates cards. **No replacement, reissue, versioning
or invalidation concept exists anywhere** — ZENX-VERIFIED.

## Research — SOURCE-VERIFIED

| Practice | Evidence |
|---|---|
| Replacement fee is standard | $15–$30 across institutions ([Columbia $20](https://ssc.columbia.edu/replace-id-card), [UCSC $30](https://idcards.ucsc.edu/general-information/replacement-cards/), [RePublic $15](https://republiccharterschools.org/student-id-replacement-form/), [Berkeley $25](https://bsa.berkeley.edu/news/notification-fee-replace-lost-cal-1-card)) |
| **Damaged ≠ lost** — damaged replaced **free on surrender of the old card** | [Berkeley](https://bsa.berkeley.edu/news/notification-fee-replace-lost-cal-1-card) |
| **Theft + police report → waived** | [CampusIDNews](https://www.campusidnews.com/lost-id-card-it-will-cost-you/) |
| **Age-based free replacement** (card ≥4 years old) | [Illinois State](https://redbirdcard.illinoisstate.edu/card-security/) |
| Charged to the **student billing account**, keyed by student ID | [USD](https://usdkb.sandiego.edu/s/article/Student-ID-Card-Replacement-and-Fees) |

⚠ **All sources are US higher-education.** **NOT ESTABLISHED BY SOURCES** for Indian K-12 schools, and
**not established** are: multiple-replacement escalation, admin authorization workflow,
payment-before-print enforcement, replacement version numbering. **Not invented.**

The **reason codes are the load-bearing finding**, and they are consistent across independent
institutions: **lost → charge · damaged → free on surrender · stolen w/ report → waive · aged → free.**

## ZENX RECOMMENDATION — mechanism

Answering "which model": **an ad-hoc student service charge via the existing `createModuleFee()`**
(`Fee_lifecycle.php:897`) — already the ad-hoc mechanism for Hostel (`Hostel.php:360`) and Transport.
**Not** a fee head (fee heads are recurring, class-wide; this is per-incident and per-student).

⚠ **BLOCKER:** `createModuleFee()` sits inside the **FROZEN Fees Exemption v2 surface**
(`fees_exemption_v2_flags.php` states Phase 3 converges service generation off it). **Building on it
now collides with parked uncommitted work.** → **ID-card charges must wait for the entitlements
cutover. Recommend FUTURE, not next.**

Required end-to-end chain (design only, not authorized):
```
Request (reason code) → authorization → charge → payment → receipt → accounting
  → OLD TOKEN INVALIDATED ← security-critical, must not be skipped
  → new card minted (version++) → audit trail
```
Free regeneration must be prevented by **reason code + authorization**, not by the fee alone.

---

# PART J — FEE RECEIPT IDENTITY

**ZENX-VERIFIED:** there is effectively **one** human-facing receipt renderer,
`Fees::print_receipt()` (`Fees.php:3995`) → `views/fees/receipt.php`, fed by two write paths in
`FeeCollectionService` (`$batchReceiptData` ~793, `$receiptDoc` ~1216) — both carry `srNo`/`rollNo`
since Phase 13.

| Field | On receipt? |
|---|---|
| Student Name | ✅ |
| **SR No.** | ✅ (Phase 13) |
| Father / Guardian | ✅ |
| Class / Section | ✅ |
| **Roll No.** | ✅ (Phase 13) |
| Fee details / Payment details / Receipt number | ✅ |
| **Student ID** | ✅ correctly **NOT printed** — stored internally only |

**Receipt-type coverage:** normal ✅ · ad-hoc/module fee ✅ (same renderer) · **late-fee ⚠ — the fine
prints only what the cashier typed (G-3)** · ID-card charge ❌ (doesn't exist) · other service
charges ✅ via `createModuleFee`.

**Verdict: identity presentation is correct and consistent. The one receipt defect is G-3.**

---

# PART K — FEES + ACCOUNTING

| Link | Status |
|---|---|
| Demand → collection → receipt | ✅ |
| Receipt numbering | ✅ **atomic** — `feeCounters/{schoolId}_{kind}_claim_{V+1}` create-if-not-exists |
| Duplicate payment | ✅ `feeIdempotency` + `Accounting_idempotency` |
| Refund / reversal | ✅ `feeRefunds` / `feeRefundVouchers` / `feeRefundAudit` exist (not deeply traced) |
| Fine → receipt total | ⚠ `netTotal = schoolFees − discountFees + fineAmount` (`FeeCollectionService.php:684/1211`) — **but `fineAmount` is the typed value (G-3)** |
| **Fine → ledger classification** | ⚠ **NOT ESTABLISHED** — fine is stored on the receipt as `fine` and flows inside `netTotal`; whether it posts to a **separate income head** is unverified. **INFERENCE: it does not.** Penalty income is normally classified separately. **Needs its own trace before any late-fee release.** |
| Waiver | ❌ no mechanism |
| ID-card charge | ❌ doesn't exist |
| RTDB | ✅ none introduced; `Operations_accounting` Firestore-only |

---

# PART L — LATENCY / PERFORMANCE

| Operation | Reads before | Reads after | Notes |
|---|---|---|---|
| Due-date stamping | n/a | **+1 per generation run** | `getDueDay()` **must be memoised**, not called per demand — else 12-month generation = **12 extra reads/student** (N+1) |
| Fine-aware proration | n | **n** | pure formula change, **zero** new I/O |
| L-1 cache fix | n | **n** | 2 cache **deletes**, no Firestore I/O; *net negative* latency (prevents a stale-read decision) |
| Late-fee per student | 1 settings + demands | same | settings read **must** be hoisted for any bulk run |
| **Future scheduler** | — | ⚠ | a `where dueDate <= today` sweep **needs a composite index** — *not required by this release* |
| Reminders | — | ⚠ | must **re-read payment state per student before send** (scrub rule) — inherent N+1 unless batched by `IN` chunks |
| Capacity / roll checks | 0–2, memoised | same | ✅ already optimal |
| Receipt generation | atomic counter | same | ✅ |
| Bulk import 500 rows | +4 total | same | ✅ no N+1 |

**Standing rule honoured:** no proposed change introduces a per-student read. The two places where
N+1 could creep in — `getDueDay()` in the generation loop, and the reminder scrub — are called out
with their mitigations **before** any code exists.

---

# PART M — CROSS-PLATFORM

| Question | Answer |
|---|---|
| New field needed? | `dueDate` on demand docs — **already in the schema** (`Fee_firestore_txn.php:907`), simply never populated |
| Already on the Firestore doc? | ✅ field exists |
| Apps already download it? | ✅ — demands are fetched whole; **zero additional reads** |
| Display updates? | Parent App **could** show a due date it currently can't; not required for this release |
| Serialization? | Field appearing where `null` was tolerated — **non-breaking** for Kotlin `String?` |
| Sibling switching / PTM | unaffected — keyed on `studentId` |
| Receipt / PDF | **G-3 blocks correct fine rendering** |
| Teacher App | **no fee surface — zero impact** |

`FEE_DUE`'s declared `dataKeys: ['amount','dueDate']` is **only satisfiable after Part E lands** —
the push contract was written for a field nothing populates.

---

# PART N — LIFECYCLE REGRESSION CHECK

| Transition | StudentID | SRNO | AdmNo | Roll | Class/Section | Session | Status | Fees |
|---|---|---|---|---|---|---|---|---|
| Admission | new | allocated | generated | manual/suggest | set, capacity ✅ | set | Active | demands **(no dueDate ❌)** |
| Import | new | allocated | generated | from file | capacity ✅ | set | Active | **no dueDate ❌** |
| CRM | new | allocated | generated | not set | capacity ✅ | set | Active | **no dueDate ❌** |
| **Promotion** | unchanged | unchanged | frozen | carried | changes | changes | Active | reassigned **no dueDate ❌** · **L-1 stale ❌** |
| Transfer | unchanged | unchanged | unchanged | carried+checked | section changes | unchanged | Active | unaffected |
| Withdrawal | unchanged | preserved | preserved | preserved | preserved | preserved | Withdrawn | frozen ✅ |
| Re-admission | **new** | **preserved (relink)** | **new** | new validation | new placement | new | Active | **no dueDate ❌** |
| Rollover | unchanged | unchanged | unchanged | per-session | per-session | new | Active | cancel ✅ |

**Identity: no inconsistency found.** `studentId` is the sole FK in every module; SRNO is never
persisted as a key; no derivation between them anywhere. **ZENX-VERIFIED.**
**Fees: every generator except the bulk one produces dateless demands.**

Downstream modules (Attendance, Marks, Results, Homework, PTM, Transport, Documents, Certificates,
Parent/Teacher) — **no identity inconsistency**; Certificates/LMS remain RTDB-blocked for SRNO display.

---

# PART O — FINAL DECISION MATRIX

| ID | Finding | Sev | Evidence | Business impact | Technical | Latency | Security | Cross-module | Action | Confidence | Verdict |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **G-3** | Computed fine never reaches receipt | 🔴 HIGH | ZENX-VERIFIED | Fines uncollectable | 2 disconnected mechanisms | none | — | Receipt, Accounting, Parent | Bridge computed→collection | **High** | **MUST FIX NOW** |
| **AB** | No `dueDate` in 5 generators | 🔴 HIGH | ZENX-VERIFIED | Late fees unreachable | Stamp from `getDueDay()` | +1/run if memoised | — | Parent (`FEE_DUE`) | Stamp all paths | **High** | **MUST FIX NOW** |
| **F-1** | Proration erases fines | 🔴 HIGH | ZENX-VERIFIED | **Money vanishes** | Add fine to balance | none | — | Accounting | Fine-aware formula | **High** | **MUST FIX NOW (with AB)** |
| **H-1** | `auto_remind` dead UI | 🔴 HIGH | ZENX-VERIFIED | **Misleads schools** | No consumer | — | — | Parent | Disable now, build later | **High** | **MUST FIX NOW** (disable) |
| **L-1** | Promotion cache stale 90 s | 🟡 MED | ZENX-VERIFIED | Roll collisions missed | Caller-level bust | **negative** | — | SIS, Fees search | 1-line + fix comment | **High** | **MUST FIX NOW** |
| K-1 | Fine ledger classification | 🟡 MED | INFERENCE | Penalty income unclassified | unverified | — | — | Accounting | **Trace before late-fee release** | **Low** | **SHOULD FIX** |
| G-1 | No grace / cap | 🟡 MED | SOURCE-VERIFIED | Compliance ("reasonable, ≥15d") | 3 settings | none | — | Fees | Add w/ late-fee release | **High** | **SHOULD FIX** |
| F-2 | `FEE_DUE` never emitted | 🟡 MED | ZENX-VERIFIED | No parent alerts | Add emit | none | — | Parent | After scheduler | **High** | **SHOULD FIX** |
| F-3 | No fee scheduler | 🟡 MED | ZENX-VERIFIED | Fines unusable at scale | New cron | index later | — | Fees | Phase 15.4 | **High** | **SHOULD FIX** |
| D-1 | No bulk roll-assign screen | 🟡 MED | **SOURCE-VERIFIED** | Manual per-student toil | New screen | 0 extra | — | SIS | Build | **Med** | **SHOULD FIX** |
| C-11 | Import capacity preview | 🟢 LOW | ZENX-VERIFIED | Post-hoc rejections | Reuse validator | 0 extra | — | SIS | Build | **High** | SHOULD FIX |
| C-12/D | Override + audit trail | 🟡 MED | ZENX-VERIFIED | No sanctioned exception | Needs policy | low | audit | SIS | **Design first** | **High** | SHOULD FIX |
| C-13 | Atomic seat reservation | 🟡 MED | ZENX-VERIFIED | Rare 1-over | Shared primitive | med | — | SIS/Fees | Build **once**, shared | **High** | FUTURE |
| I-1 | ID-card replacement | 🟡 MED | SOURCE-VERIFIED (US HE) | Free reprints; **stale credential** | `createModuleFee` | med | **⚠ token invalidation** | Fees, Attendance, Accounting | **Blocked by frozen v2** | **Med** | **FUTURE** |
| J-1 | Receipt identity | ✅ | ZENX-VERIFIED | correct | — | — | — | — | none | High | **NO ACTION** |
| — | Installments | 🟢 | ZENX-VERIFIED absent | greenfield | new model | — | — | Fees | Own phase | High | **FUTURE** |
| — | Mid-session over-collection credit | 🟡 MED | ZENX-VERIFIED | Credit owed unrecorded | Phase C/AC4 | — | — | Accounting | Deferred by design | High | FUTURE |
| — | Exemption / concession | ⛔ | flags FALSE | — | — | — | — | — | **DO NOT TOUCH** | High | **FROZEN** |
| — | Per-day penalty | 🟢 | SOURCE-VERIFIED | optional | breaks idempotency guard | needs cron | — | Fees | After scheduler | High | FUTURE |
| — | Exam Roll Number | 🟢 LOW | SOURCE-VERIFIED | separate exam roll | new field | — | — | Exam | flag only | Med | FUTURE |
| — | AI placement | ⚪ | NOT ESTABLISHED | — | — | — | ⚠ discrimination | — | — | — | **DO NOT BUILD** |
| — | Auto roll at admission | ⚪ | NOT ESTABLISHED | — | — | — | — | — | — | — | **DO NOT BUILD** |
| — | Global roll uniqueness | ⚪ | contradicted | — | — | — | — | — | — | — | **DO NOT BUILD** |

---

# PART P — PRE-IMPLEMENTATION PACKAGE (no code written)

## Release 1 — "Fees Foundation" (the four coupled gates + L-1)

**1. Files** — `libraries/Fee_lifecycle.php` · `controllers/Sis.php` · `libraries/Firestore_service.php`
(comment only) · `services/FeeCollectionService.php` · `views/fee_management/reminders.php` (disable
control) · `tests/Unit/*` (new).

**2. Functions** — `buildAdmissionDemandSpecs`, `assignInitialFees`, `reassignFeesOnPromotion`,
`previewDemandSpecs`, `proRateFees` (formula), `Sis::execute_promotion` (cache bust),
`FeeCollectionService` fine sourcing. **`createModuleFee` deliberately EXCLUDED** (frozen v2).

**3. Data model** — populate existing `dueDate`; **no new field, no new collection.**

**4. Rules / indexes** — **NONE.** No new collection, no new query predicate. *(A future
`where dueDate <=` scheduler sweep would need a composite index — not this release.)*

**5. Migration** — none.

**6. Backfill** — **required but SEPARATE.** Pre-existing demands stay dateless and permanently
fine-exempt. Needs explicit authorization + dry-run script (**D7**). **I will not touch production data.**

**7. Rollback** — clean. Formula/field additions, no destructive write, no migration. Reverting stops
new stamping; stamped demands remain valid.

**8. Test plan** — Part F's 10 scenarios (order-independence is the key invariant) + due-date
derivation + 1–28 clamp + lexicographic ordering vs `date('Y-m-d')` + `getDueDay()` memoisation
(assert one read per run). **Baseline stays 10 pre-existing failures.**

**9. UAT plan** — admit a student → demand shows a due date; back-date → fine appears; **fine appears
on the receipt without retyping (G-3)**; prorate → **fine survives**; promote → roll collision caught
immediately (L-1); reminders control visibly disabled. **UAT NOT EXECUTED.**

**10. Latency budget** — +1 read per generation run (memoised `getDueDay`); 0 for proration;
**negative** for L-1. **No per-student reads added.**

**11. Cross-platform** — no app change required; no new reads; `FEE_DUE` payload becomes satisfiable.

**12. Risks** — (a) shipping AB without F-1 **destroys money**; (b) without G-3 fines are computed but
uncollectable; (c) touching `createModuleFee` collides with frozen v2; (d) `getDueDay()` in a loop
= N+1.

**13. Sequence**
```
R1  Fees Foundation   AB + F-1 + G-3 + L-1 + disable auto_remind      ← ONE release, indivisible
R2  Late-fee policy   grace + cap + K-1 ledger trace
R3  Automation        fee cron → scrub → dedupe → FEE_DUE → history
R4  Section closure   import preview → override+audit design
R5  Roll              bulk assign screen → duplicate survey (D5)
R6  Future            installments · ID-card (post-v2) · atomic seats · per-day
```

---

# DECISIONS REQUIRED

Open: **D1** *(recommend: disable now, build in R3)* · **D2** *(recommend: configurable, default flat
+ grace + cap; per-day later)* · **D3** override policy · **D4** promotion roll · **D5** survey script
· **D6** AI placement · **D7** backfill · **D8** *(now supersedes: **four** gates ship together)*
· **D9** installments → own phase.

**New: D10 — authorize a read-only trace of fine→ledger classification (K-1) before any late-fee release?**

---

# EXECUTIVE SUMMARY — PLAIN LANGUAGE

**What's already correct.** The class-and-section work holds up: no route can overfill a section, and
capacity respects sessions and withdrawn students. Student identity is genuinely sound — every module
keys off Student ID, SR numbers are never used as keys, and nothing derives one from the other.
Receipts show the right things: name, SR number, class, roll — with the internal Student ID correctly
kept off the printed page. Receipt numbers are allocated safely, so two cashiers can't collide. And
the late-fee calculation won't double-charge if it runs twice.

**What's wrong, and it's worse than last phase suggested.** Fees look like a finished module and
aren't wired together. I found a third break this phase. Your system can *calculate* a late fee and
store it — but the payment screen takes the fine from whatever the cashier types. The two never meet.
So even after we fix due dates, a computed fine still won't reach the receipt unless someone retypes
it by hand.

Put together, there are four breaks in one chain: fees are created without a due date; late fees are
therefore never triggered; if we fixed only that, a separate routine would silently wipe fines off
family balances; and even then the fine wouldn't print. **They have to be fixed as one release. Fixing
any one alone makes things worse, not better** — the second one literally makes money disappear.

Separately, the "automatic fee reminders" checkbox still does nothing at all. My recommendation is to
grey it out now and build it properly afterwards, rather than leave staff trusting something that
isn't running.

**Where I was wrong before.** I previously told you three times that no school ERP automatically
assigns roll numbers, and that was too strong. Real products ship a dedicated "Assign Roll Numbers"
screen — pick a class and section, pull up the list, fill in the numbers. That's a feature ZenX
doesn't have, and it's the one roll-number gap the research actually supports. What research still
does *not* support is silent assignment at admission, so I'm not recommending that. One thing I hadn't
seen before: schools keep a *separate exam roll number* alongside the class roll. ZenX has no concept
of it — worth knowing, not worth building unasked.

**Two things I want to flag as genuinely risky.** First, late fees have a legal dimension, not just a
commercial one — the guidance is that schools should allow a grace period of around fifteen days and
keep penalties proportionate. ZenX currently charges immediately, with no grace and no ceiling. That
should change before late fees go live anywhere.

Second, ID cards. I found they carry signed tokens used for campus-access scanning. That means a lost
card isn't just an inconvenience — **it's a working entry credential in someone else's hands.** Any
replacement feature has to cancel the old card, not just print a new one and take a fee. Research also
gave a clear, consistent rule set worth copying: charge for lost, replace damaged cards free when the
old one is handed back, waive with a police report, and replace worn-out old cards free. But this work
is blocked for now — the natural place to build it sits inside the frozen entitlements work, so it
should wait rather than collide with it.

**One honest gap.** I could not confirm whether late-fee income is posted to accounting as a separate
category or just absorbed into the fee total. I believe it isn't separated, but I haven't proven it,
so I've marked it low-confidence rather than assert it. That needs checking before late fees go live —
penalty income is normally classified on its own.

**No code has been written.** The recommended order is in Part P: fix the four coupled fee breaks and
the promotion cache issue together as one release, then late-fee policy, then automation, then the
section and roll number work. **Awaiting your authorization before implementing anything.**

---

**Sources:** [Campus24x7 fee-structure guide](https://campus24x7.in/blogs/school-fee-structure-erp-setup-guide-india) ·
[Knwdle late-fee calculator](https://knwdle.com/tools/late-fee-calculator) ·
[schoolerp.co.in fees & fine](https://schoolerp.co.in/fees-and-fine-management.html) ·
[FeeMonk](https://www.feemonk.com/blog/what-happens-if-students-miss-fee-deadlines-in-india) ·
[LawRato](https://lawrato.com/civil-legal-advice/is-there-any-law-which-regulates-college-fines-for-delayed-fees-245502) ·
[VirtueSkool Assign Roll Numbers](https://virtueskool.com/HelpAndManual/HTML/assign-roll-numbers.html) ·
[SFT Roll Call](https://shyamfuture.com/sft-rollcall/) ·
[RollNumber Assigner](https://schoolerp.taxtribute.com/rollnumberassigner) ·
[n8n fee-reminder workflow](https://n8n.io/workflows/7039-send-school-fee-reminders-with-payment-links-via-email-and-whatsapp/) ·
[IVR Solutions](https://www.ivrsolutions.in/solutions/education/fee-reminder) ·
[SchoolDeck auto due reminders](https://databus.co/schooldeck/features/auto-due-reminders/) ·
[Columbia](https://ssc.columbia.edu/replace-id-card) · [UCSC](https://idcards.ucsc.edu/general-information/replacement-cards/) ·
[Berkeley](https://bsa.berkeley.edu/news/notification-fee-replace-lost-cal-1-card) ·
[Illinois State](https://redbirdcard.illinoisstate.edu/card-security/) ·
[RePublic Schools](https://republiccharterschools.org/student-id-replacement-form/) ·
[CampusIDNews](https://www.campusidnews.com/lost-id-card-it-will-cost-you/) ·
[USD](https://usdkb.sandiego.edu/s/article/Student-ID-Card-Replacement-and-Fees)
