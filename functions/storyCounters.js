/**
 * Story engagement counters — SEC-4 hardening (2026-07-08).
 *
 * viewCount and reactionCounts on a story doc are now maintained
 * EXCLUSIVELY server-side, off the tenant-guarded per-user subcollection
 * writes. Clients (parent + teacher apps) write ONLY their own
 * `viewers/{uid}` / `reactions/{uid}` marker doc; they never touch the
 * aggregate counters. The Firestore rule forbids any non-staff story
 * update and no client writes viewCount/reactionCounts at all, so the
 * counters cannot be forged (a parent could previously set
 * reactionCounts to any value and loop viewCount).
 *
 * Two triggers:
 *   1. onStoryViewerCreated  — +1 viewCount per UNIQUE viewer. Fires only
 *      on the first write of a viewers/{uid} doc; the app's markAsViewed
 *      transaction is idempotent (one doc per user), and a later name
 *      backfill is an update (does NOT refire onCreated), so viewCount
 *      increments exactly once per user. No decrement on viewer delete —
 *      views are monotonic, and story deletion drops the whole doc.
 *   2. onStoryReactionWritten — toggle-safe reactionCounts delta from the
 *      before/after emoji (create → +new, change → -old +new, delete → -old).
 *
 * Both updates use .update() (not set/merge) so a race with story deletion
 * throws "no document to update" and is swallowed rather than resurrecting
 * a deleted story as a counter-only stub.
 */

const admin = require('firebase-admin');
const { onDocumentCreated, onDocumentWritten } = require('firebase-functions/v2/firestore');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();
const FieldValue = admin.firestore.FieldValue;
const FieldPath = admin.firestore.FieldPath;
const REGION = 'us-central1';

// ─── Trigger 1: viewCount ──────────────────────────────────────────
exports.onStoryViewerCreated = onDocumentCreated(
  { document: 'stories/{storyId}/viewers/{uid}', region: REGION },
  async (event) => {
    const storyId = event.params.storyId;
    try {
      await db.collection('stories').doc(storyId)
        .update({ viewCount: FieldValue.increment(1) });
    } catch (e) {
      // Story deleted between the viewer write and this trigger — ignore.
      logger.warn(`[story-counter] viewCount +1 skipped for ${storyId}: ${e.message}`);
    }
  }
);

// ─── Trigger 2: reactionCounts ─────────────────────────────────────
exports.onStoryReactionWritten = onDocumentWritten(
  { document: 'stories/{storyId}/reactions/{uid}', region: REGION },
  async (event) => {
    const storyId = event.params.storyId;
    const before = event.data && event.data.before && event.data.before.exists ? event.data.before.data() : null;
    const after  = event.data && event.data.after  && event.data.after.exists  ? event.data.after.data()  : null;

    const prevEmoji = before ? String(before.emoji || '') : '';
    const nextEmoji = after  ? String(after.emoji  || '') : '';
    if (prevEmoji === nextEmoji) return; // no net change (e.g. name backfill)

    // Build alternating (FieldPath, delta) args so emoji map keys with
    // special characters (❤️) are never mis-parsed as dot-path segments.
    const args = [];
    if (prevEmoji) args.push(new FieldPath('reactionCounts', prevEmoji), FieldValue.increment(-1));
    if (nextEmoji) args.push(new FieldPath('reactionCounts', nextEmoji), FieldValue.increment(1));
    if (args.length === 0) return;

    try {
      await db.collection('stories').doc(storyId).update(...args);
    } catch (e) {
      logger.warn(`[story-counter] reactionCounts skipped for ${storyId}: ${e.message}`);
    }
  }
);
