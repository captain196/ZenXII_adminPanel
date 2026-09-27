/**
 * ══════════════════════════════════════════════════════════════════════════
 *  READ-ONLY SRNO IDENTITY + LOOKUP AUDIT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Proves the identity properties the lifecycle depends on, against real data
 * rather than against the code that is supposed to maintain them:
 *
 *   1. UNIQUENESS is per SCHOOL. Two students in one school must never hold
 *      the same SRNO. Two students in DIFFERENT schools holding SRNO 1 is
 *      correct and must not be reported.
 *   2. The index and the student agree in BOTH directions — every claim
 *      points at a student who carries that number, and every numbered
 *      student has a claim.
 *   3. A lookup can never cross a tenant boundary.
 *   4. `historicalStudentIds[]` preserves continuity across re-admission, so
 *      an SRNO is not silently rebound to an unrelated student.
 *
 * ── THE NAMESPACE TRAP THIS ACCOUNTS FOR ─────────────────────────────────
 * `srNoIndex` is partially migrated (forensic audit 2026-08-19): document ids
 * are canonical (`SCH_XXXX_{n}`) while the stored `schoolId` FIELD carries
 * whichever key the caller passed — `Sis::_claimIdentityAttributes` passes the
 * legacy login code ("10012"), `Readmission` passes the canonical id.
 *
 * A tenant-filtered query on the canonical id therefore returns NOTHING for
 * rows written under the legacy code, and an audit that used one namespace
 * would report "clean" while seeing none of the data. This resolves tenancy
 * from the DOC ID, which is canonical in every row, and treats the field as
 * advisory.
 *
 *   node scripts/srno_lookup_readonly.js
 *   node scripts/srno_lookup_readonly.js --selftest
 *
 * Writes nothing.
 * exit 0 = identity intact   2 = an integrity violation
 */
const admin = require('firebase-admin');
const path  = require('path');

// ── PURE RULES, so they can be proven without Firestore ──────────────────
const normSr = (v) => String(v ?? '').trim().replace(/^0+(?=\d)/, '').toUpperCase();

/** Tenancy from the canonical doc id, never from the drifting field. */
const tenantFromDocId = (docId) => {
  const m = String(docId || '').match(/^(SCH_[A-Z0-9]+)_(.+)$/);
  return m ? { school: m[1], key: m[2] } : null;
};

