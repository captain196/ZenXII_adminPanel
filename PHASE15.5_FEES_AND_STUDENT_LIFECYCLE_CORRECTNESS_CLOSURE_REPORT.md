# Phase 15.5 — Fees & Student Lifecycle Correctness Closure

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED. NOT PUSHED. NOT DEPLOYED. UAT NOT EXECUTED.**
No production data read or written. No Firestore rules or indexes modified. No RTDB introduced.

**Evidence labels:** ZENX-VERIFIED · SOURCE-VERIFIED · INDUSTRY PRACTICE · INFERENCE ·
ZENX RECOMMENDATION · NOT ESTABLISHED BY SOURCES

---

## 1. EXECUTIVE SUMMARY

Release 1 of the correctness chain is implemented: **five coupled defects fixed in one release**,
because fixing any subset would have left the system worse than before.

| ID | Defect | Status |
|---|---|---|
| **AB** | Fee demands carried no due date → late fees permanently unreachable | ✅ **FIXED** |
| **F-1** | Proration silently erased applied fines → financial data loss | ✅ **FIXED** |
| **G-3** | Computed fine never reached collection; cashier could silently waive it | ✅ **FIXED** |
| **L-1** | Promotion left the student snapshot stale 90 s → roll-collision holes | ✅ **FIXED** |
| **H-1** | "Auto reminders" control persisted but nothing ever sent them | ✅ **FIXED** (honestly disabled) |

**Tests: 830 run, 10 failures — identical to the pre-change baseline of 798/10. Zero new failures;
+32 new tests, all passing.** Every touched file passes `php -l`.

**One genuine authorization boundary reached and respected.** While fixing F-1 I found the *same*
fine-erasing formula inside `createModuleFee()` — which sits on the **frozen Fees Exemption v2
surface**. Per rule §22 I did **not** touch it. It is documented in §20 with exact evidence.

**Scope discipline:** one defect discovered mid-implementation was fixed because it was directly
coupled (the receipt/accounting fine consumption, folded into G-3). Nothing unrelated was expanded.

---

## 2. WHAT WAS RESEARCHED

External (this phase and carried forward, all re-labelled): Indian fee-regulation jurisdiction;
late-fee models; reminder scheduling; roll-number assignment; ID-card replacement.

