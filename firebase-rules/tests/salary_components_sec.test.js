/**
 * salaryComponents — Firestore Rules unit tests (ZenX HRMS W2.1).
 *
 * Contract:
 *   match /salaryComponents/{docId}
 *     allow read:  isAdmin() && isSameSchool()
 *     allow write: if false            // server-only, see the rule's comment
 *
 * The closed write arm is the point, not an oversight. Component metadata is
 * only meaningful if it passed Salary_component_service's validation (the
 * percent-vs-money split, the enum set, the systemLocked guard on the thirteen
 * components that describe live payroll). A direct client-SDK write would
 * bypass every one of those, so no client may write at all — the admin panel
 * reaches this collection through PHP on the service account, which bypasses
 * rules entirely.
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

const SCHOOL = 'SCH_TEST_COMP_001';
const OTHER = 'SCH_TEST_COMP_002';

const ADMIN = 'admin-comp-1';
const OTHER_ADMIN = 'admin-comp-2';
const TEACHER = 'STA_COMP_T1';
const STUDENT = 'STU_COMP_1';       // parent-app auth uid

let env;

const doc = (school, code) => `salaryComponents/${school}_${code}`;

function ctx(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
const asAdmin   = (school = SCHOOL, uid = ADMIN) => ctx(uid, school, 'admin');
const asTeacher = (school = SCHOOL) => ctx(TEACHER, school, 'Teacher');
const asParent  = (school = SCHOOL) => ctx(STUDENT, school, 'Parent');
const asAnon    = () => env.unauthenticatedContext().firestore();

const BASIC = {
  code: 'BASIC', name: 'Basic', kind: 'earning',
  calcType: 'FIXED', basis: 'NONE', basisComponents: [],
  rate: null, amountPaise: 0,
  taxable: true, pfApplicable: true, esiApplicable: true,
  prorated: true, statutory: null,
  displayOrder: 10, active: true, systemLocked: true,
};

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    for (const s of [SCHOOL, OTHER]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test` });
      await db.doc(doc(s, 'BASIC')).set({ ...BASIC, schoolId: s });
      await db.doc(doc(s, 'PF_EMPLOYEE')).set({
        schoolId: s, code: 'PF_EMPLOYEE', name: 'Provident Fund (Employee)',
        kind: 'deduction', calcType: 'PERCENT', basis: 'BASIC', basisComponents: [],
        rate: 0, amountPaise: null, statutory: 'PF',
        taxable: false, pfApplicable: false, esiApplicable: false, prorated: true,
        displayOrder: 110, active: true, systemLocked: true,
      });
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

describe('salaryComponents — read is admin-only, same-school', () => {
  test('same-school admin CAN read a component', async () => {
    await assertSucceeds(asAdmin().doc(doc(SCHOOL, 'BASIC')).get());
  });

  test('same-school non-admin staff CANNOT read', async () => {
    // Component metadata is compensation policy, not staff-visible reference data.
    await assertFails(asTeacher().doc(doc(SCHOOL, 'BASIC')).get());
  });

  test('a parent CANNOT read', async () => {
    await assertFails(asParent().doc(doc(SCHOOL, 'BASIC')).get());
  });

  test('unauthenticated client CANNOT read', async () => {
    await assertFails(asAnon().doc(doc(SCHOOL, 'BASIC')).get());
  });
});

describe('salaryComponents — tenant isolation', () => {
  test("cross-school admin CANNOT read another school's component", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'BASIC')).get());
  });

  test("cross-school admin CANNOT write into another school's catalogue", async () => {
    await assertFails(
      asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'INJECTED')).set({ ...BASIC, schoolId: SCHOOL, code: 'INJECTED' })
    );
  });

  test("cross-school admin CANNOT delete another school's component", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'BASIC')).delete());
  });

  test('each tenant reads only its own catalogue', async () => {
    await assertSucceeds(asAdmin(OTHER, OTHER_ADMIN).doc(doc(OTHER, 'BASIC')).get());
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, 'PF_EMPLOYEE')).get());
  });
});

describe('salaryComponents — writes are server-only (validation cannot be bypassed)', () => {
  test('same-school ADMIN CANNOT create a component directly', async () => {
    await assertFails(
      asAdmin().doc(doc(SCHOOL, 'NEW_ALLOWANCE')).set({
        ...BASIC, schoolId: SCHOOL, code: 'NEW_ALLOWANCE', systemLocked: false,
      })
    );
  });

  test('same-school ADMIN CANNOT update a component directly', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, 'BASIC')).update({ name: 'Renamed' }));
  });

  test('same-school ADMIN CANNOT delete a component', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, 'BASIC')).delete());
  });

  test('an admin CANNOT unlock a system component from the client', async () => {
    // The whole reason the write arm is closed: systemLocked is what stops the
    // thirteen seeded components from being rewritten to misdescribe payroll.
    await assertFails(asAdmin().doc(doc(SCHOOL, 'BASIC')).update({ systemLocked: false }));
  });

  test('an admin CANNOT store a contradictory PERCENT+amountPaise component', async () => {
    // Exactly the corruption Salary_component_service::validate() rejects, and
    // which a direct client write would otherwise sneak past.
    await assertFails(
      asAdmin().doc(doc(SCHOOL, 'BAD_MIX')).set({
        schoolId: SCHOOL, code: 'BAD_MIX', name: 'Bad', kind: 'deduction',
        calcType: 'PERCENT', rate: 12, amountPaise: 50000, basis: 'BASIC',
      })
    );
  });

  test('staff CANNOT write', async () => {
    await assertFails(asTeacher().doc(doc(SCHOOL, 'BASIC')).update({ name: 'Staff edit' }));
  });

  test('a parent CANNOT write', async () => {
    await assertFails(asParent().doc(doc(SCHOOL, 'PARENT')).set({ schoolId: SCHOOL, code: 'PARENT' }));
  });

  test('unauthenticated client CANNOT write', async () => {
    await assertFails(asAnon().doc(doc(SCHOOL, 'ANON')).set({ schoolId: SCHOOL, code: 'ANON' }));
  });

  test('forged schoolId in the payload is still denied', async () => {
    await assertFails(
      asAdmin().doc(doc(SCHOOL, 'SPOOF')).set({ ...BASIC, schoolId: OTHER, code: 'SPOOF' })
    );
  });

  test('missing schoolId in the payload is still denied', async () => {
    await assertFails(
      asAdmin().doc(doc(SCHOOL, 'NOSCHOOL')).set({ code: 'NOSCHOOL', name: 'No tenant', kind: 'earning' })
    );
  });
});
