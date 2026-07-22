# Admission Document Lifecycle — Architecture & Design Review

**Status:** DESIGN ONLY — no code, no implementation, no commit. Awaiting approval.
**Scope of this review:** Admission module only. The design is drawn so it can *later* become the canonical **ZenX Document Service** (Student, Staff, HR, Transport, Certificates), but **no other module is touched or refactored in this phase.**
**Author:** Architecture review · **Date:** 2026-07-21

> This document reviews the *current* Admission document implementation, exposes its gaps, and proposes a canonical lifecycle, storage layout, metadata model, replace/retention workflows, security posture, and a phased roadmap. Decisions that need your sign-off are called out in **▶ DECISION** blocks.

---

## 1. Current Architecture Review

### 1.1 What exists today (as-built)

**Public upload path** — `Admission_public::_handle_admission_uploads($schoolId, $appId)`
- Inputs: `photo` (images), `aadhaar_card` (image/PDF), `documents[]` (multi, image/PDF/DOC/XLS).
- Validation: extension allow-list + `finfo` MIME check + `is_uploaded_file()` + **1 MB** cap; `documents[]` capped at 10.
- Upload: `Firebase::uploadFile($tmp, "admission/{schoolId}/{appId}/{prefix}_{safeName}")` (service-account write → bypasses client Storage rules; works on blocked networks).
- Persist: on `crmApplications/{schoolId}_{appId}` → `photo_url`, `aadhaar_url`, `documents[] = [{name,url,category}]`.

**Admin visibility** — `Sis::get_application()` returns the full doc; `admission_crm/applications.php` renders photo thumbnail + Aadhaar/document links in the detail modal.

**Enrollment carry-over** — `Sis::save_admission()` reads the lead doc server-side and copies `photo_url → Profile Pic + Doc['Photo']`, `aadhaar_url → Doc['Aadhar Card']`, each `documents[] → Doc[<name>]` **by reference** (stores the admission Storage URL on the student). A fresh upload by the admin wins over the carried-over file.

**Storage/lifecycle primitives already available** — `Firebase::uploadFile()`, `getDownloadUrl()` (tokenized `firebasestorage.googleapis.com` URL), `deleteStorageFile()`, `copyStorageFile()`.

**Student-side (for contrast, NOT in scope to change now)** — `Sis::_uploadStudentFile()` writes to `schools/{schoolId}/students/{studentId}/{profile|documents}/{label}_{ts}_{rand}.{ext}`, generates thumbnails, enforces a per-student **quota** (DM6), validates MIME. Fixed `Doc` slots: *Birth Certificate, Aadhar Card, Transfer Certificate, Photo*.

### 1.2 Strengths
- ✅ Firestore-only for metadata; **Firebase Storage** for bytes (no RTDB) — aligned with the ZenX hard rule.
- ✅ Server-side, service-account uploads → resilient on restricted parent networks; independent of client Storage rules.
- ✅ Real MIME validation (`finfo`), not just extension; `is_uploaded_file()` guard; size cap; tenant-scoped path (`{schoolId}` in the key).
- ✅ Enrollment already *reuses* the same Storage object (no re-upload) — the direction the spec wants.
- ✅ Tokenized download URLs are CSP-allowed for the admin panel (`img-src *.googleapis.com`).

