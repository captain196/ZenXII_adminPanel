# Staff Lifecycle — production-readiness gate

**Date:** 2026-08-22 · **Branch:** `integration/phase2` · **Nothing committed since `f1ad3ec`.**

Evidence state at the time of writing:

| Surface | Tests | Result |
|---|---|---|
| Admin (PHP) | **4443** | 10 class-A failures, 5 intentional incompletes |
| Teacher (JVM) | **109** | 0 failures |
| Parent (JVM) | **77** | 0 failures |
| Firestore rules (Jest) | 1290 | **BLOCKED** — needs JDK 21+, machine has 17 |

The 10 failures are the documented class-A baseline: `BackupTest` (a `1 KB` vs
`1.0 KB` format drift) and cross-repo tests that grep Kotlin source via a
hardcoded path from another machine. None is a Staff Lifecycle defect. The 5
incompletes are deliberate and each names an open item.

**Suite memory: 440 MB, down from 506 MB against a 512 MB limit** — headroom
went from 6 MB to 72 MB. The cause was measured before anything was changed:
PHPUnit holds every TestCase instance for the whole run, so a large source
string kept in a property stays resident once per test method (~18 MB per
source-reading class, measured as 116 MB vs 44 MB for comparable pure-logic
classes). Twelve of this programme's own classes now release the reference in
`tearDown()`. **No test was weakened, no coverage deleted, and the memory limit
was not raised.** Roughly 41 classes belonging to other programmes carry the
same pattern and were deliberately not touched.

**A green suite is not a pass.** Every row below states what was actually
exercised, and the standard applied is the one set for this programme: source
existing is not evidence.

---

## The 24 points

### 1. Functional completeness — ⚠️ PARTIAL

The lifecycle runs end to end — recruit → onboard → employ → attend → leave →
pay → appraise → transfer → resign → notice → clear → separate → rehire. Two
genuine gaps remain, both recorded rather than hidden:

- **F&F cannot compute.** Lifecycle, resolvers, rules, accounting and document
  boundaries are complete; the calculation is blocked (§4).
- **Comp-off expiry and cap have no admin UI.** The keys work; a tenant can only
  set them by writing `schools.attendancePolicy` directly.

Off-cycle runs, `statutory_wage_base`, encashment payout and probation/
confirmation remain unbuilt (Asanify matrix rows 50, 67, 70).

### 2. State machines — ✅ PASS

Separation, onboarding, clearance, leave-year closure, F&F settlement and
regularization all behaviourally tested, including every invalid transition.

Two findings, both fixed: the **rehire/notice deadlock** (D-005) left an employee
permanently unable to resign; and the onboarding/clearance completion asymmetry
was verified *correct* and pinned so a well-meaning refactor into one shared
"all resolved" helper cannot let a `flagged` clearance complete.

### 3. Payroll correctness — ⚠️ PARTIAL *(downgraded 2026-08-22)*

Verified against the **real shipped engine** (`tests/Support/PayrollEngine.php`),
not a transcription. Σ earnings == gross across 6 denominators × 6 fraction
patterns; clamping; determinism (generation ≡ regeneration); extra-worked pay
not inflating the PF base.

**Discovered:** every prior money suite *mirrored* the engine's arithmetic, and
the mirror is **off by a paise** on ordinary inputs (₹40,000 / 30 days / 7
absent → mirror 30,666.67, engine 30,666.66). The engine is authoritative — its
gross is the sum of the lines it prints. Recorded in `PayrollMirrorFidelityTest`.

**DOWNGRADED 2026-08-22 — D-PF-1.** The arithmetic is exact; the **statutory
wage base is wrong**. Provident fund is computed on earned **basic alone**, in
both the legacy and the statutory-aware branch, while `da` is a real populated
component that already counts toward gross. Section 6 of the EPF & MP Act 1952
makes the contribution payable on *"the basic wages, dearness allowance and
retaining allowance (if any)"*. This understates both the employee deduction and
the employer's liability, and it predates the 2025 labour codes entirely.

