# SUBJECT-FS-CUTOVER-01 — Firestore Subject Canonicalization Audit & Migration Plan

**Status:** AUDIT & DESIGN ONLY — no implementation / commits / pushes / writes
**Date:** 2026-06-09 · branch `ank-yug_b1` (ZenX) · `c:\xampp\htdocs\ZenX\school`

> ⚠️ **Scope-changing finding:** since this task was scoped, concurrent exam-cutover work (`captain196`, "EXAM-DEF-FS") **already migrated `Exam::get_subjects()` to Firestore**. The migration is far more advanced than the task's Phase plan assumes. `Exam_engine::get_subject_list()` is now **dead code** (zero callers). Only **LMS**, a **SIS fallback**, and **dead-code/docblock cleanup** remain. This report reflects the **current** code, not the original assumption.

---

## 1. ARCHITECTURE AUDIT

### 1.1 Canonical source — Firestore `subjects` collection
- **Doc id:** `{schoolId}_{classKey}_{subjectCode}`
- **Fields:** `schoolId, classKey, subjectCode, name, category, stream, session, updatedAt` (camelCase canonical; reads also accept snake_case `code`/`subject_name`).
- **Writers (Firestore-only):** `School_config::save_subject` (:1992), `save_bulk_subjects` (:2333), `delete_subject` (:2074, with referential-integrity check vs `subjectAssignments`).
- **Readers (active, Firestore):** `School_config::get_subjects` (:1682), `Exam::_build_subjects_map` (:371) + `Exam::get_subjects` (:874), `Academic::_getSubjectCatalogFromFirestore` (:1529), `Sis` (:476/:3691/:3844/:3899/:4276), `Subject_master_verify` (CLI). **Populated & used at runtime.**

### 1.2 RTDB `Schools/{school}/Subject_list` — current consumer map
| Consumer | File:line | Status | Category | Breaks if RTDB gone? |
|---|---|---|---|---|
| `Exam::get_subjects()` (Marks Entry / Template Designer AJAX) | Exam.php:874 | **MIGRATED → Firestore** (`_build_subjects_map`) | Active production (now FS) | No |
| `Exam_engine::get_subject_list()` | Exam_engine.php:496-519 | **DEAD** — zero callers (only caller was `get_subjects`, now migrated) | Dead code | No (nothing calls it) |
| `Lms::get_classes_subjects()` | **Lms.php:76** | **RTDB read** (`firebase->get(".../Subject_list")`) | **Active production — migration debt** | **YES** (LMS subject dropdowns empty) |
| `Sis::get_additional_subjects()` | **Sis.php:3853** | **RTDB fallback** after Firestore `subjects` | Active (Firestore-first; fallback = debt) | Only if Firestore `subjects` also empty |
| `Data_service` `'subjects'` mapping | Data_service.php:30,71 | **DEAD** — no caller uses `data->get('subjects')`; mobile uses `subjectAssignments`/`sections` | Dead / migration debt | No |
| Academic docblock "Subject_list" | Academic.php:250,255 | **Stale comment** — code reads Firestore (:292, `_getSubjectCatalogFromFirestore`) | Doc debt | No |
| Examination / School_config docblocks | Examination.php:16, School_config.php:23 | **Stale comment** only (no runtime read found) | Doc debt | No |

### 1.3 RTDB Subject_list WRITERS — **NONE**
No code writes `Schools/{school}/Subject_list`. The node is **write-orphaned (zombie)**: it only holds legacy data. School_config writes subjects to **Firestore**, never RTDB.

### 1.4 Module dependency summary (current)
- **Marks Entry / Examination / Results:** Firestore (`Exam::get_subjects` → `_build_subjects_map`). ✅
- **Teacher App / Mobile API:** no live RTDB Subject_list dependency — `Data_service` `'subjects'` mapping is dead; mobile subject data comes from `subjectAssignments`/`sections` (Firestore). ✅
- **LMS:** **RTDB Subject_list (`Lms.php:76`) — the principal remaining runtime dependency.** ❌
- **SIS:** Firestore-first with an RTDB **fallback** (`Sis.php:3853`). ⚠️
- **Academic / Homework / PTM / Staff / School_config:** Firestore (`subjects` / `subjectAssignments` / `sections`). ✅

