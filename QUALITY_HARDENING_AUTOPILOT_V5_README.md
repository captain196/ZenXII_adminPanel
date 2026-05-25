# Quality Hardening Autopilot v5.0 — Integration Guide

Multi-platform / multi-module quality hardening for the School ERP project.

---

## 5-Minute Quickstart

1. **Verify file layout** (one-time):
   ```
   c:\xampp\htdocs\Grader\school\
   ├── QUALITY_HARDENING_AUTOPILOT_V5_LOGIC.md         (this prompt)
   ├── QUALITY_HARDENING_AUTOPILOT_V5_INSTANCE.yaml    (project data)
   ├── QUALITY_HARDENING_AUTOPILOT_V5_README.md        (this file)
   ├── BUG_LEDGER.md                                   (your bug registry)
   └── .autopilot/
       ├── CAMPAIGN_PLAN.yaml          (autopilot creates on first PLAN approval)
       └── COMPLETED_LOG.json          (autopilot creates on first cycle)
   ```
   Create `.autopilot/` directory if missing.

2. **Fill two baseline commits** in INSTANCE.yaml:
   ```powershell
   # In each repo root:
   cd D:\Projects\SchoolSyncParent;  git rev-parse HEAD
   cd D:\Projects\SchoolSyncTeacher; git rev-parse HEAD
   ```
   Paste each hash into the corresponding `baseline_commit: null` field.

3. **Invoke the autopilot** with LOGIC.md as the system prompt. Tell it
   to read INSTANCE.yaml from the project root.

4. **First cycle expected behavior**:
   - Runs DIRTY_TREE_REPORT across all 4 platforms.
   - Loads INSTANCE.yaml; validates schema.
   - Runs PATH-EXISTENCE PRE-CHECK on `verified: false` surfaces.
   - Emits `SURFACE_PRECHECK_REPORT` listing what exists vs missing.

5. **Respond** with one of:
   - `promote all` — flip every found surface to `verified: true`
   - `promote fees:parent-android:app/src/main/java/.../fees/**` — one
     at a time
   - `remove <module>:<platform>:<path>` — delete a non-existent surface
   - `write instance` — authorize the autopilot to apply approved
     promotions directly to INSTANCE.yaml

6. **After pre-check resolves**, the autopilot detects
   `mission_state: unplanned` and emits a `PLAN` cycle proposing module
   sequencing based on `priority`, `blocking_dependencies`, and notes
   (HOLD/freeze/soak states). Approve, revise, or reject.

On `approve`, `.autopilot/CAMPAIGN_PLAN.yaml` is written atomically and
the mission transitions to `planned`. The next cycle begins `DISCOVERY`
on the first sequenced module.

---

## Three-file architecture

| File | Tier | Owner | Mutated when... |
|---|---|---|---|
| LOGIC.md | Stable | Prompt engineer | Prompt logic revised (rare) |
| INSTANCE.yaml | Project | Operator | Project structure changes |
| README.md | Stable | Prompt engineer | Integration protocol changes |
| .autopilot/CAMPAIGN_PLAN.yaml | Runtime | Autopilot writes; operator approves | PLAN cycle runs |
| BUG_LEDGER.md | Runtime | Autopilot writes; operator triages | Every DISCOVERY/FIX/VERIFY |
| .autopilot/COMPLETED_LOG.json | Runtime | Autopilot only | Every cycle (atomic write) |

**File-path discipline**: human-authored/edited files at repo root;
autopilot-writes files in `.autopilot/`. This is a deliberate split —
makes commits, gitignore, and backup policies clean.

**Critical**: never edit COMPLETED_LOG.json by hand. Corruption →
`BLOCKED log_truncation_detected`.

---

## How the files relate

```
LOGIC.md  ──reads──→  INSTANCE.yaml  (registries: platforms, modules, services)
   │
   │  produces (with operator approval)
   ▼
.autopilot/CAMPAIGN_PLAN.yaml ──drives──→  cycle sequencing across modules
   │
   │  cycles produce findings
   ▼
BUG_LEDGER.md  ←──updated each cycle──→  .autopilot/COMPLETED_LOG.json
```

