// Scan every likely collection for any remaining STU0001 traces.
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const COLS = [
  ['feeReceipts', 'studentId'],
  ['feeReceiptAllocations', 'studentId'],
  ['feeReceiptIndex', 'userId'],
  ['feeDemands', 'studentId'],
  ['feeDefaulters', 'studentId'],
  ['studentAdvanceBalances', 'studentId'],
  ['studentDiscounts', 'studentId'],
  ['feeCarryForward', 'studentId'],
  ['feeReminderLog', 'student_id'],
  ['feeOnlineOrders', 'studentId'],
  ['feeOnlinePayments', 'studentId'],
  ['paymentIntents', 'studentId'],
  ['feeRefunds', 'studentId'],
  ['feeRefundVouchers', 'studentId'],
  ['accountingLedgerEntries', 'studentId'],
];

(async () => {
  for (const [col, field] of COLS) {
    try {
      const snap = await db.collection(col).where(field, '==', 'STU0001').get();
      console.log(`${col.padEnd(30)} ${field.padEnd(14)}  ${snap.size} doc(s)`);
      if (snap.size > 0) {
        snap.docs.slice(0, 3).forEach(d => console.log(`   · ${d.id}`));
      }
    } catch (e) {
      console.log(`${col.padEnd(30)} ${field.padEnd(14)}  ERROR: ${e.message.substring(0,80)}`);
    }
  }
})().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
