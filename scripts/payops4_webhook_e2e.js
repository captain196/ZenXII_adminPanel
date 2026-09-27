/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PAY-OPS-4 — END-TO-END WEBHOOK RECONCILIATION PROOF
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Drives the REAL `fee_management/payment_webhook` endpoint over HTTP, with
 * genuinely-signed bodies, against a real fixture tenant in the Firestore
 * emulator. Nothing here is mocked on ZenX's side: the controller, the
 * provider adapter, Payment_service, Fee_payment_claim, FeeCollectionService,
 * the receipt/allocation/accounting writers and the idempotency layer all run.
 *
 * ── WHAT IT PROVES ───────────────────────────────────────────────────────
 *   E1  captured webhook, client never returned    → settles EXACTLY once
 *   E2  identical redelivery                       → duplicate, no 2nd receipt
 *   E3  redelivery with a NEW event id             → still no 2nd receipt
 *   E4  payment.failed                             → nothing financial
 *   E5  unknown event                              → nothing at all
 *   E6  provider says NOT captured                 → refused, terminal
 *   E7  provider UNREACHABLE, then recovers        → retry SURVIVES and settles
 *   E8  provider reports a different amount        → refused
 *   E9  forged webhook signature                   → 401 before anything
 *   E10 payment belongs to another order           → refused
 *   E11 a SECOND provider (mock) settles the same way — no Razorpay lock-in
 *
 * E7 is the headline. Before this change the first attempt's failure was
 * written as `processed_at`, so the gateway's redelivery was answered
 * "Already processed (duplicate webhook)" and the payment could never be
 * reconciled by any automatic means. Here the retry is expected to SETTLE.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────────
 * Refuses unless FIRESTORE_EMULATOR_HOST is loopback AND the app under test is
 * on loopback. It writes money records, so pointing it at a live tenant must
 * be impossible by omission, not by care. Secrets are read programmatically
 * and never printed.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/payops4_webhook_e2e.js
 */
const admin  = require('firebase-admin');
const crypto = require('crypto');
const http   = require('http');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
const emuHost = EMU.replace(/^https?:\/\//, '').split(':')[0];
if (!EMU || !['127.0.0.1', 'localhost', '::1'].includes(emuHost)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  process.exit(1);
}

const SCHOOL   = process.env.ZX_SCHOOL  || 'SCH_EMUFIXTURE1';
const SESSION  = process.env.ZX_SESSION || '2026-27';
const STUDENT  = process.env.ZX_STUDENT || 'STU0005';
const APP_PORT = parseInt(process.env.ZX_APP_PORT  || '8099', 10);
const STUB     = parseInt(process.env.ZX_STUB_PORT || '8123', 10);

// A test-mode key shape. It authenticates nothing: every provider call in this
// run goes to the loopback stub, reached only because Fee_management honours an
// api_base override in SIMULATOR_ENVIRONMENTS for loopback destinations.
const API_KEY    = 'rzp_test_PAYOPS4STUB';
const API_SECRET = 'stub_key_secret_not_a_real_credential';
const WH_SECRET  = 'stub_webhook_secret_not_a_real_credential';

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

let failures = 0;
const ok   = (m) => console.log(`     PASS  ${m}`);
const fail = (m) => { failures++; console.log(`     FAIL  ${m}`); };
const check = (cond, m) => (cond ? ok(m) : fail(m));

// ══ TRANSPORT ═════════════════════════════════════════════════════════════

function request(opts, body) {
  return new Promise((resolve) => {
    const req = http.request(opts, (res) => {
      let b = '';
      res.on('data', (c) => (b += c));
      res.on('end', () => {
        let json = null;
        try { json = JSON.parse(b); } catch (e) { /* non-JSON is itself a result */ }
        resolve({ code: res.statusCode, body: b, json });
      });
    });
    req.on('error', (e) => resolve({ code: 0, body: `TRANSPORT: ${e.message}`, json: null }));
    if (body) req.write(body);
    req.end();
  });
}

/** Deliver a webhook exactly as a provider would: signature over the raw bytes. */
async function deliver(payload, { eventId, signature, secret = WH_SECRET } = {}) {
  const raw = JSON.stringify(payload);
  const sig = signature !== undefined
    ? signature
    : crypto.createHmac('sha256', secret).update(raw).digest('hex');
  return request({
    host: '127.0.0.1', port: APP_PORT, method: 'POST',
    path: '/index.php/fee_management/payment_webhook',
    headers: {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(raw),
      'X-Razorpay-Signature': sig,
      'X-Razorpay-Event-Id': eventId || ('evt_' + crypto.randomBytes(8).toString('hex')),
    },
    timeout: 120000,
  }, raw);
}

/** Point the loopback provider stub at a state for one payment id. */
const setStub = (cfg) => request({
  host: '127.0.0.1', port: STUB, method: 'POST', path: '/__set',
  headers: { 'Content-Type': 'application/json' },
}, JSON.stringify(cfg));

// ══ ENVELOPES ═════════════════════════════════════════════════════════════

const capturedEvent = (pay, order) => ({
  entity: 'event', event: 'payment.captured', contains: ['payment'],
  payload: { payment: { entity: {
    id: pay, entity: 'payment', order_id: order, status: 'captured', captured: true,
  } } },
});
const failedEvent = (pay, order) => ({
  entity: 'event', event: 'payment.failed', contains: ['payment'],
  payload: { payment: { entity: {
    id: pay, entity: 'payment', order_id: order, status: 'failed',
    error_code: 'BAD_REQUEST_ERROR', error_description: 'Payment failed',
  } } },
});
const unknownEvent = (pay, order) => ({
  entity: 'event', event: 'refund.processed', contains: ['refund'],
  payload: { payment: { entity: { id: pay, order_id: order, status: 'captured' } } },
});

// ══ FIRESTORE READS ═══════════════════════════════════════════════════════

const orderDoc = async (o) =>
  (await db.collection('feeOnlineOrders').doc(`${SCHOOL}_${o}`).get()).data() || null;

const receiptsFor = async (payId) => {
  const s = await db.collection('feeReceipts').where('schoolId', '==', SCHOOL).get();
  return s.docs.map((d) => d.data()).filter((r) => String(r.paymentId || '') === payId);
};
const allReceipts = async () => {
  const s = await db.collection('feeReceipts').where('schoolId', '==', SCHOOL).get();
  return s.docs.map((d) => d.data());
};
const paymentsFor = async (payId) => {
  const s = await db.collection('feeOnlinePayments').where('schoolId', '==', SCHOOL).get();
  return s.docs.map((d) => d.data())
    .filter((p) => String(p.gateway_payment_id || '') === payId && p.docType !== 'webhook_log');
};
const idempotency = async () => {
  const s = await db.collection('feeIdempotency').where('schoolId', '==', SCHOOL).get();
  return s.docs.map((d) => ({ id: d.id, ...d.data() }));
};
const demandSnapshot = async () => {
  const s = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL).where('studentId', '==', STUDENT).get();
  const rows = s.docs.map((d) => d.data());
  const byStatus = {};
  rows.forEach((r) => { byStatus[r.status] = (byStatus[r.status] || 0) + 1; });
  return {
    total: rows.length, byStatus,
    paid: rows.reduce((a, r) => a + Number(r.paidAmount || 0), 0),
    net:  rows.reduce((a, r) => a + Number(r.netAmount  || 0), 0),
  };
};
const allocationCount = async () => {
  const s = await db.collection('feeReceiptAllocations').where('schoolId', '==', SCHOOL).get();
  return s.size;
};

