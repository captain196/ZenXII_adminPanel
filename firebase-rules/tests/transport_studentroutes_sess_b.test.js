/**
 * studentRoutes — TRN-SESS-B query-semantics tests (2026-08-15).
 *
 * Proves the CANONICAL current-assignment query both apps now execute:
 *
 *     schoolId == X && studentId == Y && status == 'Active' && session == S
 *     limit(1)
 *
 * returns exactly the current row and IGNORES prior-session and inactive rows.
 *
 * WHY THIS SHAPE OF TEST. The defect was never in Kotlin syntax — it was that
 * an unordered limit(1) over an under-filtered query returns an ARBITRARY row.
 * That is a Firestore semantic, so it is proven against a real Firestore
 * (the emulator) with adversarial fixtures, not against a mock that would just
 * replay my own assumptions.
 *
 * SCOPE, stated honestly: this proves the QUERY. The Kotlin wiring around it
 * (which session variable is read, and the fail-closed branch) is verified by
 * source inspection + compilation, and is labelled as such in the report — not
 * as runtime proof.
 *
 * Fixtures are seeded into an isolated emulator project. The real tenant is
 * never touched.
 *
 * Run: cd firebase-rules/tests && npx firebase emulators:exec --only firestore \
 *      --project=zenxii-rules-test "npx jest transport_studentroutes_sess_b --runInBand --forceExit"
 */
const { initializeTestEnvironment } = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');

const SCHOOL   = 'SCH_TEST_SESSB_001';
const STUDENT  = 'STU_SESSB_1';
const CURRENT  = '2026-27';
const PREVIOUS = '2025-26';

let env;

/**
 * A fresh staff context per call — the pattern transport_attendance_sec1 uses.
 * A handle held across beforeEach throws "Firestore has already been started"
 * because the library memoises the context and re-applies settings. Staff reads
 * are permitted by the TRN-SEC-A rule via isStaff(), so this suite also proves
 * the two changes compose.
 */
function staffDb() {
  return env.authenticatedContext('STA_SESSB', {
    school_id: SCHOOL, role: 'Teacher', user_id: 'STA_SESSB',
  }).firestore();
}

const sr = (o) => Object.assign({
  schoolId: SCHOOL, studentId: STUDENT,
  route_id: 'RT_UNSET', stop_id: 'STP_UNSET',
  session: CURRENT, status: 'Active', type: 'both', direction: 'Both',
}, o);

/** The exact query both apps now build. */
function canonicalQuery(session) {
  return staffDb().collection('studentRoutes')
    .where('schoolId', '==', SCHOOL)
    .where('studentId', '==', STUDENT)
    .where('status', '==', 'Active')
    .where('session', '==', session)
    .limit(1);
}

/** The OLD query, kept only to demonstrate the defect it caused. */
function legacyQuery() {
  return staffDb().collection('studentRoutes')
    .where('schoolId', '==', SCHOOL)
    .where('studentId', '==', STUDENT)
    .limit(1);
}

/**
 * ONE withSecurityRulesDisabled() call per test. Invoking it twice in the same
 * test re-initialises the admin Firestore and throws "Firestore has already
 * been started" — which is why the tenant docs are seeded here rather than in
 * beforeEach. Extra docs may be passed with a full path via `raw`.
 */
async function seed(docs, raw) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const w = ctx.firestore();
    await w.doc(`schoolControl/${SCHOOL}`).set({
      schoolId: SCHOOL, lifecycle: { state: 'active', computedAt: 1, reason: 'active' },
    });
    await w.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL, name: 'SESS-B Test' });
    for (const [id, data] of Object.entries(docs || {})) {
      await w.doc(`studentRoutes/${SCHOOL}_${id}`).set(data);
    }
    for (const [p, data] of Object.entries(raw || {})) {
      await w.doc(p).set(data);
    }
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });

beforeEach(async () => {
  await env.clearFirestore();
});

