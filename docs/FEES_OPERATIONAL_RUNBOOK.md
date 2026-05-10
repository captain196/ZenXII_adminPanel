# 📕 FEES MODULE OPERATIONAL RUNBOOK

**Version 1.0 — 2026-05-08 — Pre-go-live cohort**

---

## 0. SCOPE & CURRENT BASELINE

### What this runbook covers
Operational procedures for the Fees module across:
- Admin Panel (CodeIgniter PHP)
- Parent App (Android Kotlin)
- Teacher App (Android Kotlin)
- Razorpay payment gateway
- Firestore + Cloud Functions

### Verified baseline as of 2026-05-08
| Phase | Status | Notes |
|---|---|---|
| 1 | ✅ Live | Audit logger fix, audit collection canonicalization, write-lock |
| 1.5 | ✅ Live | `demandsForStudent` defect restoration |
| 2A | ✅ Live | Parent schoolCode→schoolId, session filter |
| 2B | ✅ Live | feeAuditLogs Shape A unification |
| 2D | ✅ Live | Teacher feeBreakdown contract correction |
| 3F | ✅ Live | Webhook config drift assertion (24h cool window in progress) |
| 3A | 🟡 Deploy-deferred | Operational sweep — pending Blaze |
| 3B | 🟡 Deploy-deferred | Atomic CF claim — pending Blaze |

### Known-historical baseline (acknowledged, not blocking)
- **STU0004 + STU0005**: `feeDefaulters.totalDues=0` despite ~₹34,600 unpaid demands each. Stale denormalized convenience copies. Canonical `feeDemands` correct. Parent UI unaffected. Will be auto-corrected by Phase 3D scheduled recompute post-Blaze.

### Critical contacts & escalation
| Role | Contact | When to escalate |
|---|---|---|
| Primary developer | Shivam Rathore (admin_id=SSA0001) | Any production blocker; rollback decision |
| Firebase project | `graderadmin` | Console: https://console.firebase.google.com/project/graderadmin |
| Working dirs | Admin: `C:\xampp\htdocs\Grader\school` / Parent: `D:\Projects\SchoolSyncParent` / Teacher: `D:\Projects\SchoolSyncTeacher` | All operations originate here |

---

## 1. DAILY OPERATIONAL CHECKS

**Run every morning before opening admin operations. ~5 minutes.**

### 1.1 PHP error log scan
```powershell
$log = "C:\xampp\htdocs\Grader\school\application\logs\log-$(Get-Date -Format 'yyyy-MM-dd').php"
Select-String -Path $log -Pattern "FEE_AUDIT_FAIL|CONFIG DRIFT|FATAL|Undefined property: Fee_refund_service"
```
**Expected**: zero matches.
**If any match**: see Section 4 (CONFIG DRIFT) or Section 5 (payment anomaly).

### 1.2 Stuck-state spot check (Firestore Console)
1. Open Firebase Console → Firestore Database
2. Query `feeRefunds` filter `status == "processing"` → expect **0 docs**
3. Query `fee_generation_jobs` filter `status == "running"` → expect **0 docs**
4. Open `feeLocks` collection → expect **0 docs** (or only docs <300s old)
5. Open `feePendingWrites` collection → expect **0 docs** (or only docs <600s old)

**If any non-zero**: see Section 3 (stuck refund) or Section 9.5 (CF job recovery).

### 1.3 Recent webhook activity (only if Razorpay traffic occurred)
```powershell
Select-String -Path $log -Pattern "payment_webhook" | Select-Object -Last 20
```
Look for: `IP BLOCKED`, `REPLAY REJECTED`, `HMAC FAILED`, `CONFIG DRIFT`. All zero-tolerance.

### 1.4 Audit trail growth
```powershell
node "C:\xampp\htdocs\Grader\school\scripts\count_collections_phase1.js" 2>&1 | Select-String "feeAuditLogs"
```
**Expected**: monotonically increasing with admin activity. If flat across days when activity occurred → audit logger may be silently broken.

---

## 2. WEEKLY PROCEDURES

**Run every Monday morning. ~15 minutes.**

### 2.1 Baseline integrity check
```powershell
cd C:\xampp\htdocs\Grader\school
php scripts\fee_integrity_check.php --schoolId=SCH_D94FE8F7AD --session=2026-27 2>&1 | tee snapshots\integrity_$(Get-Date -Format 'yyyy-MM-dd').log
```
**Expected output** (current baseline):
```
findings: {"I-1":0,"I-2":2,"I-3":0,"I-4":0}
```
- I-1, I-3, I-4 must remain **0**
- I-2 = **2** is acknowledged baseline (STU0004 + STU0005)
- I-2 > 2 = new defaulter drift surfaced; investigate at Section 6

