/**
 * staffSeparations — Firestore Rules unit tests (Phase 3B.1).
 *
 * Deliberately narrower than `staff`'s own broad same-school read (Phase 3B
 * locked decision): read gate = same as write gate, SIS/manage only.
 * Ordinary same-school Teachers/Parents/Students may still read a staff
 * member's bare CURRENT STATUS via the unchanged `staff` rule — this
 * collection is the separation workflow record itself, not that.
 *
 * Run: cd firebase-rules/tests && npm test
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

const SCHOOL = 'SCH_TEST_SEP_001';
const OTHER_SCHOOL = 'SCH_TEST_SEP_002';

const ADMIN_SIS_MANAGE = 'admin-sep-manage';
const ADMIN_SIS_VIEW   = 'admin-sep-view';
const TEACHER_UID      = 'teacher-sep-1';
const PARENT_UID       = 'parent-sep-1';
const STUDENT_UID      = 'student-sep-1';

let env;

const sepDocOf = (school, id) => `staffSeparations/${school}_${id}`;
const staffDocOf = (school, staffId) => `staff/${school}_${staffId}`;

function asRole(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
function asAnon() {
  return env.unauthenticatedContext().firestore();
}

function baseSeparation(overrides = {}) {
  return {
    schoolId: SCHOOL, staffId: 'STA_SEP_1', separationType: 'Resignation',
    reason: 'Relocating', status: 'SEPARATED', actualLastWorkingDate: '2026-08-29',
    initiatedBy: ADMIN_SIS_MANAGE, initiatedAt: '2026-08-29 10:00:00', updatedAt: '2026-08-29 10:00:00',
    employmentEventRef: 'evt_ref_1',
    ...overrides,
  };
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
    await db.doc(`staffCapabilities/${ADMIN_SIS_MANAGE}`).set({ modules: ['SIS'], levels: { SIS: 'manage' }, admin: false });
    await db.doc(`staffCapabilities/${ADMIN_SIS_VIEW}`).set({ modules: ['SIS'], levels: { SIS: 'view' }, admin: false });
    await db.doc(`staffCapabilities/${TEACHER_UID}`).set({ modules: [], levels: {}, admin: false });

    await db.doc(staffDocOf(SCHOOL, 'STA_SEP_1')).set({
      schoolId: SCHOOL, staffId: 'STA_SEP_1', name: 'Departed Staff', status: 'Inactive',
      exitDate: '2026-08-29', lastWorkingDate: '2026-08-29',
    });
    await db.doc(sepDocOf(SCHOOL, 'evt_existing')).set(baseSeparation());
    await db.doc(sepDocOf(OTHER_SCHOOL, 'evt_existing2')).set(baseSeparation({ schoolId: OTHER_SCHOOL, staffId: 'STA_SEP_2' }));
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

describe('staffSeparations — create/update, SIS/manage gated', () => {
  test('SAME-SCHOOL admin with SIS:manage CAN create a separation record', async () => {
    await assertSucceeds(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin').doc(sepDocOf(SCHOOL, 'evt_new_ok')).set(baseSeparation())
    );
  });

  test('SAME-SCHOOL admin with only SIS:view CANNOT create a separation record', async () => {
    await assertFails(
      asRole(ADMIN_SIS_VIEW, SCHOOL, 'admin').doc(sepDocOf(SCHOOL, 'evt_new_denied')).set(baseSeparation())
    );
  });

  test('a non-admin TEACHER cannot create a separation record', async () => {
    await assertFails(
      asRole(TEACHER_UID, SCHOOL, 'Teacher').doc(sepDocOf(SCHOOL, 'evt_new_teacher_denied')).set(baseSeparation())
    );
  });

  test('CROSS-SCHOOL create is denied', async () => {
    await assertFails(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin').doc(sepDocOf(OTHER_SCHOOL, 'evt_cross_denied')).set(baseSeparation({ schoolId: OTHER_SCHOOL }))
    );
  });

  test('unauthenticated write is denied', async () => {
    await assertFails(asAnon().doc(sepDocOf(SCHOOL, 'evt_anon_denied')).set(baseSeparation()));
  });
});

describe('staffSeparations — no delete', () => {
  test('DELETE is denied — a separation record is never removed', async () => {
    await assertFails(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin').doc(sepDocOf(SCHOOL, 'evt_existing')).delete()
    );
  });
});

describe('staffSeparations — read is narrower than staff\'s own broad same-school read', () => {
  test('SIS/manage admin CAN read a separation record', async () => {
    await assertSucceeds(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin').doc(sepDocOf(SCHOOL, 'evt_existing')).get()
    );
  });

  test('a same-school TEACHER CANNOT read separation detail (reason/dates)', async () => {
    await assertFails(
      asRole(TEACHER_UID, SCHOOL, 'Teacher').doc(sepDocOf(SCHOOL, 'evt_existing')).get()
    );
  });

  test('a same-school PARENT CANNOT read separation detail', async () => {
    await assertFails(
      asRole(PARENT_UID, SCHOOL, 'Parent').doc(sepDocOf(SCHOOL, 'evt_existing')).get()
    );
  });

  test('a same-school STUDENT CANNOT read separation detail', async () => {
    await assertFails(
      asRole(STUDENT_UID, SCHOOL, 'Student').doc(sepDocOf(SCHOOL, 'evt_existing')).get()
    );
  });

  test('CROSS-SCHOOL read is denied even for an SIS/manage admin of a different school', async () => {
    await assertFails(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin').doc(sepDocOf(OTHER_SCHOOL, 'evt_existing2')).get()
    );
  });

  test('BUT a Teacher/Parent/Student CAN still read the staff member\'s bare current status via the unchanged staff rule', async () => {
    const snap = await asRole(PARENT_UID, SCHOOL, 'Parent').doc(staffDocOf(SCHOOL, 'STA_SEP_1')).get();
    await assertSucceeds(Promise.resolve(snap));
    expect(snap.data().status).toBe('Inactive');
  });
});
