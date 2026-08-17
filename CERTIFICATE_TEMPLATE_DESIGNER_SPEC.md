# ZenXii — Certificate Template Designer
## Module specification (v2 — supersedes the 2026-08-17 draft)

**Status:** SPEC — no code written, nothing committed, nothing deployed.
**Date:** 2026-08-17
**Companions:** `DOCUMENT_ENGINE_ARCHITECTURE.md` (storage / numbering / immutability ADR) ·
`SCHOOL_DOCUMENT_ECOSYSTEM_RESEARCH.md` (evidence-graded research, 281 documents)

**Confirmed with product owner:** renderer stays **dompdf 2.0.8**, no new infra ·
v1 covers **Transfer Certificate, Bonafide, Character**.

> **What changed from v1 of this spec.** v1 designed a free drag-and-drop canvas and made every
> document conform to it. That was backwards. Neither v1 document is a canvas document: Bonafide
> and Character are **prose letters**, and the CBSE TC is a **prescribed form** whose layout is not
> ours to rearrange. v2 gives each document the editor its shape actually calls for, and replaces
> compliance-by-validation with **compliance-by-construction**. The canvas survives — scoped to
> where x/y genuinely earns its place.

---

## 1. Scope

### 1.1 In scope

A **template designer and registry**. A school can: pick a certificate → choose a starter template
or a blank one → design it with government policy and compliance enforced → publish it → set one
active per certificate type.

### 1.2 Out of scope (explicit)

**No module's print button is wired.** Fees, SIS/TC, Result and HR consume the active template at
*their* point of print; that integration is deferred and is not part of this build.

Also excluded: issuance, number allocation at issue, PDF archival, QR verification endpoint,
approval workflow on issued documents, DigiLocker, bulk printing. Those belong to the Document
Engine (see the ADR).

### 1.3 The real deliverable

Because the consumers are built later, **the contract is the deliverable, not the UI.** Get §6 and
§7 right and every future print-point integration is mechanical. Get them wrong and we integrate
every module twice.

---

## 2. Verified constraints (read before designing anything)

Each was confirmed by reading source, not assumed.

| # | Constraint | Evidence | Consequence |
|---|---|---|---|
| C1 | **dompdf 2.0.8 has no flexbox and zero CSS grid** | The codebase's own comment in `Pdf_generator::_wrap_html()`: *"Dompdf has LIMITED flexbox and ZERO CSS grid support."* Only `classic.php` (0 flex/grid) is on the dompdf path; the other 5 report-card templates carry 74 flex/grid declarations and are browser-print only | No editor may emit flex/grid |
| C2 | **`Pdf_generator::render()` cannot be reused as-is** | Injects **184 lines** of report-card CSS (`.rc-*`, `.cb-*`, `.mn-*`, `.md-*`, `.el-*`) plus `body{font-size:11px}` into *every* PDF, unconditionally; and hardcodes `setPaper('A4','portrait')` | Needs a **separate clean render path** (§8.1) |
| C3 | **No Indic font coverage** | dompdf ships only DejaVu + core PDF fonts; DejaVu has no Devanagari/Tamil/Telugu. No Indic TTF anywhere in the project; no font-registration mechanism in `Pdf_generator` | Multilingual is **blocked** until a font pipeline exists (§9) |
| C4 | **`isRemoteEnabled: true`** | `Pdf_generator::$defaultOptions` | Server-side fetch of template image URLs ⇒ **SSRF risk**; expiring signed URLs break later renders (§8.3) |
| C5 | **Class-name collisions are a recurring bug class here** | The `.att-grid` incident (a table class colliding with a card-grid utility) | Template CSS must be **namespaced and isolated** (§8.2) |
| C6 | `result/template_designer` is a **marks-scheme editor** (Theory / max-marks rows) | `Result.php:112` | Name collision — do not reuse the name |
| C7 | CKEditor vendored at `tools/bower_components/` but **used nowhere** | grep across views/controllers/assets | Not prior art; choose the editor deliberately |
| C8 | `config/numbering.php` holds **zero document kinds** | All 6 registered kinds are Communication | Register document kinds (§10) |

