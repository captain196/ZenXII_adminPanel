// R.5 test helper — flips the chart-of-accounts state so you can
// trigger journal-post failures on demand, and clears the
// journalPosted flag on a refund so you can re-exercise the retry
// button without having to refund a fresh receipt each time.
//
// Usage (from C:\xampp\htdocs\Grader\school):
//
//   node scripts/test_r5_journal.js break [schoolId] [accountCode]
//     → marks the fee-income account (default 4010) as 'inactive'.
//       The next refund you Process will have its journal post FAIL.
//       Toast: "Refund processed. Journal post failed — click Retry..."
//
//   node scripts/test_r5_journal.js fix [schoolId] [accountCode]
//     → flips the same account back to 'active'. Run this right
//       after you see the red "Retry Journal" button appear, BEFORE
//       you click it (so the retry has a healthy CoA to post against).
//
//   node scripts/test_r5_journal.js unpost <refundDocId>
//     → sets journalPosted=false on the refund doc + clears
//       journalEntryId/journalPostedAt. Lets you re-click Retry
//       Journal on an already-posted refund to prove the idempotency
//       guard (should toast "Journal was already posted — nothing to
//       retry.") without actually needing a failure.
//
//   node scripts/test_r5_journal.js verify <refundDocId>
//     → prints the refund's journal-state fields and whether a
//       matching ledger entry exists in accountingLedger.
//
//   node scripts/test_r5_journal.js list [schoolId]
//     → shows refunds with journalPosted !== true, so you can find
//       any stranded ones.

const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));

admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const DEFAULT_SCHOOL = 'SCH_D94FE8F7AD';
const DEFAULT_CODE   = '4010';

async function setAccountStatus(schoolId, accountCode, status) {
  // accountingCoa doc IDs follow {schoolCode}_{code} per
  // Accounting_firestore_sync::getAccount.
  const docId = `${schoolId}_${accountCode}`;
  const ref = db.collection('accountingCoa').doc(docId);
  const snap = await ref.get();
  if (!snap.exists) {
    console.error(`\n✗ accountingCoa/${docId} not found.`);
    console.error(`  Check the schoolId — try:  node scripts/test_r5_journal.js list\n`);
    process.exit(1);
  }
  const prev = snap.data().status || '(unknown)';
  await ref.update({ status, updatedAt: new Date().toISOString() });
  console.log(`\n✓ ${docId}.status: ${prev} → ${status}`);
  console.log(`  name="${snap.data().name || accountCode}"`);
  if (status === 'inactive') {
    console.log(`\n👉 Now create+approve+process a refund in admin.`);
    console.log(`   EXPECTED: yellow/red toast "Refund processed. Journal post failed..."`);
    console.log(`   The refund row will show a red "Retry Journal" button.\n`);
    console.log(`   AFTER you see the warning, run:  node scripts/test_r5_journal.js fix`);
    console.log(`   Then click "Retry Journal" on the row.\n`);
  } else {
    console.log(`\n👉 Account healthy again. Click "Retry Journal" on the refund row.`);
    console.log(`   EXPECTED: green toast "Journal posted."\n`);
  }
}

async function unpost(refDocId) {
  const ref = db.collection('feeRefunds').doc(refDocId);
  const snap = await ref.get();
  if (!snap.exists) { console.error(`Refund not found: ${refDocId}`); process.exit(1); }
  await ref.update({
    journalPosted:    false,
    journalEntryId:   null,
    journalPostedAt:  '',
    journalLastError: 'unposted by test helper — retry to re-attach existing ledger',
  });
  console.log(`\n✓ ${refDocId} flagged journalPosted=false.`);
  console.log(`\n👉 Click "Retry Journal" on the row.`);
  console.log(`   If the ledger already has an entry for this refund, the idempotency`);
  console.log(`   guard will return it and the toast will read:`);
  console.log(`   "Journal was already posted — nothing to retry."\n`);
}