### 2.2 Collection-count delta from previous week
```powershell
node scripts\count_collections_phase1.js | Out-File snapshots\counts_$(Get-Date -Format 'yyyy-MM-dd').txt
fc snapshots\counts_PREVIOUS_DATE.txt snapshots\counts_$(Get-Date -Format 'yyyy-MM-dd').txt
```
Verify:
- `feeReceipts` grew with paid fees
- `feeDemands` flat unless new fee-generation jobs ran
- `feeAuditLogs` grew (audit activity)
- `fee_audit_logs` (snake) flat at **17** — never grows again

### 2.3 Operational-alert review (POST-BLAZE only)
After Phase 3A is deployed:
- Open `feeOpsAlerts` collection in Firebase Console
- Filter `status == "open"` → review each
- Filter `status == "resolved"` → confirm appropriate auto-resolutions

---

## 3. STUCK REFUND HANDLING

### 3.1 Detection
- Daily check (Section 1.2) reveals `feeRefunds` doc with `status='processing'`
- AND `processLock` ISO-8601 timestamp older than 5 minutes (300s)

### 3.2 Triage (5 min — read-only)
For refund `ref_xxxxx`:
1. Open `feeRefunds/SCH_D94FE8F7AD_ref_xxxxx` in Firebase Console
2. Note: `studentId, amount, receiptNo, processLock, voucherKey, processedBy, processedDate`
3. Check `feeRefundVouchers` for a doc with `refundId == "ref_xxxxx"` — voucher exists?
4. Check `feeLocks/SCH_D94FE8F7AD_<studentId>` — lock still held?
5. Check the demand referenced in `feeReceiptAllocations/SCH_D94FE8F7AD_2026-27_F<receiptNo>` — has its `lastRefundReceipt` been set to `REFUND_<refundIdUpper>`?

### 3.3 Decision tree

| Voucher | Demand reversed (lastRefundReceipt set) | Recommended action |
|---|---|---|
| Absent | No | **Click "Unstick" in admin UI** — clean re-process from start |
| Present | Yes | Refund effectively complete — manually mark `status='processed'` (admin direct via Firebase Console) + clear processLock |
| Present | No | **Test artifact** — manually mark `status='rejected'` + add manualReset metadata (per Section 3.5 below) |
| Absent | Yes | Anomalous — **escalate** to developer; demand reversed without voucher artifact suggests partial-write between steps |

### 3.4 Standard recovery — admin UI Unstick
1. Open admin → Fees → Refunds
2. Filter by status: Processing
3. Click **"Unstick"** button on the stuck row
4. Confirms with dialog
5. Refund flips to `approved`; admin can then click **"Process"** to retry
6. Verify in Firestore: refund `status` updates within seconds; `processLock` cleared

### 3.5 Manual rejection (test artifacts only)
For test/historical refunds that should be terminated cleanly:
```javascript
// Run from C:\xampp\htdocs\Grader\school via node -e "..."
const refRef = fs.doc('feeRefunds/SCH_D94FE8F7AD_ref_xxxxx');
await refRef.update({
  status: 'rejected',
  processLock: '',
  manualResetAt: new Date().toISOString(),
  manualResetBy: 'operator-<name>',
  manualResetReason: '<one-sentence justification>',
  updatedAt: new Date().toISOString(),
});
```
**Always preserve voucher**. Never delete.

### 3.6 Audit
After ANY refund-state mutation, verify a `feeAuditLogs` entry was written (manual mutations bypass the auto-audit; document mutations in `application/logs/log-*.php` via console output or comment in the JS one-liner).

---

## 4. CONFIG DRIFT RESPONSE (Phase 3F)

### 4.1 Detection signals
1. PHP error log line: `payment_webhook: CONFIG DRIFT — rzp_live_* key with mode='test'` (or `rzp_test_* key with mode='live'`)
2. Admin reports webhook 500 errors with `Gateway configuration mismatch. Contact admin.`
3. Razorpay merchant dashboard shows webhook delivery failures

