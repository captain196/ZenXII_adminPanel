# FEE HEAD + FEE STRUCTURE + BILLING — FINAL RECONCILIATION

**READ-ONLY. Nothing implemented.** 2026-08-17
**[F]** verified from code/data · **[O]** observed behaviour · **[I]** inference · **[R]** recommendation · **[?]** business decision needed

---

## 0. ⚠ TWO CONTRADICTIONS WITH THE STATED MODEL — reporting, not redesigning

Your brief states the intended billing model as:

```
Fee Structure:  annual value = ₹48,000,  frequency = monthly
Generated:      April ₹4,000, May ₹4,000, June ₹4,000 …
```

**[F] ZenX does not work that way, and cannot today.**

**Contradiction 1 — there is no "annual value ÷ frequency" anywhere.** The fee chart is a
**month-keyed map**: `$chart[$monthOrYearly][$headName] = $amount` **[F** `Fee_lifecycle:366-380`**]**.
The operator enters an **explicit per-month amount for each month**, and generation emits one demand
per chart-month entry:

```php
foreach ($chart as $monthOrYearly => $heads) {
    foreach ($heads as $headName => $amount) {
        $isYearly     = ($monthOrYearly === 'Yearly Fees');
        $targetMonths = $isYearly ? ['April'] : [$monthOrYearly];
```

**[F]** Live data agrees: chart rows carry `{"name":"Tuition Fee","amount":600,"frequency":"monthly"}` —
600 **is** the monthly charge, not 7,200 ÷ 12. No `/ 12` or `* 12` exists in the sync or generator.

**Contradiction 2 — quarterly billing does not exist.** **[F]** `Fee_lifecycle::FREQUENCY_MAP`
(`Monthly=>12, Quarterly=>4, Annual=>1, One-time=>1`) is **declared and never referenced anywhere** —
dead configuration. **[F]** The operator UI accepts only `['Monthly','Yearly']`
(`Fee_management::add_fee_title`). **[F]** Generation branches on a **boolean** `$isYearly`, not on an
n-way frequency. **[F]** Live `frequency` values are only `monthly` and `annual`.

**[I] The real model is binary — a head sits either in month buckets or in the `Yearly Fees` bucket —
not a head with a configurable frequency.** Supporting the model in your brief (annual value +
frequency → derived instalments, including quarterly) is a **billing-engine feature that does not
exist**, not a Fee-Head-identity concern. I am reporting it rather than designing it.

**This does not block Student Enlightenment** — see §13.

## 1. Fee-head identity map

| Carrier **[F]** | Scope | Cardinality (live) | Producer | Readers | Rename-stable | Rollover-stable | Class-stable | Authoritative? |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| chart-row `feeHeadId` | chart row | **28 for 6 heads** | `Fee_firestore_sync` (mints on name miss) | `_demandId`, `readFeeHeadIds` | **No** | No | **No** | No |
| `canonicalFeeHeadId` | demand | **3 per head** | copied from chart id | dedupe in auto-generate | No | No | **No** | **No** |
| `feeHeads` collection | school | **0 docs** | nothing | `canonicalHeadIdMap()` | — | — | — | **Dead** |
| `_titles` doc | school+**session** | 2 docs | `add/delete_fee_title` | title lists | No (delete+add) | **No — session-keyed** | Yes | No |
| **`feeHeadRegistry`** | **school** | **12 entries + 12 claims** | M3 mint | **none yet** | **Yes** | **Yes** | **Yes** | **Yes** |
| `feeHeadRef` | chart row | 28 rows → 12 ids | M4 | **none yet** | **Yes** | **Yes** | **Yes** | **Yes (pointer)** |

**[F] Proof that `feeHeadRef` can be the one authority:** it is the only carrier that is 1-to-1 with a
school-level head (28 rows → 12 ids, correctly collapsing per-class duplicates), immutable, and free of
session or class components. **[F] Proof `canonicalFeeHeadId` cannot:** 3 distinct values per head and
1,092 existing values under the old meaning.

## 2. Custom-head requirement — what works and what blocks

| Requirement | Supported? | Blocker **[F]** |
| --- | --- | --- |
| 1 select a seeded head | **Yes** (mechanism proven by the M3 mint) | needs a UI |
| 2 create a custom head | **Yes** structurally | needs a UI |
| 3 rename safely | **No, yet** | six name dependencies (§6) |
| 4 archive safely | **Yes** — `status='archived'`, resolvable for history | — |
| 5 use in Fee Structures | **Partly** | **`feeHeadRef` is erased on the next chart save** (§4) |
| 6 generate demands | **Yes** | classification not stamped until M5 |
| 7 use in Scholarship | **Yes**, via `category` | M5 |
| 8 map to Accounting | **No** | name-keyword mapping; a custom head falls through to the default account |

## 3. Billing behaviour — verified

**[F]** Monthly: one demand per chart-month entry, amount as entered. **[F]** Annual: heads in the
`Yearly Fees` bucket produce **one** demand stamped to April (`$targetMonths = ['April']`).
**[F]** One-time: not a frequency — module charges (`createModuleFee`) write a single demand at the
session's April `periodKey`. **[F] Quarterly: unsupported** (§0).

**Q4 — can different heads have different frequencies in one session? [F] Yes.** Live: Tuition and
Transport are `monthly`; Examination and Registration are `annual`, in the same chart. That works
because frequency is a property of **which bucket the head occupies in the structure**, not of the head.

**Q3 — can the same head be monthly *or* annual purely by structure? [F] Yes for monthly↔annual**
(move it between buckets; the head identity is untouched). **No for quarterly.**

## 4. Fee Head vs Fee Structure — one live violation

**[R]** The separation you describe is correct and is what the registry implements. **[F] One violation
exists today:** `Fee_firestore_sync::syncFeeStructure()` **mints identity inside the structure save** —
`'feeHeadId' => $regId[$name] ?? $existingIds[$name] ?? $mintFeeHeadId()`. A *pricing* save creates
*identity*, keyed by name. That is the root of the rename problem.

**[F] And it is actively destructive today:** that function rebuilds each row with exactly five keys,
**contains `feeHeadRef` zero times**, and writes with `firestoreSet($docId, $doc)` **with no merge
flag**. **The next fee-chart save erases all 28 `feeHeadRef` values.** The authority is non-durable.

## 5. Demand identity — verified against your seven tests

| Test | Result **[F]** |
| --- | --- |
| 1 different periods → different demands | **Pass** — `periodKey` is in the id |
| 2 monthly doesn't overwrite another month | **Pass** |
| 3 quarterly doesn't overwrite | **N/A — quarterly unsupported** |
| 4 annual vs monthly collision | **Pass** — different head keys; but note an annual head lands on April, the same `periodKey` as April monthly demands, separated only by the head key |
| 5 one-time + recurring coexist | **Pass** — different head keys |
| 6 two ID-card replacements in one month | **Pass** — `chargeInstance` V2/V3 |
| 7 Transport/Hostel byte-compatible | **Pass** — unchanged |

**[R] `feeHeadRef` must NOT enter `demandId`.** The existing key component (chart `feeHeadId`, or the
head name for module fees) must remain **because 1,242 live demands already encode it**; changing the
formula re-identifies future demands against unchanged historical ones and duplicates charges. Identity
of the *head* and identity of the *demand record* are different questions.

## 6. Rename — safe, once six conversions land

**[F]** Every name dependency, and its fix:

| # | Site | Fix |
| --- | --- | --- |
| 1 | `Fee_firestore_sync` mints on name miss | preserve by `feeHeadRef` |
| 2 | `createModuleFee` → name in `_demandId` | registry-pinned `demandKey` (ids stay byte-identical) |
| 3 | `canonicalHeadIdMap()` name→id | resolve from `feeHeadRef` |
| 4 | concession/entitlement `head:` scope, id **else name** | store scopes as `head:<feeHeadRef>` |
| 5 | `Operations_accounting` name keyword → GL | mapping on the registry |
| 6 | `proRateFees` `category ?? feeHead` | via `category` (M5) |

**[R] Rename is architecturally supportable — it is gated on this finite list, not forbidden.** Until
they land it must be prevented, including the **delete-and-re-add** vector (**[F]** the only rename
path that exists: `Fee_management` has `add_fee_title`/`delete_fee_title` and no rename endpoint).

**Rename effects once fixed:** existing structures and demands unchanged (they carry snapshots and
their own ids); future structures keep the same `feeHeadRef`; concession scopes keep matching;
accounting keeps mapping; reporting groups by identity.

## 7. Classification — the conflation is real

**[F]** `category` lives on the registry entry, is stamped onto the demand at generation (M5, not yet
live), is immutable thereafter, and drives eligibility plus invariant I3.

**[F] The conflation you suspected is real.** `academic` currently means **"the default scholarship
applies"** — an eligibility bucket — while its *name* suggests "an educational charge". Under it, a
custom "Smart Classroom Fee" or "Activity Fee" becomes scholarship-eligible **by default**.

**[?] BUSINESS DECISION REQUIRED — I will not invent it.** Does ZenX need a
**non-service, non-administrative, non-default-eligible** class (e.g. `optional_academic` /
`development`) for custom school charges? Needed **before custom heads ship**; **does not block**
enrollment or Phase C.

## 8. Scholarship / Student Enlightenment

**[F]** Eligibility keys on `category` only, so it is **frequency-agnostic**: a monthly Tuition demand
and an annual Examination demand are each evaluated on their own stamped category. Your seven scenarios
all resolve correctly under the model — **provided `category` is populated**, which **[F]** it is not:
admission demands still carry `category=''`, so the rule and I3 are inert.

Whole-session fixed awards and per-period percentage awards both operate over the generated spec set,
which already spans mixed frequencies. **[F] Generation == recalculation** holds because ids are
deterministic. **The single dependency is M5.**

## 9. Accounting

**[F]** `Operations_accounting` maps head → GL by **name keyword** (`'transport'`, `'hostel'`,
`'id card'`…). Seeded heads work by luck of naming; **custom heads match nothing and post to the
default account**; renamed heads may silently change account. **[R]** Mapping should live on the
registry (explicit account per head, Fee Structure able to override) — **[F]** the shape ERPNext uses
(default income account on the Fee Category). **Blocker for custom heads only.**

## 10. Parent / Teacher / Admin

**[F]** Parent reads `demandId` (opaque `String`), `feeHead`, `category`, amounts; ignores unknown
fields; transport detection is category-first with a name fallback → custom heads render, unknown
categories **fail safe**. **[F]** Teacher has no fee-head/category/`demandId`-format/`chargeInstance`
coupling. **[R] No client change is required** for `feeHeadRef`, custom heads, rename or mixed
frequencies.

## 11. Mid-session and rollover

**[F/I]** `FH_001` in 2026-27 and 2027-28 with different amounts/frequency/applicability — supported,
because the registry has no session component. Mid-session new head, new student, and structure change
all work; **[F]** only one session exists live, so rollover is test-provable, not yet observed.
**[F]** The `_titles` doc **is** session-keyed (`{school}_{session}_titles`), which is why it must not
become the head catalogue — the registry supersedes it.

## 12. Canonical model

```
SCHOOL
  ↓  owns
FEE HEAD REGISTRY      OWNS: identity (feeHeadId), name, category, status, (future) GL mapping
  ↓  feeHeadRef        NEVER owns: amount, frequency, session, class, section
FEE STRUCTURE          OWNS: session, class/section applicability, amount, frequency/bucket
  ↓                    NEVER owns: identity minting, classification
CANONICAL FEE SPEC     OWNS: resolved feeHeadRef + category + amount + period
  ↓
FEE DEMAND             OWNS: student, periodKey, amount, dueDate, payment state,
                             feeHeadRef, name snapshot, category snapshot, chargeInstance, demandId
                       NEVER owns: re-derived classification, recomputed identity
                       LEGACY, frozen: canonicalFeeHeadId, chart feeHeadId
  ↓
PAYMENT                OWNS: receipts, allocations, accounting postings
```

## 13. Decision table

| Item | Current **[F]** | Recommended | Migration | Risk | Blocking? |
| --- | --- | --- | --- | --- | --- |
| `canonicalFeeHeadId` | 3 per head; 1,092 values | **frozen legacy provenance** | **No** | low | No |
| `feeHeadRef` | 28 rows, **erased on chart save** | **the authority; make durable** | No | **high if unfixed** | **YES** |
| chart `feeHeadId` | minted by name | keep as legacy demand key | No | med | No |
| `feeHeadRegistry` | 12+12, unread | the authority | No | low | No |
| `feeHeads` collection | **0 docs** | **dead — do not populate** | No | high if revived | No |
| `_titles` | session-keyed | retire after registry UI | No | low | No |
| Fee Structure | pricing + **mints identity** | pricing only | No | med | No |
| Fee Demand | no `feeHeadRef` | add ref + snapshots (new demands) | No | low | No |
| monthly / annual | works | keep | No | low | No |
| **quarterly** | **unsupported; dead FREQUENCY_MAP** | **billing-engine feature — separate phase** | No | low | No |
| annual-÷-frequency | **does not exist** | **[?] confirm intent** | No | med | No |
| custom heads | structurally ready | after §6 + §7 `[?]` + §9 | No | med | No |
| seeded heads | mechanism proven | catalogue → mints a normal head | No | low | No |
| rename | unsafe (6 sites) | **gated, then enabled** | No | high | No |
| `category` | not stamped | **M5** | No | med | **YES for Phase C** |
| Scholarship | inert | after M5 | No | med | **YES for Phase C** |
| Accounting | name keywords | registry mapping | No | med | custom heads only |
| module charges | one registry | keep | No | low | No |
| `chargeInstance` | generic, optional | keep | No | low | No |

## 14. Sequencing

**MUST FIX BEFORE STUDENT ENLIGHTENMENT**
1. Make `feeHeadRef` durable in `Fee_firestore_sync` (~2 lines; no migration, rules or flag).
2. Keep rename gated (delete-and-re-add included).
3. **M5** — stamp `category` from the registry, + registry rules/index under reconcile-first.

**AFTER STUDENT ENLIGHTENMENT**
4. `feeHeadRef` + snapshots onto new demands · `demandKey` · `canonicalHeadIdMap` by ref · concession
   scopes by ref → then enable rename and ship custom heads.
5. Accounting mapping on the registry.
6. **[?]** classification-granularity decision.

**OPTIONAL FUTURE**
7. Quarterly / annual-÷-frequency billing engine (§0) · remove dead `FREQUENCY_MAP` and
   `Fees::_buildDemandId()` · unify the two demand-id strategies.

---

## The ten answers

1. **Schools can define their own heads?** Structurally yes; safely **after** step 1 + §6 + §9.
2. **Seeded + custom?** Yes — seeded is a catalogue that mints an ordinary school-owned head.
3. **Same head monthly/quarterly/annual by structure?** **Monthly↔annual yes. Quarterly NO — unsupported.**
4. **Different heads, different frequencies, same session?** **Yes — verified live.**
5. **Must `feeHeadRef` remain the authority?** **Yes** — it is the only 1-to-1, rename-stable, session-free carrier.
6. **What becomes of `canonicalFeeHeadId`?** **Frozen legacy provenance** — never extended, repurposed or migrated.
7. **Head / Structure / Demand relationship?** §12.
8. **Can rename be made safe?** **Yes** — gated on six conversions, not inherently unsafe.
9. **Does Scholarship work with this model?** **Yes, frequency-agnostically — once M5 stamps `category`.**
10. **What must be fixed first?** **`feeHeadRef` durability, the rename gate, and M5.** Nothing else.

Nothing implemented. No PHP, Kotlin, Firestore data, migration, registry record, rules, index or flag
changed; no commit, push or deploy. `USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE`.
