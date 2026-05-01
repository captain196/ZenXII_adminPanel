// reset_test_student_payments.js
//
// One-shot DESTRUCTIVE wipe of all payment-related state for a test
// student so you can re-run the payment flow from scratch.
//
// Wipes (per student):
//   • feeReceipts            — all paid receipts
//   • feeReceiptAllocations  — per-receipt allocation breakdowns
//   • feeOnlineOrders        — Razorpay order records
//   • feeReceiptIndex        — receipt-number reservation index
//   • feeIdempotency         — payment-id replay guards
//   • feeDefaulters/{...}    — defaulter snapshot (regenerates on next payment)
//   • studentAdvanceBalances/{...}.amount → 0
//   • students/{...}.monthFee → all flags 0
//
// Resets (per demand for the student):
//   • paid_amount → 0
//   • balance     → net_amount
//   • status      → 'unpaid'
//   • last_receipt → ''
//
// Resets (school-level):
//   • feeCounters/{schoolId}_receipt_seq → 0  (so next receipt = F1)
//   • Deletes all feeCounters/{schoolId}_receipt_seq_claim_* docs
//
// USAGE:
//   node scripts/reset_test_student_payments.js                          # DRY-RUN
//   node scripts/reset_test_student_payments.js --apply                  # default student STU0001
//   node scripts/reset_test_student_payments.js --apply --student STU0002
//
// The dry-run shows what WOULD be deleted/reset; nothing is touched.

const admin = require('firebase-admin');
const path = require('path');

const APPLY = process.argv.includes('--apply');
const STUDENT = (() => {
  const i = process.argv.indexOf('--student');
  return i >= 0 ? process.argv[i + 1] : 'STU0001';
})();
const SCHOOL_ID = 'SCH_D94FE8F7AD';

