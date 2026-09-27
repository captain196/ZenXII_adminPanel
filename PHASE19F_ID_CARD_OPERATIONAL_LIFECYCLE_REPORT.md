# PHASE 19F — ID CARD OPERATIONAL LIFECYCLE + SEC-ID-3 TELEMETRY GATE

**Date:** 2026-08-15 · **Branch:** `integration/phase2`
**Status: READY WITH CONDITIONS** (see §25 — two disclosed UI conditions; UAT **NOT EXECUTED**)

Not committed · not pushed · not deployed · no rules changed · no indexes changed · no production write ·
no RTDB · no Fees/Attendance/Examination code touched.

---

## 1. EXECUTIVE SUMMARY

19E built a card model nothing could reach. **19F wires it into the panel**: an operator can now issue,
replace, revoke and inspect a specific card, and a student leaving the school has their card revoked
automatically.

| Delivered | |
|---|---|
| 4 endpoints | `id_card_register` / `issue` / `revoke` / `history`, explicitly routed |
| RBAC | mutations gate on **graded `SIS:manage` with no legacy role-name bypass** |
| Terminal-exit revoke | TC + withdrawal → revoke; **reversible status changes deliberately excluded** |
| **SRNO-4 CLOSED** | search now matches SR No. / Adm No. / Student ID / name |
| **SRNO-5 CLOSED** | SR No. is now the primary printed identity; Adm No. printed; Student ID subordinate |
| Workspace | status badge, version, card-status filter, issue/replace/revoke/history per card |

**Regression: 1236 tests / 3459 assertions / 10 failures — the identical pre-existing baseline.**
51 new tests. Lint clean.

**Nothing about credential acceptance changed.** Legacy, V1 and V2 all decode exactly as before; no QR
consumer enforces card version (test-asserted). **SEC-ID-2 remains open by design.**

> **Production legacy-usage evidence does not yet exist.** SEC-ID-3 is written but undeployed. No
> deadline is proposed.

---

## 2. WORKING-TREE RECONCILIATION (Part 0)

| File | State | Classification | Action |
|---|---|---|---|
| `firestore.rules` | `M` | **B — earlier uncommitted work** | **not touched** (§18) |
| `firestore.indexes.json` | `M` | **B** | **not touched** — none needed |
| `rbac_helper.php` | `MM` (staged + unstaged) | **B** | not touched |
| `MY_Controller.php` | `M` | **B** | not touched |
| `Firestore_service.php` | `M` | **B** | not touched |
| `qr_token_helper.php` | `M` | **A — Phase 19E** | not touched this phase |
| `Id_card_service.php` | `??` untracked | **A — Phase 19E** | not touched this phase |
| `Sis.php` | `M`, **+2229 lines** | **B — SRNO/APAAR/admission work** | **edited additively only** |
| `views/sis/id_card.php` | `M` | **B** | **edited surgically** |

**Ambiguity reported rather than assumed:** `Sis.php` carries +2229 uncommitted lines from earlier
phases. Every edit there was an exact-string insertion — three additive blocks and one 6-line addition
inside `id_card()`. No existing hunk was rewritten, reordered or reformatted.

---

## 3. SEC-ID-3 VERIFICATION (Part 1)

Re-traced against live source, not the prior report. **Three** runtime `qr_token_decode()` consumers —
no fourth exists (`Campus_access_verifier` is a self-test harness; `Sis::qr_image` renders pixels only).

| Consumer | legacy | V1 | V2 | Telemetry | API | Severity | CI3 accepts? | Threshold allows? |
|---|---|---|---|---|---|---|---|---|
| `Attendance::scan_qr` (`:4246`) | **accepted** | accepted | **accepted** | `:4282` | `log_message` | `error` | ✅ | ✅ |
| `Transport_qr_attendance_service` (`:185`) | **rejected** | accepted | **accepted** | `:188` | `log_message` | `error` | ✅ | ✅ |
| `Campus_access::scan_qr_lookup` (`:307`) | **accepted** | accepted | **accepted** | `:307` | `log_message` | `error` | ✅ | ✅ |

- **V2 is accepted everywhere V1 is**, including Transport's fail-closed path, because it decodes as
  non-legacy. No consumer change was needed or made.
