# Sections & Seats — Module Design

**Replaces:** the legacy "Classes & Sections" module (`application/controllers/Classes.php`)
**Status:** DESIGN ONLY — nothing deleted, nothing built, not authorized for implementation
**Author:** Claude · **Date:** 2026-08-16
**Tenant context:** `SCH_B56BB9A401` (vikrantpublic), session `2026-27`

---

## 0. Scope of this document

The operator's instruction was: *retire the legacy module, delete it, rebuild from scratch under a
new name, after real research.*

This document is the research and the design. It deliberately **stops before deletion**, because the
audit below found that "delete the module" and "delete the feature" are not the same act — one is
safe, the other breaks admission, promotion, transfer, attendance and fees on a live tenant.

**What is NOT in this document:** implementation, migration scripts, deleted files, schema changes.
No file has been deleted or created for the new module.

---

## 1. Executive summary

| Question | Answer |
| --- | --- |
| Is the module legacy? | **Yes.** 3,023 lines across a 981-line controller and two jQuery views, one of which is 1,653 lines. |
| Can it be deleted? | **The UI and controller: yes.** The `sections` collection and `maxStrength`: **no.** |
| Why not? | `maxStrength` is enforced in `MY_Controller` — the spine. ~15 other modules read `sections`. |
| Is there a deeper defect? | **Yes — the feature is split across two modules that disagree.** See §3. |
| Measured problem? | `classes/fetch_class_sections` took **2,092 ms** for one class (`application/logs/log-2026-08-16.php:381`). |
| Recommended scope | Replace the UI + controller. Keep the storage contract byte-for-byte. |

---

## 2. Current-state audit

### 2.1 The legacy module

| File | Lines | Notes |
| --- | ---: | --- |
| `application/controllers/Classes.php` | 981 | 16 public methods |
| `application/views/section_students.php` | 1,653 | 28 jQuery/`$.ajax` blocks |
| `application/views/class_profile.php` | 389 | jQuery; **only** capacity editor in the panel |
| `application/views/manage_classes.php` | 162 | written 2026-08-16 to restore a dead route; also retired |

Controller surface — `application/controllers/Classes.php`:

| Method | Line | Verdict |
| --- | ---: | --- |
| `ensure_class_exists` | 19 | keep behaviour, re-home |
| `manage_classes` | 35 | replaced by new list screen |
| `section_students` | 52 | replaced by new roster screen |
| `fetch_classes_grid` | 72 | replaced |
| `fetch_class_sections` | 185 | replaced — **this is the 2 s call** |
| `add_section` | 286 | **duplicate** of `School_config::save_section` |
| `fetch_sections_list` | 358 | replaced |
| `get_class_details` | 389 | replaced |
| `view` | 446 | replaced |
| `fetch_section_students` | 466 | replaced |
| `get_section_settings` | 548 | replaced |
| `save_section_settings` | 577 | **unique** — only writer of `maxStrength` from a UI |
| `format_class_name` | 614 | superseded by canonical `normalizeClassSection` |
| `loadClassesForTransfer` | 643 | **unique** |
| `transfer_students` | 680 | **unique** — only student-between-sections mover |
| `delete_section` | 891 | **duplicate** of `School_config::delete_section` |

### 2.2 The HTTP surface is self-contained — good news

Every caller of a `classes/*` endpoint lives inside the module's own three views. The only outside
reference is a "change capacity" link added to `application/views/studentAdmission.php:1245` on
2026-08-15, which needs one line repointed.

**Consequence:** retiring these endpoints breaks no other module. This is the part that is genuinely
safe to delete.

### 2.3 The data contract is NOT self-contained

`sections` is read by at least fifteen other modules:

`Academic.php:612,847` · `Admin.php:460,656` · `Admission_public.php:136` · `Attendance.php:5700` ·
`Communication.php:1859` · `Exam.php:319,378` · `Fees.php:327,1422,4165` · `Fee_management.php:290` ·
`NoticeAnnouncement.php:400` · `Ptm.php:332,539` · `Homework.php:776` · `Admission_xref_verify.php:74` ·
`School_config.php` (rollover) · `Sis.php` · `MY_Controller.php`

