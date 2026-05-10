#!/usr/bin/env node
/**
 * Phase 1 — live Firestore rules verification.
 *
 * The firebaserules.test API requires `firebaserules.releases.runTests`
 * which the graderadmin SA does not have. Instead this script:
 *   1. Mints a Firebase custom token via Admin SDK with desired claims
 *   2. Exchanges it for an ID token via Identity Toolkit
 *   3. Attempts the four operations against live Firestore REST as a
 *      non-privileged client — rules fire for real
 *   4. Observes HTTP status code and infers ALLOW/DENY
 *   5. Defensively checks no test doc leaked
 *
 * Non-mutating: 1 of 4 tests is a write expected to DENY (no doc
 * created on success); 3 are reads. After the run, scans for a
 * leaked test doc and deletes it via Admin SDK if present.
 */
const path = require('path');
const https = require('https');

const FN_NM = path.resolve(__dirname, '..', 'functions', 'node_modules');
const admin = require(path.join(FN_NM, 'firebase-admin'));

const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fsdb = admin.firestore();

const PROJECT_ID = sa.project_id;
const WEB_API_KEY = 'AIzaSyBe0xmEw3ms6WWmnkj3-hFAspksx9v4CTQ';
const SCHOOL = 'SCH_D94FE8F7AD';
const TEST_DOC_PATH = 'feeAuditLogs/test_phase1_lock_check';

function httpsRequest(opts, body) {
  return new Promise((resolve, reject) => {
    const req = https.request(opts, res => {
      let buf = '';
      res.on('data', d => buf += d);
      res.on('end', () => {
        let parsed = buf;
        try { parsed = JSON.parse(buf); } catch (e) {}
        resolve({ status: res.statusCode, body: parsed });
      });
    });
    req.on('error', reject);
    if (body) req.write(typeof body === 'string' ? body : JSON.stringify(body));
    req.end();
  });
}

async function getIdToken(uid, claims) {
  const customToken = await admin.auth().createCustomToken(uid, claims);
  const r = await httpsRequest({
    hostname: 'identitytoolkit.googleapis.com',
    path: `/v1/accounts:signInWithCustomToken?key=${WEB_API_KEY}`,
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
  }, { token: customToken, returnSecureToken: true });
  if (r.status !== 200 || !r.body.idToken) {
    throw new Error('signInWithCustomToken failed: ' + JSON.stringify(r.body));
  }
  return r.body.idToken;
}

async function fsGet(idToken, docPath) {
  const r = await httpsRequest({
    hostname: 'firestore.googleapis.com',
    path: `/v1/projects/${PROJECT_ID}/databases/(default)/documents/${docPath}`,
    method: 'GET',
    headers: { 'Authorization': `Bearer ${idToken}` },
  });
  return r;
}

async function fsCreate(idToken, collection, docId, fields) {
  const r = await httpsRequest({
    hostname: 'firestore.googleapis.com',
    path: `/v1/projects/${PROJECT_ID}/databases/(default)/documents/${collection}?documentId=${docId}`,
    method: 'POST',
    headers: { 'Authorization': `Bearer ${idToken}`, 'Content-Type': 'application/json' },
  }, { fields });
  return r;
}

async function getRealAuditDocId() {
  const snap = await fsdb.collection('feeAuditLogs').limit(1).get();
  if (snap.empty) throw new Error('feeAuditLogs is empty — backfill missing?');
  return snap.docs[0].id;
}

