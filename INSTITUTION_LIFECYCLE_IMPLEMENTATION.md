# Institution Lifecycle — Implementation & Certification

**Status:** IMPLEMENTED · UNCOMMITTED · rules RECONCILED but **NOT DEPLOYED** · nothing pushed
**Date:** 2026-08-21 · Branch `integration/phase2`
**Scope delivered:** state machine · access resolver · append-only audit · cron guards ·
per-operation web authorization (N1) · CLOSED read/write split in rules · readiness scorer ·
closure checklist · Superadmin lifecycle console.

---

## 1. The three axes

| Axis | Question | Owner | Touched here? |
|---|---|---|---|
| **Commercial** `schoolControl.lifecycle.state` | Is the tenant entitled to operate ZenX? | B2 (`B2_registry_service`) | Only actor/reason metadata + CAS. States untouched. |
| **Institution** `schools.institution.state` | What is the real-world state of the institution? | `Institution_lifecycle` (new) | Yes — the new axis |
| **Academic session** `schools.sessions[]`, freeze | Which session is active/frozen/archived? | `Session_lifecycle`, `School_config` | **Not touched at all** |

Orthogonal by construction. There is no institution `SUSPENDED` state — suspension is a commercial
fact and the commercial axis already owns it. Two suspension truths guarantee divergence.

---

## 2. State machine

```
PROSPECT ──▶ ONBOARDING ──▶ ACTIVE ──▶ CLOSING ──▶ CLOSED ──▶ ARCHIVED
   │             │                        │           │            │
   └──▶ CLOSED ◀─┘                        └─▶ ACTIVE  └─▶ ACTIVE ◀─┘
        (2-person)                         (cancel)     (reopen, 2-person)
```

10 legal edges of 36 ordered pairs; the other 26 are refused as `illegal_transition`; a
self-transition is an idempotent no-op. Two-person control on `CLOSING→CLOSED`,
`CLOSED→ARCHIVED`, `CLOSED→ACTIVE`, `ARCHIVED→ACTIVE`, and on abandoning a PROSPECT/ONBOARDING
tenant into CLOSED.

**Backward compatibility — the property that makes this shippable:** a tenant with no
`institution` block normalizes to `ACTIVE` (all-true). The axis is **inert** until a state is
explicitly written. No backfill was required, and none was run.

---

## 3. Enforcement — three layers, composed with AND

```
request
 → LAYER 1  lifecycle gate      may ANYONE act on this school right now?
 → LAYER 2  graded RBAC         may THIS actor do it?          (UNCHANGED)
 → LAYER 3  tenant scoping      is it THEIR data?              (UNCHANGED)
```

| Surface | Gate | Where |
|---|---|---|
| Mobile/client reads & writes | `firestore.rules` — `tenantActive()` + `institutionAllowsWrite()` | rules |
| **Admin Panel — `require_permission()`** | `lifecycle_allows_operation()` | **247 sites** |
| **Admin Panel — `_require_role()`** | `lifecycle_allows_operation()` | **926 sites** |
| CLI scheduled jobs | `lifecycle_guard_or_skip()` / `lifecycle_guard_check()` | 11 jobs |
| Superadmin console | SA session (`MY_Superadmin_Controller`) — deliberately **not** lifecycle-gated, so the SA can still act on suspended and closed tenants | SA base |

**Both authorization entry points are gated.** Gating only `require_permission()` would have
covered 247 of 1,173 sites and left Fees (82), SIS (38), HR (45), Communication (20) and
Configuration (35) completely ungated — the "per-operation authorization" claim would have been
false. `_require_role()` keeps its graded-RBAC bridge byte-for-byte (`has_permission()` first,
graded-authoritative on a held module, legacy name list only for unmigrated users); the lifecycle
clause is purely additive and runs before it.

RBAC is untouched — verified by diff: no line touching `has_permission`, `rbac_granted_level` or
`rbac_level_rank` changed, and nothing was removed from `_require_role()`. No legacy role-name
gating exists anywhere in the lifecycle libraries (grep-verified: 0 occurrences).

