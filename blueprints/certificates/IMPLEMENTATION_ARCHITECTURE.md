# ZenXii Certificate Designer — Implementation Architecture

**Status:** BUILD DOCUMENT — no code written, nothing committed, nothing deployed.
**Date:** 2026-08-17
**Reads with:** `FINAL_BLUEPRINT.md` (what and why) · `DOCUMENT_ENGINE_ARCHITECTURE.md` (storage ADR)
· `SCHOOL_DOCUMENT_ECOSYSTEM_RESEARCH.md` (compliance evidence)

This document is the *how*. Every API named here was verified against the codebase.

---

## 0. Architecture change — the layout engine got much simpler

The blueprint (§6.4) specced a **server-side layout pass**: measure auto-height text against real
data, then propagate displacement through anchor chains and emit final absolute Y positions. It was
named the hardest mechanic in the module and gated the whole build as Phase 0.1.

**It is unnecessary.** We do not need to measure text — the renderer already does.

**Anchor chains become flow containers.**

- An object with `anchorTo == null` is emitted absolutely positioned at its `(x, y)`.
- An **anchor chain** (a root plus everything transitively anchored to it) is emitted as **one
  absolutely-positioned container** at the root's `(x, y)`, whose members are ordinary **block
  children in document order**.
- Inside the container: `margin-top: {anchorGapMm}mm`, `margin-left: {member.x − root.x}mm`,
  `width: {member.w}mm`.
- `height: auto` → emit no height; the renderer measures and everything below flows down.
- `height: fixed` → emit an explicit height.

Block flow inside an absolutely-positioned container is well-supported in both mPDF and dompdf, so
this needs no engine features we lack.

**Consequences**

| Before | After |
|---|---|
| Server-side text measurement | None — renderer does it |
| Topological sort + displacement propagation | Emit order only |
| Two-pass render | Single pass |
| Phase 0.1 gate | **Removed.** Phase 0 keeps 0.2/0.3/0.4 |
| Layout can disagree with render | Structurally cannot |

The design-time model is unchanged: users still express intent as "this sits 4 mm below that."
Only the emission strategy changed. **This is the single biggest cost reduction available**, and it
should be reflected back into the blueprint's S6.4 and S9 before the blueprint is locked.

Residual: an unanchored object can still be overlapped by a grown chain. That is a **design-time
warning** computed on sample data, not a render-time algorithm.

> ### ⚠ G0.4 CORRECTION (2026-08-17) — overflow needs a second mechanism
>
> Gate G0.4 **validated** everything above: block-flow-inside-absolute, 4-deep chains, fixed+auto
> siblings, tables inside absolute containers. The simplification stands and Phase 0.1 stays removed.
>
> But it also proved a gap. **`$mpdf->page` cannot detect overflow of absolutely-positioned
> content** — a container whose content far exceeds the page bottom still reports `page == 1`,
> while identical content in normal flow paginates to 2. Absolute content does not paginate; it
> **silently clips**. A TC with a long `reasonForLeaving` would lose its signature block with no
> error.
>
> **Two-tier detection is therefore mandatory:**
>
> 1. **Flow region** — `$mpdf->page` gate. Verified working.
> 2. **Absolute chains** — `measureBlock()`: render the chain in flow on a scratch doc with an
>    un-paginatable page height and read the `$mpdf->y` delta as its natural height in mm; throw
>    `E_PAGE_OVERFLOW` when `topMm + heightMm > pageHeight − bottomMargin`. Probe-verified
>    monotonic (15.15 → 71.79 mm across 1–16 content reps).
>
> This does **not** reinstate the removed layout pass: positioning still comes from flow containers
> and the renderer. mPDF is used purely as a **measuring device for validation**, on auto-height
> chains only, at proof/publish time — never per keystroke.

---

## 1. Component map

