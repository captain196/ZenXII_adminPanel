# 01b · Backend spec — Document Engine (Certificates), server side

**Agent: A4 · BACKEND-SPEC. Evidence ceiling: E2 (static source read only — nothing executed,
nothing observed at runtime).** Every claim below is cited `file:line`. No citation = deleted.
Classified `[CONFIRMED]` (read the exact code path), `[INFERRED]` (reasoned from adjacent code,
not directly observed), `[UNKNOWN]` (not reached this pass), `[CONTESTED]` (code comment claims
X, code itself does Y).

Primary files read in full: `Doc_templates.php` (1115), `Doc_template_service.php` (918),
`Doc_contract.php` (487), `Doc_block_service.php` (290), `Doc_presence.php` (143),
`Doc_resolver.php` (214), `Doc_rows.php` (67), `document_targets.php` (232), plus targeted reads
of `Doc_serializer.php` (guardSrc, ~491-614), `Doc_renderer.php` (guardImages, ~382-427),
`doc_types.php` (header + merge-field table head), `MY_Controller.php` (~960-1115, ~264-300),
`rbac_helper.php` (full), `routes.php` (doc_templates block + csrf_exclude_uris),
`Firestore_service.php` (get/set/update), `Firestore_rest_client.php` (setDocument/updateDocument/
commitBatch signatures).

---

## 1 · Endpoint contract table

All 24 AJAX methods are declared in `Doc_templates::CAPABILITIES` (`Doc_templates.php:44-73`) and
enforced centrally by `_remap()` (`Doc_templates.php:96-116`) — a method not in the map is denied
with a 403 before it runs (`Doc_templates.php:103-108`). `_remap()` also 404s anything not an
existing public, non-underscore method (`:98-101`).

