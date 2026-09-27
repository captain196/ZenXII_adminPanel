# STUDENT ENTITLEMENTS — PHASE 3B: B1 + B2 CUTOVER IMPLEMENTATION

**B1 = PASS · B2 = PASS · B3 = UNTOUCHED · `ENTITLEMENT_BILLING_CUTOVER = OFF` · `REAL_ADMISSION_READY = FALSE`**
**No live data changed · nothing committed, pushed or deployed · no real admission performed**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c9244b6b41e1c6e1fcc059bbd66a85cfee5b4`

---

## 1. Part 0 — baseline, and it matched

| Item | Value |
| --- | --- |
| HEAD | `758c9244b6b41e1c6e1fcc059bbd66a85cfee5b4` (`integration/phase2`) |
| Suite baseline | 1729 tests / 10 failures |
| Live `studentEntitlements` / `studentConcessions` / `studentServiceEnrollments` / `scholarshipAwards` | **0 / 0 / 0 / 0** |

Pre-change digests: `Sis.php 9222e572` · `Fee_lifecycle.php facbbcf3` · `Fee_generation_service.php
1ee335a9` · `Fee_concession_reader.php ec88ea49` · `Fee_entitlement_reader.php 36be55fd` ·
`Entitlement_service.php 7bccb08b` · `Entitlements.php 004f7dd8` · `studentAdmission.php d9cfd934` ·
`capacity_cas.php f113cd9d` · `firestore.rules 4f9ed6e1` · `firestore.indexes.json c473c914`.

Nothing differed unexpectedly.

---

## 2. Part 1 — the accessor boundary (the B2 prerequisite)

**Before.** `Fee_generation_service` asked a rule for its deduction semantics by naming legacy fields
inline: `(string) ($c['type'] ?? '')` and `(float) ($c['value'] ?? 0)`. An entitlement stores
`valueType` / `value` and has **no `type` field at all**, so the reader swap alone would have fetched
the right rows through the wrong fields — `type` resolving to `''`, no branch matching, **every
entitlement deducting zero**.

**After.** `Fee_concession_reader` gained the two accessors that `Fee_entitlement_reader` already had,
so both readers satisfy one contract, and the generator asks the reader:

```php
fn(array $c): string => $this->concessionReader->deductionType($c),
fn(array $c): float  => $this->concessionReader->deductionValue($c)
```

The generator now names **no** source field and knows **no** collection. Swapping the reader is the
only change the cutover makes; the arithmetic below it is untouched.

---

## 3. Part 2 — the cutover flag, at exactly one boundary

New flag in `fees_exemption_v2_flags.php`:

```php
$config['USE_ENTITLEMENT_BILLING'] = false;   // B2 cutover: true
```

Read in **exactly one place** — `Fee_lifecycle::_routedBuildAdmissionSpecs()` — which constructs *one*
reader and passes it to the generator:

```
false → Fee_concession_reader   (studentConcessions, legacy)
true  → Fee_entitlement_reader  (studentEntitlements, canonical)
```

**Never both, structurally.** Only one reader object is ever built, so there is no code path in which
two candidate sets exist. A double discount is impossible rather than merely avoided. A flag-read error
falls back to the legacy authority that has been billing all along.

Enforced by test: the flag appears exactly once in `Fee_lifecycle`, never in the generator, and the
generator's authoritative path names neither collection.

---

## 4. Part 4/5 — B1 admission reordering and billing state

**New sequence in `Sis::save_admission()`:**

```
… identity (Student ID · SRNO · APAAR · admission number) …
        ↓
★ _resolve_admission_entitlement()      ← NEW, before pricing
        ↓
★ assignInitialFees()                   ← now prices against it
        ↓
billing state resolved → history · audit · receipt · notify
        ↓
