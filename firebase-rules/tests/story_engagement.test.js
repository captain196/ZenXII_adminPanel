/**
 * Story engagement (viewers / reactions) — Firestore Rules unit tests.
 *
 * Reproduces a production bug found 2026-08-08: NO story view or reaction had
 * ever been recorded. Live data showed every story at viewCount=0 with zero
 * viewer docs and reactionCounts={}, and an on-device log proved the client
 * payload was correct yet still got PERMISSION_DENIED:
 *
 *   Story.markAsViewed docId=STU0012 userId=STU0012 schoolId=SCH_B56BB9A401
 *   Story.view FAILED  PERMISSION_DENIED: Missing or insufficient permissions.
 *
 * Cause: both apps write the marker inside a TRANSACTION, whose first step is
 * `tx.get(viewers/{uid})` on a doc that does not exist yet. The C2 read-side
 * hardening made the read rule `resource.data.get('schoolId','') == claim`,
 * but for a MISSING document `resource` is null — so the read is denied and
 * the transaction dies before the write is even attempted.
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
const OTHER_SCHOOL = 'SCH_OTHER_0001';
const PARENT = 'STU0012';
const OTHER_PARENT = 'STU0099';
const STORY = `${SCHOOL}_STA0011_1786075718979`;

let env;

// Defaults MUST match viewerDoc()'s default, or the path's {uid} and the
// payload's userId disagree and every write is denied for the wrong reason.
const viewerPath = (uid = PARENT) => `stories/${STORY}/viewers/${uid}`;
const reactionPath = (uid = PARENT) => `stories/${STORY}/reactions/${uid}`;

function parentCtx(uid = PARENT, school = SCHOOL) {
  return env.authenticatedContext(uid, {
    school_id: school,
    schoolId: school,
    role: 'Parent',
  });
}

function viewerDoc(uid = PARENT, school = SCHOOL) {
  return {
    userId: uid,
    userName: 'Test Parent',
    userPic: '',
    schoolId: school,
    viewedAt: new Date(),
  };
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8') },
  });
});

afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); });

describe('story viewers', () => {
  // THE REGRESSION. Both apps read the marker before writing it, so if this
  // get is denied no view can ever be recorded — which is exactly what
  // production showed.
  test('a viewer may GET their own marker when it does NOT exist yet', async () => {
    const db = parentCtx().firestore();
    await assertSucceeds(db.doc(viewerPath()).get());
  });

  test('a viewer may create their own marker', async () => {
    const db = parentCtx().firestore();
    await assertSucceeds(db.doc(viewerPath()).set(viewerDoc()));
  });

  test('read-then-write (the real transaction shape) succeeds end to end', async () => {
    const db = parentCtx().firestore();
    await assertSucceeds(db.runTransaction(async (tx) => {
      const snap = await tx.get(db.doc(viewerPath()));
      if (!snap.exists) tx.set(db.doc(viewerPath()), viewerDoc());
    }));
  });

  test('an existing marker is still readable by its own school', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc(viewerPath()).set(viewerDoc());
    });
    await assertSucceeds(parentCtx().firestore().doc(viewerPath()).get());
  });

  // ── Tenant isolation must survive the fix ────────────────────────────
  test('another school CANNOT read an existing marker', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc(viewerPath()).set(viewerDoc());
    });
    const foreign = parentCtx(OTHER_PARENT, OTHER_SCHOOL).firestore();
    await assertFails(foreign.doc(viewerPath()).get());
  });

  // ST-SEC-13 (closed 2026-08-08). Previously the rule checked only that the
  // PAYLOAD's userId matched the doc id — a condition ANY authenticated member
  // of the school could satisfy for ANYONE's id, forging a "seen" record or a
  // reaction that never happened. `uid == request.auth.uid` now asserts the
  // writer IS that person.
  //
  // Verified before tightening: across 140 auth users every real account has
  // request.auth.uid EQUAL to its app id; the only exceptions are 2 PROBE_*
  // rows and 3 *_test_phase1 fixtures, none of which post or view stories.
  test('a viewer CANNOT forge a marker under someone else’s id', async () => {
    const db = parentCtx().firestore();
    await assertFails(db.doc(viewerPath(OTHER_PARENT)).set(viewerDoc(OTHER_PARENT)));
  });

  test('a viewer CANNOT forge even with a perfectly-matching payload', async () => {
    // The payload is internally consistent (userId == doc id == OTHER_PARENT,
    // correct school) — only the WRITER is wrong. This is precisely the case
    // the old rule let through.
    const db = parentCtx(PARENT).firestore();
    await assertFails(db.doc(viewerPath(OTHER_PARENT)).set(viewerDoc(OTHER_PARENT, SCHOOL)));
  });

  test('the real owner CAN still write their own marker', async () => {
    // The other half of the tightening: it must not lock out the legitimate
    // writer. auth.uid == doc id == payload userId.
    const db = parentCtx(OTHER_PARENT).firestore();
    await assertSucceeds(db.doc(viewerPath(OTHER_PARENT)).set(viewerDoc(OTHER_PARENT)));
  });

  test('a viewer CANNOT stamp another school on their marker', async () => {
    const db = parentCtx().firestore();
    await assertFails(db.doc(viewerPath()).set(viewerDoc(PARENT, OTHER_SCHOOL)));
  });

  test('an unauthenticated user cannot read or write markers', async () => {
    const anon = env.unauthenticatedContext().firestore();
    await assertFails(anon.doc(viewerPath()).get());
    await assertFails(anon.doc(viewerPath()).set(viewerDoc()));
  });
});

describe('story reactions', () => {
  // Identical rule shape, so the same bug blocked every reaction ever made.
  test('a reactor may GET their own marker when it does NOT exist yet', async () => {
    const db = parentCtx().firestore();
    await assertSucceeds(db.doc(reactionPath()).get());
  });

  test('read-then-write (the real transaction shape) succeeds end to end', async () => {
    const db = parentCtx().firestore();
    await assertSucceeds(db.runTransaction(async (tx) => {
      const snap = await tx.get(db.doc(reactionPath()));
      if (!snap.exists) {
        tx.set(db.doc(reactionPath()), {
          emoji: '❤️', userId: PARENT, userName: 'Test Parent',
          userPic: '', schoolId: SCHOOL, reactedAt: new Date(),
        });
      }
    }));
  });

  test('a reactor CANNOT forge a reaction under someone else’s id', async () => {
    // Same ST-SEC-13 tightening on the reactions block — a faked reaction is
    // arguably worse than a faked view, since it attributes an opinion.
    const db = parentCtx(PARENT).firestore();
    await assertFails(db.doc(reactionPath(OTHER_PARENT)).set({
      emoji: '❤️', userId: OTHER_PARENT, userName: 'Someone Else',
      userPic: '', schoolId: SCHOOL, reactedAt: new Date(),
    }));
  });

  test('another school CANNOT read an existing reaction', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc(reactionPath()).set({
        emoji: '❤️', userId: PARENT, schoolId: SCHOOL,
      });
    });
    const foreign = parentCtx(OTHER_PARENT, OTHER_SCHOOL).firestore();
    await assertFails(foreign.doc(reactionPath()).get());
  });
});
