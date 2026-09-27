# PHASE 19K — ID CARD MIGRATION, ENFORCEMENT & CLOSURE

**Date:** 2026-08-15 · **Branch:** `integration/phase2`
**Status: NOT CLOSED — BLOCKED ON EXTERNAL DEPLOYMENT + REAL UAT**

Not committed · not pushed · not deployed · no rules/indexes changed · no Attendance/Examination changed ·
`Fee_lifecycle.php` and `Operations_accounting.php` untouched · no RTDB.

---

## 1. EXECUTIVE SUMMARY

**Independently re-verifying 19I/19J found three real defects, two of them HIGH. All three are fixed.**
That is the substance of this phase — and it is exactly why the previous report should not have been
taken as proof of completeness.

| ID | Sev | Defect | State |
|---|---|---|---|
| **ID-MINT-1** | **HIGH** | The printed card still minted **V1** while `issue()` recorded a **V2** card. Reissuing a student produced a v2 register entry and a physically un-versioned card. `migrationReadiness()` would have reported *"safe to enforce"* while **not one physical card in the school was V2** — the migration could never have completed, and the failure was silent. | **FIXED** |
| **ID-RACE-1** | **HIGH** | `issue()` had no server-side idempotency. Two rapid Replace submissions each claimed the next version — **two cards and two replacement fee demands for one real event**. The panel's busy-lock is client-side only. | **FIXED** |
| **ID-ACCT-1** | MED | The fee head *"ID Card Replacement (Card v2)"* matches **no** keyword in the account resolver and falls through to the default **`4010` Tuition Fees** — the exact misclassification ID-D4 exists to prevent. | **registered** (decision, §12) |

**ID Cards cannot be closed.** Enforcement, legacy retirement and UAT all require a production deployment
and a browser/device I do not have. **No telemetry exists; none is invented.**

**Regression: 1336 tests / 3724 assertions / 10 failures — the identical pre-existing baseline, 0 new.**
19 new tests. Lint clean. RTDB clean.

---

## 2. WORKTREE (Part 0)

Unchanged classification from 19I. `firestore.rules`, `firestore.indexes.json`, `rbac_helper.php`,
`MY_Controller.php`, `Firestore_service.php`, `Entity_firestore_sync.php` all carry **earlier unrelated**
uncommitted work — **none touched**. Edits this phase: `Id_card_service.php`, `Sis.php` (additive),
`views/sis/id_card.php`, plus one new test file. Nothing reset, stashed or reverted.

## 3. PHASE 19I/19J VERIFICATION (Part 1) — **did not hold**

Verified true: Credential V2 · `Id_card_service` lifecycle · `validateCredential()` logic and fail-closed
behaviour · `migrationReadiness()` · flags all `false` · fee policy zero-default · three QR consumers
unwired · SRNO · UI · RBAC · audit shape · server-only Firestore access · `$this->feeLifecycle` genuinely
available in `Sis.php` (`:69-71` — checked, not assumed).

**Did NOT hold — the three defects above.** Two of them were introduced by my own earlier phases and
survived four rounds of review because every check confirmed the *record* was right without ever asking
whether the *printed artefact* matched it, or what two concurrent operators would do.

### ID-MINT-1 — the migration could never have completed

`views/sis/id_card.php` unconditionally called `qr_token_encode()` (V1). `Id_card_service::issue()`
returns a **V2** credential and records a versioned card. **Nothing ever printed V2.** So:

- every physical card in production today is **legacy or V1**;
- an operator could "reissue to migrate", see *card v2 · Active*, and hand out a V1 card;
- `migrationReadiness()` reports on **issuance**, so it would have said `safeToEnforceV2: true`;
- flipping `IDCARD_ENFORCE_V2` then changes nothing (V1 is unaffected by design), so the school would
  believe it had migrated while remaining fully exposed.

