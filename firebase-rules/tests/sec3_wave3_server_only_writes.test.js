/**
 * SEC-3 WAVE 3 — IDENTITY, PAYROLL, RBAC AND PRICING AUTHORITY.
 *
 * ── THE DEFECT ────────────────────────────────────────────────────────────
 * Six collections carried an unscoped write:
 *
 *     allow write: if isAdmin();
 *
 * `isAdmin()` checks auth, `tenantActive(token.school_id)` and an admin role.
 * It never compares the DOCUMENT's `schoolId` to the caller's token, so an
 * admin of School A could write School B's staff records, payslips, role
 * catalogue, gateway secrets and fee chart.
 *
 * ── WHY SERVER-ONLY AND NOT `isSameSchoolWrite()` ─────────────────────────
 * Tenant-scoping would be the reflex fix. It is the WEAKER one here, because
 * the client population these arms authorise does not exist:
 *
 *   1. The admin panel contains NO Firebase JS SDK. Every panel write runs
 *      through PHP on the service account, which bypasses rules entirely.
 *      (The single `firebasejs` match in the tree is a commented-out line in
 *      application/config/migration.php.)
 *   2. Cloud Functions use firebase-admin — also bypassing rules.
 *   3. `scripts/app_firestore_write_census.php` reports READ-ONLY on BOTH apps
 *      for staff / salarySlips / rbacRoles / feeSettings / feeStructures, and
 *      exactly one client write on `students`.
 *   4. Neither app uses `docRef()`, a raw `.collection()`, or a batch /
 *      transaction against any wave-3 collection.
 *
 * The census was validated against a known-positive control (`userDevices`,
 * which both apps demonstrably write) BEFORE its READ-ONLY verdicts were
 * relied on — otherwise a census that silently matched nothing would have
 * "proved" every collection safe.
 *
 * `if false` is also the house idiom: the live ruleset already uses it for 87
 * other server-only arms.
 *
 * ── `students` IS THE EXCEPTION ───────────────────────────────────────────
 * The Parent app really does write it, so only the blanket admin arm is
 * denied. The two self-service `allow update` clauses remain. Firestore ORs
 * all matching allow rules — that is asserted here, not assumed, because if it
 * were wrong this change would lock every student out of their own password
 * reset.
 *
 * Runs against the wave-3 artifact via SEC3_W3_RULES; skips otherwise.
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
const STUDENT_A  = 'STU0001';
const STUDENT_A2 = 'STU0002';

/** All six wave-3 collections. */
const SERVER_ONLY = ['students', 'staff', 'salarySlips', 'rbacRoles', 'feeSettings', 'feeStructures'];

const RULES_PATH = process.env.SEC3_W3_RULES || '';
const HAVE_RULES = RULES_PATH && fs.existsSync(RULES_PATH);

// Loading a ~146KB ruleset exceeds Jest's 5s default for beforeAll on a loaded
// machine. A hook timeout fails every test in the file and is easy to misread
// as a total denial.
jest.setTimeout(60000);

let env;
const DBS = {};