response carries billing_state + entitlement
```

An entitlement known at the desk is submitted **with** the form (`ent_*` fields) and resolved in the
same request. Post-admission ENTCAP capture is untouched and remains the route for concessions agreed
later. Approval policy is not re-implemented — it defers to the same `has_permission('Fees','manage')`
rule `Entitlements::_approval_required()` uses, so the desk and the API cannot diverge.

**B6 fixed — fee generation no longer fails silently.** It previously caught, logged and carried on, so
a student could be admitted with **zero demands** under a green "Admission successful". Now:

| Outcome | State | Message |
| --- | --- | --- |
| demands created | `billed` | "Student admitted successfully." |
| entitlement failed, demands created without it | `billing_pending` | "…BILLING PENDING" + how to fix |
| exception, **or zero demands with no exception** | `billing_failed` | "…BILLING FAILED" + do not collect payment |

Both failure paths log greppably: `ACC_ADMISSION_BILLING_FAILED` and `ACC_ADMISSION_BILLING_EMPTY`.
The request is *not* aborted — the student genuinely exists by then, so aborting would be a lie in the
other direction. The admission stands; the billing outcome is reported truthfully.

---

## 5. Part 6 — entitlement dedupe (B5)

**Before.** The document id was `{school}_{student}_{timestamp}_{random}` — every call created a new
document by construction. A double-click, an HTTP retry or a resubmitted admission created a **second
active concession**, and two active concessions **sum** in `_applyDeductions()`. The student was
double-discounted.

**After.** The id is derived from the entitlement's **business identity** — student, kind, scope,
valueType, normalised value, frequency, window, session. `reason`, `source`, `requestedBy` and the
timestamps are deliberately excluded: they are provenance, not price, so a retyped reason cannot mint a
second concession.

Concurrency uses **create-if-not-exists (409)** — the same primitive `Id_generator` uses for STU claims
— so two simultaneous retries race and exactly one creates. `COLLISION` is reported as
`{ok: true, duplicate: true}` with the existing status, never as an error (a successful retry must not
look like a failure) and never as a fresh record.

---

## 6. Part 7 — admission UI

Two minimal changes to `studentAdmission.php`, no redesign, Bootstrap 3 / AdminLTE untouched:

1. **Carry a known entitlement with the form.** Purely additive — if the panel is untouched or
   half-filled, no `ent_*` field is sent and `save_admission()` takes its unchanged path.
2. **Render the financial state, not the transport status.** The alert level is driven by
   `billing_state`: green only for `billed`, amber for `billing_pending`, red for `billing_failed`.
   An HTTP 200 no longer produces a green "Admission successful!" over an unbilled student.

---

## 7. Tests

Test-first throughout: every fix has a test that failed before it.

| Suite | Before the change | After |
| --- | --- | --- |
| `Phase3bEntitlementBillingCutoverTest` (**new**, 27 tests) | **10 failures** | **OK — 27 tests, 77 assertions** |
| `Phase3B1AdmissionOrderingTest` (13 tests) | 3 asserted the OLD defects | **OK — 13 tests, 47 assertions** |
| `Phase2EntitlementSemanticsTest` (20) | — | OK |
| Entitlement controller suites (40) | — | OK |

**Three Phase-3 tests were inverted, not deleted.** They existed to pin defects this phase was
authorized to fix, so leaving them asserting the old behaviour would have been the weakening the brief
forbids. Each now asserts the corrected contract from the same angle:

| Test | Was | Now |
| --- | --- | --- |
| `B1-01` | admission does NOT touch the entitlement system | admission resolves it, and **strictly before** `assignInitialFees()` |
| `B1-11` | *"…is currently swallowed"* | *"…is reported not swallowed"* — asserts `billing_failed` + both log markers + `billing_state` in the response |
| `B2-PREREQ` | generator **hardcodes** legacy accessors | generator **uses the reader accessor boundary** |

**Full Unit suite: 1756 tests, 5530 assertions, 10 failures.** Baseline 10, **new 0**. 1729 → 1756 is
exactly the 27 tests added.

---

## 8. The required proof

| | Billed |
| --- | --- |
| ₹1000 tuition, 20% entitlement, **legacy authority** (flag OFF) | **₹1000** |
| ₹1000 tuition, 20% entitlement, **entitlement authority** (flag ON) | **₹800** |
| ₹1000 tuition + ₹800 transport, 20% **blanket academic** entitlement | tuition **₹800**, transport **₹800** |
| ₹800 transport, explicit `category:transport` 25% | **₹600** |

A **negative control** asserts the two authorities produce different money — if the reader choice made
no difference, every other test in the file would be vacuous, and that test fails.

Full semantics matrix (A–K) passes: no entitlement → 1000 · 20% → 800 · fixed 500 → 500 · fullExempt →
demand dropped · rejected → 1000 · future-effective → 1000 · expired → 1000 · percent+fixed additive →
700 · fullExempt wins → dropped · over-deduction clamps at 0 · unsupported type → 1000 **plus
`ENTITLEMENT_VALUETYPE_UNSUPPORTED`**.

---

## 9. Idempotency and the failure matrix

| Retry vector | Protected by | Duplicate possible? |
| --- | --- | --- |
| Student / Student ID | one `_nextStudentId()` per request + "already exists" refusal | No |
| Admission number / SRNO / APAAR | claimed after the student write, unchanged this phase | No |
| **Entitlement** | **deterministic fingerprint id + 409 create-if-not-exists (new)** | **No** |
| Fee demands | deterministic demand ids — regeneration overwrites | No |
| Accounting posting | unchanged; guarded by the existing receipt path | No |

| Failure point | Student | Entitlement | Demands | State returned | Retry safe? |
| --- | --- | --- | --- | --- | --- |
| validation | none | none | none | `json_error` | yes |
| student write | none | none | none | `json_error` | yes — nothing minted past the guard |
| identity claim | exists | none | none | warning appended | yes |
| entitlement proposal | exists | none | priced without it | **`billing_pending`** | yes — resubmit adds it |
| entitlement approval pending | exists | pending | priced without it | `billed` + "pending" note | yes |
| **fee generation** | exists | as resolved | **none** | **`billing_failed`** | yes — Generate Demands |
| history / audit | exists | as resolved | exist | `billed` | yes |
| receipt / accounting | exists | as resolved | exist | `billed` + fee warning | yes |
| response lost | exists | as resolved | exist | — | **yes** — a resubmit hits the duplicate-id refusal and the entitlement fingerprint |

No financial record is deleted anywhere to make a test pass.

---

## 10. Files changed

| File | Change |
| --- | --- |
| `application/libraries/Fee_concession_reader.php` | **+** `deductionType()` / `deductionValue()` — the shared accessor contract |
| `application/libraries/Fee_generation_service.php` | asks the reader for deduction semantics; names no field |
| `application/libraries/Fee_lifecycle.php` | the single authority boundary, behind `USE_ENTITLEMENT_BILLING` |
| `application/config/fees_exemption_v2_flags.php` | **+** `USE_ENTITLEMENT_BILLING = false` |
| `application/libraries/Entitlement_service.php` | deterministic fingerprint id + create-if-not-exists dedupe |
| `application/controllers/Sis.php` | `_resolve_admission_entitlement()` before pricing; billing state; B6 fix |
| `application/views/studentAdmission.php` | submit a known entitlement; render the billing state |
| `tests/Unit/Phase3bEntitlementBillingCutoverTest.php` | **NEW** — 27 tests |
| `tests/Unit/Phase3B1AdmissionOrderingTest.php` | three tests inverted to the corrected contracts |

**Unchanged, verified by digest:** `firestore.rules 4f9ed6e1` · `firestore.indexes.json c473c914` ·
`capacity_cas.php f113cd9d`. No RTDB. No Parent/Teacher app change. No atomic-capacity change.

---

## 11. Flags

| Flag | Value |
| --- | --- |
| **`USE_ENTITLEMENT_BILLING`** | **`false`** — the cutover is OFF |
| `USE_UNIFIED_FEE_GEN` | `true` (pre-existing) |
| `CONCESSION_UI_ENABLED` | `true` (pre-existing) |
| `SERVICE_ENROLLMENT_UI_ENABLED` · `ENTITLEMENT_SHADOW_COMPARE` · `USE_ENTITLEMENT_SERVICE_PRICING` · `PHASE_3_CONVERGED` | `false` |
| `atomic_capacity_enabled` / `capacity_group_max_students` | `true` / `499` (unchanged) |

---

## 12. Live data

**Zero writes.** No admission performed, no student, demand, receipt, concession, entitlement or
accounting record created or altered. `studentEntitlements` and `studentConcessions` re-verified at
**0 docs**. No migration. No production collection populated to make a test pass — every test is a
deterministic in-process fixture with no Firestore connection.

---

## 13. Status and the required answers

```
F1 = PASS
F2 = PASS — APPROVED
F3 = PASS
B1 = PASS
B2 = PASS
B3 = UNTOUCHED

