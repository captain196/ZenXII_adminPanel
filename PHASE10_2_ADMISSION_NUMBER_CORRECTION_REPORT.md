# Phase 10.2 — Admission Number Architecture Correction

**Date:** 2026-08-13 · **Branch:** `integration/phase2` · **Not committed, not pushed, not staged.**

---

## A. Current (Phase 10.1) Implementation Audit — What Was Wrong

Phase 10.1 generated `YYYY/CLASS/XXX` (e.g. `2026/01/012`) as a **pure string computation** from
`session_year` + `classOrder` + **SRNO**. Three problems with that design, now corrected:

1. **It embedded SRNO.** That made Admission Number a re-encoding of an identifier the student
   already has — SRNO *is* the school's sequential register number, so the two were redundant.
2. **It embedded class.** That tied an admission-event identifier to an academic position the
   student outgrows, and forced a `00` fallback bucket for pre-primary classes that had no
   established code.
3. **It left a live defect on re-admission.** Because `relink()` overwrites `srNo` *after* the
   Admission Number had already been computed from a *different*, freshly-allocated SRNO, a
   re-admitted student's Admission Number could permanently carry an SRNO component that no longer
   matched their actual SRNO. This was disclosed as an open gap in the Phase 10.1 report and is now
   **closed**, not by patching the stale value, but by removing SRNO from the format entirely and
   issuing a genuinely new number.

**Migration impact: NONE.** A read-only production scan (Firebase Admin SDK, zero writes) confirmed:

```
totalStudents: 161   withAdmissionNumber: 0   withAdmissionNumberCamel: 0
admissionNoIndex / admissionNumberIndex / admNoIndex collections: 0 docs each
```

**No production data carries any Admission Number**, and the Phase 10.1 code existed only in the
uncommitted working tree — it never ran against real data. This is therefore a **clean correction,
not a migration**: nothing to preserve, nothing to rewrite, no destructive change, no backfill.

## B. Corrected Architecture

`Admission_no_service.php` — a **new, dedicated identity service**, a deliberate structural sibling
of `Sr_no_service.php` and `Apaar_service.php`. One service per identity domain, exactly as those
two already are relative to each other. It is **not** a merge into either.

## C/D. Format and Why It Is Professionally Appropriate

**`YYYY/TTTTTT`** — e.g. **`2026/7K4P92`**

| Component | Value | Rationale |
|---|---|---|
| `YYYY` | Admission year, server-supplied from the session year | The one component an operator or parent would reasonably expect to recognise on a printed certificate; sortable and human-meaningful |
| `TTTTTT` | 6 CSPRNG chars over a **30-symbol unambiguous alphabet** (`23456789ABCDEFGHJKMNPQRSTVWXYZ` — no `0/O`, `1/I/L`, or `U`) | 30⁶ = **729,000,000** per school-year. Excluding look-alike characters means a number read aloud or copied off a printed certificate cannot be mis-transcribed — a real, practical ERP concern |

**Why random rather than sequential:** a sequential admission number leaks the school's exact intake
count and ordering to anyone holding two certificates, and would need its own counter competing with
SRNO's. SRNO **already is** the school's sequential register number — duplicating that role here is
precisely the redundancy this phase corrects.

**`random_int()` (CSPRNG), not `mt_rand()`** — a predictable admission number on a printed
certificate is a mild forgery aid, and the CSPRNG costs nothing at this volume.

## E. Scope / Uniqueness Decision — SCHOOL-SCOPED

Doc id is `{schoolId}_{year}_{token}` via `Firestore_service::docId()` — **the identical doc-id
prefixing isolation model `srNoIndex` and `apaarIndex` already use.** School A and School B may both
legitimately hold `2026/7K4P92`. No global uniqueness is claimed or needed: an Admission Number is
only ever meaningful within the issuing school. **No second identity system was created** — this
reuses the established ZenX pattern rather than inventing a new one.

*(Note: the doc id uses `_` where the display value uses `/`, because a Firestore document id may
not contain a forward slash. The stored `admissionNo` field carries the canonical display form.)*

## F. Collision / Concurrency Design

```
generate candidate token  →  firestoreCreate (create-if-not-exists)  →  created?
                                                                        YES → claimed, done
                                                                        NO  → collision signal,
                                                                              new candidate (≤8 tries)
```

`firestoreCreate` is the **same atomic collision primitive** `Id_generator`, `Sr_no_service` and
`Apaar_service` already rely on. There is **no read-then-write-if-absent anywhere** — that pattern is
both slower and racy, and is explicitly not used. Two concurrent allocations racing the same token:
exactly one `firestoreCreate` succeeds; the loser retries with a fresh token.

## G/H/I. Lifecycles

