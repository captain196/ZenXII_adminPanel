# ZenXii Certificate Designer — Blueprint

**Status:** DRAFT — not locked. No code written, nothing committed, nothing deployed.
drafted_against essay v1 | constraints_honored [CON-RENDERER(mod), CON-V1_SCOPE,
CON-NO_PRINT_IMPL, CON-NO_DEPLOY, CON-COMPLIANCE, CON-FAIL_CLOSED, CON-MULTILINGUAL,
CON-CANVAS_FULL]

Sections: S1–S14 ✓ (S8–S14 drafted v1)

---

## S1 — Executive Summary

ZenXii Certificate Designer is a **single full-canvas design tool** with a **compliance layer**,
letting a school design any certificate exactly as it wants while making it structurally unable to
drop a legally mandated field.

The product bet: every school ERP ships fixed certificate templates, and every school hates them —
because an Indian school's certificate carries its identity (letterhead, seal, signature block,
language) and its legal obligations at the same time. Existing tools force a choice between
*editable* and *compliant*. This one refuses the trade.

**Three decisions define the build.**

1. **One canvas, not three editors.** A prose letter is a text box; a prescribed form is a table.
   Once text boxes hold rich text and auto-grow, both collapse into one editor. One editor to
   build, learn, maintain, and serialize.
2. **Compliance by required objects, presence-only.** A profile declares required content keys.
   You may move, resize, restyle, re-font and translate them freely; you may not delete one, and
   publish blocks on an unbound key. Total design freedom, guaranteed content.
3. **mPDF, not dompdf, for this engine.** dompdf has no complex-text shaping and renders Devanagari
   matras in the wrong order — *wrong words on a legal document*. mPDF ships an Indic shaper
   covering all nine target languages, is pure PHP, and needs no new infrastructure.

**Scope discipline.** This build produces the designer and the template registry. It does **not**
wire any module's print button — Fees, SIS/TC, Result and HR consume the active template later.
Because the consumers come later, the **contract is the deliverable** (S7), not the UI.

**v1 covers** Transfer Certificate, Bonafide, Character — compliance-heavy first, so the hardest
case proves the model.

**Principal risk** is not technical. It is that only **one** compliance profile (CBSE TC) rests on
verified primary sources; Kerala and Tamil Nadu field lists were never retrieved, and Maharashtra,
Karnataka and UP have no verified authority at all. The architecture handles this honestly via a
`generic` profile that enforces nothing and says so — but the *product claim* must not outrun the
evidence.

---

## S2 — Vision / Mission

**Mission.** Let an Indian school produce every document it is required to issue — correctly,
in its own language, in its own visual identity, on the paper it already owns.

**Vision.** The document layer becomes ZenXii's most defensible surface. Feature parity in
attendance or fees is a matter of engineering time; a maintained, cited, board-and-state-scoped
compliance corpus is a research asset competitors cannot clone by reading a screenshot.

**Principles**

| # | Principle | Consequence |
|---|---|---|
| P1 | Freedom in form, discipline in content | Presence-only compliance (S5) |
| P2 | Never present custom as law | Every rule renders authority + evidence level |
| P3 | Fail closed | Unresolved placeholder = hard error, never a blank |
| P4 | The school owns its identity | Letterhead, seal, signature, language are theirs |
| P5 | Honest about what we do not know | `generic` profile enforces nothing, and says so |
| P6 | Print onto what schools already own | Pre-printed stationery is a first-class mode |

**Non-goals.** Not a general-purpose design tool. Not a DTP replacement. Not a document *issuance*
system (that is the Document Engine). Not a board-submission portal.

---

## S5 — Feature Catalog

Grouped by capability. **[v1]** ships now · **[v2]** deferred with intent.

### 5.1 Document type selection
- **[v1]** Type picker: Transfer Certificate · Bonafide · Character
- **[v1]** Each tile shows the resolved compliance profile, active template, last edited
- **[v1]** Per-school type enablement (a school without hostels sees no hostel documents)
- **[v2]** Full type catalogue from the 281-document research corpus

### 5.2 Template gallery
- **[v1]** Platform starter templates, **cloned** into the school on use — never referenced live
- **[v1]** School's own templates: draft / published / active
- **[v1]** Blank canvas
- **[v1]** Duplicate, rename, archive
- **[v1]** Starters filtered by the school's board + state
- **[v2]** Cross-school template sharing within a trust/chain

### 5.3 The canvas — full design control
- **[v1]** Objects: **text box** (rich text, merge-field chips), **image**, **shape** (line/box/
  divider), **table**, **QR/barcode placeholder**, **page-number**
- **[v1]** Drag, resize, **snap guides**, **z-order**, **undo/redo**
- **[v1]** **Rulers, zoom/pan, grid, precise mm coordinate entry**
- **[v1]** Page setup: A4 / A5 / Legal / custom (mm), portrait or landscape, margins
- **[v1]** **Reusable blocks** — save a letterhead, signature block or seal once, reuse across every
  certificate type; edits propagate to templates that reference the block
- **[v1]** Per-object lock (position lock, distinct from compliance lock)
- **[v1]** **Multi-select + align/distribute** *(restored by operator — without them every element
  of a reusable letterhead block is positioned one at a time)*
- **[v2]** Layers panel, grouping
- **[v2]** Rotation, opacity, gradients, multi-page documents