async function ensureNoLeak() {
  const ref = fsdb.doc(TEST_DOC_PATH);
  const snap = await ref.get();
  if (snap.exists) {
    console.error(`\n🚨 LEAKED TEST DOC FOUND at ${TEST_DOC_PATH} — rule DID NOT deny. Deleting now.`);
    await ref.delete();
    return false;
  }
  return true;
}

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Phase 1 — live Firestore rules verification');
  console.log(`  project: ${PROJECT_ID}`);
  console.log('═══════════════════════════════════════════════════════════════');

  // Pre-flight: confirm no leftover test doc from a previous run
  const cleanStart = await ensureNoLeak();
  if (!cleanStart) console.log('  (pre-existing leftover cleaned — continuing)');

  const realAuditId = await getRealAuditDocId();
  console.log(`  realAuditId for 17b: ${realAuditId}\n`);

  // Mint two ID tokens — one admin, one parent
  console.log('Minting ID tokens...');
  const adminToken = await getIdToken('admin_test_phase1', { role: 'admin', school_id: SCHOOL });
  const parentToken = await getIdToken('parent_test_phase1', { role: 'parent', school_id: SCHOOL });
  console.log('  admin: ok');
  console.log('  parent: ok\n');

  let pass = 0, fail = 0;
  const normalize = v => v.startsWith('ALLOW') ? 'ALLOW' : v.startsWith('DENY') ? 'DENY' : v;
  const recordResult = (label, expected, actualStatus, mapping) => {
    const verdict = mapping(actualStatus);
    const ok = normalize(verdict) === expected;
    console.log(`${ok ? '✅' : '❌'} ${label}`);
    console.log(`     expected=${expected}  actual=${verdict}  (HTTP ${actualStatus})`);
    if (ok) pass++; else fail++;
  };

  // ── 17a: admin write to feeAuditLogs (must DENY) ─────────────
  console.log('Test 17a — admin write to feeAuditLogs (must DENY)');
  const r17a = await fsCreate(adminToken, 'feeAuditLogs', 'test_phase1_lock_check', {
    schoolId: { stringValue: SCHOOL },
    auditId: { stringValue: 'test' },
    _phase1_test: { booleanValue: true },
  });
  recordResult('17a — admin write to feeAuditLogs', 'DENY', r17a.status, s => s === 403 ? 'DENY' : (s >= 200 && s < 300) ? 'ALLOW' : `HTTP_${s}`);

  // ── 17b: admin read of real feeAuditLogs doc (must ALLOW) ────
  console.log('Test 17b — admin read of real feeAuditLogs doc (must ALLOW)');
  const r17b = await fsGet(adminToken, `feeAuditLogs/${realAuditId}`);
  recordResult('17b — admin read of real feeAuditLogs doc', 'ALLOW', r17b.status, s => s === 200 ? 'ALLOW' : s === 403 ? 'DENY' : `HTTP_${s}`);

  // ── 17c: parent read of /studentFeeSummary/anything (must DENY) ─
  console.log('Test 17c — parent read of /studentFeeSummary/anything (must DENY)');
  const r17c = await fsGet(parentToken, 'studentFeeSummary/anything');
  recordResult('17c — parent read of /studentFeeSummary/anything', 'DENY', r17c.status, s => s === 403 ? 'DENY' : s === 404 ? 'ALLOW (doc absent)' : (s >= 200 && s < 300) ? 'ALLOW' : `HTTP_${s}`);

  // ── 17d: admin read of non-existent feeCarryForward doc (must ALLOW) ─
  console.log('Test 17d — admin read of /feeCarryForward/SCH_*_2026-27_anyStudent (must ALLOW)');
  const r17d = await fsGet(adminToken, `feeCarryForward/${SCHOOL}_2026-27_anyStudent`);
  // ALLOW + doc absent = 404 (rule passed, then doc not found)
  // ALLOW + doc present = 200
  // DENY = 403
  recordResult('17d — admin read of feeCarryForward (non-existent)', 'ALLOW', r17d.status, s => s === 200 ? 'ALLOW (doc present)' : s === 404 ? 'ALLOW (doc absent)' : s === 403 ? 'DENY' : `HTTP_${s}`);

  console.log('───────────────────────────────────────────────────────────────');
  console.log(`Result: ${pass}/4 pass, ${fail} fail`);

  // Post-flight: confirm no test doc leaked from 17a
  console.log('\nPost-flight leak check on /feeAuditLogs/test_phase1_lock_check ...');
  const cleanEnd = await ensureNoLeak();
  console.log(cleanEnd ? '  ✅ no leak — rule held' : '  ❌ LEAK detected (cleaned)');

  process.exit(fail === 0 && cleanEnd ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
