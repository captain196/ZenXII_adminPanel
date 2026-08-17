# ZenXii Document Engine — Architecture Decision Record

**Status:** DRAFT — UNCOMMITTED, NOT DEPLOYED. No code written. Design only.
**Date:** 2026-08-15
**Scope:** Document generation, numbering, issuance, printing, verification, archival
**Surfaces affected:** Admin panel (authority), Teacher app (read), Parent app (read), Cloud Functions, Firestore rules, Storage rules

---

## 0. Decision summary

| # | Decision | Confidence |
|---|---|---|
| D1 | Document records live in **Firestore**, not RTDB | High — see §2 |
| D2 | PDF binaries live in **Cloud Storage**, never in either database | High — hard limit |
| D3 | Numbering extends the existing **`Numbering_service`**, does not re-implement | High |
| D4 | `Numbering_service` needs a **range-allocation** call before bulk printing ships | High — see §5.3 |
| D5 | `gaplessClass` must become **enforced**, not merely declared | High — see §5.4 |
| D6 | The existing RTDB `Certificates` module is **replaced, not migrated in place** | **CONFIRMED by product owner 2026-08-15** — see §9 |
| D7 | Issued documents are **immutable at the rules layer**; only a status transition is writable | High |
| D8 | Verification uses an **HMAC token**, never the raw document number | High — see §7 |

---

## 1. What exists today (verified by source survey, 2026-08-15)

This section is evidence, not assumption. Each row was read directly.

| Asset | Path | State |
|---|---|---|
| Certificates module | `application/controllers/Certificates.php` (692 lines) | **RTDB-backed**, 4 types only |
| PDF library | `application/libraries/Pdf_generator.php` (557 lines) | dompdf; A4 portrait hardcoded; has `download`/`inline`/`save`/`batch_download` (zip) |
| Numbering allocator | `application/libraries/Numbering_service.php` | Firestore claim-doc CAS; **6 Communication kinds only** |
| Numbering registry | `application/config/numbering.php` | `notice, circular, template, trigger, queue, log` — **zero document kinds** |
| CAS primitive | `Firestore_service::nextSchoolCounter()` | one-at-a-time allocation, `maxAttempts` 8 |
| QR helper | `application/helpers/qr_token_helper.php` | HMAC-SHA256 (64-bit truncated) over `{schoolId}\|{studentId}` |
| Report card templates | `application/views/result/templates/` | 6 templates: cbse, classic, elegant, minimal, modern, professional |
| Batch report cards | `application/views/result/batch_report_cards.php` | exists |
| TC print (path A) | `application/views/sis/tc_print.php` + `Sis.php::tc_list()` | separate from Certificates |
| TC print (path B) | `Certificates.php` type `transfer` | **duplicate TC path** |
| Receipts | `fees/receipt.php`, `admission/receipt_pdf.php`, `receipt_voucher.html` | three separate implementations |
| ID cards | `sis/id_card.php`, `student_id_card.php`, `teacher_id_card.php` | three separate implementations |
| Public route precedent | `Admission_public` (`admission/form/…`, `admission/receipt/…`) | unauthenticated routes already exist |
| Document verification endpoint | — | **does not exist** |

### 1.1 Defects in the current certificate module

Read from `Certificates.php` directly:

1. **Wrong datastore.** Paths are `Schools/{school}/{session}/Certificates/…` on RTDB. Student data is read from `Users/Parents/{parent_db_key}/{userId}` using legacy field names (`Name`, `Father Name`, `Admission Number`). `CLAUDE.md` designates these RTDB paths legacy and shrinking.
2. **Number allocation is not atomic.** The code reads the counter, adds 1, writes it back — the inline comment says *"best-effort atomicity"*. Two concurrent issuances produce a **duplicate certificate number**. For a legally-numbered series this is a correctness defect, not a performance one.
3. **One counter for all types.** `bonafide`, `transfer` and `character` all draw from `Counters/certificateNumber`, so the series is `CERT-2026-00001…` regardless of document type. Schools maintain separate registers per document type; this cannot reproduce them.
4. **Number format carries no identity.** `CERT-{year}-{seq}` has no school code, no document-type code, and uses the calendar year rather than the academic session used everywhere else in ZenXii.
5. **No PDF is produced.** `pdfUrl` is written as `''` and never populated. Output is browser `window.print()` only.
6. **No QR, no verification, no hash.** Nothing distinguishes a genuine document from a retyped one.
7. **No approval workflow.** `generate_certificate()` issues immediately on POST.
8. **Only 4 types**, one of which is `custom`.
9. **Layer-2 role-name gate.** Uses `_require_role(self::MANAGE_ROLES, …)` with hardcoded role names — the known pattern that blocks custom roles.

