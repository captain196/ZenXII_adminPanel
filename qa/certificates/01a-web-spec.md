# 01a · A2 WEB-SPEC — Admin Web surface (Document Engine / Certificates)

**Evidence ceiling: E2 (static trace only). No code executed. No "PASS" claims.**
Scope: `application/controllers/Doc_templates.php`, `application/views/doc_templates/index.php`,
`assets/js/doctemplates/designer.js` (5668 lines), `assets/css/doctemplates.css` (935 lines).

---

## 1 · Screen and route inventory

Three screens, one view (`index.php`), switched **entirely client-side** by `go(screen)`
(`designer.js:2064-2071`): `hub` (D0, document types), `gallery` (D1, templates for one type),
`designer` (D2, the editor). The controller has 3 page-load methods (`index`, `gallery/(:any)`,
`design/(:any)`) that each render the same view with a different `$active_tab` → `BOOT.screen`
[CONFIRMED, `index.php:22-31`, `Doc_templates.php` CAPABILITIES table].

**`go()` never calls `history.pushState`/`replaceState`, and the file has zero `popstate`/
`hashchange` listeners** — confirmed by exhaustive grep across all 5668 lines: zero matches for
`pushState`, `replaceState`, `popstate`, `hashchange`, `location.href` assignment [CONFIRMED,
E2, whole-file grep]. `BOOT.screen`/`BOOT.docType`/`BOOT.templateId` are read exactly once, at
load (`designer.js:5521-5522, 5539-5540`), to pick the *initial* screen; every subsequent
navigation (crumb clicks `designer.js:4896-4897`, gallery card clicks `2197, 2243`, "Open"
`2282-2292`, `openTemplate` `2450, 2480`) mutates in-memory `S.screen`/`S.docType`/`S.tpl` only.

**Consequences, given the module also has no sidebar link** (confirmed absent by the
dependency graph, §7, and re-confirmed here — no `<a>`/nav element in `index.php` links
anywhere but `data-go` buttons that call `go()`, never a URL):

- **Bookmarking** only ever captures the *entry* screen (`/doc_templates`, `/doc_templates/
  gallery/{type}`, `/doc_templates/design/{id}` — all explicitly routed, `routes.php:1396-1415`
  per the dependency graph). A bookmark taken from inside the designer after navigating there
  via the gallery (rather than a direct `/design/{id}` link) is a bookmark of the gallery screen
  the user started on, not of the template they were looking at, because the URL never changed.
- **Refresh** while in the designer, if that designer was reached by in-app navigation (hub →
  gallery → open), reloads the *original* route and drops the user back on the hub or gallery —
  the open template and any pending unsaved-but-not-yet-autosaved edit are gone from view (the
  autosave debounce, §6, may still have persisted the last saved state server-side, but the
  session state — selection, undo stack, scroll — is lost). Only a designer reached via a direct
  `/design/{id}` URL survives a refresh, because that route alone re-supplies `BOOT.templateId`.
- **Browser Back/Forward** does nothing inside the SPA — no history entries were pushed for
  in-app screen changes — so pressing Back from the designer (reached by clicking through the
  gallery) leaves `doc_templates` entirely, landing wherever the browser history had the user
  before the module was opened, not on the gallery.
