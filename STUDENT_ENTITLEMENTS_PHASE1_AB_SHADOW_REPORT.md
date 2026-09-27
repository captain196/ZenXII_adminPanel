# STUDENT ENTITLEMENTS — PHASE 1 A/B SHADOW VERIFICATION

**READ-ONLY. No production change, no flag change, no write, no migration, nothing committed.**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Tenant:** `SCH_B56BB9A401`
**Baseline:** `STUDENT_ENTITLEMENTS_ADMISSION_CUTOVER_AUDIT.md` (blocker IDs B1–B9 preserved)

---

## 1. EXECUTIVE VERDICT

```
A/B_INCONCLUSIVE
```

The comparison could not be performed against live data because **there is no live data to compare**.
`studentConcessions`, `studentEntitlements`, `studentServiceEnrollments` and `scholarshipAwards` are
**all empty — zero documents, platform-wide**, not merely in the testing tenant.

This is not a "we don't know". It is a precise and useful fact, and it cuts both ways:

- **Good:** there is no legacy concession estate to migrate, reconcile or get wrong. B9's migration-risk
  dimension is effectively **nil today**.
- **Bad:** it means the shadow cycle can never produce evidence, so the cutover cannot be justified by
  "the two agree in production". Every guarantee must come from code-level proof instead.

So this phase did the second thing: it executed the **real production matchers and the real deduction
algorithm** over a deterministic fixture, and that **found two genuine divergence classes plus one
latent vocabulary gap**, all of which would change money the day real concessions exist.

**B9 is now answered.** It is no longer a blocker for lack of evidence — it is answered "there is no
estate", and the three findings below replace it.

---

## 2. DATASET SCOPE

| Measure | Value |
| --- | --- |
| Tenant | `SCH_B56BB9A401` (Vikrant Public) |
| Session examined | `2026-27` |
| Students examined | **23** |
| Old concession records (`studentConcessions`, all statuses) | **0** |
| New entitlement records (`studentEntitlements`, all statuses) | **0** |
| Comparable records | **0** |
| Service records (`kind='service'` / `studentServiceEnrollments`) | **0** |
| Third-authority records (`exemptedFees` / `discountHeads` / `scholarshipAwards`) | **0 / 0 / 0** |
| Students holding any rule on either side | **0** |

**Platform-wide census (counts only, no tenant identifiers, no personal data):**

```
studentConcessions        : 0 docs total
studentEntitlements       : 0 docs total
studentServiceEnrollments : 0 docs total
scholarshipAwards         : 0 docs total
```

Note against the project record: the migration was previously reported as run with `--commit`,
finding one inert document. **That document no longer exists anywhere.** The most likely explanation
is that it belonged to the retired probe tenant `SCH_D94FE8F7AD`, which was deleted. Either way, the
migration has nothing to carry forward.

---

## 3. COMPARISON RESULTS

### 3a. LIVE (authoritative for the verdict)

| Class | Count |
| --- | --- |
| MATCH | 0 |
| MISSING_NEW | 0 |
| EXTRA_NEW | 0 |
| VALUE_DIVERGENCE | 0 |
| STATUS_DIVERGENCE | 0 |
| PERIOD_DIVERGENCE | 0 |
| DUPLICATE | 0 |
| SERVICE_NO_LEGACY_EQUIVALENT | 0 |
| THIRD_AUTHORITY_ONLY | 0 |
| OTHER | 0 |

All zero because both sides are empty. The real `Fee_concession_reader` and `Fee_entitlement_reader`
were executed against live Firestore for all 23 students; both returned empty candidate sets for
every one.

### 3b. DETERMINISTIC FIXTURE (clearly labelled — NOT live)

Ten legacy concession shapes, each mapped through the migration's own `mapScope()` /
`VALUE_TYPE_MAP` rules, then billed by the **real** production code on both sides.

