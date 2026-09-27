# PHASE 20 — Class & Section Modernization: Research and Design

**Status:** RESEARCH + DESIGN ONLY — no code modified, nothing deleted, not authorized for implementation
**Date:** 2026-08-16 · **Author:** Claude
**Branch:** `integration/phase2` @ `758c924` · **Tenant:** `SCH_B56BB9A401` (vikrantpublic), session `2026-27`

---

## 1. Executive summary

### 1.1 The headline

**The new Sections screen does not manage capacity at all.** `application/views/school_config/index.php`
contains **zero** references to `maxStrength` or `max_strength`. It manages section *existence*
(A/B/C/D/E/F toggles) and nothing else. Capacity was never migrated to it — it was never there.

The legacy page is therefore not a stale duplicate of the new screen. It is **the only implementation
of a capability the new screen never had**. That changes the retirement plan: this is a *completion*
job, not a *replacement* job.

### 1.2 What is already correct — and it is a lot

The capacity **engine** is mature, canonical, and correct. All five primitives live on the spine
(`application/core/MY_Controller.php`) as `protected` methods, which means a new controller inherits
them for free with zero re-implementation:

| Primitive | Line | Role |
| --- | ---: | --- |
| `_filterSectionRoster()` | 1258 | LC-1-tolerant roster filter (both field shapes) |
| `_sectionCapacityExceeded()` | 1295 | the single enforcement gate |
| `_sectionCapacity()` | 1328 | D17 resolution, memoised per request |
| `_sectionCapacityMessage()` | 1358 | operator-facing refusal text |
| `_cachedStudentDocsForRollCheck()` | 1388 | shared snapshot — prevents N+1 |

**All seven placement flows already route through the one gate.** Verified by tracing every caller to
its enclosing function:

| Flow | Site |
| --- | --- |
| Admission | `Sis.php:667` → `save_admission()` |
| Promotion | `Sis.php:1549` → `execute_promotion()` |
| Import (execute) | `Sis.php:5552` → `_runStudentImport()` |
| Import (validate) | `Sis.php:6610` → `import_validate()` |
| Enrollment | `Sis.php:9629` → `enroll_student()` |
| Session rollover | `School_config.php:4132` → `rollover_session()` |
| Section transfer | `Classes.php:750` → `transfer_students()` |

**There is no second capacity mechanism.** The audit found none. D17 is intact.

### 1.3 What is broken

Five findings, ranked. Two are new and material.

| ID | Severity | Finding |
| --- | --- | --- |
| **P20-F1** | **HIGH** | Section delete guards use single-shape predicates — the exact LC-1 anti-pattern that caused a prior production incident. A section holding legacy-shaped students can pass the "has students" check and be deleted. |
| **P20-F2** | **MEDIUM** | "Unlimited capacity" is **provably unreachable dead code**. `_sectionCapacity()` cannot return ≤ 0, so the `if ($capacity <= 0) return null;` branch at `MY_Controller:1307` never executes. |
| **P20-F3** | **MEDIUM** | No UI writes capacity except the legacy page. Every section created via the new screen or Academic Setup silently inherits the hardcoded default 40. |
| **P20-F4** | LOW | Three section-creation paths with three different validation rules. |
| **P20-F5** | LOW | `fetch_class_sections` measured at **2,092 ms** for one class (`application/logs/log-2026-08-16.php:381`). |

### 1.4 Direction: **A, with one carve-out**

**Consolidate** Class/Section/Capacity into one modern module — but **rollover stays in
`School_config`**. Source tracing, not preference, drives this. See §16.4.

---

## 2. Part 0 — Worktree safety (completed before any inspection)

| Item | State |
| --- | --- |
| Branch | `integration/phase2` @ `758c924` |
| Staged | **4 files** — `Admin_login.php`, `Staff.php`, `deptrole_helper.php`, `rbac_helper.php` |
| Modified | 117 |
| Untracked | 477 |
| Stashes | 3 |

**The 4 staged files are the `role_login_surface_gate` work, recorded as STAGED + HELD.** They are
unrelated to Class/Section and were not touched.

**`firebase-rules/firestore.rules` and `firestore.indexes.json` are already dirty** from other work.
Not read for modification, not touched, not deployed. Per standing rule, any future rules work on
this module must **pull first**.

Stashes inspected: none contains Class/Section work (`stash@{1}` holds only a `school_config_locks`
cache file for the wiped tenant `SCH_D94FE8F7AD`). **Nothing at risk of being overwritten.**

---

## 3. Part 2 — Audit of the current new Sections page

**Location:** `application/views/school_config/index.php` — 4,016 lines, 219 KB, single monolithic
view serving all Academic Setup tabs. Rendered by `School_config::index()` (`:56-60`).

### 3.1 Endpoints it calls

| Line | Endpoint | Purpose |
| ---: | --- | --- |
| 2782 | `school_config/get_all_sections` | load matrix |
| 3134 | `school_config/bulk_save_sections` | apply A–F toggles |
| 3196 | `school_config/save_section` | add one section |
| 3215 | `school_config/delete_section` | remove one section |

