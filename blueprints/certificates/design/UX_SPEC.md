# ZenXii Certificate Designer — UX & Interface Specification

**Status:** DESIGN — nothing implemented, nothing committed, nothing deployed.
**Date:** 2026-08-18
**Scope:** v1 designer surface only — type picker → template gallery → canvas designer → proof →
publish. Issuance, the register, revocation and public verification belong to the Document Engine
and are **out of scope here** (ADR §13.3, `CON-NO_PRINT_IMPL`).
**Companions:** `design/prototype.html` — a working prototype of everything below.
`design/REFERENCE_RESEARCH.md` — the Figma / Canva / document-builder study this is grounded in.
`design/FIGMA_STUDY.md` — Figma audited subsystem by subsystem, each with an ADOPT / ADAPT / REJECT
decision and the resulting implementation backlog.
`design/FIGMA_ARCHITECTURE_STUDY.md` — Figma's *architecture* (auto layout, components, variables
and modes, libraries, branching, Dev Mode) against ours. Found two contradictions inside the
blueprint; §1 and §2 there resolve them.
`design/ASSETS_AND_CAPABILITIES.md` — images, signatures and print quality; the IT Act finding on
scanned signatures; and a ranked map of what else this module could do.
`design/COMPLIANCE_ARCHITECTURE.md` — **compliance as a stack** (national ∪ central board ∪ state)
rather than one profile, the authorities actually seeded, and the Kerala primary-source findings.
Open it directly, or the published copy at the artifact URL.

---

## 0. Why this document exists

`FINAL_BLUEPRINT.md` decides *what* ships (S5 feature catalogue) and *why* (S3, S4, S10).
`IMPLEMENTATION_ARCHITECTURE.md` and `COLLECTION_SHAPES.md` decide *how it is built*.
Neither says what any of it **looks like or how it behaves under the hand** — and this module has
five mechanics that cannot be built correctly from a feature list, because their whole difficulty
is in the interface:

| Mechanic | Why a feature list is not enough |
|---|---|
| Required objects (`requiredKey`) | "Cannot be deleted" is a rule. The *product* is how the refusal reads. |
| Anchor chains (`anchorTo` + gap) | An invisible relationship the clerk must be able to see, or they will fight it. |
| Auto-grow / overflow / overlap | Final geometry exists only at render time. The designer has to show a future. |
| Header/footer regions | They look like objects but obey different rules. Nothing on screen says so. |
| Mandatory `lineHeight` | A blocking validation on a field most people would never think about. |

This spec covers those first, then the ordinary screens.

**Design constraints inherited, not negotiated:** house tokens from
`tools/css/header-inline.css`; no bundler; no `<canvas>` (text must stay real DOM for Quill);
capability RBAC via `has_permission()`, never `_require_role()`; every AJAX POST registered in
`csrf_exclude_uris`.

---

## 1. Design language

### 1.1 Palette

Base tokens are the panel's, unchanged — the designer must not look like a different product:

```
--gold / clay   #BC5A3C   brand, primary action, selection
--gold2         #9E4830   pressed, sidebar gradient
day             bg #F7F2ED · card #FFFFFF · t1 #2A1C14 · t2 #6B5346 · t3 #9E8578
night           bg #17100C · card #201711 · t1 #F4E9E2 · t2 #C9B3A8
type            Syne (display) · Plus Jakarta Sans (body) · JetBrains Mono (data)
radius          12 / 8 / 5      ease .22s cubic-bezier(.4,0,.2,1)
```

**One token is new, and it is the most important decision in this spec:**

```
--seal          #A8322A (day) · #E4695E (night)     statutory red
--seal-dim      9% tint            --seal-ring      30% tint
```

A crimson deliberately pushed *off* the clay hue. Everything legally load-bearing — required
objects, evidence-level badges, publish blockers, the refusal card — is drawn in `--seal` and
**nothing else is**. Clay stays the brand and the primary button. The result: a Principal can scan
the screen and see what carries legal exposure without reading a word, and no marketing accent can
ever be mistaken for a compliance signal.

It is also the right red historically — TNER r.44 requires a duplicate TC to be marked *"in red
ink"*. The tool's compliance colour is the ink schools already use for this.

### 1.2 Semantic colours (separate from the accent)

`--ok` bound / passing · `--warn` warnings that do not block · `--seal` blocking · `--info`
computed fields and anchor relationships. Four meanings, four colours, no overlap with clay.

### 1.3 Type roles

| Role | Face | Used for |
|---|---|---|
| Display | Syne 700/800, `-0.02em` | Screen titles, modal titles, template names |
| Body | Plus Jakarta Sans 400/600/700 | Everything readable |
| **Data** | JetBrains Mono, `tabular-nums` | **Every millimetre, every merge-field key, every evidence level, every hash, every version number** |

The mono is not decoration. It is the tell that this is an instrument: a clerk reading
`tc.reasonForLeaving` in mono understands it is a machine name and not a label they can retype.

### 1.4 Paper is paper in both themes

The A4 page keeps `--page:#FFFFFF` and near-black ink in day **and** night. Only the desk around it
changes. Inverting the page in dark mode would make the designer lie about what prints — and the
whole architecture rests on preview and PDF agreeing.

### 1.5 Motion

Almost none. Transitions on hover/border/background at `--ease`. No entrance animations, no
page-load choreography. Two exceptions, both functional: the proof-render progress bar (it is
communicating real server work) and the toast. `prefers-reduced-motion` disables both.

---

## 2. Screen map

```
D0  Document types  ─┬─►  D1 Template gallery (per type)  ──►  D2 Designer
                     │                                            │
                     │                          ┌─────────────────┼──────────────────┐
                     │                       D3 Proof        D4 Publish       D5 History
                     │                                            │                 │
                     └──────────────────────────────────  D6 Compliance detail  D7 Conflict
```

Breadcrumb is the only back-navigation: `Certificates › Transfer Certificate › TC — main
letterhead [Draft v3]`. No browser-back dependency; the designer is a single CI3 view with
client-side screen switching (`views/doc_templates/index.php` as an SPA shell, per
`IMPLEMENTATION_ARCHITECTURE.md` §2).

---

## 3. D0 — Document types

**Job:** pick a type, and see at a glance whether that type is in a healthy state.

Grid of cards, `minmax(268px, 1fr)`. Each card carries, in this order:

1. Type name (display face) + **alias line** — `School Leaving Certificate`,
   `Conduct Certificate`, `present-tense · no statutory basis found`.
   The alias line is doing real work: S7 of the ADR resolved nine synonym clusters, and the office
   calls these documents by whatever their state calls them. A clerk looking for "SLC" must find
   the TC card.
2. **A schematic glyph** — a 36×44 miniature of the active template's geometry (§4.2).
3. Three metadata rows: compliance profile · active template · template count + last edited.
4. Status chips: `Statutory format` (seal) or `Free format`; `Active` or `No active template`.

Below a divider: **Not enabled for this school**, rendered at 55% opacity — Study Certificate,
Migration Certificate, Fee Receipt, each with a one-line reason
(`board-issued — never merged with TC`, `needs repeating rows — v2`).

> **Why show disabled types at all.** It answers "can this system do X?" without a support ticket,
> and it pre-empts the request to merge TC and Migration Certificate, which the research explicitly
> forbids. The reason line is the product doing its own FAQ.

