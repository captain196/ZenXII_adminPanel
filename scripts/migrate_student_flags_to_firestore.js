#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Student Flags Migration (Phase B)
 *
 *  Source A (admin writes — PascalCase enums):
 *    Schools/{schoolId}/{session}/{classKey}/{sectionKey}/RedFlags/{studentId}/{flagId}
 *
 *  Source B (mobile teacher writes — lowercase enums):
 *    StudentFlags/{schoolCode}/{studentId}/{flagId}
 *
 *  Target:
 *    Firestore collection `studentFlags`, doc ID `{schoolId}_{flagId}`,
 *    canonical camelCase + lowercase enums.
 *
 *  IDEMPOTENT — re-running with the same input is a no-op (deterministic
 *  doc IDs + merge writes).
 *  NON-DESTRUCTIVE — does not delete RTDB data.
 *
 *  Usage:
 *    node scripts/migrate_student_flags_to_firestore.js              # dry-run
 *    node scripts/migrate_student_flags_to_firestore.js --apply      # write
 *    node scripts/migrate_student_flags_to_firestore.js --apply --school SCH_X
 *    node scripts/migrate_student_flags_to_firestore.js --source A   # admin only
 *    node scripts/migrate_student_flags_to_firestore.js --source B   # mobile only
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

const args = process.argv.slice(2);
const APPLY         = args.includes('--apply');
const DRY_RUN       = !APPLY;
const FILTER_SCHOOL = args.includes('--school')
  ? args[args.indexOf('--school') + 1] : null;
const SOURCE_FILTER = args.includes('--source')
  ? String(args[args.indexOf('--source') + 1]).toUpperCase() : 'BOTH';

const serviceAccount = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({
  credential:  admin.credential.cert(serviceAccount),
  databaseURL: DATABASE_URL,
});
const rtdb = admin.database();
const fs   = admin.firestore();

// ── Styling ────────────────────────────────────────────────────────
const FIXED   = '\x1b[32m+ MIGRATED\x1b[0m';
const SKIPPED = '\x1b[90m- SKIPPED \x1b[0m';
const ERROR   = '\x1b[31m! ERROR   \x1b[0m';
const DRY     = '\x1b[35m~ DRY-RUN \x1b[0m';
const BOLD    = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM     = (s) => `\x1b[2m${s}\x1b[0m`;
const CYAN    = (s) => `\x1b[36m${s}\x1b[0m`;
const HR  = '-'.repeat(70);
const HR2 = '='.repeat(70);

const stats = {
  schoolsProcessed: 0,
  sourceAFlags:     0,
  sourceBFlags:     0,
  fsCreated:        0,
  fsUpdated:        0,
  fsSkipped:        0,
  errors:           0,
};

// ── Helpers ────────────────────────────────────────────────────────

async function rtdbGet(p) {
  try { return (await rtdb.ref(p).once('value')).val(); }
  catch (_) { return null; }
}

const VALID_TYPES      = new Set(['homework', 'behavior', 'performance']);
const VALID_SEVERITIES = new Set(['low', 'medium', 'high']);
const VALID_STATUSES   = new Set(['active', 'resolved']);

function lc(v, fallback, valid) {
  const s = String(v ?? '').trim().toLowerCase();
  if (valid && !valid.has(s)) return fallback;
  return s || fallback;
}

function toMs(v) {
  if (v == null) return 0;
  if (typeof v === 'number') {
    // RTDB stores some timestamps in seconds, others in ms
    return v < 1e12 ? v * 1000 : v;
  }
  const n = Number(v);
  return Number.isFinite(n) ? (n < 1e12 ? n * 1000 : n) : 0;
}

function ensureClassPrefix(s) {
  const v = String(s ?? '').trim();
  if (!v) return '';
  return /^Class\s/i.test(v) ? v : `Class ${v}`;
}

function ensureSectionPrefix(s) {
  const v = String(s ?? '').trim();
  if (!v) return '';
  return /^Section\s/i.test(v) ? v : `Section ${v}`;
}

function buildDocId(schoolId, flagId) {
  return `${schoolId}_${flagId}`;
}

function flagDocsEqual(a, b) {
  const fields = [
    'flagId','schoolId','schoolCode','session','studentId','studentName',
    'rollNo','fatherName','className','section','type','severity','status',
    'message','subject','teacherId','teacherName','createdAtMs',
    'resolvedAtMs','resolvedBy','hwId',
  ];
  for (const f of fields) {
    const va = a[f] ?? null;
    const vb = b[f] ?? null;
    if ((va == null && vb == null)) continue;
    if (String(va) !== String(vb)) return false;
  }
  return true;
}

