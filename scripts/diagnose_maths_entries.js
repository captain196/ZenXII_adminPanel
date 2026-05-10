#!/usr/bin/env node
/**
 * READ-ONLY diagnostic — lists every doc/cell in Firestore where the
 * subject is "Maths" (case-insensitive variants). Used to scope the
 * cleanup script. No writes.
 *
 *   node scripts/diagnose_maths_entries.js
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

const isMaths = (s) => typeof s === 'string' && s.trim().toLowerCase() === 'maths';

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Maths-vs-Mathematics diagnostic');
  console.log('  school: ' + SCHOOL + '  session: ' + SESSION);
  console.log('═══════════════════════════════════════════════════════════════');

  // ── 1. subjectAssignments with subject = "Maths" ──
  const sa = await fs.collection('subjectAssignments')
    .where('schoolId', '==', SCHOOL).where('session', '==', SESSION).get();
  const mathsAssignments = [];
  const mathematicsAssignments = [];
  for (const d of sa.docs) {
    const data = d.data();
    const name = data.subjectName || '';
    if (isMaths(name)) mathsAssignments.push({ id: d.id, ...data });
    else if (name.toLowerCase() === 'mathematics') mathematicsAssignments.push({ id: d.id, ...data });
  }

  console.log('\n▸ subjectAssignments using "Maths" (' + mathsAssignments.length + ')');
  for (const a of mathsAssignments) {
    console.log('  • ' + a.id);
    console.log('      class:    ' + (a.className || a.classKey || '?'));
    console.log('      section:  ' + (a.section || a.sectionKey || '_ALL_'));
    console.log('      teacher:  ' + (a.teacherName || '?') + ' (' + (a.teacherId || '?') + ')');
    console.log('      periods/week: ' + (a.periodsPerWeek || 0));
    console.log('      subjectCode:  ' + (a.subjectCode || '?'));
  }

  console.log('\n▸ subjectAssignments using "Mathematics" (' + mathematicsAssignments.length + ')');
  for (const a of mathematicsAssignments) {
    console.log('  • ' + a.id);
    console.log('      class:    ' + (a.className || a.classKey || '?'));
    console.log('      section:  ' + (a.section || a.sectionKey || '_ALL_'));
    console.log('      teacher:  ' + (a.teacherName || '?') + ' (' + (a.teacherId || '?') + ')');
  }

  // ── 2. timetables containing "Maths" cells ──
  const tt = await fs.collection('timetables')
    .where('schoolId', '==', SCHOOL).where('session', '==', SESSION).get();
  const ttsWithMaths = [];
  for (const d of tt.docs) {
    const data = d.data();
    const periods = Array.isArray(data.periods) ? data.periods : [];
    const mathCells = periods.filter(p => isMaths(p.subject));
    if (mathCells.length > 0) {
      ttsWithMaths.push({ id: d.id, day: data.day, sectionKey: data.sectionKey, mathsCells: mathCells.length });
    }
  }
  console.log('\n▸ timetable docs containing "Maths" cells (' + ttsWithMaths.length + ' docs)');
  let totalMathsCells = 0;
  for (const t of ttsWithMaths) {
    console.log('  • ' + t.sectionKey + ' / ' + t.day + '  →  ' + t.mathsCells + ' cell(s)');
    totalMathsCells += t.mathsCells;
  }
  console.log('  TOTAL "Maths" cells in timetables: ' + totalMathsCells);

  // ── 3. Recommended cleanup ──
  console.log('\n═══════════════════════════════════════════════════════════════');
  console.log('  RECOMMENDATION');
  console.log('═══════════════════════════════════════════════════════════════');
  if (mathsAssignments.length === 0 && totalMathsCells === 0) {
    console.log('  ✓ No "Maths" entries found — nothing to clean up.');
  } else {
    console.log('  ' + mathsAssignments.length + ' subjectAssignments + ' + totalMathsCells + ' timetable cells use "Maths".');
    console.log();
    console.log('  Two cleanup paths:');
    console.log();
    console.log('  A) RENAME "Maths" → "Mathematics" everywhere');
    console.log('     • Preserves the teacher-class-period relationship');
    console.log('     • Class 9th/10th continue having a math subject (just renamed)');
    console.log('     • Recommended if "Maths" was a naming mistake');
    console.log();
    console.log('  B) DELETE "Maths" assignments + cells entirely');
    console.log('     • Class 9th/10th LOSE their math subject (no replacement)');
    console.log('     • Periods become unallocated; need manual re-add as Mathematics');
    console.log('     • Use only if you want to re-create from scratch');
  }
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
