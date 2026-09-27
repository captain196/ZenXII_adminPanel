# PHASE 19I/19J — ID CARD SECURITY + REPLACEMENT FEE COMPLETION REPORT

**Date:** 2026-08-15 · **Branch:** `integration/phase2`
**Status: READY WITH CONDITIONS — ID Cards NOT CLOSED · UAT NOT EXECUTED**

Not committed · not pushed · not deployed · no rules/indexes changed · no Attendance/Examination code
changed · `Fee_lifecycle.php` untouched · no RTDB.

---

## 1. EXECUTIVE SUMMARY

**19J (legacy retirement) cannot be completed, and I will not pretend otherwise.** Its own precondition —
"zero legacy holders" — is unknowable today, because SEC-ID-3 telemetry is undeployed and **no production
evidence of legacy card circulation exists**. Enforcing now would reject every legitimate holder on the
first scan.

So this phase built **everything that can be built safely**, all of it inert by default:

| Delivered | State |
|---|---|
| **`validateCredential()`** — ONE canonical credential authority | built, wired **nowhere**, flags OFF |
| **`migrationReadiness()`** — evidence report replacing a guessed deadline | built |
| **`id_card_flags.php`** — `ENFORCE_V2` / `REJECT_LEGACY` / `REJECT_V1`, separately flippable | all **false** |
| **`Id_card_fee_policy`** + wiring — replacement charging via the canonical generator | built, **all amounts 0.0** |
| **ATT-WD-1 / ATT-WD-2** — Attendance working-day defects | **found, registered, not fixed** (Part 17) |

**Regression: 1317 tests / 3678 assertions / 10 failures — the identical pre-existing baseline.**
36 new tests. Lint clean. RTDB clean.

**Two new Attendance defects found** — one of them a dead setting with real, silent business impact (§19).

---

## 2. PREVIOUS PHASE VERIFICATION (Part 1)

Re-verified against source, not the reports: Credential V2 exists and is version-bound;
`Id_card_service` provides issue/supersede/revoke/history/register; V2 is **not** enforced; legacy and V1
still accepted; SEC-ID-3 present in `Attendance.php:4282` and `Campus_access.php:307` at level `error`
but **undeployed**; the 19H workspace is intact. **All 19H claims held.**

## 3. UAT STATUS (Part 3) — **NOT EXECUTED**

**No browser or device is available to me.** I did not execute UAT and claim none. Of the 30 cases:

| Class | Count | Cases |
|---|---|---|
| **STATICALLY VERIFIED** (logic proven by behavioural tests) | 12 | 1–8 (issue/replace/revoke/re-revoke/history), 23 (old vs new card), 24 (withdrawal/TC), 25 (re-admission), 29 (audit shape) |
| **PARTIALLY VERIFIED** (server side proven; rendering unproven) | 8 | 9–10 (history states), 11–13 (search matching), 28 (RBAC gate), 22 (print modes) |
| **REQUIRES REAL BROWSER** | 8 | 14–18 (Select All/Clear/Print Selected/zero-selection), 19–21 (double-click guards) |
| **REQUIRES DEVICE / DEPLOYMENT** | 2 | 27 (physical QR scan), 30 (**legacy telemetry — blocked on deployment**) |

**Blocks closure:** cases 19–21 and 27 and 30. Everything else is either proven or proven server-side.

## 4. SRNO MANDATORY AUDIT (Part 2) — re-run from source

Not inherited. Four sweeps repeated:

- **A. Roll-No substitution** — regex for an "Adm No."/"SR No." label near a `rollNo` value across all
  views and controllers. Two hits, both benign (the SRNO-1 fix comment; a `Fees.php` presence-check).
  **No substitution defect.**
- **B. SRNO as key/FK** — `docId($srNo)`, `docId2($srNo)`, `'studentId' => $srNo`, `where('srNo')`
  outside `Sr_no_service`: **zero hits.**
- **C. Lifecycle mutation** — `execute_promotion`, `transfer_students`, `rollover_session`: **zero
  `srNo` references.** Preservation is structural.
- **D. Writers** — all enumerated and legitimate.

