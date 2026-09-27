/**
 * NEW-SCHOOL ONBOARDING — A TENANT WITH NO `schoolControl` DOCUMENT.
 *
 * ── THE PRODUCTION OBSERVATION ────────────────────────────────────────────
 * Measured 2026-08-22: the estate holds **9 real tenants but only 7
 * `schoolControl` documents**. Two tenants exist in `schools` with none:
 *
 *     SCH_202512        (stub, 1 field)
 *     SCH_D94FE8F7AD    "IIT Kanpur" — 30 fields, a fully-shaped tenant
 *
 * ── WHY THAT MATTERS ──────────────────────────────────────────────────────
 * Every client authorization predicate funnels through `tenantActive()`:
 *
 *     function tenantActive(schoolId) {
 *       let ctrl = get(/databases/$(database)/documents/schoolControl/$(schoolId));
 *       let sch  = get(/databases/$(database)/documents/schools/$(schoolId));
 *       return ctrl != null
 *         && ctrl.data.lifecycle.state in ['active','trialing','expiring_soon','grace']
 *         && ( sch == null || !('adminDisabled' in sch.data) || sch.data.adminDisabled.value != true );
 *     }
 *
 * `isStaff()`, `isAdmin()` and `isSameSchool()` all require it. With no
 * `schoolControl` document, `ctrl` is null and every one of them is false.
 *
 * So a school can be fully created in `schools` — name, address, board, classes,
 * sections, the lot — and its Parent and Teacher apps still cannot read a single
 * document. The admin panel keeps working, because PHP runs on a service account
 * that bypasses rules entirely, so the failure is invisible from the back office
 * and total on the phones.
 *
 * This suite proves that consequence rather than asserting it, and pins the
 * onboarding invariant: **creating a tenant must create its `schoolControl`
 * document, or the tenant is born mute to its own users.**
 *
 * Runs against the DEPLOYED production ruleset via ONBOARD_RULES.
 */

const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');

const PROJECT_ID = 'zenxii-rules-test';

/** A correctly onboarded tenant — schools + schoolControl. */
const GOOD = 'SCH_TENANTGOOD';
/** The observed defect shape — present in `schools`, absent from `schoolControl`. */
const ORPHAN = 'SCH_TENANTORPH';

const RULES_PATH = process.env.ONBOARD_RULES || '';
const HAVE_RULES = RULES_PATH && fs.existsSync(RULES_PATH);

jest.setTimeout(60000);

let env;
const DBS = {};

beforeAll(async () => {
  if (!HAVE_RULES) return;
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });

  // Contexts built once, before the first withSecurityRulesDisabled() seed.
  DBS.staffGood   = env.authenticatedContext('STA_GOOD', { school_id: GOOD,   schoolId: GOOD,   role: 'Teacher' }).firestore();
  DBS.staffOrphan = env.authenticatedContext('STA_ORPH', { school_id: ORPHAN, schoolId: ORPHAN, role: 'Teacher' }).firestore();
  DBS.adminOrphan = env.authenticatedContext('ADM_ORPH', { school_id: ORPHAN, schoolId: ORPHAN, role: 'Admin' }).firestore();
  DBS.parentOrphan = env.authenticatedContext('STU_ORPH', {
    school_id: ORPHAN, schoolId: ORPHAN, role: 'Parent',
    student_id: 'STU_ORPH', student_ids: ['STU_ORPH'],
  }).firestore();
});

afterAll(async () => { if (env) await env.cleanup(); });

beforeEach(async () => {
  await env.clearFirestore();
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();

    // Correctly onboarded.
    await db.collection('schools').doc(GOOD).set({ schoolId: GOOD, name: 'Good School' });
    await db.collection('schoolControl').doc(GOOD).set({ lifecycle: { state: 'active' } });

    // The defect: a complete tenant record, no control document.
    await db.collection('schools').doc(ORPHAN).set({
      schoolId: ORPHAN, name: 'Orphan School', city: 'X', board: 'CBSE',
    });
    // NO schoolControl/{ORPHAN} — this is the whole point.

    // Identical business data in both tenants, so any difference in outcome is
    // attributable to the control document and nothing else.
    for (const s of [GOOD, ORPHAN]) {
      await db.collection('feeDemands').doc(`${s}_D1`).set({
        schoolId: s, session: '2026-27', studentId: `STU_${s}`, netAmount: 540, balance: 540,
      });
      await db.collection('students').doc(`${s}_STU`).set({ schoolId: s, studentId: `STU_${s}` });
      await db.collection('sections').doc(`${s}_SEC`).set({ schoolId: s, name: 'A' });
    }
  });
});

