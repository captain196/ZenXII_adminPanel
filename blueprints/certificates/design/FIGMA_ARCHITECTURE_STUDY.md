# Figma as architecture — what it teaches the Certificate Designer

**Date:** 2026-08-18 · **Method:** the deep-research brief's method — primary sources first
(Figma Learn / help centre), critical rather than promotional, **architecture over feature lists**,
and every claim labelled by what kind of claim it is.

**Scope, stated up front.** The brief is written for a different question: *should a software
company adopt Figma as its design platform?* That report would cover pricing, plan tiers, org/team
governance, Dev Mode seat licensing, plugin-security policy, competitor scoring and a maturity
model. None of that changes the module we are building, and the pricing and plan sections would be
stale within a quarter. **§8 lists exactly what I excluded and offers it separately.**

What I did instead: ran the brief's depth against the ~third of it that bears on a canvas-based
document designer — auto layout, component architecture, variables/modes/tokens, libraries and
their update model, branching, developer handoff, versioning, performance. That produced three
things worth more than a survey:

1. **Two contradictions inside our own blueprint** that Figma has already solved (§1, §2).
2. **Four capabilities we are missing** that are cheap because Figma proved the shape (§3–§6).
3. A clear statement of **what we should deliberately not copy** (§7).

Claim labels used throughout: **[DOC]** documented by Figma · **[PRACTICE]** documented
recommendation · **[OURS]** our architectural inference · **[RISK]** a failure mode.

---

## 1 · CONTRADICTION — reusable blocks vs. immutable published templates

### The two halves

`FINAL_BLUEPRINT.md` S5.3, on reusable blocks:

> "save a letterhead, signature block or seal once, reuse across every certificate type;
> **edits propagate to templates that reference the block**"

`COLLECTION_SHAPES.md` §4, on published versions:

> "**No update, no delete — ever.** Not by a school admin, not by a platform admin, not by a Cloud
> Function."

**These cannot both be true.** [OURS] If a school fixes a typo in its letterhead block and that
propagates into a template whose v2 is *published and active*, then the frozen snapshot that is
supposed to answer *"show me the exact template that produced this certificate"* has changed
underneath a document already issued to a student. That is not a bug in an edge case — it is the
central integrity claim of the module failing on the most ordinary action a clerk performs.

Neither document acknowledges the other. The blueprint's line reads as a feature; the collection
shape reads as a guarantee; nobody wrote down what happens when they meet.

### What Figma does

Figma does not propagate. It **offers**. [DOC]

> "When someone publishes an update to a main component, style, or variable in a library, Figma
> makes the update **available** in every file where it is used. Anyone with can-edit access can
> **review and accept or ignore** the changes."

The mechanics matter as much as the principle: [DOC]

- Publishing shows a **blue badge** on the library icon in every consuming file — a notification,
  not a mutation.
- Reviewing shows the change **side by side** by default, with an **overlay** toggle.
- The consumer chooses **Update instance** (one) or **Update all**.

So a Figma component change is a *pull*, initiated by the consumer, previewed before it lands.

### Resolution [OURS]

Adopt the pull model, with our immutability rule on top:

| | Behaviour |
|---|---|
| **Draft templates** | A block edit propagates immediately. A draft is a working document; silent update is what the clerk wants. |
| **Published / active versions** | **Never change.** The snapshot is the legal record. |
| **A published template whose block changed** | Shows a **pending block update** badge. Reviewing shows before/after. Accepting **creates draft v+1** — it does not touch the published version. Publishing that draft goes through the normal gate, including a fresh proof render. |
| **Ignoring** | Permitted and remembered, so the badge does not nag. A school may deliberately keep an old letterhead on a certificate type. |

This is strictly better than either half of the original: the clerk gets propagation where it is
safe, the register keeps its integrity where it matters, and the awkward case — "your letterhead
changed but this certificate is live" — becomes a visible decision instead of a silent one.

**[RISK] if not fixed:** the first school that edits a letterhead silently invalidates every
published template that uses it, and nobody finds out until an audit compares a printed TC against
its stored snapshot.

---

## 2 · CONTRADICTION — auto-grow has no ceiling

### The gap

Our object model has `height: "auto" | "fixed"` and nothing else. `COLLECTION_SHAPES.md` §3.1.

Figma's resizing model has **four** states, not two: [DOC]

| Mode | Meaning |
|---|---|
| **Hug contents** | The frame shrinks to its children — our `auto`. |
| **Fill container** | The child stretches to the available space. Not available on top-level frames. |
| **Fixed** | Size is held regardless of content — our `fixed`. |
| **Min / Max** | **Dimensional boundaries applied on top of any of the above.** |

The missing piece is the fourth row. [OURS] On a certificate this is not a nicety:

`tc.reasonForLeaving` is auto-grow, anchored above the date of issue, the declaration, and the
signature block. A p95 reason is two lines. A clerk pasting a paragraph from a parent's letter is
eight. Every anchored object below it moves down, and the signature block leaves the page —
**on a document that is legally required to carry three signatures and a seal.**

Today the only signal is our overflow badge, and it fires on `fixed` boxes only. An `auto` box by
definition never overflows; it just pushes everything else off the sheet.

Figma names the exact limitation we would otherwise walk into: [DOC]

> "Text layers cannot simultaneously set max height AND max lines."

So the choice must be made deliberately. **[OURS] Choose max height**, because a certificate is
measured against a physical sheet and lines are a typographic accident of the font.

### Resolution [OURS]

Add `maxHMm` to the object model, applicable to `height: "auto"`. When resolved content exceeds it:

- the object clamps at `maxHMm` so downstream anchors stay where the designer put them;
- a **blocking** finding is raised, not a warning — content that does not fit is content that will
  not print, and on a statutory field silent truncation is the worst possible outcome;
- the finding names the field and the overshoot in mm, at the current data mode.

Ship it with the **p95 mode** already built: the pair is the whole point. p95 shows you the length
that breaks the layout; `maxHMm` decides what the layout does about it.

**Also adopted from the same model [DOC → OURS]:** Figma's **"Ignore auto layout"** — an object
that stays inside the frame but leaves the flow, "functioning like absolute-positioned CSS
elements". We have this implicitly (an object with no `anchorTo`), but implicitly is how a clerk
ends up confused about why one thing moves and another does not. Make it an explicit per-object
state in the Flow section: **In flow (anchored)** / **Absolute**. Same data, honest label.

**Not adopted:** *Fill container*. Our width is set by the page's printable area, and a certificate
has no resizable parent to fill. Adding it would import the whole parent-frame concept for nothing.

---

## 3 · Variables, collections and modes — this is our i18n, and it is better than ours

### What Figma has [DOC]

- A **collection** is a set of variables plus a set of **modes**.
- A **mode** is "a list of values for a variable in a collection, storing one value per variable"
  and modes "represent the different contexts of our designs".
- Documented use cases are **theming, localization, device size** — and Figma explicitly says
  string variables are "great for switching languages between different localized designs".
- **Aliasing:** a variable can reference another variable of the same type — this is how primitive
  → semantic → component token layering is implemented.
- **Scoping:** "limit which properties a variable can be applied to", e.g. a colour variable
  restricted to strokes.
- **Mode inheritance:** objects default to **Auto**. An Auto object walks up its ancestors to the
  nearest container with an explicit mode; if none, it falls back to **the collection's default
  mode**. An explicit assignment shows a tag next to the layer name.

### What we have

`content.i18n` — a flat per-object map of language → Delta, plus one global
`languageFallback: "block" | "default"`.

Structurally this *is* a mode system, built by hand, missing two things: **inheritance** and
**per-scope defaults**.

### What that costs us [OURS]

`FINAL_BLUEPRINT.md` S13.2 defers **bilingual side-by-side** to v2 and calls the retrofit cost
**High**, because "both strings occupy the page simultaneously, so it changes the *layout* model".

That is true for genuinely side-by-side text. But a large fraction of what schools actually mean by
"bilingual" is *not* side-by-side — it is **a Hindi letterhead over an English body**, or an English
statutory table under a Hindi title. With mode inheritance that is nearly free: set the language
mode on the **header region**, leave the body on Auto, and both resolve correctly with one template
and one string set per object.

**Adopt [OURS]:** language becomes a *mode* resolved by inheritance —
`object → region → template default`. An object with an explicit language shows a tag, exactly as
Figma tags an explicitly-moded layer. `languageFallback: block` stays the rule at the leaf: if the
resolved mode has no string, **the render fails** rather than quietly substituting English.

**Adopt [OURS]:** **scoping** for merge fields. Our contract already types every field
(`string | date | int | enum | text`). Scoping means the picker offers only fields whose type fits
the target — and, more usefully, that a `computed: "attendance"` field cannot be inserted into a
template for a document type whose contract does not declare attendance. Today the picker is
filtered by document type only; typed scoping is the second half of "the picker is the contract".

**Consider later [OURS]:** modes for **board/state compliance variation**. A CBSE TC and a Kerala
TC differ in required fields, not usually in layout. Modelling board as a mode over one template is
tempting — but the compliance profile is a *validation* input, not a value substitution, and
overloading modes with it would blur a boundary the blueprint is deliberately strict about
(§10.4: "compliance validates the template, never the issuance"). **Flagged, not adopted.**

---

## 4 · Component properties — how not to explode the starter gallery

### What Figma has [DOC]

