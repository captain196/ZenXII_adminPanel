/**
 * SEC-2 — `userDevices` OWNERSHIP.
 *
 * ── THE DEFECT ────────────────────────────────────────────────────────────
 *     match /userDevices/{docId} {
 *       allow read:   if isAuth();
 *       allow create: if isAuth();
 *       allow update: if isAuth();
 *       allow delete: if isAdmin();
 *     }
 *
 * Any authenticated user of ANY tenant could read, create or update ANY device
 * document. `userDevices` is the SOLE canonical FCM token store. A push token is
 * a delivery address: reading one enables targeted push spoofing given a server
 * key; overwriting one redirects or suppresses another user's notifications.
 *
 * ── WHY THIS IS NOT THE SEC-1 FIX ─────────────────────────────────────────
 * SEC-1's nine collections have ZERO client writes, so `isSameSchoolWrite()`
 * closes them. Here BOTH apps legitimately write — device self-registration —
 * so tenancy alone is insufficient and the wrong fix would break push for every
 * user. The correct constraint is OWNERSHIP.
 *
 * ── THE PROVEN DATA CONTRACT ──────────────────────────────────────────────
 * Verified in both apps' `AuthRepository.registerFcmToken()`:
 *
 *     docId  = `{userId}_{safeDeviceId}`
 *     fields = schoolId, userId, deviceId, fcmToken, platform,
 *              status, lastActive, appRole
 *
 * And `functions/index.js:224` states the identity mapping outright:
 *   "Firebase Auth UID, so a student's id IS the parent's userDevices.userId"
 *
 * So `userId == request.auth.uid` for parents AND staff. That equality is the
 * whole basis of the fix, which is why it was proven before a rule was written
 * rather than assumed from the field's name.
 *
 * Neither app READS the collection (setDocument only); backend and Cloud
 * Functions use the admin SDK and bypass rules. Read is therefore narrowed to
 * the owner rather than left open.
 *
 * Runs against the SEC-2 artifact via SEC2_RULES; skips otherwise.
 */

const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');

const PROJECT_ID = 'zenxii-rules-test';   // must match the emulator's project
const SCHOOL_A   = 'SCH_TENANT_AAA';
const SCHOOL_B   = 'SCH_TENANT_BBB';

// Firebase Auth UID == the app's userId (student id for parents, staff id for staff).
const USER_A1 = 'STU1001';   // school A
const USER_A2 = 'STU1002';   // school A, a DIFFERENT user
const USER_B1 = 'STU2001';   // school B
const DEVICE   = 'device_abc';

const RULES_PATH = process.env.SEC2_RULES || '';
const HAVE_RULES = RULES_PATH && fs.existsSync(RULES_PATH);

// Loading a ~146KB ruleset into the emulator exceeds Jest's 5s default for
// beforeAll on a loaded machine. The timeout is an environment property, not
// a property of the rules — a hook timeout fails every test in the file and is
// easy to misread as a total denial.
jest.setTimeout(60000);

let env;

beforeAll(async () => {
  if (!HAVE_RULES) return;
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });

  /**
   * HARNESS ORDERING CONSTRAINT — see the same note in
   * sec1_cross_tenant_fee_writes.test.js. The first rules-ENFORCING context
   * must exist before the first `withSecurityRulesDisabled()` seed, or every
   * test fails identically with "Firestore has already been started", which
   * reads exactly like a total rules failure.
   */
  env.authenticatedContext('warmup', { school_id: 'SCH_WARMUP', role: 'Admin' }).firestore();
});
afterAll(async () => { if (env) await env.cleanup(); });

/** A logged-in app user. UID is the app userId, per the proven contract. */
function user(uid, school, role = 'Student') {
  return env.authenticatedContext(uid, {
    school_id: school, schoolId: school, role,
  }).firestore();
}

function adminOf(school) {
  return env.authenticatedContext(`admin_${school}`, {
    school_id: school, schoolId: school, role: 'Admin',
  }).firestore();
}

/** The exact payload both apps write. */
function payload(uid, school, extra = {}) {
  return {
    schoolId: school,
    userId: uid,
    deviceId: DEVICE,
    fcmToken: 'fcm_token_value',
    platform: 'android',
    status: 'active',
    lastActive: '2026-08-21T00:00:00Z',
    appRole: 'parent',
    ...extra,
  };
}

const docFor = (uid) => `${uid}_${DEVICE}`;

async function seed(uid, school) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.collection('userDevices').doc(docFor(uid)).set(payload(uid, school));
  });
}

async function seedTenants() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL_A, SCHOOL_B]) {
      await db.collection('schools').doc(s).set({ schoolId: s });
      // tenantActive() reads schoolControl/{id}.lifecycle.state — NOT a `status`
      // field. An invented fixture shape makes isAdmin() throw
      // "Property lifecycle is undefined on object", which surfaces as
      // PERMISSION_DENIED on every test and reads like a rules defect.
      await db.collection('schoolControl').doc(s).set({ lifecycle: { state: 'active' } });
    }
  });
}

