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

  complianceProfileId: "cbse",
  complianceProfileVersion: 4,
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

---

## 4. `documentTemplateVersions/{schoolId}_TPL0007_v2` — create-only

The answer to *"show me the exact template that produced this certificate"*, years later.

```jsonc
{
  schoolId, templateId, docType, version: 2,
  snapshot: { page, header, footer, objects, languages, defaultLanguage },  // frozen, complete
  contractRef, complianceProfileId, complianceProfileVersion,
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

## 5. Compliance profiles — embedded in `documentTypes`

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
  objects: [ … ],
  usedByTemplates: ["TPL0007"],    // denormalised for propagation
  createdAt, updatedAt, updatedBy
}
```

---

## 8. Indexes (P1.2) — **declared, NOT deployed**

Written into `firebase-rules/firestore.indexes.json` alongside the existing 184.

| Collection | Fields |
|---|---|
| `documentTemplates` | `schoolId` ASC, `docType` ASC, `status` ASC |
| `documentTemplates` | `schoolId` ASC, `docType` ASC, `activeVersion` ASC |
| `documentTemplates` | `schoolId` ASC, `updatedAt` DESC |
| `documentTemplateVersions` | `templateId` ASC, `version` DESC |
| `reusableBlocks` | `schoolId` ASC, `blockType` ASC |

⚠ **Deploy these BEFORE any query code exists.** Index builds take time, and this project already
carries drift (284 live vs 183 declared). Verify with `node aegis/cli.js indexes`.

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
