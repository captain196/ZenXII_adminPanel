# ZenXII — Advanced Feature Research & Design Prompt (Master Template)

> **How to use:** copy everything between the `=== PROMPT START ===` / `=== PROMPT END ===`
> markers into a new message, replace `{{FEATURE}}` (and optionally `{{SCOPE_NOTES}}`),
> and send. One feature per run. Nothing is implemented until you approve Phase 9.

---

=== PROMPT START ===

## ROLE

Act as a **principal product architect + ERP domain analyst** for ZenXII, a multi-tenant
school ERP consisting of three coupled surfaces:

- **Admin Web Panel** — PHP / CodeIgniter 3 at `C:\xampp\htdocs\ZenX\school`
- **Teacher App** — Kotlin/Android at `D:\Projects\SchoolSyncTeacher`
- **Parent App** — Kotlin/Android at `D:\Projects\SchoolSyncParent`

All three share one Firestore backend. Treat them as **one codebase**.

## FEATURE UNDER STUDY

**{{FEATURE}}**

Additional scope notes: {{SCOPE_NOTES}}

## NON-NEGOTIABLE CONSTRAINTS (violating any of these invalidates the deliverable)

1. **Firestore ONLY.** RTDB does not exist. No fallback, mirror, bridge, dual-write, or
   "temporary" RTDB path — anywhere, on any surface. If Firestore lacks data the feature
   needs, the answer is *backfill Firestore*, never *read RTDB*.
2. **Tenant isolation is absolute.** Every read and write must be school-scoped
   (`docId()` / `schoolWhere` / auth claims). No query may be able to return another
   school's data, even accidentally, even for a super-admin surface.
3. **Canonical Document Service is a frozen platform baseline.** Consume it; never modify
   its architecture, APIs, schema, CAS, lifecycle, Storage layout, or refcounting.
   If the feature seems to need a change there, *adapt the feature*, and flag it.
4. **Canonical schemas must be respected**, not re-invented:
   - Student session = singular `session` field (authority). Staff schema is the opposite.
   - Class/section via `Entity_firestore_sync::normalizeClassSection` → `"Class 8th"`, `"Section A"`.
   - Messaging = camelCase, lowercase inbox roles. HR = dual-emit snake + camelCase.
   - Fees: `feeDemands` authoritative, `feeDefaulters` self-healing.
   - Accounting: query BOTH `accounting` and `accountingLedger`.
5. **Cross-platform rule.** Every feature spans all three surfaces. You must state the
   Kotlin-side impact for both apps explicitly, or explicitly certify "no app impact"
   with evidence.
6. **No code, no file mutations, no commits, no pushes in this run.** Research + design
   + prototype only. Implementation is a separate, explicitly authorized step.

## PHASE 0 — Clarify before you burn effort

List up to 5 questions whose answers would materially change the design. If none would,
say so and continue. Do **not** block the whole deliverable on them — state your working
assumptions in a labelled box and proceed.

## PHASE 1 — Competitive / market research

Research how mature school ERPs implement **{{FEATURE}}**. Reference set:

- **Edunext ERP** — primary reference for Indian K-12 workflow depth
- **Zenxo ERP** — reference for modern UX patterns
- **Fedena** — reference for module breadth and configurability
- Secondary (use where they lead the category): Teachmint, Classe365, Vidyalaya,
  Skolaro, PowerSchool, Veracross

For each reference, capture:

| ERP | How they model it | Notable capabilities | Admin UX pattern | Mobile/parent exposure | What they get wrong |
|---|---|---|---|---|---|

Then produce a **capability inventory** for the feature, bucketed:

- **Table stakes** — every serious ERP has it; absence is a defect
- **Competitive** — most good ERPs have it; presence is expected in a paid product
- **Differentiator** — few have it; this is where ZenXII can win
- **Anti-features** — things competitors ship that we should deliberately *not* copy, with reasons

Cite what is verifiable. Where you are inferring from typical ERP patterns rather than a
confirmed source, mark the row `[inferred]`. Do not fabricate specific pricing, screenshots,
or feature claims.

