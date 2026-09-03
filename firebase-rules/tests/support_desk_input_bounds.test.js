/**
 * Support Desk — server-side input bounds and the open-ticket cap.
 *
 * These rows (SD-T2-011/014/015/020/036) are GAP-DOCUMENTING: they assert what
 * the rules actually do today, not what we might wish. A test that encodes a
 * wish fails on day one and gets deleted; a test that encodes the real boundary
 * tells you the day someone changes it.
 *
 * Each case is therefore labelled CURRENT (the behaviour as shipped) with the
 * consequence spelled out, so tightening a rule later breaks a test that
 * explains exactly what it was defending.
 */
const {
  initializeTestEnvironment, assertFails, assertSucceeds,
} = require('@firebase/rules-unit-testing');
const firebase = require('firebase/compat/app');
require('firebase/compat/firestore');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');
const SCHOOL = 'SCH_TEST_BOUNDS_01';
const PARENT = 'STU_BOUNDS_A';
const NOW = () => firebase.firestore.FieldValue.serverTimestamp();

let env;
const parent = () => env
  .authenticatedContext(PARENT, { school_id: SCHOOL, role: 'parent' })
  .firestore();

/** A ticket create that satisfies every predicate; cases vary one field. */
const ticket = (id, extra = {}) => ({
  schoolId: SCHOOL, ticketId: id,
  reporterId: PARENT, studentId: PARENT,
  studentName: 'Bounds Child', className: 'Class 9th-Section A',
  category: 'fees', subject: 'bounds probe',
  status: 'open', lane: 'normal', attachments: [],
  createdAt: NOW(), lastMessageAt: NOW(),
  ...extra,
});

async function seed(openCount = 0) {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    await db.doc(`schoolControl/${SCHOOL}`).set({
      schoolId: SCHOOL,
      lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
    });
    await db.doc(`schools/${SCHOOL}`).set({ schoolId: SCHOOL, name: 'Bounds Test' });
    await db.doc(`supportCounters/${SCHOOL}_${PARENT}`)
      .set({ schoolId: SCHOOL, reporterId: PARENT, openCount });
    await db.doc(`supportTickets/${SCHOOL}_TKT_BOUNDSHOST00001`).set({
      schoolId: SCHOOL, ticketId: 'TKT_BOUNDSHOST00001', reporterId: PARENT,
      studentId: PARENT, status: 'open', lane: 'normal', category: 'fees', messageCount: 1,
    });
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
});
afterAll(async () => { if (env) await env.cleanup(); });
beforeEach(async () => { await env.clearFirestore(); await seed(0); });

describe('SD-T2-014 — no server-side length or category validation on a ticket', () => {
  test('an empty subject is still accepted — there is deliberately no minimum', async () => {
    // The staff side runs _text() with min/max bounds. The parent side has no
    // equivalent, so a blank complaint reaches the queue and occupies a slot.
    await assertSucceeds(
      parent().doc(`supportTickets/${SCHOOL}_TKT_BOUNDSEMPTY0001`)
        .set(ticket('TKT_BOUNDSEMPTY0001', { subject: '' }))
    );
  });

  test('R15: a ~100KB subject is now REFUSED (cap 200)', async () => {
    await assertFails(
      parent().doc(`supportTickets/${SCHOOL}_TKT_BOUNDSHUGE00001`)
        .set(ticket('TKT_BOUNDSHUGE00001', { subject: 'x'.repeat(100000) }))
    );
  });

  test('R15: an arbitrary category is now REFUSED (allowlist of 9)', async () => {
    await assertFails(
      parent().doc(`supportTickets/${SCHOOL}_TKT_BOUNDSCAT000001`)
        .set(ticket('TKT_BOUNDSCAT000001', { category: 'zzz_not_a_real_category' }))
    );
  });
});

describe('SD-T2-036 — a forged "conduct" CATEGORY is not the confidential LANE', () => {
  test('CURRENT: category "conduct" is accepted and lands in the ordinary queue', async () => {
    // The consequence is the point: it is readable by anyone holding
    // Support:view — potentially including the staff member being reported.
    await assertSucceeds(
      parent().doc(`supportTickets/${SCHOOL}_TKT_BOUNDSCONDUCT01`)
        .set(ticket('TKT_BOUNDSCONDUCT01', { category: 'conduct' }))
    );
  });

  test('the LANE itself is still forced to normal — a forged lane is refused', async () => {
    await assertFails(
      parent().doc(`supportTickets/${SCHOOL}_TKT_BOUNDSLANE00001`)
        .set(ticket('TKT_BOUNDSLANE00001', { lane: 'conduct' }))
    );
  });
});

