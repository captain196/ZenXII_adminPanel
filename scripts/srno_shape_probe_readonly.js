/**
 * READ-ONLY probe of the srNoIndex / SRNO identity shape.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * Two records disagree and both cannot be right:
 *
 *   • The SRNO identity audit (2026-08-17) recorded 16 `srNoIndex` documents
 *     for this project, claimed by `P203M_UAT`, pointing at STU0164–STU0181.
 *   • `admission_index_marker_readonly.js` (2026-08-27), querying
 *     `where('schoolId','==',SCHOOL)`, reported "no entries for this tenant".
 *
 * A disagreement about whether identity claims EXIST is not a detail, so this
 * resolves it without repeating either assumption: it lists the collection
 * unfiltered, reports the real field names, and only then applies the tenant
 * filter. If the documents are there but carry no `schoolId` field — or carry
 * it under another name — the filtered query would have silently returned
 * nothing, which looks identical to "clean".
 *
 *   node scripts/srno_shape_probe_readonly.js
 *
 * Writes nothing. Prints identifiers and field NAMES only.
 */
const admin = require('firebase-admin');
const path  = require('path');

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SECRET = /pass|secret|token|key|credential|otp|hash|salt/i;

(async () => {
  console.log('SRNO / IDENTITY-INDEX SHAPE PROBE (READ ONLY)\n');

  for (const col of ['srNoIndex', 'admissionNoIndex', 'apaarIndex']) {
    let snap;
    try { snap = await db.collection(col).get(); }
    catch (e) { console.log(`  ${col}: unreadable (${e.message})\n`); continue; }

    console.log(`  ── ${col}`);
    console.log(`     total documents in project : ${snap.size}`);
    if (snap.empty) { console.log(''); continue; }

    // Real field names, and how many docs carry each.
    const fieldUse = {};
    snap.forEach((d) => {
      Object.keys(d.data() || {}).forEach((k) => {
        if (SECRET.test(k)) return;
        fieldUse[k] = (fieldUse[k] || 0) + 1;
      });
    });
    console.log(`     fields (doc count)         : `
      + Object.entries(fieldUse).sort((a, b) => b[1] - a[1])
          .map(([k, v]) => `${k}(${v})`).join(', '));

    // Does a tenant filter actually reach them?
    const withSchoolId = snap.docs.filter((d) => (d.data() || {}).schoolId !== undefined).length;
    console.log(`     carrying a schoolId field  : ${withSchoolId} / ${snap.size}`);
    if (withSchoolId < snap.size) {
      console.log(`     >>> ${snap.size - withSchoolId} document(s) are INVISIBLE to any`);
      console.log('         where("schoolId","==",…) query. A tenant-filtered audit of this');
      console.log('         collection reports "clean" because it cannot see them.');
    }

    // Tenant distribution, derived from the doc id prefix as a cross-check
    // that does not depend on the field being present.
    const byPrefix = {};
    snap.forEach((d) => {
      const m = String(d.id).match(/^(SCH_[A-Z0-9]+)_/);
      const k = m ? m[1] : '(no SCH_ prefix)';
      byPrefix[k] = (byPrefix[k] || 0) + 1;
    });
    console.log(`     by doc-id prefix           : ${JSON.stringify(byPrefix)}`);

    // Owner field + claimedBy, which is how the earlier audit identified its
    // own UAT leftovers.
    const owners = {}, claimers = {};
    snap.forEach((d) => {
      const x = d.data() || {};
      for (const f of ['currentStudentId', 'studentId', 'ownerStudentId']) {
        if (x[f] !== undefined) owners[f] = (owners[f] || 0) + 1;
      }
      const cb = String(x.claimedBy ?? '(unset)');
      claimers[cb] = (claimers[cb] || 0) + 1;
    });
    console.log(`     owner field(s)             : ${JSON.stringify(owners)}`);
    console.log(`     claimedBy                  : ${JSON.stringify(claimers)}`);
    console.log('');
  }

  // ── Do any students carry an SRNO field at all? ──────────────────────
  const stu = await db.collection('students').get();
  const srFields = {};
  let anySr = 0;
  stu.forEach((d) => {
    const x = d.data() || {};
    let has = false;
    for (const k of Object.keys(x)) {
      if (/^sr\s*no|^srno|^sr_no|^srNumber/i.test(k) && String(x[k] ?? '').trim() !== '') {
        srFields[k] = (srFields[k] || 0) + 1;
        has = true;
      }
    }
    if (has) anySr++;
  });
  console.log('  ── students');
  console.log(`     total                      : ${stu.size}`);
  console.log(`     carrying a non-empty SR field : ${anySr}`);
  console.log(`     SR field names seen        : ${Object.keys(srFields).length
    ? JSON.stringify(srFields) : '(none)'}`);

  console.log('\n  Nothing was written.');
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
