/**
 * ══════════════════════════════════════════════════════════════════════════
 *  TT-C8 OPTION 6 — SUPERSEDE, AT THE PERSISTENCE BOUNDARY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The unit suite proves the invariants deterministically. This proves the same
 * things where the real Firestore client meets a real database, through the
 * real production method, with real OS-process concurrency — because that is
 * where every defect in this programme that mattered was actually found: the
 * envelope row shape, the 'OK' vs 'SUCCESS' literal, the cached rollover read.
 *
 * Proven here:
 *   1. the recovery path — an ambiguous open-ended pair is repaired, and a
 *      successor becomes publishable;
 *   2. published CONTENT and the compatibility projection are untouched;
 *   3. coverage only shrinks, and no past date's resolution moves;
 *   4. supersede ∥ supersede, and supersede ∥ publish, on one starting gun;
 *   5. the audit entry is actually PERSISTED — read back from the collection,
 *      not inferred from a return value.
 *
 * Refuses to run against anything but a loopback emulator.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8080 node scripts/timetable_supersede_proof.js
 *
 * exit 0 = every assertion held; 2 = one did not.
 */
const admin = require('firebase-admin');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
const host = EMU.replace(/^https?:\/\//, '').split(':')[0];
if (!EMU || !['127.0.0.1', 'localhost'].includes(host)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  process.exit(1);
}

const REPO    = path.resolve(__dirname, '..');
const SCHOOL  = 'SCH_SUPERSEDE1';
const SESSION = '2026-27';

const iso = (d) => d.toISOString().slice(0, 10);
const shift = (days) => { const d = new Date(); d.setDate(d.getDate() + days); return iso(d); };
const TODAY = iso(new Date());

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

let failures = 0;
const fail = (m) => { failures++; console.log(`   FAIL  ${m}`); };
const ok   = (m) => console.log(`   ok    ${m}`);
const note = (m) => console.log(`   ·     ${m}`);
const head = (m) => console.log(`\n══ ${m}`);
function eq(actual, expected, label) {
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a === e) { ok(`${label} = ${a}`); return true; }
  fail(`${label}: expected ${e}, got ${a}`); return false;
}

const CELLS = ['Class 9th/Section A', 'Class 9th/Section B'];
const DAYS  = ['Monday', 'Tuesday'];
const P = (subject) => ([{ periodNumber: 1, subject, teacherId: 'STA0001',
  teacher: 'A Teacher', startTime: '09:00', endTime: '09:40', type: 'class' }]);

const vDoc = (plan, vid) => `${SCHOOL}_${SESSION}_${plan}_${vid}`;

async function wipe() {
  try {
    const dir = path.join(REPO, 'application', 'cache', 'fsdoc');
    for (const f of fs.readdirSync(dir)) fs.unlinkSync(path.join(dir, f));
  } catch (e) { /* absent is fine */ }
  for (const col of ['timetables', 'timetableVersions', 'timetableVersionDays',
                     'timetablePublishGuards', 'academicAuditLog',
                     'schoolControl', 'schools']) {
    const snap = await db.collection(col).get();
    const batch = db.batch();
    snap.docs.forEach((d) => batch.delete(d.ref));
    if (snap.size) await batch.commit();
  }
  await db.doc(`schoolControl/${SCHOOL}`).set({ schoolId: SCHOOL, lifecycle: { state: 'active' } });
  await db.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL, institution: { state: 'active' } });
}

/** A candidate, complete, with a real verdict stamped by the production code. */
async function seedCandidate(plan, vid, subject) {
  await db.doc(`timetableVersions/${vDoc(plan, vid)}`).set({
    schoolId: SCHOOL, session: SESSION, planId: plan, versionId: vid,
    planName: `Plan ${plan}`, status: 'candidate',
    effectiveFrom: '', effectiveTo: '', generation: { state: 'complete' },
    facts: { metrics: { shortfall: { known: true, total: 0 } } },
  });
  for (const sk of CELLS) for (const day of DAYS) {
    await db.doc(`timetableVersionDays/${vDoc(plan, vid)}_${sk.replace(/\//g, '_')}_${day}`).set({
      schoolId: SCHOOL, session: SESSION, planId: plan, versionId: vid,
      classSection: sk, day, periods: P(subject), manuallyEdited: false,
    });
  }
  probe(plan, vid, ['--mode=stamp-verdict', `--from=${TODAY}`]);
}