// ── Resolve school_code <-> school_id ──────────────────────────────

async function buildCodeMaps() {
  const idx = (await rtdbGet('Indexes/School_codes')) || {};
  const codeToId = {};   // "10001" -> "SCH_xxx"
  const idToCode = {};   // "SCH_xxx" -> "10001"
  for (const [k, v] of Object.entries(idx)) {
    if (typeof v !== 'string') continue;
    if (k.startsWith('SCH_')) {
      idToCode[k] = v;
      codeToId[v] = k;
    } else {
      codeToId[k] = v;
      idToCode[v] = k;
    }
  }
  return { codeToId, idToCode };
}

// ── Cache student profile lookups ──────────────────────────────────

const studentCache = new Map();
async function getStudentProfile(schoolId, session, classKey, sectionKey, studentId) {
  const cacheKey = `${schoolId}|${studentId}`;
  if (studentCache.has(cacheKey)) return studentCache.get(cacheKey);

  // Try the path-known location first (cheapest)
  let data = null;
  if (session && classKey && sectionKey) {
    data = await rtdbGet(`Schools/${schoolId}/${session}/${classKey}/${sectionKey}/Students/${studentId}`);
  }

  // Fallback: scan classes/sections for current session if path-known fails.
  // Needed for Source B (mobile) where we don't know class/section.
  if (!data && session) {
    const sessionNode = await rtdbGet(`Schools/${schoolId}/${session}`);
    if (sessionNode && typeof sessionNode === 'object') {
      outer: for (const [ck, sections] of Object.entries(sessionNode)) {
        if (!ck.startsWith('Class ') || !sections || typeof sections !== 'object') continue;
        for (const [sk, sectionNode] of Object.entries(sections)) {
          if (!sk.startsWith('Section ') || !sectionNode || typeof sectionNode !== 'object') continue;
          const stu = sectionNode.Students && sectionNode.Students[studentId];
          if (stu && typeof stu === 'object') {
            data = stu;
            // also remember class/section for downstream use
            data.__classKey   = ck;
            data.__sectionKey = sk;
            break outer;
          }
        }
      }
    }
  }

  const profile = data ? {
    name:       data.Name       || data.name       || '',
    rollNo:     data.RollNo     || data.rollNo     || '',
    fatherName: data.FatherName || data.fatherName || '',
    classKey:   data.__classKey   || ensureClassPrefix(classKey   || data.Class    || data.className || ''),
    sectionKey: data.__sectionKey || ensureSectionPrefix(sectionKey || data.Section || data.section || ''),
  } : null;
  studentCache.set(cacheKey, profile);
  return profile;
}

// ── Build canonical Firestore doc ──────────────────────────────────

function buildFlagDoc({
  schoolId, schoolCode, session, classKey, sectionKey,
  studentId, studentName, rollNo, fatherName, flagId, raw, sourceLabel,
}) {
  return {
    flagId,
    schoolId,
    schoolCode: schoolCode || schoolId,
    session:    session || '',
    studentId:  String(studentId || ''),
    studentName: String(studentName || raw.studentName || ''),
    rollNo:     String(rollNo || ''),
    fatherName: String(fatherName || ''),
    className:  ensureClassPrefix(classKey   || raw.className || ''),
    section:    ensureSectionPrefix(sectionKey || raw.section || ''),
    type:       lc(raw.type,     'behavior', VALID_TYPES),
    severity:   lc(raw.severity, 'low',      VALID_SEVERITIES),
    status:     lc(raw.status,   'active',   VALID_STATUSES),
    message:    String(raw.message || ''),
    subject:    String(raw.subject || ''),
    teacherId:  String(raw.teacherId || ''),
    teacherName: String(raw.teacherName || ''),
    createdAtMs: toMs(raw.createdAt ?? raw.timestamp ?? raw.createdAtMs ?? 0),
    createdAt:   admin.firestore.FieldValue.serverTimestamp(),
    updatedAt:   admin.firestore.FieldValue.serverTimestamp(),
    resolvedAtMs: raw.resolvedAt != null ? toMs(raw.resolvedAt)
                : raw.resolvedAtMs != null ? toMs(raw.resolvedAtMs) : null,
    resolvedAt:   null, // server-side timestamp added on resolve only
    resolvedBy:   raw.resolvedBy || null,
    hwId:         raw.hwId || null,
    migratedFrom: sourceLabel,
    migratedAt:   admin.firestore.FieldValue.serverTimestamp(),
  };
}

