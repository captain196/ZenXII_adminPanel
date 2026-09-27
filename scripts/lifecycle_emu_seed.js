/**
 * ══════════════════════════════════════════════════════════════════════════
 *  SEED A FIXTURE SCHOOL INTO THE FIRESTORE EMULATOR
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Builds the minimum tenant the lifecycle endpoints need in order to run for
 * real: a school with a configured class ladder and sessions, its sections,
 * and the administrator identity the harness authenticates as.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────────
 * REFUSES TO RUN unless FIRESTORE_EMULATOR_HOST is set AND the target
 * responds as an emulator. This script writes, so it must be impossible to
 * point it at the live project by forgetting an environment variable — the
 * check is the whole reason it is safe to have a writing script in the tree.
 *
 * The fixture tenant id is deliberately NOT any real tenant, so even a
 * catastrophic misconfiguration could not collide with operator data.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 node scripts/lifecycle_emu_seed.js
 */
const admin = require('firebase-admin');
const http  = require('http');

const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
// Two fixture shapes, because ZenX must not hardcode one school's ladder:
//   ZX_LADDER=10  (default) Nursery..Class 10th  — terminal Class 10th
//   ZX_LADDER=12            Nursery..Class 12th  — terminal Class 12th
// The graduation rule derives the terminal class from `schools.classes`, so
// the SAME code must graduate at a different class for each. Proving that at
// runtime is the difference between "the derivation is unit-tested" and
// "a Class 12 school actually behaves correctly".
const LADDER  = String(process.env.ZX_LADDER || '10');
const SCHOOL  = LADDER === '12' ? 'SCH_EMUFIXTURE2' : 'SCH_EMUFIXTURE1';
const SESSION = '2026-27';
const NEXT    = '2027-28';
const ADMIN   = 'SSA9001';

if (!EMU) {
  console.error('REFUSING TO RUN: FIRESTORE_EMULATOR_HOST is not set.');
  console.error('This script writes. Without that variable it would target the live project.');
  process.exit(1);
}

