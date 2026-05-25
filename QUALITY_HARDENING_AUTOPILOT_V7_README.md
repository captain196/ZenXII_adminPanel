# Quality Hardening Autopilot v7.0 — Integration Guide

Multi-platform / multi-module quality hardening for the School ERP project.

**v7.0 posture**: self-contained (no cross-version references), with
calibration advice and bug-status precision improvements.

---

## ⚙️ Read first: Operational Characteristics

LOGIC.md opens with **Operational Characteristics** — five behavioral
constraints of LLM-executed autopilots, each with a mitigation:

1. State coherence degrades past ~10 cycles per session
2. Pattern-matching natural-language invariants is approximate
3. Filesystem atomicity is best-effort
4. Cross-file consistency can drift
5. Subtle multi-hop bugs may be missed

Read that section first. The autopilot is a force-multiplier, not an oracle.

---

## 🎯 Recommended first mission (calibration run)

**Do NOT start with Fees.** 4 platforms, P0, freeze choreography = worst
calibration target.

**Start with `school_config` or `academic_planner`:**
- school_config: 2 platforms (admin + Firebase rules), P2, Phase 3
  close-out work — low ambiguity
- academic_planner: 1 platform (admin only), P3, contained scope

**Setup for calibration (v7.0 — cleaner than v6.0 advice):**

Do NOT use `exclude <module>` to scope down — that cascades bugs to
`wontfix-out-of-mission` and creates churn on re-include. Instead:

When the PLAN cycle proposes a CAMPAIGN_PLAN with all 9 in-mission
modules, respond:

```
revise calibration: include only [school_config] in sequence; others
remain queued but unsequenced
```

The autopilot will re-propose a CAMPAIGN_PLAN with school_config alone
in sequence. Other in-mission modules stay in `queued` state (not
out_of_mission) and can be promoted to the next mission's CAMPAIGN_PLAN
without bug-status churn.

After 5–10 calibration cycles on school_config, you have data on:
- Does the autopilot understand your codebase patterns?
- Does the DISCOVERY → triage → FIX → VERIFY flow match your throughput?
- Is the cycle cadence right?
- Are enforcement_tiers calibrated correctly for your stack invariants?
- Are the worked-example formats matching the real outputs?

Then run a fresh PLAN to scope in the remaining modules (Fees, etc.).

---

## 5-Minute Quickstart

1. **Verify file layout:**
   ```
   c:\xampp\htdocs\Grader\school\
   ├── QUALITY_HARDENING_AUTOPILOT_V7_LOGIC.md
   ├── QUALITY_HARDENING_AUTOPILOT_V7_INSTANCE.yaml
   ├── QUALITY_HARDENING_AUTOPILOT_V7_README.md
   ├── BUG_LEDGER.md
   └── .autopilot/                  (create if missing)
   ```

2. **Fill two baseline commits in INSTANCE.yaml:**
   ```powershell
   cd D:\Projects\SchoolSyncParent;  git rev-parse HEAD
   cd D:\Projects\SchoolSyncTeacher; git rev-parse HEAD
   ```

3. **Invoke autopilot** with LOGIC.md as system prompt.

4. **First cycle**: DIRTY_TREE → SCHEMA VALIDATION → PATH-EXISTENCE
   PRE-CHECK (if any `verified: false` surfaces in scope) →
   `SURFACE_PRECHECK_REPORT`.

5. **Respond:**
   - **First**: review the `not_found:` list. Remove or fix each path.
   - **Then**: `promote all` to flip the `found:` list.

6. **PLAN cycle fires** with proposed sequencing. For calibration:
   `revise calibration: include only [school_config] in sequence`.
   For full mission: `approve`.

---

## Three-file architecture

| File | Tier | Owner | Path |
|---|---|---|---|
| LOGIC.md | Stable | Prompt engineer | Repo root |
| INSTANCE.yaml | Project | Operator | Repo root |
| README.md | Stable | Prompt engineer | Repo root |
| CAMPAIGN_PLAN.yaml | Runtime | Autopilot + operator approval | `.autopilot/` |
| BUG_LEDGER.md | Runtime | Mixed | Repo root |
| COMPLETED_LOG.json | Runtime | Autopilot only | `.autopilot/` |

---

## v7.0 vs v6.0 — what changed

