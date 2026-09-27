# Phase 15.9 — Fees Final Closure Gate

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**AUDIT PHASE — NO CODE CHANGED. Nothing committed / pushed / merged / rebased / deployed.**
No production data read or written. `firestore.rules` / `firestore.indexes.json` untouched.
No Class/Section/Roll implementation. **UAT NOT EXECUTED.**

---

## 1. EXECUTIVE SUMMARY

This gate re-verified the Fees chain against **source, not prior reports**, and deliberately audited
the three areas the programme had **never deeply traced**: refunds, the defaulter report, and the
legacy sync tooling.

**Three areas verified correct for the first time** (previously assumed, now proven):

| Area | Result |
|---|---|
| **Refund / reversal money math** | ✅ **fine-aware already** — `Fee_refund_service.php:535` computes `$newBal = round(max(0, $net + $fine - $newPaid), 2)`, reading both key spellings |
| **Defaulter report** | ✅ **no N+1** — bulk `feeDefaulters` + `studentFeeSummary` queries, joined in memory |
| **Concessions "under construction" page** | ✅ **honest** — flag-gated with a **server-side re-check**, not view-only |

**One new finding, and I did not fix it — deliberately.**

**F-1 (NEW) — `firestore_bulk_sync` reports success while doing nothing. 🟡 MEDIUM · ZENX-VERIFIED.**
`Fee_management::firestore_bulk_sync()` (line 4913, routed at `routes.php:1104`) calls ~16 `syncAll*`
methods that read **retired RTDB** and returns *"Firestore bulk sync completed."* — the same
false-success class as the WhatsApp button. `Fee_firestore_sync::syncAllDefaulters()` (line 704) is
also an **N+1 by construction**: `shallow_get($basePath)` then `firebase->get("{$basePath}/{$studentId}")`
**once per student**. `Firebase::get()` is RTDB — `$this->database->getReference($path)->getValue()`.

**Why I stopped instead of fixing it** (§5 gives the full reasoning): it is **not operator-facing**
(routed, but exposed by no button or link in any view), and it spans **Fees + Homework + Accounting**
as part of the separately tracked RTDB-elimination programme. Making only the Fees third honest would
be exactly the half-fix this programme forbids.

**The live Fees runtime path is RTDB-clean** — verified by scan across `Fee_late_fee_service`,
`Fee_reminder_service`, `Push_enqueue_service`, `Fee_lifecycle`, `FeeCollectionService`.

**Tests: 896, 10 failures — identical to the 798/10 baseline. No code changed, so no delta.**

---

## 2. WHAT WAS RE-VERIFIED (source over report)

| Claim from a prior phase | Re-verified verdict |
|---|---|
| Refunds preserve the fine | ✅ **TRUE** — now proven, was previously untraced |
| Defaulter report has no N+1 | ✅ **TRUE** — now proven |
| `reminder_message` reaches parents | ✅ **TRUE** — `functions/index.js:158` `doc.body \|\| spec.fBody` |
| `emit_push` delegation intact | ✅ |
| Late-fee idempotency (`abs(existing−computed) < 0.01`) | ✅ |
| Fine-aware proration | ✅ |
| G-3 canonical fine → receipt → 4060 | ✅ |
| Receipt shows SR No./Roll, hides Student ID | ✅ |
| `createModuleFee` defect still frozen & unreachable | ✅ |
| Fees has zero dead settings | ✅ for `feeSettings`; **F-1 is a dead *endpoint*, not a setting** |

## 3. WHAT WAS RESEARCHED

No new business decision arose this phase, so no new external research was required. Prior evidence
stands and is unchanged: hostel late fees are real (**SOURCE-VERIFIED**), Indian fee regulation is
**state jurisdiction** (**SOURCE-VERIFIED**), no national K-12 grace/cap rule (**NOT ESTABLISHED**),
and SMS/WhatsApp dispatch internals are **NOT ESTABLISHED** by vendor documentation.

## 4. NEW FINDINGS

| ID | Finding | Sev | Evidence |
|---|---|---|---|
| **F-1** | `firestore_bulk_sync` reports success while reading retired RTDB; `syncAllDefaulters` is an N+1 | 🟡 MED | `Fee_management.php:4913/4946`, `Fee_firestore_sync.php:704`, `Firebase.php:283` |

