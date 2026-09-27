/**
 * SEC-7 — DOES THE PARSER'S MODEL MATCH THE FIRESTORE ENGINE?
 *
 * Every classification rule in `scripts/lib/rules_parser.php` encodes a belief
 * about how Firestore evaluates a condition. Those beliefs have been wrong
 * three times in this programme, each time producing a confident and incorrect
 * security verdict:
 *
 *   - redundant parentheses hid a top-level `||`, so `marks.get` was CLEARED;
 *   - `isAdmin()` was filed as a platform boundary, hiding ~90 pairs;
 *   - a fail-open sibling was treated as defeating a binding `&&` check.
 *
 * So the model is measured here against the real engine rather than argued
 * about. Each test states the parser's PREDICTION and asserts the MEASURED
 * behaviour. A divergence is a test failure, not a silent modelling error.
 *
 * Runs against `fixtures/semantics_probe.rules` — a laboratory fixture, never
 * an artifact, never deployed.
 */

const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const SCHOOL_A = 'SCH_TENANT_AAA';
const SCHOOL_B = 'SCH_TENANT_BBB';
const RULES_PATH = path.join(__dirname, 'fixtures', 'semantics_probe.rules');

jest.setTimeout(60000);

let env;
const DBS = {};

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });

  // Contexts built ONCE, before the first withSecurityRulesDisabled() seed.
  DBS.staffA = env.authenticatedContext('STA0001', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Teacher',
  }).firestore();
  DBS.staffB = env.authenticatedContext('STA9999', {
    school_id: SCHOOL_B, schoolId: SCHOOL_B, role: 'Teacher',
  }).firestore();
  DBS.parentA = env.authenticatedContext('STU0001', {
    school_id: SCHOOL_A, schoolId: SCHOOL_A, role: 'Parent',
  }).firestore();
});

afterAll(async () => { if (env) await env.cleanup(); });

async function seed(col, docId, data) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    await ctx.firestore().collection(col).doc(docId).set(data);
  });
}

beforeEach(async () => { await env.clearFirestore(); });

