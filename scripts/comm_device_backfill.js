#!/usr/bin/env node
/**
 * COMM-DEVICE Package 3A — P0.5 backfill script.
 *
 * Migrates every RTDB-only device record at `Users/Devices/{userId}/{deviceId}`
 * into the Firestore canonical `userDevices/{userId}_{safeDeviceId}` collection
 * with the 8-field canonical shape + 3 provenance markers.
 *
 * Modes:
 *   (no flag)         dry-run — prints the planned writes; performs ZERO writes
 *   --apply           writes the planned docs to Firestore (idempotent, merge=true)
 *   --verify          runs the VP1..VP7 post-backfill validation checks
 *   --help            show usage
 *
 * Idempotency guarantees:
 *   • Every write is `set(..., { merge: true })` so re-running over an existing
 *     doc only updates listed fields; never blows away unrelated mobile-app data.
 *   • Per-doc no-op detection: if the existing Firestore doc already has the
 *     same fcmToken AND same lastActive AND all 8 canonical fields present,
 *     the write is skipped entirely (no Firestore mutation).
 *   • Per-batch marker `_backfillBatch="comm-device-p05-2026-06-15"` so the
 *     full set is queryable for verification or surgical rollback.
 *   • Reads RTDB but NEVER mutates it. Source of truth preserved end-to-end.
 *
 * Rollback:
 *   To undo a backfill, delete every doc in `userDevices` where
 *   `_backfillBatch == "comm-device-p05-2026-06-15"`. A standalone rollback
 *   script can be written if needed; for low-volume rollback the Firebase
 *   console works fine via the same query.
 *
 * Author: COMM-DEVICE Package 3A — P0.5
 * Created: 2026-06-15
 */

'use strict';

const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));

// ─── Configuration ────────────────────────────────────────────────
const SERVICE_ACCOUNT_PATH = path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
const RTDB_URL    = 'https://graderadmin-default-rtdb.firebaseio.com';
const BATCH_TAG   = 'comm-device-p05-2026-06-15';
const FS_COLLECTION = 'userDevices';
const RTDB_BASE_PATH = 'Users/Devices';

// Canonical fields the verifier-equivalent enforcement expects on every doc.
const CANONICAL_FIELDS = [
  'schoolId','userId','deviceId','fcmToken','platform','status','lastActive','appRole',
];

// ─── Bootstrap ────────────────────────────────────────────────────
const SVC = JSON.parse(fsLib.readFileSync(SERVICE_ACCOUNT_PATH, 'utf8'));
admin.initializeApp({
  credential: admin.credential.cert(SVC),
  databaseURL: RTDB_URL,
});
const fsdb = admin.firestore();
const rtdb = admin.database();

const HR = (s='═') => console.log(s.repeat(78));

// ─── Helpers ──────────────────────────────────────────────────────
function nowIso() {
  return new Date().toISOString();
}

function safeDeviceId(raw) {
  return String(raw).replace(/[^A-Za-z0-9_\-]/g, '_');
}

function normalizeRole(rawRole, fallback) {
  const r = String(rawRole || '').toLowerCase().trim();
  if (r === 'teacher')                        return 'teacher';
  if (r === 'admin' || r === 'principal')     return 'admin';
  if (r === 'school super admin' || r === 'school_super_admin' || r === 'ssa') return 'admin';
  if (r === 'parent' || r === 'student')      return 'parent';
  return fallback || 'teacher';
}

/**
 * Resolve schoolId + appRole + display name for a userId by querying
 * Firestore canonical collections (staff first, then students).
 * Returns { schoolId, appRole, name, source } or null when unresolved.
 */
async function resolveUserTenant(userId) {
  // Staff: by staffId field
  try {
    const sn = await fsdb.collection('staff').where('staffId', '==', userId).limit(1).get();
    if (!sn.empty) {
      const d = sn.docs[0].data();
      return {
        schoolId: d.schoolId,
        appRole:  normalizeRole(d.role || d.Role, 'teacher'),
        name:     d.name || d.Name || userId,
        source:   'staff(staffId)',
      };
    }
  } catch (e) {}
  // Staff: by docId suffix `{schoolId}_{userId}`
  try {
    const sn = await fsdb.collection('staff').limit(500).get();
    for (const d of sn.docs) {
      if (d.id.endsWith('_' + userId)) {
        const x = d.data();
        return {
          schoolId: x.schoolId || d.id.replace('_' + userId, ''),
          appRole:  normalizeRole(x.role || x.Role, 'teacher'),
          name:     x.name || x.Name || userId,
          source:   'staff(docId)',
        };
      }
    }
  } catch (e) {}
  // Students: by userId field
  try {
    const sn = await fsdb.collection('students').where('userId', '==', userId).limit(1).get();
    if (!sn.empty) {
      const d = sn.docs[0].data();
      return {
        schoolId: d.schoolId,
        appRole:  'parent',
        name:     d.name || d.Name || userId,
        source:   'students(userId)',
      };
    }
  } catch (e) {}
  // Students: by docId suffix
  try {
    const sn = await fsdb.collection('students').limit(500).get();
    for (const d of sn.docs) {
      if (d.id.endsWith('_' + userId)) {
        const x = d.data();
        return {
          schoolId: x.schoolId || d.id.replace('_' + userId, ''),
          appRole:  'parent',
          name:     x.name || x.Name || userId,
          source:   'students(docId)',
        };
      }
    }
  } catch (e) {}
  return null;
}

