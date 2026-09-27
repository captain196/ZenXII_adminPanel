# PHASE 19E — ID CARD DATA MODEL + CREDENTIAL V2 FOUNDATION
## Implementation Report

**Date:** 2026-08-15 · **Branch:** `integration/phase2`
**Status: READY FOR REVIEW**

Not committed · not pushed · not deployed · no rules changed · no indexes changed · no production write ·
no migration · no UAT claimed · no RTDB.

---

## 1. EXECUTIVE SUMMARY

Phase 19D established that ZenX had an ID-card **printer**, not an ID-card system: no card collection,
no status, no version, no issuance record. The credential was `HMAC(secret, schoolId|studentId)` — both
inputs permanent — so reprinting produced a byte-identical token, a lost card could not be revoked, and a
replacement fee could not honestly be charged.

**19E closes the architectural gap without changing a single runtime behaviour.**

Two additions:

1. **Credential V2** — `"{schoolId}|{studentId}|v{N}|{sig}"`, with the version inside the **signed
   material**. A specific card becomes retirable.
2. **`Id_card_service`** — the one sanctioned path for `idCards`: versioned issuance, supersede, revoke,
   history, and a school-wide register that costs **one query, not N**.

**Nothing is enforced.** No QR consumer calls the new predicate; a test asserts that. Legacy unsigned and
V1 signed tokens decode exactly as before, and a V2 token is accepted everywhere V1 is — including by
Transport's fail-closed check — because it reads as non-legacy. Enforcement is 19G, after reissue.

**Regression: 1185 tests / 3352 assertions / 10 failures — the identical pre-existing baseline.**
57 new tests, all passing. Lint clean. RTDB scan clean.

---

## 2. FILES MODIFIED

| File | Change | Prior state |
|---|---|---|
| `application/helpers/qr_token_helper.php` | V2 encode/sign + 4-part decode; docblocks corrected | **was clean** — no overlap |
| `application/libraries/Id_card_service.php` | **NEW** — card lifecycle service | new file |
| `tests/Unit/Phase19ECredentialV2Test.php` | **NEW** — 21 tests | new file |
| `tests/Unit/Phase19EIdCardModelTest.php` | **NEW** — 36 tests | new file |

## 3. FILES INTENTIONALLY NOT MODIFIED

| File | Why |
|---|---|
| `Attendance.php`, `Transport_qr_attendance_service.php`, `Visitor_service.php` | Enforcement is 19G. A test asserts none of them references `Id_card_service` or `isCurrentVersion` |
| `Fee_lifecycle.php` | Charging is 19I. A test asserts the service contains no `createModuleFee`/`feeDemands` |
| `views/sis/id_card.php` | Still mints V1. Switching the printed card is 19G's migration step |
| `Sis.php` | Controller wiring is 19F |
| **`firebase-rules/firestore.rules`** | Forbidden this phase — **and already modified in the working tree by other work** (§11) |
| **`firebase-rules/firestore.indexes.json`** | Same — and the design needs no new index (§12) |
| `MY_Controller.php`, `rbac_helper.php`, `Firestore_service.php` | Dirty from prior phases; 19E needs no change to them |

### Working-tree overlap — reported as Part 0 requires

`firestore.rules`, `firestore.indexes.json`, `rbac_helper.php` (staged **and** unstaged),
`MY_Controller.php` and `Firestore_service.php` all carry uncommitted changes from earlier phases.
**None was touched.** `qr_token_helper.php` was verified clean before editing.

---

## 4. DATA MODEL

Phase 19D's proposal was reviewed against the code before adoption, and **implemented as proposed** with
one qualification: `expired` is a valid status but is never set — no ZenX or Indian K-12 evidence that
cards expire (decision ID-D8 stands).

**Two shapes, one collection** — the same two-tier pattern `Sr_no_service` uses for `srNoIndex` and
`Id_generator` for the STU counter, reused rather than reinvented:

```
idCards/{schoolId}_{studentId}          kind:'current'   ← pointer, fast lookup
idCards/{schoolId}_{studentId}_v{N}     kind:'version'   ← permanent issuance record
```

The **version record is authoritative**; the pointer is a cache. A pointer write failing can therefore
leave a stale stamp, never a second active card.

