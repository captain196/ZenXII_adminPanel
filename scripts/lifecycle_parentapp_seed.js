/**
 * ══════════════════════════════════════════════════════════════════════════
 *  SEED A LOGIN-CAPABLE PARENT FOR THE END-TO-END DEVICE PROOF
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Creates, in the EMULATORS only:
 *   • an Auth user the Parent App can actually sign in as, whose uid IS the
 *     studentId — which is what makes the app's canonical document id
 *     `{schoolCode}_{studentId}` resolve, and what the emulator ruleset
 *     checks (`docId.split('_')[1] == request.auth.uid`);
 *   • the `Indexes/School_codes` entry `AuthRepository.lookupSchoolCode()`
 *     reads to turn a login code into the canonical school id.
 *
 * The student document itself is NOT written here. It is created by the REAL
 * admission endpoint via Lifecycle_probe, so the record the app reads is the
 * one production code produced — seeding it by hand would prove nothing.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────────
 * Refuses unless BOTH emulator variables point at loopback. This script
 * creates a CREDENTIAL; run against production it would mint a real parent
 * login. The password is generated per-run and printed only as a length.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 \
 *   FIREBASE_AUTH_EMULATOR_HOST=127.0.0.1:9099 \
 *   node scripts/lifecycle_parentapp_seed.js --student=STU0001
 */
const admin = require('firebase-admin');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};

const FS_EMU   = process.env.FIRESTORE_EMULATOR_HOST || '';
const AUTH_EMU = process.env.FIREBASE_AUTH_EMULATOR_HOST || '';
const SCHOOL   = arg('school', 'SCH_EMUFIXTURE1');
const CODE     = arg('code', '99001');
const STUDENT  = arg('student', '');

const loopback = (v) =>
  ['127.0.0.1', 'localhost', '::1'].includes(v.replace(/^https?:\/\//, '').split(':')[0]);

if (!FS_EMU || !AUTH_EMU || !loopback(FS_EMU) || !loopback(AUTH_EMU)) {
  console.error('REFUSING TO RUN: both FIRESTORE_EMULATOR_HOST and');
  console.error('FIREBASE_AUTH_EMULATOR_HOST must point at a loopback emulator.');
  console.error('This script CREATES A CREDENTIAL; against production it would mint');
  console.error('a real parent login.');
  process.exit(1);
}
if (!STUDENT) { console.error('--student=STU00xx is required'); process.exit(1); }

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();
const auth = admin.auth();

// Per-run, never reused, never printed.
const password = 'Emu!' + Math.random().toString(36).slice(2, 10) + 'Aa1';

(async () => {
  console.log(`SEEDING PARENT LOGIN — ${STUDENT} @ ${SCHOOL} (EMULATORS ONLY)\n`);

  // The app signs in with a SYNTHETIC email it composes itself:
  // `FirebaseAuthManager` builds `{userId.lowercase()}@schoolsync.app`.
  // This must match exactly, or the credential exists and login still fails —
  // read from the app rather than guessed, because a plausible-looking domain
  // would have produced a login failure that looked like a product defect.
  const email = `${STUDENT.toLowerCase()}@schoolsync.app`;

  try { await auth.deleteUser(STUDENT); } catch (e) { /* first run */ }
  await auth.createUser({ uid: STUDENT, email, password, emailVerified: true });

  // The claims the rules and the app both expect — dual-emitted snake AND
  // camel, exactly as the admin login does. One without the other is a
  // silent PERMISSION_DENIED.
  await auth.setCustomUserClaims(STUDENT, {
    school_id: SCHOOL, schoolId: SCHOOL,
    role: 'student', studentId: STUDENT,
  });

  console.log(`  auth user   : uid=${STUDENT} email=${email}`);
  console.log(`  password    : present(len=${password.length})`);
  console.log(`  claims      : school_id + schoolId (dual-emitted), role=student`);

  // AuthRepository.lookupSchoolCode() resolves the login code -> school id.
  await db.collection('Indexes').doc('School_codes').set({ [CODE]: SCHOOL }, { merge: true });
  console.log(`  school code : ${CODE} -> ${SCHOOL}`);

  // Emitted for the harness to consume; this is an emulator-only credential.
  console.log(`\nPARENT_LOGIN_ID=${STUDENT}`);
  console.log(`PARENT_LOGIN_PW=${password}`);
  console.log(`PARENT_SCHOOL_CODE=${CODE}`);
  console.log('\n  Emulators only. No live tenant was touched.');
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
