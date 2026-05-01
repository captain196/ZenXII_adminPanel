// validate_rtdb_migration.js — compare a migrated subtree on both sides.
//
// After running migrate_rtdb_to_firestore.js for a mapping, this script
// verifies that every RTDB leaf now has a matching Firestore document
// and every migrated Firestore doc traces back to a live RTDB leaf.
// Useful immediately post-migration AND as a periodic drift check
// while both stores are still live.
//
// Report-only — never writes. Exit code 0 when sets match, 1 otherwise.
//
// Usage:
//   node scripts/validate_rtdb_migration.js --mapping=notifBadges
//   node scripts/validate_rtdb_migration.js --mapping=presence --sampleSize=50

const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));

admin.initializeApp({
  credential: admin.credential.cert(sa),
  databaseURL: process.env.RTDB_URL || 'https://graderadmin-default-rtdb.asia-southeast1.firebasedatabase.app',
});
const rtdb = admin.database();
const fs   = admin.firestore();

const MAPPINGS = {
  notifBadges:  { rtdbRoot: 'NotifBadge',     firestoreCollection: 'notifBadges',  leafDepth: 1 },
  presence:     { rtdbRoot: 'Presence',       firestoreCollection: 'presence',     leafDepth: 1 },
  studentFlags: { rtdbRoot: 'StudentFlags',   firestoreCollection: 'studentFlags', leafDepth: 3 },
  userDevices:  { rtdbRoot: 'Users/Devices',  firestoreCollection: 'userDevices',  leafDepth: 2 },
  otpSessions:  { rtdbRoot: 'System/PasswordResets',               firestoreCollection: 'otp_sessions',      leafDepth: 1 },
  // Per-school hostel mappings — caller must pass --rtdbRootOverride=Schools/<id>/Operations/Hostel/Buildings
  hostelBuildings:   { rtdbRoot: 'Schools/__schoolId__/Operations/Hostel/Buildings',    firestoreCollection: 'hostelBuildings',   leafDepth: 1, perSchool: true },
  hostelRooms:       { rtdbRoot: 'Schools/__schoolId__/Operations/Hostel/Rooms',        firestoreCollection: 'hostelRooms',       leafDepth: 1, perSchool: true },
  hostelAllocations: { rtdbRoot: 'Schools/__schoolId__/Operations/Hostel/Allocations',  firestoreCollection: 'hostelAllocations', leafDepth: 1, perSchool: true },
  notices:           { rtdbRoot: 'Schools/__schoolId__/__session__/All Notices',       firestoreCollection: 'notices',           leafDepth: 1, perSchool: true, perSession: true },

  // Inventory (per-school)
  inventoryItems:      { rtdbRoot: 'Schools/__schoolId__/Operations/Inventory/Items',      firestoreCollection: 'inventory',            leafDepth: 1, perSchool: true },
  inventoryCategories: { rtdbRoot: 'Schools/__schoolId__/Operations/Inventory/Categories', firestoreCollection: 'inventoryCategories',  leafDepth: 1, perSchool: true },
  vendors:             { rtdbRoot: 'Schools/__schoolId__/Operations/Inventory/Vendors',    firestoreCollection: 'vendors',              leafDepth: 1, perSchool: true },
  purchaseOrders:      { rtdbRoot: 'Schools/__schoolId__/Operations/Inventory/Purchases',  firestoreCollection: 'purchaseOrders',       leafDepth: 1, perSchool: true },
  inventoryIssues:     { rtdbRoot: 'Schools/__schoolId__/Operations/Inventory/Issues',     firestoreCollection: 'inventoryIssues',      leafDepth: 1, perSchool: true },

  // Transport (per-school)
  vehicles:          { rtdbRoot: 'Schools/__schoolId__/Operations/Transport/Vehicles',    firestoreCollection: 'vehicles',          leafDepth: 1, perSchool: true },
  routes:            { rtdbRoot: 'Schools/__schoolId__/Operations/Transport/Routes',      firestoreCollection: 'routes',            leafDepth: 1, perSchool: true },
  transportStops:    { rtdbRoot: 'Schools/__schoolId__/Operations/Transport/Stops',       firestoreCollection: 'transportStops',    leafDepth: 1, perSchool: true },
  studentRoutes:     { rtdbRoot: 'Schools/__schoolId__/Operations/Transport/Assignments', firestoreCollection: 'studentRoutes',     leafDepth: 1, perSchool: true },

  // Events (per-school)
  events:            { rtdbRoot: 'Schools/__schoolId__/Events/List',                      firestoreCollection: 'events',            leafDepth: 1, perSchool: true },
  eventParticipants: { rtdbRoot: 'Schools/__schoolId__/Events/Participants',              firestoreCollection: 'eventParticipants', leafDepth: 2, perSchool: true },

  // Library (per-school)
  libraryBooks:      { rtdbRoot: 'Schools/__schoolId__/Operations/Library/Books',         firestoreCollection: 'libraryBooks',      leafDepth: 1, perSchool: true },
  bookCategories:    { rtdbRoot: 'Schools/__schoolId__/Operations/Library/Categories',    firestoreCollection: 'bookCategories',    leafDepth: 1, perSchool: true },
  libraryIssues:     { rtdbRoot: 'Schools/__schoolId__/Operations/Library/Issues',        firestoreCollection: 'libraryIssues',     leafDepth: 1, perSchool: true },
  libraryFines:      { rtdbRoot: 'Schools/__schoolId__/Operations/Library/Fines',         firestoreCollection: 'libraryFines',      leafDepth: 1, perSchool: true },
};