Separately, the labour codes (in force **21 Nov 2025**) require excluded
allowances above 50% of total remuneration to be added back into wages for PF,
gratuity, bonus and leave encashment. `Statutory_wage_base.php` implements that
and is asserted to be wired to nothing — a half-wired statutory base would be
worse than an unwired one.

Latent: **0 salary structures, 0 payslips** in any tenant, so nobody has been
under-contributed and no correction is owed. Remediation is a **one-site**
change — `Statutory_config_service::computePf()` accepts a wage and does not
choose the base itself, so tenant rates and ceilings are unaffected.

Not fixed autonomously: it changes every employee's take-home and the school's
contribution liability. Decision pack item 1, and it needs the tenant's CA.

### 4. F&F correctness — ⛔ BLOCKED (by policy, not engineering)

Complete and tested: state machine (33 tests), tenure / encashment / notice
resolvers, rules patch (D-007), accounting boundary, document boundary.

Blocked on: off-cycle runs (capability 50), `statutory_wage_base` (does not
exist), and D-002 **Q1, Q2, Q5, Q6, Q7** — see §policy below.

### 5. Leave correctness — ✅ PASS

Balance field ownership, carry-forward, year-closure idempotency (five guard
layers, and `carried +=` is non-idempotent *by construction* — safe only because
of them), comp-off ledger, accrual.

**Fixed (D-001):** a half-day comp-off leave spent a whole 1.0 credit and
**silently forfeited the other 0.5**. Partial consumption implemented with an
additive release; no migration needed.

### 6. Attendance correctness — ✅ PASS

All 9 marks have a decided payroll disposition, asserted by reading the writer's
vocabulary out of source so a 10th mark fails the suite. Regularization is
fail-closed: `Lock_cache` **rethrows**, so an unreadable month-lock can never
read as "unlocked".

### 7. Effective dating — ⚠️ PARTIAL

Boundary semantics pinned against the real resolver: half-open
`[effectiveFrom, effectiveTo)`, so **the new department owns the transfer day**.

**Known divergence:** payroll resolves department once at `$periodStart`; leave
and attendance resolve per day. Payroll is the lone outlier. Latent — 0 timeline
rows in any tenant — and recorded as an incomplete.

### 8. Salary revision — ⛔ BLOCKED (D-006 timing)