**No new money-correctness defect, no new privilege gap, no new N+1 in the live Fees path.**

## 5. F-1 — full trace and the scope call

```
routes.php:1104  fee_management/firestore_bulk_sync
  └─ Fee_management::firestore_bulk_sync()            :4913
       ├─ fsSync->syncAllFeeStructures()        ─┐
       ├─ fsSync->syncAllDemandsForAllStudents() │  all read RTDB
       ├─ fsSync->syncAllDefaulters()      :659 ─┤  → return 0 once RTDB is retired
       ├─ …13 more syncAll* (Homework, Accounting)┘
       └─ json_success('Firestore bulk sync completed.')   ← claims success regardless

Fee_firestore_sync::syncAllDefaulters()
  $studentIds = firebase->shallow_get("Schools/{code}/{session}/Fees/Demands")   ← RTDB
  foreach ($studentIds as $studentId)
      firebase->get("{basePath}/{$studentId}")                                    ← RTDB, PER STUDENT
Firebase::get() → $this->database->getReference($path)->getValue()                ← RTDB confirmed
```

**Consequences:** with RTDB retired the loop never executes (`shallow_get` returns nothing), every
counter is `0`, and the endpoint still reports completion. If RTDB *were* populated, it would issue
one sequential round trip per student.

**Why this is NOT fixed here — the four honest reasons:**
1. **Not operator-facing.** Routed, but no view links it. A school administrator cannot reach it by
   clicking. The WhatsApp button was fixed precisely *because* staff click it weekly; this is not
   the same exposure.
2. **Cross-subsystem.** It syncs Fees **and Homework and Accounting**. A Fees-phase change would
   either touch two other subsystems or fix one third of an endpoint — the half-fix this programme
   explicitly forbids.
3. **It belongs to a tracked programme.** RTDB elimination is its own workstream (~600 sites); this
   is one of them, not a stray Fees bug.
4. **Deleting it unilaterally is not obviously safe.** It is documented migration tooling; removing a
   path someone may still consider a fallback is a decision, not a cleanup.

**Recommended action (needs your word):** retire `firestore_bulk_sync` and the `syncAll*` RTDB
readers as part of the RTDB-elimination phase. Minimal interim alternative if you want it neutralised
now: have the endpoint fail closed with *"Legacy RTDB sources are retired — this tool no longer
performs any migration"* rather than report completion. **One-line decision: retire, neutralise, or
leave until the RTDB phase.**

## 6. FIXES IMPLEMENTED THIS PHASE
**None.** Everything audited was either verified correct or is a genuine boundary (§5, §15–17).
Per the gate's own rule, I did not invent work to appear productive.

## 7. FILES CHANGED
**None this phase.** Working tree unchanged from the end of 15.8.

## 8. FEES LIFECYCLE TRACE (51 points — condensed verdicts)

| Stage | Verdict |
|---|---|
| 1–4 structure / config / heads / assignment | ✅ |
| 5–9 demand generation (admission, promotion, re-admission, session) | ✅ single convergence point |
| 10–11 dueDate, grace | ✅ memoised, no N+1 |
| 12–14 late fee, cap, persistence | ✅ idempotent, absolute write |
| 15–17 fine collection / override / waiver authorization | ✅ MANAGE + reason + audit |
| 18–22 receipt + identity | ✅ SR No. & Roll shown, Student ID hidden but canonical |
| 23–25 journal, 4060 ledger, bank/cash | ✅ deterministic `JE_FEE_{key}`, ledger validated |
| 26–28 partial / full / duplicate payment | ✅ `feeIdempotency` |
| 29 refund / reversal | ✅ **fine-aware — newly proven** |
| 30 defaulter detection | ✅ **no N+1 — newly proven** |
| 31–38 reminder eligibility → dedupe → paid-before-send | ✅ |
| 39–40 reminder message, auto_remind | ✅ live consumers |
| 41–42 cron, dry-run | ✅ CLI-only |
| 43 historical planner | ✅ read-only, **not runnable here** |
| 44–47 withdrawn, siblings, session, tenant | ✅ |
| 48–49 RBAC, audit | ✅ |
| 50–51 performance, concurrency | ✅ / classified honestly (§14) |

## 9. MONEY CORRECTNESS

