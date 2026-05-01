#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Messaging Inbox Case Migration (Phase 3)
 *
 *  Copies legacy capital-case inbox entries:
 *      Schools/{school}/Communication/Messages/Inbox/Admin/{uid}/{convId}
 *      Schools/{school}/Communication/Messages/Inbox/Teacher/{uid}/{convId}
 *      Schools/{school}/Communication/Messages/Inbox/HR/{uid}/{convId}
 *  → into the canonical lowercase paths:
 *      Schools/{school}/Communication/Messages/Inbox/admin/{uid}/{convId}
 *      Schools/{school}/Communication/Messages/Inbox/teacher/{uid}/{convId}
 *      Schools/{school}/Communication/Messages/Inbox/hr/{uid}/{convId}
 *
 *  IDEMPOTENT — safe to run multiple times. Lowercase entries are
 *  merged-on-top of legacy ones (lowercase wins on conflict because it
 *  is the new canonical write target).
 *
 *  NON-DESTRUCTIVE by default — capital-case nodes are KEPT after the
 *  copy. Pass `--prune` to delete them after a successful copy.
 *
 *  Usage:
 *    node scripts/migrate-inbox-case.js                  # dry-run
 *    node scripts/migrate-inbox-case.js --apply          # actually write
 *    node scripts/migrate-inbox-case.js --apply --prune  # write + delete legacy
 *    node scripts/migrate-inbox-case.js --school Demo    # one school only
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
const PRUNE = args.includes('--prune');
const FILTER_SCHOOL = args.includes('--school')
  ? args[args.indexOf('--school') + 1]
  : null;

const serviceAccount = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({
  credential: admin.credential.cert(serviceAccount),
  databaseURL: DATABASE_URL,
});

const rtdb = admin.database();

// ── Styling ────────────────────────────────────────────────────────
const FIXED   = '\x1b[32m✔ MIGRATED\x1b[0m';
const MERGED  = '\x1b[36m⊕ MERGED  \x1b[0m';
const SKIPPED = '\x1b[90m⊘ SKIPPED \x1b[0m';
const PRUNED  = '\x1b[33m✂ PRUNED  \x1b[0m';
const ERROR   = '\x1b[31m✖ ERROR   \x1b[0m';
const DRY     = '\x1b[35m⬡ DRY-RUN \x1b[0m';
const BOLD    = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM     = (s) => `\x1b[2m${s}\x1b[0m`;
const CYAN    = (s) => `\x1b[36m${s}\x1b[0m`;

const LEGACY_TO_CANONICAL = {
  Admin: 'admin',
  Teacher: 'teacher',
  HR: 'hr',
};

const stats = {
  schools: 0,
  legacyNodesScanned: 0,
  conversationsCopied: 0,
  conversationsMerged: 0,
  conversationsSkipped: 0,
  conversationsPruned: 0,
  errors: 0,
};

async function listSchools() {
  if (FILTER_SCHOOL) return [FILTER_SCHOOL];
  const snap = await rtdb.ref('Schools').once('value');
  const schools = [];
  snap.forEach((child) => {
    schools.push(child.key);
  });
  return schools;
}

/**
 * Merge `src` into `dst` non-destructively. Lowercase (canonical) keys
 * win when both sides set the same field. Returns the merged map.
 */
function mergeInbox(legacyEntry, canonicalEntry) {
  const merged = { ...(legacyEntry || {}) };
  for (const [k, v] of Object.entries(canonicalEntry || {})) {
    if (v !== null && v !== undefined && v !== '') merged[k] = v;
  }
  return merged;
}

