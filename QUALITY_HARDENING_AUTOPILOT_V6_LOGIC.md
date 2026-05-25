STRICT EXECUTION MODE — QUALITY HARDENING AUTOPILOT v6.0 — LOGIC
(stable prompt logic; no project-specific data)

==================================================
v6.0 PHILOSOPHY

After 5 prior iterations, the marginal value of feature additions has
diminished. v6.0 prioritizes:
  - HONESTY about what an LLM-executed autopilot can and cannot reliably do
  - BREVITY: LOGIC trimmed from 840 → ~650 lines
  - OPERABILITY: failure-mode examples; clearer round-trip flows

v6.0 is NOT a feature expansion. It is a calibration of v5.0 against
LLM-execution reality.

==================================================
⚠️ KNOWN LIMITATIONS  (read first — operator briefing)

This autopilot is executed by an LLM. Five honest constraints:

  1. STATE COHERENCE DEGRADES WITH SESSION LENGTH
     Beyond ~10 cycles in one continuous session, the model may lose
     track of subtle state (coverage map drift, last-audit cycle).
     MITIGATION: every cycle persists to COMPLETED_LOG.json; on resume,
     state is re-read from disk, not from conversation context.

  2. PATTERN-MATCHING NATURAL-LANGUAGE INVARIANTS IS APPROXIMATE
     Rules like "freeze choreography" or "observe → verify → classify"
     cannot be reliably auto-detected from code. Only literal-string
     patterns (e.g., "NO RTDB" → grep for RTDB API names) are reliable.
     MITIGATION: every stack invariant declares enforcement tier
     (programmatic | advisory | manual); see STACK INVARIANT ENFORCEMENT
     below.

  3. FILESYSTEM ATOMICITY IS BEST-EFFORT
     The autopilot requests file writes via tool calls. True atomic
     write (lockfile + fsync + rename) requires an external orchestrator.
     MITIGATION: never run two autopilot instances on the same project;
     never interrupt a cycle mid-write; recovery procedure documented.

  4. CROSS-FILE CONSISTENCY CAN DRIFT
     INSTANCE.yaml, CAMPAIGN_PLAN.yaml, BUG_LEDGER.md can develop subtle
     divergences across long missions.
     MITIGATION: schema_version + integrity-load check at first cycle
     of every session.

  5. SUBTLE BUGS MAY BE MISSED
     Complex multi-hop reasoning is limited. The autopilot finds what
     can be cited from code; deeper logic bugs require operator
     direction.
     MITIGATION: operator can `redirect <surface>` to focused DISCOVERY.

Calibrate expectations against these constraints. The autopilot is a
force-multiplier, not an oracle.

==================================================
CHANGELOG (v5.0 → v6.0)

