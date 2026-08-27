/**
 * Support Desk — Firestore Rules unit tests.
 *
 * Executes the security half of the S-matrix from the build book (S013, S014,
 * S027–S032) against the emulator, so the rules can be proven without
 * deploying anything to production.
 *
 * The identity model matters here and is easy to get wrong: THIS INSTALL LOGS
 * A PARENT IN AS THE STUDENT. The auth uid IS the student id, and no
 * student_ids / student_id claim is minted. ownsStudent() has three arms and
 * the third — `sid == request.auth.uid` — is the one that actually fires.
 * Omitting it denies every create, and it looks like a mysterious permission
 * error rather than a rules bug. These tests cover all three arms so a future
 * edit cannot quietly drop the one this deployment depends on.
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

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');

const SCHOOL = 'SCH_TEST_SUP_001';
const OTHER_SCHOOL = 'SCH_TEST_SUP_002';

// uid === studentId, per this install's identity model.
const PARENT_A = 'STU_SUP_A';
const PARENT_B = 'STU_SUP_B';

const TICKET_A = 'TKT_AAAAAAAAAAAAAAAA';
const TICKET_B = 'TKT_BBBBBBBBBBBBBBBB';

const NOW = () => firebase.firestore.FieldValue.serverTimestamp();

let env;

const ticketPath = (school, tid) => `supportTickets/${school}_${tid}`;

/** A create payload that satisfies every arm of the create rule. */
function newTicket(overrides) {
  return Object.assign({
    schoolId: SCHOOL,
    ticketId: TICKET_A,
    sessionId: '2026-27',
    lane: 'normal',
    category: 'fees',
    subject: 'Receipt shows the wrong amount',
    studentId: PARENT_A,          // === uid
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
    body: 'Any update on this please?',
    attachments: [],
    createdAt: NOW(),
  }, overrides || {});
}

/** Parent context. No student_ids claim — matching how this install signs in. */
function parent(uid, school) {
  return env.authenticatedContext(uid, { school_id: school || SCHOOL, role: 'parent' }).firestore();
}
/** A parent whose claims DO carry student_ids, to cover ownsStudent arm 1. */
function parentWithClaim(uid, studentIds) {
  return env.authenticatedContext(uid, {
    school_id: SCHOOL, role: 'parent', student_ids: studentIds,
  }).firestore();
}