**Key correction carried in from Phase 15.4 and honoured here:** Indian school fee regulation is
**state jurisdiction** ([Centre's Rajya Sabha clarification](https://www.freepressjournal.in/education/who-regulates-private-school-fees-centre-clarifies-in-rajya-sabha-that-states-and-uts-have-jurisdiction)),
with Delhi, Maharashtra and Gujarat statutes differing. **NOT ESTABLISHED BY SOURCES:** any national
K-12 grace period or penalty cap. **I therefore hard-coded no grace period, no cap, and no penalty
policy in this release** — §7 of the directive. Late-fee policy configuration is deferred to R2 by
design, not by omission.

---

## 3. WHAT WAS VERIFIED (independently, before changing anything)

| Claim | Verdict |
|---|---|
| `Fee_lifecycle` stamps no due date | ✅ ZENX-VERIFIED — grep count of `dueDate` = **0** |
| `getDueDay()` exists, clamps 1–28, defaults 10 | ✅ `Fee_firestore_txn.php:1215` |
| `periodKey` is `YYYY-MM` | ✅ `Fee_lifecycle.php:302` |
| Late-fee engine compares `due_date` to `date('Y-m-d')` | ✅ `Fees.php:4551` |
| `normalizeDemandDoc` maps `dueDate → due_date` | ✅ line 691 — **no field-name bug** |
| `proRateFees` omits fine from balance | ✅ ZENX-VERIFIED, line 694 |
| Fine sourced from cashier POST | ✅ `FeeCollectionService.php:128-130` |
| `fine_amount` only *displayed*, never prefilled | ✅ `student_ledger.php:422/543` only |
| `_write()` bypasses `_bustDashboard` | ✅ `Entity_firestore_sync.php:1040` calls `firebase->firestoreSet` |
| Promotion is the ONLY stale flow | ✅ admission/import/deletion/transfer all bust correctly |
| `auto_remind` has no consumer | ✅ 3 references, all UI |
| **Fine posts to a dedicated ledger 4060** | ✅ `Operations_accounting.php:1403-1414` — **K-1 needs no fix** |
| Baseline = 798 tests / 10 failures | ✅ measured before any edit |

---

## 4. WHAT WAS WRONG

Beyond the five known defects, implementation surfaced one **new** structural fact that made AB far
safer than planned, and one **new** boundary:

**NEW (favourable) — a single convergence point.** ZENX-VERIFIED: `reassignFeesOnPromotion()` →
`assignInitialFees()` → `_routedBuildAdmissionSpecs()` → `buildAdmissionDemandSpecs()`, and
`previewDemandSpecs()` routes there too. So **one insertion point covers admission, initial
assignment, promotion reassignment, preview and re-admission** — satisfying "do not fix only one
path" without five parallel edits, and making **preview and commit agree by construction** rather
than by discipline.

**NEW (boundary) — the same defect on frozen ground.** `createModuleFee()` contains
`$balance = max(0.0, round($net - $paidSoFar, 2))` (`Fee_lifecycle.php:1043`) — structurally
identical to F-1. **Not fixed. See §20.**

---

## 5. WHAT WAS FIXED

### AB — Due date stamping
Added memoised `_dueDay()` and `_dueDateFor()`; stamped `'dueDate'` into the demand spec at the
convergence point. Composition is pure string work (`YYYY-MM` + clamped day), so **February and leap
years need no special case** — day 29–31 can never be produced.

### F-1 — Fine-aware proration
```php
$fineAmt = (float) ($d['fineAmount'] ?? $d['fine_amount'] ?? 0);
$newBal  = round($newNet + $fineAmt - $paidAmt, 2);
```
Both key spellings read, because demand docs are camel→snake normalised depending on caller. Fines
are **not** prorated — a lateness penalty does not scale with months of service (**INFERENCE**,
labelled; NOT ESTABLISHED BY SOURCES).

### G-3 — Computed fine becomes canonical
Placed **where `$demands` is already in memory** → **zero additional Firestore reads**. Rules:
- **Default:** the system-computed fine wins; a silently altered POST value is ignored.
- **Override:** only on explicit request, requiring `has_permission('Fees','manage')` **and** a
  reason — mirroring the existing discount gate. Fails **closed** if RBAC can't be evaluated.
- **Canonical 0:** the posted value is honoured, preserving today's manual/ad-hoc fine workflow.
- **Any divergence is audited** via the existing `Fee_audit_logger` (fail-soft — never fails a payment).

Because both `netTotal` computations sit downstream, the approved fine flows to **receipt total,
receipt `fine`, and the 4060 journal line** from one variable — they cannot diverge.

### L-1 — Promotion cache invalidation
Two cache deletes after `batchMoveStudents()`, wrapped so a cache failure never fails a completed
promotion. **Fixed at the caller deliberately** — rerouting `_write()` would change every entity sync
in the ERP. Also **corrected the false comment** at `Firestore_service.php:387` that claimed the
Entity wrappers route through `_bustDashboard`; it now states the real rule (`$this->fs->…` covered,
`$this->firebase->…` not).

### H-1 — Honest disablement
Control disabled with an explicit "Not available yet" hint pointing to the **real** manual
`Send Reminder` action (verified to exist: `Fee_management.php:2344`). `name` removed so it cannot
post; existing JS still resolves the element and stores `auto_remind=0`, making the persisted setting
match reality.

---

## 6. FILES CHANGED

| File | Change |
|---|---|
| `application/libraries/Fee_lifecycle.php` | memo property, `_dueDay()`, `_dueDateFor()`, `dueDate` stamp, fine-aware balance |
| `application/services/FeeCollectionService.php` | G-3 canonical-fine gate + authorization + audit |
| `application/controllers/Sis.php` | L-1 cache invalidation in `execute_promotion()` |
| `application/libraries/Firestore_service.php` | corrected false coverage comment (comment only) |
| `application/views/fee_management/reminders.php` | H-1 disablement |
| `tests/Unit/Phase155FeesCorrectnessChainTest.php` | **NEW** — 32 tests |

⚠ **Honesty note on diffstat:** `git diff --stat` reports large line counts for `Sis.php` (2021) and
`Fee_lifecycle.php` (315). **Those are NOT my changes** — both files were already dirty from earlier
uncommitted phases (139 modified files at session start). My edits are the blocks described above.
**No unrelated dirty file was touched.**

---

## 7. DATABASE / SCHEMA IMPACT

**None.** `dueDate` already existed on the demand document (`Fee_firestore_txn.php:907`) and was
simply never populated. **No new field, no new collection, no duplicate due-date system, no
migration.** Audit records use the existing `Fee_audit_logger` store.

## 8. FIRESTORE READS / WRITES

| Operation | Before | After |
|---|---|---|
| Demand generation (per student, 3 heads × 12 months) | n | **n + 1** (one memoised `getDueDay`) |
| Proration | n | **n** (pure arithmetic) |
| Fee collection | n | **n** (G-3 reuses the already-loaded `$demands`) |
| Promotion | n | **n** (2 cache deletes, no Firestore I/O) |

**No new Firestore query, no new index, no rules change.**

## 9. PERFORMANCE / N+1 ANALYSIS

The one N+1 risk was `getDueDay()`. Un-memoised it would cost **36 reads per student** (3 heads ×
12 months) — 18,000 reads for a 500-student generation. The memo pins it to **one read per
instance**, and `test_due_day_is_resolved_once_not_per_demand` asserts exactly that. **Net latency of
L-1 is negative** — it removes a stale-read decision path.

## 10. SECURITY / RBAC ANALYSIS

| Action | Gate | Server-side |
|---|---|---|
| Collect fees | COUNTER_ROLES + `Fees:edit` | ✅ |
| Submit discount | MANAGE_ROLES + `Fees:edit` | ✅ |
| Compute fines | MANAGE_ROLES + `Fees:edit` | ✅ |
| **Override computed fine** | **MANAGE_ROLES + reason + audit** | ✅ **NEW** |

**The asymmetry is closed:** waiving a computed penalty now requires the same authority as granting a
discount. Enforcement is **server-side**, not UI-hidden — the check runs before any receipt,
allocation or journal write. **Fails closed** when RBAC is unavailable. No privilege escalation
introduced.

## 11. FEES END-TO-END TRACE

```
admission/promotion/re-admission → buildAdmissionDemandSpecs  → dueDate ✅
  → Fees::compute_fines_for_student (due_date < today)        → fine_amount ✅ idempotent
  → proRateFees                                               → fine PRESERVED ✅
  → FeeCollectionService G-3 gate                             → canonical fine ✅ authorized+audited
  → netTotal = fees − discount + fine                         → receipt total ✅
  → receipt.php (SR No., Roll No., fine; Student ID hidden)   → ✅
  → journal fine_amount → Operations_accounting → Cr 4060 Late Fee ✅
```
Every hop verified against source. **The chain is closed end-to-end for the first time.**

## 12. STUDENT LIFECYCLE TRACE

| Transition | StudentID | SRNO | AdmNo | Roll | Class/Section | Capacity | Fees |
|---|---|---|---|---|---|---|---|
| Admission | new | allocated | generated | manual/Suggest | set | ✅ | **dueDate ✅** |
| Import | new | allocated | generated | from file | ✅ projected | ✅ | **dueDate ✅** |
| CRM | new | allocated | generated | not set | ✅ | ✅ | **dueDate ✅** |
| **Promotion** | unchanged | unchanged | frozen | carried+checked | changes | ✅ | **dueDate ✅ · cache ✅** |
| Transfer | unchanged | unchanged | unchanged | carried+checked | section changes | ✅ | unaffected |
| Withdrawal | unchanged | preserved | preserved | preserved | preserved | seat freed | frozen |
| Re-admission | new | preserved (relink) | new | validated | new | ✅ | **dueDate ✅** |

## 13–15. CLASS/SECTION · ROLL NUMBER · SRNO REGRESSION

**Class/Section:** all six capacity paths intact; session-scoped; Active-only occupancy;
`maxStrength <= 0` still unlimited; running seat counter intact; no N+1. **No capacity redesign.**

**Roll Number:** School+Session+Class+Section scope intact; **no global uniqueness introduced**; no
silent auto-assignment; no renumbering; alphanumeric-safe (string compare, no numeric cast); Suggest
atomic claim intact. **L-1 strengthens promotion-time collision detection** — the guarantee now holds
immediately rather than after 90 s. Bulk roll-assignment screen **deliberately not built** (not part
of this correctness chain, per §11 of the directive).

**SRNO:** operational lookup preserved; never persisted as a foreign key; **never derived from Student
ID** — enforced by crossed fixtures (`STU0012↔47`, `STU0047↔12`) in the new suite. Receipt shows
SR No.; `feeReceipts.studentId` remains canonical.

## 16. CROSS-MODULE REGRESSION

Attendance, Homework, Marks/Results, PTM, Transport, Library, Hostel, Communication, Documents,
Certificates/TC: **all key on `studentId`; none consumes `dueDate`, `fine_amount` or the student
list cache in a way this release changes.** Accounting is the only downstream consumer and it
receives a *more* correct value. **No RTDB introduced anywhere.**

## 17. PARENT / TEACHER / ADMIN IMPACT

- **Parent App:** demand docs already downloaded whole → `dueDate` costs **zero** extra reads; a field
  appearing where `null` was tolerated is non-breaking for Kotlin `String?`. `FEE_DUE`'s declared
  `dataKeys:['amount','dueDate']` is now satisfiable. **No app change required or made.**
- **Teacher App:** no fee surface — **zero impact. Not modified.**
- **Admin:** cashiers see the computed fine applied automatically; unauthorized waivers refused.

## 18. TESTS

```
Baseline (pre-change):  798 tests, 2507 assertions, 10 failures
After:                  830 tests, 2574 assertions, 10 failures
New:                    +32 tests, all passing.  NEW FAILURES: 0
```
Pre-existing failures (unchanged, **not mine**): Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2.

Coverage: due date (format, day 1, day 28, February, leap year, clamp, lexicographic ordering,
malformed key, session-spanning months, **N+1 memo**); fine (survives proration, **order
independence**, partial, full, zero, decimal, repeated/idempotent, over-collection); fine control
(computed used automatically, silent waiver blocked, silent reduction blocked, unauthorized override
refused, reason required, authorized override audited, manual ad-hoc preserved, single value to
receipt+accounting); identity (crossed SRNO/StudentID).

## 19. UAT MATRIX — **NOT EXECUTED**

No browser or device testing was performed. All rows are **PENDING**.

| # | Test | Preconditions | Steps | Expected | Actual |
|---|---|---|---|---|---|
| 1 | Admission | Fee structure exists | Admit student | Demand created **with dueDate** | PENDING |
| 2 | Section capacity | Section at max | Admit | Rejected with counts | PENDING |
| 3 | Roll validation | Roll taken | Enter same roll | Rejected | PENDING |
| 4 | SRNO lookup | Known SRNO | Search by SRNO | Correct student | PENDING |
| 5 | Fee demand | Post-admission | Open ledger | Demands listed | PENDING |
| 6 | **Due date** | due_day=10 | Inspect demand | `YYYY-MM-10` | PENDING |
| 7 | **Late fine** | Back-dated demand | Run auto_compute_fines | `fine_amount` set once | PENDING |
| 8 | **Proration preserves fine** | Fine applied | Trigger proration | **Fine still in balance** | PENDING |
| 9 | **Fine override (unauth)** | COUNTER user | Request override | **403 refused** | PENDING |
| 10 | **Fine override (auth)** | MANAGE user + reason | Override | Applied + audited | PENDING |
| 11 | Receipt | Payment w/ fine | Print | SR No., Roll, fine; no Student ID | PENDING |
| 12 | Accounting | After payment | Inspect journal | **Cr 4060 Late Fee** | PENDING |
| 13 | Parent App | After payment | Open fees | Correct amounts | PENDING |
| 14 | **Promotion + roll** | Promote class | Immediately assign a taken roll | **Collision caught at once** | PENDING |
| 15 | **Promotion + capacity** | Promote | Immediately check capacity | Correct counts | PENDING |
| 16 | Transfer | — | Transfer section | Capacity + roll enforced | PENDING |
| 17 | Re-admission | Withdrawn student | Re-admit | SRNO relinked, new StudentID, dueDate | PENDING |
| 18 | Bulk import | CSV | Import | Capacity projected, dueDate stamped | PENDING |
| 19 | Reminder setting | Open reminders | Inspect | **Disabled + honest hint** | PENDING |
| 20 | No regression | — | Existing flows | Unchanged | PENDING |

## 20. REMAINING ISSUES — with reasons

| Issue | Why it remains |
|---|---|
| **`createModuleFee()` fine erasure** (`Fee_lifecycle.php:1043`) | 🔴 **BLOCKED — FROZEN ARCHITECTURE.** Structurally identical to F-1: `$balance = max(0.0, round($net - $paidSoFar, 2))` drops the fine. Sits inside `createModuleFee()` (lines 987–1112), the Fees Exemption v2 surface that `fees_exemption_v2_flags.php` states Phase 3 will converge off. Rule §22 forbids touching it. **Currently harmless** (module fees are Transport/Hostel, and fines are only computed on academic demands), **but it must be fixed in the entitlements cutover.** ZENX-VERIFIED. |
| `createModuleFee()` due dates | Same frozen surface. Module fees remain dateless → exempt from late fees. |
| Late-fee policy (grace/cap/basis) | **INTENTIONALLY DEFERRED to R2.** State-specific law; hard-coding any value would be wrong for some tenants. Needs D2. |
| Real reminder automation | **SEQUENCED** — needs due dates (now landed) + a scheduler. Will reuse the `FeeWorker` queue pattern; **no second dispatcher.** |
| Per-day penalties | **NOT SAFE** — breaks the `abs($existingFine-$fine)<0.01` idempotency guard; needs cap + scheduler. |
| Fine reversal / waiver accounting | **INSUFFICIENT EVIDENCE** + unreachable until fines exist in production. |
| Backfill of pre-existing dateless demands | **REQUIRES AUTHORIZATION (D7)** — production data. Not touched. |
| Bulk roll-assignment screen | SOURCE-VERIFIED gap, but **not part of this correctness chain**; deliberate scope discipline. |
| Import capacity preview · full-section override · atomic seats | Business decisions / independent. |
| ID-card replacement | Blocked by frozen v2; also a **security** workflow (signed access tokens). |

## 21. BUSINESS DECISIONS STILL REQUIRED

**D2** late-fee policy (grace/cap/basis defaults) · **D3** full-section override authority ·
**D4** promotion roll carry-vs-clear · **D5** duplicate-roll survey authorization ·
**D7** backfill dateless demands · **D9** installments as own phase ·
**D11 (new)** confirm the frozen-surface `createModuleFee` fine fix rides the entitlements cutover.

## 22. EXPLICITLY EXCLUDED — and why

AI placement (unevidenced + discrimination risk) · silent auto roll assignment (NOT ESTABLISHED) ·
global roll uniqueness (contradicted) · Exam Roll Number (insufficient evidence) · per-day penalties
(unsafe now) · installments (greenfield) · ID-card replacement (frozen + security) · atomic seat
reservation (should be one shared primitive) · **any rules/indexes change** (none needed) ·
**any production data change** (unauthorized).

## 23. GIT STATUS

```
Branch:  integration/phase2          HEAD: 758c924   (unchanged — no commit made)
Modified by me: application/libraries/Fee_lifecycle.php
                application/libraries/Firestore_service.php
                application/services/FeeCollectionService.php
                application/controllers/Sis.php
                application/views/fee_management/reminders.php
Untracked by me: tests/Unit/Phase155FeesCorrectnessChainTest.php
                 PHASE15.5_..._CLOSURE_REPORT.md

Working tree total: ~446 entries — the rest is PRE-EXISTING uncommitted work
from earlier phases. None of it was touched.

firebase-rules/firestore.rules ........ MODIFIED BEFORE THIS PHASE — NOT touched by me
firebase-rules/firestore.indexes.json . MODIFIED BEFORE THIS PHASE — NOT touched by me

COMMIT: none    PUSH: none    MERGE/REBASE: none    DEPLOY: none
```

## 24. FINAL VERDICT

**Can a real school safely depend on these workflows now? For the fee correctness chain — yes, with
two caveats stated plainly.**

What changed for a school: fees now carry a due date, so the system can finally tell that a payment
is late. A penalty, once applied, survives every later recalculation instead of quietly vanishing.
The penalty the system calculates is the penalty that gets collected, printed and posted to the
ledger — a cashier can no longer waive it by typing zero, and if someone with the right authority
does waive it, there is a reason and a record. And promoting a class no longer leaves a 90-second
window where roll-number protection can be bypassed.

**Caveat one:** late fees still have to be triggered manually, one student at a time. Nothing charges
them automatically, because there is no scheduler yet. That is R3, and it is deliberate — building a
scheduler before the numbers underneath it were correct would have automated the wrong answer.

**Caveat two:** existing fee demands created before today still have no due date and remain exempt
from late fees. Fixing that means touching live data, which I have not done and will not do without
explicit authorization.

**One thing I could not fix and want visible rather than buried:** the same fine-erasing formula
exists in a second place, `createModuleFee()`, which sits on the frozen entitlements architecture. It
is harmless today — module fees are Transport and Hostel charges, and fines are only computed on
academic demands — but it must be fixed when that architecture unfreezes, or the defect will return
through a different door.

**UAT has not been run.** Tests pass and the baseline is unchanged, but no browser or device testing
was performed, and I am not claiming otherwise. The 20-row matrix in §19 is ready to execute.

**Nothing has been committed, pushed or deployed.**
