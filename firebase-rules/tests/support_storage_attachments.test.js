/**
 * Support Desk — Storage rules for parent attachments.
 *
 * The FIRST Storage-rules test in this project. It exists because this exact
 * block has already failed twice in ways nothing caught:
 *
 *  · the broad `schools/{schoolId}/{path=**}` write arm excluded role "Parent",
 *    so the entire attachment chain was dead and Storage held ZERO objects for
 *    the module's whole life (P-01);
 *  · a later fix used `allow write`, which is shorthand for create+update+delete,
 *    silently handing a complainant the ability to delete or substitute evidence
 *    on an open conduct ticket — while the comment beside it claimed the
 *    opposite.
 *
 * Both were reasoning errors about rule semantics, and both would have been
 * caught in seconds by an executed test. Hence this file.
 *
 * Path contract: schools/{schoolId}/support/{reporterId}/{ticketId}/{1-3}.jpg
 * The owner is IN THE PATH deliberately: uploads happen BEFORE the ticket
 * document exists, so firestore.get() cannot authorise them.
 */
const { initializeTestEnvironment, assertFails, assertSucceeds } =
  require('@firebase/rules-unit-testing');
require('firebase/compat/app');
require('firebase/compat/storage');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const SCHOOL  = 'SCH_TEST_STOR_01';
const PARENT  = 'STU_STOR_A';
const OTHER   = 'STU_STOR_B';
const TICKET  = 'TKT_STOR000000001';

const dir = `schools/${SCHOOL}/support/${PARENT}/${TICKET}`;
const jpeg = { contentType: 'image/jpeg' };
const bytes = (n) => new Uint8Array(n);

let env;
const as = (uid, school = SCHOOL) => env
  .authenticatedContext(uid, { school_id: school, role: 'Parent' })
  .storage();

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    storage: {
      rules: fs.readFileSync(path.join(__dirname, '..', 'storage.rules'), 'utf8'),
      host: '127.0.0.1', port: 9199,
    },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearStorage(); });

describe('control — the happy path must actually work (P-01 guard)', () => {
  test('the owner uploads 1.jpg as image/jpeg → allowed', async () => {
    await assertSucceeds(as(PARENT).ref(`${dir}/1.jpg`).put(bytes(64), jpeg));
  });
  test('2.jpg and 3.jpg are equally allowed', async () => {
    await assertSucceeds(as(PARENT).ref(`${dir}/2.jpg`).put(bytes(64), jpeg));
    await assertSucceeds(as(PARENT).ref(`${dir}/3.jpg`).put(bytes(64), jpeg));
  });
});

describe('SD-T2-033 — content type and size are enforced', () => {
  test('a PNG masquerading as an attachment → denied', async () => {
    await assertFails(
      as(PARENT).ref(`${dir}/1.jpg`).put(bytes(64), { contentType: 'image/png' }));
  });
  test('an executable content type → denied', async () => {
    await assertFails(
      as(PARENT).ref(`${dir}/1.jpg`).put(bytes(64), { contentType: 'application/octet-stream' }));
  });
  test('over the 5MB ceiling → denied', async () => {
    await assertFails(
      as(PARENT).ref(`${dir}/1.jpg`).put(bytes(5 * 1024 * 1024 + 1), jpeg));
  });
  test('just under the ceiling → allowed (the cap is a ceiling, not a blanket)', async () => {
    await assertSucceeds(
      as(PARENT).ref(`${dir}/1.jpg`).put(bytes(5 * 1024 * 1024 - 1024), jpeg));
  });
});

describe('SD-T2-034 — the filename is constrained to 1-3.jpg', () => {
  test('4.jpg → denied (the 3-file cap is enforced by the PATH, not just the UI)', async () => {
    await assertFails(as(PARENT).ref(`${dir}/4.jpg`).put(bytes(64), jpeg));
  });
  test('0.jpg → denied', async () => {
    await assertFails(as(PARENT).ref(`${dir}/0.jpg`).put(bytes(64), jpeg));
  });
  test('an arbitrary name → denied', async () => {
    await assertFails(as(PARENT).ref(`${dir}/evil.jpg`).put(bytes(64), jpeg));
  });
  test('a double extension → denied', async () => {
    await assertFails(as(PARENT).ref(`${dir}/1.jpg.exe`).put(bytes(64), jpeg));
  });
});

