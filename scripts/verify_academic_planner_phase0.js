#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Academic Planner — Phase 0 Verification (post-migration smoke test)
 *
 *  Reads ALL migrated docs across the 5 collections and asserts:
 *    1. Canonical (camelCase) fields are present
 *    2. Legacy fields, when they existed, still match their canonical mirror
 *    3. phase0MigrationRev=1 stamp is present
 *    4. Field types are sane (arrays are arrays, ints are ints)
 *
 *  NON-destructive (read-only). Run anytime after --apply.
 *
 *  Usage:
 *    node scripts/verify_academic_planner_phase0.js
 *    node scripts/verify_academic_planner_phase0.js --school SCH_X
 *    node scripts/verify_academic_planner_phase0.js --verbose
 * ═══════════════════════════════════════════════════════════════════
 */

const path = require('path');

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
const VERBOSE       = process.argv.includes('--verbose');
const FILTER_SCHOOL = parseArg('school');

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

const fail = []; // collected failures
function assert(cond, msg, docPath) {
  if (!cond) {
    fail.push({ msg, docPath });
    if (VERBOSE) console.log(RED('  ✗ ') + msg + DIM(' @ ' + docPath));
  } else if (VERBOSE) {
    console.log(GRN('  ✓ ') + msg);
  }
}

async function getDocs(coll) {
  const c = fs.collection(coll);
  const q = FILTER_SCHOOL ? c.where('schoolId', '==', FILTER_SCHOOL) : c;
  const snap = await q.get();
  return snap.docs;
}

async function checkTimetables() {
  console.log(BOLD('▸ timetables'));
  const docs = await getDocs('timetables');
  for (const d of docs) {
    const data = d.data();
    const p = `timetables/${d.id}`;
    assert(typeof data.schoolId === 'string' && data.schoolId.length > 0,
      'schoolId is non-empty string', p);
    assert(data.phase0MigrationRev === 1,
      'phase0MigrationRev === 1', p);
    assert(typeof data.phase0MigratedAt === 'string',
      'phase0MigratedAt is string (ISO timestamp)', p);
    assert(typeof data.version === 'number' && data.version >= 1,
      'version is positive int', p);
    assert(typeof data.manuallyEdited === 'boolean',
      'manuallyEdited is boolean', p);
    assert(Array.isArray(data.periods) || data.periods === undefined,
      'periods is array (when present)', p);
  }
  return docs.length;
}

async function checkSubstitutes() {
  console.log(BOLD('▸ substitutes'));
  const docs = await getDocs('substitutes');
  for (const d of docs) {
    const data = d.data();
    const p = `substitutes/${d.id}`;
    assert(data.phase0MigrationRev === 1, 'phase0MigrationRev === 1', p);
    assert(typeof data.version === 'number' && data.version >= 1, 'version is positive int', p);

    // Top-level camel mirrors of snake-case identity fields. If the legacy
    // snake field was on the doc pre-migration, the camel mirror MUST equal it.
    if (data.absent_teacher_id !== undefined) {
      assert(data.absentTeacherId === data.absent_teacher_id,
        'absentTeacherId mirrors absent_teacher_id', p);
    }
    if (data.absent_teacher_name !== undefined) {
      assert(data.absentTeacherName === data.absent_teacher_name,
        'absentTeacherName mirrors absent_teacher_name', p);
    }
    if (data.created_by !== undefined) {
      assert(data.createdByName === data.created_by,
        'createdByName mirrors created_by', p);
    }
    if (data.updated_by !== undefined) {
      assert(data.updatedByName === data.updated_by,
        'updatedByName mirrors updated_by', p);
    }

    // Per-assignment mirrors
    if (Array.isArray(data.assignments)) {
      data.assignments.forEach((a, i) => {
        if (!a || typeof a !== 'object') return;
        if (a.substitute_teacher_id !== undefined) {
          assert(a.substituteTeacherId === a.substitute_teacher_id,
            `assignments[${i}].substituteTeacherId mirrors substitute_teacher_id`, p);
        }
        if (a.substitute_teacher_name !== undefined) {
          assert(a.substituteTeacherName === a.substitute_teacher_name,
            `assignments[${i}].substituteTeacherName mirrors substitute_teacher_name`, p);
        }
      });
    }
  }
  return docs.length;
}

