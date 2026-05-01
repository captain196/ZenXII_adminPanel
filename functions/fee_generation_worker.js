/**
 * Fee Generation Worker — Cloud Function (Node.js, Gen-2).
 *
 * Triggered on `fee_generation_jobs/{jobId}` document creation. The PHP
 * admin panel writes a job spec; this worker processes it asynchronously
 * with batched Firestore writes + bounded concurrency.
 *
 * Targets:
 *   - 1,000 students → < 5 minutes
 *   - No HTTP timeout risk on the PHP side (fire-and-forget)
 *   - Safe re-runs (idempotency via deterministic demand doc IDs)
 *   - Per-student failure isolation (one bad student doesn't abort the run)
 *
 * Job schema (written by PHP):
 *   fee_generation_jobs/{jobId} {
 *     jobId:               string,
 *     schoolId:            string,
 *     session:             string (e.g. "2026-27"),
 *     class:               string|"",        // "Class 8th" or "" for all
 *     section:             string|"",        // "Section A" or "" for all
 *     months:              string[],         // ["April"] or all 12
 *     requestedBy:         string,           // admin id, for audit
 *     requestedAt:         ISO string,
 *     status:              "pending",        // set by PHP
 *     // Worker-updated progress fields (all written here):
 *     startedAt, completedAt, failedAt,
 *     totalStudents, processedStudents, successCount, failureCount,
 *     demandsCreated, demandsSkipped,
 *     errors: [{ studentId, error, at }],
 *   }
 *
 * Demand doc idempotency key:
 *   feeDemands/{schoolId}_{session}_{studentId}_{periodKey}_{feeHeadSlug}
 *   Deterministic — re-running the job will overwrite the same doc
 *   (merge=true), skipping money fields that already have writes.
 */

const admin = require('firebase-admin');
const { onDocumentCreated } = require('firebase-functions/v2/firestore');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();

// ── Tuning knobs ────────────────────────────────────────────────────────
const BATCH_SIZE       = 400;   // Firestore hard limit is 500; leave headroom
const MAX_CONCURRENCY  = 5;     // Parallel batch commits
const PROGRESS_EVERY   = 50;    // Push job progress every N students

// Indian academic months (April → March)
const ACADEMIC_MONTHS = [
  'April', 'May', 'June', 'July', 'August', 'September',
  'October', 'November', 'December', 'January', 'February', 'March',
];
const MONTH_TO_NUM = {
  January: '01', February: '02', March: '03', April: '04', May: '05', June: '06',
  July: '07', August: '08', September: '09', October: '10', November: '11', December: '12',
};

// ── Helpers ─────────────────────────────────────────────────────────────

/** Slugify a fee-head name to make a stable doc-ID segment. */
function slug(s) {
  return String(s || '')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');
}

/** Build period_key in YYYY-MM format for monthly; YYYY-04 for Yearly. */
function buildPeriodKey(session, month) {
  // session = "2026-27" → startYear = 2026
  const startYear = parseInt(String(session).split('-')[0], 10) || new Date().getFullYear();
  if (month === 'Yearly Fees' || month === 'Yearly') {
    return `${startYear}-04`;
  }
  const mm = MONTH_TO_NUM[month] || '04';
  // Jan/Feb/Mar belong to startYear+1 in Indian academic year.
  const year = (mm === '01' || mm === '02' || mm === '03') ? startYear + 1 : startYear;
  return `${year}-${mm}`;
}

/** Run an async mapper over items with bounded concurrency (Promise.all pool). */
async function mapLimit(items, limit, fn) {
  const results = new Array(items.length);
  let next = 0;
  const workers = Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (true) {
      const i = next++;
      if (i >= items.length) return;
      try {
        results[i] = await fn(items[i], i);
      } catch (e) {
        results[i] = { __error: e };
      }
    }
  });
  await Promise.all(workers);
  return results;
}

