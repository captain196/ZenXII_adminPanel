// migrate_fee_defaulters_doc_id.js
//
// One-shot migration: rewrites legacy `feeDefaulters` doc IDs from
//   {schoolId}_{studentId}              (legacy)
// to the canonical
//   {schoolId}_{session}_{studentId}    (matches Fee_dues_check::getDues)
//
// The reader (Fee_dues_check.php:135) was updated in Phase 1 to expect
// the canonical form, so legacy docs are now invisible — they sit in
// the collection as orphans until a payment regenerates them under the
// new ID. This script accelerates that cleanup by walking every doc
// and migrating in place.
//
// Detection: a doc is legacy when its ID does NOT start with
//   `{schoolId}_{session}_`   (computed from the doc's own
// `schoolId` + `session` body fields). New-format docs are skipped.
//
// Usage (from project root):
//   node scripts/migrate_fee_defaulters_doc_id.js          # dry-run report
//   node scripts/migrate_fee_defaulters_doc_id.js --apply  # actually move
//
// Safe to re-run — already-canonical docs are no-ops.

const admin = require('firebase-admin');
const path = require('path');

const APPLY = process.argv.includes('--apply');
const sa = require(path.join(
  __dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  console.log(`feeDefaulters migration — mode: ${APPLY ? 'APPLY' : 'DRY-RUN'}`);

  const snap = await db.collection('feeDefaulters').get();
  console.log(`Scanned ${snap.size} docs`);

  let migrated = 0;
  let alreadyCanonical = 0;
  let skipped = 0;
  const examples = [];

  for (const doc of snap.docs) {
    const id = doc.id;
    const data = doc.data();
    const schoolId = data.schoolId;
    const session = data.session;
    const studentId = data.studentId;

    if (!schoolId || !session || !studentId) {
      skipped++;
      if (examples.length < 5) examples.push({ id, reason: 'missing schoolId/session/studentId' });
      continue;
    }

    const canonical = `${schoolId}_${session}_${studentId}`;
    if (id === canonical) {
      alreadyCanonical++;
      continue;
    }

    if (examples.length < 5) {
      examples.push({ from: id, to: canonical, dues: data.totalDues });
    }

    if (APPLY) {
      // Write the new doc, then delete the old. Use `set` (overwrites
      // any prior canonical doc — which can only exist if a payment
      // regenerated it after the schema change; in that case the
      // payment-side write is fresher and we shouldn't clobber it).
      const newRef = db.collection('feeDefaulters').doc(canonical);
      const existing = await newRef.get();
      if (existing.exists) {
        // A canonical doc already exists for this student (post-Phase 1
        // payment) — keep the new one, just drop the legacy.
        await doc.ref.delete();
      } else {
        await newRef.set(data);
        await doc.ref.delete();
      }
    }
    migrated++;
  }

  console.log('--- Summary ---');
  console.log(`Already canonical : ${alreadyCanonical}`);
  console.log(`To migrate        : ${migrated}`);
  console.log(`Skipped (no body) : ${skipped}`);
  if (examples.length) {
    console.log('Examples:');
    examples.forEach(e => console.log('  ', JSON.stringify(e)));
  }
  console.log(APPLY
    ? `Done. ${migrated} doc(s) moved.`
    : `Dry-run only. Re-run with --apply to migrate ${migrated} doc(s).`
  );
  process.exit(0);
})().catch(err => {
  console.error('Migration failed:', err);
  process.exit(1);
});
