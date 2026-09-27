# FEE HEAD REGISTRY AND CLASSIFICATION — FINAL DESIGN

**IMPLEMENTATION-READY DESIGN. NOTHING IMPLEMENTED, MIGRATED, OR MODIFIED.**
2026-08-17 · supersedes `FEE_HEAD_REGISTRY_AND_CLASSIFICATION_DESIGN.md`

---

## 1. Executive summary

ZenX has no fee-head entity. A head exists only as a per-chart pricing row, so its financial nature is
recovered at runtime by guessing from its display name — and 1,190 of 1,242 live demands (95.8%) carry
no classification at all, including **500 `Transport Fee` demands** that a blanket concession would
therefore wrongly discount.

This design introduces one school-scoped **Fee Head Registry** as the single classification authority,
stamps the resolved `category` onto every new demand at generation, and freezes legacy demands
untouched. It **adds no field to the demand schema and changes no reader** — `category` already flows
through spec → `Fee_firestore_txn:895` → demand and is already read by
`Fee_generation_service:147`. The work is to make that value authoritative, not to invent a pipeline.

Both open business decisions are now resolved (**§5 administrative**, **§6 meals**), and the
cross-platform impact has been verified rather than assumed (**§16**). **Readiness: READY.**

## 2. Locked invariants

| | Invariant |
| --- | --- |
| **A** | Fee Head identity is **school-scoped**: `schoolId + feeHeadId → head`. Never session-scoped. Survives rollover, repricing, rename, archival. |
| **B** | Pricing is **session-scoped**: session/class/section/amount/frequency/billingType live in `feeStructures`. |
| **C** | Classification belongs to the school-scoped head. Readers consume the **stamped `demand.category`** — never names, substrings, module names, or read-time reclassification. |
| **D** | SRNO is **school-scoped and session-independent**: `school + SRNO → student`. Untouched by this work. |

A and D are the same architectural lesson: **a school-scoped operational identity must never
accidentally become session-scoped.**

## 3. Final identity model

`feeHeadId` = `FH_{12 hex}` — the token shape already in the data, but **minted once per school per
head** and stored in the registry, not per chart row. Uniqueness of the *name* is enforced by a
create-if-not-exists claim doc, the primitive already trusted by `Id_generator`, `srNoIndex`,
`apaarIndex` and `Sr_no_service`.

**Rename moves the claim; the `feeHeadId` never changes.** That single property is what makes
classification survivable — today a rename silently reclassifies.

## 4. Final classification vocabulary (closed, lowercase)

The one financial question it answers: **does the default scholarship/concession rule apply?**

| Category | Meaning | Default | Service? | Operator-visible today? | Explicit scope can opt in? |
| --- | --- | --- | --- | --- | --- |
| `academic` | the school's own instructional charge — tuition, examination, registration, admission, library, lab, sports levies | **eligible** | no | **yes** | n/a (already eligible) |
| `transport` | vehicle conveyance — route/bus/van fares | **excluded** | yes | **yes** | **yes** |
| `hostel` | boarding and lodging | **excluded** | yes | **yes** | **yes** |
| `meals` | food service — mess/canteen | **excluded** | yes | **NO — reserved (§6)** | yes, once shipped |
| `administrative` | administrative/operational charges — ID-card replacement (§5) | **excluded** | **no** | **yes** | yes, only by an explicit future policy |
| `unknown` | unclassified or unrecognised | **fails closed** | n/a | no — never selectable | **no** |

`administrative` is deliberately **excluded but not a service** — it is not something a family opts
into, so grouping it with transport/hostel would misrepresent it even though the default matches.

**Not financial categories:** `extra_curricular`, `other`, `General`, `Extra-curricular`. For every one
of them the eligibility answer is identical to `academic`, so a separate category would be a second
way to express one rule. They belong to `displayGroup` (§7 of your brief).

## 5. Administrative decision — ADOPTED

ID-card replacement fees (`Sis.php:3927` → `Id_card_fee_policy`, the third `createModuleFee` caller
alongside Transport and Hostel) are classified **`administrative`, excluded by default**. They are
**not** to be classified `academic` for convenience. A scholarship may reach them only under an
explicit future scope/policy that names the category.

## 6. Meals decision — RESERVED BUT UNUSED

Determination stands: **ZenX does not bill meals** — no module, controller, collection, or head; the
token exists only in `Fee_entitlement_reader::SERVICE_CATEGORIES`. Therefore:

- `meals` **stays in the closed vocabulary** (removing it would weaken I3 the day meals ships);
- it is **hidden from the operator UI**; no head may be created with it today;
- **no meals module, data, or billing behaviour is created.**

## 7. Registry schema

