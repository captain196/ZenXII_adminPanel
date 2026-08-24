/**
 * Institution Lifecycle — Firestore Rules security tests (2026-08-21).
 *
 * Proves the INSTITUTION axis at the rules layer, independently of the PHP
 * resolver. The two gates are implemented twice on purpose (defence in
 * depth); this file is the executable half of the parity claim — the PHP
 * half lives in tests/Unit/LifecycleResolverAndGuardTest.php.
 *
 * What is asserted here:
 *   1. ABSENT institution field  ⇒ behaves exactly as before (inertness).
 *   2. ACTIVE                    ⇒ read + write.
 *   3. CLOSING                   ⇒ read + write (the running year must finish).
 *   4. CLOSED                    ⇒ READ ONLY — this is the split that
 *                                  tenantActive() alone cannot express.
 *   5. ARCHIVED / PROSPECT /
 *      ONBOARDING                ⇒ no client access at all.
 *   6. Composition with the commercial axis is MOST-RESTRICTIVE-WINS.
 *   7. Cross-tenant isolation still holds in every institution state.
 *   8. schoolLifecycleEvents is unreachable by any client, in any state.
 *
 * Run: cd firebase-rules/tests && npx firebase emulators:exec --only firestore \
 *        --project=zenxii-rules-test "npx jest institution_lifecycle_sec --runInBand --forceExit"
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

const SCHOOL = 'SCH_LC_001';
const OTHER  = 'SCH_LC_002';
const TEACHER = 'STA_T1';
const PARENT  = 'PAR_P1';
const ADMIN   = 'ADM_A1';

let env;

function ctx(uid, role, schoolId) {
  return env.authenticatedContext(uid, {
    school_id: schoolId,
    schoolId:  schoolId,
    role:      role,
    user_id:   uid,
  }).firestore();
}
const teacher = (s = SCHOOL) => ctx(TEACHER, 'Teacher', s);
const parent  = (s = SCHOOL) => ctx(PARENT, 'Parent', s);
const admin   = (s = SCHOOL) => ctx(ADMIN, 'Admin', s);

/** Seed a tenant with a given commercial state and institution state. */
async function seedTenant(schoolId, commercial, institution) {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    await db.doc(`schoolControl/${schoolId}`).set({
      lifecycle: { state: commercial },
    });
    const school = { schoolId: schoolId, name: 'Test School' };
    if (institution !== undefined) {
      school.institution = institution === null ? {} : { state: institution };
    }
    await db.doc(`schools/${schoolId}`).set(school);
    await db.doc(`students/${schoolId}_STU1`).set({
      schoolId: schoolId, studentId: 'STU1', name: 'Child One',
    });
  });
}

/**
 * A representative client WRITE that funnels through isSameSchoolWrite().
 *
 * `leaveApplications` is used deliberately. Its create arm is
 * `isSameSchoolWrite()` in BOTH the currently-deployed ruleset and this
 * working copy, so the suite means the same thing whichever ruleset is
 * loaded — which is what makes it usable to validate a deployment artifact.
 *
 * Two fixtures were rejected, and the reasons are worth keeping:
 *   • `attendance` is `allow write: if false` (server-only), so it can never
 *     demonstrate a lifecycle verdict.
 *   • `userDevices` looks ideal in this working copy (create is a bare
 *     `isSameSchoolWrite()`), but the DEPLOYED ruleset still has it as
 *     `allow create: if isAuth()` — the tenant-binding fix for it is local
 *     and undeployed. A write there proves nothing about the lifecycle gate
 *     and silently passes when it should fail.
 *
 * The extra conditions below (status/applicantType/applicantId) are the
 * collection's own invariants; they are satisfied identically in every
 * lifecycle state, so any denial still comes from the lifecycle gate alone.
 */