/**
 * Build the canonical Firestore payload from an RTDB record + resolved tenant.
 */
function buildPayload(userId, deviceId, info, tenant) {
  const payload = {
    schoolId:   tenant.schoolId,
    userId:     userId,
    deviceId:   deviceId,                          // original, unsanitized
    fcmToken:   String(info.fcmToken || ''),
    platform:   String(info.platform || 'android'),
    status:     String(info.status || 'active'),
    lastActive: String(info.lastActive || nowIso()),
    appRole:    tenant.appRole,
    // ── Provenance ──
    _backfilledAt:    nowIso(),
    _backfilledFrom:  'rtdb_users_devices',
    _backfillBatch:   BATCH_TAG,
  };
  // Optional preserved fields (carry over only if present)
  for (const k of ['boundAt','deviceName','appVersion','os','blockedAt','staleAt']) {
    if (info[k] !== undefined && info[k] !== null && info[k] !== '') {
      payload[k] = info[k];
    }
  }
  return payload;
}

/**
 * Decide whether the planned write is a no-op against the existing Firestore doc.
 * Returns { skip: bool, reason: string }.
 */
function planVsExisting(payload, existingData) {
  if (!existingData) return { skip: false, reason: 'no-existing-doc' };
  // If the existing doc already carries the same token + lastActive + all
  // canonical fields, the write would be a no-op — skip it.
  const sameToken      = (existingData.fcmToken || '') === (payload.fcmToken || '');
  const sameLastActive = (existingData.lastActive || '') === (payload.lastActive || '');
  const allCanonical   = CANONICAL_FIELDS.every(f =>
    existingData[f] !== undefined && existingData[f] !== '' && existingData[f] !== null
  );
  if (sameToken && sameLastActive && allCanonical) {
    return { skip: true, reason: 'identical-canonical-doc-already-present' };
  }
  return { skip: false, reason: 'existing-doc-needs-update' };
}

// ─── Main scan: discover the RTDB-only records ────────────────────
async function buildBackfillPlan() {
  // 1. Pull Firestore canonical map (by token + docId) for fast lookup.
  const fsSnap = await fsdb.collection(FS_COLLECTION).limit(2000).get();
  const fsTokens = new Set();
  const fsDocs   = new Map();   // docId -> data
  fsSnap.forEach(d => {
    const x = d.data() || {};
    if (x.fcmToken) fsTokens.add(String(x.fcmToken));
    fsDocs.set(d.id, x);
  });

  // 2. Walk RTDB; identify entries not mirrored in Firestore.
  const rtdbSnap = await rtdb.ref(RTDB_BASE_PATH).limitToFirst(10000).once('value');
  const rtdbVal  = rtdbSnap.val() || {};

  const orphans = [];
  for (const [userId, devices] of Object.entries(rtdbVal)) {
    if (!devices || typeof devices !== 'object') continue;
    for (const [deviceId, info] of Object.entries(devices)) {
      if (!info || typeof info !== 'object') continue;
      const sdid       = safeDeviceId(deviceId);
      const expectedId = `${userId}_${sdid}`;
      const tokenInFs  = info.fcmToken && fsTokens.has(String(info.fcmToken));
      const docInFs    = fsDocs.has(expectedId);
      if (!tokenInFs && !docInFs) {
        orphans.push({ userId, deviceId, sdid, expectedDocId: expectedId, info });
      }
    }
  }

  // 3. For each orphan, resolve tenant + build payload.
  const plan = [];
  for (const r of orphans) {
    const tenant = await resolveUserTenant(r.userId);
    if (!tenant || !tenant.schoolId) {
      plan.push({
        userId: r.userId, deviceId: r.deviceId, sdid: r.sdid,
        expectedDocId: r.expectedDocId,
        status: 'UNRESOLVED',
        reason: 'no schoolId resolvable from staff or students collection',
      });
      continue;
    }
    const payload  = buildPayload(r.userId, r.deviceId, r.info, tenant);
    const existing = fsDocs.get(r.expectedDocId);
    const decision = planVsExisting(payload, existing);
    plan.push({
      userId: r.userId, deviceId: r.deviceId, sdid: r.sdid,
      expectedDocId: r.expectedDocId,
      tenant, payload,
      action: decision.skip ? 'SKIP' : (existing ? 'UPDATE' : 'CREATE'),
      reason: decision.reason,
    });
  }

  // Summary counts
  const summary = {
    fleetFirestoreTotal: fsSnap.size,
    fleetRtdbDeviceCount: orphans.length + Array.from(fsTokens).length, // approx
    orphansFound: orphans.length,
    plannedCreates:  plan.filter(p => p.action === 'CREATE').length,
    plannedUpdates:  plan.filter(p => p.action === 'UPDATE').length,
    plannedSkips:    plan.filter(p => p.action === 'SKIP').length,
    plannedUnresolved: plan.filter(p => p.status === 'UNRESOLVED').length,
  };
  return { plan, summary, fsSnap, rtdbVal, fsTokens, fsDocs };
}

