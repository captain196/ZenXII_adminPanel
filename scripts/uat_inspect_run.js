#!/usr/bin/env node
/**
 * UAT run inspection — STRICTLY READ-ONLY. Writes nothing.
 *
 * Answers §4 (the 1-slip / total_net=0 case) and §5 (can June/July continue
 * UAT) from stored evidence, without touching tenant configuration.
 *
 *   node scripts/uat_inspect_run.js
 */
const admin = require('firebase-admin');
const path = require('path');

const SA = path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
admin.initializeApp({ credential: admin.credential.cert(require(SA)) });
const db = admin.firestore();

const T = 'SCH_D94FE8F7AD';
const line = (c = '=') => console.log(c.repeat(78));

(async () => {
  console.log('\nUAT RUN INSPECTION (READ-ONLY) — ' + T);
  line();

  // ── §4: the PR0002 run and its single slip ──
  const runs = await db.collection('salarySlips')
    .where('schoolId', '==', T).where('type', '==', 'run').get();
  console.log('\nA. PAYROLL RUNS');
  line('-');
  runs.forEach((d) => {
    const r = d.data();
    console.log(`  ${r.month} ${r.year}  status=${r.status}  staff=${r.staff_count}  ` +
      `gross=${r.total_gross}  net=${r.total_net}  workingDays=${r.working_days}`);
  });

  const slips = await db.collection('salarySlips')
    .where('schoolId', '==', T).where('type', '==', 'payslip').get();
  console.log(`\nB. PAYSLIPS (${slips.size} total across all runs)`);
  line('-');

  slips.forEach((d) => {
    const s = d.data();
    if (String(s.runId || '') !== 'PR0002') return;

    console.log(`\n  RUN PR0002 — ${s.staff_name} (${s.staffId})`);
    console.log('  ' + '-'.repeat(74));
    console.log(`    basic=${s.basic}  hra=${s.hra}  da=${s.da}  ta=${s.ta}  medical=${s.medical}  other=${s.other_allowances}`);
    console.log(`    gross=${s.gross}   deductions=${s.total_deductions}   NET=${s.net_pay}`);
    console.log(`    workingDays=${s.working_days} daysWorked=${s.days_worked} absent=${s.days_absent} lwp=${s.lwp_days}`);
    console.log(`    dailySalary=${s.daily_salary}  deductionDays=${s.deduction_days}  lwpDeduction=${s.lwp_deduction}`);
    console.log(`    pf=${s.pf_employee} esi=${s.esi_employee} pt=${s.professional_tax} tds=${s.tds} other=${s.other_deductions}`);

    const sal = s._salary_snapshot;
    console.log(`    _salary_snapshot: ${sal ? JSON.stringify(sal) : 'ABSENT'}`);

    const att = s._attendance_snapshot;
    if (att) {
      console.log(`    _attendance_snapshot: month=${att.month_key} vacantTreatment=${att.vacant_treatment}`);
      console.log(`      day_wise    = ${JSON.stringify(att.day_wise)}`);
      console.log(`      window_mask = ${JSON.stringify(att.window_mask)}`);
      console.log(`      lwp_mask    = ${JSON.stringify(att.lwp_mask)}`);
      console.log(`      derived     = ${JSON.stringify(att.derived)}`);
      console.log(`      workingDays=${att.working_days} daysInMonth=${att.days_in_month} holidays=${JSON.stringify(att.holiday_days)}`);
    } else { console.log('    _attendance_snapshot: ABSENT'); }

    const tr = s._calculation_trace;
    if (tr) {
      console.log(`    _calculation_trace: source=${tr.statutory_source}`);
      console.log(`      bases = ${JSON.stringify(tr.bases)}`);
      console.log(`      paise = ${JSON.stringify(tr.paise)}`);
      console.log(`      warnings = ${JSON.stringify(tr.warnings)}`);
    } else { console.log('    _calculation_trace: ABSENT'); }

    console.log(`    _payroll_adjustments: ${s._payroll_adjustments ? JSON.stringify(s._payroll_adjustments) : 'null (none)'}`);
    console.log(`    att_warning=${JSON.stringify(s.att_warning)}  deduction_reason=${JSON.stringify(s.deduction_reason)}`);
  });

  // ── §5: is any period viable for the remaining UAT? ──
  console.log('\n\nC. ATTENDANCE COVERAGE PER MONTH  (§5 — can UAT continue?)');
  line('-');
  const att = await db.collection('staffAttendanceSummary').where('schoolId', '==', T).get();
  const byMonth = {};
  att.forEach((d) => {
    const a = d.data();
    const m = a.month || '?';
    byMonth[m] = byMonth[m] || [];
    const dw = String(a.dayWise || '');
    byMonth[m].push({
      staffId: a.staffId,
      len: dw.length,
      V: (dw.match(/V/g) || []).length,
      P: (dw.match(/P/g) || []).length,
      A: (dw.match(/A/g) || []).length,
      L: (dw.match(/L/g) || []).length,
      M: (dw.match(/M/g) || []).length,
    });
  });
  Object.keys(byMonth).sort().forEach((m) => {
    console.log(`\n  ${m}: ${byMonth[m].length} staff with an attendance doc`);
    byMonth[m].forEach((r) => {
      console.log(`    ${r.staffId}  len=${r.len}  P=${r.P} A=${r.A} L=${r.L} M=${r.M} V(unmarked)=${r.V}`);
    });
  });

  // Structures + who could actually be paid
  const struct = await db.collection('salarySlips')
    .where('schoolId', '==', T).where('type', '==', 'salary_structure').get();
  const withStruct = new Set();
  struct.forEach((d) => { const m = d.id.match(/_SAL_(.+)$/); if (m) withStruct.add(m[1]); });
  console.log(`\n  staff WITH a salary structure: ${[...withStruct].sort().join(', ')}`);

  console.log('\n  VIABILITY: a month is UAT-viable when at least one employee has BOTH');
  console.log('  a salary structure AND an attendance doc with no unmarked (V) days.');
  Object.keys(byMonth).sort().forEach((m) => {
    const viable = byMonth[m].filter((r) => withStruct.has(r.staffId) && r.V === 0);
    console.log(`    ${m}: ${viable.length} viable employee(s)` +
      (viable.length ? ` -> ${viable.map((v) => v.staffId).join(', ')}` : '  <- NOT VIABLE'));
  });

  // ── Attendance config: what is vacant_treatment actually set to? ──
  const school = await db.collection('schools').doc(T).get();
  const cfg = (school.data() || {}).attendancePolicy || (school.data() || {}).attendanceConfig || {};
  console.log('\n\nD. ATTENDANCE CONFIG (read-only — NOT changed)');
  line('-');
  console.log('  vacant_treatment = ' + JSON.stringify(cfg.vacant_treatment ?? '(unset -> defaults to "block")'));
  console.log('  extra_worked_rate = ' + JSON.stringify(cfg.extra_worked_rate ?? '(unset -> 1.0)'));

  console.log('\n' + '='.repeat(78));
  console.log('  NOTHING WAS WRITTEN. No configuration was changed.');
  line();
  console.log('');
})().catch((e) => { console.error(e); process.exit(1); });
