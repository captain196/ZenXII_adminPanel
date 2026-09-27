# M5 PREREQUISITE HARDENING — IMPLEMENTATION + VERIFICATION

**Steps 1–3 complete. M5 itself NOT started.** 2026-08-17

---

## 1. EXECUTIVE VERDICT — **PASS**

All three prerequisites are implemented, tested and independently verified. **No live data was
changed, no demand was touched, no rules or indexes were modified, and nothing was committed.**

## 2. Read-only reconciliation (before any change)

**[F]** HEAD `758c924` on `integration/phase2`, **0 new commits**. Rules digest **`1090ecbf`**,
indexes **`11ad46ea`** — both matching the M5 gate, so no concurrent drift since that audit.
**[F]** No new production reader/writer of `feeHeadRef` or `categorySource` had appeared — the only
matches were the two warning comments I had previously added. **[F]** Pre-change snapshot: 9
`feeStructures` docs, **28 pricing rows, 28 carrying `feeHeadRef`**.

**State matched the gate exactly**, so I proceeded.

## 3. STEP 1 — `feeHeadRef` durability

**File:** `application/libraries/Fee_firestore_sync.php`, `syncFeeStructure()`.

The previous snapshot is now mined for **two** bindings, not one — `$existingIds[$name]` (unchanged,
pre-existing) and the new `$existingRefs[$name]`. Both row builders emit `feeHeadRef` when a binding
resolves. The full-overwrite `firestoreSet()` is unchanged; the field simply survives it now.

## 4. STEP 2 — maintenance logic and identity guarantees

**I did not implement "if the name changed, mint a new ref."** That is the defect, not the fix.
Resolution is a two-source lookup that **never mints**:

```php
$resolveRef = static function (string $name) use ($existingRefs, $registryRefs): ?string {
    if (isset($existingRefs[$name])) return $existingRefs[$name];   // 1. the binding this row already had
    $nn = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    return $registryRefs[$nn] ?? null;                              // 2. the school-scoped registry
};                                                                  // 3. otherwise: NOTHING
```

`$registryRefs` is built from `feeHeadRegistry` filtered on `schoolId`, **excluding name-claim
documents and non-`active` entries**.

**Guarantees:**

| Property | How it holds |
| --- | --- |
| Survives amount / frequency / re-save / rollover | the binding is read from the prior row, independent of pricing |
| **Name is not the identity authority** | the name only *re-links* a row to a binding it already had; it can never *create* one |
| **Never mints** | an unresolved row gets **no field** — fail-closed |
| No session component | the registry is school-scoped; `docId()` takes exactly two parameters |
| Archived heads excluded | `status !== 'active'` is skipped, so archived heads are never bound to new pricing |
| Registry unavailable | the `catch` leaves `$registryRefs` empty → no ref, never a guess |

**The one subcase I deliberately did not solve, as instructed:** a **chart-only rename** (or
delete-and-re-add) has no stable join key, so the row resolves to nothing and `feeHeadRef` is omitted.
I did not invent a migration. **Note the useful asymmetry this produces:** a *registry-mediated*
rename — where the head is renamed in the registry first — **does** resolve, because the incoming chart
name matches the registry even though it no longer matches the old snapshot. So the safe rename path
works and the unsafe one fails closed.

Seeded heads, custom heads, Transport, Hostel and ID-card all use this identical school-scoped
mechanism — nothing about them is special-cased.

## 5. STEP 3 — `categorySource` writer behaviour

**File:** `application/libraries/Fee_firestore_txn.php`, `_buildDemandDoc()` — the builder **shared by
both the single-write and batched paths**, so both persist it (asserted mechanically).

| Case | Behaviour |
| --- | --- |
| **A** absent | **no field created** — every existing caller writes a byte-identical document |
| **B** `'registry'` | persisted verbatim |
| **C** empty / whitespace | **not persisted** |

No backfill, no rewrite of existing `category` values, no normalisation, and **no `''` → `academic`
conversion**.

## 6. Test results

| | Tests | Failures |
| --- | --- | --- |
| Baseline (start of pass) | 1,890 | **10** |
| Final | **1,954** | **10** |

**New tests: 23** (`tests/Unit/FeeHeadRefDurabilityTest.php`) — **all pass. Zero new failures.**

**Observation worth flagging:** the total rose by 64 while I added 23. The other ~41 are not mine —
consistent with the concurrent Staff-Lifecycle/HRMS work active in this repo. The metric that matters
is unchanged: **failures 10 → 10.**

