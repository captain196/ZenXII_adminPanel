#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Phase C-1 — Timetable Migration: RTDB → Firestore
 *
 *  Source (RTDB):
 *    Schools/{schoolId}/{session}/{class}/{section}/Time_table/{day}/{periodKey}
 *    Schools/{schoolId}/{session}/Time_table_settings  (read-only — for No_of_periods)
 *
 *  Target (Firestore):
 *    timetables/{schoolId}_{session}_{className}_{section}_{day}
 *
 *  Decision rules per (session, className, section, day) tuple:
 *    1. Firestore doc absent                                → CREATE
 *    2. Firestore doc exists with NON-EMPTY periods array   → SKIP
 *    3. Firestore doc exists with empty/missing periods     → OVERWRITE
 *
 *  Every decision is logged with reason. Re-runs are idempotent.
 *  Dry-run by default. NEVER deletes RTDB data.
 *
 *  Usage:
 *    node scripts/migrate_timetables_to_firestore.js                  # dry-run, all schools
 *    node scripts/migrate_timetables_to_firestore.js --apply          # write, all schools
 *    node scripts/migrate_timetables_to_firestore.js --school SCH_X
 *    node scripts/migrate_timetables_to_firestore.js --school=SCH_X
 *    node scripts/migrate_timetables_to_firestore.js --apply --school SCH_X
 *    node scripts/migrate_timetables_to_firestore.js --verbose        # log every tuple
 * ═══════════════════════════════════════════════════════════════════
 */

const admin = require('firebase-admin');
const path  = require('path');

const SERVICE_ACCOUNT_PATH = path.resolve(
  __dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
);
const DATABASE_URL = process.env.RTDB_URL
  || 'https://graderadmin-default-rtdb.firebaseio.com/';

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
const APPLY         = process.argv.includes('--apply');
const VERBOSE       = process.argv.includes('--verbose');
const DRY_RUN       = !APPLY;
const FILTER_SCHOOL  = parseArg('school');
const FILTER_CLASS   = parseArg('class');    // e.g. "Class 7th"
const FILTER_SECTION = parseArg('section');  // e.g. "Section A"
const FILTER_DAY     = parseArg('day');      // e.g. "Friday"

// ── Firebase init ──────────────────────────────────────────────────
const sa = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({
  credential:  admin.credential.cert(sa),
  databaseURL: DATABASE_URL,
});
const rtdb = admin.database();
const fs   = admin.firestore();

// ── Styling ────────────────────────────────────────────────────────
const BOLD = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM  = (s) => `\x1b[2m${s}\x1b[0m`;
const CYAN = (s) => `\x1b[36m${s}\x1b[0m`;
const YEL  = (s) => `\x1b[33m${s}\x1b[0m`;
const RED  = (s) => `\x1b[31m${s}\x1b[0m`;
const GRN  = (s) => `\x1b[32m${s}\x1b[0m`;
const HR   = '─'.repeat(72);
const HR2  = '═'.repeat(72);

// ── Stats ──────────────────────────────────────────────────────────
const stats = {
  schools:           0,
  rtdbDayTuples:     0,
  rtdbPeriodLeaves:  0,
  decisionCreate:    0,
  decisionSkip:      0,
  decisionOverwrite: 0,
  errors:            0,
  classOrderUnparseable: [], // { schoolId, className, reason }
  perSchool:         {},     // schoolId -> { create, skip, overwrite, errors }
};
function bumpSchool(schoolId, key) {
  if (!stats.perSchool[schoolId]) {
    stats.perSchool[schoolId] = { create: 0, skip: 0, overwrite: 0, errors: 0 };
  }
  stats.perSchool[schoolId][key]++;
}

// ── Helpers ────────────────────────────────────────────────────────
async function rtdbGet(p) {
  try { return (await rtdb.ref(p).once('value')).val(); }
  catch (_) { return null; }
}

