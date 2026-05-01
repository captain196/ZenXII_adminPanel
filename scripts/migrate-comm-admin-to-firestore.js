#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Communication Admin Modules → Firestore Backfill (Phase 6e)
 *
 *  Copies existing RTDB data for the admin-internal Communication
 *  sub-modules into their new Firestore collections so the controller
 *  reads (which are now Firestore-first) see historical data.
 *
 *  Source RTDB tree:
 *    Schools/{school}/Communication/
 *    ├── Templates/{TPLxxx}        →  messageTemplates/{schoolId}_{TPLxxx}
 *    ├── Triggers/{TRGxxx}         →  alertTriggers/{schoolId}_{TRGxxx}
 *    ├── Queue/{QUExxxxx}          →  messageQueue/{schoolId}_{QUExxxxx}
 *    └── Logs/{LOGxxxxx}           →  deliveryLogs/{schoolId}_{LOGxxxxx}
 *
 *  Doc id format matches Communication::_dwSet():
 *    {schoolId}_{entityId}
 *
 *  Each Firestore doc gets {schoolId, id} stamped on top of the
 *  original RTDB shape so the read helpers can reconstruct the
 *  legacy [id => data] map without scanning doc IDs.
 *
 *  IDEMPOTENT — uses set(merge:true). NON-DESTRUCTIVE — RTDB stays
 *  intact and continues to receive mirror writes from the admin panel.
 *
 *  Usage:
 *    node scripts/migrate-comm-admin-to-firestore.js                # dry-run
 *    node scripts/migrate-comm-admin-to-firestore.js --apply        # write
 *    node scripts/migrate-comm-admin-to-firestore.js --school Demo  # one school
 *    node scripts/migrate-comm-admin-to-firestore.js --apply --only templates
 * ═══════════════════════════════════════════════════════════════════
 */

const admin = require('firebase-admin');
const path = require('path');

const SERVICE_ACCOUNT_PATH = path.resolve(
  __dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
);
const DATABASE_URL = 'https://graderadmin-default-rtdb.firebaseio.com/';

const args = process.argv.slice(2);
const DRY_RUN = !args.includes('--apply');
const FILTER_SCHOOL = args.includes('--school')
  ? args[args.indexOf('--school') + 1]
  : null;
const ONLY = args.includes('--only')
  ? args[args.indexOf('--only') + 1]
  : null;

const serviceAccount = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({
  credential: admin.credential.cert(serviceAccount),
  databaseURL: DATABASE_URL,
});

const rtdb = admin.database();
const fs = admin.firestore();

// Module config: rtdb sub-key → firestore collection
const MODULES = {
  templates: { rtdb: 'Templates', col: 'messageTemplates' },
  triggers:  { rtdb: 'Triggers',  col: 'alertTriggers' },
  queue:     { rtdb: 'Queue',     col: 'messageQueue' },
  logs:      { rtdb: 'Logs',      col: 'deliveryLogs' },
};

// ── Styling ────────────────────────────────────────────────────────
const FIXED   = '\x1b[32m✔ COPIED  \x1b[0m';
const SKIPPED = '\x1b[90m⊘ SKIPPED \x1b[0m';
const ERROR   = '\x1b[31m✖ ERROR   \x1b[0m';
const DRY     = '\x1b[35m⬡ DRY-RUN \x1b[0m';
const BOLD    = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM     = (s) => `\x1b[2m${s}\x1b[0m`;
const CYAN    = (s) => `\x1b[36m${s}\x1b[0m`;

const stats = {
  schools: 0,
  templates: 0,
  triggers: 0,
  queue: 0,
  logs: 0,
  errors: 0,
};

function schoolIdFor(rtdbKey) {
  return rtdbKey;
}

async function listSchools() {
  if (FILTER_SCHOOL) return [FILTER_SCHOOL];
  const snap = await rtdb.ref('Schools').once('value');
  const out = [];
  snap.forEach((c) => out.push(c.key));
  return out;
}

async function migrateCollection(schoolKey, schoolId, modKey) {
  const cfg = MODULES[modKey];
  if (!cfg) return;
  if (ONLY && ONLY !== modKey) return;

  const root = `Schools/${schoolKey}/Communication/${cfg.rtdb}`;
  const snap = await rtdb.ref(root).once('value');
  if (!snap.exists()) return;

  const all = snap.val() || {};
  for (const [id, data] of Object.entries(all)) {
    // Skip counter pseudo-entries that lived under the same node
    if (id === 'Counter' || id === 'Counters') continue;
    if (!data || typeof data !== 'object') continue;

    const docId = `${schoolId}_${id}`;
    const doc = { schoolId, id, ...data };

    if (DRY_RUN) {
      console.log(`${DRY}  ${CYAN(schoolKey)} ${DIM(`${cfg.col}/`)}${docId}`);
      stats[modKey] += 1;
      continue;
    }

    try {
      await fs.collection(cfg.col).doc(docId).set(doc, { merge: true });
      stats[modKey] += 1;
    } catch (e) {
      console.error(`${ERROR}  ${cfg.col}/${docId}: ${e.message}`);
      stats.errors += 1;
    }
  }
  if (!DRY_RUN && stats[modKey] > 0) {
    console.log(`${FIXED}  ${CYAN(schoolKey)} ${cfg.col}: ${stats[modKey]} so far`);
  }
}

async function migrateOneSchool(schoolKey) {
  const schoolId = schoolIdFor(schoolKey);
  const baseSnap = await rtdb.ref(`Schools/${schoolKey}/Communication`).once('value');
  if (!baseSnap.exists()) {
    console.log(`${SKIPPED} ${CYAN(schoolKey)} ${DIM('(no Communication node)')}`);
    return;
  }
  stats.schools += 1;
  console.log(`\n${BOLD(schoolKey)}`);
  for (const modKey of Object.keys(MODULES)) {
    await migrateCollection(schoolKey, schoolId, modKey);
  }
}

async function main() {
  console.log(BOLD('━━━ Communication Admin Modules Backfill ━━━'));
  console.log(`Mode:    ${DRY_RUN ? 'DRY-RUN (no writes)' : 'APPLY'}`);
  console.log(`School:  ${FILTER_SCHOOL || '(all)'}`);
  console.log(`Module:  ${ONLY || '(all: templates, triggers, queue, logs)'}\n`);

  const schools = await listSchools();
  for (const school of schools) {
    await migrateOneSchool(school);
  }

  console.log('\n' + BOLD('━━━ Summary ━━━'));
  console.log(`Schools scanned:  ${stats.schools}`);
  console.log(`Templates:        ${stats.templates}`);
  console.log(`Triggers:         ${stats.triggers}`);
  console.log(`Queue items:      ${stats.queue}`);
  console.log(`Delivery logs:    ${stats.logs}`);
  if (stats.errors) console.log(`\x1b[31mErrors: ${stats.errors}\x1b[0m`);

  if (DRY_RUN) {
    console.log('\n' + DIM('Re-run with --apply to actually write to Firestore.'));
  }
  process.exit(stats.errors ? 1 : 0);
}

main().catch((e) => {
  console.error('Fatal:', e);
  process.exit(1);
});
