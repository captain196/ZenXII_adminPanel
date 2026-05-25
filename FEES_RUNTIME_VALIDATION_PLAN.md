# Fees Runtime Validation Plan — Stage 1

**Authorized:** 2026-05-22 (operator directive: *"Runtime-validation phase authorized. Begin planning and orchestration for staged runtime validation, starting with fees module only."*)
**Phase:** First entry to runtime/staging validation engineering for the v7 campaign
**Stance:** Planning and orchestration only. No autonomous fixes. No static rediscovery expansion. No schema migration. No production-certification claim.
**Operating model:** evidence-triggered — fixes only proceed on the basis of empirically-observed staging telemetry, not speculative pattern-matching.
**Companion artifact:** [V7_CAMPAIGN_REPORT_SESSIONS_1_4.md](V7_CAMPAIGN_REPORT_SESSIONS_1_4.md) (closes the autonomous static-hardening phase)

---

## 0. Authorization scope and explicit limits

### 0.1 What this authorization covers

- **Planning and orchestration** for staged runtime validation of the **fees module only**
- Producing this artifact and operator checkpoints
- Read-only reconnaissance against staging Firestore (no writes)
- Razorpay test-mode sandbox flows initiated by operator
- Telemetry observation and classification per the locked response contract (§7)
- Evidence merge to BUG_LEDGER when staging signal warrants it (DISCOVERY trigger, not autopilot-initiated)

### 0.2 What this authorization does NOT cover (other gates preserved verbatim)

| Gate | Status post-authorization |
|---|---|
| accounting runtime-gate | **unchanged — still gated** (Strategy C-A; soak continues per [[accounting_soak_contract]]) |
| hr_payroll runtime-gate | **unchanged — still gated** (Strategy C) |
| staff_hardening patches 6–15 | **unchanged — still HOLD** |
| BUG-037 (school_config save_classes lock+CAS) | **unchanged — still deferred** (UX coord) |
| BUG-040 / 041 / 042 (mobile attendance carries) | **unchanged — still deferred** |
| Snapshot-commit posture | **unchanged — still deferred** |
| Schema migrations | **prohibited** within this plan |
| Lock-gate lifting on any other module | **prohibited** within this plan |
| Static rediscovery expansion | **prohibited** within this plan |

### 0.3 What "runtime-validated" will mean at the end of Stage 1

If the operator completes the full validation sequencing in §6 and the campaign emerges with no `FREEZE_REQUIRED` signals, the fees module will move from `runtime-gated` to `runtime-soak-completed`. **This is still not a production-certification claim.** Soak-completed means: representative scenarios have been exercised against staging Razorpay sandbox and observed telemetry was within the expected envelope. It does not constitute a release decision.

---

## 1. Payment lifecycle validation

Validates that the Razorpay sandbox payment path produces correct end-state across the admin write path, the projection (`feeDefaulters`), the parent app's read path, and the accounting integration boundary.

### 1.1 Razorpay sandbox happy-path — single student, single demand

**Scenario:** parent initiates payment via parent app → Razorpay sandbox accepts → webhook delivers → admin processes → projection updates → parent re-reads.

