/**
 * LIFECYCLE BOUNDARY — student-status read gate (isActiveStudent), 2026-09-19.
 *
 * Verifies the new /students GET gate:
 *   - A non-staff principal (parent/student token) may GET a student doc ONLY
 *     when it is Active. An Inactive/TC/Alumni/withdrawn doc is DENIED.
 *   - Staff (teacher/admin by role claim) may GET ANY student (rosters, alumni
 *     lists, TC records depend on it).
 *   - A missing status field FAILS OPEN (reads as Active) — a legit active
 *     student without the field is never locked out.
 *   - Existence-probe (non-existent doc) stays allowed.
 *   - LIST is unchanged (sibling lookup + staff queries keep working).
 *
 * Run: cd firebase-rules/tests && npm test  (or emulators:exec with jest)
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
const SCHOOL_ID = 'SCH_TEST_STATUS_001';

let env;

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    // Active tenant so tenantActive() passes for every role.
    await db.doc(`schoolControl/${SCHOOL_ID}`).set({
      schoolId: SCHOOL_ID,
      lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
    });
    await db.doc(`schools/${SCHOOL_ID}`).set({
      schoolId: SCHOOL_ID, name: 'Status Gate School',
      adminDisabled: { value: false, updatedAt: 'test' },
    });
    await db.doc(`tenantPublic/${SCHOOL_ID}`).set({
      schoolId: SCHOOL_ID, lifecycleState: 'active', adminDisabled: false,
    });
    // Three students: Active, Inactive, and one with NO status field.
    await db.doc(`students/${SCHOOL_ID}_STU_ACTIVE`).set({
      schoolId: SCHOOL_ID, studentId: 'STU_ACTIVE', name: 'Active Kid', status: 'Active',
    });
    await db.doc(`students/${SCHOOL_ID}_STU_INACTIVE`).set({
      schoolId: SCHOOL_ID, studentId: 'STU_INACTIVE', name: 'Left Kid', status: 'Inactive',
    });
    await db.doc(`students/${SCHOOL_ID}_STU_NOSTATUS`).set({
      schoolId: SCHOOL_ID, studentId: 'STU_NOSTATUS', name: 'No Status Kid',
    });
  });
}

function authedDb(role) {
  return env
    .authenticatedContext('test-user', { school_id: SCHOOL_ID, role })
    .firestore();
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seedWorld(); });

describe('students GET — status gate', () => {
  test('parent GET Active student → ALLOWED', async () => {
    await assertSucceeds(authedDb('parent').doc(`students/${SCHOOL_ID}_STU_ACTIVE`).get());
  });

  test('parent GET Inactive student → DENIED (the fix)', async () => {
    await assertFails(authedDb('parent').doc(`students/${SCHOOL_ID}_STU_INACTIVE`).get());
  });

  test('parent GET student with NO status field → ALLOWED (fail-open)', async () => {
    await assertSucceeds(authedDb('parent').doc(`students/${SCHOOL_ID}_STU_NOSTATUS`).get());
  });

  test('teacher GET Inactive student → ALLOWED (staff override)', async () => {
    await assertSucceeds(authedDb('teacher').doc(`students/${SCHOOL_ID}_STU_INACTIVE`).get());
  });

  test('admin GET Inactive student → ALLOWED (staff override)', async () => {
    await assertSucceeds(authedDb('admin').doc(`students/${SCHOOL_ID}_STU_INACTIVE`).get());
  });

  test('parent GET non-existent student → ALLOWED (existence probe preserved)', async () => {
    await assertSucceeds(authedDb('parent').doc(`students/${SCHOOL_ID}_STU_MISSING`).get());
  });
});

describe('students LIST — unchanged (sibling lookup preserved)', () => {
  // A real client lists with a schoolId filter (isSameSchool() has always
  // required it for list; an unfiltered list was denied before this change too).
  // These prove LIST semantics are untouched by the GET status-gate.
  test('parent LIST (schoolId-filtered) students → ALLOWED', async () => {
    await assertSucceeds(
      authedDb('parent').collection('students').where('schoolId', '==', SCHOOL_ID).get()
    );
  });

  test('teacher LIST (schoolId-filtered) students → ALLOWED', async () => {
    await assertSucceeds(
      authedDb('teacher').collection('students').where('schoolId', '==', SCHOOL_ID).get()
    );
  });
});
