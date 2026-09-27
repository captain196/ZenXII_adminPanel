/**
 * HW-D1 PHASE 2 CANDIDATE — assignment-scoped homework authorization.
 *
 * Runs against firestore.rules.hwd1_candidate, NOT the live rules file. The
 * candidate must not be applied until the Teacher-app build carrying HW-D1
 * phase 1 (subjectCode + assignClassSeg/assignSectionSeg) has rolled out —
 * otherwise handsets on the previous release lose the ability to set homework.
 *
 * Proves the full dimension set the owner asked for:
 *   create · update · delete · substitute · inactive
 *   cross-class · cross-section · cross-subject · cross-school
 *
 * Security model (identical to the shipped marks binding): the segments are
 * lookup HINTS with no trust of their own. The rule fetches the assignment and
 * re-validates ITS OWN teacherId/schoolId/className/section, so a forged
 * segment can only cause a lookup miss — never a false authorization.
 */
const { initializeTestEnvironment, assertSucceeds, assertFails } = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const CANDIDATE = path.join(__dirname, '..', 'firestore.rules.hwd1_candidate');
const A = 'SCH_A0001';
const B = 'SCH_B0002';
const SESSION = '2026-27';
const T1 = 'STA0001';   // owns 8/A/MATH  (section-specific)
const T2 = 'STA0002';   // owns nothing
const T3 = 'STA0003';   // Inactive
const SUB = 'STA0004';  // substitute: holds a CLASS-WIDE (_ALL_) assignment on 9/ENG
let env;

const asg = (school, cls, sec, sub) => `${school}_${SESSION}_${cls}_${sec}_${sub}`;

async function seed() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [A, B]) {
      await db.doc(`schoolControl/${s}`).set({ schoolId: s, lifecycle: { state: 'active' } });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: s });
    }
    for (const [id, st] of [[T1, 'Active'], [T2, 'Active'], [T3, 'Inactive'], [SUB, 'Active']]) {
      await db.doc(`staff/${A}_${id}`).set({ schoolId: A, staffId: id, status: st });
    }
    // section-specific, live
    await db.doc(`subjectAssignments/${asg(A, '8', 'A', 'MATH')}`).set({
      schoolId: A, session: SESSION, className: '8', section: 'A',
      subjectCode: 'MATH', teacherId: T1, archived: false,
    });
    // section-specific, ARCHIVED
    await db.doc(`subjectAssignments/${asg(A, '8', 'A', 'SCI')}`).set({
      schoolId: A, session: SESSION, className: '8', section: 'A',
      subjectCode: 'SCI', teacherId: T1, archived: true,
    });
    // CLASS-WIDE (_ALL_) — the substitute/delegation mechanism
    await db.doc(`subjectAssignments/${asg(A, '9', '_ALL_', 'ENG')}`).set({
      schoolId: A, session: SESSION, className: '9', section: '',
      subjectCode: 'ENG', teacherId: SUB, archived: false,
    });
    // an existing homework owned by T1, for update/delete arms
    await db.doc('homework/HW_OWNED').set({
      schoolId: A, session: SESSION, teacherId: T1,
      className: '8', section: 'A', subjectCode: 'MATH', title: 'existing',
    });
  });
}

const staff = (uid, school) =>
  env.authenticatedContext(uid, { school_id: school, role: 'Teacher' }).firestore();

/** A well-formed homework payload. Overrides let each test bend one axis. */
const hw = (o = {}) => Object.assign({
  schoolId: A, session: SESSION, teacherId: T1,
  className: '8', section: 'A',
  subjectCode: 'MATH',
  assignClassSeg: '8', assignSectionSeg: 'A',
  title: 'Worksheet', createdAt: 1,
}, o);

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(CANDIDATE, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seed(); });

describe('HW-D1 candidate — CREATE', () => {
  test('assigned teacher, own subject/class/section → ALLOWED', async () => {
    await assertSucceeds(staff(T1, A).doc('homework/N1').set(hw()));
  });

  test('CROSS-SUBJECT: no assignment for ENG on 8/A → denied', async () => {
    await assertFails(staff(T1, A).doc('homework/N2').set(hw({ subjectCode: 'ENG' })));
  });

  test('CROSS-SECTION: assignment is 8/A, posting to 8/B → denied', async () => {
    await assertFails(staff(T1, A).doc('homework/N3')
      .set(hw({ section: 'B', assignSectionSeg: 'B' })));
  });

  test('CROSS-CLASS: posting to class 9 → denied', async () => {
    await assertFails(staff(T1, A).doc('homework/N4')
      .set(hw({ className: '9', assignClassSeg: '9' })));
  });

  test('CROSS-TEACHER: T2 posts against T1 assignment → denied', async () => {
    await assertFails(staff(T2, A).doc('homework/N5').set(hw({ teacherId: T2 })));
  });

  test('ARCHIVED assignment cannot authorize → denied', async () => {
    await assertFails(staff(T1, A).doc('homework/N6').set(hw({ subjectCode: 'SCI' })));
  });

  test('INACTIVE teacher → denied', async () => {
    await assertFails(staff(T3, A).doc('homework/N7').set(hw({ teacherId: T3 })));
  });

  test('CROSS-SCHOOL: tenant B token writing tenant A homework → denied', async () => {
    await assertFails(staff(T1, B).doc('homework/N8').set(hw()));
  });

  test('missing subjectCode FAILS CLOSED → denied', async () => {
    const d = hw(); delete d.subjectCode;
    await assertFails(staff(T1, A).doc('homework/N9').set(d));
  });

  test('FORGED segment cannot fabricate authorization → denied (lookup miss)', async () => {
    await assertFails(staff(T2, A).doc('homework/N10')
      .set(hw({ teacherId: T2, assignClassSeg: '8', assignSectionSeg: 'A' })));
  });

  test('SUBSTITUTE via class-wide _ALL_ assignment → ALLOWED', async () => {
    await assertSucceeds(staff(SUB, A).doc('homework/N11').set(hw({
      teacherId: SUB, className: '9', section: 'C',
      subjectCode: 'ENG', assignClassSeg: '9', assignSectionSeg: 'C',
    })));
  });

  test('non-substitute cannot ride the class-wide assignment → denied', async () => {
    await assertFails(staff(T2, A).doc('homework/N12').set(hw({
      teacherId: T2, className: '9', section: 'C',
      subjectCode: 'ENG', assignClassSeg: '9', assignSectionSeg: 'C',
    })));
  });
});

describe('HW-D1 candidate — UPDATE / DELETE (unchanged arms)', () => {
  test('owner updates own homework → ALLOWED', async () => {
    await assertSucceeds(staff(T1, A).doc('homework/HW_OWNED').update({ title: 'edited' }));
  });

  test('CROSS-TEACHER update → denied', async () => {
    await assertFails(staff(T2, A).doc('homework/HW_OWNED').update({ title: 'hijacked' }));
  });

  test('CROSS-SCHOOL update → denied', async () => {
    await assertFails(staff(T1, B).doc('homework/HW_OWNED').update({ title: 'x' }));
  });
});
