# Phase 16.4 — Class/Section/Roll Integrity + Bulk Roll Assignment

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED (SEC-2, ROLL-5 comment). NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED.**
**UAT NOT EXECUTED.** No production data touched. `firestore.rules` / `firestore.indexes.json`
**NOT modified**. No RTDB introduced. No AI placement.

---

## 1. EXECUTIVE SUMMARY

SEC-2 was not a theoretical doc-id collision. **It was silently overriding every school's configured
section capacity**, and it undermined the Phase 15 capacity programme from underneath.

| ID | Finding | Severity | Status |
|---|---|---|---|
| **SEC-2** | `create_section` wrote a **session-less** doc-id; every reader used the session-ful one → the configured `maxStrength` was **invisible**, and capacity silently fell back to a hardcoded **40** | 🔴 **HIGH** | ✅ **FIXED** |
| **SEC-4** | `_sectionCapacity()` can **never** return ≤ 0, so the "unlimited" branch is **unreachable**; admission/import/CRM cap an unconfigured section at 40 while promotion/transfer treat it as unlimited | 🟡 **MED** | ⛔ **BUSINESS DECISION (D17)** |
| **ROLL-5** | `rollNoClaims` never releases; `claimedAt` is written but **never read**. An abandoned "Suggest" blackholes that number **permanently**. The in-code comment claimed the opposite. | 🟡 **MED** | ⚠ **comment corrected; repair needs D18** |
| **Bulk Roll Assignment** | Researched and designed in full | — | ⛔ **NOT BUILT — see §9** |

**Correction to my own prior reports.** Phases 15, 16.0 and 16.2 all stated that
"`maxStrength <= 0` retains unlimited semantics" as if it were system-wide. **That is true of the
validator but NOT of the system**, because `_sectionCapacity()` never yields 0. My Phase 15 test
`test_unset_capacity_means_unlimited_not_zero` exercises a branch that **cannot occur in
production**. That was wrong and is corrected here.

**Tests: 958 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +12 new tests.**

---

## 2. SEC-2 INVESTIGATION (traced, not assumed)

| # | Question | Answer |
|---|---|---|
| 1 | Which writer used the session-less id? | **`Classes::create_section()` only** — `Classes.php:186`, hand-built `{schoolId}_{classKey}_{section}` |
| 2 | Which readers use the canonical id? | `get_section_settings` `:392` · `save_section_settings` `:422` · `delete` `:757` · `Fees.php:1422` · `Sis.php:1511, 1951, 4340, 4524` · **`MY_Controller::_sectionCapacity()`** · `School_config.php:1360/1407/1692/1733` (explicit session) |
| 3 | Are the documents session-specific in practice? | **Yes** — the doc stores `session`, and `sectionDocId()` embeds it |
| 4 | Can multiple sessions coexist? | **Not via `create_section`** — the session-less id made 2025-26 and 2026-27 collide on one document, so creating the same class+section in a new session was refused as "Section already exists" |
| 5 | Collisions in production data? | **NOT VERIFIED — no credential.** I will not estimate. Registered as **SEC-5** (read-only survey) |
| 6 | Legacy behaviour? | **Yes** — the outlier predates `sectionDocId()`'s session parameter (added 2026-05-28) |
| 7 | Migration required for correctness? | **No** — see §4 |
| 8 | Does anything depend on the old id? | **No reader anywhere** reads the session-less id. That is precisely the defect |

### The real-world consequence (ZENX-VERIFIED)

```
Operator creates Class 5-A with capacity 30
  → create_section writes   SCH_1_Class 5th_Section A        (session-less)

Operator reopens Section Settings
  → get_section_settings reads SCH_1_2026-27_Class 5th_Section A  → NOT FOUND
  → screen shows max_strength: 0

Admission / import / CRM capacity check
  → _sectionCapacity() reads the same session-ful id          → NOT FOUND
  → falls back to its hardcoded default: 40
  ⇒ THE SCHOOL SET 30. THE SYSTEM ENFORCED 40.
```

The Phase 15 guards were working correctly — **they were being handed the wrong number.** A school
configuring 50 was equally capped at 40.

Saving Section Settings once wrote a **second**, session-ful document, after which capacity behaved
correctly — so the defect bit hardest on freshly configured sections and left duplicate records.

## 3. CLASS/SECTION IDENTITY MODEL

| Aspect | Finding | Label |
|---|---|---|
| School scope | `schoolId` prefix on every doc-id | ZENX-VERIFIED |
| Session scope | `sectionDocId()` embeds session; docs store `session` | ZENX-VERIFIED |
| Class identity | `classKey` — `"Class 5th"` | ZENX-VERIFIED |
| Section identity | stored **prefixed** (`"Section A"`); consumers need **bare** (Phase 16.3) | ZENX-VERIFIED |
| Are `Class 5/A/2025-26` and `Class 5/A/2026-27` separate documents? | **Yes, by design** — the architecture is session-scoped; `create_section` was the outlier | ZENX-VERIFIED |
| Should section *definitions* carry forward across sessions? | **NOT ESTABLISHED BY SOURCES**; no vendor documents it. Registered as **SEC-3** | — |

