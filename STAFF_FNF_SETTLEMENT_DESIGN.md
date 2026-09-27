# Full & Final Settlement — lifecycle design

**DESIGN ONLY. No implementation is authorized. No financial formula is proposed
here — every money rule is deferred to D-002 and listed in §6.**

Grounded in a read of the shipped lifecycle (2026-08-21). F&F is genuinely
greenfield: a repo-wide search for `fnf | full and final | settlement | gratuity |
encash` returns **zero implementation**. Everything else in the lifecycle already
exists and must be consumed, not rebuilt.

---

## 1. What already exists

| Concern | Collection | State vocabulary |
|---|---|---|
| Resignation + notice | `staffNoticePeriods` | `pending → approved / waived / reduced / rejected → completed`, plus `withdrawn` |
| Exit clearance | `staffClearances` | per-dept `pending / cleared / flagged`; completes only when **all** `cleared` |
| Onboarding | `staffOnboarding` | per-task `pending / done / skipped`; completes on `done` **or** `skipped` |
| Employment record | `staffEmploymentTimeline` / `staffEmploymentEvents` | effective-dated attribute history |
| Separation record | `staffSeparations` | linked record written when staff becomes SEPARATED |
| Pay | `salarySlips` (polymorphic `RUN_` / `SLIP_` / `SAL_`) | run-level `Draft` etc. |
| Leave | `leaveTypes`, balance docs (`type == 'balance'`, fields `used` / `carried`) | — |

`staffNoticePeriods` already carries the fields F&F needs as input:
`noticeStartDate`, `noticeDurationDays`, `expectedLastWorkingDay`,
`actualLastWorkingDay`, `decision`, `separationCompleted`.

**Design consequence:** F&F introduces exactly one new document and reads
everything else. It must not duplicate notice, clearance, or timeline state.

---

## 2. State machine

One document, `staffSettlements`, one `status` field.

```
   (none) --initiate--> initiated
                            |
                       compute
                            v
                        calculated <-------------+
                            |                    |
                     submitForReview             | reject
                            v                    |
                         inReview ---------------+
                            |
                        approve
                            v
                         approved
                            |
                      recordPayment
                            v
                          paid
                            |
                          close
                            v
                         closed        (terminal)

   cancel: allowed from initiated / calculated / inReview / approved
           --> cancelled (terminal)
           REFUSED from paid / closed
```

### Transition table

| From | Event | To | Guard |
|---|---|---|---|
| — | `initiate` | `initiated` | separation exists **and** notice is `approved`/`waived`/`reduced`/`completed`; no open settlement for this staff |
| `initiated` | `compute` | `calculated` | all §3 inputs resolvable; clearance complete **or** override recorded |
| `calculated` | `compute` | `calculated` | recompute is idempotent; supersedes the prior figure |
| `calculated` | `submitForReview` | `inReview` | a computed figure exists |
| `inReview` | `approve` | `approved` | approver ≠ initiator ≠ subject (§5) |
| `inReview` | `reject` | `calculated` | rejection note required |
| `approved` | `recordPayment` | `paid` | payment reference required |
| `paid` | `close` | `closed` | accounting entry posted |
| `initiated` / `calculated` / `inReview` / `approved` | `cancel` | `cancelled` | reason required |
| `paid` / `closed` | `cancel` | **refused** | money has moved — reverse, don't cancel |

### Rules the machine must enforce

- **`paid` and `closed` are terminal.** Once money has moved, correction is a new
  compensating settlement, never a mutation of this one. This mirrors the
  accounting module's existing posture.
- **Approval is not idempotent.** Re-approving an `approved` settlement is
  refused, exactly as `complete_notice_period` refuses a second completion.
- **Cancellation before payment only**, and it is terminal — a cancelled
  settlement is superseded by a new one, not reopened. *(Reopen-after-cancel is
  deliberately excluded; see §6 Q9.)*
- **One open settlement per staff member per separation.** The `initiate` guard
  is the uniqueness point.
- **Recompute is allowed until `inReview`** and must be idempotent, because
  clearance flags and final attendance land late.

