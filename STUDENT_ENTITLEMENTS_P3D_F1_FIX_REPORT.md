# P3D-F1 — RECALC ENGINE PARAMETER FIX

**STATUS: COMPLETE** (narrow fix only, as authorised)
**One new conflict surfaced by Step 4 — reported, not acted on (§8)**
**`USE_ENTITLEMENT_BILLING` = FALSE · `REAL_ADMISSION_READY` = FALSE**
**No live data · no rules/indexes · no RTDB · no commit, push or deploy**
**Date:** 2026-08-17 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Files changed

| File | Change |
| --- | --- |
| `application/controllers/Fees.php` | `_recalculateStudentUnpaidDemands()` — preamble replaced (P3D-F1) |
| `tests/Unit/Phase3dF1RecalcParameterFixTest.php` | **NEW** — 12 regression tests |
| `tests/Unit/Phase3dLegacyEngineGoldenMasterTest.php` | 2 tests retired to the corrected contract (§5) |

Nothing else. `Fee_lifecycle.php cd142c10`, `Sis.php 4c7e088a`, `capacity_cas.php f113cd9d`,
`firestore.indexes.json c473c914` — all unchanged.

---

## 2. Exact code-path change

**Removed** (31 lines) — the helper re-deriving all three of its own parameters from the request:

```php
$this->_require_post();
$this->_require_role(self::MANAGE_ROLES, 'recalculate_demands', 'Fees', 'edit');
$studentId = trim($this->input->post('student_id') ?? '');
if ($studentId === '') return $this->json_error('Student ID is required.');
$studentId = $this->safe_path_segment($studentId, 'student_id');
… getStudent() → $class/$section → json_error on each failure …
$feeChart    = $this->_getClassFeeChart($class, $section);
$discountMap = $this->_getStudentDiscounts($studentId, $class, $section);
```

**Added** — use what the caller passed, fail soft, never terminate:

```php
if ($studentId === '' || $feeChart === []) {
    return ['updated' => 0, 'preserved' => 0, 'attempted' => 0];
}
$this->load->library('Fee_firestore_txn', null, 'fsTxn');
$this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
$existing = $this->fsTxn->demandsForStudent($studentId);
```

**The pricing loop below it is untouched, byte for byte.** This fix was about *which student* and
*which chart* — never about money. Pinned by `test_F1_11_pricing_arithmetic_unchanged`.

---

## 3. Why the defect existed

The helper was almost certainly extracted from `recalculate_demands()` and kept its parent's request
preamble. For that one caller it stayed *coincidentally* correct — `recalculate_demands()` POSTs the
same student it passes — so the parameters and the re-read agreed and nothing looked wrong.

The bug only became reachable when a **second** caller appeared: `save_updated_fees()` loops a roster
and passes a different `$sid` on every iteration. The POST of a class-fee-chart save cannot name a
student, so the re-read produced `''`, and `json_error()` ends in `exit` (`MY_Controller:1546`).

The result: **the class fee-chart save died on the first student in the roster**, every later student
was silently never recalculated, and the operator saw *"Student ID is required."* on a screen with no
student on it. The caller's `try` at `:1520` could not intervene — `exit` is not an exception — so the
fail-soft intent documented directly above it (*"a propagation hiccup must never fail the request"*)
never actually held.

---

## 4. How the fix preserves intended behaviour

| Caller | Before | After |
| --- | --- | --- |
| `recalculate_demands()` `:7042` | resolved `$studentId`/`$feeChart`/`$discountMap`, passed them, helper recomputed **identical** values | receives and uses the same three values — **behaviourally unchanged**, minus two duplicate Firestore reads |
| `save_updated_fees()` `:1529` | **exited** on the first roster student | processes the whole roster, using the **freshly saved** chart it passes |
| `recalc_unpaid_discounts()` | never a caller | untouched |

Authorisation is unchanged: both public callers already gate on `_require_post()` + `_require_role()`
before the loop. A private helper invoked per-student must not re-check a request it cannot be
specific about.

`test_F1_09` states the equivalence argument as an assertion: `recalculate_demands()` still performs
every one of the seven steps the helper used to duplicate, so the values reaching the loop are
identical.

---

## 5. Tests

| | |
| --- | --- |
| **Before the fix** | 12 new tests, **6 failing** — F1-01…F1-06, pinning the defect |
| **After the fix** | **OK — 12 tests, 41 assertions** |
| Golden master | **OK — 14 tests**, 2 retired to the corrected contract |
| Both suites together | **OK — 26 tests, 93 assertions** |
| Full Unit suite | **1795 tests, 5667 assertions, 10 failures** |
| Baseline / new failures | **10 / 0** |

1783 → 1795 is exactly the 12 tests added.

**Two golden-master tests were retired, not deleted**, and the reason is recorded in each:
`test_P3D_F1_recalc_engine_overwrites_its_studentId_parameter` and
`..._empty_student_id_exits_the_whole_request` asserted the defect you authorised me to remove. Per the
brief — *"if an old test encodes deliberately retired behaviour, document the reason and update it to
the new approved contract"* — they now assert the corrected contract and are prefixed `RETIRED`. A
third test's *message* was corrected for the same reason; its assertions were already true either way.

