/**
 * ══════════════════════════════════════════════════════════════════════════
 *  SVC-1 · A SERVICE CONCESSION THAT IS SILENTLY NOT APPLIED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * THE GAP, in the product's own words (Fee_lifecycle, the flag-off branch):
 *
 *   "Families can request transport / hostel / meals through
 *    Entitlement_requests, and Entitlement_service records them as ACTIVE
 *    regardless of this flag. So a school can accumulate approved service
 *    concessions and keep billing every one of them at full fare, with
 *    nothing anywhere reporting it."
 *
 * For a brand-new school that is a correctness gap, not a migration posture:
 * the school's admin approves a transport concession, the UI accepts it, and
 * the family is still charged the full fare. Silently.
 *
 * ── WHAT THIS PROVES, IN ORDER ───────────────────────────────────────────
 *   A  flag OFF  → the charge is billed GROSS despite an active, correctly
 *                  scoped entitlement. The gap, reproduced rather than argued.
 *   B  flag ON   → the same charge is DISCOUNTED, by the exact expected amount.
 *   C  I3 holds  → a blanket scope:'all' concession still CANNOT touch a
 *                  service head. Enabling Phase D must not become a licence to
 *                  discount transport with a tuition concession.
 *
 * The flag is turned on PER TENANT through the real grant surface, not by
 * editing config: that keeps the proof honest about which mechanism did the
 * work, and leaves the global default decision to the evidence.
 *
 * Every charge is raised through fee_management/raise_student_charge — a real
 * admin endpoint that routes to Fee_lifecycle::createModuleFee(), the same
 * canonical path Transport and Hostel use. Nothing here writes a demand.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/service_entitlement_proof.js
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
const HEAD = 'Transport Fee';
const FARE = 1000;

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
  try { return m ? JSON.parse(m[0]) : { status: 'error', message: out.slice(0, 250) }; }
  catch (e) { return { status: 'error', message: out.slice(0, 250) }; }
}

/**
 * Set Phase D for THIS tenant only, through the real grant front door.
 *
 * Both directions matter now. Phase D ships ON as of 2026-08-30, so case A can
 * no longer reproduce the gap by "leaving the flag alone" — it has to REVOKE
 * for this tenant. That is a better test than the original: it exercises the
 * per-tenant kill switch, which is the only way to roll the capability back
 * for one school without touching the global default.
 */
function setPhaseD(on) {
  const env = { ...process.env, FIRESTORE_EMULATOR_HOST: EMU };
  // HOLD, not revoke: revoke deletes the override and falls back to the
  // global default, which is now ON. Only an explicit per-tenant false
  // disables a capability for one school.
  const verb = on ? 'grant' : 'hold';
  try {
    const out = execFileSync('php', ['index.php', 'rollout_grant_cli', verb, S,
      'USE_ENTITLEMENT_SERVICE_PRICING', 'SVC-1 service entitlement proof', '--commit'],
      { env, encoding: 'utf8', timeout: 120000, cwd: ROOT });
    return { ok: !/refus|denied/i.test(out), out };
  } catch (e) { return { ok: false, out: ((e.stdout || '') + (e.stderr || '')) }; }
}

/** A service-scoped entitlement — the only shape I3 permits against a fare. */
function serviceEntitlement(studentId, scope) {
  const id = `${S}_${studentId}_E${crypto.randomBytes(10).toString('hex')}`;
  return [id, {
    entitlementId: id, schoolId: S, studentId, session: SESSION,
    kind: 'concession', concessionType: 'other', scope,
    valueType: 'percent', value: 25, status: 'active',
    approvalRequired: false, effectiveFrom: '2026-04-01', effectiveTo: null,
    source: 'svc1-proof', createdAt: new Date().toISOString(),
  }];
}

/**
 * The ONE demand a module-fee call produced, by its returned id.
 *
 * The first version of this summed every transport-ish demand the student held
 * — which included the TWELVE monthly Transport Fee rows the admission itself
 * generates from the class fee chart. gross came out at 4600 instead of 1000
 * and every assertion was measuring the chart, not the fare under test.
 */
async function demandById(demandId) {
  const s = await db.collection('feeDemands')
    .where('schoolId', '==', S).where('demandId', '==', demandId).get();
  if (s.empty) {
    const alt = await db.collection('feeDemands').doc(`${S}_${demandId}`).get();
    return alt.exists ? alt.data() : null;
  }
  return s.docs[0].data();
}