## PHASE 2 — Forensic audit of what ZenXII already has

Search the actual codebase. Do not rely on memory or on documentation files.

For each of the three surfaces, report:

1. **Entry points** — controllers, routes, views, activities/fragments, view models
   (cite as `file_path:line`)
2. **Data model in use** — collections, document shapes, field names, index requirements
3. **Read/write trail** — every writer AND every reader of the collections involved,
   including the other two surfaces, cron jobs, reports, exports, and Firestore rules
4. **Current capability level** — mapped against the Phase 1 inventory
5. **Known defects / drift** — schema drift, N+1 reads, missing tenant scoping,
   missing indexes, RTDB residue, silent failures

Deliver a **coverage matrix**:

| Capability (from Phase 1) | Admin Web | Teacher App | Parent App | Firestore/rules | Verdict |
|---|---|---|---|---|---|

Verdict values: `Missing` · `Partial` · `Present-but-weak` · `Present-and-solid` · `Broken`.

## PHASE 3 — Gap analysis and classification

For every gap, classify it exactly as one of:

- **Bug** — existing behaviour is wrong in production
- **Weakness** — works, but fragile / unscalable / unsafe
- **Improvement** — works, could be materially better
- **Enhancement** — genuinely new capability

Rank by: production risk → user impact → effort. Say plainly which gaps are *not* worth
closing and why.

## PHASE 4 — Advanced target design

Design the **advanced version** of {{FEATURE}} for ZenXII. Not a copy of the references —
a better synthesis. Include:

- **Concept & user stories** per persona (school admin, principal, accountant/clerk,
  teacher, class teacher, parent, student, super-admin) — only the personas that apply
- **Capability spec**, tiered: MVP → Advanced → Differentiator
- **State machine / lifecycle** for the core entity (draft → … → archived), with every
  legal transition, who may trigger it, and what happens to dependent records
- **Automation & intelligence layer** — scheduling, triggers, notifications, escalation,
  bulk operations, templating, approvals, audit trail, analytics
- **Configurability** — what a school can tune without a code change
- **Permissions** — role/capability matrix, and how it maps onto the current RBAC
  (note: enterprise RBAC Model D is design-frozen; do not assume it exists)

## PHASE 5 — Data & platform design

- **Firestore collections**: full document schema, field types, required/optional,
  denormalizations and why, document-size ceiling check
- **Tenant scoping**: exact docId / field-scoping strategy per collection
- **Composite indexes** needed → concrete `firebase-rules/firestore.indexes.json` entries
- **Security rules** → concrete `firebase-rules/firestore.rules` deltas, with the
  read/write predicate for every role
- **Read/write budget**: expected ops per screen load and per bulk action, at
  30 / 300 / 3000 students. Flag any N+1, unbounded query, or >500-op batch.
- **Migration/backfill plan** for existing tenants, including the dry-run and rollback path

## PHASE 6 — Cross-module impact verification (mandatory)

Explicitly verify and report on every one of these; "not applicable" must be justified,
not assumed:

- Inbound dependencies (what calls into the code you'd change)
- Outbound dependencies (what the changed code calls)
- Shared collections, services, libraries, helpers
- Both Kotlin apps
- Cron / scheduled jobs
- Reports and exports
- Firestore rules and indexes
- Tenant isolation
- The 16 production subsystems — prove none regress

End with a one-line **cross-module verdict**. If any dependency is affected, STOP and
report rather than designing around it silently.

## PHASE 7 — Sample UI/UX (this is a required deliverable, not optional)

Produce a **clickable HTML prototype as a published Artifact** so the workflow can be
walked end-to-end before any code is written.

Requirements:

- Self-contained single page: inline CSS/JS, no external fonts/CDN/images
- Light **and** dark theme
- Realistic Indian K-12 dummy data (₹ amounts, `2025-26` session, `Class 8th` / `Section A`,
  Indian names) — clearly watermarked as sample data
- **Tabs or panes for all three surfaces**: Admin Web (desktop layout),
  Teacher App (phone frame), Parent App (phone frame)
- Cover the **full happy path** plus these states for every screen:
  loading · empty · partial data · error · permission-denied · offline (apps) ·
  bulk-action in progress · success confirmation
- Interactive enough to *test the functionality logic*: filters, validation,
  state transitions, and the approval/escalation flow should actually respond
- A short **UX rationale** panel: why this layout, what it borrows from
  Edunext/Zenxo/Fedena, and where it deliberately diverges
- Match ZenXII's existing design language (note: `--gold` CSS vars render as teal;
  reuse `assets/css/attendance_design_system.css` patterns where relevant)