**Conclusion:** this is a prototype. It should not be extended.

---

## 2. D1 — Firestore, not RTDB

Full reasoning and sources are in the session record; summarised here as the decision basis.

| Requirement of a document system | RTDB | Firestore |
|---|---|---|
| Register query: school + session + type + date range, sorted, paginated | ✗ sorts **or** filters on one property | ✓ compound, composite index |
| Read cost of a register page | bills **bytes**; queries "deep by default", return the whole subtree | bills per document returned; paginates |
| Allocate number **and** write document atomically | ✗ transactions atomic only on one subtree | ✓ "atomically read and write data from any part of the database" |
| Deny read of sensitive documents below a granted node | ✗ read rules **cascade**, cannot be revoked deeper | ✓ non-cascading; query fails closed |
| Freeze an issued document, allow one field to transition | awkward | ✓ per-field rule comparison |
| Statutory retention / auto-expiry | ✗ | ✓ TTL policies |
| Backups | daily only | scheduled, up to 14-week retention, + PITR |
| Availability | 99.95%, zonal | 99.999%, multi-region (`nam5`) |

The atomicity row is the decisive one. In RTDB the counter and the document are different subtrees, so a failure between the two writes **burns a number permanently**. Gaps in a statutory document series are audit findings.

The two costs we accept by choosing Firestore:

- **~1 sustained write/sec per document.** The counter document is the contention point during bulk issuance. Addressed by D4 (§5.3).
- **Every register query needs a composite index.** ZenXii already carries index drift (284 live vs 183 declared). This module must ship its indexes declared in `firestore.indexes.json`, deployed **before** the code.

---

## 3. Data architecture — three stores

Neither database stores the PDF. Firestore's document ceiling is **1 MiB**; a report card with an embedded school logo exceeds it.

```
Cloud Storage   schools/{schoolId}/documents/{session}/{docType}/{docId}.pdf
                └─ the rendered binary + its SHA-256, written once, never mutated

Firestore       documents/{schoolId}_{docId}
                └─ the record: number, type, subject, status, hash, audit trail

Firestore       systemCounters/{schoolId}_{kind}
                └─ the allocator pointer (existing Numbering_service storage)
```

The Storage path deliberately matches ZenXii's existing `schools/{schoolId}/...` scheme.

---

## 4. Firestore collection shape

Flat top-level collection, `{schoolId}_{entityId}` key — consistent with the rest of ZenXii. Collection name must be added to `ZenXII_Teacher/.../util/Constants.kt` (`object Firestore`), not written as a string literal.

```
documents/{schoolId}_DOC0000042
{
  schoolId:        "SCH_D94FE8F7AD",     // required — every query scopes on this
  session:         "2026-27",            // required — every query scopes on this too
  docType:         "transfer_certificate",
  docNumber:       "SCH94FE/TC/2627/00042",   // human-readable, printed
  seq:             42,                        // integer for register ordering
  periodScope:     "session",                 // or "financial_year" for money docs

  subjectType:     "student",            // student | staff | class | school
  subjectId:       "STU0187",
  subjectName:     "…",                  // denormalised for register display
  classId:         "…", sectionId: "…",  // denormalised, nullable

  status:          "issued",             // draft|pending_approval|issued|revoked|superseded
  issuedAt:        <timestamp>,
  issuedBy:        "STA0067",
  approvedBy:      "STA0001",            // null when the type needs no approval

  templateId:      "tpl_tc_cbse_v3",
  templateVersion: 3,                    // pin — a reissue must reproduce the original
  fieldValues:     { … },                // the resolved merge data, frozen at issue

  storagePath:     "schools/SCH_D94FE8F7AD/documents/2026-27/transfer_certificate/DOC0000042.pdf",
  contentHash:     "sha256:…",           // of the PDF bytes
  verifyToken:     "…",                  // HMAC, see §7

  supersedes:      null,                 // docId of the original, on reissue
  supersededBy:    null,
  revokedAt:       null, revokedBy: null, revokeReason: null,

  retainUntil:     <timestamp>,          // drives the TTL policy
  createdAt:       <timestamp>
}
```

