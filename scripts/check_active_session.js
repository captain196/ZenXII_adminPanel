// Nuke all fee-related Firestore data for STU0001 so admin can
// re-generate demands and start a clean payment test flow.
//
//   node scripts/reset_stu0001_fees.js dry-run
//   node scripts/reset_stu0001_fees.js live
//   node scripts/reset_stu0001_fees.js live --reset-counter
//
// `--reset-counter` also clears the school-wide feeCounters/*receipt_seq*
// docs (pointer + claim_N) so the next payment starts back at F#1.
// Only safe when the school has no other real receipts — use in dev only.

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';
const STUDENT = 'STU0001';

// Each entry: [collection, filters, per-doc-log-fn]
const PLAN = [
  ['feeDemands', [['schoolId','==',SCHOOL],['session','==',SESSION],['studentId','==',STUDENT]],
    d => `${d.data().month || '?'}/${d.data().fee_head || d.data().feeHead || '?'}  bal=${d.data().balance ?? 0}`],
  ['feeReceipts', [['schoolId','==',SCHOOL],['studentId','==',STUDENT]],
    d => `receipt ${d.data().receiptNo || '?'}  amount=${d.data().amount ?? 0}  date=${d.data().date || d.data().createdAt || '?'}`],
  ['feeReceiptAllocations', [['schoolId','==',SCHOOL],['studentId','==',STUDENT]],
    d => `alloc ${d.data().receiptNo || d.data().receiptKey || '?'}`],
  ['feeReceiptIndex', [['schoolId','==',SCHOOL],['userId','==',STUDENT]],
    d => `index ${d.data().receiptNo || '?'}`],
  ['feeDefaulters', [['schoolId','==',SCHOOL],['studentId','==',STUDENT]],
    d => `defaulter totalDues=${d.data().totalDues ?? 0}`],
  ['studentAdvanceBalances', [['schoolId','==',SCHOOL],['studentId','==',STUDENT]],
    d => `advance doc (amount=${d.data().amount ?? 0})`],
  ['studentDiscounts', [['schoolId','==',SCHOOL],['studentId','==',STUDENT]],
    d => `discount ${d.data().reason || d.data().type || '?'}`],
  ['feeCarryForward', [['schoolId','==',SCHOOL],['studentId','==',STUDENT]],
    d => `carry-forward ${d.data().previousSession || '?'}`],
  ['feeReminderLog', [['schoolId','==',SCHOOL],['student_id','==',STUDENT]],
    d => `reminder ${d.data().month || '?'} via ${d.data().channel || '?'}`],
  ['feeOnlineOrders', [['schoolId','==',SCHOOL],['studentId','==',STUDENT]],
    d => `order ${d.id}  status=${d.data().status || '?'}`],
  ['feeOnlinePayments', [['schoolId','==',SCHOOL],['studentId','==',STUDENT]],
    d => `payment ${d.id}`],
  ['paymentIntents', [['schoolId','==',SCHOOL],['studentId','==',STUDENT]],
    d => `intent ${d.id}`],
];

async function main() {
  const mode = (process.argv[2] || '').toLowerCase();
  const resetCounter = process.argv.includes('--reset-counter');
  if (!['dry-run','live'].includes(mode)) {
    console.log('Usage: node scripts/reset_stu0001_fees.js dry-run|live [--reset-counter]');
    process.exit(2);
  }

  let totalDocs = 0;
  for (const [col, filters, fmt] of PLAN) {
    let q = db.collection(col);
    filters.forEach(f => { q = q.where(f[0], f[1], f[2]); });
    const snap = await q.get();
    console.log(`\n[${col}] ${snap.size} doc(s) match:`);
    snap.docs.forEach(d => console.log(`   · ${d.id.padEnd(50)}  ${fmt(d)}`));
    totalDocs += snap.size;

    if (mode === 'live' && !snap.empty) {
      const batch = db.batch();
      snap.docs.forEach(d => batch.delete(d.ref));
      await batch.commit();
      console.log(`   ✓ deleted ${snap.size} doc(s) from ${col}`);
    }
  }

  // Student monthFee reset
  const studentRef = db.collection('students').doc(`${SCHOOL}_${STUDENT}`);
  const ssnap = await studentRef.get();
  if (ssnap.exists) {
    const cur = ssnap.data().monthFee || {};
    console.log(`\n[students] ${SCHOOL}_${STUDENT} monthFee has ${Object.keys(cur).length} key(s)`);
    Object.entries(cur).forEach(([k,v]) => console.log(`   · ${k}=${v}`));
    if (mode === 'live' && Object.keys(cur).length > 0) {
      await studentRef.update({ monthFee: {}, updatedAt: new Date().toISOString() });
      console.log(`   ✓ cleared monthFee map`);
    }
  }

  // Receipt counter reset (school-wide, opt-in). Nukes both the pointer
  // doc and every claim_N doc so nextCounter() starts fresh at 1.
  if (resetCounter) {
    const ptrRef = db.collection('feeCounters').doc(`${SCHOOL}_receipt_seq`);
    const ptrSnap = await ptrRef.get();
    const claimSnap = await db.collection('feeCounters')
      .where('schoolId', '==', SCHOOL)
      .where('kind', '==', 'receipt_seq')
      .get();
    const claims = claimSnap.docs.filter(d => /claim_/.test(d.id));

    console.log(`\n[feeCounters] receipt_seq pointer: ${ptrSnap.exists ? `value=${ptrSnap.data().value}` : 'absent'}`);
    console.log(`[feeCounters] receipt_seq claims: ${claims.length}`);
    claims.forEach(d => console.log(`   · ${d.id} = ${d.data().value}`));

    if (mode === 'live' && (ptrSnap.exists || claims.length > 0)) {
      const batch = db.batch();
      if (ptrSnap.exists) batch.delete(ptrRef);
      claims.forEach(d => batch.delete(d.ref));
      await batch.commit();
      console.log(`   ✓ deleted receipt_seq pointer + ${claims.length} claim(s)`);
    }
  } else {
    console.log(`\n[feeCounters] skipped — pass --reset-counter to also clear receipt_seq`);
  }

  console.log(`\n${mode === 'dry-run' ? 'DRY RUN — nothing deleted.' : 'Done.'} Total docs across collections: ${totalDocs}`);
}

main().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
