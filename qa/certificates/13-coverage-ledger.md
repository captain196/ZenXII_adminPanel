# COVERAGE LEDGER — Certificates / Document Engine · run 2 · 2026-09-04

Counts and **named gaps**. No completeness percentages: a percentage needs a denominator,
and the denominator — the true total of workflows, error paths and transitions — is exactly
what is unknown, because undiscovered items are undiscovered. Where a real denominator
exists it is written as a fraction with both numbers visible.

|                          | Discovered | Modelled | Rows | Open gaps |
|---|---|---|---|---|
| Controllers / libraries  | 1 + 9      | 9        | —    | legacy `Certificates.php` mapped, **not** deeply modelled |
| Public endpoints         | 27 (3 page + 24 AJAX) | 24/24 | see 06 | 5 have no client caller: `preview`, `save_block`, `get_blocks`, `validate`, `archive` |
| Routes                   | 17/24 explicit | — | — | 7 rely on CI3 default routing; **4 of 7 confirmed live by QA-LEAD**; `version_pdf`, `presence`, `leave`, `duplicate` untested |
| Capability grades        | 3 (view/edit/manage) | 24/24 gated | yes | only **manage** exercised this run — view and edit are unprovisioned |
| Firestore collections    | 4 owned + 2 read | 4 | yes | `reusableBlocks` document key shape never traced |
| Firestore rules blocks   | 4 | 4 | yes | `templateSessions` has **no** match block (falls to catch-all deny) |
| Composite indexes        | 7 declared | 7 | 1 | **0 of 7 required by any current query** — provisioned for a feature that does not exist |
| Cloud Functions          | **0** | — | — | none exist for this module; nothing to test |
| Print points             | 8 declared, **0 wired** | 8 | yes | seam verified structurally inert |
| Entity states            | 9 | 9 | yes | `STRANDED` and `PHANTOM` are code-shape only, never observed live |
| Transitions              | — | all | yes | **4 illegal-but-permitted**; **5 business-required-but-impossible** |
| Module invariants        | 11 | 11 | yes | **3 currently violated** (N9 unstripped `version`, N10 archive→reactivate, N11 archived-not-hidden) |
| Background processes     | 1 (presence heartbeat) | 1 | yes | no hidden-tab/idle stop condition |
| Cross-surface capability | 1 surface + 2 app absences | 3 | yes | absence independently confirmed in both apps |
| Test assets (existing)   | 16 PHPUnit files, 9 Jest blocks, 1 browser harness (179 rows) | — | — | harness writes to **real tenant data**; 71 of 85 live templates are its debris |
| Live population          | 85 heads, 5 published, 2 active, 2 archived | — | yes | single school only; no second tenant available |

## Agents

**Spawned: 12 of 12.** None skipped.
- **A3 · MOBILE-SPEC — deliberate depth reduction.** Full lifecycle/rotation/process-death
  analysis was not performed, because the surface does not exist. Mandate narrowed to
  *proving* the absence with a reproducible search. **This is a scope decision, and it is a
  coverage gap if the absence is ever wrong.**
- **A7 · DIFF-ANALYST — re-aimed.** Classic cross-surface parity is thin (one surface), so
  A7 was pointed at the divergences that do exist. That decision found the run's
  scope-changing result (the legacy system issues certificates).
- **A11 · ADVERSARY — completed its deliverables, then hit a session rate limit** while
  composing its summary. Both files were already written; its verdicts were recovered from
  disk by QA-LEAD and independently re-verified for OV1. **No loss of coverage.**

## Unexamined areas — named

1. **Legacy `Certificates.php` internals.** Mapped (692 lines, 17 routes, RTDB, 0 tests) and
   confirmed to issue real certificates. **Not modelled, not attacked, no state machine.**
   It is the system the sidebar actually exposes. Largest single gap in this run.
2. **The two apps beyond absence.** Confirmed to contain no certificate code. No further
   analysis performed, correctly — but if a print point is wired, this coverage is void.
3. **`view` and `edit` grade behaviour at runtime.** Everything observed this run used
   **manage**. Every permission row is therefore unexecuted.
4. **A second tenant.** No cross-tenant evidence of any kind was obtainable.
5. **Production infrastructure.** PHP `post_max_size`/`memory_limit`, Lightsail RAM/vCPU/disk,
   and whether instance snapshots include `uploads/` — all unread, and the last one gates a P1.
6. **mPDF internals.** Whether it dereferences CSS `url()`, and its image-decoder behaviour
   on hostile input.
7. **Compliance layer depth.** `Doc_compliance` was read as a reporter; the authority data
   and its `verifiedOn`/evidence-grade claims were not audited against sources.
8. **`Doc_serializer` client-side DOM escaping.** A8 scoped itself server-side; `docTitle`
   and `name` rendering in `designer.js` was not audited for XSS.