| Field | Where | Note |
|---|---|---|
| `schoolId`, `studentId` | both | tenant + FK |
| `currentVersion` / `version` | pointer / version | |
| `status` | both | `active` \| `replaced` \| `revoked` \| `expired` |
| `reason` | both | `first_issue` \| `lost` \| `stolen` \| `damaged` \| `wear` \| `data_correction` \| `admin` |
| `event` | version | `issued` \| `replaced` \| `revoked` \| `reprinted` |
| `supersedesVersion` / `supersededByVersion` | version | the chain |
| `issuedAt/By`, `replacedAt/By/Reason`, `revokedAt/By/Reason` | version | who and why |
| `credentialType` | both | `v2` |
| `srNo`, `admissionNo`, `studentName`, `className`, `section` | both | **denormalised for display/print/search only** |
| `createdAt`, `updatedAt` | both | |

**`rollNo` is deliberately absent** — it is session/class-scoped and reassigned on promotion, so it must
never become card identity. A test enforces that it cannot be written even if a caller passes it.

**Status vs reason vs event is strictly separated.** Modelling `lost`/`stolen`/`damaged` as *statuses* is
the common design error — they describe **why** a card left service, not what state it is in. A lost card
and a damaged card are both simply `replaced`. A test pins this.

### Concurrency

A version is claimed with `firestoreCreate` — atomic create-if-not-exists, the **same primitive**
`Id_generator`, `Sr_no_service`, `Apaar_service` and `Admission_no_service` already rely on. Two
operators issuing at once both try `_v3`; exactly one create succeeds, the loser probes `_v4`. There is
no read-then-write race and therefore **no way to produce two active cards** — enforced structurally, not
by a read-check. Tested.

---

## 5. CREDENTIAL V2 DESIGN

```
V2      base64url("{schoolId}|{studentId}|v{N}|{sig}")
        sig = first 16 hex of HMAC-SHA256(secret, "{schoolId}|{studentId}|v{N}")
V1      base64url("{schoolId}|{studentId}|{sig}")            unchanged
legacy  base64url("{schoolId}|{studentId}")                  unchanged, still accepted
```

The version is stamped `v{N}` so a 4-part payload can never be confused with a 3-part one and the segment
is self-describing. **The version is signed, not merely carried** — editing it invalidates the signature
rather than silently promoting a superseded card. That single property is what makes revocation real, and
it has its own test.

**`cardVersion` is `null` for V1 and legacy, never `1`.** Those tokens name *no card*; defaulting to 1
would make an unmanaged card look like version 1 of a managed one.

---

## 6. LEGACY / V1 / V2 COMPATIBILITY

| Token | Decodes? | `legacy` | `tokenVersion` | `cardVersion` |
|---|---|---|---|---|
| Legacy 2-part | ✅ | `true` | `legacy` | `null` |
| V1 signed | ✅ | `false` | `v1` | `null` |
| V2 versioned | ✅ | `false` | `v2` | N |
| Tampered / malformed | ❌ null | — | — | — |

**The `legacy` contract every consumer depends on is unchanged**, which is what makes this additive:

- `Attendance::scan_qr` — accepts all three; SEC-ID-3 telemetry still fires only for legacy.
- `Transport_qr_attendance_service` — rejects legacy; **accepts V2** because it reads non-legacy.
- `Visitor_service` / `Campus_access` — unchanged.

A dedicated test asserts all three behaviours, so a future edit cannot silently break V1 or legacy.
Also tested: a **V1 signature cannot be replayed as V2** — the two functions sign different material.

---

## 7. SECURITY MODEL

| Property | Status | Test |
|---|---|---|
| Valid V2 accepted | ✅ | ✅ |
| Bad signature rejected | ✅ | ✅ |
| Altered `studentId` rejected | ✅ | ✅ |
| Altered `schoolId` rejected | ✅ | ✅ |
| **Altered `cardVersion` rejected** | ✅ | ✅ |
| Cross-student transfer | ❌ blocked | ✅ |
| Cross-tenant transfer | ❌ blocked | ✅ |
| Version 0 / non-numeric / 5-part | ❌ rejected | ✅ |
| One active card per student | ✅ structural | ✅ |
| Superseded version is not current | ✅ | ✅ |
| **SEC-ID-2 (unsigned accepted)** | **still open — deliberately** | ✅ |

SEC-ID-2 remains open because closing it now would brick every printed card. 19E gives it the *mechanism*
for a safe close (per-student reissue); 19J performs it by measured evidence.

---

## 8. RBAC

**No RBAC change in this phase** — the service exposes no HTTP surface, so there is nothing to gate yet.
`_require_role()` was re-verified (`MY_Controller.php:1667`): SA bypass → `has_permission($module,$level)`
→ holding the module at a lower level is an authoritative **deny** → legacy role-name allow-list.

For 19F, the controller endpoints must use `_require_role([], 'id_card_issue', 'SIS', 'manage')` — the
empty allow-list makes graded capability the sole authority and creates **no legacy role-name bypass**.
Printing stays at `SIS/view`. **ID-D6 (issue/revoke = `SIS/manage`) is carried into 19F.** Configuration
is *not* used for a student-lifecycle operation.

