/**
 * appraisals — D-003 draft-visibility rules unit test.
 *
 * FINDING (2026-09-19 cross-platform critic audit): the `appraisals` read arm
 * allowed `resource.data.staffId == request.auth.uid` with NO status gate, while
 * Hr::save_appraisal writes status:'Draft' with the reviewer's unfinished
 * overallRating + areasOfImprovement already in the document. The Teacher app hid
 * drafts CLIENT-SIDE only (getMyAppraisals `.filter { it.status != "Draft" }`),
 * so a raw Firestore read with the subject's own token returned the draft review.
 *
 * FIX: the subject's own-read arm is now gated `&& status != 'Draft'` (deny-list,
 * to mirror the app filter and survive new terminal statuses). The admin arm is
 * unchanged (admins read every status via the Admin SDK, which bypasses rules).
 *
 * Run: cd firebase-rules/tests && npm test
 *   (or: npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *        "npx jest appraisal_draft_visibility --runInBand --forceExit")
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

const SCHOOL = 'SCH_TEST_APR_001';
const OTHER_SCHOOL = 'SCH_TEST_APR_002';

const SUBJECT_UID = 'STA0007';      // the appraised teacher; uid == staffId
const OTHER_STAFF_UID = 'STA0008';  // a colleague in the same school
const ADMIN_UID = 'admin-apr-1';

let env;

const apprDoc = (school, id) => `appraisals/${school}_${id}`;

function asStaff(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'Teacher', user_id: uid }).firestore();
}
function asAdmin(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'admin', user_id: uid }).firestore();
}

// A realistic appraisal document as Hr::save_appraisal writes it (the sensitive
// reviewer fields are always present, even on a Draft).
function appraisalData(status) {
  return {
    schoolId: SCHOOL,
    staffId: SUBJECT_UID,
    staffName: 'Test Teacher',
    reviewerId: 'admin-apr-1',
    overallRating: 3.5,
    areasOfImprovement: 'Reviewer draft notes — must not leak before submission',
    status: status,
    updatedAt: new Date().toISOString(),
  };
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8') },
  });
});

afterAll(async () => { if (env) await env.cleanup(); });

beforeEach(async () => {
  await env.clearFirestore();
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.doc(apprDoc(SCHOOL, 'A_DRAFT')).set(appraisalData('Draft'));
    await db.doc(apprDoc(SCHOOL, 'A_SUBMITTED')).set(appraisalData('Submitted'));
    await db.doc(apprDoc(SCHOOL, 'A_REVIEWED')).set(appraisalData('Reviewed'));
    // A malformed doc with NO status field (should not error-deny an owner read).
    const noStatus = appraisalData('Reviewed');
    delete noStatus.status;
    await db.doc(apprDoc(SCHOOL, 'A_NOSTATUS')).set(noStatus);
  });
});

describe('appraisals — D-003 draft visibility', () => {
  test('subject CANNOT read their OWN Draft appraisal', async () => {
    const db = asStaff(SUBJECT_UID, SCHOOL);
    await assertFails(db.doc(apprDoc(SCHOOL, 'A_DRAFT')).get());
  });

  test('subject CAN read their own Submitted appraisal', async () => {
    const db = asStaff(SUBJECT_UID, SCHOOL);
    await assertSucceeds(db.doc(apprDoc(SCHOOL, 'A_SUBMITTED')).get());
  });

  test('subject CAN read their own Reviewed appraisal', async () => {
    const db = asStaff(SUBJECT_UID, SCHOOL);
    await assertSucceeds(db.doc(apprDoc(SCHOOL, 'A_REVIEWED')).get());
  });

  test('a doc missing status does NOT error-deny the owner (treated non-Draft)', async () => {
    const db = asStaff(SUBJECT_UID, SCHOOL);
    await assertSucceeds(db.doc(apprDoc(SCHOOL, 'A_NOSTATUS')).get());
  });

  test('a colleague CANNOT read someone else\'s appraisal (any status)', async () => {
    const db = asStaff(OTHER_STAFF_UID, SCHOOL);
    await assertFails(db.doc(apprDoc(SCHOOL, 'A_SUBMITTED')).get());
    await assertFails(db.doc(apprDoc(SCHOOL, 'A_DRAFT')).get());
  });

  test('a same-school NON-admin token cannot read another staff draft', async () => {
    const db = asStaff(OTHER_STAFF_UID, SCHOOL);
    await assertFails(db.doc(apprDoc(SCHOOL, 'A_DRAFT')).get());
  });

  test('an admin of ANOTHER school cannot read this school\'s appraisal', async () => {
    const db = asAdmin(ADMIN_UID, OTHER_SCHOOL);
    await assertFails(db.doc(apprDoc(SCHOOL, 'A_REVIEWED')).get());
  });

  test('client writes remain server-only (allow write: if false)', async () => {
    const db = asStaff(SUBJECT_UID, SCHOOL);
    await assertFails(db.doc(apprDoc(SCHOOL, 'A_DRAFT')).set({ status: 'Reviewed' }));
    await assertFails(db.doc(apprDoc(SCHOOL, 'A_SUBMITTED')).delete());
  });
});
