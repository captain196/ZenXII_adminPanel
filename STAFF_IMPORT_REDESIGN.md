# Staff Import Redesign — Standard *and* Flexible

**Goal:** let any school import its staff data regardless of the column names,
order, or which optional sections they include — while keeping one canonical
data model and never silently corrupting records.

**Status:** Proposal (design review). No code changed by this document.

**Scope:** `Staff::import_staff()`, `Staff::download_staff_template()`, the
`import_staff.php` view, and a new schema config. The single-add form
(`new_staff`) and edit form (`edit_staff`) are out of scope except where they
share the canonical schema.

---

## 1. Why the current importer breaks on "other formats"

All references are to `application/controllers/Staff.php`.

| # | Problem | Where | Effect |
|---|---------|-------|--------|
| 1 | Headers matched by **exact string** (`array_combine` then `$rowData['Phone Number']`) | `:526, :542-550` | "Mobile", "Contact No", "Staff Name" etc. read as empty → rows skipped as "missing" |
| 2 | **Hard fail on column count** (`count($headers) !== count($row)`) | `:521-524` | One extra/missing column anywhere → the **whole row is dropped**, counted as an error with **no message** |
| 3 | **Silent default to `ROLE_TEACHER`** when role can't be inferred | `:612` | A blank/unknown Position makes everyone a Teacher — silent data-integrity bug |
| 4 | Position is **required**, no explicit Role column supported | `:562` | Files that express role differently fail every row |
| 5 | **No validation preview** — writes straight to Firestore + Firebase Auth + phone index + salary + leave docs | `:716-785` | Mistakes go live instantly and are hard to undo (Auth users created) |
| 6 | Template instructs **"do NOT reorder or rename headers"** | `download_staff_template()` `:970` | Pushes rigidity; the opposite of flexible |
| 7 | Importer field list and template field list are **two hand-maintained copies** | `:542-550` vs `:848` | They drift apart over time |
| 8 | **No dedupe / upsert** on re-import (single-add path blocks dup staff phones; import does not) | `:763` vs `new_staff :1106` | Re-running a file creates duplicate staff |

**Net:** the importer only accepts *its own* template, in its own order, with
its own names — and fails opaquely otherwise.

---

## 2. Target architecture — a 6-stage, schema-driven pipeline

```
Upload ─▶ [1] Canonical schema ─▶ [2] Header resolution ─▶ [3] Mapping review UI
                                                                    │
        Commit ◀─ [6] Idempotent write ◀─ [5] Dry-run preview ◀─ [4] Transform+validate
```

The **canonical schema** is the spine: the importer, the template generator,
the validation, and the mapping UI all read from it. One source of truth.

### Stage 1 — Canonical field schema  *(new: `application/config/staff_import_schema.php`)*

Each field defined exactly once, with everything the pipeline needs:

```php
$config['staff_import_fields'] = [
    'name' => [
        'label'    => 'Name',
        'required' => true,
        'aliases'  => ['staff name', 'employee name', 'full name', 'teacher name'],
        'transform'=> 'trim',
    ],
    'phone' => [
        'label'    => 'Phone Number',
        'required' => true,
        'aliases'  => ['mobile', 'mobile number', 'contact', 'contact no', 'phone no', 'cell'],
        'transform'=> 'digits_only',
        'validate' => '/^[6-9]\d{9}$/',
        'error'    => 'must be a 10-digit Indian mobile (starts 6-9)',
    ],
    'dob' => [
        'label'    => 'DOB',
        'required' => true,
        'aliases'  => ['date of birth', 'birth date', 'birthday'],
        'transform'=> 'date',         // normalize DD-MM-YYYY | YYYY-MM-DD → canonical
    ],
    'email'    => ['label'=>'Email','required'=>true,'aliases'=>['email address','mail'],'validate'=>'email'],
    'gender'   => ['label'=>'Gender','required'=>true,'aliases'=>['sex'],'enum'=>['Male','Female','Other']],
    'role' => [
        'label'    => 'Role',
        'required' => false,          // resolved via the role chain (see §3)
        'aliases'  => ['designation', 'position', 'job title', 'post', 'role id'],
    ],
    'department' => [
        'label'    => 'Department',
        'required' => false,          // optional now (was required) — see Department/subject rule
        'aliases'  => ['dept', 'faculty', 'section'],
    ],
    'teaching_subjects' => [
        'label'    => 'Teaching Subjects',
        'required' => false,
        'aliases'  => ['subjects', 'subject', 'subjects taught', 'teaching subject'],
        'multi'    => true,           // comma-separated → array
    ],
    // …employment_type, date_of_joining, father_name, blood_group, salary,
    //   allowances, bank*, emergency*, address*, statutory* — all the same shape
];
```

Benefits: adding a field or an alias is a one-line change; the template
generator emits `label`s from here; validation is declarative, not a wall of
hardcoded `if` blocks.

### Stage 2 — Header resolution (auto-map, no UI needed)

```
normalize(header) = lowercase, trim, collapse spaces, strip punctuation
for each uploaded header:
    1. exact match on a field's normalized label or any alias  → map
    2. else fuzzy match (similar_text / Levenshtein ≥ 85%)     → map (tentative)
    3. else                                                    → unmapped
```

Resolves "Staff Name"→`name`, "Mobile No."→`phone`, "D.O.B"→`dob` automatically.
Expected to auto-resolve the large majority of real-world files.

### Stage 3 — Interactive mapping review  *(the real "any format" unlock)*

A screen after upload:

