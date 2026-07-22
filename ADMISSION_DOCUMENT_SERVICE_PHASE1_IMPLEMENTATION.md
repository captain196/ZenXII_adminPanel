# Canonical Document Service — Phase 1 Implementation & Certification Report

**Scope:** Admission module only (forward-compatible subset of ADR-001 Option C).
**Status:** IMPLEMENTED — uncommitted, awaiting review. No commit, no push.
**Decisions honored:** C / revised-D1 (link, not move) · D2 = add 4 predefined public fields · D4 = 180-day retention · D5 = per-type caps + photo compression.
**Date:** 2026-07-22

> Phase 1 builds the *minimum* forward-compatible Document Service the Admission module needs today — Firestore-only metadata, Firebase Storage bytes, one physical object per logical document, reference-counted deletion. No module outside Admission was modified. The API is generic so Student/HR/Hostel/Transport/Certificates can adopt it later **without redesign** (Phase 2+, separate approval).

---

## 1. Architecture Summary

A logical document is a **first-class entity** with a stable id, addressed once in Storage and **referenced** by modules — never moved, never copied.

```
        ┌─────────────────────────┐
        │   Document_service      │  upload · replace · delete
        │   (library, generic)    │  link · unlink · verify · getMetadata
        └───────────┬─────────────┘
                    │  service-account (Admin SDK, bypasses client rules)
        ┌───────────┴───────────┐
        ▼                       ▼
  Firebase Storage        Firestore (metadata ONLY)
  documents/{school}/     documents/{school}_{docId}       ← 1 record per file
    {docId}_v{n}.{ext}    documentLinks/{...}_{module}_{entity}  ← refs (refcount)
```

- **Modules never see Storage paths.** They hold an opaque `documentId` plus a **denormalized pointer** (`{documentId, type, label, url, verificationStatus}`) cached on their own doc, so lists/receipts render with **no join and no extra read**.
- **Ownership = link-set + retention policy**, evaluated in one place → deletion is provably safe.
- **Enrollment is a metadata operation** ("add a `student` link"), not a file operation. One physical object is referenced by both the Admission application and the Student.

### Files (this phase)
| File | Change |
|---|---|
| `application/libraries/Document_service.php` | **NEW** — the service (upload/replace/delete/link/unlink/verify/getMetadata). |
| `application/config/document_service.php` | **NEW** — per-type policy, caps, retention, deny-list, optimization. |
| `application/controllers/Admission_public.php` | Public upload routed through the service; 4 new predefined types; enriched `documentRefs`. |
| `application/controllers/Sis.php` | Enrollment `link()` (revised D1); 3 role-gated CRM endpoints + ownership guard. |
| `application/views/admission/public_form.php` | 4 new optional predefined fields; per-type client validation aligned to server. |
| `application/views/admission_crm/applications.php` | Managed document cards (verify/replace/delete) rendered from `documentRefs`. |
| `application/config/routes.php` | 3 routes for the document endpoints. |
| `firebase-rules/firestore.rules` | Server-only deny rules for `documents` + `documentLinks`. |
| `application/controllers/Document_service_verify.php` | **NEW** — CLI end-to-end lifecycle verifier (reusable). |

---

## 2. Firestore Schema (metadata is the sole source of truth — no RTDB)

**`documents/{schoolId}_{documentId}`** — one record per physical file:
```jsonc
{
  documentId, schoolId, type, label, category,          // identity
  mime, ext, sizeBytes, storagePath, downloadUrl,       // bytes
  version,                                               // replace bumps this
  verificationStatus: "pending|verified|rejected", verifiedBy, verifiedAt,
  uploadedBy, uploadedAt, replacedAt,
  linkCount: <int>,                                      // reference count
  retention: { policy:"default", eligibleAt:<iso|null>, legalHold:false }
}
```

**`documentLinks/{schoolId}_{documentId}_{module}_{entityId}`** — one per reference:
```jsonc
{ documentId, schoolId, module:"admission|student|…", entityId, role,
  active:true, linkedBy, linkedAt, unlinkedAt?, relinkedAt? }
```

**Denormalized pointer** (cached on the owning doc — e.g. `crmApplications.documentRefs[]`):
```jsonc
{ documentId, type, role, label, url, category, verificationStatus }
```
The `documents/{id}` record stays authoritative for lifecycle; the pointer exists purely so readers avoid a join.

