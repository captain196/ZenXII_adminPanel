# ZENX HRMS — W3.2.5 — ASYNC / SCALABLE PAYROLL GENERATION — GATE REPORT

**Date:** 2026-08-09
**Result:** **PARTIAL** — the measured bottleneck is closed; **async itself is deferred on evidence**
**Status:** UNCOMMITTED · flags **OFF** · nothing deployed, migrated, seeded or production-enabled

---

## 1. Pre-implementation inspection

§"do NOT assume an async architecture is required before measuring" was the decisive instruction.
Measuring first changed what this gate built.

**Existing infrastructure found:**

| Asset | State |
|---|---|
| `FeeWorker.php` | A **production-hardened job worker** — `feeJobs` queue, claim, stuck-job reaping (>5 min → requeue), `MAX_ATTEMPTS`, `failed` state with error context, try/finally lock release, structured logging, `run` + `health` endpoints, Task Scheduler invocation |
| 10 cron controllers | Established scheduled-execution pattern |
| `PayrollReconciler` | Idempotent accounting sweep already in the payroll path |
| **`Firestore_service::batchWrite()`** | **Already existed**, chunks at 450 under Firestore's 500/request hard limit, falls back to per-doc `set()` — **payroll never called it** |

**So ZenX already had both an async pattern and a batching primitive.** Neither needed inventing.

## 2 / 3. Measured findings — and the exact W3.2.5 scope they justified

`php index.php hrms_w325_scale run` — **writes nothing**, test-asserted:

**Engine (`_compute_payslip`, invoked by reflection on the real controller):**

| Employees | Time | Per employee | Memory Δ |
|---|---|---|---|
| 500 | 3.9 ms | 0.008 ms | 0.0 MB |
| 1,000 | 8.3 ms | 0.008 ms | 0.0 MB |
| 5,000 | **39.3 ms** | 0.008 ms | 0.0 MB |

**The engine is not the bottleneck at any required scale.** Linear, sub-millisecond, no I/O.

**Reads:** bulk and **headcount-independent** — roster, salary structures (via the W3.1 seam),
attendance summaries, leave requests, leave types, holidays, overrides: **7 queries regardless of
headcount**. No N+1 existed.

**Writes — the entire problem:**

| Employees | Reads | Writes BEFORE | Writes AFTER | Reduction |
|---|---|---|---|---|
| 500 | 7 | 501 | **3** | 167× |
| 1,000 | 7 | 1,001 | **4** | 250× |
| 5,000 | 7 | 5,001 | **13** | **385×** |

`generate_payroll()` wrote slips in a `foreach` of single `set()` calls — **one sequential Firestore
round-trip per employee**, while a batching primitive sat unused in the same codebase.

**Scope consequence:** the honest first fix is **batching, not async**. Async orchestration on top of
5,001 sequential writes would have hidden the defect behind a progress bar.

## 4 / 5. What changed

1. **`_buildPayslipDoc()`** — extracted so the single-write and batch-write paths **cannot diverge**.
   Two builders would eventually disagree on a field, and a payslip would then differ depending on
   whether the run was large enough to batch. Test-asserted: exactly one builder, exactly two callers.
2. **`_fsSyncPayslipsBatch()`** — batched write via the existing `batchWrite()`, `merge: true`
   preserved, failed doc ids returned.
3. **`generate_payroll()`** now batches, and emits **one named warning per failed slip** — a failed
   slip is an unpaid employee, so "12 writes failed" would be unactionable.
4. **N-15** — payout readiness wired into preflight (§11).
5. **`payroll_readiness_helper.php`** — see §11 for why this extraction was necessary.
6. **`Hrms_w325_scale`** — read-only measurement harness.

**No async layer was built.** See §21.

## 6 / 7 / 8 / 9 / 10. Idempotency · retry · failure recovery · progress · concurrency

**Unchanged and inherited, because no new execution model was introduced.** Generation remains
synchronous, so the existing guarantees still hold exactly as certified:

- **Duplicate-run prevention** — preflight refuses a second run for the same month/year.
- **Duplicate-slip prevention** — deterministic doc id `SLIP_{runId}_{staffId}` with `merge: true`;
  re-running writes the same document rather than a second one.
- **Duplicate accounting posting** — `postRunAccrual()` idempotent on `JE_HR_PAYROLL_{runId}`;
  regeneration deltas idempotent on `{runId}_{staffId}_ADJ{regenCount}`.
