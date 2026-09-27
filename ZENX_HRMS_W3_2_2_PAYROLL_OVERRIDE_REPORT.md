# ZENX HRMS — W3.2.2 — PAYROLL-PERIOD OVERRIDE + EDITABLE REVIEW GRID

**Date:** 2026-08-09
**Gate:** W3.2.2 — second of six W3.2 sub-gates
**Result:** **DELIVERED** — with two capabilities honestly classified as NOT supported rather than faked
**Status:** UNCOMMITTED · flags **OFF** · mode **LEGACY** · rules **written, NOT deployed** · nothing migrated or seeded

---

## A / B. Repository inspection — what already existed

The instruction not to assume absence merely because there is no `override` controller was
well-placed. Two existing capabilities changed the entire design:

### B.1 — `PayrollAccountingService::postRunAdjustment()` already exists

A consolidated payroll **delta** journal, already used by `regenerate_staff_payroll`:

- posts a delta, not a reversal
- **idempotent** on a deterministic `source_ref` (`{runId}_{staffId}_ADJ{regenCount}`)
- **Dr == Cr validated by the engine**
- maps to the existing chart: `5010`/`5020` expense, `2020` net payable, `2030`–`2034` liabilities

### B.2 — the certified engine ALREADY accepts payroll-period adjustments

W2.7 added `$extraComponents` to `_compute_payslip()`:

```php
_compute_payslip($sal, …, ?array $statutory = null, array $extraComponents = [])
```

Earnings join gross **before** the statutory block, deductions join the deduction pool, everything in
integer paise through `_applyRatio()`. **Nothing had ever supplied that parameter.**

**So the override feature is 80% pre-built.** What was missing was persistence, validation,
authorization and UI — not arithmetic and not accounting. Building an override→money→ledger path
would have created the second payroll engine this programme exists to avoid.

**The resulting architecture reuses everything:**

```
override stored  →  hr/regenerate_staff_payroll (existing, certified)
                 →  _compute_payslip($extraComponents)   (existing, W2.7)
                 →  existing delta computation            (existing)
                 →  postRunAdjustment()                   (existing, idempotent, Dr==Cr)
```

No new accounting code. No new money code. Test-asserted: neither new file references
`PayrollAccountingService`, `postRunAdjustment`, or any account code.

## C. Business model

**Canonical representation: `original + adjustment = final`, with all three engine-derived.**

Chosen over `original + delta` because "delta" is ambiguous once statutory is involved. A ₹5,000
bonus does **not** change net by ₹5,000 — it raises gross, which raises the ESI and TDS base, so net
moves by less. Storing a single "delta" would force a reader to guess which delta was meant. Storing
the two endpoints plus the adjustment lines is unambiguous and reconstructable.

**The baseline is computed by re-running the engine with no adjustments, never by subtraction.**
Subtraction would be a second arithmetic path for money *and* would be wrong wherever an adjustment
moves a statutory base. `_compute_payslip()` is pure computation with no Firestore access, so the
extra call is free and keeps the engine the only source of these numbers.

### Adjustment types and their statutory effect — observed, not invented

The engine already decides this. The table **documents** its behaviour:

| Type | Engine kind | Gross | ESI/TDS base | PF base | PT | Net |
|---|---|---|---|---|---|---|
| BONUS · INCENTIVE · ARREARS · ONE_TIME_EARNING · CORRECTION_ADD | `earning` | **↑** | **↑** | — | — | ↑ (less than gross) |
| ONE_TIME_DEDUCTION · RECOVERY · CORRECTION_DEDUCT | `deduction` | — | — | — | — | **↓ by exactly the amount** |

PF is unaffected because the engine computes it on earned **basic**, and no period override can move
basic.

**Proration defaults to FALSE.** A bonus is awarded for a period, not earned per-day; scaling it by
attendance would pay less than the amount an operator typed and approved. The flag is stored per
override so the choice is explicit and auditable rather than implied.

### Three capabilities REFUSED rather than faked

| Type | Why it is refused |
|---|---|
| **REIMBURSEMENT** | Every earning in the engine joins gross and is therefore exposed to ESI and TDS. **There is no non-taxable earning class.** Booking a reimbursement as a bonus would tax an expense repayment — a money error. Needs an engine change. |
| **PF_BASE_ADJUSTMENT** | PF is a percentage of earned basic; an override cannot move basic. Needs the W2 component model. |
| **LOP_ADJUSTMENT** | LOP is derived from Attendance and Leave, which are authoritative. Adjusting it from payroll would make payroll a second attendance system. Correct route: fix Attendance/Leave, then regenerate. |

