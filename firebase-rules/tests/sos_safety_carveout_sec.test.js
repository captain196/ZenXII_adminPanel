/**
 * SOS safety carve-out — Firestore Rules tests (2026-08-21).
 *
 * Proves that raising a school-bus emergency alert does NOT depend on the
 * COMMERCIAL axis, while proving equally hard that the carve-out is not a
 * back door into anything else.
 *
 * Background: B2_lifecycle_job / Superadmin_plans::expire_check flip a tenant
 * to `suspended` automatically when its grace period lapses. That job is
 * unattended and date-driven, so it can fire while a bus is mid-route. The
 * Teacher app writes sosAlerts directly to Firestore (no PHP fallback), so
 * before this carve-out a billing job could silently disable the emergency
 * channel. In India a panic/emergency button in a public service vehicle —
 * school buses included — is regulated under AIS 140 (MoRTH).
 *
 * Run: cd firebase-rules/tests && npx firebase emulators:exec --only firestore \
 *        --project=zenxii-rules-test "npx jest sos_safety_carveout --runInBand --forceExit"
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

const SCHOOL = 'SCH_SOS_001';
const OTHER  = 'SCH_SOS_002';
const DRIVER = 'STA_DRV1';
const PARENT = 'PAR_P1';

let env;

function ctx(uid, role, schoolId) {
  return env.authenticatedContext(uid, {
    school_id: schoolId, schoolId: schoolId, role: role, user_id: uid,
  }).firestore();
}
const driver = (s = SCHOOL) => ctx(DRIVER, 'Teacher', s);
const parent = (s = SCHOOL) => ctx(PARENT, 'Parent', s);

async function seedTenant(schoolId, commercial, institution, adminDisabled) {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    await db.doc(`schoolControl/${schoolId}`).set({ lifecycle: { state: commercial } });
    const school = { schoolId: schoolId, name: 'Bus School' };
    if (institution) school.institution = { state: institution };
    if (adminDisabled) school.adminDisabled = { value: true };
    await db.doc(`schools/${schoolId}`).set(school);
  });
}

function sos(overrides) {
  return Object.assign({
    schoolId: SCHOOL,
    alertType: 'emergency',
    severity: 'Critical',
    tripId: 'TRP1',
    raisedBy: DRIVER,
    app_version: '1.0.0',
    client_platform: 'android',
    client_timestamp: 1751000000000,
  }, overrides || {});
}

const alertRef = (db, id) => db.doc(`sosAlerts/${id || SCHOOL + '_A1'}`);

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8') },
  });
}, 60000);

afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); });

// ── The carve-out itself ─────────────────────────────────────────────

describe('SOS survives the commercial axis', () => {
  test('ACTIVE tenant → driver raises SOS (baseline)', async () => {
    await seedTenant(SCHOOL, 'active', 'ACTIVE');
    await assertSucceeds(alertRef(driver()).set(sos()));
  });

  for (const commercial of ['suspended', 'past_due', 'expired', 'provisioning_incomplete']) {
    test(`commercial ${commercial} → driver can STILL raise SOS`, async () => {
      await seedTenant(SCHOOL, commercial, 'ACTIVE');
      await assertSucceeds(alertRef(driver()).set(sos()));
    });
  }

  test('operator adminDisabled → driver can still raise SOS', async () => {
    await seedTenant(SCHOOL, 'active', 'ACTIVE', true);
    await assertSucceeds(alertRef(driver()).set(sos()));
  });

  test('CLOSING school still runs buses → SOS allowed', async () => {
    await seedTenant(SCHOOL, 'suspended', 'CLOSING');
    await assertSucceeds(alertRef(driver()).set(sos()));
  });
});

// ── The carve-out must not become a back door ────────────────────────

describe('SOS carve-out is bounded', () => {
  for (const state of ['CLOSED', 'ARCHIVED']) {
    test(`${state} school → SOS DENIED (no buses to keep safe)`, async () => {
      await seedTenant(SCHOOL, 'active', state);
      await assertFails(alertRef(driver()).set(sos()));
    });
  }

  test('suspended tenant still cannot READ the alerts back', async () => {
    await seedTenant(SCHOOL, 'suspended', 'ACTIVE');
    await env.withSecurityRulesDisabled(async (c) => {
      await c.firestore().doc(`sosAlerts/${SCHOOL}_SEED`).set(sos());
    });
    await assertFails(alertRef(driver(), SCHOOL + '_SEED').get());
  });

  test('suspended tenant cannot write anything ELSE', async () => {
    // `leaveApplications` deliberately, NOT `userDevices`: the deployed
    // ruleset still has userDevices as a bare `allow create: if isAuth()`
    // (its tenant-binding fix is local and undeployed), so a write there
    // would succeed for reasons unrelated to the lifecycle gate and the
    // assertion would be meaningless against a deployment artifact.
    await seedTenant(SCHOOL, 'suspended', 'ACTIVE');
    await assertFails(driver().doc(`leaveApplications/${SCHOOL}_LV1`).set({
      schoolId: SCHOOL, status: 'pending', attendanceStamped: false,
      applicantId: DRIVER, applicantType: 'staff',
      startDate: '2026-09-01', endDate: '2026-09-02',
    }));
  });

  test('cross-tenant SOS injection is denied', async () => {
    await seedTenant(SCHOOL, 'suspended', 'ACTIVE');
    await seedTenant(OTHER, 'active', 'ACTIVE');
    await assertFails(
      driver(SCHOOL).doc(`sosAlerts/${OTHER}_X1`).set(sos({ schoolId: OTHER }))
    );
  });

  test('a parent cannot raise an SOS even on a suspended tenant', async () => {
    await seedTenant(SCHOOL, 'suspended', 'ACTIVE');
    await assertFails(alertRef(parent()).set(sos({ raisedBy: PARENT })));
  });

  test('unauthenticated cannot raise an SOS', async () => {
    await seedTenant(SCHOOL, 'suspended', 'ACTIVE');
    await assertFails(env.unauthenticatedContext().firestore()
      .doc(`sosAlerts/${SCHOOL}_U1`).set(sos()));
  });

  test('enum validation still applies on a suspended tenant', async () => {
    await seedTenant(SCHOOL, 'suspended', 'ACTIVE');
    await assertFails(alertRef(driver()).set(sos({ alertType: 'prank' })));
    await assertFails(alertRef(driver()).set(sos({ severity: 'Catastrophic' })));
  });

  test('client metadata is still required on a suspended tenant', async () => {
    await seedTenant(SCHOOL, 'suspended', 'ACTIVE');
    const noMeta = sos();
    delete noMeta.app_version;
    delete noMeta.client_platform;
    delete noMeta.client_timestamp;
    await assertFails(alertRef(driver()).set(noMeta));
  });

  test('a driver still cannot update or delete an alert', async () => {
    await seedTenant(SCHOOL, 'suspended', 'ACTIVE');
    await env.withSecurityRulesDisabled(async (c) => {
      await c.firestore().doc(`sosAlerts/${SCHOOL}_A1`).set(sos());
    });
    await assertFails(alertRef(driver()).update({ severity: 'Low' }));
    await assertFails(alertRef(driver()).delete());
  });
});
