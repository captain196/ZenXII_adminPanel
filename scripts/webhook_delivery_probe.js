/**
 * ══════════════════════════════════════════════════════════════════════════
 *  WEBHOOK DELIVERY PROBE — the client-interrupted reconciliation path
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The webhook is the ONLY path that reconciles a payment when the parent
 * never returns — app closed, signal lost, browser killed after paying. It
 * has never once run: `webhook_secret` was unset until 2026-08-28, and the
 * Razorpay adapter could not read a nested webhook body at all (PAY-OPS-3).
 *
 * This delivers a GENUINELY SIGNED webhook to the real endpoint, exactly as
 * Razorpay would:
 *
 *   • the real nested `payment.captured` envelope;
 *   • `X-Razorpay-Signature` = HMAC-SHA256(rawBody, webhook_secret), computed
 *     from the secret stored in the tenant's own gateway document;
 *   • the raw body posted byte-for-byte, because the signature covers bytes.
 *
 * ── WHAT THIS DOES AND DOES NOT PROVE ────────────────────────────────────
 * It proves everything on OUR side: signature verification, replay/dedupe,
 * payload parsing, allocation, receipt, accounting. It does NOT prove that
 * Razorpay's own delivery reaches the endpoint — only a dashboard-triggered
 * delivery can show that, and it is reported separately rather than implied.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────────
 * Refuses unless the target is loopback AND Firestore is an emulator, so this
 * cannot post a fabricated payment at a live tenant. The secret is read
 * programmatically and never printed.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/webhook_delivery_probe.js \
 *     --order=order_xxx --payment=pay_xxx [--repeat]
 */
const admin  = require('firebase-admin');
const crypto = require('crypto');
const http   = require('http');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
const emuHost = EMU.replace(/^https?:\/\//, '').split(':')[0];
if (!EMU || !['127.0.0.1', 'localhost', '::1'].includes(emuHost)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  process.exit(1);
}

const SCHOOL  = arg('school', 'SCH_EMUFIXTURE1');
const SESSION = arg('session', '2026-27');
const PORT    = parseInt(arg('port', '8099'), 10);
const ORDER   = arg('order', '');
const PAYMENT = arg('payment', '');
const REPEAT  = process.argv.includes('--repeat');

if (!ORDER || !PAYMENT) {
  console.error('--order=order_xxx and --payment=pay_xxx are required');
  process.exit(1);
}

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

function post(rawBody, signature, eventId) {
  return new Promise((resolve) => {
    const req = http.request({
      host: '127.0.0.1', port: PORT,
      path: `/fee_management/payment_webhook`,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(rawBody),
        'X-Razorpay-Signature': signature,
        'X-Razorpay-Event-Id': eventId,
        // The handler IP-allowlists Razorpay's ranges plus loopback; this
        // request genuinely originates from 127.0.0.1.
      },
      timeout: 60000,
    }, (res) => {
      let body = '';
      res.on('data', (c) => { body += c; });
      res.on('end', () => resolve({ status: res.statusCode, body: body.slice(0, 400) }));
    });
    req.on('error', (e) => resolve({ status: 0, body: e.message }));
    req.on('timeout', () => { req.destroy(); resolve({ status: 0, body: 'timeout' }); });
    req.write(rawBody);
    req.end();
  });
}

const snapshot = async () => {
  const r = await db.collection('feeReceipts').where('schoolId', '==', SCHOOL).get();
  const a = await db.collection('accounting').where('schoolId', '==', SCHOOL).get();
  const p = await db.collection('processedPayments').where('schoolId', '==', SCHOOL).get();
  let paid = 0;
  const d = await db.collection('feeDemands').where('schoolId', '==', SCHOOL).get();
  d.forEach((x) => { paid += Number((x.data() || {}).paidAmount || 0); });
  return { receipts: r.size, vouchers: a.size, processed: p.size, paidTotal: paid };
};

(async () => {
  console.log(`WEBHOOK DELIVERY PROBE — ${SCHOOL} -> 127.0.0.1:${PORT}\n`);

  const gw = (await db.collection('feeSettings')
    .doc(`${SCHOOL}_${SESSION}_gateway`).get()).data() || {};
  const secret = String(gw.webhook_secret || '');
  if (!secret) {
    // Wording matters here, not just intent: DiagnosticSecretSafetyTest scans
    // every output statement in this directory that mentions a secret and
    // requires it to be visibly in a presence/absence context. Saying ABSENT
    // is what makes "this line reports existence, never a value" checkable by
    // something other than a human reading it.
    console.error('webhook_secret: ABSENT on the gateway document — the endpoint will (correctly) reject.');
    process.exit(2);
  }
  console.log(`  webhook_secret: present(len=${secret.length})  provider=${gw.provider} mode=${gw.mode}`);

  const before = await snapshot();
  console.log(`  BEFORE  receipts=${before.receipts} vouchers=${before.vouchers} `
    + `processedPayments=${before.processed} paidTotal=${before.paidTotal}\n`);

  // The real Razorpay envelope — nested, which is what PAY-OPS-3 fixed.
  const envelope = {
    entity: 'event',
    account_id: 'acc_TEST',
    event: 'payment.captured',
    contains: ['payment'],
    payload: { payment: { entity: {
      id: PAYMENT, entity: 'payment', amount: 0, currency: 'INR',
      status: 'captured', order_id: ORDER, method: 'card',
    } } },
    created_at: Math.floor(Date.now() / 1000),
  };
  const rawBody = JSON.stringify(envelope);
  const signature = crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
  const eventId = 'evt_probe_' + crypto.randomBytes(6).toString('hex');

  console.log('  → delivering a correctly signed payment.captured …');
  const r1 = await post(rawBody, signature, eventId);
  console.log(`     HTTP ${r1.status}  ${r1.body.replace(/\s+/g, ' ').slice(0, 220)}`);

  const after = await snapshot();
  console.log(`\n  AFTER   receipts=${after.receipts} vouchers=${after.vouchers} `
    + `processedPayments=${after.processed} paidTotal=${after.paidTotal}`);

  // ── Replay: the same event id must not produce a second receipt ───────
  if (REPEAT) {
    console.log('\n  → REPLAY: delivering the identical event again …');
    const r2 = await post(rawBody, signature, eventId);
    console.log(`     HTTP ${r2.status}  ${r2.body.replace(/\s+/g, ' ').slice(0, 160)}`);
    const after2 = await snapshot();
    console.log(`     AFTER REPLAY receipts=${after2.receipts} vouchers=${after2.vouchers} `
      + `paidTotal=${after2.paidTotal}`);
    console.log(after2.receipts === after.receipts && after2.paidTotal === after.paidTotal
      ? '     ✓ idempotent — the replay produced no second receipt and moved no money'
      : '     ✗ REPLAY CREATED A DUPLICATE');
  }

  // ── A forged signature must be refused ───────────────────────────────
  console.log('\n  → FORGED signature (must be rejected) …');
  const bad = await post(rawBody, 'deadbeef'.repeat(8), 'evt_forged_' + Date.now());
  console.log(`     HTTP ${bad.status}  ${bad.body.replace(/\s+/g, ' ').slice(0, 160)}`);
  const after3 = await snapshot();
  console.log(after3.receipts === after.receipts
    ? '     ✓ forged webhook changed nothing'
    : '     ✗ A FORGED WEBHOOK WAS ACCEPTED');
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