| v6.0 audit finding | v7.0 fix |
|---|---|
| Worked examples referenced "see v5.0 LOGIC" | **All examples inlined**; LOGIC self-contained again (cost ~70 lines; judged worth it) |
| Some `enforcement_tier: programmatic` overstated | **Re-calibrated**: `gold_css_means_teal` → advisory; `audit_log_immutable` and `fees_authoritative_collection` split per-platform (PHP/rules = programmatic; Kotlin = advisory) |
| Enforcement didn't capture platform variance | **Per-platform `enforcement_tier`**: `{default, overrides{platform_id: tier}}` format |
| `include <module>` could revive intentional wontfixes | **Bug status distinguished**: `wontfix` (operator-set, permanent) vs `wontfix-out-of-mission` (cascade-set, auto-flip on include). Only cascade-set flip back. |
| PATH-EXISTENCE pre-check toolchain-fragile | **Tool preference order specified**: Glob > Test-Path > ls fallback |
| No example of Q12 in action | **Worked AUDIT example** with Q12 = ACTION_REQUIRED on 3x `no_rtdb` override |
| Migration v3→v6 hand-wavy | **Concrete before/after** for BUG_LEDGER and INSTANCE in MIGRATION section |
| "Known Limitations" psychologically risky framing | Renamed to **"Operational Characteristics"** — neutral, behavioral |
| Recommended First Mission used `exclude` (noisy) | **Cleaner calibration**: don't exclude; revise PLAN to scope down sequence |
| Fictional examples didn't show your project's flavor | **One CLARIFY example** uses School ERP-style invariant language (staff_hardening HOLD discipline) — bridges fictional shapes to your vocabulary |

---

## Common operator commands

```
# Pre-check
promote all
remove tc_transfer_certificate:admin-web:application/controllers/TC.php
write instance              # autopilot writes INSTANCE.yaml directly

# Planning
approve
approve with invariant override: no_rtdb     # rate-limited via Q12
revise <reason>
revise calibration: include only [<modules>] in sequence  # NEW v7 pattern

# Triage
triage BUG-014:P0:cross-platform-consistency
merge to ledger
discard BUG-014

# Flow
advance | halt | redirect <BUG-N|surface|module> | status

# Module / Mission
exclude staff_hardening "HOLD discipline"  # bugs → wontfix-out-of-mission
include staff_hardening "HOLD cleared"     # cascade-set bugs auto-flip
                                            # to open; operator-set
                                            # wontfix preserved

force close <module> <reason>
force mission close <reason>

# Verification
confirm | reopen <reason>

# Inspection
review
audit now
```

---

## Maintenance protocol

| Change | Action |
|---|---|
| New module appears | Add to modules[] with `state: queued`; PATH-EXISTENCE pre-check validates surfaces; run PLAN to revise |
| Module retired mid-mission | Change `state: out_of_mission`; add `out_of_mission_reason`; run PLAN |
| Platform added | Add to platforms[]; add module surfaces |
| Shared service refactored | Update shared_services[]; bump `contract_version`; consumers need re-verification |
| Stack invariant added | Use **realistic enforcement_tier**: `programmatic` only for literal patterns; `manual` for conceptual rules; per-platform override if detection varies by language |

After any INSTANCE edit: `reread instance`.

---

## Health check (before long sessions)

- [ ] `git status` clean on all 4 platforms
- [ ] INSTANCE.yaml has zero remaining `verified: false` in-mission
- [ ] BUG_LEDGER.md parses
- [ ] `.autopilot/COMPLETED_LOG.json` `schema_version: "v7.0"`
- [ ] INSTANCE.yaml `schema_version: "v7.0"`
- [ ] No P0 in `fixed-partial` / `fixed-unverified` from prior session

---

## FAQ

**Q: What's per-platform `enforcement_tier`?**
A: A rule like `no_rtdb` is easy to detect in PHP (grep API names) but
less so in Firebase rules DSL. v7.0 lets you declare:
```yaml
enforcement_tier:
  default: programmatic
  overrides:
    firebase-rules: advisory
```
The autopilot uses programmatic enforcement on most platforms and
falls back to advisory on firebase-rules.

**Q: What's the difference between `wontfix` and `wontfix-out-of-mission`?**
A: `wontfix` = operator explicitly decided not to fix; permanent.
`wontfix-out-of-mission` = cascade-set when a module was excluded;
will auto-flip to `open` if the module is later re-included. This
distinction prevents `include` from accidentally reviving bugs you
intentionally wontfixed.

**Q: How does v7.0 know which tool to use for PATH-EXISTENCE PRE-CHECK?**
A: Preference order in LOGIC.md: Glob tool (most-specific) → PowerShell
Test-Path → Bash ls. Autopilot picks the most-specific available tool
in its environment.

**Q: What's the Q12 audit example showing?**
A: A worked AUDIT block where the operator has overridden the `no_rtdb`
stack invariant 3 times (e.g., for 3 different bugs). Q12 fires
ACTION_REQUIRED — the autopilot halts and surfaces the pattern. Either
the invariant text needs revision, or the operator is normalizing
exceptions. Worth surfacing either way.

**Q: For calibration, why not just use `exclude`?**
A: `exclude` cascades all open bugs in that module to
`wontfix-out-of-mission`. Useful when you're permanently removing a
module from mission scope. Wasteful when you just want to focus the
sequence temporarily. v7.0 advises: leave modules in `queued`, scope
down only the CAMPAIGN_PLAN sequence. Cleaner reversal.

---

## When to abandon and restart

Rare conditions:
- COMPLETED_LOG.json corrupted → archive, delete, restart via
  `ENTRY_POINT = "resume"`
- INSTANCE schema bump → apply MIGRATION (LOGIC has v3→v7 direct path)
- Mission scope changed dramatically → run fresh PLAN

Never restart on a BLOCK. Investigate the `reason_code`.

---

End of README v7.0.
