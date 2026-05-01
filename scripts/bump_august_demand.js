// Trigger a re-emit of the August Computer Fee demand doc so the parent
// app's observeFeeDemandsLive listener receives a fresh snapshot.
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const demandId = 'DEM_202608_COMPUTER_FEE';
  const ref = db.collection('feeDemands').doc(demandId);
  const snap = await ref.get();
  if (!snap.exists) { console.log('Demand not found'); return; }
  const d = snap.data();
  console.log('Before:', { balance: d.balance, paid_amount: d.paid_amount, discountAmount: d.discountAmount });

  // Write a touch field to emit a change event without altering money fields.
  await ref.update({
    _listenerBump: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  });

  const after = await ref.get();
  console.log('After:', { balance: after.data().balance, paid_amount: after.data().paid_amount, _listenerBump: after.data()._listenerBump });
  console.log('\nOpen the parent app — Firestore should push the updated snapshot now.');
})().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
