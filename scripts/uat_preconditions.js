#!/usr/bin/env node
/**
 * UAT PRECONDITION INSPECTION — STRICTLY READ-ONLY.
 *
 * Writes NOTHING. Answers the question the UAT authorization (§3) requires to
 * be answered BEFORE any operation: which tenant, which employees, which
 * applicant, which payroll period — and, critically, whether each authorized
 * UAT operation is runnable at all.
 *
 * §7 explicitly forbids inventing LOP to close a capability. This determines
 * whether real LOP exists in any period, so that instruction can be honoured
 * with evidence rather than guessed at.
 *
 *   node scripts/uat_preconditions.js
 */
const admin = require('firebase-admin');
const path = require('path');

const SA = path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
admin.initializeApp({ credential: admin.credential.cert(require(SA)) });
const db = admin.firestore();

const line = (c = '=') => console.log(c.repeat(78));

(async () => {
  console.log('\nZENX HRMS — UAT PRECONDITION INSPECTION (READ-ONLY)');
  line();

  // ── Tenants ──
  const schools = await db.collection('schools').get();
  const tenants = [];
  schools.forEach((d) => { if (!d.id.includes('_profile')) tenants.push(d.id); });
  tenants.sort();
  console.log(`  tenants discovered: ${tenants.length}`);

  // Per-tenant payroll-readiness profile.
  for (const t of tenants) {
    const staffSnap = await db.collection('staff').where('schoolId', '==', t).get();
    if (staffSnap.empty) continue;

    const structSnap = await db.collection('salarySlips')
      .where('schoolId', '==', t).where('type', '==', 'salary_structure').get();
    const runSnap = await db.collection('salarySlips')
      .where('schoolId', '==', t).where('type', '==', 'run').get();
    const appSnap = await db.collection('hrApplicants').where('schoolId', '==', t).get();
    const statSnap = await db.collection('statutoryConfig').where('schoolId', '==', t).get();

    let active = 0;
    staffSnap.forEach((d) => {
      const s = d.data();
      if (String(s.status || s.Status || 'Active') === 'Active') active++;
    });

    console.log(`\n  ${t}`);
    console.log('  ' + '-'.repeat(74));
    console.log(`    staff (total / active)   : ${staffSnap.size} / ${active}`);
    console.log(`    salary structures        : ${structSnap.size}`);
    console.log(`    payroll runs             : ${runSnap.size}`);
    console.log(`    hr applicants            : ${appSnap.size}`);
    console.log(`    statutory policies       : ${statSnap.size}`);

    if (structSnap.size === 0) {
      console.log('    UAT VERDICT: NOT RUNNABLE — no salary structures, payroll cannot generate.');
      continue;
    }

    // ── Which periods are free for a controlled test run? ──
    const usedPeriods = [];
    runSnap.forEach((d) => {
      const r = d.data();
      usedPeriods.push(`${r.month} ${r.year} [${r.status}]`);
    });
    console.log(`    existing run periods     : ${usedPeriods.length ? usedPeriods.join(', ') : 'none'}`);

    // ── N-15: can the bank-details blocker actually be exercised? ──
    let withBank = 0, withoutBank = 0, withPan = 0;
    const pii = await db.collection('staffPrivate').where('schoolId', '==', t).get()
      .catch(() => ({ empty: true, size: 0, forEach: () => {} }));
    const piiById = {};
    pii.forEach((d) => { piiById[d.id] = d.data(); });

    staffSnap.forEach((d) => {
      const s = Object.assign({}, d.data(), piiById[d.id] || {});
      const bd = s.bankDetails || {};
      const hasBank = ['accountNumber', 'account_number', 'accountNo', 'acNumber']
        .some((k) => String(bd[k] || '').trim() !== '')
        || ['bankAccountNumber', 'bank_account_number', 'accountNumber', 'account_number']
          .some((k) => String(s[k] || '').trim() !== '');
      hasBank ? withBank++ : withoutBank++;
      if (['pan', 'PAN', 'panNumber', 'pan_number'].some((k) => String(s[k] || '').trim() !== '')) withPan++;
    });
    console.log(`    PII mirror docs          : ${pii.size || 0}`);
    console.log(`    staff WITH bank details  : ${withBank}`);
    console.log(`    staff WITHOUT bank       : ${withoutBank}   <- N-15 blocker is exercisable if > 0`);
    console.log(`    staff with PAN           : ${withPan}`);

    // ── §7: is there real LOP to review, or must LOP be NOT TESTABLE? ──
    const attSnap = await db.collection('staffAttendanceSummary')
      .where('schoolId', '==', t).get();
    const months = {};
    attSnap.forEach((d) => {
      const a = d.data();
      const m = a.month || '?';
      months[m] = months[m] || { docs: 0, absent: 0 };
      months[m].docs++;
      const dw = String(a.dayWise || '');
      months[m].absent += (dw.match(/A/g) || []).length;
    });
    const monthKeys = Object.keys(months).sort();
    console.log(`    attendance summary months: ${monthKeys.length ? monthKeys.join(', ') : 'NONE'}`);
    monthKeys.forEach((m) => {
      console.log(`      ${m}: ${months[m].docs} staff doc(s), ${months[m].absent} 'A' mark(s)`);
    });

    // ── P-063: is there an applicant at Selected to convert? ──
    const stages = {};
    let selected = 0, alreadyHired = 0;
    appSnap.forEach((d) => {
      const a = d.data();
      const st = a.stage || a.ats_status || a.status || '?';
      stages[st] = (stages[st] || 0) + 1;
      if (String(st).toLowerCase() === 'selected') selected++;
      if (a.staff_id || String(a.ats_status || '').toLowerCase() === 'hired') alreadyHired++;
    });
    console.log(`    applicant stages         : ${Object.keys(stages).length ? JSON.stringify(stages) : 'none'}`);
    console.log(`    at 'Selected'            : ${selected}   <- P-063 needs >= 1`);
    console.log(`    already hired/linked     : ${alreadyHired}`);

    // ── Verdicts, per authorized operation ──
    console.log('\n    AUTHORIZED-OPERATION READINESS:');
    console.log(`      Op1 payroll run        : ${structSnap.size > 0 ? 'RUNNABLE' : 'NOT RUNNABLE'}`);
    console.log(`      Op2 override           : RUNNABLE once a Draft run exists`);
    console.log(`      Op3 regeneration       : RUNNABLE once a Draft run exists`);
    const lopPossible = monthKeys.some((m) => months[m].absent > 0);
    console.log(`      Op4 LOP review         : ${lopPossible
      ? 'POSSIBLY RUNNABLE (absence marks exist; LOP also needs APPROVED unpaid leave)'
      : 'NOT TESTABLE — no absence marks in any period. Do NOT invent LOP (§7).'}`);
    console.log(`      Op5 finalize/unlock    : RUNNABLE once a Draft run exists`);
    console.log(`      Op6 ATS convert        : ${selected > 0 ? 'RUNNABLE' : 'NOT RUNNABLE — no applicant at Selected'}`);
    console.log(`      Statutory display      : ${statSnap.size === 0 ? 'NOT CONFIGURED (expected — stays BLOCKED)' : statSnap.size + ' policies'}`);
  }

  console.log('\n' + '='.repeat(78));
  console.log('  NOTHING WAS WRITTEN. This inspection is read-only.');
  line();
  console.log('');
})().catch((e) => { console.error(e); process.exit(1); });
