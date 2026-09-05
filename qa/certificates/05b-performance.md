# 05b · Performance / scale — Document Engine (Certificates)

**Agent: A9 · PERF-ANALYST.** Evidence ceiling **E2** for every code-derived claim
(`file:line`), building on the **E3** measurements in `_live-state.md` (85 real templates,
`SCH_B56BB9A401`, captured 2026-09-04). Scale arithmetic (10×/100×/1000×) is **modelled**,
not measured — labelled `[MODELLED]`. No PASS is issued by this document; it is arithmetic
and code tracing only. Classification per claim: `[CONFIRMED]` read the exact code path ·
`[MODELLED]` arithmetic built on measured/confirmed inputs · `[UNKNOWN]` genuinely open.

---

## 0 · A correction to the mission's premise — `version_pdf` does not render

The mission brief states `version_pdf` "renders a ~1 MB PDF on demand through mPDF." Traced
the full method body: `Doc_templates.php:452-489`. It does **not** call `Doc_renderer`/mPDF at
all — it resolves a path (`$snap['proofPdfPaths'][$lang]`, falling back to the
`{id}_v{n}_{lang}.pdf` naming convention, `:466-468`), canonicalises it (`realpath()` +
containment check, `:474-479`), and calls `readfile($file); exit;` (`:488`). **It streams a
file already rendered at `proof_pdf()`/publish time — a static-file read, not a render.**
`[CONFIRMED]`

This matters for the model: `version_pdf` has no mPDF memory/time cost and no render
concurrency limit — it is bounded by disk I/O and PHP's `readfile()`, which streams in chunks
without loading the whole file into memory. The four 200s + four different hashes
`_live-state.md` L7 observed are real (E3) — they are four different *files* being streamed,
not four renders. **The only endpoint that actually invokes mPDF per-request is `proof_pdf()`**
(`Doc_templates.php:820-886`, confirmed below) — that is where the render-concurrency risk
lives, not on the version-history page.

---

## 1 · The list path — `get_templates`

**Traced end to end, `Doc_templates.php:328-343`:**
```php
$where = [['schoolId', '=', $this->school_id]];
if ($docType !== '') { $where[] = ['docType', '=', $docType]; }
$rows = Doc_rows::map($this->fs->schoolWhere('documentTemplates', $where));
return ['templates' => $rows];
```
No `limit()`, no cursor, no field mask/projection anywhere in this method or in
`Doc_rows::map()`. `[CONFIRMED — no pagination, no projection, server or client]`

**Client always requests the unfiltered set on every hub load.** `hydrateFromServer()` calls
`srv.templates("")` (`designer.js:5597`) — empty `docType` — regardless of which gallery the
user is about to open. This happens once per page load (`designer.js:5668`, top-level call)
and again after "my copy" duplication (`designer.js:1196,1377`). Opening the gallery from the
hub is pure client-side navigation over the already-loaded `S.lib` — **no second
`get_templates` call was found** (`grep` for `hydrateFromServer(` returns exactly the 3 call
sites above). `[CONFIRMED]`

### Payload arithmetic `[MODELLED]`, built on the E3 measurement (85 → 456 KB, median 4.9 KB)

| Scale | Templates | Payload (× measured 456 KB / 85) |
|---|---|---|
| 1× (measured) | 85 | 456 KB |
| 10× | 850 | ≈ 4.56 MB |
| 100× | 8,500 | ≈ 45.6 MB |
| 1000× | 85,000 | ≈ 456 MB |

At **50 concurrent staff**, each triggering one full unfiltered fetch on hub load:

| Scale | Templates | Aggregate payload / burst (× 50) |
|---|---|---|
| 1× | 85 | 22.8 MB |
| 10× | 850 | 228 MB |
| 100× | 8,500 | 2.28 GB |
| 1000× | 85,000 | 22.8 GB |

### What breaks first

Four candidates, reasoned in order:

1. **Browser render** — *not* the first failure. `paintGallery()` (`designer.js:2519-`) renders
   only `libOf(S.docType)` — the current type's rows — not the full library, so DOM cost scales
   with per-type count, not the school total. The hub itself (`paintHub()`,
   `designer.js:2173-`) renders one card per document *type* (a fixed, small set), not per
   template. Browser DOM cost only becomes acute if a single type accumulates thousands of
   rows (see §6) — a later, narrower failure than the payload itself.
2. **Firestore read cost** — scales linearly and compounds with concurrency (§ below) but is a
   *cost* problem, not an availability problem, until a quota/budget cap is hit; no evidence
   found that one exists here. `[UNKNOWN — billing alerts/quotas, out of code scope]`
3. **PHP memory** — `Doc_rows::map()` builds the **entire** result as a nested PHP associative
   array before `json_encode`, inside one `mod_php` Apache worker
   (`PATH_A_US_SERVER_RUNBOOK.md:16,28` — Apache + `mod_php`, not PHP-FPM, confirmed by direct
   read). PHP's array/string overhead for a deeply-nested structure like `objects` typically
   runs several times the eventual JSON byte size. At 45.6 MB of JSON (100×), the live PHP
   array is plausibly 150–450 MB in one worker process. **`memory_limit` on this deployment was
   not read this pass** — `[UNKNOWN]`, flagged identically in `01b-backend-spec.md`'s own gap
   list. This is a real candidate for "breaks first" but cannot be asserted without that value.
4. **Payload size / transfer time — the one this pass can prove from measured numbers alone.**
   `get_templates` is on the **critical path of every single hub load**, gated behind nothing.
   At 10× (850 templates, 4.56 MB) a hub load is already carrying ~10× the bytes of today's
   already-uncached, unpaginated response. At 100× (8,500 templates, 45.6 MB), transferring
   that payload — on top of the ~1.7–2.3 s cross-region Firestore query
   (`Doc_presence.php:71-73` cites this figure; CLAUDE.md repeats it as the reason the PHP
   server sits in Ohio) and the `json_encode`/parse overhead on both ends — pushes every staff
   member's hub load into tens of seconds, for an action (opening the certificate hub) that
   currently completes in low seconds. **This is the first user-visible break, and it lands
   between the 10× and 100× marks — before browser rendering degrades and independent of the
   unmeasured PHP memory ceiling.**

**Named answer: the unpaginated, unprojected `get_templates` response is the first thing to
break, via payload transfer time on every hub load, somewhere in the 850–8,500-template range
(10×–100×) — with PHP memory exhaustion as a closely-coupled second failure mode at the same
scale that this pass could not quantify precisely (`memory_limit` unmeasured).**

### The N+1 shape the mission calls out — `create()`'s numbering scan

`Doc_template_service.php:194`:
```php
$existing = ($this->store['query'])(self::HEAD_COLLECTION, [['schoolId', '=', $schoolId]]) ?: [];
$max = 0;
foreach ($existing as $id => $row) { ... }
```
**Unbounded, no `limit()`.** Every call to `create()` (and `duplicate()`, which calls
`create()` internally — `Doc_templates.php:530-571`, confirmed in `01b` §4) reads **every**
template head the school has ever created, just to compute the next `TPL####` number.
`[CONFIRMED]`

**Modelled at 8,500 templates:** creating a *single new draft* costs one Firestore query that
returns and PHP-iterates 8,500 documents (a `preg_match` per row), *before* the create-only
`exists()`/`set()` pair even runs. This cost is paid on **every** "New template" click,
forever, and grows without bound as the never-cleaned draft pile grows (94% of the 85 measured
templates are never-published drafts with no bulk-delete path — `_live-state.md` O5,
`01c-data-spec.md` §10). The most common authoring action in the module gets slower with every
template ever created, published or not.

---

## 2 · Firestore round trips per user action