describe('SD-T2-015 / SD-T2-020 — message body length and attachment count', () => {
  const message = (extra = {}) => ({
    schoolId: SCHOOL, ticketId: 'TKT_BOUNDSHOST00001',
    reporterId: PARENT, senderType: 'parent', senderId: PARENT,
    senderName: 'Bounds Parent', body: 'probe', createdAt: NOW(),
    ...extra,
  });

  test('R15: a ~100KB message body is now REFUSED (cap 5000)', async () => {
    await assertFails(
      parent().collection('supportMessages').add(message({ body: 'y'.repeat(100000) }))
    );
  });

  test('R15: 6 attachments on a MESSAGE are now REFUSED (cap 3, matching a ticket)', async () => {
    // Both are capped at 3 now. They were not: a ticket was capped and a
    // message was not, in the same module with the same uploader.
    await assertFails(
      parent().collection('supportMessages')
        .add(message({ attachments: ['1.jpg','2.jpg','3.jpg','4.jpg','5.jpg','6.jpg'] }))
    );
  });

  test('for contrast, 4 attachments on a TICKET are refused', async () => {
    await assertFails(
      parent().doc(`supportTickets/${SCHOOL}_TKT_BOUNDSATT000001`)
        .set(ticket('TKT_BOUNDSATT000001',
          { attachments: ['1.jpg','2.jpg','3.jpg','4.jpg'] }))
    );
  });
});

describe('SD-T2-011 — the open-ticket cap is check-then-act (R9)', () => {
  test('at openCount = 5 a single create is refused (the cap does work serially)', async () => {
    await env.clearFirestore(); await seed(5);
    await assertFails(
      parent().doc(`supportTickets/${SCHOOL}_TKT_BOUNDSCAP000001`)
        .set(ticket('TKT_BOUNDSCAP000001'))
    );
  });

  test('CURRENT: at openCount = 4, SIX concurrent creates all succeed', async () => {
    // The rule reads a counter that only a Cloud Function increments, and it
    // does so AFTER the write. Every request in a burst therefore reads the
    // same pre-increment value and every one passes underCap(). The cap is a
    // serial control being asked to do a concurrent job.
    await env.clearFirestore(); await seed(4);
    const db = parent();
    const results = await Promise.allSettled(
      Array.from({ length: 6 }, (_, i) =>
        db.doc(`supportTickets/${SCHOOL}_TKT_BOUNDSBURST0000${i}`)
          .set(ticket(`TKT_BOUNDSBURST0000${i}`)))
    );
    const ok = results.filter(r => r.status === 'fulfilled').length;
    // Report rather than merely assert — the row asks "how many actually succeed".
    console.log(`      [SD-T2-011] openCount=4, 6 concurrent creates → ${ok} succeeded`);
    expect(ok).toBeGreaterThan(1);
  });
});

describe('SD-T0-026 — server-owned fields a parent must not set at create', () => {
  test('status forged to "assigned" → denied (the rule pins it to open)', async () => {
    await assertFails(
      parent().doc(`supportTickets/${SCHOOL}_TKT_FORGESTATUS001`)
        .set(ticket('TKT_FORGESTATUS001', { status: 'assigned' })));
  });

  test('status forged to "closed" → denied', async () => {
    await assertFails(
      parent().doc(`supportTickets/${SCHOOL}_TKT_FORGECLOSED001`)
        .set(ticket('TKT_FORGECLOSED001', { status: 'closed' })));
  });

  test('assignedTo self-assigned at create → denied', async () => {
    // Not merely wrong data: a ticket that arrives pre-assigned skips triage
    // and lands in someone's queue without anyone having routed it.
    await assertFails(
      parent().doc(`supportTickets/${SCHOOL}_TKT_FORGEASSIGN01`)
        .set(ticket('TKT_FORGEASSIGN01', { assignedTo: 'STA0025' })));
  });

  test('ticketNo minted by the client → denied', async () => {
    // ticketNo is allocated by a Cloud Function transaction. A client-chosen
    // value collides with the sequence and gives two tickets the same number.
    await assertFails(
      parent().doc(`supportTickets/${SCHOOL}_TKT_FORGENUMBER01`)
        .set(ticket('TKT_FORGENUMBER01', { ticketNo: 1 })));
  });

  test('messageCount pre-seeded → denied', async () => {
    await assertFails(
      parent().doc(`supportTickets/${SCHOOL}_TKT_FORGECOUNT001`)
        .set(ticket('TKT_FORGECOUNT001', { messageCount: 99 })));
  });
});
