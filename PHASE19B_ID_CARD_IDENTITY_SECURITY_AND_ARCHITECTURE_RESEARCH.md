# Phase 19B — ID Cards & Identity: Security + Architecture Research

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**RESEARCH ONLY — NO CODE MODIFIED**, as instructed. Nothing committed / pushed / deployed.
No production data touched. Rules/indexes untouched. Fees, Attendance, Examination not modified.

---

## 1. STOP — READ THIS BEFORE THE ARCHITECTURE SECTIONS

You asked me to design an ID Cards & Identity workspace and integrate replacement fees. **I did not
get that far, because tracing the credential surfaced something that outranks the whole feature.**

| ID | Finding | Severity |
|---|---|---|
| **SEC-ID-2** | **Anyone can forge a working ID credential with no secret.** `qr_token_decode()` accepts **2-part UNSIGNED** tokens — plain `base64url("{schoolId}\|{studentId}")` — and `Attendance::scan_qr` **proceeds** on them. Student IDs are sequential (`STU0001`, `STU0002`…) and school IDs are `SCH_XXXXXX` | 🔴 **CRITICAL** |
| **SEC-ID-3** | **The telemetry meant to close that window is silently discarded.** The legacy-token usage line uses `log_message('warning', …)` — **`WARNING` is not a CI3 log level** (`ERROR`/`DEBUG`/`INFO`/`ALL`), so it is dropped; `log_threshold=1` would drop it anyway | 🔴 **HIGH** |
| **SEC-ID-1** | A lost/stolen card **cannot be revoked** — the token is a pure function of `(schoolId, studentId)`, carries no version, and no revocation list exists. A reprinted card is byte-identical to the lost one | 🔴 **HIGH** |

**SEC-ID-2 is strictly worse than SEC-ID-1.** Revocation only matters once someone physically holds a
card. Forgery needs nothing — no card, no theft, no secret. A guessed student ID is enough.

**SEC-ID-3 is what makes it unfixable-by-observation:** the code's own plan is *"we can flip acceptance
off once every active card is reissued"* — but the evidence needed to know that has **never been
written to disk.**

**Consequence for your actual request:** an ID-card replacement fee built today would charge a family
to replace a card that (a) the finder can still use, and (b) an attacker never needed in the first
place. **I am not going to build that.**

---

## 2. CREDENTIAL — CURRENT STATE, VERIFIED INDEPENDENTLY FROM SOURCE

`helpers/qr_token_helper.php`

```
signed (3-part) : base64url("{schoolId}|{studentId}|{sig}")
                  sig = HMAC-SHA256(_qr_secret(), "{schoolId}|{studentId}") — 16 hex chars
legacy (2-part) : base64url("{schoolId}|{studentId}")        ← NO SIGNATURE, STILL ACCEPTED
```

`qr_token_decode()` returns `['schoolId','studentId','legacy'=>true]` for the 2-part form.
`Attendance::scan_qr` (≈4258) then does **nothing but log** and continues.

**Answering your ten questions directly:**

| Question | Answer |
|---|---|
| Do two cards for the same student get the same credential? | **Yes — identical.** The payload is `(schoolId, studentId)` only |
| Can a lost card be revoked? | **No.** No version field, no revocation list (repo-wide search: every `revoke` hit is Firebase Auth admin handling) |
| Does replacement invalidate the old card? | **No.** The reprint is the same token |
| Does a withdrawn student become invalid? | **Yes** — `scan_qr` checks `status`/`Status` for `Active` (correctly reads both shapes) |
| Is status checking sufficient? | **No.** It is the *only* control, and it is all-or-nothing per student — you must deactivate the child to stop the card |
| Card version? | **Does not exist** |
| Card issuance history? | **Does not exist** |
| Revocation mechanism? | **Does not exist** |
| Is QR verification tenant-safe? | **Yes** — cross-school tokens rejected, with `CROSS_TENANT_PROBE` telemetry and an existence-oracle-safe 404 |
| Can tokens be replayed? | **Yes** — the token is static and carries no nonce or expiry; replay is inherent to a printed card, but combined with SEC-ID-2 it means a *forged* token also replays indefinitely |

**Blast radius — scoped precisely rather than left implied:**