### 4.2 Immediate response (5 min)
1. Open admin → Fees → **Gateway Config** UI
2. Check `provider`, `mode`, `api_key` for the active session
3. Verify consistency:
   - `rzp_live_*` key requires `mode='live'`
   - `rzp_test_*` key requires `mode='test'`
4. If mismatch:
   - **Live key in test mode**: change `mode` to `live` (if you intended live), OR change `api_key` to a `rzp_test_*` value (if you intended test)
   - Save → Phase 3F save-validator confirms consistency before write
5. Trigger one webhook from Razorpay dashboard (Test Webhook button) → verify 200 response

### 4.3 If admin UI rejects save
Phase 3F save-time validator will return `Mode/key mismatch: ...`. This is correct — fix the input pair before retry.

### 4.4 If drift persists after fix
- Check `feeSettings/SCH_D94FE8F7AD_<session>_gateway` directly in Firebase Console
- Confirm field values match what admin UI displays
- If discrepancy → cache invalidation issue; restart Apache

### 4.5 Drift-log dedup verification
```powershell
$today = Get-Date -Format 'yyyy-MM-dd'
(Select-String -Path "application\logs\log-$today.php" -Pattern "CONFIG DRIFT").Count
```
**Expected**: 1 line per drifted webhook request. If multiplied per request → escalate (drift dedup safeguard regression).

---

## 5. PAYMENT ANOMALY ESCALATION

### 5.1 Categories
| Symptom | First-line check |
|---|---|
| Parent reports "Payment success but no receipt" | Section 5.2 |
| Parent reports "Charged twice" | Section 5.3 |
| Receipt total doesn't match payment | Section 5.4 |
| Refund stuck or duplicated | Section 3 |
| Admin sees "Worker DOWN" banner persistent | Section 5.5 |

### 5.2 Missing receipt for confirmed payment
1. Get parent's claimed `paymentId` (from their Razorpay confirmation, or order_id)
2. Query Firestore: `feeOnlineOrders` where `gateway_payment_id == "<paymentId>"` → find order doc
3. Check `status` field: `paid` = success; `processing` = stuck; `created` = never confirmed
4. Cross-check `feeReceipts` for student → expected receipt with matching amount
5. If `paid` order but no receipt → **escalate**: process likely failed mid-write; check `feePendingWrites` for orphan
6. If `processing` for > 10 min → manual intervention via `retry_payment_processing` admin endpoint

### 5.3 Suspected duplicate charge
1. Razorpay merchant dashboard: confirm number of CAPTURED payments for the student (use mobile/student_id)
2. Firestore: `feeReceipts` where `studentId == "<sid>"` AND `createdAt` near the time → count
3. If 1 Razorpay capture but 2 receipts → developer escalation (atomic batch failure)
4. If 2 Razorpay captures → real duplicate; refund one via admin Refunds workflow
5. Log root cause in `application/logs/log-*.php` audit trail

### 5.4 Receipt total mismatch
```javascript
// Diagnostic — run via node -e
const r = await fs.doc('feeReceipts/SCH_D94FE8F7AD_F<num>').get();
const data = r.data();
console.log('amount:', data.amount, 'inputAmount:', data.inputAmount, 'allocatedAmount:', data.allocatedAmount, 'netAmount:', data.netAmount);
const a = await fs.doc('feeReceiptAllocations/SCH_D94FE8F7AD_2026-27_F<num>').get();
const sum = (a.data().allocations || []).reduce((s, x) => s + (x.allocated || 0), 0);
console.log('allocations sum:', sum);
// Expected: amount === inputAmount === allocatedAmount === sum (within 0.005)
```

### 5.5 Worker DOWN banner persistent
Pre-existing operational gap in PHP queue infrastructure (NOT Phase 1-3F scope).
- Payments still RECORDED (atomic batch on counter flow)
- Async post-processing (notifications, summary refresh) delayed
- Verify: Windows Task Scheduler or cron job for `FeeWorker.php` is enabled and firing
- Manually trigger: `php index.php fees/feeworker_run` via CLI if endpoint exists

---

## 6. PARENT / TEACHER DISCREPANCY HANDLING

### 6.1 Parent shows different total than Teacher
**Most common cause**: stale `feeDefaulters` (Teacher reads it; Parent reads `feeDemands` directly).

