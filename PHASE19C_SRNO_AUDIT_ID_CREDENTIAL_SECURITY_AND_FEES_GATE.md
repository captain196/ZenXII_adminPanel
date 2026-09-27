# PHASE 19C — SRNO AUDIT · ID CREDENTIAL SECURITY · FEES COMPLETION GATE

**Date:** 2026-08-15 · **Branch:** `integration/phase2` · **Status:** IMPLEMENTED, UNCOMMITTED, NOT DEPLOYED

**Regression:** 1128 tests / 3200 assertions / **10 failures — the identical pre-existing baseline.**
`php -l` clean on every changed file.

**Headline:** three defects fixed (SEC-ID-3 at two sites, SRNO-1 on the Transfer Certificate). Parts 6–9
are **blocked on one business decision**, stated explicitly in §7 — not silently deferred.

---

## 1. WHAT WAS FIXED IN THIS PHASE

| ID | Defect | Severity | Location | Status |
|---|---|---|---|---|
| **SEC-ID-3a** | Legacy-token security log written at a level CI3 discards | **HIGH** (blind security migration) | `Attendance.php` scan_qr | **FIXED** |
| **SEC-ID-3b** | Cross-tenant probe log, same dropped level | **HIGH** | `Attendance.php` scan_qr | **FIXED** |
| **SEC-ID-3c** | Visitor path returned a `legacy` flag nothing consumed | MEDIUM | `Campus_access.php` scan_qr_lookup | **FIXED** |
| **SRNO-1** | Transfer Certificate printed **Roll No** under "Adm No." | **HIGH** (legal document) | `views/sis/tc_print.php:433` | **FIXED** |

---

## 2. PART 0 + 14 — SRNO AUDIT

### 2.1 What SRNO actually is — `SOURCE-VERIFIED` from `Sr_no_service.php`

| Question | Answer | Evidence |
|---|---|---|
| Canonical field | `srNo` (display) + `srNoNorm` (leading-zeros stripped) + `srNoSource` | `Sr_no_service.php:156`, `:497-499` |
| Storage | `srNoIndex/{schoolId}_{srNoNorm}` (ownership) **and** `students.srNo` (display) | `:50-59`, `:153` |
| Generation | Automatic school-scoped sequential via `allocateNext()`; counter at `feeCounters/_sys_SRNO_{schoolId}`; atomic `firestoreCreate` claim | `:235-284` |
| Uniqueness scope | **Per school** — doc id is `{schoolId}_{norm}` | `:153` |
| Session-specific? | **No.** No session anywhere in the key or document | `:50` |
| Changes on promotion? | **No** — nothing on the promotion path writes it | grep: no writer |
| Changes on re-admission? | **No — deliberately preserved.** Same SRNO, new Student ID, via `relink()` | `:10-13`, `:410` |
| Changes on transfer? | **No** | no writer |
| Printed identity? | **Yes** — student ID card | `views/sis/id_card.php:337-341` |
| Used in documents? | Partially — **this is where SRNO-1 was found** | §2.3 |
| Imports/exports? | Yes — `SR Number` column, digits-only validation | `Import_schema.php:103`, `:464` |
| Foreign key? | **Never.** Student ID is the sole FK on every lifecycle collection | `:89-90` |

**Format:** digits only, 1–9 chars (`/^[0-9]{1,9}$/`). `"0147"` and `"147"` collide by design.

### 2.2 Two corrections to assumptions

1. **SRNO ≠ Roll No**, and they behave oppositely. Roll No is class/session-scoped, free text
   (`"A12"`), reassigned on promotion and section transfer. SRNO is permanent and school-scoped.
2. **A misleading comment, not a defect.** `Sr_no_service.php:264` claims `claim()` writes "srNoIndex +
   the student doc's `srNo`/`srNoNorm` fields". It does **not** — `claim()` (`:140-188`) writes only
   `srNoIndex`. The student-doc fields are written by the *controllers* (`Sis.php:8987`, `:5316`,
   `:5345`). Behaviour is correct; the comment is wrong. Registered as **SRNO-3** (cosmetic).

### 2.3 SRNO-1 — the defect, `SOURCE-VERIFIED`

`views/sis/tc_print.php:433` rendered:

```php
<span>Adm No. <span class="dotted"><?= htmlspecialchars($rollNo) ?></span></span>
```

