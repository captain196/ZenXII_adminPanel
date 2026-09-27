# Phase A+1 Backlog — Student Exit → Transport Cleanup

**Status:** NOT STARTED. Not authorized to implement. Second task after Phase A closes (after
`PROMOTION_TRANSPORT_BILLING_RECOVERY_BACKLOG.md`).
**Severity:** Medium (no money at risk — fees are already correctly frozen — but real, compounding
capacity-accuracy and orphaned-data risk).
**Origin:** discovered during Phase A's G1/G2 adversarial re-audit (2026-08-08), while stress-testing the
"TC" scenario against the actual TC-issuance code path.

## The evidence (already gathered — starting point for the audit, not a substitute for it)

Grepped `Sis.php` for every reference to `Transport_service`/`unassignStudent`: **zero matches.** Both TC-issuance (`issue_tc()`, calls `freezeFeesOnSoftDelete()` around [Sis.php:1992](application/controllers/Sis.php#L1992)) and soft-delete (`delete_student()`, same call around [Sis.php:5357](application/controllers/Sis.php#L5357)) freeze the student's fee demands (all categories, correctly — no further billing) but never touch the `studentRoutes` assignment doc or the bus capacity cache.

Consequence: a Transport-assigned student who gets a TC or is soft-deleted keeps their `studentRoutes` doc and their reserved seat in `busCapacityCache.current_count` **forever**. `Transport_service::rebuildCapacityCache()` doesn't help — it counts live `studentRoutes` docs by route+session with zero check of whether the referenced student is still active, so a rebuild doesn't self-heal the leak either. Over a school year, especially at mass year-end TC issuance for a graduating batch, this compounds into routes appearing full when they aren't, wrongly blocking new assignments via `_reserveCapacitySeat()`'s capacity check.

This is pre-existing, not something Phase A's G1/G2 fix touches or worsens.

## Required audit scope (per operator instruction)

1. **Audit every exit pathway, not just TC/soft-delete**: TC issuance, soft delete, **hard delete** (if one exists — confirm), withdrawal (if a distinct flow from TC exists in this codebase — confirm), and session-end exit (a student who simply isn't re-enrolled for the next session — does anything mark them exited, or do they just silently not appear in the rollover chunk query?).
2. **Ensure transport assignments are released correctly on exit** — decide and implement the correct action per pathway: full `unassignStudent()` (removes the doc, releases capacity, prorates the final balance) is the obvious default for a permanent exit; confirm there's no legitimate reason to instead just freeze-in-place (e.g., an appeal/reversal window on TC where the assignment might need to be restorable).
3. **Ensure capacity counters and caches are released correctly** — `_bumpCapacityCache(-1)` on exit, plus verify `rebuildCapacityCache()` gets a matching audit-side check added (e.g., cross-reference against student active status) so a rebuild can self-heal any historical leak from before this fix ships, not just prevent new ones.
4. **Ensure related records are released/finalized correctly** — the assignment-history log (`_appendAssignmentHistoryEvent`), any open suspension state, `fee_demand_ref` — all need a defined terminal state on exit, not just deletion of the primary doc.
5. **Ensure billing stays consistent** — confirm `freezeFeesOnSoftDelete()`'s existing behavior (correct today) is preserved exactly; this backlog item is about the assignment/capacity side, not re-litigating the fee-freeze mechanism.
6. **Ensure reporting stays consistent** — any Transport occupancy/utilization report reading `studentRoutes` by route+session needs to reflect the exit immediately, not carry a phantom passenger.
7. **Ensure audit history remains consistent** — the exit action itself (TC/delete/withdrawal) should produce a Transport-domain audit entry (`emitAudit('studentRouteAssignment', ..., 'released_on_exit', ...)` or equivalent) so the unassignment is traceable to the triggering exit event, not just logged as an unexplained standalone unassign.

## Do not

- Do not implement a fix that assumes TC and soft-delete are the only two exit paths — confirm the full list first (item 1 above); a fix scoped to only the two paths found so far may miss others.
- Do not touch `freezeFeesOnSoftDelete()` itself — it's already correct; this backlog is additive (call Transport cleanup alongside it), not a rewrite of the fee-freeze mechanism.
- Do not ship without a rebuild/backfill plan for schools that already have accumulated phantom seats from before this fix — a forward-only fix leaves existing production data uncorrected.
