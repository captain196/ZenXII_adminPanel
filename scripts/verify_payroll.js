const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';

(async () => {
  // Find latest run
  const runs = await db.collection('salarySlips')
    .where('schoolId', '==', SCHOOL)
    .where('type', '==', 'run').get();
  console.log('=== RUNS ===');
  runs.forEach(d => {
    const x = d.data();
    console.log(`${d.id} month=${x.month} status=${x.status} gross=${x.total_gross} net=${x.total_net} ded=${x.total_deductions} staff=${x.staff_count} workDays=${x.working_days}`);
    if (x.warnings && x.warnings.length) console.log('  warnings:', x.warnings);
  });

  console.log('\n=== SLIPS (latest run) ===');
  const slips = await db.collection('salarySlips')
    .where('schoolId', '==', SCHOOL)
    .where('type', '==', 'payslip').get();
  const rows = [];
  slips.forEach(d => {
    const x = d.data();
    rows.push({
      id: d.id, runId: x.runId, staff: x.staff_name,
      basic: x.basic, gross: x.gross, ded: x.total_deductions, net: x.net_pay,
      absent: x.days_absent, worked: x.days_worked, lwp: x.lwp_days, paidLeave: x.paid_leave_days,
      status: x.status,
    });
  });
  rows.sort((a,b) => a.id.localeCompare(b.id));
  rows.forEach(r => console.log(JSON.stringify(r)));

  console.log('\n=== JOURNAL (HR_Payroll, active) ===');
  const j = await db.collection('accounting').where('source', '==', 'HR_Payroll').get();
  j.forEach(d => {
    const x = d.data();
    console.log(`${d.id} voucher=${x.voucher_no} src_ref=${x.source_ref} status=${x.status} dr=${x.total_dr} cr=${x.total_cr} lines=${(x.lines||[]).length}`);
    if (x.status === 'active') {
      (x.lines || []).forEach(l => console.log(`  ${l.account_code} dr=${l.dr} cr=${l.cr}`));
    }
  });

  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
