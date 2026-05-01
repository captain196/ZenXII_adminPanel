// One-shot: compute + write feeDefaulters docs from existing feeDemands.
// Use when demands exist but defaulter snapshot is missing (e.g., after
// a data reset + demand generation without the defaulter-sync step).
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL  = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';

(async () => {
  // Group demands by studentId
  const dSnap = await db.collection('feeDemands')
    .where('schoolId','==',SCHOOL).where('session','==',SESSION).get();
  const byStudent = new Map();
  dSnap.forEach(doc => {
    const d = doc.data() || {};
    const sid = String(d.studentId || '');
    if (!sid) return;
    const list = byStudent.get(sid) || [];
    list.push(d);
    byStudent.set(sid, list);
  });
  console.log(`Students with demands: ${byStudent.size}`);

  const today = new Date().toISOString().slice(0,10);
  let written = 0, cleared = 0;

  for (const [sid, demands] of byStudent) {
    const first = demands[0];
    const className = first.className || '';
    const section   = first.section || '';
    const studentName = first.studentName || sid;
    const fatherName  = first.fatherName || '';

    let totalDues = 0;
    const unpaidMonths = new Set();
    const overdueMonths = new Set();
    demands.forEach(d => {
      const bal = Number(d.balance || 0);
      const status = String(d.status || '').toLowerCase();
      if (bal > 0.005 && status !== 'paid') {
        totalDues += bal;
        if (d.month) unpaidMonths.add(d.month);
        if (d.due_date && d.due_date < today) overdueMonths.add(d.month || '');
      }
    });
    totalDues = Math.round(totalDues * 100) / 100;

    const docId = `${SCHOOL}_${SESSION}_${sid}`;
    const doc = {
      schoolId: SCHOOL,
      session: SESSION,
      studentId: sid,
      studentName,
      fatherName,
      className,
      section,
      totalDues,
      unpaidMonths: Array.from(unpaidMonths),
      overdueMonths: Array.from(overdueMonths),
      isDefaulter: totalDues > 0.005,
      examBlocked: false,       // policy-driven; admin decides
      resultWithheld: false,
      updatedAt: new Date().toISOString(),
      source: 'backfill_script',
    };
    await db.collection('feeDefaulters').doc(docId).set(doc, { merge: true });
    if (totalDues > 0.005) written++;
    else cleared++;
  }
  console.log(`\nWrote ${written + cleared} defaulter docs: ${written} with dues, ${cleared} cleared.`);
})().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