`$rollNo = $s['Roll No']`. A Transfer Certificate is a legal document the student carries to the
receiving school, and Roll No is wrong for it twice over: it is **reassigned on promotion and section
transfer**, so it does not identify the student after they leave; and it is free text, not unique
within the school.

**Fix:** print `admissionNo` (canonical, from `Admission_no_service`), fall back to `srNo`, otherwise
leave the dotted line **blank** — a blank line on a certificate is correct and fillable by hand,
whereas a confidently-printed wrong number is an integrity failure on a document the school cannot
retract. A separate `SR No.` field is now emitted when it differs from the printed admission number.

### 2.4 Unrelated "serial" fields — the Part 14 distinction

| Field | What it is | Verdict |
|---|---|---|
| `tc['serial_no']` (`tc_print.php:43`) | The **TC's own** serial, falls back to `tc_no` | Not SRNO — correct as-is |
| `"S.No"` (`section_students.php:403`) | Row counter in a table | Not SRNO |
| `"Admission Register"` (`tc_print.php:484`) | CBSE wording: "DOB according to Admission Register" | Not a field |
| `admissionNo` | Canonical Admission Number, own service + index | Related but **distinct** from SRNO |

### 2.5 Module table (Part 14 requirement)

`R`=read `W`=write `D`=display `P`=print `S`=search `X`=import/export

| Module | SRNO required? | Field | R | W | D | P | S | X | Status | Gap / Fix |
|---|---|---|---|---|---|---|---|---|---|---|
| Admission | Yes | `srNo` | ✅ | ✅ | ✅ | — | — | — | **OK** | auto-allocated `admission_auto` |
| Import / bulk | Yes | `SR Number` | ✅ | ✅ | ✅ | ✅ | — | ✅ | **OK** | `import_auto` / `import_historical` |
| Re-admission | Yes | `srNo` | ✅ | ✅ | ✅ | — | — | — | **OK** | preserved via `relink()` |
| Student Record / 360 | Yes | `srNo` | ✅ | — | ✅ | — | ✅ | — | **OK** | |
| Class / Section roster | Display | `sr_no` | ✅ | — | ✅ | — | — | — | **OK** | own column |
| **ID Card** | **Yes** | `srNo` | ✅ | — | ✅ | ✅ | — | — | **OK** | prints "SR No." |
| **TC / Certificates** | **Yes** | *was `Roll No`* | ✅ | — | ✅ | ✅ | — | — | **FIXED** | **SRNO-1** |
| Fees / receipts | Display + lookup | `srNo` | ✅ | — | ✅ | ✅ | ✅ | — | **OK** | resolves `via: 'srNo'` |
| Promotion / rollover | Must preserve | `srNo` | — | — | — | — | — | — | **OK** | no writer = preserved |
| Transfer (section) | Must preserve | `srNo` | — | — | — | — | — | — | **OK** | no writer = preserved |
| Attendance | No | — | — | — | — | — | — | — | **OK** | keyed by Student ID |
| Exam / Results | No | — | — | — | — | — | — | — | **OK** | keyed by Student ID |
| Library / Hostel / Transport | No | — | — | — | — | — | — | — | **OK** | keyed by Student ID |
| Accounting | No | — | — | — | — | — | — | — | **OK** | |
| **Teacher App** | Display | `srNo: Any?` | ✅ | — | ✅ | — | — | — | **OK** | "Roll X · SR Y" in attendance |
| **Parent App** | Display | `srNo: Any?` | ✅ | — | ✅ | — | — | — | **OK** | Profile "SR No." row |

**Both apps model SRNO as `Any?` with a tolerant `srNoText` accessor** — deliberately defensive against
a numeric write crashing deserialisation. `ZENX-VERIFIED`: every current admin write path emits a
**string**, so that defence is precautionary, not compensating for a live defect.

---

## 3. PART 1 — PHASE 19B FEES FIXES RE-VERIFIED

Re-read in live source, not taken from the prior report:

| Check | Result |
|---|---|
| FEES-FROZEN-1 present, both branches | ✅ `Fee_lifecycle.php:1077-1090` |
| FEES-FROZEN-2 present, all 3 sites | ✅ writer `:259`, reader `:104` + `:159` |
| `net + fine − paid` used consistently | ✅ no variant found |
| **No second balance formula introduced** | ✅ |
| **No new fee engine created** | ✅ |
| `createModuleFee()` callers | 7 Transport + 1 Hostel — all share the one fixed path |
| Regeneration idempotent | ✅ 3 consecutive runs byte-identical |
| Defaulter / reminder / TC clearance / accounting | ✅ all read `balance`, inherit the fix |
| Parent + Teacher apps | ✅ read camelCase `fineAmount`/`balance`; no app change needed |

