# Release Integration Phase 1 — Water-Tight Runbook

**Branch:** `integration/phase1` (8 commits ahead of `ank-yug_b1`, which is untouched)
**Rollback anchors:** branch `backup/pre-integration-2026-08-06` · tag `pre-integration-phase1`
**Principle:** prove on a copy → automate the evidence → roll out reversibly → never touch prod blind.

Execute the gates **in order**. A gate's STOP criterion halts the release; do not proceed on any Critical/High.

---

## Pre-flight — already cleared in-repo ✅
- 7 merge conflicts resolved; **95 PHP files pass `php -l`**; `firestore.indexes.json` valid (251 indexes); `firestore.rules` braces balanced (377=377).
- **CSRF:** every app Bearer endpoint is in `csrf_exclude_uris` (`parent_create_order`, `parent_verify_payment`, `payment_webhook`, `auth/*`, `staff_attendance/*`, `attendance/save`, `attendance/correction/submit`).
- **Play Integrity:** `INERT BY DEFAULT` (enabled=false, fail-open, not wired) → no effect on apps.
- Payroll Engine intact; attendance→pay wired; **finalise-gate** added to `generate_payroll`.

---

## Gate 5 — Rules & Indexes (emulator → staging)

### 5a. Emulator rules tests (ZERO prod risk) — MUST be green
```bash
cd firebase-rules/tests
npm install
npm test           # firebase emulators:exec --only firestore --project=zenxii-rules-test "jest --runInBand --forceExit"
```
Includes the 2 new transport tests **and** the new `staff_attendance_summary_sec.test.js` (payroll-source rule).
**PASS:** all suites green. **STOP:** any red → fix rules before any deploy.

### 5b. Set deploy aliases so a deploy can NEVER hit the wrong project
Create `.firebaserc` at repo root (NOT committed with real ids unless you intend it):
```json
{
  "projects": {
    "staging": "REPLACE_WITH_STAGING_PROJECT_ID",
    "prod": "graderadmin"
  }
}
```
> Verify `prod` against your live console before trusting it. If you have no staging project, create one (Firebase console) and seed 1 test tenant — never validate on prod.

### 5c. Diff the ruleset — review only the delta
```bash
# what does the merged ruleset change vs what's live on prod?
git show ank-yug_b1:firebase-rules/firestore.rules > /tmp/rules.pre
diff /tmp/rules.pre firebase-rules/firestore.rules | less
```
Eyeball ONLY the delta (not 335 rules). Confirm no collection was widened unintentionally.

### 5d. Deploy — indexes FIRST (additive), rules SECOND (versioned)
```bash
firebase deploy --only firestore:indexes --project staging   # async build, non-breaking
# wait for index builds to finish (console), then:
firebase deploy --only firestore:rules  --project staging
```
**PASS:** deploy clean, indexes built, staging app reads/writes OK.
**ROLLBACK:** rules → Firebase console → Firestore → Rules → version history → publish previous. Indexes are additive (no rollback needed).

---

## Gate 6 — Cross-system (Parent + Teacher, against STAGING)
Point the **debug** builds at the staging backend (Parent: `BuildConfig.BASE_URL`; Teacher: same). Then, per app:
1. **Login** succeeds.
2. **One read** (Parent: fee list / dashboard; Teacher: dashboard / attendance).
3. **One write** (Parent: `parent_create_order` reaches Razorpay; Teacher: `attendance/save`).

Watch staging logs for **HTTP 401/403 spikes** during the test — a spike means a missed CSRF/auth exemption. None expected (pre-cleared).
**STOP:** any app login/read/write failure that isn't config (URL/keys).

---

## Gate 9 — Regression (automated + differential)

### 9a. Automated verifier suite — gate on zero FAIL
```bash
php scripts/verify_integration.php --school=<STAGING_TENANT>
```
Runs the read-only self-tests (RBAC `deptrole_selftest`, health, events/gallery, timetables, + candidates). Exit 0 = all PASS.
**STOP:** exit 1 (a FAIL) blocks the release. Exit 2 = a check couldn't run → confirm its CLI and re-run.

### 9b. Differential validation — same tenant, prod-code vs integration-code
```bash
git checkout ank-yug_b1 && php scripts/diff_snapshot.php --school=<T> --out=/tmp/snap_pre
git checkout integration/phase1 && php scripts/diff_snapshot.php --school=<T> --out=/tmp/snap_post
php scripts/diff_snapshot.php --diff /tmp/snap_pre /tmp/snap_post
```
**PASS:** only INTENDED deltas (payroll finalise-gate, RBAC role labels). Any unexplained delta in a ledger/payslip/fee-demand → investigate.

### 9c. Targeted manual UAT (only what can't be automated)
- **Payroll:** run for a PAST month → finalise-gate lets it through; run for the CURRENT month → blocked with the clear message. Verify one payslip's pay = attendance-driven (LWP deducts, paid leave doesn't).
- Use `RBAC_ACCEPTANCE_TEST_CHECKLIST.md` + `FEES_UAT_STU0001_PRODUCTION_CHECKLIST.md`.

### 9d. Two tracked follow-ups
- **Driver `user_id` claim:** create a staff (driver) → inspect the minted Firebase custom claims → confirm `user_id` present (Transport rules need it). Staff.php took incoming; incoming emits `user_id` in its claim paths — verify it covers the driver path.
- **Semantic rules review** on staging (auto-merge is syntactically clean; security needs eyes).

---

## Canary → Production
1. Staging green on all gates → deploy integration to **ONE real tenant** (canary); soak **48h**.
2. Monitor: error logs, 401/403 rate, payroll runs, tenant-isolation, no new `log_message('error')` spikes.
3. **Go/No-Go:** zero Critical/High → `git checkout ank-yug_b1 && git merge --no-ff integration/phase1` → deploy to all.

## Rollback (any Critical/High, any time)
- **Code:** `git revert` the merge on `ank-yug_b1` (or redeploy `backup/pre-integration-2026-08-06`).
- **Rules:** Firebase console rules version rollback.
- **Indexes:** additive — no rollback needed.
- App builds unaffected (they hit the same URLs; only backend behavior changed).

## Go/No-Go checklist (all must be ✅)
- [ ] Gate 5a emulator tests green
- [ ] Gate 5c ruleset delta reviewed
- [ ] Gate 5d staging deploy clean
- [ ] Gate 6 Parent+Teacher login/read/write on staging
- [ ] Gate 9a verifier suite exit 0
- [ ] Gate 9b differential shows only intended deltas
- [ ] Gate 9c payroll finalise-gate + past-month payslip correct
- [ ] Gate 9d driver `user_id` claim confirmed
- [ ] Canary 48h clean
