/**
 * SEC-8 — B-022 RESOLUTION · APPEND-ONLY DELETE DENIAL.
 *
 * ── THE POINT OF THIS SUITE IS THE OPPOSITE OF THE OTHERS ─────────────────
 * Every other SEC suite is DIFFERENTIAL: it must fail against the unfixed live
 * pull, because it changes behaviour.
 *
 * This one must pass IDENTICALLY against BOTH the live pull and the artifact,
 * because the artifact deliberately changes NOTHING behaviourally. `marks` and
 * `academicAuditLog` deletes were already refused — via a null dereference on
 * `request.resource`, which raises an evaluation error. SEC-8 makes that denial
 * explicit and intentional so a future author "repairing" the broken predicate
 * cannot accidentally open it.
 *
 * A pass against both rulesets is therefore the CORRECT result here, and is the
 * evidence that this is a tightening of intent rather than a grant.
 *
 * Run it both ways:
 *   SEC8_RULES=<live pull>  -> all pass  (delete already refused)
 *   SEC8_RULES=<artifact>   -> all pass  (delete refused on purpose)
 *
 * ── WHY DELETION STAYS PROHIBITED ─────────────────────────────────────────
 * Established from the codebase, not inferred from the rule's shape:
 *   - `Audit_log_service.php` calls itself a "write-only audit trail" and
 *     exposes only `log()`; no delete method exists.
 *   - Neither app deletes marks; the Teacher app's only client deletes are
 *     homework and stories.
 *   - The Teacher app corrects marks with `setDocument(merge = true)` plus a
 *     `status: "draft"` field — correction is overwrite, not deletion.
 *   - The admin panel deletes `exams` and `examSchedule` but never `marks`.
 *   - `Firestore_helper` documents `marksAudit` as an "append-only marks change
 *     trail", a design that assumes marks do not silently vanish.
 *
 * A genuine back-office correction path remains available through the Admin
 * SDK, which bypasses these rules entirely.
 */

const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');

const PROJECT_ID = 'zenxii-rules-test';
const SCHOOL_A = 'SCH_TENANT_AAA';
const SCHOOL_B = 'SCH_TENANT_BBB';

const RULES_PATH = process.env.SEC8_RULES || '';
const HAVE_RULES = RULES_PATH && fs.existsSync(RULES_PATH);

jest.setTimeout(60000);

let env;
const DBS = {};

beforeAll(async () => {
  if (!HAVE_RULES) return;
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });

  DBS.staffA = env.authenticatedContext('STA0001', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Teacher',
  }).firestore();
  DBS.adminA = env.authenticatedContext('admin_A', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Admin',
  }).firestore();
  DBS.staffB = env.authenticatedContext('STA9999', {
    school_id: SCHOOL_B, schoolId: SCHOOL_B, role: 'Teacher',
  }).firestore();
});

afterAll(async () => { if (env) await env.cleanup(); });

async function seedTenants() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL_A, SCHOOL_B]) {
      await db.collection('schools').doc(s).set({ schoolId: s });
      await db.collection('schoolControl').doc(s).set({ lifecycle: { state: 'active' } });
    }
  });
}

async function seedDoc(col, docId, data) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    await ctx.firestore().collection(col).doc(docId).set(data);
  });
}

const maybe = HAVE_RULES ? describe : describe.skip;

