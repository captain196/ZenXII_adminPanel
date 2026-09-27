/**
 * statutoryConfig — Firestore Rules unit tests (ZenX HRMS W2.2).
 *
 * Contract:
 *   match /statutoryConfig/{docId}
 *     allow read:  isAdmin() && isSameSchool()
 *     allow write: if false            // server-only
 *
 * Why the write arm is closed: a client able to write here could change every
 * employee's statutory deduction at once, and — because the documents are
 * effective-dated — could forge a window that rewrites what a PAST payroll
 * period resolves to. The validation that prevents that (bounded rates,
 * integer-paise ceilings, non-overlapping slab bands, non-overlapping effective
 * windows) lives in Statutory_config_service and cannot be expressed in rules,
 * so no client may write at all.
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

const SCHOOL = 'SCH_TEST_STAT_001';
const OTHER = 'SCH_TEST_STAT_002';

const ADMIN = 'admin-stat-1';
const OTHER_ADMIN = 'admin-stat-2';
const TEACHER = 'STA_STAT_T1';
const STUDENT = 'STU_STAT_1';

let env;

const doc = (school, from) => `statutoryConfig/${school}_${from}`;

function ctx(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
const asAdmin   = (school = SCHOOL, uid = ADMIN) => ctx(uid, school, 'admin');
const asTeacher = (school = SCHOOL) => ctx(TEACHER, school, 'Teacher');
const asParent  = (school = SCHOOL) => ctx(STUDENT, school, 'Parent');
const asAnon    = () => env.unauthenticatedContext().firestore();

const CFG = (school) => ({
  schoolId: school,
  effectiveFrom: '2026-04-01', effectiveTo: null,
  pf:  { employeeRate: 12, employerRate: 12, wageCeilingPaise: 1500000,
         applyCeiling: true, eligibleAboveCeiling: true },
  esi: { employeeRate: 0.75, employerRate: 3.25, wageThresholdPaise: 2100000, applicable: true },
  pt:  { state: '', slabs: [] },
  tds: { mode: 'FLAT_PERCENT', rate: 0 },
});

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    for (const s of [SCHOOL, OTHER]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test` });
      await db.doc(doc(s, '2026-04-01')).set(CFG(s));
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

describe('statutoryConfig — read is admin-only, same-school', () => {
  test('same-school admin CAN read', async () => {
    await assertSucceeds(asAdmin().doc(doc(SCHOOL, '2026-04-01')).get());
  });
  test('same-school staff CANNOT read', async () => {
    await assertFails(asTeacher().doc(doc(SCHOOL, '2026-04-01')).get());
  });
  test('a parent CANNOT read', async () => {
    await assertFails(asParent().doc(doc(SCHOOL, '2026-04-01')).get());
  });
  test('unauthenticated client CANNOT read', async () => {
    await assertFails(asAnon().doc(doc(SCHOOL, '2026-04-01')).get());
  });
});

describe('statutoryConfig — tenant isolation', () => {
  test("cross-school admin CANNOT read another school's statutory policy", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, '2026-04-01')).get());
  });
  test('each tenant reads only its own policy', async () => {
    await assertSucceeds(asAdmin(OTHER, OTHER_ADMIN).doc(doc(OTHER, '2026-04-01')).get());
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, '2026-04-01')).get());
  });
  test("cross-school admin CANNOT write into another school's policy", async () => {
    await assertFails(
      asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, '2027-04-01')).set(CFG(SCHOOL))
    );
  });
  test("cross-school admin CANNOT delete another school's policy", async () => {
    await assertFails(asAdmin(OTHER, OTHER_ADMIN).doc(doc(SCHOOL, '2026-04-01')).delete());
  });
});

describe('statutoryConfig — writes are server-only', () => {
  test('same-school admin CANNOT create a configuration', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, '2027-04-01')).set(CFG(SCHOOL)));
  });

  test('same-school admin CANNOT lower a PF rate', async () => {
    // The headline attack: one write changes every employee's PF deduction.
    await assertFails(asAdmin().doc(doc(SCHOOL, '2026-04-01')).update({ 'pf.employeeRate': 1 }));
  });

  test('same-school admin CANNOT raise the ESI threshold', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, '2026-04-01')).update({ 'esi.wageThresholdPaise': 99999999 }));
  });

  test('same-school admin CANNOT rewrite the PT slab table', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, '2026-04-01')).update({
      'pt.slabs': [{ fromPaise: 0, toPaise: null, amountPaise: 0 }],
    }));
  });

  test('same-school admin CANNOT forge an effective window over a past period', async () => {
    // Effective dating is what makes past runs reconstructable; a forged
    // window would silently change what a closed period resolves to.
    await assertFails(asAdmin().doc(doc(SCHOOL, '2020-04-01')).set({
      ...CFG(SCHOOL), effectiveFrom: '2020-04-01', effectiveTo: null,
    }));
  });

  test('same-school admin CANNOT delete a configuration', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, '2026-04-01')).delete());
  });

  test('staff CANNOT write', async () => {
    await assertFails(asTeacher().doc(doc(SCHOOL, '2026-04-01')).update({ 'pf.employeeRate': 0 }));
  });

  test('a parent CANNOT write', async () => {
    await assertFails(asParent().doc(doc(SCHOOL, '2028-04-01')).set(CFG(SCHOOL)));
  });

  test('unauthenticated client CANNOT write', async () => {
    await assertFails(asAnon().doc(doc(SCHOOL, '2029-04-01')).set(CFG(SCHOOL)));
  });

  test('forged schoolId in the payload is still denied', async () => {
    await assertFails(asAdmin().doc(doc(SCHOOL, '2030-04-01')).set({ ...CFG(SCHOOL), schoolId: OTHER }));
  });

  test('missing schoolId in the payload is still denied', async () => {
    const { schoolId, ...noTenant } = CFG(SCHOOL);
    await assertFails(asAdmin().doc(doc(SCHOOL, '2031-04-01')).set(noTenant));
  });
});
