// Period-lock dual-write drift detector. Run periodically during the
// L2 soak window to catch any controller-doc / canonical-doc divergence
// that L2's dual-write should keep in lock-step. A non-zero exit code
// means drift was detected.
//
// USAGE:
//   cd functions && node _check_period_lock_drift.js
//
// EXIT CODES:
//   0 = no drift
//   1 = drift detected (see stdout for which schools/sessions)
//   2 = fatal error

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join('..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

function sessionFor(d) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return '';
  const [y, m] = d.split('-').map(Number);
  if (m >= 4) return y + '-' + String((y + 1) % 100).padStart(2, '0');
  return (y - 1) + '-' + String(y % 100).padStart(2, '0');
}

(async () => {
  const snap = await db.collection('accountingConfig').get();
  const ctrl = [];
  const cano = [];
  snap.forEach((d) => {
    const id = d.id;
    if (id.endsWith('_period_lock')) ctrl.push({ id, data: d.data() });
    else if (id.endsWith('_periodLock')) cano.push({ id, data: d.data() });
  });

  console.log(`scanned: controller=${ctrl.length} canonical=${cano.length}`);
  let drifts = 0;

  // Direction 1: every controller lock has a matching canonical
  for (const c of ctrl) {
    const schoolId = c.id.replace(/_period_lock$/, '');
    const lu = c.data.locked_until || '';
    if (!lu) continue; // controller has no active lock
    const session = sessionFor(lu);
    if (!session) {
      console.log(`DRIFT: ${schoolId} controller has malformed locked_until=${lu}`);
      drifts++;
      continue;
    }
    const expected = `${schoolId}_${session}_periodLock`;
    const matched = cano.find((x) => x.id === expected);
    if (!matched) {
      console.log(`DRIFT: ${schoolId} controller=${lu} but canonical ${expected} MISSING`);
      drifts++;
    } else if ((matched.data.lockedUntil || '') !== lu) {
      console.log(`DRIFT: ${schoolId} controller=${lu} canonical=${matched.data.lockedUntil || '∅'}`);
      drifts++;
    } else {
      console.log(`OK: ${schoolId} aligned at ${lu} (canonical session=${session})`);
    }
  }

  // Direction 2: every canonical lock has a matching controller (or is a recently-reopened-empty doc)
  for (const k of cano) {
    const m = k.id.match(/^(.+)_(\d{4}-\d{2})_periodLock$/);
    if (!m) {
      console.log(`DRIFT: ${k.id} doesn't match expected canonical doc-id pattern`);
      drifts++;
      continue;
    }
    const schoolId = m[1];
    const session = m[2];
    const cu = k.data.lockedUntil || '';
    if (!cu) continue; // canonical has no active lock — fine if controller also has none
    const ctrlMatched = ctrl.find((x) => x.id === `${schoolId}_period_lock`);
    if (!ctrlMatched) {
      console.log(`DRIFT: ${schoolId} canonical(${session})=${cu} but controller MISSING`);
      drifts++;
    } else if ((ctrlMatched.data.locked_until || '') !== cu) {
      console.log(`DRIFT: ${schoolId} canonical(${session})=${cu} controller=${ctrlMatched.data.locked_until || '∅'}`);
      drifts++;
    }
    // If both match in direction 1, we already logged OK there; skip duplicate.
  }

  if (drifts === 0) {
    console.log('\n✓ NO DRIFT — all lock docs are dual-write coherent.');
    process.exit(0);
  } else {
    console.log(`\n✗ ${drifts} DRIFT(S) DETECTED — operator action required.`);
    process.exit(1);
  }
})().catch((e) => { console.error('FATAL:', e); process.exit(2); });
