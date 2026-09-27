# PHASE 19D — ID CARD & STUDENT IDENTITY LIFECYCLE
## Research + Pre-Implementation Audit

**Date:** 2026-08-15 · **Branch:** `integration/phase2` · **Type:** RESEARCH ONLY
**No source file was modified. No rules, no indexes, no commit, no push, no deploy, no production write, no UAT claimed.**

Labels: `SOURCE-VERIFIED` (read in this repo) · `ZENX-VERIFIED` (proven by trace) ·
`INDUSTRY PRACTICE` (external, cited) · `INFERENCE` · `ZENX RECOMMENDATION` · `NOT ESTABLISHED`

---

## A. EXECUTIVE SUMMARY

**ZenX does not have an ID-card system. It has an ID-card *printer*.**

That is the single finding this phase turns on, and it reframes everything the previous reports said.
`ZENX-VERIFIED`: there is **no card collection, no card document, no issuance record, no status field,
no version, and no history** anywhere in the repository. `Sis::id_card()` is a **stateless bulk render**
that lists every active enrolled student and mints a QR at page-render time. The credential is a pure
function `HMAC(secret, schoolId|studentId)` — both inputs immutable for the life of the student.

Three consequences follow, and they are not independent bugs but one architectural fact:

1. **"Reissue" does not exist.** Printing the card again produces a byte-identical credential.
2. **Revocation is impossible** — there is nothing to revoke and nothing that records a revocation.
3. **A replacement fee would be unsafe to charge today**, because you would be charging a parent for a
   card that is indistinguishable from the one it replaces.

**However — and this materially corrects the alarm level of the prior reports** — I traced the actual
runtime consumers and found the exposure is narrower than stated:

| Scenario | Real behaviour today | `ZENX-VERIFIED` |
|---|---|---|
| Withdrawn student's card | **Fails** — `scan_qr` rejects any non-Active status | `Attendance.php:4318-4320` |
| Re-admitted student's old card | **Fails** — re-admission *requires* the prior record to be non-Active | `Readmission.php:148-151` |
| Cross-school use | **Fails** — tenant check on every consumer | `Attendance.php:4269` |
| One student's card used for another | **Fails** for signed tokens — the HMAC binds studentId | test-proven, 19C |
| Transport boarding with unsigned card | **Fails** — F14 rejects legacy outright | `Transport_qr_attendance_service.php:188` |
| **Lost/stolen card of an ACTIVE student** | **WORKS INDEFINITELY** | **the real gap** |
| **Forged unsigned card for any active student** | **WORKS** at attendance | SEC-ID-2 |

So the unmitigated case is exactly one — and it is the most common real-world one: **an active student
loses their card.** Today the school's only options are to withdraw the student (absurd) or rotate the
server secret (which kills every card in every school).

**Answer to the closure question "is this production-safe?": for attendance marking, no.** It is
production-*tolerable* only because the two consumers that matter most for physical security — transport
boarding and the visitor gate — already fail closed or are read-only behind an authenticated operator.

---

## B. CURRENT ARCHITECTURE (`SOURCE-VERIFIED`)

### B.1 The complete producer → storage → consumer map

**PRODUCERS (mint a credential)**

| Site | What it mints | When |
|---|---|---|
| `views/sis/id_card.php:308` | `qr_token_encode(schoolId, studentId)` | **at page render**, never stored |
| `Visitor_service::_mintBadgeCode` (`:798`) | `qr_token_encode(schoolId, passId)` | visitor pass creation |
| `Transport_probe_f14.php:119` | test fixture | probe only |

**STORAGE**

> **NONE.** `ZENX-VERIFIED` — grep for `idCards|studentCards|cardIssuance|cards` across
> `application/` returns only unrelated CSS/JS. `Firestore_helper`'s collection constant table has no
> card entry. The student document carries no card field.

**CONSUMERS (validate a credential)**

| Site | Unsigned accepted? | Status gate | Verdict |
|---|---|---|---|
| `Attendance.php:4246` `scan_qr` | **YES** | ✅ Active-only | marks attendance |
| `Transport_qr_attendance_service.php:185` | **NO — rejects** | eligibility via `studentRoutes` | already hardened |
| `Visitor_service.php:782` → `Campus_access::scan_qr_lookup` | **YES** | pass must exist | **read-only**, RBAC-gated |

