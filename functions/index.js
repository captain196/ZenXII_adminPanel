/**
 * SchoolSync real-time push dispatcher.
 *
 * Triggered on every new `pushRequests/{docId}` doc. Handles the marks that
 * need real-time, admin-independent delivery:
 *   - NOTICE_CREATED / CIRCULAR_CREATED / EVENT_CREATED  (broadcast by audience)
 *   - MESSAGE_RECEIVED                                   (per-conversation)
 *   - PTM_CLASS_TEACHER                                  (per class-teacher)
 *   - FLAG_CREATED                                       (per-student → parent)
 *
 * The mark-space is PARTITIONED with the admin panel's PHP poller
 * (MY_Controller::_auto_process_push_requests), which owns the homework /
 * leave / attendance marks (source=teacher / homework_* / teacher_leave_*).
 * NEVER handle the same mark in both — that double-sends.
 *
 * Fan-out strategy:
 *   target_group → audience (role broadcast OR class/section students' parents)
 *   → userDevices query → fcmToken list → FCM multicast.
 */

const admin = require('firebase-admin');
const { onDocumentCreated } = require('firebase-functions/v2/firestore');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();
const messaging = admin.messaging();

// Marks recognised by this dispatcher. Each mark drives a different
// recipient-resolution path (see the branches inside the trigger below).
//
//   NOTICE_CREATED, CIRCULAR_CREATED — broadcast by role (target_group).
//   MESSAGE_RECEIVED                 — per-userId push (recipientIds).
//   PTM_CLASS_TEACHER                — per-staffId push for the specific
//                                      class teachers of a PTM's sections.
//                                      Replaces the previous "All Teachers"
//                                      overshoot for class-specific PTMs.
const MARKS_HANDLED = new Set([
  'NOTICE_CREATED',
  'CIRCULAR_CREATED',
  'EVENT_CREATED',        // Event published by admin → notify audience
  'MESSAGE_RECEIVED',
  'PTM_CLASS_TEACHER',
  'FLAG_CREATED',         // Red Flag raised by teacher → notify parent
]);

// The broadcast marks share one recipient-resolution path (target_group →
// audience). Each maps to the data.type + id field the apps switch on.
const BROADCAST_MARKS = {
  NOTICE_CREATED:   { type: 'notice_created',   idKey: 'noticeId',   fTitle: 'New Notice',    fBody: 'A new notice has been posted' },
  CIRCULAR_CREATED: { type: 'circular_created', idKey: 'circularId', fTitle: 'New Circular',  fBody: 'A new circular has been posted' },
  EVENT_CREATED:    { type: 'event_created',    idKey: 'eventId',    fTitle: 'New Event',     fBody: 'Tap to view details' },
};

/**
 * Resolve target_group → list of appRole(s) to query.
 * Default is both (All School). Customise if finer-grained targeting is needed.
 */
function rolesForTarget(targetGroup) {
  const t = String(targetGroup || 'All School').trim().toLowerCase();
  if (t === 'all teachers' || t === 'teachers' || t === 'all staff' || t === 'staff') return ['teacher'];
  if (t === 'all parents' || t === 'parents' || t === 'all students' || t === 'students') return ['parent'];
  // Class/section-targeted groups are handled by classSectionTarget() below
  // (resolved to the section's students → their parents), never reached here.
  // Any residual class-ish string ships to parents only as a safe fallback.
  if (t.includes('class ')) return ['parent'];
  return ['teacher', 'parent']; // "All School" and unknowns
}

/**
 * Detect a class- or section-scoped target_group and normalise it to a
 * student query. Returns null for role/all targets (handled by rolesForTarget).
 *
 *   "Class 10th|Section A"  → { sectionKey: "Class 10th/Section A" }
 *   "Class 10th"            → { className: "Class 10th" }
 *   "10th"                  → { className: "Class 10th" }
 *   "All Parents" / "All School" / "" → null
 *
 * Mirrors the admin panel's Communication::_audience_keys_from_group() class
 * detection so push targeting matches the in-app audience filter exactly.
 */
