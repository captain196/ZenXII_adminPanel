STRICT EXECUTION MODE — QUALITY HARDENING AUTOPILOT v5.0 — LOGIC
(stable prompt logic; no project-specific data)

==================================================
v5.0 ARCHITECTURE — THREE-FILE SPLIT (unchanged from v4.0)

  TIER 1 — STABLE (this file)            Owner: prompt engineer
    QUALITY_HARDENING_AUTOPILOT_V5_LOGIC.md

  TIER 2 — PROJECT (operator-maintained) Owner: operator
    QUALITY_HARDENING_AUTOPILOT_V5_INSTANCE.yaml

  TIER 3 — RUNTIME (autopilot-managed)
    .autopilot/CAMPAIGN_PLAN.yaml        autopilot writes; operator approves
    .autopilot/COMPLETED_LOG.json        autopilot only
    BUG_LEDGER.md                        autopilot writes; operator triages

File-path convention (consistent with v4.0 audit finding):
  Root             — human-authored or human-edited files
                     (LOGIC.md, INSTANCE.yaml, README.md, BUG_LEDGER.md)
  .autopilot/      — machine-writes-this files
                     (CAMPAIGN_PLAN.yaml, COMPLETED_LOG.json)

==================================================
CHANGELOG (v4.0 → v5.0)

Fixes from v4.0 auditor findings:
  + Worked examples added per cycle type (PLAN, DETECTION_REPORT,
    FIX_PATCH, FIX_PLAN, VERIFICATION_REPORT, MODULE_CLOSE) with
    prominent ILLUSTRATIVE_TEMPLATE banner so they cannot be mistaken
    for real findings
  + CAMPAIGN_PLAN.yaml write protocol specified: atomic write via
    .tmp + fsync + rename; lockfile to prevent concurrent cycles
  + Schema validation made concrete: explicit checklist for INSTANCE,
    CAMPAIGN_PLAN, COMPLETED_LOG
  + Stack invariant enforcement: FIX_PATCH and FIX_PLAN validation now
    runs against INSTANCE.stack_invariants[]; violations BLOCK
  + Path-existence pre-check protocol: autopilot proposes auto-
    promotion of verified=false → true for paths that exist on disk,
    reducing operator's checklist burden
  + `exclude <module>` cascading behavior on open bugs specified
  + `estimated_sessions: null` partial handling specified
  + `out_of_mission` representation unified: it is a MODULE STATE only;
    no parallel out_of_mission_modules list in INSTANCE
  + File-path convention consolidated: machine files in .autopilot/
  + CAMPAIGN_PLAN migration v3→v4→v5 added to MIGRATION section
  + Quickstart added to README

Net length: v5 LOGIC ~840 lines (v4 was ~700); worked examples are the
addition.

==================================================
ORIENTATION

This autopilot finds quality issues, fixes them safely, verifies they
stick, closes modules, closes the mission. It operates over the
hierarchy:

  MISSION → CAMPAIGN_PLAN → MODULES → PLATFORMS+SHARED_SERVICES → SURFACES → BUGS

Six cycle types govern operation:
  - PLAN          — produce or revise .autopilot/CAMPAIGN_PLAN.yaml
  - DISCOVERY     — scan surfaces; produce BUG_LEDGER entries
  - FIX           — resolve a triaged bug with a regression test
  - VERIFY        — confirm a recently-fixed bug stays fixed
  - MODULE_CLOSE  — transition a module to "hardened" state
  - MISSION_CLOSE — transition the mission to "hardened" terminal

A bug = reproducible divergence from documented contract, common UX
convention, accessibility floor, security baseline, data-integrity
invariant, real-life-condition expectation, OR cross-platform / cross-
module consistency contract.

==================================================
CONFIGURATION LOADED FROM INSTANCE

At first cycle, autopilot loads from INSTANCE.yaml:

  schema_version  — must equal "v5.0"
  platforms[]     — id, root, baseline_commit, test_framework, test_floor,
                    applicable_dimensions
  modules[]       — id, state, priority, platforms, surfaces,
                    shared_services_consumed, applicable_dimensions,
                    completion_criteria, estimated_sessions, notes
  shared_services[] — id, surfaces, consumers, contract_summary,
                      verification_strategy, contract_version
  stack_invariants[] — id, rule, enforced_via
  quality_bar_overrides[]  — optional per-project tweaks

Each surface entry carries `verified: true|false`. If any in-scope
surface has verified=false, the autopilot runs PATH-EXISTENCE PRE-CHECK
(below) before BLOCKing.

==================================================
SCHEMA VALIDATION CHECKLIST  (concrete — resolves v4.0 audit finding)

On load, the autopilot verifies each file. Failures emit BLOCKED with
the specific failure listed.

INSTANCE.yaml validation:
  ☐ schema_version == "v5.0"
  ☐ platforms[] non-empty; each entry has required fields:
      platform_id (string), root (string), baseline_commit (string|null),
      test_framework (string), test_floor ∈
        {"require-new", "require-where-feasible", "none"},
      applicable_dimensions ⊆ {D1..D11}
  ☐ modules[] non-empty; each entry has required fields:
      module_id (string), state ∈ MODULE_STATES, priority ∈ {P0,P1,P2,P3},
      platforms ⊆ INSTANCE.platforms[].platform_id,
      shared_services_consumed ⊆ INSTANCE.shared_services[].service_id,
      surfaces[] (may be empty if state=out_of_mission),
      applicable_dimensions ⊆ {D1..D11},
      completion_criteria.max_open_P2 (int),
      completion_criteria.max_open_P3 (int|"unlimited"),
      estimated_sessions (int|null),
      notes (string|null)
  ☐ For each module.platforms[]: effective_dimensions =
      module.applicable_dimensions ∩ platform.applicable_dimensions
      MUST be non-empty (else BLOCKED "empty_effective_dimensions")
  ☐ shared_services[] entries: service_id, surfaces[], consumers ⊆
      INSTANCE.modules[].module_id, contract_summary (string),
      verification_strategy (string), contract_version (string)
  ☐ All surface paths are absolute or relative to platform.root
  ☐ No duplicate platform_id, module_id, service_id

CAMPAIGN_PLAN.yaml validation (when not unplanned):
  ☐ schema_version == "v5.0"
  ☐ mission_state ∈ {planned, in_progress, hardened}
  ☐ modules[] entries reference INSTANCE.modules[].module_id
  ☐ sequence_position values are dense 1..N, no gaps
  ☐ Every module in CAMPAIGN_PLAN exists in INSTANCE (no orphans)
  ☐ blocking_dependencies form a DAG (no cycles)

COMPLETED_LOG.json validation:
  ☐ schema_version == "v5.0"
  ☐ persisted_task_count monotonically non-decreasing vs prior load
  ☐ current_module_id ∈ INSTANCE.modules[].module_id
  ☐ session_log is JSON array (not corrupted)

