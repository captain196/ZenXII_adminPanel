# Phase 19A — Fees Final Completion: Pre-Implementation Research

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**RESEARCH ONLY — NO CODE MODIFIED**, as instructed. Nothing committed / pushed / deployed.
No production data touched. Rules/indexes untouched. Attendance and Examination **not** touched.

---

## 1. HEADLINE — READ FIRST

Three findings materially change the Fees completion plan, and **two of them correct my own earlier
reports**.

| # | Finding | Impact |
|---|---|---|
| **1** | **Phase 2 of Fees Exemption v2 is ACTIVE and COMMITTED** — `USE_UNIFIED_FEE_GEN = true`, `CONCESSION_UI_ENABLED = true`. My earlier reports repeatedly said "flags stay FALSE". **Outdated.** Only **Phase 3** remains frozen | Narrows the freeze considerably |
| **2** | **FEES-2 is NOT blocked by the freeze.** `createModuleFee` is not forbidden — Hostel and Transport already call it. The freeze concerns *replacing* it in Phase 3, not *using* it. **My earlier "blocked by frozen surface" conclusion was wrong** | The charge path is available today |
| **3** | 🔴 **SEC-ID-1 — a lost or stolen ID card CANNOT be revoked.** The QR credential is `HMAC-SHA256(secret, "{schoolId}\|{studentId}")` — **no version, no nonce, no expiry.** Every card ever printed for a student produces the **identical** token, and **no revocation list exists** | **This is the real FEES-2 blocker, and it is a security defect in its own right** |

**And one hard limit I must state up front:** FEES-4 requires **real UAT execution**. I have no browser
or device. **I cannot execute it, therefore I cannot declare Fees CLOSED** — your own instruction says
so explicitly, and I am not going to work around it.

---

## 2. FEES-1 — DATELESS DEMAND BACKFILL

**Current state (ZENX-VERIFIED):** the dry-run planner exists —
`Fee_late_fee_service::planDueDateBackfill()` + `Fees_late_fee_cron::plan_due_date_backfill`.
It classifies each dateless demand as:

- **reconstructable** — carries a canonical `periodKey` (`YYYY-MM`); proposed date is
  `periodKey + getDueDay()`, byte-identical to what the live generator produces today;
- **ambiguous** — no canonical periodKey; **reported, never guessed.**

**It writes nothing.** There is deliberately no `--commit` flag and no write path in the class.

⚠ **Known limitation, restated:** reconstruction applies the school's **current** due day. If a school
changed that setting mid-year, a rebuilt date may differ from what was operationally true. ZenX stores
no history of the setting, so this is undetectable from data.

### BOUNDARY — I cannot proceed

**No service-account credential exists on this machine** (re-verified this phase). I cannot run the
planner, therefore I **cannot produce the exact dry-run result** you asked for, and I will not
estimate counts.

**Exact ask:**
```
SCHOOL_ID=SCH_xxxxxx SESSION_YEAR=2026-27 php index.php fees_late_fee_cron plan_due_date_backfill
```
Share the output and I will build the migration against real numbers — with idempotency, verification
and a separate ambiguous-records report.

**Acceptance condition:** every reconstructable demand carries `periodKey + dueDay`; every ambiguous
record is listed and untouched; re-running the migration is a no-op.

## 3. FEES-2 — ID CARD REPLACEMENT (the big correction)

### 3.1 The freeze is not the blocker

| Assertion in my earlier reports | Source truth |
|---|---|
| "`createModuleFee` is on the frozen surface; ID-card charges must wait for the entitlements cutover" | **WRONG.** `Hostel.php:360` and Transport already call it in production. Phase 3 will *converge service generation off* it — that is a replacement plan, not a prohibition |
| "Flags stay FALSE" | **OUTDATED.** `USE_UNIFIED_FEE_GEN` and `CONCESSION_UI_ENABLED` are **committed as `true`** |

**Charge mechanism available today:** `Fee_lifecycle::createModuleFee($studentId, $moduleType, $feeData)`
— the only per-student ad-hoc charge path. `Fee_generation_service::generateDemandsForStudent()` is
structure-driven (class/section fee structure) and cannot express a one-off charge. **ZENX-VERIFIED.**

⚠ **FEES-FROZEN-1 still applies to that function** (`Fee_lifecycle.php:1043` drops `fine_amount` from
the balance). It stays **unreachable** while module late fees are off (FEES-3) — but an ID-card charge
would create module demands, so **FEES-3 and FEES-2 become coupled**: enabling module late fees while
FEES-FROZEN-1 is open would let an ID-card fine be silently erased.

### 3.2 🔴 SEC-ID-1 — the real blocker, and a live security defect

`helpers/qr_token_helper.php`:

```
signed token = base64url("{schoolId}|{studentId}|{sig}")
sig          = HMAC-SHA256(_qr_secret(), "{schoolId}|{studentId}")
```

**The payload is a pure function of (schoolId, studentId).** No card version, no issue timestamp, no
nonce, no expiry. Repo-wide search for a revocation list, card version or blocklist: **nothing exists**
(every `revoke` hit is Firebase Auth refresh-token handling for admins).