**Not consumers** (corrections to the prior blast-radius claim): `Campus_access_verifier.php:170` is a
**self-test harness**; `Sis::qr_image` renders pixels and never authorizes.

**UI ENTRY POINTS** — `SOURCE-VERIFIED`, and this corrects the phase brief's premise:

ID Cards is **already a top-level nav item**, not buried in Student Record:
`views/include/header.php:684` → *Student Info System → ID Cards*; plus `sis/index.php:89` and
`sis/students.php:271`. Routes `sis/id_card` and `student/id_card`. Staff cards are separate
(`Staff::teacher_id_card`).

### B.2 What the ID-card page actually does

`Sis::id_card()` (`:3340`): RBAC `SIS/view`; loads the cached student snapshot; filters `status == Active`
**in PHP**; intersects with enrolled IDs; sorts class → section → name; renders every card. Client-side
class/section filter and a text search. Three print actions: **Print One, Print Filtered, Print All**.

`ZENX RECOMMENDATION` note: bulk print therefore **already exists** and should not be listed as a new
requirement. What does not exist is *selective* print (checkbox multi-select) and any notion of a print
**queue** or reprint record.

---

## C. COMPLETE CREDENTIAL FLOW

```
Admission → studentId (STU####, permanent, internal FK)
          → srNo      (permanent, school-facing)  ← never in the credential
                │
     ID Cards page renders
                │
     qr_token_encode(schoolId, studentId)
       payload = "{schoolId}|{studentId}|{sig}"
       sig     = first 16 hex of HMAC-SHA256(secret, "{schoolId}|{studentId}")
                │
       base64url → SVG (chillerlan/php-qrcode, inline data: URI)
                │
       printed on paper ────────► NOTHING IS PERSISTED
                │
     scan → qr_token_decode() → 3-part: verify HMAC
                                2-part: ACCEPT, flag legacy   ← SEC-ID-2
                │
       consumer applies its own tenant + status + eligibility checks
```

**The critical property:** the credential is *derived*, not *issued*. Nothing distinguishes card #1 from
card #5 because they are the same 64 bits.

---

## D. SECURITY FINDINGS — Part 5 answered point by point

| | Question | Answer (`SOURCE-VERIFIED` unless noted) |
|---|---|---|
| A | What does the token contain? | `schoolId`, `studentId`, 64-bit truncated HMAC. **No version, no issue date, no nonce, no expiry.** |
| B | Who creates it? | `views/sis/id_card.php:308` at render; `Visitor_service` for passes |
| C | Who validates it? | `qr_token_decode()`, then each consumer's own checks |
| D | Who accepts unsigned? | `Attendance::scan_qr`, `Campus_access::scan_qr_lookup` |
| E | Who rejects unsigned? | `Transport_qr_attendance_service::decodeStudentToken` |
| F | Lost card? | **Nothing happens. It stays valid.** No report path, no status, no revocation |
| G | Stolen card? | Identical — the system cannot represent "stolen" |
| H | Replaced card? | Replacement produces the **same credential**. "Replacement" is only a reprint |
| I | Old credential still valid? | **Yes, necessarily** — it is the same credential |
| J | One student's credential for another? | **No** for signed (HMAC binds studentId, test-proven). **Yes** for unsigned — forge any studentId |
| K | Across schools? | **No** — tenant check on every consumer |
| L | Forgeable? | **Yes, trivially, unsigned.** `base64url("SCH_X\|STU0012")`. Student IDs are sequential ⇒ enumerable |
| M | Revoke exactly one card? | **No** |
| N | Distinguish old vs new card? | **No** |
| O | Issuance record? | **No** |
| P | Audit record? | **No** for cards. `auditLogs` exists but nothing writes card events |
| Q | Withdrawal? | Card **stops working** — `scan_qr` Active gate. ✅ |
| R | Re-admission? | New studentId ⇒ **new credential**. Old record must already be non-Active ⇒ **old card dead**. ✅ |
| R.1 | New Student ID on re-admission? | **Yes** — and SRNO is deliberately preserved via `relink()` |
| R.2 | Previous credentials? | Dead via the status gate, not via revocation |
| S | Section transfer? | **No effect** — credential binds neither class nor section. ✅ correct |
| T | Promotion? | **No effect.** ✅ correct |

**The security model is "identity + status", not "credential lifecycle".** It is coherent, and for
lifecycle transitions it is genuinely correct — promotion, transfer, withdrawal and re-admission all
behave properly *by construction*. It has exactly one hole: a credential cannot be invalidated
independently of the student.

