#!/usr/bin/env node
/**
 * One-shot migration — Red Flag RTDB legacy paths → Firestore studentFlags.
 *
 * Source (RTDB legacy paths, scanned for any school):
 *   /Schools/{schoolCode}/{session}/{class}/{section}/RedFlags/{studentId}/{flagId}
 *   /StudentFlags/{schoolCode}/{studentId}/{flagId}            (mobile-app legacy)
 *
 * Destination (Firestore canonical, single source of truth):
 *   /studentFlags/{schoolId}_{flagId}
 *
 * Rules followed:
 *   - Idempotent: re-runs are safe (skip if Firestore doc already exists).
 *   - Non-destructive: RTDB rows are NOT deleted by this script (safety net
 *     during validation). After Firestore is verified correct, run with
 *     --delete-source to purge RTDB.
 *   - Preserves: studentId, schoolId, schoolCode, severity, type, message,
 *     subject, status, createdAtMs, createdBy, teacherId, etc.
 *   - Normalises: severity/type/status to lowercase; createdAt to ISO 8601;
 *     fills missing schoolId from path; coerces string `severity` to enum.
 *
 * Dry-run by default. Use --apply to write to Firestore. Use --apply
 * --delete-source to also purge the RTDB after a verified Firestore write.
 *
 *   node scripts/migrate_redflags_rtdb_to_firestore.js
 *   node scripts/migrate_redflags_rtdb_to_firestore.js --apply
 *   node scripts/migrate_redflags_rtdb_to_firestore.js --apply --delete-source
 */

const path = require('path');

let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({
  credential: admin.credential.cert(sa),
  databaseURL: 'https://graders-1c047-default-rtdb.asia-southeast1.firebasedatabase.app',
});
const fs   = admin.firestore();
const rtdb = admin.database();

const APPLY        = process.argv.includes('--apply');
const DELETE_SRC   = process.argv.includes('--delete-source');
const VERBOSE      = process.argv.includes('--verbose');

const VALID_SEVERITIES = ['low', 'medium', 'high'];
const VALID_TYPES      = ['homework', 'behavior', 'performance'];
const VALID_STATUSES   = ['active', 'resolved', 'deleted'];

const norm = (v, allowed, fallback) => {
  const s = String(v || '').toLowerCase().trim();
  return allowed.includes(s) ? s : fallback;
};

