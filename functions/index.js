/**
 * SchoolSync UNIVERSAL push dispatcher.
 *
 * Single delivery path for the whole platform. Triggered on every new
 * `pushRequests/{docId}` doc; every module — any PHP controller or either
 * app — signals a push by writing ONE doc { schoolId, mark, ...audience }.
 * The mark's row in MARK_REGISTRY below drives recipient resolution + payload.
 *
 * To add push to a new module: add a row to MARK_REGISTRY and have the
 * producer write the doc. No new send code, auth, or token plumbing.
 *
 * MIGRATION IN PROGRESS: the legacy senders (MY_Controller PHP poller,
 * inline Push_service calls, and the messageQueue engine) are being retired
 * onto this dispatcher module-by-module. HARD RULE: when a module starts
 * writing a mark here, its old sender MUST be disabled in the same change —
 * a mark handled in two places double-sends.
 *
 * Audience modes (see AUDIENCE): BROADCAST (target_group → roles OR
 * class/section students' parents) and USERS (explicit id list). Both resolve
 * to a userDevices query → fcmToken list → FCM multicast (notification + data,
 * high priority, no channel_id so each app's manifest default channel wins).
 */

const admin = require('firebase-admin');
const { onDocumentCreated } = require('firebase-functions/v2/firestore');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();
const messaging = admin.messaging();

// ─── UNIVERSAL MARK REGISTRY ───────────────────────────────────────
// Single source of truth for every push in the platform. To add push to
// a new module you add ONE row here + have the producer (PHP controller or
// app) write a `pushRequests` doc with { schoolId, mark, ...audience fields }.
// NOTHING else sends push — the PHP poller / inline Push_service / messageQueue
// senders are being retired onto this dispatcher (see migration notes in repo).
//
// Each mark declares:
//   type        default data.type the apps switch on (doc.pushType overrides —
//               lets one mark cover approved/rejected, absent/late, etc.)
//   audience    BROADCAST (target_group → roles or class/section parents)
//               USERS     (explicit id list from `idField` + generic `userIds`)
//   idField     (USERS)  doc field holding the recipient id(s); string or array
//   idKey       (BROADCAST) resource-id field echoed into data for deep-linking
//   fTitle/fBody fallback title/body when the doc omits title/body
//   dataKeys    extra doc fields echoed verbatim into the data payload
//   notify(doc) OPTIONAL — derive {title,body} from fields (legacy flag/message)
//   data(doc)   OPTIONAL — derive extra data fields (paired with notify)
const AUDIENCE = { BROADCAST: 'broadcast', USERS: 'users' };

