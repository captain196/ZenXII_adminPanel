# PHASE 19N — TARGET #2: ID CARD REPLACEMENT CHARGES

**Date:** 2026-08-16 · **Branch:** `integration/phase2`
**Status: READY FOR UAT — one product decision outstanding (ID-D7 amounts)**

Not committed · not pushed · not deployed · **no rules/indexes changed** · Attendance, Examination and
Library untouched · ID-card security migration not reopened · no RTDB.

---

## 1. EXECUTIVE SUMMARY

Tracing the charging chain end-to-end found **three defects that would each have broken replacement
charging in production**. All three are fixed.

| ID | Sev | Defect | State |
|---|---|---|---|
| **ID-ACCT-1** | **HIGH** | The fee head matched no accounting keyword → every replacement credited **4010 Tuition Fees**, booking a consumable reimbursement as tuition revenue. | **FIXED → 4140** |
| **ID-CFG-1** | **HIGH** | Per-school amounts were a **DEAD READ** — the policy looked them up in `feeSettings`, **nothing ever wrote them**, and no screen existed. "Configurable" charges could never be configured, so every replacement was **silently free forever**. | **FIXED** |
| **ID-DUE-1** | **MED** | The demand carried **no `dueDate`**, so both the reminder service and the late-fee engine skip it — an unpaid replacement charge would sit unchased indefinitely. | **FIXED** |

**ID-CFG-1 is the one that mattered most**, and it was mine: 19I/19K/19L all reported the fee mechanism
as "built, amounts default to zero", which was true — and useless, because there was no way to make them
non-zero. The feature was complete on paper and inert in reality.

**Regression: 1404 tests / 3920 assertions / 10 failures — the identical baseline, 0 new.**
41 new tests. Lint clean. RTDB clean.

---

## 2. WORKTREE (Part 0)

519 dirty/untracked paths from Phases 19B–19L, none disturbed. `firestore.rules` and
`firestore.indexes.json` **not touched — and not required** for this target. `Operations_accounting.php`
was already dirty from earlier work; the ID-ACCT-1 edit is a **single additive array entry**, applied by
exact-string match, disturbing nothing around it.

## 3. RE-VERIFICATION (Part 1)

Re-traced in live source and confirmed working: `Id_card_service` · credential V2 · `issue()` ·
replace/reissue · revoke · supersede · history · reason · operator note · `expectedCurrentVersion` ·
duplicate-click · two-operator protection · physical V2 printing · SRNO · four-field search · Admin UI ·
fee demand creation. **Nothing previously marked fixed was found broken.** The three defects above are
gaps in the *charging* chain that no previous phase traced to its end.

## 4. ID-D4 ACCOUNTING — **4140 confirmed**

Evaluated 19L's recommendation against live source rather than adopting it.

`SOURCE-VERIFIED` — the seeded chart (`Accounting.php:4051-4062`) has **no 4070**, and
**`4140 Miscellaneous Income`** exists under `4100 Other Income`, seeded active.

**Confirmed correct, and `4070` deliberately not created:**
- An ID-card replacement is **cost recovery for a consumable**, not fee income. Booking it beside
  Tuition/Transport/Hostel under `4000 Fee Income` would overstate Fee Income in every report.
- 4140 needs **no new production accounting structure** — the brief's own constraint.

## 5. ID-ACCT-1 — FIXED (Part 4)

One additive keyword in `Operations_accounting::_resolveAccount()`:

```php
'4140' => ['id card', 'identity card'],
```

**One keyword covers every version**, because the head always *starts* with "ID Card Replacement" —
no per-version mapping, which would need extending forever. Test-asserted for v1…v99.

**Proven, not assumed:**

| Fee head | Resolves to |
|---|---|
| `ID Card Replacement (Card v2 … v99)` | **`4140` Miscellaneous Income** |
| — **never** — | ~~`4010` Tuition Fees~~ |
| Transport / Hostel / Late / Tuition | unchanged — 4040 / 4050 / 4060 / 4010 |

Also verified: no earlier keyword steals the head (`replacement` does not contain `late`), and a school
that has removed 4140 degrades to the documented 4010 default via the existing
`isset($coa[$code]) && active` guard rather than erroring.

> **A test bug worth recording.** My first version of the resolver mirror failed for every version. PHP
> coerces numeric array **keys** to `int`, so `$code` was `4140` not `'4140'` and my strict `in_array`
> against string codes never matched. **Production is unaffected** — the real resolver uses
> `isset($coa[$code])` (same normalisation) and declares a `: string` return type. My mirror was wrong,
> not the code under test.

