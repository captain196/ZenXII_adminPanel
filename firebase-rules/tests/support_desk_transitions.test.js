/**
 * Support Desk — transition-boundary rules tests.
 *
 * Authored after A6 MODELLER produced the module's first state machine. Every
 * case here defends a boundary that no existing test touched: the rules suites
 * covered ownership, tenancy and field forgery, but NOTHING asserted anything
 * about a ticket's STATUS when a parent appends a message.
 *
 * The headline case is X5. Two separate comments in the codebase assert that
 * the server refuses a parent reply to a closed ticket:
 *
 *   SupportThreadScreen.kt:152  "the server refuses the write"
 *   views/support/thread.php:240 (same claim for staff)
 *
 * For staff that is true — Support.php:913 returns 409. For the PARENT it is
 * false: the supportMessages create rule checks schoolId, senderType, senderId,
 * reporterId, createdAt and ticket ownership, and never reads ticket.status.
 * These tests establish which way it actually behaves, so the claim is settled
 * by execution rather than by reading a comment.
 *
 * A test that documents current-but-wrong behaviour is marked CURRENT; one that
 * asserts the behaviour we want is marked REQUIRED and is expected to fail until
 * the rule is changed. Nothing here is skipped — a skipped test is a test nobody
 * looks at.
 */
const {
  initializeTestEnvironment,
  assertFails,
  assertSucceeds,
} = require('@firebase/rules-unit-testing');
const firebase = require('firebase/compat/app');
require('firebase/compat/firestore');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');

const SCHOOL = 'SCH_TEST_TRN_001';
const PARENT_A = 'STU_TRN_A';

const T_OPEN = 'TKT_TRNOPEN000000';
const T_CLOSED = 'TKT_TRNCLOSED0000';
const T_RESOLVED = 'TKT_TRNRESOLVED00';

const NOW = () => firebase.firestore.FieldValue.serverTimestamp();
const ticketPath = (tid) => `supportTickets/${SCHOOL}_${tid}`;

let env;

function parent(uid = PARENT_A) {
  return env
    .authenticatedContext(uid, { school_id: SCHOOL, role: 'parent' })
    .firestore();
}

/** A well-formed parent message. Only ticketId varies between cases. */
function message(ticketId, extra = {}) {
  return {
    schoolId: SCHOOL,
    ticketId,
    reporterId: PARENT_A,
    senderType: 'parent',
    senderId: PARENT_A,
    senderName: 'Test Parent',
    body: 'transition probe',
    createdAt: NOW(),
    ...extra,
  };
}

async function seed() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.doc(`schoolControl/${SCHOOL}`).set({
      schoolId: SCHOOL,
      lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
    });
    await db.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL, name: 'Transition Test' });

    const base = {
      schoolId: SCHOOL, lane: 'normal', reporterId: PARENT_A,
      studentId: PARENT_A, category: 'fees', messageCount: 1,
    };
    await db.doc(ticketPath(T_OPEN)).set({ ...base, ticketId: T_OPEN, status: 'open' });
    await db.doc(ticketPath(T_CLOSED)).set({
      ...base, ticketId: T_CLOSED, status: 'closed',
      closedAt: new Date().toISOString(), closureReason: 'seeded closed',
    });
    await db.doc(ticketPath(T_RESOLVED)).set({
      ...base, ticketId: T_RESOLVED, status: 'resolved',
      resolvedAt: new Date().toISOString(),
      reopenableUntil: new Date(Date.now() + 7 * 86400e3).toISOString(),
    });
    await db.doc(`supportCounters/${SCHOOL}_${PARENT_A}`)
      .set({ schoolId: SCHOOL, reporterId: PARENT_A, openCount: 1 });
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seed(); });

describe('X5 — a parent appending to a ticket by STATUS', () => {
  test('open ticket → allowed (control: the mechanism works at all)', async () => {
    await assertSucceeds(
      parent().collection('supportMessages').add(message(T_OPEN))
    );
  });

  test('resolved ticket → allowed (REQUIRED: this is the reopen path)', async () => {
    // Must stay permitted. functions/supportDesk.js:291 turns a parent message
    // on a resolved ticket into the reopen transition; denying it here would
    // remove the only route a parent has back to a live conversation.
    await assertSucceeds(
      parent().collection('supportMessages').add(message(T_RESOLVED))
    );
  });

  test('CLOSED ticket → REQUIRED denied; two code comments claim this already holds', async () => {
    // If this passes, the rule reads ticket.status and the comments are true.
    // If it fails, the parent can append to a ticket the desk considers finished:
    // the CF then bumps messageCount/lastParentReplyAt on the closed ticket and
    // pushes TICKET_REPLIED to its assignee.
    await assertFails(
      parent().collection('supportMessages').add(message(T_CLOSED))
    );
  });
});

describe('X21 — server-owned fields a parent may forge at ticket create', () => {
  const newTicket = (extra = {}) => ({
    schoolId: SCHOOL,
    ticketId: 'TKT_FORGEPROBE0001',
    reporterId: PARENT_A,
    studentId: PARENT_A,
    studentName: 'Test Child',
    className: 'Class 9th-Section A',
    category: 'fees',
    subject: 'forge probe',
    status: 'open',
    lane: 'normal',
    attachments: [],
    createdAt: NOW(),
    lastMessageAt: NOW(),
    ...extra,
  });

  test('control — a clean create is allowed', async () => {
    await assertSucceeds(
      parent().doc('supportTickets/' + SCHOOL + '_TKT_FORGEPROBE0001').set(newTicket())
    );
  });

  test('REQUIRED — a create carrying lastStaffReplyAt is refused', async () => {
    // _awaiting_us() (Support.php:202-208) compares lastStaffReplyAt against
    // lastParentReplyAt. A far-future value removes the ticket from the desk's
    // "awaiting us" view permanently — a ticket nobody has answered, that the
    // queue reports as answered.
    await assertFails(
      parent()
        .doc('supportTickets/' + SCHOOL + '_TKT_FORGEPROBE0001')
        .set(newTicket({ lastStaffReplyAt: '2099-01-01T00:00:00+00:00' }))
    );
  });

  test('REQUIRED — a create carrying messageCount is refused', async () => {
    await assertFails(
      parent()
        .doc('supportTickets/' + SCHOOL + '_TKT_FORGEPROBE0001')
        .set(newTicket({ messageCount: 9999 }))
    );
  });
});