---

## 3. Objects and inputs

### New — `staffSettlements`

Identity and lifecycle only. Every amount is a **stored output** of a computation
whose rule is D-002, never a rule embedded in this document.

```
schoolId, session, staffId, staffName            tenant + subject
separationId, noticePeriodId, clearanceId        links, not copies
status, statusHistory[]                          the machine above
initiatedBy/At, computedAt, reviewedBy/At,
  approvedBy/At, paidAt, closedAt,
  cancelledBy/At/Reason
lastWorkingDay                                   resolved, not re-derived (§4)
computation: {
  inputsSnapshot: {...}                          frozen at compute time
  components: [ {code, label, direction, amount, basis} ]
  gross, deductions, net
  policyVersion                                  which D-002 answers produced this
}
paymentRef, accountingEntryId
```

Two properties matter most:

- **`inputsSnapshot` is frozen at compute time.** A settlement computed in
  March must not silently change when an April payroll run edits an attendance
  mark. Without this, `approved` means nothing.
- **`policyVersion` records which D-002 answers produced the figure.** Policy will
  change; historical settlements must remain explainable.

### Read-only inputs

| Input | Source | Note |
|---|---|---|
| Last working day | `staffNoticePeriods.actualLastWorkingDay ?? expectedLastWorkingDay` | authority; **not** re-derived |
| Notice served vs shortfall | `staffNoticePeriods` (`decision`, duration) | `waived` / `reduced` change the shortfall |
| Unpaid salary | payroll engine, final partial month | **reuse the shipped engine** (§4) |
| Leave balance | balance docs (`used` / `carried`) | encashable subset is policy |
| Attendance / LOP | attendance + leave, final period | already shared with payroll |
| Salary structure | `salarySlips` `SAL_{staffId}` | effective-dated |
| Tenure | `staffEmploymentTimeline` | gratuity eligibility input |
| Recoveries | advances, assets, `flagged` clearance items | policy: which are auto-deducted |

---

## 3a. F&F executes as an OFF-CYCLE PAYROLL RUN

*Added 2026-08-21, from the governing spec.* This supersedes the looser "F&F
calls the payroll engine" wording below.

`docs/specs/hr-payroll-brief.md` line 369 lists off-cycle runs as "**F&F**, bonus
payout, incentive payout, arrears-only", and the Asanify coverage matrix sequences
capability 70 (F&F) behind capability 50 (off-cycle runs): *"Not present — needs
off-cycle run (50)"*.

So F&F is **not** a parallel money system, and not even a caller of the engine in
its own right. It is a **run type**. `staffSettlements` remains the lifecycle,
approval and audit record; the money is produced by a payroll run whose scope is
one employee and one partial period.

### What already supports this

Verified by reading the engine, not assumed:

- **`runId` is opaque.** `$runId = $this->_next_id('PR', 'PayrollRun')` — a
  sequential counter, *not* derived from month/year. Multiple runs can therefore
  coexist for the same period without an identity collision.
- **The slip is already run-scoped**: `SLIP_{runId}_{staffId}`, and
  `_buildPayslipDoc()` stamps `runId` on every slip. An off-cycle slip is
  naturally distinguishable from the monthly one.
- **One calculation engine.** `_compute_payslip()` is called by both existing
  paths and is pure. An off-cycle run reuses it unchanged — which is what keeps
  F&F from drifting on half-days, LOP and Calendar Half-Day.
- **Regeneration, archival and audit** already exist per slip and apply as-is.

### The one thing that blocks it

`generate_payroll()` refuses a second run for a period:

```php
$fsExRuns = $this->fs->schoolWhere('salarySlips', [
    ['type', '==', 'run'], ['month', '==', $month], ['year', '==', $year],
]);
if (!empty($fsExRuns)) { … "A payroll run already exists for {$month} {$year}" }
```

This is correct for monthly runs — two monthly runs for March would double-pay
the school — but it also blocks the F&F run for a leaver in March.