| Surface | Uses the QR credential? | SEC-ID-2 exposure |
|---|---|---|
| **Student ID card** — `Sis::id_card()` (:3340), `student_id_card.php` | ✅ yes | 🔴 **affected** |
| **Campus-access / visitor pass** — `Campus_access_verifier.php:169` mints via the **same helper** for `passId` | ✅ yes | ⚠ **likely affected — per-consumer validation not traced.** Flagged, not asserted |
| **Staff / teacher ID card** — `Staff::teacher_id_card()` (:3647), `teacher_id_card.php` | ❌ **no QR at all** (verified: no `qr_token_encode`, no QR renderer) | ✅ **not affected** — print-only card |

**Teacher App:** a source scan found **no reference** to `qr_token` or ID-card handling — the only
matches were a compiled `.dex` build artifact and replay logs, neither of which is source evidence.
**Conclusion: no evidence the Teacher App consumes the credential.** I am not claiming it definitely
does not; I am claiming there is no source basis to say it does. **Parent App: not traced.**

## 3. INDUSTRY RESEARCH

| Topic | Finding | Label |
|---|---|---|
| Replacement is charged for a **lost** card | $15–$30 across institutions ([Columbia $20](https://ssc.columbia.edu/replace-id-card), [UCSC $30](https://idcards.ucsc.edu/general-information/replacement-cards/), [Berkeley $25](https://bsa.berkeley.edu/news/notification-fee-replace-lost-cal-1-card), [RePublic $15](https://republiccharterschools.org/student-id-replacement-form/)) | **SOURCE-VERIFIED** (US higher-ed) |
| **Damaged** card replaced **free on surrender of the old card** | Berkeley | **SOURCE-VERIFIED** |
| **Stolen** waived with a police report | [CampusIDNews](https://www.campusidnews.com/lost-id-card-it-will-cost-you/) | **SOURCE-VERIFIED** |
| **Aged** cards replaced free | [Illinois State](https://redbirdcard.illinoisstate.edu/card-security/) | **SOURCE-VERIFIED** |
| Charged to the student billing account | [USD](https://usdkb.sandiego.edu/s/article/Student-ID-Card-Replacement-and-Fees) | SOURCE-VERIFIED |
| **Reporting a card lost immediately deactivates it** | Berkeley / Illinois State both describe reporting-and-deactivation as the first step | **SOURCE-VERIFIED** — and ZenX cannot do this at all |
| Card **status lifecycle** (ACTIVE/LOST/REVOKED/REPLACED…) as a named model | **NOT ESTABLISHED BY SOURCES** — vendors describe *actions*, not a documented state machine | — |
| Bulk generation / print queue / template management in **school** ERPs | **NOT ESTABLISHED BY SOURCES** at documentation level | — |
| Any of the above for **Indian K-12** specifically | **NOT ESTABLISHED BY SOURCES** | — |

**The single most useful external finding:** every institution's process begins with *report it lost →
the card is deactivated*. **Deactivation is the product, and the fee is incidental to it.** ZenX has
the fee half available and the deactivation half missing — which is exactly backwards.

## 4. GAP MATRIX

| Capability | ZenX today | Gap |
|---|---|---|
| Generate a card | ✅ `Sis::id_card()` + `views/sis/id_card.php` | — |
| Signed credential | ✅ HMAC available | ⚠ unsigned still accepted (SEC-ID-2) |
| Tenant isolation | ✅ | — |
| Withdrawn student blocked | ✅ | — |
| **Revoke one card** | ❌ | **SEC-ID-1** |
| **Card version** | ❌ | prerequisite for revocation |
| **Issuance history** | ❌ | needed for audit |
| **Report lost / stolen** | ❌ | the industry-standard first step |
| **Replacement workflow** | ❌ | — |
| **Replacement charge** | ⚠ mechanism exists (`createModuleFee`) | blocked behind the above |
| **Bulk generation / print queue** | ❌ | NOT ESTABLISHED as required |
| **Dedicated workspace** | ❌ | lives inside the student record |
| Teacher App / Parent App card surface | **NOT VERIFIED** | trace incomplete — I will not claim either way |

## 5. RECOMMENDED ARCHITECTURE (design only — nothing built)

**Credential v2 payload:** `{schoolId}|{studentId}|{cardVersion}|{sig}` where
`sig = HMAC(secret, "{schoolId}|{studentId}|{cardVersion}")`.

**Card record** (`studentCards`, one per issuance): `studentId · cardVersion · status · issuedAt ·
issuedBy · reason · replacedBy · revokedAt · revokedBy`.

**Status set:** `ACTIVE · LOST · STOLEN · DAMAGED · REVOKED · REPLACED · EXPIRED`.
⚠ These are a **ZENX RECOMMENDATION**, not an industry model — no source documents a named state
machine. Recommend starting with the minimum that supports revocation (`ACTIVE / REVOKED / REPLACED`)
and adding reason codes as metadata rather than as statuses.

**Verification becomes:** signature valid → **and** `cardVersion` matches the student's current active
card → **and** student is Active.

### Migration strategy — the decision that matters (D24)

| Option | Effect |
|---|---|
| **A. Reject unsigned now** | Closes SEC-ID-2 **immediately**. **Every legacy printed card stops working** on the day it ships |
| **B. Dual-accept with a deadline** | Existing cards keep working; the hole stays open until the deadline. **Requires SEC-ID-3 fixed first**, otherwise you cannot see who is still on legacy |
| **C. Per-student forced reissue** | Safest sequencing, most operational effort |

**ZENX RECOMMENDATION:** **fix SEC-ID-3 first (a one-word change: `'warning'` → `'error'`)**, run for a
short observation period to learn how many legacy scans actually occur, *then* choose between A and B
on evidence rather than guesswork. **That single-word fix is the cheapest, highest-value action
available in this whole area**, and it is the prerequisite for making an informed migration decision.

## 6. FEES INTEGRATION (design only)

Ownership split exactly as you specified — ID Cards owns reason, status, revocation, issuance,
history; Fees owns charge, demand, payment, receipt, accounting, refund. The charge would use the
existing canonical one-off path (`Fee_lifecycle::createModuleFee`), **not** a new fee engine.

⚠ **Coupling carried from Phase 19A:** `createModuleFee` currently drops `fine_amount` when
recomputing balance (**FEES-FROZEN-1**). Harmless while module late fees are off — but an ID-card
demand is a module demand, so this must be fixed before any module late fee is enabled.

⚠ **No fee amount will be hardcoded.** Per-reason policy (lost / damaged / stolen / aged) must be
school-configurable, and each charge must carry an auditable reason. **The policy itself is a business
decision** — the external evidence is US higher-ed only.

## 7. UI INFORMATION ARCHITECTURE (design only)

Today: `Sis::id_card()` + `views/sis/id_card.php`, reached from the student record. There is **no**
dedicated workspace, no template management, no print queue, no card list, no status filter.

Proposed **ID Cards & Identity** workspace: Dashboard · Student cards (status filter) · Pending /
Active / Lost-Stolen / Revoked / Expired · Replacement requests · Bulk generation · Print queue ·
Templates · Verification · Card history · Settings.
Student record keeps a **read-only summary** — current status, card version, issue date, last
replacement — linking into the workspace.

⚠ **Bulk generation, print queue and template management are NOT ESTABLISHED BY SOURCES** as required
school-ERP capabilities. I am listing them because you named them, **not** because research supports
them as necessary. I would build the workspace around **revocation and replacement first**, and treat
templates/print-queue as separate, evidence-led scope.

## 8. IMPLEMENTATION PLAN — ordered by what actually protects a child

| # | Item | Sev | Blocker |
|---|---|---|---|
| **1** | **SEC-ID-3** — `log_message('warning')` → `'error'` so legacy usage is visible | 🔴 | **none — I can do this now** |
| 2 | Observation period; measure legacy scan volume | — | needs deployment + time |
| 3 | **SEC-ID-2** — stop accepting unsigned tokens (Option A or B) | 🔴 CRITICAL | **D24** — depends on step 2 |
| 4 | **SEC-ID-1** — card version + `studentCards` + revocation; scanner checks version | 🔴 | D24 |
| 5 | Report lost/stolen → revoke → reissue workflow | 🟡 | step 4 |
| 6 | ID Cards & Identity workspace | 🟡 | step 5 |
| 7 | Replacement charge via `createModuleFee` | 🟡 | step 5 + **FEES-FROZEN-1** + policy decision |
| 8 | Bulk generation / templates / print queue | 🟢 | NOT ESTABLISHED — evidence first |

**I did not implement step 1** because this phase was explicitly research-first and you asked for the
plan before code. It is a one-word change and I can do it immediately on your word.

## 9. DECISIONS REQUIRED

| ID | Decision |
|---|---|
| **D24** | 🔴 **Migration strategy for unsigned tokens** — reject now (breaks every printed card) vs dual-accept with a deadline. **Recommend: fix SEC-ID-3 first, measure, then decide** |
| **D25** | Authorize the immediate SEC-ID-3 one-word logging fix |
| **D26** | Card status model — minimal (`ACTIVE/REVOKED/REPLACED` + reason metadata) or the full seven-status set |
| **D27** | Per-reason replacement fee policy (lost / damaged / stolen / aged) — school-configurable amounts |
| **D22** | (carried) Authorize fixing FEES-FROZEN-1 — prerequisite for any module fee |

## 10. RISKS

**Doing nothing:** forged credentials remain trivially mintable, and there is no log to prove whether
it has happened. **Fixing SEC-ID-2 carelessly (Option A):** every printed card in every school stops
working the day it deploys. **Building the fee first:** you charge families for a replacement that
does not replace the credential.

## 11. TESTS / UAT (to be written when implementation is authorized)

Unit: signed token accepted · **unsigned token rejected** · wrong-signature rejected · cross-school
rejected · version mismatch rejected · revoked card rejected · withdrawn student rejected · reissue
mints a new version · old version fails after reissue.
UAT: scan a real card · scan an old card after replacement (**must fail**) · report lost → old card
fails immediately · verify the replacement charge and receipt · verify audit history.
**No UAT has been executed at any point in this programme.**

## 12. VERDICT

**ID CARDS & IDENTITY = BLOCKED (SECURITY).**
Not because the feature is hard, but because building the requested feature on top of an unauthenticated
credential would make the security position worse while appearing to improve it.

**Deferred register updated:** **SEC-ID-1** · **SEC-ID-2** 🔴 CRITICAL · **SEC-ID-3** ·
plus all carried items (FEES-D7/D9/D13/D14/D15, FEES-FROZEN-1, FEES-UAT, **EXAM-LC1**, LC-1 remaining,
LC-2, ATT-2, ATT-UI, CS-ROLL, CAP-2).

---

## 13. WHAT THIS MEANS FOR A REAL SCHOOL

**Right now, someone can walk into your school using a QR code they made on a laptop.**

The code on a student ID card is meant to be signed by your server, so only cards you printed work.
The system does produce signed codes — but it **also still accepts unsigned ones**, left over from
before signing was added. An unsigned code is just the school's id and a student's id, run through a
basic encoding anyone can do. Student ids run in sequence — STU0001, STU0002 — so someone doesn't even
need to see a real card. They can guess.

The scanner notices this and writes a note to say "an old-style code was used". **That note is thrown
away.** It's written at a level the system doesn't recognise, so it never reaches the log file. The
plan in the code was to stop accepting old codes "once every card has been reissued" — but with no
record of who is still using them, that day can never arrive.

**And the thing you actually asked me to build has the same problem underneath it.** A replacement fee
assumes a lost card can be cancelled. It can't. The code on a card is worked out purely from the
school and student ids, so the replacement is **identical** to the lost one. Reprinting changes
nothing. Charging for it would take money for a fix that doesn't fix anything, which is exactly the
kind of thing we've spent this programme removing.

Every school system I looked at starts the same way: **you report the card lost, and it stops working
immediately.** That deactivation *is* the service. The fee is a footnote. ZenX has the footnote and
not the service.

**The cheapest useful thing I can do is one word.** Changing how that ignored note is written makes
old-code usage visible in your logs. Once you can see whether anyone is still using old cards, you can
decide whether to switch them off immediately — which is safest but stops every printed card that day
— or run a changeover period. Right now that decision would be a guess.

**I haven't changed anything.** You asked for research before code, and given what this turned up, I'd
want your explicit go before touching how cards are validated — that's the kind of change that can
lock an entire school out at the gate on a Monday morning.