Invariant: `balance = net + fine − paid` (floored at 0). Every writer checked:

| Writer | Formula | Verdict |
|---|---|---|
| Late-fee engine | `net + fine − paid` | ✅ |
| `proRateFees` | `newNet + fine − paid` | ✅ (fixed 15.5) |
| Collection allocation | `bal − alloc` (decrements a fine-inclusive balance) | ✅ |
| **Refund / reversal** | `max(0, net + fine − newPaid)` | ✅ **newly proven** |
| `createModuleFee` | `max(0, net − paidSoFar)` — **omits fine** | ⛔ **FROZEN** |
| `reverseAdmissionFees` | `balance = 0` (deliberate reversal) | ✅ |

**`createModuleFee` remains the only formula that can lose money, and it remains unreachable** —
module fees are Transport/Hostel and the engine fines only academic demands, so no fine exists on
those documents. Acceptance test at unfreeze: module fee + posted fine → re-run → assert
`balance == net + fine − paid` and `fine_amount` unchanged.

## 10. RECEIPT / ACCOUNTING — one canonical result

`$fineAmount` is resolved by the G-3 gate **before** both `netTotal` computations, so collection,
receipt and journal consume one value and cannot diverge. Receipt shows SR No. (`receipt.php:458`),
Roll No. (474), fine when > 0 (563); **Student ID not printed** (445–450) while
`feeReceipts.studentId` stays the canonical FK.

**Identity separation proven by crossed fixtures across five suites:** `STU0012 ↔ SRNO 47`,
`STU0047 ↔ SRNO 12`. No digit-derived logic exists anywhere — Student ID ≠ SR No. ≠ Roll No.

## 11. REMINDER TRACE
`auto_remind` · `days_before_due` · `remind_on_due` · `days_after_due` · `reminder_message` — all
have live consumers. Dedupe is structural (`{schoolId}_feedue_{studentId}_{demandId}_{stage}`), so
five cron runs produce **one** notification. Paid-before-send is dropped by a re-read that **fails
closed**. Siblings and tenants cannot collide. Withdrawn/archived excluded.
WhatsApp/SMS/email: `status='not_sent'`, `delivered:false`, honest message, button labelled
"record only" — **no fake delivery** (fixed 15.8).

## 12. SECURITY / RBAC
Collection COUNTER · discount MANAGE + server cap · **fine override MANAGE + reason + audit** ·
settings FINANCE · crons **CLI-only**. All server-side, all fail closed.
**POST-manipulation escalation re-tested:** a COUNTER user submitting `fineAmount=0` against a
computed fine has the value **ignored** (computed fine wins); requesting an override without MANAGE
is **refused 403 before any write**. No escalation path found.

## 13. PERFORMANCE / N+1

| Path | Verdict |
|---|---|
| Demand generation | ✅ memoised due-day (1 read/run) |
| Late-fee cron | ✅ 1 settings + 1 query |
| Reminder cron | ✅ 1 + 1 + R re-reads (R = notifications, not students) |
| Payment re-check | ✅ bounded, deliberate |
| Receipt generation | ✅ atomic counter |
| **Defaulter report** | ✅ **bulk queries — newly proven** |
| Fee search | ✅ cached snapshot |
| Sibling handling | ✅ no per-sibling read in Fees |
| **`syncAllDefaulters`** | ⛔ **N+1 (RTDB, legacy, unreachable)** — F-1 |

## 14. CONCURRENCY — classified honestly

| Operation | Class |
|---|---|
| Receipt numbering | **ATOMIC** (`feeCounters` create-if-not-exists) |
| Push enqueue | **ATOMIC** (deterministic docId) |
| Journal creation | **ATOMIC** (deterministic `JE_FEE_{key}`) |
| Duplicate payment | **ATOMIC** (`feeIdempotency`) |
| Fine application | **IDEMPOTENT, NOT ATOMIC** — concurrent runs may both write the *same* absolute value; harmless |
| Reminder dispatch | CF-owned |
| Section capacity / roll | **DETECTION-GRADE** (pre-existing, unchanged) |

**Nothing is claimed atomic that is not.**

