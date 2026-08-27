/**
 * Document Engine (Certificate Designer) — Firestore Rules unit tests.
 *
 * COLLECTION_SHAPES.md §9 / EXECUTION_PLAN_v1.1 P1.3.
 *
 * NOTE FOR ANYONE READING A GREEN RUN: today NO CLIENT WRITES ANY OF THESE
 * COLLECTIONS. The panel writes through the Admin SDK, which bypasses rules
 * entirely, and neither app reads documents yet. So these rules are not
 * currently load-bearing — they are the boundary that must already be correct
 * when the apps arrive, and an explicit intent in place of the catch-all deny.
 * That is precisely why they need tests now: nothing in production would notice
 * if they were wrong.
 *
 * The three things worth proving, in order:
 *
 *  1. A PUBLISHED head is nearly frozen. documentTemplateVersions holds the
 *     snapshot a certificate was actually rendered from; if the head could be
 *     edited freely after publish, the snapshot stops reconciling with it.
 *
 *  2. Publishing and activation are 'manage', not 'edit'. activeVersion is the
 *     pointer every print point resolves — moving it changes what the school
 *     legally issues.
 *
 *  3. A frozen version is frozen for EVERYONE, forever. No update, no delete,
 *     at any capability grade.
 *
 * Run: cd firebase-rules/tests && npm install && npm test
 */
const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');

const SCHOOL = 'SCH_TEST_DOCENG_001';
const OTHER = 'SCH_TEST_DOCENG_002';
const SUSPENDED = 'SCH_TEST_DOCENG_SUSP';

const STA_EDIT = 'STA_DE_EDIT';     // Certificates: edit
const STA_MANAGE = 'STA_DE_MANAGE'; // Certificates: manage
const STA_NONE = 'STA_DE_NONE';     // caps doc exists, Certificates absent
const STA_NOCAPS = 'STA_DE_NOCAPS'; // NO caps doc at all — must fail open

const TPL_DRAFT = `${SCHOOL}_TPL0001`;
const TPL_PUB = `${SCHOOL}_TPL0002`;
const BLK = `${SCHOOL}_BLK0001`;

let env;

const draftHead = (over = {}) => ({
  schoolId: SCHOOL, templateId: 'TPL0001', docType: 'transfer_certificate',
  name: 'TC — main letterhead', status: 'draft',
  version: 3, publishedVersion: null, activeVersion: null, lockVersion: 17,
  languages: ['en'], defaultLanguage: 'en',
  ...over,
});

async function seed() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();

    // isSameSchool()/isStaff() chain to tenantActive(), which get()s
    // schoolControl. Without these docs every call fails as an evaluation
    // error rather than a clean deny.
    for (const s of [SCHOOL, OTHER]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `School ${s}` });
    }
    await db.doc(`schoolControl/${SUSPENDED}`).set({
      schoolId: SUSPENDED,
      lifecycle: { state: 'suspended', computedAt: Date.now(), reason: 'non-payment' },
    });
    await db.doc(`schools/${SUSPENDED}`).set({ schoolId: SUSPENDED, name: 'Lapsed School' });

    // staffCapabilities/{uid} — the server-maintained module set. Absence
    // FAILS OPEN by design, so STA_NOCAPS deliberately gets no doc.
    await db.doc(`staffCapabilities/${STA_EDIT}`).set({
      schoolId: SCHOOL, modules: ['Certificates'], levels: { Certificates: 'edit' },
    });
    await db.doc(`staffCapabilities/${STA_MANAGE}`).set({
      schoolId: SCHOOL, modules: ['Certificates'], levels: { Certificates: 'manage' },
    });
    await db.doc(`staffCapabilities/${STA_NONE}`).set({
      schoolId: SCHOOL, modules: ['Attendance'], levels: { Attendance: 'manage' },
    });

    // Platform collections — no schoolId field, by design.
    await db.doc('documentTypes/transfer_certificate').set({
      name: 'Transfer Certificate', statutory: true,
    });
    await db.doc('mergeFieldContracts/transfer_certificate_v3').set({
      docType: 'transfer_certificate', version: 3, keys: ['student.fullName'],
    });
    await db.doc('complianceAuthorities/cbse').set({
      label: 'CBSE', tier: 'board', scope: { board: 'CBSE' }, version: 4,
    });

    await db.doc(`documentTemplates/${TPL_DRAFT}`).set(draftHead());
    await db.doc(`documentTemplates/${TPL_PUB}`).set(draftHead({
      templateId: 'TPL0002', name: 'TC — published', status: 'published',
      version: 2, publishedVersion: 2, activeVersion: 2,
    }));
    await db.doc(`documentTemplates/${OTHER}_TPL0009`).set({
      ...draftHead(), schoolId: OTHER, templateId: 'TPL0009',
    });

    await db.doc(`documentTemplateVersions/${SCHOOL}_TPL0002_v2`).set({
      schoolId: SCHOOL, templateId: 'TPL0002', docType: 'transfer_certificate',
      version: 2, proofPdfHash: 'sha256:abc', publishedBy: STA_MANAGE,
    });

    await db.doc(`reusableBlocks/${BLK}`).set({
      schoolId: SCHOOL, blockId: 'BLK0001', blockType: 'letterhead', name: 'Main letterhead',
    });
  });
}