**New this phase:** the fee payload and `Id_card_fee_policy` carry **no identity at all** — they key on
`studentId` + card version. The replacement demand therefore cannot introduce an identity substitution.

| Check (A–P) | Result |
|---|---|
| A. Roll No. never replaces SRNO | ✅ |
| B. Student ID never replaces SRNO where permanent identity is expected | ✅ (card hierarchy fixed 19F) |
| C. SRNO never a Firestore doc ID | ✅ |
| D. SRNO never a FK | ✅ |
| E/F/G. Promotion / transfer / rollover preserve SRNO | ✅ zero references |
| H. Re-admission follows canonical policy | ✅ `relink()` |
| I. ID Card displays + searches SRNO | ✅ primary + searchable |
| J. TC uses SRNO/Adm No. correctly | ✅ fixed 19C |
| K. Fees uses SRNO correctly | ✅ receipt SR No. + Roll No., separately labelled |
| L. Attendance does not substitute Roll No. | ✅ keyed on `studentId` |
| M/N. Teacher / Parent App | ✅ SRNO correct |
| O. Imports/exports preserve SRNO | ✅ own column |
| P. Receipts/certificates/reports | ✅ |

**SRNO audit CLEAN. No defect found, none omitted.**

## 5. SEC-ID-3 TELEMETRY (Part 4)

| Question | Answer |
|---|---|
| Code path | `Attendance::scan_qr` (`:4282`), `Campus_access::scan_qr_lookup` (`:307`) |
| Log level | `error` — the ONLY level written at `log_threshold=1` (`system/core/Log.php:106`) |
| Message | `SEC-ID-3 … legacy unsigned token used for {schoolId}/{studentId} (reissue this student's ID card…)` |
| Legacy actually recorded? | **Yes, once deployed** — the branch fires on `$decoded['legacy']` |
| School-identifiable? | ✅ `schoolId` in the message |
| Student traceable? | ✅ `studentId` — enough to reissue that exact card |
| Distinguishes legacy / V1 / V2? | **Partially — see the gap below** |
| Identifies which schools/students still use legacy? | ✅ for legacy; ❌ V1 is invisible |
| Persisted under production config? | ✅ `error` ≥ threshold 1 |
| Credential material logged? | ❌ never — no token, signature or secret |

> **Gap found and registered (SEC-ID-3b, LOW).** Telemetry fires only for `legacy`. A **V1** card — signed
> but un-versioned — logs nothing, so the log alone cannot tell you how many V1 holders remain before
> flipping `IDCARD_REJECT_V1`. Not fixed here: adding a V1 line would change deployed behaviour in the
> same step that is meant to be pure observation, and V1 retirement is a later, lower-urgency milestone
> than unsigned-legacy retirement. Registered with an acceptance condition (§24).

### Deployment checklist — **I am not authorized to deploy**

**SEC-ID-3 is code-complete and requires controlled deployment.**

- **Files:** `application/controllers/Attendance.php`, `application/controllers/Campus_access.php`
- **Changes:** log output only.
- **Does NOT change:** credential acceptance (legacy ✅ V1 ✅ V2 ✅), card behaviour, data, schema, rules,
  indexes, config.
- **Verify:** `grep "SEC-ID-3" application/logs/log-YYYY-MM-DD.php` on the Ohio box.
- **Rollback:** revert two files. Nothing written, nothing migrated — rollback is total.
- **Observation window:** at least one full attendance cycle in which **every** student is expected to
  scan at least once. **No deadline is invented** — the window is defined by coverage, not the calendar.
- **Evidence required before migration:** the set of `{schoolId, studentId}` pairs appearing in
  SEC-ID-3 lines, cross-referenced against `migrationReadiness()`.
- **Readiness for SEC-ID-1/2:** §6.

## 6. MIGRATION READINESS (Part 5)

