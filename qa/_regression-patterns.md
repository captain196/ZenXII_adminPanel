# Regression patterns — what has broken what

Historical breakage relationships: a change to X that broke Y, or two pieces of work colliding. This
is thinner evidence than the other three files — most of `BUG_LEDGER.md` records defects found by
audit, not breakage traced to a specific prior change, and most of git log's defect-shaped commits
don't name what introduced the bug they fix. What follows is only the set of relationships that are
actually traceable to a named cause in the sources. Where a commit message describes a fix but not
what broke it, it belongs in `_patterns.md`, not here.

---

### RTDB library retirement (F28) → broke bulk student promotion → rolled back
Retiring `Dual_write.php` (723 LoC of RTDB-bridge code, believed fully dead) inlined its 3 remaining
live callers directly. The forensic pass that approved the retirement correctly established the
callers were already Firestore-only, but missed that the *wrapping* layer
(`writeToFirestore`'s 2-attempts-then-enqueue retry semantics) was load-bearing fault tolerance for
bulk operations. The very next operator smoke test — promoting 7 students across a class/section/
session change — hit a PHP `max_execution_time` timeout that the retry-queue would previously have
absorbed. Rolled back with a single `git checkout` within the same day.
Cited: `BUG_LEDGER.md` BUG-051, lines 1786–1882 ("REOPENED 2026-05-25 — regression-on-smoke; Tier-1
rollback executed").

### ...and that same rollback investigation surfaced an unrelated real bug
While re-testing the *reversed* direction of the same promotion workflow to confirm BUG-051's root
cause (and definitively clear F28 of blame — the timeout turned out to be pre-existing latency, not
caused by the retirement), the operator's plain description of the reverse-promote outcome ("no
student" after a session switch) surfaced BUG-052: `Entity_firestore_sync`'s 9 sync methods hardcoded
the writer's *current* session instead of honoring a caller-supplied one, silently misfiling promoted
students under the wrong academic year. Unrelated to F28/BUG-051 except by being found during the
same investigation.
Cited: `BUG_LEDGER.md` BUG-052, lines 1885–1938, esp. the "Critical operator observation enabling
discovery" note at line 1892.

### A payment-path refactor (Q4(i) / `FC_OPTIMIZED batch_path`) broke two siblings of the same write, independently
Consolidating the parent-app synchronous Razorpay payment path onto a new batched-commit strategy
dropped two writes that the *pre-refactor* code, and the *sibling* webhook/admin paths, still
performed: the `feeOnlinePayments` audit-trail doc (BUG-044) and the deferred accounting-journal post
(BUG-047). Both were traced to the same migration gap in `parent_verify_payment`, discovered only
because Stage 1 runtime validation compared the parent-path outcome against the admin-path outcome for
the *same* logical payment and found the two diverged.
Cited: `BUG_LEDGER.md` BUG-044, lines 629–670 ("root_cause_post_forensic: Q4(i) refactor migration
gap... sibling pattern to BUG-047. Same Q4(i) regression family"); BUG-047, lines 896–955.

### A rules "withdrawal" fix was itself wrong and had to be reverted
`1a5abee` "Document Engine: withdraw index declarations; record the live drift" removed composite
index declarations believed to be causing drift against production. That withdrawal was incorrect —
`99c3bf4` "Document Engine P1.2: declare the 7 composite indexes — the withdrawal was wrong" had to
re-declare them for real. A fix for a suspected drift problem introduced an actual one.
Cited: commits `1a5abee` and `99c3bf4` (`git -C ~/Desktop/Zennxii_adminPanel log --oneline`).

### A rules reconciliation pass dropped a clause it should have preserved
`aecbf87` "firestore.rules: reconcile 46 production-only blocks + restore students.prefLang" merged
production-only rule blocks back into the repo. The reconciliation itself dropped the staff `prefLang`
self-service clause, requiring a follow-up fix: `8cec39f` "rules: re-apply the staff prefLang clause
the reconciliation dropped". A drift-repair operation produced a new, narrower drift.
Cited: commits `aecbf87` and `8cec39f`.

### A Storage rule tightened to close one hole broke a legitimate retry flow
`31cc721` "storage: the create-only narrowing broke the attachment retry path" fixes a regression
introduced by an earlier narrowing of the Storage write rule to create-only semantics (closing
`c775dfe`'s "allow write grants delete" overbreadth) — the tightening also blocked the legitimate
case of retrying a failed attachment upload, which needs to overwrite/recreate the same path.
Cited: commits `c775dfe` and `31cc721`.

### The same cross-site fix landed at two call sites but missed a third, structurally identical one
The `adminDisabled` predicate fix (treating the field's audit-log Array shape correctly instead of a
stale boolean cast) was applied at `get_tenant_identity` (Phase 1G) and `list_tenants_summary` (Phase
1H) but not at the third call site sharing the exact same predicate,
`_load_enriched_tenants()` — which produced a phantom "DISABLED" badge on an active school in the
School Search page, caught only by an operator walkthrough after the module was otherwise marked
complete.
Cited: `BUG_LEDGER.md` "Walkthrough-caught defect resolution (2026-06-03)", lines 34–44.

---

**Thin on:** commit-traceable "X broke Y" relationships outside the Fees/SIS/Document-Engine/rules
work above — the four module UAT docs (Admission CRM, Red Flags, Staff Roles, Stories) record
findings from audits, not defects traced to a specific prior commit, so they contribute to
`_patterns.md` and `_known-risks.md` but not here. The wider git log is dominated by fix commits whose
messages describe the defect, not its origin, so most of it isn't citable as a regression
*relationship* (X caused Y) without guessing.
