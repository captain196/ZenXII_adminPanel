# 🚀 Runbook: promote `ank-yug_b1` → live `yug_b1_t`

**Project:** `graderadmin` (single shared Firebase backend)
**Server:** Lightsail `/opt/zenxii`, branch `yug_b1_t`, IP `65.0.240.198`
**Current live SHA:** `5408bd0` (rollback anchor) · **Target SHA:** `b9aea6a`
**Scale:** 159 commits, 226 files, +63.7k / −11.7k

> Confirmed answers: backend deploys/backfills NOT yet run · server file-protection
> state unknown · live Teacher/Parent apps already on Firestore · plan-only (execute
> each phase on explicit go-ahead).

## ✅ Phase-0 audit results (2026-06-28, read-only)
- **SSH:** `admin@65.0.240.198` with `~/.ssh/lightsail_zenxii.pem` (NOT `ubuntu`/`bitnami`).
- **Server app:** `/opt/zenxii` on `yug_b1_t @ 5408bd0` — matches rollback anchor. ✅
- **skip-worktree:** ONLY `.env` is protected (`S`). `.htaccess` is **NOT** protected.
- **Prod Firebase SA JSON present:** `application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json` (creds for backfills live ON the server).
- **Local tooling:** firebase CLI 14.16 authed (sees graderadmin) ✅ · node v22 ✅ · **gcloud NOT installed** (Firestore backup must use Console export or install gcloud).

## ⛔ BLOCKER: server `git pull` will ABORT (dirty tracked files)
The server working tree is dirty, and these dirty files are ALSO changed by the
incoming commits → `git pull` fails with "local changes would be overwritten":
- `.htaccess`  (prod RewriteBase + CSP/HSTS hardening — must preserve)
- `application/cache/dashboard/grader_dash_v1_SCH_D94FE8F7AD_*.json` (5 files)
- `application/cache/firebase_auth/oauth_token.json`

Cache files are **tracked** (not gitignored) — repo hygiene debt. Server-modified
`README.md` + `Firebase_Architecture_Summary.html` are NOT in the incoming diff, so
the pull won't touch them (leave as-is). Handling baked into Phase 3/4 below.

## Ordering principle
Backend data must exist **before** the restrictive rules and new code go live.
So: **backfills → indexes → (window) rules + code**. Backfills and indexes are
zero-downtime; rules + code are the risky switch and want a maintenance window.

Key fact: new `firestore.rules` are **more restrictive** — they add a
`tenantActive(school_id)` gate to most reads/writes, tighten some
`allow write: isAdmin()` → `isSuperAdmin()`, and turn a public
`read: if request.auth == null` into `read: if isAuth()`. They are coupled to the
backfills (tenant-active state + canonical claims must exist first) and to
token-refresh timing. Do NOT deploy rules early in isolation.

All three backfill scripts default to **DRY-RUN** (write nothing until `--apply`).

---

## Phase 0 — Pre-flight (no downtime, anytime)
1. **Firestore backup:** `gcloud firestore export gs://<bucket>/pre-yugb1t-$(date +%F)`
   (Console → export also fine). Only thing that makes the data side reversible.
2. **Confirm service-account creds** for the backfill scripts (firebase-admin → `graderadmin`). Note SA JSON path.
3. **Inventory deployed rules** for rollback: `firebase firestore:rules get` (or keep in-repo `firestore.rules.pre_h1_backup`).
4. **Server file-protection audit:**
   ```
   ssh ubuntu@65.0.240.198
   cd /opt/zenxii && git ls-files -v | grep '^S'   # S = skip-worktree
   ```
   Confirm `.env`, `.htaccess`, Firebase JSON show `S`. If `.htaccess` is NOT
   skip-worktree, back it up (`cp .htaccess .htaccess.prod.bak`) — incoming
   version adds caching headers and could clobber prod RewriteBase + CSP/HSTS.