function probe(plan, vid, extra = []) {
  try {
    return execFileSync('php', ['scripts/timetable_publish_race_probe.php',
      `--school=${SCHOOL}`, `--session=${SESSION}`, `--plan=${plan}`,
      `--version=${vid}`, ...extra], {
      cwd: REPO, encoding: 'utf8', timeout: 180000,
      env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU },
    });
  } catch (e) { return (e.stdout || '') + (e.stderr || ''); }
}

const parse = (out) => { const m = String(out).match(/\{[\s\S]*\}/); return m ? JSON.parse(m[0]) : { outcome: 'no-json', raw: String(out).slice(0, 300) }; };

const publish  = (plan, vid, from, to = '') => parse(probe(plan, vid, [`--from=${from}`, `--to=${to}`]));
const supersede = (plan, vid, last, reason = 'closing the term', admin_ = 'STA_SUP') =>
  parse(probe(plan, vid, ['--mode=supersede', `--from=${TODAY}`, `--last=${last}`,
                          `--reason=${reason}`, `--admin=${admin_}`]));

/** Launch one operation held at a gate, for the concurrency section. */
function held(mode, plan, vid, gate, extra) {
  return new Promise((resolve) => {
    const args = ['scripts/timetable_publish_race_probe.php',
      `--school=${SCHOOL}`, `--session=${SESSION}`, `--plan=${plan}`,
      `--version=${vid}`, `--gate=${gate}`, ...extra];
    if (mode === 'supersede') args.push('--mode=supersede');
    const p = spawn('php', args, { cwd: REPO,
      env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU } });
    let out = '';
    p.stdout.on('data', (d) => { out += d; });
    p.stderr.on('data', (d) => { out += d; });
    p.on('close', () => resolve(parse(out)));
  });
}

const versions = async () => {
  const s = await db.collection('timetableVersions').where('schoolId', '==', SCHOOL).get();
  const out = {}; s.docs.forEach((d) => { out[d.id] = d.data(); }); return out;
};

/** Resolution, computed the way the production predicate computes it. */
function coversOn(vs, date) {
  return Object.values(vs)
    .filter((v) => v.status === 'published'
      && v.effectiveFrom && v.effectiveFrom <= date
      && (!v.effectiveTo || v.effectiveTo >= date))
    .map((v) => `${v.planId}/${v.versionId}`).sort();
}

