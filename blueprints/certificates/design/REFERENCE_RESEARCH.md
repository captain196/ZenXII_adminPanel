# Reference study — Figma, Canva, and document-template builders

**Date:** 2026-08-18 · **Purpose:** ground the Certificate Designer's interaction model in patterns
users already know, instead of inventing a canvas from scratch.
**Method:** vendor help centres first (Figma Learn, Canva Help), then practitioner references, then
the document-automation category (PandaDoc, DocuSign) for the merge-field half of the problem.
Sources listed at the end; claims that come from practitioner blogs rather than vendor docs are
marked *(secondary)*.

---

## 1. Why two references, not one

The Certificate Designer sits between two product categories and has to borrow from both:

| | Figma | Canva | Us |
|---|---|---|---|
| User | designer by trade | anyone | **office clerk, not a designer** (persona P1) |
| Canvas | infinite, unitless (px) | fixed page(s) | **fixed page, millimetres, print-bound** |
| Output | screens | print/social | **a legal document** |
| Precision | very high | low, deliberately | **high — pre-printed stationery in v1.1** |
| Content | arbitrary | arbitrary | **contract-bound merge fields** |

So: **Canva's approachability and text model, Figma's precision instruments, and the document
category's merge-field discipline.** Where they conflict, the clerk wins — but the precision has to
be *available*, because P3 (IT coordinator) sets the template up and P1 only edits it afterwards.

---

## 2. Figma — what to take

### 2.1 Interface anatomy

A Figma design file has **five interactive regions**: toolbar, navigation bar, left panel, right
panel, canvas.

- **Toolbar** — the creation tools plus the quick-actions menu. Tools carry single-letter
  shortcuts: `V` move, `H` hand, `F` frame, `T` text, `R` rectangle/shape, `⇧⌘K` image,
  `⇧A` auto-layout, `⇧S` section.
- **Navigation bar** — a left-most vertical strip of *tabs* (layers/pages, assets, plugins,
  variables, notifications). The tab strip is separate from the panel content it swaps.
- **Left panel** — content depends on the selected tab; the file tab holds **layers and pages**.
- **Right panel** — the properties panel, with a **Design** tab whose sections are, in order:
  layout · appearance · auto layout · constraints · fills · strokes · selection colors · effects ·
  export.
- **Canvas** — pan with **space + drag**; zoom with **⌘/Ctrl + scroll**. The hand tool exists
  specifically to let people explore without accidentally modifying anything.

**Taken:** the five-region model, the vertical tab strip driving a swappable left panel, the
single-letter tool shortcuts, space-drag pan and ⌘-scroll zoom, and the ordered right-panel
sections (we substitute our own: position & size · flow · typography · binding · arrange).

**Rejected:** auto-layout and constraints. Our layout model is absolute mm plus anchor chains; a
second layout paradigm would be exactly the "two ways to do one thing" the house rules forbid.

### 2.2 Measurement and smart guides — the most transferable idea

- Smart guides appear while moving an object, and they fire on **equal spacing**, not just
  alignment: with two rectangles 20 px apart, moving a third to 20 px from the second shows the
  measurement.
- **Alt/Option + hover** while something is selected measures to whatever you hover, drawing a red
  line with horizontal and vertical distances.
- Hovering empty areas inside a container surfaces padding values.
- Rulers toggle with `⇧R`.

**Taken, and it matters more for us than for Figma:** a certificate is measured in millimetres
against a physical sheet, and v1.1 prints onto pre-printed stationery where 2 mm of drift ruins the
job. Snap guides therefore carry a **mm label**, and Alt-hover measurement gives the distance
between any two objects without arithmetic. Figma users get this as a convenience; a school clerk
aligning to a printed box needs it as an instrument.

*(Note: Figma's red measurement line is not restylable, which is a known complaint. Ours uses
`--info` for anchors and a distinct measurement colour, so anchor relationships and ad-hoc
measurements never look alike.)*

### 2.3 Arrange and duplicate

`⌘D` duplicate · `⌘]` / `⌘[` reorder layers *(secondary — Figma recently remapped
`⌘⌥↑/↓`, which now rotates; the bracket shortcuts survived)*.

**Taken:** `⌘D`, `⌘]` / `⌘[`. **Avoided:** `⌘⌥↑/↓`, precisely because Figma's own remap of it
caused a forum backlash — an arrange shortcut that silently becomes a transform shortcut is a
trap worth not copying.

### 2.4 Layers panel