/** Safe Firestore batch commit with a retry on aborted/unavailable. */
async function commitBatch(batch, attempt = 1) {
  try {
    await batch.commit();
  } catch (e) {
    if (attempt < 3 && /ABORTED|UNAVAILABLE|DEADLINE_EXCEEDED/i.test(e.message || '')) {
      await new Promise(r => setTimeout(r, 200 * attempt));
      return commitBatch(batch, attempt + 1);
    }
    throw e;
  }
}

// ── Domain helpers ──────────────────────────────────────────────────────

/** List every class/section that has a fee chart in this session. */
async function listSectionsWithFeeChart(schoolId, session) {
  const snap = await db.collection('feeStructures')
    .where('schoolId', '==', schoolId)
    .where('session',  '==', session)
    .get();
  const out = [];
  snap.forEach(doc => {
    const d = doc.data() || {};
    const c = String(d.className || '').trim();
    const s = String(d.section   || '').trim();
    if (c && s) out.push({ class: c, section: s, chart: d });
  });
  return out;
}

/** Resolve the job scope → concrete list of class/section pairs to process. */
async function resolveScope(job) {
  const all = await listSectionsWithFeeChart(job.schoolId, job.session);
  return all.filter(cs => {
    if (job.class   && cs.class   !== job.class)   return false;
    if (job.section && cs.section !== job.section) return false;
    return true;
  });
}

/** Fetch roster for a class/section. Returns array of {studentId, name}. */
async function listRoster(schoolId, className, section) {
  const snap = await db.collection('students')
    .where('schoolId',  '==', schoolId)
    .where('className', '==', className)
    .where('section',   '==', section)
    .get();
  const out = [];
  snap.forEach(doc => {
    const d = doc.data() || {};
    const sid = String(d.studentId || d.userId || d.id || doc.id.split('_').pop() || '').trim();
    if (!sid) return;
    out.push({
      studentId:    sid,
      studentName:  String(d.name || d.studentName || sid),
      fatherName:   String(d.fatherName || ''),
    });
  });
  return out;
}

/**
 * Build the demand docs for a single student × fee chart × months.
 * Returns an array of { id, data } objects ready for batching.
 * Doc ID is deterministic — re-running overwrites the same slot (merge).
 */
function buildDemandsForStudent(job, student, cs) {
  const docs = [];
  const heads = Array.isArray(cs.chart.feeHeads) ? cs.chart.feeHeads : [];
  const now = new Date().toISOString();

  heads.forEach(head => {
    const headName = String(head.name || '').trim();
    const amount   = Number(head.amount || 0);
    const freq     = String(head.frequency || 'monthly').toLowerCase();
    const category = String(head.category || 'General');
    if (!headName || amount <= 0) return;

    const isYearly = freq === 'annual' || freq === 'yearly' || freq === 'one-time';
    // Yearly → emit once (bundled with April). Monthly → emit per selected month.
    const periodsToEmit = isYearly
      ? (job.months.includes('April') || job.months.includes('Yearly Fees') ? ['April'] : [])
      : job.months.filter(m => ACADEMIC_MONTHS.includes(m));

    periodsToEmit.forEach(month => {
      const periodKey = buildPeriodKey(job.session, isYearly ? 'Yearly Fees' : month);
      // The UI labels yearly as "Yearly Fees", keeps monthly as the month name.
      const periodLabel = isYearly ? 'Yearly Fees' : month;
      const headSlug = slug(headName);
      const docId = isYearly
        ? `${job.schoolId}_${job.session}_${student.studentId}_YEARLY_${headSlug}`
        : `${job.schoolId}_${job.session}_${student.studentId}_${periodKey}_${headSlug}`;

      docs.push({
        id: docId,
        data: {
          schoolId:        job.schoolId,
          session:         job.session,
          studentId:       student.studentId,
          studentName:     student.studentName,
          fatherName:      student.fatherName,
          className:       cs.class,
          section:         cs.section,
          fee_head:        headName,
          feeHead:         headName,
          fee_head_id:     headSlug,
          category,
          period:          periodLabel,
          month:           periodLabel,
          period_key:      periodKey,
          period_type:     isYearly ? 'yearly' : 'monthly',
          frequency:       freq,
          original_amount: amount,
          grossAmount:     amount,
          discount_amount: 0,
          discountAmount:  0,
          fine_amount:     0,
          fineAmount:      0,
          net_amount:      amount,
          netAmount:       amount,
          paid_amount:     0,
          paidAmount:      0,
          balance:         amount,
          status:          'unpaid',
          due_date:        dueDateFor(job.session, periodLabel),
          generatedBy:     job.jobId,
          createdAt:       now,
          updatedAt:       now,
        },
      });
    });
  });
  return docs;
}

