# Master Timetable — Switchover Readiness and Activation Protocol

**Status: CANONICAL SWITCHOVER IMPLEMENTATION-READY.**
**NOT YET SWITCHOVER-SAFE / PRODUCTION-READY.**

The repository-level work is complete and verified. Two load-bearing safety
boundaries remain open in the ACTIVE system, and both can damage live
teacher- and parent-visible data after activation:

> **A.** ~~The session rollover tool can still overwrite a projected canonical
> timetable after projection.~~ **CLOSED 2026-08-31** — fix applied in the
> working tree under owner authorisation, proven by execution and mutation
> (§4). **Uncommitted and unmerged**; the rollover tool's owner still reviews
> and merges it.
>
> **B.** The deployed Firestore rules still allow staff clients to create,
> modify and delete timetable documents, including projected ones (§5).

Both sit outside this programme's authorisation. Evidence, an exact patch, a
candidate ruleset and regression tests are prepared below; the changes belong
to their owners.

Until BOTH are resolved and verified, the system is **READY FOR COORDINATED
SWITCHOVER** — and the school's published canonical timetable is **NOT YET the
production-active source displayed to teachers and parents**.

Nothing here has been executed against production. No cron is scheduled.

Prepared by the Timetable Consumer Integration / Switchover Readiness audit.
Nothing was committed, pushed or deployed.

---

## 1. The authoritative data flow, boundary by boundary

| # | Boundary | State | Evidence |
|---|---|---|---|
| 1 | Period scheduling → `timetableSettings` | production-active | existing suite |
| 2 | Subject assignment → `subjectAssignments` | production-active | Subject Assignment module closure |
| 3 | Planning / generation → candidate | implemented, tested by execution | `TimetableGenerationRunTest`, `TimetableMultiCandidateTest` |
| 4 | Validation → verdict | implemented, tested by execution | `TimetableValidationTest` |
| 5 | Publish → `timetableVersions` / `timetableVersionDays` | implemented, executable, **routed** (`/timetable/*`) | `TimetablePublishTest`; routes in `routes.php:430+` |
| 6 | Resolve version in force for a date | implemented, tested by execution | `TimetablePlanResolutionTest` — `resolved` / `none` / `ambiguous` / `unreadable` |
| 7 | Projection → `timetables` | implemented, tested by execution, **NOT scheduled, NOT production-active** | `TimetableProjectionTest` (26 tests), `TimetableProjectionCronTest` (15 tests) |
| 8 | `timetables` → Teacher App | implemented, consumer contract proven by execution; **device verification pending** | `TimetableConsumerContractTest`, `ClassSectionKeyContractTest`, `TimetableOwnershipTest` |
| 9 | `timetables` → Parent App | implemented, consumer contract proven by execution; **device verification pending** | `TimetableConsumerContractTest`, `ClassSectionKeyContractTest` (parent) |

**The only gap between published authority and the apps is boundary 7's
activation.** Boundaries 1–6 and 8–9 are complete.

## 2. Writer inventory for `timetables` (pinned by test)

| Writer | Role | Status |
|---|---|---|
| `Timetable_plan_store::projectPublishedVersion` | the sanctioned writer | apply requires `APPLY=1` (CLI only) |
| `Timetable_service` (3 sites) | retired legacy editor | `_refuseLegacyWrite()` throws unconditionally before every write |
| `Timetables_reconcile.php` | repair tool | skips projected documents |
| `scripts/rollover_academic_session.php` | session rollover | **live second writer — see §4** |
| Teacher App / Parent App | consumers | **zero writers** (verified by grep in both repos) |

Pinned by `TimetableWriterAuthorityScriptsTest` across `application/` **and**
`scripts/` — the existing guard walks `application/` only.

### "Sanctioned writer" does NOT mean "only code path capable of writing"

Read this before activation. Four things can physically write this collection
today; only one is sanctioned. What stops each of the others differs, and the
difference decides how much you can rely on it:

| Writer | Stopped by | Can it race the projection? |
|---|---|---|
| `Timetable_plan_store` (projection) | — sanctioned | it IS the writer |
| `Timetable_service` (legacy editors) | **architecture** — unconditional throw in PHP before every write | no |
| `Timetables_reconcile.php` | **architecture** — skips any document carrying `projectedFromVersion` (verified at line 182) | no |
| `scripts/rollover_academic_session.php` | **architecture (as of the gate-A fix)** — `Rollover_projection_guard` refuses projected and unreadable destinations | no, once merged |
| Teacher / Parent apps | **code only** — zero write calls in either repo; the deployed RULES still permit client writes (TT-D1) | not today, but nothing prevents it |

So the single-writer guarantee is currently held by **code discipline and
operator sequencing**, not by the platform. Gates A and B are exactly what turn
it into an enforced guarantee.

## 3. Projection properties (all proven by execution)

Single sanctioned writer · idempotent (a second run of the same version writes
nothing, the version counter does not drift) · safe to retry after a partial run ·
partial failure reported honestly · unreadable legacy collection refuses rather
than writing blind · only a *published, complete* version projects · provenance
stamped (`projectedFromPlan` / `projectedFromVersion` / `projectedAt`) · tenant
and session isolation (5 tests) · orphans reported, never deleted · **no HTTP
handler can apply a projection** (pinned: every controller call is the dry-run
form).

