# Stream Configuration — Production Root-Cause Analysis

**Module:** Academic Setup → Stream Configuration (School Config)
**Symptom:** Clicking **Save All Streams** fails every stream with HTTP 409
> "Another administrator updated school configuration during your request. Please reload the page and retry."
…even though **only one administrator is logged in**.

**Verdict:** This is a **false-positive optimistic-concurrency rejection**. It is **NOT** a genuine concurrent edit, not a Firestore fault, not a version-token *format* problem, and not a client-side stale-state problem. It is a **stale cross-request server cache**: batch commits (`commitBatch`) do **not** invalidate the whitelisted `schools` config-doc cache, so after the first save advances the document's `updateTime`, every subsequent save reads a **stale `__updateTime`** and its CAS precondition is rejected by Firestore.

---

## 1. Execution flow (Browser → Firestore → response)

| # | Layer | File / symbol | What happens |
|---|-------|---------------|--------------|
| 1 | Browser | [index.php `saveAllStreams()`](application/views/school_config/index.php#L3468-L3518) | Iterates `CFG.streams` and calls `save_stream` **once per stream, sequentially** (`saveNext(i)` recursion). Each is a **separate AJAX request**. |
| 2 | Controller | [School_config.php `save_stream()`](application/controllers/School_config.php#L2500-L2599) | Reads the school doc, captures `__updateTime`, writes the merged `streams` map back under a CAS precondition. |
| 3 | Read (token) | [School_config.php:2543](application/controllers/School_config.php#L2543) `$this->firebase->firestoreGet('schools', $schoolDocId)` → [Firebase.php:1137](application/libraries/Firebase.php#L1137) → [Firestore_rest_client.php `getDocument()`:598](application/libraries/Firestore_rest_client.php#L598) | Read passes through the **cross-request config-doc cache**. |
| 4 | Token capture | [School_config.php:2553](application/controllers/School_config.php#L2553) `$updateTime = (string)($fsSchool['__updateTime'] ?? '')` | The version token comes from **whatever `getDocument` returned — cached or live**. |
| 5 | Write (CAS) | [School_config.php:2570-2575](application/controllers/School_config.php#L2570-L2575) sets `precondition = ['updateTime' => $updateTime]`, commits via `firestoreCommitBatch` → [Firebase.php:1243](application/libraries/Firebase.php#L1243) → [Firestore_rest_client.php `commitBatch()`:953](application/libraries/Firestore_rest_client.php#L953) | The precondition is sent to Firestore as `currentDocument.updateTime`. |
| 6 | CAS compare | **Firestore server** (`documents:commit`) | Firestore compares the supplied `updateTime` against the document's *live* `updateTime`. Mismatch ⇒ `FAILED_PRECONDITION` (HTTP 400). |
| 7 | Response | [School_config.php:2581-2588](application/controllers/School_config.php#L2581-L2588) | `!$committed` ⇒ returns 409 with the "Another administrator…" message. |

**Answers to the required questions:**
1. **Controller:** `School_config::save_stream()` (Save All calls it N times).
2. **Service performing the save:** `Firebase::firestoreCommitBatch()` → `Firestore_rest_client::commitBatch()`.
3. **Where the version token is read:** `Firestore_rest_client::getDocument()` (via `firestoreGet`), surfaced as `__updateTime` by `decodeDocument()` ([Firestore_rest_client.php:581-587](application/libraries/Firestore_rest_client.php#L581-L587)).
4. **Where the token is compared:** **On the Firestore server**, against `currentDocument.updateTime` sent by `commitBatch()`.
5. **Which value is stale:** The **`__updateTime` read from the cross-request cache** — it reflects the document *before* the first save, not after.
6. **Where the failure is generated:** Firestore returns `FAILED_PRECONDITION`; the application maps it to the 409 at [School_config.php:2585](application/controllers/School_config.php#L2585).
7. **Genuinely from Firestore?** The *rejection* is genuinely from Firestore (the CAS really failed). But the *cause* is application-side: the token supplied was stale because of the cache.
8. **Application-side bug:** **Yes** — the stale token originates in `commitBatch()` failing to invalidate the config-doc cache.

---

## 2. The defect (asymmetric cache invalidation)

### 2a. `schools` is a cross-request cached "stable config" doc (TTL 300 s)
[Firestore_rest_client.php:51-57](application/libraries/Firestore_rest_client.php#L51-L57)
```php
private const CONFIG_DOC_TTL = [
    'schools'           => 300,   // 5 min — changes rarely; writes bust it immediately
    'schoolControl'     => 300,
    ...
];
```
`getDocument()` serves `schools/{id}` from a **file-based cache** (`application/cache/fsdoc/*.cache`) on hit ([Firestore_rest_client.php:612-620](application/libraries/Firestore_rest_client.php#L612-L620)). The cached value is the **entire decoded document, including `__updateTime`** ([getDocument:627-631](application/libraries/Firestore_rest_client.php#L627-L631)).

**Live evidence (this machine, `application/cache/fsdoc/`):** a live cache entry for a `schools` doc contains
```
__updateTime = 2026-07-16T17:00:10.553388Z
has_streams  = yes
```
i.e. the cache *does* persist the exact version token the CAS relies on. (13 cache files present; cache is active.)

### 2b. Single-doc writers bust the cache; `commitBatch` does NOT
Every single-document write method invalidates **both** the request memo **and** the cross-request config cache:
```php
$this->_readCache = []; $this->_bustConfigCache($collection, $docId);
```
— see `createDocument` [:926](application/libraries/Firestore_rest_client.php#L926), and the update/set/delete family at [:1272](application/libraries/Firestore_rest_client.php#L1272), [:1307](application/libraries/Firestore_rest_client.php#L1307), [:1351](application/libraries/Firestore_rest_client.php#L1351), [:1377](application/libraries/Firestore_rest_client.php#L1377), [:1428](application/libraries/Firestore_rest_client.php#L1428).

But **`commitBatch()` clears only the request memo** — it never calls `_bustConfigCache()`:
[Firestore_rest_client.php:953-956](application/libraries/Firestore_rest_client.php#L953-L956)
```php
public function commitBatch(array $ops): bool
{
    $this->_readCache = []; // PERF: write invalidates the request read memo
    if (empty($ops)) return true;
    ...
    // ← no _bustConfigCache() anywhere in this method
```

**This asymmetry is the root cause.** All three stream endpoints write through `commitBatch`:
- `save_stream` → [School_config.php:2575](application/controllers/School_config.php#L2575)
- `seed_streams` → [School_config.php:1169](application/controllers/School_config.php#L1169)
- `delete_stream` → [School_config.php:2707 area](application/controllers/School_config.php#L2703)

So a stream write updates Firestore (advancing `updateTime`) but leaves the **stale `schools` cache entry in place for up to 300 s**. The next request reads the stale `__updateTime` and its CAS is rejected.

> The comment at line 52 ("writes bust it immediately") is only true for single-doc writes. For batch commits it is **false** — that is precisely the bug.

---

## 3. Reproduction — exact sequence

**Precondition:** the `schools/{id}` config cache is warm (any page view / read in the last 5 min populated it with the doc at server updateTime **T0**). One admin, no one else editing.

**First "Save All Streams"** (5 streams: Science, Commerce, Arts, General, +1):

| Step | Request | Cached `__updateTime` read | Server `updateTime` | CAS result |
|------|---------|---------------------------|---------------------|-----------|
| Save #1 Science | fresh HTTP req | **T0** (cache hit) | T0 | ✅ match → commit → server now **T1**; **cache NOT busted → still T0** |
| Save #2 Commerce | fresh HTTP req | **T0** (stale hit) | T1 | ❌ `FAILED_PRECONDITION` → 409 |
| Save #3 Arts | fresh HTTP req | **T0** (stale) | T1 | ❌ 409 |
| Save #4 General | fresh HTTP req | **T0** (stale) | T1 | ❌ 409 |
| Save #5 | fresh HTTP req | **T0** (stale) | T1 | ❌ 409 |

⇒ UI toast: **"1 of 5 streams saved — 4 failed."**

**User clicks "Save All Streams" again to retry** (the failure panel keeps the Save bar visible for one-click retry — [index.php:3488-3489](application/views/school_config/index.php#L3488-L3489)):

- Cache still holds **T0** (still inside the 300 s TTL; save #1's success never busted it). Server is at **T1**.
- **Every** save now reads T0 against a server at T1 → **all 5 fail**.

⇒ UI panel: **"5 stream(s) failed to save"** — **exactly the reported symptom.**

This deterministically explains "every stream fails with a single admin": the first save in a warm-cache window may succeed, but it self-poisons the cache, so all following saves — and all saves on any retry within 5 minutes — hit a stale token.

**Why the single "Save Stream" button seems to work intermittently:** a lone save succeeds *iff* the cache token happens to equal the live token at that instant (fresh window). The second consecutive single save within 5 minutes fails the same way. "Save All" just makes the failure immediate and total because it fires 5 writes back-to-back.

---

## 4. Ruling out the other hypotheses

| Candidate cause | Verdict | Evidence |
|---|---|---|
| Genuine concurrent update | ❌ Ruled out | Single admin; failures are deterministic, not racy. |
| Version-token *format* mismatch | ❌ Ruled out | Save #1 succeeds with the same code path ⇒ the RFC3339 `__updateTime` format is accepted by CAS. Only staleness varies. |
| Client-side stale state | ❌ Ruled out | The token is read **server-side** per request from Firestore/cache; the browser never supplies `updateTime`. |
| Backend stale read | ✅ **Confirmed** | `getDocument` serves `schools` from a 300 s cross-request cache that `commitBatch` never invalidates. |
| First save invalidates token, later saves reuse old one | ✅ **Confirmed (via cache)** | Not in-process reuse — each request re-reads, but from a **shared file cache** that still holds T0. |
| Sequential saves treated as concurrent | ✅ **Confirmed (mechanism)** | Save #1's server-side `updateTime` bump is invisible to saves #2–#5 because the cache masks it. |
| Firestore `updateTime` mismatch | ✅ (proximate) | The precondition *does* mismatch at the server — but because the app supplied a stale token, not because Firestore misbehaved. |
| Incorrect CAS implementation | ⚠️ Partial | CAS itself is correct; the **token-sourcing** (cached read) is the defect. |
| Batch semantics wrong | ❌ | The write itself is a correct single-doc merge PATCH; batching is not the problem. |

---

## 5. Affected files (summary)

| File | Lines | Role in the bug |
|---|---|---|
| `application/libraries/Firestore_rest_client.php` | [953-956](application/libraries/Firestore_rest_client.php#L953-L956) | **Root cause** — `commitBatch()` omits `_bustConfigCache()`. |
| `application/libraries/Firestore_rest_client.php` | [51-57](application/libraries/Firestore_rest_client.php#L51-L57), [598-633](application/libraries/Firestore_rest_client.php#L598-L633) | `schools` is cross-request cached (300 s) incl. `__updateTime`. |
| `application/controllers/School_config.php` | [2500-2599](application/controllers/School_config.php#L2500-L2599) (`save_stream`), [1096-1195](application/controllers/School_config.php#L1096-L1195) (`seed_streams`), [2604-2738](application/controllers/School_config.php#L2604-L2738) (`delete_stream`) | Read cached token → CAS-write via `commitBatch` → 409 on stale token. |
| `application/views/school_config/index.php` | [3468-3518](application/views/school_config/index.php#L3468-L3518) | Sequential Save-All amplifies the defect into a total failure + one-click retry that fails everything. |

**Blast radius beyond streams:** any config-cached collection (`schools`, `schoolControl`, `timetableSettings`, `systemPlans`, `tenantPublic`) written via `commitBatch` under a CAS precondition has the same latent false-positive. Non-config collections (accounting, receipts, feeDemands, …) are unaffected because they are never in `CONFIG_DOC_TTL`, so their cache-file lookup is a no-op.

---

## 6. One-line root cause

> `Firestore_rest_client::commitBatch()` invalidates only the in-request read memo, not the cross-request `schools` config-doc cache (TTL 300 s). After the first stream save advances the document's `updateTime`, subsequent Save-All requests read a stale `__updateTime` from that cache, so their optimistic-concurrency (CAS) precondition is legitimately rejected by Firestore — surfacing as a false "another administrator" 409 for a single user.

Proposed remediation: see **STREAM_CONFIGURATION_PROPOSED_FIX.md**.
