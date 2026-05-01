// List all refund vouchers for a given student, sorted newest first.
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const studentId = process.argv[2] || 'STU0001';
  const snap = await db.collection('feeRefundVouchers')
    .where('studentId', '==', studentId).get();
  console.log(`Found ${snap.size} refund vouchers for ${studentId}`);
  snap.docs.forEach(d => {
    const x = d.data();
    console.log(`  ${d.id}`);
    console.log(`    origReceiptNo=${x.origReceiptNo}  amount=${x.amount}  feeTitle="${x.feeTitle}"  processedAt=${x.processedAt}  refundId=${x.refundId}`);
  });
})().then(() => process.exit(0));
