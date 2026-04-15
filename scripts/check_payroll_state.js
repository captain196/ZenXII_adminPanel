const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const out = { slips: { SAL: [], RUN: [], SLIP: [], other: [] }, journal: [], counters: null };

  const ss = await db.collection('salarySlips').get();
  ss.forEach(d => {
    const id = d.id, data = d.data();
    const row = { id, type: data.type, status: data.status, staffId: data.staffId || data.staff_id, runId: data.runId };
    if (id.includes('_SAL_')) out.slips.SAL.push(row);
    else if (id.includes('_RUN_')) out.slips.RUN.push(row);
    else if (id.includes('_SLIP_')) out.slips.SLIP.push(row);
    else out.slips.other.push(row);
  });

  // Check accounting journal for HR_Payroll entries
  const acct = await db.collection('accounting').where('source', '==', 'HR_Payroll').get();
  acct.forEach(d => {
    const x = d.data();
    out.journal.push({ id: d.id, voucher_no: x.voucher_no, source_ref: x.source_ref, status: x.status, is_finalized: x.is_finalized, total_dr: x.total_dr, total_cr: x.total_cr });
  });

  console.log(JSON.stringify(out, null, 2));
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