**Minimal change:** give the run a `runType` (`monthly` | `offcycle`, defaulting
to `monthly` so every existing run and this guard are unchanged), and scope the
uniqueness check to `runType == 'monthly'`. Off-cycle runs are constrained
instead by their own rule — at most one open settlement per staff member, which
`Fnf_state_machine::isOpen()` already enforces.

Nothing else in the run model needs to change. That is the whole architectural
delta, and it is deliberately small: the engine was already general enough.

### Boundary of responsibility

```
staffSettlements                    payroll run (offcycle)
─────────────────                   ──────────────────────
lifecycle + guards          →       compute earned wages for the partial period
approval / separation of duties     apply statutory config
inputsSnapshot (frozen)     ←       return the computed figures
policyVersion                       write SLIP_{runId}_{staffId}
accounting entry id         ←       post to accounting as any run does
```

The settlement never computes money itself. It records **which** run produced the
figures and freezes the inputs that run consumed, so an approved settlement stays
explainable after the underlying data moves on.

### Consequence for sequencing

Off-cycle runs (capability 50) are a **hard prerequisite** for the F&F
calculation. Everything in §2 and §5 — the state machine, guards, RBAC, audit,
concurrency, visibility — is independent of it and can ship first, which is why
`Fnf_state_machine` exists already and carries no money.

---

## 4. Dependencies and their hazards

1. **Payroll — must reuse, must not fork.** The final partial month is an
   existing solved problem: `_staff_working_days()`, the ratio-denominator model,
   paise-exact arithmetic. A second implementation would drift from payroll on
   half-days, LOP and the Calendar Half-Day rule. **F&F executes as an off-cycle
   run over that engine (§3a) — it does not reimplement or even wrap it.**
2. **Attendance / Leave** — the same shared code path, so the Calendar Half-Day
   rules apply unchanged. F&F must not re-interpret a mark.
3. **Leave balance** — the balance writer's field-merge semantics matter: a
   settlement must read a balance, never recompute one.
4. **Notice period** — `lastWorkingDay` has exactly one authority. F&F **reads**
   it. Re-deriving it from a resignation date is how the two surfaces drift.
5. **Department / effective dating — known trap.** `DepartmentCalendarDivergenceTest`
   records that payroll resolves department once at `$periodStart` while leave
   resolves per day. A settlement spanning a mid-month transfer inherits that
   divergence. Latent today (0 timeline rows), but F&F must not add a **third**
   resolution strategy.
6. **Accounting — nothing new is needed, and nothing new is permitted.**
   Verified 2026-08-22 by reading `PayrollAccountingService`.

   It routes **exclusively** through the canonical engine
   (`Operations_accounting::create_journal`) and has **no direct write path to
   any accounting collection**. That is deliberate: the service's own docblock
   records that a historical imbalance (`JE_20260414193106_f55be96d`) was
   produced by a legacy HR module doing direct `firestoreSet('accounting', …)`
   writes, bypassing the engine's CAS, period-lock, idempotency and
   balance-batch protections. The service exists to make that
   "structurally impossible".

   So an F&F settlement posts **as any run posts** — through
   `postRunAccrual()` / `postRunPayment()`. Three properties make this work
   without any F&F-specific accounting code:

   - **Journal ids are run-derived**: `JE_HR_PAYROLL_{runId}_{seq}`. An
     off-cycle run therefore gets its own journals with no collision — the
     third place the opaque `runId` (§3a) pays off, after slip ids and the
     run document itself.
   - **Idempotency is already handled**: the service builds deterministic
     `source_ref`s "so idempotency holds across retries". A settlement that
     retries a posting cannot double-post.
   - **`postReversal($originalEntryId, $reason)` exists.** The compensating
     correction model — `paid`/`closed` terminal, corrections never mutating
     history — is already supported at the ledger layer. The design arrived at
     that rule independently; accounting had already implemented it.

   `postRunAdjustment()` covers the arrears/recovery shape the brief describes
   for post-lock corrections.

   **Still a hazard:** the recorded convergence debt — consumers must query
   **both** `accounting` and `accountingLedger`. A settlement reconciliation
   screen that reads only one will under-report.