```
┌─ BROWSER ────────────────────────────────────────────────────────┐
│  designer.js      canvas: select/drag/resize/snap/multi-select    │
│  objects.js       object model + undo/redo (command stack)        │
│  textedit.js      Quill 2.0.3 + MergeField blot + i18n            │
│  compliance.js    live rule panel                                 │
│  api.js           fetch wrapper — checks r.ok AND {status}        │
└────────────────────────────┬─────────────────────────────────────┘
                             │ AJAX (CSRF-excluded POSTs)
┌────────────────────────────▼─────────────────────────────────────┐
│  Doc_templates.php          controller — thin, auth + validation  │
│  ├─ Doc_template_service    CRUD, versioning, activate, locking   │
│  ├─ Doc_compliance          profile resolution + validation       │
│  ├─ Doc_serializer          model → HTML  (ONE serializer)        │
│  └─ Doc_renderer            HTML → PDF via mPDF (clean path)      │
└────────────────────────────┬─────────────────────────────────────┘
                             │
┌────────────────────────────▼─────────────────────────────────────┐
│  Firestore   documentTypes · documentTemplates · reusableBlocks   │
│  Storage     schools/{schoolId}/doctemplates/**                   │
└──────────────────────────────────────────────────────────────────┘
```

**Naming:** `Doc_templates`, not `template_designer` — `Result.php:112` already owns
`result/template_designer`, which is a **marks-scheme** editor. Legacy `Certificates.php` is left
untouched (ADR D6: replace alongside).

---

## 2. File layout

```
application/
  controllers/Doc_templates.php
  libraries/
    Doc_template_service.php
    Doc_compliance.php
    Doc_serializer.php
    Doc_renderer.php
  config/
    doc_types.php                     # platform seed for documentTypes
    numbering.php                     # ADD document kinds (currently zero)
  views/doc_templates/
    index.php                         # SPA shell: gallery + designer
    _canvas.php  _inspector.php  _compliance_panel.php
assets/
  js/vendor/quill.js  quill.snow.css  # self-hosted UMD (Chart.js precedent)
  js/doctemplates/
    designer.js  objects.js  textedit.js  compliance.js  api.js
  css/doctemplates.css
  fonts/noto/                         # 8 scripts, SIL OFL
```

No bundler exists in this repo and none is introduced. Plain `<script>` includes, self-hosted.

---

## 3. Data layer

### 3.1 Collections

| Collection | Key | Scope |
|---|---|---|
| `documentTypes` | `{typeId}` | platform |
| `platformTemplates` | `{templateId}` | platform (cloned on use) |
| `documentTemplates` | `{schoolId}_TPL0007` | school |
| `reusableBlocks` | `{schoolId}_BLK0003` | school |

School-scoped docs use `$this->fs->docId($entityId)` → `{schoolId}_{entityId}`, matching ZenXii
convention.

### 3.2 Template document

```jsonc
{
  schoolId: "SCH_D94FE8F7AD",
  docType: "transfer_certificate",
  name: "TC — main letterhead",
  status: "draft|published|archived",
  version: 3,
  lockVersion: 17,
  isActive: false,
  complianceProfileId: "cbse",
  contractVersion: 3,
  languages: ["en","hi"],
  defaultLanguage: "en",

  page: { size:"A4", orientation:"portrait",
          marginsMm:{ t:15,r:15,b:15,l:15 } },

  objects: [
    { id:"obj_7", type:"text",
      xMm:20, yMm:45, wMm:170, hMm:12, z:3,
      height:"auto",
      anchorTo:"obj_6", anchorGapMm:4,
      locked:false,
      requiredKey:"tc.reasonForLeaving",
      style:{ fontFamily:"notosans", sizePt:11, weight:400,
              colour:"#000", align:"left", lineHeight:1.4 },
      content:{ i18n:{ en:{ ops:[…] }, hi:{ ops:[…] } } } }
  ],

  proofPdfHash:"sha256:…",
  createdBy, updatedBy, publishedBy, publishedAt, updatedAt
}
```

**Object types:** `text · image · shape · table · qr · pageNumber`.
`requiredKey != null` ⇒ **not deletable**, and publish blocks while unbound.

### 3.3 Storage

```
schools/{schoolId}/doctemplates/{templateId}/{assetId}.{ext}
```

