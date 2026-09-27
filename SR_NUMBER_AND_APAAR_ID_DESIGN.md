# SR Number & APAAR ID — Identity Design (Admission Phase 1)

**Status:** Design complete · **not implemented** · not committed
**Parent document:** `STUDENT_ADMISSION_RESEARCH_AND_DESIGN.md` §4.8 (this file is the standalone, implementation-ready extract — and is now the **authoritative** copy, see §13)
**Working simulation:** https://claude.ai/code/artifact/ba357083-51d8-449e-80fe-6a16e97286a8 — **drive the whole flow**: submit the public form as a parent, enrol the applicant, issue and claim the SR number, search and print it. Themed with the real panel tokens from `tools/css/header-inline.css`.
**Screen mockups:** https://claude.ai/code/artifact/c5b5e55f-ff0e-42b7-acb9-fe5100af0028 — six annotated plates, series setup → certificate
**Admission prototype:** https://claude.ai/code/artifact/929f9324-c6fb-4586-8775-5c6f91a0c4dd — *Admin Web → "SR & APAAR"* tab
**Classification:** Defect fix (D3, D3b), not an enhancement — see §1
**Date:** 2026-08-06 · SR digits-only decision same day (§3.1) · restored 2026-08-07 (§13)

---

## 1. Why this is a defect, not a feature

| | Finding | Evidence |
|---|---|---|
| **D3** | **No SR number exists anywhere.** Every certificate and TC prints the internal `STU…` id | `Certificates.php:383` and `:459` read `$student['Admission Number']` with a fallback chain to `User Id`. **No writer for that field exists anywhere in the codebase.** Every TC issued today therefore carries a fabricated register number |
| **D3b** | **No APAAR ID field exists.** Schools are required to hold the government student ID and cannot record it | `grep -i "apaar\|abc_id\|abcId"` over `application/` and both Kotlin repos → **zero matches** (re-verified 2026-08-06). It lives in a parallel spreadsheet today |

The SR number is **the identifier schools actually operate on** — roll calls, fee registers, TCs, and every staff-to-staff conversation. Its absence forces the office back to paper. Both are cheap now and expensive to retrofit once more student data accumulates.

---

## 2. Three identifiers, three different jobs

| | **STU id** | **SR number** | **APAAR ID** |
|---|---|---|---|
| Example | `STU00418` | `0147` — **digits only** | `123456789012` |
| Owner | ZenXII (system) | **The school** | Government of India |
| Scope | Unique per tenant | **Unique per school; each school runs its own series** | Nationally unique, one per child for life |
| Purpose | Primary key — doc ids, Firebase Auth uid, custom claims, app payloads, every foreign key | **The identifier the school operates on** | Statutory / reporting identity, record portability |
| Assigned | Automatically at enrolment | At enrolment — auto / manual / hybrid per school config | Never by us; supplied by the parent |
| Mutable | **Never** | Immutable once set; Principal-only correction, audited | Editable (usually obtained after admission) |
| **Change in this design** | **None whatsoever** | New | New |

> ### The load-bearing rule
> **`STU…` is untouched.** No doc id, no Firebase Auth uid, no custom claim, no `User Id` field, no Kotlin payload, no foreign key changes. SR number and APAAR ID are **additive display and lookup identifiers layered on top**. Reading this design as "replace the student id" is a misreading. The existing identity decision (global STU/STA counter is load-bearing, migration deferred to SaaS scale) **stands unchanged and is not reopened**.

---

## 3. SR number

### 3.1 Format — digits only

> **DECIDED 2026-08-06 (operator): the SR number is numeric. Digits and nothing else.**
> No prefix, no session segment, no slashes, letters, spaces or punctuation. A school register number is a number, and every downstream use — sorting the register, reading it over the phone, reconciling a fee book — is easier when it cannot contain anything else. **Prefix and session-in-number are removed from the design**, not made optional.

