/**
 * marks — Firestore Rules unit tests.
 *
 * Phase 0.1 empirically proved the `marks` rule's boundary. Phase 0.2 closed
 * G-08 (employment-status authorization). Phase 0.2B closes G-09 (assignment
 * authorization) after Phase 0.2A corrected an earlier misdiagnosis and
 * Phase 0.2B's pre-checks (Check A: exam/create.php flow trace; Check B:
 * live examSchedule data inspection, 18/18 entries deterministically mapped)
 * both passed clean.
 *
 * Current contract under test (firestore.rules, `marks` block):
 *
 *   allow write: if isStaff() && isSameSchoolWrite() && hasCapability('Results')
 *                && isActiveStaff()                                    // G-08
 *                && subjectCode/assignClassSeg/assignSectionSeg/session/
 *                   className/section all present and non-empty          // G-09 fail-closed guard
 *                && marksAssignmentOk(...);                              // G-09 exact lookup
 *
 * marksAssignmentOk() does an exact get() on one of two candidate
 * subjectAssignments paths (per-section, or class-wide "_ALL_"), built from
 * CLIENT-SUPPLIED assignClassSeg/assignSectionSeg lookup-hint fields — but
 * authorization is decided entirely by cross-validating the FETCHED
 * document's own teacherId/archived/schoolId/className/section fields
 * against this write's trusted values, so a wrong/forged hint can only
 * cause a lookup miss, never a false allow. See the firestore.rules comment
 * block above `marksAssignmentOk()` for the full design rationale.
 *
 * IMPORTANT SCOPE NOTE (unchanged since Phase 0.1): this file proves the
 * RULES-LAYER boundary only. It cannot prove Auth-token-revocation timing.
 *
 * Run: cd firebase-rules/tests && npm test
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

const SCHOOL = 'SCH_TEST_MK_001';
const OTHER_SCHOOL = 'SCH_TEST_MK_002';
const SESSION = '2026-27';

const TEACHER_WITH_CAPS = 'STA_MK_CAPS';          // Active, Results capability, HAS a valid live assignment.
const TEACHER_NO_CAPS_DOC = 'STA_MK_NOCAPS';      // No staffCapabilities doc — fail-open path (G-11, untouched).
const DEACTIVATED_TEACHER = 'STA_MK_DEACTIVATED'; // Staff doc says Inactive; otherwise has a valid assignment.
const TEACHER_NO_STAFF_DOC = 'STA_MK_NOSTAFFDOC'; // No staff doc at all — G-08 fail-open-on-absence path.
const PRINCIPAL_ACTIVE = 'STA_MK_PRINCIPAL';      // Active, non-Teacher legitimate Result role.
const TEACHER_NO_RESULTS_MODULE = 'STA_MK_NORESULTSMOD'; // Capabilities doc exists, excludes 'Results'.
const TEACHER_NO_ASSIGN = 'STA_MK_NOASSIGN';      // Active, capable, but genuinely zero subjectAssignments rows.
const TEACHER_ARCHIVED_ONLY = 'STA_MK_ARCHIVED';  // Active, capable; their only matching row is archived=true.
const TEACHER_CLASSWIDE = 'STA_MK_CLASSWIDE';     // Active, capable; assigned class-wide ("_ALL_"), not per-section.

// Canonical scenario coordinates, matching what a real Exam.php-resolved
// schedule + Teacher App write would carry.
const CLASS_NAME = 'Class 5';
const SECTION = 'Section A';
const SUBJECT_CODE = 'MATH5';
const ASSIGN_CLASS_SEG = 'Class_5';     // space -> underscore, matches Subject_assignment_service::docId()
const ASSIGN_SECTION_SEG = 'Section_A';

const OTHER_CLASS_NAME = 'Class 6';
const OTHER_ASSIGN_CLASS_SEG = 'Class_6';
const OTHER_SECTION = 'Section B';
const OTHER_ASSIGN_SECTION_SEG = 'Section_B';
const OTHER_SUBJECT_CODE = 'SCI5';

let env;

const marksDocOf = (school, id) => `marks/${school}_${id}`;
const saDocOf = (school, classSeg, sectionSeg, code) => `subjectAssignments/${school}_${SESSION}_${classSeg}_${sectionSeg}_${code}`;
const saDocOfClassWide = (school, classSeg, code) => `subjectAssignments/${school}_${SESSION}_${classSeg}__ALL__${code}`;

const baseMark = (overrides) => ({
  schoolId: SCHOOL, session: SESSION, examId: 'EXAM1', studentId: 'STU_X',
  subject: 'Mathematics', subjectCode: SUBJECT_CODE,
  className: CLASS_NAME, section: SECTION,
  assignClassSeg: ASSIGN_CLASS_SEG, assignSectionSeg: ASSIGN_SECTION_SEG,
  componentMarks: { theory: 80 },
  ...overrides,
});

function asRole(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
function asAnon() {
  return env.unauthenticatedContext().firestore();
}

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL, OTHER_SCHOOL]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test School` });
    }

    // ── TEACHER_WITH_CAPS: Active, capable, has the canonical valid assignment. ──
    await db.doc(`staffCapabilities/${TEACHER_WITH_CAPS}`).set({ modules: ['Results', 'Attendance'], admin: false });
    await db.doc(`staff/${SCHOOL}_${TEACHER_WITH_CAPS}`).set({
      schoolId: SCHOOL, staffId: TEACHER_WITH_CAPS, status: 'Active', name: 'Active Teacher',
    });
    await db.doc(saDocOf(SCHOOL, ASSIGN_CLASS_SEG, ASSIGN_SECTION_SEG, SUBJECT_CODE)).set({
      schoolId: SCHOOL, session: SESSION, teacherId: TEACHER_WITH_CAPS,
      className: CLASS_NAME, section: SECTION, subjectCode: SUBJECT_CODE, subjectName: 'Mathematics',
    });

    // ── TEACHER_NO_CAPS_DOC: no staffCapabilities doc — fail-open (G-11,
    //    untouched), but MUST have a genuinely valid assignment of their own
    //    now that G-09 stacks on top — otherwise the "fail-open still means
    //    allowed" test would be conflating a G-11 pass with a G-09 failure. ──
    await db.doc(`staff/${SCHOOL}_${TEACHER_NO_CAPS_DOC}`).set({
      schoolId: SCHOOL, staffId: TEACHER_NO_CAPS_DOC, status: 'Active', name: 'No-Caps Teacher',
    });
    await db.doc(saDocOf(SCHOOL, ASSIGN_CLASS_SEG, ASSIGN_SECTION_SEG, SUBJECT_CODE + '_NC')).set({
      schoolId: SCHOOL, session: SESSION, teacherId: TEACHER_NO_CAPS_DOC,
      className: CLASS_NAME, section: SECTION, subjectCode: SUBJECT_CODE + '_NC', subjectName: 'Mathematics NC',
    });

    // ── DEACTIVATED_TEACHER: Inactive staff doc, otherwise a genuinely valid assignment. ──
    await db.doc(`staffCapabilities/${DEACTIVATED_TEACHER}`).set({ modules: ['Results'], admin: false });
    await db.doc(`staff/${SCHOOL}_${DEACTIVATED_TEACHER}`).set({
      schoolId: SCHOOL, staffId: DEACTIVATED_TEACHER, status: 'Inactive', name: 'Deactivated Teacher',
    });
    await db.doc(saDocOf(SCHOOL, ASSIGN_CLASS_SEG, ASSIGN_SECTION_SEG, SUBJECT_CODE + '_D')).set({
      schoolId: SCHOOL, session: SESSION, teacherId: DEACTIVATED_TEACHER,
      className: CLASS_NAME, section: SECTION, subjectCode: SUBJECT_CODE + '_D', subjectName: 'Mathematics D',
    });

    // ── TEACHER_NO_STAFF_DOC: no staff doc at all — G-08 fail-open-on-absence. ──
    await db.doc(`staffCapabilities/${TEACHER_NO_STAFF_DOC}`).set({ modules: ['Results'], admin: false });
    await db.doc(saDocOf(SCHOOL, ASSIGN_CLASS_SEG, ASSIGN_SECTION_SEG, SUBJECT_CODE + '_N')).set({
      schoolId: SCHOOL, session: SESSION, teacherId: TEACHER_NO_STAFF_DOC,
      className: CLASS_NAME, section: SECTION, subjectCode: SUBJECT_CODE + '_N', subjectName: 'Mathematics N',
    });

    // ── PRINCIPAL_ACTIVE: legitimate non-Teacher role. Also given a matching
    //    assignment row so the "role preserved" test isolates role behavior,
    //    not assignment behavior. ──
    await db.doc(`staffCapabilities/${PRINCIPAL_ACTIVE}`).set({ modules: ['Results'], admin: false });
    await db.doc(`staff/${SCHOOL}_${PRINCIPAL_ACTIVE}`).set({
      schoolId: SCHOOL, staffId: PRINCIPAL_ACTIVE, status: 'Active', name: 'Active Principal',
    });
    await db.doc(saDocOf(SCHOOL, ASSIGN_CLASS_SEG, ASSIGN_SECTION_SEG, SUBJECT_CODE + '_P')).set({
      schoolId: SCHOOL, session: SESSION, teacherId: PRINCIPAL_ACTIVE,
      className: CLASS_NAME, section: SECTION, subjectCode: SUBJECT_CODE + '_P', subjectName: 'Mathematics P',
    });

    // ── TEACHER_NO_RESULTS_MODULE: capabilities doc exists, excludes 'Results'. ──
    await db.doc(`staffCapabilities/${TEACHER_NO_RESULTS_MODULE}`).set({ modules: ['Attendance'], admin: false });
    await db.doc(`staff/${SCHOOL}_${TEACHER_NO_RESULTS_MODULE}`).set({
      schoolId: SCHOOL, staffId: TEACHER_NO_RESULTS_MODULE, status: 'Active', name: 'No-Results-Module Teacher',
    });

    // ── TEACHER_NO_ASSIGN: Active, capable, genuinely zero subjectAssignments rows. ──
    await db.doc(`staffCapabilities/${TEACHER_NO_ASSIGN}`).set({ modules: ['Results'], admin: false });
    await db.doc(`staff/${SCHOOL}_${TEACHER_NO_ASSIGN}`).set({
      schoolId: SCHOOL, staffId: TEACHER_NO_ASSIGN, status: 'Active', name: 'Unassigned Teacher',
    });

    // ── TEACHER_ARCHIVED_ONLY: their only matching row is archived. ──
    await db.doc(`staffCapabilities/${TEACHER_ARCHIVED_ONLY}`).set({ modules: ['Results'], admin: false });
    await db.doc(`staff/${SCHOOL}_${TEACHER_ARCHIVED_ONLY}`).set({
      schoolId: SCHOOL, staffId: TEACHER_ARCHIVED_ONLY, status: 'Active', name: 'Archived-Assignment Teacher',
    });
    await db.doc(saDocOf(SCHOOL, ASSIGN_CLASS_SEG, ASSIGN_SECTION_SEG, SUBJECT_CODE + '_ARCH')).set({
      schoolId: SCHOOL, session: SESSION, teacherId: TEACHER_ARCHIVED_ONLY,
      className: CLASS_NAME, section: SECTION, subjectCode: SUBJECT_CODE + '_ARCH', subjectName: 'Archived Subject',
      archived: true,
    });

    // ── TEACHER_CLASSWIDE: assigned class-wide ("_ALL_"), not per-section. ──
    await db.doc(`staffCapabilities/${TEACHER_CLASSWIDE}`).set({ modules: ['Results'], admin: false });
    await db.doc(`staff/${SCHOOL}_${TEACHER_CLASSWIDE}`).set({
      schoolId: SCHOOL, staffId: TEACHER_CLASSWIDE, status: 'Active', name: 'Class-wide Teacher',
    });
    await db.doc(saDocOfClassWide(SCHOOL, ASSIGN_CLASS_SEG, SUBJECT_CODE + '_CW')).set({
      schoolId: SCHOOL, session: SESSION, teacherId: TEACHER_CLASSWIDE,
      className: CLASS_NAME, section: '', subjectCode: SUBJECT_CODE + '_CW', subjectName: 'Class-wide Subject',
    });

    // ── Cross-school probe target: TEACHER_WITH_CAPS's assignment also
    //    exists (deliberately) under OTHER_SCHOOL with a DIFFERENT teacher,
    //    to prove a same-uid cross-school collision can't be exploited. ──
    await db.doc(`staff/${OTHER_SCHOOL}_${TEACHER_WITH_CAPS}`).set({
      schoolId: OTHER_SCHOOL, staffId: TEACHER_WITH_CAPS, status: 'Active', name: 'Other-School Same-UID',
    });
    await db.doc(`staffCapabilities/${TEACHER_WITH_CAPS}`).set({ modules: ['Results', 'Attendance'], admin: false }, { merge: true });
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

describe('marks rules — G-08 (unchanged, re-verified alongside G-09)', () => {
  test('an Inactive staff member with an otherwise-valid assignment CANNOT write marks', async () => {
    await assertFails(
      asRole(DEACTIVATED_TEACHER, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_DEACT_1')).set(
        baseMark({ studentId: 'STU1', subjectCode: SUBJECT_CODE + '_D', savedBy: DEACTIVATED_TEACHER })
      )
    );
  });

  test('a staff member with NO staff doc at all, but a valid assignment, CAN still write marks (G-08 fail-open-on-absence preserved)', async () => {
    await assertSucceeds(
      asRole(TEACHER_NO_STAFF_DOC, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_NOSTAFFDOC_1')).set(
        baseMark({ studentId: 'STU2', subjectCode: SUBJECT_CODE + '_N', savedBy: TEACHER_NO_STAFF_DOC })
      )
    );
  });
});

describe('marks rules — G-09 CLOSED: positive path', () => {
  test('Active teacher + exact current assignment (correct class/section/subjectCode) CAN write marks', async () => {
    await assertSucceeds(
      asRole(TEACHER_WITH_CAPS, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_OK_1')).set(
        baseMark({ studentId: 'STU3', savedBy: TEACHER_WITH_CAPS })
      )
    );
  });

  test('a legitimate non-Teacher Result role (Principal) with a matching assignment CAN write marks — role/capability behavior preserved', async () => {
    await assertSucceeds(
      asRole(PRINCIPAL_ACTIVE, SCHOOL, 'Principal').doc(marksDocOf(SCHOOL, 'M_PRINCIPAL_1')).set(
        baseMark({ studentId: 'STU4', subjectCode: SUBJECT_CODE + '_P', savedBy: PRINCIPAL_ACTIVE })
      )
    );
  });

  test('a teacher assigned CLASS-WIDE ("_ALL_") CAN write marks for any section of that class', async () => {
    await assertSucceeds(
      asRole(TEACHER_CLASSWIDE, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_CLASSWIDE_1')).set(
        baseMark({ studentId: 'STU5', subjectCode: SUBJECT_CODE + '_CW', savedBy: TEACHER_CLASSWIDE })
      )
    );
  });
});

describe('marks rules — G-09 CLOSED: negative path (required scenario matrix)', () => {
  test('Active teacher with NO assignment at all CANNOT write marks', async () => {
    await assertFails(
      asRole(TEACHER_NO_ASSIGN, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_NOASSIGN_1')).set(
        baseMark({ studentId: 'STU6', savedBy: TEACHER_NO_ASSIGN })
      )
    );
  });

  test('Active teacher assigned to a DIFFERENT subject CANNOT write marks for this subject', async () => {
    await assertFails(
      asRole(TEACHER_WITH_CAPS, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_WRONGSUBJ_1')).set(
        baseMark({ studentId: 'STU7', subjectCode: OTHER_SUBJECT_CODE, savedBy: TEACHER_WITH_CAPS })
      )
    );
  });

  test('Active teacher assigned to a DIFFERENT class CANNOT write marks for this class', async () => {
    await assertFails(
      asRole(TEACHER_WITH_CAPS, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_WRONGCLASS_1')).set(
        baseMark({
          studentId: 'STU8', className: OTHER_CLASS_NAME, assignClassSeg: OTHER_ASSIGN_CLASS_SEG,
          savedBy: TEACHER_WITH_CAPS,
        })
      )
    );
  });

  test('Active teacher assigned to a DIFFERENT section CANNOT write marks for this section', async () => {
    await assertFails(
      asRole(TEACHER_WITH_CAPS, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_WRONGSECTION_1')).set(
        baseMark({
          studentId: 'STU9', section: OTHER_SECTION, assignSectionSeg: OTHER_ASSIGN_SECTION_SEG,
          savedBy: TEACHER_WITH_CAPS,
        })
      )
    );
  });

  test('an ARCHIVED assignment CANNOT authorize a marks write', async () => {
    await assertFails(
      asRole(TEACHER_ARCHIVED_ONLY, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_ARCHIVED_1')).set(
        baseMark({ studentId: 'STU10', subjectCode: SUBJECT_CODE + '_ARCH', savedBy: TEACHER_ARCHIVED_ONLY })
      )
    );
  });

  test('a cross-school assignment (same teacherId value, different tenant) CANNOT authorize a write', async () => {
    // TEACHER_WITH_CAPS is a valid, active, capable teacher of OTHER_SCHOOL
    // too (seeded above) but has NO assignment row under OTHER_SCHOOL for
    // this class/section/subject — isSameSchoolWrite() alone would already
    // deny this (payload schoolId must equal the caller's own token
    // school_id), so this specifically proves that floor still holds
    // alongside the new G-09 check, not that G-09 independently blocks it.
    await assertFails(
      asRole(TEACHER_WITH_CAPS, OTHER_SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_XSCHOOL_ASSIGN_1')).set(
        baseMark({ studentId: 'STU11', savedBy: TEACHER_WITH_CAPS })
      )
    );
  });

  test('missing subjectCode FAILS CLOSED — Active teacher, valid capability, but no subjectCode field at all', async () => {
    const { subjectCode, ...markWithoutCode } = baseMark({ studentId: 'STU12', savedBy: TEACHER_WITH_CAPS });
    await assertFails(
      asRole(TEACHER_WITH_CAPS, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_NOCODE_1')).set(markWithoutCode)
    );
  });

  test('empty-string subjectCode FAILS CLOSED the same as a missing field', async () => {
    await assertFails(
      asRole(TEACHER_WITH_CAPS, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_EMPTYCODE_1')).set(
        baseMark({ studentId: 'STU13', subjectCode: '', savedBy: TEACHER_WITH_CAPS })
      )
    );
  });

  test('an INCORRECT subjectCode (well-formed but wrong) is denied — not merely "any non-empty code passes"', async () => {
    await assertFails(
      asRole(TEACHER_WITH_CAPS, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_BADCODE_1')).set(
        baseMark({ studentId: 'STU14', subjectCode: 'TOTALLY_MADE_UP_CODE', savedBy: TEACHER_WITH_CAPS })
      )
    );
  });
});

describe('marks rules — payload-manipulation resistance', () => {
  test('a client cannot bypass the school boundary by writing a payload schoolId matching a DIFFERENT school than their own token', async () => {
    await assertFails(
      asRole(TEACHER_WITH_CAPS, OTHER_SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_XSCHOOL_1')).set(
        baseMark({ studentId: 'STU15', savedBy: TEACHER_WITH_CAPS })
      )
    );
  });

  test('a client cannot substitute another teacher\'s valid assignment by forging savedBy — authorization is derived from the caller\'s own token', async () => {
    // TEACHER_NO_RESULTS_MODULE (no Results capability) attempts to write a
    // mark claiming savedBy = TEACHER_WITH_CAPS (who DOES have capability
    // AND a valid assignment). hasCapability()/isActiveStaff()/
    // marksAssignmentOk() all evaluate against request.auth.uid (the real
    // caller), never against the payload's savedBy field.
    await assertFails(
      asRole(TEACHER_NO_RESULTS_MODULE, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_FORGE_SAVEDBY')).set(
        baseMark({ studentId: 'STU16', savedBy: TEACHER_WITH_CAPS })
      )
    );
  });

  test('a client cannot forge assignClassSeg/assignSectionSeg to "borrow" someone else\'s valid assignment while claiming a different className/section', async () => {
    // TEACHER_WITH_CAPS genuinely holds a valid assignment at
    // (Class 5/Section A/MATH5). This attempts to write a mark whose
    // CLAIMED className is a different class (OTHER_CLASS_NAME) while the
    // lookup-hint fields still point at their real, valid assignment doc.
    // marksAssignmentOk() must reject this because the fetched assignment's
    // className field ("Class 5") does not equal the write's own claimed
    // className field (OTHER_CLASS_NAME) — proving the hint fields carry no
    // trust and the fetched document's content is what's actually checked.
    await assertFails(
      asRole(TEACHER_WITH_CAPS, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_FORGE_HINT_1')).set(
        baseMark({
          studentId: 'STU17', className: OTHER_CLASS_NAME,
          // assignClassSeg/assignSectionSeg/subjectCode deliberately left
          // pointing at TEACHER_WITH_CAPS's REAL valid assignment doc.
          savedBy: TEACHER_WITH_CAPS,
        })
      )
    );
  });

  test('an inactive teacher cannot bypass isActiveStaff() by omitting identity-adjacent payload fields', async () => {
    await assertFails(
      asRole(DEACTIVATED_TEACHER, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_DEACT_NOFIELD')).set(
        baseMark({ studentId: 'STU18', subjectCode: SUBJECT_CODE + '_D' })
      )
    );
  });
});

describe('marks rules — hasCapability() fail-open (unchanged, deliberately out of Phase 0.2/0.2B scope per G-11)', () => {
  test('a staff member with NO staffCapabilities doc at all, but a valid assignment, CAN still write marks (fail-open, not fail-closed)', async () => {
    await assertSucceeds(
      asRole(TEACHER_NO_CAPS_DOC, SCHOOL, 'Teacher').doc(marksDocOf(SCHOOL, 'M_NOCAPS_1')).set(
        baseMark({ studentId: 'STU19', subjectCode: SUBJECT_CODE + '_NC', savedBy: TEACHER_NO_CAPS_DOC })
      )
    );
  });
});

describe('marks rules — controls: floor protections unchanged from Phase 0.1', () => {
  test('unauthenticated client CANNOT write marks', async () => {
    await assertFails(
      asAnon().doc(marksDocOf(SCHOOL, 'M_ANON_1')).set(baseMark({ studentId: 'STU20' }))
    );
  });

  test('a Student-role token CANNOT write marks (isStaff() exclusion holds)', async () => {
    await assertFails(
      asRole('STU_MK_1', SCHOOL, 'Student').doc(marksDocOf(SCHOOL, 'M_STUDENT_1')).set(
        baseMark({ studentId: 'STU_MK_1' })
      )
    );
  });

  test('a Parent-role token CANNOT write marks (isStaff() exclusion holds)', async () => {
    await assertFails(
      asRole('PAR_MK_1', SCHOOL, 'Parent').doc(marksDocOf(SCHOOL, 'M_PARENT_1')).set(
        baseMark({ studentId: 'STU21' })
      )
    );
  });
});