- `system/core/Log.php:106` defines `ERROR|DEBUG|INFO|ALL`; `config.php:104` sets `log_threshold=1`.
  `error` is the **only** level that reaches the production log.
- **`sec_telem->emit('CROSS_TENANT_PROBE', 'warning', …)` was left untouched** — its second argument is a
  telemetry *severity*, a different API from `log_message()`. A test pins it against a well-meaning sweep.
- **No credential material is logged.** Messages carry `schoolId` and `studentId` only — never the token,
  the signature or the secret. Verified at all three sites.

## 4. DEPLOYMENT PREREQUISITE (Part 2) — gate documented, **not deployed**

| | |
|---|---|
| **Files required** | `application/controllers/Attendance.php`, `application/controllers/Campus_access.php` |
| **Behaviour change** | **Log output only.** Legacy scans begin writing an `error` line |
| **Credential acceptance** | **UNCHANGED** — legacy ✅, V1 ✅, V2 ✅ all still accepted |
| **Expected log entry** | `ERROR - … SEC-ID-3 Attendance::scan_qr — legacy unsigned token used for SCH_XXXXXX/STU0012 (reissue this student's ID card to mint a signed token)` |
| **Operator verification** | `grep "SEC-ID-3" application/logs/log-YYYY-MM-DD.php` on the Ohio box after one attendance cycle |
| **Rules / indexes / config** | **none required** |
| **Rollback** | Revert two files. No data written, no schema, no acceptance change — rollback is total |

**Sequence remains MEASURE → MIGRATE → VERIFY → ENFORCE.** Legacy acceptance is **not** disabled in this
phase. **No deadline is invented.**

---

## 5. EXTERNAL RESEARCH (Part 3)

Carried from 19D (US higher-ed, cited there) plus this phase's re-check. Classification is explicit:

| Finding | Class |
|---|---|
| First issue free; replacement chargeable | `SOURCE-VERIFIED` (Iowa, Berkeley, CMU, UCSD, Dartmouth, Syracuse) |
| Escalating fee for repeat loss | `SOURCE-VERIFIED` (Dartmouth $25 → $50) |
| Stolen waived with a police report | `SOURCE-VERIFIED` (CMU) |
| Damaged free/reduced on surrender of the old card | `SOURCE-VERIFIED` (Dartmouth, CMU, Syracuse) |
| Lost card deactivated, access moved to the replacement | `SOURCE-VERIFIED` |
| ERPs track reissue counts to spot frequent losers | `SOURCE-VERIFIED` |
| **Indian K-12 replacement-charge norm** | **`NOT ESTABLISHED`** — searches surface only CBSE *admit-card* duplicates, a board artefact. A school ID card has no external standard to inherit |
| Reason capture on replacement | `SOURCE-VERIFIED` (request forms capture reason + date + evidence) |
| Lost/stolen/damaged are **reasons**, not statuses | `ZENX RECOMMENDATION` |
| Print queue as a requirement | **`NOT ESTABLISHED`** — deliberately not built |

**The structure generalises; the amounts do not, and none of it is law here.** That is why §17 keeps every
default at zero.

## 6. ID-CARD LIFECYCLE FINDINGS

The `status / reason / event` separation from 19E survived contact with the controller unchanged. One
finding emerged only at wiring time — §14.

---

## 7. IMPLEMENTATION CHANGES

**`application/controllers/Sis.php`** (additive)
`_idCardSvc()`, `_idCardIdentity()`, `id_card_register()`, `id_card_issue()`, `id_card_revoke()`,
`id_card_history()`, `_revoke_id_card_on_exit()`; two call sites in `issue_tc()` / `withdraw_student()`;
6 lines in `id_card()` to pass `srNo` + `admissionNo` to the view.

**`application/config/routes.php`** — 4 explicit routes.

**`application/views/sis/id_card.php`** — SRNO-5 hierarchy, Adm No., SRNO-4 search + data attributes,
card-status filter, per-card ops strip, lifecycle JS.

**`tests/Unit/Phase19FIdCardLifecycleWiringTest.php`** — 51 tests.

Every operation follows **authorization → validation → server action → audit → truthful response**.
The service's refusal reason is mapped to a specific operator message (`card_already_issued`,
`no_existing_card_to_replace`, `version_claim_exhausted`) rather than a generic failure. Every endpoint
catches `\Throwable`, logs at `error`, and returns `json_error` — no silent failure.

