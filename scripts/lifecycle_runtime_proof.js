/**
 * ══════════════════════════════════════════════════════════════════════════
 *  RUNTIME LIFECYCLE PROOF — drives real transitions, reconciles after each
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Executes the lifecycle end to end against a Firestore EMULATOR by invoking
 * `Lifecycle_probe`, which dispatches into the real `Sis` / `Classes` /
 * `School_config` endpoints. Nothing here re-implements business logic; this
 * file only sequences the transitions and then READS the result back.
 *
 * After every transition it reconciles the surfaces that transition is
 * supposed to move — the authoritative student document, the identity
 * indexes, the fee book, and the history trail — and reports what it found.
 * A transition that "succeeded" while leaving a surface behind is exactly the
 * defect class this programme has been chasing, and only a read-back can see
 * it.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────────
 * Refuses to run unless FIRESTORE_EMULATOR_HOST points at a loopback
 * emulator. `Lifecycle_probe` refuses independently. Two gates, because this
 * one issues WRITES through production code paths.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/lifecycle_runtime_proof.js
 *
 * exit 0 = every transition behaved and reconciled
 *      2 = a transition failed or left a surface inconsistent
 */
const admin = require('firebase-admin');
const { execFileSync } = require('child_process');

const EMU     = process.env.FIRESTORE_EMULATOR_HOST || '';
const SCHOOL  = process.env.ZX_SCHOOL  || 'SCH_EMUFIXTURE1';
const SESSION = process.env.ZX_SESSION || '2026-27';
const NEXT    = '2027-28';
const ADMIN_ID   = 'SSA9001';
const ADMIN_ROLE = 'School Super Admin';

const host = EMU.replace(/^https?:\/\//, '').split(':')[0];
if (!EMU || !['127.0.0.1', 'localhost', '::1'].includes(host)) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.');
  console.error('This driver issues real lifecycle WRITES through production code paths.');
  process.exit(1);
}

admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

let failures = 0;
const fail = (m) => { failures++; console.log(`     ✗ ${m}`); };
const ok   = (m) => console.log(`     ✓ ${m}`);

/** Run one transition through the real endpoint; return its parsed JSON. */
function step(...args) {
  const env = {
    ...process.env,
    FIRESTORE_EMULATOR_HOST: EMU,
    ZX_ADMIN_ID: ADMIN_ID, ZX_ADMIN_ROLE: ADMIN_ROLE,
    ZX_SCHOOL: SCHOOL, ZX_SESSION: SESSION,
    ZX_FROM_CLASS: 'Class 5th', ZX_FROM_SECTION: 'Section A',
  };
  let out = '';
  try {
    out = execFileSync('php', ['index.php', 'lifecycle_probe', 'step', ...args],
      { env, encoding: 'utf8', timeout: 120000 });
  } catch (e) {
    out = (e.stdout || '') + (e.stderr || '');
  }
  const m = out.match(/\{[\s\S]*\}\s*$/);
  if (!m) return { status: 'error', message: `no JSON response: ${out.slice(0, 300)}` };
  try { return JSON.parse(m[0]); } catch (e) { return { status: 'error', message: out.slice(0, 300) }; }
}

const studentDoc = async (sid) =>
  (await db.collection('students').doc(`${SCHOOL}_${sid}`).get()).data() || null;

const demandsOf = async (sid) => {
  const s = await db.collection('feeDemands')
    .where('schoolId', '==', SCHOOL).where('studentId', '==', sid).get();
  const byStatus = {};
  s.forEach((d) => {
    const st = String((d.data() || {}).status || '?');
    byStatus[st] = (byStatus[st] || 0) + 1;
  });
  return { total: s.size, byStatus };
};

const historyOf = async (sid) => {
  const s = await db.collection('studentHistory')
    .where('schoolId', '==', SCHOOL).where('studentId', '==', sid).get();
  return s.docs.map((d) => String((d.data() || {}).action || '?'));
};

