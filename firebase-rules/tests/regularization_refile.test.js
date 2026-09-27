/**
 * attendanceRegularizations — re-filing a refused request (R-X1).
 *
 * This file runs the SAME battery twice:
 *
 *   1. against the LIVE firestore.rules, where it documents the defect — a
 *      rejected day cannot be re-filed — and proves every existing protection
 *      still holds;
 *   2. against those rules with the R-X1 patch applied IN MEMORY, proving the
 *      patch opens exactly one transition and nothing else.
 *
 * firestore.rules is never modified: the patch is applied to a copy inside the
 * test, so this is evidence about the real, current ruleset rather than about a
 * hand-maintained fixture that may have drifted from it.
 *
 * Run:
 *   cd firebase-rules/tests
 *   npx firebase emulators:exec --only firestore --project=zenxii-rules-test \
 *     "npx jest regularization_refile --runInBand --forceExit"
 */
const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const patch = require('../patches/rx1_regularization_refile');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');

const SCHOOL = 'SCH_TEST_REG_001';
const OTHER_SCHOOL = 'SCH_TEST_REG_002';

const STAFF = 'uid-staff-owner';      // owns the requests under test
const PEER = 'uid-staff-peer';        // a different same-school staff member
const ADMIN = 'uid-admin';
const OTHER_STAFF = 'uid-other-school-staff';

const DATE = '2026-08-10';
const OTHER_DATE = '2026-08-11';

const COLL = 'attendanceRegularizations';
const docPath = (school, staff, date) => `${COLL}/${school}_${staff}_${date}`;

// The doc ids the app would generate.
const REJECTED = docPath(SCHOOL, STAFF, DATE);
const PENDING = docPath(SCHOOL, STAFF, OTHER_DATE);
const APPROVED = docPath(SCHOOL, STAFF, '2026-08-12');
const PEER_PENDING = docPath(SCHOOL, PEER, DATE);
const FOREIGN = docPath(OTHER_SCHOOL, OTHER_STAFF, DATE);
const FRESH = docPath(SCHOOL, STAFF, '2026-08-20');   // does not exist

/** The exact payload the Teacher app submits (AttendanceRegularizationRepository). */
function appSubmit(overrides = {}) {
  return {
    schoolId: SCHOOL,
    staffId: STAFF,
    staffName: 'Owner Teacher',
    personType: 'staff',
    date: DATE,
    requestedStatus: 'P',
    claimedCheckInAt: '2026-08-10T09:05:00Z',
    claimedCheckOutAt: '2026-08-10T16:30:00Z',
    reason: 'GPS did not register my punch',
    batchId: 'REG_batch_2',
    status: 'pending',
    reviewedBy: '',
    reviewedAt: null,
    remarks: '',
    source: 'teacher_app',
    ...overrides,
  };
}

function seedDoc(status, extra = {}) {
  return {
    schoolId: SCHOOL,
    staffId: STAFF,
    staffName: 'Owner Teacher',
    personType: 'staff',
    date: DATE,
    requestedStatus: 'P',
    reason: 'first attempt',
    batchId: 'REG_batch_1',
    status,
    reviewedBy: status === 'pending' ? '' : 'uid-principal',
    remarks: status === 'rejected' ? 'Times do not match the gate log' : '',
    source: 'teacher_app',
    ...extra,
  };
}

let env;

function asStaff(uid, school = SCHOOL) {
  return env.authenticatedContext(uid, { school_id: school, role: 'Teacher', user_id: uid }).firestore();
}
function asAdmin(uid, school = SCHOOL) {
  return env.authenticatedContext(uid, { school_id: school, role: 'admin', user_id: uid }).firestore();
}
function asParent(uid, school = SCHOOL) {
  return env.authenticatedContext(uid, { school_id: school, role: 'Parent', user_id: uid }).firestore();
}

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL, OTHER_SCHOOL]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test School` });
    }

    await db.doc(REJECTED).set(seedDoc('rejected'));
    await db.doc(PENDING).set(seedDoc('pending', { date: OTHER_DATE }));
    await db.doc(APPROVED).set(seedDoc('approved', { date: '2026-08-12', appliedMark: 'P' }));
    await db.doc(PEER_PENDING).set(seedDoc('pending', { staffId: PEER, staffName: 'Peer' }));
    await db.doc(FOREIGN).set(seedDoc('rejected', { schoolId: OTHER_SCHOOL, staffId: OTHER_STAFF }));
  });
}

/** Restore the seeded documents between tests so each starts from a known state. */
async function resetDocs() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.doc(REJECTED).set(seedDoc('rejected'));
    await db.doc(PENDING).set(seedDoc('pending', { date: OTHER_DATE }));
    await db.doc(APPROVED).set(seedDoc('approved', { date: '2026-08-12', appliedMark: 'P' }));
    await db.doc(PEER_PENDING).set(seedDoc('pending', { staffId: PEER, staffName: 'Peer' }));
    await db.doc(FRESH).delete();
  });
}

/**
 * The battery both rulesets must satisfy. `refileAllowed` is the ONE behaviour
 * the patch changes; everything else must be identical before and after, which
 * is what makes this a safety proof rather than a demonstration.
 */
function battery(refileAllowed) {
  // ── The transition R-X1 is about ──────────────────────────────────────
  describe('re-filing', () => {
    const expectRefile = refileAllowed ? assertSucceeds : assertFails;

    test(`owner re-files a REJECTED day → ${refileAllowed ? 'allowed' : 'DENIED (the defect)'}`, async () => {
      await expectRefile(asStaff(STAFF).doc(REJECTED).set(appSubmit(), { merge: true }));
    });

    test(`owner amends their own PENDING day → ${refileAllowed ? 'allowed' : 'DENIED'}`, async () => {
      await expectRefile(
        asStaff(STAFF).doc(PENDING).set(appSubmit({ date: OTHER_DATE, reason: 'corrected times' }), { merge: true })
      );
    });

    test(`owner re-files asking for HALF-DAY → ${refileAllowed ? 'allowed' : 'DENIED'}`, async () => {
      await expectRefile(asStaff(STAFF).doc(REJECTED).set(appSubmit({ requestedStatus: 'M' }), { merge: true }));
    });
  });

  // ── Everything below must behave IDENTICALLY under both rulesets ──────
  describe('creation is unchanged', () => {
    test('owner creates a brand-new request', async () => {
      await assertSucceeds(
        asStaff(STAFF).doc(FRESH).set(appSubmit({ date: '2026-08-20' }))
      );
    });

    test('cannot create a request for somebody else', async () => {
      await assertFails(
        asStaff(STAFF).doc(docPath(SCHOOL, PEER, '2026-08-21')).set(appSubmit({ staffId: PEER, date: '2026-08-21' }))
      );
    });

    test('cannot create a pre-approved request', async () => {
      await assertFails(
        asStaff(STAFF).doc(FRESH).set(appSubmit({ date: '2026-08-20', status: 'approved' }))
      );
    });

    test('cannot self-request a pay-bearing mark (H / L / W)', async () => {
      for (const mark of ['H', 'L', 'W', 'O']) {
        await assertFails(
          asStaff(STAFF).doc(FRESH).set(appSubmit({ date: '2026-08-20', requestedStatus: mark }))
        );
      }
    });

    test('a parent token cannot create one at all', async () => {
      await assertFails(asParent('uid-parent').doc(FRESH).set(appSubmit({ date: '2026-08-20' })));
    });
  });

  describe('an approved record is immutable', () => {
    test('owner cannot re-open an APPROVED day', async () => {
      await assertFails(
        asStaff(STAFF).doc(APPROVED).set(appSubmit({ date: '2026-08-12' }), { merge: true })
      );
    });

    test('owner cannot flip an approved mark to a richer one', async () => {
      await assertFails(
        asStaff(STAFF).doc(APPROVED).set({ appliedMark: 'W' }, { merge: true })
      );
    });
  });

  describe('ownership', () => {
    test("cannot touch a peer's request", async () => {
      await assertFails(asStaff(STAFF).doc(PEER_PENDING).set(appSubmit({ staffId: PEER }), { merge: true }));
    });

    test('cannot reassign a request to someone else', async () => {
      await assertFails(asStaff(STAFF).doc(REJECTED).set(appSubmit({ staffId: PEER }), { merge: true }));
    });

    test('cannot walk a refused claim onto a different date', async () => {
      await assertFails(asStaff(STAFF).doc(REJECTED).set(appSubmit({ date: '2026-08-15' }), { merge: true }));
    });

    test('cannot turn it into a student record', async () => {
      await assertFails(asStaff(STAFF).doc(REJECTED).set(appSubmit({ personType: 'student' }), { merge: true }));
    });
  });

  describe('no client may write a decision', () => {
    test('owner cannot approve their own request', async () => {
      await assertFails(asStaff(STAFF).doc(REJECTED).set({ status: 'approved' }, { merge: true }));
    });

    test('owner cannot name an approver', async () => {
      await assertFails(
        asStaff(STAFF).doc(REJECTED).set(appSubmit({ reviewedBy: 'uid-principal' }), { merge: true })
      );
    });

    test('owner cannot pre-set the applied mark', async () => {
      await assertFails(
        asStaff(STAFF).doc(REJECTED).set(appSubmit({ appliedMark: 'P' }), { merge: true })
      );
    });

    test('a peer cannot decide the request', async () => {
      await assertFails(asStaff(PEER).doc(REJECTED).set({ status: 'approved', reviewedBy: PEER }, { merge: true }));
    });

    test('even an admin client cannot decide it (server-only)', async () => {
      await assertFails(asAdmin(ADMIN).doc(REJECTED).set({ status: 'approved' }, { merge: true }));
    });
  });

  describe('tenant isolation', () => {
    test('another school\'s staff cannot read this request', async () => {
      await assertFails(asStaff(OTHER_STAFF, OTHER_SCHOOL).doc(REJECTED).get());
    });

    test('another school\'s staff cannot re-file into this tenant', async () => {
      await assertFails(asStaff(OTHER_STAFF, OTHER_SCHOOL).doc(REJECTED).set(appSubmit(), { merge: true }));
    });

    test('cannot move a request into another tenant', async () => {
      await assertFails(asStaff(STAFF).doc(REJECTED).set(appSubmit({ schoolId: OTHER_SCHOOL }), { merge: true }));
    });

    test('a cross-school admin cannot read it', async () => {
      await assertFails(asAdmin('uid-other-admin', OTHER_SCHOOL).doc(REJECTED).get());
    });
  });

  describe('reads', () => {
    test('owner reads their own request', async () => {
      await assertSucceeds(asStaff(STAFF).doc(REJECTED).get());
    });

    test('same-school admin reads any request', async () => {
      await assertSucceeds(asAdmin(ADMIN).doc(REJECTED).get());
    });

    test("a peer cannot read someone else's request", async () => {
      await assertFails(asStaff(PEER).doc(REJECTED).get());
    });
  });

  describe('deletion stays closed', () => {
    test('owner cannot delete a refused request', async () => {
      await assertFails(asStaff(STAFF).doc(REJECTED).delete());
    });

    test('admin client cannot delete one either', async () => {
      await assertFails(asAdmin(ADMIN).doc(REJECTED).delete());
    });
  });

  describe('withdrawal (R-X2)', () => {
    // greytHR documents the boundary this asserts: Withdraw is offered on the
    // PENDING tab, and once a manager approves "it cannot be canceled".
    test(`owner withdraws a PENDING request → ${refileAllowed ? 'allowed' : 'DENIED'}`, async () => {
      const expectCancel = refileAllowed ? assertSucceeds : assertFails;
      await expectCancel(asStaff(STAFF).doc(PENDING).set({ status: 'cancelled' }, { merge: true }));
    });

    test('owner cannot withdraw an APPROVED request', async () => {
      await assertFails(asStaff(STAFF).doc(APPROVED).set({ status: 'cancelled' }, { merge: true }));
    });

    test('owner cannot withdraw a REJECTED request', async () => {
      await assertFails(asStaff(STAFF).doc(REJECTED).set({ status: 'cancelled' }, { merge: true }));
    });

    test("owner cannot withdraw someone else's request", async () => {
      await assertFails(asStaff(STAFF).doc(PEER_PENDING).set({ status: 'cancelled' }, { merge: true }));
    });

    test('owner cannot auto-reject', async () => {
      await assertFails(asStaff(STAFF).doc(PENDING).set({ status: 'auto_rejected' }, { merge: true }));
    });

    // R-X1-D1 (2026-08-25). This assertion was the INVERSE and it was wrong:
    // withdraw writes 'cancelled' to the SAME deterministic id the app re-submits
    // to, so barring that from-state made Withdraw a one-way door — the employee
    // could never file for that day again. Worse, submit is one atomic batch, so a
    // single withdrawn date failed every other date in the request. Found on a
    // real device: all 13 selected days were refused.
    test('a WITHDRAWN record can be re-filed', async () => {
      await env.withSecurityRulesDisabled(async (ctx) => {
        await ctx.firestore().doc(FRESH).set(seedDoc('cancelled', { date: '2026-08-20' }));
      });
      await assertSucceeds(
        asStaff(STAFF).doc(FRESH).set(appSubmit({ date: '2026-08-20' }), { merge: true })
      );
    });
  });
}

// ═══════════════════════════════════════════════════════════════════════
//  1. The live rules — the defect, and the protections that already hold
// ═══════════════════════════════════════════════════════════════════════
describe('LIVE firestore.rules', () => {
  beforeAll(async () => {
    env = await initializeTestEnvironment({
      projectId: PROJECT_ID,
      firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
    });
    await seedWorld();
  });
  afterAll(async () => { if (env) await env.cleanup(); });
  beforeEach(resetDocs);

  // R-X1 is DEPLOYED (2026-08-25) and the working tree is synced to it, so the
  // live ruleset satisfies the POST-fix contract. This argument was false while
  // the suite documented the defect; flipping it is how the file stops describing
  // a world that no longer exists.
  battery(true);
});

// The second variant — the same rules with the R-X1 patch applied IN MEMORY —
// was removed on 2026-08-25: the patch is deployed, so it now double-applies and
// its own anti-false-pass guard correctly refuses. patches/rx1_regularization_refile.js
// stays in the repo as the deployment record.
