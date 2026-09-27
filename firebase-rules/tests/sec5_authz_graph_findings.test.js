/**
 * SEC-5 — MEASUREMENT OF THE AUTHORIZATION-GRAPH FINDINGS.
 *
 * STATUS 2026-08-22: REGRESSION PROOF, not characterisation.
 *
 * Every shape below was measured OPEN against the pre-fix live ruleset, which is
 * how each was confirmed real before a predicate was designed. SEC-6 closed A,
 * B, D and F in production (ruleset a4a484d2); SEC-8 made C explicit
 * (e8f2f47a). The exposure assertions have therefore been flipped from
 * assertSucceeds to assertFails and now guard the fixes.
 *
 * Shape C already asserted refusal and is unchanged — it was always a
 * FUNCTIONAL defect (a legitimate same-school delete denied by a null
 * dereference), never an exposure.
 *
 * ── WHY THIS SUITE EXISTS ─────────────────────────────────────────────────
 * `scripts/firestore_authz_graph.php` reconstructs the whole ruleset per
 * (collection × operation) with helpers inlined and OR-semantics resolved. It
 * surfaced eight findings that three earlier hand-rolled censuses had all
 * missed — including two, `exams` and `examSchedule`, that
 * `scripts/sec4_read_arm_census.php` had positively CLEARED as "already
 * correct".
 *
 * A static tool that contradicts an earlier static tool settles nothing. This
 * suite is the arbiter: it measures each finding on the emulator against the
 * real deployed rules.
 *
 * ── THE FOUR SHAPES UNDER TEST ────────────────────────────────────────────
 *
 *   A. isStaff() || (isSameSchool() && ...)        exams, examSchedule
 *      The SEC-4 short-circuit, reached without the helper.
 *
 *   B. isAuth()                                     substitutes, timetableSettings
 *      No scoping whatsoever on read.
 *
 *   C. isStaff() && isSameSchoolWrite()  on DELETE  academicAuditLog, marks
 *      `request.resource` is NULL on delete. Does Firestore ERROR (deny) or
 *      does the arm somehow pass? This is measured, not assumed — the answer
 *      decides whether it is a security finding or a functional one.
 *
 *   D. isStaff() && (isSameSchoolWrite() || isSameSchool())  on CREATE
 *      `resource` is ALWAYS null on create, so `isSameSchool()`'s leading
 *      `resource == null ||` is unconditionally true. Does the tenant check
 *      evaporate, leaving bare isStaff()?
 */

const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');

const PROJECT_ID = 'zenxii-rules-test';
const SCHOOL_A   = 'SCH_TENANT_AAA';
const SCHOOL_B   = 'SCH_TENANT_BBB';

const RULES_PATH = process.env.SEC5_LIVE_RULES || '';
const HAVE_RULES = RULES_PATH && fs.existsSync(RULES_PATH);

jest.setTimeout(60000);

let env;
const DBS = {};

beforeAll(async () => {
  if (!HAVE_RULES) return;
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });

  // Built ONCE, before the first withSecurityRulesDisabled() seed.
  DBS.teacherA = env.authenticatedContext('STA0001', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Teacher',
  }).firestore();
  DBS.adminA = env.authenticatedContext('admin_A', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Admin',
  }).firestore();
  DBS.parentA = env.authenticatedContext('STU0001', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Parent',
  }).firestore();
});

afterAll(async () => { if (env) await env.cleanup(); });

async function seedTenants() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL_A, SCHOOL_B]) {
      await db.collection('schools').doc(s).set({ schoolId: s });
      await db.collection('schoolControl').doc(s).set({ lifecycle: { state: 'active' } });
    }
  });
}

async function seedDoc(col, docId, data) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    await ctx.firestore().collection(col).doc(docId).set(data);
  });
}

const maybe = HAVE_RULES ? describe : describe.skip;