`Attendance::scan_qr` validates: HMAC signature · tenant match · **student status `Active`** (correctly
reading both `status` and `Status`).

**Therefore:**

1. **A lost or stolen ID card cannot be revoked.** Whoever holds it can keep scanning in.
2. **Reprinting the card changes nothing** — the new card carries the *same* token as the lost one.
3. The **only** existing mitigation is marking the student non-Active — plainly unacceptable for a
   child who merely lost their card.

**This is a security defect independent of Fees, and it is why an "ID-card replacement fee" cannot
honestly be built yet.** Charging a family for a replacement while the lost card still opens the gate
would be selling a remedy that does not remedy anything — precisely the false-promise class this
programme has spent phases removing.

### 3.3 What FEES-2 actually requires

| Component | Status |
|---|---|
| Charge (`createModuleFee`) | ✅ available now |
| Receipt / accounting | ✅ existing paths |
| Reason codes (lost / damaged / stolen / aged) | ⛔ **BUSINESS DECISION** — see below |
| Authorization + audit | ✅ patterns exist (`log_audit`, MANAGE+reason) |
| **Old credential invalidation** | ⛔ **ARCHITECTURAL — token format must carry a version/nonce** |
| Card version tracking | ⛔ does not exist |

**Token-change impact (INFERENCE, flagged):** adding a version to the payload changes the token for
**every** student, so **every existing printed card stops validating** unless a migration/dual-accept
window is designed. That is a material architectural change and a rollout event for the school — not
something to slip into a fees phase.

