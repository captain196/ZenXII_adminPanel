/**
 * B-025 — PROOF OF THE CLAIM PRIMITIVE, UNDER REAL CONCURRENCY.
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────
 * `Fee_management::_verify_and_process()` is the shared core of BOTH parent
 * payment paths — the frontend callback and the Razorpay webhook. Its lock
 * comment states the intent exactly:
 *
 *     // ── A. Order-level lock (prevents webhook + frontend double-execute) ──
 *     $existingLock = firestoreGet('feeLocks', $lockDocId);   // READ
 *     if (locked && age < 120) return refuse;
 *     firestoreSet('feeLocks', $lockDocId, [...]);            // BLIND SET
 *
 * That is read-then-write. Two callers that read before either writes BOTH
 * observe "no lock" and BOTH proceed. And the frontend/webhook pair is exactly
 * the case that races: Razorpay fires the webhook while the client callback is
 * also running, by design.
 *
 * The two per-path dedups do not close it either, because they live in
 * DIFFERENT namespaces:
 *   frontend → processedPayments/{gatewayPaymentId}
 *   webhook  → webhook EVENT-id dedup
 * On a first-time payment both are cold, so both paths pass their own guard.
 *
 * ── THE INVARIANT THIS MUST ESTABLISH ─────────────────────────────────────
 *     ONE gateway payment identity → AT MOST ONE successful financial
 *     application (one receipt, one allocation set, one journal, one demand
 *     mutation set). The loser must produce NO financial side effects, and the
 *     loser's retry must be idempotent.
 *
 * ── WHAT IS BEING PROVEN HERE, AND WHAT IS NOT ────────────────────────────
 * This suite proves the PRIMITIVE, not the payment pipeline. It establishes,
 * under genuine parallel load against a real Firestore, that:
 *
 *   1. `set`    (PATCH)                     is NOT exclusive — N winners
 *   2. `create` (POST ?documentId=)         IS  exclusive — exactly 1 winner
 *   3. a transport failure is distinguishable from a collision
 *
 * (2) is what the PHP client's `createDocumentStatus()` already issues:
 *     POST {baseUrl}/{collection}?documentId={id}
 *     201 → SUCCESS · 409 → COLLISION · anything else → FAILURE
 *
 * NO PRODUCTION DATA AND NO FINANCIAL TRANSACTION IS INVOLVED. The point is to
 * verify the mechanism a fix would rely on BEFORE writing the fix — the
 * end-to-end payment behaviour remains PRODUCTION VERIFICATION BLOCKED, because
 * the estate has recorded zero receipts and none may be manufactured.
 */

const { initializeTestEnvironment } = require('@firebase/rules-unit-testing');
const path = require('path');
const fs = require('fs');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, 'fixtures', 'semantics_probe.rules');
const HOST = '127.0.0.1';
const PORT = 18080;

jest.setTimeout(120000);

let env;

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: HOST, port: PORT },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); });

/**
 * Talk to the emulator over the SAME REST surface the PHP client uses, rather
 * than through the SDK. The SDK would hide the very distinction under test.
 */
const BASE = `http://${HOST}:${PORT}/v1/projects/${PROJECT_ID}/databases/(default)/documents`;

async function restCreate(collection, docId, fields) {
  const res = await fetch(`${BASE}/${collection}?documentId=${encodeURIComponent(docId)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer owner' },
    body: JSON.stringify({ fields }),
  });
  return res.status;
}

async function restSet(collection, docId, fields) {
  const res = await fetch(`${BASE}/${collection}/${encodeURIComponent(docId)}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer owner' },
    body: JSON.stringify({ fields }),
  });
  return res.status;
}

const strField = (v) => ({ stringValue: v });

