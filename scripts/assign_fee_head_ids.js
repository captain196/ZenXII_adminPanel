// Phase 2.5 Step 1 — Assign stable, opaque feeHeadId to every fee head in
// every feeStructures doc. Runs BEFORE the demand-ID migration so the
// new demand ID format (feeDemands/{studentId}_{month}_{feeHeadId}) has
// a stable target to reference.
//
// ID format: FH_ + 12-hex (≈ 48 bits of entropy; >10^14 heads before a
// 1-in-a-million collision). Assigned ONCE per head — re-runs are a
// no-op for heads that already have an ID.
//
//   node scripts/assign_fee_head_ids.js dry-run
//   node scripts/assign_fee_head_ids.js live

const admin = require('firebase-admin');
const crypto = require('crypto');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

function newFeeHeadId() {
  return 'FH_' + crypto.randomBytes(6).toString('hex').toUpperCase();
}

(async () => {
  const mode = (process.argv[2] || '').toLowerCase();
  if (!['dry-run','live'].includes(mode)) {
    console.log('Usage: node scripts/assign_fee_head_ids.js dry-run|live');
    process.exit(2);
  }

  const snap = await db.collection('feeStructures').get();
  console.log(`Scanning ${snap.size} feeStructures docs...\n`);

  let docsTouched = 0, headsAssigned = 0, headsAlreadyIded = 0;
  let batch = db.batch();
  let batchOps = 0;

  for (const doc of snap.docs) {
    const data = doc.data() || {};
    const heads = Array.isArray(data.feeHeads) ? data.feeHeads : [];
    if (heads.length === 0) continue;

    let changed = false;
    const patched = heads.map(h => {
      if (h && typeof h === 'object' && typeof h.feeHeadId === 'string' && h.feeHeadId.startsWith('FH_')) {
        headsAlreadyIded++;
        return h;
      }
      const id = newFeeHeadId();
      headsAssigned++;
      changed = true;
      console.log(`  ${doc.id}  ${h.name || '(unnamed)'} → ${id}`);
      return Object.assign({}, h, { feeHeadId: id });
    });

    if (changed) {
      docsTouched++;
      if (mode === 'live') {
        batch.set(doc.ref, {
          feeHeads:  patched,
          updatedAt: new Date().toISOString(),
        }, { merge: true });
        batchOps++;
        if (batchOps >= 400) {
          await batch.commit();
          batch = db.batch();
          batchOps = 0;
        }
      }
    }
  }

  if (mode === 'live' && batchOps > 0) await batch.commit();

  console.log(`\n──────────────────────────────────────────────`);
  console.log(`Mode:                ${mode.toUpperCase()}`);
  console.log(`Structures scanned:  ${snap.size}`);
  console.log(`Structures touched:  ${docsTouched}`);
  console.log(`Heads newly ID'd:    ${headsAssigned}`);
  console.log(`Heads already ID'd:  ${headsAlreadyIded}`);
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
