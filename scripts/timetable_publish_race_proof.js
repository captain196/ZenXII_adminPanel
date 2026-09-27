/**
 * ══════════════════════════════════════════════════════════════════════════
 *  CAN TWO ADMINISTRATORS PUBLISHING AT ONCE FORK THE TIMETABLE?
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `POST /timetable_lifecycle/publish` reaches the projection writer:
 *
 *   Timetable_lifecycle::publish()            require_permission(Academic, manage)
 *     → Timetable_plan_store::publishCandidate()
 *         → firestoreSet(timetableVersions, …)        status := published
 *         → projectPublishedVersion(plan, version, TRUE)
 *             → firestoreSet(timetables, …)           the collection both apps read
 *
 * So an HTTP request CAN write `timetables`. Two questions follow, and neither
 * can be answered by reading the code:
 *
 *   1. Two admins publishing two different versions that both cover today —
 *      each one's overlap check runs BEFORE the other's write lands. Do both
 *      publish, and do both project into the same documents?
 *   2. The cron writes the same documents. Can it interleave with a publish?
 *
 * Both are read-then-write windows, so both are tested by opening the window
 * for real: two OS processes, one starting gun, the same tenant. Sequential
 * calls would step over the window and prove nothing.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8080 node scripts/timetable_publish_race_proof.js
 *
 * exit 0 = every assertion held (which includes assertions that RECORD a race,
 *          where the race is the finding); 2 = an assertion failed.
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
const SCHOOL  = 'SCH_RACEPROOF1';
const SESSION = '2026-27';
const PLAN    = 'PLAN1';
const TODAY   = new Date().toISOString().slice(0, 10);

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

const PERIODS = (subject, tid) => ([
  { periodNumber: 1, subject, teacherId: tid, teacher: tid,
    startTime: '09:00', endTime: '09:40', type: 'class' },
]);

// Scale matters for the interleaving question. Each projected document is an
// INDEPENDENT REST write — there is no batch and no transaction — so the wider
// the write burst, the more chance two concurrent projections interleave. A
// real school is 30-60 sections x 6 days; ZX_RACE_SCALE reproduces that.
const SCALE = parseInt(process.env.ZX_RACE_SCALE || '2', 10);
const CELLS = Array.from({ length: SCALE }, (_, i) =>
  `Class ${9 + Math.floor(i / 4)}th/Section ${String.fromCharCode(65 + (i % 4))}`);
const DAYS  = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
  .slice(0, SCALE <= 2 ? 2 : 6);

const legacyId = (sectionKey, day) =>
  `${SCHOOL}_${SESSION}_${sectionKey.replace(/\//g, '_')}_${day}`;

async function wipe() {
  try {
    const dir = path.join(REPO, 'application', 'cache', 'fsdoc');
    for (const f of fs.readdirSync(dir)) fs.unlinkSync(path.join(dir, f));
  } catch (e) { /* absent is fine */ }
  for (const col of ['timetables', 'timetableVersions', 'timetableVersionDays',
                     'timetableSettings', 'schoolControl', 'schools', 'academicAuditLog']) {
    const snap = await db.collection(col).get();
    const batch = db.batch();
    snap.docs.forEach((d) => batch.delete(d.ref));
    if (snap.size) await batch.commit();
  }
}

/** A candidate version, complete and checked, ready to publish. */
async function seedVersion(versionId, subject, teacherId, plan = PLAN) {
  await db.doc(`timetableVersions/${SCHOOL}_${SESSION}_${plan}_${versionId}`).set({
    schoolId: SCHOOL, session: SESSION, planId: plan, versionId,
    planName: `Plan ${plan}`, status: 'candidate',
    effectiveFrom: '', effectiveTo: '',
    generation: { state: 'complete' },
    facts: { metrics: { shortfall: { known: true, total: 0 } } },
  });
  for (const sectionKey of CELLS) {
    for (const day of DAYS) {
      const safe = sectionKey.replace(/\//g, '_');
      await db.doc(`timetableVersionDays/${SCHOOL}_${SESSION}_${plan}_${versionId}_${safe}_${day}`).set({
        schoolId: SCHOOL, session: SESSION, planId: plan, versionId,
        classSection: sectionKey, day, periods: PERIODS(subject, teacherId),
        manuallyEdited: false,
      });
    }
  }
}

async function seed() {
  await db.doc(`schoolControl/${SCHOOL}`).set({ schoolId: SCHOOL, lifecycle: { state: 'active' } });
  await db.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL, name: SCHOOL, institution: { state: 'active' } });
}

/**
 * Record a REAL verdict on a candidate, computed by the production
 * fingerprint function over the actual days. publishCandidate() refuses a
 * version whose recorded check no longer describes its content, so a
 * hand-written fingerprint would simply be rejected — the probe calls
 * Timetable_validation_service itself.
 */
