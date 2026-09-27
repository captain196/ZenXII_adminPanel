/**
 * ══════════════════════════════════════════════════════════════════════════
 *  TIMETABLE PROJECTION — RUNTIME PROOF AT THE PERSISTENCE BOUNDARY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Gates C, D and E of the Master Timetable switchover ask three questions that
 * source inspection and doubles cannot answer:
 *
 *   C  Is the projection cron safe to put on a schedule?
 *   D  Does applying it, then running it again, report NO further writes?
 *   E  Is the published canonical version actually present in `timetables`,
 *      field for field, for the right tenant and nobody else's?
 *
 * Every one of those is a statement about what is PERSISTED after the real
 * code has run. This driver therefore seeds a Firestore emulator with the
 * admin SDK, invokes the REAL CLI controller
 * (`php index.php timetable_projection_cron run`) through the REAL
 * Firestore_rest_client — which routes to the emulator when
 * FIRESTORE_EMULATOR_HOST is set — and then reads the documents back and
 * asserts on them.
 *
 * ── WHY A DOUBLE WAS NOT ENOUGH ─────────────────────────────────────────
 *
 * The unit double for this path returns query rows FLAT. The real REST client
 * returns them as {id, data} envelopes. Every read in Timetable_plan_store
 * unwraps both shapes except one, and against a double that lies about the
 * shape the difference is invisible. This driver exists because that class of
 * defect can only be seen where the real client meets a real database.
 *
 * ── SAFETY ──────────────────────────────────────────────────────────────
 *
 * Refuses to run unless FIRESTORE_EMULATOR_HOST points at loopback. The PHP
 * side refuses independently: Firestore_rest_client only leaves production
 * when that same variable is set and shape-valid. Two gates, because this
 * driver issues real WRITES through production code paths.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/timetable_projection_runtime_proof.js
 *
 * exit 0 = every gate assertion held
 *      2 = an assertion failed (details printed)
 */
const admin = require('firebase-admin');
const { execFileSync } = require('child_process');
const path = require('path');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
const host = EMU.replace(/^https?:\/\//, '').split(':')[0];
if (!EMU || !['127.0.0.1', 'localhost', '::1'].includes(host)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  console.error('This driver issues real timetable WRITES through production code paths.');
  process.exit(1);
}

const REPO    = path.resolve(__dirname, '..');
const SESSION = '2026-27';
const A       = 'SCH_TTPROOFA1';   // the tenant under test
const B       = 'SCH_TTPROOFB2';   // a neighbour that must never be touched
const PLAN    = 'PLAN1';
const VER     = 'V1';
const TODAY   = '2026-09-15';

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

let failures = 0;
const fail = (m) => { failures++; console.log(`   FAIL  ${m}`); };
const ok   = (m) => console.log(`   ok    ${m}`);
const head = (m) => console.log(`\n══ ${m}`);

/** Assert helper that reports the actual value rather than just "false". */
function eq(actual, expected, label) {
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a === e) { ok(`${label} = ${a}`); return true; }
  fail(`${label}: expected ${e}, got ${a}`);
  return false;
}

/** Run the real cron. Returns {out, code}. */
function cron({ apply = false, school = A, forDate = TODAY } = {}) {
  const env = {
    ...process.env,
    FIRESTORE_EMULATOR_HOST: EMU,
    SCHOOL_ID: school,
    SESSION_YEAR: SESSION,
    FOR_DATE: forDate,
  };
  if (apply) env.APPLY = '1'; else delete env.APPLY;
  try {
    return { out: execFileSync('php', ['index.php', 'timetable_projection_cron', 'run'],
      { cwd: REPO, env, encoding: 'utf8', timeout: 180000 }), code: 0 };
  } catch (e) {
    return { out: (e.stdout || '') + (e.stderr || ''), code: e.status === undefined ? -1 : e.status };
  }
}

/** "N document(s) would be written, M already match, K existing document(s)…" */
function parseExplain(out) {
  const m = out.match(/(\d+) document\(s\) would be written, (\d+) already match, (\d+) existing/);
  if (!m) return null;
  return { writes: +m[1], unchanged: +m[2], orphans: +m[3] };
}