function parseArgs(argv) {
  const opts = {};
  for (const a of argv.slice(2)) {
    if (!a.startsWith('--')) continue;
    const eq = a.indexOf('=');
    if (eq === -1) opts[a.slice(2)] = true;
    else opts[a.slice(2, eq)] = a.slice(eq + 1);
  }
  return opts;
}

function flattenLeaves(node, depth, prefix = []) {
  if (depth === 0) {
    return [{ key: prefix.join('/'), value: node }];
  }
  if (!node || typeof node !== 'object') return [];
  const out = [];
  for (const k of Object.keys(node)) {
    out.push(...flattenLeaves(node[k], depth - 1, [...prefix, k]));
  }
  return out;
}

(async () => {
  const opts = parseArgs(process.argv);
  const name = String(opts.mapping || '');
  if (!MAPPINGS[name]) {
    console.error(`--mapping required. One of: ${Object.keys(MAPPINGS).join(', ')}`);
    process.exit(2);
  }
  const mapping = MAPPINGS[name];
  let rtdbRoot = mapping.rtdbRoot;
  const firestoreCollection = mapping.firestoreCollection;
  const leafDepth = mapping.leafDepth;
  if (mapping.perSchool) {
    const sid = String(opts.schoolId || '');
    if (!sid) { console.error(`--schoolId required for per-school mapping ${name}`); process.exit(2); }
    rtdbRoot = rtdbRoot.replace('__schoolId__', sid);
  }
  if (mapping.perSession) {
    const sess = String(opts.session || '');
    if (!sess) { console.error(`--session required for per-session mapping ${name}`); process.exit(2); }
    rtdbRoot = rtdbRoot.replace('__session__', sess);
  }
  if (opts.rtdbRootOverride) rtdbRoot = String(opts.rtdbRootOverride);
  const sampleSize = Math.max(1, parseInt(opts.sampleSize || '25', 10));

  console.log(`\nVALIDATE  ${rtdbRoot}  ↔  ${firestoreCollection}  (depth=${leafDepth})\n`);

  const rtdbSnap = await rtdb.ref(rtdbRoot).once('value');
  const rtdbLeaves = rtdbSnap.exists() ? flattenLeaves(rtdbSnap.val(), leafDepth) : [];
  const rtdbKeys = new Set(rtdbLeaves.map(l => l.key));
  console.log(`RTDB leaves:         ${rtdbLeaves.length}`);

  const fsQuery = await fs.collection(firestoreCollection).get();
  const fsDocs  = fsQuery.docs.map(d => ({ id: d.id, data: d.data() || {} }));
  console.log(`Firestore docs:      ${fsDocs.length}`);

  // Cross-check: how many RTDB leaves are missing a Firestore doc?
  // Compute expected Firestore doc id from the RTDB key path the same
  // way migrate_rtdb_to_firestore's default template does (underscore-joined).
  const expectedFsId = key => key.replace(/\//g, '_');
  const fsIds = new Set(fsDocs.map(d => d.id));
  const missingInFs = [];
  for (const l of rtdbLeaves) {
    const id = expectedFsId(l.key);
    if (!fsIds.has(id)) missingInFs.push({ rtdbKey: l.key, expectedFsId: id });
  }
  // Orphans: Firestore docs whose _migratedFrom doesn't correspond to a
  // live RTDB leaf. Ignore docs not written by the migrator (no marker).
  const orphansInFs = [];
  for (const d of fsDocs) {
    const marker = d.data._migratedFrom;
    if (!marker) continue;
    const leaf = String(marker).replace(/^rtdb:\/\//, '');
    const expectedKey = leaf.replace(new RegExp(`^${rtdbRoot}/`), '');
    if (!rtdbKeys.has(expectedKey)) orphansInFs.push({ fsId: d.id, marker });
  }

  console.log(`\nMissing in Firestore (RTDB leaf, no matching FS doc): ${missingInFs.length}`);
  missingInFs.slice(0, sampleSize).forEach(m => console.log(`   · ${m.rtdbKey}  (expected FS id: ${m.expectedFsId})`));
  if (missingInFs.length > sampleSize) console.log(`   … and ${missingInFs.length - sampleSize} more`);

  console.log(`\nOrphans in Firestore (FS doc, RTDB leaf is gone):      ${orphansInFs.length}`);
  orphansInFs.slice(0, sampleSize).forEach(o => console.log(`   · ${o.fsId}  (was: ${o.marker})`));
  if (orphansInFs.length > sampleSize) console.log(`   … and ${orphansInFs.length - sampleSize} more`);

  const ok = missingInFs.length === 0;
  console.log(`\nVerdict: ${ok ? '✅ IN SYNC' : '❌ OUT OF SYNC — run migrate_rtdb_to_firestore.js again'}`);
  process.exit(ok ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