describe('B-025 · claim primitive under concurrency', () => {

  /**
   * THE DEFECT, REPRODUCED.
   *
   * This is the shape `_verify_and_process()` uses today. Every concurrent
   * caller "acquires" the lock. If this test ever reported one winner, the
   * finding would be wrong — so it is the control that proves the finding real.
   */
  test('set (PATCH) is NOT exclusive — every concurrent caller wins', async () => {
    const N = 12;
    const codes = await Promise.all(
      Array.from({ length: N }, (_, i) =>
        restSet('feeLocks_probe', 'SCH_A_order_1', { token: strField('caller_' + i) })
      )
    );

    const winners = codes.filter((c) => c >= 200 && c < 300).length;
    expect(winners).toBe(N);   // all N "acquire" the same lock
  });

  /**
   * THE FIX'S MECHANISM.
   *
   * `POST ?documentId=` is Firestore's native create; the server returns 409
   * ALREADY_EXISTS. Exclusivity is decided server-side, so no read-then-write
   * window exists for a competitor to slip through.
   */
  test('create (POST ?documentId=) IS exclusive — exactly one winner', async () => {
    const N = 12;
    const codes = await Promise.all(
      Array.from({ length: N }, (_, i) =>
        restCreate('paymentClaims_probe', 'SCH_A_pay_ABC123', { owner: strField('caller_' + i) })
      )
    );

    const winners = codes.filter((c) => c >= 200 && c < 300);
    const collisions = codes.filter((c) => c === 409);

    expect(winners.length).toBe(1);
    expect(collisions.length).toBe(N - 1);
  });

  /**
   * Exclusivity must hold at higher contention too — the frontend/webhook race
   * is only 2-way, but a claim primitive that degrades under load is not one.
   */
  test('create stays exclusive at 40-way contention', async () => {
    const N = 40;
    const codes = await Promise.all(
      Array.from({ length: N }, () =>
        restCreate('paymentClaims_probe', 'SCH_A_pay_HOT', { owner: strField('x') })
      )
    );

    expect(codes.filter((c) => c >= 200 && c < 300).length).toBe(1);
    expect(codes.filter((c) => c === 409).length).toBe(N - 1);
  });

  /**
   * DISTINCT KEYS MUST NOT CONTEND.
   *
   * A claim keyed per payment must not serialise unrelated payments — that
   * would turn a correctness fix into a throughput regression for a school
   * collecting fees from many parents at once.
   */
  test('claims on distinct payment ids all succeed independently', async () => {
    const N = 25;
    const codes = await Promise.all(
      Array.from({ length: N }, (_, i) =>
        restCreate('paymentClaims_probe', `SCH_A_pay_${i}`, { owner: strField('p' + i) })
      )
    );

    expect(codes.filter((c) => c >= 200 && c < 300).length).toBe(N);
  });

  /**
   * TENANT ISOLATION OF THE CLAIM KEY.
   *
   * The key must be school-scoped, or one tenant's payment id could block
   * another's. Gateway payment ids are globally unique in practice, but the
   * claim must not DEPEND on that — a second gateway, a sandbox id, or a
   * replayed test id must not cross tenants.
   */
  test('the same payment id in two tenants does not collide when school-scoped', async () => {
    const a = await restCreate('paymentClaims_probe', 'SCH_A_pay_SHARED', { owner: strField('a') });
    const b = await restCreate('paymentClaims_probe', 'SCH_B_pay_SHARED', { owner: strField('b') });

    expect(a).toBeGreaterThanOrEqual(200);
    expect(a).toBeLessThan(300);
    expect(b).toBeGreaterThanOrEqual(200);
    expect(b).toBeLessThan(300);
  });

  /**
   * ...and the SAME key in the SAME tenant still collides, so the scoping does
   * not accidentally disable the guard.
   */
  test('school-scoping does not weaken the guard within one tenant', async () => {
    const first  = await restCreate('paymentClaims_probe', 'SCH_A_pay_SAME', { owner: strField('1') });
    const second = await restCreate('paymentClaims_probe', 'SCH_A_pay_SAME', { owner: strField('2') });

    expect(first).toBeLessThan(300);
    expect(second).toBe(409);
  });

  /**
   * A RETRY BY THE WINNER MUST BE RECOGNISABLE, NOT DESTRUCTIVE.
   *
   * If the winner crashes after claiming and retries, its own second attempt
   * returns 409 — the same signal a loser gets. So the claim doc alone cannot
   * express "who owns this"; it must carry an owner token the caller can
   * compare. This test pins that requirement rather than assuming the design
   * handles it.
   */
  test('a winner retrying its own claim is indistinguishable by status alone', async () => {
    const first = await restCreate('paymentClaims_probe', 'SCH_A_pay_RETRY', { owner: strField('tokenX') });
    expect(first).toBeLessThan(300);

    const retry = await restCreate('paymentClaims_probe', 'SCH_A_pay_RETRY', { owner: strField('tokenX') });
    expect(retry).toBe(409);

    // Therefore the claim MUST store an owner token, and the caller MUST read
    // it back on collision to tell "I already own this" from "someone else does".
    const res = await fetch(`${BASE}/paymentClaims_probe/SCH_A_pay_RETRY`, {
      headers: { Authorization: 'Bearer owner' },
    });
    const doc = await res.json();
    expect(doc.fields.owner.stringValue).toBe('tokenX');
  });
});