---

## 8. RBAC (Part 5)

| Operation | Gate |
|---|---|
| `id_card` (print page) | `SIS:view` — unchanged |
| `id_card_register`, `id_card_history` | `SIS:view` |
| `id_card_issue`, `id_card_revoke` | **`_require_role([], …, 'SIS', 'manage')`** |

**The empty allow-list is the security property.** `_require_role()` falls through to a legacy
**role-name** allow-list when the graded check does not grant; a non-empty list would let someone merely
*named* e.g. "Principal" mutate credentials without holding `SIS:manage`. `[]` removes that path, making
the graded capability the sole authority — the pattern `Entitlements.php` established.

| Actor | Result | Why |
|---|---|---|
| `SIS:manage` | **allowed** | `has_permission('SIS','manage')` grants |
| `SIS:view` only | **denied** | holding SIS at a lower level is an authoritative deny |
| `Configuration:manage` only | **denied** | no SIS hold → empty legacy list → deny |
| unrelated module | **denied** | same |
| Super Admin / School Super Admin | allowed | existing architecture, unchanged |

Tested by source-scan (§20). `Configuration` appears in none of the four endpoints — asserted.

## 9. AUDIT

No new audit system. `log_audit()` is called inside `Id_card_service` (19E) with the payload under
`meta` and `entityType: 'idCard'` — the only keys it persists. Actions: `id_card_issued`,
`id_card_revoked`. A TC/withdrawal revoke produces the same `id_card_revoked` entry with
`reason: admin`, so the trail shows *why* alongside the `TC_ISSUED` / withdrawal history entry.

---

## 10. SRNO MANDATORY AUDIT (Part 8) — **two defects CLOSED**

### SRNO-4 — CLOSED
Search matched only `data-name` and `data-uid`. An operator who knows a child by SR No. — what the school
actually uses — had to look the internal Student ID up elsewhere before reprinting a card. Now matches
**name, Student ID, SR No. and Admission No.**, with the placeholder updated to say so.

### SRNO-5 — CLOSED
The card printed **Student ID first, in the accent colour, bold**, with SR No. plain beneath and
Admission No. absent — inverted against ZenX's own identity model. Now: **SR No. primary (accent+bold)
→ Adm No. → Student ID (subordinate, `opacity:.7`)**.

> **A documented prior decision was reversed, on explicit instruction, and I am flagging it rather than
> burying it.** The view carried a comment deliberately excluding Admission No. as "an admission-office
> artefact" that "becomes stale-looking after re-admission, when the card is not reissued". **Half that
> rationale is now obsolete**: since 19E, re-admission mints a new `studentId` and therefore a new card,
> so no re-admitted student carries a card bearing their old admission number. The remaining half is a
> presentation preference. Both the reversal and the surviving objection are recorded in the file.

### Closure table

| Surface | SR No. | Student ID | Admission No. | Roll No. |
|---|---|---|---|---|
| Admin ID Cards workspace | ✅ shown + **searchable** | ✅ shown + searchable | ✅ **searchable** | ✅ contextual |
| **Printed ID Card** | ✅ **PRIMARY** | ✅ subordinate | ✅ **present** | ✅ contextual, with class/section |
| `idCards` records | ✅ denormalised, **not a key** | ✅ FK (correct) | ✅ | ❌ **rejected by design** |
| Student Record | ✅ | ✅ | ✅ | ✅ |
| Attendance | n/a | ✅ FK | n/a | n/a |
| TC | ✅ (19C) | n/a | ✅ (19C) | ✅ not identity |
| Receipts / Roster | ✅ | ✅ | ✅ | ✅ |
| Teacher App | ✅ | internal | n/a | ✅ |
| Parent App | ✅ | internal | n/a | n/a |

**Invariants re-verified:** SRNO survives promotion, section transfer and re-admission (via `relink()`);
SRNO is never a Firestore FK; **Roll No. cannot reach a card** — `_idCardIdentity()` does not accept it
and a test asserts it. The **credential binds `studentId` only** — a test asserts exactly one
`qr_token_encode()` call and that its subject is never `srNo`/`admNo`/`rollNo`/`class`/`section`.

**No new SRNO defect found. No SRNO issue omitted.**