---

## 3. Storage Layout

```
documents/{schoolId}/{documentId}_v{version}.{ext}
```
- **Flat, ID-addressed, module-agnostic.** No module-nested paths → backend is swappable and no path churn when a referrer set changes.
- **Extension derived from validated MIME**, not client input.
- **Replace** writes `_v{n+1}` then deletes `_v{n}` → exactly one live object per logical document.
- Tenant is encoded in the path (`{schoolId}/`) and re-checked in metadata on every read.

---

## 4. Document Service API (generic; wired to Admission only this phase)

| Method | Contract |
|---|---|
| `upload(tmp, name, type, meta)` | Validate → optimize (images) → store one object → write metadata (`linkCount=0`). Returns pointer. |
| `replace(docId, tmp, name, by)` | New version on the same id; **upload new → repoint metadata → delete old** (safe order); verification reset. |
| `link(docId, module, entityId, role, by)` | Atomic create-if-absent link (**idempotent**); `linkCount++`; clears retention countdown. |
| `unlink(docId, module, entityId, by)` | Deactivate link; `linkCount--`; when it hits 0, start the retention clock (no purge). |
| `delete(docId, module, entityId, by, force)` | `unlink` then purge **only if** `linkCount==0` **and** no `legalHold` **and** (`force` or retention elapsed). |
| `verify(docId, status, by)` | Set `pending|verified|rejected`. |
| `getMetadata(docId)` | Tenant-checked read of the canonical pointer. |

Every method emits a per-document **audit event** (`auditLogs`, `module:'document_service'`) — upload/replace/link/unlink/verify/purge.

**Correctness rests on three verified Firebase primitives:** `firestoreCreate` (atomic create-if-absent → 409-safe link dedup), `firestoreIncrement` (server-side atomic transform → concurrency-safe refcount), `firestoreUpdate` (nested field-path aware → `retention.eligibleAt` updates without clobbering the map).

---

## 5. Flows

### Upload (public form)
```
Parent submits → _handle_admission_uploads
  per file: docsvc.upload(type)  → validate (ext+MIME+size+deny-list) → optimize photo → store → metadata
            docsvc.link('admission', appId, role)   → linkCount 0→1
  persist crmApplications.documentRefs[] (+ legacy photo_url/aadhaar_url/documents[])
```

### Replace (CRM)
```
Admin picks file → admission_crm/replace_document
  role gate → ownership guard (docId ∈ this application) → docsvc.replace()
    upload _v{n+1} → repoint metadata → DELETE _v{n} → verification=pending
  refresh denormalized pointers (documentRefs url + photo_url/aadhaar_url/documents)
```

### Link / Unlink (enrollment = revised D1)
```
Enrollment (Sis::save_admission):
  for each lead documentRef: docsvc.link('student', studentId, role)   ← NO copy, NO move
  student Doc[] also populated (denormalized display) for backward compat
Delete a lead doc:
  docsvc.delete('admission', appId, force=false)
    unlink → if still linked by an enrolled student → refcount 1 → NOT purged (W4 resolved)
             if orphaned → retention clock starts (180d); purge later via force/reconciler
```

### CRUD summary
Create = `upload`+`link`; Read = pointer / `getMetadata`; Update = `replace` (bytes) / `verify` (status); Delete = `unlink`→conditional purge.

---

## 6. Security Review

| Control | Status |
|---|---|
| Extension allow-list (per type) | ✅ server-side |
| MIME via `finfo` (must match type) | ✅ mandatory |
| Hard deny-list (`svg/svgz/html/js/php/exe/bat/…` + `image/svg+xml`,`text/html`) | ✅ any type, ext **and** MIME |
| Per-type size caps (photo 2 MB · docs 5 MB) | ✅ config-driven (D5) |
| `is_uploaded_file()` on browser uploads | ✅ (internal temp files opt-out for replace/verifier) |
| Tenant isolation | ✅ `{schoolId}_` doc/link ids + stored-schoolId re-check on every read; cross-school read returns null |
| Ownership guard (CRM actions) | ✅ documentId must be referenced by the target application |
| Role gating | ✅ `_require_role(MANAGE_ROLES, …)` on verify/replace/delete |
| Download authorization | ✅ tokenized `firebasestorage.googleapis.com` URLs, CSP-allow-listed |
| Client-collection exposure | ✅ `documents`/`documentLinks` **deny-all** in rules (server-only; apps read the pointer, never these) |
| Extension spoofing | ✅ stored ext derived from validated MIME, not filename |

