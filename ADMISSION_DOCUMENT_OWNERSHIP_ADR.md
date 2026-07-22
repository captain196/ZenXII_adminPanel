# ADR-001 — Document Ownership Architecture (revises D1)

**Status:** **RATIFIED & IMPLEMENTED** (Phase 1, Admission) — 2026-07-22. Option **C** adopted; **revised-D1** in force. **AMENDED (A1):** the retained-multi-version proposal in this ADR (`versions[]` / "old retained per policy") is **SUPERSEDED** by the single-live-version model Phase 1 ratified — see Amendment A1 below and §11 of `DOCUMENT_SERVICE_ARCHITECTURE_AND_CERTIFICATION.md`. Supersedes the D1 recommendation in `ADMISSION_DOCUMENT_LIFECYCLE_ARCHITECTURE_REVIEW.md`.
**Question:** How should a Storage object be owned/shared across ZenX modules (Admission, Student, HR, Staff, Hostel, Transport, Certificates …) over a 10+ year horizon — preserving audit history, historical admission records, storage consistency, and maintainability?

---

> ### Amendment A1 — Phase 1 ratifies SINGLE-LIVE-VERSION (2026-07-22)
> Option **C** (Canonical Document Service, unique IDs + reference links) and **revised-D1** (link, not move) are ratified and implemented for Admission. **One part of the "Canonical shape (target)" below is superseded:** the multi-version retention sketch — `currentVersion` + `versions: [...]` and "old version retained per policy" — is **NOT** adopted. Phase 1 ships a **single-live-version** model: `version` is a monotonic counter, and on replace the superseded Storage object is **permanently deleted** (provenance preserved in the immutable audit trail; prior bytes not recoverable). This is the deliberate storage-efficiency choice and is the canonical behavior. Recoverable version history remains an optional, per-module **Phase-2+** enhancement, not a Phase-1 commitment. Authoritative reference: **§11 of `DOCUMENT_SERVICE_ARCHITECTURE_AND_CERTIFICATION.md`.** Everything else in the Canonical shape (identity, links, reference counting, retention, denormalized pointer) stands **as implemented**.

---

## The three candidates

- **A — MOVE ownership:** on hand-off (e.g., enrollment) the bytes are copied to the new module's path and the source is deleted. One physical copy; ownership transfers.
- **B — Shared document reference:** multiple modules store the same raw Storage path/URL. No move, no copy, no coordinator.
- **C — Canonical Document Service (unique Document IDs + reference links):** a first-class `documents/{documentId}` entity is the single source of truth for one Storage object. Modules never see Storage paths; they **link** to a document by ID. The service owns upload, path, URL minting, versioning, verification, audit, retention, and **reference counting**.

---

## Evaluation

| Criterion | A · MOVE | B · Shared raw ref | C · Canonical Doc Service |
|---|---|---|---|
| Duplication | None | None | None |
| Move/copy cost per hand-off | Copy+delete every time (N×M bespoke flows) | None | None (just add a link) |
| Deletion safety (the W4 hazard) | Safe *only* because ownership is exclusive; but every transfer is a risky mutation | **Unsafe** — no owner; deleting in one module breaks others | **Safe** — purge only when link-count = 0 **and** retention elapsed **and** no legal hold, enforced centrally |
| Historical admission record fidelity | Weak — bytes leave the application; you keep a pointer, not the file/version | Fair — path persists but can vanish under you | **Strong** — application keeps a link to the exact document (+ version); file never moves |
| Audit / provenance | Path changes over life → old URLs break; audit spans multiple paths | Ambiguous ownership → weak provenance | **One immutable-ish record per document**; replace = new version (Phase 1: single-live-version, old bytes retired — A1) |
| Coupling between modules | Medium — each pair needs move logic | **High** — modules hardcode each other's Storage paths | **Low** — modules hold only opaque IDs; service is the seam |
| Scale to N modules | Poor — O(N²) transfer paths | Poor — shared mutable state, no coordinator | **Excellent** — one engine; new module = "link", nothing else |
| Content dedup (same file twice) | No | Accidental only | **Yes** — content-hash → one object, many links |
| Storage-backend portability | Locked to path scheme | Locked | **High** — path is internal to the service; swappable |
| Concurrency correctness | Copy+delete race windows | Undefined | Reference counting via CAS/transaction — a solved pattern |
| Up-front complexity | Low | Lowest | **Higher** (new collection + link + refcount) |
| 10-year maintainability | Degrades (bespoke flows accrue) | Degrades fastest (coupling + unsafe deletes) | **Best** — one lifecycle model to reason about |

### Why A and B fail long-term
- **B (shared raw reference)** is the source of the W4 data-loss hazard generalized to every module: with no owner and no reference count, *no one can safely delete anything*, yet everyone can. It also hard-couples modules to each other's Storage layout. Reject.
- **A (MOVE)** is correct only when a document belongs to exactly one module at a time. Real ZenX documents are **multi-tenant across modules**: a single Aadhaar scan is legitimately the Admission record, the Student record, and (later) the Hostel/Transport KYC. MOVE forces a false "one owner" model and turns every cross-module relationship into a bespoke, mutating copy+delete — O(N²) flows and broken historical URLs. It also weakens the *historical admission record*: after enrollment the application no longer holds the file. Reject as the long-term model.

