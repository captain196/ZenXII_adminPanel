/**
 * READ-ONLY check of the P11-G2 hard-delete orphan MARKER on identity indexes.
 *
 * ── WHAT IS BEING TESTED ─────────────────────────────────────────────────
 * `Sis::delete_student()` deliberately does NOT release an Admission Number,
 * SRNO or APAAR ID on a hard delete — reusability is an open business
 * decision, recorded in the code. What it DOES promise is an integrity
 * MARKER, so an index entry pointing at a deleted student is distinguishable
 * from a healthy one:
 *
 *     if (($admDoc['currentStudentId'] ?? '') === $id) {
 *         update(... 'currentOwnerDeleted' => true ...)
 *     }
 *
 * That guard reads `currentStudentId`. This reports which owner field the
 * documents ACTUALLY carry, and how many orphans ended up marked — because a
 * guard that reads a field the document does not have can never be true, and
 * the marker it protects can never be written.
 *
 *   node scripts/admission_index_marker_readonly.js --school=SCH_B56BB9A401
 *
 * Writes nothing.
 * exit 0 = every orphaned index entry carries the marker
 *      2 = orphaned entries exist with no marker
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

const INDEXES = ['admissionNoIndex', 'srNoIndex', 'apaarIndex'];
const OWNER_FIELDS = ['currentStudentId', 'studentId', 'ownerStudentId', 'currentOwner'];

(async () => {
  console.log(`IDENTITY-INDEX ORPHAN MARKER CHECK — ${SCHOOL} (READ ONLY)\n`);

  const stu = await db.collection('students').where('schoolId', '==', SCHOOL).get();
  const live = new Set();
  stu.forEach((d) => {
    const s = d.data() || {};
    live.add(String(s.studentId || d.id.replace(`${SCHOOL}_`, '')));
  });

  let unmarked = 0;

  for (const col of INDEXES) {
    let snap;
    try { snap = await db.collection(col).where('schoolId', '==', SCHOOL).get(); }
    catch (e) { console.log(`  ${col}: not queryable (${e.message})\n`); continue; }
    if (snap.empty) { console.log(`  ${col}: no entries for this tenant\n`); continue; }

    // Which owner field do these documents actually use?
    const fieldUse = {};
    snap.forEach((d) => {
      const data = d.data() || {};
      for (const f of OWNER_FIELDS) if (data[f] !== undefined) fieldUse[f] = (fieldUse[f] || 0) + 1;
    });

    let orphans = 0, marked = 0;
    snap.forEach((d) => {
      const data = d.data() || {};
      // Resolve the owner through ANY of the candidate fields, so this check
      // does not repeat the assumption it is testing.
      let owner = '';
      for (const f of OWNER_FIELDS) {
        if (data[f]) { owner = String(data[f]); break; }
      }
      if (!owner) return;
      if (live.has(owner)) return;
      orphans++;
      if (data.currentOwnerDeleted === true) marked++;
    });

    console.log(`  ${col}`);
    console.log(`    entries              : ${snap.size}`);
    console.log(`    owner field(s) present: ${Object.keys(fieldUse).length
      ? Object.entries(fieldUse).map(([k, v]) => `${k}(${v})`).join(', ')
      : '(none of the candidates!)'}`);
    console.log(`    guard in delete_student reads: currentStudentId — `
      + `${fieldUse.currentStudentId ? 'PRESENT' : 'ABSENT from every document'}`);
    console.log(`    orphaned entries     : ${orphans}`);
    console.log(`    of those, marked     : ${marked}`);
    if (orphans > 0 && marked === 0) {
      unmarked += orphans;
      console.log(`    >>> ${orphans} orphan(s), NONE marked. The marker P11-G2 promises is`);
      console.log('        never written, so nothing distinguishes an index entry owned by a');
      console.log('        deleted student from a healthy one.');
    }
    console.log('');
  }

  console.log('-'.repeat(70));
  console.log(unmarked === 0
    ? '  Every orphaned index entry carries its integrity marker.'
    : `  ${unmarked} orphaned index entr(ies) carry NO marker.`);
  process.exit(unmarked === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