**Collection:** `feeHeadRegistry` · **Doc id:** `{schoolId}_{feeHeadId}` · **Claim doc:**
`{schoolId}_name_{nameNorm}` (same collection, `kind: 'nameClaim'`).

```
feeHeadRegistry/{schoolId}_{feeHeadId}
{
  feeHeadId, schoolId,                          // IMMUTABLE — identity
  name, nameNorm,                               // mutable — display identity
  category, categorySetAt, categorySetBy,       // financial classification + provenance
  status,                                       // 'active' | 'archived'
  displayGroup, sortOrder,                      // display only, no financial meaning
  createdAt, createdBy, updatedAt, updatedBy
}
```

| Rule | Specification |
| --- | --- |
| **Immutable** | `feeHeadId`, `schoolId`, `createdAt`, `createdBy` — rejected on update |
| **Mutable** | `name`, `nameNorm`, `category` (forward-effective), `status`, `displayGroup`, `sortOrder` |
| **Validation** | `name` non-empty ≤ 64 chars; `category` ∈ the closed vocabulary **minus `unknown`** (never operator-assignable) **minus `meals`** (§6); `status` ∈ {active, archived} |
| **Uniqueness** | `nameNorm` = lowercase-trim-collapse-whitespace; enforced by create-if-not-exists on the claim doc. Rename = create new claim → on success delete old → update doc. Claim failure aborts the rename with no partial state |
| **Archive** | `status='archived'`; excluded from new chart rows and from pickers; **historical demands untouched**; resolvable by id so old demands still explain themselves |
| **Delete** | **Not offered by any API.** Deleting a head would orphan financial history |

## 8. Session pricing model

```
feeStructures/{school}_{session}_{class}_{section}
  .feeHeads[] = { feeHeadRef, amount, frequency, billingType }
```

`feeHeadRef` = the registry `feeHeadId`. **The mutable `name` ceases to be an identity authority** —
it may still be denormalised for display, but nothing resolves on it. The legacy per-row `feeHeadId`
is left in place, unread, and retired at M7.

## 9. Canonical spec integration

Resolution happens **before** any demand is generated:

```
Fee_head_resolver::resolve(schoolId, feeHeadRef)
    → active registry entry  →  category
    → archived               →  category (historical rows still price out)
    → not found / no ref     →  FAIL CLOSED: abort generation, named error, zero writes
        ↓
$entryData['category'] = <registry category>        ← Fee_lifecycle:377
        ↓
Fee_generation_service:147  $cat = $data['category']   ← already reads this key
```

No spec may be emitted without a resolved category. `unknown` is never written by this path — it is a
read-time interpretation of legacy `''` only.

## 10. Demand stamping

```
Fee_firestore_txn:895   'category' => <resolved>,  'categorySource' => 'registry'
```

- **Immutable once written.** The writer must not overwrite an existing non-empty `category` on merge —
  this closes the blanking risk where a recalculating path that omits `category` silently clears it.
- **Never re-derived.** No reader reconstructs category from `feeHead`, module name, or chart.
- `categorySource` absent ⇒ legacy; `'registry'` ⇒ post-cutover. That is how the two eras coexist.

## 11. Module integration

| Module | Today | Under this design |
| --- | --- | --- |
| **Transport** | `createModuleFee(…, 'Transport', …)` writes `lc(moduleType)` — accidentally correct | resolves a registry head → `transport` |
| **Hostel** | `Hostel.php:360`, same mechanism. **No hostel head exists in any chart** — created only at runtime | resolves a registry head → `hostel` |
| **ID-card** | `Sis.php:3927` via `Id_card_fee_policy` — currently unclassified | resolves a registry head → **`administrative`** |
| **Meals** | does not exist | remains unused until a real module ships |

**Q23 — preventing future anonymous heads:** `createModuleFee()` must require a resolved
`feeHeadRef`. A module that supplies an unregistered head **fails closed** rather than minting an
implicit one. This is the rule that stops the problem recurring.

**Migration completeness note:** the M1 proposal must enumerate not only the 6 chart heads but also
**runtime-only module heads** (Hostel, ID-card) which appear in no chart — otherwise the first
post-cutover hostel or ID-card fee would fail closed in production.

## 12. Legacy-data strategy

**The 1,190 `category=''` demands are frozen.** No backfill, no rewrite. `netAmount`, `grossAmount`,
`paidAmount`, `balance`, concessions, scholarships and receipts are **not** altered at any step.

- Read-time interpretation of `''` is **`unknown`**.
- `unknown` fails closed for **new** financial decisions; it does **not** retroactively withdraw a
  concession already committed to a historical demand.
- **If a legacy demand is ever recalculated** and its head cannot be authoritatively resolved,
  recalculation **fails closed with a named error** rather than guessing `academic`. A visible refusal
  is strictly better than a silent monetary error.
