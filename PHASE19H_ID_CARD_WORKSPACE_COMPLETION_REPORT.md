# PHASE 19H — ID CARD PROFESSIONAL WORKSPACE COMPLETION

**Date:** 2026-08-15 · **Branch:** `integration/phase2`
**Status: READY FOR UAT** — UAT **NOT EXECUTED**

Not committed · not pushed · not deployed · no rules/indexes changed · no Attendance/Examination/Fees
code touched · credential acceptance unchanged · no RTDB.

---

## 1. EXECUTIVE SUMMARY

19F left two disclosed UI conditions. **Both are now closed.**

| | 19F | 19H |
|---|---|---|
| **ID-UI-1** | reason capture and history via native `prompt()`/`alert()` | **Bootstrap 3 / AdminLTE modals** — controlled reason select, separate operator note, full history table with loading / error / empty / retry |
| **ID-UI-2** | not built | **Select All / Clear / count / Print Selected**, zero-selection and duplicate-print guards |

**No native dialog remains anywhere in the workspace** — `alert`, `prompt` and `confirm` are all gone,
verified against comment-stripped source.

One genuine backend addition: an **operator note**, stored strictly separately from the canonical reason
(`reasonNote` / `revokedNote`, bounded to 300 chars, control characters stripped). The reason stays a
controlled enum because it drives audit, reporting and — in 19I — fee eligibility.

**Regression: 1281 tests / 3585 assertions / 10 failures — the identical pre-existing baseline.**
45 new tests. Lint clean.

**Security boundary held exactly:** SEC-ID-1 not enforced, SEC-ID-2 not closed, SEC-ID-3 still
**WRITTEN, NOT DEPLOYED**. **Production legacy-usage evidence still does not exist.**

---

## 2. WORKING-TREE RECONCILIATION (Part 0)

| File | State | Classification | Action |
|---|---|---|---|
| `views/sis/id_card.php` | `M` | **19F + earlier** | edited (this phase's target) |
| `Id_card_service.php` | `??` | **19E** | edited — note support added |
| `Sis.php` | `M`, +2229 lines | **earlier SRNO/APAAR/admission** | 2-line additive change only |
| `routes.php` | `M` | **19F** | untouched |
| `qr_token_helper.php` | `M` | **19E** | untouched |
| `firestore.rules` | `M` | **earlier, unrelated** | **untouched** (§13) |
| `firestore.indexes.json` | `M` | **earlier, unrelated** | **untouched** — none needed |
| `rbac_helper.php` | `MM` | **earlier, unrelated** | untouched |
| `MY_Controller.php`, `Firestore_service.php` | `M` | **earlier, unrelated** | untouched |

No unrelated change was reset, stashed or overwritten.

---

## 3. RESEARCH FINDINGS (Part 1)

| Finding | Class |
|---|---|
| Stack is **Bootstrap 3.3.7 + AdminLTE**; jQuery is loaded in `include/footer.php` | `SOURCE-VERIFIED` |
| **No `sis/*` view uses jQuery at all** — the whole module is vanilla JS | `SOURCE-VERIFIED` |
| `include/footer.php` loads AFTER the view body, so jQuery is undefined when this view's inline IIFE runs | `SOURCE-VERIFIED` |
| Modals elsewhere (`class_profile.php`) use `$('#m').modal('show')` | `SOURCE-VERIFIED` |
| Reason capture on replacement uses controlled forms in real ERP/ID-office workflows | `SOURCE-VERIFIED` (19D citations) |
| **Use Bootstrap 3 modal *markup* driven by vanilla show/hide** | **`ZENX UI RECOMMENDATION`** |

> **Why the modal is vanilla, not jQuery.** A `$('#idcReasonModal').modal('show')` in this file would
> throw at parse time — jQuery does not exist yet. Restructuring the whole view into a
> `DOMContentLoaded` wrapper to gain jQuery would diverge from every other `sis/*` view. Toggling the
> same classes Bootstrap's own plugin toggles (`.in`, `display:block`, `body.modal-open`, a
> `.modal-backdrop`) keeps the dialog visually native with **no load-order dependency and no new
> dependency**. A test pins that no jQuery call creeps in later.

No Bootstrap 4/5, no new framework, no custom CSS framework — the modals use stock AdminLTE classes
(`modal`, `dl-horizontal`, `callout`, `form-control`, `table`, `alert`).

---

## 4. ID-UI-1 IMPLEMENTATION (Parts 2–3)

### Reason modal (replace / revoke)

Shows **Student · SR No. · Adm No. · Student ID (subordinate) · Current card (version + status)**, then:

