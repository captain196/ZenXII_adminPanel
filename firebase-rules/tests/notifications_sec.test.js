/**
 * notifications — Firestore Rules tests (tenant binding on write).
 *
 * Remediation P1 #22 (2026-08-12). Pre-fix contract was:
 *     match /notifications/{docId} { allow read: isSameSchool(); allow write: isStaff(); }
 *
 * `isStaff()` carries no binding to the target document's school, so School A
 * staff could create a notification stamped schoolId:"SCH_B" — which School B's
 * correctly-scoped read rule then surfaces to B's staff/parents/students as
 * legitimate content — or edit/delete School B's existing rows.
 *
 * Post-fix:
 *     create: isStaff() && isSameSchoolWrite()
 *     update: isStaff() && isSameSchool() && BOTH tenant fields frozen
 *     delete: isStaff() && isSameSchool()
 *
 * The update arm deliberately does NOT use isSameSchoolWrite(). Legacy rows
 * written by Entity_firestore_sync::syncNotification carry `schoolCode` and NO
 * `schoolId`; requiring a non-empty payload schoolId would break the Teacher
 * app's markNotificationRead() (a {read:true} field update) on every one of
 * them. Freezing both tenant fields blocks the rewrite vector without that
 * cost. The legacy-shape non-regression case below is the reason for that
 * design and MUST keep passing.
 *
 * Run: cd firebase-rules/tests && npm test
 *   (or: npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *        "npx jest notifications_sec --runInBand --forceExit")
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

const SCHOOL = 'SCH_TEST_NOTIF_001';
const OTHER_SCHOOL = 'SCH_TEST_NOTIF_002';

const N_CANON = `${SCHOOL}_N0001`;        // canonical shape: has schoolId
const N_LEGACY = `${SCHOOL}_N0002`;       // legacy shape: schoolCode only, NO schoolId
const N_OTHER = `${OTHER_SCHOOL}_N0001`;

const STAFF = 'STA_N1';
const OTHER_STAFF = 'STA_N2';
const PARENT = 'STU_N_P1';

let env;

function asStaff(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'Teacher', user_id: uid }).firestore();
}
function asParent(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'Parent', student_id: uid }).firestore();
}
function asAnon() {
  return env.unauthenticatedContext().firestore();
}

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL, OTHER_SCHOOL]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test School` });
    }
    await db.doc(`notifications/${N_CANON}`).set({
      schoolId: SCHOOL, userId: STAFF, title: 'Canonical', body: 'x', read: false,
    });
    // Legacy shape produced by Entity_firestore_sync::syncNotification —
    // schoolCode present, schoolId ABSENT. isSameSchool() accepts it via its
    // schoolCode arm; isSameSchoolWrite() would NOT.
    await db.doc(`notifications/${N_LEGACY}`).set({
      schoolCode: SCHOOL, userId: STAFF, title: 'Legacy', body: 'y', read: false,
    });
    await db.doc(`notifications/${N_OTHER}`).set({
      schoolId: OTHER_SCHOOL, userId: OTHER_STAFF, title: 'Other tenant', body: 'z', read: false,
    });
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
  await seedWorld();
}, 120000);
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await seedWorld(); });

describe('notifications — P1 #22 create is tenant-bound', () => {
  test('THE FINDING: staff CANNOT create a notification stamped with another school id', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`notifications/${OTHER_SCHOOL}_INJECTED`).set({
        schoolId: OTHER_SCHOOL, userId: 'STU_VICTIM', title: 'Injected', body: 'cross-tenant', read: false,
      })
    );
  });

  test('staff CAN create a notification for their own school', async () => {
    await assertSucceeds(
      asStaff(STAFF, SCHOOL).doc(`notifications/${SCHOOL}_NEW1`).set({
        schoolId: SCHOOL, userId: STAFF, title: 'Mine', body: 'ok', read: false,
      })
    );
  });

  test('create with NO schoolId is denied', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`notifications/${SCHOOL}_NEW2`).set({
        userId: STAFF, title: 'No tenant', body: 'x', read: false,
      })
    );
  });

  test('create with EMPTY schoolId is denied', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`notifications/${SCHOOL}_NEW3`).set({
        schoolId: '', userId: STAFF, title: 'Blank tenant', body: 'x', read: false,
      })
    );
  });

  test('parent (non-staff) CANNOT create a notification', async () => {
    await assertFails(
      asParent(PARENT, SCHOOL).doc(`notifications/${SCHOOL}_NEW4`).set({
        schoolId: SCHOOL, userId: PARENT, title: 'Self-serve', body: 'x', read: false,
      })
    );
  });

  test('unauthenticated CANNOT create a notification', async () => {
    await assertFails(
      asAnon().doc(`notifications/${SCHOOL}_ANON`).set({ schoolId: SCHOOL, title: 'Anon', body: 'x' })
    );
  });
});

describe('notifications — P1 #22 update cannot move a doc between tenants', () => {
  test('ESCALATION: staff CANNOT rewrite schoolId on an existing doc', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`notifications/${N_CANON}`).set({ schoolId: OTHER_SCHOOL }, { merge: true })
    );
  });

  test('ESCALATION: staff CANNOT rewrite schoolCode on a legacy doc', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`notifications/${N_LEGACY}`).set({ schoolCode: OTHER_SCHOOL }, { merge: true })
    );
  });

  test('ESCALATION: staff CANNOT bolt a foreign schoolId onto a legacy doc', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`notifications/${N_LEGACY}`).set({ schoolId: OTHER_SCHOOL }, { merge: true })
    );
  });

  test("cross-school staff CANNOT update another school's notification", async () => {
    await assertFails(
      asStaff(OTHER_STAFF, OTHER_SCHOOL).doc(`notifications/${N_CANON}`).set({ read: true }, { merge: true })
    );
  });

  test("cross-school staff CANNOT delete another school's notification", async () => {
    await assertFails(asStaff(OTHER_STAFF, OTHER_SCHOOL).doc(`notifications/${N_CANON}`).delete());
  });
});

describe('notifications — P1 #22 non-regression (the reason update != isSameSchoolWrite)', () => {
  test('LEGACY SHAPE: markNotificationRead on a schoolCode-only doc STILL WORKS', async () => {
    // This is the Teacher app's real write path. If this ever fails, the
    // update arm has been "tightened" to isSameSchoolWrite() and every
    // pre-canonical notification row has become unreadable-as-read.
    await assertSucceeds(
      asStaff(STAFF, SCHOOL).doc(`notifications/${N_LEGACY}`).set({ read: true }, { merge: true })
    );
  });

  test('CANONICAL SHAPE: markNotificationRead still works', async () => {
    await assertSucceeds(
      asStaff(STAFF, SCHOOL).doc(`notifications/${N_CANON}`).set({ read: true }, { merge: true })
    );
  });

  test('same-school staff CAN delete their own school\'s notification', async () => {
    await assertSucceeds(asStaff(STAFF, SCHOOL).doc(`notifications/${N_CANON}`).delete());
  });

  test('same-school read still works', async () => {
    await assertSucceeds(asStaff(STAFF, SCHOOL).doc(`notifications/${N_CANON}`).get());
  });

  test('cross-school read is still denied', async () => {
    await assertFails(asStaff(OTHER_STAFF, OTHER_SCHOOL).doc(`notifications/${N_CANON}`).get());
  });
});

describe('notifications — P1 #22 suspended tenant fails closed', () => {
  test('writes denied when the tenant lifecycle is suspended', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc(`schoolControl/${SCHOOL}`).set({
        schoolId: SCHOOL, lifecycle: { state: 'suspended', computedAt: Date.now(), reason: 'nonpayment' },
      });
    });
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`notifications/${SCHOOL}_SUSPENDED`).set({
        schoolId: SCHOOL, userId: STAFF, title: 'x', body: 'y', read: false,
      })
    );
  });
});
