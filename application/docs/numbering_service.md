# Numbering Service

Platform allocator for sequential, human-readable business document IDs across the ZenX multi-tenant ERP. Built on the proven `Firestore_service::nextSchoolCounter` primitive (claim-doc CAS). Firestore-only. Admin backend is the sole allocation authority; Parent and Teacher apps observe canonical identifiers via Firestore and never mint them.

This document covers what is implemented today (Phase 1) and the standard pattern for future modules to adopt the service. Module-specific design and documentation belongs with each module's own work when that work happens.

---

## 1. Quick Start

The platform adoption pattern is four steps:

```
STEP 1   Register the kind in application/config/numbering.php
STEP 2   Call $id = $this->numbering->next('your_kind', [...])
STEP 3   Store the returned ID on the business document
STEP 4   NEVER generate business numbers locally
```

**Absolute rules:**

- `next()` is the only allocation API. `peek()` and `describe()` are diagnostic only — never use them to predict an ID.
- Mobile apps (Parent, Teacher) **never** allocate IDs. They observe canonical documents written by the Admin backend.
- LEGAL_GAPLESS kinds use retry-then-throw on exhaustion. **Never** catch the exception silently and substitute a fallback ID — the action must fail entirely to preserve audit integrity (CGST / Companies Act compliance).

---

## 2. Public API

```php
class Numbering_service
{
    public function init($fs, string $tenantId, string $session = ''): self;

    public function next(string $kind, array $opts = []): string;
    public function peek(string $kind, array $opts = []): int;
    public function describe(string $kind): array;
}
```

### `next($kind, $opts = []): string`

Allocate the next ID for `$kind`. Returns the formatted human-readable ID (e.g., `"NOT0042"`).

Recognized `$opts` (all optional):

| Key | Type | Purpose |
|---|---|---|
| `seedFloor` | int | Minimum starting value (migration use) |
| `maxAttempts` | int | CAS retry budget (default 8) |
| `period` | string | Explicit period override |
| `claimedBy` | string | Audit actor (auto-resolved from session if absent) |
| `claimedFor` | string | Audit context tag (e.g., `"COMM:SAVE_NOTICE"`) |
| `idempotencyKey` | string | Recorded on claim doc for audit (dedup cache deferred) |

Throws `\RuntimeException` on CAS retry exhaustion (gapless contract). Throws `\LogicException` on unknown kind, disabled service, or disabled kind.

### `peek($kind, $opts = []): int`

Operational/diagnostic. Returns current pointer value without allocating. Returns `0` if no allocation has occurred. **Never use in business logic** — race-vulnerable, gives no reservation.

### `describe($kind): array`

Pure registry lookup. Safe to call before `init()`. Returns `{kind, prefix, padWidth, gaplessClass, periodScope, seedSource, flagState}`.

### Loading

```php
$this->load->library('numbering_service', null, 'numbering');
$this->numbering->init($this->fs, $this->school_id, $this->session_year);
```