**Why `templateVersion` is pinned:** a duplicate TC issued in 2029 must reproduce the 2026 document, not re-render it through a template that has since changed. Without version pinning, reissue silently forges a different document.

**Why `fieldValues` is frozen:** the student's class and address will change. The document must show what was true at issue.

---

## 5. Numbering architecture

### 5.1 Reuse, do not rebuild

`Numbering_service` already provides registry-driven formats, period scoping, audit-actor tracking, pad-width overflow warnings, and per-kind enable/disable. Its docblock states the rule directly: *"NEVER generate business numbers locally (no inline counters, no peek()-then-increment, no mobile-side allocation)."* The current `Certificates.php` violates exactly this.

### 5.2 Register document kinds

The registry has zero document kinds today. Add them — one row per document type, e.g.:

```php
'transfer_certificate' => [
    'prefix'       => 'TC',
    'padWidth'     => 5,
    'gaplessClass' => 'STATUTORY',      // new class — see §5.4
    'periodScope'  => 'session',
],
'fee_receipt' => [
    'prefix'       => 'RCP',
    'padWidth'     => 6,
    'gaplessClass' => 'STATUTORY',
    'periodScope'  => 'financial_year', // money follows the FY, not the session
],
'bonafide' => [
    'prefix'       => 'BC',
    'padWidth'     => 5,
    'gaplessClass' => 'OPERATIONAL',
    'periodScope'  => 'session',
],
```

Note the periodScope split: academic documents reset per **session**, financial documents per **Indian financial year**. The service already supports both.

### 5.3 D4 — range allocation is required before bulk printing

`Firestore_service::nextSchoolCounter()` allocates **one** value per call via claim-doc CAS with `maxAttempts` 8. Issuing 800 report cards therefore means 800 sequential round trips against a single contended pointer document, on a datastore whose sustained per-document write ceiling is ~1/sec. This will not complete in a usable time and will exhaust retries under contention.

**Required addition:** `nextSchoolCounterRange(string $kind, int $count)` — one transaction that advances the pointer by `$count` and returns the reserved band `[start, end]`.

This must land **before** any bulk-issuance feature ships. It is the single most likely cause of a production failure in this module.

Consequence to accept: if a batch partially fails, the reserved band is partly unused. That is a *gap*, which is why §5.4 matters — the class of document determines whether a gap is tolerable.

### 5.4 D5 — `gaplessClass` is currently decorative

`gaplessClass` is declared on all six registry kinds, but the string appears exactly once in `Numbering_service.php` (line 208), inside the `describe()` return value. **Nothing enforces it.** The docblock's "retry-then-throw on contention exhaustion (gapless contract)" is documentation of intent, not implemented behaviour.

Before statutory documents depend on it, define and enforce three classes:

| Class | Meaning | On allocation failure |
|---|---|---|
| `STATUTORY` | Register must be gapless and auditable — TC, fee receipt | Throw. Never skip. Void-with-reason is the only way a number leaves the series. |
| `OPERATIONAL` | Human-facing, gaps tolerable — bonafide, ID card | Retry, then skip |
| `INTERNAL` | Machine identifier — queue, log | Skip freely |

A voided `STATUTORY` number must retain a record explaining the void. That is what makes the register defensible in an audit — an unexplained missing number is the finding.

### 5.5 Number format

```
{schoolCode}/{typeCode}/{periodShort}/{seq}
SCH94FE/TC/2627/00042
```

- Carries school, type and period on the printed face — a school clerk can file it without opening the ERP.
- The Firestore key stays `{schoolId}_{docId}` per ZenXii convention; the printed number is a separate field.
- **The printed number is not the verification credential** — it is sequential and trivially enumerable. See §7.

---

## 6. D7 — Immutability

An issued document must become read-only at the **rules** layer, not by convention in PHP. This is what makes the archive trustworthy.

Rules obligations for `documents/{docId}`:

- `create` — only the panel's service identity; `status` must be `draft` or `issued`.
- `update` — permitted **only** for a status transition `issued → revoked` (or `→ superseded`), by a holder of the revoke capability. Every other field must compare equal to `resource.data`.
- `delete` — denied for everyone, always. Documents are revoked, never deleted.
- Read — scoped by `school_id` claim; sensitive types (salary, medical, disciplinary) additionally owner- or capability-gated.

Corresponding Storage rule: `schools/{schoolId}/documents/**` is write-once — no overwrite of an existing object. A reissue writes a **new** `docId`, links `supersedes`, and leaves the original intact.