`maxStrength` is consumed by `Classes.php`, `School_config.php`, `Sis.php`, `Sections_verify.php`,
and **`application/core/MY_Controller.php`**.

---

## 3. The root defect: the feature lives in two places that disagree

This is the finding that matters most, and it is the thing the operator has already felt as
*"why is the strength in a different location"*.

| Capability | Academic Setup (`School_config`) | Classes & Sections (`Classes`) |
| --- | --- | --- |
| Create / soft-delete / restore **class** | ✅ `:829`, `:988`, `:1130` | ❌ |
| Create **section** | ✅ `save_section:1327` | ⚠️ `add_section:286` (second impl.) |
| Delete section | ✅ `:1383` | ⚠️ `:891` (second impl.) |
| Bulk section edit | ✅ `bulk_save_sections:1631` | ❌ |
| **Set seat capacity** | ❌ | ✅ `save_section_settings:577` — **sole writer** |
| **Move students between sections** | ❌ | ✅ `transfer_students:680` — **sole mover** |
| Session rollover of capacity | ✅ SEC-13 `:3956-3998` | ❌ |

Neither module is complete. An admin must use both, and nothing in either UI says so.

### Defects this split produces

**SS-D1 — Sections created in Academic Setup have no capacity at all.**
`School_config::save_section()` (`:1366`) passes only `['created_at' => …]` to `saveSection()`, and
`Firestore_service::saveSection()` (`:1267-1277`) stamps `schoolId`, `className`, `section`,
`classOrder`, `sectionCode`, `sectionKey`, `classTeacher`, `session`, `studentCount`, `updatedAt` —
**but not `maxStrength`**. The section therefore falls to `MY_Controller`'s hardcoded default of 40.

*This is the direct cause of the `(0/40)` the operator queried on the admission form.* The 40 was
never configured by anyone; it is a fallback presented as if it were a setting.

**SS-D2 — "Unlimited" is documented but unreachable.**
`MY_Controller` (`:1184-1187`) defines `maxStrength <= 0` as **UNLIMITED**. Both writers
(`Classes::add_section:295`, `Classes::save_section_settings:586`) reject `<= 0` as "Invalid input".
A documented semantic that no UI can express.

**SS-D3 — Divergent section-name validation.**
`School_config::save_section` enforces `^[A-Z]$` (single capital letter). `Classes::add_section`
accepts any string and prefixes `"Section "`. Two doors, two rulebooks, one collection.

**SS-D4 — Ordering was reinvented.**
`Entity_firestore_sync::CLASS_ORDER_MAP` (`:133-143`) is the canonical rank
(PLAYGROUP −5 → UKG −1 → 1…12) and is already **stamped onto every section doc as `classOrder`** by
`saveSection()`. The listing screen ignored it and sorted by insertion order, which surfaced as
`10th, 1st, 2nd … 9th, Lkg, Nursery, Playgroup`.
*(A local `_classOrder()` helper added on 2026-08-16 also duplicates this map — the new module must
read the stored `classOrder` instead, and that helper retires with the controller.)*

**SS-D5 — Performance.** `fetch_class_sections` is 2,092 ms measured. It calls
`_cachedStudentDocsForRollCheck()` and filters per section; the shared snapshot avoids N+1 Firestore
reads, but the per-section PHP filtering is re-run for every section on every page load.

---

## 4. The contract that must survive — non-negotiable

The new module **must not change any of the following.** Each carries incident history.

### 4.1 Document identity
```
sections/{schoolId}_{session}_{classKey}_{sectionKey}
```
Built only via `Firestore_service::sectionDocId()` (`:1286`). Never hand-built.

> **SEC-2** (`Classes.php:305-330`): a writer once hand-built this id *without the session*. Create,
> read and update addressed different documents. The settings screen showed nothing after a save,
> and `_sectionCapacity()` silently enforced 40 for every school. The capacity guards were correct —
> they were handed the wrong number.

### 4.2 Field shape (`Firestore_service::saveSection`)
`schoolId`, `className`, `section`, `classOrder`, `sectionCode`, `sectionKey`, `classTeacher`,
`session`, `studentCount`, `updatedAt`, and `maxStrength` where set.
Read tolerantly: `maxStrength ?? max_strength ?? 0`.

