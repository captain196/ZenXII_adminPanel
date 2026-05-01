// Seed the Razorpay gateway config for a school/session. Equivalent to
// navigating Admin Panel → Fees → Payment Gateway and clicking Save.
//
// Writes to: feeSettings/{schoolId}_{session}_gateway
//
// Usage:
//   node scripts/seed_razorpay_gateway.js \
//     --school SCH_D94FE8F7AD --session 2026-27 \
//     --key rzp_test_xxxxxxxxxxxxxxxx --secret xxxxxxxxxxxxxxxxxxxx \
//     [--mode test] [--webhook-secret ""]
//
// Safe to re-run (idempotent via firestoreSet merge).
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

function arg(flag, fallback = '') {
  const i = process.argv.indexOf(flag);
  return i >= 0 ? process.argv[i + 1] : fallback;
}

(async () => {
  const schoolId  = arg('--school');
  const session   = arg('--session');
  const apiKey    = arg('--key');
  const apiSecret = arg('--secret');
  const mode      = arg('--mode', 'test');
  const webhook   = arg('--webhook-secret', '');

  const missing = [];
  if (!schoolId)  missing.push('--school');
  if (!session)   missing.push('--session');
  if (!apiKey)    missing.push('--key');
  if (!apiSecret) missing.push('--secret');
  if (missing.length) {
    console.error('Missing required flags:', missing.join(', '));
    process.exit(1);
  }

  if (!apiKey.startsWith('rzp_test_') && !apiKey.startsWith('rzp_live_')) {
    console.error('WARNING: key does not look like a Razorpay key (expected rzp_test_* or rzp_live_*)');
  }
  if (mode === 'test' && apiKey.startsWith('rzp_live_')) {
    console.error('ERROR: mode=test but key is a live key. Aborting.');
    process.exit(1);
  }
  if (mode === 'live' && apiKey.startsWith('rzp_test_')) {
    console.error('ERROR: mode=live but key is a test key. Aborting.');
    process.exit(1);
  }

  const docId = `${schoolId}_${session}_gateway`;
  const payload = {
    schoolId,
    session,
    provider: 'razorpay',
    mode,
    api_key: apiKey,
    api_secret: apiSecret,
    webhook_secret: webhook,
    active: true,
    updated_at: new Date().toISOString(),
  };

  await db.collection('feeSettings').doc(docId).set(payload, { merge: true });
  console.log(`✓ Wrote feeSettings/${docId}`);
  console.log('  provider:', payload.provider);
  console.log('  mode:    ', payload.mode);
  console.log('  api_key: ', apiKey.substring(0, 12) + '…');
  console.log('  secret:  ', '•'.repeat(apiSecret.length - 4) + apiSecret.slice(-4));
  console.log('  active:  ', payload.active);
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
