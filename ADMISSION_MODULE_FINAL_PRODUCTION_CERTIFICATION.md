# ADMISSION MODULE — FINAL PRODUCTION CERTIFICATION

**Scope:** Admission + Admission CRM subsystem (public admission form, Razorpay online payment callback/webhook, offline collection, receipts, ADM numbering, accounting integration, CRM lead/inquiry/waitlist/application lifecycle, search/pagination, audit trail, history/timeline caches, school-logo rendering).
**Database:** Firestore ONLY (Firebase Storage = uploaded-file source of truth). No RTDB, no dual-write.
**Status of this document:** Closure audit + safe MUST-FIX implementation. Nothing committed or pushed.

---

## 1. EXECUTIVE VERDICT

**CONDITIONALLY PRODUCTION-READY — code-complete and regression-green; two non-blocking items require an operator decision (they are release-management / product-policy, not Admission correctness defects).**

- All **Critical / High / Medium correctness, security, payment, accounting, tenant-isolation, and data-integrity** issues found in this closure are **RESOLVED and verified**, OR verified **already-fixed**.
- Two items are **STOP / decision-gated** and do **not** block Admission's functional correctness:
  - **C — Firestore index drift** (cross-module release-management; the Admission-critical indexes are all deployed READY; a canonical-file reconciliation carries deletion risk and needs approval).
  - **E — Phone canonicalization** (a known product-policy limitation; changing it risks merging existing identities → needs a data-impact decision).
- Remaining open items are genuine **FUTURE ENHANCEMENTS** or **SAFE, bounded TECHNICAL DEBT** (documented, none are correctness/security/accounting blockers).

Admission is **ready to commit** as a production-hardened module; the two decision-gated items can be commit-independent follow-ups.

---

## 2. CERTIFIED PRIOR WORK (must remain green — RE-VERIFIED this closure)