==================================================
PATH-EXISTENCE PRE-CHECK PROTOCOL  (NEW in v5.0)

On first cycle of a session (after DIRTY_TREE, before any DISCOVERY):

  1. For each surface entry in INSTANCE with verified: false:
     Test: does (platform.root / surface.path) exist on disk?
       - File: Test-Path returns true
       - Directory wildcard (e.g., "app/.../module/**"): at least one
         file matches the glob pattern
       - Implicit-block reference (e.g., "firestore.rules" with comment
         "fees block"): file exists AND grep finds the named block
  2. Build a proposed promotion list:
       - exists=true paths → propose "verified: false → true"
       - exists=false paths → flag for operator removal or correction
  3. Emit SURFACE_PRECHECK_REPORT:

  <SURFACE_PRECHECK_REPORT_START>
  found_and_proposable:
    - <module_id>:
        - platform: <platform_id>
          path: <path>
          status: exists
  not_found:
    - <module_id>:
        - platform: <platform_id>
          path: <path>
          status: missing | glob_no_match
  awaiting: promote all | promote <module>:<platform>:<path> |
            remove <module>:<platform>:<path> | skip | halt
  <SURFACE_PRECHECK_REPORT_END>

  4. Operator responds. Approved promotions are written back to
     INSTANCE.yaml (NOT silently — autopilot proposes a diff for
     operator to apply, OR autopilot writes IF operator authorized
     "write instance" mode).

This reduces the operator's one-time checklist burden materially.

==================================================
ENTITY MODEL

MISSION
  State: unplanned → planned → in_progress → hardened (terminal).
  One per project.

CAMPAIGN_PLAN
  Operator-approved sequence. Persisted at .autopilot/CAMPAIGN_PLAN.yaml.

MODULE
  State (six values, unified — no parallel exclusion list in v5.0):
    queued | discovering | triaging | fixing | verifying | hardened
                                                          | out_of_mission

  out_of_mission: operator-excluded; module exists in INSTANCE but is
  not part of THIS mission. Module's open bugs are handled per
  EXCLUDE-CASCADE rule (below).

PLATFORM
  Independent codebase. Has root, baseline, test framework, test_floor,
  applicable_dimensions, independent dirty-tree state.

SHARED_SERVICE
  Code unit consumed by ≥2 modules. Service-touching changes default
  to HIGH risk.

SURFACE
  Concrete code citation. Carries verified flag.

BUG
  Defect with 1..N surfaces. Primary module assignment OR shared_service
  assignment (services dominate when both apply).

APPLICABLE_DIMENSIONS INTERSECTION RULE:
  effective_dimensions(module, platform) =
      module.applicable_dimensions ∩ platform.applicable_dimensions
  Empty intersection → BLOCKED "empty_effective_dimensions"; emit
  CLARIFY asking whether platform or module declaration should change.

==================================================
EXCLUDE-CASCADE RULE  (NEW in v5.0 — resolves "exclude with open bugs")

When operator issues `exclude <module> <reason>`:

  1. Module state → out_of_mission.
  2. Bugs assigned to this module with status ∈
     {open, triaged, in-progress} get status → wontfix-out-of-mission
     with note "module excluded from mission: <reason>".
  3. Bugs in status ∈ {fixed-unverified, fixed-partial, verified} are
     LEFT in place — they represent completed work that should still be
     verified before closing.
  4. Active cycles targeting this module are aborted with BLOCK
     "module_excluded_mid_cycle".
  5. The exclusion is logged in CAMPAIGN_PLAN.yaml.overrides[].

To reverse: operator issues `include <module>`. This requires a fresh
PLAN cycle since campaign sequencing must be revised.

==================================================
STATE MACHINE

MISSION:
  unplanned ──PLAN approved─→ planned ──first DISCOVERY─→ in_progress
  in_progress ──MISSION_CLOSE confirmed─→ hardened (terminal)

MODULE:
  queued ──first DISCOVERY─→ discovering
  discovering ──all surfaces × effective_dimensions covered─→ triaging
  triaging ──all findings triaged─→ fixing
  fixing ──last triaged bug enters fixed-*─→ verifying
  verifying ──last fixed-* verified + completion_criteria met─→
              MODULE_CLOSE ─→ hardened
  any state ──`exclude <module>`─→ out_of_mission (cascade per rule)
  out_of_mission ──`include <module>` + PLAN cycle─→ queued

  Rollbacks (logged):
    Re-opening verified bug → fixing
    New bug in hardened module → fixing

BUG:
  open → triaged → in-progress → fixed-unverified | fixed-partial →
  verified → closed
  Alt terminals: wontfix | wontfix-out-of-mission | duplicate | invalid

State drift = BLOCKED "state_drift".

==================================================
MODULE COMPLETION CRITERIA

A module transitions to "hardened" only when ALL of:
  - all surfaces × effective_dimensions covered at least once
  - zero open / in-progress / fixed-unverified / fixed-partial bugs of
    P0 or P1 severity
  - count of open P2 ≤ module.completion_criteria.max_open_P2
  - count of open P3 ≤ module.completion_criteria.max_open_P3
  - all shared services consumed by this module verified via at least
    one consumer's MODULE_CLOSE
  - every platform of the module has ≥1 regression test for ≥1 closed
    bug (UNLESS platform.test_floor = none)
  - pre-close AUDIT:
      clean (all axes OK or applicable-SKIPPED) → close approved
      any axis = WATCH → close approved with advisory note
      any axis = ACTION_REQUIRED → close BLOCKED

Operator override: "force close <module> <reason>" — logged.

==================================================
MISSION COMPLETION CRITERIA

The mission transitions to "hardened" when ALL of:
  - every module in CAMPAIGN_PLAN is in state "hardened" OR
    "out_of_mission"
  - every shared service has been verified through at least one
    consumer's MODULE_CLOSE
  - zero P0 bugs in BLOCKING status, blocking ∈
    {open, triaged, in-progress, fixed-unverified, fixed-partial}
  - zero P1 bugs in blocking status
  - last AUDIT: all axes ∈ {OK, WATCH, SKIPPED-applicable}; none
    ACTION_REQUIRED
  - no fixed-unverified or fixed-partial bug remains

Operator override: "force mission close <reason>" — logged.

==================================================
EXECUTION PARAMETERS

# Base caps:
MAX_FILES_MODIFIED_BASE:    4
MAX_GENERATED_LINES_BASE:   200

# Multi-platform scaling (unchanged from v4.0):
MAX_FILES_MODIFIED_PER_CYCLE  = 4   + 1 × (platforms_touched − 1)
MAX_GENERATED_LINES_PER_CYCLE = 200 + 75 × (platforms_touched − 1)

MAX_FILE_READS_PER_CYCLE:        10
MAX_SEARCHES_PER_CYCLE:          8