## 15. HISTORICAL DATA (D7)
**NEEDS PRODUCTION AUTHORIZATION + credential.** The planner exists and is read-only; **no
service-account credential is present on this machine**, so I cannot run it and **will not estimate
counts I cannot measure**. Reconstruction is `periodKey + getDueDay()`, canonical `YYYY-MM` only;
anything else is reported AMBIGUOUS, never guessed. ⚠ It applies the school's *current* due day — a
mid-year change to that setting is undetectable from data.

## 16. MODULE FEES (D11 / D13)
**FROZEN.** Evidence supports that module fees *can* carry penalties (hostel late fees are
SOURCE-VERIFIED), but transport/hostel are suspendable services and some heads don't apply to some
students. **ZENX RECOMMENDATION: per-module opt-in, default OFF, suspension/cancellation/exemption
aware.** Not implementable without opening the frozen surface.

## 17. EXTERNAL PROVIDER (D14)
**BLOCKED.** Push genuinely dispatches; the other three channels are honestly reported as not sent.
**Recommendation: stay push-only; do not build a speculative provider abstraction** — pick one
concrete provider first if you decide to buy.

## 18. TEST RESULTS

```
BASELINE:      798 tests, 2507 assertions, 10 failures
AFTER:         896 tests, 2693 assertions, 10 failures
NEW FAILURES:  0
NEW TESTS:     0 this phase (98 added across 15.5–15.8)
LINT:          clean (no files changed this phase)
RTDB SCAN:     live Fees path CLEAN; legacy migration sync uses RTDB (F-1)
```
**Not "all tests pass"** — the 10 pre-existing baseline failures remain and are not mine
(Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2).

## 19. UAT MATRIX (31 rows)

| # | Test | Status |
|---|---|---|
| 1–8 | demand · due date · grace · fine · cap · collection · override · audit | UNIT VERIFIED · **UAT NOT EXECUTED** |
| 9–12 | receipt SRNO · Roll · Student ID hidden · accounting | STATIC VERIFIED · **UAT NOT EXECUTED** |
| 13–14 | partial · full payment | UNIT VERIFIED · **UAT NOT EXECUTED** |
| 15–20 | reminders before/on/overdue · paid-before-send · duplicate · sibling | UNIT VERIFIED · **UAT NOT EXECUTED** |
| 21–22 | settings · custom message | STATIC VERIFIED · **UAT NOT EXECUTED** |
| 23 | cron dry-run | STATIC VERIFIED · **UAT NOT EXECUTED** |
| 24 | historical planner | STATIC VERIFIED · **NOT VERIFIED** (no credential) |
| 25–27 | promotion · transfer · re-admission | UNIT VERIFIED · **UAT NOT EXECUTED** |
| 28 | identity invariants | UNIT VERIFIED |
| 29 | Class/Section/Roll regression | UNIT VERIFIED · **UAT NOT EXECUTED** |
| 30 | CLI security | STATIC VERIFIED · **UAT NOT EXECUTED** |
| 31 | false-delivery prevention | UNIT VERIFIED · **UAT NOT EXECUTED** |

**No browser or device UAT has been executed at any point in this programme.**

## 20. FINAL DECISION TABLE

| Finding | Sev | Current state | Evidence | Fix now? | Why / why not |
|---|---|---|---|---|---|
| Refund preserves fine | — | Correct | `Fee_refund_service.php:535` | **B** | Verified correct |
| Defaulter report N+1 | — | Correct | bulk queries | **B** | Verified correct |
| Concessions placeholder | — | Honest + gated | server re-check | **B** | Correct pattern |
| Live Fees path RTDB-free | — | Clean | scan | **B** | Verified |
| Fine override escalation | — | Closed | G-3 gate | **B** | Re-tested |
| **`firestore_bulk_sync` false success + RTDB N+1** | 🟡 MED | Reachable by URL, no UI | `:4913/:4946`, `Fee_firestore_sync:704` | **NO** | **Cross-subsystem; belongs to RTDB-elimination; partial fix would be worse** |
| `createModuleFee` fine erasure | 🟡 MED | Unreachable | `Fee_lifecycle:1043` | **F** | Frozen surface |
| Historical dateless demands | 🟡 MED | Planner ready | — | **D** | Production authorization + credential |
| Module-fee penalties | 🟡 MED | Off | hostel SOURCE-VERIFIED | **C** | Business decision |
| SMS/WhatsApp/email delivery | 🟡 MED | Honest, not sent | — | **E** | External provider |
| Per-day penalties | ⚪ | Absent | idempotency contract | **C/G** | Unsafe without cap |
| Installments | ⚪ | Absent | greenfield | **G** | Own phase |
| Capacity/roll atomicity | 🟡 MED | Detection-grade | pre-existing | **G** | One shared primitive |
| AI placement / auto-roll / global roll uniqueness | — | Absent | unevidenced | **H** | Do not build |

**Category A (fix now) is empty** — everything safely fixable within Fees scope was already closed in
15.5–15.8.

## 21. REMAINING BUSINESS DECISIONS
**D13** module-fee penalties (recommend opt-in, default OFF) · **D2** per-day penalties (recommend no)
· **D15 (new)** `firestore_bulk_sync`: retire / neutralise / defer to the RTDB phase ·
**D3/D4/D5** Class/Section/Roll policy · **D9** installments.

## 22. REMAINING PRODUCTION AUTHORIZATIONS
**D7** historical due-date backfill — *smallest ask: run `plan_due_date_backfill` on one
school/session and share the output.*

## 23. FINAL FEES READINESS VERDICT

**Fees is closed. Proceed to Class/Section/Roll.**

The engineering chain is complete and now independently re-verified rather than assumed. This gate
specifically targeted the untraced corners — refunds, defaulters, legacy sync — and the money-critical
ones came back **correct**. The one new finding is legacy migration tooling that no school
administrator can reach, and it belongs to a different programme.

Nothing in the remaining list blocks the next phase: one command run, one purchase decision, two
policy calls, and one frozen-surface item that is unreachable today.

**The standing caveat, unchanged: no browser or device UAT has been executed.** Before this reaches a
real school, run the 31-row matrix — especially fine override, the receipt, and the paid-before-
reminder race.

## 24. RECOMMENDATION FOR THE NEXT PHASE

Open Class/Section/Roll closure with the four items on regression-watch since 15.1:
1. **Import capacity preview** (🟢 LOW, zero extra reads — reuses the existing validator)
2. **Full-section override + audit trail** (🟡 MED — **design first**, needs D3: who may override)
3. **Bulk roll-assignment screen** (🟡 MED — the one SOURCE-VERIFIED roll gap, per VirtueSkool)
4. **Duplicate-roll survey** (needs D5 authorization; read-only)

Carry forward as non-negotiable invariants: Student ID canonical · SRNO never derived · roll scoped
to School+Session+Class+Section · **no global roll uniqueness** · no silent renumbering · no AI
placement.

---

## WHAT THIS MEANS FOR A REAL SCHOOL

**Fees is done, and this last pass was about proving it rather than believing it.**

I went back specifically to the parts nobody had checked properly. The most important was refunds —
if you refund a family who had a late fee, does the penalty get handled correctly? **Yes.** The maths
there was already right. Same for the defaulter list: it loads in a couple of queries, not one per
student, so it won't crawl as your school grows.

**I did find one more thing, and this time I chose not to touch it.** There's an old maintenance tool
left over from when ZenX stored data in a different database. It says "sync completed" while actually
doing nothing, because the old database is gone. It matters far less than the WhatsApp button we
fixed, for one simple reason: **there is no button for it.** No one in your office can reach it — it
only exists at a web address a developer would have to type. And it touches homework and accounting
as well as fees, so fixing only the fees part would leave it half-broken, which is worse than leaving
it alone. It belongs with the wider cleanup of that old database.

**So where does that leave you?** Fees raises correctly, charges penalties fairly under your own
rules, collects the right amount, prints an honest receipt, books it to the right ledger, and tells
parents once — never twice, and never if they've just paid. Nothing on the fee screens now claims to
do something it doesn't.

**Four things still sit with you, none of them urgent:** run one report so we can see how many old
fee records need repairing; decide whether you want real WhatsApp or SMS (that's a purchase); decide
whether transport and hostel fees should carry late fees; and confirm what to do with that old
maintenance tool.

**And the one thing I keep flagging: none of this has been tested in a browser or on a phone.** The
code is right as far as tests and inspection can prove, but somebody needs to sit down and actually
click through it before a real school depends on it.

**Fees is closed. Ready for Class, Section and Roll Number.**
