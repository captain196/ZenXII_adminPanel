# Phase 16.7 — Next Session Setup / Class-Section Rollover (SEC-3 closure)

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED. UAT NOT EXECUTED.**
No production data touched. `firestore.rules` / `firestore.indexes.json` **NOT modified**.
**No Fees code touched.** No RTDB. No bulk roll assignment. No full-section override. No AI placement.

---

## 1. EXECUTIVE SUMMARY

**SEC-3 did not need a new wizard. It needed one missing field.**

Tracing the live source before coding — as Part 1 required — overturned my own conclusion from the
previous two phases:

> ⚠ **CORRECTION TO PHASES 16.5 AND 16.6.** Both reports stated *"no code copies section definitions
> into a new session."* **That was wrong.** `School_config::rollover_session()` (line 3610) has copied
> sections all along, with `copy_sections` defaulting **on**, an idempotency intent map, resume after
> partial failure, and CAS locking. A `preview_rollover()` endpoint (line 3516) exists too. **I had
> searched the promotion flow and not `School_config`.** Building the wizard I recommended in 16.6
> would have been a second implementation of working infrastructure — exactly what Part 1 forbids.

**The actual defect was narrower and worse:**

| ID | Finding | Sev | Status |
|---|---|---|---|
| **SEC-13** | `rollover_session()` rebuilt each section document field by field and **omitted `maxStrength`**. Every rolled-over section was created with no capacity, so the D17 resolver defaulted to **40** — a school's **30 / 40 / 50 became 40 / 40 / 40** | 🔴 **HIGH** | ✅ **FIXED** |
| **SEC-13b** | `preview_rollover()` showed only class + section — the operator could not see the capacity being carried, nor whether the target already had that section | 🟡 MED | ✅ **FIXED** |
| **SEC-2 family** | The rollover copy still hand-built its section doc-id — the last such site | 🟢 LOW | ✅ **FIXED** |
| **SEC-14** | Rollover writes **no audit record at all** | 🟡 MED | ⛔ **BOUNDARY** — §10 |
| **SEC-15** | `promote_students` runs under `Configuration:manage`, not SIS | 🟡 MED | ⛔ **DECISION** — §5 |

**Tests: 1009 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +18 new tests.**

---

## 2. RESEARCH BASIS (carried from 16.6, unchanged)

