# Text editing — the problem, and a Content pane

**Status:** **BUILT and verified in the browser.** Normative text now lives in UX_SPEC §5A.4b;
this document is kept as the rationale record. **UNCOMMITTED / DEPLOY-PENDING.**
**Date:** 2026-08-19 · built 2026-08-23
**Supersedes nothing** — it *adds* a route. In-place editing stays.

---

## 1. The complaint, stated precisely

Today there are four ways to start editing text (UX_SPEC §5A.4): double-click, Enter on a selected
object, ✎ in the context toolbar, ✎ in the inspector. The spec justifies this as *"four routes
because this is the most-used action in the tool."*

Four routes do not fix the problem. **They are four doors into the same mode**, and the mode is the
problem. To change one word a clerk must:

1. **find** the right text object on a zoomable canvas,
2. **hit** it precisely — the target may be 9.5 pt tall,
3. **enter** edit mode,
4. **edit** at whatever zoom happens to be set,
5. **exit** the mode,

and at step 2 the most likely misfire is **dragging the object instead of editing it** — silently
moving a piece of a legally-formatted document.

That is acceptable for someone designing a layout. It is wrong for the person the personas call
P1 — the office clerk, *"not a designer by trade"*, whose actual job is fixing a typo in the
school's declaration sentence, not moving boxes.

## 2. Why this is a known problem, not a preference

The WYSIWYG literature names it directly: the alternative to a visual canvas is *"separation of
content and presentation, allowing users to structure and write the document once, rather than
repeatedly alternating between the two modes"* — which is the friction described above, exactly.

The observation that WYSIWYG editors *"are not always easy to use, particularly when trying to
format content in complex layouts"* is more pointed here than for a web page, because a certificate
**is** a complex fixed layout, and the person editing it is the person least equipped to repair it.

## 3. What a certificate actually is

A poster is a canvas. **A certificate is a document** — it is read top to bottom, as prose and
labelled fields. Everything about it, including the compliance model, is organised as *content that
must be present*, not as *shapes that must be arranged*.

The tool currently offers only the canvas view of it. It should also offer the document view.

## 4. Proposal — a fifth rail pane: **Content**

The rail already has `Insert · Fields · Blocks · Layers`. Add **Content**.

```
┌ CONTENT ──────────────────────────────┐
│ ⌂ HEADER                              │
│   ZENXII MODEL SCHOOL            [ab] │
│   Sector 14, New Delhi — 110001  [ab] │
│                                       │
│ ¶ BODY                                │
│ ● 1. Name of Pupil                    │
│      {student.fullName}               │
│ ● 6. Date of birth …                  │
│      (in figures) {student.dob}        │
│   ▸ Declaration                        │
│      This is to certify that the pupil │
│      named herein has been a bona fide │
│      student of this institution…      │
│                                        │
│ ⌂ FOOTER                               │
│   Page {PAGENO} of {nbpg}        [ab]  │
└────────────────────────────────────────┘
```

### Rules

| # | Rule | Why |
|---|---|---|
| R1 | Lists every text object in **reading order** — resolved Y, then X. Not z-order, not creation order. | It must read like the document. Reading order is the only order a clerk thinks in. |
| R2 | **Always editable.** No mode, no ✎, no double-click. Click the text, type. | Removes the mode entirely for the common task. |
| R3 | **Cannot move, resize, reorder or delete anything.** Content only. | A clerk editing prose can no longer break a legally-formatted layout by mis-dragging. |
| R4 | Merge fields render as the **same chips** as on canvas, atomic, inserted only from the Fields pane. | One mental model; the picker stays the contract (§5A.5). |
| R5 | Selecting a row **selects and scrolls to** the object on the canvas, and vice versa. | The canvas stays a live preview; the two views are one selection. |
| R6 | Required objects carry the **seal marker** here too. | Compliance is visible while proofreading, which is when it matters. |
| R7 | Header and footer appear as their own groups. | They are first-class in the model (COLLECTION_SHAPES §3.2); hiding them here would make them unfindable. |
| R8 | Language switch applies to this pane. Untranslated strings show an explicit empty state, not the fallback silently. | The untranslated report becomes something you can *act* on in place. |
| R9 | Non-text objects (image, shape, qr) appear as **greyed one-line rows**, not editable. | Preserves reading order and completeness without pretending they are text. |

### What it deliberately does not do

Not a second designer. No fonts, sizes, colours, positions. If a clerk wants those, that is the
canvas, and that is a different job with different permissions.

## 5. Effect on the existing routes

| Route | Verdict |
|---|---|
| Double-click on canvas | **Keep.** It is the Figma/Canva convention and designers expect it. |
| Enter on selected object | **Keep.** Keyboard path, costs nothing. |
| ✎ in **context toolbar** | **Keep.** It appears next to the object being worked on. |
| ✎ in **inspector** | **Remove.** It duplicates the toolbar button, sits far from the object, and is the least discoverable of the four. Its job is better done by the Content pane. |

That is one fewer control, not four — the goal is not to strip routes but to make the *common* one
mode-free.

## 6. Why this is also the safer build

The spec (§5A.4) records two real bugs already fixed in the prototype: the editable state must be
declarative or *"the user's keystrokes vanish into a dead node"*, and any render while editing must
harvest the DOM back first or the edit is lost. Both are canvas in-place-editing bugs.

A Content pane editing a plain, linear list has **none of that surface**. It is the low-risk path to
the same outcome, which is why it should carry the everyday traffic.

## 7. What the build changed about the proposal

**R2 "always editable" forced a commit-model rewrite.** The first cut committed on `blur`, mirroring
the canvas. That is wrong for a pane whose whole premise is that there is no mode to leave: blur is
not guaranteed to arrive. The model is now written on every `input`, with undo coalesced into one
entry per typing burst (baseline armed at the start of the burst, pushed on blur). §5A.4b carries
the detail.

Verifying this surfaced that **Chrome dispatches no `focus`/`blur` at all while the document lacks
OS focus** — `element.focus()` still sets `activeElement`, but no event fires and `execCommand`
is inert. Any future browser check of this pane must drive it with synthetic `InputEvent`s rather
than real typing, or it will report false failures. That is a harness fact, not a product fact —
but it cost an afternoon, so it is written down.

**§6's claim was too strong.** It said a linear list has "none of that surface". It has less, not
none: `paintContent()` rebuilds the list wholesale, so it had the same dead-node bug, and now
refuses to repaint under a live edit. Commit-on-input is what actually makes the data safe.

## 8. Open

- Should the Content pane be the **default** pane when a template is opened by someone holding only
  `edit` (not `manage`)? Arguably yes — it is the view that matches their job. **Still open.**
- ~~Read-only **Proofread** view~~ — **built**, as the *Read* half of the Edit-all / Read toggle.
- Long prose in a narrow rail: does the pane need a **widen** affordance, or should a long field
  open a larger editor? **Still open** — worth deciding once a real declaration paragraph is in a
  template, since the answer depends on how long the longest one actually is.
