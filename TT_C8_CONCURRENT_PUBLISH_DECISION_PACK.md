# TT-C8 — Concurrent publish splits the timetable

**Research pack. Nothing implemented. Decision required before any code changes.**

Prepared 2026-08-31 against `integration/phase2`. Every claim below marked
*executed* was produced by running the real production code against a local
Firestore emulator, not by reading source.

---

## 1. ZenX current behaviour

Publishing is the only step that makes a timetable live. Reached two ways:

```
POST /timetable_lifecycle/publish        session auth · Academic:manage · CSRF on
  └─ Timetable_plan_store::publishCandidate()
       ├─ four content refusals  (status, completeness, verdict present, verdict current)
       ├─ _publishedOverlaps()                  ← READ.  reports, never forbids
       ├─ firestoreSet(timetableVersions)       ← WRITE. status := published, no precondition
       └─ if in force today AND overlaps empty:
            projectPublishedVersion(…, TRUE)    ← writes `timetables`, doc by doc
```

`resolveForDate()` then answers "which timetable is live on date D" by scanning
every published version for the school+session and returning RESOLVED (exactly
one), NONE, or AMBIGUOUS (more than one).

---

## 2. Exact root cause

**Two different definitions of "in force" exist, and the write-side one is both
narrower than the read-side one and applied non-atomically.**

| | scope | when |
|---|---|---|
| `_publishedOverlaps()` — write side | published versions **of the same `planId`** | read *before* the status write |
| `resolveForDate()` — read side | published versions **across all plans**, school+session | at read time |

Two independent failures follow.

### 2a. The race — same plan, two administrators (executed)

Each publish's overlap check reads before the other's write lands, so both see
no overlap and both proceed to publish **and** to project. The projection writes
each document as a separate REST call — no batch, no transaction — so the two
write bursts interleave.

Two OS processes released on one starting gun, entering the real
`publishCandidate()`:

| scale | outcome |
|---|---|
| 4 cells, 5 runs | both publish; one version wins entirely; winner varied (VA 3×, VB 2×) |
| **120 cells — a realistic school, 3 runs** | both publish; `timetables` ends **mixed**: 74/46, 111/9, 2/118 |

At real scale the projection is a long enough burst to interleave. **Different
classes are left on different versions** — the school's timetable is a state no
single published version describes.

### 2b. The detection gap — different plans, one user, no concurrency (executed)

Two *sequential* publishes, single administrator:

```
publish PLAN_A/V1 : {"outcome":"published","overlaps":[],"projection":{"applied":true,"written":2}}
publish PLAN_B/V1 : {"outcome":"published","overlaps":[],"projection":{"applied":true,"written":2}}
timetables by source : {"V1@PLAN_B":2}
cron resolution      : Resolution: ambiguous → REFUSED
```

`overlaps: []` **both times** — no warning at all — because the check filters by
`planId`. The projection therefore ran (it is gated on `empty($overlaps)`), and
the school is left permanently ambiguous. This is not a race; it is
deterministic.

**Current reachability:** only `planId = 'REGULAR'` is creatable —
`ensureRegularPlan()` hardcodes it and nothing creates the `KIND_TEMPORARY` /
`KIND_EXAM` plans the constants anticipate. So 2b is **latent today** and becomes
live the moment a second plan kind ships. 2a is reachable today.

### 2c. Why it does not heal

- `resolveForDate()` returns AMBIGUOUS and the cron correctly **REFUSES** rather
  than choosing — verified in the same run. So nothing repairs the split.
- There is deliberately **no unpublish and no edit-in-place**: *"the published
  record must stay reconstructable as what was actually published."*
- Publishing dates are not auto-closed: *"editing a published version's dating —
  even only its dating — is a mutation of published data, and no owner decision
  authorises it."*

**A school that reaches this state cannot get out of it through the product.**

---

## 3. Affected modules

- **Academic → Master Timetable** — publish, resolve, project.
- **`timetables`** — the compatibility projection; the collection both apps read.
- **Teacher App / Parent App** — consume the split silently. Neither has any way
  to know its timetable is drawn from two versions.
- **`Timetable_projection_cron`** — refuses (correctly), so the scheduled path
  goes dead for that tenant until a human intervenes.
- **Not affected:** substitutes, calendar, curriculum, lesson planner — all read
  the projection or their own collections; none decides what is live.