- **Reason** — a `<select>` limited to `lost | stolen | damaged | wear | data_correction | admin`.
  `first_issue` is **deliberately absent**: the service derives it, and offering it would let an operator
  mislabel a replacement as a first issue, corrupting both the audit trail and 19I fee eligibility.
- **Note** — optional free text, explicitly labelled *"Recorded alongside the reason, never instead of
  it"*.
- **Outcome callout** — replace: *"current card will be marked **replaced** and a new card will become
  **active**"*; revoke: a red callout stating **"This cannot be undone"**.
- Confirm is disabled in-flight and shows a spinner. **On failure the modal stays open** with the error
  inline, so the operator can correct and retry without re-entering anything.

A **first issue** shows no modal at all — there is nothing to ask, and a dialog with one possible answer
is friction, not confirmation.

### History modal

A real table: **version · event · status · reason (+ note) · issued · issued by · replaced (+by) ·
revoked (+by)**, newest first, with four distinct states — loading, error (with **explicit Retry**),
confirmed-empty, and data.

> **Empty is never a default.** `!Array.isArray(rows)` is treated as **malformed → error**, not as "no
> history". Rendering an empty state for a broken endpoint would hide the fault behind a clean UI.

Success feedback moved to a **non-blocking corner toast** (`flash()`), colour-coded — a `noop` revoke
returns `warning`, not `success`, because nothing actually changed.

## 5. ID-UI-2 IMPLEMENTATION (Part 4)

Per-card checkbox · **Select All** · **Clear** · live selected count · **Print Selected** (disabled at
zero).

- **Select All selects only what is currently VISIBLE.** Selecting hidden rows would print a set the
  operator never reviewed. **Clear** clears everything, including hidden — the safe direction.
- **Duplicate-print guard** via `dataset.busy`, with the button disabled and a spinner while preparing.
- A blocked pop-up or render failure surfaces *"Could not open the print view"* rather than looking like
  a completed print.
- **Zero additional Firestore reads** — it clones already-rendered DOM, the same nodes Print Filtered and
  Print All use. Print One / Filtered / All are all preserved.

---

## 6. FULL WORKSPACE AUDIT (Part 5)

| Capability | State |
|---|---|
| Search: SR No. · Adm No. · Student ID · Name | ✅ |
| Filter: Class · Section · **Card status** | ✅ |
| Card state: None / Active / Replaced / Revoked | ✅ badge + version |
| Issue · Replace · Revoke · History | ✅ modal-driven |
| Print: One · Filtered · **Selected** · All | ✅ |

**Not duplicated into Student Record** — no ID-card operation was added there. ID Cards remains the
single operational workspace.

## 7. ASYNC ACTION AUDIT (Part 6)

| Action | Loading | Disabled | Dup-click | Success | Error | Recovery | Refresh |
|---|---|---|---|---|---|---|---|
| Register load | ✅ | n/a | n/a | strips render | **"Card status unavailable"** | page reload | ✅ |
| Issue (first) | ✅ strip lock | ✅ | ✅ | toast | toast | strip restored | ✅ re-query |
| Replace | ✅ spinner | ✅ | ✅ | toast | **inline, modal stays open** | retry in place | ✅ re-query |
| Revoke | ✅ spinner | ✅ | ✅ | toast (`noop`→warning) | inline | retry in place | ✅ re-query |
| History | ✅ | n/a | ✅ | table | alert + **Retry** | Retry button | on retry |
| Print Selected | ✅ spinner | ✅ | ✅ | print window | toast | button restored | count refresh |

Handled: **403/500** (`!r.ok`), **malformed JSON** (`r.json().catch`), **non-array history**, **network
failure** (promise rejection → inline/toast), **stale card state** (every mutation re-reads the register
rather than patching local state), **already-revoked** (`noop`, reported truthfully), **already-replaced**
(service refuses `first_issue` on an existing card).

**No fake success anywhere** — every path checks `r.ok` **and** `status === 'success'`.

---

## 8. SRNO MANDATORY AUDIT (Part 7) — **re-run from scratch**

Not inherited from 19F. Four fresh repository-wide sweeps:

**A. Roll-No-substituted-for-permanent-identifier (the SRNO-1 bug class)** — regex across all views and
controllers for an "Adm No."/"SR No." label near a `rollNo` value. **Two hits, both benign:** the SRNO-1
fix comment in `tc_print.php`, and `Fees.php:4067`, which is a *presence check* (`$srNo === '' || $rollNo
=== ''`) triggering a single legacy fallback read — not a substitution. **No substitution defect exists.**

