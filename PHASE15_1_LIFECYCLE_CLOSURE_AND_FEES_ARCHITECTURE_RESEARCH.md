# Phase 15.1 — Class/Section Closure + Fees Architecture Deep Research

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924` · **Type: RESEARCH + AUDIT ONLY — zero code changed.**

**No source files modified. No Firestore rules/indexes touched. No production data read or written.
Nothing committed, staged, pushed, merged, rebased or deployed. UAT NOT EXECUTED.**

**Evidence labels:** **ZENX-VERIFIED** · **SOURCE-VERIFIED** · **INDUSTRY PRACTICE** · **INFERENCE**
· **ZENX RECOMMENDATION** · **NOT ESTABLISHED BY SOURCES**

---

## 1. Executive Summary

The Class/Section work is genuinely closed. **The Fees module is not — and the most serious finding
in this report is that ZenX's fee UI promises automation that does not exist in code.**

**Three findings a school would hit in production, all ZENX-VERIFIED this pass:**

| # | Finding | Severity |
|---|---|---|
| **F-1** | **"Automatic fee reminders" is a dead setting.** `auto_remind` and `days_before_due` are written by `Fee_management`, rendered with a checkbox in `views/fee_management/reminders.php`, and **read by nothing**. A school switches automatic reminders ON, sees it persist, and no reminder is ever sent. | 🔴 **HIGH** |
| **F-2** | **`FEE_DUE` push is registered but never emitted.** It exists in the Cloud Functions `MARK_REGISTRY` (`functions/index.js:123`) with title/body/dataKeys fully defined. **Zero PHP call sites emit it.** The plumbing is built end-to-end except the trigger. | 🔴 **HIGH** |
| **F-3** | **Late fees cannot be applied at scale.** `auto_compute_fines` is a **manual, per-student POST endpoint**. There is **no fee cron** — crons exist for Transport, Backup, Campus Access and B2 analytics, but none for Fees. A 2,000-student school has no way to apply late fees except clicking through students one at a time. | 🔴 **HIGH** |

**Two corrections to prior reports** (both were pessimistic; the code is better than reported):

| Prior claim | ZENX-VERIFIED reality |
|---|---|
| "Late-fee idempotency is unverified — appears to overwrite `fine_amount` each call (double-charge risk if scheduled)." | **It is idempotent.** `Fees.php:4562` — `if (abs($existingFine - $fine) < 0.01) continue;` — computes an absolute value, never `+=`. Re-running is safe. |
| Suspected `due_date` field-name mismatch (camel written, snake read) would make late fees dead even where due dates exist. | **No bug.** `Fee_firestore_txn::normalizeDemandDoc()` explicitly maps `dueDate → due_date` (line 691). I checked specifically because it would have been severe; it is correct. |

**The root Fees blocker is confirmed and unchanged:** `Fee_lifecycle.php` contains **zero**
`dueDate` references (ZENX-VERIFIED by grep). The admission-time demand generator — the source of
most real demands — sets no due date. `compute_fines_for_student` then skips any demand without one
(`Fees.php:4551-4552`). **Late fee is architecturally unreachable for the majority of demands**, and
fixing the due date is the gate that unlocks it.

**Phase 15's own remaining items are re-verified below (§2). None is a defect in what was built;
A/B/D are genuine gaps, C is a documented limitation, E is credential-blocked, F needs a business
decision.**

## 2. Phase 15 Remaining Issue Closure

### A. Import preview does not show capacity projection

| | |
|---|---|
| **Genuinely a defect?** | No — a **usability gap**. Commit-time enforcement is correct and complete (Phase 15). |
| **ZenX today** | ZENX-VERIFIED: no `capacity`/`maxStrength` reference anywhere in the preview/dedupe-check path. Operator learns of over-capacity only *after* clicking Import, via per-row skip reasons. |
| **Production risk** | An operator uploads 60 students for a 40-seat section, waits through the import, then finds 20 rejected. Recoverable but poor. |
| **Real ERPs** | **NOT ESTABLISHED BY SOURCES** — no vendor documentation found showing a capacity preview table. |
| **Severity** | 🟢 **LOW** |
| **Action** | **FIX NEXT** — reuses the exact validator already built; no new reads (same memoised snapshot). |
| **Cost / latency** | +0 reads (snapshot already loaded during validation). |

### B. No full-section override mechanism

| | |
|---|---|
| **Genuinely a defect?** | No — a **business-policy decision that has never been made**. |
| **ZenX today** | ZENX-VERIFIED: no override path exists anywhere. `get_class_sections()`'s own comment claims an admin "must create a new section or **manually override**" — **the implementation never had an override**. The comment describes an intent that was never built. |
| **Production risk** | A Principal legitimately needs to admit a 41st student (sibling, board directive, mid-year transfer-in). Today the only workaround is **editing `maxStrength` itself**, which permanently changes the section's stated capacity instead of granting a one-time exception — and leaves no record of why. |
| **Real ERPs** | **NOT ESTABLISHED BY SOURCES.** |
| **Severity** | 🟡 **MEDIUM** |
| **Action** | **DESIGN FIRST** — needs answers: who may override, is it audited, is it per-admission or per-section? |

### C. Capacity validation is detection-grade, not atomic

| | |
|---|---|
| **Genuinely a defect?** | A **known, documented limitation** — not a regression. It matches the pre-existing promotion/transfer guards exactly. |
| **ZenX today** | Read-then-write. Two admissions into the final seat in the same instant can both pass. |
| **Production risk** | One seat over capacity, occasionally, in a school with concurrent admission operators. Self-correcting on the next admission attempt (which then sees 41/40 and blocks). |
| **Severity** | 🟡 **MEDIUM** |
| **Action** | **DEFER** — an atomic seat-claim primitive should be built **once** and shared by capacity, roll number, promotion and transfer, rather than bolted onto one path. |

### D. No section-capacity audit trail

| | |
|---|---|
| **ZenX today** | ZENX-VERIFIED: zero `log_audit` calls referencing capacity. Rejections are returned to the operator but not recorded. |
| **Production risk** | No forensic answer to "why is 5-A at 41?" — which becomes urgent precisely when an override mechanism (B) exists. |
| **Severity** | 🟢 **LOW** standalone · **rises to MEDIUM if B is built** |
| **Action** | **DESIGN WITH B** — an override without an audit trail would be the actual defect. |

### E. Duplicate Roll Number survey not performed

**ENVIRONMENT BLOCKED**, unchanged. No service-account credential on this machine. Enforcement is
forward-only and cannot corrupt existing duplicates. **Action: authorize a read-only survey script.**

### F. Promotion Roll Number behaviour

**NOT ESTABLISHED BY SOURCES** after a second research pass. No vendor documents roll-number
behaviour during promotion. ZenX carries the roll forward and blocks collisions — the safer of the
two options absent evidence. **Action: BUSINESS DECISION, not an engineering one.**

### G. Newly discovered Class/Section issues

**One found — see §4 (L-1, stale section on the cached snapshot).**

## 3. Class Assignment Research

**Question: should ZenX auto-assign Class from age/DOB?**

**NOT ESTABLISHED BY SOURCES.** No vendor documentation was found defining an age→class rule that
ZenX could safely copy. Age-for-grade norms are also **jurisdiction- and board-specific** (CBSE, ICSE,
state boards and international schools differ), so a single hardcoded rule would be wrong for some
tenants by construction — **INFERENCE**, clearly labelled.

**ZENX RECOMMENDATION: keep Class as an explicit administrative decision.** Do not build an
eligibility engine. If a school needs age validation, that is a per-tenant configurable policy and a
separate authorized project — not a universal rule.

## 4. Class → Section Lifecycle Re-Audit

| Transition | Class | Section | Roll | SRNO | Student ID | Adm No | Capacity |
|---|---|---|---|---|---|---|---|
| Direct admission | set | set | manual/suggest | allocated | created | generated | **✔ enforced (Ph15)** |
| CRM enrolment | from application | operator picker | not set (ZENX-VERIFIED) | allocated | created | generated | **✔ enforced (Ph15)** |
| Bulk import | from file | from file | from file | allocated | created | generated | **✔ projected (Ph15)** |
| Class/Section change (edit) | **preserved** — edit form cannot transfer | preserved | validated | unchanged | unchanged | unchanged | n/a |
| Promotion | changes | changes | **carried forward** | unchanged | unchanged | **frozen** | ✔ pre-existing guard |
| Section transfer | — | changes | carried, collision-checked | unchanged | unchanged | unchanged | ✔ pre-existing guard |
| Withdrawal / TC | preserved | preserved | preserved | preserved | preserved | preserved | **seat freed** (Active-only) |
| Re-admission | new placement | new placement | new validation | **preserved** (relink) | **new** | **new** | ✔ inherited via `enroll_student` |

### L-1 [NEW] — Stale class/section in the shared snapshot after promotion

**🟡 MEDIUM · ZENX-VERIFIED (mechanism) / INFERENCE (impact window)**

The capacity and roll validators read the shared `students_raw` snapshot, invalidated by
`_bustDashboard()` on any `students` write. Promotion writes students through
`Student_write_service::batchMoveStudents()` → `syncStudent`. **I could not confirm that path routes
through `Firestore_service::set/update`** (the methods that call `_bustDashboard`) — it uses
`writeToFirestore('syncStudent', …)`.

**If it does not**, then immediately after a promotion the snapshot still shows students in their
*old* class/section for up to 90 s, so a capacity check on the destination could under-count and a
roll check could miss a collision.

**Not confirmed either way** — it needs one focused trace of `Entity_firestore_sync::syncStudent`.
Flagged rather than asserted. **Action: verify before the next capacity-related change.**

## 5. Section Assignment + the AI Placement Concept

**Existing behaviour (ZENX-VERIFIED):** most-headroom suggestion, operator-overridable, capacity-
enforced server-side since Phase 15. This is sound and should not be replaced.

**On the proposed AI placement engine — evaluated factor by factor:**

| Factor | Assessment |
|---|---|
| Section capacity / headroom | **Legitimate** — already implemented, no AI needed |
| Teacher workload balance | **Legitimate but not a student attribute** — this is timetable/staffing, better solved there |
| Sibling placement | **Legitimate** — but needs the sibling model that does not yet exist (SIB work is separate) |
| Transport route grouping | **Operationally useful**, low risk |
| Language / medium of instruction | **Legitimate** where a school runs parallel mediums — this is an *eligibility constraint*, not a balancing preference |
| **Boys/girls balancing** | **⚠ POTENTIALLY DISCRIMINATORY.** Automated placement on a protected attribute carries real legal/ethical exposure that varies by jurisdiction. **DO NOT automate.** |
| **Academic performance balancing** | **⚠ HIGHLY SENSITIVE.** Algorithmic sorting of children by ability (streaming/tracking) is educationally contested and reputationally hazardous. **DO NOT automate.** |

**ZENX RECOMMENDATION:** if this is ever built, it must be **recommendation-only, with a written
explanation of every factor used, and an explicit human approval step** — never automatic assignment.
Protected attributes (gender) and sensitive attributes (academic performance) should be **excluded
entirely** rather than merely "explained." **NOT ESTABLISHED BY SOURCES** that any real ERP does
AI placement — treat the whole concept as 🔵 FUTURE, not a gap.

## 6. Roll Number — Final Position

Three research passes (Fedena, NextERP, MyClassboard) consistently show **no automatic assignment at
admission**; manual entry or a separate explicit generation operation. ZenX's current model (manual
default + opt-in atomic Suggest + School/Session/Class/Section uniqueness) matches the evidence.
**No change recommended.** Remaining open item is F above (promotion behaviour), which is a business
decision.

## 7. SRNO / Student ID Cross-Module Audit

**Re-verified this pass by the same sweep method: zero modules persist SRNO as a key; Student ID is
the sole foreign key everywhere.** Crossed-pair invariant (`STU0012↔SRNO 47`, `STU0047↔SRNO 12`) is
enforced by tests across four suites.

| Module | SRNO searchable | SRNO displayed | Persists |
|---|---|---|---|
| Fees (counter, browse) | ✔ | ✔ receipt | `studentId` |
| SIS / Student 360 | ✔ | ✔ | `studentId` |
| Transport / Hostel / Library | ✔ | ✔ | `studentId` |
| Attendance / Marks / Homework / Red Flags | ✖ (roster-driven) | ✔ | `studentId` |
| PTM | ✖ | ✔ (denormalised) | `studentId` |
| Certificates / LMS | ✖ **RTDB-blocked** | ✖ | `studentId` |
| Parent / Teacher App | ✖ / ✔ | ✔ | `studentId` |

## 8. FEES — Deep Architecture Audit

### 8.1 The three live operational failures (detail)

**F-1 · Automatic reminders are a dead setting — 🔴 HIGH**

ZENX-VERIFIED: `auto_remind` appears in exactly three places — `Fee_management.php` (writes it),
and `views/fee_management/reminders.php` lines 44/45/315 (checkbox + form serialisation).
**No server-side consumer exists anywhere in the codebase.**

*Why this is worse than a missing feature:* the school is shown a persisted "ON" state. Staff will
reasonably believe parents are being reminded and stop chasing manually. **The silent failure mode is
the damage**, not the absent automation.

**F-2 · `FEE_DUE` push registered but never emitted — 🔴 HIGH**

`functions/index.js:123` defines it completely — audience, `idField: 'studentId'`, type, title, body,
`dataKeys: ['amount','dueDate']`. Repo-wide grep for an `emit_push('FEE_DUE'…)` call site returns
**nothing**. Per CLAUDE.md, `emit_push($mark, …)` is the *only* sanctioned dispatcher — so the entire
delivery chain exists and is simply never triggered.

**F-3 · Late fees are manual and per-student — 🔴 HIGH**

`auto_compute_fines` is a `POST` requiring `student_id`. **No fee cron exists** (ZENX-VERIFIED
against the controllers directory). Applying late fees school-wide is therefore an O(students) manual
operation. For a 2,000-student school this is not usable, so in practice **late fees will simply never
be charged** — a direct revenue-integrity issue.

### 8.2 Fees findings table

| Ref | Area | ZenX today | Severity |
|---|---|---|---|
| **AB** | **Due dates** | `Fee_lifecycle` sets **none** (grep: zero `dueDate`). Only `Fees.php`'s separate bulk generator does. **Root blocker.** | 🔴 **HIGH** |
| K | Late fee calculation | Exists, **idempotent** (correction §1), **flat not accruing** — no per-day growth | 🟡 MEDIUM |
| K | Late fee reachability | Skips demands with no due date → unreachable for admission-generated demands | 🔴 **HIGH** (gated on AB) |
| Z/AA–AD | Reminders / SMS / Email / WhatsApp | Settings dead (F-1); push never emitted (F-2); WhatsApp/SMS **queue only, no dispatcher in-repo** | 🔴 **HIGH** |
| S/T | Receipt numbering | **Atomic** — `feeCounters/{schoolId}_{kind}_claim_{V+1}` create-if-not-exists. **Correct.** | ✅ |
| U | Duplicate receipt / payment | `feeIdempotency` collection + `Accounting_idempotency` claim pattern present | ✅ |
| P/Q | Refunds / reversals | `feeRefunds`, `feeRefundVouchers`, `feeRefundAudit` collections exist with status checks | ✅ (not deeply traced) |
| AG | ID-card replacement fee | **Entirely absent** — no service-charge mechanism | 🟡 MEDIUM |
| AH | Certificate/document fees | **Absent** | 🟢 LOW |
| X | Accounting integration | Present; `Operations_accounting` is **Firestore-only** (Phase 12 correction holds) | ✅ |
| AT | Student ID / SRNO lookup | ✔ both, resolving to Student ID | ✅ |
| AZ/BA | Fee search latency | **Cached since Phase 13** (`students_raw`, write-invalidated) | ✅ |
| BB | Concurrency | Receipt numbering atomic; capacity/roll detection-grade | 🟡 MEDIUM |

### 8.3 The coupled gate

```
Fee_lifecycle sets no dueDate
        ↓
