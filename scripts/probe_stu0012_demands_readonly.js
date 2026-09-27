/**
 * READ-ONLY. Prints the live open fee demands for one student so the
 * pre-payment state is read from Firestore rather than assumed from a
 * runbook. Makes no writes and prints no credential.
 *
 *   node scripts/probe_stu0012_demands_readonly.js
 *   node scripts/probe_stu0012_demands_readonly.js --school=SCH_X --student=STU0001
 */
const admin = require('firebase-admin');
const path  = require('path');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};
const SCHOOL  = arg('school', 'SCH_B56BB9A401');
const STUDENT = arg('student', 'STU0012');

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const num = (v) => (typeof v === 'number' ? v : Number(v || 0));

(async () => {
  // Tenant-scoped, as every read in this codebase must be.
  const snap = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL)
    .where('studentId', '==', STUDENT)
    .get();

  const rows = [];
  snap.forEach((d) => {
    const x = d.data();
    const total = num(x.netAmount != null ? x.netAmount : x.amount);
    const paid  = num(x.paidAmount);
    const bal   = total - paid;
    rows.push({
      docId: d.id,
      session: x.session || null,
      month: x.month || x.period || null,
      status: x.status || null,
      total,
      paid,
      balance: bal,
      // Minor units are what the money-safety check actually compares.
      balance_paise: Math.round(bal * 100),
      dueDate: x.dueDate || null,
    });
  });

  rows.sort((a, b) => String(a.month).localeCompare(String(b.month)));
  const open = rows.filter((r) => r.balance > 0);

  console.log(JSON.stringify({
    school: SCHOOL,
    student: STUDENT,
    demands_total: rows.length,
    demands_open: open.length,
    open_balance_sum: open.reduce((s, r) => s + r.balance, 0),
    open_balance_sum_paise: open.reduce((s, r) => s + r.balance_paise, 0),
    open: open,
    all: rows,
  }, null, 2));
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
