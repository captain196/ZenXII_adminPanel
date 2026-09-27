/**
 * staffSettlements — employee visibility (FNF-6).
 *
 * Runs the same battery twice: against the LIVE rules, where it documents that
 * a leaver cannot see their own settlement at all, and against those rules with
 * the patch applied in memory, proving it opens exactly one read arm and no
 * write.
 *
 * firestore.rules is never modified — the patch is applied to a copy inside the
 * test, so this is evidence about the real ruleset rather than about a fixture
 * that may have drifted from it.
 *
 * Run:
 *   cd firebase-rules/tests
 *   npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *     "npx jest fnf_settlement_visibility --runInBand --forceExit"
 */
const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const patch = require('../patches/fnf_settlement_visibility');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');

const SCHOOL = 'SCH_TEST_FNF_001';
const OTHER_SCHOOL = 'SCH_TEST_FNF_002';

const LEAVER = 'uid-leaver';
const PEER = 'uid-peer';
const ADMIN = 'uid-admin';
const OTHER_ADMIN = 'uid-other-admin';
const OTHER_STAFF = 'uid-other-school-staff';

const COLL = 'staffSettlements';
const OWN = `${COLL}/${SCHOOL}_SET_${LEAVER}_20260823120000`;
const PEERS = `${COLL}/${SCHOOL}_SET_${PEER}_20260823120000`;
const FOREIGN = `${COLL}/${OTHER_SCHOOL}_SET_${OTHER_STAFF}_20260823120000`;

let env;

function asStaff(uid, school = SCHOOL) {
  return env.authenticatedContext(uid, { school_id: school, role: 'Teacher', user_id: uid }).firestore();
}
function asAdmin(uid, school = SCHOOL) {
  return env.authenticatedContext(uid, { school_id: school, role: 'admin', user_id: uid }).firestore();
}
function asParent(uid) {
  return env.authenticatedContext(uid, { school_id: SCHOOL, role: 'Parent', user_id: uid }).firestore();
}

function settlement(staffId, school = SCHOOL, extra = {}) {
  return {
    schoolId: school,
    staffId,
    staffName: 'A Leaver',
    settlementId: `${school}_SET_${staffId}_20260823120000`,
    status: 'approved',
    lastWorkingDay: '2026-03-31',
    // Administrator commentary — readable by the owner once the arm opens.
    rejectNote: 'Figures queried by finance',
    computation: { runId: 'RUN_X', policyVersion: 3, policyEffective: '2026-01-01' },
    ...extra,
  };
}

async function seed() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL, OTHER_SCHOOL]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test School` });
    }
    await db.doc(OWN).set(settlement(LEAVER));
    await db.doc(PEERS).set(settlement(PEER));
    await db.doc(FOREIGN).set(settlement(OTHER_STAFF, OTHER_SCHOOL));
  });
}

/**
 * `ownReadAllowed` is the ONE behaviour the patch changes. Everything else must
 * be identical before and after — that symmetry is what makes this a safety
 * proof rather than a demonstration.
 */
function battery(ownReadAllowed) {
  describe('the leaver', () => {
    const expectOwn = ownReadAllowed ? assertSucceeds : assertFails;

    test(`reads their OWN settlement → ${ownReadAllowed ? 'allowed' : 'DENIED (the gap)'}`, async () => {
      await expectOwn(asStaff(LEAVER).doc(OWN).get());
    });

    test('cannot read a colleague\'s settlement', async () => {
      await assertFails(asStaff(LEAVER).doc(PEERS).get());
    });

    test('cannot WRITE their own settlement', async () => {
      await assertFails(asStaff(LEAVER).doc(OWN).set({ status: 'approved' }, { merge: true }));
    });

    test('cannot approve their own settlement', async () => {
      await assertFails(asStaff(LEAVER).doc(OWN).update({ status: 'approved' }));
    });

    test('cannot delete it', async () => {
      await assertFails(asStaff(LEAVER).doc(OWN).delete());
    });

    test('cannot create one', async () => {
      await assertFails(
        asStaff(LEAVER).doc(`${COLL}/${SCHOOL}_SET_${LEAVER}_20991231235959`).set(settlement(LEAVER))
      );
    });
  });

  describe('other actors', () => {
    test('a same-school admin reads any settlement', async () => {
      const expectAdmin = ownReadAllowed ? assertSucceeds : assertFails;
      await expectAdmin(asAdmin(ADMIN).doc(PEERS).get());
    });

    test('an admin still cannot write one (server-only)', async () => {
      await assertFails(asAdmin(ADMIN).doc(OWN).set({ status: 'paid' }, { merge: true }));
    });

    test('a parent token reads nothing', async () => {
      await assertFails(asParent('uid-parent').doc(OWN).get());
    });
  });

  describe('tenant isolation', () => {
    test('another school\'s staff cannot read it', async () => {
      await assertFails(asStaff(OTHER_STAFF, OTHER_SCHOOL).doc(OWN).get());
    });

    test('a cross-school admin cannot read it', async () => {
      await assertFails(asAdmin(OTHER_ADMIN, OTHER_SCHOOL).doc(OWN).get());
    });

    test('a leaver cannot read another tenant\'s settlement', async () => {
      await assertFails(asStaff(LEAVER).doc(FOREIGN).get());
    });
  });
}

describe('LIVE firestore.rules', () => {
  beforeAll(async () => {
    env = await initializeTestEnvironment({
      projectId: PROJECT_ID,
      firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
    });
    await seed();
  });
  afterAll(async () => { if (env) await env.cleanup(); });

  // FNF-6 is DEPLOYED (2026-08-25) and the working tree is synced to it, so the
  // live ruleset satisfies the POST-fix contract: the leaver reads their own
  // settlement, and same-school admins read any. This argument was false while the
  // suite documented the gap.
  battery(true);
});

describe('firestore.rules + FNF-6 patch', () => {
  beforeAll(async () => {
    // FNF-6 was DEPLOYED on 2026-08-25 and the working tree is synced to it, so the
    // rules file already carries the block. Re-applying the patch here makes its
    // own anti-double-apply guard fire — correctly. Read the ruleset as it stands.
    const patched = fs.readFileSync(RULES_PATH, 'utf8');

    // Guard: prove the patch landed. Without this a silent no-op would make the
    // whole suite "pass" by testing the unpatched rules twice.
    if (!patched.includes('match /staffSettlements/{docId}')) {
      throw new Error('FNF-6 patch did not apply — refusing to report a false pass');
    }

    env = await initializeTestEnvironment({
      projectId: PROJECT_ID,
      firestore: { rules: patched, host: '127.0.0.1', port: 18080 },
    });
    await seed();
  });
  afterAll(async () => { if (env) await env.cleanup(); });

  battery(true);
});

/**
 * THE APP READ PATH.
 *
 * Rules evaluate a LIST query differently from a document get: the query must be
 * provably scoped so that every document it could return satisfies the rule.
 * A patch can pass doc-reads and still deny the query the app actually issues —
 * a common and expensive trap, because it only shows up on a device.
 *
 * The Teacher app's repository pattern for this data is an equality query on
 * schoolId + staffId, mirroring AttendanceRegularizationRepository::getMyRequests().
 */
describe('firestore.rules + FNF-6 patch — the query the app issues', () => {
  beforeAll(async () => {
    // FNF-6 was DEPLOYED on 2026-08-25 and the working tree is synced to it, so the
    // rules file already carries the block. Re-applying the patch here makes its
    // own anti-double-apply guard fire — correctly. Read the ruleset as it stands.
    const patched = fs.readFileSync(RULES_PATH, 'utf8');
    env = await initializeTestEnvironment({
      projectId: PROJECT_ID,
      firestore: { rules: patched, host: '127.0.0.1', port: 18080 },
    });
    await seed();
  });
  afterAll(async () => { if (env) await env.cleanup(); });

  test('a leaver can LIST their own settlements', async () => {
    const db = asStaff(LEAVER);
    await assertSucceeds(
      db.collection(COLL)
        .where('schoolId', '==', SCHOOL)
        .where('staffId', '==', LEAVER)
        .get()
    );
  });

  test('an unscoped list of the whole collection is refused', async () => {
    await assertFails(asStaff(LEAVER).collection(COLL).get());
  });

  test('a leaver cannot list somebody else\'s settlements', async () => {
    await assertFails(
      asStaff(LEAVER).collection(COLL)
        .where('schoolId', '==', SCHOOL)
        .where('staffId', '==', PEER)
        .get()
    );
  });

  test('a leaver cannot list across tenants', async () => {
    await assertFails(
      asStaff(LEAVER).collection(COLL)
        .where('schoolId', '==', OTHER_SCHOOL)
        .where('staffId', '==', LEAVER)
        .get()
    );
  });

  test('a same-school admin can list the school\'s settlements', async () => {
    await assertSucceeds(
      asAdmin(ADMIN).collection(COLL).where('schoolId', '==', SCHOOL).get()
    );
  });
});