### 5.4 Text and data binding
- **[v1]** Rich text inside a text box: bold, italic, underline, size, colour, alignment, lists
- **[v1]** **Merge fields as atomic chips** — a cursor placed mid-token cannot corrupt them
- **[v1]** Field picker offers **only** the type's declared contract (S7); no free-typed tokens
- **[v1]** **Auto-grow text boxes with push-down anchoring** (see S6.4 — the hardest mechanic here)
- **[v1]** Live sample data toggle: see the layout with a real student's values
- **[v2]** Conditional sections (show a block only when a field is non-empty)
- **[v2]** Computed expressions in-template

### 5.5 Compliance layer
- **[v1]** Profile resolution: school board + state → profile; **no match → `generic`**
- **[v1]** **Required objects** — presence-only. Freely movable, restyleable, translatable;
  **not deletable**
- **[v1]** Live compliance panel with per-rule **authority + evidence level + verifiedOn**
- **[v1]** Publish blocked on any unbound required key
- **[v1]** Profile staleness banner past a review interval
- **[v1]** Compliance profiles editable by **platform super-admin only**
- **[v2]** Conditional content rules (CISCE-style: "Promoted to Class X" gated on criteria)

### 5.6 Language
- **[v1]** Nine languages: English, Hindi, Tamil, Telugu, Marathi, Gujarati, Bengali, Kannada,
  Malayalam (eight scripts — Hindi and Marathi share Devanagari)
- **[v1]** Every text object carries a **per-language string map** — a Hindi certificate is the
  same template with a different string set, not a second template
- **[v1]** Language switcher previews each in the designer
- **[v1]** Per-language font selection with sane defaults
- **[v1]** Untranslated-string report before publish
- **[v2]** **Bilingual side-by-side on one document** *(not selected by operator; several state
  boards expect it — flagged in S14)*
- **[v2]** Auto-translation assistance

### 5.7 Pre-printed stationery — **DEFERRED to v1.1** *(operator decision)*
Deferred because assumption **A3** (pre-printed certificate books are common enough to justify the
work) is **LOW confidence** — the research pass explicitly failed to verify it, and the print domain
received no adversarial verification at all. Building it now risks building for nobody.

- **[v1.1]** Stationery mode: suppress chrome already printed on the paper
- **[v1.1]** Non-printing **tracing background** — position against a scan of the real stationery
- **[v1.1]** Calibration offset (mm) + **crosshair test print** for printer drift
- **[v1.1]** Declare Book No. / Sl. No. fields (CBSE Annexure-I carries them pre-printed)
- **[v2]** Consumed-serial recording (issuance-time; out of scope)

**Retained in v1 so v1.1 is additive, not a rewrite:** millimetre coordinate system, precise mm
entry, rulers and grid. Stationery mode then adds a background layer, a chrome toggle and an offset
— it does not change the layout model.

### 5.8 Proof, publish, activate
- **[v1]** **Proof PDF** through the real mPDF pipeline with real data
- **[v1]** Publish gated on a successful proof render; hash stored
- **[v1]** Immutable versioning — editing a published template creates v+1
- **[v1]** Exactly one **active** template per (school, docType)
- **[v1]** Optimistic locking (`lockVersion`) — concurrent editors cannot silently overwrite
- **[v1]** RBAC: view / edit / **manage** (publish + activate)

---

## S6 — Technical Architecture

### 6.1 Stack decisions and why

