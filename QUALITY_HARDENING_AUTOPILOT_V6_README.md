# Quality Hardening Autopilot v6.0 — Integration Guide

Multi-platform / multi-module quality hardening for the School ERP project.

**v6.0 posture**: honest about LLM execution limits. Calibrated against
v5.0 audit findings. NOT a feature expansion.

---

## ⚠️ Read before deploying

The LOGIC.md file opens with a **Known Limitations** section. Read it
first. Five honest constraints:
1. State coherence degrades past ~10 cycles per session
2. Pattern-matching natural-language invariants is approximate
3. Filesystem atomicity is best-effort
4. Cross-file consistency can drift
5. Subtle multi-hop bugs may be missed

Each has a mitigation. The autopilot is a force-multiplier, not an
oracle. Calibrate expectations.

---

## 🎯 Recommended first mission (calibration run)

**Do NOT start with Fees.** Fees has 4 platforms, P0 severity, freeze
choreography. If the autopilot misbehaves there, the blast radius is
financial.

**Start with `school_config` or `academic_planner`:**
- 1-2 platforms only (Admin + maybe Firebase rules)
- P2/P3 priority
- Contained user-facing scope
- Phase 3 soak (school_config) — close-out work, low ambiguity

This calibration run answers:
- Does the autopilot understand your codebase patterns?
- Does the DISCOVERY → triage → FIX → VERIFY flow match your throughput?
- Is the cycle cadence (6 tasks/session) right?
- Are the worked-example formats matching your real outputs?

After 5-10 calibration cycles, you have data to adjust INSTANCE.yaml
priorities, completion_criteria, or audit cadence before tackling Fees.

**To run calibration: temporarily set state: out_of_mission on all
modules EXCEPT school_config in INSTANCE.yaml. Run a full mission to
hardened. Then revert exclusions and run a real PLAN cycle.**

---

## 5-Minute Quickstart

1. **Verify file layout**:
   ```
   c:\xampp\htdocs\Grader\school\
   ├── QUALITY_HARDENING_AUTOPILOT_V6_LOGIC.md
   ├── QUALITY_HARDENING_AUTOPILOT_V6_INSTANCE.yaml
   ├── QUALITY_HARDENING_AUTOPILOT_V6_README.md
   ├── BUG_LEDGER.md
   └── .autopilot/                  (create if missing)
   ```

2. **Fill two baseline commits** in INSTANCE.yaml:
   ```powershell
   cd D:\Projects\SchoolSyncParent;  git rev-parse HEAD
   cd D:\Projects\SchoolSyncTeacher; git rev-parse HEAD
   ```

3. **Invoke autopilot** with LOGIC.md as system prompt.

4. **First cycle**: DIRTY_TREE → SCHEMA VALIDATION → PATH-EXISTENCE
   PRE-CHECK if any `verified: false` surfaces in-scope →
   `SURFACE_PRECHECK_REPORT`.

5. **Respond — IMPORTANT WORKFLOW**:
   - **First**: review the `not_found:` list. Each entry is a surface
     that doesn't exist. Choose: `remove <m>:<p>:<path>` (delete from
     INSTANCE) OR edit INSTANCE to fix the path.
   - **Then**: `promote all` to flip the `found:` list to verified=true.
   - Optionally: `write instance` to authorize autopilot to apply
     promotions directly.

6. **After pre-check resolves**, the autopilot detects
   `mission_state: unplanned` and emits PLAN with proposed sequencing.
   Approve, revise, or reject.

---

## Three-file architecture

| File | Tier | Owner | Path |
|---|---|---|---|
| LOGIC.md | Stable | Prompt engineer | Repo root |
| INSTANCE.yaml | Project | Operator | Repo root |
| README.md | Stable | Prompt engineer | Repo root |
| CAMPAIGN_PLAN.yaml | Runtime | Autopilot + operator | `.autopilot/` |
| BUG_LEDGER.md | Runtime | Mixed | Repo root |
| COMPLETED_LOG.json | Runtime | Autopilot only | `.autopilot/` |

Human-edited files → repo root. Machine-managed files → `.autopilot/`.

---

## v6.0 vs v5.0 — what changed