beforeAll(async () => {
  if (!HAVE_RULES) return;
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });

  // Contexts built ONCE, before the first withSecurityRulesDisabled() seed:
  // RulesTestContextImpl.firestore() re-invokes useEmulator() every call and
  // throws once the instance has started.
  DBS.adminA = env.authenticatedContext('admin_A', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Admin',
  }).firestore();
  DBS.adminB = env.authenticatedContext('admin_B', {
    school_id: SCHOOL_B, schoolId: SCHOOL_B, role: 'Admin',
  }).firestore();
  DBS.teacherA = env.authenticatedContext('staff_A', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Teacher',
  }).firestore();
  // uid == studentId, per the documented doc-id contract `{schoolId}_{studentId}`.
  DBS.studentA = env.authenticatedContext(STUDENT_A, {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Student',
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

const studentDoc = (school, sid) => ({
  schoolId: school,
  studentId: sid,
  name: 'Test Student',
  className: 'V',
  section: 'A',
  status: 'Active',
  mustChangePassword: true,
  prefLang: 'en',
  updatedAt: '2026-08-01T00:00:00Z',
});

const maybe = HAVE_RULES ? describe : describe.skip;

maybe('SEC-3 wave 3 · server-only writes', () => {
  beforeEach(async () => {
    await env.clearFirestore();
    await seedTenants();
  });

  // ══ EVERY WRITE VERB, INDEPENDENTLY ═════════════════════════════════════
  //
  // create / update / delete are asserted SEPARATELY rather than through one
  // `write` test. `allow write` covers all three but `request.resource` is
  // null on delete, so a single-verb test can pass while one verb behaves
  // differently — which is exactly the defect an earlier SEC-1 artifact had.

  SERVER_ONLY.forEach((col) => {
    test(`${col}: an admin CANNOT create, even in their own school`, async () => {
      await assertFails(
        DBS.adminA.collection(col).doc(`${SCHOOL_A}_NEW`).set({ schoolId: SCHOOL_A, v: 1 })
      );
    });

    test(`${col}: an admin CANNOT update, even in their own school`, async () => {
      const id = `${SCHOOL_A}_EXISTING`;
      await seedDoc(col, id, { schoolId: SCHOOL_A, v: 1 });

      await assertFails(DBS.adminA.collection(col).doc(id).update({ v: 2 }));
    });

    test(`${col}: an admin CANNOT delete, even in their own school`, async () => {
      const id = `${SCHOOL_A}_EXISTING`;
      await seedDoc(col, id, { schoolId: SCHOOL_A, v: 1 });

      await assertFails(DBS.adminA.collection(col).doc(id).delete());
    });

    // The original vulnerability — cross-tenant. Still refused, now for a
    // stronger reason than tenancy.
    test(`${col}: an admin of School A CANNOT write School B's document`, async () => {
      const id = `${SCHOOL_B}_EXISTING`;
      await seedDoc(col, id, { schoolId: SCHOOL_B, v: 1 });

      await assertFails(DBS.adminA.collection(col).doc(id).update({ v: 999 }));
    });

    test(`${col}: an admin of School A CANNOT delete School B's document`, async () => {
      const id = `${SCHOOL_B}_EXISTING`;
      await seedDoc(col, id, { schoolId: SCHOOL_B, v: 1 });

      await assertFails(DBS.adminA.collection(col).doc(id).delete());
    });

    test(`${col}: a teacher CANNOT write`, async () => {
      await assertFails(
        DBS.teacherA.collection(col).doc(`${SCHOOL_A}_T`).set({ schoolId: SCHOOL_A, v: 1 })
      );
    });

    test(`${col}: an unauthenticated client CANNOT write`, async () => {
      await assertFails(
        DBS.anon.collection(col).doc(`${SCHOOL_A}_U`).set({ schoolId: SCHOOL_A, v: 1 })
      );
    });
  });

  // ══ READS MUST BE UNTOUCHED ═════════════════════════════════════════════
  //
  // The change edits only write arms. If a read regressed, this would be a
  // breakage dressed as a security fix.

  test('staff: same-school read still works', async () => {
    const id = `${SCHOOL_A}_STA0001`;
    await seedDoc('staff', id, { schoolId: SCHOOL_A, name: 'T' });
    await assertSucceeds(DBS.teacherA.collection('staff').doc(id).get());
  });

  test('rbacRoles: same-school read still works', async () => {
    const id = `${SCHOOL_A}_role1`;
    await seedDoc('rbacRoles', id, { schoolId: SCHOOL_A, modules: [] });
    await assertSucceeds(DBS.teacherA.collection('rbacRoles').doc(id).get());
  });

  test('feeStructures: same-school read still works', async () => {
    const id = `${SCHOOL_A}_V`;
    await seedDoc('feeStructures', id, { schoolId: SCHOOL_A, amount: 1000 });
    await assertSucceeds(DBS.teacherA.collection('feeStructures').doc(id).get());
  });

  test('feeSettings: a NON-gateway doc is still readable', async () => {
    const id = `${SCHOOL_A}_2026_general`;
    await seedDoc('feeSettings', id, { schoolId: SCHOOL_A, type: 'general', lateFee: 50 });
    await assertSucceeds(DBS.teacherA.collection('feeSettings').doc(id).get());
  });

  /** The gateway secrets must stay unreadable — unchanged, and pinned. */
  test('feeSettings: a GATEWAY doc remains unreadable', async () => {
    const id = `${SCHOOL_A}_2026_gateway`;
    await seedDoc('feeSettings', id, {
      schoolId: SCHOOL_A, type: 'gateway', api_secret: 'SECRET', webhook_secret: 'SECRET',
    });
    await assertFails(DBS.teacherA.collection('feeSettings').doc(id).get());
  });

  test('salarySlips: own payslip read still works', async () => {
    const id = `${SCHOOL_A}_staff_A_2026_08`;
    await seedDoc('salarySlips', id, { schoolId: SCHOOL_A, staffId: 'staff_A', net: 50000 });
    await assertSucceeds(DBS.teacherA.collection('salarySlips').doc(id).get());
  });

  // ══ `students` SELF-SERVICE MUST SURVIVE ════════════════════════════════
  //
  // This is the whole risk of the wave. If Firestore did NOT OR the allow
  // rules, denying the blanket arm would lock every student out of their own
  // password reset. Asserted, not assumed.

  describe('students self-service (the one real client write path)', () => {
    const docId = `${SCHOOL_A}_${STUDENT_A}`;

    beforeEach(async () => {
      await seedDoc('students', docId, studentDoc(SCHOOL_A, STUDENT_A));
    });

    test('a student CAN still clear their own mustChangePassword flag', async () => {
      await assertSucceeds(
        DBS.studentA.collection('students').doc(docId)
          .update({ mustChangePassword: false, updatedAt: '2026-08-21T00:00:00Z' })
      );
    });

    test('a student CAN still set their own prefLang', async () => {
      await assertSucceeds(
        DBS.studentA.collection('students').doc(docId)
          .update({ prefLang: 'hi', updatedAt: '2026-08-21T00:00:00Z' })
      );
    });

    // ── ADVERSARIAL: try to break the intended workflow ──────────────────

    /**
     * The two clauses are deliberately separate so neither allowlist widens.
     * Sending BOTH fields matches NEITHER `hasOnly` set and must fail — if it
     * succeeded, the narrow mustChangePassword allowlist would be bypassable
     * by appending a second permitted field.
     */
    test('a student CANNOT combine both self-service fields in one write', async () => {
      await assertFails(
        DBS.studentA.collection('students').doc(docId)
          .update({ mustChangePassword: false, prefLang: 'hi', updatedAt: 'x' })
      );
    });

    test('a student CANNOT change their own class (privilege escalation)', async () => {
      await assertFails(
        DBS.studentA.collection('students').doc(docId).update({ className: 'XII' })
      );
    });

    test('a student CANNOT smuggle a field alongside a permitted one', async () => {
      await assertFails(
        DBS.studentA.collection('students').doc(docId)
          .update({ mustChangePassword: false, updatedAt: 'x', status: 'Alumni' })
      );
    });

    /** Re-homing: `schoolId` is not in either allowlist. */
    test('a student CANNOT re-home their own record to another school', async () => {
      await assertFails(
        DBS.studentA.collection('students').doc(docId)
          .update({ schoolId: SCHOOL_B, updatedAt: 'x' })
      );
    });

    test('a student CANNOT write ANOTHER student\'s record', async () => {
      const other = `${SCHOOL_A}_${STUDENT_A2}`;
      await seedDoc('students', other, studentDoc(SCHOOL_A, STUDENT_A2));

      await assertFails(
        DBS.studentA.collection('students').doc(other)
          .update({ mustChangePassword: false, updatedAt: 'x' })
      );
    });

    test('a student CANNOT write a record in another school', async () => {
      const other = `${SCHOOL_B}_${STUDENT_A}`;
      await seedDoc('students', other, studentDoc(SCHOOL_B, STUDENT_A));

      await assertFails(
        DBS.studentA.collection('students').doc(other)
          .update({ mustChangePassword: false, updatedAt: 'x' })
      );
    });

    /**
     * DELETE has no allow arm at all now — the self-service clauses are
     * `allow update` only. A student must not be able to delete their own
     * record, which would be an obvious way to escape a disciplinary flag.
     */
    test('a student CANNOT delete their own record', async () => {
      await assertFails(DBS.studentA.collection('students').doc(docId).delete());
    });

    /**
     * CREATE: the self-service clauses dereference `resource.data.studentId`,
     * which is null on create. A student must not be able to mint a record.
     */
    test('a student CANNOT create a new student record', async () => {
      await assertFails(
        DBS.studentA.collection('students').doc(`${SCHOOL_A}_STU9999`)
          .set(studentDoc(SCHOOL_A, 'STU9999'))
      );
    });

    /** The prefLang clause's own type and length guards. */
    test('a student CANNOT set a prefLang longer than 8 characters', async () => {
      await assertFails(
        DBS.studentA.collection('students').doc(docId)
          .update({ prefLang: 'averyverylonglanguagecode', updatedAt: 'x' })
      );
    });

    test('a student CANNOT set a non-string prefLang', async () => {
      await assertFails(
        DBS.studentA.collection('students').doc(docId)
          .update({ prefLang: 12345, updatedAt: 'x' })
      );
    });

    test('same-school read of a student record still works', async () => {
      await assertSucceeds(DBS.teacherA.collection('students').doc(docId).get());
    });
  });
});
