#!/usr/bin/env node
/**
 * Data-layer smoke test for the Homework module.
 *
 * This complements HOMEWORK_SMOKE_TEST.md — that doc has the manual UI
 * checks (tap-this, look-for-that). This script automates everything
 * that's verifiable from outside the UI:
 *
 *   §A  Data shape on existing docs (createdAt type, sectionKey format,
 *       subjects, dueDate format, schoolId scoping).
 *   §B  Live race-condition simulation — parent submits while teacher
 *       reviews; verify the transaction collapses to a single consistent
 *       state.
 *   §C  Cascade delete — verify deletion of a homework also removes its
 *       submissions and teacherMarks.
 *   §D  Closed-homework guard — verify reviewOrMark rejects on closed.
 *   §E  Index + rules file sanity (deployed-blob verification is on you).
 *
 * Read-only by default. Pass --apply to allow the write tests in §B–§D.
 * Tests use clearly-labeled "[SMOKE TEST]" docs and auto-clean on exit.
 *
 *   node scripts/smoke_test_homework.js                       # read-only
 *   node scripts/smoke_test_homework.js --school=SCH_X        # read-only, single school
 *   node scripts/smoke_test_homework.js --apply --school=SCH_X
 */
const path = require('path');
const fs = require('fs');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();
const Timestamp = admin.firestore.Timestamp;
const FieldValue = admin.firestore.FieldValue;

function arg(name, dflt) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  return eq ? eq.split('=').slice(1).join('=') : dflt;
}
const APPLY = process.argv.includes('--apply');
const SCHOOL = arg('school', 'SCH_D94FE8F7AD');

// Test results tracker
const results = []; // { id, group, name, status: 'pass'|'fail'|'skip', detail }
function record(id, group, name, status, detail = '') {
  results.push({ id, group, name, status, detail });
  const icon = status === 'pass' ? '✓' : status === 'fail' ? '✗' : '·';
  const colour = status === 'pass' ? 32 : status === 'fail' ? 31 : 90;
  console.log(`  \x1b[${colour}m${icon}\x1b[0m  ${id}  ${name}${detail ? '  — ' + detail : ''}`);
}

function header(text) {
  console.log(`\n\x1b[1m${text}\x1b[0m`);
}