### 1.3 Weaknesses / inconsistencies / technical debt
| # | Issue | Impact |
|---|---|---|
| W1 | **Two upload engines** — admission uses `Firebase::uploadFile` directly; students use `_uploadStudentFile` (thumbnails + quota + different rules). | Divergent behavior; no single source of truth. |
| W2 | **Divergent Storage layouts** — `admission/{school}/{app}/` (flat) vs `schools/{school}/students/{student}/` (nested). | Migration mismatch; hard to reason about ownership. |
| W3 | **Divergent constraints** — admission: 1 MB, allows `gif/doc/docx/xls/xlsx`; student: quota-based, allows `webp` but **not** `gif/doc/xls`. | A valid admission `.xls/.gif` cannot become a valid student doc; silent drift. |
| W4 | ⚠️ **Shared-file retention hazard** — enrollment stores the *admission* URL on the student. The student's file physically lives under `admission/{app}/`. A later "permanent delete" of that (rejected/archived) application would **break an enrolled student's documents**. | **Data-loss risk.** Must be resolved by the canonical design. |
| W5 | **No replace workflow** — re-uploading doesn't delete the old Storage object; there is no in-application replace at all. | Orphan files; no "one active document" guarantee. |
| W6 | **No per-document audit** — only a submission-level `public_application` audit exists. No upload/replace/delete/migrate/purge events. | Fails the spec's audit requirement; weak forensics. |
| W7 | **No verification state** — documents have no `verified/pending/rejected` status; the app only has a coarse `stage=document_collection`. | No document-level review workflow. |
| W8 | **Naming keeps original filename** (`{prefix}_{safeName}`) rather than a canonical type name. | Duplicate-type files can co-exist; harder cleanup. |
| W9 | **No orphan detection / cleanup** job. | Storage drifts over time. |
| W10 | **Public form** only has *Photo, Aadhaar, and a generic multi-file picker* — no dedicated Birth Certificate / Transfer / Marksheet fields (those exist only on the admin student form). | Spec Part 4/5 (per-field vs Additional) not fully expressible. |

---

## 2. Canonical Admission Document Lifecycle

```
 Parent Upload ──▶ Admission Application ──▶ Admission CRM Review ──▶ Document Verification
                                                                            │
                                              ┌─────────────────────────────┤
                                              ▼                             ▼
                                          Approved                      Rejected
                                              │                             │
                                              ▼                             ▼
                                   Enrollment (ownership transfer)     Archived (retained)
                                              │                             │
                                              ▼                             ▼
                                     Student Documents              Retention window (N days)
                                                                            │
                                                                            ▼
                                                              Permanent Delete (explicit action)
```

**Document state machine (per document, not per application):**

`uploaded` → `verified` | `rejected(doc)` → (on app approval) `migrated` → (student-owned)
`uploaded` → `replaced` (old object purged, new becomes `uploaded`)
Application `rejected` → documents become `archived` → after retention → `purged`

Each transition emits an **audit event** (see §4, §9).

---

## 3. Storage Architecture (canonical)

### 3.1 Proposed canonical layout
```
admission/{schoolId}/{applicationId}/
    photo.jpg                     ← canonical type name (not original filename)
    aadhaar.pdf
    birth_certificate.pdf
    transfer_certificate.pdf
    marksheet.pdf
    medical_certificate.pdf
    additional/
        {slug}_{shortid}.pdf      ← non-predefined docs
    _trash/                       ← optional: replaced objects held briefly before purge
```

After **enrollment**, ownership moves to the canonical student prefix (already used by `_uploadStudentFile`):
```
schools/{schoolId}/students/{studentId}/
    profile/photo.jpg
    documents/aadhaar.pdf
    documents/birth_certificate.pdf
    documents/additional/{slug}_{shortid}.pdf
```

### 3.2 Naming convention
- **Predefined types** → fixed canonical filename (`photo.jpg`, `aadhaar.pdf`, …). One file per type ⇒ replace overwrites logically (old object deleted first).
- **Additional** → `{sanitized-slug}_{6-char-id}.{ext}` under `additional/`.
- Extension derived from validated MIME, not client input.

### 3.3 Replace / orphan prevention
- Because predefined types map to a **single canonical path**, replace = *delete old object → upload new → update metadata* (atomic-ish; see §6). No accumulation of same-type files.
- A nightly (or on-demand) **reconciler** cross-checks `crmApplications.documents[].storagePath` against actual Storage objects under the app prefix; anything in Storage with **no metadata pointer** = orphan → logged, then purged after a grace period.

### 3.4 Cleanup strategy
- **Replace:** old object deleted immediately (or moved to `_trash/` and purged by the reconciler).
- **Rejected app:** nothing deleted at rejection; purge only at explicit Permanent Delete after retention (§8).
- **Enrolled app:** files **moved** to the student prefix (§7); the application keeps pointer metadata but owns no live bytes.

