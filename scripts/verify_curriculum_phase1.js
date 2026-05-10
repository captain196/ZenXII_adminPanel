#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Curriculum — Phase 1 Verification (post-migration)
 *
 *  For every curriculum doc, asserts:
 *    • topicsModel === 'subcollection'
 *    • topicIds is a non-null array
 *    • parent.totalTopics === topicIds.length === actual subcollection size
 *    • parent.completedTopics === actual count of subcollection topics with status='completed'
 *    • each subcollection topic has the canonical fields populated
 *    • each subcollection doc's topicId matches its docId
 *    • parent's legacy `topics[]` array is preserved (rollback capability)
 *
 *  Read-only. Run anytime after --apply.
 *
 *  Usage:
 *    node scripts/verify_curriculum_phase1.js
 *    node scripts/verify_curriculum_phase1.js --school SCH_X --verbose
 * ═══════════════════════════════════════════════════════════════════
 */

const path = require('path');

let admin;
try {
  admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin'));
} catch (e) {
  admin = require('firebase-admin');
}

const SERVICE_ACCOUNT_PATH = path.resolve(
  __dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
);

function parseArg(name) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  if (eq) return eq.split('=').slice(1).join('=');
  const i = process.argv.indexOf(`--${name}`);
  if (i !== -1 && i + 1 < process.argv.length && !process.argv[i + 1].startsWith('--')) {
    return process.argv[i + 1];
  }
  return null;
}
const VERBOSE       = process.argv.includes('--verbose');
const FILTER_SCHOOL = parseArg('school');

const sa = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

const BOLD = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM  = (s) => `\x1b[2m${s}\x1b[0m`;
const CYAN = (s) => `\x1b[36m${s}\x1b[0m`;
const YEL  = (s) => `\x1b[33m${s}\x1b[0m`;
const RED  = (s) => `\x1b[31m${s}\x1b[0m`;
const GRN  = (s) => `\x1b[32m${s}\x1b[0m`;
const HR   = '─'.repeat(72);

const fail = [];
function assert(cond, msg, ctx) {
  if (!cond) {
    fail.push({ msg, ctx });
    if (VERBOSE) console.log(RED('  ✗ ') + msg + DIM(' @ ' + ctx));
  } else if (VERBOSE) {
    console.log(GRN('  ✓ ') + msg + DIM(' @ ' + ctx));
  }
}

async function verifyOne(parentDoc) {
  const data = parentDoc.data();
  const id   = parentDoc.id;

  assert(data.topicsModel === 'subcollection',
    `topicsModel === 'subcollection'`, `curriculum/${id}`);
  assert(Array.isArray(data.topicIds),
    `topicIds is array`, `curriculum/${id}`);
  assert(typeof data.totalTopics === 'number',
    `totalTopics is number`, `curriculum/${id}`);
  assert(typeof data.completedTopics === 'number',
    `completedTopics is number`, `curriculum/${id}`);
  assert(Number(data.phase1MigrationRev || 0) >= 1,
    `phase1MigrationRev >= 1`, `curriculum/${id}`);

  // Check legacy array still exists (rollback capability).
  assert(Array.isArray(data.topics) || data.topics === undefined,
    `legacy topics[] preserved (or never existed)`, `curriculum/${id}`);

  // Read subcollection
  const sub = await fs.collection('curriculum').doc(id).collection('topics').get();
  const subDocs = sub.docs;

  const topicIds = Array.isArray(data.topicIds) ? data.topicIds : [];
  assert(subDocs.length === topicIds.length,
    `subcollection size (${subDocs.length}) === topicIds length (${topicIds.length})`,
    `curriculum/${id}`);
  assert(subDocs.length === Number(data.totalTopics || 0),
    `subcollection size (${subDocs.length}) === totalTopics (${data.totalTopics})`,
    `curriculum/${id}`);

  // Each subcollection doc: canonical fields + ID match
  let actualCompleted = 0;
  const actualIds = new Set();
  for (const td of subDocs) {
    const t = td.data();
    actualIds.add(td.id);
    assert(t.topicId === td.id,
      `topic.topicId === docId (${td.id})`, `curriculum/${id}/topics/${td.id}`);
    assert(typeof t.title === 'string',
      `title is string`, `curriculum/${id}/topics/${td.id}`);
    assert(['not_started', 'in_progress', 'completed'].includes(t.status),
      `status is valid enum`, `curriculum/${id}/topics/${td.id}`);
    assert(typeof t.sortOrder === 'number',
      `sortOrder is number`, `curriculum/${id}/topics/${td.id}`);
    assert(typeof t.estPeriods === 'number',
      `estPeriods is number`, `curriculum/${id}/topics/${td.id}`);
    assert(typeof t.version === 'number' && t.version >= 1,
      `version >= 1`, `curriculum/${id}/topics/${td.id}`);
    assert(t.parentDocId === id,
      `parentDocId === parent (${id})`, `curriculum/${id}/topics/${td.id}`);
    assert(t.schoolId === data.schoolId,
      `schoolId mirrors parent`, `curriculum/${id}/topics/${td.id}`);
    if (t.status === 'completed') actualCompleted++;
  }

  assert(actualCompleted === Number(data.completedTopics || 0),
    `actual completed (${actualCompleted}) === completedTopics counter (${data.completedTopics})`,
    `curriculum/${id}`);

  // topicIds[] must match the set of subcollection doc IDs
  const expectedIds = new Set(topicIds);
  let mismatches = 0;
  for (const tid of expectedIds) if (!actualIds.has(tid)) mismatches++;
  for (const aid of actualIds)   if (!expectedIds.has(aid)) mismatches++;
  assert(mismatches === 0,
    `topicIds set === subcollection ID set (mismatches=${mismatches})`,
    `curriculum/${id}`);

  if (VERBOSE) {
    console.log(`  ${id}  →  topics=${subDocs.length}, completed=${actualCompleted}, percent=${data.percentComplete}%`);
  }
}

async function main() {
  console.log(BOLD(CYAN('Curriculum — Phase 1 Verification')));
  console.log(HR);
  console.log(`  school:  ${FILTER_SCHOOL || '(all)'}`);
  console.log(`  verbose: ${VERBOSE}`);
  console.log(HR);

  const col = fs.collection('curriculum');
  const q = FILTER_SCHOOL ? col.where('schoolId', '==', FILTER_SCHOOL) : col;
  const snap = await q.get();
  console.log(`  found:   ${snap.size} curriculum doc(s)`);
  console.log(HR);

  for (const d of snap.docs) {
    await verifyOne(d);
  }

  console.log(HR);
  console.log(BOLD('Summary'));
  console.log(`  parents checked: ${snap.size}`);
  if (fail.length === 0) {
    console.log(GRN(BOLD('  ✔ All assertions passed.')));
    process.exit(0);
  } else {
    console.log(RED(BOLD(`  ✗ ${fail.length} assertion(s) failed:`)));
    fail.forEach(f => console.log(RED('    • ') + f.msg + DIM(' @ ' + f.ctx)));
    process.exit(2);
  }
}

main().catch(e => { console.error(RED('Fatal:'), e); process.exit(1); });
