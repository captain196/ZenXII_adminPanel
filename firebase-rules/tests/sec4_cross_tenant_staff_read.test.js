/**
 * SEC-4 — CROSS-TENANT STAFF **READ** ON STUDENT AND FINANCIAL RECORDS.
 *
 * ── HOW THIS WAS FOUND ────────────────────────────────────────────────────
 * Not by looking for it. SEC-1 changed only write arms, so a read-regression
 * test was added purely to prove the write fix had not disturbed reads:
 *
 *     test('cross-school read remains refused', ...)
 *
 * It FAILED. The read was never refused in the first place — the test had
 * encoded an assumption about production rather than a measurement of it.
 *
 * ── THE DEFECT ────────────────────────────────────────────────────────────
 *     function isStaff() {
 *       return isAuth()
 *         && tenantActive(request.auth.token.school_id)     // CALLER's tenant
 *         && !(request.auth.token.role in ['Student', 'Parent', ...]);
 *     }
 *
 *     function isStaffOrOwnStudent() {
 *       return isStaff() || (isSameSchool() && isOwnStudent());
 *     }
 *
 * `isStaff()` validates the CALLER (authenticated, own tenant active, not a
 * student/parent). It never compares the DOCUMENT's `schoolId` to the caller's
 * token. Tenant scoping appears only in the second disjunct — and `||`
 * short-circuits, so for any staff caller the document check is never reached.
 *
 * Any staff account of ANY tenant could therefore read these documents in EVERY
 * tenant. Final measured scope: 14 exposed arms — 13 through the helper plus
 * `circularReads` inline. (An early figure of "45 arms across 44 collections"
 * was an OVERCOUNT that treated the 37 CORRECTLY composed arms as defects, and
 * is withdrawn.)
 *
 * ── WHY IT IS FILED SEPARATELY FROM SEC-1 ─────────────────────────────────
 * SEC-1 is a WRITE finding and its artifact does not touch reads. This is a
 * pre-existing LIVE condition. These tests deliberately run against the
 * UNMODIFIED LIVE PULL (SEC4_LIVE_RULES) so the result cannot be confused with
 * something a SEC-1/SEC-2 artifact introduced.
 *
 * ── STATUS ────────────────────────────────────────────────────────────────
 * REGRESSION PROOF as of 2026-08-21. These tests originally asserted the EXPOSURE
 * with assertSucceeds, and were written to FAIL the day the boundary was fixed.
 * SEC-6 fixed it in production (ruleset a4a484d2, 2026-08-21), so they have been
 * flipped to assertFails and now guard the fix instead of documenting the hole.
 *
 * The controls below — a PARENT refused, and attendance/submissions/studentFlags
 * refused all along — are unchanged and still prove the finding was specifically
 * the staff disjunct rather than a blanket absence of tenant checks.
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

/**
 * A representative slice of the affected collections, chosen so the blast
 * radius is visible rather than implied: money and the academic record.
 */
// Collections whose read arm is `isStaffOrOwnStudent()` — the defective helper.
const EXPOSED = [
  'feeDemands',
  'feeReceipts',
  'feeDefaulters',
  'studentDiscounts',
  'scholarshipAwards',
  'marks',
  'results',
];

// Collections that compose the SAME two predicates CORRECTLY, and are the
// proof that the fix is a re-composition rather than a new invention:
//
//   attendance   (isStaff() && isSameSchool()) || (... isSameSchool() ...)
//   submissions  isSameSchoolStrict() && (isStaff() || isOwnStudent())
//
// Measured, not assumed: an early draft of this suite expected these two to be
// exposed as well. They refused the cross-tenant read, which is what showed the
// remediation pattern was already present in the ruleset.
const CORRECTLY_SCOPED = ['attendance', 'submissions', 'studentFlags'];

/**
 * `circularReads` is exposed by a DIFFERENT shape and is listed separately so
 * the two are not conflated.
 *
 * The thirteen collections above leak through the `isStaffOrOwnStudent()`
 * HELPER. This one spells the same mistake out inline:
 *
 *     allow read: if isAuth() && (
 *       resource == null
 *       || resource.data.get('userId', '') == request.auth.uid
 *       || isStaff()                                   <- bare, no school check
 *     );
 *
 * Fixing the helper would NOT fix this arm. Recorded so the remediation is not
 * declared complete on a helper edit alone.
 */
const EXPOSED_INLINE = ['circularReads'];

const RULES_PATH = process.env.SEC4_LIVE_RULES || '';
const HAVE_RULES = RULES_PATH && fs.existsSync(RULES_PATH);

// Loading a ~146KB ruleset into the emulator exceeds Jest's 5s default for
// beforeAll on a loaded machine. The timeout is an environment property, not
// a property of the rules — a hook timeout fails every test in the file and is
// easy to misread as a total denial.
jest.setTimeout(60000);

let env;
const DBS = {};