const sa = require(path.join(
  __dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

async function deleteByQuery(coll, predicates, label) {
  let q = db.collection(coll);
  for (const [field, op, val] of predicates) q = q.where(field, op, val);
  const snap = await q.get();
  console.log(`  ${coll}: ${snap.size} match  (${label})`);
  if (!APPLY) return snap.size;
  let deleted = 0;
  for (const doc of snap.docs) {
    await doc.ref.delete();
    deleted++;
  }
  return deleted;
}

(async () => {
  console.log(`\n${APPLY ? 'APPLY' : 'DRY-RUN'}  student=${STUDENT}  school=${SCHOOL_ID}\n`);

  // ── 1. Receipts ───────────────────────────────────────────────────
  console.log('--- 1. feeReceipts ---');
  await deleteByQuery('feeReceipts',
    [['schoolId', '==', SCHOOL_ID], ['studentId', '==', STUDENT]],
    'all receipts for student');

  // ── 2. Receipt allocations ───────────────────────────────────────
  console.log('\n--- 2. feeReceiptAllocations ---');
  await deleteByQuery('feeReceiptAllocations',
    [['schoolId', '==', SCHOOL_ID], ['studentId', '==', STUDENT]],
    'allocation rows');

  // ── 3. Online orders (Razorpay) ──────────────────────────────────
  console.log('\n--- 3. feeOnlineOrders ---');
  await deleteByQuery('feeOnlineOrders',
    [['schoolId', '==', SCHOOL_ID], ['student_id', '==', STUDENT]],
    'orders');

  // ── 4. Receipt index (numbering) ─────────────────────────────────
  console.log('\n--- 4. feeReceiptIndex ---');
  // Index docs are keyed `{schoolId}_{session}_{receiptNo}`. We don't
  // store studentId on them, so we can't query by student. Instead we
  // wipe ALL index docs for this school+session so the receipt
  // numbering restarts cleanly. Safe because receipts are gone too.
  const indexSnap = await db.collection('feeReceiptIndex')
    .where('schoolId', '==', SCHOOL_ID).get();
  console.log(`  feeReceiptIndex: ${indexSnap.size} match  (all index docs for school)`);
  if (APPLY) for (const d of indexSnap.docs) await d.ref.delete();

  // ── 5. Idempotency markers ───────────────────────────────────────
  console.log('\n--- 5. feeIdempotency ---');
  // Hash-keyed; can't filter by student. Wipe all for this school.
  const idempSnap = await db.collection('feeIdempotency')
    .where('schoolId', '==', SCHOOL_ID).get();
  console.log(`  feeIdempotency: ${idempSnap.size} match  (all markers for school)`);
  if (APPLY) for (const d of idempSnap.docs) await d.ref.delete();

  // ── 6. Defaulter doc ─────────────────────────────────────────────
  console.log('\n--- 6. feeDefaulters ---');
  // Doc-id `{schoolId}_{session}_{studentId}` — try every active session
  // by listing what's there.
  const defSnap = await db.collection('feeDefaulters')
    .where('schoolId', '==', SCHOOL_ID)
    .where('studentId', '==', STUDENT).get();
  console.log(`  feeDefaulters: ${defSnap.size} match`);
  if (APPLY) for (const d of defSnap.docs) await d.ref.delete();

  // ── 7. Wallet (advance balance) → 0 ──────────────────────────────
  console.log('\n--- 7. studentAdvanceBalances ---');
  const walletId = `${SCHOOL_ID}_${STUDENT}`;
  const wsnap = await db.collection('studentAdvanceBalances').doc(walletId).get();
  if (wsnap.exists) {
    const before = wsnap.data().amount || 0;
    console.log(`  ${walletId}: amount ${before} → 0`);
    if (APPLY) await wsnap.ref.update({ amount: 0, lastReceipt: '', updatedAt: new Date().toISOString() });
  } else {
    console.log(`  ${walletId}: no doc (skip)`);
  }

  // ── 8. Reset all demands for the student in the active session ──
  console.log('\n--- 8. feeDemands (reset paid amounts) ---');
  const demandsSnap = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL_ID)
    .where('studentId', '==', STUDENT).get();
  let toReset = 0;
  let alreadyClean = 0;
  for (const d of demandsSnap.docs) {
    const data = d.data();
    if ((data.paid_amount || 0) > 0 || (data.status && data.status !== 'unpaid')) {
      toReset++;
      if (APPLY) {
        await d.ref.update({
          paid_amount: 0,
          paidAmount: 0,
          balance: data.net_amount || data.netAmount || 0,
          status: 'unpaid',
          last_receipt: '',
          updatedAt: new Date().toISOString()
        });
      }
    } else {
      alreadyClean++;
    }
  }
  console.log(`  feeDemands: ${toReset} reset · ${alreadyClean} already clean`);

  // ── 9. monthFee flags on student doc → all 0 ────────────────────
  console.log('\n--- 9. students.monthFee ---');
  const stuId = `${SCHOOL_ID}_${STUDENT}`;
  const stuSnap = await db.collection('students').doc(stuId).get();
  if (stuSnap.exists) {
    const monthFee = stuSnap.data().monthFee || {};
    const cleared = {};
    for (const k of Object.keys(monthFee)) cleared[k] = 0;
    const dirty = Object.values(monthFee).filter(v => v !== 0).length;
    console.log(`  ${stuId}: ${dirty} flag(s) currently non-zero → clearing all`);
    if (APPLY && Object.keys(cleared).length) {
      await stuSnap.ref.update({ monthFee: cleared, updatedAt: new Date().toISOString() });
    }
  } else {
    console.log(`  ${stuId}: no doc (skip)`);
  }

  // ── 10. Reset receipt-counter for the school ────────────────────
  console.log('\n--- 10. feeCounters (receipt sequence) ---');
  const ctrId = `${SCHOOL_ID}_receipt_seq`;
  const ctrSnap = await db.collection('feeCounters').doc(ctrId).get();
  if (ctrSnap.exists) {
    const before = ctrSnap.data().value || 0;
    console.log(`  ${ctrId}: value ${before} → 0`);
    if (APPLY) await ctrSnap.ref.update({ value: 0, updatedAt: new Date().toISOString() });
  } else {
    console.log(`  ${ctrId}: no doc`);
  }
  // Delete all per-N claim docs so they don't block fresh allocation
  const claimsSnap = await db.collection('feeCounters')
    .where('schoolId', '==', SCHOOL_ID)
    .where('kind', '==', 'receipt_seq').get();
  let claimDeleted = 0;
  for (const d of claimsSnap.docs) {
    if (d.id === ctrId) continue; // skip the main counter doc
    if (APPLY) await d.ref.delete();
    claimDeleted++;
  }
  console.log(`  feeCounters claim docs: ${claimDeleted} ${APPLY ? 'deleted' : 'would delete'}`);

  console.log(`\n${APPLY ? '✓ DONE — student is ready for fresh payment tests.' : 'DRY-RUN complete. Re-run with --apply to actually wipe.'}\n`);
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
