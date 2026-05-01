/**
 * Stories cleanup — Hardening #3.
 *
 * Two cooperating triggers keep the `stories` collection AND its
 * Firebase Storage media in lockstep:
 *
 *  1. onStoryDeleted  — fires every time a story doc is deleted
 *                       (admin moderation hard-delete OR Firestore TTL
 *                       sweep). Deletes the Storage object referenced
 *                       by the doc's mediaUrl + drops the
 *                       `stories/{id}/viewers` subcollection.
 *
 *  2. sweepExpiredStories — scheduled every 2h. Belt-and-suspenders
 *                       in case Firestore TTL is delayed (TTL has no
 *                       SLA tighter than ~24h). Deletes any expired
 *                       story doc itself; the onStoryDeleted trigger
 *                       above then cleans up the Storage media.
 *
 * The sweep + the trigger MUST coexist — TTL alone leaves Storage
 * orphans; the trigger alone wouldn't fire if TTL is disabled.
 */

const admin = require('firebase-admin');
const { onDocumentDeleted } = require('firebase-functions/v2/firestore');
const { onSchedule } = require('firebase-functions/v2/scheduler');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();

// ─── Helpers ──────────────────────────────────────────────────────

/**
 * Extract the Storage object path from a Firebase download URL.
 * Input:  https://firebasestorage.googleapis.com/v0/b/BUCKET/o/ENCODED?alt=media&token=...
 * Output: ENCODED URL-decoded (e.g. stories/SCH.../T.../123.jpg)
 */
function extractStoragePath(url) {
  if (!url || typeof url !== 'string') return null;
  const m = url.match(/\/o\/([^?]+)/);
  if (!m) return null;
  try { return decodeURIComponent(m[1]); } catch { return null; }
}

/**
 * Best-effort Storage delete. Logs but does NOT throw — orphan files
 * are recoverable via the bucket lifecycle rule, but a thrown
 * exception would fail the whole cleanup batch.
 */
async function deleteStorageMedia(storagePath) {
  if (!storagePath) return false;
  try {
    await admin.storage().bucket().file(storagePath).delete({ ignoreNotFound: true });
    return true;
  } catch (e) {
    logger.warn(`storage delete failed for ${storagePath}: ${e.message}`);
    return false;
  }
}

/**
 * Wipe the per-user `viewers` subcollection of a deleted story.
 * Batched in 400-doc chunks to stay under the 500 writes-per-batch
 * Firestore limit.
 */
async function deleteViewersSubcollection(storyId) {
  const subRef = db.collection('stories').doc(storyId).collection('viewers');
  let total = 0;
  while (true) {
    const snap = await subRef.limit(400).get();
    if (snap.empty) break;
    const batch = db.batch();
    snap.docs.forEach((d) => batch.delete(d.ref));
    await batch.commit();
    total += snap.size;
    if (snap.size < 400) break;
  }
  return total;
}

// ─── Trigger 1: onStoryDeleted ────────────────────────────────────

/**
 * Fires once per story doc deletion (regardless of WHO deleted it —
 * admin hard-delete, Firestore TTL, or this CF's own sweep). The
 * deleted-doc snapshot still has the mediaUrl, so we can derive the
 * Storage path and clean up the media + viewers in one shot.
 */
exports.onStoryDeleted = onDocumentDeleted('stories/{storyId}', async (event) => {
  const storyId = event.params.storyId;
  const data = event.data?.data() || {};
  const mediaUrl = data.mediaUrl || '';
  const path = extractStoragePath(mediaUrl);

  const [mediaDeleted, viewersDeleted] = await Promise.all([
    deleteStorageMedia(path),
    deleteViewersSubcollection(storyId).catch((e) => {
      logger.warn(`viewers cleanup failed for ${storyId}: ${e.message}`);
      return 0;
    }),
  ]);

  logger.info(
    `[story-cleanup] ${storyId} → storage=${mediaDeleted ? 'ok' : 'skip'}, ` +
    `viewers=${viewersDeleted}`
  );
});

// ─── Trigger 2: scheduled expiry sweep ────────────────────────────

/**
 * Every 2 hours, hard-delete any story whose expiresAtTs is past.
 * Each delete fires onStoryDeleted above which handles Storage +
 * viewers cleanup, so this function only deals with the doc itself.
 *
 * Batched in 400-doc chunks; loops until the result set is empty.
 * Worst case — admin hard-deletes a backlog of 10,000 expired docs
 * (legacy cleanup) — runs in under 30s.
 */
exports.sweepExpiredStories = onSchedule(
  {
    schedule: 'every 2 hours',
    timeZone: 'UTC',
    timeoutSeconds: 540,
    memory: '512MiB',
  },
  async () => {
    const nowTs = admin.firestore.Timestamp.now();
    let totalDeleted = 0;

    while (true) {
      // Query for expired docs. expiresAtTs is the canonical Timestamp
      // field; legacy expiresAt (Long) docs are caught by a separate
      // millis-comparison query below.
      const snap = await db.collection('stories')
        .where('expiresAtTs', '<', nowTs)
        .limit(400).get();
      if (snap.empty) break;
      const batch = db.batch();
      snap.docs.forEach((d) => batch.delete(d.ref));
      await batch.commit();
      totalDeleted += snap.size;
      if (snap.size < 400) break;
    }

    // Fallback sweep for pre-v1.9 docs that only have the Long
    // `expiresAt` field. Drop this branch once legacy data has aged
    // out (one full retention cycle past v1.9 cutover).
    const nowMs = Date.now();
    while (true) {
      const snap = await db.collection('stories')
        .where('expiresAt', '<', nowMs)
        .limit(400).get();
      if (snap.empty) break;
      // Filter out docs that ALSO have expiresAtTs (already covered above).
      const filtered = snap.docs.filter((d) => !d.data().expiresAtTs);
      if (filtered.length === 0) break;
      const batch = db.batch();
      filtered.forEach((d) => batch.delete(d.ref));
      await batch.commit();
      totalDeleted += filtered.length;
      if (snap.size < 400) break;
    }

    logger.info(`[story-sweep] deleted ${totalDeleted} expired stories`);
  }
);
