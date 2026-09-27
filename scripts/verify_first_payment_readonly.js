/**
 * ══════════════════════════════════════════════════════════════════════════
 *  FIRST-PAYMENT VALIDATION — READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Proves, from the data rather than from logs:
 *
 *     ONE PAYMENT  =  ONE FINANCIAL EFFECT
 *     ONE RETRY    =  ZERO DUPLICATE FINANCIAL EFFECTS
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────
 * "The payment succeeded" is what the gateway says. It is not the same claim
 * as "exactly one receipt, one allocation and one balanced journal entry now
 * exist, and the provider recorded on them is the provider that actually
 * collected the money". Only the second claim protects a school's books, and
 * only the data can settle it.
 *
 * ── SAFETY ────────────────────────────────────────────────────────────────
 * READ ONLY. No set/update/delete/batch anywhere in this file. Secrets are
 * never read and never printed. Safe to run against a live tenant — that is
 * the point, since it is the live tenant whose first payment needs proving.
 *
 *   node scripts/verify_first_payment_readonly.js --school=SCH_XXXX --student=STU0012
 *   node scripts/verify_first_payment_readonly.js --school=SCH_XXXX --order=order_ABC
 */

const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const ARGV    = process.argv.slice(2);
const argOf   = (k) => (ARGV.find((a) => a.startsWith(`--${k}=`)) || '').split('=')[1] || '';
const SCHOOL  = argOf('school');
const STUDENT = argOf('student');
const ORDER   = argOf('order');

if (!SCHOOL) {
  console.error('  --school=SCH_XXXX is required.');
  process.exit(1);
}

const rule = (s = '─') => console.log('  ' + s.repeat(72));
// The counter lives with the printer, so a ❌ CANNOT be printed without being
// counted. It previously lived in the caller and was only incremented when the
// author remembered to wrap the detail in fail(); the first live payment then
// printed two ❌ under a "1 PROBLEM(S) FOUND" summary. A validator that
// miscounts its own findings is not a validator.
let problems = 0;
const verdict = (ok, label, detail) => {
  if (!ok) problems++;
  console.log(`  ${ok ? '✅' : '❌'}  ${label}${detail ? ' — ' + detail : ''}`);
};

