/**
 * READ-ONLY surface map for the Student Lifecycle Gate 2 reconciliation.
 *
 * Gate 2 compares three layers for every student:
 *   AUTHORITATIVE  — the `students` document
 *   DERIVED        — rosters, strengths, indexes, per-student operational docs
 *   REPORTING      — anything a screen or export reads
 *
 * Before comparing them, the layers have to be located. This enumerates every
 * collection in the project, counts how much of it belongs to this tenant, and
 * reports which collections carry a `studentId` — i.e. which ones a lifecycle
 * transition is capable of leaving stale.
 *
 *   node scripts/lifecycle_surface_map_readonly.js --school=SCH_B56BB9A401
 *
 * Writes nothing.
 */
const admin = require('firebase-admin');
const path  = require('path');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};
const SCHOOL = arg('school', 'SCH_B56BB9A401');

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

// Fields that must never be printed, whatever collection they turn up in.
const SECRET = /pass|secret|token|key|credential|otp|hash|salt|auth/i;

(async () => {
  console.log(`LIFECYCLE SURFACE MAP — ${SCHOOL} (READ ONLY)\n`);

  const cols = await db.listCollections();
  console.log(`  ${cols.length} collections in the project\n`);

  const rows = [];
  for (const c of cols) {
    // Tenant slice. Not every collection is school-scoped, so a failed query
    // is information (no schoolId field), not an error.
    let mine = 0, hasStudentId = false, sessionField = false, statusField = false;
    let sampleFields = [];
    try {
      const snap = await c.where('schoolId', '==', SCHOOL).limit(500).get();
      mine = snap.size;
      snap.forEach((d) => {
        const data = d.data() || {};
        if (data.studentId !== undefined) hasStudentId = true;
        if (data.session   !== undefined) sessionField = true;
        if (data.status    !== undefined) statusField  = true;
        if (!sampleFields.length) {
          sampleFields = Object.keys(data).filter((k) => !SECRET.test(k)).sort();
        }
      });
    } catch (e) {
      rows.push({ id: c.id, mine: -1, note: 'not school-scoped / unindexed' });
      continue;
    }
    if (mine === 0) continue;
    rows.push({
      id: c.id, mine, hasStudentId, sessionField, statusField,
      fields: sampleFields.slice(0, 14).join(','),
    });
  }

  rows.sort((a, b) => b.mine - a.mine);

  console.log('  TENANT-SCOPED COLLECTIONS (docs, and whether a lifecycle move can stale them)\n');
  console.log('    ' + 'collection'.padEnd(26) + 'docs'.padStart(6)
    + '  studentId  session  status');
  console.log('    ' + '-'.repeat(70));
  for (const r of rows) {
    if (r.mine < 0) continue;
    console.log('    ' + r.id.padEnd(26) + String(r.mine).padStart(6)
      + '  ' + (r.hasStudentId ? '   yes  ' : '    -   ')
      + '  ' + (r.sessionField ? '  yes  ' : '   -   ')
      + '  ' + (r.statusField ? ' yes' : '  -'));
  }

  const perStudent = rows.filter((r) => r.mine > 0 && r.hasStudentId);
  console.log(`\n  ${perStudent.length} collection(s) carry a studentId — these are the surfaces a`);
  console.log('  lifecycle transition must keep consistent:');
  for (const r of perStudent) {
    console.log(`    ${r.id.padEnd(24)} session=${r.sessionField ? 'yes' : 'no '} `
      + `status=${r.statusField ? 'yes' : 'no '}  fields: ${r.fields}`);
  }

  console.log('\n  Nothing was written.');
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