Coverage: A1–A7b durability (save, amount, frequency, re-save, no-session, registry resolution,
case/whitespace) · archived-entry and name-claim exclusion · **fail-closed on chart-only rename, on a
brand-new head, and on registry failure** · no field added or dropped beyond `feeHeadRef` ·
B8 `feeHeadRef` never in `demandId` (comment-stripped source scan of every call site **plus** live
byte-identical Transport/Hostel id assertions) · B13 a pricing save never writes the registry ·
C14–C20 the three writer cases, payment fields still conditional, purely additive · D legacy `''`
never promoted, and `Academic`/`Transport`/`General` pass through unchanged.

Two of my own test bugs, found and fixed rather than worked around: I omitted `ready` on the double,
and fed the wrong chart shape (monthly heads are read from `$feeChart['April']`, not a flat map). A
third — using `+` on arrays, which does **not** override an existing key — was masking the legacy
pass-through assertion.

## 7. Live-data impact — **none**

Independent verification, **separate process and script**, diffing a pre-change snapshot:

```
registry entries 12 · claims 12 · identities unique 12
feeStructures BYTE-IDENTICAL (9 docs) · 28 rows · 28 still carry feeHeadRef
feeDemands 1242 · gained categorySource: 0 · gained feeHeadRef: 0
category distribution unchanged {(empty):1190, Academic:24, Transport:24, General:4}
net=502048.00  paid=0.00  balance=501950.00
students 150 · srNoIndex 0 · feeReceipts 0 · scholarshipAwards 0 · studentEntitlements 0
ALL CHECKS PASS — no live data changed.
```

**No financial record was read-modified-written.** The code changes alter only what *future* writes
contain; no write was executed against live data in this pass.

## 8. Identity proof

**School-scoped:** the registry query filters on `schoolId` and doc ids are `{schoolId}_{feeHeadId}`.
**No session component:** `Fee_head_resolver::docId()` takes exactly two parameters (reflection-asserted),
and the emitted ref is asserted not to contain the session or `2026`.
**`canonicalFeeHeadId` untouched:** not read or written by either change; its 1,092 values are unchanged.
**`feeHeadRef` never in `demandId`:** every `_demandId()` call site scanned with comments stripped, plus
live equality against `DEM_STU0012_202604_TRANSPORT_FEE_TRANSPORT` and `…_HOSTEL_FEE_HOSTEL`.

## 9. Cross-system

**No Admin, Parent or Teacher change made or required.** Neither app references `feeHeadRef` or
`categorySource`; both fields are additive and ignored by Firestore `toObject()`. No Kotlin touched.

## 10. Rules / indexes

**Unchanged and untouched.** Current digests: rules **`1090ecbf`**, indexes **`11ad46ea`** — identical
before and after. Neither change requires a rules or index modification: `feeHeadRegistry` is read
server-side via the admin SDK, which bypasses rules.

## 11. Remaining blockers

**For these prerequisites: none.** The three M5 gate blockers B1, B2 and B3 are closed.

Still open, and correctly outside this pass: chart-only rename remains gated (fail-closed by design);
`Fee_head_resolver` is still unwired; `Fees::_resolveFeeCategory()` still guesses from names; the 52
out-of-contract legacy category values still need a policy decision; the `academic` eligibility-bucket
question is unresolved.

## 12. Exact next authorisation

**READY FOR M5 PROPER.**

M5 proper — **not started, and not to be started in this pass** — is: wire `Fee_head_resolver` into the
canonical spec behind `USE_FEE_HEAD_REGISTRY` (default **false**), stamp `category` +
`categorySource: 'registry'` on new demands only, retire `_resolveFeeCategory()` after soak, and add
the `feeHeadRegistry` rules and `schoolId + status` index under the reconcile-first procedure from the
**current** digest.

---

**Files changed this pass (2 production, 1 test):**
`application/libraries/Fee_firestore_sync.php` · `application/libraries/Fee_firestore_txn.php` ·
`tests/Unit/FeeHeadRefDurabilityTest.php` (new).

No flag created · no migration · no backfill · no registry write · no demand write · no rules/index
change · no SRNO change · no Billing V2 field (`chargeType`, installment plans) added · no RTDB ·
no commit, push, deploy, merge or rebase. HEAD `758c924`, 0 new commits.
`USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE`.
