/**
 * locations — Firestore Rules unit tests (ZenX HRMS Phase 1).
 *
 * Phase 1 introduced the `locations` collection (campus / office / store — one
 * generic entity, labelled per tenant terminology). It shipped a rules block
 * but no rules test; this closes that gap before deployment, per the Phase 1
 * runtime gate's requirement to validate tenant isolation, admin-write and
 * school-scoped-read behaviour for every new collection.
 *
 * Contract (firestore.rules):
 *   match /locations/{docId}
 *     allow read:  isSameSchool()   // any authenticated same-school principal
 *     allow write: isAdmin()        // same-school admin only
 *
 * Doc id convention is `{schoolId}_{locationId}` (Firestore_service::docId()),
 * and every doc also carries a `schoolId` field — isSameSchool() matches on the
 * FIELD, so these tests deliberately assert on field-based scoping rather than
 * assuming the id prefix is what protects the row.
 *
 * Run: cd firebase-rules/tests && npm test
 *   (or: npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *        "npx jest locations_sec --runInBand --forceExit")
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

const SCHOOL = 'SCH_TEST_LOC_001';
const OTHER_SCHOOL = 'SCH_TEST_LOC_002';

const LOC_A = 'LOC_AAAA1111';          // default location of SCHOOL
const LOC_B = 'LOC_BBBB2222';          // second location of SCHOOL
const LOC_OTHER = 'LOC_CCCC3333';      // a location of OTHER_SCHOOL

const STAFF = 'STA_L1';
const ADMIN = 'admin-loc-1';
const OTHER_ADMIN = 'admin-loc-2';

let env;

const docOf = (school, loc) => `locations/${school}_${loc}`;

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
    await db.doc(docOf(SCHOOL, LOC_A)).set({
      schoolId: SCHOOL, locationId: LOC_A, name: 'Main Campus',
      isDefault: true, createdAt: now, updatedAt: now,
    });
    await db.doc(docOf(SCHOOL, LOC_B)).set({
      schoolId: SCHOOL, locationId: LOC_B, name: 'North Campus',
      isDefault: false, createdAt: now, updatedAt: now,
    });
    await db.doc(docOf(OTHER_SCHOOL, LOC_OTHER)).set({
      schoolId: OTHER_SCHOOL, locationId: LOC_OTHER, name: 'Rival Campus',
      isDefault: true, createdAt: now, updatedAt: now,
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

describe('locations rules — school-scoped read', () => {
  test('same-school staff CAN read a location in their school', async () => {
    await assertSucceeds(asStaff(STAFF, SCHOOL).doc(docOf(SCHOOL, LOC_A)).get());
  });

  test('same-school admin CAN read a location in their school', async () => {
    await assertSucceeds(asAdmin(ADMIN, SCHOOL).doc(docOf(SCHOOL, LOC_B)).get());
  });

  test('unauthenticated client CANNOT read any location', async () => {
    await assertFails(asAnon().doc(docOf(SCHOOL, LOC_A)).get());
  });
});

describe('locations rules — tenant isolation (the load-bearing case)', () => {
  test("cross-school staff CANNOT read another school's location", async () => {
    await assertFails(asStaff(STAFF, OTHER_SCHOOL).doc(docOf(SCHOOL, LOC_A)).get());
  });

  test("cross-school ADMIN CANNOT read another school's location", async () => {
    await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(docOf(SCHOOL, LOC_A)).get());
  });

  test("cross-school admin CANNOT update another school's location", async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(docOf(SCHOOL, LOC_B)).set({ name: 'Hijacked' }, { merge: true })
    );
  });

  test("cross-school admin CANNOT delete another school's location", async () => {
    await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(docOf(SCHOOL, LOC_B)).delete());
  });

  test('cross-school admin CANNOT create a location under another school id', async () => {
    await assertFails(
      asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(`locations/${SCHOOL}_LOC_INJECTED`).set({
        schoolId: SCHOOL, locationId: 'LOC_INJECTED', name: 'Injected', isDefault: false,
      })
    );
  });
});

describe('locations rules — write authority', () => {
  test('same-school admin CAN create a location for their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(`locations/${SCHOOL}_LOC_NEW9999`).set({
        schoolId: SCHOOL, locationId: 'LOC_NEW9999', name: 'New Campus', isDefault: false,
      })
    );
  });

  test('same-school admin CAN update a location in their own school', async () => {
    await assertSucceeds(
      asAdmin(ADMIN, SCHOOL).doc(docOf(SCHOOL, LOC_B)).set({ name: 'North Campus (renamed)' }, { merge: true })
    );
  });

  test('non-admin same-school staff CANNOT write a location', async () => {
    await assertFails(
      asStaff(STAFF, SCHOOL).doc(docOf(SCHOOL, LOC_B)).set({ name: 'Staff edit' }, { merge: true })
    );
  });

  test('non-admin same-school staff CANNOT delete a location', async () => {
    await assertFails(asStaff(STAFF, SCHOOL).doc(docOf(SCHOOL, LOC_B)).delete());
  });

  test('unauthenticated client CANNOT create a location', async () => {
    await assertFails(
      asAnon().doc(`locations/${SCHOOL}_LOC_ANON`).set({ schoolId: SCHOOL, name: 'Anon' })
    );
  });
});