| Class | Count | Cases |
| --- | --- | --- |
| MATCH | **8** | C1, C2, C3, C4, C6, C7, C9, C10 |
| MISSING_NEW | **1** | C5 |
| VALUE_DIVERGENCE | **1** | C8 |
| STATUS_DIVERGENCE | 0 | — |
| PERIOD_DIVERGENCE | 0 | C9 proves the window logic is byte-identical |
| DUPLICATE | 0 | not exercised (no live estate) |
| SERVICE_NO_LEGACY_EQUIVALENT | n/a | see §5 |

---

## 4. SAMPLE EVIDENCE

### VALUE_DIVERGENCE — C8, invariant I3 (blanket concession vs a service head)

A legacy `scope='all'`, `percent 20` concession, applied to a tuition head and a transport head:

```
legacy nets : Bus Fee 640 · Tuition 800
new    nets : Bus Fee 800 · Tuition 800
```

The legacy reader discounts the bus fare; the new reader **refuses** it
(`Fee_entitlement_reader::SERVICE_CATEGORIES`, `:55` and `:117`). This is deliberate and, in my view,
correct — a blanket tuition concession should not quietly discount transport. But it **is** a
divergence: at cutover, every student holding a blanket concession would start being billed **more**
on service heads.

### MISSING_NEW — C5, head-scoped concession with no stable head id

```
legacy: scope='head', targetFeeHeadId='', targetFeeHead='Lab Fee'  → applies, net 200 (from 400)
new   : record would not exist — the migration SKIPS it
```

`migrate_student_entitlements.js::mapScope()` returns `null` when `targetFeeHeadId` is empty, so the
record is skipped with a logged problem. The legacy reader, however, has a **name fallback**
(`Fee_concession_reader:73–74`) and applies the concession anyway. Any legacy concession that relied
on that fallback **silently disappears** at cutover, and the student is billed **more**.

### MATCH — C6, the near-miss worth naming

```
legacy: targetFeeHeadId='Lab Fee', targetFeeHead='Lab Fee'  → net 200
new   : scope='head:Lab Fee'                                 → net 200
```

This matches **only because the id field literally contains the head name**. The new reader's fallback
(`:126`) compares the *same* target token against `$feeHead`, whereas legacy compares a *separate*
field. The two agree by coincidence of content, not by design — a concession with
`targetFeeHeadId='HD_LAB'` against a chart head that has no stable id would match on neither.

### Latent — `amountDue` produces a silent zero deduction

`Entitlement_service::VALUE_TYPES` (`:68`) permits `amountDue`. `_applyDeductions`
(`Fee_generation_service:141–148`) handles only `fullExempt`, `percent`, `fixed`.

```
ACTIVE entitlement valueType='amountDue' value=250 on a 1000 head
→ net produced by the real algorithm: 1000   (750 if it were honoured)
```

The migration never emits `amountDue`, so no migrated record can hit this. But `propose()` accepts it
today, so an operator capturing that type through the live UI creates an approved entitlement that
**bills full price with no error**.

---

## 5. FIELD MAPPING

| Concept | OLD `studentConcessions` | NEW `studentEntitlements` | Transformation |
| --- | --- | --- | --- |
| Student | `studentId` | `studentId` | identical |
| Tenant | `schoolId` | `schoolId` | identical |
| Session | `session` (`null` = all sessions) | `session` (same convention) | identical — both readers apply the same null rule |
| Status gate | `status == 'active'` | `status == 'active'` **plus `kind == 'concession'`** | new side filters `kind` server-side |
| Kind | *(none)* | `kind` ∈ service · concession · credit_note | **new concept** |
| Scope | `scope` ∈ all · head · category **+** `targetFeeHeadId` / `targetFeeHead` / `targetCategory` | single string: `all` · `head:<id>` · `category:<cat>` | target folded into the scope string — **the lossy step**; `targetFeeHead` (the NAME) is dropped |
| Deduction type | `type` ∈ percent · fixed · fullExempt | `valueType` ∈ percent · fixed · fullExempt · **amountDue** | renamed; vocabulary widened beyond what the algorithm supports |
| Deduction value | `value` | `value` | identical (`fullExempt` forced to 0) |
| Effective from | `effectiveFrom` (`YYYY-MM-DD`, required) | `effectiveFrom` | identical; migration SKIPS malformed values |
| Effective to | `effectiveTo` (nullable) | `effectiveTo` (nullable) | identical |
| Legacy status vocab | `active` · `revoked` | `active` · `discontinued` (+ proposed, pending_approval, rejected) | `revoked → discontinued` |
| Approval | *(none)* | `approvalRequired`, `approvedBy`, `approvedAt` | **new**; not read by either matcher — `status` remains the gate |

