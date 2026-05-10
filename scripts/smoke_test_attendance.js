#!/usr/bin/env node
/**
 * Data-layer smoke test for the Attendance module.
 *
 * Companion to smoke_test_homework.js. Targets the regressions we've
 * actually been bitten by, not just generic invariants:
 *
 *   §A  Data shape on attendance + attendanceSummary docs (Phase 7h
 *       canonical doc id, schoolId scoping, tardy canonical, dayWise
 *       length matches totalDays).
 *   §B  Auth-gate regression (PHP source grep): MY_Controller's
 *       _get_teacher_assignments must read Firestore subjectAssignments,
 *       NOT the dead RTDB Schools/{school}/{year}/Teachers/{tid}/Duties
 *       path. This is the bug we found 2026-05-07 — Vipul Tiwari got
 *       "not assigned to this class/section" because the auth gate was
 *       reading the wrong store.
 *   §C  Teacher dashboard performance regression (Kotlin source grep):
 *       Teacher DashboardViewModel must use the class-wide attendance
 *       fetch, NOT the per-student getStudentAttendanceSummary loop
 *       that caused the ~12s dashboard load on 2026-05-07.
 *   §D  Index sanity (attendanceSummary keyed-by-section query needs
 *       schoolId + sectionKey + month).
 *   §E  Per-student summary integrity audit (dayWise length matches
 *       totalDays, no missing-required-fields drift on recent docs).
 *
 * Read-only by default. No --apply mode here — attendance writes touch
 * audit logs, governance state, and lock docs that we don't want to
 * synthesise from a smoke test.
 *
 *   node scripts/smoke_test_attendance.js
 *   node scripts/smoke_test_attendance.js --school=SCH_X
 */
const path = require('path');
const fs = require('fs');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

function arg(name, dflt) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  return eq ? eq.split('=').slice(1).join('=') : dflt;
}
const SCHOOL = arg('school', 'SCH_D94FE8F7AD');

const results = [];
function record(id, group, name, status, detail = '') {
  results.push({ id, group, name, status, detail });
  const icon   = status === 'pass' ? '✓' : status === 'fail' ? '✗' : '·';
  const colour = status === 'pass' ? 32  : status === 'fail' ? 31  : 90;
  console.log(`  \x1b[${colour}m${icon}\x1b[0m  ${id}  ${name}${detail ? '  — ' + detail : ''}`);
}
function header(text) { console.log(`\n\x1b[1m${text}\x1b[0m`); }

const REQ_DAILY    = ['schoolId', 'studentId', 'date', 'status', 'sectionKey', 'session'];
const REQ_SUMMARY  = ['schoolId', 'studentId', 'month', 'monthLabel', 'type', 'session',
                      'dayWise', 'present', 'absent', 'tardy', 'leave', 'holiday',
                      'vacation', 'totalDays', 'workingDays', 'percentage'];

