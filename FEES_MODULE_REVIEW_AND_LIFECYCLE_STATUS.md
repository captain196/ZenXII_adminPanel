# Fees Module Review + Student Lifecycle Status

**Date:** 2026-09-19 · Reviewed against current code (`Fees.php` 8,833 lines, `Fee_management.php`
7,755, `FeeCollectionService.php` 2,541, `views/fees_counter.php` 5,547, `views/fees/`).

---

## PART 0 — Remaining lifecycle items: status

- **Rules gate (`isActiveStudent`)** — code done + emulator-verified (8/8). A clean, deploy-ready file
  `firebase-rules/firestore.rules.STUDENTGATE_READY` is prepared = **deployed baseline + ONLY the
  students gate** (no other session's WIP) and **compile-verified**. Not deployed: I can't confirm from
  here that the baseline still matches live, and shipping a stale ruleset could revert rules deployed
  since. Deploy = copy it over `firestore.rules` in a clean checkout →
  `firebase deploy --only firestore:rules --project graderadmin`.
- **Payment atomicity** — resolved as handled-by-design (reconciler implemented). Open item is an OPS
  check that `AccountingReconciler` runs on the production cron.
- **C / D / E** — DONE + dynamic (per-school toggles on the Fees policy screen).

---

## PART 1 — Student Lifecycle: features COMPLETE (this programme)

| Stage | Status | What's in place |
|---|---|---|
| **Admission (walk-in + CRM)** | ✅ | Fail-closed on missing fee structure; SRNO invariant (walk-in rolls back, CRM flags loud); `admno_assigned` signal; **idempotency mutex** (double-POST no longer mints two students) |
| **Identity** | ✅ | studentId (globally unique, atomic), SRNO (school-scoped, atomic claim), admissionNumber, APAAR — all atomic, tenant-scoped |
| **Placement + capacity** | ✅ | Section seat CAS guard — version-before-count, nonce'd, fails closed; the strongest part |
| **Status transitions** | ✅ | Active/Inactive/withdraw/delete; fee-book freeze consistent across ALL exit doors; **Auth login disabled on every exit** (retained-access fix); **terminal-state guard** (TC/Alumni can't be silently reactivated); **defaulter mirror recomputed on freeze** |
| **Transfer / TC** | ✅ | TC = freeze + Auth-disable + full reverse (cancel_tc); section transfer relabels current-session demands; **cross-class re-pricing is a per-school toggle** |
| **Promotion / rollover / graduation** | ✅ | Terminal class derived from `schools.classes` (not hardcoded), CAS placement, all-or-nothing, operator warned of empty new-session book; **Alumni re-enrolment now warns** |
| **Cross-system (Parent app)** | ✅ | Live admin→Firestore→app sync proven on a physical device |
| **Tenant isolation** | ✅ | Zero cross-tenant references (runtime-proven) |
| **Attendance (lifecycle-coupled)** | ✅ | Exit blanks/cascades attendance; inactive students drop off roster; **C1 offline queue device-proven** |
| **Security boundary (rules)** | ⚠️ code done, deploy-pending | `isActiveStudent` GET gate verified; Auth-disable already closes the practical exposure |

**Owner-policy toggles now available (default OFF):** all-years TC clearance; re-price fees on class
transfer. **Ops-only remaining:** deploy the rules gate; confirm the reconciler cron.

---

## PART 2 — Fees module: page inventory

### Full pages (menu/route → view)
**Fees controller:** `fees/fees_counter` (the collection counter), `fees/dashboard`,
`fees/student_ledger`, `fees/defaulter_report`, `fees/fees_chart`, `fees/student_fees`,
`fees/class_fees`, `fees/fees_records`, `fees/print_receipt/{no}`, `fees/transaction_audit`,
`generate_demands`, `queue_dashboard` (last two reachable only via CI default routing — no explicit
route). `fees/fees_structure` → deprecated redirect to `fee_management/categories`.

**Fee_management controller:** `fee_management/categories` (fee heads), `/discounts`, `/scholarships`,
`/refunds`, `/reminders`, `/gateway`, `/online_payments`, `/student_charges`,
`/payment_reconciliation`. Plus `fee_concessions` (Exemption v2) and `fee_simulation`.

### AJAX endpoints
~40 on `Fees` (lookup_student, fetch_months, fetch_fee_details, submit_fees, search_student,
print/receipt_status, discount recalc, demand generation/preview, queue admin, year-rollover,
integrity/backfill) and ~60 on `Fee_management` (CRUD for heads/discounts/scholarships; full refund
lifecycle; gateway + webhook + reconciliation; student charges; get/save_blocking_policy).

### Housekeeping flags found
- **Dev/QA mutators are public methods on the live controller:** `void_test_receipt`,
  `test_reset_student`, `verify_session_b/c` — role-gated + prod-rejected, but shouldn't be reachable
  methods on the production counter controller.
- **`fees_structure.php` view is orphaned/unreachable** (only a redirect maps near it).

---

## PART 3 — Fee counter page: working + UI/UX

**Page:** `Fees::fees_counter` → `views/fees_counter.php`. Role gate `COUNTER_ROLES` (`Fees:edit`).
A **4-step single-screen wizard** with a sticky Payment Summary card.

1. **Find student** — one box accepts Student ID *or* SR No (`lookup_student` → canonical
   `Sr_no_service`); or a Browse-by-name modal (`search_student`; roll number deliberately NOT
   searchable to prevent wrong-child posting). The UI **echoes which identifier matched**
   ("✓ SR No. 2 → STU0032 · Name") — real anti-mis-post design.
2. **Load dues** — `fetch_months` returns a per-month map + summary (gross/paid/discount/remaining);
   a "No fees generated" empty state suppresses the misleading ₹0 tiles.
3. **Allocate** — 3-state month tiles (Paid/Partial/Unpaid), April auto-bundles the yearly tile,
   oldest-first allocation preview with "exceeds due" warning, cash/bank payment-mode dropdown from
   the Chart of Accounts, on-file discount banner + one-off discount modal.
4. **Submit** — confirmation modal (Due/Fine/Cash-now/Remaining) → `submit_fees` →
   `FeeCollectionService::submit()` (session-freeze + period-lock guards run first). Async mode blocks
   printing and polls `receipt_status` until posted. Receipt via `print_receipt/{no}` with school
   branding.

**UI/UX character:** genuinely good for a till — scan/Enter-friendly, spinners + disabled states,
confirm-before-money, honest async receipt gating, XSS-safe alerts, focus-trapped modals. Weak spots:
a 5,547-line single view, the ID field lacks `inputmode="numeric"` (no numeric keypad on a tablet),
and there's dead/duplicated markup (below).

---

## PART 4 — Critical review + ratings

| Dimension | Rating | Notes |
|---|:---:|---|
| Correctness & money-safety | **8/10** | Overpayment rejected server-side; demand read fails CLOSED (throws, not `[]`); fine can't be silently waived; atomic receipt claim + idempotency; client checks `r.ok` AND `status:'error'`. Bug: `void_test_receipt` reads `$did` before `$d` is assigned (dev-only). |
| Tenant & session scoping | **9/10** | Money reads scoped by school + session + studentId; receipt/display counters correctly split (lifetime key vs per-session serial). Latent: `fetch_fee_receipts` history query has no session filter (safe only while receipt numbers are globally unique). |
| UI / UX | **7/10** | Strong anti-mis-post + confirm + async gating; clumsy: 5.5k-line view, no numeric inputmode, dead controls. |
| Robustness | **8/10** | Double-submit disabled both entry points + server lock/hash; tolerant JSON parse; real empty/edge/network states; stale-tab caught server-side. |
| Consistency & polish | **6/10** | **Duplicate `grantDiscountModal` block (colliding IDs, dead markup)**; `defaulter_report.php` uses native `alert()` ×8 vs styled toasts elsewhere; dead RTDB-noop scaffolding in `void_test_receipt`; multi-thousand-line files. |

### OVERALL: **7.5 / 10**
**Verdict:** the collection engine is production-grade and safe to take real money — fail-closed reads,
server-authoritative overpayment/fine/duplicate guards, atomic receipt claims, correct tenant+session
scoping, and a UI that actually fails closed. It's held back from 8+ by polish debt: a shipped
duplicate modal with colliding IDs, a use-before-assignment bug in the void utility, session-less
receipt-history queries relying on an unwritten invariant, dev mutators public on the live controller,
and multi-thousand-line files — none money-losing today, but the kind of rot that breeds the next bug.

### Highest-impact fixes — OUTCOME (2026-09-19)
1. **DONE** — deleted the duplicated `grantDiscountModal` block (`fees_counter.php`); now a single
   modal (no colliding IDs), view lint-clean.
2. **DONE** — fixed the `$did`/`$d` use-before-assignment in `void_test_receipt` (`Fees.php`): read
   `$d` first, take the doc id from `$row['id']` so the `feeIdempotency` delete targets the right doc.
3. **WITHDRAWN** — `inputmode="numeric"` is WRONG here: the ID field accepts both alphanumeric Student
   IDs (`STU0012`) and numeric SR numbers, so forcing a numeric keypad would block Student-ID entry.
4. **DONE (as documented assertion, not a filter)** — `fetch_fee_receipts` is *intended* full
   cross-session payment history; a session filter would wrongly hide prior years. Added an explicit
   INVARIANT comment (safe because receipt ids are globally unique per school; revisit if numbering
   ever becomes per-session).
5. **NO CHANGE NEEDED** — the dev/QA mutators (`void_test_receipt`, `test_reset_student`,
   `verify_session_b/c`, `verify_test_cleanup`) already call `_reject_in_production()` →
   `show_404()` in any non-`development` environment, so they are already hard-gated off production.

Verification: all touched files lint-clean; the only test error/failure in the Fees run are the
PRE-EXISTING `Sis::$seatGuard` import-harness stub and a cross-repo Kotlin source-scan baseline
failure (both confirmed to fail identically with my changes stashed). Zero new failures.

### Optional remaining polish (not applied — cosmetic)
- `fees/defaulter_report.php` uses native `alert()` ×8 vs the styled toasts elsewhere — a consistency
  nit, not a defect. Can be swapped to `showToast` on request.
- The multi-thousand-line controllers/view are a maintainability tax (no behaviour impact).
