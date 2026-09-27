# Phase 15.6 — Fees Module Professional Closure

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED. NOT PUSHED. NOT MERGED. NOT DEPLOYED. UAT NOT EXECUTED.**
No production data read or written. `firestore.rules` / `firestore.indexes.json` **NOT modified**.
No RTDB introduced (statically verified). No unrelated working-tree change touched.

---

## 1. EXECUTIVE SUMMARY

Phase 15.5 made the fee *numbers* correct. **Phase 15.6 makes them operate** — late fees now apply
automatically, at school scale, under a policy each tenant controls.

| # | Delivered | Severity closed |
|---|---|---|
| 1 | **One canonical late-fee engine** — extracted to `Fee_late_fee_service`, used by both the endpoint and automation | 🔴 HIGH (prevented a second fee engine) |
| 2 | **Nightly automatic late-fee cron**, idempotent, tenant-isolated, dry-run capable | 🔴 HIGH |
| 3 | **Grace period + maximum cap**, per-school/per-session, configurable end-to-end | 🟡 MEDIUM |
| 4 | **Read-only historical due-date audit** — measures backfill scope without touching data | 🟡 MEDIUM |

**Tests: 859 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +29 new tests.**
All touched files pass `php -l`.

**Deliberately NOT built, with reasons in §10:** automatic reminders (real architecture boundary),
per-day penalties (unsafe under the current idempotency contract), `createModuleFee` fix (frozen
surface), ID-card replacement (frozen + security), installments (greenfield), production backfill
(needs authorization).

**One earlier claim corrected in §4.**

---

## 2. RESEARCH METHODOLOGY

External product/documentation research → ZenX source trace → gap matrix → severity → implement only
where justified. Vendor marketing was **not** treated as proof; every external claim carries a label.

## 3. EXTERNAL SOURCES AND EVIDENCE CLASSIFICATION

