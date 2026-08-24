/**
 * REGRESSION: `institution` present but NULL.
 *
 * The lifecycle clause reads:
 *     !('institution' in sch.data) || !('state' in sch.data.institution) || …
 *
 * If a cleanup or migration writes `institution: null` rather than deleting
 * the field, the first arm is FALSE (the field exists) and the second
 * evaluates `'state' in null`. If that errors, the whole rule denies and the
 * tenant loses ALL client access — a self-inflicted outage from a tidy-up.
 *
 * Produced by an actual restore during E2E, which wrote null instead of
 * removing the field. This pins the behaviour either way.
 */
const {
  initializeTestEnvironment, assertSucceeds, assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');
const SCHOOL = 'SCH_NULLMAP_1';

let env;
const teacher = () => env.authenticatedContext('STA_1', {
  school_id: SCHOOL, schoolId: SCHOOL, role: 'Teacher', user_id: 'STA_1',
}).firestore();

async function seed(institutionValue) {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    await db.doc(`schoolControl/${SCHOOL}`).set({ lifecycle: { state: 'active' } });
    const s = { schoolId: SCHOOL, name: 'NullMap' };
    if (institutionValue !== undefined) s.institution = institutionValue;
    await db.doc(`schools/${SCHOOL}`).set(s);
    await db.doc(`students/${SCHOOL}_STU1`).set({ schoolId: SCHOOL, studentId: 'STU1' });
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8') },
  });
}, 60000);
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); });

describe('institution field present but NULL must not lock the tenant out', () => {
  test('institution: null → reads still ALLOWED', async () => {
    await seed(null);
    await assertSucceeds(teacher().doc(`students/${SCHOOL}_STU1`).get());
  });

  test('institution: null → writes still ALLOWED', async () => {
    await seed(null);
    await assertSucceeds(teacher().doc(`leaveApplications/${SCHOOL}_L1`).set({
      schoolId: SCHOOL, status: 'pending', attendanceStamped: false,
      applicantId: 'STA_1', applicantType: 'staff',
      startDate: '2026-09-01', endDate: '2026-09-02',
    }));
  });

  test('institution: {} (empty map, no state) → allowed', async () => {
    await seed({});
    await assertSucceeds(teacher().doc(`students/${SCHOOL}_STU1`).get());
  });

  test('field entirely absent → allowed (the baseline shape)', async () => {
    await seed(undefined);
    await assertSucceeds(teacher().doc(`students/${SCHOOL}_STU1`).get());
  });

  test('a real ARCHIVED state still denies (the gate still works)', async () => {
    await seed({ state: 'ARCHIVED' });
    await assertFails(teacher().doc(`students/${SCHOOL}_STU1`).get());
  });
});