---

## 11. UI / WORKSPACE AUDIT (Part 9)

ID Cards was already a top-level *Student Info System* nav item, so it was **extended, not duplicated** —
no parallel controller, and no ID-card operations were added to Student Record.

Now present: card status badge · current version · card-status filter (active/replaced/revoked/none) ·
**Issue first card / Issue new card / Replace / Revoke / History** per student · four-field search ·
class + section filters · print one/filtered/all.

**Honesty guarantees, each test-pinned:**
- The ops strip renders **empty** and is populated only after the register actually loads. Offering
  Issue/Revoke before the real state is known would let an operator act on a guess.
- A failed register load prints **"Card status unavailable"** — empty strips would read as "no card".
- Every reply is checked for `r.ok` **and** `status === 'success'` (fetch does not reject on 403/500).
- The strip locks during a request: **Issue is not idempotent** — a second click would claim another
  version.
- `Revoke` on an already-dead card reports *"already out of service"*, not a fresh revocation.
- Controls are hidden without `SIS:manage`; the **server gate remains authoritative**.

## 12. PRINT AUDIT (Part 10)

Existing print (one / filtered / all, local SVG QR, no network) preserved unchanged. The identity
hierarchy is corrected per §10. Mutable class/section/roll remain **outside** the credential.
**Selective checkbox print was not built** — 19D classified it RECOMMENDED, not required; Print Filtered
covers the practical workflow. Registered (§22).

## 13. SEARCH AUDIT (Part 11)

Client-side over already-rendered rows — **no request per keystroke, no N+1**, no extra Firestore read.
Both new attributes are always emitted (possibly empty) so there is no undefined access; an empty field
simply never matches. Mixed data tolerated via three field spellings for SR No. and two for Adm No.

## 14. WITHDRAWAL / TC INTEGRATION (Part 7) — including the trap

`issue_tc()` (Status→`TC`) and `withdraw_student()` (Status→`Inactive`) are the two **terminal** exits;
both now revoke. Best-effort and placed **after** the exit is recorded — the student's status is what the
scanner actually enforces, so a failure degrades to pre-19F behaviour and can never abort a TC.

> **The finding this phase turned up.** `change_status()` also sets `Inactive` — the *same value* as
> withdrawal — but it is a **reversible** Active⇄Inactive toggle. A revocation cannot be undone, only
> superseded by a fresh issue, so revoking there would strand the card of anyone temporarily deactivated
> and reactivated. **Wiring the hook to the status VALUE rather than the OPERATION's intent would have
> been the bug.** `change_status()` and `cancel_tc()` are deliberately excluded, and a test asserts they
> stay excluded. Re-admission is unaffected: it mints a new `studentId` and therefore a new credential.

## 15–16. TEACHER APP / PARENT APP (Part 13)

**Teacher App** — re-verified: SRNO shown (`AttendanceScreen.kt:865`); **no QR/ID-card symbol exists**.
No teacher workflow depends on card status. **Nothing invented. No change.**

**Parent App** — re-verified: SRNO shown (`ProfileScreen.kt:335`). `StudentIdCard`
(`DashboardScreen.kt:801`) is confirmed a **gradient welcome banner** (name/class/school/today's status),
not a credential — it stays informational. **No digital credential exposed**: a screenshot-able QR is
weaker than paper and would undermine revocation. **NOT RECOMMENDED** without its own security model.

## 17. FEES DEPENDENCY (Part 12) — boundary held

**No charge implemented. `Fee_lifecycle.php` untouched.** A test asserts no `createModuleFee` in any 19F
method. The constraint is preserved: `periodKey` is hardcoded to `{sessionYear}-04`, so
`DEM_{studentId}_{YYYY}04_{HEAD}_{MODULE}` is **one demand per student per head per session** — a naive
second replacement would rewrite the first and, if paid, recompute to `balance 0 / status paid`, giving a
**free second card with a ledger claiming payment**. 19I must scope the fee identity by **card version**;
`version` is already first-class, so no `Fee_lifecycle` change is needed. `chargeableReasons()` remains a
classification with **no amounts**.

## 18. FIRESTORE RULES / INDEXES (Part 14)

`idCards` access in 19F is **entirely server-side** (Admin SDK, which bypasses rules), so
**deny-by-default is correct and intentional** — documented, not patched. No client reads it.

**No rule was written.** `firestore.rules` is dirty in the working tree; a deploy ships the whole file, so
editing it here risks shipping someone else's half-finished work. **Prerequisite: reconcile with canonical
`integration/phase2` first.** When 19K/Parent card status arrives, the requirement is: `idCards` —
`allow read` for same-school staff and the student's own parent; `allow write: if false` (this service is
the sole writer). **No speculative rule created. No index required** — every query is either schoolId-only
equality or a point-get.