### 3.2 Verdict table

| Question | Answer |
| --- | --- |
| Backed by Firestore? | **Yes** — `sections` collection, canonical `sectionDocId()`. No RTDB. |
| Uses the canonical doc id? | **Yes** — `{schoolId}_{session}_{classKey}_{sectionKey}` |
| Session part of identity? | **Yes**, and correctly POSTed as an explicit target session |
| Is capacity read here? | **No — zero `maxStrength` references** |
| Is capacity written here? | **No** |
| Which page writes capacity? | **Only** `class_profile.php` → `Classes::save_section_settings()` (`:577`) |
| Calls legacy code? | **No** — fully separate from `Classes.php` |
| Competing document shapes? | **No** — both writers use `Firestore_service::saveSection()` |
| Legacy documents still written? | **No** |
| Native `alert`/`confirm`/`prompt`? | **One**, and it is for *locations* (`:1465`), not sections |
| Confirmation UX | Uses `_confirmModal()` promise-based modal (`:3208`) — **correct pattern to adopt** |

### 3.3 What is genuinely good here

- Session-scoped writes with an explicit `$sessionYear`, fixing a real 2026-05-28 bug where adding
  "Section A for 2027-28" checked 2026-27 and falsely reported "already exists".
- Per-row `try/catch` in `bulk_save_sections` so one failure does not abort the batch, with
  **structured** `failed[]` rows (`class_key`, `section`, `action`, `reason`) alongside legacy
  human-readable `skipped[]`.