---

## v5.0 vs v4.0 — what changed

| v4.0 audit finding | v5.0 fix |
|---|---|
| All concrete worked examples removed | Worked examples restored per cycle type, with prominent `ILLUSTRATIVE_TEMPLATE` banners; values are placeholders on a fictional "TaskFlow SaaS" project |
| CAMPAIGN_PLAN.yaml write protocol ambiguous | Specified: lockfile + .tmp + fsync + rename; `atomic_write_failed` BLOCK on failure |
| Schema validation hand-wavy | Concrete checklist for INSTANCE, CAMPAIGN_PLAN, COMPLETED_LOG validation |
| Stack invariants documentation-only | Autopilot enforcement protocol: pattern-match rules during FIX validation; violations escalate risk or BLOCK |
| Surfaces `verified: false` checklist tedious | PATH-EXISTENCE PRE-CHECK auto-detects existing paths and proposes promotions; operator approves in bulk |
| `exclude <module>` interaction with bugs undefined | EXCLUDE-CASCADE rule: open/triaged/in-progress bugs → `wontfix-out-of-mission`; fixed-* preserved |
| `estimated_sessions: null` partial behavior undefined | PLAN computes "partial — N modules unfilled" when some are null |
| `out_of_mission` representation inconsistent | Unified: state on module entry only; no parallel list in INSTANCE |
| Migration only covered BUG_LEDGER | Migration covers BUG_LEDGER, INSTANCE, CAMPAIGN_PLAN, COMPLETED_LOG |
| `.autopilot/` for one file, root for others | Consolidated: runtime files in `.autopilot/`, human files at root |

---

## Starting a fresh mission

1. Verify INSTANCE.yaml is current (run through Operator Checklist at
   bottom of that file).
2. Confirm `BUG_LEDGER.md` exists (create empty if not).
3. Confirm `.autopilot/` directory exists.
4. Invoke the autopilot with `ENTRY_POINT = "auto"`.
5. Walk through DIRTY_TREE_REPORT, SURFACE_PRECHECK_REPORT, then PLAN
   cycle.

---

## Resuming a mission

Invoke with `ENTRY_POINT = "resume"`. The autopilot loads
`.autopilot/COMPLETED_LOG.json` and restores `persisted_task_count`,
`last_audit_at_task`, `current_module_id`, `consecutive_all_OK_audits`.

If any bug is in `fixed-unverified` or `fixed-partial`, VERIFY (or
continuation-FIX) fires before any new work.

---

## Maintenance protocol

### When project structure changes

| Change | Action |
|---|---|
| New module appears | Add to `modules:` with `state: queued`; mark surfaces; run PLAN to revise CAMPAIGN_PLAN |
| Module retired | Change its `state: out_of_mission`; add `out_of_mission_reason`. If was in CAMPAIGN_PLAN, run PLAN to revise |
| Platform added | Add to `platforms:`; add module surfaces for it |
| Shared service refactored | Update `shared_services[]`; bump `contract_version`; consumers need re-verification |
| Stack invariant added | Update `stack_invariants:` with `enforced_via` set appropriately; autopilot picks it up on next cycle |

After any INSTANCE edit: `reread instance`.

### When prompt logic needs updating

Edit LOGIC.md separately. INSTANCE.yaml untouched (unless schema bumps).

---

## Common operator commands

