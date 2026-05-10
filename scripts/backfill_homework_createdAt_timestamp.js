#!/usr/bin/env node
/**
 * Convert legacy `homework.createdAt` from epoch-millis (Long) to a real
 * Firestore Timestamp.
 *
 * The admin's Homework controller used to write `createdAt` as a JS number,
 * while the Teacher app writes a Firestore Timestamp. Firestore sorts those
 * in separate type buckets (numbers vs. timestamps), so admin-created homework
 * always sorted at the bottom of the Teacher app's "Recent" list — even when
 * its actual creation time was newer.
 *
 * The PHP write path was fixed in 2026-05-06; this script is a one-shot to
 * normalise all existing docs so older admin homework moves into the same
 * sort bucket as Teacher writes.
 *
 * USAGE:
 *   node scripts/backfill_homework_createdAt_timestamp.js                    # dry run, all schools
 *   node scripts/backfill_homework_createdAt_timestamp.js --apply            # commit
 *   node scripts/backfill_homework_createdAt_timestamp.js --school=SCH_X --apply
 *
 * Idempotent — docs whose createdAt is already a Timestamp are skipped.
 */
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

const APPLY = process.argv.includes('--apply');
function arg(name, dflt) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  return eq ? eq.split('=').slice(1).join('=') : dflt;
}
const SCHOOL_FILTER = arg('school', '');

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  homework.createdAt → Timestamp backfill');
  console.log('  scope: ' + (SCHOOL_FILTER ? `school=${SCHOOL_FILTER}` : 'ALL schools'));
  console.log('  state: ' + (APPLY ? 'APPLY (writes will commit)' : 'DRY-RUN (no writes)'));
  console.log('═══════════════════════════════════════════════════════════════');

  let q = fs.collection('homework');
  if (SCHOOL_FILTER) q = q.where('schoolId', '==', SCHOOL_FILTER);

  const snap = await q.get();
  console.log(`Scanned ${snap.size} homework doc(s).`);

  let alreadyOk = 0;
  let toFix = [];
  let skipped = [];

  for (const d of snap.docs) {
    const ca = d.get('createdAt');
    if (ca instanceof admin.firestore.Timestamp) {
      alreadyOk++;
      continue;
    }
    if (typeof ca === 'number') {
      // Epoch millis if > 1e12, else treat as seconds
      const ms = ca > 1_000_000_000_000 ? ca : ca * 1000;
      toFix.push({ ref: d.ref, id: d.id, ms, school: d.get('schoolId') || d.get('schoolCode') || '?' });
      continue;
    }
    if (typeof ca === 'string' && ca.trim() !== '') {
      const parsed = Date.parse(ca);
      if (!Number.isNaN(parsed)) {
        toFix.push({ ref: d.ref, id: d.id, ms: parsed, school: d.get('schoolId') || d.get('schoolCode') || '?' });
        continue;
      }
    }
    skipped.push({ id: d.id, type: typeof ca, value: ca });
  }

  console.log(`  already Timestamp:   ${alreadyOk}`);
  console.log(`  to convert:          ${toFix.length}`);
  console.log(`  skipped (unknown):   ${skipped.length}`);
  if (skipped.length) {
    console.log('  ─ skipped sample:');
    skipped.slice(0, 5).forEach(s => console.log(`     ${s.id}  (${s.type}) ${JSON.stringify(s.value)}`));
  }
  if (!toFix.length) {
    console.log('Nothing to do.');
    process.exit(0);
  }

  console.log('\nFirst 10 conversions preview:');
  toFix.slice(0, 10).forEach(t => {
    const iso = new Date(t.ms).toISOString();
    console.log(`  ${t.id}  school=${t.school}  ${t.ms} → ${iso}`);
  });

  if (!APPLY) {
    console.log('\nDRY-RUN. Re-run with --apply to commit.');
    process.exit(0);
  }

  // Batch in chunks of 400 (Firestore limit is 500/batch — leave headroom).
  console.log('\nCommitting…');
  let committed = 0;
  for (let i = 0; i < toFix.length; i += 400) {
    const slice = toFix.slice(i, i + 400);
    const batch = fs.batch();
    for (const t of slice) {
      const ts = admin.firestore.Timestamp.fromMillis(t.ms);
      batch.update(t.ref, { createdAt: ts });
    }
    await batch.commit();
    committed += slice.length;
    process.stdout.write(`  committed ${committed}/${toFix.length}\r`);
  }
  console.log(`\nDone. Updated ${committed} doc(s).`);
  process.exit(0);
})().catch(err => {
  console.error('Fatal:', err);
  process.exit(1);
});