Traced each server-side operation to its `store['...']` calls (`Doc_template_service.php`,
`Doc_templates.php`, `Doc_presence.php`). Wall-clock uses the repo's own cited cross-region
figure, ~1.7–2.3 s per Firestore call (`Doc_presence.php:71-73`), **sequentially** where the
client/server code awaits calls one at a time (confirmed per action below) — parallel calls
are noted where found.

| User action | Firestore round trips | Sequential? | Modelled wall-clock |
|---|---|---|---|
| **Opening the hub** (page load) | `get_types`→1 read (`_school_context`, `Doc_templates.php:245-266`) + `get_templates`→1 query (`:328-343`) = **2** | Yes — `hydrateFromServer()` awaits `srv.types()` then `srv.templates()` in sequence (`designer.js:5583,5597`) | **3.4–4.6 s** |
| **Opening the gallery** (from hub) | **0** — client-side nav over already-loaded `S.lib`; no `get_templates` call found in the gallery-open path | n/a | **~0 s** (unless it's the first screen, in which case it inherits the hub's 2 calls above) |
| **Opening a template** | `get_template`→1 read (`Doc_templates.php:344-363`) + `offerRoomChoice()`'s presence probe→1 (heartbeat write+query, `designer.js:1159`) + `startPresence()`'s immediate `beat()`→1 more presence call when no one else is present (`designer.js:1213-1224`, calls `srv.presence` again) = **3** | Yes, awaited in order (`designer.js:2453-2460`, `1159`, `1220`) | **5.1–6.9 s** |
| **Saving** | `head()` read (`Doc_template_service.php:316`, itself 1 `get`) + 1 `update()` (`:346`) = **2** | Yes — read-then-write in one PHP call | **3.4–4.6 s** |
| **Publishing** | `head()` read (`:483`) + `exists()` on `documentTemplateVersions` (`:517`) + `set()` snapshot (`:551`) + `update()` head (`:563`) = **4**, non-atomic across 2 collections | Yes, all sequential in one method body | **6.8–9.2 s** |
| **Activating** | `head()` read (`:602`) + siblings `query()` (`:648-651`) + 1 `commit()` (batched, atomic across N docs, still 1 network round trip) = **3** (+1 more if rolling back to an explicit older version — the `exists()` snapshot check at `:622`) | Yes | **5.1–6.9 s** (6.8–9.2 s on an explicit-version rollback) |
| **Presence heartbeat** | `set()` write + `others()` query = **2**, every call (`Doc_presence.php:76-92`, one call does both by design, per its own comment `:66-73`) | Yes, one method does both | **3.4–4.6 s** per heartbeat (async, does not block the editor UI) |

**N+1 shapes found:** `create()`'s numbering scan (§1) is the clearest one — one query whose
size is unbounded by the school's total template count, on the hot path of a routine action.
No other N+1 pattern (a loop issuing one Firestore call per iteration) was found in the traced
methods — `activate()`'s sibling displacement is N documents but **1** network call (batched
commit), which is the correct shape.

---

## 3 · Presence heartbeats

**Client interval:** `setInterval(beat, 60000)` — **60 s** (`designer.js:1226`), against a
server-side active window of **90 s** (`Doc_presence.php:38`, `ACTIVE_WINDOW_SEC`). `[CONFIRMED]`

**Per-heartbeat cost:** 1 write (`Doc_presence::heartbeat` → `store['set']`,
`Doc_presence.php:76-92`) + 1 query (`others()`, `:98-115`, scoped `schoolId==` + `templateId==`,
equality-only, needs no composite index). **2 Firestore round trips per beat.**

### Writes/reads per minute, modelled `[MODELLED]`, one active-template session per editor

| Concurrent editors | Heartbeats/min | Firestore writes/min | Firestore reads/min |
|---|---|---|---|
| 1 | 1 | 1 | 1 |
| 5 | 5 | 5 | 5 (each read now also scans up to 4 other live rows) |
| 20 | 20 | 20 | 20 (each read scans up to 19 other live rows) |

