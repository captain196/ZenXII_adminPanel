/**
 * ══════════════════════════════════════════════════════════════════════════
 *  NEW SCHOOL · WORKS WITH **NO ROLLOUT GRANT AT ALL**
 * ══════════════════════════════════════════════════════════════════════════
 *
 * THE CLAIM: a school sold tomorrow configures its fee heads through the
 * product and bills correctly — entitlement pricing, allocation ledger and all
 * — without anyone granting a single flag for it.
 *
 * Every previous proof granted the rollout chain first, so it could never have
 * detected "this only works because someone remembered". Here the tenant's
 * schoolControl is asserted to hold NO rolloutFlags, and the run fails if it
 * does. What makes it work is the config default, and what makes the default
 * safe is the capability guard in Fee_lifecycle.
 *
 * ── THE SECOND HALF, WHICH MATTERS MORE ──────────────────────────────────
 * It also proves the guard: BEFORE the heads are registered the same school
 * must still BILL — on legacy pricing — rather than fail head resolution closed
 * and generate zero demands. Default-on without that guard would silently
 * unbill every school that had not finished configuring.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/newschool_noflag_proof.js
 */
const admin = require('firebase-admin');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
if (!['127.0.0.1', 'localhost', '::1'].includes(EMU.split(':')[0])) {
  console.error('REFUSING: FIRESTORE_EMULATOR_HOST must be loopback');
  process.exit(1);
}

const S = 'SCH_EMUFIXTURE1';
const SESSION = '2026-27';
const ROOT = path.join(__dirname, '..');

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

let fails = 0;
const ok  = (m) => console.log(`     PASS  ${m}`);
const bad = (m) => { fails++; console.log(`     FAIL  ${m}`); };
const chk = (c, m) => (c ? ok(m) : bad(m));
const head = (m) => console.log(`\n  ${m}`);

function clearFsdoc() {
  const dir = path.join(ROOT, 'application', 'cache', 'fsdoc');
  let n = 0;
  try { for (const f of fs.readdirSync(dir)) if (f.endsWith('.cache')) { fs.unlinkSync(path.join(dir, f)); n++; } }
  catch (e) {}
  return n;
}

function probe(step, ...args) {
  const env = { ...process.env, FIRESTORE_EMULATOR_HOST: EMU,
    ZX_ADMIN_ID: 'SSA9001', ZX_ADMIN_ROLE: 'School Super Admin',
    ZX_SCHOOL: S, ZX_SESSION: SESSION };
  let out = '';
  try { out = execFileSync('php', ['index.php', 'lifecycle_probe', 'step', step, ...args],
    { env, encoding: 'utf8', timeout: 180000, cwd: ROOT }); }
  catch (e) { out = (e.stdout || '') + (e.stderr || ''); }
  const m = out.match(/\{[\s\S]*\}\s*$/);
  try { return m ? JSON.parse(m[0]) : { status: 'error', message: out.slice(0, 200) }; }
  catch (e) { return { status: 'error', message: out.slice(0, 200) }; }
}

const rows = async (col) =>
  (await db.collection(col).where('schoolId', '==', S).get()).docs.map((d) => d.data());

/** The school's concession policy, in the entitlement shape. */
function entitlement(studentId) {
  const id = `${S}_${studentId}_E${crypto.randomBytes(10).toString('hex')}`;
  return [id, {
    entitlementId: id, schoolId: S, studentId, session: SESSION,
    kind: 'concession', concessionType: 'other', scope: 'all',
    valueType: 'percent', value: 10, status: 'active',
    approvalRequired: false, effectiveFrom: '2026-04-01', effectiveTo: null,
    source: 'noflag-proof', createdAt: new Date().toISOString(),
  }];
}

/** A new school's chart carries no registry ref — the seed fabricates one. */
async function stripFabricatedRefs() {
  let n = 0;
  for (const d of (await db.collection('feeStructures').get()).docs) {
    const v = d.data();
    const key = ['heads', 'feeHeads', 'rows'].find((k) => Array.isArray(v[k]));
    if (!key) continue;
    let touched = false;
    const next = v[key].map((r) => {
      if (r && r.feeHeadRef !== undefined) { touched = true; const { feeHeadRef, ...rest } = r; return rest; }
      return r;
    });
    if (touched) { await d.ref.update({ [key]: next }); n++; }
  }
  return n;
}

