#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Phase 8 — Analytics drift detector  (read-only)
 *
 *  Compares CURRENT counters in `subjectPlanProgress` and
 *  `dailyTeacherMonitoring` against a fresh recomputation from
 *  `lessonPlans` (the source of truth). Reports:
 *
 *    • per-summary-doc divergence (which counters are wrong, by how much)
 *    • aggregate % drift across the school (counters wrong / counters total)
 *    • exit code  0   if drift ≤ 1%   (clean)
 *                 1   if drift  > 1%  (alert — wire to cron mailer)
 *                 2   on hard error
 *
 *  Reuses scripts/lib/analytics_helpers.js so the recomputation logic is
 *  identical to rebuild_analytics_summaries.js. Never writes Firestore.
 *
 *    node scripts/detect_analytics_drift.js
 *    node scripts/detect_analytics_drift.js --verbose
 *    SCHOOL=SCH_X SESSION=2026-27 node scripts/detect_analytics_drift.js
 *    node scripts/detect_analytics_drift.js --threshold=0.5  # 0.5%
 *    node scripts/detect_analytics_drift.js --json           # machine-readable
 * ═══════════════════════════════════════════════════════════════════
 */

const path = require('path');

let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

const { computeSummariesFromPlans, fetchPlansForSession } = require('./lib/analytics_helpers');

const SCHOOL    = process.env.SCHOOL  || 'SCH_D94FE8F7AD';
const SESSION   = process.env.SESSION || '2026-27';
const VERBOSE   = process.argv.includes('--verbose');
const JSON_OUT  = process.argv.includes('--json');
const thresholdArg = process.argv.find(a => a.startsWith('--threshold='));
const THRESHOLD = thresholdArg ? parseFloat(thresholdArg.split('=')[1]) : 1.0;  // %

const SUBJECT_COUNTERS = ['totalPlans', 'plannedCount', 'completedCount', 'skippedCount', 'rescheduledCount', 'percentComplete'];
const DAILY_COUNTERS   = ['plansSaved', 'plannedCount', 'completedCount', 'skippedCount', 'rescheduledCount'];

// ── helpers ─────────────────────────────────────────────────────────

function bold(s){return JSON_OUT?s:`\x1b[1m${s}\x1b[0m`}
function red(s){return JSON_OUT?s:`\x1b[31m${s}\x1b[0m`}
function yel(s){return JSON_OUT?s:`\x1b[33m${s}\x1b[0m`}
function grn(s){return JSON_OUT?s:`\x1b[32m${s}\x1b[0m`}
function dim(s){return JSON_OUT?s:`\x1b[2m${s}\x1b[0m`}
function log(...args){ if (!JSON_OUT) console.log(...args); }

/** Tolerance for percentComplete only (rounded to 0.1). */
function counterMismatch(field, expected, actual) {
  expected = Number(expected ?? 0);
  actual   = Number(actual   ?? 0);
  if (field === 'percentComplete') {
    return Math.abs(expected - actual) > 0.15;  // 0.1 rounding + small float slack
  }
  return expected !== actual;
}

async function fetchSummaryDocs(collection) {
  const snap = await fs.collection(collection)
    .where('schoolId', '==', SCHOOL)
    .where('session',  '==', SESSION)
    .get();
  const out = {};
  for (const d of snap.docs) out[d.id] = d.data();
  return out;
}

// ── main ────────────────────────────────────────────────────────────