## 4. Session rollover — reconciliation matrix

Proven by running the real projection planner (`TimetableRolloverReconciliationTest`).

| Carried state | Published version covers it? | Outcome | Class |
|---|---|---|---|
| nothing carried | yes | created, counter starts at 1 | safe |
| copy | yes | overwritten by the published version | safe, reconciled |
| blank | yes | overwritten | safe, reconciled |
| copy | no | **orphan — stays visible to teachers and parents** | orphan-producing, recoverable |
| blank | no | **orphan — an empty day stays visible** | orphan-producing, recoverable |
| already projected, same version | yes | unchanged | safe retry |
| already projected, newer version | yes | rewritten, provenance follows the new version | safe |
| already projected, newer version drops section | no | **orphan** | orphan-producing |
| no canonical version published at all | — | cron skips; apps show whatever was carried | school-policy dependent |
| **rollover run AFTER a projection** | — | **projected document replaced by last year's copy** | **destructive — see below** |

### The destructive ordering — FIXED (was: proven structurally)

**Closed 2026-08-31.** The decision now lives in
`application/libraries/Rollover_projection_guard.php`, a pure function the
timetable carry calls. It refuses two destinations the old rule admitted:
a document carrying `projectedFromVersion` (canonical-derived state), and a
destination whose read FAILED (not knowing is not permission — the unreadable
case is counted as `failed`, not `skipped`, so the operator sees work left
undone). Provenance is checked BEFORE the rollover marker, because the
projection merges: a carried document later projected over keeps its marker and
gains provenance, and the canonical reason is the true one to report.

Proven by execution: `RolloverProjectionGuardTest` (10 tests). Attacked by
mutation: **7 mutants, 7 killed** — including reordering the checks, accepting a
blank provenance marker, and reverting the tool to decide inline.

**Still required from the owner:** review and merge. The change is in the
working tree only — nothing committed, nothing deployed.

<details><summary>The original hazard, for the record</summary>


`scripts/rollover_academic_session.php` skips a destination document only when it
carries `phase3RolloverRev`. A projected document does not carry that marker, and
the write is `setDocument()` **without merge** — a full replace. So running the
rollover after a version has been published and projected for the new session
replaces this year's published timetable with last year's copy, provenance and
all, on every screen. The pre-read that decides whether to skip is also swallowed,
so an unreadable destination produces the same overwrite.

**Minimal fix (owner of the rollover tool):**

```php
// skip a document the projection authored
if (is_array($existing) && (string) ($existing['projectedFromVersion'] ?? '') !== '') {
    $stats['timetables']['skipped']++;
    continue;
}
```

and treat a failed pre-read as *do not write* rather than *not yet rolled*.

</details>

**A note on how the fix was verified, because it matters.** The two hazard tests
were written to FAIL when the fix landed. They did not — they asserted the
absence of a string in the SCRIPT, and the fix moved that string into a library,
so they kept passing while testing nothing. A hazard test must pin the
behaviour, not the place the behaviour lives. Both were rewritten to assert the
safe behaviour through the guard, and the mutation battery confirms they now
fail when the guard is broken.

### Rollback is safe against canonical-derived state

`--rollback --confirm` deletes documents in the target session, but only those
carrying `phase3RolloverRev` at or above the current revision — that is, only
the ones the rollover itself created. A projected document carries provenance
and no rollover marker, so it is skipped as "not phase3-rolled". **Rollback
cannot delete a projected canonical timetable.** (Proven structurally from the
rollback branch; the same marker that makes the carry path dangerous makes the
delete path safe.)

### Rollover is already school-configurable

`--timetables=carry|blank|skip` (default `carry`), validated against an allowlist,
dry-run unless `--apply`, with `--rollback --confirm`. Source-session documents are
never modified. Staged per-section transitions are expressible through the
canonical side instead: publish a version covering only the sections that have
moved; the rest keep their carried documents until a later version covers them.
**No further configuration is required and no developer default was invented.**

## 5. Firestore rules — the exact owner-coordinated change

**Current (deployed):**

```
match /timetables/{docId} {
  allow read:   if isSameSchool();
  allow create: if isStaff() && isSameSchoolWrite();
  allow update: if isStaff() && isSameSchool() && isSameSchoolWrite();
  allow delete: if isStaff() && isSameSchool();
}
```

**Gap (TT-D1, open):** any staff account — including a driver, peon or security
guard — can create, rewrite or delete a school's timetable through the client SDK.
After the switchover that also forks the derived copy away from the canonical
version it mirrors.

**Required end state:** `allow read: if isSameSchool(); allow write: if false;`
The projection writes through the Admin SDK, which bypasses rules, so the sole
legitimate writer is unaffected. Both apps are read-only.

**Conflicting assertions to flip at the same time (3):**