function writeClientDoc(db, schoolId, uid = TEACHER) {
  return db.doc(`leaveApplications/${schoolId}_LV_${uid}`).set({
    schoolId: schoolId,
    status: 'pending',
    attendanceStamped: false,
    applicantId: uid,
    applicantType: 'staff',
    startDate: '2026-09-01',
    endDate: '2026-09-02',
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

// ── 1. Inertness: the axis must not exist for legacy tenants ─────────

describe('Institution axis — ABSENT means unchanged behaviour', () => {
  test('no institution field at all → reads and writes behave as before', async () => {
    await seedTenant(SCHOOL, 'active', undefined);
    await assertSucceeds(teacher().doc(`students/${SCHOOL}_STU1`).get());
    await assertSucceeds(writeClientDoc(teacher(), SCHOOL));
  });

  test('institution map present but with no state field → still allowed', async () => {
    await seedTenant(SCHOOL, 'active', null);
    await assertSucceeds(teacher().doc(`students/${SCHOOL}_STU1`).get());
    await assertSucceeds(writeClientDoc(teacher(), SCHOOL));
  });
});

// ── 2/3. Operating states ────────────────────────────────────────────

describe('Institution axis — operating states', () => {
  test('ACTIVE → read and write allowed', async () => {
    await seedTenant(SCHOOL, 'active', 'ACTIVE');
    await assertSucceeds(teacher().doc(`students/${SCHOOL}_STU1`).get());
    await assertSucceeds(writeClientDoc(teacher(), SCHOOL));
  });

  test('CLOSING → read AND write still allowed (the running year must finish)', async () => {
    await seedTenant(SCHOOL, 'active', 'CLOSING');
    await assertSucceeds(teacher().doc(`students/${SCHOOL}_STU1`).get());
    await assertSucceeds(writeClientDoc(teacher(), SCHOOL));
  });
});

// ── 4. The CLOSED split — the core of this test file ─────────────────

describe('Institution axis — CLOSED is READ-ONLY, not switched off', () => {
  test('CLOSED → parent can still READ their child record', async () => {
    await seedTenant(SCHOOL, 'active', 'CLOSED');
    await assertSucceeds(parent().doc(`students/${SCHOOL}_STU1`).get());
  });

  test('CLOSED → teacher can still READ', async () => {
    await seedTenant(SCHOOL, 'active', 'CLOSED');
    await assertSucceeds(teacher().doc(`students/${SCHOOL}_STU1`).get());
  });

  test('CLOSED → the school document itself remains readable', async () => {
    await seedTenant(SCHOOL, 'active', 'CLOSED');
    await assertSucceeds(teacher().doc(`schools/${SCHOOL}`).get());
  });

  test('CLOSED → client WRITE is DENIED', async () => {
    await seedTenant(SCHOOL, 'active', 'CLOSED');
    await assertFails(writeClientDoc(teacher(), SCHOOL));
  });

  test('CLOSED → admin client write is DENIED too (not a role bypass)', async () => {
    await seedTenant(SCHOOL, 'active', 'CLOSED');
    await assertFails(writeClientDoc(admin(), SCHOOL, ADMIN));
  });
});

// ── 5. Non-operating states deny everything ──────────────────────────

describe('Institution axis — non-operating states', () => {
  for (const state of ['ARCHIVED', 'PROSPECT', 'ONBOARDING']) {
    test(`${state} → client reads DENIED`, async () => {
      await seedTenant(SCHOOL, 'active', state);
      await assertFails(teacher().doc(`students/${SCHOOL}_STU1`).get());
      await assertFails(parent().doc(`students/${SCHOOL}_STU1`).get());
    });

    test(`${state} → client writes DENIED`, async () => {
      await seedTenant(SCHOOL, 'active', state);
      await assertFails(writeClientDoc(teacher(), SCHOOL));
    });
  }
});

// ── 6. Composition with the commercial axis ──────────────────────────

describe('Composition — most-restrictive-wins across both axes', () => {
  test('commercial suspended + institution ACTIVE → denied (commercial wins)', async () => {
    await seedTenant(SCHOOL, 'suspended', 'ACTIVE');
    await assertFails(teacher().doc(`students/${SCHOOL}_STU1`).get());
    await assertFails(writeClientDoc(teacher(), SCHOOL));
  });

  test('commercial active + institution ARCHIVED → denied (institution wins)', async () => {
    await seedTenant(SCHOOL, 'active', 'ARCHIVED');
    await assertFails(teacher().doc(`students/${SCHOOL}_STU1`).get());
  });

  test('commercial grace + institution CLOSING → still operating', async () => {
    await seedTenant(SCHOOL, 'grace', 'CLOSING');
    await assertSucceeds(teacher().doc(`students/${SCHOOL}_STU1`).get());
    await assertSucceeds(writeClientDoc(teacher(), SCHOOL));
  });

  test('every denying commercial state denies regardless of institution state', async () => {
    for (const commercial of ['past_due', 'suspended', 'expired', 'provisioning_incomplete']) {
      await env.clearFirestore();
      await seedTenant(SCHOOL, commercial, 'ACTIVE');
      await assertFails(teacher().doc(`students/${SCHOOL}_STU1`).get());
    }
  });

  test('every permitting commercial state still permits when institution is ACTIVE', async () => {
    for (const commercial of ['active', 'trialing', 'expiring_soon', 'grace']) {
      await env.clearFirestore();
      await seedTenant(SCHOOL, commercial, 'ACTIVE');
      await assertSucceeds(teacher().doc(`students/${SCHOOL}_STU1`).get());
    }
  });
});

// ── 7. Tenant isolation is unaffected by institution state ───────────

describe('Cross-tenant isolation holds in every institution state', () => {
  for (const state of ['ACTIVE', 'CLOSING', 'CLOSED']) {
    test(`school A (${state}) cannot read school B`, async () => {
      await seedTenant(SCHOOL, 'active', state);
      await seedTenant(OTHER, 'active', 'ACTIVE');
      await assertFails(teacher(SCHOOL).doc(`students/${OTHER}_STU1`).get());
      await assertFails(parent(SCHOOL).doc(`schools/${OTHER}`).get());
    });
  }

  test('a CLOSED school cannot write into an ACTIVE one', async () => {
    await seedTenant(SCHOOL, 'active', 'CLOSED');
    await seedTenant(OTHER, 'active', 'ACTIVE');
    await assertFails(writeClientDoc(teacher(SCHOOL), OTHER));
  });
});

// ── 8. The audit trail is unreachable by clients ─────────────────────

describe('schoolLifecycleEvents is server-only in every state', () => {
  for (const state of ['ACTIVE', 'CLOSING', 'CLOSED', 'ARCHIVED']) {
    test(`${state} → client cannot read or write a lifecycle event`, async () => {
      await seedTenant(SCHOOL, 'active', state);
      await env.withSecurityRulesDisabled(async (c) => {
        await c.firestore().doc(`schoolLifecycleEvents/LC_${SCHOOL}_CLOSING_abc`).set({
          schoolId: SCHOOL, fromState: 'ACTIVE', toState: 'CLOSING', actorId: 'SA1',
        });
      });
      await assertFails(admin().doc(`schoolLifecycleEvents/LC_${SCHOOL}_CLOSING_abc`).get());
      await assertFails(
        admin().doc(`schoolLifecycleEvents/LC_${SCHOOL}_FORGED`).set({ schoolId: SCHOOL })
      );
    });
  }

  test('a client cannot forge an institution state by writing the schools doc', async () => {
    await seedTenant(SCHOOL, 'active', 'CLOSED');
    // schools/{id} is write: if isSuperAdmin() — an ordinary admin must not
    // be able to flip their own school back to ACTIVE to regain writes.
    await assertFails(
      admin().doc(`schools/${SCHOOL}`).set({ institution: { state: 'ACTIVE' } }, { merge: true })
    );
  });
});