compute_fines_for_student skips demands with no dueDate
        ↓
late fee unreachable for most demands
        ↓
AND no cron exists to run it at scale anyway
        ↓
AND reminders that would warn parents are dead settings
```

**All four must be fixed together to make fee collection behave like a real ERP.** Fixing any one
alone changes nothing observable. **This is the single most important finding in this report.**

## 9. Latency Analysis

| Workflow | Current | Risk |
|---|---|---|
| Fee counter lookup | 1–2 reads | ✅ |
| Fee search | 0 (cache hit) / 1 (miss) | ✅ since Phase 13 |
| Section capacity | 0–2 reads, memoised | ✅ |
| Bulk import, 500 rows/3 sections | +4 reads total | ✅ no N+1 |
| **Defaulter list** | **not traced this pass** | ⚠ unknown — recommend audit |
| Parent `findSiblings` | **full-collection scan per dashboard load** | 🟡 MEDIUM, pre-existing |

## 10. Firestore Rules / Indexes

**NO RULE/INDEX CHANGE REQUIRED.** Nothing was implemented. The catch-all
`match /{document=**} { allow read, write: if false; }` already denies every server-only collection
(`rollNoClaims`, `feeCounters`, index collections) by default. `firestore.rules` mtime 2026-08-13,
`firestore.indexes.json` 2026-08-12 — untouched.

## 11. Issue Register

| Issue | Module | Evidence | Sev | Current Behaviour | Action | Dependency | Cost | Latency | Now? |
|---|---|---|---|---|---|---|---|---|---|
| F-1 dead reminder settings | Fees | ZENX-VERIFIED | 🔴 HIGH | UI lies to operator | Build consumer or disable UI | AB | low | none | **YES** |
| F-2 `FEE_DUE` never emitted | Fees | ZENX-VERIFIED | 🔴 HIGH | Push plumbing unused | Add emit at reminder job | F-1, AB | low | none | **YES** |
| F-3 no fee cron | Fees | ZENX-VERIFIED | 🔴 HIGH | Late fee manual per-student | Add scheduled job | AB | med | none | **YES** |
| AB no due dates | Fees | ZENX-VERIFIED | 🔴 HIGH | Blocks all of the above | Set on generation | — | low | none | **YES** |
| L-1 stale snapshot post-promotion | SIS | ZENX-VERIFIED mechanism, unconfirmed impact | 🟡 MED | possible 90 s stale capacity | **Trace first** | — | none | verify |
| Ph15-B full-section override | SIS | ZENX-VERIFIED absent | 🟡 MED | no sanctioned path | DESIGN FIRST | D | low | none | design |
| Ph15-C non-atomic capacity | SIS | documented | 🟡 MED | rare 1-over | DEFER — shared primitive | — | med | none | no |
| Ph15-A import preview | SIS | ZENX-VERIFIED | 🟢 LOW | post-hoc reasons only | FIX NEXT | — | 0 reads | soon |
| Ph15-D capacity audit trail | SIS | ZENX-VERIFIED | 🟢 LOW→MED | none | with B | B | low | none | with B |
| Ph15-E duplicate roll survey | SIS | env-blocked | 🟡 MED | unknown scope | authorize script | creds | read-only | none | **decision** |
| AG ID-card replacement fee | Fees | ZENX-VERIFIED absent | 🟡 MED | no mechanism | FUTURE | AB | med | none | no |
| Parent full scan | Parent | ZENX-VERIFIED | 🟡 MED | N reads/dashboard | FUTURE | — | med | high | no |
| Certificates/LMS RTDB | Docs/LMS | ZENX-VERIFIED | 🟡 MED | blocks SRNO there | separate phase | — | high | none | no |
| AI placement | SIS | NOT ESTABLISHED | ⚪ | n/a | **DO NOT BUILD** | — | — | — | no |

## 12. TOP 10 ISSUES ZENX SHOULD FIX NEXT

1. **AB — Set due dates in `Fee_lifecycle`.** Unblocks everything below. Financial correctness.
2. **F-3 — Fee cron.** Without scheduled execution, late fees are theoretical.
3. **F-1 — Make `auto_remind` real, or remove the checkbox.** Either fix it or stop promising it; the current state misleads schools.
4. **F-2 — Emit `FEE_DUE`.** Completes a chain already 90% built.
5. **L-1 — Trace `syncStudent` cache invalidation.** Cheap to verify, potentially real.
6. **Ph15-E — Authorize the duplicate-roll survey.** You cannot plan remediation without the number.
7. **Ph15-A — Import capacity preview.** Zero-cost UX win.
8. **Ph15-B + D — Design full-section override *with* audit trail.** Business decision first.
9. **Parent `findSiblings` scan.** Latency, pre-existing.
10. **Certificates/LMS RTDB migration.** Unblocks SRNO in the last two modules.

## 13. Recommended Implementation Sequence

```
Phase 15.2  Fees Foundation      → AB (due dates) + L-1 trace          [must be first]
                ↓