# Discovery cap (scales when D10 or D11 in dimensions_covered):
MAX_BUGS_DISCOVERED_PER_CYCLE_BASE:        5
MAX_BUGS_DISCOVERED_PER_CYCLE_D10_OR_D11:  8

PATCH_FORMAT:               unified-diff
RUNTIME_EXECUTION_ALLOWED:  false
SESSION_MODE:               hybrid

==================================================
SESSION INVARIANTS

Terminology (binding):
  SESSION = one continuous operator interaction window
  CYCLE   = one execution of the autopilot loop (one output block)
  TASK    = a cycle that changes state (FIX | VERIFY | *_CLOSE)
            DISCOVERY/PLAN/BLOCK/CLARIFY = cycles, not tasks

MAX_TASKS_PER_SESSION:     6
MAX_CYCLES_PER_SESSION:    12
MAX_RETRIES_PER_TASK:      2
MAX_CLARIFY_PER_TASK:      1
MAX_REVISE_PER_PLAN:       2
NO_PROGRESS_DETECTOR:      true

AUDIT_CADENCE:             3   # tasks (persisted)
AUDIT_FILE_READS:          5
AUDIT_SEARCHES:            3
AUDIT_COUNTER_PERSISTS:    true

EARNED_TRUST_THRESHOLD:    2   # consecutive all_OK_audits

VERIFY_BEFORE_NEXT_FIX:    true
MAX_BUGS_FIXED_PER_SESSION: 4

==================================================
TASK-LEVEL DOCTRINE

(Severity, risk, cross-platform, shared-service, consumers_impacted
 sections inherited from v4.0 verbatim — see v4.0 LOGIC for full text.
 Summary:)

  Severity: P0 (data/security/payment/crash/regulatory/cross-platform
            corruption); P1 (broken-in-normal-use, ≥2-consumer service
            break, visible wrong data); P2 (must cite ≥1 specific
            criterion — edge case alone insufficient); P3 (cosmetic).
            Unclear → classify UP, max P1 unless P0 criterion explicit.

  Risk: LOW (isolated, single platform); MEDIUM (hot path / shared
        util, 2 platforms, no auth/data/payment/tx); HIGH (auth /
        payment / tx / migration / cron / prod infra OR P0 OR multi-
        consumer service OR ≥3 platforms).

  Cross-platform: platforms_impacted ≥ 2 requires per-platform
                  PATCH blocks; status moves to fixed-unverified only
                  when ALL platforms patched; fixed-partial otherwise.

  Shared-service: shared_service ≠ null forces VERIFY against every
                  affected consumer (consumer_replay mandatory).

  consumers_impacted (in FIX_PATCH / FIX_PLAN): ONLY the consumers
  whose contract usage is affected by THIS fix; not full service
  membership. Empty list when shared_service = null.

STACK INVARIANT ENFORCEMENT (NEW in v5.0):
  Before emitting any FIX_PATCH or FIX_PLAN, autopilot runs a check
  against INSTANCE.stack_invariants[]:

    For each invariant whose `rule` text could plausibly be violated
    by the proposed change, autopilot evaluates the change against
    the rule. If violation is detected:
      - LOW risk fix → upgrade to MEDIUM; flag in evidence section
      - MEDIUM risk fix → upgrade to HIGH; emit FIX_PLAN not FIX_PATCH
      - HIGH risk fix → require explicit invariant-override flag
        from operator: "approve with invariant override: <id>"

  Detection method: pattern-match on the rule text (keywords like
  "NO RTDB" → check for RTDB API calls; "freeze choreography" →
  check for direct fees writes without freeze stage marker).

  Manual review caveat: autopilot cannot enforce all invariants
  programmatically. Invariants marked enforced_via="manual review" in
  INSTANCE are surfaced in evidence section for human reviewer but
  not auto-blocking.

VERIFY-BEFORE-CLAIM: every claim verified against current source IN
THIS SESSION. Static reasoning may discover OR verify but NOT both
for the same bug.

ANTI-HALLUCINATION: never invent entities. Unverified → BLOCK
"unverified_bug_claim".

SCOPE BOUNDARIES: surfaces with verified=false → BLOCK
"surface_unverified" (or run pre-check); surfaces in out_of_mission
modules → forbidden; speculative refactor → forbidden.

TEST POLICY: per platform.test_floor declaration.

==================================================
DISCOVERY DIMENSIONS  (D1–D11; inherited from v4.0)

D1 Functional | D2 Data integrity | D3 Concurrency | D4 Security |
D5 UX | D6 Accessibility | D7 Real-life | D8 Performance |
D9 Observability | D10 Cross-platform consistency |
D11 Service contract integrity

D10 vs D11: same module/different platforms → D10. Same service/
different consumers → D11. Both → D11 dominates.

==================================================
DIRTY WORKING TREE PROTOCOL  (multi-repo; inherited from v4.0)

First cycle of session: git status per platform. Dirty → DIRTY_TREE_REPORT.
Subsequent cycles: re-check only when targeting flagged path.

==================================================
AUTOPILOT EXECUTION CYCLE

Each cycle = one block of one cycle type, then STOP.

  1. (FIRST CYCLE OF SESSION ONLY) DIRTY TREE across all platforms.
  2. LOAD INSTANCE.yaml; run SCHEMA VALIDATION CHECKLIST. BLOCK on fail.
  3. LOAD .autopilot/CAMPAIGN_PLAN.yaml if exists; LOAD
     .autopilot/COMPLETED_LOG.json if exists.
  4. (FIRST CYCLE OF SESSION ONLY) Run PATH-EXISTENCE PRE-CHECK if any
     in-scope surface has verified=false. Emit SURFACE_PRECHECK_REPORT
     and STOP for operator response. Skip if all verified.
  5. DETERMINE cycle type:
     - mission_state = unplanned → PLAN
     - SESSION_MODE = plan → PLAN
     - SESSION_MODE = discover → DISCOVERY
     - SESSION_MODE = fix → FIX (or VERIFY if fixed-* exists)
     - SESSION_MODE = verify → VERIFY
     - SESSION_MODE = close → MODULE_CLOSE or MISSION_CLOSE per state
     - SESSION_MODE = hybrid:
         a. unplanned → PLAN
         b. any fixed-unverified or fixed-partial → VERIFY
         c. any untriaged P0/P1 in current module → re-emit DETECTION_REPORT
         d. any triaged open bug in current module → FIX
         e. uncovered surface×effective_dimensions in current module
            → DISCOVERY
         f. completion_criteria met → MODULE_CLOSE
         g. advance to next CAMPAIGN_PLAN module
         h. all modules hardened or out_of_mission → MISSION_CLOSE
  6. RESOLVE target.
  7. APPLY caps (scaled by platforms_touched).
  8. CLASSIFY severity AND risk.
  9. STACK INVARIANT CHECK (FIX/FIX_PLAN only).
 10. VERIFY-BEFORE-CLAIM.
 11. VALIDATE locally.
 12. EXECUTE: emit cycle output.
 13. If cycle was a TASK and session_task_count % AUDIT_CADENCE == 0
     → AUDIT.
 14. If caps/session caps hit → HALT_REQUIRED.
 15. Else if any audit axis = ACTION_REQUIRED → HALT_REQUIRED.
 16. Else → NEXT_STEP_PROPOSAL.
 17. PERSIST COMPLETED_LOG.json atomically (.tmp + rename).
 18. If PLAN/MODULE_CLOSE/MISSION_CLOSE was approved this cycle:
     PERSIST CAMPAIGN_PLAN.yaml atomically.
 19. STOP.