**Resolved in direction, not scheduled.** Compensation is not effective-dated:
one `SAL_{staffId}` document, overwritten, read live by both engines — so
regenerating March after an April raise prices March at April's salary. The
governing spec forbids exactly this ("changing a policy in October must not
retroactively alter September's payroll"). Latent: 0 structures, 0 payslips, 0
regenerations anywhere.

### 9. Security — ⚠️ PARTIAL *(D-004 applied 2026-08-22; deploy still pending)*

- **D-004** — any staff token could read **every colleague's leave**, including
  the free-text reason. Was the only non-latent finding. **APPLIED to the
  working tree** during a 53-minute quiescent window on `firestore.rules`, after
  confirming the concurrent programme had stopped writing. Single hunk, one
  `match` block. Both apps' query shapes were checked first, so the narrowing
  breaks no client. **Not deployed** — live still serves the broad arm.

  Two traps were caught while applying it, both of which would have shipped
  silently. The collection is **polymorphic**: leave *balance* documents carry
  `staffId` but neither `applicantId` nor `applicantType`, and a missing-field
  access is an error that denies — so every access is now `.get(field,
  default)`, and without that the narrowing would have broken every employee's
  balance screen. And a first draft admitted the student queue ungated, which
  would have let parent and student tokens read every other student's leave —
  closing a staff exposure by opening a student one of the same shape. Both are
  now asserted by tests.

- **D-003** — an employee can read their own **draft** appraisal; the app hides
  drafts client-side only. Latent (0 appraisals). Patch drafted, not applied.

D-003 and D-007 remain blocked on the rules file (§12).
Verified correct: self-approval guards, payroll-bearing leave requiring admin,
attendance pre-stamping blocked, Document Service and settlement collections
server-only.

### 10. RBAC — ✅ PASS

47/47 throughout, and graded RBAC untouched by this programme — the only
RBAC-matching line I changed anywhere is a comment.

### 11. Tenant isolation — ✅ PASS

Every lifecycle collection swept. Five are server-only by design (default-deny,
no app reads them). Every HR index contains `schoolId`. No cross-tenant read
path found.

### 12. Firestore rules — ⛔ BLOCKED (concurrent + credential)

Analysed thoroughly; **never edited**. The file is actively owned by another
programme — I watched it go 958 → 986 → 1049 → 397 insertions mid-session, and
watched `reportingManagerId` disappear and return.

Empirical verification is separately blocked: `firebase-tools` requires **JDK
21+**, this machine has **17**. All rules conclusions therefore rest on source
reading — valid, but not emulator-verified this session.

### 13. Firestore indexes — ✅ PASS

Every app query traced to an index. `appraisals` genuinely *requires* its
composite (an `orderBy` outside the equality filters cannot be index-merged);
the rest are served by prefix. No speculative indexes added.

### 14. Concurrency — ✅ PASS

CAS verified on leave decisions, balance writes (`exists:false` on create closes
the duplicate-creation race), comp-off per-credit claims, year closure, and
document linking. Measured, not assumed: warm/cold produce **identical** answers.

### 15. Idempotency — ✅ PASS

Year closure (idempotent exit before a non-idempotent `carried +=`), comp-off
earn, document linking, accounting `source_ref`s, payroll determinism.

### 16. Financial precision — ✅ PASS

Integer paise throughout, exactly one rounding per component, integer-summed
gross. Verified by executing the real primitives.

### 17. Accounting integration — ✅ PASS

`PayrollAccountingService` routes **exclusively** through the canonical engine
and has **no direct write path** — because a legacy module once caused a real
imbalance that way. F&F needs no accounting code of its own; `postReversal()`
already implements the compensating-correction model.

### 18. Cross-platform contracts — ✅ PASS

All 23 Teacher payslip fields reconciled; day fields `Double` on both sides;
nested earnings sum to the stated gross. **Fixed:** the Teacher app could render
a **negative** leave balance where the server clamps to 0.

Parent verified isolated — its staff-domain models are dead code with no
repository, ViewModel or screen.

### 19. Notifications — ⚠️ PARTIAL *(assessed 2026-08-22)*

Full producer → registry → recipient trace done; `LifecycleNotificationAuditTest`
pins it.

**The entire Staff Lifecycle emits exactly ONE notification** —
`STAFF_LEAVE_DECIDED`, on leave approve/reject. It is correctly wired: the
registry targets `audience: USERS, idField: 'staffId'`, the producer supplies
`staffId`, and the dedupe key includes the decision so a rejection cannot
suppress a later approval. `emit_push()` **fails closed** without a tenant and
stamps `school_id`, so recipient resolution is tenant-safe.

**One genuine gap — a dead consumer.** `SALARY_PROCESSED` is a fully specified
registry row (`audience: USERS`, `idField: 'staffId'`, `type:
'salary_processed'`) with **zero producers**. Payroll runs, payslips are
written, and no employee is ever told. The design decision was made and
recorded; only the emit is missing. **Not wired here** — it would start pushing
to real staff devices, and the trigger point (finalize? publish?) is a product
choice.

**Everything else is silent**: resignation, notice decisions, leave submission,
leave cancellation, regularization, salary revision, appraisal, onboarding,
clearance, exit, rehire. Whether those should notify is product scope, so the
silence is recorded rather than filled — and asserted, so adding one fails the
test and forces this audit to be revisited.

**Correction to an earlier reading:** `HOMEWORK_CREATED` looked like a dead
producer. It is not — the non-dispatch is deliberate and documented, because the
Teacher app writes those documents itself and dispatching them server-side would
"DOUBLE-SEND and over-broadcast". Pinned so nobody fixes it into a double-send.

### 20. Documents / PDF / XLSX — ✅ PASS

Real round trips, not "a file was produced": XLSX written and **parsed back**
(money as numbers not text, paise intact, totals reconciling); payslip HTML
parsed field by field; a genuine Dompdf render validated. Escaping proven
behaviourally.

### 21. Performance / N+1 — ✅ PASS

**Measured**, not grepped: 50 queries cold vs **1** warm for a 50-staff roster,
identical answers. Two real N+1s fixed — the nightly finaliser and the
user-facing attendance grid. All other roster paths verified clean.

### 22. Error / retry handling — ✅ PASS

Fail-closed where it matters (month lock, clearance, RBAC, warm-timeline
fallback). Compensating release on failed comp-off consumption. Degradation is
in speed, never correctness.

### 23. Auditability — ✅ PASS

`log_audit` on every state transition; regeneration archives the prior slip and
demands a ≥10-char reason; the D-005 cancellation records what it came *from*.

### 24. Production deployment readiness — ⛔ BLOCKED

Nothing committed. Three rules patches prepared and unapplied. The rules suite
cannot run here. Deployment needs explicit authorization, which has not been
given.

---

## Summary

| Verdict | Count | Points |
|---|---|---|
| ✅ PASS | 15 | 2, 5, 6, 10, 11, 13, 14, 15, 16, 17, 18, 20, 21, 22, 23 |
| ⚠️ PARTIAL | 5 | 1, 3, 7, 9, 19 |
| ⛔ BLOCKED | 4 | 4, 8, 12, 24 |
| ❔ NOT ASSESSED | 0 | — |

**Point 3 moved PASS → PARTIAL on 2026-08-22.** That is a downgrade found by
this audit, not a regression introduced by it — the PF wage base has always
excluded DA. It is recorded as a downgrade because calling payroll correct while
its statutory base is wrong would be exactly the kind of unearned green this
gate exists to prevent.

**The honest headline: the Staff Lifecycle is engineering-complete for everything
that does not require an owner decision, a deployment, or a toolchain the machine
does not have.** It is not production-ready, and the reasons are specific and
listed — not a general lack of confidence.

### What must happen before a real school runs on this

1. **Fix the PF wage base (D-PF-1)** with the tenant's CA. This is not an F&F
   item — it affects every ordinary payroll run and should be settled before the
   first one. Decision pack item 1.
2. **Deploy D-004** (staff leave read scope). Applied to the working tree; the
   live project still serves the broad arm until rules are deployed. Review the
   whole-file `git diff` first — the local rules file is ~1,051 insertions ahead
   of `HEAD`, most of it other programmes' work.
3. **Answer decision pack items 2–6** — or F&F cannot compute.
4. **Schedule D-006** (salary effective dating) before any tenant records a
   salary revision. Free to do now; expensive after. Run the **offline** parity
   harness first — switching the A/B shadow on today would compare against empty
   collections and report a meaningless "no divergence".
5. ~~Build off-cycle runs~~ — **DONE 2026-08-22.** `runType` on every run,
   monthly uniqueness preserved, and all nine run consumers audited — which
   found G-1 (an off-cycle settlement could satisfy the month-lock precondition
   and freeze out a payroll that had never run) and G-2 (an off-cycle run could
   become the dashboard's headline payroll figure). Both fixed and guarded.
6. **Get a JDK 21+ box** and run the rules suite before deploying any rules.
7. ~~Assess notifications~~ — **DONE.** `SALARY_PROCESSED` trigger analysed:
   only full payment is irreversible, so that is the only defensible trigger.
   Whether it fires at all is decision pack item 11.
8. ~~Add the comp-off policy UI~~ — **DONE.** Expiry days and maximum balance
   are configurable and server-validated, and `0` means never-expires / no-cap.

### What is safe to leave

The latent findings — D-003, the payroll department divergence, D-006's current
state — are all backed by censuses showing **zero affected records in any
tenant**. They are real and must be fixed before the relevant feature is first
used, but none is corrupting data today, and none requires historical
remediation.