Figma's left panel lists every layer, and it is how people select things that are stacked,
tiny, or behind something else.

**Taken, with a second motive:** the layers list is also the **only realistic keyboard-accessible
path to selection** on an absolutely-positioned canvas. In our spec it is not a v2 nicety
(`FINAL_BLUEPRINT` S5.3 defers "layers panel, grouping"); the *list* ships in v1 as the a11y path,
while *grouping* stays deferred. That distinction is worth making explicitly to the build team.

---

## 3. Canva — what to take

### 3.1 The context toolbar is the whole interaction model

Selecting any element raises a **floating/context toolbar near the selection**, and **its contents
change with the element type** — text shows font, size, colour, alignment, spacing; an image shows
image controls; clicking the background changes it again. Users learn one place to look, and the
tool teaches itself by changing.

**Taken wholesale.** This is the single biggest usability gap in prototype v1: everything lived in
a far-right inspector, which is correct for a designer at a 27-inch monitor and wrong for a clerk
on a 13-inch laptop editing a line of text. v2 puts the frequent controls on a floating toolbar at
the selection, and keeps the inspector for precision (mm coordinates, anchors, bindings).

Canva's own text toolbar order — font · size · colour · B/I/U · alignment · spacing (under
"Advanced settings") — is a reasonable frequency ranking, and we follow it, inserting **`+ Field`**
where Canva has "Effects", because inserting a merge field is our highest-frequency text action.

### 3.2 Text editing

**Double-click the text box to edit its content**, then format with the toolbar. Font size is a
numeric field with `+`/`−`; text colour is an `A` with a colour bar beneath it; spacing lives in an
advanced section.

**Taken:** double-click to edit in place. `Enter` on a selected text object also enters edit mode,
and `Esc` commits — a keyboard path Canva does not advertise but which P3 will expect from every
other tool.

### 3.3 Left panel as a tabbed content library

Canva stacks tabs vertically — Templates · Elements · Text · Brand · Uploads — and clicking one
**swaps the panel instantly without leaving the design**; the set is customisable via "More".

**Taken:** the vertical tab strip and instant swap. Our tabs are **Layers · Insert · Fields ·
Blocks** — the same shape, our content. "Brand" maps onto Blocks (letterhead, signature, seal),
which is the school's identity in exactly Canva's sense.

### 3.4 What we deliberately do *not* take from Canva

- **Snapping-only precision.** Canva hides numeric position from casual users; we surface mm
  everywhere, because print alignment is the job.
- **Template-first browsing as the primary surface.** Our gallery is small and scoped to one
  document type; an infinite template feed would be noise.
- **Effects, filters, animation.** No certificate needs a text shadow.

---

## 4. Document-template builders — the merge-field half

PandaDoc renders a variable as **text in square brackets on a yellow background** so it is visually
distinct from ordinary text, and on document creation it detects which tokens the template uses and
prompts the sender to fill them. DocuSign's template merge fields work the same way — a named key
resolved at send time.

**Taken:**
- **A visually distinct token.** Ours is a rounded chip with a tinted ground and a mono label.
  It reads as "not text" at a glance, and at 9 pt on a busy form that legibility matters.
- **Atomicity.** The chip is one object: one backspace removes it whole, and a caret cannot land
  inside it.
- **The picker is the contract.** Neither product lets you invent a token by typing brackets that
  match nothing — and neither do we, for a stronger reason: an unresolved placeholder printed on a
  transfer certificate is a forgery vector, not a cosmetic bug.

**Rejected:** bracket syntax (`[First name]`). Typing brackets invites typing *any* brackets. A
chip that can only be inserted from the picker cannot be misspelled.

### 4.1 The technical trap, documented

`contenteditable="false"` inside a `contenteditable` region is the standard implementation for
atomic tokens, and it has well-known caret bugs: the caret cannot be placed inside a
`contenteditable=false` element by definition; several browsers refuse to place the caret *before
or after* such an element with arrow keys, or *between two adjacent* ones; and backspace behaviour
around them has been a long-standing Firefox bug.

**Consequence for the build:** the prototype demonstrates the pattern with raw `contenteditable`,
but production must use **Quill 2.0.3 Embed blots**, which are void nodes the editor's own document
model understands — exactly as `IMPLEMENTATION_ARCHITECTURE.md` §7.3 already specifies. This
research confirms that decision rather than revisiting it: hand-rolling atomic tokens on raw
`contenteditable` would import a decade of browser bugs.