function classSectionTarget(targetGroup) {
  const raw = String(targetGroup || '').trim();
  if (!raw) return null;
  const low = raw.toLowerCase();
  if (low === 'all' || low.startsWith('all ')) return null;
  if (/(teacher|staff|faculty|admin|principal|parent|guardian|student)/.test(low)) return null;

  if (raw.includes('|')) {
    const [clsRaw, secRaw] = raw.split('|').map((s) => s.trim());
    const cls = /^class\s/i.test(clsRaw) ? clsRaw : (clsRaw ? `Class ${clsRaw}` : '');
    if (cls && secRaw) return { sectionKey: `${cls}/${secRaw}` };
    if (cls) return { className: cls };
    return null;
  }
  if (/^class\s/i.test(raw) || /^\d/.test(raw) || /(nursery|lkg|ukg|playgroup)/i.test(low)) {
    const cls = /^class\s/i.test(raw) ? raw : `Class ${raw}`;
    return { className: cls };
  }
  return null;
}

/**
 * Tokens for the PARENTS of every active student in a class (all sections) or
 * a single section. Parents authenticate with their child's studentId as the
 * Firebase Auth UID, so a student's id IS the parent's userDevices.userId.
 *
 * Uses a single-field equality query (sectionKey OR className) so NO composite
 * index is required; schoolId is filtered client-side because those field
 * values are not globally unique across schools. Withdrawn/inactive students
 * are excluded so their parents don't get pushes.
 */
async function tokensForParentsOfClassSection(schoolId, target) {
  let snap;
  if (target.sectionKey) {
    snap = await db.collection('students').where('sectionKey', '==', target.sectionKey).get();
  } else if (target.className) {
    snap = await db.collection('students').where('className', '==', target.className).get();
  } else {
    return [];
  }
  const studentIds = [];
  snap.forEach((d) => {
    const s = d.data() || {};
    if (s.schoolId !== schoolId) return;
    const st = String(s.status || '').toLowerCase();
    if (st === 'inactive' || st === 'left' || st === 'withdrawn' || st === 'deleted') return;
    const sid = String(s.studentId || d.id || '').trim();
    if (sid) studentIds.push(sid);
  });
  return tokensForUsers(schoolId, [...new Set(studentIds)]);
}

async function tokensForSchool(schoolId, roles) {
  const tokens = [];
  for (const role of roles) {
    const snap = await db.collection('userDevices')
      .where('schoolId', '==', schoolId)
      .where('appRole', '==', role)
      .where('status', '==', 'active')
      .get();
    snap.forEach(d => {
      const t = d.data().fcmToken;
      if (typeof t === 'string' && t.length > 0) tokens.push(t);
    });
  }
  // De-dupe in case a user has multiple devices with same token
  return [...new Set(tokens)];
}

/**
 * Tokens for an exact list of userIds (used for per-conversation message push).
 * `userId` on the userDevices doc matches `participantIds` on the conversation.
 */
async function tokensForUsers(schoolId, userIds) {
  if (!userIds || !userIds.length) return [];
  const tokens = [];
  // Firestore `in` filter supports 10 values per query — chunk if needed.
  for (let i = 0; i < userIds.length; i += 10) {
    const chunk = userIds.slice(i, i + 10);
    const snap = await db.collection('userDevices')
      .where('schoolId', '==', schoolId)
      .where('userId', 'in', chunk)
      .where('status', '==', 'active')
      .get();
    snap.forEach(d => {
      const t = d.data().fcmToken;
      if (typeof t === 'string' && t.length > 0) tokens.push(t);
    });
  }
  return [...new Set(tokens)];
}

async function sendToTokens(tokens, notification, dataPayload) {
  if (!tokens.length) return { successCount: 0, failureCount: 0, skipped: true };
  // sendEachForMulticast replaces deprecated sendMulticast; handles >500 via batches.
  const BATCH = 500;
  let total = { successCount: 0, failureCount: 0 };
  const invalidTokens = [];
  for (let i = 0; i < tokens.length; i += BATCH) {
    const chunk = tokens.slice(i, i + BATCH);
    const resp = await messaging.sendEachForMulticast({
      tokens: chunk,
      notification,
      data: dataPayload,
      // NOTE: no channelId override — Teacher app uses "school_sync_channel"
      // and Parent app uses "schoolsync_notifications", each set by the
      // FCMService.onMessageReceived → showNotification() path. Letting the
      // apps choose avoids a silent-drop on Android 8+ with a wrong channel.
      android: { priority: 'high' },
    });
    total.successCount += resp.successCount;
    total.failureCount += resp.failureCount;
    resp.responses.forEach((r, idx) => {
      if (!r.success) {
        const code = r.error?.code || '';
        // Clean up stale/invalid tokens so we stop hitting them next time.
        if (code === 'messaging/registration-token-not-registered' ||
            code === 'messaging/invalid-registration-token') {
          invalidTokens.push(chunk[idx]);
        }
      }
    });
  }
  if (invalidTokens.length) {
    logger.info(`Pruning ${invalidTokens.length} stale FCM tokens`);
    // Best-effort cleanup — don't block on failures
    const batch = db.batch();
    const snaps = await Promise.all(invalidTokens.map(tok =>
      db.collection('userDevices').where('fcmToken', '==', tok).limit(5).get()
    ));
    snaps.forEach(snap => snap.forEach(d => batch.update(d.ref, { status: 'stale', fcmToken: '' })));
    await batch.commit().catch(e => logger.warn('Stale-token cleanup failed:', e.message));
  }
  return total;
}

