# P1.1 — Document Engine collection shapes

**Status:** DESIGN — declared, not deployed. Indexes are written into
`firebase-rules/firestore.indexes.json`; **deploying them is a separate, permissioned step.**
**Date:** 2026-08-18

Derived from `IMPLEMENTATION_ARCHITECTURE.md` §3 and v1.1 §3.2–3.7. This is the single source the
indexes (P1.2) and rules (P1.3) are both generated from — get it wrong and both are wrong.

---

## 0. Conventions this must obey

| Rule | Source |
|---|---|
| Flat top-level collections — **never** per-school subtrees | ADR / CLAUDE.md |
| Document key `{schoolId}_{entityId}` | ADR |
| Every query scoped **twice**: `schoolId` **and** session where session-bound | CLAUDE.md — "the single most repeated bug in this codebase" |
| Collection names live in `ZenXII_Teacher/.../util/Constants.kt` (`object Firestore`), not string literals | CLAUDE.md |
| Claims dual-emit `school_id` (snake, rules) **and** `schoolId` (camel) | ADR |

**Session scoping — a deliberate exception.** A *template* is not session-bound: a school designs
its TC layout once and keeps using it across years. So `documentTemplates` carries **no** session
field and is queried by `schoolId + docType` only. Session belongs on the **issued document**
(next engine), which is where the "scope twice" rule bites. Recording this explicitly because it
looks like the classic missing-session-filter bug and is not.

---

## 1. Collections

| Collection | Key | Scope | Mutability |
|---|---|---|---|
| `documentTypes` | `{typeId}` | **platform** — no schoolId | platform-admin write |
| `mergeFieldContracts` | `{docType}_v{n}` | **platform** | create-only (immutable versions) |
| `platformTemplates` | `{templateId}` | **platform** | platform-admin write; **cloned** on use |
| `documentTemplates` | `{schoolId}_TPL0007` | school | head — mutable draft + pointers |
| `documentTemplateVersions` | `{schoolId}_TPL0007_v2` | school | **create-only, forever** |
| `reusableBlocks` | `{schoolId}_BLK0003` | school | school write |

---

## 2. `documentTypes/{typeId}` — platform

```jsonc
{
  typeId: "transfer_certificate",
  label: { en: "Transfer Certificate", hi: "स्थानांतरण प्रमाणपत्र" },
  shape: "form",                       // form | letter | canvas (v2: canvas is the one editor)
  contractRef: "transfer_certificate_v3",
  enabledByDefault: true,
  complianceProfiles: [ /* §5 */ ],
  createdAt, updatedAt, updatedBy
}
```

v1 seeds exactly three: `transfer_certificate`, `bonafide`, `character`.

---

## 3. `documentTemplates/{schoolId}_TPL0007` — the head

Holds the working draft **and the pointers**. Publishing freezes a snapshot elsewhere (§4).

```jsonc
{
  schoolId: "SCH_D94FE8F7AD",
  templateId: "TPL0007",
  docType: "transfer_certificate",
  name: "TC — main letterhead",

  status: "draft",                     // draft | published | archived
  version: 3,                          // the DRAFT's version number
  publishedVersion: 2,                 // -> documentTemplateVersions, null if never published
  activeVersion: 2,                    // exactly one active per (schoolId, docType); null if none
  lockVersion: 17,                     // optimistic concurrency — stale write returns conflict

  // compliance is a STACK, not a lookup — see §5
  complianceBasis:  { board: "CBSE", state: "Kerala", stage: "secondary" },
  complianceLayers: [ { authorityId: "cbse", version: 4, applied: true },
                      { authorityId: "ker",  version: 2, applied: true } ],
  contractRef: "transfer_certificate_v3",

  languages: ["en", "hi"],
  defaultLanguage: "en",
  languageFallback: "block",           // block | default — statutory docs use block

  page: {
    size: "A4",                        // A4|A5|Letter|Legal|[w,h] mm
    orientation: "portrait",
    marginsMm: { t: 42, r: 15, b: 16, l: 15 },
    pageMode: "single",                // single | flow  (drives the overflow gate)
    stationeryMode: false,
    calibrationMm: { x: 0, y: 0 }      // v1.1 deferred; field reserved
  },

  header: { objects: [ … ] },          // repeating chrome — see §3.2
  footer: { objects: [ … ] },
  objects: [ … ],                      // §3.1

  createdAt, createdBy, updatedAt, updatedBy
}
```

