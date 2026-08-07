# RC-2 — Query Fallback Hardening & Composite Indexes (operational note)

**Part of:** TMS Stabilization Sprint · **Scope:** `FirestoreRestClient::query()` fallback + `firebase-rules/firestore.indexes.json`
**Status:** implemented + validated (uncommitted). Documentation-only artifact; no code change introduced by this file.

---

## 1. What the hardened fallback does

`FirestoreRestClient::query()` runs an indexed query first (server-side `orderBy` + `limit`). If Firestore rejects it — almost always a **missing composite index** (`FAILED_PRECONDITION`) — the client retries **without `orderBy` and without `limit`**, fetches the full set matching the `where` filters, sorts it in PHP, and slices to the requested `limit`. The result is the **correct top-N**.

Previously the retry dropped `orderBy` but **kept** `limit`, so it returned an arbitrary `limit` documents (the first N by `__name__`) and sorted only those — a silent **wrong** top-N.

## 2. Expected performance characteristics

The fallback trades **latency and read volume for correctness**. Its cost depends entirely on how many documents match the `where` filters (NOT on the `limit`):

| Situation | Behavior | Cost |
|---|---|---|
| **Composite index present** (deployed) | Fallback never triggers; the first indexed query succeeds | Optimal — server returns exactly `limit` docs, already ordered |
| **Index missing, small match set** (tens–hundreds of docs) | One extra round-trip; fetch all matches; PHP `usort` + `array_slice` | Negligible — a few hundred reads + in-memory sort |
| **Index missing, large match set** (thousands+) | Fetch **all** matching docs, sort in PHP, slice | Linear in match-set size: more Firestore reads, more memory, higher latency. **Bounded by the `where` filters, not by `limit`** |

Key implications:
- A tenant-scoped query (`schoolId ==` + a status/route filter) bounds the match set to that tenant's slice, so the fallback stays cheap for realistic data sizes.
- A broad query (only `schoolId ==`) against a large collection with a missing index will fetch that entire tenant slice on every call. This is the case the loud log flags.
- Every fallback logs `ERROR`: `"composite index MISSING for orderBy '<field>' … ADD THE INDEX."` — treat these as an operational signal to deploy the missing index.
- Because the fallback is **correct**, there is no functional urgency to fix a logged index — only a cost/latency one. That is the right trade-off: correct-and-slower beats fast-and-wrong.

## 3. The fallback is a correctness mechanism, NOT a substitute for indexes

The hardened fallback exists so that a missing or not-yet-deployed composite index can never silently return wrong or partial data. **It is a safety net, not a performance strategy.**

- Do **not** rely on the fallback as the steady-state execution path for any query.
- Every query with an `orderBy` + equality filter still needs its matching composite index for production-grade latency and read cost.
- The 11 RC-2 composite indexes added to `firestore.indexes.json` are the intended steady-state path; the fallback only covers the window before they are deployed (and any future query whose index is forgotten).
- Verifier gate `CH-TRN-RC2-2` asserts the RC-2 composites remain present in the index manifest; `CH-TRN-RC2-1` asserts the fallback keeps dropping the `limit`.

## 4. Production deployment requirement (RELEASE STEP)

**Composite indexes are defined in `firebase-rules/firestore.indexes.json` but take effect only when deployed to the Firestore project.** Production releases MUST deploy the index manifest as part of the release process:

```bash
firebase deploy --only firestore:indexes --project <prod-project>
```

Sequencing and expectations:
- Deploy indexes **before** (or together with) the PHP code that issues the corresponding queries — this matches the existing TMS release plan (indexes before code) and eliminates the fallback window in production.
- Composite index builds are **asynchronous**; large tenants can take minutes to hours. Wait for each index to reach state **Ready** in the Firebase console before treating the query path as optimized. Until then, the hardened fallback keeps results correct (just slower).
- Requires the Firestore project on the Blaze plan (composite indexes require Blaze).
- After deploy, the `ERROR` fallback logs for these queries should stop; any that persist indicate an index that did not build or a query shape not yet covered.

**Release checklist addition:** "Deploy `firestore.indexes.json`; confirm all Transport composite indexes are `Ready` before promoting the Transport code path." This belongs in the TMS release execution plan's Firestore-indexes stage.

---

*Working artifact; not committed to git.*