const MARK_REGISTRY = {
  // ── Broadcast (role / class-section) ──────────────────────────────
  NOTICE_CREATED:    { audience: AUDIENCE.BROADCAST, type: 'notice_created',   idKey: 'noticeId',    fTitle: 'New Notice',      fBody: 'A new notice has been posted',      dataKeys: ['category'] },
  CIRCULAR_CREATED:  { audience: AUDIENCE.BROADCAST, type: 'circular_created', idKey: 'circularId',  fTitle: 'New Circular',    fBody: 'A new circular has been posted',    dataKeys: ['category'] },
  EVENT_CREATED:     { audience: AUDIENCE.BROADCAST, type: 'event_created',    idKey: 'eventId',     fTitle: 'New Event',       fBody: 'Tap to view details',               dataKeys: ['category'] },
  GALLERY_ADDED:     { audience: AUDIENCE.BROADCAST, type: 'gallery_added',    idKey: 'albumId',     fTitle: 'New Photos',      fBody: 'New photos have been added',        dataKeys: ['category', 'mediaCount'] },
  STORY_POSTED:      { audience: AUDIENCE.BROADCAST, type: 'story_created',    idKey: 'storyId',     fTitle: 'New Story',       fBody: 'Tap to view',                       dataKeys: ['category'] },
  TIMETABLE_CHANGED: { audience: AUDIENCE.BROADCAST, type: 'timetable_changed',idKey: 'timetableId', fTitle: 'Timetable Update',fBody: 'Your timetable has changed',        dataKeys: ['category', 'day'] },
  EXAM_SCHEDULE:     { audience: AUDIENCE.BROADCAST, type: 'exam_schedule',    idKey: 'examId',      fTitle: 'Exam Schedule',   fBody: 'A new exam schedule is available',  dataKeys: ['category'] },
  // NOTE: Homework (create/review) is intentionally NOT registered here. The
  // Teacher app writes those pushRequests docs with mark=HOMEWORK_CREATED/
  // HOMEWORK_REVIEWED *and* a legacy `source`, and the PHP poller already
  // owns them (it resolves the class→parents audience correctly). Registering
  // them here would DOUBLE-SEND and, for HOMEWORK_CREATED, over-broadcast to
  // the whole school (the app's doc has class/section, not target_group).
  // App-originated events (homework, and app-side leave/attendance) stay on
  // the poller until a future pass migrates the app producer + audience map.

  // ── Explicit-recipient (single user or list) ──────────────────────
  MESSAGE_RECEIVED:  {
    audience: AUDIENCE.USERS, idField: 'recipientIds', type: 'message',
    notify: (d) => ({ title: String(d.senderName || 'New message').slice(0, 80), body: String(d.body || '').slice(0, 180) }),
    data:   (d) => ({ senderName: String(d.senderName || 'New message').slice(0, 80), senderId: String(d.senderId || ''), message: String(d.body || '').slice(0, 180), conversationId: String(d.conversationId || '') }),
  },
  PTM_CLASS_TEACHER: { audience: AUDIENCE.USERS, idField: 'recipientStaffIds', type: 'ptm_class_teacher', fTitle: 'Parent-Teacher Meeting', fBody: '', dataKeys: ['ptmEventId', 'noticeId', 'category'] },
  FLAG_CREATED:      {
    audience: AUDIENCE.USERS, idField: 'studentId', type: 'red_flag',
    notify: (d) => {
      const severity = String(d.severity || 'low').toLowerCase();
      const studentName = String(d.studentName || '').slice(0, 80);
      const flagType = String(d.flagType || d.type || '').toLowerCase();
      const message = String(d.message || '').slice(0, 180);
      const teacherName = String(d.teacherName || '').slice(0, 80);
      const subjectStr = String(d.subject || '').slice(0, 40);
      const sevLabel = severity === 'high' ? '🔴 Red Flag' : severity === 'medium' ? '🟠 Concern' : 'ℹ️  Note';
      return {
        title: [sevLabel, studentName].filter(Boolean).join(' · ') || 'New flag from school',
        body: (subjectStr ? `${subjectStr}: ` : '') + (message || `${teacherName} raised a ${flagType || 'flag'}`),
      };
    },
    data: (d) => ({ flagId: String(d.flagId || ''), studentId: String(d.studentId || ''), severity: String(d.severity || 'low').toLowerCase(), flagType: String(d.flagType || d.type || '').toLowerCase() }),
  },
  FLAG_RESOLVED:     {
    audience: AUDIENCE.USERS, idField: 'studentId', type: 'red_flag_resolved',
    notify: (d) => {
      const studentName = String(d.studentName || '').slice(0, 80);
      const subjectStr = String(d.subject || '').slice(0, 40);
      return {
        title: ['✅ Flag Resolved', studentName].filter(Boolean).join(' · ') || 'Flag resolved',
        body: (subjectStr ? `${subjectStr}: ` : '') + 'A flag about your child has been resolved.',
      };
    },
    data: (d) => ({ flagId: String(d.flagId || ''), studentId: String(d.studentId || ''), severity: String(d.severity || 'low').toLowerCase(), flagType: String(d.flagType || d.type || '').toLowerCase() }),
  },
  LEAVE_DECIDED:       { audience: AUDIENCE.USERS, idField: 'studentId', type: 'leave_update', fTitle: 'Leave Update', fBody: '', dataKeys: ['leaveId', 'startDate', 'endDate'] },
  STAFF_LEAVE_DECIDED: { audience: AUDIENCE.USERS, idField: 'staffId',   type: 'leave_update', fTitle: 'Leave Update', fBody: '', dataKeys: ['leaveId', 'startDate', 'endDate'] },
  ATTENDANCE_MARKED:   { audience: AUDIENCE.USERS, idField: 'studentId', type: 'student_absent', fTitle: 'Attendance', fBody: '', dataKeys: ['day', 'month', 'class', 'section', 'mark'] },
  SUBSTITUTE_ASSIGNED: { audience: AUDIENCE.USERS, idField: 'recipientStaffIds', type: 'substitute_assigned', fTitle: 'Substitute Assigned', fBody: 'You have been assigned as a substitute teacher', dataKeys: ['date', 'periods', 'class', 'section'] },
  RESULT_PUBLISHED:    { audience: AUDIENCE.USERS, idField: 'studentId', type: 'exam_result', fTitle: 'Result Published', fBody: 'Your result has been published', dataKeys: ['examId', 'resultId'] },
  FEE_PAID:            { audience: AUDIENCE.USERS, idField: 'studentId', type: 'fee_payment_confirmed', fTitle: 'Payment Received', fBody: 'Your fee payment has been received', dataKeys: ['amount', 'receiptId'] },
  FEE_DUE:             { audience: AUDIENCE.USERS, idField: 'studentId', type: 'fee_reminder', fTitle: 'Fee Reminder', fBody: 'You have a pending fee payment', dataKeys: ['amount', 'dueDate'] },
  BIRTHDAY:            { audience: AUDIENCE.USERS, idField: 'studentId', type: 'birthday_wish', fTitle: 'Happy Birthday!', fBody: 'Wishing you a wonderful day!', dataKeys: [] },
  // ── Support Desk (P5, 2026-08-26) ─────────────────────────────────
  // Producers: functions/supportDesk.js (parent side) and
  // Support.php::_push() (staff side). One mark, one producer per direction.
  TICKET_RAISED: {
    audience: AUDIENCE.USERS, idField: 'recipientStaffIds', type: 'support_ticket',
    notify: (d) => ({
      title: `New ticket${d.categoryLabel ? ' · ' + String(d.categoryLabel).slice(0, 40) : ''}`,
      body:  String(d.subject || 'A parent raised a support ticket').slice(0, 180),
    }),
    data: (d) => ({ ticketId: String(d.ticketId || ''), category: String(d.category || '') }),
  },
  TICKET_ASSIGNED: {
    audience: AUDIENCE.USERS, idField: 'recipientStaffIds', type: 'support_assigned',
    fTitle: 'Ticket assigned to you', fBody: 'Tap to open it', dataKeys: ['ticketId', 'category'],
  },
  // Direction is chosen by the PRODUCER, not the registry: the panel addresses
  // recipientIds at the parent, the CF addresses it at the assignee.
  TICKET_REPLIED: {
    audience: AUDIENCE.USERS, idField: 'recipientIds', type: 'support_reply',
    notify: (d) => ({
      title: String(d.senderName || 'School').slice(0, 80),
      body:  String(d.preview || '').slice(0, 180),
    }),
    data: (d) => ({ ticketId: String(d.ticketId || '') }),
  },
  TICKET_RESOLVED: {
    audience: AUDIENCE.USERS, idField: 'recipientIds', type: 'support_resolved',
    fTitle: 'Ticket resolved', fBody: 'Tap to view the response', dataKeys: ['ticketId'],
  },
  // Force-close ends a parent's complaint WITHOUT their agreement — it is the
  // one action in this module that does that. It emitted nothing, so the parent
  // learned of it only by reopening the app and noticing the thread had closed.
  // closeStaleTickets is deliberately silent (a ticket ageing out of its reopen
  // window is not news); a human closing your complaint is.
  TICKET_CLOSED: {
    audience: AUDIENCE.USERS, idField: 'recipientIds', type: 'support_closed',
    notify: (d) => ({
      title: 'Ticket closed',
      body: String(d.closureReason || 'The school closed this ticket').slice(0, 180),
    }),
    data: (d) => ({ ticketId: String(d.ticketId || '') }),
  },
  TICKET_REOPENED: {
    audience: AUDIENCE.USERS, idField: 'recipientStaffIds', type: 'support_reopened',
    fTitle: 'Ticket reopened', fBody: 'A parent reopened a resolved ticket', dataKeys: ['ticketId'],
  },
  SALARY_PROCESSED:    { audience: AUDIENCE.USERS, idField: 'staffId',   type: 'salary_processed', fTitle: 'Salary Processed', fBody: 'Your salary has been processed', dataKeys: ['amount', 'month'] },
};

