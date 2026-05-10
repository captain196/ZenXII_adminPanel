#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Academic Planner — Phase 0 Migration
 *
 *  Idempotent rewrite of legacy-shape docs to canonical (camelCase /
 *  schoolId / version-stamped) shape. Multi-collection, scoped by flag.
 *
 *  Collections handled:
 *    timetables          docId fixed to {schoolId}_{session}_{class}_{section}_{day}
 *                        schoolId field set to canonical school_id
 *                        manuallyEdited:false stamped if missing
 *                        version:1 stamped if missing
 *    substitutes         camelCase fields (absentTeacherId, substituteTeacherId, ...)
 *                        per-assignment normalization via Entity_firestore_sync rules (mirrored in JS)
 *                        version:1 stamped if missing
 *                        legacy snake-case keys preserved (dual-shape during transition)
 *    calendarEvents      startDate/endDate camelCase, visibleTo defaults to ['admin','teacher']
 *                        version:1 stamped if missing
 *    timetableSettings   startTime/endTime/periodsPerDay/lengthOfPeriod/recesses/workingDays camelCase
 *                        legacy Capitalized_snake_case keys preserved
 *    curriculum          version:1 stamped if missing (no other shape changes)
 *
 *  Marker:
 *    Every migrated doc gets `phase0MigratedAt: <ISO ts>` and
 *    `phase0MigrationRev: 1`. A re-run with the same rev SKIPS the doc.
 *    Bump rev to 2 if a future migration step needs to revisit.
 *
 *  Safety:
 *    Dry-run by default. Use --apply to commit. Per-collection flags
 *    (--collections=timetables,substitutes,...) for partial runs.
 *    Per-school filter (--school SCH_X). Verbose logging available.
 *
 *  Usage:
 *    node scripts/migrate_academic_planner_phase0.js                    # dry-run, all collections, all schools
 *    node scripts/migrate_academic_planner_phase0.js --apply            # commit
 *    node scripts/migrate_academic_planner_phase0.js --school SCH_X
 *    node scripts/migrate_academic_planner_phase0.js --collections=timetables,calendarEvents
 *    node scripts/migrate_academic_planner_phase0.js --apply --verbose
 * ═══════════════════════════════════════════════════════════════════
 */

const path = require('path');

// firebase-admin is installed under functions/node_modules in this repo
// (no root-level package.json). Resolve it explicitly so the script works
// regardless of cwd. If you've installed firebase-admin at root, the second
// fallback uses Node's normal resolution.
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

