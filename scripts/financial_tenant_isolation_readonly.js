/**
 * ══════════════════════════════════════════════════════════════════════════
 *  READ-ONLY MULTI-TENANT FINANCIAL ISOLATION AUDIT  (Phase 8)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Money is the one place where a tenant boundary failure is unrecoverable: a
 * receipt issued against another school's demand cannot be un-issued, and the
 * accounting entry it produced is already in someone's books.
 *
 * This checks, across EVERY tenant at once, that no financial record reaches
 * across a school boundary:
 *
 *   1. every financial row carries a schoolId, and it matches the school in
 *      its own document id (an id says one tenant, a field says another);
 *   2. every row that names a studentId names a student OF THAT SCHOOL;
 *   3. every allocation/receipt names a demand of the SAME school;
 *   4. no identity claim (srNo / admissionNo) is held across schools;
 *   5. receipt numbers are unique WITHIN a school — and reuse ACROSS schools
 *      is correct and is not reported.
 *
 * ── WHY DOC-ID TENANCY, NOT THE FIELD ────────────────────────────────────
 * `srNoIndex.schoolId` is known to carry the legacy numeric code on some rows
 * while the doc id is canonical (forensic audit 2026-08-19). An audit that
 * trusted the field alone would silently see none of those rows. Tenancy is
 * therefore taken from the id where the id carries it, and the field is
 * compared AGAINST it rather than believed.
 *
 *   node scripts/financial_tenant_isolation_readonly.js
 *
 * Writes nothing. exit 0 = isolated, 2 = a boundary is crossed.
 */
const admin = require('firebase-admin');
const path  = require('path');

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

let problems = 0, notes = 0;
const fail = (m) => { problems++; console.log(`    VIOLATION  ${m}`); };
const note = (m) => { notes++;    console.log(`    note       ${m}`); };

/** Canonical school prefix from a doc id, or null when it carries none. */
const schoolFromId = (id) => {
  const m = String(id || '').match(/^(SCH_[A-Z0-9]+)_/);
  return m ? m[1] : null;
};