Reminder from `CLAUDE.md`: `firestore.rules` is a shared file with concurrent editors and teammates deploying from their own machines. Run `node aegis/cli.js rules status` before editing, keep the change inside one `match` block, diff before deploying.

---

## 7. D8 — Verification

`qr_token_helper.php` already provides HMAC-SHA256 signing and QR rendering (`qr_svg_data_uri`), but its token is an **identity** token over `{schoolId}|{studentId}`, minted for attendance scanning and ID cards. It carries no document ID, no issue date and no revocation state. It cannot verify a document.

Extend the same primitive rather than inventing a second one:

- Token payload: `{schoolId}|{docId}|{contentHash-prefix}`, HMAC-signed with the server secret.
- The QR encodes a **verification URL**, e.g. `zenxii.com/verify/{token}` — not the document number.
- Public, unauthenticated route. The precedent exists: `Admission_public` already serves unauthenticated routes. **It must be added to `csrf_exclude_uris`** if it accepts POST, or CodeIgniter returns a blank 403 with no log — the known CSRF trap.
- The verify page discloses the **minimum**: document type, school, subject name, issue date, and status (valid / revoked / superseded). Never the full document body, never PII beyond the name. A verification endpoint that returns the whole TC is a data-leak endpoint.
- Rate-limit it. It is the only unauthenticated surface that touches student records.
- Verify against `contentHash` so an altered PDF fails even with a valid token.

**Current 64-bit truncation:** acceptable for an attendance token, marginal for a document credential. Widen to at least 128 bits for documents.

---

## 8. Printing and retention

**Printing.** `Pdf_generator` hardcodes `setPaper('A4', 'portrait')` in `render()`. Real school offices also use A5 (receipts), legal, pre-printed certificate stationery, thermal receipt rolls, and PVC ID card stock. Paper size, orientation and margins must move into the **template**, not the library. A "print onto pre-printed stationery" mode — which suppresses the letterhead/logo/border and prints only the variable fields at calibrated offsets — is required by any school that has already bought security paper.

`batch_download()` already produces a zip; bulk printing needs a **single merged PDF** instead, so the office prints one job rather than 800.

**Retention.** `retainUntil` drives a Firestore TTL policy.

> **Research outcome (updated 2026-08-16) — retention is almost entirely unlegislated.**
> Across **nine researched domains and 281 documents, exactly four** legally prescribed
> retention periods were found anywhere:
> - **5 years** — ESI Register of Employees (ESI Reg. 32(2))
> - **5 years** from last entry — ESI Accident Book (Reg. 66(ii))
> - **6 years** — FCRA accounting statements (FCRR r.17(7))
> - **1 year** — acknowledgement of a Rajasthan SLFC meeting notice (r.7(2))
>
> Plus one event-based rule (Rajasthan r.11(1)(c): until audit is over and objections settled)
> and CBSE's exam-only rule (Affiliation Bye-Law 14.19(b): question papers, answer sheets and
> internal-assessment records to **end-September of the next academic year** — a floor, not a
> destruction mandate, binding CBSE-affiliated schools only).
>
> **There is no prescribed retention period anywhere for a TC, a fee receipt, a service book,
> an appointment letter, an audited account, a POSH record, a gratuity record, a scholarship
> supporting document, a report card, or an admission register** — i.e. for essentially every
> document this engine will hold.
>
> Consequence: any retention number we ship for those records is **Level C/D (our invention)**
> and must be labelled as such in the UI and the config. Do not present a default retention as
> a legal requirement. Make every value school-configurable with the default clearly marked
> "ZenXii recommendation, not a statutory period."

TTL must never be applied by default to financial or statutory registers. Note also that TTL deletes are **not transactional** and run within ~24h of expiry, so TTL is a storage-hygiene tool, not a compliance guarantee — a document that must be destroyed on a date needs an explicit job.

---

## 9. D6 — Replacement, not in-place migration

The existing module is on the wrong datastore, with a non-atomic allocator, a shared counter, no PDF and no verification. There is no incremental path from it.

Proposed sequencing:

1. Build the Firestore engine alongside the RTDB one. Do not touch `Certificates.php`.
2. Backfill historical issued certificates from RTDB into `documents/` as `status: issued`, `legacyImport: true`, preserving original numbers and issue dates. Do **not** regenerate their PDFs — no PDF ever existed, and rendering one now would fabricate a document that was never issued in that form.
3. Cut the UI over per document type.
4. Retire the RTDB paths only after the register reconciles.

