#!/usr/bin/env node
/**
 * Diagnostic — what RTDB data exists for student flags?
 *
 *   node scripts/inspect_student_flags_rtdb.js [SCH_XXXXXX]
 *
 * Prints:
 *  - Top-level keys present under Schools, StudentFlags, Indexes/School_codes
 *  - For each school: every {session}/{class}/{section} node and whether
 *    it has a `RedFlags` child, with counts.
 *  - Top-level StudentFlags/* keys and counts.
 */

const admin = require('firebase-admin');
const path  = require('path');

const sa = require(path.resolve(__dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));

admin.initializeApp({
  credential:  admin.credential.cert(sa),
  databaseURL: process.env.RTDB_URL
    || 'https://graderadmin-default-rtdb.firebaseio.com/',
});
const rtdb = admin.database();
const filter = process.argv[2] || null;

async function shallow(p) {
  const url = new URL(`${p}.json`, admin.app().options.databaseURL);
  url.searchParams.set('shallow', 'true');
  // Use REST shallow probe via admin token
  const token = await admin.app().options.credential.getAccessToken();
  const res = await fetch(url.toString(), {
    headers: { Authorization: `Bearer ${token.access_token}` }
  });
  if (!res.ok) return null;
  return await res.json();
}

async function get(p) {
  try { return (await rtdb.ref(p).once('value')).val(); }
  catch (_) { return null; }
}

function countLeaves(node) {
  if (!node || typeof node !== 'object') return 0;
  return Object.keys(node).length;
}

(async () => {
  console.log('\n═══ RTDB student-flag inspection ═══\n');

  // Top-level survey
  const schoolsKeys = await shallow('Schools');
  const sysSchools  = await get('System/Schools');
  const sfTopLevel  = await shallow('StudentFlags');
  const idxCodes    = await shallow('Indexes/School_codes');

  console.log('Top-level Schools children:        ',
    schoolsKeys ? Object.keys(schoolsKeys).length : 0);
  console.log('System/Schools entries:            ',
    sysSchools ? Object.keys(sysSchools).length : 0);
  console.log('Top-level StudentFlags children:   ',
    sfTopLevel ? Object.keys(sfTopLevel).length : 0,
    sfTopLevel ? '(' + Object.keys(sfTopLevel).slice(0, 5).join(', ') + ')' : '');
  console.log('Indexes/School_codes entries:      ',
    idxCodes ? Object.keys(idxCodes).length : 0);

  // Per-school deep dive
  let ids = sysSchools ? Object.keys(sysSchools) : [];
  if (filter) ids = ids.filter(i => i === filter);

  for (const id of ids) {
    console.log(`\n── ${id} ──`);

    const node = await get(`Schools/${id}`);
    if (!node) { console.log('   (Schools/' + id + ' empty)'); continue; }

    const sessions = Object.keys(node).filter(k => /^\d{4}-\d{2}$/.test(k));
    console.log('  Sessions:', sessions.join(', ') || '(none)');

    let totalAflags = 0;
    for (const s of sessions) {
      const sNode = node[s];
      if (!sNode || typeof sNode !== 'object') continue;
      for (const [ck, sects] of Object.entries(sNode)) {
        if (!ck.startsWith('Class ') || !sects || typeof sects !== 'object') continue;
        for (const [sk, sec] of Object.entries(sects)) {
          if (!sk.startsWith('Section ')) continue;
          const rf = sec && sec.RedFlags;
          if (rf && typeof rf === 'object') {
            let count = 0;
            for (const flags of Object.values(rf)) {
              if (flags && typeof flags === 'object') count += Object.keys(flags).length;
            }
            if (count > 0) {
              console.log(`    Source A: ${s}/${ck}/${sk}/RedFlags  → ${count} flag(s) across ${Object.keys(rf).length} student(s)`);
              totalAflags += count;
            }
          }
        }
      }
    }
    if (totalAflags === 0) console.log('    Source A: no RedFlags nodes found in any class/section');

    // Source B candidates
    const codeFromIdx = idxCodes && idxCodes[id];
    const candidates = new Set([id]);
    if (codeFromIdx && typeof codeFromIdx === 'string') candidates.add(codeFromIdx);
    let totalBflags = 0;
    for (const key of candidates) {
      const sf = await get(`StudentFlags/${key}`);
      if (!sf) { console.log(`    Source B: StudentFlags/${key}  → (empty)`); continue; }
      let count = 0;
      for (const flags of Object.values(sf)) {
        if (flags && typeof flags === 'object') count += Object.keys(flags).length;
      }
      console.log(`    Source B: StudentFlags/${key}  → ${count} flag(s) across ${Object.keys(sf).length} student(s)`);
      totalBflags += count;
    }

    console.log(`  TOTAL: A=${totalAflags}  B=${totalBflags}`);
  }

  console.log('\n');
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