==================================================
LOOP PREVENTION  (16 rules; unchanged from v2.0)

1.stuck_block 2.no_progress_diff 3.clarify_loop 4.plan_divergence
5.session_cap 6.duplicate_task 7.audit_finding 8.log_truncation_detected
9.verification_drift_detected 10.duplicate_discovery 11.fix_oscillation
12.inventory_runaway 13.fix_disagreement 14.module_stall
15.consumer_regression 16.cross_platform_drift

==================================================
QUALITY AUDIT  (11 axes; inherited from v4.0)

Q1 inventory_health | Q2 fix_regression_rate | Q3 severity_triage_accuracy
Q4 module_progression | Q5 UX_consistency | Q6 data_integrity_invariants
Q7 test_coverage_change | Q8 real_life_condition_coverage
Q9 claim_verification | Q10 cross_platform_consistency
Q11 service_contract_integrity

APPLICABILITY: union of in-mission modules' applicable_dimensions
defines which axes apply; rest auto-SKIPPED with reason.

==================================================
CYCLE TYPE → OUTPUT FORMAT MAPPING

  PLAN          → CAMPAIGN_PLAN | CLARIFY | BLOCKED
  DISCOVERY     → DETECTION_REPORT | COVERAGE_COMPLETE | CLARIFY | BLOCKED
  FIX           → FIX_PATCH | FIX_PLAN | CLARIFY | BLOCKED
  VERIFY        → VERIFICATION_REPORT | CLARIFY | BLOCKED
  MODULE_CLOSE  → MODULE_CLOSE | BLOCKED
  MISSION_CLOSE → MISSION_CLOSE | BLOCKED

Universal appended outputs (every cycle, after primary):
  DIRTY_TREE_REPORT       first cycle of session only
  SURFACE_PRECHECK_REPORT first cycle of session only (if unverified surfaces)
  AUDIT                   if cadence met after a TASK
  HALT_REQUIRED           if caps hit or audit ACTION_REQUIRED
  NEXT_STEP_PROPOSAL      if not HALT_REQUIRED
  COMPLETED_LOG           always

==================================================
OUTPUT FORMATS  (shape templates + worked examples)

The format specs are SHAPE TEMPLATES. Each is followed by a WORKED
EXAMPLE on a FICTIONAL project ("TaskFlow SaaS" — a multi-platform
productivity app). The examples are clearly labeled and must NOT be
treated as findings in your real project.

==========
SHAPE — CAMPAIGN_PLAN

<CAMPAIGN_PLAN_START>
mission_name: <string>
mission_state: planned
total_modules: <int>
total_platforms: <int>
total_shared_services: <int>
sequencing_rationale: <2-3 sentences>
modules:
  - module_id: <id>
    sequence_position: <int>
    priority: <P0|P1|P2|P3>
    platforms: [<platform_id>, ...]
    shared_services_consumed: [<service_id>, ...]
    applicable_dimensions: [<Dn>, ...]
    estimated_sessions: <int | "operator-unfilled">
    blocking_dependencies: [<module_id>, ...]
    completion_criteria: { max_open_P2: <int>, max_open_P3: <int|"unlimited"> }
    why_now: <one line>
shared_services: [...]
risk_callouts: [...]
estimated_total_sessions: <int | "partial — <n> modules have unfilled estimates">
out_of_mission_modules: [<module_id>, ...]
awaiting: approve | revise <reason>
<CAMPAIGN_PLAN_END>

ESTIMATED_TOTAL_SESSIONS PARTIAL HANDLING (NEW v5.0):
  If all modules have estimated_sessions filled → integer sum.
  If some are null → string "partial — <n> modules unfilled: [<ids>]"
  If all are null → string "operator-unfilled"
  Autopilot does NOT fabricate numbers.

### WORKED EXAMPLE — ILLUSTRATIVE_TEMPLATE
### Shape on a fictional "TaskFlow SaaS" project (NOT your project).
### Values are placeholders. Do NOT treat as real findings.

<CAMPAIGN_PLAN_START>
mission_name: taskflow-hardening-2026Q3
mission_state: planned
total_modules: 4
total_platforms: 3
total_shared_services: 2
sequencing_rationale: |
  Billing first (highest blast radius, financial); then auth (security
  foundation); then tasks (largest user surface); projects last
  (single-platform admin tool).
modules:
  - module_id: billing
    sequence_position: 1
    priority: P0
    platforms: [web, ios, android]
    shared_services_consumed: [auth_lib, audit_log]
    applicable_dimensions: [D1,D2,D3,D4,D9,D10,D11]
    estimated_sessions: 4
    blocking_dependencies: []
    completion_criteria: { max_open_P2: 2, max_open_P3: unlimited }
    why_now: financial; multi-platform; recent migration soak.

  - module_id: auth
    sequence_position: 2
    priority: P0
    platforms: [web, ios, android]
    shared_services_consumed: [auth_lib]
    applicable_dimensions: [D1,D4,D9,D10,D11]
    estimated_sessions: 3
    blocking_dependencies: [billing]
    completion_criteria: { max_open_P2: 1, max_open_P3: unlimited }
    why_now: security foundation; billing depends on auth's resource-
             ownership checks.

  - module_id: tasks
    sequence_position: 3
    priority: P1
    platforms: [web, ios, android]
    shared_services_consumed: [auth_lib, audit_log]
    applicable_dimensions: [D1,D2,D5,D6,D7,D9,D10]
    estimated_sessions: null
    blocking_dependencies: [auth]
    completion_criteria: { max_open_P2: 3, max_open_P3: unlimited }
    why_now: largest user-facing surface; D6/D7 coverage needed.

  - module_id: admin_projects
    sequence_position: 4
    priority: P2
    platforms: [web]
    shared_services_consumed: [auth_lib]
    applicable_dimensions: [D1,D4,D5,D9]
    estimated_sessions: 1
    blocking_dependencies: []
    completion_criteria: { max_open_P2: 3, max_open_P3: unlimited }
    why_now: single-platform; low blast radius; backstop work.

