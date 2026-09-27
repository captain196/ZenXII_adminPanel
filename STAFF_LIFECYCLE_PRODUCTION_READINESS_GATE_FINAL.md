# Staff Lifecycle — final production-readiness gate

**2026-08-22 · `integration/phase2` · nothing committed since `f1ad3ec`.**
Nothing pushed, deployed, migrated, or flag-flipped.

| Surface | Tests | Result |
|---|---|---|
| Admin (PHP) | **4515** | 10 class-A failures, 5 intentional incompletes, **442 MB** / 512 MB |
| Teacher (JVM) | 109 | Not re-run — **no cross-platform surface changed** (see §14) |
| Parent (JVM) | 77 | Not re-run — same reason |
| Firestore rules (Jest) | 1290 | **BLOCKED** — needs JDK 21+; machine has **17.0.18** (verified this session) |

**A green suite is not a pass.** Every row states what was actually exercised.

---

## Failure classification — every one, with evidence

| # | Failure | Class | Evidence |
|---|---|---|---|
| 1 | `BackupTest::size_human…` | **Pre-existing** | `1 KB` vs `1.0 KB` format drift; documented in CLAUDE.md as baseline |
| 2–8 | Homework / ParentHomeworkRepo (7) | **Pre-existing** | Cross-repo tests grepping Kotlin source via a hardcoded path from another machine |
| 9–10 | `TeacherExecuteDeleteCascade` (2) | **Pre-existing** | Same cross-repo pattern |

**Zero failures caused by this programme.** One transient 11th failure
(`ProcessedPaymentIdempotencyTest`) appeared earlier and was traced by file
mtime to the concurrent Fees programme writing four files between 12:45–12:57;
it cleared on their side without intervention. One further transient
(`StatutoryPfWageBase::…wired_to_nothing`) **was** mine — a new config file
mentioned the class in prose and tripped a bare-name scan. The predicate was
tightened to match its stated intent (call sites, not mentions) and a
non-vacuity proof added.

The 5 incompletes are deliberate and each names an open item.

---

## The 24 points

| # | Area | Verdict | Evidence |
|---|---|---|---|
| 1 | Functional completeness | ⚠️ **PARTIAL** | Lifecycle runs end to end. F&F calculation absent by design (DR-07/08/09). |
| 2 | State machines | ✅ **PASS** | `Fnf_state_machine` terminal states, self-approval fails closed. |
| 3 | **Payroll correctness** | ⚠️ **PARTIAL** | Arithmetic exact and real-engine tested. **Statutory wage base wrong** — PF on basic alone (DR-01). Latent: 0 payslips. |
| 4 | **Statutory correctness** | ⚠️ **PARTIAL** | Wage engine now correct (W-1/W-2/W-3 fixed, 54 tests). **Not wired** — `USE_STATUTORY_WAGE_BASE` false. EPS split and PF membership absent (DR-17). |
| 5 | F&F | ⛔ **BLOCKED** | By policy (DR-09 notice basis, DR-10 threshold), not engineering. |
| 6 | Off-cycle payroll | ✅ **PASS** | `runType` on every run; monthly uniqueness preserved; all 9 consumers audited; G-1/G-2 fixed and guarded. |
| 7 | Effective dating | ⚠️ **PARTIAL** | Department per-day resolved (N-40). Salary effective dating gated (DR-15). Payout-from absent (DR-18). |
| 8 | Salary revision | ⛔ **BLOCKED** | DR-15 — needs offline parity evidence. |
| 9 | Security | ⚠️ **PARTIAL** | D-004 applied locally, **not deployed**. D-003/D-007 drafted. |
| 10 | RBAC | ✅ **PASS** | Graded RBAC untouched throughout. |
| 11 | Tenant isolation | ✅ **PASS** | Census confirms 9 tenants cleanly separated; no cross-tenant query introduced. |
| 12 | Firestore rules | ⛔ **BLOCKED** | JDK 17 verified this session. Not faked, not worked around. |
| 13 | Firestore indexes | ✅ **PASS** | No new query shape introduced. |
| 14 | Concurrency / CAS | ✅ **PASS** | No new write path. Existing CAS untouched. |
| 15 | Idempotency | ✅ **PASS** | Wage engine is pure — identical input, identical output, by construction. |
| 16 | Financial precision | ✅ **PASS** | Integer paise throughout; add-back trigger is exact cross-multiplication, no rounding; excess rounds in the employee's favour. |
| 17 | Accounting integration | ✅ **PASS** | Untouched. `PayrollAccountingService` remains the only bridge. |
| 18 | Admin/Teacher/Parent contracts | ✅ **PASS** | **No collection, field, endpoint or rule changed** — verified (§14). |
| 19 | Notifications | ⚠️ **PARTIAL** | `SALARY_PROCESSED` trigger objectively resolved (fully-paid); firing at all is a product choice (DR-14). |
| 20 | Documents / PDF | ✅ **PASS** | Untouched. |
| 21 | Performance / N+1 | ✅ **PASS** | Wage engine is O(components), no I/O. Two earlier N+1s remain fixed (50→1). |
| 22 | Error / retry handling | ✅ **PASS** | Malformed rows skipped not fatal; malformed threshold falls back to statutory default rather than dividing by zero. Both tested. |
| 23 | Auditability | ✅ **PASS** | Per-component `trace` records the classification **actually applied**, including defaulted ones; `unclassified[]` names every gap. |
| 24 | Deployment readiness | ⛔ **BLOCKED** | Rules undeployed; statutory flag must stay false pending CA + owner. |