| # | Endpoint | HTTP | Auth (ctor) | RBAC grade | Required params | Validation | Errors / codes | Response shape | Idempotent? |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `index` | GET | session (`require_permission`, view floor) | view | `docType` (URI, optional) | `_safe_type()` fail-closed to `''` | n/a (page render) | HTML view | yes (read) |
| 2 | `gallery` | GET | same | view | same, delegates to `index()` | same | same | HTML | yes |
| 3 | `design` | GET | same | edit | `templateId` (URI) | `safe_path_segment()` — char-class only, no ownership check | 400 via `json_error`/redirect if unsafe chars | HTML | yes |
| 4 | `get_types` | GET | session | view | none | contract load can throw | 500 `json_error` on missing/broken `doc_types.php` (`:234-236`) | `{school, types[]}` | yes |
| 5 | `get_templates` | GET | session | view | `docType` (GET, optional filter) | none beyond school scoping | `_run()` taxonomy (below) | `{templates:[…]}`, docId-keyed → normalised list | yes |
| 6 | `get_template` | GET | session | view | `templateId` (GET) | `safe_path_segment` + explicit tenant check `:353` (`schoolId !== this.school_id` → `RuntimeException`) | 422 "Template not found" (see §7 status-code note) | `{template}` with `_id` re-attached | yes |
| 7 | `get_blocks` | GET | session | view | `blockType` (GET, optional) | none | `_run()` | `{blocks:[…]}` | yes |
| 8 | `get_versions` | GET | session | view | `templateId` (GET) | tenant check `:387` | 422 not-found | `{versions:[…], draftVersion, activeVersion}` | yes |
| 9 | `version_pdf` | GET | session | view | `templateId`, `version`, `lang` (GET) | tenant check; path canonicalisation `realpath()` + `str_starts_with()` containment (`:474-481`) | `show_404()` on any failure (no JSON error — this is a raw file stream) | binary PDF stream | yes |
| 10 | `presence` | **POST required** (`_require_post()`) | session | view | `templateId` (POST) | `safe_path_segment` | 405 if not POST | `{others:[…]}` | **no** — every call writes a fresh heartbeat row (by design) |
| 11 | `leave` | POST | session | view | `templateId` (POST) | same | 405 | `{left: bool}` | yes (delete-if-present) |
| 12 | `duplicate` | POST | session | edit | `templateId`, optional `name`/`objects` (POST) | tenant check on source; `objects` JSON-decoded with no shape/size validation beyond `is_array` fallback | `_run()` taxonomy | `{templateId, template}` | **no** — mints a new template every call |
| 13 | `create` | POST | session | edit | `docType`, optional `seed` (JSON) | `_safe_type()` fail-closed; `seed` must be array-or-null; 8 lifecycle keys stripped from seed (`:630-633`) — **`docType` reassignment is not possible here** (checked separately, see §6) | 422 `InvalidArgumentException` on bad type/seed; 500 fallback | `{templateId, template}` | **no** — mints new doc each call |
| 14 | `save` | POST | session | edit | `templateId`, `lockVersion`, `patch` (JSON) | `lockVersion` required non-empty; `patch` must be JSON object | 422 on missing lock/bad patch; **409** `E_CONFLICT` on stale lock (service, `:326-332`) | `{lockVersion}` | **no**, and see §5 — a stale write can silently win under real concurrency despite the lock |
| 15 | `validate` | POST | session | edit | `template` (JSON) | `_safe_type()` on `docType` inside body | `_run()` | `{blocking:[…], warnings:[…], ok}` | yes (pure function of input) |
| 16 | `preview` | POST | session | edit | `template` (JSON), optional `lang`/`sample`/`isDuplicate` | `sample` restricted to `typical|p95` | 422 on bad sample/template | `{html, lang, sample}` | yes |
| 17 | `proof_pdf` | POST | session | edit | `templateId` (POST) | tenant check on stored template (not client body) | `_run()`; can 500 on disk write failure | `{proof, paths, perLanguage}` | **no** — re-renders and overwrites the same `_v{n}_{lang}.pdf` file path every call (same version → same filename, so repeat calls are effectively idempotent on disk, but always re-record `lastProof` with a fresh timestamp) |
| 18 | `upload_asset` | POST (`multipart/form-data`) | session | edit | `file` (`$_FILES`) | `is_uploaded_file()`, size cap 4 MiB (`ASSET_MAX_BYTES`, `:896`), MIME allow-list by content-sniff (`finfo`) not extension/Content-Type, `getimagesize()` second opinion | 422 on any check failure | `{src, width, height, mime, bytes}` | yes — content-hash filename means a re-upload of the same bytes is a no-op write (`:948` `is_file($dest)` short-circuits `move_uploaded_file`) |
| 19 | `save_block` | POST | session | edit | `blockId` (POST), `block` (JSON) | `safe_path_segment`; `schoolId` forced from session, never trusted from body (`:977`) | `_run()`; service throws on cross-tenant id collision (`Doc_block_service.php:100-105`) | `{version}` | **no** — every save bumps `version` (`Doc_block_service.php:114`), even a byte-identical resubmit |
| 20 | `publish` | POST | session | **manage** | `templateId` (POST) | proof-on-record checks (§6) | 422/409 per `_run()`; illegal-transition also 422 (not 409 — see §7) | `{versionId, version, lockVersion}` | **no**, and non-atomic — see §4 |
| 21 | `activate` | POST | session | **manage** | `templateId`, optional `version` (POST) | version range + snapshot-exists checks (`:613-628`) | 422/409; 422 "no atomic write available" if `commit` unset | `{activeVersion, displaced, rollback, lockVersion}` | yes on repeat with the same explicit version (re-commits the same assignment; lockVersion still increments each call, so not a true no-op) |
| 22 | `deactivate` | POST | session | **manage** | `templateId` (POST) | `activeVersion !== null` precondition | 422 if already inactive | patch object | fails-safe on repeat (throws, no corruption) |
| 23 | `delete` | POST | session | **manage** | `templateId` (POST) | refuses if `activeVersion` or `publishedVersion` set (`:790-806`) | 422 on either guard; 422 if store has no delete capability | `{deleted, name}` | yes (second call 422s "no template" once gone) |
| 24 | `archive` | POST | session | **manage** | `templateId` (POST) | `assertTransition()` — refuses from `archived` (terminal) and refuses while active (`:843-850`) | 422 | patch object | fails-safe on repeat |

**GET accepting a mutation: none found.** Every state-changing endpoint calls `_require_post()`
(`Doc_templates.php:146-153`) before touching the service layer, confirmed at each of lines 500,
514, 532, 602, 647, 675, 773, 822, 912, 965, 1001, 1024, 1047, 1060, 1069. `[CONFIRMED]`

**Route-table gap (documentation, not access-control):** `routes.php:1396-1415` explicitly maps
17 of the 24 AJAX methods. `get_versions`, `version_pdf`, `presence`, `leave`, `duplicate`,
`deactivate`, `delete` have no explicit `$route[...]` entry. CodeIgniter 3 falls back to default
`controller/method` segment routing for anything unmatched, and no catch-all/deny route for
`doc_templates/*` exists earlier in the file (`routes.php`, full-file grep), so these almost
certainly still dispatch normally through `_remap()` — but this is static reasoning, not a
runtime check, and stated as such. `[INFERRED — E2, not verified live]`

---

## 2 · RBAC grade table (full — `Doc_templates::CAPABILITIES`, `Doc_templates.php:44-73`)

