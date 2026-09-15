# Constraints the implementation must honour

From this repo's own regression memory (`BUG_LEDGER.md`) plus checks I ran directly. Written before
implementing, so the merge does not reintroduce a bug class the file already fixed elsewhere.

## 1 · The two doors can clobber each other — VERIFIED, and it is BUG-028's class

`BUG-028` records read-modify-write on the school doc **without lock or CAS**, and establishes the
canonical fix in this very file. `BUG-037` carries the unfixed remainder (`save_classes`).

**Both writers of the issuer keys are blind. Checked directly:**

| writer | line | `_config_lock_acquire` | `__updateTime` precondition |
|---|---|---|---|
| `save_profile()` | 405 | **0** | **0** |
| `save_issuer_identity()` | 506 | **0** | **0** |

Both end in a plain `$this->fs->update('schools', $this->school_id, $doc)`.

**And `save_issuer_identity()` is a read-modify-write with a real TOCTOU window.** It reads
`$existing`, derives `$claimMoved` by comparing the stored `affiliationBoard`/`affiliationNo`
against the incoming ones, clears `verification` if the claim moved, then computes and stores
`issuerIdentity.level` from `array_merge($existing, $fields, …)` — and only then writes.

**So two failures are live today, before any change of mine:**

1. **Lost update.** `save_profile` writes `affiliation_board` / `affiliation_no` into the same
   `affiliationBoard` / `affiliationNo` keys the Issuer tab validated. Whichever tab saves last wins,
   silently — and the Profile door does no validation at all.
2. **A stored `level` that can disagree with the stored claim.** If the profile writes a new
   `affiliationNo` between the issuer tab's read and its write, the persisted
   `issuerIdentity.level` was computed against a value no longer in the document — a compliance
   state derived from data that has already been replaced.

**Binding consequence.** The merge does a read-modify-write on exactly these keys. It **must** use the
canonical pattern already present at 7+ sites in this file:

```
_config_lock_acquire('<name>')  →  firestoreGet capturing __updateTime
                               →  mutate
                               →  firestoreCommitBatch with
                                  $ops[0]['precondition'] = ['updateTime' => $updateTime]
                               →  ACC_* log on CAS-fail   →  finally release
```

Canonical references: `delete_stream`, `add_session`, `seed_streams`, `save_stream`. Shipping the
merge as another blind write would reintroduce BUG-028 on a **compliance-critical** field, which is
worse than where it was first found.

## 2 · Every config mutation must emit an audit line — BUG-026

`log_audit('Configuration', '<verb>', <entity>, "<description>")` at the success path. `save_profile`
is one of the six canonical sites, so the merged path **must keep emitting** — a governance auditor
has to be able to reconstruct who changed an issuer identity and when, from `audit_logs` alone.
**Check whether `save_issuer_identity` emits one at all; if it does not, that is a BUG-026 omission
on a field that decides whether certificates may be issued.**

## 3 · No raw exception text to the client — BUG-027

Six sites were fixed from `json_error('Failed to X: ' . $e->getMessage())` to a generic message, with
the full exception kept server-side via `log_message`. Anything I add follows that: generic to the
user, detail to the log.

## 4 · One field, three different length limits — found while checking the above

The same stored value is bounded three different ways:

| where | limit |
|---|---|
| Profile view `pf_affiliation_no` | `maxlength=60` |
| Profile controller `save_profile` | **100** |
| Issuer view `ii_affiliation_no` | `maxlength=40` |

So a 70-character value is accepted by the Profile door and then **cannot be edited** through the
Issuer door without truncation. Merging the doors has to settle on one limit — and the board-pattern
validation makes 40 more than sufficient.

## 5 · Aegis cannot compute this module's blast radius — a gap, not a failure

`node aegis/cli.js doctor` is nominal (all five surfaces resolve, 25 modules, 12 contracts, graph
integrity OK) and `verify` passes. **But the manifest has no `documents`/`certificates` module** —
the Document Engine post-dates it. The nearest neighbour is `exam` (blast radius 6 modules across 3
surfaces), which is genuinely adjacent because result cards print `Aff. No.`

**So the wiring had to be established by reading, not by asking Aegis.** Adding a module entry to the
manifest is worth doing, but it lives on the `aegis` branch
(`~/Desktop/zenxii-aegis`, a separate worktree) and is not part of this change.