| Layer | Choice | Rationale |
|---|---|---|
| PDF renderer | **mPDF** (Composer) | Only pure-PHP option with an Indic shaper. dompdf renders Devanagari matras in the wrong order — four open issues (#655, #969, #1361, #2855) |
| Rich text | **Quill 2.0.3** self-hosted UMD in `assets/js/` | BSD (commercial OK, self-host OK); *"requires no build steps"*; **Embed blots are void nodes** = atomic merge fields |
| Canvas | Hand-rolled on the Quill/DOM model | Evaluate Fabric/Konva in R2b; a canvas of absolutely-positioned DOM nodes may beat a `<canvas>` library, since text must stay real DOM for Quill |
| Storage | Firestore (per ADR) | Compound queries, atomic transactions, non-cascading rules |
| Binaries | Cloud Storage `schools/{schoolId}/…` | Firestore doc ceiling is 1 MiB |

**Rejected:** CKEditor 5 (GPL-or-paid; free tier is CDN-only, 1,000 loads/month) · CKEditor 4.12.1,
already vendored (**EOL 30 June 2023**, unpatched) · TipTap and ProseMirror (require a bundler; the
panel has none) · headless Chromium (new infra; Ohio box has OOM history).

### 6.2 Renderer isolation — no regression risk

mPDF is used **only** by the document engine. Every existing dompdf path (Result, Accounting, Sis,
Admission_public, import_credentials_pdf) is untouched. This is not a compromise — spec §8.1
already required a separate render path, because `Pdf_generator::render()` injects **184 lines** of
report-card CSS and hardcodes A4 portrait into every PDF it produces.

```
Designer ──► TemplateModel (JSON, Firestore)
                    │
                    ├──► HtmlSerializer ──┬──► browser preview  (same output)
                    │                     └──► mPDF ──► Proof PDF / print
                    └──► ComplianceValidator
```

One serializer, two sinks. Divergence is a bug, not a style choice.

### 6.3 Font pipeline (new critical-path work)

1. Bundle **Lohit** per Indic script — Devanagari (Hindi + Marathi), Tamil, Telugu, Gujarati,
   Bengali, Kannada, Malayalam. **OFL 1.1**, free for commercial redistribution. Latin uses
   mPDF's bundled **DejaVuSans**; Lohit has no Latin coverage.
   *(Corrected 2026-08-17 by gate G0.2 — **Noto Sans is unusable with mPDF**: every current build,
   Latin included, throws `GPOS Lookup Type 5, Format 3 not supported` at registration. Lohit
   verified: all 7 Indic scripts embed as distinct subsets. Note Lohit ships **Regular only** —
   no true Bold.)*
2. Register in mPDF's `fontdata` config; writable `ttfontdata/` cache directory.
3. `useOTL = 0xFF` (bit `0x80` is the one complex scripts need).
4. Keep font subsetting on — the PDF embeds only used glyphs, so output stays small even though
   the server-side font set is several MB.
5. **Verify per script with a real proof render.** OTL v2 script tags (e.g. `bng2` vs `beng`) give
   better results; older open-source fonts written for v1 still work but render worse.

### 6.4 Auto-grow with push-down

> **SUPERSEDED 2026-08-17** — see `IMPLEMENTATION_ARCHITECTURE.md` §0. The server-side layout pass
> described below is **not needed**. An anchor chain is emitted as a single absolutely-positioned
> container whose members are ordinary block children, so the renderer performs the measurement and
> reflow itself. No text measurement, no topological sort, no two-pass render. **Phase 0.1 is
> removed from the roadmap.** The design-time model below still stands — only the emission strategy
> changed.

A canvas positions absolutely, but real data varies. Final geometry is therefore knowable only at
**render** time, not design time.

- A text box may be `fixed` or `auto` height.
- An object may declare `anchorTo: <objectId>` — it holds a **gap** below that object rather than
  an absolute Y.
- The serializer runs a **layout pass**: resolve auto heights against real data, then propagate
  displacement through anchor chains, then emit final absolute positions.
- Cycles are rejected at design time.
- Anything not anchored keeps its absolute position; the designer warns if a resolved layout would
  overlap.

This is the single most complex piece of engineering in the module and should be built and tested
before the surrounding UI.

### 6.5 Security

| Risk | Control |
|---|---|
| SSRF via image URLs (mPDF, like dompdf, fetches remotely) | Images are **Storage refs, not URLs**; whitelist enforced **in the renderer**, not the UI |
| Expiring signed URLs breaking later renders | Never persist signed URLs in a template |
| Template CSS colliding with panel CSS | Emit namespaced under a generated root class; the `.att-grid` incident is precedent |
| Rich-text HTML injection | Server-side sanitise Quill output on save; never trust client HTML |
| Privilege escalation via profile edit | Compliance profiles are platform-super-admin only |

### 6.6 Performance

- mPDF is heavier than dompdf. Proof renders are **explicit, not per-keystroke** — the Ohio box has
  OOM history.
- Preview is browser HTML (same serializer), so the edit loop never touches the server renderer.
- Font cache warms once per script, not per render.
- Gallery uses metadata cards, **not** rendered thumbnails — PDF→PNG needs imagick/ghostscript,
  which is unverified on the box.

---

## S3 — Market Analysis

> **Evidence warning.** Under doctrine 2 this module must not invent market or competitor data.
> Only the two figures below are verified. **Everything else in S3 is HYPOTHESIS and is labelled
> as such.** The operator runs this business and is the primary source here — see the ASK at 3.4.

### 3.1 Verified market shape

| Fact | Figure | Source |
|---|---|---|
| Schools in India | ~1.5 million | research corpus |
| CBSE-affiliated | ~30,000 (~2%) | verification pass, repeated across multiple verifiers |

The 2% figure is the commercially decisive one. A CBSE-only compliance story addresses a rounding
error of the market; **the state boards are the market.** This is precisely why the compliance model
is board-and-state-scoped with a `generic` fallback rather than a CBSE-shaped default.

### 3.2 Positioning HYPOTHESES

- `HYPOTHESIS(competing Indian school ERPs ship fixed, non-designable certificate templates,
  confidence=m)` — consistent with the fact that ZenXii's own `Certificates.php` does exactly
  this (4 hardcoded types, no PDF, no designer), which is weak evidence of category norms.
- `HYPOTHESIS(no competitor offers board/state-scoped compliance enforcement with cited
  authority, confidence=l)` — plausible, unverified. **Do not use in sales material until
  verified.**
- `HYPOTHESIS(certificate/document capability influences ERP purchase decisions materially,
  confidence=l)` — the office bears this pain daily, but purchase decisions are made by
  principals and trustees.

### 3.3 Where the defensibility actually is

Not the canvas — a canvas is engineering any competitor can fund. The defensible asset is the
**maintained, cited, board-and-state-scoped compliance corpus** with `verifiedOn` freshness. That
took two adversarial research passes, 281 documents, and produced 41 refutations of claims that
looked authoritative. It cannot be cloned from a screenshot.

The corollary is a *cost*, not just a moat: the corpus must be maintained. Authorities moved within
the last year (Code on Wages commenced 21-11-2025; a CBSE bye-law repealed in 2010 still widely
cited). An unmaintained compliance claim becomes a liability that asserts wrong law confidently.

### 3.4 ASK — operator input required

1. Which ERPs do you actually lose deals to, and what do their certificate modules do?
2. Has a prospect ever asked for regional-language certificates? That would move
   `CON-MULTILINGUAL` from "operator preference" to "evidenced demand".
3. Do schools ask for TC specifically, or for the whole document set?

Until answered, S3 supports **no** competitive claims.

---

## S4 — Personas

Grounded in the RBAC catalogue actually present in the codebase (`Super Admin`, `School Super
Admin`, `Admin`, `Principal`, `Teacher`) plus roles named in the research corpus.

### P1 — Office Clerk / Admin Assistant · **primary designer**
Issues most documents; owns the physical certificate books; knows exactly what the school's
letterhead must look like. **Not a designer by trade.**
- **Needs:** get an existing template looking right, fast; not to be blamed for a legal mistake
- **Fears:** breaking something invisible; a certificate rejected by another school
- **Design consequence:** starter templates must be excellent, because most clerks will never
  start from blank. Compliance-by-construction protects them by default rather than by vigilance.
- Capability: `edit` — **not** publish

### P2 — Principal · **approver and signatory**
Signs and seals; carries the legal exposure; low tolerance for tools.
- **Needs:** confidence a template is compliant before it is used; control over what goes out
- **Design consequence:** `manage` (publish + activate) sits here. The compliance panel is written
  for *this* reader — authority and evidence level visible, not hidden behind a tooltip.

### P3 — School IT / ERP Coordinator · **power user**
Present in larger schools and chains; sets things up once.
- **Needs:** reusable blocks, stationery calibration, multi-type consistency
- **Design consequence:** justifies rulers, mm entry and reusable blocks in v1 — features P1 will
  rarely touch but P3 needs to make P1's life work.

### P4 — Platform Super-Admin (ZenXii) · **compliance owner**
- **Needs:** edit profiles, publish starters, see which schools run stale profiles
- **Design consequence:** the *only* role that may edit compliance profiles. A school must not be
  able to edit its way out of a legal requirement.

### P5 — Developer (future integrator) · **contract consumer**
Wires Fees/TC/Result print points **after** this build.
- **Needs:** a stable contract, fail-closed errors, no silent blanks
- **Design consequence:** S7 exists for this persona. They are absent at build time and cannot
  argue for themselves — which is exactly why the contract is specced first.

**Deliberately not a persona:** parents and students. They *receive* documents; they never design
them. Their needs belong to the Document Engine.

---

## S7 — Data Model  *(the deliverable)*

Firestore per the ADR. Flat top-level collections; `{schoolId}_{entityId}` keys. Collection names
belong in `ZenXII_Teacher/.../util/Constants.kt` (`object Firestore`), never string literals.

### 7.1 Collections

```
documentTypes/{typeId}                    platform-level, no schoolId
platformTemplates/{templateId}            starter gallery — CLONED on use, never referenced live
documentTemplates/{schoolId}_TPL0007      a school's templates
reusableBlocks/{schoolId}_BLK0003         letterhead / signature / seal blocks
```

### 7.2 `documentTypes` — compliance + contract

```jsonc
{
  typeId: "transfer_certificate",
  label: { en: "Transfer Certificate", hi: "स्थानांतरण प्रमाणपत्र", … },
  contractVersion: 3,

  dataContract: {
    "student.name":             { type:"string", required:true },
    "student.admissionNumber":  { type:"string", required:true },
    "student.dob":              { type:"date",   required:true, source:"admission_register" },
    "attendance.workingDays":   { type:"int",    required:true, computed:"attendance" },
    "attendance.daysPresent":   { type:"int",    required:true, computed:"attendance" },
    "result.promotionEligible": { type:"enum",   required:true, computed:"result" },
    "tc.reasonForLeaving":      { type:"text",   required:true, source:"operator" },
    "school.name":              { type:"string", required:true }
  },
  aliases: { "student.fatherName": "guardian.fatherName" },   // renames, never in-place

  complianceProfiles: [
    {
      id: "cbse",
      scope: { board: "CBSE" },
      authority: "CBSE Examination Bye-Laws, Annexure-I",
      evidenceLevel: "A",
      verifiedOn: "2026-08-16",
      owner: "platform-compliance",
      requiredKeys: [ "student.name", "student.dob", … ],   // 22 fields — TRANSCRIBE FROM SOURCE
      requiredSignatures: ["class_teacher","checked_by","principal"],
      sealRequired: true,
      stationery: { preprintedBookSerial: true },
      numberingKind: "transfer_certificate"
    },
    { id:"generic", scope:{ fallback:true }, requiredKeys: [], enforces: [] }
  ]
}
```

**Profile resolution:** `board + state → profile`; no match → `generic`, which enforces nothing and
says so in the UI. We never invent a requirement to fill a gap.

### 7.3 `documentTemplates` — the canvas model

```jsonc
{
  schoolId, docType, name,
  status: "draft" | "published" | "archived",
  version: 3,
  lockVersion: 17,                 // optimistic concurrency — stale write ⇒ conflict
  isActive: false,                 // exactly one per (schoolId, docType)
  complianceProfileId: "cbse",
  contractVersion: 3,              // contract this design was authored against
  languages: ["en","hi"],
  defaultLanguage: "en",

  page: { size:"A4", orientation:"portrait", marginsMm:{…},
          stationeryMode:false, calibrationMm:{ x:0, y:0 } },

  objects: [
    {
      id: "obj_7",
      type: "text",                          // text | image | shape | table | qr | pageNumber
      xMm: 20, yMm: 45, wMm: 170, hMm: 12,
      z: 3,
      height: "auto",                        // "fixed" | "auto"
      anchorTo: "obj_6", anchorGapMm: 4,     // holds a GAP below obj_6, not an absolute Y
      locked: false,                         // user position lock
      requiredKey: "tc.reasonForLeaving",    // compliance binding ⇒ NOT DELETABLE
      style: { fontFamily, sizePt, weight, colour, align, lineHeight },
      content: {
        i18n: {                              // per-language Quill Delta
          en: { ops:[ {insert:"Reason: "}, {insert:{mergeField:"tc.reasonForLeaving"}} ] },
          hi: { ops:[ … ] }
        }
      }
    }
  ],

  proofPdfHash: "sha256:…",        // proves it rendered; publish gate
  createdBy, updatedBy, publishedBy, publishedAt
}
```

**Four load-bearing details.**

1. **`requiredKey` is the entire compliance mechanism.** Non-null ⇒ the object cannot be deleted and
   publish blocks if the key is unbound. Position, size, style, font and language stay fully free.
   Presence-only, exactly as specified.
2. **`anchorTo` + `anchorGapMm` express intent, not geometry.** Final Y is resolved at render time
   against real data (S6.4). Cycles rejected at design time.
3. **`content.i18n` keyed by language** — a Hindi certificate is the same object with a different
   Delta, never a second template.
4. **Merge fields are Quill embeds** (`{insert:{mergeField:…}}`) — void nodes, so a mid-token
   keystroke cannot corrupt them.

### 7.4 Contract evolution — append-only

- Fields are **append-only**. Never remove or rename in place.
- A rename ships as a **new field + an `aliases` entry**, so v3 templates keep resolving.
- `contractVersion` increments on addition; templates record what they were authored against.

Without this, adding one field silently breaks every published template — the classic mail-merge
failure.

### 7.5 Consumption contract *(deferred integration surface)*

```php
$tpl  = $documentTemplates->active($schoolId, 'transfer_certificate');
$html = $templateRenderer->render($tpl, $dataBundle, $lang);   // single serializer
$pdf  = $documentPdf->render($html, $tpl->page);               // mPDF, paper from template
```

Both failure modes **fail closed**:
- **Contract mismatch** — bundle lacks a variable the template uses ⇒ hard error, never render.
- **Unresolved placeholder** ⇒ hard error. A document must **never** print a literal
  `{student_name}`; that is an embarrassment and a forgery vector.

This reverses today's `Certificates.php`, which substitutes `''` for missing fields and prints a
blank.

### 7.6 Indexes — declare and deploy BEFORE code

Per the ADR, index drift is an existing problem (284 live vs 183 declared). Required composites:

| Collection | Fields |
|---|---|
| `documentTemplates` | `schoolId` + `docType` + `status` |
| `documentTemplates` | `schoolId` + `docType` + `isActive` |
| `documentTemplates` | `schoolId` + `updatedAt` (gallery ordering) |
| `reusableBlocks` | `schoolId` + `blockType` |

### 7.7 Rules obligations

- `documentTypes`, `platformTemplates` — read: any authenticated school user; **write: platform
  super-admin only**
- `documentTemplates` — scoped by `school_id` claim; write requires the capability grade
- Published templates are **immutable except** `isActive`, `status→archived`, and `lockVersion`
- `firestore.rules` is a shared file with concurrent editors — run `node aegis/cli.js rules status`
  before editing, keep the change in one `match` block, diff before deploy

---

## S8 — User Journeys

### J1 — Clerk adapts a starter to the school's letterhead  *(the dominant path)*
Certificates → **Bonafide** → gallery → picks "Bonafide — Classic" → **clone** into school →
canvas opens → replaces logo (Storage picker) → edits school name/address text → drags the
signature block → **Proof PDF** → looks right → *cannot publish* (`edit` only) → requests approval.
**Design load:** starter quality is decisive. Most clerks will never open a blank canvas.

### J2 — Principal reviews and activates
Opens the draft → compliance panel shows all rules **green with authority + evidence level** →
Proof PDF with a real student → **Publish** (v1, immutable, hash stored) → **Set active**.
From here, any future print point resolves this template.

### J3 — Clerk designs a TC from blank *(compliance in action)*
Blank canvas → profile resolves to **CBSE** → required objects appear pre-placed, each flagged
*required* → clerk rearranges freely, restyles, changes fonts → deletes one by accident →
**deletion refused**, panel explains: *"Date of first admission — CBSE Examination Bye-Laws,
Annexure-I · Level A · verified 2026-08-16"* → publishes.
**This is the product thesis in one interaction:** total freedom, impossible to drop a mandated field.

### J4 — School with no verified profile
State-board school in Karnataka → profile resolves to **`generic`** → panel states plainly that
**no verified requirements exist for this board/state** and enforces nothing.
**Honesty is the feature.** We never invent a requirement to fill a gap.

### J5 — Hindi certificate
Template → language switcher → **हिन्दी** → each text object shows its Hindi Delta → clerk
translates labels (merge fields untouched — they resolve from data) → untranslated-string report
lists 3 remaining → fixes → Proof PDF renders through mPDF with `useOTL` → **matras and conjuncts
correct** → publish.
**Critical test:** this journey is the one that proves the renderer decision. It must be exercised
per script, not once.

### J6 — Pre-printed stationery
IT coordinator → stationery mode → uploads a scan of the school's TC book page as tracing
background → chrome auto-suppressed → positions variable fields over the printed blanks →
**crosshair test print** → measures 2 mm drift → enters calibration offset → re-prints → aligned.

### J7 — Concurrent edit *(failure path)*
Two admins open the same template. B saves first. A saves → `lockVersion` stale → **conflict**,
with reload-or-overwrite. Never a silent overwrite.

### J8 — Future integrator *(post-build, out of scope)*
Fees module calls `active($schoolId,'fee_receipt')` + `render($tpl,$bundle,$lang)`. Bundle missing
a used variable → **hard error, nothing renders.** No blank field ever prints.

---

## S9 — Milestone Roadmap  *(relative sequence — no dates, per operator)*

Ordered by **risk retirement**, not by visible progress. The riskiest mechanics come first, because
each one, if wrong, invalidates work built on top of it.

### Phase 0 — De-risk before any UI  ⚠ do not skip
| # | Task | Retires |
|---|---|---|
| ~~0.1~~ | ~~Anchor/layout-pass prototype~~ — **REMOVED 2026-08-17.** Anchor chains emit as flow containers; the renderer measures. See `IMPLEMENTATION_ARCHITECTURE.md` §0. | — |
| 0.2 | **Quill → mPDF proof render, per script** — all 8 scripts, real conjuncts and matras | `CON-MULTILINGUAL`. The whole renderer decision rests on this. |
| 0.3 | **Transcribe CBSE Annexure-I** from the source PDF | Gates the only verified profile v1 ships |
| 0.4 | mPDF spike: memory + render time on an Ohio-class box | Box has OOM history |

**Gate:** if 0.2 fails, the architecture changes. Nothing downstream should start first.

### Phase 1 — Foundation
Data model + contract (S7) · Firestore collections · **indexes declared and deployed first** ·
rules (one `match` block, `aegis rules status` before editing) · register document kinds in
`config/numbering.php` · clean mPDF render path (**not** `Pdf_generator::render()`) · RBAC via
`has_permission()`.

### Phase 2 — Canvas core
Object model · drag, resize, **snap guides**, z-order, **undo/redo** · **multi-select +
align/distribute** · rulers, zoom/pan, grid, **precise mm entry** · page setup · serializer (one,
two sinks) · namespaced CSS emission.

### Phase 3 — Text and binding
Quill 2.0.3 self-hosted UMD in `assets/js/` · merge fields as **Embed blots** · field picker bound
to the contract · auto-grow wired to the Phase-0 layout pass · live sample data.

### Phase 4 — Compliance layer
Profile schema + resolution (board+state → `generic`) · `requiredKey` binding · non-deletable
required objects · compliance panel with **authority + evidence level + verifiedOn** · publish
gate on unbound keys · staleness banner.

### Phase 5 — Publish pipeline
Proof PDF + hash · immutable versioning · single active per (school, docType) · `lockVersion` ·
sanitise Quill HTML server-side · image whitelist **in the renderer**.

### Phase 6 — Language
Bundle Lohit per Indic script (OFL 1.1) + bundled DejaVu for Latin · mPDF `fontdata` registration + writable `ttfontdata/` ·
`useOTL = 0xFF` · per-object i18n editing · untranslated-string report.

### Phase 7 — Reusable blocks + starter templates
Block collection + propagation (depends on Phase 2 multi-select — assembling a letterhead block one
element at a time is untenable) · author starters per type × board/state.

### Phase 8 — Hardening
Per-script render suite · concurrency tests · overflow/overlap warnings with real data ·
accessibility · UAT with a real school.

### ~~Phase 9~~ → **v1.1: Stationery** *(deferred, operator decision)*
Tracing background · chrome suppression · calibration offset · crosshair test print · Book No./
Sl. No. declarations. Additive — the mm coordinate system it needs ships in Phase 2.

**Deploy discipline:** rules, indexes and Cloud Functions deploy **separately** from PHP, and
indexes go **early** (they take time to build). Work happens on `yug_testing`; **`yug_b1_t` is the
live AWS branch and is never worked on directly**. Nothing deploys without explicit per-change
permission (`CON-NO_DEPLOY`).

---

## S10 — Risk / Compliance / Security

### 10.1 Retired by this blueprint
| Risk | Retired by |
|---|---|
| dompdf cannot shape Indic ⇒ **wrong words on a legal document** | mPDF (S6.1) — found in research, not in production |
| Preview lies about PDF output | No editor emits flex/grid; proof-render publish gate |
| Compliance validator policing a free canvas | Presence-only `requiredKey` (S7.3) |
| CKEditor licence / EOL exposure | Quill BSD (S6.1) |
| Text overflow silently clipping a legal field | Auto-grow + anchor layout pass |

### 10.2 Active — technical
| Risk | Sev | Mitigation |
|---|---|---|
| Anchor layout pass is genuinely hard; wrong model ⇒ canvas rewrite | **High** | Phase 0.1 before any UI |
| mPDF memory/time on an OOM-prone box | **High** | Phase 0.4 spike; proof renders explicit, never per-keystroke |
| A script renders incorrectly despite OTL (old v1-spec fonts) | **High** | Phase 0.2 per-script proof; prefer OTL-v2 fonts |
| Indexes not deployed before code | High | Phase 1, indexes first |
| Template CSS collides with panel CSS | High | Namespaced emission — `.att-grid` precedent |
| SSRF via image refs | High | Storage refs only; whitelist **in renderer** |
| Rich-text HTML injection | High | Server-side sanitise on save; never trust client HTML |
| Contract change breaks published templates | Medium | Append-only + `aliases` (S7.4) |
| Concurrent overwrite | Medium | `lockVersion` |

### 10.3 Active — compliance *(the ones that matter most)*
| Risk | Sev | Mitigation |
|---|---|---|
| **Annexure keys transcribed from an OCR'd summary rather than source** | **High** | Phase 0.3 — blocking |
| Presence-only compliance if a board mandates **order** | **High** | Unverified (A11). Verify against Annexure-I; profile can add an order rule per-profile without model change |
| Profile applied outside its board/state scope | High | Explicit scoping + `generic` fallback |
| Level C practice presented as law | High | Authority + evidence level rendered on every rule |
| **Compliance corpus goes stale** — authorities moved within the last year | High | `verifiedOn` + named owner + staleness banner. This is an **ongoing operational cost**, not a one-off build task. |
| Only **one** verified profile ships (CBSE TC) | Medium | `generic` enforces nothing and says so; do not let marketing outrun evidence |

### 10.4 Legal boundary — carried forward, non-negotiable
Compliance validates the **template**, never the **issuance**. The CBSE form contains a *"dues paid
upto"* field, so the template renders it — that grants **zero** gating power. Rule text permitting a
no-dues gate exists (KER Ch. VI r.17(2); TNER r.40–42; CBSE r.8(vi)) but courts have gutted it
(Delhi HC LPA 393/2014; Kerala HC 2025:KER:69076; Madras HC DB 22.07.2024 — a TC *"is not a tool
for the schools to collect arrear fees"*; Karnataka HC 2025:KHC:5986), and RTE s.5(3) makes the TC
immediate and non-withholdable at the elementary stage. Any future no-dues setting must be
per-state, never default-on, and **impossible to enable for classes I–VIII**.

### 10.5 Independent finding *(outside this module)*
15 MB of **EOL, unpatched CKEditor 4.12.1** is web-served from `tools/` and referenced by zero
code. See SUG-004 — remove in a separate change, not inside a feature build.

---

## S11 — Business Model

> Doctrine 2 applies: no invented revenue or willingness-to-pay data. Packaging options below are
> **HYPOTHESIS**; the cost side is grounded.

### 11.1 Grounded — the cost side
The build is one-off; **the compliance corpus is a recurring cost.** Authorities move (Code on
Wages commenced 21-11-2025; a CBSE bye-law repealed in 2010 still widely cited; a GST entry quoted
from a superseded 2017 original). Every profile needs a named owner and periodic re-verification.

**A compliance claim that is not maintained becomes a liability, not an asset.** Budget the
maintenance or do not make the claim.

### 11.2 Packaging options — all HYPOTHESIS
| Option | Fit | Confidence |
|---|---|---|
| Included in base ERP | Treats documents as table stakes; maximises adoption, monetises nothing directly | m |
| Premium "Compliance Pack" — verified profiles + regional languages gated | Charges for the genuinely expensive part (the corpus), not the canvas | l |
| Per-document-type unlock | Granular but likely irritating at school budgets | l |

**Recommendation (Level D, ours):** base ERP. The moat is retention and displacement difficulty —
a school that has designed its own certificates in ZenXii has switching cost. Gating the corpus
behind a paywall while shipping a `generic` profile that enforces nothing is a poor first
impression.

### 11.3 Depends on the S3.4 ASK
Pricing cannot be settled without knowing which ERPs you lose deals to and whether regional-language
certificates have ever been asked for. **S11 is provisional until S3.4 is answered.**

---

## S12 — Metrics

Measurable, instrumented, honest — including the ones that could embarrass us.

### 12.1 Adoption
- % schools with ≥1 **published** custom template *(primary success signal)*
- % starting from **blank** vs cloning a starter → validates or refutes A2 and the starter investment
- Templates per school; time from first open to first publish

### 12.2 Correctness  *(must-be-zero class)*
- **Unresolved-placeholder errors at render: target 0.** Non-zero means a contract bug reached production.
- **Contract-mismatch errors: target 0**
- Proof-render failure rate — high means the serializer and mPDF disagree
- Per-script render regressions — a suite, run per release, not once

### 12.3 Compliance health
- % published templates on a **real** (non-`generic`) profile → measures corpus coverage
- **% profiles within their review interval** → the maintenance metric; if this decays, §11.1 is being ignored
- Count of blocked publishes due to unbound required keys → proves the mechanism fires

### 12.4 Language
- % templates with a non-English language set → converts `CON-MULTILINGUAL` from preference to evidence
- Untranslated-string count at publish

### 12.5 Operational
- mPDF render p50/p95 and peak memory → watches the OOM risk
- Support tickets mentioning certificate layout, before vs after
- Concurrent-edit conflicts → validates `lockVersion` was needed

### 12.6 Explicitly NOT a metric
Time spent in the designer. Longer sessions may mean delight or confusion; it cannot distinguish
them and would drive the wrong optimisation.

---

## S13 — Deferred

Everything below is deliberately **out of v1**, with the reason and the retrofit cost stated. None
of it requires a rewrite of the v1 architecture.

### 13.1 Deferred to v1.1
| Item | Why deferred | Retrofit cost |
|---|---|---|
| **Pre-printed stationery mode** | A3 (stationery prevalence) is LOW confidence; research pass could not verify it | **Low** — mm coordinates, rulers and grid ship in v1; v1.1 adds a background layer, chrome toggle and offset |

### 13.2 Deferred to v2
| Item | Why deferred | Retrofit cost |
|---|---|---|
| Layers panel, grouping | Operator scoped out; multi-select + align/distribute restored instead | Low — UI over the existing object model |
| **Bilingual side-by-side** on one document | Operator: switchable languages only | **High** — both strings occupy the page simultaneously, so it changes the *layout* model, not just strings. Flagged in S14. |
| Rotation, opacity, gradients | Not required by any v1 document | Low |
| Multi-page documents | No v1 document exceeds one page | Medium — page-break control is historically weak in PHP renderers |
| **Flow bands** (repeating rows) | No v1 document has a repeating collection | Medium — additive element type; **required** for fee receipts and report cards |
| Conditional sections / computed expressions | Not needed by v1 documents | Low |
| Full 281-document type catalogue | Only 3 types in v1 | Low — data, not code |
| Cross-school template sharing (trusts/chains) | No verified demand | Low |
| Auto-translation assistance | Manual translation is adequate at 3 document types | Low |

### 13.3 Out of scope entirely — belongs to the Document Engine
Issuance · number allocation at issue · PDF archival to Storage · QR verification endpoint ·
approval workflow on *issued* documents · DigiLocker · bulk printing · consumed-serial recording ·
**wiring any module's print button** (`CON-NO_PRINT_IMPL`).

---

## S14 — Open Questions

### 14.1 Blocking — must resolve before or during Phase 0
| # | Question | Owner | Blocks |
|---|---|---|---|
| Q1 | **Does CBSE mandate field ORDER on the Annexure-I TC, or only presence?** Presence-only compliance was chosen; if order is mandated, profiles need an order rule. The model supports adding one per-profile without a rewrite, but the answer must be known. | Platform compliance | Correctness of the only verified profile |
| Q2 | **Exact Annexure-I field list, labels and order** — must be transcribed from the source PDF, not the OCR'd research summary | Platform compliance | Phase 0.3, gates v1 |
| Q3 | **Do all 8 scripts render correctly through mPDF with real conjuncts and matras?** | Engineering | Phase 0.2, gates `CON-MULTILINGUAL` |
| Q4 | **Does the anchor/layout-pass model hold under real data?** | Engineering | Phase 0.1, gates the canvas |
| Q5 | **mPDF memory and render time on an Ohio-class box** — the box has OOM history | Engineering | Phase 0.4 |

### 14.2 Non-blocking — open, logged, not silently resolved
| # | Question | Status |
|---|---|---|
| Q6 | **Market/competitor picture** (S3.4 ASK) — which ERPs do we lose to, has any prospect asked for regional-language certificates, do schools ask for TC specifically or the whole set? | **Operator chose: leave as HYPOTHESIS.** S3 supports no competitive claims; **S11 is provisional** until answered. |
| Q7 | **Bilingual side-by-side** — several state boards appear to expect it | Deferred deliberately. High retrofit cost (changes the layout model). Worth verifying with schools before v2 planning. |
| Q8 | **Is pre-printed stationery actually common?** (A3, LOW confidence) | Deferred with v1.1. Answer before building Phase v1.1, not after. |
| Q9 | **Kerala Form 5 / TN Appendix 5 field lists** were never retrieved; **Maharashtra, Karnataka, UP** have no verified authority at all | These schools get `generic`. Each new verified profile is pure additive value — and the state boards are ~98% of the market (S3.1). |
| Q10 | **Canvas implementation**: hand-rolled DOM vs Fabric/Konva | Decide in Phase 2. Text must remain real DOM for Quill, which likely rules out a `<canvas>` library. |
| Q11 | **Compliance corpus maintenance owner and review interval** | Unassigned. §11.1: an unmaintained compliance claim is a liability, not an asset. |

### 14.3 Live suggestions
- **SUG-001** — verify stationery prevalence → **absorbed into Q8** by the v1.1 deferral
- **SUG-004** — delete the 15 MB EOL, unpatched, web-served CKEditor 4.12.1 from `tools/`
  (referenced by zero code). **Still open.** Separate change, not inside a feature build.
- ~~SUG-002~~ closed — CONFLICT-1 resolved to full canvas
- ~~SUG-003~~ closed — R2 selected Quill 2.0.3