### 4.3 Capacity semantics (`MY_Controller::_sectionCapacity`, `:1330-1351`)
- Active students consume a seat; Withdrawn / TC / Inactive do **not**.
- Session-scoped: 2025 Class 5-A does not consume 2026 Class 5-A. *(**SEC-11**: this query was once
  school-scoped only, so a section reading 45/40 "FULL" in the UI still admitted on the server.)*
- Absent `maxStrength` → default **40**, matching `get_class_sections()` so picker and enforcement agree.
- `maxStrength <= 0` → **UNLIMITED**. Changing this would retroactively lock every never-configured section.

### 4.4 Dual field shapes (LC-1)
`students` holds both legacy PascalCase (`Class`, `Section`, `Status`) and canonical camelCase
(`className`, `section`, `status`). **A Firestore equality predicate binds to one exact field name.**
Roster queries must filter in PHP across both shapes — never with a single-shape `where`.

> This already caused a production incident (`Attendance.php:1218` — *"students disappear from
> parent/teacher views after admin bulk saves"*), and a partial Phase 16.6 fix that added session
> scoping while leaving the single-shape predicates in place.

### 4.5 Session rollover
`School_config.php:3956-3998` copies `maxStrength` forward (SEC-13). If capacity ownership moves,
this path moves with it — or capacity silently resets to 40 every new session.

---

## 5. Proposed module: **Sections & Seats**

### 5.1 Why this name
The domain vocabulary is not shared across the market: PowerSchool and Infinite Campus use *section*
to mean a scheduled course-section; Indian K-12 ERPs such as Fedena use *Course & Batch*; this
product's own users say *Class*, *Section*, and *strength*. "Sections & Seats" names the two things
the screen actually manages, keeps the operator's word (*section*), and avoids colliding with
"Academic Setup", which owns structure definition.

> **Research note, stated plainly:** general web searches on competitor module design returned vendor
> comparison listicles with no design substance. This design is grounded in the codebase's own audit
> and incident history, not in claimed competitor research. If specific product documentation is
> provided, the naming and IA sections should be revisited against it.

### 5.2 Information architecture

```
Student Info System
  └── Sections & Seats
        ├── L1  Overview      all classes, seats vs filled, problems surfaced
        ├── L2  Class         sections in one class; edit seats inline
        └── L3  Section       roster; move students out
```

### 5.3 Screens

**L1 — Overview.** One card per class, in canonical `classOrder`. Each card shows sections count,
total seats, filled, and a fill bar. Header summary: classes · sections · seats · filled.
Surfaces the states that matter and are currently invisible:
- `capacity not set` (SS-D1) — amber, actionable
- `unlimited` (SS-D2) — explicit, not "0"
- `over capacity` — red, when filled > seats

**L2 — Class.** Sections table: name · seats · filled · free · class teacher. Seats editable inline,
saved per row. Add section here — **one** creation path, replacing both SS-D3 doors.

**L3 — Section.** Roster with the move-students action (the unique `transfer_students` capability),
carried over with its existing guards intact.

### 5.4 Endpoints (new controller `Section_seats.php`)

| Route | Method | RBAC | Replaces |
| --- | --- | --- | --- |
| `section_seats/` | GET | `Configuration:view` | `classes/manage_classes` |
| `section_seats/class/{slug}` | GET | `Configuration:view` | `classes/view/{slug}` |
| `section_seats/section/{c}/{s}` | GET | `Configuration:view` | `classes/section_students/...` |
| `section_seats/overview_data` | POST | `Configuration:view` | `fetch_classes_grid` |
| `section_seats/class_data` | POST | `Configuration:view` | `fetch_class_sections` |
| `section_seats/roster_data` | POST | `Configuration:view` | `fetch_section_students` |
| `section_seats/save_seats` | POST | `Configuration:edit` | `save_section_settings` |
| `section_seats/add_section` | POST | `Configuration:edit` | both creation paths |
| `section_seats/move_students` | POST | `Configuration:edit` | `transfer_students` |

Notes carried from existing traps:
- Every POST must be checked against `csrf_exclude_uris` policy in `application/config/config.php`
  *(these are not `superadmin/*`, so they should NOT need exclusion — verify, don't assume)*.
- Panel JS must check `r.ok` **and** `{status:'error'}` before rendering success. Fail closed.
- Prefer `has_permission()` over the legacy `_require_role()` name-gate, which blocks custom roles.

### 5.5 Performance target
Overview must aggregate from **one** section-collection read plus the shared student snapshot — not
per-class queries. `fetch_classes_grid` already proves the section side is a single read. Budget:
**≤ 400 ms** for L1 against the current measured 2,092 ms for a single class in L2.

---

## 6. Retire plan (not yet authorized)

Ordered so the panel is never broken between steps.

1. Build `Section_seats.php` + views alongside the legacy module. Both live. No deletions.
2. Repoint `studentAdmission.php:1245` to the new class screen.
3. Move the sidebar entry from "Classes & Sections" to "Sections & Seats".
4. **Soak.** Operator confirms seats edit, section add, and student move all work on the new screens.
5. Only then delete `Classes.php`, `class_profile.php`, `section_students.php`, `manage_classes.php`.
6. Retire `School_config`'s duplicate section CRUD **only if** the consolidation decision is taken —
   and only after its rollover path (§4.5) is re-verified.

**Rollback:** until step 5, reverting is a one-line sidebar change. After step 5 it is a `git revert`;
the branch must therefore have a clean commit immediately before step 5. Since **no data shape
changes at any step**, no data rollback is required at all — that is the whole point of keeping the
contract.

---

## 7. Cross-module impact

| Surface | Impact | Why |
| --- | --- | --- |
| Attendance, Fees, Exam, PTM, Communication, Homework, Notice | **None** | read `sections`; shape unchanged |
| Admission (`Sis`, `Admission_public`) | **None** | capacity guard untouched |
| Promotion / Transfer / TC | **None** | same guard |
| `School_config` rollover | **None** under recommended scope | untouched |
| **Parent app (Kotlin)** | **None expected — must be confirmed** | reads `sections`; no field added/removed/renamed |
| **Teacher app (Kotlin)** | **None expected — must be confirmed** | `saveSection:1256` comment states Android expects the exact docId `{schoolId}_{session}_{className}_{section}` — preserved |
| `firestore.rules` / indexes | **None expected** | no new collection, no new query shape. Re-verify at build time per standing rule; **pull first**. |

Per the cross-platform rule, the two Kotlin app checks are **open items**, not cleared ones.

---

## 8. Open decisions

| ID | Decision | Recommendation |
| --- | --- | --- |
| **SS-D6** | Consolidate `School_config`'s section CRUD into this module? | Operator chose *design first*; recommend **yes, in a later phase**, after soak. |
| **SS-D7** | Expose "unlimited" (`maxStrength = 0`) in the UI? (SS-D2) | **Yes** — as an explicit checkbox, so the documented semantic becomes reachable. |
| **SS-D8** | Backfill `maxStrength` on sections created via Academic Setup? (SS-D1) | **No backfill.** Show "capacity not set" and let the operator decide. A backfill would write 40 as if it were chosen. |
| **SS-D9** | Single-letter section names only? (SS-D3) | Adopt `School_config`'s `^[A-Z]$` as the single rule. |
| **SS-D10** | Show class-teacher assignment on L2? | Defer — `classTeacher` exists but ownership sits with Academic/Staff. |

---

## 9. What this design explicitly refuses to do

- **Delete `sections` or `maxStrength`.** Breaks the spine and ~15 modules.
- **Change the section docId.** SEC-2 is a solved incident; re-opening it re-breaks capacity silently.
- **Re-derive occupancy by a cheaper route for the list screen.** SEC-11 was exactly that bug: a UI
  number that disagreed with the server's guard. One derivation, shared.
- **Migrate data.** Nothing about storage changes. This is a UI and controller replacement.

---

## 10. Status

**DESIGN ONLY.** No files deleted. No new module files created. Not authorized for implementation.
The 2026-08-16 fixes to `Classes.php` and `manage_classes.php` remain in the working tree as interim
repairs; they retire with the module at step 5.

Everything remains **uncommitted**.