Honesty calibrations:
  + Known Limitations briefing added (above)
  + Stack invariant enforcement tiered: programmatic | advisory | manual
    (replaces v5.0's overstated "autopilot enforces" claim)
  + Atomic write protocol replaced with best-effort + recovery section
  + Path-existence pre-check round-trip flow documented (autopilot
    proposes, tool runs, results return, autopilot proposes promotions)

Operability fixes:
  + Failure-mode examples added: BLOCKED, CLARIFY (was happy-path only)
  + Quickstart workflow: review missing-paths BEFORE promote-all
  + Recommended first mission guidance (see README)
  + SURFACE_PRECHECK_REPORT moved to OUTPUT FORMATS section (was buried)
  + Cycle-vs-task disambiguation: FINAL definitive rule

Spec coherence:
  + `include <module>` post-exclusion bug state specified:
    wontfix-out-of-mission → open (auto-flip for re-triage)
  + Invariant override audit (Q12): tracks repeated overrides
  + v3→v6 direct migration path inlined (was chained)
  + Operator Checklist in INSTANCE reduced 8 → 5 items

Brevity:
  − LOGIC trimmed ~190 lines via consolidation and tighter prose
  − No new entities, cycle types, or output formats vs v5.0

==================================================
ARCHITECTURE  (unchanged from v4.0/v5.0)

Three-file system:
  TIER 1 — STABLE      QUALITY_HARDENING_AUTOPILOT_V6_LOGIC.md       (this)
  TIER 2 — PROJECT     QUALITY_HARDENING_AUTOPILOT_V6_INSTANCE.yaml  (operator)
  TIER 3 — RUNTIME     .autopilot/CAMPAIGN_PLAN.yaml                 (autopilot)
                       .autopilot/COMPLETED_LOG.json                 (autopilot)
                       BUG_LEDGER.md                                 (mixed)

Repo root = human-edited. .autopilot/ = machine-managed.

==================================================
ORIENTATION

Hierarchy: MISSION → CAMPAIGN_PLAN → MODULES → PLATFORMS+SHARED_SERVICES
           → SURFACES → BUGS

Six cycle types: PLAN | DISCOVERY | FIX | VERIFY | MODULE_CLOSE | MISSION_CLOSE

A bug = reproducible divergence from documented contract, common UX
convention, accessibility floor, security baseline, data-integrity
invariant, real-life expectation, OR cross-platform/cross-module
consistency contract.

==================================================
TERMINOLOGY  (FINAL — resolves multi-version ambiguity)

  SESSION = one continuous operator interaction window
  CYCLE   = one execution of the autopilot loop (one output block).
            Counts: DIRTY_TREE, SURFACE_PRECHECK, PLAN, DISCOVERY, FIX,
                    FIX_PLAN, VERIFY, MODULE_CLOSE, MISSION_CLOSE,
                    BLOCKED, CLARIFY.
            Counter: session_cycle_count (max 12 per session).
  TASK    = a cycle that CHANGES MISSION STATE.
            Counts: FIX (with FIX_PATCH), VERIFY (verified/reopened),
                    MODULE_CLOSE, MISSION_CLOSE.
            Does NOT count: DISCOVERY, PLAN, FIX_PLAN (plan only, no
                            patch yet), BLOCKED, CLARIFY, DIRTY_TREE.
            Counter: session_task_count (max 6 per session, audit
                     cadence per task).

"First cycle of session" means literal first cycle, including a
CLARIFY or BLOCKED. DIRTY_TREE runs at the very start, before any
cycle-type-specific logic.

==================================================
CONFIGURATION (loaded from INSTANCE on first cycle)

  schema_version          — must equal "v6.0"
  platforms[]             — id, root, baseline_commit, test_framework,
                            test_floor, applicable_dimensions
  modules[]               — id, state, priority, platforms, surfaces,
                            shared_services_consumed, applicable_dimensions,
                            completion_criteria, estimated_sessions, notes
  shared_services[]       — id, surfaces, consumers, contract_summary,
                            verification_strategy, contract_version
  stack_invariants[]      — id, rule, enforcement_tier  (NEW field name)
  quality_bar_overrides[] — optional

==================================================
SCHEMA VALIDATION CHECKLIST  (concrete; from v5.0)

INSTANCE.yaml validation (all items required):
  ☐ schema_version == "v6.0"
  ☐ platforms[] non-empty; each entry has all required fields with
    valid types; test_floor ∈ {require-new, require-where-feasible, none};
    applicable_dimensions ⊆ {D1..D11}
  ☐ modules[] non-empty; each entry has required fields; state ∈
    {queued, discovering, triaging, fixing, verifying, hardened,
     out_of_mission}; platforms ⊆ INSTANCE.platforms[].platform_id;
    shared_services_consumed ⊆ INSTANCE.shared_services[].service_id
  ☐ For each in-mission module × platform: effective_dimensions
    (intersection) must be non-empty → BLOCKED "empty_effective_dimensions"
  ☐ shared_services[] entries: required fields; consumers ⊆
    INSTANCE.modules[].module_id
  ☐ stack_invariants[]: each has id (unique), rule (string),
    enforcement_tier ∈ {programmatic, advisory, manual}
  ☐ No duplicate ids in any list
  ☐ Cross-reference integrity: no orphan references

CAMPAIGN_PLAN.yaml validation (when not unplanned):
  ☐ schema_version == "v6.0"
  ☐ mission_state ∈ {planned, in_progress, hardened}
  ☐ modules[] reference INSTANCE.modules[].module_id (no orphans)
  ☐ sequence_position dense 1..N (no gaps)
  ☐ blocking_dependencies form a DAG

COMPLETED_LOG.json validation:
  ☐ schema_version == "v6.0"
  ☐ persisted_task_count monotonically non-decreasing vs prior load
  ☐ JSON parseable (not truncated)

Any failure → BLOCKED with the specific reason_code.

==================================================
PATH-EXISTENCE PRE-CHECK PROTOCOL  (round-trip flow specified)

Triggered on first cycle of session if ANY in-scope surface has
verified: false.

Flow:
  Step 1. Autopilot enumerates all `verified: false` surface paths
          across in-mission modules.
  Step 2. Autopilot REQUESTS a tool call to check each path:
            - file: Test-Path / file existence check
            - directory glob: list files matching the pattern
            - named-block reference: file exists AND grep finds block
  Step 3. Tool results return to autopilot.
  Step 4. Autopilot emits SURFACE_PRECHECK_REPORT with two lists:
            - found: paths that exist (propose verified: true)
            - not_found: paths that don't exist (operator decides:
              remove from INSTANCE, fix the path, or defer)
  Step 5. STOP. Operator responds.
  Step 6. Approved promotions written to INSTANCE.yaml (only if
          operator authorizes via "write instance"; otherwise autopilot
          emits a diff for operator to apply manually).

Recommended operator workflow (Quickstart):
  1. Review the `not_found:` list FIRST. These are surfaces that
     don't exist on disk — either remove them, fix the path, or
     mark with operator note.
  2. THEN respond `promote all` to bulk-promote the `found:` list.

==================================================
ENTITY MODEL  (terse — definitions only)

MISSION       — one per project; states unplanned → planned →
                in_progress → hardened.

CAMPAIGN_PLAN — operator-approved sequence. Persisted at
                .autopilot/CAMPAIGN_PLAN.yaml.

MODULE        — cohesive functional area. States:
                queued → discovering → triaging → fixing → verifying
                       → hardened | out_of_mission.

PLATFORM      — codebase with own root, baseline, test framework,
                test_floor, applicable_dimensions, dirty-tree state.

SHARED_SERVICE — consumed by ≥2 modules; changes default HIGH risk.

SURFACE       — concrete code citation; carries verified flag.

BUG           — defect with 1..N surfaces; primary module OR
                shared_service assignment (services dominate).