---

## E–F. MIGRATION OPTIONS AND RECOMMENDATION

`NOT ESTABLISHED` — still zero telemetry. SEC-ID-3 was fixed in 19C but **has not been deployed**, so no
historical or current evidence of legacy usage exists. I will not estimate a number.

| | Option | Operational risk | Security | Complexity | Data model | Verdict |
|---|---|---|---|---|---|---|
| A | Reject unsigned now | **Severe** — attendance scanning stops school-wide until every card is reprinted | closes SEC-ID-2 instantly | trivial | none | **Reject** |
| B | Dual-accept + deadline | Low — cards keep working, usage logged | SEC-ID-2 stays open during window | low | none | **Phase 1** |
| C | Per-student forced reissue | Low, granular | closes SEC-ID-2 **and** SEC-ID-1 | high | card doc + version | **Phase 2 endgame** |

**`ZENX RECOMMENDATION` — the prior recommendation is CONFIRMED, with one refinement.**

B then C remains correct. The refinement: **B is not a migration strategy on its own, it is a
measurement window.** B alone can never close SEC-ID-2 safely, because flipping acceptance off by date
kills any card not yet reprinted. Only C can retire cards individually. So:

> **B measures. C migrates.** Deploy SEC-ID-3 → observe one full attendance cycle → build the card model
> (C) → reissue per student, each reissue closing that student's legacy exposure → flip acceptance off
> when the card model reports zero remaining legacy holders. **The deadline is then a measured
> consequence, not a guess.**

**Rollback:** every step is additive. Legacy acceptance is a single branch in `qr_token_decode()`; the
card model is a new collection read only by new code. Nothing existing changes behaviour until the final
flip, which is one boolean.

---

## G. PROFESSIONAL ID CARDS & IDENTITY WORKSPACE

**Correction to the brief's premise:** ID Cards is *already* a top-level nav item under Student Info
System — it is **not** embedded in Student Record. The problem is not placement, it is that the page is a
**print sheet with no operational surface**.

`ZENX RECOMMENDATION` — evolve the existing route rather than create a competing one:

| Area | Verdict | Rationale |
|---|---|---|
| Card register (search, status, version, issue/replace dates) | **REQUIRED** | the missing core |
| Search by **SR No. / Admission No. / name / class** | **REQUIRED** | see SRNO-4 |
| Issue / Reissue with **reason capture** | **REQUIRED** | reason drives the fee |
| Revoke (single card) | **REQUIRED** | SEC-ID-1 |
| Card history per student | **REQUIRED** | audit |
| Status filters (Active / Lost / Stolen / Damaged / Revoked / Replaced) | **REQUIRED** | |
| Print one / filtered / all | **ALREADY EXISTS** | keep |
| **Selective** multi-select print | **RECOMMENDED** | reprint batches after replacement |
| Fee status per replacement | **RECOMMENDED** | |
| Templates / designer | **FUTURE** | `NOT ESTABLISHED` as a requirement |
| **Print queue** | **FUTURE** | `NOT ESTABLISHED` — I found no evidence schools need a queue; browser print is the actual workflow. The prior report's suggestion is **not** supported |

**Student Record** keeps a concise read-only summary (current card, status, version, issue date, last
replacement) that **links into** the workspace. `ZENX RECOMMENDATION`: no card *operations* in Student
Record — one home for lifecycle actions.

---

## H. CARD LIFECYCLE STATE MACHINE (`ZENX RECOMMENDATION` — for approval, not built)

Deliberately minimal. **Separate `status` from `reason` from `event`**, as the brief requires, and do not
invent statuses the school cannot act on.

```
                 ┌──────────► REVOKED ──────────┐
                 │           (no successor)      │
   (none) ──► ACTIVE ──► REPLACED ──► (superseded by v+1 ACTIVE)
                 │
                 └──────────► EXPIRED (only if the school sets a validity)
```

- **`status`** — `active` | `replaced` | `revoked` | `expired`. Four, not eight.
- **`reason`** — `first_issue` | `lost` | `stolen` | `damaged` | `wear` | `data_correction` | `admin`.
  *Lost/stolen/damaged are **reasons**, not statuses* — this is the modelling error most designs make.
- **`event`/history** — append-only: `issued`, `replaced`, `revoked`, `reprinted`.

