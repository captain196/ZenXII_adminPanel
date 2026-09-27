# A · UAT SRNO-1 END-TO-END VALIDATION PLAN  ·  B · PHASE C IMPLEMENTATION GATE

**A: test plan only — the admission was NOT executed.**
**B: the service-classification dependency is NOT resolved. STOPPED before any scholarship code.**
2026-08-17 · UAT tenant `SCH_B56BB9A401`

---

# A. UAT SRNO-1 END-TO-END VALIDATION PLAN (NOT EXECUTED)

Purpose: prove the SRNO-F1 path end-to-end on a real admission, consuming SRNO 1 naturally.

## A0. Preconditions — re-verify immediately before, read-only

Re-run `scripts/uat_srno_reset_postverify.php`. Required state, all of which held at 05:20 UTC today:

```
pointer ABSENT · srNoIndex 0 docs · claim_1..25 ABSENT
23 students, 0 with srNo · feeDemands 26
USE_ENTITLEMENT_BILLING = false   (the legacy concession reader stays the pricing authority)
```

**Abort if any differs** — in particular if the pointer reappeared, which would mean something called
`allocateNext()` in the interim and SRNO 1 is already gone.

Capture a fresh pre-admission snapshot (same shape as `UAT_SRNO_RESET_SNAPSHOT.json`): student ids,
`feeDemands` / `feeReceipts` / `scholarshipAwards` / `studentEntitlements` / `admissions` counts, and
the target section's `currentStrength`.

## A1. The action — exactly one

**One** admission through the canonical admission endpoint in `Sis.php` — the path carrying the
SRNO-F1 fix, where `_claimIdentityAttributes()` sets `$srnoAssigned = false` on failure and the caller
rolls back. Performed **by the operator through the UI**, not by a script: the point is to exercise
the real request path, and a script would bypass the controller guards being validated.

- Tenant `SCH_B56BB9A401`, an ordinary class/section with free capacity.
- A clearly-marked UAT student name.
- **No SRNO typed in** — allocation must be automatic.
- One student only. Not a bulk import.

## A2. Success criteria — the SRNO chain

| # | Assertion | Where |
| --- | --- | --- |
| 1 | admission returns success | HTTP response |
| 2 | `feeCounters/_sys_SRNO_SCH_B56BB9A401` exists, **`value = 1`** | pointer |
| 3 | `feeCounters/_sys_SRNO_SCH_B56BB9A401_claim_1` exists | reservation |
| 4 | `srNoIndex/SCH_B56BB9A401_1` exists | ownership |
| 5 | that doc has `srNo = 1`, `currentStudentId = <new STU>`, `claimedBy` set, **no session field**, `historicalStudentIds = []` | school-scoped identity |
| 6 | the student document carries `srNo = 1` (and `srNoNorm` if written) | student |
| 7 | `srNoIndex` for the tenant = **exactly 1 doc** — no duplicate SRNO | uniqueness |
| 8 | `claim_2` … `claim_25` still ABSENT — exactly one number consumed | no over-allocation |
| 9 | resolution works: `school + SRNO 1 → the new studentId` via `Sr_no_service::resolve()` | the identity contract |

## A3. Side-effect criteria — nothing else moves

| # | Assertion |
| --- | --- |
| 10 | the **23 pre-existing students are byte-unchanged and still have no `srNo`** — this is the legacy population and the admission must not touch it |
| 11 | `feeDemands` = 26 + exactly the new student's demands; **STU0012's 26 unchanged** |
| 12 | every new demand carries `studentId` = the new student, `schoolId` = tenant, and a non-empty `dueDate` |
| 13 | `feeReceipts` unchanged (0) — an admission must not create a receipt |
| 14 | `scholarshipAwards`, `studentEntitlements` unchanged (0) |
| 15 | no accounting/journal entry created by the admission itself |
| 16 | section `currentStrength` incremented by exactly 1 |
| 17 | no other tenant changed — `SCH_218AAF5C23` still 1,118 demands |
| 18 | `billing_state` on the response/log is `billed`, not `billing_pending` / `billing_failed` |

## A4. Rollback safety — how far this test can honestly go

The happy path above does **not** exercise rollback. Proving rollback needs an *induced* SRNO
failure, which is a genuinely different test:

- **A4a (safe, recommended): static verification.** Confirm by reading `Sis.php` that the failure
  branch still deletes the student via `entity_sync->deleteStudent()`, calls
  `id_generator->releaseClaim('STU', …)`, recomputes section strength, and returns `json_error` —
  and that `PhaseSrnoF1AdmissionIdentityTest` (11 tests) still passes. This is already covered by the
  existing suite and costs nothing.
- **A4b (NOT recommended without separate authorisation): live induced failure.** The only realistic
  trigger is making `allocateNext()` fail — e.g. pre-creating `claim_1..15` so all 15 attempts
  collide. That deliberately burns SRNOs and leaves residue we would have to clean again. **I do not
  propose running it.**

So A4 is **static only** unless you say otherwise, and I will report rollback as "verified by test +
code inspection", never as "verified live".

## A5. Post-test verification

Fresh process, read-only, diffed against the A0 snapshot — assertions 1–18 plus a reconciliation:
exactly **one** new student, **one** new `srNoIndex` doc, **one** new claim doc, pointer 0 → 1.

**Stop conditions:** if SRNO ≠ 1, if the pointer lands anywhere but 1, if any pre-existing student
gains an `srNo`, if `feeDemands` for STU0012 change, or if the admission succeeds *without* an SRNO —
stop and report; do not "fix forward" during a validation run.