**Fix:** `Sis::id_card()` loads the register (one query — the same one the panel already makes) and passes
`{studentId: activeVersion}`; the view mints **V2 when a managed active card exists**, V1 otherwise.
Students with no managed card keep a working V1 token. Only `STATUS_ACTIVE` versions are passed, so a
revoked card is never printed as if live.

### ID-RACE-1 — one event, two cards, two charges

The atomic `firestoreCreate` claim prevents two cards at the *same* version; it cannot prevent two
legitimate-looking *sequential* ones. Two submits both read `prevVer=1` and claim v2 and v3.

**Fix:** an optional `expectedCurrentVersion` assertion — the **same discipline
`Sr_no_service::relink()` already enforces**, reused rather than reinvented. The panel sends the version
it displayed; a stale assertion aborts with `version_conflict` and **writes nothing**. A correctly
sequenced second replacement still succeeds. Tested both ways, including that only **one** chargeable
version can result.

**No second credential authority was created.** `validateCredential()` remains the only one.

## 4. SRNO MANDATORY AUDIT (Part 2) — re-run, **CLEAN**

Four sweeps repeated from source, not inherited:

- **Roll-No substitution:** two hits, both benign (the SRNO-1 fix comment; a `Fees.php` presence-check).
- **SRNO as key/FK:** zero hits.
- **Lifecycle mutation:** `execute_promotion` / `transfer_students` / `rollover_session` — **zero `srNo`
  references**. Preservation is structural.
- **Writers:** all enumerated, all legitimate.

**New surfaces this phase carry no identity risk:** the `card_versions` map is keyed on `studentId`; the
`expected_version` assertion is an integer. Neither can introduce a substitution. The credential still
binds `studentId` only — a test fails the build if the encode subject ever becomes `srNo`, `admNo`,
`rollNo`, `cls` or `sec`, and it now covers **both** the V1 and V2 mint calls.

All A–P checks pass. **No SRNO defect found; none omitted.**

## 5. SEC-ID-3 DEPLOYMENT BOUNDARY (Part 3) — **I cannot deploy; nothing is faked**

| | |
|---|---|
| Files | `application/controllers/Attendance.php`, `application/controllers/Campus_access.php` |
| Change | **log output only** |
| Log level | `error` — the only level written at `log_threshold=1` |
| Message | `SEC-ID-3 … legacy unsigned token used for {schoolId}/{studentId} (reissue this student's ID card…)` |
| Sufficient for tracing? | ✅ `{schoolId, studentId}` identifies the exact card to reissue |
| Rejects credentials? | ❌ | 
| Changes QR behaviour / card records / fees / rules / indexes? | ❌ to all |
| Rollback | revert two files — nothing written, nothing migrated |
| Observation | at least one complete real scanning cycle in which **every** student is expected to scan. **No deadline invented** — coverage defines the window, not the calendar |

**Production legacy-usage evidence does not exist.**

## 6. LEGACY MIGRATION EVIDENCE (Part 4) — cannot be collected

No `{schoolId, studentId}` pairs can be produced without deployment. The distinction the brief demands is
now enforced *in code*: `migrationReadiness()` returns `legacyHoldersKnown: false` and a note pointing at
SEC-ID-3.

> **ISSUED ≠ PRESENTED.** The register proves who has been *issued* a card. Only telemetry shows what a
> family *presents*. **ID-MINT-1 proves why that distinction is not pedantry** — before this phase, every
> student could have had a v2 card issued while every physical card was V1.

## 7. V1 TELEMETRY GAP (Part 5) — investigated, and the population is now known

**`SOURCE-VERIFIED`: V1 cards certainly exist.** The printed card minted V1 unconditionally from the HMAC
upgrade until this phase. So production is **legacy + V1 only, with zero V2 cards** — established by
reading the mint path, not inferred.

That makes SEC-ID-3b more relevant than 19I judged: V1 is not an edge case, it is (with legacy) the
*entire* current population. **Still not implemented here**, for the reason the brief itself gives —
adding a V1 line changes what the "pure observation" deployment does, and that deployment has not
happened yet. Sequencing it wrongly would mean deploying twice to observe once.

