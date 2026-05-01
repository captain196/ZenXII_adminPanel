// One-shot: backfill legacy feeDemands docs that were written BEFORE
// Phase 2 (the canonical periodToMonth helper). The previous writer
// chopped "Yearly Fees 2026-27" -> "Yearly", so:
//
//   - month: "Yearly"  (should be "Yearly Fees")
//   - period_type / periodType / isYearly: missing entirely
//
// This patch:
//   - normalises month to "Yearly Fees" when the period clearly indicates
//     a yearly demand (period starts with "Yearly Fees" OR frequency=annual)
//   - stamps the Phase 9 fields period_type / periodType / isYearly
//
// All updates are merge=true so unrelated fields are untouched.
//
// USAGE:
//   node scripts/backfill_yearly_label.js               # DRY-RUN
//   node scripts/backfill_yearly_label.js --apply       # write
//
// Re-runnable. No-op when nothing matches.

const admin = require('firebase-admin');
const path = require('path');

const APPLY = process.argv.includes('--apply');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

// Mirrors Fee_firestore_txn::periodToMonth — strip ONLY the trailing
// year token, preserve multi-word labels like "Yearly Fees".
function periodToMonth(period) {
  if (!period) return '';
  return String(period).replace(/\s+\d{4}(-\d{2,4})?$/, '').trim();
}

function isYearlyDemand(d) {
  // Three signals — any one is enough.
  if (String(d.frequency || '').toLowerCase() === 'annual') return true;
  const m = String(d.month || '').trim();
  if (m === 'Yearly' || m === 'Yearly Fees') return true;
  const p = periodToMonth(String(d.period || ''));
  if (p === 'Yearly Fees') return true;
  return false;
}

(async () => {
  console.log(`feeDemands yearly backfill — mode: ${APPLY ? 'APPLY' : 'DRY-RUN'}\n`);

  const snap = await db.collection('feeDemands').get();
  console.log(`Scanning ${snap.size} demand doc(s)...`);

  let yearlyCount = 0;
  let monthFix = 0;
  let typeStamp = 0;
  let alreadyClean = 0;
  let updated = 0;
  let skipped = 0;

  for (const doc of snap.docs) {
    const d = doc.data() || {};
    if (!isYearlyDemand(d)) continue;
    yearlyCount++;

    const patch = {};
    let needs = false;

    if (d.month !== 'Yearly Fees') {
      patch.month = 'Yearly Fees';
      monthFix++;
      needs = true;
    }
    if (d.period_type !== 'yearly') { patch.period_type = 'yearly'; needs = true; }
    if (d.periodType  !== 'yearly') { patch.periodType  = 'yearly'; needs = true; }
    if (d.isYearly    !== true)     { patch.isYearly    = true;     needs = true; }
    if (needs) typeStamp++;

    if (!needs) { alreadyClean++; continue; }

    patch.updatedAt = new Date().toISOString();
    console.log(`  ${doc.id}: month='${d.month}' -> 'Yearly Fees', stamp period_type=yearly`);

    if (APPLY) {
      try {
        await doc.ref.set(patch, { merge: true });
        updated++;
      } catch (e) {
        console.error(`    FAILED: ${e.message}`);
        skipped++;
      }
    } else {
      updated++; // count as would-update
    }
  }

  console.log(`\n──────────────── SUMMARY ────────────────`);
  console.log(`  Yearly demands found  : ${yearlyCount}`);
  console.log(`  Already clean         : ${alreadyClean}`);
  console.log(`  Need month fix        : ${monthFix}`);
  console.log(`  Need stamp fix        : ${typeStamp}`);
  console.log(`  ${APPLY ? 'Updated' : 'Would update'}              : ${updated}`);
  if (skipped) console.log(`  Skipped (errors)      : ${skipped}`);
  console.log(`──────────────────────────────────────────`);
  console.log(APPLY
    ? '\n✓ DONE — re-run with same command to verify (should be no-op).'
    : '\nDRY-RUN complete. Re-run with --apply to write.');
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
