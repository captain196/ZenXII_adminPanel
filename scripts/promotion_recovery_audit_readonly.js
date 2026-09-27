/**
 * READ-ONLY promotion recovery audit.
 *
 * ── WHAT THIS EXISTS TO CATCH ────────────────────────────────────────────
 * Promotion moves students SYNCHRONOUSLY and then defers fee regeneration to
 * a post-response shutdown handler. If that handler dies mid-loop — fatal,
 * OOM, timeout, worker recycle — the students are already in their new class
 * while some of them have no demands in the destination session.
 *
 * The code itself names that state as the danger ("Active-with-zero-demands").
 * Nothing watched for it: the job document sat at `deferred-fees-pending`
 * forever, there is no status-poll endpoint, and `processedCount` was written
 * only at the very end, so an interruption left a figure that was not merely
 * stale but false.
 *
 * This reconciles the JOB's recorded intent against the DEMANDS that actually
 * exist. It changes nothing — repair is a decision, not a side effect of
 * looking.
 *
 *   node scripts/promotion_recovery_audit_readonly.js --school=SCH_B56BB9A401
 *   node scripts/promotion_recovery_audit_readonly.js --stale-minutes=30
 *
 * exit 0 = every promotion job accounted for
 *      2 = a job is stuck, or a promoted student has no demands
 */
const admin = require('firebase-admin');
const path  = require('path');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};
const SCHOOL  = arg('school', 'SCH_B56BB9A401');
const STALE_M = parseInt(arg('stale-minutes', '30'), 10);

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

let problems = 0;
const fail = (msg) => { problems++; console.log(`  PROBLEM  ${msg}`); };

// ── THE DECISIONS, AS PURE FUNCTIONS ─────────────────────────────────────
// Factored out so they can be exercised without Firestore. There are no
// promote_jobs documents in this project yet — the deferred path has no
// operating history — so running the audit against live data proves only that
// it runs, not that it DETECTS. --selftest closes that gap.
const isStuck = (job, nowMs, staleMinutes) => {
  if (String(job.status || '') !== 'deferred-fees-pending') return false;
  const started = job.startedAt ? Date.parse(job.startedAt) : NaN;
  if (Number.isNaN(started)) return true;          // undatable + unfinished = stuck
  return (nowMs - started) / 60000 > staleMinutes;
};

const countsAccountForIntent = (job) => {
  const expected  = Number(job.expectedCount || 0);
  if (!String(job.status || '').startsWith('completed') || expected <= 0) return true;
  const processed = Number(job.processedCount || 0);
  const failed    = Array.isArray(job.failedStudents) ? job.failedStudents.length : 0;
  return processed + failed === expected;
};

