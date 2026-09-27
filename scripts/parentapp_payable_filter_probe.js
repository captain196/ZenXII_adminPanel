/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PARENT APP PAYABLE FILTER — the negative control, on real data
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The Parent App must never offer money the school has already decided not to
 * collect. `FeeFirestoreRepository.PAYABLE_DEMAND_STATUSES = {"unpaid",
 * "partial"}` is an allowlist, and its docblock records that this path once
 * filtered `status != "paid"` and consequently showed FROZEN and REVERSED
 * demands as dues a parent could act on.
 *
 * An allowlist in source is an argument, not evidence. This flips real demands
 * to `frozen` and `reversed` on the fixture tenant and states exactly what the
 * app must show afterwards, so the assertion can be checked against the
 * handset rather than against the code that is supposed to implement it.
 *
 * The student stays ACTIVE on purpose: deactivating them would trip the
 * "no longer enrolled" gate and the Fees screen would never render, which
 * would prove the access gate rather than the payable filter.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 \
 *     node scripts/parentapp_payable_filter_probe.js --student=STU0001
 *
 * Writes ONLY to the emulator, and only demand statuses.
 */
const admin = require('firebase-admin');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
const loopback = (v) =>
  ['127.0.0.1', 'localhost', '::1'].includes(v.replace(/^https?:\/\//, '').split(':')[0]);
if (!EMU || !loopback(EMU)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  console.error('This script mutates demand statuses.');
  process.exit(1);
}

const SCHOOL  = arg('school', 'SCH_EMUFIXTURE1');
const STUDENT = arg('student', 'STU0001');

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

const money = (n) => '₹' + Number(n).toLocaleString('en-IN');

(async () => {
  console.log(`PARENT APP PAYABLE FILTER PROBE — ${STUDENT} @ ${SCHOOL}\n`);

  const snap = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL).where('studentId', '==', STUDENT).get();

  if (snap.empty) { console.error('no demands for this student'); process.exit(1); }

  const rows = snap.docs
    .map((d) => ({ id: d.id, ...(d.data() || {}) }))
    .sort((a, b) => String(a.periodKey || '').localeCompare(String(b.periodKey || '')));

  const before = rows.reduce((s, r) => s + Number(r.netAmount ?? r.amount ?? 0), 0);
  console.log(`  demands            : ${rows.length}`);
  console.log(`  total before       : ${money(before)}  (all unpaid)`);

  // Freeze the first four, reverse the next two. Both are states the school
  // has decided not to collect; neither may appear as payable.
  const frozen   = rows.slice(0, 4);
  const reversed = rows.slice(4, 6);

  const batch = db.batch();
  frozen.forEach((r)   => batch.update(db.collection('feeDemands').doc(r.id), { status: 'frozen' }));
  reversed.forEach((r) => batch.update(db.collection('feeDemands').doc(r.id), { status: 'reversed' }));
  await batch.commit();

  const frozenSum   = frozen.reduce((s, r) => s + Number(r.netAmount ?? r.amount ?? 0), 0);
  const reversedSum = reversed.reduce((s, r) => s + Number(r.netAmount ?? r.amount ?? 0), 0);
  const payable     = before - frozenSum - reversedSum;

  console.log(`\n  frozen             : ${frozen.length} demand(s)  ${money(frozenSum)}`);
  frozen.forEach((r) => console.log(`      ${r.period ?? r.periodKey} ${r.feeHead} ${money(r.netAmount ?? 0)}`));
  console.log(`  reversed           : ${reversed.length} demand(s)  ${money(reversedSum)}`);
  reversed.forEach((r) => console.log(`      ${r.period ?? r.periodKey} ${r.feeHead} ${money(r.netAmount ?? 0)}`));

  console.log(`\n  ── WHAT THE PARENT APP MUST NOW SHOW`);
  console.log(`     payable total    : ${money(payable)}   (was ${money(before)})`);
  console.log(`     payable rows     : ${rows.length - frozen.length - reversed.length}`);
  console.log(`     it must NOT list : ${frozen.length} frozen + ${reversed.length} reversed`);
  console.log(`\n  If the app still shows ${money(before)}, the payable allowlist is not`);
  console.log('  being applied and a parent can pay money the school wrote off.');
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