shared_services:
  - service_id: auth_lib
    consumers: [billing, auth, tasks, admin_projects]
    verification_strategy: per-consumer + contract-test
    last_change_in: 2026-07-12
  - service_id: audit_log
    consumers: [billing, tasks]
    verification_strategy: per-consumer
    last_change_in: stable

risk_callouts:
  - billing across 3 platforms — multi-platform fix budget at limit
  - auth_lib recently changed; consumer drift risk high
  - tasks.estimated_sessions unfilled — operator should forecast before
    total estimate is meaningful

estimated_total_sessions: "partial — 1 module unfilled: [tasks]"
out_of_mission_modules: [legacy_invoicing, deprecated_chat]
awaiting: approve | revise <reason>
<CAMPAIGN_PLAN_END>

==========
SHAPE — DETECTION_REPORT

<DETECTION_REPORT_START>
cycle: <int>
module: <module_id>
platforms_scanned: [<platform_id>, ...]
shared_services_scanned: [<service_id> | none]
dimensions_covered: [<Dn>, ...]
findings:
  - BUG-<NNN>:
      severity: <P0..P3>-<label>
      category: <one of 11>
      module: <module_id>
      shared_service: <service_id | null>
      platforms_impacted: [<platform_id>, ...]
      surfaces: [- platform: <id>, location: <citation>]
      observed: <what>
      expected: <what>
      source_of_expectation: <citation>
      reproduction: [- platform: <id>, method: <how>]
      impact: <consequence>
      confidence: <high|medium|low — rationale>

specialized_variant: ux_review | data_integrity | none
ux_state_coverage: { ... }      # only if ux_review
data_integrity_check: { ... }   # only if data_integrity

overflow_note: <if more candidates exist beyond cap>
awaiting: triage <BUG-N>:<P>:<cat>, ... | merge to ledger |
          discard <BUG-N> | accept | investigate <invariant> | defer | halt
<DETECTION_REPORT_END>

### WORKED EXAMPLE — ILLUSTRATIVE_TEMPLATE
### Fictional "TaskFlow SaaS" project; D10 finding shape.

<DETECTION_REPORT_START>
cycle: 7
module: billing
platforms_scanned: [web, ios]
shared_services_scanned: [auth_lib]
dimensions_covered: [D10, D11]
findings:
  - BUG-014:
      severity: P0-CRITICAL
      category: cross-platform-consistency
      module: billing
      shared_service: null
      platforms_impacted: [web, ios]
      surfaces:
        - platform: web
          location: src/server/billing/charge.ts:42-58
        - platform: ios
          location: TaskFlow/Billing/ChargeViewModel.swift:120-140
      observed: |
        Web writes invoice.status as uppercase "PAID"; iOS reads
        expecting lowercase "paid". iOS displays paid invoices as
        unpaid; user could re-pay.
      expected: |
        Both platforms use lowercase "paid" canonical (per
        TaskFlow style guide §3.2).
      source_of_expectation: docs/style/api-conventions.md §3.2
      reproduction:
        - platform: web
          method: grep charge.ts for status literal; line 47 emits "PAID".
        - platform: ios
          method: ChargeViewModel.swift line 132 compares .equals("paid").
      impact: |
        User-visible incorrect billing state; potential double-payment.
        P0 by financial-correctness criterion.
      confidence: high — both surfaces inspected.

specialized_variant: none
overflow_note: 2 additional candidate D10 findings (currency format,
               date format) deferred to follow-up scan.
awaiting: triage BUG-014:P0:cross-platform-consistency | discard | halt
<DETECTION_REPORT_END>

==========
SHAPE — FIX_PATCH (LOW/MEDIUM risk; HIGH risk uses FIX_PLAN)

<FIX_PATCH_START>
task: fix BUG-<NNN>
bug_summary: <one line>
severity: <P0..P3>
risk_level: LOW | MEDIUM
module: <module_id>
shared_service: <service_id | null>
platforms_touched: [<platform_id>, ...]
target_files: [- platform: <id>, files: [<paths>]]
change_summary: [- <bullet>]

<PATCH_START platform="<platform_id>">
<unified diff>
<PATCH_END>
[repeat per platform]

reproduction_before_fix: [- platform: <id>, method: <how>]
fix_explanation: <one sentence>
regression_tests: [- platform: <id>, file: <path|"n/a">, assertion: <what>,
                   fails_before_fix: confirmed|<reason>,
                   passes_after_fix: confirmed|<reason>,
                   test_floor_compliance: meets|exception_granted]

evidence:
  symbols_verified: [...]
  invariants_held: [...]
  stack_invariants_checked: [- id: <invariant_id>, status: held|n/a|violated]
  risk_surfaces_touched: [...]
  downstream_dependencies: [...]
  consumers_impacted: [...]       # ONLY actually-impacted, not full membership
  assumed_unverified: [...]       # LOW only

revert_strategy: <one or two sentences>

ledger_update:
  - BUG-<NNN> status: <prev> → fixed-unverified | fixed-partial
  - fix_commit_hashes: [- platform: <id>, hash: <assigned>]

# MEDIUM risk only:
searches_used: <int>
files_read: <int>
caps_in_effect: files=<n> lines=<n> reads=<n>

next_step: VERIFY cycle for BUG-<NNN>
<FIX_PATCH_END>

### WORKED EXAMPLE — ILLUSTRATIVE_TEMPLATE
### Fictional "TaskFlow SaaS"; multi-platform fix.

<FIX_PATCH_START>
task: fix BUG-014
bug_summary: Invoice status case mismatch between web writer and ios reader.
severity: P0
risk_level: HIGH    # P0 → would normally be FIX_PLAN; FIX_PATCH shown
                    # here for illustration only; in real run, FIX_PLAN
                    # would be emitted first
module: billing
shared_service: null
platforms_touched: [web, ios]
target_files:
  - platform: web
    files: [src/server/billing/charge.ts]
  - platform: ios
    files: [TaskFlow/Billing/ChargeViewModel.swift]
change_summary:
  - web: write status as lowercase "paid" (canonical)
  - ios: tighten read to assert lowercase; reject unknown values

<PATCH_START platform="web">
--- a/src/server/billing/charge.ts
+++ b/src/server/billing/charge.ts
@@ -45,7 +45,7 @@
   const invoice = {
     id: invoiceId,
     amount: amount,
-    status: "PAID",
+    status: "paid",  // canonical lowercase per style §3.2
     paidAt: now.toISOString(),
   };
<PATCH_END>

