// Debug: show what students Fees::_searchByName would see for a given
// school. Mirrors that query exactly so we can confirm whether the
// no-results problem is data (no docs match), index (schoolId field
// missing), or something else upstream.
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';

(async () => {
  console.log(`\nstudents for schoolId == ${SCHOOL}\n` + '─'.repeat(78));

  // 1) Filtered query — exactly what _searchByName runs.
  const snap = await db.collection('students').where('schoolId', '==', SCHOOL).get();
  console.log(`  Filtered (schoolId=${SCHOOL}): ${snap.size} doc(s)`);

  // 2) Unfiltered (limit 5) so we can see the actual schoolId values
  //    in the collection. If filtered is 0 but unfiltered is non-empty,
  //    the schoolId field is missing or has a different value.
  const allSnap = await db.collection('students').limit(8).get();
  console.log(`  Unfiltered sample : ${allSnap.size} doc(s) shown\n`);

  console.log('  doc id                                     | schoolId                | studentId | name                       | className');
  console.log('  ' + '-'.repeat(140));
  for (const doc of allSnap.docs) {
    const d = doc.data() || {};
    console.log('  ' +
      String(doc.id).padEnd(43) + ' | ' +
      String(d.schoolId   || '<MISSING>').padEnd(23) + ' | ' +
      String(d.studentId  || d.userId || '').padEnd(9) + ' | ' +
      String(d.name       || d.Name || '').padEnd(27) + ' | ' +
      String(d.className  || ''));
  }
  if (snap.empty) {
    console.log('\n  ⚠️  Filtered query returned 0 — search would say "No students found".');
    console.log('     Check whether the schoolId field exists on the docs above.');
  }
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