| v5.0 audit finding | v6.0 fix |
|---|---|
| Stack invariant enforcement overstated | `enforcement_tier` field added: `programmatic`/`advisory`/`manual`. Honest about what autopilot can auto-detect. |
| Cognitive load grew (length) | LOGIC trimmed 840 → ~650 lines via consolidation and tighter prose. |
| PATH-EXISTENCE PRE-CHECK conflated levels | Round-trip flow documented: autopilot proposes path list → tool runs check → results return → autopilot proposes promotions. |
| Atomic-write protocol aspirational | Replaced with **honest** best-effort + recovery section. Explicit caveat: true atomicity needs external orchestrator. |
| Examples covered happy path only | **Added BLOCKED + CLARIFY worked examples** with realistic scenarios. |
| `promote all` invites skipped review | Quickstart updated: **review not_found list FIRST**, then bulk-promote found. |
| No rate limit on invariant overrides | **Q12 audit axis**: WATCH on 2x override of same invariant; ACTION_REQUIRED on 3x. |
| `include <module>` post-exclusion bug state undefined | **EXCLUDE/INCLUDE CASCADE rules**: bugs in `wontfix-out-of-mission` auto-flip to `open` for re-triage on `include`. |
| Migration chained not self-contained | **v3→v6 direct migration** path inlined in LOGIC. |
| `SURFACE_PRECHECK_REPORT` documentation asymmetric | Moved to OUTPUT FORMATS section as first-class format. |
| Cycle vs Task ambiguity persistent since v2.0 | **FINAL definitive rule** in TERMINOLOGY section: TASK = state-changing cycle (FIX/VERIFY/*_CLOSE only). |
| 8-item Operator Checklist partially obsolete | Reduced to **5 items**, reflecting pre-check automation. |

---

## Common operator commands

```
# Pre-check (NEW: review not_found FIRST)
promote all
promote fees:parent-android:app/src/main/java/.../fees/**
remove tc_transfer_certificate:admin-web:application/controllers/TC.php
write instance              # autopilot writes INSTANCE.yaml directly

# Planning
approve
approve with invariant override: no_rtdb     # rate-limited via Q12
revise <reason>

# Triage
triage BUG-014:P0:cross-platform-consistency
merge to ledger
discard BUG-014

# Flow
advance
halt
redirect <BUG-N|surface|module>
status

# Module/Mission
exclude staff_hardening "HOLD discipline"   # bugs → wontfix-out-of-mission
include staff_hardening "HOLD cleared"      # bugs auto-flip back to open
force close <module> <reason>
force mission close <reason>

# Verification
confirm
reopen <reason>

# Inspection
review
audit now
```

---

## Maintenance protocol

| Change | Action |
|---|---|
| New module appears | Add to modules[] with `state: queued`; PATH-EXISTENCE pre-check will validate surfaces; run PLAN to revise CAMPAIGN_PLAN |
| Module retired | Change `state: out_of_mission`; add `out_of_mission_reason`; run PLAN if was in CAMPAIGN_PLAN |
| Platform added | Add to platforms[]; add module surfaces |
| Shared service refactored | Update shared_services[]; bump `contract_version`; consumers need re-verification |
| Stack invariant added | Update stack_invariants[] with **realistic enforcement_tier** (`programmatic` only if literal pattern; `manual` if conceptual) |

After any INSTANCE edit: `reread instance`.

---

## Health check (before long sessions)

- [ ] `git status` clean on all 4 platforms (or DIRTY_TREE triaged)
- [ ] INSTANCE.yaml has zero remaining `verified: false` in-mission
- [ ] BUG_LEDGER.md parses
- [ ] `.autopilot/COMPLETED_LOG.json` `schema_version: "v6.0"`
- [ ] INSTANCE.yaml `schema_version: "v6.0"`
- [ ] No P0 in `fixed-partial` / `fixed-unverified` from prior session

---

## FAQ

**Q: What's `enforcement_tier` and why does it matter?**
A: v5.0 promised the autopilot would "enforce stack invariants." Honest
calibration: only literal-pattern rules (e.g., "NO RTDB" → grep RTDB
API names) can be reliably auto-detected. Conceptual rules (e.g.,
"freeze choreography") cannot. v6.0 declares each invariant's
enforcement tier: `programmatic` (auto-detected, BLOCKs on violation),
`advisory` (best-effort detection, flagged in evidence), `manual`
(surfaced for human reviewer only). Calibrate expectations.

**Q: What if the autopilot misses a stack invariant violation?**
A: For `advisory`/`manual` tiers, it can. Operator's `review` command
runs strict safety review. Worst case: bug ships, gets discovered
post-deploy, gets re-opened via standard process.

**Q: Why was the autopilot's atomic write promise demoted?**
A: True atomicity (lockfile + fsync + rename) requires syscalls the
LLM tool layer doesn't expose. v6.0 acknowledges: write tool calls
are atomic in their own scope, but cross-call atomicity isn't
guaranteed. Mitigations: don't run two instances; don't interrupt
mid-cycle; recovery procedure documented in LOGIC.

**Q: What's Q12 invariant_override_discipline?**
A: New audit axis. Tracks repeated `approve with invariant override: <id>`
commands. 2x override of same invariant → WATCH. 3x → ACTION_REQUIRED.
Rationale: repeated overrides suggest the invariant text needs revision
OR you're normalizing exceptions. Either way, worth surfacing.

**Q: Can I re-include a module I excluded?**
A: Yes. `include <module> <reason>` flips state back to `queued`. Bugs
that were marked `wontfix-out-of-mission` auto-flip back to `open` for
re-triage. Operator confirms or wontfixes them individually after the
fresh PLAN cycle revises CAMPAIGN_PLAN.

**Q: Why did you recommend calibrating on school_config first?**
A: It's the cheapest calibration: 2 platforms (admin + Firebase rules),
P2 priority, current Phase 3 soak is close-out work with low ambiguity.
If the autopilot works there, you've validated the flow before
committing to Fees (4 platforms, P0, freeze discipline). If it fails
or produces low-quality output, you've spent minimal time and can
adjust INSTANCE.yaml before the high-stakes work.

---

## When to abandon and restart

Restart conditions (rare):
- `COMPLETED_LOG.json` corrupted → archive, delete, restart via
  `ENTRY_POINT = "resume"`. State rebuilds from CAMPAIGN_PLAN + BUG_LEDGER.
- INSTANCE schema bump (next major LOGIC version): apply MIGRATION.
- Mission scope changed dramatically: run fresh PLAN cycle.

Never restart on a BLOCK. Investigate the `reason_code`.

---

End of README v6.0.
