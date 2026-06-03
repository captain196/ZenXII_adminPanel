#!/usr/bin/env node
/**
 * Wave A — Phase A1 : backfill Firestore superAdmins/{SUP} profile docs.
 *
 * Reads RTDB Users/Admin/Our Panel (READ-ONLY) and, for each SUP* account,
 * constructs a password-free camelCase profile doc (explicit field whitelist
 * — passwords/Credentials can never leak in by construction) and links the
 * Firebase Auth uid.
 *
 * MODES:
 *   (default)   DRY-RUN — prints each would-be payload, writes NOTHING.
 *   --apply     CREATE-ONLY — creates superAdmins/{id} if absent; skips if it
 *               already exists (idempotent).
 *   --force     with --apply: overwrite an existing doc (repair only).
 *
 * Never writes RTDB / Mongo / Firebase Auth. Only writes the superAdmins
 * Firestore collection, and only under --apply.
 */
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }

const SA_PATH = path.resolve(__dirname, '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
const DB_URL  = process.env.RTDB_URL || 'https://graderadmin-default-rtdb.firebaseio.com/'; // matches Firebase.php
const EMAIL_DOMAIN = 'schoolsync.app';

const APPLY = process.argv.includes('--apply');
const FORCE = process.argv.includes('--force');

const sa = require(SA_PATH);
admin.initializeApp({ credential: admin.credential.cert(sa), databaseURL: DB_URL });
const rtdb = admin.database();
const fsdb = admin.firestore();
const auth = admin.auth();

// Build the password-free profile doc via an explicit whitelist.
function buildPayload(id, rec, firebaseUid) {
  const profile = (rec && rec.Profile) || {};
  const ah      = (rec && rec.AccessHistory) || {};
  return {
    superAdminId: id,
    name:         rec.Name || profile.name || '',
    email:        rec.Email || profile.email || '',
    status:       rec.Status || 'Active',
    role:         'super_admin',                 // canonical (not RTDB PascalCase 'Super Admin')
    isPrimary:    !!rec.is_primary,
    phone:        profile.phone || '',
    createdAt:    profile.created_at || rec['Created On'] || null,
    createdBy:    profile.created_by || null,
    firebaseUid:  firebaseUid || null,
    authProvider: 'firebase',
    accessHistory: {
      lastLogin:   ah.SA_LastLogin || rec.SA_LastLogin || null,
      lastLoginIp: ah.SA_LastLoginIP || rec.SA_LastLoginIP || null,
    },
    migratedToFirestore: true,
    migratedAt: new Date().toISOString(),
    source: 'a1_backfill',
  };
}

const PW_KEYS = ['password', 'Password', 'credentials', 'Credentials', 'passwordHash', 'pwd'];
function assertNoSecrets(payload) {
  const bad = Object.keys(payload).filter(k => PW_KEYS.includes(k));
  if (bad.length) throw new Error('SECURITY: payload contains forbidden field(s): ' + bad.join(','));
  // deep check one level (accessHistory)
  for (const v of Object.values(payload)) {
    if (v && typeof v === 'object') {
      const bad2 = Object.keys(v).filter(k => PW_KEYS.includes(k));
      if (bad2.length) throw new Error('SECURITY: nested forbidden field(s): ' + bad2.join(','));
    }
  }
}

(async () => {
  const mode = APPLY ? (FORCE ? 'APPLY+FORCE' : 'APPLY (create-only)') : 'DRY-RUN (no writes)';
  console.log('=== WAVE A / PHASE A1 — superAdmins backfill ===');
  console.log('MODE:', mode);
  console.log('');

  const ourPanel = (await rtdb.ref('Users/Admin/Our Panel').once('value')).val() || {};
  let planned = 0, created = 0, skipped = 0;

  for (const [id, rec] of Object.entries(ourPanel)) {
    if (!rec || typeof rec !== 'object' || !/^SUP\d+$/i.test(id)) continue;
    planned++;

    let firebaseUid = null;
    try { firebaseUid = (await auth.getUserByEmail(`${id.toLowerCase()}@${EMAIL_DOMAIN}`)).uid; }
    catch (e) { firebaseUid = null; }

    const payload = buildPayload(id, rec, firebaseUid);
    assertNoSecrets(payload);

    const ref = fsdb.collection('superAdmins').doc(id);
    const exists = (await ref.get()).exists;

    console.log(`--- superAdmins/${id} ---`);
    console.log('  exists already :', exists);
    console.log('  firebaseUid    :', firebaseUid || '(none)');
    console.log('  no-secret check: PASS (no password/Credentials field)');
    console.log('  payload        :');
    console.log(JSON.stringify(payload, null, 2).split('\n').map(l => '    ' + l).join('\n'));

    if (!APPLY) {
      console.log('  action         :', exists ? 'would SKIP (exists)' : 'would CREATE');
    } else if (exists && !FORCE) {
      console.log('  action         : SKIPPED (exists; use --force to overwrite)');
      skipped++;
    } else {
      await ref.set(payload, { merge: false });
      console.log('  action         :', exists ? 'OVERWRITTEN (--force)' : 'CREATED');
      created++;
    }
    console.log('');
  }

  console.log('=== SUMMARY ===');
  console.log('SUP* accounts in Our Panel :', planned);
  if (APPLY) {
    console.log('created :', created, '| skipped :', skipped);
  } else {
    console.log('DRY-RUN — no writes performed. Re-run with --apply to create.');
  }
  console.log('Expected post-apply parity : superAdmins docs ==', planned, '(== Our Panel SUP* count)');

  await admin.app().delete();
  process.exit(0);
})().catch(e => { console.error('A1 backfill FAILED:', e && e.message ? e.message : e); process.exit(1); });
