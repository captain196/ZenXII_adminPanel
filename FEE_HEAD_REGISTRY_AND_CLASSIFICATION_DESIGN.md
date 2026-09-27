# FEE HEAD REGISTRY AND CLASSIFICATION — TECHNICAL DESIGN

**READ-ONLY DESIGN. NOTHING IMPLEMENTED. NOTHING MIGRATED. NO LIVE DATA CHANGED.**
2026-08-17 · derived from live data + traced code, no invented relationships

---

## 1. Current architecture

```
feeStructures/{school}_{session}_titles              per-school+SESSION head list
   .feeHeads[] = { name, type }                        name + billing frequency only
feeStructures/{school}_{session}_{class}_{section}    per-class PRICING rows
   .feeHeads[] = { feeHeadId, name, amount, frequency[, billingType] }
        ↓
Fee_lifecycle::buildAdmissionDemandSpecs → $entryData (:377)        ← no category
Fees::_generateDemandsForMonth (:4949) → _resolveFeeCategory()      ← name heuristic
Fee_lifecycle::createModuleFee (:1137) → category = lc(moduleType)  ← module-driven
        ↓
Fee_generation_service (:147) $cat = $data['category'] ?? ''
Fee_firestore_txn (:895)      'category' => $pick($data,['category'])
        ↓
feeDemands.category → Fee_entitlement_reader::appliesTo() → invariant I3
```

Live shape, all tenants: **9 `feeStructures` docs** (7 charts + 2 `_titles`), **36 head rows**,
**6 distinct names**, **28 distinct `feeHeadId`s**, **8 rows with no id**, **one session (2026-27)**,
2 schools. Related collections are empty: `fees`, `feeSettings`, `feeCategories`, `feeMonths`,
`scholarships`, `scholarshipDiscount`, `serviceEnrollments`, `studentServices` — all **0 docs**
(`studentDiscounts` holds the 1 live doc that must not be touched). `feeHeads` is a **field name,
not a collection**.

## 2. Current deficiencies

| # | Deficiency | Evidence |
| --- | --- | --- |
| A | No fee-head entity exists | no head collection anywhere; 0 docs in every candidate |
| B | `feeHeadId` is a row id, not an identity | 6 real heads → **28 ids**, and **0 ids reused across charts** |
| C | 8 rows carry no id at all | `Admission/Examination/Library/Registration` 1 each, `Transport/Tuition` 2 each |
| D | Classification stored nowhere on a head | chart rows carry only `name/amount/frequency/billingType` |
| E | Three writers disagree | admission → `''`; monthly → heuristic; module → `lc(moduleType)` |
| F | 1,190 / 1,242 demands `category=''` | incl. **500 `Transport Fee`** — I3 inert for all of them |
| G | Rename silently reclassifies | classification derived from the name string |
| H | Registry is session-scoped | `{school}_{session}_titles` — a head's nature is not a per-session fact |

## 3. Proposed stable identity model

**Identity is minted, never derived.** A new `feeHeadId` of the form `FH_{12 hex}` is **retained as
the token shape** (the existing chart rows already use it, so nothing new is invented), but it is
minted **once per school per head** and stored in the registry — not per chart row.

- Scope: **school**, exactly like SRNO. `school + feeHeadId → head`. **Never keyed by session.**
- Doc id: `feeHeadRegistry/{schoolId}_{feeHeadId}` — the repo's standard `{school}_{key}` grammar.
- Uniqueness of the *name* is enforced by a claim doc, reusing the create-if-not-exists primitive
  already trusted by `Id_generator`, `srNoIndex`, `apaarIndex` and `Sr_no_service`:
  `feeHeadRegistry/{schoolId}_name_{normalisedName}` → holds the `feeHeadId`. Rename releases the old
  claim and takes a new one; **the `feeHeadId` never changes.**

**Identity survives rename, re-pricing, session rollover and archival. Nothing else does.**

## 4. The school-scoped Fee Head Registry

