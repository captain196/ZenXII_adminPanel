# ZenX Document Service — Architecture & Production Certification

**Status:** CANONICAL REFERENCE. Phase 1 (Admission) is implemented, verified, and certified.
**Audience:** every engineer integrating a module (Student, HR, Staff, Hostel, Transport, Certificates) or a client app (Parent, Teacher) with the Document Service.
**Authority:** this document is the single source of truth for the Document Service's invariants, guarantees, and integration contract. If code and this document disagree, that is a bug in one of them — reconcile before shipping.
**Version:** 1.1 · **Date:** 2026-07-22 · Supersedes the MOVE-based D1 in `ADMISSION_DOCUMENT_LIFECYCLE_ARCHITECTURE_REVIEW.md`.

---

## 1. Purpose

A single, module-agnostic service that owns the full lifecycle of every uploaded file in ZenX: **one physical Storage object per logical document, addressed by a stable id, referenced (never copied) by any number of modules, and deleted only when provably safe.** Firestore is the sole metadata store; Firebase Storage is the sole byte store. There is no RTDB, and there is no second metadata store.

Phase 1 wires the service to **Admission only**. Every design choice below is made so that Student/HR/Hostel/Transport/Certificates become an *additive* integration ("add a link"), never a rewrite.

---

## 2. Vocabulary

| Term | Meaning |
|---|---|
| **Document** | One logical file with a stable `documentId`, one physical Storage object, and one metadata record (`documents/{schoolId}_{documentId}`). |
| **Link** | A reference from a module entity (an application, a student, a staff member…) to a document (`documentLinks/{…}`). A document may have many links. |
| **Reference count** (`linkCount`) | The number of currently-active links. The document is *owned collectively* by its link set. |
| **Denormalized pointer** | A cached `{documentId, type, label, url, verificationStatus}` copied onto the *owning module's* entity doc, so lists/receipts render with no join. The `documents/{id}` record stays authoritative for lifecycle. |
| **Module** | A logical owner namespace for links: `admission`, `student`, `hr`, `hostel`, `transport`, `certificate`, … |
| **Role** | The document's purpose within an entity: `photo`, `aadhaar`, `birth_certificate`, `document`, … |

---

## 3. Architectural invariants (must ALWAYS hold)

These are load-bearing. A change that violates any of them is incorrect by definition.

1. **One physical object per logical document.** Replace produces a new version *in place* (old object retired); a document is never duplicated.
2. **Bytes are never moved or copied between modules.** A hand-off (e.g. enrollment) adds a *link*, not a copy. (This is the ratified revised-D1.)
3. **Modules never see or store Storage paths.** They hold an opaque `documentId` plus a denormalized pointer. The Storage path is internal to the service and may change (e.g. on replace) without any referrer knowing.
4. **`linkCount` equals the number of active links, always.** This invariant is enforced atomically (see §7) and is the basis of safe deletion.
5. **A document is purged only when `linkCount == 0` AND no `legalHold` AND (retention elapsed OR an explicit force).** Deleting one module's link can never destroy a file another module still references.
6. **Firestore is the only metadata store; Firebase Storage is the only byte store; no RTDB.** Client apps never read `documents`/`documentLinks` — they read the denormalized pointer.
7. **Every document/link id is tenant-prefixed (`{schoolId}_…`) and every read re-checks the stored `schoolId`.** One school can never reach another's documents.
8. **Every state change is audited** (`auditLogs`, `module:'document_service'`): upload, replace, link, unlink, verify, purge.

---

## 4. Data model

### 4.1 Firestore — `documents/{schoolId}_{documentId}`
```jsonc
{
  documentId, schoolId, type, label, category,          // identity
  mime, ext, sizeBytes, storagePath, downloadUrl,       // bytes (storagePath internal)
  version,                                               // replace bumps this, monotonic
  verificationStatus: "pending|verified|rejected", verifiedBy, verifiedAt,
  uploadedBy, uploadedAt, replacedAt,
  linkCount: <int>,                                      // == active link count (invariant #4)
  retention: { policy:"default", eligibleAt:<iso|null>, legalHold:false }
}
```

### 4.2 Firestore — `documentLinks/{schoolId}_{documentId}_{module}_{entityId}`
```jsonc
{ documentId, schoolId, module, entityId, role,
  active:true, linkedBy, linkedAt, unlinkedAt?, unlinkedBy?, relinkedAt? }
```
The link doc id is deterministic — `module` and `entityId` are sanitized (`[^A-Za-z0-9_] → _`). This determinism is what makes link/unlink idempotent by point-read (no query, no index).