### Additional areas required by the directive

| Area | Verdict | Evidence |
|---|---|---|
| Configuration usability | ⚠️ **PARTIAL** | Flags and threshold seeded with sources + `TODO: verify with CA`. **No admin UI yet** for wage classification — recorded, not built, because the classification vocabulary should not reach the UI before the owner accepts the model. |
| Migration / backward compatibility | ✅ **PASS** | Legacy entry point byte-identical on its closed vocabulary; all pre-existing wage-base tests pass unchanged. **Zero data to migrate** (§13). |
| Test health | ✅ **PASS** | 442 MB / 512 MB. No test weakened or deleted; three pins **inverted** with reasons, none removed. |

---

## §13 — Census, re-verified independently this session

Read-only (`scripts/pf_statutory_census.js`, proven to contain zero write calls
before it was run):

**9 tenants · 0 salary structures · 0 with `da` · 0 payslips · 0 runs ·
0 assignments · 0 catalogue rows · 0 payroll journals · 0 locked months ·
0 `statutoryConfig` · 0 schools with `salaryDefaults`.**

Every statutory finding is latent in the strongest sense: not merely
unexercised, but with no data on which it *could* have been exercised. There is
no migration, backfill, arrears, reversal, or employee to make whole.

---

## §14 — Cross-system verification

**Verified by construction, not by assumption.**

- `Statutory_wage_base.php` contains **zero** references to `firestore`,
  `firebase`, `->fs->`, `http` or `curl` — measured, count 0. It is a pure
  function with no I/O.
- The only other change is a **new** config file whose flags all default false
  (or, for the warning, change no money).
- No Firestore collection, document field, HTTP endpoint, security rule or index
  was added, removed or altered this session.

Therefore Teacher and Parent are unaffected: there is no surface through which
they could observe the change. Their suites were not re-run because re-running
them would prove nothing that the absence of a surface does not already prove —
and stating "109/109 passed" from a stale run would be worse than saying this.

**Parent app payroll access:** unchanged — Parent has no payroll surface.

---

## What must happen before a real school runs on this

1. **Owner answers OWN-1 and OWN-4** (accept the PF cost; whether pay-scale/DA
   structures are used). OWN-4 decides whether the defect is live or theoretical
   for that school.
2. **CA answers CA-1…CA-5** — above all, which allowances are paid universally.
   That is a question of fact about the school, not about software.
3. **Run the offline parity harness** — quantifies the delta per structure with
   no tenant data required.
4. **Then** flip `USE_STATUTORY_WAGE_BASE` per tenant, never globally.
5. **Deploy D-004** after a whole-file `git diff` review — the rules file is
   ~1,078 insertions ahead of `HEAD`, most of it other programmes' work.
6. **Get a JDK 21+ box** and run the rules suite before any rules deploy.

## What must remain disabled

`USE_STATUTORY_WAGE_BASE` · `STATUTORY_WAGE_SHADOW` ·
`USE_COMPONENT_COMPENSATION` · `COMPENSATION_SHADOW_COMPARE` — all **false**.
`Statutory_wage_base` remains wired to nothing, asserted by a test with a
non-vacuity proof.

---

## Honest headline

**The statutory wage engine is now correct. It is deliberately not connected to
anybody's pay.**

Three defects in it were found and fixed this session, all of which would have
shipped had the previous recommendation — "just wire it up" — been followed.
Every one failed in the same direction: understating a statutory liability.

The remaining gap between "correct engine" and "correct payroll" is one flag,
two call sites, and two questions only a chartered accountant and the school
owner can answer.