```
feeHeadRegistry/{schoolId}_{feeHeadId}
{
  feeHeadId:      "FH_AACC5FDE7970",   // identity, immutable, minted once
  schoolId:       "SCH_B56BB9A401",    // identity, immutable
  name:           "Tuition Fee",       // display, mutable (rename)
  nameNorm:       "tuition fee",       // uniqueness key, derived from name
  category:       "academic",          // CLASSIFICATION — closed vocabulary
  categorySetAt:  "2026-08-17T…",      // classification provenance
  categorySetBy:  "SSA0011",
  status:         "active",            // active | archived   (never deleted)
  displayGroup:   "Academic",          // display only — free text, no financial meaning
  sortOrder:      10,                  // display only
  createdAt, createdBy, updatedAt, updatedBy
}
```

**Field roles, kept explicitly separate per §4 of your brief:**

| Role | Fields | Mutable? |
| --- | --- | --- |
| **Identity** | `feeHeadId`, `schoolId` | **Never** |
| **Classification** | `category` (+ provenance) | Yes, forward-effective only (§5) |
| **Display** | `name`, `displayGroup`, `sortOrder` | Yes, zero financial effect |
| **Lifecycle** | `status` | active ⇄ archived |
| **NOT here — session-scoped** | `amount`, `frequency`/`billingType`, class, section | lives in `feeStructures` |

**Answers to §2 Q1–Q30**

| Q | Answer |
| --- | --- |
| 1 | Stable identity = minted school-scoped `feeHeadId`; name is *not* identity |
| 2–5 | Fields and their roles: the table above |
| 6 | Amount, frequency, class, section stay in session-scoped `feeStructures` |
| 7 | Create = mint id + claim `nameNorm` + require `category` (no anonymous creation) |
| 8 | Rename = update `name`/`nameNorm`, move the claim, **id unchanged**; no reclassification |
| 9 | Archive = `status='archived'`; hidden from new charts, historical demands untouched |
| 10 | **No.** Delete is not offered — a deleted head would orphan financial history |
| 11 | Nothing happens to historical demands: their `category` was stamped at generation |
| 12 | Yes, but **forward-effective only** |
| 13 | From the change instant: only demands generated *after* it use the new value |
| 14 | Protected by the stamp — historic demands are never re-derived (§8) |
| 15 | Chart rows gain `feeHeadRef` = the registry `feeHeadId` |
| 16 | The 28 unstable ids are **retired**, not migrated — mapped by name→registry once (§7) |
| 17 | Rollover copies **pricing** only; classification is school-scoped so it doesn't roll |
| 18 | Yes — one head, many sessions, one identity. That's the point |
| 19 | Per class/section rows in `feeStructures`, each referencing one `feeHeadRef` |
| 20 | `frequency` stays on the pricing row; the registry never carries frequency |
| 21 | Transport/Hostel `createModuleFee` resolves its head via the registry (§15/§16) |
| 22 | Meals — see §17: **not shipped**; token reserved, unused |
| 23 | `createModuleFee` must require a resolved `feeHeadRef`; **an unregistered head fails closed** |
| 24–26 | Bulk, admission and monthly all call **one resolver** — `Fee_head_resolver::resolve(schoolId, ref)` |
| 27–28 | Recalculation/regeneration re-resolve the same `feeHeadRef` and **preserve** the stamped category |
| 29–30 | Scholarship and concession logic read `feeDemand.category` **only** — never a name, never a lookup |

## 5. Classification model

**Vocabulary — closed, lowercase.** The financial question it answers, and the only one:
*does the default scholarship/concession rule apply?*

| Category | Meaning | Default concession | Service? | Live heads today |
| --- | --- | --- | --- | --- |
| `academic` | the school's own instructional charge | **applies** | no | Tuition, Examination, Registration, Library, Admission |
| `transport` | vehicle conveyance | **excluded** (opt-in by explicit scope) | yes | Transport Fee |
| `hostel` | boarding/lodging | **excluded** (opt-in) | yes | none in any chart; `Hostel.php` creates them at runtime |
| `meals` | food service | **excluded** (opt-in) | yes | **none — reserved, see §17** |
| `unknown` | unclassified / unrecognised | **fails closed** | n/a | the 1,190 legacy demands |

**Examination, Registration, Library, Lab, Sports stay `academic`** — for every one of them the answer
to "does the default concession apply?" is yes, so a separate financial category would be a second way
to express one rule and a new way to get it wrong. Their grouping in the UI is `displayGroup`, which
carries no financial meaning. **`extra_curricular` and `other` are therefore NOT financial
categories.**