---

## 9. AUDIT LOGGING

`log_audit()` was re-verified (`audit_helper.php:82-86`): from `$context` it persists **only** `appId`,
`entityType` and `meta` — every other top-level key is **silently discarded**. `issue()` and `revoke()`
both nest their payload under `meta` and set `entityType: 'idCard'`. No parallel audit store.

**How this is tested, and why not by capturing calls.** This repo has no live Firestore in tests, and by
convention (`tests/Support/audit_helper_stub.php`) `log_audit()` runs for real and degrades to a no-op via
its own `!isset($CI->fs)` exit — so there is nothing to capture. Audit is therefore asserted
**structurally**: the test parses the method bodies (stripping comments first) and fails if any top-level
context key falls outside the persisted whitelist. That is precisely the defect this programme hit in
Phase 16.8, where a flat rollover payload persisted nothing.

> **A detour worth recording.** I first wrote a `log_audit` stub to capture calls. It never fired. The
> cause: PHPUnit loads *every* file in `tests/Unit` to discover tests, and five existing tests already
> require the real `audit_helper.php` at file level — so my `function_exists` guard correctly declined to
> define a stub, and the real function swallowed its own failure. A stub registered inside
> `setUpBeforeClass` can never win that race. I deleted my redundant stub rather than leave a
> dead file that looks functional, and adopted the repo's existing convention.

---

## 10. FIRESTORE READ / WRITE BUDGET

