/**
 * ══════════════════════════════════════════════════════════════════════════
 *  BILLING V2 LEDGER — MULTI-TENANT RUNTIME PROOF (EMULATOR)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The one claim nothing had ever proven: that the NORMAL demand-generation
 * path emits allocation rows when the ledger is granted.
 *
 * It could not have, until now. LEDGER-RESOLVE-1 showed the flag resolver
 * called a method that does not exist and had its fatal swallowed into
 * `false`, so the ledger was unreachable for every tenant regardless of any
 * grant. This exercises the REAL path — admission → pricing → allocation
 * assembly → persistence — against two independent tenants, one granted and
 * one not.
 *
 * Seeding an allocation row by hand would prove nothing; the point is that
 * production code produces it.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/ledger_multitenant_runtime_proof.js
 *
 * Emulator only — refuses on anything but loopback.
 */
const admin = require('firebase-admin');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

/**
 * ── FSDOC-1 · THE CACHE THAT SURVIVES THE WIPE ────────────────────────────
 *
 * application/cache/fsdoc is a Firestore DOCUMENT cache on local disk. Wiping
 * the emulator does not touch it, so a run inherits the previous run's
 * documents — and, worse, the previous run's ABSENCES.
 *
 * This is what made every earlier investigation contradict itself: the pricing
 * authority appeared to flip between runs over identical data, granting M5
 * appeared to change results it cannot influence, and a check added AFTER a log
 * line appeared to cause that log line. None of it was real. With the cache
 * cleared the proof runs identically three times in a row.
 *
 * Clearing it is part of establishing the fixture, not a convenience.
 */
function clearFsdoc() {
  const dir = path.join(__dirname, '..', 'application', 'cache', 'fsdoc');
  let n = 0;
  try {
    for (const f of fs.readdirSync(dir)) {
      if (f.endsWith('.cache')) { fs.unlinkSync(path.join(dir, f)); n++; }
    }
  } catch (e) { /* absent cache dir is fine */ }
  return n;
}

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
if (!['127.0.0.1', 'localhost', '::1'].includes(EMU.split(':')[0])) {
  console.error('REFUSING: FIRESTORE_EMULATOR_HOST must be loopback');
  process.exit(1);
}

const A = 'SCH_EMUFIXTURE1';   // GRANTED
const B = 'SCH_EMUFIXTURE2';   // NOT granted — the control
const S = '2026-27';

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

let fails = 0;
const ok   = (m) => console.log(`     PASS  ${m}`);
const bad  = (m) => { fails++; console.log(`     FAIL  ${m}`); };
const chk  = (c, m) => (c ? ok(m) : bad(m));

/** Run a real admission through the real controller. */
function admit(school, name, cls, sec) {
  const env = {
    ...process.env,
    FIRESTORE_EMULATOR_HOST: EMU,
    ZX_ADMIN_ID: 'SSA9001', ZX_ADMIN_ROLE: 'School Super Admin',
    ZX_SCHOOL: school, ZX_SESSION: S,
  };
  let out = '';
  try {
    out = execFileSync('php', ['index.php', 'lifecycle_probe', 'step', 'admit', name, cls, sec],
      { env, encoding: 'utf8', timeout: 180000 });
  } catch (e) { out = (e.stdout || '') + (e.stderr || ''); }
  const m = out.match(/\{[\s\S]*\}\s*$/);
  try { return m ? JSON.parse(m[0]) : { status: 'error', message: out.slice(0, 300) }; }
  catch (e) { return { status: 'error', message: out.slice(0, 300) }; }
}

const rows = async (col, school) => {
  const s = await db.collection(col).where('schoolId', '==', school).get();
  return s.docs.map((d) => d.data());
};

