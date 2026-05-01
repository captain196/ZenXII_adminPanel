// Mop-up: delete remaining feeRefunds + feeRefundVouchers for STU0001.
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  for (const col of ['feeRefunds', 'feeRefundVouchers']) {
    const snap = await db.collection(col).where('studentId', '==', 'STU0001').get();
    console.log(`\n[${col}] ${snap.size} doc(s):`);
    snap.docs.forEach(d => console.log(`   · ${d.id}  amount=${d.data().amount ?? d.data().refundAmount ?? '?'}  status=${d.data().status ?? '?'}`));
    if (!snap.empty) {
      const batch = db.batch();
      snap.docs.forEach(d => batch.delete(d.ref));
      await batch.commit();
      console.log(`   ✓ deleted ${snap.size} doc(s)`);
    }
  }
})().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