Phase 15.3  Fees Automation      → F-3 cron + F-1 reminders + F-2 push [depends on AB]
                ↓
Phase 15.4  Section Closure      → Ph15-A preview, then B+D design
                ↓
Phase 16    Data Remediation     → Ph15-E survey → duplicate-roll cleanup plan
                ↓
Phase 17    Latency              → Parent findSiblings, defaulter list audit
                ↓
Phase 18    RTDB Retirement      → Certificates, LMS → then SRNO there
```

**Not locked.** Sequence follows the dependency graph: nothing in 15.3 is observable until 15.2 lands.

## 14. Decisions Required From You

| # | Decision |
|---|---|
| **D1** | **F-1: fix automatic reminders, or remove the UI control?** Leaving it as-is is the one option I'd argue against — it misinforms schools. |
| **D2** | Late fee model: **flat one-time** (current) or **per-day accrual**? Changes the calculation contract. |
| **D3** | Full-section override: who may, and is it audited? |
| **D4** | Promotion roll behaviour: carry forward (current) or clear? |
| **D5** | Authorize the read-only duplicate-roll survey script? |
| **D6** | AI placement: confirm **recommendation-only**, with gender and academic performance **excluded**? |

## 15. UAT Plan & Regression Risks

**UAT NOT EXECUTED** — nothing was implemented this phase. Carried-forward UAT from Phases 13–15
remains outstanding (receipt SRNO display, sibling import, capacity rejection, roll rejection).

**Regression risk this phase: zero** — no code changed.

---

## Git Status

```
Branch: integration/phase2   HEAD: 758c924   (unchanged)
Admin 442 dirty (+1 = this report only) · Teacher 31 · Parent 23 (both UNCHANGED)
firestore.rules 2026-08-13 · firestore.indexes.json 2026-08-12 — untouched
```

---

## WHAT THIS MEANS FOR A REAL SCHOOL

**The class-and-section work is genuinely finished.** You can't overfill a section from any of the
three admission routes any more, and the roll-number register is trustworthy. What's left there is
small: a nicer import preview, and a decision about whether a Principal should be allowed to
deliberately exceed a section's limit.

**The fees module is a different story, and I'd rather tell you now than let a school find out.**

There is a checkbox in your fee settings labelled automatic reminders. A school will switch it on,
see it save, and reasonably assume parents are now being reminded about due fees. **Nothing happens.**
No code anywhere reads that setting. That's worse than not having the feature at all, because your
office staff will stop chasing parents manually, trusting a system that isn't doing anything.

The same is true of the push notification for fees — it's fully built inside the mobile
infrastructure, with the message text already written, and nothing ever triggers it.

And late fees can only be applied **one student at a time, by hand**. There's no scheduled job. For a
school with two thousand students, that means late fees will simply never get charged.

**These three things share one root cause:** the fee demands created when a student is admitted don't
carry a due date at all. Without a due date, nothing can tell that a fee is late — so the late-fee
calculation skips those demands entirely, and there's nothing for a reminder to warn about. Fix the
due date first and the rest becomes possible. Fix any of them alone and you'll see no difference.

**Two pieces of good news**, because I checked things I'd previously flagged as risky: the late-fee
calculation will *not* double-charge if it runs twice — it's written safely. And receipt numbers are
allocated atomically, so two cashiers taking payments at the same moment cannot get the same receipt
number.

**One thing I want to flag carefully.** You mentioned a future AI that could place students into
sections considering things like boy/girl balance and academic performance. Capacity, siblings,
transport and language are all sensible. But automatically sorting children by gender or by academic
ability carries real legal and ethical risk, and it varies by country. My strong recommendation is
that those two factors be left out entirely — not merely "explained" — and that anything the system
suggests always needs a human to approve it.

**Nothing was changed this phase. This is a research and decision document.**