async function verify(refDocId) {
  const snap = await db.collection('feeRefunds').doc(refDocId).get();
  if (!snap.exists) { console.error(`Refund not found: ${refDocId}`); process.exit(1); }
  const x = snap.data();

  console.log(`\n── feeRefunds/${refDocId} ──`);
  console.log(`   status               = ${x.status}`);
  console.log(`   journalPosted        = ${x.journalPosted === undefined ? '(unset — pre-R.5)' : x.journalPosted}`);
  console.log(`   journalEntryId       = ${x.journalEntryId || '(none)'}`);
  console.log(`   journalPostedAt      = ${x.journalPostedAt || '(never)'}`);
  console.log(`   journalLastError     = ${x.journalLastError || '(none)'}`);
  console.log(`   journalRetryCount    = ${x.journalRetryCount || 0}`);

  const schoolId = x.schoolId;
  const refId = x.refundId || refDocId.split('_').slice(-1)[0];
  const session = x.session || '';

  // Match Accounting_firestore_sync::findJournalBySourceRef constraint set.
  const ledQ = await db.collection('accountingLedger')
    .where('schoolId',   '==', schoolId)
    .where('source',     '==', 'fee_refund')
    .where('source_ref', '==', refId)
    .get();

  console.log(`\n── accountingLedger (source=fee_refund, source_ref=${refId}) ──`);
  if (ledQ.empty) {
    console.log(`   ✗ no ledger entry found`);
  } else {
    ledQ.docs.forEach(d => {
      const e = d.data();
      const st = e.status || '(no status)';
      console.log(`   ✓ ${e.entryId}  voucher=${e.voucher_no}  date=${e.date}  status=${st}  Dr=${e.total_dr} Cr=${e.total_cr}`);
    });
  }

  const bothOk = (x.journalPosted === true) && !ledQ.empty;
  console.log(`\n  R.5 healthy: ${bothOk ? '✓ yes' : '✗ no'}\n`);
}

async function list(schoolId) {
  const q = await db.collection('feeRefunds')
    .where('schoolId', '==', schoolId)
    .where('status', '==', 'processed').get();
  const stranded = q.docs.filter(d => d.data().journalPosted === false);
  console.log(`\nProcessed refunds for schoolId=${schoolId}: ${q.size} total, ${stranded.length} with journalPosted=false\n`);
  if (!stranded.length) {
    console.log(`✓ All processed refunds have journalPosted=true (or are pre-R.5).`);
    return;
  }
  console.log('docId                                          amount  receiptNo  lastError');
  console.log('-'.repeat(110));
  stranded.forEach(d => {
    const x = d.data();
    console.log(`${d.id.padEnd(46)} ${String(x.amount || 0).padStart(6)}  ${String(x.receiptNo || x.receipt_no || '').padEnd(9)}  ${(x.journalLastError || '').slice(0,50)}`);
  });
}

(async () => {
  const [cmd, a, b] = process.argv.slice(2);
  if (cmd === 'break')       await setAccountStatus(a || DEFAULT_SCHOOL, b || DEFAULT_CODE, 'inactive');
  else if (cmd === 'fix')    await setAccountStatus(a || DEFAULT_SCHOOL, b || DEFAULT_CODE, 'active');
  else if (cmd === 'unpost') await unpost(a);
  else if (cmd === 'verify') await verify(a);
  else if (cmd === 'list')   await list(a || DEFAULT_SCHOOL);
  else {
    console.log('Usage:');
    console.log('  node scripts/test_r5_journal.js break  [schoolId] [accountCode]   # 4010 → inactive');
    console.log('  node scripts/test_r5_journal.js fix    [schoolId] [accountCode]   # back to active');
    console.log('  node scripts/test_r5_journal.js unpost <refundDocId>              # clear journalPosted flag');
    console.log('  node scripts/test_r5_journal.js verify <refundDocId>              # print journal state + ledger');
    console.log('  node scripts/test_r5_journal.js list   [schoolId]                 # show stranded refunds');
  }
})().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });
