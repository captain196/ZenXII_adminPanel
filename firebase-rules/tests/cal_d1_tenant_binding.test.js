/**
 * CAL-D1 — TENANT BINDING ON THE CALENDAR-ADJACENT COLLECTIONS.
 *
 * This suite exists to answer one question with execution rather than reading:
 *
 *   Can an authenticated principal of School A read, create, overwrite or
 *   delete a document that belongs to School B?
 *
 * It is deliberately DIFFERENTIAL. Point it at any ruleset via CAL_D1_RULES and
 * it reports what that ruleset actually permits, so the same file proves the
 * defect against the current candidate and proves the fix against a patched one.
 *
 *   CAL_D1_RULES=../firestore.rules            npx jest cal_d1_tenant_binding
 *   CAL_D1_RULES=/tmp/patched.rules            npx jest cal_d1_tenant_binding
 *
 * TWO DISTINCT DEFECT SHAPES ARE TESTED, because they fail for different reasons:
 *
 *   WRITE — `allow write: if isAdmin();`
 *     isAdmin() confirms the CALLER is an admin whose OWN tenant is active. It
 *     never constrains the document being written, so an admin of A can author
 *     a document carrying schoolId B.
 *
 *   READ  — `allow read: if isStaff() || (isSameSchool() && …);`
 *     isStaff() likewise only checks the caller's own tenant is active. Because
 *     it sits on the LEFT of the disjunction it short-circuits the same-school
 *     test entirely, so any staff member of any school reads any school's exams.
 *     Writing it as `isSameSchool() && (isStaff() || …)` binds the tenant first.
 *
 * Nothing here touches production. Emulator only, and the project id is a
 * throwaway.
 */

const fs = require('fs');
const path = require('path');
const {
  initializeTestEnvironment,
  assertFails,
  assertSucceeds,
} = require('@firebase/rules-unit-testing');

const PROJECT_ID = 'zenxii-rules-test';
const SCHOOL_A = 'SCH_TENANT_AAA';
const SCHOOL_B = 'SCH_TENANT_BBB';

const RULES_PATH = process.env.CAL_D1_RULES
  ? path.resolve(__dirname, process.env.CAL_D1_RULES)
  : path.resolve(__dirname, '../firestore.rules');

jest.setTimeout(60000);

let env;
const DB = {};

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: {
      rules: fs.readFileSync(RULES_PATH, 'utf8'),
      host: '127.0.0.1',
      port: 18080,
    },
  });

  // An ADMIN of school A. Both claim spellings are emitted, as production does.
  DB.adminA = env
    .authenticatedContext('uid_admin_a', {
      school_id: SCHOOL_A,
      schoolId: SCHOOL_A,
      role: 'Admin',
      staffCapabilities: { admin: true },
    })
    .firestore();

  // A TEACHER of school A — staff, but not an admin.
  DB.teacherA = env
    .authenticatedContext('uid_teacher_a', {
      school_id: SCHOOL_A,
      schoolId: SCHOOL_A,
      role: 'Teacher',
    })
    .firestore();

  DB.anon = env.unauthenticatedContext().firestore();
});

afterAll(async () => { if (env) await env.cleanup(); });

async function seed(collection, id, data) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    await ctx.firestore().collection(collection).doc(id).set(data);
  });
}

// Tenants must be active, or tenantActive() denies for the wrong reason and
// every assertion below would pass vacuously.
beforeAll(async () => {
  // tenantActive() reads schoolControl/{id}.lifecycle.state AND schools/{id}.
  // Seeding only `schools` left tenantActive() false for BOTH tenants, so every
  // assertFails() passed for the wrong reason — a vacuous green. The
  // same-tenant counter-tests are what exposed it.
  for (const s of [SCHOOL_A, SCHOOL_B]) {
    await seed('schoolControl', s, { lifecycle: { state: 'active' } });
    await seed('schools', s, { schoolId: s });
  }
});

describe('CAL-D1 · cross-tenant WRITE on server-owned collections', () => {
  const COLLECTIONS = [
    'calendarEvents',
    'subjects',
    'exams',
    'examSchedule',
    'appraisals',
  ];

  COLLECTIONS.forEach((col) => {
    describe(col, () => {
      test(`an admin of A cannot CREATE a ${col} document owned by B`, async () => {
        await assertFails(
          DB.adminA.collection(col).doc(`${SCHOOL_B}_x1`).set({
            schoolId: SCHOOL_B,
            title: 'injected by school A',
          })
        );
      });

      test(`an admin of A cannot OVERWRITE B's ${col} document`, async () => {
        const id = `${SCHOOL_B}_x2`;
        await seed(col, id, { schoolId: SCHOOL_B, title: 'B owns this' });
        await assertFails(
          DB.adminA.collection(col).doc(id).set({
            schoolId: SCHOOL_B,
            title: 'overwritten by school A',
          })
        );
      });

      test(`an admin of A cannot DELETE B's ${col} document`, async () => {
        const id = `${SCHOOL_B}_x3`;
        await seed(col, id, { schoolId: SCHOOL_B, title: 'B owns this' });
        await assertFails(DB.adminA.collection(col).doc(id).delete());
      });

      test(`an unauthenticated client cannot write ${col}`, async () => {
        await assertFails(
          DB.anon.collection(col).doc(`${SCHOOL_A}_x4`).set({ schoolId: SCHOOL_A })
        );
      });
    });
  });
});

describe('CAL-D1b · cross-tenant READ on exam data', () => {
  test("a teacher of A cannot read B's exams", async () => {
    const id = `${SCHOOL_B}_e1`;
    await seed('exams', id, {
      schoolId: SCHOOL_B,
      status: 'Published',
      name: "B's exam",
    });
    await assertFails(DB.teacherA.collection('exams').doc(id).get());
  });

  test("a teacher of A cannot read B's exam schedule", async () => {
    const id = `${SCHOOL_B}_es1`;
    await seed('examSchedule', id, {
      schoolId: SCHOOL_B,
      examId: 'EX_B',
      subject: 'Maths',
    });
    await assertFails(DB.teacherA.collection('examSchedule').doc(id).get());
  });
});

/**
 * The counter-test. A tenant-binding fix is only correct if it leaves the
 * legitimate same-school paths working — otherwise "secure" just means broken.
 */
describe('same-tenant access must keep working', () => {
  test('a teacher of A can read their own calendar events', async () => {
    const id = `${SCHOOL_A}_ok1`;
    await seed('calendarEvents', id, { schoolId: SCHOOL_A, title: 'Diwali' });
    await assertSucceeds(DB.teacherA.collection('calendarEvents').doc(id).get());
  });

  test('a teacher of A can read their own published exams', async () => {
    const id = `${SCHOOL_A}_ok2`;
    await seed('exams', id, { schoolId: SCHOOL_A, status: 'Published', name: 'Term 1' });
    await assertSucceeds(DB.teacherA.collection('exams').doc(id).get());
  });

  test('a teacher of A can read their own exam schedule', async () => {
    const id = `${SCHOOL_A}_ok3`;
    await seed('examSchedule', id, { schoolId: SCHOOL_A, examId: 'EX_A', subject: 'Maths' });
    await assertSucceeds(DB.teacherA.collection('examSchedule').doc(id).get());
  });

  test('a teacher of A can read their own subjects', async () => {
    const id = `${SCHOOL_A}_ok4`;
    await seed('subjects', id, { schoolId: SCHOOL_A, name: 'Maths' });
    await assertSucceeds(DB.teacherA.collection('subjects').doc(id).get());
  });
});