`ZENX RECOMMENDATION`: deploy SEC-ID-3 as-is first (it is already written and reviewed); add V1 telemetry
as a **second** observation change once the first has proven the mechanism in production. **No V1 deadline
is invented.**

## 8. REISSUE / MIGRATION WORKFLOW (Part 6) — verified

Filter *"No card issued"* → the remaining list **is** the migration backlog → Issue/Replace → filter
empties. Targeted reissue for a student named in telemetry works via the SR No. / Adm No. / Student ID
search closed in 19F.

Verified behaviourally: no duplicate active version · **concurrent issue now refused** (ID-RACE-1) ·
history retained · reason recorded · operator note preserved separately · old version superseded · new
credential valid **and now actually printed** (ID-MINT-1).

## 9. SEC-ID-1 ENFORCEMENT (Part 7) — **built, wired nowhere, OFF**

Cannot be enabled: evidence does not exist, and — until this phase — **no V2 card had ever been
printed**, so enforcement would have had nothing legitimate to accept. Post-fix, enforcement becomes
meaningful only after reissue actually distributes V2 cards.

`validateCredential()` behaviour, tested: current V2 **accept** · superseded **reject** · revoked
**reject** · no active card **reject** · cross-school **reject (no flag can disable)** · malformed
**reject** · V1 and legacy **unaffected**. `enforceV2` deliberately cannot retire V1 — conflating them
would force schools to reprint cryptographically sound cards.

## 10. SEC-ID-2 RETIREMENT (Part 8) — **NOT DONE, correctly**

Every precondition unmet. **No migration evidence table can be produced** — the columns (legacy scans,
students affected) come from telemetry that does not exist. Producing a table of zeros would be
manufacturing evidence.

Mechanism built and tested; flag ships `false`; rollback is one config value.

## 11. CREDENTIAL CONSUMER MATRIX

| | Attendance `scan_qr` | Transport F14 | Campus Access lookup |
|---|---|---|---|
| Signed / V1 / V2 accepted | ✅ / ✅ / ✅ | ✅ / ✅ / ✅ | ✅ / ✅ / ✅ |
| Legacy accepted | ✅ | ❌ rejects | ✅ |
| Current version checked | ❌ | ❌ | ❌ |
| Revoked / replaced rejected | ❌ | ❌ | ❌ |
| Inactive student rejected | ✅ | via eligibility | pass must exist |
| Wrong student / school / malformed | ✅ / ✅ / ✅ | ✅ / ✅ / ✅ | ✅ / ✅ / ✅ |
| Unsigned rejected | ❌ | ✅ | ❌ |
| **Uses canonical authority** | not yet | not yet | not yet |

Three wiring changes remain — **three call sites, not three policies**.

## 12. REPLACEMENT FEE + ACCOUNTING (Parts 9–11) — **ID-ACCT-1 found**

`Operations_accounting::_resolveAccount()` maps a fee head to an account by **keyword**:

```
4020 admission|registration|enrol   4030 exam|test|assessment
4040 transport|bus|conveyance       4050 hostel|boarding|mess
4060 late|fine|penalty|overdue      → default 4010 Tuition Fees
```

**"ID Card Replacement (Card v2)" matches none of them → posts to `4010` Tuition Fees.** That is precisely
the misclassification ID-D4 was raised to prevent, and the version-scoped head also defeats the resolver's
*exact-match config* path (step 1) because a school would need one config entry per card version.

**Not silently fixed.** Adding a keyword presupposes the account choice, and Part 9 forbids creating
production accounting structure without authorization. **Nothing posts today** because every amount is
`0.0`, so this is latent, not live.