## A6. Scope

UAT only. `REAL_ADMISSION_READY` stays **FALSE** — this validates the SRNO path, it does not certify
real-school admission. No `enroll_student()` change (SRNO-F2 stays unimplemented), no B3, no commit.

**NOT EXECUTED. Awaiting your instruction.**

---

# B. PHASE C IMPLEMENTATION GATE — SERVICE CLASSIFICATION

Your §6: *"If an authoritative classification mechanism is still unavailable, report the exact
dependency and STOP before changing scholarship production code."*

## B1. Verdict — **UNAVAILABLE. I am stopping.**

An authoritative service classification does not exist today. This is not "the table is empty" — there
are **four independent defects**, and populating `feeCategories` fixes only the first.

## B2. The exact dependency, with evidence

**D1 — the lookup table is empty everywhere.**
`feeCategories` holds **0 documents platform-wide** (live query, all tenants). A CRUD surface *does*
exist — `Fee_management::save_category()` / `delete_category()` — so the mechanism is real and
reachable; no school has ever used it.

**D2 — the canonical generation path never populates `category` at all.**
The admission spec built in `Fee_lifecycle.php:377` (`$entryData`) has **no `category` key**.
`Fee_firestore_txn.php:895` therefore persists `category => ''`, and
`Fee_generation_service.php:147` reads `$cat = $data['category'] ?? ''` → `''`.

Live confirmation in the UAT tenant — every demand, including the transport head:

```
feeHead='Tuition Fee'    category=''
feeHead='Library Fee'    category=''
feeHead='Transport Fee'  category=''      ← a service head classified as nothing
```

**Consequence — invariant I3 is currently inert on the canonical path.**
`Fee_entitlement_reader::appliesTo()` enforces I3 as:

```php
if ($scope === 'all') {
    if (in_array(strtolower(trim($category)), self::SERVICE_CATEGORIES, true)) return false;
}
```

With `$category = ''` that test is always false, so **a blanket `scope:'all'` concession WOULD net
against `Transport Fee`** — the exact outcome I3 exists to prevent. Only the legacy
`Fees::_generateDemandsForMonth()` path computes a category at all (`_resolveFeeCategory()`,
`Fees.php:4949`); the canonical path does not.

**D3 — the fallback heuristic cannot express two of the three service categories.**
`SERVICE_CATEGORIES = ['transport', 'hostel', 'meals']`, but `_resolveFeeCategory()`'s keyword
fallback has branches for transport/bus, tuition, lab/library, sport/game — and **no branch for
hostel and none for meals**. Both fall through to `'General'`. So even where the heuristic runs, I3
protects transport only; hostel and meals are silently unprotected.

**D4 — the operator vocabulary omits the same two categories.**
`Fee_management::save_category()` validates
`$validTypes = ['Academic', 'Transport', 'Extra-curricular', 'Other']` — **no Hostel, no Meals**.
I3 matches on the free-text `name`, so an operator who happens to type "Hostel" would work by
coincidence; nothing enforces or even suggests it. A financial invariant resting on an operator's
choice of free text is not an authority.

## B3. Why this blocks Phase C specifically

Your locked decisions include **"default scope = academic"** and **"transport/hostel/meals excluded by
default"**. Both are evaluated through exactly the `$category` value shown above to be `''`. Building
the whole-session allocator on top of that would mean:

- eligible-gross weighting computed over a base that **silently includes transport, hostel and meals**;
- a scholarship capped by "total eligible gross" where *eligible* is wrong, so the cap is wrong;
- and because the allocation is proportional, **every** demand's share is wrong, not just the service
  ones.

That is a silent monetary error across the whole allocation, which is precisely the class of failure
these gates exist to stop. **I will not write scholarship production code against it.**

## B4. What would resolve it — for your decision, not implemented

1. **Make `category` authoritative at the source** — populate it on the canonical spec in
   `Fee_lifecycle` from a real classification, so every demand carries it at write time.
2. **Give the classification a closed vocabulary** covering `academic | transport | hostel | meals`
   (plus whatever else), stored on the fee head or the category record — not inferred from a name.
3. **Extend `save_category()`'s `$validTypes`** to include Hostel and Meals so the operator surface can
   express what I3 enforces.
4. **Backfill or migration decision** for existing demands with `category=''` — including whether
   legacy demands are reclassified or grandfathered.
5. Only then: fail-closed behaviour for an unclassifiable head, so an unknown head is never
   silently treated as academic.

Items 1–3 are code changes to production financial paths. **None is authorised and none has been
made.** I've registered this as the Phase C blocker rather than working around it with the name
heuristic, which §6 explicitly forbids as a permanent authority.

## B5. Everything else in Phase C is ready

The 16 locked financial decisions are unchanged and understood; the whole-session allocator was
already measured to add **zero Firestore reads** (48 specs 0.0 ms → 4,800 specs 2.0 ms, in-memory over
an already-built spec set), and the session-scoped award fetch *replaces* the existing
`readScholarshipAwards()` call for a net read delta of ±0. **Classification is the only blocker.**

---

## State

Nothing executed, nothing changed. No admission · no `allocateNext()` · no scholarship, entitlement,
B3, N+1, SRNO-F2 or SRNO-F3 work · no student modified · no backfill · no rules or index change
(baseline re-checked at `5b0f86fc`, concurrent Staff-Lifecycle/HRMS work untouched) · no RTDB · no
commit, push or deploy. `USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE`.

**Awaiting: (A) authorisation to run the one UAT admission, and (B) your direction on the
classification dependency.**