```
We matched 22 of 25 columns.   [ ✓ auto-mapped ]   [ ⚠ needs you ]

  Your column          →   ZenXii field
  ───────────────────      ─────────────────────────────
  "Emp Mobile"         →   [ Phone Number ▾ ]   (fuzzy 88%)
  "Subject Handled"    →   [ Teaching Subjects ▾ ]
  "Joining"            →   [ Date Of Joining ▾ ]
  "House"              →   [ — ignore — ▾ ]      (unmapped)

  Preview (first 5 rows):  ┌── live table reflecting the mapping ──┐
```

- Tentative fuzzy matches are pre-selected but flagged for confirmation.
- **Save the mapping per school** (`schools/{id}.importMappings.staff`) so the
  next import from the same source is one click.
- This is exactly the Flatfile / CSVBox / OneSchema onboarding pattern.

### Stage 4 — Transform + validate + defaults (schema-driven)

For each row, per field: apply `transform` → check `required`/`validate`/`enum`
→ fill schema `default` when optional-and-empty. Collect **all** errors per row
(don't stop at the first). Apply domain rules here, including the
**Department-carries-subject rule** already shipped: for teaching roles with no
explicit subjects, move the Department value into `teaching_subjects` and blank
the real department.

### Stage 5 — Dry-run preview + downloadable error report

**Validate the entire file before writing anything.**

```
   ✅ 18 ready to import
   ⚠️  4 need attention      [ Download error report (.csv) ]
   Row 7  — Phone "12345" invalid (must be 10-digit, 6-9)
   Row 12 — Email missing
```

The error CSV is the original rows + an appended `_errors` column, so the admin
fixes in place and re-uploads. Nothing touches Firestore until they click
**Confirm import**.

### Stage 6 — Idempotent commit

- **Dedupe on phone/email** before insert (reuse the `indexPhones` lookup from
  `new_staff :1101-1119`).
- Re-import = **upsert** (update existing staff by phone/email) instead of
  minting duplicate STA ids.
- Keep the existing per-row `try/catch` + claim-release resilience (`:747-757`).
- Report partial success: `{created, updated, skipped[], errors[]}`.

---

## 3. Role resolution — explicit chain, never silent-Teacher

Replace the `:612` silent default with an ordered chain:

1. **Explicit `Role` / `Role ID` column** → map text to `ROLE_*`
   (reuse `POSITION_ROLE_MAP` + accept raw `ROLE_*` ids).
2. **Position keyword map** (existing `_infer_roles_from_position`).
3. **Otherwise → flag for review.** The row is held back in the preview and
   **not imported** until the admin assigns a role. *(Decision §6.1 — there is
   no auto-fallback role; the old silent `ROLE_TEACHER` default is removed.)*

This keeps the convenient keyword inference but makes the ambiguous case an
**explicit admin action**, never a silent mislabel.

---

## 4. Phased rollout

| Phase | Deliverable | Effort | Why |
|-------|-------------|--------|-----|
| **1** | Schema config (Stage 1) + header aliasing/fuzzy (Stage 2) + stop dropping rows on column mismatch (fix #2) + role chain w/ per-import fallback (§3) + add Role & Teaching Subjects to template | Low–Med | Accepts most real files immediately; kills the two silent-corruption bugs |
| **2** | Dry-run preview + downloadable error report (Stage 5) | Med | Biggest safety/UX win; no more half-imports |
| **3** | Interactive mapping UI + saved per-school mappings (Stage 3) | Med–High | "Any format, truly" |
| **4** | Upsert/dedupe on re-import (Stage 6) | Med | Safe re-runs |

**Recommended first cut:** Phase 1 + a minimal Phase 2 preview. That delivers
"standard + flexible" for the majority of schools and is mostly a refactor of
existing logic into a schema-driven shape — low risk, high coverage.

---

## 5. Compatibility & migration notes

- **Backward compatible:** the canonical schema's `label`s equal today's exact
  headers, so the current template keeps working unchanged.
- **Column-count fix is safe:** tolerate ragged rows by mapping available
  columns and validating per-field, instead of dropping the row wholesale.
- **Already-imported records** (e.g. the 12 test staff with `department` =
  subject and empty `teaching_subjects`) are unaffected by this redesign; a
  separate one-time migration can move those over if desired.
- **No schema change to the `staff` Firestore doc** — this is all ingestion-side.

---

## 6. Resolved decisions  *(agreed 2026-06-12)*

1. **Role fallback → flag for review (safe).** Rows whose role can't be
   resolved are **held back in the preview and NOT imported** until the admin
   assigns a role. No silent default to Teacher. (Updates §3 step 3.)
2. **Mapping scope → auto-map only (Phase 2).** Ship the alias dictionary +
   fuzzy matching; **defer the interactive mapping UI (Stage 3 / Phase 3)**
   until real-world files prove it's needed.
3. **Upsert key → phone only.** A re-imported row is the same staff member iff
   its phone matches an existing staff `indexPhones` entry. (A changed phone
   will create a new record — accepted trade-off for simplicity.)
4. **Saved mappings → per-school only.** Remember each school's confirmed
   mapping for one-click repeat imports; **no prebuilt SIS presets.** (Moot
   while the mapping UI is deferred — revisit with Phase 3.)

### Impact of these decisions on the plan

- **§3 role chain** collapses to: explicit Role column → keyword map →
  *otherwise flag for review*. There is no auto-fallback role.
- **§2 Stage 3** (mapping UI) and **per-school saved mappings** are **deferred**
  — Phase 1 + 2 rely entirely on alias + fuzzy auto-mapping. Headers that don't
  auto-resolve surface in the preview as unmapped, and their rows are flagged.
- **§2 Stage 6** dedupe uses **phone only** (reuse `new_staff :1101-1119`
  `indexPhones` lookup); match → update, no match → create.
- **Revised first cut:** Phase 1 + minimal Phase 2 preview, with
  flag-for-review and phone-only dedupe baked in. Phase 3/4 remain future work.