> **`ZENX RECOMMENDATION` for ID-D4** — create **`4070 ID Card & Identity Income`** (Revenue), and add
> `'4070' => ['id card', 'identity card']` to the keyword map. The head *starts* with "ID Card
> Replacement", so one keyword matches **every** version — which also solves the exact-match problem
> elegantly. The resolver already guards `isset($coa[$code]) && status === 'active'`, so the keyword is a
> **no-op until the account is deliberately created** — safe to add, but still a decision.
>
> **Why a distinct account:** this is cost recovery for a consumable, not tuition. Folding it into 4010
> inflates fee-collection and tuition-revenue analytics.

**ID-D7 (amounts):** unchanged — per-school, `lost`/`stolen`/`damaged` configurable, **default ₹0**.
`wear`, `data_correction` and `admin` remain **structurally non-chargeable**: a parent must not pay for
normal wear or for the school's own data error. Confirmed by test, even against a config attempting it.

**Part 10 verified behaviourally:** v2 (lost) and v3 (damaged) produce **separate** fee heads and
therefore separate demands; the v2 record survives the v3 replacement intact (`supersededByVersion` set,
original `reason` preserved); a double-click now yields **exactly one** chargeable version.

**Part 11:** no already-fixed Fees defect reopened. `Fee_lifecycle.php` untouched — test-asserted.
**Fees is NOT closed** (UAT not executed).

## 13. FIRESTORE RULES (Part 12) — verified, unchanged

`idCards` remains server-side only (Admin SDK bypasses rules); **deny-by-default is correct and
intentional**; no client consumer exists. No rule written, no index changed. Prerequisite for any future
rule: **reconcile `firestore.rules` with canonical `integration/phase2` first**.

## 14–15. TEACHER APP / PARENT APP

Re-verified unchanged: SRNO correct in both (`AttendanceScreen.kt:865`, `ProfileScreen.kt:335`); no
ID-card management invented; **the secure QR credential is not exposed** to either app. No change.

## 16. ID CARD UAT (Part 12 of brief) — **NOT EXECUTED**

**No browser or device is available to me. No UAT result is manufactured.**

| Class | Cases |
|---|---|
| **STATICALLY VERIFIED** (behavioural tests) | 1–8, 21 (old vs new QR), 22–24 (withdrawal/TC/re-admission), 26 (audit), 30 (fee) |
| **PARTIALLY VERIFIED** (server proven, rendering not) | 9–11 (search), 25 (RBAC), 20 (filtered print) |
| **REQUIRES UAT — browser** | 12–19 (Select All / Clear / Print Selected / zero selection / double-click ×4) |
| **REQUIRES UAT — device + deployment** | 27 (physical scan), 28 (legacy rejection), 29 (V2 acceptance) |

**Cases 12–19 and 27–29 block closure.**

## 17. ATTENDANCE (Part 13) — preserved, not touched

**ATT-WD-1 (HIGH)** — Attendance ignores `Timetable_service.workingDays`. Sunday hardcoded
(`Attendance.php:5039`); Saturday not treated as non-working; a Mon–Fri school counts ~4 Saturdays a
month as working, understating every student's attendance across Admin, Teacher App and Parent App,
silently. A working Sunday cannot be marked at all (`:1441`, `:1558`). **The operator-configured working
days already exist in ZenX and are simply never read.**

**ATT-WD-2 (MEDIUM)** — `schools.attendanceConfig.working_days` is read by `Health_check.php:934` and
displayed by `Attendance.php:611` but **written by nothing**: a permanent false "not configured" alarm.

Not fixed — forbidden this phase, and changing working-day resolution alters historical percentages on
three surfaces. **Carried in §19.**

## 18. EXAMINATION (Part 14) — not started

**EXAM-LC1** remains open. `Roster_helper::for_class()` is camelCase-only, affecting Examination, Result
and Health_check. **Not fixed from this phase.** The Examination phase must begin by tracing the shared
roster helper and every consumer.

## 19. MASTER DEFERRED REGISTER (Part 16)

