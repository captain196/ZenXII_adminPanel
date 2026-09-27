// ─────────────────────────────────────────────────────────────────────────
// TEST SCHOOL RESET — STORAGE + FIREBASE AUTH — SCH_D94FE8F7AD
//
//   node scripts/test_school_reset_storage_auth.js            # DRY RUN
//   node scripts/test_school_reset_storage_auth.js --commit   # deletes
//
// Companion to test_school_reset.js (which cleared Firestore). Same
// convention: dry-run unless --commit is typed.
//
// ═══════════════════════════════════════════════════════════════════════
//  HOW TENANT OWNERSHIP IS PROVEN — this is the whole safety argument
// ═══════════════════════════════════════════════════════════════════════
//  The Firestore reset removed students/parents/staff, so ownership can no
//  longer be established by joining against those records. Two independent
//  sources of truth survive, and both are used here:
//
//  STORAGE — every object path carries the school ID as a COMPLETE PATH
//  SEGMENT, in one of two shapes (verified against all 338 bucket objects):
//      SCH_D94FE8F7AD/Students/...        (50 objects)
//      documents|admission|homework|galleryMedia/SCH_D94FE8F7AD/...  (42)
//  Matching is on the segment, never a loose substring, so a path that
//  merely happened to contain the token elsewhere could not qualify.
//
//  AUTH — Firebase custom claims live in Auth, NOT Firestore, so they
//  survived the reset intact. The panel dual-emits `school_id` (snake) and
//  `schoolId` (camel). A user is deleted ONLY if that claim equals this
//  tenant. Measured on the live project:
//      21  claim == SCH_D94FE8F7AD   -> DELETE
//     119  claim == another school   -> NEVER TOUCHED
//       6  no school claim at all    -> REFUSED, manual review
//
//  The 6 unclaimed users (SUP0001, PROBE_F7_STU_*, *_test_phase1) are NOT
//  deleted. SUP0001 in particular looks like a platform superadmin. Absent
//  proof of ownership the rule is: do not delete.
// ─────────────────────────────────────────────────────────────────────────

const admin = require('firebase-admin');
const path  = require('path');
const sa    = require(path.join(__dirname, '..', 'application', 'config',
                'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({
  credential: admin.credential.cert(sa),
  storageBucket: 'graderadmin.appspot.com',
});

const SCHOOL = 'SCH_D94FE8F7AD';
const COMMIT = process.argv.includes('--commit');

/** True only when the school ID appears as a COMPLETE path segment. */
function storageOwnedByTenant(name) {
  return name.startsWith(SCHOOL + '/') || name.includes('/' + SCHOOL + '/');
}

/** True only when a surviving Auth custom claim names this tenant. */
function authOwnedByTenant(user) {
  const c = user.customClaims || {};
  const sid = c.school_id || c.schoolId || c.schoolID || null;
  return sid === SCHOOL;
}

(async () => {
  console.log(`MODE   = ${COMMIT ? 'COMMIT (DELETING)' : 'DRY RUN (no writes)'}`);
  console.log(`TENANT = ${SCHOOL}\n`);

  // ── STORAGE ────────────────────────────────────────────────────────
  console.log('=== STORAGE ===');
  let sDeleted = 0, sFailed = 0, sRefused = 0;
  const [files] = await admin.storage().bucket().getFiles();
  const mine = files.filter(f => storageOwnedByTenant(f.name));
  const notMine = files.length - mine.length;

  console.log(`bucket objects total = ${files.length}`);
  console.log(`belong to this tenant = ${mine.length}`);
  console.log(`belong elsewhere (untouched) = ${notMine}`);

  for (const f of mine) {
    if (!storageOwnedByTenant(f.name)) { sRefused++; continue; }   // belt and braces
    if (!COMMIT) continue;
    try { await f.delete(); sDeleted++; }
    catch (e) { sFailed++; console.log(`  FAILED ${f.name}: ${e.message}`); }
  }
  console.log(COMMIT ? `deleted=${sDeleted} failed=${sFailed}` : '(dry run — nothing deleted)');

  // ── AUTH ───────────────────────────────────────────────────────────
  console.log('\n=== FIREBASE AUTH ===');
  const ours = [], otherSchool = [], unclaimed = [];
  let res = await admin.auth().listUsers(1000);
  while (true) {
    for (const u of res.users) {
      const c = u.customClaims || {};
      const sid = c.school_id || c.schoolId || c.schoolID || null;
      if (authOwnedByTenant(u)) ours.push(u);
      else if (sid) otherSchool.push(u);
      else unclaimed.push(u);
    }
    if (!res.pageToken) break;
    res = await admin.auth().listUsers(1000, res.pageToken);
  }

  console.log(`ours (claim == ${SCHOOL}) = ${ours.length}`);
  console.log(`another school            = ${otherSchool.length}   <-- NEVER TOUCHED`);
  console.log(`no school claim           = ${unclaimed.length}   <-- REFUSED, manual review`);

  let aDeleted = 0, aFailed = 0;
  for (const u of ours) {
    if (!authOwnedByTenant(u)) continue;                            // belt and braces
    console.log(`  ${COMMIT ? 'DELETE ' : 'PROPOSE'} ${u.uid}  ${u.email || u.phoneNumber || '(no id)'}`);
    if (!COMMIT) continue;
    try { await admin.auth().deleteUser(u.uid); aDeleted++; }
    catch (e) { aFailed++; console.log(`    FAILED: ${e.message}`); }
  }
  console.log('\n  REFUSED (no claim — left in place for manual review):');
  unclaimed.forEach(u => console.log(`    ${u.uid}  ${u.email || u.phoneNumber || '(none)'}`));

  // ── SUMMARY ────────────────────────────────────────────────────────
  console.log('\n' + '-'.repeat(60));
  console.log(`STORAGE  deleted=${sDeleted}  failed=${sFailed}  untouched(other)=${notMine}`);
  console.log(`AUTH     deleted=${aDeleted}  failed=${aFailed}  untouched(other schools)=${otherSchool.length}  refused(ambiguous)=${unclaimed.length}`);
  if (!COMMIT) console.log('\nDRY RUN — nothing was deleted. Re-run with --commit to execute.');

  await admin.app().delete();
})().catch(e => { console.error('FATAL', e.message); process.exit(1); });