## 19. PERFORMANCE (Part 15)

| Operation | Reads | Writes |
|---|---|---|
| ID Cards page load | 1 cached snapshot + 1 school doc + **1 register query** | 0 |
| `currentCard` / `currentVersion` | 1 point-get | 0 |
| `history` | 1 + V point-gets (V ≈ ≤5) | 0 |
| `issue` | 1 get | 3 (claim + supersede + pointer) |
| `revoke` | 1 get | 2 |
| Search / filter | **0** | 0 |

**One register query for any N students** — test-asserted (`substr_count == 1`). No N+1, no composite
index, no school-wide scan in a hot path. Scales to 5,000+.

## 20. TESTS

**51 new tests / 107 assertions, all passing.** Coverage of Part 16's 24 items: lifecycle (1–10) and
tenant/student isolation (11–12) by the 19E behavioural suite (36 tests, in-memory Firestore doubles);
RBAC (13), audit (14), SRNO/Adm No./Roll-No rejection (15–17), search (18), withdrawal (19),
re-admission (20), idempotency (21) and V1/legacy/V2 compatibility (22–24) here and in the 19E credential
suite (21 tests).

Controller/view assertions parse real source with **comments stripped** (`token_get_all`) so prose can
neither satisfy nor defeat a scan — the trap hit in 19C. One of my own assertions was wrong and is
recorded: a blanket "view must not contain `$rollNo`" failed against correct code, because Roll No is
legitimately *printed*; it was tightened to target the mint call.

| Check | Result |
|---|---|
| Full suite | **1236 / 3459 / 10 failures** |
| Baseline | **identical 10** — no new failure |
| `php -l` (5 files) | clean |
| RTDB scan | clean |

## 21. UAT MATRIX — **NOT EXECUTED**

No browser or device UAT has been performed. Requires a live tenant, printer and scan device.

| | Case | Expected |
|---|---|---|
| A | First card | Issue first card → v1 active, badge Active |
| B | Lost card | Replace, reason `lost` → v2 active |
| C | Replacement | old shows `replaced`, new `active` |
| D | **Old card** | **still scans — expected in 19F**, enforcement is 19G |
| E | New card | scans; QR differs from the old |
| F | Revoke | badge Revoked; re-revoke says "already out of service" |
| G | History | newest-first list with version/status/reason/date |
| H–J | Search SR No. / Adm No. / Student ID | each finds the student |
| K/L | Print one / filtered / all | unchanged; SR No. primary, Adm No. present |
| M | Withdraw | card auto-revoked; withdrawal still succeeds if revoke fails |
| N | Re-admit | new Student ID, **SRNO preserved**, fresh card |
| O/P | Teacher / Parent app | unaffected; SRNO still shown |
| Q | QR scan | attendance marks Present |
| R | **Legacy QR telemetry** | **blocked until SEC-ID-3 is deployed** |
| S/T | V1 / V2 QR | both accepted |
| U | RBAC | `SIS:view` operator sees no Issue/Revoke and is refused server-side |

## 22–23. DEFERRED WORK REGISTER + NEW FINDINGS

