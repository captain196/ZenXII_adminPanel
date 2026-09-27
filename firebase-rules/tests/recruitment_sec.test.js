/**
 * hrJobs / hrApplicants — Firestore Rules unit tests (ZenX HRMS W1.3).
 *
 * W1.3 pointed Ats.php at the canonical Firestore recruitment collections that
 * Hr.php already used. Neither collection had ANY rules test, and the audit
 * found both carried `allow write: if isAdmin()` — which proves the caller is
 * AN admin but never binds the write to their own tenant, so an admin of one
 * school could create or overwrite another school's job postings and candidate
 * records. That is the same defect (and the same fix) as the `locations` block.
 *
 * Contract after W1.3 (firestore.rules):
 *   match /hrJobs/{docId}
 *     allow read:           isSameSchool()                    // Teacher app reads openings
 *     allow create, update: isAdmin() && isSameSchoolWrite()
 *     allow delete:         isAdmin() && isSameSchool()
 *
 *   match /hrApplicants/{docId}
 *     allow read:           isAdmin() && isSameSchool()       // candidate PII — admin only
 *     allow create, update: isAdmin() && isSameSchoolWrite()
 *     allow delete:         isAdmin() && isSameSchool()
 *
 * The asymmetry is deliberate: the Teacher app lists open jobs, but no app
 * client ever reads applicants.
 *
 * isSameSchoolWrite() matches request.resource.data.schoolId — the FIELD, not
 * the document-id prefix — so the injection tests below set a foreign schoolId
 * field explicitly rather than relying on the id to carry the boundary.
 *
 * Run: cd firebase-rules/tests && npm test
 *   (or: npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *        "npx jest recruitment_sec --runInBand --forceExit")
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

const SCHOOL = 'SCH_TEST_ATS_001';
const OTHER_SCHOOL = 'SCH_TEST_ATS_002';

const JOB_A = 'JOB0001';               // job of SCHOOL
const JOB_OTHER = 'JOB0009';           // job of OTHER_SCHOOL
const APP_A = 'APP0001';               // applicant of SCHOOL
const APP_OTHER = 'APP0009';           // applicant of OTHER_SCHOOL

const STAFF = 'STA_A1';
const ADMIN = 'admin-ats-1';
const OTHER_ADMIN = 'admin-ats-2';

let env;

const jobDoc = (school, id) => `hrJobs/${school}_${id}`;
const appDoc = (school, id) => `hrApplicants/${school}_${id}`;

function asStaff(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'Teacher', user_id: uid }).firestore();
}
function asAdmin(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'admin', user_id: uid }).firestore();
}
function asAnon() {
  return env.unauthenticatedContext().firestore();
}

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    // tenantActive() requires an active schoolControl doc per school.
    for (const s of [SCHOOL, OTHER_SCHOOL]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test School` });
    }
    const now = new Date().toISOString();

    await db.doc(jobDoc(SCHOOL, JOB_A)).set({
      schoolId: SCHOOL, title: 'Mathematics Teacher', department: 'Academics',
      positions: 2, vacancies: 2, filled_positions: 0,
      status: 'Open', statusLower: 'open', created_at: now,
    });
    await db.doc(jobDoc(OTHER_SCHOOL, JOB_OTHER)).set({
      schoolId: OTHER_SCHOOL, title: 'Rival Opening', department: 'Academics',
      positions: 1, vacancies: 1, status: 'Open', statusLower: 'open', created_at: now,
    });

    await db.doc(appDoc(SCHOOL, APP_A)).set({
      schoolId: SCHOOL, job_id: JOB_A, name: 'Candidate One',
      email: 'one@example.test', phone: '9990000001',
      stage: 'Applied', status: 'Applied', ats_status: 'active', applied_date: '2026-08-01',
    });
    await db.doc(appDoc(OTHER_SCHOOL, APP_OTHER)).set({
      schoolId: OTHER_SCHOOL, job_id: JOB_OTHER, name: 'Rival Candidate',
      email: 'rival@example.test', phone: '9990000009',
      stage: 'Selected', status: 'Selected', ats_status: 'active', applied_date: '2026-08-02',
    });
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
  await seedWorld();
}, 120000); // initializeTestEnvironment can take 12s+ on a cold emulator; the
            // repo-wide 5000ms Jest default is the documented cause of the
            // pre-existing h_lifecycle flake — do not inherit it here.
afterAll(async () => { if (env) await env.cleanup(); });

describe('hrJobs — read (Teacher app lists openings)', () => {
  test('same-school staff CAN read a job in their school', async () => {
    await assertSucceeds(asStaff(STAFF, SCHOOL).doc(jobDoc(SCHOOL, JOB_A)).get());
  });

  test('same-school admin CAN read a job in their school', async () => {
    await assertSucceeds(asAdmin(ADMIN, SCHOOL).doc(jobDoc(SCHOOL, JOB_A)).get());
  });

  test('unauthenticated client CANNOT read a job', async () => {
    await assertFails(asAnon().doc(jobDoc(SCHOOL, JOB_A)).get());
  });

  test("cross-school staff CANNOT read another school's job", async () => {
    await assertFails(asStaff(STAFF, OTHER_SCHOOL).doc(jobDoc(SCHOOL, JOB_A)).get());
  });
});

describe('hrApplicants — read (candidate PII, admin only)', () => {
  test('same-school ADMIN CAN read an applicant', async () => {
    await assertSucceeds(asAdmin(ADMIN, SCHOOL).doc(appDoc(SCHOOL, APP_A)).get());
  });

  test('same-school non-admin staff CANNOT read an applicant', async () => {
    // A teacher must never see candidate contact details, even in their own school.
    await assertFails(asStaff(STAFF, SCHOOL).doc(appDoc(SCHOOL, APP_A)).get());
  });

  test("cross-school admin CANNOT read another school's applicant", async () => {
    await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(appDoc(SCHOOL, APP_A)).get());
  });

  test('unauthenticated client CANNOT read an applicant', async () => {
    await assertFails(asAnon().doc(appDoc(SCHOOL, APP_A)).get());
  });
});

describe('hrJobs — write authority and tenant binding (W1.3 regression)', () => {
  test('same-school admin CAN create a job for their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(jobDoc(SCHOOL, 'JOB0100')).set({
        schoolId: SCHOOL, title: 'Physics Teacher', department: 'Academics',
        positions: 1, vacancies: 1, status: 'Open', statusLower: 'open',
      })
    );
  });

  test('same-school admin CAN update a job in their own school', async () => {
    // The vacancy decrement Ats::finalize_hire() performs.
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(jobDoc(SCHOOL, JOB_A)).set(
        { schoolId: SCHOOL, positions: 1, vacancies: 1, filled_positions: 1 }, { merge: true }
      )
    );
  });

  test('non-admin same-school staff CANNOT write a job', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(jobDoc(SCHOOL, JOB_A)).set({ schoolId: SCHOOL, title: 'Hijacked' }, { merge: true })
    );
  });

  test("cross-school admin CANNOT update another school's job", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(jobDoc(SCHOOL, JOB_A)).set(
        { schoolId: SCHOOL, title: 'Hijacked' }, { merge: true }
      )
    );
  });

  test('cross-school admin CANNOT create a job under another school id', async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(jobDoc(SCHOOL, 'JOB_INJECTED')).set({
        schoolId: SCHOOL, title: 'Injected', department: 'X', positions: 1, status: 'Open',
      })
    );
  });

  test("cross-school admin CANNOT delete another school's job", async () => {
    await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(jobDoc(SCHOOL, JOB_A)).delete());
  });
});

describe('hrApplicants — write authority and tenant binding (W1.3 regression)', () => {
  test('same-school admin CAN create an applicant for their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(appDoc(SCHOOL, 'APP0100')).set({
        schoolId: SCHOOL, job_id: JOB_A, name: 'Candidate Two',
        stage: 'Applied', status: 'Applied', ats_status: 'active',
      })
    );
  });

  test('same-school admin CAN advance an applicant stage', async () => {
    // The write Ats::move_stage() performs, including the HR dual-emit.
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(appDoc(SCHOOL, APP_A)).set(
        { schoolId: SCHOOL, stage: 'Shortlisted', status: 'Shortlisted' }, { merge: true }
      )
    );
  });

  test('non-admin same-school staff CANNOT write an applicant', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(appDoc(SCHOOL, APP_A)).set(
        { schoolId: SCHOOL, stage: 'Selected' }, { merge: true }
      )
    );
  });

  test("cross-school admin CANNOT update another school's applicant", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(appDoc(SCHOOL, APP_A)).set(
        { schoolId: SCHOOL, stage: 'Selected' }, { merge: true }
      )
    );
  });

  test('cross-school admin CANNOT create an applicant under another school id', async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(appDoc(SCHOOL, 'APP_INJECTED')).set({
        schoolId: SCHOOL, job_id: JOB_A, name: 'Injected', stage: 'Applied',
      })
    );
  });

  test("cross-school admin CANNOT delete another school's applicant", async () => {
    await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(appDoc(SCHOOL, APP_A)).delete());
  });

  test('unauthenticated client CANNOT create an applicant', async () => {
    await assertFails(
      asAnon().doc(appDoc(SCHOOL, 'APP_ANON')).set({ schoolId: SCHOOL, name: 'Anon' })
    );
  });
});

describe('hrApplicants — schoolId field is the boundary, not the doc id', () => {
  test('admin CANNOT write a doc whose schoolId field names another school', async () => {
    // isSameSchoolWrite() reads request.resource.data.schoolId. Placing a
    // foreign schoolId in a document whose ID prefix looks local must still
    // fail, or the tenant boundary would be only as strong as the id convention.
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(appDoc(SCHOOL, 'APP_SPOOF')).set({
        schoolId: OTHER_SCHOOL, job_id: JOB_OTHER, name: 'Spoofed', stage: 'Applied',
      })
    );
  });

  test('admin CANNOT create a job with a missing schoolId field', async () => {
    await assertFails(
      asAdmin(ADMIN, SCHOOL).doc(jobDoc(SCHOOL, 'JOB_NOSCHOOL')).set({
        title: 'No tenant', department: 'X', positions: 1, status: 'Open',
      })
    );
  });
});