---

## 3. The core idea: documents have three shapes

The mistake in v1 was treating "certificate" as one thing. It is three, and each wants a different
editor. Forcing all three onto a canvas is what created most of v1's complexity.

| Shape | What it actually is | Editor | v1 documents |
|---|---|---|---|
| **Letter** | Prose with merge fields; flows | Rich-text editor + field picker | Bonafide, Character |
| **Form** | Prescribed labelled fields, fixed order | **Locked layout** + chrome customiser | CBSE Transfer Certificate |
| **Canvas** | Free absolute x/y | Drag-and-drop | Pre-printed stationery overlay |

Evidence this is the right cut, from the research:

- The only prescribed conduct certificate found — **TN Educational Rules Appendix 5-B** — reads:
  *"This is to certify that ____ was a student of this institution from ____ to ____ during the
  period his/her Conduct and Character were ____."* That is a **sentence with blanks**, not a
  layout.
- **Bonafide has no prescribed format at all** (Level C; no statutory basis found) — issued on
  school letterhead in a locally invented format. Pure letter.
- **CBSE Annexure-I** is 22 numbered fields in fixed order with a signature block. A **form**.

Flowing text also disposes of a whole class of bug for free: a long student name or a three-line
reason-for-leaving simply wraps. v1 needed a `shrink | wrap | clip | error` overflow engine to
handle what prose handles natively.

### 3.1 Where the canvas genuinely earns its place

Absolute x/y is the *only* correct model for **pre-printed stationery overlay** — positioning
variable fields onto paper the school already owns, calibrated in millimetres. That is real, it is
in v1, and nothing else solves it. It is also the right model later for award certificates and ID
cards.

The canvas is an **editor for the documents that need it**, not the substrate every other document
is built on.

---

## 4. Compliance by construction, not by validation

**This is the most important design change in v2.**

v1 said: *design freely, then we validate that the 22 mandated fields are present.* That is weak
(present-on-a-canvas ≠ correctly ordered, labelled or legible), it makes us police the user, and
it solves a problem we created by allowing free layout of a prescribed form in the first place.

v2 says: **ship the compliant layout as the artifact.**

- The CBSE TC form ships with all 22 Annexure-I fields, correctly ordered and labelled, **locked**.
- The school customises only the **chrome**: letterhead block, logo, fonts, colours, margins,
  signature names and designations, seal, stationery mode.
- **There is no operation that removes or reorders a mandated field**, so non-compliance is not
  reachable.

### 4.1 Extension is append-only

Schools have real needs the Annexure doesn't cover (APAAR ID, Aadhaar reference, house name). So:

- **Allowed:** append extra rows *after* the mandated set; restyle; translate labels.
- **Forbidden:** remove, reorder or relabel a mandated field.

One narrow validator remains, covering exactly this rule — not a general layout policeman.

### 4.2 Profiles are board- and state-scoped

The research's dominant finding was scope overstatement: CBSE-affiliated schools are ~30,000 of
~1.5 million Indian schools. Applying a CBSE profile to a Kerala state-board school is simply wrong.

```
documentTypes/transfer_certificate
  complianceProfiles: [
    { id:'cbse',       scope:{ board:'CBSE' },              shape:'form',   … },
    { id:'cisce',      scope:{ board:'CISCE' },             shape:'form',   … },
    { id:'generic',    scope:{ fallback:true },             shape:'letter', enforces:[] }
  ]
```

Resolution: school board + state → profile; **no match → `generic`**, which enforces nothing and
says so plainly in the UI. **We never invent a requirement to fill a gap.**

CISCE prescribes **no TC format** — it regulates *content* ("Promoted to Class X" may not be
printed unless promotion criteria are met). So a profile carries either a **field set** (form) or a
**conditional content rule** (letter). The model must express both.

### 4.3 Compliance validates the template, never the issuance

