# Student Entitlements — Final Pre-Implementation Architecture Review

**Reviewing:** `ADMISSION_SERVICES_AND_EXEMPTIONS_RESEARCH_AND_DESIGN.md` (Phases A–F), treated as one production migration.
**Posture:** Adversarial. The job is to break the design proposed by the same author, not to bless it.
**Status:** Review only. **No code written. Nothing implemented.**
**Date:** 2026-08-07

**Method note:** Before writing this review, I went back into the live codebase to pressure-test the design's own load-bearing assumptions rather than re-read my own document and agree with it. Two checks changed the risk picture materially — both are Critical Issue #1 and #2 below. Everything in this review is labeled **[VERIFIED]** (read directly from code, cited `file:line`), **[VERIFIED-DIRECTIONAL]** (strong evidence, not exhaustively proven), or **[UNVERIFIED — gate before proceeding]** (a real open question the design currently assumes an answer to).

---

## Critical Issues

### C1 — [VERIFIED-DIRECTIONAL] The design's core generation-correctness claim assumes a demand-generation model that may not match reality

§5.2 of the design states: *"a period's demand was already generated correctly at generation time, from whatever was active for that month... the generator simply stops reading it for future periods."* This assumes demand generation is an **ongoing, recurring, period-by-period** process — something that runs monthly and can simply "not see" a closed entitlement from then on.

I went back and checked this. Evidence points the other way:

- `Fee_lifecycle.php:918` computes `12 - ($joinMonth - ACADEMIC_START_MONTH)` — a **months-remaining-in-session** count, used at admission time.
- `Fee_lifecycle.php:21` logs: *"NO fee structure for [...]; student admitted with ZERO fee demands. Set up fees for this class then run **Generate Demands** (or re-save the Fee Structure) to bill this student."* — this is recovery language for a **manually-triggered bulk operation**, not evidence of an automatic monthly cron.
- I searched for a scheduled job that re-runs generation monthly. **None found** referencing `Fee_generation_service`/`assignInitialFees` in any cron/scheduler controller.

Put together, the more likely model is: **`assignInitialFees` generates the entire remaining session's monthly demand documents in one batch, at admission**, and "Generate Demands" is an operator-triggered catch-up action, not a recurring monthly event the entitlement log can simply "not be read by" next month.

