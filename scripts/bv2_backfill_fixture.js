/**
 * ══════════════════════════════════════════════════════════════════════════
 *  BV2-1 FIXTURE — reproduce the pre-ledger discount condition on the emulator
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Recreates, in the emulator, exactly the shape found on the live tenant:
 * demands that carry a real discount with NO allocation row to explain it,
 * plus the active entitlement that discount came from.
 *
 * This exists so the BV2-1 remedy can be PROVEN rather than proposed. The
 * backfill is run for real here, and `billing_v2_certify whatif` is run
 * before and after — turning "24 rows would be created" into "the violation
 * is gone".
 *
 * Field shapes are copied from the live documents (grossAmount 600,
 * discountAmount 60, netAmount 540, concessionApplied 60, feeHeadRef matching
 * the entitlement scope), so the fixture exercises the same matching logic
 * rather than a convenient simplification.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/bv2_backfill_fixture.js
 */
const admin = require('firebase-admin');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
const loopback = (v) =>
  ['127.0.0.1', 'localhost', '::1'].includes(v.replace(/^https?:\/\//, '').split(':')[0]);
if (!EMU || !loopback(EMU)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  console.error('This script WRITES demands and entitlements.');
  process.exit(1);
}

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

const SCHOOL  = 'SCH_BV2FIXTURE';
const SESSION = '2026-27';
const HEAD    = 'FH_BV2TUITION1';

(async () => {
  console.log(`BV2-1 FIXTURE — ${SCHOOL} / ${SESSION} on ${EMU}\n`);

  await db.collection('schools').doc(SCHOOL).set({
    name: 'BV2 Backfill Fixture', schoolCode: '99002', schoolId: SCHOOL,
    sessions: [SESSION], currentSession: SESSION, institution: { state: 'ACTIVE' },
  });

  // Two students, mirroring STU0184/STU0185.
  const students = ['STU9001', 'STU9002'];
  let demands = 0;

  for (const sid of students) {
    await db.collection('students').doc(`${SCHOOL}_${sid}`).set({
      schoolId: SCHOOL, studentId: sid, session: SESSION,
      className: 'Class 9th', section: 'Section A',
      name: `BV2 Fixture ${sid}`, status: 'Active', srNo: sid.slice(-1),
    });

    // The ACTIVE entitlement the discount came from — 10% on one head.
    const entId = `${SCHOOL}_${sid}_EBV2FIXTURE`;
    await db.collection('studentEntitlements').doc(entId).set({
      entitlementId: entId, schoolId: SCHOOL, studentId: sid,
      kind: 'concession', concessionType: 'other',
      scope: `head:${HEAD}`, valueType: 'percent', value: 10,
      effectiveFrom: '2026-04-01', effectiveTo: null,
      session: SESSION, status: 'active',
      approvedBy: 'BV2_FIXTURE', approvedAt: new Date().toISOString(),
      reason: 'BV2-1 fixture — reproduce pre-ledger discount',
    });

    // 12 monthly demands, each already discounted, each with NO allocation.
    for (let m = 1; m <= 12; m++) {
      const period = `2026-${String(m).padStart(2, '0')}`;
      const did = `${SCHOOL}_${sid}_${period}`.toLowerCase().replace(/[^a-z0-9]/g, '');
      await db.collection('feeDemands').doc(did).set({
        demandId: did, schoolId: SCHOOL, studentId: sid, session: SESSION,
        feeHead: 'Tuition Fee', feeHeadRef: HEAD, feeHeadId: HEAD,
        className: 'Class 9th', section: 'Section A',
        period, periodKey: period, periodType: 'monthly', frequency: 'monthly',
        grossAmount: 600, discountAmount: 60, netAmount: 540,
        concessionApplied: 60, fineAmount: 0, paidAmount: 0, balance: 540,
        status: 'unpaid', dueDate: `${period}-10`,
        category: 'academic', categorySource: 'registry',
        createdAt: new Date().toISOString(),
      });
      demands++;
    }
  }

  console.log(`  students            : ${students.length}`);
  console.log(`  active entitlements : ${students.length}`);
  console.log(`  discounted demands  : ${demands} (₹60 each, no allocation rows)`);
  console.log(`\n  Fixture ready. Expected: whatif fails I2 x${demands}, backfill creates ${demands} rows.`);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
