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

### Phase 2b — Firestore TTL policy for F13 breadcrumbs ⚠️ MANUAL, EASY TO FORGET

**Required by F13.1 (Live GPS). Not expressible in `firestore.rules` or
`firestore.indexes.json`, so `firebase deploy` will NEVER create it.** Deploying F13 without
this step is silent: everything works, and `vehicleBreadcrumbs` grows without bound at roughly
**86,000 documents per day per school** at 3,000 students.

```bash
gcloud firestore fields ttls update expires_at \
  --collection-group=vehicleBreadcrumbs \
  --enable-ttl \
  --project=graderadmin
# verify:
gcloud firestore fields ttls list --project=graderadmin
```
(Console equivalent: Firestore → *Time-to-live* → Create policy → collection group
`vehicleBreadcrumbs`, timestamp field `expires_at`.)

Two things that make this fail **silently** rather than loudly:

1. **TTL only acts on a field of Firestore type `Timestamp`.** An ISO-8601 *string* is ignored and
   the document never expires. Server writes go through
   `Transport_gps_service::breadcrumbExpiresAt()`, which emits the
   `__firestore_type => timestamp` sentinel that `FirestoreRestClient::encode()` turns into a
   native `timestampValue`; the Kotlin driver app must likewise write a `Timestamp`, never a
   `String`. Verifier gate `CH-TRN-F13-1` and probe `transport_probe_f13` (§E) both pin this.
2. **Deletion is asynchronous** — Google states documents are typically deleted within 24h of
   expiry, not at the instant. Do not treat a lingering expired doc as a defect.

TTL deletions **count as document deletes** for billing, but no more than a cron sweep would, and
they consume no read quota — which is why D-3 chose native TTL over a sweeper.

**Fallback (only if TTL is unavailable in the project):** decision D-3 option B — a bounded cron
sweep in `Transport_expiry_cron`. The composite index
`vehicleBreadcrumbs (schoolId, expires_at)` already ships for exactly this, so no index change is
needed to fall back.

### Phase 2c — Timetable projection cron ⚠️ MANUAL, EASY TO FORGET

**Required the moment any school publishes a Master Timetable. Not expressible
in `firestore.rules` or `firestore.indexes.json`, and `git pull` will never
create it.** Deploying without this is silent in the worst way: the office sees
the new timetable immediately and **every teacher and parent keeps seeing the
previous one, indefinitely**.

Why a scheduled task exists at all: the canonical resolver answers "which
timetable applies today" from the versions themselves, with no flag to flip.
The Teacher and Parent apps, however, read the legacy `timetables` collection
through the client SDK, and that collection is **materialised** — somebody has
to write today's answer into it. Publishing a timetable effective next Monday
is correct for the resolver instantly; the apps do not change until this runs.

**ONE entry for the whole platform, shortly after midnight** (before the school
day, so a version that becomes effective today is materialised in time):

```bash
# Linux crontab — 00:20 daily. ONE LINE, ALL SCHOOLS. No --schoolId.
20 0 * * * /usr/bin/php /opt/zenxii/scripts/run_scheduled.php --job=timetable \
  >> /var/log/zenxii_timetable.log 2>&1
```

```powershell
# Windows Task Scheduler equivalent
schtasks /Create /SC DAILY /ST 00:20 /TN "ZenX-Timetable" /TR ^
  "C:\xampp\php\php.exe C:\xampp\htdocs\ZenX\school\scripts\run_scheduled.php --job=timetable"
```

**The absence of `--schoolId` is the point.** ZenX is multi-tenant, so timetable
delivery cannot be a per-customer deployment task — one entry per school is a
step that silently fails the first time somebody forgets at onboarding, and the
symptom is a school whose teachers see the wrong timetable with no error
anywhere. The job discovers eligible tenants from the `schools` collection and
each one's session from its own `currentSession`, exactly as
`transport_analytics_rollup_job` already does. **Onboarding a school is the only
action needed to make it eligible.** Add `--schoolId`/`--session` only for a
targeted or recovery run.

**Route it through `run_scheduled.php`, not through `index.php` directly.**
The wrapper is the repository's existing convention for scheduled maintenance
(the same one `--job=reconcile` and `--job=integrity` use, see
`scripts/cron.txt`), and it is what makes a failure something other than a line
in a log nobody reads:

- every run lands in `scheduled_job_runs/{runId}` with status, exit code,
  duration and the last 50 lines of output;
- a non-zero exit raises an alert record, which dispatches to Slack/email when
  `feeSettings/{schoolId}_alerts` is configured.

Add `--dry-run` to prove a new entry is wired before letting it write.

Verified end to end on 2026-09-12 against the live tenant: run record written,
exit 0, correct resolution, nothing materialised.

**The school is no longer helpless without this.** Since 2026-09-12 the Master
Timetable screen detects that the apps are behind and offers **Send to teachers
and parents now** (`manage`, and only for the timetable in force *today* — the
legacy collection has one document per weekday and no date, so no other day is
representable). That covers the case that used to be silent: publishing a
future-dated timetable, which projects nothing on the day it is published.

The cron is still what you want in production, because the button only helps a
school that *opens the screen*. A timetable that becomes effective overnight
reaches the apps unattended only if this entry exists.

Safe to run repeatedly and safe to miss:

- **Idempotent** — measured: 36 documents written on the first run, **0** on the
  second. It writes only what actually differs.
- **Missed runs self-recover** — it asks *"what applies on this date"*, never
  *"what changed since last run"*. A run days late still projects the correct
  answer; there is no catch-up state to lose.
- **Fails closed** — if two published versions cover the date, or the versions
  cannot be read, it refuses and leaves what teachers currently see untouched.
- **`APPLY=1` materialises TODAY only.** `FOR_DATE` is for dry runs; applying
  another date would write a timetable that is over, or has not started, into a
  collection that has no date dimension.

Exit codes: `0` ran (including "nothing to project") · `1` env/init failure ·
`2` refused — **the apps may be stale, alert on this**.

### What to monitor

| Signal | Where | Means |
|---|---|---|
| No `scheduled_job_runs` row with `job=timetable` in the last 26h | Firestore | the entry is missing or the host is down — **the most likely failure, because it is the silent one** |
| `status=failed`, `exitCode=2` | `scheduled_job_runs` + alert | refused; nothing written, apps unchanged. Usually two published versions covering one day |
| `status=running` for more than a few minutes | `scheduled_job_runs` | hung; the wrapper writes `running` before it starts |
| `"are NOT seeing this timetable"` | Master Timetable screen, and the run transcript | the apps and the published timetable genuinely differ |

The transcript names the *kind* of difference, not only a count — whether class
pages are missing entirely, were never sent from a published timetable, are left
over from an earlier one, or were changed by something other than publishing.
That last one is the only state that implies a defect rather than a pending job.

**How to tell it is missing, without waiting for a complaint:** Health Check →
Academic → *"Teachers See The Published Timetable"* fails and prints the exact
command, and the Master Timetable screen says *"Teachers and parents are still
seeing a different timetable"* under **In force**. Both are read-only, and both
compare every class page rather than asking whether a command completed.
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
