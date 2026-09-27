// READ-ONLY census of every gateway config, so a hardening change cannot
// silently disable collection for a tenant that depends on today's behaviour.
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const snap = await db.collection('feeSettings').get();
  const rows = [];
  snap.forEach(d => {
    if (!d.id.endsWith('_gateway')) return;
    const c = d.data();
    rows.push({
      id: d.id,
      provider: c.provider === undefined ? '<<ABSENT>>' : c.provider,
      mode: c.mode === undefined ? '<<ABSENT>>' : c.mode,
      active: c.active === undefined ? '<<ABSENT>>' : c.active,
      hasKey: String(c.api_key || '') !== '',
      hasSecret: String(c.api_secret || '') !== '',
    });
  });
  const summary = {
    totalGatewayDocs: rows.length,
    activeAbsent: rows.filter(r => r.active === '<<ABSENT>>').length,
    activeFalse:  rows.filter(r => r.active === false).length,
    providerAbsentOrEmpty: rows.filter(r => r.provider === '<<ABSENT>>' || r.provider === '').length,
    providerValues: [...new Set(rows.map(r => String(r.provider)))],
    missingCreds: rows.filter(r => !r.hasKey || !r.hasSecret).length,
    rows,
  };
  console.log(JSON.stringify(summary, null, 2));
  process.exit(0);
})().catch(e => { console.error('PROBE FAILED:', e.message); process.exit(1); });
