// Backfills canonical camelCase fields on existing SLIP_* docs in salarySlips.
// Non-destructive: adds fields; does not remove snake_case originals.
// Mirrors Hr::_fsSyncPayslip canonical block.
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const DRY_RUN = process.argv.includes('--dry-run');

// Resolve month name → ISO key ("2026-04") using the run doc's year.
const MONTH_NUM = {
  January: '01', February: '02', March: '03', April: '04', May: '05', June: '06',
  July: '07', August: '08', September: '09', October: '10', November: '11', December: '12',
};

async function resolveMonthKey(runId, schoolId) {
  const runDoc = await db.collection('salarySlips').doc(`${schoolId}_RUN_${runId}`).get();
  if (!runDoc.exists) return { month: '', monthKey: '', year: '' };
  const d = runDoc.data();
  const mNum = MONTH_NUM[d.month] || '00';
  return { month: d.month || '', monthKey: `${d.year}-${mNum}`, year: String(d.year || '') };
}

(async () => {
  const snap = await db.collection('salarySlips').where('type', '==', 'payslip').get();
  console.log(`Found ${snap.size} payslip docs`);

  const runCache = {};
  let updated = 0;
  for (const doc of snap.docs) {
    const s = doc.data();
    const schoolId = s.schoolId || '';
    const runId = s.runId || '';
    const cacheKey = `${schoolId}|${runId}`;
    if (!runCache[cacheKey]) runCache[cacheKey] = await resolveMonthKey(runId, schoolId);
    const ctx = runCache[cacheKey];

    const canonical = {
      staffName: String(s.staff_name || ''),
      netPayable: Number(s.net_pay || 0),
      grossEarnings: Number(s.gross || 0),
      totalDeductions: Number(s.total_deductions || 0),
      workingDays: Number(s.working_days || 0),
      presentDays: Number(s.days_worked || 0),
      daysAbsent: Number(s.days_absent || 0),
      lwpDays: Number(s.lwp_days || 0),
      paidLeaveDays: Number(s.paid_leave_days || 0),
      month: ctx.month,
      monthKey: ctx.monthKey,
      year: ctx.year,
      earnings: {
        basic: Number(s.basic || 0),
        hra: Number(s.hra || 0),
        da: Number(s.da || 0),
        ta: Number(s.ta || 0),
        medical: Number(s.medical || 0),
        otherAllowances: Number(s.other_allowances || 0),
      },
      deductions: {
        pfEmployee: Number(s.pf_employee || 0),
        esiEmployee: Number(s.esi_employee || 0),
        professionalTax: Number(s.professional_tax || 0),
        tds: Number(s.tds || 0),
        otherDeductions: Number(s.other_deductions || 0),
      },
    };

    console.log(`${doc.id} → monthKey=${canonical.monthKey} netPayable=${canonical.netPayable} staffName="${canonical.staffName}"`);
    if (!DRY_RUN) {
      await doc.ref.set(canonical, { merge: true });
      updated++;
    }
  }
  console.log(DRY_RUN ? '\n(DRY RUN)' : `\n✅ ${updated} slip(s) backfilled`);
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
