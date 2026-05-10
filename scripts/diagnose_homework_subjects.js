#!/usr/bin/env node
/**
 * Diagnostic: print every subject in `subjectAssignments` for a given school,
 * grouped by class. Used to triage why the admin Homework subject dropdown
 * shows fewer options than expected.
 *
 *   node scripts/diagnose_homework_subjects.js
 *   node scripts/diagnose_homework_subjects.js --school=SCH_D94FE8F7AD
 *   node scripts/diagnose_homework_subjects.js --school=SCH_D94FE8F7AD --class="Class 8th"
 */
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

function arg(name, dflt) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  return eq ? eq.split('=').slice(1).join('=') : dflt;
}
const SCHOOL = arg('school', 'SCH_D94FE8F7AD');
const CLASS  = arg('class',  '');

(async () => {
  let q = fs.collection('subjectAssignments').where('schoolId', '==', SCHOOL);
  if (CLASS) q = q.where('className', '==', CLASS);
  const snap = await q.get();

  console.log(`subjectAssignments for school=${SCHOOL}${CLASS ? ` class="${CLASS}"` : ''}`);
  console.log(`Found ${snap.size} doc(s).\n`);

  const byClass = {};
  for (const d of snap.docs) {
    const className = d.get('className') || '(no className)';
    const section = d.get('section') || '';
    const subjectName = d.get('subjectName') || '(no subjectName)';
    const subjectCode = d.get('subjectCode') || '';
    const teacherName = d.get('teacherName') || '';
    const key = `${className} / ${section || '(class-wide)'}`;
    if (!byClass[key]) byClass[key] = [];
    byClass[key].push({ subjectName, subjectCode, teacherName, docId: d.id });
  }

  const keys = Object.keys(byClass).sort();
  if (!keys.length) { console.log('  (empty — no assignments at all)'); process.exit(0); }
  for (const k of keys) {
    console.log(`▸ ${k}`);
    for (const r of byClass[k]) {
      console.log(`    ${r.subjectName.padEnd(20)}  code=${r.subjectCode}  teacher=${r.teacherName}`);
    }
    console.log('');
  }

  const distinct = new Set();
  for (const arr of Object.values(byClass)) for (const r of arr) distinct.add(r.subjectName);
  console.log('Distinct subjects across this scope:');
  console.log('  ' + [...distinct].sort().join(', '));
  process.exit(0);
})().catch(e => { console.error('Fatal:', e); process.exit(1); });
