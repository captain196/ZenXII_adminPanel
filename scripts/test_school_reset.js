// ─────────────────────────────────────────────────────────────────────────
// TEST SCHOOL TENANT RESET — SCH_D94FE8F7AD
//
//   node scripts/test_school_reset.js              # DRY RUN (default)
//   node scripts/test_school_reset.js --commit     # actually deletes
//
// Follows this repo's script convention: dry-run unless --commit is typed.
//
// ═══════════════════════════════════════════════════════════════════════
//  WHY THIS IS SAFE FOR A SHARED DATABASE
// ═══════════════════════════════════════════════════════════════════════
//  This Firestore project holds REAL schools alongside the test tenant, and
//  many collections mix them in the same collection (transportAuditLog is
//  1,820 ours out of 4,534; auditLogs 309 of 732). A collection-wide delete
//  would therefore destroy live customer data.
//
//  Three independent guards prevent that:
//
//   1. EVERY query is filtered — `where('schoolId','==',SCHOOL)` or a
//      documentId range bounded to `SCH_D94FE8F7AD_`. There is no unfiltered
//      read anywhere in this file.
//
//   2. PER-DOCUMENT ASSERTION — ownedByTenant() re-checks every single doc
//      immediately before it is added to a delete batch. A document is
//      deleted ONLY if its schoolId field equals ours, or its document ID is
//      exactly ours / prefixed with ours. Anything else is REFUSED, counted
//      and reported. Even a mis-written query cannot delete another school.
//
//   3. RETAIN LIST — the school shell plus authentication/provisioning
//      records are excluded, so the tenant stays loginable and rebuildable
//      instead of needing re-provisioning.
//
//  Both ID-keyed and field-keyed collections are covered: ~1,409 tenant
//  documents (all 854 feeDemands among them) carry schoolId as a FIELD with
//  no ID prefix, so a prefix-only sweep would silently miss them.
//
//  Subcollections: sampled across students/feeDemands/accounting/staff/
//  parents/schools/sections/auditLogs/notices/transportAuditLog — NONE found,
//  so plain document deletes are complete.
//
//  NOT touched: Firebase Storage, Firebase Auth, firestore.rules,
//  firestore.indexes.json, any other tenant.
// ─────────────────────────────────────────────────────────────────────────

const admin = require('firebase-admin');
const path  = require('path');
const sa    = require(path.join(__dirname, '..', 'application', 'config',
                'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';
const PREFIX = SCHOOL + '_';
const COMMIT = process.argv.includes('--commit');

// Shell / auth / provisioning — RETAINED. Deleting these would leave an
// unloginable school requiring re-provisioning, defeating the purpose of
// keeping a rebuildable test tenant. Pass --nuke-shell to include them.
const NUKE_SHELL = process.argv.includes('--nuke-shell');
const RETAIN = new Set(NUKE_SHELL ? [] : [
  'schools', 'schoolConfig', 'schoolCodeIndex', 'schoolNameIndex',
  'indexSchoolCodes', 'schoolSsa', 'subscriptions', 'tenantAudit',
  'tenantPublic', 'adminAuthState',
]);

/** HARD SAFETY GATE — the last line of defence before any delete. */
function ownedByTenant(doc) {
  const d = doc.data() || {};
  const f = d.schoolId || d.school_id || d.schoolID || null;
  if (f === SCHOOL) return true;
  if (doc.id === SCHOOL || doc.id.startsWith(PREFIX)) return true;
  return false;   // belongs to someone else, or cannot be proven — never delete
}

async function purge(colName, snap, stats, seen) {
  let batch = db.batch(), n = 0;
  for (const doc of snap.docs) {
    const key = colName + '/' + doc.id;
    if (seen.has(key)) continue;          // pass 1 and pass 2 can overlap
    seen.add(key);

    if (!ownedByTenant(doc)) {
      stats.refused++;
      if (stats.refusedIds.length < 25) stats.refusedIds.push(key);
      continue;
    }
    stats.matched++;
    if (!COMMIT) continue;                // dry run: count only

    batch.delete(doc.ref); n++;
    if (n % 450 === 0) { await batch.commit(); stats.deleted += 450; batch = db.batch(); }
  }
  const rem = n % 450;
  if (COMMIT && rem > 0) { await batch.commit(); stats.deleted += rem; }
}

(async () => {
  console.log(`MODE = ${COMMIT ? 'COMMIT (DELETING)' : 'DRY RUN (no writes)'}`);
  console.log(`TENANT = ${SCHOOL}`);
  console.log(`SHELL = ${NUKE_SHELL ? 'WILL BE DELETED' : 'RETAINED'}\n`);

  const cols = await db.listCollections();
  const results = [];
  let gMatched = 0, gDeleted = 0, gRefused = 0, gErrors = 0;
  const refusedAll = [];

  for (const c of cols) {
    const name = c.id;
    if (RETAIN.has(name)) { results.push({ name, matched: 0, deleted: 0, refused: 0, note: 'RETAINED' }); continue; }

    const stats = { matched: 0, deleted: 0, refused: 0, refusedIds: [] };
    const seen  = new Set();
    try {
      // pass 1 — schoolId field (catches docs with no ID prefix)
      let snap = await c.where('schoolId', '==', SCHOOL).get().catch(() => null);
      if (snap && !snap.empty) await purge(name, snap, stats, seen);

      // pass 2 — documentId range (catches docs with no schoolId field)
      snap = await c.orderBy(admin.firestore.FieldPath.documentId())
                    .startAt(PREFIX).endAt(PREFIX + '').get().catch(() => null);
      if (snap && !snap.empty) await purge(name, snap, stats, seen);

      // pass 3 — a document named exactly for the school
      const one = await c.doc(SCHOOL).get().catch(() => null);
      if (one && one.exists && !seen.has(name + '/' + one.id)) {
        seen.add(name + '/' + one.id);
        stats.matched++;
        if (COMMIT) { await one.ref.delete(); stats.deleted++; }
      }
    } catch (e) {
      gErrors++;
      results.push({ name, matched: stats.matched, deleted: stats.deleted, refused: stats.refused, note: 'ERROR: ' + e.message });
      continue;
    }

    if (stats.matched || stats.refused) {
      results.push({ name, matched: stats.matched, deleted: stats.deleted, refused: stats.refused, note: '' });
      gMatched += stats.matched; gDeleted += stats.deleted; gRefused += stats.refused;
      refusedAll.push(...stats.refusedIds);
    }
  }

  console.log('collection'.padEnd(34), 'matched'.padStart(8), 'deleted'.padStart(8), 'refused'.padStart(8), ' note');
  console.log('-'.repeat(78));
  results.filter(r => r.matched || r.refused || r.note)
         .sort((a, b) => b.matched - a.matched)
         .forEach(r => console.log(r.name.padEnd(34), String(r.matched).padStart(8),
                                   String(r.deleted).padStart(8), String(r.refused).padStart(8), ' ' + r.note));
  console.log('-'.repeat(78));
  console.log(`TOTAL MATCHED (this tenant)        = ${gMatched}`);
  console.log(`TOTAL DELETED                      = ${gDeleted}`);
  console.log(`TOTAL REFUSED (not ours — skipped) = ${gRefused}`);
  console.log(`COLLECTIONS WITH ERRORS            = ${gErrors}`);
  if (refusedAll.length) console.log('REFUSED SAMPLE:', refusedAll.slice(0, 25).join(', '));
  if (!COMMIT) console.log('\nDRY RUN — nothing was deleted. Re-run with --commit to execute.');

  await admin.app().delete();
})().catch(e => { console.error('FATAL', e.message); process.exit(1); });
