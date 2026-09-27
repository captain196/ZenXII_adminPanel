# M5 — FIRESTORE RULES + INDEXES RECONCILIATION

## FINAL STATUS: **STOP — NO CHANGE REQUIRED, AND COMMITTING WOULD SWEEP CONCURRENT WORK**

Nothing edited, staged, committed or pushed. 2026-08-17

---

## 1. The headline

**M5 needs no rules change and no index change.** The current configuration is already correct — and
it is *safer* than anything I would have added. Adding rules now would weaken security for no
consumer, which your own §8 forbids: *"If the current architecture intentionally keeps registry access
denied from clients, preserve that."*

Separately, and independently: **the rules file cannot be committed in isolation right now**, because
it carries 831 uncommitted lines of someone else's HRMS/Staff-Lifecycle security work.

## 2. Git state (Step 0)

| | |
| --- | --- |
| Branch | `integration/phase2` — your working branch, as clarified |
| HEAD | `758c9244b6b41e1c6e1fcc059bbd66a85cfee5b4` |
| Upstream | **none configured** |
| `origin/integration/phase2` | **does not exist** — remote has only `ank-yug_b1`, `main`, `yug_testing` |
| Ahead of `origin/ank-yug_b1` | **48 commits** · ahead of `origin/main`: **254** |
| Uncommitted files | **677** |
| Already staged **by another session** | `Admin_login.php`, `deptrole_helper.php`, `rbac_helper.php` |

**Three-way reconciliation is vacuous here.** With no remote copy of these files on this branch, there
is no "remote version" and no common ancestor to merge from — **the local file is the current state.**
Nothing to reconcile, and nothing at risk of being overwritten. `git fetch --prune` was run; no
remote branch appeared.

## 3. Why no rules change is required

**The rules file already denies `feeHeadRegistry` to every client.** It ends with an explicit
catch-all:

```
match /{document=**} {
  allow read, write: if false;
}
```

`feeHeadRegistry` appears **0 times** in the rules, so it falls through to that deny. And every access
in the codebase is **server-side**:

| Access | Mechanism |
| --- | --- |
| `Fee_head_resolver::resolve()` | `fs->get()` — document read, admin SDK |
| `Fee_head_resolver::resolveByName()` | `fs->get()` on the name claim — admin SDK |
| `Fee_firestore_sync` | `firestoreQuery('feeHeadRegistry', …)` — admin SDK |
| Parent app / Teacher app | **no reference to `feeHeadRegistry` at all** |

Admin SDK calls bypass rules. So the registry works server-side today, is unreachable from any client,
and **no client needs it** — there is no registry UI yet.

**I deliberately did not confuse "the server works" with "the client is secure" (§8).** I checked the
client side and found no consumer; deny-all is therefore both correct and the strongest posture. The
rules block becomes necessary only when a client-facing Fee Head Registry UI is built.

## 4. Why no index change is required

**A correction to my own earlier design.** I previously specified a `schoolId + status` composite
index. Tracing the implemented queries shows that is wrong:

- both resolver paths are **document `get()`s** — no query, no index;
- the one query, in `Fee_firestore_sync`, filters on **`schoolId` only** (single-field equality) and
  filters `status` in PHP.

Firestore serves single-field equality from its **automatic** index. **No composite index is
required**, and adding one would be the "manufactured index" your §10 warns against.
`feeHeadRegistry` appears **0 times** in `firestore.indexes.json` — correctly.

## 5. The commit blocker (independent of the above)

Even if a change were needed, it could not be committed narrowly today:

```
firebase-rules/firestore.rules        831 insertions   ← NOT mine
firebase-rules/firestore.indexes.json 532 insertions   ← NOT mine
```

Collections added by that uncommitted work: `payrollOverrides`, `salaryComponents`, `salaryGrades`,
`salaryStructureTemplates`, `staffEmploymentEvents`, `staffEmploymentTimeline`,
`staffSalaryAssignments`, `staffScheduleAssignments`, `statutoryConfig`, `campusAccessEvents`,
`campusAccessRequests`, `locations`, `mapsConfig`, `mapsSecrets`, `platformConfig`, `platformSecrets`,
`admissionNoIndex`, `apaarIndex`, `srNoIndex`.

That is concurrent HRMS/Staff-Lifecycle and Campus-Access security work. **Git stages whole files** —
committing `firestore.rules` would commit all 831 lines of it, unreviewed, under an M5 commit message.
Your §16 forbids exactly that, and splitting another developer's security hunks is precisely what §2
rules out.

## 6. Push assessment

I did not push. Two reasons beyond the above:

1. There is no upstream, so a push would **create** `origin/integration/phase2` and publish **48+
   commits** — the Phase 1+2 integration work your notes hold as *LOCAL ONLY, awaiting your test*.
   That is far outside "push the rules/index change".
2. Nothing needs pushing, since nothing needs changing (§3–§4).

## 7. Confirmations

- **No unrelated change committed** — nothing was committed at all.
- **`USE_FEE_HEAD_REGISTRY` remains `false`.**
- **No live financial data changed**; no demand, receipt or balance touched.
- Rules digest **unchanged**; indexes digest **unchanged**; working tree otherwise untouched, including
  the 3 files another session had staged.
- No force-push, no reset, no clean, no branch created.

## 8. What this unblocks

**The UAT does not need this step.** Every registry read during UAT is server-side, so rules are not
consulted. [M5_UAT_PROCEDURE.md](M5_UAT_PROCEDURE.md) can proceed as written.

## 9. Exact next authorisation required

Pick one:

1. **Proceed to UAT now** — my recommendation. The rules/index step is genuinely not a prerequisite.
2. **Author the rules block anyway**, ahead of a future registry UI. I can write the
   `match /feeHeadRegistry/{doc}` block (same-school read; write restricted to Fees `manage`; name
   claims never client-deletable) — but it cannot be committed separately until the concurrent HRMS
   rules work is committed by its owner. Say the word and I'll write it and leave it uncommitted.
3. **Commit the concurrent HRMS rules work too** — needs that work's owner to confirm it is complete;
   I will not commit another session's security rules on my own judgement.

I'd take (1), and revisit rules when a registry UI actually exists.
