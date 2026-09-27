# PHASE 12A — FEE GENERATION / RECALCULATION LATENCY AUDIT

**Measurement and diagnosis only. No optimisation applied. No production code, query, index, cache or
batching changed. No live data modified. No commit, push or deploy.**
**Date:** 2026-08-17 · **Tenant:** `SCH_B56BB9A401` · `scripts/fee_latency_audit.php` (grep-verified write-free)

---

## 0. The headline, before the detail

| Finding | Verdict |
| --- | --- |
| **Does the scholarship design add Firestore round trips?** | **No. Zero.** The allocator is pure in-memory arithmetic over a spec set that is already loaded — 4,800 specs cost **2.0 ms**. |
| **Is there an existing N+1?** | **Yes, severe.** `demandsForStudent()` is re-queried **once per period** with no memo — **12 identical queries** where 1 would do, a measured **13.1×** amplification. |
| **Is the path Firestore-bound or CPU-bound?** | **Overwhelmingly Firestore-bound.** Every measured operation spends ≥99% of its wall time on the wire. Local PHP is negligible everywhere. |

**An honesty caveat that governs every number below.** These were measured from a **development machine
in India against Firestore over REST**, so absolute latencies (~600–1,000 ms per round trip) are inflated
by geography. Production runs on the Ohio Lightsail box, far closer to Firestore; the same operations
there will be an order of magnitude faster.

**The read COUNTS and the amplification RATIO are environment-independent, and they are the real
finding.** I have not extrapolated absolute production timings, because I cannot measure them from here.

---

## 1. Measurements

Read counts and Firestore wall-time come from the REST client's own counters
(`$probeHttpCalls` / `$probeHttpMs` / `$probeCacheHits`), so the network-vs-local split is **measured,
not estimated**.

| # | Operation | n | Median | p95 | Reads | Memo hits | Firestore ms | Local ms | Bound by |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Single doc read, memo defeated | 8 | **622.7** | 1886.6 | 6 | 2 | 5899.8 | 0.5 | Firestore |
| 1b | Same doc ×8 (memo served) | 8 | **0.0** | 1117.1 | **1** | **7** | 1117.0 | 0.1 | Firestore |
| 2 | Query: students by `schoolId` | 5 | **860.7** | 984.3 | 5 | 0 | 4403.7 | 9.0 | Firestore |
| 3 | Fee-chart read | 6 | **0.0** | 898.1 | **1** | **5** | 898.0 | 0.1 | Firestore |
| 4 | Student identity read | 6 | **0.0** | 758.0 | **1** | **5** | 757.9 | 0.1 | Firestore |
| 5 | `scholarshipAwards` query (per student) | 6 | **972.9** | 1000.2 | 6 | 0 | 5166.5 | 0.4 | Firestore |
| 6 | `feeDemands` query (one student) | 6 | **1037.6** | 1104.3 | 6 | 0 | 5287.3 | 2.6 | Firestore |
| 7 | `buildAdmissionDemandSpecs()` | 4 | **1063.1** | 2752.2 | 6 | 5 | 5239.7 | 14.6 | Firestore |
| 8 | Allocator, 48 specs | 20 | **0.0** | 0.0 | **0** | 0 | 0.0 | 0.2 | **Local** |
| 8 | Allocator, 480 specs | 20 | **0.1** | 0.1 | **0** | 0 | 0.0 | 2.2 | **Local** |
| 8 | Allocator, 4,800 specs | 20 | **2.0** | 2.8 | **0** | 0 | 0.0 | 39.4 | **Local** |
| 9 | `demandsForStudent()` ×12 | 1 | **10,497.7** | — | **12** | **0** | 10,492.3 | 5.3 | Firestore |
| 9b | `demandsForStudent()` ×1 | 1 | **803.6** | — | 1 | 0 | 802.4 | 1.2 | Firestore |

`buildAdmissionDemandSpecs()` produced **37 specs** for Class 9th / Section A, `chartFound = true`.

**Cold vs warm.** Rows 1b, 3 and 4 show the request-scoped memo working exactly as designed: the first
call pays a round trip, the next 5–7 cost **0.0 ms**. Rows 2, 5, 6 are queries, which the memo does not
serve — every call is a fresh round trip.