**Component properties** consolidate what is changeable about a component into one panel:

- **Boolean** — currently for **layer visibility** only: show/hide a nested layer.
- **Instance swap** — mark which nested instances can be swapped, with a default and a set of
  **preferred** values.
- **Text** — mark which strings are editable.
- **Nested instances** can be *exposed* so their properties appear alongside the parent's, "without
  deep-selecting layers to find them".

And the performance guidance is explicit: [PRACTICE]

> "If a library has a large number of variants … consider using **component properties** to reduce
> the number of components and variants needed."

### Why this matters to us [OURS]

Our starter gallery is heading for exactly the variant explosion Figma warns about. Author starters
as *combinations* — TC × CBSE × Hindi × A5 — and the catalogue is 2 types of paper × 9 languages ×
n boards per document type, which is unmaintainable by the same small team that maintains the
compliance corpus.

**Adopt:** one starter per document type per *layout idea*, with **properties**:

| Property | Type | Ours |
|---|---|---|
| Language | mode | §3 — not a separate starter |
| Paper | enum | already live (A4/A5/Letter/Legal re-lays out) |
| Letterhead | **instance swap** | which reusable block fills the header region, with preferred values |
| Countersignature block | **boolean** | show only when required |

The boolean case is not hypothetical. `COLLECTION_SHAPES.md` §5 already models
`countersignature: { conditional: true, when: "origin_board != CBSE" }` — and
`FINAL_BLUEPRINT.md` S5.4 defers **conditional sections** ("show a block only when a field is
non-empty") to v2. Figma's boolean property is the cheap version of exactly that, and the compliance
corpus has already produced a case that needs it. **Adopt a minimal `showWhen` on the object**, with
the profile able to drive it — not the general expression language that was rightly deferred.

---

## 5 · Branching, review, and our conflict dialog

### What Figma has [DOC]

Branches are "controlled environments that allow you to explore changes … without editing the
original file". The lifecycle is: branch → **request review** → reviewer **approves** or
**suggests changes** → **review updates from the main file** → **resolve conflicts** → merge.

### What we have

A conflict dialog whose third button is **"Save mine over theirs."**

**[RISK]** That button is data loss with a confirmation on it. It is defensible on a *draft* — two
clerks nudging objects — and indefensible on a template that is on the published track, where the
other person's change may be the one a Principal already approved.

### Resolution [OURS]

We do not need branching. We need Figma's *ordering*: **see the difference before choosing.**

- Keep the three options on a draft, but make "review changes" the default action rather than a
  secondary one, and show the per-object difference — our schematic already renders object
  rectangles, so a before/after schematic with changed objects highlighted is nearly free.
- On a template with an active published version, **remove blind overwrite entirely**. The choices
  become *reload theirs* or *save mine as a new draft version*. Nothing is lost either way.

---

## 6 · Developer handoff — Dev Mode is the shape of our consumption contract

### What Figma has [DOC]

- **Ready for dev** — a status on sections/frames/components, with a dedicated view listing
  everything marked ready "without having to navigate around the canvas".
- **Annotations** — designers mark up designs with context, specs and measurements; developers see
  them update in real time.
- **Compare changes** — see when a frame was last edited and **compare changes at different points
  in version history**.
- **Inspect** — specs and component information needed "to transform it into code".

### Mapping [OURS]

| Figma | Ours | State |
|---|---|---|
| Ready for dev | `isActive` / `activeVersion` | Exists |
| Annotations | The compliance panel — authority, evidence level, verified date | Exists, and is stronger: ours is validated, not free text |
| Inspect | The consumption contract: `active()` → `render($tpl,$bundle,$lang)` | Specced (S7.5) |
| **Compare changes** | — | **Missing** |

**Adopt: compare versions.** "What changed between the version that is live and the draft I am
about to publish?" is the single question a Principal asks before approving, and today the answer is
"open both and squint". With the schematic renderer we already have, a side-by-side with changed
objects tinted — Figma shows side-by-side by default with an overlay toggle — is a small build with
disproportionate value at the approval gate, which is *the* moment of legal exposure in this module.

---

## 7 · Deliberately not copied

Recorded so nobody re-opens them. Extends the reject list in `FIGMA_STUDY.md` §15.

| Figma | Why not [OURS] |
|---|---|
| **Fill container** | No resizable parent; width comes from the printable area. |
| **Grid auto layout / wrap** | No v1 document has a wrapping collection. Fee receipts will need repeating rows — that is `flowRegion`, already reserved, and it is a *flow* not a *grid*. |
| **Nested components / exposed nested instances** | Our blocks are one level deep by design. Nesting buys reuse we do not have and costs a mental model the clerk does not want. |
| **Branching / merging** | Full VCS for a 15-object document is disproportionate. We take the review ordering, not the machinery. |
| **Variants (as combinations)** | The thing Figma's own performance guidance tells you to avoid. Properties instead — §4. |
| **Modes for board/state compliance** | Blurs validation into value substitution. Flagged in §3, not adopted. |
| **Plugins / an extension API** | A school ERP should not run third-party code against student records. |
| **Multiplayer** | `lockVersion` plus an honest conflict dialog is the right cost for a panel with no realtime layer. |
| **Everything organisational** | Teams, projects, permissions, seats — ZenXii's RBAC already owns this, and a second permission model is the "second way to do a solved thing" the house rules forbid. |

---

## 8 · What the brief asked for that I did not do

Not oversights — scope calls, each with a reason. Say the word on any of them.

| Brief section | Why excluded |
|---|---|
| §31 Cost & licensing, §21 enterprise governance, §22 org security, §20 collaboration | These answer "should our company adopt Figma", not "how should the Certificate Designer behave". Also the fastest-decaying facts in the whole brief. |
| §30 Competitor comparison, §36 scoring, §39 maturity model, §33 case studies | Procurement artefacts. They do not change a line of this module. |
| §11 Prototyping, §16 AI, §17 plugins, §18 REST API/webhooks | Figma platform capabilities with no analogue here — we are building an editor, not integrating with Figma. |
| §13/14/15 Figma → Android / Web / iOS | Would matter if ZenXii's *design workflow* ran through Figma. It is a real question, just a different one: **"should ZenXii adopt Figma for designing the panel and the two apps?"** That is worth answering properly, on its own, with the token-pipeline and Compose/Dev-Mode sections intact. |
| §27 "50 common mistakes" | The useful subset — detached components, hardcoded values, no tokens, variant explosion, monolithic files — is already absorbed above as design rules. A list of 50 padded to reach 50 would be worse than the 6 that apply. |

---

## 9 · Backlog this produced

| # | Change | Source | Size |
|---|---|---|---|
| **A1** | `maxHMm` on auto-grow + **blocking** finding on overshoot | §2 | S |
| **A2** | Explicit **In flow / Absolute** state on every object | §2 | XS |
| **A3** | Block edits offer a **pending update** on published templates; accepting creates draft v+1; ignoring is remembered | §1 | M |
| **A4** | **Compare versions** — side-by-side schematic, changed objects tinted | §6 | M |
| **A5** | `showWhen` conditional visibility, drivable by the compliance profile | §4 | S |
| **A6** | Language as an **inherited mode** (object → region → template default) | §3 | M |
| **A7** | **Typed scoping** on the field picker | §3 | S |
| **A8** | Starters carry **properties**, not variant combinations | §4 | Doc |
| **A9** | Conflict dialog: review-first; **no blind overwrite on a published-track template** | §5 | S |

A1, A2, A3, A4, A5 and A9 are built. A6, A7, A8 are specified and open.

---

## Sources

All Figma Learn (vendor documentation).

- [Guide to auto layout](https://help.figma.com/hc/en-us/articles/360040451373-Guide-to-auto-layout)
- [Overview of variables, collections, and modes](https://help.figma.com/hc/en-us/articles/14506821864087-Overview-of-variables-collections-and-modes)
- [Modes for variables](https://help.figma.com/hc/en-us/articles/15343816063383-Modes-for-variables)
- [Guide to variables in Figma](https://help.figma.com/hc/en-us/articles/15339657135383-Guide-to-variables-in-Figma)
- [Explore component properties](https://help.figma.com/hc/en-us/articles/5579474826519-Explore-component-properties)
- [Create and manage component properties](https://help.figma.com/hc/en-us/articles/8883756012823-Create-and-manage-component-properties)
- [Review and accept library updates](https://help.figma.com/hc/en-us/articles/360039234193-Review-and-accept-library-updates)
- [Publish a library](https://help.figma.com/hc/en-us/articles/360025508373-Publish-a-library)
- [Guide to branching](https://help.figma.com/hc/en-us/articles/360063144053-Guide-to-branching)
- [Merge branch into main file](https://help.figma.com/hc/en-us/articles/5691189138839-Merge-branch-into-main-file)
- [Guide to Dev Mode](https://help.figma.com/hc/en-us/articles/15023124644247-Guide-to-Dev-Mode)
- [Compare changes in Dev Mode](https://help.figma.com/hc/en-us/articles/15023193382935-Compare-changes-in-Dev-Mode)
- [Dev Mode ready for dev view](https://help.figma.com/hc/en-us/articles/23918228264855-Dev-Mode-ready-for-dev-view)
- [Reduce memory usage in files](https://help.figma.com/hc/en-us/articles/360040528173-Reduce-memory-usage-in-files)
