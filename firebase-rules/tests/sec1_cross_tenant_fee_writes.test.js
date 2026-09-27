/**
 * SEC-1 — CROSS-TENANT WRITE ON MONEY-ADJACENT COLLECTIONS.
 *
 * ── THE DEFECT ────────────────────────────────────────────────────────────
 * NINE collections carry a correctly school-scoped READ but an UNSCOPED write:
 *
 *     allow read:  if isStaffOrOwnStudent();     // -> isSameSchool()
 *     allow write: if isAdmin();                 // <- no ownership check
 *
 * `isAdmin()` verifies auth, `tenantActive(token.school_id)` and an admin
 * role/capability. It never compares the DOCUMENT's `schoolId` to the caller's
 * token. So an authenticated admin of School A can write School B's documents.
 *
 * `feeDemands` / `feeReceipts` already do it correctly:
 *     allow create, update: if isAdmin() && isSameSchoolWrite();
 *
 * `studentDiscounts` is the sharpest case: `FeeCollectionService` reads it to
 * compute `approvedDiscountCap`, the server-side ceiling on a client-supplied
 * discount. It is a financial control, not a projection.
 *
 * ── WHICH RULES THIS RUNS AGAINST ─────────────────────────────────────────
 * NOT the repository's `firestore.rules`. That file is ~31KB AHEAD of
 * production and carries another workstream's undeployed work, so testing it
 * would not describe production.
 *
 * These tests run against the SEC-1 MINIMAL ARTIFACT: the live deployed ruleset
 * with exactly nine lines changed. Set SEC1_RULES to that artifact's path.
 * Without it the suite is skipped rather than silently testing the wrong file.
 */

const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');

const PROJECT_ID = 'zenxii-rules-test';   // must match the emulator's project
const SCHOOL_A   = 'SCH_TENANT_AAA';
const SCHOOL_B   = 'SCH_TENANT_BBB';

// The nine collections under test.
const COLLECTIONS = [
  // wave 1 — money-adjacent
  'feeDefaulters',
  'studentDiscounts',
  'scholarshipAwards',
  'feeCarryForward',
  'feeReceiptAllocations',
  // wave 2 — same defect class, verified ZERO client writes
  'feeReminderLog',
  'fee_generation_jobs',
  'timetableSettings',
  'substitutes',
];

const RULES_PATH = process.env.SEC1_RULES || '';
const HAVE_RULES = RULES_PATH && fs.existsSync(RULES_PATH);

// Loading a ~146KB ruleset into the emulator exceeds Jest's 5s default for
// beforeAll on a loaded machine. The timeout is an environment property, not
// a property of the rules — a hook timeout fails every test in the file and is
// easy to misread as a total denial.
jest.setTimeout(60000);

let env;

beforeAll(async () => {
  if (!HAVE_RULES) return;
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: {
      rules: fs.readFileSync(RULES_PATH, 'utf8'),
      host: '127.0.0.1',
      port: 18080,
    },
  });

  /**
   * HARNESS ORDERING CONSTRAINT — isolated 2026-08-21 with a standalone repro.
   *
   * The FIRST rules-ENFORCING context must be created BEFORE the first
   * `withSecurityRulesDisabled()` call. Seeding opens a rules-BYPASSING app,
   * which starts the Firestore instance for this projectId; the first
   * enforcing context created afterwards then throws
   *
   *   "Firestore has already been started and its settings can no longer be
   *    changed"
   *
   * ...and EVERY test in the file fails identically. That failure mode is
   * indistinguishable at a glance from "the ruleset denies everything", which
   * is precisely why it is pinned here: a green-to-red flip caused by harness
   * ordering must never be mistaken for a security finding, in either
   * direction.
   *
   * Building every context ONCE, up front, makes the order deterministic:
   * after this point no test creates a new Firestore instance, so nothing can
   * be "already started" by a later seed.
   */
  [SCHOOL_A, SCHOOL_B].forEach((s) => {
    DBS[s] = env.authenticatedContext(`admin_${s}`, {
      school_id: s,
      schoolId:  s,
      role:      'Admin',
    }).firestore();
  });
  DBS.anon = env.unauthenticatedContext().firestore();
});

afterAll(async () => { if (env) await env.cleanup(); });

/** Contexts are built once in beforeAll — see the ordering note above. */
const DBS = {};

/** An admin authenticated against one school. */
function adminOf(school) {
  return DBS[school];
}

/**
 * CALL `ctx.firestore()` EXACTLY ONCE PER CALLBACK.
 *
 * `RulesTestContextImpl.firestore()` re-invokes `useEmulator()` on EVERY call,
 * and `useEmulator()` throws once the instance has started:
 *
 *   "Firestore has already been started and its settings can no longer be
 *    changed"
 *
 * So a seed loop written as
 *
 *     await ctx.firestore().collection('schools').doc(s).set(...)
 *     await ctx.firestore().collection('schoolControl').doc(s).set(...)
 *
 * throws on the second line. Because the throw happens in `beforeEach`, EVERY
 * test in the file fails identically — which reads exactly like "the ruleset
 * denies everything" and is easy to misdiagnose as a security finding.
 * Hoisting the handle into a variable is the whole fix.
 */