### 3.1 Object model

```jsonc
{
  id: "obj_7",
  type: "text",            // text | image | shape | table | qr | pageNumber
  xMm: 20, yMm: 45, wMm: 170, hMm: 12,
  z: 3,
  height: "auto",          // fixed | auto
  maxHMm: 34,              // ⛔ ceiling for height:"auto" — see §3.5
  anchorTo: "obj_6",       // null = absolute at (x,y); else holds a GAP below that object
  anchorGapMm: 4,
  flowRegion: false,       // at most ONE per template — see §3.3
  locked: false,           // user position lock (distinct from compliance lock)
  requiredKey: "tc.reasonForLeaving",   // non-null ⇒ NOT DELETABLE; publish blocks if unbound
  style: {
    fontFamily: "lohitdeva",
    sizePt: 9.5,
    lineHeight: 1.45,      // ⛔ MANDATORY — see §3.4
    weight: 400, colour: "#000", align: "left"
  },
  content: { /* type-specific; text uses i18n Quill Deltas */ }
}
```

### 3.2 `header` / `footer` are first-class — a G0 finding

Chrome that must **reserve space** cannot be an absolute object. Proven while building the TC
specimen: letterhead placed absolutely with a `margin-top` shim on the body **collided with rows
1–4**, because mPDF collapses margin-top on the first flow block and absolute content reserves
nothing.

Chrome therefore lives in `header`/`footer`, rendered via mPDF's `SetHTMLHeader`/`SetHTMLFooter`,
with `page.marginsMm.t` reserving the space. This also gives repeat-per-page for free.

### 3.3 `flowRegion` — at most one

The single chain permitted to paginate. Everything else is absolute. Required because
`pageMode: "flow"` documents need a region mPDF can actually break across pages.

### 3.4 `style.lineHeight` is mandatory, not optional

**G0.5, blocking.** With an explicit line-height, mPDF and Chrome agreed on **92/92** probes,
0.00 mm divergence. Without it each engine uses its own font-derived leading — Tamil measured
**18.03 mm vs 9.53 mm** on one block, ~2× out, compounding down a chain. A template with a null
`lineHeight` must fail validation, not render.

### 3.5 `maxHMm` — auto-grow needs a ceiling *(added 2026-08-19)*

`design/FIGMA_ARCHITECTURE_STUDY.md` §2 caught a hole in the model above: `height` had only
`auto | fixed`, and **an `auto` box by definition never overflows — it pushes everything below it
off the sheet.** The overflow badge fires on `fixed` boxes only, so the dangerous case was silent.

Concretely: `tc.reasonForLeaving` is auto-grow, anchored above the issue date, the declaration and
the signature block. A p95 reason is two lines; a clerk pasting a parent's letter is eight. Every
anchored object below moves down and **the signature block leaves the page — on a document legally
required to carry three signatures and a seal.**

So `height: "auto"` may carry `maxHMm`. When resolved content exceeds it:

- the object **clamps** at `maxHMm`, so downstream anchors stay where the designer put them;
- a **blocking** finding is raised — not a warning. Content that does not fit is content that will
  not print, and silent truncation of a statutory field is the worst available outcome;
- the finding names the field and the overshoot in mm, at the current data mode.

Max-height rather than max-lines is deliberate: a certificate is measured against a physical sheet,
and line count is an accident of the font.

**This complements, not replaces, the §8 tier-2 chain check.** `maxHMm` stops one object pushing
the chain; `wouldOverflow()` catches the chain's aggregate against the page. Both are needed.

---

## 4. `documentTemplateVersions/{schoolId}_TPL0007_v2` — create-only

The answer to *"show me the exact template that produced this certificate"*, years later.

