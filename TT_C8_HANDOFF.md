# TT-C8 — Closure and Handoff

**Status: repository work item COMPLETE. Not committed, not merged, not
deployed, not production-active.**

Branch `integration/phase2`. Verified fresh on 2026-09-01 against HEAD
`4d4c577`. Nothing in this package has run against production.

---

## 1. What TT-C8 guarantees

> **For one school, one academic session, and any given date, at most one
> published timetable version is in force — and it is impossible for two
> administrators, or two plans, or one administrator twice, to make that
> untrue.**

Everything below exists to make that sentence true at the write boundary
rather than hoped for at the read boundary.

## 2. What TT-C8 changes

**Option 2 — one definition of "in force".** `Timetable_plan_service` owns
`coversDate()`, `rangesOverlap()` and `publishedOverlaps()`. `resolveForDate()`
CALLS `coversDate()` rather than carrying a copy, and the publish-time overlap
check delegates to `publishedOverlaps()`. Scope is school + session across ALL
plans. Previously the write side filtered by `planId` while the read side did
not, so publishing an exam timetable over a live regular one reported no
overlap at all and projected anyway.

**Option 3 — aggregate-root optimistic concurrency.** One document per
(school, session), `timetablePublishGuards/{school}_{session}`, carrying no
business data — only a token that changes on every state-changing operation.
Both operations read it FIRST, then read state, then validate, then commit the
version write and the token write in ONE batch with the token as precondition.
No lease, no TTL, no lock, no expiry, no second mechanism.

**Option 4 — an overlapping published timetable is refused.** Not reported.
An overlap has no valid meaning anywhere: the resolver refuses to choose, the
projection holds one document per section per day, and there is no unpublish.

**Option 6 — SUPERSEDE.** `supersedeVersion(planId, versionId,
lastEffectiveDate, reason)` closes the FUTURE applicability of a published,
open-ended version so a successor can be published. Nine preconditions, all
refusing before any write. Writes four fields on one document. Never touches
`status`, content, `effectiveFrom`, or an `effectiveTo` already set.

## 3. What was actually proven, and how

| evidence class | what it covers |
|---|---|
| **Source inspection** | the complete writer set for `timetableVersions`; only `publishCandidate` and `supersedeVersion` can change what is in force — `createDraftVersion` forces DRAFT, `createGenerationCandidate` forces CANDIDATE, `discardVersion` refuses anything but draft/candidate |
| **Unit tests** | 741 timetable/rollover tests, 4,841 assertions |
| **Property proofs** | historical invariance and coverage monotonicity asserted over 241 and 271 CONSECUTIVE dates, not samples, seeded from a deliberately ambiguous state |
| **Emulator execution** | real `Firestore_rest_client` against a real Firestore emulator, through the real CLI/service path |
| **Two-process concurrency** | supersede ∥ supersede, supersede ∥ publish, publish ∥ publish — separate OS processes released on one starting gun |
| **Mutation testing** | **24 mutants, 24 killed, 0 survived, 0 not applied** |
| **Persistence read-back** | the SUPERSEDE audit row read back OUT of `academicAuditLog`, not inferred from a return value |

### The invariants, stated exactly

- **Published content immutability.** `timetableVersionDays` never written.
  On the version document only `effectiveTo`, `supersededAt`, `supersededBy`,
  `supersededReason` may be written; the payload itself is asserted equal to
  the allowlist, so a merge cannot smuggle a field in.
- **Historical invariance.** For every date before today, resolution is
  identical before and after. This rests on the `lastEffectiveDate >= today`
  precondition and on `status` never being touched.
- **Coverage monotonicity.** For every date, `covers_after ⊆ covers_before`.
  SUPERSEDE removes dates from one version's range and touches one version, so
  it is structurally incapable of creating an ambiguity — which is what makes
  it safe as the repair FOR one.
- **One-way dating.** An `effectiveTo` is set once. A repeat is refused
  `already_bounded`. No rollback, no reopen, no unpublish.
- **Concurrency.** Publish and supersede share ONE token, because both change
  what is in force and must serialise against each other, not only against
  themselves.
- **Failure semantics.** Only `SUCCESS` (Firestore_rest_client::COMMIT_SUCCESS)
  is success. `PRECONDITION_CONFLICT` is a retryable refusal. Every other
  outcome — FAILURE, UNKNOWN, empty — is a failure.

### Two defects found by auditing my own work

1. **Guard-ordering (both operations).** The token was read AFTER the version.
   Proven by execution: a supersede re-dated an already-bounded version, and a
   publish re-published an already-published one, each overwriting the other
   actor's dates. Fixed by restoring the authorised order; two mutants now
   guard it.
2. **A vacuous assertion in my own harness.** The probe reported `getCode()`
   for publish refusals — always `0` for `Service_exception` — so every
   code-based assertion on a publish refusal was silently always false.

### Hidden assumptions, stated rather than buried

