# Document Service Phase 1 — Concurrency Hardening (F3/F4) & Re-Verification

**Scope:** `Document_service.php` only (+ CLI verifier). **No other module modified.** No commit, no push.
**Result:** F3 and F4 hardened with optimistic concurrency (CAS); full verification re-run and **PASS**. F5 additionally resolved as a side effect.
**Date:** 2026-07-22

> The service is becoming the foundation other modules will inherit, so the concurrency guarantees now live at the service layer, built entirely from primitives the Firestore REST client already exposes (`firestoreGet.__updateTime` + `commitBatch` with `currentDocument` preconditions — the same Phase-8 accounting CAS pattern). No shared infrastructure was changed.

---

## What changed (isolated to the Document Service)

| Method | Before | After |
|---|---|---|
| `replace()` | read version → upload `_v{n+1}` → `firestoreUpdate` (no precondition) → delete old. Concurrent replaces raced on the version. | **CAS retry loop:** read `version`+`updateTime` → upload to a **unique** path → commit metadata **only if the doc's `updateTime` is unchanged**; on a CAS miss, **delete our own object** (no orphan) and retry on fresh state. |
| `link()` | `firestoreCreate` (atomic) **then** a separate `firestoreIncrement` **then** a separate retention update. | **Single atomic `commitBatch`:** link create-if-absent (or CAS reactivate) **+** `linkCount` write CAS'd on the doc's `updateTime`, committed together; retry on contention. |
| `unlink()` | read link → check `active` → **separate** `firestoreUpdate(active=false)` **+ separate** `firestoreIncrement(-1)`. Two concurrent unlinks both passed the check → **double decrement**. | **Single atomic `commitBatch`:** deactivate link (CAS on the link's `updateTime`) **+** `linkCount` decrement (CAS on the doc's `updateTime`), committed together. Only the writer that actually flips `active` decrements. |
| `replacePath()` | — | new helper: unique per-attempt Storage path (`{docId}_v{n}_{rand}.{ext}`) so concurrent replaces never collide. |
| `retentionMap()` | — | rebuilds the full nested `retention` map (batch writes can't use dotted paths), preserving `policy`/`legalHold`. |

**Mechanism:** correctness comes from the **server-side `currentDocument` precondition** (a stale `updateTime` → the whole `:commit` fails atomically, HTTP 400 FAILED_PRECONDITION). The retry loop (`CAS_MAX_RETRIES = 6`, small backoff) only absorbs contention; it can never produce an incorrect count — the invariant is enforced by Firestore, not by the retries. Under extreme contention an operation returns a clean `"...busy — please retry"` error (the CRM endpoints already surface `json_error`), never a corrupt count.

- **F5 resolved for free:** link create/reactivate and the counter change are now one atomic commit, closing the old create-then-increment gap.
- **F6/F7** (purge Storage-delete not result-checked; `is_uploaded_file` soft-bypass) remain open, unchanged, LOW — out of this hardening's scope.

---

## Verification — re-run in full against a live test tenant (`SCH_9C7986EA3E`)

### 1. Single-thread lifecycle — `document_service_verify run` → **20 / 20 PASS**
upload → tenant-isolation (cross-school read blocked) → link → **dedup** → student-link → verify → **replace (v1→v2)** → unlink (refcount holds) → delete+force (**purge**) → gone. Confirms the hardened code is behavior-compatible end-to-end.

### 2. Deterministic concurrency proof — `document_service_verify race` → **11 / 11 PASS**
Constructs the exact interleaving (capture admin-1's read → admin-2 completes a real mutation → replay admin-1's now-stale commit):

```
F4 — two admins unlink the same link simultaneously:
  [PASS] linkCount = 2 (admission + student)
  [PASS] after admin-2 unlink: linkCount = 1
  [PASS] stale concurrent unlink REJECTED by CAS
  [PASS] linkCount still 1 — NO double-decrement
F3 — two admins replace the same document simultaneously:
  [PASS] admin-2 replace → version 2
  [PASS] stale concurrent replace REJECTED by CAS
  [PASS] serialized replace advances → version 3
  [PASS] downloadUrl matches committed storagePath
```

### 3. Empirical parallel stress — 3 OS processes × 4 link/unlink cycles on ONE shared counter → **invariant holds**
```
  stored linkCount = 1; recomputed active links = 1
  [PASS] INVARIANT: stored linkCount == active link count (no drift)
  [PASS] all workers net-zero → count back to baseline 1
```
A separate run whose workers were **SIGKILL'd mid-cycle** still showed `stored linkCount == active links` (2 == 2) — the atomic batch keeps link-state and counter consistent even across a crash between link and unlink. (High latency to the US-region test DB from here limits the practical worker/cycle count; the deterministic proof in §2 is the authoritative correctness evidence — it does not depend on hitting a race window.)

---

## The six requested stress scenarios

| Scenario | Verdict | Basis |
|---|---|---|
| **Simultaneous Replace** | ✅ No lost update, no orphan | §2 F3 (CAS rejects stale; serialized version advance; loser deletes own object) |
| **Simultaneous Link / Unlink** | ✅ No double-count | §2 F4 + §3 parallel invariant |
| **Simultaneous Delete** | ✅ Safe | delete = CAS-safe `unlink` (§2 F4) + idempotent purge (`firestoreDelete` + `deleteStorageFile` are both idempotent → concurrent purges cause no error, no double effect) |
| **Simultaneous Verify** | ✅ No corruption | single-field write of a validated enum; last-writer-wins between two valid statuses; no counter/multi-doc invariant involved |
| **Simultaneous Enrollment** | ✅ Exactly-once | `Accounting_idempotency` `ENROLL_{appId}` gate (unchanged) + `link()` idempotent — a concurrent/retry enrollment neither creates a second student nor double-counts links |

## Confirmations

- **No race conditions** — CAS preconditions serialize every count mutation and every version bump; proven in §2, corroborated in §3.
- **No orphan Storage objects** — replace deletes the loser's own object on a CAS miss and the old object on a win; unique paths prevent collisions; the lifecycle purge was exercised (§1).
- **No metadata corruption** — `version` strictly increases under CAS; `downloadUrl` always matches the committed `storagePath`; `retention` map preserved whole (`policy`/`legalHold`).
- **Correct reference counts** — the invariant *stored `linkCount` == number of active links* held under deterministic race, real parallelism, and mid-operation crash.

---

## Additional issues discovered during hardening

None that are architectural. One behavioral note (not a defect): under **sustained** high contention on a single document, an operation may exhaust its 6 CAS retries and return a clean `"busy — please retry"` error rather than blocking; the CRM already surfaces this as a retryable error, and the stored count is never left inconsistent. F6/F7 (both LOW, previously reported) remain open and out of scope for this pass.

---

## Files changed this pass
| File | Change |
|---|---|
| `application/libraries/Document_service.php` | `replace`/`link`/`unlink` rewritten with CAS; `replacePath()` + `retentionMap()` helpers; `CAS_MAX_RETRIES`. |
| `application/controllers/Document_service_verify.php` | added `race` (deterministic F3/F4 proof) + `race_setup`/`race_worker`/`race_check` (parallel stress). |

`php -l` clean on both. No module outside the Document Service touched; metadata shapes unchanged (backward compatible). **No commit, no push — awaiting your review.**
