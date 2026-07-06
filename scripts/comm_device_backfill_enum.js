#!/usr/bin/env node
/**
 * COMM-DEVICE P0.5 — pre-backfill enumeration probe.
 *
 * READ-ONLY. Identifies every RTDB-only device record and resolves
 * the tenant schoolId for each affected user from Firestore canonical
 * (staff / students collections). Output drives the backfill design.
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({
  credential: admin.credential.cert(SVC),
  databaseURL: 'https://graderadmin-default-rtdb.firebaseio.com',
});
const fsdb = admin.firestore();
const rtdb = admin.database();

function safeDeviceId(raw) {
  return String(raw).replace(/[^A-Za-z0-9_\-]/g, '_');
}

async function resolveUserTenant(userId) {
  // Try staff first (admins + teachers), then students.
  try {
    const sn = await fsdb.collection('staff').where('staffId', '==', userId).limit(1).get();
    if (!sn.empty) {
      const d = sn.docs[0].data();
      return { source: 'staff', schoolId: d.schoolId, role: d.role || d.Role, name: d.name || d.Name };
    }
  } catch (e) {}
  // Fallback: docId-prefix scan (some staff docs key on docId `{schoolId}_{userId}`).
  try {
    const sn = await fsdb.collection('staff').limit(500).get();
    for (const d of sn.docs) {
      const id = d.id;
      if (id.endsWith('_' + userId)) {
        const x = d.data();
        return { source: 'staff(docId)', schoolId: x.schoolId || id.replace('_' + userId, ''), role: x.role || x.Role, name: x.name || x.Name };
      }
    }
  } catch (e) {}
  // Students lookup.
  try {
    const sn = await fsdb.collection('students').where('userId', '==', userId).limit(1).get();
    if (!sn.empty) {
      const d = sn.docs[0].data();
      return { source: 'students', schoolId: d.schoolId, role: 'parent', name: d.name || d.Name };
    }
  } catch (e) {}
  try {
    const sn = await fsdb.collection('students').limit(500).get();
    for (const d of sn.docs) {
      const id = d.id;
      if (id.endsWith('_' + userId)) {
        const x = d.data();
        return { source: 'students(docId)', schoolId: x.schoolId || id.replace('_' + userId, ''), role: 'parent', name: x.name || x.Name };
      }
    }
  } catch (e) {}
  return null;
}

(async () => {
  console.log('═'.repeat(78));
  console.log('  COMM-DEVICE P0.5 — pre-backfill enumeration probe');
  console.log('═'.repeat(78));

  // ── Firestore canonical map (by token + docId) ───────────────────
  const fsSnap = await fsdb.collection('userDevices').limit(1000).get();
  const fsTokens = new Set();
  const fsDocIds = new Set();
  fsSnap.forEach(d => {
    const x = d.data() || {};
    if (x.fcmToken) fsTokens.add(String(x.fcmToken));
    fsDocIds.add(d.id);
  });

  // ── Walk RTDB Users/Devices ──────────────────────────────────────
  const rtdbSnap = await rtdb.ref('Users/Devices').limitToFirst(10000).once('value');
  const rtdbVal = rtdbSnap.val() || {};

  const orphanRecords = []; // RTDB records NOT mirrored in Firestore by either token or docId
  for (const [userId, devices] of Object.entries(rtdbVal)) {
    if (!devices || typeof devices !== 'object') continue;
    for (const [deviceId, info] of Object.entries(devices)) {
      if (!info || typeof info !== 'object') continue;
      const sdid = safeDeviceId(deviceId);
      const expectedDocId = `${userId}_${sdid}`;
      const tokenInFs = info.fcmToken && fsTokens.has(String(info.fcmToken));
      const docIdInFs = fsDocIds.has(expectedDocId);
      if (!tokenInFs && !docIdInFs) {
        orphanRecords.push({ userId, deviceId, sdid, expectedDocId, info });
      }
    }
  }

  console.log(`\n  RTDB-only device records to backfill: ${orphanRecords.length}`);

  // ── Resolve schoolId for every affected user ─────────────────────
  console.log('\n── Per-user tenant resolution + record detail ──\n');
  const byUser = {};
  for (const r of orphanRecords) {
    if (!byUser[r.userId]) byUser[r.userId] = { records: [], tenant: null };
    byUser[r.userId].records.push(r);
  }

  for (const userId of Object.keys(byUser).sort()) {
    const t = await resolveUserTenant(userId);
    byUser[userId].tenant = t;
    const records = byUser[userId].records;
    console.log(`  userId=${userId.padEnd(12)}  records=${records.length}  resolved=${t ? `${t.source} schoolId=${t.schoolId} role=${t.role||'?'} name="${t.name||'?'}"` : 'UNRESOLVED'}`);
    records.forEach(r => {
      const info = r.info;
      console.log(`    └─ deviceId=${r.deviceId.padEnd(38)} sdid=${r.sdid.padEnd(38)}`);
      console.log(`         status=${info.status||'?'} platform=${info.platform||'?'} token=${(info.fcmToken||'(none)').slice(0,40)}${(info.fcmToken||'').length>40?'...':''}`);
      console.log(`         lastActive=${info.lastActive||'?'} boundAt=${info.boundAt||'?'}`);
      const extra = Object.keys(info).filter(k => !['status','platform','fcmToken','lastActive','boundAt','deviceName','appVersion','os','blockedAt','staleAt'].includes(k));
      if (extra.length) console.log(`         extra-fields: [${extra.join(', ')}]`);
    });
  }

  // ── Backfill plan summary ────────────────────────────────────────
  console.log('\n── Backfill plan preview (no writes performed) ──\n');
  let writable = 0, unresolved = 0;
  for (const userId of Object.keys(byUser).sort()) {
    const u = byUser[userId];
    if (!u.tenant || !u.tenant.schoolId) {
      console.log(`  UNRESOLVED: ${userId} (${u.records.length} record(s)) — cannot determine schoolId; SKIP in backfill`);
      unresolved += u.records.length;
      continue;
    }
    for (const r of u.records) {
      console.log(`  WOULD WRITE: userDevices/${r.expectedDocId}`);
      console.log(`    schoolId=${u.tenant.schoolId}  userId=${userId}  deviceId=${r.deviceId}`);
      console.log(`    appRole=${u.tenant.role||'(unknown)'}  status=${r.info.status||'active'}  platform=${r.info.platform||'android'}`);
      console.log(`    fcmToken=${(r.info.fcmToken||'(none)').slice(0,40)}...`);
      writable++;
    }
  }
  console.log(`\n  Backfill scope:  ${writable} writable, ${unresolved} unresolved`);
  console.log(`  Total affected users: ${Object.keys(byUser).length}`);

  // ── Coverage check (which docs are duplicates?) ──────────────────
  console.log('\n── Sanity check: existing Firestore docs for affected users ──\n');
  for (const userId of Object.keys(byUser).sort()) {
    const existing = await fsdb.collection('userDevices').where('userId', '==', userId).limit(50).get();
    console.log(`  ${userId}: ${existing.size} existing Firestore doc(s) (${byUser[userId].records.length} would be added)`);
  }

  console.log('');
  console.log('═'.repeat(78));
  console.log('  Pre-backfill enumeration complete. No writes performed.');
  console.log('═'.repeat(78));
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
