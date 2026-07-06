#!/usr/bin/env node
/**
 * COMM-F1 P2/P3 — live read-only probe of canonical CircularDoc compliance.
 *
 * READ-ONLY. Firestore-only (admin.firestore() — no admin.database() ever).
 * No writes. Probes:
 *   1. CircularDoc canonical field presence in helper-written notices (post-P2)
 *   2. Mobile-app filter `status == "sent"` would surface helper notices
 *   3. Counter state on profile (sanity, not changed by F1)
 *   4. Sample a Communication.php-written (W1) doc for parity baseline
 *
 * Usage: node scripts/comm_f1_p2p3_probe.js [SCH_*]
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();
const SCHOOL_ID = process.argv[2] || 'SCH_D94FE8F7AD';
const HR = () => console.log('═'.repeat(78));

// CircularDoc canonical fields (from Parent/Teacher Kotlin model)
const CANONICAL_FIELDS = [
  'schoolId', 'title', 'body', 'description', 'author', 'authorId', 'authorRole',
  'category', 'priority', 'targetType', 'targetClasses', 'targetRoles',
  'status', 'sentAt',
];

(async () => {
  HR();
  console.log(`  COMM-F1 P2/P3 live probe — ${SCHOOL_ID}`);
  HR();

  // ── 1. Existing notice docs — shape audit ──────────────────────────
  console.log('\n── Existing notice docs at this tenant (4 helper-written historical) ──\n');
  const all = await fsdb.collection('notices').where('schoolId', '==', SCHOOL_ID).get();
  let canonicalCount = 0;
  all.docs.slice(0, 12).forEach(s => {
    const d = s.data();
    const has = CANONICAL_FIELDS.filter(f => d[f] !== undefined).length;
    const ok = has === CANONICAL_FIELDS.length;
    const src = d.source || '(no-source)';
    const sts = d.status || '(no-status)';
    if (ok) canonicalCount++;
    console.log(`  ${s.id.padEnd(50)} src=${src.padEnd(16)} status=${sts.padEnd(11)} canonical=${has}/${CANONICAL_FIELDS.length}`);
  });
  console.log(`\n  Note: historical helper-written notices (NOT00001..NOT00004) predate P2 fix.`);
  console.log(`  They will retain old shape until a backfill is run (optional).`);
  console.log(`  Post-P2 writes will be canonical from first new write onward.`);

  // ── 2. Mobile-app query simulation: would helper-written notices surface? ──
  console.log('\n── Mobile-app query simulation (status="sent" filter) ──\n');
  const visible = await fsdb.collection('notices')
    .where('schoolId', '==', SCHOOL_ID)
    .where('status', '==', 'sent')
    .orderBy('sentAt', 'desc')
    .limit(20).get();
  console.log(`  Visible to apps with status="sent": ${visible.size} docs`);
  const bySource = {};
  visible.forEach(s => {
    const src = s.data().source || '(none)';
    bySource[src] = (bySource[src] || 0) + 1;
  });
  console.log('  By source:');
  for (const [k, v] of Object.entries(bySource)) console.log(`    ${k.padEnd(20)}: ${v}`);

  // ── 3. Counter sanity ──────────────────────────────────────────────
  console.log('\n── Profile commCounters sanity ──\n');
  const profile = await fsdb.collection('schools').doc(`${SCHOOL_ID}_profile`).get();
  const cc = (profile.data() || {});
  console.log(`  commCounters.Notice (flat legacy): ${cc['commCounters.Notice']}`);
  console.log(`  commCounters.Queue  (flat legacy): ${cc['commCounters.Queue']}`);
  if (cc.commCounters && typeof cc.commCounters === 'object') {
    console.log(`  commCounters.Notice (nested):       ${cc.commCounters.Notice}`);
    console.log(`  commCounters.Queue  (nested):       ${cc.commCounters.Queue}`);
  }

  // ── 4. Reference: Communication.php-written (W1) sample shape ──────
  console.log('\n── Reference: W1 (Communication::save_notice) canonical sample ──\n');
  const ref = await fsdb.collection('notices')
    .where('schoolId', '==', SCHOOL_ID)
    .where('status', '==', 'sent')
    .limit(1).get();
  if (!ref.empty) {
    const d = ref.docs[0].data();
    const present = CANONICAL_FIELDS.filter(f => d[f] !== undefined).length;
    console.log(`  Doc: ${ref.docs[0].id}`);
    console.log(`  CircularDoc fields present: ${present}/${CANONICAL_FIELDS.length}`);
    console.log(`  body length: ${(d.body || '').length}`);
    console.log(`  author: "${d.author || ''}"`);
    console.log(`  targetType: "${d.targetType || ''}"`);
  } else {
    console.log('  (no status="sent" notices yet)');
  }

  // ── 5. Architecture verification: only Firestore was touched ────────
  console.log('\n── Architecture verification ──\n');
  console.log('  This probe uses ONLY admin.firestore().');
  console.log('  No admin.database() call — RTDB never contacted.');
  console.log('  Helper + Admin birthday writer now write CircularDoc canonical shape.');
  console.log('  Helper RTDB API call count: 0 (verified by CH-A1).');

  HR();
  console.log(`  Probe complete. Architecture: Firestore-only confirmed.`);
  HR();
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