**Verified isolation:** the CLI verifier confirms a service scoped to `{schoolId}_OTHER` cannot read another school's document (returns null).

---

## 7. Storage Efficiency Review + Consumption Comparison

### Levers the canonical service adds
1. **Photo optimization** — GD downscale to 1200px long edge + JPEG q82. A typical 2.5 MB phone photo → ~150 KB (**~16×**).
2. **Single physical object + references** — enrollment (and every future module) *links*; it never copies bytes. No per-hand-off duplication.
3. **Orphan-free replace** — old version deleted on replace; no accumulation.
4. **Reference-counted, retention-governed purge** — reclaims rejected-application bytes safely.

### Per-student model (typical uploaded mix)
| Item | Raw | Canonical |
|---|---|---|
| Photo | 2.5 MB | 0.15 MB (optimized) |
| Aadhaar | 0.4 MB | 0.4 MB |
| Birth certificate | 0.4 MB | 0.4 MB |
| 1 additional (TC/marksheet) | 0.4 MB | 0.4 MB |
| **Per logical set** | **3.7 MB** | **1.35 MB** |

Three implementations compared (per **enrolled** student, who exists as both an application and a student record):

| Implementation | Bytes / enrolled student |
|---|---|
| **A · Naive ERP** (unoptimized + copy-on-enroll, i.e. MOVE/shared-copy family) | 3.7 (admission) + 3.7 (student copy) = **7.4 MB** |
| **B · Pre-Phase-1 ZenX** (reference-reuse, unoptimized, no replace) | **3.7 MB** |
| **C · Canonical Phase 1** (link + optimized + orphan-free) | **1.35 MB** |

### Scaled projections
| Scope (≈students) | A · Naive | B · Pre-Phase-1 | **C · Canonical** | C saves vs A | C saves vs B |
|---|---|---|---|---|---|
| 1 school · 1,000 | 7.4 GB | 3.7 GB | **1.35 GB** | 6.05 GB (82%) | 2.35 GB (63%) |
| 1 school · 5,000 | 37 GB | 18.5 GB | **6.75 GB** | 30.3 GB (82%) | 11.8 GB (63%) |
| 100 schools · 100,000 | 740 GB | 370 GB | **135 GB** | **605 GB (82%)** | **235 GB (63%)** |

At Firebase Storage ~$0.026/GB-month, 100 schools ≈ **$3.5/mo (C)** vs $9.6/mo (B) vs $19.2/mo (A). The 82% vs A is the number that matters long-term: **A multiplies with every module** (the same Aadhaar copied for Admission + Student + Hostel + Transport = 4×), while **C stays at 1×** no matter how many modules link it. That divergence is the 10-year win.

> Assumptions are explicit and conservative (one optimized photo + three ~0.4 MB documents; ~100% enroll for worst-case). Schools with heavier document mixes shift all rows up proportionally; the **ratios hold**.

### Further optimization opportunities (not built — future phases)
- **Content-hash dedup** — `contentHash` → identical re-uploads become 0-byte (many links, one object). Field is reserved in the design; not yet enforced.
- **WebP/AVIF photo encode** — ~25–35% below JPEG q82 at equal quality.
- **PDF normalization** — flatten + downsample embedded images (docs are the largest remaining bucket).
- **Orphan reconciler cron** — auto-reclaim rejected-app bytes past the 180-day window (Phase 1 starts the clock; reclamation today is via explicit force-purge).
- **Nearline/Coldline tier** for archived (past-retention-grace) documents — ~50% cheaper per GB.

---

## 8. Performance Analysis