Templates store **Storage refs, never URLs** — signed URLs expire and would break later renders,
and arbitrary URLs make the renderer an SSRF primitive (§9).

### 3.4 Indexes — deploy BEFORE code

```
documentTemplates:  schoolId + docType + status
documentTemplates:  schoolId + docType + isActive
documentTemplates:  schoolId + updatedAt DESC
reusableBlocks:     schoolId + blockType
```

Index drift is a live problem in this project (284 live vs 183 declared). Declare in
`firestore.indexes.json` and deploy early — index builds take time.

---

## 4. Controller and routes

`Doc_templates extends MY_Controller`. Thin: auth, validate, delegate, respond.

```php
public function __construct() {
    parent::__construct();
    require_permission('Certificates');          // NOT _require_role() — see §8
    $this->load->library('doc_template_service', null, 'tpl');
    $this->tpl->init($this->fs, $this->school_id, $this->session_year);
}
```

| Route | Method | Capability |
|---|---|---|
| `doc_templates` | GET | view |
| `doc_templates/gallery/(:any)` | GET | view |
| `doc_templates/design/(:any)` | GET | edit |
| `doc_templates/get_types` | GET | view |
| `doc_templates/get_templates` | GET | view |
| `doc_templates/get_template` | GET | view |
| `doc_templates/create` | POST | edit |
| `doc_templates/save` | POST | edit |
| `doc_templates/validate` | POST | edit |
| `doc_templates/preview` | POST | edit |
| `doc_templates/proof_pdf` | POST | edit |
| `doc_templates/publish` | POST | **manage** |
| `doc_templates/activate` | POST | **manage** |
| `doc_templates/archive` | POST | manage |
| `doc_templates/upload_asset` | POST | edit |
| `doc_templates/blocks/*` | GET/POST | view / edit |

> ### ⚠ CSRF trap
> **Every POST above must be added to `csrf_exclude_uris` in `application/config/config.php`.**
> CodeIgniter's global CSRF rejects unlisted AJAX POSTs with a **blank 403** — no log, no console
> error. This has bitten this codebase repeatedly.

**Response contract** — `json_success(['data' => …])` / `json_error($msg, $code)`. The front-end
helper must check **`r.ok` AND `{status:'error'}`**; `fetch()` does not reject on 403/500, and a
denied action otherwise reports as success.

---

## 5. Services

### 5.1 `Doc_template_service`

```php
init($fs, string $schoolId, string $session): self
list(string $docType = '', string $status = ''): array
get(string $templateId): ?array
createBlank(string $docType, string $name): string
cloneFrom(string $sourceId, bool $fromPlatform, string $name): string
save(string $templateId, array $objects, array $page, int $lockVersion): array
publish(string $templateId, string $proofPdfHash): array
activate(string $templateId): bool          // atomically deactivates siblings
archive(string $templateId): bool
```

**Locking.** `save()` compares `lockVersion`; a stale write returns
`['conflict' => true, 'current' => …]` rather than overwriting. Two admins on one template must
never silently clobber each other — this becomes a legal artefact.

**Activation is a transaction.** Exactly one active per `(schoolId, docType)`. Deactivate siblings
and activate the target atomically — Firestore transactions span the whole database, which is
precisely why the ADR chose it over RTDB.

**Publishing is immutable.** `published` templates accept changes only to `isActive`,
`status→archived`, `lockVersion`. Editing a published template creates **v+1**.

**IDs** come from `Numbering_service`, never inline counters:

```php
$this->load->library('numbering_service', null, 'numbering');
$this->numbering->init($this->fs, $schoolId, $session);
$id = $this->numbering->next('doc_template');    // → TPL0007
```

Add to `application/config/numbering.php` (which today holds **zero** document kinds):

```php
'doc_template'  => ['prefix'=>'TPL','padWidth'=>4,'gaplessClass'=>'INTERNAL','periodScope'=>'none'],
'doc_block'     => ['prefix'=>'BLK','padWidth'=>4,'gaplessClass'=>'INTERNAL','periodScope'=>'none'],
```

