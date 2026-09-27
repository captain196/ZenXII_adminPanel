/**
 * READ-ONLY financial truth model for one payment specimen.
 *
 * SECRET SAFETY: this script NEVER dumps a whole document from a collection
 * that can hold credentials. Fields are selected by name, and anything whose
 * key looks like a credential is reported only as present/absent/present(len=N)
 * — never as a value. An earlier ad-hoc probe dumped a whole feeSettings doc
 * and leaked a Razorpay TEST key; that is the defect this shape prevents.
 *
 *   node scripts/financial_truth_model_readonly.js --school=SCH_X --receipt=F1
 */
const admin = require('firebase-admin');
const path  = require('path');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};
const SCHOOL  = arg('school', 'SCH_B56BB9A401');
const RKEY    = arg('receipt', 'F1');
const SESSION = arg('session', '2026-27');

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SECRETY = /secret|api_key|apikey|password|token|credential/i;
const safe = (k, v) => {
  if (SECRETY.test(k)) {
    if (v === undefined || v === null || v === '') return 'absent';
    return `present(len=${String(v).length})`;
  }
  return v;
};
/** Print only the named fields. Never Object.keys() a credential-bearing doc. */
const show = (label, data, fields) => {
  console.log(`  ${label}`);
  if (!data) { console.log('    <MISSING>'); return; }
  for (const f of fields) {
    const v = data[f];
    const rendered = v === undefined ? '<unset>'
                   : v === ''        ? '"" (EMPTY)'
                   : JSON.stringify(safe(f, v));
    console.log(`    ${f.padEnd(22)} ${String(rendered).slice(0, 110)}`);
  }
};
const head = (t) => console.log('\n' + '─'.repeat(74) + '\n  ' + t + '\n' + '─'.repeat(74));

