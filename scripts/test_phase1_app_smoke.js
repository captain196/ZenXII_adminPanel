#!/usr/bin/env node
/**
 * Phase 1 — Parent & Teacher app smoke equivalent.
 *
 * The mobile apps cannot be exercised from this seat, but we can
 * verify every Firestore read path they depend on by:
 *   1. Picking a real (studentId, schoolId, session) tuple from
 *      live feeDemands data.
 *   2. Minting a Parent ID token (uid == that studentId, role=parent).
 *   3. Minting a Teacher ID token (synthetic uid, role=teacher).
 *   4. Attempting each app-equivalent read against live Firestore REST.
 *   5. Verifying ALLOW/DENY against expected per Phase-1 rules.
 *   6. Confirming cross-student isolation (Parent A can't read Parent B's
 *      data).
 *
 * Read-only. No docs created or mutated.
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

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Phase 1 — Parent & Teacher app smoke (Firestore-side equiv)');
  console.log(`  project: ${PROJECT_ID}`);
  console.log('═══════════════════════════════════════════════════════════════\n');

  // ── Setup: discover a real (studentA, schoolId, session) from live data ──
  console.log('Discovering real test fixtures from live data...');
  const demandsSnap = await fsdb.collection('feeDemands').limit(50).get();
  if (demandsSnap.empty) { console.error('No feeDemands in collection — cannot run smoke.'); process.exit(2); }

  const demandsByStudent = new Map(); // studentId -> array of { id, schoolId, session }
  demandsSnap.docs.forEach(d => {
    const data = d.data();
    const sid = data.studentId || data.student_id;
    if (!sid) return;
    if (!demandsByStudent.has(sid)) demandsByStudent.set(sid, []);
    demandsByStudent.get(sid).push({ id: d.id, schoolId: data.schoolId, session: data.session });
  });
  if (demandsByStudent.size < 2) {
    console.error('Need ≥2 distinct studentIds in feeDemands sample for cross-isolation test. Got ' + demandsByStudent.size);
    process.exit(2);
  }
  const [studentA, studentB] = [...demandsByStudent.keys()];
  const demandA = demandsByStudent.get(studentA)[0];
  const demandB = demandsByStudent.get(studentB)[0];
  const SCHOOL_ID = demandA.schoolId;
  const SESSION = demandA.session;

  // Try to find a feeReceipt that belongs to studentA (used for P2 + T3)
  const recSnap = await fsdb.collection('feeReceipts')
    .where('studentId', '==', studentA).limit(1).get();
  let receiptA = null;
  if (!recSnap.empty) receiptA = recSnap.docs[0].id;
  else {
    // Fallback: ANY receipt — we'll pick the studentId from it for the parent test
    const anyRec = await fsdb.collection('feeReceipts').limit(1).get();
    if (!anyRec.empty) {
      receiptA = anyRec.docs[0].id;
      // For the parent receipt test we'd need a parent matching THIS receipt's
      // studentId. Re-anchor parentA on it.
      const recData = anyRec.docs[0].data();
      const recStudent = recData.studentId || recData.student_id;
      if (recStudent && recStudent !== studentA) {
        // pick a different fallback so P2 still tests own-receipt access
        // by re-anchoring the parent identity
      }
    }
  }

  console.log(`  studentA:  ${studentA}`);
  console.log(`  studentB:  ${studentB} (used for cross-isolation DENY test)`);
  console.log(`  schoolId:  ${SCHOOL_ID}`);
  console.log(`  session:   ${SESSION}`);
  console.log(`  demandA.id: ${demandA.id}`);
  console.log(`  demandB.id: ${demandB.id}`);
  console.log(`  receiptA:   ${receiptA || '(none — parent receipt test will use any receipt)'}`);

  // ── Mint tokens ──────────────────────────────────────────────
  console.log('\nMinting ID tokens...');
  const parentAToken = await getIdToken(studentA, { role: 'parent', school_id: SCHOOL_ID });
  const teacherToken = await getIdToken('teacher_test_phase1', { role: 'teacher', school_id: SCHOOL_ID });
  console.log('  parentA: ok');
  console.log('  teacher: ok\n');

  let pass = 0, fail = 0;
  const normalize = v => v.startsWith('ALLOW') ? 'ALLOW' : v.startsWith('DENY') ? 'DENY' : v;
  const recordResult = (label, expected, actualStatus, mapping) => {
    const verdict = mapping(actualStatus);
    const ok = normalize(verdict) === expected;
    console.log(`${ok ? '✅' : '❌'} ${label}`);
    console.log(`     expected=${expected}  actual=${verdict}  (HTTP ${actualStatus})`);
    if (ok) pass++; else fail++;
  };

  const allowMap = s => s === 200 ? 'ALLOW' : s === 404 ? 'ALLOW (doc absent)' : s === 403 ? 'DENY' : `HTTP_${s}`;
  const denyMap  = s => s === 403 ? 'DENY'  : s === 200 ? 'ALLOW' : s === 404 ? 'ALLOW (doc absent)' : `HTTP_${s}`;

  // ── PARENT BATTERY ──────────────────────────────────────────
  console.log('── PARENT APP READ EQUIVALENT (uid = studentA) ──\n');

  console.log('P1 — parent reads own feeDemand (must ALLOW)');
  const rP1 = await fsGet(parentAToken, `feeDemands/${demandA.id}`);
  recordResult('P1 — feeDemands/{ownDemandId}', 'ALLOW', rP1.status, allowMap);

  if (receiptA) {
    console.log('P2 — parent reads a feeReceipt (must ALLOW or DENY based on ownership)');
    const rP2 = await fsGet(parentAToken, `feeReceipts/${receiptA}`);
    // Note: receiptA may belong to a different studentId. If so, expect DENY.
    // We resolve by reading the doc once via Admin SDK to know the truth.
    const recOwnerSnap = await fsdb.collection('feeReceipts').doc(receiptA).get();
    const recOwner = (recOwnerSnap.data() || {}).studentId;
    const expectedP2 = recOwner === studentA ? 'ALLOW' : 'DENY';
    recordResult(`P2 — feeReceipts/${receiptA} (recOwner=${recOwner === studentA ? 'self' : 'other'})`, expectedP2, rP2.status, expectedP2 === 'ALLOW' ? allowMap : denyMap);
  }

  console.log('P3 — parent reads own feeDefaulters (must ALLOW; doc may be absent)');
  const rP3 = await fsGet(parentAToken, `feeDefaulters/${SCHOOL_ID}_${SESSION}_${studentA}`);
  recordResult('P3 — feeDefaulters/{ownDocId}', 'ALLOW', rP3.status, allowMap);

  console.log('P4 — parent reads own feeCarryForward (must ALLOW; doc may be absent — Phase 1 NEW RULE)');
  const rP4 = await fsGet(parentAToken, `feeCarryForward/${SCHOOL_ID}_${SESSION}_${studentA}`);
  recordResult('P4 — feeCarryForward/{ownDocId}', 'ALLOW', rP4.status, allowMap);

  console.log('P5 — parent reads OTHER student feeDefaulters (must DENY — cross-student isolation)');
  const rP5 = await fsGet(parentAToken, `feeDefaulters/${SCHOOL_ID}_${SESSION}_${studentB}`);
  recordResult('P5 — feeDefaulters/{otherStudentDocId}', 'DENY', rP5.status, denyMap);

  console.log('P6 — parent reads OTHER student feeDemand (must DENY — cross-student isolation)');
  const rP6 = await fsGet(parentAToken, `feeDemands/${demandB.id}`);
  recordResult('P6 — feeDemands/{otherStudentDemandId}', 'DENY', rP6.status, denyMap);

  // ── TEACHER BATTERY ─────────────────────────────────────────
  console.log('\n── TEACHER APP READ EQUIVALENT (synthetic teacher uid) ──\n');

  console.log('T1 — teacher reads any feeDemand (must ALLOW — staff blanket access)');
  const rT1 = await fsGet(teacherToken, `feeDemands/${demandA.id}`);
  recordResult('T1 — feeDemands/{anyDemandId}', 'ALLOW', rT1.status, allowMap);

  if (receiptA) {
    console.log('T2 — teacher reads a feeReceipt (must ALLOW — staff blanket access)');
    const rT2 = await fsGet(teacherToken, `feeReceipts/${receiptA}`);
    recordResult('T2 — feeReceipts/{anyReceiptId}', 'ALLOW', rT2.status, allowMap);
  }

  console.log('T3 — teacher reads any feeDefaulters (must ALLOW)');
  const rT3 = await fsGet(teacherToken, `feeDefaulters/${SCHOOL_ID}_${SESSION}_${studentA}`);
  recordResult('T3 — feeDefaulters/{anyDocId}', 'ALLOW', rT3.status, allowMap);

  console.log('T4 — teacher reads any feeCarryForward (must ALLOW — Phase 1 NEW RULE)');
  const rT4 = await fsGet(teacherToken, `feeCarryForward/${SCHOOL_ID}_${SESSION}_${studentA}`);
  recordResult('T4 — feeCarryForward/{anyDocId}', 'ALLOW', rT4.status, allowMap);

  // ── Summary ─────────────────────────────────────────────────
  console.log('\n───────────────────────────────────────────────────────────────');
  console.log(`Result: ${pass} pass, ${fail} fail`);
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