**B. SRNO used as a Firestore key/FK** — searched `docId($srNo)`, `docId2($srNo)`,
`'studentId' => $srNo`, and `where('srNo')` outside `Sr_no_service`. **Zero hits.** SRNO is never a
foreign key.

**C. Lifecycle mutation** — `execute_promotion` (Sis), `transfer_students` (Classes) and
`rollover_session` (School_config) contain **zero `srNo` references**. SRNO survives promotion, section
transfer and rollover **by construction**, not by convention. Pinned by a test that re-extracts those
three method bodies.

**D. Every `srNo` writer** enumerated and classified: `Sr_no_service` (allocation/relink),
`Readmission` (relink), `Sis` admission + import (`admission_auto` / `import_auto` /
`import_historical`), and read-only denormalised display copies in `Operations_accounting`,
`Transport_service`, `Id_card_service`, `FeeCollectionService`. **No illegitimate writer.**

### Surface matrix

| Surface | Displayed | Searchable | Printed | Preserved | Confused w/ Adm No. | w/ Student ID | w/ Roll No. | Wrong FK |
|---|---|---|---|---|---|---|---|---|
| ID Cards workspace | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Printed ID Card | ✅ **primary** | n/a | ✅ | ✅ | ❌ separate row | ❌ subordinate | ❌ contextual | ❌ |
| Student Record | ✅ | ✅ | n/a | ✅ | ❌ | ❌ | ❌ | ❌ |
| TC | ✅ | n/a | ✅ | ✅ | ❌ (fixed 19C) | ❌ | ❌ (fixed 19C) | ❌ |
| Receipts | ✅ | n/a | ✅ | ✅ | n/a¹ | ❌ (prior fix) | ❌ separate row | ❌ |
| Roster / section list | ✅ | ✅ | n/a | ✅ | ❌ own column | ❌ own column | ❌ | ❌ |
| Attendance | n/a² | n/a | n/a | n/a | — | — | — | ❌ |
| Fees | ✅ | ✅ (`via: 'srNo'`) | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Transport | ✅ denormalised | n/a | n/a | ✅ | ❌ | ❌ | ❌ | ❌ |
| Import/export | ✅ | n/a | ✅ | ✅ | ❌ own column | ❌ own column | ❌ | ❌ |
| Promotion / transfer / re-admission | n/a | n/a | n/a | ✅ **never written** | — | — | — | ❌ |
| Teacher App | ✅ | n/a | n/a | ✅ | n/a | ❌ | ❌ shown as `Roll X · SR Y` | ❌ |
| Parent App | ✅ | n/a | n/a | ✅ | n/a | ❌ | — | ❌ |
| `idCards` | ✅ denormalised | ✅ | ✅ | ✅ | ❌ separate field | ❌ | ❌ **rejected by design** | ❌ |

¹ The receipt deliberately omits Admission No. — a documented prior decision, with SR No. + Roll No.
shown instead. Not a defect.
² Attendance is keyed on `studentId` (correct — it is the internal FK).

**Result: SRNO audit CLEAN. No new SRNO defect found. Nothing omitted.**

## 9. IDENTITY HIERARCHY (Part 8)

PRIMARY **SR No.** → SECONDARY **Admission No.** → INTERNAL **Student ID** (`opacity:.7`) → CONTEXTUAL
**Class / Section / Roll No.** → MACHINE **QR**.

**Encode call verified, not assumed:** exactly **one** `qr_token_encode(` in the view, and its subject is
`$uid`. A regex test fails the build if it ever becomes `$srNo`, `$admNo`, `$rollNo`, `$cls` or `$sec`.

## 10. PRINTED CARD AUDIT (Part 9)

SR No. primary · Adm No. present · Student ID subordinate · Roll No. contextual (with class/section) ·
QR = credential. Changing **Roll No., Class or Section cannot change the credential** — none is signed,
test-asserted. **Replacement changes the QR** because the card version is signed: `v1 ≠ v2`,
behaviourally proven. **V2 is not enforced.**

## 11–12. TEACHER APP / PARENT APP (Part 10)

**Teacher App** — SR No. still correct (`AttendanceScreen.kt:865`, `"Roll X · SR Y"`). No ID-card
management invented. No change.

**Parent App** — SR No. still correct (`ProfileScreen.kt:335`). `StudentIdCard`
(`DashboardScreen.kt:801`) remains an informational banner. **No secure QR credential exposed** — a
screenshot-able credential is weaker than paper and undermines revocation. No parent-side
reissue/revoke invented.