maybe('SEC-8 · append-only delete denial (behaviour must be IDENTICAL on both rulesets)', () => {
  beforeEach(async () => {
    await env.clearFirestore();
    await seedTenants();
  });

  // ══ DELETE STAYS PROHIBITED ═════════════════════════════════════════════

  [
    { col: 'marks', doc: { schoolId: SCHOOL_A, studentId: 'STU0001', total: 88, status: 'draft' } },
    { col: 'academicAuditLog', doc: { schoolId: SCHOOL_A, action: 'status_change', entityId: 'T1' } },
  ].forEach(({ col, doc }) => {
    test(`${col}: same-school staff delete is REFUSED`, async () => {
      await seedDoc(col, `${SCHOOL_A}_D1`, doc);
      await assertFails(DBS.staffA.collection(col).doc(`${SCHOOL_A}_D1`).delete());
    });

    test(`${col}: same-school ADMIN delete is REFUSED`, async () => {
      await seedDoc(col, `${SCHOOL_A}_D2`, doc);
      await assertFails(DBS.adminA.collection(col).doc(`${SCHOOL_A}_D2`).delete());
    });

    test(`${col}: cross-school delete is REFUSED`, async () => {
      await seedDoc(col, `${SCHOOL_B}_D3`, { ...doc, schoolId: SCHOOL_B });
      await assertFails(DBS.staffA.collection(col).doc(`${SCHOOL_B}_D3`).delete());
    });

    test(`${col}: deleting a NON-EXISTENT document is REFUSED`, async () => {
      await assertFails(DBS.staffA.collection(col).doc(`${SCHOOL_A}_NOPE`).delete());
    });
  });

  // ══ CREATE AND UPDATE MUST SURVIVE UNTOUCHED ════════════════════════════
  //
  // Splitting `allow write` into `allow create, update` + `allow delete` must
  // not disturb the write path. If it did, the Teacher app would stop being
  // able to save marks — a far worse outcome than the accident being fixed.

  test('marks: same-school staff CREATE still works', async () => {
    await assertSucceeds(
      DBS.staffA.collection('marks').doc(`${SCHOOL_A}_M1`)
        .set({ schoolId: SCHOOL_A, studentId: 'STU0001', total: 90, status: 'draft' })
    );
  });

  /** The real Teacher-app shape: setDocument(merge = true) over an existing doc. */
  test('marks: correction by MERGE-OVERWRITE still works (the real correction path)', async () => {
    await seedDoc('marks', `${SCHOOL_A}_M2`, {
      schoolId: SCHOOL_A, studentId: 'STU0001', total: 70, status: 'draft',
    });

    await assertSucceeds(
      DBS.staffA.collection('marks').doc(`${SCHOOL_A}_M2`)
        .set({ schoolId: SCHOOL_A, studentId: 'STU0001', total: 95, status: 'draft' }, { merge: true })
    );
  });

  test('marks: same-school staff UPDATE still works', async () => {
    await seedDoc('marks', `${SCHOOL_A}_M3`, { schoolId: SCHOOL_A, total: 70 });
    await assertSucceeds(DBS.staffA.collection('marks').doc(`${SCHOOL_A}_M3`).update({ total: 80 }));
  });

  test('marks: CROSS-SCHOOL create is still REFUSED', async () => {
    await assertFails(
      DBS.staffA.collection('marks').doc(`${SCHOOL_B}_M4`)
        .set({ schoolId: SCHOOL_B, studentId: 'STU9999', total: 10 })
    );
  });

  test('academicAuditLog: same-school staff CREATE still works', async () => {
    await assertSucceeds(
      DBS.staffA.collection('academicAuditLog').doc(`${SCHOOL_A}_L1`)
        .set({ schoolId: SCHOOL_A, action: 'create', entityId: 'T2' })
    );
  });

  test('academicAuditLog: CROSS-SCHOOL create is still REFUSED', async () => {
    await assertFails(
      DBS.staffA.collection('academicAuditLog').doc(`${SCHOOL_B}_L2`)
        .set({ schoolId: SCHOOL_B, action: 'create', entityId: 'T3' })
    );
  });

  /** Symmetry — School B staff must be refused School A just as firmly. */
  test('the delete prohibition is symmetric across tenants', async () => {
    await seedDoc('marks', `${SCHOOL_A}_S1`, { schoolId: SCHOOL_A, total: 50 });
    await assertFails(DBS.staffB.collection('marks').doc(`${SCHOOL_A}_S1`).delete());
  });

  // ══ READS UNCHANGED ═════════════════════════════════════════════════════

  test('academicAuditLog: same-school ADMIN read still works', async () => {
    await seedDoc('academicAuditLog', `${SCHOOL_A}_R1`, { schoolId: SCHOOL_A, action: 'x' });
    await assertSucceeds(DBS.adminA.collection('academicAuditLog').doc(`${SCHOOL_A}_R1`).get());
  });

  test('academicAuditLog: cross-school admin read is REFUSED', async () => {
    await seedDoc('academicAuditLog', `${SCHOOL_B}_R2`, { schoolId: SCHOOL_B, action: 'x' });
    await assertFails(DBS.adminA.collection('academicAuditLog').doc(`${SCHOOL_B}_R2`).get());
  });
});
