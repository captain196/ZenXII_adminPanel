# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

**This is the ZenXii admin panel — the largest of five surfaces of the ZenXii ERP**, and the one that
also carries the Cloud Functions, the Firestore/Storage/RTDB rules, and the indexes that the Teacher
and Parent apps depend on. Full cross-system architecture and contracts live in
`/Users/yuggi/AndroidStudioProjects/CLAUDE.md`; the apps are at
`/Users/yuggi/AndroidStudioProjects/ZenXII_{Teacher,Parent}`.

Note the spelling: this repo is `Zennxii_adminPanel` (**double n**) even though the product is ZenXii.

## Commands

```bash
php -S localhost:8080            # from the repo root; .env APP_HOST + $_allowed_hosts expect this exact port
composer install
php -l application/controllers/Attendance.php     # lint every PHP file you touch, every time

vendor/bin/phpunit --testsuite Unit               # 155 tests
vendor/bin/phpunit --testsuite Unit --filter HomeworkRosterFailClosedTest
```
`--testsuite Unit` is **required** — a bare `phpunit` aborts because `tests/Integration` doesn't exist.

**Baseline: 4 failures + 27 skipped.** Not green, and not yours to fix in passing. The skips are
cross-repo tests that grep the Kotlin app source via a hardcoded Windows path
(`D:/Projects/SchoolSyncTeacher/...`) from another machine; `BackupTest::size_human_…` fails on a
`1 KB` vs `1.0 KB` format drift. Compare your change against this baseline.

```bash
cd firebase-rules/tests && npm test                             # Jest rules tests on the Firestore emulator
cd firebase-rules
firebase deploy --only firestore:rules   --project graderadmin
firebase deploy --only firestore:indexes --project graderadmin  # do early — index builds take time
firebase deploy --only storage           --project graderadmin
firebase deploy --only functions         --project graderadmin  # sources ../functions
```

## Structure

CodeIgniter 3.1.13. 140 controllers, ~40 view folders (one per module), a `Common_model` that most
controllers share, and the real logic in `application/libraries/`.

- `application/core/MY_Controller.php` — the spine. Sets `$this->school_id` (`SCH_XXXXXX`) and
  `$this->session_year` from the session + custom claims, exposes `$this->fs`
  (`libraries/Firestore_helper.php`: `get/set/update/delete/query/querySchool/querySchoolSession`),
  and provides `emit_push()`, security headers, session-freeze and period-lock guards, teacher
  assignment scoping, and `json_success()/json_error()`. `MY_Superadmin_Controller` is the SA variant.
- **Always scope by tenant *and* session** — use `querySchool()` / `querySchoolSession()`, never raw
  `query()`. A missing session filter is the most repeated bug in this codebase.
- `application/helpers/rbac_helper.php` — `has_permission($module, $level)` /
  `require_permission()`, graded `view | edit | manage`. Effective access =
  `union(staff_roles[]) + extra − denied`, catalogue in `schools.staffRoles`, mirrored to
  `functions/rbac_modules.json`. A legacy `_require_role()` name-gate still shadows RBAC in some
  controllers and blocks custom roles — prefer `has_permission`.
- `functions/` — Cloud Functions. `index.js` holds the `MARK_REGISTRY`: **the only push dispatcher**.
  To add push to a module, add a registry row and call `emit_push($mark, $dedupeKey, $fields)`; never
  add a second sender for an existing mark.
- `firebase-rules/` — `firestore.rules` (the real authorization boundary), `storage.rules`,
  `database.rules.json`, `firestore.indexes.json`, and `firebase.json` (which points functions at
  `../functions`).
- `scripts/` — one-off Node backfills/probes run with `firebase-admin`. They default to **dry-run**;
  writing needs `--commit` (some use `--apply` — check the script's own flag before running).
- `tools/`, `assets/`, `uploads/` — static assets and user files. Note the vhost blocks
  `DirectoryMatch tools/` in production (403), and the root `.htaccess` denies `*.json`, so
  `assets/data/*.json` needs its own `.htaccess`.

## Traps

- **CSRF blank page.** Every new `superadmin/*` (and some `fee_management/*`) AJAX POST must be added
  to `csrf_exclude_uris` in `application/config/config.php`, or CI's global CSRF rejects it with a
  blank 403 — no log, no console error.
- **Phantom success.** `fetch()` doesn't reject on 403/500. Panel JS must check `r.ok` **and**
  `{status: 'error'}` before rendering success, or a denied action reports as done. Fail closed.
- **`firestore.rules` is edited concurrently by other sessions.** Re-read it right before editing,
  keep the change inside one `match` block, and `git diff` before deploying — a deploy ships the whole
  file including anyone else's half-finished work.
- **Claims must be dual-emitted:** `school_id` (snake, read by rules) *and* `schoolId` (camel, read by
  admin login). One without the other = silent `PERMISSION_DENIED`. Claim changes need a re-login.
- **Table misaligned?** Check for a CSS class colliding with a `display:grid` card utility before
  debugging the table itself.
- **Not in git, present only on the server:** `.env`, the Firebase service-account JSON,
  the production `.htaccess`, and `uploads/`. `PATH_A_US_SERVER_RUNBOOK.md` lists them.

## Deploy

Work branch is `yug_testing`; **production runs `yug_b1_t`** on the Ohio Lightsail box —
`us-east-2`, `3.138.59.194`, `admin@`, `~/.ssh/ohio.pem`, `/opt/zenxii` — deployed by `git pull`
there. That is the only live server. `DEPLOY_RUNBOOK.md` predates the move and quotes an older
address; its ordering (backfills → indexes → rules + code) is still correct, its host details are not.

A plain `git pull` on the server aborts on tracked-but-dirty files: set `core.fileMode=false`, clear
blockers, and keep `.htaccess`/`.env` skip-worktree. Rules, indexes and functions deploy **separately**
from the PHP code — shipping code that needs a new index without the index is a guaranteed break.

**Never commit, push, or deploy without explicit permission for that specific change.** Work locally,
let the user verify on `localhost:8080`, leave the change UNCOMMITTED / DEPLOY-PENDING and say so.

## Where the answers already are

`BUG_LEDGER.md` (regression memory), `FINAL_BLUEPRINT.md`, `PROJECT_STATUS.md`, the
`QUALITY_HARDENING_AUTOPILOT_V*` docs, and — in the apps' parent folder
`/Users/yuggi/AndroidStudioProjects` — the per-module `*_MODULE_INVESTIGATION_AND_UAT.md` reports.
Most modules have already been audited; check before re-investigating.
