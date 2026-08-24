/**
 * Lifecycle attack surface — final adversarial pass (2026-08-22).
 *
 * The Parent and Teacher apps now TRUST `tenantPublic` to decide what to show
 * the user. That makes the mirror a security-relevant surface for the first
 * time: if a client could write it, a closed school could be forged back to
 * "Operational" in the UI, and a suspended tenant could hide its own hold.
 *
 * This suite attacks the signal itself, plus the state that feeds it.
 *
 * Run: cd firebase-rules/tests && npx firebase emulators:exec --only firestore \
 *        --project=zenxii-rules-test "npx jest lifecycle_attack --runInBand --forceExit"
 */
const {
  initializeTestEnvironment, assertSucceeds, assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');

const SCHOOL = 'SCH_ATK_001';
const OTHER  = 'SCH_ATK_002';

let env;

function actor(uid, role, schoolId = SCHOOL) {
  return env.authenticatedContext(uid, {
    school_id: schoolId, schoolId: schoolId, role: role, user_id: uid,
  }).firestore();
}
const parent = (s = SCHOOL) => actor('PAR_1', 'Parent', s);
const teacher = (s = SCHOOL) => actor('STA_1', 'Teacher', s);
const admin = (s = SCHOOL) => actor('ADM_1', 'Admin', s);
const ssa = (s = SCHOOL) => actor('SSA_1', 'School Super Admin', s);

/**
 * Seeds one tenant. ALL privileged setup must funnel through this helper:
 * calling env.withSecurityRulesDisabled() ad hoc inside a test throws
 * "Firestore has already been started and its settings can no longer be
 * changed" once a client instance exists in that test.
 */
async function seed(schoolId, commercial, institution, adminDisabled) {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    await db.doc(`schoolControl/${schoolId}`).set({ lifecycle: { state: commercial } });
    const s = { schoolId: schoolId, name: 'Atk' };
    if (institution) s.institution = { state: institution };
    if (adminDisabled !== undefined) s.adminDisabled = { value: adminDisabled };
    await db.doc(`schools/${schoolId}`).set(s);
    await db.doc(`tenantPublic/${schoolId}`).set({
      schoolId: schoolId,
      lifecycleState: commercial,
      institutionState: institution || 'ACTIVE',
      adminDisabled: adminDisabled === true,
    });
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8') },
  });
}, 60000);
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); });

// ── The signal the apps trust ────────────────────────────────────────

describe('tenantPublic cannot be forged by any client', () => {
  const forge = { institutionState: 'ACTIVE', lifecycleState: 'active', adminDisabled: false };

  for (const [label, ctx] of [
    ['parent', () => parent()],
    ['teacher', () => teacher()],
    ['admin', () => admin()],
    ['school super admin', () => ssa()],
  ]) {
    test(`${label} cannot overwrite their own school's mirror`, async () => {
      await seed(SCHOOL, 'suspended', 'CLOSED');
      await assertFails(ctx().doc(`tenantPublic/${SCHOOL}`).set(forge));
      await assertFails(ctx().doc(`tenantPublic/${SCHOOL}`).update(forge));
    });
  }

  test('a client cannot delete the mirror to escape a restriction', async () => {
    await seed(SCHOOL, 'suspended', 'CLOSED');
    await assertFails(admin().doc(`tenantPublic/${SCHOOL}`).delete());
  });

  test('a client cannot create a mirror for a school that has none', async () => {
    await seed(SCHOOL, 'active', 'ACTIVE');
    await assertFails(
      admin().doc(`tenantPublic/${OTHER}`).set({ schoolId: OTHER, institutionState: 'ACTIVE' })
    );
  });

  test('the mirror remains READABLE for the caller\'s own school (apps depend on it)', async () => {
    await seed(SCHOOL, 'suspended', 'CLOSED');
    // This is the one read that must survive a restriction, otherwise the app
    // cannot explain why everything else is denied.
    await assertSucceeds(parent().doc(`tenantPublic/${SCHOOL}`).get());
  });

  test('the mirror of ANOTHER school is not readable', async () => {
    await seed(SCHOOL, 'active', 'ACTIVE');
    await seed(OTHER, 'active', 'CLOSED');
    await assertFails(parent(SCHOOL).doc(`tenantPublic/${OTHER}`).get());
  });
});

