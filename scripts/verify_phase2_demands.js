// Post-generation audit. Confirms every student has its own unique
// demand docs and there's no ID collision between students.
//
//   node scripts/verify_phase2_demands.js

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL  = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';

(async () => {
  const snap = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL)
    .where('session',  '==', SESSION)
    .get();

  console.log(`\nTotal demand docs: ${snap.size}\n`);

  const perStudent  = new Map();     // studentId -> count
  const docIds      = new Map();     // doc ID prefix -> sample count
  const idPatterns  = { new: 0, old: 0 };

  snap.forEach(d => {
    const x = d.data() || {};
    const sid = String(x.studentId || x.student_id || '?');
    perStudent.set(sid, (perStudent.get(sid) || 0) + 1);

    // Check doc ID format: new = DEM_{studentId}_..., old = DEM_{yyyymm}_{HEAD}
    const id = d.id;
    if (id.startsWith(`DEM_${sid}_`)) idPatterns.new++;
    else if (/^DEM_\d{6}_/.test(id))  idPatterns.old++;
  });

  // Per-student breakdown
  console.log('Per-student demand counts:');
  [...perStudent.entries()].sort().forEach(([sid, n]) => {
    console.log(`  ${sid.padEnd(20)} — ${n} demands`);
  });

  console.log(`\nDoc ID format:\n  new format (includes studentId): ${idPatterns.new}\n  old format (collision risk):     ${idPatterns.old}`);

  // Defaulters
  const defs = await db.collection('feeDefaulters')
    .where('schoolId', '==', SCHOOL)
    .where('session',  '==', SESSION)
    .get();
  console.log(`\nfeeDefaulters docs: ${defs.size}`);
  [...defs.docs].sort((a,b) => a.id.localeCompare(b.id)).forEach(d => {
    const x = d.data() || {};
    console.log(`  ${x.studentId} — dues ₹${x.totalDues || 0}  · class ${x.className || '?'} / ${x.section || '?'}`);
  });

  // Verdict
  const expectStudents = 10;
  const expectDemandsPerStudent = 37;   // 3 monthly × 12 + 1 annual
  const pass = perStudent.size === expectStudents
            && [...perStudent.values()].every(n => n === expectDemandsPerStudent)
            && idPatterns.old === 0
            && defs.size === expectStudents;

  console.log(`\n${'='.repeat(50)}`);
  console.log(pass ? '✅  PHASE 2 VERIFICATION: PASS' : '❌  PHASE 2 VERIFICATION: FAIL');
  console.log('='.repeat(50));
})().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