A student has **at most one `active` card**. Issuing v+1 atomically sets v to `replaced`.
Withdrawal/TC does **not** need a card status change — the status gate already handles it, and adding
one would duplicate authority.

**Credential V2** (design only): payload `{schoolId, studentId, cardVersion, sig}`, sig binding all three.
Validation = HMAC ✓ **and** `cardVersion == the student's current active version`. That single added
comparison is what makes revocation and replacement real. Legacy 2-part and V1 3-part remain acceptable
during the window.

---

## I. REPLACEMENT / REISSUE WORKFLOW (`ZENX RECOMMENDATION`)

```
Report (reason + optional evidence)
  → RBAC gate (SIS/manage — printing is view today; issuing must be stronger)
  → old card: status=replaced|revoked, revokedBy, revokedAt, reason
  → new card: version+1, status=active, issuedBy, issuedAt
  → fee: raised ONLY if policy says this reason is chargeable
  → audit: log_audit(...) under `meta`
  → print
```

**Ordering decision (`BUSINESS DECISION REQUIRED`, ID-D3):** revoke-then-issue means the student is
without a working card between the two. Recommended: **revoke and issue in one atomic operation**, and
charge separately — never gate issuance on payment (see J).

---

## J. FEES INTEGRATION — the significant finding

### J.1 `createModuleFee()` cannot be used as-is. `ZENX-VERIFIED`.

`Fee_lifecycle.php:999-1000`:

```php
$periodKey = sprintf('%04d-%02d', $year, self::ACADEMIC_START_MONTH);   // always {sessionYear}-04
$demandId  = $this->_demandId($studentId, $periodKey, '', $headName . '_' . $moduleType);
// → DEM_{studentId}_{sessionYear}04_{HEAD}_{MODULE}
```

**There is exactly ONE demand per (student, feeHead, module) per session.** That is correct for
Transport and Hostel, which model a *recurring subscription amended in place*. It is wrong for a card
replacement, which is a *discrete chargeable event*.

**What would actually happen** if replacement charging naively called `createModuleFee`:

- 1st replacement → demand created for ₹X.
- Parent pays ₹X.
- 2nd replacement, same session → **same demandId** → amount rewritten to ₹X (not ₹2X) → FEES-FROZEN-1
  logic computes `balance = X + 0 − X = 0`, `status = 'paid'`.
- **The second replacement is silently free, and the ledger says it was paid.**

This inverts Part 9's question. The risk is not duplicate charges from repeated clicks — it is **missing
charges**, plus a false `paid` state. Note this is *not* a defect in the 19B fix; the fix is behaving
correctly for a demandId that should never have been reused.

**Registered as ID-D2 (design constraint), not a defect** — nothing charges card fees today, so nothing
is currently broken.

### J.2 The safe design — no new fee engine, no change to `createModuleFee`

`ZENX RECOMMENDATION`: make the **card version part of the fee head**, which the existing contract
already supports because `_demandId` derives its key from the head name:

```
fee_head = "ID Card Replacement (Card v3)"   → DEM_STU0012_202604_ID_CARD_REPLACEMENT_CARD_V3_IDCARD
```

Distinct per replacement, naturally idempotent (re-clicking the same replacement hits the same demandId
and rewrites identical values), inherently auditable, and **the parent sees a meaningful line on the
receipt**. Zero modification to `Fee_lifecycle`. Category `idcard` (lowercase, per the F10 convention).

### J.3 Policy — `INDUSTRY PRACTICE`, with an honest limit

US higher-ed is consistent and well-documented:

- **First issue free** — Iowa, Berkeley.
- **Lost is chargeable** — $25 (UCSD, Dartmouth 1st/2nd), $30 (CMU), $35 (Iowa), $40 (Syracuse).
- **Escalating for repeat loss** — Dartmouth $25 → $50 from the third.
- **Stolen waived with a police report** — CMU.
- **Damaged free or reduced on surrender of the old card** — Dartmouth (normal wear free), CMU $10 with
  surrender, Syracuse (wear waived).

**`NOT ESTABLISHED`:** I found **no authoritative Indian K-12 norm** for school ID replacement charges.
The Indian search surfaced only CBSE *admit-card* duplicates — a different artefact under board
jurisdiction. A school ID card is a school-internal artefact with no external standard to inherit.

> This matters, and it is the same trap this programme hit before with late-fee "grace periods": the US
> higher-ed **structure** generalises well; the **amounts do not**, and none of it is law here.