(async () => {
  log('═══════════════════════════════════════════════════════════════');
  log('  Phase 8 — Analytics drift detector  (read-only)');
  log('  school    : ' + SCHOOL + '   session: ' + SESSION);
  log('  threshold : ' + THRESHOLD + '%');
  log('═══════════════════════════════════════════════════════════════');

  // 1. Pull source plans + recompute via shared helper
  const plans = await fetchPlansForSession(fs, { schoolId: SCHOOL, session: SESSION });
  const expected = computeSummariesFromPlans(plans, { schoolId: SCHOOL, session: SESSION });
  log('  scanned lessonPlans   : ' + plans.length);

  // 2. Pull current summary docs
  const liveSubject = await fetchSummaryDocs('subjectPlanProgress');
  const liveDaily   = await fetchSummaryDocs('dailyTeacherMonitoring');
  log('  live subjectPlanProgress docs   : ' + Object.keys(liveSubject).length);
  log('  live dailyTeacherMonitoring docs: ' + Object.keys(liveDaily).length);

  // 3. Diff each summary collection
  const issues = {
    subjectPlanProgress:    [],
    dailyTeacherMonitoring: [],
    missingDocs:            [],   // expected docs not in live
    orphanDocs:             [],   // live docs that no longer have any plans
  };
  let countersChecked = 0, countersDiverged = 0;

  const diff = (collection, expectedAgg, liveDocs, fieldList) => {
    // Forward: expected → live
    for (const id of Object.keys(expectedAgg)) {
      const exp = expectedAgg[id];
      const live = liveDocs[id];
      if (!live) {
        issues.missingDocs.push({ collection, docId: id, expected: exp });
        countersChecked  += fieldList.length;
        countersDiverged += fieldList.length;
        continue;
      }
      for (const f of fieldList) {
        countersChecked++;
        if (counterMismatch(f, exp[f], live[f])) {
          countersDiverged++;
          issues[collection].push({
            docId:    id,
            field:    f,
            expected: Number(exp[f] ?? 0),
            actual:   Number(live[f] ?? 0),
          });
        }
      }
    }
    // Backward: live docs that have no source plans → orphans
    for (const id of Object.keys(liveDocs)) {
      if (!expectedAgg[id]) {
        // Only flag as orphan if the live doc actually has non-zero counters
        const ld = liveDocs[id];
        const hasData = fieldList.some(f => Number(ld[f] ?? 0) > 0);
        if (hasData) {
          issues.orphanDocs.push({ collection, docId: id, current: ld });
          countersChecked  += fieldList.length;
          countersDiverged += fieldList.length;
        }
      }
    }
  };
  diff('subjectPlanProgress',    expected.subjectAgg, liveSubject, SUBJECT_COUNTERS);
  diff('dailyTeacherMonitoring', expected.dailyAgg,   liveDaily,   DAILY_COUNTERS);

  // 4. Compute percentages + verdict
  const driftPct = countersChecked > 0
    ? +(countersDiverged / countersChecked * 100).toFixed(2)
    : 0;
  const status = (driftPct <= THRESHOLD) ? 'clean' : 'alert';

  // 5. Output
  if (JSON_OUT) {
    console.log(JSON.stringify({
      schoolId:        SCHOOL,
      session:         SESSION,
      thresholdPct:    THRESHOLD,
      countersChecked,
      countersDiverged,
      driftPct,
      status,
      issues,
      timestamp:       new Date().toISOString(),
    }, null, 2));
  } else {
    log('\n  counters checked  : ' + countersChecked);
    log('  counters diverged : ' + countersDiverged);
    log('  drift             : ' + (status === 'alert' ? red(driftPct + '%') : grn(driftPct + '%')));
    log('  threshold         : ' + THRESHOLD + '%');

    const total = issues.subjectPlanProgress.length + issues.dailyTeacherMonitoring.length;
    if (total > 0 || issues.missingDocs.length > 0 || issues.orphanDocs.length > 0) {
      log('\n  ' + bold('Mismatches:'));
      if (issues.missingDocs.length > 0) {
        log('    ' + yel('Missing summary docs (recompute would create):'));
        for (const m of issues.missingDocs.slice(0, VERBOSE ? 100 : 5))
          log('      • ' + m.collection + '/' + m.docId);
        if (!VERBOSE && issues.missingDocs.length > 5)
          log('      … +' + (issues.missingDocs.length - 5) + ' more (use --verbose)');
      }
      if (issues.orphanDocs.length > 0) {
        log('    ' + yel('Orphan summary docs (no source plans):'));
        for (const o of issues.orphanDocs.slice(0, VERBOSE ? 100 : 5))
          log('      • ' + o.collection + '/' + o.docId);
        if (!VERBOSE && issues.orphanDocs.length > 5)
          log('      … +' + (issues.orphanDocs.length - 5) + ' more (use --verbose)');
      }
      for (const collection of ['subjectPlanProgress', 'dailyTeacherMonitoring']) {
        if (issues[collection].length === 0) continue;
        log('    ' + yel('Counter mismatches in ' + collection + ':'));
        for (const i of issues[collection].slice(0, VERBOSE ? 100 : 10)) {
          log('      • ' + i.docId + '  ' + i.field
              + dim('  expected=') + i.expected
              + dim('  actual=')   + i.actual);
        }
        if (!VERBOSE && issues[collection].length > 10)
          log('      … +' + (issues[collection].length - 10) + ' more (use --verbose)');
      }
    }

    log('\n═══════════════════════════════════════════════════════════════');
    if (status === 'clean') {
      log(grn(bold('  ✔ DRIFT WITHIN THRESHOLD')));
    } else {
      log(red(bold('  ✗ DRIFT EXCEEDS THRESHOLD — run rebuild_analytics_summaries.js --apply')));
    }
    log('═══════════════════════════════════════════════════════════════');
  }

  // Exit codes for cron alerting:  0 clean | 1 drift alert
  process.exit(status === 'clean' ? 0 : 1);
})().catch(e => {
  console.error('FATAL:', e);
  process.exit(2);   // hard error — distinct from drift alert
});
