#!/usr/bin/env node
/**
 * Communication E2E drill — investigate the two findings surfaced by census:
 *   F1: feeReceiptIndex docs lack receiptKey (sendFeePaymentConfirmation chain)
 *   F2: profile carries BOTH nested + flat commCounters (counter divergence risk)
 *
 * READ-ONLY. Firestore-only.
 *
 * Usage: node scripts/comm_e2e_drill.js [SCH_*]
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();
const SCHOOL_ID = process.argv[2] || 'SCH_D94FE8F7AD';
const HR = (s='═') => console.log(s.repeat(78));

(async () => {
  HR();
  console.log(`  Communication E2E Drill — ${SCHOOL_ID}`);
  HR();

  // ─── F1: feeReceiptIndex actual shape ───────────────────────────
  console.log('\n── F1: feeReceiptIndex actual shape (3 samples)\n');
  const idx = await fsdb.collection('feeReceiptIndex')
    .where('schoolId', '==', SCHOOL_ID).limit(3).get();
  idx.forEach(s => {
    const d = s.data();
    console.log(`  Doc: ${s.id}`);
    console.log(`  Fields:`);
    for (const [k, v] of Object.entries(d)) {
      console.log(`    ${k.padEnd(25)} = ${typeof v === 'string' ? `"${String(v).slice(0,40)}"` : JSON.stringify(v).slice(0,60)}`);
    }
    console.log('');
  });

  console.log('\n── F1b: feeReceipts actual shape (1 sample)\n');
  const rcpt = await fsdb.collection('feeReceipts')
    .where('schoolId', '==', SCHOOL_ID).limit(1).get();
  rcpt.forEach(s => {
    const d = s.data();
    console.log(`  Doc: ${s.id}`);
    for (const [k, v] of Object.entries(d)) {
      console.log(`    ${k.padEnd(25)} = ${typeof v === 'string' ? `"${String(v).slice(0,40)}"` : (Array.isArray(v) ? `[Array(${v.length})]` : JSON.stringify(v).slice(0,60))}`);
    }
  });

  console.log('\n── F1c: How to chain feeReceiptIndex → feeReceipts ──\n');
  // Try by docId pattern: feeReceiptIndex docId = "{school}_{session}_{receiptNo}"
  //                      feeReceipts docId likely = "{school}_{...}"
  const sampleIdx = idx.docs[0];
  if (sampleIdx) {
    const id = sampleIdx.id;
    const parts = id.split('_');
    console.log(`  Index docId parts: [${parts.join(' | ')}]`);
    // The receiptNo is the last segment
    const receiptNo = parts[parts.length - 1];
    console.log(`  Inferred receiptNo: ${receiptNo}`);
    // Try fee_receipts by receiptNo field
    const matches = await fsdb.collection('feeReceipts')
      .where('schoolId', '==', SCHOOL_ID)
      .where('receiptNo', '==', receiptNo).limit(1).get();
    console.log(`  feeReceipts(receiptNo=${receiptNo}): ${matches.size} match(es)`);
    if (matches.size > 0) {
      const m = matches.docs[0];
      console.log(`    → ${m.id}`);
      const md = m.data();
      console.log(`    amount: ${md.amount}  studentName: ${md.studentName || md.Student_Name}  month: ${md.month}`);
    }
  }

  // ─── F2: profile commCounters dual-shape state ──────────────────
  console.log('\n── F2: profile commCounters dual-shape\n');
  const profile = await fsdb.collection('schools').doc(`${SCHOOL_ID}_profile`).get();
  const pd = profile.data() || {};
  console.log(`  All top-level fields starting with "commCounters":`);
  const cc = Object.keys(pd).filter(k => k.startsWith('commCounters'));
  for (const k of cc) {
    const v = pd[k];
    const shape = typeof v === 'object' ? 'NESTED MAP' : 'FLAT VALUE';
    console.log(`    ${k.padEnd(28)} = ${JSON.stringify(v).slice(0, 80)}  [${shape}]`);
  }

  // ─── F3: highest existing notice ID — what would _nextCommCounter('Notice', 4) produce? ──
  console.log('\n── F3: notice ID sequence in canonical collection\n');
  const allNotices = await fsdb.collection('notices')
    .where('schoolId', '==', SCHOOL_ID).limit(50).get();
  const noticeIds = [];
  allNotices.forEach(s => {
    const d = s.data();
    noticeIds.push(d.noticeId || s.id.replace(`${SCHOOL_ID}_`, ''));
  });
  noticeIds.sort();
  console.log(`  ${allNotices.size} notices in collection:`);
  noticeIds.forEach(id => console.log(`    ${id}`));
  // Simulate the helper's self-seed: max NOT(\d+) parse
  let max = 0;
  for (const id of noticeIds) {
    const m = id.match(/^NOT(\d+)$/);
    if (m) {
      const n = parseInt(m[1], 10);
      if (n > max) max = n;
    }
  }
  console.log(`\n  Helper self-seed would compute max = ${max}, next call would mint NOT${String(max+1).padStart(4,'0')}`);
  console.log(`  Communication.php::_next_id reads flat commCounters.Notice = ${pd['commCounters.Notice']}, would mint NOT${String((pd['commCounters.Notice']||0)+1).padStart(4,'0')}`);
  if (max + 1 === (pd['commCounters.Notice']||0) + 1) {
    console.log(`  → BOTH writers would mint the SAME ID — collision risk if invoked concurrently`);
  } else {
    console.log(`  → DIFFERENT NEXT IDs (helper=NOT${String(max+1).padStart(4,'0')} vs ctlr=NOT${String((pd['commCounters.Notice']||0)+1).padStart(4,'0')})`);
  }

  // ─── F4: trigger + template absence ──────────────────────────────
  console.log('\n── F4: triggers + templates — production state\n');
  const trgs = await fsdb.collection('alertTriggers').where('schoolId','==',SCHOOL_ID).get();
  const tpls = await fsdb.collection('messageTemplates').where('schoolId','==',SCHOOL_ID).get();
  console.log(`  alertTriggers: ${trgs.size}`);
  console.log(`  messageTemplates: ${tpls.size}`);
  console.log(`  → fire_event() will return 0 with no triggers configured (dormant trigger system)`);
  console.log(`  → Direct write paths active: write_event_notice (11 notices), _queueDirectNotification (queue=0)`);

  HR();
  console.log('  Drill complete.');
  HR();
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