/**
 * Compute due-date. Common Indian pattern: dueDay of the billing month.
 * Yearly falls due 15 April (session start + 2 weeks).
 */
function dueDateFor(session, periodLabel) {
  const startYear = parseInt(String(session).split('-')[0], 10) || new Date().getFullYear();
  if (periodLabel === 'Yearly Fees' || periodLabel === 'Yearly') {
    return `${startYear}-04-15`;
  }
  const mm = MONTH_TO_NUM[periodLabel] || '04';
  const yr = (mm === '01' || mm === '02' || mm === '03') ? startYear + 1 : startYear;
  return `${yr}-${mm}-10`; // 10th of the month
}

/**
 * Given candidate demand docs, filter out those that already exist with
 * money applied (paid_amount > 0) — we never want to clobber real payment
 * state. Returns { toCreate, skipped }.
 * Uses a single whereIn query with chunks of 30 ids.
 */
async function filterExisting(docs) {
  if (!docs.length) return { toCreate: [], skipped: 0 };
  const existing = new Set();
  for (let i = 0; i < docs.length; i += 30) {
    const chunk = docs.slice(i, i + 30).map(d => d.id);
    const snap = await db.collection('feeDemands')
      .where(admin.firestore.FieldPath.documentId(), 'in', chunk)
      .get();
    snap.forEach(s => {
      const d = s.data() || {};
      // Consider "existing" iff payment has touched it. A 0-paid unpaid
      // demand can be safely overwritten (re-generation is a no-op).
      if (Number(d.paid_amount || d.paidAmount || 0) > 0.005) existing.add(s.id);
    });
  }
  const toCreate = docs.filter(d => !existing.has(d.id));
  return { toCreate, skipped: existing.size };
}

/** Chunk an array into groups of size N. */
function chunk(arr, n) {
  const out = [];
  for (let i = 0; i < arr.length; i += n) out.push(arr.slice(i, i + n));
  return out;
}

// ── Main worker ─────────────────────────────────────────────────────────