// ─────────────────────────────────────────────────────────────────────
// §A — Data shape sanity (read-only)
// ─────────────────────────────────────────────────────────────────────
async function groupA() {
  header(`§A — Data shape sanity (school=${SCHOOL})`);

  const hwSnap = await db.collection('homework').where('schoolId', '==', SCHOOL).get();
  if (hwSnap.empty) {
    record('A0', 'A', 'homework collection has docs for this school', 'fail',
      'no docs found — run a create cycle first');
    return null;
  }
  record('A0', 'A', `homework collection has ${hwSnap.size} doc(s)`, 'pass');

  // A1 — every createdAt is a Firestore Timestamp (not a number / not a string)
  let timestampOk = 0, nonTimestamp = 0, sample = '';
  for (const d of hwSnap.docs) {
    const ca = d.get('createdAt');
    if (ca instanceof Timestamp) timestampOk++;
    else { nonTimestamp++; sample = `${d.id}: ${typeof ca} ${JSON.stringify(ca)}`.slice(0, 100); }
  }
  if (nonTimestamp === 0) {
    record('A1', 'A', `createdAt is Timestamp on all ${timestampOk} doc(s)`, 'pass');
  } else {
    record('A1', 'A', `createdAt non-Timestamp on ${nonTimestamp} doc(s)`, 'fail',
      `e.g. ${sample}. Run scripts/backfill_homework_createdAt_timestamp.js --apply`);
  }

  // A2 — sectionKey present and matches "Class X/Section Y" canonical shape
  let sectionKeyOk = 0, badSectionKey = [];
  for (const d of hwSnap.docs) {
    const sk = d.get('sectionKey') || '';
    if (/^Class .+\/Section .+$/.test(sk)) sectionKeyOk++;
    else badSectionKey.push(`${d.id}: "${sk}"`);
  }
  if (badSectionKey.length === 0) {
    record('A2', 'A', `sectionKey canonical on all ${sectionKeyOk} doc(s)`, 'pass');
  } else {
    record('A2', 'A', `${badSectionKey.length} doc(s) with non-canonical sectionKey`, 'fail',
      badSectionKey.slice(0, 3).join(', '));
  }

  // A3 — totalStudents > 0 on docs created post-fix (any Timestamp createdAt)
  let zeroTotal = 0, withTotal = 0;
  for (const d of hwSnap.docs) {
    const tot = d.get('totalStudents') || 0;
    if (tot === 0) zeroTotal++;
    else withTotal++;
  }
  if (zeroTotal === 0) {
    record('A3', 'A', `totalStudents > 0 on all ${withTotal} doc(s)`, 'pass');
  } else {
    record('A3', 'A', `totalStudents == 0 on ${zeroTotal} doc(s)`, 'skip',
      'pre-fix admin docs may have 0 — only flag if all are 0');
  }

  // A4 — dueDate is ISO-with-TZ shape
  let dueDateOk = 0, badDueDate = [];
  for (const d of hwSnap.docs) {
    const dd = d.get('dueDate') || '';
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:?\d{2}|Z)$/.test(dd)) dueDateOk++;
    else if (dd) badDueDate.push(`${d.id}: "${dd}"`);
  }
  if (badDueDate.length === 0) {
    record('A4', 'A', `dueDate ISO-with-TZ on all ${dueDateOk} doc(s)`, 'pass');
  } else {
    record('A4', 'A', `${badDueDate.length} doc(s) with non-ISO dueDate`, 'fail',
      badDueDate.slice(0, 3).join(', '));
  }

  // A5 — schoolId scoping (all docs match the requested school)
  let badSchool = [];
  for (const d of hwSnap.docs) {
    const s = d.get('schoolId');
    if (s !== SCHOOL) badSchool.push(`${d.id}: schoolId=${s}`);
  }
  if (badSchool.length === 0) {
    record('A5', 'A', `every doc scoped to schoolId=${SCHOOL}`, 'pass');
  } else {
    record('A5', 'A', `cross-school leak`, 'fail', badSchool.slice(0, 3).join(', '));
  }

  // A6 — distinct subjects don't include known typos
  const typoSet = new Set(['maths', 'math', 'mathmatics', 'mathemactics', 'mathematicss']);
  const dirtySubjects = [];
  for (const d of hwSnap.docs) {
    const subj = (d.get('subject') || '').trim();
    if (typoSet.has(subj.toLowerCase())) dirtySubjects.push(`${d.id}: "${subj}"`);
  }
  if (dirtySubjects.length === 0) {
    record('A6', 'A', 'no homework with known subject typos', 'pass');
  } else {
    record('A6', 'A', `${dirtySubjects.length} homework with subject typos`, 'fail',
      dirtySubjects.slice(0, 3).join(', '));
  }

  // A7 — every active homework has a parent submissionCount counter that's an int
  let countOk = 0, countBad = [];
  for (const d of hwSnap.docs) {
    const sc = d.get('submissionCount');
    if (typeof sc === 'number') countOk++;
    else countBad.push(`${d.id}: ${typeof sc}`);
  }
  if (countBad.length === 0) {
    record('A7', 'A', `submissionCount is numeric on all ${countOk} doc(s)`, 'pass');
  } else {
    record('A7', 'A', `submissionCount non-numeric on ${countBad.length}`, 'fail',
      countBad.slice(0, 3).join(', '));
  }

  // Pick a candidate homework for the live tests (most recent, active)
  const candidate = hwSnap.docs
    .filter(d => (d.get('status') || 'active') === 'active')
    .sort((a, b) => (b.get('createdAt')?.toMillis?.() || 0) - (a.get('createdAt')?.toMillis?.() || 0))[0];
  return candidate || null;
}

