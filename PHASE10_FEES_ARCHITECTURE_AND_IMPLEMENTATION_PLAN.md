# Phase 10 — Fees Workstream Architecture Audit + Phase 9.2 Verification Closure

**Date:** 2026-08-13 · **Branch:** `integration/phase2` · **Type: AUDIT/DESIGN ONLY. Zero code changed.**

No file was edited, no rule/index touched, no production data written, nothing staged, committed,
or pushed. Per explicit instruction: **STOP AFTER THE AUDIT.**

---

# PART A — Phase 9.2 Verification Closure

## A1. Bulk Import UAT Readiness Matrix

| # | Scenario | Status | Reason |
|---|---|---|---|
| 1 | Historical import mode | NOT TESTED | No browser/authenticated session available in this environment |
| 2 | New admission import mode | NOT TESTED | Same |
| 3 | SR Number required in historical mode | NOT TESTED (browser) — code-verified | `Import_schema::validateRow()`'s mode branch is unit-tested (`ImportSchemaIdentityTest.php`); the end-to-end UI enforcement is not |
| 4 | SR Number ignored in new mode | NOT TESTED (browser) — code-verified | Same — unit-tested at the schema layer, not click-tested |
| 5 | Automatic SRNO allocation | NOT TESTED (browser) — service-verified | `Sr_no_service::allocateNext()` itself has 10 passing unit tests (Phase 9.1); the import call site is not independently browser-tested |
| 6 | APAAR | NOT TESTED | Same environment limitation |
| 7 | Roll Number | NOT TESTED | Same |
| 8 | Duplicate SRNO | NOT TESTED (browser) — service-verified | Collision logic proven at the service layer |
| 9 | Duplicate APAAR | NOT TESTED (browser) — service-verified | Same |
| 10 | Cross-school SRNO | NOT TESTED (browser) — service-verified | `SrNoServiceMultiTenantTest.php` proves isolation at the service layer |
| 11 | Validation before commit | NOT TESTED (browser) | `import_validate()` logic unit-tested; UI flow not |
| 12 | Commit revalidation | NOT TESTED (browser) | `import_commit()` logic read/verified by source trace; not click-tested |
| 13 | Rollback behavior (conflict after student creation) | NOT TESTED at all — ENVIRONMENT BLOCKED | This specific path (`saveStudent()` succeeds, then `claim()`/`allocateNext()` fails, triggering `deleteStudent()` + `releaseClaim()`) requires a live Firestore write sequence to observe end-to-end; no test (unit or browser) exercises it directly. **This is the single highest-value manual test for the next UAT session.** |
| 14 | Credentials output | NOT TESTED (browser); **gap already found by source read** | See A2 — bulk import's credentials array/PDF handout does not include SR Number at all |
| 15 | Imported student profile | NOT TESTED (browser) | Profile page's SRNO badge (Phase 9.1) is generic — should render correctly for an imported student, not independently re-verified |
| 16 | Student 360 | NOT TESTED (browser); **code fix made in 9.2, not re-verified live** | The `srNo` alias fix (Phase 9.2 §Student_context.php) is unit-untested (no existing Student 360 test harness in this repo) |
| 17 | Fees | NOT TESTED (browser) — source-verified | `_runStudentImport()`'s existing fee-chart gate/`assignInitialFees()` call is unchanged, unaffected by identity fields |
| 18 | Parent App | NOT TESTED — NOT APPLICABLE by design | Zero SRNO/APAAR dependency confirmed by grep across every Phase 9 pass |
| 19 | Teacher App | NOT TESTED — NOT APPLICABLE by design | Same |

**No item above is claimed as PASS.** Every claim is either NOT TESTED (environment-blocked) or
explicitly qualified with what level of verification (unit/service/source-trace) actually backs it.

## A2. SRNO Position in the UI — Verified Surfaces + Real Gaps Found