```
LEGACY (today)
  → deploy SEC-ID-3 ....................... observation only, zero risk
  → observe ............................... until scan coverage is complete
  → identify legacy holders ............... from the log; the register CANNOT know this
  → reissue via the 19F/19H workspace ..... Replace, reason=admin, fee 0 by default
  → migrationReadiness() → neverIssued 0 .. server-side proof every student has a card
  → IDCARD_ENFORCE_V2 = true .............. superseded/revoked cards start failing
  → observe again ......................... confirm no legitimate rejections
  → IDCARD_REJECT_LEGACY = true ........... SEC-ID-2 closes
  → (later, separately) IDCARD_REJECT_V1
```

**What an administrator actually does:** open ID Cards → filter card status **"No card issued"** → the
remaining list *is* the migration backlog → Issue/Replace each → the filter empties → flip one flag.

> **`migrationReadiness()` states its own limit.** It returns `legacyHoldersKnown: false` and a note
> pointing at SEC-ID-3. The server can only know who has been **issued** a card; it cannot know what card
> a family physically carries. Reporting only the flattering number would have made the gate look
> satisfiable when it is not.

## 7. SEC-ID-1 ENFORCEMENT (Part 6) — built, **OFF**

`Id_card_service::validateCredential($decoded, $flags)` is the single authority. Behaviourally tested:

| Input | Flags off | `enforceV2` |
|---|---|---|
| Current V2 | accept `v2_unenforced` | accept **`v2_current`** |
| **Superseded V2** | accept | **reject `superseded`** |
| **Revoked card** | accept | **reject `no_active_card`** |
| **No managed card** | accept | **reject `no_active_card`** |
| V1 | accept | accept (unaffected — names no version) |
| Legacy | accept | accept (unaffected) |
| **Cross-tenant** | **reject** | **reject** (no flag can disable it) |
| Malformed / unknown version | reject | reject |

Design points that matter:
- **`enforceV2` cannot retire V1 or legacy.** Those name no card version; only their own flags retire
  them. Conflating them would force a school to reprint cryptographically sound cards.
- **Fails closed** on every unknown state.
- **Zero reads while off** — the flags short-circuit before Firestore (test-asserted). One point-get per
  V2 scan when on. Never a query, never an index.
- Student status, transport eligibility and RBAC stay with their consumers — those are domain rules, not
  credential rules.

**Wired into no consumer.** A test asserts `validateCredential` appears in none of the three.

## 8. SEC-ID-2 RETIREMENT (Part 7) — **NOT DONE, correctly**

Every precondition is unmet: legacy holders unknown, printed-card circulation unknown, no observation
performed. The **mechanism** is built and tested (`rejectLegacy` → `legacy_rejected`) and ships **off**.

Emergency recovery, should a flip go wrong: set the flag back to `false` — one config value, no data
migration, instant.

## 9. CREDENTIAL CONSUMER MATRIX (Part 6)

Three runtime consumers (`Campus_access_verifier` is a self-test harness; `Sis::qr_image` renders pixels).

| | Attendance `scan_qr` | Transport F14 | Campus Access lookup |
|---|---|---|---|
| Signed accepted | ✅ | ✅ | ✅ |
| Legacy accepted | ✅ | ❌ rejects | ✅ |
| V1 / V2 accepted | ✅ / ✅ | ✅ / ✅ | ✅ / ✅ |
| Current version checked | ❌ | ❌ | ❌ |
| Revoked / replaced rejected | ❌ | ❌ | ❌ |
| Inactive student rejected | ✅ | via eligibility | pass must exist |
| Wrong student | ✅ (HMAC) | ✅ | ✅ |
| Wrong school | ✅ | ✅ | ✅ |
| Malformed rejected | ✅ | ✅ | ✅ |
| Unsigned rejected | ❌ | ✅ | ❌ |
| **Uses canonical authority** | **not yet** | **not yet** | **not yet** |

**The three "not yet" cells are the remaining SEC-ID-1 work** — one wiring change each, once evidence
exists. The policy now lives in one place so those become three call sites, not three policies.

## 10. ID CARD WORKSPACE (Part 8)

Re-verified intact from 19H: four-field search, three filters, issue/replace/revoke/history, four print
modes, Bootstrap 3 modals, no `alert`/`prompt`/`confirm`, loading states, duplicate-click guards,
truthful success/error, retry, no fake success, no empty-success on malformed responses. **No UI gap
found this phase, so nothing to fix.** Not duplicated into Student Record.