| Endpoint | Grade | Endpoint | Grade |
|---|---|---|---|
| `index` | view | `create` | **edit** |
| `gallery` | view | `save` | **edit** |
| `design` | edit | `validate` | edit |
| `get_types` | view | `preview` | edit |
| `get_templates` | view | `proof_pdf` | edit |
| `get_template` | view | `upload_asset` | **edit** |
| `get_blocks` | view | `save_block` | **edit** |
| `get_versions` | view | `publish` | **manage** |
| `version_pdf` | view | `activate` | **manage** |
| `presence` | view | `archive` | **manage** |
| `leave` | view | `deactivate` | **manage** |
| `duplicate` | **edit** | `delete` | **manage** |

**Mission's specific list — which of {create, save, publish, activate, deactivate, archive,
delete, duplicate, upload_asset, save_block} require `manage`:**

- **Require `manage`:** `publish`, `activate`, `archive`, `deactivate`, `delete` (5 of 10).
- **Require only `edit`:** `create`, `save`, `duplicate`, `upload_asset`, `save_block` (5 of 10).
- **No endpoint in this set has NO check at all** — every one of the 24 methods has a
  `CAPABILITIES` entry; `_remap()` denies (403, logged as `error`) anything missing one
  (`Doc_templates.php:103-108`). `[CONFIRMED]`

This is a deliberate two-tier design per the controller's own section comment
(`Doc_templates.php:983-986`: "state transitions — legally consequential" get `manage`; ordinary
drafting work gets `edit`). Whether `edit` is the *correct* ceiling for `create` (minting a new
statutory-document draft), `upload_asset` (writing arbitrary-content files, MIME-checked, into the
school's own storage tree) and `save_block` (creating/overwriting a shared letterhead/seal block
referenced by every future template) is a product judgement this pass surfaces rather than settles
— flagged for QA-LEAD. `[CONFIRMED design / INFERRED as a possible gap]`

---

## 3 · CSRF posture — **PROTECTED, token traced end to end**

- Global CSRF is ON: `$config['csrf_protection'] = TRUE`, `csrf_token_name = 'csrf_token'`,
  `csrf_regenerate = FALSE` (`application/config/config.php:173-177`).
- `doc_templates/*` is **absent** from `$config['csrf_exclude_uris']`
  (`application/config/config.php:184-214`, full array read) — confirmed by direct read, not by
  trusting the controller's own doc-comment claiming this. `[CONFIRMED]`
- Server seeds the token into the page: `application/views/doc_templates/index.php:29-30`
  (`'csrfName' => $this->security->get_csrf_token_name()`, `'csrfHash' => $this->security->get_csrf_hash()`).
- Client attaches it on every POST: `assets/js/doctemplates/designer.js:911` (reads
  `BOOT.csrfName`/`BOOT.csrfHash`), `:951` (`body.append(SRV.csrf.name, SRV.csrf.hash)`),
  `:1246` (same, on the multipart upload path).
- Token rotation is read back from every response: `MY_Controller::json_success()` and
  `json_error()` both append a fresh `csrf_token` (`MY_Controller.php:1095`, `:1107`), and
  `designer.js:967` re-stores it (`if (body && body.csrf_token) SRV.csrf.hash = body.csrf_token`) —
  this is what makes `csrf_regenerate = FALSE` safe for a long-lived SPA session: the token
  doesn't rotate server-side per request, but the client keeps a live copy anyway.

**Verdict: `doc_templates/*` is CSRF-protected (excluded routes list does not contain it), and the
token round-trip is real — not merely asserted in a comment.** `[CONFIRMED]`

---

## 4 · Lifecycle traces

### `create()` (`Doc_template_service.php:177-255`)

- Preconditions: `schoolId`/`docType` non-empty; a `custom:` type must arrive with a non-empty
  `docTitle` (`:187-192`).
- Tenant: `schoolId` is a constructor parameter set from the **session** by the controller
  (`Doc_templates.php:283-289`, `'schoolId' => (string) $this->school_id`), never from the
  request body — confirmed the `create()` AJAX method never reads a client `schoolId`
  (`Doc_templates.php:600-643`, full method body).
- What is written: one new `documentTemplates` doc, id `{schoolId}_TPL####`
  (`Doc_template_service.php:210-243`), full field set including
  `status:'draft', version:1, lockVersion:0, publishedVersion:null, activeVersion:null`.