> Note: `gaplessClass` is currently **declarative only** — it appears once in
> `Numbering_service.php:208` inside `describe()` and nothing enforces it. Fine for `INTERNAL`
> kinds. It must be made real before *issued-document* numbering (Document Engine, not this build).

### 5.2 `Doc_compliance`

```php
resolveProfile(string $docType, string $board, string $state): array
validate(array $template, array $profile): array
   // → ['blocking'=>[…], 'warnings'=>[…], 'info'=>[…]]
requiredKeys(array $profile): array
```

Resolution: `board + state → profile`, else **`generic`** — which enforces nothing and says so.
We never invent a requirement to fill a gap.

`validate()` returns findings carrying `authority`, `evidenceLevel`, `verifiedOn` so the UI can
render *why*. Publish blocks on `blocking` only.

### 5.3 `Doc_serializer` — one serializer, two sinks

```php
render(array $template, array $data, string $lang, array $opts = []): string
```

Same output feeds browser preview and mPDF. There is no second "preview CSS". Divergence is a bug.

**Emission rules**

1. Root `<div class="zx-tpl-{templateId}">`, page box sized in mm from `page`.
2. **All CSS namespaced under that root.** Bare element selectors are forbidden. (`.att-grid`
   collided with a card-grid utility here before — namespacing is not optional.)
3. Unanchored object → `position:absolute; left/top/width` in mm.
4. **Anchor chain → one absolute container; members are block children** (§0).
5. `height:auto` → omit height. `height:fixed` → explicit.
6. **No flex, no grid, ever.**
6a. **Every text object MUST emit an explicit `line-height`.** *(G0.5, blocking.)* With it, mPDF
   and Chrome agree **exactly** — 92/92 probes across 23 widths × 4 scripts, 0.00 mm divergence.
   Without it each engine uses its own font-derived default leading and agreement collapses:
   Tamil measured **18.03 mm in mPDF vs 9.53 mm in Chrome** on one block (~2× out), Devanagari
   −2.77 mm, Latin +1.95 mm — and the error compounds down an anchor chain. A template may not
   leave `line-height` unset.
7. Quill Delta → HTML with merge fields resolved for `$lang`.
8. `opts['sample']` renders sample values; production render resolves real data.

**Fail closed.** Unresolved placeholder or contract mismatch ⇒ **throw**. A document must never
print a literal `{student_name}` — that is an embarrassment and a forgery vector. This deliberately
reverses `Certificates.php`, which substitutes `''` and prints a blank.

### 5.4 `Doc_renderer` — mPDF, clean path

```php
render(string $html, array $page): string    // PDF bytes
proof(array $template, string $lang): array  // ['pdf'=>…, 'hash'=>…]
```

**Do not call `Pdf_generator::render()`.** It injects **184 lines** of report-card CSS
(`.rc-* .cb-* .mn-* .md-* .el-*`) plus `body{font-size:11px}` into every PDF and hardcodes
`setPaper('A4','portrait')`. Certificates must not inherit that.

```php
$mpdf = new \Mpdf\Mpdf([
    'mode'        => 'utf-8',
    'format'      => $page['size'],            // from template, never hardcoded
    'orientation' => $page['orientation'] === 'landscape' ? 'L' : 'P',
    'margin_left' => $page['marginsMm']['l'],  // …t/r/b
    'useOTL'      => 0xFF,                     // complex-script shaping — bit 0x80
    'useSubstitutions' => false,
    'fontDir'     => [ FCPATH.'assets/fonts/noto' ],
    'fontdata'    => $this->_fontdata(),
    'tempDir'     => APPPATH.'cache/mpdf',     // must be writable
]);
```

Existing dompdf paths (Result, Accounting, Sis, Admission_public) are **untouched** — zero
regression risk.

---

## 6. Fonts

> **CORRECTED 2026-08-17 by gate G0.2 — Noto Sans is NOT usable with mPDF.**
> Every current Google-Fonts Noto TTF (including Latin `NotoSans-Regular`) throws
> `GPOS Lookup Type 5, Format 3 not supported` or `contains MarkGlyphSets` from
> `TTFontFile::_getCoverage()` — a hard exception at registration, not a degraded render.
> mPDF's parser is fine: its bundled fonts, including the Indic `Lohit-Kannada.ttf`, all parse.
> **The family is Lohit.** Verified: all 7 Indic scripts embed as distinct subsets.

