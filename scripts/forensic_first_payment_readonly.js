/**
 * READ-ONLY forensic dump of the first controlled payment.
 *
 * The validator reported two failures. Before either is called a defect,
 * the raw documents have to be read: a validator looking at the wrong
 * field produces exactly the same output as a real money bug. Prints no
 * credential and writes nothing.
 *
 *   node scripts/forensic_first_payment_readonly.js
 */
const admin = require('firebase-admin');
const path  = require('path');

const SCHOOL  = 'SCH_B56BB9A401';
const STUDENT = 'STU0012';
const PAYMENT = 'pay_TUGNuDaaxFKMo0';
const ORDER   = 'order_TUGE1YFX8qO59l';

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const head = (t) => console.log('\n' + '='.repeat(72) + '\n  ' + t + '\n' + '='.repeat(72));
const j = (o) => JSON.stringify(o, null, 2);

(async () => {
  head('DEMANDS — was the money actually applied?');
  const dem = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL).where('studentId', '==', STUDENT).get();
  dem.forEach((d) => {
    const x = d.data();
    console.log(`  ${d.id}`);
    console.log(`    status=${x.status} amount=${x.amount} net=${x.netAmount} paid=${x.paidAmount} bal=${(x.netAmount ?? x.amount) - (x.paidAmount || 0)}`);
  });

  head('RECEIPTS — full document, every field');
  const rec = await db.collection('feeReceipts').where('schoolId', '==', SCHOOL).get();
  rec.forEach((d) => { console.log(`  --- ${d.id}`); console.log(j(d.data())); });

  head('ALLOCATIONS — where the validator read 0');
  for (const col of ['feeAllocations', 'feeReceiptAllocations', 'feePaymentAllocations']) {
    try {
      const s = await db.collection(col).where('schoolId', '==', SCHOOL).get();
      console.log(`  ${col}: ${s.size} doc(s)`);
      s.forEach((d) => { console.log(`    --- ${d.id}`); console.log(j(d.data())); });
    } catch (e) { console.log(`  ${col}: ${e.message}`); }
  }

  head('PROCESSED-PAYMENT MARKER — the retry guard');
  for (const col of ['processedPayments', 'feeProcessedPayments', 'paymentProcessed', 'webhookEvents']) {
    try {
      const s = await db.collection(col).limit(20).get();
      console.log(`  ${col}: ${s.size} doc(s) total in collection`);
      s.forEach((d) => {
        const x = d.data();
        const hit = d.id.includes(PAYMENT) || JSON.stringify(x).includes(PAYMENT);
        console.log(`    ${hit ? '>>> MATCH' : '   '} ${d.id}  ${hit ? j(x) : ''}`);
      });
    } catch (e) { console.log(`  ${col}: ${e.message}`); }
  }

  head('ORDER — the paid one');
  const o = await db.collection('feeOnlineOrders').where('schoolId', '==', SCHOOL).get();
  o.forEach((d) => {
    const x = d.data();
    if (String(x.gatewayOrderId || x.gwOrderId || '').includes(ORDER) || d.id.includes(ORDER) || x.status === 'paid') {
      console.log(`  --- ${d.id}`); console.log(j(x));
    }
  });

  head('ACCOUNTING / JOURNAL');
  for (const col of ['accounting', 'accountingLedger']) {
    try {
      const s = await db.collection(col).where('schoolId', '==', SCHOOL)
        .orderBy('createdAt', 'desc').limit(5).get();
      console.log(`  ${col}: ${s.size} recent doc(s)`);
      s.forEach((d) => { console.log(`    --- ${d.id}`); console.log(j(d.data())); });
    } catch (e) { console.log(`  ${col}: ${e.message}`); }
  }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
