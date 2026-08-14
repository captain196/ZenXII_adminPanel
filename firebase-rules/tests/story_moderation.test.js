/**
 * Story moderation enforcement (F-R4.9) — Firestore Rules unit tests.
 *
 * Found during the 2026-08-11 admin-panel UAT. The `stories` read rule scoped
 * by school and audience but never looked at `status`. Both apps hide moderated
 * stories with a CLIENT-SIDE filter:
 *
 *   .filter { it.status == "active" && it.expiresAtMillis > nowMs }
 *
 * so a flagged or removed story was still delivered to every entitled device
 * and merely not drawn. An older or modified client would happily render
 * content an admin had explicitly pulled — i.e. "Remove" hid content instead of
 * revoking it.
 *
 * The rule now denies non-staff reads of anything not `active`. Staff stay
 * exempt on purpose: the Teacher app must still show a teacher their own story
 * after it is moderated, and the admin panel reads through the Admin SDK, which
 * bypasses rules entirely.
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

const SCHOOL = 'SCH_B56BB9A401';
const PARENT = 'STU0012';
const STAFF = 'STA0011';
const CLASS_KEY = '9-a';

let env;

const storyPath = (id) => `stories/${id}`;

function parentCtx(uid = PARENT, school = SCHOOL) {
  return env.authenticatedContext(uid, {
    school_id: school,
    schoolId: school,
    role: 'Parent',
  });
}

// isStaff() is "authenticated, tenant active, and role is NOT a
// student/parent/guardian variant" — so any staff-ish role qualifies.
function staffCtx(uid = STAFF, school = SCHOOL) {
  return env.authenticatedContext(uid, {
    school_id: school,
    schoolId: school,
    role: 'Teacher',
  });
}

function storyDoc(status, audience) {
  const d = {
    schoolId: SCHOOL,
    teacherId: STAFF,
    authorId: STAFF,
    mediaType: 'image',
    mediaUrl: 'https://example.test/x.jpg',
    caption: 'test',
    createdAt: 1786455647682,
    expiresAt: 1786542047682,
    audienceClassKeys: audience,
  };
  // Omit the field entirely when null, to model legacy docs written before
  // `status` existed.
  if (status !== null) d.status = status;
  return d;
}

// Every fixture is seeded with rules disabled so setup can never be the thing
// under test.
async function seed(id, status, audience = ['*']) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    await ctx.firestore().doc(storyPath(id)).set(storyDoc(status, audience));
  });
}

beforeAll(async () => {
  // No host/port: the SDK reads FIRESTORE_EMULATOR_HOST, which
  // `firebase emulators:exec` exports (port 18080 per firebase.json).
  // Hardcoding 127.0.0.1:8080 here pointed at nothing and every test in this
  // file failed with ECONNREFUSED — including the positive control, which is
  // what gave the harness away.
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8') },
  });

  // isSameSchool()/isStaff() both call tenantActive(), which get()s
  // schoolControl/{schoolId}. A get() on a MISSING doc errors and denies the
  // whole rule, so without this every assertSucceeds fails for a reason that
  // has nothing to do with moderation. Seed the other tenant too, so the
  // cross-school test is denied for school mismatch rather than a missing doc.
  // NOTE: tenantActive() get()s BOTH schoolControl/{id} AND schools/{id}. In the
  // rules engine a get() on a MISSING doc raises a service-call error (it does
  // not return null), so the `sch == null` branch never saves us — the whole
  // rule evaluation fails and the read is denied. Both docs must exist.
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL, 'SCH_OTHER_0001']) {
      await db.doc(`schoolControl/${s}`).set({ schoolId: s, lifecycle: { state: 'active' } });
      // No adminDisabled key — tenantActive() treats its absence as enabled.
      await db.doc(`schools/${s}`).set({ schoolId: s, name: 'Test School' });
    }
  });

  await seed('s_active', 'active');
  await seed('s_flagged', 'flagged');
  await seed('s_removed', 'removed');
  await seed('s_legacy', null); // no status field at all
  await seed('s_class_flagged', 'flagged', [CLASS_KEY]);
  await seed('s_class_active', 'active', [CLASS_KEY]);

  // Entitle the parent to CLASS_KEY so class-targeted cases fail on status
  // alone, not on audience.
  await env.withSecurityRulesDisabled(async (ctx) => {
    await ctx.firestore().doc(`storyAudience/${PARENT}`).set({
      schoolId: SCHOOL,
      classKeys: [CLASS_KEY],
    });
  });
});

afterAll(async () => { if (env) await env.cleanup(); });

describe('F-R4.9 — parents may only read ACTIVE stories', () => {
  test('parent CAN read an active whole-school story', async () => {
    const db = parentCtx().firestore();
    await assertSucceeds(db.doc(storyPath('s_active')).get());
  });

  test('parent CANNOT read a FLAGGED story', async () => {
    const db = parentCtx().firestore();
    await assertFails(db.doc(storyPath('s_flagged')).get());
  });

  test('parent CANNOT read a REMOVED story', async () => {
    const db = parentCtx().firestore();
    await assertFails(db.doc(storyPath('s_removed')).get());
  });

  test('parent CAN still read a legacy story with NO status field', async () => {
    // The rule defaults to 'active'. If this regresses, every pre-moderation
    // story silently disappears for parents.
    const db = parentCtx().firestore();
    await assertSucceeds(db.doc(storyPath('s_legacy')).get());
  });

  test('status is enforced for class-targeted stories too, not just whole-school', async () => {
    const db = parentCtx().firestore();
    await assertSucceeds(db.doc(storyPath('s_class_active')).get());
    await assertFails(db.doc(storyPath('s_class_flagged')).get());
  });
});

describe('F-R4.9 — staff remain exempt', () => {
  test('staff CAN read a flagged story', async () => {
    const db = staffCtx().firestore();
    await assertSucceeds(db.doc(storyPath('s_flagged')).get());
  });

  test('staff CAN read a removed story', async () => {
    // The Teacher app has to be able to tell an author their story was pulled.
    const db = staffCtx().firestore();
    await assertSucceeds(db.doc(storyPath('s_removed')).get());
  });

  test('staff still cannot reach another school', async () => {
    // Guards against the status gate accidentally widening tenant access.
    const db = staffCtx(STAFF, 'SCH_OTHER_0001').firestore();
    await assertFails(db.doc(storyPath('s_active')).get());
  });
});