| ID | Sev | Module | Business impact | Technical impact | Dependency | Next phase | Acceptance |
|---|---|---|---|---|---|---|---|
| **SEC-ID-3 deploy** | HIGH | ID Card | migration cannot start | no evidence exists | **operator authorization** | immediate | `SEC-ID-3` lines visible in production log |
| **SEC-ID-1** | HIGH | ID Card | lost card still scans | authority built, unwired | SEC-ID-3 + reissue distributing V2 | 19L | superseded/revoked rejected; current accepted at all 3 consumers |
| **SEC-ID-2** | HIGH | ID Card | unsigned tokens forgeable | mechanism built, off | zero legitimate legacy usage | 19M | forged unsigned rejected; V2 accepted; no school bricked |
| **SEC-ID-3b** | MED *(raised from LOW)* | ID Card | V1 holders invisible — and V1 is now known to be most of the population | telemetry covers legacy only | SEC-ID-3 deployed first | 19L | V1 usage countable per school |
| **ID-ACCT-1** | MED | Accounting | replacement income posts to **Tuition 4010** | head matches no keyword | **ID-D4** | 19L | posts to a distinct revenue account |
| **ID-D4** | decision | Accounting | income misclassified | — | **user** | — | account created + posting verified |
| **ID-D7** | decision | Fees | no charging possible | — | **user** | — | amounts set per school |
| **ATT-WD-1** | **HIGH** | Attendance | Mon–Fri schools' attendance % understated every month on 3 surfaces | working days hardcoded | ATT-WD-D1 | Attendance closure | Mon–Fri school shows correct % with no manual holidays |
| **ATT-WD-2** | MED | Attendance | permanent false health alarm | dead read | ATT-WD-1 | Attendance closure | health check reflects reality |
| **EXAM-LC1** | **HIGH** | Examination | camelCase-only roster → no marks / report card | shape drift | — | Examination | both shapes return students |
| **LC-1 residual (~10)** | MED | various | rows silently dropped | single-shape predicates | — | per-module | predicates replaced with `_filterSectionRoster` |
| **Fees UAT** | HIGH | Fees | Fees not closable | — | live tenant + operator | — | matrix executed |
| **FEES-D7 backfill** | MED | Fees | historical dueDates absent | — | production credential | — | dry-run reviewed, then run |
| **`studentFeeSummary` reconciliation** | MED | Fees | historical summaries understated | self-heals per student | production credential | — | totals match demands |
| **FEES-D16** | decision | Fees | Transport/Hostel never fined | — | user | — | policy chosen, forward-only cutoff |
| **FEES-D9/D13/D14/D15** | decision | Fees | carried | — | user | — | decided |
| **D19 Bulk Roll Assignment** | MED | Class/Section | manual roll entry | — | user decision | — | bulk assignment available |
| **Existing-roll policy** | MED | Class/Section | ambiguous on re-run | — | user decision | — | policy defined |
| **CAP-2** | MED | Class/Section | concurrent overfill | race | — | Class/Section | concurrent adds cannot exceed capacity |
| **`idCards` rules** | MED | ID Card | none today | server-only | reconcile canonical rules | 19M | rule deployed before any client read |
| **SRNO-3** | LOW | SIS | none | inaccurate comment | — | any | comment matches code |

## 20. RESEARCH (Part 17)

| Finding | Class |
|---|---|
| First issue free; loss chargeable; escalating repeat loss; stolen waived with police report; damaged waived on surrender | `SOURCE-VERIFIED` — **US higher-ed**, cited in 19D |
| **Indian K-12 school-ID replacement norm** | **`NOT ESTABLISHED`** — only CBSE *admit-card* duplicates surface, a board artefact |
| ZenX resolves fee-head → account by keyword, default 4010 | `SOURCE-VERIFIED` (`Operations_accounting:1160-1178`) |
| 4060 Late Fee precedent for head-specific accounts | `SOURCE-VERIFIED` |
| `4070 ID Card & Identity Income` + `'id card'` keyword | `ZENX RECOMMENDATION` |
| Per-reason amounts | **`USER DECISION REQUIRED`** (ID-D7) |
| Ledger account | **`USER DECISION REQUIRED`** (ID-D4) |