async function checkCalendarEvents() {
  console.log(BOLD('▸ calendarEvents'));
  const docs = await getDocs('calendarEvents');
  for (const d of docs) {
    const data = d.data();
    const p = `calendarEvents/${d.id}`;
    assert(data.phase0MigrationRev === 1, 'phase0MigrationRev === 1', p);
    assert(typeof data.version === 'number' && data.version >= 1, 'version is positive int', p);
    assert(typeof data.startDate === 'string' && data.startDate.length > 0,
      'startDate is non-empty string', p);
    assert(typeof data.endDate === 'string' && data.endDate.length > 0,
      'endDate is non-empty string', p);
    if (data.start_date !== undefined) {
      assert(data.startDate === data.start_date,
        'startDate mirrors start_date', p);
    }
    if (data.end_date !== undefined) {
      assert(data.endDate === data.end_date,
        'endDate mirrors end_date', p);
    }
    assert(Array.isArray(data.visibleTo) && data.visibleTo.length > 0,
      'visibleTo is non-empty array', p);
    const allowed = ['admin', 'teacher', 'parent'];
    assert(data.visibleTo.every(v => allowed.includes(v)),
      `visibleTo entries are subset of ${JSON.stringify(allowed)}`, p);
  }
  return docs.length;
}

async function checkTimetableSettings() {
  console.log(BOLD('▸ timetableSettings'));
  const docs = await getDocs('timetableSettings');
  for (const d of docs) {
    const data = d.data();
    const p = `timetableSettings/${d.id}`;
    assert(data.phase0MigrationRev === 1, 'phase0MigrationRev === 1', p);

    // Canonical camelCase
    assert(typeof data.startTime      === 'string', 'startTime is string', p);
    assert(typeof data.endTime        === 'string', 'endTime is string',   p);
    assert(typeof data.periodsPerDay  === 'number', 'periodsPerDay is number', p);
    assert(typeof data.lengthOfPeriod === 'number', 'lengthOfPeriod is number', p);
    assert(Array.isArray(data.workingDays), 'workingDays is array', p);

    // Legacy mirrors should match canonical when both exist
    if (data.Start_time !== undefined) {
      assert(data.startTime === data.Start_time, 'startTime mirrors Start_time', p);
    }
    if (data.End_time !== undefined) {
      assert(data.endTime === data.End_time, 'endTime mirrors End_time', p);
    }
    if (data.No_of_periods !== undefined) {
      assert(Number(data.periodsPerDay) === Number(data.No_of_periods),
        'periodsPerDay mirrors No_of_periods', p);
    }

    // Recesses canonical shape
    if (Array.isArray(data.recesses)) {
      data.recesses.forEach((r, i) => {
        assert(typeof r.afterPeriod === 'number' || r.afterPeriod === null || r.afterPeriod === undefined,
          `recesses[${i}].afterPeriod is number/null/missing`, p);
        assert(typeof r.durationMin === 'number',
          `recesses[${i}].durationMin is number`, p);
      });
    }
  }
  return docs.length;
}

async function checkCurriculum() {
  console.log(BOLD('▸ curriculum'));
  const docs = await getDocs('curriculum');
  for (const d of docs) {
    const data = d.data();
    const p = `curriculum/${d.id}`;
    assert(data.phase0MigrationRev === 1, 'phase0MigrationRev === 1', p);
    assert(typeof data.version === 'number' && data.version >= 1,
      'version is positive int', p);
    assert(Array.isArray(data.topics) || data.topics === undefined,
      'topics is array (when present)', p);
  }
  return docs.length;
}

async function main() {
  console.log(BOLD(CYAN('Academic Planner Phase 0 — Verification')));
  console.log(HR);
  console.log('  school:  ' + (FILTER_SCHOOL || '(all)'));
  console.log('  verbose: ' + VERBOSE);
  console.log(HR);

  const counts = {};
  counts.timetables        = await checkTimetables();
  counts.substitutes       = await checkSubstitutes();
  counts.calendarEvents    = await checkCalendarEvents();
  counts.timetableSettings = await checkTimetableSettings();
  counts.curriculum        = await checkCurriculum();

  console.log(HR);
  console.log(BOLD('Summary'));
  for (const [c, n] of Object.entries(counts)) {
    console.log(`  ${c.padEnd(20)} docs checked: ${n}`);
  }
  console.log(HR);
  if (fail.length === 0) {
    console.log(GRN(BOLD('✔ All assertions passed.')));
    process.exit(0);
  } else {
    console.log(RED(BOLD(`✗ ${fail.length} assertion(s) failed:`)));
    fail.forEach(f => console.log(RED('  • ') + f.msg + DIM(' @ ' + f.docPath)));
    process.exit(2);
  }
}

main().catch(e => {
  console.error(RED('Fatal:'), e);
  process.exit(1);
});
