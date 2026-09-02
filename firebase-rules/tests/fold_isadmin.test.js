/**
 * Admin→staff FOLD — rules unit tests for the isAdmin() OR-arm.
 *
 * After the fold a demoted admin's token role is their NOMINAL label (e.g.
 * "HR Manager"), no longer "Admin", so the legacy role arm alone would drop them
 * from every isAdmin()-gated write. The fold adds a second arm that recognises a
 * true admin via the server-maintained staffCapabilities/{uid}.admin flag.
 *
 * Target: /subjects write (allow write: if isAdmin();) — a clean isAdmin()-only arm.
 *
 * Verifies:
 *   1. legacy admin (role='Admin')                         → ALLOW (role arm, unchanged)
 *   2. folded admin (nominal role + caps.admin==true)       → ALLOW (new caps arm)
 *   3. folded plain staff (nominal role + caps.admin==false)→ DENY  (no escalation)
 *   4. folded admin, caps doc ABSENT                        → DENY  (fail-safe to role arm)
 *   5. ordinary Teacher (never an admin)                    → DENY  (unchanged)
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
const SCHOOL = 'SCH_TEST_FOLD_001';

let env;

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });

beforeEach(async () => {
  await env.clearFirestore();
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    // tenantActive() needs an active schoolControl doc for this school.
    await db.doc(`schoolControl/${SCHOOL}`).set({ lifecycle: { state: 'active' } });
    await db.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL });
    // Server-maintained capability docs (uid == staffId).
    await db.doc('staffCapabilities/folded-admin').set({ schoolId: SCHOOL, admin: true,  modules: [], levels: {} });
    await db.doc('staffCapabilities/folded-plain').set({ schoolId: SCHOOL, admin: false, modules: [], levels: {} });
  });
});

// Authenticated Firestore handle with the given uid + token role.
function asUser(uid, role) {
  return env.authenticatedContext(uid, { school_id: SCHOOL, role }).firestore();
}
/* The isAdmin()-gated write under test.
   PROBE CHANGED 2026-09-02, subjects -> locations, and the assertions were NOT
   flipped. SEC-3 wave4 made `subjects` server-only ("Subject catalogue. No
   client writes"), so the old probe is denied for EVERY caller — which would
   have made these tests pass as assertFails while proving nothing about
   isAdmin() at all.
   `locations` is still gated exactly as intended:
       allow create, update: if isAdmin() && isSameSchoolWrite();
   so it exercises the isAdmin() fold, which is what this suite is for. */
function writeSubject(db) {
  return db.doc(`locations/${SCHOOL}_L1`).set({ schoolId: SCHOOL, name: 'Main Block' });
}

describe('FOLD isAdmin() — legacy role arm still works', () => {
  test('legacy admin (role=Admin) → ALLOW', async () => {
    await assertSucceeds(writeSubject(asUser('legacy-admin', 'Admin')));
  });
  test('Super Admin → ALLOW', async () => {
    await assertSucceeds(writeSubject(asUser('sa-uid', 'Super Admin')));
  });
});

describe('FOLD isAdmin() — new staffCapabilities.admin arm', () => {
  test('folded admin (role="HR Manager" + caps.admin=true) → ALLOW', async () => {
    await assertSucceeds(writeSubject(asUser('folded-admin', 'HR Manager')));
  });
  test('folded plain staff (role="Librarian" + caps.admin=false) → DENY (no escalation)', async () => {
    await assertFails(writeSubject(asUser('folded-plain', 'Librarian')));
  });
  test('folded admin with NO caps doc → DENY (fail-safe to role arm)', async () => {
    await assertFails(writeSubject(asUser('no-caps-uid', 'HR Manager')));
  });
});

describe('FOLD isAdmin() — non-admins unchanged', () => {
  test('ordinary Teacher → DENY', async () => {
    await assertFails(writeSubject(asUser('teacher-uid', 'Teacher')));
  });
});
