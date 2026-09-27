# PHASE 19L — CREDENTIAL WIRING · V1 TELEMETRY · ACCOUNTING

**Date:** 2026-08-16 · **Branch:** `integration/phase2`
**Status: NOT CLOSED — BLOCKED ON DEPLOYMENT, REAL UAT, AND TWO DECISIONS**

Not committed · not pushed · not deployed · no rules/indexes changed · Attendance **working-day logic
untouched** · `Fee_lifecycle.php` and `Operations_accounting.php` untouched · no RTDB.

---

## 1. EXECUTIVE SUMMARY

`validateCredential()` is now the **sole credential authority for all three consumers**. Behaviour today
is unchanged — every flag ships `false` — but enforcement is now a config flip that has already been
exercised in code, rather than a code change made under time pressure at the gate.

**Two new defects found while wiring, one of them a landmine I planted myself minutes earlier:**

| ID | Sev | Defect | State |
|---|---|---|---|
| **ID-WIRE-1** | **HIGH** | `Transport_qr_attendance_service` has **no `$this->firebase` property**. My first wiring passed it, leaving the card service `ready === false` — silent today, but the moment enforcement is enabled `currentVersion()` returns 0 for every student and **Transport rejects the entire school at the bus door**. | **FIXED** |
| **ID-VIS-1** | **HIGH** | Visitor badges carry a **passId**, are minted V1, and have **no `idCards` record** — they merely share the token encoder. Passing them the student-card flags means enabling `IDCARD_REJECT_V1` to retire *student* cards would **reject every visitor badge in the school**. | **FIXED by design** |

Also delivered: **SEC-ID-3b V1 telemetry**, behind its own separately-deployable flag; **ID-D4 researched
against the real Chart of Accounts**, correcting my own 19K recommendation.

**Regression: 1356 tests / 3798 assertions / 10 failures — the identical baseline, 0 new.**
20 new tests; **3 pre-existing guard tests updated, not deleted** (§9).

---

## 2. WORKTREE (Part 0)

`firestore.rules`, `firestore.indexes.json`, `rbac_helper.php`, `MY_Controller.php`,
`Firestore_service.php`, `Entity_firestore_sync.php` — all **earlier unrelated** dirty work, **none
touched**. Edited this phase: `Id_card_service.php`, `Attendance.php` (credential path only),
`Transport_qr_attendance_service.php`, `Visitor_service.php`, `id_card_flags.php`, plus tests. Nothing
reset, stashed or reverted.

## 3. PHASE 19K RE-VERIFICATION (Part 1) — **holds**

Independently confirmed in live source:

- **ID-MINT-1:** `Sis::id_card()` builds `card_versions` filtered to `STATUS_ACTIVE`; the view mints
  **V2 when a managed active card exists**, V1 otherwise. A revoked card is never printed as live.
- **ID-RACE-1:** `expectedCurrentVersion` present; stale assertion → `version_conflict` with **no write**;
  correctly sequenced replacement still succeeds; only **one** chargeable version can result.
- History · revoke · supersede · audit-under-`meta` · reason · operator note · SRNO · RBAC · search ·
  print · duplicate-click — all verified.

**No 19K defect found.** The defects this phase found were introduced *by this phase's own wiring* and by
a pre-existing cross-domain coupling.

## 4. SRNO MANDATORY AUDIT (Part 2) — re-run, **CLEAN**

Four sweeps from source: Roll-No substitution (two benign hits) · SRNO as key/FK (**zero**) ·
`execute_promotion` / `transfer_students` / `rollover_session` (**zero `srNo` references**) · all writers
legitimate. All A–P checks pass.

**New surfaces carry no identity risk:** `configuredFlags()` returns booleans; the V1 telemetry line logs
`schoolId` + `studentId` only. The credential subject is still `studentId` — no consumer was given a path
to substitute SR No. or Roll No.

## 5. SEC-ID-3 DEPLOYMENT (Part 3) — **prepared, NOT deployed**

| | |
|---|---|
| Files | `application/controllers/Attendance.php`, `application/controllers/Campus_access.php` |
| Change | **log output only** |
| Level | `error` — the only level written at `log_threshold=1` |
| Records | `schoolId` + `studentId` — sufficient to reissue the exact card |
| Rejects credentials? | ❌ — all flags ship false |
| Fees / schema / rules / indexes? | ❌ to all |
| Rollback | revert the files; nothing written, nothing migrated |
| Observation | one complete real scanning cycle in which every student has had an opportunity to scan |