7. **Employee exit** — staff becoming Inactive is already required before
   `complete_notice_period`. F&F must not be a second writer of staff status.
8. **Documents — consume the frozen service; no change to it is required.**
   Verified 2026-08-22 by reading `application/libraries/Document_service.php`.

   Exit letters (relieving, experience, settlement statement) go through the
   Canonical Document Service, which is a **frozen platform baseline**: modules
   consume it, nobody modifies it without a proven defect and sign-off. The good
   news is that F&F needs no modification at all.

   - `link($documentId, $module, $entityId, $role)` takes `$module` as a **free
     string** — there is no allow-list to extend. F&F links with
     `module = 'fnf'`, `entityId = settlementId`, and a `role` naming the letter
     (`relieving_letter`, `experience_letter`, `settlement_statement`).
   - **Linking is CAS-protected and idempotent** — re-linking an
     already-linked document returns `ok` with the existing `linkCount` rather
     than double-counting. A settlement that retries a document step cannot
     corrupt the refcount.
   - **`unlink()` on cancellation.** The service is link-not-move (ADR-001
     Option C): unlinking decrements the refcount and the underlying blob
     survives if anything else still references it. A cancelled settlement must
     unlink, not delete.
   - **Never write `documents` / `documentLinks` directly.** They are
     server-only in the rules (`allow read, write: if false`) and the service
     owns the refcount; a direct write would corrupt it exactly as a direct
     ledger write corrupted accounting (§4.6). Same class of mistake, same
     answer.

   Today the service has exactly **one** consumer (`Student360`), so F&F would
   be the second — worth knowing, because the integration is less exercised than
   the accounting one.

   **Generating the letter is not architecture.** Producing a PDF is already
   solved: `Pdf_generator::render($html)` returns bytes with no HTTP, and the
   payslip path already builds HTML from a pure builder and renders it (verified
   in `PayslipDocumentRenderTest`). What a relieving letter *says* is template
   and product content, not an engineering decision — and it is the one part of
   this boundary that still needs someone to write it.
9. **Cross-platform** — Teacher/Parent have **no** settlement surface today.
   Adding one is a separate authorized decision, not implied by this design.

---

## 5. Security and isolation (inferable — no policy needed)

- Every read/write scoped by `schoolId` + `session` via `schoolWhere()`.
- Graded RBAC (`has_permission`), **not** legacy role-name gates.
- **Separation of duties, by direct analogy to `decide_leave` and
  `staff_regularization_decide`:** the subject must never initiate, review, or
  approve their own settlement, and — because this is a larger sum than a leave
  day — the approver should differ from the initiator. *(Whether approval needs
  two distinct approvers above a threshold is policy: §6 Q8.)*
- Firestore rules — **two stages**, prepared in `D007_STAFF_SETTLEMENTS_RULES_PATCH.md`:
  - **Stage 1, shipping with the collection: server-only** (`allow read, write:
    if false`). Teacher and Parent have no settlement surface (§4.9), so no
    client needs this collection. Granting an app read "ready for later" would
    expose gross, deductions, net and recovery figures to every staff token for
    a feature nobody can open. This is the posture `documents` / `documentLinks`
    already take, and the panel is unaffected because it uses the Admin SDK.
  - **Stage 2, only if an employee-facing surface is authorized:** an employee
    may read their **own** settlement, and only at a status where the figure is
    final — `approved` / `paid` / `closed` — never `calculated` or `inReview`,
    which are working numbers a reject can still change. Writes stay
    `if false` throughout: unlike a leave request, there is no client-side
    settlement action at any status.

  **This is the D-003 lesson applied at design time rather than retrofitted.**
  D-003 exists because a rule conditioned on ownership alone while the app hid
  drafts client-side — and client-side filtering is not access control.
- Full audit trail via `log_audit` on every transition.

---

## 6. What requires business policy — D-002 decision matrix

Nothing below is inferable from the codebase. Each row states the question, why
it cannot be guessed, and the options — **no recommendation on money.**