| Phase | Item | Status this closure |
|---|---|---|
| P1 | C1 double-reconcile / idempotency (`ADM_COLLECT_{appId}`) | GREEN (untouched; payment path intact) |
| P1 | C2 atomic ADM receipt numbering | GREEN |
| P1 | C5 journal failure detection / reconciliation | GREEN |
| P1 | M2 offline-collection race | GREEN |
| P2 | C3 captured-payment verification | GREEN (security sweep confirmed) |
| P2 | M1 amount verification | GREEN |
| P2 | callback/webhook policy parity | GREEN |
| P3 | C6 output encoding / stored-XSS | GREEN (esc() confirmed across admission_crm/*) |
| P4 | C7 scalable pagination/search | GREEN (query/leads/pipeline suites PASS) |
| P4 | M8 crmApplications indexes | GREEN (9 indexes READY) |
| P4 | M3 redundant double-write elimination | GREEN (equivalence PASS; audit writes are a separate collection) |
| P4 | M4 logo cache + SSRF hardening | GREEN (security sweep confirmed intact) |
| P4 | M7-A permanent audit authority + backfill | GREEN (verify suite PASS) |
| P4 | M7-B bounded concurrency-safe caches | GREEN + HARDENED (item H below) |

---

## 3. MASTER DEFECT REGISTER (closure items A–L)

Legend: **FIX** = fixed this closure · **VERIFIED-DONE** = already correct, verified · **STOP** = decision/approval-gated · **DEBT** = safe bounded technical debt · **FUTURE** = enhancement · **REJECTED** = not an issue.

### A / C4 — Enterprise Webhook Security Hardening → **VERIFIED-DONE**
- **Severity:** High (payment auth). **Root cause:** historical concern that webhook auth might fail-open.
- **Finding:** Both `Admission_public::payment_webhook` and `Fee_management::payment_webhook` are **identically hardened**: IP allowlist (all modes), config-drift (live-key vs mode) → 500, 5-min replay window, **live-mode fail-closed** (missing secret → 500, missing signature → 401), HMAC `hash_equals` on the **raw body**, event-id dedup. Mock gateway is fail-closed in production. Only skip is test-mode-with-no-secret (no real money). **Parity holds — no fix required on either module.**
- **Production impact:** none (already safe). **Regression risk:** none.

### B / Fix D — Offline Admission Fee Amount Resolver → **FIX**
- **Severity:** High (data integrity — wrong amount collected). **Root cause:** offline `collect_admission_fee` read `$app['fee_amount']` (unset for admin-created apps) then fell back to the **global** flat `schools.admissionFee`, ignoring class-specific `feeStructures` — so an admin collecting at the counter could under/over-charge vs the online flow.
- **Fix:** Introduced ONE canonical stateless resolver `admission_resolve_fee($fs,$schoolDoc,$session,$class)` in `admission_crm_helper.php` (class-specific `feeStructures` → global fallback). **Online `_resolve_admission_fee` now delegates to it** (byte-identical behaviour); **offline `collect_admission_fee` now calls it** (with a legacy `$app['fee_amount']` fallback). Online == offline by construction.
- **Reuse:** canonical resolver (no parallel pricing engine). **Files:** `admission_crm_helper.php`, `Admission_public.php`, `Sis.php`. **Migration:** none. **Index:** none. **Regression risk:** Low — proven online-unchanged + offline-now-class-specific (fixd_test 6/6 PASS).

### C — Firestore Index Drift → **STOP (decision-gated; read-only reconciliation done)**
- **Severity:** Medium (release-management). **Root cause:** deployed indexes and local `firestore.indexes.json` diverged over time; the 9 C7 + 2 M7 Admission indexes were surgically deployed but intentionally not written to the drifted file.
- **Finding:** Admission-critical indexes (9 crmApplications C7 + 2 auditLogs/auditArchive M7) are **deployed and READY with confirmed runtime consumers** (proven by C7/M7 runtime suites). The broader deployed set is large and spans all modules (Transport/TMS, Fees, HR, etc.); a full deployed-vs-local diff confirms significant cross-module drift.
- **Why STOP:** establishing one canonical file safely requires a per-index consumer audit; a bulk `firebase deploy --only firestore:indexes` would **DELETE** deployed-only indexes (production breakage). **No destructive action taken.**
- **Proposed safe path (needs approval):** (1) enumerate deployed indexes per collectionGroup; (2) **additively** merge every deployed index into the local file (never delete); (3) separately audit local-only/undeployed and obsolete indexes with named consumers before any deletion; (4) commit the additively-reconciled file as the new canonical. Admission functionality does not depend on this.

### D — crmInquiries / crmWaitlist scalability → **FIX (bounded) + FUTURE (pagination)**
- **Severity:** Medium. **Root cause:** `_crm_list()` full-scanned all sessions; `fetch_inquiries`/`fetch_waitlist` returned everything.
- **Fix:** `_crm_list()` is now **session-scoped** (schoolId+session equality, zig-zag, no index) — every caller already discarded non-current-session rows, so behaviour-identical while bounding the scan to the current session working set. Collections are currently empty in production.
- **Future:** cursor pagination for `fetch_inquiries`/`fetch_waitlist` (like `fetch_applications`) when volume warrants — deferred (no current scale; do not create speculative indexes). **Files:** `Sis.php`.

### E — Admission phone canonicalization → **STOP (data-impact decision required)**
- **Severity:** Medium (data integrity / identity). **Root cause:** `phone_norm` is digits-only (`9876543210`); `+91 9876543210` / `91-9876543210` normalize to `919876543210` (13 digits) vs `9876543210` (10 digits) — so the SAME Indian number can appear as two identities in duplicate-detection / CRM search / records.
- **Why STOP:** applying a `+91`/leading-`91` canonicalization would change how existing records match and **could merge or split existing identities** in production. Per instruction, this needs a migration/data-impact review before any production data change.
- **Data-impact to decide:** whether to (a) strip a leading `91` when the remainder is a 10-digit Indian mobile, (b) backfill `phone_norm` on existing crmApplications, (c) how duplicate-detection should treat historical mismatches. **No change made.**

### F — Guardian-name search → **REJECTED (future enhancement, not a defect)**
- **Severity:** Low. C7 deliberately chose **student-only** searchTokens (approved design). CRM search already supports student name + phone + application-ID. Guardian/parent-name search is a convenience enhancement, **not** a production defect or required functionality. **Classification: FUTURE ENHANCEMENT.**

### G — Full Audit History reader → **VERIFIED-DONE (bounded)**
- **Severity:** Low. `audit_trail_for_app()` unions `auditLogs` + `auditArchive` scoped by `(schoolId, appId)`, **bounded to 500 per collection** (index `(schoolId, appId, timestampMs DESC)` READY on both), deduped by stable doc id, ordered newest-first, tenant-scoped, role-guarded endpoint (`Sis::admission_audit_trail`, VIEW_ROLES). It does **not** reintroduce an unbounded read (hard 500 cap; an application with >500 audit events is pathological). **No fix required.**

### H — CAS Failure Semantics → **FIX (mandatory correctness)**
- **Severity:** High (concurrency correctness — the user's flag was correct). **Root cause:** M7-B's original CAS-exhaustion fallback did a **non-CAS array overwrite**, which could clobber a concurrent appender's entry — reintroducing the exact lost-update race M7-B eliminates.
- **Fix:** On CAS exhaustion the fallback now **never rewrites the array**. It persists **only the business fields** (merge; scalar last-write-wins, touches no array) so the business/payment mutation still lands, and **drops the recent-cache append** (logged for monitoring). No data loss: the permanent authoritative record is already in `auditLogs` (M7-A). Fail-safe: business/financial success never depends on the cache append. **Files:** `Firestore_rest_client.php`. **Regression:** CAS primitive suite 15/15 PASS.

### I — Performance / full-scan risks → **FIX (public path + bounding) + FUTURE (aggregation)**
- **Severity:** Critical (public path) / High (admin analytics). **Root cause:** `_crm_list()` full-scan (all sessions) wired into 6 paths.
- **Fixes:** (1) **Finding 1 (Critical):** public `submit_online_form` duplicate-phone check now uses a **bounded equality query** (schoolId+session+phone, zig-zag, no index) instead of a full scan — removes a per-public-submit full-collection read. (2) `_crm_list()` **session-scoped** — bounds the analytics/dashboard/inquiry/waitlist scans (Findings 2–6) to the current session.
- **Future (bounded now):** `fetch_analytics` / `crm_dashboard` / `admission_analytics` still aggregate in PHP over the current session; converting to COUNT aggregation (like `fetch_leads_stats`) is a future optimization — now session-bounded, admin-authenticated, low current volume. **Files:** `Sis.php`.

### J — Security → **VERIFIED-DONE** (residual Low consistency = DEBT)
- **Severity:** clean. Independent security sweep found **no Critical/High exploitable issues**: CSRF global + POST mutations; every CRM mutation `_require_role`-guarded; reads/writes school-scoped (docId `{schoolId}_` prefix) → no IDOR; receipts require HMAC token (`hash_equals`) → non-enumerable; SSRF hardened (M4 intact); output encoding (C6 intact); Razorpay callback signature + webhook HMAC verified; secrets never echoed; mock fail-closed in prod.
- **DEBT (Low, not exploitable):** F1/F2 — two views (`applications.php` onclick, `admission_leads.php` pre-C6 `esc()`) interpolate **server-generated/validated IDs** into attributes without the quote-safe `esc()`; server IDs are `[A-Za-z0-9_-]` only → not exploitable; recommend aligning to the C6 quote-safe `esc()` for consistency. F3–F6 informational/by-design (documented).

### K — Data Integrity → **VERIFIED-DONE**
- Application IDs (`APP_*`/`APP{counter}`), receipt IDs (atomic ADM, C2), payment_status/receipt_no (single-writer, clobber-guarded), student-enrollment linkage (`student_id`), admissionReceipts (dedicated collection), journals (`JE_FEE_*` deterministic), auditLogs (immutable, appId-correlated, M7-A), history/timeline (CAS-bounded, M7-B), searchTokens (C7, preserved through all writes — verified), schoolId/session stamping (`setEntity`/`casAppendBounded` — verified). No integrity defects.

### L — Workflow → **VERIFIED-DONE (query/logic layer)**
- Public application, admin create/edit, inquiry CRUD, inquiry→application, lead status, pipeline/stage, approve/reject, waitlist add/remove/promote, online payment/callback/webhook, offline collection, receipt, enrollment, search, pagination, audit history, logo rendering — all exercised across the phase runtime suites. Authenticated click-through UAT (below) is the only residual.

---

## 4. CERTIFICATIONS

- **Payment integrity:** single canonical money path (`record_payment`), idempotency gate (C1/M2), captured-amount verification (C3/M1), callback/webhook parity, webhook HMAC fail-closed in live (C4). Cache-maintenance (history/timeline) is fully isolated from payment success (H). **GREEN.**
- **Accounting integrity:** deterministic `JE_FEE_{receiptKey}` journals, failure detection + reconciler (C5), ADM numbering (C2) — untouched by this closure. **GREEN.**
- **Security:** no Critical/High exploitable issues (independent sweep). **GREEN** (Low consistency debt documented).
- **Multi-tenant isolation:** every read/write school-scoped; audit + receipts tenant-checked; no cross-tenant bleed (verified in C7/M7 suites). **GREEN.**
- **Performance/scale:** public path de-scanned; CRM lists session-bounded; cursor pagination + COUNT aggregation for the primary grids; page reads O(page) not O(N) (proven to 10k). **GREEN** (analytics aggregation = future optimization).

---

## 5. FULL REGRESSION MATRIX (this closure — all PASS)

| Suite | Result |
|---|---|
| Fix D resolver (class-specific/global/none/online≡offline) | PASS (6/6) |
| H CAS primitive isolation (8 concurrency scenarios) | PASS (15/15) |
| M7-B E2E (field preservation + concurrency, real doc) | PASS |
| M7-A audit authority + backfill + correlation + tenant | PASS |
| C7 query-layer (browse/status/name/phone/appID + isolation) | PASS |
| C7 leads (in-filter + COUNT funnel) | PASS |
| C7 pipeline (bounded columns + class filter + cursor) | PASS |
| Tokenizer + JS≡PHP parity | PASS |
| M3 write equivalence (single==dual) | PASS |
| Migration idempotency (0 need trim) | PASS |
| New query shapes (phone-equality + session-scoped, no index error) | PASS |
| Cross-module: Fees webhook parity | VERIFIED |

Parent App / Teacher App: `crmApplications` (and its history/timeline/searchTokens) are **admin-only** pre-enrollment collections with no app-side readers found; enrollment writes to `students`/`studentHistory` (unchanged). No app-side impact from this closure. (Recommend a one-line confirm in the two Kotlin repos before release — see UAT.)
Fees module: untouched except the verified-parity webhook (no code change). **No regression.**

---

## 6. EXACT FILES CHANGED (this closure)

- `application/libraries/Firestore_rest_client.php` — H fail-safe CAS-exhaustion fallback (no array overwrite).
- `application/helpers/admission_crm_helper.php` — canonical `admission_resolve_fee()` (Fix D).
- `application/controllers/Admission_public.php` — `_resolve_admission_fee` delegates to canonical resolver.
- `application/controllers/Sis.php` — Fix D offline resolver; `_crm_list` session-scoped (I); `submit_online_form` bounded phone-equality dup-check (I/Finding 1).

*(Prior-phase files already in the working tree: `Firestore_service.php`, `Admission_collection.php`, `admission_search_helper.php`, `audit_helper.php`, and the `admission_crm/*` + `sis/admission_leads.php` views.)*
Non-shipping scratchpad test harnesses only.

---

## 7. PRODUCTION INDEX & MIGRATION STATE

- **Deployed & READY (Admission-critical):** 9 crmApplications (C7/M8) + `auditLogs (schoolId,appId,timestampMs)` + `auditArchive (schoolId,appId,timestampMs)` (M7-A). All confirmed by runtime consumers.
- **New indexes required by this closure:** **NONE** (Fix D uses `schoolList` on the existing `feeStructures`; the new phone-dup and session-scoped queries are multi-equality zig-zag — no composite index).
- **Migration/backfill:** M7-A history→auditLogs backfill complete + idempotent (48/48). M7-B array migration: 0 docs needed trimming. **No new migration in this closure.**
- **Index drift (C):** decision-gated, additive-only reconciliation recommended — see §3-C. Do not bulk-deploy.

---

## 8. PRODUCTION DEPLOYMENT STEPS

1. Ensure `ENCRYPTION_KEY` is set in production (already enforced by `config.php`).
2. Deploy the changed PHP files (§6). No index deploy required for this closure.
3. Confirm the 11 Admission indexes remain READY (already deployed).
4. (Decision-gated) Schedule the additive index-file reconciliation (C) and the phone-canonicalization decision (E) as separate changes.

## 9. ROLLBACK CONSIDERATIONS

- All changes are code-only (no schema/data migration in this closure) → rollback = redeploy previous PHP files.
- Fix D: rollback reverts offline collection to the prior (global-fallback) amount resolution — no data written incorrectly by the new code (it resolves the *correct* class-specific amount).
- H: rollback reverts to the (unsafe) non-CAS fallback — not recommended.
- No indexes created/deleted → nothing to roll back on Firestore.

## 10. REQUIRED MANUAL UAT (authenticated, operator-run)

1. Offline collect for an **admin-created** application in a class that has a class-specific `feeStructures` admission fee → confirm the **class-specific** amount is charged (Fix D).
2. Public online-form submit with a duplicate phone → confirm the duplicate is still detected (Finding 1).
3. CRM grids (Applications/Leads/Pipeline) paginate/search/filter; open an application → "View Full Audit History" loads the permanent trail.
4. One online Razorpay payment (test mode) end-to-end + one webhook replay → confirm single receipt/journal, `payment_collected` appears once.
5. One-line confirm no `crmApplications` reads exist in the Parent/Teacher Kotlin repos.

---

## 11. ANSWERS TO THE FOUR CLOSING QUESTIONS

1. **100% complete:** C4 webhook hardening (both modules, verified) · Fix D offline resolver · H CAS fail-safe · Finding 1 public de-scan · `_crm_list` session-bounding · G audit-reader bound · J security (no exploitable issues) · K data integrity · all Phase 1–4 regressions.
2. **Requires your decision (STOP):** **C — Firestore index-drift reconciliation** (additive-only path proposed; deletion risk) and **E — phone canonicalization** (identity merge/data-impact). Neither blocks Admission correctness.
3. **Future enhancements only:** F guardian-name search · analytics COUNT-aggregation (now session-bounded) · inquiry/waitlist cursor pagination · J F1/F2 esc() consistency (Low, non-exploitable) · audit-reader pagination beyond 500.
4. **Is Admission ready to commit?** **YES** — the module is production-hardened and regression-green; every MUST-FIX correctness/security/payment/accounting/tenant/data-integrity/scale item is resolved or verified-done. Commit the §6 files. The two STOP items (C, E) are safe to schedule as separate, commit-independent follow-ups.

---
*Nothing committed or pushed. No destructive production changes performed.*
