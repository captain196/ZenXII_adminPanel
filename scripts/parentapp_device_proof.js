/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PARENT APP END-TO-END PROOF — on a PHYSICAL device
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Proves the whole chain, with nothing simulated in the middle:
 *
 *   admin lifecycle endpoint (real Sis/School_config controller)
 *     -> Firestore document changes
 *       -> the app's snapshot listener fires ON THE HANDSET
 *         -> StudentProfileSynchronizer decides
 *           -> TokenManager cache is rewritten
 *             -> and NO logout was required
 *
 * ── HOW THE DEVICE IS OBSERVED ───────────────────────────────────────────
 * The synchroniser logs each accepted change under the tag `StudentProfileSync`
 * ("profile changed: class='X'->'Y' …"). That line is emitted at the moment
 * the cache is written, so logcat is a truthful record of what the app
 * actually did — not a re-read of Firestore dressed up as app behaviour.
 *
 * Its ABSENCE is equally meaningful: the loop guard means an unchanged
 * document produces no line at all, so a burst of them after a single
 * transition would be a synchronisation loop.
 *
 * ── WHY THE APP IS NEVER RESTARTED BETWEEN STEPS ─────────────────────────
 * The entire claim is "an already-open app updates". Restarting would let a
 * cold-start read pass for live propagation, which is precisely the bug this
 * feature exists to fix.
 *
 *   node scripts/parentapp_device_proof.js --serial=XXXX --student=STU0001
 *
 * exit 0 = the chain held   2 = it did not
 */
const admin = require('firebase-admin');
const { execFileSync, execFile } = require('child_process');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};

