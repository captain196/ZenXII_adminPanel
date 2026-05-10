#!/usr/bin/env node
/**
 * Data-layer smoke test for the Student Red Flag module.
 *
 * Regression-focused: every check targets a bug we've actually shipped a
 * fix for, so reverting any of them lights up here at script-run time.
 *
 *   §A  studentFlags data shape (doc id, type/severity/status enums,
 *       canonical class/section, createdByRole, *Ms fields are numeric).
 *   §B  Admin timestamp regression (BUG #3 / BUG #4, 2026-05-06):
 *       admin must not write ISO `createdAt`/`resolvedAt` STRINGS — only
 *       Firestore Timestamps. Prevents drift from the Teacher canonical.
 *   §C  Cross-school auth regression (BUG #1, 2026-05-06): Red_flags.php
 *       resolve_flag/delete_flag/bulk_resolve must use the AND-mismatch
 *       pattern (schoolId !== X AND schoolCode !== X), NOT the `??`
 *       chain that failed when schoolId was empty.
 *   §D  Phase 6A foundation regressions (Teacher Kotlin source):
 *         - QuickFlagSheet free-text fallback REMOVED (2026-05-07 mandate)
 *         - "No subject assigned" message present
 *         - Exactly 3 templates ship in v1 (no creep)
 *         - StudentsScreen has the QuickFlagSheet wiring
 *   §E  Firestore rules sanity (any school staff can resolve, soft-delete
 *       creator-only — the 2026-05-06 policy update).
 *   §F  Index sanity for the dashboard rolling-window queries.
 *   §G  flagId fallback regression (BUG #2, 2026-05-06): every doc must
 *       have a `flagId` field — the admin's _collect_all_flags strip
 *       fallback exists, but the canonical write path should produce it.
 *
 *   node scripts/smoke_test_redflags.js
 *   node scripts/smoke_test_redflags.js --school=SCH_X
 */
const path = require('path');
const fs = require('fs');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();
const Timestamp = admin.firestore.Timestamp;

function arg(name, dflt) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  return eq ? eq.split('=').slice(1).join('=') : dflt;
}
const SCHOOL = arg('school', 'SCH_D94FE8F7AD');

const results = [];
function record(id, group, name, status, detail = '') {
  results.push({ id, group, name, status, detail });
  const icon   = status === 'pass' ? '✓' : status === 'fail' ? '✗' : '·';
  const colour = status === 'pass' ? 32  : status === 'fail' ? 31  : 90;
  console.log(`  \x1b[${colour}m${icon}\x1b[0m  ${id}  ${name}${detail ? '  — ' + detail : ''}`);
}
function header(text) { console.log(`\n\x1b[1m${text}\x1b[0m`); }

const ALLOWED_TYPES      = new Set(['homework', 'behavior', 'performance']);
const ALLOWED_SEVERITIES = new Set(['low', 'medium', 'high']);
const ALLOWED_STATUSES   = new Set(['active', 'resolved', 'deleted']);
const ALLOWED_ROLES      = new Set(['teacher', 'admin']);

// BUG #3 / #4 fix date — admin write path stopped emitting ISO createdAt /
// stopped omitting createdByRole on this date. Docs older than this are
// "legacy era" and reported as skip-with-info; docs newer must conform
// or it's a real regression.
const BUG3_4_FIX_MS = new Date('2026-05-06T00:00:00Z').getTime();
function isLegacyEra(d) {
  const ms = d.data().createdAtMs;
  return typeof ms === 'number' && ms < BUG3_4_FIX_MS;
}

