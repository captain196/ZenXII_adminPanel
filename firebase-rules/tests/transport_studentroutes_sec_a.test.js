/**
 * studentRoutes — Firestore Rules unit tests for TRN-SEC-A (2026-08-15).
 *
 * studentRoutes was the ONE Transport collection whose read had no ownership
 * arm: `allow read: if isSameSchool()`. isSameSchool() is satisfied by ANY
 * authenticated user in the tenant — isStaff() is the helper that excludes
 * parents/students — so any parent could enumerate EVERY student's route, stop
 * and pickup time. That maps a named child to where they wait alone each
 * morning, so it is child-safety data rather than ordinary PII.
 *
 * The fix adds a single ownership arm on `student_ids`, which
 * Firebase::buildCanonicalClaims() guarantees for every student/parent login
 * (Firebase.php:855-862 forces student_ids = [student_id] when absent).
 *
 * Run: cd firebase-rules/tests && npm test
 *   (or: npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *        "npx jest transport_studentroutes_sec_a --runInBand --forceExit")
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

const SCHOOL_A = 'SCH_TEST_SECA_001';
const SCHOOL_B = 'SCH_TEST_SECA_002';

const STU_OWN   = 'STU_OWN1';     // the parent's own child
const STU_SIB   = 'STU_SIB1';     // second child of the multi-child parent
const STU_OTHER = 'STU_OTHER1';   // an unrelated same-school student

let env;

const srDoc = (school, studentId) => `studentRoutes/${school}_${studentId}`;

function route(school, studentId) {
  return {
    schoolId: school,
    studentId: studentId,
    route_id: 'RT1',
    stop_id: 'STP1',
    session: '2026-27',
    status: 'Active',
    type: 'both',
    direction: 'Both',
  };
}

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL_A, SCHOOL_B]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: 'SEC-A Test ' + s });
    }
    await db.doc(srDoc(SCHOOL_A, STU_OWN)).set(route(SCHOOL_A, STU_OWN));
    await db.doc(srDoc(SCHOOL_A, STU_SIB)).set(route(SCHOOL_A, STU_SIB));
    await db.doc(srDoc(SCHOOL_A, STU_OTHER)).set(route(SCHOOL_A, STU_OTHER));
    await db.doc(srDoc(SCHOOL_B, STU_OTHER)).set(route(SCHOOL_B, STU_OTHER));
  });
}

// Parent/student accounts log in AS the student: uid == studentId, and the
// canonical claims builder always emits student_ids (Firebase.php:855-862).
function parent(school, uid, studentIds) {
  return env.authenticatedContext(uid, {
    school_id: school, role: 'Parent', student_id: studentIds[0], student_ids: studentIds,
  }).firestore();
}
function staff(school, uid) {
  return env.authenticatedContext(uid, {
    school_id: school, role: 'Teacher', user_id: uid,
  }).firestore();
}
function admin(school, uid) {
  return env.authenticatedContext(uid, {
    school_id: school, role: 'admin', user_id: uid,
  }).firestore();
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seedWorld(); });

describe('studentRoutes TRN-SEC-A — read is ownership-bound', () => {

  test('SEC-1 same-school staff can read studentRoutes', async () => {
    const db = staff(SCHOOL_A, 'STA_T1');
    await assertSucceeds(db.doc(srDoc(SCHOOL_A, STU_OWN)).get());
  });

  test('SEC-2 parent can read their OWN child route', async () => {
    const db = parent(SCHOOL_A, STU_OWN, [STU_OWN]);
    await assertSucceeds(db.doc(srDoc(SCHOOL_A, STU_OWN)).get());
  });

  test('SEC-3 parent CANNOT read another student route (the TRN-SEC-A hole)', async () => {
    const db = parent(SCHOOL_A, STU_OWN, [STU_OWN]);
    await assertFails(db.doc(srDoc(SCHOOL_A, STU_OTHER)).get());
  });

  test('SEC-4 multi-child parent can read EACH authorized child', async () => {
    const db = parent(SCHOOL_A, STU_OWN, [STU_OWN, STU_SIB]);
    await assertSucceeds(db.doc(srDoc(SCHOOL_A, STU_OWN)).get());
    await assertSucceeds(db.doc(srDoc(SCHOOL_A, STU_SIB)).get());
  });

  test('SEC-5 multi-child parent still cannot reach an unrelated student', async () => {
    const db = parent(SCHOOL_A, STU_OWN, [STU_OWN, STU_SIB]);
    await assertFails(db.doc(srDoc(SCHOOL_A, STU_OTHER)).get());
  });

  test('SEC-6 cross-school parent is denied', async () => {
    // Same studentId value exists in School B; the tenant arm must still deny.
    const db = parent(SCHOOL_A, STU_OTHER, [STU_OTHER]);
    await assertFails(db.doc(srDoc(SCHOOL_B, STU_OTHER)).get());
  });

  test('SEC-6b cross-school staff is denied', async () => {
    const db = staff(SCHOOL_A, 'STA_T1');
    await assertFails(db.doc(srDoc(SCHOOL_B, STU_OTHER)).get());
  });

  test('SEC-7 writes remain admin-only', async () => {
    const p = parent(SCHOOL_A, STU_OWN, [STU_OWN]);
    const t = staff(SCHOOL_A, 'STA_T1');
    const a = admin(SCHOOL_A, 'admin-uid-1');
    await assertFails(p.doc(srDoc(SCHOOL_A, STU_OWN)).set(route(SCHOOL_A, STU_OWN)));
    await assertFails(t.doc(srDoc(SCHOOL_A, STU_OWN)).set(route(SCHOOL_A, STU_OWN)));
    await assertSucceeds(a.doc(srDoc(SCHOOL_A, STU_OWN)).set(route(SCHOOL_A, STU_OWN)));
  });

  test('SEC-8 a parent with NO student_ids claim is denied (fails closed)', async () => {
    const db = env.authenticatedContext('SOMEONE', { school_id: SCHOOL_A, role: 'Parent' }).firestore();
    await assertFails(db.doc(srDoc(SCHOOL_A, STU_OWN)).get());
  });

  test('SEC-9 unauthenticated is denied', async () => {
    const db = env.unauthenticatedContext().firestore();
    await assertFails(db.doc(srDoc(SCHOOL_A, STU_OWN)).get());
  });

  test('SEC-10 a parent cannot enumerate the collection', async () => {
    const db = parent(SCHOOL_A, STU_OWN, [STU_OWN]);
    // A list over the whole collection would expose every child's stop; the
    // rule is evaluated per-document, so an unfiltered list must fail.
    await assertFails(db.collection('studentRoutes').get());
  });
});