Practical guards to carry over regardless of editor:
- always keep a text node adjacent to a chip, so there is somewhere for the caret to live;
- treat `Backspace`/`Delete` next to a chip as "remove the whole chip";
- never nest formatting inside a chip.

---

## 5. Synthesis — the rules this produced

1. **Frequent controls come to the selection; precise controls stay in the panel.** (Canva + Figma)
2. **One vertical tab strip, one swappable panel.** (both)
3. **Single-letter tool shortcuts, space-drag pan, ⌘-scroll zoom.** (Figma)
4. **Every guide carries a number.** Alignment is a hint; millimetres are the deliverable. (Figma,
   amplified for print)
5. **Double-click to edit, Esc to commit.** (Canva, plus a keyboard path)
6. **Tokens are chips, insertable only from the contract picker.** (PandaDoc/DocuSign, hardened)
7. **The layers list ships in v1** — it is the accessibility path, not a power feature. (Figma,
   re-motivated)
8. **No second layout paradigm.** Absolute mm + anchor chains only; no auto-layout, no constraints.

---

## 6. What is still unresearched

- **Print-shop tooling** (CorelDRAW, Illustrator's print workflow, RIP software) — the actual
  reference for pre-printed stationery calibration in v1.1. Not studied here.
- **Indic-script text editing UX** — caret behaviour in conjuncts, input methods, and what a Hindi
  typist expects. This is a real gap: nine languages ship in v1 and no research has touched how
  they are *typed*, only how they are *rendered*.
- **Accessibility of canvas editors.** Figma's and Canva's own screen-reader stories are weak; there
  is no strong pattern to copy, so ours has to be designed rather than borrowed.

---

## Sources

**Figma (vendor)**
- [Explore design files – Figma Learn](https://help.figma.com/hc/en-us/articles/15297425105303-Explore-design-files)
- [Explore the navigation bar and left sidebar – Figma Learn](https://help.figma.com/hc/en-us/articles/360039831974-View-layers-and-pages-in-the-left-sidebar)
- [Measure distances between layers – Figma Learn](https://help.figma.com/hc/en-us/articles/360039956974-Measure-distances-between-layers)
- [Use Figma products with a keyboard – Figma Learn](https://help.figma.com/hc/en-us/articles/360040328653-Use-Figma-products-with-a-keyboard)

**Figma (secondary)**
- [Figma designer interface tools and functions – Digidop](https://www.digidop.com/blog/figma-designer-interface-features-and-tools)
- [Figma Keyboard Shortcuts (2026) – Dualite](https://dualite.dev/blogs/figma-keyboard-shortcuts)
- [Measure Distance – Figma Handbook, Design+Code](https://designcode.io/figma-handbook-measure-distance/)
- [Bring forward / send backward shortcut remap – Figma Forum](https://forum.figma.com/suggest-a-feature-11/shortcut-option-command-up-down-rotates-objects-instead-of-moving-layer-order-46295)

**Canva**
- [Add and edit text – Canva Help Centre](https://www.canva.com/en_gb/help/add-and-edit-text/)
- [Format text – Canva Help Center](https://www.canva.com/help/format-text/)
- [Canva — Side Panel And Its Tabs – C# Corner](https://www.c-sharpcorner.com/article/canva-sidebar-and-its-tabs-learn-canva/)
- [How to use the Canva editor – Enterprise Nation](https://www.enterprisenation.com/learn-something/how-to-use-the-canva-editor/)
- [Canva Editor Interface — Layout & Toolbar Guide – eStudy 24|7](https://estudy247.com/courses/canva/lessons/canva-interface/)

**Document templates / merge fields**
- [Variables (new experience) – PandaDoc Help](https://support.pandadoc.com/en/articles/9714599-variables)
- [Create a Template with Merge Fields for Bulk Send – DocuSign Support](https://support.docusign.com/s/document-item?language=en_US&bundleId=xry1643227563338&topicId=tba1578456393017.html)
- [Merge fields in a rich text editor – Syncfusion](https://www.syncfusion.com/blogs/post/merge-fields-in-react-rich-text-editor)

**contenteditable pitfalls**
- [Non editable span in contenteditable – Lulu's blog](https://lucidar.me/en/rich-content-editor/non-editable-span-in-contenteditable/)
- [Bug 685452: Delete on contenteditable=false spans – Mozilla Bugzilla](https://bugzilla.mozilla.org/show_bug.cgi?id=685452)
- [ContentEditable – W3C](https://www.w3.org/TR/content-editable/)