function stampVerdict(versionId, plan = PLAN) {
  const out = execFileSync('php', ['scripts/timetable_publish_race_probe.php',
    `--school=${SCHOOL}`, `--session=${SESSION}`, `--plan=${plan}`,
    `--version=${versionId}`, `--from=${TODAY}`, '--mode=stamp-verdict'], {
    cwd: REPO, encoding: 'utf8',
    env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU },
  });
  note(`verdict recorded for ${plan}/${versionId}: ${out.trim()}`);
}

/** Launch one publish, held at the gate. Resolves with its parsed JSON. */
function publishProc(versionId, adminId, gate, plan = PLAN) {
  return new Promise((resolve) => {
    const p = spawn('php', ['scripts/timetable_publish_race_probe.php',
      `--school=${SCHOOL}`, `--session=${SESSION}`, `--plan=${plan}`,
      `--version=${versionId}`, `--from=${TODAY}`, `--admin=${adminId}`,
      `--gate=${gate}`], {
      cwd: REPO, env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU },
    });
    let out = '', err = '';
    p.stdout.on('data', (d) => { out += d; });
    p.stderr.on('data', (d) => { err += d; });
    p.on('close', () => {
      const m = out.match(/\{[\s\S]*\}/);
      resolve(m ? JSON.parse(m[0]) : { outcome: 'no-json', raw: out + err });
    });
  });
}

const projectionSnapshot = async () => {
  const s = await db.collection('timetables').where('schoolId', '==', SCHOOL).get();
  const out = {};
  s.docs.forEach((d) => { out[d.id] = d.data(); });
  return out;
};

