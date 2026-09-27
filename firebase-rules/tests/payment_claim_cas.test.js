/**
 * ══════════════════════════════════════════════════════════════════════════
 *  B-025 LEASE TAKEOVER + REF-1 PREREQUISITE — PROVEN, NOT ASSUMED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `payment_claim_primitive.test.js` established that `create` is exclusive and
 * `set` is not. That alone is NOT a fix: an atomic create never self-heals, so
 * once the claim exists it is owned forever and a crashed processor would wedge
 * the order permanently.
 *
 * So Fee_payment_claim carries a LEASE, and an EXPIRED lease is taken over by a
 * compare-and-swap on the document's server `updateTime` — never by a second
 * blind write, which would reintroduce the very defect being fixed.
 *
 * The PHP-side unit tests can only prove the precondition was SENT. Whether the
 * SERVER honours it is a Firestore property, and that is what this file
 * establishes against a real emulator over the SAME REST surface the PHP client
 * uses (`:commit` with `currentDocument`).
 *
 * The second half proves `currentDocument.exists`, which REF-1's design depends
 * on and which has NEVER been exercised in this codebase.
 *
 * NO PRODUCTION DATA AND NO FINANCIAL TRANSACTION IS INVOLVED. End-to-end
 * payment behaviour remains PRODUCTION VERIFICATION BLOCKED: the estate has
 * recorded zero receipts and none may be manufactured.
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

const BASE = 'http://' + HOST + ':' + PORT +
  '/v1/projects/' + PROJECT_ID + '/databases/(default)/documents';

const DOC = (col, id) =>
  'projects/' + PROJECT_ID + '/databases/(default)/documents/' + col + '/' + id;

const strField = (v) => ({ stringValue: v });
const AUTH = { 'Content-Type': 'application/json', Authorization: 'Bearer owner' };

async function restCreate(collection, docId, fields) {
  const res = await fetch(
    BASE + '/' + collection + '?documentId=' + encodeURIComponent(docId),
    { method: 'POST', headers: AUTH, body: JSON.stringify({ fields }) }
  );
  return res.status;
}

/** A `:commit` with an optional currentDocument precondition. */
async function restCommit(collection, docId, fields, currentDocument) {
  const write = {
    update: { name: DOC(collection, docId), fields },
    updateMask: { fieldPaths: Object.keys(fields) },
  };
  if (currentDocument) write.currentDocument = currentDocument;

  const res = await fetch(BASE + ':commit', {
    method: 'POST', headers: AUTH, body: JSON.stringify({ writes: [write] }),
  });
  return res.status;
}

async function readDoc(collection, docId) {
  const res = await fetch(BASE + '/' + collection + '/' + encodeURIComponent(docId), {
    headers: { Authorization: 'Bearer owner' },
  });
  return { status: res.status, body: await res.json() };
}

async function readUpdateTime(collection, docId) {
  const { body } = await readDoc(collection, docId);
  return body.updateTime;
}

async function readField(collection, docId, field) {
  const { body } = await readDoc(collection, docId);
  return body.fields && body.fields[field] ? body.fields[field].stringValue : null;
}

