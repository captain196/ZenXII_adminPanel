#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  backfill_forget_password_details.js
 *
 *  Backfills the `forget_password_details` map onto every existing
 *  schools/{schoolId} doc, so the forgot-password (password-recovery) flow can
 *  read the school super admin's recovery contact directly off the school doc
 *  — matching what B2_registry_service::create_tenant() now writes for newly
 *  onboarded schools.
 *
 *  Shape written:
 *    schools/{schoolId}.forget_password_details = {
 *      name:   <ssa name>,
 *      email:  <ssa email>,
 *      number: <ssa phone>,
 *    }
 *  (No `id` field — the school's primarySsaId is the SSA identity. Docs written
 *   by an earlier version of this script carried an `id` subfield; this pass
 *   rewrites them to drop it.)
 *
 *  Source of truth, in priority order:
 *    name   ← schoolSsa/{ssaId}.name   → staff/{schoolId}_{ssaId}.name → ''
 *    email  ← schoolSsa/{ssaId}.email  → staff/{schoolId}_{ssaId}.email → ''
 *    number ← schoolSsa/{ssaId}.phone  → staff Profile.phone → schools.phone → ''
 *             (older SSAs predate the Admin-Phone wizard field, so phone is
 *              usually empty on schoolSsa — we fall back to the best available
 *              contact number; the SSA can correct it from School Config.)
 *
 *  Safety:
 *    - Dry-run by DEFAULT. Pass --commit to write.
 *    - Idempotent: merge write of a single field; re-runs converge.
 *    - Fill-if-empty per field: skips forget_password_details that's already set
 *      and skips schoolSsa.phone that's already set (unless --force) — never
 *      clobbers a hand-corrected value.
 *    - Reads only Firestore (schools / schoolSsa / staff). Writes only:
 *        schools/{id}.forget_password_details  and  schoolSsa/{ssaId}.phone
 *      (the latter syncs the canonical SSA doc for pre-existing schools whose
 *       phone predates the onboarding Admin-Phone field). Only under --commit.
 *    - NEVER touches RTDB / Mongo / Firebase Auth.
 *
 *  Usage:
 *    node scripts/backfill_forget_password_details.js                 # dry-run
 *    node scripts/backfill_forget_password_details.js --commit        # write
 *    node scripts/backfill_forget_password_details.js --only=SCH_XXXX # one tenant
 *    node scripts/backfill_forget_password_details.js --commit --force # overwrite existing blocks
 * ═══════════════════════════════════════════════════════════════════════════
 */
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }

const SERVICE_ACCOUNT_PATH = path.resolve(
  __dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
);

function parseArg(name) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  if (eq) return eq.split('=').slice(1).join('=');
  return null;
}
const COMMIT = process.argv.includes('--commit');
const FORCE  = process.argv.includes('--force');
const ONLY   = parseArg('only'); // optional single SCH_ id

const sa = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fsdb = admin.firestore();

const firstNonEmpty = (...vals) => {
  for (const v of vals) {
    if (v !== undefined && v !== null && String(v).trim() !== '') return String(v).trim();
  }
  return '';
};

const isFilledBlock = (b) =>
  b && typeof b === 'object' &&
  (firstNonEmpty(b.name) !== '' || firstNonEmpty(b.email) !== '' || firstNonEmpty(b.number) !== '');
// Legacy docs may carry a now-removed `id` subfield — flag them for cleanup.
const hasStaleId = (b) => b && typeof b === 'object' && Object.prototype.hasOwnProperty.call(b, 'id');

