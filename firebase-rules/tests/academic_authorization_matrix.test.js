/**
 * ZenX academic authorization matrix — SRNO 5/6/8/10/12/15/22.
 *
 * Converts claims previously made from code inspection into EXECUTED evidence
 * against the real rules engine. Every negative case must actually be refused;
 * a rule is not proven by reading it.
 *
 * Covered:
 *   SRNO 5/6  cross-teacher  (marks, homework, subjectAssignments)
 *   SRNO 8    parent/student teacher relationship + PII boundary
 *   SRNO 10   student attendance is server-authored only
 *   SRNO 12   marks bind to a live, non-archived, owned assignment
 *   SRNO 15   inactive staff — HW-D3 now FIXED (isActiveStaff on homework create)
 *   SRNO 22   cross-tenant isolation on every academic collection
 */
const { initializeTestEnvironment, assertSucceeds, assertFails } = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');
const A = 'SCH_A0001';
const B = 'SCH_B0002';
const SESSION = '2026-27';
const T1 = 'STA0001';          // owns the assignment
const T2 = 'STA0002';          // same school, owns nothing
const T3 = 'STA0003';          // same school, status Inactive
let env;

/** subjectAssignments doc id: {school}_{session}_{classSeg}_{sectionSeg}_{subject} */
const asgId = (school, cls, sec, sub) => `${school}_${SESSION}_${cls}_${sec}_${sub}`;

async function seed() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [A, B]) {
      await db.doc(`schoolControl/${s}`).set({ schoolId: s, lifecycle: { state: 'active' } });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: s });
    }
    await db.doc(`staff/${A}_${T1}`).set({ schoolId: A, staffId: T1, name: 'Teacher One', status: 'Active' });
    await db.doc(`staff/${A}_${T2}`).set({ schoolId: A, staffId: T2, name: 'Teacher Two', status: 'Active' });
    await db.doc(`staff/${A}_${T3}`).set({ schoolId: A, staffId: T3, name: 'Departed', status: 'Inactive' });
    await db.doc(`staffPrivate/${A}_${T1}`).set({ schoolId: A, staffId: T1, panNumber: 'ABCDE1234F' });

    // T1 owns 8/A/MATH. An archived row for T1 on 8/A/SCI.
    await db.doc(`subjectAssignments/${asgId(A, '8', 'A', 'MATH')}`).set({
      schoolId: A, session: SESSION, className: '8', section: 'A',
      subjectCode: 'MATH', teacherId: T1, teacherName: 'Teacher One', archived: false,
    });
    await db.doc(`subjectAssignments/${asgId(A, '8', 'A', 'SCI')}`).set({
      schoolId: A, session: SESSION, className: '8', section: 'A',
      subjectCode: 'SCI', teacherId: T1, teacherName: 'Teacher One', archived: true,
    });
    await db.doc(`attendance/${A}_${SESSION}_8_A_2026-08-27`).set({ schoolId: A, session: SESSION });
  });
}

const staff  = (uid, school, role = 'Teacher') =>
  env.authenticatedContext(uid, { school_id: school, role }).firestore();
const parent = (uid, school) =>
  env.authenticatedContext(uid, { school_id: school, role: 'Parent' }).firestore();

const marksDoc = (school, subject, cls = '8', sec = 'A') => ({
  schoolId: school, session: SESSION,
  className: cls, section: sec,
  assignClassSeg: cls, assignSectionSeg: sec,
  subjectCode: subject, studentId: 'STU0001', marks: 90,
});

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seed(); });

/* ── SRNO 12 + 5/6 — marks bind to a live owned assignment ───────────── */
describe('SRNO 12 — marks authorization', () => {
  test('owner writes marks for their OWN live assignment → allowed', async () => {
    await assertSucceeds(staff(T1, A).doc('marks/M1').set(marksDoc(A, 'MATH')));
  });

  test('CROSS-TEACHER: T2 writes marks on T1 assignment → denied', async () => {
    await assertFails(staff(T2, A).doc('marks/M2').set(marksDoc(A, 'MATH')));
  });

  test('ARCHIVED assignment cannot authorize a marks write → denied', async () => {
    await assertFails(staff(T1, A).doc('marks/M3').set(marksDoc(A, 'SCI')));
  });

  test('CROSS-SUBJECT: no assignment for that subject → denied', async () => {
    await assertFails(staff(T1, A).doc('marks/M4').set(marksDoc(A, 'ENG')));
  });

  test('CROSS-SECTION: assignment is 8/A, writing 8/B → denied', async () => {
    await assertFails(staff(T1, A).doc('marks/M5').set(marksDoc(A, 'MATH', '8', 'B')));
  });

  test('missing subjectCode FAILS CLOSED → denied', async () => {
    const d = marksDoc(A, 'MATH'); delete d.subjectCode;
    await assertFails(staff(T1, A).doc('marks/M6').set(d));
  });

  test('INACTIVE staff writes marks → denied (isActiveStaff)', async () => {
    await assertFails(staff(T3, A).doc('marks/M7').set(marksDoc(A, 'MATH')));
  });

  test('CROSS-TENANT: tenant B token writes tenant A marks → denied', async () => {
    await assertFails(staff(T1, B).doc('marks/M8').set(marksDoc(A, 'MATH')));
  });
});