---

## 4. Capability matrix (institution axis)

| Capability | PROSPECT | ONBOARDING | ACTIVE | CLOSING | CLOSED | ARCHIVED |
|---|---|---|---|---|---|---|
| allowRead | ✗ | ✓ | ✓ | ✓ | ✓ | ✗ |
| allowWrite | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ |
| allowLogin | ✗ | ✓ | ✓ | ✓ | ✓ | ✗ |
| allowPayments | ✗ | ✗ | ✓ | ✓ | ✗ | ✗ |
| allowNewIntake | ✗ | ✗ | ✓ | **✗** | ✗ | ✗ |
| allowJobs | ✗ | ✗ | ✓ | ✓ | ✗ | ✗ |
| allowNotifications | ✗ | ✗ | ✓ | ✓ | ✗ | ✗ |
| allowDocuments | ✗ | ✗ | ✓ | ✓ | **✓** | ✗ |
| allowTransportSafety | ✗ | ✗ | ✓ | ✓ | ✗ | ✗ |
| allowHistoricalAccess | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |

Commercial axis: `trialing/active/expiring_soon/grace` → all true;
`past_due/suspended/expired/provisioning_incomplete` **and unknown** → all false **except
`allowTransportSafety`**. Composition is AND on every key.

**CLOSED is read-only, not off.** Parents keep reaching their child's records; certificates and
mark sheets remain issuable (`Certificates`, `Results` map to `allowDocuments`); every ordinary
write stops. That carve-out is institution-axis only — a commercially suspended tenant cannot use
it to keep working.

---

## 5. Firestore model & rules

| Data | Location | Client access |
|---|---|---|
| Institution state | `schools/{schoolId}.institution.{state, since, effectiveDate, reason, actorId, lastEventId}` | read via existing `schools` rule |
| Lifecycle history | `schoolLifecycleEvents/{eventId}` | **deny read, deny write** |
| Client mirror | `tenantPublic/{schoolId}.institutionState` | existing client-read rule |

Rules changes (2, both minimal):
- `tenantActive()` — institution states `ACTIVE|CLOSING|CLOSED` pass; `ARCHIVED|PROSPECT|ONBOARDING`
  deny; **absent ⇒ allowed**. Zero extra document reads (`sch` is already fetched).
- `isSameSchoolWrite()` — new `institutionAllowsWrite()` clause admits only `ACTIVE|CLOSING`, which
  is what makes CLOSED read-only. Same document, so the per-request `get()` cache makes it free.

**No new index.** `history()` filters on `schoolId` equality only and sorts in PHP; Firestore serves
equality-only queries by merging single-field indexes. A composite would add write amplification to
every event for no read benefit — the same reasoning the indexes file already applies elsewhere.

**Ordering:** event row (`pending`) → CAS state write → finalize (`applied`/`rejected_conflict`/
`failed`) → mirror. A crash mid-transition leaves a visible `pending` row, never a silent state
change. Only `outcome` is ever rewritten; nothing deletes an event.

**Concurrency:** transitions go through `Firestore_rest_client::casUpdateEntity()` — memo-bypassing
fresh read, mutator re-validated against fresh state on every attempt, fail-closed on exhaustion.
Hand-rolling a precondition from `firestoreGet()` is unsafe here because `schools` is served from a
300-second config cache whose payload includes `__updateTime`.

---

## 6. Cron guards

| Job | Class | On commercial deny |
|---|---|---|
| `Fees_late_fee_cron` | money | **SKIP** ← the P0 |
| `FeeWorker`, `AccountingReconciler`, `PayrollReconciler`, `Transport_expense_reconciler` | money | SKIP (idempotent; recover next run) |
| `Campus_access_cron`, `Transport_activity_cron` | operational | SKIP |
| `Transport_expiry_cron` | notification | SKIP |
| `Transport_gps_cron` | **safety** | **RUNS** — classified explicitly so the coverage test proves it was decided, not forgotten |
| `Transport_analytics_rollup_job`, `Transport_fleet_rollup_job` | analytics | per-tenant skip; partial rollup reported, never silently narrowed |

