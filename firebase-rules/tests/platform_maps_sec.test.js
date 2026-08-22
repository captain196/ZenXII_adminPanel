/**
 * platformConfig / platformSecrets — Firestore Rules tests (2026-08-16).
 *
 * The ZenX platform maps default lives outside every tenant. isSameSchool()
 * compares resource.data.schoolId to the caller's claim and so cannot express a
 * tenant-less document; the choice was therefore between a permissive isAuth()
 * read and no client read at all.
 *
 * DENY-ALL was chosen. Clients receive the RESOLVED PUBLIC configuration for
 * their own surface through Maps_config_service::getClientConfig(); nobody
 * reads the platform document directly. Writes come from the superadmin backend
 * over the Admin SDK, which bypasses rules — so `false` locks out clients
 * without blocking the platform operator.
 *
 * These tests prove the boundary holds for every client identity that exists.
 *
 * Run: cd firebase-rules/tests && npx firebase emulators:exec --only firestore \
 *      --project=zenxii-rules-test "npx jest platform_maps_sec --runInBand --forceExit"
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

const SCHOOL_A = 'SCH_TEST_PLAT_A';
const SCHOOL_B = 'SCH_TEST_PLAT_B';

let env;

const PLATFORM_CFG = 'platformConfig/maps';
const PLATFORM_SEC = 'platformSecrets/maps';

async function seed() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL_A, SCHOOL_B]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: 1, reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: 'Platform Test ' + s });
      await db.doc(`mapsConfig/${s}_${s}`).set({
        schoolId: s, mode: 'global', global_provider: 'maptiler',
        providers: { maptiler: { enabled: true, public: { api_key: 'TENANT_' + s } } },
      });
    }
    // Written the way the superadmin backend writes it (Admin SDK, no rules).
    await db.doc(PLATFORM_CFG).set({
      capabilities: { render: { provider: 'maptiler', enabled: true, public: { api_key: 'PLATFORM_PUBLIC' } } },
      environment: 'uat', commercial_status: 'free_noncommercial',
    });
    await db.doc(PLATFORM_SEC).set({ maptiler: { server_key: 'PLATFORM_SECRET' } });

    // PLAT-11 evidence, gathered HERE on purpose: the rules-unit-testing
    // library allows only ONE withSecurityRulesDisabled() context per test —
    // a second call throws "Firestore has already been started". So the
    // admin-SDK round trip is exercised inside this single call and asserted
    // afterwards. It proves deny-all locks out CLIENTS, not the platform
    // operator, which is the whole point of the rule.
    await db.doc(PLATFORM_CFG).set(
      { capabilities: { render: { provider: 'maplibre', enabled: true } } }, { merge: true });
    const rt = await db.doc(PLATFORM_CFG).get();
    adminSdkProof = (rt.data() || {}).capabilities?.render?.provider || null;
    // restore the seeded provider so the other tests see a stable world
    await db.doc(PLATFORM_CFG).set(
      { capabilities: { render: { provider: 'maptiler', enabled: true, public: { api_key: 'PLATFORM_PUBLIC' } } } },
      { merge: true });
  });
}

/** Populated by seed(); asserted by PLAT-11. */
let adminSdkProof = null;

function staff(school)  { return env.authenticatedContext('STA_' + school, { school_id: school, role: 'Teacher', user_id: 'STA_' + school }).firestore(); }
function admin(school)  { return env.authenticatedContext('ADM_' + school, { school_id: school, role: 'admin',   user_id: 'ADM_' + school }).firestore(); }
function parent(school) { return env.authenticatedContext('STU_' + school, { school_id: school, role: 'Parent',  student_id: 'STU_' + school, student_ids: ['STU_' + school] }).firestore(); }

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seed(); });

describe('platformConfig / platformSecrets — deny-all to every client', () => {

  // ── platformConfig ─────────────────────────────────────────────────────

  test('PLAT-1 school ADMIN cannot read platformConfig', async () => {
    await assertFails(admin(SCHOOL_A).doc(PLATFORM_CFG).get());
  });

  test('PLAT-2 school ADMIN cannot write platformConfig', async () => {
    await assertFails(admin(SCHOOL_A).doc(PLATFORM_CFG).set({ hijacked: true }));
  });

  test('PLAT-3 staff cannot read platformConfig', async () => {
    await assertFails(staff(SCHOOL_A).doc(PLATFORM_CFG).get());
  });

  test('PLAT-4 parent cannot read platformConfig', async () => {
    await assertFails(parent(SCHOOL_A).doc(PLATFORM_CFG).get());
  });

  test('PLAT-5 unauthenticated cannot read platformConfig', async () => {
    await assertFails(env.unauthenticatedContext().firestore().doc(PLATFORM_CFG).get());
  });

  test('PLAT-6 nobody can enumerate the platformConfig collection', async () => {
    await assertFails(admin(SCHOOL_A).collection('platformConfig').get());
    await assertFails(parent(SCHOOL_A).collection('platformConfig').get());
  });

  // ── platformSecrets ────────────────────────────────────────────────────

  test('PLAT-7 school admin cannot read platformSecrets', async () => {
    await assertFails(admin(SCHOOL_A).doc(PLATFORM_SEC).get());
  });

  test('PLAT-8 school admin cannot write platformSecrets', async () => {
    await assertFails(admin(SCHOOL_A).doc(PLATFORM_SEC).set({ maptiler: { server_key: 'stolen' } }));
  });

  test('PLAT-9 staff and parent cannot read platformSecrets', async () => {
    await assertFails(staff(SCHOOL_A).doc(PLATFORM_SEC).get());
    await assertFails(parent(SCHOOL_A).doc(PLATFORM_SEC).get());
  });

  test('PLAT-10 nobody can enumerate platformSecrets', async () => {
    await assertFails(admin(SCHOOL_A).collection('platformSecrets').get());
  });

  // ── the superadmin backend path still works ────────────────────────────

  test('PLAT-11 the Admin SDK path (rules bypassed) can still manage the platform', () => {
    // Gathered during seed() — see the comment there for why it cannot live in
    // this test body. Deny-all must stop clients WITHOUT stopping the operator.
    expect(adminSdkProof).toBe('maplibre');
  });

  // ── existing tenant behaviour is unaffected ────────────────────────────

  test('PLAT-12 tenant mapsConfig read is UNCHANGED for its own school', async () => {
    await assertSucceeds(admin(SCHOOL_A).doc(`mapsConfig/${SCHOOL_A}_${SCHOOL_A}`).get());
    await assertSucceeds(staff(SCHOOL_A).doc(`mapsConfig/${SCHOOL_A}_${SCHOOL_A}`).get());
  });

  test('PLAT-13 school A cannot read school B mapsConfig (cross-tenant)', async () => {
    await assertFails(admin(SCHOOL_A).doc(`mapsConfig/${SCHOOL_B}_${SCHOOL_B}`).get());
  });

  test('PLAT-14 school A cannot write school B mapsConfig', async () => {
    await assertFails(admin(SCHOOL_A).doc(`mapsConfig/${SCHOOL_B}_${SCHOOL_B}`).set({ hijacked: true }));
  });

  test('PLAT-15 tenant mapsConfig remains server-write-only for its OWN school too', async () => {
    await assertFails(admin(SCHOOL_A).doc(`mapsConfig/${SCHOOL_A}_${SCHOOL_A}`).set({ tampered: true }));
  });

  test('PLAT-16 tenant mapsSecrets stays deny-all', async () => {
    await assertFails(admin(SCHOOL_A).doc(`mapsSecrets/${SCHOOL_A}_${SCHOOL_A}`).get());
  });
});