| Surface | Status | Evidence |
|---|---|---|
| Admission CRM credentials panel | **DONE** (Phase 9.1) | SR Number rendered beside User ID in `showEnrollmentCredentials()` |
| Direct/walk-in admission credentials box | **DONE** (Phase 9.1) | Same pattern in `studentAdmission.php` |
| Student Profile (hero, beside Student ID badge) | **DONE** (Phase 9.1) | `sp-badge` row in `student_profile.php` |
| Student Profile (Academic tab, detailed) | **DONE** (Phase 9.1) | Supplementary display |
| Re-admission confirm screen | **DONE** (Phase 9.1) | Shows relinked SRNO in the success message |
| Student 360 | **PARTIALLY DONE, now improved this pass** | `Student_context.php`'s `srNumber` alias now recognizes the live `srNo` field (Phase 9.2 fix) — but this was never independently browser-verified to actually RENDER in the `workspace.php` academic-detail row (§A1 item 16) |
| **Bulk-import result screen (`#resultCard`)** | **GAP FOUND — MISSING** | `import_students_map.php`'s result screen only shows aggregate counts (`success`/`updated`/`duplicates`/`error`/`feesPending`) — **zero per-student SRNO is ever displayed**, confirmed by grep (zero `srNo` references in the result-rendering JS) |
| **Bulk-import credentials PDF handout** | **GAP FOUND — MISSING** | `_runStudentImport()`'s `$credentials[]` array (`Sis.php:5000-5005`) contains only `name`/`phone`/`userId`/`password` — no `srNo` key, confirmed by direct source read. The downloadable PDF (`import_credentials_pdf.php` view, fed by this array) therefore cannot show it either |
| **Student list (`sis/students`/`all_student`)** | **GAP FOUND — MISSING** | Confirmed by grep: zero `srNo`/`SR Number` references anywhere in `application/views/sis/students.php` — the roster table has no SRNO column |
| Certificates/TC/Documents | **NOT APPLICABLE per current design** — see Part B | `Certificates.php` reads `Admission Number` (falls back to Student ID), never `srNo` directly |

**Two genuine, newly-confirmed gaps requiring a decision, not yet implemented:** bulk-import's
result/credentials surfaces and the student list roster never show SR Number, even though every
single-student admission path now does. This is an inconsistency worth closing in a future pass —
**not implemented in this audit-only turn.**

## A3. Automatic SRNO Business Rule — Re-Confirmed, Not Re-Investigated

Re-confirmed from source (not re-derived from scratch, per instruction not to repeat completed
work): `Sr_no_service::allocateNext(string $schoolId, string $studentId)` is called from exactly
three places — `Sis::_claimIdentityAttributes()` (shared by `enroll_student()` and
`save_admission()`, Phase 9.1) and `_runStudentImport()`'s New-Admission-mode branch (Phase 9.2).
All three converge on the identical, unmodified atomic primitive. `Sr_no_service.php` was **not**
touched in this pass — confirmed by `git diff` showing zero changes to that file since Phase 9.1's
own final state.

## A4. No Manual School Selection — Re-Confirmed Across All Paths

Re-confirmed by fresh grep this pass (not merely cited from memory):
```
grep -n "input->post('school|input->get('school|post('schoolId'|post('school_id'|post('tenant" Sis.php   → 0 matches
grep -n "input->post('school|...                                                Readmission.php → 0 matches
```
Every admission path (`enroll_student`, `save_admission`, `_runStudentImport`, `Readmission::confirm`)
derives school context exclusively from `$this->parent_db_key`/`$this->school_id`, themselves set
only from server-side session/Firebase-Auth-claims in `MY_Controller.php` — unchanged since every
prior phase's identical finding. **No client-controlled school identity was found anywhere in the
admission flow. No security issue to report.**

---

# PART B — Admission Number Decision (Investigation Only, No Implementation)

1. **What "Admission Number" means in established school ERP workflows:** typically the school's
   own sequential, human-facing enrolment/register identifier printed on certificates, TCs, and
   report cards — conceptually closer to SRNO than to an internal system ID.
2. **What ZenX currently means by it:** nothing yet, operationally — the field is read
   (`Certificates.php:384,460`: `$student['Admission Number'] ?? $student['User Id']`) but **never
   written anywhere**, confirmed unchanged since the Phase 9.2 audit (re-confirmed this pass by
   grep, zero writers found).
3. **Does SRNO semantically equal Admission Number in ZenX?** Conceptually, yes — both are meant to
   be the school's own sequential, human-facing number. This is exactly why the original
   `SR_NUMBER_AND_APAAR_ID_DESIGN.md` proposed the dual-emit.
4. **Existing fields/documents/templates using it:** only `Certificates.php`'s two read sites; no
   admission form, receipt, or export references it.
5. **Do certificates/TC/report cards/admission forms/receipts/profile/apps/exports expect it?**
   Only `Certificates.php`, confirmed by repo-wide grep for `Admission Number`/`admission_number`/
   `admissionNumber` this pass — no other consumer exists.
