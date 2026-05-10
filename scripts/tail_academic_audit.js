#!/usr/bin/env node
/**
 * Tail recent academicAuditLog entries — for Phase 4 verification.
 *
 *   node scripts/tail_academic_audit.js                       # 20 most recent
 *   node scripts/tail_academic_audit.js --limit=50
 *   node scripts/tail_academic_audit.js --entityType=curriculum
 *   node scripts/tail_academic_audit.js --entityType=substitute
 *   node scripts/tail_academic_audit.js --action=delete
 */
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

function arg(name, dflt) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  return eq ? eq.split('=').slice(1).join('=') : dflt;
}
const SCHOOL = arg('school', 'SCH_D94FE8F7AD');
const LIMIT = parseInt(arg('limit', 20), 10);
const FILTER_ENT = arg('entityType', null);
const FILTER_ACT = arg('action', null);

async function fetchSorted() {
  // Try the indexed path first (fast for large collections).
  try {
    let q = fs.collection('academicAuditLog').where('schoolId', '==', SCHOOL);
    if (FILTER_ENT) q = q.where('entityType', '==', FILTER_ENT);
    if (FILTER_ACT) q = q.where('action', '==', FILTER_ACT);
    q = q.orderBy('ts', 'desc').limit(LIMIT);
    return await q.get();
  } catch (e) {
    if (e.code !== 9 /* FAILED_PRECONDITION = index missing/building */) throw e;
    console.log('(server-side index unavailable — using client-side sort fallback)\n');
    // Fallback: query without orderBy, sort in JS, slice to limit.
    let q = fs.collection('academicAuditLog').where('schoolId', '==', SCHOOL);
    if (FILTER_ENT) q = q.where('entityType', '==', FILTER_ENT);
    if (FILTER_ACT) q = q.where('action', '==', FILTER_ACT);
    const snap = await q.get();
    const sorted = snap.docs.sort((a, b) => (b.data().ts || '').localeCompare(a.data().ts || ''));
    return { size: Math.min(sorted.length, LIMIT), empty: sorted.length === 0, docs: sorted.slice(0, LIMIT) };
  }
}

(async () => {
  const snap = await fetchSorted();

  console.log('═'.repeat(72));
  console.log(`  academicAuditLog — last ${snap.size} entries (school=${SCHOOL})`);
  if (FILTER_ENT) console.log(`  filter: entityType=${FILTER_ENT}`);
  if (FILTER_ACT) console.log(`  filter: action=${FILTER_ACT}`);
  console.log('═'.repeat(72));

  if (snap.empty) {
    console.log('  (no entries — try doing some clicks in the UI then re-run)');
    return;
  }

  for (const d of snap.docs) {
    const data = d.data();
    const t = (data.ts || '').slice(0, 19);
    const actor = data.actor || {};
    console.log(`  ${t}  [${data.action.padEnd(13)}] ${data.entityType.padEnd(18)} ${data.entityId}`);
    console.log(`            actor: ${actor.name || '?'} (${actor.uid || '?'}, ${actor.role || '?'})`);
    if (data.before) console.log(`            before: ${JSON.stringify(data.before)}`);
    if (data.after)  console.log(`            after : ${JSON.stringify(data.after)}`);
    if (data.metadata) console.log(`            meta  : ${JSON.stringify(data.metadata)}`);
    console.log();
  }
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