// ─── Render the dry-run report ────────────────────────────────────
function renderDryRun(planResult) {
  const { plan, summary } = planResult;
  HR();
  console.log('  COMM-DEVICE P0.5 — backfill DRY-RUN');
  console.log('  (no Firestore or RTDB writes will be performed)');
  console.log(`  batch tag: ${BATCH_TAG}`);
  HR();

  console.log(`\n  Firestore userDevices docs (pre):    ${summary.fleetFirestoreTotal}`);
  console.log(`  Orphan RTDB-only records found:      ${summary.orphansFound}`);
  console.log(`  Planned CREATEs (new docs):          ${summary.plannedCreates}`);
  console.log(`  Planned UPDATEs (existing doc merge):${summary.plannedUpdates}`);
  console.log(`  Planned SKIPs   (already canonical): ${summary.plannedSkips}`);
  console.log(`  UNRESOLVED      (no tenant context): ${summary.plannedUnresolved}`);

  if (summary.orphansFound === 0) {
    console.log('\n  ✓ Nothing to do — userDevices is fully canonical relative to RTDB.');
    return;
  }

  console.log('\n── Planned writes ──\n');
  // Group by user for readability
  const byUser = {};
  plan.forEach(p => { (byUser[p.userId] = byUser[p.userId] || []).push(p); });
  for (const userId of Object.keys(byUser).sort()) {
    const items = byUser[userId];
    const t = items[0].tenant;
    const header = t
      ? `${userId}  →  schoolId=${t.schoolId}  appRole=${t.appRole}  name="${t.name}"  source=${t.source}`
      : `${userId}  →  UNRESOLVED`;
    console.log(`  ${header}`);
    items.forEach(p => {
      if (p.status === 'UNRESOLVED') {
        console.log(`    UNRESOLVED  ${p.expectedDocId}  — ${p.reason}`);
        return;
      }
      console.log(`    ${p.action.padEnd(7)}  userDevices/${p.expectedDocId}`);
      console.log(`             deviceId=${p.deviceId}`);
      console.log(`             token=${(p.payload.fcmToken || '(empty)').slice(0,40)}${(p.payload.fcmToken||'').length>40?'...':''}`);
      console.log(`             status=${p.payload.status}  platform=${p.payload.platform}`);
      console.log(`             lastActive=${p.payload.lastActive}`);
      if (p.reason) console.log(`             reason: ${p.reason}`);
    });
    console.log('');
  }

  HR();
  console.log('  This was a DRY-RUN. Re-run with `--apply` to perform writes.');
  console.log('  All writes use Firestore set(..., { merge: true }) — idempotent and safe.');
  HR();
}

