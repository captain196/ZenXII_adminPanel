/**
 * R-X1-D1 — a WITHDRAWN request must not lock the date forever.
 *
 * Found on a real device. The app writes a deterministic id
 * ({school}_{staff}_{date}) with merge, so a re-submit is an UPDATE, and the
 * whole submission is ONE atomic WriteBatch.
 *
 * R-X1 shipped with allowed from-states ['pending','rejected'] — omitting
 * 'cancelled', the state its own WITHDRAW arm writes. So withdrawing a request
 * permanently barred the employee from ever filing for that day again, and
 * because the batch is atomic, ONE withdrawn date failed every other date in
 * the same submission. On the device that surfaced as
 * "You don't have access to this section" for all 13 selected days.
 *
 * The rule's own comment had anticipated exactly this failure mode for
 * already-filed dates; the withdraw state reintroduced it.
 */
const {initializeTestEnvironment, assertSucceeds, assertFails} =
  require('@firebase/rules-unit-testing');
const fs = require('fs');

const path = require('path');
// Defaults to the working-tree ruleset so this stays in the standing battery;
// CANDIDATE_RULES overrides it when verifying a ruleset before deployment.
const CANDIDATE = process.env.CANDIDATE_RULES || path.join(__dirname, '..', 'firestore.rules');
const SCHOOL = 'SCH_RX1_A', OWNER = 'uid-owner', PEER = 'uid-peer';
const REG = 'attendanceRegularizations';
let env;

const staff = (uid, school = SCHOOL) =>
  env.authenticatedContext(uid, {school_id: school, role: 'Teacher', user_id: uid}).firestore();

const reg = (staffId, status, extra = {}) => ({
  schoolId: SCHOOL, staffId, personType: 'staff', date: '2026-08-10',
  status, requestedStatus: 'P', reason: 'missed punch',
  reviewedBy: '', appliedMark: '', ...extra,
});

const id = s => `${REG}/${SCHOOL}_${OWNER}_${s}`;

beforeAll(async () => {
  const rules = fs.readFileSync(CANDIDATE, 'utf8');
  if (!rules.includes("in ['pending', 'rejected', 'cancelled']")) {
    throw new Error('candidate lacks the R-X1-D1 fix — refusing to report a false pass');
  }
  env = await initializeTestEnvironment({
    projectId: 'zenxii-rules-test',
    firestore: {rules, host: '127.0.0.1', port: 18080},
  });
  await env.withSecurityRulesDisabled(async ctx => {
    const db = ctx.firestore();
    await db.doc(`schoolControl/${SCHOOL}`).set({
      schoolId: SCHOOL, lifecycle: {state: 'active', computedAt: Date.now(), reason: 'active'},
    });
    await db.doc(`schools/${SCHOOL}`).set({schoolId: SCHOOL});
    await db.doc(id('cancelled')).set(reg(OWNER, 'cancelled'));
    await db.doc(id('pending')).set(reg(OWNER, 'pending'));
    await db.doc(id('rejected')).set(reg(OWNER, 'rejected'));
    await db.doc(id('approved')).set(reg(OWNER, 'approved', {reviewedBy: 'uid-hr', appliedMark: 'P'}));
  });
});

afterAll(async () => { if (env) await env.cleanup(); });

const patch = (docPath, body) => staff(OWNER).doc(docPath).update(body);

describe('R-X1-D1 — withdraw must not be a one-way door', () => {
  test('THE DEFECT: a WITHDRAWN date can be re-filed', async () => {
    await assertSucceeds(patch(id('cancelled'), reg(OWNER, 'pending')));
  });

  test('a REJECTED date can still be re-filed', async () => {
    await assertSucceeds(patch(id('rejected'), reg(OWNER, 'pending')));
  });

  test('a PENDING date can still be amended', async () => {
    await assertSucceeds(patch(id('pending'), {...reg(OWNER, 'pending'), requestedStatus: 'M'}));
  });

  // ── the fix must not widen anything else ──
  test('an APPROVED date is still immutable', async () => {
    await assertFails(patch(id('approved'), reg(OWNER, 'pending')));
  });

  test('re-filing still cannot name an approver', async () => {
    await assertFails(patch(id('cancelled'), {...reg(OWNER, 'pending'), reviewedBy: 'uid-hr'}));
  });

  test('re-filing still cannot pre-set the applied mark', async () => {
    await assertFails(patch(id('cancelled'), {...reg(OWNER, 'pending'), appliedMark: 'P'}));
  });

  test('re-filing still cannot self-approve', async () => {
    await assertFails(patch(id('cancelled'), reg(OWNER, 'approved')));
  });

  test('re-filing still cannot move the date', async () => {
    await assertFails(patch(id('cancelled'), {...reg(OWNER, 'pending'), date: '2026-08-11'}));
  });

  test('re-filing still cannot change the tenant', async () => {
    await assertFails(patch(id('cancelled'), {...reg(OWNER, 'pending'), schoolId: 'SCH_OTHER'}));
  });

  test('re-filing still cannot request a payroll-bearing mark', async () => {
    await assertFails(patch(id('cancelled'), {...reg(OWNER, 'pending'), requestedStatus: 'H'}));
  });

  test("a peer still cannot touch someone else's withdrawn request", async () => {
    await assertFails(staff(PEER).doc(id('cancelled')).update(reg(OWNER, 'pending')));
  });

  test('a withdrawn request still cannot be deleted', async () => {
    await assertFails(staff(OWNER).doc(id('cancelled')).delete());
  });
});