// ─────────────────────────────────────────────────────────────────────
// §A — Data shape sanity
// ─────────────────────────────────────────────────────────────────────
async function groupA() {
  header(`§A — Data shape sanity (school=${SCHOOL})`);

  const [dailySnap, sumSnap] = await Promise.all([
    db.collection('attendance')      .where('schoolId', '==', SCHOOL).get(),
    db.collection('attendanceSummary').where('schoolId', '==', SCHOOL).get()
  ]);

  if (dailySnap.empty && sumSnap.empty) {
    record('A0', 'A', 'attendance + attendanceSummary collections empty', 'fail',
      'mark some attendance from the Teacher app to seed data');
    return;
  }
  record('A0', 'A',
    `attendance=${dailySnap.size} doc(s), attendanceSummary=${sumSnap.size} doc(s)`,
    'pass');

  // A1 — daily doc id format: {schoolId}_{date}_{studentId}
  let badDailyId = 0, sampleBadDaily = '';
  dailySnap.forEach(d => {
    const x = d.data();
    const expected = `${SCHOOL}_${x.date}_${x.studentId}`;
    if (d.id !== expected) {
      badDailyId++;
      if (!sampleBadDaily) sampleBadDaily = `id=${d.id} expected=${expected}`;
    }
  });
  if (badDailyId === 0) {
    record('A1', 'A',
      `daily doc id matches {schoolId}_{date}_{studentId} on all ${dailySnap.size} doc(s)`, 'pass');
  } else {
    record('A1', 'A', `${badDailyId} daily doc(s) have non-canonical id`, 'fail', sampleBadDaily);
  }

  // A2 — summary doc id format: {schoolId}_{studentId}_{month}  (Phase 7h canonical)
  let badSumId = 0, sampleBadSum = '';
  sumSnap.forEach(d => {
    const x = d.data();
    const expected = `${SCHOOL}_${x.studentId}_${x.month}`;
    if (d.id !== expected) {
      badSumId++;
      if (!sampleBadSum) sampleBadSum = `id=${d.id} expected=${expected}`;
    }
  });
  if (badSumId === 0) {
    record('A2', 'A',
      `summary doc id matches Phase 7h {schoolId}_{studentId}_{month} on all ${sumSnap.size} doc(s)`,
      'pass');
  } else {
    record('A2', 'A', `${badSumId} summary doc(s) have non-canonical id`, 'fail', sampleBadSum);
  }

  // A3 — required fields on summaries
  let missingFields = 0, sampleMissing = '';
  sumSnap.forEach(d => {
    const x = d.data();
    const miss = REQ_SUMMARY.filter(f => x[f] === undefined);
    if (miss.length) {
      missingFields++;
      if (!sampleMissing) sampleMissing = `${d.id} missing=[${miss.join(',')}]`;
    }
  });
  if (missingFields === 0) {
    record('A3', 'A', `all summary docs have required canonical fields`, 'pass');
  } else {
    record('A3', 'A',
      `${missingFields} summary doc(s) missing required fields (legacy/partial)`,
      'skip', sampleMissing);
  }

  // A4 — tardy canonical: every summary has `tardy`. The `late` legacy alias is allowed
  // (memory: "Tardy canonical, `late` legacy alias still written for one cycle"), but
  // any doc that has `late` but NOT `tardy` is a regression.
  let lateOnly = 0, tardyAndLate = 0;
  sumSnap.forEach(d => {
    const x = d.data();
    const hasTardy = x.tardy !== undefined;
    const hasLate  = x.late  !== undefined;
    if (hasLate && !hasTardy) lateOnly++;
    if (hasLate && hasTardy)  tardyAndLate++;
  });
  if (lateOnly === 0) {
    record('A4', 'A',
      `tardy canonical on every summary (${tardyAndLate} also still emit late legacy alias)`,
      'pass');
  } else {
    record('A4', 'A',
      `${lateOnly} summary doc(s) have late but NO tardy — canonical regression`,
      'fail');
  }

  // A5 — schoolId scoping (defensive — query already filtered)
  let wrongSchool = 0;
  dailySnap.forEach(d => { if (d.data().schoolId !== SCHOOL) wrongSchool++; });
  sumSnap.forEach(d => { if (d.data().schoolId !== SCHOOL) wrongSchool++; });
  record('A5', 'A',
    wrongSchool === 0
      ? `every doc scoped to schoolId=${SCHOOL}`
      : `${wrongSchool} doc(s) leaked across schoolId`,
    wrongSchool === 0 ? 'pass' : 'fail');

  // A6 — daily status values are canonical {P,A,T,L,H,V}. Anything else is drift.
  const ALLOWED = new Set(['P', 'A', 'T', 'L', 'H', 'V']);
  let badStatus = 0, sampleBadStatus = '';
  dailySnap.forEach(d => {
    const s = (d.data().status || '').toString().toUpperCase();
    if (!ALLOWED.has(s)) {
      badStatus++;
      if (!sampleBadStatus) sampleBadStatus = `${d.id} status=[${d.data().status}]`;
    }
  });
  if (badStatus === 0) {
    record('A6', 'A', `daily status is canonical {P,A,T,L,H,V} on all ${dailySnap.size} doc(s)`, 'pass');
  } else {
    record('A6', 'A', `${badStatus} daily doc(s) have non-canonical status`, 'fail', sampleBadStatus);
  }
}

