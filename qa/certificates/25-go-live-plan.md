# GO-LIVE PLAN — Documents module · drafted 2026-09-04

Nothing here has been executed. Every step that touches production is marked and needs
explicit approval; approval for one does not carry to the next.

---

## Phase 0 · Two things that block a clean commit

**0.1 · A foreign file is in the working tree.**
`functions/studentAssistant.js` — **+112 / −16**, not mine; I never touched Cloud Functions.
It belongs to another session. It must be **excluded** from my commit, not swept in.
→ *Decision needed: leave it uncommitted, or is it yours to commit separately?*

**0.2 · `yug_b1_t` is not present locally.** The deploy branch has to be fetched before
anything can be rebased onto it. Until then I cannot tell you whether the branch is behind,
or whether a colleague has deployed something I would overwrite.

---

## Phase 1 · Commit to `yug_testing` — safe, reversible, no production impact

Current branch is `yug_testing` — correct. `yug_b1_t` is the live branch and is not touched.

Suggested split, so a revert can be surgical:

| Commit | Contents |
|---|---|
| 1 | **Security + integrity fixes** — save() allowlist, proof_pdf guard, atomic publish, CAS, CSS-injection fix, geometry bounds, archived-reactivate guard |
| 2 | **Fee Receipt + custom document types** |
| 3 | **Seeding** — `Doc_seeder`, generated `doc_starters.php`, `tools/gen_doc_starters.js` |
| 4 | **RBAC wiring + sidebar + "Documents" naming + Archivo font** |
| 5 | **UX fixes** — card redesign, archive UI, error banner, modal focus, keyboard guard, layout coalescing, list projection |
| 6 | **Legacy retirement** — the deletions and the tombstone |
| 7 | **QA workspace** — `qa/**` (documentation only, zero runtime effect) |

**Gate:** 580 tests · 4 failures · 27 skipped — the standing baseline. Verified now.

---

## Phase 2 · THE GATE — human UAT · **this is what actually blocks go-live**

**178 rows, all `NOT TESTED`.** Everything I have done is E2 analysis plus my own
instrumented runs. That is not certification, and I will not describe it as such.

Minimum before production, in order:

1. **T0 · 30 rows.** If T0 fails, nothing else matters.
2. **A `view`-grade and an `edit`-grade login.** Every permission row is currently
   unexecuted — the entire session ran as `manage`. This is the single largest hole.
3. **T1 core journeys** — 56 rows.

Rows already answered by evidence gathered this session (recorded, not re-runnable by you):
tenant reads, the state-gate bypass, archive/reactivate, the modal key bleed, CAS refusal.

---

## Phase 3 · Deploy the code — **needs explicit approval**

Per `DEPLOY_RUNBOOK.md` and CLAUDE.md. **My changes need no index, rules or Functions
deploy** — verified: zero new composite indexes (the seeder query is single-field
`schoolId ==`), `firestore.rules` and `firestore.indexes.json` untouched.

```
1  git fetch, confirm it actually succeeded
2  rebase yug_testing onto yug_b1_t; resolve honestly, do not force
3  re-run the suite against baseline AFTER the rebase
4  secret scan  (run now: clean)
5  push yug_testing → yug_b1_t
6  on the Ohio box (us-east-2, 3.138.59.194, admin@, /opt/zenxii): git pull
```

**Deletions reach production in this step.** `Certificates.php` and its view disappear from
the live server. Safe — zero certificates were ever issued — but it is a real removal, and
any bookmark to `/certificates` will 404 from that moment.

**No cache-busting action needed:** `designer.js` and the CSS are versioned by `filemtime`,
so the new build is picked up on first load.

---

## Phase 4 · The RTDB P0 — **separate, and more urgent than this module**

Independent of everything above. Live root rule is `.read/.write: auth != null` with no
`Schools` block, exposing `Users/{Admin,Parents,Devices}`, `User_ids_pno` (108), `Exits`
(108), ~90 raw phone numbers for one school, and per-session `Accounts` — cross-tenant,
read **and write**, to any authenticated user of the shared project.

1. Draft scoped rules → **review by you** → deploy (`firebase deploy --only database`)
2. Delete `firebase-rules/database.rules.json.rollback` (`.read/.write: true`, no auth)
3. Build an RTDB sentinel, or migrate the remaining RTDB data to Firestore

**This should ship before or alongside the module, not after.** It is not a Documents
problem; retiring the certificate controller did not touch it.

---

## Phase 5 · After go-live

- Wire issuance: `Doc_resolver::activeVersion()` + `Sis::_get_tc_number()`'s claim-doc CAS
  + a stored PDF artefact. Both halves exist and are sound; they have never been joined.
- `create()`'s id-numbering TOCTOU (UR-5) — recorded, not fixed.
- Gallery sort/filter/pagination at scale (A10) — 85 templates on one school today.
- Presence has no hidden-tab stop; proof PDFs and assets are never cleaned up.

---

## Honest status

| | |
|---|---|
| Code | every P0 and code-fixable P1/P2 from the certification is closed |
| Tests | 580 · 4 failures · 27 skipped — baseline |
| Regression | browser harness 159/179, the same 20 pre-existing failures, zero new |
| **Certification** | **not certified — no UAT row has been executed by a human** |
| Production P0 | **open** — RTDB rules, live now |
