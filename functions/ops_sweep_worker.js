// functions/ops_sweep_worker.js
//
// Phase 3A — Detect-only operational sweep.
//
// Runs every 10 minutes via Cloud Scheduler. Queries server-side state
// for stuck/orphan conditions and writes deduplicated alerts to
// feeOpsAlerts. Does NOT remediate — that's deferred to a future phase
// once detection accuracy is validated.
//
// Safeguards:
//   - Deterministic alertId (sha256 of schoolId|category|entityId)
//     guarantees idempotent dedup. Same orphan = same doc; re-detection
//     bumps detectionCount, never duplicates.
//   - Hard per-sweep alert-write cap (ALERT_CAP_PER_SWEEP = 100) protects
//     against runaway storms from malformed orphans or unknown failures.
//     When the cap is hit, sweep:
//       * stops further upserts in this invocation
//       * logs `alert_cap_exceeded` once
//       * SKIPS auto-resolve for the affected categories to avoid
//         falsely resolving still-stuck alerts whose upsert was capped
//       * completes successfully (no fatal throw, no infinite retry)
//   - Auto-resolve closes alerts whose source orphan has cleared.
//     Skipped when cap is hit, to preserve correctness.

const { onSchedule } = require('firebase-functions/v2/scheduler');
const { logger } = require('firebase-functions/v2');
const admin = require('firebase-admin');
const crypto = require('crypto');

if (!admin.apps.length) admin.initializeApp();
const fs = admin.firestore();

const SWEEP_VERSION = '3A.1';

// Detection thresholds (seconds). 600s = 10 min — 2x the unstick
// endpoint's 300s safe-window; aligns with the schedule cadence so an
// orphan is alerted within ~20 min of the threshold being crossed.
const STUCK_REFUND_TTL_S       = 600;
const STUCK_JOB_TTL_S          = 600;
const ORPHAN_PENDING_TTL_S     = 600;
const STUCK_ONLINE_ORDER_TTL_S = 600;

// Hard upper bound on alert writes per single sweep invocation.
// Protects against alert storms and malformed orphan explosions.
const ALERT_CAP_PER_SWEEP = 100;

const ALERT_COLL = 'feeOpsAlerts';

function ageSeconds(iso) {
  if (!iso) return Infinity;
  const ts = (typeof iso === 'string') ? Date.parse(iso) : NaN;
  if (Number.isNaN(ts)) return Infinity;
  return Math.floor((Date.now() - ts) / 1000);
}

function deterministicAlertId(schoolId, category, entityId) {
  const h = crypto.createHash('sha256');
  h.update(`${schoolId}|${category}|${entityId}`);
  return 'OPSALERT_' + h.digest('hex').slice(0, 16);
}

async function upsertAlert(ctx, alert) {
  if (ctx.alertWriteCount >= ALERT_CAP_PER_SWEEP) {
    ctx.capExceeded = true;
    if (!ctx.capExceededLogged) {
      logger.warn('alert_cap_exceeded', {
        cap: ALERT_CAP_PER_SWEEP,
        sweepVersion: SWEEP_VERSION,
        category: alert.category,
        firstSkippedEntityId: alert.entityId,
      });
      ctx.capExceededLogged = true;
    }
    ctx.alertsSkipped++;
    return null;
  }

  const docId = deterministicAlertId(alert.schoolId, alert.category, alert.entityId);
  const ref = fs.collection(ALERT_COLL).doc(docId);
  const snap = await ref.get();
  const now = admin.firestore.FieldValue.serverTimestamp();
  if (snap.exists) {
    const prev = snap.data();
    await ref.update({
      lastDetectedAt: now,
      detectionCount: (prev.detectionCount || 0) + 1,
      detail: alert.detail,
      // Preserve 'acknowledged' state. Otherwise re-open if was 'resolved'.
      status: prev.status === 'acknowledged' ? 'acknowledged' : 'open',
      severity: alert.severity, // refresh in case age tier escalated
    });
  } else {
    await ref.set({
      alertId: docId,
      schoolId: alert.schoolId,
      session: alert.session || '',
      category: alert.category,
      severity: alert.severity,
      entity: alert.entity,
      entityId: alert.entityId,
      entityPath: alert.entityPath,
      detail: alert.detail,
      recommendedAction: alert.recommendedAction,
      firstDetectedAt: now,
      lastDetectedAt: now,
      detectionCount: 1,
      status: 'open',
      resolvedAt: null,
      acknowledgedAt: null,
      acknowledgedBy: null,
      sweepVersion: SWEEP_VERSION,
    });
  }
  ctx.alertWriteCount++;
  return docId;
}

