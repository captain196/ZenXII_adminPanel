/**
 * salaryStructureTemplates — Firestore Rules unit tests (ZenX HRMS W2.6).
 *
 * Contract:
 *   match /salaryStructureTemplates/{docId}
 *     allow read:  isAdmin() && isSameSchool()
 *     allow write: if false          // server-only
 *
 * A template is not itself pay, but it is the thing an operator stamps onto
 * many employees at once — so a bad one propagates. The validation that
 * prevents that (catalogue-bound lines, one balancing component, at least one
 * earning, percent-vs-amount discipline) lives in Salary_template_service and
 * cannot be expressed in rules, so no client writes this collection.
 *
 * Reads are admin-only because a template reveals the organisation's entire pay
 * structure in one document.
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

const SCHOOL = 'SCH_TEST_TPL_001';
const OTHER = 'SCH_TEST_TPL_002';

const ADMIN = 'admin-tpl-1';
const OTHER_ADMIN = 'admin-tpl-2';
const TEACHER = 'STA_TPL_T1';
const STUDENT = 'STU_TPL_1';

let env;

const doc = (school, code) => `salaryStructureTemplates/${school}_${code}`;

function ctx(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
const asAdmin   = (school = SCHOOL, uid = ADMIN) => ctx(uid, school, 'admin');
const asTeacher = (school = SCHOOL) => ctx(TEACHER, school, 'Teacher');
const asParent  = (school = SCHOOL) => ctx(STUDENT, school, 'Parent');
const asAnon    = () => env.unauthenticatedContext().firestore();

const TPL = (school) => ({
  schoolId: school, code: 'TEACHING', name: 'Teaching Staff',
  payGrade: 'C', displayOrder: 10, active: true,
  lines: [
    { componentId: 'BASIC', calcType: 'FIXED', basis: 'NONE', rate: null, amountPaise: 2000000 },
    { componentId: 'SPECIAL_ALLOWANCE', calcType: 'BALANCING', basis: 'NONE', rate: null, amountPaise: null },
  ],
});

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    for (const s of [SCHOOL, OTHER]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test` });
      await db.doc(doc(s, 'TEACHING')).set(TPL(s));
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

describe('salaryStructureTemplates — read is admin-only', () => {
  test('same-school admin CAN read a structure', async () => {
    await assertSucceeds(asAdmin().doc(doc(SCHOOL, 'TEACHING')).get());
  });
  test('same-school staff CANNOT read the pay structure', async () => {
    await assertFails(asTeacher().doc(doc(SCHOOL, 'TEACHING')).get());
  });
  test('a parent CANNOT read', async () => {
    await assertFails(asParent().doc(doc(SCHOOL, 'TEACHING')).get());
  });
  test('unauthenticated client CANNOT read', async () => {
    await assertFails(asAnon().doc(doc(SCHOOL, 'TEACHING')).get());
  });
});

describe('salaryStructureTemplates — tenant isolation', () => {
  test("cross-school admin CANNOT read another school's structure", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'TEACHING')).get());
  });
  test('each tenant reads only its own structures', async () => {
    await assertSucceeds(asAdmin(OTHER, OTHER_ADMIN).doc(doc(OTHER, 'TEACHING')).get());
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'TEACHING')).get());
  });
  test("cross-school admin CANNOT write into another school's structures", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'ADMIN_STAFF')).set(TPL(SCHOOL)));
  });
  test("cross-school admin CANNOT delete another school's structure", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'TEACHING')).delete());
  });
});

describe('salaryStructureTemplates — writes are server-only', () => {
  test('same-school admin CANNOT create a structure', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, 'ADMIN_STAFF')).set({ ...TPL(SCHOOL), code: 'ADMIN_STAFF' }));
  });

  test('same-school admin CANNOT rewrite a structure line', async () => {
    // A structure is stamped onto many employees; a bad edit propagates.
    await assertFails(asAdmin().doc(doc(SCHOOL, 'TEACHING')).update({
      lines: [{ componentId: 'BASIC', calcType: 'FIXED', amountPaise: 99999999 }],
    }));
  });

  test('same-school admin CANNOT store a contradictory line', async () => {
    // The service rejects a percentage carrying an amount; a direct write would not.
    await assertFails(asAdmin().doc(doc(SCHOOL, 'BAD')).set({
      ...TPL(SCHOOL), code: 'BAD',
      lines: [{ componentId: 'PF_EMPLOYEE', calcType: 'PERCENT', rate: 12, amountPaise: 5000 }],
    }));
  });

  test('same-school admin CANNOT delete a structure', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, 'TEACHING')).delete());
  });

  test('staff CANNOT write', async () => {
    await assertFails(asTeacher().doc(doc(SCHOOL, 'TEACHING')).update({ name: 'Edited' }));
  });

  test('a parent CANNOT write', async () => {
    await assertFails(asParent().doc(doc(SCHOOL, 'P')).set(TPL(SCHOOL)));
  });

  test('unauthenticated client CANNOT write', async () => {
    await assertFails(asAnon().doc(doc(SCHOOL, 'A')).set(TPL(SCHOOL)));
  });

  test('forged schoolId in the payload is still denied', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, 'SPOOF')).set({ ...TPL(SCHOOL), schoolId: OTHER }));
  });

  test('missing schoolId in the payload is still denied', async () => {
    const { schoolId, ...noTenant } = TPL(SCHOOL);
    await assertFails(asAdmin().doc(doc(SCHOOL, 'NOSCHOOL')).set(noTenant));
  });
});