exports.dispatchNoticeAndCircularPushes = onDocumentCreated(
  {
    document: 'pushRequests/{reqId}',
    region: 'us-central1', // change if your project uses a different region
  },
  async (event) => {
    const snap = event.data;
    if (!snap) return;
    const doc = snap.data() || {};
    const mark = doc.mark || '';

    if (!MARKS_HANDLED.has(mark)) {
      // Not ours — leave it for the other CF (e.g. HOMEWORK_CREATED).
      return;
    }

    const schoolId = doc.schoolId || '';
    if (!schoolId) {
      logger.warn(`[${mark}] missing schoolId — dropping`, { id: snap.id });
      await snap.ref.set({ status: 'error', error: 'missing schoolId', processedAt: new Date().toISOString() }, { merge: true });
      return;
    }

    // ── Branch per mark ─────────────────────────────────────────────
    let tokens = [];
    let notification;
    let dataPayload;

    if (mark === 'MESSAGE_RECEIVED') {
      // Per-conversation: fetch recipients from participantIds minus sender.
      const convId  = String(doc.conversationId || '');
      const senderId = String(doc.senderId || '');
      const recipientIds = Array.isArray(doc.recipientIds) ? doc.recipientIds : [];
      if (!recipientIds.length || !convId) {
        logger.warn(`[${mark}] missing conversationId or recipientIds`, { id: snap.id });
        await snap.ref.set({ status: 'error', error: 'missing conversationId/recipientIds', processedAt: new Date().toISOString() }, { merge: true });
        return;
      }
      tokens = await tokensForUsers(schoolId, recipientIds);
      const senderName = String(doc.senderName || 'New message').slice(0, 80);
      const msgBody    = String(doc.body || '').slice(0, 180);
      notification = { title: senderName, body: msgBody };
      dataPayload = {
        type: 'message',
        senderName,
        senderId,
        message: msgBody,
        conversationId: convId,
        schoolId,
      };
      logger.info(`[${mark}] conv=${convId} recipients=${recipientIds.length} tokens=${tokens.length}`);
    } else if (mark === 'FLAG_CREATED') {
      // Red Flag raised on a specific student. Parents authenticate with
      // their child's studentId as the Firebase Auth UID, so targeting
      // the parent is the same as targeting the studentId.
      const studentId   = String(doc.studentId   || '');
      const studentName = String(doc.studentName || '').slice(0, 80);
      const flagId      = String(doc.flagId      || '');
      const severity    = String(doc.severity    || 'low').toLowerCase();
      const flagType    = String(doc.flagType    || doc.type || '').toLowerCase();
      const message     = String(doc.message     || '').slice(0, 180);
      const teacherName = String(doc.teacherName || '').slice(0, 80);

      if (!studentId || !flagId) {
        logger.warn(`[${mark}] missing studentId/flagId — dropping`, { id: snap.id });
        await snap.ref.set({ status: 'error', error: 'missing studentId/flagId', processedAt: new Date().toISOString() }, { merge: true });
        return;
      }
      tokens = await tokensForUsers(schoolId, [studentId]);

      // Title varies by severity; body shows teacher + first line of message.
      const sevLabel = severity === 'high'   ? '🔴 Red Flag'
                     : severity === 'medium' ? '🟠 Concern'
                     :                         'ℹ️  Note';
      const subjectStr = String(doc.subject || '').slice(0, 40);
      const titlePieces = [sevLabel, studentName].filter(Boolean);
      notification = {
        title: titlePieces.join(' · ') || 'New flag from school',
        body:  (subjectStr ? `${subjectStr}: ` : '') + (message || `${teacherName} raised a ${flagType || 'flag'}`),
      };
      dataPayload = {
        type:        'red_flag',
        flagId,
        studentId,
        severity,
        flagType,
        schoolId,
      };
      logger.info(`[${mark}] school=${schoolId} student=${studentId} sev=${severity} tokens=${tokens.length}`);
    } else if (mark === 'PTM_CLASS_TEACHER') {
      // Per-staffId targeting for the section's class teachers. Replaces
      // the legacy "All Teachers" overshoot — only the specific teachers
      // who own a section in the PTM get the push.
      const recipientStaffIds = Array.isArray(doc.recipientStaffIds) ? doc.recipientStaffIds : [];
      if (!recipientStaffIds.length) {
        logger.warn(`[${mark}] missing recipientStaffIds`, { id: snap.id });
        await snap.ref.set({ status: 'error', error: 'missing recipientStaffIds', processedAt: new Date().toISOString() }, { merge: true });
        return;
      }
      tokens = await tokensForUsers(schoolId, recipientStaffIds);
      const title = String(doc.title || 'Parent-Teacher Meeting').slice(0, 120);
      const body  = String(doc.body  || '').slice(0, 240);
      notification = { title, body };
      dataPayload = {
        type:       'ptm_class_teacher',
        ptmEventId: String(doc.ptmEventId || ''),
        noticeId:   String(doc.noticeId   || ''),
        category:   'meeting',
        schoolId,
      };
      logger.info(`[${mark}] school=${schoolId} staffIds=${recipientStaffIds.length} tokens=${tokens.length}`);
    } else {
      // Broadcast marks: NOTICE_CREATED / CIRCULAR_CREATED / EVENT_CREATED.
      const spec = BROADCAST_MARKS[mark];
      const resourceId = String(doc[spec.idKey] || doc.source_id || '');

      const title = String(doc.title || spec.fTitle).slice(0, 120);
      const body  = String(doc.body  || spec.fBody).slice(0, 240);

      // Class/section-scoped → resolve to that group's students' parents so we
      // don't over-broadcast to the whole school. Otherwise fan out by role.
      const cs = classSectionTarget(doc.target_group);
      if (cs) {
        tokens = await tokensForParentsOfClassSection(schoolId, cs);
        logger.info(`[${mark}] school=${schoolId} target="${doc.target_group}" → ${cs.sectionKey ? 'section ' + cs.sectionKey : 'class ' + cs.className} → recipients=${tokens.length}`);
      } else {
        const roles = rolesForTarget(doc.target_group);
        tokens = await tokensForSchool(schoolId, roles);
        logger.info(`[${mark}] school=${schoolId} target="${doc.target_group}" → roles=${roles.join(',')} recipients=${tokens.length}`);
      }
      notification = { title, body };
      dataPayload = {
        type: spec.type,
        [spec.idKey]: resourceId,
        category: String(doc.category || ''),
        schoolId,
      };
    }

    try {
      const result = await sendToTokens(tokens, notification, dataPayload);

      await snap.ref.set({
        status: 'done',
        processedAt: new Date().toISOString(),
        recipients: tokens.length,
        fcmSuccess: result.successCount || 0,
        fcmFailure: result.failureCount || 0,
      }, { merge: true });
    } catch (err) {
      logger.error(`[${mark}] dispatch failed:`, err);
      await snap.ref.set({
        status: 'error',
        error: String(err.message || err).slice(0, 400),
        processedAt: new Date().toISOString(),
      }, { merge: true });
    }
  }
);

