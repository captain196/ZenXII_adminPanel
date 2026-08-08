/**
 * students.prefLang — Firestore Rules unit tests.
 *
 * The Parent app mirrors the user's chosen language to their own students doc
 * so push can be composed in that language and a reinstall can restore it. The
 * rule is a SEPARATE `allow update` clause beside the mustChangePassword one:
 * widening that allowlist instead would let a student send both fields in a
 * single write, and it has to stay byte-narrow.
 *
 * What actually needs proving here is the escalation boundary. `students` is an
 * admin-write collection holding Class, Section and Status; a self-service
 * clause on it is only safe because hasOnly() pins the write to exactly two
 * fields. These tests are what stops a future edit from loosening that
 * silently.
 *
 * Run: cd firebase-rules/tests && npm install && npm test
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

const SCHOOL = 'SCH_TEST_LANG_001';
const OTHER_SCHOOL = 'SCH_TEST_LANG_002';
const STU_A = 'STU_LANG_A';
const STU_B = 'STU_LANG_B';

let env;

const docPath = (schoolId, studentId) => `students/${schoolId}_${studentId}`;

async function seed() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    // isSameSchool() chains to tenantActive(), which get()s schoolControl.
    // Without this doc every write fails with a rules *evaluation error*
    // rather than a clean deny — which also means the language mirror
    // correctly stops working once a school's subscription lapses.
    await db.doc(`schoolControl/${SCHOOL}`).set({
      schoolId: SCHOOL,
      lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
    });
    await db.doc(`schoolControl/${OTHER_SCHOOL}`).set({
      schoolId: OTHER_SCHOOL,
      lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
    });
    await db.doc(`schools/${OTHER_SCHOOL}`).set({ schoolId: OTHER_SCHOOL, name: 'Other School' });
    await db.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL, name: 'Lang Test School' });
    await db.doc(docPath(SCHOOL, STU_A)).set({
      schoolId: SCHOOL, studentId: STU_A, name: 'Aarav',
      className: '8', section: 'A', status: 'Active',
      // Seeded TRUE so the clearing test below produces a real diff. Writing a
      // field to the value it already holds does NOT appear in affectedKeys(),
      // so seeding false would make the two-field test pass vacuously.
      mustChangePassword: true,
    });
    await db.doc(docPath(SCHOOL, STU_B)).set({
      schoolId: SCHOOL, studentId: STU_B, name: 'Bhavna',
      className: '8', section: 'B', status: 'Active',
    });
    await db.doc(docPath(OTHER_SCHOOL, STU_A)).set({
      schoolId: OTHER_SCHOOL, studentId: STU_A, name: 'Other-tenant Aarav',
      className: '8', section: 'A', status: 'Active',
    });
  });
}

// The app authenticates as the student; auth.uid IS the studentId.
function student(uid, schoolId) {
  return env
    .authenticatedContext(uid, { school_id: schoolId, role: 'student' })
    .firestore();
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seed(); });

describe('students.prefLang — the write the app actually makes', () => {
  test('own doc, prefLang + updatedAt → allowed', async () => {
    await assertSucceeds(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A))
        .update({ prefLang: 'ta', updatedAt: '2026-08-08T00:00:00Z' })
    );
  });

  test('every shipped language tag is accepted', async () => {
    for (const tag of ['en', 'hi', 'mr', 'gu', 'ta', 'te']) {
      await assertSucceeds(
        student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A))
          .update({ prefLang: tag, updatedAt: '2026-08-08T00:00:00Z' })
      );
    }
  });

  test('prefLang alone, without updatedAt → allowed (hasOnly, not hasAll)', async () => {
    await assertSucceeds(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A)).update({ prefLang: 'hi' })
    );
  });
});

describe('students.prefLang — escalation boundary', () => {
  test('smuggling Section alongside prefLang → denied', async () => {
    await assertFails(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A))
        .update({ prefLang: 'hi', section: 'Z' })
    );
  });

  test('smuggling Status alongside prefLang → denied', async () => {
    await assertFails(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A))
        .update({ prefLang: 'hi', status: 'Inactive' })
    );
  });

  test('smuggling className alongside prefLang → denied', async () => {
    await assertFails(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A))
        .update({ prefLang: 'hi', className: '12' })
    );
  });

  test("another student's doc, same school → denied", async () => {
    await assertFails(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_B))
        .update({ prefLang: 'hi', updatedAt: '2026-08-08T00:00:00Z' })
    );
  });

  test('same studentId in a DIFFERENT school → denied (tenant scoping)', async () => {
    await assertFails(
      student(STU_A, SCHOOL).doc(docPath(OTHER_SCHOOL, STU_A))
        .update({ prefLang: 'hi', updatedAt: '2026-08-08T00:00:00Z' })
    );
  });

  test('unauthenticated write → denied', async () => {
    await assertFails(
      env.unauthenticatedContext().firestore()
        .doc(docPath(SCHOOL, STU_A)).update({ prefLang: 'hi' })
    );
  });
});

describe('students.prefLang — type and length guards', () => {
  test('non-string prefLang → denied', async () => {
    await assertFails(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A)).update({ prefLang: 42 })
    );
  });

  test('over-long prefLang (>8 chars) → denied', async () => {
    await assertFails(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A))
        .update({ prefLang: 'this-is-far-too-long-to-be-a-bcp47-tag' })
    );
  });

  test('exactly 8 chars → allowed (boundary is inclusive)', async () => {
    await assertSucceeds(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A)).update({ prefLang: 'sr-Latn1' })
    );
  });
});

describe('students — the mustChangePassword clause still works (no regression)', () => {
  test('clearing mustChangePassword on own doc → still allowed', async () => {
    await assertSucceeds(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A))
        .update({ mustChangePassword: false, updatedAt: '2026-08-08T00:00:00Z' })
    );
  });

  test('mustChangePassword + prefLang in ONE write → denied', async () => {
    // Both fields genuinely change, so affectedKeys() is
    // ['mustChangePassword','prefLang'] and NEITHER hasOnly() matches. This is
    // exactly why the two clauses are kept separate rather than merged.
    await assertFails(
      student(STU_A, SCHOOL).doc(docPath(SCHOOL, STU_A))
        .update({ mustChangePassword: false, prefLang: 'hi' })
    );
  });
});