/**
 * The receipt-allocation documents, with their per-demand breakdown.
 *
 * Existence was already asserted; SUMMING them was not. "allocations > 0" is
 * satisfied by a single row that allocates one rupee of a nineteen-hundred
 * rupee payment, which is exactly the shape of a money bug that reconciles
 * nowhere and shows up months later as an unexplained balance.
 */
const allocationDocs = async () => {
  const s = await db.collection('feeReceiptAllocations').where('schoolId', '==', SCHOOL).get();
  return s.docs.map((d) => d.data());
};
/**
 * Journal entries, whole. accountingCount() only ever counted them, and a
 * COUNT is not an accounting assertion: an entry that debits 900 and credits
 * nothing is still one entry. ZenX posts real double-entry vouchers
 * (lines[] with dr/cr, plus total_dr/total_cr headers), so the invariants
 * below are the domain's own, not invented for the test.
 */
/**
 * Every document id in the money collections, with its owning tenant.
 *
 * ── WHY A BASELINE IS NEEDED ─────────────────────────────────────────────
 * The isolation check used to count documents whose schoolId != SCHOOL and
 * require ZERO. That is only meaningful in a database containing exactly one
 * tenant — which is the only way it had ever been run. Populate a second
 * tenant and it counts that tenant's perfectly legitimate documents as
 * violations, while a run against an empty sibling proves nothing at all.
 *
 * What actually needs asserting is narrower and stronger: THIS RUN must not
 * create or modify a document belonging to another tenant. So take the ids
 * before, take them after, and judge only the difference.
 */
const moneyDocIds = async () => {
  const out = new Map();
  for (const col of ['feeOnlineOrders', 'feeReceipts', 'feeOnlinePayments', 'feeIdempotency',
                     'accounting', 'accountingLedger', 'feeReceiptAllocations']) {
    try {
      const s = await db.collection(col).get();
      s.docs.forEach((d) => out.set(col + '/' + d.id, String((d.data() || {}).schoolId || '')));
    } catch (e) { /* collection may not exist */ }
  }
  return out;
};

const feeDrTotal = (docs) => docs
  .filter((j) => String(j.voucher_type || '') === 'Fee')
  .reduce((a, j) => a + (Array.isArray(j.lines) ? j.lines : [])
    .reduce((x, l) => x + Number(l.dr || 0), 0), 0);

const accountingDocs = async () => {
  const out = [];
  for (const col of ['accounting', 'accountingLedger']) {
    try {
      const s = await db.collection(col).where('schoolId', '==', SCHOOL).get();
      s.docs.forEach((d) => out.push(d.data()));
    } catch (e) { /* collection may not exist */ }
  }
  return out;
};
const accountingCount = async () => {
  let n = 0;
  for (const col of ['accounting', 'accountingLedger']) {
    try {
      const s = await db.collection(col).where('schoolId', '==', SCHOOL).get();
      n += s.size;
    } catch (e) { /* collection may not exist in the fixture */ }
  }
  return n;
};
const claimDocs = async () => {
  const s = await db.collection('feeLocks').get();
  return s.docs.map((d) => ({ id: d.id, ...d.data() }))
    .filter((c) => String(c.id).startsWith(SCHOOL));
};