// ── Idempotent write ──────────────────────────────────────────────

async function writeFlag(docId, newDoc, label) {
  const ref = fs.collection('studentFlags').doc(docId);
  let existing = null;
  try {
    const snap = await ref.get();
    existing = snap.exists ? snap.data() : null;
  } catch (e) {
    console.log(`       ${ERROR}  read ${docId}: ${e.message}`);
    stats.errors++;
    return;
  }

  if (existing && flagDocsEqual(existing, newDoc)) {
    stats.fsSkipped++;
    return;
  }

  if (DRY_RUN) {
    console.log(`       ${DRY}  ${docId}  ${DIM(label)}`);
    if (existing) stats.fsUpdated++; else stats.fsCreated++;
    return;
  }

  try {
    await ref.set(newDoc, { merge: true });
    if (existing) {
      stats.fsUpdated++;
      console.log(`       ${FIXED}  ${docId}  ${DIM('(updated, ' + label + ')')}`);
    } else {
      stats.fsCreated++;
      console.log(`       ${FIXED}  ${docId}  ${DIM('(created, ' + label + ')')}`);
    }
  } catch (e) {
    console.log(`       ${ERROR}  write ${docId}: ${e.message}`);
    stats.errors++;
  }
}

// ── SOURCE A — admin RTDB (per-class RedFlags) ─────────────────────

async function processSourceA(schoolId, schoolCode) {
  const schoolNode = await rtdbGet(`Schools/${schoolId}`);
  if (!schoolNode || typeof schoolNode !== 'object') return;

  const sessions = Object.keys(schoolNode).filter(k => /^\d{4}-\d{2}$/.test(k));
  for (const session of sessions) {
    const sessionNode = schoolNode[session];
    if (!sessionNode || typeof sessionNode !== 'object') continue;

    for (const [classKey, sections] of Object.entries(sessionNode)) {
      if (!classKey.startsWith('Class ')) continue;
      if (!sections || typeof sections !== 'object') continue;

      for (const [sectionKey, sectionNode] of Object.entries(sections)) {
        if (!sectionKey.startsWith('Section ')) continue;
        const redFlags = sectionNode && sectionNode.RedFlags;
        if (!redFlags || typeof redFlags !== 'object') continue;

        for (const [studentId, flags] of Object.entries(redFlags)) {
          if (!flags || typeof flags !== 'object') continue;

          const profile = await getStudentProfile(schoolId, session, classKey, sectionKey, studentId);

          for (const [flagId, raw] of Object.entries(flags)) {
            if (!raw || typeof raw !== 'object') continue;
            stats.sourceAFlags++;

            const doc = buildFlagDoc({
              schoolId, schoolCode, session, classKey, sectionKey,
              studentId,
              studentName: profile?.name,
              rollNo:      profile?.rollNo,
              fatherName:  profile?.fatherName,
              flagId, raw, sourceLabel: 'A:admin-rtdb',
            });

            await writeFlag(buildDocId(schoolId, flagId), doc,
              `A ${classKey}/${sectionKey} ${studentId}`);
          }
        }
      }
    }
  }
}

// ── SOURCE B — mobile RTDB (top-level StudentFlags) ───────────────

