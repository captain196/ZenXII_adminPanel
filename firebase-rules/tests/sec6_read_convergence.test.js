/**
 * SEC-6 — READ/TENANT CONVERGENCE.
 *
 * Closes SEC-4 (the `isStaffOrOwnStudent()` short-circuit, 13 collections) and
 * SEC-5 shapes B, C, D and F. Design and per-collection reasoning:
 * `.claude/zenx/SEC4_SEC5_DESIGN_MATRIX.md`.
 *
 * ── RUN IT BOTH WAYS ──────────────────────────────────────────────────────
 *   SEC6_RULES=<artifact>   -> every test passes
 *   SEC6_RULES=<live pull>  -> the exposure tests FAIL
 *
 * A suite green against both would prove only that it never exercised the
 * protection. The failure count against the live pull is the actual evidence.
 *
 * ── WHAT MUST NOT BREAK ───────────────────────────────────────────────────
 * Every legitimate workflow that flows through these arms is asserted
 * explicitly: staff reading their own school, a parent reading their own
 * child's record, parents gated to Published exams, both apps' circular-read
 * receipts, and the Parent app's own-child fee/marks reads. A security fix that
 * denies a valid school workflow is a regression wearing a fix's clothes.
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

const STU_A   = 'STU0001';   // parent/student of School A
const STU_A2  = 'STU0002';   // a DIFFERENT student of School A
const STAFF_A = 'STA0001';

/** The 13 collections reached through `isStaffOrOwnStudent()`. */
const HELPER_COLLECTIONS = [
  'feeDemands', 'feeReceipts', 'feeDefaulters', 'feeCarryForward',
  'feeReceiptAllocations', 'feeReminderLog', 'feeOnlineOrders',
  'feeRefunds', 'feeRefundVouchers', 'studentDiscounts',
  'scholarshipAwards', 'marks', 'results',
];

const RULES_PATH = process.env.SEC6_RULES || '';
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

  // Built ONCE, before the first withSecurityRulesDisabled() seed:
  // RulesTestContextImpl.firestore() re-invokes useEmulator() on every call and
  // throws once the instance has started.
  DBS.staffA = env.authenticatedContext(STAFF_A, {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Teacher',
  }).firestore();
  DBS.adminA = env.authenticatedContext('admin_A', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Admin',
  }).firestore();
  DBS.staffB = env.authenticatedContext('STA9999', {
    school_id: SCHOOL_B, schoolId: SCHOOL_B, role: 'Teacher',
  }).firestore();
  // uid == studentId, per the documented `{schoolId}_{studentId}` contract.
  DBS.parentA = env.authenticatedContext(STU_A, {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Parent',
    student_id: STU_A, student_ids: [STU_A],
  }).firestore();
  DBS.anon = env.unauthenticatedContext().firestore();
});

afterAll(async () => { if (env) await env.cleanup(); });