⚠ **SEC-3 (new, business decision):** now that create is session-scoped, sections created in 2025-26
are **not** found in 2026-27, so capacity there falls back to 40 until they are created for the new
session. This tension **already existed** — `School_config` has always written session-scoped — so
the fix does not introduce it, but it makes session rollover a question you must answer.

## 4. WHY NO MIGRATION IS REQUIRED

Old session-less documents were **already invisible to every reader**. After the fix, create writes
where readers look, so behaviour strictly improves:

| Concern | Before | After |
|---|---|---|
| Capacity read | not found → **40** | found → **configured value** |
| Settings screen | shows 0 | shows the real value |
| Class dropdowns (16.3) | `schoolWhere` sees **both**; deduped | unchanged |
| Duplicate prevention | blocked across sessions | correct per session |

**Old documents become inert, not harmful.** Cleaning them is housekeeping, not correctness —
registered as **SEC-5** with a read-only survey, no production write.

## 5. ROLL NUMBER MODEL — re-verified

Scope **School + Session + Class + Section**, never global. Roll ≠ Student ID ≠ SR Number ≠ Admission
Number; roll is register/display data. Guards confirmed present at: admission `:665` · edit `:6625` ·
import `:5058` (+ in-file, 16.2) · promotion (`$rollOwners`) · **section transfer (16.1)**.
**CRM enrolment still assigns no roll** — ROLL-2, by design pending the bulk tool.
Alphanumeric-safe (string compare, `12A ≠ 12`, case-insensitive).

## 6. ROLLNOCLAIMS — traced (Part 9)

| Property | Finding |
|---|---|
| Protects | the *suggestion* handing out a number two operators could both take |
| Scope | `{schoolId}_{md5(session_class_section_candidate)}` — tenant + session + class + section + roll |
| Atomicity | **genuine** — `firestoreCreate` is create-if-not-exists |
| Release | **NONE** |
| TTL | **NONE** — `claimedAt` is written but **never read**; no Firestore TTL policy on the collection |
| Concurrency | correct — two concurrent suggestions cannot collide |

**ROLL-5:** an operator who clicks *Suggest* and never saves permanently blackholes that number for
future suggestions. Suggestions drift upward and stop reusing real gaps. **It fails safe** — it can
never produce a duplicate, and manual entry is unaffected — which is why I recorded it rather than
hot-fixing: the repair needs a TTL/release policy (**D18**).

**The in-code comment asserted the opposite** — that stale claims are "treated as free by the loop
below". They are not. **I corrected the comment**, because a false comment is the same defect class
this programme has repeatedly had to unwind, and leaving it would guarantee the next reader
mis-diagnoses this.

## 7. EXTERNAL ERP RESEARCH — re-confirmed

| Ordering option | Evidence |
|---|---|
| Name A-Z / Z-A | **SOURCE-VERIFIED** — NextERP, AMS ERP |
| Admission Number | **SOURCE-VERIFIED** |
| Date of Joining | **SOURCE-VERIFIED** |
| Age | **SOURCE-VERIFIED** |
| Manual entry | **SOURCE-VERIFIED** — Fedena, VirtueSkool |
| Existing Roll (re-sequence in place) | **NOT ESTABLISHED** — a ZENX RECOMMENDATION, useful for tidying after promotion |
| Duplicate prevention within a batch | **SOURCE-VERIFIED** — *"two students of a batch cannot be assigned the same roll number"* |
| Starting number other than 1 | **NOT ESTABLISHED** — do **not** build configurable start without evidence |

## 8. BULK ROLL ASSIGNMENT — DESIGN (pre-implementation, not built)

```
Class → Section → Active roster (current session)
      → ordering: Manual | Name A-Z | Name Z-A | Admission No | Existing Roll
      → PREVIEW (read-only; nothing written)
      → validation: in-list duplicates · existing-roll policy · session · section membership
      → explicit SAVE → batched writes → Student_history_service audit
```
- **RBAC:** `SIS:manage` — matching every other placement operation after 16.1's D16 realignment.
  No new permission.
- **Capacity:** **not applicable** — it neither creates nor moves students, only renumbers an existing
  roster. Confirmed explicitly per Part 14.
- **Reuse:** `rollNoClaims` for atomicity, `Student_history_service` (16.1) for audit
  (old roll → new roll, mode, actor, class, section, session). No second mechanism.