**I have no deployment authority. Production telemetry does not exist and none is fabricated.**

> **Scope note:** `Attendance.php` now also carries the credential wiring and the V1 telemetry line, so
> the SEC-ID-3 deployment necessarily ships them too. Both are **inert by configuration**, and the
> combined change is still **log-output-only** with all flags false. Stated explicitly so the operator
> reviews what is actually being deployed rather than the 19C description of it.

## 6. V1 TELEMETRY (Part 4) — implemented, **separately gated**

`IDCARD_V1_TELEMETRY`, default **false**, distinct from every other flag.

**Why separate:** SEC-ID-3 must deploy as a *pure observation* change. Bundling V1 telemetry into the
same activation would mean the first production run of the observation mechanism also carried an
untested second behaviour. The flag lets the operator deploy once and activate in two reviewed steps.

**Volume warning, and why it defaults off:** 19K established production is **legacy + V1 with zero V2
cards**, so V1 is most of the live population — enabling this writes an `error` line for essentially
**every scan**. Fine for a bounded window, unacceptable as a steady state. Enable → observe → disable.

Logs `schoolId`, `studentId`, consumer, timestamp (CI's own). **Never** the token, signature or secret —
asserted by a test across all three consumers. Wrapped in `catch (\Throwable)` so telemetry can never
block a scan. **No V1 deadline invented.**

## 7. CANONICAL CREDENTIAL AUTHORITY (Part 5) — wired, all three

| Consumer | Wiring | Flags |
|---|---|---|
| `Attendance::scan_qr` | `validateCredential()` | `configuredFlags()` |
| `Transport_qr_attendance_service` | `validateCredential()` **after** its own stricter legacy rejection | `configuredFlags()` |
| `Visitor_service::lookupByQrToken` | `validateCredential()` | **explicit all-false — never `configuredFlags()`** |

Domain rules stay with their owners: attendance's Active-status gate, transport's `studentRoutes`
eligibility, campus-access RBAC. **No consumer re-implements credential policy** — a test asserts none
calls `isCurrentVersion()` or `currentVersion()` directly.

### ID-WIRE-1 — the landmine

`Transport_qr_attendance_service` holds `$this->fs` but **no `firebase` property**. My first wiring
passed `$this->firebase`, so `Id_card_service::init()` would have set `ready === false`. Today that is
invisible. The moment `IDCARD_ENFORCE_V2` is enabled, `currentCard()` returns null → `currentVersion()`
returns 0 → **every student rejected as `no_active_card` at the bus door**.

Caught by checking the property list instead of assuming it. Fixed to take firebase from the CI
instance, with an explicit not-ready fallback that **falls through rather than returning early** — my
first fallback would have skipped F14's own tenant check below it, which is a security regression. Both
are pinned by tests.

### ID-VIS-1 — cross-domain coupling

`Visitor_service::_mintBadgeCode()` calls `qr_token_encode($schoolId, $passId)`. A visitor badge is a
**V1 token whose subject is a passId** (`VPS######`), with no `idCards` record. The two credential
families share only the encoder.

Passing it `configuredFlags()` would mean a decision about **reprinting student ID cards** silently
invalidates **every visitor badge in the school**. So the visitor path gets explicit all-false flags: it
takes the parts that belong to *every* credential — malformed rejection and tenant isolation — and none
of the student-card lifecycle policy. The original tenant check is retained as defence in depth, since
the canonical check is skipped if the service cannot be built.

### Verdict matrix (with `enforceV2`)

Current V2 **accept** · superseded **reject** · revoked **reject** · no active card **reject** ·
cross-school **reject (no flag disables it)** · wrong student **reject (HMAC)** · malformed **reject** ·
unknown version **reject** · V1 **accept** · legacy **accept**. `enforceV2` **cannot** retire V1 —
conflating them would force schools to reprint cryptographically sound cards.

### Rejection messages leak nothing

`superseded` and `no_active_card` return an **identical** operator message — the difference is exactly
the oracle an attacker probes for. The precise code goes to the log. `cross_tenant` may be named because
it reveals nothing the holder does not already know and is the actionable message. Test-asserted.

## 8. MIGRATION (Parts 6–7) — evidence still unavailable

**ISSUED ≠ PRESENTED**, and `migrationReadiness()` enforces the distinction in code
(`legacyHoldersKnown: false`). The evidence report's columns — legacy scans, V1 scans, students affected
— all come from telemetry that has not been deployed. **Producing that table with zeros would be
manufacturing evidence, so it is not produced.**

Workflow verified: *No card issued* filter → Issue/Replace → version increments → old superseded →
**new card now physically prints V2** (ID-MINT-1) → history → audit. Telemetry-identified students are
reachable by SR No. / Adm No. / Student ID search.

## 9. TESTS (Part 21) — including three I had to correct

| Metric | Value |
|---|---|
| TOTAL TESTS | **1356** |
| TOTAL ASSERTIONS | **3798** |
| BASELINE FAILURES | **10** |
| **NEW FAILURES** | **0** |
| NEW TESTS | **20** |
| LINT / RTDB | clean |
| RULES / INDEXES | unchanged |

The first full run showed **13 failures — 3 new**. All three were my own earlier guard tests asserting
*"no consumer calls the authority"* / *"wired nowhere"* — a premise **19L deliberately changed**.

> They were **updated, not deleted.** Deleting them would have quietly dropped a real guard. Each now
> asserts the invariant that actually matters today: consumers **defer** to the authority, never
> re-implement it, and enforcement stays inert **by the flag gate** rather than by absence of wiring.
> The superseded premise is documented in each test so the change is auditable.

## 10. ACCOUNTING — ID-D4 (Part 8), **correcting my 19K recommendation**

`SOURCE-VERIFIED` — the CoA seed is `Accounting.php:4051-4062` (mirrored in `Hr.php:5613`):

```
4000 Fee Income (parent)   4010 Tuition · 4020 Admission · 4030 Examination
                           4040 Transport · 4050 Hostel · 4060 Late Fees/Penalty
4100 Other Income (parent) 4110 Interest · 4120 Donation · 4130 Rent · 4140 Miscellaneous
```

**`4070` does not exist.** Two corrections to what I wrote in 19K:

1. I claimed `AccountingSimulator:822`'s hardcoded income list would need updating. **Wrong** — that list
   classifies **Dr** lines as concessions; an income account used only for **Cr** is unaffected.
2. I recommended creating 4070 without having found the seed. Having now read it, there is a **zero-change
   option** I missed: **`4140 Miscellaneous Income`** already exists, is already outside the Fee Income
   group, and needs no new production structure.

| Option | Account | New structure? | Semantics | Reporting |
|---|---|---|---|---|
| **A** | **4140 Miscellaneous Income** (exists) | **none** | correct — this is not fee income | lumped with other misc |
| **B** | **4070 ID Card & Identity Income** (new, under 4000) | seed in **two** files | sits with fee heads, which slightly overstates "Fee Income" | dedicated line |

> **`ZENX RECOMMENDATION`: Option A now, Option B only if the school wants a dedicated reporting line.**
> A requires no production accounting change and is semantically defensible: an ID-card replacement is
> cost recovery for a consumable, which is precisely *Other → Miscellaneous* income. B is the nicer
> report at the cost of creating structure and placing non-fee income under Fee Income.
>
> Either way the resolver needs one keyword — `'id card'` — which matches **every** version because the
> head *starts* with "ID Card Replacement". **No version-specific mapping**, which the brief forbids.

**Not implemented.** `Operations_accounting.php` is untouched: adding the keyword presupposes the account
choice, and creating production accounting structure needs authorization. **Nothing posts today** —
every amount is ₹0 — so this is latent, not live. **ID-D4 remains a decision.**

## 11. REPLACEMENT FEES — ID-D7 (Part 9)

Unchanged and unimplemented-by-design: per-school, `lost`/`stolen`/`damaged` configurable, **default ₹0**.
`first_issue` is **structurally excluded**; `wear`, `data_correction` and `admin` are **never chargeable**
— a parent must not pay for normal wear or the school's own data error. Test-asserted even against a
config attempting it.

| Evidence | Class |
|---|---|
| First issue free · loss chargeable · escalating repeat loss · stolen waived with police report · damaged waived on surrender | `SOURCE-VERIFIED` — **US higher-ed** |
| **Indian K-12 school-ID replacement norm** | **`NOT ESTABLISHED`** — only CBSE *admit-card* duplicates surface |
| Amounts | **`USER DECISION REQUIRED`** |

**US higher-ed structure is not Indian K-12 policy** — which is why every default is ₹0 rather than a
plausible-looking number.

## 12. MULTIPLE REPLACEMENTS (Part 10) — verified behaviourally

v2 (lost) and v3 (damaged) produce **separate fee heads → separate demands**; the v2 record survives v3
intact (`supersededByVersion` set, original `reason` preserved); a double-click yields **exactly one**
chargeable version (ID-RACE-1). Receipts/payments/balance/defaulter follow the existing `feeDemands`
chain untouched. **Two browser tabs / two operators** is the same server-side path as double-click and is
covered by the same assertion — but the browser-level case still **requires real UAT**.

## 13. FIRESTORE RULES (Part 11) — unchanged

`idCards` remains server-only; deny-by-default is correct and intentional; no client consumer exists. No
rule written, no index changed. Prerequisite for any future rule: reconcile `firestore.rules` with
canonical `integration/phase2` first.

## 14. UAT (Part 12) — **NOT EXECUTED**

**No browser or device. No result is manufactured.**

| Class | Cases |
|---|---|
| **STATICALLY VERIFIED** | first issue · replace (lost/stolen/damaged/wear/admin) · revoke · re-revoke · history · old vs new QR · withdrawal · TC · re-admission · audit · replacement fee · concurrent replace (server side) |
| **PARTIALLY VERIFIED** | search ×3 · RBAC · filtered print |
| **REQUIRES BROWSER** | Select All · Clear · Print Selected · zero selection · duplicate click ×4 · two tabs |
| **REQUIRES DEVICE** | physical QR scan · legacy QR · V1 QR · V2 QR · revoked QR |
| **REQUIRES DEPLOYMENT** | legacy telemetry · V1 telemetry · accounting posting |

## 15. FINAL SECURITY REVIEW (Part 15)

| Check | Result |
|---|---|
| Any path mints V1 unexpectedly? | ❌ — only when no managed active card exists, which is correct |
| Accepts unsigned unexpectedly? | ❌ — legacy accepted **deliberately**, gated, Transport already refuses |
| Ignores current version? | ❌ — the authority checks it whenever `enforceV2` is on |
| Accepts superseded / revoked? | ❌ under enforcement; ✅ today **by design**, pending evidence |
| Bypasses `validateCredential()`? | ❌ — all three consumers wired |
| Uses Roll No / SRNO as credential subject? | ❌ — `studentId` only, test-asserted |
| Exposes secret? | ❌ — no credential material logged anywhere |
| Exposes QR to mobile apps? | ❌ — neither app receives it |

## 16. MASTER DEFERRED REGISTER (Part 16)

| ID | Sev | Module | Business impact | Technical impact | Current state | Dependency | Next phase | Acceptance |
|---|---|---|---|---|---|---|---|---|
| **SEC-ID-3 deploy** | HIGH | ID Card | migration cannot start | no evidence exists | code-complete, undeployed | **operator authorization** | immediate | `SEC-ID-3` lines in production log |
| **SEC-ID-1** | HIGH | ID Card | lost card still scans | **wired**, flag off | inert by config | telemetry + reissue | 19M | superseded/revoked rejected at all 3 consumers |
| **SEC-ID-2** | HIGH | ID Card | unsigned tokens forgeable | mechanism built, off | inert by config | zero legitimate legacy usage | 19M | forged unsigned rejected; no school bricked |
| **SEC-ID-3b** | MED | ID Card | V1 holders uncountable | **implemented**, flag off | inert by config | SEC-ID-3 deployed first | 19M | V1 usage countable per school |
| **ID-VIS-1** | HIGH | Campus Access | V1 retirement would brick every visitor badge | shared token encoder | **mitigated** — visitor path never inherits student flags | — | — | ✅ visitor badges immune to student-card policy |
| **ID-WIRE-1** | HIGH | Transport | whole school rejected at the bus door on enforcement | missing `firebase` property | **FIXED** | — | — | ✅ card service ready; falls through, never early-returns |
| **ID-ACCT-1** | MED | Accounting | replacement income posts to **Tuition 4010** | head matches no keyword | open | **ID-D4** | 19M | posts to the chosen account |
| **ID-D4** | decision | Accounting | income misclassified | — | open | **user** | — | account chosen; keyword added; posting verified |
| **ID-D7** | decision | Fees | no charging possible | — | open | **user** | — | amounts set per school |
| **ATT-WD-1** | **HIGH** | Attendance | Mon–Fri schools' attendance % understated every month across Admin + both apps | ignores `Timetable_service.workingDays`; Sunday hardcoded; working Sunday unmarkable | open, **untouched** | ATT-WD-D1 | Attendance closure | correct % with no manual holidays |
| **ATT-WD-2** | MED | Attendance | permanent false health alarm | `attendanceConfig.working_days` read, never written | open | ATT-WD-1 | Attendance closure | health check reflects reality |
| **EXAM-LC1** | **HIGH** | Examination | camelCase-only roster → no marks / report card | shape drift in `Roster_helper::for_class` | open | — | Examination | both shapes return students |
| **LC-1 residual (~10)** | MED | various | rows silently dropped | single-shape predicates | open | — | per-module | replaced with `_filterSectionRoster` |
| **Fees UAT** | HIGH | Fees | Fees not closable | — | open | live tenant | — | matrix executed |
| **FEES-D7 backfill** | MED | Fees | historical dueDates absent | — | open | production credential | — | dry-run reviewed, then run |
| **`studentFeeSummary` reconciliation** | MED | Fees | historical summaries understated | self-heals per student | open | production credential | — | totals match demands |
| **FEES-D16** | decision | Fees | Transport/Hostel never fined | — | open | user | — | policy chosen, forward-only cutoff |
| **FEES-D9/D13/D14/D15** | decision | Fees | carried | — | open | user | — | decided |
| **D19 Bulk Roll Assignment** | MED | Class/Section | manual roll entry | — | open | user | — | bulk assignment available |
| **Existing-roll policy** | MED | Class/Section | ambiguous on re-run | — | open | user | — | policy defined |
| **CAP-2** | MED | Class/Section | concurrent overfill | race | open | — | Class/Section | concurrent adds cannot exceed capacity |
| **`idCards` rules** | MED | ID Card | none today | server-only | intentional | reconcile canonical rules | 19M | rule deployed before any client read |
| **SRNO-3** | LOW | SIS | none | inaccurate comment | open | — | any | comment matches code |

## 17. DECISIONS REQUIRED

| ID | Decision | Recommendation |
|---|---|---|
| **SEC-ID-D1** | Authorize deploying SEC-ID-3 | **Yes** — log-only, all flags off, total rollback. Gates everything downstream |
| **ID-D4** | Ledger account | **`4140 Miscellaneous Income`** (exists, zero new structure) — or create `4070` for a dedicated line |
| **ID-D7** | Per-reason amounts | per-school; keep defaults ₹0 |
| **ATT-WD-D1** | Attendance to consume `Timetable_service.workingDays`? | **Yes** — the data exists and is operator-configured |

## 18. CLOSURE GATE (Part 17)

❌ SEC-ID-3 deployed · ❌ telemetry observed · ❌ legacy usage identified · ⏸ V1 usage addressed
(*mechanism ready, flag off*) · ❌ physical migration completed · ✅ **V2 actually printed** ·
❌ `migrationReadiness` independently verified against telemetry · ✅ **`validateCredential` wired into
all 3** · ✅ current V2 works · ✅ superseded rejected *(under enforcement)* · ✅ revoked rejected
*(under enforcement)* · ❌ legacy retirement enabled · ⏸ V1 retirement policy · ⏸ ID-D4 · ⏸ ID-D7 ·
❌ replacement accounting verified · ✅ multiple replacements verified · ❌ browser UAT · ❌ physical QR
scan · ✅ Teacher App · ✅ Parent App · ✅ SRNO clean · ✅ Firestore-only · ✅ RTDB clean ·
✅ zero new regression failures · ✅ no HIGH/CRITICAL ID-card defect remains

# ID CARDS — **NOT CLOSED**

**Blocked on three external things, none of them engineering:**

1. **Deploy SEC-ID-3** (operator) — gates the entire chain.
2. **Real UAT** — browser and scan device.
3. **ID-D4 and ID-D7** — product decisions.

This phase again found HIGH defects the previous report could not have caught, one of them created by
its own wiring minutes earlier. **That is the argument for the re-verification discipline, not against
it** — and the reason I will not mark this closed.