async function seedTenants() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL_A, SCHOOL_B]) {
      await db.collection('schools').doc(s).set({ schoolId: s });
      // tenantActive() reads schoolControl/{id}.lifecycle.state — NOT `status`.
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

maybe('SEC-6 · read/tenant convergence', () => {
  beforeEach(async () => {
    await env.clearFirestore();
    await seedTenants();
  });

  // ══ SHAPE A — the helper, 13 collections ════════════════════════════════

  describe('shape A · isStaffOrOwnStudent()', () => {
    HELPER_COLLECTIONS.forEach((col) => {
      test(`${col}: cross-school staff read is REFUSED`, async () => {
        const id = `${SCHOOL_B}_${STU_A}`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, studentId: 'STU9999', amount: 999 });

        await assertFails(DBS.staffA.collection(col).doc(id).get());
      });

      test(`${col}: cross-school ADMIN read is REFUSED`, async () => {
        const id = `${SCHOOL_B}_${STU_A}`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, studentId: 'STU9999', amount: 999 });

        await assertFails(DBS.adminA.collection(col).doc(id).get());
      });

      // ── the workflows that must survive ────────────────────────────────

      test(`${col}: own-school staff read still WORKS`, async () => {
        const id = `${SCHOOL_A}_${STU_A}`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, studentId: STU_A, amount: 100 });

        await assertSucceeds(DBS.staffA.collection(col).doc(id).get());
      });

      test(`${col}: a parent reading their OWN child still WORKS`, async () => {
        const id = `${SCHOOL_A}_${STU_A}`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, studentId: STU_A, amount: 100 });

        await assertSucceeds(DBS.parentA.collection(col).doc(id).get());
      });

      test(`${col}: a parent reading ANOTHER child is still REFUSED`, async () => {
        const id = `${SCHOOL_A}_${STU_A2}`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, studentId: STU_A2, amount: 100 });

        await assertFails(DBS.parentA.collection(col).doc(id).get());
      });

      // ── forged / missing tenant markers ────────────────────────────────

      test(`${col}: a document with NO schoolId is not readable cross-school`, async () => {
        const id = `${SCHOOL_B}_NOSCHOOL`;
        await seedDoc(col, id, { studentId: 'STU9999', amount: 5 });

        await assertFails(DBS.staffA.collection(col).doc(id).get());
      });

      /**
       * `isSameSchool()` retains a legacy `resource.data.schoolCode` arm. On the
       * READ path `resource` is the STORED document, not attacker-supplied, so
       * this is not forgeable by a client — but a document legitimately keyed by
       * the OTHER school's code must still not be readable here.
       */
      test(`${col}: a foreign schoolCode does not grant a read`, async () => {
        const id = `${SCHOOL_B}_LEGACY`;
        await seedDoc(col, id, { schoolCode: SCHOOL_B, studentId: 'STU9999', amount: 5 });

        await assertFails(DBS.staffA.collection(col).doc(id).get());
      });

      test(`${col}: an unauthenticated client cannot read`, async () => {
        const id = `${SCHOOL_A}_${STU_A}`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, studentId: STU_A, amount: 1 });

        await assertFails(DBS.anon.collection(col).doc(id).get());
      });

      /**
       * LIST must not bypass the boundary a GET enforces. A query is evaluated
       * per candidate document, so an unfiltered collection read must fail even
       * though each individual document would be refused.
       */
      test(`${col}: an unscoped LIST cannot bypass the boundary`, async () => {
        await seedDoc(col, `${SCHOOL_A}_${STU_A}`, { schoolId: SCHOOL_A, studentId: STU_A });
        await seedDoc(col, `${SCHOOL_B}_X`, { schoolId: SCHOOL_B, studentId: 'STU9999' });

        await assertFails(DBS.staffA.collection(col).get());
      });

      /**
       * ...but a correctly scoped query must still work, or the fix breaks
       * every list screen in both apps.
       */
      test(`${col}: a school-scoped LIST still WORKS`, async () => {
        await seedDoc(col, `${SCHOOL_A}_${STU_A}`, { schoolId: SCHOOL_A, studentId: STU_A });

        await assertSucceeds(
          DBS.staffA.collection(col).where('schoolId', '==', SCHOOL_A).get()
        );
      });

      /**
       * WRITE SEMANTICS MUST BE UNTOUCHED — this artifact edits reads only.
       *
       * The assertion is CROSS-SCHOOL refusal rather than "all client writes
       * fail". An earlier draft asserted the latter and failed on `marks`,
       * whose write arm is
       *     isStaff() && isSameSchoolWrite() && hasCapability('Results')
       * and `hasCapability()` is DELIBERATELY fail-open when the caller has no
       * `staffCapabilities/{uid}` document ("pre-rollout", per its own comment).
       * A same-school staff write therefore succeeds today, by design.
       *
       * That fail-open is keyed on `request.auth.uid`, so it is per-USER, not
       * per-tenant — one school's RBAC rollout cannot affect another's. It is a
       * deliberate rollout decision, not a tenant-isolation defect, and this
       * artifact does not touch it.
       */
      test(`${col}: cross-school client write is still REFUSED`, async () => {
        await assertFails(
          DBS.staffA.collection(col).doc(`${SCHOOL_B}_NEW`).set({ schoolId: SCHOOL_B, amount: 1 })
        );
      });
    });
  });

  // ══ SHAPE B — exams / examSchedule ══════════════════════════════════════

  describe('shape B · exams and examSchedule', () => {
    test('exams: cross-school staff read is REFUSED', async () => {
      const id = `${SCHOOL_B}_EX1`;
      await seedDoc('exams', id, { schoolId: SCHOOL_B, status: 'Draft' });

      await assertFails(DBS.staffA.collection('exams').doc(id).get());
    });

    test('exams: own-school staff read of a DRAFT still WORKS', async () => {
      const id = `${SCHOOL_A}_EX2`;
      await seedDoc('exams', id, { schoolId: SCHOOL_A, status: 'Draft' });

      await assertSucceeds(DBS.staffA.collection('exams').doc(id).get());
    });

    /** Parent gating must be preserved EXACTLY — published visible... */
    test('exams: a parent CAN still read a Published exam in their school', async () => {
      const id = `${SCHOOL_A}_EX3`;
      await seedDoc('exams', id, { schoolId: SCHOOL_A, status: 'Published' });

      await assertSucceeds(DBS.parentA.collection('exams').doc(id).get());
    });

    /** ...and drafts still hidden. */
    test('exams: a parent still CANNOT read a Draft exam', async () => {
      const id = `${SCHOOL_A}_EX4`;
      await seedDoc('exams', id, { schoolId: SCHOOL_A, status: 'Draft' });

      await assertFails(DBS.parentA.collection('exams').doc(id).get());
    });

    test('examSchedule: cross-school staff read is REFUSED', async () => {
      const id = `${SCHOOL_B}_ES1`;
      await seedDoc('examSchedule', id, { schoolId: SCHOOL_B, examId: 'EX1' });

      await assertFails(DBS.staffA.collection('examSchedule').doc(id).get());
    });

    test('examSchedule: own-school staff read still WORKS', async () => {
      const id = `${SCHOOL_A}_ES2`;
      await seedDoc('examSchedule', id, { schoolId: SCHOOL_A, examId: 'EX1' });

      await assertSucceeds(DBS.staffA.collection('examSchedule').doc(id).get());
    });

    test('exams: write semantics unchanged', async () => {
      await assertFails(
        DBS.staffA.collection('exams').doc(`${SCHOOL_A}_W`).set({ schoolId: SCHOOL_A })
      );
    });
  });

  // ══ SHAPE C — bare isAuth() reads ═══════════════════════════════════════

  describe('shape C · substitutes and timetableSettings', () => {
    ['substitutes', 'timetableSettings'].forEach((col) => {
      test(`${col}: cross-school staff read is REFUSED`, async () => {
        const id = `${SCHOOL_B}_X1`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, value: 'secret' });

        await assertFails(DBS.staffA.collection(col).doc(id).get());
      });

      /** The widest part of the old exposure: parents of other tenants. */
      test(`${col}: cross-school PARENT read is REFUSED`, async () => {
        const id = `${SCHOOL_B}_X2`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, value: 'secret' });

        await assertFails(DBS.parentA.collection(col).doc(id).get());
      });

      test(`${col}: own-school staff read still WORKS`, async () => {
        const id = `${SCHOOL_A}_X3`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, value: 'ours' });

        await assertSucceeds(DBS.staffA.collection(col).doc(id).get());
      });

      /** Both apps read `substitutes`; a role gate was deliberately NOT added. */
      test(`${col}: own-school PARENT read still WORKS`, async () => {
        const id = `${SCHOOL_A}_X4`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, value: 'ours' });

        await assertSucceeds(DBS.parentA.collection(col).doc(id).get());
      });
    });
  });

  // ══ SHAPE D — create fail-open ══════════════════════════════════════════

  describe('shape D · create fail-open', () => {
    ['subjectPlanProgress', 'dailyTeacherMonitoring'].forEach((col) => {
      test(`${col}: cross-school CREATE is REFUSED`, async () => {
        await assertFails(
          DBS.staffA.collection(col).doc(`${SCHOOL_B}_C1`).set({ schoolId: SCHOOL_B, v: 1 })
        );
      });

      test(`${col}: own-school create still WORKS`, async () => {
        await assertSucceeds(
          DBS.staffA.collection(col).doc(`${SCHOOL_A}_C2`).set({ schoolId: SCHOOL_A, v: 1 })
        );
      });

      test(`${col}: create with NO schoolId is REFUSED`, async () => {
        await assertFails(
          DBS.staffA.collection(col).doc(`${SCHOOL_A}_C3`).set({ v: 1 })
        );
      });

      test(`${col}: own-school update still WORKS`, async () => {
        const id = `${SCHOOL_A}_C4`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, v: 1 });

        await assertSucceeds(DBS.staffA.collection(col).doc(id).update({ v: 2 }));
      });

      test(`${col}: re-homing to another school is REFUSED`, async () => {
        const id = `${SCHOOL_A}_C5`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, v: 1 });

        await assertFails(DBS.staffA.collection(col).doc(id).update({ schoolId: SCHOOL_B }));
      });

      /**
       * The delete arm is NEW — the old `allow write` covered delete via a
       * predicate that dereferenced the null `request.resource`. Same-school
       * delete must work and cross-school must not.
       */
      test(`${col}: own-school delete WORKS`, async () => {
        const id = `${SCHOOL_A}_C6`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, v: 1 });

        await assertSucceeds(DBS.staffA.collection(col).doc(id).delete());
      });

      test(`${col}: cross-school delete is REFUSED`, async () => {
        const id = `${SCHOOL_B}_C7`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, v: 1 });

        await assertFails(DBS.staffA.collection(col).doc(id).delete());
      });

      test(`${col}: deleting a NON-EXISTENT document fails closed`, async () => {
        await assertFails(DBS.staffA.collection(col).doc(`${SCHOOL_A}_NOPE`).delete());
      });
    });
  });

  // ══ SHAPE F — circularReads ═════════════════════════════════════════════

  describe('shape F · circularReads', () => {
    test('cross-school staff read is REFUSED', async () => {
      const id = `${SCHOOL_B}_CR1`;
      await seedDoc('circularReads', id, { schoolId: SCHOOL_B, userId: 'STU9999' });

      await assertFails(DBS.staffA.collection('circularReads').doc(id).get());
    });

    /**
     * A NON-EXISTENT receipt is still readable — DELIBERATELY, and measured.
     *
     * The arm's own `resource == null ||` disjunct was removed, but the fix
     * composes with `isSameSchool()`, which retains its OWN leading
     * `resource == null ||`. So a missing document still passes.
     *
     * That is the documented design decision, not an oversight: substituting
     * `isSameSchoolStrict()` would turn every read of an absent document from
     * "empty" into PERMISSION_DENIED across all 13 helper collections too — a
     * behavioural change with a wider blast radius than the finding itself. A
     * missing document discloses no data.
     *
     * An earlier draft of this test asserted refusal, contradicting the design
     * matrix it was written from. Recorded here so the choice stays visible; if
     * the strict variant is ever adopted this must flip to `assertFails`.
     */
    test('reading a NON-EXISTENT receipt is still ALLOWED (isSameSchool fail-open, by design)', async () => {
      await assertSucceeds(DBS.staffA.collection('circularReads').doc(`${SCHOOL_A}_NOPE`).get());
    });

    test('own-school staff read still WORKS', async () => {
      const id = `${SCHOOL_A}_CR2`;
      await seedDoc('circularReads', id, { schoolId: SCHOOL_A, userId: STU_A });

      await assertSucceeds(DBS.staffA.collection('circularReads').doc(id).get());
    });

    test('a user CAN still read their OWN receipt', async () => {
      const id = `${SCHOOL_A}_CR3`;
      await seedDoc('circularReads', id, { schoolId: SCHOOL_A, userId: STU_A });

      await assertSucceeds(DBS.parentA.collection('circularReads').doc(id).get());
    });

    test("a user CANNOT read another user's receipt", async () => {
      const id = `${SCHOOL_A}_CR4`;
      await seedDoc('circularReads', id, { schoolId: SCHOOL_A, userId: STU_A2 });

      await assertFails(DBS.parentA.collection('circularReads').doc(id).get());
    });

    /** Both apps WRITE their own receipt — this must be untouched. */
    test('a user CAN still write their own receipt', async () => {
      await assertSucceeds(
        DBS.parentA.collection('circularReads').doc(`${SCHOOL_A}_CR5`)
          .set({ schoolId: SCHOOL_A, userId: STU_A, readAt: 'now' })
      );
    });

    test("a user still CANNOT forge another user's receipt", async () => {
      await assertFails(
        DBS.parentA.collection('circularReads').doc(`${SCHOOL_A}_CR6`)
          .set({ schoolId: SCHOOL_A, userId: STU_A2, readAt: 'now' })
      );
    });
  });

  // ══ SHAPE G — bare isAdmin() on back-office reads ═══════════════════════
  //
  // These surfaced ONLY after a false negative in the audit tool was fixed:
  // `isAdmin()` expands to a role list containing 'Super Admin', so a
  // platform-detection check that string-matched the INLINED condition filed
  // every `isAdmin()` arm as `platform-only` and hid them from the census.
  // `isAdmin()` admits ordinary school admins; it is not a platform boundary.

  describe('shape G · bare isAdmin() reads', () => {
    ['feeAuditLogs', 'feeRefundAudit', 'fee_generation_jobs'].forEach((col) => {
      test(`${col}: cross-school ADMIN read is REFUSED`, async () => {
        const id = `${SCHOOL_B}_A1`;
        await seedDoc(col, id, { schoolId: SCHOOL_B, action: 'secret', amount: 999 });

        await assertFails(DBS.adminA.collection(col).doc(id).get());
      });

      test(`${col}: own-school admin read still WORKS`, async () => {
        const id = `${SCHOOL_A}_A2`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, action: 'ours' });

        await assertSucceeds(DBS.adminA.collection(col).doc(id).get());
      });

      /** Non-admin staff were never admitted and must still not be. */
      test(`${col}: non-admin staff read is still REFUSED`, async () => {
        const id = `${SCHOOL_A}_A3`;
        await seedDoc(col, id, { schoolId: SCHOOL_A, action: 'ours' });

        await assertFails(DBS.staffA.collection(col).doc(id).get());
      });

      test(`${col}: an unscoped LIST cannot bypass the boundary`, async () => {
        await seedDoc(col, `${SCHOOL_A}_A4`, { schoolId: SCHOOL_A });
        await seedDoc(col, `${SCHOOL_B}_A5`, { schoolId: SCHOOL_B });

        await assertFails(DBS.adminA.collection(col).get());
      });
    });

    /**
     * `feeRefundAudit` create/update/delete were ALREADY tenant-scoped — only
     * its read leaked. Pinned so the write arms are shown untouched.
     */
    test('feeRefundAudit: own-school create still WORKS', async () => {
      await assertSucceeds(
        DBS.adminA.collection('feeRefundAudit').doc(`${SCHOOL_A}_R1`)
          .set({ schoolId: SCHOOL_A, amount: 10 })
      );
    });

    test('feeRefundAudit: cross-school create is still REFUSED', async () => {
      await assertFails(
        DBS.adminA.collection('feeRefundAudit').doc(`${SCHOOL_B}_R2`)
          .set({ schoolId: SCHOOL_B, amount: 10 })
      );
    });

    /** `feeAuditLogs` is server-only for writes and must stay that way. */
    test('feeAuditLogs: client write is still REFUSED', async () => {
      await assertFails(
        DBS.adminA.collection('feeAuditLogs').doc(`${SCHOOL_A}_W`).set({ schoolId: SCHOOL_A })
      );
    });
  });

  // ══ THE PARENT PAYMENT FLOW — the exact queries PaymentSession.kt issues ══
  //
  // Found by re-running the cross-platform census: `feeDemands/Parent` and
  // `feeReceipts/Parent` came back REVIEW because the payment flow reaches them
  // through a RAW `firestore.collection(...)` rather than the service wrapper.
  // Both turned out to be reads — but they are reads on a MONEY-CRITICAL path,
  // and the earlier suite only exercised the STAFF query shape.
  //
  // SEC-7 Q10 established that a scoped read arm rejects a query unless the
  // query itself carries the filters that prove it. So the Parent app's exact
  // query shape has to be asserted, not assumed to be covered by the staff one.

  describe('Parent payment flow (PaymentSession.kt query shapes)', () => {
    /**
     * `confirmFromBackend()` — a single-document get of the receipt just paid.
     * Under the fix a parent reaches this through `isOwnStudent()`, so the
     * receipt's `studentId` must match their uid.
     */
    test('a parent CAN read their own receipt by document id', async () => {
      const id = `${SCHOOL_A}_RCPT1`;
      await seedDoc('feeReceipts', id, {
        schoolId: SCHOOL_A, studentId: STU_A, amount: 5000, feeMonths: ['April'],
      });

      await assertSucceeds(DBS.parentA.collection('feeReceipts').doc(id).get());
    });

    test("a parent CANNOT read another student's receipt by document id", async () => {
      const id = `${SCHOOL_A}_RCPT2`;
      await seedDoc('feeReceipts', id, {
        schoolId: SCHOOL_A, studentId: STU_A2, amount: 5000, feeMonths: ['April'],
      });

      await assertFails(DBS.parentA.collection('feeReceipts').doc(id).get());
    });

    /**
     * `monthStatusFromDemands()` — the exact three-filter query the app issues:
     *     whereEqualTo schoolId + session + studentId
     * It must remain permitted, or fee payment confirmation breaks for every
     * parent.
     */
    test('the parent CAN still run the exact feeDemands payment query', async () => {
      await seedDoc('feeDemands', `${SCHOOL_A}_D1`, {
        schoolId: SCHOOL_A, session: '2026-27', studentId: STU_A, period: 'April', netAmount: 100,
      });

      await assertSucceeds(
        DBS.parentA.collection('feeDemands')
          .where('schoolId', '==', SCHOOL_A)
          .where('session', '==', '2026-27')
          .where('studentId', '==', STU_A)
          .get()
      );
    });

    /** The same query aimed at ANOTHER student must be refused. */
    test('the parent CANNOT run that query for another student', async () => {
      await seedDoc('feeDemands', `${SCHOOL_A}_D2`, {
        schoolId: SCHOOL_A, session: '2026-27', studentId: STU_A2, netAmount: 100,
      });

      await assertFails(
        DBS.parentA.collection('feeDemands')
          .where('schoolId', '==', SCHOOL_A)
          .where('session', '==', '2026-27')
          .where('studentId', '==', STU_A2)
          .get()
      );
    });

    /** And aimed at another SCHOOL. */
    test('the parent CANNOT run that query against another school', async () => {
      await seedDoc('feeDemands', `${SCHOOL_B}_D3`, {
        schoolId: SCHOOL_B, session: '2026-27', studentId: STU_A, netAmount: 100,
      });

      await assertFails(
        DBS.parentA.collection('feeDemands')
          .where('schoolId', '==', SCHOOL_B)
          .where('session', '==', '2026-27')
          .where('studentId', '==', STU_A)
          .get()
      );
    });

    /** Dropping the studentId filter must not become a way to see everyone. */
    test('the parent CANNOT drop the studentId filter to widen the query', async () => {
      await seedDoc('feeDemands', `${SCHOOL_A}_D4`, {
        schoolId: SCHOOL_A, session: '2026-27', studentId: STU_A2, netAmount: 100,
      });

      await assertFails(
        DBS.parentA.collection('feeDemands')
          .where('schoolId', '==', SCHOOL_A)
          .where('session', '==', '2026-27')
          .get()
      );
    });
  });

  // ══ CROSS-TENANT SANITY, FROM THE OTHER SIDE ════════════════════════════

  /**
   * School B's staff must be equally refused School A's data. Testing only one
   * direction can pass by accident if a predicate happens to hard-code or
   * favour one tenant.
   */
  test('the boundary is symmetric — School B staff cannot read School A', async () => {
    const id = `${SCHOOL_A}_${STU_A}`;
    await seedDoc('feeDemands', id, { schoolId: SCHOOL_A, studentId: STU_A, amount: 100 });

    await assertFails(DBS.staffB.collection('feeDemands').doc(id).get());
  });

  test('School B staff CAN read their own school', async () => {
    const id = `${SCHOOL_B}_X`;
    await seedDoc('feeDemands', id, { schoolId: SCHOOL_B, studentId: 'STU9999', amount: 100 });

    await assertSucceeds(DBS.staffB.collection('feeDemands').doc(id).get());
  });
});