6. **Would changing Student-ID-fallback to SRNO alter existing business meaning?** **Yes, materially.**
   Every certificate ever generated by any live ZenX tenant has printed the internal Student ID
   (e.g. `STU0042`) in this slot. Switching the source to SRNO would change what NEW certificates
   print without altering already-issued paper certificates — a real backward-compatibility/
   consistency question a school administrator would need to be aware of, not a pure code decision.
7. **Would existing documents remain backward compatible?** Already-issued PDFs are static and
   unaffected either way. The open question is purely about NEW certificates going forward.
8. **Recommendation (A/B/C/D):** **Option B (Admission Number = SRNO) is the architecturally
   correct target**, matching the original design's intent and SRNO's own newly-mandatory,
   sequential, human-facing nature (Phase 9.1) — SRNO is now precisely the "school's own sequential
   enrolment number" Admission Number was always meant to be, and building a THIRD, separate
   canonical number (Option C) would be a needless new identity system, explicitly discouraged
   throughout this whole program.

**This is a genuine business decision, not made here.** Recommendation: **Option B**, implemented as
a small, additive dual-emit (`Admission Number = srNo`, matching the original design's field name,
touching only the write path that currently omits it — not `Certificates.php` itself, which needs
zero change since its own fallback logic already prefers `Admission Number` when present). **Waiting
for approval before any implementation.**

---

# PART C — Fees Architecture Map (traced from source, not filenames)

```
Student
 ↓ (Fee_lifecycle::assignInitialFees, admission-time)
Fee Structure (feeStructures, per class/section/session — Fees.php::save_fees)
 ↓ (Fee_lifecycle::buildAdmissionDemandSpecs / Fee_generation_service, concession-aware)
Fee Demand (feeDemands — one per month for monthly heads, one April demand for yearly heads)
 ↓ Due Date — ⚠ ONLY set by Fees.php's SEPARATE bulk "Generate Demands" path, NEVER by the
              admission-time generator (Fee_lifecycle) — a real, pre-existing, already-documented
              gap (memory: "Late Fee/Penalty prerequisites — 2 coupled gates"), re-confirmed
              unchanged by this audit
 ↓ Reminder — Fee_management::send_reminder(), MANUAL/admin-triggered only, no scheduler
 ↓ Penalty — Fees.php::compute_fines_for_student(), MANUAL/admin-triggered only, requires dueDate
 ↓ Collection — FeeCollectionService::submit() (idempotent, sharded receipt counter)
 ↓ Receipt — feeReceipts, numbered via Fee_sharded_counter (mod-10 sharded atomic claim)
 ↓ Accounting — Operations_accounting::create_fee_journal() — ⚠ RTDB-BACKED (see §H)
 ↓ Defaulter — Fee_defaulter_check / Fee_dues_check, feeDefaulters (deleted when dues reach 0)
 ↓ Parent Notification — Push_service (FCM) works inline; WhatsApp/SMS/Email only QUEUE, no
                          connected external dispatcher found in-repo
```

**Firestore collections in active use:** `feeStructures`, `feeDemands`, `feeReceipts`,
`feeReceiptAllocations`, `feeReceiptIndex`, `feeDefaulters`, `feeSettings`, `feeReminderLog`,
`studentDiscounts`, `studentConcessions`, `studentServiceEnrollments`, `studentEntitlements`/
`studentFeeCredits` (Phase B successor), `scholarshipAwards`, `feeOnlineOrders`, `feeAuditLogs`/
`feeAuditEvents`, `feeOpsAlerts`, `feeIdempotency`, `feeLocks`, `feeCounters`,
`feeCounterShards`, `feeDemandLocks`, `feePendingWrites`, `fee_generation_jobs`, `feeRefunds`,
`feeRefundVouchers` — an already extensive, already-hardened Firestore data model. **This workstream
must extend it, never replace it.**

---

# PART D — Parent Fee Reminder Audit