async function seed(school, col, docId, extra = {}) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.collection(col).doc(docId).set({
      schoolId: school,
      studentId: 'STU0001',
      amount: 100,
      ...extra,
    });
  });
}

/** The tenant must be marked active or isAdmin() fails for an unrelated reason. */
async function seedTenants() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL_A, SCHOOL_B]) {
      await db.collection('schools').doc(s).set({ schoolId: s });
      // tenantActive() reads schoolControl/{id}.lifecycle.state — NOT a `status`
      // field. An invented fixture shape makes isAdmin() throw
      // "Property lifecycle is undefined on object", which surfaces as
      // PERMISSION_DENIED on every test and reads like a rules defect.
      await db.collection('schoolControl').doc(s).set({ lifecycle: { state: 'active' } });
    }
  });
}

const maybe = HAVE_RULES ? describe : describe.skip;

maybe('SEC-1 · cross-tenant writes on money-adjacent collections', () => {
  beforeEach(async () => {
    await env.clearFirestore();
    await seedTenants();
  });

  // ══ THE VULNERABILITY ═══════════════════════════════════════════════════

  COLLECTIONS.forEach((col) => {
    test(`${col}: an admin of School A CANNOT update School B's document`, async () => {
      const docId = `${SCHOOL_B}_STU0001`;
      await seed(SCHOOL_B, col, docId);

      await assertFails(
        adminOf(SCHOOL_A).collection(col).doc(docId).update({ amount: 999999 })
      );
    });

    test(`${col}: an admin of School A CANNOT create a document owned by School B`, async () => {
      await assertFails(
        adminOf(SCHOOL_A).collection(col).doc(`${SCHOOL_B}_STU0002`).set({
          schoolId: SCHOOL_B, studentId: 'STU0002', amount: 500,
        })
      );
    });

    test(`${col}: an admin of School A CANNOT delete School B's document`, async () => {
      const docId = `${SCHOOL_B}_STU0001`;
      await seed(SCHOOL_B, col, docId);

      await assertFails(
        adminOf(SCHOOL_A).collection(col).doc(docId).delete()
      );
    });

    // ══ LEGITIMATE WRITES MUST STILL WORK ═════════════════════════════════

    test(`${col}: an admin CAN write their OWN school's document`, async () => {
      await assertSucceeds(
        adminOf(SCHOOL_A).collection(col).doc(`${SCHOOL_A}_STU0003`).set({
          schoolId: SCHOOL_A, studentId: 'STU0003', amount: 250,
        })
      );
    });

    /**
     * DELETE MUST SURVIVE THE FIX — this is the regression the first artifact
     * would have caused.
     *
     * `allow write` covers create + update + delete, but on a DELETE
     * `request.resource` is null. An artifact that wrote
     * `allow write: if isAdmin() && isSameSchoolWrite();` would therefore have
     * denied EVERY delete, because `isSameSchoolWrite()` reads
     * `request.resource.data.schoolId`.
     *
     * The fix follows the idiom `feeDemands` already uses — splitting the arms
     * so each inspects the object that actually exists:
     *     create/update -> isSameSchoolWrite()  (request.resource)
     *     delete        -> isSameSchool()       (resource)
     */
    test(`${col}: an admin CAN delete their OWN school's document`, async () => {
      const docId = `${SCHOOL_A}_STU0008`;
      await seed(SCHOOL_A, col, docId);

      await assertSucceeds(
        adminOf(SCHOOL_A).collection(col).doc(docId).delete()
      );
    });

    /**
     * DELETE USES A STRICT PREDICATE, NOT `isSameSchool()`.
     *
     * `feeDemands` authorises delete with `isSameSchool()`, but adversarial
     * review rejected copying that here for two reasons — and a delete IS a
     * write:
     *
     *   1. `isSameSchool()` carries a legacy `resource.data.schoolCode ==
     *      token.school_id` arm. D7 (2026-07-12) deliberately removed that arm
     *      from the WRITE path as the "spoofable schoolCode fallback (F-2)",
     *      keeping it for reads only. Authorising a delete through it would
     *      reintroduce exactly what D7 removed.
     *   2. `isSameSchool()` begins `resource == null ||`, i.e. it fails OPEN on
     *      a missing document.
     *
     * Measured before choosing: 33 live documents across these nine
     * collections, ZERO lacking `schoolId`, so the legacy arm buys nothing.
     *
     * A document carrying ONLY `schoolCode` must therefore NOT be deletable.
     */
    test(`${col}: a schoolCode-only document cannot be deleted (legacy arm refused)`, async () => {
      const docId = `${SCHOOL_A}_LEGACY01`;
      await env.withSecurityRulesDisabled(async (ctx) => {
        // No schoolId — only the legacy key.
        const db = ctx.firestore();
        await db.collection(col).doc(docId)
          .set({ schoolCode: SCHOOL_A, studentId: 'STU0009', amount: 5 });
      });

      await assertFails(
        adminOf(SCHOOL_A).collection(col).doc(docId).delete()
      );
    });

    /** And a delete of a NON-EXISTENT document must fail closed. */
    test(`${col}: deleting a non-existent document fails closed`, async () => {
      await assertFails(
        adminOf(SCHOOL_A).collection(col).doc(`${SCHOOL_A}_NOSUCHDOC`).delete()
      );
    });

    // ══ THE ESCAPE HATCHES ════════════════════════════════════════════════

    /**
     * A missing schoolId must not slip through. `isSameSchoolWrite()` requires
     * the field to be a non-empty string, so an omitted owner is refused rather
     * than defaulting to the caller's tenant.
     */
    test(`${col}: a write with NO schoolId is refused`, async () => {
      await assertFails(
        adminOf(SCHOOL_A).collection(col).doc(`${SCHOOL_A}_STU0004`).set({
          studentId: 'STU0004', amount: 10,
        })
      );
    });

    test(`${col}: a write with an EMPTY schoolId is refused`, async () => {
      await assertFails(
        adminOf(SCHOOL_A).collection(col).doc(`${SCHOOL_A}_STU0005`).set({
          schoolId: '', studentId: 'STU0005', amount: 10,
        })
      );
    });

    /**
     * RE-HOMING. An admin must not be able to take ownership of their own
     * document by rewriting `schoolId` to another tenant — the write is
     * evaluated against `request.resource.data`, i.e. the NEW value.
     */
    test(`${col}: an admin cannot re-home their own document to another school`, async () => {
      const docId = `${SCHOOL_A}_STU0006`;
      await seed(SCHOOL_A, col, docId);

      await assertFails(
        adminOf(SCHOOL_A).collection(col).doc(docId).update({ schoolId: SCHOOL_B })
      );
    });

    /** And an unauthenticated client must never write at all. */
    test(`${col}: an unauthenticated client cannot write`, async () => {
      await assertFails(
        DBS.anon
          .collection(col).doc(`${SCHOOL_A}_STU0007`).set({ schoolId: SCHOOL_A, amount: 1 })
      );
    });
  });

  // ══ READS STAY AS THEY WERE ═════════════════════════════════════════════

  /**
   * The fix touches only the write arm. A same-school staff read must keep
   * working, or the change would be a regression dressed as a security fix.
   */
  test('same-school read is unaffected by the write-arm change', async () => {
    const docId = `${SCHOOL_A}_STU0001`;
    await seed(SCHOOL_A, 'feeDefaulters', docId);

    await assertSucceeds(
      adminOf(SCHOOL_A).collection('feeDefaulters').doc(docId).get()
    );
  });

  /**
   * CROSS-SCHOOL READ IS **NOT** REFUSED — and that is SEC-4, not SEC-1.
   *
   * This test originally asserted `assertFails`, on the assumption that a
   * correctly scoped read arm implied tenant isolation. It failed, and the
   * reason is a live defect this artifact does not touch:
   *
   *     isStaffOrOwnStudent() = isStaff() || (isSameSchool() && isOwnStudent())
   *
   * `isStaff()` checks the CALLER (auth, own tenant active, not a
   * student/parent) and never compares the DOCUMENT's school. `||`
   * short-circuits, so for any staff caller the tenant check is never reached.
   *
   * The assertion is inverted here to record production's ACTUAL behaviour,
   * so this suite measures the write fix without silently smuggling in a
   * false claim about reads. The finding itself, its 44-collection blast
   * radius and its remediation live in sec4_cross_tenant_staff_read.test.js.
   *
   * FLIPPED 2026-08-21 — SEC-6 CLOSED IT IN PRODUCTION.
   *
   * The helper is now `isSameSchool() && (isStaff() || isOwnStudent())`, so the
   * tenant comparison can no longer be short-circuited. This test has returned
   * to asserting refusal, exactly as the note above required.
   *
   * Note on HOW it is refused: `isSameSchool()` still carries a legacy
   * `resource.data.schoolCode` arm, so on a School-B document holding only
   * `schoolId` the denial arrives as an evaluation error
   * ("Property schoolCode is undefined") rather than a clean false. The request
   * is refused either way, and `assertFails` covers both — but see
   * SEC6_DEPLOYMENT_PACKET.md for why that distinction was worth measuring.
   */
  test('cross-school read is REFUSED for staff (SEC-4 closed by SEC-6)', async () => {
    const docId = `${SCHOOL_B}_STU0001`;
    await seed(SCHOOL_B, 'feeDefaulters', docId);

    await assertFails(
      adminOf(SCHOOL_A).collection('feeDefaulters').doc(docId).get()
    );
  });
});