// ── Payload/recipient helpers (used by the generic dispatcher below) ──

// Gather recipient userIds for a USERS-audience mark: from the mark's `idField`
// (string → single, array → many) PLUS the always-supported generic `userIds`.
function collectUserIds(doc, idField) {
  const out = [];
  const add = (v) => { const s = String(v == null ? '' : v).trim(); if (s) out.push(s); };
  const raw = idField ? doc[idField] : null;
  if (Array.isArray(raw)) raw.forEach(add); else if (raw) add(raw);
  if (Array.isArray(doc.userIds)) doc.userIds.forEach(add);
  return [...new Set(out)];
}

// FCM requires every data value to be a string; drop nulls.
function stringifyData(obj) {
  const out = {};
  Object.keys(obj).forEach((k) => { if (obj[k] != null) out[k] = String(obj[k]); });
  return out;
}

// Build the { notification, dataPayload } for a mark from its spec + doc.
// doc.title/body and doc.pushType override the registry defaults.
function buildPayload(spec, doc, schoolId) {
  const type = String(doc.pushType || spec.type || '');
  if (spec.notify) {
    const n = spec.notify(doc);
    const data = { type, schoolId, title: n.title, body: n.body, ...(spec.data ? spec.data(doc) : {}) };
    return { notification: { title: n.title, body: n.body }, dataPayload: stringifyData(data) };
  }
  const title = String(doc.title || spec.fTitle || '').slice(0, 120);
  const body = String(doc.body || spec.fBody || '').slice(0, 240);
  const data = { type, schoolId, title, body };
  if (spec.idKey) data[spec.idKey] = String(doc[spec.idKey] || doc.source_id || '');
  (spec.dataKeys || []).forEach((k) => { if (doc[k] != null) data[k] = doc[k]; });
  // Echo single-id routing fields so the apps can deep-link even if not listed.
  ['studentId', 'staffId'].forEach((k) => { if (doc[k] != null && data[k] == null) data[k] = doc[k]; });
  return { notification: { title, body }, dataPayload: stringifyData(data) };
}

