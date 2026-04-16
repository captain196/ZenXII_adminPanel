/**
 * SchoolSync Notice / Circular push dispatcher.
 *
 * Triggered on every new `pushRequests/{docId}` doc. Handles the two mark
 * types added by Communication.php + Hr.php:
 *   - NOTICE_CREATED    (source=notice_created, noticeId=<NOTxxxx>)
 *   - CIRCULAR_CREATED  (source=circular_created, circularId=<CIRxxxx>)
 *
 * Does NOT overlap with existing homework / attendance CFs because those
 * use different mark values. Deployed alongside — safe to merge.
 *
 * Fan-out strategy:
 *   target_group → audience roles → userDevices query → fcmToken list → FCM multicast.
 */

const admin = require('firebase-admin');
const { onDocumentCreated } = require('firebase-functions/v2/firestore');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();
const messaging = admin.messaging();

const MARKS_HANDLED = new Set(['NOTICE_CREATED', 'CIRCULAR_CREATED', 'MESSAGE_RECEIVED']);

/**
 * Resolve target_group → list of appRole(s) to query.
 * Default is both (All School). Customise if finer-grained targeting is needed.
 */
function rolesForTarget(targetGroup) {
  const t = String(targetGroup || 'All School').trim().toLowerCase();
  if (t === 'all teachers' || t === 'teachers' || t === 'all staff' || t === 'staff') return ['teacher'];
  if (t === 'all parents' || t === 'parents' || t === 'all students' || t === 'students') return ['parent'];
  // Class/section-targeted (e.g. "Class 10th|Section A") — ship to parents only.
  // Teachers assigned to that section get their own Teacher app list — out of
  // scope for this Cloud Function; can be extended later via staff-assignment
  // lookup if needed.
  if (t.includes('class ')) return ['parent'];
  return ['teacher', 'parent']; // "All School" and unknowns
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
    } else {
      const isNotice = mark === 'NOTICE_CREATED';
      const typeKey  = isNotice ? 'notice_created' : 'circular_created';
      const idKey    = isNotice ? 'noticeId' : 'circularId';
      const resourceId = String(doc[idKey] || doc.source_id || '');

      const title = String(doc.title || (isNotice ? 'New Notice' : 'New Circular')).slice(0, 120);
      const body  = String(doc.body  || (isNotice ? 'A new notice has been posted' : 'A new circular has been posted')).slice(0, 240);

      const roles = rolesForTarget(doc.target_group);
      logger.info(`[${mark}] school=${schoolId} target="${doc.target_group}" → roles=${roles.join(',')} resource=${resourceId}`);
      tokens = await tokensForSchool(schoolId, roles);
      logger.info(`[${mark}] fcm recipients: ${tokens.length}`);
      notification = { title, body };
      dataPayload = {
        type: typeKey,
        [idKey]: resourceId,
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