These are returned by `payroll_overrides/types` and shown in the UI **with the reason**, so an
operator looking for "Reimbursement" is told why it is absent instead of booking it as a bonus.

## D. Firestore data model

**Decision: option A — one document per override**, in a new `payrollOverrides` collection.

| Criterion | Why A wins |
|---|---|
| **Concurrency** | Decisive. Two admins adding different adjustments to the same employee never collide — separate documents. An embedded array needs read-modify-write and would lose one silently. |
| Auditability | A row cannot be overwritten wholesale by an unrelated edit. |
| Queryability | One `schoolWhere(run_id)` returns the whole run's adjustments. |
| Performance | **Bulk by run, not per employee** — the same N+1 trap `resolveMany()` exists to avoid. |
| Single source of truth | The slip stores the *applied result* (`_payroll_adjustments`), which is a derived snapshot — the same relationship `_salary_snapshot` already has. |

**Not stored in `salarySlips`**, despite that collection's `type`-discriminator convention: its rule
allows `resource.data.staffId == request.auth.uid`, which would make the admin-internal `reason`
("retention adjustment pending exit") readable by the employee it concerns. Overrides follow the
`salaryComponents` precedent instead — **server-only writes**, because every write must pass
validation a client-SDK write would bypass.

Rules block **written, not deployed**. PHP uses the Admin SDK and bypasses rules, so functionality
does not depend on the deploy. The file was re-read immediately before editing per the
concurrent-edit rule.

## E. Override lifecycle

`active` → `void`. **No second approval system was created** — §"do not create duplicate approval
systems" — because the run lifecycle already provides the control: adjustments are only accepted
while the run is `Draft`, and `Draft → Finalized → Paid` already has approval and an audited unlock.

**Withdrawal voids, never deletes.** A withdrawn adjustment is exactly what an auditor asks about;
deleting the row destroys the answer.

## F / G. Review grid and employee drill-down

Extended the **existing** payroll run grid (`#tblPayslips`) — no separate payroll application, no new
frontend framework, existing AdminLTE/Bootstrap conventions:

- new **Adjustments** column showing the net effect, `—` when unadjusted
- drill-down panel showing **Original gross → each adjustment line → Revised gross → Original net →
  Revised net**, placed first so it cannot be missed
- inline create form (type · amount · reason), offered only when the run is `Draft` **and** the user
  holds `HR:manage`

Every figure is read from the slip's `_payroll_adjustments` block, which the engine wrote. **The
browser performs no payroll arithmetic** — §"do not reconstruct numbers in the UI".

The form states plainly that saving records the adjustment and that **Regenerate** applies it, so a
stored-but-unapplied adjustment is never mistaken for a paid one. Client-side validation mirrors the
server rules for instant feedback; the server re-validates everything and is the actual guard. The
`r.ok`/`status==='error'` phantom-success guard is applied.

## H / I. Original → adjustment → final

Persisted on **both** slip writers (`generate_payroll` and `regenerate_staff_payroll`) as
`_payroll_adjustments`: version, count, per-line breakdown, `original_gross`, `original_net`,
`final_gross`, `final_net`, `applied_at`, `applied_by`. `null` when unadjusted — so an adjusted run
that nets to zero stays distinguishable from one never adjusted.

Both engine call sites receive overrides, test-asserted. The codebase's own contract is that
*"regenerate is contractually required to produce identical numbers to generate for the same
inputs"* — that contract breaks the moment one call site can see an adjustment the other cannot.

## J. Accounting

**No new posting logic.** Applying an override runs the existing recalculation, which computes
`dGross/dNet/dPF/dESI/dTDS/dProfTax/dOtherDed` and posts through `postRunAdjustment()` — already
idempotent on a deterministic `source_ref`, already engine-validated for `Dr == Cr`.

**Honest limitation:** an override posts to the **existing** payroll expense accounts (`5010`/`5020`),
because §"do NOT invent account codes" — a dedicated Bonus Expense account is a Chart of Accounts
decision that is the operator's, not mine. Flagged, not silently decided.

## K. RBAC

Graded `HR` only. `view` lists adjustments; **`manage` is required to create or withdraw**, since
both move money — `view` is deliberately not sufficient. Every endpoint enforces independently;
UI hiding is a convenience and is tested as such.

**No new permission key was minted.** `HR:manage` already gates `regenerate_staff_payroll`, which is
what actually applies an adjustment to money and the ledger. A separate key that gated *storing* an
adjustment while `HR:manage` still allowed *applying* it would be security theatre. **Recorded as a
deliberate decision**: if payroll adjustment authority must be separable from HR management, that is
a real requirement and belongs in the W5 payroll RBAC split (gap 92), not invented here.

