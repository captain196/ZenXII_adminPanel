# Async Fee Queue — Deployment & Operations Checklist

Phase 7A → 7F. Written to be read end-to-end before the first production enable.

---

## 1. Environment variables

Required (fail-loud if missing — the worker exits with a clear error):

| Var | Value | Where |
|---|---|---|
| `SCHOOL_NAME` | human school name, matches `Schools/{name}` | Windows Task Scheduler env OR shell env for cron |
| `SESSION_YEAR` | `YYYY-YY`, e.g. `2026-27` | same |

Feature flag (opt-in, default **OFF**):

| Var | Value | Effect |
|---|---|---|
| `FEES_ASYNC_ALLOCATION` | `1` to enable | Server-wide: every `submit_fees` uses the async pipeline |
|   | unset / any other value | Legacy sync path (unchanged) |

Set server-wide via Apache `SetEnv FEES_ASYNC_ALLOCATION 1` in `.htaccess` OR per-call via `$data['async_allocation'] = true` (internal callers only).

## 2. Firestore rules & indexes — deploy BEFORE flipping the flag

Rules (add to `firebase-rules/firestore.rules`):

```
match /feeJobs/{id} {
  allow read:  if isAdmin();
  allow write: if false;                 // server only
}
match /feeWorkerHeartbeat/{id} {
  allow read:  if isAdmin();
  allow write: if false;                 // server only
}
match /feeAuditLogs/{id} {
  allow read:  if isAdmin();
  allow write: if false;                 // server only
}
```

Composite indexes (`firebase-rules/firestore.indexes.json`):

```
feeJobs (schoolId ASC, session ASC, status ASC, createdAt ASC)
feeJobs (schoolId ASC, session ASC, status ASC, updatedAt ASC)
feeJobs (schoolId ASC, session ASC, status ASC, finishedAt DESC)
feeLocks (schoolId ASC, acquiredAt ASC)
```

Deploy both: `firebase deploy --only firestore:rules,firestore:indexes`. Wait for "Index(es) built" in the console before flipping `FEES_ASYNC_ALLOCATION=1`.

## 3. Scheduler — validate the worker is actually running

Windows Task Scheduler:

| Field | Value |
|---|---|
| Program / script | `C:\xampp\php\php.exe` |
| Arguments | `index.php feeworker run` |
| Start in | `C:\xampp\htdocs\Grader\school` |
| Trigger | Daily, repeat every **1 minute**, for 24 h |
| Run as | local admin (so php.exe has permission to read service-account JSON) |
| Env vars | `SCHOOL_NAME`, `SESSION_YEAR` |

Sub-minute cadence: create a second task staggered by 30 s (two tasks × 1 min each = 30 s effective).

Validate:

- `php index.php feeworker health` → returns JSON with `queued_count`, `processing_count`, etc.
- Trigger one submit, confirm a `feeJobs/{id}` doc appears with `status='queued'`.
- Wait ≤ 60 s, confirm the doc flips to `status='done'` and `feeReceipts/{id}.status='posted'`.
- Watch Windows Event Viewer → Task Scheduler → "Ran successfully" on every scheduled trigger.

## 4. Logging — what to watch

All logs land in `application/logs/log-YYYY-MM-DD.php`.

### FC (sync submit path)
- `FC_TIMING`        — per-phase ms on submit critical path
- `FC_OPTIMIZED`     — compact snapshot of batch size + read-parallel ms
- `[FCS ASYNC COMMIT]`, `[FCS BATCH COMMIT]`, `[FCS CLEANUP FALLBACK]`

### FEE_JOB (worker)
- `FEE_JOB_STARTED`
- `FEE_JOB_DONE`
- `FEE_JOB_RETRY`          — transient failure, will retry
- `FEE_JOB_FAILED`         — gave up after 3 attempts (operator triage)
- `FEE_JOB_REAPED`         — stuck-processing job auto-reset
- `FEE_JOB_REAPER_ERROR`
- `FEE_LOCK_RELEASED`      — reason=token-match / stale-override / absent
- `FEE_LOCK_SKIPPED`       — not ours and still fresh
- `FEE_LOCK_RELEASE_ERROR`
- `FEE_JOB_DEFERRED_*_FAIL` — non-critical side-effect (defaulter/summary/journal)
- `FEE_WORKER_HEARTBEAT_FAIL`

### FEE_AUDIT (admin actions on the queue)
- `FEE_AUDIT job_retry`
- `FEE_AUDIT bulk_retry_failed`
- `FEE_AUDIT bulk_reap_stuck`
- `FEE_AUDIT bulk_clear_stale_locks`
- `FEE_AUDIT_FAIL`         — audit write itself failed (action still ran)

