// M1A.8 idempotent no-op verification helper.
// Reads the controller-doc and canonical-doc, prints updateTime
// (Firestore server-managed — only changes on actual writes) plus
// the lock metadata fields that the no-op guard must preserve.
//
// USAGE:
//   1. Run BEFORE clicking Lock-with-same-date — capture baseline.
//   2. Click Lock with the same date in the admin UI.
//   3. Run AGAIN — compare.
//   No-op behavior: both updateTime values should be UNCHANGED.

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join('..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();
const SCHOOL = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';

(async () => {
  const ctrl = await db.collection('accountingConfig')
    .doc(`${SCHOOL}_period_lock`).get();
  const cano = await db.collection('accountingConfig')
    .doc(`${SCHOOL}_${SESSION}_periodLock`).get();

  console.log('━━━ controller doc ━━━');
  if (!ctrl.exists) {
    console.log('  (absent)');
  } else {
    console.log('  updateTime  :', ctrl.updateTime.toDate().toISOString());
    const d = ctrl.data();
    console.log('  locked_until:', d.locked_until || '∅');
    console.log('  locked_by   :', d.locked_by || '∅');
    console.log('  locked_at   :', d.locked_at || '∅');
    console.log('  close_reason:', d.close_reason || '∅');
    console.log('  prior_until :', d.prior_locked_until || '∅');
    console.log('  state       :', d.state || '∅');
  }

  console.log('\n━━━ canonical doc ━━━');
  if (!cano.exists) {
    console.log('  (absent)');
  } else {
    console.log('  updateTime  :', cano.updateTime.toDate().toISOString());
    const d = cano.data();
    console.log('  lockedUntil :', d.lockedUntil || '∅');
    console.log('  lockedBy    :', d.lockedBy || '∅');
    console.log('  lockedAt    :', d.lockedAt || '∅');
    console.log('  reason      :', d.reason || '∅');
    console.log('  session     :', d.session || '∅');
  }

  process.exit(0);
})().catch((e) => { console.error('FATAL:', e); process.exit(2); });
