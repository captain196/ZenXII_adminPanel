# Figma, subsystem by subsystem — and what we take

**Date:** 2026-08-18 · **Method:** Figma Learn (vendor documentation) read article by article, one
interaction subsystem at a time. Practitioner sources used only where the vendor docs are silent,
and marked *(secondary)*. Sources listed at the end.

**Why this exists.** The first pass borrowed Figma's *shape* (five regions, tool shortcuts, measured
guides). It did not audit Figma's *behaviour*, so basic things were missing — clicking outside an
object did not deselect it, because nothing on the desk was listening. That is a symptom: the fix is
not one handler, it is a subsystem-by-subsystem audit with an explicit decision on each.

Every subsystem below carries a decision:

| | |
|---|---|
| **ADOPT** | Take it as Figma does it. Users already know it; deviation is a cost with no benefit. |
| **ADAPT** | The idea is right, the units/constraints differ. Millimetres, one fixed page, a compliance layer. |
| **REJECT** | Deliberately not ours, with the reason recorded so nobody re-litigates it later. |

---

## S1 · Selection

**Figma.** Click an object to select. Shift-click adds; shift-click an already-selected object
removes it. A marquee is a click-and-drag from empty canvas across the objects to select. Hovering
reveals the layer's bounds. In the layers panel, click selects; click-then-shift-click selects a
range; modifier-click picks individual non-adjacent layers.

Two behaviours that matter more than they sound:

- **Locked objects cannot be selected with a normal left-click.** They are reachable only through
  the right-click *Select layer* menu (shown with a padlock) or the layers panel.
- **Hidden layers do not appear in the Select layer menu** and must be made visible to be selected.

**Decision — ADOPT, with one addition.** Everything above, plus: a lock in a certificate template
is usually a clerk protecting the letterhead they finally got right. If a locked object still
selects and drags on click, the lock is decorative. Locked and hidden objects therefore fall through
to the canvas (starting a marquee), exactly as in Figma, and stay reachable from the Layers panel.

---

## S2 · Deselection — the reported defect

**Figma.** "To clear your selection entirely, click on a blank area of the canvas or press Escape."

**What we had.** Clicking blank *paper* cleared the selection. Clicking the **desk around the page**
did nothing at all, because no handler existed there — and the desk is most of the screen, so this
read as "deselect is broken".

**Decision — ADOPT, and treat the desk as canvas.** Blank paper, the desk, and Escape all clear the
selection. Escape is staged, which Figma's docs do not spell out but its behaviour implies:

```
editing text  → Esc commits the text and leaves the object selected
object selected → Esc clears the selection
nothing selected → Esc returns the active tool to Move
```

Three presses always get you back to a neutral state, and no press ever loses work.

---

## S3 · Nesting and deep select

**Figma.** Clicking a nested object selects its top-level parent. To go deeper: double-click, press
Enter, or hold ⌘/Ctrl and click. `Enter` selects a child, `Shift Enter` the parent, `Tab` the next
sibling, `Shift Tab` the previous. A right-click **Select layer** menu lists everything under the
cursor.

**Decision — mostly REJECT, one ADOPT.**
Our object model is deliberately flat: absolute objects in three regions (header / body / footer),
no groups in v1. There is no nesting to descend into, so deep-select, `Enter`-to-descend and
`Shift Enter`-to-ascend have nothing to act on. `Enter` is better spent on *edit this text*, which
is the most frequent action in this tool.

**ADOPT the right-click Select layer list.** A certificate is dense and objects overlap — a seal
under a signature rule, a table under a declaration. Being able to right-click a pile and pick from
a list of what is underneath is worth more here than in Figma, and it is also how a locked object
gets selected. **ADOPT `Tab` / `Shift Tab`** as flat cycling through the object list, which doubles
as the keyboard path to selection.

---

## S4 · Move and nudge

**Figma.** Arrow keys nudge by the **small nudge** (default 1), Shift+arrows by the **big nudge**
(default 10), both configurable in Preferences. Holding Alt/Option while dragging duplicates.

**Decision — ADOPT, in millimetres.** 1 mm and 10 mm. The values are not user-configurable in v1:
mm are physical, and 1/10 is the natural decimal step for paper. `Alt`+drag duplicates — this is
muscle memory for anyone who has used a design tool and costs nothing to support.

**One local rule Figma has no equivalent for:** an anchored object cannot be moved vertically,
because its Y is resolved at render time from the object it hangs off. Horizontal movement stays
free, and the Y field is disabled with the reason on hover.