| # | Question | Why not inferable | Options |
|---|---|---|---|
| Q1 | **Gratuity eligibility** | Statutory floor (commonly 5 years) but schools often pay earlier; tenure source is `staffEmploymentTimeline` | (a) statutory only (b) statutory + tenant override (c) per-tenant from scratch |
| Q2 | **Gratuity formula** | Depends on which salary component is the base | (a) last drawn basic (b) basic+DA (c) gross (d) tenant-defined |
| Q3 | ~~Leave encashment — which balance~~ | **RESOLVED 2026-08-22 — it was not a policy choice.** See below. | — |
| Q4 | ~~Encashment divisor~~ | **RESOLVED 2026-08-21** — the tenant's existing `days_basis`. See below. | — |
| Q5 | **Notice shortfall recovery basis** | Recovery on gross vs basic differs greatly; `waived` / `reduced` already exist as decisions | (a) basic (b) gross (c) per-day CTC (d) never auto-recover |
| Q6 | **Deduction priority / floor** | When deductions exceed dues, is net clamped to zero or does it become a receivable? | (a) clamp to 0 (b) allow negative → receivable (c) clamp + flag for manual recovery |
| Q7 | **Statutory treatment** | PF/ESI/TDS on settlement components is jurisdictional | (a) exclude all (b) apply existing payroll statutory config (c) per-component flags |
| Q8 | **Approval authority** | Separation of duties is inferable; the **threshold** is not | (a) single approver (b) dual above an amount (c) role-based ladder |
| Q9 | **Cancellation / reopen** | §2 excludes reopen-after-cancel as the safe default; a school may want a correction window | (a) cancel is terminal *(as designed)* (b) reopen allowed before payment (c) reopen with re-approval |
| Q10 | **Clearance override** | May a settlement compute while a department is `flagged`? | (a) never (b) override with reason + audit (c) compute but block approval |

### Q3 and Q4, resolved

**Q3 — which balance is encashable.** The original framing was wrong. It asked
for one answer across all schools (earned only / earned + carried / per type),
but a school that encashes Earned Leave and not Casual Leave is not in *conflict*
with one that encashes neither — they simply have different leave policies, which
`schools.leaveTypes` already models per type.

So encashability is a **per-type flag**, resolved by
`Fnf_encashment_resolver::isEncashable()`, and it **defaults to FALSE**. That
default is not a market norm: encashing leave creates a payment obligation, and
defaulting it on would invent a liability the school never agreed to.

The available-days rule is not invented either — it mirrors the shipped leave
engine exactly, clamp included:

```
available = max(0, allocated + carried - used)
```

The clamp matters. `used` accrues for unpaid types too, so without it an
over-consumed type would report negative days and *reduce* a settlement — paying
someone less because they took unpaid leave.

**Q4 — the divisor.** Encashment uses the tenant's existing `days_basis`
(`docs/specs/hr-payroll-brief.md` §5.4: calendar days / fixed 30 / working days,
per legal entity — note 26 is not among them). Any other divisor would make a day
of leave worth a different amount than a day of salary for the same employee in
the same month. There is nothing to decide; the value is already configured.

The resolver returns **days only**. Converting days to money happens in the
off-cycle run (§3a), which is where `days_basis` already lives — asserted by a
test that the resolver contains no rupee arithmetic at all.

---

**Q1, Q2, Q5–Q7 block the calculation engine. Q8–Q10 block only the workflow
guards** — so the state machine, document model, RBAC, audit trail and tests in
§2–§5 can be built the moment implementation is authorized, without waiting on
the money rules. That separation is the point of this document.

Q2 is additionally constrained: the gratuity base is `statutory_wage_base` under
the labour codes, which does not yet exist in ZenX and is a prerequisite rather
than part of F&F.

---

## 7. Build order once authorized

1. `staffSettlements` document + state machine + guards + audit *(no amounts)*
2. Firestore rules with the D-003-style status-gated read
3. Behavioural tests for every transition and every refusal
4. Input resolvers (read-only; reuse payroll/leave engines)
5. **— D-002 gate —** calculation engine
6. Accounting posting + document-service letters
7. Cross-system: only if a Teacher/Parent surface is separately authorized