**Types are basis-dependent.** The Kerala Certificate of School Education (KER r.22A) appears only
for a school in Kerala; the Study Certificate only in Andhra Pradesh. A type that does not apply is
shown disabled **with the reason** — *"Applies in Andhra Pradesh — this school is in Kerala"* —
rather than hidden, so the answer to "can this system do X?" never requires a support ticket.

**States:** no types enabled → single empty card with a link to school settings. A type whose
profile has gone stale → amber ring on the profile row.

---

## 4. D1 — Template gallery

**Job:** get to a good starting point in one click. Persona P1 (office clerk) will almost never
open a blank canvas — starter quality is the product here.

Two sections, in this order, deliberately:

1. **Your templates** — draft / published / active, newest first.
2. **Starters — cloned into your school, never linked.** The subtitle is the whole contract: a
   starter is copied on use, so a platform edit can never mutate a school's published document.

Plus a **Blank canvas** card in the starters row, rendered as a hatched sheet with a `＋`. It is in
the starters row, not first, because putting "start from nothing" first is a bad default for P1.

### 4.0 Starters are authored, and filtered by basis

Five real starter templates exist, each declaring the basis it is written for; the gallery offers
only the matching ones. A Kerala school on the State Board sees the **Certificate of School
Education** (wording transcribed from KER r.22A) and the plain TC; a CBSE school in Jharkhand sees
the Annexure-I form and the plain TC, and no Kerala certificate at all.

Where nothing is written for a type under this basis, the section says so and points at the blank
canvas rather than showing an empty row.

### 4.1 Card anatomy

`210:297` preview · name · mono metadata line (`draft v3 · published v2 · edited 2 days ago`) ·
status chips. Chips are the state model, literally: `Draft v3` (warn), `v2 active` (ok),
`3 strings untranslated` (neutral).

### 4.2 Schematic previews — a design decision that saves an infrastructure decision

`FINAL_BLUEPRINT.md` §6.6 rules out rendered thumbnails: PDF→PNG needs imagick/ghostscript, which
is unverified on the Ohio box. The blueprint's answer was "metadata cards", which makes a gallery
of identical grey rectangles.

**Instead: draw the object rectangles.** For each object emit an absolutely-positioned `<i>` at
`x/w ÷ 210`, `y/h ÷ 297` as percentages. Required objects render in `--seal`, rules/lines in a
darker ink, everything else in 16% ink. Seals get `border-radius:50%`.

- Zero server cost, zero new dependencies, instant, and always current.
- It shows what actually differs between two templates — the layout — which a rendered thumbnail at
  230px wide would not legibly show anyway.
- It makes the compliance story visible in the gallery: the red marks *are* the statutory fields.

Same routine renders the D0 glyph and the D3 proof pane. One function, three sizes.

---

## 5. D2 — The designer

```
┌ topbar 54 ───────────────────────────────────────────────────────────────────┐
│ brand · breadcrumb + status chip        lang | coverage | data mode | ↺ ↻     │
│                                         History · Proof PDF · Publish · ◐     │
├ rail 232 ─┬─ desk (fluid) ──────────────────────────┬─ inspector 316 ─────────┤
│ Insert    │  ruler                                  │ ▾ Object                │
│ Fields    │  ┌──────── A4 page ────────┐            │   geometry / flow / type│
│ Blocks    │  │ header band              │           │   binding / lock / z    │
│           │  │ ...objects...            │           │ ▸ Page                  │
│           │  │ footer band              │           │ ▾ Compliance            │
│           │  └──────────────────────────┘           │   profile + rules       │
├ status 30 ┴─────────────────────────────────────────┴─────────────────────────┤
│ zoom · selection coords · data mode · findings · save state + lockVersion     │
└───────────────────────────────────────────────────────────────────────────────┘
```

Compliance is **stacked below the inspector, not a tab beside it**. P2 (Principal) is the reader of
that panel and the approver of the document; a tab would let it be out of sight at the moment it
matters. Both sections are collapsible; compliance is open by default and its header carries a
live badge (`3 blocking` / `Clear`).

### 5.1 Left rail — panes, not a toolbar

`Layers · Insert · Fields · Blocks · Content`.

- **Insert** — six object tiles (`text · table · image · shape · qr · pageNumber`), each with a
  one-line mono subtitle naming its behaviour (`rich · auto-grow`, `storage ref`, `footer only`).
  Below: **Align selection** (left / centre / right / to page centre) and **View** (grid, anchors).
- **Fields** — the merge-field picker, bound to the type's declared contract and nothing else.
  Each row: mono key, plus tags — `calc` (computed by attendance/result, blue), `req` (required by
  the active profile, seal), `used` (already bound somewhere in this template).
  Header copy states the rule: *"There is no free-typed token — a placeholder that resolves to
  nothing is a forgery vector, so the picker is the contract."*
  Clicking inserts an atomic chip at the cursor of the selected text object.
- **Blocks** — reusable letterhead / signature / seal blocks with usage counts
  (`4 objects · used by 3 templates`), because editing one propagates.
- **Content** — the document read as a document: every object in reading order, prose editable
  in place, nothing movable. See §5A.4b. This is the pane the office clerk lives in.

### 5.2 The desk and the page

- Rulers in **mm** on both axes, ticks every 10 mm, labels in mono. mm is the storage unit; px is
  display only (`pxPerMm = zoom × 96/25.4`).
- Page: white, `--page-sh`, 2px radius, `overflow:hidden` — content that leaves the sheet is gone,
  exactly as it would be in print.
- **Margin frame** — dashed clay inset showing `marginsMm`.
- **Header and footer bands** — tinted, dashed-edged strips at top and bottom, labelled
  `HEADER · REPEATS EVERY PAGE` and `FOOTER` in 8px mono. Objects inside them are positioned
  relative to the band, not the page.
- **Grid** — 5 mm, toggled, drawn as two `linear-gradient`s at 7% ink.

> **Why the bands are drawn at all.** COLLECTION_SHAPES §3.2 records a real failure: letterhead
> placed as an absolute object with a `margin-top` shim collided with rows 1–4, because mPDF
> collapses margin-top on the first flow block and absolute content reserves nothing. The fix was
> making header/footer first-class. If the designer does not *show* that boundary, a clerk will
> drag the letterhead into the body and recreate the bug by hand.

### 5.3 Selection and manipulation

Audited against Figma subsystem by subsystem in `FIGMA_STUDY.md`; the rules below are the outcome.

**Deselection has three surfaces, and all three must work** — this was the defect that prompted the
audit. Blank paper, **the desk around the page**, and `Esc`. The desk is most of the screen; if it
does not listen, deselect reads as broken however correct the page handler is.

**Escape is staged.** Editing → commit, stay selected. Selected → clear the selection.
Nothing selected → return the tool to Move. Three presses always reach a neutral state and no
press ever loses work.

**Locked and hidden objects are not selectable on the canvas** and fall through to the marquee,
as in Figma. They stay reachable from the Layers panel and the right-click *Select layer* list —
otherwise a lock is decorative, which is worse than no lock.

**Right-click gives a *Select layer* list of everything under the cursor**, topmost first. A
certificate is dense and objects overlap — a seal under a signature rule, a table under a
declaration — and this is the only route to an object that is underneath another, or locked.