**One open decision I am flagging rather than inventing.** Tracing `createModuleFee` callers turned up
a **third** module beyond Transport and Hostel: **ID-card replacement fees**
(`Sis.php:3927` → `Id_card_fee_policy`). That charge is neither instructional nor an opted-in service —
it is an administrative/penalty charge, and *"should a scholarship pay to replace a lost ID card?"* is
a real financial question, not a display one. I recommend a fifth category **`administrative`,
excluded by default**, but I have **not** added it to the vocabulary: it needs your decision. If you
decline it, ID-card fees must be explicitly assigned to `academic` — the one thing that must not
happen is their falling through unclassified.

## 6. Session pricing model

Unchanged, and deliberately so:

```
feeStructures/{school}_{session}_{class}_{section}
  .feeHeads[] = { feeHeadRef, amount, frequency, billingType }   ← + feeHeadRef, − name as identity
```

Pricing is session-scoped and class-scoped; identity and classification are school-scoped. The same
head priced ₹500 in Class 2 and ₹600 in Class 3 in 2026-27, and ₹650 in 2027-28, is **one** registry
entry with one classification.

## 7. Existing-data mapping analysis (§3)

| Measure | Value |
| --- | --- |
| `feeStructures` docs | 9 (7 charts + 2 `_titles`) |
| fee-head rows | 36 |
| **distinct names** | **6** |
| **distinct `feeHeadId`s** | **28** |
| rows with no `feeHeadId` | **8** |
| **ids reused across charts/sessions** | **0** |
| ids bound to more than one name | 0 |
| sessions present | 1 (`2026-27`) |
| schools | 2 |

The 6 names, with the classification a name-based *proposal* would suggest:

| Name | rows | proposed | confidence |
| --- | --- | --- | --- |
| Tuition Fee | 9 | `academic` | high |
| Transport Fee | 9 | `transport` | high |
| Examination Fee | 7 | `academic` | high |
| Registration Fee | 7 | `academic` | high |
| Admission Fee | 2 | `academic` | high |
| Library Fee | 2 | `academic` | high |

**No hostel head and no meals head exists in any chart, in either school.**

**Can the mapping be deterministic? No — and it must not be.** The names above are unambiguous, so a
name pass would *propose* correctly for all 6. But §E forbids the name being the authority, and the
rename fragility (deficiency G) is exactly why. **Therefore: the name pass produces a proposal;
the operator confirms it; the registry is authoritative from that instant, and names are never
consulted again at runtime.** Operator confirmation is **required** — for all 6 heads, one time.

That is also why the mapping is cheap: 6 confirmations, not 1,242.

## 8. Legacy-demand grandfathering (§6)

**The 1,190 `category=''` demands are frozen exactly as they are. No backfill, no rewrite, no
net/balance change.**

- Their `category` stays `''`. A read treats `''` as `unknown`.
- **`unknown` fails closed for NEW financial decisions only.** It does not retroactively withdraw a
  concession already applied to a historical demand — those amounts are already committed.
- **The one hazard, stated plainly:** if a legacy demand is ever *recalculated*, the new rules would
  have to classify it. The design refuses to guess: recalculation of a demand whose head cannot be
  resolved to a registry entry **fails closed with an explicit error** rather than silently treating
  it as `academic`. That converts a silent monetary error into a visible, fixable one.
- New demands are distinguishable from legacy by a stamp: `categorySource: 'registry'`. Absent =
  legacy. This is how the two eras coexist without ambiguity.

## 9. New-demand contract

```
Fee_head_resolver::resolve(schoolId, feeHeadRef)  ──► registry entry (or FAIL CLOSED)
        ↓ category
canonical spec  $entryData['category'] = <registry category>     ← Fee_lifecycle:377
        ↓
Fee_generation_service (:147) reads $data['category']            ← ALREADY reads this key
        ↓
Fee_firestore_txn (:895) persists 'category' + 'categorySource'  ← ALREADY persists this key
        ↓
feeDemands.category  (immutable thereafter)
        ↓
Fee_entitlement_reader::appliesTo() / scholarship allocator
```