Read cost per call is not the row count returned but 1 Firestore query op — the read-side cost
scaling that matters is document reads charged per matched+returned row, which stays small
(bounded by concurrent-editor count on that one template) regardless of school size, since the
query is scoped by `templateId`, not just `schoolId`.

### Steady-state cost of a tab left open all day `[MODELLED]`

60 s interval × a 7-hour school day (420 min) = **420 heartbeats**, each 1 write + 1 read =
**840 Firestore ops/tab/day**, run indefinitely as long as `S.screen==="designer"`
(`designer.js:1218`, the beat's own guard). At 50 staff each leaving one tab open all day:
**42,000 Firestore ops/day** from presence alone, before any editing happens.

### Stop condition on hidden/idle tabs — **none found**

Grepped `designer.js` for `document.hidden`, `visibilitychange`, and any idle-timeout guard
around `startPresence`/`stopPresence` — **zero hits**. The only stop conditions are:
`stopPresence()` called when `S.screen !== "designer"` (i.e. navigating away inside the SPA,
`designer.js:1218`) and the best-effort `pagehide` beacon to `/leave`
(`designer.js:1230-1237`, browsers "are free to drop" it per the code's own comment,
`Doc_presence.php:130-134`). **A backgrounded or minimized browser tab left open on the
designer screen keeps heart-beating every 60 s indefinitely** — there is no
`document.hidden`-driven pause. `[CONFIRMED absence]`

### Cleanup — never, only filtered at read time

`templateSessions` rows are never deleted server-side except via the best-effort `leave()`
beacon (`Doc_presence.php:135-142`). `others()` filters stale rows **at read time**
(age > 90 s excluded, `:110-113`) but nothing ever runs a delete sweep. **The collection grows
monotonically with every heartbeat × user × template combination ever recorded, forever.**
`[CONFIRMED, cross-referenced with `01c-data-spec.md` §10]`

---

## 4 · PDF render concurrency

**`proof_pdf()` is the only endpoint that invokes mPDF** (§0 correction). Traced
`Doc_templates.php:820-886`: for each declared language, `$this->docser->render(...)` builds
HTML, then `$this->docpdf->render($html, ...)` (`Doc_renderer.php`) runs mPDF, then the bytes
are written to disk (`file_put_contents`, `:857`) and concatenated for the content hash
(`:862`). **No cache check precedes the render call** — every `proof_pdf` request re-renders
every declared language from scratch, unconditionally. `[CONFIRMED — no caching]`

**Resource caps, `Doc_renderer.php:38-40`:**
```php
const MAX_MEMORY  = '96M';   // 26MB measured × ~3.7 headroom
const MAX_SECONDS = 15;      // 151ms measured × 10 CPU penalty × 10 safety
const MAX_PAGES   = 20;
```
Applied per-render via `ini_set('memory_limit', self::MAX_MEMORY)` /
`set_time_limit(self::MAX_SECONDS)` (`Doc_renderer.php:222-223`), restored after
(`:263-264`). These are **per-process** PHP directives — they cap what one render is allowed
to consume, not what the server as a whole can sustain concurrently.

**No concurrency gate found.** Grepped `Doc_templates.php`, `Doc_renderer.php`,
`Doc_template_service.php` for `flock`, `sem_acquire`, `Semaphore`, `queue`, `rate.limit`,
`throttle` — **zero hits**. There is no lock, queue, or rate limit around `proof_pdf`.
`[CONFIRMED absence]`

**Deployment shape:** Apache + `mod_php` (`PATH_A_US_SERVER_RUNBOOK.md:16,28`), i.e. each
concurrent PHP request holds a full Apache worker process for the request's duration — a
render that takes up to 15 s (the cap) holds that worker, and the memory it consumes (up to
96 MB by the app's own cap, though G0.6 measured 26 MB in the normal case) for that whole
window. **Instance size / RAM, and Apache's `MaxRequestWorkers`/MPM config, were not found in
the runbook this pass** — `[UNKNOWN]`.

### Concurrency model `[MODELLED]`

If *N* staff simultaneously click "Render proof" (a plausible burst — e.g., several class
teachers proofing transfer certificates near a session-end deadline), the server holds *N*
Apache workers each running mPDF, each capable of consuming up to 96 MB and 15 s. With
`[UNKNOWN]` total instance RAM and worker-pool size, the practical concurrency ceiling cannot
be given a number — but the *shape* is clear and citable: **there is no application-level
throttle, so the load is offered entirely to the OS/Apache layer with no graceful degradation
path** (no queue, no 429/"try again" response, no serialization) — a burst either renders or
the worker pool/RAM is exhausted and requests start failing/timing out with no distinguishing
error surfaced to the user beyond the generic 500 (`Doc_templates.php`'s shared `_run()`
taxonomy, `01b-backend-spec.md` §7).

---

## 5 · Disk growth

**Never deleted:** zero `unlink()` calls found in `Doc_templates.php` for either proof PDFs or
uploaded assets (`01c-data-spec.md` §10, independently re-confirmed by this pass's own grep of
`Doc_templates.php`, `Doc_template_service.php`). `[CONFIRMED absence]`

**Where they live:** `uploads/{schoolId}/doctemplates/_proofs/` (proof PDFs, blocked from
direct HTTP by `uploads/.htaccess`, `00-dependency-graph.md` §1) and
`uploads/{schoolId}/doctemplates/assets/` (content-hash-named images, capped at 4 MiB each,
`Doc_templates.php:896` `ASSET_MAX_BYTES`). Both are the PHP server's **local filesystem**, not
GCS — bounded by whatever disk the Lightsail instance has. `[CONFIRMED]`

**Per-proof size, from measured data:** `_live-state.md` L7 observed rendered PDFs from
24,162 bytes (text-only, v1) to 1,003,107 bytes (image-bearing, v6) for the *same* template
across its version history — i.e. **~1 MB is the upper end for an image-bearing certificate,
not a fixed size.** `proof_pdf()` writes one file per declared language and **overwrites the
same filename on every re-proof of the same version** (`Doc_templates.php:850`,
`basename($id) . '_v' . $version . '_' . $safeLang . '.pdf'`) — so disk growth is not "one file
per proof click," it is "one file per (template, version, language) that ever got a proof,"
plus one snapshot's worth of PDFs frozen forever at every `publish()`.

### Growth model `[MODELLED — explicit assumption stated]`

Assumption: a school actively using the module publishes/proofs on the order of the observed
population's *published* share — 5 of 85 templates have ever recorded a proof
(`_live-state.md`, population table) — call it roughly **6% of a school's templates reaching a
recorded proof** at any snapshot in time, each producing ~1 language-set of files at up to
~1 MB apiece (unbounded upward for multi-language certificates — a bilingual TC writes 2 files
per proof event).

| School's total templates (all time, incl. dead drafts) | Templates with a recorded proof (~6%) | Disk, single-language (~1 MB each) | Disk, bilingual (~2 MB each) |
|---|---|---|---|
| 85 (measured) | 5 | ~5 MB | ~10 MB |
| 850 (10×) | 51 | ~51 MB | ~102 MB |
| 8,500 (100×) | 510 | ~510 MB | ~1.02 GB |

This is **per school**, and it is a floor, not a ceiling — every *re*-proof of a version before
publish overwrites its own file (no growth there), but every *new* version proofed adds a new
file, and nothing ever removes a prior version's proof once superseded. Multiply by tenant
count for the platform total: **[UNKNOWN]** total school count on this Firebase project — not
established by this pass or by any prior agent's document read this session.

**Bounded?** No quota, rotation, or archival policy was found anywhere in the traced code. The
only thing standing between this and unbounded growth is the Lightsail instance's disk size,
which is `[UNKNOWN]` (not in the runbook excerpt read this pass).

---

## 6 · The client — `designer.js` (5,668 lines) hot-path analysis

### 6a — Full-DOM rebuild confirmed, on two hot paths

`layoutPage()` (`designer.js:1876-`) is the single function responsible for laying out the
editable page. On every call it:
1. Wipes the entire page DOM: `P.innerHTML=""` (`:1886`).
2. Rebuilds **every** object node from scratch via `buildObject(o)`, one full pass over
   `S.tpl.objects` (`:1904-1911`).
3. Runs a **second** full pass calling `inner.getBoundingClientRect()` per object
   (`:1918`) — a synchronous, forced-reflow read immediately after synchronous writes in
   pass 1, the textbook layout-thrashing pattern.
4. Runs a **third** pass (anchor-chain resolution) that can re-iterate all objects up to 12
   times if anchors chain (`:1928-1936`, `while(moved && guard++<12)`).
5. Runs a **fourth** pass applying final geometry/state classes to every node (`:1939-1961`).

**This is O(objects) per call, not O(objects²)** — no nested per-object loop over all other
objects was found in `layoutPage()` itself (the anchor-resolution loop is bounded to 12
outer iterations, each a flat pass, not nested per-pair). `snap()` (`designer.js:2027-2040`,
called during a drag) **is** effectively O(objects) per mousemove tick since it filters
`S.tpl.objects` on every call — combined with `layoutPage()` also being called from the same
tick, a drag against N objects costs O(N) work per mousemove event, not O(N²), but that O(N)
work repeats at native mousemove frequency with **no throttling found** (no
`requestAnimationFrame`, no debounce — grepped the `mousemove` listener body,
`designer.js:3996-4036`, direct calls to `layoutPage()`/`snap()` inline).

**Called on every keystroke.** The content-editable `input` handler
(`designer.js:3216-3220`) commits the edit to the model then calls `layoutPage(); paintStatus();`
directly — **not** debounced, **not** the lighter-weight `paintContent()` (which is explicitly
guarded against re-running mid-edit, `:3130-3133`, to avoid destroying the focused node).
`[CONFIRMED]` Practically: typing one character in a template with many objects triggers a
full page-DOM teardown/rebuild plus one forced-reflow read per object, on every keystroke.

**Called on every mousemove tick during a drag or resize.** The `mousemove` handler
(`designer.js:3996-4032`) calls `layoutPage()` inline for both `"move"` and `"resize"` drag
modes (`:4014,4025`) — full `render()` is called only once, on `mouseup`
(`designer.js:4048`), which is the *correct* pattern for the top-level panel repaint, but
`layoutPage()`'s own internal cost (§ above) is paid on every intermediate mousemove event
regardless.

**Practical ceiling:** not quantified this pass — no object-count stress data was captured
(the golden fixtures `tests/doctemplates/golden/tc_p95.html` exist per
`00-dependency-graph.md` §1 but were not read/measured here). **`[UNKNOWN]`** — flagged for a
runtime agent to measure `layoutPage()` wall-clock at realistic object counts (a TC with
signature blocks, seals, multi-language runs plausibly holds 20-60 objects; the p95 fixture
name suggests a stress case exists but was not opened this pass).

### 6b — Undo stack: bounded, not a leak

`push()` (`designer.js:1743`): `S.undo.push({label, before, after}); if(S.undo.length>80)
S.undo.shift();` — **capped at 80 entries.** `[CONFIRMED — negative finding, recorded so it is
not re-flagged]` Each entry stores a full before/after object-array snapshot
(`snapshot()`/`JSON.parse(JSON.stringify(...))` pattern used throughout, e.g.
`designer.js:2289`), so memory cost per undo entry scales with the template's own object
count — 80 × (2 × template size) is bounded but not small for a large template; not measured
this pass.

### 6c — Listener accumulation — not found in the traced paths

`layoutPage()`'s full rebuild (`P.innerHTML=""` then re-append) means DOM nodes and any
listeners attached to them are discarded and recreated each call — this is the *opposite* of
listener accumulation (a correctness/perf trade: it avoids stale-listener leaks at the cost of
the rebuild itself, §6a). `paintContent()`'s content-editable rows are similarly rebuilt
wholesale (`wrap.innerHTML=""`, `:3135`) except while a row is actively focused
(`:3130-3133`). No `addEventListener` call was found attached to `window`/`document` inside a
per-object or per-render loop (the `window.addEventListener("mousemove"/"mouseup",...)`
handlers are registered once, top-level, `designer.js:3996,4045`) — **no listener-accumulation
pattern found in the paths traced this pass.** `[CONFIRMED, scoped to the functions read this
pass — the full 5,668-line file was not read end to end]`

### 6d — Gallery list rendering, no virtualization

`paintGallery()` (`designer.js:2519-`): `rows.forEach(row => { ...full row markup...
schematic(buildTpl(row).objects, ...) ... })` (`:2582-`) — one full `<div class="tpl-row">`
subtree **plus one `schematic()` sub-render** (itself one DOM node per object,
`designer.js:2157-2170`) per template row, no windowing/virtualization, no pagination.
`[CONFIRMED]` Bounded today by the 71-row TC gallery (measured population); at 10×/100× growth
concentrated in one popular type (transfer certificates are 71 of 85 = 84% of the measured
population already), a single gallery view could plausibly reach 710–7,100 rows, each
constructing a `schematic()` sub-DOM proportional to that template's object count — this is
the client-side echo of the same "no pagination anywhere" finding as `get_templates` (§1), one
layer up the stack.

---

## 7 · Index readiness

**Verified independently** (not just cited from `01c`): `firebase-rules/firestore.indexes.json`
declares, for this module's collections: `documentTemplates` × 3 composite indexes
(`(schoolId, docType, status)`, `(schoolId, docType, activeVersion)`,
`(schoolId, updatedAt DESC)` — `firestore.indexes.json:3578-3630` region),
`documentTemplateVersions` × 1 (`(templateId ASC, version DESC)`, `:3631-3645`),
`reusableBlocks` × 1 (`(schoolId, blockType)`, `:3646-`). `[CONFIRMED, direct read]`

**Every query this module's code actually issues is equality-only** — re-grepped `Doc_*.php`
and `Doc_templates.php` for `orderBy`/range operators: zero hits, consistent with `01c`'s own
finding. **None of the 7 declared composite indexes is required by any query the code issues
today** — Firestore serves equality-only compound filters without a declared index.
`[CONFIRMED]`

**The obvious next features, checked against what's already declared:**

- **Sort the gallery by updated date.** `(schoolId, updatedAt DESC)` is **already declared**
  (`firestore.indexes.json`, the `documentTemplates` region above) and is not required by any
  current query. **This index is pre-provisioned for exactly this feature** — adding
  `orderBy('updatedAt','desc')` to `get_templates` (or a school-scoped equivalent) would work
  immediately with no new index deploy. `[CONFIRMED — the index exists and matches this shape]`
- **Filter the gallery by status.** `(schoolId, docType, status)` is **already declared** and
  would directly serve `where('schoolId','==',x).where('docType','==',y).where('status','==',z)`.
  **Also pre-provisioned.** One caveat carried over from `01c-data-spec.md` §2: `status` only
  ever holds `'draft'`/`'archived'` in practice (`publish()` never writes `'published'` — see
  `Doc_template_service.php:556`), so a status filter built naively against the literal string
  `'published'` would silently return nothing; the real "is this published" predicate is
  `publishedVersion != null`, which **has no matching declared index** — a status-style filter
  on publication state would need `(schoolId, docType, publishedVersion)` or similar, not
  present in the current 7. **Named gap: if "filter by status" means the axis product actually
  cares about (published vs. not), the existing index does not cover it.**

**Verdict: index provisioning is ahead of the code for both named next-features, with one
caveat** — the declared `status` index would silently serve the wrong axis if a filter feature
is built against the field name rather than the field's actual semantics (O1, `_live-state.md`).

---

## 8 · Counts

| Item | Count | Evidence |
|---|---|---|
| Firestore round trips — opening the hub | 2 | E2 |
| Firestore round trips — opening the gallery (post-hub) | 0 | E2 |
| Firestore round trips — opening a template | 3 | E2 |
| Firestore round trips — save | 2 | E2 |
| Firestore round trips — publish | 4 (non-atomic across 2 collections) | E2 |
| Firestore round trips — activate | 3 (4 on explicit-version rollback) | E2 |
| Firestore round trips — one presence heartbeat | 2 | E2 |
| Presence heartbeat interval (client) | 60,000 ms | E2, `designer.js:1226` |
| Presence active window (server) | 90 s | E2, `Doc_presence.php:38` |
| Heartbeats/tab/7-hour day | 420 → 840 Firestore ops | Modelled |
| `unlink()` calls for proof PDFs / assets | 0 | E2, absence — reconfirms `01c` §10 |
| mPDF render caps | 96 MB memory / 15 s / 20 pages | E2, `Doc_renderer.php:38-40` |
| Concurrency gates (lock/queue/throttle) around rendering | 0 | E2, absence |
| Endpoints that actually invoke mPDF per-request | 1 (`proof_pdf`) — **not** `version_pdf` | E2, §0 correction |
| `get_templates` pagination/projection mechanisms found | 0 | E2, absence |
| `layoutPage()` DOM-rebuild + forced-reflow passes per call | 4 passes, O(objects) each | E2 |
| Hot paths calling `layoutPage()` unthrottled | 2 (every keystroke; every mousemove during drag) | E2 |
| Undo stack cap | 80 entries | E2, `designer.js:1743` — negative finding |
| Declared composite indexes for this module | 7 | E2, direct read |
| Of those, required by a current query | 0 | E2 |
| Of those, matching a plausible next feature (sort by updatedAt) | 1 | E2 |
| Of those, matching a plausible next feature (filter by status literal) | 1 (but wrong axis vs. O1 — see §7) | E2 |

---

## 9 · Named gaps / `[UNKNOWN]`s

- **PHP `memory_limit` on the live Apache/`mod_php` deployment** — not read this pass; directly
  gates whether `get_templates` fails by memory exhaustion before or after the payload-transfer
  threshold named in §1. Same gap flagged independently in `01b-backend-spec.md` §7's own
  named-gaps list.
- **Lightsail instance size (RAM/vCPU) and Apache `MaxRequestWorkers`/MPM config** — not found
  in `PATH_A_US_SERVER_RUNBOOK.md`'s excerpted setup steps; needed to give §4's render-
  concurrency model a numeric ceiling instead of a shape-only answer.
- **Total tenant (school) count on this Firebase project** — needed to turn §5's per-school
  disk-growth model into a platform total; not established by this or any prior pass this
  session.
- **`layoutPage()`/`schematic()` wall-clock at realistic and stress object counts** — no runtime
  measurement taken; the `tc_p95.html` golden fixture (`tests/doctemplates/golden/`) suggests a
  stress case was authored for this exact question but was not opened or measured this pass.
- **Disk size / free space on the Lightsail instance** — the only real ceiling on §5's unbounded
  growth model; not found in the runbook excerpt read this pass.
- **Whether the pre-fix `lastProof` records without a corresponding on-disk PDF would 404 under
  `version_pdf`'s fallback path** — inherited from `01c-data-spec.md` §13's own open item; not
  independently re-verified here, and immaterial to this document's arithmetic since it's a
  correctness question, not a scale one.