/**
 * Compute classOrder from className. Per spec:
 *   - Numeric classes ("Class 7th", "Class 12") → parse digits
 *   - Pre-primary (Nursery, LKG, UKG, KG) → 0
 *   - Anything else → log loudly and return null (caller decides)
 */
function computeClassOrder(className) {
  const stripped = String(className || '').replace(/^Class\s+/i, '').trim();
  const lower = stripped.toLowerCase();
  const prePrimary = ['nursery', 'pre-nursery', 'pre nursery', 'lkg', 'ukg', 'kg', 'kg1', 'kg2', 'pre-kg'];
  if (prePrimary.includes(lower)) {
    return { value: 0, ok: true, reason: `pre-primary "${stripped}" → 0` };
  }
  const match = stripped.match(/(\d+)/);
  if (match) {
    return { value: parseInt(match[1], 10), ok: true, reason: `parsed "${stripped}" → ${match[1]}` };
  }
  return { value: null, ok: false, reason: `unparseable className "${stripped}"` };
}

function computeSectionCode(section) {
  return String(section || '').replace(/^Section\s+/i, '').trim();
}

/**
 * Build the canonical Firestore doc ID matching Academic.php:
 *   {schoolId}_{session}_{sectionKey-with-_-instead-of-/}_{day}
 *   = {schoolId}_{session}_{className}_{section}_{day}
 * with literal spaces preserved.
 */
function buildDocId(schoolId, session, className, section, day) {
  return `${schoolId}_${session}_${className}_${section}_${day}`;
}

/**
 * Convert one RTDB period leaf into a Firestore-shaped period object.
 * Drops legacy/contamination fields: Time, PassingMarks, TotalMarks.
 */
function mapPeriodLeaf(periodKey, leaf) {
  const periodNumber = parseInt(periodKey, 10);
  if (!Number.isFinite(periodNumber)) return null;
  if (!leaf || typeof leaf !== 'object') return null;
  return {
    periodNumber,
    subject:   String(leaf.subject   ?? ''),
    teacher:   String(leaf.teacher   ?? ''),
    teacherId: String(leaf.teacherId ?? ''),
    startTime: String(leaf.startTime ?? ''),
    endTime:   String(leaf.endTime   ?? ''),
    room:      String(leaf.room      ?? ''),
    type:      String(leaf.type      ?? 'class'),
  };
}

/**
 * Check whether a Firestore doc has a non-empty periods array with
 * at least one period that has actual content (subject or teacherId).
 * An array of empty placeholder periods (all-empty fields) counts as
 * empty — we'll overwrite that.
 */
function isPeriodsValid(fsDoc) {
  if (!fsDoc) return false;
  const periods = Array.isArray(fsDoc.periods) ? fsDoc.periods : null;
  if (!periods || periods.length === 0) return false;
  return periods.some(p => p && (
    (p.subject && String(p.subject).trim() !== '') ||
    (p.teacherId && String(p.teacherId).trim() !== '')
  ));
}

/**
 * Look up No_of_periods to fill gaps in the Firestore periods array
 * (matching Academic.php convention). Falls back to highest period seen
 * or 8 if neither side has a settings doc.
 */
const settingsCache = new Map();
async function getNoOfPeriods(schoolId, session) {
  const cacheKey = `${schoolId}|${session}`;
  if (settingsCache.has(cacheKey)) return settingsCache.get(cacheKey);

  let val = null;
  // Firestore canonical
  try {
    const doc = await fs.collection('timetableSettings').doc(`${schoolId}_${session}`).get();
    if (doc.exists) {
      const n = Number(doc.data().No_of_periods);
      if (Number.isFinite(n) && n > 0) val = n;
    }
  } catch (_) {}
  // RTDB fallback
  if (val == null) {
    const settings = await rtdbGet(`Schools/${schoolId}/${session}/Time_table_settings`);
    if (settings) {
      const n = Number(settings.No_of_periods);
      if (Number.isFinite(n) && n > 0) val = n;
    }
  }
  settingsCache.set(cacheKey, val);
  return val;
}