**Entry-point endpoints (per [[razorpay_dashboard_next]]):**
- `fee_management/parent_create_order` (Firebase ID-token auth, derives school_id from claims)
- Razorpay sandbox checkout (`com.razorpay:checkout:1.6.38` in parent app)
- `fee_management/parent_verify_payment` (cross-student guard checks order's student_id matches token uid)
- `Fee_management::payment_webhook` (webhook receiver — signature verified)

**Observable evidence checklist:**
- `feeOnlineOrders/{orderId}` document created with correct `student_id`, `school_id`, `amount`, `status='created'`
- `feeOnlinePayments/{paymentId}` document written with `status='captured'` after success
- `feeDemands/{studentId}_{session}` updated — corresponding demand row `paid_amount` += transaction amount; `payment_status` transitioned
- `feeDefaulters/{schoolId}_{session}_{studentId}` projection refreshed — `totalDues` recomputed (excluding Hostel/Transport per TC-3 partition rule, see [[fees_canonical_architecture]])
- Parent app receipt visible in payment-history list within refresh window (see §4.1)
- Accounting journal posted (income recognition + cash/bank Dr) — verify via accounting collection; expected event `ACC_JOURNAL_COMMITTED` if accounting integration emits it

**Expected timing envelope (Tier 1 target):**
- Razorpay callback → admin verify endpoint: < 2s under sandbox load
- Admin verify → demand update + projection update: synchronous within `_verify_and_process()`
- Parent app visibility: depends on listener-mode (Firestore real-time) vs poll-mode — to be measured

### 1.2 Duplicate callback handling

**Scenario:** webhook delivers same payment event twice (Razorpay retry under their delivery guarantee).

**Test method:** in Razorpay dashboard, manually re-deliver a successful webhook via "Resend" function on the same payment ID.

**Observable expected behavior:**
- Second webhook arrival is detected as duplicate (idempotency key on `paymentId` or order+payment composite)
- `feeOnlinePayments/{paymentId}` is NOT re-written with new state
- `feeDemands` `paid_amount` does NOT double-increment
- `feeDefaulters.totalDues` does NOT double-decrement
- Telemetry: expected duplicate-suppression event (e.g. `WEBHOOK_DUPLICATE_SUPPRESSED` — name TBD by source inspection during scenario, NOT pre-modified)
- Optional: webhook_log doc may record both deliveries with second flagged as `replay=true`

**Risk if behavior diverges:** `FREEZE_REQUIRED` — duplicate ledger entry / double-credit / double-decrement of dues are all financial-integrity violations.

### 1.3 Retry ordering

**Scenario:** parent app initiates payment → network glitch → parent app retries before first attempt resolves → both reach Razorpay → both succeed at gateway → both webhooks deliver.

**Test method:** simulate via two parent-app instances (or a scripted retry) issuing parent_create_order for the same demand within a short window.

**Observable expected behavior:**
- Either: second order is blocked at create-time by existing pending-order check
- Or: both orders are accepted but only one payment captures (Razorpay-side natural deduplication on order_id)
- Or: both payments capture but the second is reconciled as overpayment (advance balance) — **note this is a design decision the staging signal must confirm**
- No demand row should accept double-payment without an explicit advance-balance ledger entry

**Risk if behavior diverges:** `FREEZE_REQUIRED` if double-credit applied silently. `INVESTIGATE` if behavior is bounded-correct but UX is unclear.

### 1.4 Webhook replay behavior

**Scenario:** Razorpay dashboard manual replay of a webhook from a previous day's payment.

**Test method:** identify a stable historical payment in staging; trigger "Resend" from Razorpay dashboard webhook log.

**Observable expected behavior:**
- Signature verification still passes (test secret is stable)
- Idempotency check matches existing `feeOnlinePayments/{paymentId}` doc → suppression
- No state mutation
- Telemetry signal: replay detection event with `paymentId` + age delta

**Risk if behavior diverges:** if replay mutates state, `FREEZE_REQUIRED`. If replay produces telemetry but no state change, that is `NORMAL`.

### 1.5 Payment-success vs ledger-write timing

**Scenario:** between Razorpay returning `success` to parent app and admin completing the ledger write (`feeDemands` + projection + accounting journal), the parent app may briefly see stale state.

**Test method:** measure delta from `parent_verify_payment` 200 response → `feeDefaulters.totalDues` reflecting the payment.

**Observable expected behavior:**
- Synchronous path: `_verify_and_process()` writes demand + projection + accounting journal within the same request → parent immediately re-fetches and sees updated state
- If any of the three writes are deferred (e.g. queued via `FeeWorker.php`): document the visibility window
- Parent app should not display "Payment failed" if Razorpay said success — even if ledger is mid-write

**Risk classification:**
- Bounded visibility window (< 2s) under sync path: `NORMAL`
- Visibility window > 5s sustained: `INVESTIGATE`
- Parent app showing failure on success: `INVESTIGATE` (UX-bounded, not financial-integrity)
- Demand updated but projection not refreshed within 30s: `INVESTIGATE`
- Demand updated but accounting journal missing entirely after 5min: `FREEZE_REQUIRED`

### 1.6 Failed-payment recovery paths

**Scenario A — gateway-side failure:** Razorpay returns failure (card declined, insufficient funds, etc.).
**Scenario B — verify-side failure:** Razorpay succeeds but `parent_verify_payment` returns 500.
**Scenario C — webhook-only success:** parent app loses network; webhook is the only path that completes.

**Observable expected behavior:**
- **A:** `feeOnlineOrders` status → `failed`. No demand mutation. No projection mutation. No accounting write. Parent app shows clear failure with retryable affordance.
- **B:** `feeOnlinePayments` exists with `captured` status from Razorpay; admin endpoint failed to verify. Webhook is expected to recover and complete the processing on its own delivery. **This is the most important recovery path to validate** because it's the silent-failure class.
- **C:** webhook completes `_verify_and_process` from the webhook path (not parent path); parent app on next refresh sees updated state.

**Risk classification:**
- Scenario B never recovers (webhook also fails or webhook handler missing the recovery branch): `FREEZE_REQUIRED`
- Scenario B recovers but takes > 60s: `INVESTIGATE`
- Scenario A leaves orphaned `feeOnlineOrders` for > 24h: `WATCH`

### 1.7 Parent retry behavior

**Scenario:** parent app, after a perceived failure, re-initiates payment for the same demand.

**Observable expected behavior:**
- New `feeOnlineOrders` doc created with new `orderId`
- If first attempt actually succeeded (silent-success): new order should be detected at verify-time as redundant, parent shown "already paid"
- If first attempt actually failed: new order proceeds normally
- Cross-student guard: token uid must match new order's student_id

**Risk classification:**
- Redundant retry after silent success accepted as new payment → double-credit: `FREEZE_REQUIRED`
- Redundant retry blocked with clear messaging: `NORMAL`

---

## 2. Reconciliation integrity

### 2.1 feeDemands ↔ feeDefaulters projection consistency

**Invariant:** for every `feeDemands` row with `pending_amount > 0`, the corresponding `feeDefaulters/{schoolId}_{session}_{studentId}` doc must have `totalDues` equal to the sum of `pending_amount` across all that student's pending demands (excluding `category in ('Hostel', 'Transport')` per TC-3 partition rule — see [[fees_canonical_architecture]]).

**Reconciler convergence test:**
- Force a manual `feeDemands` edit via admin panel (e.g. concession applied to one demand row)
- Verify `Fee_firestore_sync::syncDefaulterStatus` fires
- Re-read `feeDefaulters` projection
- Compare against canonical aggregation via `Fee_defaulter_check::isDefaulter()`

**Risk classification:**
- Drift > 0 sustained beyond 30s after demand edit: `INVESTIGATE` (auto-healing should converge)
- Drift > 0 sustained beyond 5min: `FREEZE_REQUIRED`
- Drift on `category='Hostel'` or `'Transport'` included in `totalDues`: `FREEZE_REQUIRED` (partition rule violation)
- Drift detected only on a single student under load: `INVESTIGATE`
- Drift detected across multiple students simultaneously: `FREEZE_REQUIRED`

### 2.2 Pending-write recovery

**Scenario:** admin write completes for demand, but projection write fails (network blip, Firestore transient error).

**Test method:** in staging, simulate via deliberate transient failure injection IF infrastructure exists; otherwise observe natural transient under burst load.

**Observable expected behavior:**
- Projection-write failure is detected (logged)
- Either: retry happens automatically (via `FeeWorker.php` deferred queue, if applicable)
- Or: next read-through path triggers a heal (the "self-healing projection" claim from [[fees_canonical_architecture]])
- Self-heal trigger: any reader hitting `Fee_defaulter_check::isDefaulter()` should re-aggregate from `feeDemands` and overwrite stale projection

**Risk classification:**
- Self-heal happens on next read: `NORMAL`
- Self-heal does NOT happen and read returns stale projection silently: `INVESTIGATE`
- Self-heal not happening AND no telemetry on the failed write: `FREEZE_REQUIRED` (observability gap on financial-integrity drift)

### 2.3 Idempotency guarantees

**Invariant:** the composite key `(school_id, paymentId)` must be idempotent across all of:
- `_verify_and_process()` (admin verify endpoint)
- `payment_webhook` (webhook handler)
- Direct admin "process payment" (if any administrative re-trigger exists)

**Test method:**
- Issue same payment through all three paths sequentially
- Verify exactly one `feeOnlinePayments` doc
- Verify exactly one demand mutation
- Verify exactly one accounting journal (if integrated)

**Risk classification:**
- Duplicate doc / duplicate mutation / duplicate journal: `FREEZE_REQUIRED`
- Same `paymentId` accepted by two paths but second is suppressed without telemetry: `WATCH`
- Same `paymentId` accepted by two paths with explicit idempotency-suppression telemetry: `NORMAL`

### 2.4 Orphaned payment detection

**Scenario:** payment captured at Razorpay → both verify endpoint and webhook fail to complete → payment exists at gateway but not in admin Firestore.

**Detection model expected:**
- Reconciliation job (likely cron, possibly manual admin tool) periodically polls Razorpay `/payments` for `captured` payments and cross-references against `feeOnlinePayments` doc set
- Orphans are flagged
- Operator can manually trigger re-processing

**Observable evidence to seek during Stage 1:**
- Does this reconciliation job exist? (scan during the controlled-evidence window of Tier 2 — do NOT pre-modify code)
- If yes: what is its trigger cadence?
- If no: orphan detection is operator-manual via Razorpay dashboard inspection

**Risk classification:**
- Orphans exist but are detected and processable: `NORMAL`
- Orphans exist with no detection mechanism: `WATCH` (latent operational risk, not financial-integrity)
- Orphan accepted as legitimate payment without re-verification against Razorpay: `FREEZE_REQUIRED`

### 2.5 Double-credit prevention

**Invariant:** no demand row should have `paid_amount` exceeding the sum of unique `paymentId`s applied to it.

**Test method:** during Tier 3 scenarios that exercise retry + webhook + duplicate callback paths concurrently, audit a sample of demand rows for this invariant.

**Risk classification:**
- Any demand row with `paid_amount > sum(unique paymentId amounts)`: `FREEZE_REQUIRED`

### 2.6 Cross-student isolation guarantees

**Invariant:** parent A's token cannot trigger a payment that mutates parent B's child's demand.

**Test method (controlled — operator must explicitly authorize):**
- Two test parent accounts with distinct Firebase UIDs
- Account A obtains a Razorpay order for child A
- Account A's verify call uses order_id from Account B's order (substituted manually)
- `parent_verify_payment` should reject with cross-student guard
- Telemetry expected: `CROSS_STUDENT_GUARD_HIT` or equivalent

**Risk classification:**
- Guard rejects + telemetry emits: `NORMAL`
- Guard rejects silently (no telemetry): `WATCH` (observability gap)
- Guard does NOT reject: `FREEZE_REQUIRED` (security + financial-integrity)

---

## 3. Runtime observability

### 3.1 Expected telemetry — happy path

For a single successful Razorpay sandbox payment, the **expected** telemetry event sequence (to be confirmed during Tier 1 — do not assume names without controlled observation):

| Order | Event class | Source | Notes |
|---|---|---|---|
| 1 | parent_create_order entered | Fee_management | parent app initiated |
| 2 | Razorpay order created | Payment_gateway_razorpay | external — observable in Razorpay dashboard |
| 3 | Razorpay checkout success callback | parent app PaymentBridge | external — Razorpay → MainActivity |
| 4 | parent_verify_payment entered | Fee_management | parent app verify call |
| 5 | Signature verified | Payment_gateway_razorpay | crypto check |
| 6 | Cross-student guard passed | Fee_management | order.student_id == token.uid |
| 7 | Demand mutated | Fee_firestore_sync | `feeDemands` updated |
| 8 | Projection refreshed | Fee_firestore_sync::syncDefaulterStatus | `feeDefaulters` updated |
| 9 | Accounting journal (if integrated) | accounting service | income recognition |
| 10 | Webhook arrival | Fee_management::payment_webhook | redundant — idempotency-suppressed |

### 3.2 Expected telemetry — failure path

For a captured-but-not-verified failure (§1.6 Scenario B):

| Order | Event class | Source |
|---|---|---|
| 1–5 | (same as happy path through signature verify) | |
| 6 | parent_verify_payment 500 | error path emission expected |
| 7 | Webhook arrival | recovery trigger |
| 8 | Webhook detects unprocessed `feeOnlinePayments` | recovery branch |
| 9 | Webhook completes `_verify_and_process` | recovery success |
| 10 | (then happy-path telemetry continues from demand mutation onward) | |

### 3.3 Operator-visible failure signatures

Expected to surface in admin observability surface (logs, audit_log, sec_telem). Names to be confirmed during Tier 1.

| Signature | Likely event class | Risk class if observed |
|---|---|---|
| Signature verification failure | `PAYMENT_SIGNATURE_INVALID` (TBC) | `INVESTIGATE` if isolated; `FREEZE_REQUIRED` if sustained |
| Cross-student guard hit | `CROSS_STUDENT_GUARD_HIT` (TBC) | `WATCH` if isolated; `INVESTIGATE` if pattern |
| Idempotency suppression | `PAYMENT_IDEMPOTENCY_SUPPRESS` (TBC) | `NORMAL` |
| Projection sync failure | `FEE_PROJECTION_SYNC_FAILED` (TBC) | `INVESTIGATE` if self-heal works; `FREEZE_REQUIRED` if it doesn't |
| Webhook signature failure | `WEBHOOK_SIGNATURE_INVALID` (TBC) | `INVESTIGATE` if isolated; `FREEZE_REQUIRED` if Razorpay legitimate |
| Orphan reconciliation report | `FEE_ORPHAN_DETECTED` (TBC) | `WATCH` (informational) |

### 3.4 Alert-worthy drift conditions

Conditions that should escalate to operator attention even without an explicit FREEZE_REQUIRED:

1. `feeDemands` total income for the day > Razorpay sandbox captured total (admin reports more income than gateway delivered)
2. `feeDefaulters.totalDues` aggregate drift > 0 sustained > 5min after last demand mutation
3. `feeOnlinePayments` count diverges from Razorpay dashboard captured count by > 1 sustained
4. Any single payment with `webhook_log` entries > 3 (excessive retry by Razorpay suggests handler error)
5. Sustained webhook signature-invalid emissions (could indicate misconfigured secret or attempted forgery)

---

## 4. Mobile/runtime interaction

### 4.1 Parent app refresh timing

**Question to answer during Tier 1:** does FeesScreen refresh via Firestore real-time listener or via explicit `loadFees()` poll?

Per [[razorpay_dashboard_next]]: `FeesViewModel` calls `loadFees()` after `verifyPayment` returns. So the parent-side read is **poll-after-action**, not real-time listener for the post-payment refresh.

**Expected visibility envelope:**
- Same-app same-session: instant (poll runs on success callback)
- App backgrounded during payment, foregrounded later: depends on whether `onResume` re-polls — to be measured
- Cross-device (parent paid on phone, also logged in on tablet): tablet sees update only on next manual refresh or app foreground

**Risk classification:**
- Same-session no refresh after success: `INVESTIGATE` (UX broken, not financial)
- Cross-device visibility delay > 5min: `WATCH`
- Cross-device visibility delay where second device shows OLDER state after refresh: `INVESTIGATE`

### 4.2 Offline retry behavior

**Scenario:** parent initiates payment while connected → goes offline mid-Razorpay-flow → comes back online.

**Open questions for Tier 2 observation:**
- Does Razorpay SDK queue the response and deliver on reconnect? (per SDK docs typically yes)
- Does PaymentBridge buffer the event if VM is not listening?
- Does `verifyPayment` retry on transient network failures?

**Expected envelope:**
- Razorpay sandbox typically completes payment server-side even if client loses connectivity; webhook is the safety net
- Worst-case: payment captured at gateway, client cannot reach admin verify, webhook completes the recovery

**Risk classification:**
- Offline → reconnect → state correct on next foreground: `NORMAL`
- Offline → reconnect → app stuck in "verifying" indefinitely: `INVESTIGATE`
- Offline → reconnect → state incorrect (says paid when not, or vice versa): `FREEZE_REQUIRED`

### 4.3 Stale cache visibility window

**Scenario:** Firestore offline cache may show old `feeDemands` / `feeDefaulters` data after a payment if the cache is not invalidated.

**Expected envelope:**
- Firestore SDK default behavior: cache is reconciled on next online read; offline reads from cache may be momentarily stale
- Parent app reads `feeDefaulters` via `FeeFirestoreRepository::observeDefaulterStatus` (per [[fees_canonical_architecture]]) — if observer, real-time; if get-once, cache-stale possible

**Risk classification:**
- Stale read after payment for < 5s: `NORMAL`
- Stale read sustained > 30s in foreground app: `INVESTIGATE`

### 4.4 Delayed reconciliation visibility

**Scenario:** if `Fee_firestore_sync::syncDefaulterStatus` is asynchronous (queued via worker), parent app may see `feeDemands` updated but `feeDefaulters` not yet refreshed.

**Expected envelope:** projection write should be inside the same synchronous request as the demand write (per [[fees_canonical_architecture]] write-trigger map). To be confirmed by source inspection during Tier 1 controlled window.

**Risk classification:**
- Demand updated, projection updated within same request: `NORMAL`
- Demand updated, projection deferred and arrives within 30s: `WATCH`
- Demand updated, projection deferred and never arrives: `FREEZE_REQUIRED`

### 4.5 Notification timing assumptions

**Open questions for Tier 2:**
- Does the admin or parent app send a payment-success push notification?
- If yes, does the notification fire before, during, or after the demand-write completes?
- What does the parent see if they tap the notification before the state has refreshed?

**Risk classification:**
- Notification fires after state-write completes: `NORMAL`
- Notification fires before state-write completes and tapping it shows stale state for < 5s: `WATCH`
- Notification fires but tapped state never updates: `INVESTIGATE`

---

## 5. Runtime-failure choreography

Inherits the freeze choreography established for fees changes ([[feedback_freeze_choreography]]) and the soak operating contract established for accounting ([[accounting_soak_contract]]).

### 5.1 Rollback expectations

**At Stage 1 entry:** rollback to pre-Stage-1 state is trivial because **no code changes have been made**. This plan is planning-only; the working tree is unchanged for the fees module.

**Once Stage 1 begins finding evidence-triggered fixes:** each fix follows the freeze → forensic → package → apply → 24h cool window → GO/NO-GO pattern.

**Rollback envelopes for in-flight payments under freeze:**
- Razorpay sandbox payments completed at gateway: cannot be "unwound" at Razorpay; reconciliation must occur via refund or manual adjustment
- Admin-side `feeDemands` mutations: rollback via journal reversal entry (do NOT delete docs; preserve audit trail)
- Projection drift: rebuild via `Fee_firestore_sync::syncDefaulterStatus` re-trigger or `scripts/backfill_defaulters.js` manual run

### 5.2 Freeze criteria (FREEZE_REQUIRED triggers — fees-specific extension to accounting soak contract)

Reusing the accounting soak vocabulary verbatim — same risk levels (`NORMAL`, `WATCH`, `INVESTIGATE`, `FREEZE_REQUIRED`).

**Fees-specific FREEZE_REQUIRED triggers (additive to accounting soak triggers):**

| Trigger | Severity rationale |
|---|---|
| Duplicate `feeOnlinePayments` doc with same paymentId | direct financial-integrity violation |
| `feeDemands.paid_amount` > sum of unique-paymentId amounts | double-credit |
| `feeDefaulters.totalDues` drift > 0 sustained > 5min | projection-correctness violation |
| Cross-student payment success (parent A pays for child B without authorization) | security + financial-integrity |
| Hostel/Transport categories included in `totalDues` aggregation | TC-3 partition violation |
| Webhook replay causing state mutation | idempotency violation |
| Demand updated but accounting journal missing after 5min | cross-module integration violation |
| Signature-invalid webhook accepted | gateway-integrity violation |
| Orphaned Razorpay-captured payment accepted as paid in admin without re-verification | reconciliation-integrity violation |
| Parent retry after silent success accepted as new payment | double-credit |
| Razorpay sandbox captured-total > admin reported income for the day | gateway-vs-admin divergence |

### 5.3 Forensic capture requirements

If FREEZE_REQUIRED is triggered, capture the following before any remediation attempt:

1. **Snapshot of affected Firestore docs** (export via Firebase console or scripted): `feeOnlineOrders`, `feeOnlinePayments`, `feeDemands` for affected students, `feeDefaulters` for affected students, relevant accounting collection docs
2. **Razorpay dashboard webhook delivery log** for affected payments (timestamps, retry counts, response codes)
3. **Admin server logs** for the request window (PHP error log + audit_log + sec_telem emissions)
4. **Parent app debug log** (`cache/debug.log` via `adb run-as` on debuggable builds — see BUG-038/039 fix for canonical pull instructions)
5. **Timestamps for every event** in the failure chain

### 5.4 Evidence preservation expectations

- **Do NOT delete `feeOnlinePayments` docs** even if duplicate — flag them for review
- **Do NOT manually edit `feeDemands.paid_amount`** to "correct" drift — issue a reversing accounting journal entry instead
- **Do NOT re-process orphaned Razorpay captures** until orphan-handling design is reviewed
- **Preserve `feeOnlineOrders` history** even for failed/abandoned orders — they are reconciliation witnesses

### 5.5 Safe retry boundaries

- **Razorpay webhook retries:** Razorpay will retry up to N times per their delivery policy; the handler must remain idempotent through all retries (§2.3)
- **Parent app verify retry:** parent app may retry verify; admin must remain idempotent (§2.3)
- **Admin manual re-process:** admin re-trigger (if any) must also be idempotent
- **Reconciliation re-run:** `backfill_defaulters.js` must be idempotent (re-run produces same final state)
- **`Fee_firestore_sync::syncDefaulterStatus` re-fire:** must be idempotent (same input → same projection state)

---

## 6. Validation sequencing

Three tiers, safest first. Each tier requires operator checkpoint before advancing.

### 6.1 Tier 1 — Safest-first (single-student, single-demand, sandbox happy path)

**Goal:** establish baseline telemetry envelope and confirm happy-path correctness end-to-end.

**Scenarios:**
- T1.1 — Single-student, single-demand, single-payment via parent app (§1.1)
- T1.2 — Same flow via admin "record offline payment" (if applicable) for control comparison
- T1.3 — Telemetry observation pass: catalog every event emitted during T1.1 (no code changes; just observation)
- T1.4 — Read-side timing measurement (parent app refresh envelope — §4.1)
- T1.5 — Projection consistency check immediately after T1.1 (§2.1 invariant validation, single-row)

**Expected duration:** 30-60 min per scenario; 1-2 days total including operator review window.

**Operator checkpoint before Tier 2:** *"Tier 1 complete. Baseline telemetry confirmed. Risk classification: NORMAL across all scenarios. Approved to proceed to Tier 2."*

### 6.2 Tier 2 — Moderate (duplicate handling, retry, webhook replay, failure recovery)

**Goal:** stress idempotency boundaries and observe failure-recovery paths.

**Scenarios:**
- T2.1 — Duplicate webhook delivery (§1.2)
- T2.2 — Webhook replay from previous day (§1.4)
- T2.3 — Payment-success vs ledger-write timing measurement (§1.5)
- T2.4 — Failed-payment Scenario A (gateway-side failure — §1.6)
- T2.5 — Failed-payment Scenario B (verify-side failure with webhook recovery — §1.6) — **HIGHEST PRIORITY**: this is the silent-failure recovery class
- T2.6 — Failed-payment Scenario C (webhook-only success — §1.6)
- T2.7 — Parent retry behavior (§1.7)
- T2.8 — Pending-write recovery (§2.2)
- T2.9 — Mobile offline retry (§4.2)

**Expected duration:** 2-4 hours per scenario depending on failure-injection complexity; 1 week total including evidence review.

**Operator checkpoint before Tier 3:** *"Tier 2 complete. Idempotency and recovery verified. Risk classifications recorded: [list]. Approved to proceed to Tier 3."*

### 6.3 Tier 3 — Highest-risk (concurrent, multi-student, cross-tenant)

**Goal:** validate behavior under realistic concurrency and confirm isolation guarantees.

**Scenarios:**
- T3.1 — Concurrent payments for the same demand row from two parent app instances (§1.3 retry-ordering)
- T3.2 — Multi-student concurrent payments (different demands, same school, same admin request window)
- T3.3 — Cross-student isolation hostile test (§2.6) — **OPERATOR MUST EXPLICITLY AUTHORIZE** before this scenario; involves deliberate guard probing
- T3.4 — Cross-tenant isolation hostile test (parent of school A attempts payment against school B) — same authorization requirement
- T3.5 — Reconciliation under load (multiple parents paying simultaneously, observe projection convergence under contention)
- T3.6 — Orphan detection rehearsal (§2.4) — manually create an orphan via webhook suppression and verify detection
- T3.7 — Sustained-load envelope (15-burst equivalent for fees, mirror the accounting load pattern from soak)

**Expected duration:** 1-2 days per scenario; 1-2 weeks total.

**Operator checkpoint before exiting Stage 1:** *"Tier 3 complete. No sustained FREEZE_REQUIRED signals. Fees module moves from `runtime-gated` to `runtime-soak-completed`. Subsequent posture: monitor; new fix work is evidence-triggered."*

### 6.4 Recommended cadence

- **Tier 1:** dense — multiple scenarios per day if conditions permit. Low risk; high learning value.
- **Tier 2:** measured — one scenario per day, with operator-reviewed evidence-merge window after each. Mirror the accounting soak rhythm.
- **Tier 3:** slow — one scenario per 1-3 days; explicit "go again tomorrow" gates between hostile scenarios.

**Per the accounting soak contract:** *"observe → verify → classify → do not mutate"* during any soak window. Even Tier 1 scenarios must NOT trigger autonomous fixes — every finding goes through the freeze → forensic → package → apply choreography.

### 6.5 Operator checkpoints summary

| Checkpoint | Trigger | Operator decision |
|---|---|---|
| Before T1.1 | Plan accepted | "Begin Tier 1" |
| Between Tier 1 scenarios | Each scenario complete with telemetry captured | "Continue Tier 1" or "Hold for review" |
| Before Tier 2 | T1.x all NORMAL | "Approved to proceed to Tier 2" |
| Between Tier 2 scenarios | Each scenario complete | "Continue Tier 2" or "Hold for review" |
| Before Tier 3 hostile tests (T3.3, T3.4) | explicit authorization required | "Authorized for hostile scenario T3.x" |
| Before Tier 3 | T2.x all classified | "Approved to proceed to Tier 3" |
| Exit Stage 1 | T3.x all classified, no sustained FREEZE | "Fees runtime-soak-completed" |

---

## 7. Operating contract (locked response mode for soak telemetry)

Reuses the accounting soak operating contract verbatim — same locked response format and risk vocabulary. Reproduced inline for fees runtime work:

### 7.1 Locked response format

For every telemetry observation submitted by operator during Stage 1, response is:

```
## Observed Signal
## Classification
## Risk Level
## Likely Cause
## Recommended Action
```

### 7.2 Risk vocabulary (no new levels permitted)

- `NORMAL` — expected behavior under load
- `WATCH` — informational; surface but don't escalate
- `INVESTIGATE` — non-rollback-sensitive anomaly worth diagnosing
- `FREEZE_REQUIRED` — rollback-sensitive financial-integrity violation; recommend immediate freeze

### 7.3 Authorization discipline (preserves explicit-approval gate from BUG-028)

- No code changes without explicit *"Authorized — begin scenario T<N.M>"* or *"Apply fix for finding F<N>"* message.
- *"Acknowledged"* or *"Continue"* responses during soak are NOT implicit authorization for code mutation.
- Scenario authorizations are scope-bounded — message specifies what IS and IS NOT permitted.
- Even additive-only repairs require explicit authorization.
- Design packages are review-ready in conversation but never lead to implementation without authorization.

### 7.4 Default standby posture

When operator's instruction is only *"observe"* / *"classify"* / *"verify"* / *"do not mutate"*, response is to confirm standby posture and wait. Do not generate analysis, planning, or proposals unsolicited.

### 7.5 Read-only operations always permitted

- Reading existing files (Glob, Grep, Read)
- Telemetry observation
- Razorpay dashboard inspection
- Firestore console read-only inspection
- Running `Fee_defaulter_check::isDefaulter()` as verification (read-only)
- Manual `scripts/backfill_defaulters.js` re-run AFTER operator authorization for the specific incident

---

## 8. Carry-forward and explicit out-of-scope

### 8.1 Items explicitly out of scope of this plan

- accounting module runtime validation (separate plan, separate authorization required)
- hr_payroll module runtime validation (separate plan, separate authorization required)
- staff_hardening patches 6–15 (HOLD-gated)
- BUG-037 / 040 / 041 / 042 fix execution (deferred — separate authorization required)
- Schema migration of `feeDemands` or `feeDefaulters`
- New telemetry-emission sites in fees code (any addition is evidence-triggered, not pre-emptive)
- Mobile pilot expansion to homework/messages
- Snapshot-commit posture change
- Lock-gate adjustments on any module
- `Fee_lifecycle::reassignFeesOnPromotion` line 278 placeholder fix (latent — flag for future surgical phase, do not opportunistically remediate per [[feedback_freeze_choreography]])
- TC-4 (canonicalize `Fee_dues_check::check`) — deferred follow-up from [[fees_canonical_architecture]]
- Scheduled `reconcile_fees.php` cron — Blaze-only, infrastructure decision pending

### 8.2 Items that will be carried forward if observed during Stage 1

- Any FREEZE_REQUIRED finding → goes through forensic → package → apply choreography in a separate authorized window
- Any WATCH/INVESTIGATE finding → catalogued in BUG_LEDGER under new BUG-NNN entries
- Any telemetry-gap finding (emission expected, none observed) → catalogued as observability backlog, NOT pre-emptively added
- Any orphan or reconciliation finding → catalogued for runtime-operational backlog

---

## 9. Initialization checklist (operator-side prerequisites before T1.1)

| # | Item | Verification method |
|---|---|---|
| 1 | Razorpay test-mode credentials populated in `feeSettings/{school}_{session}_gateway` | admin panel → Fees Management → Gateway Config; `provider='razorpay'`, `mode='test'`, valid `api_key` + `api_secret` |
| 2 | Webhook URL configured in Razorpay sandbox dashboard pointing to staging admin webhook endpoint | Razorpay dashboard → Webhooks |
| 3 | At least one staging student account exists with at least one open `feeDemands` row | Firestore console: `feeDemands` collection has a doc with `pending_amount > 0` |
| 4 | Corresponding `feeDefaulters` projection doc exists for the staging student | Firestore console: `feeDefaulters/{schoolId}_{session}_{studentId}` doc present |
| 5 | Parent app build connected to staging `BuildConfig.BASE_URL` | `app/build.gradle.kts:25` matches staging admin URL |
| 6 | Parent app test account with Firebase ID-token claims matching staging student | Firebase auth console: test user with `school_id` + `uid` claims set |
| 7 | Razorpay sandbox test-card details available to operator | Razorpay docs: test cards |
| 8 | Admin observability surface accessible (PHP error log + audit_log Firestore collection) | admin host shell access + Firestore console |
| 9 | Parent app debuggable build for `adb run-as cache/debug.log` pulls | build variant: debug |
| 10 | Snapshot of pre-Stage-1 state captured (current Firestore state of `feeDemands` + `feeDefaulters` + `feeOnlineOrders` + `feeOnlinePayments` for test student) | manual export OR documented current state for rollback witness |

**Operator confirms checklist complete via:** *"Stage 1 initialization checklist complete. Begin T1.1."*

---

## 10. Entry to runtime engineering phase — closing statement

This plan is the operational continuation of the v7 campaign report ([V7_CAMPAIGN_REPORT_SESSIONS_1_4.md](V7_CAMPAIGN_REPORT_SESSIONS_1_4.md)). It produces no code changes. It does not lift any gate other than fees runtime-validation engagement. It preserves all existing governance discipline.

Future fix work under this plan is **evidence-triggered** — telemetry observed during scenarios triggers a forensic window, which triggers a package window, which triggers explicit apply authorization. No fix proceeds on speculation.

The plan respects the operator's stated principle that *"empirical proof matters more than architectural prediction"* (per [[accounting_soak_contract]]).

This plan does NOT constitute a production-certification claim and never will. Soak completion produces operational confidence, not certification.

---

## 11. Halt posture

**Current state:** runtime-validation phase authorized for fees. Plan delivered. **Awaiting operator signal:** *"Stage 1 initialization checklist complete. Begin T1.1."*

No autonomous activity will occur until that signal is received. Per the locked operating contract, *"observe → verify → classify → do not mutate"* is the default.

*End of plan.*
