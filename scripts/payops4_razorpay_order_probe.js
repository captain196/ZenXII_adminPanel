/**
 * ══════════════════════════════════════════════════════════════════════════
 *  ASK RAZORPAY WHAT IT THINKS OF OUR ORDER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A device-side "Something went wrong" from Razorpay's own checkout is not
 * diagnosable from ZenX's logs: the order was created successfully, so
 * everything on our side looks healthy. The only authority on why checkout
 * refused to render is Razorpay.
 *
 * This reads (never writes):
 *   GET /v1/orders/{id}            — does the order exist, what state, amount
 *   GET /v1/orders/{id}/payments   — every attempt against it, with the REAL
 *                                    error_code / error_description / step
 *
 * That last one is what actually explains a failed attempt — the app only
 * surfaces the SDK's numeric code, and its logcat line never reached us.
 *
 * ── SECRET HANDLING ──────────────────────────────────────────────────────
 * Credentials are read from the emulator's gateway document programmatically
 * and used only as the HTTP Basic auth header. Nothing is printed but
 * presence/length, per DiagnosticSecretSafetyTest's rule.
 *
 * Emulator-only for the credential read; the Razorpay call is READ-ONLY and
 * hits the provider's real API in whichever mode those keys belong to.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 \
 *     node scripts/payops4_razorpay_order_probe.js [--order=order_xxx]
 */
const admin = require('firebase-admin');
const https = require('https');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
if (!['127.0.0.1', 'localhost', '::1'].includes(EMU.split(':')[0])) {
  console.error('REFUSING: FIRESTORE_EMULATOR_HOST must be loopback (credential source).');
  process.exit(1);
}

const SCHOOL  = process.env.ZX_SCHOOL  || 'SCH_EMUFIXTURE1';
const SESSION = process.env.ZX_SESSION || '2026-27';

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

function rzp(path, key, secret) {
  return new Promise((resolve) => {
    const req = https.request({
      host: 'api.razorpay.com', path, method: 'GET',
      headers: {
        Authorization: 'Basic ' + Buffer.from(`${key}:${secret}`).toString('base64'),
        Accept: 'application/json',
      },
      timeout: 20000,
    }, (res) => {
      let b = '';
      res.on('data', (c) => (b += c));
      res.on('end', () => {
        let j = null;
        try { j = JSON.parse(b); } catch (e) { /* keep raw */ }
        resolve({ code: res.statusCode, json: j, raw: b.slice(0, 400) });
      });
    });
    req.on('error', (e) => resolve({ code: 0, json: null, raw: 'TRANSPORT: ' + e.message }));
    req.on('timeout', () => { req.destroy(); resolve({ code: 0, json: null, raw: 'TIMEOUT' }); });
    req.end();
  });
}

(async () => {
  const gw = (await db.collection('feeSettings')
    .doc(`${SCHOOL}_${SESSION}_gateway`).get()).data() || {};
  const key = String(gw.api_key || '');
  const sec = String(gw.api_secret || '');
  if (!key || !sec) { console.error('gateway credentials absent'); process.exit(1); }
  console.log(`credentials: api_key present(len=${key.length})  api_secret present(len=${sec.length})`);
  console.log(`key is test mode: ${key.startsWith('rzp_test_') ? 'yes' : 'NO'}\n`);

  // Which order? The most recent one on the fixture unless told otherwise.
  let orderId = arg('order', '');
  if (!orderId) {
    const s = await db.collection('feeOnlineOrders').where('schoolId', '==', SCHOOL).get();
    const rows = s.docs.map((d) => d.data() || {})
      .filter((o) => o.gateway_order_id)
      .sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));
    orderId = rows.length ? rows[0].gateway_order_id : '';
  }
  if (!orderId) { console.error('no order id to probe'); process.exit(1); }
  console.log(`probing order: ${orderId}\n`);

  const o = await rzp(`/v1/orders/${encodeURIComponent(orderId)}`, key, sec);
  console.log(`GET /v1/orders/${orderId}  ->  HTTP ${o.code}`);
  if (o.json && !o.json.error) {
    console.log(`  status   : ${o.json.status}`);
    console.log(`  amount   : ${o.json.amount} paise  (due ${o.json.amount_due})`);
    console.log(`  currency : ${o.json.currency}`);
    console.log(`  attempts : ${o.json.attempts}`);
    console.log(`  receipt  : ${o.json.receipt || '(none)'}`);
  } else {
    console.log(`  ERROR: ${o.json ? JSON.stringify(o.json.error) : o.raw}`);
  }

  console.log('');
  const p = await rzp(`/v1/orders/${encodeURIComponent(orderId)}/payments`, key, sec);
  console.log(`GET /v1/orders/${orderId}/payments  ->  HTTP ${p.code}`);
  if (p.json && Array.isArray(p.json.items)) {
    if (!p.json.items.length) {
      console.log('  NO PAYMENT ATTEMPTS RECORDED — checkout never reached the bank.');
      console.log('  That points at the checkout session itself (methods/account), not the card.');
    }
    p.json.items.forEach((it) => {
      console.log(`  ${it.id}  status=${it.status}  method=${it.method}  amount=${it.amount}`);
      if (it.error_code) {
        console.log(`     error_code   : ${it.error_code}`);
        console.log(`     description  : ${it.error_description}`);
        console.log(`     source/step  : ${it.error_source} / ${it.error_step}`);
        console.log(`     reason       : ${it.error_reason}`);
      }
    });
  } else {
    console.log(`  ERROR: ${p.json ? JSON.stringify(p.json.error) : p.raw}`);
  }
  process.exit(0);
})();
