# Student Document Management — Implementation Report (first Document Service consumer)

**Objective:** Make the Student "Documents" experience the first production consumer of the (frozen) Canonical Document Service.
**Status:** Implemented, verified, regression-audited. **Uncommitted — stopping before commit.**
**Scope guard:** Document Service (APIs / schema / config / concurrency) **unchanged**. No change to Admission, Parent App, Teacher App, Fees, HR, Hostel, Transport, or Certificates.
**Date:** 2026-07-22

---

## 1. Audit — the Student module before this phase

- **`Sis::documents($userId)`** → `views/sis/documents.php`: a basic page — free-text label, thumbnail grid, **View + Delete only**.
- **`Sis::upload_document()`**: raw `firebase->uploadFile` to `schools/{schoolId}/students/{userId}/documents/{label}`; writes the `documents`+`Doc` map (`{label:{url,thumbnail,uploaded_at,bytes}}`); per-student **quota** (`_check_doc_quota`, DM6). **No verification, no versioning, no reference counting, no per-document audit.**
- **`Sis::delete_document()`**: removes Storage object + map entry.
- **`_uploadStudentFile()`** is a *separate* engine used only by `edit_student` (Birth/Aadhaar/Transfer + photo).
- **Consumers of student `Doc[]`** (verified via full-repo survey): `_getStudent`/`_normalizeStudentDoc` (pass-through, no whitelist), `documents.php`, `student_profile.php` (a secondary Documents tab), the student-list row thumbnail, `profile()` JSON, `id_card.php`. **Parent/Teacher apps read the `Doc[]` map directly.**
- **`documentRefs` on `students` is entirely unused** today (only `crmApplications` uses it) → safe to introduce; every student writer *merges*, so it is never clobbered.

## 2. Commercial-ERP comparison

Fedena / OpenSIS / PowerSchool / Vidyalaya / Classe365 provide, beyond raw upload: predefined document **types** + custom, an admin **verification workflow** (pending→verified/rejected), **status badges**, **version/replace**, download + preview, a **per-document audit trail**, and a mandatory-document **completeness** signal. The old page had none of the workflow layer — exactly what the Document Service supplies. This implementation brings Student Documents to that commercial baseline (type slots, verification, badges, versioned replace, download/preview, audit history). *Not in this phase (future):* document expiry dates and a mandatory-document checklist/percentage.

## 3. Design

**Consumer pattern (per canonical doc §13), Document Service unchanged:**
- New uploads → `docsvc->upload(type)` + `docsvc->link('student', studentId, role)`; lifecycle actions → `docsvc->verify/replace/delete`.
- **Data on `students/{id}` (additive):** a new `documentRefs[]` denormalized pointer (`{documentId,type,label,url,category,verificationStatus,version,uploaded_at}`) drives the admin UI; the legacy `documents`/`Doc` map is **kept in sync** (url + `documentId`) so the **Parent/Teacher apps keep displaying documents with no app change**.
- **Predefined single-slot:** re-uploading a predefined type (Aadhaar / Birth / Transfer / Marksheet / Medical) **replaces** it (new version); `additional` always adds a new document.
- **Backward compatibility:** existing `Doc[]` entries (and enrollment-carried documents) render as **read-only "Legacy" cards** (view/download) — no migration. Re-uploading brings a type under the service.
- **Reused, not rebuilt:** the existing per-student **quota** (`_check_doc_quota`), `_log_history`, `_getStudent`, role gates, and `json_*` helpers. **No new upload engine or Storage logic** — the Document Service owns bytes.

## 4. Implementation (3 files; Student module only)

| File | Change |
|---|---|
| `application/controllers/Sis.php` | **Added** (pure additions — no existing line changed): `student_upload_document`, `verify_student_document`, `replace_student_document`, `delete_student_document`, `student_document_audit`, + helpers `_student_doc_types` / `_student_doc_label` / `_student_doc_owned` (ownership guard) / `_student_sync_docref` (pointer + legacy-map sync). Tenant via `school_name`; audit via `_log_history(school_id, …)` so entries are findable by the existing `studentHistory` reader. |
| `application/config/routes.php` | **Added** 5 routes `sis/{student_upload_document, verify_student_document, replace_student_document, delete_student_document, student_document_audit}`. |
| `application/views/sis/documents.php` | **Rebuilt** into the professional experience (below). Renders `documentRefs` managed cards + legacy cards; typed upload; audit history panel. |

**Untouched:** `Document_service.php`, `document_service.php` (config), all Admission code, `firestore.rules`/indexes (documents/documentLinks already server-only; `documentRefs` is additive on students and needs no rule), `student_profile.php` (keeps working via the synced `Doc[]` map).