- The 98 demands carrying neither `schoolId` nor `feeHead` are a pre-existing anomaly outside this
  scope; they remain `unknown` and are not touched.

## 13. Migration / cutover

| Step | Writes | Reads | Existing financial records change? | Gate / verification | Rollback |
| --- | --- | --- | --- | --- | --- |
| **M1** proposal | **none** | `feeStructures` + module head names + distinct `feeHead` on demands | **no** | report only | n/a |
| **M2** operator confirmation | none (UI state) | M1 output | **no** | **nothing commits without explicit per-head confirmation** | abandon |
| **M3** mint registry | `feeHeadRegistry` docs + name claims **only** | M2 output | **no** | every head has a category; claims unique | delete the collection — nothing depends on it yet |
| **M4** add `feeHeadRef` | additive field on chart rows | registry | **no** — pricing untouched | every active chart row resolves | field becomes unread |
| **M5** resolver live | new demands only | registry | **no** — only *future* demands differ | **flag default false**; canary one class | **flip the flag** |
| **M6** soak | none | new demands | **no** | sample categories vs expectation; I3 fires on transport | flip the flag |
| **M7** retire heuristic + `_titles` head role | code removal | — | **no** | full suite + T33 | git revert (post-soak only) |

**No destructive migration. No historical demand rewrite. No automatic guessing.** Live student
balances cannot change at any step: no step writes to `feeDemands`.

## 14. Rollback

M3 is deletable, M4 is additive and inert, **M5 rolls back by flipping one flag**, M6 is read-only.
M7 is the only irreversible step and runs last, after soak. Because no legacy demand is ever modified,
**there is no financial state to restore** — rollback is a behaviour switch, not a data repair.

## 15. Security / RBAC — requirements only, nothing written

**No rules or index change is proposed in this pass.** When implementation reaches M3:

1. **fetch/reconcile the latest canonical remote rules first**, 2. inspect the working tree,
3. reconcile concurrent Staff-Lifecycle/HRMS changes, 4. only then propose a controlled change.
The baseline has already moved twice under us (`f58499f8` → `5b0f86fc`); **do not overwrite it.**

Future requirements: a `match /feeHeadRegistry/{doc}` block with `isSameSchoolWrite()`-style tenant
guards; name-claim docs writable only by the same school and never client-deletable; reads
school-filtered with `status` filtering done in the query, not the client; writes gated on Fees
`manage` (RBAC, not a legacy role-name gate); classification changes audited via
`categorySetBy`/`categorySetAt`. Index: `schoolId + status` (+ `sortOrder` if the picker sorts).

## 16. Performance — and a verified cross-platform result

**The N+1 is not fixed here.** It stays registered for B3. The sequencing is preserved exactly:

```
Fee Head Registry / Classification → Scholarship correction → B3 routing → N+1 fix during routing
```

The registry adds **one cached read per school per request** (the head set is ~6 rows; memoise like
`canonicalHeadIdMap()` already does). The allocator itself remains 0 additional reads / ~2 ms at
4,800 specs.

**Cross-platform impact — verified, not assumed.** The **Parent app does read `demand.category`**
(`TransportFeesRepository.kt:64`), which I checked rather than presuming no coupling:

```kotlin
val cat = demand.category.trim()
if (cat.equals("transport", ignoreCase = true)) return true
val head = demand.feeHead.lowercase()
return head.contains("transport") || head.contains("bus")
```

It is **category-first with a name fallback**. Today `category=''` falls through to the name and works;
after cutover `category='transport'` matches on the first branch — **identical result, no app change
required**, and the match is case-insensitive so the 24 legacy `Transport` values also work. The
change is strictly an improvement: a transport head named "Conveyance" is missed today and correctly
included after. The name fallback should eventually be retired, but it is harmless and is **not** a
prerequisite. **The Teacher app has no fee-category coupling at all.**

## 17. Scholarship / concession integration

```
eligible(demand) = demand.category === 'academic'
                   OR award.scope explicitly names demand.category
'' | 'unknown'   → NOT eligible; new generation refused (fail closed)
```

Mechanically delivers: default academic ✓ · transport excluded ✓ · hostel excluded ✓ · meals excluded
✓ (vacuously) · **administrative excluded ✓** · explicit service scope opts in ✓ · unknown fails
closed ✓. Eligible-gross weighting and the total-eligible-gross cap are then computed over a base that
is correct by construction. **No name lookup, no module-name inference, no heuristic.**
**The scholarship engine remains unimplemented and blocked behind this work.**

## 18. Test matrix (36)