describe('B-025 · lease takeover via updateTime CAS', () => {

  test('a read surfaces an updateTime, and a CAS on it succeeds', async () => {
    await restCreate('feeLocks_cas', 'SCH_A_order_CAS1', { token: strField('PRIOR') });

    const version = await readUpdateTime('feeLocks_cas', 'SCH_A_order_CAS1');
    expect(typeof version).toBe('string');
    expect(version.length).toBeGreaterThan(0);

    const code = await restCommit(
      'feeLocks_cas', 'SCH_A_order_CAS1',
      { token: strField('TAKEOVER') },
      { updateTime: version }
    );
    expect(code).toBeLessThan(300);
    expect(await readField('feeLocks_cas', 'SCH_A_order_CAS1', 'token')).toBe('TAKEOVER');
  });

  /**
   * THE CONTROL FOR THE CAS ITSELF. A stale version must be REJECTED. If the
   * server silently ignored the precondition, every "exclusive" takeover below
   * would really be an unguarded write and would pass vacuously.
   */
  test('a stale updateTime is rejected — the precondition is really enforced', async () => {
    await restCreate('feeLocks_cas', 'SCH_A_order_CAS2', { token: strField('PRIOR') });
    const stale = await readUpdateTime('feeLocks_cas', 'SCH_A_order_CAS2');

    // Someone else moves the document first.
    await restCommit('feeLocks_cas', 'SCH_A_order_CAS2',
      { token: strField('WINNER') }, { updateTime: stale });

    // Our attempt still carries the version read BEFORE that move.
    const code = await restCommit('feeLocks_cas', 'SCH_A_order_CAS2',
      { token: strField('LOSER') }, { updateTime: stale });

    expect(code).toBe(400);   // FAILED_PRECONDITION
    expect(await readField('feeLocks_cas', 'SCH_A_order_CAS2', 'token')).toBe('WINNER');
  });

  /**
   * THE PROPERTY THE RECOVERY PATH RESTS ON.
   *
   * N processors all observe the SAME expired lease and all attempt a takeover
   * with the version they read. Exactly one may win — otherwise a crashed
   * payment could be recovered by two processors at once, which is B-025 again,
   * merely relocated into the recovery path.
   */
  test('N concurrent takeovers of one expired lease — exactly one wins', async () => {
    const N = 12;
    await restCreate('feeLocks_cas', 'SCH_A_order_RACE', { token: strField('CRASHED') });

    const version = await readUpdateTime('feeLocks_cas', 'SCH_A_order_RACE');

    const codes = await Promise.all(
      Array.from({ length: N }, (_, i) =>
        restCommit('feeLocks_cas', 'SCH_A_order_RACE',
          { token: strField('recoverer_' + i) },
          { updateTime: version })
      )
    );

    expect(codes.filter((c) => c >= 200 && c < 300).length).toBe(1);
    expect(codes.filter((c) => c === 400).length).toBe(N - 1);
  });

  /**
   * CONTRAST: the same takeover WITHOUT a precondition. This is what the code
   * would do if the CAS were ever dropped — and it is why the PHP-side suite
   * asserts the precondition is PRESENT rather than merely checking the result.
   */
  test('control — the same takeover WITHOUT a precondition lets everyone win', async () => {
    const N = 12;
    await restCreate('feeLocks_cas', 'SCH_A_order_NOCAS', { token: strField('CRASHED') });

    const codes = await Promise.all(
      Array.from({ length: N }, (_, i) =>
        restCommit('feeLocks_cas', 'SCH_A_order_NOCAS', { token: strField('recoverer_' + i) })
      )
    );

    expect(codes.filter((c) => c >= 200 && c < 300).length).toBe(N);
  });

  /**
   * The claim is per-tenant: the same gateway order id under two school ids is
   * two documents, and a takeover of one must not touch the other.
   */
  test('a takeover is confined to its own tenant document', async () => {
    await restCreate('feeLocks_cas', 'SCH_A_order_SHARED', { token: strField('A_OWNER') });
    await restCreate('feeLocks_cas', 'SCH_B_order_SHARED', { token: strField('B_OWNER') });

    const vA = await readUpdateTime('feeLocks_cas', 'SCH_A_order_SHARED');
    expect(await restCommit('feeLocks_cas', 'SCH_A_order_SHARED',
      { token: strField('A_RECOVERED') }, { updateTime: vA })).toBeLessThan(300);

    expect(await readField('feeLocks_cas', 'SCH_B_order_SHARED', 'token')).toBe('B_OWNER');
  });
});

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  REF-1 PREREQUISITE — `currentDocument.exists`
 * ══════════════════════════════════════════════════════════════════════════
 *
 * REF-1 (refund/payment race) cannot be fixed the way B-025 was, because its
 * lock has TWO participants with two implementations: Fee_refund_service calls
 * acquireLock(), while FeeCollectionService writes the lock as an unconditional
 * `set` op inside its own atomic batch.
 *
 * The design gives that batch op a precondition rather than unpicking the batch
 * — `exists:false` for a clean acquire, `updateTime` for a stale takeover. The
 * REST client passes `precondition` straight through to `currentDocument`, so
 * the form is structurally supported; but `exists` has never been exercised
 * here, and relying on an unproven precondition in the commit that writes
 * receipts, allocations and journals is exactly the class of assumption that
 * produced FEE-W1.
 */