| Gesture | Behaviour |
|---|---|
| Click | Select one. Hairline clay border on hover with the object's name; 1.5px on select. |
| Shift-click | Add / remove from selection. |
| Drag on empty paper | Marquee; anything intersecting is selected. |
| Multi-selection | Drawn as **one dashed bounding box**, not N boxes. |
| `Alt`-drag | Duplicate as you drag. |
| `Tab` / `Shift Tab` | Cycle objects. Flat — we have no nesting to descend into, so `Enter` is spent on *edit this text* instead. |
| `⌘A` / `⌘⇧A` | Select all / invert. |
| Drag an object | Move. Snaps to margins, page centre, and sibling edges — **threshold in px (6px), not mm**, so snapping feels identical at 40% and 150% zoom. Guides draw as 1px clay lines. |
| Drag a handle | Resize. 8 handles; `w`/`h` in mm. A `height:auto` object refuses vertical resize — the content owns that dimension. |
| `↑↓←→` | Nudge 1 mm. `shift` → 10 mm. |
| `Delete` | Delete, unless a `requiredKey` is present (§6.1). |
| `Esc` | Clear selection. |
| `⌘Z` / `⌘⇧Z` | Undo / redo. |
| Drag from a ruler | Pull out a **guide**; live mm label; snapped to like any other edge; drag it back off the page to remove. |

**Undo is a command stack with per-gesture coalescing** — one drag emits one command, not one per
`mousemove`. `IMPLEMENTATION_ARCHITECTURE.md` §7.1 is right that retrofitting this is a rewrite;
the prototype does it from the first line.

An anchored object's Y is **not draggable** and its Y input is disabled with the reason on hover.
Dragging it horizontally still works. This is the only place the tool takes a freedom away for a
non-legal reason, so it says why.

### 5.3a Instruments borrowed wholesale

**Ruler guides.** Drag from either ruler, mm label while dragging, snapping in the same pass as
margins and object edges, drag off the page to remove, *Remove all guides* in the context menu.
They ship in v1 even though stationery mode is v1.1, because that is precisely what they are for:
laying a line at 96.5 mm and snapping the variable fields to the boxes already printed on a
security-paper TC book.

**Arithmetic in every millimetre field.** Figma's X/Y/W/H accept `+10`, `*2`, `(x/2)+6`. Ours accept
the same — `210/2`, `96.5+3` — because the alternative is a clerk with a calculator. A field that
cannot be evaluated says so and reverts rather than silently writing `NaN`.

**The full alignment set.** Left / horizontal centres / right / top / vertical centres / bottom,
plus distribute horizontally and vertically, on `⌥A ⌥H ⌥D ⌥W ⌥V ⌥S`. **With one object selected,
align acts on the printable area inside the page margins** — Figma's "align to parent", and the
fastest way to centre a title. The first draft shipped only the three horizontal buttons, which is
half a feature: a signature block is aligned vertically.

**Zoom to selection** (`⇧2`), alongside fit (`⇧1`) and 100% (`⇧0`). A 9 pt statutory field on an A4
sheet at fit-zoom is four pixels tall; checking one means getting close to it.

**Click versus drag when placing text.** Click creates an auto-grow box spanning to the right
margin; drag creates a fixed box at the size drawn. Figma's gesture exactly, and a good default.

**Click-through text editing.** While editing one text object, clicking another edits it directly —
no second double-click. A clerk translating a certificate edits eight objects in a row.

### 5.3b Flow is now an explicit state

The Flow section reads **Absolute** / **In flow (anchored)** as a two-button segment, not an
inference from whether `anchorTo` happens to be set. Figma makes the equivalent explicit with
*Ignore auto layout*; leaving it implicit is how a clerk ends up unable to explain why one object
moves with the text and another does not.

### 5.3c Conditional visibility

An object may carry `showWhen: <fieldKey>` — shown only when that field resolves to a value. It
renders with a dashed outline and an `IF` badge in the designer so a conditional object is never
mistaken for a missing one, and dims when the condition is false at the current sample data.

This is the cheap half of the "conditional sections" the blueprint defers to v2, and the compliance
corpus has already produced a case that needs it: CBSE's countersignature block applies only when
the originating board is not CBSE, which `COLLECTION_SHAPES` §5 already models as
`countersignature: { conditional: true, when: "origin_board != CBSE" }`.

### 5.3d Assets — drop, paste, replace

