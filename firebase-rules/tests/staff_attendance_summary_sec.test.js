/**
 * staffAttendanceSummary — Firestore Rules unit tests (Release Integration Phase 1).
 *
 * staffAttendanceSummary drives PAYROLL (present/absent/leave/LWP → pay). The
 * incoming attendance overhaul added this collection to the rules but shipped
 * NO rules test for it. This closes that gap.
 *
 * Contract (firestore.rules):
 *   allow read:  own summary (resource.data.staffId == auth.uid)
 *                OR same-school admin (isAdmin() && isSameSchool())
 *   allow write: if false   // server-only (Admin SDK bypasses rules)
 *
 * Run: cd firebase-rules/tests && npm install && npm test
 *   (or: npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *        "npx jest staff_attendance_summary_sec --runInBand --forceExit")
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

const SCHOOL = 'SCH_TEST_SAS_001';
const OTHER_SCHOOL = 'SCH_TEST_SAS_002';
const MONTH = '2026-07';

const STAFF1 = 'STA_S1';            // owns the summary under test
const STAFF2 = 'STA_S2';            // a different same-school staff
const ADMIN = 'admin-sas-1';        // same-school admin
const OTHER_ADMIN = 'admin-sas-2';  // admin of a DIFFERENT school

let env;

const summaryPath = `staffAttendanceSummary/${SCHOOL}_${STAFF1}_${MONTH}`;

// Staff carry role='Teacher' + user_id claim = staff id; request.auth.uid = the
// context id. The own-read rule compares resource.data.staffId == auth.uid, so
// we authenticate STAFF1 as uid STAFF1 (matching the seeded doc's staffId).
function asStaff(uid) {
  return env.authenticatedContext(uid, { school_id: SCHOOL, role: 'Teacher', user_id: uid }).firestore();
}
function asAdmin(uid, school) {
  return env.authenticatedContext(uid, { school_id: school, role: 'admin', user_id: uid }).firestore();
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
    // The summary under test — belongs to STAFF1 in SCHOOL.
    await db.doc(summaryPath).set({
      schoolId: SCHOOL, staffId: STAFF1, month: MONTH,
      present: 22, absent: 2, leave: 1, halfDay: 0, holiday: 4, weeklyOff: 4,
      workingDays: 24, _finalisedAt: new Date().toISOString(),
    });
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
  await seedWorld();
});
afterAll(async () => { if (env) await env.cleanup(); });

describe('staffAttendanceSummary rules (payroll source of truth)', () => {
  test('owner staff CAN read their own summary', async () => {
    await assertSucceeds(asStaff(STAFF1).doc(summaryPath).get());
  });

  test('another same-school staff CANNOT read it', async () => {
    await assertFails(asStaff(STAFF2).doc(summaryPath).get());
  });

  test('same-school admin CAN read any staff summary', async () => {
    await assertSucceeds(asAdmin(ADMIN, SCHOOL).doc(summaryPath).get());
  });

  test('cross-school admin CANNOT read it (tenant isolation)', async () => {
    await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(summaryPath).get());
  });

  test('owner staff CANNOT write their summary (server-only)', async () => {
    await assertFails(asStaff(STAFF1).doc(summaryPath).set({ present: 99 }, { merge: true }));
  });

  test('same-school admin CANNOT write a summary (server-only)', async () => {
    await assertFails(asAdmin(ADMIN, SCHOOL).doc(summaryPath).set({ present: 99 }, { merge: true }));
  });
});
