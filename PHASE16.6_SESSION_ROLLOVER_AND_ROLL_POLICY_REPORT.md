# Phase 16.6 — Session Rollover Research + Roll Policy + Connected Defect Closure

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED (SEC-11, SEC-12). NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED.**
**UAT NOT EXECUTED.** No production data touched. `firestore.rules` / `firestore.indexes.json`
**NOT modified**. **No Fees code touched.** No RTDB introduced. No AI placement.

---

## 1. EXECUTIVE SUMMARY

The Part 9 sweep found **two defects that were crashing or misreporting live screens**, both
directly connected to the capacity work and both fixed here.

| ID | Finding | Sev | Status |
|---|---|---|---|
| **SEC-12** | `_rtdb_fallback_students()` and `_rtdb_fallback_all_sections()` were **deleted from the codebase but three call sites were left behind**. An empty Firestore result called an **undefined method → PHP fatal error** | 🔴 **HIGH** | ✅ **FIXED** (all 3) |
| **SEC-11** | Three Classes.php read paths counted section occupancy **across every session**, so the UI showed a strength the server disagreed with | 🟡 **MED** | ✅ **FIXED** |
| **ROLL-6** | Claim release on save | 🟢 LOW | ⛔ **ANALYSED — recommend NOT implementing** (§8) |
| SEC-3 · D19 · D4 · D3 · D5 | Researched with recommendations | — | ⛔ **DECISIONS REQUIRED** |

**SEC-12 is the serious one.** Three guards of the form `if (empty($x)) { $x = $this->_rtdb_fallback_…(); }`
call methods that no longer exist — a comment at `Classes.php:854` even records *"the 3 deleted
_rtdb_fallback methods. Removed entirely."* The methods went; the calls stayed. Consequences:

- Opening a section with **no active students** → fatal error instead of an empty roster.
- **A school with no sections configured yet** — i.e. every school on its first run — **crashed the
  classes screen** (two of the three sites).

⚠ **And my own SEC-11 fix would have widened it:** correct session scoping makes empty results *more*
common, so a section holding only last year's students would newly have hit the crash. Fixing SEC-11
without SEC-12 would have made things worse.

**Tests: 991 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +15 new tests.**

---

## 2. PART 1/2 — SEC-3 SESSION ROLLOVER: RESEARCH → RECOMMENDATION

### External evidence