| Operation | Reads | Writes |
|---|---|---|
| `currentCard()` / `currentVersion()` / `isCurrentVersion()` | **1 get** | 0 |
| `historyFor(student)` | 1 + V gets (V = that student's versions, typically ≤ 5) | 0 |
| `registerForSchool()` | **1 query, any N** | 0 |
| `issue()` | 1 get | 1 create + 1 supersede + 1 pointer = **3** |
| `revoke()` | 1 get | 2 |

**The existing ID-card page is unchanged: ~1 cached snapshot read + 1 school doc, QR rendered on local
CPU.** When 19H adds the register it costs **+1 query**, joined against the cached student snapshot in
PHP — never 800 reads for 800 students. A test proves 25 students still cost exactly one query.

Scales to 5,000+ students: the register is one query whose result is filtered in PHP; the hot scan path
(19G) is a single point-get by constructed doc id, with no query and no index.

---

## 11. RULES FINDINGS — research only, nothing modified

`idCards` is a **new collection with no rule**, so it is currently deny-by-default. That is safe today
(all access is server-side via the Admin SDK, which bypasses rules) but must be addressed before any
client reads it.

Required when 19K/Parent-App card status is built:

```
match /idCards/{docId} {
  allow read:  if isSameSchoolRead() && (isStaff() || isOwnStudentParent());
  allow write: if false;   // server-only — this service is the sole writer
}
```

**Not written.** `firestore.rules` is **already modified in the working tree** by other work; per the
repo's pull-first rule a deploy ships the whole file, so touching it here would risk shipping someone
else's half-finished change. **Prerequisite: reconcile `firestore.rules` with the canonical branch
first.**

## 12. INDEX FINDINGS — none required

The query shapes were chosen so **no composite index is needed**:

- `registerForSchool()` filters `schoolId` equality only — served by Firestore's automatic single-field
  index. `kind` and `status` are filtered **in PHP on purpose**; adding a second equality predicate would
  demand a composite index this phase is not permitted to create.
- Every other read is a point-get by constructed doc id.

**`firestore.indexes.json` is also dirty in the working tree** — a second reason it was left alone.

---

## 13–15. ADMIN / TEACHER APP / PARENT APP AUDIT

**Admin Panel** — ID Cards is already a top-level nav item (`header.php:684`, *Student Info System → ID
Cards*), plus `sis/index.php:89` and `sis/students.php:271`. It renders, filters by class/section, and
prints one/filtered/all. Still missing: register, status, history, revoke, reissue, reason capture,
SR No./Adm No. search. **19F/19H.**

**Teacher App** — re-verified: SRNO displayed (`AttendanceScreen.kt:865`, *"Roll X · SR Y"*); **no QR or
ID-card symbol exists**. 19D's classification **NOT REQUIRED / FUTURE** is confirmed. No teacher QR
scanning was invented.

**Parent App** — re-verified: SRNO displayed (`ProfileScreen.kt:335`). **No ID-card feature.**
`StudentIdCard` (`DashboardScreen.kt:801`) is a gradient **welcome banner** — name, class, school,
today's status — not a credential. 19D's classification is confirmed; no digital credential was
invented, and it stays **NOT RECOMMENDED** (a screenshot-able credential undermines revocation).

**Neither app requires any change for 19E.** The card model is server-side only.

---

## 16. STUDENT LIFECYCLE COMPATIBILITY

| Transition | Student ID | SRNO | Adm No. | Roll No. | Credential | Card |
|---|---|---|---|---|---|---|
| Admission | new | allocated | allocated | assigned | v1 on first issue | `active` v1 |
| Promotion | same | **preserved** | same | reassigned | **unchanged** | unchanged |
| Session rollover | same | **preserved** | same | reassigned | **unchanged** | unchanged |
| Section transfer | same | **preserved** | same | may change | **unchanged** | unchanged |
| Lost → replacement | same | preserved | same | — | **new (v+1)** | old `replaced` |
| Withdrawal / TC | same | preserved | same | — | dead via Active gate | revoke in 19F |
| Re-admission | **new** | **preserved via `relink()`** | new | new | **new identity** | fresh v1 |

Neither class nor section is signed into the credential, so **promotion and section transfer cannot
invalidate a card** — tested. Re-admission mints a new `studentId` and therefore a different credential,
while `Sr_no_service::relink()` preserves SRNO separately — also tested. **No promotion, transfer,
withdrawal or re-admission logic was altered.**

---

## 17. FEES DEPENDENCY — no charge created

`Fee_lifecycle.php` was **not modified**, and the service contains no `createModuleFee` or `feeDemands`
reference (test-asserted).

19D's constraint re-verified at source (`Fee_lifecycle.php:999`): `periodKey` is hardcoded to
`{sessionYear}-04`, so `DEM_{studentId}_{YYYY}04_{HEAD}_{MODULE}` yields **one demand per student per
head per session**. A naive second replacement in one session would rewrite the first demand rather than
add — and if the first was paid, recompute to `balance 0, status paid`, giving a **free second card with
a ledger saying it was paid**.

The foundation supports the safe design without touching `Fee_lifecycle`: `version` is now a first-class
property, so 19I can scope the fee head per card (`"ID Card Replacement (Card v3)"`), which `_demandId`
already derives its key from. `chargeableReasons()` ships as a **classification only** — lost/stolen/
damaged; first issue, wear, data correction and admin are excluded because the first card and the
school's own errors are not the family's fault. **No amount, no policy, no charge.** → **19I (ID-D2/D4/D7).**

---

## 18. SRNO MANDATORY AUDIT

Canonical model re-verified against `Sr_no_service.php`: permanent, school-scoped, session-independent,
preserved across promotion / transfer / re-admission, never a foreign key.

### In the new card model

| Aspect | Status |
|---|---|
| Denormalised onto card + pointer | ✅ for display/print/search |
| **Used as a key?** | ❌ **never** — cards key on `studentId`; test asserts no `{schoolId}_{srNo}` doc |
| Admission No. carried | ✅ alongside SRNO |
| Roll No. stored | ❌ **rejected by design**; test asserts it cannot be written |
| Survives reissue | ✅ carried to every new version |
| In the credential | **correctly absent** — a credential binds the internal FK, not the display identity |

The `STU0012→SR 47 / STU0047→SR 12` swap fixture is used to prove the two never cross-contaminate.

### Part 18 closure table

| Surface | SRNO | Student ID | Admission No. | Roll No. |
|---|---|---|---|---|
| Admin ID Card page | ✅ shown · ❌ **not searchable (SRNO-4)** | ✅ shown + searchable | ❌ absent | ✅ shown |
| Student Record | ✅ | ✅ | ✅ | ✅ |
| **Printed ID Card** | ✅ present but **secondary (SRNO-5)** | ⚠️ **styled as primary identity** | ❌ **absent** | ✅ correct |
| Attendance | n/a | ✅ FK (correct) | n/a | n/a |
| Teacher App | ✅ | internal | n/a | ✅ |
| Parent App | ✅ | internal | n/a | n/a |
| TC | ✅ (added 19C) | n/a | ✅ (fixed 19C) | ✅ correctly not identity |
| Receipts | ✅ | ✅ | n/a | n/a |
| Roster | ✅ | ✅ | ✅ | ✅ |
| **idCards (new)** | ✅ denormalised, not FK | ✅ FK (correct) | ✅ | ❌ correctly absent |

**Flags:** SRNO-4 and SRNO-5 remain open. Both are **UI/print-surface** changes and Part 3 forbids UI work
this phase — carried to **19H** with acceptance conditions (§23). No new SRNO defect was introduced, and
no place was found where SRNO is used as a foreign key.

---

## 19–22. TESTS · LINT · RTDB · BASELINE

**57 new tests, 152 assertions, all passing** — 21 credential + 36 model. Coverage against Part 13's list:
all 18 required cases plus malformed/5-part/version-0/clamping, concurrency, register cost, tenant
isolation, audit shape, and no-fee/no-RTDB guards.

| Check | Result |
|---|---|
| `php -l` (4 files) | ✅ clean |
| Full suite | **1185 tests / 3352 assertions / 10 failures** |
| Baseline | **10 pre-existing failures, identical set** — no new failure |
| RTDB scan | ✅ clean |
| Helper visibility | ✅ all public/instance methods verified before use |
| Comments vs behaviour | ✅ docblocks corrected to match the new return shape |
| **Test scanners matching own comments** | ✅ guarded — the audit scan strips comments via `token_get_all()` before matching (the exact trap hit in 19C) |
| Consumers untouched | ✅ test asserts no `Id_card_service`/`isCurrentVersion` in the three QR consumers |

---

## 23. DEFERRED WORK REGISTER

| ID | Sev | Location | Reason | Dependency | Phase | Acceptance condition |
|---|---|---|---|---|---|---|
| **SEC-ID-2** | HIGH | `qr_token_helper.php` legacy branch | closing now bricks every printed card | reissue coverage | **19J** | card register reports zero legacy holders; then acceptance flips off |
| **SEC-ID-1** | HIGH | credential | mechanism now exists; enforcement not enabled | reissue | **19G** | superseded/revoked version rejected at every consumer; current accepted |
| **SEC-ID-3 deploy** | — | 19C, written | not deployed → still zero telemetry | authorization | **immediate** | legacy-usage events visible in the production log |
| **ID-D2** | HIGH (design) | `Fee_lifecycle.php:999` | not a live defect — nothing charges cards | ID-D2/D4/D7 | **19I** | 2nd replacement in one session raises a 2nd distinct demand; repeat clicks idempotent |
| **SRNO-4** | MED | `id_card.php:205,422` | UI — forbidden this phase | workspace | **19H** | operator finds a student by SR No. **and** Adm No. |
| **SRNO-5** | MED | `id_card.php:330-334` | printed-artefact layout is a school decision | **ID-D5** | **19H** | SR No. primary, Adm No. present, Student ID de-emphasised |
| **ID-D6** | MED | `Sis.php:3341` | no HTTP surface yet | — | **19F** | issue/revoke require `SIS/manage`; print stays `view` |
| Rules for `idCards` | MED | `firestore.rules` | file dirty; forbidden | reconcile with canonical | **19G/19K** | server-only write rule deployed before any client read |
| Card status UI | — | Admin | UI forbidden | model | **19H** | register with status filters + history |
| Withdrawal → auto-revoke | LOW | lifecycle | needs the 19F operation | `revoke()` | **19F** | TC/withdrawal revokes the active card |
| SRNO-3 | LOW | `Sr_no_service.php:264` | wrong comment, no behaviour impact | — | any | comment matches code |
| FEES-D16 / D7 / UAT | — | Fees | decision / credential / tenant | — | — | unchanged |
| EXAM-LC1, LC-1 residual | HIGH/MED | other modules | one-module-at-a-time rule | — | own phases | unchanged |

## 24. NEW FINDINGS

1. **`log_audit` stub race (test infrastructure).** PHPUnit loads every test file to discover tests, so a
   stub registered in `setUpBeforeClass` loses the `function_exists` race to files that require the real
   helper at file level. Resolved by adopting the repo's existing convention. Recorded because it will
   silently mislead anyone who tries to assert audit calls in this suite.
2. **No new production defect was found in 19E.** The 19D findings were all re-verified as still true.

## 25. UAT REQUIREMENTS — not executed, not claimable

Needs a live tenant, a printer and a physical scan device: print a V2 card and scan it at attendance;
confirm a V1 card still scans; confirm a legacy card still scans **and now appears in the log**; issue a
replacement and confirm a new credential; confirm the old card still works **(expected in 19E — version
enforcement is off)**; confirm one active card per student in Firestore.

## 26. NEXT PHASE

**Deploy SEC-ID-3 first** — it is written, it changes no behaviour, and without it the migration has no
evidence. Then **19F**: controller endpoints for issue / reissue / revoke with reason capture, `SIS/manage`,
withdrawal→revoke, and V2 minting on the printed card behind a flag.

**19E is READY FOR REVIEW. ID Cards are NOT closed. Fees are NOT closed.**
