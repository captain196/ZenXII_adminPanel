#!/usr/bin/env node
/**
 * Normalises subject naming: "Maths" → "Mathematics" across:
 *   - subjectAssignments (3 docs in your data)
 *   - timetables (12 cells)
 *
 * MODES:
 *   --mode=rename (default) — keeps assignments + cells, just renames subject
 *   --mode=delete           — deletes the 3 Maths assignments + clears 12 cells
 *
 * Dry-run by default. --apply to commit.
 *
 *   node scripts/fix_maths_to_mathematics.js                   # preview, mode=rename
 *   node scripts/fix_maths_to_mathematics.js --apply           # commit rename
 *   node scripts/fix_maths_to_mathematics.js --mode=delete     # preview delete
 *   node scripts/fix_maths_to_mathematics.js --mode=delete --apply
 */
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';
const FROM = 'Maths';
const TO   = 'Mathematics';

function arg(name, dflt) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  return eq ? eq.split('=').slice(1).join('=') : dflt;
}
const APPLY = process.argv.includes('--apply');
const MODE  = arg('mode', 'rename');
if (!['rename', 'delete'].includes(MODE)) {
  console.error('--mode must be rename or delete');
  process.exit(2);
}

const isMaths = (s) => typeof s === 'string' && s.trim().toLowerCase() === FROM.toLowerCase();

(async () => {
  const ts = new Date().toISOString();
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Maths → Mathematics cleanup');
  console.log('  mode:  ' + (MODE === 'rename' ? 'RENAME (safe)' : 'DELETE (destructive)'));
  console.log('  state: ' + (APPLY ? 'APPLY' : 'DRY-RUN'));
  console.log('═══════════════════════════════════════════════════════════════');

  let saWrites = 0, ttWrites = 0, ttCellsTouched = 0;

  // ── 1. subjectAssignments ──
  const saSnap = await fs.collection('subjectAssignments')
    .where('schoolId', '==', SCHOOL).where('session', '==', SESSION).get();
  for (const d of saSnap.docs) {
    const data = d.data();
    if (!isMaths(data.subjectName || '')) continue;

    if (MODE === 'rename') {
      console.log('  [SA rename] ' + d.id);
      console.log('    subjectName: "' + data.subjectName + '" → "' + TO + '"');
      if (APPLY) {
        await d.ref.update({
          subjectName:   TO,
          updatedAt:     ts,
          updatedByUid:  'system_maths_normalize',
          updatedByName: 'Maths→Mathematics normalisation',
        });
      }
      saWrites++;
    } else {
      console.log('  [SA delete] ' + d.id);
      console.log('    Class ' + (data.className || '?') + ' / ' + (data.section || '?') + ' (' + (data.subjectCode || '?') + ') by ' + (data.teacherName || '?'));
      console.log('    ⚠ Class loses its math subject; periods/week=' + (data.periodsPerWeek || 0) + ' become unallocated');
      if (APPLY) await d.ref.delete();
      saWrites++;
    }
  }
  if (saWrites === 0) console.log('  (no subjectAssignments docs match)');

  // ── 2. timetables ──
  const ttSnap = await fs.collection('timetables')
    .where('schoolId', '==', SCHOOL).where('session', '==', SESSION).get();
  for (const d of ttSnap.docs) {
    const data = d.data();
    const periods = Array.isArray(data.periods) ? data.periods : [];
    let touched = false;
    const newPeriods = periods.map(p => {
      if (!isMaths(p.subject)) return p;
      touched = true;
      ttCellsTouched++;
      if (MODE === 'rename') {
        return { ...p, subject: TO };
      } else {
        return { ...p, subject: '', teacher: '', teacherId: '', teacher_id: '' };
      }
    });
    if (!touched) continue;

    console.log('  [TT ' + MODE + '] ' + (data.sectionKey || d.id) + ' / ' + (data.day || '?'));
    if (APPLY) {
      await d.ref.update({
        periods:       newPeriods,
        updatedAt:     ts,
        updatedByUid:  'system_maths_normalize',
        updatedByName: 'Maths→Mathematics normalisation',
      });
    }
    ttWrites++;
  }
  if (ttWrites === 0) console.log('  (no timetable docs need touching)');

  console.log();
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Summary');
  console.log('  subjectAssignments touched: ' + saWrites);
  console.log('  timetable docs touched:     ' + ttWrites);
  console.log('  timetable cells changed:    ' + ttCellsTouched);
  console.log('═══════════════════════════════════════════════════════════════');
  if (!APPLY) console.log('  DRY-RUN — re-run with --apply to commit.');
  else        console.log('  Applied. Reload Master Timetable — "Maths" pill should be gone.');
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