**`ZENX-VERIFIED`: no ID-card fee producer exists yet** — nothing calls `createModuleFee()` for a card
replacement. That is Part 9, and it is gated (§7).

---

## 4. PART 3 — QR CREDENTIAL TRACE (independent, not the prior report's)

### 4.1 Blast radius — the prior report was wrong in both directions

`SOURCE-VERIFIED` — **three** runtime consumers of `qr_token_decode()`, not one:

| Consumer | Accepts unsigned? | Telemetry | Verdict |
|---|---|---|---|
| `Attendance.php:4246` scan_qr | **YES** | was **dropped** | **fixed telemetry**; still accepts (SEC-ID-2 open) |
| `Transport_qr_attendance_service.php:185` | **NO — rejects** | `error` (valid) | **already hardened**, untouched |
| `Visitor_service.php:782` → `Campus_access::scan_qr_lookup` | **YES** | **none at all** | **telemetry added** |

**Correction:** `Campus_access_verifier.php:170` is a **self-test harness**, not a runtime gate. It is
not part of the attack surface. `Sis::qr_image` renders images only and never decodes for authorization.

**Correction to severity framing:** the earlier characterisation of a uniformly critical unsigned-token
surface was too broad. Transport already fails closed. The visitor path requires an authenticated
operator holding `Campus Access / edit`, is **read-only** ("this endpoint never changes any state"), and
`_requirePass()` rejects any passId that does not already exist — so a forgery there needs a real pass
ID and grants no state change. **The one surface where an unsigned forgery has real effect is
`Attendance::scan_qr`**, which marks a student Present.

### 4.2 SEC-ID-2 — still open, `SOURCE-VERIFIED`

`qr_token_helper.php:148` returns 2-part unsigned tokens as valid with `legacy => true`. Anyone can
base64 `"{schoolId}|{studentId}"` by hand. **Deliberately NOT closed in this phase** — the brief forbids
it ("Do NOT reject legacy unsigned tokens yet") and closing it would brick every printed card.

### 4.3 SEC-ID-1 — revocation, `SOURCE-VERIFIED`

There is **no card version, no revocation list, no issuance record** anywhere. The signature binds only
`(schoolId, studentId)`, both immutable. Consequently **a signed token is valid forever and cannot be
revoked without changing the server secret, which would invalidate every card in every school at once.**
A lost card stays valid indefinitely. This is the substantive gap, and it is what Part 6 exists to fix.

---

## 5. PART 4 — SEC-ID-3 IMPLEMENTED

### The verification the brief demanded, done first

`SOURCE-VERIFIED` from the framework in this repo:

- `system/core/Log.php:106` — `$_levels = array('ERROR'=>1, 'DEBUG'=>2, 'INFO'=>3, 'ALL'=>4)`.
  **`WARNING` is not a level.**
- `system/core/Log.php:180` — an unknown level short-circuits and the message is **discarded silently**.
- `application/config/config.php:104` — `log_threshold = 1` (**errors only**).

Therefore `'error'` is not merely *a* valid choice, it is **the only level that reaches the production
log at all** — `'info'` and `'debug'` are valid but would also be discarded at threshold 1.

**Repository convention corroborates:** 1837 `log_message('error', …)` vs 118 `'warning'`. And
`Transport_qr_attendance_service` already logs this exact event at `'error'`.

### Changes

1. `Attendance::scan_qr` legacy branch → `'error'`, message prefixed `SEC-ID-3`, names the student so
   the operator knows which card to reissue.
2. `Attendance::scan_qr` cross-school probe → `'error'` (same defect, same block, contained — Part 13).
3. `Campus_access::scan_qr_lookup` → logs when `legacy` is set. The flag already existed and **nothing
   consumed it**.

**Deliberately NOT changed:** `sec_telem->emit('CROSS_TENANT_PROBE', 'warning', …)`. That second
argument is a telemetry **severity**, a different API — rewriting it would corrupt working code. A
regression test pins it.

**No behaviour change. No card stops working.** This phase only makes legacy use visible.

### Test — `tests/Unit/Phase19CSecId3LegacyTokenTelemetryTest.php`, 14 tests / 35 assertions

