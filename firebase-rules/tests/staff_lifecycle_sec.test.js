/**
 * staff / subjectAssignments / userDevices — Firestore Rules unit tests
 * (Staff Lifecycle Phase 0 security hardening).
 *
 * The Phase 0 audit found three confirmed tenant-isolation gaps and this
 * file locks each one down with the same load-bearing-case discipline as
 * locations_sec.test.js / recruitment_sec.test.js:
 *
 *   1. userDevices — was bare isAuth() on read/create/update (NO tenant
 *      binding at all) and isAdmin() alone on delete. Fixed to
 *      isSameSchool()/isSameSchoolWrite(), same idiom as `staff`.
 *      userDevices doc ids are `{userId}_{deviceId}` — NOT school-prefixed
 *      (Device_service.php) — and staffId is only unique PER SCHOOL
 *      (sequential counter), so the sharpest test here is a same-docId
 *      COLLISION across two schools, not just a different-docId read.
 *
 *   2. staff — write was isAdmin() alone, not bound to the target doc's
 *      own school (same defect `locations` had pre-Phase-1-fix).
 *
 *   3. subjectAssignments — same defect, same fix, as staff.
 *
 * Run: cd firebase-rules/tests && npm test
 *   (or: npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *        "npx jest staff_lifecycle_sec --runInBand --forceExit")
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

const SCHOOL = 'SCH_TEST_SL_001';
const OTHER_SCHOOL = 'SCH_TEST_SL_002';

const ADMIN = 'admin-sl-1';
const OTHER_ADMIN = 'admin-sl-2';
const STAFF_UID = 'STA_SL_1';

// Deliberately the SAME staffId/deviceId pair under two different schools —
// this is the exact per-school-counter-collision scenario the audit flagged.
const COLLIDING_STAFF_ID = 'STA0007';
const COLLIDING_DEVICE_ID = 'devABC123';

let env;

const staffDocOf = (school, staffId) => `staff/${school}_${staffId}`;
const saDocOf = (school, id) => `subjectAssignments/${school}_${id}`;
const deviceDocOf = (userId, deviceId) => `userDevices/${userId}_${deviceId}`;
const sectionDocOf = (school, id) => `sections/${school}_${id}`;

function asStaff(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'Teacher', user_id: uid }).firestore();
}
function asAdmin(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'admin', user_id: uid }).firestore();
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

    await db.doc(staffDocOf(SCHOOL, 'STA_EXIST_1')).set({
      schoolId: SCHOOL, staffId: 'STA_EXIST_1', name: 'Existing Teacher A', status: 'Active',
    });
    await db.doc(staffDocOf(OTHER_SCHOOL, 'STA_EXIST_2')).set({
      schoolId: OTHER_SCHOOL, staffId: 'STA_EXIST_2', name: 'Existing Teacher B', status: 'Active',
    });

    await db.doc(saDocOf(SCHOOL, 'SA_EXIST_1')).set({
      schoolId: SCHOOL, teacherId: 'STA_EXIST_1', classId: 'C1', subjectId: 'SUB1', archived: false,
    });
    await db.doc(saDocOf(OTHER_SCHOOL, 'SA_EXIST_2')).set({
      schoolId: OTHER_SCHOOL, teacherId: 'STA_EXIST_2', classId: 'C1', subjectId: 'SUB1', archived: false,
    });

    await db.doc(sectionDocOf(SCHOOL, 'SEC_EXIST_1')).set({
      schoolId: SCHOOL, className: 'Class 5', section: 'A', session: '2026-27',
      maxStrength: 40, classTeacherId: 'STA_EXIST_1',
    });
    await db.doc(sectionDocOf(OTHER_SCHOOL, 'SEC_EXIST_2')).set({
      schoolId: OTHER_SCHOOL, className: 'Class 5', section: 'A', session: '2026-27',
      maxStrength: 40, classTeacherId: 'STA_EXIST_2',
    });

    // The load-bearing collision seed: SCHOOL's own device doc at the SAME
    // docId ("{userId}_{deviceId}") that OTHER_SCHOOL's admin will attempt
    // to overwrite in the isolation tests below.
    await db.doc(deviceDocOf(COLLIDING_STAFF_ID, COLLIDING_DEVICE_ID)).set({
      schoolId: SCHOOL, userId: COLLIDING_STAFF_ID, deviceId: COLLIDING_DEVICE_ID,
      platform: 'android', status: 'active',
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

describe('userDevices rules — tenant isolation (the confirmed gap)', () => {
  test('same-school staff CAN read their own device doc', async () => {
    await assertSucceeds(
      asStaff(COLLIDING_STAFF_ID, SCHOOL).doc(deviceDocOf(COLLIDING_STAFF_ID, COLLIDING_DEVICE_ID)).get()
    );
  });

  test("cross-school staff CANNOT read another school's device doc, even at a colliding docId", async () => {
    await assertFails(
      asStaff('STA_SL_OTHER', OTHER_SCHOOL).doc(deviceDocOf(COLLIDING_STAFF_ID, COLLIDING_DEVICE_ID)).get()
    );
  });

  test("cross-school user CANNOT create/overwrite another school's device doc at a colliding id (the load-bearing case)", async () => {
    await assertFails(
      asStaff(COLLIDING_STAFF_ID, OTHER_SCHOOL)
        .doc(deviceDocOf(COLLIDING_STAFF_ID, COLLIDING_DEVICE_ID))
        .set({ schoolId: OTHER_SCHOOL, userId: COLLIDING_STAFF_ID, deviceId: COLLIDING_DEVICE_ID, platform: 'android', status: 'active' }, { merge: true })
    );
  });

  test("cross-school admin CANNOT delete another school's device doc", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(deviceDocOf(COLLIDING_STAFF_ID, COLLIDING_DEVICE_ID)).delete()
    );
  });

  test('same-school user CAN register a new device for themselves', async () => {
    await assertSucceeds(
      asStaff(STAFF_UID, SCHOOL).doc(deviceDocOf(STAFF_UID, 'devNEW999')).set({
        schoolId: SCHOOL, userId: STAFF_UID, deviceId: 'devNEW999', platform: 'android', status: 'active',
      })
    );
  });

  test('a create attempt whose payload schoolId does not match the caller is denied', async () => {
    await assertFails(
      asStaff(STAFF_UID, SCHOOL).doc(deviceDocOf(STAFF_UID, 'devSPOOF')).set({
        schoolId: OTHER_SCHOOL, userId: STAFF_UID, deviceId: 'devSPOOF', platform: 'android', status: 'active',
      })
    );
  });

  test('unauthenticated client CANNOT read any device doc', async () => {
    await assertFails(asAnon().doc(deviceDocOf(COLLIDING_STAFF_ID, COLLIDING_DEVICE_ID)).get());
  });

  test('same-school admin CAN delete a device doc in their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(deviceDocOf(COLLIDING_STAFF_ID, COLLIDING_DEVICE_ID)).delete()
    );
  });
});

describe('staff rules — write tenant binding', () => {
  test('same-school admin CAN update a staff record in their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(staffDocOf(SCHOOL, 'STA_EXIST_1')).set({ department: 'Science' }, { merge: true })
    );
  });

  test("cross-school admin CANNOT update another school's staff record", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(staffDocOf(SCHOOL, 'STA_EXIST_1')).set({ department: 'Hijacked' }, { merge: true })
    );
  });

  test("cross-school admin CANNOT delete another school's staff record", async () => {
    await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(staffDocOf(SCHOOL, 'STA_EXIST_1')).delete());
  });

  test('cross-school admin CANNOT create a staff record under another school id', async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(`staff/${SCHOOL}_STA_INJECTED`).set({
        schoolId: SCHOOL, staffId: 'STA_INJECTED', name: 'Injected', status: 'Active',
      })
    );
  });

  test('non-admin same-school staff CANNOT write another staff record', async () => {
    await assertFails(
      asStaff(STAFF_UID, SCHOOL).doc(staffDocOf(SCHOOL, 'STA_EXIST_1')).set({ department: 'Staff edit' }, { merge: true })
    );
  });
});

describe('subjectAssignments rules — write tenant binding', () => {
  test('same-school admin CAN update an assignment in their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(saDocOf(SCHOOL, 'SA_EXIST_1')).set({ archived: true }, { merge: true })
    );
  });

  test("cross-school admin CANNOT update another school's assignment", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(saDocOf(SCHOOL, 'SA_EXIST_1')).set({ archived: true }, { merge: true })
    );
  });

  test("cross-school admin CANNOT delete another school's assignment", async () => {
    await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(saDocOf(SCHOOL, 'SA_EXIST_1')).delete());
  });

  test('cross-school admin CANNOT create an assignment under another school id', async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(`subjectAssignments/${SCHOOL}_SA_INJECTED`).set({
        schoolId: SCHOOL, teacherId: 'STA_EXIST_1', classId: 'C1', subjectId: 'SUB1', archived: false,
      })
    );
  });

  test('non-admin same-school staff CANNOT write an assignment', async () => {
    await assertFails(
      asStaff(STAFF_UID, SCHOOL).doc(saDocOf(SCHOOL, 'SA_EXIST_1')).set({ archived: true }, { merge: true })
    );
  });
});

describe('sections rules — write tenant binding (Phase 0.1)', () => {
  test('same-school admin CAN create a section in their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(sectionDocOf(SCHOOL, 'SEC_NEW1')).set({
        schoolId: SCHOOL, className: 'Class 6', section: 'B', session: '2026-27', maxStrength: 35,
      })
    );
  });

  test("cross-school admin CANNOT create a section under another school id", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(`sections/${SCHOOL}_SEC_INJECTED`).set({
        schoolId: SCHOOL, className: 'Class 6', section: 'C', session: '2026-27', maxStrength: 35,
      })
    );
  });

  test('same-school admin CAN update a section in their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(sectionDocOf(SCHOOL, 'SEC_EXIST_1')).set({ maxStrength: 42 }, { merge: true })
    );
  });

  test("cross-school admin CANNOT update another school's section", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(sectionDocOf(SCHOOL, 'SEC_EXIST_1')).set({ maxStrength: 999 }, { merge: true })
    );
  });

  test("same-school admin CANNOT change a section's schoolId to another school (hijack-by-write)", async () => {
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(sectionDocOf(SCHOOL, 'SEC_EXIST_1')).set({ schoolId: OTHER_SCHOOL }, { merge: true })
    );
  });

  test("cross-school admin CANNOT update another school's section even with the exact document id known", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(sectionDocOf(SCHOOL, 'SEC_EXIST_1')).set({ classTeacherId: 'STA_HIJACK' }, { merge: true })
    );
  });

  // NOTE: order matters below — the two "CANNOT delete" denial tests must
  // run BEFORE the "CAN delete" success test actually removes SEC_EXIST_1,
  // since a delete attempt against an already-deleted doc changes the rule
  // evaluation (isSameSchool()'s `resource == null` branch trivially passes
  // once the doc is gone) and would produce a false test result either way.
  test("cross-school admin CANNOT delete another school's section", async () => {
    await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(sectionDocOf(SCHOOL, 'SEC_EXIST_1')).delete());
  });

  test('unauthenticated client CANNOT read, create, or delete any section', async () => {
    await assertFails(asAnon().doc(sectionDocOf(SCHOOL, 'SEC_EXIST_1')).get());
    await assertFails(
      asAnon().doc(`sections/${SCHOOL}_SEC_ANON`).set({ schoolId: SCHOOL, className: 'X', section: 'Y' })
    );
    await assertFails(asAnon().doc(sectionDocOf(SCHOOL, 'SEC_EXIST_1')).delete());
  });

  test('non-admin same-school staff CANNOT write a section', async () => {
    await assertFails(
      asStaff(STAFF_UID, SCHOOL).doc(sectionDocOf(SCHOOL, 'SEC_EXIST_1')).set({ maxStrength: 50 }, { merge: true })
    );
  });

  test('same-school admin CAN delete a section in their own school', async () => {
    await assertSucceeds(asAdmin(ADMIN, SCHOOL).doc(sectionDocOf(SCHOOL, 'SEC_EXIST_1')).delete());
  });
});
