/**
 * ══════════════════════════════════════════════════════════════════════════
 *  COPY THE GATEWAY CONFIG FROM LIVE INTO THE EMULATOR FIXTURE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A real Razorpay TEST payment needs real test credentials. Rather than write
 * the payment into the operator's live tenant to get them, this copies the
 * gateway document onto the emulator fixture so the whole journey — order
 * creation, checkout, webhook, receipt, accounting — runs against emulator
 * data and touches nothing real.
 *
 * ── SECRET HANDLING ──────────────────────────────────────────────────────
 * Values are moved programmatically and NEVER printed. The report states only
 * presence and length, which is the same discipline every other probe in this
 * repo follows. Nothing is written back to the live project: the live read is
 * strictly read-only, and the only write target is the emulator.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 \
 *     node scripts/copy_gateway_to_emulator.js
 *
 * Refuses to run without a loopback emulator, because without it the "write"
 * side would be the live project.
 */
const admin = require('firebase-admin');
const path  = require('path');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
const host = EMU.replace(/^https?:\/\//, '').split(':')[0];
if (!EMU || !['127.0.0.1', 'localhost', '::1'].includes(host)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  console.error('Without it the destination would be the live project.');
  process.exit(1);
}

const SRC_SCHOOL = process.env.ZX_SRC_SCHOOL || 'SCH_B56BB9A401';
const DST_SCHOOL = process.env.ZX_DST_SCHOOL || 'SCH_EMUFIXTURE1';
const SESSION    = process.env.ZX_SESSION    || '2026-27';

const SECRETISH = /secret|password|token|key/i;

(async () => {
  // ── LIVE: read-only, its own app instance with real credentials ───────
  const sa = require(path.join(process.cwd(), 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
  const liveApp = admin.initializeApp(
    { credential: admin.credential.cert(sa) }, 'live');
  // The admin SDK routes to the emulator when FIRESTORE_EMULATOR_HOST is set,
  // so the LIVE handle must be built with that variable cleared.
  const saved = process.env.FIRESTORE_EMULATOR_HOST;
  delete process.env.FIRESTORE_EMULATOR_HOST;
  const liveApp2 = admin.initializeApp(
    { credential: admin.credential.cert(sa) }, 'live2');
  const liveDb = liveApp2.firestore();

  const srcId = `${SRC_SCHOOL}_${SESSION}_gateway`;
  const src = (await liveDb.collection('feeSettings').doc(srcId).get()).data();
  process.env.FIRESTORE_EMULATOR_HOST = saved;

  if (!src) { console.error(`live gateway doc ${srcId} not found`); process.exit(1); }

  console.log(`COPYING GATEWAY CONFIG  ${SRC_SCHOOL} -> ${DST_SCHOOL} (emulator only)\n`);
  for (const k of Object.keys(src).sort()) {
    const v = src[k];
    if (SECRETISH.test(k)) {
      console.log(`  ${k.padEnd(26)} ${v ? `present(len=${String(v).length})` : 'absent'}`);
    } else {
      console.log(`  ${k.padEnd(26)} ${typeof v === 'object' ? JSON.stringify(v) : String(v).slice(0, 40)}`);
    }
  }

  // ── EMULATOR: the only write ─────────────────────────────────────────
  const emuApp = admin.initializeApp({ projectId: 'graderadmin' }, 'emu');
  const emuDb = emuApp.firestore();
  const dstId = `${DST_SCHOOL}_${SESSION}_gateway`;
  await emuDb.collection('feeSettings').doc(dstId)
    .set({ ...src, schoolId: DST_SCHOOL, session: SESSION }, { merge: true });

  console.log(`\n  written to emulator: feeSettings/${dstId}`);
  console.log('  live project was READ ONLY. No value was printed.');
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