exports.processFeeGenerationJob = onDocumentCreated(
  {
    document: 'fee_generation_jobs/{jobId}',
    region:   'us-central1',
    timeoutSeconds: 540,         // 9 minutes — Gen-2 max
    memory:         '1GiB',
    concurrency:    1,           // Serialize jobs per instance to avoid fighting
  },
  async (event) => {
    const jobRef = event.data.ref;
    const job = event.data.data();
    if (!job || job.status !== 'pending') {
      logger.info('job skipped — not pending', { jobId: event.params.jobId, status: job?.status });
      return;
    }

    const startedAt = new Date().toISOString();
    await jobRef.update({ status: 'running', startedAt });

    const totals = {
      totalStudents: 0,
      processedStudents: 0,
      successCount: 0,
      failureCount: 0,
      demandsCreated: 0,
      demandsSkipped: 0,
      errors: [],
    };

    try {
      // Resolve scope
      const scope = await resolveScope(job);
      if (!scope.length) {
        await jobRef.update({
          status: 'completed',
          completedAt: new Date().toISOString(),
          ...totals,
          note: 'No matching class/section fee charts found.',
        });
        return;
      }

      // Roster expansion
      const rosters = await Promise.all(scope.map(cs =>
        listRoster(job.schoolId, cs.class, cs.section).then(r => ({ cs, roster: r }))
      ));
      totals.totalStudents = rosters.reduce((s, r) => s + r.roster.length, 0);
      await jobRef.update({ totalStudents: totals.totalStudents });

      // Build all demand docs up-front (memory-bounded — 1000 students × 40
      // heads = 40k docs × ~1KB = 40MB, well within 1GiB function memory).
      const allDocs = [];
      const studentTasks = [];
      rosters.forEach(({ cs, roster }) => {
        roster.forEach(student => {
          studentTasks.push({ cs, student });
        });
      });

      let lastProgressAt = 0;

      await mapLimit(studentTasks, MAX_CONCURRENCY, async (task, idx) => {
        try {
          const docs = buildDemandsForStudent(job, task.student, task.cs);
          allDocs.push(...docs);
          totals.successCount++;
        } catch (e) {
          totals.failureCount++;
          totals.errors.push({
            studentId: task.student.studentId,
            error: String(e.message || e),
            at: new Date().toISOString(),
          });
        } finally {
          totals.processedStudents++;
          if (totals.processedStudents - lastProgressAt >= PROGRESS_EVERY
              || totals.processedStudents === totals.totalStudents) {
            lastProgressAt = totals.processedStudents;
            await jobRef.update({
              processedStudents: totals.processedStudents,
              successCount: totals.successCount,
              failureCount: totals.failureCount,
              // Cap errors array at 100 to stay under Firestore 1MB doc limit.
              errors: totals.errors.slice(-100),
            }).catch(err => logger.warn('progress update failed', err));
          }
        }
      });

      // Filter out demands that already have payments (idempotency).
      const { toCreate, skipped } = await filterExisting(allDocs);
      totals.demandsSkipped = skipped;

      // Batch-commit writes, chunks of 400, up to MAX_CONCURRENCY in parallel.
      const batches = chunk(toCreate, BATCH_SIZE);
      logger.info('writing demand batches', {
        jobId: event.params.jobId,
        batches: batches.length,
        toCreate: toCreate.length,
        skipped,
      });

      let committed = 0;
      await mapLimit(batches, MAX_CONCURRENCY, async (group, gi) => {
        const batch = db.batch();
        group.forEach(d => {
          batch.set(db.collection('feeDemands').doc(d.id), d.data, { merge: true });
        });
        await commitBatch(batch);
        committed += group.length;
        // Progress ping after every batch group
        await jobRef.update({
          demandsCreated: committed,
          demandsSkipped: skipped,
        }).catch(err => logger.warn('batch progress update failed', err));
      });

      totals.demandsCreated = committed;

      await jobRef.update({
        status: 'completed',
        completedAt: new Date().toISOString(),
        processedStudents: totals.processedStudents,
        successCount: totals.successCount,
        failureCount: totals.failureCount,
        demandsCreated: totals.demandsCreated,
        demandsSkipped: totals.demandsSkipped,
        errors: totals.errors.slice(-100),
      });

      logger.info('job completed', {
        jobId: event.params.jobId,
        totals,
      });
    } catch (e) {
      logger.error('job failed', {
        jobId: event.params.jobId,
        error: String(e.stack || e.message || e),
      });
      await jobRef.update({
        status: 'failed',
        failedAt: new Date().toISOString(),
        failureReason: String(e.message || e).slice(0, 500),
        processedStudents: totals.processedStudents,
        successCount: totals.successCount,
        failureCount: totals.failureCount,
        demandsCreated: totals.demandsCreated,
        demandsSkipped: totals.demandsSkipped,
        errors: totals.errors.slice(-100),
      }).catch(() => {});
    }
  }
);