Copy-forward-and-adjust is **SOURCE-VERIFIED** ([Common Goal Systems](https://commongoalsystems.zendesk.com/hc/en-us/articles/360004135072-How-do-I-roll-over-to-the-next-academic-year):
*"copy classes and sections over from one year to the next and make adjustments as needed"*), as is a
guided multi-step setup ([Arbor](https://support.arbor-education.com/hc/en-us/articles/360007719277-New-School-Year-Setup-Step-6-Registers-for-Secondaries)),
and structure-before-promotion ordering. **Capacity copying specifically remains NOT ESTABLISHED BY
SOURCES** — so carrying it is a **ZENX RECOMMENDATION**, presented as such, and deliberately
implemented as a *pre-filled starting value* the school can still edit rather than an immutable rule.

## 3. ZENX ARCHITECTURE TRACED (Part 1)

| Concern | Canonical owner |
|---|---|
| Session list / active session | `schools.sessions[]` + `schools.currentSession` |
| Session CRUD | `School_config`: `add_session`, `set_active_session`, `delete_session`, `archive_session` |
| **Rollover** | **`School_config::rollover_session()` :3610** — *existed already* |
| **Rollover preview** | **`School_config::preview_rollover()` :3516** — *existed already* |
| Section doc identity | `Firestore_service::sectionDocId()` |
| Capacity resolution | `MY_Controller::_sectionCapacity()` (D17) |
| Section CRUD | `Classes::create_section` / `save_section_settings` |

**No second implementation was created.** Both fixes are surgical edits inside the existing canonical
endpoints.

## 4. WORKFLOW — WHAT ALREADY EXISTED vs WHAT I ADDED

| Step (your Part 2 design) | State |
|---|---|
| 1 Select source session | ✅ existed (`from_session`, validated against `sessions[]`) |
| 2 Select/create target | ✅ existed (`to_session`, added to `sessions[]` under lock) |
| 3 Load source classes/sections | ✅ existed |
| 4 **Preview with capacities** | ❌ **ADDED** — `currentCapacity`, `targetCapacity`, `state`, `willCopy` |
| 5 Include/exclude, modify capacity | ⚠ **partially** — the *data* is now exposed; per-row editing is a **UI** change (§16) |
| 6 Final confirmation | ✅ existed (separate `rollover_session` POST) |
| 7 Create target documents | ✅ existed — **now carries capacity** |
| 8 Audit | ❌ **absent** — SEC-14, §10 |

## 5. PERMISSION DECISION (Part 8)

`rollover_session` and `preview_rollover` both use **`Configuration:manage` / `:view`**.
**I did not change it**, and that is a considered decision: creating next year's *class/section
structure* is structural configuration, matching `create_section` and `save_section_settings`.

⚠ **SEC-15 — but the same endpoint optionally promotes students.** `promote_students=1` moves every
student into a new session under `Configuration:manage`, while Phase 16.1 deliberately moved section
*transfer* from `Configuration:manage` to `SIS:manage` on the grounds that moving students is a
student-lifecycle act. **The same argument applies here and I have not applied it** — splitting one
endpoint across two permissions changes an existing authorization contract, which is your call
(**D20**). Structure-only rollover under Configuration is correct either way.

## 6. SESSION ISOLATION (Part 3)

- Source read: `schoolWhere('sections', [['session','==',$from]])`
- Target read: `schoolWhere('sections', [['session','==',$to]])`
- Target write: **`sectionDocId($className, $section, $to)`** — canonical, session-embedded
- `from === to` rejected; both validated against `^\d{4}-\d{2}$` and against `sessions[]`
- Tenant scoping via `schoolWhere` + the `{schoolId}_` doc-id prefix

**Static scan: zero hand-built section doc-ids remain anywhere in `application/controllers/`.** The
rollover copy was the last one.

## 7. CAPACITY BEHAVIOUR (Part 6)

```
source maxStrength 30 → target maxStrength 30   (resolver → 30)
source maxStrength 50 → target maxStrength 50   (resolver → 50)
source unset / 0      → target maxStrength 0    (resolver → 40, the D17 default)
source negative       → normalised to 0
```

**Deliberate: an unconfigured source is stored as `0`, never as `40`.** Writing 40 into the document
would convert *"never configured"* into *"explicitly configured as 40"*, hiding from the operator that
nobody ever chose it. The resolver owns the default; the record stores only what the school actually
set. Both `maxStrength` and legacy `max_strength` are read.

## 8. EXISTING-TARGET BEHAVIOUR (Part 4) — non-destructive

`rollover_session()` pre-loads target ids and **skips** any section that already exists; it never
overwrites. The preview now names that outcome explicitly:

| State | Meaning |
|---|---|
| `NEW` | not in target → will be copied |
| `EXISTS_UNCHANGED` | present with the same capacity → skipped |
| `EXISTS_DIFFERENT_CAPACITY` | present with a **different** capacity → skipped, **reported for the operator to reconcile** |

**No destructive overwrite behaviour was invented.** A differing capacity is surfaced, not resolved.

## 9. IDEMPOTENCY (Part 5)

Pre-existing and verified, not rebuilt: `rolloverIntents` on the schools doc tracks
`{from}__to__{to}` as `running` / `completed`; a completed intent short-circuits and returns the prior
summary; a `running` intent resumes and skips already-promoted students. Section copying is
independently idempotent through deterministic doc-ids plus the skip-existing check.
**Tested:** a second submission copies **zero** sections and skips all three.

## 10. AUDIT (Part 9) — SEC-14, STOPPED AT THE BOUNDARY

**Rollover writes no audit record** (grep across the whole endpoint: zero `_log_history`,
`Student_history_service` or `log_audit` calls). A structural change creating an entire academic
year's class configuration — and optionally promoting every student — leaves no trace of who ran it.

⛔ **Not fixed here, for a specific reason:** `Student_history_service` is keyed on `studentId`, and
this is an **administrative** event with no student. Recording it correctly needs an
*administrative* history surface that **does not exist yet**; inventing one inside a defect-closure
phase would be new architecture. **This is the same gap already registered as SEC-6** (section CRUD
unaudited) — the two should be closed together by one admin-audit facility. **Authorization
required.**

## 11. CONNECTED DEFECTS — FOUND / FIXED / STOPPED

**Fixed:** SEC-13 (capacity dropped) · SEC-13b (preview blind to capacity and target state) ·
SEC-2 family (last hand-built section doc-id).
**Stopped at boundaries:** SEC-14 (needs an admin-audit facility) · SEC-15 (authorization contract
change — D20).
**Swept clean:** session-less ids ✅ · wrong-session reads/writes ✅ · duplicate section creation ✅ ·
cross-school leakage ✅ · empty-state crashes ✅ (16.6) · dead RTDB fallbacks ✅ (16.6) ·
inconsistent section normalisation ✅ (16.3).

## 12. FILES CHANGED

| File | Change |
|---|---|
| `application/controllers/School_config.php` | `rollover_session`: carry `maxStrength` + canonical `sectionDocId()`; `preview_rollover`: capacity + target-state |
| `tests/Unit/Phase167SessionRolloverCapacityTest.php` | **NEW** — 18 tests |

**No Fees file opened.** *(Fees files show dirty from Phases 15.5–15.9; their diff is unchanged.)*

## 13. PERFORMANCE (Part 13)

| Operation | Reads | Writes |
|---|---|---|
| Preview (before) | 2 bulk (source sections, source students) | 0 |
| **Preview (after)** | **3 bulk** (+ target sections, indexed once) | 0 |
| Rollover copy | 2 bulk (source + target sections) | 1 per **new** section |

**No N+1.** Target sections are fetched in **one** query and indexed by doc-id — deliberately not one
lookup per section. The added preview read is a single bulk query regardless of section count; a
school with 100 sections costs the same as one with 3. **No new index required** — `session ==` on a
school-scoped query is the same predicate `rollover_session` already uses.

## 14. RTDB VERIFICATION

Touched regions of `School_config.php`: **no `shallow_get`, no `getReference`** (scan count 0).
**No repo-wide eradication claimed** — HC-RTDB and LMS-RTDB remain registered from 16.3.

## 15. TESTS

```
PRE-PHASE BASELINE:  991 tests, 2892 assertions, 10 failures
AFTER 16.7:         1009 tests, 2941 assertions, 10 failures
NEW TESTS: 18        NEW FAILURES: 0
LINT: clean · RTDB: none · RULES/INDEXES: untouched
STATIC SCAN: zero hand-built section doc-ids in application/controllers/
```
Pre-existing failures unchanged and **not mine**: Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass".**

Coverage: capacities 30/40/50 survive · pre-fix omission resolved to 40 · resolver matches source ·
unconfigured stored as 0 not 40 · negative normalised · `max_strength` accepted · target uses target
session · source/target ids never collide · schools never collide · **second submission copies
nothing** · existing target never overwritten · three preview states · preview surfaces capacity ·
empty source safe · malformed rows skipped · **no student data copied** · class teacher reset ·
identity invariants.

## 16. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | Configure 5-A=30, 5-B=40, 5-C=50 in 2025-26 | Saved | **UAT PENDING** |
| 2 | Preview rollover → 2026-27 | Shows all three **with capacities** and state `NEW` | **UAT PENDING** |
| 3 | Run rollover (copy sections) | 3 sections created | **UAT PENDING** |
| 4 | **Open Section Settings in 2026-27** | **Shows 30 / 40 / 50, not 40 / 40 / 40** | **UAT PENDING** |
| 5 | Admit into 2026-27 5-A | **Blocks at 30** | **UAT PENDING** |
| 6 | **Verify 2025-26 unchanged** | Source untouched | **UAT PENDING** |
| 7 | **Run rollover twice** | Second run copies 0, skips 3 | **UAT PENDING** |
| 8 | Change target 5-A to 45, re-preview | State `EXISTS_DIFFERENT_CAPACITY` | **UAT PENDING** |
| 9 | Re-run rollover after (8) | 5-A **skipped, not overwritten** | **UAT PENDING** |
| 10 | Source session with no sections | Empty preview, no error | **UAT PENDING** |
| 11 | Section with no capacity configured | Copies as unconfigured; resolver gives 40 | **UAT PENDING** |
| 12 | Cross-session isolation | No other session appears | **UAT PENDING** |
| 13 | Cross-school isolation | No other school appears | **UAT PENDING** |
| 14 | Non-Configuration user | Refused | **UAT PENDING** |
| 15 | **Confirm no students promoted** when `promote_students` unset | Roster unchanged | **UAT PENDING** |
| 16 | **Confirm no roll numbers changed** | Rolls untouched | **UAT PENDING** |

**Row 4 is the critical one** — it is the whole point of SEC-13.
**No browser or device UAT has been executed at any point in this programme.**

## 17. REMAINING DECISIONS

| ID | Decision |
|---|---|
| **SEC-14** | Authorize an **administrative** audit facility (would also close SEC-6) |
| **D20** (new) | Should `promote_students` inside rollover require `SIS:manage`, consistent with Phase 16.1's D16? |
| **SEC-16** (new) | Per-row capacity **editing** in the preview UI — the data is now exposed; the editor is a front-end change |
| D19 · D4 · D3 · D5 | Bulk roll policy · promotion roll · full-section override · duplicate-roll survey |

## 18. DEFERRED WORK REGISTER

**Fees (verified intact, untouched):** FEES-D7 · FEES-D9 · FEES-D13 · FEES-D14 · FEES-D15 ·
FEES-FROZEN-1 · FEES-UAT.

**Class/Section/Roll:** SEC-14 (admin audit, with SEC-6) · **SEC-15/D20** (rollover promote RBAC) ·
**SEC-16** (preview capacity editor) · SEC-5 (orphaned session-less section docs — survey) ·
ROLL-1/2 (D19) · ROLL-3 (D5) · ROLL-4 (D4) · SEC-1 (D3) · ROLL-6 (closed by analysis) ·
CAP-2 (atomic primitive) · EXAM-1 · HC-RTDB · LMS-RTDB.

## 19. RECOMMENDED NEXT PHASE

**SEC-16 + SEC-14 together** — the preview now carries the numbers an operator needs, so adding the
per-row capacity editor completes your Step 5, and the audit gap is on the same endpoint. Both are
small once authorized. **D19 (bulk roll assignment) remains fully designed and needs only your
confirmation of Option C.**

---

## 20. WHAT THIS MEANS FOR A REAL SCHOOL

**Your section sizes will survive the new academic year.**

ZenX already had a "roll over to next session" feature that copied your classes and sections forward —
I was wrong in the last two reports when I said it didn't exist, and I found it by reading the code
rather than trusting my own earlier note. What it did **not** copy was the one number that matters
most: **how many seats each section has.**

So if you had set 5-A to 30, 5-B to 40 and 5-C to 50, rolling into the new year gave you three
sections with no seat limit recorded — and the system fell back to assuming 40 for all of them. Your
30-seat class quietly became a 40-seat class, and your 50-seat class quietly shrank.

That number is now carried across. And if a section never had a limit set, we record it as *"not
set"* rather than writing 40 into it — so you can still see it was never configured, instead of it
looking like somebody chose 40.

**You can also now see what will happen before it happens.** The preview lists each section with its
current capacity and tells you whether it's new, already there and identical, or already there with a
*different* capacity. Nothing is ever overwritten — if next year's 5-A already exists, it's left
exactly as you set it and simply reported, so you can decide.

**Running it twice is safe.** A second run copies nothing and tells you it skipped everything.

**Two things I stopped short of, deliberately.** Editing a capacity directly in that preview list is a
screen change rather than a data one — the numbers are now available for it, but the editing controls
aren't built. And the rollover currently keeps no record of who ran it or when. Fixing that properly
needs a place to store *administrative* history, which doesn't exist yet — the existing history is
attached to individual students, and this isn't a student event. Both need your go-ahead rather than
me improvising.

**One thing worth your attention:** the same rollover button can optionally promote every student, and
it currently sits under the general "Configuration" permission. Earlier in this programme we
deliberately moved moving-students-between-sections to require student-records permission. The same
logic arguably applies here, but changing it alters who can do what, so I've left it as your call.

**Nothing has been committed or deployed, and none of this has been clicked through in a browser.**