1. `tests/timetable_sec.test.js` — *"same-school staff CAN create a timetable stamped with their own school"*
2. `tests/homework_authorship.test.js` — *"TT-D1 OPEN: ordinary staff can still DELETE a timetable"*
3. `tests/homework_authorship.test.js` — *"TT-D1 OPEN: ordinary staff can still CREATE a timetable"*

Every other P0 #4 assertion (the cross-tenant denials) still passes under the
tightened rule.

**Candidate + proof:** `firestore.rules.timetable_candidate` and
`tests/timetable_projection_authority.test.js`, which runs both rulesets against
the real engine. `firestore.rules` itself is untouched — it carries another
programme's uncommitted work.

## 6. Activation sequence (authorised operator)

Preconditions, all already true and evidenced above: legacy writers refuse; the
projection is the only sanctioned writer; both apps are read-only; the consumer
key contract is pinned on all three sides.

1. Confirm the school has a **published** version whose effective date covers today.
2. Dry-run the projection and read the plan:
   `SCHOOL_ID=… SESSION_YEAR=… php index.php timetable_projection_cron run`
3. Review `writes` and, especially, `orphans` — carried or dropped sections still
   visible to teachers and parents.
4. Apply: same command with `APPLY=1`.
5. Re-run the dry run — it must now report **no writes** (idempotency in production).
6. Schedule daily shortly after midnight, so a version that becomes effective today
   is materialised before the school day starts.
7. Decide the orphan clean-up separately, through `Timetables_reconcile`, as an
   authorised step. **Do not delete orphans as part of activation.**
8. Coordinate the rules tightening in §5 with the P0 #4 owner.
9. Fix the rollover ordering hazard in §4 **before** the next session rollover.

## 7. Human / device verification protocol

None of the following has been performed. Each requires a real browser, a real
device, or production activation.

| # | Test | Pass criterion |
|---|---|---|
| 1 | Admin publishes a timetable from Master Timetable | publish succeeds; a version appears with the intended effective date |
| 2 | Canonical version verified | version days match what was authored; status `published` |
| 3 | Projection executed by the cron with `APPLY=1` | reports the expected writes; no refusal |
| 4 | Projected `timetables` verified against the version | every section/day matches; `projectedFromVersion` names the published version |
| 5 | Teacher App | each teacher sees **only** their own periods; class, section, subject and times correct; breaks shown once |
| 6 | Teacher App failure states | no timetable → "No timetable yet"; a forced read failure → an error, **not** an empty week; cover shown for today; a republished timetable appears after refresh |
| 7 | Parent App | the student's own class/section only; correct school, session, day/week; correct effective date |
| 8 | Parent App is read-only | no write path from the app; attempts denied by rules |
| 9 | Republish propagation | change → publish → project → Teacher App and Parent App both show the new timetable |
| 10 | Session rollover | run with the school's chosen `--timetables` strategy; confirm §4's matrix row for that strategy, and that a projected document is not overwritten |

Test 6's forced read failure and Test 8 are the two that most need a real device:
the harness proves the code path, the device proves the screen.

---

# 8. OWNER HANDOFF PACKAGE

## 8.1 Owner / action table

| # | Boundary | Owner | Action type | Blocking? |
|---|---|---|---|---|
| 1 | Rollover/projection ordering hazard | Session-rollover tool owner | **owner-authorized code change — APPLIED, awaiting review/merge** | gate A: fix done, merge pending |
| 2 | TT-D1 client writes to `timetables` | Timetable authority owner + P0 #4 owner | **owner-authorized code change**, then **deployment** | **YES — gate B** |
| 3 | Projection cron activation (`APPLY=1`, schedule) | Operations | **operations activation** | YES — gate C |
| 4 | First applied projection verified in `timetables` | Operations | **operations activation** + verification | YES — gate D |
| 5 | Teacher App shows correct teacher/day timetable | QA on device | **human/device verification** | YES — gate E |
| 6 | Parent App shows correct student/class timetable | QA on device | **human/device verification** | YES — gate F |
| 7 | Orphan clean-up after switchover | Timetable authority owner | owner-authorized **operations** step | No — post-switchover |
| 8 | Consumer contracts, key normalisation, read honesty, writer pins | This programme | **repository implementation** | **DONE** |

## 8.2 Rollover patch and its proof

File: `scripts/rollover_academic_session.php`, timetable carry block (~line 375).

```php
// A document the projection authored is canonical-derived state. Carrying
// last year's timetable over it would replace a published timetable with a
// copy, provenance and all, on every teacher and parent screen.
if (is_array($existing) && (string) ($existing['projectedFromVersion'] ?? '') !== '') {
    $stats['timetables']['skipped']++;
    if ($VERBOSE) echo dim("  skip (projected from a published version): {$dstId}\n");
    continue;
}
```

And the swallowed pre-read must stop meaning "not yet rolled":

```php
try { $existing = $fs->getDocument('timetables', $dstId); }
catch (\Throwable $e) {
    // Unreadable destination: do NOT write. Treating this as "no marker,
    // therefore not yet rolled" turns a network blip into an overwrite.
    $stats['timetables']['failed']++;
    continue;
}
```

**Tests that prove it:** `tests/Unit/TimetableRolloverReconciliationTest.php`