| Event | Student ID | SRNO | **Admission Number** | Roll No |
|---|---|---|---|---|
| **First admission** | `STU0001` (new) | `12` (allocated) | **`2026/7K4P92` (new)** | 18 |
| **Promotion** | `STU0001` — unchanged | `12` — unchanged | **`2026/7K4P92` — FROZEN** | may change |
| **Re-admission** | `STU0047` (new) | `12` — **preserved** (relinked) | **`2028/X9M2Q7` — BRAND NEW** | independent |

**Promotion** never touches Admission Number — nothing in the promotion path writes the field
(confirmed by grep; `execute_promotion()` has no reference to it).

**Re-admission** now allocates a new number in `Readmission::confirm()`, deliberately the *opposite*
of SRNO/APAAR: those identify the **person** and are preserved; Admission Number identifies the
**event**, and a re-admission is a new event. It is **not** derived from the preserved SRNO, **not**
from the class, and **not** from the old Admission Number.

## J. Bulk Import Lifecycle

Both modes allocate through the same service. **There is no `Admission Number` column in
`Import_schema.php`** (confirmed — the Phase 9.2 schema added only `SR Number`, `APAAR ID`,
`Roll No`), so a spreadsheet **cannot inject one**. Operator supply is impossible by construction.

**Historical-import year caveat — disclosed, not silently resolved:** in Historical mode the `YYYY`
component is the **current session**, not the student's real original admission year, because the
import file has no canonical field supplying it. Rather than guess, this is left as-is and flagged;
a future enhancement could add an optional `Admission Year` import column. **Not guessed at.**

## K. Identity Matrix

| Identifier | Scope | Generated by | Operator-entered? | Promotion | Re-admission |
|---|---|---|---|---|---|
| **Student ID** | **Global** (all ZenX) | `Id_generator::generate('STU')` | Never | unchanged | **new** |
| **SRNO** | **School** | `Sr_no_service::allocateNext()` | Never (ordinary admission) | unchanged | **preserved** |
| **Roll Number** | **Class + Section** | Operator, free text | Yes | may change | independent |
| **Admission Number** | **School** | `Admission_no_service::allocate()` | **Never — no method accepts one** | **frozen** | **new** |
| **previousStudentId** | — | `Sr_no_service::relink()` | Never | n/a | links old→new |

## L. Admin Panel Impact

Admission Number now displays on: the Admission CRM credentials panel, the direct-admission
credentials box, the student profile (as a distinctly-labelled field alongside SR Number, Roll
Number and APAAR), and the re-admission confirm screen (showing the **new** number beside the
**preserved** SRNO, so the operator can see at a glance which carried forward and which did not).

**No editable Admission Number input exists anywhere** — verified by grep across all admission
views and controllers: no POST/GET parameter named `admission_no`/`admissionNumber`/
`Admission Number` is read by any controller.

## M/N. Parent App / Teacher App

**PARENT APP — NO CODE CHANGE REQUIRED.** **TEACHER APP — NO CODE CHANGE REQUIRED.**

Verified by **direct source inspection**, not inference: both repos' `StudentDoc.kt` were read in
full — neither declares `srNo`, `admissionNumber`, or `admissionNo`, and a repo-wide grep for all
four alias spellings returns zero matches in either. The Parent App's own class docblock states
*"Adding a new camelCase field here is always safe — missing fields deserialise to their type's
default"*, so the new Firestore fields cannot break deserialisation.

## O. Certificates / Documents Impact

**`Certificates.php` needs ZERO changes** — it already reads `$student['Admission Number'] ??
$student['User Id']` (lines 384, 460). By keeping the field name it already expects, new
certificates automatically begin printing the real Admission Number the moment it is populated;
historical PDFs are static and unaffected. **`Student_context.php`** (Student 360) also needs zero
changes — its defensive alias list already includes both names written here.

**Where it should appear, and where it should not:**
- **Should:** student profile, admission confirmation/credentials, certificates, TC, ID card —
  these are the documents where a school's own admission-event reference is expected.
- **Should not (not added):** fee receipts and report cards already carry Student ID + class/section,
  which is what those documents are keyed and reconciled by; adding a fourth identifier there would
  add clutter without a reconciliation purpose. **Not added blindly.**
- **Not verified this pass:** whether the TC/report-card templates render it — flagged, unchanged.

## P. Firestore Rules / Index — RECONCILED AGAINST LIVE, NOT JUST GIT

Per your mid-turn instruction, I corrected my process here. My first pass only checked the *git*
remote; I then **pulled the LIVE DEPLOYED ruleset** from the Firebase project via the Rules REST API
(service-account authenticated, read-only) and diffed it against the local working copy.

