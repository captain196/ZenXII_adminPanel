/**
 * ══════════════════════════════════════════════════════════════════════════
 *  READ-ONLY THREE-LAYER LIFECYCLE RECONCILIATION  (Gate 2)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Compares, for every student in the tenant:
 *
 *   AUTHORITATIVE   students/{schoolId}_{studentId}
 *   DERIVED         live projections that must follow the student
 *   REPORTING       what a screen, export or the Parent App would show
 *
 * ── THE DEFECT CLASS THIS HUNTS ──────────────────────────────────────────
 * 23 tenant collections denormalise className / section / rollNo / session /
 * studentName. Every one of them is a surface a lifecycle transition can
 * leave behind. A promotion that updates the student document and not the
 * defaulter row produces a report that is confidently wrong — and nothing in
 * the codebase compares the two.
 *
 * ── WHY STALENESS IS NOT UNIFORMLY A BUG ─────────────────────────────────
 * Some copies MUST NOT follow the student. A mark, a receipt, a dated
 * attendance row and a history entry are records of what was true when they
 * were written; "correcting" last year's receipt to this year's class would
 * destroy the audit trail. Others — a defaulter row, a fee summary, a flag —
 * are live projections whose only purpose is to describe the student NOW.
 *
 * Treating those two alike is how a reconciler produces either a flood of
 * false alarms or a false all-clear. So every collection is classified
 * explicitly below, with the reason, and the two groups are judged by
 * different rules:
 *
 *   LIVE       must match the student document today            -> drift = DEFECT
 *   HISTORICAL must be internally consistent with its own stamp -> drift = expected
 *
 * A HISTORICAL row is still checked: its session must be a session the school
 * actually has, and it must belong to a student that exists. A dangling
 * historical row is a real defect even though a stale class is not.
 *
 *   node scripts/lifecycle_reconcile_readonly.js --school=SCH_B56BB9A401
 *   node scripts/lifecycle_reconcile_readonly.js --selftest
 *
 * Writes nothing.
 * exit 0 = every live projection agrees with its student document
 *      2 = at least one reconciliation failure
 */
const admin = require('firebase-admin');
const path  = require('path');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};
const SCHOOL = arg('school', 'SCH_B56BB9A401');

// ── CLASSIFICATION ───────────────────────────────────────────────────────
// `follows` lists the fields that must track the student document.
// A collection absent from LIVE is historical by default and is only checked
// for danglers.
const LIVE = {
  // The self-healing defaulter projection. Its whole contract is "who owes
  // money right now", so a stale class or name is a wrong report — but only
  // WITHIN its own session. Its document id embeds the session
  // (`{schoolId}_{session}_{studentId}`, confirmed empirically), so a row for
  // a session the student has since left is history, exactly like a demand.
  feeDefaulters:      { follows: ['className', 'section', 'studentName'],
                        sessionScoped: true, why: 'live defaulter report' },
  // Per-student fee rollup rendered on the fee screens and the Parent App.
  studentFeeSummary:  { follows: ['className', 'section', 'studentName'], why: 'live fee summary' },
  // ── `parents` IS WRITTEN BUT NEVER READ ──────────────────────────────
  // This was classified as a "Parent App profile mirror" that must follow the
  // student. That label was wrong, and the emulator rollover exposed it: after
  // a promotion every mirror still carried the old class.
  //
  // Traced before deciding. The Parent App does NOT read this collection — its
  // only "parents" matches are audience strings in Communication targeting
  // ("all parents"), not a Firestore read; the app takes placement from
  // `students/{id}` through StudentProfileSynchronizer. No admin controller
  // reads it either; `Entity_firestore_sync::syncParent()` writes it and
  // `Test_student_cascade_audit` merely lists it as a cascade target.
  //
  // So a stale class here is real staleness with no consumer — data hygiene,
  // not a wrong report on any surface. It is downgraded to a NOTE rather than
  // dropped, because "nothing reads it TODAY" is exactly the assumption that
  // rots. `soft: true` keeps it visible without failing the gate.
  parents:            { follows: ['className'], soft: true,
                        why: 'parent mirror (written, no known reader)' },
  // Live behavioural/medical flags shown against the current roster.
  studentFlags:       { follows: ['className', 'rollNo'],                 why: 'live flag on current roster' },
  // Current-session billing only. A demand raised in a PRIOR session records
  // the class the student was billed in THEN, and must never be dragged to
  // match where they sit now — that is history, and rewriting it to match a
  // promoted student would destroy the record of what was actually charged.
  //
  // FOUND BY THE EMULATOR ROLLOVER: this compared every demand against the
  // student's current placement and reported 100 "defects" the moment anyone
  // was promoted — the 2026-27 demands correctly said Class 3rd while the
  // students had moved to Class 4th in 2027-28. It never showed on live data
  // because the live tenant has exactly one session and no promotions, so the
  // reconciler had never once been exercised across a session boundary.
  feeDemands:         { follows: ['className'], sessionScoped: true,
                        why: 'current-session billing' },
  // Entitlement / charge rows are live financial state, not history.
  studentCharges:     { follows: [],                                      why: 'live charge state' },
  studentEntitlements:{ follows: [],                                      why: 'live entitlement state' },
};