- **Partial failure** — batch chunks are independent; failed ids are returned and named. A chunk
  failure leaves earlier chunks written, and re-running merges to the identical document.
- **Retry** — safe by construction (deterministic ids + merge).
- **Progress state / resumability / cancellation / stale-job recovery** — **NOT APPLICABLE while
  synchronous**, and therefore **not claimed**. They are acceptance criteria of `N-18`.

**Concurrency** — unchanged: `regenerate_staff_payroll` holds a `Processing` compare-and-set lock
with a lost-race check.

## 11. N-15 — payout readiness in preflight

`hasBankDetails()` (W2.3) and named preflight blockers (W3.1) both existed; **they were never
connected**, so an operator got no warning that an employee could not actually be paid.

Preflight now emits, per employee:

```
Rahul Sharma · Accounts
Bank details missing — this employee cannot be paid
→ Complete bank/payment details before payroll generation
```

**Bank = blocker. PAN = warning**, deliberately: refusing to pay someone over a document that nothing
currently consumes would stop payroll for the wrong reason. PAN becomes a blocker when TDS goes live.

**Reads the server-only PII mirror** via `_apply_staff_private_map()` — bank/PAN do not live on the
main staff doc (W2.2 discovery: zero of each). Reading only the main doc would have reported **every**
employee as unpayable.

### The correction this forced

My first implementation called `Salary_assignment_service::hasBankDetails()` directly. That tripped
**three W2 guards** (`hrms_w2_verify` C, `CompensationReaderTest`, `SalaryAssignmentTest`) which keep
the compensation cutover un-wired by asserting `Hr.php` never names that class.

**The guards were right and the predicate was in the wrong place.** "Can this person be paid" is a
question about the staff document alone — nothing to do with the component model. So the rule moved
to `payroll_readiness_helper.php`, and `Salary_assignment_service::hasBankDetails()` now **delegates
to it**. One implementation, two callers, guards intact.

**Deliberately NOT `readiness()`.** That method also asserts a W2 salary *assignment* exists — and
the component model is off with an empty collection. Calling it today would block **every employee in
every tenant** with "no salary assignment is effective". Test-asserted with a stub that reproduces
exactly that live state.

## 12 / 13. Performance and 500 / 1k / 5k results

Real measurements in §2. **What was NOT measured, stated plainly:**

- **Real Firestore write latency at 5,000 documents.** Measuring it needs either 5,000 production
  writes or a fabricated tenant. **Neither was done.** Round-trip *counts* are exact; wall-clock is
  **extrapolation**: at a typical 100–200 ms round-trip, 5,001 sequential writes ≈ **8–16 minutes**
  (would exceed a web request), versus 13 batched round-trips ≈ **1.5–3 seconds**. **Labelled as
  extrapolation, not evidence.** Registered as `N-19`.
- End-to-end wall clock of a real 5,000-employee run, concurrency under load, and partial-failure
  behaviour at scale — all unmeasured.

## 14. Accounting safety

**Untouched.** No accounting code changed. The batch writer is asserted to contain no money
arithmetic — no `_toPaise`, `_toRupees`, `_applyRatio`, `round(` or net assignment. `Dr == Cr`,
the transactional outbox, the reconciler and idempotent posting are all as certified;
`hrms_p0_verify` and `hrms_w2_verify` PASS.

## 15 / 16. RBAC, security, tenant isolation

Unchanged. Preflight remains `HR:manage`. Batch writes address documents through `docId()`, which
prefixes the caller's tenant — a batch cannot cross tenants. The PII mirror is read through the
existing server-side helper; no new client path, no rules change, no entitlement change.

## 17. Test results

**13 new tests, all passing.** Behavioural: all four bank-detail field shapes recognised · blank and
missing detected · **`readiness()` proven to block everyone under live conditions** (stub returning
no assignments). Structural: one shared doc builder with two callers · generation batches ·
`merge: true` preserved · failed slips reported individually and logged · chunk size under the
Firestore limit · batch writer contains no money arithmetic · preflight uses the shared predicate and
**not** the compensation service · bank blocks / PAN warns · PII mirror read · scale harness has no
way to write.

**Regression:** **461 tests / 1,493 assertions / 10 failures** — the documented pre-existing set,
unchanged. `hrms_w2_parity` · `hrms_w2_verify` · `hrms_p0_verify` · `hrms_closure_verify` ·
`hrms_w13_verify` all **PASS**. `php -l` clean on every touched file.