The CBSE form contains a **"dues paid upto"** field, so the template renders it. That grants **zero
gating power**. Rule text permitting a no-dues gate exists (KER Ch. VI r.17(2); TNER r.40–42; CBSE
r.8(vi)) but the courts have gutted it (Delhi HC LPA 393/2014; Kerala HC 2025:KER:69076; Madras HC
DB 22.07.2024; Karnataka HC 2025:KHC:5986), and RTE s.5(3) makes the TC immediate and
non-withholdable at the elementary stage. When issuance is built, any no-dues setting must be
per-state, never default-on, and impossible to enable for classes I–VIII (ADR §9A.2).

### 4.4 Profiles expire

Verification caught authorities superseded within the last year (Code on Wages commenced
21-11-2025; an EPF scheme text repealed weeks before the research; a CBSE bye-law repealed in 2010
still cited; a GST entry quoted from the superseded 2017 original). Every profile carries
`verifiedOn` + a named owner, and goes stale in the UI after a review interval. Every rule renders
**with its authority and evidence level visible**:

> ⛔ **Date of first admission** — CBSE Examination Bye-Laws, Annexure-I · **Level A** · verified 2026-08-16

A Level C custom must never be shown as law.

> **Build note.** Transcribe the Annexure-I field list, labels and order **from the source PDF** at
> implementation time. The research summary is adequate for speccing, not for shipping a
> legally-prescribed form — the official PDF is a scanned image read by OCR.

---

## 5. Preview honesty — solved by construction

The failure mode: a browser preview looks perfect, the PDF comes out broken, and the tool itself
has lied.

v1 solved this with a policing discipline (one serializer, banned properties, a mandatory Proof-PDF
gate). An intermediate idea — *render dompdf on every edit* — is rejected on feasibility: dompdf is
memory-hungry and the Ohio box has OOM history.

**v2 solves it by construction.** None of the three editors *can* emit flex or grid:

- Letter → paragraphs, lists, tables, images
- Form → a layout we author once and test against dompdf
- Canvas → absolute positioning

All four are natively dompdf-safe, so browser and dompdf already agree. Preview is honest for free.

Retained as a cheap backstop: **publishing requires one successful Proof PDF render** through the
real pipeline, and stores its hash. A template dompdf cannot render cannot become active. That is
one render per publish, not per keystroke.

---

## 6. Data contract (the deferred-integration surface)

### 6.1 Per-type typed variables

`Certificates.php` today uses one flat `PLACEHOLDERS` list shared across all types, so it cannot
know whether a placeholder is meaningful for a given certificate. Replace with a typed contract per
type:

```
documentTypes/transfer_certificate
  contractVersion: 3
  dataContract: {
    student.name:             { type:'string', required:true },
    student.admissionNumber:  { type:'string', required:true },
    student.dob:              { type:'date',   required:true, source:'admission_register' },
    attendance.workingDays:   { type:'int',    required:true, computed:'attendance' },
    attendance.daysPresent:   { type:'int',    required:true, computed:'attendance' },
    result.promotionEligible: { type:'enum',   required:true, computed:'result' },
    tc.reasonForLeaving:      { type:'text',   required:true, source:'operator' },
    school.name:              { type:'string', required:true },
    …
  }
```

The field picker offers **only** these. No free-typed `{whatever}`.

`computed:` matters — two Annexure-I fields (working days / days present, and promotion
eligibility) are **derived from the Attendance and Result modules**, not typed by a clerk. The
contract records what the future print point must supply and from where.

### 6.2 Contract evolution rules

- Fields are **append-only**. Never remove or rename in place.
- A rename ships as a new field + an entry in an **alias map**, so v3 templates keep resolving.
- `contractVersion` increments on any addition; templates record the version they were authored
  against.

Without this, adding a field silently breaks every published template — the classic mail-merge bug.

### 6.3 The consumption contract

```php
$tpl  = $documentTemplates->active($schoolId, 'transfer_certificate');
$html = $templateRenderer->render($tpl, $dataBundle);   // single serializer
$pdf  = $documentPdf->render($html, $tpl->page);        // clean path, paper from template
```

Both failure modes **fail closed**:

- **Contract mismatch** (bundle lacks a variable the template uses) → hard error, never render.
- **Unresolved placeholder** → hard error. A document must **never** print with a literal
  `{student_name}` on its face — that is both an embarrassment and a forgery vector.

This deliberately reverses the current module, which substitutes `''` for missing fields
(`'{leaving_date}' => ''`) and prints a blank.

---

## 7. Storage

Firestore per the ADR. Flat top-level collections, `{schoolId}_{entityId}` keys. Collection names go
in `ZenXII_Teacher/.../util/Constants.kt` (`object Firestore`), never string literals.

```
documentTypes/{typeId}                  ← platform-level
platformTemplates/{templateId}          ← starter gallery, CLONED on use
documentTemplates/{schoolId}_TPL0007    ← school's own
```

```
documentTemplates/{schoolId}_TPL0007
{
  schoolId, docType:'transfer_certificate',
  shape: 'letter' | 'form' | 'canvas',
  name, status:'draft'|'published'|'archived',
  version: 3,  lockVersion: 17,          // lockVersion = optimistic concurrency (§8.5)
  isActive: false,                       // exactly one active per (school, docType, variant)
  complianceProfileId: 'cbse',
  contractVersion: 3,
  page: { size:'A4', orientation:'portrait', margins:{…}, stationeryMode:false },
  body: { … },                           // shape-specific model (§3)
  chrome: { logoRef, letterhead, fonts, colours, signatures[], sealRef },
  proofPdfHash: 'sha256:…',
  createdBy, updatedBy, publishedBy, publishedAt
}
```

- Starter templates are **platform assets cloned into the school on first use**, never referenced
  live — a school editing a starter must not mutate every other school's gallery.
- **Images are Cloud Storage refs, never inline data URIs** — a base64 logo would approach
  Firestore's **1 MiB** document ceiling.
- Store the **model**, not rendered HTML. Rendered output is reproducible; issued documents archive
  the PDF and its hash (ADR §4).

---

## 8. Engineering requirements found by stress-testing

### 8.1 A dedicated render path (C2)

Do **not** call `Pdf_generator::render()`. Add a sibling that wraps template HTML in a **minimal**
document — reset, page box, template CSS only — with **no** report-card rules and **no** forced
`body{font-size}`. Paper size and orientation come from `$tpl->page`, not a hardcoded `setPaper`.

### 8.2 CSS isolation (C5)

Every template's CSS is emitted **namespaced under a generated root class** (`.zx-tpl-{id}`), and
all template selectors are prefixed at serialize time. Given the `.att-grid` precedent, this is not
optional. Templates cannot author bare element selectors (`div`, `td`) that escape the namespace.

### 8.3 Image safety (C4)

- Template image references are **Storage refs, not URLs**. The renderer resolves a ref to a local
  path or fetches server-side from **our bucket only**.
- **Whitelist enforced in the renderer**, not the UI. Arbitrary URLs are rejected — otherwise
  `isRemoteEnabled:true` turns every template into an SSRF primitive.
- Never persist expiring signed URLs in a template; they render today and break silently later.

### 8.4 Merge fields must be atomic

In the letter editor, a merge field is an **atomic inline node** (`contenteditable="false"`), not
text. Otherwise a cursor placed mid-token and a keystroke silently corrupt `{student_name}` into
`{studen_name}` — which then fails closed at render (§6.3), but only after the template ships.

### 8.5 Concurrent edits

Two admins editing one template must not silently overwrite each other. `lockVersion` is checked on
save; a stale write returns a conflict and the UI offers reload-or-overwrite. Last-write-wins is
not acceptable for a document that becomes a legal artefact.

### 8.6 Gallery thumbnails

PDF→PNG needs imagick/ghostscript, which may not be installed on the box. v1 therefore uses a
**styled card with template metadata**, not a rendered thumbnail. Do not make the gallery depend on
an unverified binary.

---

## 9. Multilingual — designed, deferred, and honest (C3)

Today a Hindi or Marathi certificate would render as **blank boxes**: dompdf ships only DejaVu +
core fonts, which have no Indic coverage, and there is no font-registration mechanism.