Source-asserted: no `Principal`, no `HR Manager`, no `ADMIN_ROLES`, no `_require_role`.

## L. Tenant isolation

Every read is `schoolWhere()`-scoped; every write is addressed through `docId()`, which prefixes the
caller's own school_id — so a forged run id from another tenant resolves to a non-existent document
rather than the other tenant's run. An explicit `schoolId` re-check follows. Run lookup deliberately
uses the `get()` + `docId()` idiom rather than `getEntity()`: mixing the two is the double-prefix bug
class W1.3 found in ATS and Attendance, and it was not worth re-opening for brevity.

Void additionally verifies the override belongs to the run — ownership of the override itself, not
merely of the run.

## M. Concurrency

**Sidestepped by design rather than solved with locking.** Overrides are create-and-void only —
never edited — so there is no read-modify-write to lose. Two admins adjusting the same employee
create two independent documents; both survive, both appear.

Duplicate protection is by **deterministic id**: `OVR_{run}_{staff}_{type}_{seq}`, written
non-merge. A double-submit dedups to one document; a genuine second bonus takes the next sequence
and gets its own audit row.

The underlying recalculation already holds a `Processing` compare-and-set lock with a lost-race
check — reused, not duplicated.

## N. Performance / read budget

| Operation | Firestore reads |
|---|---|
| Review grid (whole run) | **1** — one `schoolWhere(run_id)` for all employees |
| Employee drill-down | **0** — served from the grid's bulk read |
| Create an override | 2 (run + slip existence) + 1 write |
| Recalculation | 1 override read, folded into the existing regeneration |

**No N+1 introduced.** `generate_payroll` reads overrides once for the whole roster before the loop,
so applying them costs no per-employee read. The compensation seam is untouched — `resolveMany()`
still performs one query.

## O. Audit model

Per override: `override_id · school (docId) · run_id · staff_id · type · kind · amount_paise ·
prorated · reason · status · created_by · created_at · updated_by · updated_at`, plus on withdrawal
`void_reason · voided_by · voided_at`, plus on application `applied_at · original_net · final_net`.

Per slip: the `_payroll_adjustments` block above. Plus structured logs
(`PAYROLL_OVERRIDE_CREATED` / `_VOIDED`) carrying school, run, staff, type, paise and actor.

## P. Finalization immutability

Adjustments are accepted only while the run is `Draft`. `Finalized`, `Approved`, `Paid`,
`Processing` and an empty status are all refused — server-side, tested. **No hidden bypass was
created**: correcting a finalized run goes through the existing audited `unlock_payroll`, which
records `unlocked_from_status`.

## Q. Tests — 35 new, all passing

Covering test-matrix items **A–H, L–Q, V–AA** plus extras: valid earning/deduction · zero, negative,
non-numeric and absurd amounts · smallest representable amount · unknown type · **reimbursement
refused with its reason** · missing/short/over-long reason · missing employee · missing run · all
errors reported at once · Draft editable · Finalized/Approved/Paid/Processing frozen · engine-shape
mapping · **two same-type adjustments do not collapse** · voided never reaches the engine ·
malformed rows fail closed · summary arithmetic · deterministic ids · determinism across repeated
calls · **the service contains no payroll arithmetic** · exactly one rounding at the input boundary ·
no tenant literal, no RTDB · every persistence call tenant-scoped · graded RBAC per endpoint · seam
not bypassed · snapshots not rewritten · **no second accounting posting** · both engine call sites
wired · baseline engine-derived not subtracted.

### A test-infrastructure improvement worth noting

Four guards initially failed because my explanatory docblocks **named the very literals the guards
forbid** — the same trap that hit W3.2.1. Rather than delete the prose a second time, the guards now
strip comments via `token_get_all()` before asserting. This fixes the class rather than the
instance, and makes the guards **stricter**: a name can no longer hide in a comment in either
direction. Otherwise the standing pressure is to write worse comments.

## R. Integration test — NOT DELIVERED

The end-to-end scenario (generate → review → override → recalculate → audit → accounting →
finalize → verify immutable) **was not built**. It needs a live CodeIgniter request context and a
Firestore fixture; the unit bootstrap cannot load `MY_Controller`, and `tests/Integration` does not
exist in this repo's PHPUnit configuration.

Stated as missing rather than approximated by unit tests that would prove less than they appear to.
It belongs with the Test-School UAT, and the pieces it would assert are individually covered above.