const PERIODS_A = [
  { periodNumber: 1, subject: 'Mathematics', teacherId: 'STA0001', teacher: 'A Teacher',
    startTime: '09:00', endTime: '09:40', type: 'class' },
  { periodNumber: 2, subject: 'Science', teacherId: 'STA0002', teacher: 'B Teacher',
    startTime: '09:40', endTime: '10:20', type: 'class' },
];
const PERIODS_B = [
  { periodNumber: 1, subject: 'English', teacherId: 'STA0003', teacher: 'C Teacher',
    startTime: '09:00', endTime: '09:40', type: 'class' },
];

// The version covers exactly these five (section, day) cells.
const CELLS = [
  ['Class 9th/Section A', 'Monday',  PERIODS_A],
  ['Class 9th/Section A', 'Tuesday', PERIODS_B],
  ['Class 9th/Section B', 'Monday',  PERIODS_B],
  ['Class 10th/Section A', 'Monday', PERIODS_A],
  ['Class 11th/Section A', 'Monday', PERIODS_B],
];

const legacyId = (school, sectionKey, day) =>
  `${school}_${SESSION}_${sectionKey.replace(/\//g, '_')}_${day}`;

async function wipe() {
  // The PHP client keeps a cross-request FILE cache for a whitelist of config
  // collections — `timetableSettings` and `schoolControl` among them, 300s.
  // A run that left entries behind poisons the next one: the rollover read a
  // five-minute-old settings document and skipped a carry it should have made.
  // That was a real defect (TT-D5, now fixed by reading fail-loud), and it is
  // also a reason this driver must start from a clean cache, or it is testing
  // the previous run.
  try {
    const dir = path.join(REPO, 'application', 'cache', 'fsdoc');
    for (const f of require('fs').readdirSync(dir)) {
      require('fs').unlinkSync(path.join(dir, f));
    }
  } catch (e) { /* absent is fine */ }

  for (const col of ['timetables', 'timetableVersions', 'timetableVersionDays',
                     'timetableSettings', 'subjectAssignments', 'curriculum',
                     'schoolControl', 'schools', 'academicAuditLog']) {
    const snap = await db.collection(col).get();
    const batch = db.batch();
    snap.docs.forEach((d) => batch.delete(d.ref));
    if (snap.size) await batch.commit();
  }
}