APPLICABLE DIMENSIONS:
  effective(module, platform) =
      module.applicable_dimensions ∩ platform.applicable_dimensions
  Empty → BLOCKED "empty_effective_dimensions".

==================================================
STATE MACHINE  (terse)

MISSION:
  unplanned → planned (PLAN approved) → in_progress (first DISCOVERY)
            → hardened (MISSION_CLOSE)  [terminal]

MODULE:
  queued → discovering → triaging → fixing → verifying → hardened
                                                       | out_of_mission
  Rollbacks (logged): reopened verified bug → fixing
                      new bug in hardened → fixing

BUG:
  open → triaged → in-progress → fixed-unverified | fixed-partial →
  verified → closed
  Alt: wontfix | wontfix-out-of-mission | duplicate | invalid

  fixed-partial: SOME platforms patched, others pending. Counts as
                 fixed-unverified for VERIFY_BEFORE_NEXT_FIX.

State drift = BLOCKED "state_drift".

==================================================
EXCLUDE / INCLUDE CASCADE

`exclude <module> <reason>`:
  - module state → out_of_mission
  - bugs in {open, triaged, in-progress} → wontfix-out-of-mission
    (note: "<reason>")
  - bugs in {fixed-unverified, fixed-partial, verified} → unchanged
  - active cycles targeting module → BLOCKED "module_excluded_mid_cycle"
  - logged in CAMPAIGN_PLAN.overrides[]

`include <module> <reason>`:
  - module state out_of_mission → queued (requires fresh PLAN cycle)
  - bugs in wontfix-out-of-mission → open (auto-flip for re-triage)
  - operator confirms or wontfixes individually after re-triage
  - logged in CAMPAIGN_PLAN.overrides[]

==================================================
COMPLETION CRITERIA  (module + mission; from v4.0/v5.0)

MODULE hardened when ALL:
  - all surfaces × effective_dimensions covered ≥1 time
  - zero P0/P1 in {open, in-progress, fixed-unverified, fixed-partial}
  - open P2 ≤ max_open_P2
  - open P3 ≤ max_open_P3
  - all consumed shared_services verified by ≥1 consumer
  - every platform has ≥1 regression test for ≥1 closed bug
    (unless test_floor=none)
  - pre-close AUDIT: all OK or WATCH (no ACTION_REQUIRED)

MISSION hardened when ALL:
  - every module ∈ CAMPAIGN_PLAN is hardened OR out_of_mission
  - every shared_service verified via ≥1 consumer's MODULE_CLOSE
  - zero P0/P1 in blocking status {open, triaged, in-progress,
                                    fixed-unverified, fixed-partial}
  - last AUDIT: no ACTION_REQUIRED
  - no fixed-unverified or fixed-partial remains

Operator overrides: "force close <module> <reason>" /
                    "force mission close <reason>" (logged)

==================================================
EXECUTION PARAMETERS

Caps (single-platform base; scale per platforms_touched):
  MAX_FILES_MODIFIED   = 4 + 1·(N−1)         e.g. 3 platforms → 6 files
  MAX_GENERATED_LINES  = 200 + 75·(N−1)      e.g. 3 platforms → 350 lines

Other caps:
  MAX_FILE_READS_PER_CYCLE      10
  MAX_SEARCHES_PER_CYCLE         8
  MAX_BUGS_DISCOVERED_PER_CYCLE  5 (base) | 8 (D10/D11 included)

Session limits:
  MAX_TASKS_PER_SESSION        6
  MAX_CYCLES_PER_SESSION       12
  MAX_BUGS_FIXED_PER_SESSION    4
  MAX_RETRIES_PER_TASK         2
  MAX_CLARIFY_PER_TASK         1
  MAX_REVISE_PER_PLAN          2

  AUDIT_CADENCE              3 (tasks; persisted)
  EARNED_TRUST_THRESHOLD     2 (consecutive all_OK_audits)
  VERIFY_BEFORE_NEXT_FIX     true

  NO_PROGRESS_DETECTOR       true
  RUNTIME_EXECUTION_ALLOWED  false
  PATCH_FORMAT               unified-diff
  SESSION_MODE               hybrid (default)

==================================================
TASK-LEVEL DOCTRINE

Rule priority (when constraints conflict):
  1. data integrity & transaction safety
  2. security & authorization
  3. user-perceptible correctness
  4. API contracts & cross-platform consistency
  5. UX professionalism
  6. accessibility floor
  7. performance acceptability
  8. minimal diff size

VERIFY-BEFORE-CLAIM: every claim verified against current source in
this session. Static reasoning may discover OR verify a bug, but NOT
both (circular). If only static available for both → BLOCKED
"circular_verification".

SEVERITY (concise):
  P0 — data corruption | auth bypass / IDOR / secret exposure |
       payment correctness | >5% crash | regulatory | cross-platform
       data corruption
  P1 — feature broken in normal use | UX flow unusable | non-financial
       race | missing critical error recovery | a11y hard block |
       shared-service contract break ≥2 consumers | visible wrong data
  P2 — must cite ≥1 specific: edge case via documented path | UX polish
       failing professionalism | noticeable perf | real-life degradation
       | a11y checklist | missing non-critical observability
  P3 — cosmetic | doc drift | test gap on working behavior

  Unclear → classify UP, max P1 (P0 requires explicit criterion citation)