**Diagnosis**:
```javascript
// Sum of unpaid demands for student
const dem = await fs.collection('feeDemands')
  .where('schoolId', '==', 'SCH_D94FE8F7AD')
  .where('session', '==', '2026-27')
  .where('studentId', '==', '<sid>').get();
const expected = dem.docs.reduce((s, d) => s + ((d.data().status !== 'paid') ? (d.data().balance || 0) : 0), 0);
const def = await fs.doc('feeDefaulters/SCH_D94FE8F7AD_2026-27_<sid>').get();
const stored = def.exists ? (def.data().totalDues || 0) : null;
console.log('Expected:', expected, 'Stored:', stored, 'Drift:', expected - stored);
```

**If Drift > 0**:
- Trust the Parent app's display (reads canonical `feeDemands`)
- Teacher is showing stale data
- Trigger defaulter recompute (post-Blaze: Phase 3D scheduled; pre-Blaze: manual via admin endpoint if available, OR admin processes any small payment to trigger recompute hook)

### 6.2 Receipt visible in Parent but not Teacher
- Teacher reads `feeReceipts where schoolId+studentId` → should match Parent's view
- Verify Teacher app version is at least Phase 2D (`feeBreakdown: List<Map<String,Any>>`)
- Verify Parent app version is at least Phase 2A (session filter)