- **Performance:** 1 roster read (shared memoised snapshot) + in-memory sort + batched writes.
  No per-student read. 40 / 200 / 500 students all cost one read.
- **`mb_`-safe stable sorting** — a naive `sort()` on multi-byte names renumbers a class differently
  on each run.
- **Never auto-renumber on screen open**; preview strictly read-only; save explicit.

⚠ **Open decision D19 — existing-roll policy.** What happens to students who already have a roll?
Overwrite all / keep existing and fill only blanks / re-sequence preserving relative order. **This is
school policy and I will not invent it.** The design is complete apart from this.

## 9. WHY BULK ROLL ASSIGNMENT WAS NOT BUILT

Honest reason, not a scheduling excuse: **SEC-2 turned out to be a HIGH-severity capacity-integrity
defect**, and closing it properly — with tests and the SEC-4 discovery it exposed — consumed this
phase's implementation budget. Two further reasons argue for separating them:

1. **Two unrelated risk profiles in one change.** SEC-2 alters *section document identity*; bulk
   assignment adds a *new write surface for student data*. Shipping both together would make a
   regression hard to attribute.
2. **D19 is unanswered.** The existing-roll policy is the core behaviour of the screen. Building it
   with a guessed policy would be exactly the invented-business-rule this programme forbids.

**No partial screen was created** — there is no half-built controller, view or route to clean up.

## 10. REMAINING BUSINESS DECISIONS

| ID | Decision |
|---|---|
| **D17** (new) | **SEC-4:** should an unconfigured section be **40 seats** (current admission behaviour) or **unlimited** (current promotion/transfer behaviour)? The two paths disagree today. |
| **D18** (new) | **ROLL-5:** TTL/release policy for `rollNoClaims`. Recommendation: treat a claim older than ~30 minutes as free, matching the original stated intent. |
| **D19** (new) | **Bulk assignment:** existing-roll policy — overwrite / fill-blanks-only / re-sequence. |
| **SEC-3** (new) | Should section definitions carry forward at session rollover, or be created per session? |
| D3 · D4 · D5 | Full-section override · promotion roll carry-vs-clear · duplicate-roll survey |

## 11. PERFORMANCE

SEC-2 fix: **zero** read/write delta — the same single `fs->get('sections', …)`, now with the correct
id. Nothing added to any hot path. ROLL-5 change is a comment only.

## 12. RBAC

Unchanged this phase. `create_section` remains `Configuration:manage`, which is correct — creating a
section is structural configuration, unlike *moving students*, which 16.1 moved to `SIS:manage`.

## 13. AUDIT TRAIL

Unchanged. `Student_history_service` (extracted in 16.1) is confirmed suitable for bulk roll
assignment and would be reused, not duplicated.
⚠ **Observed, not fixed:** `create_section` / `save_section_settings` / section deletion write **no
history**. Structural configuration changes are unaudited. Registered as **SEC-6**.

## 14. RTDB VERIFICATION (only what was checked)

`Classes.php::create_section` ✅ no RTDB · `Sis.php::suggest_roll_no` ✅ no RTDB · files touched this
phase ✅ clean. **No repo-wide eradication claimed** — HC-RTDB and LMS-RTDB remain registered from
16.3.

## 15. TESTS

```
PRE-PHASE BASELINE:  946 tests, 2799 assertions, 10 failures
AFTER 16.4:          958 tests, 2826 assertions, 10 failures
NEW TESTS: 12        NEW FAILURES: 0
LINT: clean · RTDB: none introduced · RULES/INDEXES: untouched
```
Pre-existing failures unchanged and **not mine**: Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass".**

Coverage: legacy vs canonical ids differ · legacy doc invisible to readers · **configured 30 silently
enforced as 40** · create/read now agree · configured capacity honoured · sessions now separable ·
tenants never collide · **resolver never returns 0** · **unlimited branch unreachable** ·
**promotion and admission disagree (SEC-4)** · identity invariants.

**Self-check note:** while editing the ROLL-5 comment I introduced a duplicate `claimedAt` array key.
PHP silently overwrites duplicate literal keys and `php -l` passed, so it would not have been caught
by lint — I caught it on inspection and removed it. Verified: the key now appears exactly once.