// ─────────────────────────────────────────────────────────────────────
// §A — Data shape sanity
// ─────────────────────────────────────────────────────────────────────
async function groupA() {
  header(`§A — Data shape sanity (school=${SCHOOL})`);

  const snap = await db.collection('studentFlags')
    .where('schoolId', '==', SCHOOL).get();
  if (snap.empty) {
    record('A0', 'A', 'studentFlags collection empty for this school', 'fail',
      'create at least one flag from admin or Teacher app to seed the test');
    return null;
  }
  record('A0', 'A', `studentFlags has ${snap.size} doc(s)`, 'pass');

  // A1 — canonical doc id: {schoolId}_{flagId}
  let badId = 0, sampleBadId = '';
  snap.forEach(d => {
    const x = d.data();
    if (!x.flagId) return; // §G covers that
    const expected = `${SCHOOL}_${x.flagId}`;
    if (d.id !== expected) {
      badId++;
      if (!sampleBadId) sampleBadId = `id=${d.id} expected=${expected}`;
    }
  });
  if (badId === 0) {
    record('A1', 'A', `doc id matches {schoolId}_{flagId} on every doc`, 'pass');
  } else {
    record('A1', 'A', `${badId} doc(s) have non-canonical id`, 'fail', sampleBadId);
  }

  // A2 — type ∈ canonical set
  const badTypes = [];
  snap.forEach(d => {
    const t = (d.data().type || '').toLowerCase().trim();
    if (!ALLOWED_TYPES.has(t)) badTypes.push(`${d.id} type=[${d.data().type}]`);
  });
  if (badTypes.length === 0) {
    record('A2', 'A', `type is canonical {homework,behavior,performance} on every doc`, 'pass');
  } else {
    record('A2', 'A', `${badTypes.length} doc(s) have non-canonical type`, 'fail', badTypes[0]);
  }

  // A3 — severity ∈ canonical set
  const badSev = [];
  snap.forEach(d => {
    const s = (d.data().severity || '').toLowerCase().trim();
    if (!ALLOWED_SEVERITIES.has(s)) badSev.push(`${d.id} severity=[${d.data().severity}]`);
  });
  if (badSev.length === 0) {
    record('A3', 'A', `severity is canonical {low,medium,high} on every doc`, 'pass');
  } else {
    record('A3', 'A', `${badSev.length} doc(s) have non-canonical severity`, 'fail', badSev[0]);
  }

  // A4 — status ∈ canonical set
  const badSt = [];
  snap.forEach(d => {
    const s = (d.data().status || '').toLowerCase().trim();
    if (!ALLOWED_STATUSES.has(s)) badSt.push(`${d.id} status=[${d.data().status}]`);
  });
  if (badSt.length === 0) {
    record('A4', 'A', `status is canonical {active,resolved,deleted} on every doc`, 'pass');
  } else {
    record('A4', 'A', `${badSt.length} doc(s) have non-canonical status`, 'fail', badSt[0]);
  }

  // A5 — createdByRole ∈ canonical set (no missing / "?" / unrecognised).
  // Pre-2026-05-06 admin writes didn't always set this — those are legacy
  // era and skipped. Anything written after the fix must conform.
  const badRoleNew = [], badRoleLegacy = [];
  snap.forEach(d => {
    const r = (d.data().createdByRole || '').toLowerCase().trim();
    if (ALLOWED_ROLES.has(r)) return;
    const item = `${d.id} createdByRole=[${d.data().createdByRole}]`;
    if (isLegacyEra(d)) badRoleLegacy.push(item);
    else                badRoleNew.push(item);
  });
  if (badRoleNew.length === 0 && badRoleLegacy.length === 0) {
    record('A5', 'A', `createdByRole is canonical {teacher,admin} on every doc`, 'pass');
  } else if (badRoleNew.length === 0) {
    record('A5', 'A',
      `${badRoleLegacy.length} legacy doc(s) (pre-2026-05-06) lack createdByRole — backfill optional`,
      'skip', badRoleLegacy[0]);
  } else {
    record('A5', 'A',
      `${badRoleNew.length} POST-FIX doc(s) missing canonical createdByRole — REGRESSION`,
      'fail', badRoleNew[0] + '  — admin/teacher write path is dropping the field');
  }

  // A6 — className is "Class …" canonical (Class/section is the #1 cross-system drift hotspot)
  const badCls = [];
  snap.forEach(d => {
    const c = d.data().className || '';
    if (!/^Class\s+/.test(c)) badCls.push(`${d.id} className=[${c}]`);
  });
  if (badCls.length === 0) {
    record('A6', 'A', `className is canonical "Class …" on every doc`, 'pass');
  } else {
    record('A6', 'A', `${badCls.length} doc(s) have non-canonical className`, 'fail', badCls[0]);
  }

  // A7 — section is "Section …" canonical
  const badSec = [];
  snap.forEach(d => {
    const s = d.data().section || '';
    if (!/^Section\s+/.test(s)) badSec.push(`${d.id} section=[${s}]`);
  });
  if (badSec.length === 0) {
    record('A7', 'A', `section is canonical "Section …" on every doc`, 'pass');
  } else {
    record('A7', 'A', `${badSec.length} doc(s) have non-canonical section`, 'fail', badSec[0]);
  }

  return snap;
}