- **Reads minimized by denormalization.** The CRM list/detail renders documents from the cached `documentRefs` pointer — **zero** extra Firestore reads per document. `getMetadata` is used only for lifecycle actions, not display.
- **Upload:** 1 Storage write + 1 metadata write + 1 link (create + increment) per file — the irreducible minimum.
- **Enrollment (revised D1):** per document → `link()` = 1 read + 1 create + 1 increment + 1 retention-clear. Bounded by document count (typically 2–6), and it **replaces** no prior read; net cost is a handful of round-trips at a once-per-student event. No copy/upload of bytes (the expensive part is gone).
- **Replace/verify/delete:** each is one app read (already needed for the ownership guard) + the service ops + one pointer-refresh write. No N+1, no scans.
- **Refcount uses server-side atomic increments** (not read-modify-write) → correct under concurrency with no extra reads.
- **No composite queries in Phase 1** → no indexes required (all access is by deterministic key). A future reconciler will add the one index it needs then.

---

## 9. Backward Compatibility Report

- **Legacy fields preserved.** `photo_url`, `aadhaar_url`, `documents[]` are still written with the same shape. Every existing reader (CRM, enrollment carry-over, older records) works unchanged.
- **Old leads (no `documentRefs`) fully supported.** The CRM view falls back to read-only tiles from `*_url`; enrollment carry-over still copies URLs by reference. Nothing became invalid; no re-upload required by any school.
- **Student `Doc[]` shape unchanged.** New predefined types add labels to the same `{label:{url,thumbnail}}` map — additive; the Parent/Teacher apps read `Doc[]` and are unaffected (new labels render or are ignored; no schema break).
- **No migration.** New documents adopt the canonical model going forward; existing documents keep working as-is.

---

## 10. Regression Report

- **`php -l` clean** on all changed PHP (`Admission_public.php`, `Sis.php`, `routes.php`, `Document_service.php`, `document_service.php`, `Document_service_verify.php`).
- **Cross-module trail verified:** `documentRefs` is read only by the CRM view; `documents`/`documentLinks` are touched only by the service; **no Api/parent/teacher controller reads `crmApplications`** → the Kotlin apps are unaffected (they read student `Doc[]`, unchanged).
- **Client/server validation aligned:** fixed a latent mismatch — the service's photo policy allows `webp`/not `gif`, while the old client still accepted `gif`; the public form now mirrors the server per-type allow-lists and caps, so a file that passes the browser also passes the server (and vice-versa).
- **W4 hazard resolved:** deleting a lead's document never breaks an enrolled student — refcount keeps the object alive while any module links it (asserted by the verifier).
- **No behavior removed** from `save_admission`; the enrollment link block is additive and guarded (`if documentRefs`).

### CLI verification
```
php index.php document_service_verify run     # env: SCHOOL_ID=<test school>
```
Asserts, self-cleaning: upload → tenant-isolation → link → **dedup** → student-link → verify → **replace (version bump)** → unlink (refcount holds) → delete+force (purge) → gone. Exits non-zero on any failure.

---

## 11. Production Readiness Assessment

| Dimension | Verdict |
|---|---|
| Firestore-only, no RTDB | ✅ |
| Tenant isolation | ✅ path + docId + stored-schoolId re-check + deny-all rules |
| One physical object per logical document | ✅ ID-addressed, replace-in-place |
| Orphan prevention | ✅ replace deletes old; refcount + retention govern purge |
| Backward compatible / no migration | ✅ legacy fields + old-lead fallback |
| Security (MIME/ext/size/deny/role/ownership) | ✅ |
| Storage efficiency | ✅ optimization + references (82% vs naive, 63% vs pre-Phase-1) |
| Performance (reads/writes) | ✅ denormalized display, atomic refcount, no N+1/scans |
| Forward-compatible for Phase 2 modules | ✅ generic API; new module = "link", no core change |
| Audit coverage | ✅ per-document events + CRM `log_audit` |

### Known Phase-1 boundaries (by design, not defects)
- **Purge reclamation** of orphaned/rejected documents is via explicit force-delete today; the automatic **orphan reconciler cron** is Phase 2 (the retention clock already starts on last-unlink).
- **Content-hash dedup, WebP/AVIF, PDF normalization** are reserved optimizations, not yet enforced.
- **Student/HR/Hostel/Transport/Certificates** remain untouched — they adopt the service in Phase 2+ (separate approval).

---

*No commit. No push. No module outside Admission modified. Awaiting your review before Phase 2.*
