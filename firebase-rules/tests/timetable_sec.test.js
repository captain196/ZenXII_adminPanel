/**
 * timetables / timetableSettings / substitutes — Firestore Rules tests.
 *
 * Remediation P0 #4 (2026-08-12). Pre-fix contract was:
 *     match /timetables/{docId}        { allow read: isSameSchool(); allow write: isStaff(); }
 *     match /timetableSettings/{docId} { allow read: isAuth();       allow write: isAdmin(); }
 *     match /substitutes/{docId}       { allow read: isAuth();       allow write: isAdmin(); }
 *
 * `isStaff()` / `isAdmin()` only prove the caller is staff/admin of SOME active
 * tenant — neither inspects resource.data.schoolId or
 * request.resource.data.schoolId. So School A staff could author or overwrite
 * School B's timetable, and could rewrite an existing doc's schoolId to move it
 * between tenants. Post-fix contract splits write into create/update/delete and
 * binds each arm:
 *     create: isSameSchoolWrite()                  — pins the INCOMING schoolId
 *     update: isSameSchool() && isSameSchoolWrite() — pins BOTH old and new
 *     delete: isSameSchool()                        — request.resource is null here
 *
 * The update pair is the load-bearing one: it is what blocks the
 * "change an existing document's school association" escalation.
 *
 * Run: cd firebase-rules/tests && npm test
 *   (or: npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *        "npx jest timetable_sec --runInBand --forceExit")
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

const SCHOOL = 'SCH_TEST_TT_001';
const OTHER_SCHOOL = 'SCH_TEST_TT_002';
const SESSION = '2026-27';

const TT_A = `${SCHOOL}_${SESSION}_Class_8th_Section_A_Monday`;
const TT_OTHER = `${OTHER_SCHOOL}_${SESSION}_Class_8th_Section_A_Monday`;
const SET_A = `${SCHOOL}_${SESSION}`;
const SET_OTHER = `${OTHER_SCHOOL}_${SESSION}`;
const SUB_A = `${SCHOOL}_SUB_0001`;
const SUB_OTHER = `${OTHER_SCHOOL}_SUB_0001`;

const STAFF = 'STA_TT1';
const OTHER_STAFF = 'STA_TT2';
const ADMIN = 'STA_TT_ADM1';
const OTHER_ADMIN = 'STA_TT_ADM2';
const PARENT = 'STU_TT_P1';

let env;

function asStaff(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'Teacher', user_id: uid }).firestore();
}
function asAdmin(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'admin', user_id: uid }).firestore();
}
function asParent(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'Parent', student_id: uid }).firestore();
}
function asAnon() {
  return env.unauthenticatedContext().firestore();
}

const ttDoc = (school) => ({
  schoolId: school, session: SESSION, className: 'Class 8th',
  section: 'Section A', day: 'Monday', periods: [],
});

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    // tenantActive() requires an active schoolControl doc per school —
    // without it EVERY assertion fails for the wrong reason.
    for (const s of [SCHOOL, OTHER_SCHOOL]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test School` });
    }
    await db.doc(`timetables/${TT_A}`).set(ttDoc(SCHOOL));
    await db.doc(`timetables/${TT_OTHER}`).set(ttDoc(OTHER_SCHOOL));
    await db.doc(`timetableSettings/${SET_A}`).set({ schoolId: SCHOOL, session: SESSION, periodsPerDay: 8 });
    await db.doc(`timetableSettings/${SET_OTHER}`).set({ schoolId: OTHER_SCHOOL, session: SESSION, periodsPerDay: 8 });
    await db.doc(`substitutes/${SUB_A}`).set({ schoolId: SCHOOL, session: SESSION, date: '2026-08-12', absentTeacherId: 'STA_X' });
    await db.doc(`substitutes/${SUB_OTHER}`).set({ schoolId: OTHER_SCHOOL, session: SESSION, date: '2026-08-12', absentTeacherId: 'STA_Y' });
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
  await seedWorld();
}, 120000);
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await seedWorld(); });

describe('timetables — P0 #4 tenant binding on write', () => {
  // TT-D1 CLOSED 2026-08-31 (Master Timetable switchover, gate B). This is
  // one of FIVE assertions that had to change WITH the rule, deliberately:
  // three here (create, update, delete) and two in homework_authorship.test.js.
  //
  // It was correct for its own programme: P0 #4 bound a staff write to the
  // caller's own tenant, and same-school staff creating a timetable was the
  // legitimate case it protected. `timetables` has since stopped being a
  // collection anyone authors — it is the projection of the published
  // canonical version, written through the service account, which bypasses
  // rules. So there is no longer a legitimate client write to permit.
  //
  // P0 #4's security property is preserved, not undone: `false` denies a
  // strict superset of what the tenant-bound arms denied. Every cross-tenant
  // assertion below still passes unchanged.
  test('no client may create a timetable, not even same-school staff', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`timetables/${SCHOOL}_${SESSION}_Class_9th_Section_B_Tuesday`).set(ttDoc(SCHOOL))
    );
  });

  test('THE FINDING: staff CANNOT create a timetable stamped with another school id', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`timetables/${OTHER_SCHOOL}_${SESSION}_Class_9th_Section_B_Tuesday`)
        .set(ttDoc(OTHER_SCHOOL))
    );
  });

  test('ESCALATION: staff CANNOT rewrite an existing doc\'s schoolId to another tenant', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`timetables/${TT_A}`).set({ schoolId: OTHER_SCHOOL }, { merge: true })
    );
  });

  test("cross-school staff CANNOT update another school's timetable", async () => {
    await assertFails(
      asStaff(OTHER_STAFF, OTHER_SCHOOL).doc(`timetables/${TT_A}`).set({ periods: ['hijacked'] }, { merge: true })
    );
  });

  test("cross-school staff CANNOT delete another school's timetable", async () => {
    await assertFails(asStaff(OTHER_STAFF, OTHER_SCHOOL).doc(`timetables/${TT_A}`).delete());
  });

  test('create with NO schoolId field is denied (isSameSchoolWrite size() guard)', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`timetables/${SCHOOL}_${SESSION}_Class_10th_Section_A_Friday`)
        .set({ session: SESSION, className: 'Class 10th', section: 'Section A', day: 'Friday' })
    );
  });

  test('create with EMPTY schoolId is denied', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`timetables/${SCHOOL}_${SESSION}_Class_10th_Section_C_Friday`)
        .set({ ...ttDoc(SCHOOL), schoolId: '' })
    );
  });

  // TT-D1 CLOSED — the other two of the three assertions in this file that
  // asserted a legitimate same-school client write. Same reasoning as the create case above: the projection is
  // the only writer and it goes through the service account, so a same-school
  // staff UPDATE or DELETE of a timetable is no longer a case worth
  // protecting — after the switchover it is precisely how the derived copy
  // forks away from the canonical version it mirrors.
  test('no client may update a timetable, not even same-school staff', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`timetables/${TT_A}`).set({ periods: ['P1'] }, { merge: true })
    );
  });

  test('no client may delete a timetable, not even same-school staff', async () => {
    await assertFails(asStaff(STAFF, SCHOOL).doc(`timetables/${TT_A}`).delete());
  });

  test('unauthenticated CANNOT write', async () => {
    await assertFails(asAnon().doc(`timetables/${SCHOOL}_ANON`).set(ttDoc(SCHOOL)));
  });

  test('parent (non-staff) CANNOT write', async () => {
    await assertFails(
      asParent(PARENT, SCHOOL).doc(`timetables/${SCHOOL}_PARENT`).set(ttDoc(SCHOOL))
    );
  });

  test('same-school read still works (non-regression)', async () => {
    await assertSucceeds(asStaff(STAFF, SCHOOL).doc(`timetables/${TT_A}`).get());
  });

  test("cross-school read is still denied (non-regression)", async () => {
    await assertFails(asStaff(OTHER_STAFF, OTHER_SCHOOL).doc(`timetables/${TT_A}`).get());
  });
});

describe('timetableSettings — P0 #4 tenant binding on write', () => {
  test('same-school admin CAN create settings for their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(`timetableSettings/${SCHOOL}_2027-28`)
        .set({ schoolId: SCHOOL, session: '2027-28', periodsPerDay: 7 })
    );
  });

  test('THE FINDING: admin CANNOT create settings stamped with another school id', async () => {
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(`timetableSettings/${OTHER_SCHOOL}_2027-28`)
        .set({ schoolId: OTHER_SCHOOL, session: '2027-28', periodsPerDay: 7 })
    );
  });

  test("cross-school admin CANNOT update another school's settings", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(`timetableSettings/${SET_A}`)
        .set({ periodsPerDay: 99 }, { merge: true })
    );
  });

  test('ESCALATION: admin CANNOT rewrite settings schoolId', async () => {
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(`timetableSettings/${SET_A}`).set({ schoolId: OTHER_SCHOOL }, { merge: true })
    );
  });

  test('non-admin staff CANNOT write settings (admin arm intact)', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`timetableSettings/${SET_A}`).set({ periodsPerDay: 6 }, { merge: true })
    );
  });

  test('same-school admin CAN update their own settings (non-regression)', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(`timetableSettings/${SET_A}`).set({ periodsPerDay: 9 }, { merge: true })
    );
  });
});

describe('substitutes — P0 #4 tenant binding on write', () => {
  test('same-school admin CAN create a substitute for their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(`substitutes/${SCHOOL}_SUB_0002`)
        .set({ schoolId: SCHOOL, session: SESSION, date: '2026-08-13', absentTeacherId: 'STA_Z' })
    );
  });

  test('THE FINDING: admin CANNOT create a substitute stamped with another school id', async () => {
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(`substitutes/${OTHER_SCHOOL}_SUB_0002`)
        .set({ schoolId: OTHER_SCHOOL, session: SESSION, date: '2026-08-13', absentTeacherId: 'STA_Z' })
    );
  });

  test("cross-school admin CANNOT update another school's substitute", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(`substitutes/${SUB_A}`)
        .set({ absentTeacherId: 'HIJACK' }, { merge: true })
    );
  });

  test("cross-school admin CANNOT delete another school's substitute", async () => {
    await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(`substitutes/${SUB_A}`).delete());
  });

  test('non-admin staff CANNOT write a substitute (admin arm intact)', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`substitutes/${SUB_A}`).set({ absentTeacherId: 'X' }, { merge: true })
    );
  });

  test('same-school admin CAN delete their own substitute (non-regression)', async () => {
    await assertSucceeds(asAdmin(ADMIN, SCHOOL).doc(`substitutes/${SUB_A}`).delete());
  });
});

describe('P0 #4 — suspended tenant still fails closed', () => {
  test('writes denied when the tenant lifecycle is suspended', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc(`schoolControl/${SCHOOL}`).set({
        schoolId: SCHOOL, lifecycle: { state: 'suspended', computedAt: Date.now(), reason: 'nonpayment' },
      });
    });
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`timetables/${SCHOOL}_${SESSION}_Class_1st_Section_A_Monday`).set(ttDoc(SCHOOL))
    );
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(`substitutes/${SCHOOL}_SUB_SUSPENDED`)
        .set({ schoolId: SCHOOL, session: SESSION, date: '2026-08-14', absentTeacherId: 'STA_Q' })
    );
  });
});

/**
 * CANONICAL MASTER TIMETABLE ARCHITECTURE — server-only.
 *
 * timetablePlans / timetableVersions / timetableVersionDays are the source of
 * truth. They are written exclusively by the PHP backend through the service
 * account, which bypasses rules entirely, and NO client reads them: the Teacher
 * and Parent apps read `timetables`, which is now a derived compatibility
 * projection of a published version.
 *
 * The risk being closed is not cross-tenant access — it is that these
 * collections hold DRAFTS and CANDIDATES. A generated candidate is unfinished,
 * unvalidated work that nobody has approved. Any client read at all would let a
 * teacher see a timetable that may never be published, and treat it as theirs.
 *
 * So the minimum required client access is NONE, and these tests prove it is
 * actually none — for staff, for admins, and for the school that owns the data.
 */