/**
 * Build a dense periods array of length `targetLen`, with placeholder
 * empty periods for gaps. Matches Academic.php behaviour.
 */
function densifyPeriods(rawPeriods, targetLen) {
  const byNum = new Map();
  for (const p of rawPeriods) byNum.set(p.periodNumber, p);
  const highest = rawPeriods.reduce((m, p) => Math.max(m, p.periodNumber), 0);
  const len = Math.max(targetLen || 0, highest);
  const out = [];
  for (let n = 1; n <= len; n++) {
    if (byNum.has(n)) {
      out.push(byNum.get(n));
    } else {
      out.push({
        periodNumber: n,
        subject: '', teacher: '', teacherId: '',
        startTime: '', endTime: '', room: '', type: 'class',
      });
    }
  }
  return out;
}

// ── Decision logger ───────────────────────────────────────────────
function logDecision(decision, docId, reason) {
  const tag = decision === 'CREATE'    ? GRN ('+ CREATE   ')
            : decision === 'SKIP'      ? DIM ('- SKIP     ')
            : decision === 'OVERWRITE' ? YEL ('~ OVERWRITE')
            : decision === 'ERROR'     ? RED ('! ERROR    ')
                                       : decision;
  if (decision === 'SKIP' && !VERBOSE) return; // skips are noisy
  const prefix = DRY_RUN ? CYAN('[DRY] ') : '';
  console.log(`    ${prefix}${tag}  ${docId}  ${DIM(reason)}`);
}

// ── Per-day tuple processing ──────────────────────────────────────
async function processDayTuple({
  schoolId, session, className, section, day, dayNode,
}) {
  stats.rtdbDayTuples++;

  const docId = buildDocId(schoolId, session, className, section, day);
  const fsRef = fs.collection('timetables').doc(docId);

  // Read existing doc to apply decision rule
  let existing = null;
  try {
    const snap = await fsRef.get();
    existing = snap.exists ? snap.data() : null;
  } catch (e) {
    logDecision('ERROR', docId, `read failed: ${e.message}`);
    stats.errors++;
    bumpSchool(schoolId, 'errors');
    return;
  }

  // Build the periods array from RTDB leaves
  const rawPeriods = [];
  for (const [key, leaf] of Object.entries(dayNode)) {
    const mapped = mapPeriodLeaf(key, leaf);
    if (mapped) {
      rawPeriods.push(mapped);
      stats.rtdbPeriodLeaves++;
    }
  }
  if (rawPeriods.length === 0) {
    logDecision('SKIP', docId, 'no valid period leaves in RTDB day node');
    stats.decisionSkip++;
    bumpSchool(schoolId, 'skip');
    return;
  }

  // Decision rule
  if (existing && isPeriodsValid(existing)) {
    logDecision('SKIP', docId, `Firestore has ${existing.periods.length} valid periods`);
    stats.decisionSkip++;
    bumpSchool(schoolId, 'skip');
    return;
  }
  const decision = existing ? 'OVERWRITE' : 'CREATE';
  const reasonOut = existing
    ? 'FS doc has empty/placeholder periods — refilling from RTDB'
    : `creating from ${rawPeriods.length} RTDB period(s)`;

  // Compute classOrder
  const co = computeClassOrder(className);
  if (!co.ok) {
    stats.classOrderUnparseable.push({ schoolId, className, reason: co.reason });
  }
  // Densify to match Academic.php convention
  const noOfPeriods = await getNoOfPeriods(schoolId, session);
  const periodsDense = densifyPeriods(rawPeriods.sort((a, b) => a.periodNumber - b.periodNumber),
                                      noOfPeriods);

  const payload = {
    schoolId,
    session,
    className,
    section,
    classOrder:  co.value,           // null if unparseable — flagged in summary
    sectionCode: computeSectionCode(section),
    sectionKey:  `${className}/${section}`,
    day,
    periods:     periodsDense,
    updatedAt:   new Date().toISOString(),
    migratedFrom: 'rtdb-Time_table',
    migratedAt:   new Date().toISOString(),
  };

  if (DRY_RUN) {
    logDecision(decision, docId, reasonOut + (co.ok ? '' : ` ⚠ ${co.reason}`));
    if (decision === 'CREATE') { stats.decisionCreate++;    bumpSchool(schoolId, 'create'); }
    else                        { stats.decisionOverwrite++; bumpSchool(schoolId, 'overwrite'); }
    return;
  }

  try {
    await fsRef.set(payload, { merge: false });   // full replace; safe here because
                                                  // (a) CREATE is fresh, (b) OVERWRITE
                                                  // only triggers on empty-periods FS.
    logDecision(decision, docId, reasonOut + (co.ok ? '' : ` ⚠ ${co.reason}`));
    if (decision === 'CREATE') { stats.decisionCreate++;    bumpSchool(schoolId, 'create'); }
    else                        { stats.decisionOverwrite++; bumpSchool(schoolId, 'overwrite'); }
  } catch (e) {
    logDecision('ERROR', docId, `write failed: ${e.message}`);
    stats.errors++;
    bumpSchool(schoolId, 'errors');
  }
}