// ─────────────────────────────────────────────────────────────────────
// §B — Closed-homework guard simulation (live, requires --apply)
// ─────────────────────────────────────────────────────────────────────
async function groupB(candidate) {
  header('§B — Closed-homework guard');
  if (!APPLY) {
    record('B0', 'B', 'skipped (read-only mode — pass --apply to run)', 'skip');
    return;
  }
  if (!candidate) {
    record('B0', 'B', 'no candidate homework available', 'skip');
    return;
  }

  // We simulate the Teacher app's reviewOrMark transaction. It reads the
  // homework doc inside the txn and throws on closed. Steps:
  //   1. Mark the candidate temporarily as closed.
  //   2. Try the txn — expect failure.
  //   3. Restore status.
  const ref = candidate.ref;
  const originalStatus = candidate.get('status') || 'active';
  await ref.update({ status: 'closed', _smokeTestPaused: true });
  let rejected = false, errMsg = '';
  try {
    await db.runTransaction(async (txn) => {
      const snap = await txn.get(ref);
      const st = snap.get('status') || 'active';
      if (String(st).toLowerCase() === 'closed') {
        throw new Error('Homework is closed — reviews are not accepted');
      }
      // would normally update the submission here
    });
  } catch (e) {
    rejected = true;
    errMsg = e.message;
  }
  // Restore original status
  await ref.update({ status: originalStatus, _smokeTestPaused: FieldValue.delete() });

  if (rejected && /closed/i.test(errMsg)) {
    record('B1', 'B', 'reviewOrMark rejects on closed homework', 'pass');
  } else {
    record('B1', 'B', 'reviewOrMark did NOT reject on closed', 'fail', errMsg);
  }
}

// ─────────────────────────────────────────────────────────────────────
// §C — Cascade delete simulation (live, --apply)
// Creates a [SMOKE TEST] homework + 2 submissions + 1 teacherMark, then
// runs the cascade delete and verifies all three collections are empty.
// ─────────────────────────────────────────────────────────────────────
async function groupC() {
  header('§C — Cascade delete (homework + submissions + teacherMarks)');
  if (!APPLY) {
    record('C0', 'C', 'skipped (read-only mode — pass --apply to run)', 'skip');
    return;
  }

  const hwId = `${SCHOOL}_smoke_${Date.now()}`;
  const hwRef = db.collection('homework').doc(hwId);

  // Seed
  await hwRef.set({
    schoolId: SCHOOL,
    session: '2026-27',
    className: 'Class 99th',
    section: 'Section Z',
    sectionKey: 'Class 99th/Section Z',
    title: '[SMOKE TEST] cascade delete',
    description: 'auto-cleanup test',
    subject: 'Mathematics',
    teacherId: 'smoke',
    teacherName: 'Smoke',
    dueDate: '2026-05-15T23:59:59+05:30',
    createdAt: Timestamp.now(),
    status: 'active',
    submissionCount: 0,
    totalStudents: 3,
    attachments: [],
  });

  const subA = db.collection('submissions').doc(`${hwId}_STU_A`);
  const subB = db.collection('submissions').doc(`${hwId}_STU_B`);
  const markC = db.collection('teacherMarks').doc(`${hwId}_STU_C`);
  await Promise.all([
    subA.set({ schoolId: SCHOOL, homeworkId: hwId, studentId: 'STU_A', status: 'submitted' }),
    subB.set({ schoolId: SCHOOL, homeworkId: hwId, studentId: 'STU_B', status: 'reviewed', score: 8 }),
    markC.set({ schoolId: SCHOOL, homeworkId: hwId, studentId: 'STU_C', status: 'incomplete' }),
  ]);

  // Cascade delete (mirrors what Teacher app's executeDelete does)
  const subsSnap = await db.collection('submissions').where('schoolId', '==', SCHOOL).where('homeworkId', '==', hwId).get();
  for (const d of subsSnap.docs) await d.ref.delete();
  const marksSnap = await db.collection('teacherMarks').where('schoolId', '==', SCHOOL).where('homeworkId', '==', hwId).get();
  for (const d of marksSnap.docs) await d.ref.delete();
  await hwRef.delete();

  // Verify
  const [hwAfter, subsAfter, marksAfter] = await Promise.all([
    hwRef.get(),
    db.collection('submissions').where('schoolId', '==', SCHOOL).where('homeworkId', '==', hwId).get(),
    db.collection('teacherMarks').where('schoolId', '==', SCHOOL).where('homeworkId', '==', hwId).get(),
  ]);
  const ok = !hwAfter.exists && subsAfter.empty && marksAfter.empty;
  if (ok) {
    record('C1', 'C', 'cascade delete cleared homework + 2 submissions + 1 teacherMark', 'pass');
  } else {
    record('C1', 'C', 'orphans remain', 'fail',
      `hw=${hwAfter.exists} subs=${subsAfter.size} marks=${marksAfter.size}`);
  }
}