| ID | Sev | Location | Reason | Dependency | Phase | Acceptance |
|---|---|---|---|---|---|---|
| **SEC-ID-3 deploy** | — | 2 files | undeployed → no evidence | authorization | **immediate** | `SEC-ID-3` lines visible in production log |
| **SEC-ID-2** | HIGH | `qr_token_helper.php` | closing bricks printed cards | reissue coverage | **19J** | register shows zero legacy holders, then flip |
| **SEC-ID-1** | HIGH | credential | mechanism exists, unenforced | reissue | **19G** | revoked/superseded rejected at all 3 consumers |
| **ID-D2** | HIGH (design) | `Fee_lifecycle.php:999` | not live — nothing charges | ID-D2/D4/D7 | **19I** | 2nd replacement in a session → 2nd distinct demand |
| **ID-D4** | decision | accounting | ledger account for card income | user | **19I** | distinct revenue account chosen |
| **ID-D7** | decision | policy | per-reason amounts | user | **19I** | per-school config, defaults 0 |
| **ID-UI-1** *(new)* | LOW | `id_card.php` | reason capture and history use **native `prompt`/`alert`** — functional and honest, but not the polished workspace intended | — | **19H** | modal with a reason dropdown + inline history table |
| **ID-UI-2** *(new)* | LOW | `id_card.php` | **selective checkbox print** not built (19D: RECOMMENDED, not required) | — | **19H** | multi-select → print selected |
| Rules for `idCards` | MED | `firestore.rules` | file dirty; server-only today | reconcile canonical | **19G/19K** | rule deployed before any client read |
| **SRNO-4** | — | — | **CLOSED in 19F** | — | — | ✅ |
| **SRNO-5** | — | — | **CLOSED in 19F** | — | — | ✅ |
| **ID-D6** | — | — | **CLOSED in 19F** (`SIS:manage`) | — | — | ✅ |
| **Withdrawal → revoke** | — | — | **CLOSED in 19F** | — | — | ✅ |
| SRNO-3 | LOW | `Sr_no_service.php:264` | inaccurate comment | — | any | comment matches code |
| FEES-D16 | decision | `createModuleFee` | module late-fee policy | user | — | policy chosen |
| Fees UAT | — | Fees | live tenant | operator | — | executed |
| FEES-D7 old fee-demand backfill | — | — | production credential | authorization | — | dry-run reviewed, then run |
| Historical `studentFeeSummary` reconciliation | — | — | production credential | authorization | — | as above |
| FEES-D9/D13/D14/D15 | decision | Fees | user | — | — | unchanged |
| **Bulk Roll Assignment / D19** | MED | Classes/SIS | carried | decision | — | unchanged |
| **Existing-roll policy** | MED | roll | carried | decision | — | unchanged |
| **Atomic capacity/roll concurrency / CAP-2** | MED | Classes | carried | — | — | unchanged |
| **EXAM-LC1** | HIGH | `Roster_helper::for_class` | other module | — | Examination | camelCase-only roster fixed |
| **LC-1 residual (~10 predicates)** | MED | various | other modules | — | per-module | single-shape predicates removed |
| LC-2, ATT-2, ATT-UI, CS-ROLL-1..4, SEC-5/6, HC-RTDB, LMS-RTDB | — | various | carried | — | — | unchanged |

**New findings this phase:** the `change_status` revoke trap (§14 — avoided, test-pinned), ID-UI-1 and
ID-UI-2 above. No new correctness defect was introduced or left unfixed.

## 24. DECISIONS REQUIRED

| ID | Decision | Recommendation |
|---|---|---|
| **SEC-ID-D1** | Authorize deploying SEC-ID-3 | **Yes** — log-only, total rollback, and nothing else can be justified without it |
| **ID-D4** | Ledger account for replacement income | distinct revenue account, not general fee income |
| **ID-D7** | Per-reason replacement amounts | per-school config, **all defaults 0** |
| **ID-D2** | Fee identity per replacement | card version in the fee head — no `Fee_lifecycle` change |

## 25. FINAL GATE

**READY WITH CONDITIONS.** Not `READY FOR UAT`, and deliberately not.

Complete: backend lifecycle · RBAC · audit · SRNO audit (**two defects closed**) · search · print
hierarchy · withdrawal/TC · cross-module · cross-platform · tests with no new failures · UAT matrix.

**The two conditions — both UI, both disclosed rather than papered over:**

1. **ID-UI-1** — reason capture and history use native `prompt()`/`alert()`. Functional, honest, no fake
   state, but not the professional workspace the brief describes. A modal with a reason dropdown and an
   inline history table is 19H.
2. **ID-UI-2** — selective checkbox print not built.

Calling this "READY FOR UAT" would require me to assert the operational workspace is complete, and by the
brief's own professional bar it is not. Everything else is done and tested.

**ID Cards are NOT closed. Fees are NOT closed. UAT is NOT EXECUTED.**
