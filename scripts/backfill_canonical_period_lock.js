// Phase L1.1 — backfill canonical session-scoped period-lock docs from
// the legacy controller-global lock docs.
//
// Source:  accountingConfig/{schoolId}_period_lock         (snake_case, global)
// Target:  accountingConfig/{schoolId}_{session}_periodLock (camelCase, session-scoped)
//
// SAFETY:
//   - Reads source, computes target, writes target. Source is preserved.
//   - Idempotent: same source → same target. Re-running is a no-op when
//     content would be identical.
//   - Skips empty / malformed source docs (logs + continues).
//   - --dry-run shows what would change without writing.
//   - --apply writes the canonical docs.
//
// USAGE:
//   node scripts/backfill_canonical_period_lock.js              # dry run
//   node scripts/backfill_canonical_period_lock.js --apply      # write
//
// SCHOOL SCOPE:
//   - Iterates the `schools` collection. For each school, attempts to
//     read its controller-doc lock; writes the canonical doc when:
//     (a) controller doc exists, AND
//     (b) controller.locked_until is a valid YYYY-MM-DD date.
//   - Computes the canonical doc's session-id from the locked_until date
//     using India fiscal-year mapping (April-March academic session).
//
// CACHE INVALIDATION:
//   - The library's APCU cache (TTL 60s) is per-PHP-FPM-pool and lives
//     in process memory, not Firestore. We can't invalidate it from a
//     node script. Per the M1A.6 docblock, the next request after the
//     cache TTL expires (≤60s) will read the fresh canonical doc.
//   - For immediate effect on a running PHP server, also restart php-fpm
//     after this backfill (or wait 60s).

const admin = require('firebase-admin');
const path = require('path');
const APPLY = process.argv.includes('--apply');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

/**
 * India FY mapping: April–March academic session.
 *   2026-04-01 → "2026-27"
 *   2026-03-31 → "2025-26"
 */
function sessionForDate(yyyymmdd) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(yyyymmdd)) return null;
  const [y, m] = yyyymmdd.split('-').map(Number);
  if (m >= 4) {
    const next = (y + 1) % 100;
    return y + '-' + String(next).padStart(2, '0');
  }
  const start = y - 1;
  return start + '-' + String(y % 100).padStart(2, '0');
}

function fmtDoc(d, schoolId) {
  if (!d) return '(absent)';
  return `locked_until=${d.locked_until || '∅'} locked_by=${d.locked_by || '∅'}`;
}