// ── The state that feeds the signal ──────────────────────────────────

describe('institution state cannot be forged at source', () => {
  test('a school admin cannot flip their own school back to ACTIVE', async () => {
    await seed(SCHOOL, 'active', 'CLOSED');
    await assertFails(
      admin().doc(`schools/${SCHOOL}`).set({ institution: { state: 'ACTIVE' } }, { merge: true })
    );
  });

  test('a school super admin cannot flip institution state either', async () => {
    await seed(SCHOOL, 'active', 'ARCHIVED');
    await assertFails(
      ssa().doc(`schools/${SCHOOL}`).set({ institution: { state: 'ACTIVE' } }, { merge: true })
    );
  });

  test('a client cannot forge the commercial axis', async () => {
    await seed(SCHOOL, 'suspended', 'ACTIVE');
    await assertFails(
      admin().doc(`schoolControl/${SCHOOL}`).set({ lifecycle: { state: 'active' } }, { merge: true })
    );
    await assertFails(admin().doc(`schoolControl/${SCHOOL}`).get());
  });

  test('a client cannot clear the adminDisabled kill switch', async () => {
    // The operator's manual suspension toggle. If a school admin could clear
    // it, any suspended tenant could restore itself.
    await seed(SCHOOL, 'active', 'ACTIVE', true);
    await assertFails(
      admin().doc(`schools/${SCHOOL}`).set({ adminDisabled: { value: false } }, { merge: true })
    );
    await assertFails(
      ssa().doc(`schools/${SCHOOL}`).set({ adminDisabled: { value: false } }, { merge: true })
    );
  });
});

// ── The audit trail ──────────────────────────────────────────────────

describe('lifecycle audit cannot be forged or read by clients', () => {
  test('no client may write a lifecycle event', async () => {
    await seed(SCHOOL, 'active', 'ACTIVE');
    for (const ctx of [parent(), teacher(), admin(), ssa()]) {
      await assertFails(ctx.doc(`schoolLifecycleEvents/LC_FORGED`).set({
        schoolId: SCHOOL, fromState: 'CLOSED', toState: 'ACTIVE', actorId: 'someone-else',
      }));
    }
  });

  test('no client may read the lifecycle history', async () => {
    await seed(SCHOOL, 'active', 'ACTIVE');
    await env.withSecurityRulesDisabled(async (c) => {
      await c.firestore().doc(`schoolLifecycleEvents/LC_REAL`).set({
        schoolId: SCHOOL, actorId: 'SA0001', reason: 'internal note',
      });
    });
    await assertFails(admin().doc('schoolLifecycleEvents/LC_REAL').get());
    await assertFails(ssa().doc('schoolLifecycleEvents/LC_REAL').get());
  });

  test('no client may tamper with an existing event', async () => {
    await seed(SCHOOL, 'active', 'ACTIVE');
    await env.withSecurityRulesDisabled(async (c) => {
      await c.firestore().doc('schoolLifecycleEvents/LC_REAL').set({ schoolId: SCHOOL, outcome: 'applied' });
    });
    await assertFails(admin().doc('schoolLifecycleEvents/LC_REAL').update({ outcome: 'rejected' }));
    await assertFails(admin().doc('schoolLifecycleEvents/LC_REAL').delete());
  });
});

// ── Cross-tenant under every lifecycle state ─────────────────────────

describe('lifecycle state never opens a cross-tenant path', () => {
  for (const state of ['ACTIVE', 'CLOSING', 'CLOSED', 'ARCHIVED']) {
    test(`${state} school cannot reach another tenant`, async () => {
      await seed(SCHOOL, 'active', state);
      await seed(OTHER, 'active', 'ACTIVE');
      await assertFails(teacher(SCHOOL).doc(`schools/${OTHER}`).get());
      await assertFails(teacher(SCHOOL).doc(`tenantPublic/${OTHER}`).get());
      await assertFails(teacher(SCHOOL).doc(`sosAlerts/${OTHER}_X`).set({
        schoolId: OTHER, alertType: 'emergency', severity: 'High',
        app_version: '1.0.0', client_platform: 'android', client_timestamp: 1751000000000,
      }));
    });
  }
});