(async () => {
  // ══ RECOVERY — the reason the transition exists ═══════════════════════
  head('RECOVERY — an ambiguous open-ended pair, repaired');
  await wipe();
  await seedCandidate('PLAN_REGULAR', 'V1', 'Mathematics');
  await seedCandidate('PLAN_EXAM',    'V1', 'Chemistry');

  // Publishing the second is now refused (option 4), so the ambiguous state is
  // seeded directly — it is what a UAT tenant from before the rule looks like.
  console.log('   | publish V1 : ' + JSON.stringify(publish('PLAN_REGULAR', 'V1', shift(-60))));
  await db.doc(`timetableVersions/${vDoc('PLAN_EXAM', 'V1')}`).set(
    { status: 'published', effectiveFrom: shift(-10), effectiveTo: '' }, { merge: true });

  const before = await versions();
  const dates = []; for (let i = -90; i <= 180; i++) dates.push(shift(i));
  const coversBefore = {}; dates.forEach((d) => { coversBefore[d] = coversOn(before, d); });

  eq(coversOn(before, TODAY).length, 2, 'premise: today is genuinely AMBIGUOUS');

  const sup = supersede('PLAN_REGULAR', 'V1', shift(5), 'ended by the exam timetable');
  console.log('   | supersede  : ' + JSON.stringify(sup).slice(0, 220));
  eq(sup.outcome, 'superseded', 'the open-ended predecessor was closed');

  const after = await versions();
  eq(coversOn(after, shift(6)).length, 1, 'the day after the end date resolves to ONE version');

  // ── invariants, over the whole range, against persisted state ─────────
  const pastMoved = dates.filter((d) => d < TODAY &&
    JSON.stringify(coversOn(after, d)) !== JSON.stringify(coversBefore[d]));
  eq(pastMoved, [], 'HISTORICAL SAFETY — no date before today resolves differently');

  const added = dates.filter((d) =>
    coversOn(after, d).some((v) => !coversBefore[d].includes(v)));
  eq(added, [], 'COVERAGE MONOTONICITY — coverage was never added on any date');

  const grew = dates.filter((d) => coversOn(after, d).length > coversBefore[d].length);
  eq(grew, [], 'and no date is covered by MORE versions than before');

  // ── published content is untouched ───────────────────────────────────
  const v = after[vDoc('PLAN_REGULAR', 'V1')];
  const b = before[vDoc('PLAN_REGULAR', 'V1')];
  const allowed = ['effectiveTo', 'supersededAt', 'supersededBy', 'supersededReason'];
  const changed = Object.keys(b).filter((k) =>
    !allowed.includes(k) && JSON.stringify(b[k]) !== JSON.stringify(v[k]));
  eq(changed, [], 'only the authorised fields changed on the published version');
  eq(v.status, 'published',
     'status is NOT flipped — coversDate() gates on it, so flipping would drop '
     + 'the past dates the timetable genuinely applied to');

  const daySnap = await db.collection('timetableVersionDays')
    .where('schoolId', '==', SCHOOL).where('versionId', '==', 'V1').get();
  eq(daySnap.size > 0, true, 'premise: the version has day documents');
  eq(daySnap.docs.every((d) => !d.data().supersededAt), true,
     'timetable CONTENT was not touched');

  const ttSnap = await db.collection('timetables').where('schoolId', '==', SCHOOL).get();
  const projectedBefore = ttSnap.size;
  note(`compatibility projection holds ${projectedBefore} document(s) — supersede must not change it`);

  // ── a successor becomes publishable, which is the whole point ─────────
  //
  // BOTH open-ended versions have to be closed first. The exam one still
  // claims every future date, and the successor publish was correctly refused
  // while it did — which is the invariant doing its job, not a defect.
  const sup2 = supersede('PLAN_EXAM', 'V1', shift(5), 'exam window ends too');
  eq(sup2.outcome, 'superseded', 'the second open-ended version is closed as well');

  await seedCandidate('PLAN_REGULAR', 'V2', 'Biology');
  const succ = publish('PLAN_REGULAR', 'V2', shift(200), shift(260));
  eq(succ.outcome, 'published',
     'a successor over dates the closed version no longer claims is admitted');

  const ttAfter = await db.collection('timetables').where('schoolId', '==', SCHOOL).get();
  eq(ttAfter.size >= projectedBefore, true,
     'the projection was never REDUCED by the supersede itself');

  // ══ AUDIT — read back from the collection, not inferred ═══════════════
  head('AUDIT — the entry is persisted, not merely attempted');
  const auditSnap = await db.collection('academicAuditLog')
    .where('schoolId', '==', SCHOOL).get();
  const rows = auditSnap.docs.map((d) => d.data())
    .filter((r) => (r.entityType || '') === 'timetableVersion');
  note(`${auditSnap.size} audit row(s) for this school; ${rows.length} on timetableVersion`);

  const supRow = rows.find((r) => ((r.after || {}).transition) === 'timetable_supersede');
  if (!supRow) {
    fail('NO persisted audit row for the supersede — log() accepts an entry only '
       + 'if BOTH its action and its entity type are registered, and it returns '
       + 'false rather than throwing when they are not');
  } else {
    ok('a supersede audit row exists in academicAuditLog');
    eq(supRow.action, 'status_change', 'audit action');
    eq(supRow.entityType, 'timetableVersion', 'audit entity type');
    eq(supRow.entityId, vDoc('PLAN_REGULAR', 'V1'), 'audit target');
    eq((supRow.after || {}).supersededReason, 'ended by the exam timetable', 'reason recorded');
    eq((supRow.before || {}).effectiveTo, '', 'before state: open-ended');
    eq((supRow.after || {}).effectiveTo, shift(5), 'after state: the closing date');
    eq(typeof (supRow.actor || {}).uid, 'string', 'an actor is recorded');
  }

  // ══ CONCURRENCY — real processes, one starting gun ════════════════════
  head('CONCURRENCY — supersede ∥ supersede, and supersede ∥ publish');

  // (a) two supersedes
  await wipe();
  await seedCandidate('PLAN_A', 'VA', 'Mathematics');
  await seedCandidate('PLAN_B', 'VB', 'Chemistry');
  publish('PLAN_A', 'VA', shift(-30), shift(-20));           // bounded, out of the way
  await db.doc(`timetableVersions/${vDoc('PLAN_A', 'VA')}`).set(
    { status: 'published', effectiveFrom: shift(-30), effectiveTo: '' }, { merge: true });
  await db.doc(`timetableVersions/${vDoc('PLAN_B', 'VB')}`).set(
    { status: 'published', effectiveFrom: shift(-25), effectiveTo: '' }, { merge: true });

  let gate = path.join(os.tmpdir(), `sup_${Date.now()}`);
  let runs = [
    held('supersede', 'PLAN_A', 'VA', gate, [`--from=${TODAY}`, `--last=${shift(10)}`, '--reason=A', '--admin=STA_A']),
    held('supersede', 'PLAN_B', 'VB', gate, [`--from=${TODAY}`, `--last=${shift(12)}`, '--reason=B', '--admin=STA_B']),
  ];
  await new Promise((r) => setTimeout(r, 1200));
  fs.writeFileSync(gate, 'go');
  let [ra, rb] = await Promise.all(runs);
  try { fs.unlinkSync(gate); } catch (e) {}
  console.log('   | A: ' + JSON.stringify(ra).slice(0, 150));
  console.log('   | B: ' + JSON.stringify(rb).slice(0, 150));
  eq([ra, rb].filter((r) => r.outcome === 'superseded').length, 1,
     'SUPERSEDE ∥ SUPERSEDE — exactly one wins');
  eq([ra, rb].filter((r) => r.outcome === 'refused' && r.code === 'conflict').length, 1,
     'and the loser gets a retryable concurrency conflict');

  // (b) supersede against publish — both individually legal at the same moment
  await wipe();
  await seedCandidate('PLAN_A', 'VOLD', 'Mathematics');
  await seedCandidate('PLAN_B', 'VNEW', 'Chemistry');
  await db.doc(`timetableVersions/${vDoc('PLAN_A', 'VOLD')}`).set(
    { status: 'published', effectiveFrom: shift(50), effectiveTo: '' }, { merge: true });

  gate = path.join(os.tmpdir(), `mix_${Date.now()}`);
  runs = [
    held('supersede', 'PLAN_A', 'VOLD', gate, [`--from=${TODAY}`, `--last=${shift(80)}`, '--reason=end', '--admin=STA_S']),
    held('publish',   'PLAN_B', 'VNEW', gate, [`--from=${shift(-30)}`, `--to=${shift(-10)}`, '--admin=STA_P']),
  ];
  await new Promise((r) => setTimeout(r, 1200));
  fs.writeFileSync(gate, 'go');
  const [rs, rp] = await Promise.all(runs);
  try { fs.unlinkSync(gate); } catch (e) {}
  console.log('   | supersede: ' + JSON.stringify(rs).slice(0, 150));
  console.log('   | publish  : ' + JSON.stringify(rp).slice(0, 150));
  // WHAT THIS CAN AND CANNOT PROVE.
  //
  // A supersede takes ~30ms and a publish ~150ms+, so releasing both on one
  // gate usually SERIALISES them rather than interleaving them — and when they
  // serialise, both succeeding is the correct outcome, not a missed conflict.
  // Asserting "exactly one wins" here would be asserting a coincidence of
  // timing. Deterministic mid-operation interleaving is proven instead by
  // TimetablePublishConcurrencyTest, which opens the window at the exact point
  // between the token read and the commit.
  //
  // What this section proves is the property that must hold either way: after
  // both operations, the token reflects them and no date is ambiguous.
  const outcomes = [rs.outcome, rp.outcome].sort();
  note(`outcomes: ${JSON.stringify(outcomes)}`);
  if (rs.outcome === 'refused' || rp.outcome === 'refused') {
    eq([rs, rp].filter((r) => r.outcome === 'refused' && r.code === 'conflict').length, 1,
       'they interleaved, and the loser was refused on the TOKEN');
  } else {
    ok('they serialised — both are legitimate, and the token carried both');
  }

  const mixState = await versions();
  const ambiguous = [];
  for (let i = -90; i <= 200; i++) {
    const d = shift(i);
    if (coversOn(mixState, d).length > 1) ambiguous.push(d);
  }
  eq(ambiguous, [],
     'however they ordered, NO date ends up covered by two timetables');

  const guard = await db.doc(`timetablePublishGuards/${SCHOOL}_${SESSION}`).get();
  eq(guard.exists, true, 'and the shared token exists — one guard, both operations');

  head(failures === 0 ? 'ALL SUPERSEDE ASSERTIONS HELD' : `${failures} ASSERTION(S) FAILED`);
  process.exit(failures === 0 ? 0 : 2);
})().catch((e) => { console.error(e); process.exit(2); });
