# CANONICAL FEE-HEAD IDENTITY — DECISION-QUALITY ARCHITECTURE REVIEW

**READ-ONLY. Nothing implemented, added, migrated or changed.** 2026-08-17
**[F]** verified fact (code/data) · **[O]** observed current behaviour · **[I]** architectural inference ·
**[R]** recommendation · **[?]** unresolved question

---

## 1. Current-state identity model — we have **four** overlapping identity carriers

**[F]** Traced live:

| Carrier | Where | Scope | Populated? |
| --- | --- | --- | --- |
| chart-row `feeHeadId` | `feeStructures.feeHeads[]` | **per chart row** | yes — 28 ids for 6 heads |
| `canonicalFeeHeadId` | `feeDemands` | **per chart row** (copied) | 1,092 of 1,242 |
| `feeHeads` **collection** | school-level | school | **0 documents** |
| `feeHeadRegistry` | school-level | **school** | 12 entries + 12 claims |

**[I] Yes — we are accidentally carrying multiple overlapping identity systems.** That is the real
finding. The question is which one survives, not which one to add.

## 2. What `canonicalFeeHeadId` actually means today

**[F]** Created in `Fee_firestore_txn::_buildDemandDoc()`: caller-supplied wins, else
`canonicalHeadIdMap()[strtolower(feeHead)]`. **[F]** `canonicalHeadIdMap()` queries the `feeHeads`
**collection** by `schoolId` and maps *lowercased name → feeHeadId*. **[F] That collection holds 0
documents**, so the map is always empty and **the canonical resolution has never once fired.**

**[F]** The only real supplier is `Fees::_auto_generate_student_demands`, which passes
`$canonMap[$title] ?? $feeHeadId` — i.e. **the chart row's id**.

**[F] Measured across all 1,242 demands:**

```
Tuition Fee (SCH_218AAF5C23)      → 3 distinct canonicalFeeHeadId
Transport Fee                     → 3 distinct
Examination Fee                   → 3 distinct
Registration Fee                  → 3 distinct
canonicalFeeHeadId === feeHeadId  → 1092 / 1092  (100%)
```

**Answers:** school-scoped? **No — class-scoped.** Session-stable? Untestable (one session), but it is
minted per chart, and charts are per session, so **no [I]**. Rename-stable? **No** — a rename mints a
new chart id (§7). Structure-change-stable? **No.** Authoritative today? **No.** What prevents it? **It
is a copy of a per-chart-row id, and its resolver's source collection was never populated.**

**Can it be fixed without another field?** Only by **rewriting the meaning of 1,092 existing values**,
or by leaving them and letting the field mean two different things depending on era — with nothing
recording which. **[I]** That is the disqualifier: not effort, but **irreversible ambiguity in
financial records**.

## 3. What `feeHeadRef` actually is — challenged, not defended

**[F]** A field on chart rows holding the `feeHeadRegistry` id. 28 rows carry it. **Zero code reads
it** — `Fee_head_resolver` has no call sites.

**Challenge: is it just a second name for the same thing?** **[I] No — and the distinction is
empirical, not aesthetic.** `canonicalFeeHeadId` denotes *"the chart row this demand came from"*;
`feeHeadRef` denotes *"the school-level head this is an instance of"*. Live data shows those are
**different cardinalities**: 3-to-1 versus 1-to-1.

**Could `canonicalFeeHeadId` be that reference instead?** Yes, *going forward only* — and then two eras
of the same field are indistinguishable. **[R] Reject.**

**The honest verdict on necessity:** a **school-scoped identity field on the demand** is necessary.
Whether it is *named* `feeHeadRef` is arbitrary. What is not arbitrary is that it must be **a field
with no legacy values**, so every value in it has exactly one meaning. `feeHeadRef` satisfies that;
`canonicalFeeHeadId` cannot.

**[R]** Retain both with strictly non-overlapping roles: **`feeHeadRef` = live authority**;
**`canonicalFeeHeadId` = frozen legacy provenance, never extended, never repurposed, never migrated.**
That is not two sources of truth — it is one truth plus one archived record.

**[R] The `feeHeads` collection is dead. Do not populate it** — doing so would revive a genuine second
master.

## 4. Fee Head Registry role

**[R]** The model in your brief is correct:

```
School → Fee Head Registry → stable feeHeadId → Fee Structure → Canonical Fee Spec → Fee Demand
```

The registry is the **only** authority for identity + classification. It supports seeded heads,
custom heads, rename without identity change, archival instead of deletion, and module-generated
heads — **[F]** the M3 mint already proved the last one by collapsing chart-Transport and
runtime-Transport into a single identity per school.

## 5. School-custom Fee Head — what is stored

```
feeHeadRegistry/{schoolId}_{feeHeadId}
  feeHeadId, schoolId          IDENTITY   immutable
  name, nameNorm               DISPLAY    school's own words; nameNorm enforces uniqueness
  category                     CLASSIFICATION from ZenX's controlled vocabulary
  categorySetAt/By             provenance for a financial decision
  status                       active | archived — never deleted
  displayGroup, sortOrder      presentation only, no financial meaning
```

"Smart Classroom Fee" is a normal registry entry. Nothing else is required — **[F]** no ZenX code reads
a `refundable`, `proratable`, `chargeModel` or `optional` flag, so adding them would encode rules
nothing enforces.

**[?] UNRESOLVED — and you were right to flag it.** The current vocabulary is
`academic | transport | hostel | meals | administrative | unknown`, where `academic` means *"the default
scholarship applies"*. That makes `academic` a **default-eligibility bucket**, not a statement that the
charge is instructional. Under it, "Smart Classroom Fee" and "Activity Fee" become `academic` and are
therefore scholarship-eligible by default. **That may not be what schools intend**, and I will not
invent the rule. The open question: **should there be a non-service, non-administrative,
*non-default-eligible* class** (e.g. `optional_service` / `development`)? This needs your business
decision **before** custom heads ship — it does not block enrollment or Phase C.

## 6. Seeded vs custom

**[R] Option C — a seeded catalogue that mints an ordinary school-scoped Fee Head when selected.**

Seeded entries are a **template list ZenX ships**, not entities schools point at. Selecting one mints a
normal registry entry the school then owns, renames and archives like any other. **[I]** This is the
only option where seeded names carry no more authority than custom ones — A cannot express "ZenX
suggests this", and B creates two identity models for one concept.

## 7. Rename analysis — **rename can be made safe; it is not inherently unsafe**

You instructed me not to fall back on "block rename" unless the architecture proves rename cannot be
supported. **It does not prove that. Every name dependency is convertible to the stable identity.**

**[F]** Every place the NAME participates today:

| # | Site | Role of the name | Convertible to stable id? |
| --- | --- | --- | --- |
| 1 | `Fee_firestore_sync` `$existingIds[$name] ?? mint()` | **mints a new chart `feeHeadId` on rename** | **Yes** — key preservation on `feeHeadRef` |
| 2 | `Fee_lifecycle::createModuleFee` → `_demandId(…, '', name.'_'.module)` | module demand id | **Yes** — registry-pinned `demandKey`, ids stay byte-identical |
| 3 | `canonicalHeadIdMap()` name→id | dedupe key for auto-generate | **Yes** — resolve from `feeHeadRef` |
| 4 | `Fee_concession_reader` / `Fee_entitlement_reader` `head:` scope, id **else name** | concession targeting | **Yes** — store scopes as `head:<feeHeadRef>` |
| 5 | `Operations_accounting` name keyword → GL | account mapping | **Yes** — mapping on the registry |
| 6 | `proRateFees` `category ?? feeHead` | selection | **Yes** — via `category` (M5) |

**[I] Conclusion: rename is architecturally supportable. It is unsafe *today* only because these six
still read the name.** So the correct policy is not "blocked forever" but:

**[R] Rename is gated, not forbidden — and the gate is a finite, enumerable list (1–6 above).** Until
they land, rename must be prevented (including the delete-and-re-add vector, **[F]** the only rename
path that exists today, since `Fee_management` offers `add_fee_title`/`delete_fee_title` and no rename
endpoint). Once they land, rename is safe by construction and needs no warning.

**[F] Ordering note:** #1 is the most urgent and is not really about rename —
`Fee_firestore_sync::syncFeeStructure()` rebuilds each row with five keys, **contains `feeHeadRef` zero
times**, and writes with `firestoreSet($docId, $doc)` **without a merge flag**. **The next chart save
erases all 28 `feeHeadRef` values.** The authority is currently non-durable.

## 8–9. Demand identity and `chargeInstance`

**[R]** Three separate questions, three separate carriers:

```
feeHeadRef      WHAT type of charge      (registry identity)
chargeInstance  WHICH occurrence          (only where a head can legitimately repeat)
demandId        WHICH demand record       (deterministic, from immutable components)
```

**[F]** ID-card demonstrates the general case: head `ID Card Replacement`, instance `V{cardVersion}`
(atomically claimed and monotonic in `Id_card_service::issue()`), demand id deterministic from
student + period + key + instance. **[I]** It generalises to Trip (`TRIP-2026-001`) and Competition —
same mechanism, no new concept. Transport, Hostel and ordinary chart fees take **no** instance;
**[F]** late fees are not a head at all (`fine_amount` is updated on the existing demand).

**[R] No generic Charge Instance collection.** Nothing in the code or the requirement needs one, and
**[F]** neither ERPNext nor OpenEduCat has one.

## 10–11. What a Fee Demand should retain

**[R]** Identity **`feeHeadRef`**; a **name snapshot** (`feeHead`, already present — historical
statements must render what was charged, not today's label); a **`category` snapshot**, immutable
(scholarship must never be re-derived later); `chargeInstance` where applicable; `demandId`.
`canonicalFeeHeadId` and chart `feeHeadId` persist untouched as legacy provenance.

**[I]** Snapshotting name and category is what makes rename harmless *for history*: the demand records
what was true when it was raised.

## 12. Scholarship

**[F]** Eligibility branches on `category`; a school picks from `ASSIGNABLE`, never invents. **[F]** The
readers' `head:` scope name fallback (#4) is a live financial hazard on rename — a head-scoped
concession silently unbinds and the family pays more. **[F] Not a Phase C blocker**, because Phase C
keys on `category`, not head scopes. **The Phase C blocker is M5** — **[F]** admission demands are still
written with `category=''`, so the eligibility rule and invariant I3 are inert.

## 13. Accounting

**[F]** `Operations_accounting` maps head → GL by **name keyword**; a custom head such as "Smart
Classroom Fee" matches nothing and falls through to the default account. **[R]** Mapping belongs on the
registry (explicit account per head), with the Fee Structure able to override — **[F]** the same shape
ERPNext uses (default income account on the Fee Category). **Blocker for custom heads; not for
enrollment or Phase C**, since no custom head exists yet.

## 14. Cross-platform — no app change required

**[F]** Parent `FeeDemandDoc`: `demandId` is an opaque `String`; unknown fields ignored;
`TransportFeesRepository` is category-first with a name fallback → custom heads render normally and an
unknown category **fails safe**. **[F]** Teacher: no fee-head, category, demand-id-format or
`chargeInstance` coupling. **[F]** Admin: no rename endpoint; the chart save is the identity-mutating
path.

## 15. Architecture comparison

| | **A** `canonicalFeeHeadId` sole authority | **B** `feeHeadRef` sole authority | **C** both, separated roles | **D** revive the `feeHeads` collection |
| --- | --- | --- | --- | --- |
| Source of truth | one field, **two meanings** | one field, one meaning | **live = ref; legacy = canonical** | second master |
| School scope | only after rewrite | yes | **yes** | yes |
| Session scope | none | none | **none** | none |
| Rename | fixable | fixable | **fixable** | fixable |
| Custom / seeded heads | yes | yes | **yes** | no classification field |
| Migration | **rewrite 1,092 financial records** | none | **none** | none |
| Duplicate-identity risk | **high** (era ambiguity) | low | **low** | **high** (3 masters) |
| Name-drift risk | same | same | same | same |
| Complexity | medium | low | **low** | medium |
| Maintainability | poor — undocumentable field | good | **good, if `canonicalFeeHeadId` is explicitly frozen** | poor |
| Existing work | discarded | mostly kept | **kept** | discarded |

**B and C are the same live architecture**; they differ only in whether the legacy field is formally
declared frozen. **[R] C — B plus an explicit deprecation contract**, because silence about
`canonicalFeeHeadId` is how a future session repurposes it.

## 16–17. Recommended model and why

**[R] Architecture C.**

```
feeHeadRegistry (seeded + custom)  →  feeHeadRef  →  Fee Structure (session pricing)
        │                                                    │
        └── category (controlled)                            ▼
                                              Canonical Fee Spec → Fee Demand
                                                 feeHeadRef · category snapshot ·
                                                 name snapshot · chargeInstance · demandId
                                                 [legacy: canonicalFeeHeadId, feeHeadId — frozen]
```

**The reason, in one line:** the live data shows `canonicalFeeHeadId` is a **per-chart-row** value with
1,092 existing instances, so making it authoritative requires either rewriting financial records or
accepting a field whose meaning cannot be determined from the record itself — and neither is
acceptable for money.

## 18–20. Keep / change / defer

**Keep:** the registry (24 docs), `feeHeadRef` on 28 chart rows, `Fee_head_resolver` (unwired),
generic `chargeInstance`, the ID-card head+instance correction, the controlled vocabulary.

**Change:** make `feeHeadRef` durable in `Fee_firestore_sync` (§7 #1) — **urgent, the authority is
being erased**. Declare `canonicalFeeHeadId` frozen in code comments and tests. Remove the dead
`Fees::_buildDemandId()` when convenient.

**Revert:** nothing. No item reviewed here is wrong; the gap is that `feeHeadRef` is not yet durable or
read.

**Defer:** `feeHeadRef` on demands, `demandKey`, `canonicalHeadIdMap` by ref, concession scopes by ref,
accounting mapping, rename UI, the `[?]` classification-granularity decision.

## 21. Minimal sequence (not authorised)

1. `Fee_firestore_sync` preserves `feeHeadRef` — no migration, no rules, no flag
2. Keep rename gated (delete-and-re-add included)
3. **M5** classification stamping + registry rules/index under reconcile-first → **unblocks Phase C**
4. Then, together, the rename-gate list §7 #1–#6 → unblocks rename **and** custom heads
5. Accounting mapping · then the `[?]` vocabulary decision

## 22. Risks and invariants

Invariants: Fee Head identity school-scoped and session-independent; session belongs to pricing;
`category` controlled and fail-closed; legacy `category=''` never backfilled; existing demand ids
byte-identical; `feeHeadRef` never enters `_demandId()`; financial history never rewritten; archived
heads stay resolvable; Firestore-only, no RTDB.

Risks: `feeHeadRef` erasure (immediate); rename before §7 lands (duplicates + unbound concessions);
custom heads before accounting mapping (mis-posted revenue); the `[?]` vocabulary question shipping
undecided.

## 23. Verdict

**GO on Architecture C**, with the scope limited to §21.
**NO-GO on custom Fee Heads and rename** until §7 #1–#6 and the `[?]` classification decision land.
**Enrollment may resume after step 1. Scholarship Phase C needs step 3.**

---

Nothing implemented. No production code, live data, registry document, `feeDemands`, `feeStructures`,
rules, index, field or flag changed; no migration; no commit, push, deploy, merge or rebase.
`USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE`.

**Sources:** [ERPNext Fee Category](https://docs.frappe.io/erpnext/v13/user/manual/en/education/fee-category) ·
[ERPNext Fee Structure](https://docs.frappe.io/education/fee-structure) ·
[OpenEduCat Fees Management](https://doc.openeducat.org/applications/openeducat/fees-openeducat.html)
