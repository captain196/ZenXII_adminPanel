/**
 * studentFlags — Firestore Rules unit tests for the 2026-07-02 hardening:
 *   - Owner-scoped moderation (teacher can resolve/soft-delete ONLY own flags;
 *     soft-delete requires createdByRole=='teacher'; admin unrestricted).
 *   - Parent read gated by tenantActive() + own-child scoping.
 *   - Create hardening (teacherId==uid, non-empty studentId/flagId,
 *     createdAtMs>0, enum constraints).
 *   - Semantic-field immutability on update.
 *
 * Run: cd firebase-rules/tests && npm install && npm test
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
const SCHOOL = 'SCH_TEST_FLAG_001';

const T1 = 'teacher-uid-1';
const T2 = 'teacher-uid-2';
const ADMIN = 'admin-uid-1';

let env;

function docId(flagId) { return `studentFlags/${SCHOOL}_${flagId}`; }

function baseFlag(overrides) {
  return Object.assign({
    schoolId: SCHOOL,
    schoolCode: '10999',
    studentId: 'STU_A',
    flagId: 'F1',
    teacherId: T1,
    createdByRole: 'teacher',
    status: 'active',
    type: 'behavior',
    severity: 'high',
    message: 'Original message',
    subject: 'Maths',
    createdAtMs: 1751000000000,
  }, overrides || {});
}

async function seedWorld(state) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.doc(`schoolControl/${SCHOOL}`).set({
      schoolId: SCHOOL,
      lifecycle: { state, computedAt: Date.now(), reason: state },
    });
    await db.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL, name: 'Flag Test School' });
    // F1: teacher-created by T1 for STU_A
    await db.doc(docId('F1')).set(baseFlag());
    // F2: admin-created, routed to T1's uid, for STU_A
    await db.doc(docId('F2')).set(baseFlag({ flagId: 'F2', createdByRole: 'admin' }));
    // F3: teacher-created by T1 for a DIFFERENT student STU_B
    await db.doc(docId('F3')).set(baseFlag({ flagId: 'F3', studentId: 'STU_B' }));
  });
}

function teacher(uid) {
  return env.authenticatedContext(uid, { school_id: SCHOOL, role: 'teacher' }).firestore();
}
function admin(uid) {
  return env.authenticatedContext(uid, { school_id: SCHOOL, role: 'admin' }).firestore();
}
function parent(uid, studentIds) {
  return env.authenticatedContext(uid, { school_id: SCHOOL, role: 'parent', student_ids: studentIds }).firestore();
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); });

describe('studentFlags — parent read scoping + tenant gate', () => {
  test('parent reads OWN child (active tenant) → allowed', async () => {
    await seedWorld('active');
    await assertSucceeds(parent('p1', ['STU_A']).doc(docId('F1')).get());
  });
  test('parent reads ANOTHER child → denied', async () => {
    await seedWorld('active');
    await assertFails(parent('p1', ['STU_A']).doc(docId('F3')).get());
  });
  test('parent read denied when tenant SUSPENDED (new tenantActive gate)', async () => {
    await seedWorld('suspended');
    await assertFails(parent('p1', ['STU_A']).doc(docId('F1')).get());
  });
});

describe('studentFlags — owner-scoped moderation (C2)', () => {
  beforeEach(async () => { await seedWorld('active'); });

  test('owner teacher RESOLVES own flag → allowed', async () => {
    await assertSucceeds(teacher(T1).doc(docId('F1')).update({
      status: 'resolved', resolvedBy: T1, resolvedAtMs: 1751000009999,
    }));
  });
  test('NON-owner teacher resolves someone else\'s flag → denied', async () => {
    await assertFails(teacher(T2).doc(docId('F1')).update({
      status: 'resolved', resolvedBy: T2, resolvedAtMs: 1751000009999,
    }));
  });
  test('owner teacher SOFT-DELETES own teacher-created flag → allowed', async () => {
    await assertSucceeds(teacher(T1).doc(docId('F1')).update({
      status: 'deleted', deletedBy: T1, deletedAtMs: 1751000009999,
    }));
  });
  test('teacher soft-deletes ADMIN-created flag routed to them → denied', async () => {
    await assertFails(teacher(T1).doc(docId('F2')).update({
      status: 'deleted', deletedBy: T1, deletedAtMs: 1751000009999,
    }));
  });
  test('ADMIN resolves any flag → allowed', async () => {
    await assertSucceeds(admin(ADMIN).doc(docId('F2')).update({
      status: 'resolved', resolvedBy: ADMIN, resolvedAtMs: 1751000009999,
    }));
  });
  test('ADMIN soft-deletes any flag → allowed', async () => {
    await assertSucceeds(admin(ADMIN).doc(docId('F3')).update({
      status: 'deleted', deletedBy: ADMIN, deletedAtMs: 1751000009999,
    }));
  });
  test('immutable severity cannot be changed on update → denied', async () => {
    await assertFails(teacher(T1).doc(docId('F1')).update({
      status: 'resolved', severity: 'low',
    }));
  });
  test('invalid status value → denied (enum guard)', async () => {
    await assertFails(teacher(T1).doc(docId('F1')).update({ status: 'banana' }));
  });
});

describe('studentFlags — create hardening', () => {
  beforeEach(async () => { await seedWorld('active'); });

  test('valid create by owning teacher → allowed', async () => {
    await assertSucceeds(teacher(T1).doc(docId('NEW1')).set(baseFlag({ flagId: 'NEW1' })));
  });
  test('create with teacherId != auth.uid → denied', async () => {
    await assertFails(teacher(T2).doc(docId('NEW2')).set(baseFlag({ flagId: 'NEW2', teacherId: T1 })));
  });
  test('create with empty studentId → denied', async () => {
    await assertFails(teacher(T1).doc(docId('NEW3')).set(baseFlag({ flagId: 'NEW3', studentId: '' })));
  });
  test('create with createdAtMs <= 0 → denied', async () => {
    await assertFails(teacher(T1).doc(docId('NEW4')).set(baseFlag({ flagId: 'NEW4', createdAtMs: 0 })));
  });
  test('create with invalid severity → denied', async () => {
    await assertFails(teacher(T1).doc(docId('NEW5')).set(baseFlag({ flagId: 'NEW5', severity: 'urgent' })));
  });
  test('create with status != active → denied', async () => {
    await assertFails(teacher(T1).doc(docId('NEW6')).set(baseFlag({ flagId: 'NEW6', status: 'resolved' })));
  });
});