```jsonc
{
  schoolId, templateId, docType, version: 2,
  snapshot: { page, header, footer, objects, languages, defaultLanguage },  // frozen, complete
  contractRef, complianceBasis, complianceLayers,      // layers FROZEN — see §5.2
  validationResult: { blocking: [], warnings: [] },      // as at publish
  proofPdfHash: "sha256:…",
  proofPdfPaths: { en: "schools/…/_proofs/TPL0007_v2_en.pdf", hi: "…" },
  fontManifest: { lohitdeva: "sha256:…", dejavusans: "sha256:…" },
  mpdfVersion: "8.3.1",
  publishedBy, publishedAt
}
```

**No update, no delete — ever.** Not by a school admin, not by a platform admin, not by a Cloud
Function. Editing a published template mutates the **head** to `version: 3, status: draft`; the v2
snapshot is untouched.

**Why `fontManifest` + `mpdfVersion`:** a byte-identical re-render years later is impossible if the
font files or the engine moved underneath. Recording them makes a discrepancy *explainable* rather
than mysterious. Lohit's lack of a Bold face makes this sharper — a future bold source would change
output.

---

## 5. Compliance — **a stack, not a lookup** *(revised 2026-08-19)*

> **SUPERSEDED.** The `board + state → profile` model below was wrong, and
> `design/COMPLIANCE_ARCHITECTURE.md` §1 is right: a school is under **several authorities at
> once**. A CBSE school in Kerala is bound by RTE (national, classes I–VIII), CBSE (board) **and**
> the Kerala Education Rules (state) simultaneously. A single-profile lookup forces a choice, and
> whichever layer loses is **silently unenforced** — invisible until an audit.

### 5.1 `complianceAuthorities/{authorityId}` — platform, many-to-many

A state authority spans many document types; a document type spans many states. That relation
cannot live embedded inside `documentTypes`.

```jsonc
{
  id: "ker",
  tier: "national" | "board" | "state",
  label: "Kerala Education Rules 1959",
  authority: "Kerala Education Rules 1959, Chapter VI",
  evidenceLevel: "A",
  verifiedOn: "2026-08-18",
  owner: "platform-compliance",
  sourceRef: "gs://…/ker_chapter6.pdf",        // the artefact actually read
  reviewIntervalMonths: 12,
  scope: { board: null, state: "Kerala", stages: ["elementary","secondary"] },
  docs: {
    transfer_certificate: {
      form: "Form 5",
      requiredKeys: [],            // empty = enforces nothing, AND SAYS SO
      fieldListVerified: false,
      requiredSignatures: [], sealRequired: false,
      constraints: [ { text, citation, judicialNote? } ]
    }
  }
}
```

**Resolution is a union, not a pick:**

```
resolveStack(docType, school) = [ national…, board…, state… ]  filtered by scope
requiredKeys = ⋃ layer.requiredKeys
```

Three things fall out for free:

- A state with no transcribed rule becomes a **named layer** — *"Jharkhand — no verified
  authority"* — which is a finding, not a silence.
- **RTE drops out automatically at the secondary stage**, because its scope is classes I–VIII.
  Under the old model a human had to remember that.
- A document type can be **state-specific**: Kerala's Certificate of School Education (KER r.22A)
  simply does not exist for a Jharkhand school, and the hub says why rather than hiding it.

### 5.2 On the template head — replaces `complianceProfileId`

```jsonc
complianceBasis: { board: "CBSE", state: "Kerala", stage: "secondary" },
complianceLayers: [
  { authorityId: "cbse", version: 4, applied: true },
  { authorityId: "ker",  version: 2, applied: true },
  { authorityId: "rte",  version: 1, applied: false,
    excludedReason: "school teaches IX–XII only" }
]
```

**The layers freeze into the published snapshot (§4).** The question a published template must
answer years later is not *"what does Kerala require today?"* but *"what was this validated against
when it was issued?"* Storing only the basis would re-resolve against a corpus that has since
moved — the same reason the snapshot already records `fontManifest` and `mpdfVersion`.