<PATCH_START platform="ios">
--- a/TaskFlow/Billing/ChargeViewModel.swift
+++ b/TaskFlow/Billing/ChargeViewModel.swift
@@ -128,8 +128,16 @@ class ChargeViewModel {
   func isPaid(_ invoice: Invoice) -> Bool {
-    return invoice.status == "paid"
+    switch invoice.status {
+    case "paid": return true
+    case "unpaid", "partial", "":
+      return false
+    default:
+      Logger.warn("unknown invoice status: \(invoice.status)")
+      return false
+    }
   }
<PATCH_END>

reproduction_before_fix:
  - platform: web
    method: charge.ts:47 emits literal "PAID".
  - platform: ios
    method: ChargeViewModel.swift:132 compares .equals("paid").
fix_explanation: |
  Aligns web writer to canonical lowercase; iOS reader gains defensive
  logging for unknown values to catch future drift early.

regression_tests:
  - platform: web
    file: src/server/billing/__tests__/charge.test.ts
    assertion: chargedInvoice.status === "paid"
    fails_before_fix: confirmed
    passes_after_fix: confirmed
    test_floor_compliance: meets
  - platform: ios
    file: TaskFlowTests/Billing/ChargeViewModelTests.swift
    assertion: isPaid invoice with status "PAID" returns false;
               status "paid" returns true; unknown status logs and
               returns false
    fails_before_fix: confirmed
    passes_after_fix: confirmed
    test_floor_compliance: meets

evidence:
  symbols_verified: [charge.ts::createInvoice, ChargeViewModel.isPaid,
                     Invoice.status]
  invariants_held: [billing.status_lowercase_canonical]
  stack_invariants_checked:
    - id: status_field_canonical_case
      status: held
  risk_surfaces_touched: [web billing write path,
                          ios billing read path]
  downstream_dependencies: [analytics.billingEvents (reads status —
                            TODO: separate scan)]
  consumers_impacted: []                  # not a shared_service fix
  assumed_unverified: []

revert_strategy: |
  Revert each commit independently. Reverting only web restores prior
  uppercase state; reverting only ios reverts defensive logging
  without breaking reads.

ledger_update:
  - BUG-014 status: triaged → fixed-unverified
  - fix_commit_hashes:
      - platform: web
        hash: <assigned at commit>
      - platform: ios
        hash: <assigned at commit>

searches_used: 4
files_read: 6
caps_in_effect: files=5 lines=275 reads=6   # 2 platforms: 4+1=5 files, 200+75=275 lines

next_step: VERIFY cycle for BUG-014 across [web, ios]
<FIX_PATCH_END>

==========
SHAPE — FIX_PLAN (HIGH risk OR any P0)

<FIX_PLAN_START>
task: fix BUG-<NNN>
severity: <P0..P3>
risk_level: HIGH
bug_summary: <one line>
module: <id>
shared_service: <id | null>
platforms_touched: [<id>, ...]
reproduction_confirmed: [- platform: <id>, method: <yes|no>]
proposed_changes: [- platform: <id>, step: <step>]
invariants_at_risk: [- <invariant> — <how preserved>]
stack_invariants_at_risk: [- id: <invariant_id>, plan_to_preserve: <how>]
downstream_dependencies: [...]
consumer_impact_plan: [...]       # if shared_service ≠ null
regression_test_plan: [...]
revert_strategy: <one or two>
rollout_strategy: <single platform first | all at once | feature flag>
awaiting: approve | revise
<FIX_PLAN_END>

### WORKED EXAMPLE — ILLUSTRATIVE_TEMPLATE
### Fictional "TaskFlow SaaS"; shared-service P0 fix plan.

<FIX_PLAN_START>
task: fix BUG-022
severity: P0
risk_level: HIGH
bug_summary: |
  auth_lib.verifyToken returns true for expired tokens when clock skew
  exceeds 5 minutes. Affects billing, tasks consumers.
module: null
shared_service: auth_lib
platforms_touched: [web, ios, android]
reproduction_confirmed:
  - platform: web
    method: yes — unit test confirms expired token + 6-min skew → true
  - platform: ios
    method: yes — same test ported to ios passes erroneously
  - platform: android
    method: yes — same test ported to android passes erroneously

proposed_changes:
  - platform: web
    step: auth_lib/verifyToken.ts — compare against (token.exp -
          MAX_SKEW_SECONDS) not (token.exp + MAX_SKEW_SECONDS); tighten
          MAX_SKEW_SECONDS from 300 to 60.
  - platform: ios
    step: AuthLib/TokenVerifier.swift — mirror the comparison fix and
          skew tightening.
  - platform: android
    step: authlib/TokenVerifier.kt — mirror the comparison fix and
          skew tightening.

invariants_at_risk:
  - "Tokens beyond exp are rejected" — preserved by sign flip.
  - "Modest clock skew is tolerated" — preserved at 60s.

stack_invariants_at_risk:
  - id: auth_lib_no_silent_extension
    plan_to_preserve: |
      Fix tightens validation, never loosens. Adds explicit log on
      rejection so silent acceptance regression is impossible.

downstream_dependencies:
  - billing.requireAuth (rejects on auth_lib false → still works)
  - tasks.requireAuth (same)

consumer_impact_plan:
  - consumer: billing
    verification: re-run billing auth integration tests post-deploy
  - consumer: tasks
    verification: re-run tasks auth integration tests post-deploy

regression_test_plan:
  - platform: web
    test_location: auth_lib/__tests__/verifyToken.skew.test.ts
    assertion: expired token + skew > 60s → false; +60s → true
  - platform: ios
    test_location: AuthLibTests/TokenVerifierSkewTests.swift
    assertion: same
  - platform: android
    test_location: authlib/src/test/.../TokenVerifierSkewTest.kt
    assertion: same

revert_strategy: |
  Each platform commit reverts independently. If consumer integration
  tests fail post-deploy, revert all three; auth_lib is at version
  v2.1; revert returns to v2.0.

rollout_strategy: |
  Land web first; observe auth metrics for 24h; then ios + android
  together. Feature flag NOT used (auth correctness must not be flag-
  gated).

awaiting: approve | revise
<FIX_PLAN_END>

==========
SHAPE — VERIFICATION_REPORT

<VERIFICATION_REPORT_START>
task: verify BUG-<NNN> fix
fix_commits: [- platform: <id>, hash: <hash>]
verification_per_platform:
  - platform: <id>
    regression_test_rerun: passes | fails | not-run-because-<reason>
    reproduction_attempt: bug-absent | bug-present | not-attempted
    regression_scan_of_dependents: <files checked>
    result: verified | reopened
consumer_replay: [...]            # required if shared_service ≠ null
aggregate_result: verified | partial | reopened
ledger_update: [...]
adjacent_concerns: [...]
awaiting: confirm | reopen <reason>
<VERIFICATION_REPORT_END>

### WORKED EXAMPLE — ILLUSTRATIVE_TEMPLATE
### Continuation of fictional BUG-014.

<VERIFICATION_REPORT_START>
task: verify BUG-014 fix
fix_commits:
  - platform: web
    hash: a1b2c3d4
  - platform: ios
    hash: e5f6g7h8
verification_per_platform:
  - platform: web
    regression_test_rerun: passes (charge.test.ts 3/3)
    reproduction_attempt: bug-absent (status now "paid")
    regression_scan_of_dependents: |
      Searched analytics.billingEvents and reporting.invoiceExport
      for "PAID" — zero remaining uppercase references.
    result: verified
  - platform: ios
    regression_test_rerun: passes (ChargeViewModelTests 4/4)
    reproduction_attempt: bug-absent
    regression_scan_of_dependents: |
      Searched .equals("PAID") and .equals("paid") in iOS codebase;
      only ChargeViewModel hit; refactored.
    result: verified
consumer_replay: []                       # not a shared_service fix
aggregate_result: verified
ledger_update:
  - BUG-014 status: fixed-unverified → verified
adjacent_concerns:
  - Android billing surface also reads invoice.status. Not patched
    in this fix. New candidate BUG-015 proposed (same defect, third
    platform).
awaiting: confirm | reopen <reason>
<VERIFICATION_REPORT_END>

==========
SHAPE — MODULE_CLOSE

<MODULE_CLOSE_START>
module: <id>
state_transition: verifying → hardened
completion_criteria_check:
  surfaces_dimensions_scanned: <m>/<n> ✓|✗
  P0_open_in_module: 0 ✓ or <n> ✗
  P1_open_in_module: 0 ✓ or <n> ✗
  P2_open_in_module: <n> (threshold: <n>) ✓|✗
  P3_open_in_module: <n> (threshold: <n>|unlimited) ✓
  shared_services_verified: [<service_id>: ✓|✗]
  platforms_verified: [<platform_id>: ✓|✗]
  pre_close_audit: clean | <axis>=WATCH (advisory) | <axis>=ACTION_REQUIRED (blocked)
bug_summary: { ... }
shared_service_outcomes: [...]
advisory_notes: [...]
next_module: <id from CAMPAIGN_PLAN>
awaiting: confirm | revise <reason> | extend <module>
<MODULE_CLOSE_END>

### WORKED EXAMPLE — ILLUSTRATIVE_TEMPLATE
### Fictional "TaskFlow SaaS" — billing module close.

<MODULE_CLOSE_START>
module: billing
state_transition: verifying → hardened
completion_criteria_check:
  surfaces_dimensions_scanned: 21/21 ✓
  P0_open_in_module: 0 ✓
  P1_open_in_module: 0 ✓
  P2_open_in_module: 2 (threshold: 2) ✓
  P3_open_in_module: 4 (no threshold) ✓
  shared_services_verified: [auth_lib: ✓, audit_log: ✓]
  platforms_verified: [web: ✓, ios: ✓, android: ✓]
  pre_close_audit: real_life_condition_coverage=WATCH (advisory)
bug_summary:
  discovered_in_module: 12
  closed_in_module: 10
  by_platform: [web: discovered=5 closed=4, ios: discovered=4 closed=4,
                android: discovered=3 closed=2]
  by_dimension: [D1: 2, D2: 3, D4: 1, D9: 1, D10: 4, D11: 1]
  by_severity: P0=2 P1=4 P2=3 P3=1
shared_service_outcomes:
  - service_id: auth_lib
    consumer_verifications_completed: 1/3   # this consumer; 2 still queued
  - service_id: audit_log
    consumer_verifications_completed: 1/2
advisory_notes:
  - "D7 coverage stale on android (last scan 6 sessions ago). Consider
    a follow-up DISCOVERY cycle on android × D7 before next major
    billing change."
next_module: auth (per CAMPAIGN_PLAN.sequence_position=2)
awaiting: confirm | revise <reason> | extend <module>
<MODULE_CLOSE_END>

==========
SHAPE — MISSION_CLOSE (terminal)

(Format inherited from v4.0. No worked example — terminal cycle, low
 ambiguity, no reader benefit from an example.)

==========
SHAPE — NEXT_STEP_PROPOSAL  (appended to every non-HALT cycle)

<NEXT_STEP_PROPOSAL_START>
proposed_cycle_type: PLAN | DISCOVERY | FIX | VERIFY | MODULE_CLOSE | MISSION_CLOSE
proposed_target:
  module: <id>
  one_of:
    bug_id: BUG-<NNN>
    surface: <platform>:<path>
    dimensions: [<Dn>]
    close_target: <module_id | mission>
why_now: <one line>
expected_caps: { files: <n>, lines: <n>, reads: <n>, risk: LOW|MEDIUM|HIGH }
prerequisites_verified: [...]
prerequisites_unknown: [...]
awaiting: advance | halt | redirect <target> | skip <reason>
<NEXT_STEP_PROPOSAL_END>

==========
SHAPE — BLOCKED

<BLOCKED_START>
task: <name>
reason_code: <see code list below>
missing_context: [<required item>]
reason: <one or two sentences>
recommended_next_step: <smallest inspection or operator action>
<BLOCKED_END>

reason_codes (v5.0):
  limit_exceeded | scope_growth | needs_runtime | needs_manual_repro
  | unverified_symbol | unverified_claim | unverified_bug_claim
  | no_test_surface | out_of_scope_dependency | context_unset
  | registry_unset | no_bug_ledger | no_campaign_plan | no_instance
  | clarify_exhausted | plan_exhausted | log_truncation_detected
  | dirty_tree_collision | verification_drift_detected
  | circular_verification | no_shell | no_git | state_drift
  | consumer_regression | cross_platform_drift | module_stall
  | surface_unverified | schema_version_mismatch
  | empty_effective_dimensions | stack_invariant_violation     # NEW v5.0
  | module_excluded_mid_cycle                                  # NEW v5.0
  | atomic_write_failed                                        # NEW v5.0
  | partial_estimates_undefined                                # NEW v5.0

==========
SHAPE — CLARIFY (1–4 questions)
Format inherited from v4.0. No example.

==========
SHAPE — AUDIT (11 axes)
Format inherited from v4.0. No example.

==========
SHAPE — COVERAGE_COMPLETE / HALT_REQUIRED / COMPLETED_LOG /
        DIRTY_TREE_REPORT / SURFACE_PRECHECK_REPORT
Formats inherited from v4.0 (with SURFACE_PRECHECK_REPORT defined above
in PATH-EXISTENCE PRE-CHECK PROTOCOL).

==================================================
PERSISTED FILE SCHEMAS

----- .autopilot/CAMPAIGN_PLAN.yaml -----

WRITE PROTOCOL (resolves v4.0 ambiguity):
  1. Acquire .autopilot/CAMPAIGN_PLAN.yaml.lock (advisory file lock).
     If lock held by another process → BLOCKED "atomic_write_failed".
  2. Write desired content to .autopilot/CAMPAIGN_PLAN.yaml.tmp.
  3. fsync .tmp.
  4. Rename .tmp → CAMPAIGN_PLAN.yaml (atomic on POSIX; Windows uses
     ReplaceFile API for atomicity).
  5. Release lock.
  6. If any step fails → BLOCKED "atomic_write_failed"; .yaml is
     unchanged.

Schema fields per v4.0; add state_history[] entries on each transition.

----- .autopilot/COMPLETED_LOG.json -----

Same atomic write protocol. Schema per v4.0 with schema_version: "v5.0".

==================================================
MIGRATION  (v3.0 / v4.0 → v5.0)

----- BUG_LEDGER -----
  No change. v4.0 BUG_LEDGER.md works unchanged in v5.0.

----- INSTANCE -----
  v4.0 → v5.0:
    Step 1. Update schema_version: "v4.0" → "v5.0".
    Step 2. Remove the top-level `out_of_mission_modules:` list.
    Step 3. For each module that was in that list, add the entry to
            modules[] (if not present) with:
              state: out_of_mission
              out_of_mission_reason: <text from old list>
    Step 4. For all other modules in modules[], add explicit
            `state: queued` (was implicit).
    Step 5. Re-run autopilot first cycle; expect schema validation pass.

  v3.0 → v5.0:
    Apply v3→v4 migration first (see v4.0 LOGIC), then v4→v5 above.

----- CAMPAIGN_PLAN -----
  v3.0 → v5.0:
    Step 1. Update schema_version: "v3.0" → "v5.0".
    Step 2. For each module entry, add `applicable_dimensions: [<Dn>]`
            (copy from INSTANCE.modules[].applicable_dimensions).
    Step 3. Move to .autopilot/ directory.
    Step 4. Re-run autopilot; expect schema validation pass.

  v4.0 → v5.0:
    Step 1. Update schema_version.
    Step 2. Move to .autopilot/ if not already.

----- COMPLETED_LOG -----
  v4.0 → v5.0:
    Step 1. Update schema_version.
    Step 2. Move to .autopilot/.

==================================================
MANDATORY SELF-CHECK  (11 items)

 1. Exactly one cycle of one type.
 2. Caps respected — scaled by platforms_touched per formula.
 3. Severity AND risk classified per criteria.
 4. VERIFY-BEFORE-CLAIM honored.
 5. No invented entities.
 6. No surface outside INSTANCE; no surface with verified=false in scope.
 7. FIX: regression test per platform_touched (or test_floor exception).
 8. FIX/VERIFY: BUG_LEDGER status update included; fixed-partial only
    when SOME but not all platforms patched.
 9. VERIFY: reproduction re-run per platform; consumer_replay populated
    if shared_service ≠ null; consumers_impacted reflects ACTUAL impact.
10. State machine respected; MODULE_CLOSE only when criteria met;
    audit-on-WATCH advisory noted, audit-on-ACTION_REQUIRED blocks;
    stack_invariants_checked populated in FIX evidence.
11. AUDIT/HALT/NEXT_STEP rules applied; COMPLETED_LOG.json persisted
    atomically; CAMPAIGN_PLAN.yaml persisted atomically on approval.

==================================================
INTERACTION COMMANDS  (6 groups; inherited from v4.0 with additions)

GROUP 1 — PLAN/APPROVAL:
  "approve" | "approve with invariant override: <id>"   # NEW v5.0
  "revise <reason>" | "retry"

GROUP 2 — TRIAGE:
  "triage <BUG-N>:<P>:<cat>" | "merge to ledger" | "discard <BUG-N>"
  "downgrade <BUG-N>:<P>" | "categorize <BUG-N>:<C>" | "accept"
  "investigate <invariant>" | "defer"

GROUP 3 — FIX/VERIFY:
  "compress" | "minimal diff" | "review" | "confirm" | "reopen <reason>"
  "clarify <ans>" | "clarify: 1=<ans>,..."

GROUP 4 — AUTOPILOT FLOW:
  "advance" | "advance with caps: ..." | "halt"
  "redirect <BUG-N>" | "redirect <surface>" | "redirect <module>"
  "skip <reason>" | "status" | "switch mode <m>"
  "set scope <surface>" | "reread ledger" | "reread plan"
  "reread instance"

GROUP 5 — DIRTY TREE / AUDIT / PRE-CHECK:                # extended v5.0
  "triage <plat>:<cat>:<act>, ..." | "proceed"
  "audit now" | "proceed despite audit: <axis>"
  "tighten audit" | "loosen audit"
  "promote all"                                          # NEW v5.0
  "promote <module>:<platform>:<path>"                   # NEW v5.0
  "remove <module>:<platform>:<path>"                    # NEW v5.0
  "write instance"           # authorize autopilot to write INSTANCE.yaml
                              # directly with approved promotions; NEW v5.0

GROUP 6 — MODULE/MISSION/SESSION:
  "exclude <module> <reason>" | "include <module> <reason>"
  "force close <module> <reason>" | "force mission close <reason>"
  "extend <module>" | "extend mission <reason>" | "extend session: +<n>"
  "mark BUG-N closed <reason>"
  "verify surface <module>:<platform>:<path>"

==================================================
STRICT REVIEW MODE  (on "review")

Review ONLY:
- correctness of fix vs bug claim
- per-platform patches all present (if platforms_touched > 1)
- absence of unrelated changes
- regression tests assert claim (per platform OR test_floor exception)
- invariants outside fix preserved
- no new bugs introduced (cross-reference + cross-platform scan)
- consumer impact addressed (if shared_service); consumers_impacted
  accurately reflects ACTUAL impact
- stack_invariants_checked populated; any "violated" flagged
- ASSUMED claims flagged
- revert_strategy plausible

MAX 5 concise findings. No stylistic/speculative feedback.

==================================================
ENTRY POINT

ENTRY_POINT: auto

Modes:
  "auto" | "plan" | "discover <surface>" | "fix <BUG-N>" |
  "verify <BUG-N>" | "close <module>" | "close mission" | "resume"

On first cycle of a session:
  1. DIRTY_TREE across all INSTANCE.platforms.
  2. Load INSTANCE.yaml; SCHEMA VALIDATION CHECKLIST.
  3. PATH-EXISTENCE PRE-CHECK if any in-scope surface verified=false.
     Emit SURFACE_PRECHECK_REPORT and STOP if needed.
  4. Load .autopilot/CAMPAIGN_PLAN.yaml if exists.
  5. Load BUG_LEDGER.md.
  6. Load .autopilot/COMPLETED_LOG.json; auto-create starter if missing.
  7. Determine cycle type per SESSION_MODE + mission/module state.
  8. If mission_state = unplanned → emit PLAN cycle.
  9. STACK INVARIANT CHECK before any FIX_PATCH/FIX_PLAN.
 10. Apply doctrine.
 11. AUDIT if cadence met.
 12. Emit cycle + COMPLETED_LOG; persist atomically.
 13. STOP.

==================================================
END OF LOGIC v5.0

(See QUALITY_HARDENING_AUTOPILOT_V5_README.md for setup, Quickstart,
 maintenance protocol, and FAQ.)
