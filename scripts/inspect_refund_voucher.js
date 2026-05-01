// One-off: read the two refund-voucher docs we created during testing
// and print their full field set so we can verify schoolId is present.
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const ids = [
    'SCH_D94FE8F7AD_2026-27_REFUND_69E60BA00D75C',
    'SCH_D94FE8F7AD_2026-27_REFUND_69E6356C6D47D',
  ];
  for (const id of ids) {
    const snap = await db.collection('feeRefundVouchers').doc(id).get();
    if (!snap.exists) {
      console.log(`[MISSING] ${id}`);
      continue;
    }
    console.log(`[FOUND] ${id}`);
    console.log(JSON.stringify(snap.data(), null, 2));
    console.log('---');
  }
})().then(() => process.exit(0));
