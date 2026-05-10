#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Curriculum — Phase 1 Migration
 *
 *  Converts each curriculum doc's embedded `topics[]` array into a
 *  true Firestore subcollection at `curriculum/{parentDocId}/topics/{topicId}`,
 *  while keeping the legacy array intact for backward compatibility.
 *
 *  Per parent doc, after migration:
 *    + N subcollection docs created (one per topic), each carrying a
 *      stable UUID `topicId`, canonical camelCase field names, and
 *      audit/version metadata.
 *    + Parent gets:
 *        topicsModel: 'subcollection'      ← canonical-source switch
 *        topicIds: [uuid1, uuid2, ...]     ← ordered list (source of truth)
 *        totalTopics, completedTopics, percentComplete
 *        phase1MigratedAt, phase1MigrationRev: 1
 *
 *  Legacy `topics[]` array is PRESERVED on the parent doc (not deleted).
 *  Phase-0 fields (version, updatedByUid, etc.) are untouched.
 *
 *  Idempotency:
 *    Re-runs detect `phase1MigrationRev >= 1` (or `topicsModel === 'subcollection'`)
 *    and SKIP the doc. Bump PHASE1_REV to revisit later.
 *
 *  Safety:
 *    Dry-run by default. Per-school filter via --school. Verbose mode.
 *    Uses commit batches per parent doc — all-or-nothing per curriculum.
 *
 *  Usage:
 *    node scripts/migrate_curriculum_phase1.js                # dry-run, all
 *    node scripts/migrate_curriculum_phase1.js --apply
 *    node scripts/migrate_curriculum_phase1.js --school SCH_X --verbose
 * ═══════════════════════════════════════════════════════════════════
 */

const path   = require('path');
const crypto = require('crypto');

let admin;
try {
  admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin'));
} catch (e) {
  admin = require('firebase-admin');
}

const SERVICE_ACCOUNT_PATH = path.resolve(
  __dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
);

function parseArg(name) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  if (eq) return eq.split('=').slice(1).join('=');
  const i = process.argv.indexOf(`--${name}`);
  if (i !== -1 && i + 1 < process.argv.length && !process.argv[i + 1].startsWith('--')) {
    return process.argv[i + 1];
  }
  return null;
}
const APPLY        = process.argv.includes('--apply');
const VERBOSE      = process.argv.includes('--verbose');
const DRY_RUN      = !APPLY;
const FILTER_SCHOOL = parseArg('school');

const PHASE1_REV = 1;

const sa = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

const BOLD = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM  = (s) => `\x1b[2m${s}\x1b[0m`;
const CYAN = (s) => `\x1b[36m${s}\x1b[0m`;
const YEL  = (s) => `\x1b[33m${s}\x1b[0m`;
const RED  = (s) => `\x1b[31m${s}\x1b[0m`;
const GRN  = (s) => `\x1b[32m${s}\x1b[0m`;
const HR   = '─'.repeat(72);

const stats = {
  scanned: 0, migrated: 0, skipped: 0, failed: 0, topicsCreated: 0,
};