async function migrateOneSchool(school) {
  const inboxRoot = `Schools/${school}/Communication/Messages/Inbox`;
  const inboxSnap = await rtdb.ref(inboxRoot).once('value');
  if (!inboxSnap.exists()) {
    console.log(`${SKIPPED} ${CYAN(school)} ${DIM('(no Communication/Messages/Inbox)')}`);
    return;
  }
  stats.schools += 1;

  for (const [legacyRole, canonicalRole] of Object.entries(LEGACY_TO_CANONICAL)) {
    const legacyNode = inboxSnap.child(legacyRole);
    if (!legacyNode.exists()) continue;
    stats.legacyNodesScanned += 1;

    // legacyNode.val() shape: { uid: { convId: {...inbox entry...} } }
    const usersByUid = legacyNode.val() || {};

    for (const [uid, conversations] of Object.entries(usersByUid)) {
      if (!conversations || typeof conversations !== 'object') continue;

      for (const [convId, legacyEntry] of Object.entries(conversations)) {
        const canonicalPath = `${inboxRoot}/${canonicalRole}/${uid}/${convId}`;
        const legacyPath = `${inboxRoot}/${legacyRole}/${uid}/${convId}`;

        try {
          const canonicalSnap = await rtdb.ref(canonicalPath).once('value');
          const canonicalEntry = canonicalSnap.val();

          if (canonicalEntry == null) {
            // No canonical entry yet — straight copy.
            console.log(
              `${DRY_RUN ? DRY : FIXED}  ${CYAN(school)} ${DIM('Inbox/')}` +
                `${legacyRole}${DIM('/')}${uid}${DIM('/')}${convId} → ${canonicalRole}/`
            );
            if (!DRY_RUN) await rtdb.ref(canonicalPath).set(legacyEntry);
            stats.conversationsCopied += 1;
          } else {
            // Both exist — merge legacy into canonical (canonical wins).
            const merged = mergeInbox(legacyEntry, canonicalEntry);
            const changed = JSON.stringify(merged) !== JSON.stringify(canonicalEntry);
            if (changed) {
              console.log(
                `${DRY_RUN ? DRY : MERGED}  ${CYAN(school)} ${DIM('Inbox/')}` +
                  `${canonicalRole}${DIM('/')}${uid}${DIM('/')}${convId} ${DIM('(filled missing fields from legacy)')}`
              );
              if (!DRY_RUN) await rtdb.ref(canonicalPath).set(merged);
              stats.conversationsMerged += 1;
            } else {
              stats.conversationsSkipped += 1;
            }
          }

          if (PRUNE && !DRY_RUN) {
            await rtdb.ref(legacyPath).remove();
            console.log(`${PRUNED}  ${CYAN(school)} ${DIM(legacyPath)}`);
            stats.conversationsPruned += 1;
          } else if (PRUNE && DRY_RUN) {
            console.log(`${DRY}  would prune ${DIM(legacyPath)}`);
          }
        } catch (e) {
          console.error(`${ERROR}  ${school} ${legacyPath}: ${e.message}`);
          stats.errors += 1;
        }
      }
    }
  }
}

async function main() {
  console.log(BOLD('━━━ Messaging Inbox Case Migration ━━━'));
  console.log(`Mode:   ${DRY_RUN ? 'DRY-RUN (no writes)' : 'APPLY'}`);
  console.log(`Prune:  ${PRUNE ? 'YES (will delete capital-case nodes)' : 'no'}`);
  console.log(`School: ${FILTER_SCHOOL || '(all)'}\n`);

  const schools = await listSchools();
  for (const school of schools) {
    await migrateOneSchool(school);
  }

  console.log('\n' + BOLD('━━━ Summary ━━━'));
  console.log(`Schools scanned:        ${stats.schools}`);
  console.log(`Legacy role nodes:      ${stats.legacyNodesScanned}`);
  console.log(`Conversations copied:   ${stats.conversationsCopied}`);
  console.log(`Conversations merged:   ${stats.conversationsMerged}`);
  console.log(`Conversations unchanged:${stats.conversationsSkipped}`);
  if (PRUNE) console.log(`Conversations pruned:   ${stats.conversationsPruned}`);
  if (stats.errors) console.log(`\x1b[31mErrors: ${stats.errors}\x1b[0m`);

  if (DRY_RUN) {
    console.log('\n' + DIM('Re-run with --apply to actually write the changes.'));
  }
  process.exit(stats.errors ? 1 : 0);
}

main().catch((e) => {
  console.error('Fatal:', e);
  process.exit(1);
});
