# Global invariants

Ecosystem-wide rules that hold across all three ZenXii products (admin panel, Teacher app, Parent
app) and the shared Firebase project. Each is stated as a testable claim with its real enforcement
point — the place a violation would actually be caught, not just documented. Citations point at
`CLAUDE.md` (checked into this repo) and at `BUG_LEDGER.md` / this codebase's git history where the
invariant was demonstrably violated and then restored, which is stronger evidence than the policy
statement alone.

---

### 1. Tenant isolation — every doc/query is scoped by `schoolId`/`school_id`
No read or write may cross a school boundary. Enforcement point: Firestore Security Rules
(`isSameSchool()` / `isSameSchoolWrite()` comparing `resource.data.schoolId` against
`request.auth.token.school_id`) plus, on the PHP side, `$this->fs->querySchool()` /
`querySchoolSession()`.
*Test it:* query the same collection with two different schools' ID tokens and confirm 0 cross-school
docs return; confirm a raw client write with a foreign `schoolId` is rejected by rules, not just by
the PHP controller.
Cited: `CLAUDE.md` "Cross-system contracts → Data layout / Every query is scoped twice". Demonstrated
missing in practice at the mobile-app query layer for `substitutes` (client-side-only tenant filter,
full multi-tenant result set sent over the wire) — `BUG_LEDGER.md` FZ-3, lines 1609–1630.