**▶ DECISION D1 — Enrollment file handling.** Recommend **MOVE (copy→verify→delete source)** so exactly one physical copy exists and the retention hazard (W4) disappears. Alternative: keep shared references and forbid permanent-delete once enrolled. *I recommend MOVE.*

---

## 4. Firestore Metadata Model (canonical)

Per-document metadata, stored on the application (and mirrored to the student on migration). Proposed canonical shape:

```jsonc
// crmApplications/{schoolId}_{appId}.documents[]  (and student Doc[] post-enrollment)
{
  "type": "aadhaar",                 // enum: photo|aadhaar|birth_certificate|transfer_certificate|marksheet|medical_certificate|additional
  "label": "Aadhaar Card",           // display name
  "category": "predefined",          // predefined | additional
  "storagePath": "admission/SCH_x/APP_y/aadhaar.pdf",
  "downloadUrl": "https://firebasestorage.googleapis.com/...&token=...",
  "mime": "application/pdf",
  "sizeBytes": 84213,
  "verificationStatus": "pending",   // pending | verified | rejected
  "verifiedBy": null,                // adminId
  "verifiedAt": null,                // ISO-8601
  "uploadedBy": "public|{adminId}",
  "uploadedAt": "2026-07-21T09:12:00Z",
  "replacedAt": null,                // set when a prior object was superseded
  "migratedToStudent": false,        // true once moved to student ownership
  "auditRef": ["auditLogs/SCH_x_LOG_..."]
}
```

- The **application doc** stays the single source of truth for admission-phase documents; the **student doc** becomes source of truth post-migration. No third store.
- `verificationStatus` enables the per-document review workflow (§2) without adding a collection.

---

## 5. Document Categories & the "same-field" rule

**Predefined types** (always render in their own field, before & after upload, with *Preview / Replace / Download / Delete*):
- Student Photo · Aadhaar · Birth Certificate · Transfer Certificate · Marksheet · Medical Certificate

**Additional** (no predefined field) → collected under **Additional Documents** with the same actions.