| Claim | Label | Source |
|---|---|---|
| Late fees applied automatically from predefined rules; fines auto-added to student accounts; system keeps a record of fine transactions | **INDUSTRY PRACTICE** | [schoolerp.co.in](https://schoolerp.co.in/fees-and-fine-management.html), [EDU ERP](https://eduerp.ai/fee-management/) |
| Admins configure late-fee rules **without custom programming** | SOURCE-VERIFIED | [EduTrak](https://edutrak.com/best-automated-tuition-billing-system-for-small-schools-in-2025-2026/) |
| Scheduled/cron agents run the automation and report what was actioned | SOURCE-VERIFIED | [ShopClawMart](https://www.shopclawmart.com/blog/automate-tuition-payment-reminders-late-fees) |
| Config axes: flat vs per-day, cap, grace, current-month vs total-pending basis | SOURCE-VERIFIED | [Campus24x7](https://campus24x7.in/blogs/school-fee-structure-erp-setup-guide-india), [Knwdle](https://knwdle.com/tools/late-fee-calculator) |
| Fine **exemptions and escalation** are standard | INDUSTRY PRACTICE | schoolerp.co.in |
| **Indian school fee regulation is STATE jurisdiction**, statutes differ (Delhi 2025; Maharashtra; Gujarat Rule 7(7)) | **SOURCE-VERIFIED** | [Rajya Sabha clarification](https://www.freepressjournal.in/education/who-regulates-private-school-fees-centre-clarifies-in-rajya-sabha-that-states-and-uts-have-jurisdiction), [Delhi Act](https://foxandmandal.co.in/the-delhi-school-education-(transparency-in-fixation-and-regulation-of-fees)-act,-2025) |
| A single **national K-12** grace period / penalty cap | **NOT ESTABLISHED BY SOURCES** | — |
| Idempotency/concurrency design of any commercial late-fee engine | **NOT ESTABLISHED BY SOURCES** | vendors document features, not internals |
| Reminders ~3 days pre-due on a daily trigger; payers scrubbed from the queue | SOURCE-VERIFIED | [n8n](https://n8n.io/workflows/7039-send-school-fee-reminders-with-payment-links-via-email-and-whatsapp/), [SchoolDeck](https://databus.co/schooldeck/features/auto-due-reminders/) |

**Direct consequence:** because the governing rule is state-specific and ZenX is multi-tenant,
**grace and cap were implemented as per-school configuration, never constants.** No legal policy was
invented; no default was chosen that changes existing behaviour.

## 4. ⚠ CORRECTION TO AN EARLIER REPORT

**Phase 15.4/15.5 said "no fee scheduler exists" and treated `FeeWorker` as the pattern to copy.**
Re-verified: `FeeWorker` polls `feeJobs` for **payment allocation** after a cashier submits — a
queue/claim/reap worker. It is **not** a time-driven scheduler and **cannot** host a nightly sweep;
it has no trigger of its own.

**The correct precedent is `Transport_expiry_cron`** — `CI_Controller`, CLI-only, **one tenant per
invocation** via `SCHOOL_ID` + `SESSION_YEAR` env vars. **This changed the implementation:** the new
cron follows that shape, which gives tenant isolation *structurally* (the process only ever knows one
school) rather than by defensive filtering. **ZENX-VERIFIED.**

## 5. FEES LIFECYCLE MAP (post-15.6)

```
admission / promotion / re-admission / import / CRM
  → buildAdmissionDemandSpecs ......... dueDate stamped        (15.5) ✅
  → Fee_late_fee_service::policy() .... grace + cap, memoised  (15.6) ✅
  → cutoffDate = today − graceDays .... overdue detection      (15.6) ✅
  → fineFor() ......................... skips paid/archived/no-due/zero-net ✅
  → applyTo() ......................... ABSOLUTE write + equality guard ✅ idempotent
      ├── interactive: Fees::auto_compute_fines (one student)
      └── automated:   Fees_late_fee_cron (whole school, 1 query)   (15.6) ✅
  → proRateFees ....................... fine PRESERVED         (15.5) ✅
  → FeeCollectionService G-3 gate ..... computed fine canonical (15.5) ✅
  → receipt ........................... SR No./Roll/fine shown, Student ID hidden ✅
  → Operations_accounting ............. Cr 4060 Late Fee ✅
  → reminders ......................... ⛔ BOUNDARY (§10)
```

## 6–7. FINDINGS + SEVERITY MATRIX

| Feature | ZenX before | ERP practice | Evidence | Gap | Sev | Action |
|---|---|---|---|---|---|---|
| Automatic late fee | Manual, 1 student/POST | Automatic, scheduled | INDUSTRY PRACTICE | **Real** | 🔴 HIGH | **BUILT** |
| Single fee engine | Private controller method | — | ZENX-VERIFIED | Would force a 2nd engine | 🔴 HIGH | **BUILT** |
| Grace period | None | Configurable | SOURCE-VERIFIED | Real | 🟡 MED | **BUILT** |
| Max cap | None | Configurable | SOURCE-VERIFIED | Real | 🟡 MED | **BUILT** |
| Dry run | None | "report what was actioned" | SOURCE-VERIFIED | Real | 🟡 MED | **BUILT** |
| Historical dateless demands | Invisible | — | ZENX-VERIFIED | Real | 🟡 MED | **AUDIT BUILT** (read-only) |
| Per-day penalty | None | Offered by some | SOURCE-VERIFIED | Real but **unsafe now** | ⚪ | **EXCLUDED** §10 |
| Automatic reminders | Disabled (15.5) | Standard | SOURCE-VERIFIED | Real | 🔴 HIGH | **BOUNDARY** §10 |
| Fine exemption/escalation | None | INDUSTRY PRACTICE | INDUSTRY PRACTICE | Real | ⚪ | Not justified yet |
| `createModuleFee` fine erasure | Present | — | ZENX-VERIFIED | Real | 🟡 MED | **FROZEN** §10 |
| Installments | Absent | Common | ZENX-VERIFIED | Greenfield | ⚪ | §18 |

## 8–9. BUGS DISCOVERED AND FIXED THIS PHASE

**B-1 — The late-fee rules were unreachable from automation. 🔴 HIGH · ZENX-VERIFIED · FIXED.**
`compute_fines_for_student()` was `private` on the `Fees` web controller. Any automation would have
required a **second copy of the money rules** — two engines free to drift. Extracted to
`Fee_late_fee_service`; the controller now delegates. **The duplicated legacy body was deleted, not
left dead**, so there is exactly one implementation. The SW4-companion-F archived-demand rationale
was carried across verbatim in intent, because that guard prevents a zeroed archived balance from
being resurrected into pending dues.

**B-2 — A school-wide run would have been an N+1. 🟡 MEDIUM · FIXED BEFORE SHIPPING.**
The obvious implementation (`foreach student → computeForStudent()`) costs **one Firestore query per
student** — ~2,000 for a mid-size school. `computeForSchool()` instead issues **one session-scoped
demands query** and filters in memory. Cost: **1 settings read + 1 query + W writes**, where W = the
demands whose fine actually changed (**0 on re-run**).

**B-3 — Policy would have been unconfigurable. 🟡 MEDIUM · FIXED.**
Adding grace/cap to the engine alone would have created the mirror image of the H-1 defect: settings
the code reads but no school can set. Persistence (`Fee_management::save_reminder_settings`, with
validation) **and** UI fields were added in the same phase.

## 10. NOT FIXED — with exact reasons

**⛔ BOUNDARY 1 — Automatic reminders. 🔴 HIGH.**
*What was found:* `FEE_DUE` is fully registered (`functions/index.js:123`) and never emitted. The only
sanctioned enqueue is `MY_Controller::emit_push()` — **`protected`, on the web-controller spine**
(line 2129), and it depends on `$this->school_id` from session/claims. A CLI cron extends
`CI_Controller` and **cannot reach it**.
*Why it cannot safely be completed now:* the only two options are (a) refactor `MY_Controller` — the
spine every controller inherits — or (b) write a second `pushRequests` enqueue, which is precisely
the "second sender" CLAUDE.md forbids. Both are **materially new architecture**, not a connected bug
fix.
*Exact decision required:* authorize extracting the `emit_push` enqueue into a shared library that
both `MY_Controller` and CLI jobs call, keeping the Cloud Function as the only dispatcher.
*What remains safe:* the misleading control stays honestly disabled (15.5); the **manual**
`Fee_management::send_reminder` still works.

**⛔ BOUNDARY 2 — `createModuleFee()` fine erasure. 🟡 MEDIUM.**
*Exact location:* `Fee_lifecycle.php:1043`, inside `createModuleFee()` (lines 987–1112).
*Exact defect:* `$balance = max(0.0, round($net - $paidSoFar, 2))` — omits `fine_amount`, identical in
shape to the F-1 defect fixed in 15.5.
*Why currently harmless:* module fees are Transport/Hostel; fines are only computed on academic
demands, so no fine exists on these documents today. **It becomes dangerous the moment module fees
join the late-fee model.**
*Blocking reason:* the function is inside the **frozen Fees Exemption v2 surface** —
`fees_exemption_v2_flags.php` states Phase 3 converges service generation off `createModuleFee`.
Re-verified this phase: **the freeze still stands.**
*Acceptance test required at unfreeze:* create a module fee with a fine posted → re-run
`createModuleFee` → assert `balance == net + fine − paid` and that `fine_amount` is unchanged.
*Should module fees share the late-fee model?* **ZENX RECOMMENDATION: yes, eventually** — one engine,
one policy — but only after the entitlements cutover, and only with a per-module opt-in, since
Transport/Hostel are services a family can suspend and penalising a suspended service would be wrong.

**⛔ BOUNDARY 3 — Production backfill of dateless demands. 🟡 MEDIUM.**
Detection is built and **read-only** (`audit_due_dates`, `auditMissingDueDates()`); it classifies each
dateless demand as **reconstructable** (has a canonical `YYYY-MM` periodKey → the live generator's own
input) or **ambiguous** (must never be guessed). **No write path exists and none was written.**
Requires explicit authorization (**D7**).

**Excluded, with reasons:** per-day penalties (the `abs(existing−computed) < 0.01` guard is what makes
repeat runs safe; a daily-changing fine rewrites the balance every run and grows unbounded without a
mandatory cap — reversal semantics **NOT ESTABLISHED BY SOURCES**) · ID-card replacement (frozen +
security: cards carry signed campus-access tokens, `Attendance.php:4259`) · installments (greenfield,
§18) · fine waiver/reversal accounting (§17) · all Class/Section/Roll implementation (regression-watch
only, per instruction).

## 11. AUTOMATIC LATE-FEE ARCHITECTURE

`Fees_late_fee_cron` — CLI-only, one tenant per invocation:
```
SCHOOL_ID=SCH_123456 SESSION_YEAR=2026-27 php index.php fees_late_fee_cron run --dry-run
SCHOOL_ID=SCH_123456 SESSION_YEAR=2026-27 php index.php fees_late_fee_cron run
SCHOOL_ID=SCH_123456 SESSION_YEAR=2026-27 php index.php fees_late_fee_cron audit_due_dates
```
Properties: idempotent · never double-charges · tenant-isolated by construction · session-aware ·
skips paid/archived/dateless/zero-net · honours grace + cap · **fails closed** if policy is unreadable
· bounded reads · dry-run · structured `FEE_LATEFEE_DONE` / `FEE_LATEFEE_SKIP` logging · exit codes
0/1/2. A disabled policy exits **0** with a SKIP line so schedulers don't alert nightly.

## 12. REMINDER ARCHITECTURE
Designed, not built — see Boundary 1. When authorized: due-date evaluation → reminder stage →
**payment-state re-check immediately before send** (the SOURCE-VERIFIED scrub rule) → dedupe via
`emit_push`'s deterministic `docId(dedupeKey)` (duplicate prevention already exists) → single
dispatcher → history.

## 13. FINE POLICY ARCHITECTURE
`late_fee_enabled` · `late_fee_type` (fixed|percentage) · `late_fee_value` · **`late_fee_grace_days`
(0–365, default 0)** · **`late_fee_max_cap` (default 0 = uncapped)**. Both new keys default to
pre-15.6 behaviour exactly, so an unconfigured school observes **no change**. Per-school and
per-session because the governing law is state-specific.

## 14. HISTORICAL DEMAND / BACKFILL ANALYSIS
Reconstruction is deterministic **only** where a canonical `periodKey` exists —
`dueDate = periodKey + getDueDay()`, the same derivation the live generator uses. Ambiguous rows are
counted and sampled, never guessed. Rollback for any future backfill is trivial (the field was absent
before). **Not executed.**

## 15. MODULE-FEE ARCHITECTURE
See Boundary 2. Recommendation: converge on the single engine post-unfreeze, with per-module opt-in.

## 16. ID-CARD REPLACEMENT
Unchanged from 15.4 and still blocked. Design of record: request → reason code (lost / damaged /
stolen / aged) → authorization → charge → payment → receipt → **invalidate the old signed credential**
→ mint new version → audit. Reason codes are SOURCE-VERIFIED but **US higher-ed only**; **NOT
ESTABLISHED** for Indian K-12. The security requirement is the load-bearing part: a replacement must
never leave the old access token valid.

## 17. FINE WAIVER / REVERSAL / ACCOUNTING
Collection is correct: fine → `netTotal` → receipt → **Cr 4060 Late Fee** (`Operations_accounting.php:1403`),
deterministic `journal_entry_id`. Authorized override + reason + audit landed in 15.5.
**Waiver-after-collection and fine-specific refund reversal remain NOT ESTABLISHED BY SOURCES** and
are unreachable until fines exist in production. **No accounting model was invented.**

## 18. INSTALLMENTS
**ZENX-VERIFIED: ZenX has no installment system.** The only repo match for `installment` is
`PayrollAccountingService.php` (staff payroll). Monthly demand generation is *not* an installment
plan — it lacks per-installment schedules and independent due dates. **Greenfield; own phase.**
Gujarat Rule 7(7) (no more than one quarter's fees collected at a time) constrains that design.

## 19. RECEIPT VERIFICATION
`views/fees/receipt.php`: SR No. (458), Roll No. (474), fine line rendered when > 0 (563), Student ID
**deliberately not printed** (445–450) while `feeReceipts.studentId` remains canonical.
**receipt == collection == accounting** holds because all three derive from the single `$fineAmount`
resolved by the G-3 gate *before* both `netTotal` computations.

## 20. PARENT / TEACHER / ADMIN IMPACT
**Parent App: no change required or made** — demand docs are already downloaded whole, so `dueDate`
and `fine_amount` cost zero extra reads. **Teacher App: no fee surface, untouched.**
**Admin:** new grace/cap fields; unchanged collection flow.

## 21. STUDENT LIFECYCLE REGRESSION (watch only — no lifecycle code changed)
`Sis.php` was touched **only** by 15.5's cache-bust. This phase changed **no** admission, import, CRM,
promotion, transfer, re-admission or withdrawal code. Capacity (School+Session+Class+Section,
Active-only, `maxStrength<=0` unlimited) and Roll validation (session/class/section scoped, no global
uniqueness, alphanumeric-safe, atomic Suggest) are **unchanged and intact**. Student ID remains the
sole FK; SRNO is never persisted as a key and never derived — enforced by crossed fixtures
(`STU0012↔47`, `STU0047↔12`) in both new suites.

## 22–23. FIRESTORE COST / N+1

| Operation | Reads | Writes | RTT |
|---|---|---|---|
| Policy resolution | **1, memoised** | 0 | 1 |
| School-wide run | **1 query** | W (0 on re-run) | 1 + W |
| Per-student endpoint | 1 + 1 | ≤ demands | unchanged |
| `audit_due_dates` | **1 query** | **0** | 1 |

**No new index, no new query predicate, no rules change.** The `session ==` predicate on a
school-scoped query matches the existing `demandsForStudent` shape.

## 24. SECURITY / RBAC
`auto_compute_fines` MANAGE + `Fees:edit` · `save_reminder_settings` FINANCE + `Fees:edit` ·
`submit_fees` COUNTER + `Fees:edit` · **fine override MANAGE + reason + audit** (15.5) — all
server-side, none UI-hidden, all fail closed. **The cron is CLI-only** (`is_cli_request()` guard in
the constructor) and **cannot be reached over HTTP**; tenant scope comes from env vars, so it can
never touch another school. New settings are range-validated server-side (grace 0–365, cap ≥ 0).

## 25. TESTS

```
Baseline:      798 tests, 10 failures
After 15.5:    830 tests, 10 failures   (+32)
After 15.6:    859 tests, 10 failures   (+29)     NEW FAILURES: 0
```
Pre-existing failures unchanged (**not mine**): Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2.

New coverage: overdue detection (due today = not late, no due date = never fined, paid/archived/
partial/zero-net) · grace (0 reproduces old behaviour, shifts cutoff, boundary exclusive) · fine types
and cap (percentage, cap binds, cap 0 = uncapped) · **idempotency** (second run writes nothing, flat
fine never accumulates over 10 runs, policy change rewrites absolutely, lowered cap reduces) · batch
(300-demand pass, dry-run) · balance semantics · historical detection (reconstructable vs ambiguous) ·
identity invariants.

**TEST VERIFIED:** engine rules, idempotency, policy, batch semantics.
**STATIC VERIFIED:** `php -l` all files; RTDB grep clean; rules/indexes untouched.
**UAT NOT EXECUTED.**

## 26. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| A | New admission | Demand with `dueDate` | PENDING |
| B | Fee demand | Listed in ledger | PENDING |
| C | Due date | `YYYY-MM-<dueDay>` | PENDING |
| D | Late fine (cron, dry-run) | Reports, writes nothing | PENDING |
| E | Late fine (cron, live) | `fine_amount` set once | PENDING |
| F | **Cron re-run same day** | **0 writes** | PENDING |
| G | Grace period | Inside window → no fine | PENDING |
| H | Cap | Fine bounded | PENDING |
| I | Fine preservation | Survives proration | PENDING |
| J | Fine collection | Computed fine auto-applied | PENDING |
| K | Unauthorized waiver | **403 refused** | PENDING |
| L | Authorized waiver | Applied + audited | PENDING |
| M | Receipt | SR No., Roll, fine; no Student ID | PENDING |
| N | Accounting | **Cr 4060 Late Fee** | PENDING |
| O | Parent App | Correct amounts | PENDING |
| P | Reminder setting | Disabled + honest hint | PENDING |
| Q | Promotion | Roll collision caught immediately | PENDING |
| R | Transfer | Capacity + roll enforced | PENDING |
| S | Re-admission | SRNO relinked, new StudentID | PENDING |
| T | Bulk import | Capacity projected, dueDate stamped | PENDING |
| U | `audit_due_dates` | Counts reported, **nothing modified** | PENDING |
| V | Transport/module fee | Unaffected | PENDING |
| W | SRNO lookup | Resolves to Student ID | PENDING |
| X | Section capacity | Enforced | PENDING |
| Y | CLI-only guard | HTTP access → 403 | PENDING |

## 27–28. REMAINING ISSUES + BUSINESS DECISIONS

| Issue | Sev | Source | Safe now? | Next action |
|---|---|---|---|---|
| Automatic reminders | 🔴 HIGH | `MY_Controller::emit_push:2129` | **No** — architecture | **D12**: authorize emit_push extraction |
| `createModuleFee` fine erasure | 🟡 MED | `Fee_lifecycle.php:1043` | **No** — frozen | **D11**: ride entitlements cutover |
| Dateless historical demands | 🟡 MED | audit built | **No** — prod data | **D7**: authorize backfill after audit |
| Per-day penalty | ⚪ | — | No | **D2**: only with mandatory cap |
| Fine waiver/reversal accounting | ⚪ | — | No | Evidence needed |
| Installments | ⚪ | absent | n/a | **D9**: own phase |
| ID-card replacement | 🟡 MED | frozen + security | No | Post-unfreeze |
| Class/Section/Roll closure | 🟡 MED | 15.1–15.4 | Yes | **Next phase** (D3/D4/D5) |

## 29. EXPLICIT EXCLUSIONS
AI placement · AI class/section assignment · any balancing algorithm · silent auto roll assignment ·
global roll uniqueness · Class/Section redesign · waitlist · atomic seat reservation · bulk roll
screen · Exam Roll Number · production migration · rules/indexes changes.

## 30. GIT STATUS

```
Branch: integration/phase2      HEAD: 758c924   (unchanged — no commit)

Modified by me this phase:
  application/controllers/Fees.php              (engine → service delegation)
  application/controllers/Fee_management.php    (grace/cap persistence + validation)
  application/views/fee_management/reminders.php(grace/cap UI)
New by me this phase:
  application/libraries/Fee_late_fee_service.php
  application/controllers/Fees_late_fee_cron.php
  tests/Unit/Phase156LateFeeEngineTest.php
Carried from 15.5 (uncommitted):
  Fee_lifecycle.php · Firestore_service.php · FeeCollectionService.php · Sis.php
  tests/Unit/Phase155FeesCorrectnessChainTest.php

firebase-rules/firestore.rules ......... dirty BEFORE this phase — NOT touched by me
firebase-rules/firestore.indexes.json .. dirty BEFORE this phase — NOT touched by me
Other ~440 working-tree entries ........ pre-existing work from earlier phases — untouched
(Fee_concessions.php, Fee_generation_service.php, Fee_entitlement_reader.php are
 PRE-EXISTING entitlements-work files. NOT modified by me.)

COMMIT: none   PUSH: none   MERGE/REBASE: none   DEPLOY: none
```

## 31. FINAL VERDICT

**Can a real school now operate ZenX Fees professionally? For charging and collecting — yes. For
notifying parents — not yet, and I have not pretended otherwise.**

Fees carry due dates, overdue detection runs automatically for a whole school on one query, the
penalty is bounded by the school's own grace period and ceiling, running it twice charges nothing
extra, the computed penalty is what gets collected and printed, and it lands in its own ledger. An
operator can preview a night's charges with `--dry-run` before a single family is billed.

**Three honest gaps remain:** parents are still not notified automatically (real architectural
boundary, §10); fee demands created before Phase 15.5 remain exempt until a backfill is authorized;
and one identical defect sits behind the frozen entitlements wall, harmless today, documented with
its acceptance test.

## 32. WHAT THIS MEANS FOR A REAL SCHOOL

**Before this phase, late fees existed on paper only.** Someone would have had to open each student's
record and click a button — two thousand times. Nobody does that, so in practice no school using ZenX
was ever charging late fees.

**Now it runs by itself, once a night, for the whole school.** And it's built so that running it twice
— or ten times — never charges a family twice. That was the single most important thing to get right,
because a billing job that double-charges is worse than one that doesn't run.

**Your school decides the rules, not us.** You set how many days' grace parents get after the due
date, and the most a late fee can ever reach. We deliberately did not pick those numbers for you:
school fee rules in India are set by **state** governments and genuinely differ between Delhi,
Maharashtra and Gujarat, so any number we hard-coded would be wrong for somebody. If you don't set
them, nothing changes from how it behaved before.

**You can look before you charge.** There's a preview mode that reports exactly which families would
be charged and how much, without touching anything.

**What still doesn't work:** parents are not automatically reminded about upcoming or overdue fees.
The notification machinery exists, but the piece that sends it can only be reached from the website,
not from a scheduled overnight job. Connecting the two means changing shared plumbing that every part
of the system sits on — too risky to do quietly as part of a fees change. It needs your go-ahead as
its own piece of work. Until then the reminder switch stays honestly greyed out, and the manual
"send reminder" button still works.

**One more thing worth knowing:** fees created before this work began still have no due date, so they
can never be flagged late. We built a read-only report that counts exactly how many there are and
which can be repaired automatically — but it only counts. Changing real financial records is your
decision, not ours, and we haven't touched any.

**Nothing has been committed, pushed or deployed.**
