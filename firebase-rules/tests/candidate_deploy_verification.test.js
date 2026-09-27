/**
 * CANDIDATE RULESET VERIFICATION — what is about to be deployed, exactly.
 *
 * The other suites patch the working-tree `firestore.rules`, which currently
 * carries other programmes' UNDEPLOYED work. Deploying that file would ship
 * their half-finished changes, so the candidate is built from the LIVE
 * deployed ruleset plus FNF-6 and R-X1 only, and this suite tests that file
 * rather than the working tree.
 *
 * Path is passed in so nothing here depends on repo state:
 *   CANDIDATE_RULES=/abs/path/candidate.rules npx jest candidate_deploy_verification
 */
const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');

const CANDIDATE = process.env.CANDIDATE_RULES;
const PROJECT_ID = 'zenxii-rules-test';

const SCHOOL = 'SCH_CAND_A';
const OTHER  = 'SCH_CAND_B';
const OWNER  = 'uid-owner';
const PEER   = 'uid-peer';
const ADMIN  = 'uid-admin';

const SET  = 'staffSettlements';
const REG  = 'attendanceRegularizations';

const OWN_SET     = `${SET}/${SCHOOL}_SET_${OWNER}_1`;
const PEER_SET    = `${SET}/${SCHOOL}_SET_${PEER}_1`;
const FOREIGN_SET = `${SET}/${OTHER}_SET_${OWNER}_1`;

const OWN_REG_PENDING  = `${REG}/${SCHOOL}_REG_${OWNER}_pending`;
const OWN_REG_REJECTED = `${REG}/${SCHOOL}_REG_${OWNER}_rejected`;
const OWN_REG_REVIEWED = `${REG}/${SCHOOL}_REG_${OWNER}_reviewed`;
const PEER_REG         = `${REG}/${SCHOOL}_REG_${PEER}_pending`;

let env;

// This file is a PRE-DEPLOYMENT tool: it verifies the exact ruleset about to be
// shipped, supplied via CANDIDATE_RULES. The standing battery supplies none, and
// failing there reported a red suite for a test that simply had nothing to do.
// Skip cleanly instead — the refusal to test the WRONG file is kept in beforeAll.
const suite = CANDIDATE ? describe : describe.skip;

const staff  = (uid, school = SCHOOL) =>
  env.authenticatedContext(uid, { school_id: school, role: 'Teacher', user_id: uid }).firestore();
const admin  = (uid, school = SCHOOL) =>
  env.authenticatedContext(uid, { school_id: school, role: 'admin', user_id: uid }).firestore();
const parent = uid =>
  env.authenticatedContext(uid, { school_id: SCHOOL, role: 'Parent', user_id: uid }).firestore();

function settlement(staffId, school = SCHOOL) {
  return {
    schoolId: school, staffId, staffName: 'Owner',
    status: 'approved', lastWorkingDay: '2026-03-31',
    rejectNote: 'queried by finance',
    cancelledReason: '',
    computation: { runId: 'RUN_1', policyVersion: 3 },
  };
}

function reg(staffId, status, extra = {}) {
  return {
    schoolId: SCHOOL, staffId, personType: 'staff',
    date: '2026-07-10', status,
    requestedStatus: 'P', reason: 'missed punch',
    reviewedBy: '', appliedMark: '',
    ...extra,
  };
}

beforeAll(async () => {
  if (!CANDIDATE) throw new Error('CANDIDATE_RULES not set — refusing to test the wrong file');
  const rules = fs.readFileSync(CANDIDATE, 'utf8');

  // Guard: prove BOTH blocks are in the file under test. Without this a suite
  // could "pass" against a ruleset that never contained the patches.
  if (!rules.includes('match /staffSettlements/{docId}')) {
    throw new Error('candidate lacks the FNF-6 block — refusing to report a false pass');
  }
  if (!rules.includes("request.resource.data.get('reviewedBy', '') == ''")) {
    throw new Error('candidate lacks the R-X1 block — refusing to report a false pass');
  }

  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules, host: '127.0.0.1', port: 18080 },
  });

  await env.withSecurityRulesDisabled(async ctx => {
    const db = ctx.firestore();
    for (const s of [SCHOOL, OTHER]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: s });
    }
    await db.doc(OWN_SET).set(settlement(OWNER));
    await db.doc(PEER_SET).set(settlement(PEER));
    await db.doc(FOREIGN_SET).set(settlement(OWNER, OTHER));

    await db.doc(OWN_REG_PENDING).set(reg(OWNER, 'pending'));
    await db.doc(OWN_REG_REJECTED).set(reg(OWNER, 'rejected'));
    await db.doc(OWN_REG_REVIEWED).set(reg(OWNER, 'pending', { reviewedBy: 'uid-hr', appliedMark: 'P' }));
    await db.doc(PEER_REG).set(reg(PEER, 'pending'));
  });
});

afterAll(async () => { if (env) await env.cleanup(); });

// ── FNF-6 ────────────────────────────────────────────────────────────────