const maybe = HAVE_RULES ? describe : describe.skip;

maybe('onboarding · a tenant with no schoolControl document', () => {

  // ══ THE CONTROL — a correctly onboarded tenant works ═════════════════════

  test('correctly onboarded: staff CAN read their own school\'s demands', async () => {
    await assertSucceeds(DBS.staffGood.collection('feeDemands').doc(`${GOOD}_D1`).get());
  });

  test('correctly onboarded: staff CAN read their own sections', async () => {
    await assertSucceeds(DBS.staffGood.collection('sections').doc(`${GOOD}_SEC`).get());
  });

  // ══ THE DEFECT — the orphan tenant is mute ══════════════════════════════

  test('orphan tenant: STAFF cannot read their own school\'s fee demands', async () => {
    await assertFails(DBS.staffOrphan.collection('feeDemands').doc(`${ORPHAN}_D1`).get());
  });

  test('orphan tenant: ADMIN cannot read their own school\'s fee demands', async () => {
    await assertFails(DBS.adminOrphan.collection('feeDemands').doc(`${ORPHAN}_D1`).get());
  });

  test('orphan tenant: a PARENT cannot read their own child\'s demand', async () => {
    await assertFails(DBS.parentOrphan.collection('feeDemands').doc(`${ORPHAN}_D1`).get());
  });

  test('orphan tenant: staff cannot read the student roster', async () => {
    await assertFails(DBS.staffOrphan.collection('students').doc(`${ORPHAN}_STU`).get());
  });

  test('orphan tenant: staff cannot read sections', async () => {
    await assertFails(DBS.staffOrphan.collection('sections').doc(`${ORPHAN}_SEC`).get());
  });

  /**
   * Not a scoping failure — a TOTAL one. The orphan tenant's staff cannot read
   * even the school document that does exist for them.
   */
  test('orphan tenant: staff cannot read their own school document', async () => {
    await assertFails(DBS.staffOrphan.collection('schools').doc(ORPHAN).get());
  });

  /**
   * And it is genuinely the CONTROL DOCUMENT that decides — not the tenant id,
   * not the seeded data. Adding the missing document makes the same request by
   * the same caller succeed, with nothing else changed.
   */
  test('adding the missing schoolControl document repairs access completely', async () => {
    await assertFails(DBS.staffOrphan.collection('feeDemands').doc(`${ORPHAN}_D1`).get());

    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().collection('schoolControl').doc(ORPHAN)
        .set({ lifecycle: { state: 'active' } });
    });

    await assertSucceeds(DBS.staffOrphan.collection('feeDemands').doc(`${ORPHAN}_D1`).get());
  });

  /**
   * A lifecycle state OUTSIDE the permitted set denies just as firmly — so the
   * document's presence is necessary but not sufficient, and onboarding must
   * write a valid state rather than any state.
   */
  test('a schoolControl document with an unrecognised state still denies', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().collection('schoolControl').doc(ORPHAN)
        .set({ lifecycle: { state: 'provisioning' } });
    });

    await assertFails(DBS.staffOrphan.collection('feeDemands').doc(`${ORPHAN}_D1`).get());
  });

  /**
   * TENANT ISOLATION IS UNAFFECTED BY THE DEFECT.
   *
   * The orphan tenant being denied must not be mistaken for tenant isolation
   * working — so this proves isolation independently: a fully-onboarded tenant
   * still cannot reach another tenant's data.
   */
  test('a healthy tenant still cannot read the orphan tenant\'s data', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().collection('schoolControl').doc(ORPHAN)
        .set({ lifecycle: { state: 'active' } });
    });

    await assertFails(DBS.staffGood.collection('feeDemands').doc(`${ORPHAN}_D1`).get());
  });
});