(async () => {
  console.log(`FINANCIAL TRUTH MODEL — ${SCHOOL} receipt ${RKEY} (READ ONLY)`);

  // ── RECEIPT ───────────────────────────────────────────────────────────
  const rcpt = (await db.collection('feeReceipts').doc(`${SCHOOL}_${RKEY}`).get()).data();
  head('RECEIPT (feeReceipts) — identity, money, audit metadata');
  show('identity', rcpt, ['receiptNo', 'receiptKey', 'schoolId', 'session', 'studentId', 'srNo', 'rollNo']);
  show('money', rcpt, ['amount', 'input_amount', 'inputAmount', 'allocated_amount', 'allocatedAmount',
                       'discount', 'fine', 'netAmount']);
  show('destination', rcpt, ['paymentMode', 'bankLedgerCode', 'bankLedgerName']);
  show('provenance', rcpt, ['gateway', 'orderId', 'paymentId', 'txnId', 'remarks', 'collectedBy']);
  show('time', rcpt, ['date', 'createdAt', 'updatedAt']);
  show('presentation', rcpt, ['feeMonths', 'allocatedMonths', 'feeBreakdown']);

  // ── ALLOCATIONS ───────────────────────────────────────────────────────
  const alloc = (await db.collection('feeReceiptAllocations')
    .doc(`${SCHOOL}_${SESSION}_${RKEY}`).get()).data();
  head('ALLOCATIONS (feeReceiptAllocations) — AUTHORITATIVE per-demand truth');
  show('totals', alloc, ['totalAmount', 'netReceived', 'discount', 'fine', 'receiptNo', 'receiptKey']);
  const rows = Array.isArray(alloc && alloc.allocations) ? alloc.allocations : [];
  console.log(`  rows: ${rows.length}`);
  let allocSum = 0;
  rows.forEach((r) => {
    allocSum += Number(r.allocated || 0);
    console.log(`    ${String(r.fee_head).padEnd(24)} alloc=${String(r.allocated).padEnd(8)} bal=${String(r.balance).padEnd(6)} status=${r.status}  ${r.period}`);
  });
  console.log(`  SUM(allocated) = ${allocSum}`);

  // ── DEMANDS ───────────────────────────────────────────────────────────
  head('DEMANDS (feeDemands) — AUTHORITATIVE balance owner');
  let demandPaid = 0;
  for (const r of rows) {
    const d = (await db.collection('feeDemands').doc(r.demand_id).get()).data();
    if (!d) { console.log(`    ${r.demand_id} <MISSING>`); continue; }
    demandPaid += Number(d.paidAmount || 0);
    const total = d.netAmount != null ? d.netAmount : d.amount;
    console.log(`    ${String(d.feeHead || r.fee_head).padEnd(24)} net=${String(total).padEnd(7)} paid=${String(d.paidAmount).padEnd(7)} bal=${String(d.balance).padEnd(6)} status=${d.status}`);
  }
  console.log(`  SUM(demand.paidAmount) = ${demandPaid}`);

  // ── ORDER ─────────────────────────────────────────────────────────────
  const oid = rcpt && rcpt.orderId;
  const ord = oid ? (await db.collection('feeOnlineOrders').doc(`${SCHOOL}_${oid}`).get()).data() : null;
  head('ORDER (feeOnlineOrders) — provenance + verification audit');
  show('order', ord, ['status', 'amount', 'gateway', 'gateway_order_id', 'gateway_payment_id',
                      'signature', 'created_at', 'paid_at', 'verified_at', 'receipt_no', 'receipt_key']);

  // ── PROCESSED PAYMENT ─────────────────────────────────────────────────
  const pid = rcpt && rcpt.paymentId;
  const pp = pid ? (await db.collection('processedPayments').doc(pid).get()).data() : null;
  head('PROCESSED PAYMENT (processedPayments) — the retry guard');
  show('marker', pp, ['status', 'paymentId', 'orderId', 'receiptNo', 'receiptKey',
                      'schoolId', 'session', 'studentId', 'completedAt']);

  // ── ACCOUNTING ────────────────────────────────────────────────────────
  const je = (await db.collection('accounting').doc(`${SCHOOL}_${SESSION}_JE_FEE_${RKEY}`).get()).data();
  head('ACCOUNTING (accounting) — the books');
  show('entry', je, ['entryId', 'date', 'voucher_no', 'voucher_type', 'source', 'source_ref',
                     'total_dr', 'total_cr', 'is_finalized', 'status', 'created_by', 'created_at']);
  const lines = Array.isArray(je && je.lines) ? je.lines : [];
  let dr = 0, cr = 0;
  lines.forEach((l) => {
    dr += Number(l.dr || 0); cr += Number(l.cr || 0);
    console.log(`    ${String(l.account_code).padEnd(6)} ${String(l.account_name).padEnd(24)} dr=${String(l.dr).padEnd(7)} cr=${l.cr}`);
  });

  // ── THE INVARIANTS ────────────────────────────────────────────────────
  head('CROSS-REPRESENTATION INVARIANTS');
  const paid = Number((ord && ord.amount) || 0);
  const brk  = Array.isArray(rcpt && rcpt.feeBreakdown) ? rcpt.feeBreakdown : [];
  const brkSum = brk.reduce((s, b) => s + Number(b.amount || 0), 0);
  const chk = (ok, label, detail) =>
    console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label.padEnd(46)} ${detail}`);

  chk(dr === cr, 'total debit = total credit', `${dr} vs ${cr}`);
  chk(dr === paid, 'accounting debit = payment amount', `${dr} vs ${paid}`);
  chk(allocSum === paid, 'sum(allocations) = payment amount', `${allocSum} vs ${paid}`);
  chk(demandPaid === paid, 'sum(demand.paidAmount) = payment amount', `${demandPaid} vs ${paid}`);
  chk(Number(rcpt && rcpt.allocatedAmount) === paid, 'receipt.allocatedAmount = payment amount',
      `${rcpt && rcpt.allocatedAmount} vs ${paid}`);
  chk(Math.abs(brkSum - paid) < 0.01, 'sum(receipt.feeBreakdown) = payment amount',
      `${brkSum} vs ${paid}${Math.abs(brkSum - paid) >= 0.01 ? '   <-- STORED BREAKDOWN IS STALE' : ''}`);

  head('NEVER-POPULATED FIELDS (audit gaps)');
  const gaps = [];
  [['receipt.srNo', rcpt && rcpt.srNo], ['receipt.rollNo', rcpt && rcpt.rollNo],
   ['receipt.bankLedgerCode', rcpt && rcpt.bankLedgerCode],
   ['receipt.bankLedgerName', rcpt && rcpt.bankLedgerName],
   ['order.verified_at', ord && ord.verified_at]].forEach(([k, v]) => {
    if (v === '' || v === undefined || v === null) gaps.push(k);
  });
  gaps.forEach((g) => console.log(`    EMPTY  ${g}`));
  if (!gaps.length) console.log('    none');
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