suite('FNF-6 — employee settlement visibility', () => {
  test('an employee READS THEIR OWN settlement', async () => {
    await assertSucceeds(staff(OWNER).doc(OWN_SET).get());
  });

  test("an employee cannot read a colleague's settlement", async () => {
    await assertFails(staff(OWNER).doc(PEER_SET).get());
  });

  test('an employee cannot read another tenant\'s settlement', async () => {
    await assertFails(staff(OWNER).doc(FOREIGN_SET).get());
  });

  test('an employee cannot WRITE their own settlement', async () => {
    await assertFails(staff(OWNER).doc(OWN_SET).set({ status: 'approved' }, { merge: true }));
  });

  test('an employee cannot APPROVE their own settlement', async () => {
    await assertFails(staff(OWNER).doc(OWN_SET).update({ status: 'approved' }));
  });

  test('an employee cannot DELETE their settlement', async () => {
    await assertFails(staff(OWNER).doc(OWN_SET).delete());
  });

  test('an employee cannot CREATE a settlement', async () => {
    await assertFails(staff(OWNER).doc(`${SET}/${SCHOOL}_SET_${OWNER}_9`).set(settlement(OWNER)));
  });

  test('a PARENT reads nothing', async () => {
    await assertFails(parent('uid-parent').doc(OWN_SET).get());
  });

  test('a same-school ADMIN still reads any settlement', async () => {
    await assertSucceeds(admin(ADMIN).doc(PEER_SET).get());
  });

  test('an admin still cannot write one (server-only)', async () => {
    await assertFails(admin(ADMIN).doc(OWN_SET).set({ status: 'paid' }, { merge: true }));
  });

  test('a cross-tenant admin cannot read it', async () => {
    await assertFails(admin('uid-other-admin', OTHER).doc(OWN_SET).get());
  });

  // The query the app actually issues — rules evaluate lists differently.
  test('the employee can LIST their own settlements', async () => {
    await assertSucceeds(
      staff(OWNER).collection(SET).where('schoolId', '==', SCHOOL).where('staffId', '==', OWNER).get()
    );
  });

  test('an UNSCOPED list of the collection is refused', async () => {
    await assertFails(staff(OWNER).collection(SET).get());
  });

  test('an employee cannot list a colleague\'s settlements', async () => {
    await assertFails(
      staff(OWNER).collection(SET).where('schoolId', '==', SCHOOL).where('staffId', '==', PEER).get()
    );
  });
});

// ── R-X1 ─────────────────────────────────────────────────────────────────

suite('R-X1 — regularization withdraw / re-file', () => {
  test('the owner WITHDRAWS a pending request (pending -> cancelled)', async () => {
    await assertSucceeds(
      staff(OWNER).doc(OWN_REG_PENDING).update({ ...reg(OWNER, 'cancelled') })
    );
  });

  test('the owner RE-FILES a rejected request (rejected -> pending)', async () => {
    await assertSucceeds(
      staff(OWNER).doc(OWN_REG_REJECTED).update({ ...reg(OWNER, 'pending'), requestedStatus: 'M' })
    );
  });

  test('the owner cannot touch a request already REVIEWED', async () => {
    await assertFails(
      staff(OWNER).doc(OWN_REG_REVIEWED).update({ ...reg(OWNER, 'cancelled'), reviewedBy: 'uid-hr', appliedMark: 'P' })
    );
  });

  test('the owner cannot self-APPROVE by writing a decided status', async () => {
    await assertFails(staff(OWNER).doc(OWN_REG_PENDING).update({ ...reg(OWNER, 'approved') }));
  });

  /**
   * An APPROVED request is settled — it has already been stamped onto the
   * attendance record, so reopening it from the app would silently unpick a
   * decision. This is the state that actually has to hold, and `approved` is
   * deliberately absent from the rule's allowed from-states.
   *
   * (Re-filing a REJECTED request DOES clear reviewedBy/appliedMark, and that
   * is the intended workflow rather than a hole: the request starts a fresh
   * review. An earlier version of this test asserted the opposite and was
   * wrong about the product, not about the rule.)
   */
  test('an APPROVED request cannot be reopened by the employee', async () => {
    await env.withSecurityRulesDisabled(async ctx => {
      await ctx.firestore().doc(`${REG}/${SCHOOL}_REG_${OWNER}_approved`)
        .set(reg(OWNER, 'approved', { reviewedBy: 'uid-hr', appliedMark: 'P' }));
    });

    await assertFails(
      staff(OWNER).doc(`${REG}/${SCHOOL}_REG_${OWNER}_approved`)
        .update({ ...reg(OWNER, 'pending') })
    );
  });

  /** Nor may it be withdrawn once approved. */
  test('an APPROVED request cannot be withdrawn by the employee', async () => {
    await assertFails(
      staff(OWNER).doc(`${REG}/${SCHOOL}_REG_${OWNER}_approved`)
        .update({ ...reg(OWNER, 'cancelled') })
    );
  });

  test('the owner cannot move the request to another DATE', async () => {
    await assertFails(
      staff(OWNER).doc(OWN_REG_PENDING).update({ ...reg(OWNER, 'pending'), date: '2026-07-11' })
    );
  });

  test('the owner cannot reassign it to another EMPLOYEE', async () => {
    await assertFails(
      staff(OWNER).doc(OWN_REG_PENDING).update({ ...reg(PEER, 'pending') })
    );
  });

  test('the owner cannot change personType', async () => {
    await assertFails(
      staff(OWNER).doc(OWN_REG_PENDING).update({ ...reg(OWNER, 'pending'), personType: 'student' })
    );
  });

  test('the owner cannot move it to another TENANT', async () => {
    await assertFails(
      staff(OWNER).doc(OWN_REG_PENDING).update({ ...reg(OWNER, 'pending'), schoolId: OTHER })
    );
  });

  test("a peer cannot withdraw someone else's request", async () => {
    await assertFails(staff(PEER).doc(OWN_REG_PENDING).update({ ...reg(OWNER, 'cancelled') }));
  });

  test('the owner cannot DELETE a request', async () => {
    await assertFails(staff(OWNER).doc(OWN_REG_PENDING).delete());
  });

  test('an unsupported requestedStatus is refused', async () => {
    await assertFails(
      staff(OWNER).doc(OWN_REG_PENDING).update({ ...reg(OWNER, 'pending'), requestedStatus: 'A' })
    );
  });
});