**External evidence for reason codes (SOURCE-VERIFIED, US higher-ed only):** charge for lost
([Columbia $20](https://ssc.columbia.edu/replace-id-card), [UCSC $30](https://idcards.ucsc.edu/general-information/replacement-cards/),
[Berkeley $25](https://bsa.berkeley.edu/news/notification-fee-replace-lost-cal-1-card)) · **damaged
replaced free on surrender of the old card** (Berkeley) · **stolen waived with a police report**
([CampusIDNews](https://www.campusidnews.com/lost-id-card-it-will-cost-you/)) · **aged cards replaced
free** ([Illinois State](https://redbirdcard.illinoisstate.edu/card-security/)).
**NOT ESTABLISHED for Indian K-12** — I will not present these as your policy.

**Recommended sequencing:** SEC-ID-1 (token versioning + revocation) **first**, as a security item in
its own right; FEES-2 charge afterwards. Building the charge first would ship a fee that solves
nothing.

## 4. FEES-3 — TRANSPORT / HOSTEL / MODULE LATE FEES

**Established so far (SOURCE-VERIFIED):** hostel late fees are real — *"If hostel fees are not paid by
the due date, late fees such as ₹500/- per month may be charged"*
([Matrix High School](https://www.matrixhighschool.org/fee-structure)). Transport is configured as
separate fee slabs ([Campus24x7](https://campus24x7.in/blogs/school-fee-structure-erp-setup-guide-india)).
Fee heads and penalties are tracked separately (INDUSTRY PRACTICE).

**NOT ESTABLISHED BY SOURCES:** whether module fees share the academic late-fee *policy*, and how
suspension/cancellation interacts with a penalty. The 3+ independent sources you asked for do not
exist for that specific question — I checked across the vendors used throughout this programme.

**ZENX RECOMMENDATION (unchanged, and now with a hard prerequisite):** per-module opt-in, **default
OFF**, honouring suspension / cancellation / entitlement exemption before any module fee is penalised.

⛔ **HARD PREREQUISITE, newly established this phase:** **FEES-FROZEN-1 must be fixed before module
late fees are enabled.** `createModuleFee` drops `fine_amount` when recomputing balance, so the first
module fine would be silently erased on the next regeneration. Today that defect is unreachable
*because* module late fees are off — enabling them without fixing it would make it live.
**This coupling was not previously identified.**

## 5. FEES-4 — UAT: HARD BOUNDARY

**I cannot execute browser or device UAT.** No browser, no device, no running application instance.
The 31-row Fees matrix (plus everything accumulated since) remains **entirely unexecuted** — as it has
been for this whole programme.

Per your instruction — *"If browser/device execution is unavailable, explicitly say so and do NOT call
Fees closed"* — **Fees cannot be declared closed by me.** No amount of further static work changes
that.

## 6. FEES-5 — FINAL RE-SCAN (achievable now)

Deliverable I *can* produce without a credential or browser: a re-scan for dead settings, fake success
paths, duplicate fee engines, RTDB, and Admin/Parent integration drift. Last full sweep (Phase 15.9)
found zero dead settings after the WhatsApp fix; a fresh sweep would confirm nothing regressed across
Phases 16–18.

## 7. IMPLEMENTATION PLAN — what I can actually finish

| Item | Can I complete it? | Blocker |
|---|---|---|
| FEES-1 backfill | ❌ | **Credential** — needs one command run by you |
| FEES-2 ID-card charge | ❌ | **SEC-ID-1 architecture** + reason-code policy |
| **SEC-ID-1 token versioning** | ⚠ **design yes, implement = your call** | Changes every printed card — a school rollout event |
| FEES-3 module late fees | ⚠ partial | Needs **FEES-FROZEN-1 fixed first** + policy decision |
| **FEES-FROZEN-1** | ✅ **likely safe now** | See below |
| FEES-4 UAT | ❌ | **No browser/device** |
| FEES-5 re-scan | ✅ | none |

### The one item I recommend implementing next

**FEES-FROZEN-1** — `Fee_lifecycle.php:1043`, `$balance = max(0.0, round($net - $paidSoFar, 2))`
drops `fine_amount`. It is:

- the **prerequisite** for FEES-3 (module late fees), now proven coupled;
- structurally identical to the F-1 fix already shipped in `proRateFees()` (Phase 15.5);
- currently **unreachable**, so fixing it changes no live behaviour;
- a **formula change inside an existing function** — it does not restructure `createModuleFee` and
  therefore does not pre-empt Phase 3's convergence.

**Acceptance:** module fee + posted fine → re-run `createModuleFee` → `balance == net + fine − paid`
and `fine_amount` unchanged.

⚠ I have **not** implemented it — this is a research phase, and it touches a surface previously
declared frozen. **I want your explicit go**, because my read that it is safe rests on the freeze
being about *replacement* rather than *modification*, and you own that call.

## 8. DECISIONS REQUIRED

| ID | Decision |
|---|---|
| **D22** | Authorize fixing **FEES-FROZEN-1** now (unreachable today, prerequisite for FEES-3) |
| **D23** | **SEC-ID-1**: authorize token versioning + revocation? It invalidates every printed card unless a dual-accept window is built |
| **FEES-D13** | Module late-fee policy — per-module opt-in, default OFF? |
| **FEES-2 policy** | Charge for lost · free for damaged-on-surrender · waived for stolen-with-report · free when aged? (US higher-ed evidence only) |
| **FEES-D7** | Run the backfill planner and share output |
| **FEES-D14** | SMS/WhatsApp provider |
| **FEES-D15** | `firestore_bulk_sync` disposition |

## 9. DEFERRED REGISTER — updated

**New this phase:** **SEC-ID-1** 🔴 (lost ID cards cannot be revoked — token carries no version;
security, not fees) · **FEES-3⇄FEES-FROZEN-1 coupling** (module late fees must not be enabled first).

**Carried:** FEES-D7 · FEES-D9 · FEES-D13 · FEES-D14 · FEES-D15 · FEES-FROZEN-1 · FEES-UAT ·
ATT-2 · ATT-UI · **EXAM-LC1** 🔴 (`Roster_helper::for_class` camelCase-only — Examination/Result
students can vanish; **explicitly preserved, not forgotten**) · LC-1 remaining (~10 predicates) ·
LC-2 · CS-ROLL items · CAP-2.

## 10. VERDICT

**FEES = BLOCKED — cannot be closed by me.**
Three independent blockers: a credential I do not have (FEES-1), an architectural security gap
(SEC-ID-1 → FEES-2), and UAT I cannot execute (FEES-4).

**Nothing was implemented this phase**, as instructed.

---

## 11. WHAT THIS MEANS FOR A REAL SCHOOL

**The most important thing I found has nothing to do with money.**

You asked me to add a fee for replacing a lost ID card. Looking at how the cards actually work, I
found that **a lost card cannot be cancelled.** The code on the card is worked out purely from the
school's id and the student's id — nothing else. So the card printed today and a replacement printed
tomorrow carry **exactly the same code**, and there is no list anywhere of cancelled cards.

If a child loses their card, whoever picks it up can keep using it to enter the school, and printing a
new one does not change that. The only way to stop it today is to mark the child as no longer
enrolled — which is obviously not something you'd do because they mislaid a card.

**So charging for a replacement would be selling a fix that doesn't fix anything.** I'd rather tell
you that than build the fee.

Making cards cancellable is doable, but it means changing what's printed on them — which means
**every existing card stops working** unless we plan a changeover period. That's a decision for you,
and a real event for the school, not something to slip in quietly.

**Two things I got wrong before and am correcting.** I'd said the fees system was frozen and the
ID-card charge had to wait for that. It doesn't — the hostel and transport modules already use the
same charging route, and the "freeze" is about eventually replacing that code, not about using it.
I'd also said certain fee settings were switched off; two of them have since been switched **on**
with your authorisation, and my reports hadn't caught up.

**Two things I simply cannot do from here.** I can't run the report that shows how many old fee
records are missing due dates — that needs a live connection to your data, which I don't have. And I
can't test any of this in a browser. Over a hundred and twenty test steps have now built up across
this whole programme and **not one has been performed**.

**So I won't tell you Fees is finished.** By your own rule it can't be, and by mine it shouldn't be.

**The one thing I'd fix next** is small and invisible: a calculation in the fees code that wipes out a
late-fee charge when a transport or hostel bill is recalculated. It can't affect anything today
because those late fees are switched off — but it's the exact thing that would bite the moment you
turn them on, which is what you've asked for next. I've left it alone because it sits in code
previously marked off-limits, and I'd like your go-ahead rather than assuming.