---

## S5 · Resize and scale

**Figma.** Eight handles. Lock aspect ratio in the Layout section, temporarily overridden by holding
Control while dragging. Alt/Option resizes from the centre. A separate **Scale tool (K)** resizes
proportionally *including* text size and effects.

Text layers additionally have three resizing modes: **auto width** (grows sideways), **auto height**
(grows downward), **fixed size**. Creating text by **clicking** gives auto width; by
**click-and-drag** gives fixed size.

**Decision — ADAPT.**
- Eight handles: ADOPT.
- Alt-from-centre and aspect lock: ADOPT for images, seals and QR blocks, where proportion is real.
- **Scale tool: REJECT.** On a compliance form, point sizes are meaningful — an 9 pt statutory field
  scaled to 7.4 pt because someone dragged a corner is a defect, not a design choice. Type size
  changes through the type control, deliberately.
- **Text resizing modes: ADAPT to two.** A certificate's text boxes are column-width by definition;
  auto *width* would let a field run off the sheet. We keep **auto height** (our "auto-grow") and
  **fixed**. But we ADOPT the creation gesture verbatim, because it is a genuinely good default:
  **click = auto-grow, spanning to the right margin; drag = a fixed box at the size you drew.**

---

## S6 · Align, distribute, tidy up, smart selection

**Figma.** Six alignment controls in the Design panel — align left, horizontal centres, right, top,
vertical centres, bottom — on `Alt+A / Alt+H / Alt+D / Alt+W / Alt+V / Alt+S`. **With one object
selected, alignment is relative to its parent**; with several, relative to each other.
Distribute horizontal/vertical spacing retains the outermost objects' positions. **Tidy up**
arranges a selection into a grid from its top-left corner. **Smart selection** appears when
objects are equally spaced: pink handles between them adjust spacing directly on canvas and let you
reorder by dragging.

**Decision — ADOPT the first three, REJECT the last two.**
- Six alignment buttons and their shortcuts: ADOPT. We shipped only the three horizontal ones, which
  is half a feature — a signature block is aligned *vertically*.
- **Single-selection aligns to the page margins** (our equivalent of "the parent"). This is the
  fastest way to centre a title, and it is the behaviour people expect.
- Distribute horizontal and vertical: ADOPT.
- **Tidy up: REJECT.** It builds grids. A certificate is not a grid of tiles; the equivalent job is
  done by anchor chains, which also survive to render time.
- **Smart selection: REJECT for v1.** Elegant, but it is a spacing instrument for equally-spaced
  card layouts. Our spacing problem is one-dimensional and already solved better by
  `anchorGapMm`, which is *data* and holds under real content — smart-selection spacing is a
  design-time nicety that a long student name would immediately invalidate.

---

## S7 · Rulers, guides, grids, snapping

**Figma.** Rulers are toggled from View. **Drag from a ruler to pull a guide onto the canvas**;
Alt-drag from an existing guide duplicates it. Remove a guide by dragging it back to the ruler,
selecting it and pressing Delete, or right-click → Remove guide. With a frame selected, holding
Alt while dragging a guide **shows the distances between the guide and the objects in the frame**.
Separately, layout guides (uniform grid / columns / rows) attach to frames, and a pixel grid with
snap-to-pixel exists for export-crispness.

**Decision — ADOPT guides in full; REJECT the pixel grid; DEFER layout grids.**

Ruler guides are the highest-value borrowed feature in this study, because of where this product is
going: **v1.1 prints onto pre-printed stationery.** A clerk aligning variable fields to the boxes
already printed on a security-paper TC book needs to lay down a horizontal line at 96.5 mm and snap
to it. That is exactly a ruler guide. It ships in v1 even though stationery mode does not, because
the coordinate system is already there and guides make the v1.1 work additive.

Guides in our build: drag from either ruler; a live mm label while dragging; snapping to them in the
same pass as margins and object edges; drag back to the ruler to remove.

**Pixel grid: REJECT.** Our unit is the millimetre and our output is paper. A pixel grid would be a
lie about the medium. The existing 5 mm grid stays.

**Layout grids (columns/rows): DEFER.** Real value for a two-column certificate, but it is a second
alignment system and v1 has no document that needs it.

---

## S8 · Zoom and pan

