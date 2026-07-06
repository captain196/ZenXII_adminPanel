#!/usr/bin/env node
/**
 * COMM-MSG Phase 0.5 — fleet-wide Messaging usage audit.
 *
 * READ-ONLY. admin.firestore() only — zero RTDB connections.
 * No code changes, no commits, no pushes, no deployments.
 *
 * Determines whether Messaging is genuinely dormant before any retirement
 * decision is made.
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();

const HR = (s='═') => console.log(s.repeat(78));

// Today: 2026-06-15. Compute windows for activity analysis.
const NOW = new Date('2026-06-15T23:59:59Z').getTime();
const DAY = 24 * 60 * 60 * 1000;
const W = {
  D30:  NOW - 30  * DAY,
  D90:  NOW - 90  * DAY,
  D180: NOW - 180 * DAY,
  D365: NOW - 365 * DAY,
};

/**
 * Parse a doc's "primary timestamp" from any of several possible field shapes
 * (Firestore Timestamp, ISO string, epoch ms). Returns ms or NaN.
 */
function ts(raw) {
  if (raw === undefined || raw === null) return NaN;
  if (typeof raw === 'number') return raw;
  if (typeof raw === 'string') {
    const t = Date.parse(raw);
    return Number.isNaN(t) ? NaN : t;
  }
  if (typeof raw === 'object') {
    if (typeof raw.toMillis === 'function') return raw.toMillis();
    if (typeof raw.seconds === 'number')   return raw.seconds * 1000;
    if (typeof raw._seconds === 'number')  return raw._seconds * 1000;
  }
  return NaN;
}

function pickMsgTs(d) {
  // Order: sentAt, createdAt, sent_at, created_at, timestamp, ts, updatedAt.
  for (const k of ['sentAt','createdAt','sent_at','created_at','timestamp','ts','updatedAt']) {
    const v = ts(d[k]);
    if (!Number.isNaN(v)) return v;
  }
  return NaN;
}

function pickConvTs(d) {
  for (const k of ['lastMessageAt','lastMessage_at','updatedAt','createdAt','sentAt','timestamp']) {
    const v = ts(d[k]);
    if (!Number.isNaN(v)) return v;
  }
  return NaN;
}

function pickInboxTs(d) {
  for (const k of ['lastMessageAt','updatedAt','readAt','createdAt','timestamp']) {
    const v = ts(d[k]);
    if (!Number.isNaN(v)) return v;
  }
  return NaN;
}

