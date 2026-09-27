#!/usr/bin/env node
/**
 * W2 cutover step 1 — seed the canonical salary component catalogue.
 *
 * DRY-RUN BY DEFAULT. Writing requires --commit.
 *
 *   node scripts/w2_seed_salary_components.js
 *   node scripts/w2_seed_salary_components.js --school=SCH_XXXX
 *   node scripts/w2_seed_salary_components.js --commit
 *
 * WHAT IT WRITES AND WHY IT IS SAFE
 * ---------------------------------
 * Thirteen `salaryComponents` documents per tenant that DESCRIBE the semantics
 * `Hr::_compute_payslip()` already performs — which number is a percentage,
 * which is an amount, what base each rate applies to, and whether attendance
 * prorates it. Nothing reads this collection during payroll (verified: `Hr.php`
 * contains no reference to it), so seeding cannot change a single payslip.
 *
 * IDEMPOTENT: existing codes are reported and SKIPPED, never overwritten, so a
 * re-run cannot undo an operator's later edit to a name or display order.
 *
 * MULTI-TENANT: tenants are discovered from `schools` at runtime. No tenant id
 * is hardcoded, and the thirteen definitions are identical for every tenant —
 * they describe the shared calculator, not any school's policy.
 */
const admin = require('firebase-admin');
const path = require('path');

const args = process.argv.slice(2);
const COMMIT = args.includes('--commit');
const ONLY = (args.find((a) => a.startsWith('--school=')) || '').split('=')[1] || null;

const SA = path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
admin.initializeApp({ credential: admin.credential.cert(require(SA)) });
const db = admin.firestore();

/**
 * Mirrors Salary_component_service::systemComponents() exactly.
 * Every value is a rule read out of _compute_payslip(), not a guess:
 *   earnings   → FIXED, scaled by the attendance retention ratio
 *   PF         → PERCENT of the PRORATED basic
 *   ESI, TDS   → PERCENT of gross
 *   PT, other  → FIXED money, NOT prorated (taken at face value)
 * pfApplicable is true only for BASIC (PF's base); esiApplicable is true for
 * every earning (ESI's base is gross).
 */
const earning = (code, name, order, pfApplicable) => ({
  code, name, kind: 'earning',
  calcType: 'FIXED', basis: 'NONE', basisComponents: [],
  rate: null, amountPaise: 0,
  taxable: true, pfApplicable, esiApplicable: true,
  prorated: true, statutory: null,
  displayOrder: order, active: true, systemLocked: true,
});
const pct = (code, name, kind, basis, statutory, order) => ({
  code, name, kind,
  calcType: 'PERCENT', basis, basisComponents: [],
  rate: 0, amountPaise: null,
  taxable: false, pfApplicable: false, esiApplicable: false,
  prorated: true, statutory,
  displayOrder: order, active: true, systemLocked: true,
});
const flat = (code, name, statutory, order) => ({
  code, name, kind: 'deduction',
  calcType: 'FIXED', basis: 'NONE', basisComponents: [],
  rate: null, amountPaise: 0,
  taxable: false, pfApplicable: false, esiApplicable: false,
  prorated: false, statutory,
  displayOrder: order, active: true, systemLocked: true,
});

const COMPONENTS = [
  earning('BASIC', 'Basic', 10, true),
  earning('HRA', 'House Rent Allowance', 20, false),
  earning('DA', 'Dearness Allowance', 30, false),
  earning('TA', 'Travel Allowance', 40, false),
  earning('MEDICAL', 'Medical Allowance', 50, false),
  earning('OTHER_ALLOWANCES', 'Other Allowances', 60, false),
  pct('PF_EMPLOYEE', 'Provident Fund (Employee)', 'deduction', 'BASIC', 'PF', 110),
  pct('ESI_EMPLOYEE', 'ESI (Employee)', 'deduction', 'GROSS', 'ESI', 120),
  pct('TDS', 'TDS', 'deduction', 'GROSS', 'TDS', 130),
  flat('PROFESSIONAL_TAX', 'Professional Tax', 'PT', 140),
  flat('OTHER_DEDUCTIONS', 'Other Deductions', null, 150),
  pct('PF_EMPLOYER', 'Provident Fund (Employer)', 'employer_contribution', 'BASIC', 'PF', 210),
  pct('ESI_EMPLOYER', 'ESI (Employer)', 'employer_contribution', 'GROSS', 'ESI', 220),
];

(async () => {
  console.log('\nW2 cutover step 1 — salary component catalogue seed');
  console.log('='.repeat(76));
  console.log(COMMIT ? '  MODE: COMMIT (documents WILL be written)' : '  MODE: DRY RUN (nothing will be written)');
  if (ONLY) console.log(`  school filter: ${ONLY}`);
  console.log(`  components per tenant: ${COMPONENTS.length}`);
  console.log('='.repeat(76));

  const schools = await db.collection('schools').get();
  const tenants = [];
  schools.forEach((d) => {
    if (d.id.includes('_profile')) return;
    if (ONLY && d.id !== ONLY) return;
    tenants.push(d.id);
  });
  tenants.sort();

  if (!tenants.length) { console.log('\n  No tenants in scope.\n'); return; }

  let created = 0, skipped = 0, failed = 0;

  for (const schoolId of tenants) {
    const existing = await db.collection('salaryComponents').where('schoolId', '==', schoolId).get();
    const have = new Set();
    existing.forEach((d) => have.add(d.data().code));

    let c = 0, s = 0;
    for (const comp of COMPONENTS) {
      const docId = `${schoolId}_${comp.code}`;
      if (have.has(comp.code)) { s++; skipped++; continue; }
      if (COMMIT) {
        const now = new Date().toISOString();
        await db.collection('salaryComponents').doc(docId).set({
          ...comp, schoolId,
          createdAt: now, createdBy: 'system:w2-seed',
          updatedAt: now, updatedBy: 'system:w2-seed',
        });
      }
      c++; created++;
    }
    console.log(`  ${schoolId.padEnd(20)} ${COMMIT ? 'created' : 'would create'} ${String(c).padStart(2)}   already present ${String(s).padStart(2)}`);
  }

  console.log('\n' + '='.repeat(76));
  console.log(`  tenants: ${tenants.length}`);
  console.log(`  ${COMMIT ? 'created' : 'would create'}: ${created}   skipped (already present): ${skipped}   failed: ${failed}`);
  console.log('  payroll impact: NONE — no payroll code reads this collection');
  console.log('='.repeat(76) + '\n');

  if (!COMMIT) console.log('  Dry run only. Re-run with --commit to write.\n');
})().catch((e) => { console.error(e); process.exit(1); });