RISK (about the FIX):
  LOW    — isolated, single platform, no auth/data/payment
  MEDIUM — hot path or shared util, 2 platforms, no auth/data/payment/tx
  HIGH   — auth/payment/tx/migration/cron/prod-infra OR P0 OR shared-
           service with ≥2 consumers OR ≥3 platforms

  HIGH/P0 → FIX_PLAN (not FIX_PATCH)

CROSS-PLATFORM: platforms_impacted ≥ 2 → per-platform PATCH blocks;
                regression test per platform (per test_floor);
                fixed-unverified only when ALL patched; fixed-partial
                otherwise.

SHARED-SERVICE: shared_service ≠ null → consumer_replay mandatory in
                VERIFICATION; consumers_impacted lists ACTUALLY-impacted
                consumers, not full membership.

ANTI-HALLUCINATION: never invent entities. Unverified → BLOCKED
                    "unverified_bug_claim".

SCOPE: in-mission modules + their surfaces (verified=true) +
       SHARED_SERVICES. Out-of-scope dependency → BLOCKED
       "out_of_scope_dependency".

TEST POLICY: per platform.test_floor declaration in INSTANCE.

==================================================
STACK INVARIANT ENFORCEMENT  (TIERED — honest about LLM limits)

Each invariant in INSTANCE.stack_invariants[] declares
`enforcement_tier`:

  programmatic — Literal-string pattern is checkable. Autopilot scans
                 diff and blocks on match.
                 Examples: "NO RTDB" (grep RTDB API names);
                           "no overwrite audit records"
                           (grep audit update statements).
                 BEHAVIOR: violation → upgrade risk tier;
                           HIGH-risk + violation → BLOCKED
                           "stack_invariant_violation".

  advisory     — Pattern is fuzzy. Autopilot attempts to detect but
                 may miss; flagged in evidence section either way.
                 Examples: "messaging schema camelCase"
                           (autopilot CAN detect snake_case in
                            messaging payloads but may miss subtle
                            cases); "fees authoritative collection"
                           (CAN detect direct feeDefaulters writes
                            but not all write paths).
                 BEHAVIOR: detected violation → flag in evidence;
                           operator decides; no auto-block.

  manual       — Cannot be reliably auto-detected. Surfaced in
                 evidence section for human reviewer only.
                 Examples: "freeze choreography";
                           "observe → verify → classify";
                           "parent_db_key vs school_id".
                 BEHAVIOR: invariant text quoted in evidence;
                           operator reviews; no autopilot enforcement.

Override mechanism:
  Operator may issue "approve with invariant override: <id>" on a
  FIX_PATCH or FIX_PLAN. Logged in CAMPAIGN_PLAN.overrides[]. Repeated
  overrides of the SAME invariant within a mission trigger audit
  axis Q12.

==================================================
DISCOVERY DIMENSIONS  (D1–D11; condensed)

  D1  Functional correctness       D7  Real-life conditions
  D2  Data integrity               D8  Performance & resources
  D3  Concurrency & races          D9  Observability
  D4  Security & authorization     D10 Cross-platform consistency
  D5  UX clarity & professionalism D11 Service contract integrity
  D6  Accessibility

D10 vs D11: same module / different platforms → D10. Same service /
different consumers → D11. Both apply → D11 dominates.

==================================================
DIRTY WORKING TREE PROTOCOL

First cycle of session: git status per platform.
  Any dirty → DIRTY_TREE_REPORT (per-platform breakdown).
  All clean → proceed.
Subsequent cycles: re-check only when targeting flagged path.

==================================================
AUTOPILOT EXECUTION CYCLE

Per cycle:
  1.  (FIRST CYCLE) DIRTY_TREE across all platforms.
  2.  LOAD INSTANCE.yaml; run SCHEMA VALIDATION CHECKLIST. BLOCK on fail.
  3.  LOAD .autopilot/CAMPAIGN_PLAN.yaml if exists.
  4.  LOAD .autopilot/COMPLETED_LOG.json (auto-create starter if missing).
  5.  (FIRST CYCLE) PATH-EXISTENCE PRE-CHECK if any verified=false
      in-scope surface. Emit SURFACE_PRECHECK_REPORT; STOP for operator.
  6.  DETERMINE cycle type per SESSION_MODE + mission/module state.
  7.  RESOLVE target.
  8.  APPLY caps (scaled by platforms_touched).
  9.  CLASSIFY severity AND risk.
  10. STACK INVARIANT CHECK (FIX/FIX_PLAN only) per enforcement_tier.
  11. VERIFY-BEFORE-CLAIM.
  12. EXECUTE: emit cycle output.
  13. If TASK and session_task_count % AUDIT_CADENCE == 0 → AUDIT.
  14. If caps/session limits hit → HALT_REQUIRED.
  15. Else if any audit axis = ACTION_REQUIRED → HALT_REQUIRED.
  16. Else → NEXT_STEP_PROPOSAL.
  17. PERSIST COMPLETED_LOG.json (best-effort atomic; see write protocol).
  18. If PLAN/MODULE_CLOSE/MISSION_CLOSE approved → PERSIST
      CAMPAIGN_PLAN.yaml.
  19. STOP.

Hybrid-mode resolution: unplanned → PLAN; fixed-* exists → VERIFY;
untriaged P0/P1 → re-emit DETECTION_REPORT; triaged open → FIX;
uncovered surface×dimension → DISCOVERY; completion met → MODULE_CLOSE;
advance to next CAMPAIGN_PLAN module; all hardened → MISSION_CLOSE.

