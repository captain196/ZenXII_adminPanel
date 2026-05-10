#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  M-1: vacant_treatment legacy migration
 *
 *  Phase 5 payroll hardening flipped the DEFAULT for missing-attendance
 *  handling from 'absent' (silent zero-salary risk) to 'block' (refuse
 *  to generate slip without explicit override).
 *
 *  Schools that have an EXPLICIT setting `attendanceRules.vacant_treatment
 *  = 'absent'` still hit the legacy behavior. This script flips them to
 *  'block' so the new safety default applies everywhere.
 *
 *  IDEMPOTENT: re-running is safe; schools already on 'block' (or any
 *  non-'absent' value) are skipped.
 *
 *  Usage:
 *    node scripts/migrate_vacant_treatment.js              # dry-run
 *    node scripts/migrate_vacant_treatment.js --apply      # write
 *    node scripts/migrate_vacant_treatment.js --school SCH_DEMO --apply
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

const serviceAccount = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({
  credential: admin.credential.cert(serviceAccount),
  databaseURL: DATABASE_URL,
});

const fs = admin.firestore();

const FIXED   = '\x1b[32m✔ FLIPPED \x1b[0m';
const SKIPPED = '\x1b[90m⊘ SKIPPED \x1b[0m';
const DRY     = '\x1b[35m⬡ DRY-RUN \x1b[0m';
const ERROR   = '\x1b[31m✖ ERROR   \x1b[0m';
const BOLD    = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM     = (s) => `\x1b[2m${s}\x1b[0m`;

const stats = {
  total: 0,
  flipped: 0,
  alreadyBlock: 0,
  alreadyOther: 0,
  noConfig: 0,
  errors: 0,
};

(async () => {
  console.log(BOLD('M-1 vacant_treatment migration'));
  console.log(DIM(`Mode: ${DRY_RUN ? 'DRY RUN (no writes)' : 'APPLY'}`));
  if (FILTER_SCHOOL) console.log(DIM(`School filter: ${FILTER_SCHOOL}`));
  console.log('');

  let snap;
  try {
    snap = await fs.collection('schools').get();
  } catch (e) {
    console.error(ERROR, 'Failed to read schools collection:', e.message);
    process.exit(1);
  }

  for (const doc of snap.docs) {
    stats.total++;
    const schoolId = doc.id;
    if (FILTER_SCHOOL && schoolId !== FILTER_SCHOOL) continue;

    const data = doc.data() || {};
    const rules = data.attendanceRules || {};
    const current = rules.vacant_treatment;

    if (current === undefined || current === null || current === '') {
      stats.noConfig++;
      console.log(SKIPPED, schoolId, DIM('(no config — falls back to default block)'));
      continue;
    }

    if (current === 'block') {
      stats.alreadyBlock++;
      console.log(SKIPPED, schoolId, DIM('(already block)'));
      continue;
    }

    if (current !== 'absent') {
      stats.alreadyOther++;
      console.log(SKIPPED, schoolId, DIM(`(custom value '${current}' — left untouched)`));
      continue;
    }

    // current === 'absent' — flip it
    if (DRY_RUN) {
      console.log(DRY, schoolId, BOLD("'absent' → 'block'"));
    } else {
      try {
        await doc.ref.update({
          'attendanceRules.vacant_treatment': 'block',
          'attendanceRules.vacant_treatment_migrated_at': new Date().toISOString(),
          'attendanceRules.vacant_treatment_legacy_value': 'absent',
        });
        console.log(FIXED, schoolId, BOLD("'absent' → 'block'"));
      } catch (e) {
        stats.errors++;
        console.log(ERROR, schoolId, e.message);
        continue;
      }
    }
    stats.flipped++;
  }

  console.log('');
  console.log(BOLD('Summary:'));
  console.log(`  Schools scanned:      ${stats.total}`);
  console.log(`  Flipped 'absent'→'block': ${stats.flipped} ${DRY_RUN ? '(dry-run)' : ''}`);
  console.log(`  Already 'block':      ${stats.alreadyBlock}`);
  console.log(`  No config (default):  ${stats.noConfig}`);
  console.log(`  Other custom values:  ${stats.alreadyOther}`);
  if (stats.errors) console.log(`  ${ERROR}Errors: ${stats.errors}`);
  if (DRY_RUN && stats.flipped > 0) {
    console.log('');
    console.log(BOLD('Re-run with --apply to write changes.'));
  }
  process.exit(0);
})();