| Config key | Type | Default | Notes |
|---|---|---|---|
| `srNo.mode` | `auto` \| `manual` \| `hybrid` | `hybrid` | See §3.2 |
| `srNo.width` | int | `4` | Zero-pad width for **display only** |
| `srNo.startFrom` | int | `1` | Continues an existing paper register |
| `srNo.resetPerSession` | bool | `false` | **Most schools: false.** A lifetime register number, not a yearly one |

Rendered examples: `0147` · `00147` (width 5) · `147` (width 1–3). The school picks the width; the value is always a plain integer.

**Validation:** `^[0-9]{1,9}$` after trimming. Anything else is refused at the field, with:
> *"An SR number is digits only — no letters, slashes or spaces."*

Nine digits is the cap (safely within int range for `srNoSeq`). Leading zeros are padding, never part of the value.

**Zero-padding is a display concern, not an identity.** `147`, `0147` and `00147` are **the same register number** and must collide on save and match each other on search. Changing `width` re-renders existing numbers; it never re-issues, re-claims or rewrites them.

> **Not affected: the previous school's SR number** (`previous_sr_no`, §5.1). That belongs to another school and may legitimately read `1123/GR` or `DPS-88`. It stays free text. Only **our** SR number is digits-only.

### 3.2 Allocation modes

This is the part that matters — a school migrating from a paper register cannot accept a number we invent.

- **`auto`** — next in series allocated at enrolment; the operator never types it.
- **`manual`** — the operator types the number from their physical register. Nothing auto-allocated. Required where the book is the source of truth.
- **`hybrid` (default)** — next-in-series pre-filled and editable. An override is recorded with the operator's name in `auditLogs`, and the counter advances past the entered number so the series never re-issues it.

### 3.3 Uniqueness — atomic claim, not read-then-write

A claim document `srNoIndex/{schoolId}_{srNoNorm}` is created with **create-if-not-exists** — the same primitive already used by `indexPhones` and `Accounting_idempotency`.

`srNoNorm` is the value with **leading zeros stripped** (`0147` → `147`), so a padding difference can never open a second claim on the same register number. One operator typing `147` and another typing `0147` are competing for the same document, which is exactly right.

Two operators claiming simultaneously: one wins; the other gets
> *"SR number 0147 is already used by Neel Kulkarni (STU00418)."*

No silent duplicate. **Never** a `get()`-then-`set()`.

### 3.4 Immutability with a correction path

Once set, `srNo` is not editable by Admin or Front Office. A Principal-level `correct_sr_number` action releases the old claim and takes the new one **in a single transaction**, writes both values to `auditLogs`, and deletes no history.

Register-number corrections happen in real schools; pretending they don't is how you get staff editing Firestore by hand.

**Gaps are allowed; reuse is not.** A gap (failed enrolment, a number reserved on paper) is a *warning* in the integrity verifier. A duplicate is always an *error*.

### 3.5 Where the SR number must appear

It is a **lookup key**, not a certificate field.

| Surface | Requirement |
|---|---|
| Student search | Exact-match search by SR number — **not** a prefix token |
| Applications grid | SR column for enrolled rows |
| Student list / profile / edit | Next to the name, everywhere the STU id appears today |
| ID card | Printed — schools verify identity by it |
| Transfer certificate | Maps to `Admission Number`, so `Certificates.php` prints the real number with **zero change to that file** |
| Fee receipt & fee counter | Schools reconcile fee registers by SR number |
| Student import / export CSV | Column on both sides |
| Parent App profile | Shown — parents recognise this number, not `STU00418` |
| Teacher App student list | Shown — it is what a teacher is told over the phone |

> **Search implementation note.** SR numbers must **not** go into `searchTokens`. That field holds prefix tokens from 2 up to `MAX_PREFIX_LENGTH = 12`, capped at 40 tokens per doc (`admission_search_helper.php:60-61, 70-80`). Feeding a short numeric series into it produces enormous collision sets — a search for `01` would match half the school. SR search uses a dedicated **`srNoNorm` equality field** with its own index, the same shape as `phone_norm` (`Admission_public.php:373, 452`).