---

## 2. DATA-CONTRACT COMPARISON
| Concept | RTDB `Subject_list/{classKey}/{code}` | Firestore `subjects` doc | Mapping |
|---|---|---|---|
| subject name | `subject_name` \| `name` | `name` (`subject_name` alias) | ✅ 1:1 |
| code | node key `{code}` | `subjectCode` | ✅ |
| category | `category` | `category` | ✅ |
| stream | `stream` | `stream` | ✅ |
| class key | node index (`9`, `Nursery`, `LKG`…) | `classKey` (numeric string / pre-primary label) | ✅ via normaliser (`_subject_class_key` / `_numeric_class_key`) |
| `pattern_type` | per-class metadata (skipped by all list readers) | **absent** | ⚠️ RTDB-only; **not consumed** by any subject-list reader (`get_subject_list` skips it; `School_config::get_subjects` returns `pattern_type=null`) |

**Does Firestore `subjects` fully satisfy all current consumers?** **YES** — for name/code/category/stream. The only RTDB-only artifact (`pattern_type`) is unused by every subject-list consumer. Class-key normalisation is already implemented on the Firestore side.

---

## 3. KEY FINDINGS / RISKS
1. **🔴 Live data-drift bug (LMS):** `Lms.php:76` reads the **orphaned** RTDB Subject_list, which **nothing writes**. Subjects added/edited via School_config (Firestore) **do not appear in LMS**. On a fresh tenant the RTDB node is empty → LMS subject dropdowns are already empty. This is a *current correctness bug*, not just future debt. **HIGH.**
2. **🟡 SIS RTDB fallback (`Sis.php:3853`):** Firestore-first, but the fallback re-introduces RTDB and can serve stale legacy data; against canonical policy. **MEDIUM.**
3. **🟢 Dead code:** `Exam_engine::get_subject_list()` (zero callers) + `Data_service` `'subjects'` mapping (no caller) — safe to retire. **LOW.**
4. **🟢 Stale docblocks:** Academic/Examination/School_config still describe "Subject_list" as the source though code is Firestore. **LOW (doc).**
5. **Would break if RTDB Subject_list vanished today:** **only LMS** (hard), and **SIS** *only if* Firestore `subjects` is also empty (soft). Everything else is unaffected.

---

## 4. MIGRATION ROADMAP (re-mapped to current reality)

The task's original Phases 1–2 are **already shipped**; Phase 5's target is now **dead**. Remaining work:

### Phase 1 — Exam::get_subjects() → Firestore  ✅ ALREADY DONE
`Exam::get_subjects` (:874) + `_build_subjects_map` (:371) read Firestore only. **No action.** *(Verify: marks_entry.php / template_designer.php call `/exam/get_subjects` → now FS.)*

### Phase 2 — Marks Entry → Firestore  ✅ ALREADY DONE (via Phase 1)
Marks Entry consumes `Exam::get_subjects` (now FS). **No action** beyond verification.

### Phase 3 — Teacher / Mobile subject consumers  ✅ EFFECTIVELY DONE
No live RTDB Subject_list path for teacher/mobile. `Data_service` `'subjects'` mapping is dead. **No action** (dead-mapping removal folded into Phase R).
- Blast radius: none. Risk: none. Verify: grep no caller of `data->get('subjects')`/`->set('subjects'` via Data_service. Rollback: n/a.

### Phase 4 — Remaining legacy consumers  ⬅ THE REAL REMAINING WORK
**4a — LMS (`Lms::get_classes_subjects`, Lms.php:76):** replace `firebase->get(".../Subject_list")` with the Firestore `subjects` collection grouped by `classKey` (same pattern as `Exam::_build_subjects_map`). Preserve output shape `{classNum: [{code,name}]}`.
- **Blast radius:** LMS subject dropdowns (lesson/material/assignment creation). One controller method.
- **Runtime risk:** LOW–MED (hot LMS path). *Fixes the live data-drift bug* — LMS will finally see Firestore subjects.
- **Verification:** LMS dropdowns list the same subjects School_config shows; add a subject in School_config → appears in LMS; grep: no RTDB Subject_list read in Lms.php.
- **Rollback:** pure read swap; `git revert` (no data change).

