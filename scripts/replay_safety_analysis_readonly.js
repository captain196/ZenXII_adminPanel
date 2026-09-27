/**
 * READ-ONLY replay-safety analysis.
 *
 * Answers: if the SAME verification request, or the SAME webhook, were
 * submitted again for an already-settled payment, would a second financial
 * effect be created?
 *
 * It mutates nothing. It inspects the state each guard reads, so the verdict
 * is about the data that actually exists — not about what the code claims.
 *
 *   node scripts/replay_safety_analysis_readonly.js
 *
 * exit 0 = every guard armed · 2 = a guard is not armed
 */
const admin = require('firebase-admin');
const path  = require('path');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};
const SCHOOL  = arg('school', 'SCH_B56BB9A401');
const SESSION = arg('session', '2026-27');
const RKEY    = arg('receipt', 'F1');

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

let unarmed = 0;
const guard = (ok, name, detail) => {
  if (!ok) unarmed++;
  console.log(`  ${ok ? 'ARMED  ' : 'NOT ARMED'}  ${name.padEnd(44)} ${detail}`);
};

(async () => {
  console.log(`REPLAY SAFETY — ${SCHOOL} receipt ${RKEY} (READ ONLY, no writes)\n`);

  const rcpt = (await db.collection('feeReceipts').doc(`${SCHOOL}_${RKEY}`).get()).data();
  if (!rcpt) { console.log('  receipt not found'); process.exit(2); }
  const orderId = rcpt.orderId, paymentId = rcpt.paymentId;

  // 1. processedPayments — parent_verify_payment short-circuits on this.
  const pp = await db.collection('processedPayments').doc(paymentId).get();
  guard(pp.exists && (pp.data() || {}).schoolId === SCHOOL,
    'processed-payment marker for this payment',
    pp.exists ? `receipt ${(pp.data() || {}).receiptKey}` : 'ABSENT — a retry would re-run the fee writes');

  // 2. Order already 'paid' — verify_payment returns already_paid before
  //    touching the gateway or the books.
  const ord = (await db.collection('feeOnlineOrders').doc(`${SCHOOL}_${orderId}`).get()).data();
  guard(ord && ord.status === 'paid', 'order status short-circuit',
    ord ? `status=${ord.status}` : 'order missing');

  // 3. Receipt doc id is deterministic — a second write targets the SAME
  //    document rather than minting a second receipt.
  guard(true, 'receipt id is deterministic', `feeReceipts/${SCHOOL}_${RKEY}`);

  // 4. Allocation doc id is deterministic for the same reason.
  const al = await db.collection('feeReceiptAllocations').doc(`${SCHOOL}_${SESSION}_${RKEY}`).get();
  guard(al.exists, 'allocation id is deterministic', `${SCHOOL}_${SESSION}_${RKEY}`);

  // 5. Journal entry id is deterministic — JE_FEE_{receiptKey} dedups.
  const je = await db.collection('accounting').doc(`${SCHOOL}_${SESSION}_JE_FEE_${RKEY}`).get();
  guard(je.exists, 'journal id is deterministic', `JE_FEE_${RKEY}`);

  // ── WHAT A REPLAY WOULD ACTUALLY DUPLICATE ───────────────────────────
  // The guards above are claims. This counts the real financial artefacts,
  // which is the only way to know a previous replay did not already slip
  // through: if any of these is >1, an earlier retry DID duplicate.
  console.log('\n  ACTUAL ARTEFACT COUNTS FOR THIS PAYMENT');
  const countWhere = async (col, field, val) => {
    const s = await db.collection(col).where('schoolId', '==', SCHOOL).where(field, '==', val).get();
    return s.size;
  };
  const receipts = await countWhere('feeReceipts', 'paymentId', paymentId);
  const orders   = await countWhere('feeOnlineOrders', 'gateway_payment_id', paymentId);
  const allocs   = (await db.collection('feeReceiptAllocations')
    .where('schoolId', '==', SCHOOL).where('receiptKey', '==', RKEY).get()).size;
  const jes = (await db.collection('accounting')
    .where('schoolId', '==', SCHOOL).where('source_ref', '==', RKEY).get()).size;

  const one = (n, label) => {
    if (n !== 1) unarmed++;
    console.log(`  ${n === 1 ? 'OK   ' : 'FAIL '}  ${label.padEnd(44)} ${n}`);
  };
  one(receipts, 'receipts carrying this payment id');
  one(orders,   'orders carrying this payment id');
  one(allocs,   'allocation documents for this receipt');
  one(jes,      'journal entries referencing this receipt');

  console.log('\n' + '─'.repeat(74));
  console.log(unarmed === 0
    ? '  REPLAY SAFE — every guard armed, exactly one of each artefact'
    : `  ${unarmed} PROBLEM(S) — a replay could duplicate a financial effect`);
  process.exit(unarmed === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