(async () => {
  console.log(`RUNTIME LIFECYCLE PROOF — ${SCHOOL} on emulator ${EMU}\n`);
  console.log('Every transition below is executed through the REAL controller.\n');

  // ══ 1. ADMISSION ═══════════════════════════════════════════════════════
  console.log('  [1] ADMISSION');
  const admitRes = step('admit', 'ZXLCPROBE Runtime', '5', 'A');
  if (admitRes.status !== 'success') {
    console.log(`     ✗ admission failed: ${admitRes.message}`);
    process.exit(2);
  }
  const SID = admitRes.user_id;
  // Deliberately does not print the generated password the endpoint returns.
  console.log(`     studentId=${SID}  srNo=${admitRes.sr_no}  admissionNo=${admitRes.admission_no}`);
  console.log(`     billing_state=${admitRes.billing_state}`);

  let doc = await studentDoc(SID);
  if (!doc) { console.log('     ✗ no student document was written'); process.exit(2); }
  doc.status === 'Active' ? ok('student is Active') : fail(`status is '${doc.status}', expected Active`);
  String(doc.srNo || '') !== '' ? ok(`SRNO ${doc.srNo} is on the student document`)
    : fail('the student carries no SRNO — the admission invariant is violated');
  doc.className === 'Class 5th' ? ok('class is Class 5th') : fail(`class is '${doc.className}'`);
  doc.session === SESSION ? ok(`session is ${SESSION}`) : fail(`session is '${doc.session}'`);

  // The SRNO claim must exist and point back, or the number is unprotected.
  const claim = (await db.collection('srNoIndex').doc(`${SCHOOL}_${doc.srNo}`).get()).data();
  claim ? ok('srNoIndex claim exists') : fail('no srNoIndex claim — the SRNO is not reserved');
  if (claim && claim.currentStudentId !== SID) {
    fail(`srNoIndex claims '${claim.currentStudentId}', expected ${SID}`);
  } else if (claim) ok('srNoIndex points back at this student');

  let fees = await demandsOf(SID);
  fees.total > 0 ? ok(`fee book created: ${fees.total} demand(s) ${JSON.stringify(fees.byStatus)}`)
    : fail('admitted with ZERO demands — Active-with-no-fee-book');
  const demandsAtAdmission = fees.total;

  // ══ 2. SECTION TRANSFER ════════════════════════════════════════════════
  console.log('\n  [2] SECTION TRANSFER  (5/A -> 5/B)');
  const tRes = step('transfer', SID, 'Class 5th', 'Section B');  // from_* supplied via env below
  if (tRes.status === 'success') {
    doc = await studentDoc(SID);
    doc.section === 'Section B' ? ok('section moved to Section B')
      : fail(`section is '${doc.section}' after transfer`);
    doc.className === 'Class 5th' ? ok('class unchanged') : fail(`class changed to '${doc.className}'`);
    String(doc.srNo) === String(admitRes.sr_no)
      ? ok('SRNO survived the transfer')
      : fail(`SRNO changed from ${admitRes.sr_no} to ${doc.srNo}`);
  } else {
    fail(`transfer failed: ${tRes.message}`);
  }

  // ══ 3. STATUS Active -> Inactive -> Active ═════════════════════════════
  console.log('\n  [3] STATUS  Active -> Inactive');
  const offRes = step('change_status', SID, 'Inactive');
  if (offRes.status === 'success') {
    doc = await studentDoc(SID);
    doc.status === 'Inactive' ? ok('status is Inactive') : fail(`status is '${doc.status}'`);
    fees = await demandsOf(SID);
    (fees.byStatus.frozen || 0) > 0
      ? ok(`fee book frozen (${fees.byStatus.frozen} demand(s)) — LC-1 holds at runtime`)
      : fail(`fee book NOT frozen on deactivation: ${JSON.stringify(fees.byStatus)}`);
  } else fail(`change_status failed: ${offRes.message}`);

  console.log('\n      STATUS  Inactive -> Active');
  const onRes = step('change_status', SID, 'Active');
  if (onRes.status === 'success') {
    doc = await studentDoc(SID);
    doc.status === 'Active' ? ok('status is Active again') : fail(`status is '${doc.status}'`);
    fees = await demandsOf(SID);
    (fees.byStatus.frozen || 0) === 0
      ? ok('fee book unfrozen — FRZ-4 holds at runtime')
      : fail(`${fees.byStatus.frozen} demand(s) still frozen after reactivation`);
    fees.total === demandsAtAdmission
      ? ok(`demand count unchanged (${fees.total}) — no duplicates created`)
      : fail(`demand count moved ${demandsAtAdmission} -> ${fees.total}`);
  } else fail(`reactivation failed: ${onRes.message}`);

  // ══ 4. TC ISSUE / CANCEL ═══════════════════════════════════════════════
  console.log('\n  [4] TC ISSUE');
  const tcRes = step('issue_tc', SID, 'override');
  let tcKey = tcRes.tc_key || '';
  if (tcRes.status === 'success') {
    doc = await studentDoc(SID);
    doc.status === 'TC' ? ok('status is TC') : fail(`status is '${doc.status}'`);
    fees = await demandsOf(SID);
    (fees.byStatus.frozen || 0) > 0
      ? ok(`fee book frozen on TC (${fees.byStatus.frozen}) — LC-6 holds at runtime`)
      : fail(`LC-6 REGRESSION: TC left the fee book collectable: ${JSON.stringify(fees.byStatus)}`);
  } else fail(`issue_tc failed: ${tcRes.message}`);

  console.log('\n      TC CANCEL');
  const ctRes = step('cancel_tc', SID, tcKey);
  if (ctRes.status === 'success') {
    doc = await studentDoc(SID);
    doc.status === 'Active' ? ok('status restored to Active') : fail(`status is '${doc.status}'`);
    fees = await demandsOf(SID);
    (fees.byStatus.frozen || 0) === 0
      ? ok('fee book unfrozen on TC cancel')
      : fail(`${fees.byStatus.frozen} demand(s) still frozen after TC cancel`);
    String(doc.srNo) === String(admitRes.sr_no)
      ? ok('SRNO survived TC and cancellation')
      : fail(`SRNO changed to ${doc.srNo}`);
  } else fail(`cancel_tc failed: ${ctRes.message}`);

  // ══ 5. HISTORY ═════════════════════════════════════════════════════════
  console.log('\n  [5] LIFECYCLE HISTORY');
  const hist = await historyOf(SID);
  console.log(`     recorded actions: ${hist.join(', ') || '(none)'}`);
  hist.length > 0 ? ok(`${hist.length} history entries`) : fail('no lifecycle history recorded');

  // ══ 6. PROMOTION + TERMINAL-CLASS COMPLETION ═══════════════════════════
  // A Class 5 student promotes; a Class 10 student (the fixture's terminal
  // class) must COMPLETE rather than be pushed into a Class 11 the school
  // does not have. Both are proven in one rollover.
  console.log('\n  [6] ROLLOVER — promotion and terminal-class completion');
  const gradRes = step('admit', 'ZXLCPROBE Terminal', '10', 'A');
  if (gradRes.status !== 'success') {
    fail(`could not admit a terminal-class student: ${gradRes.message}`);
  } else {
    const GID = gradRes.user_id;
    console.log(`     terminal-class student ${GID} (srNo ${gradRes.sr_no}) admitted into Class 10th`);

    const roll = step('rollover', SESSION, NEXT);
    if (roll.status !== 'success') {
      fail(`rollover failed: ${roll.message}`);
    } else {
      console.log(`     ${roll.message || '(no message)'}`);
      const promoted = await studentDoc(SID);
      const graduated = await studentDoc(GID);

      promoted && promoted.className === 'Class 6th'
        ? ok(`Class 5th student promoted to ${promoted.className}`)
        : fail(`Class 5th student is in '${promoted && promoted.className}' after rollover`);
      promoted && promoted.session === NEXT
        ? ok(`promoted student moved to session ${NEXT}`)
        : fail(`promoted student session is '${promoted && promoted.session}'`);

      graduated && graduated.status === 'Alumni'
        ? ok('terminal-class student completed schooling (status=Alumni) — LC-11 holds at runtime')
        : fail(`LC-11: terminal-class student status is '${graduated && graduated.status}', `
             + `class '${graduated && graduated.className}' — expected Alumni`);
      graduated && String(graduated.srNo) === String(gradRes.sr_no)
        ? ok('SRNO survived graduation')
        : fail(`SRNO changed on graduation to ${graduated && graduated.srNo}`);
    }
  }

  console.log('\n' + '-'.repeat(72));
  console.log(failures === 0
    ? '  ALL TRANSITIONS BEHAVED AND RECONCILED at runtime.'
    : `  ${failures} RUNTIME FAILURE(S) — see above.`);
  process.exit(failures === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