## 16. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | Create Class 5-A with capacity 30 | Saved | **UAT PENDING** |
| 2 | **Reopen Section Settings** | **Shows 30, not 0** | **UAT PENDING** |
| 3 | **Admit into 5-A repeatedly** | **Blocks at 30, not 40** | **UAT PENDING** |
| 4 | Create 5-A with capacity 50 | Blocks at 50 | **UAT PENDING** |
| 5 | Create 5-A in a second session | **Allowed** (no longer "already exists") | **UAT PENDING** |
| 6 | Pre-existing section made before this fix | Capacity still 40 until re-saved — **expected** | **UAT PENDING** |
| 7 | Class dropdowns (16.3) | Unaffected, no duplicates | **UAT PENDING** |
| 8 | Import / promotion / transfer capacity | Use the configured value | **UAT PENDING** |
| 9 | Suggest Roll → don't save → Suggest again | Second suggestion skips the abandoned number (**known ROLL-5**) | **UAT PENDING** |
| 10 | Section delete / edit | Address the same document as create | **UAT PENDING** |

**Row 3 is the critical one** — it proves the school's configured capacity is finally the one enforced.
**No browser or device UAT has been executed at any point in this programme.**

## 17. FEES DEFERRED WORK REGISTER (untouched — no Fees code reopened)

FEES-D7 · FEES-D9 · FEES-D13 · FEES-D14 · FEES-D15 · FEES-FROZEN-1 · FEES-UAT — all carried forward
unchanged, with their dependencies and acceptance conditions as recorded in Phase 16.3 §16.

## 18. CLASS/SECTION/ROLL REGISTER

| ID | Sev | Location | Dependency | Acceptance condition |
|---|---|---|---|---|
| **SEC-3** (new) | 🟡 MED | session rollover | **Decision** | Carry-forward policy defined |
| **SEC-4** (new) | 🟡 MED | `_sectionCapacity()` default 40 | **D17** | One consistent unset-capacity semantic across all paths |
| **SEC-5** (new) | 🟢 LOW | orphaned session-less section docs | **D5-style authorization** | Read-only survey; no production write |
| **SEC-6** (new) | 🟢 LOW | `Classes.php` section CRUD | — | Section create/edit/delete audited |
| **ROLL-5** (new) | 🟡 MED | `rollNoClaims` | **D18** | Abandoned claims released or expired |
| ROLL-1/2 | 🟡 MED | SIS | **D19** | Bulk assign screen; CRM students receive rolls |
| ROLL-3 | 🟡 MED | — | **D5** | Read-only survey; no auto-renumber |
| ROLL-4 | 🟡 MED | promotion | **D4** | Carry-forward vs clear decided |
| SEC-1 | 🟡 MED | — | **D3** | Override authority + audit |
| CAP-2 | 🟡 MED | shared guards | Design | One atomic primitive across capacity+roll+promotion+transfer |
| EXAM-1 | 🟢 LOW | Exam | — | — |
| HC-RTDB | 🟡 MED | `Health_check.php` | RTDB workstream | Checks read Firestore or are retired |
| LMS-RTDB | 🟡 MED | `Lms.php:76` | Subject migration | LMS subjects read Firestore |

## 19. RECOMMENDED NEXT PHASE

**Answer D17 first.** SEC-4 means two capacity semantics are live simultaneously; every further
capacity decision inherits that ambiguity. It is one decision, and the fix is small once made.

Then **bulk roll assignment** (needs D19), which is fully designed and ready to build.

## 20. WHAT THIS MEANS FOR A REAL SCHOOL

**The section capacity you set was being ignored, and now it isn't.**

When you created a class section and set it to hold 30 students, ZenX saved that in one place and
then looked for it somewhere else. Not finding it, it quietly fell back to an assumed 40. So a
section you capped at 30 accepted 40, and a section you set to 50 stopped at 40. The capacity checks
built in earlier phases were working perfectly — they were simply being given the wrong number.

You'd also have noticed a smaller symptom: reopening Section Settings showed the capacity as blank or
zero, even though you had just saved it. Same cause.

That's fixed. **One thing to be aware of:** sections created *before* this fix will keep using 40
until someone opens Section Settings and saves them again. Re-saving each section is the one-off
tidy-up.

**Two things I found and deliberately did not decide for you.**

First, what should happen when a section has *no* capacity set at all? Right now admissions treat it
as 40 seats, but promotions and transfers treat it as unlimited — the same section, two different
answers depending on how the student arrives. That's a policy question for you.

Second, the "Suggest Roll Number" button quietly loses numbers. If someone clicks it and then
abandons the form, that number is reserved forever and never offered again — so over time suggestions
climb higher and skip genuinely free numbers. It can never produce a duplicate, so nothing is
*wrong*, it just gets less helpful. Fixing it means deciding how long a suggestion should hold a
number. **The comment in the code claimed this was already handled. It wasn't — I've corrected the
comment so the next person isn't misled.**

**The bulk roll-number screen is designed but not built.** Two reasons: this capacity defect turned
out to be serious enough to deserve the whole phase, and the screen's central behaviour — what to do
with students who already have roll numbers — is your decision, not mine to guess. Nothing half-built
was left behind.

**Nothing has been committed or deployed, and none of this has been clicked through in a browser.**