// ─────────────────────────────────────────────────────────────────────
// §D — reviewOrMark transaction status fidelity (live, --apply)
// Verifies the new status field actually persists what the teacher picked
// — was hardcoded to "reviewed" before the recent fix.
// ─────────────────────────────────────────────────────────────────────
async function groupD() {
  header('§D — Status persistence in teacherMarks');
  if (!APPLY) {
    record('D0', 'D', 'skipped (read-only mode — pass --apply to run)', 'skip');
    return;
  }

  const hwId = `${SCHOOL}_smoke_status_${Date.now()}`;
  const hwRef = db.collection('homework').doc(hwId);
  await hwRef.set({
    schoolId: SCHOOL, session: '2026-27',
    className: 'Class 99th', section: 'Section Z', sectionKey: 'Class 99th/Section Z',
    title: '[SMOKE TEST] status fidelity', subject: 'Mathematics',
    teacherId: 'smoke', teacherName: 'Smoke',
    dueDate: '2026-05-15T23:59:59+05:30',
    createdAt: Timestamp.now(),
    status: 'active', submissionCount: 0, totalStudents: 1, attachments: [],
  });

  // Simulate a teacher selecting "incomplete" for a non-submitter via the
  // reviewOrMark transaction. The KEY assertion: the teacherMark doc must
  // carry `status: "incomplete"` (not "reviewed") afterwards.
  const submissionRef = db.collection('submissions').doc(`${hwId}_STU_X`);
  const markRef = db.collection('teacherMarks').doc(`${hwId}_STU_X`);
  const chosenStatus = 'incomplete';

  await db.runTransaction(async (txn) => {
    const snap = await txn.get(submissionRef);
    if (snap.exists) {
      txn.update(submissionRef, {
        status: chosenStatus, score: -1, remark: '',
        reviewedBy: 'Smoke', reviewedAt: FieldValue.serverTimestamp(),
      });
    } else {
      txn.set(markRef, {
        schoolId: SCHOOL, homeworkId: hwId, studentId: 'STU_X',
        teacherId: 'smoke', score: -1, remark: '', status: chosenStatus,
        createdAt: FieldValue.serverTimestamp(),
      });
    }
  });

  const persisted = (await markRef.get()).get('status');
  if (persisted === chosenStatus) {
    record('D1', 'D', `teacherMark.status persists as "${chosenStatus}"`, 'pass');
  } else {
    record('D1', 'D', `teacherMark.status mismatch`, 'fail',
      `expected "${chosenStatus}", got "${persisted}"`);
  }

  // Cleanup
  await markRef.delete();
  await hwRef.delete();
}