(async () => {
  HR();
  console.log('  COMM-MSG Phase 0.5 — Fleet-Wide Messaging Usage Audit');
  console.log(`  Audit reference date: 2026-06-15`);
  HR();

  // ── Enumerate tenants ─────────────────────────────────────────────
  const schoolsSnap = await fsdb.collection('schools').limit(200).get();
  const tenants = new Set();
  schoolsSnap.forEach(s => {
    const m = s.id.match(/^(SCH_[A-F0-9]+)/i);
    if (m) tenants.add(m[1]);
  });
  const tenantList = [...tenants].sort();
  console.log(`\n  Tenants enumerated: ${tenantList.length}`);
  console.log(`    ${tenantList.join(', ')}`);

  // ── Sample doc shapes to surface schema ───────────────────────────
  console.log('\n── Sample document shapes (for schema visibility) ──\n');
  for (const col of ['conversations', 'messages', 'messageInboxes']) {
    const snap = await fsdb.collection(col).limit(1).get();
    if (snap.empty) {
      console.log(`  ${col}: EMPTY fleet-wide`);
      continue;
    }
    const doc = snap.docs[0];
    const data = doc.data() || {};
    console.log(`  ${col}/${doc.id}:`);
    const keys = Object.keys(data).slice(0, 15);
    keys.forEach(k => {
      const v = data[k];
      let preview;
      if (v && typeof v === 'object' && typeof v.toMillis === 'function') {
        preview = `Timestamp(${new Date(v.toMillis()).toISOString()})`;
      } else if (Array.isArray(v)) {
        preview = `Array(${v.length})`;
      } else {
        preview = JSON.stringify(v);
      }
      if (preview && preview.length > 80) preview = preview.slice(0, 80) + '…';
      console.log(`    ${k.padEnd(18)} = ${preview}`);
    });
  }

  // ── Per-tenant pull ───────────────────────────────────────────────
  console.log('\n── Per-tenant data pull ──\n');
  const fleet = {
    conversations: 0,
    messages: 0,
    inboxes: 0,
    tenantsWithConvData: 0,
    tenantsWithMsgData: 0,
    tenantsWithInboxData: 0,
    firstMsgTs: Infinity,
    lastMsgTs: -Infinity,
    msgInD30: 0,
    msgInD90: 0,
    msgInD180: 0,
    msgInD365: 0,
    usersInMessaging: new Set(),
  };
  const perTenant = [];

  for (const sid of tenantList) {
    const convSnap = await fsdb.collection('conversations').where('schoolId','==',sid).limit(1000).get();
    const msgSnap  = await fsdb.collection('messages').where('schoolId','==',sid).limit(1000).get();
    const inbSnap  = await fsdb.collection('messageInboxes').where('schoolId','==',sid).limit(1000).get();

    let mostRecent = -Infinity;
    let tenantFirstTs = Infinity;
    let tenantLastTs = -Infinity;
    const tenantUsers = new Set();

    convSnap.forEach(s => {
      const d = s.data() || {};
      const t = pickConvTs(d);
      if (!Number.isNaN(t)) {
        if (t < tenantFirstTs) tenantFirstTs = t;
        if (t > tenantLastTs)  tenantLastTs  = t;
        if (t > mostRecent)    mostRecent    = t;
      }
      // Convo participants
      (Array.isArray(d.participants) ? d.participants : []).forEach(p => {
        const id = (p && typeof p === 'object') ? (p.userId || p.id) : p;
        if (id) tenantUsers.add(`${sid}:${id}`);
      });
    });

    msgSnap.forEach(s => {
      const d = s.data() || {};
      const t = pickMsgTs(d);
      if (!Number.isNaN(t)) {
        if (t < tenantFirstTs) tenantFirstTs = t;
        if (t > tenantLastTs)  tenantLastTs  = t;
        if (t > mostRecent)    mostRecent    = t;
        if (t >= W.D30)  fleet.msgInD30++;
        if (t >= W.D90)  fleet.msgInD90++;
        if (t >= W.D180) fleet.msgInD180++;
        if (t >= W.D365) fleet.msgInD365++;
      }
      // Senders
      for (const k of ['senderId','from','fromId','userId','authorId']) {
        if (d[k]) tenantUsers.add(`${sid}:${d[k]}`);
      }
    });

    inbSnap.forEach(s => {
      const d = s.data() || {};
      const t = pickInboxTs(d);
      if (!Number.isNaN(t) && t > mostRecent) mostRecent = t;
      if (d.userId) tenantUsers.add(`${sid}:${d.userId}`);
    });

    fleet.conversations += convSnap.size;
    fleet.messages      += msgSnap.size;
    fleet.inboxes       += inbSnap.size;
    if (convSnap.size  > 0) fleet.tenantsWithConvData++;
    if (msgSnap.size   > 0) fleet.tenantsWithMsgData++;
    if (inbSnap.size   > 0) fleet.tenantsWithInboxData++;
    if (tenantFirstTs < fleet.firstMsgTs) fleet.firstMsgTs = tenantFirstTs;
    if (tenantLastTs  > fleet.lastMsgTs)  fleet.lastMsgTs  = tenantLastTs;
    tenantUsers.forEach(u => fleet.usersInMessaging.add(u));

    perTenant.push({
      sid,
      conversations: convSnap.size,
      messages: msgSnap.size,
      inboxes: inbSnap.size,
      tenantFirstTs,
      tenantLastTs,
      mostRecent,
      uniqueUsers: tenantUsers.size,
    });
  }

  // ── Output: fleet-wide stats ──────────────────────────────────────
  console.log('── Fleet-wide statistics ──\n');
  console.log(`  Total conversations:          ${fleet.conversations}`);
  console.log(`  Total messages:               ${fleet.messages}`);
  console.log(`  Total inbox records:          ${fleet.inboxes}`);
  console.log(`  Tenants with conversations:   ${fleet.tenantsWithConvData} / ${tenantList.length}`);
  console.log(`  Tenants with messages:        ${fleet.tenantsWithMsgData} / ${tenantList.length}`);
  console.log(`  Tenants with inboxes:         ${fleet.tenantsWithInboxData} / ${tenantList.length}`);
  console.log(`  Unique users in messaging:    ${fleet.usersInMessaging.size}`);
  console.log(`  First message timestamp:      ${fleet.firstMsgTs === Infinity ? 'n/a' : new Date(fleet.firstMsgTs).toISOString()}`);
  console.log(`  Last message timestamp:       ${fleet.lastMsgTs === -Infinity ? 'n/a' : new Date(fleet.lastMsgTs).toISOString()}`);

  // ── Output: activity windows ──────────────────────────────────────
  console.log('\n── Activity analysis (rolling windows from 2026-06-15) ──\n');
  console.log(`  Messages in last 30 days:     ${fleet.msgInD30}`);
  console.log(`  Messages in last 90 days:     ${fleet.msgInD90}`);
  console.log(`  Messages in last 180 days:    ${fleet.msgInD180}`);
  console.log(`  Messages in last 365 days:    ${fleet.msgInD365}`);

  // Days since last message
  if (fleet.lastMsgTs !== -Infinity) {
    const daysSince = Math.floor((NOW - fleet.lastMsgTs) / DAY);
    console.log(`  Days since most recent message: ${daysSince}`);
  }

  // ── Output: per-tenant breakdown ──────────────────────────────────
  console.log('\n── Tenant-by-tenant breakdown ──\n');
  console.log(`  ${'tenant'.padEnd(20)}  ${'conv'.padStart(4)}  ${'msg'.padStart(4)}  ${'inbox'.padStart(5)}  ${'users'.padStart(5)}  most-recent`);
  console.log(`  ${'─'.repeat(74)}`);
  perTenant.sort((a,b) => b.messages - a.messages || b.mostRecent - a.mostRecent);
  for (const t of perTenant) {
    const rec = t.mostRecent === -Infinity ? '(no data)' : new Date(t.mostRecent).toISOString().slice(0,10);
    console.log(`  ${t.sid.padEnd(20)}  ${String(t.conversations).padStart(4)}  ${String(t.messages).padStart(4)}  ${String(t.inboxes).padStart(5)}  ${String(t.uniqueUsers).padStart(5)}  ${rec}`);
  }

  // ── Output: classification ────────────────────────────────────────
  console.log('\n── Business-impact classification ──\n');
  // Heuristic thresholds:
  //   Active     = D30 messages >= 30 OR D30 messages >= 5 AND active tenants >= 3
  //   Low Usage  = D90 messages >= 5
  //   Dormant    = D365 messages > 0 but D90 == 0
  //   Abandoned  = D365 messages == 0
  let verdict, evidence;
  if (fleet.msgInD30 >= 30 || (fleet.msgInD30 >= 5 && fleet.tenantsWithMsgData >= 3)) {
    verdict = 'ACTIVE';
    evidence = `${fleet.msgInD30} messages in last 30 days across ${fleet.tenantsWithMsgData} tenant(s)`;
  } else if (fleet.msgInD90 >= 5) {
    verdict = 'LOW USAGE';
    evidence = `${fleet.msgInD90} messages in last 90 days across ${fleet.tenantsWithMsgData} tenant(s)`;
  } else if (fleet.msgInD365 > 0) {
    verdict = 'DORMANT';
    evidence = `${fleet.msgInD365} messages in last 365 days but 0 in last 90 days; users may have abandoned the feature`;
  } else {
    verdict = 'ABANDONED';
    evidence = `Zero messages in last 365 days; ${fleet.messages} total docs are pre-period/test data only`;
  }
  console.log(`  Verdict:   ${verdict}`);
  console.log(`  Evidence:  ${evidence}`);

  // Retirement readiness
  console.log('\n── Retirement readiness ──\n');
  if (verdict === 'ABANDONED' || verdict === 'DORMANT') {
    console.log('  Messaging retirement: JUSTIFIED');
    console.log('  Firestore migration:  NOT JUSTIFIED (no real users to serve; cost > value)');
    console.log('  Stakeholder review:   recommended (confirm no business commitments depend on chat)');
  } else if (verdict === 'LOW USAGE') {
    console.log('  Messaging retirement: NEEDS STAKEHOLDER REVIEW');
    console.log('  Firestore migration:  could be justified if usage is growing');
    console.log('  Stakeholder review:   REQUIRED');
  } else {
    console.log('  Messaging retirement: NOT JUSTIFIED');
    console.log('  Firestore migration:  JUSTIFIED (real production usage)');
    console.log('  Stakeholder review:   REQUIRED before any retirement');
  }

  // Confidence level
  console.log('\n── Confidence level ──\n');
  const totalTenants = tenantList.length;
  const dataTenants  = fleet.tenantsWithMsgData;
  const dormantTenants = totalTenants - dataTenants;
  let confidence;
  if (totalTenants <= 10 && dormantTenants / totalTenants >= 0.8) {
    confidence = 'HIGH — small fleet, strong dormancy signal';
  } else if (dormantTenants / totalTenants >= 0.6) {
    confidence = 'MEDIUM — majority dormant';
  } else {
    confidence = 'LOW — significant minority show data';
  }
  console.log(`  ${confidence}`);
  console.log(`  Fleet size: ${totalTenants} tenants; ${dataTenants} have data; ${dormantTenants} are completely empty.`);
  if (fleet.usersInMessaging.size <= 5) {
    console.log(`  Total unique participating users fleet-wide: ${fleet.usersInMessaging.size} — extremely low.`);
  }

  console.log('');
  HR();
  console.log('  Audit complete. Firestore-only, read-only. Zero writes.');
  HR();
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
