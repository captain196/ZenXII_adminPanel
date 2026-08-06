# Admission Public Form × Document Service — Integration & Regression Report

**Objective:** Complete the Admission module by integrating the deferred public-admission-form redesign + the 4 predefined upload fields with the **already-committed** Document Service (`ef6f1dc`), without changing the service.
**Status:** Integrated in the working tree, verified, regression-audited. **Uncommitted — stopping before commit.**
**Scope guard:** No change to the Document Service, Student, Parent App, Teacher App, HR, Hostel, Transport, or Certificates.
**Date:** 2026-07-22

---

## 1. What this phase does (and does not change)

The deferred work lives entirely in **one file** — `application/views/admission/public_form.php` — which already contains **both** the UX redesign **and** the 4 predefined upload fields (they were authored on top of the redesign earlier and only *deferred from the commit*, not from disk). The backend that consumes them — `Admission_public::_handle_admission_uploads`, the `document_service` config, the CRM view, and the enrollment carry-over — is **already committed and certified** in `ef6f1dc`.

**Therefore no new code was required, and none was written.** This phase is the certification that the public form (redesign + 4 fields) correctly drives the committed Document Service, plus a regression audit. The Document Service and its APIs are **unchanged**.

| File | State | Change |
|---|---|---|
| `application/views/admission/public_form.php` | uncommitted (working tree) | Redesigned UI + `photo`/`aadhaar_card`/`documents[]` + **4 predefined fields** (`birth_certificate`, `transfer_certificate`, `marksheet`, `medical_certificate`) + client validation aligned to the server. |
| Everything else (service, handler, CRM, enrollment, config, rules) | committed `ef6f1dc` | none |

---

## 2. Wiring — public form → existing Document Service (service unchanged)

```
public_form.php  ──(FormData multipart POST)──►  admission/submit
   file inputs: photo, aadhaar_card,               │
     birth_certificate, transfer_certificate,       ▼
     marksheet, medical_certificate, documents[]   Admission_public::_handle_admission_uploads   [COMMITTED]
                                                     │  per file → docsvc->upload(type)  [Document_service, UNCHANGED]
                                                     │           → docsvc->link('admission', appId, role)
                                                     ▼
                                            crmApplications.documentRefs[]  (denormalized pointer)
```
- Submission uses `var fd = new FormData(this)` (line 1005) posted as `body: fd` with **no manual Content-Type** (line 1008-1011) → the browser sets `multipart/form-data` automatically. The absent `enctype` attribute is therefore irrelevant; files are transmitted correctly.
- Each of the 4 field `name=`s maps 1:1 to a predefined `type` in `application/config/document_service.php` (committed) and is processed by the committed handler's `foreach (['birth_certificate','transfer_certificate','marksheet','medical_certificate'])` loop.
- **No `Document_service` method or signature was touched.**

---

## 3. Verification

| Area | Method | Result |
|---|---|---|
| **Upload** | Field `name=`s in the form match the committed handler's expected inputs + the config types exactly; submit is FormData/multipart. Backend upload path independently certified (`document_service_verify run` = 20/20 live). | ✅ |
| **Preview** | Each new field has `id="i_{type}"`, `id="lbl_{type}"`, `id="prev_{type}"`; `pfRenderPreview` renders image thumbnails / typed PDF icons generically (`prev_ + id.slice(2)`) with a remove control; the Step-6 review lists all inputs via `PF_FILE_INPUTS`. | ✅ |
| **Replace** | CRM `replace_admission_document` (committed) operates on the stored `documentId`; independent of the form. | ✅ (committed) |
| **CRM visibility** | CRM renders `documentRefs` (committed): the 4 predefined types display under **"Photo & Documents"**, additional under **"Additional Documents"**, each with verify/replace/delete + status badge. | ✅ (committed) |
| **Enrollment** | `_admission_docs_for_student` (committed) carries the 4 predefined types into student `Doc[]` and creates `student` links (revised D1, no copy). | ✅ (committed) |
| **Legacy admissions** | Applications created before these fields have no such inputs; the handler skips absent files (`isset`), and the form renders regardless. No migration. | ✅ |
| **Firestore-only** | Form posts to `Admission_public` (Firestore + Storage only); no RTDB anywhere in the path. | ✅ |
| **Client/server validation parity** | `PF_MAX` (photo 2 MB, docs 5 MB) and `PF_OK_EXT` (per-type jpg/jpeg/png[/pdf]) mirror `document_service` config exactly — a file passing the browser also passes the server. | ✅ |
| **Lint** | `php -l application/views/admission/public_form.php` | ✅ clean |
| **View-variable contract** | View consumes `school_id/school_profile/session_year/classes` (passed by controller) and derives `displayName/address/logoUrl` from `school_profile` (null-safe, lines 10-12); `cls` is a loop var. | ✅ no unprovided variable |