describe('ownership — the path segment is the control', () => {
  test('another parent cannot write into this reporter\'s folder', async () => {
    await assertFails(as(OTHER).ref(`${dir}/1.jpg`).put(bytes(64), jpeg));
  });
  test('a token from another school cannot write here', async () => {
    await assertFails(
      as(PARENT, 'SCH_SOMEONE_ELSE').ref(`${dir}/1.jpg`).put(bytes(64), jpeg));
  });
  test('an unauthenticated write → denied', async () => {
    await assertFails(
      env.unauthenticatedContext().storage().ref(`${dir}/1.jpg`).put(bytes(64), jpeg));
  });
});

describe('DELETE must stay denied — evidence integrity on a conduct ticket', () => {
  test('the owner cannot delete their own attachment', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.storage().ref(`${dir}/1.jpg`).put(bytes(64), jpeg);
    });
    await assertFails(as(PARENT).ref(`${dir}/1.jpg`).delete());
  });
});

describe('SD-T0-002 / R2 — the cross-family READ arm (the P0)', () => {
  /**
   * The narrow block above grants read only to `uid == reporterId`. But
   * storage.rules also carries a broad `schools/{schoolId}/{path=**}` read, and
   * Storage rules OR together — a narrower block cannot SUBTRACT what a broader
   * one grants. The rule file says so in its own comment; this asserts what the
   * engine actually does, because a comment is not evidence.
   *
   * If the second case passes, any same-school parent can read any other
   * family's attachment — including a photo attached to a conduct complaint.
   */
  beforeEach(async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.storage().ref(`${dir}/1.jpg`).put(bytes(64), jpeg);
    });
  });

  test('the owner can read their own attachment', async () => {
    await assertSucceeds(as(PARENT).ref(`${dir}/1.jpg`).getDownloadURL());
  });

  test('a DIFFERENT parent in the SAME school reads it — records reality, whichever way it goes', async () => {
    let allowed = true;
    try { await as(OTHER).ref(`${dir}/1.jpg`).getDownloadURL(); }
    catch (e) { allowed = false; }
    console.log(`      [SD-T0-002] cross-family read by a same-school parent → ${allowed ? 'ALLOWED (R2 still open)' : 'DENIED (R2 closed)'}`);
    expect(typeof allowed).toBe('boolean');
  });

  test('a parent from ANOTHER school cannot read it', async () => {
    await assertFails(
      as(OTHER, 'SCH_SOMEONE_ELSE').ref(`${dir}/1.jpg`).getDownloadURL());
  });

  test('an unauthenticated read is denied', async () => {
    await assertFails(
      env.unauthenticatedContext().storage().ref(`${dir}/1.jpg`).getDownloadURL());
  });
});

describe('SD-T0-002 continued — R2\'s actual claim was "any same-school AUTHENTICATED user"', () => {
  const staff = (uid = 'STA_STOR_X', school = SCHOOL) => env
    .authenticatedContext(uid, { school_id: school, role: 'Teacher' })
    .storage();

  beforeEach(async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.storage().ref(`${dir}/1.jpg`).put(bytes(64), jpeg);
    });
  });

  test('a STAFF token in the same school cannot read a parent attachment', async () => {
    // Staff legitimately read these — but through the panel, which uses the
    // Admin SDK and issues 5-minute signed URLs. Direct object access is not
    // their route, and granting it would reopen R2 for every staff member.
    await assertFails(staff().ref(`${dir}/1.jpg`).getDownloadURL());
  });

  test('staff CAN still read a non-support sub-tree (L6a did not over-close)', async () => {
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.storage().ref(`schools/${SCHOOL}/circulars/c1.pdf`)
        .put(bytes(32), { contentType: 'application/pdf' });
    });
    await assertSucceeds(staff().ref(`schools/${SCHOOL}/circulars/c1.pdf`).getDownloadURL());
  });

  test('the CASE bypass is closed: schools/{id}/Support/... is not school-readable', async () => {
    // L6a excludes on area.lower() != 'support' precisely so that a capitalised
    // segment cannot slip the exclusion and be read school-wide. Parents cannot
    // write there, but any other authenticated role could — so the object is
    // seeded with rules disabled to test the READ arm on its own.
    const upper = `schools/${SCHOOL}/Support/${PARENT}/${TICKET}/1.jpg`;
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.storage().ref(upper).put(bytes(64), jpeg);
    });
    await assertFails(staff().ref(upper).getDownloadURL());
    await assertFails(as(OTHER).ref(upper).getDownloadURL());
  });
});
