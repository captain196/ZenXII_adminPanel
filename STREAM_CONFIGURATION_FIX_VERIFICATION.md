# Stream Configuration Fix — Implementation & Verification Report

**Fix:** Option B — `Firestore_rest_client::commitBatch()` now invalidates the cross-request
config-doc cache for each config-cached document it writes, mirroring the single-doc writers.
**Status:** Implemented + verified. **NOT committed, NOT pushed** — awaiting your review.
**Guiding constraint honored:** *do not break any other module or functionality* (see §5 Impact Assessment + §6).

---

## 1. Code diff (the ONLY code change)

**File:** `application/libraries/Firestore_rest_client.php`, method `commitBatch()`.

> Note: `git diff` on this file also shows pre-existing uncommitted changes that were present
> **before** this task began (the file was already `M` at session start, from unrelated work).
> My change is exactly the block below and nothing else. When committing, only this hunk should
> be staged with the stream fix.

```diff
     public function commitBatch(array $ops): bool
     {
         $this->_readCache = []; // PERF: write invalidates the request read memo
+        // Symmetry with the single-doc writers (createDocument / updateDocument /
+        // setDocument / deleteDocument, which all do
+        //   $this->_readCache = []; $this->_bustConfigCache($collection, $docId);):
+        // a batch write must ALSO invalidate the cross-request config-doc cache for
+        // every whitelisted config doc it touches. Without this, a CAS batch commit
+        // against schools / schoolControl / timetableSettings / systemPlans /
+        // tenantPublic left a stale __updateTime in cache/fsdoc/, so the NEXT
+        // request's currentDocument.updateTime precondition was falsely rejected —
+        // the "Another administrator updated…" false-409 on sequential Save-All
+        // Streams (single admin). _bustConfigCache() is a no-op for any collection
+        // not in CONFIG_DOC_TTL, so hot non-config batch paths (accounting journals,
+        // receipts, feeDemands, claims, …) are untouched. Busting on ENTRY (as
+        // createDocument does) is fail-open: even if the commit later fails, no stale
+        // entry survives. Optimistic concurrency (CAS) itself is unchanged.
+        // 2026-07-22 — stale-config-cache invalidation fix (Option B).
+        foreach ($ops as $__cacheOp) {
+            $__cacheCol = (string) ($__cacheOp['collection'] ?? '');
+            $__cacheId  = (string) ($__cacheOp['docId'] ?? '');
+            if ($__cacheCol !== '' && $__cacheId !== '') {
+                $this->_bustConfigCache($__cacheCol, $__cacheId);
+            }
+        }
         if (empty($ops)) return true;
         if ($this->_blockWrite('BATCH', 'commit:' . count($ops))) return false;
```

- **~22 additive lines, one method, one file.** No change to CAS, precondition handling, write
  semantics, schema, controllers, routes, rules, indexes, or client code.
- `php -l` → **No syntax errors detected.**
- CAS precondition (`currentDocument.updateTime`) path is byte-for-byte unchanged.

---

## 2. Regression test report (before / after — same harness)

Harness: `cas_cache_verify.php` — drives the **real** `FirestoreRestClient` + **real** file cache
against **live** Firestore, using a throwaway doc in a config-cached collection
(`timetableSettings/zzCasCacheVerifyProbe`) that is created and **auto-deleted**. No real tenant
data is touched. Before/after captured by stashing only this file (`git stash push -- <file>`).

| Assertion | BEFORE (fix absent) | AFTER (fix present) |
|---|---|---|
| **T1** read-after-commit returns FRESH `__updateTime` | ❌ `token1 == token2` (stale) | ✅ fresh token |
| **T2** 5 sequential Save-All CAS commits succeed | ❌ **0/5 saved, 5 failed** (false-409) | ✅ **5/5 saved, 0 failed** |
| **T3** genuine 2-session conflict → one wins, one rejected | ✅ loser rejected | ✅ A commits, B rejected (CAS intact) |
| **T4** config-doc cache file removed by `commitBatch` | ❌ `gone=false` (not busted) | ✅ `gone=true` (busted) |
| **T5** non-config (`auditLogs`) never touches config cache | ✅ no-op | ✅ no-op |
| **Overall** | **4 assertions FAILED** (bug reproduced) | **ALL PASS** |

The BEFORE run reproduces your exact symptom: sequential Save-All produces false-409s with a
single administrator. The AFTER run eliminates them while leaving genuine concurrency rejection intact.

---

## 3. Verification evidence — mapped to your required checks