const staff = (uid, schoolId = SCHOOL, role = 'Teacher') =>
  env.authenticatedContext(uid, { school_id: schoolId, role }).firestore();
const anon = () => env.unauthenticatedContext().firestore();

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seed(); });

/* ══════════════════════════════════════════════════════════════════ */
describe('platform collections — readable by any active tenant, writable by no client', () => {
  const PLATFORM = [
    ['documentTypes', 'documentTypes/transfer_certificate'],
    ['mergeFieldContracts', 'mergeFieldContracts/transfer_certificate_v3'],
    ['complianceAuthorities', 'complianceAuthorities/cbse'],
  ];

  test.each(PLATFORM)('%s — an authenticated school user can read', async (_n, p) => {
    await assertSucceeds(staff(STA_EDIT).doc(p).get());
  });

  test.each(PLATFORM)('%s — unauthenticated cannot read', async (_n, p) => {
    await assertFails(anon().doc(p).get());
  });

  test.each(PLATFORM)('%s — a lapsed tenant cannot read', async (_n, p) => {
    await assertFails(staff(STA_EDIT, SUSPENDED).doc(p).get());
  });

  // The deliberate deviation from §9 ("platform super-admin only"): no client
  // write arm at all. Super-admin edits arrive via the Admin SDK, which
  // bypasses rules, so a client arm would grant a path nothing uses — on the
  // documents that decide which statutory fields exist and which rules apply.
  test.each(PLATFORM)('%s — even a Super Admin token cannot write from a client', async (_n, p) => {
    await assertFails(
      env.authenticatedContext('SA_1', { school_id: SCHOOL, role: 'Super Admin' })
        .firestore().doc(p).set({ tampered: true }, { merge: true })
    );
  });
});

/* ══════════════════════════════════════════════════════════════════ */
describe('documentTemplates — tenant scoping', () => {
  test('same-school staff can read a head', async () => {
    await assertSucceeds(staff(STA_EDIT).doc(`documentTemplates/${TPL_DRAFT}`).get());
  });

  test('another tenant cannot read this school’s head', async () => {
    await assertFails(staff(STA_EDIT, OTHER).doc(`documentTemplates/${TPL_DRAFT}`).get());
  });

  test('a create carrying someone else’s schoolId is denied', async () => {
    await assertFails(
      staff(STA_EDIT).doc(`documentTemplates/${SCHOOL}_TPL0100`)
        .set(draftHead({ schoolId: OTHER, templateId: 'TPL0100' }))
    );
  });
});

