/**
 * staff.reportingManagerId — Firestore Rules unit tests (Phase 2C).
 *
 * Tests the reportingManagerOk() defense-in-depth added to the EXISTING
 * `staff` create/update rule (no new collection — Reporting Manager is
 * just another field on the same document Position/Department/
 * EmploymentType already live on). Full cycle detection is PHP-only per
 * the Phase 2B design (rules cannot do an unbounded/recursive traversal);
 * these tests cover only what a single bounded read can safely check:
 * self-reference and same-school manager existence.
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

const SCHOOL = 'SCH_TEST_RM_001';
const OTHER_SCHOOL = 'SCH_TEST_RM_002';
const ADMIN = 'admin-rm-1';

let env;

const staffDocOf = (school, staffId) => `staff/${school}_${staffId}`;

function asAdmin(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'admin', user_id: uid }).firestore();
}

function baseStaff(overrides = {}) {
  return {
    schoolId: SCHOOL, staffId: 'STA_RM_NEW', status: 'Active', name: 'New Staff',
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

    await db.doc(staffDocOf(SCHOOL, 'STA_RM_MGR')).set({
      schoolId: SCHOOL, staffId: 'STA_RM_MGR', status: 'Active', name: 'Manager In School',
    });
    await db.doc(staffDocOf(OTHER_SCHOOL, 'STA_RM_MGR2')).set({
      schoolId: OTHER_SCHOOL, staffId: 'STA_RM_MGR2', status: 'Active', name: 'Manager In Other School',
    });
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

describe('staff.reportingManagerId — create/update defense-in-depth', () => {
  test('no reportingManagerId field at all still succeeds (field is optional)', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(staffDocOf(SCHOOL, 'STA_RM_A')).set(
        baseStaff({ staffId: 'STA_RM_A' })
      )
    );
  });

  test('empty-string reportingManagerId succeeds (explicit "no manager")', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(staffDocOf(SCHOOL, 'STA_RM_B')).set(
        baseStaff({ staffId: 'STA_RM_B', reportingManagerId: '' })
      )
    );
  });

  test('reportingManagerId pointing at an existing SAME-SCHOOL staff member succeeds', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(staffDocOf(SCHOOL, 'STA_RM_C')).set(
        baseStaff({ staffId: 'STA_RM_C', reportingManagerId: 'STA_RM_MGR' })
      )
    );
  });

  test('reportingManagerId pointing at a NONEXISTENT staff member is rejected', async () => {
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(staffDocOf(SCHOOL, 'STA_RM_D')).set(
        baseStaff({ staffId: 'STA_RM_D', reportingManagerId: 'STA_RM_GHOST' })
      )
    );
  });

  test('reportingManagerId equal to the document\'s own staffId (self-reference) is rejected', async () => {
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(staffDocOf(SCHOOL, 'STA_RM_E')).set(
        baseStaff({ staffId: 'STA_RM_E', reportingManagerId: 'STA_RM_E' })
      )
    );
  });

  test('reportingManagerId referencing a staffId that only exists in ANOTHER school is rejected (cross-school)', async () => {
    // STA_RM_MGR2 exists under OTHER_SCHOOL's prefix, not SCHOOL's — the
    // exists() check resolves schoolId + '_' + rm using THIS doc's own
    // schoolId (SCHOOL), so it can never find the other school's record.
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(staffDocOf(SCHOOL, 'STA_RM_F')).set(
        baseStaff({ staffId: 'STA_RM_F', reportingManagerId: 'STA_RM_MGR2' })
      )
    );
  });

  test('update (not just create) is also gated by reportingManagerOk()', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc(staffDocOf(SCHOOL, 'STA_RM_G')).set(baseStaff({ staffId: 'STA_RM_G' }));
    });
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(staffDocOf(SCHOOL, 'STA_RM_G')).update({ reportingManagerId: 'STA_RM_G' })
    );
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(staffDocOf(SCHOOL, 'STA_RM_G')).update({ reportingManagerId: 'STA_RM_MGR' })
    );
  });
});