## 5. Required capabilities — how each is delivered

| Capability | Delivery |
|---|---|
| **Preview** | Image documents render as thumbnails; PDFs/others show a typed icon (managed + legacy cards). |
| **Download** | Tokenized `downloadUrl` opens in a new tab. |
| **Verify** | Verify ✓ / Reject ✗ → `docsvc->verify` → status persisted on the ref. |
| **Replace** | Per-card Replace → hidden file input → `docsvc->replace` (new version, old object retired by the service). |
| **Delete** | `docsvc->delete('student', …)` — unlinks; purges only if orphaned & retention-eligible (a doc still linked elsewhere, e.g. its admission application, is never destroyed). |
| **Status badges** | verified (green) / rejected (red) / pending (amber) on every managed card; version shown when > 1. |
| **Audit history** | "Document History" panel → `student_document_audit` reads `DOCUMENT_*` events from `studentHistory` (existing composite index — **no new index**), plus the Document Service's own per-action `auditLogs` entries. |

## 6. Backward compatibility

- Existing students' `Doc[]` entries render as read-only **Legacy** cards (view/download); the legacy `upload_document`/`delete_document` endpoints remain intact.
- New service uploads **also** write the `documents`/`Doc` map, so **Parent/Teacher apps continue to show documents unchanged** (they read `Doc[]`; the new `documentRefs` field is ignored by Firestore object mapping).
- **No migration** — existing data is untouched; `documentRefs` is purely additive and every writer merges.
- `_getStudent` pass-through confirmed to preserve `documentRefs`.

## 7. Regression audit

| Check | Verdict |
|---|---|
| Document Service unchanged | ✅ `git diff` on `Document_service.php` + config = empty |
| `Sis.php` — no existing/Admission line changed | ✅ diff shows **pure additions** (no removed lines) |
| Only Student-module surfaces touched | ✅ 3 files: `Sis.php` (add), `routes.php` (add), `sis/documents.php` (rebuild) |
| No out-of-scope module (Admission/Parent/Teacher/Fees/HR/Hostel/Transport/Certificates) | ✅ none touched |
| No new upload engine / Storage logic | ✅ all bytes via `docsvc`; quota reused |
| No new RTDB dependency | ✅ Firestore + Storage only |
| No Firestore rule/index change required | ✅ documents/documentLinks already server-only; `documentRefs` additive; audit reuses the existing index |
| `php -l` | ✅ clean on all 3 files |
| Tenant isolation + authorization | ✅ role-gated (`sis_upload_doc`/`sis_delete_doc`/`sis_documents`) + ownership guard (documentId must be in the student's refs); `docsvc` tenant-checks every read |

**No behavioral regression found.**

## 8. Known limitations / follow-ups (out of this phase's scope)

| Sev | Item |
|---|---|
| LOW | **Enrollment-carried admission documents** land in the student `Doc[]` map *without* a `documentId`, so they render as **Legacy** cards (view/download) rather than fully managed. Bringing them under the student's `documentRefs` would require an Admission-side change (writing student `documentRefs` at enrollment) — deliberately **not** done (Admission is frozen this phase). |
| LOW | The **legacy** `delete_document` (used by Legacy cards) deletes by the stored URL; for an enrollment-carried doc that is actually service-linked, this could remove the object outside the service. This is a **pre-existing** condition from the Admission phase (unchanged here); the managed delete path (`delete_student_document`) is service-correct. |
| LOW (op) | Same `php.ini` `post_max_size`/`upload_max_filesize` note as Admission for large multi-file activity. |

None affects the core Student document workflow.

## 9. Production readiness

| Dimension | Verdict |
|---|---|
| Student Documents is a clean Document Service consumer (service unchanged) | ✅ |
| Preview / Download / Verify / Replace / Delete / Status badges / Audit history | ✅ all delivered |
| Backward compatible; no migration; apps keep working | ✅ |
| Firestore-only; tenant-isolated; role-gated + ownership-guarded | ✅ |
| Reuses quota/history/normalizer; no new engine or Storage logic | ✅ |
| `php -l` clean; pure-additive controller change | ✅ |

**Recommended final gate (operator):** a browser UAT on one student — upload each predefined type + one Additional (verify preview + status badge), verify/reject, replace (version increments), delete (confirm removal + that a shared/enrollment doc is not destroyed), confirm the Parent/Teacher app still shows the documents, and open Document History.

## 10. Commit scope (for when authorized — not done now)

Three files: `application/controllers/Sis.php`, `application/config/routes.php`, `application/views/sis/documents.php` (optionally this report), on `ank-yug_b1`. The unrelated working-tree changes stay out of scope, as before.

*No Document Service change. Nothing committed or pushed — awaiting your review.*