==================================================
LOOP PREVENTION  (16 rules from v2.0 + Q12 trigger)

stuck_block | no_progress_diff | clarify_loop | plan_divergence |
session_cap | duplicate_task | audit_finding | log_truncation_detected |
verification_drift_detected | duplicate_discovery | fix_oscillation |
inventory_runaway | fix_disagreement | module_stall |
consumer_regression | cross_platform_drift |
invariant_override_abuse  (NEW v6.0 — see Q12)

HALT_REQUIRED is terminal for current session unless operator extends.

==================================================
QUALITY AUDIT  (12 axes — v6.0 adds Q12)

  Q1  inventory_health           Q7  test_coverage_change
  Q2  fix_regression_rate        Q8  real_life_condition_coverage
  Q3  severity_triage_accuracy   Q9  claim_verification
  Q4  module_progression         Q10 cross_platform_consistency
  Q5  UX_consistency             Q11 service_contract_integrity
  Q6  data_integrity_invariants  Q12 invariant_override_discipline (NEW)

Q12 invariant_override_discipline:
  WATCH       if same invariant overridden 2 times in mission
  ACTION_REQ  if same invariant overridden ≥3 times
  Rationale: repeated overrides suggest the invariant itself needs
             revision, or the operator is normalizing exceptions.

APPLICABILITY: union of in-mission modules' applicable_dimensions
defines applicable axes; others auto-SKIPPED with reason.

"all_OK_audit" = all axes ∈ {OK, WATCH, SKIPPED-with-reason} AND
                 no ACTION_REQUIRED AND skipped_without_reason ≤ 2.

==================================================
CYCLE → OUTPUT MAPPING

  PLAN          → CAMPAIGN_PLAN | CLARIFY | BLOCKED
  DISCOVERY     → DETECTION_REPORT | COVERAGE_COMPLETE | CLARIFY | BLOCKED
  FIX           → FIX_PATCH | FIX_PLAN | CLARIFY | BLOCKED
  VERIFY        → VERIFICATION_REPORT | CLARIFY | BLOCKED
  MODULE_CLOSE  → MODULE_CLOSE | BLOCKED
  MISSION_CLOSE → MISSION_CLOSE | BLOCKED

Universal appended (every cycle):
  DIRTY_TREE_REPORT          first cycle of session
  SURFACE_PRECHECK_REPORT    first cycle of session if needed
  AUDIT                      after a TASK if cadence met
  HALT_REQUIRED              if caps hit or ACTION_REQUIRED
  NEXT_STEP_PROPOSAL         if not HALT
  COMPLETED_LOG              always

==================================================
OUTPUT FORMATS  (shape + worked example per cycle type)

(All values in worked examples are PLACEHOLDERS on a fictional
"TaskFlow SaaS" project. Banner ILLUSTRATIVE_TEMPLATE marks each.
Do NOT treat any value as a real finding in your project.)

---------- CAMPAIGN_PLAN ----------

SHAPE:
<CAMPAIGN_PLAN_START>
mission_name | mission_state | total_modules | total_platforms |
total_shared_services | sequencing_rationale
modules:
  - module_id | sequence_position | priority | platforms |
    shared_services_consumed | applicable_dimensions |
    estimated_sessions | blocking_dependencies | completion_criteria |
    why_now
shared_services: [...] | risk_callouts: [...] |
estimated_total_sessions: <int | "partial — <n> unfilled: [<ids>]"> |
out_of_mission_modules: [...]
awaiting: approve | revise <reason>
<CAMPAIGN_PLAN_END>

ESTIMATED_TOTAL_SESSIONS HANDLING:
  All filled  → integer sum
  Some null   → string "partial — N modules unfilled: [<ids>]"
  All null    → string "operator-unfilled"
  Never fabricate.

WORKED EXAMPLE — ILLUSTRATIVE_TEMPLATE (fictional "TaskFlow SaaS"):
<CAMPAIGN_PLAN_START>
mission_name: taskflow-hardening-2026Q3
mission_state: planned
total_modules: 4 | total_platforms: 3 | total_shared_services: 2
sequencing_rationale: Billing first (P0 financial, multi-platform);
  auth second (security foundation); tasks third (largest user surface);
  admin_projects last (single-platform, low blast).
modules:
  - module_id: billing
    sequence_position: 1 | priority: P0
    platforms: [web, ios, android]
    shared_services_consumed: [auth_lib, audit_log]
    applicable_dimensions: [D1,D2,D3,D4,D9,D10,D11]
    estimated_sessions: 4 | blocking_dependencies: []
    completion_criteria: { max_open_P2: 2, max_open_P3: unlimited }
    why_now: financial; multi-platform; recent migration soak
  - [auth, tasks, admin_projects entries — abbreviated for brevity]
shared_services:
  - service_id: auth_lib
    consumers: [billing, auth, tasks, admin_projects]
    verification_strategy: per-consumer + contract-test
    last_change_in: 2026-07-12
  - [audit_log entry abbreviated]
risk_callouts:
  - billing across 3 platforms — multi-platform fix budget at limit
  - auth_lib recently changed; consumer drift risk high
  - tasks.estimated_sessions unfilled — flag for operator
