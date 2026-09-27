/**
 * HW-D2 / TT-D1 — academic write-arm hardening.
 *
 * HW-D2: `homework` create was
 *     allow create: if isStaff() && isSameSchoolWrite() && hasCapability('Homework');
 * with NO binding between the document's teacherId and the caller. Any staff
 * member could therefore create homework ATTRIBUTED TO ANOTHER TEACHER —
 * authorship forgery. The update arm already enforces
 * `resource.data.teacherId == request.auth.uid || isAdmin()`, so create was
 * the odd one out.
 *
 * TT-D1: `timetables` granted isStaff() create/update/DELETE even though the
 * block's own comment states "No client writes this collection — Teacher and
 * Parent apps are read-only here". Verified: both Teacher-app usages are reads
 * (queryDocumentsAs / .get()) and the Parent app has none. So any staff
 * account — driver, peon, security — could delete a school's timetable via the
 * client SDK. The two neighbouring blocks (timetableSettings, substitutes)
 * already require isAdmin(); this brings timetables in line.
 */
const { initializeTestEnvironment, assertSucceeds, assertFails } = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');
const SCH_A = 'SCH_A0001';
const SCH_B = 'SCH_B0002';
let env;

async function seed() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCH_A, SCH_B]) {
      await db.doc(`schoolControl/${s}`).set({ schoolId: s, lifecycle: { state: 'active' } });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: s });
    }
    await db.doc(`staff/${SCH_A}_STA0001`).set({ schoolId: SCH_A, staffId: 'STA0001', status: 'Active' });
    await db.doc(`staff/${SCH_A}_STA0002`).set({ schoolId: SCH_A, staffId: 'STA0002', status: 'Active' });
    await db.doc(`timetables/${SCH_A}_2026-27_8_A_Mon`).set({ schoolId: SCH_A, session: '2026-27', periods: [] });
  });
}

const asStaff = (uid, school, role = 'Teacher') =>
  env.authenticatedContext(uid, { school_id: school, role }).firestore();

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seed(); });

const hw = (teacherId, school = SCH_A) => ({
  schoolId: school,
  teacherId,
  className: '8',
  section: 'A',
  title: 'Algebra worksheet',
  createdAt: 1,
});

describe('HW-D2 — homework authorship binding', () => {
  test('a teacher creates homework in their OWN name → allowed', async () => {
    await assertSucceeds(
      asStaff('STA0001', SCH_A).doc('homework/HW1').set(hw('STA0001'))
    );
  });

  test('DEFECT: a teacher creates homework attributed to ANOTHER teacher → must be denied', async () => {
    await assertFails(
      asStaff('STA0001', SCH_A).doc('homework/HW2').set(hw('STA0002'))
    );
  });

  test('cross-tenant homework create → denied', async () => {
    await assertFails(
      asStaff('STA0001', SCH_A).doc('homework/HW3').set(hw('STA0001', SCH_B))
    );
  });

  test('a parent cannot create homework at all', async () => {
    await assertFails(
      env.authenticatedContext('P1', { school_id: SCH_A, role: 'Parent' })
        .firestore().doc('homework/HW4').set(hw('P1'))
    );
  });
});

describe('TT-D1 — timetables are not client-writable', () => {
  const ttPath = `timetables/${SCH_A}_2026-27_8_A_Mon`;

  // TT-D1 CLOSED 2026-08-31 (Master Timetable switchover, gate B). These two
  // recorded the permissive behaviour while it stood; the rule is now
  // `allow write: if false`, so they assert the closure instead.
  //
  // The collection is the projection of the published canonical timetable,
  // written through the service account, which bypasses rules. No client
  // writes it: both apps are read-only, the legacy admin editors refuse in
  // PHP, and the rollover tool refuses projected documents.
  test('TT-D1 CLOSED: a peon can no longer DELETE a timetable', async () => {
    await assertFails(asStaff('STA0001', SCH_A, 'Peon').doc(ttPath).delete());
  });

  test('TT-D1 CLOSED: ordinary staff can no longer CREATE a timetable', async () => {
    await assertFails(
      asStaff('STA0001', SCH_A, 'Teacher')
        .doc(`timetables/${SCH_A}_2026-27_9_B_Tue`)
        .set({ schoolId: SCH_A, session: '2026-27', periods: [] })
    );
  });

  test('staff may still READ the timetable (apps depend on it)', async () => {
    await assertSucceeds(asStaff('STA0001', SCH_A).doc(ttPath).get());
  });

  test('cross-tenant timetable read → denied', async () => {
    await assertFails(asStaff('STA9999', SCH_B).doc(ttPath).get());
  });
});
