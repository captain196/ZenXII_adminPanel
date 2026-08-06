# ADMISSION MODULE — UAT TEST CASES

**Module:** Admission + Admission CRM · **Build:** commit `dc89311` (feat/admission production hardening)
**Tester:** ________________  **Date:** __________  **Environment:** ☐ Staging ☐ Production
**Verdict:** ☐ PASS ☐ FAIL (all Critical/High must PASS)

> This document is a UAT artifact (not part of the code commit). Fill **Actual Result** + **Status** as you execute each case.

---

## Pre-conditions / test-environment setup (do once)
| # | Setup item | Confirm |
|---|---|---|
| P1 | A test school exists with a readable **admission slug** (e.g. `iit-kanpur`). Verify: `admissionSlugs/{slug}` → your `schoolId`. | ☐ |
| P2 | **`PUBLIC_BASE_URL`** is set for the environment (Staging: your test host; Production: `https://www.zenxii.com/`). | ☐ |
| P3 | School **admission fee** is configured — class-specific in `feeStructures` (a head containing "admission"), or global `schools.{id}.admissionFee`. | ☐ |
| P4 | A **fee structure** exists for the enrollment class/section in the current session (enrollment pre-flight requires it). | ☐ |
| P5 | Razorpay gateway configured in **test mode** (`rzp_test_…` key + webhook secret) for the online-payment cases. | ☐ |
| P6 | You have an admin login with CRM + enrollment roles. | ☐ |
| **Test data** | Student: **"UAT Test Kumar"**, class **6th**, phone **9800000001**, DOB set (needed for password gen). Keep this record for the whole run. | ☐ |

---

## PHASE 1 — PUBLIC ADMISSION

### TC-01 — Open Admission URL
- **Pre:** P1, P2.
- **Steps:** Open `{PUBLIC_BASE_URL}/admission/form/{slug}` in a browser (incognito, logged-out).
- **Expected:** Public admission form **page loads** (HTTP 200), branded with school name/logo/address; URL shows the production/public host — **never a LAN IP/localhost**.
- **Verify at:** browser address bar + page render.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-02 — QR Code
- **Pre:** TC-01; admin on School Config → Admission Payment (or the QR poster).
- **Steps:** Scan the displayed Admission QR with a phone.
- **Expected:** QR **opens the same public admission form URL** as TC-01 (same slug, same public host).
- **Verify at:** phone browser resolves to `{PUBLIC_BASE_URL}/admission/form/{slug}`.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-03 — Submit new application
- **Pre:** TC-01, test data.
- **Steps:** Fill required fields (student name, class, phone) + optional fields → Submit.
- **Expected:** **Success** — "Application Submitted!" shown **after** submit; an `APP_…` application id is returned.
- **Verify at:** `crmApplications` — a new doc `{schoolId}_APP_…` with `status='pending'`, `stage='document_collection'`, `source='public_form'`, `searchTokens` populated, `history[0]='Application submitted via public form'`; and `auditLogs` has a `public_application` entry.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-04 — Upload photo
- **Pre:** TC-03 in progress (form open).
- **Steps:** Attach a student photo → submit.
- **Expected:** Photo **stored correctly** (uploads to Firebase Storage; no broken image).
- **Verify at:** Firebase Storage path for the application/student photo resolves; the stored reference/URL is present on the record.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-05 — Upload documents
- **Pre:** TC-03 in progress.
- **Steps:** Attach supporting document(s) → submit.
- **Expected:** Document(s) **stored correctly** in Firebase Storage; retrievable later from the application/student.
- **Verify at:** Firebase Storage + the document reference on the record.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

---

## PHASE 2 — ADMISSION CRM

### TC-06 — Application appears in CRM
- **Pre:** TC-03 done; logged in as admin (P6).
- **Steps:** Open Admission CRM → Applications (and Leads).
- **Expected:** The submitted application **appears** in the grid (current session).
- **Verify at:** `admission_crm/fetch_applications` returns the doc; row visible with correct name/class/status.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-07 — Search
- **Pre:** TC-06.
- **Steps:** In the Applications search box, search by (a) student name "UAT", (b) the `APP_…` id, (c) phone `9800000001`.
- **Expected:** Each **works** — the application is found for all three modes.
- **Verify at:** grid filters to the matching row (name→searchTokens, appID→exact, phone→phone_norm/exact).
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-08 — Approve
- **Pre:** application `status=pending`.
- **Steps:** Click Approve (optional remarks) → confirm.
- **Expected:** **Works** — status → `approved`.
- **Verify at:** `crmApplications` doc `status='approved'`, `stage='approved'`, `approved_by`/`approved_at` set, `history` appended; `auditLogs` has `approve_application` (appId-correlated).
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-09 — Waitlist
- **Pre:** a `pending` application (create a second one, or move first back to pending).
- **Steps:** Add to Waitlist → then Remove / Promote.
- **Expected:** **Works** — add sets `status=waitlisted` + a `crmWaitlist` entry; remove reverts to `pending`; promote sets `approved` and deletes the waitlist entry.
- **Verify at:** `crmApplications.status` transitions; `crmWaitlist` doc created/deleted; `auditLogs` `waitlist_add/remove/promote`.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-10 — Reject
- **Pre:** a `pending` application.
- **Steps:** Click Reject (reason) → confirm.
- **Expected:** **Works** — status → `rejected`.
- **Verify at:** `crmApplications` `status='rejected'`, `reject_reason` set, `history` appended; `auditLogs` `reject_application`.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