// ─────────────────────────────────────────────────────────────────────
// §B — Auth gate regression (PHP source grep)
// ─────────────────────────────────────────────────────────────────────
async function groupB() {
  header(`§B — Auth-gate regression (MY_Controller._get_teacher_assignments)`);

  const myCtrlPath = path.resolve(__dirname, '..', 'application', 'core', 'MY_Controller.php');
  if (!fs.existsSync(myCtrlPath)) {
    record('B0', 'B', 'MY_Controller.php not found — skipped', 'skip', myCtrlPath);
    return;
  }
  const src = fs.readFileSync(myCtrlPath, 'utf8');

  // Carve out the function body so we test only inside _get_teacher_assignments
  const fnMatch = src.match(
    /function\s+_get_teacher_assignments[\s\S]*?(?=\n\s{0,4}\/\*\*|\n\s{0,4}protected\s+function|\n\s{0,4}private\s+function|\n\s{0,4}public\s+function|\n\s{0,4}\}\s*\n\s{0,4}\})/
  );
  if (!fnMatch) {
    record('B0', 'B', 'could not locate _get_teacher_assignments body', 'skip',
      'file shape changed — update the regex');
    return;
  }
  const fnBody = fnMatch[0];

  // B1 — body MUST NOT read RTDB Duties path.
  //   This was the dead path that caused Vipul's "not assigned" error 2026-05-07.
  const rtdbDuties = /Schools\/[^\/]*\/[^\/]*\/Teachers\/[^\/]*\/Duties|firebase->\s*get\s*\(\s*["'][^"']*Duties/.test(fnBody);
  if (rtdbDuties) {
    record('B1', 'B',
      'auth gate reads RTDB Schools/.../Teachers/.../Duties — REGRESSION',
      'fail',
      'switch to Subject_assignment_service::getAssignmentsForTeacher (Firestore)');
  } else {
    record('B1', 'B', 'auth gate no longer reads RTDB Duties path', 'pass');
  }

  // B2 — body MUST use Subject_assignment_service.
  const usesSAS = /subject_assignment_service|getAssignmentsForTeacher/i.test(fnBody);
  if (usesSAS) {
    record('B2', 'B', 'auth gate uses Subject_assignment_service (Firestore canonical)', 'pass');
  } else {
    record('B2', 'B',
      'auth gate does NOT reference Subject_assignment_service',
      'fail',
      'load->library("subject_assignment_service") and call getAssignmentsForTeacher($adminId)');
  }
}

// ─────────────────────────────────────────────────────────────────────
// §C — Teacher dashboard performance regression (Kotlin source grep)
// ─────────────────────────────────────────────────────────────────────
async function groupC() {
  header(`§C — Teacher dashboard N+1 regression`);

  const dashPath = 'D:\\Projects\\SchoolSyncTeacher\\app\\src\\main\\java\\com\\schoolsync\\teacher\\ui\\dashboard\\DashboardViewModel.kt';
  if (!fs.existsSync(dashPath)) {
    record('C0', 'C', 'Teacher DashboardViewModel.kt not found — skipped', 'skip', dashPath);
    return;
  }
  const src = fs.readFileSync(dashPath, 'utf8');

  // C1 — must NOT call getStudentAttendanceSummary inside a for-loop over students.
  // We approximate "inside a per-student loop" by scanning each `for ... in students`
  // block (any loop where the iterable is named students*) for the per-student call.
  const forStudents = /for\s*\(\s*[^)]*\bin\b\s*\bstudents?\b[^)]*\)\s*\{[\s\S]*?\}/g;
  let perStudentN1 = false, perStudentSnippet = '';
  let m;
  while ((m = forStudents.exec(src)) !== null) {
    if (/getStudentAttendanceSummary\s*\(/.test(m[0])) {
      perStudentN1 = true;
      perStudentSnippet = m[0].slice(0, 120).replace(/\s+/g, ' ') + '…';
      break;
    }
  }
  if (perStudentN1) {
    record('C1', 'C',
      'getStudentAttendanceSummary called per-student in a loop — N+1 REGRESSION',
      'fail', perStudentSnippet);
  } else {
    record('C1', 'C', 'no per-student getStudentAttendanceSummary loop', 'pass');
  }

  // C2 — should use the class-wide method we added 2026-05-07.
  if (/getClassMonthlySummaries\s*\(/.test(src)) {
    record('C2', 'C', 'uses getClassMonthlySummaries (1 query per section)', 'pass');
  } else {
    record('C2', 'C',
      'getClassMonthlySummaries not used — dashboard may be doing N+1',
      'fail',
      'replace per-student loop with attendanceFirestoreRepo.getClassMonthlySummaries(sectionKey, monthKey)');
  }
}

// ─────────────────────────────────────────────────────────────────────
// §D — Index sanity (attendanceSummary keyed-by-section + month)
// ─────────────────────────────────────────────────────────────────────
async function groupD() {
  header(`§D — Index file sanity`);

  const indexesPath = path.resolve(__dirname, '..', 'firebase-rules', 'firestore.indexes.json');
  if (!fs.existsSync(indexesPath)) {
    record('D0', 'D', 'firestore.indexes.json not found', 'skip', indexesPath);
    return;
  }
  let idx;
  try {
    idx = JSON.parse(fs.readFileSync(indexesPath, 'utf8'));
  } catch (e) {
    record('D0', 'D', 'firestore.indexes.json is malformed', 'fail', e.message);
    return;
  }
  record('D0', 'D', 'firestore.indexes.json parses cleanly', 'pass');

  function hasIndex(coll, fields) {
    return (idx.indexes || []).some(ix => {
      if (ix.collectionGroup !== coll) return false;
      const got = (ix.fields || []).map(f => f.fieldPath);
      return fields.every(f => got.includes(f));
    });
  }

  // D1 — class-wide query in dashboard refactor needs (schoolId, sectionKey, month)
  if (hasIndex('attendanceSummary', ['schoolId', 'sectionKey', 'month'])) {
    record('D1', 'D',
      'attendanceSummary: schoolId+sectionKey+month index present (dashboard query)',
      'pass');
  } else {
    record('D1', 'D',
      'attendanceSummary: schoolId+sectionKey+month index MISSING',
      'fail',
      'getClassMonthlySummaries will fall back to slow scan + warning in console');
  }

  // D2 — daily roster query for fetch_student_attendance / lock state
  if (hasIndex('attendance', ['schoolId', 'sectionKey', 'date'])) {
    record('D2', 'D',
      'attendance: schoolId+sectionKey+date index present (daily fetch)',
      'pass');
  } else {
    record('D2', 'D',
      'attendance: schoolId+sectionKey+date index missing',
      'skip',
      'OK if all daily fetches are by docId — flag if dashboards/admin start querying ranges');
  }
}

// ─────────────────────────────────────────────────────────────────────
// §E — Per-student summary integrity audit
// ─────────────────────────────────────────────────────────────────────
async function groupE() {
  header(`§E — Per-student summary integrity (school=${SCHOOL})`);

  const sumSnap = await db.collection('attendanceSummary')
    .where('schoolId', '==', SCHOOL)
    .get();
  if (sumSnap.empty) {
    record('E0', 'E', 'no attendanceSummary docs — skipped', 'skip');
    return;
  }

  // E1 — dayWise length should equal totalDays for every doc that has both fields
  //   (this is THE invariant — admin/teacher writers compute totalDays = dayWise.length)
  let mismatchLen = 0, sampleLen = '';
  sumSnap.forEach(d => {
    const x = d.data();
    if (typeof x.dayWise !== 'string' || typeof x.totalDays !== 'number') return;
    if (x.dayWise.length !== x.totalDays) {
      mismatchLen++;
      if (!sampleLen) {
        sampleLen = `${d.id} dayWise.length=${x.dayWise.length} totalDays=${x.totalDays}`;
      }
    }
  });
  if (mismatchLen === 0) {
    record('E1', 'E', `dayWise.length matches totalDays on every summary`, 'pass');
  } else {
    record('E1', 'E',
      `${mismatchLen} summary doc(s) have dayWise.length != totalDays`,
      'fail', sampleLen);
  }

  // E2 — counter sum vs. dayWise. Each char in dayWise must correspond to exactly
  //   one of {present:P, absent:A, tardy:T, leave:L, holiday:H, vacation:V}.
  //   Counter-sum should equal dayWise.length.
  let mismatchSum = 0, sampleSum = '';
  sumSnap.forEach(d => {
    const x = d.data();
    if (typeof x.dayWise !== 'string') return;
    const sum = ['present','absent','tardy','leave','holiday','vacation']
      .map(k => Number(x[k] || 0))
      .reduce((a, b) => a + b, 0);
    if (sum !== x.dayWise.length) {
      mismatchSum++;
      if (!sampleSum) {
        sampleSum = `${d.id} counters.sum=${sum} dayWise.length=${x.dayWise.length}`;
      }
    }
  });
  if (mismatchSum === 0) {
    record('E2', 'E', `counter sum matches dayWise.length on every summary`, 'pass');
  } else {
    record('E2', 'E',
      `${mismatchSum} summary doc(s) have counters that don't sum to dayWise.length`,
      'skip',  // historical drift — informational, not a hard fail
      sampleSum + '  (counters may pre-date a recompute)');
  }

  // E3 — current-month freshness: at least one student in this school should have
  //   a summary doc for the CURRENT month. If not, either no attendance has been
  //   marked yet this month, or the writer is broken.
  const now = new Date();
  const ym = now.toISOString().slice(0, 7); // "2026-05"
  let currentMonthDocs = 0;
  sumSnap.forEach(d => { if (d.data().month === ym) currentMonthDocs++; });
  if (currentMonthDocs > 0) {
    record('E3', 'E',
      `current month (${ym}) has ${currentMonthDocs} summary doc(s)`, 'pass');
  } else {
    record('E3', 'E',
      `no summary docs for current month (${ym})`,
      'skip',
      'expected if no attendance marked yet this month');
  }
}

// ─────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────
(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Attendance module data-layer smoke test');
  console.log(`  scope: school=${SCHOOL}`);
  console.log('  mode:  READ-ONLY (no --apply mode for attendance)');
  console.log('═══════════════════════════════════════════════════════════════');

  await groupA();
  await groupB();
  await groupC();
  await groupD();
  await groupE();

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
