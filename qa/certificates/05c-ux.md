# 05c · A10 UX-CRITIC — Document Engine (Certificates)

**Evidence ceiling: E2.** Nothing here was observed running. Every claim is a static trace
with a `file:line` citation, or is explicitly marked `[UNKNOWN]`. No PASS claims. Counts, not
percentages. Builds on — does not restate — the six established findings in the brief and on
`_live-state.md` (E3, captured by QA-LEAD).

---

## 1 · Journey walkthroughs

### 1a · First-run: zero templates, zero active certificates

`paintHub()` (`designer.js:2172-2209`) renders one card per document type, live or not, with
three lines of metadata: compliance basis, active template, template count. For a school with
nothing yet, every card reads `Templates: none yet` and `Active template: — none —`
(`2185-2186`). There is **no distinct empty-hub state** — the same grid paints for a school
with 85 templates and a school with zero; nothing on the hub says "you haven't started" or
offers a guided first action. The only entry point is clicking a type card, which is
indistinguishable in weight from every other click a returning user makes.

Walking the path a clerk must take, blank school to one live certificate, and naming each
correctness-sensitive decision:

1. **Pick the right type card** on the hub — e.g. Kerala must not pick the generic path if
   `school_education_certificate` or `leaving_certificate_5a` applies instead
   (`designer.js:1471-1478`, `requiresState:"Kerala"`); a wrong pick is only caught later, at
   publish, by the `wrongInstrument` gate (`designer.js:5217-5219`).
2. **Choose a starter or "Blank canvas"** on the gallery (`2630-2661`). A starter can carry a
   coverage gap for the school's actual board — surfaced as a chip, "Needs N more fields for
   {board}" (`2650-2660`) — a decision the clerk must read and act on before it becomes a
   blocking row at publish.
3. **Bind every required field** the resolved compliance profile demands (`validate()`,
   `3529+`, `unbound` blocking type).
4. **Give every text object an explicit line height** — an invisible requirement with no
   client-side pre-check outside the friendlier publish-gate row (see §6); nothing in the
   canvas UI tells a clerk this exists until they hit the gate or, if the gate has a gap, the
   raw server exception.
5. **Render a Proof** — a separate, explicit action (`#proofRun` → `proofOnServer()`,
   `5127-5199`) that is a hard prerequisite for Publish (`5224-5225`, `S.proofed` gate).
6. **Publish** — freezes an immutable version. Blocked if any gate row is red
   (`5235-5244`).
7. **Activate ("Set active")** — a *separate* decision offered in a follow-up modal
   (`offerActivation`, `5321-5338`) with a "Leave it published only" escape hatch that is the
   default-looking button position (left, before the primary CTA).

**Count: 5–7 correctness-sensitive decisions**, zero of which are presented as a numbered or
guided sequence — no checklist, no progress indicator, no "3 of 5 done" affordance anywhere in
the module. A clerk who publishes and clicks "Leave it published only" (step 7) has completed
every visible step, the topbar shows the version as published, and nothing on the hub or
gallery is red or overtly wrong — the type card still reads "No active template", but that is
one line among many, in the same visual weight as "Compliance basis". This is the exact shape
of `_live-state.md`'s L3/O6 territory one layer up: **the module can leave a clerk in a state
that looks finished and is not**, without any error, because "published" and "active" are
different actions and the UI's *strongest* teaching moment for that distinction (the
publish-time modal) is also the one screen a clerk can dismiss with a single click and never
be told again.