Recommended alerts:
- `grep FEE_JOB_FAILED` in the last hour > 0 → PagerDuty
- No `FEE_JOB_STARTED` OR `run_started` heartbeat in 5 min → worker-down alert (covered by `/fees/queue_status`.`worker.down`)

## 5. Admin dashboard — operator runbook

`/fees/queue_dashboard` (role: MANAGE) shows everything:

- **Traffic light** top-right: green / amber / red
- **Worker badge**: up / warn / down (last-heartbeat age)
- **5 KPI tiles**: queued, processing (+ stuck count), failed, oldest queued age, metrics (avg ms + success %)
- **Alerts banner**: server-derived text — show verbatim to operators
- **Operator actions** (all audit-logged to `feeAuditLogs`):
  - Retry all failed — batch-queues up to 100 failed jobs
  - Force re-run stuck — resets processing > 5 min
  - Clear stale locks — deletes `feeLocks` older than 120 s
- **Failed jobs table** (newest 50) with per-row Retry

Daily check: one glance should tell you the system is healthy. Green + worker-up + no alerts = done.

## 6. Multi-school safety — verified

Every new endpoint scopes reads/writes by `schoolId` via `$this->fs->schoolId()`. Never accepts `schoolId` from the client. Verified endpoints:

- `/fees/queue_status`
- `/fees/queue_failed_jobs`
- `/fees/queue_retry_all_failed`
- `/fees/queue_reap_stuck`
- `/fees/queue_clear_stale_locks`
- `/fees/queue_job_retry`
- `/fees/receipt_status`

`feeJobs` doc IDs are prefixed `{schoolId}_...`, and all queries filter `where('schoolId','==', $schoolId)`. `feeLocks` filters the same way. `feeAuditLogs` ID prefix + field.

Worker is single-tenant: one scheduled task per (school, session) — its env vars pin the scope.

## 7. Rollback plan

### Soft rollback (zero code change)

1. Remove `FEES_ASYNC_ALLOCATION` from env (or set to `0`).
2. Next submit uses the legacy sync path. Already-queued jobs continue draining.
3. Verify: tail the log for `[FCS BATCH COMMIT]` (sync) instead of `FEE_ASYNC_JOB_QUEUED`.

### Hard rollback (after discovered bug)

1. Flip the flag off (step 1 above).
2. Drain the queue: `php index.php feeworker run` a few times manually until `queued_count=0`.
3. If any jobs are `failed`, inspect via `/fees/queue_dashboard` — either Retry once the underlying bug is fixed, OR manually execute the ops from `feeJobs/{id}.payload.ops` via the admin console.
4. Cashier-visible receipts with `status='queued'` that did NOT process will appear as stuck in parent/teacher apps — these require either re-running the worker or manually setting `status='posted'` on the receipt after verifying the allocation matches the underlying demand state.

### Emergency stop (worker running wild)

1. Disable the Windows Task Scheduler task.
2. Flip flag off.
3. New submits fall through to sync path. Nothing else needed.

## 8. Metrics to track over time

Exposed at `/fees/queue_status` (JSON) or `/fees/queue_dashboard` (UI):

| Metric | Healthy range |
|---|---|
| `queued_count` | 0–10 steady-state; spikes during peak hours are fine |
| `processing_count` | 0–5 at any instant |
| `stuck_processing` | **always 0** (reaper should catch it) |
| `failed_count` | **always 0** — investigate every non-zero |
| `oldest_job_seconds` | < 60 s |
| `metrics.avg_processing_ms` | 1 000 – 5 000 ms |
| `metrics.success_rate_pct` | ≥ 99 % |
| `worker.age_seconds` | < 120 s |
| `worker.down` | always `false` |

## 9. Known trade-offs (deliberate)

1. **Lock token vs generic retry** — the claim-batch commit can give a generic "concurrent update" error instead of the specific "lock held" / "receipt reserved" message when a race happens at commit-time. Preload covers the common case with specific messages.
2. **Receipt printed before posting** — cashier can NOT print while the receipt is `queued`. Print re-enables on status flip to `posted` (polling handles this in 3–5 s typical).
3. **Worker hold time on lock** — during async processing, the student's `feeLocks` entry is held by the worker until it finishes (typically 1–3 s). A second submit for the same student within that window gets "Previous payment is still processing." UX cost, correctness win.
4. **First run after enabling flag** — no `feeWorkerHeartbeat` doc exists yet → dashboard shows worker DOWN until the first `run` cycle. Kick it manually with `php index.php feeworker run` or wait one scheduler tick.