---

## 4. APAAR ID

APAAR (Automated Permanent Academic Account Registry) is the government's 12-digit "One Nation, One Student ID" under NEP 2020 — the same identifier as the ABC ID, issued through the APAAR/ABC platform and accessed via DigiLocker. It applies from Class 1 upward.

### **We store it. We never generate, verify or transmit it.**

- **Validation:** exactly 12 digits after stripping spaces and hyphens. **No offline checksum is assumed** — the number is verifiable only against the government portal, which is out of scope. **Do not invent a check-digit rule.**
- **Optional at every stage.** A Nursery applicant will not have one; blocking on it would be wrong.
- **Collectible later.** Editable on the student profile after enrolment. Most parents obtain it after admission — if it were only capturable on the admission form, it would be permanently blank for most students.
- **Unique within the school.** A duplicate is a typo ~100% of the time. Enforced by the same claim primitive: `apaarIndex/{schoolId}_{apaarId}`. **National uniqueness is not ours to check and must not be claimed.**
- **Not an auth key, not a lookup key, not a tenant key.** Never used to identify a session, resolve a school, or fetch a record. A stored attribute only.

### 4.1 Privacy treatment (DPDP)

APAAR derives from an Aadhaar-linked identity, so it is handled as sensitive PII.

| Rule | Detail |
|---|---|
| **Consent** | The existing public-form consent gate (`Admission_public.php:326-331`, stored as `consent_given_at` at `:487`) must be **amended to name APAAR explicitly** before the field goes live. Consent already blocks submission; the copy must match what is collected |
| Masking | Displayed `XXXX XXXX 9012` by default. Full value on explicit click, and **that reveal is audited** |
| Role gating | Full value: Admin, Principal, School Super Admin only. Front Office sees masked |
| **Teacher App** | **Never sent.** Not in any payload, not in any listing. A teacher has no reason to hold a child's government ID |
| Parent App | Shown to the parent for their own child — they own it — masked by default with a reveal |
| Public surfaces | Never on the acknowledgement receipt, fee receipt, admission poster, offer letter, or any SMS |
| Export | Admin CSV export only, and that export is audited |
| Generation | We never generate an APAAR ID or drive the parental-consent flow that government process requires |

---

## 5. The admission-form change — two different SR fields, do not conflate

**This is the single easiest thing to get wrong.** At application time the school's own register number **does not exist yet**, so the form cannot ask for it. What a transfer-in applicant *does* have is the **previous school's** SR number, printed on their TC.

### 5.1 Public admission form — `views/admission/public_form.php`

| Field | Placement | Spec |
|---|---|---|
| **`previous_sr_no`** | **Previous schooling**, after `previous_marks` (`public_form.php:437`) | Optional, ≤ 30 chars, free text. Informational only — never allocated, never indexed, never confused with ours |
| **`apaar_id`** | **Student details**, after `nationality` (`public_form.php:393`) | Optional, 12 digits, `inputmode="numeric"`, `maxlength="14"` (accepts spaces/hyphens, normalised on save) |

**The school's own SR number is not on this form.**

Copy deck:
- *APAAR help* — "The 12-digit APAAR / ABC ID from your child's school records or DigiLocker. Leave blank if you don't have it — you can add it later."
- *APAAR error* — "An APAAR ID is 12 digits. Check the number and try again."
- *Previous SR help* — "The register number from your child's previous school, printed on their transfer certificate. Optional."

### 5.2 Admin application form — `Sis::save_application` (`Sis.php:6247`)

Same two fields, same meaning, for a walk-in enquiry taken at the counter. **Still no school SR number** — this is an application, not an enrolment. The APAAR duplicate check runs on save so the operator can fix a typo while the parent is still at the desk.

### 5.3 Enrolment panel — `Sis::enroll_student` (`Sis.php:6957`)

The only place the school's own SR number is issued.