**`ZENX RECOMMENDATION` — ship the structure, let each school set the numbers. Nothing hardcoded.**

Per-school config, all amounts defaulting to **0 (free)** so no school is silently charged by an upgrade:

| Reason | Default | Configurable |
|---|---|---|
| `first_issue` | free | amount |
| `lost` | 0 | amount, escalation after N |
| `stolen` | 0 | amount, "waive with evidence" flag |
| `damaged` | 0 | amount, "waive on surrender" flag |
| `wear` | free | amount |
| `data_correction` / `admin` | free | — school's own error, never chargeable |

**Answers to Part 9's remaining questions** (`ZENX RECOMMENDATION`):
- **Charge before or after issuance?** After — raise the demand, issue the card immediately.
- **Card without payment?** **Yes.** Withholding a student's identity credential over an unpaid fee is
  punitive and breaks attendance. The fee is a receivable, not a gate.
- **Outstanding dues?** Irrelevant to issuance. Do **not** couple.
- **Failed payment?** The demand simply stays unpaid — the existing defaulter/reminder chain handles it,
  now correctly, post-FEES-FROZEN.
- **Waiver?** Use the existing concession/waiver path; must record who waived and why.
- **Parent app / receipt / accounting?** Automatic — a `feeDemands` row flows through the existing
  reader, receipt and ledger. **Ledger account needs a decision (ID-D4).**

---

## K. ACCOUNTING IMPLICATIONS

`SOURCE-VERIFIED` precedent: `Operations_accounting.php:1403-1414` posts late fees to a distinct account
(**4060 Late Fee**), so head-specific ledger mapping already exists.

`BUSINESS DECISION REQUIRED` **ID-D4** — is an ID-card replacement charge **fee income** or **cost
recovery**? It is a reimbursement of a consumable, not tuition. Recommend a distinct revenue account
rather than folding it into general fee income, so it never inflates fee-collection analytics.

---

## L–N. CROSS-SYSTEM AUDIT

### L. Admin Panel — missing
Card register · status · version · issuance record · revocation · replacement · reason capture · card
history · SR No./Adm No. search · selective print · replacement-fee policy config · per-card audit.
**Present and working:** rendering, QR, print one/filtered/all, class/section filter, tenant isolation.

### M. Teacher App — `SOURCE-VERIFIED`

| Capability | Status |
|---|---|
| View student identity | ✅ |
| See SRNO | ✅ `"Roll X · SR Y"`, `AttendanceScreen.kt:865` |
| Scan/use credential | ❌ **no QR code of any kind** — grep for `idcard\|qrToken` returns nothing |
| Card status / lost / replacement | ❌ absent |

**Classification: NOT REQUIRED (card ops) / FUTURE (scanning).** Teachers mark attendance in-app from a
roster; QR scanning is a gate/kiosk workflow. Do not invent it.

### N. Parent App — `SOURCE-VERIFIED`

| Capability | Status |
|---|---|
| View identity | ✅ |
| View SRNO | ✅ Profile "SR No." row, `ProfileScreen.kt:335` |
| View ID-card info | ❌ **none** |
| Request replacement | ❌ none |
| See replacement charge | ⚠️ **would appear automatically** once the demand exists — no work needed |
| Card status / notification | ❌ none |