function markError(snap, msg) {
  return snap.ref
    .set({ status: 'error', error: String(msg).slice(0, 400), processedAt: new Date().toISOString() }, { merge: true })
    .catch(() => {});
}

/**
 * A push that resolved an audience but reached NO device.
 *
 * Deliberately a third state beside 'done' and 'error'. It is not an error —
 * nothing threw, nothing is retryable — but recording it as 'done' is what made
 * this whole class of failure invisible: a support ticket that no staff member
 * could ever hear about was indistinguishable, in this collection, from one
 * delivered to fifty phones. `sendToTokens` has always reported `skipped` for an
 * empty token list; the dispatcher simply discarded that and wrote 'done'.
 *
 * `audienceSize` carries the actionable half, because the two causes look
 * identical here and have opposite remedies:
 *   audienceSize === 0  → nobody is targeted at all. Grant the module, or fix
 *                         the producer's audience field.
 *   audienceSize > 0    → the right people are targeted and not one of them has
 *                         the app installed. A staffing/rollout problem, not code.
 * null means the mark resolved tokens directly from a role/class query, so there
 * is no id list to count — zero tokens there means nobody in that group has a device.
 *
 * Query `pushRequests where status == 'undelivered'` to find silent failures.
 */
function markUndelivered(snap, audienceSize) {
  return snap.ref
    .set({
      status:      'undelivered',
      audienceSize: audienceSize == null ? null : Number(audienceSize),
      recipients:  0,
      fcmSuccess:  0,
      fcmFailure:  0,
      processedAt: new Date().toISOString(),
    }, { merge: true })
    .catch(() => {});
}

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

    const spec = MARK_REGISTRY[mark];
    if (!spec) {
      // Not a registered mark — ignore. (Legacy source-only docs, if any,
      // are drained by the retiring PHP poller until fully migrated.)
      return;
    }

    const schoolId = doc.schoolId || '';
    if (!schoolId) {
      logger.warn(`[${mark}] missing schoolId — dropping`, { id: snap.id });
      await markError(snap, 'missing schoolId');
      return;
    }

    try {
      // ── Resolve recipients ──
      // An explicit `userIds` list ALWAYS wins, whatever the mark's declared
      // mode — lets a producer (e.g. a scoped story) name exact recipients on
      // any mark. Otherwise fall back to the mark's audience mode.
      let tokens = [];
      // How many recipients the mark RESOLVED, before tokens. Distinguishes
      // "nobody targeted" from "targeted, but nobody has the app". See
      // markUndelivered. Stays null for role/class marks, which have no id list.
      let audienceIds = null;
      const explicitIds = Array.isArray(doc.userIds)
        ? [...new Set(doc.userIds.map((x) => String(x == null ? '' : x).trim()).filter(Boolean))]
        : [];
      if (explicitIds.length) {
        audienceIds = explicitIds.length;
        tokens = await tokensForUsers(schoolId, explicitIds);
        logger.info(`[${mark}] school=${schoolId} explicit userIds=${explicitIds.length} tokens=${tokens.length}`);
      } else if (spec.audience === AUDIENCE.BROADCAST) {
        // Class/section-scoped → that group's students' parents; else by role.
        const cs = classSectionTarget(doc.target_group);
        if (cs) {
          tokens = await tokensForParentsOfClassSection(schoolId, cs);
          logger.info(`[${mark}] school=${schoolId} target="${doc.target_group}" → ${cs.sectionKey ? 'section ' + cs.sectionKey : 'class ' + cs.className} → recipients=${tokens.length}`);
        } else {
          const roles = rolesForTarget(doc.target_group);
          tokens = await tokensForSchool(schoolId, roles);
          logger.info(`[${mark}] school=${schoolId} target="${doc.target_group}" → roles=${roles.join(',')} recipients=${tokens.length}`);
        }
      } else {
        // USERS: explicit recipient id(s) from the mark's idField + generic userIds.
        const ids = collectUserIds(doc, spec.idField);
        if (!ids.length) {
          logger.warn(`[${mark}] no recipients (idField=${spec.idField})`, { id: snap.id });
          await markError(snap, 'no recipients');
          return;
        }
        audienceIds = ids.length;
        tokens = await tokensForUsers(schoolId, ids);
        logger.info(`[${mark}] school=${schoolId} recipients=${ids.length} tokens=${tokens.length}`);
      }

      // ── Build notification + data and send ──
      const { notification, dataPayload } = buildPayload(spec, doc, schoolId);
      const result = await sendToTokens(tokens, notification, dataPayload);

      // sendToTokens returns { skipped: true } when the token list was empty.
      // That signal already existed and was thrown away here, so a push that
      // reached nobody was written as `done` — indistinguishable from success.
      if (result.skipped) {
        logger.warn(
          `[${mark}] UNDELIVERED — resolved audience=${audienceIds == null ? 'n/a' : audienceIds}, 0 registered devices`,
          { id: snap.id, schoolId }
        );
        await markUndelivered(snap, audienceIds);
        return;
      }

      await snap.ref.set({
        status: 'done',
        processedAt: new Date().toISOString(),
        recipients: tokens.length,
        fcmSuccess: result.successCount || 0,
        fcmFailure: result.failureCount || 0,
      }, { merge: true });
    } catch (err) {
      logger.error(`[${mark}] dispatch failed:`, err);
      await markError(snap, String(err.message || err));
    }
  }
);