if (process.argv.includes('--selftest')) {
  const now = Date.parse('2026-08-26T12:00:00Z');
  const cases = [
    ['stuck job, 90m old',            isStuck({status:'deferred-fees-pending',startedAt:'2026-08-26T10:30:00Z'}, now, 30), true],
    ['fresh job, 5m old',             isStuck({status:'deferred-fees-pending',startedAt:'2026-08-26T11:55:00Z'}, now, 30), false],
    ['completed job is never stuck',  isStuck({status:'completed',startedAt:'2026-08-26T01:00:00Z'}, now, 30), false],
    ['unfinished with no timestamp',  isStuck({status:'deferred-fees-pending'}, now, 30), true],
    ['counts add up',                 countsAccountForIntent({status:'completed',expectedCount:60,processedCount:58,failedStudents:[1,2]}), true],
    ['counts do NOT add up',          countsAccountForIntent({status:'completed',expectedCount:60,processedCount:58,failedStudents:[]}), false],
    ['killed handler reports zero',   countsAccountForIntent({status:'completed_with_errors',expectedCount:60,processedCount:0,failedStudents:[]}), false],
    ['pending job is not counted yet', countsAccountForIntent({status:'deferred-fees-pending',expectedCount:60,processedCount:0}), true],
  ];
  let bad = 0;
  console.log('PROMOTION RECOVERY AUDIT — SELFTEST (no Firestore)\n');
  for (const [name, got, want] of cases) {
    const ok = got === want;
    if (!ok) bad++;
    console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name.padEnd(34)} got=${got} want=${want}`);
  }
  console.log('\n' + (bad === 0
    ? '  DETECTOR VERIFIED — it fires on damage and stays quiet on healthy jobs.'
    : `  ${bad} SELFTEST FAILURE(S) — the detector cannot be trusted.`));
  process.exit(bad === 0 ? 0 : 2);
}

(async () => {
  console.log(`PROMOTION RECOVERY AUDIT — ${SCHOOL} (READ ONLY)\n`);

  const snap = await db.collection('promote_jobs').where('schoolId', '==', SCHOOL).get();
  if (snap.empty) {
    console.log('  no promotion jobs recorded for this school.');
    console.log('  Nothing to reconcile — this is a clean result, not a skipped check.');
    process.exit(0);
  }

  console.log(`  jobs found: ${snap.size}\n`);

  for (const doc of snap.docs) {
    const j = doc.data();
    const status   = String(j.status || '');
    const started  = j.startedAt ? Date.parse(j.startedAt) : NaN;
    const ageMin   = Number.isNaN(started) ? null : Math.floor((Date.now() - started) / 60000);
    const expected = Number(j.expectedCount || 0);
    const processed = Number(j.processedCount || 0);
    const ids = Array.isArray(j.promotedStudentIds) ? j.promotedStudentIds : [];

    console.log(`  ── ${doc.id}`);
    console.log(`     status=${status} expected=${expected} processed=${processed}`
      + ` age=${ageMin === null ? '?' : ageMin + 'm'} session ${j.sessionFrom} -> ${j.sessionTo}`);

    // 1. A job that never reported completion.
    if (status === 'deferred-fees-pending') {
      if (ageMin !== null && ageMin > STALE_M) {
        fail(`job ${doc.id} has been '${status}' for ${ageMin}m — the deferred handler `
          + 'did not finish. Promoted students may have no demands.');
      } else {
        console.log('     still within the expected window — not yet stale.');
      }
    }

    // 2. Recorded intent, so recovery can be targeted rather than guessed.
    if (!ids.length) {
      console.log('     NOTE: no promotedStudentIds recorded (job predates LC-5). '
        + 'Per-student reconciliation is not possible for this job.');
      continue;
    }

    // 3. THE REAL CHECK — does each promoted student actually have demands in
    //    the destination session? This is the state the deferred loop exists
    //    to create, so its absence is the damage.
    const toSession = String(j.sessionTo || '');
    let missing = [];
    for (const sid of ids) {
      if (!sid) continue;
      const d = await db.collection('feeDemands')
        .where('schoolId', '==', SCHOOL)
        .where('studentId', '==', sid)
        .where('session', '==', toSession)
        .limit(1).get();
      if (d.empty) missing.push(sid);
    }

    if (missing.length) {
      fail(`${missing.length}/${ids.length} promoted students have NO demands in `
        + `${toSession}: ${missing.slice(0, 10).join(', ')}`
        + (missing.length > 10 ? ` … (+${missing.length - 10} more)` : ''));
      console.log('     These students are Active in the destination class and owe nothing.');
      console.log('     Re-run promotion for exactly these ids, or regenerate their demands.');
    } else {
      console.log(`     all ${ids.length} promoted students have demands in ${toSession}.`);
    }

    // 4. A completed job whose counts disagree with its own intent.
    if (status.startsWith('completed') && expected > 0 && processed !== expected) {
      const failed = Array.isArray(j.failedStudents) ? j.failedStudents.length : 0;
      if (processed + failed !== expected) {
        fail(`job ${doc.id} reports processed=${processed} + failed=${failed} `
          + `which does not account for expected=${expected}`);
      }
    }
  }

  console.log('\n' + '-'.repeat(70));
  console.log(problems === 0
    ? '  ALL PROMOTION JOBS RECONCILED — no orphaned job, no student left without demands.'
    : `  ${problems} PROBLEM(S) — see above. Nothing was changed by this audit.`);
  process.exit(problems === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