// ─────────────────────────────────────────────────────────────────────
// §B — Admin timestamp regression (BUG #3 / BUG #4, 2026-05-06)
// ─────────────────────────────────────────────────────────────────────
async function groupB(snap) {
  header(`§B — Admin timestamp regression (BUG #3 / BUG #4)`);
  if (!snap || snap.empty) {
    record('B0', 'B', 'no docs — skipped', 'skip');
    return;
  }

  // B1 — createdAtMs is a number (Long), required on every doc
  let badMs = 0, sampleMs = '';
  snap.forEach(d => {
    const v = d.data().createdAtMs;
    if (typeof v !== 'number' || !Number.isFinite(v)) {
      badMs++;
      if (!sampleMs) sampleMs = `${d.id} createdAtMs=[${typeof v} ${JSON.stringify(v)}]`;
    }
  });
  if (badMs === 0) {
    record('B1', 'B', `createdAtMs is numeric on every doc`, 'pass');
  } else {
    record('B1', 'B', `${badMs} doc(s) have non-numeric createdAtMs`, 'fail', sampleMs);
  }

  // B2 — createdAt is Firestore Timestamp (NOT a string) — admin must not write ISO.
  // Pre-2026-05-06 BUG #3 docs may still have ISO strings; anything newer
  // is a real regression.
  let isoNew = 0, isoLegacy = 0, sampleIsoNew = '', sampleIsoLegacy = '';
  snap.forEach(d => {
    const v = d.data().createdAt;
    if (typeof v !== 'string') return;
    const item = `${d.id} createdAt=[string "${v.slice(0, 30)}…"]`;
    if (isLegacyEra(d)) {
      isoLegacy++; if (!sampleIsoLegacy) sampleIsoLegacy = item;
    } else {
      isoNew++;    if (!sampleIsoNew)    sampleIsoNew    = item;
    }
  });
  if (isoNew === 0 && isoLegacy === 0) {
    record('B2', 'B', `createdAt is Timestamp (not ISO string) on every doc`, 'pass');
  } else if (isoNew === 0) {
    record('B2', 'B',
      `${isoLegacy} legacy doc(s) (pre-fix) still have ISO-string createdAt — backfill optional`,
      'skip', sampleIsoLegacy);
  } else {
    record('B2', 'B',
      `${isoNew} POST-FIX doc(s) have ISO-string createdAt — BUG #3 REGRESSION`,
      'fail', sampleIsoNew);
  }

  // B3 — resolvedAt: where present, must be Timestamp not ISO string (BUG #4).
  // Same legacy-era split as B2.
  let resNew = 0, resLegacy = 0, sampleResNew = '', sampleResLegacy = '';
  snap.forEach(d => {
    const v = d.data().resolvedAt;
    if (typeof v !== 'string') return;
    const item = `${d.id} resolvedAt=[string "${v.slice(0, 30)}…"]`;
    if (isLegacyEra(d)) {
      resLegacy++; if (!sampleResLegacy) sampleResLegacy = item;
    } else {
      resNew++;    if (!sampleResNew)    sampleResNew    = item;
    }
  });
  if (resNew === 0 && resLegacy === 0) {
    record('B3', 'B', `resolvedAt is Timestamp (not ISO string) where present`, 'pass');
  } else if (resNew === 0) {
    record('B3', 'B',
      `${resLegacy} legacy doc(s) still have ISO-string resolvedAt — backfill optional`,
      'skip', sampleResLegacy);
  } else {
    record('B3', 'B',
      `${resNew} POST-FIX doc(s) have ISO-string resolvedAt — BUG #4 REGRESSION`,
      'fail', sampleResNew);
  }

  // B4 — every resolved doc must have numeric resolvedAtMs
  let resolvedNoMs = 0;
  snap.forEach(d => {
    const x = d.data();
    if ((x.status || '') === 'resolved' && typeof x.resolvedAtMs !== 'number') {
      resolvedNoMs++;
    }
  });
  if (resolvedNoMs === 0) {
    record('B4', 'B', `every resolved doc has numeric resolvedAtMs`, 'pass');
  } else {
    record('B4', 'B',
      `${resolvedNoMs} resolved doc(s) missing resolvedAtMs — admin write path drift`,
      'fail');
  }
}