// ─── Stories cleanup (Hardening #3) ────────────────────────────────
// onStoryDeleted + sweepExpiredStories — see ./storiesCleanup.js
const stories = require("./storiesCleanup");
exports.onStoryDeleted       = stories.onStoryDeleted;
exports.sweepExpiredStories  = stories.sweepExpiredStories;

// ─── Story posted → push fan-out (universal dispatcher, Phase 1) ────
// Fires on stories/{storyId} create (covers app- AND admin-created stories)
// and writes a STORY_POSTED pushRequests doc. See ./onStoryCreated.js.
const storyPush = require("./onStoryCreated");
exports.onStoryCreated = storyPush.onStoryCreated;

// ─── Stories engagement counters (SEC-4, 2026-07-08) ───────────────
// Server-authoritative viewCount / reactionCounts off the tenant-guarded
// viewers/reactions subcollections — clients never write the aggregates.
const storyCounters = require("./storyCounters");
exports.onStoryViewerCreated   = storyCounters.onStoryViewerCreated;
exports.onStoryReactionWritten = storyCounters.onStoryReactionWritten;

// ─── Stories audience index (SEC-1, 2026-07-08) ────────────────────
// Server-maintained per-parent entitlement doc consulted by the stories
// read rule — replaces the bypassable client-side audience filter.
const storyAudience = require("./storyAudience");
exports.refreshStoryAudience = storyAudience.refreshStoryAudience;
exports.onStudentClassChanged = storyAudience.onStudentClassChanged;

