#!/usr/bin/env node
/**
 * Phase 0.2B Check B — examSchedule subjectCode-mapping inspection.
 * STRICTLY READ-ONLY. Writes nothing. No .set/.update/.delete calls exist
 * anywhere in this file — verify that claim by grepping this file for
 * those method names before trusting it, the same way you would any
 * other diagnostic script in this directory.
 *
 * Purpose: determine whether existing `examSchedule` documents' subject
 * entries (subjectName only, per Phase 0.2A's finding — no subjectCode
 * field exists in the schema today) can be deterministically mapped to
 * the canonical `subjects.subjectCode` for the SAME class, using the
 * exact same canonical source Exam.php::_build_subjects_map() and
 * School_config.php's save_subject already use — no new mapping rule is
 * invented here.
 *
 * TEMPORARY diagnostic tooling — not a product feature. Not committed
 * unless separately authorized. Mirrors scripts/uat_inspect_run.js's
 * pattern (firebase-admin, service-account cert, read-only).
 *
 *   SCHOOL_ID=SCH_B56BB9A401 SESSION_YEAR=2026-27 \
 *     node scripts/p02b_examschedule_subjectcode_inspect.js
 */
const admin = require('firebase-admin');
const path = require('path');

const SA = path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
admin.initializeApp({ credential: admin.credential.cert(require(SA)) });
const db = admin.firestore();

const SCHOOL_ID    = process.env.SCHOOL_ID    || 'SCH_B56BB9A401';
const SESSION_YEAR = process.env.SESSION_YEAR || '2026-27';

const line = (c = '=') => console.log(c.repeat(78));
const norm = (s) => String(s || '').trim().toLowerCase();

// Mirrors Exam.php::_subject_class_key() EXACTLY (application/controllers/
// Exam.php, verified by direct read) — examSchedule.className is a display
// label ("Class 3rd"); subjects.classKey is the bare canonical key ("3").
// Using anything other than this exact function would produce a false
// "unmapped" result that reflects a bug in THIS script, not a real data
// problem — this function exists specifically so that risk is eliminated.
function subjectClassKey(className) {
  const l = norm(className);
  if (l.indexOf('nursery') !== -1) return 'Nursery';
  if (l.indexOf('lkg') !== -1) return 'LKG';
  if (l.indexOf('ukg') !== -1) return 'UKG';
  if (l.indexOf('playgroup') !== -1 || l.indexOf('play') !== -1) return 'Playgroup';
  const m = className.match(/\d+/);
  if (m) return String(parseInt(m[0], 10));
  return '';
}

(async () => {
  console.log('\nPhase 0.2B Check B — examSchedule subjectCode mapping inspection (READ-ONLY)');
  console.log(`Scope: schoolId=${SCHOOL_ID}  session=${SESSION_YEAR}`);
  line();

  // ── 1) Load canonical subject master (same collection Exam.php/School_config.php read) ──
  const subjSnap = await db.collection('subjects')
    .where('schoolId', '==', SCHOOL_ID)
    .get();

  // classKey -> { nameLower -> [subjectCode,...] }   (mirrors Subject_master_verify.php's own duplicate-detection map)
  const canonicalByClassName = {};
  // classKey -> Set(subjectCode)  (for examSchedule.subjectCode cross-check, item 11)
  const canonicalCodesByClass = {};
  let subjectMasterDocs = 0;
  subjSnap.forEach((d) => {
    subjectMasterDocs++;
    const data = d.data();
    const classKey = String(data.classKey || '');
    const code = String(data.subjectCode ?? data.code ?? '');
    const name = String(data.name ?? data.subject_name ?? '');
    if (!classKey || !name) return;
    const nl = norm(name);
    canonicalByClassName[classKey] = canonicalByClassName[classKey] || {};
    canonicalByClassName[classKey][nl] = canonicalByClassName[classKey][nl] || [];
    if (code) canonicalByClassName[classKey][nl].push(code);
    canonicalCodesByClass[classKey] = canonicalCodesByClass[classKey] || new Set();
    if (code) canonicalCodesByClass[classKey].add(code);
  });
  console.log(`\nCanonical subject-master docs loaded (for cross-reference): ${subjectMasterDocs}`);

  // ── 2) Load examSchedule documents for this tenant ──
  const schedSnap = await db.collection('examSchedule')
    .where('schoolId', '==', SCHOOL_ID)
    .get();

  let docsInspected = 0;
  let entriesInspected = 0;
  let withSubjectName = 0, missingSubjectName = 0;
  let withSubjectCodeField = 0, missingSubjectCodeField = 0; // any of subjectCode/code/subject_code already present
  let deterministic = 0, ambiguous = 0, unmapped = 0, staleLegacy = 0, caseWhitespaceOnly = 0;
  let codeFieldMismatch = 0;
  const anomalies = [];
  const sessionMismatchDocs = [];

  schedSnap.forEach((docSnap) => {
    docsInspected++;
    const data = docSnap.data();
    const docId = docSnap.id;
    const rawClassName = String(data.className || '');
    const classKey = subjectClassKey(rawClassName); // normalized, matches subjects.classKey
    const session = String(data.session || '');
    if (session && session !== SESSION_YEAR) sessionMismatchDocs.push(docId);

    const subjects = Array.isArray(data.subjects) ? data.subjects : [];
    subjects.forEach((entry, idx) => {
      entriesInspected++;
      const subjName = String(entry.subjectName ?? '').trim();
      const existingCodeField = String(entry.subjectCode ?? entry.code ?? entry.subject_code ?? '');

      if (subjName) withSubjectName++; else missingSubjectName++;
      if (existingCodeField) withSubjectCodeField++; else missingSubjectCodeField++;

      if (!subjName) {
        anomalies.push({ docId, idx, classKey, rawClassName, subjectName: subjName, reason: 'MISSING_SUBJECT_NAME' });
        return;
      }

      const nl = norm(subjName);
      const classMap = canonicalByClassName[classKey] || {};
      const exactCodes = classMap[nl] || [];

      // Case/whitespace-only variant check: does a differently-cased/spaced
      // key in the SAME class map to the same underlying code set?
      let caseVariantCodes = null;
      if (exactCodes.length === 0) {
        const variantKey = Object.keys(classMap).find((k) => k.replace(/\s+/g, '') === nl.replace(/\s+/g, ''));
        if (variantKey) caseVariantCodes = classMap[variantKey];
      }

      if (exactCodes.length === 1) {
        deterministic++;
        if (existingCodeField && existingCodeField !== exactCodes[0]) {
          codeFieldMismatch++;
          anomalies.push({
            docId, idx, classKey, subjectName: subjName,
            reason: 'EXISTING_CODE_FIELD_MISMATCH',
            existingCodeField, canonicalCode: exactCodes[0],
          });
        }
      } else if (exactCodes.length > 1) {
        ambiguous++;
        anomalies.push({
          docId, idx, classKey, subjectName: subjName,
          reason: 'AMBIGUOUS_MULTIPLE_CODES', candidateCodes: exactCodes,
        });
      } else if (caseVariantCodes && caseVariantCodes.length >= 1) {
        caseWhitespaceOnly++;
        anomalies.push({
          docId, idx, classKey, subjectName: subjName,
          reason: 'CASE_OR_WHITESPACE_VARIANT_ONLY', candidateCodes: caseVariantCodes,
        });
      } else {
        // No match at all in the current live subject master for this class.
        unmapped++;
        staleLegacy++;
        anomalies.push({
          docId, idx, classKey, subjectName: subjName,
          reason: 'UNMAPPED_NOT_IN_CURRENT_MASTER',
        });
      }
    });
  });

  // ── 3) Report ──
  line();
  console.log('\n─── Volume ───');
  console.log(`examSchedule documents inspected: ${docsInspected}`);
  console.log(`subject entries inspected: ${entriesInspected}`);
  if (sessionMismatchDocs.length) {
    console.log(`\nNOTE: ${sessionMismatchDocs.length} inspected doc(s) have a session field != ${SESSION_YEAR} (inspected anyway, schoolId-scoped query only): ${sessionMismatchDocs.slice(0, 10).join(', ')}${sessionMismatchDocs.length > 10 ? ', …' : ''}`);
  }

  console.log('\n─── Field presence ───');
  console.log(`subjectName present: ${withSubjectName}`);
  console.log(`subjectName missing: ${missingSubjectName}`);
  console.log(`subjectCode-shaped field (subjectCode/code/subject_code) present: ${withSubjectCodeField}`);
  console.log(`subjectCode-shaped field missing: ${missingSubjectCodeField}`);

  console.log('\n─── Mapping to canonical subjects.subjectCode ───');
  console.log(`deterministic mappings (exactly one canonical code for this class+name): ${deterministic}`);
  console.log(`ambiguous mappings (multiple canonical codes for this class+name): ${ambiguous}`);
  console.log(`unmapped subjects (no match in current live master for this class): ${unmapped}`);
  console.log(`stale/legacy subjects (no longer in current master): ${staleLegacy}`);
  console.log(`case/whitespace-only variants (matched only after normalizing): ${caseWhitespaceOnly}`);
  console.log(`existing subjectCode-shaped field that MISMATCHES canonical: ${codeFieldMismatch}`);

  console.log(`\n─── Anomalies (${anomalies.length} total) ───`);
  anomalies.slice(0, 200).forEach((a) => {
    const extra = a.candidateCodes ? ` candidates=[${a.candidateCodes.join(',')}]`
      : (a.existingCodeField !== undefined ? ` existingField="${a.existingCodeField}" canonical="${a.canonicalCode}"` : '');
    console.log(`  - [${a.reason}] doc=${a.docId} entry#${a.idx} class="${a.classKey}" subjectName="${a.subjectName}"${extra}`);
  });
  if (anomalies.length > 200) console.log(`  … and ${anomalies.length - 200} more (truncated for output size).`);

  console.log('\n─── Verdict ───');
  const materialAnomalies = ambiguous + unmapped + codeFieldMismatch; // case/whitespace variants are deterministic-with-normalization, not material
  if (entriesInspected === 0) {
    console.log('⚪ NO DATA — no examSchedule subject entries found in scope; nothing to assess.');
  } else if (materialAnomalies === 0) {
    console.log(`✅ CLEAN — all ${entriesInspected} subject entries deterministically map to a canonical subjectCode (${caseWhitespaceOnly} needed case/whitespace normalization, which is itself deterministic).`);
    console.log('A deterministic backfill appears possible for all inspected records.');
  } else {
    console.log(`🛑 MATERIAL ANOMALIES FOUND — ${materialAnomalies} entries do not deterministically map (ambiguous=${ambiguous}, unmapped=${unmapped}, codeFieldMismatch=${codeFieldMismatch}).`);
    console.log('Deterministic backfill is NOT safely possible for all records without human review of the anomalies listed above.');
  }

  console.log('\n=== End inspection — no writes were performed ===');
})().catch((e) => {
  console.error('ERROR during inspection:', e.message);
  process.exit(1);
});
