// One-shot debug: list demands grouped by class+className to spot the
// "Unknown" bucket on the dashboard.
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const snap = await db.collection('feeDemands')
    .where('schoolId', '==', 'SCH_D94FE8F7AD')
    .where('session', '==', '2026-27')
    .get();

  console.log(`\nTotal demands: ${snap.size}\n`);
  console.log('class                | className            | studentId  | period              | net    | balance | feeHead');
  console.log('-'.repeat(130));
  for (const doc of snap.docs) {
    const d = doc.data();
    console.log(
      `${String(d.class || '<MISSING>').padEnd(20)} | ` +
      `${String(d.className || '<MISSING>').padEnd(20)} | ` +
      `${String(d.studentId || '').padEnd(10)} | ` +
      `${String(d.period || '').padEnd(20)} | ` +
      `${String(d.net_amount ?? d.netAmount ?? '').padStart(6)} | ` +
      `${String(d.balance ?? '').padStart(7)} | ` +
      `${d.feeHead || d.fee_head || ''}`
    );
  }
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