| # | Test | # | Test |
| --- | --- | --- | --- |
| 1 | identity is school-scoped | 19 | regeneration preserves category |
| 2 | identity is session-independent | 20 | recalculation preserves category |
| 3 | rename preserves `feeHeadId` | 21 | legacy `''` never silently classified |
| 4 | repricing preserves `feeHeadId` | 22 | legacy balances unchanged |
| 5 | rollover preserves `feeHeadId` | 23 | rename cannot reclassify old demands |
| 6 | archive prevents new use | 24 | school A cannot resolve school B's head |
| 7 | delete unavailable in every API | 25 | two sessions share one head identity |
| 8 | name uniqueness enforced | 26 | Transport module resolves a registry head |
| 9 | category validated against the closed vocabulary | 27 | Hostel module resolves a registry head |
| 10 | administrative classification assignable | 28 | ID-card resolves an **administrative** head |
| 11 | administrative excluded by default | 29 | unregistered module head fails closed |
| 12 | `meals` reserved in the vocabulary | 30 | no duplicate registry identity |
| 13 | `meals` hidden from operator UI | 31 | no duplicate name claim |
| 14 | `unknown` fails closed | 32 | scholarship reads `demand.category` only |
| 15 | transport excluded | 33 | **no runtime name heuristic remains after cutover** |
| 16 | hostel excluded | 34 | rollback flag restores prior behaviour |
| 17 | explicitly scoped service category opts in | 35 | no accounting duplication |
| 18 | admission/monthly/bulk converge on one category | 36 | no duplicate fee demands |

Plus **T37 (added):** `unknown` is never operator-assignable, and **T38:** a merge write never
overwrites a non-empty `category`.

## 19. Failure modes

| Mode | Behaviour |
| --- | --- |
| Head not in registry | generation aborts, named error, **zero demands written** |
| Chart row missing `feeHeadRef` | pre-generation gate fails loudly before any write |
| Legacy demand recalculated, head unresolvable | refuse; never default to `academic` |
| Name claim collision | rename/create aborts with no partial state |
| Registry read fails (network) | fail closed — never fall back to the heuristic |
| Module supplies an unregistered head | fail closed (Q23) |
| Concurrent rename + generation | generation resolves by immutable id; unaffected |

## 20. Open risks

1. **Operator mapping quality.** Six confirmations decide classification for everything downstream. A
   wrong confirmation is a wrong financial rule — mitigated by provenance fields and forward-effective
   changes, not prevented.
2. **Runtime-only heads.** Hostel and ID-card appear in no chart; if M1 misses them, their first
   post-cutover fee fails closed in production. Called out in §11 as a completeness requirement.
3. **Rules baseline drift.** Moving under concurrent work; M3 needs the reconcile-first procedure.
4. **Single-session data.** Only `2026-27` exists live, so rollover behaviour (T5, T25) can be proven
   by test but not yet observed in production data.
5. **Pre-existing 98 anomalous demands** remain `unknown` — untouched, out of scope.

## 21. Implementation sequence

1. `Fee_head_resolver` + registry schema constants + the closed vocabulary (unit-tested, unwired)
2. M1 read-only proposal script (chart heads + runtime module heads + demand head names)
3. Operator confirmation UI (M2) — no financial path touched
4. Rules + index, under the §15 reconcile-first procedure
5. M3 mint · M4 additive `feeHeadRef`
6. Wire the resolver into `Fee_lifecycle` spec building behind the M5 flag (default **false**)
7. Converge monthly/bulk/module paths onto the same resolver
8. Canary → soak (M6) → retire the heuristic and the `_titles` head role (M7)
9. **Only then** unblock the scholarship correction, then B3, then the N+1 fix

---

## IMPLEMENTATION READINESS

**READY.**

Both business decisions are resolved (`administrative` adopted, `meals` reserved-but-hidden), the
architecture is fully specified against traced code and live data, the migration is additive and
flag-gated with a one-flag rollback, and the cross-platform question was **verified** — the Parent
app's category-first-with-name-fallback filter makes this change compatible with no app work, and the
Teacher app has no coupling.

Two items are **sequenced dependencies, not blockers**: the `feeHeadRegistry` rules and index must be
authored at step 4 under the reconcile-first procedure (deferred by your instruction, not unresolved);
and the M1 proposal must include runtime-only module heads (§11), which is a specified requirement of
the step rather than an open question.

**Nothing implemented.** No registry, no migration, no backfill, no `feeCategories`/`feeDemands`/
`Fee_lifecycle`/`Fee_generation_service`/`Fee_firestore_txn` edit, no scholarship code, no SRNO change,
no rules/indexes, no RTDB, no commit/push/deploy/merge/rebase. `USE_ENTITLEMENT_BILLING = false` ·
`REAL_ADMISSION_READY = FALSE`. The system is **not** declared production-ready; this declares the
*design* ready to implement on your authorisation.