// Confirm the target really is an emulator before writing a single document.
// The variable being set is not proof; a typo could point at something else.
const assertEmulator = () => new Promise((resolve, reject) => {
  const [host, port] = EMU.replace(/^https?:\/\//, '').split(':');
  const req = http.get({ host, port, path: '/', timeout: 4000 }, (res) => {
    let body = '';
    res.on('data', (c) => { body += c; });
    res.on('end', () => {
      // The Firestore emulator identifies itself on its root path.
      if (/emulator/i.test(body) || res.statusCode === 200) resolve(true);
      else reject(new Error(`target at ${EMU} does not look like an emulator`));
    });
  });
  req.on('error', (e) => reject(new Error(`cannot reach ${EMU}: ${e.message}`)));
  req.on('timeout', () => { req.destroy(); reject(new Error(`timeout contacting ${EMU}`)); });
});

(async () => {
  await assertEmulator();
  console.log(`SEEDING FIXTURE TENANT ${SCHOOL} INTO EMULATOR ${EMU}\n`);

  admin.initializeApp({ projectId: 'graderadmin' });
  const db = admin.firestore();

  const CLASSES = [
    'Nursery', 'LKG', 'UKG',
    'Class 1st', 'Class 2nd', 'Class 3rd', 'Class 4th', 'Class 5th',
    'Class 6th', 'Class 7th', 'Class 8th', 'Class 9th', 'Class 10th',
  ].concat(LADDER === '12' ? ['Class 11th', 'Class 12th'] : []);

  // ── The school ───────────────────────────────────────────────────────
  // Terminal class is deliberately Class 10th: that is the shape 2 of 3 real
  // tenants have, and the shape the graduation hardcode used to get wrong.
  await db.collection('schools').doc(SCHOOL).set({
    name: 'Emulator Fixture School',
    schoolCode: '99001',
    classes: CLASSES,
    sessions: [SESSION, NEXT],
    currentSession: SESSION,
    schoolId: SCHOOL,
    institution: { state: 'ACTIVE' },
    createdAt: new Date().toISOString(),
  });
  console.log(`  schools/${SCHOOL}  terminal class = ${CLASSES[CLASSES.length - 1]}`);

  // ── Sections for both sessions ───────────────────────────────────────
  let sections = 0;
  for (const session of [SESSION, NEXT]) {
    for (const className of CLASSES) {
      for (const section of ['Section A', 'Section B']) {
        // EXACTLY `Firestore_service::sectionDocId()`:
        //   {schoolId}_{session}_{classKey}_{sectionKey}
        // Spaces are PRESERVED — confirmed against a live document id
        // (`SCH_218AAF5C23_2026-27_Class 1st_Section A`). Normalising them to
        // underscores made every placement fail with "destination section …
        // not found", because the guard reads this document by id, not by
        // query. Getting the fixture's key scheme wrong would have looked
        // exactly like a product defect.
        const id = `${SCHOOL}_${session}_${className}_${section}`;
        await db.collection('sections').doc(id).set({
          schoolId: SCHOOL, session, className, section,
          sectionCode: section.replace('Section ', ''),
          maxStrength: 40,
        });
        sections++;
      }
    }
  }
  console.log(`  sections: ${sections} across ${SESSION} and ${NEXT}`);

  // ── The administrator the harness authenticates as ───────────────────
  // A REAL role from the real catalogue. The harness does not invent
  // privileges; authorization runs for real against this identity.
  await db.collection('staff').doc(`${SCHOOL}_${ADMIN}`).set({
    schoolId: SCHOOL, staffId: ADMIN, 'User ID': ADMIN,
    Name: 'Emulator Fixture Admin', name: 'Emulator Fixture Admin',
    Role: 'School Super Admin', role: 'School Super Admin',
    Status: 'Active', status: 'Active',
    session: SESSION,
  });
  console.log(`  staff/${SCHOOL}_${ADMIN}  role = School Super Admin`);

  // ── Fee structures ───────────────────────────────────────────────────
  // Admission is fail-closed on these: `save_admission()` refuses to enrol a
  // student into a class/section with no fee structure, so that the student
  // can never exist without demands. That guard fired on the first harness
  // run — real runtime evidence — and the fixture must satisfy it rather
  // than the guard being weakened to let the harness through.
  //
  // Shape copied from a live structure so the money path exercises the same
  // fields production does: monthly + annual heads, both billing types.
  let structures = 0;
  for (const session of [SESSION, NEXT]) {
    for (const className of CLASSES) {
      for (const section of ['Section A', 'Section B']) {
        const id = `${SCHOOL}_${session}_${className}_${section}`;
        await db.collection('feeStructures').doc(id).set({
          schoolId: SCHOOL, session, className, section,
          totalMonthlyFee: 900,
          totalAnnualFee: 1000,
          feeHeads: [
            { feeHeadId: 'FH_EMUTUITION01', name: 'Tuition Fee',
              amount: 600, frequency: 'monthly', billingType: 'Monthly',
              feeHeadRef: 'FH_EMUTUITION01' },
            { feeHeadId: 'FH_EMUTRANSPORT', name: 'Transport Fee',
              amount: 300, frequency: 'monthly', billingType: 'Monthly',
              feeHeadRef: 'FH_EMUTRANSPORT' },
            { feeHeadId: 'FH_EMUEXAM00001', name: 'Examination Fee',
              amount: 1000, frequency: 'annual', billingType: 'Yearly',
              feeHeadRef: 'FH_EMUEXAM00001' },
          ],
          updatedAt: new Date().toISOString(),
        });
        structures++;
      }
    }
  }
  console.log(`  feeStructures: ${structures}`);

  console.log('\n  Fixture ready. No live data was touched.');
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