Phase 1 does not modify `MY_Controller` — the service is loaded on demand by consumers (Phase 2+ migration adds it to MY_Controller's bootstrap).

---

## 3. Generic Adoption Pattern

Adoption is the same for every module — Communication, Fees, SIS, Payroll, Library, anything new.

1. **Register the kind** in `application/config/numbering.php` with `prefix`, `padWidth`, `gaplessClass`, `periodScope`, `seedSource`. Add to `_kindFlags` as `'disabled'`. Land via PR review (with Compliance reviewer if `LEGAL_GAPLESS`).
2. **Replace the legacy counter code** in your module's controller with `$this->numbering->next('your_kind', [...])`. Keep the legacy path reachable behind the `_kindFlags` check for rollback.
3. **Run the seed-from-legacy script** (operator-driven) so the pointer doc reflects the max existing ID. First post-cutover allocation continues from there — historical IDs are untouched.
4. **Flip `_kindFlags` to `'enabled'`**. Monitor; soak (24h OPERATIONAL, 48h AUDIT_TRAIL, 72h LEGAL_GAPLESS).
5. **Retire legacy code** in a follow-up PR. Verifier CH-NUM-2 enforces no remaining direct counter touches.

The service code does not change for any of this — the registry grows, the service stays generic.

---

## 4. Worked Examples

### Example A — Communication (Phase 2 actual)

Registry entry (Phase 1 shipped):

```php
'notice' => [
    'prefix'       => 'NOT',
    'padWidth'     => 4,
    'gaplessClass' => 'OPERATIONAL',
    'periodScope'  => 'none',
    'seedSource'   => ['collection' => 'notices', 'pattern' => '/^NOT(\d+)$/'],
],
```

Caller (Phase 2 cutover — `Communication.php`):

```php
$id = $this->numbering->next('notice', [
    'claimedBy'  => $this->session->userdata('admin_id'),
    'claimedFor' => 'COMM:SAVE_NOTICE',
]);
$this->fs->set('notices', $id, $noticePayload);
```

Produces `NOT0001`, `NOT0002`, … per school, monotonic forever. Operational class — gaps acceptable under contention exhaustion (extremely rare at ZenX scale).

### Example B — Fee Receipts (Indian GST compliance pattern)

Registry entry (future Phase — when Fee_firestore_txn unifies onto the service):

```php
'receipt' => [
    'prefix'       => 'R',
    'padWidth'     => 6,
    'gaplessClass' => 'LEGAL_GAPLESS',     // CGST Rule 46 / 49
    'periodScope'  => 'session',           // April–March FY reset
    'seedSource'   => ['collection' => 'feeReceipts', 'pattern' => '/^R(\d+)$/'],
],
```

Caller pattern:

```php
try {
    $receiptNo = $this->numbering->next('receipt', [
        'claimedBy'  => $this->session->userdata('admin_id'),
        'claimedFor' => 'FEES:COLLECT_PAYMENT',
    ]);
    $this->fs->set('feeReceipts', $receiptNo, $receiptPayload);
} catch (\RuntimeException $e) {
    // LEGAL_GAPLESS: do NOT substitute a fallback ID. The payment must
    // fail entirely so audit integrity (CGST replay sequence) is preserved.
    show_error('Receipt allocation unavailable. Please retry.');
}
```

Produces `R000001`, `R000002`, … per school per fiscal year. Counter resets each April 1. Gap-free by retry-then-throw contract.

---

## 5. Future Modules

The following modules will adopt the Numbering Service over future phases. Each will be designed and documented when its migration is actually planned, following the four-step adoption pattern above.

- **Accounting vouchers** (LEGAL_GAPLESS; Companies Act audit-trail aligned)
- **HR / ATS** (operational identifiers)
- **Library, Hostel, Transport, Inventory, Assets, PTM** (mix of operational and LEGAL_GAPLESS)
- **Certificates** (also retires the last RTDB counter dependency)
- **Admin / Superadmin / LMS** (closes unsafe count-based pattern)
- **Future modules** — Payroll Stage 3+, Invoice / Billing, anything new

Detailed registry entries, format choices, and cutover plans for each module are authored at that module's design time — not in advance.

---

## 6. Operator Reference

### 6.1 Permission Model

| Component | Allowed to allocate? |
|---|---|
| Admin backend controllers, libraries, background workers, cron jobs | ✅ Yes |
| Operator-run migration / recovery scripts | ✅ Yes |
| Parent App, Teacher App (Kotlin) | ❌ Never |
| Public admission form, client-side JavaScript | ❌ Never |
| Third-party integrations, webhook receivers | ❌ Never |
| Cloud Functions (none today; if added, must HTTP-callback to admin) | ❌ Never by default |

Mobile apps observe canonical documents written by the Admin backend. User-initiated requests on mobile go to `pending*` collections; admin reviews and allocates the canonical ID at approval time.

### 6.2 Verifier

```
php index.php numbering_verifier verify
```

Required env: `SCHOOL_ID`, `SESSION_YEAR`. Exit codes: `0` PASS/INFO · `1` env missing · `2` any FAIL.

Six assertions: CH-NUM-1 inventory · CH-NUM-2 service-routing · CH-NUM-3 legal-gapless integrity · CH-NUM-4 seed-pattern validity · CH-NUM-5 period-scope schema · CH-NUM-6 pad-width utilisation.

In Phase 1 (no kinds enabled), CH-NUM-2 and CH-NUM-3 report `INFO  no enabled kinds`. CH-NUM-4 and CH-NUM-5 validate registry data. CH-NUM-6 reports `0%` utilisation for every kind (no allocations yet).

### 6.3 Observability

Every `next()` call emits one structured log line:

```
[INFO]  numbering_service kind=notice tenant=SCH_X value=42 id=NOT0042 duration_ms=2734 outcome=success
[WARN]  numbering_service kind=receipt tenant=SCH_X value=8042 padWidth=6 util_pct=80.4
[ERROR] numbering_service kind=voucher_JV tenant=SCH_X value=null duration_ms=8312 outcome=exhausted_throw
```

Targets: P50 ≤1500ms · P95 ≤3500ms · P99 ≤6000ms · LEGAL_GAPLESS success ≥99.99% · pad-width utilisation ≤80%.

### 6.4 Disaster Recovery

**Pointer corruption / drift** — the claim-doc layer guarantees correctness regardless of pointer state. A drifted pointer just costs extra probes. Self-heals on next successful allocation. Operator manual rewrite is <10 minutes per tenant per kind if drift exceeds `maxAttempts`.

**Claim doc deletion (catastrophic)** — allows ID reuse. Procedure:

1. Set `_kindFlags[kind] = 'disabled'` to halt allocations.
2. Run replay-from-business-collection script: scan canonical collection (e.g., `notices`), extract IDs via registry pattern, write recovery claim docs with `claimedBy: "recovery_script"` and `recovered: true`.
3. Reset pointer to `max(N)`.
4. Verify CH-NUM-4 passes; re-enable.

RTO <2 hours per tenant per kind. Per-tenant scoping means one school's incident doesn't disrupt others.

**Backup retention** — Firestore native backup must include `systemCounters`. LEGAL_GAPLESS claim docs retained ≥8 years (Indian tax audit window).

---

## 7. Notes

- Indian financial year (April–March) is the platform default for `periodScope: session`. Service-internal helper `_indianFinancialYear()` computes the FY string (e.g., `"2026_27"`).
- Future international support arrives as an additive extension point (jurisdiction profiles) — no current product requirement.
- Phase 1 ships the service, registry, and verifier. No production callers are modified. Phase 2 Communication migration is the first real consumer.
