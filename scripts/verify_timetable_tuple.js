// Phase C-1 — single-tuple verification (read-only).
// Prints the Firestore doc + RTDB source side-by-side so we can validate
// fidelity after the controlled --apply.
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.resolve(__dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({
  credential: admin.credential.cert(sa),
  databaseURL: process.env.RTDB_URL || 'https://graderadmin-default-rtdb.firebaseio.com/',
});
const fs = admin.firestore();
const rtdb = admin.database();

const SCHOOL = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';
const CLASS = 'Class 7th';
const SECTION = 'Section A';
const DAY = 'Friday';
const DOC_ID = `${SCHOOL}_${SESSION}_${CLASS}_${SECTION}_${DAY}`;

(async () => {
  // Firestore
  const snap = await fs.collection('timetables').doc(DOC_ID).get();
  const fsDoc = snap.exists ? snap.data() : null;

  // RTDB source
  const rtdbPath = `Schools/${SCHOOL}/${SESSION}/${CLASS}/${SECTION}/Time_table/${DAY}`;
  const rtdbDay = (await rtdb.ref(rtdbPath).once('value')).val();

  // Settings
  const settingsSnap = await fs.collection('timetableSettings').doc(`${SCHOOL}_${SESSION}`).get();
  const settings = settingsSnap.exists ? settingsSnap.data() : null;

  console.log('\n=== Firestore doc ===');
  console.log('docId:', DOC_ID);
  console.log(JSON.stringify(fsDoc, null, 2));

  console.log('\n=== RTDB source ===');
  console.log('path:', rtdbPath);
  console.log(JSON.stringify(rtdbDay, null, 2));

  console.log('\n=== Settings (No_of_periods) ===');
  console.log('No_of_periods (FS settings):', settings?.No_of_periods);

  // Programmatic checks
  const checks = [];
  const noP = Number(settings?.No_of_periods || 8);
  checks.push(['periods array length matches No_of_periods',
    Array.isArray(fsDoc?.periods) && fsDoc.periods.length === noP,
    `expected ${noP}, got ${fsDoc?.periods?.length}`]);

  const seq = (fsDoc?.periods || []).map(p => p.periodNumber);
  const expectedSeq = Array.from({ length: noP }, (_, i) => i + 1);
  checks.push(['periodNumber sequence is 1..N',
    JSON.stringify(seq) === JSON.stringify(expectedSeq),
    `seq=${JSON.stringify(seq)}`]);

  // Each RTDB period must round-trip into Firestore
  if (rtdbDay && typeof rtdbDay === 'object') {
    for (const [k, v] of Object.entries(rtdbDay)) {
      const n = parseInt(k, 10);
      if (!Number.isFinite(n)) continue;
      const fsP = (fsDoc?.periods || []).find(p => p.periodNumber === n);
      checks.push([`period ${n} subject preserved`,
        fsP && fsP.subject === (v.subject || ''),
        `RTDB.subject=${JSON.stringify(v.subject)}, FS.subject=${JSON.stringify(fsP?.subject)}`]);
      checks.push([`period ${n} teacherId preserved`,
        fsP && fsP.teacherId === (v.teacherId || ''),
        `RTDB.teacherId=${JSON.stringify(v.teacherId)}, FS.teacherId=${JSON.stringify(fsP?.teacherId)}`]);
      checks.push([`period ${n} startTime preserved`,
        fsP && fsP.startTime === (v.startTime || ''),
        `RTDB.startTime=${JSON.stringify(v.startTime)}, FS.startTime=${JSON.stringify(fsP?.startTime)}`]);
      checks.push([`period ${n} endTime preserved`,
        fsP && fsP.endTime === (v.endTime || ''),
        `RTDB.endTime=${JSON.stringify(v.endTime)}, FS.endTime=${JSON.stringify(fsP?.endTime)}`]);
    }
  }

  // Metadata
  checks.push(['classOrder is 7', fsDoc?.classOrder === 7, `got ${fsDoc?.classOrder}`]);
  checks.push(['sectionCode is "A"', fsDoc?.sectionCode === 'A', `got ${JSON.stringify(fsDoc?.sectionCode)}`]);
  checks.push(['sectionKey is "Class 7th/Section A"',
    fsDoc?.sectionKey === 'Class 7th/Section A', `got ${JSON.stringify(fsDoc?.sectionKey)}`]);
  checks.push(['day field matches', fsDoc?.day === DAY, `got ${JSON.stringify(fsDoc?.day)}`]);
  checks.push(['schoolId field matches', fsDoc?.schoolId === SCHOOL, `got ${JSON.stringify(fsDoc?.schoolId)}`]);
  checks.push(['session field matches', fsDoc?.session === SESSION, `got ${JSON.stringify(fsDoc?.session)}`]);
  checks.push(['migratedFrom marker present', fsDoc?.migratedFrom === 'rtdb-Time_table', `got ${JSON.stringify(fsDoc?.migratedFrom)}`]);
  checks.push(['no Time field leaked into FS', !('Time' in (fsDoc || {})), '']);
  checks.push(['no PassingMarks field leaked into FS', !('PassingMarks' in (fsDoc || {})), '']);
  checks.push(['no TotalMarks field leaked into FS', !('TotalMarks' in (fsDoc || {})), '']);

  console.log('\n=== Validation checks ===');
  let pass = 0, fail = 0;
  for (const [name, ok, detail] of checks) {
    const tag = ok ? '\x1b[32m✓ PASS\x1b[0m' : '\x1b[31m✗ FAIL\x1b[0m';
    console.log(`  ${tag}  ${name}${ok ? '' : '  — ' + detail}`);
    ok ? pass++ : fail++;
  }
  console.log(`\nTotal: ${pass} passed, ${fail} failed`);
  process.exit(fail > 0 ? 2 : 0);
})().catch(e => { console.error(e); process.exit(1); });