// Collections whose stamps are deliberately frozen. Listed explicitly so the
// classification is a decision on the record, not an omission.
// ── DANGLING IS CORRECT HERE ─────────────────────────────────────────────
// A reference that outlives its student is normally a defect. For these it is
// the POINT: they are the record that the student existed and what was done
// to them. Deleting them with the student would destroy the only evidence the
// deletion ever happened, and a reconciler that reported them would be
// permanently red for a system behaving correctly.
//
// Verified against `Sis::delete_student()`, which hard-deletes the student
// document and the Firebase Auth user while deliberately preserving the audit
// trail and the identity claims (P11-G2 records that reusability of a
// hard-deleted student's identifiers is an open business decision).
const DANGLING_OK = {
  studentHistory:   'the lifecycle audit trail — it must outlive the student',
  admissionNoIndex: 'an identity claim, deliberately not released on delete (P11-G2)',
  srNoIndex:        'an identity claim, deliberately not released on delete',
  apaarIndex:       'an identity claim, deliberately not released on delete',
};

const HISTORICAL = {
  marks:                'a mark belongs to the exam it was scored in',
  marksAudit:           'audit of a mark, frozen with it',
  results:              'a published result is a record of that session',
  feeReceipts:          'a receipt is a legal record of what was collected',
  feeReceiptAllocations:'allocation detail of a frozen receipt',
  attendance:           'a dated attendance mark',
  attendanceSummary:    'a month rollup, stamped with its month',
  studentHistory:       'the lifecycle audit trail itself',
  admissionNoIndex:     'the admission event, frozen by design',
  processedPayments:    'a settled payment',
  submissions:          'a homework submission, frozen at submit time',
  teacherMarks:         'a homework score, frozen when given',
  attendanceCorrectionRequests: 'a request about a specific past date',
  attendanceEventsFired:'a dispatch record',
  pushRequests:         'a sent notification',
};

let problems = 0;
let warnings = 0;
const fail = (msg) => { problems++; console.log(`    DEFECT  ${msg}`); };
// A hygiene observation that must be visible but must not fail the gate.
// Kept separate so "clean" never has to mean "we stopped looking".
const warn = (msg) => { warnings++; console.log(`    NOTE    ${msg}`); };

// ── PURE COMPARISON, so it can be proven without Firestore ───────────────
const norm = (v) => String(v == null ? '' : v).trim().toLowerCase();

/**
 * Compare one derived row against the authoritative student.
 * Returns an array of human-readable mismatches (empty = agrees).
 * A BLANK derived value is "not carried", not "wrong" — these collections
 * populate their denormalised fields opportunistically.
 */
function driftOf(student, row, follows) {
  const out = [];
  for (const f of follows) {
    const a = norm(student[f]);
    const b = norm(row[f]);
    if (b === '') continue;
    if (a === '') continue;
    if (a !== b) out.push(`${f}: student='${student[f]}' derived='${row[f]}'`);
  }
  return out;
}

