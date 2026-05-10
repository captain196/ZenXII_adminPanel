#!/usr/bin/env node
/**
 * Backfill fee_audit_logs (snake) → feeAuditLogs (camel).
 *
 * Phase 1 stabilization (2026-05-07). The Fee_audit_logger writer was
 * renamed from `fee_audit_logs` to `feeAuditLogs` to match the
 * Firestore rule already in place. This script mirrors every existing
 * snake doc into the camel collection so admin audit dashboards see
 * the full pre-rename history. The snake collection is left intact
 * for a 30-day grace window before retirement.
 *
 * USAGE:
 *   node scripts/backfill_fee_audit_logs_camel.js                       # dry-run, all schools
 *   node scripts/backfill_fee_audit_logs_camel.js --apply               # commit
 *   node scripts/backfill_fee_audit_logs_camel.js --school=SCH_X --apply
 *
 * Idempotent — uses auditId as the docId. Re-runs are safe and become
 * the post-deploy belt-and-suspenders pass for any race-window writes.
 */
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

const APPLY = process.argv.includes('--apply');
function arg(name, dflt) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  return eq ? eq.split('=').slice(1).join('=') : dflt;
}
const SCHOOL_FILTER = arg('school', '');
const BATCH_SIZE = 250;

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  fee_audit_logs (snake) → feeAuditLogs (camel) backfill');
  console.log('  scope: ' + (SCHOOL_FILTER ? `school=${SCHOOL_FILTER}` : 'ALL schools'));
  console.log('  state: ' + (APPLY ? 'APPLY (writes will commit)' : 'DRY-RUN (no writes)'));
  console.log('═══════════════════════════════════════════════════════════════');

  let q = fs.collection('fee_audit_logs');
  if (SCHOOL_FILTER) q = q.where('schoolId', '==', SCHOOL_FILTER);

  const snap = await q.get();
  console.log(`Source docs found: ${snap.size}`);

  let copied = 0, skipped = 0, errored = 0;
  let batch = fs.batch();
  let inBatch = 0;

  for (const doc of snap.docs) {
    const data = doc.data();
    const auditId = data.auditId || doc.id;
    if (!auditId) { errored++; continue; }

    const targetRef = fs.collection('feeAuditLogs').doc(auditId);

    if (!APPLY) {
      // Dry-run: count what would be written. Does NOT check existing camel docs.
      copied++;
      continue;
    }

    // Idempotent merge — if the camel doc already exists with same auditId,
    // merge:true is a no-op for unchanged fields.
    const merged = { ...data, migratedFromSnake: true, migratedAt: admin.firestore.FieldValue.serverTimestamp() };
    batch.set(targetRef, merged, { merge: true });
    inBatch++;
    copied++;

    if (inBatch >= BATCH_SIZE) {
      try {
        await batch.commit();
        process.stdout.write(`  committed ${copied}/${snap.size}\r`);
      } catch (e) {
        console.error(`\n  batch failed at ${copied}: ${e.message} — retrying in 2s`);
        await new Promise(r => setTimeout(r, 2000));
        try { await batch.commit(); } catch (e2) { errored += inBatch; copied -= inBatch; }
      }
      batch = fs.batch();
      inBatch = 0;
    }
  }

  if (APPLY && inBatch > 0) {
    try { await batch.commit(); } catch (e) { console.error(`final batch failed: ${e.message}`); errored += inBatch; }
  }

  console.log('');
  console.log('═══════════════════════════════════════════════════════════════');
  console.log(`  ${APPLY ? 'COPIED' : 'WOULD COPY'}: ${copied}`);
  console.log(`  SKIPPED: ${skipped}`);
  console.log(`  ERRORED: ${errored}`);
  console.log('═══════════════════════════════════════════════════════════════');
  process.exit(errored > 0 ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