---

## 5A. Superseded — compliance profiles embedded in `documentTypes`

```jsonc
{
  id: "cbse",
  version: 4,
  scope: { board: "CBSE" },                 // resolution: board+state -> profile, else `generic`
  authority: "CBSE Examination Bye-Laws, Annexure-I",
  evidenceLevel: "A",
  verifiedOn: "2026-08-18",
  owner: "platform-compliance",
  sourceRef: "tests/doctemplates/gate0/reference/cbse_examination_byelaws_1995upd2004.pdf",
  requiredKeys: [ /* 22 — pending G0.8 sign-off */ ],
  requiredSignatures: ["class_teacher", "checked_by", "principal"],
  sealRequired: true,
  stationery: { preprintedBookSerial: true },
  countersignature: { conditional: true, when: "origin_board != CBSE" },
  numberingKind: null                        // issued-doc numbering is the NEXT engine
}
```

`generic` is the fallback: `requiredKeys: []`, enforces nothing, and **says so in the UI**. We
never invent a requirement to fill a gap.

---

## 6. `mergeFieldContracts/{docType}_v{n}` — create-only

```jsonc
{
  docType: "transfer_certificate", version: 3,
  fields: [
    { key: "student.fullName", type: "string", source: "students.name",
      nullable: false, maxLen: 120, sample: "Aarav Sharma" },
    { key: "attendance.workingDays", type: "int", computed: "attendance",
      nullable: false, sample: "220" }
  ],
  aliases: { "student.fatherName": "guardian.fatherName" },
  createdAt
}
```

- **Append-only.** Removing a field ⇒ mark `deprecatedIn`, never delete: old snapshots must resolve.
- `maxLen` feeds the capacity budget; `sample` must be **p95-realistic** — a short sample is how
  overflow bugs reach production.

---

## 7. `reusableBlocks/{schoolId}_BLK0003`

```jsonc
{
  schoolId, blockId: "BLK0003",
  blockType: "letterhead",         // letterhead | signature | seal
  name: "Main letterhead",
  version: 3,                      // bumped on every edit
  objects: [ … ],
  usedByTemplates: ["TPL0007"],    // denormalised, for the update OFFER
  createdAt, updatedAt, updatedBy
}
```

### 7.1 Block edits are an OFFER, not a propagation *(revised 2026-08-19)*

`design/FIGMA_ARCHITECTURE_STUDY.md` §1 caught a contradiction between two of our own documents,
and it is the sharper of the two it found:

- `FINAL_BLUEPRINT.md` S5.3 — block edits *"propagate to templates that reference the block"*
- `COLLECTION_SHAPES.md` §4 — published versions *"No update, no delete — ever."*

**These cannot both be true.** If a school fixes a typo in its letterhead and that propagates into
a template whose v2 is published and active, the frozen snapshot that is supposed to answer *"show
me the exact template that produced this certificate"* has changed underneath a document already
issued to a student. That is the module's central integrity claim failing on the most ordinary
action a clerk performs — and neither document acknowledged the other.

Figma does not propagate; it **offers**. Adopt the pull model with our immutability on top:

| Target | Behaviour |
|---|---|
| **Draft** templates | Edit propagates immediately — a draft is a working document |
| **Published / active** versions | **Never change.** The snapshot is the legal record. |
| Published template whose block changed | Shows a **pending block update** badge; review is before/after; accepting creates **draft v+1** and never touches the published version |
| Ignoring | Permitted **and remembered**, so the badge does not nag. A school may deliberately keep an old letterhead on one certificate type. |

Needs on the template head: `blockRefs: [{ blockId, acceptedVersion, ignoredVersion }]`.

**Risk if unfixed:** the first school to edit a letterhead silently invalidates every published
template using it, and nobody finds out until an audit compares a printed TC against its snapshot.

---

## 8. Indexes (P1.2) — **DECLARED 2026-08-27, NOT deployed** *(withdrawal reversed — see §8.2)*

