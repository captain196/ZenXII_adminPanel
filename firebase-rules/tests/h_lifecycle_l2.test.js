/**
 * H-LIFECYCLE Layer 2 — emulator-based end-to-end flow tests.
 *
 * Differs from Layer 1 (h_lifecycle.test.js):
 *   • L1 is a wide matrix of single-op checks against minimal docs.
 *   • L2 is a focused set of realistic FLOWS: a parent navigating
 *     multiple collections, a suspend transition mid-session, a
 *     reactivation cycle, the cross-tenant isolation check, etc.
 *   • L2 also uses realistic doc shapes (full schools/schoolControl
 *     payloads + multi-collection seed) to catch issues that minimal
 *     stubs miss (e.g. unrelated fields breaking the rule eval).
 *
 * Together L1 + L2 give layered confidence before any rules deploy.
 *
 * Run alongside L1 via: cd firebase-rules/tests && npm test
 */
const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test-l2';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');
const SCHOOL_A = 'SCH_TEST_L2_A001';
const SCHOOL_B = 'SCH_TEST_L2_B002';

let env;

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

afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); });

/** Seed a tenant with realistic full-shape docs across all gated collections. */
async function seedRealisticTenant({ schoolId, lifecycleState, adminDisabled = false }) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.doc(`schools/${schoolId}`).set({
      schoolId,
      name: 'Test School ' + schoolId.slice(-4),
      schoolCode: '999' + schoolId.slice(-1),
      domainIdentifier: 'test-' + schoolId.toLowerCase(),
      primarySsaId: 'SSA_TEST_' + schoolId.slice(-1),
      createdAt: '2026-01-01',
      logoUrl: '',
      sessions: ['2026-27'],
      currentSession: '2026-27',
      statsCache: { totalStudents: 100, totalStaff: 10, lastUpdated: '2026-05-31T00:00:00Z' },
      adminDisabled: { value: adminDisabled, updatedAt: '2026-05-31T00:00:00Z' },
    });
    await db.doc(`schoolControl/${schoolId}`).set({
      schoolId,
      lifecycle: { state: lifecycleState, computedAt: Date.now(), reason: lifecycleState },
      subscription: { subscriptionId: 'SUB_' + schoolId, planId: 'PLAN_TEST' },
      entitlements: { student_management: true, fees: true },
      billingSummary: { totalDue: 0 },
    });
    await db.doc(`tenantPublic/${schoolId}`).set({
      schoolId,
      lifecycleState,
      adminDisabled,
      activeModules: { student_management: true, fees: true },
      mirroredAt: '2026-05-31T00:00:00Z',
    });
    // Multi-collection seed: students, staff, attendance, homework, feeReceipts.
    for (let i = 1; i <= 3; i++) {
      await db.doc(`students/STU_${schoolId}_${i}`).set({
        schoolId, studentId: 'STU000' + i, name: 'Student ' + i, classKey: '10',
      });
    }
    await db.doc(`staff/STA_${schoolId}_1`).set({
      schoolId, staffId: 'STA0001', name: 'Test Teacher', role: 'teacher',
    });
    await db.doc(`attendance/ATT_${schoolId}_2026-05-31`).set({
      schoolId, date: '2026-05-31', records: {},
    });
    await db.doc(`homework/HW_${schoolId}_1`).set({
      schoolId, hwId: 'HW_' + schoolId + '_1', title: 'Test HW',
    });
    await db.doc(`feeReceipts/FR_${schoolId}_1`).set({
      schoolId, receiptId: 'FR_' + schoolId + '_1', amount: 1000,
    });
  });
}

const authedDb = (schoolId, role, uid = 'l2-user') =>
  env.authenticatedContext(uid, { school_id: schoolId, role }).firestore();
const unauthedDb = () => env.unauthenticatedContext().firestore();

// ───────────────────────────────────────────────────────────────────
// FLOW A: active tenant — parent reads multiple collections (happy path)
// ───────────────────────────────────────────────────────────────────
describe('L2 Flow A — Active tenant, parent reads multiple collections', () => {
  beforeEach(async () => { await seedRealisticTenant({ schoolId: SCHOOL_A, lifecycleState: 'active' }); });

  test('parent reads students', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'parent').doc(`students/STU_${SCHOOL_A}_1`).get());
  });
  // attendance + feeReceipts have rules that restrict parents to their
  // OWN student (isOwnStudent) — that's existing rule design,
  // independent of H1. Use teacher role here (which has unconditional
  // school-scoped read) to isolate the H1-gate behavior under test.
  test('teacher reads attendance', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'teacher').doc(`attendance/ATT_${SCHOOL_A}_2026-05-31`).get());
  });
  test('parent reads homework', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'parent').doc(`homework/HW_${SCHOOL_A}_1`).get());
  });
  test('teacher reads feeReceipts', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'teacher').doc(`feeReceipts/FR_${SCHOOL_A}_1`).get());
  });
  test('parent reads own school doc', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'parent').doc(`schools/${SCHOOL_A}`).get());
  });
  test('parent reads tenantPublic', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'parent').doc(`tenantPublic/${SCHOOL_A}`).get());
  });
  test('teacher reads staff', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'teacher').doc(`staff/STA_${SCHOOL_A}_1`).get());
  });
});