**Also to be consolidated:** TC currently has two independent implementations (`Sis::tc_list` + `sis/tc_print.php`, and `Certificates` type `transfer`); receipts have three; ID cards have three. These are the same document class rendered by different code. They should converge on the engine — but each is a live surface, so convergence is per-type and verified, not a single sweep.

---

## 9A. Verified legal constraints (deep-research pass, 2026-08-15)

14 findings survived adversarial verification, drawn from four authorities: CBSE Examination
Bye-Laws (1995, upd. 2004), CBSE Affiliation Bye-Laws 2018, CISCE ICSE Regulations (Jan 2026
edition), and RTE Act 2009 + the 2024 Amendment Rules. The ones that constrain this design:

### 9A.1 The TC is format-prescribed — it is not a template choice

CBSE Examination Bye-Laws **Annexure-I** fixes a **22-field** TC format, plus pre-printed
**Book No. / Sl. No. / Admission No.**, a signature block of **Class Teacher → "Checked by" →
Principal**, and a **seal**. Bye-law 8(iii) makes an authenticated TC from the last school a
precondition to an Admission Register entry; 8(vii) plus the Annexure footnote add a
**countersignature** branch for TCs originating outside CBSE.

Design consequences (these are requirements, not preferences):
- The TC template is **validated**, not freely designed. Missing a mandated field is a defect.
- Two fields are **computed, not entered**: `total working days` / `days present` from the
  attendance module, and `promotion eligibility` from the result module.
- **`Book No.` + `Sl. No.` are a second, stationery-bound numbering axis** distinct from our
  `docNumber` (§5.5). Schools buy pre-printed TC books. The engine must record the physical
  book/serial actually consumed, or the office register and the ERP diverge.
- `countersignatureRequired` / `countersignedBy` need to be first-class fields.
- CISCE, by contrast, **does not publish a TC format** — it regulates TC *content*: the status
  "Promoted to Class X" may not be printed unless promotion criteria are met (≥33% in five
  subjects incl. English, **and** ≥75% attendance). So the same document type needs
  board-conditional **field validation**, which is the "Universal → board variation" axis.

### 9A.2 The no-dues gate on TC issuance — rule text exists, but the courts have gutted it

> **CORRECTED 2026-08-16 by the second research pass.** The first pass concluded there was *no*
> legal basis for a no-dues gate (it refuted the claim 0-3, but only tested CBSE *bye-law 8*
> as the grounding). The second pass, searching state education rules and case law, found the
> basis **does** exist in rule text. The practical conclusion is unchanged, but the reasoning
> was wrong and is replaced below.

**Rule text permitting a no-dues gate does exist (Level A):**

- **Kerala Education Rules 1959, Ch. VI r.17(2):** "No transfer certificate shall be issued to a
  pupil from whom there are any dues to the school." (also r.18(2))
- **Tamil Nadu Educational Rules r.40 / 41 / 42.**
- **CBSE Examination Bye-Laws Ch. 3 r.8(vi):** a pupil "shall on a payment of all dues, receive
  an authenticated copy of the Transfer certificate."

**But that basis is being read down or overridden almost everywhere, including at the secondary
stage where RTE does not reach:**

- **Delhi HC, LPA 393/2014 (01.08.2014)** — the DSE Act/Rules 1973 contain *no* provision on
  issuing TCs at all; r.167 only permits striking a name off the rolls. Delhi therefore has no
  such rule to invoke.
- **Kerala HC, 2025:KER:69076 (17.09.2025)** — expressly refused to let CBSE r.8(vi) justify
  withholding.
- **Madras HC (DB), 22.07.2024** — a TC "is not a tool for the schools to collect arrear fees."
- **Karnataka HC, WP 31492/2024 (2025:KHC:5986)** — the certificate is the student's property,
  held by the school "as a trustee."
- **RTE s.5(3)** independently makes the TC immediate, non-withholdable and non-blocking at the
  **elementary** stage, with disciplinary liability for a delaying head-teacher.

**Citation discipline:** the Supreme Court's *Indian School, Jodhpur* directions (03.05.2021,
para 117(iv) and (vii)) bar debarring students, withholding results and withholding board-exam
names for fee arrears — but say **nothing about transfer certificates**. Citing that case for
TC-withholding is an extension, not a holding. Do not put it in product copy.