Bundle **Lohit** per Indic script under `assets/fonts/lohit/` (OFL 1.1 — free commercial
redistribution): Devanagari (Hindi + Marathi), Tamil, Telugu, Gujarati, Bengali, Kannada,
Malayalam. **Latin uses mPDF's bundled `DejaVuSans`** — Lohit has no Latin coverage.

Source: the Debian package pool (`fonts-lohit-*`); GitHub raw and pagure.io were rate-limited
(429/503). See `tests/doctemplates/gate0/fetch_lohit.sh`.

```php
'lohitdeva' => ['R' => 'Lohit-Devanagari.ttf', 'useOTL' => 0xFF],
'lohittaml' => ['R' => 'Lohit-Tamil.ttf',      'useOTL' => 0xFF],
// … one per script; Latin resolves to the bundled dejavusans family
```

⚠ **Lohit ships Regular only — there is no Bold face.** mPDF synthesises bold. Templates that
depend on a true bold weight for Indic text will not get one; if that proves unacceptable at UAT,
a per-script bold source must be found before launch.

- `useOTL = 0xFF` per family; bit `0x80` is the one complex scripts need.
- Keep subsetting on — the PDF embeds only used glyphs, so output stays small.
- `ttfontdata/` cache must be writable and warms once per family.
- **Prefer OTL-v2 fonts** (`dev2`, `bng2`, `tml2` script tags). mPDF supports v1-era fonts but
  renders them worse.
- **Verify per script with a real proof render** (Phase 0.2) — conjuncts and matras, not just
  "text appears".

---

## 7. Front-end

No bundler. Plain ES modules via `<script type="module">`, self-hosted Quill UMD.

### 7.1 Object model + undo/redo (`objects.js`)

Single in-memory `TemplateModel`. All mutations go through a **command stack** —
`{do, undo, coalesceKey}`. Drag emits one coalesced command per gesture, not per mousemove.
Undo/redo is unusable if built any other way, and retrofitting it is a rewrite.

### 7.2 Canvas (`designer.js`)

- Absolutely-positioned **DOM nodes**, not `<canvas>` — text must stay real DOM for Quill.
  (This resolves blueprint Q10: a `<canvas>` library is ruled out by that requirement.)
- mm is the storage unit; px only for display. `pxPerMm = zoom * 96/25.4`.
- Snap guides: object edges/centres + page centre + margins, threshold in **px** so snapping feels
  constant across zoom levels.
- Multi-select: marquee + shift-click; align/distribute operate on the selection.
- Z-order via `z`, normalised on save.
- `requiredKey != null` ⇒ delete is refused with the citation shown.

### 7.3 Quill + merge fields (`textedit.js`)

Quill mounts **in place** on the selected text object.

```js
const Embed = Quill.import('blots/embed');
class MergeField extends Embed {
  static blotName = 'mergeField';
  static tagName  = 'SPAN';
  static className= 'zx-mf';
  static create(v){ const n=super.create();
    n.setAttribute('data-key', v.key); n.setAttribute('contenteditable','false');
    n.textContent = v.label; return n; }
  static value(n){ return { key:n.getAttribute('data-key'), label:n.textContent }; }
}
Quill.register(MergeField);
```

Embed blots are **void nodes** — Quill does not traverse their children — so a cursor placed
mid-token cannot corrupt `{student_name}`.

**i18n:** one Quill instance, swapping Deltas. Switching language does
`getContents()` → store under old lang → `setContents(delta[newLang])`. Merge fields are
language-independent; only surrounding text is translated.

### 7.4 API helper (`api.js`)

```js
const r = await fetch(url, {method:'POST', body:fd});
if (!r.ok) throw new ApiError(r.status);
const j = await r.json();
if (j.status === 'error') throw new ApiError(j.message);   // fail closed
```

