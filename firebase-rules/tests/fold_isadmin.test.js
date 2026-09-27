/**
 * Admin→staff FOLD — rules unit tests for the isAdmin() OR-arm.
 *
 * After the fold a demoted admin's token role is their NOMINAL label (e.g.
 * "HR Manager"), no longer "Admin", so the legacy role arm alone would drop them
 * from every isAdmin()-gated write. The fold adds a second arm that recognises a
 * true admin via the server-maintained staffCapabilities/{uid}.admin flag.
 *
 * Target: /rbacRoles write (allow write: if isAdmin();) — a clean isAdmin()-only arm.
 *
 * NOTE (CAL-D1, 2026-09-01): this probe used /subjects until the CAL-D1 tenant-
 * binding restoration returned that collection to `allow write: if false` — the
 * server-only posture SEC-3 wave 4 deployed to production on 2026-08-21 and which
 * the repository ruleset had never received. /subjects was only ever a VEHICLE for
 * exercising isAdmin(), never an assertion that subjects should be client-writable,
 * so the probe moves to another collection whose arm is still isAdmin()-only. What
 * this file tests — the fold's two arms — is unchanged.
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
// The isAdmin()-gated write under test.
function writeSubject(db) {
  return db.doc(`rbacRoles/${SCHOOL}_R1`).set({ schoolId: SCHOOL, name: 'Probe Role' });
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