Drop a file on empty paper to place it; drop onto an existing image to **replace it and keep the
box** (Figma's drop-to-replace); paste from the clipboard; double-click an empty placeholder for a
file picker. The filename classifies the asset — `principal-signature.png` arrives as a *signature* —
because a clerk who just scanned three files should not classify each one by hand.

An image object is either a **static asset** (crest, signature, seal, watermark — uploaded once,
identical on every certificate) or **bound to an image-typed contract field** (student photograph,
verification QR — resolved per document). The inspector makes you say which, because the two look
identical in a layout and behave nothing alike at issuance.

Three checks run live, all warnings rather than blockers:

- **Print resolution** — `dpi = pixelWidth ÷ (widthMm ÷ 25.4)`, shown as *"76 dpi at 40 mm — soft in
  print"*, recomputed on resize because dpi is a function of printed size, not of the file.
- **Transparency** — a signature without an alpha channel prints as a white block over the ruled
  line, so it says so.
- **Empty placeholder** — it will print as an empty box.

**SVG is refused** — it is XML that can carry script, event handlers, XXE and `foreignObject`, and a
school's asset store is not where you want to test your sanitiser. PNG / JPEG / WebP, sniffed by
decoding rather than by extension, 8 MB ceiling. Production adds the ADR's rules: Storage refs never
URLs (mPDF fetches remote images server-side, so a URL field is an SSRF primitive), and EXIF
stripped on ingest — a scanned signature can carry the GPS of wherever it was photographed.

**On signatures, the copy is deliberate.** A scanned image is not an electronic signature under the
IT Act 2000 — s.3 means a DSC, s.3A means a Second-Schedule signature such as Aadhaar eSign, and
s.5 makes those equivalent to a handwritten one. An image is a picture of a signature. The panel
says so, because a school will otherwise believe the document is "digitally signed". Authenticity in
this product is carried by the verification QR, not by the PDF.

### 5.3e Duplicate marking, signatures

**The duplicate mark is the one rendering feature a statute specifies.** KER r.22, TNER r.44 and
CBSE r.8(vi) all require a reissue to be marked, and Tamil Nadu names the ink colour. So a template
with no mark **blocks publish**, with a one-click fix that inserts one in the prescribed colour; a
mark in the wrong colour warns, citing the rule. A status-bar toggle previews the document **as an
original or as a duplicate** — without it the mark is invisible chrome nobody positions or checks.

It reuses `showWhen`, driven by an issuance flag rather than merge data. TN's *"issued only once"*
is carried on the rule but not enforced here: that is a constraint on issuance, and this module does
not issue.

**Signatures** are checked two ways. A prescribed role with no block is blocking; blocks in the
wrong sequence warn, showing laid-out versus prescribed order. Warning, not blocking, because the
order is prescribed for the *form* and we have not verified that a differently-arranged horizontal
row is itself a defect — overstating it would be a Level D inference wearing a Level A citation.

**Drawing a signature** is offered wherever the asset kind is *signature*: a canvas pad that
auto-crops to the ink and emits a transparent PNG, which removes the white-block problem by
construction. The pad still carries the IT Act note — a drawn signature is exactly as much of a
legal signature as a scanned one.

### 5.4 Inspector

Sections in fixed order — geometry, flow, type, binding, lock, z-order, delete.

- **Geometry** — X / Y / W / H in mm, `step 0.5`, mono. Y disabled when anchored; H disabled when
  `height:auto`.
- **Flow** — a two-button segment: `Fixed height` / `Auto-grow`. Then `Anchor to` (a select of
  sibling ids) and `Gap mm`. Hint copy: *"An anchored object holds a **gap below** its neighbour,
  never an absolute Y. Real data decides the final position — which is why the p95 toggle matters."*
- **Type** — size pt, **line height**, weight, alignment. See §6.5.
- **Binding** — §6.1.
- **Lock** — `Unlocked` / `Position locked`, a *user* lock, visually distinct from the compliance
  lock. Locked objects get `cursor:not-allowed` and refuse drag but stay selectable and editable.

**Page section** exposes margins (editable), and — read-only in v1, but *visible* — `size`,
`orientation`, `pageMode: single`, `languageFallback: block`. Showing a disabled control with its
value is how the tool teaches its own model; hiding it makes `block` fallback a surprise at publish.

### 5.5 Status bar

`Zoom − 70% + fit` · selection readout (`t_reason · 15,191 mm · 180×10 mm · anchored to t_table`) ·
data mode · findings count · `Saved · lockVersion 17`.

The lockVersion is displayed permanently, in mono. It is the only warning a user gets that they are
editing a shared object, and it makes the conflict dialog (§9.4) legible when it fires.

---

---

## 5A. Interaction model

Grounded in `REFERENCE_RESEARCH.md`. The short version: **Canva's approachability and text model,
Figma's precision instruments, the document-automation category's merge-field discipline.**

### 5A.1 Five regions (Figma)

Tab strip · left panel · toolbar + canvas · right panel · status bar. The vertical tab strip is
separate from the panel it swaps — **Layers · Insert · Fields · Blocks** — so the panel content
changes instantly without leaving the design (Canva's Templates/Elements/Text/Brand/Uploads shape,
our content).

**Layers ships in v1.** `FINAL_BLUEPRINT` S5.3 defers "layers panel, grouping" to v2; the *list*
must not be deferred, because it is the only realistic keyboard-accessible path to selection on an
absolutely-positioned canvas. *Grouping* stays deferred. It also does the ordinary job: selecting
something tiny, stacked or behind something else. Rows are grouped by region — header / body /
footer — which teaches the region model for free, and each row carries type icon, name, an anchor
mark, a required mark, and show/lock toggles.

### 5A.2 A tool model, not a button rail

A floating tool pill sits at the top of the desk: **Move · Hand · Text · Table · Image · Rule · QR**,
each on a single-letter shortcut. Pick a tool, drag on the page to place at that size, and the tool
returns to Move. Placing a text object drops you straight into editing it — the alternative (place,
then hunt for how to type) is exactly the complaint that produced this revision.

The Hand tool exists for the same reason it exists in Figma: exploring a dense page without
accidentally moving a statutory field.

### 5A.3 The context toolbar carries the frequent controls

Selecting anything raises a floating toolbar **at the selection**, and its contents change with the
selection — text shows font, size, B/I/U, alignment, colour and **+ Field**; a shape shows
duplicate, lock, arrange, delete; a multi-selection shows align and distribute.

This is the single largest fix over the first draft. Putting every control in a far-right inspector
is right for a designer at a 27-inch monitor and wrong for a clerk on a 13-inch laptop changing one
line of text. Frequent controls come to the selection; **precise** controls — millimetre
coordinates, anchors, compliance bindings — stay in the inspector, which is what it is good at.

Control order follows Canva's own frequency ranking (font · size · style · alignment · colour),
with **+ Field** inserted where Canva has Effects, because inserting a merge field is our
highest-frequency text action.

### 5A.4 Text editing

- **Double-click** a text object, or press **Enter** with it selected, or use ✎ in the toolbar.
  The inspector's ✎ was **removed** — it duplicated the toolbar button, sat far from the object,
  and was the least discoverable of the four. It now reads *"¶ Edit in Content"* and opens §5A.4b
  instead, which is the better answer to what that button was for.
- The object becomes editable in place, at its real size and position on the page. No side pane,
  no modal.
- **Esc commits.** Clicking anything else commits. Switching language commits. Leaving the screen
  commits. There is no way to lose an edit by navigating away.
- While editing, merge fields render as **chips showing the field name**, never sample values —
  the author needs to see structure, not data. The moment editing ends they resolve back to
  whatever the data mode is showing.
- Bold/italic/underline are real inline runs; the model stores them per run.

**Implementation note that must not be lost:** the editable state has to be *declarative* — set
while the object node is built, not applied afterwards — or the next re-render silently drops it
and the user's keystrokes vanish into a dead node. Equally, any render while editing must harvest
the live DOM back into the model first, and restore the caret by character offset afterwards.
Both were real bugs in this prototype before they were rules.

### 5A.4b The Content pane — editing a certificate as a document

Canvas editing is right for a *designer*. It is wrong for the person the personas call P1 — the
office clerk fixing a typo in a declaration sentence. To change one word they had to find a 9.5 pt
target on a zoomable canvas, hit it precisely, enter a mode, edit, and leave the mode — and the
most likely misfire, **dragging the object instead of editing it**, silently moves a piece of a
legally-formatted document. A certificate is not a poster: it is read top to bottom, as prose and
labelled fields. The tool offered only the canvas view of it; it now also offers the document view.

- Lists **every** object in **reading order** — region (`header → body → footer`), then resolved Y,
  then X. Not z-order, not creation order. Reading order is the only order a clerk thinks in.
- **Always editable. No mode, no ✎, no double-click.** Click a line and type.
- **Nothing here can move, resize, reorder or delete.** Content only — so proofreading can no
  longer break the layout.
- Merge fields render as the **same chips** as on canvas, atomic and `contenteditable="false"`,
  inserted only from the Fields pane (§5A.5 stays the contract).
- Required objects carry the **seal marker (✦)** here too — compliance is visible while
  proofreading, which is exactly when it matters.
- Non-text objects (image, shape, rule, qr) appear as **greyed, non-editable rows**, preserving
  reading order without pretending they are text. Clicking one selects it on canvas.
- Selecting a row selects the object on the canvas, and the canvas keeps updating as you type —
  the two views are one selection with one live preview.
- **Edit all / Read** toggle. *Read* is a continuous-text proofreading view with region headings
  and no editable nodes at all, so a final check before publish cannot become an accidental edit.

**Commit model — this is the load-bearing part.** The model is updated on **every keystroke**
(`input`), not on blur. Two reasons, both learned the hard way:

1. *No edit can be lost.* Blur is not guaranteed — switching window, closing the tab, or any
   programmatic focus change can skip it. Chrome does not even dispatch `focus`/`blur` when the
   document lacks OS focus. Committing on `input` makes the model never lag the screen.
2. *It defuses the §5A.4 bug class.* Because the model is already current, a re-render that
   destroys the focused node can no longer take the keystrokes with it.

Undo must not follow suit: one entry per character is useless. The baseline snapshot is armed once
at the **start** of a typing burst (from `focus` *and* from the first `input`, since focus is
unreliable) and a **single** undo entry is pushed on blur, comparing against that baseline — not
against the model as it stood a moment earlier, which is always identical by then and would push
nothing at all.

The pane additionally **refuses to repaint underneath a live edit**: `paintContent()` harvests the
focused row into the model, sets a deferred flag, and returns; the repaint runs when the edit is
released. Rebuilding the list mid-keystroke is precisely the dead-node failure §5A.4 records.

### 5A.4a The contract is per document type

The field picker header names the type and its field count — *"Certificate of School Education ·
11 fields"*. A transfer certificate declares 21; Form 5A declares 16. One global list was a
correctness bug: it offered a Kerala school-education certificate the CBSE attendance and promotion
fields its contract never declares.

Binding a key the type does not declare is **blocking**: *"Field not declared by this document type
— the bundle will not carry it, so the render fails closed."*

One constraint this exposed, which is worth knowing before building reusable blocks: **a block
imposes its bound keys on every contract that uses it.** The shared letterhead binds
`school.affiliationNo`, so every type whose starter uses that block must declare it. Publishing a
block that binds a new key is a contract change for every type referencing it.

### 5A.5 Merge fields are chips, and only the picker makes them

PandaDoc and DocuSign both render a variable as a visually distinct token resolved at send time.
Ours is a rounded chip with a tinted ground and a mono label, `contenteditable=false`, atomic:
the caret cannot land inside it and one backspace removes it whole.

Inserting happens at the caret from the **Fields** panel or the **+ Field** button. There is no
bracket syntax — typing brackets invites typing *any* brackets, and an unresolved placeholder
printed on a transfer certificate is a forgery vector, not a cosmetic bug.

**The picker steals focus, so the caret must be saved before it opens and restored before the
insert** — otherwise every field lands at the end of the paragraph. (Also a real bug here first.)

Production uses **Quill 2.0.3 Embed blots**, not raw `contenteditable`: embeds are void nodes the
editor's document model understands, which sidesteps a decade of documented caret bugs around
`contenteditable=false` — cannot place the caret before/after, cannot sit between two adjacent
tokens, inconsistent backspace. See `REFERENCE_RESEARCH.md` §4.1. Guards that survive regardless of
editor: always keep a text node adjacent to a chip; treat backspace beside a chip as "remove the
whole chip"; never nest formatting inside one.

### 5A.6 Measured guides

Snapping fires against margins, page centre, and sibling edges — threshold in **px** so it feels
identical at every zoom — and **every guide carries the millimetre it snapped to**.

Holding **alt** while hovering another object measures to it: a coloured line and the gap in mm,
in a colour distinct from the anchor-chain indicator so a relationship and a measurement never look
alike. Figma users treat this as a convenience. For a template that will be printed onto
pre-printed stationery where 2 mm of drift ruins the job, it is an instrument.

### 5A.7 Right-click

A context menu on the canvas: Edit text · Duplicate · Copy · Bring forward · Send backward · Lock ·
**Anchor to object above / Detach** · Delete. The anchor entry matters — anchoring is the module's
hardest concept, and making it a one-click action from the object itself (which computes the current
gap and keeps the object where it is) is far more learnable than a dropdown in a panel.

### 5A.8 Page setup is live

Size (A4 / A5 / Letter / Legal) and orientation re-lay-out the page immediately. A designer who
cannot see A5 until export will design an A4 document and discover the problem at the printer.


## 6. The five mechanics

### 6.1 Required objects — the product thesis, expressed three ways

An object with a non-null `requiredKey` gets:

1. **A 2px `--seal` rail in the left gutter of the object.** Persistent, unobtrusive, visible at
   any zoom. A clerk learns in thirty seconds that red-railed things are the legally load-bearing
   ones.
2. **A `Required` chip** in the Object section header.
3. **A binding card** in the inspector on a `--seal-dim` ground: evidence-level badge, the mono
   key, and the sentence that defines the whole feature —
   > *"Move it, restyle it, translate it, change its font — all free. It cannot be deleted, and
   > publish blocks while it is unbound."*

**Deletion is refused with a citation, never with an error.** The modal is titled *"This object
cannot be deleted"* and shows a definition list: Field · Authority · Evidence · Verified (date +
owner + review interval) · Scope. It closes with the legal boundary in plain language:

> Compliance validates the **template**, never the issuance. That a form carries a "fees paid up to"
> field grants no power to withhold the certificate — courts have repeatedly held a TC is not a tool
> to collect arrears, and at the elementary stage it cannot be withheld at all.

That paragraph is in the product, not just the docs, because the person most likely to be told
"just block the TC until they pay" is the clerk holding this screen.

**Never do:** grey out the delete button. A disabled control with no explanation reads as a bug and
teaches nothing. The button stays live and the refusal teaches.

### 6.2 Anchor chains — making an invisible relationship visible

- A dotted `--info` vertical line in the left gutter spans from the bottom of the anchor to the top
  of the anchored object. It is exactly the gap, drawn.
- Toggleable (`View → Anchors`) because at 14 objects it can get busy.
- The status bar names the relationship for the selected object.
- Cycles are rejected at design time — the `Anchor to` select excludes any object that would close
  a loop (prototype excludes self; production must exclude descendants too).

### 6.3 Auto-grow, overflow, overlap — showing a future

Three distinct signals, three distinct treatments:

| Signal | When | Treatment |
|---|---|---|
| `GROWS +4.2mm` | `height:auto` and resolved height exceeds the authored box | Blue pill, top-right of the object |
| `OVERFLOW` | `height:fixed` and content exceeds the box | Amber pill + amber object border |
| Overlap | Two objects in the same region intersect at the current data | Amber row in the compliance panel, naming both ids |
| `CLAMPED −2.8mm` | `height:auto` with a `maxHMm` that the resolved content exceeds | **Seal-red pill and a blocking finding** — see below |

**Auto-grow needs a ceiling.** Figma's resizing model has four states — hug, fill, fixed, **and
min/max on top of any of them**. Ours had two. The missing one is not cosmetic: `tc.reasonForLeaving`
is auto-grow and anchored above the date of issue, the declaration and the signature block. A clerk
pasting a paragraph from a parent's letter pushes all of them down and the **signature block leaves
the page** — on a document legally required to carry three signatures and a seal. An `auto` box
never "overflows"; it just moves everything else.

`maxHMm` clamps the box so downstream anchors hold, and raises a **blocking** finding naming the
field and the overshoot in mm. Blocking, not warning: content that does not fit is content that will
not print, and silent truncation on a statutory field is the worst available outcome. It pairs with
the p95 mode by design — p95 shows you the length that breaks the layout, `maxHMm` decides what the
layout does about it.

Figma documents the trap to avoid here too: a text layer cannot set max height *and* max lines at
once. We choose **height**, because a certificate is measured against a physical sheet and line
count is an accident of the font.

Overlap detection is per-region (body / header / footer), tolerance 0.5 mm, and it is a **warning,
not a blocker** — a designer may legitimately overlap a seal and a signature rule.

**The layout pass has one non-obvious requirement:** measure *after* applying the authored width.
Measuring a shrink-to-fit box means text never wraps, and every auto-grow object reports a
one-line height. (This was a live bug in the prototype, found by the p95 mode it exists to power.)

### 6.4 Sample data and the p95 stress mode — *new, not in the blueprint*

A three-way segment: **Field names · Typical · p95**.

- **Field names** — merge fields render as clay chips with their human label. This is design mode.
- **Typical** — the contract's `sample` value.
- **p95** — the contract's `p95` value: *"Lakshmi Priyadarshini Venkataraman"*,
  *"Shri Guru Harkrishan Public Senior Secondary School, Ranchi"*, a two-line reason for leaving.