**v1 ships English-only, and says so** rather than offering a language selector that silently
produces empty documents.

The pipeline, when built:
1. Bundle licensed Indic TTFs (e.g. Noto Sans Devanagari / Tamil / Telugu).
2. Register them with dompdf's font cache and expose them as template font choices.
3. Keep `isFontSubsettingEnabled` on — full Indic fonts are large.
4. Store label translations **keyed to the same field set**, so a bilingual document is a language
   variant of one template, not a second template.

Point 4 is the design constraint that matters now: the template model must key labels by field id,
so translation is additive later.

---

## 10. Changes required in existing code

| # | Change | Why |
|---|---|---|
| 1 | New clean render path; paper from template | C2 — 184 lines of report-card CSS + hardcoded A4 |
| 2 | Register document kinds in `config/numbering.php` | C8 — zero document kinds today |
| 3 | Do **not** extend `Certificates.php` | RTDB prototype; replace-alongside confirmed (ADR D6) |
| 4 | Pick a name that does not collide with `result/template_designer` | C6 |
| 5 | Gate with `has_permission()`, not `_require_role()` name lists | Layer-2 gate blocks custom roles |

---

## 11. RBAC

| Capability | Grade |
|---|---|
| View gallery / templates | `view` |
| Create, edit, save draft | `edit` |
| **Publish** | `manage` |
| **Set active** | `manage` |
| Edit compliance profiles | **Platform/super-admin only — never the school** |

A school must not be able to edit its way out of a legal requirement.

---

## 12. Risks

| Risk | Sev | Mitigation |
|---|---|---|
| Editor emits CSS dompdf can't render | High | §5 — no editor can emit flex/grid; publish gated on Proof PDF |
| Reusing `Pdf_generator::render()` injects report-card CSS | High | §8.1 dedicated path |
| Template CSS collides with panel CSS | High | §8.2 namespacing (`.att-grid` precedent) |
| Arbitrary image URL ⇒ SSRF | High | §8.3 renderer-side whitelist |
| Compliance profile applied outside its board/state scope | High | §4.2 scoping + `generic` fallback |
| Level C practice presented as law | High | §4.4 authority + evidence level on every rule |
| Document prints an unresolved `{placeholder}` | High | §6.3 fail closed |
| Annexure field list taken from OCR summary, not source | High | §4.4 build note |
| Contract change breaks published templates | Medium | §6.2 append-only + alias map |
| Merge field corrupted by mid-token editing | Medium | §8.4 atomic nodes |
| Concurrent edits silently overwrite | Medium | §8.5 `lockVersion` |
| Multilingual ships as blank boxes | Medium | §9 English-only in v1, stated |
| Bands absent when receipts/report cards arrive | Low | §13 additive element type |

---

## 13. Deliberately deferred

- **Flow bands** (repeating rows). No v1 document has a repeating collection. Required for **fee
  receipts and report cards** in phase 2; additive to the model — a new element type, not a
  migration. v1 must not foreclose it.
- Multi-page documents and dompdf page-break control (historically weak for tables).
- Multilingual (§9).
- Everything in §1.2.

---

## 14. v1 acceptance

A school administrator can:

1. Open Certificates and pick **Transfer Certificate**, **Bonafide** or **Character**
2. See starter templates for **their board and state**, plus a blank one
3. Edit **Bonafide/Character as prose letters** with merge fields from the typed contract
4. Edit the **CBSE TC as a locked compliant form**, customising chrome and appending extra rows,
   with mandated fields un-removable and un-reorderable
5. See every compliance rule with its **authority and evidence level**
6. Design in **pre-printed stationery mode** on a millimetre canvas with a calibration test print
7. Render a **Proof PDF** through the real pipeline and see exactly what prints
8. Publish (version pinned, immutable) and **set active**

A developer can:

9. Call `active(schoolId, docType)` + `render(tpl, bundle)` — the entire deferred integration
   surface, fail-closed on both contract mismatch and unresolved placeholders.

---

## 15. Status

**Nothing implemented.** No files changed, no rules edited, no indexes declared, no deploy.
Design baseline for review.