| Field | Behaviour |
|---|---|
| **`sr_no`** | Driven by `srNo.mode`. `hybrid`: pre-filled with next-in-series, editable. `manual`: empty and required. `auto`: read-only. Claimed atomically (§3.3) |
| **`apaar_id`** | Carried forward from the application, editable, still optional |

### 5.4 Student profile / edit

Both remain reachable forever — `sr_no` (Principal-only correction) and `apaar_id` (Admin-editable) — because in real schools both arrive late: a parent brings the APAAR slip in October, and a register number gets corrected after an audit.

---

## 6. Data & platform

### 6.1 New collections (both server-only, deny-all, `schoolId`-prefixed doc ids)

```
srNoIndex/{schoolId}_{srNoNorm}     → { studentId, srNo, claimedAt, claimedBy }
apaarIndex/{schoolId}_{apaarId}     → { studentId, claimedAt, claimedBy }
```

### 6.2 Additive fields

**`students`**

| Field | Type | Notes |
|---|---|---|
| `srNo` | string | The number as displayed, zero-padded to `srNo.width` — e.g. `0147`. Digits only. Immutable after set |
| `srNoNorm` | string | Leading zeros stripped — `147`. The exact-match search key **and** the claim key. Makes `147` ≡ `0147` ≡ `00147` |
| `srNoSeq` | int | `147`. Numeric value — drives the next-in-series suggestion, sorting, and gap detection |
| `srNoSource` | string | `auto` \| `manual` \| `imported` — needed to explain gaps and overrides during audit |
| `Admission Number` | string | **Dual-emit of `srNo`** (matching the HR snake+camel convention). Makes `Certificates.php:383` and `:459` print the real register number with **zero change to that file** |
| `apaarId` | string | 12 digits, optional, mutable, masked in UI |

**`crmApplications`** gains `previous_sr_no` (informational, never indexed) and `apaar_id` (carried to the student at enrolment).

### 6.3 Indexes — `firebase-rules/firestore.indexes.json`

```jsonc
{"collectionGroup":"students","queryScope":"COLLECTION","fields":[
  {"fieldPath":"schoolId","order":"ASCENDING"},
  {"fieldPath":"srNoNorm","order":"ASCENDING"}]}
```

That single index backs SR-number search. **No index on `apaarId`** — it is never queried, only claimed by deterministic doc id, and indexing a government identifier is an unnecessary exposure. `srNoIndex` and `apaarIndex` need no index at all (deterministic doc-id fetches only).

### 6.4 Security rules — `firebase-rules/firestore.rules`

```
// Identity claim indexes. srNoIndex guarantees one SR number per student
// per school; apaarIndex guarantees one APAAR ID per student per school.
// apaarIndex in particular holds a government identifier as part of its
// document id, so it is server-only with no client read path.
match /srNoIndex/{docId}   { allow read, write: if false; }
match /apaarIndex/{docId}  { allow read, write: if false; }
```

### 6.5 Tenant scoping

Both claim collections are **doc-id-prefixed with `schoolId`**, so a claim in school A can never collide with or be read from school B. Every read and write goes through the existing school-scoped helpers. Per the tenant-isolation hard rule, no cross-school path exists by construction.

### 6.6 Read/write budget

| Operation | Today | Added | Total |
|---|---|---|---|
| Enrolment | ~12 reads + ~10 writes | +1 counter write, +1 SR claim write, +1 APAAR claim write (only if supplied) | ~28 |
| Search by SR number | n/a | 1 indexed equality query | 1 |

No N+1, no collection scan, no unbounded fan-out.

---

## 7. Backfill — import-first, never invent

> **The first draft proposed assigning numbers sequentially by admission date. That is wrong.** Every school already has a register with numbers in it; inventing a parallel series puts ZenXII permanently out of step with the school's own book — the exact problem this feature exists to solve.

