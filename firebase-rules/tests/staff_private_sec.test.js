/**
 * staffPrivate — Firestore Rules unit tests (Phase 3A.1: separation-metadata
 * privacy closure).
 *
 * The `staffPrivate` rule itself is NOT changed by Phase 3A.1 — this test
 * file is new because no rules-emulator test existed for it before, despite
 * the mechanism already protecting PAN/Aadhaar/PF/ESI/salary/bank. Phase
 * 3A.1 adds exitReason/deactivationReason/deactivatedBy/reactivatedBy to the
 * SAME field set flowing through the SAME, already-existing owner-only rule
 * (firestore.rules: `match /staffPrivate/{docId}` — read: owner-only,
 * write: false, server-only via Admin SDK). These tests close that gap and
 * confirm the boundary the new separation fields now rely on.
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

const SCHOOL = 'SCH_TEST_SP_001';
const OTHER_SCHOOL = 'SCH_TEST_SP_002';

const OWNER_STAFF_ID = 'STA_SP_OWNER';   // this doc's own uid == this staffId
const OTHER_TEACHER   = 'STA_SP_PEER';
const PARENT_UID      = 'parent-sp-1';
const STUDENT_UID     = 'student-sp-1';
const ADMIN_UID       = 'admin-sp-1';

let env;

const staffPrivateDocOf = (school, staffId) => `staffPrivate/${school}_${staffId}`;
const staffDocOf = (school, staffId) => `staff/${school}_${staffId}`;

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

    // Public staff doc — post-Phase-3A.1 shape: separation narrative/actor
    // fields are ABSENT (moved out), operationally-load-bearing dates remain.
    await db.doc(staffDocOf(SCHOOL, OWNER_STAFF_ID)).set({
      schoolId: SCHOOL, staffId: OWNER_STAFF_ID, name: 'Owner Staff', status: 'Inactive',
      exitDate: '2026-08-31', lastWorkingDate: '2026-08-31',
      // exitReason/deactivationReason/deactivatedBy intentionally absent.
    });

    // The private mirror — where the sensitive fields now live.
    await db.doc(staffPrivateDocOf(SCHOOL, OWNER_STAFF_ID)).set({
      staffId: OWNER_STAFF_ID,
      exitReason: 'Resignation — relocating to another city',
      deactivationReason: 'Resignation — relocating to another city',
      deactivatedBy: 'ADM0001',
      panNumber: 'ABCDE1234F',
    });
    await db.doc(staffPrivateDocOf(OTHER_SCHOOL, 'STA_SP_OTHERSCHOOL')).set({
      staffId: 'STA_SP_OTHERSCHOOL', exitReason: 'Other-school record',
    });
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

describe('staffPrivate — separation metadata now lives here, owner-only read', () => {
  test('the staff member THEMSELVES (owner) CAN read their own exitReason/deactivationReason', async () => {
    await assertSucceeds(
      asRole(OWNER_STAFF_ID, SCHOOL, 'Teacher').doc(staffPrivateDocOf(SCHOOL, OWNER_STAFF_ID)).get()
    );
  });

  test('a same-school PARENT CANNOT read another staff member\'s separation metadata', async () => {
    await assertFails(
      asRole(PARENT_UID, SCHOOL, 'Parent').doc(staffPrivateDocOf(SCHOOL, OWNER_STAFF_ID)).get()
    );
  });

  test('a same-school STUDENT CANNOT read another staff member\'s separation metadata', async () => {
    await assertFails(
      asRole(STUDENT_UID, SCHOOL, 'Student').doc(staffPrivateDocOf(SCHOOL, OWNER_STAFF_ID)).get()
    );
  });

  test('a same-school PEER TEACHER (not the owner, no HR/private access) CANNOT read another staff member\'s separation metadata', async () => {
    await assertFails(
      asRole(OTHER_TEACHER, SCHOOL, 'Teacher').doc(staffPrivateDocOf(SCHOOL, OWNER_STAFF_ID)).get()
    );
  });

  test('a same-school ADMIN via direct client SDK CANNOT read another staff member\'s separation metadata — the rule has NO role exception; authorized HR/Admin access is PHP-backend-mediated (Admin SDK bypass), never direct client reads', async () => {
    await assertFails(
      asRole(ADMIN_UID, SCHOOL, 'admin').doc(staffPrivateDocOf(SCHOOL, OWNER_STAFF_ID)).get()
    );
  });

  test('a DIFFERENT-SCHOOL user CANNOT read it, even as their own uid pattern in another school', async () => {
    await assertFails(
      asRole(OWNER_STAFF_ID, OTHER_SCHOOL, 'Teacher').doc(staffPrivateDocOf(SCHOOL, OWNER_STAFF_ID)).get()
    );
  });

  test('an UNAUTHENTICATED user CANNOT read it', async () => {
    await assertFails(
      asAnon().doc(staffPrivateDocOf(SCHOOL, OWNER_STAFF_ID)).get()
    );
  });

  test('NO client (including the owner) can WRITE staffPrivate — server-only, unchanged', async () => {
    await assertFails(
      asRole(OWNER_STAFF_ID, SCHOOL, 'Teacher').doc(staffPrivateDocOf(SCHOOL, OWNER_STAFF_ID))
        .set({ staffId: OWNER_STAFF_ID, exitReason: 'tampered' })
    );
  });
});

describe('staff — normal profile reads remain unaffected by the scrub', () => {
  test('same-school read of the (now-scrubbed) public staff doc still succeeds and still exposes non-sensitive fields', async () => {
    const snap = await asRole(PARENT_UID, SCHOOL, 'Parent').doc(staffDocOf(SCHOOL, OWNER_STAFF_ID)).get();
    await assertSucceeds(Promise.resolve(snap));
    const data = snap.data();
    expect(data.name).toBe('Owner Staff');
    expect(data.exitDate).toBe('2026-08-31'); // operationally load-bearing (Payroll) — deliberately still public
    expect(data.exitReason).toBeUndefined();   // moved out — never was written here in this seeded shape
    expect(data.deactivationReason).toBeUndefined();
  });
});