| Check | Result |
|---|---|
| Live ruleset deployed | `2026-08-10T20:40:32Z` |
| Local file | 2899 lines vs live 2581 |
| Differing lines | 386 |
| **Collections in LIVE but MISSING from LOCAL** (a deploy would DELETE their rules) | **ZERO** — verified by extracting and comparing every `match /<collection>` block: 168 live, 184 local, `comm -23` empty |
| Live-only *line* differences | Every one is **replaced by a stricter local version** (adding `isSameSchoolWrite()` tenant guards) — hardening, not loss |
| One apparent regression investigated | `transportAttendance` `allow create`: live permits a **driver** arm that local drops. **Investigated rather than assumed** — local carries a dated `F14 (2026-08-12)` comment explaining the driver client-write arm was **deliberately withdrawn** for a real security reason (rules cannot verify an HMAC signature nor resolve a transport entitlement; writes now go through `Transport_driver_api` server-side). **Local is 2 days newer than live and intentionally stricter — not stale, not a loss.** |
| **Conclusion** | **Local is a strict superset/hardening of live. Nothing would be lost by an eventual deploy.** |

**My change to the file is one isolated, additive 9-line block:**
`match /admissionNoIndex/{docId} { allow read, write: if false; }` — placed immediately after the
`srNoIndex`/`apaarIndex` blocks it mirrors. Server-only, identical posture to its two siblings.
**Verified to compile** via `firebase deploy --only firestore:rules --dry-run --project graderadmin`
→ *"rules file firestore.rules compiled successfully / Dry run complete!"* — **dry-run only, nothing
deployed.**

**`firestore.indexes.json` NOT modified** — no new query shape exists; the index is accessed only by
exact doc id.

## Q. Security / Tenant Isolation

- **Cannot be forged:** no method on the service accepts an operator-supplied value — there is
  deliberately no `claim($operatorValue, …)` counterpart to `Sr_no_service::claim()`, because unlike
  SRNO (which has a legitimate historical-import case) an Admission Number has no pre-existing
  real-world counterpart to preserve.
- **Cannot cross tenants:** doc-id prefixing; unit-tested that School A cannot resolve School B's
  number while School B's own lookup still succeeds (proving genuine scoping, not universal failure).
- **School identity server-derived:** re-confirmed by grep — zero `input->post('school…')` reads in
  `Sis.php` or `Readmission.php`.
- **Index is deny-all to clients** (§P), so no client can enumerate or claim numbers.

## R. Performance / Latency Impact

| Metric | Value |
|---|---|
| **READS added** | **0** |
| **WRITES added** | **1** per admission (the atomic index claim) |
| **QUERIES added** | **0** — exact doc-id access only |
| **Transactions/batches** | 0 |
| **Per-row import cost** | +1 write per student |
| **Retry cost** | 1 additional write per collision; expected collision rate far below 0.01% at 30⁶ candidates |
| **N+1 risk** | **None** — no read-before-write loop anywhere; unit-tested that an uncontended allocation succeeds on attempt 1 and writes exactly one document |

This is one extra write, not one extra read-then-write round-trip — the cheapest safe shape given
the known elevated Firestore latency. **Phase 10.1 added 0 I/O but was architecturally wrong; this
adds exactly 1 write to be correct and collision-safe.** That trade is deliberate and disclosed.

## S. Tests

`tests/Unit/AdmissionNoServiceTest.php` — **19 tests, 101 assertions, all passing.**

`tests/Unit/AdmissionNumberComponentsTest.php` — **DELETED.** It tested the rejected
`YYYY/CLASS/SRNO` format; leaving it passing against a design that is no longer the business rule
would have been worse than removing it.

Coverage: canonical format; **regression guard that the rejected `2026/01/012` shape no longer
validates**; ambiguous-character rejection; wrong-length rejection; **structural proof via
reflection that `allocate()` takes no class/SRNO/roll-number argument**; two students with the same
SRNO/class getting different numbers; year component; invalid-year/missing-input rejection; exactly
one write per allocation; 25 sequential allocations all unique; index doc records owner+school;
resolve success/not-found/malformed; two schools holding the same number; cross-school resolve
isolation; service writes only the index, never the student doc.