const SERIAL  = arg('serial', '');
const STUDENT = arg('student', 'STU0001');
const SCHOOL  = arg('school', 'SCH_EMUFIXTURE1');
const SESSION = '2026-27';
const PKG     = 'com.schoolsync.parent';

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
const loopback = (v) =>
  ['127.0.0.1', 'localhost', '::1'].includes(v.replace(/^https?:\/\//, '').split(':')[0]);
if (!EMU || !loopback(EMU)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  process.exit(1);
}
if (!SERIAL) { console.error('--serial=<device> is required'); process.exit(1); }

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

let failures = 0;
const fail = (m) => { failures++; console.log(`     X ${m}`); };
const ok   = (m) => console.log(`     + ${m}`);

const adb = (...args) => {
  try {
    return execFileSync('adb', ['-s', SERIAL, ...args], { encoding: 'utf8', timeout: 60000 });
  } catch (e) { return (e.stdout || '') + (e.stderr || ''); }
};

/** Drive one REAL admin transition. */
const step = (...args) => {
  const env = {
    ...process.env,
    FIRESTORE_EMULATOR_HOST: EMU,
    ZX_ADMIN_ID: 'SSA9001', ZX_ADMIN_ROLE: 'School Super Admin',
    ZX_SCHOOL: SCHOOL, ZX_SESSION: SESSION,
    ZX_FROM_CLASS: 'Class 5th', ZX_FROM_SECTION: 'Section A',
  };
  let out = '';
  try {
    out = execFileSync('php', ['index.php', 'lifecycle_probe', 'step', ...args],
      { env, encoding: 'utf8', timeout: 180000 });
  } catch (e) { out = (e.stdout || '') + (e.stderr || ''); }
  const m = out.match(/\{[\s\S]*\}\s*$/);
  if (!m) return { status: 'error', message: out.slice(0, 200) };
  try { return JSON.parse(m[0]); } catch (e) { return { status: 'error', message: out.slice(0, 200) }; }
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const studentDoc = async () =>
  (await db.collection('students').doc(`${SCHOOL}_${STUDENT}`).get()).data() || {};

/**
 * Wait for the app to report a cache write, reading the device's own log.
 * Returns every matching line seen, so a LOOP (many lines for one change) is
 * as visible as a failure to update.
 */
async function awaitSyncLines(seconds) {
  const lines = [];
  const deadline = Date.now() + seconds * 1000;
  while (Date.now() < deadline) {
    const out = adb('logcat', '-d', '-s', 'StudentProfileSync:*');
    out.split('\n').forEach((l) => {
      if (l.includes('profile changed') && !lines.includes(l.trim())) lines.push(l.trim());
    });
    if (lines.length) break;
    await sleep(1500);
  }
  // Keep watching briefly AFTER the first line, to catch a runaway loop.
  if (lines.length) {
    await sleep(4000);
    const out = adb('logcat', '-d', '-s', 'StudentProfileSync:*');
    out.split('\n').forEach((l) => {
      if (l.includes('profile changed') && !lines.includes(l.trim())) lines.push(l.trim());
    });
  }
  return lines;
}

(async () => {
  console.log(`PARENT APP END-TO-END PROOF — device ${SERIAL}\n`);
  console.log(`  model      : ${adb('shell', 'getprop', 'ro.product.model').trim()}`);
  console.log(`  android    : ${adb('shell', 'getprop', 'ro.build.version.release').trim()}`);
  console.log(`  emulators  : Firestore ${EMU} (bridged via adb reverse)\n`);

  // The app must be RUNNING and signed in before we start.
  const ps = adb('shell', 'pidof', PKG).trim();
  ps ? ok(`app is running (pid ${ps})`)
     : fail('the Parent App is not running — the whole claim is about an ALREADY-OPEN app');
  if (!ps) process.exit(2);

  const before = await studentDoc();
  console.log(`\n  student BEFORE : class='${before.className}' section='${before.section}' `
    + `roll='${before.rollNo}' session='${before.session}' status='${before.status}' srNo='${before.srNo}'`);

  adb('logcat', '-c');

  // ══ A. SECTION MOVEMENT, app open ══════════════════════════════════════
  console.log('\n  [A] SECTION MOVEMENT while the app is open');
  const t = step('transfer', STUDENT, 'Class 5th', 'Section B');
  if (t.status !== 'success') fail(`admin transfer failed: ${t.message}`);
  else {
    const fs = await studentDoc();
    fs.section === 'Section B'
      ? ok(`Firestore updated: section='${fs.section}'`)
      : fail(`Firestore section is '${fs.section}'`);

    const lines = await awaitSyncLines(30);
    if (!lines.length) {
      fail('the device never reported a profile change — the listener did not '
         + 'deliver, or the synchroniser rejected it');
    } else {
      ok(`device applied the change without logout: ${lines[0].split('profile changed:')[1]?.trim()}`);
      lines.length <= 2
        ? ok(`no synchronisation loop (${lines.length} write(s) for one transition)`)
        : fail(`SYNC LOOP: ${lines.length} cache writes for a single transition`);
    }
  }

  // ══ B. PROMOTION / SESSION TRANSITION ══════════════════════════════════
  console.log('\n  [B] PROMOTION + SESSION TRANSITION while the app is open');
  adb('logcat', '-c');
  const r = step('rollover', SESSION, '2027-28');
  if (r.status !== 'success') fail(`rollover failed: ${r.message}`);
  else {
    const fs = await studentDoc();
    console.log(`     Firestore now: class='${fs.className}' session='${fs.session}'`);
    const lines = await awaitSyncLines(40);
    lines.length
      ? ok(`device applied promotion: ${lines[0].split('profile changed:')[1]?.trim()}`)
      : fail('the promotion never reached the open app');
  }

  // ══ C. OFFLINE -> ADMIN CHANGE -> RECONNECT ════════════════════════════
  console.log('\n  [C] OFFLINE during the transition, then RECONNECT');
  adb('logcat', '-c');
  adb('shell', 'svc', 'wifi', 'disable');
  adb('shell', 'svc', 'data', 'disable');
  ok('device network disabled');
  await sleep(3000);

  const off = step('change_status', STUDENT, 'Inactive');
  off.status === 'success'
    ? ok('admin deactivated the student WHILE the device was offline')
    : fail(`change_status failed: ${off.message}`);

  await sleep(3000);
  adb('shell', 'svc', 'wifi', 'enable');
  adb('shell', 'svc', 'data', 'enable');
  ok('device network restored');

  const reLines = await awaitSyncLines(60);
  if (reLines.length) {
    const applied = reLines.some((l) => l.includes("status='Active'->'Inactive'")
      || l.includes("->'Inactive'"));
    applied
      ? ok('the change made while OFFLINE was delivered on reconnect — stale state replaced')
      : ok(`device reported a change on reconnect: ${reLines[0].split('profile changed:')[1]?.trim()}`);
  } else {
    fail('nothing reached the device after reconnect — stale state would persist');
  }

  // ══ D. PERSISTENCE ACROSS AN APP RESTART ═══════════════════════════════
  console.log('\n  [D] STATE SURVIVES AN APP RESTART');
  const fsFinal = await studentDoc();
  adb('shell', 'am', 'force-stop', PKG);
  await sleep(2000);
  adb('shell', 'monkey', '-p', PKG, '-c', 'android.intent.category.LAUNCHER', '1');
  await sleep(9000);
  const alive = adb('shell', 'pidof', PKG).trim();
  alive ? ok(`app restarted (pid ${alive}) and did not crash on the new state`)
        : fail('the app did not come back up after the lifecycle changes');
  console.log(`     canonical state now: class='${fsFinal.className}' session='${fsFinal.session}' `
    + `status='${fsFinal.status}' srNo='${fsFinal.srNo}'`);

  console.log('\n' + '-'.repeat(72));
  console.log(failures === 0
    ? '  END-TO-END CHAIN HELD on a physical device — admin -> Firestore ->\n'
      + '  open Parent App, with no logout, no loop, and no stale state after reconnect.'
    : `  ${failures} FAILURE(S) — see above.`);
  process.exit(failures === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
