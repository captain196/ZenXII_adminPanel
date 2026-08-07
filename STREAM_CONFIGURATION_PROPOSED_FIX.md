# Stream Configuration — Proposed Production Fix

> **Status: PROPOSAL ONLY. No code has been changed.** Awaiting review before implementation.
> Companion to **STREAM_CONFIGURATION_ROOT_CAUSE_ANALYSIS.md**.

## Design constraints (must all hold)
- ✅ Preserve optimistic concurrency (CAS via `currentDocument.updateTime`).
- ✅ Preserve data integrity — no full-doc overwrites, keep the `updateMask`/merge PATCH.
- ✅ Firestore remains the **only** source of truth.
- ✅ Eliminate **false-positive** concurrency failures for a single admin.
- ✅ Still reject **genuine** simultaneous edits by two admins.
- ❌ Do **not** remove concurrency protection, disable version checking, or bypass CAS.

---

## Recommended fix — **Option B: make `commitBatch()` invalidate the config-doc cache (symmetry fix)**

The single-doc writers already do the right thing; `commitBatch()` simply forgot to. Make batch commits invalidate the cross-request config cache for every config-cached document they touch — identical semantics to `createDocument`/`updateDocument`/`setDocument`.

**File:** `application/libraries/Firestore_rest_client.php`, method `commitBatch()` ([line 953](application/libraries/Firestore_rest_client.php#L953)).

**Change (conceptual — bust the config cache for each op, mirroring the single-doc writers):**
```php
public function commitBatch(array $ops): bool
{
    $this->_readCache = []; // (existing) invalidate request read memo
    // NEW: also invalidate the cross-request config-doc cache for any
    // config-cached collection in this batch — mirrors the single-doc
    // writers (createDocument/updateDocument/setDocument/...), which all do
    //   $this->_readCache = []; $this->_bustConfigCache($collection, $docId);
    foreach ($ops as $op) {
        $c = (string)($op['collection'] ?? '');
        $d = (string)($op['docId'] ?? '');
        if ($c !== '' && $d !== '') {
            $this->_bustConfigCache($c, $d);   // no-op unless $c is in CONFIG_DOC_TTL
        }
    }
    if (empty($ops)) return true;
    ...
}
```

### Why bust at method **entry** (before the HTTP commit)
The Save-All failure is cross-request: it is the **previous** save's commit that must clear the cache so the **next** request reads live. Busting on entry guarantees that, and is also **fail-open** — if the commit itself later fails, the cache is already cleared and the next read goes live (never leaves a stale entry behind). This matches `createDocument`, which busts on entry ([:926](application/libraries/Firestore_rest_client.php#L926)).

### Why this is safe / minimal
- `_bustConfigCache()` is a **no-op** unless the collection is one of the 5 whitelisted config docs (`schools`, `schoolControl`, `timetableSettings`, `systemPlans`, `tenantPublic`) — it just resolves a cache filename and `@unlink`s it if present ([:678-682](application/libraries/Firestore_rest_client.php#L678-L682)).
- The **hot batch paths** (accounting journals, receipts, feeDemands, claims) write **non-config** collections ⇒ the added loop is a cheap string check with **zero I/O** and zero behavioral change for them.
- It restores the exact invariant the code already assumes elsewhere ("writes bust it immediately", [:52](application/libraries/Firestore_rest_client.php#L52)).

### Effect on the reproduction
- Save #1 commits (server → T1) **and busts the `schools` cache**.
- Save #2 request reads **live** → gets T1 → CAS matches → commits (→ T2) and busts again.
- Saves #3–#5 cascade the same way ⇒ **all succeed**. Retual retries also read live.
- Two genuinely-concurrent admins still collide: whoever commits second against a moved `updateTime` is correctly rejected with the real 409. **Protection preserved.**

---

## Alternative / narrower options (for comparison)

### Option A — controller-scoped fresh read (streams only)
In `save_stream`/`seed_streams`/`delete_stream`, bust or bypass the cache **before** reading the token so `__updateTime` is always live. Requires exposing an uncached read (e.g. a public `getDocumentFresh()` or a pre-read `_bustConfigCache('schools', $id)`), because `_getDocumentUncached()` is private.
- ➖ Fixes only the 3 stream endpoints; leaves the same latent bug for every other `commitBatch` CAS writer of a config doc (e.g. class/section/subject/profile saves that also target `schools`). Higher long-term risk; three edit sites instead of one.

### Option C — remove `schools` from the cache whitelist
- ➖ Reintroduces a ~1.7 s cross-region read on nearly every page. Unnecessary perf regression; rejected.

### Option D — client/controller retry-on-409 with a forced fresh read
Make the endpoint (or JS) re-read live and retry once on `FAILED_PRECONDITION`.
- ➕ Reasonable **defense-in-depth**; the code comment already says "Caller is expected to refresh and retry on 409" ([Firestore_rest_client.php:997-998 area](application/libraries/Firestore_rest_client.php#L992-L1005)) but no caller implements it.
- ➖ Treats the symptom, not the cause; still serves stale reads elsewhere. **Optional complement to B, not a substitute.**

**Recommendation:** ship **Option B** (the primitive-level symmetry fix). Optionally add **Option D**'s single retry as defense-in-depth later.

---

## Files that would change (Option B)
| File | Change | LoC |
|---|---|---|
| `application/libraries/Firestore_rest_client.php` | Add config-cache bust loop to `commitBatch()` | ~6 additive |

No controller, view, route, rule, or index change required. No schema change. Firestore stays the source of truth.

---

## Regression risk assessment

| Dimension | Assessment |
|---|---|
| **Scope** | One method (`commitBatch`). Behavior changes **only** for the 5 config-cached collections; all other collections unaffected (no-op). |
| **Concurrency** | CAS unchanged; genuine two-admin conflicts still rejected. Only **false positives** from stale cache are removed. |
| **Data integrity** | Unchanged — same merge/`updateMask` PATCH; no overwrite path introduced. |
| **Performance** | Negligible: after a config-doc batch write, that doc's next read is one live cross-region read (~1.7 s) instead of a cache hit — identical to what single-doc writers already cause, and correct. Hot accounting/receipt batches (non-config) see only a string comparison per op. |
| **Cross-module impact** | `commitBatch` is shared platform code. Because the new bust is gated by `CONFIG_DOC_TTL`, modules writing non-config docs (Accounting, Fees, Transport, Communication) are provably unaffected. Modules writing config docs via batch (School Config class/section/subject/stream/profile, School Control, timetable settings, plans, tenantPublic) get the **correct** cache invalidation they already assumed. |
| **Rollback** | Delete the added loop — instant revert; no data migration. |
| **Apps (Parent/Teacher)** | None — server-side cache only; no Firestore data shape or rules change. |

## Suggested verification (post-implementation, when authorized)
1. **Repro-before:** warm `schools` cache, Save All 4 streams → observe "1 saved, 3 failed", retry → "0/4, 4 failed".
2. **After fix:** same steps → **4/4 saved**, retry → **4/4 saved** (idempotent).
3. **Concurrency still holds:** two sessions save different streams against the same warm token; the second commit against the moved `updateTime` is rejected with a real 409 (verify one succeeds, one is asked to retry, and a retry then succeeds).
4. **Non-config batch unaffected:** run a receipt/journal batch write; confirm no new cache files under `cache/fsdoc/` and unchanged latency.
5. Confirm `seed_streams` and `delete_stream` also succeed sequentially (same root path).

---

## Note on module freeze
School Config is in a Phase-3 soak (observe→verify→classify). This document is the **classify + propose** step only. **No code, commit, or push** has been made. Implementation of Option B is a shared-primitive change and should proceed only under explicit authorization with the verification plan above.