**Backend upload correctness** was proven end-to-end in Phase 1 (`document_service_verify`): upload → link → verify → replace → unlink → purge, plus tenant isolation and the CAS concurrency proofs. The form change only supplies matching field names into that certified path.

---

## 4. Regression audit — no unrelated Admission behavior changed

Compared the redesigned `public_form.php` against the committed baseline (`git show HEAD:…`):

| Check | Evidence | Verdict |
|---|---|---|
| No form field removed/renamed | `comm -23` of all `name="…"` (HEAD vs current) = **empty**; every data field (`student_name`, `dob`, `class`, `phone`, `email`, `father_occupation`, …) is byte-identical. Additions only: the upload fields + `theme-color` meta. | ✅ |
| No JS element id removed | `comm -23` of all `getElementById('…')` = **empty**. | ✅ |
| Submit/offline/pay/callback endpoints unchanged | `admission/submit`, `admission/choose_offline`, `admission/pay`, `admission/payment_callback` all present and unchanged. | ✅ |
| Payment flow intact | 11 Razorpay references preserved; offline/pay/callback build `new URLSearchParams(...)` (line 1053) exactly as before — only the *file* submit uses `FormData`. | ✅ |
| CSRF preserved | `csrf-name`/`csrf-token` fields present; token appended to every request; server no-store cache headers (committed) intact. | ✅ |
| No CSP breakage | `Admission_public extends CI_Controller` (not `MY_Controller`, whose CSP is admin-only) → no CSP on the public page; the redesign's CDN fonts/icons load, degrading gracefully to system fonts if a network blocks them. | ✅ |
| No shared-library / Document Service change | This phase touches only `public_form.php`. | ✅ |
| No RTDB introduced | Firestore + Storage only. | ✅ |
| No API contract change | New routes/fields are additive; existing endpoints and field names unchanged. | ✅ |
| Out-of-scope modules untouched | No change to Student/Parent/Teacher/HR/Hostel/Transport/Certificates. | ✅ |

**No behavioral regression found.**

---

## 5. Findings

| # | Severity | Finding |
|---|---|---|
| — | — | No Critical/High/Medium code defects. The Document Service is unchanged; the integration is correctly wired. |
| N1 | **LOW (operational, not code)** | **Aggregate upload size.** With all fields populated the worst-case POST is ≈ photo 2 MB + aadhaar 5 MB + 4×5 MB + up to 10×5 MB ≈ **77 MB**. PHP silently drops `$_POST` when `post_max_size` is exceeded → a confusing "submission failed". This is a *pre-existing* consideration (`documents[]` was already multi-file); the 4 new fields marginally raise the ceiling. **Recommend the operator confirm `post_max_size` / `upload_max_filesize` / `max_file_uploads` in `php.ini` accommodate expected submissions.** No code change. |

---

## 6. Production readiness

| Dimension | Verdict |
|---|---|
| Redesigned public form integrated with the committed Document Service | ✅ |
| 4 predefined upload fields present + fully wired (label/preview/validation) | ✅ |
| Service and its APIs unchanged | ✅ |
| Backward compatible (no field renamed; legacy apps need no migration) | ✅ |
| Firestore-only; no RTDB | ✅ |
| Payment/CSRF/submit flows preserved | ✅ |
| Out-of-scope modules untouched | ✅ |
| `php -l` clean | ✅ |

**Recommended final gate (operator):** a browser UAT of one public submission with all six document types attached (upload → CRM detail shows each in its section → replace one → approve → enroll → confirm the student profile carries the files and the links exist), plus a `php.ini` size check per N1. The code integration itself is certified.

---

## 7. Commit scope (for when authorized — not done now)

A single file completes this phase: **`application/views/admission/public_form.php`**. It should be committed on `ank-yug_b1` as the Admission public-form redesign + Document Service upload fields. (Optionally include this report.) The other unrelated working-tree changes remain out of scope, exactly as in `ef6f1dc`.

*No code changed in this phase (integration was already wired). Nothing committed or pushed — awaiting your review.*