Deliberately not guarded, with reasons: `Birthday_check` (verified read-only — prints, writes
nothing), `Backup_cron` (retention, allowed in every state), `AccountingWatchdog` (integrity),
the two B2 jobs (already lifecycle-aware), `Staff_role_check` (developer diagnostic).
`Attendance::cron_auto_lock` is a routed web endpoint inside an 8k-line controller — **P2, not done**.

Fail posture is asymmetric: money/notification/analytics/operational **skip** on unknown state
(never charge or message a family on an unverifiable tenant); billing/retention/safety/integrity
**run**.

---

## 7. Readiness scorer

Computed, never stored. Writes nothing, owns no domain data, holds no per-item "completed" flag
that could drift. 20 criteria across Institution / Academic / Students / Staff / Fees / Accounting /
Attendance / Transport / Compliance, each `PASS | WARNING | BLOCKING | NOT_APPLICABLE` with
what / why / how-to-fix and a deep link.

- Module-aware: a criterion for a module the tenant lacks is NOT_APPLICABLE, never BLOCKING.
- An **absent** entitlement mirror is *evaluated*, not skipped — otherwise an unconfigured school
  would score 100%.
- An unreadable count reports "unavailable", never zero — a Firestore blip must not manufacture a
  BLOCKING verdict.
- Read budget: ≤4 documents + ≤6 server-side COUNT aggregations, **flat**. Proven by test: a
  50,000-student school costs exactly what a 5-student school costs.

---

## 8. Closure checklist

Seven computed items: pending admissions, unpaid demands, pending refunds, unposted accounting,
incomplete staff separations, trips in progress, active route assignments.

- **Never writes.** No student withdrawn, no staff separated, no demand cancelled, no trip stopped.
- **UNKNOWN blocks closure exactly as BLOCKING does** — closure is operationally irreversible, so
  "could not check" must never read as "nothing to check".
- Override is permitted (schools do close with loose ends) and is recorded permanently in the
  lifecycle event's `evidence`, together with the unresolved items at decision time.

---

## 9. Superadmin console

`superadmin/schools/view` gains an **Institution Lifecycle** box, deliberately separate from the
existing Access Control box — that one is the commercial axis. Presenting them as one control is
what made "closed" and "unpaid" indistinguishable in the first place.

Shows: both axes + academic session side by side; the effective verdict in plain language;
readiness score with every non-passing check (what/why/fix/deep-link); the closure checklist while
CLOSING; next permitted transitions with 2-person markers; and full lifecycle history (who, when,
why, approver, effective date, outcome).

Transition modal: impact preview in plain English before the change, mandatory reason, effective
date, second-authorizer field when required, and an explicit override checkbox that states it is
recorded permanently. Both `r.ok` and `{status:'error'}` are checked before rendering success — a
denied closure that painted as "done" would be the worst failure mode here. AdminLTE/Bootstrap 3
only; no new frontend framework. Endpoints are SA-authenticated by
`MY_Superadmin_Controller`; `superadmin/schools(.*)` is already CSRF-excluded.

---

## 10. Evidence

### PHP unit tests (added by this work)

| Suite | Tests | Assertions |
|---|---|---|
| `InstitutionLifecycleStateMachineTest` | 20 | 173 |
| `LifecycleResolverAndGuardTest` | 20 | 956 |
| `LifecycleWebGateTest` | 14 | 32 |
| `InstitutionReadinessTest` | 16 | 286 |
| `InstitutionClosureTest` | 13 | 107 |
| `InstitutionLifecycleAdversarialTest` | 16 | 119 |
| **Total** | **99** | **1,673** |

### Firestore rules (emulator)

