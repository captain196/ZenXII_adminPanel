# Recurring defect shapes

Bug patterns this codebase has actually produced more than once, not generic QA wisdom. Each entry:
the shape, why it recurs here, how to detect it, and a citation. "Citation" is a file path, a
`BUG_LEDGER.md` entry, a `TEST_DOSSIER.md` section, a module UAT doc finding, or a commit hash in
this repo.

---

## CSRF blank-403 on a new AJAX POST route
**Shape:** a new `superadmin/*` (and some `fee_management/*`) POST endpoint isn't added to
`csrf_exclude_uris` in `application/config/config.php`; CodeIgniter's global CSRF check rejects it
with a blank 403 and nothing in the console or logs.
**Why it recurs:** the exclusion list is easy to forget when adding a route, and the failure mode is
silent — no stack trace, no log line, just a blank page.
**Detect:** hit the new endpoint directly and check for a blank 403 before assuming a backend bug;
grep `csrf_exclude_uris` for the new route.
Cited: `CLAUDE.md` "Traps that keep biting → CSRF blank page".

## Phantom success — a client doesn't check `r.ok` / `{status:'error'}`
**Shape:** `fetch()` resolves on 403/500 the same as on 200; a JS helper that only checks for a thrown
exception (not the response body/status) shows a success toast for a denied or failed write.
**Why it recurs:** the failure is silent by construction — the request *did* complete, just not the
way the caller assumed.
**Detect:** every write helper must assert `r.ok` *and* `body.status !== 'error'` before reporting
success; test by forcing a 403/500 response and confirming the UI shows failure.
Cited: `CLAUDE.md` "Traps that keep biting → Phantom success". The same *shape* recurs independently
in two other modules: Support Desk (`7cf4ef8` "resolve / return-to-queue / force-close reported
success on a failed write"; inverted at `e5fb54c` "reply() claimed 'Nothing was sent' for a write
that may have landed" — a write whose true state and reported state disagree in *either* direction)
and Certificate Designer (`TEST_DOSSIER.md` lines 280–285: an abandoned in-flight proof still lands
and unlocks publish — "the codebase's own *phantom success* class, where a cancelled action reports
as done").

## Missing academic-session filter on an otherwise school-scoped query
**Shape:** a query correctly filters by `schoolId` but omits the session predicate, so it silently
returns docs from every academic year.
**Why it recurs:** CLAUDE.md calls this the single most repeated bug in the codebase — session
scoping is easy to omit because the query still "looks scoped" with just `schoolId`.
**Detect:** `querySchool()` vs `querySchoolSession()` — grep for the former on any endpoint that
should be session-bound.
Cited: `CLAUDE.md` "Cross-system contracts". See also invariant #3 in `_global-invariants.md`.

## Compose dialogs/sheets clip on small screens or landscape
**Shape:** a new dialog, sheet, form, or menu in the Teacher app has no height cap, no
`verticalScroll` on the body, no sticky footer, and no `imePadding` — content is cut off on small
devices or in landscape.
**Why it recurs:** it's easy to build and verify a dialog only in portrait on a large emulator.
**Detect:** test every new Compose dialog in landscape on a small/low-res device.
Cited: `CLAUDE.md` "Traps that keep biting → Compose dialogs clip".

## 12-hour timetable strings parsed as 24-hour
**Shape:** timetable period times are stored as `"10:45AM"` strings; code that does naive numeric/
24-hour parsing silently sorts afternoon periods into the morning.
**Detect:** any new timetable-time consumer must explicitly parse AM/PM.
Cited: `CLAUDE.md` "Traps that keep biting → Timetable times are 12-hour strings".

## `display:grid`/`flex` utility class collision breaks table alignment
**Shape:** a component's class name collides with an existing card-grid utility class elsewhere in
the panel's CSS, and a table's header/body silently misalign.
**Detect:** when a table misaligns for no obvious reason, check for a class-name collision before
debugging the table markup itself.
Cited: `CLAUDE.md` "Traps that keep biting". The same *shape* (an unrelated layout system leaking into
a component through a shared class) recurs in Certificate Designer: `6b0b2d3` "The Page panel zigzagged
because Bootstrap's clearfix became grid items".

## A CI3/framework property name shadows a project constant
**Shape:** a controller or library defines a property (e.g. `$log`) that collides with a CodeIgniter
core property (`$this->log`), producing confusing runtime behaviour with no obvious cause.
**Detect:** grep new controller properties against CI3 core property names before naming them.
Cited: `CLAUDE.md` "Traps that keep biting". Concrete instance: `4ec8928` "Fix migration controllers:
rename $log property (collides with CI core $this->log)".

## Silent exception swallowing hides Firestore degradation from both operator and UI
**Shape:** a `catch` block discards the exception (or logs via a channel that gets stripped, e.g.
`android.util.Log.w` on OEM Android builds) and returns an empty/default value — the UI shows "no
data," indistinguishable from a real empty state.
**Why it recurs:** it's the path of least resistance when handling a Firestore call that "shouldn't
fail," and it's the single most repeated finding category in `BUG_LEDGER.md`'s v1 cycle.
**Detect:** grep for empty-body or bare `catch` blocks; confirm every catch on a Firestore/network
call emits a structured log (`log_message`, `debugLog`, or equivalent) before returning a fallback.
Cited: `BUG_LEDGER.md` BUG-002 (6 silent catches in `Homework.php`, lines 354–363), BUG-018/019/022/
023/024/025 (same shape independently in the Teacher and Parent Kotlin repos, lines 276–342).

## Sibling-path / sibling-site parity drift
**Shape:** a fix, a write, or a guard is applied at one code path or call site but never propagated to
a structurally identical sibling (a webhook path vs a parent-app sync path; the Teacher app vs the
Parent app; one call site vs another in the same file).
**Why it recurs:** the sibling isn't visible from the site being edited, and nothing forces a
parity check — this is the most common shape found across this ledger.
**Detect:** whenever you fix one path, grep for the sibling operation on the other path/app/site and
diff them; don't assume symmetry.
Cited: `BUG_LEDGER.md` BUG-044/BUG-047 (the same Q4(i) refactor left `parent_verify_payment` missing
writes that `_verify_and_process` had — lines 629–670, 896–955); BUG-050 (webhook path guarded against
amount mismatch, parent path wasn't, lines 1717–1762); FZ-3 (the identical missing-`schoolId`-predicate
bug present independently in both the Teacher and Parent apps' `TimetableFirestoreRepository`, lines
1609–1630); the walkthrough-caught defect where the same `adminDisabled` predicate bug was fixed at 2
call sites during Phase 1G/1H but a 3rd site (School Search) was missed until an operator walkthrough
caught a phantom badge (lines 34–44); Staff Roles I6 (a bypass label removed from PHP's allow-list but
still present in the Cloud Function's, `STAFF_ROLES_MODULE_INVESTIGATION_AND_UAT.md` §1.10 I6).

## Missing lock+CAS on a shared-field read-modify-write → silent lost writes
**Shape:** an endpoint reads a shared map field (e.g. `schools.streams`, `staffRoles`), mutates it in
memory, and blind-writes it back with no `_config_lock_acquire` and no Firestore `__updateTime`
precondition. Two concurrent editors clobber each other with no error.
**Detect:** grep for `fs->update` on a shared config field with no preceding lock-acquire + precondition
pair; compare against the canonical lock+CAS shape already present elsewhere in the same file.
Cited: `BUG_LEDGER.md` BUG-028 (`School_config.php` save_classes/seed_streams/save_stream, lines
1080–1103). The identical shape recurs independently in Staff Roles: "no CAS/`__updateTime` on
whole-map `staffRoles`/`departments` writes; 2 admins editing different roles → one silently lost" —
`STAFF_ROLES_MODULE_INVESTIGATION_AND_UAT.md` §1.10 I2, §1.9.

## Cross-tenant existence oracle via distinguishable 404 vs 403
**Shape:** a "doc not found" response and a "found but wrong school" response are distinguishable
(404 vs 403), letting an attacker enumerate cross-school ids by the response code alone — even though
the actual data never leaks.
**Detect:** collapse cross-tenant denials into the same response shape as true-not-found; verify with
a probe that diffs the two response bodies/codes.
Cited: `BUG_LEDGER.md` BUG-015 (`Homework.php`, 5 sites, lines 489–498).

## Raw exception message echoed to the client leaks implementation details
**Shape:** `json_error('Failed to X: ' . $e->getMessage())` puts the raw exception text — which can
include Firestore index names, paths, or schema — into a JSON response an authenticated (but not
necessarily trusted) client can read.
**Detect:** grep for `getMessage()` concatenated into a `json_error` call; the server log (not the
response) should carry the detail.
Cited: `BUG_LEDGER.md` BUG-027 (`School_config.php`, 6 sites, lines 1065–1078).

## Rules/index drift between what git holds and what's live in production
**Shape:** `firestore.rules`, `storage.rules`, or `firestore.indexes.json` in the repo doesn't match
what's actually deployed — either because a teammate deployed from their own machine, or because a
whole-file deploy silently overwrote someone else's in-flight block.
**Detect:** never assume the checked-out file is authoritative; `node aegis/cli.js rules status` reads
the *deployed* ruleset and diffs per match-block.
Cited: `CLAUDE.md` "Traps that keep biting". Demonstrated at its worst by `BUG_LEDGER.md` CARRY-013
(production RTDB rules were wide-open while the repo had no rules file at all, lines 1592–1607) and by
git history: `d3d396b` "Commit and deploy firestore.rules blocks that git did not have", `aecbf87`
"firestore.rules: reconcile 46 production-only blocks + restore students.prefLang", `d31eb68`
"Storage: recover the admin-Stories block that lived only in production". A reconciliation pass can
itself introduce this drift: `8cec39f` "rules: re-apply the staff prefLang clause the reconciliation
dropped" had to fix a clause `aecbf87`'s reconciliation had silently removed.

## A rules-tightening fix breaks a legitimate adjacent flow
**Shape:** narrowing a Storage/Firestore rule to close one hole (e.g. making writes create-only)
removes capability a different, legitimate flow depended on (e.g. a retry).
**Detect:** when tightening a rule, enumerate every flow that currently relies on the broader grant,
not just the one you're trying to close.
Cited: `31cc721` "storage: the create-only narrowing broke the attachment retry path" (the inverse
defect — `c775dfe` "support attachments were deletable — `allow write` grants delete" — shows the same
rule can be simultaneously too broad in one dimension and too narrow in another).

## A test double is more capable than the production adapter it stands in for
**Shape:** unit/integration tests pass against a mock/double that implements a method or accepts a
parameter the real client library doesn't actually have — so the double silently "tests itself,"
hiding a fatal defect (a client-supplied integrity value trusted at face value; a call to a method
that doesn't exist on the real client; a `Transaction` object accepted but never actually used by the
write path).
**Why it recurs:** the double was built to make the test pass, not to mirror the real adapter's
constraints.
**Detect:** wire the real adapter (not the double) at least once before calling a path
production-ready, even if that means a slower, environment-dependent test.
Cited: `TEST_DOSSIER.md` "The wiring pass — three production defects", lines 287–307: a forgeable
publish-time proof (client-supplied hash/fontManifest/mpdfVersion trusted verbatim), `activate()`
calling `runTransaction()` on a client that doesn't have it, and a `Transaction` object the write path
silently ignored. "The pattern in all three: the unit tests injected exactly the capability production
lacked. A double that is more capable than the real thing tests the double."

## Harness/test-infrastructure bugs masquerade as product bugs
**Shape:** the test runner itself is broken in a way that produces *plausible*, confidently-reported
failures that look exactly like real product defects — a stale cached script served instead of the
current file, a poll that's satisfied instantly because the flag it's waiting on was already true, a
backgrounded/hidden tab silently throttling timers and shifting which tests "fail" between runs.
**Why it recurs:** a broken harness fails loud and specific, not vague — which makes it easy to
mistake for a real bug and go debug the wrong layer.
**Detect:** before trusting a suspicious failure, rule out stale assets (hard-reload / cache-bust),
confirm a wait observes a *transition* rather than a state that may already be satisfied, and confirm
the test tab was actually visible/foregrounded for its full run.
Cited: `TEST_DOSSIER.md` "Real-Chrome session — three harness defects, no product defects", lines
250–278.

## A guard exists on paper but isn't actually wired into every path that needs it
**Shape:** a security/validation guard is built and unit-tested, but one of its two real call paths
never routes through it — so the guard is structurally present and functionally absent on that path.
**Detect:** for any guard, enumerate every path that produces the guarded output and confirm each one
actually calls the guard, not just the path the guard's own tests exercise.
Cited: `TEST_DOSSIER.md` lines 200–203: `Doc_renderer::guardImages()` protected the PDF render path,
but the browser preview path never passed through the renderer at all — a template embedding a
third-party tracking-pixel URL rendered it, unguarded, in the designer.

## Derived/aggregate counters ratchet instead of reflecting live state
**Shape:** a count field (open-ticket count, reopen count) is incremented/decremented procedurally
instead of being recomputed from the underlying records, so it drifts from reality and only ever
grows.
**Detect:** prefer a query-derived count over a maintained counter where feasible; if a counter must be
maintained, test the decrement path as hard as the increment path.
Cited: `fa7f4a5` "support: openCount no longer increments — a derived count cannot ratchet"; `9767df8`
"Support: stop double-counting a reopen — the open-ticket cap was ratcheting".

## A read failure is reported indistinguishable from a legitimate empty result
**Shape:** a failed Firestore read (permission error, index missing, network) returns the same shape
as "zero matching docs," so the UI shows an empty state with no way to tell outage from truth.
**Related but distinct:** a fallback path for a failed/degraded query can go further and return a
*plausible, confidently-ordered but wrong* page instead of either the right answer or a visible
failure — worse than an empty state, because it looks correct.
**Detect:** every failed-read branch must be distinguishable in the UI/logs from a true-empty branch;
any fallback/degraded-mode branch must be tested against its "confidently wrong" failure mode, not
just its happy path.
Cited: `45a9694` "support: stop reporting failed reads as empty results, and guard the closed state";
`04e17f7` "firestore: the orderBy fallback served plausible, confidently-ordered, WRONG pages" fixed
by `674bbf5` "the orderBy fallback now returns a CORRECT page, not a refusal".

## Audit trail recorded with no actor identity
**Shape:** an audit-log write captures the action and the entity but not who did it — the log exists
and looks complete, but can't answer "who."
**Why it recurs:** it's the same shape independently in at least three modules, suggesting the audit
helper's call sites don't default to capturing the actor.
**Detect:** for any `log_audit`/audit-write call, confirm the actor field is populated from the
authenticated session, not left to a default.
Cited: `94dfdc0` "The audit trail recorded nobody" (Certificate Designer); `00f8b44` "Support: the
audit ledger was recording every action with no actor at all"; and the coverage-gap shape at
`BUG_LEDGER.md` BUG-026 (18 of ~28 `School_config.php` mutating endpoints had no `log_audit` at all,
lines 1046–1063) plus `STAFF_ROLES_MODULE_INVESTIGATION_AND_UAT.md` §1.10 I8 ("Org + Hr role/dept
writers `log_audit` NOTHING").

## Two independently-implemented copies of the same authorization rule diverge on an edge case
**Shape:** the same business rule (a legacy fallback, a bypass label, a claim resolution) is
hand-implemented separately in PHP and in a Cloud Function (or in two apps); they agree on the common
case and diverge on an edge case, in either direction — sometimes locking out a legitimate user,
sometimes granting more than intended.
**Detect:** whenever a rule exists in more than one language/runtime, diff their edge-case behaviour
explicitly rather than assuming parity from a shared spec.
Cited: `STAFF_ROLES_MODULE_INVESTIGATION_AND_UAT.md` §1.10 I3 (PHP has a legacy `schools.roles`
fallback the Cloud Function lacks, so an un-migrated tenant's CF writes an *empty* caps doc — which
then flips the rules-side default from fail-open to fail-**closed**, locking staff out of writes PHP
would allow) and I6 (a bypass label removed from PHP's allow-list but still live in the CF's). Also
flagged as a live maintenance risk even where currently correct: `STORIES_MODULE_INVESTIGATION_AND_UAT.md`
§8 G5 — audience-key canonicalization is independently implemented in PHP, Kotlin, and JS ("triplicated
… any drift = silent scoping mismatch").

---

## A staged destructive change is picked up by whoever commits next

**Shape.** `git rm` (or `git add` on a deletion) stages immediately. Two sessions work this
tree concurrently, so anything left staged is fair game for the next `git commit -a` from
another session — which lands your change under someone else's message, without the other
half of the work it belonged with.

**Why it recurs here.** Multiple agent sessions share one checkout, and this repo's own
working agreement is to sweep the whole tree when committing ("commit every changed line").
That agreement is right, and it is exactly what makes a stray staged deletion dangerous.

**What it cost, 2026-09-05.** A retirement was staged as `git rm application/controllers/
Certificates.php` plus its view, intending to commit alongside the `routes.php` and
`header.php` cleanup. Another session committed first (`3d83cf9`, Student AI work) and took
the two deletions with it. HEAD then held **16 routes pointing at a controller that no
longer existed** and a sidebar linking to them: every Certificates menu entry 404'd. Nobody
deployed in that window, so it cost nothing real — this time.

**How to detect.** Before committing, `git diff --cached --name-only` and ask whether every
staged path is yours *and* complete. A staged deletion with no accompanying route/nav change
is the specific smell.

**The rule.** **Stage and commit in one motion, or do not stage at all.** For a destructive
change, prefer plain `rm` and let git see an unstaged deletion until the commit is ready —
an unstaged change cannot be swept into someone else's commit.

**Corollary for reviewers.** A commit whose stat shows deletions unrelated to its subject
line has probably absorbed someone else's staging. It is not necessarily wrong to keep — in
this instance reverting would have restored a system deliberately retired AND undone
legitimate Student AI work — but the missing half must be found and landed immediately.

## Test strategies that found real bugs here

**Cross-path / cross-surface asymmetry comparison** — running the same conceptual operation through
two different paths (parent-app Razorpay sync payment vs. admin offline counter payment) and diffing
their resulting state was explicitly the highest-signal method in the Fees module hardening: it
surfaced BUG-043, BUG-044, BUG-046, and BUG-047 (`BUG_LEDGER.md` lines 594–955), each an
asymmetry invisible from either path alone.

**Wiring the real production adapter instead of the double it was tested against** — found 3 defects
(forgeable proof, a call to a nonexistent method, a decorative transaction) that no unit test could
catch, because the unit tests exercised the double, not the production client. `TEST_DOSSIER.md`
"The wiring pass", lines 287–307.

**Running the E2E suite in a real, foregrounded browser instead of headless** — immediately surfaced
3 defects that looked exactly like activation bugs and were entirely in the harness (stale cached
script, a poll satisfiable by an already-true flag, a hidden tab silently throttling and shifting
failures between runs). `TEST_DOSSIER.md` "Real-Chrome session", lines 250–278.

**Negative-testing a rules deploy against the live production URL, not trusting the deploy tool's
success message** — a first RTDB rules deploy attempt silently did not take effect; only a
post-deploy unauthenticated-read probe against the real database caught it. `BUG_LEDGER.md` CARRY-013,
lines 1672–1699 ("do NOT assume operator's deploy success message means rules are live").

**Adversarial rules test suites written specifically to close gaps the first suite left** — Support
Desk's rules coverage grew via a dedicated adversarial pass (`4d9588a` "23 adversarial cases for the
gaps the first suite left") and a security-focused half of the matrix (`e2e7f95` "29 cases, the
security half of the S-matrix"), rather than treating the first green suite as sufficient.

**Golden-file byte-for-byte snapshots plus a test that the goldens differ from each other** — catches
a serializer regression that would make all outputs identical (i.e. a regression the golden-diff alone
could miss if it degenerated toward a single fixed output). `TEST_DOSSIER.md` line 90.

**Mutation testing on numeric/geometry logic** — `DocRendererPageGeometryTest` is explicitly
mutation-tested; the old page-height expression fails 3 of 6 mutants, which a purely example-based test
would not have caught. `TEST_DOSSIER.md` line 89.

**A plain operator observation during real use surfaced a defect no synthetic test exercised** — BUG-052
(cross-session promotion writing the wrong session) was found from the operator's own words: "all 7
students moved to Class 9th and status active but... when I switch the session to 2027-28 then there
is no student." `BUG_LEDGER.md` line 1892. Real usage, not scripted UAT, was the trigger.