`COLLECTION_SHAPES.md` §6 already requires `sample` to be p95-realistic, with the reason stated
exactly right: *"a short sample is how overflow bugs reach production."* This spec turns that
requirement into a control. One click puts every worst-case string on the page, auto-grow fires,
anchored objects push down, and overflow and overlap warnings appear — **at design time, in the
office, before a single certificate is issued.**

Fields rendering p95 data are tinted amber so the mode is unmistakable, and the status bar reads
`p95 sample data — the length that breaks layouts`.

This is the single highest-value addition in this spec. It converts the module's hardest class of
production bug into something a clerk can see and fix in ten seconds.

### 6.5 Mandatory line-height

`style.lineHeight` is a normal-looking number input that happens to be **blocking**. When empty:

- the input turns `--seal` with a seal-dim ground;
- an inline error appears immediately beneath it:
  > *"Line height is mandatory. Without it mPDF and the browser disagree — Tamil measured 18.03 mm
  > against 9.53 mm on one block. Publish will block."*
- the compliance panel adds a blocking row;
- publish refuses.

The error carries the measurement because the requirement is otherwise unbelievable. G0.5 proved
92/92 probes at 0.00 mm divergence with it, and ~2× error without it, compounding down an anchor
chain. Telling a user "line height is required" invites them to think it is bureaucracy; telling
them "18.03 vs 9.53" makes them believe it once and never ask again.

---

## 7. Compliance panel

### 7.1 It renders a stack, not a profile

A school is under several authorities at once — the RTE Act nationally, its central board, and its
state's education rules — with different scopes. The first draft modelled one profile resolved by
`board + state`, which forces a choice between the board rule and the state rule and silently drops
whichever loses. Full reasoning in `COMPLIANCE_ARCHITECTURE.md` §1.

The panel therefore has three parts, top to bottom:

**The basis header** — the ring (`19/19` bound of required), the school's basis
(`CBSE · Kerala`, `Classes IX–XII`), and a **Change** button.

**One card per applicable layer**, each carrying: the authority's label and the form it prescribes
(`Kerala Education Rules 1959 · Form 5`), the citation, a **tier chip** (`national` /
`central board` / `state`), its **own evidence badge**, its `verifiedOn` date, and whether it
contributes required fields or **constraints only**. Beneath each, its rules — `§` for a rule,
**`⚖` for a judicial gloss that reads the rule down**. The no-dues rule shows both: the text at
source, and the courts on top of it, permanently attached.

Tier chips are colour-coded away from each other on purpose — a clerk should be able to see at a
glance that a requirement is a *state* requirement, because that is what changes if the school moves
or the board changes.

**The rule rows**, each naming the authority that requires it: `student.dobWords · Kerala Education
Rules 1959`. Under one profile a field was simply "required"; under a stack, *by whom* is the first
thing anyone asks.

### 7.2 A state with no rule is a layer, not a silence

Where no state authority is held, the panel renders a named card —
**"Jharkhand — no verified authority"** — rather than omitting the tier. Absence stated is a finding;
absence hidden is a bug that surfaces in an audit. The copy is unchanged from the original generic
card, because it was already the right copy; only its position in the model moved.

### 7.3 Evidence levels are per layer and never averaged

CBSE at Level A and Tamil Nadu at Level B appear side by side with their own badges. A single
blended "profile evidence level" would be a lie about both. Level D still renders with a dashed
border so our own recommendations look provisional.

### 7.4 Changing the basis, and excluding a layer

Two controls, deliberately separate:

- **Change basis** — board, state, classes taught. The modal **previews the resolved stack live** as
  each field changes, so the consequence is visible before it is applied. Setting a school to
  secondary-only visibly drops the RTE layer, which is exactly the sort of scope rule a human
  otherwise has to remember.
- **Exclude a layer** — a toggle per layer, for the case where an authority genuinely does not reach
  this school. **A written reason is mandatory and is stored**; without one the action is refused
  and says why. An unexplained exclusion is what an auditor looks for.

The boundary holds: a school may exclude a layer, it may not rewrite one. Authority definitions stay
platform-super-admin-only.

### 7.3a A prescribed form can dictate language

Kerala's Form 5 is **printed bilingually — English and Malayalam, every label**. So a layer may
declare `bilingual`, and the panel states whether the template actually carries those languages.
This is the first case where the multilingual requirement is *statutory* rather than an operator
preference, and it is why language modes (§8) are not a nicety.

Where we hold the form but not the script — Kerala's Malayalam is stored in the source PDF in a
legacy non-Unicode encoding — the starter declares the language and leaves it **untranslated**.
The coverage indicator then reads honestly (`2/7 translated`) instead of implying a bilingual
document we cannot produce.

### 7.3b Sensitive fields the form itself prescribes

Form 5 requires **Religion** and **SC / ST / OBC status** on the certificate. The rule mandates the
field; it says nothing about who may retain a copy, for how long, or where it is stored — and the
printed document travels to another school. Fields carrying a `pii` flag raise an advisory in the
compliance panel, phrased as a decision for whoever owns the register rather than a default the
tool quietly makes.

### 7.4a Routing — when the rule changes which document you issue

Most rules say what a document must contain. KER r.17(3) says a pupil over 20 removed from the rolls
may not be given a transfer certificate **at all** — they get a Form 5A leaving certificate, a
different instrument.

A layer may therefore carry a **routing card**, which has two states:

- **Dormant** — *"Sometimes this is the wrong instrument"*, with the condition in mono, the citation,
  and a button that opens the other document type. The clerk meets the branch before they hit it.
- **Fired** — statutory-red, *"⚠ At this data, the correct instrument is Leaving Certificate
  (Form 5A)"*, plus a blocking rule row and a failing publish-gate row.

It fires off sample data, so it is checkable at design time with the p95 toggle rather than when a
21-year-old walks into the office. It gates **publish**, never issuance — the standing rule that
compliance validates the template and not the issuance is intact; this simply tells the designer the
template will be reached by pupils it must not serve.

### 7.5 Staleness and provenance

Unchanged in principle, now per layer: each card carries `verifiedOn` and, where we have it, the
`sourceRef` of the artefact actually read. The citation modal shows it, so "where did this come
from?" is answerable without leaving the screen.

## 8. Language

**Language is a mode, resolved by inheritance** — `object → region (header / body / footer) →
template default` — not a flat per-object map. An object on **Auto** inherits; pinning one shows its
language as a tag in the Layers list, exactly as Figma tags an explicitly-moded layer, and the
inspector's Language mode select reads *"Auto — inherit (English)"* so the resolved value is visible
without changing anything.

This is what makes the common bilingual case cheap. `FINAL_BLUEPRINT` S13.2 costs bilingual
documents as a **High** retrofit, and for genuine side-by-side text that is still true. But what
most schools mean is *a Hindi letterhead over an English body*, and that is now one setting: pin the
`header` region to `हिन्दी`, leave the body on Auto. One template, one string set per object, no
change to the layout model.

- Segment `EN | हिन्दी` in the topbar, with a separate mono chip showing coverage
  (`10/10 translated`). Coverage counts text objects that resolve to that language — an object
  **deliberately pinned to another language is not "untranslated"** here, so a bilingual template
  stops nagging.