**Therefore (unchanged, better grounded):** a hard no-dues block must **not** be the default and
must be **incapable of being enabled for classes I–VIII**. Ship it as an optional, per-school,
class-scoped *warning* with override-with-reason. Because the rule text is state-specific and
the case law runs against it, the setting must be **per-state configurable with the judicial
position surfaced in the UI** — a school in Delhi has no rule to rely on at all.

Boundaries on the RTE leg: classes I–VIII only (not IX–XII); its literal destination scope is
government/aided schools (private unaided are reached via state RTE rules and DoE circulars);
and the Act sets **no numeric turnaround deadline and no issuance register** — so "track TC
turnaround" is a sound Level D inference, not a legal duty.

**Also corrected:** duplicate-marking on TC reissue is **not** merely our design preference —
it is prescribed. KER r.22 ("Duplicate certificate issued should be clearly marked Duplicate"),
TNER r.44 ("shall clearly bear the mark duplicate **in red ink**. It shall be issued only
once."), CBSE r.8(vi) ("it shall always be so marked"). The engine must mark reissues, and
TNER's once-only rule means the reissue count is itself a gated field.

### 9A.3 Registers are the Level A baseline, and "maintain" ≠ "generate"

CBSE Affiliation Bye-Law **14.19** enumerates the minimum record set: (a) admission and
withdrawal register; (b) exam papers, answer sheets, internal assessment; (c) attendance records;
(d) staff service records incl. appointment/confirmation letters and service books; (e) financial
documents; (f) annual **OASIS and U-DISE e-returns**; (g)/(h) catch-alls.

Two disciplines this imposes on our requirements table:
- The list is a **floor, not a closed set**, and binds **CBSE-affiliated schools only**.
- The legal duty is to **MAINTAIN or ISSUE** a record — *never* to generate it in software.
  Every "therefore the ERP must…" is **Level D** and must not inherit the Level A citation.
  The research explicitly flagged claims arriving with the two welded together.

The **Admission & Withdrawal Register** is the irreducible artefact: CISCE's correction workflow
falls back to it when the Original Admission Form is lost, which makes it the system of record
for identity, and therefore the highest-integrity object in the module.

### 9A.4 Attendance feeds three separate denominators

CBSE Rule 13.1(i): ≥75% of classes held, counted to the 1st of the month preceding the exam
month — **plus a separate 75% laboratory/practical** requirement gated by the Head of
Institution, **plus** 75% on internal-assessment subjects (13.2(i)). Rule 14 allows condonation
of up to 15% (below 60% only on serious medical grounds).

CISCE differs materially: 75% **per year** of the two-year course, condonable to 60% by the Chief
Executive, below 60% only on three enumerated grounds, computed to a fixed **15 February**
cut-off — and **not applied at all** to repeat, absent-result or supplementary candidates.

So the eligibility computation is **board-specific, multi-denominator, and overridable with a
recorded condonation authority and reason**. A single "attendance %" field cannot express it.

### 9A.5 Result documents are distinct artefacts, and doctrine has diverged from practice

CBSE doctrine (Rules 63/64/66) separates Statement of Marks (every candidate who appeared),
Pass Certificate (passers only — improvement and additional-subject candidates get a marks
statement and **never** a fresh certificate), Provisional Certificate (on fee), and Migration
Certificate. **In practice** these are now printed as one combined "Marks Statement cum
Certificate", and the Migration Certificate is DigiLocker-issued, free, via **DADS**.

CISCE issues three expressly **non-interchangeable** ICSE documents (Pass Certificate cum
Statement of Marks / Statement of Marks / Supplementary Statement of Marks), and candidates
marked ABSENT in the Main Examination receive **no result document at all**.

**Requirement:** the master table needs a **doctrine column and a current-practice column,
kept separate.** Modelling only doctrine builds documents the boards no longer issue.

### 9A.6 Corrections are a prescribed workflow, not a free edit

CISCE Chapter VI requires three Principal-attested artefacts (Original Admission Form; the
relevant **Admission & Withdrawal Register** pages; the admission-time TC), routed **only**
through the Head of Institution — "under no circumstances" direct from student or parent. DOB
and spelling corrections only within **three years** of result declaration; **originals must be
surrendered** before a revised certificate issues; an actual *name change* (vs correction)
additionally needs a **court decree plus Gazette notification**.

This validates §4's `supersedes`/`supersededBy` chain and adds requirements: a correction is a
**request workflow with an evidence bundle and an attesting authority**, and issuance of the
replacement is **blocked on surrender of the original**.

### 9A.7 Synonym verdicts — direct schema guidance (resolved 2026-08-16)

The first pass left this unresearched and flagged it as expensive to get wrong. It is now
resolved. **There is no national vocabulary for Indian school certificates**, so several of
these resolve differently by state — a schema that hard-merges them will be wrong somewhere.

| Cluster | Verdict | Basis |
|---|---|---|
| **TC ≡ School Leaving Certificate ≡ Leaving Certificate** | **EQUIVALENT** — one entity + alias/label field | Delhi School Education Rules 1973 r.139 lists them as alternatives; Passports (Amendment) Rules 2025 G.S.R. 156(E) treats "Transfer or school leaving or matriculation certificate" as one clause |
| **TC vs Migration Certificate** | **DISTINCT — never merge** | Different issuer (school vs board), trigger, recipient. CBSE r.66, r.27; r.8(vi) |
| **Character ≡ Conduct Certificate** | **EQUIVALENT in substance** — merge, keep name configurable | The only prescribed form found, TNER Appendix 5-B (r.34), is titled "Conduct Certificate" and certifies "Conduct **and** Character" in one form |
| **Bonafide vs Study Certificate** | **OVERLAPPING — do NOT merge** | Study cert is *retrospective*, year-by-year, and named in law (AP G.O.P. 646 dt. 10.07.1979, para 9, under Art. 371-D). Bonafide is *present-tense* and has **no statutory basis found at all** (Level C) |
| **Student Status Certificate** | **DOES NOT EXIST** — do not create the entity | Negative finding: no Indian act, rule, bye-law or notification uses the name. It is a vendor/imported label for bonafide |
| **Attendance Certificate** | **Field, not document** | Attendance is prescribed as certified *content* inside the TC and the board exam application (CBSE r.12(iv)/13.1(i); KER Form 5; TNER App. 5 field 15(a)). No rule prescribes a standalone form |
| **Statement of Marks ≡ Marksheet ≡ Grade Sheet** | **EQUIVALENT** | CBSE Ch.9 uses the terms interchangeably for one instrument; marks-vs-grades is a property of the exam scheme |
| **Provisional vs Pass Certificate** | **DISTINCT — never merge** | CBSE r.64(ii): a Provisional Certificate is expressly available to a **Compartment** candidate who has *not* passed and will never get a pass certificate for that attempt. Merging makes that case unrepresentable |
| **Pass Certificate vs Marksheet** | **OVERLAPPING** | Doctrinally two (r.63(i) vs r.63(ii)); current CBSE practice prints one "Marksheet cum Certificate" |

**Two traps this exposes:**

1. **"Leaving Certificate" is not always a TC synonym.** Kerala **Form 5A** is a *distinct
   statutory instrument* — a Leaving Certificate for over-age pupils removed from the rolls,
   issuable where a TC may not be (KER r.17(3)). Blind aliasing on the string "leaving
   certificate" would collapse two different legal documents.
2. **My own research brief contained an error.** I asserted that the Kerala Education Rules
   pair a TC with a Conduct Certificate. They do not — KER Ch. VI has no pupil conduct
   certificate at all. **Tamil Nadu** is the state that pairs them (TNER r.34). Corrected here
   so it does not propagate into the schema.

---

## 10. Open questions — require the research pass or a decision from you

**Resolved by the 2026-08-15 research pass:**

- ~~Whether the TC format is prescribed~~ → **Yes, CBSE Annexure-I, 22 fields.** See §9A.1.
- ~~Statutory retention periods per document class~~ → **Only one exists** (exam artefacts,
  end-Sept of the next academic year). Everything else is ours to invent and label. See §8.
- ~~Whether a no-dues gate on TC is lawful~~ → **No verified legal basis; likely unlawful for
  classes I–VIII.** See §9A.2.

**Still open — the research pass did NOT reach these.** Targets 4–10 of the brief returned no
verified findings, so the master document database cannot yet be built. Uncovered domains:

1. **UDISE+ capture formats and PEN** (Permanent Education Number) — what schools must report
   annually, and which of it the ERP must hold.
2. **APAAR / ABC and DigiLocker issuance mechanics** — including the consent flow and the
   school's role. Three claims here were *refuted*; the DigiLocker delivery mechanism is
   explicitly **unverified**.
3. **State-board variation — TC vs SLC vs LC.** Whether these are regional names for one
   instrument or materially different documents. Specifically Maharashtra's LC as **DOB
   evidence** (which would make it a high-integrity legal record, not a courtesy document) and
   Kerala's TC + Conduct Certificate pairing. Also: which states have notified RTE Rule 16A
   equivalents, since the held-back register is Level A only where holding-back is in force.
4. **Staff/HR/payroll statutes** — Payment of Wages, Gratuity, EPF/ESI, Form 16, Shops &
   Establishments, POSH. Nothing verified. §4's `subjectType: "staff"` is currently unsupported
   by any researched requirement.
5. **Child-safety and transport compliance** — POCSO, fire NOC, CMVR/Supreme Court school-bus
   norms, health check-ups, mid-day-meal registers.
6. **Scholarship and welfare** — NSP, income/caste certificates, RTE 25% EWS/DG documents.
7. **Financial** — fee-receipt norms, GST education exemption and what it means for receipt vs
   invoice format, 12A/80G, audited statements for affiliation.
8. **Real-world print stationery** — pre-printed security paper, counterfoil receipt books,
   thermal receipt printers, PVC ID stock. §8's design is currently informed by observation of
   the codebase, not by researched practice.

**Also still open, unchanged:**

- **Which types require approval before issue.** Receipt is almost certainly automatic; TC
  almost certainly is not. To be sourced, not assumed.
- ~~Bonafide vs Study vs Student Status; Character vs Conduct~~ → **RESOLVED, see §9A.7.**
- **Multilingual.** The template must carry language variants keyed to one field set, so a
  bilingual document is not a separate template.

---

## 11. Risk register

| Risk | Severity | Mitigation |
|---|---|---|
| Bulk issuance melts the counter document | **High** | §5.3 range allocation — must ship first |
| Composite indexes not deployed before code | **High** | Declare in `firestore.indexes.json`, deploy indexes early (they take time to build) |
| Verification endpoint over-discloses | **High** | §7 minimum disclosure + rate limit |
| Reissue re-renders through a changed template | **High** | §4 `templateVersion` pin + frozen `fieldValues` |
| `gaplessClass` still unenforced when statutory docs go live | Medium | §5.4 |
| Rules edit collides with another session | Medium | `aegis rules status` before editing |
| Legacy backfill fabricates PDFs that never existed | Medium | §9 step 2 — metadata only |
| `_require_role` name gates block custom roles | Medium | Use `has_permission()` capability gating, not role names |
| **No-dues gate on TC shipped as a hard block** | **High** | §9A.2 — unlawful for classes I–VIII; warning + override only |
| **Level C/D practice presented to schools as legal requirement** | **High** | Separate doctrine / practice / our-recommendation columns; never let a Level D inference inherit a Level A citation |
| TC template missing an Annexure-I mandated field | High | §9A.1 — validate the template against the field list, don't free-design it |
| Physical Book No./Sl. No. diverges from ERP numbering | Medium | §9A.1 — record consumed stationery serials as a second axis |
| Modelling CBSE's three result documents that are now printed as one | Medium | §9A.5 — doctrine vs practice columns |
| Merging bonafide/study/status or character/conduct wrongly in the schema | Medium | Unresearched (§10) — keep them distinct until sourced |

---

## 12. Citation hygiene (carried from the research pass)

These are process rules for whoever writes the final requirements table:

1. **Cite primary text only.** Two secondary reproductions of the bye-laws were found *wrong*
   against the primary (a schoolserv.in paraphrase of 14.19; a cbseportal.com chapter index).
   Never cite a blog copy of a bye-law.
2. **Every citation needs an official URL *and* an archived snapshot.** `cbse.gov.in` and
   `cisce.org` 403 automated fetchers; several key CBSE documents are **scanned images with no
   text layer**; the CBSE Examination Bye-Laws live URL now **404s** and was recovered from the
   Wayback Machine, as was the RTE amendment PDF.
3. **Re-verify before freezing the baseline.** The CISCE regulations cited are the January 2026
   edition governing ICSE 2028; CBSE's attendance circulars are re-issued annually
   (09.10.2024, 04.08.2025); the CBSE Migration "transition period" is open-ended and its end
   could not be established.
4. **Six refuted claims must not leak back in** — listed in §9A.2 and the research output.

---

## 13. Status

**Nothing in this document has been implemented.** No files changed, no rules edited, no indexes declared, no deploy performed. This is a design baseline for review.
