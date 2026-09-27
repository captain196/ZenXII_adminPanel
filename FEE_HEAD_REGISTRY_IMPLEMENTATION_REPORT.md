# FEE HEAD REGISTRY — CONTROLLED IMPLEMENTATION REPORT

**A: resolver + vocabulary IMPLEMENTED and tested (unwired). C: M3 registry MINTED and verified.**
**B: ID-card demand-identity change NOT implemented — §5 stop condition fired.**
2026-08-17

---

## A. Resolver and constants — implemented, unwired, tested

**New file:** `application/libraries/Fee_head_resolver.php` (production library, **zero call sites** —
inert until the cutover step).

Closed vocabulary as approved: `academic · transport · hostel · meals · administrative · unknown`.
`ASSIGNABLE` deliberately excludes `unknown` (a read-time interpretation of legacy `''`, never a
choice) and `meals` (reserved, hidden — no module exists). `SERVICE_CATEGORIES` stays
`transport/hostel/meals`, so `administrative` is excluded-by-default **without** being a service.

The five hard rules are enforced in code, not documentation: school-scoped identity with **no
parameter through which a session could enter**; never classify by name; fail closed on
missing/unknown/archived/invalid/read-failure; never coerce an unrecognised category; and the resolver
returns **classification only** — it exposes no demand-identity material at all.

**Tests:** `tests/Unit/FeeHeadResolverTest.php` — **23 tests, 74 assertions, all pass.** Covers T1, T2,
T6, T9, T10, T11, T12, T13, T14, T23, T25, T36, plus every failure path, request-scoped memoisation
(3 resolves → 1 read), and cross-school memo isolation.

One test I wrote badly and fixed rather than papered over: my first "no session component" assertion
counted underscores in a doc id — arithmetic that failed for the wrong reason. Replaced with the
actual invariant: `docId()` and `resolve()` take exactly two parameters (reflection-checked), so there
is nowhere a session *could* go, and one head resolves identically across sessions.

## B. ID-card demand identity — **NOT IMPLEMENTED. §5 STOP.**

Your §5 required verifying the demand data contract *before* editing, and stopping if it differs from
my report. **It differs. I stopped.**

**What I claimed:** "the version is stored on the demand (`chargeInstance`)".

**What is actually true:** `Fee_firestore_txn::writeDemand()` does not merge caller data — it
constructs `$doc` from a **strict field whitelist**:

```php
$doc = [ 'schoolId', 'session', 'demandId', 'studentId', 'studentName', 'className', 'section',
         'sectionKey', 'feeHead', 'feeHeadId', 'category', 'frequency', 'period', 'month',
         'periodKey', 'periodType', 'isYearly', 'billingType', 'grossAmount', 'discountAmount',
         'fineAmount', 'netAmount', 'dueDate', 'createdAt', 'createdBy', 'updatedAt' ];
```

There is no `chargeInstance` field, and **any field not on that list is silently dropped** — it never
reaches Firestore. So the design as written would have produced a demand whose stored data cannot
regenerate its own id, and the omission would have been invisible: no error, no log, just a missing
field discovered later by a recalculation that could not resolve the instance.

**Can an existing canonical field carry it (§5)?** No, and I checked rather than assumed.
`feeHeadId` is the only unused-by-module-fees slot, but it is head **identity** — the registry
`feeHeadRef` is destined for it, and overloading it with a charge instance would collapse exactly the
identity/instance separation your §2 makes non-negotiable.

**So this needs a decision:** adding `chargeInstance` to the canonical demand whitelist is a **schema
change to the shared demand writer**, affecting every generator, not just ID-card. That is beyond
"modify only the minimum required surfaces" (§3) as I described it, so I did not do it.

**Compatibility already verified**, so the decision is narrow:

- **Parent app:** `FeeDemandDoc` is a Kotlin `data class` with defaults; Firestore `toObject()`
  ignores unknown fields. An additive field is safe. Its only `category` consumer
  (`TransportFeesRepository.kt:64`) is category-first with a name fallback — unaffected.
- **Teacher app:** no fee-category or instance coupling.
- **Reconciliation:** nothing reads a per-demand instance today, because none exists.

**Nothing was edited:** `_demandId()`, `createModuleFee()`, `Id_card_fee_policy` and
`Fee_firestore_txn` are byte-unchanged. T39–T44 are **not** written — they would encode the
unresolved contract.

**The §2 invariant is meanwhile intact by inaction:** no module call passes `feeHeadRef` into the
demand key, so Transport and Hostel demand ids are byte-identical to before (nothing touched them).

## C. M3 registry minting — executed and independently verified

Pre-write, all 8 §12 confirmations passed: registry empty (0 docs), no unexpected records, no
conflicting claims, live school set `{SCH_218AAF5C23, SCH_B56BB9A401}` matching the mapping exactly,
12 entries, 12 unique claims, zero identity collisions — and the exact 24 documents were printed
before any write.

**Result: 12 registry entries + 12 name claims created. 0 skipped. 0 failures.**

`feeHeadId` is derived deterministically at mint time (`FH_` + sha1(school|nameNorm)[0..12]) purely so
a re-run is idempotent; once stored it is immutable and a rename never re-derives it. Both writes use
`createDocument()` (create-if-not-exists), and the claim is taken **first** — if a name were already
held by a different identity the script halts rather than minting a second one.

## D. Exact live documents created (24)

| School | Name | Category | `feeHeadId` |
| --- | --- | --- | --- |
| SCH_218AAF5C23 | Tuition Fee | `academic` | `FH_69F749D2E973` |
| SCH_218AAF5C23 | Examination Fee | `academic` | `FH_EA952DF24FF5` |
| SCH_218AAF5C23 | Registration Fee | `academic` | `FH_65B1767A8813` |
| SCH_218AAF5C23 | Transport Fee | `transport` | `FH_260B9A27E328` |
| SCH_218AAF5C23 | Hostel Fee | `hostel` | `FH_18B87D217EBC` |
| SCH_218AAF5C23 | ID Card Replacement | `administrative` | `FH_D0E229CCD551` |
| SCH_B56BB9A401 | Tuition Fee | `academic` | `FH_DF3C200439E2` |
| SCH_B56BB9A401 | Admission Fee | `academic` | `FH_1DDD704466C5` |
| SCH_B56BB9A401 | Library Fee | `academic` | `FH_088BB5D74C9F` |
| SCH_B56BB9A401 | Transport Fee | `transport` | `FH_E038A430DF0D` |
| SCH_B56BB9A401 | Hostel Fee | `hostel` | `FH_180EDF7FC11D` |
| SCH_B56BB9A401 | ID Card Replacement | `administrative` | `FH_2102C0AF6BE4` |

Plus 12 claims `feeHeadRegistry/{school}_name_{nameNorm}`, each pointing at its entry.

**Independent post-verification** (`scripts/m3_verify_fee_head_registry.php`, separate process — the
R5 lesson that a tool's own report is not evidence): **every check PASS.** 24 docs · 12 entries + 12
claims · all 12 school|name→category pairs match · **no `session` field on any entry** · all `active` ·
every category assignable · doc id `== {schoolId}_{feeHeadId}` · **no meals head** · exactly 2
administrative · every claim resolves to a real entry · **one identity per school+name** (Transport
not duplicated) · the live resolver returns the right category for 3 probes · **school B cannot
resolve school A's identity** (`not_found`).

## E. Documents NOT touched

`feeDemands` **1,242 platform-wide** (UAT 26, other tenant 1,118) · `feeStructures` **9** ·
`students` **150** · `srNoIndex` **0** · `feeCategories` 0 · no scholarship, entitlement, receipt or
accounting document. **No legacy `category=''` demand was backfilled, reclassified or rebalanced.**
No `feeHeadRef` was added to any chart row (that is M4).

## F. Cross-system compatibility

Unchanged and re-confirmed: Parent app tolerant of additive fields and category-first with name
fallback; Teacher app uncoupled. **No app change made or required.** Nothing shipped to either repo.

## G. Unexpected findings

1. **The `writeDemand()` whitelist (§B)** — the one that stopped the ID-card work. Worth flagging
   beyond this phase: *any* future field added to a demand payload without touching that whitelist is
   silently discarded.
2. **Registry doc ids contain spaces** (`…_name_tuition fee`). Valid in Firestore and consistent with
   existing ids like `SCH_…_Class 9th_Section A`, so I left it — but if you prefer underscores in
   claim ids, now is the moment, before anything references them.
3. **No Firestore rules exist for `feeHeadRegistry`.** The mint used the admin SDK, which bypasses
   rules. Client access is therefore denied-by-default — the safe state — and nothing reads the
   collection yet. Rules remain correctly sequenced to the later controlled step.

## H. Test count vs baseline

| | Tests | Failures |
| --- | --- | --- |
| Baseline (before this pass) | 1,849 | **10** |
| After | **1,872** | **10** |

**+23 tests, zero new failures.** The 10 are the pre-existing baseline (`TeacherExecuteDeleteCascadeTest`
and the other known ones), untouched by this work.

## I. Rules / index baseline

**No rules or index change made.** `firestore.rules` working-tree digest **unchanged at `5b0f86fc`**;
`firestore.indexes.json` untouched. Concurrent Staff-Lifecycle/HRMS work left exactly as found.

---

## State

Git: **no commit, push, deploy, merge or rebase.** HEAD still `758c924` on `integration/phase2`.

`USE_FEE_HEAD_REGISTRY` **not created** (nothing to gate — the resolver is unwired) ·
`USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE` · no RTDB · no scholarship, B3,
N+1, SRNO-F2/F3 or backfill work · SRNO untouched and still school-scoped, session-independent.

**Rollback:** delete the 24 `feeHeadRegistry` documents. Nothing else depends on them; no financial
record was written.

## Exact next authorisation required

1. **The `chargeInstance` field decision (§B)** — approve adding it to the `writeDemand()` whitelist
   (a shared-writer schema change), or direct a different mechanism. Until then the ID-card identity
   change and T39–T44 stay unimplemented.
2. Then, in sequence: M4 (`feeHeadRef` on chart rows, additive), the rules/index step under the
   reconcile-first procedure, and M5 behind `USE_FEE_HEAD_REGISTRY` defaulting to false.

Stopping here as instructed.