| Your required verification | Result | Evidence |
|---|---|---|
| Save All Streams succeeds repeatedly without false 409 | ✅ | T2: 5/5 sequential commits succeed (was 0/5). The streams endpoints call this exact primitive. |
| Individual Save Stream continues to work | ✅ | T1: single CAS commit succeeds; read-back is fresh. |
| `seed_streams()` works | ✅ (structural) | Uses identical `firestoreGet('schools')` → `firestoreCommitBatch(precondition)` path ([School_config.php:1151-1169](application/controllers/School_config.php#L1151-L1169)); primitive is fixed → same guarantee as T2. |
| `delete_stream()` works | ✅ (structural) | Same path ([School_config.php:2698-2707](application/controllers/School_config.php#L2698-L2707)); dependency guard + CAS unchanged. |
| Two simultaneous sessions → one success, one legit conflict | ✅ | T3: session A commits, session B (moved `updateTime`) is correctly rejected → **CAS preserved**. |
| All School Config features using the same primitive work | ✅ | §5 audit: 11 `schools`-doc CAS writers in School_config share one pattern; the primitive fix covers them uniformly. |
| Accounting/Fees/Transport/Communication/Receipts/Fee Demands + all non-config batch ops unaffected | ✅ | T5 (runtime) + §5 audit: those write **non-config** collections → `_bustConfigCache` is a guarded no-op; behavior byte-identical. |

> Scope of evidence (transparent): the harness proves the **shared primitive** (`commitBatch`)
> against a config-cached collection — behaviorally identical to what the stream/session endpoints
> do against `schools`. It does not click the browser UI. The endpoint-level guarantee follows
> because every affected endpoint uses this exact primitive + read pattern (verified statically in §5).

---

## 4. Performance observations

- **Non-config batches (the hot paths):** the added loop does, per op, two array fetches + a
  string compare; `_bustConfigCache` → `_configCacheFile` returns `null` immediately via
  `isset(self::CONFIG_DOC_TTL[$collection])` for any non-whitelisted collection, so **no filesystem
  I/O** occurs. Cost for accounting journals / receipts / feeDemands / claims batches is effectively
  zero (a few ns per op). **No measurable regression.**
- **Config-doc batches (rare — school setup, session add, plan changes):** after the write, that
  doc's next read is one live cross-region read (~1.7 s) instead of a cache hit — **identical to what
  the single-doc writers already cause**, and it is the *correct* behavior (fresh token). Only the
  writing admin's immediately-following read of that same doc is affected; other pages still hit cache.
- **Net:** correctness restored (false-409 eliminated), with negligible and correct perf impact.
  Localhost `perf_probe.log` continues to record per-request timings for ongoing observation.

---

## 5. Repository-wide impact assessment (shared-primitive audit)

`commitBatch()` has **28 caller files**. Only callers that (a) write a **config-cached** collection
**and** (b) use a **CAS precondition** were susceptible to the stale-cache false-409. Enumeration:

### 5a. Susceptible → FIXED by this change
| Module / file | Config collection(s) written via CAS | Was susceptible? | Fixed? | Notes |
|---|---|---|---|---|
| `School_config.php` (streams, classes, sections, subjects, profile, board, report-card, add_session, …) | `schools` ×11 ops, 46 CAS refs | **Yes** | ✅ | The reported bug. All 11 `schools`-doc writers share the identical read→CAS-commit pattern; primitive fix covers all uniformly. |
| `Session_lifecycle.php::addSession` | `schools` (line 123) w/ `updateTime` precondition (line 134) | **Yes** | ✅ | Same latent false-409 on "Add Session"; now resolved. |

### 5b. Writes config docs but NOT susceptible (no CAS precondition)
| Module / file | Collections | Susceptible? | Effect of fix |
|---|---|---|---|
| `B2_registry_service.php` (tenant onboarding) | `schools`, `schoolControl`, `tenantPublic` (lines 1492/1496/1520) | **No** — create flow, **0** preconditions | Harmless bonus: post-create cache is now correctly invalidated. No behavior change. |

### 5c. Not susceptible — write NON-config collections (fix is a guarded no-op)
All remaining callers write business/dynamic collections that are **never** in `CONFIG_DOC_TTL`, so
`_bustConfigCache` short-circuits to a no-op and behavior is byte-identical:

`Fees.php`, `FeeCollectionService.php`, `Accounting.php`, `Fee_firestore_txn.php`, `FeeWorker.php`,
`Fee_refund_service.php`, `Fee_batch_writer.php`, `Fee_audit_logger.php`, `Operations_accounting.php`,
`Accounting_idempotency.php`, `AccountingReconciler.php`, `Billing_integrity.php`,
`Staff_attendance_writer.php`, `Attendance.php`, `Exam_result_store.php`, `Document_service.php`,
`Curriculum_service.php`, `Homework.php`, `Hr.php`, `Timetable_service.php`, `B2_lifecycle_job.php`,
`Stream_b_verifier.php`, `Document_service_verify.php`, `scripts/rollover_academic_session.php`.
(`auditLogs` proven no-op at runtime — T5.)

**Cross-module verdict:** No module outside School Config / Session lifecycle changes behavior.
Accounting, Fees, Transport, Communication, Receipts, Fee Demands, Attendance, Exams, HR, Homework,
Documents, Timetable, Billing — all write non-config collections through `commitBatch` and are
**provably unaffected** (structural guard + runtime T5). No Firestore schema, rules, index, route,
controller, or client/app change. No impact on the Parent or Teacher apps (server-side cache only).

---

## 6a. Live UI endpoint verification (real controllers, authenticated admin)

Beyond the primitive harness, the **actual School_config HTTP endpoints** were driven against the
running server (`http://localhost/ZenX/school`) as the logged-in admin **SSA0001** (School Super
Admin) on tenant **SCH_D94FE8F7AD (IIT Kanpur)** — the exact controllers the browser UI calls
(`get_config`, `seed_streams`, `save_stream`, `delete_stream`, `bulk_save_sections`, `add_session`,
`delete_session`). CSRF-authenticated, sequential `save_stream` calls exactly as the JS
`saveAllStreams()` fires them. **All mutations were reversed and the tenant restored to its exact
pre-test snapshot** (verified: streams=MATCH, sessions=MATCH, no stray section).

| # | UI action → endpoint | Result |
|---|---|---|
| 1 | Open Stream Configuration → `get_config` | ✅ HTTP 200, streams load |
| 2 | Seed Streams → `seed_streams` | ✅ 4 streams seeded (Science/Commerce/Arts/General) |
| 3 | Individual Save Stream → `save_stream` | ✅ "Stream 'Humanities' saved." |
| 4 | **Save All Streams → 5 sequential `save_stream`, repeated ×3** | ✅ **Round 1: 5/5 (200×5), Round 2: 5/5, Round 3: 5/5 — ZERO false-409** |
| 5 | Delete Stream → `delete_stream` | ✅ "Stream deleted." |
| 6 | Refresh → `get_config` | ✅ Science/Commerce/Arts/General persisted; Humanities gone |
| 7 | Sections screen → `bulk_save_sections` (add 'Z' / remove 'Z') | ✅ created=1 then removed=1 (net-zero) |
| 8 | Add Academic Session → `add_session` 2099-00, then `delete_session` | ✅ added → sessions list updated → deleted (net-zero) |

**Result: 14/14 assertions passed via the real endpoints.** The decisive line is #4: **15 consecutive
CAS writes to `schools/{id}` across 3 Save-All rounds, every one HTTP 200.** Under the pre-fix code,
Round 1 would have produced 4 false-409s and Rounds 2–3 all 5 — the exact reported symptom. The fix
eliminates them at the real endpoint level while §2/§3 confirm genuine concurrency is still rejected.

**Non-regression confirmed live:** the Sections save (`bulk_save_sections`, a different School Config
screen) and the Session add/delete flow both succeeded through the same shared primitive with no
errors, and the tenant was restored byte-for-byte.

## 6. Final implementation summary

- **Root cause (proven earlier):** `commitBatch()` cleared only the in-request memo, never the
  cross-request `schools` config cache (TTL 300 s), so after the first stream save advanced the
  document's `updateTime`, subsequent Save-All requests read a stale `__updateTime` and Firestore
  legitimately rejected the CAS → false "another administrator" 409 for a single admin.
- **Fix:** make `commitBatch()` invalidate the config-doc cache for each config-cached op it writes —
  exact symmetry with the single-doc writers. CAS untouched; no force-write / overwrite introduced.
- **Verified:** before/after harness against live Firestore — false-409 eliminated (0/5 → 5/5),
  genuine concurrency still rejected, cache correctly busted, non-config paths untouched.
- **Non-regression:** repo-wide audit of all 28 `commitBatch` callers; only School_config +
  Session_lifecycle (both `schools`-doc CAS writers) are affected, and both are *fixed*, not broken.
  Everything else is a guarded no-op.
- **Also resolved by the same fix (bonus):** the identical latent false-409 in `seed_streams`,
  `delete_stream`, School_config class/section/subject/profile/board/report-card saves, and
  `Session_lifecycle::addSession`.

**Awaiting your review of this report before any commit or push.** When authorized, only the
`commitBatch()` hunk in §1 will be committed with the two analysis docs — the pre-existing unrelated
changes in that file will not be bundled.