// ───────────────────────────────────────────────────────────────────
// FLOW B: tenant SUSPENDED mid-session — all access denied except mirror
// ───────────────────────────────────────────────────────────────────
describe('L2 Flow B — Tenant suspended mid-session', () => {
  beforeEach(async () => { await seedRealisticTenant({ schoolId: SCHOOL_A, lifecycleState: 'suspended' }); });

  test('parent → students DENIED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`students/STU_${SCHOOL_A}_1`).get());
  });
  test('parent → attendance DENIED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`attendance/ATT_${SCHOOL_A}_2026-05-31`).get());
  });
  test('parent → homework DENIED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`homework/HW_${SCHOOL_A}_1`).get());
  });
  test('parent → schools DENIED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`schools/${SCHOOL_A}`).get());
  });
  test('teacher → staff DENIED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'teacher').doc(`staff/STA_${SCHOOL_A}_1`).get());
  });
  test('admin → write student DENIED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'admin').doc('students/STU_NEW_BLOCKED').set({
      schoolId: SCHOOL_A, studentId: 'STU_NEW', name: 'Blocked',
    }));
  });
  test('parent STILL reads tenantPublic (mirror always accessible)', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'parent').doc(`tenantPublic/${SCHOOL_A}`).get());
  });
});

// ───────────────────────────────────────────────────────────────────
// FLOW C: adminDisabled override — Q3 defense-in-depth
// ───────────────────────────────────────────────────────────────────
describe('L2 Flow C — adminDisabled=true overrides allowed lifecycle (Q3 defense in depth)', () => {
  beforeEach(async () => {
    await seedRealisticTenant({ schoolId: SCHOOL_A, lifecycleState: 'active', adminDisabled: true });
  });

  test('teacher → students DENIED (lifecycle says active, but admin override)', async () => {
    await assertFails(authedDb(SCHOOL_A, 'teacher').doc(`students/STU_${SCHOOL_A}_1`).get());
  });
  test('admin → staff DENIED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'admin').doc(`staff/STA_${SCHOOL_A}_1`).get());
  });
  test('parent → schools DENIED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`schools/${SCHOOL_A}`).get());
  });
  test('parent STILL reads tenantPublic', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'parent').doc(`tenantPublic/${SCHOOL_A}`).get());
  });
});

// ───────────────────────────────────────────────────────────────────
// FLOW D: lifecycle transition (suspended → active)
// ───────────────────────────────────────────────────────────────────
describe('L2 Flow D — Lifecycle transition suspended → active', () => {
  beforeEach(async () => { await seedRealisticTenant({ schoolId: SCHOOL_A, lifecycleState: 'suspended' }); });

  test('Initially DENIED; after backend reactivation → ALLOWED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`students/STU_${SCHOOL_A}_1`).get());

    // Backend (service-account-equivalent: rules disabled context) reactivates
    await env.withSecurityRulesDisabled(async (ctx) => {
      const adminDb = ctx.firestore();
      await adminDb.doc(`schoolControl/${SCHOOL_A}`).update({ 'lifecycle.state': 'active' });
      await adminDb.doc(`tenantPublic/${SCHOOL_A}`).update({ lifecycleState: 'active' });
    });

    // Same authed client now sees access restored on next read.
    await assertSucceeds(authedDb(SCHOOL_A, 'parent').doc(`students/STU_${SCHOOL_A}_1`).get());
  });
});

// ───────────────────────────────────────────────────────────────────
// FLOW E: cross-tenant isolation regression check
// ───────────────────────────────────────────────────────────────────
describe('L2 Flow E — Cross-tenant isolation regression (existing rule still works under H1)', () => {
  beforeEach(async () => {
    await seedRealisticTenant({ schoolId: SCHOOL_A, lifecycleState: 'active' });
    await seedRealisticTenant({ schoolId: SCHOOL_B, lifecycleState: 'active' });
  });

  test('parent-of-A cannot read B students', async () => {
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`students/STU_${SCHOOL_B}_1`).get());
  });
  test('parent-of-A cannot read B schools', async () => {
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`schools/${SCHOOL_B}`).get());
  });
  test('parent-of-A cannot read B tenantPublic', async () => {
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`tenantPublic/${SCHOOL_B}`).get());
  });
});

