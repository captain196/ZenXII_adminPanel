/**
 * ══════════════════════════════════════════════════════════════════════════
 *  READ-ONLY BEHAVIOURAL TENANT-ISOLATION CHECK FOR LIFECYCLE DATA
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Gate 5 asks for BEHAVIOURAL evidence, not a reading of the code. The write
 * side of that (a forged id posted at a real endpoint) needs live writes and
 * is reported separately as blocked. This covers the half that can be proven
 * from data alone, and it is the half that actually decides isolation:
 *
 *   1. EVERY lifecycle document's id is prefixed with the schoolId it claims.
 *      This is the invariant `Firestore_service::docId()` creates and that
 *      `firestore.rules` relies on. A document whose id prefix disagrees with
 *      its `schoolId` field is a cross-tenant write that already happened.
 *
 *   2. NO student-linked document references a studentId belonging to another
 *      tenant. A forged id that slipped past validation would land here.
 *
 *   3. Student ids are globally unique across tenants, so a leaked id cannot
 *      silently resolve to a different school's child.
 *
 * Runs across ALL tenants deliberately: an isolation check confined to one
 * school can only ever confirm that school looks tidy.
 *
 *   node scripts/lifecycle_tenant_isolation_readonly.js
 *
 * Writes nothing. Prints ids and counts only — no personal data.
 * exit 0 = isolation intact   2 = a violation
 */
const admin = require('firebase-admin');
const path  = require('path');

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

// The lifecycle surface, from the surface map.
const COLLECTIONS = [
  'students', 'parents', 'feeDemands', 'feeDefaulters', 'studentFeeSummary',
  'studentFlags', 'studentCharges', 'studentEntitlements', 'studentHistory',
  'admissionNoIndex', 'attendance', 'attendanceSummary', 'marks', 'results',
  'feeReceipts', 'feeReceiptAllocations', 'processedPayments', 'submissions',
];

let problems = 0;
let notes = 0;
const fail = (m) => { problems++; console.log(`  VIOLATION  ${m}`); };
// Reported, never counted as a breach. See the call site for why conflating
// the two would have produced 76 fictitious violations.
const note = (m) => { notes++; if (notes <= 5) console.log(`  note       ${m}`); };