ENTITLEMENT_BILLING_CUTOVER = OFF
REAL_ADMISSION_READY = FALSE
```

| Question | Answer |
| --- | --- |
| Is the new entitlement actually used in initial admission billing? | **Yes — when `USE_ENTITLEMENT_BILLING` is ON.** Admission resolves the entitlement before pricing, and the generator reads `studentEntitlements`. With the flag OFF (as shipped) billing is unchanged. |
| Does ₹1000 + 20% academic entitlement produce ₹800? | **Yes**, flag ON. **₹1000** flag OFF. Both asserted. |
| Does a blanket academic entitlement leave transport undiscounted? | **Yes — ₹800 stays ₹800** (F2, approved). |
| Can fee-generation failure still silently report admission success? | **No.** `billing_failed`, a red alert, and two greppable log markers. |
| Can retry create a duplicate entitlement? | **No.** Deterministic fingerprint + 409 create-if-not-exists. |
| Can retry create duplicate fee demands? | **No.** Demand ids are deterministic; regeneration overwrites. |
| Can retry create duplicate student identity? | **No.** One mint per request, plus the "already exists" refusal. |

**`REAL_ADMISSION_READY` stays FALSE** — deliberately. The mechanism is implemented and unit-proven,
but the flag is OFF, no live UAT has been run, and B3 (the third discount authority in
`Fees::_getStudentDiscounts()`) is still open.

---

## 14. What I did not do, and why

- **Did not enable the flag.** It ships OFF; enabling it is your decision, as with atomic capacity.
- **Did not touch B3.** `Fees::recalculate_demands()` still re-derives from `exemptedFees` /
  `discountHeads` / `scholarshipAwards`. All three are empty, so there is no live conflict, but a
  student whose demands carry `concessionApplied` is only *partially* fenced (`Fees.php:6944`).
- **Did not run a live admission**, per Part 13.
- **Did not use the legacy dual-write**, per your instruction.

**Recommended next:** authorize live UAT with `USE_ENTITLEMENT_BILLING = true` on
`SCH_B56BB9A401` using a dedicated test student — not a real school student — then B3.

**Stopping for your review. No commit, no push, no deploy.**