| Question | Answer | Evidence |
|---|---|---|
| 1. Identify upcoming fees | Yes — `Fee_management::fetch_due_students()` scans `feeDemands` | `Fee_management.php:2203-2335` |
| 2. Identify fees due today | Implicit via due-date comparison in the same scan | Same |
| 3. Identify overdue fees | Yes, same scan | Same |
| 4. Identify parent/student | Yes — student→parent resolution already exists (used by push send) | `Fee_management.php:2457-2496` |
| 5. Can it send a reminder | **Push: yes, working.** WhatsApp/SMS/Email: **no** — queues only | `Fee_management.php:2511` (push) vs `2506-2510` (queue-only, explicit "TODO" comment naming an external dispatcher that doesn't exist in-repo) |
| 6. Channels | `log`/`whatsapp`/`sms`/`email`/`push` — only `push` actually delivers | Same |
| 7. Duplicate-reminder prevention | Not found — each `send_reminder()` call is independent; no cooldown/dedupe check before dispatch | Confirmed by reading the full method — no "already reminded today" guard |
| 8. Reminder history | Yes — `feeReminderLog`, with a "Last Reminder" column already surfaced in the UI | `Fee_management.php:2295-2306` |
| 9. Configurable | Yes — `get_reminder_settings`/`save_reminder_settings` (`auto_remind`, `days_before_due`, `reminder_message`, `due_day_of_month`) | `Fee_management.php:2124-2197` |
| 10. Admin enable/disable | `auto_remind` flag exists **but nothing reads it** — stored preference with no consumer | Confirmed by grep: no code path checks `auto_remind` to gate an automatic send |
| 11. Configurable timing | `days_before_due` array exists, same problem — **no scheduler consumes it** | Same |
| 12. Respects exemptions/concessions | Indirectly — `fetch_due_students()` reads `feeDemands.balance`, which `Fee_generation_service` already nets against concessions at generation time | Inherited correctness, not independently re-verified this pass |
| 13. Respects fully-paid | Yes — balance-based, a paid demand has `balance` ≈ 0 | Same mechanism as defaulter check |
| 14. Avoids reminders after payment | Yes, same reasoning — no separate "already paid" bug class found | — |
| 15. Handles partial payments | Yes — balance-based, not paid/unpaid boolean | — |
| 16. Handles multiple demands | Yes — `fetch_due_students()` aggregates `unpaid_months`/`total_due` per student | `Fee_management.php:2286-2291` |
| 17. Works across sessions | Scoped by `schoolId`+`session` in the query | `Fee_management.php:2302-2303` |
| 18. Tenant isolation | Yes — same school/session-scoped query pattern as every other Fees read | — |
| 19. Integrates with Communication infra | Partially — reuses `Push_service`/FCM (the SAME infra Communication uses elsewhere); does NOT reuse a generic template/notification-preferences system for WhatsApp/SMS/Email (none was found connected) | — |

**Classification: PARTIALLY IMPLEMENTED.** The identification logic, history log, settings UI, and
push channel are real and working. **The single missing piece that matters most: no automatic
trigger exists anywhere** — `auto_remind`/`days_before_due` are configuration with no consumer. The
Cloud Functions `MARK_REGISTRY` even has a pre-registered `FEE_DUE`/`fee_reminder` push mark
(`functions/index.js:123`) that is **never called** by any PHP code (confirmed by grep — zero
`emit_push('FEE_DUE'` sites) — a second, independent piece of evidence for the same conclusion,
via a completely different mechanism than the manual `send_reminder()` path.

---

# PART E — Late Fee / Penalty Audit

| Question | Answer | Evidence |
|---|---|---|
| Grace period | Implicit only — a fine only computes once `due_date < today`, no separate configurable grace-period field | `Fees.php:4406-4482` |
| Fixed penalty | Yes — `late_fee_type='fixed'`, flat `late_fee_value` | Same |
| Percentage penalty | Yes — `late_fee_type='percentage'`, `netAmount * value/100` | Same |
| Daily/monthly penalty | **Not found** — the calculation is a one-shot flat/percentage amount, not a per-day/per-month accrual | Confirmed by reading the full method — no date-delta multiplication |
| Maximum penalty cap | **Not found** | Same |
| Penalty start/end date | Only implicit via `due_date`; no separate penalty-window fields | Same |
| Penalty waiver | Not found in this specific code path — general concession/discount tooling (`Fee_concessions.php`) exists but is not wired to fines specifically | — |
| Principal/admin override | Not found for fines specifically | — |
| Reason for waiver | N/A — no waiver mechanism found | — |
| Audit trail | Partial — `fine_computed_at` timestamp is written onto the demand; no dedicated fine-specific audit event confirmed | `Fees.php:4455-4475` |
| Accounting posting | **Yes, real** — account code `4060` "Late Fees/Penalty" exists and is posted to when `fineAmount > 0` | `Operations_accounting.php:1108-1155,1253,1394-1405` |
| Receipt display | `fine`/`fine_amount` fields flow into the receipt write (`FeeCollectionService.php` receipt fields include `fine`) — not independently re-verified whether the receipt VIEW template renders it | — |
| Parent visibility | Not independently verified this pass — flagged NOT VERIFIED |
| Defaulter calculation | `balance` recompute after `fine_amount` is applied, so defaulter status stays correct | `Fees.php:4455-4475` |
| Partial payment handling | Inherited from the balance-based model | — |
| Exemption/concession interaction | **Real gap** — no evidence found that a fully/partially exempted demand is excluded from fine calculation; the method iterates "non-archived unpaid/partial demands," and an exempted demand's `netAmount` is already reduced but not necessarily excluded from the fine-eligible set | Needs explicit verification before implementation — flagged, not assumed either way |
| Recalculation safety | The method appears to overwrite `fine_amount`/`fine_computed_at` each call — **re-running it on the same demand could recompute rather than skip**, a real double-charge risk if triggered twice without a guard | Flagged as NOT VERIFIED whether idempotency exists here — needs source-level confirmation before any scheduler is built on top of it |
| Duplicate-penalty prevention | **Not confirmed** — same concern as above | — |

**Classification: PARTIALLY IMPLEMENTED / gated by a coupled prerequisite.** Real calculation logic
exists and posts correctly to Accounting, but is (a) manually triggered per-student, never automatic,
and (b) **only reachable for demands that have a `dueDate`** — and the admission-time generator
(`Fee_lifecycle::assignInitialFees`, the path EVERY normally-admitted or bulk-imported student's
demands actually come from) **never sets one**. This exact coupling was already identified and
tracked in this program's own memory before this audit (`admission_services_exemptions_research.md`
— "Late Fee/Penalty prerequisites — 2 coupled gates: every generator must set dueDate (only Fees.php
does); proRateFees() must become fine-aware; fixing first activates second") — **re-confirmed
unchanged by this pass's fresh source read, not merely cited.**

