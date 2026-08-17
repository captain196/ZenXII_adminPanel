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

/**
 * F5 (2026-08-15) — graded capability on create.
 *
 * The create arm used hasCapability('Red Flags'), which only checks module
 * PRESENCE. A staff member granted Red Flags at `view` was blocked in the UI
 * (ModuleGate.canEdit) but NOT by the rules, so a direct SDK call was accepted:
 * the client and the server disagreed on who may write. It is now
 * hasCapabilityLevel('Red Flags', 'edit').
 *
 * These tests seed an actual staffCapabilities doc — the pre-existing suite
 * never did, which is precisely why the gap went unnoticed.
 */
describe('studentFlags — create requires Red Flags at edit level (F5)', () => {
  async function seedCaps(uid, modules, levels) {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc(`staffCapabilities/${uid}`).set({
        schoolId: SCHOOL, modules, levels,
      });
    });
  }

  beforeEach(async () => { await seedWorld('active'); });

  test('view-level grantee CANNOT create (was allowed before the fix)', async () => {
    await seedCaps(T1, ['Red Flags'], { 'Red Flags': 'view' });
    await assertFails(teacher(T1).doc(docId('CAP1')).set(baseFlag({ flagId: 'CAP1' })));
  });

  test('edit-level grantee CAN create', async () => {
    await seedCaps(T1, ['Red Flags'], { 'Red Flags': 'edit' });
    await assertSucceeds(teacher(T1).doc(docId('CAP2')).set(baseFlag({ flagId: 'CAP2' })));
  });

  test('manage-level grantee CAN create', async () => {
    await seedCaps(T1, ['Red Flags'], { 'Red Flags': 'manage' });
    await assertSucceeds(teacher(T1).doc(docId('CAP3')).set(baseFlag({ flagId: 'CAP3' })));
  });

  test('module absent from a populated caps doc → denied', async () => {
    await seedCaps(T1, ['Attendance'], { Attendance: 'manage' });
    await assertFails(teacher(T1).doc(docId('CAP4')).set(baseFlag({ flagId: 'CAP4' })));
  });

  /**
   * Documents a real client/server asymmetry in an UNREACHABLE state:
   * hasCapabilityLevel() defaults a missing level entry to 'view' (→ denied),
   * whereas ModuleGate.canEdit() treats present-but-unlevelled as full access
   * (→ FAB shown). Unreachable because functions/staffCapabilities.js writes a
   * levels[m] entry for EVERY module it grants (defaulting to 'manage', incl.
   * the legacy-roles fallback), so `modules` can never contain a module absent
   * from `levels`. Pinned here so a future change to that writer trips a test
   * instead of silently locking teachers out of raising flags.
   */
  test('module held with NO level entry → denied (fails closed; unreachable in practice)', async () => {
    await seedCaps(T1, ['Red Flags'], {});
    await assertFails(teacher(T1).doc(docId('CAP5')).set(baseFlag({ flagId: 'CAP5' })));
  });

  test('NO caps doc at all → still fail-open (pre-rollout accounts unaffected)', async () => {
    await assertSucceeds(teacher(T1).doc(docId('CAP6')).set(baseFlag({ flagId: 'CAP6' })));
  });

  /**
   * F15 (2026-08-15) — the owner (non-admin) update arm had NO capability check,
   * so a view-level grantee could not CREATE a flag but could still RESOLVE or
   * SOFT-DELETE their own, while the admin panel gates resolve at `edit`. The
   * owner arm now requires `edit` too — deliberately not `manage`, because the
   * 5-second Undo soft-deletes the flag the teacher just raised.
   */
  test('view-level grantee CANNOT resolve their own flag (was allowed before the fix)', async () => {
    await seedCaps(T1, ['Red Flags'], { 'Red Flags': 'view' });
    await assertFails(teacher(T1).doc(docId('F1')).update({
      status: 'resolved', resolvedBy: T1, resolvedAtMs: 1751000009999,
    }));
  });

  test('view-level grantee CANNOT soft-delete their own flag', async () => {
    await seedCaps(T1, ['Red Flags'], { 'Red Flags': 'view' });
    await assertFails(teacher(T1).doc(docId('F1')).update({
      status: 'deleted', deletedBy: T1, deletedAtMs: 1751000009999,
    }));
  });

  test('edit-level grantee CAN resolve their own flag', async () => {
    await seedCaps(T1, ['Red Flags'], { 'Red Flags': 'edit' });
    await assertSucceeds(teacher(T1).doc(docId('F1')).update({
      status: 'resolved', resolvedBy: T1, resolvedAtMs: 1751000009999,
    }));
  });

  test('edit-level grantee CAN soft-delete their own flag (Undo must keep working)', async () => {
    await seedCaps(T1, ['Red Flags'], { 'Red Flags': 'edit' });
    await assertSucceeds(teacher(T1).doc(docId('F1')).update({
      status: 'deleted', deletedBy: T1, deletedAtMs: 1751000009999,
    }));
  });

  test('ADMIN is unaffected by the capability gate on update', async () => {
    await seedCaps(ADMIN, ['Attendance'], { Attendance: 'view' });
    await assertSucceeds(admin(ADMIN).doc(docId('F1')).update({
      status: 'resolved', resolvedBy: ADMIN, resolvedAtMs: 1751000009999,
    }));
  });
});
