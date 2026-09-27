/**
 * staffSalaryAssignments — Firestore Rules unit tests (ZenX HRMS W2.3).
 *
 * Contract:
 *   match /staffSalaryAssignments/{docId}
 *     allow read:  isAdmin() && isSameSchool()
 *     allow write: if false          // server-only
 *
 * One document is one person's salary, and the documents are effective-dated —
 * so a client write could not only change present pay but forge a window that
 * rewrites what a CLOSED payroll period resolves to. That is what the closed
 * write arm prevents.
 *
 * The own-record read a staff member will eventually need is deliberately NOT
 * here; it belongs with ESS (W5), designed alongside the payslip surface.
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

const SCHOOL = 'SCH_TEST_ASSIGN_001';
const OTHER = 'SCH_TEST_ASSIGN_002';

const ADMIN = 'admin-assign-1';
const OTHER_ADMIN = 'admin-assign-2';
const STAFF_SELF = 'STA0001';       // the employee the assignment belongs to
const STAFF_OTHER = 'STA0002';
const STUDENT = 'STU_ASSIGN_1';

let env;

const doc = (school, staffId, from) => `staffSalaryAssignments/${school}_${staffId}_${from}`;

function ctx(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
const asAdmin = (school = SCHOOL, uid = ADMIN) => ctx(uid, school, 'admin');
const asStaff = (uid, school = SCHOOL) => ctx(uid, school, 'Teacher');
const asParent = (school = SCHOOL) => ctx(STUDENT, school, 'Parent');
const asAnon = () => env.unauthenticatedContext().firestore();

const ASSIGN = (school, staffId) => ({
  schoolId: school, staffId,
  effectiveFrom: '2026-04-01', effectiveTo: null,
  payBasis: 'MONTHLY_FIXED', status: 'active',
  lines: [{ componentId: 'BASIC', calcType: 'FIXED', basis: 'NONE', rate: null, amountPaise: 3000000 }],
  grossPaise: 3000000,
});

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    for (const s of [SCHOOL, OTHER]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test` });
      await db.doc(doc(s, STAFF_SELF, '2026-04-01')).set(ASSIGN(s, STAFF_SELF));
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

describe('staffSalaryAssignments — read is admin-only', () => {
  test('same-school admin CAN read an assignment', async () => {
    await assertSucceeds(asAdmin().doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).get());
  });

  test('a staff member CANNOT read their OWN salary assignment yet', async () => {
    // Not an oversight — ESS own-record access is W5, designed with the payslip
    // surface. Pinned so nobody assumes it already works.
    await assertFails(asStaff(STAFF_SELF).doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).get());
  });

  test("a staff member CANNOT read a colleague's salary", async () => {
    await assertFails(asStaff(STAFF_OTHER).doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).get());
  });

  test('a parent CANNOT read any salary assignment', async () => {
    await assertFails(asParent().doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).get());
  });

  test('unauthenticated client CANNOT read', async () => {
    await assertFails(asAnon().doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).get());
  });
});

describe('staffSalaryAssignments — tenant isolation', () => {
  test("cross-school admin CANNOT read another school's salary data", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).get());
  });

  test('each tenant reads only its own assignments', async () => {
    await assertSucceeds(asAdmin(OTHER, OTHER_ADMIN).doc(doc(OTHER, STAFF_SELF, '2026-04-01')).get());
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).get());
  });

  test("cross-school admin CANNOT write into another school's assignments", async () => {
    await assertFails(
      asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, STAFF_SELF, '2027-04-01')).set(ASSIGN(SCHOOL, STAFF_SELF))
    );
  });

  test("cross-school admin CANNOT delete another school's assignment", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).delete());
  });
});

describe('staffSalaryAssignments — writes are server-only', () => {
  test('same-school admin CANNOT create an assignment', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, STAFF_OTHER, '2026-04-01')).set(ASSIGN(SCHOOL, STAFF_OTHER)));
  });

  test('same-school admin CANNOT raise a salary directly', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).update({ grossPaise: 99999999 }));
  });

  test('a staff member CANNOT raise their own salary', async () => {
    await assertFails(asStaff(STAFF_SELF).doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).update({ grossPaise: 99999999 }));
  });

  test('CANNOT forge a back-dated window over a closed period', async () => {
    // The attack effective dating exists to prevent: rewriting what a settled
    // month resolves to.
    await assertFails(
      asAdmin().doc(doc(SCHOOL, STAFF_SELF, '2020-04-01')).set({
        ...ASSIGN(SCHOOL, STAFF_SELF), effectiveFrom: '2020-04-01',
      })
    );
  });

  test('CANNOT close an existing window to make room for another', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).update({ effectiveTo: '2026-04-30' }));
  });

  test('same-school admin CANNOT delete an assignment', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, STAFF_SELF, '2026-04-01')).delete());
  });

  test('a parent CANNOT write', async () => {
    await assertFails(asParent().doc(doc(SCHOOL, STAFF_OTHER, '2028-04-01')).set(ASSIGN(SCHOOL, STAFF_OTHER)));
  });

  test('unauthenticated client CANNOT write', async () => {
    await assertFails(asAnon().doc(doc(SCHOOL, STAFF_OTHER, '2029-04-01')).set(ASSIGN(SCHOOL, STAFF_OTHER)));
  });

  test('forged schoolId in the payload is still denied', async () => {
    await assertFails(
      asAdmin().doc(doc(SCHOOL, STAFF_OTHER, '2030-04-01')).set({ ...ASSIGN(SCHOOL, STAFF_OTHER), schoolId: OTHER })
    );
  });

  test('missing schoolId in the payload is still denied', async () => {
    const { schoolId, ...noTenant } = ASSIGN(SCHOOL, STAFF_OTHER);
    await assertFails(asAdmin().doc(doc(SCHOOL, STAFF_OTHER, '2031-04-01')).set(noTenant));
  });
});
