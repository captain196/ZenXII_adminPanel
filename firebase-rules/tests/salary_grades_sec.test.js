/**
 * salaryGrades — Firestore Rules unit tests (ZenX HRMS W2.5).
 *
 * Contract:
 *   match /salaryGrades/{docId}
 *     allow read:  isAdmin() && isSameSchool()
 *     allow write: if false          // server-only
 *
 * A grade is not per-employee pay, but it IS the control that decides whether a
 * salary is flagged out-of-band. A client able to widen a band could silence
 * that warning for everyone at once — which is precisely the check's purpose —
 * so the write arm is closed like the rest of the compensation layer.
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

const SCHOOL = 'SCH_TEST_GRADE_001';
const OTHER = 'SCH_TEST_GRADE_002';

const ADMIN = 'admin-grade-1';
const OTHER_ADMIN = 'admin-grade-2';
const TEACHER = 'STA_GRADE_T1';
const STUDENT = 'STU_GRADE_1';

let env;

const doc = (school, code) => `salaryGrades/${school}_${code}`;

function ctx(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
const asAdmin   = (school = SCHOOL, uid = ADMIN) => ctx(uid, school, 'admin');
const asTeacher = (school = SCHOOL) => ctx(TEACHER, school, 'Teacher');
const asParent  = (school = SCHOOL) => ctx(STUDENT, school, 'Parent');
const asAnon    = () => env.unauthenticatedContext().firestore();

const GRADE = (school) => ({
  schoolId: school, code: 'C', name: 'Grade C',
  minPaise: 2500000, maxPaise: 4000000,
  displayOrder: 30, active: true,
});

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    for (const s of [SCHOOL, OTHER]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test` });
      await db.doc(doc(s, 'C')).set(GRADE(s));
    }
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

describe('salaryGrades — read is admin-only', () => {
  test('same-school admin CAN read a grade', async () => {
    await assertSucceeds(asAdmin().doc(doc(SCHOOL, 'C')).get());
  });
  test('same-school staff CANNOT read', async () => {
    await assertFails(asTeacher().doc(doc(SCHOOL, 'C')).get());
  });
  test('a parent CANNOT read', async () => {
    await assertFails(asParent().doc(doc(SCHOOL, 'C')).get());
  });
  test('unauthenticated client CANNOT read', async () => {
    await assertFails(asAnon().doc(doc(SCHOOL, 'C')).get());
  });
});

describe('salaryGrades — tenant isolation', () => {
  test("cross-school admin CANNOT read another school's bands", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'C')).get());
  });
  test('each tenant reads only its own grades', async () => {
    await assertSucceeds(asAdmin(OTHER, OTHER_ADMIN).doc(doc(OTHER, 'C')).get());
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'C')).get());
  });
  test("cross-school admin CANNOT write into another school's grades", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'D')).set(GRADE(SCHOOL)));
  });
  test("cross-school admin CANNOT delete another school's grade", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'C')).delete());
  });
});

describe('salaryGrades — writes are server-only', () => {
  test('same-school admin CANNOT create a grade', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, 'D')).set({ ...GRADE(SCHOOL), code: 'D' }));
  });

  test('same-school admin CANNOT widen a band to silence out-of-band warnings', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, 'C')).update({ maxPaise: 99999999 }));
  });

  test('same-school admin CANNOT store an inverted band', async () => {
    // The service rejects ceiling < floor; a direct write would sneak past it.
    await assertFails(asAdmin().doc(doc(SCHOOL, 'C')).update({ minPaise: 9000000, maxPaise: 100 }));
  });

  test('same-school admin CANNOT delete a grade', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, 'C')).delete());
  });

  test('staff CANNOT write', async () => {
    await assertFails(asTeacher().doc(doc(SCHOOL, 'C')).update({ maxPaise: 99999999 }));
  });

  test('a parent CANNOT write', async () => {
    await assertFails(asParent().doc(doc(SCHOOL, 'E')).set(GRADE(SCHOOL)));
  });

  test('unauthenticated client CANNOT write', async () => {
    await assertFails(asAnon().doc(doc(SCHOOL, 'F')).set(GRADE(SCHOOL)));
  });

  test('forged schoolId in the payload is still denied', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, 'G')).set({ ...GRADE(SCHOOL), schoolId: OTHER }));
  });

  test('missing schoolId in the payload is still denied', async () => {
    const { schoolId, ...noTenant } = GRADE(SCHOOL);
    await assertFails(asAdmin().doc(doc(SCHOOL, 'H')).set(noTenant));
  });
});
