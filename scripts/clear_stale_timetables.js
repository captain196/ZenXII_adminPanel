#!/usr/bin/env node
/**
 * Deletes timetable docs for sections that have NO backing subjectAssignments.
 * These are "orphan" timetables — leftover from earlier test data or removed
 * assignments. They cause phantom conflicts because auto-generate skips them
 * (algorithm requires subjectAssignments to know what to place).
 *
 * Identifies stale sections by:
 *   1. Listing every (className, section) that has at least one timetable doc
 *   2. Listing every (className, section) that has at least one subjectAssignment
 *   3. The DIFF = stale sections
 *
 * Dry-run by default. --apply to commit.
 *
 *   node scripts/clear_stale_timetables.js
 *   node scripts/clear_stale_timetables.js --apply
 */
const path = require('path');
const crypto = require('crypto');

let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';
const APPLY = process.argv.includes('--apply');

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Clear stale timetable docs (no backing subjectAssignments)');
  console.log('  mode: ' + (APPLY ? 'APPLY' : 'DRY-RUN'));
  console.log('═══════════════════════════════════════════════════════════════');

  // ── 1. Sections that HAVE subjectAssignments ──
  const saSnap = await fs.collection('subjectAssignments')
    .where('schoolId', '==', SCHOOL).where('session', '==', SESSION).get();
  const sectionsWithAssignments = new Set();
  for (const d of saSnap.docs) {
    const data = d.data();
    const cls = data.className || '';
    const sec = data.section   || '';
    if (cls && sec) sectionsWithAssignments.add(cls + '/' + sec);
  }

  // ── 2. Sections that have timetable docs ──
  const ttSnap = await fs.collection('timetables')
    .where('schoolId', '==', SCHOOL).where('session', '==', SESSION).get();
  const ttDocsBySection = {};
  for (const d of ttSnap.docs) {
    const data = d.data();
    const cls = data.className || '';
    const sec = data.section   || '';
    const sk  = cls + '/' + sec;
    if (!ttDocsBySection[sk]) ttDocsBySection[sk] = [];
    ttDocsBySection[sk].push({ id: d.id, day: data.day || '?', ref: d.ref });
  }

  // ── 3. Find stale sections (have timetables, no assignments) ──
  const staleSections = [];
  for (const sk of Object.keys(ttDocsBySection)) {
    if (!sectionsWithAssignments.has(sk)) staleSections.push(sk);
  }

  console.log('\n  sections with subjectAssignments : ' + sectionsWithAssignments.size);
  console.log('  sections with timetable docs     : ' + Object.keys(ttDocsBySection).length);
  console.log('  STALE sections (timetable only)  : ' + staleSections.length);

  if (staleSections.length === 0) {
    console.log('\n  ✓ No stale sections. Nothing to clean.');
    return;
  }

  console.log('\n▸ Stale sections to clear:');
  let totalDocs = 0;
  for (const sk of staleSections.sort()) {
    const docs = ttDocsBySection[sk] || [];
    totalDocs += docs.length;
    console.log('  • ' + sk + '  (' + docs.length + ' day-doc(s))');
    for (const doc of docs) console.log('      - ' + doc.day + '  →  ' + doc.id);
  }
  console.log('\n  TOTAL timetable docs to delete: ' + totalDocs);

  if (!APPLY) {
    console.log('\n  DRY-RUN — re-run with --apply to commit deletes.');
    return;
  }

  // ── 4. Apply deletes (with simple audit log entry per section) ──
  console.log('\n▸ Applying deletes...');
  let deleted = 0, failed = 0;
  const ts = new Date().toISOString();

  for (const sk of staleSections) {
    for (const doc of ttDocsBySection[sk]) {
      try {
        await doc.ref.delete();
        deleted++;
        if (process.argv.includes('--verbose')) console.log('    ✓ deleted ' + doc.id);
      } catch (e) {
        failed++;
        console.error('    ✗ failed  ' + doc.id + '  ' + e.message);
      }
    }
    // Audit log entry per stale section cleared (1 entry per section, not per day)
    try {
      const compact = ts.replace(/[^0-9T]/g, '').slice(0, 15);
      const rand    = crypto.randomBytes(4).toString('hex');
      const logId   = SCHOOL + '_' + compact + '_' + rand;
      await fs.collection('academicAuditLog').doc(logId).set({
        logId, schoolId: SCHOOL, session: SESSION, ts, createdAt: ts,
        action: 'delete', entityType: 'timetable',
        entityId: SCHOOL + '_' + SESSION + '_' + sk.replace('/', '_'),
        actor: { uid: 'system_clear_stale', name: 'Stale-Timetable Cleanup', role: 'system' },
        before: { sectionKey: sk, dayCount: ttDocsBySection[sk].length },
        after: null,
        metadata: { reason: 'no backing subjectAssignments', daysCleared: ttDocsBySection[sk].length },
      });
    } catch (e) {
      console.error('    (audit log write failed for ' + sk + ': ' + e.message + ')');
    }
  }

  console.log('\n═══════════════════════════════════════════════════════════════');
  console.log('  Summary');
  console.log('  deleted: ' + deleted);
  console.log('  failed:  ' + failed);
  console.log('═══════════════════════════════════════════════════════════════');
  if (failed === 0) {
    console.log('  ✓ Stale timetables cleared. Reload Master Timetable — conflicts should drop to 0.');
  } else {
    console.log('  ⚠ Some deletes failed. Re-run --apply to retry.');
  }
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