**Rule:** `type ∈ predefined-set` ⇒ the file must appear in *that* field; it must **never** be duplicated into the Additional section. Routing is decided by `type`/`category` at upload time (the input's field name determines the type; the generic picker maps to `additional`).

**▶ DECISION D2 — Public-form fields.** Today the *public* form has Photo + Aadhaar + a generic picker only. To honor Part 4 end-to-end, Phase 1 should add dedicated public fields for **Birth Certificate, Transfer Certificate, Marksheet, Medical Certificate** (all optional). Confirm the required/optional matrix per class or globally.

---

## 6. Replace Workflow (exact sequence)

```
1. Validate new file (type, MIME, size, tenant, permission).
2. Read current metadata for that document `type`.
3. Upload new bytes to the canonical path (temp suffix if same path).
4. On success: delete the OLD Storage object (or move to _trash/).
5. Update Firestore metadata: new storagePath/downloadUrl/mime/size, replacedAt=now,
   verificationStatus reset to 'pending'.
6. Write audit event: {action:'replace', type, oldPath, newPath, by, at, appId}.
7. If step 3/4 fails → keep old metadata; surface a clean error (no partial state).
```
Guarantees: **one active object per predefined type**, no orphans, audited, verification re-triggered.

---

## 7. Approval & Enrollment (Admission → Student Documents)

**On enroll (single physical copy, no re-upload):**
```
For each application document:
  - MOVE bytes: copyStorageFile(admissionPath → studentPath) then deleteStorageFile(admissionPath)   [D1=MOVE]
  - Write student Doc metadata pointing at the studentPath + fresh downloadUrl
  - Mark application doc.migratedToStudent = true (keeps a historical pointer, owns no live bytes)
  - Audit: {action:'migrate', type, from, to, appId, studentId, by, at}
Fresh admin upload during enrollment always supersedes the carried-over file (existing behavior preserved).
```
This resolves **W4** (no shared live bytes), satisfies "reuse the same file / no duplicate upload", and gives the Student module clean ownership under its canonical prefix.

> Note: `_uploadStudentFile`'s per-student **quota** (DM6) must be considered — a MOVE should count against quota or be exempt. **▶ DECISION D3:** exempt migrated admission files from quota, or count them? (Recommend: count, but never *block* an approved enrollment — warn only.)

---

## 8. Rejected Applications — Retention Policy

- **At rejection:** documents are **not** deleted. Application `status='rejected'`, documents `archived`. Files remain readable in the CRM (audit/appeal).
- **Retention window:** configurable, default **180 days** (per-school override via `schools.{id}.admissionDocRetentionDays`). **▶ DECISION D4:** confirm default (90/180/365).
- **Permanent Delete:** explicit, permission-gated admin action only. Removes, in order, and audits each: Storage objects → Firestore document metadata → any cache → the application's doc-pointer fields → writes a terminal `purge` audit (the audit log itself is retained).
- **Never** auto-purge; never purge an application that reached `enrolled` (its files were moved to the student and are student-owned).

---

## 9. Security Review

| Control | Current | Canonical target |
|---|---|---|
| Extension allow-list | ✅ (admission) | ✅ unified list per type |
| MIME via `finfo` | ✅ | ✅ mandatory, server-side |
| Size cap | ✅ 1 MB (admission) | Per-type caps (photo ≤2 MB, docs ≤5 MB) — **▶ DECISION D5** |
| `is_uploaded_file()` | ✅ | ✅ |
| Executable/script block | Partial (allow-list only) | Explicit deny of `svg`/`html`/`js`/double-extension; strip metadata where feasible |
| Virus-safe handling | ❌ none | Optional ClamAV/API hook at upload (flag-gated); at minimum, never serve inline HTML/SVG |
| Tenant isolation | ✅ path + docId `{schoolId}_…` | ✅ enforced in the service layer; reject cross-school `storagePath` |
| Permission checks | Public upload is unauthenticated by design; admin actions role-gated | Replace/Delete/Permanent-Delete → explicit roles; download via tokenized URL only |
| Download URL | Tokenized, CSP-allowed | Keep; consider short-lived signed URLs for sensitive types (future) |

Key hardening for Phase 1: **deny `svg/html` and double-extensions**, per-type size caps, and role-gated replace/delete.

---

## 10. Production Roadmap (phased)

**Phase 0 — Design ratification (this document).** Approve D1–D5. No code.

**Phase 1 — Admission-only canonical lifecycle** (the only build phase now):
1. Introduce an internal **Admission Document Service** wrapper (single upload/replace/delete/migrate API) over the existing `Firebase` storage helpers — *no other module changed.*
2. Canonical Storage naming (§3) + canonical metadata (§4) on `crmApplications`.
3. Public form: dedicated predefined fields + Additional (D2); per-field display with Preview/Replace/Download/Delete.
4. Replace workflow (§6) with orphan-free guarantee + per-document audit (§4).
5. CRM: grouped document view + verify/replace/delete (role-gated).
6. Enrollment MOVE (§7) resolving W4; Student module *reads* the moved files (no student-module refactor — it already reads `Doc[]`).
7. Rejected → archive + retention + Permanent Delete (§8).
8. Orphan reconciler (on-demand first).
9. Full flow verification (parent → purge) + certification report.

**Phase 2+ (future, separate approval)** — promote the Admission Document Service to the **canonical ZenX Document Service**; migrate Student/Staff/HR/Transport/Certificates onto one engine, one layout, one metadata/permission/audit/retention model. **Not in scope now.**

---

## Decisions needed before Phase 1
- **D1** Enrollment file handling: **MOVE** (recommended) vs shared-reference.
- **D2** Add predefined public fields (Birth/Transfer/Marksheet/Medical) — and their required/optional matrix.
- **D3** Quota treatment for migrated files (recommend: count, warn-not-block).
- **D4** Rejected retention default (90/180/365 days).
- **D5** Per-type size caps (e.g., photo 2 MB, docs 5 MB).

*No code has been written. Nothing committed or pushed. Awaiting approval of this design and D1–D5 before any Phase 1 implementation.*