### Why C wins
**C** treats a document as a first-class entity with a stable identity, exactly like a mature DMS (Documentum/Alfresco) or a cloud object-store + metadata catalog. Bytes are written **once**, addressed by `documentId`, and **referenced** — never moved, never duplicated. Ownership becomes *link-set + retention policy*, evaluated in one place, which makes deletion provably safe and audit/history complete. Enrollment stops being a file operation and becomes a metadata operation ("add a Student link to document X"). Every future module is additive.

---

## Recommendation

**Adopt C — the Canonical Document Service with unique Document IDs and reference links — as the target architecture.** It is the only option that scales to N modules over 10+ years while keeping deletion safe, history intact, and modules decoupled.

### Canonical shape (target)
```
documents/{schoolId}_{documentId}            ← single source of truth per file
  { documentId, schoolId, type, mime, sizeBytes, contentHash,
    storagePath: "documents/{schoolId}/{documentId}.{ext}",   // internal to the service
    // ⟵ SUPERSEDED (Amendment A1). Phase 1 ships: version: 3  — a single monotonic
    //    counter, NO versions[] array; the old object is deleted on replace. See §11.
    currentVersion: 3, versions: [ {v, storagePath, uploadedBy, uploadedAt} ],
    verificationStatus, retention: {policy, eligibleAt, legalHold:false},
    linkCount: 2 }

documentLinks/{schoolId}_{documentId}_{module}_{entityId}
  { documentId, module:"admission|student|hr|hostel|...", entityId, role:"aadhaar|photo|...",
    linkedBy, linkedAt, active:true }
```
- Modules store a **denormalized pointer** on their own doc — `{documentId, type, downloadUrl(cached), status}` — so lists/receipts render with no join (Firestore denormalization); the `documents/{id}` record stays authoritative for lifecycle.
- **Replace** = new version on the same `documentId`. ~~(old version retained per policy → admission's historical record is preserved)~~ **SUPERSEDED (Amendment A1):** single-live-version — the old object is **permanently deleted** on replace; provenance is preserved via the immutable audit trail, not recoverable bytes (§11 of the canonical doc). No path churn for referrers; they resolve `documentId`.
- **Delete** = a module `unlink`s (sets `active:false`, decrements `linkCount` via CAS). The service purges the Storage object + record **only** when `linkCount == 0` **and** retention `eligibleAt` passed **and** no `legalHold` — one place, fully audited.
- **Storage path is an internal detail** (`documents/{schoolId}/{documentId}.{ext}`), flat and ID-addressed — no more module-nested paths; backend is swappable.

### Revised **D1**
> **D1 (revised): Enrollment does NOT move or copy files.** It creates a **Student link** to the same canonical document. One physical object, referenced by both the Admission application and the Student. Retention/permanent-delete is governed centrally by link-count + policy — which resolves the W4 hazard *without* the O(N²) move logic and *without* losing the historical admission record.

---

## How Phase 1 stays scoped yet forward-compatible

We do **not** build the full multi-module registry now. Phase 1 (Admission only) implements a **forward-compatible subset** of C:

1. Introduce the `documents/` + `documentLinks/` collections and a thin **Document Service** wrapper over the existing `Firebase` storage helpers — used **only by Admission** in this phase.
2. Admission uploads create canonical documents (ID-addressed path) + an `admission` link.
3. Enrollment adds a `student` link (no move, no copy) — the *only* change vs today's carry-over.
4. Replace/verify/retention/permanent-delete run through the service (reference-counted).
5. Student/HR/Staff/Hostel/Transport remain **untouched**; they read Admission's denormalized pointer exactly as today. Their migration onto the service is **Phase 2+**, separate approval.

Because Phase 1 already models documents as canonical entities with links, Phase 2 is an *extension* (wire more modules' links), **not a rewrite** — the key property MOVE and shared-ref both lack.

### Net effect on the earlier decisions
- **D1** → resolved above (link, not move).
- **D3 (quota on migrated files)** → moot for storage bytes (no second copy); quota, if applied, counts *links*/logical attachments, never re-charges bytes.
- **D2, D4, D5** unchanged and still open.

---

*Original proposal footer (historical): "No code written. Nothing committed or pushed. Please confirm C / revised-D1, and D2/D4/D5, and I will implement Phase 1…"*

**Resolution (2026-07-22):** C / revised-D1 confirmed; D2 = 4 predefined public fields, D4 = 180-day retention, D5 = per-type caps + photo compression. Phase 1 (Admission only) implemented, F1/F2 fixed, F3/F4 concurrency-hardened, verified, and certified — **single-live-version** per Amendment A1. See `DOCUMENT_SERVICE_ARCHITECTURE_AND_CERTIFICATION.md` and the three Phase-1 reports. Still uncommitted, pending the production commit.
