# Document Retention Reconciler — Architecture & Implementation Roadmap

**Status:** DESIGN ONLY. No code, no commit, no push. The Canonical Document Service remains **frozen**.
**Goal:** make the *existing* reference-count + retention lifecycle **production-complete** by adding an automatic, generic cleanup mechanism that works for **every** consumer (Admission, Student, HR, Staff, Hostel, Transport, Certificates, Parent/Teacher apps) — **without changing the Document Service's lifecycle, schema, or deletion policy.**
**Date:** 2026-07-22

---

## 0. Direct answers to the three questions

**Q1 — Is there any automatic cleanup mechanism for expired retained documents today?**
**No.** When a document's last link is removed, `unlink()` stamps `retention.eligibleAt = now + retention_days` (default 180) and stops. Purge happens **only** if `delete()` is called again *after* that timestamp has passed — and nothing ever calls it again on an already-orphaned document. So orphaned documents keep their metadata **and** their Storage bytes **forever**. (There are reconcilers for Accounting/Payroll/Fees/Timetables/Transport, but **none for `documents`**.) This was explicitly recorded as a Phase-2 item in the Document Service certification.

**Q2 — Recommended production-grade approach.**
A **scheduled, tenant-global, idempotent Document Retention Reconciler** — a *new consumer* of the Document Service that runs on the existing cron/`run_scheduled.php` mechanism. Each run: discovers eligible documents (`linkCount == 0`, `eligibleAt <= now`, no `legalHold`), **re-verifies each is genuinely orphaned by recomputing from the authoritative `documentLinks`** (defense against counter drift), then purges under an **optimistic-concurrency (CAS) guard** so a concurrent re-link aborts the purge. It deletes the **Storage object first, verifies, then the metadata** (closing the F6 gap), and audits every purge. Batched, paginated, resumable, rate-limited, with a **dry-run** mode.

**Q3 — Generic design for all consumers.**
Because the reconciler operates on the **service's own collections (`documents` + `documentLinks`)** and never on any module's data, it is **module-agnostic by construction**: any module that links documents is automatically covered, and a **new consumer needs zero cleanup code**. The full design is below.

---

## 1. Current state (evidence)

| Fact | Where |
|---|---|
| `unlink()` sets `retention.eligibleAt = now + retention_days` when `linkCount` hits 0 | `Document_service.php` (unlink) |
| `link()` clears `retention.eligibleAt = null` (a fresh reference cancels the countdown) | `Document_service.php` (link) |
| `delete(force=false)` purges only if `linkCount<=0 && !legalHold && (force || eligibleAt<=now)` | `Document_service.php` (delete) |
| `purge()` deletes Storage object → metadata → audit; it is **private** (only reachable via `delete`) | `Document_service.php` |
| **Nothing scans `documents` for eligible-but-unpurged records** | no reconciler exists |

**Consequence:** every soft-deleted (orphaned) document — a rejected admission's files, a replaced-then-unreferenced version, a student document removed by an admin — is retained **indefinitely**. Storage is never reclaimed; metadata accumulates. Functionally safe (nothing breaks), but **not production-complete** and a growing cost/compliance liability.

---

## 2. Design principles

1. **Service stays frozen.** The reconciler is a **new consumer**, not a service change. It uses the service's public API and its published collections/shapes; it introduces **no new lifecycle, deletion policy, or schema**.
2. **Single lifecycle for all consumers.** There is exactly ONE retention model (reference-count + `eligibleAt` + `legalHold`) and ONE purge definition. Student does not — and must not — differ from Admission.
3. **Links are the source of truth.** `linkCount` is a cache; the reconciler recomputes from active `documentLinks` before any destructive purge (this is also where residual F4/F5 counter drift self-heals).
4. **Safe by default.** CAS-gated purge, Storage-before-metadata deletion with verification, legal-hold honored, dry-run first, grace buffer.
5. **Firestore-only.** No RTDB. Discovery, verification, and audit all in Firestore; bytes in Storage.
6. **Tenant-safe + platform-wide.** Runs as a system job (service account) across all schools; every purge is stamped with the document's `schoolId` for audit; it never crosses a document into the wrong tenant.
7. **Observable + idempotent + resumable.** Every run emits metrics; re-running is safe; pagination survives restarts.

---

## 3. Architecture

### 3.1 Component
A new CLI-invocable component — **`Document_reconciler`** (module-agnostic) — invoked by the existing scheduler (`scripts/run_scheduled.php --job=document_retention`) and optionally by an on-demand Super-Admin trigger. It mirrors the established reconciler pattern (`AccountingReconciler` / `PayrollReconciler`) already in the codebase.