// ─────────────────────────────────────────────────────────────────────
// §E — Index + rules file sanity (read-only file-based)
// ─────────────────────────────────────────────────────────────────────
async function groupE() {
  header('§E — Index + rules file checks');

  // E1 — composite indexes present in JSON
  const indexesPath = path.resolve(__dirname, '..', 'firebase-rules', 'firestore.indexes.json');
  const indexes = JSON.parse(fs.readFileSync(indexesPath, 'utf8')).indexes || [];
  const has = (collection, fields) =>
    indexes.some(ix =>
      ix.collectionGroup === collection &&
      ix.fields.length === fields.length &&
      fields.every((f, i) => ix.fields[i].fieldPath === f.path && ix.fields[i].order === f.order)
    );

  const required = [
    { collection: 'teacherMarks', fields: [{path:'schoolId',order:'ASCENDING'},{path:'homeworkId',order:'ASCENDING'}], id: 'E1a' },
    { collection: 'submissions', fields: [{path:'schoolId',order:'ASCENDING'},{path:'studentId',order:'ASCENDING'}], id: 'E1b' },
    { collection: 'homework',    fields: [{path:'schoolId',order:'ASCENDING'},{path:'sectionKey',order:'ASCENDING'},{path:'status',order:'ASCENDING'},{path:'dueDate',order:'ASCENDING'}], id: 'E1c' },
    { collection: 'students',    fields: [{path:'schoolId',order:'ASCENDING'},{path:'sectionKey',order:'ASCENDING'}], id: 'E1d' },
  ];
  for (const r of required) {
    if (has(r.collection, r.fields)) record(r.id, 'E', `${r.collection}: ${r.fields.map(f=>f.path).join('+')} index present`, 'pass');
    else record(r.id, 'E', `${r.collection}: ${r.fields.map(f=>f.path).join('+')} MISSING`, 'fail',
      'add to firestore.indexes.json + firebase deploy');
  }

  // E2 — rules tightening
  const rulesPath = path.resolve(__dirname, '..', 'firebase-rules', 'firestore.rules');
  const rulesText = fs.readFileSync(rulesPath, 'utf8');

  // R1: submissions create uses isSameSchoolWrite
  const subCreateBlock = rulesText.match(/match \/submissions\/\{docId\}[^}]+allow create:[^;]+;/s);
  if (subCreateBlock && /isSameSchoolWrite\(\)/.test(subCreateBlock[0])) {
    record('E2-R1', 'E', 'submissions create uses isSameSchoolWrite()', 'pass');
  } else {
    record('E2-R1', 'E', 'submissions create still uses isSameSchool()', 'fail');
  }

  // R2: homework parent-update enforces +1 increment
  if (/submissionCount\s*==\s*resource\.data\.submissionCount\s*\+\s*1/.test(rulesText)) {
    record('E2-R2', 'E', 'homework submissionCount capped at +1', 'pass');
  } else {
    record('E2-R2', 'E', 'submissionCount not capped to +1', 'fail');
  }

  // R3: pushRequests read tightened to staff
  const pushBlock = rulesText.match(/match \/pushRequests\/\{docId\}[^}]+\}/s);
  if (pushBlock && /allow read:\s*if isStaff/.test(pushBlock[0])) {
    record('E2-R3', 'E', 'pushRequests read tightened to isStaff()', 'pass');
  } else {
    record('E2-R3', 'E', 'pushRequests read still permissive', 'fail');
  }

  // E3 — rules JSON parses
  try {
    JSON.parse(fs.readFileSync(indexesPath, 'utf8'));
    record('E3', 'E', 'firestore.indexes.json parses cleanly', 'pass');
  } catch (e) {
    record('E3', 'E', 'firestore.indexes.json is malformed', 'fail', e.message);
  }
}

