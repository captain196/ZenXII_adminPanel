// R.6 test helper — creates the stale-allocation setup so you can
// exercise the refuse + wallet-override flows from the admin UI.
//
// The scenario it builds:
//   1. Pick a processed receipt (default: F1). Its allocation points at
//      demand D.
//   2. Create a SYNTHETIC second allocation doc for a newer fake receipt
//      (default F999) pointing at the SAME demand D. status != 'reversed'.
//   3. Now when you create+approve+process a refund for F1 in admin,
//      the stale-allocation guard kicks in → you'll see the confirm
//      dialog offering the wallet-route override.
//
// Usage (from C:\xampp\htdocs\Grader\school):
//
//   node scripts/test_r6_stale.js list
//     → shows receipts + their allocated demands, so you can pick.
//
//   node scripts/test_r6_stale.js setup [targetReceiptNo] [fakeReceiptNo]
//     → clones the target receipt's allocation under a fake receipt key.
//       Defaults: target=1, fake=999.
//
//   node scripts/test_r6_stale.js teardown [fakeReceiptNo]
//     → deletes the synthetic allocation doc so the test state is clean.

const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));

admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const DEFAULT_SCHOOL = 'SCH_D94FE8F7AD';

async function getSession(schoolId) {
  // Any existing allocation doc tells us the current session.
  const q = await db.collection('feeReceiptAllocations')
    .where('schoolId', '==', schoolId).limit(1).get();
  if (q.empty) throw new Error(`No allocation docs found for ${schoolId} — can't infer session.`);
  return q.docs[0].data().session;
}

async function list(schoolId) {
  schoolId = schoolId || DEFAULT_SCHOOL;
  const session = await getSession(schoolId);
  const q = await db.collection('feeReceiptAllocations')
    .where('schoolId', '==', schoolId)
    .where('session', '==', session).get();

  console.log(`\nAllocation docs for ${schoolId} session=${session}:\n`);
  console.log('receiptNo  status       studentId     demands (first 3)');
  console.log('-'.repeat(100));
  q.docs.forEach(d => {
    const x = d.data();
    const rn = String(x.receiptNo || x.receipt_no || '').padEnd(10);
    const st = String(x.status || 'active').padEnd(12);
    const sid = String(x.studentId || '').padEnd(13);
    const ds = (x.allocations || []).slice(0,3).map(a =>
      `${a.demand_id}/${a.period}`).join(', ');
    console.log(`${rn} ${st} ${sid} ${ds}`);
  });
  console.log(`\n${q.size} docs total.`);
}

async function setup(targetRcpt, fakeRcpt) {
  targetRcpt = targetRcpt || '1';
  fakeRcpt   = fakeRcpt   || '999';
  const schoolId = DEFAULT_SCHOOL;
  const session  = await getSession(schoolId);

  const targetKey = `F${targetRcpt}`;
  const targetDocId = `${schoolId}_${session}_${targetKey}`;
  const targetSnap = await db.collection('feeReceiptAllocations').doc(targetDocId).get();
  if (!targetSnap.exists) {
    console.error(`\n✗ Target allocation ${targetDocId} not found.`);
    console.error(`  Try:  node scripts/test_r6_stale.js list\n`);
    process.exit(1);
  }
  const t = targetSnap.data();
  if (t.status === 'reversed') {
    console.error(`\n✗ Target receipt F${targetRcpt} is already reversed — pick a different one.`);
    process.exit(1);
  }

  const fakeKey = `F${fakeRcpt}`;
  const fakeDocId = `${schoolId}_${session}_${fakeKey}`;
  const fakeDoc = {
    ...t,
    receiptKey:  fakeKey,
    receiptNo:   fakeRcpt,
    date:        new Date().toISOString().slice(0,10),
    createdBy:   'R6_TEST_HELPER',
    txnId:       `TEST_R6_${Date.now()}`,
    status:      '',           // explicitly active
    updatedAt:   new Date().toISOString(),
    _r6_synthetic: true,       // marker so teardown knows this is safe to delete
  };
  delete fakeDoc.reversedAt;
  delete fakeDoc.refundAuditId;

  await db.collection('feeReceiptAllocations').doc(fakeDocId).set(fakeDoc);

  const dids = (t.allocations || []).map(a => a.demand_id).join(', ');
  console.log(`\n✓ Synthetic allocation doc created:`);
  console.log(`    docId       = ${fakeDocId}`);
  console.log(`    receiptNo   = ${fakeRcpt}`);
  console.log(`    studentId   = ${t.studentId}`);
  console.log(`    demand_ids  = ${dids}`);
  console.log(`\n👉 Now in admin:`);
  console.log(`   1. Create a new refund against receipt #${targetRcpt} for any amount.`);
  console.log(`   2. Approve it, then click Process → cash.`);
  console.log(`   EXPECTED: a confirm() dialog saying`);
  console.log(`     "some of its demands have been re-paid by newer receipt(s): #${fakeRcpt}"`);
  console.log(`     with a "Proceed with wallet-route refund?" prompt.`);
  console.log(`   3. Click OK → toast: "Refund processed (routed to wallet due to stale allocations)."`);
  console.log(`   4. Verify student advanceBalance went up by the refund amount.`);
  console.log(`\nAfterwards, cleanup:`);
  console.log(`   node scripts/test_r6_stale.js teardown ${fakeRcpt}\n`);
}

async function teardown(fakeRcpt) {
  fakeRcpt = fakeRcpt || '999';
  const schoolId = DEFAULT_SCHOOL;
  const session  = await getSession(schoolId);
  const fakeDocId = `${schoolId}_${session}_F${fakeRcpt}`;
  const snap = await db.collection('feeReceiptAllocations').doc(fakeDocId).get();
  if (!snap.exists) {
    console.log(`\n✓ ${fakeDocId} already absent — nothing to delete.\n`);
    return;
  }
  if (!snap.data()._r6_synthetic) {
    console.error(`\n✗ ${fakeDocId} is NOT marked _r6_synthetic — refusing to delete a real allocation.\n`);
    process.exit(1);
  }
  await db.collection('feeReceiptAllocations').doc(fakeDocId).delete();
  console.log(`\n✓ Deleted synthetic allocation ${fakeDocId}.\n`);
}

(async () => {
  const [cmd, a, b] = process.argv.slice(2);
  if (cmd === 'list')          await list(a);
  else if (cmd === 'setup')    await setup(a, b);
  else if (cmd === 'teardown') await teardown(a);
  else {
    console.log('Usage:');
    console.log('  node scripts/test_r6_stale.js list     [schoolId]');
    console.log('  node scripts/test_r6_stale.js setup    [targetReceiptNo] [fakeReceiptNo]');
    console.log('  node scripts/test_r6_stale.js teardown [fakeReceiptNo]');
  }
})().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });
