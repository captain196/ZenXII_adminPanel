/**
 * Support Desk — server-side triggers (P5).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  WHY THESE EXIST
 * ─────────────────────────────────────────────────────────────────────────────
 * Three jobs the client must not be trusted with, and one the panel cannot do:
 *
 *  1. onSupportTicketCreated  — assign the human-facing ticketNo from a
 *     transactional counter, build the write-time keywords[] search index,
 *     maintain the per-parent openCount that the RULES cap reads, and notify
 *     the desk.
 *
 *  2. onSupportMessageCreated — own the REOPEN TRANSITION. This is the whole
 *     reason the parent has `allow update: if false` on supportTickets. The
 *     old design let a parent patch `status` inside the reopen window, and
 *     hasOnly() constrains WHICH keys change, not what values they take — so a
 *     parent could write 'closed' and bury their own ticket, or junk and drop
 *     it out of every status filter: invisible to staff, live to them. Moving
 *     the transition here removed the client's write path entirely.
 *
 *  3. closeStaleTickets — retire resolved tickets once the reopen window has
 *     passed, so "resolved" and "closed" mean different things.
 *
 *  4. onSupportTicketStatusChanged — decrement openCount when a ticket leaves
 *     the active states. Without this the rules-layer cap in C-06 ratchets:
 *     a parent who raised five tickets over a year could never raise another.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  DIVISION OF LABOUR WITH THE PANEL — READ BEFORE ADDING A COUNTER
 * ─────────────────────────────────────────────────────────────────────────────
 * The panel (Support.php::_append_message) ALREADY updates messageCount,
 * lastMessageAt and lastStaffReplyAt for messages it writes itself. So the
 * message trigger below handles `senderType === 'parent'` ONLY. Making it
 * handle every message would double-count every staff reply.
 *
 * The rule: whoever writes the message owns its denormals. The panel owns
 * staff and system messages; this file owns parent messages.
 */

const admin = require('firebase-admin');
const { onDocumentCreated, onDocumentUpdated } = require('firebase-functions/v2/firestore');
const { onSchedule } = require('firebase-functions/v2/scheduler');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();
const FieldValue = admin.firestore.FieldValue;
const REGION = 'us-central1';

/** Statuses that count toward a parent's open-ticket cap. */
const ACTIVE = ['open', 'assigned', 'reopened'];

/** Reopen window. Matches Support.php::resolve(). */
const REOPEN_DAYS = 7;

// ═════════════════════════════════════════════════════════════════════════════
//  helpers
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Normalised search tokens, built at WRITE time.
 *
 * Firestore cannot substring-search, so search is array-contains over this
 * field (index 7). It is built here rather than in the app because retrofitting
 * it means rewriting every document — and because a client-supplied search
 * index is a client-controlled search index.
 *
 * Tokens: words from the subject, the student's name, and the ticket number in
 * both bare and #-prefixed forms, since people search for "142" and "#142".
 */
function buildKeywords(t, ticketNo) {
  const out = new Set();
  const add = (s) => {
    String(s || '')
      .toLowerCase()
      .split(/[^a-z0-9]+/)
      .forEach((w) => { if (w.length >= 2 && w.length <= 40) out.add(w); });
  };
  add(t.subject);
  add(t.studentName);
  add(t.category);
  if (ticketNo) { out.add(String(ticketNo)); out.add('tkt' + ticketNo); }
  // Firestore caps array-contains fields at 40 000 elements, but a runaway
  // subject should not produce a giant document either.
  return Array.from(out).slice(0, 60);
}

/** Epoch millis from whatever timestamp shape a field holds. */
function ms(v) {
  if (!v) return 0;
  if (typeof v === 'number') return v > 1e12 ? v : v * 1000;
  if (typeof v === 'string') { const n = Date.parse(v); return Number.isNaN(n) ? 0 : n; }
  if (typeof v.toMillis === 'function') return v.toMillis();
  if (v._seconds) return v._seconds * 1000;
  return 0;
}

/**
 * Staff who should see a new ticket land.
 *
 * Reads staffCapabilities, which is the server-maintained mirror of RBAC —
 * the same source the security rules consult. Deliberately NOT a role-name
 * list: roughly 40 controllers still gate on role names and block custom
 * roles, and this module does not join them.
 *
 * ⚠ Requires the composite index [schoolId ASC, modules ARRAY_CONTAINS].
 *   Without it this query throws FAILED_PRECONDITION and the desk is never
 *   notified — silently, because a push that is never emitted logs nothing.
 */
async function deskRecipients(schoolId) {
  try {
    const snap = await db.collection('staffCapabilities')
      .where('schoolId', '==', schoolId)
      .where('modules', 'array-contains', 'Support')
      .limit(50)
      .get();
    return snap.docs.map((d) => d.id);
  } catch (e) {
    logger.error('[support] deskRecipients failed — is the staffCapabilities index deployed?', {
      schoolId, error: e.message,
    });
    return [];
  }
}

/**
 * Write a pushRequests document — the one door.
 *
 * Senders never call FCM. dispatchNoticeAndCircularPushes resolves recipients
 * from MARK_REGISTRY and sends. A mark absent from the registry is IGNORED,
 * not errored, so a typo here fails silently — check the registry when a push
 * does not arrive.
 */
async function emitPush(schoolId, mark, dedupeKey, fields) {
  try {
    await db.collection('pushRequests').doc(`${schoolId}_${dedupeKey}`).set({
      schoolId,
      mark,
      status: 'pending',
      markedBy: 'system',
      createdAt: new Date().toISOString(),
      ...fields,
    });
  } catch (e) {
    logger.error(`[support] emitPush ${mark} failed`, { schoolId, error: e.message });
  }
}

/** supportCounters doc ids. Distinct shapes, same collection. */
const seqRef  = (schoolId) => db.collection('supportCounters').doc(schoolId);
const openRef = (schoolId, reporterId) =>
  db.collection('supportCounters').doc(`${schoolId}_${reporterId}`);

// ═════════════════════════════════════════════════════════════════════════════
//  1 · Ticket created
// ═════════════════════════════════════════════════════════════════════════════

exports.onSupportTicketCreated = onDocumentCreated(
  { document: 'supportTickets/{docId}', region: REGION },
  async (event) => {
    const snap = event.data;
    if (!snap) return;
    const t = snap.data() || {};

    const schoolId   = String(t.schoolId || '');
    const ticketId   = String(t.ticketId || '');
    const reporterId = String(t.reporterId || '');
    if (!schoolId || !ticketId) {
      logger.warn('[support] ticket missing schoolId/ticketId — skipping', { id: snap.id });
      return;
    }

    // ── sequential, human-facing number ──────────────────────────────────
    // Transactional because two parents raising a ticket in the same second
    // must not both read lastTicketNo = 146.
    let ticketNo = 0;
    try {
      ticketNo = await db.runTransaction(async (tx) => {
        const ref = seqRef(schoolId);
        const doc = await tx.get(ref);
        const next = ((doc.exists && Number(doc.data().lastTicketNo)) || 0) + 1;
        tx.set(ref, { schoolId, lastTicketNo: next }, { merge: true });
        return next;
      });
    } catch (e) {
      // A missing number is cosmetic — ticketId is the real key, and the panel
      // renders "#—" rather than blocking the thread (E-05). Do not abort.
      logger.error('[support] ticketNo transaction failed', { schoolId, ticketId, error: e.message });
    }

    const patch = { keywords: buildKeywords(t, ticketNo) };
    if (ticketNo) patch.ticketNo = ticketNo;
    try {
      await snap.ref.update(patch);
    } catch (e) {
      logger.error('[support] ticket patch failed', { ticketId, error: e.message });
    }

    // ── per-parent open counter, read by the RULES cap (C-06) ────────────
    if (reporterId) {
      try {
        await openRef(schoolId, reporterId).set(
          { schoolId, reporterId, openCount: FieldValue.increment(1) },
          { merge: true }
        );
      } catch (e) {
        logger.error('[support] openCount increment failed', { schoolId, reporterId, error: e.message });
      }
    }

    // ── notify the desk ──────────────────────────────────────────────────
    const lane = String(t.lane || 'normal');
    const recipients = await deskRecipients(schoolId);
    if (!recipients.length) {
      logger.warn('[support] no desk recipients — nobody holds the Support module', { schoolId });
      return;
    }

    // A confidential ticket carries NO subject and NO category in the payload.
    // Notification text renders on a lock screen; a grievance subject appearing
    // there defeats the entire lane. (Not reachable in v1 — lane is always
    // 'normal' — but the branch ships with the code that would need it.)
    const confidential = lane !== 'normal';
    await emitPush(schoolId, 'TICKET_RAISED', `sup_new_${ticketId}`, {
      recipientStaffIds: recipients,
      ticketId,
      subject:       confidential ? 'A confidential report was submitted' : String(t.subject || ''),
      category:      confidential ? '' : String(t.category || ''),
      categoryLabel: confidential ? '' : String(t.category || ''),
    });
  }
);

// ═════════════════════════════════════════════════════════════════════════════
//  2 · Message created — parent side only
// ═════════════════════════════════════════════════════════════════════════════

exports.onSupportMessageCreated = onDocumentCreated(
  { document: 'supportMessages/{docId}', region: REGION },
  async (event) => {
    const snap = event.data;
    if (!snap) return;
    const m = snap.data() || {};

    // The panel owns the denormals for messages it writes. Handling those here
    // too would double-count every staff reply.
    if (String(m.senderType || '') !== 'parent') return;

    // ── WHERE THE AUTHORISATION FOR THE WRITES BELOW COMES FROM ───────────
    // This trigger runs with admin privileges and performs no check of its
    // own, which is correct but worth spelling out: the only way a document
    // with senderType 'parent' can exist is the rules-guarded client path,
    // which requires senderId == uid AND reporterId == uid AND a get() proving
    // that ticket's reporterId is also uid. The panel never writes 'parent'
    // (Support.php only emits 'staff' and 'system').
    //
    // So the (schoolId, ticketId) pair below is already proven to belong to
    // the authenticated parent. If a future writer starts emitting 'parent'
    // messages server-side, that guarantee is gone and this needs a real check.

    const schoolId = String(m.schoolId || '');
    const ticketId = String(m.ticketId || '');
    if (!schoolId || !ticketId) return;

    const ref = db.collection('supportTickets').doc(`${schoolId}_${ticketId}`);

    let after = null;
    try {
      after = await db.runTransaction(async (tx) => {
        const doc = await tx.get(ref);
        if (!doc.exists) return null;
        const t = doc.data() || {};
        const now = new Date().toISOString();

        const patch = {
          messageCount:      FieldValue.increment(1),
          lastMessageAt:     now,
          lastParentReplyAt: now,
          updatedAt:         now,
          updatedBy:         'parent',
        };

        // ── THE REOPEN TRANSITION (C-01) ─────────────────────────────────
        // A parent replying to a resolved ticket inside the window reopens it.
        // The alternative — accepting the reply and leaving it resolved — is
        // the worst outcome available: the parent believes they have been
        // heard and nobody is looking at the queue item.
        const status = String(t.status || '');
        let reopened = false;
        if (status === 'resolved' && Date.now() < ms(t.reopenableUntil)) {
          // Straight back to 'assigned' when it still has an owner; the
          // 'reopened' state exists for tickets that lost theirs.
          patch.status     = t.assignedTo ? 'assigned' : 'reopened';
          patch.resolvedAt = FieldValue.delete();
          reopened = true;
        }

        tx.update(ref, patch);
        return { t, reopened, status };
      });
    } catch (e) {
      logger.error('[support] message transaction failed', { ticketId, error: e.message });
      return;
    }
    if (!after) {
      // The thread's ticket is gone. Rules make this unreachable from a client
      // (create requires the parent own the ticket), so it means server-side
      // deletion — worth a log, not a retry.
      logger.warn('[support] message for a missing ticket', { ticketId });
      return;
    }

    const { t, reopened } = after;

    // Reopening restores the parent's open-ticket slot; leaving it decremented
    // would let a parent accumulate invisible headroom by reopening.
    if (reopened && t.reporterId) {
      await openRef(schoolId, String(t.reporterId))
        .set({ openCount: FieldValue.increment(1) }, { merge: true })
        .catch((e) => logger.error('[support] reopen openCount failed', { error: e.message }));
    }

    const assignee = String(t.assignedTo || '');
    if (reopened) {
      await emitPush(schoolId, 'TICKET_REOPENED', `sup_reo_${ticketId}_${Date.now()}`, {
        recipientStaffIds: assignee ? [assignee] : await deskRecipients(schoolId),
        ticketId,
      });
      return;
    }

    await emitPush(schoolId, 'TICKET_REPLIED', `sup_prep_${ticketId}_${Date.now()}`, {
      recipientIds: assignee ? [assignee] : await deskRecipients(schoolId),
      ticketId,
      senderName: String(m.senderName || 'Parent'),
      preview:    String(m.body || '').slice(0, 160),
    });
  }
);

// ═════════════════════════════════════════════════════════════════════════════
//  3 · Status changed — keep openCount honest
// ═════════════════════════════════════════════════════════════════════════════

exports.onSupportTicketStatusChanged = onDocumentUpdated(
  { document: 'supportTickets/{docId}', region: REGION },
  async (event) => {
    const before = event.data?.before?.data() || {};
    const after  = event.data?.after?.data()  || {};

    const was = String(before.status || '');
    const now = String(after.status  || '');
    if (was === now) return;

    const schoolId   = String(after.schoolId || '');
    const reporterId = String(after.reporterId || '');
    if (!schoolId || !reporterId) return;

    const wasActive = ACTIVE.includes(was);
    const isActive  = ACTIVE.includes(now);
    if (wasActive === isActive) return;

    // Leaving active frees a slot; returning to it consumes one. Clamped at
    // zero on read in the rules, but keep the stored value sane too.
    const delta = isActive ? 1 : -1;
    try {
      await openRef(schoolId, reporterId).set(
        { schoolId, reporterId, openCount: FieldValue.increment(delta) },
        { merge: true }
      );
    } catch (e) {
      logger.error('[support] openCount adjust failed', { schoolId, reporterId, delta, error: e.message });
    }
  }
);

// ═════════════════════════════════════════════════════════════════════════════
//  4 · Scheduled close of stale resolved tickets
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Close tickets whose reopen window has passed.
 *
 * ⚠ Requires index 5: [schoolId, status, reopenableUntil]. Its absence is
 *   SILENT — this sweep emits no push by design, so a missing index presents
 *   as a function that has simply never done anything. The explicit success
 *   log below exists so that failure is visible in the logs rather than
 *   invisible everywhere (UAT case S040).
 *
 * Deliberately no push: nothing happened that the parent needs to know. Their
 * window lapsed; a notification saying so would be noise, and worse, would
 * read as the school taking an action against their ticket.
 */
exports.closeStaleTickets = onSchedule(
  {
    schedule: 'every 6 hours',
    timeZone: 'UTC',
    timeoutSeconds: 300,
    memory: '256MiB',
    region: REGION,
  },
  async () => {
    const cutoff = new Date().toISOString();
    let closed = 0;

    // Collection-group-wide: the sweep is tenant-agnostic on purpose, because
    // running it per school would need a school list and would silently skip
    // any tenant missing from it.
    while (true) {
      let snap;
      try {
        snap = await db.collection('supportTickets')
          .where('status', '==', 'resolved')
          .where('reopenableUntil', '<', cutoff)
          .limit(400)
          .get();
      } catch (e) {
        logger.error('[support] closeStaleTickets query failed — index missing?', { error: e.message });
        return;
      }
      if (snap.empty) break;

      const batch = db.batch();
      snap.docs.forEach((d) => batch.update(d.ref, {
        status:        'closed',
        closedAt:      new Date().toISOString(),
        closureReason: 'Reopen window elapsed',
        updatedAt:     new Date().toISOString(),
        updatedBy:     'system',
      }));
      await batch.commit();
      closed += snap.size;
      if (snap.size < 400) break;
    }

    // Assert success explicitly — a silent sweep is indistinguishable from a
    // broken one.
    logger.info('[support] closeStaleTickets complete', { closed, cutoff });
  }
);