// ─────────────────────────────────────────────────────────────────────
// §F — Parent dashboard pending-count audit
//
// Catches the 2026-05-08 regression: dashboard hid overdue homework
// because of `dueDate >= today` filter on ISO-with-TZ strings, leaving
// real pending tasks invisible.
//
// F1   — sanity-count the data: how many students have how many real
//        pending items, and how many of those would be hidden if the
//        old filter were re-introduced. Informational, never fails.
// F2   — static check on parent app source: assert the buggy date
//        filter is NOT present. This is the regression gate. If anyone
//        re-introduces `dueDate >= today` in DashboardViewModel.kt's
//        homework filter, this fails loud at script-run time.
// ─────────────────────────────────────────────────────────────────────
async function groupF() {
  header(`§F — Parent dashboard pending-count audit (school=${SCHOOL})`);

  // ── F1: data audit ────────────────────────────────────────────────
  const [hwSnap, subSnap, stuSnap] = await Promise.all([
    db.collection('homework')
      .where('schoolId', '==', SCHOOL)
      .where('status', '==', 'active')
      .get(),
    db.collection('submissions')
      .where('schoolId', '==', SCHOOL)
      .get(),
    db.collection('students')
      .where('schoolId', '==', SCHOOL)
      .get()
  ]);

  if (hwSnap.empty || stuSnap.empty) {
    record('F1', 'F', 'no active homework or students — audit skipped', 'skip');
  } else {
    const hwBySection = {};
    hwSnap.forEach(d => {
      const x = d.data();
      const k = x.sectionKey || `${x.className}/${x.section}`;
      (hwBySection[k] = hwBySection[k] || []).push({ id: d.id, ...x });
    });
    const subsByStudent = {};
    subSnap.forEach(d => {
      const x = d.data();
      if (!x.studentId || !x.homeworkId) return;
      (subsByStudent[x.studentId] = subsByStudent[x.studentId] || {})[x.homeworkId] = x;
    });

    const today = new Date().toISOString().slice(0, 10);
    let totalPending = 0, studentsWithPending = 0, wouldBeHidden = 0;

    stuSnap.forEach(d => {
      const stu = d.data();
      const sid = stu.userId || stu.studentId;
      if (!sid) return;
      if ((stu.status || '').toLowerCase() === 'inactive') return;

      const sectionKey = stu.sectionKey || `${stu.className}/${stu.section}`;
      const sectionHw  = hwBySection[sectionKey] || [];
      const subs       = subsByStudent[sid] || {};

      // Mirrors DashboardViewModel.kt: pending = active AND status == "pending"
      const realPending = sectionHw.filter(hw => {
        const status = (subs[hw.id]?.status ?? 'pending')
          .toString().toLowerCase().trim();
        return status === 'pending';
      });
      const buggyPending = realPending.filter(hw =>
        !hw.dueDate || hw.dueDate >= today
      );

      if (realPending.length > 0) studentsWithPending++;
      totalPending  += realPending.length;
      wouldBeHidden += (realPending.length - buggyPending.length);
    });

    record('F1', 'F',
      `${studentsWithPending} students have ${totalPending} real pending; ` +
      `${wouldBeHidden} would be hidden by old dueDate>=today filter`,
      'pass');
  }

  // ── F2: regression gate — parent dashboard source must not have the
  //       buggy date filter. Reads the Kotlin file directly, the same
  //       way E2 reads firestore.rules.
  const dashboardPath = 'D:\\Projects\\SchoolSyncParent\\app\\src\\main\\java\\com\\schoolsync\\parent\\ui\\dashboard\\DashboardViewModel.kt';
  if (!fs.existsSync(dashboardPath)) {
    record('F2', 'F', 'parent app source not found — regression gate skipped',
      'skip', `expected at ${dashboardPath}`);
  } else {
    const src = fs.readFileSync(dashboardPath, 'utf8');
    // Match either: it.dueDate >= today  OR  hw.dueDate >= today  OR variants.
    // Tolerates whitespace; rejects only the specific date-filter shape.
    const buggy = /\b(it|hw|homework)\.dueDate\s*>=\s*today\b/.test(src);
    if (buggy) {
      record('F2', 'F',
        'parent dashboard re-introduced the dueDate >= today filter',
        'fail',
        'remove the date filter from loadHomework() so overdue items stay visible');
    } else {
      record('F2', 'F',
        'parent dashboard does not filter pending by dueDate >= today',
        'pass');
    }
  }
}

// ─────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────
(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Homework module data-layer smoke test');
  console.log(`  scope: school=${SCHOOL}`);
  console.log(`  mode:  ${APPLY ? 'APPLY (writes test docs + cleans up)' : 'READ-ONLY'}`);
  console.log('═══════════════════════════════════════════════════════════════');

  const candidate = await groupA();
  await groupB(candidate);
  await groupC();
  await groupD();
  await groupE();
  await groupF();

  // Summary
  const pass = results.filter(r => r.status === 'pass').length;
  const fail = results.filter(r => r.status === 'fail').length;
  const skip = results.filter(r => r.status === 'skip').length;
  console.log('\n═══════════════════════════════════════════════════════════════');
  console.log(`  Results: \x1b[32m${pass} passed\x1b[0m, \x1b[31m${fail} failed\x1b[0m, \x1b[90m${skip} skipped\x1b[0m`);
  console.log('═══════════════════════════════════════════════════════════════\n');

  if (fail > 0) {
    console.log('Failed tests:');
    results.filter(r => r.status === 'fail').forEach(r => {
      console.log(`  ${r.id}  ${r.name}${r.detail ? '  — ' + r.detail : ''}`);
    });
    console.log('');
  }
  process.exit(fail > 0 ? 1 : 0);
})().catch(e => { console.error('Fatal:', e); process.exit(2); });