- `date('Y-m-d')` is **server-local**. "Today" is the server's, not the
  school's. Single-region deployment makes this currently benign; the
  historical-safety proof depends on it.
- The monotonicity proof assumes SUPERSEDE touches exactly one document. That
  is guaranteed only because of the ordering fix above.

## 4. What TT-C8 does NOT solve

None of these block TT-C8; none were touched.

| id | finding |
|---|---|
| **OOS-TT-1** | Broader Master Timetable audit gap. `publish`, `project`, `validate`, `invalidate` are still absent from `Audit_log_service::ACTIONS`, so those transitions still record nothing. Only SUPERSEDE has a working trail. |
| **OOS-CAL-1** | `calendarEvents` write rule is `allow write: if isAdmin()` with **no tenant binding** — an admin of one school can write a holiday into another, and holidays drive Attendance and Payroll. HIGH. |
| **OOS-CAL-2** | Two working-day authorities: staff/payroll resolve through the workforce policy services; student attendance uses a hardcoded Sunday + holidays. |
| **OOS-CAL-3** | ZenX has no authoritative answer to "is date X a teaching day for this school". `Working_calendar_service` is RETIRED (N-36) with no runtime consumer; `Policy_assignment_service` answers a per-EMPLOYEE question. This is why gap analysis was deferred out of SUPERSEDE. |
| **Substitute_service** | Treats UNKNOWN/FAILURE commit outcomes as success (lines ~523, ~626). |
| **Withdrawal** | No authorised mechanism exists to cancel a future-dated version before it ever applies. SUPERSEDE was deliberately not overloaded into one. |

## 5. Deployment requirements — none of these are done

| # | step | type | state |
|---|---|---|---|
| 1 | Commit the TT-C8 change set | repository | **NOT DONE** |
| 2 | Merge to the release branch | repository | **NOT DONE** |
| 3 | Deploy `firestore.rules` (TT-D1 + TT-D2 + the `timetablePublishGuards` deny) | deployment | **NOT DONE** |
| 3a | — and the apps MUST ship first: `isSameSchool()` denies the released Teacher build's unscoped `substitutes` query | ordering constraint | **NOT DONE** |
| 4 | Gate C — authorise projection activation | operations | **OPEN** |
| 5 | Gate D — apply the projection, re-run, confirm no writes | operations | emulator-evidenced only |
| 6 | Gate E — confirm the published version present in `timetables` | operations | emulator-evidenced only |
| 7 | Gate F — Teacher App on a real device | human/device | **NOT PERFORMED, NOT CLAIMED** |
| 8 | Gate G — Parent App on a real device | human/device | **NOT PERFORMED, NOT CLAIMED** |

## 6. Change-set hygiene — what is and is not separable

**Wholly TT-C8 (untracked, no other programme's content):**
`Timetable_plan_store.php`, `Timetable_plan_service.php`, `Timetable_lifecycle.php`,
`Timetable_projection_service.php`, `Rollover_projection_guard.php`, the
timetable test files, and the proof scripts
(`timetable_supersede_proof.js`, `timetable_publish_race_proof.js`,
`timetable_publish_race_probe.php`, `timetable_publish_cas_mutation.php`,
`timetable_projection_runtime_proof.js`).

**Shared — TT-C8 is hunk-separable but NOT file-separable:**

| file | TT-C8's part | what else is in the file |
|---|---|---|
| `application/config/routes.php` | ONE line: `$route['timetable/supersede']` (inside the canonical timetable block) | ~200 route additions from superadmin, maps, rollout, financial policies, HRMS/compensation, transport compliance, campus access, student360, entitlements |
| `application/libraries/Audit_log_service.php` | ONE array element `'timetableVersion'` + its comment block | `'substitutePolicy'` (Substitute programme) and `'lessonPlan'` (Lesson Planner programme) |
| `firebase-rules/firestore.rules` | three hunks: TT-D1 write deny, TT-D2 overlay reads, `timetablePublishGuards` deny | ~1,300 lines from P0 #4, the SEC-* waves and others |

The rules hunks are preserved as reapplyable patches in
`firebase-rules/patches/` against frozen before-images. **Committing any of
these three files wholesale would sweep in other programmes' unreviewed work.**

## 7. Multi-programme interaction

Another programme advanced HEAD during verification (`077cc4f` → `4d4c577`,
five commits). One touched a TT-C8 dependency, `application/libraries/Firebase.php`.

**Checked, and it does not affect TT-C8**: ignoring line endings the change is
**purely additive** — two new Auth-reporter methods (`resolvedAuthRoot()`,
`isAuthEmulatorTarget()`) for the admission-probe safety programme. There is
**no change** to `firestoreCommitBatchStatus`, `firestoreGetOrFail`,
`firestoreQueryOrFail`, `firestoreSet` or the COMMIT_* contract. The remaining
1,300-line churn is a CRLF/LF rewrite.