---

## PHASE 3 — PAYMENT

### TC-11 — Collect offline fee
- **Pre:** P3, an application (approved or pending) with the admission fee configured for its class.
- **Steps:** CRM → Collect Admission Fee → choose mode (Cash/UPI/etc.) → Collect.
- **Expected:** **Receipt generated** — an ADM receipt number is returned; the **class-specific** fee amount is charged (Fix D).
- **Verify at:** `admissionReceipts/{ADMkey}` created with `receiptNo`, `appId`, `amount` = class-specific fee, `paymentMode`, `status='PAID'`; `crmApplications.payment_status='paid'`, `receipt_no` stamped.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-12 — Collect online fee
- **Pre:** P5; open the public form/payment for an application.
- **Steps:** Choose "Pay Online" → complete Razorpay **test-card** checkout.
- **Expected:** **Receipt generated** — payment captured, ADM FEE receipt (`ADM…`) produced and downloadable.
- **Verify at:** `admissionReceipts` doc for the payment; `crmApplications.payment_status='paid'` + `receipt_no`; parent gets the fee-receipt link; a `payment_success` audit (appId-correlated).
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-13 — Accounting journal
- **Pre:** TC-11 or TC-12 succeeded.
- **Steps:** Open Accounting → Journal / Day Book for today.
- **Expected:** A journal entry **created** for the admission collection (Dr cash/bank, Cr admission income).
- **Verify at:** `admissionReceipts` doc `journalPosted=true`, `journalEntryId='JE_FEE_{ADMkey}'`; the entry appears in Day Book / Ledger / Trial Balance.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-14 — Audit log
- **Pre:** TC-11/TC-12.
- **Steps:** Open Audit Logs (or query per application).
- **Expected:** Audit entry **created** for the collection.
- **Verify at:** `auditLogs` has an `admission_collection` (offline) / `payment_success` (online) entry, correlated by `appId`.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

---

## PHASE 4 — ENROLLMENT

### TC-15 — Enroll (student created)
- **Pre:** application `status=approved` (TC-08); P4 (fee structure for class/section).
- **Steps:** CRM → Enroll → pick section → confirm.
- **Expected:** **Student created** with a `STU…` id; CRM app → `enrolled`.
- **Verify at:** `students/{STU…}` created (Name, Class, Section, `application_id` back-link, `Status='Active'`); `crmApplications` `status='enrolled'`, `student_id` set; `accountingIdempotency/{schoolId}_ENROLL_{appId}` = `success`; `auditLogs` `enroll_student`.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-16 — Student Master (visible)
- **Pre:** TC-15.
- **Steps:** Open Student Master / SIS student list.
- **Expected:** The new student is **visible** (correct name/class/section/admission no.).
- **Verify at:** SIS student list shows `STU…`; Admission Register lists the admission.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-17 — Parent login
- **Pre:** TC-15 (Auth created + credentials SMS).
- **Steps:** Use the SMS'd credentials (student id + generated password) to log in on the Parent app.
- **Expected:** **Works** — parent logs in; first-login password-change prompt (`mustChangePassword`).
- **Verify at:** Firebase Auth account exists for the student; login succeeds; `enroll_student` JSON returned `auth_created=true`.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-18 — Initial fee generated
- **Pre:** TC-15.
- **Steps:** Open the enrolled student's Fees.
- **Expected:** Initial fee demands **generated** for the class/section/session.
- **Verify at:** `feeDemands` for `STU…` populated (assignInitialFees ran once); defaulter/monthFee initialized.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

---

## PHASE 5 — NEGATIVE / IDEMPOTENCY

### TC-19 — Duplicate enrollment prevented
- **Pre:** application already enrolled (TC-15) — OR simulate two rapid enroll submits.
- **Steps:** Attempt to enroll the **same** application again (retry / second admin / double-submit).
- **Expected:** **Prevented** — no second student, no second Auth account, no second fee-demand set. Response is a safe "already enrolled / in progress".
- **Verify at:** `students` count for this application stays **1**; `accountingIdempotency/{…}_ENROLL_{appId}` = `success` (single slot); no duplicate `feeDemands`.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

### TC-20 — Duplicate payment prevented
- **Pre:** an application already paid (TC-11/TC-12).
- **Steps:** Attempt to collect the admission fee again (offline re-collect, or replay the online webhook/callback).
- **Expected:** **Prevented** — no second ADM receipt, no second journal; the original receipt is returned (dedup).
- **Verify at:** `admissionReceipts` count for the app stays **1**; `accountingIdempotency/{…}_ADM_COLLECT_{appId}` = `success`; only one `JE_FEE_{ADMkey}` journal.
- **Actual:** __________  **Status:** ☐PASS ☐FAIL

---

## SUMMARY

| Phase | Cases | Result |
|---|---|---|
| 1 · Public Admission | TC-01…05 | ☐ PASS ☐ FAIL |
| 2 · CRM | TC-06…10 | ☐ PASS ☐ FAIL |
| 3 · Payment | TC-11…14 | ☐ PASS ☐ FAIL |
| 4 · Enrollment | TC-15…18 | ☐ PASS ☐ FAIL |
| 5 · Negative/Idempotency | TC-19…20 | ☐ PASS ☐ FAIL |

**Overall UAT verdict:** ☐ **ADMISSION MODULE PASSED FINAL UAT** ☐ FAIL (list blocking case IDs: __________)

**Notes / defects found:** ________________________________________________
