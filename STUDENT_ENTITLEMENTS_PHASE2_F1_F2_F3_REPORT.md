# STUDENT ENTITLEMENTS — PHASE 2: F1 → F2 → F3 REMEDIATION

**F1 = PASS · F2 = PASS · F3 = PASS · B1/B2/B3 = UNTOUCHED · `REAL_ADMISSION_READY = FALSE`**
**No live writes · no flag changed · nothing committed, pushed or deployed**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`
**Baselines:** `STUDENT_ENTITLEMENTS_ADMISSION_CUTOVER_AUDIT.md`, `STUDENT_ENTITLEMENTS_PHASE1_AB_SHADOW_REPORT.md`

---

## 0. Step 0 — pre-change trace (no material divergence from Phase 1)

Verified against current source before touching anything. Phase 1 held, with two refinements worth
recording because they changed *where* the fix belongs:

| Question | What the source actually says |
| --- | --- |
| Does the new reader support a head **name**? | **Yes.** `Fee_entitlement_reader:120-127` matches `head:<token>` against `feeHeadId` **OR** `feeHead`. The reader was never the defect. |
| Then where is F1? | In the **migration**. `mapScope()` returned `null` when `targetFeeHeadId` was empty, skipping the row — while legacy honoured it by name (`Fee_concession_reader:73-74`). |
| Are fee-head ids stable? | **Yes** — minted as `FH_` + 12 hex (`Fee_firestore_sync:148-150`), preserved by name across chart saves. |
| What does the capture UI send as a head target? | A **free-text name** (`_entitlements_capture.php:96`, placeholder *"e.g. transport / Tuition"*). So UI-captured head concessions already rely on the reader's name fallback. |
| Is `amountDue` reachable from any UI? | **No.** The capture partial offers percent / fixed / fullExempt only (`:101-103`). It is reachable only by a direct API POST. |
| Can F2's divergence fire today? | **No.** Tuition specs carry **no `category` field** (`Fee_lifecycle:377-402`); `category` is set only by `createModuleFee` (`:1110`), and service fees do not flow through `_applyDeductions`. F2 is **latent**, activating at Phase D/3 convergence. |

No stop condition was met.

---

## 1. F1 — head-scoped mapping no longer loses the fee-head name

**Root cause.** `scripts/migrate_student_entitlements.js::mapScope()` mapped `head` scope from
`targetFeeHeadId` **only**:

```js
const head = String(d.targetFeeHeadId || '').trim();
return head ? `head:${head}` : null;      // ← empty id ⇒ record SKIPPED
```

A legacy concession `scope='head', targetFeeHeadId='', targetFeeHead='Lab Fee'` **applies** in legacy
(net 200 on a 400 head) but was discarded at migration — the student would silently be billed **more**.

**Representation decision — Option A-variant, chosen on evidence, not by copying legacy.**
Keep the single canonical token `head:<token>`, where the token is a stable `FH_…` id when one exists
and the head **name** when one never did. Rejected: adding a second `targetFeeHead` field, which would
have required changes to the reader, the validator, the UI and the migration for no additional safety —
the reader already resolves both, and the `FH_` + 12-hex id shape means a human-authored head name can
never be mistaken for an id.

**Exact change** — `scripts/migrate_student_entitlements.js`:

```js
const head = String(d.targetFeeHeadId || '').trim();
if (head) return `head:${head}`;                       // stable id wins
const headName = String(d.targetFeeHead || '').trim();
return headName ? `head:${headName}` : null;           // name fallback; null only if BOTH absent
```

Plus a structural change that makes the mapper **verifiable**: the module's Firebase bootstrap and its
`main()` now sit behind `require.main === module`, so requiring the file has **no side effects** — no
credentials read, no Firebase app created, no migration run — and `mapScope` / `VALUE_TYPE_MAP` are
exported. The unit test therefore executes the **real** mapper, not a transcription.

**Before / after billing** (400 head, 50% head-scoped concession with an empty id):

| | Legacy | New (before F1) | New (after F1) |
| --- | --- | --- | --- |
| net | **200** | **400** — concession lost | **200** |

**Tests** — `tests/Unit/Phase2EntitlementSemanticsTest.php`:

| Test | Proves |
| --- | --- |
| F1-01 | stable id → identical money on both sides |
| F1-02 | **the defect** — empty id + valid name survives and bills identically |
| F1-03 | deterministic matching: an unknown id discounts nothing; a name token hits its own head only |
| F1-04 | unresolvable head **fails closed** — never a discount |
| F1-05 | the migration skips **only** when neither id nor name exists |
| F1-06 | satisfied throughout — the real reader and real deduction algorithm execute |

---

## 2. F2 — the service-head rule, now explicit and numerically pinned

**Canonical rule, as implemented:**

> A blanket (`scope: 'all'`) concession **never** nets against a separately-enrolled service head —
> `transport`, `hostel`, `meals`. Discounting a service requires a deliberately targeted entitlement:
> `category:<service>` or `head:<service head>`.

**Code path.** `Fee_entitlement_reader::SERVICE_CATEGORIES` (`:55`), enforced at `:115-119` — a scope of
`all` returns `false` when the demand's `category` is a service category. The legacy reader has **no**
such rule.

**No broadening, no narrowing.** The rule was already implemented; this phase did not change one line
of it. What changed is that it is now **documented and asserted numerically**, so it cannot surface at
cutover as an accident.

**Allowed vs refused, from the passing tests:**

| Situation | Result |
| --- | --- |
| blanket 20% on an academic head (1000) | **800** — applies |
| blanket 20% on transport (800) | **800** — refused |
| blanket 20% on hostel (5000) | **5000** — refused |
| blanket 20% on meals (1200) | **1200** — refused |
| `category:transport` 25% on transport (800) | **600** — applies |
| `head:Bus Fee` 50% on transport (800) | **400** — applies |
| ordinary academic percent / fixed | unchanged |

**⚠ OPERATOR DECISION STILL REQUIRED.** F2-07 asserts the difference explicitly: on a transport head,
legacy bills **640** and the new rule bills **800**. This is an **intentional business-rule difference**,
not a defect — but it is a real price change for any student holding a blanket concession, and it needs
your sign-off before B2 (the reader cutover). It cannot fire today (§0), so nothing is at risk now.

---

## 3. F3 — `amountDue` removed (Option B)

**Decision: Option B — remove it from the vocabulary.** Evidence, all from source:

- **No capture surface offers it** — the shared partial lists percent / fixed / fullExempt only.
- **The migration cannot emit it** — `VALUE_TYPE_MAP` has no such entry.
- **Zero records exist** platform-wide (Phase 1).
- **It is semantically foreign to the model.** Every supported type is an *additive deduction* that sums
  with its peers; "amount due" is an *absolute net* that cannot be summed. Option A would have required
  inventing a precedence rule for absolute-vs-additive that no product decision has ever requested — new
  billing semantics smuggled in as a bug fix.

**Exact changes:**

| File | Change |
| --- | --- |
| `Entitlement_service.php` | `VALUE_TYPES` = `['percent','fixed','fullExempt']` (was `+ 'amountDue'`), with the rationale recorded in place |
| `Entitlement_service.php` | validator's `in_array($vt, ['fixed','amountDue'])` → `$vt === 'fixed'` |
| `Fee_generation_service.php` | `_applyDeductions()` now logs **`ENTITLEMENT_VALUETYPE_UNSUPPORTED`** for any type it cannot apply |

**The arithmetic is unchanged.** An unapplicable rule still contributes nothing — the safe direction
(bill gross rather than guess a discount) — but it is no longer *invisible*. Silence is precisely what
hid this hazard. In a healthy system that log line is unreachable, because the service now refuses to
create such a record; it exists to catch future vocabulary drift or a hand-written document.

**Resulting behaviour:**

| Value type | 1000 head | Status |
| --- | --- | --- |
| percent 25 | 750 | supported |
| fixed 300 | 700 | supported |
| fullExempt | demand dropped | supported |
| `amountDue` 250 | **1000 + `ENTITLEMENT_VALUETYPE_UNSUPPORTED`** | cannot be created; fails closed and loud |

**Precedence, now pinned by F3-06 (one explicit rule):**
`fullExempt` **wins** over everything; `percent` and `fixed` **sum**; the total clamps at **zero** —
never negative, never a credit.

---

## 4. Migration semantic matrix

Regenerated after the fixes (`scripts/p1ab_semantic_fixture.php`), real matchers + real arithmetic:

| Case | Verdict |
| --- | --- |
| C1 `all` percent 25 | MATCH |
| C2 `all` fixed 200 | MATCH |
| C3 `all` fullExempt | MATCH |
| C4 `head` by stable id | MATCH |
| C5 `head` id empty + name | **MATCH** *(was MISSING_NEW — fixed by F1)* |
| C6 `head` id token holding a name | MATCH |
| C7 `category` academic | MATCH |
| C8 `all` vs a SERVICE head | **INTENTIONAL DIFFERENCE (F2 / I3)** |
| C9 window closes mid-session | MATCH |
| C10 revoked → discontinued | MATCH |

```
9 MATCH · 1 INTENTIONAL DIFFERENCE · 0 DEFECT
```

**No unexplained billing divergence remains.**

---

## 5. Test results

| Stage | Result |
| --- | --- |
| Phase 2 tests, **before** the fixes | **20 tests, 5 failures** — F1-01, F1-02, F1-05, F3-01, F3-07 |
| Phase 2 tests, **after** the fixes | **OK — 20 tests, 51 assertions** |
| Full Unit suite | **1716 tests, 5406 assertions, 10 failures** |
| Baseline failures | **10** — the documented set, unchanged |
| **New failures** | **0** |
| Fixed failures | 5 (the Phase 2 regressions above) |

The ten baseline failures are unchanged: `BackupTest`, `HomeworkInputLengthValidationTest`,
`HomeworkRosterFailClosedTest`, `HomeworkSchoolIdPredicateTest`, `HomeworkSilentCatchTest`,
`ParentHomeworkRepoSilentCatchTest` (×3), `TeacherExecuteDeleteCascadeTest` (×2). No unrelated test was
altered.

The suite grew 1696 → 1716: exactly the 20 tests added.

---

## 6. Live data

**ZERO live writes.** Re-verified read-only after the work:

```
studentConcessions        : 0 docs
studentEntitlements       : 0 docs
studentServiceEnrollments : 0 docs
scholarshipAwards         : 0 docs
```

No student, demand, receipt, accounting, concession, entitlement or service record was created,
altered or deleted. No migration was run. All Phase 2 verification is in-process with no Firestore
connection opened by the test suite.

---

## 7. Flags — none enabled for cutover

| Flag | Value | Note |
| --- | --- | --- |
| `USE_UNIFIED_FEE_GEN` | `true` | pre-existing (committed HEAD), untouched |
| `CONCESSION_UI_ENABLED` | `true` | pre-existing, untouched |
| `SERVICE_ENROLLMENT_UI_ENABLED` | `false` | untouched |
| `ENTITLEMENT_SHADOW_COMPARE` | `false` | untouched |
| `USE_ENTITLEMENT_SERVICE_PRICING` | `false` | untouched |
| `PHASE_3_CONVERGED` | `false` | untouched |
| `atomic_capacity_enabled` | `true` | as previously authorized, untouched |
| `capacity_group_max_students` | `499` | untouched |

**No cutover flag was enabled.**

Protected files verified unchanged by digest: `firestore.rules 4f9ed6e1`,
`firestore.indexes.json c473c914`, `Sis.php 9222e572`, `Fee_lifecycle.php facbbcf3`,
`Fees.php 5b6b957a`.

---

## 8. Explicit readiness

```
F1 = PASS
F2 = PASS   (implementation + evidence complete; ONE operator sign-off outstanding — §2)
F3 = PASS
B1 = UNTOUCHED   (admission ordering)
B2 = UNTOUCHED   (billing-reader cutover)
B3 = UNTOUCHED   (third-authority convergence)