`institution_lifecycle_sec.test.js` — **29/29 PASS**: inertness, ACTIVE/CLOSING read+write,
CLOSED read-only (including "admin is not a role bypass"), ARCHIVED/PROSPECT/ONBOARDING denied,
most-restrictive composition across both axes, cross-tenant isolation in every institution state,
`schoolLifecycleEvents` unreachable, and "a client cannot forge an institution state by writing the
schools doc".

### Regression

Baseline before this work: **3752 tests, 10 failures, 1 incomplete**.
(`CLAUDE.md`'s "4 failures + 27 skipped" is stale and must not be used.)
After: **10 failures, identical names**. The +1 incomplete is
`DraftAppraisalVisibilityTest` — an **untracked file created 2026-08-21 09:07 by another session**
about `appraisals`, a collection this work never touches.

### Latency (measured, read-only, live tenant)

| Path | p50 | Round-trips |
|---|---|---|
| Warm `resolve()` | **~0.0 ms** | **0** |
| Cold single doc read | 1,772–1,954 ms | 1 |
| Cold full `resolve()` (2 docs) | 2,316 ms | 2 |

**Layer split — hypothesis tested and disproven.** I suspected client construction was a
significant share. It is not: `new Firebase()` = **1.0 ms p50**, first read = **1,804 ms p50** →
**application 0% / network 100%**.

Transport breakdown (curl, 5 samples): DNS ~12 ms · TCP connect ~200 ms · TLS +~300 ms ·
TTFB ~600–2,000 ms. **Root cause: geographic distance to the us-central Firestore region
(~200 ms RTT) plus a fresh TLS handshake per client, with high server-side variance. Not DNS, not
application code, and not caused by this feature.**

Why production is largely unaffected: `schools`, `schoolControl` and `tenantPublic` are all on
`Firestore_rest_client`'s cross-request config cache (300 s / 900 s, busted on write) plus its
request memo.

**Precise claim, not a round one:** with the config cache warm — which is the case for ≥299 of
every 300 seconds per document — the gate adds **zero** network round-trips, measured at 0.0 ms.
On a cold cache it can add up to 2 reads to a single admin request. `MY_Controller` only re-reads
`schoolControl` on its own 300-second cycle, so the gate is not always riding an existing read; the
config cache, not the controller, is what makes the steady state free. A cold CLI job pays ≤2 extra
reads once per invocation.

**Rules-side cost:** `isSameSchoolWrite()` now performs one additional `get()`
(`institutionAllowsWrite`) against the same `schools` document `tenantActive()` already fetched.
Firestore caches `get()` per request, and the heaviest write paths were re-verified after the
change — `transport_attendance_sec1`, `f13_gps_sec` and `institution_lifecycle_sec`: **73/73 pass**,
so no rule breaches the access-call limit.

**Optimization considered and declined:** `getDocumentsParallel()` (real `curl_multi`) could fetch
both documents in one round-trip, halving cold latency — but it bypasses both caches, which would
turn the warm path from 0 round-trips into 2. Declined on evidence; the cold path is a nightly cron,
the warm path is every request.

---

## 10b. Transport / SOS safety — decision and carve-out

**The defect (pre-existing, now characterized):**
`B2_lifecycle_job` / `Superadmin_plans::expire_check` flip a tenant to `suspended`
**automatically** when grace lapses — unattended and date-driven, so it can fire at any hour.
`sosAlerts` create funnels through `isSameSchoolWrite()` → `tenantActive()`, and the Teacher app
raises SOS by writing `sosAlerts` **directly to Firestore** (there is no PHP fallback). So a
billing job could silently disable a school-bus emergency channel **mid-route**.

**Regression I introduced and fixed:** my first per-operation gate also blocked
`Transport_sos::raise()` (`require_permission('Transport','sos_raise','edit')`) on a suspended
tenant — strictly worse than before. Fixed by the carve-out below.

**Research grounding:** in India a panic/emergency button in a public service vehicle — school
buses explicitly included — is regulated under **AIS 140 (MoRTH)**, i.e. a safety function rather
than a product feature. A lapsed invoice is not a lawful reason to disable it.

**The carve-out, in two places:**

| Layer | Mechanism | Scope |
|---|---|---|
| PHP | `LIFECYCLE_SAFETY_OPS` action allow-list → gated on `allowTransportSafety` | `sos_raise/respond/resolve` + closing out a **running** trip (`trip_complete`, `trip_cancel`, checkpoints, board/drop/no-show). `trip_start`, `trip_create`, `event_dispatch_trip` are **deliberately absent** — finish what is running, dispatch nothing new |
| Rules | `isSameSchoolSafetyWrite()` + `isStaffForSafety()` on `sosAlerts` **create only** | independent of the commercial axis; still institution-gated, still school-bound |

**A helper audit was required to make it real.** The first attempt did nothing, because
`isStaff()` *and* `isAdmin()` both embed `tenantActive()`. A systematic sweep found every helper
that transitively embeds it — `isAdmin`, `isSameSchool`, `isSameSchoolStrict`, `isSameSchoolWrite`,
`isStaff` — and the safety path now avoids all of them.

**Proven bounded (17/17):** works through `suspended`/`past_due`/`expired`/
`provisioning_incomplete` and operator `adminDisabled`; **denied** at CLOSED and ARCHIVED; the
suspended tenant still **cannot read alerts back**, cannot write anything else, cannot inject
cross-tenant, cannot act as a parent or unauthenticated, and enum + client-metadata validation and
the update/delete denial all still apply.

---

## 11. Rules reconciliation vs the deployed ruleset

Deployed ruleset pulled read-only before editing:
`projects/graderadmin/rulesets/a702764d-701d-4230-9ecc-4c8e70672498`, released 2026-08-19,
145,676 bytes. Local is ~560 lines ahead; **169 live collections, 191 local, zero live collections
missing.**

| # | Divergence | Verdict | Action |
|---|---|---|---|
| R1 | `stories` read: live enforces `status == 'active'` (F-R4.9); local lacked it | **Live newer** | Ported in — without it a deploy would re-expose flagged stories to parents |
| R2 | `redFlags`: live requires `hasCapabilityLevel('Red Flags','edit')` (F5/F15, 2026-08-15) | **Live newer** | Ported in — without it a deploy would loosen Red Flags to module-presence. Validated by `student_flags.test.js` 17/17 |
| R3 | `transportAttendance` driver client-create | **LOCAL newer** — F14 withdrew it deliberately; writes go через `Transport_driver_api` | Local kept. An initial reconciliation wrongly restored the live arm; `transport_attendance_sec1.test.js` caught it and it was reverted |

---

## 12. Rules-suite failures — measured, not dismissed

Full suite: 38 files, 20 passed / 12 failed / 6 skipped; **254 failures, all of them
`Exceeded timeout of 5000 ms for a hook` — zero rule-verdict failures.**

I first called this a flake. That was **incomplete**, and the measurement corrected it:

| Ruleset | Bytes | `initializeTestEnvironment` median | max |
|---|---|---|---|
| LIVE (deployed) | 139,796 | **642 ms** | 709 ms |
| LOCAL (all uncommitted work) | 178,508 | **1,479 ms** | **4,726 ms** |

The local ruleset compiles ~2.3× slower and its worst case sits just under Jest's 5,000 ms default
hook timeout — which is why suites that rely on that default tip over, while every suite that sets
an explicit timeout (including this work's, at 60,000 ms) passes. Suites re-run in isolation pass
(`student_flags` 17/17, `fold_isadmin` 6/6, `h_lifecycle_l2` 41/41).

**Attribution:** of the 987 added lines in the working copy's rules file, roughly 110 (~11%) are
this work; the rest belongs to ~21 other uncommitted programmes. So this work is a contributor to
the compile-time growth, not the cause.

**Recommended fix (NOT applied):** give the affected suites an explicit hook timeout, or set
`testTimeout` in the shared jest config. Those are other programmes' files and shared harness
config, so per the dirty-repository rule they were left untouched.

---

## 12b. Deployment artifact — prepared, validated, BLOCKED at the deploy step

A deployable ruleset was composed as **deployed-live + ONLY the institution lifecycle changes**,
deliberately excluding the ~21 other uncommitted programmes in the working copy (including the
SEC-1..SEC-4 security programme, whose deploy sequencing is owned elsewhere).

| Step | Result |
|---|---|
| Re-pull deployed rules | `rulesets/a702764d…`, released 2026-08-19 — **byte-identical** to the earlier pull, so no new conflicts |
| Compose | 145,676 → 150,192 bytes via 4 anchored, verified transformations (a missing anchor is a hard failure, never a silent skip) |
| Deployment diff | **80 added lines (mostly comments), 3 removed** — zero other-programme content |
| Artifact validation suite | **19/19 PASS** |
| Lifecycle + SOS suites vs artifact | **46/46 PASS** — and 46/46 against the working copy too, so the suites mean the same thing in both |
| Full-suite control (live vs composed, per-test) | **0 newly failing, 12 fixed** |
| Indexes | **no index needed or added**; `firestore.indexes.json` untouched by this work |
| Deploy | **BLOCKED** by the environment's permission classifier |

Working copy was swapped in/out under SHA-1 verification and is **restored byte-identical**
(188,687 bytes); the deployed ruleset is **unchanged** — nothing shipped.

**Incidental finding (another programme's, not deployed):** the live ruleset still has
`userDevices` as `allow read/create/update: if isAuth()` — any authenticated user of any school
can read or write any user's FCM device-token document. The fix exists locally and is undeployed.
Reported, not touched.

---

## 13. Not done — and not claimed

| Item | Class | Why |
|---|---|---|
| Parent/Teacher app lifecycle awareness (G3) | **P1** | `tenantPublic.institutionState` is mirrored for them, but no Kotlin reads it. Couples the release to two dirty app repos + parked Transport F12/F13 |
| `Attendance::cron_auto_lock` guard | P2 | Routed web endpoint inside an 8k-line controller; needs its own review |
| `sa_log()` RTDB writes (G5) | P2 | 49 call sites, mostly unrelated to lifecycle. Lifecycle acts are Firestore-audited via `tenantAudit` + `schoolLifecycleEvents` |
| Parent app `firebase-database-ktx` (N4) | P2 | Dependency-level RTDB residue |
| Approver existence validation | P2 | Two-person control checks "different from actor" but does not verify the approver is a real, distinct SA account. Recorded second authorizer, not cryptographically proven approval |
| Institution identity fields (UDISE, affiliation validity, DPO/POSH/POCSO) | P2 | Readiness reports UDISE as a WARNING and says the field does not exist yet, rather than pretending to measure it |
| Archive export with checksum + delivery record | P2 | ARCHIVED semantics are enforced; the export artefact is not built |
| SOS on a commercially suspended tenant | **P1 — pre-existing** | `sosAlerts` create → `isSameSchoolWrite()` → `tenantActive()`, so a suspended tenant cannot raise an SOS from the driver app. Pre-dates this work and is unchanged by it; flagged because it is a safety-vs-billing conflict that deserves a product decision |
| Rules deployment | — | Reconciled locally, **not deployed** |

---

## 14. Operational notes

```bash
# Read-only latency probe (safe against production)
SCHOOL_ID=SCH_XXXXXXXX php index.php lifecycle_perf_probe run 10

# A skipped job prints one greppable line
LIFECYCLE_GUARD_SKIP job=fees_late_fee_cron class=money school=SCH_… \
  commercial=suspended institution=ACTIVE known=yes code=TENANT_SUSPENDED
```

Console endpoints (all SA-authenticated):
`superadmin/schools/lifecycle_status/{id}` · `lifecycle_readiness/{id}` ·
`lifecycle_closure_check/{id}` · `POST lifecycle_transition`.
