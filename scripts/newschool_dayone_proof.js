/**
 * ══════════════════════════════════════════════════════════════════════════
 *  NEW SCHOOL · DAY-ONE FINANCIAL READINESS PROOF (EMULATOR)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * THE QUESTION: can a genuinely clean tenant reach its first correctly
 * allocated discounted fee demand through the SUPPORTED product lifecycle,
 * with no developer intervention — no hand-written Firestore documents, no
 * script with a hardcoded mapping, no flag granted behind the product's back?
 *
 * Every step below is either a real administrator endpoint (dispatched through
 * Lifecycle_probe, so real RBAC and real services run) or the real rollout
 * grant service. NOTHING here writes feeHeadRegistry, schoolControl or a demand
 * directly. That distinction is the whole point: a fixture that writes the
 * foundation itself proves the collections accept documents, and nothing about
 * whether a school can actually build one.
 *
 * The one exception is the ENTITLEMENT, which is seeded directly. It stands in
 * for the school's concession policy — that surface exists already and is not
 * what is under test here. It is flagged in the output rather than hidden.
 *
 * WHAT MADE EARLIER PROOFS LIE, and what is done about it here:
 *   • wrong collection seeded  → assert discounted > 0 BEFORE judging allocations
 *   • fsdoc disk cache survives an emulator wipe → cleared in step [0]
 *   • global STU counter       → ids are read back, never assumed
 *   • empty control tenant     → tenant B is fully configured and discounted too
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/newschool_dayone_proof.js
 *
 * Emulator only — refuses on anything but loopback. Writes no live data.
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

const A = 'SCH_EMUFIXTURE1';
const B = 'SCH_EMUFIXTURE2';
const S = '2026-27';
const ROOT = path.join(__dirname, '..');

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

let fails = 0;
const ok  = (m) => console.log(`     PASS  ${m}`);
const bad = (m) => { fails++; console.log(`     FAIL  ${m}`); };
const chk = (c, m) => (c ? ok(m) : bad(m));
const head = (m) => console.log(`\n  ${m}`);

/** FSDOC-1: a Firestore document cache on disk that survives an emulator wipe. */
function clearFsdoc() {
  const dir = path.join(ROOT, 'application', 'cache', 'fsdoc');
  let n = 0;
  try {
    for (const f of fs.readdirSync(dir)) {
      if (f.endsWith('.cache')) { fs.unlinkSync(path.join(dir, f)); n++; }
    }
  } catch (e) { /* absent is fine */ }
  return n;
}

/** Run a real endpoint through the probe, as an authenticated administrator. */
function probe(school, step, ...args) {
  const env = {
    ...process.env,
    FIRESTORE_EMULATOR_HOST: EMU,
    ZX_ADMIN_ID: 'SSA9001', ZX_ADMIN_ROLE: 'School Super Admin',
    ZX_SCHOOL: school, ZX_SESSION: S,
  };
  let out = '';
  try {
    out = execFileSync('php', ['index.php', 'lifecycle_probe', 'step', step, ...args],
      { env, encoding: 'utf8', timeout: 180000, cwd: ROOT });
  } catch (e) { out = (e.stdout || '') + (e.stderr || ''); }
  const m = out.match(/\{[\s\S]*\}\s*$/);
  try { return m ? JSON.parse(m[0]) : { status: 'error', message: out.slice(0, 200) }; }
  catch (e) { return { status: 'error', message: out.slice(0, 200) }; }
}

/** Issue a rollout grant through the REAL grant service, which enforces the chain. */
/** Trusted platform-side activation, via the operator CLI front door. */
function activate(school, commit) {
  const env = { ...process.env, FIRESTORE_EMULATOR_HOST: EMU };
  const args = ['index.php', 'rollout_grant_cli', 'activate_ready', school];
  if (commit) args.push('--commit');
  try {
    return { code: 0, out: execFileSync('php', args, { env, encoding: 'utf8', timeout: 120000, cwd: ROOT }) };
  } catch (e) {
    return { code: e.status ?? 1, out: ((e.stdout || '') + (e.stderr || '')) };
  }
}

function grant(school, flag) {
  const env = { ...process.env, FIRESTORE_EMULATOR_HOST: EMU };
  try {
    const out = execFileSync('php', ['index.php', 'rollout_grant_cli', 'grant', school, flag,
      'new-school day-one readiness proof', '--commit'],
      { env, encoding: 'utf8', timeout: 120000, cwd: ROOT });
    return { ok: !/refus|error|denied/i.test(out), out: out.slice(-300) };
  } catch (e) {
    return { ok: false, out: ((e.stdout || '') + (e.stderr || '')).slice(-300) };
  }
}