1. **Step 1 — Import (primary path).** The school exports or types their existing register into a CSV: `User Id | Student Name | Class | SR Number`. The tool matches on `User Id` first, then name+class with a **confirmation prompt for each fuzzy match**. Rejects duplicates and malformed values before writing anything. This is the path expected for essentially every live tenant.
2. **Step 2 — Report the gap.** Students with no imported SR number are listed for the school to decide on. **The tool does not guess.**
3. **Step 3 — Auto-fill (opt-in, explicit).** Only if the school explicitly asks, remaining students are assigned from `srNo.startFrom` in admission-date order, `srNoSource = auto`. **Never the default.**

Guarded by a per-school marker. **Dry-run mandatory** — prints the full proposed mapping, every unmatched row and every collision, and writes nothing. Idempotent: re-running skips students that already have an `srNo`.

**APAAR backfill — none.** There is no source to backfill from. Schools enter APAAR IDs as they collect them. The verifier reports coverage (*"n of m students have an APAAR ID"*) so a school can see progress; it never invents a value.

---

## 8. Cross-module impact verification

Per the mandatory cross-module rule, every inbound/outbound dependency was checked, not just the modified module.

| Module / surface | Verdict |
|---|---|
| **Certificates / TC** | **Affected — positively, with no file change.** `Admission Number` dual-emit makes `Certificates.php:383, :459` print the real register number instead of the STU id fallback |
| **Fees** | **Affected — display only.** SR number on the receipt and at the fee counter. **No change to demand generation, `feeDemands`, or any financial-lifecycle path.** Sits clear of the fee-demand hold |
| **SIS student list / search** | **Affected.** New `srNoNorm` equality search path; `searchTokens` deliberately untouched (§3.5) |
| **Admission CRM** | **Affected.** Two new optional fields on `crmApplications`, carried forward at enrolment |
| **Promotion / session rollover** | **Not affected.** `srNo` is lifetime by default (`resetPerSession:false`); promotion neither reads nor writes it |
| **Accounting** | **Not affected.** No journal, no ledger, no collection touched |
| **Transport / Hostel / Library / Exam** | **Not affected.** No shared collection or helper |
| **RBAC** | **Affected — 1 new capability.** `correct_sr_number`, Principal-level. APAAR reveal gated to Admin/Principal/SSA |
| **Parent App** (`SchoolSyncParent`) | **Affected — Phase 1 field.** `srNo` + `apaarId` on `StudentDoc.kt` and the profile: SR plain, APAAR masked with reveal. `StudentDoc.admissionDate` and `ProfileScreen.kt` existing rows untouched — new rows sit alongside |
| **Teacher App** (`SchoolSyncTeacher`) | **Affected — SR only.** `srNo` on the student row. **`apaarId` absent by construction** — never added to any payload or model |
| **Firestore rules / indexes** | **Affected.** 1 new index, 2 new deny-all matches (§6.3, §6.4) |
| **Cron / scheduled jobs** | **Not affected** |
| **RTDB** | **Not touched.** Firestore only, all three surfaces — no fallback, mirror or bridge |

---

## 9. Correctness

### 9.1 Invariants (new 13–15)

13. Every enrolled student has exactly one `srNo`, and exactly one `srNoIndex` doc points back to that student.
14. No two students in a school share an `srNoNorm`. No two share a non-empty `apaarId`.
15. `Admission Number` always equals `srNo` when `srNo` is set. Divergence is an integrity error.

### 9.2 Edge cases

| Case | Behaviour |
|---|---|
| Two operators claim the same SR simultaneously | One wins; the other gets a named-student error. Create-if-not-exists, no read-then-write |
| Operator overrides the suggested number in hybrid | Accepted, audited with operator name; counter advances past it |
| Operator types letters, a slash or a space | Refused at the field — *"An SR number is digits only — no letters, slashes or spaces."* Nothing is submitted |
| Operator types `147` where `0147` already exists | Same register number. The claim is refused with the other child's name — padding never opens a second claim |
| `srNo.width` changed after numbers were issued | Display re-renders (`147` → `00147`); values, claims and `srNoSeq` are untouched. No number is re-issued |
| Enrolment fails after the SR claim is written | Claim released in the same failure path; a leaked claim shows in the verifier as a gap-with-no-student |
| APAAR entered with spaces/hyphens | Normalised to 12 digits before validation and claim |
| APAAR duplicate within school | Blocked with the other child's name — "check for a typing mistake" |
| APAAR left blank | Always allowed, at every stage, forever |
| Student deleted / withdrawn | Claims released; the SR number is **not** re-issued (gap, not reuse) |
| Correction to an already-claimed number | Principal-only; old claim released and new one taken in one transaction |

