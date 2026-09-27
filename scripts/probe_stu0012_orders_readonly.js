// ── READ-ONLY. No writes, no deletes, no batch. Inspection only. ──
// Why: a Razorpay checkout is failing the instant it opens, on a real order.
// The device's logcat is muted by the OEM, so the order rows Razorpay actually
// saw are the only first-hand evidence available.
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL  = 'SCH_B56BB9A401';
const STUDENT = 'STU0012';

(async () => {
  const out = {};

  const snap = await db.collection('feeOnlineOrders')
    .where('schoolId', '==', SCHOOL).get();

  const rows = [];
  snap.forEach(d => {
    const x = d.data();
    const sid = x.studentId || x.student_id || '';
    if (sid !== STUDENT) return;
    rows.push({
      id: d.id,
      status: x.status,
      amount: x.amount,
      currency: x.currency,
      gwOrderId: x.gateway_order_id || x.gatewayOrderId || null,
      gwPaymentId: x.gateway_payment_id || x.gatewayPaymentId || null,
      mode: x.mode || null,
      provider: x.provider || null,
      // key FINGERPRINT only — never print a credential
      keyPrefix: (x.api_key || x.apiKey || '').slice(0, 14) || null,
      createdAt: x.created_at || x.createdAt || null,
      months: x.fee_months || x.feeMonths || null,
    });
  });
  rows.sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)));
  out.orderCount = rows.length;
  out.orders = rows.slice(0, 8);

  // The live gateway config Razorpay is being addressed with.
  const sessions = ['2026-27', '2025-26'];
  out.gateway = {};
  for (const s of sessions) {
    const g = await db.collection('feeSettings').doc(`${SCHOOL}_${s}_gateway`).get();
    if (!g.exists) { out.gateway[s] = 'ABSENT'; continue; }
    const c = g.data();
    out.gateway[s] = {
      provider: c.provider,
      mode: c.mode,
      active: c.active,
      keyPrefix: String(c.api_key || '').slice(0, 14),
      keyLen: String(c.api_key || '').length,
      secretLen: String(c.api_secret || '').length,   // length only
      webhookSecretSet: String(c.webhook_secret || '') !== '',
    };
  }

  console.log(JSON.stringify(out, null, 2));
  process.exit(0);
})().catch(e => { console.error('PROBE FAILED:', e.message); process.exit(1); });
