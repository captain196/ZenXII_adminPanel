/**
 * EXAM-LIFECYCLE Phase 2 (Visibility Hardening) — Firestore Rules matrix.
 *
 * Verifies the /exams read rule:
 *   allow read: if isStaff()
 *               || (isSameSchool() && resource.data.status in ['Published','Completed']);
 *
 *   1. Non-staff (parent) may read Published + Completed exams.
 *   2. Non-staff (parent) may NOT read Draft exams (Draft is staff-only).
 *   3. Staff (teacher/admin/school_super_admin) read ALL statuses incl.
 *      Draft — the Teacher marks-entry picker depends on Draft reads.
 *   4. Cross-tenant parent is denied every status (tenant isolation).
 *   5. Writes remain admin-only (isAdmin) regardless of status.
 *
 * The tenant is seeded in lifecycle.state='active' so isSameSchool()'s
 * tenantActive gate passes; this suite isolates the status dimension.
 *
 * Run: cd firebase-rules/tests && npm install && npm test
 * Requires: Node 18+, Java 11+ (Firestore emulator), Firebase CLI.
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

const SCHOOL_ID = 'SCH_TEST_EXAM_001';
const OTHER_SCHOOL_ID = 'SCH_TEST_EXAM_002';
const SESSION = '2025-26';

// Production-issued role claim values (proven against the live claim
// minters, NOT assumed):
//   - parent app authenticates as the studentId; its role claim is
//     'student' at account creation (Sis.php setFirebaseClaims) and
//     'Parent' after a password reset (Sis.php reset path). Both are
//     non-staff → must see Published/Completed only.
//   - teacher app role claim is 'Teacher' (Staff.php) → staff → Draft ok.
const PARENT_ROLES = ['student', 'Parent'];
const STAFF_ROLES = ['Teacher', 'admin', 'school_super_admin'];
const STATUSES = ['Draft', 'Published', 'Completed'];

/** docId convention matches backend: `{schoolId}_{examId}`. */
function examDocId(status) { return `${SCHOOL_ID}_EX_${status}`; }

/**
 * Seed an active tenant + one exam doc per status. Uses rules-disabled
 * context to build the world (rules bypassed for setup).
 */
async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.doc(`schoolControl/${SCHOOL_ID}`).set({
      schoolId: SCHOOL_ID,
      lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
    });
    await db.doc(`schools/${SCHOOL_ID}`).set({
      schoolId: SCHOOL_ID,
      name: 'Exam Test School',
      adminDisabled: { value: false, updatedAt: 'test' },
    });
    for (const status of STATUSES) {
      await db.doc(`exams/${examDocId(status)}`).set({
        schoolId: SCHOOL_ID,
        session: SESSION,
        examId: `EX_${status}`,
        examName: `${status} Exam`,
        status,
        startDate: '2025-12-01',
      });
    }
  });
}

/** Authed Firestore client for a given role + schoolId. */
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

afterAll(async () => { if (env) await env.cleanup(); });

beforeEach(async () => { await env.clearFirestore(); await seedWorld(); });

describe('EXAM-LIFECYCLE Phase 2 — /exams visibility matrix', () => {
  // ── Non-staff (parent): Published/Completed readable, Draft denied ──
  // Covers BOTH production-issued parent role values ('student','Parent').
  describe.each(PARENT_ROLES)('parent role=%s (non-staff)', (role) => {
    test.each(['Published', 'Completed'])(
      '→ read %s exam ALLOWED', async (status) => {
        const db = authedDb(SCHOOL_ID, role);
        await assertSucceeds(db.doc(`exams/${examDocId(status)}`).get());
      });

    test('→ read Draft exam DENIED', async () => {
      const db = authedDb(SCHOOL_ID, role);
      await assertFails(db.doc(`exams/${examDocId('Draft')}`).get());
    });
  });

  // ── Staff: every status readable (incl. Draft) ───────────────
  describe.each(STAFF_ROLES)('staff role=%s', (role) => {
    test.each(STATUSES)('→ read %s exam ALLOWED', async (status) => {
      const db = authedDb(SCHOOL_ID, role);
      await assertSucceeds(db.doc(`exams/${examDocId(status)}`).get());
    });
  });

  // ── Cross-tenant parent: denied every status ─────────────────
  test.each(STATUSES)(
    'cross-school parent (role=student) → read %s exam DENIED', async (status) => {
      const db = authedDb(OTHER_SCHOOL_ID, 'student');
      await assertFails(db.doc(`exams/${examDocId(status)}`).get());
    });

  // ── Writes stay admin-only regardless of status ──────────────
  test('teacher → write exam DENIED (write is admin-only)', async () => {
    const db = authedDb(SCHOOL_ID, 'teacher');
    await assertFails(
      db.doc(`exams/${SCHOOL_ID}_EX_NEW`).set({
        schoolId: SCHOOL_ID, session: SESSION, examId: 'EX_NEW',
        status: 'Draft', startDate: '2025-12-09',
      })
    );
  });

  test('admin → write exam ALLOWED', async () => {
    const db = authedDb(SCHOOL_ID, 'admin');
    await assertSucceeds(
      db.doc(`exams/${SCHOOL_ID}_EX_NEW`).set({
        schoolId: SCHOOL_ID, session: SESSION, examId: 'EX_NEW',
        status: 'Draft', startDate: '2025-12-09',
      })
    );
  });
});
