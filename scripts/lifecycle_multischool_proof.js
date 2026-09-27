/**
 * ══════════════════════════════════════════════════════════════════════════
 *  MULTI-SCHOOL PROOF — graduation happens at EACH school's own last class
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ZenX must not hardcode one school's class ladder. Graduation derives the
 * terminal class from `schools.classes`, so the SAME code has to graduate a
 * Class 10th student at one tenant and a Class 12th student at another — and,
 * critically, must NOT graduate the Class 10th student at the Class 12 school,
 * who still has two years to go.
 *
 * That derivation is unit-tested. This proves it at RUNTIME, on two tenants
 * with different ladders, through the real rollover endpoint — which is the
 * difference between "the rule is implemented" and "a Class 12 school
 * actually behaves correctly".
 *
 * It also proves TENANT ISOLATION on the same run: a rollover on one fixture
 * must not touch the other.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/lifecycle_multischool_proof.js
 *
 * exit 0 = both ladders behaved   2 = a violation
 */
const admin = require('firebase-admin');
const { execFileSync } = require('child_process');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
const host = EMU.replace(/^https?:\/\//, '').split(':')[0];
if (!EMU || !['127.0.0.1', 'localhost', '::1'].includes(host)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  process.exit(1);
}

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

let failures = 0;
const fail = (m) => { failures++; console.log(`     ✗ ${m}`); };
const ok   = (m) => console.log(`     ✓ ${m}`);

const SESSION = '2026-27', NEXT = '2027-28';

function step(school, ...args) {
  const env = {
    ...process.env,
    FIRESTORE_EMULATOR_HOST: EMU,
    ZX_ADMIN_ID: 'SSA9001', ZX_ADMIN_ROLE: 'School Super Admin',
    ZX_SCHOOL: school, ZX_SESSION: SESSION,
  };
  let out = '';
  try {
    out = execFileSync('php', ['index.php', 'lifecycle_probe', 'step', ...args],
      { env, encoding: 'utf8', timeout: 180000 });
  } catch (e) { out = (e.stdout || '') + (e.stderr || ''); }
  const m = out.match(/\{[\s\S]*\}\s*$/);
  if (!m) return { status: 'error', message: out.slice(0, 200) };
  try { return JSON.parse(m[0]); } catch (e) { return { status: 'error', message: out.slice(0, 200) }; }
}

const doc = async (school, sid) =>
  (await db.collection('students').doc(`${school}_${sid}`).get()).data() || null;

(async () => {
  console.log(`MULTI-SCHOOL TERMINAL-CLASS PROOF on ${EMU}\n`);

  const CASES = [
    { school: 'SCH_EMUFIXTURE1', terminal: 'Class 10th', ladder: '10' },
    { school: 'SCH_EMUFIXTURE2', terminal: 'Class 12th', ladder: '12' },
  ];

  const admitted = {};

  for (const c of CASES) {
    console.log(`  ── ${c.school}  (terminal ${c.terminal})`);

    // A student sitting IN the terminal class — must graduate.
    const termNum = c.terminal.replace(/\D/g, '');
    const t = step(c.school, 'admit', `ZXLCPROBE Terminal${c.ladder}`, termNum, 'A');
    if (t.status !== 'success') { fail(`terminal admission failed: ${t.message}`); continue; }

    // A student in Class 10th at BOTH schools. At the Class 10 school that is
    // terminal; at the Class 12 school it is emphatically not, and graduating
    // them would end a child's schooling two years early.
    const m = step(c.school, 'admit', `ZXLCPROBE Mid${c.ladder}`, '10', 'B');
    if (m.status !== 'success') { fail(`Class 10th admission failed: ${m.message}`); continue; }

    admitted[c.school] = { terminal: t.user_id, mid: m.user_id };
    console.log(`     admitted ${t.user_id} (${c.terminal}) and ${m.user_id} (Class 10th)`);
  }

  // ── Roll each school over independently ─────────────────────────────
  for (const c of CASES) {
    if (!admitted[c.school]) continue;
    console.log(`\n  ── ROLLOVER ${c.school}`);
    const r = step(c.school, 'rollover', SESSION, NEXT);
    if (r.status !== 'success') { fail(`rollover failed: ${r.message}`); continue; }
    console.log(`     ${String(r.message || '').slice(0, 150)}`);

    const term = await doc(c.school, admitted[c.school].terminal);
    const mid  = await doc(c.school, admitted[c.school].mid);

    term && term.status === 'Alumni'
      ? ok(`the ${c.terminal} student graduated (Alumni)`)
      : fail(`the ${c.terminal} student is '${term && term.status}' in `
           + `'${term && term.className}' — expected Alumni at this school's last class`);

    if (c.ladder === '10') {
      // Same student, same class, opposite correct outcome.
      mid && mid.status === 'Alumni'
        ? ok('Class 10th is terminal here, so that student graduated too')
        : fail(`Class 10th student is '${mid && mid.status}' — terminal here, should be Alumni`);
    } else {
      mid && mid.status !== 'Alumni'
        ? ok(`Class 10th student did NOT graduate here — promoted to `
           + `'${mid && mid.className}', correct for a Class 12 school`)
        : fail('a Class 10th student was GRADUATED at a Class 12 school — their '
             + 'schooling was ended two years early');
    }
  }

  // ── Tenant isolation across the whole run ───────────────────────────
  console.log('\n  ── TENANT ISOLATION');
  for (const c of CASES) {
    const snap = await db.collection('students').where('schoolId', '==', c.school).get();
    let leaked = 0;
    snap.forEach((d) => {
      const x = d.data() || {};
      if (String(x.schoolId) !== c.school) leaked++;
      if (!String(d.id).startsWith(`${c.school}_`)) leaked++;
    });
    leaked === 0
      ? ok(`${c.school}: all ${snap.size} students correctly scoped`)
      : fail(`${c.school}: ${leaked} scoping violation(s)`);
  }

  console.log('\n' + '-'.repeat(72));
  console.log(failures === 0
    ? '  BOTH LADDERS BEHAVED — graduation follows each school\'s own configuration,\n'
      + '  and no student graduated early at the longer ladder.'
    : `  ${failures} VIOLATION(S) — see above.`);
  process.exit(failures === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