const isoFromMs = (ms) => {
  const n = Number(ms);
  if (!Number.isFinite(n) || n <= 0) return new Date().toISOString();
  return new Date(n).toISOString();
};

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Red Flag RTDB → Firestore migration');
  console.log('  mode: ' + (APPLY ? (DELETE_SRC ? 'APPLY + DELETE-SOURCE' : 'APPLY') : 'DRY-RUN'));
  console.log('═══════════════════════════════════════════════════════════════');

  let scanned = 0, written = 0, skippedExisting = 0, skippedInvalid = 0, sourcesDeleted = 0, errors = 0;

  // ── Path 1: Schools/{school}/{session}/{class}/{section}/RedFlags/{sid}/{fid} ──
  const schoolsRoot = await rtdb.ref('Schools').once('value');
  schoolsRoot.forEach(schoolNode => {
    const schoolId = schoolNode.key;
    schoolNode.forEach(sessionNode => {
      const session = sessionNode.key;
      sessionNode.forEach(classNode => {
        const className = classNode.key;
        classNode.forEach(sectionNode => {
          const section = sectionNode.key;
          const flagsForSection = sectionNode.child('RedFlags');
          if (!flagsForSection.exists()) return;
          flagsForSection.forEach(studentNode => {
            const studentId = studentNode.key;
            studentNode.forEach(flagNode => {
              const flagId = flagNode.key;
              const data   = flagNode.val();
              processFlag({
                source: 'school-path',
                rtdbRef: flagNode.ref,
                schoolId, session, className, section, studentId, flagId, data,
              });
            });
          });
        });
      });
    });
  });

  // ── Path 2: /StudentFlags/{schoolCode}/{studentId}/{flagId} ──
  const mobileRoot = await rtdb.ref('StudentFlags').once('value');
  mobileRoot.forEach(schoolNode => {
    const schoolId = schoolNode.key;
    schoolNode.forEach(studentNode => {
      const studentId = studentNode.key;
      studentNode.forEach(flagNode => {
        const flagId = flagNode.key;
        const data   = flagNode.val();
        processFlag({
          source: 'mobile-path',
          rtdbRef: flagNode.ref,
          schoolId, session: '', className: '', section: '',
          studentId, flagId, data,
        });
      });
    });
  });

  async function processFlag(ctx) {
    scanned++;
    const { source, rtdbRef, schoolId, session, className, section, studentId, flagId, data } = ctx;
    if (!schoolId || !studentId || !flagId || !data || typeof data !== 'object') {
      skippedInvalid++;
      if (VERBOSE) console.log('  ⚠ skip invalid:', source, schoolId, studentId, flagId);
      return;
    }
    const docId = `${schoolId}_${flagId}`;
    const ref = fs.collection('studentFlags').doc(docId);

    try {
      const existing = await ref.get();
      if (existing.exists) {
        skippedExisting++;
        if (VERBOSE) console.log('  ⊘ exists, skip:', docId);
        if (APPLY && DELETE_SRC) { await rtdbRef.remove(); sourcesDeleted++; }
        return;
      }

      const createdAtMs = Number(data.createdAtMs ?? data.timestamp ?? 0) || Date.now();
      const out = {
        flagId,
        schoolId,
        schoolCode:    data.schoolCode || schoolId,
        session:       data.session || session || '',
        studentId,
        studentName:   String(data.studentName  || data.Name        || ''),
        rollNo:        String(data.rollNo       || data.RollNo      || ''),
        fatherName:    String(data.fatherName   || data.FatherName  || ''),
        className:     String(data.className    || className        || ''),
        section:       String(data.section      || section          || ''),
        type:          norm(data.type,     VALID_TYPES,      'behavior'),
        severity:      norm(data.severity, VALID_SEVERITIES, 'low'),
        status:        norm(data.status,   VALID_STATUSES,   'active'),
        message:       String(data.message  || data.Message  || ''),
        subject:       String(data.subject  || data.Subject  || ''),
        teacherId:     String(data.teacherId    || data.createdBy   || ''),
        createdByRole: String(data.createdByRole || 'teacher').toLowerCase(),
        createdAtMs,
        createdAt:     isoFromMs(createdAtMs),
        updatedAt:     new Date().toISOString(),
        // Migration audit fields
        migratedFromRtdb: true,
        migratedAt:       new Date().toISOString(),
        migrationSource:  source,
      };
      // Preserve resolved/deleted markers if present
      if (data.resolvedAtMs) { out.resolvedAtMs = Number(data.resolvedAtMs); out.resolvedAt = isoFromMs(data.resolvedAtMs); }
      if (data.resolvedBy)   out.resolvedBy = String(data.resolvedBy);
      if (data.deletedAtMs)  { out.deletedAtMs  = Number(data.deletedAtMs);  out.deletedAt  = isoFromMs(data.deletedAtMs); }
      if (data.deletedBy)    out.deletedBy  = String(data.deletedBy);

      if (!APPLY) {
        if (VERBOSE) console.log('  → would write:', docId, '(' + out.type + '/' + out.severity + '/' + out.status + ')');
        return;
      }
      await ref.set(out);
      written++;
      if (DELETE_SRC) { await rtdbRef.remove(); sourcesDeleted++; }
      if (VERBOSE) console.log('  ✓ wrote:', docId);
    } catch (e) {
      errors++;
      console.error('  ✗ failed:', docId, '·', e.message);
    }
  }

  console.log('\n═══════════════════════════════════════════════════════════════');
  console.log('  Summary');
  console.log('  scanned RTDB nodes : ' + scanned);
  console.log('  written to FS      : ' + written);
  console.log('  skipped (existing) : ' + skippedExisting);
  console.log('  skipped (invalid)  : ' + skippedInvalid);
  console.log('  RTDB sources purged: ' + sourcesDeleted);
  console.log('  errors             : ' + errors);
  console.log('═══════════════════════════════════════════════════════════════');
  if (!APPLY) console.log('  DRY-RUN — re-run with --apply to commit.');
  process.exit(errors === 0 ? 0 : 1);
})().catch(e => { console.error('FATAL:', e); process.exit(2); });