async function seed() {
  for (const s of [A, B]) {
    await db.doc(`schoolControl/${s}`).set({ schoolId: s, lifecycle: { state: 'active' } });
    await db.doc(`schools/${s}`).set({ schoolId: s, name: s, institution: { state: 'active' } });
  }

  // ── canonical: one published, complete version for tenant A ──────────
  await db.doc(`timetableVersions/${A}_${SESSION}_${PLAN}_${VER}`).set({
    schoolId: A, session: SESSION, planId: PLAN, versionId: VER,
    planName: 'Main', status: 'published',
    effectiveFrom: '2026-09-01', effectiveTo: '',
    generation: { state: 'complete' },
  });
  for (const [sectionKey, day, periods] of CELLS) {
    const safe = sectionKey.replace(/\//g, '_');
    await db.doc(`timetableVersionDays/${A}_${SESSION}_${PLAN}_${VER}_${safe}_${day}`).set({
      schoolId: A, session: SESSION, planId: PLAN, versionId: VER,
      classSection: sectionKey, day, periods, manuallyEdited: false,
    });
  }

  // ── legacy state the projection must reason about ────────────────────
  // 1. ALREADY PROJECTED: owned fields match AND it already declares itself
  //    derived from this very version → genuinely unchanged, must not be
  //    rewritten and must keep its `version`.
  await db.doc(`timetables/${legacyId(A, 'Class 9th/Section A', 'Monday')}`).set({
    schoolId: A, session: SESSION,
    className: 'Class 9th', section: 'Section A', classOrder: 9,
    sectionCode: 'A', sectionKey: 'Class 9th/Section A', day: 'Monday',
    periods: PERIODS_A, manuallyEdited: false,
    version: 7,
    projectedFromPlan: PLAN, projectedFromVersion: VER,
    projectedAt: '2026-09-10T00:00:00+00:00', projectedByUid: 'someone_else',
    // legacy metadata the projection must NOT erase
    generationId: 'GEN_LEGACY_1', generatedByUid: 'STA0009',
  });

  // 1b. CONTENT MATCHES BUT UNSTAMPED — the pre-switchover case, and the one
  //     TT-C4 is about. Its periods already equal the published version's, so
  //     content equality alone would call it "unchanged" and never write it,
  //     leaving it without `projectedFromVersion`. Every downstream guard
  //     (the rollover, the reconciler) keys off that field, so an unstamped
  //     canonical-derived document is the LEAST protected document in the
  //     collection. It must be written, purely to be marked.
  await db.doc(`timetables/${legacyId(A, 'Class 11th/Section A', 'Monday')}`).set({
    schoolId: A, session: SESSION,
    className: 'Class 11th', section: 'Section A', classOrder: 11,
    sectionCode: 'A', sectionKey: 'Class 11th/Section A', day: 'Monday',
    periods: PERIODS_B, manuallyEdited: false, version: 2,
  });

  // 2. DRIFTED: same cell, wrong periods → must be written.
  await db.doc(`timetables/${legacyId(A, 'Class 9th/Section B', 'Monday')}`).set({
    schoolId: A, session: SESSION,
    className: 'Class 9th', section: 'Section B', classOrder: 9,
    sectionCode: 'B', sectionKey: 'Class 9th/Section B', day: 'Monday',
    periods: [{ periodNumber: 1, subject: 'STALE', type: 'class' }],
    manuallyEdited: false, version: 3,
  });

  // 3. ORPHAN: a section the published version does not account for.
  await db.doc(`timetables/${legacyId(A, 'Class 12th/Section Z', 'Friday')}`).set({
    schoolId: A, session: SESSION,
    className: 'Class 12th', section: 'Section Z', classOrder: 12,
    sectionCode: 'Z', sectionKey: 'Class 12th/Section Z', day: 'Friday',
    periods: PERIODS_B, manuallyEdited: false, version: 1,
  });

  // 4. ANOTHER SESSION, same tenant: out of scope, must be neither written
  //    nor reported as an orphan.
  await db.doc(`timetables/${A}_2025-26_Class 9th_Section A_Monday`).set({
    schoolId: A, session: '2025-26',
    className: 'Class 9th', section: 'Section A', classOrder: 9,
    sectionCode: 'A', sectionKey: 'Class 9th/Section A', day: 'Monday',
    periods: PERIODS_B, manuallyEdited: false, version: 2,
  });

  // 5. ANOTHER TENANT: must be untouched and invisible.
  await db.doc(`timetables/${legacyId(B, 'Class 9th/Section A', 'Monday')}`).set({
    schoolId: B, session: SESSION,
    className: 'Class 9th', section: 'Section A', classOrder: 9,
    sectionCode: 'A', sectionKey: 'Class 9th/Section A', day: 'Monday',
    periods: PERIODS_B, manuallyEdited: false, version: 5,
  });
}

const snapOf = async (school, session) => {
  const s = await db.collection('timetables').where('schoolId', '==', school).get();
  const out = {};
  s.docs.forEach((d) => {
    const v = d.data() || {};
    if (session && String(v.session || '') !== session) return;
    out[d.id] = v;
  });
  return out;
};

(async () => {
  head('SETUP — wipe and seed the emulator');
  await wipe();
  await seed();
  ok(`seeded: canonical version ${PLAN}/${VER} (published, complete) with ${CELLS.length} day docs`);
  ok('seeded legacy: 1 already-correct, 1 drifted, 1 orphan, 1 other-session, 1 other-tenant');

  // ══ GATE C — is this safe to schedule? ═════════════════════════════════
  // The question a scheduler asks is "what will you do every night?". A dry
  // run against a collection that is already correct must answer "almost
  // nothing", and it must be able to SEE what is already there to say so.
  head('GATE C — dry run against seeded legacy state (nothing may be written)');
  const dry1 = cron({ apply: false });
  console.log(dry1.out.split('\n').map((l) => '   | ' + l).join('\n'));
  const p1 = parseExplain(dry1.out);
  if (!p1) { fail('could not parse the projection report'); }
  else {
    eq(p1.writes, 4, 'dry-run writes (5 cells minus the 1 already projected)');
    eq(p1.unchanged, 1, 'dry-run unchanged (only the already-PROJECTED document)');
    eq(p1.orphans, 1, 'dry-run orphans (Class 12th/Section Z Friday only)');
  }
  const afterDry = await snapOf(A, SESSION);
  eq(Object.keys(afterDry).length, 4, 'documents in tenant A after a DRY run (unchanged count)');
  eq((afterDry[legacyId(A, 'Class 9th/Section B', 'Monday')] || {}).periods[0].subject,
     'STALE', 'a dry run really did not write');

  // ══ GATE D — apply, then prove idempotency at the persistence boundary ══
  head('GATE D — APPLY=1, then re-run and demand no further writes');
  const app1 = cron({ apply: true });
  console.log(app1.out.split('\n').map((l) => '   | ' + l).join('\n'));
  eq(app1.code, 0, 'APPLY exit code');

  const applied = await snapOf(A, SESSION);
  eq(Object.keys(applied).length, 6,
     'documents in tenant A after APPLY (5 canonical cells + 1 orphan left alone)');

  // The legacy `version` counter is monotonic by convention. A document the
  // projection recognises as already correct must not be rewritten at all,
  // so its counter must survive untouched — a reset from 7 to 1 is how the
  // "existing docs are invisible" defect showed up in the stored data.
  eq((applied[legacyId(A, 'Class 9th/Section A', 'Monday')] || {}).version, 7,
     'the already-correct document kept its `version` (it was never rewritten)');
  eq((applied[legacyId(A, 'Class 9th/Section B', 'Monday')] || {}).version, 4,
     'the drifted document advanced its `version` by exactly one (3 → 4)');

  // TT-C4, at the persistence boundary: a document whose content already
  // matched but which carried no provenance was WRITTEN, and now carries the
  // stamp that the rollover guard and the reconciler both refuse to overwrite.
  const stamped = applied[legacyId(A, 'Class 11th/Section A', 'Monday')] || {};
  eq(stamped.projectedFromVersion, VER,
     'TT-C4 — the content-identical but unstamped document is now stamped');
  eq(stamped.version, 3,
     'TT-C4 — it was genuinely rewritten to acquire the stamp (2 → 3)');

  const dry2 = cron({ apply: false });
  const p2 = parseExplain(dry2.out);
  if (!p2) { fail('could not parse the second dry-run report'); }
  else {
    eq(p2.writes, 0, 'GATE D ACCEPTANCE — re-run writes');
    eq(p2.unchanged, 5, 're-run unchanged (every canonical cell)');
    eq(p2.orphans, 1, 're-run orphans (still exactly the one, still not deleted)');
  }

  // A second APPLY must also write nothing, and must not bump `version`.
  const versionsBefore = Object.fromEntries(
    Object.entries(applied).map(([k, v]) => [k, v.version]));
  const app2 = cron({ apply: true });
  const applied2 = await snapOf(A, SESSION);
  let drifted = [];
  for (const [k, v] of Object.entries(applied2)) {
    if (versionsBefore[k] !== undefined && versionsBefore[k] !== v.version) {
      drifted.push(`${k}: ${versionsBefore[k]} → ${v.version}`);
    }
  }
  eq(drifted, [], 'a second APPLY leaves every `version` counter alone');
  if (!/Projected 0 document/.test(app2.out)) {
    fail(`second APPLY should have projected 0 documents; got: ${(app2.out.match(/Projected \d+ document/) || ['?'])[0]}`);
  } else { ok('second APPLY projected 0 documents'); }

  // ══ GATE E — is the published version really what is persisted? ════════
  head('GATE E — canonical version present in `timetables`, field for field');
  for (const [sectionKey, day, periods] of CELLS) {
    const id = legacyId(A, sectionKey, day);
    const d  = applied[id];
    if (!d) { fail(`missing projected document ${id}`); continue; }
    const problems = [];
    if (d.schoolId !== A) problems.push(`schoolId=${d.schoolId}`);
    if (d.session !== SESSION) problems.push(`session=${d.session}`);
    if (d.sectionKey !== sectionKey) problems.push(`sectionKey=${d.sectionKey}`);
    if (d.day !== day) problems.push(`day=${d.day}`);
    if (JSON.stringify(d.periods) !== JSON.stringify(periods)) problems.push('periods differ');
    if (d.projectedFromVersion !== VER) problems.push(`projectedFromVersion=${d.projectedFromVersion}`);
    if (d.projectedFromPlan !== PLAN) problems.push(`projectedFromPlan=${d.projectedFromPlan}`);
    if (!d.projectedAt) problems.push('projectedAt missing');
    if (problems.length) fail(`${id}: ${problems.join(', ')}`);
    else ok(`${id} matches the published version and carries its provenance`);
  }

  // Legacy metadata the projection does not author must survive.
  const kept = applied[legacyId(A, 'Class 9th/Section A', 'Monday')] || {};
  eq(kept.generationId, 'GEN_LEGACY_1',
     'legacy generation metadata survives the projection (migration stays reversible)');

  // The orphan is still there, untouched, and still not carrying provenance.
  const orphan = applied[legacyId(A, 'Class 12th/Section Z', 'Friday')] || {};
  eq(orphan.version, 1, 'the orphan was left exactly as it was');
  eq(orphan.projectedFromVersion, undefined, 'the orphan was not adopted by the projection');

  // ══ TENANT ISOLATION ═══════════════════════════════════════════════════
  head('TENANT + SESSION ISOLATION');
  const bDocs = await snapOf(B, null);
  eq(Object.keys(bDocs).length, 1, "the neighbouring tenant still has exactly its own document");
  eq(bDocs[legacyId(B, 'Class 9th/Section A', 'Monday')].version, 5,
     "the neighbouring tenant's document was not written");
  eq(bDocs[legacyId(B, 'Class 9th/Section A', 'Monday')].projectedFromVersion, undefined,
     "the neighbouring tenant's document was not projected into");

  const otherSession = (await db.doc(`timetables/${A}_2025-26_Class 9th_Section A_Monday`).get()).data();
  eq(otherSession.version, 2, "last session's document was not written");
  eq(otherSession.projectedFromVersion, undefined, "last session's document was not projected into");

  // ══ FAIL-CLOSED ════════════════════════════════════════════════════════
  head('FAIL CLOSED — a date no published version covers');
  const none = cron({ apply: true, forDate: '2026-01-05' });   // before effectiveFrom
  if (!/Nothing published covers this date/.test(none.out)) {
    fail(`expected a "nothing published" skip; got:\n${none.out}`);
  } else { ok('a date outside the published window writes nothing and says so'); }
  const stillFive = Object.keys(await snapOf(A, SESSION)).length;
  eq(stillFive, 6, 'no documents were added or removed by the uncovered-date run');

  // ══ THE OTHER REMAINING WRITER ═════════════════════════════════════════
  // Gate B denied every CLIENT write. Two SERVER-side tools can still address
  // these documents, and both are supposed to decline on anything the
  // projection authored. This drives the repair tool for real — with subject
  // assignments that genuinely disagree with what is projected, so it has
  // every reason to write — and then checks that it did not.
  head('SECOND AUTHORITY — the repair tool must decline projected documents');

  // Authority that contradicts every projected period: Mathematics and
  // English are both taught by somebody else now.
  for (const [cls, sec, subject, tid, tname] of [
    ['Class 9th', 'Section A', 'Mathematics', 'STA_NEW1', 'New Maths Teacher'],
    ['Class 9th', 'Section A', 'Science',     'STA_NEW2', 'New Science Teacher'],
    ['Class 9th', 'Section B', 'English',     'STA_NEW3', 'New English Teacher'],
    ['Class 10th', 'Section A', 'Mathematics','STA_NEW1', 'New Maths Teacher'],
    ['Class 12th', 'Section Z', 'English',    'STA_NEW3', 'New English Teacher'],
  ]) {
    await db.doc(`subjectAssignments/${A}_${cls}_${sec}_${subject}`.replace(/\s/g, '_')).set({
      schoolId: A, session: SESSION, className: cls, section: sec,
      subjectName: subject, teacherId: tid, teacherName: tname,
    });
  }

  const before = await snapOf(A, SESSION);
  let rec;
  try {
    rec = execFileSync('php', ['index.php', 'timetables_reconcile', 'run'], {
      cwd: REPO, encoding: 'utf8', timeout: 180000,
      env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU, SCHOOL_ID: A,
             SESSION_YEAR: SESSION, APPLY: '1' },
    });
  } catch (e) { rec = (e.stdout || '') + (e.stderr || ''); }
  console.log(rec.split('\n').map((l) => '   | ' + l).join('\n'));

  const after = await snapOf(A, SESSION);
  const projectedIds = CELLS.map(([sk, day]) => legacyId(A, sk, day));
  const touched = projectedIds.filter((id) =>
    JSON.stringify(before[id]) !== JSON.stringify(after[id]));
  eq(touched, [],
     'not one projected document was altered by the repair tool, even though '
     + 'the subject assignments contradict every one of them');

  if (!/document\(s\) are projections of a published timetable and were NOT touched/.test(rec)) {
    fail('the operator was not told that documents were skipped — TT-C7');
  } else { ok('the run says out loud which documents it declined and why'); }

  // The unprojected document is in scope, so the tool is genuinely working
  // rather than declining everything.
  const orphanId = legacyId(A, 'Class 12th/Section Z', 'Friday');
  eq(after[orphanId].periods[0].teacherId, 'STA_NEW3',
     'a document no version authored IS repaired — the refusal above is about '
     + 'provenance, not about the tool being inert');

  // ══ GATES F/G — THE CONSUMERS, AS THEY ACTUALLY READ ═══════════════════
  //
  // What a device confirms is that a screen renders. What can be confirmed
  // HERE is the thing underneath it: that the documents the projection
  // persisted satisfy the exact queries the two apps issue. These are not
  // approximations of those queries — they are the shapes read out of the
  // apps' own repositories:
  //
  //   Teacher  TimetableFirestoreRepository.getTimetable
  //            whereEqualTo("schoolId", …).whereEqualTo("sectionKey", …)
  //            then .filter { it.session == session }   (client-side, on purpose:
  //            a third equality would need a composite index)
  //   Parent   TimetableFirestoreRepository.getTimetable — the same shape.
  //   Teacher  LessonPlanFirestoreRepository.resolveTimetableCell
  //            collection("timetables").document(id) — a DIRECT id read.
  //
  // A device check is still required and is not claimed here; this proves the
  // data those checks will be looking at.
  head('GATES F/G — the Teacher and Parent read paths against persisted state');

  async function appQuery(schoolCode, sectionKey, session) {
    const s = await db.collection('timetables')
      .where('schoolId', '==', schoolCode)
      .where('sectionKey', '==', sectionKey)
      .get();
    return s.docs.map((d) => d.data()).filter((v) => String(v.session || '') === session);
  }

  const seen = await appQuery(A, 'Class 9th/Section A', SESSION);
  eq(seen.map((d) => d.day).sort(), ['Monday', 'Tuesday'],
     'both apps see exactly the days the published version defines for 9-A');
  eq(seen.every((d) => Array.isArray(d.periods) && d.periods.length > 0), true,
     'every day the apps receive carries its periods');

  // The session filter is client-side, so last year's document IS returned by
  // the query and must be excluded by the filter — not by luck.
  const unfiltered = await db.collection('timetables')
    .where('schoolId', '==', A).where('sectionKey', '==', 'Class 9th/Section A').get();
  eq(unfiltered.size, 3, "the query itself returns last year's document too");
  eq(seen.length, 2, 'and the client-side session filter is what removes it');

  // Cross-tenant, through the same query the apps use.
  const neighbour = await appQuery(B, 'Class 9th/Section A', SESSION);
  eq(neighbour.length, 1, 'the neighbouring tenant sees exactly its own document');
  eq(neighbour[0].projectedFromVersion, undefined,
     "and not this tenant's projected content");

  // The Teacher app's DIRECT id read — the one TT-F1 broke. The id is built
  // the way Constants.timetableDocId builds it: canonical class/section,
  // underscores, no path separator.
  const teacherCellId = `${A}_${SESSION}_Class 9th_Section A_Monday`;
  if (teacherCellId.includes('/')) {
    fail('the id the Teacher app builds contains a path separator — it would '
       + 'be an invalid document reference, not a missing document');
  }
  const cell = await db.doc(`timetables/${teacherCellId}`).get();
  eq(cell.exists, true, 'the period-match guard can address the projected document');
  eq((cell.data().periods || [])[0].teacherId, 'STA0001',
     'and reads the teacher the published version put in that slot');

  // ══ THE ROLLOVER — GATE A, AT THE PERSISTENCE BOUNDARY ═════════════════
  //
  // Gate A's guard was proven as a pure function and by mutation. What had
  // never been executed is the tool itself against a database that actually
  // holds a projected timetable. This is the single most dangerous sequence
  // in the whole architecture — publish, project, then roll over — because
  // the rollover writes whole documents into the collection both apps read
  // and knows nothing about canonical provenance on its own.
  head('THE DANGEROUS SEQUENCE — a session rollover run AFTER a projection');

  await db.doc(`timetableSettings/${A}_${SESSION}`).set({
    schoolId: A, session: SESSION, periodsPerDay: 8,
    periodTimes: [{ periodNumber: 1, startTime: '09:00', endTime: '09:40' }],
  });
  // The destination session already has settings that are NOT this rollover's
  // — carrying over them would replace a configured year with last year's.
  await db.doc(`timetableSettings/${A}_2027-28`).set({
    schoolId: A, session: '2027-28', periodsPerDay: 6, configuredByHand: true,
  });

  const projectedBefore = await snapOf(A, SESSION);
  let roll;
  try {
    roll = execFileSync('php', ['scripts/rollover_academic_session.php',
      `--school=${A}`, `--from=${SESSION}`, '--to=2027-28', '--apply', '--verbose'], {
      cwd: REPO, encoding: 'utf8', timeout: 180000,
      env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU },
    });
  } catch (e) { roll = (e.stdout || '') + (e.stderr || ''); }
  console.log(roll.split('\n').slice(0, 40).map((l) => '   | ' + l).join('\n'));

  // 1. Not one projected document may be altered, in EITHER session.
  const projectedAfter = await snapOf(A, SESSION);
  const rollTouched = Object.keys(projectedBefore).filter((id) =>
    JSON.stringify(projectedBefore[id]) !== JSON.stringify(projectedAfter[id]));
  eq(rollTouched, [], 'the rollover changed nothing in the session it read from');

  // 2. It must not have manufactured teacher-visible documents in the new
  //    session FROM projected ones — that is the carry, and it is allowed;
  //    what matters is that the carried copies are marked as carried and
  //    never inherit the projection's provenance, or the next projection
  //    would treat them as its own.
  const next = await snapOf(A, '2027-28');
  const carried = Object.values(next);
  if (carried.length === 0) {
    fail('premise: the rollover carried nothing, so this proves nothing');
  } else {
    ok(`the rollover carried ${carried.length} document(s) into 2027-28`);
    eq(carried.every((d) => d.carriedFromSession === SESSION), true,
       'every carried document says which session it came from');
    eq(carried.some((d) => d.projectedFromVersion), false,
       'and NONE of them claims to be derived from a published version — a '
       + 'carried copy that inherited provenance would be mistaken for '
       + 'canonical state by every guard in the system');
  }

  // 3. TT-D3: the destination settings document was configured by hand and
  //    carries no rollover marker. It is a legitimate write target, so the
  //    interesting assertion is the one about a SECOND run.
  const settingsAfterFirst = (await db.doc(`timetableSettings/${A}_2027-28`).get()).data();
  eq(settingsAfterFirst.phase3RolloverRev, 1, 'the settings carry stamped its revision');

  // 4. Run it AGAIN. Everything must now be refused, in both collections.
  let roll2;
  try {
    roll2 = execFileSync('php', ['scripts/rollover_academic_session.php',
      `--school=${A}`, `--from=${SESSION}`, '--to=2027-28', '--apply', '--verbose'], {
      cwd: REPO, encoding: 'utf8', timeout: 180000,
      env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU },
    });
  } catch (e) { roll2 = (e.stdout || '') + (e.stderr || ''); }

  const nextAgain = await snapOf(A, '2027-28');
  const secondRunTouched = Object.keys(next).filter((id) =>
    JSON.stringify(next[id]) !== JSON.stringify(nextAgain[id]));
  eq(secondRunTouched, [], 'a second rollover run rewrote nothing it had already carried');

  const settingsAfterSecond = (await db.doc(`timetableSettings/${A}_2027-28`).get()).data();
  eq(JSON.stringify(settingsAfterSecond), JSON.stringify(settingsAfterFirst),
     'TT-D3 — and left the settings document exactly as it was');
  if (!/already rolled/.test(roll2)) {
    fail('the second run did not say why it declined');
  } else { ok('the second run says "already rolled" rather than going quiet'); }

  // 5. Now project into the NEW session and roll over once more with a bumped
  //    intent — the actual dangerous sequence. The projected documents must
  //    survive.
  await db.doc(`timetableVersions/${A}_2027-28_${PLAN}_${VER}`).set({
    schoolId: A, session: '2027-28', planId: PLAN, versionId: VER,
    planName: 'Next Year', status: 'published',
    effectiveFrom: '2027-04-01', effectiveTo: '', generation: { state: 'complete' },
  });
  for (const [sectionKey, day, periods] of CELLS.slice(0, 2)) {
    const safe = sectionKey.replace(/\//g, '_');
    await db.doc(`timetableVersionDays/${A}_2027-28_${PLAN}_${VER}_${safe}_${day}`).set({
      schoolId: A, session: '2027-28', planId: PLAN, versionId: VER,
      classSection: sectionKey, day, periods, manuallyEdited: false,
    });
  }
  const projNext = cron({ apply: true, forDate: '2027-05-01' });
  const envNext = { ...process.env, FIRESTORE_EMULATOR_HOST: EMU,
                    SCHOOL_ID: A, SESSION_YEAR: '2027-28', FOR_DATE: '2027-05-01', APPLY: '1' };
  try {
    execFileSync('php', ['index.php', 'timetable_projection_cron', 'run'],
      { cwd: REPO, env: envNext, encoding: 'utf8', timeout: 180000 });
  } catch (e) { /* reported below by the read-back */ }

  const publishedNext = await snapOf(A, '2027-28');
  const nowProjected = Object.entries(publishedNext)
    .filter(([, v]) => v.projectedFromVersion === VER).map(([k]) => k);
  if (nowProjected.length === 0) {
    fail('premise: nothing was projected into the new session');
  } else {
    ok(`${nowProjected.length} document(s) in 2027-28 are now published canonical state`);

    const beforeDanger = await snapOf(A, '2027-28');
    let roll3;
    try {
      roll3 = execFileSync('php', ['scripts/rollover_academic_session.php',
        `--school=${A}`, `--from=${SESSION}`, '--to=2027-28', '--apply', '--verbose'], {
        cwd: REPO, encoding: 'utf8', timeout: 180000,
        env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU },
      });
    } catch (e) { roll3 = (e.stdout || '') + (e.stderr || ''); }

    const afterDanger = await snapOf(A, '2027-28');
    const clobbered = nowProjected.filter((id) =>
      JSON.stringify(beforeDanger[id]) !== JSON.stringify(afterDanger[id]));
    eq(clobbered, [],
       'GATE A, EXECUTED — a rollover run after a projection did not replace '
       + "one published document with last year's copy");
    if (!/canonical authority|published version/.test(roll3)) {
      fail('the rollover did not explain that it was protecting canonical state');
    } else { ok('and it named the reason: canonical authority, not its to overwrite'); }
  }

  head(failures === 0 ? 'ALL GATE ASSERTIONS HELD' : `${failures} ASSERTION(S) FAILED`);
  process.exit(failures === 0 ? 0 : 2);
})().catch((e) => { console.error(e); process.exit(2); });