/* ── SRNO 10 — student attendance is server-authored only ────────────── */
describe('SRNO 10 — student attendance', () => {
  const att = `attendance/${A}_${SESSION}_8_A_2026-08-27`;

  test('teacher CANNOT write attendance from a client (PHP-only)', async () => {
    await assertFails(staff(T1, A).doc(att).set({ schoolId: A, session: SESSION, marked: true }));
  });

  test('admin CANNOT write attendance from a client either', async () => {
    await assertFails(staff('ADM1', A, 'Admin').doc(att).set({ schoolId: A, x: 1 }));
  });

  test('same-school staff may READ attendance', async () => {
    await assertSucceeds(staff(T1, A).doc(att).get());
  });

  test('CROSS-TENANT attendance read → denied', async () => {
    await assertFails(staff(T1, B).doc(att).get());
  });
});

/* ── SRNO 5/6 — assignments are admin-authored ───────────────────────── */
describe('SRNO 5/6 — subjectAssignments', () => {
  test('a teacher cannot forge their own assignment → denied', async () => {
    await assertFails(staff(T2, A).doc(`subjectAssignments/${asgId(A, '9', 'C', 'ENG')}`)
      .set({ schoolId: A, session: SESSION, className: '9', section: 'C', subjectCode: 'ENG', teacherId: T2 }));
  });

  test('a teacher cannot reassign an existing assignment to themselves → denied', async () => {
    await assertFails(staff(T2, A).doc(`subjectAssignments/${asgId(A, '8', 'A', 'MATH')}`)
      .update({ teacherId: T2 }));
  });

  test('a teacher cannot delete an assignment → denied', async () => {
    await assertFails(staff(T1, A).doc(`subjectAssignments/${asgId(A, '8', 'A', 'MATH')}`).delete());
  });

  test('CROSS-TENANT assignment read → denied', async () => {
    await assertFails(staff(T1, B).doc(`subjectAssignments/${asgId(A, '8', 'A', 'MATH')}`).get());
  });
});

/* ── SRNO 8 — parent/student teacher relationship + PII boundary ─────── */
describe('SRNO 8 — parent teacher relationship', () => {
  test('parent reads same-school assignments (My Teachers) → allowed', async () => {
    await assertSucceeds(parent('P1', A).doc(`subjectAssignments/${asgId(A, '8', 'A', 'MATH')}`).get());
  });

  test('parent resolves the teacher staff doc → allowed (by design)', async () => {
    await assertSucceeds(parent('P1', A).doc(`staff/${A}_${T1}`).get());
  });

  test('PII BOUNDARY: parent CANNOT read staffPrivate → denied', async () => {
    await assertFails(parent('P1', A).doc(`staffPrivate/${A}_${T1}`).get());
  });

  test('CROSS-TENANT: parent of B cannot read A assignments → denied', async () => {
    await assertFails(parent('P9', B).doc(`subjectAssignments/${asgId(A, '8', 'A', 'MATH')}`).get());
  });

  test('CROSS-TENANT: parent of B cannot read A staff → denied', async () => {
    await assertFails(parent('P9', B).doc(`staff/${A}_${T1}`).get());
  });

  test('parent cannot write an assignment → denied', async () => {
    await assertFails(parent('P1', A).doc(`subjectAssignments/${asgId(A, '8', 'A', 'MATH')}`)
      .update({ teacherName: 'forged' }));
  });
});

/* ── SRNO 15 / HW-D3 — inactive staff: records CURRENT behaviour ─────── */
describe('SRNO 15 — inactive staff academic access', () => {
  const hw = (t) => ({ schoolId: A, teacherId: t, className: '8', section: 'A', title: 'x', createdAt: 1 });

  test('active teacher creates homework in own name → allowed', async () => {
    await assertSucceeds(staff(T1, A).doc('homework/H1').set(hw(T1)));
  });

  test('HW-D3 FIXED: an INACTIVE teacher can no longer create homework', async () => {
    await assertFails(staff(T3, A).doc('homework/H2').set(hw(T3)));
  });

  test('inactive teacher still cannot forge another author → denied (HW-D2)', async () => {
    await assertFails(staff(T3, A).doc('homework/H3').set(hw(T1)));
  });
});
