/**
 * SEC-3 WAVE 4 — ACADEMIC STRUCTURE AND SCHEDULING.
 *
 * Nine collections, TWO different boundaries, chosen from the real write
 * contracts rather than applied uniformly:
 *
 *   SERVER-ONLY (8)   sections, subjects, subjectAssignments, exams,
 *                     examSchedule, timetables, calendarEvents, appraisals
 *                     — zero client writes on both apps, so `isAdmin()` (or
 *                     `isStaff()`, for timetables) authorises a client
 *                     population that does not exist.
 *
 *   DELETE-ONLY (1)   leaveApplications — BOTH apps legitimately write it and
 *                     its create/update arms are already among the best-scoped
 *                     in the ruleset (tenant + applicant ownership + status
 *                     transition + immutable-field checks). ONLY
 *                     `allow delete: if isAdmin();` lacked tenant scoping.
 *
 * Denying leaveApplications wholesale would have broken leave application on
 * both apps. That is why the boundary is decided per collection.
 *
 * ── THE DIFFERENTIAL ──────────────────────────────────────────────────────
 * This suite is only meaningful if it FAILS against the unfixed live ruleset.
 * Run it both ways:
 *   SEC3_W4_RULES=<artifact>  -> expect all pass
 *   SEC3_W4_RULES=<live pull> -> expect the cross-tenant cases to fail
 * A suite that passes against both proves nothing.
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

const SERVER_ONLY = [
  'sections', 'subjects', 'subjectAssignments', 'exams',
  'examSchedule', 'timetables', 'calendarEvents', 'appraisals',
];

const RULES_PATH = process.env.SEC3_W4_RULES || '';
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
  DBS.adminA   = env.authenticatedContext('admin_A',   { school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Admin' }).firestore();
  DBS.teacherA = env.authenticatedContext('STA0001',   { school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Teacher' }).firestore();
  DBS.teacherA2= env.authenticatedContext('STA0002',   { school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Teacher' }).firestore();
  DBS.adminB   = env.authenticatedContext('admin_B',   { school_id: SCHOOL_B, schoolId: SCHOOL_B, role: 'Admin' }).firestore();
  DBS.studentA = env.authenticatedContext('STU0001',   { school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Student' }).firestore();
  DBS.anon     = env.unauthenticatedContext().firestore();
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

maybe('SEC-3 wave 4', () => {
  beforeEach(async () => {
    await env.clearFirestore();
    await seedTenants();
  });

  // ══ SERVER-ONLY — every verb asserted independently ═════════════════════

  describe('server-only collections', () => {
    SERVER_ONLY.forEach((col) => {
      test(`${col}: admin CANNOT create in their own school`, async () => {
        await assertFails(DBS.adminA.collection(col).doc(`${SCHOOL_A}_N`).set({ schoolId: SCHOOL_A, v: 1 }));
      });

      test(`${col}: admin CANNOT update in their own school`, async () => {
        const id = `${SCHOOL_A}_E`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, v: 1 });
        await assertFails(DBS.adminA.collection(col).doc(id).update({ v: 2 }));
      });

      test(`${col}: admin CANNOT delete in their own school`, async () => {
        const id = `${SCHOOL_A}_E`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, v: 1 });
        await assertFails(DBS.adminA.collection(col).doc(id).delete());
      });

      test(`${col}: admin of School A CANNOT write School B's document`, async () => {
        const id = `${SCHOOL_B}_E`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, v: 1 });
        await assertFails(DBS.adminA.collection(col).doc(id).update({ v: 999 }));
      });

      test(`${col}: admin of School A CANNOT delete School B's document`, async () => {
        const id = `${SCHOOL_B}_E`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, v: 1 });
        await assertFails(DBS.adminA.collection(col).doc(id).delete());
      });

      test(`${col}: a teacher CANNOT write`, async () => {
        await assertFails(DBS.teacherA.collection(col).doc(`${SCHOOL_A}_T`).set({ schoolId: SCHOOL_A, v: 1 }));
      });

      test(`${col}: an unauthenticated client CANNOT write`, async () => {
        await assertFails(DBS.anon.collection(col).doc(`${SCHOOL_A}_U`).set({ schoolId: SCHOOL_A, v: 1 }));
      });
    });

    // Reads are untouched by this change, and must be proven so.
    ['sections', 'subjects', 'subjectAssignments', 'timetables', 'calendarEvents'].forEach((col) => {
      test(`${col}: same-school read still works`, async () => {
        const id = `${SCHOOL_A}_R`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, v: 1 });
        await assertSucceeds(DBS.teacherA.collection(col).doc(id).get());
      });
    });

    test('appraisals: own appraisal read still works', async () => {
      const id = `${SCHOOL_A}_STA0001_2026`;
      await seedDoc('appraisals', id, { schoolId: SCHOOL_A, staffId: 'STA0001', rating: 4 });
      await assertSucceeds(DBS.teacherA.collection('appraisals').doc(id).get());
    });
  });

  // ══ leaveApplications — DELETE tightened, WORKFLOW PRESERVED ════════════
  //
  // The whole risk of this collection is that leave application breaks on both
  // apps. Each surviving arm is exercised, not assumed.

  describe('leaveApplications (delete-only fix)', () => {
    const leaveDoc = (school, applicantId, type, status) => ({
      schoolId: school,
      applicantId,
      applicantType: type,
      status,
      startDate: '2026-09-01',
      endDate: '2026-09-02',
      attendanceStamped: false,
    });

    test('a staff member CAN still apply for their own leave', async () => {
      await assertSucceeds(
        DBS.teacherA.collection('leaveApplications').doc(`${SCHOOL_A}_L1`)
          .set(leaveDoc(SCHOOL_A, 'STA0001', 'staff', 'pending'))
      );
    });

    test('a student CAN still apply for their own leave', async () => {
      await assertSucceeds(
        DBS.studentA.collection('leaveApplications').doc(`${SCHOOL_A}_L2`)
          .set(leaveDoc(SCHOOL_A, 'STU0001', 'student', 'pending'))
      );
    });

    test('a staff member CAN still approve a student\'s pending leave', async () => {
      const id = `${SCHOOL_A}_L3`;
      await seedDoc('leaveApplications', id, leaveDoc(SCHOOL_A, 'STU0001', 'student', 'pending'));

      await assertSucceeds(
        DBS.teacherA.collection('leaveApplications').doc(id)
          .set({ ...leaveDoc(SCHOOL_A, 'STU0001', 'student', 'approved') })
      );
    });

    test('an applicant CAN still cancel their own pending leave', async () => {
      const id = `${SCHOOL_A}_L4`;
      await seedDoc('leaveApplications', id, leaveDoc(SCHOOL_A, 'STA0001', 'staff', 'pending'));

      await assertSucceeds(
        DBS.teacherA.collection('leaveApplications').doc(id)
          .set({ ...leaveDoc(SCHOOL_A, 'STA0001', 'staff', 'cancelled') })
      );
    });

    test('an applicant still CANNOT approve their own leave', async () => {
      const id = `${SCHOOL_A}_L5`;
      await seedDoc('leaveApplications', id, leaveDoc(SCHOOL_A, 'STA0001', 'staff', 'pending'));

      await assertFails(
        DBS.teacherA.collection('leaveApplications').doc(id)
          .set({ ...leaveDoc(SCHOOL_A, 'STA0001', 'staff', 'approved') })
      );
    });

    // ── the actual fix ──────────────────────────────────────────────────

    test('an admin CAN still delete leave in their OWN school', async () => {
      const id = `${SCHOOL_A}_L6`;
      await seedDoc('leaveApplications', id, leaveDoc(SCHOOL_A, 'STA0001', 'staff', 'pending'));

      await assertSucceeds(DBS.adminA.collection('leaveApplications').doc(id).delete());
    });

    test('an admin CANNOT delete leave in ANOTHER school', async () => {
      const id = `${SCHOOL_B}_L7`;
      await seedDoc('leaveApplications', id, leaveDoc(SCHOOL_B, 'STA9999', 'staff', 'pending'));

      await assertFails(DBS.adminA.collection('leaveApplications').doc(id).delete());
    });

    /** The strict predicate must fail closed on a missing document. */
    test('deleting a NON-EXISTENT leave application fails closed', async () => {
      await assertFails(DBS.adminA.collection('leaveApplications').doc(`${SCHOOL_A}_NOPE`).delete());
    });

    /** And must not accept the legacy schoolCode key that D7 removed. */
    test('a schoolCode-only leave document cannot be deleted', async () => {
      const id = `${SCHOOL_A}_L8`;
      await seedDoc('leaveApplications', id, {
        schoolCode: SCHOOL_A, applicantId: 'STA0001', applicantType: 'staff', status: 'pending',
      });

      await assertFails(DBS.adminA.collection('leaveApplications').doc(id).delete());
    });

    test('a non-admin staff member still CANNOT delete leave', async () => {
      const id = `${SCHOOL_A}_L9`;
      await seedDoc('leaveApplications', id, leaveDoc(SCHOOL_A, 'STA0001', 'staff', 'pending'));

      await assertFails(DBS.teacherA.collection('leaveApplications').doc(id).delete());
    });
  });
});