> **The 2026-08-19 withdrawal was reasoned from a hazard that does not exist.** All seven are now in
> `firebase-rules/firestore.indexes.json`. Aegis reports them as `mine 7 · dropped 0`. **Deploying is
> still a separate, permissioned step.** The original withdrawal text is kept below unedited, because
> the reasoning error is worth being able to re-read.

> **Withdrawn from `firestore.indexes.json` on operator instruction.** The table below records what
> the Document Engine will need; nothing is declared in the repo and nothing exists in Firestore.
>
> **Why this matters — the drift is worse than documented.** A read-only
> `firebase firestore:indexes --project graderadmin` returned **285 live** indexes against **184**
> in the file: 180 in both, and **105 live-only**. Deploying the file as desired state would offer
> to **delete** those 105, which cover Transport (`tripLogs`, `vehicles`, `routes`,
> `studentRoutes`, `routeAssignments`), CRM (`crmApplications`), campus access, visitor passes,
> driver incidents — and `auditLogs` itself.
>
> **Reconcile the file to live before anyone runs an index deploy on this project.** That is a
> standing hazard independent of this module.

| Collection | Fields |
|---|---|
| `documentTemplates` | `schoolId` ASC, `docType` ASC, `status` ASC |
| `documentTemplates` | `schoolId` ASC, `docType` ASC, `activeVersion` ASC |
| `documentTemplates` | `schoolId` ASC, `updatedAt` DESC |
| `documentTemplateVersions` | `templateId` ASC, `version` DESC |
| `reusableBlocks` | `schoolId` ASC, `blockType` ASC |
| `complianceAuthorities` | `scope.state` ASC, `tier` ASC |
| `complianceAuthorities` | `scope.board` ASC, `tier` ASC |

⚠ **Deploy these BEFORE any query code exists.** Index builds take time, and this project already
carries drift (284 live vs 183 declared).

### 8.1 Re-verified 2026-08-27 — **the withdrawal stands, and the tooling that was supposed to check it is gone**

`[FACT|OBSERVED]` Recomputed from `aegis/.state/live-indexes-graderadmin.json` using Aegis's own
canonical index key, diffed against both `HEAD` and the working tree:

| | Count |
|---|---|
| Live (cached snapshot) | **284**, all `READY` |
| Declared at `HEAD` | **184** |
| Declared in working tree | **193** (+9 uncommitted, from other work — not this module) |
| In both | **183** |
| **Live-only — a desired-state deploy would offer to DELETE these** | **101** |
| Declared-only — would be built on deploy | **10** |

Live-only, by collection: `tripLogs` 10 · `crmApplications` 9 · `vehicles` 6 · `transportExpenses` 6 ·
`studentRoutes` 5 · `routeAssignments` 4 · `routes` 4 · `visitPasses` 4 · `driverIncidents` 3 ·
`campusAccessRequests` 3 · `temporaryDriverAssignments` 3 · `transportAuditLog` 2 · and the tail.
**Transport and CRM would take the worst of it.** The earlier figure of 105 is now **101** — nine new
declarations have landed since, four of which matched a live-only index. **The hazard has narrowed by
four and is otherwise unchanged.**

`[FACT|OBSERVED]` **Zero indexes exist, live or declared, for any of the six Document Engine
collections** (`documentTemplates`, `documentTemplateVersions`, `reusableBlocks`,
`complianceAuthorities`, `documentTypes`, `mergeFieldContracts`). P1.2 is genuinely undone, not
half-done.

> ⚠️ **TWO CAVEATS ON THE NUMBERS ABOVE, both of which weaken them:**
> 1. **The snapshot is from 2026-08-15 07:36 UTC — twelve days stale.** Anything deployed since is
>    invisible to it. These figures describe a *cached* state, not production right now.
> 2. **`aegis/cli.js` NO LONGER EXISTS.** `~/Desktop/Zennxii_adminPanel/aegis/` contains only
>    `.state/` and `.reports/`; the tool source is gone. **The repo `CLAUDE.md` still instructs
>    `node aegis/cli.js indexes` as the way to verify this, and that command cannot run.** A live
>    re-read needs the tool restored or the Firestore Admin API called directly.
>
> **This is why the drift figure keeps being quoted rather than re-measured** — and quoting it is
> exactly how a stale number becomes a fact. Restoring Aegis is a prerequisite for closing P1.2,
> not an unrelated chore.