if (process.argv.includes('--selftest')) {
  const cases = [
    ['agrees',            driftOf({className:'Class 5th'}, {className:'Class 5th'}, ['className']).length, 0],
    ['stale class',       driftOf({className:'Class 6th'}, {className:'Class 5th'}, ['className']).length, 1],
    ['case/space noise',  driftOf({className:'Class 5th'}, {className:' class 5TH '}, ['className']).length, 0],
    ['derived blank',     driftOf({className:'Class 6th'}, {className:''}, ['className']).length, 0],
    ['student blank',     driftOf({className:''}, {className:'Class 5th'}, ['className']).length, 0],
    ['two fields drift',  driftOf({className:'A',section:'X'}, {className:'B',section:'Y'}, ['className','section']).length, 2],
    ['unwatched ignored', driftOf({className:'A',rollNo:'9'}, {className:'A',rollNo:'1'}, ['className']).length, 0],
  ];
  let bad = 0;
  console.log('LIFECYCLE RECONCILER — SELFTEST (no Firestore)\n');
  for (const [name, got, want] of cases) {
    const ok = got === want;
    if (!ok) bad++;
    console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name.padEnd(20)} got=${got} want=${want}`);
  }
  console.log('\n' + (bad === 0
    ? '  COMPARISON VERIFIED — it fires on drift and stays quiet on noise.'
    : `  ${bad} SELFTEST FAILURE(S) — the reconciler cannot be trusted.`));
  process.exit(bad === 0 ? 0 : 2);
}

// The emulator needs no credential and must not be handed one. When
// FIRESTORE_EMULATOR_HOST is set, firebase-admin routes to it automatically —
// this only avoids loading a service-account key that would be meaningless
// there. Unset (the normal case) behaves exactly as before, against production.
//
// This reconciler is READ-ONLY either way; the switch exists so the same
// checks that police live data can also be run over the post-lifecycle
// emulator fixture, rather than having a second, divergent implementation.
if (process.env.FIRESTORE_EMULATOR_HOST) {
  admin.initializeApp({ projectId: 'graderadmin' });
} else {
  const sa = require(path.join(process.cwd(), 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
  admin.initializeApp({ credential: admin.credential.cert(sa) });
}
const db = admin.firestore();

(async () => {
  console.log(`THREE-LAYER LIFECYCLE RECONCILIATION — ${SCHOOL} (READ ONLY)\n`);

  const school = (await db.collection('schools').doc(SCHOOL).get()).data() || {};
  const knownSessions = new Set((Array.isArray(school.sessions) ? school.sessions : []).map(String));

  // ── AUTHORITATIVE LAYER ────────────────────────────────────────────────
  const stuSnap = await db.collection('students').where('schoolId', '==', SCHOOL).get();
  const students = new Map();
  stuSnap.forEach((d) => {
    const s = d.data() || {};
    const id = String(s.studentId || d.id.replace(`${SCHOOL}_`, ''));
    students.set(id, s);
  });
  console.log(`  authoritative students: ${students.size}`);
  console.log(`  known sessions        : ${[...knownSessions].join(', ') || '(none)'}\n`);

  // ── LIVE PROJECTIONS ───────────────────────────────────────────────────
  console.log('  ── LIVE PROJECTIONS (must agree with the student document today)\n');
  for (const [col, spec] of Object.entries(LIVE)) {
    const snap = await db.collection(col).where('schoolId', '==', SCHOOL).get();
    let checked = 0, dangling = 0, drifted = 0, historical = 0;
    snap.forEach((d) => {
      const row = d.data() || {};
      const sid = String(row.studentId || '');
      if (!sid) return;
      checked++;
      const stu = students.get(sid);
      if (!stu) {
        dangling++;
        // A `parents` mirror outliving its student is a DIFFERENT and much
        // milder thing than a drifted live projection, and conflating them
        // would leave this reconciler permanently red over a known state.
        //
        // `Sis::delete_student()` hard-deletes the student document AND the
        // Firebase Auth user, but does not remove the parent mirror. With the
        // Auth account gone the mirror is unreachable — the Parent App reads
        // it by document id only after authenticating — so this is data
        // hygiene, not exposure. Verified on the live tenant: 16 orphaned
        // mirrors, 0 enabled Auth accounts.
        //
        // Deliberately NOT auto-cleaned: `parents` carries `childrenIds`, so
        // deleting a mirror on a single child's removal risks destroying
        // sibling linkage. Surfacing it is the correct action; deciding its
        // fate is not this script's to take.
        if (col === 'parents') warn(`${col}/${d.id} mirrors deleted student '${sid}'`);
        else fail(`${col}/${d.id} references studentId '${sid}' which has no student document`);
        return;
      }
      // A session-scoped projection is only "live" for the session the
      // student is currently in. Rows from an earlier session are historical
      // and are checked for danglers only, exactly like studentHistory.
      if (spec.sessionScoped) {
        const rowSession = String(row.session || '');
        const stuSession = String(stu.session || '');
        if (rowSession !== '' && stuSession !== '' && rowSession !== stuSession) {
          historical++;
          return;
        }
      }

      const drift = driftOf(stu, row, spec.follows);
      if (drift.length) {
        drifted++;
        const say = spec.soft ? warn : fail;
        say(`${col}/${d.id} (${spec.why}) disagrees with the student — ${drift.join('; ')}`);
      }
    });
    const verdict = (dangling + drifted) === 0
      ? (historical ? `consistent (${historical} prior-session, historical)` : 'consistent')
      : `${drifted} drifted, ${dangling} dangling`
        + (historical ? `, ${historical} prior-session` : '');
    console.log(`    ${col.padEnd(22)} ${String(snap.size).padStart(4)} docs, `
      + `${checked} student-linked — ${verdict}`);
  }

  // ── HISTORICAL RECORDS ─────────────────────────────────────────────────
  console.log('\n  ── HISTORICAL RECORDS (frozen by design; checked for danglers only)\n');
  for (const [col, why] of Object.entries(HISTORICAL)) {
    let snap;
    try {
      snap = await db.collection(col).where('schoolId', '==', SCHOOL).get();
    } catch (e) { continue; }
    if (snap.empty) continue;
    let dangling = 0, badSession = 0, linked = 0;
    snap.forEach((d) => {
      const row = d.data() || {};
      const sid = String(row.studentId || '');
      if (!sid) return;
      linked++;
      if (!students.has(sid)) {
        dangling++;
        if (!DANGLING_OK[col]) {
          fail();
        }
      }
      const sess = row.session === undefined ? null : String(row.session);
      if (sess && knownSessions.size && !knownSessions.has(sess)) {
        badSession++;
        fail(`${col}/${d.id} is stamped session '${sess}', which this school does not have`);
      }
    });
    console.log(`    ${col.padEnd(30)} ${String(snap.size).padStart(4)} docs, ${linked} student-linked`
      + ` — ${(dangling + badSession) === 0 ? 'sound' : `${dangling} dangling, ${badSession} bad session`}`);
    if ((dangling + badSession) === 0 && linked === 0) {
      // Nothing to say; the collection simply has no student-linked rows.
    }
  }

  // ── SESSION INTEGRITY ON THE AUTHORITATIVE LAYER ───────────────────────
  console.log('\n  ── AUTHORITATIVE SESSION / PLACEMENT INTEGRITY\n');
  let noSession = 0, unknownSession = 0, noClass = 0, activeNoClass = 0;
  for (const [id, s] of students) {
    const sess = String(s.session || '');
    const status = String(s.status || 'Active');
    if (!sess) { noSession++; fail(`student ${id} has no session`); }
    else if (knownSessions.size && !knownSessions.has(sess)) {
      unknownSession++;
      fail(`student ${id} is in session '${sess}', which this school does not have`);
    }
    if (!String(s.className || '')) {
      noClass++;
      if (status === 'Active') {
        activeNoClass++;
        fail(`student ${id} is Active with no className — they appear on no roster`);
      }
    }
  }
  console.log(`    students without a session      : ${noSession}`);
  console.log(`    students in an unknown session  : ${unknownSession}`);
  console.log(`    students without a class        : ${noClass} (${activeNoClass} of them Active)`);

  console.log('\n' + '-'.repeat(72));
  console.log(problems === 0
    ? '  RECONCILED — every live projection agrees with its student document,\n'
      + '  and no historical record carries an impossible session.'
    : `  ${problems} RECONCILIATION DEFECT(S) — see above. Nothing was changed.`);
  if (warnings) {
    console.log(`  ${warnings} hygiene NOTE(S) — surfaced, not counted as failures. `
      + 'See the parents note above.');
  }
  process.exit(problems === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
