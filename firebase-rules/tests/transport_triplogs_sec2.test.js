/**
 * tripLogs — Firestore Rules unit tests for SEC-2 (2026-07-22).
 *
 * A bare isStaff() create let any same-school staff plant a trip with an
 * arbitrary driver_staff_id and then drive its state machine (forged safety
 * events). No client creates tripLogs today (admin PHP via service account
 * bypasses rules; the Teacher app only UPDATEs), so the staff create arm is now
 * bound to the trip's OWN driver: a driver may create only a trip whose
 * driver_staff_id equals their user_id claim. Admin retains unrestricted create.
 *
 * Run: cd firebase-rules/tests && npm test
 *   (npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *      "npx jest transport_triplogs_sec2 --runInBand --forceExit")
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
const SCHOOL = 'SCH_TEST_SEC2_001';

const D1 = 'STA_DRIVER1';
const X  = 'STA_OTHER1';

let env;

const tripId = (k) => `tripLogs/${SCHOOL}_${k}`;

function trip(overrides) {
  return Object.assign({
    schoolId: SCHOOL,
    id: 'TRP1',
    driver_staff_id: D1,
    status: 'Ready',
    route_id: 'RT1',
    roster_student_ids: ['STU1'],
  }, overrides || {});
}

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.doc(`schoolControl/${SCHOOL}`).set({
      schoolId: SCHOOL, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
    });
    await db.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL, name: 'SEC2 Test School' });
  });
}

// uid == staff id so request.auth.token.user_id resolves to the staff id.
function staff(userId) {
  return env.authenticatedContext(userId, { school_id: SCHOOL, role: 'Teacher', user_id: userId }).firestore();
}
function staffNoUserId(uid) {
  return env.authenticatedContext(uid, { school_id: SCHOOL, role: 'Teacher' }).firestore();
}
function admin(uid) {
  return env.authenticatedContext(uid, { school_id: SCHOOL, role: 'admin', user_id: uid }).firestore();
}
function parent(uid) {
  return env.authenticatedContext(uid, { school_id: SCHOOL, role: 'parent', user_id: uid }).firestore();
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seedWorld(); });

describe('tripLogs SEC-2 — create bound to trip driver', () => {
  test('DRIVER creates own trip (driver_staff_id == user_id) → allowed', async () => {
    await assertSucceeds(staff(D1).doc(tripId('TRP1')).set(trip()));
  });

  test('staff FORGES a trip for another driver → DENIED (core SEC-2)', async () => {
    await assertFails(staff(X).doc(tripId('TRP1')).set(trip({ driver_staff_id: D1 })));
  });

  test('ADMIN creates a trip for any driver → allowed', async () => {
    await assertSucceeds(admin('adm1').doc(tripId('TRP1')).set(trip({ driver_staff_id: D1 })));
  });

  test('staff with EMPTY user_id claim → DENIED', async () => {
    await assertFails(staffNoUserId('nouid').doc(tripId('TRP1')).set(trip({ driver_staff_id: '' })));
  });

  test('PARENT create → DENIED', async () => {
    await assertFails(parent('p1').doc(tripId('TRP1')).set(trip({ driver_staff_id: 'p1' })));
  });
});