- The BOOT-time deep-link path itself (design/{id} and gallery/{type} landing directly on the
  right screen with the fixture blanked out first) is well engineered and documented as a fix
  for a real prior bug (`designer.js:5528-5537, 5634-5638` — "a bookmark, a browser Back, or a
  link someone pasted always dropped you at the top") — but that fix covers only the *load*, not
  in-app navigation afterward, which was never wired to the URL at all.

No separate modal-only "screens" exist outside these three; all dialogs (`#modal`/`#scrim`) are
DOM overlays inside `screen-designer`, opened/closed via `modal()`/`closeModal()`
(`designer.js:4938-4942`) — none of them touch the URL either.

---

## 2 · Every user-triggerable action — validation / endpoint / success / failure handling

All server calls go through one `api()` wrapper (`designer.js:934-970`) and one `srv` map
(`designer.js:987-1019`); no other `fetch(` call exists in the file (confirmed by grep — the
only other network primitive used is `navigator.sendBeacon` for `leave`, `designer.js:1240-1246`,
which is fire-and-forget by design and has no success/failure handling at all, consistent with
its "best effort" framing in-code).

| Action (UI trigger) | Client validation before call | Endpoint (`srv.*`) | On success | On failure |
|---|---|---|---|---|
| Rename template (crumb, blur) | none — empty falls back to `"Untitled template"` (`2087`) | none directly; folded into next autosave via `save` | `markDirty()` schedules save | same as autosave |
| Autosave (debounced 1.5s after any edit) | guarded by `S.creating`/`S.conflict`/`S.readOnly`/`isPersistedId` (`1055-1069`) — not a *value* validation, a *should-we-call-at-all* gate | `save` | `S.dirty=false`, `snapshotBase()`, silent toast only if not `silent` | 409 → `resolveConflict()` (3-way merge, see §6); any other error → `apiFail()` toast, `S.dirty` stays true |
| Manual Save (`#saveBtn`) | `if(!S.dirty && !S.conflict) toast("Already saved")` | `save` | toast "Saved" | `apiFail` toast |
| Create template (starter or blank) | none on payload; UI just picks a `docType`/`seed` | `create` | `adoptTemplate()`, `go("designer")` | `apiFail()`, stays on gallery |
| Create custom document type (`askName`) | non-empty after trim + "has letters or digits" via `customTypeFor()` (`2372-2376`); `maxlength="60"` HTML attribute | `create` (docType becomes the derived custom id) | opens the new/existing type's gallery | `apiFail()` |
| Duplicate ("Save mine as a copy" in conflict dialog) | none | `duplicate` | new template adopted, toast | `apiFail()`, copy button re-enabled |
| Proof PDF | none (uses whatever is currently in `S.tpl`) | `proof_pdf` | `S.proofed` set from hash, PDF link shown | `apiFail()` |
| Publish | none client-side beyond `S.dirty` autosave-first (`5288`) — the actual gate (proof freshness, content hash) is server-only | `publish` | version bumped, `offerActivation()` modal | `apiFail()` — including the "no proof" / "design changed since proof" server refusals, surfaced only as a generic `ApiError` toast, not a dedicated explanatory dialog |
| Activate / "Set active" (gallery row, offer-after-publish, rollback) | none | `activate` | `S.active[...]` set, toast | `apiFail()`, button re-enabled |
| Deactivate | confirm modal only | `deactivate` | pointer cleared, toast | `apiFail()` |
| Delete draft | confirm modal only; row must be `!row.publishedVersion` to even show the button, and gated by `SRV.can.manage` (`2611-2615`) | `delete` | row removed from `S.lib`, toast | `apiFail()`, button re-enabled |
| Upload asset (crest/signature/image) | none found beyond browser file picker `accept` | `upload_asset` | asset path stored on the object | `apiFail()` (not traced further inside this pass — file-type/size enforcement is server-side only as far as this pass found, see §7) |
| Version rollback | confirm modal | `activate` (with `version`) | pointer set to that version, toast | `apiFail()` |

**No client call has a missing endpoint** (dependency graph §2, re-confirmed by spot checks
above). **Two endpoints are wired in `srv` but have zero call sites anywhere else in the file**
beyond their own definitions: `srv.archive` (`1007`) and `srv.validate` (`1000`) — confirmed by
`grep -n "\barchive\b"` returning only the `srv.archive:` definition line, and by the earlier
dependency-graph pass for `validate`. `archive` is a **manage-graded, legally consequential**
state transition per the controller's own comment (`Doc_templates.php:1067-1073`, mirroring the
"archived instead" language shown to the user in the Delete dialog copy,
`designer.js:2705-2708`) with no button, menu item, or code path in the shipped UI that reaches
it — the UI's Delete dialog *tells the user* published templates are archived instead of
deleted, but nothing in the client can actually perform that archive.