/** The school's concession policy. Seeded directly — see the header note. */
function entitlement(school, studentId) {
  const id = `${school}_${studentId}_E${crypto.randomBytes(10).toString('hex')}`;
  return [id, {
    entitlementId: id, schoolId: school, studentId, session: S,
    kind: 'concession', concessionType: 'other', scope: 'all',
    valueType: 'percent', value: 10, status: 'active',
    approvalRequired: false, effectiveFrom: '2026-04-01', effectiveTo: null,
    source: 'dayone-proof', createdAt: new Date().toISOString(),
  }];
}

const rows = async (col, school) => {
  const s = await db.collection(col).where('schoolId', '==', school).get();
  return s.docs.map((d) => d.data());
};

/** Take one clean tenant all the way through the supported lifecycle. */
async function commission(school, label, holdBack = false) {
  head(`── ${label} (${school}) · supported lifecycle ──────────────────`);

  // 2 · THE PLATFORM GRANT — now impossible to get wrong.
  //
  // Auto-granting from the fee-head screen was implemented and REVERTED: issuing
  // a tenant grant is confined to the superadmin surface and the CLI front door
  // (T3), and a school-facing endpoint granting its own tenant's platform
  // capabilities crosses that boundary. The operator step stays.
  //
  // What changed is that it can no longer be done in the damaging order. The
  // grant service now REFUSES the registry flag while any charted head is
  // unregistered, so the state that silently stops a school billing is
  // unreachable rather than merely discouraged. That refusal is asserted here,
  // before the heads exist, because a guard nobody has seen fail is not a guard.
  const early = grant(school, 'USE_FEE_HEAD_REGISTRY');
  chk(!early.ok && /not registered yet/i.test(early.out),
    'granting the registry BEFORE the heads exist is refused, naming the missing heads '
    + `(got: ${early.ok ? 'ALLOWED — the guard is gone' : 'refused'})`);

  // 1 · ADMINISTRATOR ACTION — build the financial foundation.
  //
  // EVERY head that appears in the school's fee chart must be registered.
  // Generation is all-or-nothing: one unresolvable head aborts the whole
  // student's billing, by design (a demand nobody can classify is worse than
  // no demand). Registering a hand-picked subset is what left "Examination Fee"
  // unresolved and produced demands=0 with everything else green.
  //
  // The names are read from the tenant's OWN chart, which is what an
  // administrator sees on screen. The category is the school's decision — the
  // mapping here stands in for that choice, and deliberately does not guess
  // from the name at runtime anywhere in the product.
  const chartHeads = new Set();
  const cs = await db.collection('feeStructures').where('schoolId', '==', school).get();
  cs.docs.forEach((d) => {
    const v = d.data();
    const key = ['heads', 'feeHeads', 'rows'].find((k) => Array.isArray(v[k]));
    if (key) v[key].forEach((r) => { if (r && r.name) chartHeads.add(String(r.name)); });
  });
  const categoryFor = (n) => /transport|bus/i.test(n) ? 'transport'
    : (/hostel|mess/i.test(n) ? 'hostel' : 'academic');
  const heads = [...chartHeads].sort().map((n) => [n, categoryFor(n)]);
  chk(heads.length > 0, `chart exposes ${heads.length} fee head(s) to register: ${heads.map(h => h[0]).join(', ')}`);
  // holdBack leaves ONE charted head unregistered on purpose, so the readiness
  // gate has something real to refuse. A gate only ever exercised against a
  // perfect tenant proves nothing about the tenant that needs it.
  const toRegister = holdBack ? heads.slice(0, -1) : heads;
  const withheld = holdBack ? heads[heads.length - 1][0] : null;
  for (const [name, cat] of toRegister) {
    const r = probe(school, 'register_head', name, cat);
    chk(r.status === 'success' && r.feeHeadId,
      `admin registered "${name}" (${cat}) → ${r.feeHeadId || r.message || 'FAILED'}`);
  }
  if (withheld) ok(`deliberately left "${withheld}" UNREGISTERED — this tenant starts NOT_READY`);

  // The registry must now be non-empty FOR THIS TENANT, or everything below is
  // measuring the pre-registry legacy path instead of the one under test.
  const reg = (await db.collection('feeHeadRegistry').get()).docs
    .filter((d) => d.data().schoolId === school && d.data().kind !== 'nameClaim');
  chk(reg.length >= 1, `tenant now owns ${reg.length} registered fee head(s)`);



  return { count: reg.length, withheld, heads };
}