> **Named honestly because the name invites the opposite conclusion:** `StudentIdCard` in
> `DashboardScreen.kt:801` is a **gradient welcome banner** (name / class / school / today's status). It
> is **not** a credential, has no QR, and does not even show SRNO.

**Classification:** view card status = **RECOMMENDED**; report lost = **FUTURE** (needs the card model
plus an approval workflow); see the charge = **automatic**; digital card in-app = **NOT REQUIRED** and
arguably harmful (a screenshot-able credential is weaker than paper, and undermines revocation).

---

## O. STUDENT LIFECYCLE INTEGRATION

| Transition | Student ID | SRNO | Adm No. | Roll No. | Credential | Card status |
|---|---|---|---|---|---|---|
| Admission | new | **allocated** | allocated | assigned | minted on print | *n/a today* |
| Promotion | same | **preserved ✅** | same | **reassigned** | unchanged ✅ | should persist |
| Session rollover | same | **preserved ✅** | same | reassigned | unchanged ✅ | should persist |
| Section transfer | same | **preserved ✅** | same | may change | unchanged ✅ | should persist |
| Re-admission | **NEW** | **preserved via `relink()` ✅** | new | new | **new** ✅ | new card |
| Withdrawal / TC | same | preserved | same | — | **dead via status gate ✅** | should become revoked |
| Alumni / deactivation | same | preserved | same | — | dead ✅ | revoked |

**All three brief requirements verified `SOURCE-VERIFIED`:** promotion does not change SRNO; section
transfer does not change SRNO; re-admission preserves SRNO. **No path lets Roll No become the permanent
identity** — and the one document that had made that mistake (the TC) was fixed in 19C.

---

## P. SRNO MANDATORY AUDIT

Canonical model re-verified in 19D against `Sr_no_service.php`: `srNo` display, `srNoNorm` ownership key,
`srNoSource` provenance; `srNoIndex/{schoolId}_{srNoNorm}`; school-scoped; session-independent;
permanent; never a foreign key.

### ID-card workflow specifically

| Aspect | Status | Detail |
|---|---|---|
| SRNO generation | ✅ | automatic on admission |
| SRNO read | ✅ | `id_card.php:258` reads three spellings |
| SRNO display on card | ✅ | `:337-341` "SR No." row |
| SRNO print | ✅ | on the printed card |
| **SRNO search** | ❌ **SRNO-4** | search matches `data-name` + `data-uid` only (`:422`) |
| SRNO sorting | ❌ | sorts class → section → name only |
| SRNO in card history | ❌ | no history exists |
| SRNO in QR | **correctly absent** | the credential must bind the **internal FK**, not the display identity |
| Teacher App | ✅ | |
| Parent App | ✅ | |

### New SRNO defects found this phase

**SRNO-4 — ID-card search cannot find a student by SR No. or Admission No.** `SOURCE-VERIFIED`
(`id_card.php:205` placeholder *"Search by name or Student ID…"*; `:422` matches only name + uid). A
school knows its students by SR No.; to reprint a card the operator must first look the Student ID up
elsewhere. **Severity: MEDIUM (usability/workflow).**

**SRNO-5 — the printed card presents Student ID as the primary identity.** `SOURCE-VERIFIED`
(`id_card.php:330-334`: Student ID rendered `color:var(--idc);font-weight:700`; SR No. plain). Student ID
is the **internal FK**; SRNO is the **permanent school-facing identity**. The visual hierarchy is
inverted, and **Admission No. is absent from the card entirely.** **Severity: MEDIUM (identity
semantics).** *Not fixed in this phase — this is a research phase and the card is a printed artefact
whose layout is a school-visible decision (ID-D5).*

**SRNO-3** (from 19C, unchanged): `Sr_no_service.php:264` comment claims `claim()` writes the student
doc's `srNo`; it does not — the controllers do. Cosmetic.

**Student ID vs SRNO — the semantic separation holds everywhere else.** I checked and did **not** find
Student ID misused as a foreign key substitute, nor SRNO used as an FK. The separation is correct; only
the *presentation* layer (SRNO-4/5) confuses them.

---

## Q. UI GAPS

| Gap | Evidence |
|---|---|
| No card status anywhere | no storage |
| No replacement workflow | — |
| No revoke control | — |
| No reason capture | — |
| No card history | — |
| No confirmation on print | `onclick="idcPrintAll()"` fires immediately |
| Search excludes SR No./Adm No. | SRNO-4 |
| No selective (checkbox) print | only one/filtered/all |
| **RBAC asymmetry** | printing the **entire school's** cards needs only `SIS/view` (`:3341`). Issuing/revoking must require `SIS/manage`. **ID-D6** |
| No duplicate-click guard | irrelevant today (no writes); **becomes critical** once issuance writes |

**No fake buttons found.** Every control on the ID-card page does what it says — the page is honest,
merely incomplete. Per Part 15 there is nothing to make honest and no dead setting to remove.

---

## R. PRINT / DOCUMENT AUDIT

| Document | SRNO | Adm No. | Student ID | Roll No. | Verdict |
|---|---|---|---|---|---|
| ID card | ✅ | ❌ absent | ✅ **over-emphasised** | ✅ | **SRNO-5** |
| TC | ✅ (added 19C) | ✅ (fixed 19C) | — | — | **fixed** |
| Receipts | ✅ | — | ✅ | — | OK |
| Import credentials PDF | ✅ | ✅ | ✅ | — | OK |
| Section roster | ✅ | ✅ | ✅ | ✅ | OK — separate columns |
| Student 360 | ✅ | ✅ | ✅ | ✅ | OK |

**Unrelated "serial" fields re-confirmed distinct:** `tc['serial_no']` = the TC's own serial;
`"S.No"` = a row counter; `"Admission Register"` = CBSE DOB wording.

---

## S–T. PERFORMANCE AND FIRESTORE COST

**Today:** `id_card()` costs ~1 cached snapshot read + 1 school doc. QR rendering is local CPU
(`chillerlan`), zero network. **Efficient — do not regress this.**

**The N+1 trap the card model would introduce:** rendering 800 cards must **not** fetch 800 card
documents. `ZENX RECOMMENDATION`: one `querySchool('idCards', [...])` for the whole school, indexed by
`schoolId + status`, joined in PHP — exactly the pattern `_cachedStudentDocsForRollCheck()` already
establishes. Budget: **+1 query per page render**, not +N.

Write cost per replacement: 1 card write + 1 supersede write + 1 fee demand + 1 audit = **4 writes**,
comfortably inside a single batch.

**`U. Rules implications (research only — nothing modified):`** a new `idCards` collection needs a
`match` block: server-only writes, `isSameSchoolWrite()` on every path, and read access for the student's
own parent **only if** the Parent App ever surfaces card status. A composite index on
`schoolId + studentId + version` and on `schoolId + status` would be required. **Not written, not
deployed — flagged for the implementation phase.**

---

## V. TEST PLAN (designed, not written)

**Credential:** legacy accepted+flagged · signed accepted · tampered rejected · signature not
transferable between students · cross-school rejected · **revoked version rejected** · **superseded
version rejected** · current version accepted.

**Lifecycle:** first issue · lost → revoke → reissue · damaged w/ surrender · stolen w/ evidence ·
duplicate replacement request is idempotent · old credential fails immediately after replacement ·
withdrawal · re-admission (new credential, **SRNO preserved**) · promotion (credential unchanged, SRNO
unchanged) · section transfer (both unchanged).

**Fees:** replacement raises exactly one demand · **second replacement raises a SECOND distinct demand**
(the ID-D2 regression) · repeated clicks on the *same* replacement do not duplicate · waiver ·
unpaid does not block issuance · receipt · accounting posting · parent-app visibility.

**SRNO:** preserved across every transition · searchable by SR No. · displayed on card · never used as FK.

**UAT (Admin / Teacher / Parent) — explicitly NOT executed and not claimable in this phase.** Requires a
live tenant, a printer, and a physical scan device.

---

## W–X. DEFECT AND DEFERRED REGISTER

| ID | Sev | Location | Type | Reason deferred | Prerequisite |
|---|---|---|---|---|---|
| **SEC-ID-1** | **HIGH** | credential design | architecture | business decision | ID-D1 |
| **SEC-ID-2** | **HIGH** | `qr_token_helper.php:148` | security | closing it bricks cards | card model + reissue |
| **SEC-ID-3** | — | fixed 19C | telemetry | **deployment pending** | authorization |
| **ID-D2** | **HIGH (design)** | `Fee_lifecycle.php:999` | constraint | not a live defect — nothing charges card fees yet | design approval |
| **SRNO-4** | MED | `id_card.php:205,422` | usability | belongs to workspace build | implementation phase |
| **SRNO-5** | MED | `id_card.php:330-334` | identity semantics | printed-artefact layout is a school decision | ID-D5 |
| **SRNO-3** | LOW | `Sr_no_service.php:264` | wrong comment | cosmetic | — |
| **ID-D6** | MED | `Sis.php:3341` | RBAC | `SIS/view` prints all cards | decision |
| FEES-D16 | — | `createModuleFee` | policy | business decision | — |
| FEES-D7 / summary reconciliation | — | — | data | production credential | authorization |
| FEES-UAT | — | — | — | live tenant | operator |
| EXAM-LC1 | HIGH | `Roster_helper::for_class` | correctness | **other module** | Examination phase |
| LC-1 residual (~10) | MED | various | correctness | **other modules** | per-module phases |

---

## Y. BUSINESS DECISIONS REQUIRED

| ID | Decision | Recommendation |
|---|---|---|
| **ID-D1** | Migration strategy | **B to measure, C to migrate.** Deploy SEC-ID-3 first |
| **ID-D2** | Fee identity per replacement | Card version in the fee head — no `createModuleFee` change |
| **ID-D3** | Revoke/issue ordering | Atomic single operation; never gate issuance on payment |
| **ID-D4** | Ledger account for replacement income | Distinct account, not general fee income |
| **ID-D5** | Card layout: promote SR No. over Student ID? add Adm No.? | Yes to both |
| **ID-D6** | RBAC for issue/revoke | `SIS/manage`; keep printing at `view` |
| **ID-D7** | Replacement charges per reason | Structure now, **all defaults 0**; each school sets amounts |
| **ID-D8** | Card validity/expiry | **Recommend none** — no evidence Indian K-12 expires cards |

---

## Z. RECOMMENDED IMPLEMENTATION SEQUENCE

1. **Deploy SEC-ID-3** (19C, already written). *Nothing else can be justified without telemetry.*
2. **19E — card model + credential V2.** `idCards` collection, version, status/reason/history; V2 encode
   /decode accepting legacy + V1 + V2. **No consumer behaviour change yet.**
3. **19F — lifecycle operations.** Issue / reissue / revoke with reason + audit; atomic supersede;
   `SIS/manage` gate.
4. **19G — validation flip.** Consumers check `cardVersion == active`. Old cards start failing *only*
   after their student has been reissued.
5. **19H — workspace UI.** Register, search (incl. SR No./Adm No. — SRNO-4), status filters, history,
   selective print, confirmations.
6. **19I — fee integration.** Per-school policy, version-scoped fee head, waiver, receipt, ledger.
7. **19J — legacy retirement.** When the card model reports zero legacy holders, flip acceptance off,
   closing SEC-ID-2. *Measured, not dated.*
8. **19K — Parent App card status** (RECOMMENDED, optional).

Steps 2–4 are additive and individually deployable. **SEC-ID-2 closes only at step 7 — by evidence.**

---

## FINAL ANSWERS

1. **Production-safe?** **No** for attendance. Tolerable elsewhere: transport fails closed, the visitor
   path is read-only behind RBAC, and withdrawal/re-admission/cross-school are all correctly blocked.
2. **Can a lost card be revoked today?** **No.** Not for one card, not at all — short of rotating the
   secret and killing every card in every school.
3. **Can an unsigned card be forged today?** **Yes**, trivially, and Student IDs are enumerable. Signed
   cards are not forgeable and are not transferable between students.
4. **Safest migration?** **B to measure, C to migrate.** Deploy SEC-ID-3, observe, build the card model,
   reissue per student, then flip.
5. **Dedicated workspace?** **Yes** — but evolve the existing top-level ID Cards page. It is already
   correctly placed; it lacks an operational surface, not a home.
6. **Replacement-fee policy?** Structure from US higher-ed (first free, lost chargeable, escalating,
   stolen waived with evidence, damaged waived on surrender) — **amounts per school, defaulting to 0**.
   No Indian K-12 norm exists to inherit.
7. **Old cards after replacement?** Revoked and rejected at validation — which requires credential V2.
8. **Withdrawal / re-admission?** **Already correct.** Both kill the old credential via the Active gate;
   re-admission mints a new one and preserves SRNO.
9. **Human-facing identity?** **SR No.** — with Admission No. for register/board context. Student ID is
   internal and should be de-emphasised (SRNO-5).
10. **Where must SRNO appear?** Card (primary), TC ✅, receipts ✅, rosters ✅, search ❌ (SRNO-4), card
    history (when built), both apps ✅.
11. **Missing in Admin?** The entire card model, lifecycle, revocation, history, fee policy, and SR
    No./Adm No. search.
12. **Missing in Teacher App?** Nothing required. Scanning is FUTURE at most.
13. **Missing in Parent App?** Card status = RECOMMENDED. Replacement request = FUTURE. A digital card is
    **NOT RECOMMENDED** — a screenshot-able credential undermines revocation.
14. **Implement first?** **Deploy SEC-ID-3**, then the card model (19E).
15. **Must NOT be implemented yet?** Rejecting unsigned tokens (needs telemetry); any replacement
    *charging* (needs ID-D2/D4/D7); credential V2 validation enforcement (needs the model + reissue path);
    any production backfill; and any UAT claim.

---

**Nothing was modified in this phase.** Uncommitted work from 19B/19C remains as reported.
**ID Cards are NOT closed. Fees are NOT closed.**
