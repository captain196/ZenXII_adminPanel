// One-off: pull the real Firestore state for the parent-app test student
// so we can generate concrete manual test cases with actual values
// (month names, amounts, receipt IDs, wallet balance).
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const NAME_HINT = (process.argv[2] || 'Hanuman').toLowerCase();

(async () => {
  const out = {};

  // ── 1. Find the student doc ──
  const studentsSnap = await db.collection('students').get();
  let student = null;
  studentsSnap.forEach(d => {
    const x = d.data();
    const nm = (x.name || x.studentName || '').toLowerCase();
    if (nm.includes(NAME_HINT) && !student) student = { id: d.id, data: x };
  });
  if (!student) { console.log('Student not found for hint:', NAME_HINT); process.exit(1); }

  const { id: docId, data: s } = student;
  const schoolId = s.schoolId || docId.split('_')[0];
  const studentId = s.studentId || docId.replace(schoolId + '_', '');

  out.student = {
    docId,
    schoolId,
    studentId,
    name: s.name || s.studentName,
    className: s.className,
    section: s.section,
    rollNo: s.rollNo,
    fatherName: s.fatherName,
    session: s.session,
    monthFee: s.monthFee,
  };

  // ── 2. Fee demands (pending + paid) ──
  const demandsSnap = await db.collection('feeDemands')
    .where('schoolId', '==', schoolId)
    .where('studentId', '==', studentId)
    .get();
  out.demands = [];
  demandsSnap.forEach(d => {
    const x = d.data();
    out.demands.push({
      id: d.id,
      period: x.period,
      period_key: x.period_key,
      fee_head: x.fee_head,
      net_amount: x.net_amount ?? x.netAmount,
      paid_amount: x.paid_amount ?? x.paidAmount,
      balance: x.balance,
      status: x.status,
      session: x.session,
    });
  });
  out.demands.sort((a,b) => (a.period_key || '').localeCompare(b.period_key || ''));

  // ── 3. Fee receipts ──
  const receiptsSnap = await db.collection('feeReceipts')
    .where('schoolId', '==', schoolId)
    .where('studentId', '==', studentId)
    .get();
  out.receipts = [];
  receiptsSnap.forEach(d => {
    const x = d.data();
    out.receipts.push({
      id: d.id,
      receiptNo: x.receiptNo,
      receiptKey: x.receiptKey,
      amount: x.amount,
      netAmount: x.netAmount,
      paymentMode: x.paymentMode,
      feeMonths: x.feeMonths,
      createdAt: x.createdAt,
      date: x.date,
      feeBreakdownCount: Array.isArray(x.feeBreakdown) ? x.feeBreakdown.length : 0,
    });
  });
  out.receipts.sort((a,b) => String(b.receiptNo || '').localeCompare(String(a.receiptNo || '')));

  // ── 4. Advance (wallet) balance ──
  const walletDoc = await db.collection('studentAdvanceBalances').doc(`${schoolId}_${studentId}`).get();
  out.wallet = walletDoc.exists ? { id: walletDoc.id, ...walletDoc.data() } : null;

  // ── 5. Gateway config for the school ──
  // Try a few session keys — parent app uses active session
  const session = s.session || out.demands[0]?.session || '2025-26';
  const gwDoc = await db.collection('feeSettings').doc(`${schoolId}_${session}_gateway`).get();
  out.gateway = gwDoc.exists
    ? { id: gwDoc.id, provider: gwDoc.data().provider, mode: gwDoc.data().mode,
        has_api_key: !!gwDoc.data().api_key, has_api_secret: !!gwDoc.data().api_secret,
        active: gwDoc.data().active }
    : { id: gwDoc.id, exists: false };

  // ── 6. Fee structure for this class/section ──
  const cls = s.className, sec = s.section;
  if (cls && sec && session) {
    const fsDoc = await db.collection('feeStructures').doc(`${schoolId}_${session}_${cls}_${sec}`).get();
    if (fsDoc.exists) {
      const fs = fsDoc.data();
      out.feeStructure = {
        id: fsDoc.id,
        totalAnnualFee: fs.totalAnnualFee,
        feeHeads: (fs.feeHeads || []).map(h => ({ name: h.name, amount: h.amount, frequency: h.frequency })),
      };
    } else {
      out.feeStructure = { id: fsDoc.id, exists: false };
    }
  }

  // ── 7. Online orders (failed / successful) ──
  const ordersSnap = await db.collection('feeOnlineOrders')
    .where('schoolId', '==', schoolId)
    .where('studentId', '==', studentId)
    .get();
  out.onlineOrders = [];
  ordersSnap.forEach(d => {
    const x = d.data();
    out.onlineOrders.push({
      id: d.id,
      status: x.status,
      amount: x.amount,
      gatewayOrderId: x.gatewayOrderId || x.gateway_order_id,
      createdAt: x.createdAt || x.created_at,
    });
  });

  console.log(JSON.stringify(out, null, 2));
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