---

## 3 · Fail-closed audit

`api()` (`designer.js:919-970`) checks, in this order, before ever returning success to a
caller: `fetch` didn't throw → response body parses as JSON → **`r.ok`** → body exists →
**`body.status !== "error"`**. Any of those failing throws `ApiError`, which every caller either
`await`s directly (propagating to its own `catch`/`apiFail`) or lets bubble. **No call site in
the file bypasses `api()`/`srv`** — confirmed by grep: the only other network calls are
`navigator.sendBeacon` (`leave`, fire-and-forget, no result to misreport) and the `<a href>`
PDF-view links (`version_pdf`, `proof_pdf` download), which are plain GET navigations, not
AJAX-with-a-result, so there is no success/failure state for them to misreport either
[CONFIRMED — E2, exhaustive grep for `fetch(` and `XMLHttpRequest`].

**No path found in this pass where a denied or failed action is reported as a success.** This
is a design the code is explicit about defending (`designer.js:919-926` doc comment names the
exact bug class from `CLAUDE.md`/`feedback_phantom_success_fetch.md`). Two things this pass
could **not** verify (`[UNKNOWN]`, E2 ceiling):
- Whether `body.csrf_token` rotation (`965`) can itself desync under a specific interleaving
  (e.g. two rapid POSTs) — not traced past this file, would need the CI3 CSRF library and a live
  session.