## 11. REPLACEMENT FEE ARCHITECTURE (Parts 9–10)

**No parallel fee system.** `Id_card_fee_policy` decides *whether*, *how much*, *under which head*; the
canonical `Fee_lifecycle::createModuleFee()` writes the demand, exactly as Transport and Hostel do.
**`Fee_lifecycle.php` is unmodified** — a test asserts no ID-card token leaks into it.

### ID-D2 solved — verified at source, not assumed

`Fee_lifecycle.php:999` hardcodes the period to `{sessionYear}-04`, so the demand id is
`DEM_{studentId}_{YYYY}04_{HEAD}_{MODULE}`. A shared head would make a **second replacement rewrite the
first** — and if the first was paid, recompute to `balance 0 / status paid`, giving a **silently free
second card with a ledger claiming payment**.

Because `_demandId()` derives its key from the **fee head**, the card version goes in the head:

```
"ID Card Replacement (Card v2)" → DEM_STU0012_202604_ID_CARD_REPLACEMENT_CARD_V2_IDCARD
"ID Card Replacement (Card v3)" → DEM_STU0012_202604_ID_CARD_REPLACEMENT_CARD_V3_IDCARD
```

Two properties fall out: **idempotency** (a double-click hits the same id and rewrites identical values —
never a duplicate charge) and **legibility** (the parent's receipt names the card). Both tested.

### Policy

**Every amount defaults to 0.0, and zero means no demand is raised at all.** Per-school overrides live in
Firestore `feeSettings/{school}_{session}_idcard` so a school is configurable without a deploy.

| Reason | Chargeable? | Default |
|---|---|---|
| `first_issue` | **never — structurally excluded** | — |
| `lost` / `stolen` / `damaged` | yes, if configured | **0.0** |
| `wear` / `data_correction` / `admin` | **never** | — |

`wear` and the school's own corrections are not the family's fault; `first_issue` is part of admission
and has no key that could make it chargeable by accident (test-asserted, even with a config trying).

**The operator cannot manipulate fee eligibility:** the reason is a controlled enum validated server-side
against `Id_card_service::validReasons()`, and the free-text note is stored separately and never read by
the fee path.

Charging is **best-effort after issuance** — a credential is a child's identity and must never be
withheld because a receivable could not be written. A failure leaves an uncharged replacement
(recoverable); aborting would leave a child without a card (not).

## 12. ACCOUNTING (Part 11) — **DECISION REQUIRED, nothing hardcoded**

No account is hardcoded and no journal is posted by this code. The demand flows through the existing
`feeDemands` → receipt → ledger chain, so posting happens only when a school configures a non-zero
amount **and** a payment is collected.

`SOURCE-VERIFIED`: `Operations_accounting.php:1403-1414` posts late fees to a distinct account
(**4060 Late Fee**), so head-specific mapping already exists as a precedent.

> **ID-D4 remains open and I have not invented an answer.** An ID-card replacement charge is *cost
> recovery for a consumable*, not tuition. Folding it into general fee income would inflate
> fee-collection analytics. **Recommendation: a distinct revenue account.** Until it is chosen, the
> zero-default means nothing posts — the system is safe, not silently wrong.

## 13. FIRESTORE RULES (Part 12) — **verified, unchanged**

`idCards` is written and read **only** server-side via the Admin SDK, which bypasses rules. No client
reads it. **Deny-by-default is therefore correct and intentional**, and exposing the collection now would
add attack surface for no consumer. **No rule written, no index changed.**

`firestore.rules` and `firestore.indexes.json` remain dirty from earlier unrelated work. **Prerequisite
for any future rule: reconcile with canonical `integration/phase2` first** — a deploy ships the whole
file. Required rule when 19K/Parent card status arrives: `allow read` for same-school staff and the
student's own parent; `allow write: if false`.

## 14–15. TEACHER APP / PARENT APP (Part 13)

**Teacher App** — SRNO correct (`AttendanceScreen.kt:865`). No ID-card management invented; none exists.
**Parent App** — SRNO correct (`ProfileScreen.kt:335`). `StudentIdCard` remains an informational banner.
**The secure QR credential is NOT exposed** to either app — a screenshot-able credential is weaker than
paper and would undermine the revocation this programme just built. No change to either app.

## 16. CROSS-MODULE LIFECYCLE (Part 14)

| Transition | Identity | SRNO | Status | Class/Section/Roll | Fees | Card | Attendance |
|---|---|---|---|---|---|---|---|
| Admission | new STU | allocated | Active | assigned | generated | first issue | starts |
| Promotion | same | **preserved** | Active | class↑, roll reassigned | new session | **unchanged** | new session |
| Section transfer | same | **preserved** | Active | section changes | unchanged | **unchanged** | roster moves |
| Rollover | same | **preserved** | Active | reassigned | new session | **unchanged** | new session |
| Lost → replacement | same | preserved | Active | unchanged | **fee if configured** | v+1; old replaced | unaffected |
| Withdrawal / TC | same | preserved | Inactive/TC | — | frozen | **auto-revoked** | blanked forward |
| Re-admission | **new STU** | **preserved via relink** | Active | new | new | fresh v1 | new |

Neither class, section nor roll is signed into the credential, so no academic movement can invalidate a
card. **Verified, not assumed.**

## 17–18. DEFERRED REGISTERS — Fees, Class/Section/Roll (Parts 15–16)

Carried forward intact, none lost: old fee-demand backfill (FEES-D7) · historical `studentFeeSummary`
reconciliation · **ID-card replacement charges (now MECHANISM COMPLETE, awaiting ID-D4/D7)** ·
Transport/Hostel module late fees (FEES-D16) · **Fees UAT** · FEES-D9/D13/D14/D15 · **Bulk Roll
Assignment / D19** · **Existing-roll policy** · **Atomic capacity/roll concurrency / CAP-2**. Full detail
in §24. **Fees is NOT closed** — its UAT has not been executed.

## 19. ATTENDANCE WORKING-DAY FINDING (Part 17) — **two new defects**

Investigated, **not changed** (no ID-card dependency exists).

**Findings, `SOURCE-VERIFIED`:**
1. `Attendance::_get_sundays()` (`:5039`) hardcodes `date('w') == 0`. `_resolve_non_working_days()` =
   **Sundays + `calendarEvents` holidays only**.
2. **Saturday is NOT treated as non-working** — so a Mon–Sat school is accidentally correct, and a
   **Mon–Fri school must hand-enter every Saturday as a holiday, forever**.
3. A school with a **working Sunday cannot mark attendance at all** — `:1441` and `:1558` hard-block it.
4. **The school's real working days already exist in ZenX**: `Timetable_service` stores a `workingDays`
   array (default Mon–Sat), operator-configured. **Attendance never reads it.**
5. `schools.attendanceConfig.working_days` is read by `Health_check.php:934` and displayed by
   `Attendance.php:611` — but **nothing anywhere writes it**. A **read-only ghost**: the health check
   reports "Working days not configured" permanently, for every school, forever.

**Business impact:** a Mon–Fri school's attendance percentage is understated for **every student, every
month**, because ~4 Saturdays a month count as working days. That flows into reports, the Parent App and
the Teacher App. It is silent — nothing errors.

**ATT-WD-1 (HIGH)** — Attendance ignores the school's configured working days.
**ATT-WD-2 (MEDIUM)** — `attendanceConfig.working_days` is a dead read producing a permanent false health
alarm.

Not fixed here because Part 17 forbids it and because changing working-day resolution alters historical
percentages across three surfaces — that is an Attendance lifecycle closure, not an ID-card side effect.
Registered with acceptance conditions (§24).

## 20. RESEARCH SOURCES AND EVIDENCE CLASSIFICATION (Part 18)

| Finding | Class |
|---|---|
| First issue free; loss chargeable; escalating for repeat loss; stolen waived with a police report; damaged waived on surrender | `SOURCE-VERIFIED` — **US higher-ed** (Iowa, Berkeley, CMU, UCSD, Dartmouth, Syracuse), cited in the 19D report |
| **Indian K-12 school-ID replacement charge norm** | **`NOT ESTABLISHED`** — searches surface only CBSE *admit-card* duplicates, a board artefact under different authority |
| Head-specific ledger accounts already exist in ZenX (4060 Late Fee) | `SOURCE-VERIFIED` |
| Zero-default, per-school configurable amounts | `ZENX RECOMMENDATION` |
| Which ledger account receives replacement income | **`USER DECISION REQUIRED`** (ID-D4) |
| Actual per-reason amounts | **`USER DECISION REQUIRED`** (ID-D7) |

**The US structure generalises; the amounts do not, and none of it is law in India.** That is precisely
why every default is zero rather than a plausible-looking number.

## 21. TESTS (Part 21)

| Metric | Value |
|---|---|
| TOTAL TESTS | **1317** |
| TOTAL ASSERTIONS | **3678** |
| BASELINE FAILURES | **10** (Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2) |
| **NEW FAILURES** | **0** |
| NEW TESTS | **36** |
| LINT | clean (4 files) |
| RTDB | clean |
| RULES | unchanged — emulator tests not required |
| INDEXES | unchanged |

**Not "all tests pass"** — the 10 pre-existing baseline failures remain, untouched and unrelated.

One test initially failed against my own docblock (which explains *why* the policy defers to
`createModuleFee`) — fixed with a comment stripper. That is the third time this trap has appeared in the
programme; the stripper is now standard in these suites.

## 22. NEW DEFECTS

| ID | Sev | Found | State |
|---|---|---|---|
| **ATT-WD-1** | **HIGH** | Attendance ignores configured working days | registered (§24) |
| **ATT-WD-2** | MEDIUM | `attendanceConfig.working_days` dead read / false health alarm | registered |
| **SEC-ID-3b** | LOW | telemetry does not record V1 usage | registered |

## 23. DECISIONS REQUIRED

| ID | Decision | Recommendation |
|---|---|---|
| **SEC-ID-D1** | Authorize deploying SEC-ID-3 | **Yes** — log-only, total rollback. **Nothing downstream can proceed without it** |
| **ID-D4** | Ledger account for replacement income | distinct revenue account, not general fee income |
| **ID-D7** | Per-reason replacement amounts | per-school; keep defaults at 0 |
| **ATT-WD-D1** | Should Attendance consume `Timetable_service.workingDays`? | **Yes** — the data already exists and is operator-configured |

## 24. REMAINING WORK

| ID | Sev | Module | Location | Business impact | Technical impact | State | Why not fixed | Dependency | Solution | Acceptance | Owner |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **SEC-ID-3 deploy** | HIGH | ID Card | 2 controllers | migration cannot start | no evidence | code-complete | **I cannot deploy** | authorization | controlled deploy | `SEC-ID-3` lines in production log | **operator** |
| **SEC-ID-1** | HIGH | ID Card | 3 consumers | lost card still scans | authority built, unwired | flag OFF | no evidence; would reject everyone | SEC-ID-3 + reissue | wire `validateCredential`, flip flag | superseded/revoked rejected; current accepted | eng after evidence |
| **SEC-ID-2** | HIGH | ID Card | `qr_token_helper` | forgeable unsigned tokens | mechanism built | flag OFF | would brick printed cards | zero legacy holders | flip `IDCARD_REJECT_LEGACY` | forged unsigned rejected; V2 accepted | eng after evidence |
| **SEC-ID-3b** | LOW | ID Card | `Attendance:4282` | V1 holders invisible | incomplete evidence | open | would change deployed behaviour mid-observation | SEC-ID-3 deployed | add a V1 telemetry line | V1 usage countable | eng, 19K |
| **ID-D4** | — | Accounting | chart of accounts | income misclassified | — | open | **product decision** | user | choose account | account chosen + posting verified | **user** |
| **ID-D7** | — | Fees | `id_card_flags` | no charging | — | open | **product decision** | user | set amounts | amounts set per school | **user** |
| **ATT-WD-1** | **HIGH** | Attendance | `Attendance:5039`, `:1143` | Mon–Fri schools' % understated every month, across Admin + both apps | working days hardcoded | open | Part 17 forbids; alters historical % on 3 surfaces | ATT-WD-D1 | consume `Timetable_service.workingDays` | Mon–Fri school shows correct % with no manual holidays | eng, Attendance phase |
| **ATT-WD-2** | MED | Attendance | `Health_check:934` | permanent false alarm | dead read | open | same | ATT-WD-1 | write it, or remove the check | health check reflects reality | eng, Attendance phase |
| Rules for `idCards` | MED | ID Card | `firestore.rules` | none today | server-only | intentional | file dirty; no client consumer | reconcile canonical | least-privilege read rule | rule deployed before any client read | eng, 19K |
| **Fees UAT** | HIGH | Fees | — | Fees not closable | — | open | needs live tenant | operator | execute | matrix executed | **operator** |
| FEES-D7 backfill | MED | Fees | — | historical dueDates absent | — | open | production credential | authorization | dry-run then run | scope reviewed, run | operator |
| `studentFeeSummary` reconciliation | MED | Fees | — | historical summaries understated | self-heals per student | open | production credential | authorization | re-derive sweep | totals match demands | operator |
| FEES-D16 module late fees | — | Fees | `createModuleFee` | Transport/Hostel never fined | — | open | **product decision** | user | separate policy, forward-only cutoff | policy chosen | **user** |
| FEES-D9/D13/D14/D15 | — | Fees | — | carried | — | open | product decisions | user | — | — | user |
| **Bulk Roll Assignment / D19** | MED | Class/Section | — | manual roll entry | — | open | **product decision** | user | — | — | user |
| **Existing-roll policy** | MED | Class/Section | — | ambiguous on re-run | — | open | product decision | user | — | — | user |
| **CAP-2 atomic capacity/roll** | MED | Class/Section | Classes | concurrent overfill | race | open | needs design | — | atomic claim (as `Sr_no_service`) | concurrent adds cannot exceed capacity | eng |
| **EXAM-LC1** | HIGH | Examination | `Roster_helper::for_class` | camelCase-only roster → no marks/report card | shape drift | open | other module | — | dual-shape filter | both shapes return students | eng, Exam phase |
| LC-1 residual (~10) | MED | various | — | dropped rows | single-shape predicates | open | other modules | — | `_filterSectionRoster` | predicates removed | eng, per module |
| SRNO-3 | LOW | SIS | `Sr_no_service:264` | none | wrong comment | open | cosmetic | — | correct comment | matches code | eng |

## 25. UAT MATRIX

The 30-case matrix from 19H stands, with §3's classification applied. **Cases 19–21 (double-click), 27
(physical scan) and 30 (legacy telemetry) block closure and cannot be executed from here.**

## 26. CLOSURE GATE

**ID CARDS: NOT CLOSED. Status — READY WITH CONDITIONS.**

| Gate | |
|---|---|
| Backend lifecycle | ✅ |
| UI complete | ✅ (19H) |
| Credential authority — one place | ✅ built |
| **Credential enforcement live** | ❌ **no evidence** |
| **Legacy retired** | ❌ **precondition unmet** |
| Replacement fee mechanism | ✅ built, inert |
| **Replacement fee live** | ❌ ID-D4 + ID-D7 |
| Accounting | ⏸ decision |
| Rules | ✅ deny-by-default intentional |
| Teacher / Parent App | ✅ verified |
| SRNO audit | ✅ clean |
| Cross-module lifecycle | ✅ |
| Firestore-only / RTDB | ✅ |
| Performance | ✅ zero-read while off; 1 point-get when on |
| Regression | ✅ 1317 / 10 baseline, 0 new |
| **UAT** | ❌ **NOT EXECUTED** |

**Closure requires, in order:** deploy SEC-ID-3 → observe → reissue → `migrationReadiness()` clean →
enforce V2 → observe → retire legacy → decide ID-D4/D7 → execute UAT.

Everything that could be built without evidence has been built. **What remains is not engineering — it is
a deployment, an observation window, and two product decisions.**
