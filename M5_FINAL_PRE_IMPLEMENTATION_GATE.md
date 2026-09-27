# M5 — FINAL PRE-IMPLEMENTATION GATE

**READ-ONLY. Nothing implemented.** 2026-08-17
**[F]** verified now from code/data · **[I]** inference · **[R]** recommendation · **[?]** business decision

---

## 1. EXECUTIVE VERDICT — **BLOCKED (NO-GO)**

**M5 is not safe to implement today.** Your §8 sets the condition explicitly — *"if the problem still
exists: STOP M5 readiness"* — and **it still exists**. Two further blockers surfaced in this audit.

| # | Blocker | Evidence **[F]** |
| --- | --- | --- |
| **B1** | **Chart save erases `feeHeadRef`** | `Fee_firestore_sync::syncFeeStructure()` rebuilds each row with 5 keys; the file contains `feeHeadRef` **0 times**; writes via `firestoreSet($docId, $doc)` **with no merge flag** = full overwrite |
| **B2** | **`feeHeadRef` has NO production writer or reader** | Repo-wide: `feeHeadRef` appears in application code **only inside `Fee_head_resolver` as a parameter name**. The 28 values were written by a one-off script. **No code path creates or maintains it.** |
| **B3** | **`categorySource` is not implemented** | Repo-wide: **0 occurrences**. It is design language only — there is currently **no way to distinguish a legacy demand from an M5 demand** |

**B2 is the one I under-reported previously and it is the most consequential:** `feeHeadRef` is not a
fragile field, it is an **unmaintained one**. M5 would wire classification to a value nothing keeps
current.

**Unblocking is small** — three contained changes (§15) — and none requires migration, rules, or
touching a demand.

## 2. Current architecture (verified, not assumed)

```
feeHeadRegistry  12 entries + 12 claims   ← authority, but READ BY NOTHING
   ↓ feeHeadRef  28/28 chart rows          ← WRITTEN BY NOTHING (script-only), ERASED on chart save
FEE STRUCTURE    7 charts, session-scoped
   ↓
CANONICAL SPEC   category NOT sourced from the registry
   ↓
FEE DEMAND       1,242 · feeHeadRef on 0 · category='' on 1,190
   ↓
ENTITLEMENT      eligibility keys on category → currently inert
```

## 3. Live-data census **[F]**

| Measure | Value |
| --- | --- |
| registry entries / claims | **12 / 12** · categories `{academic:6, transport:2, hostel:2, administrative:2}` · all `active` |
| entries carrying a session field | **0** ✓ |
| entries carrying `chargeType` | **0** (Billing V2, not M5) |
| charts / rows / rows with `feeHeadRef` | **7 / 28 / 28** ✓ |
| demands total | **1,242** |
| demands with `feeHeadRef` | **0** |
| demands with `category=''` | **1,190** |
| demands with a name snapshot (`feeHead`) | **1,144** (98 have none — the pre-existing anomalous set) |
| category values present | `(empty)` 1,190 · **`Academic` 24 · `Transport` 24 · `General` 4** |

**[F] NEW FINDING — existing category values are out-of-contract.** They are **capitalised**
(`Academic`, `Transport`), and **`General` (×4) is not in the controlled vocabulary at all** — it is
neither valid nor `unknown`. The readers survive by `strtolower()`/`strcasecmp`, so `Academic` and
`Transport` still match, but **`General` matches no rule**. M5 must define how these 52 pre-M5 values
are treated; they are not the same population as the 1,190 empties.

## 4. `feeHeadRef` authority — **structurally correct, operationally absent**

**[F]** 1-to-1 with the school-level head (28 rows → 12 ids), no session component, immutable, school
isolated. It **is** the right authority. **[F]** But: written only by a script, read by nothing,
absent from all 1,242 demands, and erased by the chart save. **[F]** It never enters `demandId`
anywhere — the invariant holds. **[F]** No Kotlin file in either app references it — no client
dependency.

## 5. `canonicalFeeHeadId` — legacy status confirmed

**[F]** Still 3 distinct values per head; still equal to the chart-row `feeHeadId` on all 1,092;
`canonicalHeadIdMap()`'s source collection `feeHeads` still holds **0 documents**, so the canonical
resolution has still never fired. **[R]** Frozen legacy provenance. **Do not populate `feeHeads`.**

## 6. `category` authority — writers traced

| Location **[F]** | Operation | Source | Can be empty? | Infers from name? | M5 action |
| --- | --- | --- | --- | --- | --- |
| `Fee_lifecycle` admission spec (`$entryData`) | create | **omitted entirely** | **Yes → `''`** | no | **wire to registry** |
| `Fee_lifecycle::createModuleFee` | create | `strtolower($moduleType)` | no | module name | **wire to registry** |
| `Fees::_generateDemandsForMonth` → `_resolveFeeCategory()` | create | **name keyword heuristic** | falls back `General` | **YES** | **retire** |
| `Fee_firestore_txn::_buildDemandDoc` | persist | `$pick($data,['category'])` | **Yes** | no | keep; add fail-closed |
| `Fee_generation_service:147` | compare | `$data['category'] ?? ''` | Yes | no | keep |
| `Fee_concession_reader` / `Fee_entitlement_reader::appliesTo` | compare (I3) | demand `category` | n/a | no | keep |
| `Fee_lifecycle::proRateFees` | select | `category ?? feeHead` | n/a | **YES (fallback)** | Billing V2 |
| `Fee_lifecycle::cancelSessionDemands` | query | `category == lc(module)` | n/a | no | keep |

**[F] Three independent category writers disagree today**, and one of them (`_resolveFeeCategory`)
guesses from the name and can emit `General` — which is how the 4 out-of-vocabulary values exist.

## 7. Legacy-demand safety

**[R]** The rule stands: **a `category=''` demand must never silently become `academic`.** With
**B3** unresolved there is **no field that distinguishes eras**, so any M5 logic would have to infer
"legacy" from emptiness — workable for `''`, but **not for the 52 capitalised/`General` values**, which
are pre-M5 yet non-empty.

**[R]** M5 must therefore treat classification as a **three-state** problem, not two:
`registry-stamped` · `pre-M5 legacy (empty or out-of-contract)` · `unresolvable → fail closed`.
`categorySource` is **required** to make that distinction reliable. **No backfill.** Display,
recalculation, proration, cancellation and regeneration of legacy demands must keep today's behaviour.

## 8. Chart-save durability — **STILL BROKEN**

**[F]** Verified against current code: `feeHeadRef` count in `Fee_firestore_sync.php` = **0**; rows
rebuilt with `feeHeadId, name, amount, frequency, billingType`; `firestoreSet($docId, $doc)` with no
merge. **The next chart save erases all 28 values.** Additionally the same function **mints identity
inside a pricing save** (`$existingIds[$name] ?? mint()`), which is the root of the rename hazard.

**Smallest safe correction:** preserve `feeHeadRef` alongside `feeHeadId` from the previous snapshot
(~2 lines). **Must happen before M5.** Not fixed in this audit, per instruction.

## 9. Rename dependency matrix

| Dependency **[F]** | Name-based? | Convertible to `feeHeadRef`? | Historical migration? | Risk |
| --- | --- | --- | --- | --- |
| chart identity minting | **Yes** | Yes | No | **High** |
| module demand identity | **Yes** | Yes (pinned `demandKey`) | No | **High** |
| `canonicalHeadIdMap()` | **Yes** | Yes | No | High |
| concession `head:` scope | **Yes (fallback)** | Yes | **Yes — existing scopes** | **High — silent money change** |
| entitlement `head:` scope | **Yes (fallback)** | Yes | Yes | High |
| accounting keyword map | **Yes** | Yes | No | Med |
| proration | via `category`/name | Yes | No | Med |
| session cancellation | `category` only | n/a | No | Low |
| Parent / Teacher | display only | n/a | No | **None** |
| reports / exports | none found | n/a | No | None |

**[R] M5 can proceed safely while rename stays gated.** The gate must block: **rename of a fee-head
name, delete-and-re-add of an in-use fee title, and changing a head's `category` after demands exist**
(the last matters because category is stamped and immutable on demands — changing it mid-session would
make new and old demands of the same head disagree).

## 10. Seeded + custom heads — model ready, surface not built

**[F]** The registry supports both identically: school-scoped id, school-chosen name, category from the
controlled list, archive not delete. **[F]** The M3 mint proved the mechanism. **No global master
exists** — seeded is a template list, not an entity. **[R] Model is ready; the UI is not built and is
not part of M5.**

## 11. Scholarship / Student Enlightenment readiness

**[R] After M5** eligibility can rely on `category`; it is frequency-agnostic (monthly and annual
demands are each evaluated on their own stamp); transport/hostel/meals excluded as services;
administrative excluded; `feeHeadRef` identifies the head independently of session; historical
snapshots stay historical. **[F] The name heuristic must be removed from the generation path**
(`_resolveFeeCategory`) for "no name heuristic remains" to be true.

## 12. Parent / Teacher compatibility

**[F]** No app references `feeHeadRef`. Parent reads `demandId` (opaque), `feeHead`, `category`,
amounts; unknown fields ignored; transport detection is category-first with a name fallback → an
unknown or empty category **fails safe**. Teacher has no coupling. **[R] No app change required for
M5.**

## 13. Accounting

**[F]** Still name-keyword mapping; custom heads would fall through to the default account. **[R] Not
an M5 dependency** — M5 changes classification on demands, not GL posting. Defer to the accounting
phase; blocker only for custom heads.

## 14. Rules and indexes — requirements only

**[F] Current working-tree digest: `1090ecbf`** — moved again (previously `f3ed755b`, before that
`5b0f86fc`). **Not touched by me.** Record this and **re-check immediately before any rules work.**

| Need | Detail |
| --- | --- |
| `match /feeHeadRegistry/{doc}` | read: same-school only; write: same-school **and** Fees `manage`; name-claim docs never client-deletable; no cross-school resolution |
| Index | `schoolId + status` (add `sortOrder` only if the picker sorts) |
| Why | the resolver reads the registry at generation time; without rules the collection stays admin-SDK-only (safe but unusable from clients) |

## 15. M5 implementation plan (NOT authorised)

**MUST DO BEFORE STUDENT ENLIGHTENMENT**

| Step | File / function | Data | Migration | Flag | Rollback |
| --- | --- | --- | --- | --- | --- |
| **1** Preserve `feeHeadRef` | `Fee_firestore_sync::syncFeeStructure()` | none | No | No | revert 1 file |
| **2** Maintain `feeHeadRef` on chart save | same function — resolve from the registry by the row's existing id/name **at save time only** | chart rows | No | No | revert |
| **3** Add `categorySource` to the whitelist | `Fee_firestore_txn::_buildDemandDoc()` (conditional, like `chargeInstance`) | new demands only | No | No | revert |
| **4** Wire the resolver into the spec | `Fee_lifecycle` admission spec + `createModuleFee`, behind `USE_FEE_HEAD_REGISTRY` (default **false**) | new demands only | No | **Yes** | flip flag |
| **5** Retire `_resolveFeeCategory()` | `Fees.php` | none | No | after soak | revert |
| **6** Rules + index | `firebase-rules/` under reconcile-first from `1090ecbf` | none | No | No | revert |

**DEFER TO BILLING V2:** `chargeType`, installment plans, frequency validation, mid-session generation,
proration correctness, the "Yearly Fees" replacement.
**DEFER TO LATER HARDENING:** `demandKey`, `canonicalHeadIdMap` by ref, concession-scope migration,
accounting mapping, rename enablement, custom-head UI, removing dead `_buildDemandId`/`FREQUENCY_MAP`.

## 16. Test matrix (A–W, to be written at implementation)

A registry identity · B school isolation · C **no session in `feeHeadRef`** · D seeded selection mints
an ordinary head · E custom head creation · F rename leaves identity intact · G category correctness
per head · H unknown category **fails closed** · I **legacy `''` never becomes academic** · J monthly
demand stamped · K annual demand stamped · L Transport · M Hostel · N ID-card `chargeInstance` ·
O scholarship eligibility by category · P Parent unaffected · Q Teacher unaffected ·
**R chart-save preserves `feeHeadRef`** · S recalculation preserves category · T regeneration stable ·
U bulk generation converges · V concurrency (same id merges) · W **`feeHeadRef` never in `demandId`**.
**Add: X — the 52 pre-M5 capitalised/`General` values are treated as legacy, not re-stamped.**

## 17–18. Rollback and live-data impact

**Rollback:** every step is a file revert; step 4 is a flag flip. **Live-data impact of M5: new demands
only.** No existing demand is read-modified-written. The 24 registry docs and 28 `feeHeadRef` values
are additive and deletable.

## 19. Billing V2 deferred — one reservation

**[R]** M5 must not stamp anything that Billing V2 will own. Specifically **do not add `chargeType` to
the registry during M5** even though the target diagram shows it — one-time-vs-recurring semantics are
Billing V2's, and adding the field early would invite code to read it before the billing engine honours
it.

## 20. Unresolved business decisions

**[?]** Does `academic` need splitting into eligible vs non-eligible school charges (custom heads
default to scholarship-eligible today)? · **[?]** How should the **52 pre-M5 non-empty category values**
(`Academic`/`Transport` capitalised, `General` ×4) be treated — legacy-frozen, or normalised? ·
**[?]** Rounding/remainder rule (Billing V2 only).

## Corrections to earlier reports — stated plainly

| Previously claimed | Current code/data proves | Why it was wrong | Consequence |
| --- | --- | --- | --- |
| M4 "attached `feeHeadRef`", implying it is part of the working architecture | **No production code writes or reads it**; script-written data only | I reported the *data* result and did not audit for a maintaining code path | **B2** — M5 would depend on an unmaintained field |
| `categorySource` described as distinguishing eras (`"absent ⇒ legacy"`) | **0 occurrences — never implemented** | I carried design language into status language | **B3** — no era distinction exists |
| Category values summarised as `(empty)/Academic/Transport/General` without comment | **`General` is outside the controlled vocabulary**, and 48 values are capitalised | I listed them without testing them against the vocabulary | 52 demands need an explicit legacy policy |

## 21. GO / NO-GO

**NO-GO for M5 today.**
**GO for M5 immediately after steps 1–3** (`feeHeadRef` durable + maintained, `categorySource` added),
with steps 4–6 forming M5 proper behind a default-false flag.

Nothing implemented. No production code, Kotlin, rules, indexes, live data, migration, backfill, flag,
scholarship, entitlement or SRNO change. No commit, push or deploy.
`USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE`.
