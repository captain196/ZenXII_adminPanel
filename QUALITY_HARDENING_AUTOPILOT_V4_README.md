# Quality Hardening Autopilot v4.0 — Integration Guide

Three-file system for running multi-platform / multi-module quality
hardening missions on the School ERP project.

---

## Files in this system

| File | Tier | Owner | Mutated when... |
|---|---|---|---|
| `QUALITY_HARDENING_AUTOPILOT_V4_LOGIC.md` | Stable | Prompt engineer | Prompt logic itself is revised (rare) |
| `QUALITY_HARDENING_AUTOPILOT_V4_INSTANCE.yaml` | Project | Operator | Project structure changes (modules added/removed, services refactored, platforms added) |
| `QUALITY_HARDENING_AUTOPILOT_V4_README.md` | Stable | Prompt engineer | Integration protocol changes |
| `CAMPAIGN_PLAN.yaml` | Runtime | Autopilot writes / operator approves | PLAN cycle runs |
| `BUG_LEDGER.md` | Runtime | Autopilot updates / operator triages | Every DISCOVERY / FIX / VERIFY |
| `COMPLETED_LOG.json` | Runtime | Autopilot only | Every cycle (atomic write) |

**Critical**: never edit `COMPLETED_LOG.json` by hand. Treat it like a
database journal. If it gets corrupted, the autopilot will refuse to
proceed (`BLOCKED log_truncation_detected`).

---

## How the files relate

```
LOGIC.md  ──reads──→  INSTANCE.yaml  (registries: platforms, modules, services)
   │
   │  produces, with operator approval
   ▼
CAMPAIGN_PLAN.yaml ──drives──→  cycle sequencing across modules
   │
   │  cycles produce findings
   ▼
BUG_LEDGER.md  ←──updated each cycle──→  COMPLETED_LOG.json (state journal)
```

`LOGIC.md` is the prompt. The autopilot reads the other files to
understand your specific project, but the rules are stable.

---

## Starting a fresh mission

1. **Verify INSTANCE.yaml**
   - Open `QUALITY_HARDENING_AUTOPILOT_V4_INSTANCE.yaml`.
   - Walk the **Operator Checklist** at the bottom of the file.
   - For each module, confirm `surfaces[].paths` exist and flip
     `verified: false` → `verified: true`. Remove paths that don't
     exist.
   - Fill `baseline_commit` for `parent-android` and `teacher-android`
     (run `git rev-parse HEAD` in each repo).
   - Fill `estimated_sessions` per module (rough forecast; PLAN cycle
     will sum these).
   - Save and commit.

2. **Create empty `BUG_LEDGER.md`** (or seed with known issues in v4.0
   schema — see LOGIC.md migration section).

3. **Invoke the autopilot** with `LOGIC.md` as the prompt, pointing at
   `INSTANCE.yaml`. The first cycle will:
   - Run `DIRTY_TREE_REPORT` across all 4 platforms (admin-web,
     parent-android, teacher-android, firebase-rules).
   - Load INSTANCE.yaml; validate.
   - Detect `mission_state: unplanned`.
   - Emit a `PLAN` cycle proposing module sequencing.

4. **Review the proposed CAMPAIGN_PLAN**. The autopilot does NOT
   pre-decide for you; it proposes based on `priority`,
   `blocking_dependencies`, and notes (HOLD / freeze / soak states).
   Approve, revise, or reject.

5. On `approve`, the autopilot writes `CAMPAIGN_PLAN.yaml` and
   transitions to `mission_state: planned`. The next cycle begins
   `DISCOVERY` on the first sequenced module.

---

## Resuming a mission

Run with `ENTRY_POINT = "resume"`. The autopilot will:

1. Load `COMPLETED_LOG.json`; restore `persisted_task_count`,
   `last_audit_at_task`, `current_module_id`, `consecutive_all_OK_audits`.
2. If any bug has status `fixed-unverified` or `fixed-partial` →
   `VERIFY` (or continuation-`FIX`) before anything else.
3. Otherwise continue the hybrid-mode cycle selection at the current
   module.

---

## Maintenance protocol (keep the system fresh)

### When project structure changes

| Change | Action |
|---|---|
| New module appears | Add to `modules:` in INSTANCE.yaml; mark surfaces `verified: true` after confirming; set `priority` and `estimated_sessions`; run `PLAN` cycle to revise CAMPAIGN_PLAN |
| Module removed | Move to `out_of_mission_modules:` with reason. If mid-mission and module was in CAMPAIGN_PLAN, run `PLAN` cycle to revise |
| Platform added | Add to `platforms:` in INSTANCE.yaml; set `baseline_commit`, `test_framework`, `test_floor`, `applicable_dimensions`; add module surfaces for that platform |
| Shared service refactored | Update `shared_services[]` entry; bump `contract_version`; the autopilot will treat consumers as needing re-verification |
| Stack invariant added/changed | Update `stack_invariants:`. Existing fixes won't be re-evaluated; future cycles will respect the new rule |

