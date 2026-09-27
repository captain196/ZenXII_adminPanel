/**
 * READ-ONLY production parity probe.
 *
 * Answers one question from OUTSIDE the server: is production executing the
 * new payment engine, or the old one?
 *
 * ── WHY THIS IS NOT GUESSWORK ────────────────────────────────────────────
 * The release adds public controller methods that production does not have.
 * CodeIgniter falls back to automatic controller/method routing, so a method
 * that exists answers differently from one that does not:
 *
 *   BEFORE the release   /fee_management/student_charges        -> 404
 *   AFTER  the release   /fee_management/student_charges        -> 503
 *                        (the release-decoupling guard: shipped, not enabled)
 *
 *   BEFORE               /fee_management/test_gateway_connection -> 404
 *   AFTER                                                        -> 401/403
 *                        (method exists, refuses an unauthenticated caller)
 *
 * A 503 on student_charges is therefore a POSITIVE parity signal AND a live
 * check that the guard survived the merge. A 500 there would mean the guard
 * was lost and the endpoint is fataling on a missing service.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────────
 * GET only, unauthenticated, no body. Nothing is created, read from a tenant,
 * or mutated. GET is used deliberately: CSRF fires BEFORE routing on POST, so
 * a POST 403 cannot distinguish "route missing" from "route present".
 *
 *   node scripts/production_parity_probe_readonly.js
 *   node scripts/production_parity_probe_readonly.js --host=https://www.zenxii.com
 */
const https = require('https');
const http  = require('http');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};
const HOST = (arg('host', 'https://www.zenxii.com')).replace(/\/+$/, '');

const get = (url) => new Promise((resolve) => {
  const mod = url.startsWith('https') ? https : http;
  const req = mod.get(url, { timeout: 20000 }, (r) => {
    // Drain without keeping the body: a response body could contain tenant
    // data, and nothing here needs it.
    r.on('data', () => {});
    r.on('end', () => resolve(r.statusCode));
  });
  req.on('timeout', () => { req.destroy(); resolve(null); });
  req.on('error', () => resolve(null));
});

/**
 * Each probe states what the OLD code answers and what the NEW code answers.
 * A status matching neither is reported as INCONCLUSIVE rather than guessed at.
 */
const PROBES = [
  {
    path: '/fee_management/student_charges',
    old:  [404],
    neu:  [503],
    means: 'release deployed AND the Student Charges guard is intact',
    alarm: { 500: 'GUARD LOST — the endpoint is fataling on a missing service' },
  },
  {
    path: '/fee_management/test_gateway_connection',
    old:  [404],
    neu:  [401, 403],
    means: 'the new gateway self-test endpoint exists and refuses anonymous callers',
  },
  {
    path: '/fee_management/payment_webhook',
    old:  [400, 401, 403, 405],
    neu:  [400, 401, 403, 405],
    means: 'webhook route reachable (present in BOTH versions — not a parity signal)',
    notSignal: true,
  },
];

(async () => {
  console.log(`PRODUCTION PARITY PROBE  —  ${HOST}`);
  console.log('READ-ONLY. GET only, unauthenticated, no body retained.\n');

  let neu = 0, old = 0, inconclusive = 0, alarms = 0;

  for (const p of PROBES) {
    const code = await get(HOST + p.path);
    let verdict;

    if (code === null)                       { verdict = 'UNREACHABLE'; inconclusive++; }
    else if (p.alarm && p.alarm[code])       { verdict = `** ${p.alarm[code]} **`; alarms++; }
    else if (p.notSignal)                    { verdict = 'not a parity signal'; }
    else if (p.neu.includes(code))           { verdict = 'NEW ENGINE'; neu++; }
    else if (p.old.includes(code))           { verdict = 'OLD ENGINE'; old++; }
    else                                     { verdict = 'INCONCLUSIVE'; inconclusive++; }

    console.log(`  ${String(code ?? '---').padEnd(5)} ${p.path.padEnd(44)} ${verdict}`);
    if (!p.notSignal) console.log(`        expect ${p.old.join('/')} before, ${p.neu.join('/')} after — ${p.means}`);
  }

  console.log('\n' + '-'.repeat(74));
  if (alarms) {
    console.log('  ALARM — a deployed endpoint is fataling. Investigate before any payment.');
    process.exit(2);
  }
  if (neu > 0 && old === 0)      console.log('  PRODUCTION RUNS THE NEW PAYMENT ENGINE.');
  else if (old > 0 && neu === 0) console.log('  PRODUCTION RUNS THE OLD ENGINE — the release is NOT deployed.');
  else if (neu > 0 && old > 0)   console.log('  MIXED — a partial deployment. Treat as NOT deployed and investigate.');
  else                           console.log('  INCONCLUSIVE — could not establish parity from outside.');
  console.log('-'.repeat(74));

  // Exit 0 only when parity is positively established.
  process.exit(neu > 0 && old === 0 ? 0 : 3);
})();