---

# PART F — Lost/Duplicate ID Card Audit

**Classification: MISSING — confirmed by repo-wide search, not assumed from a similarly-named file.**

`Sis::id_card()` (`Sis.php:3171-3225`) is a pure list-and-print generator — pulls active, enrolled
students and renders a print template. It has **zero** concept of: a persistent card record, a card
status (active/lost/replaced), a card number distinct from Student ID, a reissue action, or any fee
linkage. Repo-wide, case-insensitive search for `reissue`, `duplicate card`, `lost card`,
`replacement fee` found matches only in unrelated features (QR attendance token reissue, a visitor
pass note) — **zero matches for anything ID-card-related.**

**Recommended lifecycle (design only, not built):**
```
ACTIVE → LOST/DAMAGED (operator or parent-initiated flag)
       → FEE REQUIRED (if the school has a configured reissue fee — see Part G)
       → PAYMENT (via the existing FeeCollectionService, not a new payment system)
       → REISSUED (new card record, incrementing a reissue counter)
       → OLD CARD INVALIDATED (status flag, not deleted — for audit history)
```
This would need: a new lightweight Firestore collection (e.g. `studentIdCards` — a per-student
history of issued cards, status, reason, linked receipt) and a small controller surface
(request/approve/reissue actions), RBAC-gated the same way other student-record mutations are.
**Not implemented in this audit-only pass.**

---

# PART G — ID Card Fee Integration Recommendation

**Recommendation: (B) an ad-hoc demand, using the existing canonical Fees pipeline — NOT a new
mini-payment system, NOT a configurable recurring fee head.**