// ─── Stories cleanup (Hardening #3) ────────────────────────────────
// onStoryDeleted + sweepExpiredStories — see ./storiesCleanup.js
const stories = require("./storiesCleanup");
exports.onStoryDeleted       = stories.onStoryDeleted;
exports.sweepExpiredStories  = stories.sweepExpiredStories;

// ─── PTM creation → async push fan-out (Phase D perf) ──────────────
//
// Watches `ptmEvents/{ptmDocId}` document creates. When a new scheduled
// PTM lands, this CF emits the pushRequests rows the existing
// `dispatchNoticeAndCircularPushes` will fan out to FCM. Doing this in a
// CF (instead of synchronously inside `Ptm::save()`) shaves ~1–2 s off
// the admin save and stops admin clients from blocking on FCM SDK calls.
exports.onPtmCreated = onDocumentCreated(
  {
    document: 'ptmEvents/{ptmDocId}',
    region: 'us-central1',
  },
  async (event) => {
    const snap = event.data;
    if (!snap) return;
    const doc = snap.data() || {};

    // Only fire for newly-scheduled PTMs. Cancelled / completed docs
    // skip — admin set_status() handles those separately for now.
    const status = String(doc.status || '').toLowerCase();
    if (status !== 'scheduled') {
      logger.info(`[onPtmCreated] skipping doc=${snap.id} status=${status}`);
      return;
    }

    const schoolId = String(doc.schoolId || '');
    if (!schoolId) {
      logger.warn(`[onPtmCreated] missing schoolId on doc=${snap.id}`);
      return;
    }

    const ptmEventId = String(doc.ptmEventId || snap.id);
    const titleRaw   = String(doc.title || 'Parent-Teacher Meeting').slice(0, 120);
    const title      = `[PTM] ${titleRaw}`;

    // Build a compact body for the push notification.
    const bodyParts = [];
    if (doc.description) bodyParts.push(String(doc.description));
    if (doc.date)        bodyParts.push(`Date: ${doc.date}`);
    if (doc.startTime && doc.endTime) bodyParts.push(`Time: ${doc.startTime}–${doc.endTime}`);
    if (doc.location)    bodyParts.push(`Venue: ${doc.location}`);
    const body = bodyParts.join('\n').replace(/<[^>]+>/g, '').slice(0, 240);

    const sectionKey   = String(doc.sectionKey || 'ALL');
    const isAllSchool  = (sectionKey === 'ALL' || sectionKey === '');
    const parentTarget = isAllSchool ? 'All Parents' : sectionKey.replace('/', '|');

    const writes = [];

    // Parent push — broadcast by role/section through the existing
    // NOTICE_CREATED handler in dispatchNoticeAndCircularPushes.
    writes.push(db.collection('pushRequests').doc(`ptm_created_${ptmEventId}_parents`).set({
      schoolId,
      mark:         'NOTICE_CREATED',
      source:       'ptm_created',
      status:       'pending',
      ptmEventId,
      noticeId:     '',
      title,
      body,
      category:     'meeting',
      priority:     'Normal',
      target_group: parentTarget,
      markedBy:     'cf:onPtmCreated',
      createdAt:    new Date().toISOString(),
    }));

    // Per-class-teacher push — only the section's class teachers.
    const staffIds = Array.isArray(doc.sections)
      ? [...new Set(
            doc.sections
              .map(s => (s && typeof s.classTeacherId === 'string') ? s.classTeacherId.trim() : '')
              .filter(s => s.length > 0)
          )]
      : [];
    if (staffIds.length > 0) {
      writes.push(db.collection('pushRequests').doc(`ptm_classteacher_${ptmEventId}`).set({
        schoolId,
        mark:               'PTM_CLASS_TEACHER',
        source:             'ptm_class_teacher',
        status:             'pending',
        ptmEventId,
        noticeId:           '',
        title,
        body,
        category:           'meeting',
        priority:           'Normal',
        recipientStaffIds:  staffIds,
        markedBy:           'cf:onPtmCreated',
        createdAt:          new Date().toISOString(),
      }));
    } else {
      logger.warn(`[onPtmCreated] ${ptmEventId}: no class teachers in sections[] — skipping teacher push`);
    }

    try {
      await Promise.all(writes);
      logger.info(`[onPtmCreated] ${ptmEventId} school=${schoolId} parentTarget="${parentTarget}" staffIds=${staffIds.length} pushRequests=${writes.length}`);
    } catch (err) {
      logger.error(`[onPtmCreated] failed for ${ptmEventId}:`, err);
    }
  }
);