// ─── Apply mode ───────────────────────────────────────────────────
async function applyPlan(planResult) {
  const { plan, summary } = planResult;
  HR();
  console.log('  COMM-DEVICE P0.5 — backfill APPLY MODE');
  console.log(`  batch tag: ${BATCH_TAG}`);
  HR();

  if (summary.orphansFound === 0) {
    console.log('\n  Nothing to do — fully canonical.');
    return { written: 0, skipped: 0, failed: 0 };
  }

  let written = 0, skipped = 0, failed = 0;
  const failures = [];
  for (const p of plan) {
    if (p.status === 'UNRESOLVED') {
      skipped++;
      console.log(`  SKIP (unresolved): ${p.expectedDocId} — ${p.reason}`);
      continue;
    }
    if (p.action === 'SKIP') {
      skipped++;
      console.log(`  SKIP (no-op):      ${p.expectedDocId} — ${p.reason}`);
      continue;
    }
    try {
      await fsdb.collection(FS_COLLECTION).doc(p.expectedDocId).set(p.payload, { merge: true });
      written++;
      console.log(`  ${p.action.padEnd(7)}: ${p.expectedDocId}`);
    } catch (e) {
      failed++;
      const msg = e.message || String(e);
      failures.push({ docId: p.expectedDocId, err: msg.slice(0, 200) });
      console.log(`  FAILED:  ${p.expectedDocId} — ${msg.slice(0, 200)}`);
    }
  }

  console.log('\n── Apply summary ──');
  console.log(`  Written: ${written}`);
  console.log(`  Skipped: ${skipped}`);
  console.log(`  Failed:  ${failed}`);
  if (failures.length) {
    console.log('\n  Failure detail:');
    failures.forEach(f => console.log(`    ${f.docId}: ${f.err}`));
  }
  return { written, skipped, failed };
}

// ─── Verification: VP1–VP7 ────────────────────────────────────────
async function runVerification() {
  HR();
  console.log('  COMM-DEVICE P0.5 — POST-BACKFILL VERIFICATION (VP1..VP7)');
  console.log(`  batch tag: ${BATCH_TAG}`);
  HR();

  const out = {};

  // VP1: parity probe — no RTDB-only tokens, no RTDB-only users
  console.log('\n── VP1: Fleet-wide parity (Firestore vs RTDB) ──\n');
  {
    const fsSnap = await fsdb.collection(FS_COLLECTION).limit(2000).get();
    const fsTokens = new Set();
    const fsUsers  = new Set();
    fsSnap.forEach(d => {
      const x = d.data() || {};
      if (x.fcmToken) fsTokens.add(String(x.fcmToken));
      if (x.userId)   fsUsers.add(x.userId);
    });
    const rtdbSnap = await rtdb.ref(RTDB_BASE_PATH).limitToFirst(10000).once('value');
    const rtdbVal  = rtdbSnap.val() || {};
    let rtdbOnlyTokens = 0, rtdbOnlyUsers = 0;
    for (const [userId, devices] of Object.entries(rtdbVal)) {
      if (!devices || typeof devices !== 'object') continue;
      if (!fsUsers.has(userId)) rtdbOnlyUsers++;
      for (const [, info] of Object.entries(devices)) {
        if (!info || typeof info !== 'object') continue;
        if (info.fcmToken && !fsTokens.has(String(info.fcmToken))) rtdbOnlyTokens++;
      }
    }
    out.VP1 = { rtdbOnlyTokens, rtdbOnlyUsers, pass: rtdbOnlyTokens === 0 && rtdbOnlyUsers === 0 };
    console.log(`  RTDB-only tokens: ${rtdbOnlyTokens} (expected 0)`);
    console.log(`  RTDB-only users:  ${rtdbOnlyUsers} (expected 0)`);
    console.log(`  VP1: ${out.VP1.pass ? '✓ PASS' : '✗ FAIL'}`);
  }

  // VP2: userDevices fleet count delta sanity check (post: 57 expected)
  console.log('\n── VP2: Fleet-wide userDevices doc count ──\n');
  {
    const snap = await fsdb.collection(FS_COLLECTION).limit(2000).get();
    out.VP2 = { count: snap.size, expected: 57, pass: snap.size === 57 };
    console.log(`  userDevices fleet count: ${snap.size} (expected 57 post-backfill, was 44 pre-backfill)`);
    console.log(`  VP2: ${out.VP2.pass ? '✓ PASS' : '✗ FAIL (drift acceptable if mobile traffic added entries)'}`);
  }

  // VP3: per-user doc counts for the 4 affected users
  console.log('\n── VP3: Per-user device count (4 backfill-affected users) ──\n');
  {
    const expected = {
      STA0001: 27, STA0002: 10, STU0001: 4, STU0017: 1,
    };
    const observed = {};
    let allMatch = true;
    for (const u of Object.keys(expected)) {
      const sn = await fsdb.collection(FS_COLLECTION).where('userId','==',u).limit(100).get();
      observed[u] = sn.size;
      if (sn.size !== expected[u]) allMatch = false;
      console.log(`  ${u}: observed=${sn.size}  expected=${expected[u]}  ${sn.size === expected[u] ? '✓' : '✗'}`);
    }
    out.VP3 = { expected, observed, pass: allMatch };
    console.log(`  VP3: ${out.VP3.pass ? '✓ PASS' : '✗ FAIL'}`);
  }

  // VP4 + VP5: push smoke-test gates (operator-driven, not automated here)
  console.log('\n── VP4 / VP5: Push delivery smoke tests (OPERATOR-DRIVEN) ──\n');
  console.log('  VP4: Send a test push to STU0017 (Arjun Singh, SCH_B56BB9A401)');
  console.log('  VP5: Send a test push to STA0001 (Vipul Tiwari, SCH_D94FE8F7AD)');
  console.log('  These require operator action — confirm FCM acceptance via Firebase console or in-app receipt.');
  out.VP4 = { manual: true, instruction: 'operator-driven' };
  out.VP5 = { manual: true, instruction: 'operator-driven' };

  // VP6: backfill-tag rollup query
  console.log('\n── VP6: Backfill-tag rollup query ──\n');
  {
    const sn = await fsdb.collection(FS_COLLECTION).where('_backfillBatch','==',BATCH_TAG).limit(500).get();
    out.VP6 = { count: sn.size, expected: 13, pass: sn.size === 13 };
    console.log(`  _backfillBatch=${BATCH_TAG}: ${sn.size} docs (expected 13)`);
    console.log(`  VP6: ${out.VP6.pass ? '✓ PASS' : '✗ FAIL'}`);
  }

  // VP7: RTDB tree integrity check (read-only)
  console.log('\n── VP7: RTDB Users/Devices tree integrity ──\n');
  {
    const rtdbSnap = await rtdb.ref(RTDB_BASE_PATH).limitToFirst(10000).once('value');
    const rtdbVal  = rtdbSnap.val() || {};
    const userRoots = Object.keys(rtdbVal).length;
    let deviceCount = 0;
    for (const [, devices] of Object.entries(rtdbVal)) {
      if (!devices || typeof devices !== 'object') continue;
      deviceCount += Object.keys(devices).length;
    }
    out.VP7 = { userRoots, deviceCount, expectedRoots: 13, expectedDevices: 57,
                pass: userRoots === 13 && deviceCount === 57 };
    console.log(`  RTDB user-roots: ${userRoots}  (expected 13)`);
    console.log(`  RTDB devices:    ${deviceCount}  (expected 57)`);
    console.log(`  VP7: ${out.VP7.pass ? '✓ PASS' : '✗ FAIL (RTDB drift — investigate)'}`);
  }

  console.log('\n');
  HR();
  const automatedPasses = ['VP1','VP2','VP3','VP6','VP7']
    .filter(k => out[k] && out[k].pass).length;
  console.log(`  Automated checks: ${automatedPasses}/5 PASS  (VP4/VP5 operator-driven)`);
  console.log(`  Overall VP1..VP7: ${automatedPasses === 5 ? 'READY for operator VP4/VP5 sign-off' : 'BLOCKED — see failures above'}`);
  HR();
  return out;
}