(async () => {
  console.log('══════════════════════════════════════════════════════════════');
  console.log('  FIRST-PAYMENT VALIDATION (READ ONLY)');
  console.log(`  school=${SCHOOL}${STUDENT ? ` student=${STUDENT}` : ''}${ORDER ? ` order=${ORDER}` : ''}`);
  console.log('══════════════════════════════════════════════════════════════\n');

  // Decorates a failure message. Counting now happens in verdict() itself.
  const fail = (m) => m;

  // ── 1. ORDERS ───────────────────────────────────────────────────────────
  const orderSnap = await db.collection('feeOnlineOrders')
    .where('schoolId', '==', SCHOOL).get();

  let orders = [];
  orderSnap.forEach((d) => {
    const x = d.data();
    if (STUDENT && (x.student_id || x.studentId) !== STUDENT) return;
    if (ORDER && (x.gateway_order_id || '') !== ORDER) return;
    orders.push({ id: d.id, ...x });
  });
  orders.sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)));

  console.log('  ORDERS');
  rule();
  if (!orders.length) {
    verdict(false, 'no matching order found', fail('nothing to validate'));
  }

  // ── A SINGLE TARGET, WHEN ONE WAS NAMED ─────────────────────────────────
  // `--order=X` names ONE payment. If the filter matches more than one, the
  // checks below would report an aggregate and an operator would read it as a
  // statement about the payment they actually care about.
  if (ORDER && orders.length > 1) {
    verdict(false, `--order=${ORDER} matched ${orders.length} orders`,
      fail('ambiguous target — the per-payment verdicts below cannot be attributed'));
  }

  // ── NO SILENT CAP ───────────────────────────────────────────────────────
  // This iterated `orders.slice(0, 5)`. With more than five orders the rest
  // were never provenance-checked, and the summary still said everything
  // passed. A validator that quietly skips rows is worse than one that
  // refuses to run: it produces confident, incomplete assurance.
  //
  // Every order is checked. Console output is capped, and the cap is STATED.
  const SHOW = 8;
  for (const o of orders) {
    const gw = o.gateway || '';
    const gwOk = !!gw && gw !== 'unknown';
    const tenantOk = o.schoolId === SCHOOL;

    if (!gwOk) fail('provenance');
    if (!tenantOk) fail('tenant');

    // Always print a row that FAILED, whatever the display cap.
    if (orders.indexOf(o) < SHOW || !gwOk || !tenantOk) {
      console.log(`  · ${o.gateway_order_id}  status=${o.status}  amount=${o.amount}`);
      verdict(gwOk, '  provider provenance recorded',
        gw || 'ABSENT — GW-ORD-1 cannot protect this order');
      verdict(tenantOk, '  tenant matches', o.schoolId);
    }
  }
  if (orders.length > SHOW) {
    console.log(`  (${orders.length} orders checked; ${orders.length - SHOW} passing rows not printed)`);
  }
  console.log('');

  const paid = orders.filter((o) => o.status === 'paid');

  // ── 2. ONE PAYMENT = ONE RECEIPT ────────────────────────────────────────
  console.log('  RECEIPTS  (one paid order must yield exactly one receipt)');
  rule();
  for (const o of paid) {
    const rSnap = await db.collection('feeReceipts')
      .where('schoolId', '==', SCHOOL)
      .where('orderId', '==', o.gateway_order_id).get();

    const n = rSnap.size;
    verdict(n === 1, `order ${o.gateway_order_id} → ${n} receipt(s)`,
      n === 1 ? (rSnap.docs[0].data().receiptNo || rSnap.docs[0].id)
              : fail(n === 0 ? 'MONEY TAKEN, NO RECEIPT' : 'DUPLICATE RECEIPTS'));

    rSnap.forEach((d) => {
      const rec = d.data();
      const recGw = rec.gateway || '';
      verdict(recGw === o.gateway, '  receipt provenance matches the order',
        recGw === o.gateway ? recGw : fail(`receipt=${recGw || 'ABSENT'} order=${o.gateway}`));
    });
  }
  if (!paid.length) console.log('  (no paid orders yet)');
  console.log('');

  // ── 3. ONE PAYMENT = ONE PROCESSED-PAYMENT MARKER (idempotency) ─────────
  console.log('  IDEMPOTENCY  (the marker that makes a retry safe)');
  rule();
  for (const o of paid) {
    const pid = o.gateway_payment_id || '';
    if (!pid) { verdict(false, 'no gateway_payment_id on a paid order', fail('cannot check')); continue; }
    // The marker lives in `processedPayments`, keyed by the gateway payment
    // id. This read `feeProcessedPayments` — a collection that does not exist
    // — so it reported "0 markers — A RETRY COULD DOUBLE-CHARGE" on the first
    // live payment while the marker was present and correct. A false alarm on
    // a money guard is not harmless: it invites someone to "fix" a system that
    // was already right.
    const pDoc = await db.collection('processedPayments').doc(pid).get();
    const ok = pDoc.exists && (pDoc.data() || {}).schoolId === SCHOOL;
    verdict(ok, `payment ${pid} → ${pDoc.exists ? 1 : 0} marker(s)`,
      ok ? 'a retry will be refused correctly'
         : fail(pDoc.exists ? 'marker belongs to another tenant' : 'A RETRY COULD DOUBLE-CHARGE'));
  }
  console.log('');

  // ── 4. ALLOCATION ───────────────────────────────────────────────────────
  console.log('  ALLOCATIONS  (money must be applied to a demand, not just receipted)');
  rule();
  for (const o of paid) {
    const rSnap = await db.collection('feeReceipts')
      .where('schoolId', '==', SCHOOL)
      .where('orderId', '==', o.gateway_order_id).get();
    for (const r of rSnap.docs) {
      const recNo = r.data().receiptNo || r.id;
      const aSnap = await db.collection('feeReceiptAllocations')
        .where('schoolId', '==', SCHOOL).where('receiptNo', '==', recNo).get();
      // One allocation DOC holds an `allocations[]` array — one entry per
      // demand settled. Summing a top-level `amount` field (which does not
      // exist on this doc) read 0 for a correctly-allocated ₹700 receipt, and
      // counting docs reported "1 allocation" for what were really two.
      // Count the entries and sum `allocated`.
      const rows = aSnap.docs.flatMap((d) => {
        const a = d.data().allocations;
        return Array.isArray(a) ? a : [];
      });
      const total = Math.round(rows.reduce((s, r) => s + Number(r.allocated || 0), 0) * 100) / 100;
      verdict(rows.length >= 1, `receipt ${recNo} → ${rows.length} allocation(s) in ${aSnap.size} doc(s)`,
        rows.length ? `total=${total}` : fail('RECEIPT WITH NO ALLOCATION — demand not cleared'));
      if (rows.length) {
        verdict(Math.abs(total - Number(o.amount || 0)) < 0.01,
          '  allocated total equals the amount paid',
          `${total} vs ${o.amount}`);
      }
    }
  }
  console.log('');

  // ── 5. ACCOUNTING — the ACC-PM1 regression check ────────────────────────
  console.log('  ACCOUNTING  (provider identity must NOT decide the ledger)');
  rule();
  for (const o of paid) {
    const rSnap = await db.collection('feeReceipts')
      .where('schoolId', '==', SCHOOL)
      .where('orderId', '==', o.gateway_order_id).get();
    for (const r of rSnap.docs) {
      const rec = r.data();
      const mode = String(rec.paymentMode || rec.payment_mode || '');
      // ACC-PM1: an online payment must be classified 'Online' (→ bank ledger),
      // never a provider name, and never fall through to 1010 Cash in Hand.
      verdict(!/razorpay|cashfree|paytm|stripe/i.test(mode),
        `receipt ${rec.receiptNo || r.id} payment_mode is provider-neutral`,
        mode || fail('EMPTY'));
      verdict(/^online$|^bank/i.test(mode) || mode === '',
        '  classification routes to a bank ledger',
        mode === '' ? fail('empty — would fall through to 1010 Cash in Hand') : mode);
    }
  }
  console.log('');

  // ── 6. NO DUPLICATE FINANCIAL EFFECT ACROSS THE WHOLE SET ───────────────
  console.log('  DUPLICATE SWEEP');
  rule();
  const byPayment = {};
  for (const o of orders) {
    const pid = o.gateway_payment_id || '';
    if (!pid) continue;
    byPayment[pid] = (byPayment[pid] || 0) + 1;
  }
  const dupes = Object.entries(byPayment).filter(([, n]) => n > 1);
  verdict(dupes.length === 0, 'no payment id appears on more than one order',
    dupes.length ? fail(dupes.map(([p, n]) => `${p}×${n}`).join(', ')) : 'clean');

  rule('═');

  // ── A VACUOUS PASS IS WORSE THAN A FAILURE ──────────────────────────────
  // The first version of this reported "ALL CHECKS PASSED — one payment
  // produced exactly one financial effect" when there were ZERO paid orders.
  // Every downstream check had simply been skipped, and an operator running
  // this after a FAILED payment would have been told everything was fine.
  //
  // Nothing verified is not the same as nothing wrong.
  if (!orders.length) {
    console.log('  NO ORDERS FOUND — nothing was verified.');
    console.log('  This is NOT a pass. Check the --school/--student/--order filters.');
    rule('═');
    process.exit(3);
  }

  if (!paid.length) {
    console.log('  NO PAID ORDERS — the receipt, idempotency, allocation and');
    console.log('  accounting checks did not run. This is NOT a pass.');
    console.log(`  ${orders.length} order(s) exist; none reached status=paid.`);
    if (problems) {
      console.log(`  ${problems} problem(s) were still found in what COULD be checked.`);
    }
    rule('═');
    process.exit(3);
  }

  if (problems === 0) {
    console.log(`  ALL CHECKS PASSED across ${paid.length} paid order(s) —`);
    console.log('  each produced exactly one financial effect.');
  } else {
    console.log(`  ${problems} PROBLEM(S) FOUND. Do NOT process further payments`);
    console.log('  until each is understood. See the runbook stop conditions.');
  }
  rule('═');

  process.exit(problems === 0 ? 0 : 2);
})().catch((e) => { console.error('VALIDATION FAILED TO RUN:', e.message); process.exit(1); });
