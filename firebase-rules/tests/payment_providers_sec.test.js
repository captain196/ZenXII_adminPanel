/**
 * P9 — paymentProviders IS SERVER-ONLY.
 *
 * ── WHAT THIS COLLECTION HOLDS ────────────────────────────────────────────
 * paymentProviders/{schoolId}_{providerId} is the per-school, multi-provider
 * successor to the single feeSettings gateway document. EVERY document holds
 * that school's api_key, api_secret and webhook_secret for one provider.
 *
 * There is therefore no legitimate client read at all — unlike feeSettings,
 * which mixes secret gateway docs with non-secret fee settings and so needs a
 * `type != 'gateway'` predicate. The rule under test is a flat
 * `allow read, write: if false`, and these tests prove that holds for every
 * caller shape the platform can produce.
 *
 * ── WHY THE WRITE ARM MATTERS AS MUCH AS THE READ ARM ────────────────────
 * Provider SELECTION is the architecture's server-side authority. A client
 * that could write here could set `isDefault`/`active`, pointing a school at
 * an attacker-controlled provider, or rewrite credentials outright. Denying
 * writes is not defence-in-depth here — it is the control itself.
 *
 * ── TWO HARNESS TRAPS, BOTH DOCUMENTED IN sec1_cross_tenant_fee_writes ────
 * Both were hit while writing this file and are worth restating, because both
 * produce "every test fails identically", which reads exactly like a total
 * denial and is trivially mistaken for a passing security result:
 *
 *   1. Every rules-ENFORCING context must be created BEFORE the first
 *      withSecurityRulesDisabled() seed.
 *   2. `ctx.firestore()` must be called EXACTLY ONCE per callback — it
 *      re-invokes useEmulator() each time and throws once the instance has
 *      started. Hoist the handle into a variable.
 *
 * The POSITIVE CONTROL below exists specifically so that a harness fault can
 * never masquerade as a security pass: if the contexts are broken, the control
 * fails too, and the whole file is known-invalid rather than falsely green.
 *
 * ── WHICH RULES THIS RUNS AGAINST ─────────────────────────────────────────
 * By default the repository's firestore.rules — but that file carries 35 new
 * match blocks from six other workstreams, and only ONE of them
 * (paymentProviders) is this programme's. Deploying it would ship all of them.
 *
 * Set PP_RULES to run against a different ruleset. The intended deployment
 * path is the MINIMAL ARTIFACT — the committed baseline plus exactly the
 * paymentProviders block, nothing else:
 *
 *   PP_RULES=../firestore.minimal.rules npx jest payment_providers_sec
 *
 * Verified 2026-08-25: 38/38 against that artifact, positive control included.
 * This mirrors sec1_cross_tenant_fee_writes, which tests the DEPLOYED ruleset
 * plus a minimal delta for exactly the same reason.
 */

const {
  initializeTestEnvironment,
  assertFails,
  assertSucceeds,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const SCHOOL_A   = 'SCH_TENANT_AAA';
const SCHOOL_B   = 'SCH_TENANT_BBB';

const RULES_PATH = process.env.PP_RULES
  || path.join(__dirname, '..', 'firestore.rules');

// Loading the full ruleset into the emulator exceeds Jest's 5s default for
// beforeAll on a loaded machine. A hook timeout fails every test in the file
// and reads exactly like a total denial.
jest.setTimeout(120000);

let env;
const DBS = {};

const docFor = (school, provider) => `paymentProviders/${school}_${provider}`;

const SECRET_DOC = {
  schoolId:   SCHOOL_A,
  providerId: 'razorpay',
  provider:   'razorpay',
  active:     true,
  isDefault:  true,
  mode:       'test',
  api_key:        'rzp_test_PUBLIC',
  api_secret:     'THE_SECRET_NOBODY_MAY_READ',
  webhook_secret: 'THE_WEBHOOK_SECRET',
};

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: {
      rules: fs.readFileSync(RULES_PATH, 'utf8'),
      host: '127.0.0.1',
      port: 18080,
    },
  });

  // TRAP 1: build every enforcing context BEFORE the first seed.
  DBS.adminA = env.authenticatedContext(`admin_${SCHOOL_A}`, {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Admin',
  }).firestore();

  DBS.adminB = env.authenticatedContext(`admin_${SCHOOL_B}`, {
    school_id: SCHOOL_B, schoolId: SCHOOL_B, role: 'Admin',
  }).firestore();

  DBS.superAdminA = env.authenticatedContext(`sa_${SCHOOL_A}`, {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'School Super Admin',
  }).firestore();

  DBS.parentA = env.authenticatedContext(`parent_${SCHOOL_A}`, {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Parent',
  }).firestore();

  DBS.teacherA = env.authenticatedContext(`teacher_${SCHOOL_A}`, {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Teacher',
  }).firestore();

  DBS.anon = env.unauthenticatedContext().firestore();
});

afterAll(async () => { if (env) await env.cleanup(); });

/**
 * TRAP 2: ONE `ctx.firestore()` per callback, hoisted. Seeds tenants (so
 * tenantActive()/isAdmin() succeed for the positive control) and the provider
 * documents under test.
 */
async function seedAll() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL_A, SCHOOL_B]) {
      await db.collection('schools').doc(s).set({ schoolId: s });
      // tenantActive() reads schoolControl/{id}.lifecycle.state.
      await db.collection('schoolControl').doc(s).set({ lifecycle: { state: 'active' } });
      await db.doc(docFor(s, 'razorpay')).set({ ...SECRET_DOC, schoolId: s });
      // A non-secret fee setting, for the positive control below.
      await db.collection('feeSettings').doc(`${s}_reminders`).set({
        schoolId: s, type: 'reminders', enabled: true,
      });
    }
  });
}

beforeEach(async () => {
  await env.clearFirestore();
  await seedAll();
});

// ══ CONTROLS — THE HARNESS MUST BE CAPABLE OF BOTH OUTCOMES ══════════════

/**
 * THE POSITIVE CONTROL. Without this, "every paymentProviders operation is
 * denied" could equally mean "these contexts cannot do anything at all".
 * School A's admin reading School A's own NON-secret fee setting must
 * SUCCEED — proving the contexts, claims and tenant fixtures are all sound,
 * and that the denials below are caused by the paymentProviders rule
 * specifically.
 */
test('POSITIVE CONTROL: adminA can read a non-secret feeSettings doc', async () => {
  await assertSucceeds(DBS.adminA.doc(`feeSettings/${SCHOOL_A}_reminders`).get());
});

/** The seed really wrote a real secret — otherwise denials prove nothing. */
test('CONTROL: the seeded provider document exists and holds a secret', async () => {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const snap = await ctx.firestore().doc(docFor(SCHOOL_A, 'razorpay')).get();
    expect(snap.exists).toBe(true);
    expect(snap.data().api_secret).toBe('THE_SECRET_NOBODY_MAY_READ');
  });
});

// ══ READS — NOBODY, INCLUDING THE OWNING SCHOOL'S ADMIN ══════════════════

describe('reads are denied for every client role', () => {
  const cases = [
    ['own-school admin',        () => DBS.adminA],
    ['own-school super admin',  () => DBS.superAdminA],
    ['own-school parent',       () => DBS.parentA],
    ['own-school teacher',      () => DBS.teacherA],
    ['other-school admin',      () => DBS.adminB],
    ['unauthenticated',         () => DBS.anon],
  ];

  test.each(cases)('%s cannot read the provider doc', async (_label, db) => {
    await assertFails(db().doc(docFor(SCHOOL_A, 'razorpay')).get());
  });

  test.each(cases)('%s cannot list the collection', async (_label, db) => {
    await assertFails(db().collection('paymentProviders').get());
  });
});

// ══ CROSS-TENANT ═════════════════════════════════════════════════════════

test('School B admin cannot query School A rows by schoolId', async () => {
  await assertFails(
    DBS.adminB.collection('paymentProviders').where('schoolId', '==', SCHOOL_A).get()
  );
});

// ══ WRITES — PROVIDER SELECTION IS SERVER AUTHORITY ══════════════════════

describe('writes are denied for every client role', () => {
  const writers = [
    ['own-school admin',       () => DBS.adminA],
    ['own-school super admin', () => DBS.superAdminA],
    ['own-school parent',      () => DBS.parentA],
    ['other-school admin',     () => DBS.adminB],
    ['unauthenticated',        () => DBS.anon],
  ];

  test.each(writers)('%s cannot create a provider', async (_label, db) => {
    await assertFails(
      db().doc(docFor(SCHOOL_A, 'attacker')).set({
        schoolId: SCHOOL_A, providerId: 'attacker', provider: 'attacker',
        active: true, isDefault: true,
      })
    );
  });

  test.each(writers)('%s cannot flip active/isDefault', async (_label, db) => {
    await assertFails(
      db().doc(docFor(SCHOOL_A, 'razorpay')).update({ isDefault: false, active: false })
    );
  });

  test.each(writers)('%s cannot overwrite credentials', async (_label, db) => {
    await assertFails(
      db().doc(docFor(SCHOOL_A, 'razorpay')).update({ api_secret: 'ATTACKER_SECRET' })
    );
  });

  test.each(writers)('%s cannot delete a provider', async (_label, db) => {
    await assertFails(db().doc(docFor(SCHOOL_A, 'razorpay')).delete());
  });
});

/**
 * The specific escalation this rule exists to stop: pointing a school at an
 * attacker-controlled provider by writing an active+default row.
 */
test('a client cannot point a school at an attacker-controlled provider', async () => {
  await assertFails(
    DBS.adminA.doc(docFor(SCHOOL_A, 'evilpay')).set({
      schoolId: SCHOOL_A, providerId: 'evilpay', provider: 'evilpay',
      active: true, isDefault: true,
      api_key: 'attacker_key', api_secret: 'attacker_secret',
    })
  );
});

test('School B admin cannot create a provider row claiming School A', async () => {
  await assertFails(
    DBS.adminB.doc(docFor(SCHOOL_A, 'evilpay')).set({
      schoolId: SCHOOL_A, providerId: 'evilpay', active: true, isDefault: true,
    })
  );
});

// ══ THE SERVER PATH STILL WORKS ══════════════════════════════════════════

/**
 * A rule that denied the SERVER too would be a lockout, not a control. The
 * PHP service account bypasses rules entirely — modelled by the
 * rules-disabled context. ONE ctx.firestore() call (trap 2).
 */
test('the server (service account) can still read and write providers', async () => {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await assertSucceeds(db.doc(docFor(SCHOOL_A, 'razorpay')).get());
    await assertSucceeds(db.doc(docFor(SCHOOL_A, 'razorpay')).update({ mode: 'live' }));
  });
});