### 4.3 Storage layout
```
documents/{schoolId}/{documentId}_v{version}.{ext}          # initial upload (v1)
documents/{schoolId}/{documentId}_v{version}_{rand}.{ext}    # replace (v2+), unique per attempt
```
Flat, id-addressed, module-agnostic, backend-swappable. The random suffix on replaced versions guarantees concurrent replaces never write the same object (see §7).

### 4.4 Denormalized pointer (stored on the owning entity doc)
```jsonc
{ documentId, type, role, label, url, category, verificationStatus }
```
Example: `crmApplications.documentRefs[]`. A future `students`/`staff`/`hostelResidents` doc holds the same shape.

---

## 5. Public API

Bind once per request: `init($firebase, $schoolId)` — sets the Firebase client and the tenant scope. All ids are then scoped to that school.

| Method | Contract | Returns |
|---|---|---|
| `upload($tmp, $origName, $type, $meta=[])` | Validate (ext+MIME+size+deny-list) → optimize (images) → store one object → write metadata (`linkCount=0`). Caller then `link()`s. | `['ok','error','document'=>pointer]` |
| `replace($documentId, $tmp, $origName, $by)` | New version on the same id, orphan-free and concurrency-safe (§7). Resets `verificationStatus` to `pending`. | `['ok','error','document'=>pointer]` |
| `link($documentId, $module, $entityId, $role, $by)` | Add a reference. **Idempotent** per `(doc,module,entity)`; concurrency-safe. Clears any retention countdown. | `['ok','error','linkCount'?]` |
| `unlink($documentId, $module, $entityId, $by)` | Deactivate a reference; when the last active link goes, start the retention clock (no purge). Concurrency-safe. | `['ok','error','linkCount'?]` |
| `delete($documentId, $module, $entityId, $by, $force=false)` | `unlink` then purge **only if** orphaned & (retention elapsed or `$force`). | `['ok','error','purged'=>bool]` |
| `verify($documentId, $status, $by)` | Set `pending|verified|rejected`. | `['ok','error']` |
| `getMetadata($documentId)` | Tenant-checked read of the pointer. | `pointer|null` |

**Never call `purge()` directly** — it is a private, unconditional destroyer. Deletion always goes through `delete()`, which enforces invariant #5.

---

## 6. Lifecycle

```
upload ──► (linkCount 0) ──link(moduleA)──► referenced (1) ──link(moduleB)──► referenced (2)
                                                  │                                  │
                                               verify                             unlink(any)
                                                  │                                  │
                                          verified/rejected                   referenced (n-1)
                                                                                     │
                                          replace ─► version+1, status=pending   unlink(last)
                                                                                     │
                                                                          orphaned + retention clock
                                                                                     │
                                                        delete(force) OR retention elapsed ─► PURGE
```

- **Attach** = `upload` + `link`.
- **Hand-off** (Admission → Student on enrollment) = `link(newModule)` — no copy, no move.
- **Replace** = `replace` (new version, old object **permanently deleted** — no recoverable history, see §11 — verification reset).
- **Detach** = `unlink` (or `delete`, which unlinks then conditionally purges).
- **Purge** = terminal; only via `delete` under invariant #5.

---

## 7. Concurrency guarantees (the contract other modules inherit)

The service is safe under concurrent operations. Correctness comes from **optimistic concurrency (CAS)** using the platform's existing primitives — `firestoreGet.__updateTime` (the server updateTime) + `commitBatch` with per-write `currentDocument` preconditions (a stale precondition fails the whole commit atomically, HTTP 400 FAILED_PRECONDITION). This is the same pattern the Phase-8 accounting engine uses. `CAS_MAX_RETRIES = 6` with small backoff absorbs contention; the invariant is enforced by Firestore, not by the retries.

| Operation | Guarantee | How |
|---|---|---|
| **Replace** | No lost update, no orphan | Read `version`+`updateTime` → upload to a **unique** path → commit metadata **only if `updateTime` unchanged**. CAS miss → delete our own object (no orphan) → retry on fresh state (serialized, never discarded). |
| **Link** | Exactly one increment per new link; dedup idempotent | One atomic `commitBatch`: link create-if-absent (or CAS reactivate) **+** `linkCount` change CAS'd on the doc's `updateTime`. Losers retry, observe the active link, no-op. |
| **Unlink / Delete** | No double-decrement | One atomic `commitBatch`: deactivate the link (CAS on the link's `updateTime`) **+** `linkCount` decrement (CAS on the doc's `updateTime`). Only the writer that flips `active` decrements. |
| **Verify** | No corruption | Single validated-enum field; last-writer-wins between valid states. |
| **Enrollment / hand-off** | Exactly-once | The calling flow is idempotency-gated (e.g. `ENROLL_{appId}`); `link()` is idempotent regardless. |