- Switching swaps the previewed Delta per object. Merge fields are language-independent and never
  need translating — only the surrounding text does. The picker makes this visible by leaving field
  chips unchanged across the switch.
- Untranslated objects render `— untranslated —` at 35% opacity on the canvas, so a gap is a visible
  hole rather than an invisible English fallback.
- Publish surfaces an untranslated-string count as a **warning**, and states the consequence:
  `languageFallback` is `block`, so a missing Hindi string stops the render — it does not silently
  fall back to English.

**Per-language font selection** belongs in the Type section (`lohitdeva`, `lohittaml`, … with
`dejavusans` for Latin). Not modelled in the prototype. Note for the UI: **Lohit has no true Bold** —
the weight select must show 700 as *synthesised* for Indic families, or a school will design against
a bold that does not exist.

---

## 9. Proof, publish, history, conflict

### 9.1 Proof (D3)

A modal, never inline. Left: the schematic. Right: a step log that names what the server is actually
doing —

```
· Serializing template → HTML (namespaced under .zx-tpl-TPL0007)
· Resolving merge fields against sample data
· Registering fonts — lohitdeva, dejavusans · useOTL 0xFF
· mPDF render · A4 portrait · margins 42/15/16/15
· Hashing PDF bytes
```

then a result block: outcome, **peak memory**, render time, content hash.

Peak memory and render time are on screen because the Ohio box has OOM history and this is the
cheapest possible early-warning instrument (blueprint 10.2). The Proof button carries a `~2s` hint
so the explicit, non-live nature of the render is expected rather than experienced as lag.

Language tabs across the top with per-language coverage — a proof is per language, and J5 is the
journey that validates the entire renderer decision.

### 9.2 Publish (D4)

A **gate checklist**, not a confirm dialog. One row per condition, each pass/fail/warn with its
evidence:

| Row | Fail copy |
|---|---|
| Required fields bound | `3 required fields unbound` + the keys |
| Line height declared | `An object has no line height` + the id |
| Proof render | `No proof render on this version — publish is gated on a PDF that actually rendered` |
| Translation | warning + the `block` fallback consequence |
| Overflow at p95 | warning + the ids |

The primary button is disabled while any row fails, and the modal subtitle changes to
`Blocked — resolve the red rows first`. Footnote explains what publishing writes:
`documentTemplateVersions/{schoolId}_{templateId}_v{n}` — create-only, never updated or deleted, by
anyone; recording the font manifest and mPDF version so a re-render years later is explainable
rather than mysterious.

### 9.2a Block updates are offered, never propagated

The blueprint says block edits "propagate to templates that reference the block". `COLLECTION_SHAPES`
§4 says a published version is create-only, "no update, no delete — ever, by anyone". **Both cannot
hold.** A letterhead fix that propagates into a published template silently rewrites the snapshot
that answers *"what produced this certificate?"* for a document already in a student's hands.

Figma's library model resolves it: publishing makes an update **available**; each consuming file
gets a badge, reviews it side-by-side, and chooses *update this one* or *update all*. Nothing moves
without a consumer saying so.

Ours:

- **Draft** templates take a block edit immediately.
- **Published / active** versions never change.
- A published template whose block moved on shows **Update available — v3 → v4** with a **Review**
  action; accepting creates **draft v+1** and clears the proof, so it re-enters the normal publish
  gate. Ignoring is remembered — a school may deliberately keep an old letterhead on one type.

### 9.2c Publish is not activate

These were conflated; they are different acts with different blast radii.

**Publish** freezes an immutable version with its proof hash, font manifest and engine version.
Nothing prints because of it. **Activate** points the document type at one published version — and
*that* is what every print point resolves: the office button, the Teacher app, a parent's download,
all at once, with no per-surface rollout.

So activation gets its own confirmation naming what it replaces, and publishing offers it as an
explicit second step rather than doing it silently. The gallery is where activation lives: each
published template carries **Set active**, the active one carries **Deactivate**, and an unpublished
draft has the control disabled with the reason on hover.

Deactivation warns honestly: with nothing active the print point **fails closed** — it refuses to
render rather than falling back to another template. That is correct, and it is also a visible
outage for the office, so it should not be a one-click accident.

Certificates already issued are unaffected — each records the version that produced it.

### 9.2b Compare with the published version

*"What changed since the version that is live?"* is the question a Principal asks at the approval
gate — the module's moment of legal exposure — and the honest answer today is "open both and
squint". Dev Mode's **compare changes** is the pattern; our schematic renderer makes it nearly free.
Side-by-side, changed objects in clay, additions in green, removals in amber, with a named list
beneath. Reachable from History.

### 9.3 History (D5)

A version timeline. Each published entry shows date, publisher, content hash, mPDF version and font
manifest. Header copy names the question it answers:
**"show me the exact template that produced this certificate"** — asked three years later by
somebody who is not you.

### 9.4 Conflict (D7)

Fires on stale `lockVersion`. Names the other person, both lockVersions, and **summarises both sides'
changes** before offering a choice.

**On a template with an active published version, blind overwrite is not offered at all.** The
options become *keep editing*, *reload theirs*, or *review both and save mine as a new draft*. One
of those two changes may be the one a Principal already approved; "Save mine over theirs" is data
loss with a confirmation button on it. Figma's branching flow — review, then resolve, then merge —
is the ordering we borrow, without the machinery.

Never a silent overwrite, and never a bare "someone else edited this". A user cannot choose between
two versions they cannot see.

---

## 10. States

| State | Treatment |
|---|---|
| Gallery, no templates | Inline note in the section, not a full-page empty state — starters are right below. |
| No selection | Inspector shows the interaction legend with `kbd` chips. Teaching moment, not blank space. |
| Loading a template | Skeleton page rectangle + rail disabled. No spinner over the canvas. |
| Save failure | Toast in `--seal`, save state stays `Unsaved changes`. **Never** report success on a failed write — the panel's documented phantom-success class. `api.js` checks `r.ok` **and** `{status:'error'}`. |
| Unresolved placeholder at render | Hard error naming the key. A document must never print a literal `{student_name}`. |
| Profile stale | Amber banner, work continues. |
| No proof yet | Publish enabled but gated; the gate row explains. |

---

## 11. Keyboard

Every convention that already exists in Figma is borrowed rather than invented
(`REFERENCE_RESEARCH.md` §2.1, §2.3).

```
V / H                 Move · Hand
T B I L Q             Text · Table · Image · Rule · QR   (place by dragging)
double-click          Edit text in place
Enter                 Edit the selected text object
Esc                   Finish editing · clear selection · return to Move
⌘B / ⌘I / ⌘U          Bold · italic · underline, while editing
↑ ↓ ← →               Nudge 1 mm          shift + arrows   10 mm
⌘D                    Duplicate           ⌘C / ⌘V          Copy · paste
⌘] / ⌘[               Bring forward · send backward
⌘Z / ⌘⇧Z              Undo · redo
space + drag          Pan the desk        ⌘ + scroll       Zoom
alt + hover           Measure to another object
shift + click         Add to selection
⌫                     Delete (refused on required objects)
```

`⌘⌥↑/↓` is deliberately **not** bound to arrange. Figma remapped it to rotate and the change was
badly received; an arrange shortcut that silently becomes a transform shortcut is a trap.

A shortcut sheet is reachable from the status bar, so the map is discoverable rather than folklore.

## 12. Accessibility