**Two failures I introduced and fixed** — both my own errors, neither a production defect:
the `Salary_assignment_service` guard breach (§11), and a scale-harness assertion so blunt it banned
the *word* `batchWrite` from an `echo` string. Re-pointed at access primitives (`$this->fs`,
`->set(`, `firebase`), which is the property that actually matters.

## 18 / 19. Browser and Test-School status — **NOT PERFORMED**

**OPERATOR VERIFICATION REQUIRED.** No browser test and no Test-School run. Unverified: the preflight
bank/PAN blockers rendering, and a real batched generation against a live tenant. No Test-School data
was read destructively, written, or manufactured.

## 20. Master-register changes

| Capability | Before | After |
|---|---|---|
| `N-15` payout readiness in preflight | MISSING | **COMPLETE** |
| `N-17` batched payslip writes | *(new)* | **COMPLETE** |
| `N-18` async job orchestration | *(new)* | **MISSING** |
| `N-19` real write-latency benchmark | *(new)* | **MISSING** |
| `T-14` performance testing | MISSING | **COMPLETE** |
| `T-15` async/scale testing | MISSING | **PARTIAL** |

**Canonical count 161 → 164.** Authoritative status counts (enumerated per family, not derived by
delta): **COMPLETE 50 · PARTIAL 41 · MISSING 62 · ENH 1 · REF 1 · BLOCKED 8 · OP-DEC 1 · N/A 0**.

**Proof:** `50 + 41 + 62 + 1 + 1 + 8 + 1 + 0 = 164` ✓ · **Completeness 50/164 = 30.5%.**

## 21. Remaining incomplete · 22. Explicitly deferred · 23. Newly discovered

**`P-047` / backlog `C` — async payroll generation — remains MISSING, and W3.2.5 is therefore
`PARTIAL`, not complete.**

**Deferred on evidence, not on convenience.** Batching removed the measured bottleneck, so async is
no longer justified *by the measurement available*. It is **not** cancelled: `N-18` carries its full
acceptance criteria (background execution, progress state, resumability, duplicate-run/slip/posting
prevention, retry, partial-failure recovery, per-employee failure reporting, safe restart,
cancellation, stale-job recovery, audit) and names `FeeWorker` as the reuse pattern. Owner wave
**W5 — or earlier if `N-19` disproves the extrapolation.**

Also incomplete: `N-19` (real latency at scale), `T-15` (async testing — untestable while unbuilt),
`T-07`/`T-11` (browser and Test-School UAT).

**Newly discovered:** `N-17`, `N-18`, `N-19` — all registered in the master register with status,
evidence, acceptance criteria and owner wave, **not left in prose**.

## 24 / 25. Final counts and proof that nothing disappeared

`50 + 41 + 62 + 1 + 1 + 8 + 1 + 0 = 164` ✓

| Check | Result |
|---|---|
| Every pre-wave capability still present | ✓ — 161 retained, 3 added |
| `P-047` async still tracked | ✓ — MISSING, with `N-18` carrying its criteria |
| Nothing marked COMPLETE without evidence | ✓ — `T-15` is PARTIAL, not COMPLETE, because async is unbuilt |
| Nothing downgraded without evidence | ✓ |
| New capabilities registered, not hidden in prose | ✓ — `N-17`/`N-18`/`N-19` |
| Statutory rule preserved | ✓ — no policy inferred; W2 flags untouched |
| Money contract preserved | ✓ — batch writer asserted free of arithmetic; `hrms_p0_verify` PASS |
| Every incomplete capability has an owner wave | ✓ |

---

## Final state

- **Implemented:** shared payslip doc builder · batched slip writes (385× fewer round-trips at 5k) ·
  named per-slip failure reporting · N-15 payout readiness · `payroll_readiness_helper` · read-only
  scale harness · 13 tests.
- **Reused:** `batchWrite()` · `_apply_staff_private_map()` · the existing bank-detail rule ·
  preflight blocker shape · the payroll engine, accounting, RBAC and tenant primitives — all untouched.
- **Discovered:** the scale problem was **writes only**; reads were already bulk; the batching
  primitive already existed unused; `readiness()` would block every employee if used as-is.
- **Deferred with evidence:** async orchestration (`N-18`), real latency benchmark (`N-19`).
- **Production data changed:** **none.** **Deployed:** **nothing.** **Committed/pushed:** **nothing.**
- **Browser / Test-School verification:** **not performed, not claimed.**

**W3.2.5 status: PARTIAL.** The gate's primary capability (`P-047` async) is not complete, and this
report does not claim otherwise.