### 2. Claims are dual-emitted — `school_id` (snake) AND `schoolId` (camel), always both
Every claims writer must set both keys in the same write. `firestore.rules` reads only the snake key;
admin login reads only the camel key. Emitting one without the other produces a silent
`PERMISSION_DENIED` on whichever surface reads the missing key, with no error visible to the user who
triggered it.
*Test it:* after any claims-writing code path (onboarding, role change, claim backfill), fetch the
custom claims and assert both keys are present and equal; log in on the surface that reads the
key you didn't touch.
Cited: `CLAUDE.md` "Cross-system contracts → Claims must be dual-emitted". Corroborated by a live
convergence effort in this repo — commit `be65ad3` ("align Firebase custom claims to canonical
camelCase") and the transitional bridge left in place at commit `3311fac` ("transitional snake_case
claims reader bridge [RETIRE after claims convergence]") — i.e. the two keys have genuinely drifted
apart in production before.

### 3. Every query is scoped twice — `school_id` AND academic session
A school-scoped query that omits the session filter silently returns cross-session data (e.g. last
year's enrolled students appearing in this year's view). CLAUDE.md names this "the single most
repeated bug in this codebase."
*Test it:* run the same list/read endpoint before and after a session rollover and confirm the result
set changes; grep new Firestore query call sites for a session predicate alongside the schoolId one.
Cited: `CLAUDE.md` "Cross-system contracts". Demonstrated in a different but adjacent shape at the
*write* side: `Entity_firestore_sync.php`'s 9 sync methods hardcoded `'session' => $this->session`
and ignored a caller-supplied session, so a cross-session student promotion silently wrote the wrong
academic year — `BUG_LEDGER.md` BUG-052, lines 1885–1938 ("all 7 students moved... when I switch the
session... there is no student").

### 4. Flat top-level collections, keyed `{schoolId}_{entityId}`
Firestore is not organised per-school subtrees; every document id is prefixed with its owning school.
A writer that mints an id without the school prefix (or a reader that doesn't filter by it) breaks
isolation even though the collection itself is shared.
*Test it:* spot-check a new collection's doc ids for the `{schoolId}_` prefix; confirm the collection
has no query path that omits `schoolId`.
Cited: `CLAUDE.md` "Cross-system contracts → Data layout". Demonstrated as a real defect and fix at
`BUG_LEDGER.md` FZ-1 (`Communication_helper` event-notice docId scoping, lines 1586–1590) and the
CARRY-005/CARRY-011 remediation of un-prefixed historical notice docs (lines 1320–1351, 1520–1554).

### 5. Write ownership is split — panel + Cloud Functions own most writes; apps mostly read
The Teacher and Parent apps write only their own module's documents (e.g. a teacher creates a
homework or a quick-flag); most state mutation flows through the admin panel or a Cloud Function.
*Test it:* for a new app-side write path, confirm the corresponding Firestore rule scopes the write
to the module actually owned by that role, not a blanket authenticated-write.
Cited: `CLAUDE.md` "Cross-system contracts → Write ownership".

### 6. Push has exactly one door — `pushRequests` + the `MARK_REGISTRY` Cloud Function
Every notification is sent by writing a `pushRequests` doc via `MY_Controller::emit_push()`; the
`MARK_REGISTRY` dispatcher in `functions/index.js` is the sole resolver/sender. One mark, one sender,
ever — CLAUDE.md records that three separate senders existed historically and double-sent.
*Test it:* grep for any FCM send call outside `functions/index.js`; confirm a new push type has
exactly one `MARK_REGISTRY` entry.
Cited: `CLAUDE.md` "Cross-system contracts → Push has exactly one door". Corroborated by two commits
that had to harden this exact contract after the fact: `a7a86a1` ("push: record `undelivered` when a
mark reaches zero devices") and `4c989d0` ("Support: push dedupe keys must be stable, or at-least-once
means twice") — dedupe-key stability is what keeps "one door" from becoming "two sends."

### 7. RBAC is graded (`view|edit|manage`) via `has_permission()`; `ModuleGate.kt` fails OPEN
`has_permission($module, $level)` / `require_permission()` in `rbac_helper.php` is the graded
authority; effective access = `union(staff_roles[]) + extra − denied`. In the Teacher app,
`ModuleGate.kt` mirrors this for navigation but **fails open when capabilities haven't loaded** — it
is UI polish only. Firestore rules are the only real enforcement boundary for app-side RBAC.
*Test it:* never trust a client-side gate as the security boundary in review; the rules test suite is
the one that must deny.
Cited: `CLAUDE.md` "RBAC is one unified catalogue". This exact drift class was found live:
`STAFF_ROLES_MODULE_INVESTIGATION_AND_UAT.md` §1.10 I11 — the Teacher app "gates on presence only
(edit/manage not honored in-app UI; rules are the real level enforcer)" — and I1 in the same section,
where a *server-side* graded check was itself defeated by a coarser legacy name-list, proving the
graded check has to be the one enforced everywhere, not assumed.

### 8. Storage paths are always `schools/{schoolId}/...`
No school's files live outside its own storage prefix; Storage rules are keyed off this path shape.
*Test it:* upload from a foreign-school session and confirm the path-based rule denies it regardless
of the client-sent metadata.
Cited: `CLAUDE.md` "Cross-system contracts → Storage path scheme". Storage rules under this scheme
have needed real hardening more than once — `c775dfe` ("storage: support attachments were deletable —
`allow write` grants delete") and `45281ab` ("Storage: withdraw the school-wide read and re-issue it
per sub-tree, minus support") — the path convention held, but the *grant shape* within it did not.

### 9. Staff PII is split — `staffPrivate/{schoolId}_{staffId}` is owner-readable only
Sensitive staff fields never live in the general `staff` doc; they live in a separate,
owner-scoped collection.
*Test it:* confirm a non-owner (another staff member, an admin without the relevant grant) cannot
read `staffPrivate` for someone else.
Cited: `CLAUDE.md` "Cross-system contracts → Staff PII is split".

### 10. Firestore/RTDB rules are the only real security boundary — "not in git" does not mean "not live"
Anything not explicitly gated in the deployed ruleset is unprotected, regardless of what the app
layer or UI assumes. Production can (and has) held rules that exist in nobody's local checkout.
*Test it:* `node aegis/cli.js rules status` before trusting any rules-adjacent assumption — it reads
the *deployed* ruleset, which git cannot.
Cited: `CLAUDE.md` "Traps that keep biting → firestore.rules is one shared file". Demonstrated at
maximum severity by `BUG_LEDGER.md` CARRY-013 (lines 1644–1701): production RTDB rules were found to
be `{".read": "true", ".write": "true"}` — open to any authenticated *or unauthenticated* caller,
across 124+ admin-web RTDB call sites and both mobile apps — while the repo had no
`database.rules.json` at all until that session added one. Also demonstrated repeatedly on the
Firestore side: `d3d396b` ("Commit and deploy firestore.rules blocks that git did not have") and
`aecbf87` ("firestore.rules: reconcile 46 production-only blocks + restore students.prefLang").
