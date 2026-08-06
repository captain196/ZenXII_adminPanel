# ZenX ERP — Enterprise Document, Certificate, Receipt & PDF Management

## Cross-Platform Production Audit, Gap Analysis & Architecture Review

**Status:** AUDIT ONLY — no code written, no commits. Awaiting your review/approval before any implementation.
**Audited paths:** Admin `C:\xampp\htdocs\ZenX\school` (canonical; Grader retired) · Parent `D:\Projects\SchoolSyncParent` · Teacher `D:\Projects\SchoolSyncTeacher` · Rules `…\ZenX\school\firebase-rules\`.
**Method:** 5 parallel read-only investigators, evidence cited as `file:line`.

---

## 1. EXECUTIVE SUMMARY

**ZenX already has substantial, in many places production-grade, document functionality — but not a *unified document engine*. The truth is nuanced, not "missing."**

Maturity by pillar:

| Pillar | Maturity | One-line verdict |
|---|---|---|
| PDF rendering | **Consolidated (mostly)** | One hardened Dompdf wrapper `Pdf_generator`, but report-card-coupled and bypassed by Accounting for landscape. |
| Fee receipts | **Production-ready core** | Single engine, server-verified Razorpay, atomic tenant-scoped numbering, deferred idempotent accounting — with 2 immutability leaks + unaudited reprint. |
| Certificates / TC | **Fragmented** | **Two rival certificate systems**; the legally-critical TC is **not immutable** on reprint. |
| Report cards | **Strong data, weak issuance** | Firestore-canonical results, 6 templates — but rendered from a **mutable, never-frozen** hybrid; no number, no issuance record. |
| Admission docs | **Mixed** | Paid ADM receipt production-ready; acknowledgement live; final-admission-form & profile-PDF **missing**. |
| ID cards / payslips | **Good** | Student ID + payslip production-ready; one orphaned dead view; external CDN in ID print. |
| Storage strategy | **None (by design)** | **Zero generated PDFs are persisted** — everything is regenerated on the fly. |
| Numbering | **Inconsistent** | 4+ schemes; only Fees + TC are collision-safe; no shared allocator across document types. |
| Verification (QR) | **Attendance only** | Production-grade token helper, but **no certificate/TC verification endpoint exists**. |
| Cross-platform delivery | **Broken authority model** | **Parent app fabricates official fee-receipt PDFs on-device**; apps cannot receive a server-issued PDF today. |
| Firestore-only compliance | **Two violations** | 7 residual RTDB sites in `Result.php`; Parent app still runs live RTDB (Teacher app is clean). |
| Intra-tenant privacy | **One real leak** | `marks`/`results` are readable school-wide by **any parent**. |

**Overall maturity: ~55–60% of a commercial K-12 ERP.** The rendering foundation and fee/accounting spine are genuinely good. What's missing is the *connective tissue*: a document-instance registry, immutability discipline for issued documents, one numbering authority, server-issued delivery to the apps, and QR verification.

---

## 2. CURRENT DOCUMENT INVENTORY

| # | Document Type | Module | Generator | Template | Data Source | Stored/Dynamic | Numbering | Admin | Parent | Teacher | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Fee Receipt (counter) | Fees | `FeeCollectionService` + `Fees::print_receipt` (`Fees.php:3919`) | `views/fees/receipt.php` (HTML print) | Firestore `feeReceipts` | Dynamic | `F{n}` atomic, tenant-scoped | ✓ | client-gen ⚠ | aggregate read | **PARTIAL** |
| 2 | Fee Receipt (online/Razorpay) | Fees | same engine (`Fee_management.php:3110/3908`) | same view | Firestore | Dynamic | same | ✓ | client-gen ⚠ | — | **PARTIAL** (server-verified✓) |
| 3 | Fee / Outstanding Statement | Fees | — | — | — | — | — | partial | — | — | **MISSING** (formal doc) |
| 4 | Admission Paid FEE Receipt (ADM…) | Admission | `Admission_public::fee_receipt` / `Sis::admission_receipt` | `admission/fee_receipt.php` (Dompdf) | Firestore `admissionReceipts` | Dynamic (snapshot) | `ADM[0-9]{4,10}` seq | ✓ | link | — | **PRODUCTION READY** |
| 5 | Admission Acknowledgement Receipt | Admission | `Admission_public::receipt` | `admission/receipt_pdf.php` (Dompdf) | Firestore `crmApplications` **live** | Dynamic | appId | ✓ | link | — | **PARTIAL** (live data) |
| 6 | Admission "Scan to Apply" Poster | Admission | `Sis::admission_poster` | `admission_crm/poster.php` | `schools` | Dynamic | n/a | ✓ | — | — | OK |
| 7 | Final Admission Form / Student Record | SIS | — | — | — | — | — | — | — | — | **MISSING** |
| 8 | Student Profile Printout (PDF) | SIS | `Sis::student_profile` (screen only) | `student_profile.php` | Firestore | Dynamic (HTML) | n/a | screen | — | — | **MISSING** (PDF export) |
| 9 | Student ID Card | SIS | `Sis::id_card` (`Sis.php:2382`) | `sis/id_card.php` (`window.print`) | Firestore + QR token | Dynamic | QR token | ✓ | — | — | **PRODUCTION READY** (CDN caveat) |
| 10 | Student ID Card (legacy) | SIS | — (orphaned) | `views/student_id_card.php` | — | — | — | — | — | — | **LEGACY / dead code** |
| 11 | Staff / Teacher ID Card | Staff | `Staff::teacher_id_card` (`Staff.php:3374`) | `teacher_id_card.php` | Firestore | Dynamic | — | ✓ | — | — | **PARTIAL** |
| 12 | Salary Slip / Payslip | HR/Payroll | `Hr::download_payslip` (`Hr.php:5490`) | `_render_payslip_html` (Dompdf) | Firestore `salarySlips` | Dynamic (snapshot record) | `RUN_/SLIP_` | ✓ (own) | — | — | **PRODUCTION READY** |
| 13 | Report Card | Result | `Result::download_pdf` / print | `result/templates/{classic,cbse,minimal,modern,elegant,professional}` (Dompdf + print) | Firestore `results` (agg) **+ live `marks`** | Dynamic | none | ✓ | data tile | teacher preview | **INCONSISTENT** |
| 14 | Batch Report Cards (ZIP) | Result | `download_batch_pdf` | same | Firestore | Dynamic | none | ✓ | — | — | INCONSISTENT |
| 15 | Marks Statement | Result | (within report card) | — | Firestore | Dynamic | none | — | — | — | **MISSING** (distinct doc) |
| 16 | Merit List / Tabulation / Class Result | Examination | `Examination.php` | `examination/*` (print) | Firestore | Dynamic | — | ✓ | — | — | OK (print) |
| 17 | Admit Card / Hall Ticket | Exam | — (only fee-block toggle) | — | — | — | — | — | — | — | **MISSING** |
| 18 | Bonafide Certificate | Certificates | `Certificates` ctrl | `certificates/index.php` (`window.print`) | RTDB-path + snapshot | Dynamic | `CERT-{yyyy}-{#####}` non-atomic | ✓ | — | view | **PARTIAL** |
| 19 | Character Certificate | Certificates | same | same | RTDB-path + snapshot | Dynamic | same | ✓ | — | view | **PARTIAL** |
| 20 | Custom Certificate | Certificates | same | same | RTDB-path + snapshot | Dynamic | `TPL{####}` | ✓ | — | view | **PARTIAL** |
| 21 | Transfer Certificate (via Certificates `transfer`) | Certificates | `Certificates` ctrl | `certificates/index.php` | RTDB-path | Dynamic | `CERT-…` | ✓ | — | — | **DUPLICATED / redundant** |
| 22 | Transfer Certificate (real workflow) | SIS | `Sis::issue_tc` / `print_tc` (`Sis.php:1447`) | `sis/tc_print.php` (`window.print`) | Firestore `students.TC` + `schools.tcIndex` | Dynamic | `TC-{code}-{YYYY}-{####}` atomic | ✓ | (none) | — | **PARTIAL** (not immutable) |
| 23 | No-Dues Certificate | Fees/SIS | (embedded in TC dues gate) | — | Firestore | — | — | — | — | — | **MISSING** (standalone) |
| 24 | Accounting Books (day/cash/ledger/voucher) | Accounting | `Accounting.php:4204` **raw Dompdf** | `accounting/*` | Firestore | Dynamic (landscape) | voucher no | ✓ | — | — | **PARTIAL / duplicated** |
| 25 | Login-Credentials Handout | SIS/Staff | `Sis`/`Staff` | `import_credentials_pdf.php` (Dompdf) | Firestore | Dynamic | n/a | ✓ | — | — | OK |
| 26 | Events / Communication Circular | Events/Comm | print CSS | `events/circular.php`, `communication/circulars.php` | Firestore | Dynamic | — | ✓ | — | — | OK |

**Classification tally:** Production-ready 5 · Partial 8 · Inconsistent 2 · Duplicated/Legacy 3 · Missing 6.

---

## 3. ARCHITECTURE FINDINGS — how document generation works today

- **One server-side PDF engine: Dompdf 2.x**, wrapped by `application/libraries/Pdf_generator.php` (`render/download/inline/save/batch_download`; A4-portrait **hardcoded** `:400`; chroot `:68`; SSRF-aware remote images). Deliberately chosen over TCPDF/wkhtmltopdf (`:16-18`).
- **Five coexisting rendering mechanisms:** (M1) the `Pdf_generator` wrapper — report cards, payslips, admission receipts, credentials, poster; (M2) **raw `new \Dompdf\Dompdf()` in `Accounting.php:4204`** bypassing the wrapper purely to get **landscape**; (M3) JS `window.print()` pop-ups — ID cards, certificates, TC; (M4) `@media print` CSS in 31 views; (M5) `Dompdf::stream()` (Accounting only).
- **Data is Firestore-canonical** for receipts (`feeReceipts`), results (`results`/`marks`), payslips (`salarySlips`), admission receipts (`admissionReceipts`), TC (`students.TC`/`schools.tcIndex`) — **except** the Certificates controller, which still uses the **RTDB-style path API** (`$this->firebase->get/set` under `Schools/{school}/{session}/Certificates/...`).
- **No document persisted to Firebase Storage.** Every PDF is regenerated on demand. Storage is used only for uploaded media (photos, logos, homework/gallery/story attachments) via `Firebase.php:getStorageBucket/uploadFile/getDownloadUrl`.
- **Branding for PDFs is well-engineered:** `public_url_helper.php` embeds the school logo as a `data:` URI via a 7-day-cached, SSRF-hardened fetch (host-allowlisted to `firebasestorage.googleapis.com`, 3 MB cap, image-byte validation). Parent-facing URLs use `public_base_url()` (prod `www.zenxii.com`), never the request host.

---

## 4. COMMERCIAL ERP COMPARISON

Mature K-12 ERPs (e.g. the class of PowerSchool / Fedena-style / Indian SIS vendors) converge on **one backend Document Service** with these separations — mapped to ZenX today:

| Layer | Commercial norm | ZenX today |
|---|---|---|
| A. Business data | Canonical records | ✓ Firestore (`students`, `feeReceipts`, `results`, `salarySlips`) |
| B. Document definition | A registry of typed documents (`FEE_RECEIPT`, `TRANSFER_CERTIFICATE`) | ✗ No registry — document identity is implicit in each controller |
| C. Template | Versioned templates, branding-aware | ~Partial — report cards have 6 selectable templates + per-school config; other docs hardcode HTML; no versioning |
| D. Data snapshot | Immutable capture at issuance | ~Partial — fee/ADM receipts + Certificates snapshot; **TC and report cards do not** |
| E. Document instance | An "issued document" row w/ number, issuer, timestamp, status | ~Partial — receipts/TC have instance docs; report cards have **none** |
| F. File | Optional stored PDF | ✗ None stored anywhere |
| G. Verification | Number + QR + public verify URL | ✗ QR exists but wired to attendance only; no verify endpoint |
| H. Audit trail | Generate/issue/download/reprint/cancel | ~Partial — issuance audited; **reprints not**; cancellation only for TC/certificates |

**The gap is not rendering — it is B, E, F, G and the *discipline* of D.** ZenX does per-module PDF well; it lacks the unifying instance/registry/verification layer that makes documents behave like official records.

---

## 5. GAP ANALYSIS — what ZenX is missing

1. **No document-instance registry** — there is no single collection that answers "what official documents has this school issued, to whom, with what number, in what state?" TC and receipts each have their own shape; report cards/marks statements have nothing.
2. **No shared template/versioning model** — a template edit cannot be tied to already-issued documents; only report cards have selectable templates.
3. **Immutability is applied unevenly** — correct for fee/ADM receipts (amounts) and Certificates; **absent for TC and report cards**, which re-render from live mutable data against permanent/implied identity.
4. **No storage strategy** — no stored PDF for even legally-significant documents (TC, payslips, receipts); no archival/retention policy.
5. **No verification layer for documents** — the QR infrastructure exists and is production-grade but only attests attendance ID cards.
6. **Numbering is fragmented** — Fees (`F{n}`) and TC (`TC-…`) are atomic; Certificates (`CERT-…`) is non-atomic/race-prone; report cards have none; a documented `Numbering_service` exists but is unwired for documents.
7. **Cross-platform delivery is inverted** — the Parent app **generates** official receipts on-device; the correct model (server issues → apps display) is not wired because receipt/result docs carry no `pdfUrl`.
8. **Two Firestore-only violations** block a clean story: `Result.php` (7 RTDB sites) and the Parent app (live RTDB SDK + fallbacks); the Certificates controller also uses the RTDB-path API.

**Root cause:** ZenX grew module-by-module, each solving *its own* print/PDF need well, with no horizontal "document" abstraction ever introduced. This is scattered + partially duplicated functionality, **not** absent functionality.

---

## 6. DUPLICATION ANALYSIS

- **Two Transfer-Certificate systems** — `Certificates` controller's `transfer` type (RTDB-path, non-atomic number, no dues gate, no roster/auth side-effects) vs the real `Sis::issue_tc` workflow (Firestore, atomic number, dues gate, roster removal, Auth disable). A TC issued from the Certificates tab is dangerously under-powered. **→ Retire the Certificates `transfer` type.**
- **Accounting re-implements Dompdf** (`Accounting.php:4204-4218`) instead of using `Pdf_generator`, solely for landscape. **→ Add an orientation/margins parameter to the wrapper and fold it back.**
- **Two student ID-card views** — `sis/id_card.php` (live) and orphaned `views/student_id_card.php` (zero references). **→ Delete the orphan.**
- **Two receipt-number allocators** — the live `Fee_firestore_txn::nextCounter` and a dead `Fee_management::_nextReceiptNo()` (`:330`, no callers). **→ Delete the dead copy.**
- **Two branding-load philosophies** — data-URI embed (PDF paths) vs direct-URL + `onerror` (browser-print paths). Acceptable, but should be one policy.
- **Stale comments** referencing `qrserver.com`/server-side QR PNGs in `receipt_pdf.php:15` and `sis/id_card.php:408-410` (code actually renders local SVG QR).

---

## 7. SECURITY FINDINGS

| Severity | Finding | Evidence |
|---|---|---|
| **HIGH** | **`marks` & `results` readable by any parent school-wide.** Rules gate reads with `isSameSchool()` not `isStaffOrOwnStudent()` → every parent can read every student's exam data (PII). Fees are correctly child-scoped by contrast. | `firestore.rules:639-648` vs `:689-705` |
| **HIGH** | **Parent app fabricates official fee receipts on-device** (signature boxes, GSTIN, logo) from Firestore primitives with no server-issued/verifiable artifact; a tampered APK could render arbitrary amounts. | `ReceiptPdfGenerator.kt:38-289` |
| **MEDIUM** | **Storage download URLs bypass Storage rules and never expire** — `?alt=media&token=` links are long-lived public tokens; signed-URL/TTL strategy explicitly deferred. Gallery/messages/schools trees also lack MIME/size/role gating. | `storage.rules:44-59, 295` |
| **MEDIUM** | **QR token secret has a hardcoded dev fallback**; unsigned legacy tokens still accepted. | `qr_token_helper.php:65, 147` |
| **LOW** | External Font-Awesome CDN in the ID-card print pop-up contradicts the codebase's own local-asset/CSP hardening. | `sis/id_card.php:481` |

**Good news:** tenant isolation (`schoolId` scoping via `tenantActive()`/`isSameSchool()`) is solid across ~31 collections and all Storage paths; no document collection is cross-tenant readable; fee child-scoping, exam server-infra locks (`read/write:false`), and Razorpay server-side verification are all correct.

---

## 8. HISTORICAL-CORRECTNESS FINDINGS

Documents that can **silently change** after issuance:

| Document | Problem | Evidence |
|---|---|---|
| **Transfer Certificate** (most material) | Print re-reads **live** student particulars (father/mother/DOB/admission date/class/subjects) against a **permanent** TC number; edit the student → the same TC number prints different content. Many template fields never captured → always blank. | `sis/tc_print.php:18-52` vs snapshot `Sis.php:1504-1516` |
| **Report Card** | Hybrid: aggregates from the `results` snapshot but **per-component marks read live**; editing marks changes historical cells while totals stay stale → cards can show components that don't sum to the total. Re-compute overwrites `results` in place; **no frozen/versioned card**. | `Result.php:1971` (snapshot) vs `:1989-1997` (live) |
| **Fee Receipt** (minor) | Amounts/breakdown are snapshot-immutable ✓, but **school branding is always read live** and **father name falls back to the live student doc** on reprint → a rename/logo change alters historical receipts. | `Fees.php:3980-3986, 4017-4020` |
| **Admission Acknowledgement** | Re-renders the live `crmApplications` doc (acceptable for an acknowledgement, not a legal snapshot). | `Admission_public.php:1698` |

**Correct today (reference behaviour):** Certificates (bonafide/character) reproduce a frozen `templateData` snapshot; admission **paid** FEE receipt (ADM) and payslips render from write-once records.

**Preview vs Issued** is currently **not a modelled distinction** anywhere — every generate is effectively a live "preview" even when the output carries a permanent number.

---

## 9. CROSS-PLATFORM RESPONSIBILITY MATRIX

**Current state vs target:**

| Capability | Admin (target) | Parent (target) | Teacher (target) | Current reality |
|---|---|---|---|---|
| Configure templates/branding | ✓ | ✗ | ✗ | Admin only (report-card config exists) |
| Preview documents | ✓ | ✗ | limited | Admin ✓ |
| **Issue** official documents | ✓ | ✗ | request-only | Admin ✓ |
| Approve certificates/TC | ✓ (Principal) | ✗ | ✗ | TC has role gate; Certificates has role gate |
| Cancel / revoke | ✓ | ✗ | ✗ | TC cancel ✓, Certificates revoke ✓, others ✗ |
| **View/download** issued docs | ✓ | ✓ (own child) | authorized subset | **Parent generates locally ⚠; no server PDF** |
| Bulk generate | ✓ | ✗ | ✗ | Batch report cards ✓ |
| Search issuance history | ✓ | ✗ | ✗ | TC index ✓, Certificates register ✓, others ✗ |

**Permission matrix (recommended, least-privilege):**

| Document | Admin | Accountant | Principal | Teacher | Parent | Student |
|---|---|---|---|---|---|---|
| Fee Receipt | issue/print/reprint | issue/print/reprint | view | ✗ | view/download own child | view own |
| Fee/No-Dues Statement | ✓ | ✓ | view | ✗ | view own child | view own |
| Report Card / Marks | issue | ✗ | approve/view | preview own class/subject | view own child (published) | view own (published) |
| Admit Card | issue | ✗ | view | ✗ | download own child | download own |
| Transfer Certificate | initiate | dues-clear input | **approve/issue** | ✗ | download own child (after issue) | ✗ |
| Bonafide / Character | issue | ✗ | approve | request | download own child (after issue) | ✗ |
| Student ID Card | issue | ✗ | view | ✗ | download own child | view own |
| Staff ID / Salary Slip | issue (HR) | payroll | view | download **own** only | ✗ | ✗ |

**Hard rule to enforce:** the Parent and Teacher apps must **never** be authorities that mint official documents from local data — they **display** server-issued artifacts. This directly reverses today's `ReceiptPdfGenerator` behaviour.

---

## 10. TARGET ARCHITECTURE — ZenX Document Service (thin, reuse-first)

Do **not** build a greenfield engine. Introduce a thin `Document_service` (CI library) that *composes existing production parts* and adds only the missing layers (instance registry, immutability discipline, verification, delivery).

```
 Business module (Fees / SIS / Result / HR / Certificates / Admission)
        │  emits a typed request: {docType, entityRef, actorRole}
        ▼
 ┌───────────────────────── Document_service (NEW, thin) ─────────────────────────┐
 │  1. Registry lookup        → docType config (owner, snapshot?, number?, QR?, store?) │
 │  2. Authorization          → reuse rbac_helper + tenant/role/parent-child checks     │
 │  3. Snapshot builder       → freeze canonical data + branding + templateVersion      │
 │  4. Number allocator       → reuse fs->nextSchoolCounter / Fee_firestore_txn (CAS)    │
 │  5. Renderer               → Pdf_generator (EXTENDED: orientation/margins/hdr/ftr/wm) │
 │  6. (optional) Storage     → Firebase.php uploadFile → schools/{id}/documents/…       │
 │  7. Instance writer        → documentInstances/{id} (metadata + number + hash + state)│
 │  8. Verification token     → reuse qr_token_helper (HMAC), embed QR in PDF            │
 │  9. Audit                  → documentAuditLogs (issue/reprint/download/cancel)        │
 └────────────────────────────────────────────────────────────────────────────────┘
        │                                   │
        ▼                                   ▼
 Admin (issue/preview/reprint)     pdfUrl / storagePath written into the domain doc
                                            │
                                            ▼
                          Parent / Teacher apps DISPLAY the server-issued PDF
                          (kill client-side ReceiptPdfGenerator)
                                            │
                                            ▼
                          Public /verify/{token} → minimal attest page (QR)
```

**Preview vs Issued** becomes a first-class flag: *preview* renders from live data with a `PREVIEW / NOT AN OFFICIAL DOCUMENT` watermark and allocates no number; *issue* snapshots, numbers, (optionally) stores, and writes an instance.

---

## 11. FIRESTORE DATA MODEL (reuse-first; inspect before creating)

**Reuse as-is (do NOT duplicate):** `feeReceipts`, `feeReceiptAllocations`, `feeReceiptIndex`, `admissionReceipts`, `salarySlips`, `results`, `students.TC` / `schools.tcIndex`, `feeCounters`, existing `Numbering_service` counters.

**Add a thin unifying layer only where gaps exist:**

- `documentTemplates/{schoolId}_{docType}_{version}` — template id, version, branding-snapshot policy, layout (orientation/margins), field map. *(Report-card `reportCardConfig` migrates in as the first citizen.)*
- `documentInstances/{schoolId}_{docType}_{number}` — the canonical "issued document": `docType`, `number`, `entityRef` (e.g. `feeReceipts/…`), `templateVersion`, `snapshotHash`, `issuedBy`, `issuedAt`, `status` (issued|cancelled|revoked|superseded), `pdfStoragePath?`. For receipts/TC this **references** the existing domain doc rather than copying it.
- `documentAuditLogs/{autoId}` — `docInstanceId`, `action` (generate|issue|reprint|download|cancel|verify), `actorUid`, `role`, `ts`. *(Closes the unaudited-reprint gap.)*
- **No new numbering collection** — route all allocation through the existing atomic `nextSchoolCounter` / `Fee_firestore_txn` CAS pattern; retire `Certificates` non-atomic counter and the dead `_nextReceiptNo`.

Rules: `documentInstances`/`documentTemplates` → read `isStaffOrOwnStudent()` (parents see only their child's), write `if false` (Admin SDK only); `documentAuditLogs` → `read/write:false`. **Fix `marks`/`results` to `isStaffOrOwnStudent()`.**

---

## 12. FIREBASE STORAGE ARCHITECTURE

- **Tenant-safe path (already reserved in rules):** `schools/{schoolId}/documents/{docType}/{academicSession}/{number}.pdf` — covered by `storage.rules:192-197`.
- **Access:** stop shipping long-lived `?alt=media&token=` URLs for official documents. Serve via a **backend-mediated download** (`Document_service::download($instanceId)` re-checks auth) or **short-TTL signed URLs** (Admin SDK `getSignedUrl`), tenant + role + parent-child verified per request.
- **Store selectively** (see §16): only documents needing legal permanence get a stored PDF; the rest stay snapshot+regenerate.
- Deduplicate by `snapshotHash`; never overwrite an issued file — a correction creates a **new** instance (`supersedes`).

---

## 13. DOCUMENT REGISTRY (recommended canonical set)

| docType | Owning module | Draftable | Approval | Perm. number | Immutable snapshot | Store PDF | QR verify | Parent | Teacher |
|---|---|---|---|---|---|---|---|---|---|
| `ADMISSION_APPLICATION` | Admission | ✓ | ✗ | ✓ | ✓ | ✗ | ✗ | view own | ✗ |
| `ADMISSION_FINAL_FORM` | Admission/SIS | ✗ | ✓ | ✓ | ✓ | opt | ✗ | view own | ✗ |
| `STUDENT_PROFILE` | SIS | ✗ | ✗ | ✗ | ✗ (preview) | ✗ | ✗ | view own | authorized |
| `STUDENT_ID_CARD` | SIS | ✗ | ✗ | ✗ (QR token) | ✓ | opt | ✓ (attend.) | download own | ✗ |
| `FEE_RECEIPT` | Fees | ✗ | ✗ | ✓ | ✓ | opt | opt | download own | ✗ |
| `FEE_STATEMENT` | Fees | ✗ | ✗ | ✗ | ✗ (preview) | ✗ | ✗ | view own | ✗ |
| `NO_DUES_CERTIFICATE` | Fees | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | download own | ✗ |
| `TRANSFER_CERTIFICATE` | SIS | ✓ | ✓ | ✓ | ✓ **(fix)** | ✓ | ✓ | download own | ✗ |
| `BONAFIDE_CERTIFICATE` | Certificates→SIS | ✓ | ✓ | ✓ | ✓ | opt | ✓ | download own | request |
| `CHARACTER_CERTIFICATE` | Certificates→SIS | ✓ | ✓ | ✓ | ✓ | opt | ✓ | download own | ✗ |
| `REPORT_CARD` | Result | ✗ | ✓ (publish) | ✓ **(add)** | ✓ **(add)** | opt | opt | download own (published) | preview class |
| `MARKS_STATEMENT` | Result | ✗ | ✓ | ✓ | ✓ | opt | opt | download own | ✗ |
| `ADMIT_CARD` | Exam | ✗ | ✓ | ✓ | ✓ | ✗ | opt | download own | ✗ |
| `STAFF_ID_CARD` | Staff/HR | ✗ | ✗ | ✗ | ✓ | opt | ✗ | ✗ | own |
| `SALARY_SLIP` | Payroll | ✗ | ✓ (finalize) | ✓ | ✓ | opt | ✗ | ✗ | own |

---

## 14. IMPLEMENTATION ROADMAP (production-safe, incremental)

> Phase 0 (this audit) is complete. Each later phase is independently shippable and reversible. Ordered by **risk-adjusted value**: fix correctness/security on the money + legal docs first; apps last.

**Phase 1 — Renderer foundation.** *Objective:* extend `Pdf_generator` with `orientation/margins/header/footer/watermark` params; fold `Accounting.php:4204` back onto it; delete orphan `student_id_card.php` and dead `_nextReceiptNo`. *Files:* `libraries/Pdf_generator.php`, `Accounting.php`. *Firestore/Storage/Rules:* none. *Apps:* none. *Tests:* golden-PDF diff on report card + day_book landscape. *Accept:* all existing PDFs byte-stable except Accounting now via wrapper. *Rollback:* revert lib. *Risk:* **LOW.**

**Phase 2 — Registry + instance/audit skeleton.** *Objective:* introduce `Document_service`, `documentInstances`, `documentAuditLogs`, template-version concept; wire **reprint audit** for existing receipts (no behaviour change to output). *Firestore:* new collections + rules (`read isStaffOrOwnStudent`, `write:false`). *Apps:* none. *Accept:* every reprint writes an audit row; instances created for new receipts. *Risk:* **LOW–MEDIUM.**

**Phase 3 — Fee-receipt immutability + delivery.** *Objective:* snapshot **branding** into `feeReceipts` at issue; reprint stops reading live `schools`/`students`; write `pdfStoragePath`/`pdfUrl` into the receipt doc; **retire the Parent `ReceiptPdfGenerator`**, app displays server PDF. *Firestore:* `feeReceipts` +branding snapshot +pdf ref. *Storage:* store receipt PDF (optional) at tenant path. *Rules:* Storage signed-URL/mediated download. *Apps:* Parent — replace generator with a Storage/URL reader. *Preserve:* `defer_accounting` + `JE_FEE_{receiptKey}` idempotency untouched. *Accept:* old receipts render identically post-rename; app shows server artifact; no client-built official PDF. *Risk:* **MEDIUM** (touches money path + app).

**Phase 4 — Admission documents.** *Objective:* snapshot the acknowledgement receipt; add `ADMISSION_FINAL_FORM` + student-profile PDF. *Files:* `Admission_public.php`, `Sis.php`, new views. *Risk:* **LOW–MEDIUM.**

**Phase 5 — TC & Certificate convergence.** *Objective:* make `Sis` TC print **immutable** (capture all particulars into the snapshot; print from snapshot only); **retire the Certificates `transfer` type**; migrate the Certificates controller off the RTDB-path API onto Firestore; unify numbering onto `nextSchoolCounter`. *Firestore:* Certificates → `documentInstances`/Firestore. *Rules:* new cert collections. *Apps:* Parent gains TC/cert download (post-issue). *Risk:* **MEDIUM–HIGH** (legal doc + RTDB removal).

**Phase 6 — Report-card issuance snapshot.** *Objective:* on publish, freeze a report-card snapshot (component marks + aggregates + templateVersion + number); render issued cards from the snapshot; keep live path as explicit *preview*. *Files:* `Result.php`, `_report_card_data.php`, `Exam_result_store`. *Firestore:* `resultsPublished`/instance. *Risk:* **MEDIUM.**

**Phase 7 — Parent App Document Center.** *Objective:* one place to view/download all issued documents for the child (receipts, published report cards, released certificates/TC) from server artifacts. *Apps:* Parent. *Depends on:* 3/5/6. *Risk:* **MEDIUM.**

**Phase 8 — Teacher authorized access.** *Objective:* least-privilege preview/request surface (own class/subject), no financial/TC/HR access. *Apps:* Teacher. *Risk:* **LOW–MEDIUM.**

**Phase 9 — QR verification + Storage hardening.** *Objective:* public `/verify/{token}` attest page (minimal fields) for TC/certificates/no-dues; embed QR in those PDFs; enforce signed URLs + MIME/size/role gating on Storage; remove QR secret hardcoded fallback + reject unsigned legacy tokens. *Files:* new `Verify` controller, `qr_token_helper.php`, `storage.rules`. *Risk:* **MEDIUM.**

**Phase 10 — Firestore-only compliance + certification.** *Objective:* migrate the 7 `Result.php` RTDB sites (defaulter reads, audit pushes, `ExamEligibility` write, `AttendanceRules` read) to Firestore; complete **Parent app** RTDB elimination (Auth/Leave/DataRepository) to match the clean Teacher app; fix `marks`/`results` rules to `isStaffOrOwnStudent()`; full production certification across all 3 surfaces. *Risk:* **MEDIUM–HIGH.**

*(For each phase, the standard gate applies: files affected, Firestore/Storage/Rules deltas, per-app impact, migration + backward-compat notes, tests, acceptance criteria, rollback — expanded per phase at kickoff.)*

---

## FINAL DECISION

### Should ZenX build a centralized Document Engine / Document Service?

**PARTIALLY — YES to a *thin* Document Service; NO to a greenfield engine or a template designer.**

**Why, specifically for this repo:** ZenX already owns the hard parts — a single hardened Dompdf wrapper, a server-verified fee/accounting spine with atomic tenant-scoped numbering, a production-grade HMAC QR token helper, an SSRF-hardened branding pipeline, and Firestore-canonical data for receipts/results/payslips/TC. Rebuilding those would be waste and risk. What's genuinely absent is the **connective layer**: a document-instance registry, consistent immutability for *issued* documents, one numbering authority, server-issued delivery to the apps, and QR verification. A thin `Document_service` that *composes* the existing parts and adds only that layer is the correct, lowest-risk path. A drag-and-drop template designer is **not** warranted — a controlled, versioned template system meets commercial K-12 requirements.

### Retain / Migrate / Replace

- **Retain (reference-quality):** `Pdf_generator` (extend, don't replace) · fee-receipt engine `FeeCollectionService` + Razorpay verification + `JE_FEE_{receiptKey}` accounting hook · admission **paid** ADM receipt · salary-slip pipeline · `qr_token_helper` · `public_url_helper` branding/data-URI · the `Sis::issue_tc` **workflow** (dues gate, atomic number, side-effects).
- **Migrate:** Certificates controller → Firestore + `Document_service` (off the RTDB-path API) · report-card rendering → published immutable snapshot + number · fee-receipt reprint → snapshot branding + server-delivered PDF · `Result.php` 7 RTDB sites → Firestore · Parent app RTDB → Firestore · numbering → single `nextSchoolCounter` authority.
- **Replace / Remove:** the Parent app's on-device `ReceiptPdfGenerator` (replace with server-issued display) · the Certificates `transfer` type (redundant with `Sis` TC) · orphan `views/student_id_card.php` · dead `Fee_management::_nextReceiptNo()` · Accounting's raw-Dompdf block · QR secret hardcoded fallback + unsigned-legacy-token acceptance · stale `qrserver.com` comments.

### Recommended target flow

```
Admin / Business Module
  → Canonical Firestore Business Record (feeReceipts / results / students.TC / salarySlips …)
  → Authorized Document_service (RBAC + tenant + parent-child)
  → Versioned Template (documentTemplates, branding snapshot)
  → Immutable Issuance Snapshot (+ atomic number via nextSchoolCounter + snapshotHash)
  → Professional PDF (extended Pdf_generator: A4/landscape, header/footer/watermark, QR)
  → Firebase Storage where legally required (schools/{id}/documents/…), signed/mediated access
  → Firestore Document Metadata (documentInstances + documentAuditLogs)
  → Secure Cross-Platform Access (Admin issues · Parent/Teacher DISPLAY, never generate)
```

**Nothing will be implemented until you review and approve this audit and the proposed architecture.**