- Whether every server endpoint actually returns `{status:'error', message}` on every failure
  path rather than a bare non-2xx with no parseable body (the client handles the latter too, via
  `!body`, but this pass did not cross-check every one of `Doc_templates.php`'s ~24 endpoints
  for consistent envelope shape — that is more naturally A1/A4's controller-side territory).

---

## 4 · Error / loading / empty states per screen

| Screen | Loading | Empty | Error |
|---|---|---|---|
| Hub | none distinct — `paintHub()` renders immediately from whatever `S.school`/type catalogue is in memory (fixture, then live) | N/A (always shows the fixed type catalogue) | **none.** If `srv.types()` fails, the catch block (`5588-5591`) only `console.warn`s; the hub silently keeps showing default/fixture school state/board (which gates which certificate types are offered) with no on-screen indication that the read failed |
| Gallery | yes — `S.loading` drives `"Checking which template is active…"` (`2528`) and `"Loading your templates…"` in the empty-list slot (`2559-2561`) | yes — distinct copy: `"No templates of this type yet…"` (`2560-2561`) | **collapses into the empty state.** On `srv.templates()` failure (`5628-5631`), `S.lib={}` then `repaintScreen()` — the gallery renders the *identical* "No templates of this type yet" message a genuinely-empty school would see, plus a toast (`apiFail`) that self-clears after ~3.2s (`designer.js:1662-1665`). Once the toast fades there is no persisted way to tell "you have none" from "the read failed" apart from re-navigating and hoping it works |
| Designer | yes — name shows `"Loading…"` in the crumb while `S.loading` (`2086`); identity is deliberately blanked before the fetch resolves (`5525-5527`) specifically to avoid showing another template's name/state, per its own comment | N/A (a template is always adopted or the screen never opens) | `apiFail(e, "Opening the template")` toast only (`5641-5644`) on `srv.template()` failure — no dedicated "couldn't open this template" screen state; the designer is left showing whatever the blanked/fixture shell had |

**Named gaps:** no persistent (non-toast) error banner or retry affordance exists anywhere in
the module for a failed read. Every read failure degrades to "looks like an empty/default
state" once the 3.2s toast clears.

---

## 5 · Destructive-action table

| Action | Confirmed? | Reversible? | Notes |
|---|---|---|---|
| Delete draft | yes — dedicated modal, explicit "Delete draft" button, not a bare confirm() (`2699-2718`) | **No** — irreversible, and the modal says so plainly ("goes for good") | Only reachable for a never-published draft; a published template cannot be deleted (enforced both in server copy shown to the user and, per `Doc_template_service`, presumably server-side too — not independently re-verified this pass) |
| Deactivate | yes — modal (`2779-2784`) | Yes — reactivatable at any time (stated in copy) | Correctly framed as consequential ("every print point… fails closed") without being framed as irreversible, which matches reality |
| Activate ("Make live") | yes — modal with a gate list explaining what's replaced (`2727-2745`) | Yes — the replaced template "stays published and can be reactivated" (own copy) | The modal itself is the confirmation; no capability check gates the *button* that opens it (see §8) |
| Rollback (activate a historic version) | yes — dedicated modal, framed as "recorded as a rollback" (`5442-5452`) | Yes — same mechanism as activate | |
| Publish | not a confirm-style modal, but the primary CTA itself; server refuses without a fresh proof | Effectively irreversible **per version** (a published version is immutable/never rewritten, `Doc_template_service.php:516-522`) but publishing again (a new draft → new version) is unlimited, so the *type* isn't locked out | No client-side "are you sure" before Publish beyond the button itself — arguably fine, since publish alone has no visible effect until activated, but there is no explanatory modal either, unlike every other consequential action |
| Archive | **UI-unreachable** (§2) | server semantics not inspected this pass | Cannot be confirmed or unconfirmed from the UI because there is no UI path to it at all |
| "Keep theirs" (conflict resolution — discards the local unsaved copy) | the conflict modal itself is the confirmation (explicit button, explanatory copy) | **No** for the local unsaved edits on the overlapping objects (the rest is merged, per the modal's own claim) | Not a separate "are you sure you want to discard" beyond selecting the option — consistent with the rest of the module's one-modal-is-the-confirmation pattern |

**No irreversible-and-unconfirmed action was found** in the reachable UI. The one genuinely
irreversible, unconfirmed-because-unreachable action is `archive` — moot only because nothing
can trigger it.

---

## 6 · Concurrency / multi-tab / stale state

- **Optimistic locking**: every `save()` call sends `lockVersion`; a server-side mismatch
  returns HTTP 409, which `srvSaveDraft` specifically detects (`1436-1439`) and routes to
  `resolveConflict()` rather than treating it as a generic failure.
- **Same template open twice** (two tabs/windows): each page load is an independent JS module
  instance with its own `S`. Nothing synchronizes them client-side (no `BroadcastChannel`,
  `localStorage` event, or `postMessage` found — grep confirms none of those three appear in
  the file). The only cross-tab signal is server-mediated: (a) `presence` — a 60s heartbeat
  (`startPresence`, `1214-1227`) that shows a "N others here" chip but is explicitly documented
  as advisory ("presence is a courtesy; never interrupt the work for it", `1223`) — it does not
  block or warn before an edit, only after opening, via a one-time "Just look / Work on my own
  copy / Edit together" prompt when presence data is already known at open time (`1160-1199`);
  (b) the 409 conflict on save, which is the actual enforcement.
- **Merge on conflict**: `mergeObjects()` (`1264-1298`) does an object-granularity 3-way merge
  against `S.serverBase` (the snapshot taken at load, advanced on every successful save,
  `1416-1417`). Disjoint edits merge silently (with a toast saying so — `1327`); a true overlap
  (both sides changed the *same* object to *different* values) opens a modal offering
  Keep-theirs / Save-mine-as-copy / Keep-mine (`1330-1362`). This is a materially more careful
  design than "last write wins" or "reject and reload," and is documented as a deliberate fix
  for an earlier cruder version.
- **Idle-then-act on stale state**: `beforeunload` only fires on browser navigation/tab close,
  not on in-app screen switches, so a page left open and idle for a long time, then acted on,
  still routes through the normal save→409→merge path — no special idle handling was found, but
  none appears to be needed given the merge design. One open question this pass could not
  settle from source alone: whether a `lockVersion` held across a very long idle period (hours)
  behaves any differently server-side (e.g., a session/claims expiry interacting with the save
  call) — `[UNKNOWN]`, outside static reach.

---

## 7 · Validation inventory — client-only flagged

| Input | Client-side check | Server-side twin found? |
|---|---|---|
| Template name (crumb, `contenteditable`) | none beyond "empty → Untitled template" fallback (`2087`) — **no length cap at all**, client or found server-side | not found in `save()`/`create()` (both only strip *lifecycle* keys, `Doc_templates.php:620-630, 646`); **`[CLIENT-ONLY / UNBOUNDED]`** |
| Custom document name (`askName` modal) | `maxlength="60"` HTML attribute + "has letters or digits" regex via `customTypeFor()` | not found — `create()` only strips lifecycle keys from `seed`, no length/charset re-check on the type-derived name observed in `Doc_templates.php:600-639`; **`[CLIENT-ONLY]`** for the 60-char cap specifically (a raw `<input maxlength>` is trivially bypassable via a raw POST) |
| Page margins / object x·y·w·h (mm fields) | `evalMm()` (`1651-1661`) rejects non-numeric/non-arithmetic input (toast + refuse) but performs **no range clamp** — a negative margin or an arbitrarily large value (e.g. `99999`) parses as valid | not found in `Doc_template_service::save()` (`314-346`) — the patch is written after stripping only the 8 lifecycle keys, with no numeric bounds check on `page`/`marginsMm`/object geometry; **`[CLIENT-ONLY, AND EVEN THE CLIENT CHECK IS NON-RANGE]`** |
| Text object font size | `type="number" step="0.5" min="4"` (`2947`) | not independently re-verified server-side this pass; HTML `min` is not itself an enforcement mechanism once past the browser (a direct POST bypasses it) — `[LIKELY CLIENT-ONLY, unconfirmed server twin]` |
| Table column width (%) | HTML declares `min="5" max="100"` (`3468`) but the actual write handler only enforces `isFinite(n)&&n>0 ? Math.min(100,n) : null` (`4806-4807`) — **the declared `min="5"` is not enforced by the handler**, only the `max="100"` half is (via `Math.min`) | not found server-side |
| Custom "why excluded" reason (compliance exclusion) | non-empty check with a specific message ("A reason is required — an unexplained exclusion is an audit finding", `3836`) | not traced this pass — `[UNKNOWN]` |
| Uploaded asset (image) | none found in `designer.js` beyond the browser's native file picker (no client-side type/size check before `srv.uploadAsset`) | server-side handling exists at `Doc_templates.php:910-961` (per dependency graph) but this pass did not re-derive its exact accepted-type/size logic — flagged for cross-check, not re-verified here |

**Net finding**: every numeric geometry field (page margins, object position/size, table column
width) that this pass could locate has **either no server-side bound at all**, or a
client-side bound (`min`/`max` HTML attributes) that is **not actually enforced** by the JS
handler that reads it, or both. The one thing genuinely enforced everywhere it matters is
*parseability* (via `evalMm`'s regex gate) — not *range*.

---

## 8 · RBAC surfacing in the UI (destructive/consequential actions) — additional finding

`SRV.can.manage` gates only **two** things in the whole file: the Delete-draft button's
visibility (`designer.js:2611-2615`) and the rollback button inside Version History
(`5388, 5401`). `SRV.can.edit` is **never read anywhere in `designer.js`** (zero hits) — which
is consistent with `edit` being the capability required just to load the `design` page at all
(`Doc_templates.php` CAPABILITIES: `'design' => 'edit'`), so an edit-only user cannot reach the
JS without also having edit.

But three other **manage-graded** actions are rendered **unconditionally**, with no
`SRV.can.manage` check anywhere near them:
- **Publish** — `#pubBtn` in the designer topbar (`2136`), always in the DOM whenever the
  designer screen is on.
- **Activate ("Make live")** — the gallery row action (`2600-2605`) and the
  post-publish "Set v# active" offer (`5309-5322`, `5297-5303`).
- **Deactivate** — the gallery row action (`2611`, unconditional — contrast with the
  `SRV.can.manage` check three lines later that gates *only* Delete).

So a staff member holding `edit` but not `manage` on the Certificates module can open a
template, click Publish or Make-Live or Deactivate, and only discover the denial from the
generic `apiFail()` toast the server's 403 produces — the UI offers no visual cue in advance
that these are actions they cannot complete. This is not the phantom-success bug class (the
server correctly fails closed and the toast correctly reports it, per §3) — it is a phantom-
*availability* gap: buttons for actions the role cannot perform are shown as if enabled.

---

## Counts

- Screens: 3 (`hub`, `gallery`, `designer`), all client-state-switched, 0 with URL sync after
  initial load.
- AJAX endpoints called through `api()`/`srv`: 20 of 24 controller endpoints reached from some
  UI action (per §2/dependency graph); `archive`, `validate`, `preview`, `save_block` = 0 call
  sites in `designer.js`.
- Destructive/consequential actions inventoried: 7 (delete, deactivate, activate, rollback,
  publish, archive, conflict "keep theirs"); 6 of 7 reachable from the UI; 6 of the reachable 6
  are confirmed via a modal; 0 irreversible-and-unconfirmed among the reachable set.
- Manage-graded UI controls checked for `SRV.can.manage` gating: 2 of 5 found (Delete, Rollback)
  gate correctly; 3 of 5 (Publish, Activate, Deactivate) render unconditionally.
- Numeric/text inputs inventoried: 6 (template name, custom doc name, page margins/object
  geometry, font size, column width %, exclusion reason); confirmed client-only (no server twin
  found) for 3 of 6 (template name — unbounded both sides; custom doc name length cap; geometry
  range); 1 of 6 has a declared-but-unenforced client bound (column width `min="5"`).
- Screens with a distinct, non-toast error state for a failed read: 0 of 3.
- `history.pushState`/`replaceState`/`popstate`/`hashchange` occurrences in `designer.js`: 0.
- Cross-tab sync primitives (`BroadcastChannel`/`localStorage` events/`postMessage`) found: 0 —
  concurrency handled entirely server-side via `lockVersion` + 409 + merge.
- Bare (non-`.zxdt`-scoped) CSS selectors in `doctemplates.css`: 0 found in a full-file scan
  (all rules scoped or in documented `@media`/`@keyframes` blocks) — three *historical*
  Bootstrap-collision defects (`.row` negative margin, `.modal` position/inset, `.row`
  pseudo-elements as grid items) are each individually patched with a specific override, not
  resolved with a systemic reset.

## Named gaps / could not establish

- Whether the 7 endpoints missing an explicit `routes.php` entry (`get_versions`, `version_pdf`,
  `presence`, `leave`, `duplicate`, `deactivate`, `delete` — per dependency graph §1a) actually
  resolve via CI3's default segment routing rather than 404ing: static reasoning only, not
  exercised — needs a runtime-capable pass (A4).
- Whether `Doc_templates.php`'s ~24 endpoints all return a consistent `{status:'error',
  message}` envelope on every failure path (only the client's handling of that envelope was
  audited here, not the server's actual emission on every branch).
- Server-side length/shape validation for the uploaded asset (`upload_asset`) beyond what the
  dependency graph already located structurally — not independently re-derived this pass.
- Whether `lockVersion`/session behavior changes over a multi-hour idle period (claims
  expiry interaction) — no client-side special-casing found, but the server side of that
  interaction is out of this pass's file set.
- Exact validation performed by the "why excluded" compliance reason field beyond the
  non-empty check — not traced into `Doc_compliance.php`.