- `test_hazard_rollover_does_not_yet_protect_a_projected_document` — asserts today's unsafe state. **Expected to FAIL when the patch lands**; flip its middle assertion to `assertStringContainsString('projectedFromVersion', $code)`.
- `test_hazard_a_failed_pre_read_is_read_as_not_yet_rolled` — same; remove or invert once the catch stops continuing to the write.
- The eight matrix tests in that file must stay green throughout — they encode carry/blank/orphan/retry semantics the patch must not disturb.

**Gate A passes when:** both hazard tests are updated to assert the safe behaviour and the whole file is green.

## 8.3 Firestore rules change and the three coordinated assertions

Replace the write arms in `firestore.rules`:

```
match /timetables/{docId} {
  allow read:  if isSameSchool();
  allow write: if false;
}
```

The projection writes through the Admin SDK and bypasses rules, so the sanctioned
writer is unaffected. Both apps are read-only (verified by grep in both repos).

**Three assertions requiring deliberate, simultaneous update:**

1. `firebase-rules/tests/timetable_sec.test.js` — *"same-school staff CAN create a timetable stamped with their own school"* → `assertFails`
2. `firebase-rules/tests/homework_authorship.test.js` — *"TT-D1 OPEN: ordinary staff can still DELETE a timetable"* → `assertFails`
3. `firebase-rules/tests/homework_authorship.test.js` — *"TT-D1 OPEN: ordinary staff can still CREATE a timetable"* → `assertFails`

Every other P0 #4 assertion (the cross-tenant denials) passes unchanged.

**Prepared evidence:** `firebase-rules/firestore.rules.timetable_candidate` and
`firebase-rules/tests/timetable_projection_authority.test.js`, which runs both
rulesets against the emulator — deployed 8/8 (gap recorded), candidate 8/8.

**Gate B passes when:** the rule is applied to the shared file with the three
assertions updated, the emulator suite is green, and the change is deployed.

## 8.4 Activation order — and what must not be reordered

| Step | Type | Must precede |
|---|---|---|
| 1. ~~Apply rollover patch~~ **DONE** — review and merge it | owner-authorized code change | everything |
| 2. Apply TT-D1 rule + 3 assertions; emulator green | owner-authorized code change | step 3 |
| 3. Deploy rules | deployment | step 4 |
| 4. Confirm a published version covers today | operations | step 5 |
| 5. Dry-run the projection; review `writes` **and `orphans`** | operations | step 6 |
| 6. Apply with `APPLY=1` | operations activation | step 7 |
| 7. Re-run dry run — must report **no writes** | operations | step 8 |
| 8. Schedule daily, shortly after midnight | operations activation | — |
| 9. Teacher App + Parent App device checks | human/device verification | sign-off |
| 10. Orphan clean-up via `Timetables_reconcile` | owner-authorized operations | after sign-off |

> **THE DANGEROUS SEQUENCE.** publish canonical → activate projection → later
> run the rollover without the step-1 patch → **the projected timetable is
> replaced by last year's copy on every teacher and parent screen.** This is
> not hypothetical and not rate-limited by anything: the rollover tool has no
> knowledge of projection provenance. Step 1 is a prerequisite for activation,
> not a follow-up to it.

**Ordering constraints that are not stylistic:**

- **Step 1 before any rollover runs, ever.** A rollover executed after step 6 without the patch silently replaces the published timetable. This is the single most dangerous reordering.
- **Step 2 before step 3**; deploying the rule without updating the three assertions leaves the suite red and the change indistinguishable from a regression.
- **Step 5 before step 6.** The dry run is the only chance to see orphans before teachers do.
- **Step 7 before step 8.** Scheduling before proving idempotency in production risks a nightly job that rewrites documents every run.
- **Step 10 last, and never inside activation.** Deleting live teacher-visible schedules during a switchover is the hidden destructive step this architecture exists to avoid.

## 8.5 Go / no-go gates

**GO only when all seven hold:**

| Gate | Condition | Type |
|---|---|---|
| A | Rollover hazard fixed **and verified** — DONE in the working tree (10 guard tests, 7/7 mutants killed); **merge still required** | owner-authorized code change |
| B | TT-D1 resolved **and verified** (rules deployed, emulator green) | code change + deployment |
| C | Projection activation authorized | operations activation |
| D | Projection applied successfully; re-run reports no writes | operations activation |
| E | A published canonical timetable confirmed present in `timetables` | operations verification |
| F | Teacher App confirms the correct teacher/day timetable | human/device verification |
| G | Parent App confirms the correct student/class timetable | human/device verification |

**NO-GO while any gate is open.** Until all seven pass, the state is
**READY FOR COORDINATED SWITCHOVER**, and the school's published canonical
timetable is **NOT** the production-active source shown to teachers and parents.

---

# 9. GATES C–G — REPOSITORY CLOSURE (2026-08-31)

Gates C, D and E were written as **operations activation** steps against the
live tenant, and F and G as **device verification**. None of those can be
performed from this workstream, and none is claimed here. What this section
records is the different and prior question that had never been asked: whether
the code those gates would activate is correct.

It was not. Six defects were found, four of them by executing the real code
against a Firestore emulator rather than by reading it.