### 8.2 ⚠️ CORRECTION — Aegis restored 2026-08-27, and the LIVE read refutes §8.1 on the point that mattered

`[FACT|OBSERVED — `node cli.js indexes status --fresh`, live against `graderadmin`, 2026-08-27]`
Aegis was reinstated as a **git worktree of the `aegis` branch** at `~/Desktop/zenxii-aegis`, and the
live board replaces every cached figure above.

| | Cached (2026-08-15) | **LIVE (2026-08-27)** |
|---|---|---|
| Composite indexes in production | 284 | **296** |
| Declared (disk = HEAD = origin) | 193 | **193** |
| Clean | 183 | **192** |
| Live-only | 101 | **104** |
| **Committed but NOT deployed** | *not measured* | **1** |

> ## 🔴 THE CORRECTION: AN INDEX DEPLOY DOES NOT DELETE ANYTHING.
> §8's whole framing — *"deploying the file as desired state would offer to **delete** those 105"* —
> **is wrong, and this document has repeated it since 2026-08-19.** Aegis states it plainly:
> **"an index deploy only creates, it does not delete undeclared ones."**
> The 104 live-only indexes are **not a deploy hazard at all.** Their defect is **reproducibility**: a
> staging or restored project built from `firestore.indexes.json` comes up missing them, and those
> queries fail there. Recover with `node cli.js indexes pull`.
>
> **The cost of that error was not theoretical.** It is why P1.2 was withdrawn on 2026-08-19, why the
> Document Engine has had no indexes for eight days, and why the Support Desk staged its work. The
> withdrawal was reasoned from a hazard that does not exist.
>
> ⚠️ **And the severity runs the OTHER WAY.** For indexes the emergency is a **committed index that is
> not deployed** — its query is failing *now*. A live index in no commit is untidy, not an outage.

**THE ONE REAL FINDING, and §8.1 could not have seen it:**

```
✖ NOT DEPLOYED   staffCapabilities [COLLECTION]   schoolId ↑, modules CONTAINS
    Support Desk P5 (2026-08-26) — supportDesk.js deskRecipients():
    which staff should be told a new ticket landed.
```

**Eight of the nine Support Desk indexes are already live; this one is not.** `deskRecipients()` will
fail with `FAILED_PRECONDITION` the moment `supportDesk.js` is deployed — so **this index must be
deployed BEFORE the Cloud Function**, which is the ordering `DEPLOY_RUNBOOK.md` already mandates
(backfills → indexes → rules + code).

**P1.2 SHOULD BE RECONSIDERED.** The reason it was withdrawn does not hold. The seven Document Engine
indexes can be declared and deployed; a deploy will create them and touch nothing else.

---

`documentTypes` and `mergeFieldContracts` are small platform collections fetched by key or scanned
whole — no composite index needed.

---

## 9. Rules obligations (P1.3) — not yet written

- `documentTypes`, `mergeFieldContracts`, `platformTemplates` — read: any authenticated school
  user; **write: platform super-admin only.** A school must not edit its way out of a requirement.
- `documentTemplates` — scoped on the `school_id` claim; write requires the capability grade.
  Published heads mutate only `activeVersion`, `status→archived`, `lockVersion`.
- `documentTemplateVersions` — **create-only.** `allow update, delete: if false;` for everyone.
- `reusableBlocks` — school-scoped read/write by capability.

⚠ `firestore.rules` is shared, and teammates deploy from their own machines. Run
`node aegis/cli.js rules status` **first**, keep the edit inside one `match` block, diff before
deploying.

---

## 10. Open

- `requiredKeys` for the CBSE profile — **pending G0.8 second-person sign-off**
- `calibrationMm` reserved but unused until stationery mode (v1.1)
- Kotlin `Constants.kt` additions — Teacher/Parent apps only read documents in a later phase