Proves the framework facts by **parsing `system/core/Log.php` itself**; proves both call sites log at
`'error'`; proves no discarded level remains in either method; proves the telemetry severity was not
over-corrected; and functionally exercises the helper — legacy accepted+flagged, signed not flagged,
tampered signature rejected, signature **not transferable between students**. The end-to-end test asserts
a legacy scan produces a **capturable `'error'` event** via the bootstrap's log stub — an observable
event, not merely a source string.

> The first version of this test failed against itself: my own explanatory comment contained the text
> `log_message('warning'` and matched the scanner. It now strips comments via `token_get_all()` and
> inspects **code**, not prose.

---

## 6. PART 5 — LEGACY CARD MIGRATION: WHAT EVIDENCE ACTUALLY EXISTS

**None. Zero. `NOT ESTABLISHED`.**

Being exact, because this is the crux of the phase: the only mechanism that could ever have recorded
legacy-token usage was writing to a discarded log level, and that was true from the day the migration
window opened until this phase. **No historical evidence exists and none can be recovered.** Evidence
begins accruing only after this fix is deployed — and I cannot deploy, so **I cannot report observed
usage, and I will not simulate it.**

`INFERENCE` (stated as such): signed tokens are minted only by `views/sis/id_card.php` at render time, so
any card printed before the HMAC upgrade is unsigned. Most currently-carried cards are therefore likely
legacy. This is reasoning about mint paths, **not** a measurement.

### Options

| | Approach | Operational impact on a real school | Verdict |
|---|---|---|---|
| **A** | Reject unsigned immediately | Every pre-upgrade card dies at the gate on day one. Attendance scanning stops for most students until every card is reprinted. | **Reject** — the brief forbids it and it is operationally unacceptable |
| **B** | Dual-accept with a deadline | Cards keep working; usage is now logged; the school reprints during the window; acceptance flips off at a chosen date | **`ZENX RECOMMENDATION`** |
| **C** | Per-student forced reissue | Finest control, but needs the per-card state model that does not exist yet (SEC-ID-1) | Becomes available only *after* Part 6 |

**`ZENX RECOMMENDATION`:** adopt **B**, but do not choose the deadline now — deploy SEC-ID-3 first,
observe the log for one full attendance cycle, then set the date on measured evidence. C becomes the
better endgame once credential V2 exists, because it can retire cards individually instead of by date.

### `BUSINESS DECISION REQUIRED` — SEC-ID-D1

Confirm option **B**, and confirm the observation window. **This is the decision Parts 6–9 are waiting
on.**

---

## 7. PARTS 6–9 — NOT IMPLEMENTED, AND WHY (Part 13 disclosure)

Parts 6 (credential V2), 7 (card lifecycle), 8 (ID Cards & Identity workspace) and 9 (fee integration)
are **not implemented**. Per Part 13 this requires an exact justification, so:

- **Exact scope withheld:** versioned credential `{schoolId, studentId, cardVersion, sig}`; card status
  model; revocation; replacement; issuance history; the dedicated operational workspace; and the
  replacement-fee integration through `createModuleFee()`.
- **Reason:** **explicit business decision outstanding (SEC-ID-D1)** — an allowed Part 13 reason. The
  brief itself sequences this: *"Only after the migration strategy is justified, design and implement."*
  The migration strategy cannot be justified on evidence, because §6 establishes that **no evidence
  exists yet** and none can be collected until SEC-ID-3 is deployed.
- **Why not build it anyway:** a versioned credential changes what a valid token *is*. Building
  revocation + reissue + fee charging on an unchosen migration strategy would produce exactly the
  half-feature Part 13 forbids — and the worst possible outcome, a replacement flow that charges a
  parent while the old card still works, is the specific failure Part 9 names.
- **Prerequisite:** SEC-ID-D1 answered, and SEC-ID-3 deployed long enough to produce a usage baseline.
- **Acceptance condition:** old credential rejected after revocation; new credential accepted; no card
  silently invalidated; replacement fee raised through the existing `createModuleFee()` with an
  auditable reason; every issuance answering who/why/which/when.
- **Next phase:** 19D.

**A UI gap is disclosed rather than half-built:** there is currently no operator-facing surface for card
status, revocation, or replacement, because no backend capability exists to expose. Per Part 12 I have
**not** created controls with no backend consumer.

---

## 8. PART 2 — FEES OUTSTANDING REGISTER