const maybe = HAVE_RULES ? describe : describe.skip;

maybe('SEC-2 · userDevices ownership', () => {
  beforeEach(async () => {
    await env.clearFirestore();
    await seedTenants();
  });

  // ══ LEGITIMATE SELF-REGISTRATION MUST KEEP WORKING ══════════════════════

  test('a user CAN register their own device', async () => {
    await assertSucceeds(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A1))
        .set(payload(USER_A1, SCHOOL_A))
    );
  });

  test('a user CAN refresh their own token (merge update)', async () => {
    await seed(USER_A1, SCHOOL_A);
    await assertSucceeds(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A1))
        .set(payload(USER_A1, SCHOOL_A, { fcmToken: 'rotated_token' }), { merge: true })
    );
  });

  test('a user CAN register a SECOND device (multi-device)', async () => {
    await assertSucceeds(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(`${USER_A1}_device_xyz`)
        .set(payload(USER_A1, SCHOOL_A, { deviceId: 'device_xyz' }))
    );
  });

  test('a user CAN read their own device document', async () => {
    await seed(USER_A1, SCHOOL_A);
    await assertSucceeds(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A1)).get()
    );
  });

  // ══ CROSS-USER, SAME SCHOOL — the token-harvesting vector ═══════════════

  test('a user CANNOT read another user\'s device token', async () => {
    await seed(USER_A2, SCHOOL_A);
    await assertFails(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A2)).get()
    );
  });

  test('a user CANNOT overwrite another user\'s device document', async () => {
    await seed(USER_A2, SCHOOL_A);
    await assertFails(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A2))
        .set(payload(USER_A2, SCHOOL_A, { fcmToken: 'attacker_token' }))
    );
  });

  /**
   * FORGED userId — the attacker writes to their OWN doc id but claims another
   * user in the payload. The rule must compare the PAYLOAD to the token, not
   * trust the document path.
   */
  test('a user CANNOT forge another user\'s userId in the payload', async () => {
    await assertFails(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A1))
        .set(payload(USER_A2, SCHOOL_A))
    );
  });

  // ══ CROSS-TENANT ════════════════════════════════════════════════════════

  test('a user of School A CANNOT read a School B device', async () => {
    await seed(USER_B1, SCHOOL_B);
    await assertFails(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_B1)).get()
    );
  });

  test('a user of School A CANNOT write a School B device', async () => {
    await assertFails(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_B1))
        .set(payload(USER_B1, SCHOOL_B))
    );
  });

  /**
   * Claiming a foreign schoolId while keeping your own uid must also fail —
   * otherwise a user could plant a device into another tenant's namespace.
   */
  test('a user CANNOT register their own device under another school', async () => {
    await assertFails(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A1))
        .set(payload(USER_A1, SCHOOL_B))
    );
  });

  // ══ MISSING / MALFORMED FIELDS ═════════════════════════════════════════

  test('a write with NO userId is refused', async () => {
    const p = payload(USER_A1, SCHOOL_A);
    delete p.userId;
    await assertFails(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A1)).set(p)
    );
  });

  test('a write with NO schoolId is refused', async () => {
    const p = payload(USER_A1, SCHOOL_A);
    delete p.schoolId;
    await assertFails(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A1)).set(p)
    );
  });

  test('a write with an EMPTY userId is refused', async () => {
    await assertFails(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A1))
        .set(payload('', SCHOOL_A))
    );
  });

  // ══ DELETE ══════════════════════════════════════════════════════════════

  test('an admin CAN delete a device in their OWN school', async () => {
    await seed(USER_A1, SCHOOL_A);
    await assertSucceeds(
      adminOf(SCHOOL_A).collection('userDevices').doc(docFor(USER_A1)).delete()
    );
  });

  test('an admin CANNOT delete a device in ANOTHER school', async () => {
    await seed(USER_B1, SCHOOL_B);
    await assertFails(
      adminOf(SCHOOL_A).collection('userDevices').doc(docFor(USER_B1)).delete()
    );
  });

  test('a non-admin user CANNOT delete even their own device', async () => {
    await seed(USER_A1, SCHOOL_A);
    await assertFails(
      user(USER_A1, SCHOOL_A).collection('userDevices').doc(docFor(USER_A1)).delete()
    );
  });

  // ══ UNAUTHENTICATED ═════════════════════════════════════════════════════

  test('an unauthenticated client cannot read', async () => {
    await seed(USER_A1, SCHOOL_A);
    await assertFails(
      env.unauthenticatedContext().firestore()
        .collection('userDevices').doc(docFor(USER_A1)).get()
    );
  });

  test('an unauthenticated client cannot write', async () => {
    await assertFails(
      env.unauthenticatedContext().firestore()
        .collection('userDevices').doc(docFor(USER_A1)).set(payload(USER_A1, SCHOOL_A))
    );
  });
});
