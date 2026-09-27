# RBAC Migration Backlog — incremental legacy retirement

**Created:** 2026-08-08, spun out of Phase A's `RBAC_MIGRATION_REPORT.md` per explicit instruction: fleet-wide
retirement is **not** part of Phase A, but is tracked here as its own program. The operator has flagged a
release in the coming month — this backlog is written to move as fast as is safe, not to be a shelf document.

## Why incremental, even with a release coming

A fleet-wide sweep is the fastest way to break production access, not the fastest way to retire legacy RBAC.
`Transport.php` (audited during Phase A) proved the risk is real, not theoretical: its legacy arrays are
**currently load-bearing** for Principal/Vice-Principal/Operations-Manager-labeled staff, none of which the
graded catalogue grants `Transport` to by default. A blind sweep would have silently locked them out. The
per-controller, prove-then-delete method below is the fast-but-safe path — most controllers should take
minutes once the pattern is applied, and it can run in parallel across controllers since each is independent.

## Scale (measured 2026-08-08)

- **52 controllers** carry hardcoded `*_ROLES` array constants.
- **37 already dual-gate** — every `_require_role()` call site also passes graded `(module, level)` args. These are the fast lane: the graded path is already wired, only the array itself needs proving-safe-to-delete.
- **15 have zero graded args anywhere** — pure legacy, no `(module, level)` argument on any call site in the controller. These need the graded args added first, which is a larger, per-endpoint change (what module? what level?) before the array can even be considered for removal. Slower lane.

## Per-controller procedure (repeat for each of the 52)

1. **Enumerate** every role name in the controller's `*_ROLES` constants.
2. **For each role name**, check whether it resolves via the graded catalogue:
   - Is it a `DEFAULT_STAFF_ROLES` label in `Staff.php`, and does that entry's `permissions` list include this controller's module(s)? → graded-covered.
   - Is `Super Admin` / `School Super Admin`? → always covered (unconditional bypass in `_require_role`, independent of any array).
   - Is `Admin`? → covered via `ROLE_ADMIN` (catalogue-backfilled to all modules) + the `MY_Controller.php:700-717` fold safety net. Confirm `Admin_role_backfill.php` has been run fleet-wide before treating this as universally safe (the safety net covers un-backfilled tenants live, but confirming backfill ran removes the last bit of uncertainty).
   - Anything else (a role with no `DEFAULT_STAFF_ROLES` entry, e.g. `Transport.php`'s `'Operations Manager'`) → **not** graded-covered. Either extend the catalogue first (add the role, grant the module) or leave the array entry in place.
3. **If every role resolves via the graded path**: remove the array (pass `[]`), following the exact pattern used in `Fee_concessions.php` this session — a class-level comment explaining the retirement, evidence cited, `php -l`, done.
4. **If any role does not resolve**: do not touch the array. Either (a) file a catalogue-extension task (grant the missing role the module at the right level) and re-run this procedure once that ships, or (b) leave a comment on the array explaining exactly which role(s) still depend on it and why, mirroring what `Transport.php` needed in `RBAC_MIGRATION_REPORT.md` §3.
5. **Regression-test the module** before moving to the next controller — log in as (or simulate) each role the array used to admit, confirm they still get in; confirm a role that shouldn't have access still gets denied.

## Suggested ordering

Fastest wins first, to show progress quickly against the release timeline:

1. **The 37 dual-gated controllers**, ordered by how many of their roles are obviously graded-covered already (`Admin`, `Super Admin`, `School Super Admin`, `Accountant` are covered everywhere by construction — a controller whose array is *only* those names is a same-shape repeat of `Fee_concessions.php` and should take minutes).
2. **`Transport.php`** specifically is blocked on a catalogue decision, not a code change: does `ROLE_PRINCIPAL`/`ROLE_VICE_PRINCIPAL` get `Transport` added to their default `permissions`, and is `'Operations Manager'` a role that should be formally added to `DEFAULT_STAFF_ROLES`, retired, or aliased to `ROLE_TRANSPORT_MANAGER`? Surface this as a product decision, not a migration-mechanics task — it doesn't belong on this backlog's per-controller loop until that's answered.
3. **The 15 zero-graded-args controllers** last — each needs `(module, level)` arguments designed and added to every `_require_role()` call site before the array can even be evaluated for removal. Treat each as its own small design task (which module? which level per action?), not a mechanical retirement.

## Do not

- Do not remove an array because "it's probably fine" — every entry needs the resolves-via-catalogue check in step 2, per role, with the catalogue read directly (not assumed from a prior controller's outcome — `Fee_concessions.php` being safe told us nothing about whether `Transport.php` was, and it wasn't).
- Do not touch the central `resolve_role_access()` legacy `schools.roles[name]` fallback or its `RBAC_LEGACY_FALLBACK_HIT` instrumentation as part of this backlog — that's the correct, already-instrumented safety net for un-folded tenants and has its own, separate retirement gate (zero hits fleet-wide) referenced in `MY_Controller.php:709`'s `RBAC_RETIREMENT_INVESTIGATION` runbook.
- Do not batch multiple controllers into one commit — each is an independent, individually-regression-tested change; bundling them defeats the "verify no production role loses access" step per controller.

## Status

Not started. `Fee_concessions.php` (Phase A) is the one completed reference example this backlog's procedure was reverse-engineered from. `Transport.php` is audited and intentionally left alone (§3 of `RBAC_MIGRATION_REPORT.md`), pending the catalogue decision above.