---

## 6. SEMANTIC DIFFERENCES (records that look alike but bill differently)

1. **Blanket concession × service head (I3).** Same `all`/`percent`/`value` on both sides; different
   money on any head whose `category` is transport, hostel or meals.
2. **Head scope resolved by name.** Legacy carries id *and* name and can match on either. The new model
   carries one token; the migration keeps the id and discards the name.
3. **`amountDue`.** A valid, approvable entitlement type that the deduction algorithm does not
   implement — indistinguishable from a normal record until you look at the net.
4. **Approval state is not a billing gate.** An entitlement can be `active` with `approvalRequired=true`
   and `approvedBy=null` only via a code path that bypasses `approve()`; both readers gate on `status`
   alone, so approval metadata carries no billing weight. Not a divergence today — worth pinning.

---

## 7. BILLING IMPACT — "if we switched the reader today, what changes?"

With the estate empty: **nothing changes for anyone.** Zero students hold a rule on either side, so
swapping `Fee_concession_reader` for `Fee_entitlement_reader` today would move exactly ₹0.

That makes the swap unusually cheap — and it does **not** make it safe, because the swap's risk is
entirely in what gets captured *after* it:

| Finding | Impact once real concessions exist |
| --- | --- |
| I3 (C8) | Students with blanket concessions billed **more** on transport/hostel/meals |
| MISSING_NEW (C5) | Concessions relying on the name fallback vanish — students billed **more** |
| `amountDue` | Approved concession silently ignored — student billed **full price** |
| B1 ordering (unchanged) | A concession captured at admission still cannot affect the demands already written |
| B3 third authority | Empty today, so no current conflict — the structural risk in `Fees::_getStudentDiscounts()` remains |

---

## 8. MIGRATION SAFETY

> **Can we safely switch the billing reader from `studentConcessions` to `studentEntitlements` based
> on this evidence?**

```
NOT YET
```

**Why not "YES", given the swap moves ₹0 today:** because a reader swap is not the deliverable — a
*working concession* is. Swapping the reader while B1 (ordering) and B2 (approval has no fee effect)
are open produces a system where the new authority is read but nothing ever populates it in time to
bill. You would trade a legacy path that at least works for a new path that is correct and inert.

**Why not "NO":** nothing found here argues against the destination. The models are semantically
comparable, 8 of 10 shapes bill identically, and the two that do not are both understood, both
one-directional (the new side bills *more*, never less), and both fixable before any real data exists.

**The safest possible moment to swap is now-ish** — while the estate is empty, so no reconciliation,
no backfill and no divergence window exists — but only *after* the three findings above are closed and
B1/B2 are addressed.

---

## 9. THIRD-AUTHORITY FINDINGS

`students.exemptedFees`, `students.discountHeads` and `scholarshipAwards` were inventoried read-only
across all 23 students: **0 students carry any of them, and `scholarshipAwards` holds 0 documents.**

So the third authority creates **no current divergence**. The structural hazard from the audit is
unchanged and unfixed: `Fees::recalculate_demands()` (`Fees.php:7006`) still bypasses `Fee_lifecycle`
entirely and re-derives from `_getStudentDiscounts()` (`Fees.php:4729`), which reads only those three
sources. Today it has nothing to read. The moment anyone populates `exemptedFees` or `discountHeads`,
a third answer to "what does this student owe" comes back to life.