- **Atomicity / concurrency — CONTESTED.** The doc-comment (`:162-176`) claims: *"NUMBERING IS
  CREATE-ONLY WITH RETRY, not read-then-write... each attempt refuses to write over an existing
  id and tries the next number."* The actual mechanism is:
  1. `($this->store['query'])(...)` — one read of every existing template head for the school,
     **unbounded** (no `limit`, no pagination — `:194`), to compute `$max`.
  2. Loop `$n = $max+1 .. $max+50`: for each candidate id, call `($this->store['exists'])(...)`
     (`:206`), and if false, `($this->store['set'])(...)` (`:245`).

  `exists()` and `set()` are **two separate, unguarded Firestore REST calls** — `Firestore_service::exists()`
  and `::set()` (`Firestore_service.php:317-331`, `:613` region) — with **no transaction and no
  create-only precondition** between them. `Firestore_rest_client::setDocument()`
  (`Firestore_rest_client.php:1166`) takes no `precondition`/`currentDocument` argument at all —
  that parameter only exists on `commitBatch()` (`:996`, `:1013-1047`), which `create()` does not
  use. So this is a plain **TOCTOU race**, not a CAS: two concurrent `create()` calls that both
  read the same `$max` and both reach `exists()` before either calls `set()` will both see
  `TPL####` as free, and the second `set()` **silently overwrites** the first template with no
  error, no version bump, no conflict signal — the exact failure mode the comment says this design
  prevents. The repo's own documented cross-region Firestore latency (~1.7-2.3s per call, cited in
  `Doc_presence.php:71-73` and elsewhere in this codebase) makes this window wide enough to be a
  real, not merely theoretical, hazard for two near-simultaneous "New template" clicks.
  `[CONTESTED — comment claims atomicity, code does not provide it. file:Doc_template_service.php:162-176,194,206,245; Firestore_rest_client.php:1166,996]`
- Partial failure: none of the writes here are multi-document, so there's no cross-collection
  partial-failure surface at `create()` itself (unlike `publish()`, §below).

### `save()` (`Doc_template_service.php:314-349`) — see §5, optimistic locking, for the CAS defect.

- Preconditions: status must be `draft` (`:318-323`); lock must match (`:325-332`).
- Strips 7 lifecycle/identity keys from the patch (`:336-339`) — **`docType` is NOT in this
  strip list.** See §6 for the consequence.
- Write: single `update()` call (merge-style PATCH), unconditional — no Firestore precondition.

### `publish()` (`Doc_template_service.php:481-568`) — **two-step, not atomic**

1. Validate proof-on-record matches current design (§ proof gate, below) — read-only checks.
2. `($this->store['set'])(VERSION_COLLECTION, $vid, $snapshot)` — **write #1**, create-only by
   virtue of the preceding `exists()` guard at `:517-522` (same TOCTOU caveat as `create()`
   applies here too, though a second `publish()` racing against itself on the same `$vid` is a
   narrower window since it requires the same head to be published twice concurrently, which
   `assertTransition` already partially guards against by requiring `status==='draft'`... but
   that read-then-write status check has the identical race shape as `save()`'s lock check —
   see §5).
3. `($this->store['update'])(HEAD_COLLECTION, $docId, $headPatch)` — **write #2**, separate
   Firestore call, unconditional.

   **Partial-failure consequence, traced:** if write #1 succeeds and write #2 fails (network
   blip, PHP timeout, process kill — anything between `:551` and `:563`), the result is a
   **permanently stuck template**: the version snapshot `{docId}_v{N}` now exists in
   `documentTemplateVersions`, but the head's `publishedVersion`/`version`/`status` never
   advanced. A retried `publish()` call re-enters this same method, reaches the `exists()` check
   at `:517`, finds the snapshot **already there**, and throws `"version '$vid' already exists...
   Snapshots are create-only"` (`:518-521`) — **before ever reaching write #2 again.** There is no
   code path that resumes or repairs this: the template can never publish v{N} (the id is taken)
   and can never publish v{N+1} either, because `version` on the head was never incremented, so
   the *next* publish attempt computes the *same* `$vid`. The only way out is manual Firestore
   intervention. This is a real, cited answer to the mission's "what happens on partial failure"
   question, and it is the single most severe lifecycle finding in this pass.
   `[CONFIRMED by code trace; failure MODE inferred from the two-call structure — the failure
   itself was never observed at runtime, evidence ceiling E2]`