// ── Per-school walk ───────────────────────────────────────────────
async function processSchool(schoolId) {
  console.log(`\n${HR}\n  ${BOLD(CYAN(schoolId))}\n${HR}`);
  stats.schools++;

  const root = await rtdbGet(`Schools/${schoolId}`);
  if (!root || typeof root !== 'object') {
    console.log(`  ${DIM('Schools/' + schoolId + ' — empty')}`);
    return;
  }
  const sessions = Object.keys(root).filter(k => /^\d{4}-\d{2}$/.test(k));
  if (sessions.length === 0) {
    console.log(`  ${DIM('No academic sessions found')}`);
    return;
  }

  for (const session of sessions) {
    const sNode = root[session];
    if (!sNode || typeof sNode !== 'object') continue;

    for (const [classKey, sects] of Object.entries(sNode)) {
      if (!classKey.startsWith('Class ') || !sects || typeof sects !== 'object') continue;
      if (FILTER_CLASS && classKey !== FILTER_CLASS) continue;
      for (const [secKey, secNode] of Object.entries(sects)) {
        if (!secKey.startsWith('Section ') || !secNode || typeof secNode !== 'object') continue;
        if (FILTER_SECTION && secKey !== FILTER_SECTION) continue;
        const tt = secNode['Time_table'];
        if (!tt || typeof tt !== 'object') continue;

        for (const [day, dayNode] of Object.entries(tt)) {
          if (!dayNode || typeof dayNode !== 'object') continue;
          if (FILTER_DAY && day !== FILTER_DAY) continue;
          await processDayTuple({
            schoolId, session,
            className: classKey,
            section:   secKey,
            day,
            dayNode,
          });
        }
      }
    }
  }
}