The partial fence at `Fees.php:6944` (skip demands carrying `concessionApplied`) still stands and still
only protects demands that *already* have a concession applied.

---

## 10. RECOMMENDATION FOR PHASE 2 — not implemented, not authorized

Phase 2 must address, in this order:

1. **F1 — the `head:` mapping loses the head name.** Either carry both tokens
   (`head:<id>` + a name fallback field) or have the migration/UI guarantee a stable head id.
   Without this, C5-shaped concessions silently disappear. *(Cheap now — no data to re-migrate.)*
2. **F2 — decide I3 explicitly.** Confirm with the operator that a blanket concession must NOT discount
   transport/hostel/meals, and record it as a deliberate behaviour change rather than letting it
   surface as a billing surprise.
3. **F3 — close the `amountDue` gap.** Either implement it in `_applyDeductions` or remove it from
   `Entitlement_service::VALUE_TYPES`. Shipping a proposable type that bills nothing is the worst of
   the three options.
4. **B1 — admission ordering.** Capture must precede demand generation, or approval must trigger
   regeneration. Unchanged from the audit and still the largest blocker.
5. **B2 — the reader swap itself**, behind its own flag, once 1–4 are done. Do it while the estate is
   empty.
6. **B3 — fence or converge `Fees::_getStudentDiscounts()`** before anyone can write `exemptedFees`
   or `discountHeads` again.

**Do not** enable `ENTITLEMENT_SHADOW_COMPARE` as a verification step. It is genuinely read-only in
itself, but the only way to trigger it is to run a real generation, and every production caller of that
generation commits demands. With an empty estate it would also report nothing. This report replaces it.

---

## 11. WHAT WAS ACTUALLY TESTED AND READ

**Executed against LIVE Firestore (read-only):**
- `Fee_concession_reader::activeConcessionsForStudent()` — real class, 23 students
- `Fee_entitlement_reader::activeConcessionsForStudent()` — real class, 23 students
- Unfiltered census of `studentConcessions` / `studentEntitlements` (all statuses, all kinds, all sessions)
- Per-student inventory of `students.exemptedFees` / `discountHeads`
- Count-only platform-wide census of all four collections

**Executed with NO Firestore connection (deterministic fixture):**
- `Fee_concession_reader::appliesTo()` and `Fee_entitlement_reader::appliesTo()` — real classes
- `Fee_generation_service::_applyDeductions()` — the real, shared deduction algorithm, reached by
  reflection rather than re-implemented, so the arithmetic under test is the shipped arithmetic
- The migration's own `mapScope()` / `VALUE_TYPE_MAP` rules

**Write-call audit:** both scripts were grepped for `firestoreSet`, `firestoreUpdate`,
`firestoreCreate`, `firestoreDelete`, `commitBatch`, `setDocument`, `updateDocument`,
`deleteDocument`, `writeDemand`, `updateDemand`, `assignInitialFees`, `updateEntity`, `saveStudent`
and every `Entitlement_service` mutator — **zero matches** outside comments.

**Artifacts (new, uncommitted):**

| File | Purpose |
| --- | --- |
| `scripts/p1ab_shadow_compare.php` | live read-only A/B comparator + third-authority inventory |
| `scripts/p1ab_semantic_fixture.php` | deterministic semantic equivalence harness |

---

## 12. CONSTRAINTS HONOURED

No production code modified · no flag changed (`atomic_capacity_enabled` still `true` as authorized;
all `fees_exemption_v2_flags` as found) · no student, concession, entitlement, service, demand,
receipt or accounting record created, altered or deleted · no migration · no approval, proposal,
rejection or discontinuation · no fee regeneration or recalculation · `firestore.rules`,
`firestore.indexes.json`, `capacity_cas.php`, `Fee_generation_service.php`, `Fee_lifecycle.php`,
`Fees.php` and the Admission implementation all untouched · nothing committed, pushed or deployed ·
atomic capacity not reopened.
