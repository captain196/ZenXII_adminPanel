#!/usr/bin/env node
/**
 * W2.3 — legacy salary structure → effective-dated salary assignment.
 *
 * DRY-RUN BY DEFAULT. Writing requires --commit AND --effective-from, and even
 * then it only ADDS documents to `staffSalaryAssignments`. The legacy
 * `salarySlips` structures are never read-modified, never deleted, never
 * touched. Payroll continues to read them until the W2.4 reader gate.
 *
 *   node scripts/w2_3_migrate_salary_assignments.js
 *   node scripts/w2_3_migrate_salary_assignments.js --school=SCH_XXXX
 *   node scripts/w2_3_migrate_salary_assignments.js --effective-from=2026-04-01 --commit
 *
 * WHY --effective-from IS MANDATORY FOR A COMMIT
 * ----------------------------------------------
 * The legacy record has `created_at` and `updated_at`, but neither is a
 * compensation effective date — `updated_at` is when someone last edited the
 * row, which may be years after the salary actually took effect. Deriving a
 * date from it would fabricate history, and the whole point of W2.3 is that
 * history becomes trustworthy. So the operator states the date.
 *
 * IDEMPOTENCY
 * -----------
 * The document id is `{schoolId}_{staffId}_{effectiveFrom}`, so re-running with
 * the same date targets the same document instead of creating duplicates.
 * Existing assignments are reported and SKIPPED, never overwritten — an
 * operator's later edit survives a re-run.
 */
const admin = require('firebase-admin');
const path = require('path');

const args = process.argv.slice(2);
const has = (f) => args.includes(f);
const val = (f) => {
  const hit = args.find((a) => a.startsWith(`${f}=`));
  return hit ? hit.slice(f.length + 1) : null;
};

const COMMIT = has('--commit');
const ONLY_SCHOOL = val('--school');
const EFFECTIVE_FROM = val('--effective-from');

if (COMMIT && !EFFECTIVE_FROM) {
  console.error('\n--commit requires --effective-from=YYYY-MM-DD.');
  console.error('The legacy record carries no compensation effective date; deriving one from');
  console.error('updated_at would fabricate history. State the date explicitly.\n');
  process.exit(1);
}
if (EFFECTIVE_FROM && !/^\d{4}-\d{2}-\d{2}$/.test(EFFECTIVE_FROM)) {
  console.error('--effective-from must be YYYY-MM-DD.');
  process.exit(1);
}

const SA = path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
admin.initializeApp({ credential: admin.credential.cert(require(SA)) });
const db = admin.firestore();

const toPaise = (rupees) => Math.round(parseFloat(rupees || 0) * 100);

/** Mirrors Salary_assignment_service::fromLegacyStructure() exactly. */
const FIXED = {
  basic: 'BASIC', hra: 'HRA', da: 'DA', ta: 'TA',
  medical: 'MEDICAL', other_allowances: 'OTHER_ALLOWANCES',
  professional_tax: 'PROFESSIONAL_TAX', other_deductions: 'OTHER_DEDUCTIONS',
};
const PERCENT = {
  pf_employee: 'PF_EMPLOYEE', esi_employee: 'ESI_EMPLOYEE', tds: 'TDS',
  pf_employer: 'PF_EMPLOYER', esi_employer: 'ESI_EMPLOYER',
};
const EARNINGS = ['BASIC', 'HRA', 'DA', 'TA', 'MEDICAL', 'OTHER_ALLOWANCES'];

function mapLegacy(legacy, staffId, effectiveFrom) {
  const lines = [];
  let grossPaise = 0;

  for (const [field, code] of Object.entries(FIXED)) {
    if (!(field in legacy)) continue;
    const p = toPaise(legacy[field]);
    lines.push({ componentId: code, calcType: 'FIXED', basis: 'NONE', rate: null, amountPaise: p });
    if (EARNINGS.includes(code)) grossPaise += p;
  }
  for (const [field, code] of Object.entries(PERCENT)) {
    if (!(field in legacy)) continue;
    lines.push({
      componentId: code, calcType: 'PERCENT',
      basis: ['PF_EMPLOYEE', 'PF_EMPLOYER'].includes(code) ? 'BASIC' : 'GROSS',
      rate: parseFloat(legacy[field] || 0), amountPaise: null,
    });
  }
  return {
    staffId, effectiveFrom, effectiveTo: null,
    payBasis: 'MONTHLY_FIXED', status: 'active',
    templateId: null, payGrade: null,
    lines, grossPaise, ctcPaise: null,
    supersedesId: null,
    reason: 'Migrated from the legacy salary structure (W2.3).',
  };
}