async function autoResolveCleared(ctx, category, currentlyOpenIds) {
  // SAFETY: when cap was hit, we may have failed to upsert some active
  // orphans this sweep. currentlyOpenIds is therefore incomplete.
  // Auto-resolving on a partial set risks falsely closing still-stuck
  // alerts. Defer resolution to the next sweep when (likely) the cap
  // pressure has eased.
  if (ctx.capExceeded) {
    logger.warn('autoResolve skipped due to cap exceeded', { category });
    return 0;
  }

  const snap = await fs.collection(ALERT_COLL)
    .where('category', '==', category)
    .where('status', '==', 'open')
    .get();
  const toResolve = [];
  snap.docs.forEach(d => {
    if (!currentlyOpenIds.has(d.id)) toResolve.push(d.ref);
  });
  if (toResolve.length === 0) return 0;
  const now = admin.firestore.FieldValue.serverTimestamp();
  await Promise.all(toResolve.map(r => r.update({
    status: 'resolved',
    resolvedAt: now,
  })));
  return toResolve.length;
}

async function sweepStuckRefunds(ctx) {
  const snap = await fs.collection('feeRefunds').where('status', '==', 'processing').get();
  const openIds = new Set();
  let alerted = 0;
  for (const doc of snap.docs) {
    const data = doc.data();
    const lockTs = data.processLock || data.process_lock || '';
    const age = ageSeconds(lockTs);
    if (age < STUCK_REFUND_TTL_S) continue;
    const id = await upsertAlert(ctx, {
      schoolId: data.schoolId || '',
      session: data.session || '',
      category: 'stuck_refund',
      severity: age > 3600 ? 'high' : 'medium',
      entity: 'refund',
      entityId: data.refundId || doc.id,
      entityPath: `feeRefunds/${doc.id}`,
      detail: {
        receiptNo: data.receiptNo || '',
        studentId: data.studentId || '',
        amount: Number(data.amount) || 0,
        processLockAgeSec: age,
      },
      recommendedAction: `Click Unstick on this refund (admin UI) or POST /fee_management/unstick_refund refund_id=${data.refundId || doc.id}`,
    });
    if (id) {
      openIds.add(id);
      alerted++;
    }
  }
  const resolved = await autoResolveCleared(ctx, 'stuck_refund', openIds);
  return { scanned: snap.size, alerted, resolved };
}

async function sweepStuckJobs(ctx) {
  const snap = await fs.collection('fee_generation_jobs').where('status', '==', 'running').get();
  const openIds = new Set();
  let alerted = 0;
  for (const doc of snap.docs) {
    const data = doc.data();
    const startedAt = data.startedAt || '';
    const age = ageSeconds(startedAt);
    if (age < STUCK_JOB_TTL_S) continue;
    const id = await upsertAlert(ctx, {
      schoolId: data.schoolId || '',
      session: data.session || '',
      category: 'stuck_job',
      severity: age > 3600 ? 'high' : 'medium',
      entity: 'job',
      entityId: doc.id,
      entityPath: `fee_generation_jobs/${doc.id}`,
      detail: {
        startedAt,
        startedAtAgeSec: age,
        processedStudents: Number(data.processedStudents) || 0,
        totalStudents: Number(data.totalStudents) || 0,
      },
      recommendedAction: `Job running > ${STUCK_JOB_TTL_S}s. Likely function timeout. Manually flip status='failed' or restart.`,
    });
    if (id) {
      openIds.add(id);
      alerted++;
    }
  }
  const resolved = await autoResolveCleared(ctx, 'stuck_job', openIds);
  return { scanned: snap.size, alerted, resolved };
}