## ✅ Phase-1 DRY-RUN verdict (2026-06-28, zero writes) — backend mostly migrated already
Ran locally (firebase-admin **v12** — v14 breaks `admin.credential.cert`; SA JSON
scp'd to gitignored path, removed after). Real writes remaining are tiny:
- **b2_schools:** 11 RTDB keys, **0 unresolved**, 1 parity, 9 `_claim` skipped →
  **1 gap-fill** (`SCH_D94FE8F7AD` IIT Kanpur: adds `adminDisabled`, `statsCache`, …).
  ⇒ `tenantActive()` rule risk effectively gone.
- **superAdmins:** SUP0001+SUP0002 already migrated → **NO-OP**. SA login dependency satisfied.
- **admin provisioning:** 13/13 admins already in Firebase Auth → **NO-OP**.
- **admin_claims:** 9 canonical, **4 additive** camelCase writes (`SSA0008–0011`), 0 failed.
  Rules' required snake_case claims (`school_id`,`role`) already present on all; the
  4 writes only add camelCase aliases for new code → those 4 SSAs need a **re-login**.

Net remaining backfill at window: **1 school doc + 4 claim top-ups** (both additive).
Flags: b2_schools=`--commit`; superAdmins=`--apply`; admin_claims needs
`WAVE_C_BACKFILL_CONFIRM=YES_I_AUTHORIZE node scripts/admin_claims_backfill_c0.js apply`
(and a fresh `admin_inventory_c0.js` snapshot first).

## Phase 1 — Backfills (no downtime; old live code unaffected)
Write flag is **`--commit`** (NOT `--apply`); dry-run is the default. Scripts load
SA creds from relative `../application/config/graderadmin-firebase-adminsdk-…json`.
⚠️ **Server has NO Node.js** → run these locally (npm i firebase-admin + copy SA
JSON) OR install Node on the server first. Order matters:
1. `node scripts/sa_b2_schools_backfill.js` → review → `--commit`
   (populates `schools/{id}` profile + tenant/adminDisabled lifecycle that `tenantActive()` reads).
2. `node scripts/sa_backfill_superadmins_a1.js` → review → `--commit`
   (superAdmins docs — SA login depends on these once `sa.authz_firestore_strict` ships ON).
3. `node scripts/admin_claims_backfill_c0.js` → review → commit (explicit confirm env var)
   (additive canonical claims; preserves snake_case).
4. ⚠️ Custom-claim changes only reach a client after ID-token refresh (re-login).
   Advise admins to re-login post-deploy.

Phase-0 note: each script also reads RTDB. Project list shows a separate
`database-23930` project — confirm whether `System/Schools` RTDB lives there vs
`graderadmin` (scripts accept `RTDB_URL=...` override).

## Phase 2 — Indexes (no downtime, do early — they take time)
```
cd firebase-rules && firebase deploy --only firestore:indexes --project graderadmin
```
Wait until ALL indexes show "Enabled" before Phase 4. Extra indexes are harmless to old code.

## Phase 3 — Server prep (no downtime) — REQUIRED to unblock the pull
On `admin@65.0.240.198:/opt/zenxii`:
1. **Back up + protect `.htaccess`:**
   ```
   cp .htaccess .htaccess.prod.bak
   git update-index --skip-worktree .htaccess
   ```
   (skip-worktree makes pull ignore it; we keep the prod version. `.env` already S.)
2. **Discard dirty tracked runtime cache** so the tree is clean for pull (these regenerate):
   ```
   git checkout -- application/cache/
   ```
   (oauth_token.json discard just forces a re-auth refresh — harmless.)
3. Leave `README.md` / `Firebase_Architecture_Summary.html` (not in incoming diff).
4. `git fetch origin` then `git diff yug_b1_t origin/ank-yug_b1 --stat` to eyeball.
5. **Repo-hygiene follow-up (post-deploy):** add `application/cache/` to `.gitignore`
   + `git rm -r --cached application/cache/` so this never blocks a deploy again.

## Phase 4 — Deploy window (⏸️ maintenance / low-traffic)
1. Push FF: `git push origin ank-yug_b1:yug_b1_t`
2. Deploy rules: `firebase deploy --only firestore:rules,storage:rules --project graderadmin`
3. Pull on server: `cd /opt/zenxii && git pull origin yug_b1_t`
4. Restore `.htaccess` if touched (re-apply prod RewriteBase + hardening).
5. Cloud Functions: if `../functions` (codebase `notice-circular-push`) changed,
   `firebase deploy --only functions`. (Lives outside this repo — verify.)
6. Clear opcache/CI cache if applicable.

## Phase 5 — Smoke tests (priority order)
- [ ] SA login (SUP0001) — `sa.authz_firestore_strict` path.
- [ ] School-admin login + **Login Activity** tab (needs `security_events` index).
- [ ] Fees: save structure → demands generate (no 500).
- [ ] Exam → report card (logo fallback, PASS/FAIL vs PROMOTED).
- [ ] Communication / circular send.
- [ ] Stories create/view.
- [ ] Spot-check a logo/file (storage path scheme).

## Phase 6 — Rollback / kill-switches (fastest → fullest)
1. **Flag kill-switches (instant, no redeploy):** flip OFF `b2.*`, `sa.*`, `stream_b_*`
   on server (comments: "false = instant revert to RTDB"). Does NOT cover the
   unconditional comm-helper RTDB retirement — but apps are on Firestore, so fine.
2. **Rules rollback:** redeploy `firestore.rules.pre_h1_backup` / saved copy.
3. **Code rollback:** `git push -f origin 5408bd0:yug_b1_t` → reset --hard + pull on server.
4. **Data rollback:** restore Phase-0 Firestore export (last resort; backfills are merge/additive).

## Biggest risks, ranked
1. Restrictive rules + `tenantActive()` — if b2_schools backfill misses any live
   school, reads/writes denied site-wide. Verify backfill report covers all schools.
2. Claim-refresh lag — logged-in users may hit denials until re-login.
3. SA login depends on superAdmins backfill — test immediately.
4. `.htaccess` clobber — skip-worktree + backup.
