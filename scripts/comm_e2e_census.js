#!/usr/bin/env node
/**
 * Communication module end-to-end cross-platform census.
 *
 * READ-ONLY. No writes. Probes the LIVE Firestore canonical collections
 * that the V7-migrated Communication_helper.php writes to (notices,
 * messageQueue, alertTriggers, messageTemplates) and that the Parent +
 * Teacher Kotlin apps read from. Reports population, schema shape,
 * recent activity, and cross-platform consumer compatibility.
 *
 * Usage: node scripts/comm_e2e_census.js [SCH_*]
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();

const SCHOOL_ID = process.argv[2] || process.env.SCHOOL_ID || 'SCH_D94FE8F7AD';
const SESSION   = process.env.SESSION_YEAR || '2026-27';

const HR = (s='=') => console.log(s.repeat(78));
const out = {};

async function probe(name, fn) {
  try {
    const t = Date.now();
    const r = await fn();
    out[name] = { ok: true, ms: Date.now() - t, ...r };
    return r;
  } catch (e) {
    out[name] = { ok: false, error: String(e.message || e).slice(0, 200) };
    return null;
  }
}

(async () => {
  HR('═');
  console.log(`  Communication E2E Census — ${SCHOOL_ID} / ${SESSION}`);
  HR('═');

  // ─── Section 1: Canonical-collection populations ─────────────────
  console.log('\n── Section 1: Canonical collection populations\n');

  for (const c of ['notices', 'messageQueue', 'alertTriggers', 'messageTemplates',
                   'feeReceiptIndex', 'feeReceipts', 'feeDemands', 'students',
                   'circulars', 'messageInboxes']) {
    await probe(`pop_${c}`, async () => {
      const snap = await fsdb.collection(c).where('schoolId', '==', SCHOOL_ID).limit(500).get();
      console.log(`  ${c.padEnd(20)}: ${String(snap.size).padStart(4)} docs`);
      return { count: snap.size };
    });
  }

  // ─── Section 2: Profile-doc counters (commCounters.{Queue,Notice}) ─
  console.log('\n── Section 2: Profile commCounters (canonical CAS source)\n');

  await probe('profile_counters', async () => {
    const doc = await fsdb.collection('schools').doc(`${SCHOOL_ID}_profile`).get();
    if (!doc.exists) {
      console.log(`  schools/${SCHOOL_ID}_profile: NOT FOUND`);
      return { exists: false };
    }
    const d = doc.data() || {};
    const cc = d.commCounters || {};
    console.log(`  commCounters (nested):`);
    for (const k of ['Queue', 'Notice', 'Conversation', 'Message', 'Circular', 'Template', 'Trigger', 'Log']) {
      const v = (cc[k] !== undefined) ? cc[k] : '(unset)';
      console.log(`    ${k.padEnd(14)}: ${v}`);
    }
    // also check legacy flat keys
    const flatKeys = Object.keys(d).filter(k => k.startsWith('commCounters.'));
    if (flatKeys.length > 0) {
      console.log(`  commCounters.* (legacy flat keys, if any):`);
      for (const fk of flatKeys) console.log(`    ${fk}: ${d[fk]}`);
    } else {
      console.log(`  commCounters.* (flat keys): none — canonical nested only`);
    }
    return { commCounters: cc, flatKeys };
  });

  // ─── Section 3: Recent notices — shape parity with mobile apps ───
  console.log('\n── Section 3: Recent notices — shape parity\n');

  await probe('notices_recent', async () => {
    const snap = await fsdb.collection('notices')
      .where('schoolId', '==', SCHOOL_ID)
      .limit(5).get();
    if (snap.empty) { console.log('  no recent notices'); return { count: 0 }; }
    console.log(`  ${snap.size} recent notices sampled:`);
    const expectedFields = ['noticeId','title','description','category','startDate','endDate',
                            'priority','targetGroup','status','source','createdAt','publishedAt'];
    let shapeOk = 0, shapeBad = 0;
    snap.forEach(s => {
      const d = s.data();
      const present = expectedFields.filter(f => d[f] !== undefined);
      const missing = expectedFields.filter(f => d[f] === undefined);
      const ok = missing.length === 0;
      if (ok) shapeOk++; else shapeBad++;
      console.log(`    ${s.id}`);
      console.log(`      title:    "${String(d.title||'').slice(0,60)}"`);
      console.log(`      category: ${d.category||'(absent)'}/${d.eventCategory||'-'}`);
      console.log(`      source:   ${d.source||'(absent)'}  status: ${d.status||'(absent)'}`);
      console.log(`      shape:    ${ok ? 'CANONICAL' : 'MISSING fields: ' + missing.join(',')}`);
    });
    console.log(`  Shape parity: ${shapeOk}/${shapeOk+shapeBad} canonical-shape`);
    return { shapeOk, shapeBad, total: snap.size };
  });

  // ─── Section 4: Recent messageQueue — shape parity ───────────────
  console.log('\n── Section 4: Recent messageQueue — shape parity\n');

  await probe('queue_recent', async () => {
    const snap = await fsdb.collection('messageQueue')
      .where('schoolId', '==', SCHOOL_ID)
      .limit(5).get();
    if (snap.empty) { console.log('  no queue items'); return { count: 0 }; }
    console.log(`  ${snap.size} queue items sampled:`);
    const expected = ['trigger_id','channel','recipient_type','recipient_id','title','message_body',
                      'status','priority','attempts','event_type','source'];
    let shapeOk=0, shapeBad=0;
    snap.forEach(s => {
      const d = s.data();
      const present = expected.filter(f => d[f] !== undefined);
      const missing = expected.filter(f => d[f] === undefined);
      const ok = missing.length === 0;
      if (ok) shapeOk++; else shapeBad++;
      console.log(`    ${s.id}`);
      console.log(`      trigger_id: ${d.trigger_id||'?'} channel: ${d.channel||'?'} status: ${d.status||'?'}`);
      console.log(`      recipient:  type=${d.recipient_type||'?'} id=${d.recipient_id||'?'}`);
      console.log(`      event:      ${d.event_type||'?'} source: ${d.source||'?'}`);
      console.log(`      shape:      ${ok ? 'CANONICAL' : 'MISSING fields: ' + missing.join(',')}`);
    });
    console.log(`  Shape parity: ${shapeOk}/${shapeOk+shapeBad} canonical-shape`);
    return { shapeOk, shapeBad, total: snap.size };
  });

  // ─── Section 5: Triggers + Templates — admin authoring ───────────
  console.log('\n── Section 5: Alert triggers + message templates (admin authored)\n');

  await probe('triggers_active', async () => {
    const snap = await fsdb.collection('alertTriggers')
      .where('schoolId', '==', SCHOOL_ID).limit(50).get();
    if (snap.empty) { console.log('  no triggers configured'); return { count: 0 }; }
    console.log(`  ${snap.size} triggers configured:`);
    let enabled = 0;
    snap.forEach(s => {
      const d = s.data();
      const isEnabled = !!d.enabled;
      if (isEnabled) enabled++;
      console.log(`    ${s.id.padEnd(38)} enabled=${isEnabled ? 'Y' : 'N'} event=${d.event_type||'?'} tpl=${d.template_id||'?'}`);
    });
    console.log(`  ${enabled} of ${snap.size} enabled`);
    return { total: snap.size, enabled };
  });

  await probe('templates_count', async () => {
    const snap = await fsdb.collection('messageTemplates')
      .where('schoolId', '==', SCHOOL_ID).limit(100).get();
    console.log(`  ${snap.size} templates configured`);
    return { count: snap.size };
  });

  // ─── Section 6: Recipient resolution (students canonical fields) ─
  console.log('\n── Section 6: Recipient resolution — students canonical contact fields\n');

  await probe('recipient_canonical', async () => {
    const snap = await fsdb.collection('students')
      .where('schoolId', '==', SCHOOL_ID).limit(5).get();
    let with_parent = 0, missing_contact = 0;
    snap.forEach(s => {
      const d = s.data();
      const fatherName = d.fatherName || d['Father Name'] || '';
      const phone      = d.phoneNumber || d.phone || d.Phone || '';
      const fatherPhone = d.fatherPhone || d['Father Phone'] || '';
      const ok = fatherName && (phone || fatherPhone);
      if (ok) with_parent++; else missing_contact++;
      console.log(`    ${s.id}: father="${fatherName.slice(0,20)}" phone="${(phone||fatherPhone).slice(0,15)}" ${ok ? 'OK' : 'MISSING'}`);
    });
    console.log(`  Parent contact resolvable: ${with_parent}/${snap.size}`);
    return { with_parent, missing_contact, total: snap.size };
  });

  // ─── Section 7: Fee receipt chain (sendFeePaymentConfirmation) ───
  console.log('\n── Section 7: Fee receipt chain — sendFeePaymentConfirmation path\n');

  await probe('receipt_chain', async () => {
    const idxSnap = await fsdb.collection('feeReceiptIndex')
      .where('schoolId', '==', SCHOOL_ID).limit(3).get();
    if (idxSnap.empty) { console.log('  no receipts'); return { count: 0 }; }
    let chainOk = 0;
    for (const i of idxSnap.docs) {
      const id = i.data();
      const receiptKey = id.receiptKey;
      if (!receiptKey) { console.log(`    ${i.id}: receiptKey missing`); continue; }
      const rcptDoc = await fsdb.collection('feeReceipts').doc(`${SCHOOL_ID}_${receiptKey}`).get();
      const ok = rcptDoc.exists;
      if (ok) chainOk++;
      console.log(`    ${i.id} → feeReceipts/${SCHOOL_ID}_${receiptKey}: ${ok ? 'CHAIN_OK' : 'BROKEN'}`);
    }
    console.log(`  Chain integrity: ${chainOk}/${idxSnap.size}`);
    return { chainOk, total: idxSnap.size };
  });

  // ─── Section 8: Fee reminder query (sendFeeReminder path) ────────
  console.log('\n── Section 8: Fee reminder query — sendFeeReminder path\n');

  await probe('reminder_query', async () => {
    const snap = await fsdb.collection('feeDemands')
      .where('schoolId', '==', SCHOOL_ID)
      .where('status', 'in', ['Pending', 'Overdue', 'unpaid', 'partial']).limit(10).get();
    console.log(`  ${snap.size} demands with reminder-eligible status:`);
    const byStudent = {};
    snap.forEach(s => {
      const d = s.data();
      byStudent[d.studentId] = (byStudent[d.studentId] || 0) + 1;
    });
    const students = Object.keys(byStudent);
    console.log(`  Affecting ${students.length} students`);
    for (const sid of students.slice(0, 3)) console.log(`    studentId=${sid}: ${byStudent[sid]} demands`);
    return { demands: snap.size, students: students.length };
  });

  // ─── Section 9: Cross-platform — circulars + inboxes (for context) ─
  console.log('\n── Section 9: Cross-platform context — circulars / inboxes\n');

  await probe('circulars_recent', async () => {
    const snap = await fsdb.collection('circulars')
      .where('schoolId', '==', SCHOOL_ID).limit(5).get();
    console.log(`  ${snap.size} recent circulars (admin-authored, mobile-readable)`);
    return { count: snap.size };
  });

  await probe('inboxes', async () => {
    const snap = await fsdb.collection('messageInboxes')
      .where('schoolId', '==', SCHOOL_ID).limit(5).get();
    console.log(`  ${snap.size} messageInboxes entries`);
    return { count: snap.size };
  });

  // ─── Summary ─────────────────────────────────────────────────────
  console.log('');
  HR('═');
  console.log('  Census Summary');
  HR('═');

  const sections = [
    ['Section 1 — canonical populations', ['pop_notices','pop_messageQueue','pop_alertTriggers','pop_messageTemplates']],
    ['Section 2 — profile counters',     ['profile_counters']],
    ['Section 3 — notices shape',        ['notices_recent']],
    ['Section 4 — queue shape',          ['queue_recent']],
    ['Section 5 — triggers + templates', ['triggers_active','templates_count']],
    ['Section 6 — recipient resolution', ['recipient_canonical']],
    ['Section 7 — receipt chain',        ['receipt_chain']],
    ['Section 8 — reminder query',       ['reminder_query']],
    ['Section 9 — cross-platform context',['circulars_recent','inboxes']],
  ];

  let totalOk = 0, totalErr = 0;
  for (const [label, keys] of sections) {
    const oks = keys.filter(k => out[k]?.ok).length;
    const errs = keys.filter(k => !out[k]?.ok).length;
    totalOk += oks; totalErr += errs;
    console.log(`  ${label.padEnd(40)} ${oks}/${keys.length} OK`);
  }

  console.log('');
  HR('═');
  console.log(`  OVERALL: ${totalOk} probes OK, ${totalErr} probes failed`);
  HR('═');

  process.exit(totalErr === 0 ? 0 : 2);
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