**Why this matters:** if all of a student's April→March demands already exist as documents the moment they're admitted, then closing an entitlement in October (per §5.2's "the generator simply stops reading it for future periods") does **nothing** — the November–March demand docs were already written in April, at the old (or absent) entitlement state. §5.2 as written does not fix G1/G2; it just moves the same bug into the new architecture with better-sounding language.

**What must happen instead, and must be decided before Phase C starts:**
1. **Confirm the actual generation model** — read `_routedBuildAdmissionSpecs()` and its non-unified sibling in full, and instrument one real admission in a staging environment to count how many `feeDemands` documents a single `assignInitialFees` call produces. This is a fact-finding task, not a design decision — do it first.
2. If generation is confirmed session-wide/upfront (my directional read), the design needs an explicit **regeneration step**: any entitlement change must archive/void the affected student's **unpaid future-period** demand documents and re-run generation for the remaining periods from the new entitlement state. This is a materially different, riskier mechanism than "stops reading it" — it needs its own transactional design, not a footnote.
3. If generation turns out to be genuinely incremental (a monthly job I didn't find), §5.2 is correct as written and this entire finding downgrades to informational.

**This is the single highest-priority open question in the whole design.** Nothing in Phase C should be built until it's answered.

### C2 — [VERIFIED] No credit-note/reversal mechanism for a PAID demand that needs retroactive adjustment

I checked where accounting journals actually post. **Journals post at payment/collection time, not at demand-generation time** — `create_fee_journal` is called from `Fee_management.php`, `Admission_public.php`, `Admission_collection.php`, `FeeWorker.php`, `AccountingReconciler.php`, `AccountingSimulator.php`. **Zero calls exist in `Fee_lifecycle.php`**, where `assignInitialFees`/`createModuleFee`/demand generation lives. This is good news for unpaid demands (see C1: they can be freely regenerated, no journal exists yet) — but it exposes a gap for paid ones.

**The scenario the design doesn't cover:** a parent pays September's tuition in full. In October, an RTE/EWS concession is approved retroactive to September (a real, common case — the family submitted documents in August, verification took six weeks). September's demand was already paid and already has a posted journal entry. **Nothing in §5, §8, or the invariants (I1–I5) says what happens to that journal.** Silently leaving it as-is means the family effectively pre-paid a fee they were later exempted from, with no credit and no record that they're owed one. Silently editing the paid demand's amount would corrupt an already-reconciled ledger.

**Required before Phase C ships:** an explicit rule — most likely, an entitlement's `effectiveFrom` can be backdated, but **the generator only ever regenerates unpaid future periods**; any period already paid at the time of approval gets a **credit-note entitlement** (a `kind=concession` row scoped to a single past period, or a new `kind=credit_note` with its own journal-reversal path) rather than a silent retroactive rewrite. This needs the same rigor as the RTE `govtReceivable` sub-object already in the schema — it's the same category of problem (money owed *back*, not money owed *forward*) and deserves the same explicit modeling, not an implicit assumption.

---

## High Risks

### H1 — RBAC `Entitlements:manage` conflates two unrelated approval decisions

§5.5 gives one capability, `Entitlements:manage`, authority over **both** concession approval (a money decision — does this family get 15% off, is the RTE claim genuine) **and** service entitlement decisions (a capacity/operations decision — is there a seat on Route 2, is Twin Room 14 actually free). These require different competence and, in a real school, are usually different people (Accountant/Principal vs Transport Manager/Hostel Warden). As designed, granting `Entitlements:manage` to a Principal gives them nominal authority to approve a hostel room allocation they have no visibility into whether there's capacity for, and a Transport Manager granted the same capability (because they legitimately need to approve route changes) also gains authority over fee concessions they have no business approving.

**Fix:** split the *approve* side by kind. `Entitlements:manage` governs concession/scholarship/RTE approval only. Service entitlements (transport/hostel) continue to gate their *approval* step through the existing `Transport:manage`/`Hostel:manage` modules — which already exist, are already capacity-aware, and already have the right people holding them. Only the *propose* side (`Entitlements:edit`) should be the shared, cross-cutting capability — because proposing is low-risk and benefits from one consistent UI regardless of kind. This is a schema/RBAC-wiring change, not a new mechanism — cheap to fix now, expensive to unwind after Phase E ships an approval queue built around the wrong grant.

### H2 — No denormalized entitlement reference on `feeDemands` — reintroduces the N+1 risk the delivery plan only flags as a "watch item"

§6's cross-module table says the Parent App's fee-breakdown screen will "show concessions applied" — but the `studentEntitlements` schema (§5.1) has no field that lets a `feeDemands` document say *which* entitlement rows produced its net amount. Displaying "why is my fee ₹3,570 instead of ₹4,200" therefore requires a second query per demand against `studentEntitlements`, for every demand line, on every screen that shows a breakdown. §9 mentions this class of risk only as something to "watch" on gate 8 — it needs to be closed at the schema level, not monitored at runtime.

**Fix:** at generation time, stamp each `feeDemands` doc with `entitlementRefs: [entitlementId, ...]` and a `netEffect: {gross, deductions: [{entitlementId, kind, amount}], net}` snapshot. This also solves a correctness problem, not just a performance one: if an entitlement is later edited or revoked, the *demand* still shows exactly what applied *when it was generated* — an audit-correct snapshot, not a live join that could drift.

### H3 — Firestore composite index budget is already at 257 entries; the design proposes adding to it without checking the ceiling

**[VERIFIED]** `firebase-rules/firestore.indexes.json` currently defines **257** composite indexes. The design proposes at least two more (`studentEntitlements` × `(schoolId, studentId, status)` and `(schoolId, status, effectiveFrom)`). I have not re-verified today's exact Firestore quota for a project at this tier — but at 257 already committed, **this must be confirmed before Phase B, not discovered after a deploy fails.** If the project is at or near its ceiling, Phase B's own indexes could be blocked, and every other module's future index needs are now competing for a shrinking budget this design would be consuming without anyone having checked.

**Fix:** treat "confirm current Firestore composite-index quota vs. actual usage, with headroom for Phase B's 2 new indexes plus a reasonable buffer for other in-flight work" as a literal release gate for Phase B, not an assumption.

### H4 — Transactional ordering: entitlement writes could reference a student that doesn't exist yet

Path 3 (`save_admission()`) creates the `students` doc directly, with no `crmApplications` intermediate. If the new admission wizard (Phase E) writes `studentEntitlements` rows in a separate call *before* or *racing with* the student-creation write — a plausible implementation shape if "create student" and "commit entitlements" are two separate service calls glued together in a controller — a failure partway through leaves **orphan entitlement documents referencing a `studentId` that was never created**, or a **student with no entitlements** despite the wizard showing them as selected. Neither the coverage matrix (§2.1) nor invariants I1–I5 states an ordering guarantee here.

**Fix:** make this an explicit invariant (I6): *student-doc creation and its initial entitlement writes commit as one atomic unit (Firestore transaction or batch) or not at all.* This is the same discipline already used for `crmEnrollLocks` in `enroll_student()` — extend it, don't invent a new pattern.

### H5 — Concession stacking is undefined

Nothing in §5.1–§5.4 specifies what happens when a student qualifies for **more than one** active concession simultaneously — e.g. a sibling discount (15%) *and* a staff-ward discount (50%) on the same student. Do percent concessions stack additively (65% off)? Does only the largest apply? Is there a combined cap? This is not a hypothetical — sibling + staff-ward is a completely ordinary real-world combination in any school with faculty children. Left unspecified, whoever implements Phase C will pick an answer by accident, and it will be wrong for at least one of the two intended semantics.

**Fix:** explicit stacking rule needed before Phase C: percent concessions stack additively, clamped combined at a configurable ceiling (default suggestion: 100%, i.e. never below zero net — separately from `fullExempt`, which always wins outright regardless of what else is active); fixed-amount concessions stack additively, clamped so net never goes negative. State this as invariant I7.

### H6 — No entitlement lifecycle rule on student deletion, TC issuance, or transfer-out

If a student is deleted, issued a TC, or transferred out mid-session, their `studentEntitlements` rows are — per the current design — silent orphans: nothing discontinues them, nothing archives them, and a future report scanning "active entitlements" would still count a transport seat or hostel room held by a student who left months ago (real operational impact: Transport/Hostel capacity counts would be wrong).

**Fix:** wire entitlement discontinuation into the existing student-deletion/TC-issuance code paths (`Sis::delete_student`, TC issuance flow) as part of Phase D, not left implicit.

---

## Medium Risks

### M1 — "A/B against legacy for one cycle" (Phase C) is not mechanically specified

Does A/B mean generating real demands through *both* the legacy and new paths and diffing them (double the writes, double the cost, and a real risk of accidentally persisting both if the diff step has a bug)? Or a dry-run comparator that runs the new logic read-only and diffs against what the legacy path *would* produce, without writing the "B" side at all? The design should mandate the latter explicitly — a **read-only shadow comparison**, logged, never persisted twice. As written, an implementer could reasonably build the expensive, riskier version.

### M2 — Approval-queue ordering and pagination aren't specified

The proposed index `(schoolId, status, effectiveFrom)` orders the approval queue by *effective date*, not by *how long a request has been waiting* — for an SLA-driven queue ("escalate a `pending_approval` entitlement that's aged out"), the natural sort is age of request, not effective date of the change. Recommend adding `(schoolId, status, requestedAt)` as the queue's real index, and explicitly require cursor pagination in the UI spec (§7) — a mega-school's queue growing past one page isn't addressed today.

### M3 — Approval deadlock has no defined escalation target

§8's edge cases correctly flag "approval-required concession never actioned (SLA breach — escalate, don't silently keep billing)" but never say *who* it escalates to. If the only `Entitlements:manage` holder in a school is on leave, requests queue forever. **Fix:** since `Super Admin`/`School Super Admin` already unconditionally bypass every RBAC gate (`MY_Controller.php:701-710`, confirmed), the natural, zero-new-machinery backstop is: SLA-breached entitlements auto-notify School Super Admin. State this explicitly rather than leaving "escalate" undefined.

### M4 — Parent-initiated **concession** requests (not just service requests) are in the review's explicit scope but out of the design's actual scope

The user's review explicitly lists "Concession Request" as a Parent App flow to verify. The design's Phase F text only ever says "Parent-facing mid-year request flow" for **transport/hostel/meal** — a parent claiming new EWS eligibility mid-year, or requesting a scholarship review, was never explicitly scoped in or out. This needs a decision: if in scope, it needs its own sub-design (most likely: document upload + a distinct verification step, since a concession claim needs evidence a service request doesn't) — currently unspecified either way.

### M5 — Late-fee interaction with concession-adjusted amounts is unverified

The design doesn't state, and I did not verify, whether late-fee surcharge logic (wherever it lives) computes against the stored (already net-of-concession) `feeDemands.amount`, or independently re-derives a gross amount from the fee chart. If the latter, a legitimately concession-reduced student could be charged a late fee calculated against the *original* amount. Flag as a verification item before Phase C, not resolved here.

### M6 — Promotion's interaction with entitlements is implicit, not designed

`reassignFeesOnPromotion` (`Fee_lifecycle.php`) already calls `assignInitialFees` internally — so *if* Phase C properly routes `assignInitialFees` through the entitlement-aware generator, promotion inherits correct entitlement-aware billing "for free," and a sibling discount survives a class change without extra work. This should be **stated as a design requirement**, not left to be discovered as a side effect — an implementer who patches `assignInitialFees` directly but leaves `reassignFeesOnPromotion` calling an older code path would silently reintroduce G1-class bugs at every promotion cycle.

---

## Low Risks

- **L1 — Stale-cache exposure unverified.** `Accounting_cache`/`Accounting_period_lock` are used elsewhere in the fee/payment path (`collect_admission_fee`); whether `Fee_generation_service` or a future fee-preview endpoint reads through a cache that needs explicit invalidation on entitlement approval was not verified. Check before Phase C.
- **L2 — Offline queue for the Parent App's request flow is unverified against existing patterns.** Recommend reusing whatever offline-write queue already exists for fee payments/other parent-initiated writes rather than building a second one; I did not confirm what that existing pattern is.
- **L3 — Unbounded `feeDemands` growth has no archival/TTL plan.** Not urgent at current scale, but at 500,000 students × multi-year retention with one doc per fee-head per month and no archival mechanism found beyond promotion-triggered archiving, this is a multi-year storage/backup-cost conversation worth having before it becomes urgent.
- **L4 — RTE `govtReceivable` has no owning module.** The design correctly declines to build a government-facing reconciliation module in this phase (§9, "what I would NOT build") — but that means the receivable object will sit unmonitored until *some* future module claims it. Fine as a phased decision; worth a one-line note in the backlog so it isn't forgotten.

---

## Recommended Changes (consolidated)

1. **[Blocks Phase C]** Resolve C1 — confirm whether demand generation is session-wide/upfront or incremental, before designing the regeneration mechanism.
2. **[Blocks Phase C]** Design and add an explicit credit-note/reversal path for paid, journal-backed demands affected by a retroactive entitlement change (C2).
3. **[Blocks Phase E's approval UI]** Split `Entitlements:manage` — concessions only; route service (transport/hostel) approval through the existing `Transport`/`Hostel` module capabilities (H1).
4. **[Schema change, cheap now]** Add `entitlementRefs`/`netEffect` snapshot fields to `feeDemands` at generation time (H2).
5. **[Blocks Phase B]** Confirm current Firestore composite-index quota and headroom before adding any new index (H3).
6. **New invariant I6:** student-doc creation and initial entitlement writes are one atomic unit (H4).
7. **New invariant I7:** explicit concession-stacking rule (H5).
8. Wire entitlement discontinuation into student deletion/TC issuance (H6).
9. Specify Phase C's "A/B" as a read-only shadow comparison, not a double-write (M1).
10. Add `(schoolId, status, requestedAt)` index; require cursor pagination on the approval queue (M2).
11. Explicit SLA-breach escalation target: School Super Admin (M3).
12. Decide and document whether parent-initiated concession requests are in scope for Phase F (M4).
13. Verify late-fee computation reads net (post-concession) amounts (M5).
14. State the promotion/entitlement interaction as a Phase C requirement, not an implicit side effect (M6).

None of these require redesigning the core concept. They tighten it.

---

## Section-by-Section Findings

### 5. Admission Workflow — all three pathways

Re-verified against §0 of the design doc: the three-pathway analysis holds up. The one addition from this adversarial pass is **H4** (transactional ordering) — specific to path 3, since it's the only one of the three that writes `students` without a `crmApplications` staging document first, making it the pathway most exposed to a partial-write orphan.

### 6. Parent App

Transport/Hostel/Meal request flow: covered by the existing design, contingent on H1's RBAC split. **Concession Request: gap, see M4.** Approval flow: sound in concept, needs M3's escalation target. Offline mode: unverified, see L2. Push notifications: the design doesn't specify new machinery — correct, should reuse `notification_helper.php`'s existing pattern (same one `notify_admission_confirmed` uses) rather than invent a second notification path.

### 7. RBAC

Full role list requested by the review — School Admin, Principal, Accountant, Transport Manager, Hostel Warden, Admissions Officer, Front Office, Parent, Teacher. The design's two-level model (`edit`=propose, `manage`=approve) is right in shape but wrong in grain — see H1. One clarification worth stating explicitly rather than assuming: **Parent and Teacher are not part of the `staffRoles`/`RBAC_MODULES` graded system at all** — they authenticate and authorize through an entirely separate mechanism (phone/child-linkage for parents, Firebase-claim-based for teachers). A parent proposing an entitlement is authorized by *owning the student record*, not by holding any `Entitlements:edit` grant — the design should say this plainly rather than let a reader assume parents sit somewhere in the same permission table as staff.

### 8. Student 360

**Does not exist in this codebase.** Grepped exhaustively — zero matches for any "Student 360" concept anywhere in `application/`. The closest existing analog is `Sis::profile()`/`Sis::student_profile()` (`Sis.php:700,5472`). Recommendation for whenever a consolidated profile view is eventually built: it must read entitlements through the single canonical `Fee_entitlement_reader` this design already proposes merging (§6) — never a bespoke query against `studentEntitlements` written independently by that future module. This is the actual mechanism for "no duplicate reads, no duplicate business logic, one source of truth" — worth stating as a standing rule for any future consumer, not just Student 360 specifically.

### 9. Performance — reads/writes, current vs. target

| Operation | Current (approx.) | Target (approx.) | Notes |
|---|---|---|---|
| Per admission (any of 3 paths) | ~28 writes (§6 of design doc's own estimate) | ~28 writes + 1-3 entitlement writes + 1 fee-preview read (dry-run, no write) | Marginal increase; the preview read should be a single call, not per-field |
| Per fee-generation run (one student, one session) | N demand writes (N = months × heads), one batch | Same N, plus **one** entitlement-window read up front (if C1 confirms upfront generation) | If C1 resolves to incremental generation instead, this becomes N reads (one per period) unless batched — must be re-estimated once C1 is answered |
| Per mid-year entitlement change | N/A (mechanism doesn't exist today) | 1 entitlement write + regeneration of unpaid future periods (bounded by months remaining, typically ≤ 8) | This is new cost that doesn't exist in the current system at all — size it once C1 is resolved |
| Approval-queue screen load | N/A | 1 indexed query, paginated (M2) | Must not become a full-collection scan at scale |

**This table cannot be finalized until C1 is resolved** — every "target" row assumes an answer to the open question. Flagging the table as provisional rather than pretending precision that doesn't exist yet.

### 10. Future modules

| Module | Impact |
|---|---|
| Student Promotion | Real interaction — see M6. Free correctness inheritance IF wired correctly, silent regression risk if not. |
| Library, Inventory, Medical, Communication, ID Cards, Document Studio, Certificates | No interaction found or expected — these read academic/identity data, not billing entitlements. No redesign risk. |
| Transport GPS (F13) | No interaction — GPS is operational tracking, entitlements are billing/capacity. Confirmed disjoint. |
| Hostel | Direct interaction, already covered in Phase D. |
| Accounting, Payroll | Accounting: direct interaction via C2's credit-note gap. Payroll: no interaction — staff billing is a fully separate domain, no shared collection. |
| AI Features | Too unscoped to assess meaningfully. No known constraint today; revisit once a concrete AI feature touching fees/admission is proposed. |

### 11. Implementation order — challenged

**The A→B→C→D→E→F sequence is directionally correct but incomplete.** Specific changes:

- **Insert a Phase 0 (verification gate) before B**, covering exactly the three things this review had to dig for: (a) confirm the demand-generation model (C1), (b) confirm Firestore index headroom (H3), (c) confirm journal-posting timing assumptions used in C2's design (already directionally confirmed here, but re-verify against the specific generator code before Phase C's implementation begins). This is cheap — it's research, not code — and every phase after it depends on getting these three facts right.
- **Phase C must ship with the credit-note mechanism (C2) built in**, not deferred to a later phase — a concession-approval workflow that can't handle "approved after the family already paid" isn't done, it's half-done in a way that will surface as a support ticket the first month it's used.
- **Decouple Phase E.1 (the G10 payment-fee fix) from Phase E's timeline entirely.** E.1 has zero dependency on the entitlement-log work — it's wiring an existing, working payment function into a screen that currently skips it. It should ship as soon as the operator answers §11 decision #1, independent of where the rest of the roadmap stands.
- **RBAC split (H1) must land before Phase E's approval-queue UI is built**, not after — building the UI around a single `Entitlements:manage` grant and then splitting it later means reworking the UI's permission checks, not just a backend change.

Revised order: **Phase 0 (verify) → A → B → C (with credit-note) → D → E (with H1's RBAC split already decided) → F**, with **E.1 running independently, in parallel, whenever the operator decision lands.**

### 12. Production safety — per phase

| Phase | Rollback | Verification | Acceptance criteria | Go/No-Go |
|---|---|---|---|---|
| 0 (new) | N/A — research only | C1/H3/C2-timing confirmed in writing | All three facts documented with evidence | Go only once all three are answered |
| A | Revert the isolated patches (frequency default, suspend→Fee_lifecycle call, RBAC name-list addition) | Reproduce G1/G2 pre-fix, confirm fixed post-fix, on a real suspend/unassign | Suspending stops billing next period; unassign proration actually adjusts the amount | No-Go if any existing paid demand is touched by the fix |
| B | Drop the new collection/indexes; nothing else reads them yet | Dry-run migration report reviewed by a human before any write | Zero writes from the dry-run tool; migration report accounts for 100% of legacy rows (mapped or explicitly flagged unmapped) | No-Go if any row is silently dropped |
| C | Flip `USE_UNIFIED_FEE_GEN`-equivalent flag back; legacy `studentConcessions` reads resume | Shadow-comparison (M1) byte-identical across a full cycle; credit-note path tested against a real paid-then-changed scenario | New generator output matches legacy for 100% of a test cohort; credit-note produces a correct reversal, not a silent edit | No-Go if C1 is unresolved or C2's credit-note path is untested |
| D | Flip Transport/Hostel back to direct `createModuleFee` calls | Suspend/unassign tested against the entitlement-driven path in staging with real route/room capacity data | Capacity counts stay correct after discontinuation; H6's cleanup fires on student deletion/TC | No-Go if capacity counts drift |
| E | Feature-flag the wizard step off per pathway independently (public form / CRM / direct) | Full run of all three pathways in staging, including the no-payment path 3 warning | Fee preview matches what actually generates; H1's RBAC split enforced before any approval action is possible | No-Go if any pathway's wizard can create an entitlement the generator doesn't yet honor |
| E.1 | Revert the single new call in `save_admission()` | Confirm `admission_resolve_fee()`/`Admission_collection` behave identically whether called from path 1/2 or 3 | One receipt, one journal, same as paths 1/2 | No-Go if a second receipt mechanism gets invented instead of reusing the shared one |
| F | Feature-flag the parent-facing request screen off | Approval SLA/escalation tested with a simulated inactive approver | M3's escalation actually fires and reaches School Super Admin | No-Go if a request can get permanently stuck with no escalation path |

Every phase's regression test set should include re-running Phase A's G1/G2 reproduction — a regression here is the single worst possible outcome, since it's real money and it's already bitten families once silently.

---

## Architecture Approval

**Conditional.** The core concept — one entitlement log, dated and stateful, replacing four disconnected mechanisms, with generation reading the log rather than a mutable flag — is sound and should not be redesigned from scratch. It is well-grounded in both the external research (§1 of the design doc) and the forensic audit of what's actually broken today (§2). The RBAC foundation it builds on (§2.3) is genuinely well-designed and correctly not being replaced.

Approval is conditional on closing **C1 and C2 before Phase C begins**, and **H1 before Phase E's approval UI is built**. These are not stylistic preferences — C1 in particular means the design's stated fix for the very bug that motivated this whole project (G1/G2) does not yet demonstrably work, pending one verification step.

## Final Implementation Order

**Phase 0 (verify) → A → B → C (credit-note included) → D → E (RBAC split included) → F**, with **E.1 decoupled and shippable independently as soon as §11 decision #1 is answered.**

## Production Readiness

**Not yet.** Design readiness: high, contingent on the changes above. Implementation readiness: blocked on Phase 0's three verification items. Nothing here should be treated as "ready to code" until C1 specifically has a confirmed answer — everything downstream of it (the generation rule, the performance table, the migration shadow-comparison) is provisional until then.

---

## The One Question

**"If you were the CTO responsible for a School ERP serving thousands of schools, would you implement this architecture exactly as designed?"**

**No.**

Not because the direction is wrong — it's the right direction, and I'd keep the core concept, the RBAC approach, the migration discipline, and the phase boundaries essentially as they are. But "exactly as designed" fails on one specific, concrete point: **the design's own explanation of how it fixes the bug that started this entire project (§5.2) rests on an assumption about demand-generation timing that I could not confirm, and the evidence I found while checking points the other way.** Shipping Phase C on that assumption risks doing the worst possible thing a billing-correctness project can do — declaring a financial bug fixed when it structurally isn't, with a design document that reads as confident as this one does. A CTO's job is to not let a well-written document substitute for a verified fact.

The second reason is smaller but real: no credit-note mechanism exists for the case where a family already paid before a concession was approved. That's not an edge case in a school — it's a normal Tuesday in an admissions office. Shipping without it means the first support ticket about it becomes the team's introduction to a gap that should have been designed, not discovered.

Everything else — the RBAC grain issue, the missing denormalized fields, the index-budget check, the stacking rule, the transactional-ordering gap — is real and should be fixed, but none of it is a reason to stop the project. It's a reason to spend one more research cycle (Phase 0, as scoped above) and one more design pass on two sections (§5.2's generation rule, and a new credit-note section) before any code is written.

**Prove why, if the answer were yes:** the honest version of "yes" would require being able to say, right now, exactly how many `feeDemands` documents one admission call produces and exactly what happens to a paid demand when its governing entitlement changes retroactively. I can't say either with certainty today. Once Phase 0 answers both, the honest answer to this question becomes yes.