maybe('SEC-5 · authorization-graph findings (regression proof — closed 2026-08-21)', () => {
  beforeEach(async () => {
    await env.clearFirestore();
    await seedTenants();
  });

  // ══ SHAPE A — the SEC-4 short-circuit without the helper ════════════════
  //
  // `sec4_read_arm_census.php` CLEARED these as "already correct" because it
  // tested for the PRESENCE of isStaff() and isSameSchool() rather than their
  // STRUCTURE. Presence-not-structure is the same error that produced the
  // withdrawn "45 arms" figure.

  describe('shape A — isStaff() || (isSameSchool() && ...)', () => {
    test('exams: a teacher of School A is REFUSED School B\'s exam (closed by SEC-6)', async () => {
      const id = `${SCHOOL_B}_EX1`;
      await seedDoc('exams', id, { schoolId: SCHOOL_B, status: 'Draft', name: 'Secret Exam' });

      await assertFails(DBS.teacherA.collection('exams').doc(id).get());
    });

    test('examSchedule: a teacher of School A is REFUSED School B\'s datesheet (closed by SEC-6)', async () => {
      const id = `${SCHOOL_B}_ES1`;
      await seedDoc('examSchedule', id, { schoolId: SCHOOL_B, examId: 'EX1', subject: 'Maths' });

      await assertFails(DBS.teacherA.collection('examSchedule').doc(id).get());
    });

    /**
     * CONTROL. A parent falls to the second disjunct, where the document's
     * school IS compared. If this ever passes, the finding is broader than
     * described and the analysis needs redoing.
     */
    test('exams: a PARENT of School A is correctly refused School B\'s exam', async () => {
      const id = `${SCHOOL_B}_EX2`;
      await seedDoc('exams', id, { schoolId: SCHOOL_B, status: 'Published' });

      await assertFails(DBS.parentA.collection('exams').doc(id).get());
    });

    test('exams: a teacher CAN still read their OWN school\'s exam (must survive any fix)', async () => {
      const id = `${SCHOOL_A}_EX3`;
      await seedDoc('exams', id, { schoolId: SCHOOL_A, status: 'Draft' });

      await assertSucceeds(DBS.teacherA.collection('exams').doc(id).get());
    });
  });

  // ══ SHAPE B — bare isAuth() on read ═════════════════════════════════════

  describe('shape B — allow read: if isAuth()', () => {
    ['substitutes', 'timetableSettings'].forEach((col) => {
      test(`${col}: a teacher of School A is REFUSED School B's document (closed by SEC-6)`, async () => {
        const id = `${SCHOOL_B}_X1`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, value: 'secret' });

        await assertFails(DBS.teacherA.collection(col).doc(id).get());
      });

      /**
       * Worse than the staff cases: `isAuth()` does not exclude parents, so a
       * PARENT of another tenant reads it too. That is a wider population than
       * any other finding in this programme.
       */
      test(`${col}: a PARENT of School A is ALSO REFUSED School B's document`, async () => {
        const id = `${SCHOOL_B}_X2`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, value: 'secret' });

        await assertFails(DBS.parentA.collection(col).doc(id).get());
      });

      /**
       * The legitimate workflow. This MUST stay assertSucceeds — a bulk flip of
       * the exposure assertions briefly caught this one too, which would have
       * turned a broken own-school read into a passing test. The whole point of
       * tightening a boundary is that the inside of it still works.
       */
      test(`${col}: own-school read still WORKS (must survive the fix)`, async () => {
        const id = `${SCHOOL_A}_X3`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, value: 'ours' });

        await assertSucceeds(DBS.teacherA.collection(col).doc(id).get());
      });
    });
  });

  // ══ SHAPE C — isSameSchoolWrite() on DELETE ═════════════════════════════
  //
  // MEASURED, NOT REASONED. `request.resource` is null on delete, so
  // `request.resource.data.schoolId` dereferences null. Firestore may treat
  // that as an evaluation error (deny) — which would make this a FUNCTIONAL
  // defect (same-school delete broken) rather than a security hole.
  //
  // The authz graph currently classes it `staff-any-tenant`, i.e. OPEN. These
  // tests decide which is right, and the classifier is corrected to match.

  describe('shape C — isStaff() && isSameSchoolWrite() on delete', () => {
    test('academicAuditLog: same-school delete by staff', async () => {
      const id = `${SCHOOL_A}_AL1`;
      await seedDoc('academicAuditLog', id, { schoolId: SCHOOL_A, action: 'x' });

      // `request.resource` is null on delete. If Firestore errors, this denies.
      await assertFails(DBS.teacherA.collection('academicAuditLog').doc(id).delete());
    });

    test('academicAuditLog: cross-school delete by staff is refused', async () => {
      const id = `${SCHOOL_B}_AL2`;
      await seedDoc('academicAuditLog', id, { schoolId: SCHOOL_B, action: 'x' });

      await assertFails(DBS.teacherA.collection('academicAuditLog').doc(id).delete());
    });

    test('academicAuditLog: same-school CREATE still works (the arm is fine for create)', async () => {
      await assertSucceeds(
        DBS.teacherA.collection('academicAuditLog').doc(`${SCHOOL_A}_AL3`)
          .set({ schoolId: SCHOOL_A, action: 'created' })
      );
    });
  });

  // ══ SHAPE D — resource==null fail-open on CREATE ════════════════════════

  describe('shape D — isStaff() && (isSameSchoolWrite() || isSameSchool()) on create', () => {
    /**
     * ORIGINALLY MEASURED OPEN: on CREATE `resource` is always null, so
     * `isSameSchool()`'s leading `resource == null ||` was unconditionally true
     * and the disjunction collapsed — leaving bare `isStaff()`, which let a
     * teacher of School A mint a document owned by School B.
     *
     * SEC-6 removed the fail-open branch, so both now refuse. Flipped to
     * assertFails; these guard the fix.
     */
    test('subjectPlanProgress: a teacher of School A is REFUSED creating a School B document', async () => {
      await assertFails(
        DBS.teacherA.collection('subjectPlanProgress').doc(`${SCHOOL_B}_SP1`)
          .set({ schoolId: SCHOOL_B, planId: 'P1', progress: 50 })
      );
    });

    test('dailyTeacherMonitoring: a teacher of School A is REFUSED creating a School B document', async () => {
      await assertFails(
        DBS.teacherA.collection('dailyTeacherMonitoring').doc(`${SCHOOL_B}_DM1`)
          .set({ schoolId: SCHOOL_B, teacherId: 'STA9999', note: 'x' })
      );
    });

    test('subjectPlanProgress: own-school create works (must survive any fix)', async () => {
      await assertSucceeds(
        DBS.teacherA.collection('subjectPlanProgress').doc(`${SCHOOL_A}_SP2`)
          .set({ schoolId: SCHOOL_A, planId: 'P1', progress: 10 })
      );
    });
  });

  // ══ THE SEC-4 HELPER SET — confirm the graph agrees with SEC-4 ══════════

  describe('helper set (cross-check against SEC-4)', () => {
    ['feeDemands', 'feeReceipts', 'marks', 'results'].forEach((col) => {
      test(`${col}: cross-tenant staff read is REFUSED (closed by SEC-6)`, async () => {
        const id = `${SCHOOL_B}_H1`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, studentId: 'STU9999', amount: 999 });

        await assertFails(DBS.teacherA.collection(col).doc(id).get());
      });
    });
  });
});
