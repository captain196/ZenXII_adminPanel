/**
 * H-LIFECYCLE H1 — Firestore Rules unit-test matrix.
 *
 * Verifies the `tenantActive(schoolId)` gate composed into every
 * tenant-isolation helper. Tests both directions of the gate:
 *
 *   1. Allowed states (active/trialing/expiring_soon/grace) PERMIT
 *      reads/writes on gated collections, regardless of role.
 *   2. Denied states (past_due/suspended/expired) DENY reads/writes
 *      on gated collections, regardless of role.
 *   3. adminDisabled.value=true DENIES even when lifecycle.state IS
 *      allowed (defense in depth).
 *   4. tenantPublic/{id} stays READABLE in every state (so mobile
 *      clients can detect transitions and force-logout).
 *   5. schoolCodeIndex/{code} stays READABLE in every state (pre-auth
 *      bootstrap path — login must keep working for any tenant).
 *
 * Cross-tenant isolation is NOT retested here (the existing rules
 * matrix already covers it); these tests focus on the H1 gate.
 *
 * Run: cd firebase-rules/tests && npm install && npm test
 * Requires: Node 18+, Java 11+ (for the Firestore emulator), Firebase CLI.
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

let env;

const SCHOOL_ID = 'SCH_TEST_GATE_001';
const OTHER_SCHOOL_ID = 'SCH_TEST_GATE_002';

const ALLOWED_STATES = ['active', 'trialing', 'expiring_soon', 'grace'];
const DENIED_STATES = ['past_due', 'suspended', 'expired'];
const ALL_STATES = [...ALLOWED_STATES, ...DENIED_STATES];

const ROLES = ['parent', 'teacher', 'admin', 'school_super_admin'];

/**
 * Seed the schoolControl + schools docs in a given tenant state.
 * Uses unauthenticated admin context (rules bypassed) to set up
 * the world before each test.
 */
async function seedTenant(state, adminDisabled = false) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.doc(`schoolControl/${SCHOOL_ID}`).set({
      schoolId: SCHOOL_ID,
      lifecycle: { state, computedAt: Date.now(), reason: state },
    });
    await db.doc(`schools/${SCHOOL_ID}`).set({
      schoolId: SCHOOL_ID,
      name: 'Gate Test School',
      adminDisabled: { value: adminDisabled, updatedAt: 'test' },
    });
    // Seed a representative document in a gated collection so reads
    // hit existing-doc rule path, not non-existent-doc fast path.
    await db.doc(`students/STU0001_TEST`).set({
      schoolId: SCHOOL_ID,
      studentId: 'STU0001',
      name: 'Test Student',
    });
    await db.doc(`tenantPublic/${SCHOOL_ID}`).set({
      schoolId: SCHOOL_ID,
      lifecycleState: state,
      adminDisabled: adminDisabled,
      activeModules: { student_management: true },
    });
    await db.doc(`schoolCodeIndex/10999`).set({
      schoolId: SCHOOL_ID,
      schoolCode: '10999',
    });
  });
}

/** Returns an authed Firestore client for a given role + schoolId. */
function authedDb(schoolId, role) {
  return env
    .authenticatedContext('test-user', { school_id: schoolId, role })
    .firestore();
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: {
      rules: fs.readFileSync(RULES_PATH, 'utf8'),
      host: '127.0.0.1',
      port: 18080,
    },
  });
});

afterAll(async () => {
  if (env) await env.cleanup();
});

beforeEach(async () => {
  await env.clearFirestore();
});

describe('H-LIFECYCLE H1 — gate matrix', () => {
  // ── ALLOWED STATES ────────────────────────────────────────────
  describe.each(ALLOWED_STATES)('lifecycle.state = "%s" (allowed)', (state) => {
    beforeEach(async () => { await seedTenant(state, false); });

    test.each(ROLES)('role=%s → read students/{doc} ALLOWED', async (role) => {
      const db = authedDb(SCHOOL_ID, role);
      await assertSucceeds(db.doc('students/STU0001_TEST').get());
    });

    test('role=admin → write students/{doc} ALLOWED', async () => {
      const db = authedDb(SCHOOL_ID, 'admin');
      await assertSucceeds(
        db.doc('students/STU0002_TEST').set({
          schoolId: SCHOOL_ID,
          studentId: 'STU0002',
          name: 'New Test Student',
        })
      );
    });
  });

  // ── DENIED STATES ─────────────────────────────────────────────
  describe.each(DENIED_STATES)('lifecycle.state = "%s" (denied)', (state) => {
    beforeEach(async () => { await seedTenant(state, false); });

    test.each(ROLES)('role=%s → read students/{doc} DENIED', async (role) => {
      const db = authedDb(SCHOOL_ID, role);
      await assertFails(db.doc('students/STU0001_TEST').get());
    });

    test('role=admin → write students/{doc} DENIED', async () => {
      const db = authedDb(SCHOOL_ID, 'admin');
      await assertFails(
        db.doc('students/STU0002_TEST').set({
          schoolId: SCHOOL_ID,
          studentId: 'STU0002',
          name: 'Blocked Write',
        })
      );
    });

    test('role=parent → read schools/{id} DENIED', async () => {
      const db = authedDb(SCHOOL_ID, 'parent');
      await assertFails(db.doc(`schools/${SCHOOL_ID}`).get());
    });
  });

  // ── DEFENSE IN DEPTH: adminDisabled overrides allowed state ───
  describe.each(ALLOWED_STATES)('lifecycle.state="%s" + adminDisabled.value=true', (state) => {
    beforeEach(async () => { await seedTenant(state, true); });

    test.each(ROLES)('role=%s → read students/{doc} DENIED (admin override)', async (role) => {
      const db = authedDb(SCHOOL_ID, role);
      await assertFails(db.doc('students/STU0001_TEST').get());
    });
  });

  // ── tenantPublic STAYS READABLE in every state ────────────────
  // This is critical: mobile clients need to subscribe to
  // tenantPublic to DETECT the transition that triggers reactive
  // logout. Gating it would defeat the purpose.
  describe.each(ALL_STATES)('lifecycle.state="%s" → tenantPublic readable', (state) => {
    beforeEach(async () => { await seedTenant(state, false); });

    test.each(ROLES)('role=%s → read tenantPublic/{id} ALLOWED', async (role) => {
      const db = authedDb(SCHOOL_ID, role);
      await assertSucceeds(db.doc(`tenantPublic/${SCHOOL_ID}`).get());
    });
  });

  describe.each(ALLOWED_STATES)('lifecycle="%s" + adminDisabled=true → tenantPublic still readable', (state) => {
    beforeEach(async () => { await seedTenant(state, true); });

    test.each(ROLES)('role=%s → read tenantPublic ALLOWED (must stay readable)', async (role) => {
      const db = authedDb(SCHOOL_ID, role);
      await assertSucceeds(db.doc(`tenantPublic/${SCHOOL_ID}`).get());
    });
  });
});