```
# Pre-check
promote all
promote fees:parent-android:app/src/main/java/.../fees/**
remove tc_transfer_certificate:admin-web:application/controllers/TC.php
write instance              # autopilot applies promotions to INSTANCE.yaml

# Planning
approve                     # accept CAMPAIGN_PLAN or FIX_PLAN
approve with invariant override: no_rtdb
                            # acknowledge intentional invariant break
revise <reason>

# Triage
triage BUG-014:P0:cross-platform-consistency
merge to ledger             # accept all findings as-proposed
discard BUG-014

# Flow
advance                     # execute proposed NEXT_STEP
halt
redirect <BUG-N|surface|module>
status                      # COMPLETED_LOG snapshot only

# Module/Mission lifecycle
exclude staff_hardening "HOLD discipline"
include staff_hardening "HOLD cleared per <ref>"
force close <module> <reason>
force mission close <reason>

# Verification
confirm                     # close verified bug or *_CLOSE
reopen <reason>

# Inspection
review                      # strict safety review of pending FIX_PATCH
audit now
```

---

## Health check

Before relying on a long session, verify:

- [ ] `git status` clean (or DIRTY_TREE triaged) on **all 4 platforms**
- [ ] INSTANCE.yaml has zero remaining `verified: false` in-mission
      surfaces (or pre-check resolved them)
- [ ] BUG_LEDGER.md parses
- [ ] `.autopilot/COMPLETED_LOG.json` schema_version is `"v5.0"`
- [ ] No P0 bugs sitting in `fixed-partial` or `fixed-unverified` from
      prior session (would force VERIFY next anyway)
- [ ] INSTANCE.yaml schema_version is `"v5.0"`
- [ ] LOGIC.md is paired with the same v5.0 INSTANCE.yaml

---

## FAQ

**Q: What if a surface path uses a wildcard like `app/.../fees/**`?**
A: Pre-check passes if at least one file matches the glob. Promote to
`verified: true` then. If you want stricter checking, replace the
wildcard with a concrete list of files.

**Q: What happens to bugs in a module I exclude mid-mission?**
A: EXCLUDE-CASCADE rule: open/triaged/in-progress bugs become
`wontfix-out-of-mission`. Fixed-unverified/fixed-partial/verified are
preserved (they represent completed work). See LOGIC.md
"EXCLUDE-CASCADE RULE" section.

**Q: Can the autopilot write to INSTANCE.yaml directly?**
A: Only if you issue `write instance` during pre-check. By default, it
proposes a diff and waits for you to apply.

**Q: What if `estimated_sessions` is null for some modules?**
A: PLAN computes `estimated_total_sessions: "partial — N modules
unfilled: [<ids>]"`. The mission proceeds; you can fill estimates
later via INSTANCE.yaml edit + `reread instance`.

**Q: The stack invariant enforcement BLOCKed my fix — what now?**
A: Read the BLOCK reason. Two options: (a) revise the fix to avoid the
violation; (b) if the violation is intentional and operator-approved,
respond with `approve with invariant override: <invariant_id>`. The
override is logged in CAMPAIGN_PLAN.yaml.overrides[].

**Q: Why are runtime files in `.autopilot/` but BUG_LEDGER at root?**
A: BUG_LEDGER is operator-triaged constantly; root is the natural
place. The autopilot writes findings but you triage them. Pure
machine-state (CAMPAIGN_PLAN, COMPLETED_LOG) goes to `.autopilot/`
where it can be gitignored if desired.

**Q: I want to commit the autopilot state across sessions/machines.
Should I gitignore `.autopilot/` or not?**
A: Up to you. If single-operator on one machine: gitignore is fine.
If multiple operators or multiple machines: commit `.autopilot/` so
state is shared. Either choice is supported.

---

## When to abandon and restart

Restart conditions (rare):

- `COMPLETED_LOG.json` corrupted beyond repair: archive it, delete,
  restart with `ENTRY_POINT = "resume"`. State rebuilds from
  CAMPAIGN_PLAN + BUG_LEDGER.
- INSTANCE schema bump (next major LOGIC version): migrate per LOGIC.md
  MIGRATION section.
- Mission scope changed dramatically: run fresh `PLAN` cycle;
  CAMPAIGN_PLAN.yaml is overwritten atomically.

Never restart just because a cycle BLOCKED. Investigate the
`reason_code` — that's exactly what BLOCK is for.

---

End of README.