---

## 4. Real-world school workflow

- A school has typically 1–3 accounts with `Academic:manage`. Two of them
  publishing *different* versions within the same second is uncommon but not
  exotic — timetable changes cluster at term start, and that is exactly when two
  coordinators work the same screen.
- **A same-version double-submit is already safe**: the second call finds status
  `published` and is refused (`already_published`), and the UI disables the
  button during the request.
- The realistic triggers are therefore: two people, or one person in two tabs,
  publishing **two different versions**; and — once term/exam plans exist — the
  entirely ordinary act of publishing an exam timetable while the regular one is
  open-ended.

---

## 5. Comparable ERP patterns

- **Untis** models a changing timetable as date-ranged **terms** — a school year
  is divided so that only one timetable is in force at a time. Overlap is not a
  reported condition; it is prevented by construction.
- **PowerSchool SIS** uses **effective-dating** with a retained *Schedule Change
  History*. A new record closes the previous one; the current record is
  singular.
- Both treat "which schedule applies now" as an invariant maintained at write
  time, not a question answered — and possibly answered "don't know" — at read
  time.
- The concurrency shape itself is the standard **aggregate-root optimistic
  concurrency** pattern: the invariant spans several documents, so a single
  version token guards the whole set.

ZenX already derives "live" at read time, which is a legitimate and arguably
better model (it keeps published records immutable). What it is missing is the
write-time guarantee that the derivation can only ever have one answer.

---

## 6. Design options

### Option 1 — Serialise publish with the existing lease lock
Reuse `Fee_firestore_txn::acquireLockStatus()`'s proven three-state
(OWNER/CONFLICT/UNKNOWN) TTL lease, keyed `{school}_{session}`.

- **Closes:** 2a.
- **Leaves open:** 2b entirely (sequential, single user), and recovery.
- **Cost:** a lease that can expire mid-publish; a TTL to tune; a stuck-lock
  failure mode to operate. Introduces a liveness concern where none exists now.

### Option 2 — Unify the predicate
One pure `overlapsWith(versions, from, to)` used by **both** `publishCandidate`
and `resolveForDate`, scoped school+session across all plans.

- **Closes:** 2b.
- **Leaves open:** 2a (both callers still read before either writes).
- **Cost:** none architecturally — it removes a duplicate definition. Changes
  behaviour only by detecting overlaps it should always have detected.

### Option 3 — Aggregate-root CAS on publish  *(no lock)*
A guard document per `(school, session)` carrying **no business meaning** — only
a change token. Publish becomes: read guard (capturing `__updateTime`) → run the
overlap check → commit **one batch** containing both the version status write
*and* a guard-doc write preconditioned on that `updateTime`.

Two concurrent publishes both read the guard at U0; the first commits and moves
it to U1; the second's precondition fails with `PRECONDITION_CONFLICT` and is
refused with an actionable message.

- **Closes:** 2a, atomically and with no lease, no TTL, no stuck lock.
- **Uses only primitives already in production here:**
  `firestoreCommitBatchStatus()` with `currentDocument` preconditions — the same
  mechanism as `savePeriod` (ACAD-D20) and the accounting CAS loop.
- **Cost:** one extra document and one extra write per publish; publish can now
  fail with "someone else just published — refresh and try again", which is the
  same failure the day editor already surfaces.
- **Note on why not a pure batch precondition over the competing versions:**
  Firestore attaches `currentDocument` to a *write*, so a document can only be
  preconditioned if it is also written. Proving "no other version changed"
  without mutating published records is therefore not expressible — which is
  precisely why a single shared token document is the right instrument.

### Option 4 — Forbid an overlapping publish
With a correct predicate, refuse the publish outright instead of reporting it.

- **Closes:** the *meaning* of the failure — an overlap has no valid
  interpretation, since the projection physically holds one timetable per
  section per day and the resolver refuses to choose.