// ══ SETUP ═════════════════════════════════════════════════════════════════

async function setGateway(provider) {
  const doc = {
    provider, mode: 'test', active: true,
    api_key: API_KEY, api_secret: API_SECRET, webhook_secret: WH_SECRET,
    // The loopback test seam. Ignored outright in production — see
    // Fee_management::_init_payment_service and ProviderApiBaseSeamTest.
    api_base: `http://127.0.0.1:${STUB}/v1`,
    updated_at: new Date().toISOString(),
  };
  if (provider === 'mock') { delete doc.webhook_secret; }
  await db.collection('feeSettings').doc(`${SCHOOL}_${SESSION}_gateway`).set(doc);
}

/** Seed one order in exactly the shape Payment_service::create_order writes. */
async function makeOrder(orderId, amount, months) {
  await db.collection('feeOnlineOrders').doc(`${SCHOOL}_${orderId}`).set({
    schoolId: SCHOOL, session: SESSION,
    student_id: STUDENT, student_name: 'PAYOPS4 Payer',
    class: 'Class 5th', section: 'Section A',
    amount, fee_months: months,
    gateway: 'razorpay', gateway_order_id: orderId, gateway_payment_id: '',
    status: 'created', created_at: new Date().toISOString(), created_by: 'parent_app',
    paid_at: '', verified_at: '',
  });
}

/**
 * The OLDEST unpaid month and what it costs, from the student's real demands.
 *
 * Must be the oldest: ZenX enforces PAY-OLDER-FIRST inside
 * FeeCollectionService, and an order for a later month is refused with
 * "Please clear the earlier pending fees for …". That refusal is the business
 * rule working correctly — the harness has to respect it rather than route
 * around it, or the run would be testing a path a parent can never take.
 *
 * Ordering comes from `periodKey` ("2026-04", "2027-01"), which sorts
 * lexicographically because it is zero-padded. `month` alone ("January") does
 * not carry the year and would sort a January AFTER a December of the previous
 * calendar year.
 *
 * YEARLY HEADS COUNT. The fixture's "Yearly Fees" demand carries periodKey
 * 2026-04, the same as the first month, and is therefore part of the oldest
 * outstanding bucket. Excluding it (an obvious-looking simplification, since
 * it is not a "month") made every later order unpayable: pay-older-first kept
 * refusing with "clear the earlier pending fees for Yearly Fees", and the
 * refusal read like a webhook defect rather than the harness paying the wrong
 * thing.
 */
async function oldestUnpaidMonth() {
  const s = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL).where('studentId', '==', STUDENT).get();

  const byMonth = {};
  s.docs.map((d) => d.data()).forEach((r) => {
    if (r.status !== 'unpaid') return;
    const m = String(r.month || '');
    const k = String(r.periodKey || '');
    if (!m || !k) return;
    if (!byMonth[m]) byMonth[m] = { month: m, periodKey: k, amount: 0 };
    if (k < byMonth[m].periodKey) byMonth[m].periodKey = k;
    byMonth[m].amount += Number(r.balance || r.netAmount || 0);
  });

  const rows = Object.values(byMonth).sort((a, b) => a.periodKey.localeCompare(b.periodKey));
  if (!rows.length) throw new Error('no unpaid monthly demand left in the fixture');
  return { month: rows[0].month, amount: rows[0].amount };
}

/**
 * The scenarios that must NOT settle can all reuse the same oldest month —
 * nothing is consumed when nothing is paid. Only the settling scenarios
 * advance the queue, and they do so simply by having been paid.
 */
const pickMonth = () => oldestUnpaidMonth();

/**
 * A month that is NOT the oldest outstanding one — i.e. a payment ZenX will
 * refuse, permanently, under pay-older-first (PAY-OPS-6, E12).
 *
 * The refusal is the point: it is HTTP 409 from FeeCollectionService, the same
 * refusal every redelivery would get, and it is what the retry classifier has
 * to recognise as permanent rather than inviting the gateway to try forever.
 */
async function laterUnpaidMonth() {
  const s = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL).where('studentId', '==', STUDENT).get();

  const byMonth = {};
  s.docs.map((d) => d.data()).forEach((r) => {
    if (r.status !== 'unpaid') return;
    const m = String(r.month || ''); const k = String(r.periodKey || '');
    if (!m || !k) return;
    if (!byMonth[m]) byMonth[m] = { month: m, periodKey: k, amount: 0 };
    if (k < byMonth[m].periodKey) byMonth[m].periodKey = k;
    byMonth[m].amount += Number(r.balance || r.netAmount || 0);
  });

  const rows = Object.values(byMonth).sort((a, b) => a.periodKey.localeCompare(b.periodKey));
  // Skip every month sharing the OLDEST period — those are payable together.
  const oldestKey = rows.length ? rows[0].periodKey : '';
  const later = rows.find((r) => r.periodKey > oldestKey);
  if (!later) throw new Error('fixture has no month later than the oldest bucket');
  return { month: later.month, amount: later.amount };
}

