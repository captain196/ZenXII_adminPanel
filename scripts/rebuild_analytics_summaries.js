#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Phase 8 — Analytics summary rebuild
 *
 *  Recomputes both summary collections from scratch by scanning every
 *  doc in `lessonPlans`. Use cases:
 *    • initial bootstrap of summaries on existing data
 *    • drift recovery if the saveLessonPlan hook missed updates
 *      (e.g. teacher app writes that don't go through PHP yet)
 *    • nightly cron — schedule via cron / task scheduler
 *
 *  Idempotent. Dry-run by default; --apply commits the writes.
 *
 *    node scripts/rebuild_analytics_summaries.js
 *    node scripts/rebuild_analytics_summaries.js --apply
 *    node scripts/rebuild_analytics_summaries.js --apply --verbose
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

const SCHOOL  = process.env.SCHOOL  || 'SCH_D94FE8F7AD';
const SESSION = process.env.SESSION || '2026-27';
const APPLY   = process.argv.includes('--apply');
const VERBOSE = process.argv.includes('--verbose');

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Phase 8 — Analytics summary rebuild');
  console.log('  school : ' + SCHOOL + '   session: ' + SESSION);
  console.log('  mode   : ' + (APPLY ? 'APPLY (will write)' : 'DRY-RUN'));
  console.log('═══════════════════════════════════════════════════════════════');

  // ── 1. Pull all lesson plans for this school+session ──
  const plans = await fetchPlansForSession(fs, { schoolId: SCHOOL, session: SESSION });
  console.log('  scanned lessonPlans: ' + plans.length);

  // ── 2. Aggregate via shared helper (same logic as drift detector) ──
  const { subjectAgg, subjectMeta, dailyAgg, dailyMeta, skippedPlans } =
    computeSummariesFromPlans(plans, { schoolId: SCHOOL, session: SESSION });

  console.log('  subjectPlanProgress docs to write : ' + Object.keys(subjectAgg).length);
  console.log('  dailyTeacherMonitoring docs       : ' + Object.keys(dailyAgg).length);
  if (skippedPlans > 0) console.log('  ⚠ skipped ' + skippedPlans + ' plans missing required fields');

  if (!APPLY) {
    console.log('\n  DRY-RUN — re-run with --apply to commit.');
    if (VERBOSE) {
      console.log('\n  Sample subjectPlanProgress:');
      for (const id of Object.keys(subjectAgg).slice(0, 3))
        console.log('    ' + id + ' →', subjectAgg[id]);
      console.log('\n  Sample dailyTeacherMonitoring:');
      for (const id of Object.keys(dailyAgg).slice(0, 3))
        console.log('    ' + id + ' →', dailyAgg[id]);
    }
    return;
  }

  const now = new Date().toISOString();
  let written = 0, failed = 0;

  // Firestore batch limit = 500. Chunk both collections.
  const writeAll = async (collection, items, metaMap) => {
    const ids = Object.keys(items);
    for (let i = 0; i < ids.length; i += 450) {
      const chunk = ids.slice(i, i + 450);
      const batch = fs.batch();
      for (const id of chunk) {
        const data = Object.assign({
          schoolId: SCHOOL, session: SESSION,
          lastUpdatedAt: now,
        }, metaMap[id], items[id]);
        batch.set(fs.collection(collection).doc(id), data, { merge: true });
      }
      try {
        await batch.commit();
        written += chunk.length;
        if (VERBOSE) console.log('    ✓ committed ' + chunk.length + ' to ' + collection);
      } catch (e) {
        failed += chunk.length;
        console.error('    ✗ batch failed in ' + collection + ': ' + e.message);
      }
    }
  };

  await writeAll('subjectPlanProgress',   subjectAgg, subjectMeta);
  await writeAll('dailyTeacherMonitoring', dailyAgg,  dailyMeta);

  console.log('\n═══════════════════════════════════════════════════════════════');
  console.log('  Summary');
  console.log('  written: ' + written);
  console.log('  failed : ' + failed);
  console.log('═══════════════════════════════════════════════════════════════');
  if (failed === 0) console.log('  ✓ Analytics summaries rebuilt successfully.');
  else              console.log('  ⚠ Some writes failed — re-run --apply to retry.');
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
