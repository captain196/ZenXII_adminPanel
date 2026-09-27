/**
 * ══════════════════════════════════════════════════════════════════════════
 *  IS THE FIXTURE GATEWAY SAFE TO PAY THROUGH?
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Before a human taps "Pay" on a real device, exactly one question has to be
 * answered with certainty: can this configuration move REAL money?
 *
 * Razorpay's key prefix is the authority — `rzp_live_*` charges a real card,
 * `rzp_test_*` cannot. `mode` is a ZenX field and can drift from the key, so
 * it is reported but never trusted on its own (Fee_management refuses a
 * mismatch at webhook receive time for exactly this reason).
 *
 * ── SECRET HANDLING ──────────────────────────────────────────────────────
 * Reports BOOLEANS and LENGTHS only. No key, secret or prefix value is ever
 * printed — the same discipline as every other probe in this repo, enforced
 * by DiagnosticSecretSafetyTest.
 *
 * Emulator only: it refuses to look at the live project at all.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/payops4_gateway_mode_check.js
 */
const admin = require('firebase-admin');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
const host = EMU.replace(/^https?:\/\//, '').split(':')[0];
if (!EMU || !['127.0.0.1', 'localhost', '::1'].includes(host)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  process.exit(1);
}

const SCHOOL  = process.env.ZX_SCHOOL  || 'SCH_EMUFIXTURE1';
const SESSION = process.env.ZX_SESSION || '2026-27';

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

(async () => {
  const ref = db.collection('feeSettings').doc(`${SCHOOL}_${SESSION}_gateway`);
  const snap = await ref.get();
  if (!snap.exists) {
    console.log('NO GATEWAY DOCUMENT — nothing can be paid.');
    process.exit(1);
  }
  const d = snap.data() || {};
  const key = String(d.api_key || '');

  const isLive = key.startsWith('rzp_live_');
  const isTest = key.startsWith('rzp_test_');

  console.log(`provider        : ${d.provider || '(unset)'}`);
  console.log(`mode field      : ${d.mode || '(unset)'}`);
  console.log(`api_key         : ${key ? `present(len=${key.length})` : 'ABSENT'}`);
  console.log(`api_secret      : ${d.api_secret ? `present(len=${String(d.api_secret).length})` : 'ABSENT'}`);
  console.log(`webhook_secret  : ${d.webhook_secret ? `present(len=${String(d.webhook_secret).length})` : 'ABSENT'}`);
  console.log(`schoolId on doc : ${d.schoolId || '(unset)'}`);
  console.log('');
  console.log(`TEST KEY        : ${isTest ? 'yes' : 'no'}`);
  console.log(`LIVE KEY        : ${isLive ? 'YES — A PAYMENT WOULD CHARGE A REAL CARD' : 'no'}`);
  console.log(`KEY/MODE AGREE  : ${(isLive && d.mode === 'live') || (isTest && d.mode === 'test') ? 'yes' : 'NO — Fee_management refuses webhooks on a mismatch'}`);
  console.log('');
  console.log(isTest && d.mode === 'test'
    ? 'SAFE TO PAY: test credentials, no real money can move.'
    : 'DO NOT PAY until this is resolved.');

  // The copy script carries the SOURCE tenant's schoolId across. Left
  // uncorrected it makes the fixture's own tenant-isolation checks report a
  // document belonging to a live school.
  if (d.schoolId !== SCHOOL) {
    await ref.set({ schoolId: SCHOOL, session: SESSION }, { merge: true });
    console.log(`\nschoolId relabelled ${d.schoolId} -> ${SCHOOL} (emulator doc only)`);
  }
  process.exit(isTest && d.mode === 'test' ? 0 : 1);
})();