**Figma.** `Shift 1` zoom to fit · `Shift 2` zoom to selection · `Shift 0` zoom to 100% ·
`Shift +` / `Shift −` zoom in and out · ⌘/Ctrl+scroll to zoom · space+drag or two-finger to pan.

**Decision — ADOPT all of it,** plus `⌘ +` / `⌘ −` as an alias because that is what non-designers
try first. **Zoom to selection** is the one we were missing and it matters here: a 9 pt field on an
A4 sheet at fit-zoom is four pixels tall, and checking a statutory field means getting close to it.

---

## S9 · Layers panel

**Figma.** Lists every layer; range-select with shift; individually with the modifier; hidden and
locked state toggled per row.

**Decision — ADOPT, and it ships in v1** (the blueprint defers "layers panel, grouping" to v2 — the
*list* cannot wait, because it is the only keyboard-reachable path to selection on an absolutely
positioned canvas; *grouping* stays deferred). Rows are grouped by region, which teaches the
header/body/footer model for free.

---

## S10 · Text

**Figma.** Double-click or `Enter` enters edit mode. Once editing, clicking a *different* text layer
starts editing that one directly — no second double-click. Typography controls live in the right
sidebar: font, weight, size, line height, letter spacing, alignment, case, decoration, lists,
OpenType, text direction (including RTL/bidi).

**Decision — ADOPT.** We already have double-click and `Enter`; we add **click-through editing**,
because a clerk translating a certificate edits eight text objects in a row and should not have to
double-click each one. Bidi/RTL is noted as future work — nine Indian languages ship in v1, all
LTR, but Urdu would change that.

---

## S11 · Duplicate, copy, paste, order

**Figma.** `⌘D` duplicate · `⌘]` bring forward · `⌘⇧]`/`⌘⌥]` bring to front · `⌘[` send backward ·
`⌘A` select all · `⌘⇧A` select inverse · Alt-drag duplicates.

*(Secondary: Figma remapped `⌘⌥↑/↓` from layer order to rotate, which caused a forum backlash — an
arrange shortcut that silently becomes a transform shortcut.)*

**Decision — ADOPT,** including `⌘A` / `⌘⇧A`, which we lacked. We do **not** bind `⌘⌥↑/↓`.

One local rule: **a duplicate never inherits `requiredKey`.** Two objects claiming to be the same
statutory field is meaningless, and the compliance panel would count a satisfied rule twice.

---

## S12 · Numeric fields

**Figma.** X, Y, W and H **accept mathematical expressions** — `+10`, `*2`, `(x/2)+6` — using
`+ - * / ^` and parentheses.

**Decision — ADOPT.** This is the cheapest high-value borrow in the study. "Centre this on A4" is
`210/2` minus half the width; a clerk positioning against a stationery box measured with a ruler
types `96.5+3`. Supporting arithmetic in a millimetre field removes a calculator from the desk.

---

## S13 · Context menus

**Figma.** Right-click offers a **Select layer** submenu of everything under the cursor, plus the
usual arrange/lock/delete operations and *Remove guide* on a guide.

**Decision — ADOPT.** Ours adds *Anchor to object above / Detach*, because anchoring is this
module's hardest concept and it deserves a one-click route from the object itself.

---

## S14 · Keyboard accessibility

**Figma.** Keyboard controls are always on. `F6` moves focus to the toolbar; a **keyboard box
selection tool** (`⌥Space` / `Ctrl Space`) puts a cursor on the canvas that arrow keys move and
`Return` confirms. Figma admits some things are mouse-only. Screen-reader adaptation is a
preference.

**Decision — ADAPT, more modestly.** A floating keyboard cursor is a large build for a niche path.
Ours is the **Layers panel as the keyboard surface**: `Tab` / `Shift Tab` cycle objects, arrows
nudge, `Enter` edits, `⌫` deletes (refusing on required objects). That is a complete keyboard
workflow using a component we ship anyway, and it degrades honestly — no claim of a canvas that
can be operated blind.

---

## S15 · Deliberately rejected, with reasons