// ─── Fee Demand Generation Worker (Phase 10) ───────────────────────
// processFeeGenerationJob — triggered on new fee_generation_jobs/{jobId}
// document. Handles bulk demand creation with batched writes +
// bounded concurrency. See ./fee_generation_worker.js.
const feeWorker = require("./fee_generation_worker");
exports.processFeeGenerationJob = feeWorker.processFeeGenerationJob;

// ─── Phase 3A — Operational sweep (detect-only) ─────────────────────
// feeOpsSweep — scheduled every 10 min. Detects stuck refunds, stuck
// CF jobs, orphan pending-write markers, and stuck online orders;
// writes deduplicated alerts to feeOpsAlerts. Hard cap of 100 alert
// upserts per sweep to prevent storm scenarios. NO auto-remediation.
const opsSweep = require("./ops_sweep_worker");
exports.feeOpsSweep = opsSweep.feeOpsSweep;

// ─── getRecoveryContact (forgot-password) ──────────────────────────
// Unauthenticated Gen-2 callable. Resolves a user's school from their
// login id via the Auth custom claim and returns ONLY the school's
// recovery-contact block. Used by the Teacher/Parent apps' forgot-
// password screens. See ./recoveryContact.js.
const recoveryContact = require("./recoveryContact");
exports.getRecoveryContact = recoveryContact.getRecoveryContact;