(async () => {
  console.log('\nNEW SCHOOL · NO ROLLOUT GRANT AT ALL');
  console.log(`emulator ${EMU}\n`);

  head(`[0] cleared ${clearFsdoc()} fsdoc entr(ies); stripped refs from ${await stripFabricatedRefs()} chart doc(s)`);

  // THE PRECONDITION THE WHOLE PROOF RESTS ON.
  const ctl = (await db.collection('schoolControl').doc(S).get()).data() || {};
  const flags = ctl.rolloutFlags || {};
  chk(Object.keys(flags).length === 0,
    `tenant holds NO rollout grants (${JSON.stringify(Object.keys(flags))}) — if it holds any, this `
    + 'proves only that granting works, which was never in doubt');

  const reg0 = (await db.collection('feeHeadRegistry').get()).size;
  chk(reg0 === 0, `feeHeadRegistry starts empty (${reg0})`);

  // ══ PHASE 1 · BEFORE CONFIGURATION — THE SCHOOL MUST STILL BILL ═════════
  head('── [1] heads NOT yet registered · the guard must keep billing ──');
  const a1 = probe('admit', 'Before Config', '5', 'A');
  chk(a1.status === 'success', `admitted ${a1.user_id || a1.message}`);
  const demBefore = (await rows('feeDemands')).filter((d) => d.studentId === a1.user_id);
  chk(demBefore.length > 0,
    `student billed on LEGACY pricing while the registry is empty (${demBefore.length} demands) — `
    + 'without the capability guard, registry-mode-by-default would have produced ZERO');

  // ══ PHASE 2 · ADMIN CONFIGURES · NO FLAG IS TOUCHED ═════════════════════
  head('── [2] admin registers every charted head (real endpoint) ──────');
  const names = new Set();
  (await db.collection('feeStructures').where('schoolId', '==', S).get()).docs.forEach((d) => {
    const v = d.data();
    const key = ['heads', 'feeHeads', 'rows'].find((k) => Array.isArray(v[k]));
    if (key) v[key].forEach((r) => { if (r && r.name) names.add(String(r.name)); });
  });
  const catFor = (n) => /transport|bus/i.test(n) ? 'transport' : (/hostel|mess/i.test(n) ? 'hostel' : 'academic');
  for (const n of [...names].sort()) {
    const r = probe('register_head', n, catFor(n));
    chk(r.status === 'success', `registered "${n}" (${catFor(n)}) → ${r.feeHeadId || r.message}`);
  }
  clearFsdoc();   // the registry just changed; do not read a stale absence

  // ══ PHASE 3 · BILL A DISCOUNTED STUDENT · STILL NO GRANT ════════════════
  head('── [3] discounted admission · still zero grants ────────────────');
  const probeId = probe('admit', 'Id Probe', '5', 'A').user_id || 'STU0000';
  const nextId = 'STU' + String(parseInt(probeId.slice(3), 10) + 1).padStart(4, '0');
  const [eid, edoc] = entitlement(nextId);
  await db.collection('studentEntitlements').doc(eid).set(edoc);
  ok(`entitlement seeded for ${nextId} BEFORE admission`);

  const a2 = probe('admit', 'Discounted', '5', 'A');
  chk(a2.user_id === nextId, `admitted ${a2.user_id} (matches the pre-seeded grant)`);

  const dem = (await rows('feeDemands')).filter((d) => d.studentId === nextId);
  const disc = dem.filter((d) => Number(d.discountAmount || 0) > 0.005);
  const allocSnap = await db.collection('entitlementAllocations').where('schoolId', '==', S).get();
  const alloc = allocSnap.docs.map((d) => d.data());
  console.log(`     demands=${dem.length} discounted=${disc.length} allocations=${alloc.length}`);

  chk(dem.length > 0, 'demands generated');
  chk(disc.length > 0, 'demands are GENUINELY DISCOUNTED — the entitlement authority ran BY DEFAULT');
  chk(alloc.length > 0, 'allocation rows emitted BY DEFAULT — no USE_ALLOCATION_LEDGER grant exists');

  // I2 on every discounted demand.
  const sum = {};
  alloc.filter((r) => (r.status || 'active') === 'active')
    .forEach((r) => { sum[r.demandId] = (sum[r.demandId] || 0) + Number(r.allocatedAmount || 0); });
  const viol = disc.filter((d) => Math.abs((sum[d.demandId] || 0) - Number(d.discountAmount)) > 0.005);
  chk(viol.length === 0, `I2 holds on all ${disc.length} discounted demand(s)`);

  // The grants must STILL be absent — nothing may have quietly written one.
  const ctl2 = (await db.collection('schoolControl').doc(S).get()).data() || {};
  chk(Object.keys(ctl2.rolloutFlags || {}).length === 0,
    'the tenant STILL holds no rollout grants — the whole journey ran on defaults');

  console.log(`\n${fails === 0 ? 'NEW SCHOOL WORKS WITH NO FLAG ACTION' : fails + ' CHECK(S) FAILED'}\n`);
  process.exit(fails === 0 ? 0 : 1);
})().catch((e) => { console.error('ABORTED: ' + e.stack); process.exit(1); });