Guarantees, each mapping to a §7 requirement:

- **populated before commit** — the spec builder refuses to emit a spec without a resolved category;
- **unknown fails closed** — an unresolved head aborts generation with a named error, never defaults;
- **never inferred at read time** — readers consume `feeDemand.category` only; no name, no lookup;
- **immutable for historical demands** — writers merge but never overwrite an existing non-empty
  `category` (this closes the Q22 blanking risk in `Fee_firestore_txn:895`);
- **one resolver** — admission, monthly, bulk and module paths all call it, which is what removes the
  three-writer divergence (deficiency E).

**Note the leverage:** `category` already exists in the spec→txn→demand chain and is already read by
the generator. This design **adds no field to the demand schema and changes no reader** — it only
makes the value authoritative.

## 10–14. Generation flows

| Flow | Today | Under this design |
| --- | --- | --- |
| **Admission** (`Fee_lifecycle::buildAdmissionDemandSpecs`) | emits no `category` → `''` | resolves each chart row's `feeHeadRef` → stamps `category` |
| **Monthly** (`Fees::_generateDemandsForMonth`) | `_resolveFeeCategory()` name heuristic | same resolver; heuristic deleted |
| **Recalculation** (`_recalculateStudentUnpaidDemands`) | re-picks `category` from incoming data, can blank it | preserves the stamped value; never re-derives |
| **Regeneration** | re-resolves by name, can drift on rename | re-resolves by `feeHeadRef` → identical result |
| **Bulk** | inherits the heuristic | same resolver — identical to single-student by construction |

**Generation == recalculation == regeneration** holds because all four consult one resolver keyed on
an immutable id, not a mutable name.

## 15–17. Module integration

**15 · Transport.** `createModuleFee($studentId, 'Transport', …)` currently writes
`category = strtolower($moduleType)` — accidentally correct. It gains a registry-resolved
`feeHeadRef`, so the category comes from the registry rather than from a module-name coincidence.

**16 · Hostel.** Identical, via `Hostel.php:360`. Note **no hostel head exists in any chart** — hostel
demands are created at runtime only, which is precisely why chart-only classification would have
missed them.

**17 · Meals — DETERMINATION: ZenX does NOT bill meals today.** There is **no meals controller, no
meals library, no meals collection, and no meals head in any chart**; `createModuleFee`'s only callers
are Transport, Hostel and ID-card. `meals` appears solely as a string in
`Fee_entitlement_reader::SERVICE_CATEGORIES`. **Recommendation: keep the token reserved in the
vocabulary but ship it unused** — it is already in `SERVICE_CATEGORIES`, so leaving it costs nothing
and removing it would weaken I3 the day meals ships. **It must not be presented in the operator UI
until a meals module exists**, or schools will classify heads into a category nothing consumes.

## 18. Scholarship integration — the §8 guarantee

With the registry authoritative, the eligibility rule becomes a pure function of the stamped category:

```
eligible(demand) = demand.category === 'academic'
                   OR award.scope explicitly names demand.category   (service opt-in)
unknown / ''     → NOT eligible, and generation refused for new demands (fail closed)
```

This delivers, mechanically: default academic scope ✓ · transport excluded ✓ · hostel excluded ✓ ·
meals excluded ✓ (vacuously — none exist) · explicit service scope opts in ✓ · unknown fails closed ✓.
Eligible-gross weighting and the total-eligible-gross cap are then computed over a base that is
correct by construction. **The scholarship engine remains unimplemented and blocked.**

## 19. Migration / cutover plan (design only, not executed)

| Step | Action | Safety |
| --- | --- | --- |
| M1 | Read-only: emit the 6-head mapping proposal | no writes |
| M2 | **Operator confirms** each head's category in a UI | nothing commits without confirmation |
| M3 | Mint registry entries + name claims | new collection only; nothing existing touched |
| M4 | Add `feeHeadRef` to chart rows (additive) | `feeHeadId` left in place, unread |
| M5 | Flag-gated: resolver becomes the category source for **new** demands | flag default **false** |
| M6 | Soak; compare new demands' categories against expectation | no legacy demand touched |
| M7 | Retire `_resolveFeeCategory()` and the `_titles` doc's head-list role | after soak only |

- **Existing students / balances / concessions:** untouched at every step — no step rewrites a demand.
- **During transition:** legacy demands keep `category=''`; new ones carry `categorySource:'registry'`.
- **Session rollover:** copies pricing rows with their `feeHeadRef`; classification doesn't roll.
- **Detecting an unclassified head before financial generation:** a pre-generation check that every
  chart row resolves to an active registry entry — run as a gate, failing loudly.

## 20. Rollback

Every step is reversible because nothing is destructive: **M5 rolls back by flipping the flag**
(new demands revert to prior behaviour); M4's `feeHeadRef` is additive and simply becomes unread; M3's
registry can be deleted wholesale since no other document depends on it until M5; M7 is the only
irreversible step and runs **after** soak. **At no point is a legacy demand modified**, so there is no
financial state to restore.

## 21. Security / data-integrity implications

- **Tenant isolation:** every registry doc id is `{schoolId}_…` and every read is school-filtered —
  the same rule the rest of the codebase follows. A cross-school head read must be impossible.
- **Firestore rules + indexes:** a new collection needs a `match /feeHeadRegistry/{doc}` block with
  `isSameSchoolWrite()`-style guards, and a `schoolId + status` index. **Neither is written here** —
  and the rules baseline has moved twice under us (`f58499f8` → `5b0f86fc`), so any rules work must
  re-pull first.
- **RBAC:** registry writes are a Fees `manage` operation; classification changes should be audited
  (`categorySetBy`/`categorySetAt` exist for this).
- **Integrity:** the name claim doc prevents two heads sharing a name; `status='archived'` instead of
  delete prevents orphaned financial history.
- **Both apps:** Parent and Teacher read `feeDemands.category` only if at all — since the demand
  schema is unchanged, **no app-side change is implied**. This needs confirming against both Kotlin
  repos before implementation, not assumed.

## 22. Test matrix

| # | Area | Test |
| --- | --- | --- |
| T1 | Identity | rename a head → `feeHeadId` unchanged, demands unaffected |
| T2 | Identity | two heads cannot claim one `nameNorm` |
| T3 | Identity | archived head cannot be added to a new chart; historical demands intact |
| T4 | Identity | delete is not offered by any API |
| T5 | Classification | category change is forward-effective; prior demands keep the old stamp |
| T6 | Classification | vocabulary is closed — an unknown token is rejected |
| T7 | Fail-closed | unresolved head → generation aborts, no demand written |
| T8 | Fail-closed | recalculating a legacy `''` demand refuses rather than defaulting to academic |
| T9 | Immutability | a merge write never overwrites a non-empty `category` |
| T10 | Convergence | admission, monthly, bulk produce the **same** category for one head |
| T11 | Convergence | regeneration after a rename produces the identical category |
| T12 | I3 | blanket `scope:'all'` never nets a `transport`/`hostel`/`meals` demand |
| T13 | I3 | explicitly scoped service concession **does** apply |
| T14 | Scholarship | eligible-gross excludes service categories; cap computed on that base |
| T15 | Legacy | no existing demand's `netAmount`/`balance`/`paidAmount` changes at any migration step |
| T16 | Tenant | a head from school A is never resolvable from school B |
| T17 | Rollback | flipping M5's flag off restores prior generation behaviour exactly |
| T18 | Module | Transport/Hostel/ID-card fees all resolve a registry head or fail closed |

---

## State

**Read-only design. Nothing implemented.** No Fee Head registry, no `feeCategories` change, no
`feeDemands` change, no `Fee_lifecycle` / `Fee_generation_service` / `Fee_firestore_txn` edit, no
scholarship code, no migration, no backfill.

**Two decisions I need before this is implementable:** (1) the **`administrative`** category for
ID-card replacement fees — or an explicit ruling that they are `academic`; (2) confirmation that
**meals stays reserved-but-unused**.

SRNO-1 UAT still awaits your authenticated UI session — no bypass, no scripted admission, no further
reset, no manual allocation. `USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE` ·
no B3 · no N+1 · no SRNO-F2/F3 · rules/indexes untouched (`5b0f86fc`, concurrent Staff-Lifecycle/HRMS
work untouched) · no RTDB · no commit, push or deploy.