**Proven** (live, test tenant): deterministic stale-precondition rejection for replace and unlink (11/11), a real 3-process parallel link/unlink storm holding the invariant `stored linkCount == active links` with no drift, and invariant preservation even across a worker killed mid-cycle. Under *sustained* single-document contention an operation may exhaust its retries and return a clean `"busy — please retry"` — it never leaves an inconsistent count.

**Rule for integrators:** never bypass the service to mutate `documents`/`documentLinks` directly — you would defeat the CAS. Always go through the API.

---

## 8. Reference-count rules

1. `linkCount` starts at 0 on `upload`.
2. `link()` increments by exactly 1 **only** when it creates a new active link (or reactivates an inactive one). A repeat `link()` for the same `(doc,module,entity)` is a **no-op**.
3. `unlink()` decrements by exactly 1 **only** when it transitions an active link to inactive. A repeat `unlink()` is a **no-op**.
4. `linkCount` is written as an absolute value under CAS, never blind-incremented — so it can never drift from the true active-link count (invariant #4).
5. When `linkCount` reaches 0, `retention.eligibleAt = now + retention_days`; a subsequent `link()` clears it back to `null`.
6. `delete()` purges only when `linkCount == 0 && !legalHold && (force || eligibleAt <= now)`.
7. If ever in doubt, the **links are the source of truth**; `linkCount` is a maintained cache. A reconciler (Phase 2) may recompute it by counting active links.

---

## 9. Security model

- **Upload validation:** extension allow-list **and** `finfo` MIME (must match the type) **and** per-type size cap; stored extension derived from validated MIME, not the filename.
- **Hard deny-list** (any type): `svg/svgz/html/htm/xhtml/js/mjs/php/phtml/exe/sh/bat/cmd/com/jar/htaccess` + MIME `image/svg+xml`, `text/html`, `application/x-httpd-php`, `application/javascript`, `text/javascript`.
- **Tenant isolation:** id prefix `{schoolId}_` + stored-`schoolId` re-check on every read; a cross-school read returns `null`.
- **Client access:** `documents` and `documentLinks` are **server-only** — `firestore.rules` deny all client read/write. Client apps read the denormalized pointer on the owning entity, never these collections. Adding a new module does **not** require new rules for these two collections.
- **Action authorization:** lifecycle actions (verify/replace/delete) must be role-gated **and ownership-guarded by the caller** (the `documentId` must be referenced by the acting entity) — see the Admission CRM endpoints as the reference implementation.
- **Downloads:** tokenized `firebasestorage.googleapis.com` URLs (CSP-allow-listed for the admin panel).

---

## 10. Storage efficiency

- **One object per logical document**; replace deletes the old object (single-live-version — see §11).
- **Image optimization**: photos are GD-downscaled to 1200px on the long edge and re-encoded JPEG q82 (~16× on a typical phone photo). Controlled by `image_optimize` in config.
- **Per-type size caps** (config): photo 2 MB, documents 5 MB.
- **References, not copies**, across modules → the same Aadhaar shared by Admission + Student + Hostel + Transport is **1×**, not N×.
- **Retention + purge** reclaim orphaned bytes safely (default 180 days; per-school override via `schools.{id}.admissionDocRetentionDays`).
- **Reserved (not yet enforced):** content-hash dedup, WebP/AVIF encode, PDF normalization, Nearline tier for archived documents.

---

## 11. Document version policy

The Document Service uses a **single-live-version** model: every logical document has exactly **one** current physical object, addressed by a monotonically increasing `version` counter. This is a deliberate storage-efficiency choice (invariant #1) — it is **not** a full version-history archive.

### 11.1 How version history is managed
- Each document carries an integer `version` (starts at 1). Every successful `replace()` increments it (1 → 2 → 3 …), strictly increasing under CAS (§7).
- The current object lives at `documents/{schoolId}/{documentId}_v{version}[_{rand}].{ext}`; the `documentId` is stable across versions, so referrers never change their pointer when a document is replaced.
- There is **no `versions[]` array** of prior objects. The `version` field is a *counter and change-signal*, not an index of recoverable bytes.

### 11.2 Are previous versions recoverable?
**No.** On a successful replace the previous Storage object is **permanently deleted** once the new version's metadata commits. Prior bytes are not archived, not quarantined, and **not recoverable** through the service. Only the *current* version's bytes exist.

### 11.3 When are previous Storage objects permanently removed?
- **On replace:** immediately after the new version's metadata is committed (CAS-gated), the old object is deleted (`deleteStorageFile(oldPath)`). Under concurrent replace, a losing attempt deletes *its own* just-uploaded object, so no orphan is ever left (§7).
- **On purge:** the document's current object is removed when the whole document is purged under invariant #5 (`linkCount == 0`, no `legalHold`, retention elapsed or `force`).
- No other path deletes Storage objects.

### 11.4 Interaction with audit logs
Byte history is not kept, but **provenance is permanent.** Every `replace` writes an immutable audit event to `auditLogs` (`module:'document_service'`, `action:'replace'`) recording `{oldPath, newPath, version, actor, timestamp}`. The trail therefore answers permanently *who replaced what, when, and which storage paths were involved* — even though the superseded bytes are gone. `upload`, `verify`, and `purge` are audited the same way; the audit log is retained independently of the document (a purge writes a terminal `purge` event that survives the document).

### 11.5 Interaction with the retention policy
Version supersession and retention are **two independent mechanisms**:
- **Retention** (`retention.eligibleAt`, default 180 days) governs the **whole document's** eligibility for purge once it becomes orphaned (`linkCount == 0`) — an appeal/audit window before the *current* object may be purged.
- **Version supersession** removes the **previous** object on replace **immediately**, independent of the retention window. Retention never protects a superseded version; it only ever concerns the current object of an orphaned document.
- `legalHold` blocks purge of the current object; it does **not** retroactively restore superseded versions.

### 11.6 Divergence from ADR-001 (flagged for decision — no code changed)
> **Inconsistency discovered and reported per instruction.** ADR-001's canonical shape sketched a `versions: [{v, storagePath, …}]` array with "old retained per policy → historical record preserved." **Phase 1 does not implement that** — it ships the single-live-version model above (old bytes deleted on replace) for storage efficiency, consistent with the platform's storage-cost mandate. The `documentId`-stable design plus the audit trail preserve *provenance* and the *historical link record*, but **not recoverable prior bytes**.
>
> If a future module (Certificates, HR, legal/regulatory documents) needs **recoverable version history** — retained superseded scans, "view/rollback previous version" — that is a deliberate Phase-2+ enhancement: add a `versions[]` index, stop deleting the old object on replace, and bring superseded versions under the retention/`legalHold` engine. It is a code change with a storage-cost trade-off, out of Phase-1 scope, decided per-module. **Until then, treat replace as destructive of prior bytes.**

---

## 12. Backward compatibility contract

- Metadata field shapes are stable; the denormalized pointer shape is stable.
- Legacy entities without a pointer set (pre-service records) must keep working via a fallback to their old URL fields — no migration, no re-upload. (Admission's CRM view and enrollment both demonstrate this.)
- Adding a document type is config-only (§14); it never changes core code or existing data.

---

## 13. Developer guidelines — integrating a NEW module

> Goal: a new module (Student, HR, Hostel, Transport, Certificates, or a client app) adopts the service **additively**. Follow this contract exactly.

**DO**
1. **Scope first:** `$this->docsvc->init($this->firebase, $schoolId)` with the tenant's school id.
2. **Attach a file:** `upload($tmp, $name, $type, ['uploadedBy'=>$actor])` → then `link($documentId, '<yourModule>', $entityId, $role, $actor)`.
3. **Store a denormalized pointer** (`{documentId, type, label, url, verificationStatus}`) on *your* entity doc for display; keep it fresh on replace/verify/delete.
4. **Hand a document to another module** by calling `link()` for that module — never copy bytes.
5. **Detach** with `unlink()` (keep the file for others) or `delete()` (unlink + conditional purge).
6. **Register new document types in config** (`application/config/document_service.php`) — see §14.
7. **Role-gate and ownership-guard** every verify/replace/delete endpoint (the `documentId` must be referenced by the acting entity).
8. **Client apps:** read the denormalized pointer on the entity you already load — never query `documents`/`documentLinks`.

**DON'T**
- ❌ Write to `documents`/`documentLinks` directly (defeats the CAS and the invariants).
- ❌ Store or hardcode Storage paths in your module; hold the `documentId` + pointer only.
- ❌ Copy or move bytes on a hand-off; add a `link`.
- ❌ Call `purge()`; use `delete()`.
- ❌ Assume `linkCount` from stale reads; the service manages it — read via `getMetadata` when you need it.
- ❌ Skip the ownership guard on admin actions.

**Multi-module example (the pattern to copy):** at Admission enrollment, `save_admission`/`enroll_student` iterate the application's `documentRefs` and call `link($documentId, 'student', $studentId, $role)`. No bytes move; the same object is now referenced by both the application and the student, and the file survives an application delete because the student link keeps `linkCount > 0`.

---

## 14. Configuration reference (`application/config/document_service.php`)

Add a new document type without touching core code:
```php
'types' => [
  '<type_key>' => [
    'label'    => 'Human Label',
    'category' => 'predefined' | 'additional',   // predefined → own UI field; additional → grouped
    'ext'      => ['pdf','jpg', …],               // allow-list
    'mime'     => ['application/pdf', …],          // allow-list (must match ext)
    'maxBytes' => 5242880,                         // per-type cap
    'image'    => false,                           // true → run photo optimization
  ],
],
```
Also central here: `deny_ext`/`deny_mime`, `image_optimize`, `retention_days`, `storage_prefix`, `collection_documents`, `collection_links`.

---

## 15. Operations

- **Verifier (reusable):**
  - `SCHOOL_ID=<test> php index.php document_service_verify run` — full lifecycle (20 assertions).
  - `SCHOOL_ID=<test> php index.php document_service_verify race` — deterministic concurrency proof (11 assertions).
  - `race_setup` / `race_worker` / `race_check` — parallel stress harness.
- **Firestore rules/indexes:** `documents`/`documentLinks` are deny-all client (server-only). No composite index is required in Phase 1 (all access is by deterministic key). A Phase-2 orphan reconciler will add the one index it needs (`linkCount` + `retention.eligibleAt`).

---

## 16. Known limitations (tracked, non-blocking for Phase 1)

| Id | Severity | Item | Plan |
|---|---|---|---|
| F6 | LOW | `purge()` deletes Storage then metadata without checking the Storage-delete result → a failed Storage delete could orphan. | Add a result check + log; batch with the metadata delete. |
| F7 | LOW | `validate()` allows a non-`is_uploaded_file` path when it is a real file (internal-staging escape). | Acceptable for `$_FILES` inputs; tighten if a caller ever stages untrusted paths. |
| — | note | No automated retention **reconciler** yet — orphaned bytes past their window are reclaimed via explicit `delete(force)`. | Phase-2 reconciler cron (recompute `linkCount`, purge eligible). Before it ships, re-audit refcount edge cases. |

None affects the production Admission workflow.

---

## 17. Production Certification

**The Document Service is CERTIFIED for Phase 1 (Admission) production**, with the architecture, invariants, and guarantees above as its contract.

- ✅ Firestore-only metadata; Storage-only bytes; no RTDB.
- ✅ One physical object per logical document; references, not copies; W4 hand-off hazard resolved.
- ✅ Reference-counted, retention-governed deletion; orphan-safe.
- ✅ Concurrency-safe (CAS) — replace, link, unlink, delete, verify, enrollment all proven under deterministic race + real parallelism.
- ✅ Tenant-isolated; server-only collections; validated + deny-listed uploads; role-gated + ownership-guarded actions.
- ✅ Storage-efficient (optimization + references); backward compatible (no migration).
- ✅ Verified live on a test tenant (lifecycle 20/20, concurrency 11/11, parallel invariant holds).
- ⚠️ F6/F7 LOW open (accepted); automated reconciler deferred to Phase 2.

**Future-module readiness:** because documents are canonical entities referenced by links, integrating Student/HR/Hostel/Transport/Certificates/Parent/Teacher is additive ("add a link", "register a type", "store a pointer") — no core rewrite. Follow §13.

---

### Changelog
- **1.1 (2026-07-22)** — Added §11 Document Version Policy (single-live-version model; prior bytes not recoverable; audit/retention interaction; ADR-001 `versions[]` divergence flagged for decision). Documentation only; no code change.
- **1.0 (2026-07-22)** — Phase 1 (Admission) implemented, F1/F2 fixed, F3/F4 concurrency-hardened (CAS), verified live, certified. Canonical reference established.

*Reference documents: `ADMISSION_DOCUMENT_SERVICE_PHASE1_IMPLEMENTATION.md`, `…_CERTIFICATION.md`, `…_CONCURRENCY_HARDENING.md`, `ADMISSION_DOCUMENT_OWNERSHIP_ADR.md`.*
