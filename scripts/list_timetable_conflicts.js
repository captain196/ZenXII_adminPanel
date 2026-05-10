#!/usr/bin/env node
/**
 * READ-ONLY — lists every actual teacher conflict in the live timetable
 * data: cases where the same teacherId is placed in 2+ sections at the
 * same (day, periodNumber). Plus reports which sections are involved
 * and whether they have subjectAssignments backing them.
 *
 *   node scripts/list_timetable_conflicts.js
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

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Timetable conflict diagnostic');
  console.log('═══════════════════════════════════════════════════════════════');

  // ── Pull every timetable doc ──
  const tt = await fs.collection('timetables')
    .where('schoolId', '==', SCHOOL).where('session', '==', SESSION).get();

  // Map: teacherBusy[teacherId][day][period] = [sectionKey, sectionKey, ...]
  const busy = {};
  let totalCells = 0, populatedCells = 0;
  const sectionDays = {}; // sectionKey → set of days seen (to verify completeness)

  for (const d of tt.docs) {
    const data = d.data();
    const sectionKey = data.sectionKey || '?';
    const day = data.day || '?';
    if (!sectionDays[sectionKey]) sectionDays[sectionKey] = new Set();
    sectionDays[sectionKey].add(day);

    const periods = Array.isArray(data.periods) ? data.periods : [];
    for (const p of periods) {
      totalCells++;
      const tid = p.teacherId || '';
      const subj = p.subject || '';
      if (tid === '' || subj === '') continue;
      populatedCells++;

      const periodNum = p.periodNumber || 0;
      if (!busy[tid]) busy[tid] = {};
      if (!busy[tid][day]) busy[tid][day] = {};
      if (!busy[tid][day][periodNum]) busy[tid][day][periodNum] = [];
      busy[tid][day][periodNum].push({ sectionKey, subject: subj, teacher: p.teacher });
    }
  }

  // ── Find conflicts ──
  const conflicts = [];
  for (const tid of Object.keys(busy)) {
    for (const day of Object.keys(busy[tid])) {
      for (const period of Object.keys(busy[tid][day])) {
        const placements = busy[tid][day][period];
        if (placements.length > 1) {
          conflicts.push({
            teacherId: tid,
            teacherName: placements[0].teacher,
            day,
            period: parseInt(period, 10),
            placements,
          });
        }
      }
    }
  }

  console.log('\n  timetable docs scanned : ' + tt.size);
  console.log('  total cells            : ' + totalCells);
  console.log('  populated cells        : ' + populatedCells);
  console.log('  ACTUAL conflicts found : ' + conflicts.length);
  console.log('═══════════════════════════════════════════════════════════════');

  if (conflicts.length === 0) {
    console.log('  ✓ No teacher conflicts in current timetable data.');
    return;
  }

  // Sort conflicts: by teacher, then day, then period
  const dayOrder = { Monday:1, Tuesday:2, Wednesday:3, Thursday:4, Friday:5, Saturday:6, Sunday:7 };
  conflicts.sort((a,b) => {
    if (a.teacherName !== b.teacherName) return a.teacherName.localeCompare(b.teacherName);
    if ((dayOrder[a.day]||9) !== (dayOrder[b.day]||9)) return (dayOrder[a.day]||9) - (dayOrder[b.day]||9);
    return a.period - b.period;
  });

  console.log('\n▸ Conflict list (teacher · day · period · sections):');
  for (const c of conflicts) {
    const sections = c.placements.map(p => p.sectionKey + ' (' + p.subject + ')').join('  +  ');
    console.log('  • ' + c.teacherName.padEnd(20) + ' ' + c.day.padEnd(10) + ' P' + c.period + '  →  ' + sections);
  }

  // ── Cross-check: which sections appear in conflicts? ──
  const conflictSections = new Set();
  for (const c of conflicts) for (const p of c.placements) conflictSections.add(p.sectionKey);

  // ── Which sections have subjectAssignments? ──
  const saSnap = await fs.collection('subjectAssignments')
    .where('schoolId', '==', SCHOOL).where('session', '==', SESSION).get();
  const sectionHasAssignments = new Set();
  for (const d of saSnap.docs) {
    const data = d.data();
    const cls = data.className || '';
    const sec = data.section || '';
    if (cls && sec) sectionHasAssignments.add(cls + '/' + sec);
  }

  console.log('\n▸ Sections involved in conflicts:');
  for (const sk of [...conflictSections].sort()) {
    const hasAssignments = sectionHasAssignments.has(sk);
    console.log('  ' + (hasAssignments ? '✓' : '✗') + ' ' + sk + (hasAssignments ? '' : '  ← NO subjectAssignments (cells are STALE)'));
  }

  // ── Summary recommendation ──
  console.log('\n═══════════════════════════════════════════════════════════════');
  console.log('  RECOMMENDATION');
  console.log('═══════════════════════════════════════════════════════════════');
  const staleSections = [...conflictSections].filter(sk => !sectionHasAssignments.has(sk));
  if (staleSections.length > 0) {
    console.log('  ' + staleSections.length + ' conflict-causing section(s) have NO subjectAssignments.');
    console.log('  Auto-generate will SKIP them (algorithm requires assignments).');
    console.log('  → Either DELETE those stale timetable docs, OR add subjectAssignments for them.');
    console.log();
    console.log('  Stale sections to consider deleting:');
    for (const s of staleSections) console.log('    ' + s);
  } else {
    console.log('  All conflicting sections have subjectAssignments.');
    console.log('  → Re-run auto-generate with force_overwrite=1 should resolve conflicts.');
  }
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
