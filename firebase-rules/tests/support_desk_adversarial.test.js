/**
 * Support Desk — ADVERSARIAL rules tests.
 *
 * support_desk.test.js proves the module works and blocks the obvious attacks.
 * This file exists to attack the gaps that suite leaves, written from the
 * position that the parent app is hostile — because to the rules layer it IS.
 * The panel goes through the Admin SDK and bypasses rules entirely, so for
 * every parent-facing write these rules are not "a" boundary, they are the
 * ONLY boundary.
 *
 * Gaps targeted, none of which the first suite covers:
 *
 *   1. supportCounters WRITE. The cap that stops a parent flooding the queue is
 *      read from this doc BY THE RULES (underCap → openCount < 5). If a client
 *      could write it, the cap is decorative. Read-denial was tested; write was
 *      not.
 *   2. supportReporterIdentity WRITE — the confidential lane's identity split.
 *   3. Cross-tenant CREATE. Cross-tenant READ was tested; writing INTO another
 *      school was not.
 *   4. reporterId spoofing on a ticket (distinct from studentId/ownsStudent).
 *   5. lastMessageAt forgery (only createdAt was covered).
 *   6. Message-level createdAt forgery and reporterId spoofing.
 *   7. Message DELETE (only update was covered).
 *   8. Unauthenticated access to every collection.
 *   9. Type confusion — attachments as a non-array, oversized payloads.
 *
 * Run: cd firebase-rules/tests && npm test
 */
const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const firebase = require('firebase/compat/app');
require('firebase/compat/firestore');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-adversarial';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');

const SCHOOL = 'SCH_ADV_001';
const OTHER_SCHOOL = 'SCH_ADV_002';
const PARENT_A = 'STU_ADV_A';   // uid === studentId, per this install
const PARENT_B = 'STU_ADV_B';
const TICKET_A = 'TKT_ADVAAAAAAAAAAAA';
const TICKET_B = 'TKT_ADVBBBBBBBBBBBB';

const NOW = () => firebase.firestore.FieldValue.serverTimestamp();
const ticketPath = (school, tid) => `supportTickets/${school}_${tid}`;

function newTicket(overrides) {
  return Object.assign({
    schoolId: SCHOOL,
    ticketId: TICKET_A,
    sessionId: '2026-27',
    lane: 'normal',
    category: 'fees',
    subject: 'Adversarial baseline',
    studentId: PARENT_A,
    studentName: 'Test Child',
    className: 'VII-B',
    reporterId: PARENT_A,
    reporterName: 'Test Parent',
    isAnonymous: false,
    status: 'open',
    attachments: [],
    createdAt: NOW(),
    lastMessageAt: NOW(),
    lastParentReplyAt: NOW(),
  }, overrides || {});
}

function newMessage(overrides) {
  return Object.assign({
    schoolId: SCHOOL,
    ticketId: TICKET_A,
    reporterId: PARENT_A,
    senderType: 'parent',
    senderId: PARENT_A,
    senderName: 'Test Parent',
    body: 'Adversarial baseline',
    attachments: [],
    createdAt: NOW(),
  }, overrides || {});
}

let env;
const parent = (uid, school) =>
  env.authenticatedContext(uid, { school_id: school || SCHOOL, role: 'parent' }).firestore();
const anon = () => env.unauthenticatedContext().firestore();

async function seed({ openCount = null } = {}) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL, OTHER_SCHOOL]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: 'Adversarial Test School' });
    }
    await db.doc(ticketPath(SCHOOL, TICKET_A)).set({
      schoolId: SCHOOL, ticketId: TICKET_A, lane: 'normal', status: 'open',
      reporterId: PARENT_A, studentId: PARENT_A, category: 'fees',
      subject: 'Existing', messageCount: 1,
    });
    await db.doc(ticketPath(SCHOOL, TICKET_B)).set({
      schoolId: SCHOOL, ticketId: TICKET_B, lane: 'normal', status: 'open',
      reporterId: PARENT_B, studentId: PARENT_B, category: 'transport',
      subject: 'Another family', messageCount: 1,
    });
    await db.doc(`supportCounters/${SCHOOL}_${PARENT_A}`)
      .set({ schoolId: SCHOOL, reporterId: PARENT_A, openCount: openCount === null ? 0 : openCount });
    await db.doc(`supportNotes/n1`).set({ schoolId: SCHOOL, ticketId: TICKET_A, body: 'internal' });
    await db.doc(`supportReporterIdentity/${SCHOOL}_${TICKET_A}`)
      .set({ schoolId: SCHOOL, reporterId: PARENT_A });
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); });