## S. Regression — baseline held exactly

| Suite | Before W3.2.2 | After | New failures |
|---|---|---|---|
| PHP unit | 367 / 1184 / **10** | **402 / 1303 / 10** (+35) | **0** |
| `hrms_w2_parity` | 14 / 0 | **14 / 0 PASS** | 0 |
| `hrms_w2_verify` | 45 / 0 | **45 / 0 PASS** | 0 |
| `hrms_p0_verify` | PASS | **PASS** | 0 |
| `hrms_closure_verify` | 24 / 0 | **24 / 0 PASS** | 0 |
| `hrms_w13_verify` | 42 / 0 / 1 | **42 / 0 / 1 PASS** | 0 |

The 10 failures are the documented pre-existing set, unchanged in identity and count. **No baseline
failure was "fixed" in passing.** `php -l` clean on every touched file.

W1.2 money precision, W1.3, W2 compensation/statutory/effective-dating/parity/admin UI, the W3.1
seam and preflight, and the W3.2.1 snapshots are all intact and verified.

## T. Browser verification — **NOT PERFORMED**

**OPERATOR VERIFICATION REQUIRED.** The UI is written, lint-clean, and routed, but no browser test
was run and none is claimed. Specifically unverified: the type dropdown populating, the save
round-trip, the adjustments column rendering, the drill-down panel, and that the form is hidden for
a non-`manage` user (the endpoint denial is source-asserted, not exercised).

Also unverified behaviourally, for the same reason (no CI request context): endpoint RBAC denial,
cross-tenant rejection, and finalized-run refusal. All three are implemented and source-asserted.

## U. Remaining W3 gaps

| # | Gap | Status | Wave |
|---|---|---|---|
| 3 | LOP review / confirmation | **MISSING** | W3.2.3 |
| 4 | Full calculation trace | **PARTIALLY COMPLETE** — the adjustment panel is the first real trace segment; the full chain is not built | W3.2.4 |
| 5 | Async payslip generation | **MISSING** — not assessed this gate | W3.2.5 |
| 6 | Statutory snapshot | **MISSING** — upstream-blocked on W2 policy | W3.2.6 |
| — | Non-taxable earning class (reimbursement) | **MISSING** — engine capability | needs a W2/W4 engine gate |
| — | Separable payroll-adjustment permission | **NEEDS ENHANCEMENT** — deliberately not invented | W5 (gap 92) |
| — | Integration test | **MISSING** | Test-School UAT |
| — | Dedicated bonus expense account | **NEEDS A DECISION** — Chart of Accounts, operator-owned | operator |

## V. Mapping to the Asanify benchmark

Against the benchmark's payroll-period adjustment capability: **type-controlled adjustments,
original→adjustment→final visibility, per-adjustment audit, run-state-gated editability, and
automatic ledger flow-through** are present. **Absent:** non-taxable reimbursement, a separate
adjustment approval step, and bulk adjustment upload — each classified above rather than dropped.

This does **not** claim parity. It claims the adjustment workflow moved from *nonexistent* to
*controlled and auditable*.

## W. Confirmation — no capability dropped, no second system built

**Confirmed.** Nothing was removed, reinterpreted or narrowed. Every unsupported item in §C and §U
carries an explicit reason and a wave.

**One payroll engine** — `_compute_payslip()`, extended in W2.7, not duplicated. **One money path** —
integer paise, one conversion at the input boundary, asserted by test. **One accounting path** — the
existing outbox and delta journal. **One attendance system** · **one leave system** · **one RBAC** ·
**one compensation seam** · **one employee master**. No new entitlement key.

---

## Final state

- **Implemented:** override service, 4 endpoints, routes, rules block, both engine call sites wired,
  engine-derived original→adjustment→final on both slip writers, review-grid column, drill-down
  panel, inline create form, 35 tests.
- **Reused:** the entire recalculation and accounting path, the W2.7 engine parameter, the run
  lifecycle and its locks, the processing CAS lock, graded RBAC, tenant primitives, the existing grid.
- **Discovered:** the engine already supported adjustments and nothing had ever supplied them;
  `postRunAdjustment` already existed, idempotent and balanced.
- **Deferred:** integration test · async payslips · full trace · LOP confirm · statutory snapshot ·
  non-taxable earnings · separable permission — each with a reason and a wave.
- **Production data changed:** **none.** **Deployed:** **nothing** (rules written only).
  **Committed/pushed:** **nothing.**
- **Browser verification:** **not performed, not claimed.**