// ─── Entry point ──────────────────────────────────────────────────
function printUsage() {
  console.log('Usage: node scripts/comm_device_backfill.js [--apply | --verify | --help]');
  console.log('');
  console.log('  (no flag)   dry-run — print planned writes, perform ZERO Firestore/RTDB writes');
  console.log('  --apply     perform the Firestore writes (idempotent merge=true)');
  console.log('  --verify    run post-backfill VP1..VP7 verification routines');
  console.log('  --help      show this help');
  console.log('');
  console.log(`Batch tag: ${BATCH_TAG}`);
}

(async () => {
  const args = process.argv.slice(2);
  if (args.includes('--help')) { printUsage(); process.exit(0); }

  if (args.includes('--verify')) {
    await runVerification();
    process.exit(0);
  }

  const plan = await buildBackfillPlan();

  if (args.includes('--apply')) {
    // Always render the dry-run summary first, then apply.
    renderDryRun(plan);
    console.log('\n  ── proceeding to APPLY in 2 seconds (Ctrl+C to abort) ──\n');
    await new Promise(r => setTimeout(r, 2000));
    const result = await applyPlan(plan);
    console.log(`\n  Apply finished: written=${result.written} skipped=${result.skipped} failed=${result.failed}`);
    console.log('\n  Recommended next step: node scripts/comm_device_backfill.js --verify');
    process.exit(result.failed === 0 ? 0 : 2);
  }

  // Default: dry-run only.
  renderDryRun(plan);
  process.exit(0);
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
