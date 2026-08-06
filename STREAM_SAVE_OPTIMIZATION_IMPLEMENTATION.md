# Stream Save Optimization — Implementation & Verification Report

**Feature:** Bulk `save_streams` endpoint for the "Save All Streams" button (one request, one CAS, one atomic write).
**Status:** Implemented + verified end-to-end. **NOT committed, NOT pushed** — awaiting review.
**Independence:** Completely separate from the stale-cache bug fix (already committed at `0dd3f70`, untouched here).

---

## 1. Implementation summary

Three files changed; the stream data model, Firestore rules, indexes, and existing API contracts are unchanged.

| File | Change | Lines |
|---|---|---|
| `application/controllers/School_config.php` | **New** `save_streams()` method (bulk, atomic) — inserted next to `save_stream()` | +136 |
| `application/config/routes.php` | **New** route `school_config/save_streams` | +1 |
| `application/views/school_config/index.php` | `saveAllStreams()` rewritten: sequential loop → **one** `post('save_streams', …)` | +28 / −39 |

**`save_streams()` behavior (mirrors `seed_streams` + `save_stream` conventions exactly):**
- `_require_role(ADMIN_ROLES, …)` — same RBAC gate.
- Accepts a JSON array `[{stream_key,label,enabled}, …]` (or a map); **validates every row up-front** with the *same* rules as `save_stream` (`^[A-Za-z0-9_]+$` key, non-empty label ≤100 bytes, boolean `enabled`). Any invalid row rejects the whole batch (`422`) — nothing is written.
- Acquires the **same `'streams'` file lock** (serializes against `save_stream`/`seed_streams`/`delete_stream`).
- **Reads `schools` once**, **upserts** the incoming set into the existing `streams` map (non-destructive — deletion remains `delete_stream`'s job, preserving Save-All semantics), performs **one CAS `commitBatch`** (`currentDocument.updateTime` precondition, `updateMask` merge), returns the merged map.
- `log_audit('Configuration','save_streams', …)`; on CAS failure returns the same `409` as the other stream endpoints.

**Preserved:** Firestore as sole source of truth; existing CAS; existing validation; existing business rules; `save_stream` / `seed_streams` / `delete_stream` untouched. **No** change to Parent App or Teacher App (server-side only; same `schools.streams` shape they already read).

---

## 2. Before vs After architecture

```
BEFORE (client-orchestrated loop)                AFTER (server-side bulk)
─────────────────────────────────               ─────────────────────────────
Click "Save All Streams"                         Click "Save All Streams"
  saveAllStreams():                                saveAllStreams():
    for each stream k:                               post('save_streams', {all})   ← 1 request
      post('save_stream', {k})   ← N requests    server save_streams():
        server: read schools                         read schools            ← 1 read
                CAS commitBatch  ← 1 write/stream     validate all
                                                      CAS commitBatch        ← 1 write (atomic)
  partial success possible ("3 of 5 saved")        all-or-nothing
```

Streams now matches the single-request bulk-save architecture already used by **Classes** (`save_classes`), **Sections** (`bulk_save_sections`), and **Subjects** (`save_bulk_subjects`). The module is now consistent.

---

## 3. HTTP request comparison (N = number of streams)

| Scenario | Before | After |
|---|---|---|
| Save All with 3 streams | **3** requests | **1** request |
| Save All with 5 streams | **5** requests | **1** request |
| Save All with N streams | **N** requests | **1** request |
| Individual Add/Edit (unchanged) | 1 (`save_stream`) | 1 (`save_stream`) |

Measured live (E2E §7): the old flow produced 3–5 requests per click; the new flow produced exactly **1** (`saved=5` in a single call).

---

## 4. Firestore operation comparison (per "Save All", N streams)

| Op | Before | After |
|---|---|---|
| Document reads (`schools`) | N | **1** |
| CAS precondition checks | N | **1** |
| `commitBatch` writes | N | **1** |
| Config-lock acquire/release | N | **1** |
| Atomicity | ❌ per-stream (partial state possible) | ✅ all-or-nothing |

Write/read amplification drops from **O(N) → O(1)**.

---

## 5. Performance comparison

| Dimension | Before (N sequential) | After (1 bulk) |
|---|---|---|
| Round-trips | N × (cross-region RTT) | 1 |
| Firestore writes billed | N | 1 |
| Lock contention window | N acquire/release cycles | 1 |
| Wall-clock (5 streams) | ~5× single-save latency | ~1× |
| Scales with stream count | linearly worse | flat |

The bulk path removes N−1 network round-trips and N−1 Firestore writes per Save-All.

---

## 6. Regression report

All sibling School Config screens exercised live against the real endpoints (net-zero mutations, tenant restored):

| Area | Check | Result |
|---|---|---|
| **Classes** | `save_classes` re-post (net-zero) | ✅ PASS (HTTP 200) |
| **Sections** | `bulk_save_sections` add 'Z' → remove 'Z' | ✅ PASS |
| **Subjects** | `get_all_subjects` (read-only; streams change cannot touch it) | ✅ PASS (HTTP 200) |
| **Academic Session** | `add_session` 2099-00 → `delete_session` | ✅ PASS |
| **Individual streams** | `save_stream` add + edit, `seed_streams`, `delete_stream` | ✅ PASS (unchanged) |

**Structural isolation:** the change adds one new controller method + one route + one JS handler rewrite. It does not modify `save_stream`, `seed_streams`, `delete_stream`, `save_classes`, `bulk_save_sections`, `save_bulk_subjects`, `add_session`, or any shared helper. No schema, rules, index, or app-side change.

---

## 7. End-to-end verification report (real endpoints, admin SSA0001 / IIT Kanpur)

**17 / 17 checks passed.** Tenant restored to snapshot afterward (streams=MATCH, sessions=MATCH, no stray section).

| # | Action → endpoint | Result |
|---|---|---|
| 1 | Open Streams → `get_config` | ✅ 200 |
| 2 | **Add Stream** → `save_stream` (Bio) | ✅ "Stream 'Biology' saved." |
| 3 | **Edit Stream** → `save_stream` (label→"Biology (PCB)") | ✅ label persisted in returned map |
| 4 | **Seed Streams** → `seed_streams` | ✅ added=1 |
| 5 | **Save All Streams** → `save_streams` (5 streams, **ONE request**) | ✅ saved=5; merged map complete; `enabled:false` for Arts honored |
| 6 | **Repeat Save All ×3** | ✅ 200/200/200 — zero false-409, one request each |
| 7 | **Delete Stream** → `delete_stream` (Bio) | ✅ removed |
| 8 | **Refresh persistence** → `get_config` | ✅ Science/Commerce/Arts/General persisted; Bio gone |
| 9 | **Concurrency** → 2 parallel `save_streams` | ✅ no corruption (both serialized to 200 via the `streams` lock; a 409 on a racer would be equally correct) |
| 10 | No regression — **Classes** | ✅ |
| 11 | No regression — **Sections** | ✅ |
| 12 | No regression — **Subjects** | ✅ |
| 13 | No regression — **Academic Session** | ✅ |

**Concurrency note:** `save_streams` uses the identical optimistic-concurrency mechanism as `save_stream`/`seed_streams` — the `'streams'` file lock serializes writers, and the `currentDocument.updateTime` CAS precondition rejects a genuinely stale writer (the same precondition proven to reject stale tokens in the stale-cache fix's harness). The live parallel test confirmed the document stays consistent under simultaneous saves.

**Parent / Teacher apps:** unaffected — no data-shape, collection, or rules change; the apps read `schools.streams` exactly as before. (No app repo touched.)

---

## 8. Repository impact assessment

| Item | Impact |
|---|---|
| Files changed | 3 (`School_config.php`, `routes.php`, `school_config/index.php`) |
| New endpoint | `school_config/save_streams` (additive; nothing else calls the old loop now, but `save_stream` remains fully functional) |
| Data model / schema | **Unchanged** (`schools.streams` map) |
| Firestore rules / indexes | **Unchanged** |
| API contracts | **Unchanged** — `save_stream`, `seed_streams`, `delete_stream`, `get_config` identical; `save_streams` is new |
| Shared primitives | None modified (`commitBatch`, `_config_lock_*`, `firestoreGet` untouched) |
| Cross-module (Fees/Accounting/Transport/etc.) | **None** — change is confined to the Streams tab |
| Parent App / Teacher App | **None** |
| Relationship to `0dd3f70` (stale-cache fix) | **Independent** — different file; this feature *benefits from* that fix but does not depend on being in the same commit. Verified HEAD still contains the fix. |
| Backward compatibility | Full — individual edit path preserved; existing streams data untouched |
| Rollback | Revert these 3 files; `save_stream` loop path could be restored trivially if ever needed |
| `php -l` | Clean on all 3 files |

---

## 9. Commit policy (as instructed)

**Not committed, not pushed.** When approved, this becomes **its own feature-scoped commit**, separate from
`0dd3f70` (the stale-cache fix). All three files are otherwise clean in the working tree (no pre-existing
changes), so the feature commit is a straightforward explicit-path stage of exactly these three files.

**Suggested commit (for your approval later):**
`feat(school_config): bulk save_streams endpoint — one atomic write for "Save All Streams"`