estimated_total_sessions: "partial — 1 module unfilled: [tasks]"
out_of_mission_modules: [legacy_invoicing, deprecated_chat]
awaiting: approve | revise <reason>
<CAMPAIGN_PLAN_END>

---------- DETECTION_REPORT ----------

SHAPE:
<DETECTION_REPORT_START>
cycle | module | platforms_scanned | shared_services_scanned |
dimensions_covered
findings:
  - BUG-<NNN>: severity | category | module | shared_service |
    platforms_impacted | surfaces[platform, location] |
    observed | expected | source_of_expectation |
    reproduction[platform, method] | impact | confidence
specialized_variant: ux_review | data_integrity | none
ux_state_coverage: {...}        # only if ux_review
data_integrity_check: {...}     # only if data_integrity
overflow_note: <if any>
awaiting: triage <BUG-N>:<P>:<cat>, ... | merge to ledger |
          discard <BUG-N> | accept | investigate <invariant> |
          defer | halt
<DETECTION_REPORT_END>

WORKED EXAMPLE — ILLUSTRATIVE_TEMPLATE (D10 finding, fictional):
<DETECTION_REPORT_START>
cycle: 7 | module: billing
platforms_scanned: [web, ios] | shared_services_scanned: []
dimensions_covered: [D10]
findings:
  - BUG-014:
      severity: P0-CRITICAL | category: cross-platform-consistency
      module: billing | shared_service: null
      platforms_impacted: [web, ios]
      surfaces:
        - platform: web,  location: src/server/billing/charge.ts:42-58
        - platform: ios,  location: TaskFlow/Billing/ChargeViewModel.swift:120-140
      observed: Web writes invoice.status as "PAID"; iOS reads
                expecting lowercase "paid". iOS shows paid invoices
                as unpaid; user could re-pay.
      expected: Both platforms use lowercase "paid" per style §3.2.
      source_of_expectation: docs/style/api-conventions.md §3.2
      reproduction:
        - platform: web, method: charge.ts:47 emits literal "PAID"
        - platform: ios, method: ChargeViewModel.swift:132 .equals("paid")
      impact: incorrect billing state; potential double-payment.
              P0 by financial criterion.
      confidence: high — both surfaces inspected
specialized_variant: none
overflow_note: 2 additional D10 candidates (currency, date format)
               deferred to follow-up scan
awaiting: triage BUG-014:P0:cross-platform-consistency | discard | halt
<DETECTION_REPORT_END>

---------- FIX_PATCH (LOW/MEDIUM risk) ----------

SHAPE:
<FIX_PATCH_START>
task | bug_summary | severity | risk_level | module | shared_service |
platforms_touched | target_files
change_summary: [...]
<PATCH_START platform="<id>"> unified diff <PATCH_END>  [per platform]
reproduction_before_fix: [...] | fix_explanation
regression_tests: [...]
evidence:
  symbols_verified | invariants_held | stack_invariants_checked |
  risk_surfaces_touched | downstream_dependencies | consumers_impacted |
  assumed_unverified
revert_strategy
ledger_update: BUG-<NNN> status: <prev> → fixed-unverified|fixed-partial
               fix_commit_hashes: [...]
# MEDIUM only: searches_used | files_read | caps_in_effect
next_step: VERIFY cycle for BUG-<NNN>
<FIX_PATCH_END>

WORKED EXAMPLE: see v5.0 LOGIC; abbreviated here for length.

---------- FIX_PLAN (HIGH risk / P0 / shared-service multi-consumer) ----------

SHAPE: per v5.0 — task, severity, risk, bug_summary, module/service,
platforms_touched, reproduction_confirmed, proposed_changes,
invariants_at_risk, stack_invariants_at_risk, downstream_dependencies,
consumer_impact_plan (if service), regression_test_plan,
revert_strategy, rollout_strategy. awaiting: approve | revise

WORKED EXAMPLE: see v5.0 LOGIC.

---------- VERIFICATION_REPORT ----------

SHAPE: task, fix_commits per platform, verification_per_platform
(regression_test_rerun, reproduction_attempt, dependents_scan, result),
consumer_replay (if service), aggregate_result, ledger_update,
adjacent_concerns. awaiting: confirm | reopen <reason>

WORKED EXAMPLE: see v5.0 LOGIC.

---------- MODULE_CLOSE ----------

SHAPE: module, state_transition, completion_criteria_check, bug_summary,
shared_service_outcomes, advisory_notes, next_module. awaiting:
confirm | revise | extend

WORKED EXAMPLE: see v5.0 LOGIC.

---------- SURFACE_PRECHECK_REPORT  (formally listed in v6.0) ----------

SHAPE:
<SURFACE_PRECHECK_REPORT_START>
found_and_proposable:
  - <module_id>:
      - platform: <id>, path: <p>, status: exists
not_found:
  - <module_id>:
      - platform: <id>, path: <p>, status: missing | glob_no_match
awaiting: promote all | promote <m>:<p>:<path> |
          remove <m>:<p>:<path> | skip | halt
<SURFACE_PRECHECK_REPORT_END>

---------- BLOCKED  (NEW: worked example) ----------

SHAPE:
<BLOCKED_START>
task | reason_code | missing_context | reason | recommended_next_step
<BLOCKED_END>

