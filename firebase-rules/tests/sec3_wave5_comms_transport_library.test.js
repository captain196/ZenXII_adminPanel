/**
 * SEC-3 WAVE 5 — COMMUNICATION, TRANSPORT, LIBRARY, PTM.
 *
 * Sixteen collections, THREE boundaries, one per real write contract:
 *
 *   SERVER-ONLY (4)    routes, vehicles, studentRoutes, libraryBooks
 *                      — zero client writes on both apps.
 *
 *   DELETE-ONLY (10)   circulars, notices, events, ptmEvents, ptmRsvps,
 *                      ptmCounters, libraryFines, conversations, messages,
 *                      pushRequests
 *                      — create/update are ALREADY correctly scoped (tenant +
 *                      author/owner). Only the delete arm leaked. This is the
 *                      exact asymmetry that appears when `allow write` is
 *                      reasoned about as a single operation: nine of these ten
 *                      look "already fixed" until each verb is checked alone.
 *
 *   SPLIT (2)          notifications, libraryIssues
 *                      — real client writes, so create/update and delete are
 *                      emitted as separate arms over request.resource and
 *                      resource respectively.
 *
 * `pushRequests` keeps `isStaff()` on delete rather than being promoted to
 * `isAdmin()`. This wave tightens TENANCY; silently re-grading who may act
 * would be a second, undeclared change.
 *
 * ── THE DIFFERENTIAL ──────────────────────────────────────────────────────
 * Run against the artifact (all pass) AND the live pull (cross-tenant cases
 * fail). A suite that is green against both proves nothing.
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

const SERVER_ONLY = ['routes', 'vehicles', 'studentRoutes', 'libraryBooks'];

// Delete-only fixes. `actor` is who may delete AFTER the fix — unchanged from
// live in every case.
const DELETE_ONLY = [
  { col: 'circulars',     actor: 'admin' },
  { col: 'notices',       actor: 'admin' },
  { col: 'events',        actor: 'admin' },
  { col: 'ptmEvents',     actor: 'admin' },
  { col: 'ptmRsvps',      actor: 'admin' },
  { col: 'ptmCounters',   actor: 'admin' },
  { col: 'libraryFines',  actor: 'admin' },
  { col: 'conversations', actor: 'admin' },
  { col: 'messages',      actor: 'admin' },
  { col: 'pushRequests',  actor: 'staff' },
];

const RULES_PATH = process.env.SEC3_W5_RULES || '';
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

  DBS.adminA   = env.authenticatedContext('admin_A', { school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Admin' }).firestore();
  DBS.teacherA = env.authenticatedContext('STA0001', { school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Teacher' }).firestore();
  DBS.studentA = env.authenticatedContext('STU0001', { school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Student' }).firestore();
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

maybe('SEC-3 wave 5', () => {
  beforeEach(async () => {
    await env.clearFirestore();
    await seedTenants();
  });

  // ══ SERVER-ONLY ═════════════════════════════════════════════════════════

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

      test(`${col}: a teacher CANNOT write`, async () => {
        await assertFails(DBS.teacherA.collection(col).doc(`${SCHOOL_A}_T`).set({ schoolId: SCHOOL_A, v: 1 }));
      });

      test(`${col}: same-school read still works`, async () => {
        const id = `${SCHOOL_A}_R`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, v: 1 });
        await assertSucceeds(DBS.teacherA.collection(col).doc(id).get());
      });
    });
  });

  // ══ DELETE-ONLY FIXES ═══════════════════════════════════════════════════
  //
  // Four cases per collection, because a delete predicate can fail in four
  // distinct ways and only one of them is "cross-tenant".

  describe('delete-only fixes', () => {
    DELETE_ONLY.forEach(({ col, actor }) => {
      const who = () => (actor === 'admin' ? DBS.adminA : DBS.teacherA);

      test(`${col}: ${actor} CAN still delete in their OWN school`, async () => {
        const id = `${SCHOOL_A}_D`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, v: 1 });
        await assertSucceeds(who().collection(col).doc(id).delete());
      });

      test(`${col}: ${actor} CANNOT delete in ANOTHER school`, async () => {
        const id = `${SCHOOL_B}_D`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, v: 1 });
        await assertFails(who().collection(col).doc(id).delete());
      });

      test(`${col}: deleting a NON-EXISTENT document fails closed`, async () => {
        await assertFails(who().collection(col).doc(`${SCHOOL_A}_NOPE`).delete());
      });

      test(`${col}: a schoolCode-only document cannot be deleted`, async () => {
        const id = `${SCHOOL_A}_LEG`;
        await seedDoc(col, id, { schoolCode: SCHOOL_A, v: 1 });
        await assertFails(who().collection(col).doc(id).delete());
      });
    });
  });

  // ══ WORKFLOWS THAT MUST SURVIVE ═════════════════════════════════════════
  //
  // Nine of the ten delete-only collections carry real client create/update
  // traffic. The delete fix must not disturb it.

  describe('preserved client workflows', () => {
    test('circulars: a staff author CAN still create', async () => {
      await assertSucceeds(
        DBS.teacherA.collection('circulars').doc(`${SCHOOL_A}_C1`)
          .set({ schoolId: SCHOOL_A, authorId: 'STA0001', title: 'Notice' })
      );
    });

    test('circulars: a staff author CAN still update their OWN circular', async () => {
      const id = `${SCHOOL_A}_C2`;
      await seedDoc('circulars', id, { schoolId: SCHOOL_A, authorId: 'STA0001', title: 'A' });
      await assertSucceeds(
        DBS.teacherA.collection('circulars').doc(id)
          .set({ schoolId: SCHOOL_A, authorId: 'STA0001', title: 'B' })
      );
    });

    test('ptmRsvps: a parent CAN still create their own RSVP', async () => {
      await assertSucceeds(
        DBS.studentA.collection('ptmRsvps').doc(`${SCHOOL_A}_R1`)
          .set({ schoolId: SCHOOL_A, studentId: 'STU0001', ptmEventId: 'E1', status: 'applied' })
      );
    });

    test('ptmRsvps: a parent CANNOT create an RSVP for another student', async () => {
      await assertFails(
        DBS.studentA.collection('ptmRsvps').doc(`${SCHOOL_A}_R2`)
          .set({ schoolId: SCHOOL_A, studentId: 'STU0002', ptmEventId: 'E1', status: 'applied' })
      );
    });

    test('ptmCounters: the queue counter CAN still be bumped in-tenant', async () => {
      await assertSucceeds(
        DBS.studentA.collection('ptmCounters').doc(`${SCHOOL_A}_E1`)
          .set({ schoolId: SCHOOL_A, nextQueue: 3 })
      );
    });
  });

  // ══ SPLIT ARMS — notifications, libraryIssues ═══════════════════════════

  describe('notifications (split arms)', () => {
    test('staff CAN still mark a notification read in their own school', async () => {
      const id = `${SCHOOL_A}_N1`;
      await seedDoc('notifications', id, { schoolId: SCHOOL_A, userId: 'STA0001', read: false });
      await assertSucceeds(DBS.teacherA.collection('notifications').doc(id).update({ read: true }));
    });

    test('staff CANNOT mark a notification read in ANOTHER school', async () => {
      const id = `${SCHOOL_B}_N2`;
      await seedDoc('notifications', id, { schoolId: SCHOOL_B, userId: 'STA9999', read: false });
      await assertFails(DBS.teacherA.collection('notifications').doc(id).update({ read: true }));
    });

    test('staff CANNOT re-home a notification to another school', async () => {
      const id = `${SCHOOL_A}_N3`;
      await seedDoc('notifications', id, { schoolId: SCHOOL_A, userId: 'STA0001', read: false });
      await assertFails(DBS.teacherA.collection('notifications').doc(id).update({ schoolId: SCHOOL_B }));
    });

    test('staff CANNOT create a notification with no schoolId', async () => {
      await assertFails(
        DBS.teacherA.collection('notifications').doc(`${SCHOOL_A}_N4`).set({ userId: 'STA0001', read: false })
      );
    });

    test('staff CAN delete a notification in their own school', async () => {
      const id = `${SCHOOL_A}_N5`;
      await seedDoc('notifications', id, { schoolId: SCHOOL_A, userId: 'STA0001', read: false });
      await assertSucceeds(DBS.teacherA.collection('notifications').doc(id).delete());
    });

    test('staff CANNOT delete a notification in another school', async () => {
      const id = `${SCHOOL_B}_N6`;
      await seedDoc('notifications', id, { schoolId: SCHOOL_B, userId: 'STA9999', read: false });
      await assertFails(DBS.teacherA.collection('notifications').doc(id).delete());
    });

    /**
     * A SEPARATE LIVE BUG, DELIBERATELY PRESERVED — SEC3-NOTIF-1.
     *
     * The Parent app calls `markNotificationRead()` -> update {read: true},
     * and notification documents carry a `userId` recipient field. But the
     * write arm is `isStaff()`, and `isStaff()` explicitly excludes
     * Student/Parent/Guardian. So a parent marking their own notification read
     * is DENIED in production today, and the app swallows it as
     * `Result.failure`.
     *
     * This wave keeps `isStaff()` — it tightens TENANCY only. Widening the
     * actor to the recipient (`resource.data.userId == request.auth.uid`)
     * would FIX the bug but would also GRANT access that does not exist today,
     * and that is a product decision, not a security tightening. It is filed
     * rather than taken.
     *
     * This test therefore passes against BOTH the live ruleset and the
     * artifact, which is exactly what "preserved, not changed" should look
     * like. When the gap is closed it must flip to `assertSucceeds`.
     */
    test('a PARENT still CANNOT mark their own notification read (SEC3-NOTIF-1, unchanged)', async () => {
      const id = `${SCHOOL_A}_N8`;
      await seedDoc('notifications', id, { schoolId: SCHOOL_A, userId: 'STU0001', read: false });

      await assertFails(DBS.studentA.collection('notifications').doc(id).update({ read: true }));
    });

    test('an unauthenticated client CANNOT write a notification', async () => {
      await assertFails(
        DBS.anon.collection('notifications').doc(`${SCHOOL_A}_N7`).set({ schoolId: SCHOOL_A, read: true })
      );
    });
  });

  describe('libraryIssues (split arms)', () => {
    const issue = (school) => ({
      schoolId: school, bookId: 'B1', borrowerId: 'STU0001',
      borrowerName: 'X', issuedBy: 'STA0001', status: 'issued',
    });

    test('a teacher CAN still issue a book (issueBook create)', async () => {
      await assertSucceeds(
        DBS.teacherA.collection('libraryIssues').doc(`${SCHOOL_A}_B1_1`).set(issue(SCHOOL_A))
      );
    });

    test('a teacher CAN still return a book (returnBook update)', async () => {
      const id = `${SCHOOL_A}_B1_2`;
      await seedDoc('libraryIssues', id, issue(SCHOOL_A));
      await assertSucceeds(
        DBS.teacherA.collection('libraryIssues').doc(id).update({ status: 'returned' })
      );
    });

    test('a teacher CANNOT issue a book into ANOTHER school', async () => {
      await assertFails(
        DBS.teacherA.collection('libraryIssues').doc(`${SCHOOL_B}_B1_3`).set(issue(SCHOOL_B))
      );
    });

    test('a teacher CANNOT update another school\'s issue', async () => {
      const id = `${SCHOOL_B}_B1_4`;
      await seedDoc('libraryIssues', id, issue(SCHOOL_B));
      await assertFails(DBS.teacherA.collection('libraryIssues').doc(id).update({ status: 'returned' }));
    });

    test('a teacher CANNOT create an issue with no schoolId', async () => {
      const bad = issue(SCHOOL_A);
      delete bad.schoolId;
      await assertFails(DBS.teacherA.collection('libraryIssues').doc(`${SCHOOL_A}_B1_5`).set(bad));
    });

    test('a teacher CAN delete an issue in their own school', async () => {
      const id = `${SCHOOL_A}_B1_6`;
      await seedDoc('libraryIssues', id, issue(SCHOOL_A));
      await assertSucceeds(DBS.teacherA.collection('libraryIssues').doc(id).delete());
    });

    test('a teacher CANNOT delete another school\'s issue', async () => {
      const id = `${SCHOOL_B}_B1_7`;
      await seedDoc('libraryIssues', id, issue(SCHOOL_B));
      await assertFails(DBS.teacherA.collection('libraryIssues').doc(id).delete());
    });
  });
});
