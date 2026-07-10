/**
 * Story posted → push fan-out (universal dispatcher, Phase 1).
 *
 * Stories are created DIRECTLY in Firestore by the Teacher/Parent apps AND by
 * the admin panel (Stories::upload_story). A PHP-only emit would miss every
 * app-created story, so this fires on the `stories/{storyId}` create event and
 * writes ONE standardized `pushRequests` doc (mark STORY_POSTED) that the
 * universal dispatcher (index.js) delivers.
 *
 * Audience:
 *   audienceClassKeys == ["*"]  → whole school → target_group "All Parents".
 *   else (canonical "8-a" keys) → resolve the exact parents via the
 *   server-maintained storyAudience entitlement index (same index the read
 *   rule uses) and emit an explicit userIds list. Explicit userIds override
 *   the mark's broadcast mode in the dispatcher.
 */

const admin = require('firebase-admin');
const { onDocumentCreated } = require('firebase-functions/v2/firestore');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();
const REGION = 'us-central1';

// Resolve parent recipient uids (== studentIds) whose entitlement classKeys
// intersect the story's audience keys, via the storyAudience index.
// array-contains-any takes ≤10 values, so chunk the keys.
async function parentsForClassKeys(schoolId, keys) {
  const uids = new Set();
  for (let i = 0; i < keys.length; i += 10) {
    const chunk = keys.slice(i, i + 10);
    const snap = await db.collection('storyAudience')
      .where('schoolId', '==', schoolId)
      .where('classKeys', 'array-contains-any', chunk)
      .get();
    snap.forEach((d) => { if (d.id) uids.add(d.id); });
  }
  return [...uids];
}

exports.onStoryCreated = onDocumentCreated(
  { document: 'stories/{storyId}', region: REGION },
  async (event) => {
    const snap = event.data;
    if (!snap) return;
    const doc = snap.data() || {};

    const schoolId = String(doc.schoolId || '');
    if (!schoolId) {
      logger.warn(`[onStoryCreated] missing schoolId on ${snap.id}`);
      return;
    }
    // Only notify for live stories.
    if (String(doc.status || 'active').toLowerCase() !== 'active') return;

    const storyId = String(doc.storyId || snap.id);
    const author = String(doc.authorName || doc.teacherName || 'School').slice(0, 60);
    const caption = String(doc.caption || '').replace(/\s+/g, ' ').trim().slice(0, 140);
    const title = 'New Story';
    const body = caption ? `${author}: ${caption}` : `${author} posted a new story`;

    const keys = Array.isArray(doc.audienceClassKeys) ? doc.audienceClassKeys.map(String) : [];
    const wholeSchool = keys.length === 0 || keys.includes('*');

    const base = {
      schoolId,
      mark: 'STORY_POSTED',
      source: 'story_posted',
      status: 'pending',
      storyId,
      title,
      body,
      markedBy: 'cf:onStoryCreated',
      createdAt: new Date().toISOString(),
    };

    try {
      let payload;
      if (wholeSchool) {
        payload = { ...base, target_group: 'All Parents' };
      } else {
        const userIds = await parentsForClassKeys(schoolId, keys);
        if (!userIds.length) {
          logger.info(`[onStoryCreated] ${storyId} scoped=[${keys.join(',')}] → 0 entitled parents, skipping`);
          return;
        }
        payload = { ...base, userIds };
      }
      // Deterministic docId → idempotent if the create event redelivers.
      await db.collection('pushRequests').doc(`${schoolId}_story_${storyId}`).set(payload);
      logger.info(`[onStoryCreated] ${storyId} school=${schoolId} ${wholeSchool ? 'whole-school' : 'scoped [' + keys.join(',') + ']'} → pushRequests written`);
    } catch (err) {
      logger.error(`[onStoryCreated] failed for ${storyId}:`, err);
    }
  }
);