// ─────────────────────────────────────────────────────────────────────────────
describe('ADVERSARIAL — the open-ticket cap cannot be edited by the capped party', () => {
  test('parent writes their OWN counter to zero → denied (else the cap is decorative)', async () => {
    await seed({ openCount: 5 });
    const db = parent(PARENT_A);
    await assertFails(
      db.doc(`supportCounters/${SCHOOL}_${PARENT_A}`).set({ openCount: 0 })
    );
  });

  test('parent merges openCount downward → denied', async () => {
    await seed({ openCount: 5 });
    const db = parent(PARENT_A);
    await assertFails(
      db.doc(`supportCounters/${SCHOOL}_${PARENT_A}`).set({ openCount: 0 }, { merge: true })
    );
  });

  test('parent deletes their counter to escape the cap → denied', async () => {
    await seed({ openCount: 5 });
    const db = parent(PARENT_A);
    await assertFails(db.doc(`supportCounters/${SCHOOL}_${PARENT_A}`).delete());
  });

  test('with the counter untouched at the cap, a create is still refused', async () => {
    await seed({ openCount: 5 });
    const db = parent(PARENT_A);
    await assertFails(
      db.doc(ticketPath(SCHOOL, 'TKT_ADVCAPCAPCAPCAP')).set(newTicket({ ticketId: 'TKT_ADVCAPCAPCAPCAP' }))
    );
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('ADVERSARIAL — identity split is not writable by the reporter', () => {
  test('parent writes supportReporterIdentity → denied', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(
      db.doc(`supportReporterIdentity/${SCHOOL}_${TICKET_A}`).set({ reporterId: 'someone-else' })
    );
  });

  test('parent deletes supportReporterIdentity → denied', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(db.doc(`supportReporterIdentity/${SCHOOL}_${TICKET_A}`).delete());
  });

  test('parent writes a staff-internal note → denied', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(db.doc('supportNotes/forged').set({ schoolId: SCHOOL, body: 'planted' }));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('ADVERSARIAL — tenant isolation on WRITE, not just read', () => {
  test('parent creates a ticket INTO another school → denied', async () => {
    await seed();
    const db = parent(PARENT_A);                     // token school_id = SCHOOL
    await assertFails(
      db.doc(ticketPath(OTHER_SCHOOL, 'TKT_ADVXSXSXSXSXSXS'))
        .set(newTicket({ schoolId: OTHER_SCHOOL, ticketId: 'TKT_ADVXSXSXSXSXSXS' }))
    );
  });

  test('token for another school cannot create into THIS school', async () => {
    await seed();
    const db = parent(PARENT_A, OTHER_SCHOOL);       // token school_id = OTHER_SCHOOL
    await assertFails(
      db.doc(ticketPath(SCHOOL, 'TKT_ADVYSYSYSYSYSYS'))
        .set(newTicket({ ticketId: 'TKT_ADVYSYSYSYSYSYS' }))
    );
  });

  test('parent posts a message into another school → denied', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(
      db.collection('supportMessages').add(newMessage({ schoolId: OTHER_SCHOOL }))
    );
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('ADVERSARIAL — field forgery the first suite did not cover', () => {
  test('reporterId spoofed to another parent → denied', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(
      db.doc(ticketPath(SCHOOL, 'TKT_ADVRPRPRPRPRPRP'))
        .set(newTicket({ ticketId: 'TKT_ADVRPRPRPRPRPRP', reporterId: PARENT_B }))
    );
  });

  test('lastMessageAt back-dated to a client clock → denied', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(
      db.doc(ticketPath(SCHOOL, 'TKT_ADVLMLMLMLMLMLM'))
        .set(newTicket({ ticketId: 'TKT_ADVLMLMLMLMLMLM', lastMessageAt: new Date(2000, 0, 1) }))
    );
  });

  test('attachments as a string rather than an array → denied', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(
      db.doc(ticketPath(SCHOOL, 'TKT_ADVATATATATATAT'))
        .set(newTicket({ ticketId: 'TKT_ADVATATATATATAT', attachments: 'not-an-array' }))
    );
  });

  test('message createdAt forged → denied', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(
      db.collection('supportMessages').add(newMessage({ createdAt: new Date(2000, 0, 1) }))
    );
  });

  test('message reporterId spoofed (distinct from senderId) → denied', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(
      db.collection('supportMessages').add(newMessage({ reporterId: PARENT_B }))
    );
  });

  test('message DELETE → denied (immutability covers removal, not just edits)', async () => {
    await seed();
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc('supportMessages/m_adv').set({
        schoolId: SCHOOL, ticketId: TICKET_A, reporterId: PARENT_A,
        senderType: 'parent', senderId: PARENT_A, body: 'mine', createdAt: new Date(),
      });
    });
    const db = parent(PARENT_A);
    await assertFails(db.doc('supportMessages/m_adv').delete());
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('ADVERSARIAL — no anonymous surface anywhere', () => {
  test('unauthenticated read of a ticket → denied', async () => {
    await seed();
    await assertFails(anon().doc(ticketPath(SCHOOL, TICKET_A)).get());
  });

  test('unauthenticated create → denied', async () => {
    await seed();
    await assertFails(
      anon().doc(ticketPath(SCHOOL, 'TKT_ADVANANANANANAN'))
        .set(newTicket({ ticketId: 'TKT_ADVANANANANANAN' }))
    );
  });

  test('unauthenticated message write → denied', async () => {
    await seed();
    await assertFails(anon().collection('supportMessages').add(newMessage()));
  });

  test('unauthenticated counter read → denied', async () => {
    await seed();
    await assertFails(anon().doc(`supportCounters/${SCHOOL}_${PARENT_A}`).get());
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('ADVERSARIAL — a listing cannot be widened past the reader', () => {
  test('parent lists ALL tickets in the school → denied', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(
      db.collection('supportTickets').where('schoolId', '==', SCHOOL).get()
    );
  });

  test('parent lists messages filtered only by ticket → denied (the SD-F9 shape)', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertFails(
      db.collection('supportMessages')
        .where('schoolId', '==', SCHOOL)
        .where('ticketId', '==', TICKET_A)
        .get()
    );
  });

  test('...but the same listing constrained by reporterId is allowed', async () => {
    await seed();
    const db = parent(PARENT_A);
    await assertSucceeds(
      db.collection('supportMessages')
        .where('schoolId', '==', SCHOOL)
        .where('ticketId', '==', TICKET_A)
        .where('reporterId', '==', PARENT_A)
        .get()
    );
  });
});
