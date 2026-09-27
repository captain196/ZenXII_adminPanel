/**
 * Student Entitlements (Phase B) — Firestore Rules unit tests.
 *
 * WHY THIS EXISTS
 * ---------------
 * `studentEntitlements` and `studentFeeCredits` were added as deny-all blocks
 * with no rules tests. Deny-all is the least dangerous rule shape there is, but
 * "untested" and "safe" are different claims, and a later edit could silently
 * widen these without anything noticing. These tests pin the closed door.
 *
 * INTENT — established, not guessed:
 *   1. The rule comment states the blocks are "Deliberately DENY-ALL,
 *      byte-identical to the two predecessors above: written and read
 *      exclusively by the PHP backend through the service account, which
 *      bypasses rules."
 *   2. Deny-all is this file's established house pattern for server-only
 *      collections — feeIdempotency, feeLocks, feeCounters, feeReceiptIndex,
 *      feeDemandLocks, feePendingWrites, superAdmins, marksAudit, resultsAudit
 *      and others all use exactly this shape.
 *   3. Verified independently: NEITHER the Parent app nor the Teacher app
 *      references either collection anywhere (zero matches across both
 *      repositories). Clients see the effect via additive feeDemands fields,
 *      which already grant own-student read.
 *
 * So these blocks have no client dependency, and deploying them cannot break a
 * live client workflow — there is no client workflow to break.
 *
 * Run: cd firebase-rules/tests && npm test
 */
const {
  initializeTestEnvironment,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');

const SCHOOL = 'SCH_TEST_ENT_001';
const OTHER = 'SCH_TEST_ENT_002';

const ADMIN = 'admin-ent-1';
const TEACHER = 'STA_ENT_T1';
const STUDENT = 'STU_ENT_1';   // parent-app auth uid == student userId

let env;

const COLLECTIONS = ['studentEntitlements', 'studentFeeCredits'];
const docOf = (col, school) => `${col}/${school}_${STUDENT}_E0001`;

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
          schoolId: s, studentId: STUDENT, kind: 'concession',
          effectiveFrom: '2026-04-01', amount: 0,
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

describe.each(COLLECTIONS)('%s — server-only, closed to every client', (col) => {
  test('admin CANNOT read', async () => {
    await assertFails(asAdmin().doc(docOf(col, SCHOOL)).get());
  });

  test('staff CANNOT read', async () => {
    await assertFails(asTeacher().doc(docOf(col, SCHOOL)).get());
  });

  test('the student\'s OWN parent CANNOT read', async () => {
    // Even own-student access is closed here: clients see the effect through
    // feeDemands, never the entitlement ledger itself.
    await assertFails(asParent().doc(docOf(col, SCHOOL)).get());
  });

  test('unauthenticated client CANNOT read', async () => {
    await assertFails(asAnon().doc(docOf(col, SCHOOL)).get());
  });

  test('cross-school admin CANNOT read', async () => {
    await assertFails(asAdmin(OTHER).doc(docOf(col, SCHOOL)).get());
  });

  test('admin CANNOT create', async () => {
    await assertFails(
      asAdmin().doc(`${col}/${SCHOOL}_INJECTED`).set({ schoolId: SCHOOL, studentId: STUDENT, amount: 100000 })
    );
  });

  test('admin CANNOT update', async () => {
    // A writable entitlement ledger would be a direct route to free tuition.
    await assertFails(asAdmin().doc(docOf(col, SCHOOL)).update({ amount: 999999 }));
  });

  test('admin CANNOT delete', async () => {
    await assertFails(asAdmin().doc(docOf(col, SCHOOL)).delete());
  });

  test('parent CANNOT create an entitlement for their own child', async () => {
    await assertFails(
      asParent().doc(`${col}/${SCHOOL}_${STUDENT}_SELF`).set({
        schoolId: SCHOOL, studentId: STUDENT, kind: 'fullExempt',
      })
    );
  });
});