## 13. SECURITY BOUNDARY (Part 11)

**Nothing changed.** SEC-ID-1 not enabled · SEC-ID-2 not closed · legacy tokens still accepted · no QR
consumer modified · SEC-ID-3 behaviour untouched.

Verified behaviourally: legacy decodes as `legacy`, V1 as `v1`, V2 as `v2` with its version; V2 differs
per version; the credential subject remains `studentId`, never SRNO. A test re-asserts that
`isCurrentVersion` appears in **none** of the three consumers.

**SEC-ID-3 remains WRITTEN, NOT DEPLOYED. No telemetry evidence is invented.**

## 14. FEES BOUNDARY (Part 12)

**No charge implemented.** `Fee_lifecycle.php` untouched; `Id_card_service` contains no `createModuleFee`
or `feeDemands` — test-asserted. ID-D2 / ID-D4 / ID-D7 and every other Fees item carried forward in §18.

## 15. PERFORMANCE

| Operation | Reads | Writes |
|---|---|---|
| Page load | 1 cached snapshot + 1 school doc + **1 register query** | 0 |
| Search / filter / **selection / Print Selected** | **0** | 0 |
| Issue / Replace | 1 | 3 |
| Revoke | 1 | 2 |
| History (per open) | 1 + V point-gets (V ≈ ≤5) | 0 |

Unchanged from 19F — **the entire 19H addition is client-side**. One register query for any N students,
test-asserted. No composite index required.

## 16. TESTS

**45 new / 126 assertions, all passing.** Behavioural where possible: note storage, note/reason
separation, note normalisation and bounding, replacement supersede, `noop` revoke, credential version
change, legacy/V1/V2 decode, credential subject, SRNO-not-a-key, Roll-No rejection, and lifecycle
non-mutation of SRNO.

> **Stated plainly:** modal/checkbox/double-click behaviour is browser JS, and this repo has no DOM test
> runner for admin views (`firebase-rules/tests` is Jest against the Firestore emulator only). Adding one
> is a new toolchain dependency, not a 19H deliverable. Those contracts are pinned by targeted
> source-scan and carried into the UAT matrix, which is where a real double-click is actually proven.
> Each scan targets a specific correctness property, never mere existence.

Two of my own scans initially failed against my own comments (the note explaining that `alert()` was
replaced, and the one explaining why jQuery cannot be used). Fixed with a comment stripper — and worth
recording, because `token_get_all()` alone is insufficient here: the JavaScript lives in `T_INLINE_HTML`,
so JS comments survive it and must be stripped textually.

| Check | Result |
|---|---|
| Full suite | **1281 / 3585 / 10 failures** |
| Baseline | **identical 10** — no new failure |
| `php -l` | clean |
| RTDB scan | clean |

## 17. UAT MATRIX — **NOT EXECUTED**

| | Case | Expected |
|---|---|---|
| 1 | First issue | no modal; v1 active; toast |
| 2–5 | Replace: lost / stolen / damaged / wear | modal shows identity + outcome; v+1 active; old `replaced` |
| 6 | Revoke | red irreversible callout; requires reason; badge Revoked |
| 7 | Re-revoke | *"already out of service"* (warning toast) |
| 8 | History | table newest-first, all columns, note visible |
| 9 | History — no card | confirmed-empty state only |
| 10 | History — server down | error + **Retry** works |
| 11–13 | Search SR No. / Adm No. / Student ID | each finds the student |
| 14 | Select All | selects **visible only** |
| 15 | Clear | clears all incl. hidden |
| 16 | Print Selected | prints exactly the selection |
| 17 | Zero selection | button disabled |
| 18 | Double-click Print Selected | one print window |
| 19–21 | Double-click Issue / Replace / Revoke | one operation only |
| 22 | Filtered / bulk print | unchanged |
| 23 | Old vs new card | different QR; **both still scan** (V2 unenforced) |
| 24 | Withdrawal / TC | card auto-revoked |
| 25 | Re-admission | new Student ID, **SRNO preserved**, fresh card |
| 26 | Teacher / Parent app | unaffected; SR No. correct |
| 27 | QR scan | attendance marks Present |
| 28 | RBAC | `SIS:view` sees no Issue/Revoke; server refuses if forced |
| 29 | Audit trail | `id_card_issued` / `id_card_revoked` with reason + note under `meta` |
| 30 | **Legacy QR telemetry** | **blocked until SEC-ID-3 is deployed** |

## 18. DEFERRED WORK REGISTER

