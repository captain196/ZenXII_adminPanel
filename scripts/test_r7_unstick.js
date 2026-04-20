// R.7 test helper — sets up a 'stuck processing' refund so you can
// click the Unstick button in the admin panel and watch the flow
// without trying to race a real crash.
//
// Usage (from C:\xampp\htdocs\Grader\school):
//   node scripts/test_r7_unstick.js list [schoolId]
//     → prints recent refunds you can use for the test.
//
//   node scripts/test_r7_unstick.js fresh <docId>
//     → simulates a refund that's STILL in its 5-min processing
//       window. Click Unstick in admin → server should REFUSE with
//       "Refund is still within its processing window. Wait Ns."
//
//   node scripts/test_r7_unstick.js stale <docId>
//     → simulates a refund stranded from a crash long ago.
//       Click Unstick in admin → server should ACCEPT and flip
//       status back to 'approved'.
//
//   node scripts/test_r7_unstick.js verify <docId>
//     → prints the doc's current status / processLock / the
//       matching feeLocks + feeIdempotency docs. Run AFTER clicking
//       Unstick to confirm lock + idempotency were cleared.
//
//   node scripts/test_r7_unstick.js restore <docId>
//     → puts a test refund doc back to status='processed' (doesn't
//       re-refund anything — allocations were already reversed the
//       first time). Run at the end of your test session to leave
//       the refunds list looking normal again.

const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));

admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

function md5(s) { return require('crypto').createHash('md5').update(s).digest('hex'); }

// Must match Fee_firestore_txn::idempKey exactly:
//   md5(userId | refId | receiptNo | amount)
function idempKey(studentId, refId, origReceiptNo, amount) {
  return md5(`${studentId}|${refId}|${origReceiptNo}|${amount}`);
}

async function listRefunds(schoolId) {
  const q = await db.collection('feeRefunds')
    .where('schoolId', '==', schoolId)
    .limit(20).get();
  if (q.empty) { console.log(`No refunds found for schoolId=${schoolId}`); return; }
  console.log(`\nRecent refunds for schoolId=${schoolId}:\n`);
  console.log('docId                                            status       amount   receiptNo  studentId');
  console.log('-'.repeat(110));
  q.docs.forEach(d => {
    const x = d.data();
    const st = String(x.status || '').padEnd(12);
    const amt = String(x.amount || 0).padStart(8);
    const rno = String(x.receiptNo || x.receipt_no || '').padEnd(10);
    const sid = String(x.studentId || x.student_id || '').padEnd(12);
    console.log(`${d.id.padEnd(48)} ${st} ${amt}   ${rno} ${sid}`);
  });
  console.log('\nPick a docId and run:  node scripts/test_r7_unstick.js stale <docId>');
}

async function setState(docId, mode) {
  const ref = db.collection('feeRefunds').doc(docId);
  const snap = await ref.get();
  if (!snap.exists) { console.error(`Doc not found: ${docId}`); process.exit(1); }
  const x = snap.data();

  const lockTs = (mode === 'fresh')
    ? new Date().toISOString()            // now → server refuses
    : '2020-01-01T00:00:00+05:30';        // ancient → server accepts

  await ref.update({
    status:      'processing',
    processLock: lockTs,
  });

  console.log(`\n✓ ${docId} set to:`);
  console.log(`    status       = processing`);
  console.log(`    processLock  = ${lockTs}`);
  console.log(`    studentId    = ${x.studentId || x.student_id}`);
  console.log(`    amount       = ${x.amount}`);
  console.log(`    receiptNo    = ${x.receiptNo || x.receipt_no}`);

  if (mode === 'fresh') {
    console.log(`\n👉 Now go to admin panel → Fee Management → Refunds →`);
    console.log(`   click the "Processing" pill → click "Unstick" on the row.`);
    console.log(`   EXPECTED: red toast "Refund is still within its processing window..."\n`);
  } else {
    console.log(`\n👉 Now go to admin panel → Fee Management → Refunds →`);
    console.log(`   click the "Processing" pill → click "Unstick" on the row.`);
    console.log(`   EXPECTED: green toast "Refund reset to approved. Click Process to retry."`);
    console.log(`   THEN run:  node scripts/test_r7_unstick.js verify ${docId}\n`);
  }
}