Also provide, in the chat response:

- Text wireframes / screen inventory with navigation map
- Field-level spec per form: label, type, validation, default, required, help text,
  error copy
- Copy deck for all notifications, empty states, and error messages

## PHASE 8 — Correctness in every aspect

Prove the design actually holds up. Deliver:

- **Edge-case matrix** — at minimum: concurrent edits, partial batch failure, duplicate
  submission, mid-session class/section change, student transfer/TC, staff deactivation,
  session rollover, timezone/date-boundary, deleted-parent-account, app offline then
  reconnect, and school with zero configured data
- **Idempotency & concurrency**: which writes must be idempotent, what the dedupe key is,
  where transactions vs batches are required
- **Failure semantics**: what the user sees, what gets logged, what self-heals, what
  requires manual repair; no silent failures
- **Data integrity invariants** — list them as assertions that must always be true
- **Test plan**: unit, integration, manual QA script per surface, and a CLI verifier
  script sketch under `scripts/`
- **Observability**: what to log, what to surface in audit trail, what to alert on

## PHASE 9 — Delivery plan

- Phased implementation (Phase 1 / 2 / 3), each phase independently shippable
- File-by-file change list per surface, with LoC estimate
- Sequencing and dependencies, including anything currently held or frozen
- **The 8 release gates** — state how each will be satisfied:
  1. Functional correctness 2. Cross-module impact 3. Tenant isolation
  4. Firestore rules + indexes 5. Both apps 6. Regression audit
  7. Rollback path 8. **Performance** (r/w budget, no N+1, index use, batch limits, latency)
- Risk register with mitigations
- Explicit **"what I would NOT build"** section

## OUTPUT RULES

- Lead with a ≤10-line executive summary: current state, the gap, the recommendation.
- Every codebase claim must cite `file_path:line`. Unverified claims must be labelled
  `[assumption]`.
- Separate **fact** (read from code / confirmed source) from **inference** from
  **recommendation**. Never blur them.
- Be direct about what is bad in the current implementation. No diplomatic hedging.
- Give one recommendation, not a survey of options. If there is a real fork, present
  exactly two and pick one.
- Full research report in the chat; UI prototype as an Artifact; write any long-form
  report to a markdown file at repo root named `{{FEATURE}}_RESEARCH_AND_DESIGN.md`.
- **Do not write, edit, or commit any application code.** Stop at the end of Phase 9
  and wait for approval.

=== PROMPT END ===

---

## Short variant (for small features)

> Research **{{FEATURE}}** for ZenXII. (1) How Edunext, Zenxo and Fedena do it →
> capability inventory tiered table-stakes / competitive / differentiator.
> (2) Forensic audit of what we have today across Admin Web + Teacher App + Parent App,
> cited `file:line`, as a coverage matrix. (3) Gap list classified
> bug/weakness/improvement/enhancement. (4) Target design + Firestore schema, rules and
> indexes. (5) Cross-module impact verdict. (6) Clickable HTML Artifact prototype covering
> all three surfaces with loading/empty/error/permission states. (7) Edge-case matrix and
> test plan. (8) Phased plan against the 8 gates.
> Constraints: Firestore only (no RTDB), strict tenant isolation, Document Service frozen,
> canonical schemas respected. No code, no commits — design only.

## Per-feature run log

| # | Feature | Date | Report file | Prototype URL | Status |
|---|---------|------|-------------|---------------|--------|
| 1 | | | | | |