### 9.3 Test plan (abbreviated)

- Concurrency: 2 parallel enrolments claiming the same SR → exactly one succeeds.
- Tenant: school A cannot read, write or collide with school B's claim for the same digits.
- Certificates: a TC for a student with `srNo` prints the register number, not `STU…`, with no edit to `Certificates.php`.
- Search: exact SR match returns 1 row; a 2-char fragment returns none (proves no `searchTokens` pollution); searching `147` finds the student stored as `0147`.
- Digits-only: every non-digit input (`SR/147`, `147 A`, `१४७`, `147.0`, empty) is refused at the field and never reaches Firestore.
- Teacher App payload assertion: `apaarId` **absent** from every response.
- Backfill dry-run: writes nothing; reports every unmatched row and collision.

---

## 10. Delivery

| Item | Estimate |
|---|---|
| Admin panel (config, enrolment panel, forms, search, profile, claims, verifier, import tool) | ~+260 LoC |
| Parent App + Teacher App | ~+40 LoC combined |
| Firestore rules + indexes | 2 matches, 1 index |

Ships inside **Admission Phase 1 — Seats & Integrity**, which is independently shippable.

**All 8 release gates apply**, including gate 8 (performance): +3 writes per enrolment, 1 indexed equality query for search, no N+1, no batch-limit exposure.

---

## 11. Open decisions for the operator

1. ~~Default `srNo.includeSession`~~ — **CLOSED 2026-08-06.** The SR number is digits only; prefix and session segment are removed from the design entirely (§3.1). The remaining format choice is `width`, and its default is `4`.
2. **Does the public form ask for APAAR at all**, or is it collected only after enrolment? Making it public requires the consent-copy amendment (§4.1) to land **first**.
3. **`correct_sr_number` at Principal level** — confirm the role, given the current RBAC label-allowlist gap.
4. **Backfill timing** — the import tool is only useful once a school hands over its register CSV. Sequence against onboarding.

---

## 12. Status & next step

- Design: **complete** (this document).
- Simulation: **complete** — the full flow is driveable end to end (public form → application → enrolment → claim → search → certificate), with the digit rule, padding-collision and duplicate-claim behaviour all live.
- Mockups: **complete** — six annotated screens.
- Code: **not started.** No implementation is authorized by this document.
- Commit: **nothing committed.** See §13 — this file is untracked and therefore at risk.

---

## 13. Recovery note — 2026-08-07

This file and the `STUDENT_ADMISSION_RESEARCH_AND_DESIGN.md` §4.8 section were **lost from the working tree** when the repository moved from `integration/phase1` to `integration/phase2` on 2026-08-06 (23:49). Both were uncommitted:

- `SR_NUMBER_AND_APAAR_ID_DESIGN.md` was untracked and was swept out. **Rebuilt in full from the authoring session** — this document is complete and authoritative.
- `STUDENT_ADMISSION_RESEARCH_AND_DESIGN.md` reverted to its 924-line pre-SR/APAAR state. `git log --all -S"APAAR"` returns nothing, confirming §4.8 was **never committed and is not recoverable from git**. The stashed copy (`stash@{0}^3`) is also the 924-line version. §4.8 has been re-inserted from the authoring session as a pointer to this file; a handful of downstream cross-references in that document (its §5.2 field rows, §5.4 index entry, §5.7 backfill rewrite, §8.4 invariants 13–15, §9 Phase-1 LoC line) were **not** restored, because §6, §7 and §9 of this file supersede them.

**Action:** commit this file. Until it is committed it will not survive the next branch operation.