- **Cost:** reverses a documented house convention (*"overlaps are DETECTED,
  REPORTED and returned — never silently resolved and never forbidden"*). That
  convention is sound for *draft* overlaps; this would narrow it to
  published-vs-published only.
- **Owner decision.** It removes an action an administrator can take today.

### Option 5 — Auto-supersede the predecessor
On publish, close the previous version (`effectiveTo = newFrom − 1 day`,
`status = superseded`) in the same atomic batch. The Untis/PowerSchool shape.

- **Closes:** overlap by construction; also gives the natural recovery path.
- **Cost:** it **mutates a published record's dating**, which the code
  explicitly refuses to do without owner authorisation. Also silently changes
  how long a timetable applied — an audit-sensitive act.
- **Owner decision, and the largest one here.**

### Option 6 — Recovery action (needed under every option above)
Nothing today can clear an ambiguous state. Minimum viable: an audited,
`manage`-gated *"correct the effective dates of a published version"* action, or
an explicit *supersede* action — narrow, logged, and not a general edit.

---

## 7. Recommendation

**Adopt 2 + 3 + 6, and take 4 as the owner's policy call. Do not adopt 5 now.**

1. **Option 2 (unify the predicate)** — this is a defect, not a policy. Two
   definitions of one concept disagree; the narrower one silently authorises the
   projection. One shared pure function, used by both sides.
2. **Option 3 (aggregate-root CAS)** — the safest concurrency mechanism
   available here: no lease, no TTL, no liveness risk, no new authority for
   "what is live", built from primitives already carrying production traffic in
   this repository, and it **fails closed** on the exact `PRECONDITION_CONFLICT`
   path the codebase already handles elsewhere.
3. **Option 6 (recovery)** — required regardless, because the product currently
   has no way out of the broken state.
4. **Option 4** — recommended, but yours: with 2 and 3 in place, an overlapping
   publish becomes reliably *detectable*; whether it should then be refused or
   merely reported is a policy about what an administrator may do.

Rejecting Option 1: a lease lock solves less (2a only) and costs more (expiry,
stuck locks) than Option 3, which solves the same problem with an
already-proven, stateless mechanism.

Rejecting Option 5 for now: it mutates published data. It is the right long-term
model if the school's mental model is Untis-style terms — but that is a product
decision that should be taken deliberately, not smuggled in as a concurrency fix.

**Explicitly out of scope of this fix:** the projection's own doc-by-doc writes.
Once publishes are serialised, a partial projection is already handled — it
throws `projection_incomplete` and tells the operator to re-run, and re-running
is proven idempotent. Batching it (≤500 writes per commit) is a separate,
smaller improvement.

---

## 8. Security implications

- No change to authentication, authorisation or tenant scoping. Publish stays
  `Academic:manage`, CSRF-protected, tenant bound from the session.
- Option 3 **adds** a fail-closed refusal, so the change can only reduce what a
  request is able to do.
- The guard document is per-tenant and carries no business data; it needs the
  same `allow write: if false` treatment as the other canonical collections
  (clients never touch it — the service account bypasses rules).
- A split projection is not currently a *confidentiality* issue: every document
  involved belongs to the same tenant. It is an integrity and availability one.

## 9. Scalability implications

- Option 3 adds one read and one write per publish — publish is a rare,
  human-initiated act (a handful per school per year).
- It introduces a single serialisation point per `(school, session)`. That is
  the correct granularity: it never serialises across tenants, and two admins of
  *different* schools never contend.
- Option 2 makes the overlap scan consider all plans, which is the same
  `listVersions()` result already fetched — no additional reads, and it is
  already saturation-guarded at `VERSION_SCAN_LIMIT = 500`.

## 10. Migration / data implications

- The module is **uncommitted and undeployed**, and ZenX has no production
  operating history for it — so there are **no existing ambiguous states in
  production to migrate**.
- Any UAT tenant that has already published twice will need Option 6 to be
  cleared; it cannot be fixed by deploying the guard.
- The guard document is created on first publish; no backfill needed.
- No change to `timetableVersions`, `timetableVersionDays` or `timetables`
  schemas under options 2, 3 and 6. Option 5 *would* rewrite published rows.

## 11. Backward compatibility

- **Apps:** unaffected. Neither reads versions or plans; both read the
  projection, whose shape does not change.
- **Firestore rules:** one new collection to deny to clients. No existing rule
  changes.
- **Existing tests:** Option 2 changes `_publishedOverlaps` results for
  multi-plan fixtures; Option 3 adds a refusal path that publish tests must
  allow for. Both need coordinated test updates, in the same change, stated
  openly — the same discipline used for gate B's five assertions.
- **Option 4 would change an API outcome** (publish begins refusing where it
  previously succeeded-with-warning) and needs a UI message.

## 12. What requires owner approval

1. **Option 4** — refuse an overlapping publish, vs keep reporting it. Reverses
   a documented convention.
2. **Option 5** — auto-supersede. Mutates published dating; recommend deferring.
3. **Option 6's shape** — correcting effective dates vs an explicit supersede
   action; both are new administrative powers over published records.
4. Confirmation that a school may only ever have **one timetable in force at a
   time** — the premise the whole recommendation rests on. Untis and PowerSchool
   both assume it; ZenX's projection and resolver both already require it; but it
   has never been written down as a product rule.

## 13. What could be fixed autonomously (if authorised)

- **Option 2**, as a defect: one predicate, used by both sides.
- **Option 3**, as a concurrency fix built entirely from existing primitives.

Both are held pending this decision, per the research-first rule.

## 14. How the fix will be proven

- The existing `scripts/timetable_publish_race_proof.js` already **fails**
  against today's code at 120 cells. It becomes the regression test: after the
  fix, exactly one publish must succeed and the loser must be refused with a
  retryable message.
- The cross-plan sequential path becomes a second executed case.
- Mutation-test the CAS: removing the precondition must make the race proof fail
  again.
- Re-run the full runtime proof (55 assertions), the rules suite and the PHP
  suite against the 13-failure baseline.

## 15. Open question worth answering first

**Is an overlap ever legitimate?** If the answer is no — and the projection,
the resolver and both external ERPs all say no — then Option 4 is not a
restriction but the removal of a way to break the school, and the recommendation
simplifies to *2 + 3 + 4 + 6*.

---

# 16. OPTION 6 — SUPERSEDE: FINAL DESIGN (DESIGN ONLY, NOT IMPLEMENTED)

Options 2, 3 and 4 are implemented and proven (§14). This is the finalised
design for the recovery action, incorporating repository evidence gathered
2026-08-31. It **replaces** the earlier sketch; two of that sketch's
recommendations were wrong and are corrected below (RBAC separability, and the
gap question).

## 16.0 Why this is a prerequisite, not a follow-up

Implementing Option 4 turned a predicted consequence into a measured one. Two
tests assert today's real behaviour:

- `TimetablePublishConcurrencyTest::test_an_open_ended_timetable_blocks_every_later_publish`
- `TimetableLifecycleEndToEndTest`, step 9

An open-ended published version claims every future date, so with one timetable
in force enforced, **nothing can be published after it — including its own
correction**. `createChangeVersion()` then edit then publish is the sanctioned
correction route, and it is now refused whenever the original was published
without an end date — which is the natural thing to enter when a school does not
yet know when the term ends. Nothing in the product can add one afterwards.

## 16.1 Exact state transition model

No new lifecycle states. One new transition on the existing machine:

```
   draft --> candidate --> published --> superseded
                               |             ^
                               +-- SUPERSEDE +
```

```
SUPERSEDE(planId, versionId, lastEffectiveDate, reason)
```

**Preconditions — every one a refusal, all evaluated before any write:**

| # | condition | errorType | why |
|---|---|---|---|
| 1 | version exists and is readable | `read` / `not_found` | fail closed |
| 2 | belongs to this tenant AND session | `tenant_mismatch` | defence in depth, as `getVersion()` already does |
| 3 | `status == published` | `not_published` | a draft has no dates to close |
| 4 | `effectiveTo === ''` | `already_bounded` | **one-way**: an end date is set once, never changed |
| 5 | `lastEffectiveDate` is a valid ISO date | `validation` | |
| 6 | `lastEffectiveDate >= effectiveFrom` | `validation` | a timetable cannot end before it starts |
| 7 | `lastEffectiveDate >= today` | `retroactive` | **the load-bearing one — see 16.2** |
| 8 | `reason` non-empty after trim | `validation` | the record exists to answer "why" |
| 9 | publish guard token readable and unmoved | `read` / `conflict` | 16.6 |

**Effect — one document, one commit, two fields plus provenance:**

```
effectiveTo      : ''  ->  lastEffectiveDate        (only from unset)
status           : published -> superseded   ONLY IF lastEffectiveDate < today
                   (otherwise stays published; it is still in force)
supersededAt     : ISO timestamp        (new field, append-only)
supersededBy     : adminId              (new field, append-only)
supersededReason : operator text        (new field, append-only)
```

Because precondition 7 requires `lastEffectiveDate >= today`, the status branch
is "stays published" in every reachable case. It is written as a condition
rather than a constant so the state stays correct without a second job: the
version reads as `superseded` once the date has passed, and `coversDate()`
already stops matching it on that date regardless of status.

**Explicitly NOT in scope:** unpublish, delete, re-open, content editing, or
changing a date already set. No general edit-in-place capability is introduced.

## 16.2 Invariants, and the proof that they hold

**I1 — Published CONTENT is immutable.**
`periods` live in `timetableVersionDays`, which this transition never writes. On
the version document, `effectiveFrom`, `publishedAt`, `publishedBy`,
`publishedFingerprint`, `publishedDayCount`, `generation` and `validation` are
never written. The write mask contains exactly five field paths.

**I2 — Historical resolution is unchangeable.**
For every date `D < today`, the set of versions covering `D` is identical before
and after. Proof: `coversDate(v, D)` depends only on `status`, `effectiveFrom`
and `effectiveTo`. `effectiveFrom` is untouched. `effectiveTo` moves from `''`
(unbounded) to `lastEffectiveDate >= today`, which can only stop the version
matching dates strictly **after** `lastEffectiveDate` — all of them `>= today`.
`status` changes only when `lastEffectiveDate < today`, which precondition 7
forbids. So no past date's answer moves. **This is what makes the action safe:
it can shorten the future, never rewrite the past.**

**I3 — Ambiguity can never be introduced.**
For every date `D`, `covers_after(D)` is a subset of `covers_before(D)`.
Supersede only ever removes dates from one version's range, never adds any, and
touches exactly one version. So the count of covering versions is
non-increasing for every date: a date that resolved to exactly one version
cannot come to resolve to two. Supersede is **monotonically non-increasing in
coverage** — the only transition in the system with that property, and precisely
the property that lets it repair an ambiguous state while being unable to create
one.

**I4 — Reconstructability.**
"What was published" = `timetableVersionDays` + `publishedFingerprint`, both
untouched. "When it applied" = `effectiveFrom` + `effectiveTo`, the latter
recorded once, with actor, timestamp and reason. "Why it stopped" = the audit
row. Nothing is erased or rewritten, so no rollback mechanism is needed — and
none is offered (16.9).

## 16.3 Open-ended published versions

The only case the transition exists for. Open-ended is `effectiveTo === ''`, and
precondition 4 admits exactly that. A version that already has an end date is
refused: its dating is settled, and changing it would be the general edit
capability this design refuses to add.

## 16.4 The successor timetable

SUPERSEDE says nothing about a successor and requires none:

```
SUPERSEDE(REGULAR, V1, 2026-09-30, "Term 1 ends")  ->  V1 now [2026-04-01, 2026-09-30]
publish(REGULAR, V2, 2026-10-01)                   ->  admitted; no overlap
```

The successor goes through the ordinary publish path, subject to the same
overlap refusal and the same CAS. There is deliberately **no combined
supersede-and-publish**: fusing them would put the successor's validation inside
a transaction that closes a live timetable, so a fault in the new timetable
could leave the old one closed and nothing in force. Two independently safe
steps is the weaker coupling and the correct one.

## 16.5 Gap policy — and whether existing data can answer it

**Partially, and the design says which part rather than assuming.** Evidence
from the repository, not from a general developer policy:

| question | authority that already exists | authoritative? |
|---|---|---|
| which weekdays are working days | `Working_calendar_service` — *"THE canonical working-week authority"*, effective-dated | **Yes, when configured.** It deliberately has NO default: an unconfigured tenant resolves `configured => false` and payroll blocks rather than guessing |
| which dates are holidays / special working days | `Holiday_service` over `calendarEvents` — *"One place, and only one, answers 'is date X a holiday'"*; read-only; `holidays_in_range()`, `special_working_days_in_range()`, `load_ok` | **Yes**, and it reports a read failure rather than answering "not a holiday" |
| when the session ends | `Leave_year_service::boundsForSession()` | **No — convention.** `schools/{id}.sessions[]` is a bare array of label strings with no dates; bounds are derived from an April convention, overridable via `sessionBoundaries[label]`, which **no tenant has configured today** |

A *teaching day* is therefore already answerable from canonical data — the same
composition Attendance and Payroll use. The *horizon* is not.

**Therefore: disclose in three states, and do not refuse.**

```
gap: none      every teaching day from lastEffectiveDate+1 to the horizon is
               covered by a published version
gap: <n> days  the first uncovered teaching day is <date>, and <n> of them fall
               before the horizon
gap: unknown   the working week is unconfigured, or the holiday read failed —
               the question CANNOT be answered
```

`unknown` is a distinct outcome and must never be rendered as "no gap". The
horizon carries its provenance: *"to the end of the session (from your
configured session dates)"* versus *"(assumed to end 31 March — your school has
not set session dates)"*.