**Operations 8, 10, 11, 12 (fee generation, recalculation, regeneration, bulk) were NOT executed**:
they write, and this audit is read-only. Their read cost is characterised through their components
(rows 6, 7, 9) and their write counts by tracing (§3).

---

## 2. The N+1 — measured, not inferred

`_generateDemandsForMonth()` calls `$this->fsTxn->demandsForStudent($studentId)` at its top
(`Fees.php:4857`). It is invoked **once per month**. `demandsForStudent()` is a `schoolWhere` query with
**no memo** (`Fee_firestore_txn.php:718`).

```
12 months × 1 identical query = 12 round trips per student per generation
measured: 10,497.7 ms  vs  803.6 ms for one     →  13.1× amplification
```

The data returned is **identical on all 12 calls** — it is the student's existing demand set, used to
seed the dedupe key map. It changes only via writes made *by the same loop*.

**Contrast:** `canonicalHeadIdMap()` (`:1048`) and `_resolveFeeCategory()` *are* memoised, and cost 0
after first call. So the pattern is understood in the codebase — `demandsForStudent()` is the one that
was missed.

### Bulk scaling (projection from the measured per-student cost — NOT executed)

| Cohort | Queries, this call alone | Projected wall time |
| --- | --- | --- |
| 1 student | 12 | ~10.5 s |
| 30 students | 360 | ~315 s |
| 200 students | 2,400 | ~2,100 s (~35 min) |

Growth is **linear in students × periods** — the signature of an N+1, not of data volume. On production
hardware the constant is much smaller, but **2,400 queries to generate one school's demands remains
2,400 queries**, and it will exceed PHP's execution limit long before it is fast.

---

## 3. Hotspot analysis — your 12 points

| # | Question | Finding |
| --- | --- | --- |
| 1 | Repeated Firestore reads | **Yes** — `demandsForStudent()` ×12 per student |
| 2 | Reads inside student loops | **Yes** — `_getStudentDiscounts()` at `Fees:1528` and `:5571`, once per student; each issues a `scholarshipAwards` query (row 5, ~973 ms) plus a student read |
| 3 | Reads inside period loops | **Yes** — the N+1 above |
| 4 | Reads inside fee-head loops | **No** — the head loop is pure in-memory over the chart |
| 5 | Duplicate reads of the same document | **Yes** — same student demand set, 12×; also `getStudent()` re-read by both the endpoint and (pre-P3D-F1) the helper |
| 6 | Duplicate queries per student/session | **Yes** — as above |
| 7 | Unnecessary fee-chart re-reads | **Partly mitigated** — memo-served within a request (row 3 = 0.0 ms after first). Across the bulk job each student re-resolves the chart, but `_getClassFeeChart` benefits from the memo for the same class/section |
| 8 | Unnecessary scholarship re-reads | **Yes** — one query per student, never batched or memoised across the cohort |
| 9 | Unnecessary demand re-reads | **Yes** — the headline N+1 |
| 10 | Writes that could be batched | **Already batched** on both paths — `commitDemandBatch()` on the canonical path with a per-doc fallback, and `$batchOps` accumulation in `_generateDemandsForMonth()` for bulk. **This is a genuine strength; do not "fix" it.** |
| 11 | Queries already correctly indexed | The measured queries are equality-only (`schoolId` + `studentId` + `status`), which Firestore serves by single-field index merging. No composite index is needed or missing for them |
| 12 | Query/index combos likely to cause latency | **None found.** Latency here is round-trip *count*, not query *cost* |

---

## 4. The scholarship design's cost — the question this gate exists to answer

**Zero additional Firestore round trips.**

The `whole_session` allocator consumes the spec set the generator has **already built in memory** and
returns a `demandId ⇒ share` map. It reads nothing. Measured:

| Specs | Median | Reads |
| --- | --- | --- |
| 48 (1 student, 1 year) | **0.0 ms** | 0 |
| 480 (10 students) | **0.1 ms** | 0 |
| 4,800 (100 students) | **2.0 ms** | 0 |

A realistic single student is ~37 specs — **immeasurably small** next to a single ~800 ms round trip.

**One genuinely new read** is required by the design, and I am flagging it rather than burying it: the
award itself must be fetched, scoped to its session (P3D-F4). That is **one query per student**, and it
*replaces* the existing `readScholarshipAwards()` call inside `_getStudentDiscounts()` rather than adding
to it — so the net change is **±0 reads**, with the session predicate added to a query that already runs.