- `@set_time_limit(180)` with a documented rationale (two Firestore round-trips per section at
  1–3 s each would exceed PHP's 30 s default and surface as an opaque HTTP 500).
- Stream-aware section labels (`"Science A"`) via `/^(?:[A-Za-z]+(?: [A-Za-z]+)* )?[A-Z]$/`.
- Promise-based confirm modal and toast feedback — no native dialogs.

**This screen is a good foundation. The new module should extend it, not replace it.**

### 3.4 What is missing

Capacity. Current strength. Available seats. Occupancy. Over-capacity indication. Class teacher.
Everything in Part 4's column list except Class, Section and Status.

---

## 4. Part 3 — Capacity / strength deep audit

### 4.1 Full lifecycle trace

| Stage | Implementation | Verdict |
| --- | --- | --- |
| CREATE section | `School_config::save_section:1327`, `bulk_save_sections:1631`, `Classes::add_section:286` | ⚠️ 3 paths, only 1 sets capacity (**P20-F4**) |
| SET capacity | `Classes::save_section_settings:577` | ⚠️ **sole writer**, legacy page only |
| READ capacity | `MY_Controller::_sectionCapacity:1328` | ✅ single canonical resolver |
| DISPLAY capacity | `class_profile.php` only | ⚠️ legacy page only |
| ADMISSION | `Sis::save_admission:667` | ✅ canonical gate |
| IMPORT | `Sis::_runStudentImport:5552`, `import_validate:6610` | ✅ canonical gate |
| PROMOTION | `Sis::execute_promotion:1549` | ✅ canonical gate |
| TRANSFER | `Classes::transfer_students:750` | ✅ canonical gate |
| ROLLOVER | `School_config::rollover_session:4132` | ✅ canonical gate |
| CURRENT STRENGTH | `_sectionActiveCount()` via `_filterSectionRoster` | ✅ session-scoped, dual-shape |
| AVAILABLE SEATS | *not computed anywhere* | ❌ **missing** |

**Conclusion: capacity resolution is already single-source.** The defect is *reach*, not correctness —
one legacy screen is the only door to a correct engine.

### 4.2 D17 semantics — verified intact

`MY_Controller::_sectionCapacity()` (`:1337-1350`):

```php
$capacity = 40;                                              // :1337
$raw = (int)($doc['maxStrength'] ?? $doc['max_strength'] ?? 0);
if ($raw > 0) $capacity = $raw;                              // :1345
return $memo[$key] = $capacity;
```

Configured positive wins; unset / zero / invalid → 40. **Exactly D17. Do not change.**

### 4.3 P20-F2 — "Unlimited" is unreachable dead code

`_sectionCapacityExceeded()` (`:1305-1307`):

```php
// <= 0 means "no capacity configured" => unlimited, mirroring the
// existing promotion/transfer guards. Never means "zero seats".
if ($capacity <= 0) return null;
```

But `$capacity` comes from `_sectionCapacity()`, which **initialises to 40 and only ever raises it**.
It cannot return ≤ 0. **This branch is provably unreachable.**

**Therefore: unlimited capacity does not exist in ZenX.** Every section is capped — at its configured
value, or at 40.

This is a **contradiction between the comment and D17**, and the *comment* is the fossil. D17
deliberately replaced "0 = unlimited" with "0 = use default 40". The dead branch is the residue.

> **This is not currently harming anyone** — behaviour is consistent and matches D17. It matters
> because any decision to offer "unlimited" in the new UI is an **engine change**, not a UI change,
> and would reopen a semantic D17 closed on purpose. See decision **P20-D2**.

### 4.4 Session scoping — verified

- Identity: `sectionDocId()` embeds session (`Firestore_service:1286-1293`).
- Memo key includes session (`:1331-1334`) with an explicit comment that omitting it would let a 2025
  lookup serve a 2026 capacity.
- Occupancy: `_filterSectionRoster(..., $this->session_year)`.
- Promotion/rollover pass the **destination** session.

**SEC-2 / SEC-11 style regressions cannot reappear through these paths**, because no caller builds a
doc id by hand — every one goes through `sectionDocId()`.

### 4.5 P20-F1 — HIGH: the delete guards violate LC-1

`School_config::bulk_save_sections` (`:1743-1746`) and `School_config::delete_section` (`:1425`):

```php
$stuDocs = $this->fs->schoolWhere('students', [
    ['sectionKey', '==', $sectionKey],
    ['status',     '==', 'Active'],
]);
if (!empty($stuDocs)) { /* refuse */ }
```

This is **precisely the anti-pattern documented at `Classes.php:245-255`**:

> *"a Firestore equality predicate matches ONE exact field name, so `['Class','==',…]` +
> `['Status','==','Active']` SILENTLY DROPPED every student stored in the camelCase shape. No error —
> the class simply came back short."*

…and which already caused a production incident (`Attendance.php:1218` — *"students disappear from
parent/teacher views after admin bulk saves"*).

**Failure mode:** a student stored in the legacy shape (`Status`, not `status`) or lacking the
`sectionKey` composite does not match. The guard sees zero students, reports the section empty, and
**deletes a section that still holds children**.

The correct tolerant filter — `_filterSectionRoster()` — **already exists on the spine** and is used
by `Classes.php` for exactly this. The delete guards simply do not call it.

> **Honest scoping:** this is a *proven code-path defect*, not a measured production incident. I have
> **not** counted how many students on this tenant lack `sectionKey` or carry `Status`. Establishing
> real exposure requires a read-only probe (§21). The guard is wrong regardless of current data,
> because the data shape it assumes is not guaranteed.

To its credit, `delete_section` checks **five** collections (students, feeDemands, attendance, and
more) — the *breadth* is right; the *predicate shape* is wrong. Fixing it is a small, contained
change to the filter, not a redesign.

---

## 5. Part 1 — External ERP research

### 5.1 Honest statement of what this research produced

I ran multiple web searches on competitor module design. **General queries returned vendor comparison
listicles and marketing pages with no design substance.** I am not presenting those as evidence, and
I have not manufactured an "industry standard" from them.

One search returned **genuinely citable, directly applicable material**, recorded below. Everything
else in this document is grounded in **source tracing of this codebase**, which is stronger evidence
for ZenX than any vendor blog.

### 5.2 Finding R-1 — RTE Act 2009 pupil-teacher ratios

| Field | Value |
| --- | --- |
| **Source** | Right of Children to Free and Compulsory Education Act, 2009 — Schedule; via Ministry of Education / PIB releases |
| **What it establishes** | PTR of **30:1 at primary** and **35:1 at upper primary**. Classes I–V staffing: 2 teachers ≤60 children; 3 for 61–90; 4 for 91–120; 5 for 121–200; PTR excluding head teacher ≤40 above 200 children. Classes VI–VIII: ≥1 teacher per 35 children. |
| **Directly applicable to ZenX?** | **Partially.** These are **pupil–teacher** ratios governing *staffing*, **not statutory caps on section size**. A school may legally run a 45-child section if staffed accordingly. |
| **Design recommendation only?** | **Yes.** It informs a sensible *default*; it does not mandate enforcement. |

**Consequence for ZenX:** the hardcoded default of **40** sits above both 30 and 35. That is a
defensible product choice, not a defect — but it means the default is *permissive*, and the "capacity
not set" state should be surfaced rather than hidden behind a plausible-looking 40. This is the
strongest argument for **P20-D3** (no backfill).

### 5.3 Findings I can state from domain knowledge, flagged as such

Not externally sourced in this phase. Recorded as **design input, not evidence**:

- PowerSchool / Infinite Campus use *section* to mean a **scheduled course-section** (a
  subject-period-teacher tuple), not the Indian homeroom "Class 9-A". Their capacity model attaches
  to the course-section and is consumed by a **scheduler**, which ZenX does not have.
- Indian K-12 ERPs (Fedena and similar) use *Course & Batch*.
- **ZenX's model — one homeroom section per student per session, capacity on the section — is the
  correct model for its market.** Adopting a course-section model would be a large regression in fit.

> **Recommendation:** if externally-sourced competitor workflows matter for this decision, supply
> specific product documentation URLs and §5 should be revisited against them. I will not
> back-fill this section with plausible-sounding claims.

---

## 6. Part 6 — Canonical data contract (single source of truth)

### 6.1 Section document

**Collection:** `sections`
**Document ID:** `{schoolId}_{session}_{classKey}_{sectionKey}` — built **only** via
`Firestore_service::sectionDocId()` (`:1286`). Never hand-built. *(SEC-2)*

| Field | Type | Req | Semantics |
| --- | --- | :-: | --- |
| `schoolId` | string | ✅ | tenant |
| `className` | string | ✅ | display form, `"Class 9th"` |
| `section` | string | ✅ | display form, `"Section A"` |
| `classOrder` | int\|null | ✅ | canonical rank, `CLASS_ORDER_MAP` |
| `sectionCode` | string | ✅ | bare token, `"A"` |
| `sectionKey` | string | ✅ | composite, `"Class 9th/Section A"` |
| `session` | string | ✅ | `YYYY-YY` |
| `classTeacher` | string | ✅ | `''` default; unused by UI today |
| `studentCount` | int | ✅ | stamped 0; **denormalised, do not trust** — occupancy is computed live |
| `updatedAt` | ISO8601 | ✅ | |
| `maxStrength` | int | ❌ | **absent on most docs.** > 0 = configured; absent/0 → 40 |
| `created_at` | string | ❌ | legacy, `Y-m-d H:i:s` |

All ten required fields are stamped by `Firestore_service::saveSection()` (`:1267-1277`).
**`maxStrength` is not among them** — the root of P20-F3.

### 6.2 Class ordering

`Entity_firestore_sync::CLASS_ORDER_MAP` (`:133-143`) is canonical:

```
PLAYGROUP -5 · PRE-NURSERY -4 · NURSERY -3 · LKG -2 · UKG -1 · 1…12
```

Already stamped on every section doc as `classOrder`. **The new module must read the stored value**,
falling back to `normalizeClassSection()` only for legacy docs that predate the stamp.

> A local `_classOrder()` helper added to `Classes.php` on 2026-08-16 duplicates this map. Correct
> output, wrong source. It retires with the controller and **must not be carried forward**.

### 6.3 Format contract — DO NOT CHANGE

Storage is **prefixed display form** (`"Class 9th"`, `"Section A"`); `sectionCode` carries the bare
`"A"` for consumers that need it. Verified against `Class_section_verify.php:11-14`, a CLI read-only
verifier that already asserts exactly this shape.

**This is a cross-platform contract — see §9.**

### 6.4 Competing contracts found

**None at the storage layer.** All three writers converge on `saveSection()` / `sectionDocId()`.
The duplication is entirely in *controllers and UI*, which is what makes retirement tractable.

---

## 7. Part 9 — Rollover audit

`School_config::rollover_session()` (`:3693`).

| Property | State | Evidence |
| --- | --- | --- |
| Copies sections forward | ✅ | `:3956-3998` |
| Copies `maxStrength` | ✅ | `:3998` — SEC-13 |
| Explicit capacity comparison | ✅ | `:3569-3573` src vs dst |
| Capacity enforcement on target | ✅ | `:4132` canonical gate |
| CAS locking | ✅ | `_config_lock_acquire('sessions')` `:3404`, released `:3507` |
| Idempotency | ✅ | `'idempotent' => true` `:3320`, `:3447` |
| Preview | ✅ | `preview_rollover()` `:3516` |
| Audit | ✅ | `log_audit` |

**SEC-13 comment (`:3956`) is explicit:** an earlier version *"copied the document field by field and
simply OMITTED `maxStrength`"*.

**Verdict: rollover is the most mature part of this domain. Do not rebuild it. Do not move it.**

---

## 8. Part 10 — Roll number relationship

`Classes::transfer_students()` (`:680`) writes exactly:

```php
$this->fs->updateEntity('students', $stuId, [
    'Class' => …, 'Section' => …,          // PascalCase
    'className' => …, 'section' => …,      // camelCase alias
    'updatedAt' => date('c'),
]);
```

- **Dual-shape write — LC-1 compliant.** ✅
- **Does not touch `rollNo`.** Rolls are preserved across transfer — consistent with **D19 / Option C**.
- Pre-flight roll-conflict detection exists (`rollClashes`, `:729-734`) and **blocks** the transfer
  rather than silently renumbering. ✅

**No second roll system exists.** The new module must call the existing roll service and must not
introduce assignment logic of its own.

---

## 9. Part 8 — Cross-module dependency map

### 9.1 Admin panel consumers of `sections`

`Academic.php:612,847` · `Admin.php:460,656` · `Admission_public.php:136` · `Attendance.php:5700` ·
`Communication.php:1859` · `Exam.php:319,378` · `Fees.php:327,1422,4165` · `Fee_management.php:290` ·
`NoticeAnnouncement.php:400` · `Ptm.php:332,539` · `Homework.php:776` ·
`Admission_xref_verify.php:74` · `Sis.php` · `School_config.php` · `MY_Controller.php`

All read `className` / `section` / `sectionKey`. **None reads `maxStrength`** except `Sis`,
`School_config`, `Classes` and `Sections_verify`.

### 9.2 Kotlin apps — CHECKED, not assumed

Both repos are present on this machine and were inspected directly.

| Question | Parent | Teacher |
| --- | --- | --- |
| Reads `sections` collection? | **No** | **No** |
| Reads `maxStrength`? | **No** | **No** |
| Consumes `sectionKey` composite? | **Yes — 8+ doc types** | **Yes — 9+ doc types** |

Parent: `AttendanceSummaryDoc`, `FeeDemandDoc`, `HomeworkDoc`, `IncidentDoc`, `LeaveApplicationDoc`,
`MarksDoc`, `PtmBookingDoc`, `PtmDoc`.
Teacher: the same set plus `AttendanceDoc`, `ResultDoc`.

Both annotate the format inline as `"Class 8th/Section A"` / `"Class 9th/Section A"`.

**Two conclusions:**

1. **Capacity is admin-only.** Neither app has any capacity coupling. A capacity-focused module
   carries **zero mobile risk**. *This clears the cross-platform rule for the capacity work.*
2. **`sectionKey` is a hard cross-platform contract.** Changing the stored section format would break
   attendance, homework, fees, marks, PTM, incidents, leave and results across **both** apps.
   `Firestore_service::saveSection():1256` already notes *"Android expects
   `{schoolId}_{session}_{className}_{section}`"*. **Do not change the format. Ever.**

This directly answers Part 8's `"Section A"` vs `"A"` concern: **both forms are canonical and both are
already stored** — prefixed in `section`, bare in `sectionCode`. Consumers pick. Nothing to migrate.

### 9.3 Firestore rules / indexes

No new collection, no new query shape, no new field is proposed. **No rules or indexes change is
expected.** They are currently dirty in the worktree from unrelated work and were not touched.
Re-verify at implementation time, **pull first**.

---

## 10. Part 7 — Legacy retirement classification

| Artefact | Lines | Class | Disposition |
| --- | ---: | :-: | --- |
| `views/class_profile.php` | 389 | 5 | Retire after capacity lands in the new screen |
| `views/section_students.php` | 1,653 | 5 | Retire after roster + transfer land |
| `views/manage_classes.php` | 162 | 1 | Safe to delete — written 2026-08-16, interim only |
| `Classes::save_section_settings` | :577 | **4** | **Reusable logic** — sole capacity writer; port, do not delete |
| `Classes::transfer_students` | :680 | **4** | **Reusable logic** — roll-conflict guard is correct; port |
| `Classes::loadClassesForTransfer` | :643 | 4 | Port |
| `Classes::add_section` | :286 | 5 | Duplicate of `School_config::save_section` — delete |
| `Classes::delete_section` | :891 | 5 | Duplicate — delete |
| `Classes::fetch_*`, `view`, `manage_classes`, `get_class_details`, `section_students` | — | 5 | Replaced by new endpoints |
| `Classes::format_class_name` | :614 | 5 | Superseded by `normalizeClassSection` |
| `Classes::_classLabel/_classOrder` | :~120 | 5 | Duplicates `CLASS_ORDER_MAP` — delete |
| `Classes::ensure_class_exists` | :19 | **6** | ⚠️ **Trace all callers before touching** — not yet traced |
| `MY_Controller` capacity primitives | 1258–1400 | **3** | **KEEP — required by 7 modules** |
| `School_config` section CRUD | — | **3** | **KEEP** — the new screen depends on it |
| `School_config::rollover_session` | :3693 | **3** | **KEEP — do not rebuild** |

*Classes: 1 = safe to delete · 2 = retire after migration · 3 = still required · 4 = reusable logic ·
5 = duplicate · 6 = hidden dependency risk*

**HTTP surface is self-contained:** every `classes/*` caller lives inside the module's own three
views. The single outside reference is `studentAdmission.php:1245` (a link added 2026-08-15), needing
one line repointed.

---

## 11. Part 4 — Proposed UI/UX

### 11.1 Placement

**Extend the existing Academic Setup → Sections tab.** Do not create a new top-level module.

Rationale from tracing: the new screen already owns section identity, session selection and creation,
and already uses the correct doc id. Capacity belongs *next to* the section it describes. A separate
module would recreate the two-location split this phase exists to remove.

### 11.2 Layout — matrix **and** table

**Keep the matrix** for structure (create/remove sections; fast across many classes).
**Add a table below it** for operations:

| Class | Section | Status | Capacity | Students | Available | Occupancy | Class Teacher | Actions |
| --- | --- | --- | ---: | ---: | ---: | --- | --- | --- |
| Class 1 | A | Active | 30 | 27 | 3 | ▓▓▓▓▓▓▓▓▓░ 90% | Mrs. Sharma | Edit |
| Class 1 | B | Active | 40 | 40 | 0 | ▓▓▓▓▓▓▓▓▓▓ 100% FULL | — | Edit |
| Class 2 | A | Active | *not set* | 12 | — | — | — | Set capacity |

Rows in canonical `classOrder`. Occupancy bar coloured at ≥90 % (amber) and ≥100 % (red).
`tabular-nums` on all figures.

### 11.3 Field set for the edit drawer — researched, not copied

| Field | Include? | Why |
| --- | :-: | --- |
| Capacity | ✅ | the point of the phase |
| Status (Active/Inactive) | ⚠️ **defer** | **No `status` field exists on section docs today.** Adding one is a schema change with its own migration. Out of scope. |
| Class teacher | ⚠️ **defer** | `classTeacher` field exists but is always `''`; ownership sits with Academic/Staff. Display only. |
| Section name | ❌ | renaming changes `sectionKey` → breaks both apps (§9.2). **Never editable.** |
| Stream | ❌ | already handled by the matrix's stream-aware labels |
| Session | ❌ | selected globally on the screen; per-row would invite cross-session writes |
| Notes | ❌ | no consumer, no requirement |
| Audit info | ✅ | show `updatedAt`; `log_audit` already records the rest |

**The drawer is capacity plus read-only context. That is the honest minimum, and it is what is
actually missing.**

### 11.4 Quality bar (Part 12)

Adopt what the screen already does well: `_confirmModal()` promise pattern, `toast()` feedback,
structured per-row failures. Add: skeleton loading, empty state, error state, **duplicate-click
protection on save**, inline validation, stale-session guard on session switch.

**Bootstrap 3 / AdminLTE only.** No Bootstrap 4/5. No native `alert`/`confirm`/`prompt`.
Panel JS must check `r.ok` **and** `{status:'error'}` — fail closed.

---

## 12. Part 5 — Capacity-change policy *(ZenX product decision — flagged as such)*

**This is not an industry standard. I found no citable source prescribing it.** It is a proposal
derived from ZenX's own D17 semantics and the absolute rule that students are never silently removed.

| Scenario | Proposed behaviour |
| --- | --- |
| cap 30, students 28 → **35** | **Allow silently.** Increase, no risk. |
| cap 30, students 28 → **29** | **Allow silently.** Still ≥ occupancy. |
| cap 30, students 30 → **25** | **Warn + confirm.** Modal states: *"Class 1-A currently holds 30 students. Setting capacity to 25 puts it 5 over. No student will be moved or removed. New admissions and transfers into this section will be refused until strength drops below 25."* Requires explicit confirm. |
| cap 30, students 32 (historical) → any | **Allow, mark over-capacity.** Pre-existing overage must never block a corrective edit. |
| capacity ≤ 0 | **Reject.** Under D17, 0 means "use default 40", not "unlimited" (§4.3). Accepting 0 would silently set 40 — the opposite of intent. |
| capacity > 200 | **Warn.** Above the RTE staffing bands (R-1); likely a typo. |

**Rationale for warn-and-allow over reject:** the engine already refuses *placement* when over
capacity (`_sectionCapacityExceeded`). Lowering capacity is a **configuration** act; refusing it would
leave a school unable to correct a mistyped 400 without first moving students. **Never removes a
student** — enforcement acts on the next placement, not retroactively.

---

## 13. Part 11 — RBAC model

Existing separation is correct and must be preserved:

| Capability | Permission | Nature |
| --- | --- | --- |
| Create / delete section | `Configuration:edit` | structural |
| **Set capacity** | `Configuration:edit` | **structural** |
| View sections + occupancy | `Configuration:view` | structural |
| Transfer students | **`SIS:manage`** | **lifecycle** |
| Promotion | `SIS:manage` | lifecycle |
| Rollover | `Configuration:edit` | structural |

**Capacity is structural configuration → `Configuration`.** Moving a child is a lifecycle act →
`SIS`. Do not merge them. A timetable coordinator may legitimately set capacity without any right to
move a student between sections.

> **Implementation note:** prefer `has_permission()` over the legacy `_require_role()` name-gate,
> which shadows RBAC and blocks custom roles. `School_config` currently uses
> `_require_role(self::ADMIN_ROLES, …)`.

---

## 14. Part 13 — SRNO safety sweep *(mandatory, standing rule)*

| Check | Result |
| --- | --- |
| `srNo` used as identity in class/section code | ❌ none |
| `srNo` mutated by section transfer | ❌ **none** — `transfer_students` writes only class/section/updatedAt |
| `srNo` mutated by rollover | ❌ **none** — `School_config` has zero `srNo` references |
| `srNo` used as foreign key | ❌ none |
| `admissionNo` substituted for `srNo` | ❌ none |
| Section docs keyed by student identity | ❌ none — keyed by `{school}_{session}_{class}_{section}` |
| `srNo` read correctly | ✅ `Classes.php:537` — read-only, with `srNo ?? srNumber ?? 'SR Number'` fallbacks |
| Roll No displayed where SR No required | ✅ none found in this domain |
| Tenant scoping of SRNO resolution | ✅ `srNoIndex/{schoolId}_{srNoNorm}` — cross-tenant resolution *"not expressible through this path"* (`MY_Controller:1001-1003`) |

**SRNO is preserved across section transfer and across rollover. No violation found.**

**Design constraint carried forward:** the new module must never write `srNo`, never key a section
document by student identity, and must display **SR No** (not Roll No) wherever permanent student
identity is shown in rosters.

---

## 15. Test & UAT strategy

### 15.1 Existing assets to reuse

- `Class_section_verify.php` — CLI, read-only, idempotent, safe during live traffic. Already asserts
  the canonical 4-field shape. **Extend it with a `maxStrength` coverage report.**
- `Sections_verify.php` — already references `maxStrength`.
- PHPUnit: `vendor/bin/phpunit --testsuite Unit`. **Baseline is 10 pre-existing failures + 27
  skipped** (Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2). Compare against
  this baseline; **do not claim green.**

### 15.2 New tests required

| ID | Test |
| --- | --- |
| T-1 | `_sectionCapacity()` returns 40 for absent / 0 / negative / non-numeric `maxStrength` (D17) |
| T-2 | Capacity written by the new endpoint is read back identically by `_sectionCapacity()` |
| T-3 | **Delete guard detects a student stored in legacy `Status` shape** (P20-F1 regression) |
| T-4 | **Delete guard detects a student with no `sectionKey`** (P20-F1 regression) |
| T-5 | Capacity for session 2026-27 is not served for 2027-28 (SEC-2 / memo key) |
| T-6 | Lowering capacity below occupancy does not modify any student document |
| T-7 | `classOrder` read from doc, not recomputed |
| T-8 | Section rename is not exposed by any new endpoint (`sectionKey` immutability) |

### 15.3 Browser UAT matrix

| # | Action | Expected |
| --: | --- | --- |
| 1 | Open Sections tab | Matrix + table, canonical order, no console errors |
| 2 | Class with no `maxStrength` | Shows **"not set"**, never a plausible 40 |
| 3 | Set capacity 30 | Toast; persists across reload |
| 4 | Admit into that section until 30 | 31st refused with capacity message |
| 5 | Lower 30 → 25 with 30 students | Confirm modal; on confirm, **student count unchanged** |
| 6 | Re-check roster after #5 | **All 30 students present** |
| 7 | Delete a section holding students | Refused, count shown |
| 8 | Switch session | Table reloads; no stale capacity |
| 9 | Rollover to next session | Capacity carried (SEC-13) |
| 10 | Double-click Save | One write only |
| 11 | `Configuration:view`-only user | Read-only; edit hidden and server-refused |
| 12 | Transfer student between sections | Destination capacity enforced; **SR No unchanged** |
| 13 | Teacher + Parent app after all of the above | Attendance/homework unaffected |

---

## 16. Direction: **A or B?**

### 16.1 The question restated

Consolidate everything into one Class & Section module, or keep some Academic Setup functionality
separate?

### 16.2 What source tracing shows

The operator's stated preference is **A**. Tracing largely supports it — with one exception that
tracing, not preference, forces.

### 16.3 Answer: **A for the operational domain**

Class structure, section structure, capacity, occupancy and placement views belong in **one** screen —
the existing Academic Setup → Sections tab, extended. Reasons:

1. Capacity is meaningless without the section it describes; splitting them created this phase.
2. The new screen already owns section identity and the correct doc id.
3. Occupancy needs `_filterSectionRoster` + `_cachedStudentDocsForRollCheck`, both inherited from the
   spine — the same snapshot serves matrix and table in one read.
4. It removes the third creation path (P20-F4) by construction.

### 16.4 The carve-out: **rollover stays in `School_config`** (B, narrowly)

**Rollover must not move.** Evidence:

- It is the most mature code in the domain: CAS locking, idempotency, preview, audit, SEC-13
  capacity carry-forward.
- It rolls **far more than sections** — sessions, streams, fee structures. Sections are one step in a
  larger transaction under one lock.
- Extracting the section step would either duplicate the lock (two writers, one resource) or fragment
  an atomic operation. Both are regressions.

**The new module should *link to* rollover, never reimplement it.** The user's own instruction —
*"Do not build a second rollover system"* — and the source agree.

### 16.5 Net

> **A for Class / Section / Capacity / Occupancy. B for session rollover, which stays where it is and
> is reached by a link.**

---

## 17. Migration strategy

**None required.** No collection changes, no document id changes, no field added, removed or renamed.
`maxStrength` is already the canonical field and already read by the engine.

**Explicitly rejected: backfilling `maxStrength = 40`.** It would write an accident as if it were a
decision and permanently destroy the distinction between "configured 40" and "never configured"
(P20-D3).

---

## 18. Rollback strategy

Because no data shape changes, **there is no data rollback** — the entire risk is code.

| Phase | Rollback |
| --- | --- |
| 1–3 (build alongside) | Legacy untouched; revert new files |
| 4 (repoint links) | One-line revert |
| 5 (soak) | Sidebar revert restores legacy |
| 6 (delete legacy) | `git revert` — requires a clean commit immediately before |

**Hard requirement:** a clean commit **immediately before step 6**, so deletion is one revertible
commit. Given 117 modified + 477 untracked files currently in the tree, that commit must be
explicitly scoped and separately authorized.

---

## 19. Risks

| ID | Risk | Severity | Mitigation |
| --- | --- | :-: | --- |
| R1 | P20-F1 deletes a populated section before it is fixed | **HIGH** | Fix the guard **first**, before any UI work |
| R2 | Changing section format breaks both Kotlin apps | **HIGH** | Format is immutable; rename never exposed (T-8) |
| R3 | Reopening the "unlimited" semantic contradicts D17 | MED | P20-D2 default **NO** |
| R4 | Second occupancy derivation disagrees with the guard (SEC-11 relapse) | MED | One derivation, shared snapshot |
| R5 | Deleting `ensure_class_exists` with untraced callers | MED | Class 6 — trace before touching |
| R6 | 477 untracked files make a clean pre-deletion commit hard | MED | Scope and authorize separately |
| R7 | Occupancy across all classes is expensive | LOW | One snapshot, memoised (proven pattern) |

---

## 20. Open product decisions

| ID | Decision | Recommendation |
| --- | --- | --- |
| **P20-D1** | Confirm direction A + rollover carve-out (§16) | **Adopt** |
| **P20-D2** | Expose "unlimited" capacity? | **No.** Engine change; contradicts D17 (§4.3) |
| **P20-D3** | Backfill `maxStrength = 40`? | **No.** Show "not set" (§17) |
| **P20-D4** | Fix P20-F1 now, or with the module? | **Now** — it is a live data-loss path (R1) |
| **P20-D5** | Capacity-change policy (§12) — warn+allow? | **Adopt as ZenX policy** |
| **P20-D6** | Add section `status` field? | **Defer** — schema change, own migration |
| **P20-D7** | Class teacher editable here? | **Defer** — display only |
| **P20-D8** | Default capacity 40 vs RTE-informed 30/35? | **Keep 40**; surface "not set" instead |
| **P20-D9** | Should I run a read-only probe to size P20-F1 exposure? | **Recommend yes** — read-only, no writes |

---

## 21. Proposed implementation phases *(none authorized)*

| Phase | Work | Gate |
| --: | --- | --- |
| **20.1** | **Fix P20-F1** — route both delete guards through `_filterSectionRoster()` | T-3, T-4 pass |
| 20.2 | Read-only probe: count sections without `maxStrength`; students without `sectionKey` / with `Status` | Report only, no writes |
| 20.3 | Backend: `get_all_sections` returns capacity + occupancy; new `save_section_capacity` endpoint | T-1, T-2, T-5 |
| 20.4 | `saveSection()` accepts optional `maxStrength` — closes P20-F3 at creation | — |
| 20.5 | UI: capacity/occupancy table + edit drawer + §12 policy | UAT 1–11 |
| 20.6 | Port `transfer_students` + roster to the new screen under `SIS:manage` | UAT 12, SRNO sweep |
| 20.7 | Repoint `studentAdmission.php:1245`; move sidebar entry | — |
| 20.8 | **Soak** — operator verification | UAT 1–13 |
| 20.9 | Clean commit, then delete `Classes.php` + 3 views | Rollback point established |
| 20.10 | Re-run `Class_section_verify`; full cross-module regression | Baseline unchanged |

**Completion definition (per standing rule):** this module is complete only when backend, UI,
capacity, section management, class management, rollover integration, placement integration,
permissions, audit, cross-module compatibility, SRNO integrity, legacy retirement **and browser UAT**
are all addressed. Backend-only is not complete.

---

## 22. Status

**RESEARCH + DESIGN ONLY.**
No code modified this phase. Nothing deleted. No rules or indexes touched. Nothing committed, pushed
or deployed. The 4 staged files and 3 stashes are untouched.

**Sources:** [RTE pupil-teacher ratio (PIB)](https://www.pib.gov.in/newsite/PrintRelease.aspx?relid=77587) ·
[Student-Teacher Ratio (DoSEL, Ministry of Education)](https://dsel.education.gov.in/sites/default/files/update/Student-Teacher-Ratio.pdf) ·
[CAG Performance Audit, RTE Act Ch. IV Teachers](https://cag.gov.in/uploads/download_audit_report/2017/Chapter_4_Teachers_of_Report_No.5_of_2017_-_Performance_Audit_on_Implementation_of_the_Right_of_Children_to_Free_and_Compulsory_Education_Act,_2009_Government_o.pdf)