describe('documentTemplates — creation', () => {
  test('a draft head with Certificates:edit is allowed', async () => {
    await assertSucceeds(
      staff(STA_EDIT).doc(`documentTemplates/${SCHOOL}_TPL0101`)
        .set(draftHead({ templateId: 'TPL0101' }))
    );
  });

  test('creating a head ALREADY published is denied — publish is a transition, not an initial state', async () => {
    await assertFails(
      staff(STA_EDIT).doc(`documentTemplates/${SCHOOL}_TPL0102`)
        .set(draftHead({ templateId: 'TPL0102', status: 'published', publishedVersion: 1 }))
    );
  });

  test('a staff member whose caps omit Certificates is denied', async () => {
    await assertFails(
      staff(STA_NONE).doc(`documentTemplates/${SCHOOL}_TPL0103`)
        .set(draftHead({ templateId: 'TPL0103' }))
    );
  });

  // Rollout safety: adding a capability gate must never lock out an account
  // that predates the capabilities Cloud Function.
  test('a staff member with NO caps doc is allowed — the gate fails open', async () => {
    await assertSucceeds(
      staff(STA_NOCAPS).doc(`documentTemplates/${SCHOOL}_TPL0104`)
        .set(draftHead({ templateId: 'TPL0104' }))
    );
  });
});

describe('documentTemplates — a DRAFT head is freely editable by an editor', () => {
  test('renaming a draft is allowed', async () => {
    await assertSucceeds(
      staff(STA_EDIT).doc(`documentTemplates/${TPL_DRAFT}`).update({ name: 'TC — renamed' })
    );
  });

  test('templateId is immutable', async () => {
    await assertFails(
      staff(STA_EDIT).doc(`documentTemplates/${TPL_DRAFT}`).update({ templateId: 'TPL9999' })
    );
  });

  test('docType is immutable — a TC cannot become a Bonafide', async () => {
    await assertFails(
      staff(STA_EDIT).doc(`documentTemplates/${TPL_DRAFT}`).update({ docType: 'bonafide' })
    );
  });
});

describe('documentTemplates — a PUBLISHED head is nearly frozen', () => {
  test('renaming a published head is denied', async () => {
    await assertFails(
      staff(STA_MANAGE).doc(`documentTemplates/${TPL_PUB}`).update({ name: 'sneaky rename' })
    );
  });

  test('editing page/design fields on a published head is denied', async () => {
    await assertFails(
      staff(STA_MANAGE).doc(`documentTemplates/${TPL_PUB}`).update({ languages: ['en', 'hi'] })
    );
  });

  test('status → archived is allowed', async () => {
    await assertSucceeds(
      staff(STA_MANAGE).doc(`documentTemplates/${TPL_PUB}`)
        .update({ status: 'archived', lockVersion: 18 })
    );
  });

  test('status → draft is denied — a published head cannot be un-published', async () => {
    await assertFails(
      staff(STA_MANAGE).doc(`documentTemplates/${TPL_PUB}`).update({ status: 'draft' })
    );
  });
});

describe('documentTemplates — activation is manage, not edit', () => {
  // TPL_PUB is seeded with activeVersion: 2, so these MUST write a different
  // value. The first draft of both tests wrote 2 — a no-op that the rule
  // correctly allows, which made the editor case fail and, worse, made the
  // manager case pass without ever exercising the gate. Setting a field to the
  // value it already holds proves nothing about who may change it.
  test('an editor may NOT move activeVersion', async () => {
    await assertFails(
      staff(STA_EDIT).doc(`documentTemplates/${TPL_PUB}`)
        .update({ activeVersion: 3, lockVersion: 18 })
    );
  });

  test('a manager may move activeVersion', async () => {
    await assertSucceeds(
      staff(STA_MANAGE).doc(`documentTemplates/${TPL_PUB}`)
        .update({ activeVersion: 3, lockVersion: 18 })
    );
  });

  test('a no-op write of the SAME activeVersion is allowed for an editor', async () => {
    await assertSucceeds(
      staff(STA_EDIT).doc(`documentTemplates/${TPL_PUB}`)
        .update({ activeVersion: 2, lockVersion: 18 })
    );
  });

  test('an editor may still touch a draft that leaves activeVersion alone', async () => {
    await assertSucceeds(
      staff(STA_EDIT).doc(`documentTemplates/${TPL_DRAFT}`).update({ lockVersion: 18 })
    );
  });
});