(async () => {
  console.log('\nW2.3 — legacy salary structure → salary assignment');
  console.log('='.repeat(74));
  console.log(COMMIT ? '  MODE: COMMIT (documents WILL be written)' : '  MODE: DRY RUN (nothing will be written)');
  if (EFFECTIVE_FROM) console.log(`  effectiveFrom: ${EFFECTIVE_FROM}`);
  if (ONLY_SCHOOL) console.log(`  school filter: ${ONLY_SCHOOL}`);
  console.log('='.repeat(74));

  const snap = await db.collection('salarySlips').where('type', '==', 'salary_structure').get();
  const byTenant = {};
  snap.forEach((d) => {
    const data = d.data();
    const schoolId = data.schoolId || '';
    if (!schoolId) return;
    if (ONLY_SCHOOL && schoolId !== ONLY_SCHOOL) return;
    // Doc id is {schoolId}_SAL_{staffId}
    const m = d.id.match(/^(.+)_SAL_(.+)$/);
    if (!m) return;
    (byTenant[schoolId] = byTenant[schoolId] || []).push({ staffId: m[2], data });
  });

  const tenants = Object.keys(byTenant).sort();
  if (tenants.length === 0) {
    console.log('\n  No legacy salary structures found in scope. Nothing to migrate.\n');
    return;
  }

  let totalWould = 0, totalSkip = 0, totalWrote = 0;

  for (const schoolId of tenants) {
    const rows = byTenant[schoolId];
    console.log(`\n  Tenant ${schoolId} — ${rows.length} structure(s)`);
    console.log('  ' + '-'.repeat(70));

    for (const { staffId, data } of rows) {
      const from = EFFECTIVE_FROM || '(not supplied)';
      const docId = `${schoolId}_${staffId}_${from}`;

      let exists = false;
      if (EFFECTIVE_FROM) {
        exists = (await db.collection('staffSalaryAssignments').doc(docId).get()).exists;
      }

      const mapped = mapLegacy(data, staffId, EFFECTIVE_FROM || '0000-00-00');
      const grossRs = (mapped.grossPaise / 100).toFixed(2);
      const nLines = mapped.lines.length;

      if (exists) {
        totalSkip++;
        console.log(`    SKIP   ${staffId}  gross=₹${grossRs}  lines=${nLines}  (assignment already exists)`);
        continue;
      }

      if (COMMIT) {
        await db.collection('staffSalaryAssignments').doc(docId).set({
          ...mapped, schoolId,
          createdAt: new Date().toISOString(), createdBy: 'system:w2.3-migration',
          updatedAt: new Date().toISOString(), updatedBy: 'system:w2.3-migration',
        });
        totalWrote++;
        console.log(`    WROTE  ${staffId}  gross=₹${grossRs}  lines=${nLines}`);
      } else {
        totalWould++;
        console.log(`    WOULD  ${staffId}  gross=₹${grossRs}  lines=${nLines}`);
      }
    }
  }

  console.log('\n' + '='.repeat(74));
  console.log(`  tenants: ${tenants.length}`);
  if (COMMIT) console.log(`  written: ${totalWrote}   skipped (already present): ${totalSkip}`);
  else        console.log(`  would write: ${totalWould}   already present: ${totalSkip}`);
  console.log('  legacy salarySlips structures: UNTOUCHED');
  console.log('='.repeat(74) + '\n');

  if (!COMMIT) {
    console.log('  Dry run only. To write, re-run with:');
    console.log('    --effective-from=YYYY-MM-DD --commit\n');
  }
})().catch((e) => { console.error(e); process.exit(1); });