### 6.3 Carry-forward visible in Parent but not Teacher
Pre-existing limitation: `getClassCarryForward` lacks className/section filter (Phase 2D #2 deferred).
- Teacher view returns school-wide; UI may filter client-side
- If specific student missing: check `feeCarryForward/SCH_D94FE8F7AD_<session>_<sid>` exists

---

## 7. BLAZE ACTIVATION CHECKLIST

### 7.1 Pre-flight (before billing change)
- [ ] Confirm payment method on file with Google Cloud / Firebase
- [ ] Set Cloud Function budget alerts ($10/month threshold for early observation)
- [ ] Backup current state: `gcloud firestore export gs://<bucket>/pre-blaze-2026-MM-DD --collection-ids=feeAuditLogs,feeReceipts,feeDemands,feeRefunds,feeRefundVouchers,feeOnlineOrders`
- [ ] Verify all 4 deferred Phase 3A files are present:
  - `functions/ops_sweep_worker.js`
  - `functions/index.js` (with `feeOpsSweep` export)
  - `firebase-rules/firestore.rules` (with `feeOpsAlerts` block)
  - `firebase-rules/firestore.indexes.json` (with `feeOpsAlerts category+status` index)
- [ ] Verify Phase 3B file is present:
  - `functions/fee_generation_worker.js` (with atomic claim)

### 7.2 Activation steps
1. **Upgrade plan**: Firebase Console → Project Settings → Usage and billing → Modify plan → Blaze (Pay as you go)
2. **Wait 5 min** for billing config propagation
3. Proceed to Section 8 (Phase 3A/3B activation sequence)

### 7.3 Post-activation cost watch
- 24h: monitor Firebase Console → Usage. Sweep should be ~144 invocations.
- 7d: confirm cost trajectory <$5/month at current scale
- 30d: confirm CF execution time remains <5s typical

---

## 8. PHASE 3A / 3B ACTIVATION SEQUENCE

**Run only after Section 7 complete.**

### 8.1 Window 1 — Indexes deploy (1 min)
```powershell
cd C:\xampp\htdocs\Grader\school\firebase-rules
firebase deploy --only firestore:indexes
```
**Verify**: Firebase Console → Firestore → Indexes → new `feeOpsAlerts category+status` index in `Building` then `Enabled`.

### 8.2 Window 2 — Rules deploy (30 sec)
```powershell
firebase deploy --only firestore:rules
```
**Verify**: 2 pre-existing warnings, no new ones. "Deploy complete!"

### 8.3 Window 3 — CF deploy (3-5 min)
```powershell
cd C:\xampp\htdocs\Grader\school
firebase deploy --only functions:feeOpsSweep,functions:processFeeGenerationJob
```
**Verify**: both functions show in Firebase Console → Functions; revisions incremented.

### 8.4 Window 4 — First sweep verification (~10-12 min wait)
1. Wait for first scheduled tick of `feeOpsSweep`
2. Firebase Console → Functions → `feeOpsSweep` → Logs → look for `feeOpsSweep started` then `feeOpsSweep completed` with structured `results` payload
3. Query `feeOpsAlerts` → expected count = **0** at fresh activation (no stuck states post-cleanup)

### 8.5 Window 5 — Phase 3B verification (next fee_generation_job)
- Trigger 1 admin fee-generation job
- Verify CF logs show atomic claim succeeded; new doc field `claimedBy: <revision-id>`
- Confirm no `claim failed` logs for the single instance

### 8.6 Window 6 — Cool window
- 24h: zero CF errors, zero unexpected alerts
- After 24h clean → Phases 3A + 3B operationally verified

### 8.7 Rollback (if needed within 24h)
```powershell
# R1 — Disable sweep schedule (CF stays deployed)
gcloud functions deploy feeOpsSweep --gen2 --region=us-central1 --no-trigger --project=graderadmin

# R2 — Atomic claim rollback
cd D:\Projects\fee_generation_worker
git checkout HEAD~1 -- fee_generation_worker.js   # adjust ref to before Phase 3B commit
firebase deploy --only functions:processFeeGenerationJob
```

---

## 9. CONTROLLED-COHORT OPERATIONAL SOP

### 9.1 Onboarding cadence (first 30 days post-go-live)
- **Week 1**: 1 school (current production school)
- **Week 2**: up to 3 additional schools
- **Week 3**: up to 5 additional schools
- **Week 4**: up to 10 additional schools per week
- **After Phase 3A/3B activated + 30d clean**: lift cap

### 9.2 Per-school onboarding checklist
- [ ] School profile created in `System/Schools/{schoolId}`
- [ ] Initial admin account provisioned
- [ ] Active session set (`session_year` matches current academic year format `YYYY-YY`)
- [ ] `feeStructures` created for each class+section
- [ ] Razorpay gateway configured (Section 4.2 verifies consistency)
- [ ] Initial fee-generation job run successfully (status=`completed`)
- [ ] Run integrity check for new school: `php scripts/fee_integrity_check.php --schoolId=<NEW> --session=<YYYY-YY>` — expect 0 findings

### 9.3 Daily monitoring during cohort
- Section 1 daily checks
- Per-school audit log review (sample 1 school's `feeAuditLogs` for last 24h activity)
- Razorpay merchant dashboard cross-check for each active school

### 9.4 Weekly during cohort
- Section 2 procedures
- Compare integrity-check results week-over-week — drift = active concern

### 9.5 CF job cleanup procedure (manual until Phase 3A active)
```javascript
// For a stuck fee_generation_jobs doc:
const ref = fs.doc('fee_generation_jobs/<JOB_ID>');
await ref.update({
  status: 'failed',
  failedAt: new Date().toISOString(),
  failureReason: 'manual_reset_<reason>',
  manualResetNote: '<context>',
});
```

### 9.6 Escalation triggers (any one → halt onboarding)
- ≥2 stuck refunds aging past 1 hour without manual intervention
- ≥1 CF job stuck for >24h
- ≥1 day of `CONFIG DRIFT` log entries on production school
- I-2 finding count growing weekly without explanation
- Any `FATAL` or `Undefined property` line in PHP error log
- Parent app crash reports increase >2x baseline

---

## APPENDIX A — COMMAND CHEAT-SHEET

```powershell
# Daily log scan
$today = Get-Date -Format 'yyyy-MM-dd'
Select-String -Path "C:\xampp\htdocs\Grader\school\application\logs\log-$today.php" `
  -Pattern "FEE_AUDIT_FAIL|CONFIG DRIFT|FATAL|Undefined property"

# Live-tail today's PHP log
Get-Content -Path "C:\xampp\htdocs\Grader\school\application\logs\log-$today.php" -Wait -Tail 50

# Weekly integrity check
cd C:\xampp\htdocs\Grader\school
php scripts\fee_integrity_check.php --schoolId=SCH_D94FE8F7AD --session=2026-27

# Collection-count snapshot
node scripts\count_collections_phase1.js

# Apache restart (post-PHP-deploy)
& "C:\xampp\xampp_stop.exe" ; Start-Sleep -Seconds 3 ; & "C:\xampp\xampp_start.exe"

# Cloud Functions log tail (post-Blaze)
firebase functions:log --only feeOpsSweep --lines 50
firebase functions:log --only processFeeGenerationJob --lines 50

# Refund unstick (admin UI)
# → localhost/Grader/school/index.php/fee_management/refunds → Approved → Unstick

# Manual refund rejection (Admin SDK, audit-metadata required)
# → see Section 3.5
```

---

## APPENDIX B — FILE-PATH INDEX

| Role | Path |
|---|---|
| Admin PHP root | `C:\xampp\htdocs\Grader\school` |
| Parent app root | `D:\Projects\SchoolSyncParent` |
| Teacher app root | `D:\Projects\SchoolSyncTeacher` |
| Firebase rules | `C:\xampp\htdocs\Grader\school\firebase-rules\firestore.rules` |
| Firestore indexes | `C:\xampp\htdocs\Grader\school\firebase-rules\firestore.indexes.json` |
| Cloud Functions | `C:\xampp\htdocs\Grader\school\functions\` |
| PHP error logs | `C:\xampp\htdocs\Grader\school\application\logs\log-YYYY-MM-DD.php` |
| Fee canonical writer | `C:\xampp\htdocs\Grader\school\application\services\FeeCollectionService.php` |
| Refund service | `C:\xampp\htdocs\Grader\school\application\libraries\Fee_refund_service.php` |
| Audit logger | `C:\xampp\htdocs\Grader\school\application\libraries\Fee_audit_logger.php` |
| Integrity checker | `C:\xampp\htdocs\Grader\school\application\libraries\Fee_integrity_checker.php` |
| Integrity CLI | `C:\xampp\htdocs\Grader\school\scripts\fee_integrity_check.php` |
| Snapshots dir | `C:\xampp\htdocs\Grader\school\snapshots\` |
| Snapshot scripts | `C:\xampp\htdocs\Grader\school\scripts\count_collections_phase1.js`, `backfill_fee_audit_logs_camel.js`, `test_phase1_rules.js`, `test_phase1_app_smoke.js` |

---

## APPENDIX C — GLOSSARY

### Refund states
- `pending` — created, awaiting admin approval
- `approved` — admin approved, ready for processing
- `processing` — admin clicked Process; lock acquired; in-flight
- `processed` — successfully completed (voucher + journal + markProcessed)
- `rejected` — admin rejected OR manually reset (test artifacts)

### Fee-generation job states
- `pending` — admin submitted; awaiting CF trigger
- `running` — CF claimed via atomic transaction (Phase 3B)
- `completed` — all demands generated, summary written
- `failed` — function-side error OR manual reset

### Audit doc shapes
- **Shape A** (canonical post-Phase-2B): `{auditId, schoolId, session, action, entity, entityId, before, after, changedKeys, performedBy, source, reason, timestamp, ...}`
- All `feeAuditLogs` docs in production are Shape A as of 2026-05-08.

### Collection scope
- `feeReceipts`, `feeReceiptIndex`, `feeReceiptAllocations` — receipt subsystem
- `feeDemands`, `feeStructures` — demand subsystem
- `feeRefunds`, `feeRefundVouchers`, `feeRefundAudit` — refund subsystem
- `feeOnlineOrders`, `feeOnlinePayments` — Razorpay subsystem
- `feeAuditLogs` — audit trail (camelCase, post-Phase-1)
- `fee_audit_logs` — legacy snake-case audit (frozen at 17, never grows)
- `feeIdempotency`, `feeLocks`, `feeCounters`, `feePendingWrites`, `feeDemandLocks` — server-only safety/coordination
- `feeOpsAlerts` — operational alerts (post-Phase-3A activation)

---

## APPENDIX D — KNOWN HISTORICAL BASELINE (acknowledged, not blocking)

| Item | State | Disposition |
|---|---|---|
| `ref_69eae3ff05304` (resolved 2026-05-08) | status=`rejected`, voucher preserved as audit artifact | Cleared. Reference in this runbook only as historical evidence. |
| `JOB_20260422063643_cb8bc4` (resolved 2026-05-08) | status=`failed`, manual reset metadata | Cleared. |
| STU0004 + STU0005 defaulter staleness | `feeDefaulters.totalDues=0` despite ~₹34,600 unpaid each | **Acknowledged baseline.** Will auto-correct after Phase 3D activation post-Blaze. |
| `Worker DOWN` queue dashboard banner | Background PHP cron not firing | Pre-existing infrastructure gap. Payments still record. Section 5.5 if urgent. |
| `fee_audit_logs` (snake) at 17 docs | Phase 1 backfill source; never grows | Will sunset 30 days post-Phase-1; safe to delete after operator confidence period. |

---

## APPENDIX E — CONTACT REGISTRY (TBD)

To be filled in by operator:
- Primary on-call: ________
- Backup on-call: ________
- Razorpay support contact: ________
- Firebase support tier: ________
- Escalation path for financial discrepancy: ________

---

**End of Runbook v1.0**