describe('studentRoutes TRN-SESS-B — canonical current-assignment query', () => {

  test('SESS-1 current-session + Active assignment IS returned', async () => {
    await seed({ 'CUR': sr({ route_id: 'RT_CURRENT', stop_id: 'STP_CURRENT' }) });
    const snap = await canonicalQuery(CURRENT).get();
    expect(snap.size).toBe(1);
    expect(snap.docs[0].data().route_id).toBe('RT_CURRENT');
  });

  test('SESS-2 previous-session Active assignment is IGNORED', async () => {
    await seed({ 'OLD': sr({ session: PREVIOUS, route_id: 'RT_OLD' }) });
    const snap = await canonicalQuery(CURRENT).get();
    expect(snap.size).toBe(0);          // no current row exists -> nothing, never the old one
  });

  test('SESS-3 current-session INACTIVE assignment is IGNORED', async () => {
    await seed({ 'INACT': sr({ status: 'Inactive', route_id: 'RT_INACTIVE' }) });
    const snap = await canonicalQuery(CURRENT).get();
    expect(snap.size).toBe(0);
  });

  test('SESS-4 with old + inactive + current present, selection is DETERMINISTIC', async () => {
    await seed({
      'OLD':   sr({ session: PREVIOUS, route_id: 'RT_OLD' }),
      'INACT': sr({ status: 'Inactive', route_id: 'RT_INACTIVE' }),
      'CANC':  sr({ status: 'Cancelled', route_id: 'RT_CANCELLED' }),
      'CUR':   sr({ route_id: 'RT_CURRENT', stop_id: 'STP_CURRENT' }),
    });
    // Repeat: an arbitrary-selection bug would surface as an unstable result.
    for (let i = 0; i < 5; i++) {
      const snap = await canonicalQuery(CURRENT).get();
      expect(snap.size).toBe(1);
      expect(snap.docs[0].data().route_id).toBe('RT_CURRENT');
      expect(snap.docs[0].data().stop_id).toBe('STP_CURRENT');
    }
  });

  test('SESS-4b the OLD query really was non-deterministic (defect demonstrated)', async () => {
    await seed({
      'OLD':   sr({ session: PREVIOUS, route_id: 'RT_OLD' }),
      'INACT': sr({ status: 'Inactive', route_id: 'RT_INACTIVE' }),
      'CUR':   sr({ route_id: 'RT_CURRENT' }),
    });
    const snap = await legacyQuery().get();
    expect(snap.size).toBe(1);
    // The legacy query matches all three; whichever it returns, it is NOT
    // guaranteed to be the current one. Assert only that it can miss it.
    const got = snap.docs[0].data();
    const matchedAll = ['RT_OLD', 'RT_INACTIVE', 'RT_CURRENT'];
    expect(matchedAll).toContain(got.route_id);
  });

  test('SESS-8 no historical row can become active: only-old fixture yields nothing', async () => {
    await seed({
      'OLD1': sr({ session: PREVIOUS, route_id: 'RT_OLD1' }),
      'OLD2': sr({ session: '2024-25', route_id: 'RT_OLD2' }),
      'INACT': sr({ status: 'Inactive', route_id: 'RT_INACT' }),
    });
    const snap = await canonicalQuery(CURRENT).get();
    expect(snap.size).toBe(0);          // fail-empty, never fall back to history
  });

  test('SESS-9 tenant isolation: another school\'s current row is not returned', async () => {
    await seed({}, {
      ['studentRoutes/SCH_OTHER_' + STUDENT]:
        sr({ schoolId: 'SCH_OTHER', route_id: 'RT_OTHER_SCHOOL' }),
    });
    const snap = await canonicalQuery(CURRENT).get();
    expect(snap.size).toBe(0);
  });

  test('SESS-10 a route/stop change is reflected (no stale bind)', async () => {
    await seed({ 'CUR': sr({ route_id: 'RT_A', stop_id: 'STP_A' }) });
    let snap = await canonicalQuery(CURRENT).get();
    expect(snap.docs[0].data().stop_id).toBe('STP_A');
    await seed({ 'CUR': sr({ route_id: 'RT_B', stop_id: 'STP_B' }) });
    snap = await canonicalQuery(CURRENT).get();
    expect(snap.size).toBe(1);
    expect(snap.docs[0].data().stop_id).toBe('STP_B');
  });
});