| Requirement (§17) | Status |
|---|---|
| Generated automatically | **PASS** (unit) |
| No client-supplied value accepted | **PASS** (structural/reflection) |
| Valid format | **PASS** |
| Collision handling | **PASS** (25-allocation uniqueness run) |
| Concurrent generation | **PASS** (deterministic simulation of the atomic-claim path — same honest limitation as every prior concurrency claim in this program; true multi-process racing is not exercisable in a unit test) |
| Same-school uniqueness | **PASS** |
| Cross-school isolation | **PASS** |
| Promotion preserves it | **SOURCE VERIFIED** — nothing in the promotion path writes the field |
| Re-admission generates NEW | **SOURCE VERIFIED** — `Readmission::confirm()` traced; no controller test harness exists in this repo for any controller |
| Re-admission preserves SRNO | **PASS** (pre-existing `SrNoServiceTest`, still passing, unchanged) |
| Bulk import auto-generates | **SOURCE VERIFIED** |
| Historical import can't inject one | **SOURCE VERIFIED** — no such column in `Import_schema` |
| Certificates resolve correctly | **SOURCE VERIFIED** — field name matches the existing reader |
| Parent/Teacher App no regression | **SOURCE VERIFIED** — direct model read + repo-wide grep, both repos |
| **Full regression** | **PASS — 719 tests, 2288 assertions, same 10 pre-existing failures by exact name, zero new** (710 → 719: +19 new, −10 deleted) |
| **Browser UAT** | **ENVIRONMENT BLOCKED** — no browser tool, no authenticated session; no test account created; nothing claimed |

## T. Existing-Data Migration Impact

**NONE.** Zero production students carry any Admission Number field; zero index documents exist
(§A). No migration, no backfill, no destructive change required or performed.

## U. Fees Dependency

Unchanged and carried forward — **no Fees code was written in this phase.** The queue remains: fee
due dates (the root blocker), automatic late-fee/penalty with verified idempotency, parent reminders
(push works; `auto_remind` has no consumer; WhatsApp/SMS/email only queue), and ID-card
replacement. The **pre-existing RTDB-backed `Operations_accounting.php` dependency** is unchanged,
un-extended, and still awaiting your Phase 10 §H decision. **No new RTDB path, mirror, or dual-write
was created anywhere in this phase.**

## V. Remaining Gaps

1. **Historical-import admission year** uses the current session (§J) — disclosed, needs a product
   decision if real historical years matter.
2. **Bulk-import result screen / credentials PDF** still show neither SR Number nor Admission Number
   (pre-existing gap from Phase 10 §A2, not closed here).
3. **SIS student list** has no SR/Admission Number column (pre-existing).
4. **TC / report-card templates** not verified for Admission Number rendering.
5. **Browser UAT** not performed (§S).

## W. Files Changed / Not Changed

**Changed:**
| File | Change |
|---|---|
| `application/libraries/Admission_no_service.php` | **New** — the corrected identity service |
| `application/controllers/Sis.php` | Removed `_computeAdmissionNumber()`; added `_allocateAdmissionNumber()`; narrowed `_claimIdentityAttributes()` back (dropped the now-unused `$className` param); rewired both call sites and both bulk-import branches |
| `application/controllers/Readmission.php` | **NEW Admission Number issued on re-admission**, best-effort, non-blocking; response carries `admissionNo` + warning |
| `application/views/sis/readmission.php` | Shows the new number beside the preserved SRNO |
| `application/views/student_profile.php` | Admission Number + Roll Number as distinctly-labelled fields (from Phase 10.1, retained) |
| `application/views/studentAdmission.php`, `admission_crm/applications.php` | Credentials panels show Admission Number (from Phase 10.1, retained) |
| `firebase-rules/firestore.rules` | +9 lines: one isolated `admissionNoIndex` deny-all block |
| `tests/Unit/AdmissionNoServiceTest.php` | **New** — 19 tests |
| `tests/Unit/AdmissionNumberComponentsTest.php` | **Deleted** — tested the rejected format |

**Not changed:** `Sr_no_service.php`, `Apaar_service.php`, `Id_generator.php`, `Import_schema.php`,
`Certificates.php`, `Student_context.php`, `firestore.indexes.json`, Parent App, Teacher App, any
Fees file, any Transport/HRMS/Campus-Access file.

## X. Git Status

Branch `integration/phase2`, unchanged. No `reset`, `stash`, `checkout`, `rebase`, or `merge` run.
All pre-existing working-tree changes preserved. The 4 files showing as staged
(`Admin_login.php`, `Staff.php`, `deptrole_helper.php`, `rbac_helper.php`) were **already staged
before this entire program began** — none are mine, and I did not touch the index.

**Nothing staged by me. Nothing committed. Nothing pushed. Nothing deployed.**

## Final Status

**READY FOR COMMIT — with two disclosures**, not blockers:

1. **Browser UAT not performed** (environment-blocked) — the three UI surfaces showing Admission
   Number have not been visually verified.
2. **Historical-import year caveat** (§J) — behaviour is defined and disclosed, but may warrant a
   product decision before schools onboard real historical rosters.

The corrected model is internally consistent: Admission Number is system-generated, independent of
class/SRNO/Roll Number, school-scoped, collision-protected, frozen across promotion, and newly
issued on re-admission — with the Phase 10.1 stale-SRNO defect eliminated rather than patched.

**Recommended next phase:** the Fees due-date fix — the single highest-leverage unblock for
automatic late fees and meaningful reminders.