// ── Summary ────────────────────────────────────────────────────────
function printSummary() {
  console.log(`\n${HR2}\n  ${BOLD('SUMMARY')}\n${HR2}`);
  console.log(`  Mode:                   ${DRY_RUN ? CYAN('DRY-RUN (no writes)') : GRN('APPLY (writes performed)')}`);
  console.log(`  Schools processed:      ${stats.schools}`);
  console.log(`  RTDB day tuples seen:   ${stats.rtdbDayTuples}`);
  console.log(`  RTDB period leaves:     ${stats.rtdbPeriodLeaves}`);
  console.log();
  console.log(`  ${GRN('Created:')}              ${stats.decisionCreate}`);
  console.log(`  ${YEL('Overwritten:')}          ${stats.decisionOverwrite}  ${DIM('(FS had empty periods)')}`);
  console.log(`  ${DIM('Skipped:')}              ${stats.decisionSkip}  ${DIM('(FS already valid)')}`);
  console.log(`  ${RED('Errors:')}               ${stats.errors}`);

  // Per-school breakdown
  const ids = Object.keys(stats.perSchool);
  if (ids.length > 1) {
    console.log(`\n  ${BOLD('Per school:')}`);
    console.log(`    ${'School'.padEnd(20)} ${'CREATE'.padStart(7)} ${'OVERW.'.padStart(7)} ${'SKIP'.padStart(7)} ${'ERR'.padStart(5)}`);
    for (const id of ids) {
      const s = stats.perSchool[id];
      console.log(`    ${id.padEnd(20)} ${String(s.create).padStart(7)} ${String(s.overwrite).padStart(7)} ${String(s.skip).padStart(7)} ${String(s.errors).padStart(5)}`);
    }
  }

  // classOrder warnings
  if (stats.classOrderUnparseable.length > 0) {
    console.log(`\n  ${YEL('⚠ classOrder needs manual review:')} ${stats.classOrderUnparseable.length} doc(s)`);
    const seen = new Set();
    for (const w of stats.classOrderUnparseable) {
      const k = `${w.schoolId}|${w.className}`;
      if (seen.has(k)) continue;
      seen.add(k);
      console.log(`    - ${w.schoolId}  ${w.className}  ${DIM(w.reason)}`);
    }
    console.log(`    ${DIM('(written with classOrder=null; admin should re-save these via Academic.php to fix)')}`);
  }

  if (DRY_RUN) {
    console.log(`\n  ${DIM('Re-run with --apply to actually write to Firestore.')}`);
  }
  console.log(HR2);
}

// ── Main ───────────────────────────────────────────────────────────
async function main() {
  console.log(`\n${HR2}`);
  console.log(BOLD('  Phase C-1 — Timetable Migration: RTDB → Firestore'));
  console.log(`  ${DIM('Source: Schools/.../Time_table/{day}/{period}')}`);
  console.log(`  ${DIM('Target: timetables/{schoolId}_{session}_{className}_{section}_{day}')}`);
  console.log(DRY_RUN
    ? `  ${BOLD(CYAN('DRY-RUN MODE — no writes'))}`
    : `  ${BOLD(GRN('APPLY MODE — writing to Firestore'))}`);
  if (FILTER_SCHOOL)  console.log(`  School filter:  ${BOLD(FILTER_SCHOOL)}`);
  if (FILTER_CLASS)   console.log(`  Class filter:   ${BOLD(FILTER_CLASS)}`);
  if (FILTER_SECTION) console.log(`  Section filter: ${BOLD(FILTER_SECTION)}`);
  if (FILTER_DAY)     console.log(`  Day filter:     ${BOLD(FILTER_DAY)}`);
  if (VERBOSE)        console.log(`  Verbose: every tuple logged (incl. SKIP)`);
  console.log(HR2);

  const sysSchools = await rtdbGet('System/Schools');
  if (!sysSchools) {
    console.log(`\n  ${RED('No schools at System/Schools')}`);
    process.exit(1);
  }
  let schoolIds = Object.keys(sysSchools);
  if (FILTER_SCHOOL) {
    if (!schoolIds.includes(FILTER_SCHOOL)) {
      console.log(`\n  ${RED(`School "${FILTER_SCHOOL}" not in System/Schools.`)}`);
      process.exit(1);
    }
    schoolIds = [FILTER_SCHOOL];
  }
  console.log(`\n  Processing ${CYAN(String(schoolIds.length))} school(s)…`);

  for (const id of schoolIds) {
    try { await processSchool(id); }
    catch (e) {
      console.log(`\n  ${RED(`School ${id} failed:`)} ${e.message}`);
      stats.errors++;
    }
  }

  printSummary();
  process.exit(stats.errors > 0 ? 2 : 0);
}

main().catch(e => { console.error('\nFATAL:', e); process.exit(1); });
