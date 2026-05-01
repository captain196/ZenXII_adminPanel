// One-shot: legacy demands written before the writer dual-emit fix had
// only `className` (no `class`). The dashboard reads `class` and bucketed
// them into "Unknown". This backfill copies className -> class for any
// demand missing the class field.
//
// Re-runnable. No-op when nothing matches.
//
//   node scripts/backfill_demand_class_field.js          # DRY-RUN
//   node scripts/backfill_demand_class_field.js --apply  # write

const admin = require('firebase-admin');
const path = require('path');
const APPLY = process.argv.includes('--apply');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  console.log(`feeDemands class-field backfill — ${APPLY ? 'APPLY' : 'DRY-RUN'}\n`);

  const snap = await db.collection('feeDemands').get();
  let yearly = 0, missing = 0, fixed = 0, alreadyOk = 0;

  for (const doc of snap.docs) {
    const d = doc.data() || {};
    const hasClass     = !!(d.class && String(d.class).trim());
    const hasClassName = !!(d.className && String(d.className).trim());

    if (hasClass) { alreadyOk++; continue; }
    if (!hasClassName) { missing++; continue; }   // can't fix — no source

    const patch = {
      class:     d.className,
      updatedAt: new Date().toISOString(),
    };
    console.log(`  ${doc.id}  →  class='${d.className}'  (period=${d.period || '?'})`);

    if (APPLY) await doc.ref.set(patch, { merge: true });
    fixed++;
  }

  console.log(`\n──────────────── SUMMARY ────────────────`);
  console.log(`  Total demands           : ${snap.size}`);
  console.log(`  Already have 'class'    : ${alreadyOk}`);
  console.log(`  Missing class+className : ${missing}`);
  console.log(`  ${APPLY ? 'Fixed' : 'Would fix'}              : ${fixed}`);
  console.log(`──────────────────────────────────────────`);
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