| # | Item | Classification | Note |
|---|---|---|---|
| 1 | Historical `dueDate` backfill (**D7**) | **NEEDS PRODUCTION CREDENTIAL** | `planDueDateBackfill()` measures scope without writing. No numbers invented. |
| 2 | ID-card replacement charge | **NEEDS USER DECISION** + blocked by §7 | Must be per-school configurable, never hardcoded |
| 3 | Transport late fees | **NEEDS USER DECISION** (FEES-D16) | Mechanism identified in 19B; deliberately not enabled |
| 4 | Hostel late fees | **NEEDS USER DECISION** (FEES-D16) | Same single gate |
| 5 | Module late-fee policy | **NEEDS USER DECISION** (FEES-D16) | Recommend a *separate* policy, forward-only cutoff |
| 6 | Fees UAT | **NEEDS UAT** | Requires a live tenant + operator |
| 7 | Historical `studentFeeSummary` reconciliation | **NEEDS PRODUCTION CREDENTIAL** | Fix corrects future aggregation; existing docs self-heal on the next fee event per student |
| 8–12 | D9, D13, D14, D15 | **NEEDS USER DECISION** | Carried unchanged |
| 13 | *(new this phase)* SEC-ID-D1 → replacement-fee integration | **NEEDS USER DECISION** | §7 |

**Nothing in this register was safely implementable now.** Every item is genuinely gated on a decision,
a credential, or UAT — none on effort.

---

## 9. FINAL CLOSURE GATE (Part 15)

### Fees — **NOT CLOSED**
Implementation ✅ · tests ✅ · historical-data impact documented ✅ · safely-fixable items closed ✅ ·
policy decisions identified ✅ · **UAT not executed ❌**.

### ID Cards — **NOT CLOSED**
Credential security ❌ (SEC-ID-1 and SEC-ID-2 open) · revocation ❌ · replacement ❌ · fee integration ❌ ·
UI ❌ · cross-platform traced ✅ · SRNO impact checked ✅ · telemetry ✅.

### Deferred register

| ID | Severity | Location | Reason | Prerequisite |
|---|---|---|---|---|
| **SEC-ID-1** | **HIGH** | credential design | business decision SEC-ID-D1 | option B confirmed |
| **SEC-ID-2** | **HIGH** | `qr_token_helper.php:148` | brief forbids closing; would brick cards | credential V2 + reissue |
| **SEC-ID-D1** | decision | — | user | — |
| FEES-D16 | decision | `createModuleFee()` | user | — |
| FEES-D7 / summary reconciliation | — | — | production credential | authorization |
| FEES-UAT | — | — | live tenant | operator |
| SRNO-3 | cosmetic | `Sr_no_service.php:264` | inaccurate comment, no behaviour impact | — |
| EXAM-LC1 | HIGH | `Roster_helper::for_class` | different module — one-module-at-a-time rule | Examination phase |
| LC-1 residual (~10 predicates) | MEDIUM | various | different modules | per-module phases |

### Cross-platform

- **Admin Panel** — 4 files changed. All fixes here.
- **Teacher App** — SRNO ✅ correct. **No ID-card support** (`SOURCE-VERIFIED`: no QR/idcard symbols).
  No change required.
- **Parent App** — SRNO ✅ correct. **No ID-card support.** `StudentIdCard` in `DashboardScreen.kt:801`
  is a **welcome banner** (name/class/school/today's status) — *not* a credential. Named honestly here
  because the name invites the opposite conclusion. No change required.

---

## 10. CHANGED FILES

| File | Change |
|---|---|
| `application/controllers/Attendance.php` | SEC-ID-3a + 3b |
| `application/controllers/Campus_access.php` | SEC-ID-3c |
| `application/views/sis/tc_print.php` | SRNO-1 |
| `tests/Unit/Phase19CSecId3LegacyTokenTelemetryTest.php` | new, 14 tests |

Plus Phase 19B, still uncommitted: `Fee_lifecycle.php`, `Fee_summary_writer.php`,
`Fee_summary_reader.php`, and two test suites.

**Not committed. Not pushed. Not deployed.**

---

## 11. THE ONE THING NEEDED TO PROCEED

**Answer SEC-ID-D1** (§6): confirm migration option **B** — dual-accept with a deadline set after
observing real telemetry — and authorize deploying SEC-ID-3 so that telemetry can begin.

Parts 6–9 unblock the moment that decision exists.