## 6. ID-CFG-1 — FIXED: the charges were unconfigurable

`Id_card_fee_policy::schoolOverrides()` reads `feeSettings/{schoolId}_{session}_idcard`. **Nothing wrote
it. No UI existed.** Every prior report's "per-school configurable, default ₹0" was therefore half true:
configurable in architecture, impossible in practice.

**Added:**
- `sis/id_card_fee_settings` (read) and `sis/id_card_fee_settings_save` (write).
- A Bootstrap-3 **Replacement Charges** modal in the ID Card workspace.
- Validation: numeric, non-negative, and a **₹100,000 ceiling** — not a policy statement, a typo guard,
  so nothing can silently bill a family six figures for a plastic card because a zero was held down.
- `log_audit('Fees', 'id_card_replacement_fees_updated', …)` with the payload under `meta`.

> **RBAC — gated on `Fees:manage`, not `SIS:manage`.** Setting what a family is charged is a **money**
> decision, not a student-records one. An office operator who may issue and replace cards must not
> thereby decide what those replacements cost. This is the only endpoint in the ID-card workspace gated
> on a different module, and that asymmetry is deliberate — a separation of duties, test-asserted.

## 7. ID-DUE-1 — FIXED: the charge is now chaseable

`createModuleFee()` stamps no `dueDate` (only the tuition convergence path does). `SOURCE-VERIFIED`
consequences of a dateless demand:

- `Fee_reminder_service::stageFor()` returns null without a `dueDate` → **the family is never reminded**;
- `Fee_late_fee_service::fineFor()` returns null → it can never age into defaulter escalation.

An unpaid replacement charge would have sat invisible forever. The payload now carries
`due_date`, set by the **caller** (`Id_card_fee_policy::withDueInDays()`, default **30 days**) rather
than invented inside the policy object — a policy must not invent a billing calendar. Verified that
`Fee_firestore_txn::_buildDemandDoc()` picks `due_date` into the demand's `dueDate` field, so it reaches
the document. `Fee_lifecycle.php` **unmodified**.

## 8. ID-D7 REPLACEMENT AMOUNTS (Part 5) — **DECISION REQUIRED**, now with Indian evidence

| Evidence | Class |
|---|---|
| **ITM University Gwalior charges ₹100 for a duplicate identity card** | `SOURCE-VERIFIED` — **Indian**, higher-ed |
| **IGNOU charges nothing** for a duplicate ID card | `SOURCE-VERIFIED` — **Indian**, higher-ed |
| Indian school ID replacement fees "typically ₹100 upwards, **as per school policy**" | `SOURCE-VERIFIED` — vendor/aggregator, non-authoritative |
| First issue free · loss chargeable · escalating repeat loss · stolen waived with police report · damaged waived on surrender | `SOURCE-VERIFIED` — **US higher-ed**, structure only |
| **A binding Indian K-12 norm** | **`NOT ESTABLISHED`** — every Indian source defers to institutional policy |
| ₹0 default, per-school configurable | `ZENX RECOMMENDATION` |
| The actual amounts | **`USER DECISION REQUIRED`** |

**This is a genuine improvement on prior phases** — Indian evidence now exists where I previously found
none. But it is **higher-ed, not K-12**, and its consistent message is *"as per school policy"*. That
confirms the architecture (per-school, ₹0 default) rather than supplying a number.

### What I need you to decide

| Reason | Chargeable? | Recommended default | Recommended range |
|---|---|---|---|
| `first_issue` | **never** — structurally excluded | — | — |
| `lost` | yes, if configured | **₹0** (ship) → ₹100 typical | ₹50–₹200 |
| `stolen` | yes, if configured | **₹0** | ₹0–₹100 — commonly waived with a police report |
| `damaged` | yes, if configured | **₹0** | ₹0–₹100 — commonly waived on surrender of the old card |
| `wear` · `data_correction` · `admin` | **never** | — | — |

**The never-chargeable set is re-verified as correct and is enforced structurally**, not merely defaulted
to zero: a config attempting `['wear' => 500]` still yields ₹0 and no demand (test-asserted). A parent
must not pay for normal wear or for the school's own data error.

**Exact configuration required from you:** the three amounts, set per school at
*ID Cards → Replacement Charges* (persisted to `feeSettings/{schoolId}_{session}_idcard`). **I have
invented nothing** — ship values remain ₹0, so no school starts charging as a side effect.

## 9. MULTIPLE REPLACEMENTS (Part 6) — verified

v1 → **v2 lost** → **v3 damaged** → **v4 lost**, each producing an independent demand:

```
DEM_STU0012_202604_ID_CARD_REPLACEMENT_CARD_V2_IDCARD
DEM_STU0012_202604_ID_CARD_REPLACEMENT_CARD_V3_IDCARD
DEM_STU0012_202604_ID_CARD_REPLACEMENT_CARD_V4_IDCARD
```

Every earlier demand, reason, version and history entry survives. **No replacement can silently become
free** because an earlier demand exists — the failure ID-D2 exists to prevent, now covered to v4.

## 10. IDEMPOTENCY / CONCURRENCY (Part 7) — re-tested, not rebuilt

ID-RACE-1 re-verified: two submissions asserting the same version → the second returns
`version_conflict` with **no write**, so exactly **one** chargeable version results. Double-click, retry
and refresh share this server path. **Two tabs / two operators** is the same path, but the browser-level
case still **requires real UAT**.

## 11. UI (Part 8)

All ID-card operations remain in the dedicated workspace — nothing added to Student Record. Search
(SRNO / Adm No. / Student ID / name) · current card · issue · replace · revoke · history · reason · note ·
print · print selected — plus, new this phase, **replacement charge status**:

- A **charge preview inside the replace dialog**: *"This will raise a replacement charge of ₹150, due in
  30 days"* — or *"No charge — this reason is free under your school's policy."* The operator must know
  whether the action bills the family **before** confirming; discovering it from a later receipt is
  exactly the surprise that erodes trust in the panel.
- **Never guesses about money:** if amounts cannot be read, it says so explicitly rather than implying
  "free".
- **Revoke never previews a charge** — it issues no card, so it raises nothing.
- The charges screen **names what is never chargeable**, so those rows' absence reads as policy rather
  than oversight.

Loading states, disabled buttons, duplicate-click guards, truthful success/error, retry — all retained.
**No native `alert`/`prompt`/`confirm` reintroduced** (test-asserted against comment-stripped source).

## 12. FEES INTEGRATION (Part 9) — chain traced

```
replacement → reason (validated enum) → Id_card_fee_policy → version-scoped fee head
→ createModuleFee (canonical) → feeDemands (+ dueDate) → balance → payment
→ receipt → journal Cr 4140 → studentFeeSummary → defaulter → Parent App
```

`Fee_lifecycle.php` **unmodified** — a test asserts no ID-card token leaks into it. The policy object
raises nothing itself; the canonical generator writes. **Billing model:** a one-off `Annual`-frequency
demand with a 30-day window — it joins the normal fee cycle rather than inventing a new model, so
receipts, allocation, concessions, exemptions and defaulter logic all apply unchanged.

**Charging is best-effort after issuance** — a credential is a child's identity and must never be
withheld because a receivable could not be written. A failure leaves an uncharged replacement
(recoverable); aborting would leave a child without a card (not). Test-asserted.

## 13. EXISTING FEE-DEMAND SAFETY (Part 10) — no regression

FEES-FROZEN-1 · FEES-FROZEN-2 · AB (dueDate stamping) · F-1 · G-3 · ID-D2 · ID-RACE-1 — all re-verified
present and passing. Fine-aware balance, payment, receipt, accounting and duplicate prevention unchanged.
**No already-fixed Fees code was reopened.**

## 14. PARENT / TEACHER APP (Part 11) — traced, nothing invented

**Parent App** — a replacement demand is an ordinary `feeDemands` row, so it appears **automatically** in
the existing fee list, balance and receipt views. `FeeDemandDoc.kt` reads `feeHead`, `netAmount`,
`balance`, `dueDate`, all of which the demand now carries. **No app change required or made.**

**Teacher App** — reads `FeeDemandDoc` but surfaces no ID-card-specific view. Correct: replacement
charges are a parent/office concern. **No app change.**

## 15. REAL SCHOOL SCENARIOS (Part 12)

| | Scenario | Expected | Verified |
|---|---|---|---|
| A | First card | no charge | ✅ structurally excluded |
| B | Lost | superseded + replacement + configured charge | ✅ |
| C | Damaged | replacement + configured charge | ✅ |
| D | Second loss | independent demand | ✅ to v4 |
| E | Admin mistake | no charge | ✅ |
| F | Normal wear | no charge | ✅ |
| G | Double-click | one replacement | ✅ `version_conflict` |
| H | Two operators | server-side protection | ✅ same path — browser case needs UAT |
| I | Charge paid | correct receipt + **Cr 4140** | ✅ resolver proven; **posting needs UAT** |
| J | Charge unpaid | outstanding balance + defaulter | ✅ now has a dueDate, so it is also **reminded** |

