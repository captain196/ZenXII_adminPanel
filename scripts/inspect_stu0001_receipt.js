// Inspect most-recent receipt + allocation for STU0001 to see why Annual
// Fee is missing from the printed receipt.
//
//   node scripts/inspect_stu0001_receipt.js

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL  = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';
const SID     = 'STU0001';

(async () => {
  const rcpts = await db.collection('feeReceipts')
    .where('schoolId', '==', SCHOOL)
    .where('studentId', '==', SID)
    .get();

  const docs = rcpts.docs.map(d => ({ id: d.id, data: d.data() || {} }))
    .sort((a,b) => String(b.data.createdAt||'').localeCompare(String(a.data.createdAt||'')));

  console.log(`\n${rcpts.size} receipts for ${SID}`);

  for (const { id, data } of docs) {
    console.log(`\n--- ${id}`);
    console.log(`  receiptNo=${data.receiptNo}  amount=${data.amount}  createdAt=${data.createdAt}`);
    console.log(`  feeMonths=${JSON.stringify(data.feeMonths)}`);
    console.log(`  breakdown=${JSON.stringify(data.breakdown)}`);

    const allocKey = `${SCHOOL}_${SESSION}_${data.receiptKey || id.replace(`${SCHOOL}_`, '')}`;
    const alloc = await db.collection('feeReceiptAllocations').doc(allocKey).get();
    if (!alloc.exists) {
      console.log(`  (no allocation doc at feeReceiptAllocations/${allocKey})`);
      continue;
    }
    const a = alloc.data() || {};
    console.log(`  alloc.count=${(a.allocations||[]).length}`);
    (a.allocations || []).forEach((row, i) => {
      console.log(`    [${i}] head=${row.fee_head}  period=${row.period}  amt=${row.allocated}  status=${row.status}`);
    });
  }

  // Counter state
  const seq = await db.collection('feeCounters').doc(`${SCHOOL}_receipt_seq`).get();
  console.log(`\nfeeCounters/${SCHOOL}_receipt_seq → value=${seq.exists ? seq.data().value : 'MISSING'}`);

  const claimSnap = await db.collection('feeCounters')
    .where('schoolId', '==', SCHOOL)
    .where('kind', '==', 'receipt_seq')
    .get();
  const claims = claimSnap.docs.filter(d => /claim_/.test(d.id));
  console.log(`Active receipt_seq claims: ${claims.length}`);
  claims.slice(0, 20).forEach(d => console.log(`  ${d.id} = ${d.data().value}`));

  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
