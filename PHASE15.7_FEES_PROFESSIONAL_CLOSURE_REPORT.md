# Phase 15.7 — Fees Professional Closure: Automatic Reminders + Historical Due-Date Closure

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED. UAT NOT EXECUTED.**
No production data read or written. No production backfill. `firestore.rules` /
`firestore.indexes.json` **NOT modified**. No RTDB introduced (statically verified).

---

## 1. EXECUTIVE SUMMARY

**The headline is a correction to my own Phase 15.6 conclusion.**

Phase 15.6 declared automatic reminders an *architectural boundary* — claiming `emit_push()` was
"tied to the web-controller session/claims context" and that reaching it from CLI would be
"materially new architecture". **Re-tracing the source in this phase proved that wrong.**
`emit_push()`'s body depends on nothing but (a) an initialised `Firestore_service` and (b) a schoolId
**string**. Session and claims are merely how the *web* context happens to populate that string —
the enqueue itself never touches them. The extraction was **mechanical, not architectural**, and it
is done.

| # | Delivered | Severity closed |
|---|---|---|
| 1 | **Shared `Push_enqueue_service`** — one enqueue path for web + CLI; `emit_push` delegates to it | 🔴 HIGH (unblocked reminders without a second sender) |
| 2 | **Automatic fee reminders** — before-due / on-due / overdue stages, real dispatch via `FEE_DUE` | 🔴 HIGH |
| 3 | **Duplicate prevention + payment-state re-check** | 🔴 HIGH (prevents dunning families who paid) |
| 4 | **`auto_remind` re-enabled honestly** — it now has a real consumer | 🔴 HIGH |
| 5 | **Historical due-date backfill DRY-RUN planner** — exact proposal, zero writes | 🟡 MEDIUM |
| 6 | **`reminder_message` dead setting revived** (found in the Part-6 sweep) | 🟡 MEDIUM |

**Tests: 886 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +27 new tests.**
All touched files lint clean; RTDB scan clean; rules/indexes untouched.

**Part-6 sweep result: the Fees module now has ZERO dead settings.** Every key persisted by
`Fee_management::save_reminder_settings` has a verified server-side consumer.

---

## 2. RESEARCH METHODOLOGY
External product/vendor documentation → ZenX source trace → gap matrix → severity → implement only
where justified. Marketing claims were not treated as technical evidence.

## 3–4. EXTERNAL SOURCES AND CLASSIFICATION

| Claim | Label | Source |
|---|---|---|
| Reminders sent per due date with **configured follow-up rules**; channels vary by integration | **INDUSTRY PRACTICE** | [Nerithonx](https://nerithonx.com/blog/fee-management-software/), [Skodefy](https://www.skodefy.com/how-school-management-software-helps-in-managing-fees-and-payments) |
| **Check payment status before sending**; maintain notification history — the stated key to preventing duplicate reminders | **SOURCE-VERIFIED** | [Stampli](https://www.stampli.com/resources/notifications-and-alerts-in-accounts-payable/), Nerithonx |
| Real-time payment reflection reduces duplicate payments and follow-up calls | INDUSTRY PRACTICE | [Prayag Edu](https://prayagedu.com/online_fees), Skodefy |
| Reminders ~3 days before due on a daily trigger; **payers scrubbed from the queue** | SOURCE-VERIFIED | [n8n](https://n8n.io/workflows/7039-send-school-fee-reminders-with-payment-links-via-email-and-whatsapp/), [SchoolDeck](https://databus.co/schooldeck/features/auto-due-reminders/) |
| Escalating overdue reminders past a configurable threshold | SOURCE-VERIFIED | SchoolDeck |
| Fee regulation is **STATE** jurisdiction in India; statutes differ | SOURCE-VERIFIED | [Rajya Sabha clarification](https://www.freepressjournal.in/education/who-regulates-private-school-fees-centre-clarifies-in-rajya-sabha-that-states-and-uts-have-jurisdiction) |
| **Exact reminder timings** as a standard | **NOT ESTABLISHED BY SOURCES** | — |
| Retry semantics for failed fee notifications | **NOT ESTABLISHED BY SOURCES** | — |
| Whether late fees should apply to transport/hostel/module fees | **NOT ESTABLISHED BY SOURCES** | — |

**Consequence honoured:** no timing was invented. ZenX reads the school's own
`days_before_due` (which already existed, unconsumed), plus new `remind_on_due` and
`days_after_due`. **Every stage is tenant configuration, not a hard-coded default.**

## 5. EXISTING ZENX NOTIFICATION ARCHITECTURE — precise answers

| Question | Answer (ZENX-VERIFIED) |
|---|---|
| Where is a notification created? | `pushRequests/{schoolId}_{dedupeKey}`, `status:'pending'` |
| Who owns enqueue? | Was `MY_Controller::emit_push()` (protected). **Now `Push_enqueue_service`**, used by both |
| Who owns dispatch? | `functions/index.js` MARK_REGISTRY — **the only dispatcher, unchanged** |
| Who owns retry? | The Cloud Function. **Not reimplemented.** |
| How is duplication prevented? | **Deterministic doc id** — repeat enqueues coalesce onto one document |
| Tenant isolation? | `schoolId` prefix in the doc id + `Firestore_service` scoping |
| Student identity? | `FEE_DUE` declares `idField: 'studentId'` — the canonical technical FK |
| Can CLI safely call the enqueue path? | **Yes** — proven this phase; it needs only `$fs` + schoolId string |

⚠ **Trap carried forward:** `emit_push`'s own comment warns that marks owned by the legacy poller
(`_auto_process_push_requests`: teacher / teacher_leave_* / homework_*) **double-send** if enqueued
this way. **`FEE_DUE` is not in that set** — dispatcher-owned only — so fee reminders are safe. This
is documented in `Push_enqueue_service` so the next author cannot miss it.

## 6. FEES LIFECYCLE MAP (post-15.7)

```
demand creation → dueDate (15.5) → grace (15.6) → overdue → fine (15.6, idempotent)
  → fine persisted → override: MANAGE + reason + audit (15.5)
  → collection: computed fine canonical (15.5) → receipt (SR No./Roll/fine)
  → accounting: Cr 4060 Late Fee
  → reminder eligibility (15.7) → payment-state RE-CHECK (15.7)
  → enqueue via shared service (15.7) → dedupe by doc id → CF dispatcher → Parent App
  → reversal/refund ......... ⛔ evidence boundary
  → historical dateless ..... ⛔ authorization boundary (dry-run planner built)
  → createModuleFee ......... ⛔ frozen boundary
```

## 7–8. FINDINGS + SEVERITY MATRIX

| Feature | Industry evidence | ZenX before | Gap | Sev | Action |
|---|---|---|---|---|---|
| Reminder timing stages | INDUSTRY PRACTICE | none consumed | Real | 🔴 HIGH | **BUILT (configurable)** |
| Duplicate prevention | SOURCE-VERIFIED | doc-id existed, unused for fees | Real | 🔴 HIGH | **BUILT** |
| **Payment-state re-check** | **SOURCE-VERIFIED** | none | Real | 🔴 HIGH | **BUILT** |
| Reminder history | SOURCE-VERIFIED | none | Partial | 🟡 MED | `pushRequests` doc **is** the record; structured logs added |
| Failed-send handling | NOT ESTABLISHED | CF-owned | — | 🟢 | Counted + logged; retry left to the CF |
| Channels (SMS/WhatsApp/email) | INDUSTRY PRACTICE | queue only, no dispatcher | Real | 🟡 MED | **NOT BUILT** — external service boundary |
| Partial payments | — | — | none | ✅ | Reminded (balance > 0) |
| Fully paid / archived | SOURCE-VERIFIED | — | none | ✅ | Never reminded |
| Grace/fine interaction | — | — | none | ✅ | Independent by design |
| Historical demands | — | audit only | Real | 🟡 MED | **DRY-RUN PLANNER BUILT** |
| Session/tenant isolation | — | — | none | ✅ | Structural |
| `reminder_message` | — | **dead** | Real | 🟡 MED | **FIXED** |

## 9–10. BUGS DISCOVERED AND FIXED

**B-1 — My own Phase 15.6 boundary claim was wrong. 🔴 HIGH · FIXED.** Detailed in §1. The
correction changed the outcome of the phase: what was documented as blocked is now delivered.

**B-2 — `reminder_message` was a second dead setting. 🟡 MEDIUM · ZENX-VERIFIED · FIXED.**
Found in the Part-6 sweep. `Fee_management` persisted a custom reminder message and **no server-side
code read it** — the same defect class as `auto_remind`. `Fee_reminder_service::settings()` now
consumes it and passes it as the notification `body`. Sweep re-run afterwards: **every fee setting
now has a consumer.**

**B-3 — Unchecked toggles would silently keep their old value. 🟡 MEDIUM · FIXED.**
`FormData` omits unchecked checkboxes, so the new `remind_on_due` toggle could never be turned OFF —
the save would preserve the previous `true`. Added the explicit `'0'` set, matching the existing
pattern used for `auto_remind` and `late_fee_enabled`. Caught while wiring the UI, fixed in-phase.

## 11–12. BUGS DELIBERATELY NOT FIXED — with exact reasons

**⛔ BOUNDARY 1 — `createModuleFee()` fine erasure. 🟡 MEDIUM. Re-verified this phase; freeze stands.**
*Exact location:* `Fee_lifecycle.php:1043`, inside `createModuleFee()` (lines 987–1112).
*Exact defect:* `$balance = max(0.0, round($net - $paidSoFar, 2))` omits `fine_amount`.
*Currently harmless:* module fees are Transport/Hostel; the late-fee engine only fines academic
demands, so no fine exists on these documents. **No current financial path is silently wrong.**
*Blocking reason:* inside the frozen Fees Exemption v2 surface;
`fees_exemption_v2_flags.php` states Phase 3 converges service generation off this function.
*Acceptance test at unfreeze:* create a module fee → post a fine → re-run `createModuleFee` → assert
`balance == net + fine − paid` **and** `fine_amount` unchanged.
*Should module fees be penalised at all?* **NOT ESTABLISHED BY SOURCES.** My recommendation is
**per-module opt-in, defaulting OFF** — transport and hostel are services a family can suspend or
cancel, and penalising a suspended service would be wrong. This is a **business decision (D13)**, not
an engineering default, and I have not pre-empted it.

**⛔ BOUNDARY 2 — Production backfill of dateless demands. 🟡 MEDIUM.**
A **dry-run planner** is built (`planDueDateBackfill()` + `plan_due_date_backfill` command) which
emits, per demand: school, session, student, demand id, periodKey, status, current value and
**proposed** dueDate — and separately lists AMBIGUOUS rows it refuses to guess.
*Reconstruction rule (deterministic):* `dueDate = periodKey + getDueDay()` — byte-identical to what
the live generator would produce today. Qualifies **only** if periodKey matches `YYYY-MM`.
⚠ **Stated limitation, not hidden:** reconstruction applies the school's **current** due day. If a
school changed that setting mid-year, a rebuilt date may differ from what was operationally true.
ZenX stores no history of the setting, so this is undetectable from data — which is exactly why this
is a proposal for human approval, not an automatic repair.
*There is deliberately no `--commit` flag and no write path in the class.* Requires **D7**.

**⛔ BOUNDARY 3 — SMS / WhatsApp / email reminder channels.** Queue exists; no dispatcher in-repo.
Requires an **external service** decision (provider, credentials, cost, DLT compliance). Push via the
existing Cloud Function is delivered; other channels are not, and I did not fake them.

**⛔ BOUNDARY 4 — Fine waiver-after-collection and fine-specific refund reversal.**
**NOT ESTABLISHED BY SOURCES**, and unreachable until fines exist in production. No accounting model
invented. Collection-time override is already authorized + audited (15.5).

**Excluded as instructed:** per-day penalties (would break the `abs(existing−computed) < 0.01`
idempotency contract that makes nightly runs safe) · ID-card replacement (§16) · installments
(greenfield) · **all Class/Section/Roll implementation** (regression-watch only).

## 13. AUTOMATIC REMINDER ARCHITECTURE

```
SCHOOL_ID=… SESSION_YEAR=… php index.php fees_late_fee_cron reminders --dry-run
SCHOOL_ID=… SESSION_YEAR=… php index.php fees_late_fee_cron reminders
```
Folded into the **existing** fee cron, not a second scheduler — the precedent
`Transport_expiry_cron` set when it absorbed driver-assignment expiry and compliance re-evaluation.

Against the Part-10 checklist: due-date eligibility ✅ · upcoming ✅ · overdue ✅ · **payment re-check
immediately before enqueue ✅** · duplicate prevention ✅ (deterministic doc id) · tenant isolation ✅
(env var + doc-id prefix) · session isolation ✅ (query predicate) · student identity ✅ (`studentId`)
· sibling correctness ✅ (key includes studentId) · idempotent enqueue ✅ · retry-safe ✅ (coalescing) ·
audit ✅ (`pushRequests` doc + `FEE_REMINDER_DONE` logs) · failure logging ✅ · never notifies paid ✅ ·
never notifies archived/dateless/zero-balance ✅.

**Only one stage can match per demand per day**, so a family cannot be reminded twice in a day.

## 14. HISTORICAL DUE-DATE ANALYSIS
See Boundary 2. **Exact counts require running the read-only command against a real tenant, which I
have not done** — no production data was touched. The tooling reports scanned / reconstructable /
ambiguous and the full proposal.

## 15. MODULE-FEE ANALYSIS
See Boundary 1. Recommendation: converge on the single late-fee engine post-unfreeze, **per-module
opt-in defaulting OFF**, with suspension/withdrawal/cancellation and entitlement exemptions honoured
before any module fee is ever penalised.

## 16. ID-CARD REPLACEMENT — audited, not implemented
ZenX ID cards carry **signed campus-access credentials** (`Attendance.php:4259-4263`: *"reissue this
student's ID card to mint a signed token"*). **A replacement charge without invalidating the old
credential is not a professional solution** — the lost card keeps opening the gate.
Required chain: request → reason (lost / damaged / stolen / aged) → authorization → charge → payment
→ receipt → **invalidate old credential** → mint new version → audit.
Reason-code practice is SOURCE-VERIFIED for **US higher-ed only** (charge lost; damaged free on
surrender; stolen waived with a police report; aged free) and **NOT ESTABLISHED for Indian K-12**.
Blocked: the natural charge mechanism (`createModuleFee`) is on the frozen surface, and the
credential-invalidation half is a security workflow. **Not implemented; design recorded.**

## 17. RBAC ANALYSIS

| Action | Gate | Server-side |
|---|---|---|
| `save_reminder_settings` (incl. new stages) | FINANCE + `Fees:edit` | ✅ |
| `auto_compute_fines` | MANAGE + `Fees:edit` | ✅ |
| `submit_fees` | COUNTER + `Fees:edit` | ✅ |
| Fine override | MANAGE + reason + audit | ✅ |
| **Reminder cron / late-fee cron** | **CLI-only** (`is_cli_request()`), unreachable over HTTP | ✅ |

No privilege escalation introduced. New inputs range-validated server-side (after-days 1–365).
Everything fails **closed** — unreadable policy sends nothing; failed re-read sends nothing.

## 18. TENANT / SESSION ISOLATION
Tenant: one school per cron invocation (env var) + `schoolWhere` + `{schoolId}_` doc-id prefix —
**two tenants cannot collide even on an identical dedupe key** (test asserts this).
Session: `['session','==',$session]` on every query.

## 19–21. FIRESTORE BUDGET · N+1 · CONCURRENCY

| Operation | Reads | Writes |
|---|---|---|
| Reminder run | 1 settings + 1 session query + **R re-reads** | R enqueues |
| Late-fee run | 1 settings + 1 query | W (0 on re-run) |
| Backfill plan | 1 query | **0** |
| `emit_push` (web) | unchanged | unchanged |

**R = candidates that passed eligibility, NOT the student count** — 0 on a quiet night. The per-
candidate re-read is a deliberate correctness cost: it is the only way to avoid dunning a family who
paid between the scan and the send, which the sources identify as the key failure mode. It is bounded
by notifications sent, so it cannot degrade into a per-student N+1.
**Concurrency/idempotency:** deterministic doc ids make both crons safe to run concurrently or repeatedly.
**No new index, no new query predicate, no rules change.**

## 22. PARENT APP IMPACT
**No Parent App change required or made.** `FEE_DUE` was already registered with
`idField:'studentId'` and `dataKeys:['amount','dueDate']`; the payload supplies exactly those. The
app's existing notification handling and sibling switching key off `studentId`, which is what is
sent. **Audited, not modified** — per instruction, since no real issue was proven.

## 23. RECEIPT / ACCOUNTING VERIFICATION (re-verified, unchanged)
`views/fees/receipt.php`: SR No. (458), Roll No. (474), fine when > 0 (563), **Student ID not
printed** (445-450) while `feeReceipts.studentId` remains canonical.
**collection == receipt == accounting** holds — all three derive from the single `$fineAmount`
resolved by the G-3 gate before both `netTotal` computations; fine credits **Cr 4060 Late Fee**.

## 24. CLASS / SECTION / ROLL REGRESSION
**No lifecycle code was touched this phase.** `Sis.php` was not modified in 15.7 at all. Capacity
(School+Session+Class+Section, Active-only, `maxStrength<=0` unlimited) and Roll validation
(session/class/section scoped, no global uniqueness, alphanumeric-safe, atomic Suggest) unchanged.
Student ID remains the sole FK. **Crossed fixtures (`STU0012↔47`, `STU0047↔12`) asserted in all
three new suites**, plus an explicit test that the notification payload carries `studentId` and
**neither `srNo` nor `rollNo`**.

## 25. TESTS

```
Baseline:    798 tests, 10 failures
After 15.5:  830  (+32)
After 15.6:  859  (+29)
After 15.7:  886  (+27)     NEW FAILURES: 0
```
Pre-existing failures unchanged (**not mine**): Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass"** — the baseline's 10 remain, by design.

New coverage: eligibility (on-due, 7/3/1 before, overdue stages, unconfigured days silent, on-due
switchable) · never-notify (paid, zero-balance, archived, dateless, partial IS notified) · duplicate
prevention (identical key, five runs → one doc, stages/demands/siblings/tenants distinct) ·
**payment-before-send race** · re-check fails closed · session isolation · backfill (canonical-only
proposal, reconstructable vs ambiguous split, read-only shape) · identity invariants.

**TEST VERIFIED:** eligibility, dedupe, race handling, backfill classification.
**STATIC VERIFIED:** `php -l` all files · RTDB scan clean · rules/indexes untouched · dead-setting sweep.
**UAT NOT EXECUTED.**

## 26. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | New admission | Demand with dueDate | PENDING |
| 2 | Fee becomes overdue | Detected | PENDING |
| 3 | Late-fee cron dry-run | Reports, writes nothing | PENDING |
| 4 | Late-fee cron live | Fine set once | PENDING |
| 5 | **Same-day re-run** | **0 writes** | PENDING |
| 6 | Grace period | Inside window → no fine | PENDING |
| 7 | Maximum cap | Fine bounded | PENDING |
| 8 | Fine collection | Computed fine auto-applied | PENDING |
| 9 | Unauthorized override | **403 refused** | PENDING |
| 10 | Authorized override | Applied + audited | PENDING |
| 11 | Receipt | SR No., Roll, fine; no Student ID | PENDING |
| 12 | Accounting | Cr 4060 Late Fee | PENDING |
| 13 | Parent App | Notification received, correct student | PENDING |
| 14 | **Reminder cron dry-run** | Lists recipients, sends nothing | PENDING |
| 15 | **Reminder cron live** | One FEE_DUE per stage | PENDING |
| 16 | **Duplicate prevention** | Re-run → no second push | PENDING |
| 17 | **Pay-before-reminder race** | Paid family NOT notified | PENDING |
| 18 | Reminder settings | Toggle + stages persist | PENDING |
| 19 | Historical audit | Counts reported, nothing modified | PENDING |
| 20 | Historical dry-run plan | Proposal printed, nothing written | PENDING |
| 21 | Module fee regression | Transport/Hostel unchanged | PENDING |
| 22 | Promotion regression | Roll collision caught | PENDING |
| 23 | Transfer regression | Capacity + roll enforced | PENDING |
| 24 | Re-admission regression | SRNO relinked, new Student ID | PENDING |
| 25 | SRNO lookup | Resolves to Student ID | PENDING |
| 26 | Class/Section/Roll | Unchanged | PENDING |
| 27 | CLI-only guard | HTTP → 403 | PENDING |

## 27. REMAINING DECISIONS

| ID | Decision |
|---|---|
| **D7** | Authorize the historical due-date backfill after reviewing the dry-run |
| **D11** | `createModuleFee` fine fix rides the entitlements cutover |
| **D13 (new)** | **Should module fees (Transport/Hostel) attract late fees at all?** My recommendation: per-module opt-in, default OFF |
| **D14 (new)** | SMS / WhatsApp / email reminder channels — external provider decision |
| D2 | Per-day penalties (only with a mandatory cap) |
| D3/D4/D5 | Class/Section/Roll: override authority, promotion roll policy, duplicate survey |
| D9 | Installments as their own phase |

## 28. RECOMMENDED NEXT PHASE
**Fees is now professionally closed for the paths a school actually operates.** The next phase should
be the deferred **Class/Section/Roll closure** (import capacity preview, full-section override +
audit design, bulk roll-assignment screen, duplicate-roll survey), which has been on regression-watch
since 15.1 — unless you would rather first run the dry-runs and authorize the backfill (D7).

## 29. GIT STATUS

```
Branch: integration/phase2      HEAD: 758c924    (unchanged — no commit)

Modified by me this phase:
  application/core/MY_Controller.php            (emit_push → shared service)
  application/controllers/Fee_management.php    (after-due + on-due settings)
  application/controllers/Fees_late_fee_cron.php(reminders + backfill-plan commands)
  application/libraries/Fee_late_fee_service.php(backfill planner)
  application/views/fee_management/reminders.php(re-enabled toggle + stages)
New by me this phase:
  application/libraries/Push_enqueue_service.php
  application/libraries/Fee_reminder_service.php
  tests/Unit/Phase157RemindersAndBackfillTest.php

Carried uncommitted from 15.5/15.6: Fee_lifecycle.php · Firestore_service.php ·
  FeeCollectionService.php · Sis.php · Fees.php · Fee_late_fee_service.php ·
  Fees_late_fee_cron.php · Phase155/Phase156 tests

firebase-rules/firestore.rules ......... dirty BEFORE these phases — NOT touched by me
firebase-rules/firestore.indexes.json .. dirty BEFORE these phases — NOT touched by me
~440 other working-tree entries ........ pre-existing work — untouched

COMMIT: none   PUSH: none   MERGE/REBASE: none   DEPLOY: none
```

## 30. WHAT THIS MEANS FOR A REAL SCHOOL

**Parents now get reminded about fees, automatically.**

Until this phase, the reminder switch in your settings did nothing at all — and in the last phase I
told you connecting it would need a significant rebuild. **I was wrong about that, and I checked
again rather than take my own word for it.** The piece I thought was locked to the website turned out
to need nothing more than a school id and a database connection, both of which a scheduled job
already has. So it's done.

**How it behaves:** you choose when parents hear from you — a few days before the due date, on the
day, and again if it goes unpaid. Those are your numbers, not ours. We deliberately didn't pick them,
because school fee rules in India are set by state governments and genuinely differ.

**Two things were built specifically so parents don't get annoyed.** First, a parent is never
reminded twice for the same thing — even if the nightly job runs five times, or the server restarts
mid-way. Second, and more important: right before each message goes out, the system checks that
family's payment one more time. If they paid an hour ago, the reminder is cancelled. Chasing someone
who has already paid is the fastest way to lose a parent's trust in the system, so that check is
worth the small extra cost.

**We also found a second switch that was doing nothing.** You could type a custom reminder message
and save it, and no part of the system ever read it. It's now used as the actual message parents
receive. After fixing it we re-checked every fee setting on that screen — **all of them now do
something real.** That was the specific class of problem worth hunting: a control that looks like it
works and doesn't.

**What still isn't there:** reminders currently reach parents through the app only. Text messages,
WhatsApp and email need an outside provider chosen and paid for — that's your decision, not a
technical gap we can quietly fill.

**And the older fees still need your call.** Fees created before this work have no due date, so
they'll never be flagged late or trigger a reminder. We've built a report that shows exactly which
ones can be repaired safely, exactly what date each would get, and which ones we refuse to guess at.
It only prints the plan — it changes nothing. Repairing real financial records is your decision, and
we haven't touched a single one.

**Nothing has been committed, pushed or deployed.**