Reasoning: `Fee_lifecycle::createModuleFee()` **already exists** and is used for one-off,
non-recurring charges (documented by the Explore agent's trace as handling Hostel/Transport one-off
demands) — this is the established ZenX pattern for exactly this shape of charge: a single,
triggered-by-event demand, not a recurring structure-driven one. An ID-card-replacement fee should
reuse `createModuleFee()` (or a near-identical sibling method, if `createModuleFee()`'s existing
signature doesn't cleanly generalize — to be determined at implementation time, not guessed here),
flow through the SAME `FeeCollectionService::submit()` for payment, and land in the SAME
`feeReceipts` numbering/accounting pipeline every other fee already uses. This satisfies every
explicit requirement: receipt exists (inherited), accounting exists (inherited, subject to §H's
RTDB caveat), payment status is reliable (inherited idempotency), duplicate collection is prevented
(inherited idempotency hash), and refund/reversal is available (`Fee_refund_service.php` already
supports demand reversal generically).

**A new, small, configurable fee-head-like setting** (is reissue chargeable at this school, and how
much) is still needed — recommended as a `feeSettings` entry (matching the existing
`late_fee_enabled`/`reminders` settings pattern), not a full new "fee head" concept requiring
chart-of-fees UI changes.

---

# PART H — Accounting Integration — CRITICAL FINDING, FLAGGED NOT SILENTLY WORKED AROUND

**`Operations_accounting.php` — the ONLY code path that posts a fee collection (including any future
late-fee or ID-card-fee collection) into the Chart of Accounts/Ledger — is fully RTDB-backed.**
Confirmed: `Operations_accounting.php:16-21` documents its own storage paths as
`Schools/{school}/Accounts/ChartOfAccounts/{code}`, `Schools/{school}/{year}/Accounts/Ledger/{entryId}`,
`Ledger_index`, `Closing_balances`, `Voucher_counters` — all classic RTDB tree paths, not Firestore
collections. `Fee_refund_service.php` itself independently confirms this, calling the
accounting-journal-reversal leg "the only external RTDB touchpoint left" and naming it as deferred
future work ("Session C," `Fee_refund_service.php:7-10,18`) — **this is a pre-existing, already-known,
already-tracked gap in the current codebase, not a new discovery, and not something this audit
invented.**

**This directly conflicts with this phase's own explicit instruction ("Fees implementation MUST
remain FIRESTORE ONLY... No RTDB... Do a repo-wide RTDB audit for any NEW Fees implementation").**
Any new late-fee-accounting-posting or ID-card-fee-accounting-posting that calls
`Operations_accounting::create_fee_journal()` — the only existing, correct way to post to the Chart
of Accounts — **would inherit this RTDB dependency**, because that is simply how the accounting
ledger already works today, for every fee ZenX has ever collected. This is **not a defect introduced
by this workstream** — it is the existing, load-bearing accounting architecture.

**This is flagged as an explicit decision point, not worked around silently:**
- **Option 1:** accept the existing RTDB-backed accounting integration as an out-of-scope,
  pre-existing dependency for THIS workstream (every other fee collection already depends on it;
  singling out only the two NEW fee types for a Firestore-only accounting bridge would create a
  worse inconsistency — two different accounting mechanisms for different fee types).
- **Option 2:** treat "migrate `Operations_accounting` to Firestore" as a hard prerequisite before
  building late-fee/ID-card-fee accounting posting — a much larger, separately-scoped migration this
  workstream did not audit and is not sized to absorb.
- **Recommendation: Option 1**, with the RTDB dependency explicitly named in the implementation
  report as an accepted, pre-existing exception — **not a new violation this workstream introduces**,
  and not something to be silently glossed over as "Firestore-only" when it factually is not, for
  this one integration point. **This requires your explicit acknowledgment before implementation
  proceeds, per the "STOP and report" instruction for exactly this class of finding.**

---

# PART I — Parent App / Teacher App Impact

**Parent App:**
- Fee reminder visibility: `feeReminderLog` rules already permit `isStaffOrOwnStudent()` read access
  — the DATA is already reachable if the app were to build a screen for it. **No app code found
  consuming it yet** (not independently verified this pass — flagged NOT VERIFIED, would need a
  dedicated Parent App source check before concluding either way).
- Overdue amount / payment status: likely already surfaced via existing Fees screens in the Parent
  App (pre-existing feature, out of this audit's scope to re-verify).
- Penalty visibility: fine fields exist on demand/receipt docs; whether the Parent App reads them is
  NOT VERIFIED this pass.
- ID-card replacement request/status: **N/A — the feature doesn't exist yet anywhere (§F)**.

**Teacher App:** the Explore agent's trace confirms `feeReminderLog` rules explicitly grant Teacher
App read access to show a "✓ Reminded" pill on defaulter rows (`firestore.rules:879`) — **so the
Teacher App DOES have an existing, designed fee-adjacent surface**, contradicting a naive
"Teacher App probably needs no fee access" assumption. This must be re-confirmed against actual
Teacher App source before the next phase, not assumed either way from the rules comment alone.

**PARENT APP / TEACHER APP — NO CODE CHANGE IN THIS AUDIT-ONLY PASS.** Both need a dedicated,
evidence-based check in the implementation phase before any new screen is ruled in or out — this
audit did not open either mobile repo this turn (time/scope-bounded), and states that limitation
honestly rather than guessing.

---

# PART J — Communication Integration Audit

Existing infrastructure to reuse, not replace:
- `Push_service` → FCM — already working, already used by `send_reminder()`'s push channel.
- The Cloud Functions `MARK_REGISTRY` dispatcher pattern (per `functions/index.js` and this repo's
  own CLAUDE.md) — `FEE_DUE`/`FEE_PAID` marks already exist; `FEE_DUE` is registered but unused.
- `feeReminderLog` — already the correct, rules-protected place to record dispatch history,
  duplicate-prevention state, and delivery status.

**What does NOT exist and must not be re-invented as a "new notification framework":** a working
WhatsApp/SMS/email dispatcher. The existing code explicitly defers this to an unnamed "external
dispatcher worker (Gupshup/MSG91/Twilio)" that is not present in this repository. **Building fee
reminders further should NOT attempt to build that dispatcher** — out of scope, a separate
integration decision — but SHOULD close the gap between the already-built settings
(`auto_remind`/`days_before_due`) and an actual trigger, for the channel that DOES already work
(push), which is a bounded, achievable scope for this workstream.

---

# PART K — Exemption/Concession Interaction

- `Fee_concessions.php`/`Fee_concession_reader.php` already correctly reduce `netAmount` at demand
  GENERATION time (percent/fixed/fullExempt, scoped and date-windowed) — confirmed by the Explore
  agent's trace of `Fee_generation_service::generateDemandsForStudent`.
- **Late penalty must not apply to an already-exempted amount:** genuinely unverified this pass
  (§E) — `compute_fines_for_student()`'s exact interaction with a `fullExempt` demand was not traced
  to a definitive answer; flagged as a required pre-implementation check, not assumed safe.
- **Reminder must not demand a fully-exempted/fully-paid amount:** balance-based demand scanning
  already naturally excludes these (a `fullExempt` demand should have `balance` at or near 0) —
  reasonably safe by construction, though not independently re-verified with a live data check.
- **ID-card fee must remain separate from tuition exemption logic:** by design (§G's
  `createModuleFee()`-style ad-hoc demand is a distinct demand record, not a tuition fee head), this
  is naturally satisfied — a tuition exemption rule has no reason to ever match an ID-card demand's
  scope/category.

---

# PART L — Professional ERP Comparison

| Feature | CURRENT ZENX | REQUIRED | Classification |
|---|---|---|---|
| Fee structure/chart | Full, mature | Yes | **IMPLEMENTED** |
| Fee assignment (admission-time) | Full, concession-aware | Yes | **IMPLEMENTED** |
| Fee demands, installments | Full (monthly/yearly) | Yes | **IMPLEMENTED** |
| Due dates | Only via the separate bulk generator, not admission-time | Yes, universally | **DEFECTIVE (pre-existing, re-confirmed)** |
| Collection/receipts | Full, idempotent, sharded numbering | Yes | **IMPLEMENTED** |
| Concessions/exemptions | Full, scoped, date-windowed | Yes | **IMPLEMENTED** |
| Defaulter tracking | Full, self-healing | Yes | **IMPLEMENTED** |
| Accounting posting | Full logic, RTDB-backed | Firestore preferred | **IMPLEMENTED WITH KNOWN PRE-EXISTING RTDB EXCEPTION (§H)** |
| Refunds/reversals | Full, with idempotency | Yes | **IMPLEMENTED** |
| Parent fee reminders (automatic) | Settings exist, manual send works for push, no scheduler | Yes | **PARTIALLY IMPLEMENTED** |
| Parent fee reminders (WhatsApp/SMS/Email delivery) | Queue-only, no dispatcher | Yes, ideally | **MISSING** (external dependency, likely out of this workstream's scope) |
| Late fee/penalty calculation | Real logic, manual-trigger-only, gated by the dueDate gap | Automatic, universal | **PARTIALLY IMPLEMENTED / DEFECTIVE (coupled prerequisite)** |
| Late fee waiver/override | Not found | Recommended | **MISSING** |
| ID card replacement/reissue workflow | Does not exist | Yes (explicitly requested) | **MISSING** |
| ID card replacement fee/accounting/receipt | Does not exist (feature absent) | Yes | **MISSING** |
| Auditability (Fees generally) | Strong — `feeAuditLogs`/`feeAuditEvents`, immutable rules | Yes | **IMPLEMENTED** |

---

# PART M — Firestore / Security

- **Reconciliation:** re-confirmed unchanged this pass — `integration/phase2` has no remote
  counterpart (`git ls-remote --heads origin`), nothing to pull/reconcile, same finding as every
  prior phase.
- **No rule or index was modified this pass** — audit only, per explicit instruction.
- **Expected future needs, not yet built:** a new `studentIdCards`-style collection (§F) would need
  its own rule block (staff read/write within school, parent read own child, mirroring the existing
  `feeReceipts`/`feeDemands` pattern exactly — no new pattern to invent). A `feeSettings` addition
  for ID-card-fee/late-fee-automation config needs no new rule (the `feeSettings` match block
  already covers the collection generically). **No index is anticipated** — no new query shape is
  proposed beyond patterns already in use (exact-doc-id reads, school+session equality filters).

---

# PART N — Zero RTDB (New Work)

Repo-wide RTDB audit for anything this workstream would touch: confirmed clean **except the
already-flagged, pre-existing `Operations_accounting.php` dependency (§H)**, which this workstream
does not create and — per the recommendation in §H — should not be silently routed around either.
No new RTDB path, fallback, mirror, dual-write, or bridge is proposed anywhere in this plan.

---

# PART O — Performance / Idempotency

| Concern | Existing protection | Gap for new work |
|---|---|---|
| Duplicate reminder sends | **None found** (§D item 7) — must be added: a `feeReminderLog` lookup for "already reminded today/this cycle" before dispatch |
| Repeated penalty calculation | **Not confirmed idempotent** (§E) — `compute_fines_for_student()` appears to overwrite `fine_amount` on every call; must be verified/hardened before any automatic scheduler calls it, or a double-charge risk exists |
| Repeated payment | Already protected — `FeeCollectionService::submit()`'s idempotency hash |
| Duplicate ID-card replacement charge | N/A yet (feature doesn't exist) — the recommended `createModuleFee()`-based design should inherit the SAME idempotency-hash pattern, not invent a new one |
| Repeated receipt creation | Already protected — sharded atomic counter claim |
| Concurrent collection | Already protected — atomic claim primitives throughout |
| Concurrent penalty application | **Not confirmed protected** — flagged alongside the recalculation-safety gap above |

**Server-side protection, not merely frontend button-disabling, is mandatory for any new feature —
consistent with how every existing Fees primitive in this codebase already works** (the pattern to
extend, not invent).

---

# EXECUTIVE SUMMARY

**What already works:** the core Fees engine (structures, demands, concessions, collection,
receipts, defaulters, refunds) is mature, Firestore-first, idempotent, and well-audited. A real,
working late-fee CALCULATION exists, and a real, working PUSH-based reminder SEND mechanism exists —
both more built than a naive "grep for the word 'penalty'" pass would suggest, per this program's
own standing discipline to trace actual functionality rather than infer from filenames.

**What is missing:** an automatic/scheduled trigger for reminders (settings exist, nothing consumes
them); WhatsApp/SMS/Email delivery (queues only, no connected dispatcher); a universal due-date on
admission-time demands (the single root blocker for automatic late fees); ID card replacement as a
concept, entirely.

**What is defective:** late-fee calculation is gated behind the missing due-date on the majority of
real demands, and its own recalculation-safety (double-charge risk) is unconfirmed.

**What should be implemented first:** closing the due-date gap on `Fee_lifecycle::
buildAdmissionDemandSpecs` — this single fix is the prerequisite that unblocks automatic late fees
for the majority of real demands, exactly as this program's own memory already identified before
this audit began.

**Exact decisions requiring approval before implementation:**
1. Admission Number = SRNO (Part B) — recommended, not decided.
2. Accepting the pre-existing RTDB-backed accounting integration as an explicit, out-of-scope
   exception for this workstream (Part H) — recommended, not decided; this is the single most
   important approval needed, since it affects every new fee-collection feature in this phase.
3. ID card replacement fee architecture (Part G) — `createModuleFee()`-based ad-hoc demand
   recommended, not decided.
4. Whether to build the due-date fix first as its own bounded sub-phase before either reminders or
   penalties (recommended sequencing, not decided).

**Complete implementation roadmap (proposed, not started):**
1. Fix `Fee_lifecycle::buildAdmissionDemandSpecs` to set a universal due date (small, high-leverage,
   unblocks late fees for admission-created demands).
2. Verify and, if needed, harden `compute_fines_for_student()`'s idempotency before any automatic
   trigger is built on top of it.
3. Build the automatic reminder trigger for the push channel only (the one already-working delivery
   mechanism) — reusing `auto_remind`/`days_before_due` settings that already exist but are unused.
4. Build ID card replacement as a new, small, additive feature: `studentIdCards` collection +
   `createModuleFee()`-based fee + existing `FeeCollectionService` payment flow.
5. Re-audit Parent App/Teacher App source directly (not assumed) before deciding what, if anything,
   needs a new screen.

**Nothing was implemented, staged, committed, or pushed in this pass.**