beforeAll(async () => {
  if (!HAVE_RULES) return;
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });

  // Contexts are built ONCE, before any withSecurityRulesDisabled() seed.
  // RulesTestContextImpl.firestore() re-invokes useEmulator() on every call and
  // throws once the instance has started; see sec1_cross_tenant_fee_writes.
  DBS.teacherA = env.authenticatedContext('staff_A', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Teacher',
  }).firestore();
  DBS.adminA = env.authenticatedContext('admin_A', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Admin',
  }).firestore();
  DBS.parentA = env.authenticatedContext('STU_A1', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Parent',
  }).firestore();
});

afterAll(async () => { if (env) await env.cleanup(); });

async function seedTenants() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL_A, SCHOOL_B]) {
      await db.collection('schools').doc(s).set({ schoolId: s });
      // tenantActive() reads schoolControl/{id}.lifecycle.state.
      await db.collection('schoolControl').doc(s).set({ lifecycle: { state: 'active' } });
    }
  });
}

async function seedDoc(school, col, docId) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.collection(col).doc(docId).set({
      schoolId: school,
      studentId: 'STU_VICTIM',
      amount: 12345,
      marksObtained: 99,
    });
  });
}

const maybe = HAVE_RULES ? describe : describe.skip;

maybe('SEC-4 · cross-tenant staff read (regression proof — closed by SEC-6)', () => {
  beforeEach(async () => {
    await env.clearFirestore();
    await seedTenants();
  });

  EXPOSED.forEach((col) => {
    /**
     * THE FORMER EXPOSURE, now the guard. Before SEC-6 both of these asserted
     * assertSucceeds — a record of what production allowed. They were always
     * meant to flip, and they have.
     */
    test(`${col}: a TEACHER of School A is REFUSED School B's document`, async () => {
      const docId = `${SCHOOL_B}_STU_VICTIM`;
      await seedDoc(SCHOOL_B, col, docId);

      await assertFails(DBS.teacherA.collection(col).doc(docId).get());
    });

    test(`${col}: an ADMIN of School A is REFUSED School B's document`, async () => {
      const docId = `${SCHOOL_B}_STU_VICTIM`;
      await seedDoc(SCHOOL_B, col, docId);

      await assertFails(DBS.adminA.collection(col).doc(docId).get());
    });

    /**
     * THE CONTROL that proves the finding is specifically about the STAFF
     * disjunct rather than a blanket absence of tenant checks.
     *
     * A PARENT falls to `isSameSchool() && isOwnStudent()`, where the document's
     * school IS compared — so the same cross-tenant read must be refused. If
     * this control ever passes, the finding is broader than described and the
     * whole analysis needs redoing.
     */
    test(`${col}: a PARENT of School A is correctly refused School B's document`, async () => {
      const docId = `${SCHOOL_B}_STU_VICTIM`;
      await seedDoc(SCHOOL_B, col, docId);

      await assertFails(DBS.parentA.collection(col).doc(docId).get());
    });
  });

  /**
   * THE CONTROL GROUP — collections that already get this right.
   *
   * These use the SAME `isStaff()` and `isSameSchool()` predicates but compose
   * them with `&&` instead of letting `||` short-circuit. They refuse the
   * cross-tenant staff read today, with no code change of any kind.
   *
   * That is the whole argument for the remediation: it is not a new
   * restriction to be designed and risk-assessed from scratch, it is the
   * composition two neighbouring blocks in the same file already use.
   */
  CORRECTLY_SCOPED.forEach((col) => {
    test(`${col}: a TEACHER of School A is ALREADY refused School B's document`, async () => {
      const docId = `${SCHOOL_B}_STU_VICTIM`;
      await seedDoc(SCHOOL_B, col, docId);

      await assertFails(DBS.teacherA.collection(col).doc(docId).get());
    });

    test(`${col}: a teacher CAN still read their OWN school's document`, async () => {
      const docId = `${SCHOOL_A}_STU_VICTIM`;
      await seedDoc(SCHOOL_A, col, docId);

      await assertSucceeds(DBS.teacherA.collection(col).doc(docId).get());
    });
  });

  /**
   * The inline variant — same defect, different spelling, NOT fixed by editing
   * the helper.
   */
  EXPOSED_INLINE.forEach((col) => {
    test(`${col}: a TEACHER of School A is REFUSED School B's document (inline disjunct closed)`, async () => {
      const docId = `${SCHOOL_B}_STU_VICTIM`;
      await seedDoc(SCHOOL_B, col, docId);

      await assertFails(DBS.teacherA.collection(col).doc(docId).get());
    });
  });

  /**
   * And the legitimate case must keep working — whatever remediation is chosen
   * has to preserve this, which is why it is pinned now, before any fix.
   */
  test('a teacher CAN read their OWN school\'s document (must survive any fix)', async () => {
    const docId = `${SCHOOL_A}_STU_VICTIM`;
    await seedDoc(SCHOOL_A, 'feeDemands', docId);

    await assertSucceeds(DBS.teacherA.collection('feeDemands').doc(docId).get());
  });
});