// ───────────────────────────────────────────────────────────────────
// FLOW F: unauthenticated client
// ───────────────────────────────────────────────────────────────────
describe('L2 Flow F — Unauthenticated client', () => {
  beforeEach(async () => { await seedRealisticTenant({ schoolId: SCHOOL_A, lifecycleState: 'active' }); });

  test('unauth → students DENIED', async () => {
    await assertFails(unauthedDb().doc(`students/STU_${SCHOOL_A}_1`).get());
  });
  test('unauth → tenantPublic DENIED (auth required even for mirror)', async () => {
    await assertFails(unauthedDb().doc(`tenantPublic/${SCHOOL_A}`).get());
  });
  test('unauth → schools DENIED', async () => {
    await assertFails(unauthedDb().doc(`schools/${SCHOOL_A}`).get());
  });
});

// ───────────────────────────────────────────────────────────────────
// FLOW G: edge case — schoolControl doc missing (fail-closed)
// ───────────────────────────────────────────────────────────────────
describe('L2 Flow G — Edge case: schoolControl doc missing', () => {
  test('Tenant without schoolControl → reads DENIED (fail-closed)', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      const db = ctx.firestore();
      await db.doc(`schools/${SCHOOL_A}`).set({
        schoolId: SCHOOL_A, name: 'Orphan', adminDisabled: { value: false },
      });
      await db.doc(`students/STU_ORPHAN`).set({ schoolId: SCHOOL_A, studentId: 'STU_ORPHAN' });
      // intentionally NOT seeding schoolControl
    });
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`students/STU_ORPHAN`).get());
  });
});

// ───────────────────────────────────────────────────────────────────
// FLOW H: edge case — adminDisabled.value missing (treat as not disabled)
// ───────────────────────────────────────────────────────────────────
describe('L2 Flow H — Edge case: adminDisabled field absent (fresh tenant)', () => {
  test('Active + adminDisabled field MISSING → reads ALLOWED', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      const db = ctx.firestore();
      // schools doc WITHOUT adminDisabled field at all
      await db.doc(`schools/${SCHOOL_A}`).set({
        schoolId: SCHOOL_A, name: 'Fresh Tenant',
      });
      await db.doc(`schoolControl/${SCHOOL_A}`).set({
        schoolId: SCHOOL_A,
        lifecycle: { state: 'active', computedAt: Date.now() },
      });
      await db.doc(`tenantPublic/${SCHOOL_A}`).set({
        schoolId: SCHOOL_A, lifecycleState: 'active',
      });
      await db.doc(`students/STU_FRESH`).set({ schoolId: SCHOOL_A, studentId: 'STU_FRESH' });
    });
    await assertSucceeds(authedDb(SCHOOL_A, 'parent').doc(`students/STU_FRESH`).get());
  });
});

// ───────────────────────────────────────────────────────────────────
// FLOW I: each denied state individually (suspended/expired/past_due)
// ───────────────────────────────────────────────────────────────────
describe.each(['suspended', 'expired', 'past_due'])('L2 Flow I — Denied state "%s"', (state) => {
  beforeEach(async () => { await seedRealisticTenant({ schoolId: SCHOOL_A, lifecycleState: state }); });

  test('parent → students DENIED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'parent').doc(`students/STU_${SCHOOL_A}_1`).get());
  });
  test('admin → write attendance DENIED', async () => {
    await assertFails(authedDb(SCHOOL_A, 'admin').doc(`attendance/ATT_NEW`).set({
      schoolId: SCHOOL_A, date: '2026-06-01', records: {},
    }));
  });
});

// ───────────────────────────────────────────────────────────────────
// FLOW J: each allowed state individually (active/trialing/expiring_soon/grace)
// ───────────────────────────────────────────────────────────────────
describe.each(['active', 'trialing', 'expiring_soon', 'grace'])('L2 Flow J — Allowed state "%s"', (state) => {
  beforeEach(async () => { await seedRealisticTenant({ schoolId: SCHOOL_A, lifecycleState: state }); });

  test('parent → students ALLOWED', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'parent').doc(`students/STU_${SCHOOL_A}_1`).get());
  });
  test('teacher → attendance ALLOWED', async () => {
    await assertSucceeds(authedDb(SCHOOL_A, 'teacher').doc(`attendance/ATT_${SCHOOL_A}_2026-05-31`).get());
  });
});
