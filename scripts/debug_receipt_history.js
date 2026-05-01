// Debug: list all feeReceipts for STU0001. Parent app's
// getPaymentHistory queries exactly on (schoolId, studentId) so if
// this script finds zero, the parent UI will correctly show "No
// payment history found".
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';
const STU    = 'STU0001';

(async () => {
  console.log(`\nfeeReceipts for ${SCHOOL} / ${STU}\n` + '─'.repeat(78));

  // Exact query the parent app runs.
  const snap = await db.collection('feeReceipts')
    .where('schoolId', '==', SCHOOL)
    .where('studentId', '==', STU)
    .get();

  console.log(`  Filtered query: ${snap.size} receipt(s)\n`);
  if (snap.empty) {
    console.log('  → UI will correctly show "No payment history found".');
    console.log('  → This means no receipt exists yet for this student.');
  } else {
    console.log('  docId                              | receiptNo | amount   | date       | paymentMode');
    console.log('  ' + '-'.repeat(110));
    for (const doc of snap.docs) {
      const d = doc.data() || {};
      console.log('  ' +
        String(doc.id).padEnd(36) + ' | ' +
        String(d.receiptNo || '').padEnd(9) + ' | ' +
        String((d.amount ?? d.input_amount ?? 0).toString()).padEnd(8) + ' | ' +
        String(d.date || '').padEnd(10) + ' | ' +
        String(d.paymentMode || ''));
    }
  }

  // Also check if there are any receipts with the OTHER student ID
  // shape — maybe the user_id/studentId drifted. Show a sample.
  console.log('\n  Any receipts in this school (sample 5, unfiltered)');
  const all = await db.collection('feeReceipts')
    .where('schoolId', '==', SCHOOL)
    .limit(5).get();
  console.log(`  Total in school: ${all.size} shown`);
  for (const doc of all.docs) {
    const d = doc.data() || {};
    console.log('    ' + String(doc.id).padEnd(38) +
      ' studentId=' + String(d.studentId || '<MISSING>') +
      ' receiptNo=' + String(d.receiptNo || ''));
  }

  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