async function processSourceB(schoolId, schoolCode, codeToId) {
  // Mobile wrote under StudentFlags/{schoolCode-or-schoolId}. Try both.
  const candidates = new Set();
  if (schoolCode) candidates.add(schoolCode);
  candidates.add(schoolId);

  // Also probe top-level keys to catch unmapped schoolCodes
  for (const key of candidates) {
    const node = await rtdbGet(`StudentFlags/${key}`);
    if (!node || typeof node !== 'object') continue;

    // Pick the most recent active session for student-profile lookups
    const schoolNode = await rtdbGet(`Schools/${schoolId}`);
    let session = '';
    if (schoolNode) {
      const sessions = Object.keys(schoolNode).filter(k => /^\d{4}-\d{2}$/.test(k));
      sessions.sort();
      session = sessions[sessions.length - 1] || '';
    }

    for (const [studentId, flags] of Object.entries(node)) {
      if (!flags || typeof flags !== 'object') continue;
      const profile = await getStudentProfile(schoolId, session, null, null, studentId);

      for (const [flagId, raw] of Object.entries(flags)) {
        if (!raw || typeof raw !== 'object') continue;
        stats.sourceBFlags++;

        const doc = buildFlagDoc({
          schoolId, schoolCode, session,
          classKey:   profile?.classKey   || raw.className || '',
          sectionKey: profile?.sectionKey || raw.section   || '',
          studentId,
          studentName: profile?.name       || raw.studentName,
          rollNo:      profile?.rollNo     || '',
          fatherName:  profile?.fatherName || '',
          flagId, raw, sourceLabel: 'B:mobile-rtdb',
        });

        await writeFlag(buildDocId(schoolId, flagId), doc,
          `B ${studentId}`);
      }
    }
  }
}

// ── Main ──────────────────────────────────────────────────────────

async function processSchool(schoolId, codeToId, idToCode) {
  console.log(`\n  ${HR}\n  ${BOLD(CYAN(schoolId))}\n  ${HR}`);
  stats.schoolsProcessed++;
  const schoolCode = idToCode[schoolId] || '';

  if (SOURCE_FILTER === 'BOTH' || SOURCE_FILTER === 'A') {
    console.log(`     ${DIM('Source A — admin RTDB (Schools/.../RedFlags)')}`);
    await processSourceA(schoolId, schoolCode);
  }
  if (SOURCE_FILTER === 'BOTH' || SOURCE_FILTER === 'B') {
    console.log(`     ${DIM('Source B — mobile RTDB (StudentFlags/...)')}`);
    await processSourceB(schoolId, schoolCode, codeToId);
  }
}

async function main() {
  console.log(`\n${HR2}`);
  console.log(BOLD('  Student Flags Migration (Phase B)'));
  console.log(`  ${DIM('RTDB → Firestore studentFlags collection')}`);
  console.log(DRY_RUN
    ? `  ${BOLD('\x1b[35mDRY-RUN MODE — no writes will be made\x1b[0m')}`
    : `  ${BOLD('\x1b[32mAPPLY MODE — writing to Firestore\x1b[0m')}`);
  console.log(`  Source filter: ${BOLD(SOURCE_FILTER)}`);
  if (FILTER_SCHOOL) console.log(`  School filter: ${BOLD(FILTER_SCHOOL)}`);
  console.log(HR2);

  const { codeToId, idToCode } = await buildCodeMaps();

  const systemSchools = await rtdbGet('System/Schools');
  if (!systemSchools) {
    console.log(`\n  ${ERROR}  No schools found at System/Schools`);
    process.exit(1);
  }

  let schoolIds = Object.keys(systemSchools);
  if (FILTER_SCHOOL) {
    if (!schoolIds.includes(FILTER_SCHOOL)) {
      console.log(`\n  ${ERROR}  School "${FILTER_SCHOOL}" not in System/Schools.`);
      process.exit(1);
    }
    schoolIds = [FILTER_SCHOOL];
  }
  console.log(`\n  Processing ${CYAN(String(schoolIds.length))} school(s)`);

  for (const id of schoolIds) {
    try { await processSchool(id, codeToId, idToCode); }
    catch (e) {
      console.log(`\n  ${ERROR}  School ${id} failed: ${e.message}`);
      stats.errors++;
    }
  }

  console.log(`\n${HR2}\n  ${BOLD('SUMMARY')}\n${HR2}`);
  console.log(`  Schools processed:  ${stats.schoolsProcessed}`);
  console.log(`  Source A flags:     ${stats.sourceAFlags}`);
  console.log(`  Source B flags:     ${stats.sourceBFlags}`);
  console.log(`  Firestore created:  ${stats.fsCreated}`);
  console.log(`  Firestore updated:  ${stats.fsUpdated}`);
  console.log(`  Firestore skipped:  ${stats.fsSkipped} ${DIM('(already in canonical form)')}`);
  console.log(`  Errors:             ${stats.errors}`);
  console.log(HR2);

  if (DRY_RUN) {
    console.log(`\n  ${DIM('Re-run with --apply to actually write to Firestore.')}`);
  }

  process.exit(stats.errors > 0 ? 2 : 0);
}

main().catch(e => {
  console.error('\nFATAL:', e);
  process.exit(1);
});
