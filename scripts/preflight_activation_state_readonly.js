// READ ONLY. Confirms no activation step has been taken. No writes anywhere.
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const out = {};

  // 8/9. Has ANY tenant been migrated? (a paymentProviders row = activation)
  const pp = await db.collection('paymentProviders').get();
  out.paymentProviders_rows = pp.size;
  out.migrated_schools = [];
  pp.forEach(d => out.migrated_schools.push(d.id));

  // 10. Has any real payment ever completed, in ANY tenant?
  const orders = await db.collection('feeOnlineOrders').get();
  let paid = 0, totalOrders = 0;
  orders.forEach(d => { totalOrders++; if ((d.data().status || '') === 'paid') paid++; });
  out.feeOnlineOrders_total = totalOrders;
  out.feeOnlineOrders_paid  = paid;

  const receipts = await db.collection('feeReceipts').get();
  out.feeReceipts_total = receipts.size;

  // Gateway config: does the active tenant have a webhook secret yet? (G1)
  const gw = await db.collection('feeSettings').get();
  out.gateway_docs = [];
  gw.forEach(d => {
    if (!d.id.endsWith('_gateway')) return;
    const c = d.data();
    out.gateway_docs.push({
      id: d.id,
      provider: c.provider, mode: c.mode, active: c.active,
      // presence/length ONLY — never the value
      api_key: String(c.api_key || '') ? `present(len=${String(c.api_key).length})` : 'ABSENT',
      api_secret: String(c.api_secret || '') ? `present(len=${String(c.api_secret).length})` : 'ABSENT',
      webhook_secret: String(c.webhook_secret || '') ? `present(len=${String(c.webhook_secret).length})` : 'ABSENT',
    });
  });

  console.log(JSON.stringify(out, null, 2));
  process.exit(0);
})().catch(e => { console.error('PREFLIGHT FAILED:', e.message); process.exit(1); });
