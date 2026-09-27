/**
 * Emulator fixture for the P5-BF backfill proof.
 *
 * Seeds ONE tenant with a deliberately mixed estate so the execute run has
 * something real to decide about, and a SECOND tenant so cross-tenant
 * isolation is proven against actual data rather than fixtures in a unit test:
 *
 *   D1  600 gross · 60 discount · one 10% grant      -> CREATE
 *   D2  600 gross · 60 discount · one 10% grant      -> CREATE
 *   D3  600 gross ·  0 discount                      -> SKIP (I2 silent)
 *   D4  600 gross · 60 discount · grant now says 20% -> REFUSE (mismatch)
 *   D5  600 gross · 60 discount · already explained  -> SKIP
 *   B1  (other tenant) 600 · 60 · its own grant      -> must never be touched
 *
 * Emulator only — refuses without FIRESTORE_EMULATOR_HOST on loopback.
 */
const admin = require('firebase-admin');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
if (!['127.0.0.1', 'localhost', '::1'].includes(EMU.split(':')[0])) {
  console.error('REFUSING: FIRESTORE_EMULATOR_HOST must be loopback');
  process.exit(1);
}

const A = 'SCH_BFA', B = 'SCH_BFB', S = '2026-27';
admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

const demand = (school, id, gross, disc, student) => ({
  schoolId: school, session: S, demandId: id, studentId: student,
  grossAmount: gross, discountAmount: disc, netAmount: gross - disc,
  balance: gross - disc, paidAmount: 0, status: 'unpaid',
  feeHeadRef: 'FH_TUITION', periodKey: '2026-04', month: 'April',
});

const ent = (school, id, student, type, value) => ({
  schoolId: school, session: S, entitlementId: id, studentId: student,
  status: 'active', valueType: type, value, scope: 'student',
});

(async () => {
  const b = db.batch();

  // Tenant A
  b.set(db.collection('feeDemands').doc(`${A}_D1`), demand(A, 'D1', 600, 60, 'STU1'));
  b.set(db.collection('feeDemands').doc(`${A}_D2`), demand(A, 'D2', 600, 60, 'STU1'));
  b.set(db.collection('feeDemands').doc(`${A}_D3`), demand(A, 'D3', 600,  0, 'STU1'));
  b.set(db.collection('feeDemands').doc(`${A}_D4`), demand(A, 'D4', 600, 60, 'STU2'));
  b.set(db.collection('feeDemands').doc(`${A}_D5`), demand(A, 'D5', 600, 60, 'STU3'));

  b.set(db.collection('studentEntitlements').doc(`${A}_E1`), ent(A, 'E1', 'STU1', 'percent', 10));
  // STU2's grant now reads 20% — the demand was priced at 10%. Must REFUSE.
  b.set(db.collection('studentEntitlements').doc(`${A}_E2`), ent(A, 'E2', 'STU2', 'percent', 20));
  b.set(db.collection('studentEntitlements').doc(`${A}_E3`), ent(A, 'E3', 'STU3', 'percent', 10));

  // D5 already explained.
  b.set(db.collection('entitlementAllocations').doc(`${A}_D5_E3`), {
    allocationId: `${A}_D5_E3`, schoolId: A, session: S, studentId: 'STU3',
    demandId: 'D5', entitlementId: 'E3', basisAmount: 600, allocatedAmount: 60,
    status: 'active', valueType: 'percent', value: 10,
  });

  // Tenant B — identical shape, must be untouched by a run bound to A.
  b.set(db.collection('feeDemands').doc(`${B}_B1`), demand(B, 'B1', 600, 60, 'STU9'));
  b.set(db.collection('studentEntitlements').doc(`${B}_E9`), ent(B, 'E9', 'STU9', 'percent', 10));

  await b.commit();
  console.log(`seeded: ${A} (5 demands, 3 entitlements, 1 existing allocation) + ${B} (1 demand)`);
  process.exit(0);
})();