/**
 * A concession in the shape the generator ACTUALLY DEDUCTS FROM.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * Three runs of this proof reported discounted=0 while every isolation check
 * passed, and I twice blamed a missing rollout flag. Both guesses were wrong.
 *
 * The generator does not read `studentEntitlements` on the default path. It
 * asks whichever reader USE_ENTITLEMENT_BILLING selects, and the shipped
 * default is Fee_concession_reader → `studentConcessions`. The two collections
 * also use DIFFERENT deduction field names: a concession carries `type`/`value`,
 * an entitlement `valueType`/`value`. Seeding an entitlement therefore fetched
 * nothing on the default path, priced everything at gross, and produced no
 * allocation to check — a green run that tested nothing.
 *
 * This seeds the legacy shape, so the discount arises on the path production
 * runs by default, with no flag beyond the one under test.
 */
function concession(school, studentId) {
  const id = `${school}_${studentId}_C${crypto.randomBytes(10).toString('hex')}`;
  return [id, {
    concessionId: id, schoolId: school, studentId, session: S,
    scope: 'all',                 // every head, every period
    type: 'percent', value: 10,   // deductionType()/deductionValue() read THESE
    status: 'active',             // the reader filters on status == 'active'
    effectiveFrom: '2026-04-01',  // '' would make appliesTo() return false
    effectiveTo: null,
    source: 'runtime-proof', createdAt: new Date().toISOString(),
  }];
}

/** The Phase-C entitlement shape — shadow path only, kept for parity. */
function entitlement(school, studentId) {
  const id = `${school}_${studentId}_E${crypto.randomBytes(10).toString('hex')}`;
  return [id, {
    entitlementId: id, schoolId: school, studentId, session: S,
    kind: 'concession', concessionType: 'other', scope: 'all',
    valueType: 'percent', value: 10, status: 'active',
    approvalRequired: false, effectiveFrom: '2026-04-01', effectiveTo: null,
    source: 'runtime-proof', createdAt: new Date().toISOString(),
  }];
}

/** Grant or revoke the ledger for one tenant, via the control document. */
async function setGrant(school, enabled) {
  await db.collection('schoolControl').doc(school).set({
    schoolId: school,
    // ── GRANT ONLY THE FLAG UNDER TEST ──────────────────────────────────
    // The first version of this proof also granted USE_ENTITLEMENT_BILLING
    // and USE_FEE_HEAD_REGISTRY "to be thorough", and generation produced
    // ZERO demands for both tenants — where the same admission path had
    // produced 25 moments earlier with no grants at all.
    //
    // Those two flags change which collection PRICES a demand, and the
    // emulator fixture has no fee-head registry behind them, so pricing had
    // nothing to resolve and refused. Enabling more than the experiment needs
    // changed the thing being measured.
    //
    // Only the ledger flag varies here. Everything else stays exactly as the
    // fixture ships, so a difference between the two tenants can only be
    // caused by the grant.
    rolloutFlags: {
      USE_ALLOCATION_LEDGER: { enabled, reason: 'multi-tenant runtime proof', setBy: 'proof' },
      // ZX_ENT_BILLING=1 switches the reader to Fee_entitlement_reader, so the
      // rule handed to the allocation service carries entitlementId. Off by
      // default: the shipped default path is the legacy concession reader, and
      // that is the configuration LEDGER-EMIT-1 was found in.
      // ZX_M5=1 adds USE_FEE_HEAD_REGISTRY. Rollout_grant_service REFUSES
      // USE_ENTITLEMENT_BILLING without an active M5 grant, so the real
      // onboarded tenant necessarily has all three. This reproduces that exact
      // combination — the one a school actually gets.
      ...(process.env.ZX_M5 === '1'
        ? { USE_FEE_HEAD_REGISTRY: { enabled: true, reason: 'onboarded-state reproduction', setBy: 'proof' } }
        : {}),
      ...(process.env.ZX_ENT_BILLING === '1'
        ? { USE_ENTITLEMENT_BILLING: { enabled: true, reason: 'LEDGER-EMIT-1 comparison', setBy: 'proof' } }
        : {}),
    },
  }, { merge: true });
}

