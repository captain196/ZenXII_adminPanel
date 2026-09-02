/**
 * staff.prefLang — Firestore Rules unit tests.
 *
 * The ZenXii Teacher app mirrors the staff member's chosen language to their
 * own staff doc so push can be composed in that language and a reinstall or
 * device switch can restore it. The rule is a SEPARATE `allow update` clause
 * beside `allow write: if isAdmin()`: folding it into another clause would let
 * a caller send prefLang together with whatever that clause permits, and the
 * hasOnly() allowlist has to stay byte-narrow.
 *
 * What needs proving here is the escalation boundary. `staff` is an
 * admin-write collection holding role, staff_roles, department and status —
 * i.e. the RBAC grant itself. A self-service clause on it is only safe because
 * hasOnly() pins the write to exactly two fields. These tests are what stops a
 * future edit from loosening that silently.
 *
 * The owner predicate is the docId (`{schoolId}_{staffId}`), matching the
 * sibling staffPrivate block, NOT `resource.data.staffId`. The "staff doc with
 * no staffId field" test below is the one that justifies that choice.
 *
 * Run: cd firebase-rules/tests && npm install && npm test
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

const SCHOOL = 'SCH_TEST_SLANG_001';
const OTHER_SCHOOL = 'SCH_TEST_SLANG_002';
const STA_A = 'STA_LANG_A';
const STA_B = 'STA_LANG_B';
const STA_NOFIELD = 'STA_LANG_NOFIELD';

let env;

const docPath = (schoolId, staffId) => `staff/${schoolId}_${staffId}`;

async function seed() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    // isSameSchool() chains to tenantActive(), which get()s schoolControl.
    // Without this doc every write fails as a rules *evaluation error* rather
    // than a clean deny — which also means the language mirror correctly stops
    // working once a school's subscription lapses.
    await db.doc(`schoolControl/${SCHOOL}`).set({
      schoolId: SCHOOL,
      lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
    });
    await db.doc(`schoolControl/${OTHER_SCHOOL}`).set({
      schoolId: OTHER_SCHOOL,
      lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
    });
    await db.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL, name: 'Staff Lang School' });
    await db.doc(`schools/${OTHER_SCHOOL}`).set({ schoolId: OTHER_SCHOOL, name: 'Other School' });

    await db.doc(docPath(SCHOOL, STA_A)).set({
      schoolId: SCHOOL, staffId: STA_A, name: 'Anita',
      role: 'Teacher', primary_role: 'ROLE_TEACHER',
      staff_roles: ['ROLE_BASELINE_APP', 'ROLE_TEACHER'],
      department: 'Science', status: 'Active',
    });
    await db.doc(docPath(SCHOOL, STA_B)).set({
      schoolId: SCHOOL, staffId: STA_B, name: 'Bikram',
      role: 'Teacher', staff_roles: ['ROLE_TEACHER'],
      department: 'Maths', status: 'Active',
    });
    // A staff doc created by a merge-only writer (AdminUsers::create_admin /
    // Staff_access) that never wrote the staffId field. The docId owner check
    // must still recognise its owner; a resource.data.staffId check would not.
    await db.doc(docPath(SCHOOL, STA_NOFIELD)).set({
      schoolId: SCHOOL, name: 'Legacy Chandra',
      role: 'Teacher', status: 'Active',
    });
    // Same staffId under a different tenant — cross-tenant probe target.
    await db.doc(docPath(OTHER_SCHOOL, STA_A)).set({
      schoolId: OTHER_SCHOOL, staffId: STA_A, name: 'Other-tenant Anita',
      role: 'Teacher', status: 'Active',
    });
  });
}

// The app authenticates as the staff member; auth.uid IS the SIS staff id.
function staff(uid, schoolId) {
  return env
    .authenticatedContext(uid, { school_id: schoolId, role: 'Teacher' })
    .firestore();
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seed(); });

describe('staff.prefLang — the write the Teacher app actually makes', () => {
  test('own doc, prefLang + updatedAt (merge) → allowed', async () => {
    await assertSucceeds(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A))
        .set({ prefLang: 'ta', updatedAt: '2026-08-19T00:00:00Z' }, { merge: true })
    );
  });

  test('every shipped language tag is accepted', async () => {
    for (const tag of ['en', 'hi', 'mr', 'gu', 'ta', 'te']) {
      await assertSucceeds(
        staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A))
          .update({ prefLang: tag, updatedAt: '2026-08-19T00:00:00Z' })
      );
    }
  });

  test('prefLang alone, without updatedAt → allowed (hasOnly, not hasAll)', async () => {
    await assertSucceeds(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A)).update({ prefLang: 'hi' })
    );
  });

  test('staff doc with NO staffId field → owner still allowed (docId predicate)', async () => {
    // This is the case a `resource.data.staffId == uid` predicate would deny.
    await assertSucceeds(
      staff(STA_NOFIELD, SCHOOL).doc(docPath(SCHOOL, STA_NOFIELD))
        .update({ prefLang: 'bn', updatedAt: '2026-08-19T00:00:00Z' })
    );
  });
});

describe('staff.prefLang — escalation boundary (this collection holds the RBAC grant)', () => {
  test('smuggling staff_roles alongside prefLang → denied', async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A))
        .update({ prefLang: 'hi', staff_roles: ['ROLE_ADMIN'] })
    );
  });

  test('smuggling role alongside prefLang → denied', async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A))
        .update({ prefLang: 'hi', role: 'Admin' })
    );
  });

  test('smuggling primary_role alongside prefLang → denied', async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A))
        .update({ prefLang: 'hi', primary_role: 'ROLE_ADMIN' })
    );
  });

  test('smuggling status alongside prefLang → denied', async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A))
        .update({ prefLang: 'hi', status: 'Inactive' })
    );
  });

  test('smuggling department alongside prefLang → denied', async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A))
        .update({ prefLang: 'hi', department: 'Admin Office' })
    );
  });

  test('role change alone (no prefLang) → still denied', async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A)).update({ role: 'Admin' })
    );
  });

  test("another staff member's doc, same school → denied", async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_B))
        .update({ prefLang: 'hi', updatedAt: '2026-08-19T00:00:00Z' })
    );
  });

  test('same staffId in a DIFFERENT school → denied (tenant scoping)', async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(OTHER_SCHOOL, STA_A))
        .update({ prefLang: 'hi', updatedAt: '2026-08-19T00:00:00Z' })
    );
  });

  test('unauthenticated write → denied', async () => {
    await assertFails(
      env.unauthenticatedContext().firestore()
        .doc(docPath(SCHOOL, STA_A)).update({ prefLang: 'hi' })
    );
  });

  test('a parent cannot write a staff doc via the prefLang clause', async () => {
    const parent = env
      .authenticatedContext('STU_LANG_X', { school_id: SCHOOL, role: 'Parent' })
      .firestore();
    await assertFails(
      parent.doc(docPath(SCHOOL, STA_A)).update({ prefLang: 'hi' })
    );
  });
});

describe('staff.prefLang — type and length guards', () => {
  test('non-string prefLang → denied', async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A)).update({ prefLang: 42 })
    );
  });

  test('over-long prefLang (>8 chars) → denied', async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A))
        .update({ prefLang: 'this-is-far-too-long-to-be-a-bcp47-tag' })
    );
  });

  test('exactly 8 chars → allowed (boundary is inclusive)', async () => {
    await assertSucceeds(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_A)).update({ prefLang: 'sr-Latn1' })
    );
  });
});

describe('staff — read/write posture unchanged (no regression)', () => {
  test('same-school read still allowed', async () => {
    await assertSucceeds(
      staff(STA_A, SCHOOL).doc(docPath(SCHOOL, STA_B)).get()
    );
  });

  test('cross-tenant read still denied', async () => {
    await assertFails(
      staff(STA_A, SCHOOL).doc(docPath(OTHER_SCHOOL, STA_A)).get()
    );
  });

  /* REWRITTEN 2026-09-02. This asserted an admin could still write arbitrary
     staff fields from a client. SEC-3 wave3 removed that: `staff` is now
     server-only apart from the narrow prefLang clause this suite exists to
     test. The panel writes staff through the Admin SDK and bypasses rules, so
     the removed authorization was one nothing exercised.

     Inverted rather than deleted, because it is the SAFETY RAIL for the clause
     above it: prefLang is a self-service write on a collection holding role,
     staff_roles, department and status — the RBAC grant itself. If a future
     edit widened the write arm to make some admin flow work, this is what would
     catch it. */
  test('staff is otherwise server-only — an admin cannot write arbitrary fields', async () => {
    const admin = env
      .authenticatedContext('STA_ADMIN', { school_id: SCHOOL, role: 'Admin' })
      .firestore();
    await assertFails(
      admin.doc(docPath(SCHOOL, STA_B)).update({ department: 'Physics', role: 'HOD' })
    );
  });
});
