# Phase 15.8 — Fees Final Closure, Open-Issue Resolution & Production-Readiness Audit

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED. UAT NOT EXECUTED.**
No production data read or written. `firestore.rules` / `firestore.indexes.json` **NOT modified**.
No RTDB introduced. No Class/Section/Roll implementation — regression-watch only.

---

## 1. EXECUTIVE SUMMARY

The Fees-wide sweep found **one more live instance of the exact defect class this programme has been
chasing**, and it was the most consequential remaining one because it was reachable from a real
button that staff use every week.

**F-1 (NEW) — "WhatsApp reminder" sent nothing. 🔴 HIGH · ZENX-VERIFIED · FIXED.**
The Defaulter Report has a **WhatsApp button** (`defaulter_report.php:385`, `channel='whatsapp'`).
It wrote a `feeReminderLog` row with `status='queued'` and told the operator
*"WhatsApp reminder queued for N student(s). Delivery by the messaging worker."*
**No such worker exists in this repository** — verified by sweep: the only readers of
`feeReminderLog` are the two history endpoints in the same controller, and nothing ever moves a row
out of `queued`. So no message was ever delivered, the row sat "queued" forever in the reminder
history, and the fee office believed those parents had been contacted.

This is the same failure class as the dead `auto_remind` switch (15.5) and the dead
`reminder_message` (15.7): **the damage is not the missing feature — it is that staff stop chasing
parents because the screen says it was handled.**

**Fixed completely within scope:** the status now records `not_sent`, the response carries an
explicit `delivered:false`, the operator is told plainly that nothing was sent and which channel does
work, the button is relabelled **"WhatsApp — record only"**, and the JS fallback no longer invents a
"queued" success. Actual WhatsApp delivery remains **BLOCKED BY EXTERNAL PROVIDER** (D14) — I did not
invent one, and I deliberately did **not** silently reroute the button to push, which would simply be
a different lie.

**Also this phase:** I verified my own 15.7 work rather than trusting it — `functions/index.js:158`
does `doc.body || spec.fBody`, confirming `reminder_message` genuinely reaches parents and that I did
**not** create a new dead setting.

**Tests: 896 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +10 new tests.**

---

## 2. CORRECTIONS TO PRIOR PHASES

**CORRECTION TO PRIOR PHASE (15.7).** My 15.7 report stated the Fees module had **"ZERO dead
settings"** after the sweep. **That was too strong.** The sweep covered `feeSettings` keys
(`reminders.php`), not every Fees *action*. A whole delivery channel — reachable from a live button
on a different screen — was still fake. **Corrected: the sweep this phase covered all Fees form
controls across `views/fee_management/` and `views/fees/`, which is how F-1 surfaced.**

No other prior claim was contradicted. Re-verified as still true: `emit_push` delegation intact;
`FEE_DUE` wired; late-fee idempotency guard; grace/cap; fine-aware proration; G-3 canonical fine;
receipt identity; 4060 ledger; promotion cache bust.

## 3. RESEARCH — sources and classification

