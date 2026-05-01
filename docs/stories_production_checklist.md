# Stories — Production Hardening Checklist

After Phases A → D + Hardening rounds. Walk through this list before
shipping to a real school.

---

## 1. One-time Firebase Console setup

These are server-side configurations, not code changes. They must be
applied for hardened behaviour to take effect.

### a) Firestore TTL policy

1. Firebase Console → **Firestore Database** → **TTL** tab → **Create policy**
2. Collection: `stories`
3. Timestamp field: **`expiresAtTs`** (canonical Timestamp; targets the
   field both the teacher Kotlin writer AND the admin PHP writer
   produce as a real `timestampValue`)
4. Status → **Enabled**

Firestore will physically delete expired story docs. Each delete
fires the `onStoryDeleted` Cloud Function which removes the
associated Storage media + viewer subcollection.

### b) Storage rules

Paste the rules from `firebase_storage_rules_stories.md` into
Firebase Console → **Storage** → **Rules** → **Publish**.

Two paths covered:
- `stories/{schoolId}/{teacherId}/...` — teacher writes only with
  `auth.uid == teacherId`
- `stories/admin/{schoolId}/{adminId}/...` — admin SDK only (PHP
  backend bypasses rules)

### c) Cloud Functions deployment

```
cd C:/xampp/htdocs/Grader/school/functions
firebase deploy --only functions:onStoryDeleted,functions:sweepExpiredStories
```

The `onStoryDeleted` trigger fires on every story doc delete. The
`sweepExpiredStories` schedule runs every 2 h and is the safety net
in case TTL is delayed or disabled.

### d) Storage lifecycle (bucket-wide fallback)

In addition to (c), set a Storage lifecycle rule to delete
`stories/**` files older than 2 days. This catches any orphan that
slips past both the trigger and the sweep:

Firebase Console → Storage → bucket → Lifecycle → Add rule:
- Action: Delete
- Condition: Age = 2 days, Object name prefix = `stories/`

---

## 2. Cross-system schema lock

These constants are **mirrored** across three files. If one moves
the others MUST move in lockstep — silent validation drift otherwise.

| Constant | Teacher Kotlin | Parent Kotlin | Admin PHP |
|---|---|---|---|
| `MAX_CAPTION_LENGTH = 500` | StorySharedConfig.kt | StorySharedConfig.kt | Stories.php |
| `MAX_IMAGE_BYTES = 10MB` | StorySharedConfig.kt | (read-only, n/a) | Stories.php |
| `MAX_VIDEO_BYTES = 50MB` | StorySharedConfig.kt | n/a | Stories.php |
| `EXPIRY_MILLIS = 24h` | StorySharedConfig.kt | StorySharedConfig.kt | DEFAULT_EXPIRY_HOURS |
| `TEACHER_DAILY_LIMIT = 5` | StorySharedConfig.kt | n/a | Stories.php |
| `ADMIN_DAILY_LIMIT = 10` | n/a | n/a | Stories.php |
| Allowed types | StorySharedConfig.ALLOWED_TYPES | n/a | Stories.php ALLOWED_TYPES |
| Allowed priorities | StorySharedConfig.ALLOWED_PRIORITIES | n/a | Stories.php ALLOWED_PRIORITIES |

To change any cap: update **all three files** + (if size cap
changes) the Storage rules contentType-size check.

---

## 3. Schema deprecation timeline

| Field | Status | Removal release |
|---|---|---|
| `teacherId` | @Deprecated, dual-written | v2.0 |
| `teacherName` | @Deprecated, dual-written | v2.0 |
| `teacherPic` | @Deprecated, dual-written | v2.0 |
| `expiresAt` (Long) | @Deprecated, dual-written for legacy readers | v2.0 |
| `expiresAtTs` (Timestamp) | Canonical (since v1.9) | — |

**v2.0 cleanup PR will:**
1. Remove `@Deprecated` legacy fields from both `StoryDoc.kt`
2. Remove the legacy field writes from `StoryFirestoreRepository.uploadStory` (teacher) and `Stories.php upload_story` (admin)
3. Switch `observeMyStories` query from `whereEqualTo("teacherId", ...)` to `whereEqualTo("authorId", ...)`
4. Drop the legacy fallback branch in `sweepExpiredStories`
5. Drop `effectiveAuthor*` helpers; readers use `authorX` directly

Wait at least **one full retention cycle** (24h after v1.9 cutover)
before deploying v2.0 — gives every legacy doc time to expire and
TTL/sweep time to remove it.

---