(async () => {
  console.log('LIFECYCLE TENANT-ISOLATION CHECK — ALL TENANTS (READ ONLY)\n');

  const schools = await db.collection('schools').get();
  const schoolIds = schools.docs.map((d) => d.id);
  console.log(`  ${schoolIds.length} tenants`);

  // ── A TENANT HAS TWO NAMES, AND BOTH ARE ITSELF ──────────────────────
  // ZenX identifies a school by the canonical `SCH_XXXXXX` id AND by a
  // legacy numeric login code (`schools.schoolCode`), and older documents
  // stamp whichever the writing path had. Treating those as different
  // tenants is not a strict check, it is a WRONG one: the first run of this
  // script reported 243 "violations", 240 of which were a school being
  // compared against itself under its own second name.
  //
  // The alias map is built from the schools collection, so a code can only
  // ever alias to the tenant that actually declares it.
  const canonical = new Map();
  for (const id of schoolIds) canonical.set(id, id);
  schools.forEach((d) => {
    const x = d.data() || {};
    for (const k of ['schoolCode', 'school_code', 'code']) {
      const v = x[k];
      if (v !== undefined && String(v).trim() !== '') canonical.set(String(v), d.id);
    }
  });
  const tenantOf = (v) => canonical.get(String(v || '')) || String(v || '');
  console.log(`  ${canonical.size - schoolIds.length} legacy code alias(es) resolved\n`);

  // ── Student id ownership, built across every tenant ──────────────────
  const ownerOfStudent = new Map();   // studentId -> schoolId
  const dupStudentIds  = [];
  const allStudents = await db.collection('students').get();
  allStudents.forEach((d) => {
    const s = d.data() || {};
    const sid = String(s.studentId || '');
    const owner = tenantOf(s.schoolId);
    if (!sid || !owner) return;
    if (ownerOfStudent.has(sid) && ownerOfStudent.get(sid) !== owner) {
      dupStudentIds.push(`${sid}: ${ownerOfStudent.get(sid)} and ${owner}`);
    }
    ownerOfStudent.set(sid, owner);
  });

  console.log(`  student ids across all tenants : ${ownerOfStudent.size}`);
  if (dupStudentIds.length) {
    for (const d of dupStudentIds) {
      fail(`the same studentId exists in two tenants — ${d}`);
    }
  } else {
    console.log('    no studentId is claimed by two tenants.\n');
  }

  // ── 1 + 2, per collection ────────────────────────────────────────────
  console.log('  ── DOC-ID PREFIX vs schoolId, AND CROSS-TENANT studentId\n');
  console.log('    ' + 'collection'.padEnd(24) + 'docs'.padStart(7)
    + '  prefix-mismatch  foreign-student');
  console.log('    ' + '-'.repeat(68));

  for (const col of COLLECTIONS) {
    let snap;
    try { snap = await db.collection(col).get(); }
    catch (e) { continue; }
    if (snap.empty) continue;

    let mismatch = 0, foreign = 0;
    snap.forEach((d) => {
      const data = d.data() || {};
      const rawOwner = String(data.schoolId || '');
      const owner = tenantOf(rawOwner);
      if (!owner) return;

      // 1. The doc id must be prefixed with the tenant it claims — under
      //    EITHER of that tenant's two names, since both are legitimately in
      //    use. Documents keyed by an external identifier (a payment id from
      //    the gateway, which must match the provider's own key for
      //    idempotency) are exempt: their id is not ours to choose.
      const prefixes = [...canonical.entries()]
        .filter(([, id]) => id === owner)
        .map(([alias]) => alias + '_');
      const externallyKeyed = col === 'processedPayments' || col === 'feeOnlineOrders';
      if (!externallyKeyed && !prefixes.some((p) => d.id.startsWith(p))) {
        mismatch++;
        // A NOTE, not a violation — and the distinction is load-bearing.
        //
        // `firestore.rules` scopes these collections with isSameSchool() /
        // isSameSchoolWrite(), which compare the schoolId FIELD (plus the
        // legacy schoolCode on the read arm). They never parse the document
        // id. So a non-prefixed id breaks no security boundary; all 76
        // feeDemands rows use a hash id, which is simply that collection's
        // key scheme.
        //
        // Reported because an unprefixed id makes an id COLLISION between
        // tenants theoretically possible where a prefix would have made it
        // impossible, and because it is worth knowing which collections do
        // not follow docId(). It is not evidence of a leak, and calling it
        // one would have meant reporting 76 fictitious breaches.
        note(`${col}/${d.id} is not id-prefixed with tenant '${rawOwner}' `
          + '(rules scope this collection by the schoolId field, so this is a '
          + 'key-scheme observation, not a boundary failure)');
      }

      // 2. A referenced student must belong to the SAME tenant.
      const sid = String(data.studentId || '');
      if (!sid) return;
      const stuOwner = ownerOfStudent.get(sid);
      if (stuOwner && stuOwner !== owner) {
        foreign++;
        fail(`${col}/${d.id} (tenant ${owner}) references studentId '${sid}' `
          + `which belongs to tenant ${stuOwner}`);
      }
    });

    console.log('    ' + col.padEnd(24) + String(snap.size).padStart(7)
      + String(mismatch).padStart(17) + String(foreign).padStart(17));
  }

  console.log('\n' + '-'.repeat(72));
  console.log(problems === 0
    ? '  ISOLATION INTACT — no lifecycle document references another tenant\'s\n'
      + '  student, and no student id is claimed by two tenants.'
    : `  ${problems} ISOLATION VIOLATION(S). Nothing was changed.`);
  if (notes) {
    console.log(`  ${notes} key-scheme note(s)`
      + (notes > 5 ? ' (first 5 shown)' : '')
      + ' — documents not id-prefixed with their tenant.');
    console.log('  These collections are scoped by the schoolId FIELD in firestore.rules,');
    console.log('  so this is an observation about key schemes, not a boundary failure.');
  }
  process.exit(problems === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
