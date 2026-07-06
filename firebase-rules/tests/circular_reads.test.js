/**
 * circularReads / circularAcks — 2026-07-03 lockdown verification.
 * Owner-only + tenant-scoped writes; staff-read; no user delete.
 */
const { initializeTestEnvironment, assertSucceeds, assertFails } = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');
const SCH_A = 'SCH_A0001';
const SCH_B = 'SCH_B0002';
let env;

async function seed(state = 'active') {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCH_A, SCH_B]) {
      await db.doc(`schoolControl/${s}`).set({ schoolId: s, lifecycle: { state } });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: s });
    }
    // an existing receipt owned by u1 for read tests
    await db.doc('circularReads/C1_u1').set({ circularId: 'C1', userId: 'u1', schoolId: SCH_A, readAt: 1 });
  });
}
const asUser = (uid, school, role) => env.authenticatedContext(uid, { school_id: school, role }).firestore();

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); });

describe('circularReads lockdown', () => {
  beforeEach(async () => { await seed('active'); });

  test('owner writes own receipt → allowed', async () => {
    await assertSucceeds(asUser('u1', SCH_A, 'parent').doc('circularReads/C9_u1')
      .set({ circularId: 'C9', userId: 'u1', schoolId: SCH_A, readAt: 2 }));
  });
  test('forge another user\'s receipt → denied', async () => {
    await assertFails(asUser('u1', SCH_A, 'parent').doc('circularReads/C9_u2')
      .set({ circularId: 'C9', userId: 'u2', schoolId: SCH_A, readAt: 2 }));
  });
  test('cross-tenant schoolId → denied', async () => {
    await assertFails(asUser('u1', SCH_A, 'parent').doc('circularReads/C9_u1')
      .set({ circularId: 'C9', userId: 'u1', schoolId: SCH_B, readAt: 2 }));
  });
  test('other-school user writing into SCH_A → denied', async () => {
    await assertFails(asUser('ub', SCH_B, 'parent').doc('circularReads/C9_ub')
      .set({ circularId: 'C9', userId: 'ub', schoolId: SCH_A, readAt: 2 }));
  });
  test('write denied when tenant suspended', async () => {
    await env.withSecurityRulesDisabled(async (ctx) =>
      ctx.firestore().doc(`schoolControl/${SCH_A}`).set({ schoolId: SCH_A, lifecycle: { state: 'suspended' } }));
    await assertFails(asUser('u1', SCH_A, 'parent').doc('circularReads/C9_u1')
      .set({ circularId: 'C9', userId: 'u1', schoolId: SCH_A, readAt: 2 }));
  });
  test('owner reads own receipt → allowed', async () => {
    await assertSucceeds(asUser('u1', SCH_A, 'parent').doc('circularReads/C1_u1').get());
  });
  test('non-owner non-staff reads someone else\'s receipt → denied', async () => {
    await assertFails(asUser('u2', SCH_A, 'parent').doc('circularReads/C1_u1').get());
  });
  test('staff reads any receipt → allowed', async () => {
    await assertSucceeds(asUser('t1', SCH_A, 'teacher').doc('circularReads/C1_u1').get());
  });
  test('user cannot delete a receipt → denied', async () => {
    await assertFails(asUser('u1', SCH_A, 'parent').doc('circularReads/C1_u1').delete());
  });
});

describe('circularAcks lockdown', () => {
  beforeEach(async () => { await seed('active'); });
  test('owner writes own ack → allowed', async () => {
    await assertSucceeds(asUser('u1', SCH_A, 'parent').doc('circularAcks/C1_u1')
      .set({ circularId: 'C1', userId: 'u1', schoolId: SCH_A, ackAt: 1 }));
  });
  test('forge another user\'s ack → denied', async () => {
    await assertFails(asUser('u1', SCH_A, 'parent').doc('circularAcks/C1_u2')
      .set({ circularId: 'C1', userId: 'u2', schoolId: SCH_A, ackAt: 1 }));
  });
});