| ID | Sev | Location | Reason | Dependency | Phase | Acceptance |
|---|---|---|---|---|---|---|
| **ID-UI-1** | — | — | **CLOSED in 19H** | — | — | ✅ |
| **ID-UI-2** | — | — | **CLOSED in 19H** | — | — | ✅ |
| **SEC-ID-3 deploy** | — | 2 files | undeployed → no evidence | authorization | **immediate** | `SEC-ID-3` lines in production log |
| **SEC-ID-2** | HIGH | `qr_token_helper.php` | closing bricks printed cards | reissue coverage | **19J** | register shows zero legacy holders |
| **SEC-ID-1** | HIGH | credential | mechanism exists, unenforced | reissue | **19G** | superseded/revoked rejected at all 3 consumers |
| **ID-D2** | HIGH (design) | `Fee_lifecycle.php:999` | one demand per student/head/session | ID-D4/D7 | **19I** | 2nd replacement → 2nd distinct demand |
| **ID-D4** | decision | accounting | ledger account | user | **19I** | distinct revenue account |
| **ID-D7** | decision | policy | per-reason amounts | user | **19I** | per-school config, defaults 0 |
| Rules for `idCards` | MED | `firestore.rules` | file dirty; server-only today | reconcile canonical | **19G/19K** | rule deployed before any client read |
| SRNO-3 | LOW | `Sr_no_service.php:264` | inaccurate comment | — | any | comment matches code |
| **FEES-D16** module late fees | decision | `createModuleFee` | Transport/Hostel late-fee policy | user | — | policy chosen |
| **Fees UAT** | — | Fees | live tenant | operator | — | executed |
| **Old fee-demand backfill (FEES-D7)** | — | — | production credential | authorization | — | dry-run reviewed, then run |
| Historical `studentFeeSummary` reconciliation | — | — | production credential | authorization | — | as above |
| FEES-D9 / D13 / D14 / D15 | decision | Fees | user | — | — | unchanged |
| **Bulk Roll Assignment / D19** | MED | Classes/SIS | carried | decision | — | unchanged |
| **Existing-roll policy** | MED | roll | carried | decision | — | unchanged |
| **Atomic capacity/roll concurrency / CAP-2** | MED | Classes | carried | — | — | unchanged |
| **EXAM-LC1** | HIGH | `Roster_helper::for_class` | other module | — | Examination | camelCase-only roster fixed |
| **LC-1 residual (~10 predicates)** | MED | various | other modules | — | per-module | single-shape predicates removed |
| LC-2, ATT-2, ATT-UI, CS-ROLL-1..4, SEC-5/6, HC-RTDB, LMS-RTDB | — | various | carried | — | — | unchanged |

## 19. NEW DEFECTS

**One, found and fixed during this phase.** My first selective-print wiring wrapped `applyFilters` *after*
its event listeners were registered — the listeners hold the original function object, so the selection
count would silently have gone stale on search/filter. Replaced with a direct call inside `applyFilters`.
Caught by reasoning about listener binding, not by a test, and recorded so the pattern is not repeated.

No other new defect. No known correctness defect remains open in the ID-card workspace.

## 20. DECISIONS REQUIRED

| ID | Decision | Recommendation |
|---|---|---|
| **SEC-ID-D1** | Authorize deploying SEC-ID-3 | **Yes** — log-only, total rollback; nothing downstream can be justified without evidence |
| **ID-D4** | Ledger account for replacement income | distinct revenue account |
| **ID-D7** | Per-reason amounts | per-school config, **all defaults 0** |
| **ID-D2** | Fee identity per replacement | card version in the fee head |

## 21. FINAL GATE

**READY FOR UAT.**

| Gate | |
|---|---|
| ID-UI-1 closed | ✅ |
| ID-UI-2 closed | ✅ |
| SRNO audit clean | ✅ re-run from scratch, 4 sweeps |
| Search clean | ✅ four identifiers |
| Print clean | ✅ one / filtered / selected / all |
| Lifecycle clean | ✅ issue / replace / revoke / history |
| RBAC clean | ✅ `SIS:manage`, no legacy bypass |
| Audit clean | ✅ payload under `meta`, note included |
| Async UX clean | ✅ §7 |
| No known correctness defects | ✅ |
| No new test failures | ✅ 1281 / 10 identical baseline |
| UAT matrix complete | ✅ 30 cases |

**ID Cards are NOT CLOSED. UAT is NOT EXECUTED.**

Closure additionally requires: SEC-ID-3 deployed and observed · SEC-ID-1 enforced (19G) · SEC-ID-2
retired (19J) · replacement fees (19I) · `idCards` rules written after reconciling `firestore.rules`.