After any INSTANCE edit, send `reread instance` so the autopilot picks
it up.

### When prompt logic needs updating

Edit `LOGIC.md` separately. INSTANCE.yaml is untouched. This is the
whole point of the v4.0 split.

If `LOGIC.md` major-versions (v4 → v5), check the LOGIC.md MIGRATION
section for INSTANCE schema bumps.

---

## File location convention

For this project, all files live at the repo root:

```
c:\xampp\htdocs\Grader\school\
├── QUALITY_HARDENING_AUTOPILOT_V4_LOGIC.md
├── QUALITY_HARDENING_AUTOPILOT_V4_INSTANCE.yaml
├── QUALITY_HARDENING_AUTOPILOT_V4_README.md
├── CAMPAIGN_PLAN.yaml                         (created by PLAN cycle)
├── BUG_LEDGER.md                              (already exists)
└── .autopilot/
    └── COMPLETED_LOG.json                     (created on first cycle)
```

Create the `.autopilot/` directory before first run. Add it to
`.gitignore` only if you want session state to be local-only; otherwise
commit it so resume works across machines.

---

## What v4.0 fixes vs v3.0

(Brief — see LOGIC.md CHANGELOG for full list.)

| v3.0 problem | v4.0 fix |
|---|---|
| Project state and prompt logic intermixed | Three-file split |
| Worked examples could be mistaken for real bugs | Examples labeled `ILLUSTRATIVE_TEMPLATE`; placeholders only |
| `applicable_dimensions` ambiguous (platform vs module) | Intersection rule specified |
| `out_of_mission` state referenced but undefined | Added to MODULE state machine |
| Pre-decided CAMPAIGN_PLAN sequence | Removed; PLAN cycle proposes, operator approves |
| Fabricated "20 sessions" estimate | Operator-owned `estimated_sessions` per module in INSTANCE |
| Surfaces partly guessed without flagging | Every surface has `verified: true|false`; autopilot BLOCKs on unverified |
| Caps scaling generous (4+2N) | Tightened to 4+1·(N−1) files, 200+75·(N−1) lines |
| Migration hand-wavy | Concrete step-by-step in LOGIC.md MIGRATION section |
| `consumers_impacted` semantics unclear | Specified: only consumers whose contract usage is affected, not full membership |

---

## Common operator commands

(Full catalog in LOGIC.md — Interaction Commands section.)

```
approve                          # accept a CAMPAIGN_PLAN or FIX_PLAN
advance                          # execute the proposed NEXT_STEP
triage BUG-014:P0:cross-platform-consistency
merge to ledger                  # accept all findings as-proposed
confirm                          # close a verified bug or *_CLOSE
verify surface fees:admin-web:application/controllers/Accounting.php
                                 # flip a surface to verified=true
exclude staff_hardening "HOLD discipline"
                                 # set module to out_of_mission
reread instance                  # after editing INSTANCE.yaml
audit now                        # force an AUDIT cycle
halt                             # stop the autopilot
status                           # emit COMPLETED_LOG only; no work
```

---

## Health check

Before relying on a long session, verify:

- `git status` is clean (or DIRTY_TREE_REPORT triaged) on **all 4
  platforms**.
- `INSTANCE.yaml` has zero remaining `verified: false` for in-mission
  modules.
- `BUG_LEDGER.md` exists and parses.
- `COMPLETED_LOG.json` schema_version is `"v4.0"`.
- No P0 bugs are sitting in `fixed-partial` or `fixed-unverified` —
  these would force VERIFY on the next cycle anyway.

---

## When to abandon and restart

Restart conditions (rare):

- `COMPLETED_LOG.json` corrupted beyond repair → archive it, recreate
  empty, restart with `ENTRY_POINT = "resume"`. Mission state is
  rebuilt from `CAMPAIGN_PLAN.yaml` and `BUG_LEDGER.md`.
- INSTANCE.yaml schema diverges from LOGIC.md (after a major LOGIC
  version bump) → migrate per LOGIC.md MIGRATION section.
- Mission scope has changed dramatically → run a fresh `PLAN` cycle;
  CAMPAIGN_PLAN.yaml will be overwritten.

Never restart just because a cycle BLOCKED. Investigate the
`reason_code`; that's exactly what the BLOCK is for.

---

End of README.
