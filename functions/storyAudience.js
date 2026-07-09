/**
 * Story audience index — SEC-1 hardening (2026-07-08).
 *
 * Replaces the old client-side-only audience filter (a parent's device
 * downloaded ALL school stories and merely hid other classes locally —
 * trivially bypassable → cross-class exposure of children's media) with a
 * SERVER-maintained entitlement doc that the Firestore stories read rule
 * consults via get().
 *
 *   storyAudience/{parentUid} = {
 *     schoolId, classKeys: ["9-a","10-b"], studentIds: [...], updatedAt
 *   }
 *
 * classKeys is the UNION of the parent's children's canonical class-section
 * tokens. The doc is keyed by the parent's Firebase uid (== the child's
 * studentId under synthetic-email auth), which is exactly what the rule
 * compares against request.auth.uid. It is NEVER client-writable.
 *
 * Two write paths keep it fresh:
 *   1. refreshStoryAudience  — parent-app callable, run on login / app-start
 *      under the caller's own auth context (uid + student_ids claim).
 *   2. onStudentClassChanged — rebuilds every affected parent's entitlement
 *      when an admin promotes / re-sections a student, so class changes
 *      propagate without waiting for the parent to re-open the app.
 */

const admin = require('firebase-admin');
const { onCall, HttpsError } = require('firebase-functions/v2/https');
const { onDocumentWritten } = require('firebase-functions/v2/firestore');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();
const REGION = 'us-central1';

// ─── Canonical class-section token ─────────────────────────────────
// MUST stay byte-equivalent to StorySharedConfig.audienceKey (Kotlin, both
// apps) and Stories.php _audience_key (PHP). "Class 8th"/"8th"/"8" → "8";
// "Section A"/"A" → "a"; ("Class 8th","Section A") → "8-a".
function canonClassToken(raw) {
  let s = String(raw || '').trim().toLowerCase();
  if (s.startsWith('class ')) s = s.slice(6).trim();
  const m = s.match(/^(\d+)(st|nd|rd|th)$/);
  if (m) s = m[1];
  return s;
}
function canonSectionToken(raw) {
  let s = String(raw || '').trim().toLowerCase();
  if (s.startsWith('section ')) s = s.slice(8).trim();
  return s;
}
function audienceKey(className, section) {
  return `${canonClassToken(className)}-${canonSectionToken(section)}`;
}

// Compute the union of class-section keys for a set of student ids within a
// school, reading the authoritative `students/{schoolId}_{studentId}` docs.
async function computeClassKeys(schoolId, studentIds) {
  const keys = new Set();
  for (const sid of studentIds) {
    try {
      const snap = await db.collection('students').doc(`${schoolId}_${sid}`).get();
      if (!snap.exists) continue;
      const d = snap.data() || {};
      if (String(d.schoolId || '') !== schoolId) continue; // tenant guard
      const k = audienceKey(d.className || '', d.section || '');
      if (k && k !== '-') keys.add(k); // "-" == blank class AND section
    } catch (e) {
      logger.warn(`[story-audience] student ${sid} read failed: ${e.message}`);
    }
  }
  return [...keys];
}

// ─── Path 1: parent-app callable ───────────────────────────────────
exports.refreshStoryAudience = onCall({ region: REGION }, async (request) => {
  const auth = request.auth;
  if (!auth) throw new HttpsError('unauthenticated', 'Sign-in required.');
  const uid = auth.uid;
  const token = auth.token || {};
  const schoolId = String(token.school_id || token.schoolId || '');
  if (!schoolId) throw new HttpsError('failed-precondition', 'No school in token.');

  // student_ids claim = the parent's linked children; fall back to the
  // singular student_id, then to uid (parent auth uid == primary studentId).
  let studentIds = token.student_ids;
  if (!Array.isArray(studentIds) || studentIds.length === 0) {
    studentIds = token.student_id ? [String(token.student_id)] : [uid];
  }
  studentIds = [...new Set(studentIds.map(String).filter(Boolean))];

  const classKeys = await computeClassKeys(schoolId, studentIds);
  await db.collection('storyAudience').doc(uid).set({
    schoolId,
    classKeys,
    studentIds,
    updatedAt: admin.firestore.FieldValue.serverTimestamp(),
  });

  logger.info(`[story-audience] refresh ${uid} → [${classKeys.join(',')}]`);
  return { classKeys };
});

// ─── Path 2: rebuild on student class/section change ───────────────
exports.onStudentClassChanged = onDocumentWritten(
  { document: 'students/{docId}', region: REGION },
  async (event) => {
    const before = event.data && event.data.before && event.data.before.exists ? event.data.before.data() : {};
    const after  = event.data && event.data.after  && event.data.after.exists  ? event.data.after.data()  : null;
    if (!after) return; // deletion — stale entitlement ages out / next refresh fixes it
    if (before.className === after.className && before.section === after.section) return;

    const sid = String(after.studentId || after.userId || '');
    const schoolId = String(after.schoolId || '');
    if (!sid || !schoolId) return;

    // Rebuild every parent entitlement doc that includes this student.
    const q = await db.collection('storyAudience').where('studentIds', 'array-contains', sid).get();
    for (const doc of q.docs) {
      const data = doc.data() || {};
      const studentIds = Array.isArray(data.studentIds) && data.studentIds.length
        ? data.studentIds.map(String)
        : [sid];
      const classKeys = await computeClassKeys(schoolId, studentIds);
      await doc.ref.set({
        classKeys,
        updatedAt: admin.firestore.FieldValue.serverTimestamp(),
      }, { merge: true });
      logger.info(`[story-audience] rebuild ${doc.id} → [${classKeys.join(',')}]`);
    }
  }
);