if (process.argv.includes('--selftest')) {
  const cases = [
    ['plain number',        normSr('7'), '7'],
    ['leading zeros drop',  normSr('007'), '7'],
    ['whitespace trimmed',  normSr('  12 '), '12'],
    ['case folded',         normSr('a12'), 'A12'],
    ['all-zero survives',   normSr('0'), '0'],
    ['blank stays blank',   normSr(''), ''],
    ['docid split',         JSON.stringify(tenantFromDocId('SCH_B56BB9A401_3')),
                            JSON.stringify({ school: 'SCH_B56BB9A401', key: '3' })],
    ['docid with underscore', JSON.stringify(tenantFromDocId('SCH_X1_2026_ABC')),
                            JSON.stringify({ school: 'SCH_X1', key: '2026_ABC' })],
    ['legacy-coded id rejected', String(tenantFromDocId('10012_3')), 'null'],
  ];
  let bad = 0;
  console.log('SRNO LOOKUP AUDIT — SELFTEST (no Firestore)\n');
  for (const [name, got, want] of cases) {
    const ok = String(got) === String(want);
    if (!ok) bad++;
    console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name.padEnd(24)} got=${JSON.stringify(got)} want=${JSON.stringify(want)}`);
  }
  console.log('\n' + (bad === 0
    ? '  IDENTITY RULES VERIFIED.'
    : `  ${bad} SELFTEST FAILURE(S) — this audit cannot be trusted.`));
  process.exit(bad === 0 ? 0 : 2);
}

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

let problems = 0, notes = 0;
const fail = (m) => { problems++; console.log(`  VIOLATION  ${m}`); };
const note = (m) => { notes++; console.log(`  note       ${m}`); };

(async () => {
  console.log('SRNO IDENTITY + LOOKUP AUDIT — ALL TENANTS (READ ONLY)\n');

  // ── Students, and the SRNO each one carries ──────────────────────────
  const stu = await db.collection('students').get();
  const byStudent = new Map();          // studentId -> {school, srNo}
  const perSchoolSr = new Map();        // school -> Map(normSr -> [studentId])
  let numbered = 0;

  stu.forEach((d) => {
    const x = d.data() || {};
    const t = tenantFromDocId(d.id);
    const school = t ? t.school : String(x.schoolId || '');
    const sid = String(x.studentId || (t ? t.key : d.id));
    const sr = normSr(x.srNo);
    byStudent.set(sid, { school, srNo: sr, status: String(x.status || 'Active') });
    if (sr === '') return;
    numbered++;
    if (!perSchoolSr.has(school)) perSchoolSr.set(school, new Map());
    const m = perSchoolSr.get(school);
    m.set(sr, [...(m.get(sr) || []), sid]);
  });

  console.log(`  students                 : ${stu.size}`);
  console.log(`  carrying an SRNO         : ${numbered}`);
  console.log(`  without one (legacy)     : ${stu.size - numbered}`);
  console.log('    Students admitted before SRNO enforcement legitimately have none.');
  console.log('    That population is school-controlled and must not be auto-numbered.\n');

  // ── 1. UNIQUENESS, PER SCHOOL ────────────────────────────────────────
  console.log('  ── UNIQUENESS (per school, which is the documented scope)\n');
  let dupes = 0;
  for (const [school, m] of perSchoolSr) {
    for (const [sr, ids] of m) {
      if (ids.length > 1) {
        dupes++;
        fail(`school ${school} has SRNO '${sr}' on ${ids.length} students: ${ids.join(', ')}`);
      }
    }
    console.log(`    ${school.padEnd(20)} ${m.size} distinct SRNO(s)`);
  }
  if (dupes === 0) console.log('    no SRNO is held by two students within any school.');

  // Cross-school reuse is CORRECT. Reported so the scope is visible, and
  // deliberately not counted as a problem.
  const srToSchools = new Map();
  for (const [school, m] of perSchoolSr) {
    for (const sr of m.keys()) {
      srToSchools.set(sr, [...(srToSchools.get(sr) || []), school]);
    }
  }
  const shared = [...srToSchools.entries()].filter(([, s]) => s.length > 1);
  console.log(`\n    SRNOs reused across different schools: ${shared.length}`);
  console.log('    (correct — uniqueness is per school, never global)');

  // ── 2. INDEX <-> STUDENT AGREEMENT, BOTH WAYS ────────────────────────
  console.log('\n  ── INDEX INTEGRITY\n');
  const idx = await db.collection('srNoIndex').get();
  console.log(`    srNoIndex documents      : ${idx.size}`);

  const claimed = new Map();   // school -> Map(normSr -> currentStudentId)
  idx.forEach((d) => {
    const x = d.data() || {};
    const t = tenantFromDocId(d.id);
    if (!t) { fail(`srNoIndex/${d.id} has no canonical SCH_ prefix — tenancy is unresolvable`); return; }
    const sr = normSr(x.srNo ?? t.key);
    const owner = String(x.currentStudentId || '');
    if (!claimed.has(t.school)) claimed.set(t.school, new Map());
    claimed.get(t.school).set(sr, owner);

    // The doc id must agree with the srNo field it stores, or the claim
    // guarantees uniqueness for a number other than the one it records.
    if (normSr(t.key) !== sr) {
      fail(`srNoIndex/${d.id} is keyed '${t.key}' but records srNo '${x.srNo}' — `
        + 'the uniqueness guarantee and the stored number disagree');
    }

    if (!owner) { note(`srNoIndex/${d.id} has no currentStudentId`); return; }

    const s = byStudent.get(owner);
    if (!s) {
      note(`srNoIndex/${d.id} points at '${owner}', which has no student document `
        + '(an identity claim deliberately outlives a deleted student)');
      return;
    }
    if (s.school !== t.school) {
      fail(`srNoIndex/${d.id} (school ${t.school}) claims student '${owner}' of school ${s.school} `
        + '— a cross-tenant identity binding');
    }
    if (s.srNo !== sr) {
      fail(`srNoIndex/${d.id} claims SRNO '${sr}' for '${owner}', but that student carries `
        + `'${s.srNo || '(none)'}' — index and student disagree`);
    }
  });

  // Reverse: a numbered student must have a claim, or the number is not
  // protected against being handed to someone else.
  let unclaimed = 0;
  for (const [school, m] of perSchoolSr) {
    for (const [sr, ids] of m) {
      const owner = claimed.get(school)?.get(sr);
      if (owner === undefined) {
        unclaimed++;
        fail(`student(s) ${ids.join(', ')} carry SRNO '${sr}' in ${school} with NO srNoIndex claim `
          + '— nothing stops that number being allocated to another student');
      } else if (!ids.includes(owner)) {
        fail(`SRNO '${sr}' in ${school} is claimed by '${owner}' but carried by ${ids.join(', ')}`);
      }
    }
  }
  if (unclaimed === 0) console.log('    every numbered student has a matching index claim.');

  // ── 3. CONTINUITY ACROSS RE-ADMISSION ────────────────────────────────
  console.log('\n  ── CONTINUITY\n');
  let withHistory = 0;
  idx.forEach((d) => {
    const x = d.data() || {};
    const hist = Array.isArray(x.historicalStudentIds) ? x.historicalStudentIds : [];
    if (!hist.length) return;
    withHistory++;
    const owner = String(x.currentStudentId || '');
    if (owner && !hist.includes(owner)) {
      note(`srNoIndex/${d.id} current owner '${owner}' is not in historicalStudentIds `
        + `[${hist.join(', ')}]`);
    }
  });
  console.log(`    claims carrying historicalStudentIds: ${withHistory}`);
  console.log('    (this is what lets one SRNO follow a student through re-admission');
  console.log('     without being rebound to an unrelated child)');

  console.log('\n' + '-'.repeat(72));
  console.log(problems === 0
    ? '  SRNO IDENTITY INTACT — unique within every school, index and students\n'
      + '  agree in both directions, and no claim crosses a tenant boundary.'
    : `  ${problems} SRNO INTEGRITY VIOLATION(S). Nothing was changed.`);
  if (notes) console.log(`  ${notes} note(s) — surfaced, not counted as failures.`);
  process.exit(problems === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