(async () => {
  console.log('\nSVC-1 · SERVICE ENTITLEMENT PRICING (PHASE D)');
  console.log(`emulator ${EMU}\n`);

  head(`[0] cleared ${clearFsdoc()} fsdoc entr(ies)`);

  // The school must own a REGISTERED service head, or Student_charge_service
  // refuses by design (M5: no runtime minting of income heads).
  const reg = probe('register_head', HEAD, 'transport');
  chk(reg.status === 'success', `admin registered "${HEAD}" (transport) → ${reg.feeHeadId || reg.message}`);

  // Phase D now ships ON, so the gap must be recreated by REVOKING it for this
  // tenant. Case A therefore doubles as proof that the per-tenant kill switch
  // genuinely disables the capability.
  const revoked = setPhaseD(false);
  // schoolControl sits in Firestore_rest_client's CROSS-REQUEST config cache
  // (300s TTL on disk). The revoke happens in a separate CLI process, so the
  // next process would otherwise read the pre-revoke document and the kill
  // switch would look broken when it is not.
  clearFsdoc();
  chk(revoked.ok, 'Phase D REVOKED for this tenant — case A measures true flag-off behaviour');

  // ══ A · FLAG OFF — THE GAP ══════════════════════════════════════════════
  head('── [A] flag OFF · an active transport concession must be IGNORED ──');
  const a1 = probe('admit', 'Svc Before', '5', 'A');
  chk(a1.status === 'success', `admitted ${a1.user_id || a1.message}`);
  const sA = a1.user_id;

  const [eA, dA] = serviceEntitlement(sA, 'category:transport');
  await db.collection('studentEntitlements').doc(eA).set(dA);
  ok(`25% transport concession recorded ACTIVE for ${sA} (scope=category:transport)`);
  clearFsdoc();

  const cA = probe('module_fee', sA, 'Transport', HEAD, String(FARE));
  chk(cA.status === 'success', `transport fare raised ${cA.demand_id || cA.message}`);

  const dA2 = await demandById(cA.demand_id);
  chk(dA2 !== null, 'the fare demand is readable');
  const grossA = Number((dA2 || {}).grossAmount ?? FARE);
  const netA   = Number((dA2 || {}).netAmount || 0);
  const discA  = Number((dA2 || {}).discountAmount || 0);
  console.log(`     gross=${grossA} net=${netA} discount=${discA}`);
  chk(Math.abs(netA - FARE) < 0.005 && discA < 0.005,
    `with the capability revoked, the fare is billed in FULL despite an active concession — `
    + `SVC-1 reproduced, and the per-tenant kill switch works (net ${netA}, discount ${discA})`);

  // ══ B · FLAG ON — THE FIX ═══════════════════════════════════════════════
  head('── [B] flag ON (per-tenant grant) · the same concession must apply ──');
  const g = setPhaseD(true);
  clearFsdoc();   // same reason as the revoke above
  chk(g.ok, `USE_ENTITLEMENT_SERVICE_PRICING re-granted to ${S}`);
  clearFsdoc();

  const b1 = probe('admit', 'Svc After', '5', 'A');
  const sB = b1.user_id;
  chk(b1.status === 'success', `admitted ${sB || b1.message}`);
  const [eB, dB] = serviceEntitlement(sB, 'category:transport');
  await db.collection('studentEntitlements').doc(eB).set(dB);
  clearFsdoc();

  const cB = probe('module_fee', sB, 'Transport', HEAD, String(FARE));
  chk(cB.status === 'success', `transport fare raised ${cB.demand_id || cB.message}`);

  const dB2 = await demandById(cB.demand_id);
  const netB  = Number((dB2 || {}).netAmount || 0);
  const discB = Number((dB2 || {}).discountAmount || 0);
  console.log(`     net=${netB} discount=${discB}  (expected net 750, discount 250)`);
  chk(Math.abs(discB - FARE * 0.25) < 0.005,
    `the concession DEDUCTED exactly 25% (${discB} of ${FARE})`);
  chk(Math.abs(netB - FARE * 0.75) < 0.005,
    `net payable is the discounted fare (${netB})`);
  chk(netB < grossA,
    'the identical charge is cheaper than under case A — the flag is what changed the money');

  // ══ C · I3 · A BLANKET CONCESSION MUST STILL NOT TOUCH A FARE ═══════════
  head('── [C] I3 · scope:all must NOT discount a service head ──────────');
  const c1 = probe('admit', 'Svc Blanket', '5', 'A');
  const sC = c1.user_id;
  chk(c1.status === 'success', `admitted ${sC || c1.message}`);
  const [eC, dC] = serviceEntitlement(sC, 'all');
  await db.collection('studentEntitlements').doc(eC).set(dC);
  clearFsdoc();

  const cC = probe('module_fee', sC, 'Transport', HEAD, String(FARE));
  chk(cC.status === 'success', `transport fare raised ${cC.demand_id || cC.message}`);

  const dC2 = await demandById(cC.demand_id);
  const netC  = Number((dC2 || {}).netAmount || 0);
  const discC = Number((dC2 || {}).discountAmount || 0);
  console.log(`     net=${netC} discount=${discC}  (expected net 1000, discount 0)`);
  chk(discC < 0.005 && Math.abs(netC - FARE) < 0.005,
    'a blanket scope:all concession did NOT touch the fare — invariant I3 holds with Phase D ON');

  console.log(`\n${fails === 0 ? 'SVC-1 PROVEN: gap reproduced, fix verified, I3 intact' : fails + ' CHECK(S) FAILED'}\n`);
  process.exit(fails === 0 ? 0 : 1);
})().catch((e) => { console.error('ABORTED: ' + e.stack); process.exit(1); });