(async () => {
  const startedAt = new Date().toISOString();
  console.log(`━━━ L1.1 canonical period-lock backfill (${APPLY ? 'APPLY' : 'DRY-RUN'}) ━━━`);
  console.log(`startedAt: ${startedAt}\n`);

  let scanned = 0;
  let skippedNoController = 0;
  let skippedEmptyLock = 0;
  let skippedMalformedDate = 0;
  let identicalNoop = 0;
  let writtenNew = 0;
  let writtenOverwrite = 0;
  let errors = 0;

  const summary = []; // for final report

  // ── 1. Enumerate every school by reading the schools collection ──
  const schoolsSnap = await db.collection('schools').get();
  console.log(`schools collection size: ${schoolsSnap.size}\n`);

  for (const schoolDoc of schoolsSnap.docs) {
    const schoolId = schoolDoc.id;
    scanned++;
    const lineHead = `[${schoolId}] `;

    // ── 2. Read the controller doc ──
    let controller;
    try {
      const ref = await db.collection('accountingConfig').doc(`${schoolId}_period_lock`).get();
      controller = ref.exists ? ref.data() : null;
    } catch (e) {
      console.log(`${lineHead}controller-doc read failed: ${e.message}`);
      errors++;
      continue;
    }

    if (!controller) {
      // No legacy lock — nothing to migrate. Common case at this dev
      // school (verified earlier — both docs absent).
      skippedNoController++;
      summary.push({ schoolId, action: 'skip_no_controller_doc' });
      continue;
    }

    const lockedUntil = String(controller.locked_until || '').trim();
    if (lockedUntil === '') {
      console.log(`${lineHead}controller-doc has empty locked_until → skip`);
      skippedEmptyLock++;
      summary.push({ schoolId, action: 'skip_empty_locked_until' });
      continue;
    }

    const session = sessionForDate(lockedUntil);
    if (!session) {
      console.log(`${lineHead}malformed locked_until=${lockedUntil} → skip + log`);
      skippedMalformedDate++;
      summary.push({ schoolId, action: 'skip_malformed', locked_until: lockedUntil });
      errors++;
      continue;
    }

    const targetDocId = `${schoolId}_${session}_periodLock`;

    // ── 3. Read existing canonical doc (if any) for delta detection ──
    let canonical;
    try {
      const ref = await db.collection('accountingConfig').doc(targetDocId).get();
      canonical = ref.exists ? ref.data() : null;
    } catch (e) {
      console.log(`${lineHead}canonical-doc read failed: ${e.message}`);
      errors++;
      continue;
    }

    // ── 4. Build the canonical payload ──
    const payload = {
      schoolId:          schoolId,
      session:           session,
      lockedUntil:       lockedUntil,
      lockedBy:          String(controller.locked_by || ''),
      lockedAt:          String(controller.locked_at || ''),
      reopenedBy:        '',
      reopenedAt:        '',
      reason:            String(controller.close_reason || ''),
      _backfilledFrom:   'controller_period_lock',
      _backfilledAt:     new Date().toISOString(),
    };

    // ── 5. Compare to existing canonical (idempotency) ──
    if (canonical) {
      const sameContent = ['lockedUntil','lockedBy','lockedAt','reason'].every(
        k => String(canonical[k] || '') === String(payload[k] || '')
      );
      if (sameContent) {
        console.log(`${lineHead}canonical doc already matches → no-op`);
        identicalNoop++;
        summary.push({ schoolId, session, targetDocId, action: 'noop_identical' });
        continue;
      }
      // Existing canonical has different content. Preserve forensic
      // breadcrumb so we can audit what changed.
      payload._priorContent = {
        lockedUntil: canonical.lockedUntil || '',
        lockedBy:    canonical.lockedBy    || '',
        reason:      canonical.reason      || '',
      };
      console.log(`${lineHead}canonical doc exists with DIFFERENT content → ${APPLY ? 'overwrite' : 'would-overwrite'}`);
      console.log(`  prior: ${JSON.stringify(payload._priorContent)}`);
      console.log(`  next:  lockedUntil=${payload.lockedUntil} lockedBy=${payload.lockedBy} reason=${payload.reason}`);
      writtenOverwrite++;
    } else {
      console.log(`${lineHead}controller-doc → canonical (${session}) ${APPLY ? '✍ writing' : '(would write)'}`);
      console.log(`  source: ${fmtDoc(controller, schoolId)}`);
      console.log(`  target: accountingConfig/${targetDocId}`);
      writtenNew++;
    }

    // ── 6. Apply (when --apply) ──
    if (APPLY) {
      try {
        await db.collection('accountingConfig').doc(targetDocId).set(payload, { merge: false });
        summary.push({ schoolId, session, targetDocId, action: canonical ? 'overwrote' : 'created' });
      } catch (e) {
        console.log(`${lineHead}WRITE FAILED: ${e.message}`);
        errors++;
        if (canonical) writtenOverwrite--; else writtenNew--;
      }
    } else {
      summary.push({ schoolId, session, targetDocId, action: canonical ? 'would-overwrite' : 'would-create' });
    }
  }

  console.log('\n━━━ SUMMARY ━━━');
  console.log(`mode:                   ${APPLY ? 'APPLY' : 'DRY-RUN'}`);
  console.log(`schools scanned:        ${scanned}`);
  console.log(`skipped (no controller-doc): ${skippedNoController}`);
  console.log(`skipped (empty locked_until): ${skippedEmptyLock}`);
  console.log(`skipped (malformed date): ${skippedMalformedDate}`);
  console.log(`identical no-op:        ${identicalNoop}`);
  console.log(`${APPLY ? 'created' : 'would-create'}:               ${writtenNew}`);
  console.log(`${APPLY ? 'overwrote' : 'would-overwrite'}:             ${writtenOverwrite}`);
  console.log(`errors:                 ${errors}`);

  console.log(APPLY
    ? '\n✓ APPLY complete. PHP server should pick up new state within 60 s (APCU cache TTL); restart php-fpm for immediate effect.'
    : '\n(dry-run) Re-run with --apply to write canonical docs.');

  process.exit(errors > 0 ? 1 : 0);
})().catch(e => { console.error('FATAL:', e); process.exit(2); });