| Figma feature | Why not |
|---|---|
| **Auto layout** | A second layout paradigm. We have absolute mm + anchor chains; two ways to position is the "second way to do a solved thing" the house rules forbid. |
| **Constraints** | Meaningful when a frame resizes. Our page is a fixed physical sheet. |
| **Components / instances** | Our reuse unit is the **reusable block** (letterhead, signature, seal) and the template itself. Components would duplicate that. |
| **Scale tool (K)** | Would silently rescale statutory point sizes. |
| **Vector/pen editing, boolean ops** | No certificate needs drawn artwork. |
| **Rotation** | Deferred by the blueprint; no v1 document requires it. |
| **Pixel grid / snap to pixel** | Wrong unit, wrong medium. |
| **Multiplayer cursors** | The panel is not realtime; concurrency is handled by `lockVersion` and an honest conflict dialog. |
| **Select same fill / font / effect** | Value appears at hundreds of layers. A certificate has ~15. |
| **Tidy up (2D)** | Builds grids; our spacing problem is 1D and belongs to anchors. |
| **Smart selection spacing** | A design-time nicety that real data invalidates. |

---

## S16 · Implementation backlog

Ordered by whether it is a defect, parity, or refinement.

### P0 — defects
| # | Item | Subsystem |
|---|---|---|
| 1 | **Clicking the desk outside the page does not deselect** | S2 |
| 2 | Escape is not staged (edit → selected → cleared → tool reset) | S2 |
| 3 | Locked and hidden objects are still selectable and draggable on canvas | S1 |
| 4 | No way at all to select an object underneath another | S3 |

### P1 — parity that changes daily use
| # | Item | Subsystem |
|---|---|---|
| 5 | Vertical align (top / middle / bottom) and distribute horizontally — the align row was half-built | S6 |
| 6 | Single-selection aligns to the page margins | S6 |
| 7 | `Alt+A/D/W/S/H/V` align shortcuts | S6 |
| 8 | `⌘A` select all, `⌘⇧A` select inverse | S11 |
| 9 | `Tab` / `Shift Tab` cycle objects | S3, S14 |
| 10 | **Math expressions in every mm field** | S12 |
| 11 | **Ruler guides** — drag out, mm label, snap to, drag back to remove | S7 |
| 12 | `Shift 1` fit · `Shift 2` zoom to selection · `Shift 0` 100% · `⌘±` | S8 |
| 13 | `Alt`-drag duplicates | S4 |
| 14 | Text: click = auto-grow to the right margin, drag = fixed box | S5 |
| 15 | Click-through text editing (edit one, click the next) | S10 |
| 16 | Multi-selection bounding box | S1 |
| 17 | Hover shows the object name | S1 |
| 18 | Right-click → **Select layer** list of everything under the cursor | S3, S13 |

### P2 — later
Aspect-ratio lock and Alt-resize-from-centre for images/seals · layout grids (columns) ·
bidi/RTL text · configurable nudge.

---

## Sources

All vendor documentation unless marked.

- [Select layers and objects](https://help.figma.com/hc/en-us/articles/360040449873-Select-layers-and-objects)
- [Adjust alignment, rotation, position, and dimensions](https://help.figma.com/hc/en-us/articles/360039956914-Adjust-alignment-rotation-position-and-dimensions)
- [Add guides to the canvas or frames](https://help.figma.com/hc/en-us/articles/360040449713-Add-guides-to-the-canvas-or-frames)
- [Create layout guides](https://help.figma.com/hc/en-us/articles/360040450513-Create-layout-guides)
- [Adjust your view settings in the Editor](https://help.figma.com/hc/en-us/articles/360041065034-Adjust-your-view-settings-in-the-Editor)
- [Arrange layers with Smart selection](https://help.figma.com/hc/en-us/articles/360040450233-Arrange-layers-with-Smart-selection)
- [Guide to text in Figma Design](https://help.figma.com/hc/en-us/articles/360039956434-Guide-to-text-in-Figma-Design)
- [Adjust text dimensions and resizing](https://help.figma.com/hc/en-us/articles/27378154668951-Adjust-text-dimensions-and-resizing)
- [Scale layers while maintaining proportions](https://help.figma.com/hc/en-us/articles/360040451453-Scale-layers-while-maintaining-proportions)
- [Set small and big nudge values](https://help.figma.com/hc/en-us/articles/4404575206295-Set-small-and-big-nudge-values)
- [Use Figma products with a keyboard](https://help.figma.com/hc/en-us/articles/360040328653-Use-Figma-products-with-a-keyboard)
- [Explore the navigation bar and left sidebar](https://help.figma.com/hc/en-us/articles/360039831974-View-layers-and-pages-in-the-left-sidebar)
- *(secondary)* [Bring forward / send backward shortcut remap — Figma Forum](https://forum.figma.com/suggest-a-feature-11/shortcut-option-command-up-down-rotates-object-instead-of-moving-layer-order-46295)