async function seed({ tenantState = 'active', openCount = null, withTicketB = false } = {}) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL, OTHER_SCHOOL]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s,
        lifecycle: { state: tenantState, computedAt: Date.now(), reason: tenantState },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: 'Support Test School' });
    }
    // An existing ticket owned by PARENT_A.
    await db.doc(ticketPath(SCHOOL, TICKET_A)).set({
      schoolId: SCHOOL, ticketId: TICKET_A, lane: 'normal', status: 'open',
      reporterId: PARENT_A, studentId: PARENT_A, category: 'fees',
      subject: 'Existing', messageCount: 1,
    });
    if (withTicketB) {
      // A ticket owned by a DIFFERENT parent, same school.
      await db.doc(ticketPath(SCHOOL, TICKET_B)).set({
        schoolId: SCHOOL, ticketId: TICKET_B, lane: 'normal', status: 'open',
        reporterId: PARENT_B, studentId: PARENT_B, category: 'transport',
        subject: 'Someone else', messageCount: 1,
      });
    }
    if (openCount !== null) {
      await db.doc(`supportCounters/${SCHOOL}_${PARENT_A}`)
        .set({ schoolId: SCHOOL, reporterId: PARENT_A, openCount });
    }
    // Server-only collections, seeded so a read attempt has something to hit.
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
describe('supportTickets — create', () => {
  test('parent raises a ticket for their own child → allowed (ownsStudent arm 3: uid)', async () => {
    await seed();
    await assertSucceeds(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEW1')).set(newTicket({ ticketId: 'TKT_NEW1' }))
    );
  });

  test('ownsStudent arm 1 — student_ids claim also works', async () => {
    await seed();
    await assertSucceeds(
      parentWithClaim('parent-uid-x', [PARENT_A])
        .doc(ticketPath(SCHOOL, 'TKT_NEW2'))
        .set(newTicket({ ticketId: 'TKT_NEW2', reporterId: 'parent-uid-x' }))
    );
  });

  // S014
  test('create for ANOTHER family\'s child → denied', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEW3'))
        .set(newTicket({ ticketId: 'TKT_NEW3', studentId: PARENT_B }))
    );
  });

  // S030 — C-04: the rule must not throw on a missing field
  test('create with NO attachments field at all → allowed', async () => {
    await seed();
    const t = newTicket({ ticketId: 'TKT_NEW4' });
    delete t.attachments;
    await assertSucceeds(parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEW4')).set(t));
  });

  test('create with 4 attachments → denied', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEW5'))
        .set(newTicket({ ticketId: 'TKT_NEW5', attachments: ['1.jpg','2.jpg','3.jpg','4.jpg'] }))
    );
  });

  test('create carrying assignedTo → denied (server owns assignment)', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEW6'))
        .set(newTicket({ ticketId: 'TKT_NEW6', assignedTo: 'STA0067' }))
    );
  });

  test('create carrying ticketNo → denied (a Cloud Function assigns it)', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEW7'))
        .set(newTicket({ ticketId: 'TKT_NEW7', ticketNo: 999 }))
    );
  });

  test('create with status other than open → denied', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEW8'))
        .set(newTicket({ ticketId: 'TKT_NEW8', status: 'resolved' }))
    );
  });

  test('create on a confidential lane → denied (not reachable in v1)', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEW9'))
        .set(newTicket({ ticketId: 'TKT_NEW9', lane: 'posh' }))
    );
  });

  // S028 — a client-chosen timestamp is what let a parent pin the queue
  test('create with a client-chosen createdAt → denied', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEWA'))
        .set(newTicket({ ticketId: 'TKT_NEWA', createdAt: new Date(4102444800000) }))
    );
  });

  // S032 — the cap is a real gate, evaluated BEFORE the write lands
  test('sixth open ticket → denied at the rules layer', async () => {
    await seed({ openCount: 5 });
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEWB')).set(newTicket({ ticketId: 'TKT_NEWB' }))
    );
  });

  test('under the cap → allowed', async () => {
    await seed({ openCount: 4 });
    await assertSucceeds(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEWC')).set(newTicket({ ticketId: 'TKT_NEWC' }))
    );
  });

  test('suspended tenant → denied', async () => {
    await seed({ tenantState: 'suspended' });
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, 'TKT_NEWD')).set(newTicket({ ticketId: 'TKT_NEWD' }))
    );
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('supportTickets — read and update', () => {
  test('parent reads their OWN ticket → allowed', async () => {
    await seed();
    await assertSucceeds(parent(PARENT_A).doc(ticketPath(SCHOOL, TICKET_A)).get());
  });

  test('parent reads ANOTHER parent\'s ticket → denied', async () => {
    await seed({ withTicketB: true });
    await assertFails(parent(PARENT_A).doc(ticketPath(SCHOOL, TICKET_B)).get());
  });

  // S013
  test('cross-tenant read → denied', async () => {
    await seed();
    await assertFails(parent(PARENT_A, OTHER_SCHOOL).doc(ticketPath(SCHOOL, TICKET_A)).get());
  });

  // S027 — the defect that started the correctness gate
  test('parent PATCHes status to closed → denied (no client update path)', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, TICKET_A)).update({ status: 'closed' })
    );
  });

  // S028
  test('parent PATCHes lastMessageAt to the future → denied', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc(ticketPath(SCHOOL, TICKET_A))
        .update({ lastMessageAt: new Date(4102444800000) })
    );
  });

  test('parent deletes their ticket → denied', async () => {
    await seed();
    await assertFails(parent(PARENT_A).doc(ticketPath(SCHOOL, TICKET_A)).delete());
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('supportMessages', () => {
  test('parent replies on their own thread → allowed', async () => {
    await seed();
    await assertSucceeds(parent(PARENT_A).doc('supportMessages/MSG_1').set(newMessage()));
  });

  test('parent forges a message into another family\'s thread → denied', async () => {
    await seed({ withTicketB: true });
    await assertFails(
      parent(PARENT_A).doc('supportMessages/MSG_2')
        .set(newMessage({ ticketId: TICKET_B }))
    );
  });

  test('parent claims senderType staff → denied', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc('supportMessages/MSG_3').set(newMessage({ senderType: 'staff' }))
    );
  });

  test('parent spoofs senderId → denied', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc('supportMessages/MSG_4').set(newMessage({ senderId: PARENT_B }))
    );
  });

  // S031 — the Stories failure shape: a get() against a missing parent doc
  test('message on a ticket that does not exist → denies cleanly', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc('supportMessages/MSG_5')
        .set(newMessage({ ticketId: 'TKT_DOESNOTEXIST0' }))
    );
  });

  test('messages are immutable — update denied', async () => {
    await seed();
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().doc('supportMessages/MSG_6').set({
        schoolId: SCHOOL, ticketId: TICKET_A, reporterId: PARENT_A,
        senderType: 'parent', senderId: PARENT_A, body: 'original',
      });
    });
    await assertFails(
      parent(PARENT_A).doc('supportMessages/MSG_6').update({ body: 'edited' })
    );
  });
});

// ─────────────────────────────────────────────────────────────────────────────
describe('server-only collections are unreachable by any client', () => {
  // The reason supportNotes is a separate collection rather than a flag on
  // supportMessages: a flag is only as strong as the rule filtering it.
  test('supportNotes read → denied', async () => {
    await seed();
    await assertFails(parent(PARENT_A).doc('supportNotes/n1').get());
  });

  test('supportCounters read → denied', async () => {
    await seed({ openCount: 2 });
    await assertFails(parent(PARENT_A).doc(`supportCounters/${SCHOOL}_${PARENT_A}`).get());
  });

  test('supportReporterIdentity read → denied (anonymity is not decoration)', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc(`supportReporterIdentity/${SCHOOL}_${TICKET_A}`).get()
    );
  });

  test('supportNotes write → denied', async () => {
    await seed();
    await assertFails(
      parent(PARENT_A).doc('supportNotes/n2').set({ schoolId: SCHOOL, body: 'injected' })
    );
  });
});