describe('REF-1 · exists precondition (prerequisite proof)', () => {

  test('exists:false acts as create-if-absent inside a commit', async () => {
    const code = await restCommit('feeLocks_exists', 'SCH_A_STU777',
      { token: strField('FIRST') }, { exists: false });

    expect(code).toBeLessThan(300);
    expect(await readField('feeLocks_exists', 'SCH_A_STU777', 'token')).toBe('FIRST');
  });

  /**
   * ── REF1-F1 · THE STATUS CODE IS NOT THE ONE THE PHP CLASSIFIER EXPECTS ──
   *
   * This assertion originally expected 400, by analogy with the updateTime CAS.
   * The emulator returned 409, and that difference is LOAD-BEARING:
   *
   *   Firestore_rest_client::commitBatchStatus() maps to COMMIT_CONFLICT only
   *   when `$code === 400` AND the body says FAILED_PRECONDITION. A 409 falls
   *   straight through to COMMIT_FAILURE — "outcome UNKNOWN".
   *
   * So wiring `exists:false` into FeeCollectionService's batch WITHOUT touching
   * the classifier would report a perfectly ordinary lock CONFLICT as UNKNOWN.
   * The refund caller must not delete its idempotency guard on UNKNOWN, so the
   * two outcomes drive different recovery — collapsing them is precisely what
   * REF-1 exists to avoid, merely inverted.
   *
   * REF-1 must therefore either extend commitBatchStatus to classify
   * 409/ALREADY_EXISTS as a conflict, or use an updateTime CAS throughout.
   * Pinned here so the decision cannot be quietly skipped.
   */
  test('exists:false is REJECTED with 409 ALREADY_EXISTS, not 400', async () => {
    await restCommit('feeLocks_exists', 'SCH_A_STU888',
      { token: strField('OWNER') }, { exists: false });

    const second = await restCommit('feeLocks_exists', 'SCH_A_STU888',
      { token: strField('INTRUDER') }, { exists: false });

    expect(second).toBe(409);
    expect(await readField('feeLocks_exists', 'SCH_A_STU888', 'token')).toBe('OWNER');
  });

  /**
   * The two precondition forms fail with DIFFERENT codes. Pinned explicitly
   * because the PHP classifier branches on exactly this.
   */
  test('updateTime conflicts are 400 while exists conflicts are 409', async () => {
    await restCreate('feeLocks_exists', 'SCH_A_STU_CODES', { token: strField('OWNER') });
    const stale = await readUpdateTime('feeLocks_exists', 'SCH_A_STU_CODES');

    // Move the document so `stale` is genuinely out of date.
    await restCommit('feeLocks_exists', 'SCH_A_STU_CODES',
      { token: strField('MOVED') }, { updateTime: stale });

    const byUpdateTime = await restCommit('feeLocks_exists', 'SCH_A_STU_CODES',
      { token: strField('X') }, { updateTime: stale });
    const byExists = await restCommit('feeLocks_exists', 'SCH_A_STU_CODES',
      { token: strField('Y') }, { exists: false });

    expect(byUpdateTime).toBe(400);
    expect(byExists).toBe(409);
    expect(byUpdateTime).not.toBe(byExists);
  });

  /**
   * The property REF-1 actually needs: concurrent acquirers of a student lock
   * resolve to exactly one owner with no read-then-write window — the guarantee
   * `create` gives, but available INSIDE the batch that already writes the
   * receipt, allocations and journal atomically.
   */
  test('N concurrent exists:false acquires — exactly one wins', async () => {
    const N = 12;
    const codes = await Promise.all(
      Array.from({ length: N }, (_, i) =>
        restCommit('feeLocks_exists', 'SCH_A_STU999',
          { token: strField('caller_' + i) }, { exists: false })
      )
    );

    expect(codes.filter((c) => c >= 200 && c < 300).length).toBe(1);
  });

  /**
   * THE REASON THE BATCH APPROACH IS RIGHT AT ALL.
   *
   * A commit is all-or-nothing. If the lock op loses its precondition, the
   * receipt/allocation/journal writes in the SAME commit must not land — no
   * money moves, no partial state, nothing to reconcile.
   */
  test('a failed lock precondition aborts the WHOLE batch — no money moves', async () => {
    await restCommit('feeLocks_exists', 'SCH_A_STU_BATCH',
      { token: strField('OWNER') }, { exists: false });

    const res = await fetch(BASE + ':commit', {
      method: 'POST',
      headers: AUTH,
      body: JSON.stringify({
        writes: [
          {
            update: { name: DOC('feeLocks_exists', 'SCH_A_STU_BATCH'), fields: { token: strField('LOSER') } },
            updateMask: { fieldPaths: ['token'] },
            currentDocument: { exists: false },
          },
          {
            update: { name: DOC('feeReceipts_probe', 'SCH_A_RCPT_1'), fields: { amount: strField('5000') } },
            updateMask: { fieldPaths: ['amount'] },
          },
        ],
      }),
    });

    // 409 ALREADY_EXISTS for an `exists` precondition — see REF1-F1 above.
    expect(res.status).toBe(409);

    // The receipt from the losing batch must not exist.
    const rcpt = await readDoc('feeReceipts_probe', 'SCH_A_RCPT_1');
    expect(rcpt.status).toBe(404);
    expect(await readField('feeLocks_exists', 'SCH_A_STU_BATCH', 'token')).toBe('OWNER');
  });

  /**
   * CONTROL: the same two-write batch WITHOUT the precondition commits both.
   * Without this, the previous test could pass because the receipt write itself
   * was malformed rather than because the batch was aborted.
   */
  test('control — the same batch without a precondition writes BOTH documents', async () => {
    const res = await fetch(BASE + ':commit', {
      method: 'POST',
      headers: AUTH,
      body: JSON.stringify({
        writes: [
          {
            update: { name: DOC('feeLocks_exists', 'SCH_A_STU_CTRL'), fields: { token: strField('T') } },
            updateMask: { fieldPaths: ['token'] },
          },
          {
            update: { name: DOC('feeReceipts_probe', 'SCH_A_RCPT_2'), fields: { amount: strField('5000') } },
            updateMask: { fieldPaths: ['amount'] },
          },
        ],
      }),
    });

    expect(res.status).toBeLessThan(300);
    expect(await readField('feeReceipts_probe', 'SCH_A_RCPT_2', 'amount')).toBe('5000');
  });
});