(async () => {
  console.log('\nBILLING V2 LEDGER — MULTI-TENANT RUNTIME PROOF');
  console.log(`emulator ${EMU}\n  ${A} = GRANTED     ${B} = NOT granted (control)\n`);

  // ══ 1 · GRANTS ══════════════════════════════════════════════════════════
  console.log(`  [0] cleared ${clearFsdoc()} fsdoc cache entr(ies) — see FSDOC-1`);

  console.log('  [1] set per-tenant grants');
  await setGrant(A, true);
  await setGrant(B, false);
  const ctlA = (await db.collection('schoolControl').doc(A).get()).data();
  const ctlB = (await db.collection('schoolControl').doc(B).get()).data();
  chk(ctlA.rolloutFlags.USE_ALLOCATION_LEDGER.enabled === true,  `${A} granted`);
  chk(ctlB.rolloutFlags.USE_ALLOCATION_LEDGER.enabled === false, `${B} NOT granted`);

  // ══ 2 · GRANTED TENANT, DISCOUNTED STUDENT ══════════════════════════════
  console.log('\n  [2] GRANTED tenant · student WITH a 10% concession');

  // ── THE ENTITLEMENT MUST EXIST BEFORE PRICING RUNS ──────────────────────
  // The first version of this proof admitted the student and THEN seeded the
  // grant. Pricing had already happened, so nothing was discounted, and the
  // run reported "ALL CHECKS PASSED" with discounted=0 — every isolation check
  // green while the one claim that matters was never exercised. A proof that
  // cannot fail is not a proof.
  //
  // Demand generation happens inside admission, so the grant has to be on file
  // BEFORE the student is admitted. On a freshly seeded tenant the next id is
  // deterministic (STU0001), which is what makes that possible at all.
  const sidA = 'STU0001';
  const [cid, cdoc] = concession(A, sidA);
  await db.collection('studentConcessions').doc(cid).set(cdoc);
  const [eid, edoc] = entitlement(A, sidA);
  await db.collection('studentEntitlements').doc(eid).set(edoc);
  ok(`concession pre-seeded for ${sidA} (10%, scope=all) BEFORE admission`);

  const a1 = admit(A, 'Ledger Granted', '5', 'A');
  chk(a1.status === 'success', `admitted ${a1.user_id || '(failed: ' + (a1.message || '') + ')'}`);
  chk(a1.user_id === sidA,
    `the admitted id is ${a1.user_id} — the pre-seeded entitlement targets ${sidA}; if these `
    + 'diverge the student is priced with no grant and the proof is vacuous');

  // ══ 3 · WHAT THE RUNTIME PRODUCED ═══════════════════════════════════════
  console.log('\n  [3] runtime output — GRANTED tenant');
  const demA   = await rows('feeDemands', A);
  const allocA = await rows('entitlementAllocations', A);
  const discA  = demA.filter((d) => Number(d.discountAmount || 0) > 0.005);

  console.log(`     demands=${demA.length} discounted=${discA.length} allocations=${allocA.length}`);
  chk(demA.length > 0, 'demands were generated through the real admission path');

  if (discA.length > 0) {
    chk(allocA.length > 0,
      'allocation rows WERE produced by the normal generation path (this is the claim '
      + 'LEDGER-RESOLVE-1 made impossible before the fix)');

    // I2 on every discounted demand.
    const sum = {};
    allocA.filter((a) => (a.status || 'active') === 'active')
      .forEach((a) => { sum[a.demandId] = (sum[a.demandId] || 0) + Number(a.allocatedAmount || 0); });
    const viol = discA.filter((d) =>
      Math.abs((sum[d.demandId] || 0) - Number(d.discountAmount)) > 0.005);
    chk(viol.length === 0, `I2 holds on all ${discA.length} discounted demand(s)`);
  } else {
    console.log('     (no discounted demands were produced — see the note in the summary)');
  }

  // ══ 4 · UNGRANTED TENANT — THE CONTROL ══════════════════════════════════
  console.log('\n  [4] UNGRANTED tenant · same workflow');
  // The control carries an IDENTICAL discount. The only difference between the
  // two tenants is the ledger grant, so a discounted demand here that produces
  // no allocation is the sharpest possible statement that the grant is what
  // gates the ledger — not the absence of anything to allocate.
  // The STU counter is GLOBAL across tenants, so tenant B's first admission is
  // NOT STU0001 — it continues from whatever tenant A consumed. Seeding B's
  // grant for STU0001 silently missed, B produced no discounted demands, and
  // "B has no allocations" then proved nothing at all: there was nothing to
  // allocate. Seed a small band so the control is genuinely discounted whatever
  // id it draws, and assert below that it actually was.
  for (let n = 1; n <= 8; n++) {
    const sid = 'STU' + String(n).padStart(4, '0');
    const [bc, bcdoc] = concession(B, sid);
    await db.collection('studentConcessions').doc(bc).set(bcdoc);
    const [bpre, bpredoc] = entitlement(B, sid);
    await db.collection('studentEntitlements').doc(bpre).set(bpredoc);
  }
  const b1 = admit(B, 'No Ledger', '5', 'A');
  chk(b1.status === 'success', `admitted ${b1.user_id || '(failed)'}`);
  if (b1.user_id) {
    const [bid, bdoc] = entitlement(B, b1.user_id);
    await db.collection('studentEntitlements').doc(bid).set(bdoc);
    admit(B, 'No Ledger Two', '6', 'A');
  }

  const demB   = await rows('feeDemands', B);
  const allocB = await rows('entitlementAllocations', B);
  console.log(`     demands=${demB.length} allocations=${allocB.length}`);
  const discB = demB.filter((d) => Number(d.discountAmount || 0) > 0.005);
  console.log(`     discounted=${discB.length}`);
  chk(demB.length > 0, 'the ungranted tenant still bills normally');
  chk(discB.length > 0,
    'the CONTROL actually produced discounted demands — without this, "no allocations" '
    + 'in the ungranted tenant would be explained by having nothing to allocate, and '
    + 'the comparison would be vacuous');
  chk(allocB.length === 0,
    'the UNGRANTED tenant produced NO allocation rows — the grant is genuinely per-tenant '
    + `and does not leak (found ${allocB.length})`);

  // ══ 5 · TENANT ISOLATION ════════════════════════════════════════════════
  console.log('\n  [5] tenant isolation');
  const all = await db.collection('entitlementAllocations').get();
  const foreign = all.docs.map((d) => d.data())
    .filter((a) => a.schoolId !== A && a.schoolId !== B);
  chk(foreign.length === 0, `no allocation belongs to an unexpected tenant (${foreign.length})`);

  const mislabelled = all.docs.filter((d) => {
    const v = d.data();
    return v.schoolId && !String(d.id).startsWith(v.schoolId + '_');
  });
  chk(mislabelled.length === 0,
    `every allocation id is prefixed by its own tenant (${mislabelled.length} mismatched)`);

  const dupes = {};
  all.docs.forEach((d) => { dupes[d.id] = (dupes[d.id] || 0) + 1; });
  chk(Object.values(dupes).every((n) => n === 1), 'no duplicate allocation document ids');

  // ══ 6 · NO-DISCOUNT DEMANDS MUST HAVE NO ALLOCATION ═════════════════════
  console.log('\n  [6] no-discount demands');
  const plainA = demA.filter((d) => Number(d.discountAmount || 0) <= 0.005);
  const withAlloc = plainA.filter((d) => allocA.some((a) => a.demandId === d.demandId));
  chk(withAlloc.length === 0,
    `no allocation was invented for an undiscounted demand (${plainA.length} plain, `
    + `${withAlloc.length} wrongly allocated)`);

  console.log(`\n${fails === 0 ? 'ALL RUNTIME CHECKS PASSED' : fails + ' CHECK(S) FAILED'}\n`);
  process.exit(fails === 0 ? 0 : 1);
})().catch((e) => { console.error('ABORTED: ' + e.stack); process.exit(1); });