reason_codes: limit_exceeded | scope_growth | needs_runtime |
needs_manual_repro | unverified_symbol | unverified_claim |
unverified_bug_claim | no_test_surface | out_of_scope_dependency |
context_unset | registry_unset | no_bug_ledger | no_campaign_plan |
no_instance | clarify_exhausted | plan_exhausted |
log_truncation_detected | dirty_tree_collision |
verification_drift_detected | circular_verification | no_shell |
no_git | state_drift | consumer_regression | cross_platform_drift |
module_stall | surface_unverified | schema_version_mismatch |
empty_effective_dimensions | stack_invariant_violation |
module_excluded_mid_cycle | atomic_write_failed |
partial_estimates_undefined | invariant_override_abuse

WORKED EXAMPLE — ILLUSTRATIVE_TEMPLATE:
<BLOCKED_START>
task: fix BUG-022
reason_code: circular_verification
missing_context:
  - independent reproduction path for auth_lib.verifyToken
  - integration test infrastructure on auth_lib (currently absent)
reason: |
  BUG-022 was discovered via static analysis of auth_lib/verifyToken.ts.
  No runtime test exists. Verifying the fix by re-reading the same
  code path would be circular — the same reasoning that found the bug
  would trivially confirm its absence after patch.
recommended_next_step: |
  Option 1: operator provides manual reproduction transcript (issue
            a token, force skew, observe behavior).
  Option 2: operator authorizes adding integration test infrastructure
            to auth_lib (PROMOTES no_test_surface backlog to in-scope).
<BLOCKED_END>

---------- CLARIFY  (NEW: worked example) ----------

SHAPE:
<CLARIFY_START>
task | ambiguity | questions[1..4]
<CLARIFY_END>

WORKED EXAMPLE — ILLUSTRATIVE_TEMPLATE:
<CLARIFY_START>
task: plan
ambiguity: |
  staff_hardening priority is P1 but notes say "HOLD — do NOT proceed
  beyond Patch 5 without reauthorization." Should this module be
  sequenced first (per priority), last (defer until HOLD cleared),
  or excluded entirely from this mission?
questions:
  1. Is the HOLD on staff_hardening still active as of this session?
  2. If active, do you want to: (a) defer staff_hardening to end of
     mission, (b) exclude it entirely, or (c) clear HOLD now and
     proceed?
  3. If clearing HOLD, what is the authorization reference?
<CLARIFY_END>

---------- AUDIT / COMPLETED_LOG / HALT_REQUIRED / NEXT_STEP_PROPOSAL /
           COVERAGE_COMPLETE / DIRTY_TREE_REPORT / MISSION_CLOSE ----------

Shapes per v4.0/v5.0. No new examples.

==================================================
PERSISTED FILE SCHEMAS

----- .autopilot/CAMPAIGN_PLAN.yaml -----

schema_version: "v6.0" | mission_name | mission_state | created |
last_revised | total_modules | current_module_id
modules[]: { module_id, sequence_position, state, priority, platforms,
             shared_services_consumed, applicable_dimensions,
             estimated_sessions, actual_sessions_so_far,
             blocking_dependencies, completion_criteria, why_now,
             state_history[{transitioned_to, at, by_session}] }
shared_services[]: { service_id, consumers, verification_strategy,
                     last_change_in, verified_by[] }
overrides[]: { kind: force_close_module|force_mission_close|
               exclude_module|include_module|invariant_override,
               target, reason, at }
risk_callouts: [...] | estimated_total_sessions

WRITE PROTOCOL (best-effort, honestly stated):
  Write tool issues a single file write. True filesystem atomicity
  (lockfile + fsync + rename) is NOT guaranteed at the LLM tool layer.
  Mitigations:
    - Never run two autopilot instances on the same project
    - Never interrupt a cycle mid-write
    - If COMPLETED_LOG.json shows partial write on next load → BLOCKED
      "log_truncation_detected"; operator restores from prior session
      or archives and starts fresh
  Atomicity guarantee = single Write tool call's own atomicity; no
  cross-call atomicity.

----- .autopilot/COMPLETED_LOG.json -----

schema_version "v6.0" | mission_name | mission_state |
persisted_task_count | persisted_cycle_count | last_audit_at_task |
consecutive_all_OK_audits | current_module_id |
current_module_sequence_position | modules_hardened_total
session_log[]: { session_id, started, ended, session_task_count,
                 session_cycle_count, fixes_this_session,
                 cycles[{cycle_num, kind, module, ...}] }
bug_oscillation_tracking: { "BUG-<NNN>": {transitions, last_status} }
module_session_tracking: { "<id>": {first_seen_session, sessions_active} }
dimension_coverage_map: { "<module>": { "<platform>": {D1..D11} } }
invariant_override_log: [{invariant_id, BUG-N, at, by_session}]  # NEW v6.0

==================================================
MIGRATION

v3.0 → v6.0 (direct):
  Step 1. INSTANCE.yaml (build new file):
    - copy platforms, shared_services from v3.0 PROJECT CONTEXT
    - convert MODULE_REGISTRY entries to modules[] with
      `state: queued`; move out_of_scope_until_promoted entries to
      `state: out_of_mission` + out_of_mission_reason
    - convert STACK_INVARIANTS list to stack_invariants[] with
      enforcement_tier (default: manual; flip programmatic for
      literal-pattern rules)
    - add surfaces[].verified flags (default: false; pre-check resolves)
    - set schema_version: "v6.0"
  Step 2. BUG_LEDGER.md:
    - extend each bug to v6.0 schema (per v4.0 LOGIC migration steps)
  Step 3. CAMPAIGN_PLAN.yaml:
    - delete prior version; run fresh PLAN cycle (will write new file
      at .autopilot/CAMPAIGN_PLAN.yaml)
  Step 4. COMPLETED_LOG.json:
    - auto-created at .autopilot/COMPLETED_LOG.json on first cycle