REAL_ADMISSION_READY = FALSE
```

---

## 9. Files changed

| File | Change |
| --- | --- |
| `scripts/migrate_student_entitlements.js` | F1 name fallback; side-effect-free require; exports `mapScope`/`VALUE_TYPE_MAP` |
| `application/libraries/Entitlement_service.php` | F3: `amountDue` removed from `VALUE_TYPES`; validator simplified |
| `application/libraries/Fee_generation_service.php` | F3: `ENTITLEMENT_VALUETYPE_UNSUPPORTED` diagnostic (arithmetic unchanged) |
| `tests/Unit/Phase2EntitlementSemanticsTest.php` | **NEW** — 20 tests covering F1/F2/F3 + the matrix |
| `tests/Support/map_scope_probe.js` | **NEW** — executes the real migration mapper from PHPUnit |
| `scripts/p1ab_semantic_fixture.php` | updated to mirror the fixed mapping; reads `VALUE_TYPES` dynamically |

All uncommitted.

---

## 10. What Phase 3 should do next — not started, not authorized

1. **F2 sign-off.** Confirm the blanket-vs-service rule as product policy, and decide how existing
   holders of blanket concessions are told their service fees are no longer discounted. This gates B2.
2. **B1 — admission ordering.** The largest remaining blocker: entitlement capture must precede demand
   generation, or approval must trigger regeneration. Nothing in Phase 2 touched it.
3. **B2 — the reader swap**, behind its own flag, once F2 is signed off. The estate is still empty, so
   this remains the cheapest moment to do it.
4. **B3 — fence or converge `Fees::_getStudentDiscounts()`** before anything can write `exemptedFees`
   or `discountHeads` again.
5. **B4 — services.** `kind='service'` entitlements still bill GROSS and nothing writes
   `studentServiceEnrollments`; `USE_ENTITLEMENT_SERVICE_PRICING` stays off.
6. **B5 — `propose()` dedupe.** Two clicks still create two active concessions that would sum.

**Stopping here for review. No commit, no push, no deploy.**
