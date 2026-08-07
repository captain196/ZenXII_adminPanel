#!/usr/bin/env node
/**
 * COMM-MSG final pre-execution stakeholder review.
 *
 * READ-ONLY. admin.firestore() only. Zero writes. Zero RTDB.
 *
 * Pulls every Messaging record at SCH_D94FE8F7AD and renders it
 * verbatim for the stakeholder to confirm test-traffic-only before
 * Coming Soon execution proceeds.
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();

const SCHOOL_ID = 'SCH_D94FE8F7AD';
const HR = (s='═') => console.log(s.repeat(78));

function isoFromAny(v) {
  if (v == null) return null;
  if (typeof v === 'number') return new Date(v).toISOString();
  if (typeof v === 'string') { const t = Date.parse(v); return Number.isNaN(t) ? v : new Date(t).toISOString(); }
  if (typeof v === 'object') {
    if (typeof v.toMillis === 'function') return new Date(v.toMillis()).toISOString();
    if (typeof v.seconds === 'number')    return new Date(v.seconds * 1000).toISOString();
    if (typeof v._seconds === 'number')   return new Date(v._seconds * 1000).toISOString();
  }
  return null;
}

(async () => {
  HR();
  console.log(`  COMM-MSG Final Pre-Execution Stakeholder Review`);
  console.log(`  Tenant: ${SCHOOL_ID}`);
  console.log(`  Audit reference date: 2026-06-15`);
  HR();

  // ── Conversations ────────────────────────────────────────────────
  console.log('\n══ CONVERSATIONS ══\n');
  const convSnap = await fsdb.collection('conversations').where('schoolId','==',SCHOOL_ID).get();
  const conversations = [];
  convSnap.forEach(s => {
    conversations.push({ docId: s.id, data: s.data() || {} });
  });
  conversations.sort((a,b) => (a.data.createdAt || 0) - (b.data.createdAt || 0));

  for (let i = 0; i < conversations.length; i++) {
    const { docId, data: c } = conversations[i];
    console.log(`  Conversation ${i+1}:  ${docId}`);
    console.log(`    conversationId :   ${c.conversationId || '(unset)'}`);
    console.log(`    type           :   ${c.type || '(unset)'}`);
    console.log(`    status         :   ${c.status || '(unset)'}`);
    console.log(`    title          :   "${c.title || ''}"`);
    console.log(`    context        :   ${JSON.stringify(c.context || {})}`);
    console.log(`    createdAt      :   ${isoFromAny(c.createdAt)}`);
    console.log(`    createdBy      :   ${c.createdBy || '(unset)'}`);
    console.log(`    participantIds :   ${JSON.stringify(c.participantIds || [])}`);
    console.log(`    participants   :   ${JSON.stringify(c.participants || {})}`);
    console.log(`    participantNames:  ${JSON.stringify(c.participantNames || {})}`);
    console.log(`    lastMessage    :   "${c.lastMessage || ''}"`);
    console.log(`    lastMessageTime:   ${isoFromAny(c.lastMessageTime)}`);
    console.log(`    lastSenderId   :   ${c.lastSenderId || '(unset)'}`);
    console.log(`    lastSenderName :   ${c.lastSenderName || '(unset)'}`);
    console.log('');
  }

  // ── Messages ─────────────────────────────────────────────────────
  console.log('══ MESSAGES (verbatim text) ══\n');
  const msgSnap = await fsdb.collection('messages').where('schoolId','==',SCHOOL_ID).get();
  const messages = [];
  msgSnap.forEach(s => messages.push({ docId: s.id, data: s.data() || {} }));
  messages.sort((a,b) => (a.data.timestamp || 0) - (b.data.timestamp || 0));

  for (let i = 0; i < messages.length; i++) {
    const { docId, data: m } = messages[i];
    console.log(`  Message ${i+1}:  ${docId}`);
    console.log(`    messageId      :   ${m.messageId || '(unset)'}`);
    console.log(`    conversationId :   ${m.conversationId || '(unset)'}`);
    console.log(`    timestamp      :   ${isoFromAny(m.timestamp)}`);
    console.log(`    senderId       :   ${m.senderId || '(unset)'}`);
    console.log(`    senderName     :   "${m.senderName || ''}"`);
    console.log(`    senderRole     :   ${m.senderRole || '(unset)'}`);
    console.log(`    type           :   ${m.type || '(unset)'}`);
    console.log(`    attachmentUrl  :   "${m.attachmentUrl || ''}"`);
    console.log(`    attachmentName :   "${m.attachmentName || ''}"`);
    console.log(`    readBy         :   ${JSON.stringify(m.readBy || {})}`);
    console.log(`    TEXT           :   "${m.text || ''}"`);
    console.log('');
  }

  // ── Inboxes ──────────────────────────────────────────────────────
  console.log('══ INBOX RECORDS ══\n');
  const inbSnap = await fsdb.collection('messageInboxes').where('schoolId','==',SCHOOL_ID).get();
  const inboxes = [];
  inbSnap.forEach(s => inboxes.push({ docId: s.id, data: s.data() || {} }));

  for (let i = 0; i < inboxes.length; i++) {
    const { docId, data: ib } = inboxes[i];
    console.log(`  Inbox ${i+1}:  ${docId}`);
    console.log(`    userId         :   ${ib.userId || '(unset)'}`);
    console.log(`    role           :   ${ib.role || '(unset)'}`);
    console.log(`    conversationId :   ${ib.conversationId || '(unset)'}`);
    console.log(`    otherPartyId   :   ${ib.otherPartyId || '(unset)'}`);
    console.log(`    otherPartyName :   "${ib.otherPartyName || ib.otherName || ''}"`);
    console.log(`    otherPartyRole :   ${ib.otherPartyRole || '(unset)'}`);
    console.log(`    studentName    :   "${ib.studentName || ''}"`);
    console.log(`    studentClass   :   "${ib.studentClass || ib.className || ''}"`);
    console.log(`    section        :   "${ib.section || ''}"`);
    console.log(`    lastSenderId   :   ${ib.lastSenderId || '(unset)'}`);
    console.log(`    lastMessageType:   ${ib.lastMessageType || '(unset)'}`);
    console.log(`    unreadCount    :   ${ib.unreadCount === undefined ? '(unset)' : ib.unreadCount}`);
    console.log('');
  }

  // ── Participant identity resolution ──────────────────────────────
  console.log('══ PARTICIPANT IDENTITIES ══\n');
  // Collect every userId that appeared anywhere
  const userIds = new Set();
  conversations.forEach(c => {
    (c.data.participantIds || []).forEach(p => userIds.add(p));
    if (c.data.createdBy) userIds.add(c.data.createdBy);
    if (c.data.lastSenderId) userIds.add(c.data.lastSenderId);
  });
  messages.forEach(m => { if (m.data.senderId) userIds.add(m.data.senderId); });
  inboxes.forEach(ib => {
    if (ib.data.userId) userIds.add(ib.data.userId);
    if (ib.data.otherPartyId) userIds.add(ib.data.otherPartyId);
    if (ib.data.lastSenderId) userIds.add(ib.data.lastSenderId);
  });
  const sortedUsers = [...userIds].sort();
  console.log(`  Total unique user IDs across Messaging: ${sortedUsers.length}\n`);

  for (const uid of sortedUsers) {
    // Try resolving identity from staff (admin/teacher) then students (parent)
    let resolved = null, source = null;
    try {
      const staffSnap = await fsdb.collection('staff').doc(`${SCHOOL_ID}_${uid}`).get();
      if (staffSnap.exists) {
        const d = staffSnap.data() || {};
        resolved = { name: d.name || d.Name, role: d.role || d.Role, email: d.email || d.Email, phone: d.phoneNumber || d.phone };
        source = 'staff';
      }
    } catch (e) {}
    if (!resolved) {
      try {
        const stuSnap = await fsdb.collection('students').doc(`${SCHOOL_ID}_${uid}`).get();
        if (stuSnap.exists) {
          const d = stuSnap.data() || {};
          resolved = { name: d.name || d.Name, className: d.className, section: d.section,
                       fatherName: d.fatherName || d['Father Name'],
                       phone: d.phoneNumber || d.phone };
          source = 'students';
        }
      } catch (e) {}
    }
    if (resolved) {
      console.log(`  ${uid}  (resolved via ${source}):`);
      for (const [k, v] of Object.entries(resolved)) {
        if (v) console.log(`    ${k.padEnd(12)} = "${v}"`);
      }
    } else {
      console.log(`  ${uid}  (UNRESOLVED — neither staff nor students)`);
    }
    console.log('');
  }

  HR();
  console.log('  Report complete. Read-only, Firestore-only.');
  HR();
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
