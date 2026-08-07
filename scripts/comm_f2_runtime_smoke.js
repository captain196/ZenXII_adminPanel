#!/usr/bin/env node
/**
 * COMM-F2 runtime smoke — pure read-only simulation of the post-fix
 * Communication::_next_id and Communication.php bulk-allocate logic
 * against the current Firestore state.
 *
 * Validates: next mint produces the correct, non-colliding ID for each
 * counter type at every active tenant.
 *
 * READ-ONLY. admin.firestore() only.
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();

const SEED = {
  Notice:   { col: 'notices',         prefix: 'NOT', pad: 4 },
  Circular: { col: 'circulars',       prefix: 'CIR', pad: 4 },
  Queue:    { col: 'messageQueue',    prefix: 'QUE', pad: 5 },
};

const HR = () => console.log('═'.repeat(78));

async function scanMaxPrefix(schoolId, collection, prefix) {
  const snap = await fsdb.collection(collection).where('schoolId','==',schoolId).limit(500).get();
  const re = new RegExp(`^${prefix}(\\d+)$`);
  let max = 0;
  const existing = new Set();
  snap.forEach(s => {
    const data = s.data() || {};
    let raw = String(data.id || data.noticeId || data.circularId || data.queueId || '');
    if (!raw) {
      const did = s.id;
      raw = did.startsWith(schoolId + '_') ? did.slice(schoolId.length + 1) : did;
    }
    const m = raw.match(re);
    if (m) {
      const n = parseInt(m[1], 10);
      if (n > max) max = n;
      existing.add(n);
    }
  });
  return { max, existing };
}

async function simulateMint(schoolId, type) {
  const seed = SEED[type];
  const profile = await fsdb.collection('schools').doc(`${schoolId}_profile`).get();
  const data = profile.exists ? (profile.data() || {}) : {};
  const nested = (data.commCounters && typeof data.commCounters === 'object') ? data.commCounters : {};

  // Post-fix _next_id logic: read nested.{type}; if absent, self-heal scan.
  const nestedVal = (nested[type] !== undefined && Number.isFinite(Number(nested[type])))
    ? Number(nested[type]) : -1;

  let cur, source;
  if (nestedVal >= 0) {
    cur = nestedVal;
    source = `nested.commCounters.${type}`;
  } else {
    const { max } = await scanMaxPrefix(schoolId, seed.col, seed.prefix);
    cur = max;
    source = `self-heal scan of ${seed.col}`;
  }

  const next = cur + 1;
  const id = seed.prefix + String(next).padStart(seed.pad, '0');

  // Verify no collision in the canonical collection.
  const { existing } = await scanMaxPrefix(schoolId, seed.col, seed.prefix);
  const collides = existing.has(next);

  return { schoolId, type, cur, next, id, source, collides };
}

(async () => {
  HR();
  console.log('  COMM-F2 runtime smoke — post-fix _next_id simulation');
  HR();

  const schoolsSnap = await fsdb.collection('schools').limit(50).get();
  const tenants = new Set();
  schoolsSnap.forEach(s => {
    const m = s.id.match(/^(SCH_[A-F0-9]+)/i);
    if (m) tenants.add(m[1]);
  });

  let allOk = true;
  for (const sid of [...tenants].sort()) {
    const profile = await fsdb.collection('schools').doc(`${sid}_profile`).get();
    if (!profile.exists) {
      console.log(`\n── ${sid} ── (no profile; skipped)`);
      continue;
    }
    console.log(`\n── ${sid} ──`);
    for (const type of Object.keys(SEED)) {
      const r = await simulateMint(sid, type);
      const verdict = r.collides ? 'COLLIDES' : 'OK';
      if (r.collides) allOk = false;
      console.log(`  ${type.padEnd(10)} cur=${String(r.cur).padStart(4)}  next=${String(r.next).padStart(4)}  id=${r.id.padEnd(10)} via ${r.source.padEnd(40)} → ${verdict}`);
    }
  }

  console.log('');
  HR();
  console.log(`  Verdict: ${allOk ? 'ALL MINTS PRODUCE NON-COLLIDING IDS' : 'AT LEAST ONE COLLISION'}`);
  HR();
  process.exit(allOk ? 0 : 2);
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