describe('SEC-7 · engine semantics vs parser model', () => {

  // ══ Q1/Q2 — null dereference in a disjunction ═══════════════════════════

  /**
   * PARSER PREDICTS: `isStaff() && (sameSchoolIncoming() || sameSchoolStored())`
   * on DELETE is NOT a cross-tenant finding. `request.resource` is null so the
   * left branch cannot succeed; either the engine denies outright, or it takes
   * the right branch which DOES compare the school. Both outcomes are safe.
   *
   * This test asserts the safety property that holds under either semantics:
   * a CROSS-SCHOOL delete must fail.
   */
  test('Q1: cross-school delete through an or-rescued arm is REFUSED', async () => {
    await seed('q1_orRescue', 'b1', { schoolId: SCHOOL_B });
    await assertFails(DBS.staffA.collection('q1_orRescue').doc('b1').delete());
  });

  /** And this records WHICH of the two semantics the engine actually uses. */
  test('Q1: same-school delete through an or-rescued arm — records engine behaviour', async () => {
    await seed('q1_orRescue', 'a1', { schoolId: SCHOOL_A });
    // If the engine denies on the erroring left operand this fails; if the
    // right branch rescues it, it succeeds. Either way Q1 above still holds.
    await assertSucceeds(DBS.staffA.collection('q1_orRescue').doc('a1').delete());
  });

  /**
   * PARSER PREDICTS: `null-deref-denies` — with no alternative branch the arm
   * is dead for delete, so even a SAME-school delete is refused. This is the
   * shape `marks` and `academicAuditLog` have (B-022).
   */
  test('Q2: same-school delete with NO rescuing branch is REFUSED (arm is dead)', async () => {
    await seed('q2_noRescue', 'a1', { schoolId: SCHOOL_A });
    await assertFails(DBS.staffA.collection('q2_noRescue').doc('a1').delete());
  });

  test('Q2: create through the same arm still WORKS (request.resource exists)', async () => {
    await assertSucceeds(
      DBS.staffA.collection('q2_noRescue').doc('a2').set({ schoolId: SCHOOL_A })
    );
  });

  // ══ Q3/Q4 — resource == null on create ══════════════════════════════════

  /**
   * PARSER PREDICTS: cross-tenant create ALLOWED. `resource` is always null on
   * create, so `resource == null ||` is unconditionally true and the school
   * comparison after it is unreachable — leaving bare `isStaff()`.
   */
  test('Q3: cross-school CREATE through a null-tolerant check is ALLOWED (fail-open)', async () => {
    await assertSucceeds(
      DBS.staffA.collection('q3_createFailOpen').doc('b1').set({ schoolId: SCHOOL_B })
    );
  });

  /**
   * PARSER PREDICTS: still tenant-bound. The fail-open branch does not defeat a
   * binding sibling reached through `&&`. This is the `notificationPreferences`
   * shape an earlier heuristic wrongly flagged.
   */
  test('Q4: cross-school CREATE is REFUSED when a binding sibling is ANDed in', async () => {
    await assertFails(
      DBS.staffA.collection('q4_createBoundSibling').doc('b1').set({ schoolId: SCHOOL_B })
    );
  });

  test('Q4: own-school create still WORKS', async () => {
    await assertSucceeds(
      DBS.staffA.collection('q4_createBoundSibling').doc('a1').set({ schoolId: SCHOOL_A })
    );
  });

  // ══ Q5 — duplicate allow arms are OR'd ══════════════════════════════════

  /**
   * PARSER PREDICTS: the WEAKEST arm decides. The block has a narrow
   * staff+school read AND a broad `isAuth()` read; if Firestore ORs them, a
   * cross-school parent can read. If the census resolved to the first or
   * strongest arm instead, every finding count would be wrong.
   */
  test('Q5: a broad duplicate arm overrides a narrow one (OR semantics)', async () => {
    await seed('q5_duplicateArms', 'b1', { schoolId: SCHOOL_B });
    await assertSucceeds(DBS.parentA.collection('q5_duplicateArms').doc('b1').get());
  });

  // ══ Q6/Q7 — missing documents ═══════════════════════════════════════════

  /**
   * PARSER PREDICTS: harmless. A `resource == null ||` read arm permits reading
   * a NON-EXISTENT document, but there is nothing to disclose, so the parser
   * must NOT report it as a cross-tenant finding.
   */
  test('Q6: reading a MISSING document through a null-tolerant arm is ALLOWED', async () => {
    await assertSucceeds(DBS.staffA.collection('q6_missingDocRead').doc('nope').get());
  });

  /** ...but an EXISTING cross-school document is still refused. */
  test('Q6: an EXISTING cross-school document is still REFUSED', async () => {
    await seed('q6_missingDocRead', 'b1', { schoolId: SCHOOL_B });
    await assertFails(DBS.staffA.collection('q6_missingDocRead').doc('b1').get());
  });

  /** PARSER PREDICTS: `resource != null` makes a missing document fail CLOSED. */
  test('Q7: a strict arm makes a MISSING document fail closed', async () => {
    await assertFails(DBS.staffA.collection('q7_strictRead').doc('nope').get());
  });

  test('Q7: a strict arm still allows an existing same-school document', async () => {
    await seed('q7_strictRead', 'a1', { schoolId: SCHOOL_A });
    await assertSucceeds(DBS.staffA.collection('q7_strictRead').doc('a1').get());
  });

  // ══ Q8/Q9 — get and list are independent ════════════════════════════════

  /**
   * PARSER PREDICTS: `get` and `list` must be judged SEPARATELY. A write-only
   * or read-collapsed census cannot see a list-only leak.
   */
  test('Q8: an unscoped LIST leaks the collection even though GET is scoped', async () => {
    await seed('q8_listWiderThanGet', 'b1', { schoolId: SCHOOL_B });

    await assertFails(DBS.parentA.collection('q8_listWiderThanGet').doc('b1').get());
    await assertSucceeds(DBS.parentA.collection('q8_listWiderThanGet').get());
  });

  test('Q9: the reverse — GET open, LIST scoped — confirms independence', async () => {
    await seed('q9_getWiderThanList', 'b1', { schoolId: SCHOOL_B });

    await assertSucceeds(DBS.parentA.collection('q9_getWiderThanList').doc('b1').get());
    await assertFails(DBS.parentA.collection('q9_getWiderThanList').get());
  });

  // ══ Q10 — a query must be PROVABLY within the rule ══════════════════════

  /**
   * PARSER PREDICTS (and the SEC-6 suite relies on this): a scoped read arm
   * rejects an unfiltered query outright — Firestore does not evaluate the rule
   * per returned document and filter. The client must carry the filter.
   */
  test('Q10: an unfiltered query against a scoped arm is REFUSED', async () => {
    await seed('q10_queryMustBeProvable', 'a1', { schoolId: SCHOOL_A });
    await assertFails(DBS.staffA.collection('q10_queryMustBeProvable').get());
  });

  test('Q10: the SAME query carrying the school filter is ALLOWED', async () => {
    await seed('q10_queryMustBeProvable', 'a1', { schoolId: SCHOOL_A });
    await assertSucceeds(
      DBS.staffA.collection('q10_queryMustBeProvable').where('schoolId', '==', SCHOOL_A).get()
    );
  });

  /** And a filter naming ANOTHER school must not succeed. */
  test('Q10: a query filtered to another school is REFUSED', async () => {
    await seed('q10_queryMustBeProvable', 'b1', { schoolId: SCHOOL_B });
    await assertFails(
      DBS.staffA.collection('q10_queryMustBeProvable').where('schoolId', '==', SCHOOL_B).get()
    );
  });

  // ══ Q11/Q12 — the SEC-4 short-circuit and its fix ═══════════════════════

  test('Q11: a role disjunct with no school check grants ACROSS tenants', async () => {
    await seed('q11_roleShortCircuit', 'b1', { schoolId: SCHOOL_B, ownerId: 'someone' });
    await assertSucceeds(DBS.staffA.collection('q11_roleShortCircuit').doc('b1').get());
  });

  test('Q12: the same predicates composed with && refuse the cross-tenant read', async () => {
    await seed('q12_roleComposed', 'b1', { schoolId: SCHOOL_B, ownerId: 'someone' });
    await assertFails(DBS.staffA.collection('q12_roleComposed').doc('b1').get());
  });

  test('Q12: and still allow the same-school staff read', async () => {
    await seed('q12_roleComposed', 'a1', { schoolId: SCHOOL_A, ownerId: 'someone' });
    await assertSucceeds(DBS.staffA.collection('q12_roleComposed').doc('a1').get());
  });

  // ══ Q13 — the GLOBAL-ID DEPENDENCY, made explicit ═══════════════════════

  /**
   * The parser treats an owner-scoped branch (`X == request.auth.uid`) as a
   * valid boundary needing no school comparison. That is sound ONLY because
   * ZenX mints staff and student ids from a GLOBAL counter, so a uid cannot
   * name a different person in another tenant.
   *
   * These two tests make the dependency executable rather than a footnote.
   */
  test('Q13: owner-scoping is tenant-safe while ids are globally unique', async () => {
    // School B's document is owned by a DIFFERENT id — the real-world case.
    await seed('q13_ownerNoTenant', 'b1', { schoolId: SCHOOL_B, ownerId: 'STA9999' });
    await assertFails(DBS.staffA.collection('q13_ownerNoTenant').doc('b1').get());
  });

  /**
   * THE FAILURE MODE IF IDS EVER BECOME PER-SCHOOL.
   *
   * Here School B's document is owned by 'STA0001' — the SAME id as School A's
   * staff member. Under a global counter this document cannot exist. Under
   * per-school ids it can, and the owner-scoped arm then grants a cross-tenant
   * read with no school comparison to stop it.
   *
   * This test asserts that outcome deliberately: it documents the blast radius
   * of changing the id contract, and will keep passing (proving the hazard is
   * real) until someone adds a school comparison to owner-scoped arms.
   */
  test('Q13: an id COLLISION across tenants would grant a cross-tenant read', async () => {
    await seed('q13_ownerNoTenant', 'b_collide', { schoolId: SCHOOL_B, ownerId: 'STA0001' });
    await assertSucceeds(DBS.staffA.collection('q13_ownerNoTenant').doc('b_collide').get());
  });
});