// Server-maintained per-staff capability doc (staffCapabilities/{uid}) consulted
// by the rules via get() to enforce per-module access on client writes. Mirrors
// the PHP resolver resolve_role_access(). See ./staffCapabilities.js.
const staffCaps = require("./staffCapabilities");
exports.refreshStaffCapabilities = staffCaps.refreshStaffCapabilities;
exports.onStaffRolesChanged      = staffCaps.onStaffRolesChanged;
exports.onSchoolRolesChanged     = staffCaps.onSchoolRolesChanged;

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


// ─── studentAssistant (ZenXii Student AI) ──────────────────────────
// Authenticated Gen-2 callable. Answers a student's questions about
// THEIR OWN records (attendance, homework, fees, timetable, results)
// and files helpdesk tickets. Identity is taken from the verified ID
// token and never from model output; every query is scoped by schoolId
// AND session, failing closed when currentSession is blank. Gated per
// school by schools/{id}.ai_assistant_enabled. Needs the
// ANTHROPIC_API_KEY secret. See ./studentAssistant.js.
const studentAssistant = require("./studentAssistant");
exports.studentAssistant = studentAssistant.studentAssistant;

// ─────────────────────────────────────────────────────────────────────
//  Support Desk (P5) — parent→school ticketing
//
//  ticketNo assignment, the reopen transition (which is what lets the
//  parent have `allow update: if false` on supportTickets), the openCount
//  the rules cap reads, and the stale-resolved sweep.
// ─────────────────────────────────────────────────────────────────────
const supportDesk = require('./supportDesk');
exports.onSupportTicketCreated       = supportDesk.onSupportTicketCreated;
exports.onSupportMessageCreated      = supportDesk.onSupportMessageCreated;
exports.onSupportTicketStatusChanged = supportDesk.onSupportTicketStatusChanged;
exports.closeStaleTickets            = supportDesk.closeStaleTickets;