| Question | Finding | Label | Source |
|---|---|---|---|
| Are classes/sections copied to the next year? | *"Admins can copy classes and sections over from one year to the next **and make adjustments as needed**"* | **SOURCE-VERIFIED** | [Common Goal Systems](https://commongoalsystems.zendesk.com/hc/en-us/articles/360004135072-How-do-I-roll-over-to-the-next-academic-year) |
| Is rollover a guided multi-step process? | A **"New School Year Setup"** wizard with numbered steps | **SOURCE-VERIFIED** | [Arbor](https://support.arbor-education.com/hc/en-us/articles/360007719277-New-School-Year-Setup-Step-6-Registers-for-Secondaries) |
| Order of operations | Copy structure **first**, then promote students | **SOURCE-VERIFIED** | Common Goal Systems |
| Is next year set up early / incrementally? | Schools typically start in **Feb–Mar**, deliberately before all data is known | **SOURCE-VERIFIED** | Arbor |
| Can the admin adjust during rollover? | **Yes — explicitly** ("make adjustments as needed") | **SOURCE-VERIFIED** | Common Goal Systems |
| Is **section capacity/max strength** copied? | **NOT ESTABLISHED BY SOURCES** — no vendor documents capacity specifically | — | — |
| Does 5-A automatically become 6-A? | **NOT ESTABLISHED** — promotion maps students; section identity is not documented as auto-derived | — | — |
| Fully automatic rollover? | **NOT ESTABLISHED** — every documented flow is admin-initiated and reviewable | — | — |

### ZenX source (ZENX-VERIFIED)
Sections are session-scoped (`sectionDocId` embeds session). **No code copies section definitions or
capacities into a new session** — re-verified this phase. After rollover, every section is
unconfigured and resolves to the canonical default of 40 (Phase 16.5's D17).

### Recommendation — **Option B, a "Next Session Setup" wizard**

The evidence supports **copy-with-review**, not silent copying and not pure manual re-entry:
copy classes and sections forward, **show them for adjustment**, confirm, then create. That is
precisely the candidate you sketched, and it is the only model with direct source support.

⛔ **STOPPING HERE.** This is a **new admin workflow (screen + controller + write path)**, not a
connected defect fix. Building it inside a defect-closure phase would be the half-built-feature this
programme forbids. **Authorization required.**
**One evidenced detail worth carrying in:** capacity copying is **NOT ESTABLISHED** externally, so
the wizard should copy capacity as a **pre-filled, editable** value rather than an assumed one.

## 3. PART 3/4 — D19 BULK ROLL: RESEARCH → RECOMMENDATION

Re-confirmed from Phase 16.0/16.4 (NextERP, Fedena, AMS ERP, VirtueSkool):

| Aspect | Finding | Label |
|---|---|---|
| Bulk generation exists | Yes, as a **separate explicit operation** | **SOURCE-VERIFIED** |
| Ordering: name A-Z/Z-A, admission no, date of joining, age | documented | **SOURCE-VERIFIED** |
| Manual entry | documented | **SOURCE-VERIFIED** |
| Batch-scoped uniqueness | *"two students of a batch cannot be assigned the same roll number"* | **SOURCE-VERIFIED** |
| **Treatment of students who already have rolls** | **NOT ESTABLISHED BY SOURCES** — no vendor documents it |
| Blank-only generation mode | **NOT ESTABLISHED** |
| Preview before save / reversibility / audit | **NOT ESTABLISHED** |

**You asked me to challenge Option C. I cannot fault it, and I can support it — but on ZenX grounds,
not borrowed authority.** The external record is silent on existing-roll handling, so anyone claiming
an industry standard here would be inventing one.

**ZENX RECOMMENDATION: Option C** — default to preserving existing rolls (fill blanks only), with an
explicit, separately-chosen "Recalculate All" mode that previews every change before saving.
Rationale, from this codebase rather than vendors: roll numbers are printed on registers, mark sheets
and ID cards, so silently renumbering a class invalidates paper already in circulation. Option A is
too weak (it can never tidy a post-promotion mess); Option B is unsafe as a default. **C is A and B
with the destructive one behind an explicit choice.**

⛔ Still requires **your** confirmation — I will not implement a renumbering policy on my own
recommendation.

## 4. PART 5 — D4 PROMOTION ROLL POLICY

**External: NOT ESTABLISHED BY SOURCES.** Three research passes across five vendors have found **no
documentation of what happens to a roll number during promotion.** I will not manufacture one.

**ZenX today (ZENX-VERIFIED):** promotion **carries the roll forward** and blocks destination
collisions via the `$rollOwners` pre-check (Phase 14.1). Merging 5-A and 5-B into 6-A therefore
*blocks* rather than silently duplicating.

**ZENX RECOMMENDATION: Option C** — keep carrying forward (so nothing is lost or silently blanked),
and let the bulk assignment tool (D19) re-sequence the class afterwards. This needs **no code change
now**; it is the current behaviour plus the D19 tool. Options A and B are both already available
inside C.

## 5. PART 6 — D3 FULL-SECTION OVERRIDE

**External: NOT ESTABLISHED BY SOURCES** on every one of your ten questions — no vendor documents an
over-capacity override, who may perform it, or whether it is audited.

**ZENX RECOMMENDATION** (labelled as such, not as industry practice), built from patterns this
codebase already uses:
- **Per-admission exception, never a mode.** No "ignore capacity" checkbox — that becomes permanently
  ticked.
- **Requires a reason**, mirroring the fine-override control in Fees (MANAGE + reason + audit).
- **Audited** via `Student_history_service`.
- **`maxStrength` unchanged** — the section still reads 41/40, visibly over, rather than being
  silently widened to 41.
- **Admission only.** Import, promotion and transfer are bulk operations where an override would
  scale a mistake.

⛔ **Decision required** — specifically *who* may override (Principal-equivalent vs any SIS manager).

## 6. PART 7 — D5 DUPLICATE-ROLL SURVEY DESIGN

**Read-only. No production write. Never renumbers.**
Output per duplicate group: `school · session · class · section · rollNo · [studentId] · [name] · count`.
Cost: **one `students` read per school** (the same snapshot the guards use), grouped in memory —
runnable school-by-school.

⛔ **BLOCKED: no service-account credential is present on this machine** (re-verified). I cannot run
it and **will not estimate counts.** Requires authorization plus a credential.

## 7. PART 9 — CROSS-MODULE SWEEP RESULTS

| # | Check | Result |
|---|---|---|
| 1 | Session-less section doc-ids | ✅ **NONE REMAIN** (16.5 closed them) |
| 2 | Wrong-session reads on placement paths | ✅ none (16.5) |
| 3 | **All-session occupancy queries** | ❌ **3 FOUND → FIXED (SEC-11)** |
| 4 | Roll Number used as identity | ✅ none |
| 5 | Global roll uniqueness assumptions | ✅ none — no module keys on `rollNo` |
| 6 | SR Number derived from Student ID | ✅ none — `Sr_no_service::claim()` *associates*, never derives |
| 7 | Student ID derived from SR Number | ✅ none |
| 8 | Stale class/section references | ✅ none found |
| 9 | Inconsistent section formats written | ✅ none (16.3 normalised reads) |
| 10 | Paths bypassing canonical placement rules | ✅ none |
| — | **Calls to deleted methods** | ❌ **3 FOUND → FIXED (SEC-12)** |

## 8. ROLL-6 — ANALYSED, AND I RECOMMEND *NOT* IMPLEMENTING IT

You asked me to determine safety first. **It is safe** — the claim id is derivable at save time from
(session, class, section, roll), and releasing after confirmed persistence cannot be raced, because
once the student exists the **roster** blocks that number.

**But that is exactly why it should not be built.** Once the student is saved, `$taken` is built from
the roster and already excludes the number — **the lingering claim is inert.** Release would:

- add a **write to every roll-saving path** (admission, edit, **and bulk import — N extra writes**),
- create **three new regression surfaces** across three save paths,
- for **zero correctness gain** — Phase 16.5's TTL already restored correctness.

**ZENX RECOMMENDATION: leave claims to expire.** If the `rollNoClaims` collection's growth ever
matters, that is a cleanup job, not a change to the save paths. Registered as **ROLL-6 (closed by
analysis)** rather than deferred — overrule me if you'd rather have it.

## 9. FILES CHANGED

| File | Change |
|---|---|
| `application/core/MY_Controller.php` | extracted `_filterSectionRoster()`; `_sectionActiveCount()` delegates to it |
| `application/controllers/Classes.php` | SEC-11 ×3 (section strength, `fetch_section_students`, `delete_section` guard); **SEC-12 ×3 dead-call removals** |
| `tests/Unit/Phase166SectionRosterAndDeadFallbackTest.php` | **NEW** — 15 tests |

**No Fees file was opened.** *(Fees files appear dirty in `git status` from Phases 15.5–15.9; the
diff is unchanged this phase.)*

## 10. PERFORMANCE / READ COST

| Operation | Before | After |
|---|---|---|
| Section strength (per section) | 1 query with 3 predicates | 1 query with 2 predicates + in-memory filter — **same read count** |
| `fetch_section_students` | 1 query | 1 query — **same** |
| `delete_section` guard | 1 query | 1 query — **same** |

**No read added, no N+1, no new index.** Dropping the `Section` predicate and filtering in PHP is
deliberate: a Firestore equality predicate cannot express *"matches the session OR is blank"*, and a
hard `where session ==` would have **silently dropped legacy students**, under-counting sections and
letting a full one look empty.

## 11. CONCURRENCY

Unchanged. Capacity remains **detection-grade**; roll Suggest remains **atomic**; the TTL reclaim
remains **read-then-set, not atomic** (bounded — `_rollNoConflict()` still rejects duplicates at
save). CAP-2 remains the future shared primitive.

## 12. RTDB VERIFICATION

`Classes.php`: **all RTDB fallback calls removed** — the file now has no `shallow_get`, no
`getReference`, no fallback of any kind. `MY_Controller` touched regions: clean.
**No repo-wide eradication claimed** — HC-RTDB and LMS-RTDB remain registered from 16.3.

## 13. TESTS

```
PRE-PHASE BASELINE:  976 tests, 2869 assertions, 10 failures
AFTER 16.6:          991 tests, 2892 assertions, 10 failures
NEW TESTS: 15        NEW FAILURES: 0
LINT: clean · RTDB: removed from Classes.php · RULES/INDEXES: untouched
```
Pre-existing failures unchanged and **not mine**: Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass".**

Coverage: other sessions don't inflate strength · **UI count == server count** · legacy blank-session
students still counted · withdrawn excluded · other sections/classes excluded · Title-Case and
lowercase field shapes both match · delete guard not vetoed by a previous session · self-exclusion ·
**empty section returns a list, not a crash** · section holding only other-session students is empty ·
no-sections-configured is valid · malformed rows skipped · roster authoritative after save (ROLL-6) ·
identity invariants.

## 14. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | Configure 5-A = 30, 5-B = 50 | Both persist and display | **UAT PENDING** |
| 2 | **Classes screen strength** | Shows **this session only** | **UAT PENDING** |
| 3 | **Open a section with no students** | **Empty list, no crash** | **UAT PENDING** |
| 4 | **Brand-new school, no sections** | **Classes screen loads** | **UAT PENDING** |
| 5 | Section with only last year's students | Shows 0 this session | **UAT PENDING** |
| 6 | Fill 5-A to 30, attempt 31st | Rejected | **UAT PENDING** |
| 7 | Promote into next-session section | Allowed; last year not counted | **UAT PENDING** |
| 8 | Transfer into a full section | Rejected | **UAT PENDING** |
| 9 | Import / CRM into a full section | Rejected | **UAT PENDING** |
| 10 | Delete a section empty this session but used last year | **Not vetoed** | **UAT PENDING** |
| 11 | Suggest → save immediately | Number retained | **UAT PENDING** |
| 12 | Suggest → abandon → retry before 30 min | Skips it | **UAT PENDING** |
| 13 | Suggest → abandon → retry after 30 min | Reuses it | **UAT PENDING** |
| 14 | Manual alphanumeric roll `12A` | Accepted; `12A ≠ 12` | **UAT PENDING** |
| 15 | Legacy student with blank session | Still counted in their section | **UAT PENDING** |

**Rows 3 and 4 cover the crash. No browser or device UAT has been executed at any point.**

## 15. FEES DEFERRED REGISTER — VERIFIED INTACT

FEES-D7 · FEES-D9 · FEES-D13 · FEES-D14 · FEES-D15 · FEES-FROZEN-1 · FEES-UAT — all present,
unchanged. **No Fees code was modified and no Fees regression was found.**

## 16. CLASS/SECTION/ROLL REGISTER

| ID | Sev | Dependency | Acceptance condition |
|---|---|---|---|
| **SEC-3** | 🟡 MED | **Authorization** | "Next Session Setup" wizard: copy classes+sections forward, capacity **pre-filled and editable**, admin confirms |
| ROLL-1/2 (D19) | 🟡 MED | **D19 confirmation** | Bulk screen, Option C; CRM students receive rolls |
| ROLL-4 (D4) | 🟡 MED | **D4 confirmation** | Carry-forward retained + D19 re-sequencing |
| SEC-1 (D3) | 🟡 MED | **D3 — who may override** | Per-admission, reason, audited, capacity unchanged |
| ROLL-3 (D5) | 🟡 MED | **Authorization + credential** | Read-only survey run |
| **ROLL-6** | 🟢 LOW | — | **Closed by analysis** — recommend not implementing |
| SEC-5 · SEC-6 | 🟢 LOW | — | Orphaned section docs surveyed; section CRUD audited |
| CAP-2 | 🟡 MED | Design | One atomic primitive across capacity+roll+promotion+transfer |
| EXAM-1 · HC-RTDB · LMS-RTDB | — | — | as registered in 16.3 |

## 17. BUSINESS DECISIONS REQUIRED

**SEC-3** authorize the Next Session Setup wizard · **D19** confirm Option C · **D4** confirm carry-
forward + re-sequence · **D3** who may override a full section · **D5** authorize the survey (needs a
credential) · **ROLL-6** overrule me if you want release-on-save.

## 18. RECOMMENDED NEXT PHASE

**SEC-3 (Next Session Setup).** It is the only remaining item that is *structurally* incomplete
rather than merely undecided: today nothing carries a school's section configuration into a new
academic year, so every rollover silently resets capacities to 40. D19 can follow immediately after —
it is fully designed and needs one word from you.

---

## 19. WHAT THIS MEANS FOR A REAL SCHOOL

**Two screens were broken, and one of them crashed.**

Some old code that used to fall back to the previous database was deleted — but three places that
*called* it were left behind. So whenever there was nothing to show, ZenX called something that no
longer existed and the page failed outright. In practice that meant: **opening a class section that
has no students yet crashed**, and **a brand-new school with no sections set up couldn't open the
classes screen at all.** Both are fixed, and the leftover fallbacks are gone entirely.

Worth knowing: my own fix in this same phase would have made that crash *more* likely, because
counting students correctly means more sections legitimately come back empty. The two had to be
fixed together.

**The other fix is about the number you see.** The classes screen was counting students from *every*
year at once. A section with forty students last year and five this year showed as forty-five out of
forty — "full" — while admissions correctly still let you add more. The screen and the system
disagreed about the same classroom. Now both count the same way, and the same thing applies when you
delete a section: last year's students can no longer veto it.

**On the four decisions you asked me to research:**

I looked hard for how other school systems handle rolling into a new year. The evidence is clear on
one point — they **copy your classes and sections forward and let you adjust them before confirming.**
That's what I'd recommend building, and it's the one remaining thing that's genuinely missing rather
than just undecided: right now nothing carries your section setup into a new year, so capacities
quietly reset.

On roll numbers, I have to be straight with you: **no vendor documents what happens to existing roll
numbers during bulk assignment, or during promotion.** Anyone telling you there's an industry standard
there is guessing. My recommendation — keep existing numbers by default, with a clearly separate
"recalculate everything" option that shows you every change first — is based on your situation, not
borrowed authority: roll numbers are printed on registers and ID cards, so silently renumbering a
class invalidates paper already in people's hands.

**And one thing I recommend *not* doing.** You asked whether the "Suggest Roll Number" hold should be
released the moment a student is saved. It safely could be — but it shouldn't. Once the student
exists, their number is already blocked by the actual class list, so the hold is doing nothing.
Building the release would add a write to every save, including bulk imports, and touch three places
for no real benefit. I'd rather tell you that than add code to look thorough.

**Nothing has been committed or deployed, and none of this has been clicked through in a browser.**