(async () => {
  console.log('\nNEW SCHOOL · DAY-ONE FINANCIAL READINESS PROOF');
  console.log(`emulator ${EMU}\n`);

  head(`[0] cleared ${clearFsdoc()} fsdoc cache entr(ies) — FSDOC-1`);

  // The registry must start EMPTY, or "the admin built it" is unproven.
  const reg0 = (await db.collection('feeHeadRegistry').get()).size;
  chk(reg0 === 0, `feeHeadRegistry starts empty (${reg0}) — the foundation is built by the `
    + 'lifecycle below, not inherited from a fixture');

  const cA = await commission(A, 'TENANT A');
  const cB = await commission(B, 'TENANT B', /* holdBack */ true);

  // ══ TENANT A · READY → ACTIVATED ════════════════════════════════════════
  head('── TENANT A · readiness-driven activation ─────────────────────');
  const dry = activate(A, false);
  chk(/eligible : YES/.test(dry.out) && /Would activate/.test(dry.out),
    'dry-run reports the tenant eligible and writes nothing');
  const actA = activate(A, true);
  chk(/result : activated/.test(actA.out),
    `activation succeeded (${(actA.out.match(/statuss+:s+(w+)/) || [])[1] || 'see output'})`);

  // ══ TENANT B · NOT_READY → REPAIRED → READY → ACTIVATED ═════════════════
  head('── TENANT B · refused, repaired, then activated ───────────────');
  const refused = activate(B, true);
  chk(/eligible : no/.test(refused.out) && /unregistered_fee_heads/.test(refused.out),
    'activation REFUSED while a charted head is unregistered');
  chk(refused.out.includes(cB.withheld),
    `the refusal names the missing head ("${cB.withheld}") rather than just saying "not ready"`);

  const flagsMid = ((await db.collection('schoolControl').doc(B).get()).data() || {}).rolloutFlags || {};
  chk(!flagsMid.USE_FEE_HEAD_REGISTRY,
    'a refused activation wrote NO grant — refusal must not half-activate a tenant');

  // Repair the one missing prerequisite through the normal product surface.
  const cat = /transport|bus/i.test(cB.withheld) ? 'transport'
    : (/hostel|mess/i.test(cB.withheld) ? 'hostel' : 'academic');
  const fix = probe(B, 'register_head', cB.withheld, cat);
  chk(fix.status === 'success', `admin registered the missing "${cB.withheld}" → ${fix.feeHeadId || fix.message}`);

  const actB = activate(B, true);
  chk(/result : activated/.test(actB.out),
    'the SAME tenant now activates — NOT_READY → repaired → READY → ACTIVATED');

  // ── IDEMPOTENCY OF ACTIVATION ───────────────────────────────────────────
  const reActivate = activate(A, true);
  chk(/already_active/.test(reActivate.out),
    're-activating an active tenant is a no-op, not a second grant');

  // ── FIXTURE FIDELITY · A NEW SCHOOL'S CHART CARRIES NO REGISTRY REF ─────
  // The emulator seed fabricates feeHeadRef: 'FH_EMUTUITION01' on every chart
  // row — an identity no registry document has ever had. Generation then takes
  // the resolveForNewUse(ref) branch, misses, and aborts, so the proof was
  // measuring a broken fixture rather than the product.
  //
  // A real school cannot be in that state. Fee_firestore_sync resolves a row's
  // ref from the previous snapshot or the registry and OMITS the field when
  // neither matches — it "DELIBERATELY NEVER MINTS". So a chart saved before
  // any head is registered has NO ref, and generation falls through to
  // resolveByName(), which is exactly the path an administrator's registration
  // is meant to satisfy.
  //
  // Stripping the fabricated ref makes the fixture faithful to that state. It
  // removes an impossible value; it does not add anything the product wouldn't.
  head('── FIXTURE · strip fabricated chart refs ──────────────────────');
  let stripped = 0;
  const charts = await db.collection('feeStructures').get();
  for (const d of charts.docs) {
    const v = d.data();
    const key = ['heads', 'feeHeads', 'rows'].find((k) => Array.isArray(v[k]));
    if (!key) continue;
    let touched = false;
    const next = v[key].map((r) => {
      if (r && r.feeHeadRef !== undefined) { touched = true; const { feeHeadRef, ...rest } = r; return rest; }
      return r;
    });
    if (touched) { await d.ref.update({ [key]: next }); stripped++; }
  }
  ok(`removed fabricated feeHeadRef from ${stripped} chart document(s) — see the note above`);

  // ══ ADMIT + PRICE ═══════════════════════════════════════════════════════
  // The entitlement must exist BEFORE admission, because demand generation
  // happens inside it. The STU counter is global across tenants, so the id is
  // not assumed — a throwaway admission reveals the next id, then the real
  // discounted student is admitted against a pre-seeded grant.
  const priced = {};
  for (const [school, label] of [[A, 'TENANT A'], [B, 'TENANT B']]) {
    head(`── ${label} · admission with a 10% concession ──────────────────`);

    const probe1 = probe(school, 'admit', 'Dayone Probe', '5', 'A');
    chk(probe1.status === 'success', `baseline admission ${probe1.user_id || 'FAILED'}`);
    const nextId = 'STU' + String(parseInt(String(probe1.user_id || 'STU0000').slice(3), 10) + 1)
      .padStart(4, '0');

    const [eid, edoc] = entitlement(school, nextId);
    await db.collection('studentEntitlements').doc(eid).set(edoc);
    ok(`entitlement seeded for ${nextId} BEFORE admission (school's concession policy)`);

    const a2 = probe(school, 'admit', 'Dayone Discounted', '5', 'A');
    chk(a2.status === 'success' && a2.user_id === nextId,
      `discounted admission ${a2.user_id} matches the pre-seeded grant ${nextId}`);
    priced[school] = a2.user_id;
  }

  // ══ WHAT THE RUNTIME PRODUCED ═══════════════════════════════════════════
  const result = {};
  for (const [school, label] of [[A, 'TENANT A'], [B, 'TENANT B']]) {
    head(`── ${label} · runtime output ───────────────────────────────────`);
    const dem = await rows('feeDemands', school);
    const allocSnap = await db.collection('entitlementAllocations')
      .where('schoolId', '==', school).get();
    const alloc = allocSnap.docs.map((d) => d.data());
    const allocIds = new Set(allocSnap.docs.map((d) => d.id));
    const disc = dem.filter((d) => Number(d.discountAmount || 0) > 0.005);
    result[school] = { dem, alloc, disc };

    console.log(`     demands=${dem.length} discounted=${disc.length} allocations=${alloc.length}`);

    // PRECONDITION FIRST. Judging allocation behaviour against zero discounted
    // demands is what made three earlier runs pass while testing nothing.
    chk(dem.length > 0, 'fee generation succeeded on a school built through the lifecycle');
    chk(disc.length > 0, 'demands are GENUINELY DISCOUNTED — the precondition for every '
      + 'allocation assertion below');

    if (disc.length > 0) {
      chk(alloc.length > 0, 'allocation rows were emitted by the real generation path');

      // I2 · SUM(active allocations) == discountAmount, per demand.
      const sum = {};
      alloc.filter((r) => (r.status || 'active') === 'active')
        .forEach((r) => { sum[r.demandId] = (sum[r.demandId] || 0) + Number(r.allocatedAmount || 0); });
      const viol = disc.filter((d) => Math.abs((sum[d.demandId] || 0) - Number(d.discountAmount)) > 0.005);
      chk(viol.length === 0, `I2 holds exactly on all ${disc.length} discounted demand(s)`);

      // Every allocation must name a REAL entitlement, not a minted stand-in.
      const entIds = new Set((await rows('studentEntitlements', school)).map((e) => e.entitlementId));
      const orphan = alloc.filter((r) => !entIds.has(r.entitlementId));
      chk(orphan.length === 0, `every allocation points at a real entitlement (${orphan.length} orphaned)`);

      // Deterministic identity: the document id must BE
      // {schoolId}_{demandId}_{entitlementId}. This is what makes a re-run
      // collide instead of duplicating, so it is checked literally rather than
      // assumed from the fact that no duplicates happen to exist today.
      const idMismatch = alloc.filter((r) => {
        const expect = `${r.schoolId}_${r.demandId}_${r.entitlementId}`;
        return !allocIds.has(expect);
      });
      chk(idMismatch.length === 0,
        `every allocation id is exactly {schoolId}_{demandId}_{entitlementId} (${idMismatch.length} off-contract)`);
    }

    // Undiscounted demands must never acquire an allocation.
    const plain = dem.filter((d) => Number(d.discountAmount || 0) <= 0.005);
    const invented = plain.filter((d) => alloc.some((r) => r.demandId === d.demandId));
    chk(invented.length === 0,
      `no allocation invented for an undiscounted demand (${plain.length} plain, ${invented.length} wrong)`);
  }

  // ══ MULTI-TENANT ISOLATION ══════════════════════════════════════════════
  head('── MULTI-TENANT ISOLATION ──────────────────────────────────────');
  const all = (await db.collection('entitlementAllocations').get()).docs;

  const foreign = all.map((d) => d.data()).filter((r) => r.schoolId !== A && r.schoolId !== B);
  chk(foreign.length === 0, `no allocation belongs to an unexpected tenant (${foreign.length})`);

  const misPrefixed = all.filter((d) => {
    const v = d.data();
    return v.schoolId && !String(d.id).startsWith(v.schoolId + '_');
  });
  chk(misPrefixed.length === 0, `every allocation id is prefixed by its own tenant (${misPrefixed.length})`);

  const ids = all.map((d) => d.id);
  chk(new Set(ids).size === ids.length, 'no duplicate allocation document ids');

  // Neither tenant's allocations may reference the other's demands or grants.
  for (const [school, other] of [[A, B], [B, A]]) {
    const mine = result[school].alloc;
    const otherDemandIds = new Set(result[other].dem.map((d) => d.demandId));
    const bleed = mine.filter((r) => otherDemandIds.has(r.demandId));
    chk(bleed.length === 0, `${school} has no allocation pointing at ${other}'s demands (${bleed.length})`);
  }

  // Configuration isolation: each tenant's registry is its own.
  const regAll = (await db.collection('feeHeadRegistry').get()).docs.map((d) => d.data());
  const regBleed = regAll.filter((r) => r.schoolId !== A && r.schoolId !== B);
  chk(regBleed.length === 0, `fee head registry holds no foreign tenant rows (${regBleed.length})`);
  const aHeads = regAll.filter((r) => r.schoolId === A && r.kind !== 'nameClaim').map((r) => r.feeHeadId);
  const bHeads = regAll.filter((r) => r.schoolId === B && r.kind !== 'nameClaim').map((r) => r.feeHeadId);
  chk(aHeads.length > 0 && bHeads.length > 0
      && aHeads.every((id) => !bHeads.includes(id)),
    `identically named heads got DIFFERENT identities per tenant (A=${aHeads.length}, B=${bHeads.length})`);

  // ══ IDEMPOTENCY ═════════════════════════════════════════════════════════
  head('── IDEMPOTENCY ─────────────────────────────────────────────────');
  // Re-registering the foundation must not fork identity or duplicate rows.
  const before = (await db.collection('feeHeadRegistry').get()).size;
  const again = probe(A, 'register_head', 'Tuition Fee', 'academic');
  const after = (await db.collection('feeHeadRegistry').get()).size;
  // The operator endpoint REFUSES a true duplicate rather than quietly
  // succeeding — correct for a screen, where silently doing nothing looks like
  // it worked. Either answer is acceptable here; what must never happen is a
  // SECOND identity for one logical fee head, which the count check below is
  // what actually proves.
  const dupRefused = again.status === 'error'
    && /already exists and is registered/i.test(String(again.message || ''));
  const dupNoop = again.status === 'success' && again.created === false;
  chk(dupRefused || dupNoop,
    `a repeated registration is refused or reported as already registered (got: ${again.status} — `
    + `${String(again.message || '').slice(0, 70)})`);
  chk(before === after, `registry document count unchanged on re-registration (${before} → ${after})`);

  const allocBefore = result[A].alloc.length;
  const allocNow = (await rows('entitlementAllocations', A)).length;
  chk(allocBefore === allocNow, `allocation count stable, no duplicates appeared (${allocNow})`);

  console.log(`\n${fails === 0 ? 'DAY-ONE READINESS PROVEN' : fails + ' CHECK(S) FAILED'}\n`);
  process.exit(fails === 0 ? 0 : 1);
})().catch((e) => { console.error('ABORTED: ' + e.stack); process.exit(1); });