describe('documentTemplates — never deletable', () => {
  test.each([[STA_EDIT], [STA_MANAGE]])(
    'delete is denied even for %s — retirement is status → archived',
    async (uid) => {
      await assertFails(staff(uid).doc(`documentTemplates/${TPL_DRAFT}`).delete());
    });
});

/* ══════════════════════════════════════════════════════════════════ */
describe('documentTemplateVersions — create-only, frozen forever', () => {
  test('same-school staff can read a frozen version', async () => {
    await assertSucceeds(
      staff(STA_EDIT).doc(`documentTemplateVersions/${SCHOOL}_TPL0002_v2`).get()
    );
  });

  test('another tenant cannot read it', async () => {
    await assertFails(
      staff(STA_EDIT, OTHER).doc(`documentTemplateVersions/${SCHOOL}_TPL0002_v2`).get()
    );
  });

  test('creating a snapshot needs manage — writing one IS publishing', async () => {
    await assertSucceeds(
      staff(STA_MANAGE).doc(`documentTemplateVersions/${SCHOOL}_TPL0001_v3`).set({
        schoolId: SCHOOL, templateId: 'TPL0001', docType: 'transfer_certificate',
        version: 3, proofPdfHash: 'sha256:def', publishedBy: STA_MANAGE,
      })
    );
  });

  test('an editor cannot publish', async () => {
    await assertFails(
      staff(STA_EDIT).doc(`documentTemplateVersions/${SCHOOL}_TPL0001_v3`).set({
        schoolId: SCHOOL, templateId: 'TPL0001', docType: 'transfer_certificate',
        version: 3, proofPdfHash: 'sha256:def', publishedBy: STA_EDIT,
      })
    );
  });

  // The whole point of the collection: it answers "show me the exact template
  // that produced this certificate" years later. If it can be edited, it
  // answers nothing.
  test.each([[STA_EDIT], [STA_MANAGE]])(
    'update is denied for %s — no grade unlocks it', async (uid) => {
      await assertFails(
        staff(uid).doc(`documentTemplateVersions/${SCHOOL}_TPL0002_v2`)
          .update({ proofPdfHash: 'sha256:tampered' })
      );
    });

  test.each([[STA_EDIT], [STA_MANAGE]])(
    'delete is denied for %s — no grade unlocks it', async (uid) => {
      await assertFails(
        staff(uid).doc(`documentTemplateVersions/${SCHOOL}_TPL0002_v2`).delete()
      );
    });
});

/* ══════════════════════════════════════════════════════════════════ */
describe('reusableBlocks — edit to change, manage to remove', () => {
  test('same-school staff can read', async () => {
    await assertSucceeds(staff(STA_EDIT).doc(`reusableBlocks/${BLK}`).get());
  });

  test('another tenant cannot read', async () => {
    await assertFails(staff(STA_EDIT, OTHER).doc(`reusableBlocks/${BLK}`).get());
  });

  test('an editor may update a block', async () => {
    await assertSucceeds(
      staff(STA_EDIT).doc(`reusableBlocks/${BLK}`).update({ name: 'Main letterhead v2' })
    );
  });

  // A block imposes its bound keys on every contract that uses it, so removing
  // one can invalidate templates that reference it.
  test('an editor may NOT delete a block', async () => {
    await assertFails(staff(STA_EDIT).doc(`reusableBlocks/${BLK}`).delete());
  });

  test('a manager may delete a block', async () => {
    await assertSucceeds(staff(STA_MANAGE).doc(`reusableBlocks/${BLK}`).delete());
  });
});