(async () => {
  const mode = COMMIT ? (FORCE ? 'COMMIT + FORCE (overwrite)' : 'COMMIT (fill-if-empty)') : 'DRY-RUN (no writes)';
  console.log('=== backfill forget_password_details ===');
  console.log('MODE :', mode);
  if (ONLY) console.log('ONLY :', ONLY);
  console.log('');

  let snap;
  if (ONLY) {
    const doc = await fsdb.collection('schools').doc(ONLY).get();
    snap = { docs: doc.exists ? [doc] : [], empty: !doc.exists };
  } else {
    snap = await fsdb.collection('schools').get();
  }

  let planned = 0, written = 0, phoneWritten = 0, skippedFilled = 0, skippedNoSsa = 0;

  for (const doc of snap.docs) {
    const schoolId = doc.id;

    // schools/{id}_profile are HR/comm counter stores, not tenant docs — skip.
    if (schoolId.endsWith('_profile')) continue;

    const s = doc.data() || {};
    planned++;

    // Prefer the school doc's primarySsaId; if absent (older/partial tenants),
    // recover the SSA by querying schoolSsa for this school.
    let ssaId = firstNonEmpty(s.primarySsaId);
    if (ssaId === '') {
      const q = await fsdb.collection('schoolSsa').where('schoolId', '==', schoolId).get();
      const rows = q.docs.map(d => d.data() || {});
      const pick = rows.find(r => firstNonEmpty(r.role) === 'school_super_admin') || rows[0];
      ssaId = pick ? firstNonEmpty(pick.ssaId, pick.adminId, pick.uid) : '';
      if (ssaId !== '') console.log(`    (recovered SSA ${ssaId} via schoolSsa query — primarySsaId was missing)`);
    }
    if (ssaId === '') {
      console.log(`--- ${schoolId} : SKIP (no primarySsaId and no schoolSsa match)`);
      skippedNoSsa++;
      continue;
    }

    // Pull SSA detail + staff fallback.
    const ssaSnap = await fsdb.collection('schoolSsa').doc(ssaId).get();
    const ssa = ssaSnap.exists ? (ssaSnap.data() || {}) : {};
    const staffSnap = await fsdb.collection('staff').doc(`${schoolId}_${ssaId}`).get();
    const staff = staffSnap.exists ? (staffSnap.data() || {}) : {};
    const staffProfile = (staff && staff.Profile) || {};
    const ssaPhone = firstNonEmpty(ssa.phone); // current schoolSsa/{id}.phone (often '' on pre-existing SSAs)

    const block = {
      name:   firstNonEmpty(ssa.name, staff.name, staff.Name),
      email:  firstNonEmpty(ssa.email, staff.email, staff.Email),
      number: firstNonEmpty(ssa.phone, staffProfile.phone, s.phone),
    };

    // Two INDEPENDENT idempotent writes so each can converge on its own:
    //   (a) schools/{id}.forget_password_details  — fill-if-empty, or rewrite to
    //       strip a legacy `id` subfield (or --force)
    //   (b) schoolSsa/{ssaId}.phone               — fill-if-empty (or --force)
    // (b) keeps the canonical SSA doc in sync with the recovery block, mirroring
    //     what new onboarding now writes to BOTH places.
    const staleId    = hasStaleId(s.forget_password_details);
    const needsFpd   = FORCE || !isFilledBlock(s.forget_password_details) || staleId;
    const needsPhone = block.number !== '' && (FORCE || ssaPhone === '');

    console.log(`--- ${schoolId} : ssa=${ssaId} fpd=${JSON.stringify(block)}` +
      (ssaSnap.exists ? '' : ' (schoolSsa MISSING — used fallbacks)'));
    console.log(`        forget_password_details : ${needsFpd ? (staleId ? 'WRITE (drops legacy id)' : 'WRITE') : 'skip (already set)'}` +
      ` | schoolSsa.phone : ${needsPhone ? `WRITE "${block.number}"` : (block.number === '' ? 'skip (no number)' : `skip (already "${ssaPhone}")`)}`);

    if (!needsFpd && !needsPhone) { skippedFilled++; continue; }

    if (COMMIT) {
      if (needsFpd) {
        // update() replaces the whole forget_password_details map (unlike a
        // merge:true set, which would deep-merge and leave a stale `id` behind).
        await fsdb.collection('schools').doc(schoolId).update(
          { forget_password_details: block, updatedAt: new Date().toISOString() }
        );
        written++;
      }
      if (needsPhone) {
        await fsdb.collection('schoolSsa').doc(ssaId).set(
          { phone: block.number, updatedAt: new Date().toISOString() },
          { merge: true }
        );
        phoneWritten++;
      }
    }
  }

  console.log('');
  console.log('=== SUMMARY ===');
  console.log('schools scanned          :', planned);
  console.log('skipped (nothing to do)  :', skippedFilled);
  console.log('skipped (no SSA at all)  :', skippedNoSsa);
  if (COMMIT) {
    console.log('forget_password_details written :', written);
    console.log('schoolSsa.phone written         :', phoneWritten);
  } else {
    console.log('DRY-RUN — re-run with --commit to apply.');
  }

  await admin.app().delete();
  process.exit(0);
})().catch(e => {
  console.error('backfill FAILED:', e && e.message ? e.message : e);
  process.exit(1);
});