## 16. SRNO CLOSURE GATE (Parts 2 & 16) — **PASS**

| Check | Result |
|---|---|
| ID Card search (SRNO / Adm No. / Student ID / name) | **PASS** |
| ID Card print (SR No. primary, Adm No. present) | **PASS** |
| Fee demand | **PASS** — keys on `studentId` + card version; no identity in the fee path |
| Receipt | **PASS** — SR No. + Roll No. separately labelled |
| Student identity | **PASS** |
| Admin Panel | **PASS** |
| Parent App | **PASS** |
| Teacher App | **PASS** |
| SRNO used as FK | **PASS** — never; zero hits |
| Roll No substituted for SRNO | **PASS** — never; `Id_card_fee_policy` contains no `rollNo` at all |

**SRNO AUDIT: PASS.** Re-run from source, not inherited. Promotion / transfer / rollover still contain
**zero `srNo` references**.

## 17. TESTS (Part 17)

| Metric | Value |
|---|---|
| TOTAL TESTS | **1404** |
| TOTAL ASSERTIONS | **3920** |
| BASELINE FAILURES | **10** (Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2) |
| **NEW FAILURES** | **0** |
| NEW TESTS | **41** |
| LINT | clean (6 files) |
| RTDB | clean |
| RULES / INDEXES | **unchanged — not required** |

**Not "all tests pass"** — the 10 pre-existing failures remain, unrelated and untouched.

## 18. REGISTERS (Part 15)

### FIXED IN THIS PHASE
**ID-ACCT-1** (HIGH) → 4140 · **ID-CFG-1** (HIGH) write path + UI · **ID-DUE-1** (MED) dueDate.

### ALREADY FIXED AND RE-VERIFIED
ID-MINT-1 · ID-RACE-1 · ID-WIRE-1 · ID-VIS-1 · ID-LOAD-1 · ID-D2 · FEES-FROZEN-1 · FEES-FROZEN-2 ·
SRNO-1 · SRNO-4 · SRNO-5.

### STILL OPEN — TARGET #2
**ID-D7** — the three amounts (`lost`/`stolen`/`damaged`). **Product decision; nothing invented.**

### BLOCKED
Browser UAT (no browser) · accounting posting verification (needs a live tenant + a paid receipt) ·
two-tab concurrency at browser level.

### DEFERRED (untouched, not reopened)
SEC-ID-3 deploy · SEC-ID-3b · SEC-ID-1 · SEC-ID-2 · `idCards` rules · ATT-WD-1 · ATT-WD-2 · EXAM-LC1 ·
LC-1 residual · SRNO-3.

## 19. SIX-ITEM PRIORITY STATUS (Part 14)

| # | Item | Status | Note |
|---|---|---|---|
| 1 | Existing old fee demands | **BLOCKED** | FEES-D7 backfill + `studentFeeSummary` reconciliation — both need a **production credential**. `planDueDateBackfill()` measures scope without writing |
| 2 | **ID Card regeneration charges** | **READY FOR UAT** | mechanism complete, accounting correct, configurable, chaseable. **ID-D7 amounts outstanding**; ships at ₹0 so it is safe unconfigured |
| 3 | Transport/Hostel/module late fees | **OPEN** | FEES-D16. Mechanism identified in 19B (module demands carry no `dueDate`); **product decision** on policy |
| 4 | Fees UAT | **BLOCKED** | needs a live tenant + operator |
| 5 | Bulk Roll Assignment + existing-roll policy | **OPEN** | D19 — **product decisions**, not started |
| 6 | Atomic capacity / roll concurrency | **OPEN** | CAP-2 — needs design; `Sr_no_service`'s atomic-claim pattern is the precedent |

## 20. CLOSURE (Part 18)

**ID Card replacement charges: READY FOR UAT — not complete.**

✅ accounting mapping correct (4140, proven) · ✅ configurable charges persist · ✅ replacement demand
correct · ✅ multiple replacements work to v4 · ✅ duplicate submission safe · ✅ UI professional ·
✅ SRNO PASS · ✅ regression 0 new · ⏸ **ID-D7 amounts** · ❌ **UAT not executed**

**I have no browser or scan device. No UAT result is manufactured.** Two things genuinely need a live
run: an end-to-end **paid receipt showing Cr 4140**, and the **two-tab concurrency** case.

**The one thing I need from you:** the `lost` / `stolen` / `damaged` amounts (ID-D7). Everything else in
Target #2 is done — and because the ship default is ₹0, the feature is safe to deploy before you decide.

**Not starting Attendance, Examination or Library.** Awaiting your call on the next priority.
