/**
 * scripts/lib/analytics_helpers.js
 *
 * Shared aggregation helper used by:
 *   • scripts/rebuild_analytics_summaries.js  (writes summaries)
 *   • scripts/detect_analytics_drift.js       (compares without writing)
 *
 * Produces the EXACT same field shape that PHP's
 * Lesson_plan_service::_updateAnalyticsSummariesBestEffort writes, so a
 * recomputation here is the canonical "ground truth" for a given set of
 * lessonPlans. Any divergence between this output and live summary docs
 * is real drift — not a definition mismatch.
 *
 * NEVER add domain logic here that isn't already in the PHP hook + the
 * rebuild script. This file is the single source of analytics shape; the
 * drift detector relies on that.
 */

const VALID_STATUSES = ['planned', 'completed', 'skipped', 'rescheduled'];

/** Slug for docId components — replaces spaces and slashes with underscores.
 *  Mirrors PHP's str_replace([' ', '/'], '_', ...) used in the hook. */
const slug = (s) => String(s || '').replace(/[\s/]/g, '_');

/** Sanitise a teacherId for inclusion in a docId (matches PHP regex). */
const tidSlug = (tid) => String(tid || '').replace(/[^A-Za-z0-9_-]/g, '_');

/**
 * Aggregate plan docs into the two summary structures.
 *
 * @param {Array<object>}   plans       Plan documents (any session — caller filters).
 * @param {object}          options
 * @param {string}          options.schoolId
 * @param {string}          options.session
 *
 * @returns {{
 *   subjectAgg:  Object<string, {totalPlans:number, plannedCount:number, completedCount:number, skippedCount:number, rescheduledCount:number, percentComplete:number}>,
 *   subjectMeta: Object<string, {className:string, section:string, classSection:string, subject:string}>,
 *   dailyAgg:    Object<string, {plansSaved:number, plannedCount:number, completedCount:number, skippedCount:number, rescheduledCount:number}>,
 *   dailyMeta:   Object<string, {date:string, teacherId:string, teacherName:string}>,
 *   skippedPlans: number,
 * }}
 */
function computeSummariesFromPlans(plans, { schoolId, session }) {
  if (!schoolId || !session) {
    throw new Error('computeSummariesFromPlans: schoolId and session are required');
  }

  const subjectAgg  = {};
  const subjectMeta = {};
  const dailyAgg    = {};
  const dailyMeta   = {};
  let skippedPlans  = 0;

  for (const d of plans) {
    if (!d || typeof d !== 'object') { skippedPlans++; continue; }

    const status = String(d.status || 'planned');
    const cs     = String(d.classSection || '');
    const subj   = String(d.subject || '');
    const date   = String(d.date || '');
    const tid    = String(d.teacherId || '');

    if (!cs || !subj || !date || !tid) { skippedPlans++; continue; }

    const progId = `${schoolId}_${session}_${slug(cs)}_${slug(subj)}`;
    const monId  = `${schoolId}_${session}_${date}_${tidSlug(tid)}`;
    const statusKey = `${status}Count`;

    // ── subjectPlanProgress aggregation ──
    if (!subjectAgg[progId]) {
      subjectAgg[progId] = {
        totalPlans:       0,
        plannedCount:     0,
        completedCount:   0,
        skippedCount:     0,
        rescheduledCount: 0,
      };
      subjectMeta[progId] = {
        className:    String(d.className || ''),
        section:      String(d.section   || ''),
        classSection: cs,
        subject:      subj,
      };
    }
    subjectAgg[progId].totalPlans++;
    if (statusKey in subjectAgg[progId]) subjectAgg[progId][statusKey]++;

    // ── dailyTeacherMonitoring aggregation ──
    if (!dailyAgg[monId]) {
      dailyAgg[monId] = {
        plansSaved:       0,
        plannedCount:     0,
        completedCount:   0,
        skippedCount:     0,
        rescheduledCount: 0,
      };
      dailyMeta[monId] = {
        date,
        teacherId:   tid,
        teacherName: String(d.teacherName || ''),
      };
    }
    dailyAgg[monId].plansSaved++;
    if (statusKey in dailyAgg[monId]) dailyAgg[monId][statusKey]++;
  }

  // Compute percentComplete for subject agg (mirrors PHP rounding: 0.1 precision)
  for (const id of Object.keys(subjectAgg)) {
    const a = subjectAgg[id];
    a.percentComplete = a.totalPlans > 0
      ? Math.round((a.completedCount / a.totalPlans) * 1000) / 10
      : 0.0;
  }

  return { subjectAgg, subjectMeta, dailyAgg, dailyMeta, skippedPlans };
}

/**
 * Pull every lessonPlan doc for one (schoolId, session) and return the
 * raw data array. Convenience wrapper used by both consumers.
 *
 * @param {FirebaseFirestore} fs Firestore admin client
 */
async function fetchPlansForSession(fs, { schoolId, session }) {
  const snap = await fs.collection('lessonPlans')
    .where('schoolId', '==', schoolId)
    .where('session',  '==', session)
    .get();
  return snap.docs.map(d => d.data());
}

module.exports = {
  computeSummariesFromPlans,
  fetchPlansForSession,
  slug,
  tidSlug,
  VALID_STATUSES,
};
