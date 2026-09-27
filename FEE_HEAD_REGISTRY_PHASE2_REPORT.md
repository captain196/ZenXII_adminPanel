# FEE HEAD REGISTRY — chargeInstance + ID-card identity + M4

**All authorised work complete. Tests back to baseline. Nothing committed.**
2026-08-17

---

## A. Files changed

**Production (4):**

| File | Change |
| --- | --- |
| `application/libraries/Fee_firestore_txn.php` | `chargeInstance` added to the demand whitelist, conditionally emitted |
| `application/libraries/Fee_lifecycle.php` | `_demandId()` optional `$instanceKey`; `createModuleFee()` reads + forwards + persists it |
| `application/libraries/Id_card_fee_policy.php` | `feeHeadFor()` now returns the stable head; new `instanceKey()`; payload carries `chargeInstance` |
| `application/libraries/Operations_accounting.php` | **comment only** — its ID-card mapping rationale described the retired versioned head |

**Tests (4):** `FeeHeadChargeInstanceTest.php` (new, 16) · `FeeHeadResolverTest.php` (new, 23, prior pass) ·
`Phase19ICredentialAuthorityAndFeeTest.php`, `Phase19KIdCardRaceAndMintTest.php`,
`Phase19NIdCardReplacementChargeTest.php` (updated to the new contract).

**Scripts (2 new):** `m4_attach_fee_head_ref.php`, plus the M3 pair from the prior pass.

**Untouched:** `Fees.php`, `Fee_generation_service.php`, `Hostel.php`, `Sis.php`, all readers,
`firestore.rules`, `firestore.indexes.json`.

## B. Schema change to the writer

```php
if (array_key_exists('chargeInstance', $data)) {
    $instance = trim((string) $pick($data, ['chargeInstance'], ''));
    if ($instance !== '') $doc['chargeInstance'] = $instance;
}
```

Conditional, exactly like the payment-state fields — **CASE A** (no `chargeInstance` supplied) emits no
field at all, so every existing caller writes a byte-identical document and no synthetic value is ever
invented. **CASE B/C** persist `V2` / `V3` verbatim.

It sits in `_buildDemandDoc()`, which is shared by **both** the single-write and the batched
`commitDemandBatch()` paths — so both persist it, and neither could have without this edit.

## C. ID-card demand-id format

| | Before | After |
| --- | --- | --- |
| Head name | `ID Card Replacement (Card v2)` | **`ID Card Replacement`** (stable) |
| v2 demand id | `DEM_STU0012_202604_ID_CARD_REPLACEMENT_CARD_V2_IDCARD` | `DEM_STU0012_202604_ID_CARD_REPLACEMENT_IDCARD_V2` |
| v3 demand id | `…_CARD_V3_IDCARD` | `…_IDCARD_V3` |
| Instance | encoded in a display string | `chargeInstance: "V{cardVersion}"`, stored on the demand |

Zero live ID-card demands, so no migration and no production impact.

## D. T39–T44 — all pass

| Test | Result |
| --- | --- |
| **T39** two versions, one period → two distinct demands | PASS (+ T39b: the head is identical, only the instance separates them) |
| **T40** retry → one identical id | PASS (+ T40b: a rewrite without payment fields cannot clobber paid state — FIN-1/BUG-075) |
| **T41** Transport id byte-identical | PASS — `DEM_STU0012_202604_TRANSPORT_FEE_TRANSPORT` |
| **T42** Hostel id byte-identical | PASS — `DEM_STU0012_202604_HOSTEL_FEE_HOSTEL` |
| **T43** stored instance reproduces the id | PASS (+ T43b: no version left in the name to parse back) |
| **T44** renaming the head cannot move an existing demand | PASS |

Plus: an absent/degenerate instance appends nothing (no trailing separator), and ordinary chart-fee
ids are unchanged in both the `feeHeadId` and name-fallback forms.

## E. Shared-writer persistence

Three tests run the **real** `_buildDemandDoc()`: `chargeInstance` supplied → persisted; absent → key
absent; empty or whitespace → **not** stored as an empty string. This is the guard against the exact
silent-field-loss defect that caused the §5 stop.

## F. Transport / Hostel regression

Byte-identical, asserted against hardcoded golden strings (T41/T42). Nothing in either module path was
edited — they supply no instance, so they take the unchanged branch.

## G. `feeHeadRef` never enters `demandId`

Enforced by a mechanical regression test, not a convention: the test strips comments from
`Fee_lifecycle.php` via `token_get_all()`, extracts **every** `_demandId()` call site, and asserts none
mentions `feeHeadRef` — then asserts `createModuleFee` still passes `''` as the head-id argument. A
second test asserts the resolver exposes no method that could supply demand-identity material.

