// Check for orphan locks, idempotency keys, and online orders after a
// verify-payment timeout. Also re-list latest receipts.
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';
const STUDENT = 'STU0001';

(async () => {
  const out = {};

  // Online orders
  const orders = await db.collection('feeOnlineOrders')
    .where('schoolId', '==', SCHOOL).where('studentId', '==', STUDENT).get();
  out.orders = [];
  orders.forEach(d => {
    const x = d.data();
    out.orders.push({
      id: d.id, status: x.status, amount: x.amount,
      gateway_order_id: x.gateway_order_id || x.gatewayOrderId,
      gateway_payment_id: x.gateway_payment_id || x.gatewayPaymentId,
      fee_months: x.fee_months || x.feeMonths,
      created_at: x.created_at || x.createdAt,
      paid_at: x.paid_at, verified_at: x.verified_at,
      failure_reason: x.failure_reason,
      docType: x.docType,
    });
  });

  // Locks
  const locks = await db.collection('feeLocks')
    .where('schoolId', '==', SCHOOL).get();
  out.locks = [];
  locks.forEach(d => {
    const x = d.data();
    out.locks.push({ id: d.id, locked: x.locked, locked_at: x.locked_at, source: x.source, token: (x.token||'').slice(0,8)+'…' });
  });

  // Idempotency keys (recent)
  const idemps = await db.collection('feeIdempotency')
    .where('schoolId', '==', SCHOOL).get();
  out.idempotency = [];
  idemps.forEach(d => {
    const x = d.data();
    out.idempotency.push({ id: d.id, status: x.status, receiptNo: x.receiptNo, startedAt: x.startedAt, completedAt: x.completedAt });
  });

  // Latest receipts
  const receipts = await db.collection('feeReceipts')
    .where('schoolId', '==', SCHOOL).where('studentId', '==', STUDENT).get();
  out.receipts_count = receipts.size;
  const rows = [];
  receipts.forEach(d => { const x=d.data(); rows.push({id:d.id,receiptNo:x.receiptNo,amount:x.amount,paymentMode:x.paymentMode,feeMonths:x.feeMonths,createdAt:x.createdAt}); });
  rows.sort((a,b)=>Number(b.receiptNo||0)-Number(a.receiptNo||0));
  out.receipts_latest = rows.slice(0,3);

  // Online orders index (RTDB-mirror used by Payment_service)
  // These live under Schools/{school}/{session}/Accounts/Fees/Online_Orders
  // Skipping RTDB here since admin has moved off RTDB; Payment_service may still write there.

  console.log(JSON.stringify(out, null, 2));
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