**Proposed fix.** Make "not yet active" a persistent, page-level state, not a metadata line.
On the gallery and hub, a document type with `publishedVersion` set and no `activeVersion` for
any template should render a **dedicated banner-level callout** ("You've published a
{Type name} template, but nothing prints from it yet — Activate it"), not just a chip inside a
row. This costs one conditional block in `paintHub()`/`paintGallery()`, no new endpoint.

### 1b · Edit-and-publish (existing draft)

Reasonably well engineered — `templateState()` (`1510-1539`) computes one plain-language
status per row (`In use` / `Ready — not in use` / `Draft`) instead of exposing `status`
verbatim (see §3). Autosave (1.5 s debounce, `1030-1071`), an honest dirty/saved/conflict
indicator (`3944-3959`), and a 3-way conflict merge (`1264-1362`, documented in `01a` §6) make
the "keep working" path materially safer than most admin-panel forms in this codebase. The
weak points are the publish/activate seam above and the error-message layer (§6).

### 1c · Find-what-I-saved

Two failure modes compound here, both already partly established and extended below:

- **No URL sync** (`01a` §1) means "find what I saved" only works via in-app navigation from
  the hub, never via browser history, a bookmark taken mid-session, or a colleague pasting a
  link to a screen they are looking at (only a direct `/design/{id}` link works, and nothing in
  the UI ever hands the clerk one — there is no "Copy link" action anywhere in the file, grep
  confirms zero occurrences of `"Copy link"`/`clipboard.writeText` in `designer.js`).
- **No search, sort, or filter on the gallery** (§2 below) means "find what I saved" degrades,
  at real scale, into a linear scroll through however many rows precede the one the clerk
  wants — for `transfer_certificate` on the live school, up to 71.

---

## 2 · The gallery at real scale

`paintGallery()` (`designer.js:2519-2670`) draws one `.tpl-row` per entry in
`libOf(S.docType)` (`2580-2624`), which is `S.lib[docType]` — an array built by
`hydrateFromServer()` (`5596-5624`) via `entries.forEach(...).push(...)` in whatever order
`Object.entries(raw)` (or `raw.map`) yields from the server response. **No `.sort()` call
exists on `S.lib[type]` or on `rows` anywhere in the file** — confirmed by grep for `.sort(`
against every use of `libOf`/`S.lib` (the only `.sort()` calls in the file target
`AUTHORITIES`/merge candidates/custom-type lists, `1297, 1585`, not the gallery rows).

Consequences, traced against the live population (`_live-state.md`: 85 heads, 71
`transfer_certificate`, 80 never-published):

- **No grouping.** A clerk opening the `transfer_certificate` gallery sees all 71 rows as one
  flat list — published-and-active, published-and-not, and 65+ untouched drafts side by side,
  in server order.
- **No sort control.** Not by name, not by edited date, not by status. The hub's own summary
  line assumes a meaningful order exists — `libOf(t.id)[0].edited` (`2186, 2234`) reports the
  *first* array entry's edit time as if it were "most recently edited", with no sort backing
  that assumption. **This is itself a latent defect**, not just an absent feature: the hub's
  "edited N ago" line is only accidentally correct, and only for as long as the server happens
  to return entries in a stable, recency-adjacent order — nothing in the client establishes
  that it does.
- **No search or filter.** Zero `<input>` of type `search`/`text` bound to gallery filtering —
  confirmed against §2's grep in `01a` and re-checked here; the only inputs on the gallery
  screen are the "New document" and rename affordances, not a filter box.
- **No pagination or virtualization.** All 71 rows are built as full DOM subtrees in one pass,
  each carrying a hand-drawn `schematic()` thumbnail (`2620`) — a rendering-cost concern for
  A9, and a scannability concern here: nothing chunks the list, so "scroll to find it" is the
  only navigation available past row ~15 (the fold on a typical panel viewport).
- **What distinguishes the active template:** exactly one visual cue, a 3px left border
  (`doctemplates.css:849`, `.tpl-row.is-live{border-left:3px solid var(--ok)}`) plus an
  in-place "In use" chip (`templateState`, `1522`). It is **not pinned, sorted to top, or
  otherwise pulled out of list order** — on a 71-row unsorted list, the one template every
  print point in the school depends on can be scrolled past without being seen, and finding it
  requires either already knowing its name or reading the gallery subhead sentence above the
  list (`2529-2538`), which does name it in prose but does not link/scroll to its row.
- **The 80 never-published drafts have no bulk affordance.** `_live-state.md` O5 records that
  most of this population is E2E-harness noise. There is no multi-select, no bulk delete, no
  "hide drafts older than N days" — a school in this exact situation has no in-product way to
  clean up its own gallery short of deleting 65+ rows one at a time (each requiring its own
  confirm modal, `openDelete`, `2699-2720`).

**Proposed fix, in priority order:**
1. Sort the active/published-and-live template to the top of its type's list unconditionally,
   independent of any other sort — the one row every clerk needs first should never require
   scrolling.
2. Add a status filter chip row above the list (`All · In use · Ready · Draft`), computed
   client-side from `templateState()` — no new endpoint, the data is already in `S.lib`.
3. Add a name-search input — client-side substring filter over the already-fetched `S.lib[type]`
   array, same reasoning.
4. Sort remaining rows by `edited` descending by default (this also retroactively fixes the
   hub's `libOf(t.id)[0]` assumption instead of leaving it accidentally correct).
5. Defer true pagination/virtualization to A9's judgment on render cost, but a client-side sort
   and filter can ship without touching the endpoint at all.

---

## 3 · Mental-model assessment — draft / published / active

**Contrary to what `_live-state.md`'s raw data suggests, the UI does not surface the
`status` field to the user at all.** Grep for `.status` usage in `designer.js` shows it is
read/written only for internal bookkeeping (`2401, 2408, 2475, 2644, 5257, 5612`) and **never
interpolated into any user-facing string** — the confusing state the backend recorded
(`head v7, publishedVersion 6, activeVersion 6, status:"draft"`) never reaches a clerk as the
word "draft" contradicting the word "published" on screen, because the client's own
`templateState()` (`1510-1539`) derives its label from `publishedVersion`/`activeVersion`/
`version` alone, treating `status` as orthogonal — independently arriving at the same
conclusion the live-state capture (O1) reached about the *data*, from reading the *client*.
**This is a real strength, not a gap**, and worth recording so the team does not spend a future
pass re-deriving it as a defect.

Within that constraint, `templateState()` already produces clerk-legible language:

| Case | Label shown | Detail sentence |
|---|---|---|
| Active, nothing newer published | `In use` | "Version N is what every print point resolves" |
| Active, but a newer version is published-and-waiting | `In use` + `vN ready` chip | "Version N is in use · version M is published and waiting" |
| Published, not active | `Ready — not in use` | "Version N is published but nothing resolves it" |
| Never published | `Draft` | "Never published — it cannot be issued yet" |

This is the correct shape for a school clerk: state, then consequence, in one sentence, with no
jargon (`1510-1538`). The one place the model is taught *explicitly*, in prose, is the
publish-time modal (`5236, 5264, 5323`): **"Publishing freezes it. Activating is what makes it
print."** — a strong, correctly-timed sentence.

**Where the teaching breaks down: altitude and permanence.**

- The **hub** (one level up from the gallery) collapses all of this to a binary chip — `Active`
  or `No active template` (`2193-2194, 2239-2240`) — dropping the "published but not active"
  state entirely. A school that published but never activated sees the same hub chip as a
  school that never published at all.
- The sentence that teaches the model best — "Publishing freezes it. Activating is what makes
  it print." — is said **exactly once**, at the moment of publish, in a dismissible modal
  (§1a). It is never repeated anywhere the state persists (gallery row, hub card, designer
  topbar) for a template that is sitting in "published, not active."

**Proposed language, if the three-state model needs a name a clerk would use out loud:**

| Current internal term | Proposed clerk-facing term | Why |
|---|---|---|
| `draft` (never published) | **"Being designed"** | "Draft" already means something else to a school clerk (a rough copy of a letter); "being designed" matches what the screen is actually for |
| `published`, not active | **"Ready, not switched on"** | Names the exact fact — it exists, frozen, but issues nothing — using a physical-world metaphor (a switch) a clerk already reasons in |
| `active` | **"Switched on — this is what prints"** | Ties directly to the consequence, not the mechanism |

This is close to what `templateState()` already outputs (`In use` / `Ready — not in use` /
`Draft`) — the recommendation is less "rewrite the labels" (they're already good) and more
**"stop saying it only once."** Repeat the consequence sentence — not just the chip — on the
gallery row itself for any `Ready — not in use` row, and add the missing third state to the hub
chip set instead of collapsing it to binary.

---

## 4 · The designer canvas

The keyboard model is unusually complete for an admin-panel tool: move/hand tools, tool-key
shortcuts (`TOOLKEY`, dispatched `4602`), select-all/invert (`4561-4567`), flat Tab-cycling
(`4569-4574`), Figma-style alignment (`⌥` + letter, `4576-4578`), zoom-to-fit/selection/100%
(`4580-4586`), duplicate/copy/paste (`4591-4599`), z-order (`4600-4601`), undo/redo
(`4589-4590`, `1748-1751`), arrow-key nudge at 1 mm / 10 mm with Shift (`4607-4614`), and a
staged Escape that commits-then-deselects-then-resets-tool rather than doing all three at once
(`4544-4553`). A dedicated shortcuts reference is reachable via a topbar button
(`#keysBtn` → `openKeys()`, `index.php:241`, `designer.js:4945-4966`).

**Discoverability of that shortcut sheet is the highest cognitive-load point, and it is
itself hidden.** `#keysBtn` renders as a small icon-button (`⌨ shortcuts`) inside the zoom
control cluster (`index.php:241`) — a location a first-time user has no reason to visit before
they already suspect keyboard shortcuts exist. Nothing on first entry to the designer (no
tooltip, no first-run hint, no empty-canvas call-out) points at it. Every shortcut in the
41-line table (`4946-4962`) is therefore **discoverable only by accident or by reading the
`FIGMA_STUDY.md` reference the modal's own subtitle cites** (`4963`) — which is a source-code
document, not something a school clerk will ever open.

**What a user can do that they cannot undo.** Two distinct classes:

1. **Delete of a never-published draft.** Modal-confirmed and explicitly stated as permanent
   ("goes for good", `2703`) — correctly framed, per `01a` §5.
2. **Object deletion on the canvas via `⌫`/Backspace, with no confirmation at all**
   (`4606`, `tryDelete()`). This is consistent with a professional design tool's conventions
   (undo covers it) — **except** `S.undo`/`S.redo` are reset to empty on every template load,
   creation, or open (`2289, 2438, 2449, 2477, 5658`). **An object deleted in the *first* action
   of an editing session is unrecoverable by Undo the instant that session ends** — there is no
   cross-session undo, and nothing on screen distinguishes "undo will work" from "undo stack is
   empty" until the user presses ⌘Z and gets the "Nothing to undo" toast (`1748`). A clerk who
   deletes an object, saves (autosave fires 1.5 s later), and returns the next day has no way to
   recover it except manually rebuilding it or, if a version was ever published, rolling back to
   a whole prior *version* — not the object.

**A materially more serious instance of the same class, found this pass:** modal dialogs do
not manage focus or intercept the global keyboard handler.

- `modal()` (`4936-4941`) never calls `.focus()` on anything inside the dialog it opens, sets
  no `role="dialog"`/`aria-modal`, and traps no `Tab` key — the **only** exception in the whole
  file is `askName()` (`2381`, `setTimeout(()=>inp.focus(), 30)`), used solely for naming a new
  custom document type.
- The global `keydown` listener (`4536-4615`) gates almost everything on `S.screen==="designer"`
  (`4537`) and on whether the event target is a form field (`typing`, `4540`) — **it never
  checks whether `#scrim` (the modal backdrop) is open**, except for the `Escape` key
  specifically (`4544-4545`).

**Consequence, traced precisely:** open any confirmation modal that is not `askName` — Delete
draft, Deactivate, Activate/"Make live", Rollback, the letterhead-update comparison, the
compliance-exclusion reason dialog — and focus remains wherever it was before the modal opened
(commonly the canvas/body, since none of these triggers are text inputs). Every canvas
shortcut stays live underneath the visible dialog: `⌘A` selects every object, `⌘D` duplicates
the current selection, `⌘Z` undoes a canvas edit, arrow keys nudge the selection, and **⌫/
Backspace deletes the currently selected object(s) with no confirmation of its own**, all while
a *different* confirmation dialog is on screen and the user's attention is on its text. A clerk
reading "Deactivate this template?" and instinctively pressing Backspace to go back a step (a
common OS-level muscle memory) deletes whatever canvas object happens to be selected underneath
— an outcome that contradicts the entire point of putting a confirmation modal in the way in
the first place.

**Proposed fix.** In `modal()` (`4936`), on open: move focus to the dialog's first focusable
element (default the primary action button, or the first input if one exists — `askName`
already does the input case) and set `role="dialog" aria-modal="true"` on `#modal`. In the
global `keydown` handler, add one guard at the top mirroring the existing Escape special-case:
`if(zq("#scrim").classList.contains("is-on")) return;` for every branch except the ones needed
to operate the modal itself (which should be handled by focus trapping, not by falling through
to canvas handlers). This is a small, contained change with a precise blast radius — it does
not touch save, publish, or any server contract.

---

## 5 · Feedback honesty

**Positives, established by tracing rather than assumed:**
- The dirty/saved/conflict status line (`paintStatus`, `3944-3959`) is deliberately written in
  plain language over raw internals — its own comment states the reasoning ("`lockVersion 19`
  is our word, not the reader's… is my work safe?", `3944-3946`) and follows through: `Looking
  only — changes are not saved` / `Paused — resolve the overlap to carry on saving` /
  `Unsaved changes` / `All changes saved`. This status line is **persistent**, not a toast — it
  does not self-clear.
- `markDirty()` invalidates `S.proofed` on every edit (`1044`) specifically because a prior
  version of this module let the Publish button stay enabled after an edit invalidated the
  server's proof — its own comment names this as an observed live defect (`1049-1053`).
- The proof-render progress bar was rewritten, per its own comment, from a bar that jumped to
  55% and froze — "four seconds of a motionless bar and a disabled button, which is
  indistinguishable from a hang" (`5157-5165`) — to an honest elapsed-seconds counter.

**Where the honesty claim breaks down: the *durability* of a failure notice, not its
existence.**

- **Every failure not caught by the client's own pre-flight `validate()` reaches the user
  exclusively as a toast** (`apiFail`, `978-983`): a pill-shaped element (`doctemplates.css:
  631-637`) that **self-clears after exactly 3200 ms** regardless of message length
  (`toast()`, `1662-1665`, hardcoded `setTimeout(...,3200)`), with no dismiss-and-keep, no
  "view details," and no history. `01a` §4 already established this at the screen level (0 of
  3 screens have a persistent error state); this pass adds that **the failure message itself
  can be materially longer than a toast is built for** (§6) and is durable for less time than
  it would take to read, let alone act on.
- **The save-failure path never retries on its own.** `srvSaveDraft()` on a non-409 error calls
  `apiFail(e,"Save")` and `return false` (`1436-1437`) — `S.dirty` stays `true`, but **no new
  timer is scheduled**; the only thing that triggers another attempt is the user making a
  further edit, which calls `markDirty()` again (`1030-1071`). If a save fails for a transient
  reason and the clerk does not immediately keep typing — they pause to reread what they wrote,
  or step away — the draft sits in "Unsaved changes" (a small status-bar label, not the toast)
  indefinitely, with no automatic retry and no visible countdown or explanation of *why* it
  hasn't retried. The one durable safety net is `beforeunload` (`1078-1088`), which does warn
  before a tab close with real unsaved work — but that only fires on tab close, not on tab
  switch, screen lock, or simply walking away, which is where "I thought it saved" actually
  originates.
- **The empty-vs-failed collapse** (`01a` §4, extended): a failed `srv.templates()` read renders
  the identical "No templates of this type yet" copy a genuinely empty school sees
  (`5626-5629`), and the failed `srv.types()` read on the hub degrades to `console.warn` only
  (`5591-5594`) — invisible to anyone not holding devtools open. Both are already flagged in
  `_live-state.md` L8 as the same catalogued pattern; recorded here only to connect it to the
  toast-durability finding above: **even where a toast does fire for a read failure, it is gone
  in 3.2 s and nothing afterward distinguishes "empty" from "the read failed and gave up."**

**Nothing found where a denied or failed action is reported as a success** — the `phantom
success` bug class this repo has been bitten by before (per `01a` §3 and the file's own
`919-926` doc comment) is genuinely absent from what this pass could trace. The defect here is
the opposite shape: **honest failures that are too transient, and too technical (§6), to be
actionable.**

---

## 6 · Errors a clerk must act on — message rewrites

All four domain-specific refusals named in the brief are `RuntimeException`s thrown from
`Doc_serializer.php`, caught generically by `Doc_templates::_run()`
(`application/controllers/Doc_templates.php:308-323`), which — deliberately, and correctly for
an *unexpected* failure — logs and returns a sanitized `'The action could not be completed.'`
for anything **not** an `InvalidArgumentException`/`RuntimeException`. But these four **are**
`RuntimeException`s, so they take the other branch (`316-320`): `$this->json_error($e->getMessage(), 422)`
— **the exact developer-authored string is sent to the browser verbatim**, then wrapped by
`apiFail()` (`978-983`) into `"{what} failed — {msg}"` and shown in the 3.2-second pill toast
(§5). None of these four messages was written for a school clerk; each names its own PHP class,
an internal rule code, or an internal render engine.

The client's own pre-flight `validate()` (`3529+`) catches **two** of the four shapes before
publish and shows a friendlier gate row instead (`openPublish`, `5206, 5215`) — but it does not
cover the page-number-width check or the empty-repeating-table check at all (confirmed by grep:
no width/`pageNumber` check and no `repeatOver`-emptiness check anywhere in `designer.js`'s
`validate()`). For those two, **the only message a clerk will ever see is the raw one below** —
this is the same "guard exists on paper but isn't wired into every path" pattern
`_live-state.md` L9 found independently in the server's own type gate, one layer up in the UI.

### Rewrite 1 — page-number box too narrow

**File/line:** `application/libraries/Doc_serializer.php:522-534`
**As shown to the clerk** (via `apiFail`, prefixed `"Publish failed — "` or `"Proof failed — "`):

> Doc_serializer: page-number object 'r_page' is 12.0mm wide, too narrow for the page-number
> placeholder at 10.0pt (needs about 18mm). mPDF would break the placeholder across lines and
> print it literally instead of a number — widen the box or reduce the type size.

This is, of the four, the **best-written** of the raw messages — it does name a remedy — but
it names an internal render engine (`mPDF`) the clerk has never heard of, an object id
(`r_page`) that maps to nothing visible on screen, and buries the actual instruction ("widen
the box or reduce the type size") at the very end of a 250-character sentence that will be
gone from the toast before most readers finish the first clause.

**Rewrite:**

> **The page number won't fit.** "Page number" is too narrow to hold its own text at this font
> size — it would print as broken text instead of a number. **Make the box wider, or make the
> text smaller**, then try again.

(Client-side improvement, not just copy: this check can run in `validate()` today using the
same `1.8 * sizePt` estimate the server uses, `Doc_serializer.php:521`, so a clerk sees this as
a red gate row *before* clicking Publish, the same way line-height and off-contract already are
— not as a surprise after the click.)

### Rewrite 2 — no `lineHeight`

**File/line:** `application/libraries/Doc_serializer.php:462-467`
**As shown to the clerk:**

> Doc_serializer: object 'obj_k3f9' has no style.lineHeight. It is mandatory on every text
> object (G0.5) — without it mPDF and the browser disagree by up to 2x and the error compounds
> down the chain.

Cites an internal rule code (`G0.5`), an internal engine (`mPDF`), and an object id with no
on-screen referent — a clerk cannot select "obj_k3f9" from any menu, layer list, or search.
(Client pre-flight already catches this one, per §above — the raw message is the fallback for
whatever gap remains between the two implementations.)

**Rewrite:**

> **One of your text boxes is missing a line spacing setting**, and without it the preview and
> the printed PDF can disagree by up to 2×. Open the text object, then Style → Line height, and
> set a value — even the default is fine. *(If you can't tell which object — every text object
> on this page needs this set; try each one from the Layers panel.)*

(The parenthetical is an honest admission that the UI cannot currently point at the object —
see the proposed fix below.)

### Rewrite 3 — off-contract key

**File/line:** `application/libraries/Doc_serializer.php:682-687`
**As shown to the clerk:**

> Doc_serializer: object 'obj_x7b2' binds 'staff.principalName', which this document type's
> contract does not declare. This is the mail-merge failure the append-only contract rule
> exists to prevent.

The single worst of the four: it explains the *system's own design rationale* ("the append-only
contract rule exists to prevent") rather than the clerk's situation, and gives **no remedy at
all** — not "remove the field," not "this field isn't available for this document type," just a
description of why the rule exists.

**Rewrite:**

> **This document is using a field that doesn't belong to it.** "Principal's name" isn't one of
> the fields available for a {Document type name}. Remove it from the object, or replace it with
> a field from the panel on the right.

**Cross-cutting proposed fix for all three:** the object id (`obj_k3f9`, `r_page`, `obj_x7b2`)
is the one piece of information that would make every one of these messages actionable, and it
is currently thrown away by the presentation layer. `Doc_serializer` already returns the id in
every message; `apiFail()` already receives it inside the string. On catching a publish/proof
failure, parse the leading `object '(\w+)'` (or `page-number object '(\w+)'`) out of the
message, and if it matches an id in `S.tpl.objects`, **select that object and scroll it into
view** the same way `openCite()`'s "refused" path already does for a different error class
(`4755`, `box.scrollIntoView(...); box.focus();`). This turns "here is a string with an id in
it" into "here is the thing, now selected, that is wrong" — the single highest-leverage fix in
this section, and it reuses a pattern the codebase already has.

---

## 7 · Accessibility and reach

- **Zero ARIA.** `grep -c "aria-\|role=\"\|tabindex"` returns **0** across both
  `designer.js` (5668 lines) and `application/views/doc_templates/index.php` (260 lines) —
  confirmed, not sampled. A drag-and-drop, absolute-position canvas editor with a rich
  inspector, drawers, and modal dialogs carries no `role`, no `aria-*` state, and no explicit
  `tabindex` anywhere in the module.
- **Keyboard-only operation of the canvas is partially possible** (§4: nudge, Tab-cycling,
  delete, duplicate, align, undo/redo all have key bindings) **but object creation does not** —
  every tool (`text`, `table`, `image`, `shape`, `qr`) is placed by a `mousedown`/drag gesture
  on the desk (`3995, 4187-4229`); nothing in the file offers a keyboard-driven "place at
  default position" for a newly selected tool. A keyboard-only user can rearrange an existing
  layout but cannot originate one.
- **Modal focus and keyboard containment is the sharpest gap** (detailed in §4): no initial
  focus, no `role="dialog"`, no focus trap, and the canvas's own destructive shortcuts remain
  live underneath every dialog except `askName`.
- **Touch/tablet support for the primary manipulation surface is architecturally unlikely,
  though unverified at runtime (E2 ceiling — no observation claim made).** The entire drag/
  resize/marquee-select/pan/ruler-guide system is wired exclusively through `mousedown`/
  `mousemove`/`mouseup` (11 occurrences, `designer.js`) — confirmed by grep. The only listeners
  using the modern, touch-and-mouse-unified Pointer Events API are on the signature-drawing
  sub-canvas (`4460-4463`, 3 occurrences) — a narrow feature, not the core canvas. Browsers do
  synthesize compatibility mouse events from touch in many cases, but a continuous multi-step
  drag (select → resize → release) built on that compatibility layer, with no `touch-action`
  CSS declared anywhere in `doctemplates.css` (grep confirms none), is a known-fragile pattern —
  named here as an absence to verify at runtime (T0-class question for a human pass), not
  claimed as a failure.
- **Contrast tokens and dark mode are, by contrast, genuinely well covered** — worth recording
  as a strength, not just a checklist item. `doctemplates.css:29-90` defines a complete light
  palette on `.zxdt` and mirrors it under both `prefers-color-scheme:dark` and an explicit
  `data-theme="night"` override, consistent with the pattern this codebase's own artifact
  conventions call out as correct (media-query AND explicit-override both defined). The one
  deliberate non-swap: `--page`/`--page-ink` stay fixed white/black regardless of theme — correct,
  since the canvas page represents physical printed paper, not application chrome.
- **Touch targets:** the tool rail was explicitly widened in a prior pass — `.tabstrip button{
  width:44px; height:46px; }` (`doctemplates.css:815`), at or just above the commonly-cited 44px
  minimum — but this single explicit sizing decision is not shown to be systemic; no CSS custom
  property or utility class enforces a minimum interactive size elsewhere (e.g. inline row action
  buttons in the gallery, `.btn--sm`, are not separately verified this pass — `[UNKNOWN]`).
- **Residual risk from the three-patched-Bootstrap-collisions pattern is open-ended, not
  closed.** The brief names it as established; tracing it further: it is not three isolated
  bugs but **one open class of bug, patched reactively, twice over on the same selector.**
  `doctemplates.css:476-486` (`.row` margin), `582-589` (`.modal` position/inset), and
  `786-803` (`.row::before/::after` clearfix-as-grid-item) are three fixes for the *same*
  colliding class name (`.row`), each found only after a visible symptom (a staggered zigzag
  layout, a clipped modal, a misaligned Page panel) — the file's own comment at `798-802` says
  so explicitly: *"This is the third distinct way the same shared class name has bitten."* A
  **fourth**, on a different colliding selector, sits immediately after it: bare `label`
  (`805-809`, Bootstrap's `display:inline-block; margin-bottom:5px; font-weight:700`, only
  partially reset). Because `.zxdt`'s own rules are scoped (`.zxdt .row`, `.zxdt label`) while
  Bootstrap's global stylesheet is not, **every future class name this module introduces that
  happens to match a Bootstrap selector is a live landmine**, found only by a human noticing a
  visual break, not by any build-time or lint-time check. No systemic reset (a wrapper-scoped
  CSS reset, `all:revert-layer`, or Shadow DOM isolation) exists to close this class of defect
  permanently — the fix-per-symptom approach has a perfect track record of eventually finding
  each collision, at the cost of shipping each one first.

  **Proposed fix:** add one rule near the top of `doctemplates.css`, immediately after the
  `.zxdt *{box-sizing:border-box}` reset, that reverts the highest-risk Bootstrap surface area
  inside the wrapper wholesale — at minimum `.zxdt :is(.row,.modal,.btn,.card,.badge,.table,
  .close,.nav,.alert,.progress,.dropdown,.tooltip,.list-group,.pagination,label,.form-control)
  { all: revert-layer }` (browser-support caveat: `revert-layer` needs a modern evergreen
  browser, consistent with what an admin-panel-only tool can assume) *before* this module's own
  rules for those same selectors, so every future collision on a common Bootstrap class name is
  closed by construction rather than by the next person who happens to notice a zigzag.

---

## 8 · The two-system experience, from the user's side

Extending `_live-state.md` L1 and the dependency graph's §6 (both established, not restated):
the sidebar's Certificates menu (`application/views/include/header.php:666-669`) points
exclusively at the legacy `Certificates.php` — Dashboard, Templates, Generate, Issued — and
that legacy controller is **not a stub**: it has its own RTDB-backed data model, its own
`Generate` and `Issued` flows, and (per the dependency graph §6) its own working
issue-a-certificate path, gated by the identical RBAC key (`'Certificates'`) the Document
Engine uses.

**What a clerk following the sidebar link would reasonably conclude:** that this *is* the
certificates module — it has the shape of a complete product (a dashboard, a template list, a
generate action, an issued log), it responds to the same permission that says "you may work
with Certificates," and nothing on any of its four pages signals that a second, different,
newer system exists. There is no banner, no "try the new designer," no deprecation notice, no
cross-link in either direction — confirmed by the dependency graph's exhaustive route/nav trace
finding zero cross-references. A clerk who has always used the legacy `Generate`/`Issued` flow
has no discoverable reason to ever learn the Document Engine exists, and would build their
working habits, and their institutional muscle memory, entirely inside the system that is
**not** the one under certification, that carries no `Doc*` test suite (0 PHPUnit tests,
dependency graph §6), and that stores its own parallel record of what was "issued" in a
different database with a smaller, hardcoded type list (`bonafide`, `transfer`, `character`,
`custom` only — `Certificates.php:45` — versus the Document Engine's config-driven catalogue of
8+ types including two Kerala-specific statutory instruments the legacy system has no concept
of at all).

This is the sharpest form of the discoverability problem named in the brief: it is not merely
that the Document Engine is hard to find — it is that the thing a clerk finds *instead*, in its
place, in the one location a clerk is trained to look, is a **complete, independently
functioning, unrelated certificates system** that will never volunteer that it is the wrong one.

**Proposed fix (UX-scoped, not a routing/ownership decision — that's `H3` in `_live-state.md`
L1/L6, a business call this report does not make):** at minimum, add a one-line, dismissible
banner to every legacy `Certificates.php` page — *"A newer certificate designer is available at
{link} — templates and issued certificates here are not shared with it."* — so a clerk currently
inside the wrong system at least learns a second one exists, without this report taking a
position on which system should ultimately own the workflow.

---

## Counts

- Gallery sort/group/filter/pagination controls found for the template list: **0** (`.sort(`
  never called on `S.lib`/`libOf` results; no search `<input>`; no pagination markup).
- Visual cues distinguishing the one active template from the rest of its type's list: **1**
  (a 3px `border-left` + in-place chip; not pinned, not sorted to top).
- Correctness-sensitive decisions between a zero-template school and one live, active
  certificate: **5–7** (type choice, starter/blank choice, field binding, implicit line-height
  requirement, Proof, Publish, Activate), presented with **0** guided/checklist affordances.
- Domain-specific refusal messages reviewed for clerk-actionability: **4** (page-number-too-narrow,
  no-lineHeight, off-contract key, empty repeating table); raw-developer-string forwarded to the
  client verbatim on **4 of 4** as the fallback path; covered by a friendlier client-side
  pre-flight gate on **2 of 4** (lineHeight, off-contract); **0 of 4** identify or select the
  offending object on screen.
- Toast auto-dismiss timing for every failure (including the 4 refusals above): **3,200 ms**
  fixed, independent of message length (`toast()`, `designer.js:1662-1665`).
- ARIA/role/tabindex occurrences across `designer.js` + `index.php`: **0**.
- Modal opens that set initial focus inside the dialog: **1 of ~15+** call sites (`askName`
  only; every `modal(...)` call was not separately enumerated for total count, but grep confirms
  `.focus()` appears exactly 3 times in the whole file, one of which — `2903` — is for inline
  text editing, not a modal).
- Global keydown branches that check whether a modal (`#scrim`) is open before acting on the
  canvas: **1 of ~20+** (`Escape` only).
- Individually-patched Bootstrap selector collisions found in `doctemplates.css`: **4** distinct
  fixes across **2** colliding selector names (`.row` ×3, `label` ×1) — not 3 as the brief's
  framing suggested; extended by one more instance this pass.
- Pointer/touch-unified event listeners vs. legacy mouse-only listeners driving canvas
  manipulation: **3** (signature sub-canvas only) vs. **11** (core drag/resize/marquee/pan/
  ruler-guide system).
- `S.undo`/`S.redo` resets per session-boundary event (load/create/open): **5** call sites
  (`2289, 2438, 2449, 2477, 5658`) — confirms undo history never survives a reload, refresh, or
  re-open.

## Named gaps / could not establish

- `[UNKNOWN]` — whether touch input actually operates the canvas drag/resize/marquee system at
  all (no `touch-action` CSS, mouse-only listeners on the core surface; not runtime-verifiable
  at E2).
- `[UNKNOWN]` — actual pixel touch-target size of secondary controls (gallery row action
  buttons, inspector fields) beyond the one explicitly-sized tool rail; not measured this pass.
- `[UNKNOWN]` — whether the friendlier client-side `validate()` gate and the server's
  `Doc_serializer` throws are exhaustively enumerated as a pair anywhere in the test suite, or
  whether new server-side rules risk silently falling back to the raw-string path by default —
  this pass found the page-number-width and empty-table cases uncovered by inspection, not by a
  complete cross-reference of every `RuntimeException` in `Doc_serializer.php` against every
  `blocking` type in `validate()`.
- `[UNKNOWN]` — real behavior of the modal-focus-bleed defect (§4) under an actual browser and
  screen reader; the reasoning is a precise static trace (guard exists for Escape only,
  `.focus()` called in exactly one modal path) but no runtime reproduction was performed at this
  evidence ceiling.