**The design also removes reads.** Because a `whole_session` allocation needs the complete spec set, the
allocator must sit at the canonical boundary where that set already exists — which is structurally
incompatible with the per-month re-query. Implementing it correctly makes the N+1 *harder to
reintroduce*, not easier.

---

## 5. Your acceptance-gate categories

| | Finding |
| --- | --- |
| **A. Existing latency** | Firestore-bound throughout; ~600–1,000 ms per round trip from this machine. Read counts are the meaningful metric |
| **B. Latency introduced by the scholarship design** | **None measurable.** 0 extra reads; ≤2 ms CPU at 100× realistic scale |
| **C. Firestore/network latency** | ≥99% of wall time in every non-allocator measurement |
| **D. Local PHP computation** | Negligible — the largest local figure anywhere is 39 ms for 20 runs over 4,800 specs |
| **E. N+1 / read amplification** | **Confirmed: 13.1×** on `demandsForStudent()`. Also per-student scholarship queries in bulk loops |
| **F. Query / index problems** | **None.** All measured queries are equality-only and index-merge served |
| **G. Safe optimisation opportunities** | §6, items 1–2 — mechanical, no behaviour change |
| **H. Needs architectural/business review** | §6, items 3–4 — they alter transaction shape or read semantics |

---

## 6. Proposed optimisations — **NOT APPLIED**, listed for your review

| # | Proposal | Class | Expected effect | Risk |
| --- | --- | --- | --- | --- |
| 1 | **Memoise `demandsForStudent()` per (student, request)**, invalidated on write — the identical pattern `canonicalHeadIdMap()` already uses | **G — safe** | 12 queries → 1 per student; ~92% of that call's cost removed | Low. Must invalidate on demand writes or the dedupe map goes stale mid-loop |
| 2 | **Hoist `demandsForStudent()` out of `_generateDemandsForMonth()`** into the caller and pass it in | **G — safe** | Same as 1, and structurally prevents recurrence | Low, but it changes a private signature — pairs naturally with the B3 routing |
| 3 | **Batch the per-student scholarship/discount reads** across a bulk cohort (one query for N students instead of N queries) | **H — review** | Bulk generation from ~2,400 → ~200 queries at 200 students | Changes read semantics; needs a composite index and a decision on staleness within a long job |
| 4 | **Populate `feeCategories`** so service classification stops relying on a name heuristic | **H — data/config** | Correctness, not latency — the §4 gap in the Phase C design | Data task; also makes scholarship scope robust |

**No index change is proposed.** Item 3 would need one if adopted; I have deliberately **not** modified
`firestore.indexes.json`. No `firestore.rules` change is needed for anything here.

---

## 7. Verdict

**Functionally, the scholarship design passes this gate**: it adds no Firestore round trips and no
meaningful CPU. Its correctness gate remains separate and is unchanged by these numbers.

**But the canonical path it will run on has a pre-existing 13.1× read amplification**, and the moment
that path also becomes the route for the five B3 endpoints, that amplification is inherited by every
generation, recalculation, regeneration and bulk run in the system.

**I am not calling the path performance-safe.** It is Firestore-bound with a known N+1 that bulk
generation multiplies by cohort size. Fixing it is mechanical (item 1 or 2) and I recommend doing it
**as part of the B3 routing**, when that private signature is being touched anyway — not as a separate
change, and not silently folded into the scholarship correction.

---

## 8. State

| Item | Value |
| --- | --- |
| Production code changed | **None** |
| Caching / indexes / queries / batching changed | **None** |
| Tests | **1806**, unchanged |
| Baseline / new failures | **10 / 0** |
| Live data | **None touched** — no writes; `feeDemands`, `students`, `feeStructures` read only |
| Rules / indexes | **Untouched** |
| RTDB | **None** |
| `USE_ENTITLEMENT_BILLING` | **false** |
| `REAL_ADMISSION_READY` | **FALSE** |
| Commit / push / deploy | **NO / NO / NO** |

**Next:** your review of §6, then Phase D (scholarship correction) once the three §11 items in the
Phase C design are ruled on. The N+1 fix is recommended for the B3 routing phase, not for Phase D.