async function sweepOrphanPendingWrites(ctx) {
  const snap = await fs.collection('feePendingWrites').get();
  const openIds = new Set();
  let alerted = 0;
  for (const doc of snap.docs) {
    const data = doc.data();
    const createdAt = data.createdAt || '';
    const age = ageSeconds(createdAt);
    if (age < ORPHAN_PENDING_TTL_S) continue;
    const inferredSchool = (doc.id || '').split('_')[0];
    const id = await upsertAlert(ctx, {
      schoolId: data.schoolId || inferredSchool,
      session: data.session || '',
      category: 'orphan_pending_write',
      severity: 'high',
      entity: 'pendingWrite',
      entityId: doc.id,
      entityPath: `feePendingWrites/${doc.id}`,
      detail: {
        userId: data.userId || '',
        amount: Number(data.amount) || 0,
        months: Array.isArray(data.months) ? data.months : [],
        createdAtAgeSec: age,
      },
      recommendedAction: 'Receipt write may be partial. Run scripts/fee_integrity_check.php and inspect receipt + allocations for this receiptKey.',
    });
    if (id) {
      openIds.add(id);
      alerted++;
    }
  }
  const resolved = await autoResolveCleared(ctx, 'orphan_pending_write', openIds);
  return { scanned: snap.size, alerted, resolved };
}

async function sweepStuckOnlineOrders(ctx) {
  const snap = await fs.collection('feeOnlineOrders').where('status', '==', 'processing').get();
  const openIds = new Set();
  let alerted = 0;
  for (const doc of snap.docs) {
    const data = doc.data();
    const startedAt = data.processing_started || '';
    const age = ageSeconds(startedAt);
    if (age < STUCK_ONLINE_ORDER_TTL_S) continue;
    const id = await upsertAlert(ctx, {
      schoolId: data.schoolId || '',
      session: data.session || '',
      category: 'stuck_online_order',
      severity: age > 3600 ? 'high' : 'medium',
      entity: 'order',
      entityId: doc.id,
      entityPath: `feeOnlineOrders/${doc.id}`,
      detail: {
        gateway_order_id: data.gateway_order_id || '',
        gateway_payment_id: data.gateway_payment_id || '',
        amount: Number(data.amount) || 0,
        processing_started_age: age,
      },
      recommendedAction: 'Razorpay verify-and-process appears stuck. Check feeOnlinePayments for webhook arrival; consider retry_payment_processing.',
    });
    if (id) {
      openIds.add(id);
      alerted++;
    }
  }
  const resolved = await autoResolveCleared(ctx, 'stuck_online_order', openIds);
  return { scanned: snap.size, alerted, resolved };
}

exports.feeOpsSweep = onSchedule(
  {
    schedule: 'every 10 minutes',
    timeZone: 'UTC',
    region: 'us-central1',
    timeoutSeconds: 120,
    memory: '256MiB',
    retryCount: 1,
  },
  async (event) => {
    const t0 = Date.now();
    const ctx = {
      alertWriteCount: 0,
      alertsSkipped: 0,
      capExceeded: false,
      capExceededLogged: false,
    };
    logger.info('feeOpsSweep started', { sweepVersion: SWEEP_VERSION, cap: ALERT_CAP_PER_SWEEP });
    const results = {};
    try {
      results.refunds = await sweepStuckRefunds(ctx);
      results.jobs = await sweepStuckJobs(ctx);
      results.pendingWrites = await sweepOrphanPendingWrites(ctx);
      results.onlineOrders = await sweepStuckOnlineOrders(ctx);
      results.alertWriteCount = ctx.alertWriteCount;
      results.alertsSkipped = ctx.alertsSkipped;
      results.capExceeded = ctx.capExceeded;
      results.elapsedMs = Date.now() - t0;
      logger.info('feeOpsSweep completed', results);
    } catch (e) {
      logger.error('feeOpsSweep failed', {
        error: e.message,
        stack: e.stack,
        partialResults: results,
        alertWriteCount: ctx.alertWriteCount,
        alertsSkipped: ctx.alertsSkipped,
        capExceeded: ctx.capExceeded,
        elapsedMs: Date.now() - t0,
      });
      throw e;
    }
  }
);
