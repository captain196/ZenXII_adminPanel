// ─────────────────────────────────────────────────────────────────────
//  getRecoveryContact — unauthenticated Gen-2 callable
//
//  The mobile apps (Teacher/Parent) call this from the "Forgot password?"
//  screen with the user's login id (staff id for Teacher, student id for
//  Parent). It resolves the user's school WITHOUT any login and returns
//  ONLY the school's recovery-contact block — no PII, no other fields.
//
//  How the school is resolved (no new collection, no backfill):
//    Every staff/student has a Firebase Auth account keyed by the synthetic
//    email `{userId}@schoolsync.app` with a custom claim holding the school
//    id — the exact same identity the apps use to log in. We read that claim
//    server-side (Admin SDK bypasses Firestore rules) and return the contact.
//
//  Returns: { found: boolean, name, email, number, schoolName }
//  Any failure (blank id, unknown id, no claim, no block) → { found: false }.
// ─────────────────────────────────────────────────────────────────────
const { onCall } = require('firebase-functions/v2/https');
const admin = require('firebase-admin');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();

const EMAIL_DOMAIN = 'schoolsync.app';
const NOT_FOUND = { found: false };

// Each caller (app) may only resolve its own account type. The Teacher app must
// not surface a student/parent/admin contact, and the Parent app must not
// surface a teacher/admin contact. Enforced by the account's role claim; the
// caller passes `audience`. (Older app builds send no audience → no filter, so
// they keep working until updated.)
const AUDIENCE_ROLES = {
  teacher: ['teacher'],
  parent: ['parent', 'student'],
};

exports.getRecoveryContact = onCall({ region: 'us-central1' }, async (request) => {
  // Accounts were created with a lowercased synthetic email — match that.
  const userId = String(request.data && request.data.userId || '').trim().toLowerCase();
  if (!userId) return NOT_FOUND;
  const audience = String(request.data && request.data.audience || '').trim().toLowerCase();

  // 1. userId → Auth user → custom claims (same identity used at login).
  let user;
  try {
    user = await admin.auth().getUserByEmail(`${userId}@${EMAIL_DOMAIN}`);
  } catch (e) {
    return NOT_FOUND; // no such account
  }
  const claims = user.customClaims || {};

  // 1b. Scope to the calling app's account type. Anything outside the
  // audience's allowed roles is treated as not-found (no cross-type leak).
  const role = String(claims.role || '').toLowerCase();
  const allowedRoles = AUDIENCE_ROLES[audience];
  if (allowedRoles && !allowedRoles.includes(role)) return NOT_FOUND;

  // Apps read `school_id` (snake) primary, `schoolId` (camel) fallback — mirror that.
  const schoolId = claims.school_id || claims.schoolId;
  if (!schoolId) return NOT_FOUND;

  // 2. Read the school's recovery block (rules bypassed by Admin SDK).
  let snap;
  try {
    snap = await admin.firestore().doc(`schools/${schoolId}`).get();
  } catch (e) {
    logger.error('getRecoveryContact: school read failed', { schoolId, err: e.message });
    return NOT_FOUND;
  }
  const data = snap.data() || {};
  const c = data.forget_password_details || {};
  const name = String(c.name || '');
  const email = String(c.email || '');
  const number = String(c.number || '');

  // 3. Nothing useful to show → treat as not found.
  if (!name && !email && !number) return NOT_FOUND;

  return {
    found: true,
    name,
    email,
    number,
    schoolName: String(data.schoolName || data.name || ''),
  };
});