## H. No existing demand modified

`feeDemands` **1,242** platform-wide (UAT 26, other tenant 1,118) — unchanged. No demand id, amount,
balance, payment or category was written. **No legacy `category=''` backfill.**

## I. M3 registry integrity

Re-verified after all edits: **24 documents — 12 entries + 12 claims**, mapping intact, no session
field, one identity per school+name, no meals head. **Not reminted, not duplicated.**

## J. M4 — executed, additive, verified

Pre-write: registry intact (12+12), **7 pricing charts / 28 rows** matching M1 exactly, every row
resolving to a registry entry (an unmatched row would have halted). All 28 rows listed before writing.

**7 chart documents patched, 28 rows given `feeHeadRef`.** `_titles` docs untouched.

**Proved additive** by diffing a before/after snapshot of all 9 `feeStructures` documents:

```
ADDITIVE-ONLY VERIFIED: every pre-existing field byte-identical; only feeHeadRef added.
```

Every row's `name`, `amount`, `frequency`, `billingType` and legacy `feeHeadId` survived unchanged; no
document-level field changed; no row added or removed. Transport resolves to **one** identity across
all six SCH_218AAF5C23 charts, confirming the collapse works.

## K. Rules / index state

**No rules or index change made by me.** But the working-tree digest has moved again — **`f3ed755b`**,
not the `5b0f86fc` you quoted (the seventh move by the concurrent Staff-Lifecycle/HRMS session). I
touched neither file; this pass edited only `application/libraries/`, `tests/` and `scripts/`.
Treat `f3ed755b` as the current observation and re-check before any rules work, per the reconcile-first
procedure.

`feeHeadRegistry` still has **no rules** — the mint used the admin SDK, which bypasses them, so client
access is denied-by-default. Nothing reads the collection yet.

## L. Test count vs baseline

| | Tests | Failures |
| --- | --- | --- |
| Baseline | 1,849 | **10** |
| After | **1,890** | **10** |

**+41 tests, zero net new failures.**

**Mid-pass I did introduce 4 new failures and fixed them properly rather than moving on.**
`Phase19KIdCardRaceAndMintTest` and `Phase19NIdCardReplacementChargeTest` — two ID-card files I had not
found when I updated `Phase19I` — asserted uniqueness **on the fee head name**, which is precisely the
retired mechanism. I checked each was testing the retired *mechanism* and not a real regression: the
requirement they protect ("no replacement may reuse an earlier demand", "the fourth replacement is not
silently free") is now carried by the charge instance and is proven by T39. I updated them to assert on
the instance, kept the original intent and assertions' strength, and documented in each file why the
mechanism changed. I did not weaken or delete a single assertion.

## M. Unexpected findings

1. **Two additional ID-card test files** encoded the retired head-per-version design (§L). Worth noting
   that my earlier grep for `feeHeadFor|Card v` found `Phase19I` but these two reached the behaviour
   through `demandPayloadFor()`, so a narrower search missed them.
2. **`Operations_accounting` survives by luck of substring matching.** Its 4140 mapping keys on
   `'id card'`, which is a substring of both the old versioned names and the new stable one — so the
   accounting classification is unaffected. Its explanatory comment claimed the version was part of the
   head "so that each replacement gets its own demand id", which is now false; I corrected the comment.
3. **Rules digest drift (§K)** — seventh move, not mine.
4. `_titles` docs still exist with no `feeHeadRef`; correct for now, retired at M7.

---

## State

Git: **no commit, push, deploy, merge or rebase.** HEAD `758c924`, 0 new commits.

`USE_FEE_HEAD_REGISTRY` **not created** — the resolver is still unwired from every billing path, so
`chargeInstance` and `feeHeadRef` are inert data. `USE_ENTITLEMENT_BILLING = false` ·
`REAL_ADMISSION_READY = FALSE` · no RTDB · no scholarship, B3, N+1, SRNO-F2/F3 or backfill work · SRNO
untouched and still school-scoped, session-independent.

**Rollback:** revert the 4 production files (all changes are additive and flagless); delete the 24
registry documents; remove `feeHeadRef` from the 28 chart rows. No financial record was written at any
point, so there is no financial state to restore.

## Exact next authorisation required

The **rules/index step** — a `match /feeHeadRegistry/{doc}` block with same-school guards plus a
`schoolId + status` index, authored under the reconcile-first procedure against the current
`f3ed755b`. After that, M5 behind `USE_FEE_HEAD_REGISTRY` defaulting to false.

Stopping here as instructed. No scholarship, B3, N+1 or SRNO work started.