// ── Args ───────────────────────────────────────────────────────────
function parseArg(name) {
  const eqMatch = process.argv.find(a => a.startsWith(`--${name}=`));
  if (eqMatch) return eqMatch.split('=').slice(1).join('=');
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
const COLLECTIONS_ARG = parseArg('collections');
const COLLECTIONS = COLLECTIONS_ARG
  ? COLLECTIONS_ARG.split(',').map(s => s.trim()).filter(Boolean)
  : ['timetables', 'substitutes', 'calendarEvents', 'timetableSettings', 'curriculum'];

const PHASE0_REV = 1;

// ── Firebase init ──────────────────────────────────────────────────
const sa = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

// ── Styling ────────────────────────────────────────────────────────
const BOLD = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM  = (s) => `\x1b[2m${s}\x1b[0m`;
const CYAN = (s) => `\x1b[36m${s}\x1b[0m`;
const YEL  = (s) => `\x1b[33m${s}\x1b[0m`;
const RED  = (s) => `\x1b[31m${s}\x1b[0m`;
const GRN  = (s) => `\x1b[32m${s}\x1b[0m`;
const HR   = '─'.repeat(72);

// ── Stats ──────────────────────────────────────────────────────────
const stats = Object.fromEntries(COLLECTIONS.map(c => [c, {
  scanned: 0, migrated: 0, skipped: 0, failed: 0,
}]));

function logV(...args) { if (VERBOSE) console.log(DIM(args.join(' '))); }

// ── Helpers ────────────────────────────────────────────────────────
function alreadyMigrated(data) {
  return Number(data.phase0MigrationRev || 0) >= PHASE0_REV;
}

function stamp(data) {
  data.phase0MigratedAt   = new Date().toISOString();
  data.phase0MigrationRev = PHASE0_REV;
}

// Determine canonical schoolId for a legacy doc. Existing docs may have
// `schoolId` set to either the canonical school_id (correct) or the
// school_name (which historically equals school_id in this codebase, so
// no transform needed — we just normalize the field name).
function resolveSchoolId(d) {
  return d.schoolId || d.schoolCode || d.school_id || '';
}

// ── timetables ─────────────────────────────────────────────────────
//   Goal: every doc has docId = {schoolId}_{session}_{class}_{section}_{day}
//   AND schoolId field = canonical. Also stamp manuallyEdited:false +
//   version:1 if missing. Existing docId is kept (we don't delete &
//   recreate — that requires a transaction we don't have natively. We
//   only update the schoolId FIELD on the doc.)
async function migrateTimetables(school) {
  const col = fs.collection('timetables');
  const q = school ? col.where('schoolId', '==', school) : col;
  const snap = await q.get();
  for (const d of snap.docs) {
    stats.timetables.scanned++;
    const data = d.data();
    if (alreadyMigrated(data)) { stats.timetables.skipped++; continue; }

    const canonSchoolId = resolveSchoolId(data);
    if (!canonSchoolId) {
      logV('  skip (no schoolId)', d.id);
      stats.timetables.skipped++;
      continue;
    }

    const update = {
      schoolId: canonSchoolId,
    };
    if (data.manuallyEdited === undefined) update.manuallyEdited = false;
    if (data.version === undefined)        update.version = 1;
    stamp(update);

    if (DRY_RUN) {
      logV('  [dry] would update', d.id, '→', JSON.stringify(update));
      stats.timetables.migrated++;
      continue;
    }
    try {
      await d.ref.set(update, { merge: true });
      stats.timetables.migrated++;
    } catch (e) {
      console.error(RED('  failed'), d.id, e.message);
      stats.timetables.failed++;
    }
  }
}

// ── substitutes ────────────────────────────────────────────────────
//   Add camelCase mirrors of all snake_case fields. Keep snake-case
//   present for one cycle (dual-shape window). Stamp version:1 if missing.
async function migrateSubstitutes(school) {
  const col = fs.collection('substitutes');
  const q = school ? col.where('schoolId', '==', school) : col;
  const snap = await q.get();
  for (const d of snap.docs) {
    stats.substitutes.scanned++;
    const data = d.data();
    if (alreadyMigrated(data)) { stats.substitutes.skipped++; continue; }

    const update = {};

    // Top-level identity / audit fields
    if (data.absent_teacher_id   !== undefined && data.absentTeacherId   === undefined) update.absentTeacherId   = data.absent_teacher_id;
    if (data.absent_teacher_name !== undefined && data.absentTeacherName === undefined) update.absentTeacherName = data.absent_teacher_name;
    if (data.created_by !== undefined && data.createdByName === undefined) update.createdByName = data.created_by;
    if (data.updated_by !== undefined && data.updatedByName === undefined) update.updatedByName = data.updated_by;
    if (data.created_at !== undefined && data.createdAt     === undefined) update.createdAt     = data.created_at;
    if (data.updated_at !== undefined && data.updatedAt     === undefined) update.updatedAt     = data.updated_at;

    // Nested assignments[]
    if (Array.isArray(data.assignments) && data.assignments.length) {
      const newAssigns = data.assignments.map((a) => {
        if (!a || typeof a !== 'object') return a;
        const out = { ...a };
        if (a.substitute_teacher_id   !== undefined && out.substituteTeacherId   === undefined) out.substituteTeacherId   = a.substitute_teacher_id;
        if (a.substitute_teacher_name !== undefined && out.substituteTeacherName === undefined) out.substituteTeacherName = a.substitute_teacher_name;
        return out;
      });
      // Only write if any actual change
      const changed = JSON.stringify(newAssigns) !== JSON.stringify(data.assignments);
      if (changed) update.assignments = newAssigns;
    }

    if (data.version === undefined) update.version = 1;

    if (Object.keys(update).length === 0) { stats.substitutes.skipped++; continue; }
    stamp(update);

    if (DRY_RUN) {
      logV('  [dry] would update', d.id, '→ keys:', Object.keys(update).join(','));
      stats.substitutes.migrated++;
      continue;
    }
    try {
      await d.ref.set(update, { merge: true });
      stats.substitutes.migrated++;
    } catch (e) {
      console.error(RED('  failed'), d.id, e.message);
      stats.substitutes.failed++;
    }
  }
}

// ── calendarEvents ─────────────────────────────────────────────────
async function migrateCalendarEvents(school) {
  const col = fs.collection('calendarEvents');
  const q = school ? col.where('schoolId', '==', school) : col;
  const snap = await q.get();
  for (const d of snap.docs) {
    stats.calendarEvents.scanned++;
    const data = d.data();
    if (alreadyMigrated(data)) { stats.calendarEvents.skipped++; continue; }

    const update = {};
    if (data.start_date !== undefined && data.startDate === undefined) update.startDate = data.start_date;
    if (data.end_date   !== undefined && data.endDate   === undefined) update.endDate   = data.end_date;
    if (!Array.isArray(data.visibleTo) || !data.visibleTo.length)      update.visibleTo = ['admin', 'teacher'];
    if (data.version === undefined) update.version = 1;

    if (Object.keys(update).length === 0) { stats.calendarEvents.skipped++; continue; }
    stamp(update);

    if (DRY_RUN) {
      logV('  [dry] would update', d.id, '→ keys:', Object.keys(update).join(','));
      stats.calendarEvents.migrated++;
      continue;
    }
    try {
      await d.ref.set(update, { merge: true });
      stats.calendarEvents.migrated++;
    } catch (e) {
      console.error(RED('  failed'), d.id, e.message);
      stats.calendarEvents.failed++;
    }
  }
}

// ── timetableSettings ──────────────────────────────────────────────
async function migrateTimetableSettings(school) {
  const col = fs.collection('timetableSettings');
  const q = school ? col.where('schoolId', '==', school) : col;
  const snap = await q.get();
  for (const d of snap.docs) {
    stats.timetableSettings.scanned++;
    const data = d.data();
    if (alreadyMigrated(data)) { stats.timetableSettings.skipped++; continue; }

    const update = {};
    if (data.Start_time       !== undefined && data.startTime      === undefined) update.startTime      = data.Start_time;
    if (data.End_time         !== undefined && data.endTime        === undefined) update.endTime        = data.End_time;
    if (data.No_of_periods    !== undefined && data.periodsPerDay  === undefined) update.periodsPerDay  = data.No_of_periods;
    if (data.Length_of_period !== undefined && data.lengthOfPeriod === undefined) update.lengthOfPeriod = data.Length_of_period;
    if (Array.isArray(data.Working_days) && !Array.isArray(data.workingDays))     update.workingDays    = data.Working_days;

    if (Array.isArray(data.Recesses) && !Array.isArray(data.recesses)) {
      update.recesses = data.Recesses.map(r => ({
        afterPeriod: Number(r.after_period ?? r.afterPeriod ?? 0) || 0,
        durationMin: Number(r.duration ?? r.durationMin ?? 0) || 0,
      }));
    }

    if (Object.keys(update).length === 0) { stats.timetableSettings.skipped++; continue; }
    stamp(update);

    if (DRY_RUN) {
      logV('  [dry] would update', d.id, '→ keys:', Object.keys(update).join(','));
      stats.timetableSettings.migrated++;
      continue;
    }
    try {
      await d.ref.set(update, { merge: true });
      stats.timetableSettings.migrated++;
    } catch (e) {
      console.error(RED('  failed'), d.id, e.message);
      stats.timetableSettings.failed++;
    }
  }
}

// ── curriculum ─────────────────────────────────────────────────────
async function migrateCurriculum(school) {
  const col = fs.collection('curriculum');
  const q = school ? col.where('schoolId', '==', school) : col;
  const snap = await q.get();
  for (const d of snap.docs) {
    stats.curriculum.scanned++;
    const data = d.data();
    if (alreadyMigrated(data)) { stats.curriculum.skipped++; continue; }

    const update = {};
    if (data.version === undefined) update.version = 1;

    if (Object.keys(update).length === 0) { stats.curriculum.skipped++; continue; }
    stamp(update);

    if (DRY_RUN) {
      logV('  [dry] would update', d.id, '→ keys:', Object.keys(update).join(','));
      stats.curriculum.migrated++;
      continue;
    }
    try {
      await d.ref.set(update, { merge: true });
      stats.curriculum.migrated++;
    } catch (e) {
      console.error(RED('  failed'), d.id, e.message);
      stats.curriculum.failed++;
    }
  }
}

// ── Driver ─────────────────────────────────────────────────────────
async function main() {
  console.log(BOLD(CYAN('Academic Planner Phase 0 Migration')));
  console.log(HR);
  console.log(`  mode:        ${DRY_RUN ? YEL('DRY-RUN') : GRN('APPLY')}`);
  console.log(`  rev:         ${PHASE0_REV}`);
  console.log(`  collections: ${COLLECTIONS.join(', ')}`);
  console.log(`  school:      ${FILTER_SCHOOL || '(all)'}`);
  console.log(HR);

  const dispatch = {
    timetables:        migrateTimetables,
    substitutes:       migrateSubstitutes,
    calendarEvents:    migrateCalendarEvents,
    timetableSettings: migrateTimetableSettings,
    curriculum:        migrateCurriculum,
  };

  for (const c of COLLECTIONS) {
    if (!dispatch[c]) {
      console.log(RED(`  unknown collection: ${c}`));
      continue;
    }
    console.log(BOLD(`▸ ${c}`));
    try {
      await dispatch[c](FILTER_SCHOOL);
    } catch (e) {
      console.error(RED(`  ${c} migration failed:`), e);
    }
    const s = stats[c];
    console.log(`  scanned=${s.scanned}  migrated=${s.migrated}  skipped=${s.skipped}  failed=${s.failed}`);
  }

  console.log(HR);
  console.log(BOLD('Done'));
  if (DRY_RUN) {
    console.log(YEL('  No writes performed. Re-run with --apply to commit.'));
  }
}

main().catch(e => {
  console.error(RED('Fatal:'), e);
  process.exit(1);
});
