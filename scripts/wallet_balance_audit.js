// One-time audit: enumerate live studentAdvanceBalances docs and total rupee liability.
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const cols = ['studentAdvanceBalances', 'feeAdvanceBalances'];
  for (const col of cols) {
    const snap = await db.collection(col).get();
    if (snap.empty) { console.log(`\n[${col}] (empty)`); continue; }
    let positive = 0, total = 0;
    const bySchool = {};
    snap.docs.forEach(d => {
      const x = d.data();
      const amt = Number(x.amount ?? x.balance ?? x.advanceBalance ?? 0);
      const school = x.schoolId || '(none)';
      if (amt > 0.005) {
        positive++; total += amt;
        bySchool[school] = (bySchool[school] || 0) + amt;
      }
    });
    console.log(`\n[${col}] ${snap.size} docs, ${positive} with positive balance, total = ₹${total.toFixed(2)}`);
    Object.entries(bySchool).forEach(([s,v]) => console.log(`  ${s}: ₹${v.toFixed(2)}`));
    // Sample up to 5 positive docs
    const sample = snap.docs.filter(d => Number(d.data().amount ?? d.data().balance ?? 0) > 0.005).slice(0, 5);
    if (sample.length) {
      console.log(`  sample docs:`);
      sample.forEach(d => {
        const x = d.data();
        console.log(`    ${d.id}: studentId=${x.studentId} schoolId=${x.schoolId} session=${x.session} amount=${x.amount ?? x.balance}`);
      });
    }
  }
})().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
