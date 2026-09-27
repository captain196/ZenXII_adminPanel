/**
 * READ-ONLY verification of the dangling references the Gate 2 reconciler
 * reported. Its job is to DISPROVE them if it can.
 *
 * The reconciler builds its student set from a `where('schoolId','==',…)`
 * query and keys it on `studentId`. Three things could make a live student
 * look dangling: a missing `schoolId` field on the student doc, a `studentId`
 * field that disagrees with the document id, or a doc-id convention this
 * script did not anticipate. Each is checked here by DIRECT document read,
 * which does not depend on any of them.
 *
 * It then asks the question that actually matters: do these identities still
 * have a way IN — a Firebase Auth account, an unclaimed admission number, a
 * parent record the app would serve?
 *
 *   node scripts/orphan_verify_readonly.js --school=SCH_B56BB9A401
 *
 * Writes nothing. Prints no credential material.
 */
const admin = require('firebase-admin');
const path  = require('path');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};
const SCHOOL = arg('school', 'SCH_B56BB9A401');

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  console.log(`ORPHAN VERIFICATION — ${SCHOOL} (READ ONLY)\n`);

  // The identities the reconciler flagged.
  const suspects = [];
  const parents = await db.collection('parents').where('schoolId', '==', SCHOOL).get();
  parents.forEach((d) => {
    const p = d.data() || {};
    if (p.studentId) suspects.push(String(p.studentId));
  });
  const admissions = await db.collection('admissionNoIndex').where('schoolId', '==', SCHOOL).get();
  admissions.forEach((d) => {
    const a = d.data() || {};
    if (a.studentId) suspects.push(String(a.studentId));
  });
  const unique = [...new Set(suspects)].sort();

  console.log(`  ${unique.length} distinct studentId(s) referenced by parents/admissionNoIndex\n`);

  let confirmedMissing = [], present = [];

  for (const sid of unique) {
    // DIRECT read on the canonical id — independent of any query or field.
    const direct = await db.collection('students').doc(`${SCHOOL}_${sid}`).get();
    if (direct.exists) { present.push(sid); continue; }

    // Second chance: a document whose id differs but whose studentId matches.
    const byField = await db.collection('students').where('studentId', '==', sid).limit(1).get();
    if (!byField.empty) {
      present.push(sid + ' (doc id differs from convention!)');
      continue;
    }
    confirmedMissing.push(sid);
  }

  console.log(`  students that DO exist      : ${present.length}`);
  if (present.length) console.log(`    ${present.join(', ')}`);
  console.log(`  students CONFIRMED missing  : ${confirmedMissing.length}`);
  if (confirmedMissing.length) console.log(`    ${confirmedMissing.join(', ')}`);

  if (!confirmedMissing.length) {
    console.log('\n  The reconciler was WRONG — every reference resolves. No orphans.');
    process.exit(0);
  }

  // ── WHAT SURVIVES FOR A STUDENT THAT NO LONGER EXISTS ────────────────
  console.log('\n  ── WHAT STILL EXISTS FOR EACH CONFIRMED-MISSING STUDENT\n');
  console.log('    ' + 'studentId'.padEnd(11) + 'parent  admNo  history  authUser  demands');
  console.log('    ' + '-'.repeat(60));

  let liveAuth = 0, claimedAdm = 0;
  for (const sid of confirmedMissing) {
    const pDoc = await db.collection('parents').doc(`${SCHOOL}_${sid}`).get();
    const adm  = await db.collection('admissionNoIndex')
      .where('schoolId', '==', SCHOOL).where('studentId', '==', sid).get();
    const hist = await db.collection('studentHistory')
      .where('schoolId', '==', SCHOOL).where('studentId', '==', sid).get();
    const dem  = await db.collection('feeDemands')
      .where('schoolId', '==', SCHOOL).where('studentId', '==', sid).get();

    // Does the parent still have a way to sign in? Report existence and
    // enabled/disabled ONLY — never any credential material.
    let authState = 'none';
    try {
      const u = await admin.auth().getUser(sid);
      authState = u.disabled ? 'disabled' : 'ENABLED';
      if (!u.disabled) liveAuth++;
    } catch (e) { authState = 'none'; }

    if (!adm.empty) claimedAdm++;

    console.log('    ' + sid.padEnd(11)
      + (pDoc.exists ? ' yes  ' : '  -   ').padEnd(8)
      + (adm.empty ? '  -  ' : ' yes ').padEnd(7)
      + String(hist.size).padStart(4).padEnd(9)
      + authState.padEnd(10)
      + String(dem.size).padStart(4));
  }

  console.log('\n  ── CONSEQUENCE\n');
  console.log(`    ${claimedAdm} admission number(s) remain CLAIMED by a student that does not exist.`);
  console.log('      admissionNoIndex is the uniqueness claim for an admission number.');
  console.log('      A claim that outlives its student is a permanent leak of that number.');
  console.log(`    ${liveAuth} Firebase Auth account(s) are still ENABLED for a deleted student.`);
  if (liveAuth > 0) {
    console.log('      A parent holding these credentials can still authenticate. What they can');
    console.log('      then READ is decided by firestore.rules, not by the app — so this is the');
    console.log('      finding that needs a rules-level answer, not a UI one.');
  }
  console.log('\n  Nothing was written.');
  process.exit(2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