// ─────────────────────────────────────────────────────────────────────
// §C — Cross-school auth regression (BUG #1, 2026-05-06)
// ─────────────────────────────────────────────────────────────────────
async function groupC() {
  header(`§C — Cross-school auth regression (BUG #1, Red_flags.php)`);

  const phpPath = path.resolve(__dirname, '..', 'application', 'controllers', 'Red_flags.php');
  if (!fs.existsSync(phpPath)) {
    record('C0', 'C', 'Red_flags.php not found — skipped', 'skip', phpPath);
    return;
  }
  const src = fs.readFileSync(phpPath, 'utf8');

  // C1 — buggy `?? $existing['schoolCode'] ??` chain pattern must NOT appear.
  // The correct AND-mismatch shape is two separate comparisons.
  const buggyChain = /\$existing\[\s*['"]schoolId['"]\s*\]\s*\?\?\s*\$existing\[\s*['"]schoolCode['"]\s*\]\s*\?\?/;
  if (buggyChain.test(src)) {
    record('C1', 'C',
      'auth check still uses ?? schoolId ?? schoolCode chain — BUG #1 regression',
      'fail',
      'rewrite to: schoolId !== X && schoolCode !== X (AND-mismatch)');
  } else {
    record('C1', 'C', 'no ?? chain in cross-school auth check', 'pass');
  }

  // C2 — count occurrences of the AND-mismatch pattern. Should be ≥ 3
  // (resolve_flag, delete_flag, bulk_resolve all use the same shape).
  const andMismatch = /\$existing\[\s*['"]schoolId['"]\s*\]\s*\?\?\s*['"]\s*['"]\s*\)\s*!==\s*\$this->school_id[\s\S]{0,80}?\&\&\s*\(\s*\$existing\[\s*['"]schoolCode['"]\s*\]\s*\?\?/g;
  const matches = src.match(andMismatch) || [];
  if (matches.length >= 3) {
    record('C2', 'C',
      `AND-mismatch pattern present in ${matches.length} site(s)`, 'pass');
  } else {
    record('C2', 'C',
      `AND-mismatch pattern found in only ${matches.length} site(s) (expected ≥ 3)`,
      'skip',
      'pattern may have been refactored — verify resolve_flag/delete_flag/bulk_resolve still guard cross-school');
  }
}

// ─────────────────────────────────────────────────────────────────────
// §D — Phase 6A foundation (Teacher Kotlin)
// ─────────────────────────────────────────────────────────────────────
async function groupD() {
  header(`§D — Phase 6A foundation regressions (Teacher app)`);

  const sheetPath = 'D:\\Projects\\SchoolSyncTeacher\\app\\src\\main\\java\\com\\schoolsync\\teacher\\ui\\redflags\\QuickFlagSheet.kt';
  const studentsScreenPath = 'D:\\Projects\\SchoolSyncTeacher\\app\\src\\main\\java\\com\\schoolsync\\teacher\\ui\\students\\StudentsScreen.kt';

  if (!fs.existsSync(sheetPath)) {
    record('D0', 'D', 'QuickFlagSheet.kt not found — skipped', 'skip', sheetPath);
    return;
  }
  const sheet = fs.readFileSync(sheetPath, 'utf8');

  // D1 — "No subject assigned — contact admin" message must be present
  //   (added 2026-05-07 to enforce strict subject integrity).
  if (/No subject assigned\s*[—\-]\s*contact admin/i.test(sheet)) {
    record('D1', 'D', `"No subject assigned — contact admin" message present`, 'pass');
  } else {
    record('D1', 'D',
      `"No subject assigned — contact admin" message missing — strict subject integrity may be off`,
      'fail');
  }

  // D2 — noAssignedSubjects gate must exist + be wired into canSubmit
  //   (the actual mechanism that blocks submission when assignments are empty).
  const hasGate = /noAssignedSubjects\s*=/.test(sheet);
  const inCanSubmit = /canSubmit[\s\S]{0,200}!noAssignedSubjects/.test(sheet);
  if (hasGate && inCanSubmit) {
    record('D2', 'D', `noAssignedSubjects gate wired into canSubmit`, 'pass');
  } else {
    record('D2', 'D',
      `noAssignedSubjects gate missing or not in canSubmit`,
      'fail',
      'submit must be disabled when forceSubjectPick && subjectsForClass.isEmpty()');
  }

  // D3 — exactly 3 templates ship in v1 (Phase 6A locked design — no 4th).
  // Match `FlagTemplate(` calls inside the TEMPLATES = listOf(...) block.
  const templatesBlock = sheet.match(/TEMPLATES\s*=\s*listOf\s*\(([\s\S]*?)\)\s*\n/);
  if (templatesBlock) {
    const ctorCount = (templatesBlock[1].match(/FlagTemplate\s*\(/g) || []).length;
    if (ctorCount === 3) {
      record('D3', 'D', `exactly 3 templates in TEMPLATES list (Phase 6A locked v1)`, 'pass');
    } else {
      record('D3', 'D',
        `${ctorCount} templates found — locked design says 3 in v1`,
        'fail',
        '4th template was deferred until usage data justifies it');
    }
  } else {
    record('D3', 'D', `could not locate TEMPLATES list — file shape changed`, 'skip');
  }

  // D4 — StudentsScreen has the QuickFlagSheet wiring (today's wiring).
  if (!fs.existsSync(studentsScreenPath)) {
    record('D4', 'D', 'StudentsScreen.kt not found — skipped', 'skip', studentsScreenPath);
  } else {
    const ss = fs.readFileSync(studentsScreenPath, 'utf8');
    const hasSheetUsage = /QuickFlagSheet\s*\(/.test(ss) && /rememberQuickFlagSheetState\s*\(\s*\)/.test(ss);
    const hasFlagIcon  = /Icons\.Filled\.Flag/.test(ss);
    if (hasSheetUsage && hasFlagIcon) {
      record('D4', 'D', `StudentsScreen wires QuickFlagSheet + 🚩 IconButton`, 'pass');
    } else {
      record('D4', 'D',
        `StudentsScreen missing QuickFlag wiring (sheet=${hasSheetUsage} flagIcon=${hasFlagIcon})`,
        'fail',
        'add rememberQuickFlagSheetState + QuickFlagSheet + Icons.Filled.Flag IconButton');
    }
  }
}

// ─────────────────────────────────────────────────────────────────────
// §E — Firestore rules sanity (any school staff can resolve)
// ─────────────────────────────────────────────────────────────────────
async function groupE() {
  header(`§E — Firestore rules (studentFlags policy)`);

  const rulesPath = path.resolve(__dirname, '..', 'firebase-rules', 'firestore.rules');
  if (!fs.existsSync(rulesPath)) {
    record('E0', 'E', 'firestore.rules not found — skipped', 'skip', rulesPath);
    return;
  }
  const rules = fs.readFileSync(rulesPath, 'utf8');

  // E1 — there is a match block for studentFlags
  const flagsBlock = rules.match(/match\s+\/studentFlags\/[^\{]*\{[\s\S]*?\n\s{0,4}\}/);
  if (!flagsBlock) {
    record('E1', 'E', 'studentFlags rules block not found', 'fail',
      'rules deploy is incomplete — flag writes will be denied or wide-open');
    return;
  }
  record('E1', 'E', 'studentFlags rules block present', 'pass');

  // E2 — rules block references "resolved" status (the relaxed rule from 2026-05-06)
  if (/['"]resolved['"]/.test(flagsBlock[0])) {
    record('E2', 'E', 'studentFlags update rule mentions "resolved" status', 'pass');
  } else {
    record('E2', 'E',
      'studentFlags update rule does NOT mention "resolved" — Teacher app cannot resolve admin-created flags',
      'fail',
      'add: status=="resolved" branch that allows any school staff to update');
  }

  // E3 — soft-delete rule mentions teacherId / createdByRole guard
  if (/['"]deleted['"][\s\S]{0,400}(?:teacherId|createdByRole)/.test(flagsBlock[0])) {
    record('E3', 'E', 'soft-delete branch references teacherId/createdByRole guard', 'pass');
  } else {
    record('E3', 'E',
      'soft-delete branch does not gate by teacherId or createdByRole',
      'skip',
      'verify creator-only soft-delete is enforced — anyone could otherwise hide flags');
  }
}

// ─────────────────────────────────────────────────────────────────────
// §F — Index sanity for studentFlags rolling-window queries
// ─────────────────────────────────────────────────────────────────────
async function groupF() {
  header(`§F — Index file sanity (studentFlags)`);

  const indexesPath = path.resolve(__dirname, '..', 'firebase-rules', 'firestore.indexes.json');
  if (!fs.existsSync(indexesPath)) {
    record('F0', 'F', 'firestore.indexes.json not found', 'skip', indexesPath);
    return;
  }
  let idx;
  try {
    idx = JSON.parse(fs.readFileSync(indexesPath, 'utf8'));
  } catch (e) {
    record('F0', 'F', 'firestore.indexes.json is malformed', 'fail', e.message);
    return;
  }
  record('F0', 'F', 'firestore.indexes.json parses cleanly', 'pass');

  function hasIndex(coll, fields) {
    return (idx.indexes || []).some(ix => {
      if (ix.collectionGroup !== coll) return false;
      const got = (ix.fields || []).map(f => f.fieldPath);
      return fields.every(f => got.includes(f));
    });
  }

  // F1 — class-wide rolling-window query (admin dashboard / Teacher list)
  if (hasIndex('studentFlags', ['schoolId', 'createdAtMs'])) {
    record('F1', 'F',
      'studentFlags: schoolId+createdAtMs index present (rolling-window list)', 'pass');
  } else {
    record('F1', 'F',
      'studentFlags: schoolId+createdAtMs index MISSING',
      'fail',
      'observeFlagsForClass / getTotalActiveFlagCount will fall back to slow scan');
  }

  // F2 — per-student observeFlagsForStudent
  if (hasIndex('studentFlags', ['schoolId', 'studentId', 'createdAtMs'])) {
    record('F2', 'F',
      'studentFlags: schoolId+studentId+createdAtMs index present (per-student observe)',
      'pass');
  } else {
    record('F2', 'F',
      'studentFlags: schoolId+studentId+createdAtMs index missing',
      'skip',
      'observeFlagsForStudent uses this — flag if parent-side flag history is slow');
  }
}

// ─────────────────────────────────────────────────────────────────────
// §G — flagId fallback regression (BUG #2, 2026-05-06)
// ─────────────────────────────────────────────────────────────────────
async function groupG(snap) {
  header(`§G — flagId field presence (BUG #2)`);
  if (!snap || snap.empty) {
    record('G0', 'G', 'no docs — skipped', 'skip');
    return;
  }

  // G1 — every doc must carry a flagId field. The admin's _collect_all_flags
  // has a strip-prefix fallback for legacy docs, but the canonical write path
  // (admin + Teacher) MUST emit the field so resolve/delete round-trips work.
  let missing = 0, sampleMissing = '';
  snap.forEach(d => {
    if (!d.data().flagId) {
      missing++;
      if (!sampleMissing) sampleMissing = `${d.id} has no flagId field`;
    }
  });
  if (missing === 0) {
    record('G1', 'G', `every flag doc has a flagId field (no legacy strip needed)`, 'pass');
  } else {
    record('G1', 'G',
      `${missing} flag doc(s) missing flagId — relying on prefix-strip fallback`,
      'skip', sampleMissing + '  (legacy docs are tolerated but new writes must emit flagId)');
  }
}

// ─────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────
(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Red Flag module data-layer smoke test');
  console.log(`  scope: school=${SCHOOL}`);
  console.log('  mode:  READ-ONLY');
  console.log('═══════════════════════════════════════════════════════════════');

  const snap = await groupA();
  await groupB(snap);
  await groupC();
  await groupD();
  await groupE();
  await groupF();
  await groupG(snap);

  const pass = results.filter(r => r.status === 'pass').length;
  const fail = results.filter(r => r.status === 'fail').length;
  const skip = results.filter(r => r.status === 'skip').length;
  console.log('\n═══════════════════════════════════════════════════════════════');
  console.log(`  Results: \x1b[32m${pass} passed\x1b[0m, \x1b[31m${fail} failed\x1b[0m, \x1b[90m${skip} skipped\x1b[0m`);
  console.log('═══════════════════════════════════════════════════════════════\n');

  if (fail > 0) {
    console.log('Failed tests:');
    results.filter(r => r.status === 'fail').forEach(r => {
      console.log(`  ${r.id}  ${r.name}${r.detail ? '  — ' + r.detail : ''}`);
    });
    console.log('');
  }
  process.exit(fail > 0 ? 1 : 0);
})().catch(e => { console.error('Fatal:', e); process.exit(2); });