(async () => {
  head('SETUP');
  await wipe();
  await seed();
  await seedVersion('VA', 'Mathematics', 'STA_A');
  await seedVersion('VB', 'Chemistry',  'STA_B');
  stampVerdict('VA'); stampVerdict('VB');
  ok(`two candidate versions (VA, VB), each covering ${CELLS.length * DAYS.length} cells, both effective ${TODAY}`);

  // ══ Q1 — TWO ADMINS, ONE INSTANT ═══════════════════════════════════════
  head('Q4 — two administrators publish different versions at the same moment');

  const gate = path.join(os.tmpdir(), `ttrace_${Date.now()}`);
  const runs = [publishProc('VA', 'STA_ADMIN_A', gate), publishProc('VB', 'STA_ADMIN_B', gate)];
  await new Promise((r) => setTimeout(r, 1200));   // let both reach the gate
  fs.writeFileSync(gate, 'go');
  const [a, b] = await Promise.all(runs);
  try { fs.unlinkSync(gate); } catch (e) {}

  console.log('   | A: ' + JSON.stringify(a));
  console.log('   | B: ' + JSON.stringify(b));

  const published = [a, b].filter((r) => r.outcome === 'published');
  const projected = published.filter((r) => r.projection && r.projection.applied);

  note(`${published.length} of 2 publishes succeeded; ${projected.length} of those applied a projection`);

  // What is on the versions themselves — the canonical record.
  const vs = await db.collection('timetableVersions').where('schoolId', '==', SCHOOL).get();
  const live = vs.docs.map((d) => d.data())
    .filter((v) => v.status === 'published')
    .map((v) => `${v.versionId}[${v.effectiveFrom}→${v.effectiveTo || 'open'}]`).sort();
  note(`published versions covering today: ${JSON.stringify(live)}`);

  // What is in the collection the apps read.
  const snap = await projectionSnapshot();
  const bySource = {};
  for (const [id, d] of Object.entries(snap)) {
    const v = d.projectedFromVersion || '(none)';
    (bySource[v] = bySource[v] || []).push(id);
  }
  note(`documents in \`timetables\` by source version: ` +
       JSON.stringify(Object.fromEntries(
         Object.entries(bySource).map(([k, v]) => [k, v.length]))));

  const subjects = [...new Set(Object.values(snap)
    .map((d) => ((d.periods || [])[0] || {}).subject).filter(Boolean))].sort();

  // ── THE INVARIANT, ASSERTED ─────────────────────────────────────────
  // Owner-authorised 2026-08-31: for a given school, session and date exactly
  // ONE published version may be in force. Before the fix this section
  // recorded the breach; it now enforces the rule.
  eq(published.length, 1, 'exactly one of two simultaneous publishes succeeded');
  eq(live.length, 1, 'exactly one published version covers today');

  const refused = [a, b].filter((r) => r.outcome === 'refused');
  eq(refused.length, 1, 'the other was refused rather than silently dropped');
  if (refused.length === 1) {
    const why = String(refused[0].message || '');
    if (/someone else published/i.test(why) || /only one timetable in force/i.test(why)) {
      ok(`the loser was told why: "${why.slice(0, 90)}…"`);
    } else {
      fail(`the refusal message does not explain itself: ${why}`);
    }
    if (/refresh|change the dates|end the existing/i.test(why)) {
      ok('and what to do next');
    } else {
      fail('the refusal does not tell the administrator what to do next');
    }
  }

  eq(Object.keys(bySource).length, 1,
     '`timetables` is drawn from exactly ONE version — no split projection');
  eq(subjects.length, 1, `and every class shows one timetable (${subjects.join(', ')})`);

  // Whichever way it went, the CANONICAL side must be honest about it.
  if (live.length > 1) {
    note('two versions are published over the same dates — resolveForDate() must '
       + 'now report AMBIGUOUS rather than choose');
    const cronOut = (() => {
      try {
        return execFileSync('php', ['index.php', 'timetable_projection_cron', 'run'], {
          cwd: REPO, encoding: 'utf8', timeout: 120000,
          env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU,
                 SCHOOL_ID: SCHOOL, SESSION_YEAR: SESSION, FOR_DATE: TODAY },
        });
      } catch (e) { return (e.stdout || '') + (e.stderr || ''); }
    })();
    if (/REFUSED|ambiguous/i.test(cronOut)) {
      ok('the cron REFUSES the ambiguous state rather than picking a version');
    } else {
      fail('the cron did not refuse an ambiguous resolution:\n' + cronOut.slice(0, 400));
    }
  }

  // ══ CROSS-PLAN — THE INVARIANT IS PER SCHOOL, NOT PER PLAN ═════════════
  //
  // The overlap check used to filter by planId while the resolver did not, so
  // publishing an EXAM timetable over a live REGULAR one reported no overlap
  // at all and projected anyway. That path needs no concurrency: one
  // administrator, two sequential clicks. Both forms are exercised here.
  head('CROSS-PLAN — sequential, then concurrent');

  await wipe();
  await seed();
  await seedVersion('V1', 'Mathematics', 'STA_A', 'PLAN_REGULAR');
  await seedVersion('V1', 'Chemistry',   'STA_B', 'PLAN_EXAM');
  stampVerdict('V1', 'PLAN_REGULAR'); stampVerdict('V1', 'PLAN_EXAM');

  const seqA = await publishProc('V1', 'STA_SEQ_A', '', 'PLAN_REGULAR');
  const seqB = await publishProc('V1', 'STA_SEQ_B', '', 'PLAN_EXAM');
  console.log('   | regular: ' + JSON.stringify(seqA));
  console.log('   | exam   : ' + JSON.stringify(seqB));

  eq(seqA.outcome, 'published', 'the first plan publishes normally');
  eq(seqB.outcome, 'refused',
     'a DIFFERENT plan covering the same dates is refused by the same predicate');
  if (seqB.outcome === 'refused') {
    if (/only one timetable in force/i.test(String(seqB.message || ''))) {
      ok('and the refusal names the invariant');
    } else {
      fail(`the cross-plan refusal is not explained: ${seqB.message}`);
    }
  }

  const seqLive = (await db.collection('timetableVersions')
    .where('schoolId', '==', SCHOOL).get()).docs
    .map((d) => d.data()).filter((v) => v.status === 'published');
  eq(seqLive.length, 1, 'exactly one published version across ALL plans');

  const seqCron = (() => {
    try {
      return execFileSync('php', ['index.php', 'timetable_projection_cron', 'run'], {
        cwd: REPO, encoding: 'utf8', timeout: 120000,
        env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU,
               SCHOOL_ID: SCHOOL, SESSION_YEAR: SESSION, FOR_DATE: TODAY },
      });
    } catch (e) { return (e.stdout || '') + (e.stderr || ''); }
  })();
  if (/Resolution: resolved/.test(seqCron)) {
    ok('the resolver is unambiguous — the state the cross-plan gap used to leave');
  } else {
    fail(`the resolver is not clean after a cross-plan attempt:
${seqCron.slice(0, 300)}`);
  }

  // Now the same two plans, at the same instant.
  await wipe();
  await seed();
  await seedVersion('V1', 'Mathematics', 'STA_A', 'PLAN_REGULAR');
  await seedVersion('V1', 'Chemistry',   'STA_B', 'PLAN_EXAM');
  stampVerdict('V1', 'PLAN_REGULAR'); stampVerdict('V1', 'PLAN_EXAM');

  const xgate = path.join(os.tmpdir(), `ttxplan_${Date.now()}`);
  const xruns = [publishProc('V1', 'STA_X_A', xgate, 'PLAN_REGULAR'),
                 publishProc('V1', 'STA_X_B', xgate, 'PLAN_EXAM')];
  await new Promise((r) => setTimeout(r, 1200));
  fs.writeFileSync(xgate, 'go');
  const [xa, xb] = await Promise.all(xruns);
  try { fs.unlinkSync(xgate); } catch (e) {}
  console.log('   | A: ' + JSON.stringify(xa));
  console.log('   | B: ' + JSON.stringify(xb));

  eq([xa, xb].filter((r) => r.outcome === 'published').length, 1,
     'concurrent publishes from DIFFERENT plans: exactly one wins');

  const xLive = (await db.collection('timetableVersions')
    .where('schoolId', '==', SCHOOL).get()).docs
    .map((d) => d.data()).filter((v) => v.status === 'published');
  eq(xLive.length, 1, 'and exactly one published version survives');

  const xSnap = await projectionSnapshot();
  const xSources = [...new Set(Object.values(xSnap)
    .map((d) => d.projectedFromPlan + '/' + d.projectedFromVersion))];
  eq(xSources.length, 1, 'the projection comes from one plan and one version');

  // ══ Q5 — HTTP AND THE CRON, AT THE SAME MOMENT ═════════════════════════
  head('Q5 — a publish and the scheduled projection running together');

  await wipe();
  await seed();
  await seedVersion('VC', 'Biology', 'STA_C');
  stampVerdict('VC');

  // Publish VC so a published version exists for the cron to materialise,
  // then make the legacy documents stale so the cron has real work to do.
  const first = await publishProc('VC', 'STA_ADMIN_C', '');
  console.log('   | seed publish: ' + JSON.stringify(first));

  for (const sectionKey of CELLS) {
    for (const day of DAYS) {
      await db.doc(`timetables/${legacyId(sectionKey, day)}`).set(
        { periods: [{ periodNumber: 1, subject: 'STALE', type: 'class' }], version: 99 },
        { merge: true });
    }
  }

  const before = await projectionSnapshot();
  const cronRun = new Promise((resolve) => {
    const p = spawn('php', ['index.php', 'timetable_projection_cron', 'run'], {
      cwd: REPO, env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU,
                        SCHOOL_ID: SCHOOL, SESSION_YEAR: SESSION,
                        FOR_DATE: TODAY, APPLY: '1' },
    });
    let out = ''; p.stdout.on('data', (d) => { out += d; });
    p.stderr.on('data', (d) => { out += d; });
    p.on('close', (code) => resolve({ out, code }));
  });
  // A second projection of the SAME version, entered at the same time —
  // this is exactly what a publish concurrent with the cron does.
  const cronRun2 = new Promise((resolve) => {
    const p = spawn('php', ['index.php', 'timetable_projection_cron', 'run'], {
      cwd: REPO, env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU,
                        SCHOOL_ID: SCHOOL, SESSION_YEAR: SESSION,
                        FOR_DATE: TODAY, APPLY: '1' },
    });
    let out = ''; p.stdout.on('data', (d) => { out += d; });
    p.stderr.on('data', (d) => { out += d; });
    p.on('close', (code) => resolve({ out, code }));
  });
  const [c1, c2] = await Promise.all([cronRun, cronRun2]);
  note(`concurrent projection runs exited ${c1.code} / ${c2.code}`);

  const after = await projectionSnapshot();
  const cells = CELLS.flatMap((sk) => DAYS.map((d) => legacyId(sk, d)));

  const wrongSubject = cells.filter((id) =>
    (((after[id] || {}).periods || [])[0] || {}).subject !== 'Biology');
  eq(wrongSubject, [],
     'every document ended as the published version says, with no half-written cell');

  const versions = cells.map((id) => (after[id] || {}).version);
  note(`legacy version counters after two concurrent runs: ${JSON.stringify(versions)}`);
  const overshoot = versions.filter((v) => v > 101);
  eq(overshoot, [],
     'no counter advanced more than the two runs could account for');

  // The real question for a scheduler: does a repeated run settle?
  const settle = (() => {
    try {
      return execFileSync('php', ['index.php', 'timetable_projection_cron', 'run'], {
        cwd: REPO, encoding: 'utf8', timeout: 120000,
        env: { ...process.env, FIRESTORE_EMULATOR_HOST: EMU,
               SCHOOL_ID: SCHOOL, SESSION_YEAR: SESSION, FOR_DATE: TODAY },
      });
    } catch (e) { return (e.stdout || '') + (e.stderr || ''); }
  })();
  if (/0 document\(s\) would be written/.test(settle)) {
    ok('after the collision the state is settled — a further run writes nothing');
  } else {
    fail('the collision left work outstanding:\n' + settle.split('\n')
      .filter((l) => /would be written/.test(l)).join('\n'));
  }

  head(failures === 0 ? 'RACE PROOF COMPLETE — no assertion failed'
                      : `${failures} ASSERTION(S) FAILED`);
  process.exit(failures === 0 ? 0 : 2);
})().catch((e) => { console.error(e); process.exit(2); });
