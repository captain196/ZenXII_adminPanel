#!/usr/bin/env node
/**
 * Phase 3 — Live verification of session-rollover invariants.
 * Asserts L-3, L-5, L-6, L-7, L-8, L-9, L-10 across all carried collections.
 */
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';
const FROM = '2026-27';
const TO = '2027-28';

const fail = [];
function assert(cond, msg, ctx) {
  if (!cond) fail.push({ msg, ctx });
}

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Phase 3 — Live Verification of Carried Data');
  console.log('═══════════════════════════════════════════════════════════════');

  // L-3 — Source unchanged
  const sourceCounts = {};
  for (const c of ['curriculum','timetables','subjectAssignments']) {
    sourceCounts[c] = (await fs.collection(c).where('schoolId','==',SCHOOL).where('session','==',FROM).get()).size;
  }
  console.log('\nL-3  Source session ' + FROM + ' (must be untouched):');
  console.log('    curriculum         : ' + sourceCounts.curriculum + '   (expected 1)');
  console.log('    timetables         : ' + sourceCounts.timetables + '  (expected 33)');
  console.log('    subjectAssignments : ' + sourceCounts.subjectAssignments + '  (expected 29)');
  assert(sourceCounts.curriculum === 1, 'source curriculum = 1', 'L-3');
  assert(sourceCounts.timetables === 33, 'source timetables = 33', 'L-3');
  assert(sourceCounts.subjectAssignments === 29, 'source assignments = 29', 'L-3');

  // L-5 — Curriculum
  const newCurr = await fs.collection('curriculum').where('schoolId','==',SCHOOL).where('session','==',TO).get();
  console.log('\nL-5  Curriculum (' + newCurr.size + ' doc):');
  // Build source UUID set once
  const srcDocs = await fs.collection('curriculum').where('schoolId','==',SCHOOL).where('session','==',FROM).get();
  const srcUUIDs = new Set();
  for (const sd of srcDocs.docs) {
    (sd.data().topicIds || []).forEach(t => srcUUIDs.add(t));
  }
  for (const d of newCurr.docs) {
    const data = d.data();
    assert(data.topicsModel === 'subcollection', "topicsModel = 'subcollection'", 'L-5/' + d.id);
    assert(data.carriedFromSession === FROM, 'carriedFromSession = ' + FROM, 'L-5/' + d.id);
    assert(data.phase3RolloverRev === 1, 'phase3RolloverRev = 1', 'L-5/' + d.id);
    assert(data.completedTopics === 0, 'completedTopics = 0', 'L-5/' + d.id);
    assert(data.percentComplete === 0, 'percentComplete = 0', 'L-5/' + d.id);
    assert(data.version === 1, 'version = 1', 'L-5/' + d.id);
    assert(Array.isArray(data.topicIds) && data.topicIds.length === data.totalTopics,
      'topicIds matches totalTopics', 'L-5/' + d.id);

    let overlap = 0;
    for (const tid of (data.topicIds || [])) if (srcUUIDs.has(tid)) overlap++;
    assert(overlap === 0, 'fresh UUIDs (no overlap with source)', 'L-5/' + d.id);

    const sub = await d.ref.collection('topics').get();
    assert(sub.size === data.totalTopics, 'subcollection size matches', 'L-5/' + d.id);
    let resetOK = true, dateClearOK = true;
    for (const td of sub.docs) {
      const t = td.data();
      if (t.status !== 'not_started') resetOK = false;
      if (t.completedDate !== '') dateClearOK = false;
      assert(t.version === 1, 'topic.version = 1', 'L-5/' + d.id + '/' + td.id);
      assert(t.parentDocId === d.id, 'topic.parentDocId points to new parent', 'L-5/' + d.id + '/' + td.id);
    }
    assert(resetOK, "all topics status = 'not_started'", 'L-5/' + d.id);
    assert(dateClearOK, 'all topics completedDate empty', 'L-5/' + d.id);
    console.log('    ✓ ' + d.id + '  (' + sub.size + ' topics, all reset)');
  }

  // L-6 — Timetables
  const newTT = await fs.collection('timetables').where('schoolId','==',SCHOOL).where('session','==',TO).get();
  console.log('\nL-6  Timetables (' + newTT.size + ' docs):');
  let manualResetOk = 0, versionResetOk = 0, noGenIdOk = 0, periodsCarriedOk = 0;
  for (const d of newTT.docs) {
    const data = d.data();
    if (data.manuallyEdited === false) manualResetOk++;
    if (data.version === 1) versionResetOk++;
    if (!data.generationId) noGenIdOk++;
    if (Array.isArray(data.periods)) periodsCarriedOk++;
    assert(data.carriedFromSession === FROM, 'carriedFromSession', 'L-6/' + d.id);
    assert(data.phase3RolloverRev === 1, 'phase3RolloverRev = 1', 'L-6/' + d.id);
  }
  console.log('    manuallyEdited=false  : ' + manualResetOk + '/' + newTT.size);
  console.log('    version=1             : ' + versionResetOk + '/' + newTT.size);
  console.log('    no generationId       : ' + noGenIdOk + '/' + newTT.size);
  console.log('    periods array carried : ' + periodsCarriedOk + '/' + newTT.size);
  assert(manualResetOk === newTT.size, 'all manuallyEdited reset', 'L-6');
  assert(versionResetOk === newTT.size, 'all version=1', 'L-6');
  assert(noGenIdOk === newTT.size, 'no generationId', 'L-6');
  assert(periodsCarriedOk === newTT.size, 'all have periods', 'L-6');

  // L-7 — subjectAssignments
  const newSA = await fs.collection('subjectAssignments').where('schoolId','==',SCHOOL).where('session','==',TO).get();
  console.log('\nL-7  subjectAssignments (' + newSA.size + ' docs):');
  let teacherIdPreserved = 0, periodsWeekPreserved = 0;
  for (const d of newSA.docs) {
    const data = d.data();
    if (typeof data.teacherId === 'string' && data.teacherId.length > 0) teacherIdPreserved++;
    if (typeof data.periodsPerWeek === 'number') periodsWeekPreserved++;
    assert(data.carriedFromSession === FROM, 'carriedFromSession', 'L-7/' + d.id);
    assert(data.session === TO, 'session = ' + TO, 'L-7/' + d.id);
  }
  console.log('    teacherId preserved      : ' + teacherIdPreserved + '/' + newSA.size);
  console.log('    periodsPerWeek preserved : ' + periodsWeekPreserved + '/' + newSA.size);

  // L-8 — timetableSettings
  const settingsDoc = await fs.collection('timetableSettings').doc(SCHOOL + '_' + TO).get();
  console.log('\nL-8  timetableSettings:');
  assert(settingsDoc.exists, 'target settings exists', 'L-8');
  if (settingsDoc.exists) {
    const setData = settingsDoc.data();
    assert(setData.startTime, 'startTime carried', 'L-8');
    assert(setData.periodsPerDay > 0, 'periodsPerDay carried', 'L-8');
    assert(setData.carriedFromSession === FROM, 'carriedFromSession', 'L-8');
    console.log('    startTime          : ' + setData.startTime);
    console.log('    periodsPerDay      : ' + setData.periodsPerDay);
    console.log('    carriedFromSession : ' + setData.carriedFromSession);
  }

  // L-9 — calendarEvents NOT carried
  const calRolled = (await fs.collection('calendarEvents')
    .where('schoolId','==',SCHOOL).where('phase3RolloverRev','==',1).get()).size;
  console.log('\nL-9  calendarEvents NOT carried: ' + (calRolled === 0 ? '✓' : '✗') + ' (' + calRolled + ' rolled)');
  assert(calRolled === 0, 'no calendarEvents have phase3RolloverRev=1', 'L-9');

  // L-10 — substitutes NOT carried
  const subRolled = (await fs.collection('substitutes')
    .where('schoolId','==',SCHOOL).where('phase3RolloverRev','==',1).get()).size;
  console.log('L-10 substitutes NOT carried   : ' + (subRolled === 0 ? '✓' : '✗') + ' (' + subRolled + ' rolled)');
  assert(subRolled === 0, 'no substitutes have phase3RolloverRev=1', 'L-10');

  console.log('\n═══════════════════════════════════════════════════════════════');
  if (fail.length === 0) {
    console.log('  ✔ ALL ASSERTIONS PASSED');
  } else {
    console.log('  ✗ ' + fail.length + ' assertion(s) failed:');
    fail.forEach(f => console.log('    • ' + f.msg + ' @ ' + f.ctx));
  }
  console.log('═══════════════════════════════════════════════════════════════');
  process.exit(fail.length > 0 ? 1 : 0);
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