### 3.2 Per-run flow
```
for each eligible document (paged):
  1. DISCOVER    documents where linkCount==0 AND legalHold==false AND eligibleAt<=now, orderBy eligibleAt, limit N
  2. RE-VERIFY   recompute active links: documentLinks where documentId==D AND active==true
                  ├─ activeLinks > 0  → DRIFT: repair linkCount = activeLinks, clear eligibleAt, SKIP (not orphaned)
                  └─ activeLinks == 0 → genuinely orphaned → continue
  3. RE-CHECK    re-read the document (fresh updateTime); confirm still linkCount==0, eligibleAt<=now, !legalHold,
                  and past the GRACE buffer (eligibleAt <= now − graceDays)
  4. CAS PURGE   delete Storage object  →  verify it is gone  →  delete metadata doc UNDER a
                  currentDocument.updateTime precondition (a concurrent re-link bumps updateTime → purge aborts)
  5. AUDIT       write the standard `purge` audit event (module:'document_service') + reclaimed bytes
  6. CURSOR      advance startAfter; stop at batch cap
emit run summary (scanned / purged / drift-repaired / skipped-relinked / storage-fail / bytes-reclaimed)
```

### 3.3 The purge entry point (frozen-service note)
Two options; the roadmap uses (A) as the interim and proposes (B) as the clean, sign-off-gated evolution:

- **(A) Interim — use the existing public API.** After the reconciler's own re-verification, call `docsvc->delete(D, 'system', 'reconciler', 'reconciler', force=false)` — the empty-module unlink is a no-op and `delete()` purges because `eligibleAt<=now`. **No service change.** Residual limitation: `delete()`'s purge is not CAS-gated and does not re-check Storage-delete success (F6), so the reconciler must minimize the TOCTOU window (re-read immediately before) and tolerate a Storage-delete retry next run.
- **(B) Recommended long-term — one additive, signed-off service method.** A small `Document_service::reconcilePurge(documentId): {purged,reason}` that internally does the recompute + CAS-gated Storage-then-metadata delete. This keeps ALL purge logic inside the service (no duplication — principle #2), gives full CAS + F6 safety, and is the *only* mutation the service would ever need for this feature. It is an **additive platform capability**, requested with explicit sign-off per the frozen-service rule — not a consumer-driven change.

> Recommendation: ship the reconciler against (A) first (zero service change, immediately unblocks cleanup), and adopt (B) in a controlled service release once its design is signed off — at which point the reconciler simply calls `reconcilePurge()`.

### 3.4 Discovery indexes (Firestore, additive — not a service change)
- `documents`: composite index `legalHold ASC, linkCount ASC, retention.eligibleAt ASC` (equality on `legalHold==false` + `linkCount==0`, range/order on `eligibleAt`). *(If nesting a field under `retention` is awkward to index, promote a top-level mirror `retentionEligibleAt` — but that would touch the service; prefer indexing the nested field.)*
- `documentLinks`: composite index `documentId ASC, active ASC` for the re-verify recompute.
- These go in `firebase-rules/firestore.indexes.json`. Rules are unchanged (`documents`/`documentLinks` remain server-only deny-all; the reconciler uses the service account, which bypasses rules).

### 3.5 Scheduling & execution
- **Primary:** nightly cron via `scripts/run_scheduled.php --job=document_retention` (off-peak, e.g. 03:00), tenant-global.
- **Backlog sweep:** a bounded first run (or a few runs) to clear the accumulated orphan backlog, rate-limited.
- **On-demand:** a Super-Admin "Run document cleanup (dry-run / apply)" trigger for ops.
- **Dry-run:** `--dry-run` reports what *would* be purged (count + bytes) without deleting — the mandatory first step before enabling apply mode.

### 3.6 Safety controls
| Control | Behavior |
|---|---|
| Legal hold | `legalHold==true` → never purged (filtered in discovery + re-checked at purge). |
| Grace buffer | Purge only when `eligibleAt <= now − graceDays` (e.g. 7d) — absorbs clock skew / last-minute re-links. |
| Drift self-heal | `activeLinks > 0` while `linkCount==0` → repair the counter, clear `eligibleAt`, skip. |
| CAS purge | Metadata delete gated on `updateTime` precondition → a concurrent `link()` aborts the purge. |
| Storage-first | Delete Storage object, verify gone, *then* delete metadata (closes F6; a failed Storage delete leaves metadata to retry next run). |
| Batching / rate-limit | ≤ N docs/run, throttle Storage deletes to stay under quota. |
| Idempotent / resumable | `startAfter` cursor; re-running never double-purges. |
| Per-tenant guard | Each purge stamps `schoolId`; the job never mixes tenants. |

### 3.7 Observability
- Per-run summary document (`documentReconcilerRuns/{ts}`): scanned, purged, driftRepaired, relinkedSkips, storageFailures, bytesReclaimed, cursor, duration.
- Per-document `purge` audit event (existing shape) — full provenance retained even though bytes are gone.
- Alert thresholds (e.g. storageFailures > X, or purged spikes) for ops.

### 3.8 Retention policy extensibility (future, config-only)
The single `eligibleAt` already supports **per-type/per-module** windows: at `unlink` time the window is `retention_days`. A later, config-driven **policy map** (e.g. admission-rejected = 180d, student TC = 7 years, transport KYC = 1 year, legal = hold) can set `retention.policy` + a longer `eligibleAt` — the reconciler needs **no change** (it purges whatever `eligibleAt` says). This keeps one engine, many policies.

---

## 4. Why this is generic for every future consumer

- The reconciler reads/writes **only** `documents` + `documentLinks` — the service's own layer. It has **no knowledge of Admission, Student, HR, etc.**
- Any module that follows the consumer contract (upload → link; unlink/delete on removal) automatically gets correct cleanup. **A new consumer writes zero cleanup code** — it just links documents, and the platform reclaims them when they become orphaned.
- One policy, one job, one audit trail, platform-wide — exactly the "single lifecycle across all consumers" you asked for.

---

## 5. Edge cases & mitigations

| Case | Mitigation |
|---|---|
| Re-link during purge (someone re-attaches a just-eligible doc) | `link()` clears `eligibleAt` + bumps `updateTime`; the CAS-gated purge aborts; grace buffer further reduces the window. |
| Counter drift (linkCount says 0 but a link is active) | Re-verify from `documentLinks` before purge; repair + skip. |
| Storage delete fails / partial | Storage-first + verify; on failure keep metadata, retry next run; count as `storageFailures`. |
| Legal hold added after eligibility | Re-checked at purge; held docs skipped. |
| Huge backlog on first enable | Dry-run to size it; bounded batches; multi-run backlog sweep; rate-limit. |
| Multi-version history | Not applicable — single-live-version model; only the current object exists (ADR-001 A1). |
| Cross-tenant safety | System job scans globally but each doc carries `schoolId`; purge is per-document, never bulk-by-path. |

---

## 6. Implementation roadmap (phased — no code yet)

**Phase R0 — Design ratification (this document).** Approve the approach + open decisions (§7).

**Phase R1 — Indexes + dry-run discovery.**
- Add the two composite indexes to `firestore.indexes.json`; deploy.
- Build `Document_reconciler` with **dry-run only**: discover + re-verify + report (count, bytes, drift) — **purges nothing**. Validate against the live backlog.

**Phase R2 — Guarded purge (interim entry point A).**
- Enable apply mode using `docsvc->delete()` after re-verification, with CAS re-read, grace buffer, Storage-first delete + verify, batching, audit, run-summary.
- Reuse the CLI verifier pattern (`document_service_verify`) to prove: eligible→purged, not-eligible→skipped, re-linked→skipped, drift→repaired, shared/admission doc→never purged.

**Phase R3 — Scheduling + ops surface.**
- Wire `run_scheduled.php --job=document_retention` into cron (nightly, off-peak).
- Add the Super-Admin on-demand dry-run/apply trigger + the run-summary dashboard/alerts.

**Phase R4 — Backlog sweep.**
- Rate-limited multi-run sweep of the accumulated orphan backlog; report reclaimed bytes.

**Phase R5 (optional, sign-off-gated) — clean service entry point (B).**
- Add the additive `Document_service::reconcilePurge()` (CAS + F6-safe), controlled service release with explicit sign-off; reconciler switches to it. Retires the interim's residual limitations.

**Phase R6 (optional, config-only) — retention policy map.**
- Per-type/per-module retention windows + optional cold-storage tier for archived-but-not-yet-eligible objects.

---

## 7. Open decisions for the operator

1. **Schedule + grace:** nightly at 03:00, grace buffer 7 days — confirm or adjust.
2. **Retention window:** keep the 180-day default platform-wide, or set per-module policies (Phase R6)?
3. **Execution scope:** one global system job (recommended) vs per-tenant Super-Admin runs.
4. **Entry point:** ship interim (A) now, plan signed-off (B) later — confirm this sequencing.
5. **Backlog:** dry-run first to size the existing orphan backlog before any apply run.
6. **Hard purge vs cold-tier:** permanently delete eligible objects, or move them to a cheaper Storage class for a further window before deletion?

---

## 8. Frozen-service statement

This design **does not modify the Document Service, its APIs, schema, concurrency model, reference-count logic, Storage layout, or deletion policy.** The reconciler is a new, module-agnostic consumer using the service's existing public API plus two additive Firestore indexes. The single optional service touch (Phase R5, entry point B) is explicitly deferred and **gated on your sign-off** per the frozen-service rule.

*No code written. Nothing committed or pushed. Awaiting your review of this design + the §7 decisions before any implementation.*
