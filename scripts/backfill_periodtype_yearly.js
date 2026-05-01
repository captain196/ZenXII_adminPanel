// Backfill periodType='yearly' / isYearly=true on demand docs where the
// generator wrote frequency='yearly' but writeDemand's normaliser (bug:
// it only recognised 'annual') stamped them as periodType='monthly'.
//
// Safe to re-run. Only touches docs where the drift is detectable.
//
//   node scripts/backfill_periodtype_yearly.js

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL  = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';

const YEARLY_FREQS = new Set(['annual', 'yearly', 'one-time', 'onetime']);

(async () => {
  const snap = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL)
    .where('session',  '==', SESSION)
    .get();

  console.log(`Scanning ${snap.size} demand docs in ${SCHOOL} / ${SESSION}...`);

  let patched = 0, ok = 0, skipped = 0;
  let batch = db.batch();
  let batchCount = 0;

  for (const doc of snap.docs) {
    const x = doc.data() || {};
    const freq   = String(x.frequency || '').toLowerCase();
    const month  = String(x.month || '');
    const isYearlyTruth = YEARLY_FREQS.has(freq) || month === 'Yearly Fees';
    const currentPT     = String(x.periodType || x.period_type || '');

    if (!isYearlyTruth) { skipped++; continue; }
    if (currentPT === 'yearly' && x.isYearly === true) { ok++; continue; }

    console.log(`  patch ${doc.id}  freq=${freq} month=${month} pt=${currentPT} → yearly`);
    batch.set(doc.ref, {
      periodType:  'yearly',
      period_type: 'yearly',
      isYearly:    true,
      updatedAt:   new Date().toISOString(),
    }, { merge: true });
    patched++;
    batchCount++;

    if (batchCount >= 400) {
      await batch.commit();
      batch = db.batch();
      batchCount = 0;
    }
  }
  if (batchCount > 0) await batch.commit();

  console.log(`\nDone. patched=${patched}  already-ok=${ok}  monthly-unchanged=${skipped}`);
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
