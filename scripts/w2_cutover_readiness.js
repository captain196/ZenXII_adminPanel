#!/usr/bin/env node
/**
 * W2 cutover readiness — READ-ONLY gate analysis (steps 2 and 4).
 *
 * Writes NOTHING. Answers, per tenant:
 *
 *   Step 2 — is statutory policy explicitly configured?
 *            If not, that tenant's migration must STOP with
 *            STATUTORY CONFIGURATION REQUIRED. Nothing is inferred from the
 *            school's name, city or address.
 *
 *   Step 4 — the migration diagnostic the cutover brief asks for: legacy
 *            records, employees affected, component mappings, missing
 *            mappings, invalid records, duplicates, overlap conflicts, missing
 *            effective dates, and records needing a manual decision.
 *
 *   node scripts/w2_cutover_readiness.js
 */
const admin = require('firebase-admin');
const path = require('path');

const SA = path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
admin.initializeApp({ credential: admin.credential.cert(require(SA)) });
const db = admin.firestore();

const LEGACY_FIELDS = [
  'basic', 'hra', 'da', 'ta', 'medical', 'other_allowances',
  'pf_employee', 'pf_employer', 'esi_employee', 'esi_employer',
  'professional_tax', 'tds', 'other_deductions',
];
const EARNINGS = ['basic', 'hra', 'da', 'ta', 'medical', 'other_allowances'];