## 9.1 How gates C–G were verified without touching production

`scripts/timetable_projection_runtime_proof.js` seeds a **local Firestore
emulator** with the Admin SDK, invokes the **real CLI controllers** through the
**real** `Firestore_rest_client` (which routes to the emulator when
`FIRESTORE_EMULATOR_HOST` is set), and then reads the documents back and
asserts on the persisted state. It refuses to run against anything but
loopback, and the PHP client refuses independently.

**55 assertions, all holding**, covering: dry-run honesty, apply, idempotency
across a second apply, orphan reporting, field-for-field projection fidelity,
legacy-metadata preservation, tenant and session isolation, fail-closed on an
uncovered date, the repair tool's refusal to touch projected documents, the
session rollover including the dangerous publish→project→rollover sequence, and
the exact query shapes both apps issue.

## 9.2 Defects found and closed

| id | what it was | how it was found | consequence if shipped |
|---|---|---|---|
| **TT-C2** | `_existingLegacyDocs()` read `{id,data}` query rows as bare documents, so the tenant re-check rejected **every** row and the map was **always empty** | runtime proof | projection never idempotent — rewrote every document every run; `orphans` structurally always empty; legacy `version` reset to 1. **Gate D's acceptance test could never have passed.** |
| **TT-C4** | a document whose content already matched was skipped, so it never received `projectedFromVersion` | exposed once TT-C2 was fixed | the documents that were *already correct* were the only ones **no guard protected**: the rollover would carry over them, the reconciler would edit them |
| **TT-C1** | `Timetables_reconcile` read `timetables` with the swallowing `firestoreQuery`, and scanned with a silent 2000 cap | census | a failed read reported as "no drift found" — a clean bill of health from a tool that saw nothing |
| **TT-C7** | the "documents were skipped" notice was nested inside the `manuallyEdited` branch | census | the reconciler silently declined work unless unrelated conditions held |
| **TT-D2** | `substitutes` and `timetableSettings` had `allow read: if isAuth()` | rules census | **any signed-in user of any tenant** could read every school's daily staff-absence records — naming absent and covering teachers — and its bell times |
| **TT-D3** | the `timetableSettings` carry read source and destination in **one** `try`, so either failure nulled both | census after gate A | a failed destination read skipped the already-rolled check and overwrote; a failed source read printed "no source doc" |
| **TT-D4** | the rollover copied `projectedFromVersion` into the carried document | runtime proof | a mere copy claimed to be canonical-derived, in a session that may have no published version — and became **unrepairable** by the reconciler |
| **TT-D5** | the rollover's destination read of `timetableSettings` was served from the **cross-process 300s file cache** | runtime proof (observed live) | the overwrite decision made against a document state up to five minutes stale |
| **TT-D6** | **every** rollover carry read its destination with `getDocument()`, which swallows all non-404 failures | investigation of TT-D5 | `$readFailed` was permanently false, so **gate A's unreadable-destination protection was unreachable from the only caller that mattered.** The guard was correct and never asked. |
| **TT-F1** | the Teacher app built the timetable doc id with a **`/`** and raw class/section | consumer census | `/` is a path separator — `document()` **throws**, so the lesson-plan period guard failed for every teacher and every save ended in "The timetable for this day could not be read" |

## 9.3 Why the tests did not catch TT-C2

They tried to. `test_projecting_twice_writes_nothing_the_second_time` and
`test_orphans_are_reported_and_left_alone` existed, were correctly written, and
passed — because the double returned query rows **flat** while the real client
returns `{id, data}` envelopes. The double was easier to work with than the
thing it stood in for, and that difference was the blind spot.

The double is now faithful. With it, those two pre-existing tests **fail against
the pre-fix code** — verified by reverting the fix and re-running.

## 9.4 Gate status after this work

| Gate | Repository | Still required |
|---|---|---|
| A | closed; now **actually wired** (TT-D6) and proven at the persistence boundary | owner review/merge |
| B | closed | owner acceptance; **deploy** |
| C | the blockers to activation are closed — the cron is idempotent, fails closed, is tenant- and session-scoped, and reports orphans | **operations authorisation** |
| D | proven on the emulator: apply, then re-run reports **no writes** and no `version` drift | the same run against the live tenant |
| E | proven field-for-field, with provenance, tenant- and session-isolated | operations verification on the live tenant |
| F | the Teacher app's read paths verified against persisted state; **TT-F1 fixed** | **device verification — not performed, not claimed** |
| G | the Parent app's query shape verified against persisted state | **device verification — not performed, not claimed** |

## 9.5 Deployment ordering — one new hard constraint

**The app builds must ship before the TT-D2 rules change is deployed.**

The Teacher dashboard's substitutes query is scoped by `schoolId` only in the
**uncommitted** working tree. The released build still queries by `date` alone.
Deploying the tightened rule first denies that query outright — proven on the
emulator, in both directions — and empties the cover notice for every teacher
still on the old build.

Order: merge and release the apps → deploy rules → then the activation sequence
in §8.4.


---

# 10. RECONCILIATION — THE HTTP PUBLISH PATH (2026-08-31)

An earlier statement in this programme — "no HTTP handler applies the
projection" — was **too strong, and it is withdrawn here.** It came from a test
that checked only whether `Timetable_lifecycle.php` called
`projectPublishedVersion(..., true)` *directly*. It does not. But it reaches the
same writer one level down.

## 10.1 The traced chain

```
POST /timetable_lifecycle/publish          CSRF on; not in csrf_exclude_uris
  └─ MY_Controller                          session auth; $this->school_id,
                                            $this->session_year from the session
                                            + custom claims — never caller-supplied
  └─ Timetable_lifecycle::publish()
       require_permission('Academic', 'timetable_publish', 'manage')
  └─ Timetable_plan_store::publishCandidate(planId, versionId, from, to)
       ├─ validateVersion / getVersion / status guard (draft|candidate only)
       ├─ readCompleteCandidate()            refuses an incomplete generation
       ├─ currentVerdict()                   refuses an unchecked or stale check
       ├─ _publishedOverlaps()               DETECTS and REPORTS; never forbids
       ├─ firestoreSet(timetableVersions)    status := published   ← no precondition
       └─ if in force today AND no overlaps:
            projectPublishedVersion(plan, version, TRUE)
              └─ firestoreSet(timetables, …)  ← the collection both apps read
```

**Q1 — Can an HTTP request cause `timetables` documents to be written? YES.**
Executed, not inferred: a publish through the real `publishCandidate()` reports
`projection: {attempted: true, applied: true, written: N}` and N documents
appear in `timetables`.

**Q2 — Is it intentional?** Yes, and deliberately so. The code states the
reason: *"A newly onboarded school publishes and expects teachers to see it. It
must not have to know that a cron exists."* The cron keeps the one job
publishing cannot do — materialising a **future-dated** version on the day it
becomes effective. The earlier claim is invalidated and has been corrected in
the test that made it (`test_no_controller_applies_the_projection_directly`,
plus a new `test_publishing_is_the_only_http_path_that_applies_it`).

**Q3 — Protections on that path.** Authentication: session + custom claims via
`MY_Controller`. Authorisation: `Academic:manage` — publishing is the step that
changes what every teacher sees, so it is deliberately not `edit`. CSRF: on.
Tenant/session: bound from the session, never from the request body. Content:
four refusals before any write (not draft/candidate, incomplete generation,
unchecked, stale check). Single-writer: **none — see Q4.**

**Q4 — Can two admins publishing concurrently create competing projection
executions? YES, and it is worse than competing — it SPLITS.**

Proven by execution (`scripts/timetable_publish_race_proof.js`): two OS
processes released on one starting gun, entering the real `publishCandidate()`
against the same tenant.

| scale | result |
|---|---|
| 4 cells, 5 runs | both publish; one version wins **entirely**; winner varies |
| **120 cells (a real school), 3 runs** | both publish; **`timetables` ends holding a MIX**: 74/46, 111/9, 2/118 |

The overlap check is a read that happens **before** the other publish's write
lands, so each sees no overlap and proceeds. The projection then writes each
document as an independent REST call — no batch, no transaction — so at real
scale the two write bursts interleave and different classes end up on different
versions. **Teachers and parents see a timetable that no single published
version describes.**

The canonical side is left permanently ambiguous — two published versions with
open-ended, identical dating. `resolveForDate()` correctly reports AMBIGUOUS and
the cron correctly **REFUSES** (verified in the same run), so the split is
**not self-healing**; and there is deliberately **no unpublish**, so the state
cannot be cleared through the sanctioned API at all.

**Q5 — Can HTTP and the cron race? Materially, no.** Two concurrent
applying projections of the same version were executed: every document ended as
the published version says, with no half-written cell, and a further run wrote
nothing. One `version` counter increment was lost (both runs computed from the
same base), which is a counter artefact, not a content divergence. The cron's
real protection is upstream: it refuses whenever the resolution is ambiguous.

**Q6 — Writer inventory, corrected.** The HTTP publish path is a **sanctioned
projection path**, not an anomaly:

| writer | trigger | authority | protection |
|---|---|---|---|
| `publishCandidate()` → projection | **HTTP** `POST /timetable_lifecycle/publish` | canonical → projection, for a version in force **today** | session auth, `Academic:manage`, CSRF, tenant from session; **no publish concurrency control** |
| `Timetable_projection_cron` | CLI, `APPLY=1` | canonical → projection, for a version becoming effective **later** | CLI-only, lifecycle guard, refuses ambiguous/unreadable |
| `Timetables_reconcile` | CLI | legacy repair only | refuses any derived document |
| `rollover_academic_session.php` | CLI | session carry | refuses derived and unreadable destinations |
| every client | — | **prohibited** | `allow write: if false` |

**Q7 — Classification. The race is a BLOCKER, and it is not being fixed here.**

`TT-C8 — concurrent publish splits the projection and strands the canonical
state as ambiguous.` It is not fixed because every available fix is an owner
decision, not an implementation detail:

- forbidding overlapping publishes contradicts a documented house convention
  (*"Overlaps are DETECTED, REPORTED and returned — never silently resolved and
  never forbidden"*);
- a per-tenant publish lock, or a re-check-then-CAS immediately before
  projecting, narrows the window without closing it and changes failure
  behaviour for a legitimate operation;
- and **recovery** needs designing regardless, because there is no unpublish —
  a school that hits this today cannot get out of it through the product.

It blocks **gate C**: authorising a projection activation while two
administrators can split the collection is authorising the failure.


---

# ADDENDUM — TT-C8 integration trail, 2026-09-12

Verified against **HEAD `5136f0d`**, re-derived from source and execution. All
work below is **UNCOMMITTED**. Nothing deployed, no foreign work touched (the
22 staged files are Fees/PayOps and were not modified).

## A1. One defect found and fixed — TT-C8-D1

`Timetable_projection_cron` read `FOR_DATE` straight from the environment with
no validation and no relationship to `APPLY`.

The legacy document id is `{school}_{session}_{sectionKey}_{WEEKDAY}` — **no
effective-date dimension** — so that collection holds exactly one timetable:
the one teachers see now. Therefore:

| invocation | effect |
|---|---|
| `FOR_DATE=<past> APPLY=1` | every teacher shown a timetable that has **ended** |
| `FOR_DATE=<future> APPLY=1` | every teacher shown one **not yet in force** |

Both exited **0**, wrote documents indistinguishable from a correct
projection, and left the canonical screens — the only place that would reveal
it — untouched.

**Fixed:** `APPLY=1` may materialise **today only**; anything else is refused
with exit 2 and a message that distinguishes past from future. `FOR_DATE`
remains fully available for **dry runs**, which is what it is for. This mirrors
`supersedeVersion()`, which already refuses a past last-effective-date for the
same reason.

Pinned by `TimetableProjectionDateGuardTest` (7 tests) — including
counter-tests that dry runs and the default scheduled run are **not** refused,
and a test asserting the *premise* (the legacy id shape), so the rule is
re-derived rather than inherited if that id ever gains a date.
**Mutation: 6/6 caught**, source restored byte-identical.

## A2. Everything else about the cron was verified sound, by execution

Executed against the pure services, covering the scenarios the suite did not:

- **Effective-date transition** needs no flag and no republish (12 Sep → old
  version, 13 Sep → new).
- **Historical dates stay stable** — publishing did not rewrite history.
- **A missed or delayed run is self-recovering.** The cron asks *"what applies
  on this date"*, never *"what changed since last run"*, so a run days late
  still projects the correct answer. There is no catch-up state to lose.
- **7/7 malformed dates refused, never coerced** (including `2026-9-13` and
  ISO-with-time).
- Superseded / draft / candidate versions never materialise; an empty set and
  an unresolved date both leave the legacy documents untouched; ambiguity
  refuses and names the competing versions.

## A3. Scheduling is a TRUE EXTERNAL BOUNDARY — proven, not assumed

This repository **owns no scheduler**:

- Every ZenX cron is `CI_Controller`, CLI-only, **one tenant per invocation**,
  scheduled externally. `Transport_expiry_cron` states the convention outright:
  *"the operator drives per-tenant scheduling until multi-tenant cron"*.
- `application/config/hooks.php` defines exactly one hook, `Debug_logger`.
  There is no request-time scheduling seam.
- Even `Backup_cron`, the one HTTP-callable precedent, documents an external
  crontab line calling it.

Inventing a platform scheduler would contradict the convention and the
architecture. **What the repository can do has been done instead:**

| requirement | state |
|---|---|
| harden the CLI | done — A1 |
| safely rerunnable | **measured idempotent**: 36 writes, then **0** on re-run |
| missed-run recovery | proven — a late run resolves for today correctly |
| health/status diagnostic | **new** — see A4 |
| exact scheduling requirement | below |
| monitoring hook | exit codes (0 ran / 1 env / 2 refused) + the A4 endpoint |

**The exact requirement, per tenant and session, shortly after midnight:**

```
APPLY=1 SCHOOL_ID=<SCH_…> SESSION_YEAR=<2026-27> \
  php index.php timetable_projection_cron run
```

Until that entry exists, a timetable published for a future date becomes live
for the resolver on its date and **stays invisible to the Teacher app, the
Parent app, Substitute and Lesson Planner** until someone runs the command.

## A4. The gap is now VISIBLE where it is created

`Timetable_lifecycle::visibility` (route `timetable/visibility`, `view`) asks
the shared resolver which version is in force on a date, then asks the
projection for a **dry run**. Pending writes > 0 means the legacy mirror
disagrees with the canonical answer.

Surfaced on the **In force** card of the Master Timetable screen — where
publishing happens — as either *"Teachers and parents are seeing this
timetable"* or *"Teachers and parents are still seeing the previous
timetable"*, with the exact tenant-bound command.

It **decides nothing and applies nothing**. Applying stays CLI-only behind
`APPLY=1`; that seam is where a person decides and this does not move it.
Named `visibility`, not `projection_status`, because the screen carries a
deliberate bright line forbidding any `timetable/projection*` call — narrowing
that guard to fit a name would have blunted it.

Pinned by `TimetableVisibilityStatusTest` (8 tests). **Mutation: 7/7 caught**,
including *the dry run becomes an APPLY* and *the tenant becomes
caller-supplied*.

## A5. Consumer census — legacy `timetables`

| consumer | class | date semantics | note |
|---|---|---|---|
| Teacher Workload | **migrated** | resolves canonical by date | see `TeacherWorkloadCanonicalSourceTest` |
| Teacher app / Parent app | C — external repos | read the projection | must stay on the mirror; app release required to change |
| `Academic::get_absent_teacher_schedule` | A — candidate | date-driven cover arranging | 1 caller; stale mirror ⇒ cover arranged against the wrong week |
| `Substitute_service::_dayGridFor` | D — Substitute owner | date-driven | same exposure |
| `Substitute_service::_busyMapFor` | B | weekday only | not date-sensitive |
| `Lesson_plan_service::_resolveTimetableCell` | B | day + period | not date-sensitive |
| `Academic::get_section_timetable` | E | — | **0 callers** |
| `Academic::suggest_substitutes` | E | — | **0 callers** (matches ACAD-D15) |
| `Timetables_healthcheck` / `_verify` | E | — | read-only audits |
| `Timetables_reconcile` | B | — | writer; skips `projectedFromVersion` |
| `School_config` backup/export/purge | B | — | collection inventory only |

Migration is **not** forced for purity: while the apps read the mirror, the
mirror must stay correct, and that is the projection's job. The two class-A/D
candidates above share one exposure — a stale mirror — which the A3 schedule
and the A4 indicator address at the source rather than one screen at a time.

## A6. Performance — measured, not assumed

Against the live tenant (`SCH_B56BB9A401`, 6 timetabled sections):

| | |
|---|---|
| reads | **2 queries, 54 documents** — no N+1 |
| compute (map + diff) | **1.18 ms**, pure |
| writes, first run | 36 |
| writes, second run | **0 — idempotent, measured** |
| orphans | 0 (reported, never deleted) |
| projection size | sections × working days; ~**210 documents** for a fully timetabled 35-section school |

Writes are one document at a time, not batched, so a partial failure leaves the
mirror partly updated — which the cron **reports honestly** and a re-run
repairs, because the projection is idempotent. Recorded as a characteristic;
not changed, because no failure was demonstrated and batching would alter
tested failure semantics.

## A7. Security

- **Canonical collections are client-denied.** Verified in the current rules:
  `timetablePlans`, `timetableVersions`, `timetableVersionDays` and
  `subjectAssignmentSections` are all `allow read, write: if false`. The admin
  panel reads them server-side through the service account, which bypasses
  client rules.
- **Gate B status corrected.** The repo rules now carry
  `match /timetables` → `allow write: if false` (TT-D1, dated 2026-08-31),
  while **HEAD still has `allow write: if isStaff()`**. The fix exists in the
  working tree, is **not committed and not deployed**, and the rules file is
  +1350/−59 versus HEAD — shared with several programmes. It must never be
  committed or deployed wholesale.
- **Tenant identity is never caller-supplied.** The new endpoint takes only a
  date, validated to an ISO shape; school and session come from the
  authenticated server context. Asserted, and mutation-proven.
- **Emulator verification remains environment-blocked** — see A9.

## A8. Clean-HEAD proof — exactly one blocker

An isolated worktree at HEAD, TT-C8 files copied in, **no dependency copied**,
entry points executed:

```
TT-C8 files at HEAD          : all 8 ABSENT (untracked)
Entity_firestore_sync        : PRESENT
Service_exception            : PRESENT
Firebase::firestoreCommitBatchStatus : PRESENT
Firebase::firestoreQueryOrFail       : ABSENT
Firebase::firestoreGetOrFail         : ABSENT

PASS  pure resolver loads + resolves        status=resolved
PASS  projection mapper is pure + loads     docs=1
PASS  validation service loads              HARD=hard
FAIL  plan STORE loads                      Firebase::firestoreQueryOrFail ABSENT
```

**TT-C8's pure layer runs on bare HEAD with zero dependencies.** The only
blocker is `application/libraries/Firebase.php` needing two methods —
`firestoreQueryOrFail` and `firestoreGetOrFail`. That file is **owned by the
platform and currently staged by the Fees/PayOps session**, and its **staged
copy does not contain `firestoreGetOrFail`** — so committing it from the
current index would leave the method stranded. TT-C8 cannot commit that file;
doing so would ship another programme's unreviewed work.

## A9. What is NOT done, and why

| item | boundary |
|---|---|
| Rules emulator suite | **environment** — the JVM OOMs compiling the 200 KB ruleset (`os::commit_memory … paging file is too small`), twice, on a box where another session's emulator holds the memory and must not be killed. A minimal timetable ruleset (6.6% of the file) **does compile**, so TT-C8's own arms are valid. |
| Deploying the TT-D1 rules arm | **authorisation** (gate B) |
| Scheduling the projection | **operator/deployment** — A3 |
| Teacher / Parent app migration | **separate repositories** |
| Committing TT-C8 | **blocked** — A8 |
| Authenticated browser verification | **credentials** |