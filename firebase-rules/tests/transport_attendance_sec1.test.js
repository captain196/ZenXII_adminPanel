/**
 * transportAttendance — Firestore Rules unit tests for SEC-1 (2026-07-22).
 *
 * A boarding event is a parent-facing safety signal (a forged `no_show` fires
 * a false alert). The prior create rule allowed ANY same-school staff to write
 * one for ANY trip. SEC-1 binds non-admin creates to the DRIVER OF THE
 * REFERENCED TRIP: the caller's user_id claim must equal the trip's
 * driver_staff_id. Admin retains unrestricted create.
 *
 * Run: cd firebase-rules/tests && npm install && npm test
 *   (or: npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *        "npx jest transport_attendance_sec1 --runInBand --forceExit")
 */
const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');
const SCHOOL = 'SCH_TEST_SEC1_001';

const DRIVER = 'STA_DRIVER1';   // trip's assigned driver (user_id claim)
const OTHER  = 'STA_OTHER1';    // a different same-school staff
const ADMIN  = 'admin-uid-1';

let env;

const tripDoc = () => `tripLogs/${SCHOOL}_TRP1`;
const attId   = (evt) => `transportAttendance/${SCHOOL}_TRP1_STU1_${evt}`;
const attIdBadTrip = (evt) => `transportAttendance/${SCHOOL}_TRPX_STU1_${evt}`;

function meta() {
  return { app_version: '1.0.0', client_platform: 'android', client_timestamp: 1751000000000 };
}
function attendance(overrides) {
  return Object.assign({
    id: `${SCHOOL}_TRP1_STU1_no_show`,
    schoolId: SCHOOL,
    tripId: 'TRP1',
    studentId: 'STU1',
    route_id: 'RT1',
    event_type: 'no_show',
    marked_by_role: 'driver',
    marked_by_staff_id: DRIVER,
  }, meta(), overrides || {});
}

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.doc(`schoolControl/${SCHOOL}`).set({
      schoolId: SCHOOL, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
    });
    await db.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL, name: 'SEC1 Test School' });
    // Trip TRP1 belongs to driver STA_DRIVER1.
    await db.doc(tripDoc()).set({
      schoolId: SCHOOL, id: 'TRP1', driver_staff_id: DRIVER,
      status: 'InProgress', route_id: 'RT1', roster_student_ids: ['STU1'],
    });
  });
}

// Staff carry a free-form (non-student/parent) role + a user_id claim = staff id.
// NOTE: `user_id` is a RESERVED Firebase token field that the rules-unit-testing
// library mirrors to the auth uid, so we set uid == staff id to make
// request.auth.token.user_id resolve to the staff id (matching production, where
// setCanonicalClaims writes the SIS staff id into user_id).
function staff(userId) {
  return env.authenticatedContext(userId, { school_id: SCHOOL, role: 'Teacher', user_id: userId }).firestore();
}
function admin(uid) {
  return env.authenticatedContext(uid, { school_id: SCHOOL, role: 'admin', user_id: uid }).firestore();
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seedWorld(); });

describe('transportAttendance SEC-1 — client writes are server-mediated (F14)', () => {
  // RE-SCOPED at F14 (2026-08-12), not weakened — the assertion is strictly
  // stronger than before.
  //
  // SEC-1 originally bound a driver's client-side create to the trip they own,
  // which was the tightest thing security RULES can express. It is not enough:
  // rules cannot verify the student ID card's HMAC signature, nor resolve a
  // transport entitlement across studentRoutes status + effective dates +
  // session + service days. A student with a valid QR but NO transport facility
  // could therefore be recorded — precisely the hole F14 closes.
  //
  // So the driver arm is withdrawn entirely. Attendance mutations now go through
  // Transport_driver_api (authorized by trip ownership) and are written with the
  // admin SDK, which bypasses these rules by design.
  //
  // Old assertion: driver-of-trip create SUCCEEDS.
  // New assertion: NO driver client create succeeds at all.
  test('DRIVER OF THE TRIP client-create → DENIED (server-mediated only)', async () => {
    await assertFails(staff(DRIVER).doc(attId('no_show')).set(attendance()));
  });

  test('DIFFERENT staff forges no_show for the trip → DENIED (core SEC-1)', async () => {
    await assertFails(
      staff(OTHER).doc(attId('no_show')).set(attendance({ marked_by_staff_id: OTHER }))
    );
  });

  test('ADMIN creates attendance → allowed (unrestricted)', async () => {
    await assertSucceeds(admin(ADMIN).doc(attId('no_show')).set(attendance({ marked_by_role: 'admin', marked_by_staff_id: 'ADM1' })));
  });

  test('non-admin create referencing a NON-EXISTENT trip → DENIED', async () => {
    await assertFails(
      staff(DRIVER).doc(attIdBadTrip('no_show')).set(attendance({ id: `${SCHOOL}_TRPX_STU1_no_show`, tripId: 'TRPX' }))
    );
  });

  test('regression: driver of trip but MISSING client metadata → DENIED', async () => {
    const d = attendance();
    delete d.app_version;
    await assertFails(staff(DRIVER).doc(attId('no_show')).set(d));
  });

  test('regression: driver of trip but invalid marked_by_role → DENIED', async () => {
    await assertFails(
      staff(DRIVER).doc(attId('no_show')).set(attendance({ marked_by_role: 'teacher' }))
    );
  });

  test('regression: update/delete always denied', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc(attId('no_show')).set(attendance());
    });
    await assertFails(staff(DRIVER).doc(attId('no_show')).update({ notes: 'x' }));
    await assertFails(staff(DRIVER).doc(attId('no_show')).delete());
  });
});