async function verify(docId) {
  const ref = db.collection('feeRefunds').doc(docId);
  const snap = await ref.get();
  if (!snap.exists) { console.error(`Doc not found: ${docId}`); process.exit(1); }
  const x = snap.data();

  console.log(`\n── feeRefunds/${docId} ──`);
  console.log(`   status       = ${x.status}       ${x.status === 'approved' ? '✓ (unstick worked)' : '✗ (expected approved)'}`);
  console.log(`   processLock  = "${x.processLock || ''}"  ${(!x.processLock) ? '✓ (cleared)' : '✗ (still set)'}`);

  const schoolId = x.schoolId;
  const studentId = x.studentId || x.student_id;
  const refId = x.refundId || docId.split('_').slice(-1)[0];
  const amount = x.amount;
  const receiptNo = x.receiptNo || x.receipt_no || '';

  const lockSnap = await db.collection('feeLocks').doc(`${schoolId}_${studentId}`).get();
  console.log(`\n── feeLocks/${schoolId}_${studentId} ──`);
  console.log(`   exists       = ${lockSnap.exists}  ${!lockSnap.exists ? '✓ (released)' : '✗ (should be gone)'}`);

  const hash = idempKey(studentId, refId, receiptNo, amount);
  const idempSnap = await db.collection('feeIdempotency').doc(`${schoolId}_${hash}`).get();
  console.log(`\n── feeIdempotency/${schoolId}_${hash} ──`);
  console.log(`   exists       = ${idempSnap.exists}  ${!idempSnap.exists ? '✓ (cleared)' : '✗ (should be gone)'}`);

  console.log(`\n✓ If all three checks above passed, R.7 is working.\n`);
}

// Useful when a prior attempt hit a transport timeout and left the
// per-student feeLocks doc stranded, blocking subsequent refund /
// payment attempts with "Another fee operation for this student is
// in progress". Fully Firestore-only — just deletes the lock doc.
async function unlock(studentId, schoolId) {
  schoolId = schoolId || 'SCH_D94FE8F7AD';
  const docId = `${schoolId}_${studentId}`;
  const ref = db.collection('feeLocks').doc(docId);
  const snap = await ref.get();
  if (!snap.exists) {
    console.log(`\n✓ feeLocks/${docId} — already absent, nothing to clear.\n`);
    return;
  }
  await ref.delete();
  console.log(`\n✓ feeLocks/${docId} deleted. You can retry the refund now.\n`);
}

async function restore(docId) {
  const ref = db.collection('feeRefunds').doc(docId);
  const snap = await ref.get();
  if (!snap.exists) { console.error(`Doc not found: ${docId}`); process.exit(1); }
  await ref.update({
    status:        'processed',
    processLock:   '',
    processedDate: new Date().toISOString().replace('T',' ').slice(0,19),
  });
  console.log(`\n✓ ${docId} restored to status='processed'. No money moved — the original refund's allocations stay reversed.\n`);
}

(async () => {
  const [cmd, arg] = process.argv.slice(2);
  if (cmd === 'list')         await listRefunds(arg || 'SCH_D94FE8F7AD');
  else if (cmd === 'fresh')   await setState(arg, 'fresh');
  else if (cmd === 'stale')   await setState(arg, 'stale');
  else if (cmd === 'verify')  await verify(arg);
  else if (cmd === 'restore') await restore(arg);
  else if (cmd === 'unlock') {
    const [,, , stuArg, schoolArg] = process.argv;
    await unlock(stuArg, schoolArg);
  }
  else {
    console.log('Usage:');
    console.log('  node scripts/test_r7_unstick.js list    [schoolId]');
    console.log('  node scripts/test_r7_unstick.js fresh   <docId>');
    console.log('  node scripts/test_r7_unstick.js stale   <docId>');
    console.log('  node scripts/test_r7_unstick.js verify  <docId>');
    console.log('  node scripts/test_r7_unstick.js restore <docId>');
    console.log('  node scripts/test_r7_unstick.js unlock  <studentId> [schoolId]');
  }
})().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });
