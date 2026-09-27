# FEE IDENTITY — MINIMAL PREREQUISITE GATE

**READ-ONLY. Nothing implemented, migrated or written.**
2026-08-17 · **[ZC]** ZenX code · **[ZD]** ZenX live data · **[ER]** external ERP · **[REC]** recommendation

---

## 0. Executive answer

**The minimum is far smaller than the previous research implied — but it contains one item that is
genuinely urgent and was not previously known.**

| | Scope |
| --- | --- |
| **MUST DO NOW** | **(1) Make `Fee_firestore_sync` preserve `feeHeadRef`** — the next fee-chart save through the admin UI **silently erases all 28 values written in M4**. **(2) An operational constraint, not code: do not rename a fee head, and do not delete-and-re-add a fee title that is in use.** |
| **BEFORE SCHOLARSHIP** | M5 classification stamping + its rules/index. This — not rename — is the real scholarship dependency. |
| **DEFER** | `demandKey`, `canonicalFeeHeadId` by-ref, concession-scope migration, accounting mapping, rename UI. All are rename-activated, and rename is not a first-class operation today. |
| **DO NOT DO** | Put `feeHeadRef` into `_demandId()`; migrate demand ids; backfill legacy `category=''`; build a Charge Instance collection; redesign Fee Management. |

**Student Enrollment can resume immediately** after item (1), under the constraint in (2).
**Scholarship Phase C needs M5.**

---

## 1. Current-state verification (not from prior reports)

**[ZD]** `feeHeadRegistry`: **24 docs — 12 entries + 12 claims**, categories `{academic:6,
administrative:2, hostel:2, transport:2}`, both schools present. Intact.
**[ZD]** 28 chart rows carry `feeHeadRef`; `feeDemands` 1,242 unchanged; legacy `category=''` not
backfilled.
**[ZC]** `Fee_head_resolver` exists, **zero call sites**. `chargeInstance` present in the
`_buildDemandDoc()` whitelist (conditional). ID-card head stable + `instanceKey()`.
**[ZC]** Tests: **1,890 / 10 failures — exactly baseline**, no new failures.

## 2. Fee-head identity map

| # | Site **[ZC]** | Field used | Identity or display | Rename changes behaviour? | Can duplicate demands? | Class |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `Fee_lifecycle::assignInitialFees` (admission) | chart `feeHeadId` | **identity** | **yes — via #5** | **yes** | **B** |
| 2 | `Fee_lifecycle::createModuleFee` | head **name** | **identity** | **yes** | **yes** | C |
| 3 | `Fee_firestore_txn::_buildDemandDoc` → `canonicalFeeHeadId` | resolved **by name** | identity (dedupe) | **yes** | **yes** | C |
| 4 | `Fees::_auto_generate_student_demands` | dedupe on `(periodKey, canonicalFeeHeadId)` | identity | **yes** (via #3) | **yes** | C |
| 5 | **`Fee_firestore_sync::syncFeeStructure`** | `$existingIds[$name]` → else **mint new** | **identity source** | **yes — mints a NEW `feeHeadId`** | **yes** | **B** |
| 6 | `Fee_concession_reader::appliesTo` / `Fee_entitlement_reader::appliesTo` | `head:` scope, id **else name** | identity | **yes — concession silently unbinds** | no, but **changes money** | **A** |
| 7 | `Fee_lifecycle::proRateFees` | `category ?? feeHead` | selection | indirectly | no | D |
| 8 | `Fee_lifecycle::cancelSessionDemands` | `category == lc(module)` | selection | no | no | D |
| 9 | `Operations_accounting` head→GL | **name keyword** | mapping | **yes** | no | C |
| 10 | `Fees::_buildDemandId` | — | — | — | — | **E (dead, 0 callers)** |
| 11 | `Fee_firestore_txn::newDemandId` | `random_bytes` | identity | no | **yes, under concurrency** | D |
| 12 | Parent / Teacher `FeeDemandDoc.demandId` | opaque `String` | display | no | no | **F** |
| 13 | Transport `fee_demand_ref` | stored id string | audit only | no | no | **F** |

## 3. The rename mechanisms — now **five**, and one is new and worse

Previously identified: module `demandId` by name (#2); `canonicalFeeHeadId` by name (#3/#4);
concession scope name fallback (#6); category-based selection (#7/#8).

**NEW — mechanism #5, and it is the one that matters for enrollment [ZC]:**

```php
// Fee_firestore_sync::syncFeeStructure — the chart save path
'feeHeadId' => $regId[$name] ?? $existingIds[$name] ?? $mintFeeHeadId(),
```

`$existingIds` is built from the previous snapshot **keyed by name**; `$regId` reads the `feeHeads`
collection **by name** — and **[ZD]** that collection holds **0 documents**, so `$regId` is always
empty and preservation rests entirely on the name. **Rename a head on the chart and a brand-new random
`FH_…` is minted**, which is precisely the key the admission path feeds into `_demandId()`. Result:
**the admission/chart path produces new demand ids and duplicates existing charges.**

The pre-existing comment says *"preserve existing feeHeadId across saves so an edit to the same-named
head keeps the same opaque ID"* — the mitigation is itself name-keyed, and only survives *same-named*
edits.

**[ZC] There is no rename endpoint** — `Fee_management` exposes only `add_fee_title` and
`delete_fee_title` on the `_titles` doc. **But delete + add is a rename in disguise**, and it is
reachable today. That is why the constraint in §0 is a real requirement, not a formality.

## 4. ⚠ Defect in already-completed work — M4's `feeHeadRef` is not durable

**[ZC]** `Fee_firestore_sync::syncFeeStructure()` rebuilds each row with exactly five keys —
`feeHeadId, name, amount, frequency, billingType` — **`feeHeadRef` appears nowhere in the file (0
occurrences)** — and writes with `firestoreSet($docId, $doc)` **without a merge flag, i.e. a full
document overwrite**.

**So the next fee-chart save through the admin UI erases every one of the 28 `feeHeadRef` values M4
wrote.** M4's data is currently non-durable. Reporting, not fixing.

**[REC]** The fix is one line — carry `feeHeadRef` through from `$existingIds`-style preservation
alongside `feeHeadId`. No migration, no rules, no flag. **This is MUST-DO-NOW**, because every later
step assumes `feeHeadRef` is present.

## 5. `demandKey` validation

**[REC] Sound, but not needed yet — defer.** It answers Q1–Q12 affirmatively (it is pinned to the
existing key, so ids stay byte-identical for Transport, Hostel and chart fees; no migration; flagless;
old and new code compute the same id; survives archive, rollover, recalculation, retry). Q13: it must
be **operator-immutable** and server-owned. Q14: immutable forever. Q15: minted from the name once at
creation. Q17: school-scoped, so identical names in two schools are separate keys. Q18: "School Bus
Fee" as a *new* head gets its own key — correct.

**But it only closes mechanism #2.** With rename constrained, mechanism #2 cannot fire, so `demandKey`
buys nothing today. **Implement it when rename is unblocked, not before.**

**[REC] Confirmed: `feeHeadRef` must NOT enter `_demandId()`.** It would re-identify future demands
against unchanged historical ones. `feeHeadRef` = identity metadata; demand identity stays as it is.

## 6. `canonicalFeeHeadId`

**[ZC]** Real defect, rename-activated only. **Not a blocker for enrollment or scholarship** while
rename is constrained. Conversion to `feeHeadRef` is possible and would be backward-compatible for new
writes, but **existing demands carry the old value**, so it must never be retro-applied. **Defer.**

## 7. Scholarship / concession

**[ZC]** Both readers implement `head:<target>` as *match `feeHeadId`, else match the **name***. A
rename silently unbinds a head-scoped concession — the discount stops applying and the family is
charged more, with no error. **This is a direct financial-correctness issue and it is possible today.**

**[REC]** But it is **rename-activated**, and no concession scope needs to change for Phase C, which
keys on **`category`**, not on head scopes. **Answer to Part 5 Q4: no — scopes do not have to move to
`feeHeadRef` before Phase C**, provided rename stays blocked. Smallest safe solution = block rename;
migrate scopes later, when rename is enabled. **No dual-read** (prohibited, and unnecessary).

**The actual Phase C dependency is M5** — the registry-sourced `category` stamp. **[ZD]** admission
demands are still written with `category=''`, so I3 and the eligibility rule remain inert until M5.

## 8. Proration / rollover

**[ZC]** `proRateFees()` filters on `category ?? feeHead`; because `??` does not fall back on an empty
string, legacy demands with `category=''` **[ZD** 1,190 of 1,242**]** match nothing and are **silently
skipped**. `cancelSessionDemands()` queries `category == lc(moduleType)` and likewise misses them.
**Pre-existing, not caused by this programme, and improved — not worsened — by M5 stamping.**
**Defer**, registered as a live defect.

## 9. Custom fee heads

**[REC]** Supported by the current architecture with no new entity: custom **name** (school's choice),
**classification from the controlled vocabulary only** (`ASSIGNABLE`), stable identity, scholarship and
concession compatibility via `category`, Parent/Teacher compatibility. **[ER]** corroborated — ERPNext's
Fee Category is name + description + accounting defaults, reusable across years, with amounts in the
Fee Structure. **No policy fields needed.** Blockers before shipping custom heads: mechanisms #2, #3,
#5, #9 (accounting).

## 10. Rename policy

**[REC] C — rename BLOCKED**, and explicitly including the delete-and-re-add vector on an in-use title.
Not a UX call: five independent mechanisms produce duplicate demands or silent money changes.

**To unblock, all of these must land:** `feeHeadRef` durable (§4) · `demandKey` for module fees (#2) ·
`feeHeadId` preserved by ref not name in `Fee_firestore_sync` (#5) · `canonicalFeeHeadId` by ref (#3)
· concession scopes migrated to ref (#6) · accounting mapping off names (#9).

## 11. Accounting

**[ZC]** `Operations_accounting` maps head→GL by name keyword; a custom head matches nothing and falls
through to the default account. **[ZC]** It does **not** parse `demandId`. **Not a blocker for
enrollment or scholarship** (no custom heads exist **[ZD]**); **is a blocker for custom heads.** Owner:
a later accounting phase. **[ER]** ERPNext puts a default income account on the Fee Category.

## 12. Concurrency / two identity strategies

**[ZC]** Confirmed two, both legitimate: **deterministic** `_demandId()` (same id ⇒ merge; used by
**admission/enrollment** and module fees) and **random** `newDemandId()` + pre-read dedupe (used by
`_auto_generate_student_demands`; two concurrent runs could both see "absent" and both write).
**Enrollment uses the deterministic one — the safer of the two.** Scholarship consumes demands, not
ids. **Defer** unifying them; do not force one architecture without proof.
**[ZC]** `Fees::_buildDemandId()` has **zero callers** — dead, and divergent (keys on `feeHeadId` where
`createModuleFee` keys on the name). Its `[DEM ID FALLBACK]` guard uses `log_message('warning')`, and
CI3 levels are `ERROR/DEBUG/INFO/ALL` **[ZC** `system/core/Log.php:106`**]**, so it is **silently
dropped and has never fired.** **[REC]** Remove — safe, but optional and out of the minimum.

## 13. Cross-platform

**[ZC]** Admin: no rename endpoint; add/delete title only; chart save is the identity-mutating path
(§4). Parent: `demandId` opaque `String`; category-first with name fallback; unknown fields ignored →
custom heads and `chargeInstance` are safe, unknown categories **fail safe**. Teacher: no fee-head,
category, `demandId`-format or `chargeInstance` coupling. **No app change required.**

## 14. Minimal-scope matrix

| Change | Enrollment | Scholarship | Custom heads | Defer? | Risk if skipped |
| --- | --- | --- | --- | --- | --- |
| **Preserve `feeHeadRef` in chart sync (§4)** | **YES** | **YES** | YES | **No** | M4 data erased on next chart save |
| **Block rename / delete-re-add (constraint)** | **YES** | **YES** | n/a | **No** | duplicate demands, unbound concessions |
| M5 classification stamping + rules/index | no | **YES** | YES | No, for Phase C | I3 inert; eligibility base wrong |
| `demandKey` (module id by ref) | no | no | YES | **Yes** | only on rename |
| `canonicalFeeHeadId` by ref | no | no | YES | **Yes** | only on rename |
| Concession scopes → ref | no | no | YES | **Yes** | only on rename |
| Accounting mapping → ref | no | no | **YES** | **Yes** | custom heads mis-post |
| Remove `_buildDemandId()` | no | no | no | **Yes** | latent trap only |
| Unify id strategies | no | no | no | **Yes** | concurrency duplicate (unproven) |

**MUST DO NOW:** the two rows marked "No". **SHOULD DO NEXT:** M5 + rules/index. **DEFER:** the rest.
**DO NOT DO:** `feeHeadRef` in `_demandId()`; demand-id migration; legacy `category=''` backfill; a
Charge Instance collection; Fee Management redesign.

## 15. Implementation sequence (NOT authorised)

**Step 1 — preserve `feeHeadRef` in `Fee_firestore_sync::syncFeeStructure()`**
Files: `application/libraries/Fee_firestore_sync.php` (one function, ~2 lines: build a
`name → feeHeadRef` map from the previous snapshot exactly as `$existingIds` does, and emit it on the
rebuilt row when present). Data impact: none — it only stops erasing. Migration: **no**. Rules: **no**.
Index: **no**. Flag: **no**. Tests: chart save preserves `feeHeadRef` and `feeHeadId`; a save with no
prior ref writes no empty field; full-overwrite semantics otherwise unchanged. Cross-platform: none.
Rollback: revert one file. Concurrency: unchanged (single doc set).

**Step 2 — operational constraint.** No code. Document that fee-head rename and delete-re-add of an
in-use title are prohibited until §10's list lands. Optionally enforce later by refusing to delete a
title referenced by any chart row — **not** in this scope.

**Step 3 — M5** (classification stamping) behind `USE_FEE_HEAD_REGISTRY`, plus the `feeHeadRegistry`
rules and `schoolId + status` index, under the **reconcile-first procedure**: inspect working tree,
inspect branch, fetch remote `integration/phase2`, reconcile concurrent Staff-Lifecycle/HRMS edits,
show the exact delta, then propose. **The rules digest has moved repeatedly (last observed `f3ed755b`)
— re-check, never overwrite.**

## 16. Migration / rollback

**No migration at any step.** Step 1 changes no stored value. Step 3 gates only classification on new
demands. Rollback: revert files; the 24 registry docs and 28 `feeHeadRef` values are additive and
deletable; no financial record has been or would be written.

## 17. Final answer — can we return to Enrollment and Scholarship?

**Student Enrollment: YES, after Step 1, under the Step 2 constraint.** The admission path uses the
deterministic `_demandId()` with the chart's `feeHeadId`, which is stable **so long as no head is
renamed**. Nothing else about enrollment is blocked by fee identity.

**Scholarship / Student Enlightenment Phase C: NO — one dependency remains, and it is M5, not rename.**
**[ZD]** admission-generated demands still carry `category=''`, so the eligibility rule and invariant
I3 are inert. M5 (registry-sourced category stamping) plus its rules/index closes it.

**Resume point:** Step 1 → Step 2 → return to Student Enrollment immediately; run M5 in parallel or
directly after, then Scholarship Phase C.

Nothing in this report is authorised. No production code, Firestore data, registry document,
`feeDemands`, `feeStructures`, `feeCategories`, rules, index or feature flag was changed. No admission,
SRNO allocation, scholarship or billing run. No commit, push, deploy, merge or rebase.
`USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE` · Firestore-only, no RTDB.

**Sources:** [ERPNext Fee Category](https://docs.frappe.io/erpnext/v13/user/manual/en/education/fee-category) ·
[ERPNext Fee Structure](https://docs.frappe.io/education/fee-structure) ·
[OpenEduCat Fees Management](https://doc.openeducat.org/applications/openeducat/fees-openeducat.html)