describe('canonical timetable architecture — no client access at all', () => {
  const PLAN = `${SCHOOL}_${SESSION}_REGULAR`;
  const VERSION = `${SCHOOL}_${SESSION}_REGULAR_V1`;
  const DAY = `${SCHOOL}_${SESSION}_REGULAR_V1_Class 8th_Section A_Monday`;

  beforeEach(async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      const db = ctx.firestore();
      await db.doc(`schoolControl/${SCHOOL}`).set({
        schoolId: SCHOOL,
        lifecycle: { state: 'active', computedAt: Date.now() },
      });
      await db.doc(`timetablePlans/${PLAN}`).set({
        schoolId: SCHOOL, session: SESSION, planId: 'REGULAR', kind: 'regular',
      });
      await db.doc(`timetableVersions/${VERSION}`).set({
        schoolId: SCHOOL, session: SESSION, planId: 'REGULAR',
        versionId: 'V1', status: 'published', effectiveFrom: '2026-06-01',
      });
      await db.doc(`timetableVersionDays/${DAY}`).set({
        schoolId: SCHOOL, session: SESSION, planId: 'REGULAR',
        versionId: 'V1', classSection: 'Class 8th/Section A', day: 'Monday',
        periods: [],
      });
    });
  });

  const paths = () => [
    [`timetablePlans/${PLAN}`, 'plans'],
    [`timetableVersions/${VERSION}`, 'versions'],
    [`timetableVersionDays/${DAY}`, 'days'],
  ];

  test('same-school staff CANNOT read any canonical timetable document', async () => {
    for (const [p] of paths()) {
      await assertFails(asStaff(STAFF, SCHOOL).doc(p).get());
    }
  });

  test('same-school admin CANNOT read any canonical timetable document', async () => {
    for (const [p] of paths()) {
      await assertFails(asAdmin(ADMIN, SCHOOL).doc(p).get());
    }
  });

  test('even a PUBLISHED version is not client-readable yet', async () => {
    // Published is the only status that would ever be exposed, and it is not
    // exposed today. Granting it belongs with the Teacher app migration, not
    // before it — otherwise the apps have two places to read a timetable from.
    await assertFails(asStaff(STAFF, SCHOOL).doc(`timetableVersions/${VERSION}`).get());
  });

  test('nobody can write a canonical timetable document from a client', async () => {
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(`timetableVersions/${SCHOOL}_${SESSION}_REGULAR_V9`)
        .set({ schoolId: SCHOOL, session: SESSION, status: 'published' })
    );
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(`timetableVersionDays/${DAY}`).set({ periods: [] }, { merge: true })
    );
    await assertFails(asAdmin(ADMIN, SCHOOL).doc(`timetablePlans/${PLAN}`).delete());
  });

  test('another school gets nothing either', async () => {
    for (const [p] of paths()) {
      await assertFails(asStaff(STAFF, OTHER_SCHOOL).doc(p).get());
    }
  });

  test('a client cannot promote a draft to published', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc(`timetableVersions/${SCHOOL}_${SESSION}_REGULAR_D1`).set({
        schoolId: SCHOOL, session: SESSION, planId: 'REGULAR',
        versionId: 'D1', status: 'draft', effectiveFrom: '2026-06-01',
      });
    });
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(`timetableVersions/${SCHOOL}_${SESSION}_REGULAR_D1`)
        .set({ status: 'published' }, { merge: true })
    );
  });
});