**Why warn rather than refuse, against the fail-closed principle.** Fail-closed
applies where proceeding risks integrity. A gap risks none:
`projectionDecision()` returns `skip` on a NONE resolution and the cron
*"materialises what is live, it does not enforce emptiness"* — verified in code
and by execution. So a gap does not blank anybody's timetable. Refusing would
make SUPERSEDE unusable in exactly the situation it exists for — a school stuck
**because** it has no successor ready — and refusing on `unknown` would block
every tenant that has not configured a working week, which is every fresh
tenant.

**A consequence to state, not hide:** during a gap the apps keep showing the
superseded timetable, because the projection never deletes. That is the
existing, deliberate rule (never clear a school's timetable as a side effect),
but it means the compatibility documents are knowingly stale relative to
canonical until a successor is published. **Owner decision — 16.12.**

## 16.6 Concurrency — interaction with the publish CAS

SUPERSEDE uses **the same guard document and the same batch shape** as publish:

```
token = read(timetablePublishGuards/{school}_{session})        <- fail-loud
... all preconditions ...
commit([ version write, guard write with precondition(token) ])  <- one batch
```

Not merely reuse — required for correctness. Supersede and publish both change
which version is in force, so they must serialise against **each other**, not
only against themselves. One shared token gives that:

- supersede ∥ supersede: one wins; the loser gets `conflict`, and on retry gets
  `already_bounded`, which is the truthful answer;
- supersede ∥ publish: one wins. If supersede wins, the publish computed its
  overlap against a range that has since closed, and its CAS refuses — correctly,
  since it would otherwise be refused for an overlap that no longer exists;
- the refusal message is the retryable one publish already uses.

No new lock, no TTL, no second concurrency mechanism.

## 16.7 Audit and provenance

On the version document, append-only, never overwritten: `supersededAt`,
`supersededBy`, `supersededReason`.

Through the existing `_audit()` path — entity type `timetableVersion`, which
`Audit_log_service::ENTITY_TYPES` already accepts (**verified, not assumed**: an
unregistered entity type is silently refused, which is how every admin
lesson-plan audit write was lost):

```
action : 'supersede'
entity : timetableVersion / {school}_{session}_{plan}_{version}
before : {status: 'published', effectiveTo: ''}
after  : {status: 'published', effectiveTo: '2026-09-30',
          supersededBy: 'STA0009', supersededAt: '...',
          reason: '<operator text>', gap: 'none|<n>|unknown'}
```

The gap outcome is recorded **as it was shown to the operator**, so "did anyone
know there would be no timetable in October?" has an answer later.

## 16.8 RBAC — CORRECTION TO THE EARLIER SKETCH

The earlier sketch recommended a distinct `timetable_supersede` capability,
separately grantable from `timetable_publish`. **That was wrong; it is
withdrawn.**

`has_permission($module, $level)` grants on **module + graded level only**. The
action string in `require_permission($module, $action, $level)` is
informational: it is logged and passed to the lifecycle gate, which keys off
`$level` (`$isWrite = level in {edit, manage}`). There is no mechanism today by
which a school could grant publish without granting supersede.

**Design as it must actually be:**

```php
require_permission('Academic', 'timetable_supersede', 'manage');
```

`manage`, the same gate as publish — ending what teachers are shown is at least
as consequential as starting it. The action string is carried for the audit and
lifecycle trail. Separating the two would require extending RBAC to
sub-capabilities: the **deferred, design-frozen Enterprise RBAC programme**,
explicitly out of scope here.

## 16.9 Retry and partial failure

- **Idempotent by precondition.** A second attempt fails precondition 4
  (`already_bounded`); a double-submit refuses rather than rewriting.
- **No partial state.** One document, one commit; the guard write shares the
  batch, so either both land or neither does.
- **CAS conflict** gives the same retryable refusal as publish.
- **Unreadable version or guard** refuses and writes nothing.
- **Unclassified commit outcome** is a failure, never a success
  (`status !== 'SUCCESS'` — the literal that was wrong twice in this module).
- **No rollback.** Deliberately. Re-opening a closed range would let a timetable
  teachers had stopped seeing become live again retroactively, which is exactly
  the rewriting of publication history the principle forbids. The forward path
  is to publish a new version for the dates concerned.

## 16.10 Recovery from an already-ambiguous state

Options 2–4 make new ambiguity unreachable, but a UAT tenant may already hold
two open-ended published versions. SUPERSEDE repairs it, and I3 guarantees it
cannot make it worse:

```
before : V1 [2026-04-01 -> open]   V2 [2026-06-01 -> open]    AMBIGUOUS from 06-01
step 1 : SUPERSEDE(V1, 2026-05-31, "superseded by V2 at term change")
after  : V1 [2026-04-01 -> 2026-05-31]  V2 [2026-06-01 -> open]  RESOLVED everywhere
```

Constraint worth naming: precondition 7 requires `lastEffectiveDate >= today`,
so a range whose natural close-out date is in the past **cannot** be closed at
that date. The operator closes it at today instead, leaving the past ambiguity
intact — which is correct, because those days genuinely were ambiguous, and
rewriting them would falsify history. The reason field is where that is
recorded.

**Sequence for a stuck tenant:** supersede the older version, confirm
`resolveForDate()` reports RESOLVED for every date of interest, run the
projection cron dry, then apply. No migration, no script, no direct writes.

## 16.11 Proof plan

**Unit / property — `TimetableSupersedeTest` (new):**
- every precondition refuses, and writes nothing when it does;
- the write mask contains exactly the five permitted field paths — a mutant
  adding `periods` or `effectiveFrom` must be killed;
- **I2 as a property**: across a date range spanning before and after, the
  resolution of every date `< today` is byte-identical pre and post;
- **I3 as a property**: across the same range, `covers_after(D)` is a subset of
  `covers_before(D)` for every `D` — exhaustive over the range, not sampled;
- the three gap outcomes, including `unknown` both when the working week is
  unconfigured and when the holiday read throws;
- retry gives `already_bounded`.

**Concurrency — `TimetablePublishConcurrencyTest` (extended):** supersede ∥
supersede and supersede ∥ publish, using the existing `beforeCommit` and
`afterVersionScan` windows, each asserting the window actually opened.

**Emulator — `scripts/timetable_publish_race_proof.js` (extended):** the real
CLI path, two OS processes, and the 16.10 recovery sequence driven end to end
with persisted state read back — including that supersede itself writes nothing
to `timetables`.

**Mutation — `scripts/timetable_publish_cas_mutation.php` (extended):** remove
the `effectiveTo === ''` precondition; remove the `>= today` precondition; widen
the write mask; drop the guard precondition; return `'OK'` instead of
`'SUCCESS'`. Each must be killed. The survivor-analysis lesson from the TT-C8
battery applies: a mutant that survives means the proof is wrong, not the
mutant.

**Regression:** full timetable set, rules suite, runtime proof, and the
7,792-test suite against the 13-failure baseline.

## 16.12 Remaining owner decisions

1. **Stale projection during a gap.** After superseding with no successor, the
   apps keep showing the superseded timetable until something is published.
   Accept (recommended — it matches the existing "never clear a school's
   timetable" rule), or add an explicit operator action to clear it?
2. **Gap on `unknown`.** Recommended: warn and proceed, disclosing that the
   question could not be answered. Confirm.
3. **Future-dated versions.** Should a version whose `effectiveFrom` is still in
   the future be supersedable — cancelling a timetable before it ever applies?
   That is arguably *withdraw*, not *supersede*, and is deliberately excluded.
4. **RBAC.** Confirm `Academic:manage` (the only thing possible today) rather
   than waiting on the deferred Enterprise RBAC programme.