**US higher-ed structure is not Indian K-12 policy.** That is why every amount is zero rather than a
plausible-looking number.

## 21. TESTS

| Metric | Value |
|---|---|
| TOTAL TESTS | **1336** |
| TOTAL ASSERTIONS | **3724** |
| BASELINE FAILURES | **10** (Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2) |
| **NEW FAILURES** | **0** |
| NEW TESTS | **19** |
| LINT | clean (5 files) |
| RTDB | clean |
| RULES / INDEXES | unchanged — no emulator run required |

**Not "all tests pass"** — the 10 pre-existing failures remain, unrelated and untouched.

## 22. NEW DEFECTS

**ID-MINT-1 (HIGH) — FIXED** · **ID-RACE-1 (HIGH) — FIXED** · **ID-ACCT-1 (MED) — registered, decision.**
SEC-ID-3b raised **LOW → MED** now that V1 is known to be most of the live population.

## 23. DECISIONS REQUIRED

| ID | Decision | Recommendation |
|---|---|---|
| **SEC-ID-D1** | Authorize deploying SEC-ID-3 | **Yes** — log-only, total rollback. **Nothing downstream can proceed without it** |
| **ID-D4** | Ledger account | create **`4070 ID Card & Identity Income`** + `'id card'` keyword |
| **ID-D7** | Per-reason amounts | per-school; keep defaults ₹0 |
| **ATT-WD-D1** | Should Attendance consume `Timetable_service.workingDays`? | **Yes** — the data exists and is operator-configured |

## 24. CLOSURE GATE (Part 15)

| | Gate | |
|---|---|---|
| ❌ | SEC-ID-3 deployed | **I cannot deploy** |
| ❌ | Production telemetry observed | no evidence exists |
| ❌ | Legacy usage identified | requires telemetry |
| ❌ | Migration completed | requires evidence |
| ❌ | `migrationReadiness` evidence acceptable | requires telemetry |
| ❌ | `validateCredential` wired into all consumers | requires evidence |
| ❌ | Current V2 accepted / superseded rejected / revoked rejected **in production** | requires enforcement |
| ❌ | SEC-ID-2 safely retired | precondition unmet |
| ⏸ | V1 policy explicitly handled | population now known; telemetry pending |
| ⏸ | Replacement fee accounting decision | **ID-D4** |
| ⏸ | Replacement amounts configured | **ID-D7** |
| ✅ | Multiple replacement charges verified | behaviourally |
| ⏸ | Accounting verified | blocked by ID-D4 |
| ❌ | UAT executed | **no browser/device** |
| ❌ | Browser tests passed | as above |
| ❌ | Physical QR scan tested | as above |
| ✅ | SRNO audit clean | re-run this phase |
| ✅ | Teacher App verified | |
| ✅ | Parent App verified | |
| ✅ | No HIGH/CRITICAL **ID Card** defect remains | ID-MINT-1 and ID-RACE-1 fixed this phase |
| ✅ | Firestore-only / RTDB clean | |
| ✅ | Zero NEW regression failures | 1336 / 10 baseline |

# ID CARDS — **NOT CLOSED**

**Blocked on exactly three external things, none of which is engineering:**

1. **Deploy SEC-ID-3** (operator) — gates the entire migration chain.
2. **Execute real UAT** on a browser and a scan device (operator).
3. **Two product decisions** — ID-D4 and ID-D7.

Everything buildable without production evidence is built, and this phase proved that mattered: without
ID-MINT-1's fix the migration would have reported success while every physical card stayed V1.

**I will not mark this CLOSED, and I would not have been able to honestly do so had I skipped the
re-verification the brief demanded.**