- Tenant/ownership: enforced once, centrally, by `head()` (`:888-900`) which every lifecycle
  method funnels through — the doc-comment there (`:868-887`) explicitly narrates that `save`,
  `publish`, `activate` and `archive` **used to have no tenant check at all** before this
  refactor, because the panel's Admin SDK bypasses `firestore.rules` entirely. `[CONFIRMED,
  and independently significant: this whole class exists to compensate for firestore.rules
  giving no protection on this write path]`

### `activate()` (`Doc_template_service.php:600-725`) — the one genuinely atomic transition

- Uses `commitBatch()` (`Firestore_rest_client.php:996`), which supports per-op `precondition`
  (`currentDocument`) and is documented as all-or-nothing across documents
  (`Doc_template_service.php:104-124` constructor comment, and `:636-649` method comment).
- Builds a **complete** assignment: the target doc gets `activeVersion` set with
  `precondition: ['exists' => true]` (`:655-667`), and every *other* template of the same
  `(schoolId, docType)` that currently holds a non-null `activeVersion` is nulled in the SAME
  batch (`:669-688`). This is the one place in the module where the "exactly one active" invariant
  is actually enforced atomically rather than by convention.
- Refuses outright — rather than degrading to non-atomic writes — if no `commit` callable is
  available (`:690-702`). `[CONFIRMED fail-closed]`
- Rollback (activating an older published version) is explicitly allowed by a 2026-09-03 operator
  decision documented in the code (`:589-598`) and logged distinctly as "Rolled back to v{N}"
  vs. "Activated v{N}" (`:712-717`).

### `deactivate()` / `delete()` / `archive()` — straightforward, single `update()`/`delete()` call
each, guarded by the preconditions in §1's table. No atomicity concern because each touches only
one document. `[CONFIRMED]`

### `duplicate()` (`Doc_templates.php:530-571`)

Reads the source template (tenant-checked), then calls `create()` with a seed built from the
source **plus an optional client-supplied `objects` override** (`:546, 553`) — deliberately, per
the in-code comment, so a duplicate-to-escape-a-conflict carries what's on the caller's screen.
This means `duplicate()` inherits every property (and defect) of `create()`, including the
unbounded-query/TOCTOU numbering race. `[CONFIRMED]`

---

## 5 · Optimistic locking — **the CAS is not atomic; a stale write CAN win**

`Doc_template_service::save()` (`:314-349`):

```php
$stored = (int) ($head['lockVersion'] ?? 0);
if ($stored !== $expectedLockVersion) {
    throw new RuntimeException("E_CONFLICT: ...");
}
...
$patch['lockVersion'] = $stored + 1;
($this->store['update'])(self::HEAD_COLLECTION, $docId, $patch);
```

`$head` was fetched by a **separate prior call** to `head()` (`:316`), itself a plain
`($this->store['get'])(...)` (`:890`) with no Firestore read-lock, no transaction, and no
`updateTime` captured for later use as a precondition. The comparison at `:326` is pure PHP
logic against a value that is already stale by the time it's checked. The subsequent
`($this->store['update'])(...)` call is `Firestore_service::update()` →
`Firestore_rest_client::updateDocument()` (`Firestore_rest_client.php:1236` onward, full signature
read) — **this method accepts no precondition parameter of any kind.** It is an unconditional
PATCH.

**Consequence:** two concurrent `save()` calls that both read the head *before either writes*
will both observe the same `$stored` lockVersion, both pass the `!==` check, and both issue an
unconditional `update()`. The second `update()` on the wire wins outright — not merely "wins the
tiebreak", but **fully overwrites** the first save's patched fields with its own (Firestore field
merge doesn't help here: each PATCH carries only the fields *that request's* client changed, so
the loser's changes are not present in the winner's write and are lost). The doc-comment's stated
guarantee — *"Two clerks editing one template must not silently overwrite each other. The loser
gets a conflict; nobody gets a lost edit"* (`Doc_template_service.php:27-28`) — **does not hold
under true concurrency**; it holds only for the common case where one request's `update()` fully
completes (including any lockVersion propagated back to the *next* read) before the other
request's `head()` read begins. That is the overwhelmingly common case for a human clicking
autosave, which is presumably why this has not surfaced as a field bug — but it is not what the
code claims, and it is not a server-side atomic guarantee.

**Direct answer to the mission's question:** *the check is read-then-write, not atomic, and a
stale write CAN win* — under a narrow but real timing window, identical in shape to `create()`'s
numbering race. Both share the same root cause: the CI3-side `Firestore_service` wrapper exposes
`set`/`update`/`exists` as independent calls, and only `commitBatch()` (used exclusively by
`activate()`) carries Firestore precondition support. `[CONTESTED — doc-comment vs. code;
CONFIRMED by tracing both the service method and the underlying REST client method signature]`

Contrast with `activate()` (§4), which gets this right by using `commitBatch()` with
`precondition: ['exists' => true]` — proving the codebase *has* the primitive needed for a true
CAS, but `save()` and `create()` do not use it.

---

## 6 · The proof gate — solid, with one adjacent gap (`docType` mutability via `save()`)

### Trace: `contentHash()` → `recordProof()` → `publish()` precondition

- `contentHash()` (`Doc_template_service.php:372-382`) hashes a **canonical serialization**
  (`:394-414`, order-independent, fixed 6dp float precision) of exactly the design-affecting
  fields: `page, header, footer, objects, languages, defaultLanguage`. Status, lockVersion and
  timestamps are deliberately excluded (`:368-371`).
- `recordProof()` (`:438-479`) is called **only** from `Doc_templates::proof_pdf()`
  (`Doc_templates.php:820-886`), which:
  - reads the template from the **store** (`:826`), never from the POST body — so the HTML/PDF
    that gets hashed is always the server's own render of the server's own stored design;
  - computes the hash over the **actual rendered PDF bytes**
    (`hash('sha256', $bytesAll)`, `Doc_templates.php:873`), across all declared languages
    concatenated in declared order;
  - passes the already-loaded `$tpl` into `recordProof()` (`:879`) explicitly to avoid a second
    read, and `recordProof()` re-derives `contentHash($head)` itself server-side (`:459`) rather
    than trusting a client- or caller-supplied content hash.
  - `recordProof()` also refuses to record a proof missing `hash`/`fontManifest`/`mpdfVersion`
    (`:448-455`) — can't be satisfied by an empty/forged payload.
- `publish()` (`:481-568`) reads `$head['lastProof']` (never anything from the request) and checks,
  **in order**: (a) a proof exists at all (`:487-495`); (b) `proof.version === head.version`
  (`:497-504`); (c) `proof.contentHash === contentHash($head)` **recomputed fresh, not read from
  the stored proof's own claim** (`:508-513`).

**Can a template publish with a proof describing a different design?** No — `publish()` recomputes
`contentHash($head)` itself at publish time and compares against the value that was stamped into
the proof record at render time; it never trusts a hash carried in the proof document as
self-certifying. **Can the hash be supplied or influenced by the client?** No path was found —
`proof_pdf()` takes no template body from the client (`Doc_templates.php:820-823`, only a
`templateId`), and the hash is computed from bytes mPDF itself produced
(`Doc_templates.php:864,873`). `[CONFIRMED — this gate is sound]`

### Adjacent gap found while tracing this: `docType` can be changed post-creation, unchecked

`Doc_template_service::save()`'s strip-list (`:336-339`) removes `status, publishedVersion,
activeVersion, templateId, schoolId, updatedBy, createdBy` from an incoming patch — **`docType` is
absent from this list**, and `Doc_templates::save()` (`Doc_templates.php:645-663`) applies no
filtering of its own before calling the service. `_safe_type()` — the fail-closed gate that
decides whether a school may create a given document type at all (state-gated types, disabled
types) — is invoked **only** from `create()` (`Doc_templates.php:606`) and from `index()`/`design()`
for the page-load `doc_type` URL segment (`:164`). It is never consulted by `save()`.

**Consequence, traced end to end:** an `edit`-grade caller can (1) `create()` a `custom:x` template
— always permitted, no state gate applies to custom types (`Doc_contract.php:204-207` `isCustom()`
short-circuit) — then (2) call `save()` with `patch = {"docType": "kerala_form_5a_r22a"}` (or any
other state-gated statutory type this school's `state` does not satisfy) and the service will
apply it unconditionally. This bypasses the one enforcement point (`_safe_type()`/`typeAvailable()`)
for "which document types may this school create" entirely, via a second write path that was
never checked against it. The immediate blast radius looks contained by `validate()`'s
`offContract` check at publish-gate time (a template with objects bound to the *old* contract's
keys would likely trip `offContract` blocking errors under the *new* `docType`'s contract) — but
that is `validate()` catching a **symptom**, not `save()` enforcing the **rule**, and it was not
verified this pass whether every combination of old/new contract key sets would actually be
caught (e.g., two contracts that happen to share a subset of keys would publish cleanly with a
type the school was never entitled to create). `[CONFIRMED gap exists; CONSEQUENCE partially
INFERRED — full exploit chain to a successfully published mismatched-type document not traced end
to end this pass]`

---

## 7 · Input validation & error taxonomy

Central dispatcher `Doc_templates::_run()` (`:310-326`):

| Thrown | HTTP code | Notes |
|---|---|---|
| `InvalidArgumentException` | 422 | malformed input, e.g. bad `docType`, non-array `patch`/`seed`/`block`/`template` |
| `RuntimeException` starting `E_CONFLICT` | **409** | the *only* path to a 409 in this controller — exclusively `save()`'s lock check |
| `RuntimeException` (anything else) | 422 | includes **"Template not found"** (`get_template`, `get_versions`), illegal state transitions, tenant-mismatch refusals — all flattened to 422, which is semantically closer to 404/403 in several of these cases. Logged server-side via `log_message('error', ...)` before responding (`:319`), so the distinction is preserved in logs even though the HTTP code collapses it. |
| Any other `Throwable` | 500 | message is **never** leaked to the client (`:322-324`, generic "The action could not be completed."); the real message is logged as `UNEXPECTED`. |

`safe_path_segment()` (`MY_Controller.php:980-997`) is called **outside** `_run()`'s try/catch on
several endpoints (`proof_pdf`, `save_block`, `publish`, `activate`, `deactivate`, `delete`,
`archive` — each calls it as the first line of the method body, before `_run(function(){...})`
wraps the rest). This is safe because `safe_path_segment()` itself calls `json_error()` and
`exit`s on failure (`:985,993`) — it never falls through to the wrapped closure with a bad value —
but it means a caller auditing "does every failure go through `_run()`'s taxonomy" would find these
7 endpoints have a **second, separate** error path (always 400, "Missing required field" /
"Invalid characters in field") that never reaches the `_run()` table above. `[CONFIRMED, minor
API-contract inconsistency rather than a security gap]`

**Size cap on `objects`:** **none found.** No length/count check on the `objects` array (or
`header.objects`/`footer.objects`) anywhere in `Doc_templates::save()`, `::create()`, `::duplicate()`,
`::validate()`, or the service layer — grepped for `count(`, `max`, `size` bounds against these
keys, zero hits. The only implicit backstops are external: PHP's `post_max_size`/`memory_limit`
(not verified — E2, server config not read this pass) and, further downstream, Firestore's
per-document size ceiling (~1 MiB, not application-enforced). A caller with `edit` grade can POST
an arbitrarily large `objects`/`patch`/`seed` JSON body bounded only by those external limits, with
no application-level rejection or truncation warning surfaced to the user before the write is
attempted. **Absence is the finding.** `[CONFIRMED absence]`

**Oversized/malformed uploads:** the one place with real limits — `upload_asset()` — is well
covered: `ASSET_MAX_BYTES` (4 MiB, `:896`), content-sniffed MIME allow-list (`finfo`, not
extension or client `Content-Type`, `:930-935`), a second `getimagesize()` structural check
(`:936-939`), and a content-hash-derived filename that cannot be attacker-chosen
(`:946-947`, closing the path-traversal/overwrite class of bug outright). `[CONFIRMED]`

**Phantom-success posture:** every write path returns through `json_success`/`json_error`, both of
which set `status: 'success'|'error'` explicitly (`MY_Controller.php:1092-1111`) — the repo-wide
"phantom success" trap (a `fetch()` that doesn't check `r.ok`) is a **client-side** risk this
backend does not itself introduce; whether `designer.js` correctly checks `status` on every call
was not verified this pass (out of this agent's scope — server-side only). `[CONFIRMED shape;
client behaviour UNKNOWN to this agent]`

---

## 8 · `_safe_type()` — current implementation, fail-closed behaviour, call sites

`Doc_templates.php:1095-1114`:

```php
private function _safe_type(string $t): string
{
    if ($t === '') return '';
    try {
        $contract = $this->_contract();
        if ($contract::isCustom($t)) { return $t; }
        return $contract->typeAvailable($t, $this->_school_context()['state']) ? $t : '';
    } catch (Throwable $e) {
        log_message('error', 'Doc_templates::_safe_type — ' . $e->getMessage());
        return '';
    }
}
```

- No longer the hardcoded 3-item list the removal comment (`:1082-1093`) says it used to be — it
  now defers entirely to `Doc_contract::isCustom()` (regex match, `Doc_contract.php:140-145`) and
  `Doc_contract::typeAvailable()` (catalogue lookup honouring `disabled`/`requiresState`,
  `Doc_contract.php:184-208`). `[CONFIRMED — the widening claim is accurate]`
- **Fail-closed on every branch:** empty input → `''`; any exception (missing/broken config,
  library load failure) → logged and `''` (`:1110-1113`); an unrecognised or disabled type →
  `''` via `typeAvailable()`'s `isset(...)` check. There is no branch that returns a
  caller-supplied string unchecked except the regex-validated custom-type path, and
  `Doc_contract::CUSTOM_PATTERN` (`Doc_contract.php:140`) constrains that to
  `^custom:[a-z0-9](?:[a-z0-9_]{0,38}[a-z0-9])?$` — no room for injection through this value later
  used as a Firestore field/query filter. `[CONFIRMED]`
- **Call sites (3 total, exhaustive grep of `Doc_templates.php`):** `index()` (`:164`, page-load
  `docType` URI segment), `create()` (`:606`, the POST `docType` field), and internally by itself
  via `_school_context()` (not a separate call site, just its own dependency). **Not called by
  `save()`** — see §6's gap above, which is the direct consequence of this narrow call-site list.

---

## 9 · The unwired seam — verified structurally inert

- `document_targets.php` (232 lines, full read): 8 rows, every row's `'wired' => false`
  literal, hand-checked on all 8 (`transfer_certificate`, `bonafide`, `character`, `fee_receipt`,
  `fee_demand_note`, `staff_experience_letter`, `staff_salary_slip`, `parent_document_locker`).
  `[CONFIRMED]`
- `Doc_resolver.php` (214 lines, full read): every method is **read-only** —
  `targets()`/`targetsForModule()` read the static config array; `activeTemplate()`/`activeVersion()`
  issue only `query`/`get` store calls (`:117-152`); `readiness()` composes those with no write.
  The class's own store adapter in the constructor (`:48-64`) wires only `get`/`query` — **there is
  no `set`/`update`/`delete`/`commit` key at all** in `Doc_resolver`'s store array, meaning even a
  caller who wanted to abuse this class for a write has no callable to reach — it is structurally,
  not just behaviourally, incapable of issuing anything. `issuanceAvailable()` (`:95-103`) iterates
  the same static registry and can only ever return `false` while every row's `wired` stays
  `false`. `[CONFIRMED]`
- **Nothing calls `Doc_resolver` at all.** Repo-wide grep for `Doc_resolver`/`doc_resolver` outside
  its own file and `Doc_rows.php`'s `require_once` returns zero hits in any controller. It is
  present in the codebase, fully built, and entirely unreferenced from any HTTP-reachable code
  path. `[CONFIRMED]`
- No other code path issues a document: grepped `application/controllers` and
  `application/libraries` for `documentTemplates` — the only hits are `Doc_templates.php`,
  `Doc_resolver.php`, `Doc_compliance.php`, `Doc_block_service.php`, `Doc_template_service.php`
  (all `Doc_*` module-owned files, none of them modules that would print/issue anything).
  `[CONFIRMED]`

**Verdict: the seam is exactly as advertised — a map, not a mechanism. No code path in
`application/` can issue a document from this module in the current build.**

---

## Counts

| Item | Count |
|---|---|
| Public controller methods (page loads + AJAX) | 27 (3 + 24) |
| AJAX endpoints declared in `CAPABILITIES` (fail-closed floor) | 24 of 24 |
| Endpoints requiring `manage` | 5 (`publish`, `activate`, `archive`, `deactivate`, `delete`) |
| Endpoints requiring only `edit` (mutating) | 5 (`create`, `save`, `duplicate`, `upload_asset`, `save_block`) |
| Endpoints with `_require_post()` guard | 15 of 15 mutating endpoints (100%) |
| Endpoints accepting GET for a mutation | 0 |
| Endpoints with explicit `routes.php` entry | 17 of 24 |
| Endpoints reachable only via CI3 default routing (no explicit route) | 7 |
| `doc_templates/*` entries in `csrf_exclude_uris` | 0 |
| Distinct HTTP error codes surfaced by `_run()` | 3 (422, 409, 500) — plus 400 (via `safe_path_segment`, outside `_run()`), 405 (`_require_post`), 403 (`_remap`/`_deny`) |
| Store operations with a real atomicity primitive (`commitBatch` + precondition) | 1 method uses it (`activate()`) |
| Store operations that claim CAS/create-only but are read-then-write | 2 (`save()`'s lockVersion check; `create()`'s id-numbering) |
| `_safe_type()` call sites | 3 (`index`, `create`, self via `_school_context`) — **not** `save()` |
| `document_targets.php` rows, all `wired:false` | 8 of 8 |
| Callers of `Doc_resolver` anywhere in `application/` | 0 |

---

## Named gaps / `[UNKNOWN]`s and why

- **Whether the CI3 default-routing fallback for the 7 unrouted endpoints actually dispatches at
  runtime** — reasoned from framework behaviour and the absence of a blocking route, not observed.
  A runtime-capable agent should hit `doc_templates/get_versions?templateId=X` directly. `[UNKNOWN]`
- **Whether every old-contract/new-contract key-set combination is actually caught by `validate()`'s
  `offContract` check** after a `docType` mutation via `save()` (§6) — the gap in enforcement is
  confirmed; whether it is fully mitigated downstream by `validate()`/the publish gate in every
  case was not exhaustively traced. `[UNKNOWN — partial]`
- **Client-side (`designer.js`) `fetch`/`r.ok` handling** — out of this agent's server-only scope;
  flagged for whichever agent covers the client. `[UNKNOWN — out of scope]`
- **`reusableBlocks` document key shape** and whether `save_block`'s caller-supplied `blockId`
  namespacing could ever collide across schools for a *newly created* (not pre-existing) block —
  the same-school-only check only fires when a doc already exists at that id (`Doc_block_service.php:100-105`);
  a first-write race between two different schools picking the identical `blockId` string
  simultaneously was not traced for a TOCTOU shape (structurally the same class of bug as §4/§5,
  but not independently confirmed for this specific method). `[UNKNOWN — plausible but unverified]`
- **PHP `post_max_size`/`memory_limit` values** on the actual deployment — not read this pass, so
  the practical severity of the missing `objects` size cap (§7) is bounded by external config this
  agent did not inspect. `[UNKNOWN]`