function uuid() {
  // Standards-compliant RFC 4122 v4 UUID via crypto.randomUUID() (Node 14.17+).
  // Falls back to randomBytes if randomUUID isn't available.
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const b = crypto.randomBytes(16);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = b.toString('hex');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

async function migrateOne(parentDoc) {
  stats.scanned++;
  const data = parentDoc.data();
  const parentDocId = parentDoc.id;

  // Idempotency: skip if already migrated
  if (Number(data.phase1MigrationRev || 0) >= PHASE1_REV) {
    if (VERBOSE) console.log(DIM(`  skip (already migrated rev=${data.phase1MigrationRev}) — ${parentDocId}`));
    stats.skipped++;
    return;
  }
  if (data.topicsModel === 'subcollection') {
    if (VERBOSE) console.log(DIM(`  skip (already subcollection) — ${parentDocId}`));
    stats.skipped++;
    return;
  }

  const topics = Array.isArray(data.topics) ? data.topics : [];
  const now    = new Date().toISOString();
  const actor  = 'system_phase1_migration';

  // Build per-topic subcollection docs + ordered topicIds list.
  const topicIds  = [];
  const topicDocs = []; // [{topicId, fields}]
  let completedTopics = 0;

  topics.forEach((t, i) => {
    if (!t || typeof t !== 'object') return;
    const id = uuid();
    topicIds.push(id);

    const status = ['not_started','in_progress','completed'].includes(t.status) ? t.status : 'not_started';
    if (status === 'completed') completedTopics++;

    topicDocs.push({
      topicId: id,
      fields: {
        topicId:        id,
        parentDocId,
        schoolId:       data.schoolId || '',
        title:          String(t.title    || ''),
        chapter:        String(t.chapter  || ''),
        estPeriods:     Number(t.est_periods ?? t.estPeriods ?? 0) || 0,
        status,
        completedDate:  String(t.completed_date ?? t.completedDate ?? ''),
        sortOrder:      typeof t.sort_order === 'number' ? t.sort_order : i,
        createdAt:      now,
        createdByUid:   actor,
        createdByName:  'Phase 1 Migration',
        updatedAt:      now,
        updatedByUid:   actor,
        updatedByName:  'Phase 1 Migration',
        version:        1,
      }
    });
  });

  const totalTopics     = topicIds.length;
  const percentComplete = totalTopics > 0 ? Math.round((completedTopics / totalTopics) * 1000) / 10 : 0;

  // Parent updates (merge — DOES NOT delete legacy `topics` array)
  const parentUpdate = {
    topicsModel:        'subcollection',
    topicIds,
    totalTopics,
    completedTopics,
    percentComplete,
    phase1MigratedAt:   now,
    phase1MigrationRev: PHASE1_REV,
  };

  if (VERBOSE) {
    console.log(`  ${parentDocId}  →  ${totalTopics} topic(s), ${completedTopics} completed`);
  }

  if (DRY_RUN) {
    stats.migrated++;
    stats.topicsCreated += totalTopics;
    return;
  }

  // Apply via Firestore admin SDK transaction-ish batch.
  // Single batch: N topic creates + 1 parent merge. All-or-nothing per curriculum.
  const batch = fs.batch();
  topicDocs.forEach(({ topicId, fields }) => {
    const ref = fs.collection('curriculum').doc(parentDocId).collection('topics').doc(topicId);
    batch.set(ref, fields);
  });
  batch.set(fs.collection('curriculum').doc(parentDocId), parentUpdate, { merge: true });

  try {
    await batch.commit();
    stats.migrated++;
    stats.topicsCreated += totalTopics;
  } catch (e) {
    console.error(RED(`  failed ${parentDocId}:`), e.message);
    stats.failed++;
  }
}

async function main() {
  console.log(BOLD(CYAN('Curriculum — Phase 1 Migration (array → subcollection)')));
  console.log(HR);
  console.log(`  mode:   ${DRY_RUN ? YEL('DRY-RUN') : GRN('APPLY')}`);
  console.log(`  rev:    ${PHASE1_REV}`);
  console.log(`  school: ${FILTER_SCHOOL || '(all)'}`);
  console.log(HR);

  const col = fs.collection('curriculum');
  const q = FILTER_SCHOOL ? col.where('schoolId', '==', FILTER_SCHOOL) : col;
  const snap = await q.get();

  console.log(`  found:  ${snap.size} curriculum doc(s)`);
  console.log(HR);

  for (const d of snap.docs) {
    await migrateOne(d);
  }

  console.log(HR);
  console.log(BOLD('Summary'));
  console.log(`  scanned       : ${stats.scanned}`);
  console.log(`  migrated      : ${stats.migrated}`);
  console.log(`  skipped       : ${stats.skipped}`);
  console.log(`  failed        : ${stats.failed}`);
  console.log(`  topics created: ${stats.topicsCreated}`);
  console.log(HR);
  if (DRY_RUN) {
    console.log(YEL('  No writes performed. Re-run with --apply to commit.'));
  } else {
    console.log(GRN('  Done. Re-run dry-run to verify idempotency.'));
  }
}

main().catch(e => { console.error(RED('Fatal:'), e); process.exit(1); });
