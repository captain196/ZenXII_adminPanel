/**
 * F12.1 CBSE Transport Safety Compliance — Firestore Rules unit tests.
 *
 * WHY THIS EXISTS
 * ---------------
 * Three collections were added with no rules tests. Their documented model is
 * deliberately simple and is asserted here exactly as written:
 *
 *   "All three collections are server-only writes (Transport_compliance_service
 *    via the PHP service account); staff-read for the compliance dashboard.
 *    Authorization grading (view/edit/manage) is a panel-layer concern ... the
 *    rules layer intentionally stays a simple staff-read / server-write
 *    boundary and does not mirror RBAC levels."
 *
 * That last sentence matters for interpreting these tests: a staff member with
 * NO transport RBAC grant can still read these documents through a direct
 * client SDK call. That is the stated design, not an oversight, so it is
 * asserted as designed and flagged in the readiness report rather than
 * "fixed" here. No business permission is invented, and no production rule was
 * changed to make a test pass.
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

const SCHOOL = 'SCH_TEST_TCOMP_001';
const OTHER = 'SCH_TEST_TCOMP_002';

const ADMIN = 'admin-tcomp-1';
const TEACHER = 'STA_TCOMP_T1';
const STUDENT = 'STU_TCOMP_1';

let env;

const COLLECTIONS = ['transportComplianceRules', 'vehicleCompliance', 'vehicleCompliancePosture'];
const docOf = (col, school) => `${col}/${school}_REC0001`;

function ctx(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
const asAdmin   = (school = SCHOOL) => ctx(ADMIN, school, 'admin');
const asTeacher = (school = SCHOOL) => ctx(TEACHER, school, 'Teacher');
const asParent  = (school = SCHOOL) => ctx(STUDENT, school, 'Parent');
const asAnon    = () => env.unauthenticatedContext().firestore();

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    for (const s of [SCHOOL, OTHER]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test` });
      for (const col of COLLECTIONS) {
        await db.doc(docOf(col, s)).set({
          schoolId: s, vehicleId: 'VEH0001', status: 'compliant',
          evaluatedAt: new Date().toISOString(),
        });
      }
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

describe.each(COLLECTIONS)('%s — staff-read / server-write boundary', (col) => {
  test('same-school staff CAN read', async () => {
    await assertSucceeds(asTeacher().doc(docOf(col, SCHOOL)).get());
  });

  test('same-school admin CAN read', async () => {
    await assertSucceeds(asAdmin().doc(docOf(col, SCHOOL)).get());
  });

  test('a parent CANNOT read compliance data', async () => {
    await assertFails(asParent().doc(docOf(col, SCHOOL)).get());
  });

  test('unauthenticated client CANNOT read', async () => {
    await assertFails(asAnon().doc(docOf(col, SCHOOL)).get());
  });

  test("cross-school staff CANNOT read another school's compliance data", async () => {
    await assertFails(asTeacher(OTHER).doc(docOf(col, SCHOOL)).get());
  });

  test('NO client may create — server-only', async () => {
    await assertFails(
      asAdmin().doc(`${col}/${SCHOOL}_INJECTED`).set({ schoolId: SCHOOL, status: 'compliant' })
    );
  });

  test('NO client may update — not even an admin', async () => {
    await assertFails(asAdmin().doc(docOf(col, SCHOOL)).update({ status: 'forged' }));
  });

  test('NO client may delete', async () => {
    await assertFails(asAdmin().doc(docOf(col, SCHOOL)).delete());
  });

  test('cross-school admin CANNOT write into our tenant', async () => {
    await assertFails(
      asAdmin(OTHER).doc(docOf(col, SCHOOL)).update({ status: 'forged' })
    );
  });
});