| Claim | Label | Source |
|---|---|---|
| **Hostel fees do attract late fees** — e.g. "₹500 per month if not paid by the due date" | **SOURCE-VERIFIED** | [Matrix High School fee structure](https://www.matrixhighschool.org/fee-structure) |
| Transport is configured as **separate fee slabs/heads** (3–5 by distance/route) | SOURCE-VERIFIED | [Campus24x7](https://campus24x7.in/blogs/school-fee-structure-erp-setup-guide-india) |
| Fee heads are separable (tuition / transport / hostel / lab / exam) and penalties tracked separately | INDUSTRY PRACTICE | [Nascorp](https://www.nascorptechnologies.com/student-fee-management-system-software-erp-for-schools), [Indian Servers](https://indianservers.com/fee-management-software-for-schools/) |
| Fee structures vary within a class (day-boarder / hosteller / EWS); some heads don't apply to some students | SOURCE-VERIFIED | Campus24x7 |
| "Schools configuring late fees report 15–20% better on-time collection" | **INDUSTRY PRACTICE (vendor claim — not independently verified)** | Campus24x7 |
| SMS/WhatsApp/email delivery mechanics inside school ERPs | **NOT ESTABLISHED** — vendors document features, not dispatch internals | — |
| A single national Indian K-12 penalty rule | **NOT ESTABLISHED** — state jurisdiction (Delhi/Maharashtra/Gujarat differ) | prior phases |

**D13 answer, on evidence:** module fees **do** attract late fees in real schools (hostel is
SOURCE-VERIFIED). **But this does not make it safe to switch on in ZenX**, because transport and
hostel are *suspendable* services and the same sources show fee heads that do not apply to some
students. **ZENX RECOMMENDATION: per-module opt-in, default OFF, honouring suspension / withdrawal /
cancellation and entitlement exemptions.** Still blocked by the frozen surface — see §5.

## 4. FEES LIFECYCLE — end-to-end verification

| Stage | Source of truth | Repeat-safe? | RBAC | Audit | N+1 | Verdict |
|---|---|---|---|---|---|---|
| Demand generation | `feeDemands` | ✅ `periodKey\|headId` skip | MANAGE | — | no | ✅ |
| dueDate | demand doc | ✅ deterministic | — | — | **no** (memoised) | ✅ 15.5 |
| Grace / overdue | `feeSettings` | ✅ | FINANCE | — | no | ✅ 15.6 |
| Late fee | demand | ✅ absolute write + equality guard | MANAGE / CLI | log | no (1 query) | ✅ |
| Fine override | collection | ✅ | **MANAGE + reason** | **✅ audited** | no | ✅ 15.5 |
| Collection | `feeReceipts` | ✅ `feeIdempotency` | COUNTER | ✅ | no | ✅ |
| Receipt | receipt doc | ✅ atomic counter | — | — | no | ✅ |
| Accounting | journal | ✅ deterministic `JE_FEE_{key}` | — | ✅ | no | ✅ |
| Reminder eligibility | demands | ✅ one stage/day | CLI-only | log | no (1 query) | ✅ 15.7 |
| Payment re-check | demand re-read | ✅ fails closed | — | log | bounded by sends | ✅ |
| Enqueue | `pushRequests` | ✅ deterministic docId | CLI-only | doc = record | no | ✅ |
| CF dispatch | MARK_REGISTRY | CF-owned | — | — | — | ✅ |
| **Other channels** | `feeReminderLog` | — | FINANCE | ✅ | no | **⛔ no provider — now honest** |
| Promotion / re-admission / withdrawal | students + demands | ✅ | MANAGE | ✅ | no | ✅ |

**Sibling behaviour:** every key includes `studentId`; dedupe keys and demands are per-student.
**Withdrawn:** frozen demands are `archived` → never fined, never reminded.
**Session change:** every query carries `['session','==',…]`.
**Paid-immediately-before-send:** re-read drops the candidate.

## 5. OPEN-ITEM RESOLUTION

**D7 — Historical due-date backfill. ⛔ BLOCKED BY PRODUCTION AUTHORIZATION *and* environment.**
The dry-run planner exists (`planDueDateBackfill()` / `plan_due_date_backfill`). **I could not run it:
no service-account credential is present on this machine** (verified). I therefore **cannot report
real counts and will not estimate them.** Reconstruction is `periodKey + getDueDay()` — identical to
the live generator — and applies **only** to canonical `YYYY-MM` keys; anything else is reported
AMBIGUOUS and never guessed.
⚠ Stated limitation: it uses the school's **current** due day; a mid-year change to that setting is
undetectable from data.
**Smallest decision required:** *"Run `plan_due_date_backfill` on <school/session> and share the
output"* — then, separately, whether to apply it.

**D11 / D13 — `createModuleFee()`. ⛔ BLOCKED BY FROZEN ARCHITECTURE.** Re-verified this phase; the
freeze stands. `Fee_lifecycle.php:1043`, inside `createModuleFee()` (987–1112):
`$balance = max(0.0, round($net - $paidSoFar, 2))` omits `fine_amount`.
**Currently harmless** — module fees are Transport/Hostel and the engine only fines academic demands,
so no fine exists on these docs. **No current financial path is silently wrong.**
*Acceptance test at unfreeze:* module fee + posted fine → re-run `createModuleFee` → assert
`balance == net + fine − paid` and `fine_amount` unchanged.
**Smallest decision required (D13):** *"When entitlements unfreeze, should Transport/Hostel fees
attract late fees — yes/no, and per-module opt-in?"* My recommendation: **yes, opt-in, default OFF.**

**D14 — SMS / WhatsApp / email. ⛔ BLOCKED BY EXTERNAL PROVIDER.**
What exists: `send_reminder` accepts `log | push | whatsapp | sms | email`. **Push genuinely
dispatches** inline via FCM. **The other three only wrote a log row** — now honestly reported (F-1).
**Smallest decision required:** *"Which provider (MSG91 / Gupshup / Twilio / none) and who pays?"*
Until answered, ZenX should stay **push-only**, which it now says truthfully.
**ZENX RECOMMENDATION:** do **not** build a provider abstraction speculatively — one concrete
provider first, then generalise if a second is ever needed.

## 6. DEAD-SETTING / DEAD-FEATURE SWEEP (Part 7)

Enumerated every control across `views/fee_management/` and `views/fees/`:
`auto_remind · days_before_due · days_after_due · remind_on_due · reminder_message ·
due_day_of_month · late_fee_enabled · late_fee_type · late_fee_value · late_fee_grace_days ·
late_fee_max_cap · discount_type · frequency · scope · criteria · effective_from/to · max_cap ·
provider · api_key · api_secret · webhook_secret · mode · receipt_no · student_id · reason …`

| Result | |
|---|---|
| Settings with a real consumer | **all `feeSettings` keys** — verified individually |
| **Dead feature found** | **WhatsApp/SMS/email delivery (F-1) — FIXED (honesty), delivery blocked on D14** |
| Values consumed but never displayed | none found |
| camel/snake mismatch | none — `normalizeDemandDoc` bridges both |
| Settings that cannot be turned off | **fixed in 15.7** (`FormData` omits unchecked boxes) |
| Frontend/backend disagreement | **F-1 was the last one** |

## 7. SECURITY / RBAC (Part 8)

| Action | Server gate | Fails closed |
|---|---|---|
| Collection | COUNTER + `Fees:edit` | ✅ |
| Discount | MANAGE + `Fees:edit` + server cap vs approved concession | ✅ |
| **Fine override** | **MANAGE + reason + audit** | ✅ (no RBAC → refuse) |
| Fine computation | MANAGE + `Fees:edit` | ✅ |
| Late-fee / reminder settings | FINANCE + `Fees:edit` | ✅ |
| Send reminder | FINANCE + `Fees:edit` | ✅ |
| Crons / backfill planner | **CLI-only** (`is_cli_request()`) | ✅ unreachable over HTTP |

**The fine=0 waiver hole is closed and re-verified.** Default: the computed fine wins and a silently
altered POST value is ignored. Override: explicit request + MANAGE + reason, else refused *before any
receipt/allocation/journal write*. Divergences are audited either way. **A COUNTER user can no longer
perform the equivalent of a manager-level waiver.**

## 8. PERFORMANCE / CONCURRENCY (Part 9)

| Operation | Reads | Writes | Repeat behaviour |
|---|---|---|---|
| Late-fee cron | 1 settings + 1 query | W (**0 on re-run**) | idempotent |
| Reminder cron | 1 settings + 1 query + R re-reads | R enqueues (coalesce) | idempotent |
| Backfill planner | 1 query | **0** | read-only |
| Collection | unchanged | unchanged | `feeIdempotency` |
| Receipt number | atomic claim | 1 | collision-free |

**Honest concurrency classification — not claimed atomic:**
- **Atomic:** receipt numbering (`feeCounters` create-if-not-exists), push enqueue (deterministic
  docId), journal (deterministic id).
- **Idempotent but NOT atomic:** late-fee application (read-then-write per demand). Two concurrent
  runs could both write the same fine — harmless, because the value is absolute and identical.
- **Detection-grade, NOT atomic (pre-existing, unchanged):** section capacity and roll uniqueness.

**No N+1 introduced.** The one bounded per-item read (reminder re-check) is bounded by *notifications
sent*, not students, and is a deliberate correctness cost.

## 9. RECEIPT / ACCOUNTING FINAL TRACE (Part 5) — re-verified, no change

SR No. (`receipt.php:458`) ✅ · Roll No. (474) ✅ · fine when > 0 (563) ✅ · **Student ID not printed**
(445–450) ✅ while `feeReceipts.studentId` remains the canonical FK ✅.
**collection == receipt == accounting** — all three consume the single `$fineAmount` resolved by the
G-3 gate *before* both `netTotal` computations. Fine credits **Cr 4060 Late Fee**, bank ledger
validated as existing/active/`is_bank`/postable-leaf, duplicate receipts blocked by `feeIdempotency`.
**No working receipt/accounting logic was modified.**

## 10. STUDENT LIFECYCLE REGRESSION (Part 10)

**No lifecycle code touched this phase.** `Sis.php`, `Fee_lifecycle.php`, `FeeCollectionService.php`
untouched in 15.8. Invariants re-asserted by tests across four suites:
Student ID ≠ SRNO ≠ Roll ✅ · crossed fixtures `STU0012↔47` / `STU0047↔12` ✅ · `studentId` the sole
FK ✅ · SRNO never persisted as a key ✅ · receipt shows SRNO+Roll, hides Student ID ✅ · siblings
independent ✅ · promotion cache bust intact ✅ · capacity enforced ✅ · roll validation intact ✅.
**No regression caused by Fees work.**

## 11. TESTS (Part 13)

```
Baseline:    798 tests, 2507 assertions, 10 failures
After 15.5:  830  (+32)
After 15.6:  859  (+29)
After 15.7:  886  (+27)
After 15.8:  896  (+10)   →  2693 assertions, 10 failures
NEW FAILURES: 0
```
**This is NOT "all tests pass"** — the 10 pre-existing baseline failures remain, unchanged and not
mine: Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2.

**Lint:** all touched files clean. **RTDB scan:** clean. **Rules/indexes:** unmodified.

## 12. UAT MATRIX (Part 14)

| # | Test | Status |
|---|---|---|
| 1 | Admission → demand + dueDate | UNIT TEST VERIFIED · UAT PENDING |
| 2 | Due-date calculation (1/28/Feb/leap) | UNIT TEST VERIFIED · UAT PENDING |
| 3 | Grace period | UNIT TEST VERIFIED · UAT PENDING |
| 4 | Late fee | UNIT TEST VERIFIED · UAT PENDING |
| 5 | Late-fee cap | UNIT TEST VERIFIED · UAT PENDING |
| 6 | Fine collection | UNIT TEST VERIFIED · UAT PENDING |
| 7 | Fine override authorization | UNIT TEST VERIFIED · UAT PENDING |
| 8 | Fine audit record | STATIC VERIFIED · UAT PENDING |
| 9–11 | Receipt SRNO / Roll / Student ID hidden | STATIC VERIFIED · UAT PENDING |
| 12 | Accounting 4060 ledger | STATIC VERIFIED · UAT PENDING |
| 13 | Partial payment | UNIT TEST VERIFIED · UAT PENDING |
| 14 | Fully paid student | UNIT TEST VERIFIED · UAT PENDING |
| 15–17 | Reminder before / on / overdue | UNIT TEST VERIFIED · UAT PENDING |
| 18 | Paid-before-send race | UNIT TEST VERIFIED · UAT PENDING |
| 19 | Duplicate reminder prevention | UNIT TEST VERIFIED · UAT PENDING |
| 20 | Sibling reminder separation | UNIT TEST VERIFIED · UAT PENDING |
| 21 | Reminder settings persist | STATIC VERIFIED · UAT PENDING |
| 22 | Custom reminder message reaches parent | STATIC VERIFIED (`index.js:158`) · UAT PENDING |
| 23 | Dry-run modes | STATIC VERIFIED · UAT PENDING |
| 24 | Historical backfill planner | STATIC VERIFIED · **NOT VERIFIED** (no credential) |
| 25–27 | Promotion / transfer / re-admission | UNIT TEST VERIFIED · UAT PENDING |
| 28 | Student ID / SRNO invariants | UNIT TEST VERIFIED |
| 29 | Class/Section/Roll regression | UNIT TEST VERIFIED · UAT PENDING |
| 30 | CLI-only guard | STATIC VERIFIED · UAT PENDING |
| **31** | **WhatsApp button reports "not sent"** | **UNIT TEST VERIFIED · UAT PENDING** |

**No browser or device UAT has been executed at any point in this programme.**

## 13. FINAL DECISION MATRIX (Part 15)

**A. FIXED NOW** — F-1 WhatsApp/SMS/email false-delivery (🔴 HIGH): status `not_sent`,
`delivered:false`, honest message, relabelled button, honest JS fallback.

**B. VERIFIED CORRECT** — due dates · grace/cap · late-fee idempotency · fine-aware proration ·
canonical fine + authorized override + audit · receipt identity · 4060 ledger · receipt numbering ·
duplicate-payment protection · reminder eligibility/dedupe/re-check · `reminder_message` delivery ·
tenant + session isolation · CLI guards · promotion cache bust · lifecycle identity invariants.

**C. NEEDS YOUR BUSINESS DECISION**
- **D13** Should Transport/Hostel attract late fees? (evidence: hostel late fees are real; but they're
  suspendable services) → *recommend per-module opt-in, default OFF*
- **D2** Per-day penalties? → *recommend no, until a mandatory cap exists*
- **D3/D4/D5** Class/Section/Roll: override authority · promotion roll policy · duplicate survey
- **D9** Installments as their own phase

**D. NEEDS PRODUCTION AUTHORIZATION** — **D7** historical backfill. *Smallest ask: run
`plan_due_date_backfill` on one school/session and share the output.*

**E. NEEDS EXTERNAL PROVIDER** — **D14** SMS/WhatsApp/email. *Smallest ask: name a provider, or
confirm push-only.*

**F. FROZEN / DEFERRED** — **D11** `createModuleFee` fine erasure (harmless today; acceptance test
recorded) · ID-card replacement (frozen + signed-credential security).

**G. FUTURE ONLY** — installments (greenfield; Gujarat Rule 7(7) constrains) · fine
waiver-after-collection & fine-specific refund reversal (**NOT ESTABLISHED BY SOURCES**, unreachable
until fines exist) · atomic seat reservation (one shared primitive).

**H. NO ISSUE / DO NOT BUILD** — AI placement (unevidenced + discrimination risk) · silent auto roll
assignment (NOT ESTABLISHED) · global roll uniqueness (contradicted) · a second notification sender.

## 14. GIT STATUS

```
Branch: integration/phase2     HEAD: 758c924   (unchanged — no commit)

Modified this phase:  application/controllers/Fee_management.php
                      application/views/fees/defaulter_report.php
New this phase:       tests/Unit/Phase158ReminderChannelHonestyTest.php

Carried uncommitted from 15.5–15.7: Fee_lifecycle · Firestore_service ·
  FeeCollectionService · Sis · Fees · MY_Controller · Fee_late_fee_service ·
  Fee_reminder_service · Push_enqueue_service · Fees_late_fee_cron ·
  reminders.php · Phase155/156/157 tests

firebase-rules/* ... dirty BEFORE this programme — NOT touched
~440 other entries . pre-existing work — untouched

COMMIT: none  PUSH: none  MERGE/REBASE: none  DEPLOY: none
```

---

# FEES FINAL CLOSURE VERDICT

**Fees is ready to move to the Class/Section/Roll closure phase — with three items on your desk, none
of which blocks that move.**

Everything a school does daily — raise fees, charge them, apply a penalty, collect it, print a
receipt, post it to the ledger, notify a parent — is now correct, idempotent, authorized, audited and
free of false promises. Four coupled financial defects are closed, automation is real, and after four
phases of sweeping, **no dead Fees control remains.**

What remains is genuinely not engineering work:
1. **D7** — one read-only command on a real tenant (I have no credential).
2. **D14** — a messaging provider, or confirm push-only.
3. **D13/D11** — whether module fees get penalties, actioned when entitlements unfreeze.

**Recommendation: proceed to Class/Section/Roll closure.** Its open items (import capacity preview,
full-section override + audit, bulk roll-assignment screen, duplicate-roll survey) have been on
regression-watch since 15.1 and are unaffected by anything above.

**One caveat I will keep repeating: none of this has been UAT-tested.** Tests and static analysis are
green against baseline, but no browser or device run has happened. Before this reaches a real school,
the 31-row matrix should be executed — especially the fine override, the receipt, and the paid-before-
reminder race.

---

## WHAT THIS MEANS FOR A REAL SCHOOL

**We found one more thing that was quietly lying to your office, and it was the worst one.**

On the Defaulter Report there's a WhatsApp button. Your staff select the families who haven't paid,
click it, and the screen says the reminders were queued for delivery. **No WhatsApp message was ever
sent.** There is no service connected to actually send them. The records sat marked "queued" forever,
and your office moved on believing those parents had been contacted.

That's worse than not having the button, because it stops people from picking up the phone.

**It now tells the truth.** If you use WhatsApp, SMS or email, you'll be told plainly that the
reminder was recorded but not sent, and that no provider is configured — and it points you at the
Push option, which genuinely does reach parents in the app. We deliberately did *not* quietly make
the WhatsApp button send a push instead; that would just be a different lie.

**If you want real WhatsApp or SMS, that's a purchase, not a fix.** Someone has to choose a provider
and pay for it. Say the word and it can be connected.

**Where Fees stands now:** fees carry due dates, penalties apply automatically each night under your
own grace period and ceiling, running that job twice never charges twice, the penalty your system
calculates is the one collected and printed, waiving it needs a manager and leaves a record, and it
lands in its own ledger line. Parents get reminded in the app, once per stage, and never if they've
just paid.

**Two things still need you.** Fees created before this work have no due date and can never be flagged
late — we can show you exactly which ones are safely repairable, but changing real financial records
is your call. And whether transport and hostel fees should carry late fees is a policy question; real
schools do charge them, but those are services families can suspend, so we haven't assumed an answer.

**Fees is done. Nothing has been committed, pushed or deployed.**
