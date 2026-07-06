#!/usr/bin/env node
/**
 * COMM-DEVICE Package 3A pre-execution validation probe.
 *
 * READ-ONLY. No writes anywhere.
 *
 * Compares the Firestore canonical userDevices collection against the
 * legacy RTDB Users/Devices subtree to determine whether Package 3A
 * (server-side Device + Push convergence) can ship safely without
 * losing any active device registrations.
 *
 * The Firestore-only architecture policy applies to PRODUCTION CODE.
 * Pre-migration validation legitimately reads from BOTH stores to
 * confirm parity; this is the only way to detect RTDB-only entries
 * that would silently be orphaned by a code cutover.
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

const HR = (s='═') => console.log(s.repeat(78));

function safeDeviceId(raw) {
  return String(raw).replace(/[^A-Za-z0-9_\-]/g, '_');
}

(async () => {
  HR();
  console.log('  COMM-DEVICE Package 3A — Pre-Execution Validation Probe');
  console.log('  Mode: READ-ONLY (validation requires reading both stores)');
  HR();

  // ── Step 1: Pull Firestore canonical userDevices ─────────────────
  console.log('\n── Step 1: Firestore userDevices (canonical) — fleet pull ──\n');
  const fsSnap = await fsdb.collection('userDevices').limit(1000).get();
  // Build a (userId, fcmToken) signature map keyed by token (the truly
  // unique identifier) and also by docId for shape audit.
  const fsByToken = new Map();
  const fsByUser  = new Map();
  const fsByDocId = new Map();
  const fsByTenant = {};
  fsSnap.forEach(d => {
    const x = d.data() || {};
    fsByDocId.set(d.id, x);
    if (x.fcmToken) fsByToken.set(String(x.fcmToken), { docId: d.id, ...x });
    if (x.userId) {
      if (!fsByUser.has(x.userId)) fsByUser.set(x.userId, []);
      fsByUser.get(x.userId).push({ docId: d.id, ...x });
    }
    fsByTenant[x.schoolId || '(no-schoolId)'] = (fsByTenant[x.schoolId || '(no-schoolId)'] || 0) + 1;
  });
  console.log(`  Firestore userDevices fleet count: ${fsSnap.size}`);
  console.log('  By tenant:');
  for (const [t, c] of Object.entries(fsByTenant).sort()) {
    console.log(`    ${t.padEnd(20)} : ${c}`);
  }
  console.log(`  Unique fcmTokens in Firestore: ${fsByToken.size}`);
  console.log(`  Unique users with at least one device in Firestore: ${fsByUser.size}`);

  // ── Step 2: Pull RTDB Users/Devices ──────────────────────────────
  console.log('\n── Step 2: RTDB Users/Devices subtree — fleet pull ──\n');
  const rtdbSnap = await rtdb.ref('Users/Devices').limitToFirst(10000).once('value');
  const rtdbVal = rtdbSnap.val() || {};
  const rtdbByToken = new Map();
  const rtdbByUser  = new Map();
  let rtdbDeviceCount = 0;
  for (const [userId, devices] of Object.entries(rtdbVal)) {
    if (!devices || typeof devices !== 'object') continue;
    for (const [deviceId, info] of Object.entries(devices)) {
      if (!info || typeof info !== 'object') continue;
      rtdbDeviceCount++;
      if (info.fcmToken) rtdbByToken.set(String(info.fcmToken), { userId, deviceId, ...info });
      if (!rtdbByUser.has(userId)) rtdbByUser.set(userId, []);
      rtdbByUser.get(userId).push({ deviceId, ...info });
    }
  }
  console.log(`  RTDB Users/Devices user-roots: ${Object.keys(rtdbVal).length}`);
  console.log(`  RTDB device records total:     ${rtdbDeviceCount}`);
  console.log(`  Unique fcmTokens in RTDB:      ${rtdbByToken.size}`);

  // ── Step 3: Parity analysis ──────────────────────────────────────
  console.log('\n── Step 3: Parity analysis ──\n');

  // a) RTDB-only fcmTokens (not in Firestore)
  const rtdbOnlyTokens = [];
  rtdbByToken.forEach((info, token) => {
    if (!fsByToken.has(token)) rtdbOnlyTokens.push({ token, ...info });
  });

  // b) FS-only fcmTokens (in Firestore, not in RTDB) — these are
  //    the post-Phase-8a entries; expected to be present.
  const fsOnlyTokens = [];
  fsByToken.forEach((info, token) => {
    if (!rtdbByToken.has(token)) fsOnlyTokens.push({ token, ...info });
  });

  // c) Both-stores tokens
  const bothTokens = [];
  fsByToken.forEach((info, token) => {
    if (rtdbByToken.has(token)) bothTokens.push({ token, fs: info, rtdb: rtdbByToken.get(token) });
  });

  console.log(`  Tokens in BOTH stores:            ${bothTokens.length}`);
  console.log(`  Tokens in Firestore only:         ${fsOnlyTokens.length}  (post-Phase-8a Firestore-first writes)`);
  console.log(`  Tokens in RTDB only:              ${rtdbOnlyTokens.length}  ${rtdbOnlyTokens.length > 0 ? '← would be ORPHANED by Package 3A cutover' : '— ZERO orphan risk'}`);

  if (rtdbOnlyTokens.length > 0) {
    console.log('\n  RTDB-only token details (would be lost by cutover):\n');
    rtdbOnlyTokens.slice(0, 20).forEach(t => {
      console.log(`    userId=${t.userId}  deviceId=${t.deviceId}  status=${t.status||'?'}  platform=${t.platform||'?'}  lastActive=${t.lastActive||'?'}`);
    });
    if (rtdbOnlyTokens.length > 20) console.log(`    ... and ${rtdbOnlyTokens.length - 20} more`);
  }

  // d) Per-user coverage — every user that has any RTDB device should
  //    also have at least one Firestore device. Identify users present
  //    only in RTDB.
  const rtdbOnlyUsers = [];
  const fsUserSet = new Set(fsByUser.keys());
  rtdbByUser.forEach((devices, userId) => {
    if (!fsUserSet.has(userId)) rtdbOnlyUsers.push({ userId, deviceCount: devices.length, devices });
  });

  // e) Per-user FS coverage of RTDB users
  const rtdbUserSet = new Set(rtdbByUser.keys());
  const fsOnlyUsers = [...fsUserSet].filter(u => !rtdbUserSet.has(u));

  console.log(`\n  Users with Firestore-only registrations:   ${fsOnlyUsers.length}`);
  console.log(`  Users with BOTH stores:                    ${[...rtdbUserSet].filter(u => fsUserSet.has(u)).length}`);
  console.log(`  Users with RTDB-only registrations:        ${rtdbOnlyUsers.length}  ${rtdbOnlyUsers.length > 0 ? '← these users would lose push delivery on cutover' : '— ZERO orphan-user risk'}`);

  if (rtdbOnlyUsers.length > 0) {
    console.log('\n  Users that would lose push after cutover:\n');
    rtdbOnlyUsers.slice(0, 20).forEach(u => {
      console.log(`    ${u.userId.padEnd(15)} — ${u.deviceCount} RTDB device(s); no Firestore record`);
      u.devices.slice(0, 2).forEach(d => {
        console.log(`      • ${d.deviceId}  status=${d.status||'?'}  platform=${d.platform||'?'}  token=${(d.fcmToken||'').slice(0,32)}...`);
      });
    });
    if (rtdbOnlyUsers.length > 20) console.log(`    ... and ${rtdbOnlyUsers.length - 20} more`);
  }

  // f) Shape audit — check all 8 canonical Firestore fields present
  console.log('\n── Step 4: Firestore userDevices shape audit ──\n');
  const requiredFields = ['schoolId','userId','deviceId','fcmToken','platform','status','lastActive','appRole'];
  let allFields = 0, missingFields = [];
  fsSnap.forEach(d => {
    const x = d.data() || {};
    const missing = requiredFields.filter(f => x[f] === undefined || x[f] === '');
    if (missing.length === 0) allFields++;
    else missingFields.push({ docId: d.id, missing });
  });
  console.log(`  Docs with all 8 required canonical fields: ${allFields} / ${fsSnap.size}`);
  if (missingFields.length > 0) {
    console.log(`  Docs missing required fields: ${missingFields.length}`);
    missingFields.slice(0, 5).forEach(m => console.log(`    ${m.docId}: missing [${m.missing.join(', ')}]`));
  } else {
    console.log(`  ✓ All Firestore userDevices docs are canonical-shape`);
  }

  // ── Step 5: Verdict summary ──────────────────────────────────────
  console.log('\n');
  HR();
  console.log('  VERDICT SUMMARY');
  HR();
  if (rtdbOnlyTokens.length === 0 && rtdbOnlyUsers.length === 0 && missingFields.length === 0) {
    console.log('  ✓ V1 (userDevices coverage):           PASS  — zero RTDB-only registrations');
    console.log('  ✓ Firestore shape canonical:          PASS  — all docs carry 8 required fields');
    console.log('');
    console.log('  → Package 3A cutover would NOT lose any active device or push delivery target.');
  } else {
    console.log('  ✗ V1 (userDevices coverage):           ISSUES FOUND');
    if (rtdbOnlyTokens.length > 0)  console.log(`     - ${rtdbOnlyTokens.length} RTDB-only fcmTokens (would orphan)`);
    if (rtdbOnlyUsers.length > 0)   console.log(`     - ${rtdbOnlyUsers.length} RTDB-only users (would lose push)`);
    if (missingFields.length > 0)   console.log(`     - ${missingFields.length} FS docs missing canonical fields`);
    console.log('');
    console.log('  → Package 3A REQUIRES a backfill step (one-shot per-tenant script)');
    console.log('    to migrate the RTDB-only entries into Firestore canonical shape');
    console.log('    BEFORE the Device_service.php cutover ships.');
  }
  HR();
  process.exit(0);
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
