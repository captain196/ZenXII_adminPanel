/**
 * Maps & Location platform — Firestore Rules unit tests.
 *
 * WHY THIS EXISTS
 * ---------------
 * The rules comment states the security model plainly: "THE SPLIT IS THE
 * SECURITY MODEL. Firestore rules gate a DOCUMENT; they cannot hide a field."
 * Credentials are therefore separated into a client-readable `mapsConfig` and a
 * never-readable `mapsSecrets`, and the rules are what make that split real
 * rather than advisory. Nothing tested that split.
 *
 * These tests assert ACCESS BEHAVIOUR ONLY. No secret value is asserted on,
 * logged, or printed — the seeded value is an obvious non-credential
 * placeholder, and every secret assertion is a denial.
 *
 * INTENT SOURCE: the access model documented inline in firestore.rules.
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

const SCHOOL = 'SCH_TEST_MAPS_001';
const OTHER = 'SCH_TEST_MAPS_002';

const ADMIN = 'admin-maps-1';
const TEACHER = 'STA_MAPS_T1';
const STUDENT = 'STU_MAPS_1';   // parent-app auth uid

// Deliberately not a credential-shaped value. Never asserted on.
const PLACEHOLDER = 'PLACEHOLDER-NOT-A-REAL-KEY';

let env;

const cfg   = (s) => `mapsConfig/${s}_default`;
const sec   = (s) => `mapsSecrets/${s}_default`;
const audit = (s) => `mapsConfigAudit/${s}_A0001`;

function ctx(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
const asAdmin   = (school = SCHOOL) => ctx(ADMIN, school, 'admin');
const asTeacher = (school = SCHOOL) => ctx(TEACHER, school, 'Teacher');
const asParent  = (school = SCHOOL) => ctx(STUDENT, school, 'Parent');
const asAnon    = () => env.unauthenticatedContext().firestore();

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    for (const s of [SCHOOL, OTHER]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test` });

      await db.doc(cfg(s)).set({
        schoolId: s, mode: 'maplibre', providerAndroid: 'maplibre', providerWeb: 'maplibre',
        styleUrl: 'https://example.invalid/style.json',
        defaultCenterLat: 26.5, defaultCenterLng: 80.2, defaultZoom: 12,
      });
      await db.doc(sec(s)).set({ schoolId: s, serverKey: PLACEHOLDER });
      await db.doc(audit(s)).set({
        schoolId: s, changedBy: 'ADM0001', changedAt: new Date().toISOString(),
        keysTouched: ['styleUrl'],
      });
    }
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

describe('mapsConfig — public config, same-school authenticated read', () => {
  test('same-school admin CAN read', async () => {
    await assertSucceeds(asAdmin().doc(cfg(SCHOOL)).get());
  });
  test('same-school teacher CAN read', async () => {
    await assertSucceeds(asTeacher().doc(cfg(SCHOOL)).get());
  });
  test('same-school parent CAN read (the parent app draws a map)', async () => {
    await assertSucceeds(asParent().doc(cfg(SCHOOL)).get());
  });
  test('unauthenticated client CANNOT read', async () => {
    await assertFails(asAnon().doc(cfg(SCHOOL)).get());
  });
  test("cross-school user CANNOT read another school's map config", async () => {
    await assertFails(asAdmin(OTHER).doc(cfg(SCHOOL)).get());
  });
  test('NO client may write map config — not even an admin', async () => {
    await assertFails(asAdmin().doc(cfg(SCHOOL)).set({ schoolId: SCHOOL, mode: 'google' }, { merge: true }));
  });
  test('no client may create a map config doc', async () => {
    await assertFails(asAdmin().doc(`mapsConfig/${SCHOOL}_injected`).set({ schoolId: SCHOOL, mode: 'google' }));
  });
  test('no client may delete a map config doc', async () => {
    await assertFails(asAdmin().doc(cfg(SCHOOL)).delete());
  });
});

describe('mapsSecrets — unreadable by EVERY client (access only; no values asserted)', () => {
  test('same-school admin CANNOT read secrets', async () => {
    await assertFails(asAdmin().doc(sec(SCHOOL)).get());
  });
  test('same-school teacher CANNOT read secrets', async () => {
    await assertFails(asTeacher().doc(sec(SCHOOL)).get());
  });
  test('same-school parent CANNOT read secrets', async () => {
    await assertFails(asParent().doc(sec(SCHOOL)).get());
  });
  test('unauthenticated client CANNOT read secrets', async () => {
    await assertFails(asAnon().doc(sec(SCHOOL)).get());
  });
  test('cross-school admin CANNOT read secrets', async () => {
    await assertFails(asAdmin(OTHER).doc(sec(SCHOOL)).get());
  });
  test('admin CANNOT write secrets', async () => {
    await assertFails(asAdmin().doc(sec(SCHOOL)).set({ schoolId: SCHOOL, serverKey: PLACEHOLDER }, { merge: true }));
  });
  test('admin CANNOT create a secrets doc', async () => {
    await assertFails(asAdmin().doc(`mapsSecrets/${SCHOOL}_injected`).set({ schoolId: SCHOOL, serverKey: PLACEHOLDER }));
  });
  test('admin CANNOT delete a secrets doc', async () => {
    await assertFails(asAdmin().doc(sec(SCHOOL)).delete());
  });
});

describe('mapsConfigAudit — staff-read, server-written, append-only', () => {
  test('same-school staff CAN read the audit trail', async () => {
    await assertSucceeds(asTeacher().doc(audit(SCHOOL)).get());
  });
  test('a parent CANNOT read the audit trail', async () => {
    await assertFails(asParent().doc(audit(SCHOOL)).get());
  });
  test('unauthenticated client CANNOT read the audit trail', async () => {
    await assertFails(asAnon().doc(audit(SCHOOL)).get());
  });
  test("cross-school staff CANNOT read another school's audit trail", async () => {
    await assertFails(asTeacher(OTHER).doc(audit(SCHOOL)).get());
  });
  test('no client may append to the audit trail', async () => {
    await assertFails(
      asAdmin().doc(`mapsConfigAudit/${SCHOOL}_A9999`).set({
        schoolId: SCHOOL, changedBy: ADMIN, keysTouched: ['forged'],
      })
    );
  });
  test('no client may rewrite history', async () => {
    await assertFails(asAdmin().doc(audit(SCHOOL)).update({ keysTouched: ['tampered'] }));
  });
  test('no client may delete history', async () => {
    await assertFails(asAdmin().doc(audit(SCHOOL)).delete());
  });
});
