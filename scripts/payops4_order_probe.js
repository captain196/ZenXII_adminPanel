/**
 * Read-only probe: what happened to the fixture student's online orders?
 *
 * Emulator only. Prints order state, provenance and any failure reason so a
 * failed device payment can be diagnosed without guessing. No secret is read
 * or printed.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/payops4_order_probe.js
 */
const admin = require('firebase-admin');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
if (!['127.0.0.1', 'localhost', '::1'].includes(EMU.split(':')[0])) {
  console.error('REFUSING: FIRESTORE_EMULATOR_HOST must be loopback');
  process.exit(1);
}
const SCHOOL  = process.env.ZX_SCHOOL  || 'SCH_EMUFIXTURE1';
const SESSION = process.env.ZX_SESSION || '2026-27';

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

(async () => {
  const s = await db.collection('feeOnlineOrders').where('schoolId', '==', SCHOOL).get();
  console.log(`feeOnlineOrders for ${SCHOOL}: ${s.size}\n`);
  s.docs
    .map((d) => ({ id: d.id, ...(d.data() || {}) }))
    .sort((a, b) => String(a.created_at || '').localeCompare(String(b.created_at || '')))
    .forEach((o) => {
      console.log(`  ${o.created_at || '(no ts)'}  ${o.docType || 'order'}`);
      console.log(`    gateway_order_id : ${o.gateway_order_id || '(none)'}`);
      console.log(`    gateway          : ${o.gateway || '(none)'}`);
      console.log(`    amount           : ${o.amount}`);
      console.log(`    months           : ${JSON.stringify(o.fee_months || [])}`);
      console.log(`    status           : ${o.status}`);
      if (o.gateway_payment_id) console.log(`    payment_id       : ${o.gateway_payment_id}`);
      if (o.verified_via)       console.log(`    verified_via     : ${o.verified_via}`);
      if (o.failure_reason)     console.log(`    failure_reason   : ${o.failure_reason}`);
      console.log('');
    });

  // Did any money actually move?
  const dem = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL).where('studentId', '==', process.env.ZX_STUDENT || 'STU0001').get();
  const rows = dem.docs.map((d) => d.data());
  const paid = rows.reduce((a, r) => a + Number(r.paidAmount || 0), 0);
  const rec = await db.collection('feeReceipts').where('schoolId', '==', SCHOOL).get();
  console.log(`demands: ${rows.length}  paidAmount total: ${paid}  receipts: ${rec.size}`);
  process.exit(0);
})();