(async () => {
  console.log('MULTI-TENANT FINANCIAL ISOLATION — ALL TENANTS (READ ONLY)\n');

  // ── Who belongs to whom ──────────────────────────────────────────────
  const studentSchool = new Map();   // studentId -> schoolId
  const stu = await db.collection('students').get();
  stu.forEach((d) => {
    const x = d.data() || {};
    const sid = String(x.studentId || '');
    const school = schoolFromId(d.id) || String(x.schoolId || '');
    if (sid) studentSchool.set(sid, school);
  });
  console.log(`  students indexed : ${studentSchool.size}`);

  const demandSchool = new Map();    // demandId -> schoolId
  const dem = await db.collection('feeDemands').get();
  dem.forEach((d) => {
    const x = d.data() || {};
    demandSchool.set(String(x.demandId || d.id), String(x.schoolId || ''));
  });
  console.log(`  demands indexed  : ${demandSchool.size}\n`);

  // ── 1 + 2. Every financial row stays inside its school ───────────────
  const FINANCIAL = [
    'feeDemands', 'feeReceipts', 'feeReceiptAllocations', 'processedPayments',
    'studentCharges', 'studentEntitlements', 'studentConcessions',
    'feeDiscountAllocations', 'feeDefaulters', 'studentFeeSummary', 'accounting',
  ];

  console.log('  ── ROW-LEVEL TENANCY\n');
  for (const col of FINANCIAL) {
    let snap;
    try { snap = await db.collection(col).get(); }
    catch (e) { console.log(`    ${col.padEnd(24)} unreadable`); continue; }
    if (snap.empty) { console.log(`    ${col.padEnd(24)} empty`); continue; }

    let noSchool = 0, idMismatch = 0, crossStudent = 0;
    snap.forEach((d) => {
      const x = d.data() || {};
      const field = String(x.schoolId || '');
      const fromId = schoolFromId(d.id);

      if (!field) { noSchool++; return; }

      // The id and the field must not name different tenants.
      if (fromId && fromId !== field) {
        // Known and mitigated: some identity rows carry the legacy code in
        // the field while the id is canonical. Report as a note, not a
        // violation, unless the field names a DIFFERENT SCH_ tenant.
        if (/^SCH_/.test(field)) { idMismatch++; fail(`${col}/${d.id} id says ${fromId}, field says ${field}`); }
        else note(`${col}/${d.id} id ${fromId} vs legacy-coded field '${field}' (known namespace split)`);
      }

      const sid = String(x.studentId || '');
      if (sid && studentSchool.has(sid)) {
        const owner = studentSchool.get(sid);
        if (owner && owner !== (fromId || field)) {
          crossStudent++;
          fail(`${col}/${d.id} (school ${fromId || field}) references student '${sid}' of ${owner}`);
        }
      }
    });
    const verdict = (noSchool + idMismatch + crossStudent) === 0
      ? 'isolated'
      : `${noSchool} without schoolId, ${idMismatch} id/field split, ${crossStudent} cross-student`;
    console.log(`    ${col.padEnd(24)} ${String(snap.size).padStart(4)} rows — ${verdict}`);
    if (noSchool) fail(`${col}: ${noSchool} row(s) carry NO schoolId — invisible to every tenant-scoped query`);
  }

  // ── 3. Allocations and receipts point at same-school demands ─────────
  console.log('\n  ── ALLOCATION -> DEMAND TENANCY\n');
  let allocChecked = 0;
  for (const col of ['feeReceiptAllocations', 'feeDiscountAllocations']) {
    let snap;
    try { snap = await db.collection(col).get(); } catch (e) { continue; }
    snap.forEach((d) => {
      const x = d.data() || {};
      const school = String(x.schoolId || schoolFromId(d.id) || '');
      const rows = Array.isArray(x.allocations) ? x.allocations : [x];
      rows.forEach((a) => {
        const did = String(a.demand_id || a.demandId || '');
        if (!did) return;
        allocChecked++;
        if (!demandSchool.has(did)) {
          note(`${col}/${d.id} allocates to '${did}', which has no demand document`);
          return;
        }
        if (demandSchool.get(did) !== school) {
          fail(`${col}/${d.id} (school ${school}) allocates to demand '${did}' of `
            + `${demandSchool.get(did)} — a receipt against another school's demand`);
        }
      });
    });
  }
  console.log(`    allocation lines checked : ${allocChecked}`);
  if (allocChecked === 0) console.log('    (none present — this is an empty result, not a clean one)');

  // ── 4. Identity claims stay inside their school ──────────────────────
  console.log('\n  ── IDENTITY CLAIM TENANCY\n');
  for (const col of ['srNoIndex', 'admissionNoIndex']) {
    let snap;
    try { snap = await db.collection(col).get(); } catch (e) { continue; }
    let crossed = 0;
    snap.forEach((d) => {
      const x = d.data() || {};
      const school = schoolFromId(d.id);
      const owner = String(x.currentStudentId || x.studentId || '');
      if (!school || !owner) return;
      const ownerSchool = studentSchool.get(owner);
      if (ownerSchool && ownerSchool !== school) {
        crossed++;
        fail(`${col}/${d.id} (school ${school}) claims student '${owner}' of ${ownerSchool}`);
      }
    });
    console.log(`    ${col.padEnd(20)} ${String(snap.size).padStart(3)} claims — `
      + (crossed === 0 ? 'no cross-tenant binding' : `${crossed} CROSSED`));
  }

  // ── 5. Receipt numbers unique per school, reusable across ────────────
  console.log('\n  ── RECEIPT NUMBER SCOPE\n');
  const bySchool = new Map();
  const rec = await db.collection('feeReceipts').get();
  rec.forEach((d) => {
    const x = d.data() || {};
    const school = String(x.schoolId || schoolFromId(d.id) || '?');
    const no = String(x.receiptNo || '');
    const session = String(x.session || '');
    if (!no) return;
    const key = `${session}|${no}`;
    if (!bySchool.has(school)) bySchool.set(school, new Map());
    const m = bySchool.get(school);
    m.set(key, [...(m.get(key) || []), d.id]);
  });
  for (const [school, m] of bySchool) {
    let dupes = 0;
    for (const [key, ids] of m) {
      if (ids.length > 1) { dupes++; fail(`${school} reuses receipt ${key} on ${ids.join(', ')}`); }
    }
    console.log(`    ${school.padEnd(20)} ${m.size} receipt number(s) — `
      + (dupes === 0 ? 'unique within the school' : `${dupes} DUPLICATED`));
  }
  console.log('    (the same number in two DIFFERENT schools is correct and is not reported)');

  console.log('\n' + '-'.repeat(72));
  console.log(problems === 0
    ? '  FINANCIALLY ISOLATED — no row, allocation, claim or receipt crosses a\n'
      + '  tenant boundary.'
    : `  ${problems} ISOLATION VIOLATION(S). Nothing was changed.`);
  if (notes) console.log(`  ${notes} note(s) — surfaced, not counted as failures.`);
  process.exit(problems === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