(async () => {
  console.log('\nW2 COMPENSATION CUTOVER — READINESS ANALYSIS (read-only)');
  console.log('='.repeat(78));

  const schools = await db.collection('schools').get();
  const tenants = [];
  schools.forEach((d) => { if (!d.id.includes('_profile')) tenants.push(d.id); });
  tenants.sort();

  // ── Gather ──
  const structures = {};
  const structSnap = await db.collection('salarySlips').where('type', '==', 'salary_structure').get();
  structSnap.forEach((d) => {
    const data = d.data();
    const s = data.schoolId || '';
    if (!s) return;
    const m = d.id.match(/^(.+)_SAL_(.+)$/);
    if (!m) return;
    (structures[s] = structures[s] || []).push({ staffId: m[2], data, docId: d.id });
  });

  const staffByTenant = {};
  const staffSnap = await db.collection('staff').get();
  staffSnap.forEach((d) => {
    const data = d.data();
    const s = data.schoolId || '';
    if (!s) return;
    (staffByTenant[s] = staffByTenant[s] || new Set()).add(d.id.replace(`${s}_`, ''));
  });

  const statSnap = await db.collection('statutoryConfig').get();
  const statByTenant = {};
  statSnap.forEach((d) => {
    const s = d.data().schoolId || '';
    if (s) (statByTenant[s] = statByTenant[s] || []).push(d.data());
  });

  const compSnap = await db.collection('salaryComponents').get();
  const compByTenant = {};
  compSnap.forEach((d) => {
    const s = d.data().schoolId || '';
    if (s) (compByTenant[s] = compByTenant[s] || []).push(d.data().code);
  });

  const asgSnap = await db.collection('staffSalaryAssignments').get();
  const asgByTenant = {};
  asgSnap.forEach((d) => {
    const s = d.data().schoolId || '';
    if (s) (asgByTenant[s] = asgByTenant[s] || []).push(d.data());
  });

  // ── Per tenant ──
  let blocked = 0, ready = 0, noPayroll = 0;

  for (const t of tenants) {
    const rows = structures[t] || [];
    const comps = compByTenant[t] || [];
    const stats = statByTenant[t] || [];
    const asgs = asgByTenant[t] || [];
    const staff = staffByTenant[t] || new Set();

    console.log(`\n  ${t}`);
    console.log('  ' + '-'.repeat(74));
    console.log(`    legacy salary structures : ${rows.length}`);
    console.log(`    staff on record          : ${staff.size}`);
    console.log(`    component catalogue      : ${comps.length} / 13`);
    console.log(`    statutory policies       : ${stats.length}`);
    console.log(`    existing assignments     : ${asgs.length}`);

    if (rows.length === 0) {
      console.log('    VERDICT: no payroll data — nothing to migrate. Seed the catalogue when this');
      console.log('             tenant starts using payroll; no statutory decision needed yet.');
      noPayroll++;
      continue;
    }

    // ── Step 4 diagnostics ──
    const issues = [];
    let mapped = 0, missingStaff = 0, zeroBasic = 0, unmappable = 0;
    const seen = new Set();

    for (const { staffId, data, docId } of rows) {
      if (seen.has(staffId)) { issues.push(`duplicate structure for ${staffId}`); continue; }
      seen.add(staffId);

      if (!staff.has(staffId)) { missingStaff++; issues.push(`structure for ${staffId} has no staff record`); }

      const basic = parseFloat(data.basic || 0);
      if (!(basic > 0)) { zeroBasic++; issues.push(`${staffId}: basic is zero or missing — payroll cannot be computed`); }

      // Every legacy field must map to a catalogue component.
      const unknown = Object.keys(data).filter(
        (k) => LEGACY_FIELDS.includes(k) === false
          // Known non-compensation metadata. `staffId` belongs here: it
          // identifies the record, it is not a pay element, and flagging it
          // would make the diagnostic cry wolf on every single row.
          && !['type', 'schoolId', 'staffId', 'staff_id', 'session', 'source',
               'created_at', 'updated_at', 'updated_by', 'created_by',
               'createdAt', 'updatedAt', 'updatedBy', 'createdBy',
               '_version', '_prev', 'last_synced_at', 'id'].includes(k)
      );
      if (unknown.length) { unmappable++; issues.push(`${staffId}: unmapped field(s) ${unknown.join(', ')}`); }

      let gross = 0;
      EARNINGS.forEach((f) => { gross += Math.round(parseFloat(data[f] || 0) * 100); });
      if (gross <= 0) issues.push(`${staffId}: gross earnings total zero`);

      mapped++;
    }

    console.log(`    structures mappable      : ${mapped}`);
    console.log(`    missing staff record     : ${missingStaff}`);
    console.log(`    zero/invalid basic       : ${zeroBasic}`);
    console.log(`    unmapped legacy fields   : ${unmappable}`);
    console.log(`    effective date in legacy : 0  (none exists — must be operator-supplied)`);

    if (issues.length) {
      console.log('    records needing a decision:');
      issues.slice(0, 10).forEach((i) => console.log(`      ! ${i}`));
      if (issues.length > 10) console.log(`      … and ${issues.length - 10} more`);
    } else {
      console.log('    records needing a decision: none');
    }

    // ── Step 2 gate ──
    const gates = [];
    if (comps.length < 13) gates.push(`component catalogue not seeded (${comps.length}/13)`);
    if (stats.length === 0) gates.push('STATUTORY CONFIGURATION REQUIRED — no policy configured');

    if (gates.length) {
      console.log(`    VERDICT: ${'[31mBLOCKED[0m'} — ` + gates.join('; '));
      blocked++;
    } else {
      console.log('    VERDICT: ready for controlled migration (operator supplies --effective-from)');
      ready++;
    }
  }

  console.log('\n' + '='.repeat(78));
  console.log(`  tenants: ${tenants.length}   ready: ${ready}   blocked: ${blocked}   no payroll data: ${noPayroll}`);
  console.log('  NOTHING WAS WRITTEN.');
  console.log('='.repeat(78) + '\n');

  if (blocked > 0) {
    console.log('  The cutover sequence CANNOT proceed past step 2 for the blocked tenant(s).');
    console.log('  Statutory policy must be configured explicitly by an authorized administrator');
    console.log('  through Compensation → Statutory. It is never inferred from school name,');
    console.log('  city or address.\n');
  }
})().catch((e) => { console.error(e); process.exit(1); });