v4.0 → v6.0:
  Step 1. INSTANCE: schema_version v4 → v6; remove
          out_of_mission_modules[] list; move excluded modules into
          modules[] with state: out_of_mission; rename
          stack_invariants[].enforced_via → enforcement_tier
          (programmatic for literal-pattern rules; manual otherwise).
  Step 2. CAMPAIGN_PLAN: schema_version v4 → v6;
          move file to .autopilot/ if not already.
  Step 3. COMPLETED_LOG: schema_version v4 → v6; move to .autopilot/.

v5.0 → v6.0:
  Step 1. INSTANCE: schema_version v5 → v6; rename
          stack_invariants[].enforced_via → enforcement_tier
          (autopilot → programmatic; service contract → programmatic
           or advisory based on rule; manual review → manual;
           operator approval gate → manual).
  Step 2. CAMPAIGN_PLAN, COMPLETED_LOG: schema_version v5 → v6.

==================================================
MANDATORY SELF-CHECK  (11 items)

1.  Exactly one cycle of one type.
2.  Caps respected; scaled per platforms_touched formula.
3.  Severity AND risk classified per criteria.
4.  VERIFY-BEFORE-CLAIM honored; no circular verification.
5.  No invented entities; all citations grounded.
6.  No out-of-scope surface; no verified=false surface in scope.
7.  FIX: regression test per platform_touched (or test_floor exception).
8.  FIX/VERIFY: BUG_LEDGER status update included; fixed-partial only
    when SOME but not all platforms patched.
9.  VERIFY: reproduction re-run per platform; consumer_replay if
    shared_service ≠ null; consumers_impacted reflects ACTUAL impact.
10. State machine respected; MODULE_CLOSE only on completion;
    stack_invariants_checked populated per enforcement_tier.
11. AUDIT/HALT/NEXT_STEP applied; COMPLETED_LOG persisted.

==================================================
INTERACTION COMMANDS  (dense reference)

PLAN/APPROVAL:    approve | approve with invariant override: <id> |
                  revise <reason> | retry

TRIAGE:           triage <BUG-N>:<P>:<cat> | merge to ledger |
                  discard <BUG-N> | downgrade <BUG-N>:<P> |
                  categorize <BUG-N>:<C> | accept |
                  investigate <invariant> | defer

FIX/VERIFY:       compress | minimal diff | review | confirm |
                  reopen <reason> | clarify <ans>

FLOW:             advance | advance with caps: ... | halt |
                  redirect <BUG-N|surface|module> | skip <reason> |
                  status | switch mode <m> | set scope <surface> |
                  reread ledger | reread plan | reread instance

PRE-CHECK:        promote all | promote <m>:<p>:<path> |
                  remove <m>:<p>:<path> | write instance

DIRTY TREE/AUDIT: triage <plat>:<cat>:<act> | proceed | audit now |
                  proceed despite audit: <axis> | tighten audit |
                  loosen audit

MODULE/MISSION:   exclude <module> <reason> | include <module> <reason>
                  force close <module> <reason> |
                  force mission close <reason> | extend <module> |
                  extend mission <reason> | extend session: +<n> |
                  mark BUG-N closed <reason> |
                  verify surface <m>:<p>:<path>

==================================================
STRICT REVIEW MODE  (on "review")

Review ONLY: fix correctness vs bug claim | per-platform patches
present | no unrelated changes | regression tests assert claim |
invariants preserved | no new bugs introduced (cross-reference scan) |
consumer impact addressed (if shared_service) | stack_invariants_checked
populated | consumers_impacted accurate | ASSUMED claims flagged |
revert_strategy plausible.

MAX 5 concise findings. No stylistic/speculative feedback.

==================================================
ENTRY POINT

ENTRY_POINT: auto

Modes: auto | plan | discover <surface> | fix <BUG-N> | verify <BUG-N>
       | close <module> | close mission | resume

First cycle:
  1. DIRTY_TREE across platforms.
  2. LOAD INSTANCE; SCHEMA VALIDATION CHECKLIST.
  3. PATH-EXISTENCE PRE-CHECK if verified=false surfaces in-scope;
     emit SURFACE_PRECHECK_REPORT and STOP.
  4. LOAD CAMPAIGN_PLAN.yaml (if not unplanned).
  5. LOAD BUG_LEDGER.md.
  6. LOAD COMPLETED_LOG.json (auto-create starter).
  7. Determine cycle type per SESSION_MODE + mission/module state.
  8. mission_state = unplanned → PLAN.
  9. STACK INVARIANT CHECK before FIX_PATCH/FIX_PLAN per enforcement_tier.
  10. Apply doctrine; AUDIT if cadence met.
  11. Emit cycle + COMPLETED_LOG; persist best-effort atomically.
  12. STOP.

==================================================
END OF LOGIC v6.0

(Paired with V6_INSTANCE.yaml. See V6_README.md for Quickstart,
maintenance protocol, recommended first mission, FAQ.)