Both checks are mandatory — this codebase has a documented phantom-success bug class.

---

## 8. RBAC

| Action | Capability |
|---|---|
| View gallery/templates | `has_permission('Certificates','view')` |
| Create/edit/save | `edit` |
| Publish / activate / archive | `manage` |
| Edit compliance profiles | **platform super-admin only** |

Use `has_permission()` / `require_permission()`. **Do not use `_require_role()`** with hardcoded
role-name arrays — that is the known Layer-2 gate that blocks custom roles, and legacy
`Certificates.php` is full of it.

A school must never be able to edit its way out of a legal requirement.

---

## 9. Security

| Threat | Control |
|---|---|
| **SSRF** — mPDF fetches remote images server-side | Storage refs only; whitelist enforced **in `Doc_renderer`**, not the UI. Reject anything not resolving under `schools/{schoolId}/`. |
| Stored XSS via Quill HTML | Sanitise server-side on save (allow-list of tags/attrs). Never trust client HTML. |
| Cross-tenant read | Every query goes through `schoolWhere()`/`sessionWhere()`; rules scope on the `school_id` claim |
| Tampering with a published template | Rules permit only `isActive`, `status→archived`, `lockVersion` |
| Path traversal in asset upload | `safe_path_segment()` on every segment |
| CSS collision breaking the panel | Namespaced emission under `.zx-tpl-{id}` |
| Blank-403 CSRF | All AJAX POSTs in `csrf_exclude_uris` |

**Firestore rules** live in a shared file with concurrent editors and teammates deploying from
their own machines. Run `node aegis/cli.js rules status` first, keep the edit inside one `match`
block, diff before deploying.

---

## 10. Build order

Phase 0.1 is **removed** by §0. Remaining gates:

| # | Task | Why first |
|---|---|---|
| **0.2** | mPDF proof render, **all 8 scripts**, real conjuncts + matras | Gates `CON-MULTILINGUAL` and the renderer choice |
| **0.3** | Transcribe CBSE Annexure-I from the **source PDF** | Gates the only verified profile v1 ships |
| **0.4** | mPDF memory/time spike on an Ohio-class box | Box has OOM history |

Then:

1. **Foundation** — collections, indexes (deploy first), rules, numbering kinds, `Doc_renderer`
   clean path, controller skeleton + CSRF entries
2. **Serializer** — model → HTML incl. anchor-chain containers; golden-file tests
3. **Canvas core** — object model, command stack, drag/resize/snap, multi-select, align/distribute,
   rulers/zoom/grid/mm entry
4. **Text + binding** — Quill, MergeField blot, field picker bound to the contract
5. **Compliance** — profiles, `requiredKey`, panel with authority + evidence level
6. **Publish pipeline** — proof PDF + hash, versioning, activate, `lockVersion`
7. **Language** — font registration, per-object i18n, untranslated report
8. **Blocks + starters** — reusable blocks, authored starter templates
9. **Hardening** — per-script render suite, concurrency, overlap warnings, UAT

**Build the serializer before the canvas.** It is testable headlessly with golden files, it is what
the print points ultimately consume, and a canvas built against an unproven serializer bakes in its
mistakes.

**Deploy discipline:** rules, indexes and Cloud Functions deploy **separately** from PHP; indexes
early. Work on `yug_testing` — `yug_b1_t` is the live AWS branch and is never worked on directly.
Nothing deploys without explicit per-change permission.

---

## 11. Open items carried in

- **Q1** Does CBSE mandate field **order**, or only presence? Presence-only is implemented;
  an order rule is addable per-profile without a model change.
- **Q2** Annexure-I field list — transcribe from source (0.3).
- **Q3** All 8 scripts through mPDF (0.2).
- **Q6** Market picture — operator chose HYPOTHESIS; S11 stays provisional.
- **SUG-004** Delete the 15 MB EOL, unpatched, web-served CKEditor 4.12.1 from `tools/` —
  referenced by zero code. Separate change, not inside this build.

## 12. Status

**Nothing implemented.** No files changed, no rules edited, no indexes declared, no deploy.
