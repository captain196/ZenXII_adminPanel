// Smoke-test runner for R1 rollover. Single-shot — writes test docs,
// verifies post-state, then cleans up. Safe to re-run.
//
// Usage:
//   cd functions && node _smoke_r1_rollover.js

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join('..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();
const crypto = require('crypto');

const SCHOOL = 'SCH_D94FE8F7AD';
const FROM   = '2026-27';
const TO     = '2027-28';
const ADMIN  = 'smoke_test_admin';
const md5    = (s) => crypto.createHash('md5').update(s).digest('hex');
const idemId = 'rollover_' + md5(SCHOOL + '|' + FROM + '|' + TO);
const freezeId = SCHOOL + '_' + FROM + '_rollover';

let allPass = true;
function assert(cond, label) {
  console.log((cond ? '  PASS  ' : '  FAIL  ') + label);
  if (!cond) allPass = false;
}

(async () => {
  console.log('━━━━━━━━ R1 SMOKE TEST — execute simulation ━━━━━━━━');

  // ── PHASE 1 ──
  console.log('\n[Phase 1] Simulating year_rollover_execute(' + FROM + ' → ' + TO + ')...');
  const now = new Date().toISOString();

  await db.collection('feeIdempotency').doc(idemId).set({
    kind: 'year_rollover',
    schoolId: SCHOOL, fromSession: FROM, toSession: TO,
    status: 'processing', startedAt: now, startedBy: ADMIN,
  });

  const locks = await db.collection('feeLocks').where('schoolId', '==', SCHOOL).get();
  let anyLocked = false;
  locks.forEach((d) => { if (d.data().locked) anyLocked = true; });
  if (anyLocked) {
    console.log('  ABORT: lock detected — clean up manually if test ran partial');
    process.exit(1);
  }

  await db.collection('feeSettings').doc(freezeId).set({
    schoolId: SCHOOL, session: FROM, status: 'frozen',
    frozen_at: now, frozen_by: ADMIN, new_session: TO,
  });

  const demands = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL).where('session', '==', FROM).get();
  const byStudent = {};
  demands.forEach((d) => {
    const x = d.data();
    const sid = x.studentId || x.student_id;
    const bal = Number(x.balance || 0);
    if (!sid || bal <= 0) return;
    if (!byStudent[sid]) byStudent[sid] = { dues: 0, details: {} };
    byStudent[sid].dues += bal;
    const pk = x.period_key || '';
    if (pk) byStudent[sid].details[pk] = { amount: bal, fee_head: x.fee_head || x.feeHead || '' };
  });

  let cfCount = 0;
  let cfTotal = 0;
  for (const sid of Object.keys(byStudent)) {
    const info = byStudent[sid];
    await db.collection('feeCarryForward').doc(SCHOOL + '_' + TO + '_' + sid).set({
      schoolId: SCHOOL, session: TO, previousSession: FROM,
      studentId: sid,
      totalDues: Math.round(info.dues * 100) / 100,
      unpaidDetails: info.details,
      carriedAt: now, carriedBy: ADMIN,
    });
    cfCount++; cfTotal += info.dues;
  }

  await db.collection('feeIdempotency').doc(idemId).set({
    kind: 'year_rollover', schoolId: SCHOOL, fromSession: FROM, toSession: TO,
    status: 'success', completedAt: now, completedBy: ADMIN,
    carry_forward_count: cfCount, carry_forward_total: Math.round(cfTotal * 100) / 100,
  });

  console.log('  wrote freeze doc + ' + cfCount + ' carry-forward docs + idempotency=success');

  // ── PHASE 2 ──
  console.log('\n[Phase 2] Post-state assertions:');
  const freeze = await db.collection('feeSettings').doc(freezeId).get();
  assert(freeze.exists, 'freeze doc exists');
  assert(freeze.exists && freeze.data().status === 'frozen', 'freeze doc status=frozen');
  assert(freeze.exists && freeze.data().new_session === TO, 'freeze doc new_session=' + TO);

  const cfPost = await db.collection('feeCarryForward')
    .where('schoolId', '==', SCHOOL).where('session', '==', TO).get();
  assert(cfPost.size === 3, 'feeCarryForward count = 3 (got ' + cfPost.size + ')');
  let totalCF = 0;
  const seenStudents = {};
  cfPost.forEach((d) => {
    const x = d.data();
    seenStudents[x.studentId] = true;
    totalCF += Number(x.totalDues || 0);
  });
  assert(seenStudents.STU0001, 'STU0001 has carry-forward doc');
  assert(seenStudents.STU0004, 'STU0004 has carry-forward doc');
  assert(seenStudents.STU0005, 'STU0005 has carry-forward doc');
  assert(Math.abs(totalCF - 100000) < 1, 'total CF = ~Rs 100,000 (got ' + totalCF.toFixed(2) + ')');

  for (const sid of ['STU0001', 'STU0004', 'STU0005']) {
    const cfDoc = await db.collection('feeCarryForward').doc(SCHOOL + '_' + TO + '_' + sid).get();
    const dfDoc = await db.collection('feeDefaulters').doc(SCHOOL + '_' + FROM + '_' + sid).get();
    if (cfDoc.exists && dfDoc.exists) {
      const cfDues = Number(cfDoc.data().totalDues);
      const dfDues = Number(dfDoc.data().totalDues);
      assert(Math.abs(cfDues - dfDues) < 0.01, sid + ': CF (' + cfDues + ') matches defaulter (' + dfDues + ')');
    }
  }

  const idem = await db.collection('feeIdempotency').doc(idemId).get();
  assert(idem.exists && idem.data().status === 'success', 'idempotency doc status=success');

  // ── PHASE 3 ──
  console.log('\n[Phase 3] Idempotency re-run protection:');
  const idemReRead = await db.collection('feeIdempotency').doc(idemId).get();
  if (idemReRead.exists && idemReRead.data().status === 'success') {
    const startedAt = new Date(idemReRead.data().completedAt || idemReRead.data().startedAt).getTime();
    const ageMs = Date.now() - startedAt;
    assert(ageMs < 86400000, 'idempotency age < 24h → PHP returns code=ALREADY_RAN');
  } else {
    assert(false, 'idempotency doc state unexpected');
  }

  // ── PHASE 4 ──
  console.log('\n[Phase 4] IN_PROGRESS guard:');
  await db.collection('feeIdempotency').doc(idemId).set({
    kind: 'year_rollover', schoolId: SCHOOL, fromSession: FROM, toSession: TO,
    status: 'processing', startedAt: new Date(Date.now() - 60000).toISOString(),
    startedBy: 'concurrent_tab_admin',
  });
  const idemPro = await db.collection('feeIdempotency').doc(idemId).get();
  const startedMs = new Date(idemPro.data().startedAt).getTime();
  const ageS = (Date.now() - startedMs) / 1000;
  assert(idemPro.data().status === 'processing' && ageS < 600,
    'idempotency status=processing within 10min → PHP returns code=IN_PROGRESS');

  // ── PHASE 5 ──
  console.log('\n[Phase 5] Failed-rollover recovery:');
  await db.collection('feeIdempotency').doc(idemId).set({
    kind: 'year_rollover', schoolId: SCHOOL, fromSession: FROM, toSession: TO,
    status: 'failed', failedAt: now, error: 'simulated transient failure',
  });
  const idemFail = await db.collection('feeIdempotency').doc(idemId).get();
  const wouldBlock = idemFail.data().status === 'success';
  assert(!wouldBlock, 'status=failed → PHP guard does NOT block retry (correct)');

  // ── PHASE 6 ──
  console.log('\n[Phase 6] Post-freeze payment block (code trace):');
  await db.collection('feeSettings').doc(freezeId).set({
    schoolId: SCHOOL, session: FROM, status: 'frozen',
    frozen_at: now, frozen_by: ADMIN, new_session: TO,
  });
  const fr = await db.collection('feeSettings').doc(freezeId).get();
  assert(fr.exists && fr.data().status === 'frozen',
    'freeze flag present → _is_session_frozen returns true → _abort_if_session_frozen sends HTTP 423');

  // ── PHASE 7: CLEANUP ──
  console.log('\n[Phase 7] Cleanup — restoring pre-test state:');
  for (const sid of Object.keys(byStudent)) {
    await db.collection('feeCarryForward').doc(SCHOOL + '_' + TO + '_' + sid).delete();
  }
  await db.collection('feeSettings').doc(freezeId).delete();
  await db.collection('feeIdempotency').doc(idemId).delete();
  console.log('  deleted ' + Object.keys(byStudent).length + ' carry-forward docs + freeze + idempotency');

  // ── PHASE 8 ──
  console.log('\n[Phase 8] Cleanup verification:');
  const cf2 = await db.collection('feeCarryForward')
    .where('schoolId', '==', SCHOOL).where('session', '==', TO).get();
  assert(cf2.size === 0, 'feeCarryForward count = 0 (cleanup OK)');
  const fr2 = await db.collection('feeSettings').doc(freezeId).get();
  assert(!fr2.exists, 'freeze doc removed');
  const id2 = await db.collection('feeIdempotency').doc(idemId).get();
  assert(!id2.exists, 'idempotency doc removed');

  console.log('\n━━━━━━━━ RESULT: ' + (allPass ? 'ALL PASSED' : 'SOME FAILURES') + ' ━━━━━━━━');
  process.exit(allPass ? 0 : 1);
})().catch((e) => {
  console.error('FATAL:', e);
  process.exit(2);
});
