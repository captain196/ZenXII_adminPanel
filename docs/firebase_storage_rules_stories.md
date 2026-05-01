# Firebase Storage rules + Firestore TTL for Stories

Rules/policies required for the Stories feature (Phases A–D) to work
end-to-end in production.

---

## 1. Storage rules (updated for admin upload)

Path layouts:

```
TEACHER:  gs://<bucket>/stories/{schoolId}/{teacherId}/{epochMillis}.{ext}
ADMIN:    gs://<bucket>/stories/admin/{schoolId}/{adminId}/{epochMillis}.{ext}
```

Paste in Firebase Console → Storage → Rules → Publish:

```
rules_version = '2';
service firebase.storage {
  match /b/{bucket}/o {

    // ── Teacher uploads ──────────────────────────────────────────
    // Teacher may upload ONLY to paths prefixed with their own UID.
    match /stories/{schoolId}/{teacherId}/{file} {
      allow read: if request.auth != null;
      allow write: if request.auth != null
                   && request.auth.uid == teacherId
                   && (
                        (request.resource.contentType.matches('image/.*')
                         && request.resource.size < 10 * 1024 * 1024)
                        ||
                        (request.resource.contentType.matches('video/.*')
                         && request.resource.size < 50 * 1024 * 1024)
                      );
      allow delete: if request.auth != null && request.auth.uid == teacherId;
    }

    // ── Admin uploads ────────────────────────────────────────────
    // Admin panel uploads through the PHP backend which uses the
    // Admin SDK (bypasses these rules). This match block restricts
    // any client-side writes to this path to admin custom-claim holders.
    match /stories/admin/{schoolId}/{adminId}/{file} {
      allow read: if request.auth != null;
      // Admin SDK writes (PHP backend) bypass these rules; client-side
      // writes are blocked. Delete also admin-only (SDK bypass).
      allow write: if false;
      allow delete: if false;
    }

    // Default deny everything else.
    match /{allPaths=**} {
      allow read, write: if false;
    }
  }
}
```

---

## 2. Firestore TTL (option A — recommended)

Automatically deletes story docs 24 h after creation (technically
when `expiresAt` is past), freeing space without any cron job.

### Setup (3 clicks):

1. Firebase Console → **Firestore Database** → **TTL** tab
2. Click **"Create policy"**
3. Collection: `stories`,  Timestamp field: **`expiresAtTs`**
4. Status → **Enable**

Firestore will now delete any `stories/{id}` doc whose `expiresAtTs`
timestamp has passed. The sweep runs approximately every 24 h, so
expired stories may linger for up to ~24 h before physical deletion
— the client-side `whereGreaterThan('expiresAt', now)` filter keeps
them hidden in the interim.

### Two-field design (why):

- **`expiresAt: Long`** — epoch millis, used by client snapshot
  listeners (`whereGreaterThan('expiresAt', now)`). Simple, fast,
  works across Kotlin/Firestore deserialisation without type
  ambiguity.
- **`expiresAtTs: Timestamp`** — set from the same millis value at
  write time. Only read by Firestore TTL policy; never by app code.

Both the teacher app repo (`StoryFirestoreRepository.uploadStory`)
and the admin upload endpoint (`Stories::upload_story`) write both
fields atomically so they can never diverge.

---

## 3. Option B — Scheduled Cloud Function (if TTL not available)

Use this only if your Blaze plan doesn't allow TTL, or you need
custom logic (e.g. also delete Storage media, not just docs).

`functions/src/cleanupExpiredStories.ts`:

```ts
import * as functions from 'firebase-functions';
import * as admin from 'firebase-admin';
admin.initializeApp();

/**
 * Scheduled: delete expired stories + their Storage media.
 * Runs every 2 hours. Batched in 400-doc chunks to stay under
 * the 500 writes-per-batch Firestore limit.
 */
export const cleanupExpiredStories = functions.pubsub
  .schedule('every 2 hours')
  .timeZone('UTC')
  .onRun(async () => {
    const db = admin.firestore();
    const bucket = admin.storage().bucket();
    const now = Date.now();

    const snap = await db.collection('stories')
      .where('expiresAt', '<', now)
      .limit(400).get();

    if (snap.empty) {
      console.log('cleanupExpiredStories: 0 expired docs');
      return null;
    }

    const batch = db.batch();
    const storageDeletes: Promise<any>[] = [];
    for (const doc of snap.docs) {
      batch.delete(doc.ref);
      // Also wipe the subcollection `viewers` docs.
      const viewers = await doc.ref.collection('viewers').listDocuments();
      viewers.forEach(v => batch.delete(v));
      // And the Storage media, best-effort.
      const url = doc.get('mediaUrl') as string | undefined;
      const path = url ? extractStoragePath(url) : null;
      if (path) {
        storageDeletes.push(
          bucket.file(path).delete({ ignoreNotFound: true })
            .catch(e => console.warn('storage delete fail', path, e.message))
        );
      }
    }

    await batch.commit();
    await Promise.all(storageDeletes);
    console.log(`cleanupExpiredStories: deleted ${snap.size} expired stories`);
    return null;
  });

// Extract the gs://bucket/path substring from a Firebase download URL.
// Input:  https://firebasestorage.googleapis.com/v0/b/BUCKET/o/ENCODED?alt=media&token=...
// Output: ENCODED URL-decoded (e.g. stories/SCH.../T.../123.jpg)
function extractStoragePath(url: string): string | null {
  const m = url.match(/\/o\/([^?]+)/);
  if (!m) return null;
  try { return decodeURIComponent(m[1]); } catch { return null; }
}
```

Deploy:

```
firebase deploy --only functions:cleanupExpiredStories
```

---

## 4. Storage lifecycle rule (media-only cleanup, fallback)

If you enable Firestore TTL but can't deploy a Cloud Function for
media cleanup, add a bucket lifecycle rule to auto-delete Storage
files older than 48 h (24 h expiry + 24 h retention buffer):

Firebase Console → Storage → Rules/Settings → Lifecycle:

```json
{
  "rule": [
    {
      "action": { "type": "Delete" },
      "condition": {
        "age": 2,
        "matchesPrefix": ["stories/"]
      }
    }
  ]
}
```

This deletes any file in `stories/` older than 2 days.

---

## Validation (all 3 systems)

| Layer | Size cap (image) | Size cap (video) | MIME check | Auth check |
|---|---|---|---|---|
| **Teacher client** (StoryMediaUploader.validate) | 10 MB | 50 MB | ✅ image/* or video/* | N/A (in-process) |
| **Admin client** (index.php submitUpload) | 10 MB | 50 MB | N/A (browser picker) | CSRF token |
| **Admin backend** (Stories.php upload_story) | 10 MB | 50 MB | ✅ regex match | Role gate (MODERATE_ROLES) |
| **Storage rules** | 10 MB | 50 MB | ✅ contentType matches | Firebase Auth UID |

Four independent layers — the first three are UX safety; Storage
rules are the only security boundary that matters server-side.

---

## Testing checklist

- [ ] Teacher app: pick image → Storage upload → story appears in parent app within 100 ms
- [ ] Admin panel: open Stories → "Post Admin Story" → upload image with priority=high → red/gold ring appears in parent app row, pinned to top
- [ ] Parent app: tap a story → `viewCount` bumps by exactly 1
- [ ] Parent app: tap the SAME story again → viewCount does NOT bump (transaction gates)
- [ ] Two parents both view → viewCount bumps by 2 (different viewer docs)
- [ ] Admin flags a story → parent app row disappears within 100 ms
- [ ] Wait 24 h → story auto-hides client-side
- [ ] Wait 48 h → Firestore TTL sweep removes the doc, Storage lifecycle removes the media
