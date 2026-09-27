/**
 * PF / statutory wage census — STRICTLY READ-ONLY.
 *
 * Answers the questions that decide whether the PF wage-base finding is live or
 * theoretical, and what a cutover would touch:
 *
 *   - how many salary structures exist, per tenant
 *   - HOW MANY OF THEM POPULATE `da` (the whole question)
 *   - how many payslips / runs exist, and their statuses
 *   - whether any payroll month is locked
 *   - whether effective-dated salary assignments exist
 *   - whether the component catalogue is seeded
 *   - whether any tenant has a statutoryConfig, and what PF settings it carries
 *   - how many payroll accounting journals exist
 *
 * There is NO write of any kind in this file: no set, update, delete, add,
 * batch or commit. It cannot alter tenant data even if run by accident.
 *
 * Usage:  node scripts/pf_statutory_census.js
 */
const admin = require('firebase-admin');
const path  = require('path');

const sa = require(path.join(
  __dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const num = (v) => (typeof v === 'number' ? v : parseFloat(v || 0)) || 0;

(async () => {
  const out = {
    generatedAt: new Date().toISOString(),
    tenants: {},
    totals: {
      salaryStructures: 0, structuresWithDa: 0, structuresWithNonBasicEarnings: 0,
      payslips: 0, runs: 0, runsByStatus: {},
      salaryAssignments: 0, salaryComponents: 0,
      payrollJournals: 0, lockedPayrollMonths: 0,
      statutoryConfigs: 0,
    },
    daDetail: [],
    statutoryDetail: [],
    notes: [],
  };

  const bump = (t) => (out.tenants[t] = out.tenants[t] || {
    salaryStructures: 0, structuresWithDa: 0, payslips: 0, runs: 0,
    salaryAssignments: 0, salaryComponents: 0, payrollJournals: 0,
    lockedPayrollMonths: 0, hasStatutoryConfig: false,
  });

  // ── salarySlips holds SAL_ (structures), RUN_ and SLIP_ docs ──────────
  const ss = await db.collection('salarySlips').get();
  ss.forEach((d) => {
    const id = d.id, x = d.data() || {};
    const tenant = x.schoolId || (id.split('_')[0] + '_' + (id.split('_')[1] || '')) || 'unknown';
    const t = bump(tenant);

    if (id.includes('_SAL_') || x.type === 'salary_structure') {
      t.salaryStructures++; out.totals.salaryStructures++;

      const da    = num(x.da);
      const hra   = num(x.hra);
      const ta    = num(x.ta);
      const med   = num(x.medical);
      const other = num(x.other_allowances);
      const basic = num(x.basic);

      if (da > 0) {
        t.structuresWithDa++; out.totals.structuresWithDa++;
        out.daDetail.push({
          tenant, id, basic, da,
          daShareOfBasic: basic > 0 ? +(da / basic).toFixed(4) : null,
          pfEmployee: x.pf_employee, pfEmployer: x.pf_employer,
        });
      }
      if (hra > 0 || ta > 0 || med > 0 || other > 0 || da > 0) {
        out.totals.structuresWithNonBasicEarnings++;
      }
    } else if (id.includes('_RUN_') || x.type === 'run') {
      t.runs++; out.totals.runs++;
      const st = String(x.status || 'unknown');
      out.totals.runsByStatus[st] = (out.totals.runsByStatus[st] || 0) + 1;
    } else if (id.includes('_SLIP_')) {
      t.payslips++; out.totals.payslips++;
    }
  });

  // ── effective-dated assignments + component catalogue ─────────────────
  for (const [coll, key] of [
    ['staffSalaryAssignments', 'salaryAssignments'],
    ['salaryComponents',       'salaryComponents'],
  ]) {
    try {
      const snap = await db.collection(coll).get();
      snap.forEach((d) => {
        const x = d.data() || {};
        const tenant = x.schoolId || d.id.split('_')[0] + '_' + (d.id.split('_')[1] || '');
        bump(tenant)[key]++;
        out.totals[key]++;
      });
    } catch (e) {
      out.notes.push(`${coll}: ${e.message}`);
    }
  }

  // ── payroll accounting journals ───────────────────────────────────────
  try {
    const acct = await db.collection('accounting').where('source', '==', 'HR_Payroll').get();
    acct.forEach((d) => {
      const x = d.data() || {};
      const tenant = x.schoolId || 'unknown';
      bump(tenant).payrollJournals++;
      out.totals.payrollJournals++;
    });
  } catch (e) {
    out.notes.push(`accounting: ${e.message}`);
  }

  // ── schools: payroll locks + statutory config ─────────────────────────
  try {
    const schools = await db.collection('schools').get();
    schools.forEach((d) => {
      const x = d.data() || {};
      const tenant = x.schoolId || d.id.replace(/_profile$/, '');
      const t = bump(tenant);

      const locks = x.payrollLocks && typeof x.payrollLocks === 'object' ? x.payrollLocks : {};
      const lockedCount = Object.values(locks).filter((l) => l && l.locked).length;
      t.lockedPayrollMonths += lockedCount;
      out.totals.lockedPayrollMonths += lockedCount;

      const sd = x.salaryDefaults && typeof x.salaryDefaults === 'object' ? x.salaryDefaults : null;
      if (sd) {
        out.statutoryDetail.push({
          tenant, kind: 'salaryDefaults',
          da_pct_of_basic: sd.da_pct_of_basic,
          hra_pct_of_basic: sd.hra_pct_of_basic,
          pf_employee: sd.pf_employee, pf_employer: sd.pf_employer,
        });
      }
    });
  } catch (e) {
    out.notes.push(`schools: ${e.message}`);
  }

  // ── statutoryConfig ───────────────────────────────────────────────────
  try {
    const sc = await db.collection('statutoryConfig').get();
    sc.forEach((d) => {
      const x = d.data() || {};
      const tenant = x.schoolId || d.id.split('_')[0] + '_' + (d.id.split('_')[1] || '');
      bump(tenant).hasStatutoryConfig = true;
      out.totals.statutoryConfigs++;
      out.statutoryDetail.push({
        tenant, kind: 'statutoryConfig', docId: d.id,
        pf: x.pf || null, esi: x.esi ? { ...x.esi } : null,
        effectiveFrom: x.effectiveFrom || x.effective_from || null,
      });
    });
  } catch (e) {
    out.notes.push(`statutoryConfig: ${e.message}`);
  }

  console.log(JSON.stringify(out, null, 2));
  process.exit(0);
})().catch((e) => { console.error('CENSUS FAILED:', e.message); process.exit(1); });