**4b — SIS fallback (`Sis::get_additional_subjects`, Sis.php:3853):** remove the RTDB fallback; rely on Firestore `subjects` (already first). Fail-closed/empty if Firestore has none.
- **Blast radius:** student-edit "additional subjects" list only.
- **Runtime risk:** LOW (Firestore-first already serves it).
- **Verification:** additional-subjects list unchanged on a healthy tenant; grep: no Subject_list read in Sis.php.
- **Rollback:** `git revert`.

### Phase 5 — Retire `Exam_engine::get_subject_list()`  ⬅ NOW TRIVIAL (dead)
Already orphaned (zero callers after Phase 1). Delete the method (+ its docblock).
- **Blast radius:** none (dead code).
- **Runtime risk:** none. **Verification:** grep `get_subject_list` → zero references before delete. **Rollback:** `git revert`.

### Phase R — Retirement & hygiene (after 4a/4b land + soak)
- Remove `Data_service` `'subjects'` mapping (:30,:71) — dead.
- Fix stale docblocks (Academic:250/255, Examination:16, School_config:23).
- Mark RTDB `Schools/{school}/Subject_list` **inert** (no readers, no writers); optional out-of-band purge after soak.
- **Blast radius:** none (dead/doc). **Rollback:** `git revert`.

---

## 5. RECOMMENDED EXECUTION ORDER
1. **Phase 4a — LMS → Firestore** (highest value: fixes the live data-drift bug; the last active RTDB Subject_list reader).
2. **Phase 4b — SIS fallback removal** (canonical/fail-closed).
3. **Phase 5 — delete dead `get_subject_list()`** (after 4a/4b confirm no new caller).
4. **Phase R — retire Data_service mapping + docblocks + mark RTDB node inert** (after a short soak).

Phases 1–3 require **verification only** (already migrated).

---

## 6. COMMIT BOUNDARIES (per phase, when authorized)
- **Commit A — `fix(lms): read subjects from Firestore subjects collection (retire RTDB Subject_list)`** — `Lms.php` only. Fixes data-drift.
- **Commit B — `refactor(sis): drop RTDB Subject_list fallback in get_additional_subjects (Firestore canonical)`** — `Sis.php` only.
- **Commit C — `chore(exam): remove dead Exam_engine::get_subject_list() (no callers post-FS cutover)`** — `Exam_engine.php` only.
- **Commit D — `chore(subjects): retire dead Data_service subjects mapping + correct stale docblocks`** — `Data_service.php`, `Academic.php`, `Examination.php`, `School_config.php` (comments/dead-map only).
Each independent, single-concern, `git revert`-able; no data migration; no Firestore/RTDB writes.

---

## 7. EXPLICIT ANSWERS TO THE BRIEF
- **Consumer that breaks if RTDB Subject_list disappears today:** **LMS** (`Lms.php:76`) — hard break. **SIS** (`Sis.php:3853`) — soft, only if Firestore `subjects` also empty. *(Exam/Marks-Entry no longer break — already on Firestore.)*
- **Data mismatches RTDB vs Firestore:** RTDB has `pattern_type` (per-class metadata) not in Firestore — but it's **unused** by all subject-list readers. Otherwise 1:1. The real mismatch is **staleness**: RTDB is write-orphaned, so LMS (RTDB) and School_config (Firestore) can diverge — LMS won't see Firestore-added subjects.
- **Hidden coupling to legacy subject structures:** (a) class-key normalisation (`9`/`Nursery`/`LKG`) — already mirrored on the Firestore side; (b) `Data_service` dead `'subjects'`→RTDB mapping; (c) stale docblocks implying RTDB is canonical; (d) `pattern_type` (RTDB-only, unconsumed).

---

*Audit & design only. No code changed; no commits; no pushes; no RTDB/Firestore writes. The three exploratory agents' raw line numbers were reconciled against the current ZenX tree; note one agent inadvertently scanned the Grader clone — its module conclusions agree directionally but its line numbers do not apply to `ank-yug_b1`.*