## 4. Pre-deploy verification (run on a staging school)

### Teacher upload
- [ ] Pick image → progress 0–100% → "Media ready" chip → tap Upload → story appears in own grid + parent dashboard within 100ms
- [ ] Try posting a 6th story while 5 are still active → rejected with `Daily limit reached (5/day)…`
- [ ] Disable network mid-upload (force Firestore write to fail) → check Storage console: orphan file is auto-deleted
- [ ] Pick a video > 50 MB → rejected client-side with size error

### Admin upload
- [ ] `/stories` → "Post Admin Story" → upload image with priority=high → red/gold pinned ring on parent app
- [ ] Try posting an 11th admin story while 10 are active → rejected
- [ ] Pick a corrupted file with wrong MIME → rejected with `Uploaded file is not an image (got X)`

### Parent
- [ ] Tap a story → viewCount bumps by 1
- [ ] Tap the SAME story 5 more times → viewCount STAYS 1 (transactional gate)
- [ ] Wait 24h → story disappears from row
- [ ] After 24h+ → check Firestore + Storage: doc gone, media gone

### Admin moderation
- [ ] Flag a story → disappears from parent app row within 100ms
- [ ] Hard-delete a story → onStoryDeleted CF logs `storage=ok, viewers=N`
- [ ] Bulk-flag 5 stories → all disappear in batch

### Cross-app propagation
- [ ] Teacher uploads on device A → parent on device B sees within 100ms
- [ ] Admin uploads with `priority=high` → moves to row top on every parent device

---

## 5. Telemetry signals to watch

Once live, monitor:

- **Firestore reads/sec on `stories`** — should track active student count × short polling cadence (snapshot listener is efficient; spike = something wrong)
- **Storage egress on `stories/**`** — proportional to story view volume; should plateau ~24h after a busy posting day
- **Cloud Function failures** — `onStoryDeleted` and `sweepExpiredStories` invocation graphs in Firebase Console; failures here mean orphan media accumulating
- **Storage object count under `stories/`** — should stay roughly flat day-to-day. If growing monotonically, the cleanup chain is broken.
- **`viewCount` outliers** — any story with viewCount > active student count means the transaction gate isn't holding (shouldn't happen)

---

## 6. Rollback plan

If something goes badly wrong post-deploy:

1. **Revert app builds** — uninstall + sideload previous APK from CI
2. **Disable Cloud Functions** — `firebase functions:delete sweepExpiredStories` to stop the sweep without redeploying app
3. **Stop admin uploads** — comment-out the `Post Admin Story` button in `views/stories/index.php`; teachers continue posting normally
4. **Disable rate limit** — set `TEACHER_DAILY_LIMIT = 9999` in StorySharedConfig.kt + push hot-fix; admin via `Stories.php` constant

The `expiresAtTs` field write is forward-compatible — old app builds
that read `expiresAt` (Long) keep working because we still dual-emit.

---

## 7. Files changed in this hardening pass

| Layer | File | Purpose |
|---|---|---|
| Schema | `teacher/.../StoryDoc.kt` | Canonical Timestamp expiry + @Deprecated legacy |
| Schema | `parent/.../StoryDoc.kt` | Mirror of canonical schema |
| Constants | `teacher/.../StorySharedConfig.kt` | NEW — single source of truth |
| Constants | `parent/.../StorySharedConfig.kt` | NEW — mirror |
| Repo (teacher) | `teacher/.../StoryFirestoreRepository.kt` | Rate limit, expiresAtTs writes, listener queries Timestamp |
| Repo (parent) | `parent/.../StoryFirestoreRepository.kt` | Listener queries Timestamp; transactional viewCount (Phase D) |
| Util | `teacher/.../StoryMediaUploader.kt` | Returns storagePath for rollback; deleteByPath() helper |
| VM | `teacher/.../StoriesTeacherViewModel.kt` | Tracks storagePath; rolls back on Firestore failure |
| Backend | `application/controllers/Stories.php` | Rate limit, ALLOWED_TYPES/PRIORITIES constants, Timestamp expiry write |
| REST client | `application/libraries/Firestore_rest_client.php` | New `timestamp()` helper + DateTimeInterface encoding |
| Cloud Function | `functions/storiesCleanup.js` | NEW — onStoryDeleted + sweepExpiredStories |
| Cloud Function | `functions/index.js` | Re-export above |
| Docs | `docs/firebase_storage_rules_stories.md` | Updated with admin path + TTL field name |
| Docs | `docs/stories_production_checklist.md` | THIS file |