---

## 6. Safety

| | |
| --- | --- |
| Live data touched | **No** — read-only checks only; `studentDiscounts` still 1 doc, untouched |
| Firestore rules / indexes touched | **No** (see §7) |
| RTDB | **No** — none appears anywhere in this work |
| Flags changed | **None.** `USE_ENTITLEMENT_BILLING = false` |
| Commit / push / deploy | **NO / NO / NO** |
| B3 routing | **Not started** — this authorisation was not used for it |

Live collections re-verified: `studentEntitlements` 0 · `studentConcessions` 0 · `scholarshipAwards` 0 ·
`studentDiscounts` 1.

---

## 7. Step 3 — working-tree state re-established

`firestore.rules` has moved **again**, and again not by me: `3d835a56` → **`ae0da664`**. I have not
touched that file at any point in this programme. Per your instruction the concurrent
Staff-Lifecycle/HRMS work is left alone; **treat `ae0da664` as the current baseline.**

Current digests: `Fees.php 07e342c4` (this fix) · `Fee_generation_service.php cc729e6f` (the Phase 3B
accessor boundary) · `Fee_lifecycle.php cd142c10` · `Sis.php 4c7e088a` · `firestore.indexes.json
c473c914` · `capacity_cas.php f113cd9d`. HEAD unchanged at `758c924`.

---

## 8. Step 4 — your scholarship interpretation vs the implementation: **THEY CONFLICT**

You directed: *"A scholarship award is an annual award amount… The annual ₹12,000 must be allocated
across the applicable fee heads/periods… It must NOT be applied as the full award amount independently
to every month."*

**The current implementation does exactly what you just prohibited.** Verified:

```php
$scholarshipTotal = floatval($discountMap['_scholarship'] ?? 0);   // full award   :4826
…
$proportion      = $amount / $monthTotal;    // share WITHIN this month             :4937
$discountAmount += round($scholarshipTotal * $proportion, 2);                      // :4938
```

`$monthTotal` is the sum of **this month's** heads, so the proportion only spreads the award *within* a
month — it never divides it *across* months. Confirmed by search: **no `/12`, no month-count division,
no `annual`/`perMonth` handling anywhere in the scholarship path**, and the award record carries no
`frequency`, `period` or `validUntil` to drive one.

So a ₹12,000 annual award currently deducts ₹12,000 in April, ₹12,000 in May, and so on.

**This creates a direct conflict between two of your requirements, which I am not going to resolve
unilaterally:**

- **Flag-OFF equivalence** requires the routed endpoints to reproduce current behaviour exactly — i.e.
  to preserve the 12× over-discount.
- **Your scholarship decision** declares that behaviour wrong.

They cannot both hold. This needs your call before routing:

| Option | Consequence |
| --- | --- |
| **1. Fix the apportionment first**, as its own authorised change, then capture a NEW golden master and route against that | Cleanest. Flag-OFF equivalence then means "equivalent to correct behaviour". Requires an explicit apportionment rule (across which periods? what if generation is partial or mid-session?) |
| **2. Route first, preserving current behaviour**, fix apportionment after | Keeps the two changes separable, but ships a routing whose "equivalence" is equivalence to a known-wrong amount |
| **3. Treat the current behaviour as correct** (i.e. award means "per month") | Contradicts your stated decision; I would need you to say so explicitly |

**No live award records exist (0 platform-wide), so nothing is mispriced today** — this is a
before-we-turn-anything-on problem, not an incident.

---

## 9. P3D-F2 status

**Still registered, still unfixed, and untouched by this fix** — enforced by
`test_F1_12_P3D_F2_remains_open_and_untouched`, which fails if `_scholarship` ever appears in the
recalc engine.

Per your instruction I have not fixed it. My assessment of whether it is an unavoidable routing
prerequisite: **it is now entangled with §8.** P3D-F2 (recalculation silently drops the scholarship that
generation applied) and the annual-apportionment question are the same subsystem, and fixing either one
in isolation changes money. I recommend they be decided and done together, as one authorised
scholarship change, rather than piecemeal.

---

## 10. Exact next phase

1. **Answer §8** — which of the three options for the annual-apportionment conflict.
2. If option 1: authorise the scholarship apportionment fix (P3D-F2 folded in), then **re-capture the
   golden master** against corrected behaviour.
3. Then produce the five-endpoint routing design, showing explicitly how the annual scholarship amount
   reaches the canonical generator without being lost.
4. Then flag-OFF equivalence, then flag-ON convergence on ₹800 across Admission / Generate /
   Recalculate / Regenerate / Bulk.
5. Only after all of that: retire the orphaned readers, and consider the flag.

**`REAL_ADMISSION_READY` remains FALSE. `USE_ENTITLEMENT_BILLING` remains FALSE. No commit, push or
deploy.**