- Every interactive element is a real `<button>` / `<input>`; visible `:focus-visible` ring in clay
  at 2px offset.
- Colour is never the only channel: bound/unbound also differ by glyph (`●`/`○`), evidence levels by
  border style, data mode by an explicit status-bar sentence.
- Canvas objects must be reachable by keyboard in production — the object list is the accessible
  path to selection; the prototype does not implement it and that is a known gap.
- `prefers-reduced-motion` honoured.
- Contrast: all text tokens ≥ 4.5:1 on their own ground in both themes; the 8px ruler labels are
  decorative duplicates of the mm readout in the status bar.

---

## 13. Responsive

| Width | Behaviour |
|---|---|
| ≥1180 | Full three-pane. |
| 1180–920 | Rail 200, inspector 286. |
| 920–720 | Rail hidden (insert moves to a topbar menu); inspector kept. |
| <720 | Read-only view: gallery and proof only. **The canvas is not usable on a phone and should not pretend to be.** |

A landscape tablet is a real target (P3 sets templates up on-site). The panel's recurring
dialog-clipping bug class applies here: every modal caps height and scrolls its body with a sticky
footer.

---

## 14. Panel integration notes

- **CSS namespacing is mandatory.** Emit template CSS under `.zx-tpl-{templateId}`; the designer's
  own chrome under a `doctemplates.css` prefix. The `.att-grid` incident — a table's class colliding
  with a card-grid utility — is the precedent, and this module ships a *page of absolutely positioned
  divs* into a panel full of layout utilities.
- **No bundler.** Plain `<script>` includes, self-hosted Quill UMD (Chart.js precedent).
- **Fail-closed fetches.** Every helper checks `r.ok` **and** `{status:'error'}`.
- **CSRF — protection is KEPT. No route goes in `csrf_exclude_uris`.** *(corrected 2026-08-19 —
  gate G0.7 measured this against the running panel.)* Sending the token works with the existing
  config unchanged (`csrf_token`, `csrf_regenerate = FALSE`): POST **without** a token → 403; POST
  **with** one → passes to routing; forged token → 403. Excluding these routes would disable CSRF
  across the module — including `publish` and `activate` — letting a forged cross-site POST flip a
  school's active Transfer Certificate template. The blank-403 pain is real; the fix is to **send
  the token, not remove the check.** `api.js` attaches `csrf_token` to every POST body/FormData.
- **RBAC.** `view` gallery · `edit` design and save · `manage` publish and activate. Capability
  checks, never `_require_role()` role-name arrays. Compliance profiles: platform super-admin only.
- **Firestore rules** live in a shared file — `node aegis/cli.js rules status` first, one `match`
  block, diff before deploy.

---

## 15. Copy deck — the lines that carry the product

Use these verbatim; they were written to be read by a clerk who is afraid of being blamed.

| Where | Line |
|---|---|
| D0 subtitle | Design the certificate once. Every place that prints it — the office, the Teacher app, a parent's download — resolves the template you activate here. |
| Starters heading | Starters — cloned into your school, never linked |
| Field picker | There is no free-typed token — a placeholder that resolves to nothing is a forgery vector, so the picker is the contract. |
| Required binding | Move it, restyle it, translate it, change its font — all free. It cannot be deleted, and publish blocks while it is unbound. |
| Anchor hint | An anchored object holds a **gap below** its neighbour, never an absolute Y. Real data decides the final position. |
| Generic profile | No verified requirements for this board and state… You have complete freedom — and complete responsibility. |
| Language fallback | Statutory documents use **block**. Falling back to English on a Hindi certificate would issue a document nobody asked for. |
| Proof note | The browser preview you edit against uses the **same serializer**, so a difference between them is a bug, not a style choice. |
| History note | This is the answer to "show me the exact template that produced this certificate", asked three years later by somebody who is not you. |

---

## 16. What the prototype is and is not

**Real in the prototype:** the object model (mirrors COLLECTION_SHAPES §3.1 field-for-field);
the layout pass with measurement and anchor resolution; drag, resize, marquee, multi-select, snap,
align/distribute; the command stack with per-gesture coalescing; mm↔px at zoom; live compliance
validation; required-object refusal; language switching; sample-data modes with real p95 strings;
overflow, grow and overlap detection; the publish gate logic; both themes.

**Also real:** the compliance stack with live basis switching and layer exclusion, conditional
document-type routing, per-type field contracts, language mode inheritance, template activation and
deactivation, in-place text editing with atomic merge-field chips inserted at the caret, inline
bold/italic/underline, the tool model with placement-by-drag, space-drag pan and ⌘-scroll zoom,
the layers list, the floating context toolbar, right-click menu, alt-hover measurement, measured
snap guides, ⌘D / copy / paste, live page-size changes, and inline template renaming.

**Faked:** the proof render (staged log + synthetic hash — no mPDF); persistence (no Firestore, no
`lockVersion` round-trip); the editor itself (raw `contenteditable` stands in for Quill 2.0.3, and
runs stand in for Deltas); reusable-block propagation; per-language fonts; keyboard traversal of
the layers list.

**Illustrative and explicitly not authoritative:** the CBSE `requiredKeys` list. The real transcription
is gate 0.3, sign-off 0.8. The banner in the panel says so; do not let it be screenshotted as the
field list.

---

## 17. Open design questions

| # | Question | Blocks |
|---|---|---|
| U1 | **Does CBSE mandate field order?** Presence-only compliance is designed. If order is mandated, the rules list becomes an ordered checklist and the canvas needs an order-violation warning. The model absorbs it per-profile; the *UI* changes materially. | Compliance panel final form (blueprint Q1) |
| U2 | **Where does rich-text editing happen — in place, or in the inspector?** In-place (Quill mounted on the object) is designed here. It fights small text at 70% zoom. An inspector text pane is uglier and more reliable. Decide before Phase 3. | Text editing UX |
| U3 | **Bold for Indic.** Lohit ships Regular only; mPDF synthesises. Does the weight control show 700 as synthesised, disable it, or ship a per-script bold source? | Type section, Phase 6 |
| U4 | **Does the clerk ever see the mm coordinate fields, or is that P3-only?** Currently always visible. An "advanced" disclosure would simplify P1's inspector considerably. | Inspector density |
| U5 | **Object list / layers.** Deferred to v2, but it is also the accessible selection path. If accessibility is in scope for v1, a minimal list is not optional. | A11y, v1 scope |
| U6 | **Stationery mode (v1.1)** adds a tracing background, a chrome toggle and a calibration offset. All three are canvas-level modes. Designing them now would cost little; building them is what is deferred. | v1.1 |

---

## 18. Mapping to the execution plan

| Their phase | What this spec supplies |
|---|---|
| Phase 2 — Serializer | §5.2 band model, §6.3 measurement rule (measure after width), namespacing |
| Phase 3 — Canvas core | §5.3 gestures, snap-in-px, command stack, §6.2 anchor visuals, §5.4 inspector |
| Phase 4 — Text + binding | §5.1 field picker, §6.4 sample data modes, §6.5 line-height validation |
| Phase 5 — Compliance | §6.1 required objects, §7 whole section, evidence-level typography |
| Phase 6 — Publish | §9.1–9.4 proof, gate checklist, history, conflict |
| Phase 7 — Language | §8 |
| Phase 8 — Blocks + starters | §4 gallery, §4.2 schematics, §5.1 blocks pane |