const oid = () => 'order_' + crypto.randomBytes(7).toString('hex').toUpperCase();
const pid = () => 'pay_'   + crypto.randomBytes(7).toString('hex').toUpperCase();

// ══ THE RUN ═══════════════════════════════════════════════════════════════

(async () => {
  console.log(`\nPAY-OPS-4 WEBHOOK E2E — ${SCHOOL}/${SESSION} student ${STUDENT}`);
  console.log(`emulator ${EMU} · app 127.0.0.1:${APP_PORT} · provider stub 127.0.0.1:${STUB}\n`);

  const ping = await request({ host: '127.0.0.1', port: APP_PORT, method: 'GET', path: '/index.php' });
  if (ping.code === 0) { console.error('app is not reachable: ' + ping.body); process.exit(1); }
  const stubPing = await setStub({ payment_id: '__probe', status: 'captured', amount_paise: 1, order_id: 'x' });
  if (stubPing.code !== 200) { console.error('provider stub is not reachable'); process.exit(1); }

  const baselineIds = await moneyDocIds();
  const baselineJe = await accountingDocs();
  const baselineVouchers = baselineJe.filter((j) => String(j.voucher_type || '') === 'Fee').length;
  const baselineFeeDr  = feeDrTotal(baselineJe);

  await setGateway('razorpay');

  const before = await demandSnapshot();
  console.log(`  baseline: ${before.total} demands, ${JSON.stringify(before.byStatus)}, paid=${before.paid}\n`);

  
  // ══ E1 · THE CASE THE WEBHOOK EXISTS FOR ════════════════════════════════
  console.log('  [E1] captured webhook, client never returned');
  const m1 = await pickMonth();
  const o1 = oid(), p1 = pid();
  await makeOrder(o1, m1.amount, [m1.month]);
  await setStub({ payment_id: p1, status: 'captured', amount_paise: Math.round(m1.amount * 100), order_id: o1 });

  const ev1 = 'evt_E1_' + crypto.randomBytes(4).toString('hex');
  const r1 = await deliver(capturedEvent(p1, o1), { eventId: ev1 });
  check(r1.code === 200, `HTTP 200 (got ${r1.code}: ${String(r1.body).slice(0, 160)})`);

  const od1 = await orderDoc(o1);
  check(od1 && od1.status === 'paid', `order status = paid (got ${od1 && od1.status})`);
  check(od1 && od1.verified_via === 'provider_fetch',
    `verified_via = provider_fetch (got ${od1 && od1.verified_via}) — settled on the provider's own record, not a signature it never carried`);
  const rc1 = await receiptsFor(p1);
  check(rc1.length === 1, `exactly one receipt (got ${rc1.length})`);
  const pay1 = await paymentsFor(p1);
  check(pay1.length === 1, `exactly one feeOnlinePayments record (got ${pay1.length})`);
  const after1 = await demandSnapshot();
  check(after1.paid === before.paid + m1.amount,
    `demands credited by ${m1.amount} (paid ${before.paid} -> ${after1.paid})`);
  const idem1 = (await idempotency()).find((d) => d.order_id === o1);
  check(!!idem1 && idem1.state === 'processed' && !!idem1.processed_at,
    `idempotency state=processed with processed_at (got ${idem1 && idem1.state})`);
  const allocAfter1 = await allocationCount();
  check(allocAfter1 > 0, `receipt allocations written (${allocAfter1})`);

  // ── FINANCIAL INTEGRITY · THE ALLOCATIONS MUST SUM TO THE MONEY ─────────
  // payments − allocations = 0 remaining. Asserted exactly, in paise, because
  // the failure this catches is silent: a receipt that exists, demands that
  // look credited, and a breakdown that does not add up to what was taken.
  const docs1 = await allocationDocs();
  const mine1 = docs1.filter((d) => Array.isArray(d.allocations) && d.allocations.length);
  check(mine1.length > 0, 'at least one allocation document carries a per-demand breakdown');
  for (const d of mine1) {
    const sum = d.allocations.reduce((a, r) => a + Number(r.allocated || 0), 0);
    const net = Number(d.netReceived || 0);
    check(Math.abs(sum - net) < 0.005,
      `receipt ${d.receiptNo || d.receiptKey}: SUM(allocated)=${sum.toFixed(2)} == netReceived=${net.toFixed(2)} `
      + '— an allocation breakdown that does not add up to the money received leaves an '
      + 'unexplained balance nobody can trace back to a payment');
    check(d.allocations.every((r) => Number(r.allocated || 0) > 0),
      'no zero-value allocation rows (a row that moves no money explains nothing)');
    check(d.allocations.every((r) => Number(r.balance || 0) >= -0.005),
      'no demand was over-allocated into a negative balance');
  }
  const acctAfter1 = await accountingCount();
  check(acctAfter1 > 0, `accounting entries written (${acctAfter1})`);

  // ── DOUBLE ENTRY MUST ACTUALLY BALANCE ─────────────────────────────────
  // The count above says an entry exists. These say it is an accounting
  // record rather than a note: every voucher balances, and its header totals
  // agree with its own lines. An unbalanced journal is not a smaller problem
  // than a missing one — it is a ledger that cannot be trusted to close.
  const je1 = await accountingDocs();
  check(je1.length > 0, 'journal entries readable');
  for (const je of je1) {
    const lines = Array.isArray(je.lines) ? je.lines : [];
    check(lines.length >= 2,
      `voucher ${je.voucher_no || je.entryId}: at least two lines (single-sided entry is not double entry)`);
    const dr = lines.reduce((a, l) => a + Number(l.dr || 0), 0);
    const cr = lines.reduce((a, l) => a + Number(l.cr || 0), 0);
    check(Math.abs(dr - cr) < 0.005,
      `voucher ${je.voucher_no || je.entryId}: debits ${dr.toFixed(2)} == credits ${cr.toFixed(2)}`);
    check(Math.abs(dr - Number(je.total_dr || 0)) < 0.005
          && Math.abs(cr - Number(je.total_cr || 0)) < 0.005,
      `voucher ${je.voucher_no || je.entryId}: header totals agree with its lines `
      + `(total_dr=${je.total_dr}, total_cr=${je.total_cr}) — a header that disagrees with its own `
      + 'lines is what a report will read');
  }

  // ══ E2 · IDENTICAL REDELIVERY ═══════════════════════════════════════════
  console.log('\n  [E2] identical redelivery (same event id)');
  const r2 = await deliver(capturedEvent(p1, o1), { eventId: ev1 });
  check(r2.code === 200, `HTTP 200 (got ${r2.code})`);
  check(/duplicate|already/i.test(r2.body), 'answered as a duplicate');
  check((await receiptsFor(p1)).length === 1, 'still exactly one receipt');
  check((await demandSnapshot()).paid === after1.paid, 'no further money moved');
  check((await allocationCount()) === allocAfter1, 'no further allocations');
  check((await accountingCount()) === acctAfter1, 'no further accounting entries');

  // ══ E3 · REDELIVERY WITH A NEW EVENT ID ═════════════════════════════════
  console.log('\n  [E3] redelivery with a DIFFERENT event id (dedup doc cannot match)');
  const r3 = await deliver(capturedEvent(p1, o1), { eventId: 'evt_E3_' + crypto.randomBytes(4).toString('hex') });
  check(r3.code === 200, `HTTP 200 (got ${r3.code})`);
  check((await receiptsFor(p1)).length === 1,
    'still exactly one receipt — the payment-level marker caught what the event dedup could not');
  check((await demandSnapshot()).paid === after1.paid, 'no further money moved');

  // ══ E4 · A FAILED PAYMENT ═══════════════════════════════════════════════
  console.log('\n  [E4] payment.failed');
  const m4 = await pickMonth();
  const o4 = oid(), p4 = pid();
  await makeOrder(o4, m4.amount, [m4.month]);
  await setStub({ payment_id: p4, status: 'failed', amount_paise: Math.round(m4.amount * 100), order_id: o4 });

  const paidBefore4 = (await demandSnapshot()).paid;
  const receiptsBefore4 = (await allReceipts()).length;
  const r4 = await deliver(failedEvent(p4, o4));
  check(r4.code === 200, `HTTP 200 (acknowledged, got ${r4.code})`);
  const od4 = await orderDoc(o4);
  check(od4 && od4.status === 'failed', `order marked failed (got ${od4 && od4.status})`);
  check(od4 && od4.failed_event === 'payment.failed', 'the failing event is recorded on the order');
  check((await receiptsFor(p4)).length === 0, 'NO receipt');
  check((await paymentsFor(p4)).length === 0, 'NO payment record');
  check((await demandSnapshot()).paid === paidBefore4, 'NO money moved');
  check((await allReceipts()).length === receiptsBefore4, 'no receipt created anywhere');
  const idem4 = (await idempotency()).find((d) => d.order_id === o4);
  check(!!idem4 && idem4.state === 'non_financial', `idempotency state=non_financial (got ${idem4 && idem4.state})`);

  // ══ E5 · AN UNKNOWN EVENT ═══════════════════════════════════════════════
  console.log('\n  [E5] unknown event (refund.processed)');
  const m5 = await pickMonth();
  const o5 = oid(), p5 = pid();
  await makeOrder(o5, m5.amount, [m5.month]);
  await setStub({ payment_id: p5, status: 'captured', amount_paise: Math.round(m5.amount * 100), order_id: o5 });

  const paidBefore5 = (await demandSnapshot()).paid;
  const r5 = await deliver(unknownEvent(p5, o5));
  check(r5.code === 200, `HTTP 200 (got ${r5.code})`);
  const od5 = await orderDoc(o5);
  check(od5 && od5.status === 'created', `order untouched (got ${od5 && od5.status})`);
  check((await receiptsFor(p5)).length === 0, 'NO receipt');
  check((await demandSnapshot()).paid === paidBefore5, 'NO money moved');
  const idem5 = (await idempotency()).find((d) => d.event === 'refund.processed');
  check(!!idem5 && idem5.state === 'ignored', `idempotency state=ignored (got ${idem5 && idem5.state})`);

  // ══ E6 · PROVIDER SAYS NOT CAPTURED ═════════════════════════════════════
  console.log('\n  [E6] captured-typed event, but the provider says NOT captured');
  const m6 = await pickMonth();
  const o6 = oid(), p6 = pid();
  await makeOrder(o6, m6.amount, [m6.month]);
  // The event CLAIMS a capture. The provider's own record says authorized —
  // funds held, not settled. The allowlist must refuse it.
  await setStub({ payment_id: p6, status: 'authorized', amount_paise: Math.round(m6.amount * 100), order_id: o6 });

  const paidBefore6 = (await demandSnapshot()).paid;
  const r6 = await deliver(capturedEvent(p6, o6));
  check(r6.code === 422, `HTTP 422 terminal rejection (got ${r6.code})`);
  check((await receiptsFor(p6)).length === 0, 'NO receipt for an uncaptured payment');
  check((await demandSnapshot()).paid === paidBefore6, 'NO money moved');
  const idem6 = (await idempotency()).find((d) => d.order_id === o6);
  check(!!idem6 && idem6.state === 'rejected' && !!idem6.processed_at,
    `idempotency state=rejected, terminal (got ${idem6 && idem6.state})`);

  // ══ E7 · THE HEADLINE — A RETRY THAT SURVIVES ═══════════════════════════
  console.log('\n  [E7] provider UNREACHABLE, then recovers — the retry must survive');
  const m7 = await pickMonth();
  const o7 = oid(), p7 = pid();
  await makeOrder(o7, m7.amount, [m7.month]);
  await setStub({ payment_id: p7, mode: 'unreachable' });

  const ev7 = 'evt_E7_' + crypto.randomBytes(4).toString('hex');
  const paidBefore7 = (await demandSnapshot()).paid;

  const r7a = await deliver(capturedEvent(p7, o7), { eventId: ev7 });
  check(r7a.code === 503, `first attempt answers 503 retryable (got ${r7a.code})`);
  check((await receiptsFor(p7)).length === 0, 'nothing settled while unconfirmable');
  const od7a = await orderDoc(o7);
  check(od7a && od7a.status === 'created',
    `order NOT stamped failed on a provider outage (got ${od7a && od7a.status}) — we could not ask, so we do not conclude`);
  const idem7a = (await idempotency()).find((d) => d.id === undefined ? false : d.order_id === o7);
  check(!!idem7a && idem7a.state === 'retry' && !idem7a.processed_at,
    `idempotency records an ATTEMPT with no processed_at (state=${idem7a && idem7a.state}, processed_at=${idem7a && idem7a.processed_at})`);

  // The provider comes back. The gateway redelivers THE SAME event.
  await setStub({ payment_id: p7, status: 'captured', amount_paise: Math.round(m7.amount * 100), order_id: o7 });
  const r7b = await deliver(capturedEvent(p7, o7), { eventId: ev7 });

  check(r7b.code === 200, `the retry is ACCEPTED, not swallowed (got ${r7b.code})`);
  check(!/duplicate/i.test(r7b.body),
    'the retry was NOT answered "already processed" — this is the exact defect: the first '
    + 'failure used to write processed_at, and every redelivery after it was discarded');
  check((await receiptsFor(p7)).length === 1, 'the retry settled the payment (one receipt)');
  check((await demandSnapshot()).paid === paidBefore7 + m7.amount, `demands credited by ${m7.amount} on the retry`);
  const idem7b = (await idempotency()).find((d) => d.order_id === o7);
  check(!!idem7b && idem7b.state === 'processed' && !!idem7b.processed_at, 'marker now terminal');
  check(!!idem7b && Number(idem7b.attempts) >= 2, `attempt history preserved (attempts=${idem7b && idem7b.attempts})`);

  // ══ E8 · AMOUNT MISMATCH ════════════════════════════════════════════════
  console.log('\n  [E8] provider reports a different amount');
  const m8 = await pickMonth();
  const o8 = oid(), p8 = pid();
  await makeOrder(o8, m8.amount, [m8.month]);
  // One paise short. MONEY-1 compares exact minor units, so this must refuse.
  await setStub({ payment_id: p8, status: 'captured', amount_paise: Math.round(m8.amount * 100) - 1, order_id: o8 });

  const paidBefore8 = (await demandSnapshot()).paid;
  const r8 = await deliver(capturedEvent(p8, o8));
  check(r8.code === 422, `HTTP 422 (got ${r8.code})`);
  check((await receiptsFor(p8)).length === 0, 'NO receipt for a mismatched amount');
  check((await demandSnapshot()).paid === paidBefore8, 'NO money moved');
  const od8 = await orderDoc(o8);
  check(od8 && od8.status === 'amount_mismatch', `order flagged amount_mismatch (got ${od8 && od8.status})`);

  // ══ E9 · FORGED SIGNATURE ═══════════════════════════════════════════════
  console.log('\n  [E9] forged webhook signature');
  const m9 = await pickMonth();
  const o9 = oid(), p9 = pid();
  await makeOrder(o9, m9.amount, [m9.month]);
  await setStub({ payment_id: p9, status: 'captured', amount_paise: Math.round(m9.amount * 100), order_id: o9 });

  const paidBefore9 = (await demandSnapshot()).paid;
  const r9 = await deliver(capturedEvent(p9, o9), { signature: 'deadbeef'.repeat(8) });
  check(r9.code === 401, `HTTP 401 (got ${r9.code})`);
  check((await receiptsFor(p9)).length === 0, 'NO receipt');
  check((await demandSnapshot()).paid === paidBefore9, 'NO money moved');
  const od9 = await orderDoc(o9);
  check(od9 && od9.status === 'created', 'order untouched — rejected before any processing');

  // ══ E10 · PROVENANCE ════════════════════════════════════════════════════
  console.log('\n  [E10] the payment belongs to a DIFFERENT order');
  const m10 = await pickMonth();
  const o10 = oid(), p10 = pid();
  await makeOrder(o10, m10.amount, [m10.month]);
  await setStub({ payment_id: p10, status: 'captured', amount_paise: Math.round(m10.amount * 100), order_id: 'order_SOMEONE_ELSE' });

  const paidBefore10 = (await demandSnapshot()).paid;
  const r10 = await deliver(capturedEvent(p10, o10));
  check(r10.code === 422, `HTTP 422 (got ${r10.code})`);
  check((await receiptsFor(p10)).length === 0, 'NO receipt');
  check((await demandSnapshot()).paid === paidBefore10, 'NO money moved');

  // ══ E11 · A SECOND PROVIDER ═════════════════════════════════════════════
  console.log('\n  [E11] the SAME pipeline through a different provider adapter (mock)');
  await setGateway('mock');
  const m11 = await pickMonth();
  const o11 = oid(), p11 = pid();
  await db.collection('feeOnlineOrders').doc(`${SCHOOL}_${o11}`).set({
    schoolId: SCHOOL, session: SESSION, student_id: STUDENT, student_name: 'PAYOPS4 Payer',
    class: 'Class 5th', section: 'Section A', amount: m11.amount, fee_months: [m11.month],
    gateway: 'mock', gateway_order_id: o11, gateway_payment_id: '',
    status: 'created', created_at: new Date().toISOString(), created_by: 'parent_app',
    paid_at: '', verified_at: '',
  });

  const paidBefore11 = (await demandSnapshot()).paid;
  // The mock has no webhook secret, so the handler's HMAC step is skipped for
  // it by design (the simulator is confined to SIMULATOR_ENVIRONMENTS).
  const r11 = await deliver(capturedEvent(p11, o11), { secret: 'irrelevant' });
  check(r11.code === 200, `HTTP 200 through the mock adapter (got ${r11.code}: ${String(r11.body).slice(0, 140)})`);
  check((await receiptsFor(p11)).length === 1, 'one receipt — the pipeline is not Razorpay-specific');
  check((await demandSnapshot()).paid === paidBefore11 + m11.amount, `demands credited by ${m11.amount}`);

  // A failure event through the second provider must also settle nothing.
  const m11b = await pickMonth();
  const o11b = oid(), p11b = pid();
  await db.collection('feeOnlineOrders').doc(`${SCHOOL}_${o11b}`).set({
    schoolId: SCHOOL, session: SESSION, student_id: STUDENT, student_name: 'PAYOPS4 Payer',
    class: 'Class 5th', section: 'Section A', amount: m11b.amount, fee_months: [m11b.month],
    gateway: 'mock', gateway_order_id: o11b, gateway_payment_id: '',
    status: 'created', created_at: new Date().toISOString(), created_by: 'parent_app',
  });
  const paidBefore11b = (await demandSnapshot()).paid;
  const r11b = await deliver(failedEvent(p11b, o11b), { secret: 'irrelevant' });
  check(r11b.code === 200, `failure event acknowledged (got ${r11b.code})`);
  check((await demandSnapshot()).paid === paidBefore11b, 'NO money moved through the second provider either');

  await setGateway('razorpay');

  // ══ E12 · PAY-OPS-6 — A PERMANENT BUSINESS REFUSAL ══════════════════════
  //
  // The gateway captures the money and ZenX legitimately refuses to apply it:
  // paying a later month while an older one is outstanding is HTTP 409 from
  // FeeCollectionService, identically, on every redelivery.
  //
  // Before PAY-OPS-6 this was flagged retryable — so the provider was invited
  // to redeliver an outcome that could never change, burning its retry budget
  // and burying a captured payment under duplicate deliveries. It must now be
  // ACKNOWLEDGED (200, so retries stop) while the order stays recoverable and
  // visibly needs a human.
  console.log('\n  [E12] permanent business refusal (pay-older-first)');
  await setGateway('razorpay');
  const m12 = await laterUnpaidMonth();
  const o12 = oid(), p12 = pid();
  await makeOrder(o12, m12.amount, [m12.month]);
  await setStub({ payment_id: p12, status: 'captured', amount_paise: Math.round(m12.amount * 100), order_id: o12 });

  const paidBefore12 = (await demandSnapshot()).paid;
  const receiptsBefore12 = (await allReceipts()).length;
  const r12 = await deliver(capturedEvent(p12, o12));

  check(r12.code === 200,
    `HTTP 200 acknowledged, NOT a retry-inviting status (got ${r12.code}) — a non-2xx here `
    + 'makes the gateway redeliver a refusal that can never succeed');
  check(!!r12.json && r12.json.reconciled === false,
    'the response states reconciled=false — a bare 200 in a delivery log reads as a receipt');
  check(!!r12.json && r12.json.needs_operator_action === true,
    'the response does not flag that a human is needed');

  check((await receiptsFor(p12)).length === 0, 'NO receipt for a refused payment');
  check((await allReceipts()).length === receiptsBefore12, 'no receipt created anywhere');
  check((await demandSnapshot()).paid === paidBefore12, 'NO money moved');

  const od12 = await orderDoc(o12);
  check(od12 && od12.status === 'fees_failed',
    `order left in fees_failed for recovery (got ${od12 && od12.status}) — the admin Retry `
    + 'path keys on exactly this string');
  check(od12 && od12.payment_status === 'captured',
    'the order must record that the gateway DID capture the money');
  check(od12 && od12.failure_class === 'business',
    `failure_class=business (got ${od12 && od12.failure_class}) — tells the operator Retry `
    + 'alone will not help');

  const idem12 = (await idempotency()).find((d) => d.order_id === o12);
  check(!!idem12 && idem12.state === 'business_refused' && !!idem12.processed_at,
    `idempotency state=business_refused and terminal (got ${idem12 && idem12.state})`);

  // ══ RECONCILIATION ══════════════════════════════════════════════════════
  console.log('\n  [RECONCILE]');
  const end = await demandSnapshot();
  const settled = [m1.amount, m7.amount, m11.amount].reduce((a, b) => a + b, 0);
  check(end.paid === before.paid + settled,
    `total credited = ${settled} across exactly 3 settlements (paid ${before.paid} -> ${end.paid})`);
  check(end.total === before.total, `demand count unchanged (${before.total} -> ${end.total}) — no duplicates created`);

  // ── THE LEDGER MUST AGREE WITH THE MONEY ───────────────────────────────
  // Every rupee credited to demands must appear as a fee-voucher debit, and
  // nothing beyond it. This is the tie-out that makes the accounting stage a
  // real link in the chain instead of a side effect nobody checks: money in
  // == money posted, across the whole run.
  const jeAll = await accountingDocs();
  const feeVouchers = jeAll.filter((j) => String(j.voucher_type || '') === 'Fee');
  // DELTA, not absolute: this database may already hold vouchers from another
  // tenant or an earlier run, and neither is a defect. What must be exactly 3
  // is what THIS run posted.
  check(feeVouchers.length - baselineVouchers === 3,
    `exactly 3 fee vouchers posted by this run (got ${feeVouchers.length - baselineVouchers}) — `
    + 'a fourth means a refused or retried payment also posted to the ledger');
  // Debits posted BY THIS RUN, as a delta over the baseline total.
  const postedDr = feeDrTotal(jeAll) - baselineFeeDr;
  check(Math.abs(postedDr - settled) < 0.005,
    `ledger debits ${postedDr.toFixed(2)} == money credited to demands ${settled.toFixed(2)} `
    + '— the accounting stage reconciles to the payments, not merely to itself');
  const voucherNos = feeVouchers.map((j) => j.voucher_no);
  check(new Set(voucherNos).size === voucherNos.length,
    `no duplicate voucher number (${voucherNos.join(', ')})`);

  const receipts = await allReceipts();
  const byPayment = {};
  receipts.forEach((r) => { const k = String(r.paymentId || ''); if (k) byPayment[k] = (byPayment[k] || 0) + 1; });
  const dupes = Object.entries(byPayment).filter(([, n]) => n > 1);
  check(dupes.length === 0, `no gateway payment has more than one receipt (${JSON.stringify(dupes)})`);

  const openClaims = (await claimDocs()).filter((c) => c.status === 'in_progress' || c.token);
  check(openClaims.length === 0, `no payment claim left held (${openClaims.length})`);

  const idem = await idempotency();
  const retryLeft = idem.filter((d) => d.state === 'retry' && !d.processed_at);
  check(retryLeft.length === 0,
    `no attempt left permanently in 'retry' (${retryLeft.length}) — every scenario reached a terminal state`);

  // Tenant isolation: nothing this run wrote may name another school.
  // ── ISOLATION, MEASURED ON WHAT THIS RUN DID ───────────────────────────
  // Was: count every document whose schoolId != SCHOOL and demand zero. That
  // passes trivially when the sibling tenant is EMPTY — which is the only way
  // it had ever been run — and fails spuriously the moment it is not. Neither
  // outcome says anything about isolation.
  const afterIds = await moneyDocIds();
  const created = [...afterIds.keys()].filter((k) => !baselineIds.has(k));
  const foreignCreated = created.filter((k) => {
    const owner = afterIds.get(k);
    return owner && owner !== SCHOOL;
  });
  check(foreignCreated.length === 0,
    `this run created ${created.length} money document(s), NONE of them in another tenant `
    + `(${foreignCreated.length} foreign: ${foreignCreated.slice(0, 3).join(', ')})`);
  check(created.length > 0,
    'this run actually created money documents — an isolation check over an empty delta '
    + 'proves nothing, which is exactly how the previous version passed');

  // A sibling tenant's documents must also be UNCHANGED, not merely un-created.
  const siblingChanged = [...afterIds.keys()].filter((k) =>
    baselineIds.has(k) && baselineIds.get(k) !== SCHOOL && afterIds.get(k) !== baselineIds.get(k));
  check(siblingChanged.length === 0,
    `no pre-existing document changed tenant ownership (${siblingChanged.length})`);

  console.log(`\n${failures === 0 ? 'ALL E2E CHECKS PASSED' : failures + ' E2E CHECK(S) FAILED'}\n`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('E2E ABORTED: ' + e.stack); process.exit(1); });
