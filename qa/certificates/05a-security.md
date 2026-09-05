# 05a · Security review (adversarial) — Document Engine (Certificates)

**Agent: A8 · SECURITY-RED. Evidence ceiling E2** (static source read; two items below
extend E3 findings already on record — L9's `docType` reproduction and L7's rendered-PDF
diff — by tracing NEW code paths those passes did not examine). Threat model: an
**authenticated, legitimately-credentialed staff member** of a real school, holding `view`
or `edit` grade on `Certificates`, asking what they can reach beyond their grant — inside
their own tenant and across tenants. Not an unauthenticated-attacker review.

Every claim is `file:line`. `[CONFIRMED]` = read the exact code path. `[INFERRED]` =
reasoned from adjacent code, not executed. `[UNKNOWN]` = the test that would resolve it is
named, not run.

---

## 1 · Tenant-check table — every endpoint

The panel uses the Firebase Admin SDK; `firestore.rules` never sees these writes
(`Doc_template_service.php:878-887`, its own comment). **PHP is the only enforcement.**
Two shapes exist: (a) a single centralised check in `Doc_template_service::head()`
(`:888-900`) that every lifecycle method funnels through, and (b) an inline check
duplicated at each controller read that doesn't go through `head()`.

| # | Endpoint | Grade | Check present | Where | Reached before data returned/written? |
|---|---|---|---|---|---|
| 1 | `get_template` | view | yes | `Doc_templates.php:353` — `$t['schoolId'] !== $this->school_id` | yes — before `_id` attach/return |
| 2 | `get_templates` | view | yes (query-scoped, not per-doc) | `Doc_templates.php:332` — `$where=[['schoolId','=',$this->school_id]]` | yes — school_id is the query predicate itself, not a post-filter |
| 3 | `get_versions` | view | yes | `Doc_templates.php:387` | yes — before the version loop runs |
| 4 | `version_pdf` | view | yes | `Doc_templates.php:461` (head) + `:474-481` (path containment) | yes — `show_404()` before any header/byte is sent |
| 5 | `get_blocks` | view | yes (query-scoped) | `Doc_block_service.php:74` — `listFor($schoolId,...)`, `$schoolId` is `$this->school_id` from the controller call at `Doc_templates.php:585` | yes |
| 6 | `get_types` | view | n/a — no per-doc read, one keyed read of the caller's own `schools/{schoolId}` | `Doc_templates.php:257` | n/a |
| 7 | `presence` | view | **partial — see §1a** | `Doc_presence::heartbeat`, no existence/ownership check on `templateId` | writes always land under the caller's OWN `schoolId` prefix regardless |
| 8 | `leave` | view | same as `presence` | `Doc_presence::leave` | same |
| 9 | `duplicate` | edit | yes | `Doc_templates.php:538` — inline, on the source read | yes — before `create()` is called with the seed |
| 10 | `create` | edit | n/a by design — mints a new doc under `$this->school_id` from session, never a client field | `Doc_templates.php:636` | n/a |
| 11 | `save` | edit | yes | via `head()`, `Doc_template_service.php:894` (called at `:316`) | yes — before the patch is applied |
| 12 | `validate` | edit | n/a — operates on a client-supplied JSON body, no stored doc read | — | n/a |
| 13 | `preview` | edit | n/a — same, pure function of the POSTed body | — | n/a |
| 14 | `proof_pdf` | edit | yes | `Doc_templates.php:827` — inline | yes — before render/hash/write |
| 15 | `upload_asset` | edit | n/a — writes only under `uploads/{$this->school_id}/...`, path built server-side | `Doc_templates.php:941` | n/a |
| 16 | `save_block` | edit | **partial — see §1b** | `Doc_block_service.php:100-105` | only on an **overwrite** of an existing doc; a first write is unchecked |
| 17 | `publish` | manage | yes | via `head()` | yes |
| 18 | `activate` | manage | yes | via `head()`, plus the sibling-scan query is itself `schoolId`-scoped (`:650-653`) | yes |
| 19 | `deactivate` | manage | yes | via `head()` | yes |
| 20 | `delete` | manage | yes | via `head()` | yes |
| 21 | `archive` | manage | yes | via `head()` | yes |

**Verdict: every endpoint that reads or writes a specific existing document has a real
tenant check, reached before the data is returned or the write applied.** The four
lifecycle methods the service's own comment says *used to* have none (`save`, `publish`,
`activate`, `archive`) now do, centrally, via `head()` — confirmed by reading that method,
not by trusting the comment. `[CONFIRMED]`

### 1a · `presence`/`leave` — no template-existence/ownership check, but structurally inert

`Doc_presence::heartbeat()`/`leave()` take a caller-supplied `templateId` string with only
`safe_path_segment()` character validation (`Doc_templates.php:503,517`) — **no check that a
template with that id exists or belongs to this school.** But the write key is
`{schoolId}_{templateId}_{userId}` (`Doc_presence.php:61-64`) where `$schoolId` is always
`$this->school_id` from the session — **never client-supplied.** A caller can plant a
presence row referencing a foreign school's real (or fake) template id, but it lands under
their OWN school's key prefix, and every read (`others()`) is filtered by
`schoolId == mine` (`Doc_presence.php:102-105`). **No cross-tenant read or write is
possible through this path** — the gap is a missing existence check, not a tenant boundary
gap. `[CONFIRMED, low severity]`

### 1b · `save_block` — cross-tenant TOCTOU on a NEW block id (§3 also covers this)

The controller forces `$data['schoolId'] = $this->school_id` before every call
(`Doc_templates.php:977`) — confirmed independently, not merely as claimed. `Doc_block_service::save()`'s
protection is: *if a document already exists at `$docId` AND its stored `schoolId` differs
from the caller's* → refuse (`:100-105`). This is a **read-then-compare-then-write**, not a
Firestore precondition — the same TOCTOU shape as `Doc_template_service::create()`'s
numbering race (`01b-backend-spec.md` §4) and `save()`'s lock check (§5), which this pass
independently re-derives here for a THIRD instance. **Consequence traced:** if staff at
School A and School B both call `save_block` with the identical caller-chosen `blockId`
string inside the same narrow window (before either write lands), both `get()` reads see
"does not exist," both pass validation, and whichever `set()` reaches Firestore second
**overwrites the first — with the second caller's `schoolId`, silently reassigning a block
one school just created to another school's tenant.** Requires (a) two different schools'
staff independently choosing the exact same string as a block id, and (b) a race window on
the order of the cross-region Firestore latency this codebase's own comments cite (~1.7-2.3s,
`Doc_presence.php:71-73`) — narrow but real, not theoretical, at that latency. **Currently
unreachable from the shipped UI** (`save_block` has zero callers in `designer.js`,
per `00-dependency-graph.md` §2), but it is reachable directly over HTTP by any `edit`-grade
session. `[CONFIRMED code path; exploit requires a specific low-probability collision,
rated P3]`

### 1c · Designed test for what the live probe in `_live-state.md` could not prove

`_live-state.md` correctly flags that a probe against a *non-existent* foreign id cannot
distinguish "the tenant check fired" from "the document is simply absent." The test that
would resolve it: **seed or obtain a real `documentTemplates` doc id belonging to a SECOND
real school** (a second test tenant, or a fixture written directly via the Firestore
console/emulator, not through this module), then, authenticated as School A, call each of
`get_template`, `get_versions`, `version_pdf`, `save` (with a plausible `lockVersion`),
`publish`, `activate`, `deactivate`, `delete`, `archive` against School B's real id and
confirm every one refuses with the SAME message/code as a genuinely-absent id (no
existence oracle) — see §2 for why that message-parity check matters independently.
`[UNKNOWN — this pass could not execute it; H1/H2 fixture needed, per `_live-state.md`]`

---

## 2 · IDOR analysis

**`templateId`** — every reachable read/write funnels through `get_template`'s shape (tenant
check on the way out) or `head()`'s (tenant check on the way in); §1 shows no gap. The
error message for "foreign" and "absent" is identical (`RuntimeException("no template
'$docId'")` in the service, `"Template not found"` in the controller) — **no existence
oracle across tenants**, confirmed independently at both layers, not just the one
`_live-state.md` already checked live. `[CONFIRMED]`

**`blockId`** — see §1b; the one real gap, cross-tenant TOCTOU on first write, currently
dead from the UI.

**`version`** (on `version_pdf`) — `(int) $this->input->get('version')`, no range check
before use. Traced the consequence: a nonexistent version number resolves `$snap` to
`null`/`[]`, `$rel` falls back to the naming-convention path
(`Doc_templates.php:469-471`), and the subsequent `realpath()` containment check
(`:476-481`) returns `false` for a file that doesn't exist on disk — **fails closed to
`show_404()`**, not to a directory listing or an error leaking the constructed path. No
IDOR via version-number brute force: an attacker can only ever land on a version that (a)
was really published for a template in THEIR OWN school (tenant check already gates the
head lookup before `$snap` is even attempted) and (b) has a real file on disk. `[CONFIRMED]`

**Asset paths** (`upload_asset` writes, image `src` reads) — filenames are
`sha256(bytes).ext`, never caller-chosen (`Doc_templates.php:946-947`), so there is no
IDOR via filename manipulation on write. On READ: see §6 — these files are served
**statically**, with no capability or tenant check at all, protected only by the hash being
unguessable. That is a different class of gap (missing authorization layer, not IDOR in the
classic sense) — covered in §6.

**`version_pdf` path construction and containment, defeated on paper:** `$id` is
`safe_path_segment`-validated (character allow-list, not traversal-aware by itself), but
`basename($id)` is used when building the fallback path (`Doc_templates.php:471`), which
strips any directory component even if one somehow survived the earlier check. The
constructed path is then `realpath()`'d and compared against a `realpath()`'d root with
`str_starts_with($file, $root . DIRECTORY_SEPARATOR)` (`:476-481`). **Attempted defeats:**
- Absolute path in `$id` (`/etc/passwd`) — `_is_safe_segment()` (character allow-list, per
  `01b-backend-spec.md`'s citation of `MY_Controller.php:980-997`) almost certainly rejects
  `/`; even if it didn't, `basename()` reduces it to `passwd` before concatenation, and the
  constructed path would not resolve inside the proof directory as a real file (a 404, not a
  traversal).
- `..` sequences — same `basename()` defense, and `Doc_serializer::guardSrc()` separately
  rejects `..` for the design-time image path (`:591-614`), though that guard does not apply
  to `version_pdf`'s own construction — `version_pdf` relies on `basename()` +
  `realpath()`/`str_starts_with()` alone, which is sufficient on its own.
- Symlink escape — `realpath()` resolves symlinks before the containment compare, so a
  symlink planted inside the proof directory pointing outside it would still be caught,
  **provided nothing else on this path lets an attacker plant a symlink inside
  `uploads/{schoolId}/doctemplates/_proofs/` in the first place** — no code path in this
  module writes anything there except `proof_pdf()`, which writes PDF bytes via
  `file_put_contents()`, not a symlink. No planting vector found.
- **Verdict: the containment defense holds under every construction this pass could devise.**
  `[CONFIRMED — sound, defeats attempted]`

---

## 3 · Privilege escalation — the full unstripped-field audit

Mission's core question: what can an `edit`-grade caller reach that should require
`manage`, via a field a strip list omits?

### 3a · `save()`'s strip list vs. the FULL head field set

Head field set from `create()` (`Doc_template_service.php:210-243`), cross-checked against
the live E3 field census in `_live-state.md`: `schoolId, templateId, docType, docTitle,
name, status, version, lockVersion, publishedVersion, activeVersion, page, header, footer,
objects, languages, defaultLanguage, contractRef, complianceBasis, complianceLayers,
starterId, createdBy, createdAt, updatedAt` (+ `updatedBy`, `lastProof` written elsewhere;
`blockRefs`/`blockIgnored` dead — §11 of `01c`).

`save()`'s strip list (`Doc_template_service.php:336-339`): `status, publishedVersion,
activeVersion, templateId, schoolId, updatedBy, createdBy` — **7 of 24 fields.**

| Field | Stripped? | Client can set via `save()`'s patch? | Consequence |
|---|---|---|---|
| `status` | yes | no | — |
| `publishedVersion` | yes | no | — |
| `activeVersion` | yes | no | — |
| `templateId` | yes | no | — |
| `schoolId` | yes | no | — |
| `updatedBy` | yes | no | — |
| `createdBy` | yes | no | — |
| `lockVersion` | **no**, but overwritten unconditionally 2 lines later (`:341`) | effectively no — any client value is discarded | none |
| **`docType`** | **no** | **yes** | **[CONFIRMED, REPRODUCED — `_live-state.md` L9]** an `edit`-grade caller creates an ungated `custom:` type, then `save()`s `docType` into any state-gated statutory type the school is not entitled to create. `_safe_type()` gates `create()` and `index()` only (`Doc_templates.php:1095-1114`), never `save()`. Rated **P2** by QA-LEAD on reproduction (business-rule bypass within one's own tenant; becomes P1 the day `CON-NO_PRINT_IMPL` is wired). |
| **`version`** | **no** | **yes** | **NEW, this pass — [CONFIRMED code path, not executed live].** See §3b. |
| `name` | no | yes | Cosmetic — intended editable field, no security meaning. |
| `page/header/footer/objects/languages/defaultLanguage` | no | yes | Intended editable design content — this is what `save()` exists to let an author change. Security-relevant sub-fields inside `objects[].style.*` are covered in §7 (injection), not here. |
| `docTitle` | no | yes | Cosmetic display name for custom types; no gating role (`_safe_type()` never reads it back). |
| `contractRef` | no | yes | Traced: nothing reads `contractRef` to select validation or rendering behaviour — `validate()`/`preview()`/`proof_pdf()` all key the contract lookup off `docType` (`Doc_templates.php:682,792,832`), never `contractRef`. Descriptive metadata only. **Not exploitable.** `[CONFIRMED]` |
| `complianceBasis`/`complianceLayers` | no | yes | Considered as a possible escalation (a lower-grade user fabricating the legal-compliance record) and traced into `Doc_compliance.php`: this class only ever **reports** which templates are behind an authority revision (`affectedByAuthority()`, `:77-152`); it has no method that reads these fields for a gating decision, and its own header comment states the field is explicitly meant to be a human/school self-report, not server-computed. **By design, not a defect** — these are seeded from the client at `create()` too (`Doc_templates.php:557-558`), consistently. `[CONFIRMED, considered and closed]` |
| `starterId` | no | yes | Provenance label only (which starter a template was cloned from); nothing reads it for a security decision. |

**Also checked: `create()`'s seed strip list** (`Doc_templates.php:630-633`) —
`schoolId, templateId, status, version, lockVersion, publishedVersion, activeVersion,
lastProof` (8 keys) — `docType` is not a seed field at all (it's the separate, `_safe_type()`-gated
first argument), so `create()` itself is not vulnerable to the same shape. Consistent finding.

### 3b · NEW: unstripped `version` on `save()` — numbering corruption and a `get_versions()` cost/DoS amplifier

`version` is not in `save()`'s strip list. Traced the consequence end to end:

1. An `edit`-grade caller calls `save(id, {version: 999999}, lockVersion)`. Nothing rejects
   an arbitrary integer — no range or monotonicity check exists on this field anywhere in
   `Doc_template_service::save()`.
2. The head's `version` field is now `999999`. This is the SAME field `publish()` reads
   unconditionally: `$version = (int) ($head['version'] ?? 1);`
   (`Doc_template_service.php:497`), and on a successful publish, `publishedVersion` is set
   to that value (`:558`) and the snapshot id becomes `{docId}_v999999`
   (`:514`).
3. **`get_versions()` (`Doc_templates.php:381-421`, `view`-graded — reachable by ANY staff
   member with mere `view` on Certificates) then does:**
   ```php
   $highest = (int) ($head['publishedVersion'] ?? 0);
   for ($v = $highest; $v >= 1; $v--) {
       $doc = $this->fs->get('documentTemplateVersions', $id . '_v' . $v);
       ...
   }
   ```
   — an **unbounded, linear, per-integer Firestore read loop**, driven directly by a value
   an `edit`-grade caller planted. At `publishedVersion = 999999` this issues up to 999,999
   individual cross-region Firestore reads on a single HTTP request — at this codebase's own
   cited ~1.7-2.3s per cross-region call, this either times out the PHP request (denial of
   service on the endpoint itself, for every future viewer of that template's history) or, if
   it somehow completed, runs up real Firestore read-cost. Every subsequent call to
   `get_versions` for that template repeats the full scan; the corruption is permanent
   (`version`/`publishedVersion` are only ever advanced, never reset, by any code path found).
4. This does NOT require a `manage`-grade accomplice in practice: this codebase's RBAC
   grades are hierarchical (`view < edit < manage`, per `CLAUDE.md`/`rbac_helper.php`), so
   any `manage`-grade holder already has `edit` and can trigger both the tampering `save()`
   and the triggering `publish()` in one session; even without that, an `edit`-grade actor
   who plants the inflated `version` merely has to wait for the template's normal,
   routine next publish by a colleague to detonate it.

**Severity, calibrated against this program's own precedent** (`_live-state.md` L9 rated
the `docType` bypass P2 — "a business-rule bypass... not a tenant or auth breach"): this is
the same shape (an unstripped field, same-tenant actor) but the consequence is a
resource-exhaustion/cost vector reachable by a THIRD party (any `view`-grade viewer, not
just the actor), not merely a self-contained business-rule violation. Rated **P2**, flagged
for QA-LEAD to consider raising given the blast radius extends past the acting user.
`[CONFIRMED — code trace; NOT reproduced live, no publish was triggered this pass]`

### 3c · Other escalation shapes checked and closed

- **`recordProof()` accepting a caller-supplied head** (`Doc_template_service.php:438-446`):
  only ever called with either `null` (re-reads via `head()`, tenant-checked) or the
  already-tenant-checked `$tpl` from `proof_pdf()` — and even then re-validates
  `$head['schoolId'] !== $this->schoolId` before trusting it (`:442-446`). Not exploitable.
- **Reaching `manage` outcomes via a route CI3 dispatches without an explicit `routes.php`
  entry**: `publish`, `activate`, `deactivate`, `delete`, `archive` are ALL still
  capability-gated centrally by `_remap()` (`Doc_templates.php:96-116`) regardless of
  routing — confirmed by reading `_remap()` directly: it fires on every request to this
  controller before any method runs, keyed off the method name, not the route table.
  `L4`'s live dispatch of `delete`/`deactivate`/`archive`/`get_versions` without an
  explicit route is consistent with this: reachable, but still `manage`-gated. No
  escalation via routing obscurity.

---

## 4 · Client-trusted-field table

| Endpoint | Field | Trusted from client? | Where forced server-side instead |
|---|---|---|---|
| `create` | `schoolId` | no | `Doc_templates.php:636` — `$this->school_id` (session) |
| `save_block` | `schoolId` | no | `Doc_templates.php:977` — verified independently, not merely per the mission's claim |
| `presence`/`leave` | `schoolId` | no | `Doc_templates.php:505,519` |
| `proof_pdf`/`publish`/`activate`/`deactivate`/`delete`/`archive` | actor identity (`by`) | no | `Doc_templates.php::_actor()` (`:130-133`) reads `$this->admin_id` from session, never a POST field |
| `publish` | the proof (`hash`/`fontManifest`/`mpdfVersion`/`contentHash`) | **no** | `publish()` reads only `$head['lastProof']`, itself written server-side by `recordProof()` from bytes mPDF produced — never from the request (`01b-backend-spec.md` §6, independently re-confirmed by reading `publish()` at `Doc_template_service.php:481-513`) |
| `save` | `docType`, `version` | **yes — the two escalation findings above** | not forced/stripped |
| all lifecycle transitions | `activeVersion`/`publishedVersion`/`status` | no | stripped in `save()`; never accepted as input at all by `publish`/`activate`/`deactivate`/`archive` (they take only `templateId` + an optional target version) |

**No field was found where a write ends up trusting a client value for `schoolId` or
actor identity.** The two gaps are both narrower: specific NON-identity fields (`docType`,
`version`) that a strip list omits.

---

## 5 · Upload attack surface (`upload_asset`)

Attacked on paper against the real checks (`Doc_templates.php:910-961`):

- **Polyglot file (valid PNG + trailing/embedded PHP)**: `finfo::file()` content-sniffs the
  MIME type from the file's actual bytes/magic numbers, not the extension or client
  `Content-Type` header (`:930`). A GIF89a/PNG-polyglot crafted to also be valid PHP would
  still sniff as `image/png` or similar and be ACCEPTED — but the stored filename is a
  content hash with a fixed extension from an allow-list (`png|jpg|webp` only,
  `:946-947`), served from a directory with no `.php` handler association found in this
  module's own `.htaccess` scope (`uploads/.htaccess` only special-cases `.pdf`). **A
  polyglot cannot be executed as PHP through this path** unless the web server is
  separately configured to execute `.png`/`.jpg`/`.webp` as PHP (out of scope for this
  module, would be a server-config defect, not this endpoint's). `[CONFIRMED — content-sniff
  + fixed-extension-from-allowlist closes the classic polyglot RCE path]`
- **SVG**: not in `ASSET_MIME` (`png|jpeg|webp` only, `:889-893`) — `finfo` would sniff an
  SVG as `image/svg+xml` (or `text/html`/`text/plain` depending on content), which is not
  in the allow-list → rejected. SVG's own XSS/XXE/script-execution risk class is closed by
  the allow-list, not by any SVG-specific sanitization (none is needed if SVG can never
  pass). `[CONFIRMED]`
- **Decompression bomb** (e.g., a PNG with an enormous decompressed pixel buffer inside a
  small file): `getimagesize()` is called (`:936`) — this reads image headers/dimensions,
  it does NOT decompress the full pixel data, so a bomb would pass this check. The 4 MiB
  **compressed file-size cap** (`ASSET_MAX_BYTES`, `:896,922-925`) is the only real limit —
  it bounds the bytes read from `$_FILES`, but does not bound what `getimagesize()` or any
  LATER consumer (e.g., mPDF embedding the image at proof-render time) might allocate when
  decoding a maximally-crafted small-file/huge-dimension PNG. `[UNKNOWN — not traced into
  mPDF's own image decoder; a crafted small PNG with an extreme declared width/height could
  be a memory-exhaustion vector at RENDER time, separate from upload time. Test: upload a
  PNG with a huge `IHDR` width/height and a tiny compressed body, then call `proof_pdf()`
  against a template that references it, and observe memory use — `Doc_renderer::MAX_MEMORY`
  (96M, `:38`) is the only backstop found, and it is a `memory_limit` INI setting, not a
  guarantee mPDF respects it gracefully rather than fatal-erroring mid-render]`
- **A file that passes MIME-sniff + `getimagesize()` but isn't what it claims** (e.g., a
  crafted JPEG that is technically valid per both checks but embeds a malicious ICC
  profile, EXIF metadata, or a decoder-targeting exploit for whatever image library mPDF
  uses internally to embed it into the PDF): **not addressed by this endpoint at all** —
  both checks validate "is this a structurally real image," not "is this image safe for
  every downstream consumer." This is the same open question the codebase's own comment on
  `getimagesize()` implicitly leaves: it is "a second opinion," not an exhaustive one.
  `[UNKNOWN — would require fuzzing mPDF's image-embedding path specifically, out of scope
  for a static pass]`
- **Path handling of the hashed name**: `hash_file('sha256', ...)` (`:946`) — deterministic,
  not attacker-chosen, closes traversal/overwrite outright, independently re-confirmed.
  `is_file($dest)` short-circuit before `move_uploaded_file()` (`:948`) means a
  byte-identical re-upload is a safe no-op, not a race — the file that would already be
  there is byte-identical by construction (same hash ⇒ same bytes), so there is no
  meaningful TOCTOU here even though it LOOKS like one.
- **Where served, and can a non-image be retrieved?** Static, from
  `uploads/{schoolId}/doctemplates/assets/` (`:941,955`), with NO capability or tenant
  check on the read — see §6. Since only `png/jpg/webp` extensions are ever written by this
  endpoint and the content was sniffed before acceptance, a non-image cannot land in this
  directory THROUGH this endpoint. (Whether something else can write into the same
  directory tree was not traced this pass — out of this module's scope.)

---

## 6 · PII and data exposure

- **Student/parent PII on a template or in a rendered proof PDF: none, today.** Every
  render path this pass traced (`preview()`, `proof_pdf()`) forces `sample: 'typical'|'p95'`
  and resolves merge fields from the CONTRACT's own sample values
  (`Doc_serializer::resolve()`, `:684-696`), never from a real student/parent record — no
  code path in this module reads a `students`/`parents` collection at all (consistent with
  `00-dependency-graph.md` §3b's read-only collection list: only `schools` and
  `complianceAuthorities`). `CON-NO_PRINT_IMPL` (nothing issues a real document) means this
  is not merely well-designed, it is currently the ONLY possible outcome. `[CONFIRMED]`
- **Crest/signature images ARE potentially sensitive** (a principal's signature image,
  a school seal) and are served **statically with no authorization check at all** — see
  next bullet.
- **Asymmetric protection: proof PDFs vs. uploaded assets.** Proof PDFs
  (`uploads/{schoolId}/doctemplates/_proofs/*.pdf`) are blocked from direct HTTP access by
  `uploads/.htaccess`'s `<FilesMatch "\.pdf$">` rule and served only through the
  authenticated, tenant-checked `version_pdf()` endpoint. **Uploaded crest/signature images
  (`.../assets/*.png|jpg|webp`) have no equivalent `.htaccess` rule and no endpoint gate at
  all** — `00-dependency-graph.md` §3c itself notes they are "served statically,
  deliberately... since filenames are unguessable sha256 hashes." That is real protection
  against brute-force enumeration (a 256-bit preimage is not guessable), but it is
  **obscurity, not authorization** — the file is served to ANY requester, authenticated or
  not, tenant or not, the moment the exact hash+extension is known. A hash can leak through
  ordinary channels this module doesn't control: shared screenshots that include page
  source, browser history/autofill on a shared machine, a proxy/CDN access log, or a future
  feature that lists asset URLs in an API response for some other purpose. **A new finding
  this pass, not previously flagged**: this is a real asymmetry in the module's own design
  — one artifact type (proofs) got a real access-control decision, the sibling artifact
  type (assets) got obscurity only, and nothing documents that as a deliberate,
  reviewed trade-off versus an oversight. Rated **P3** (mathematically sound preimage
  resistance today; flagged because it is inconsistent with the module's own stated
  posture on the sibling artifact, and because nothing enforces that the hash never leaks).
- **Logs**: `log_message('error', ...)` calls throughout this module log the exception
  MESSAGE, and several of those messages include the raw `templateId`/`docId`
  (e.g., `Doc_templates.php:105,235,1111`) — ids, not PII. No student name, no merge-field
  VALUE, and no uploaded file's binary content is logged anywhere this pass found. `[CONFIRMED
  absence of PII in logs, for this module specifically]`

---

## 7 · Injection and output encoding

`Doc_serializer.php` is the one HTML producer for both the browser preview and the mPDF
render (`render()`, one function, two sinks — by design, per the file's own header). Traced
escaping at every sink:

| Sink | Escaped how | Sound? |
|---|---|---|
| Text run literal (`r.t`) | `esc()` = `htmlspecialchars(…, ENT_QUOTES\|ENT_SUBSTITUTE, 'UTF-8')` (`:662,967`) | yes, for HTML body content |
| Merge-field resolved value | `esc()` (`:657,795`) | yes |
| Table cell / repeating-table cell value | `esc()` (`:781-783,795-796,872-873`) | yes |
| `object` type attribute | `esc()` (`:450`) | yes |
| Image `src` | `esc()` **plus** `guardSrc()` scheme/traversal reject (`:562,566,591-614`) | yes — double-gated |
| Template name / `docTitle` in the PANEL UI | not this file's concern (client-rendered by `designer.js`) — **out of this agent's server-only scope**, flagged `[UNKNOWN — needs the client-side agent]` | — |
| **`style.fontFamily`, `style.track` (letter-spacing)** | `esc()` only, INSIDE a `style="…"` HTML attribute (`:474,477`) | **NO — see finding below** |
| **`content.columns[].align` in `repeatingTable()`** | `esc()` only, same context (`:858,872`) — note the file HAS a proper whitelist function, `align()` (`:952-954`), used correctly for the non-repeating-table case (`:439`), but NOT used here | **NO — same defect, second site** |
| `colour` | whitelist regex `^#[0-9A-Fa-f]{3,8}$`, else hardcoded `#000` (`:958-960`) | yes |
| `align` (non-table sites) | whitelist against `['left','right','center','justify']` (`:952-954`) | yes |

### 7a · NEW FINDING — CSS injection inside a `style` attribute bypasses the SSRF guard entirely

`htmlspecialchars()` is the correct encoding for placing a value inside HTML *text content*
or breaking out of an HTML *attribute quote*. It is the **wrong encoding for a CSS
property value inside a `style="…"` attribute** — it escapes `<`, `>`, `&`, `"`, `'`, but
**not `:` or `;`**, which are exactly the characters that let a value inject additional CSS
DECLARATIONS into the same attribute rather than just supplying one property's value.

**Concrete exploit, traced end to end:**

1. An `edit`-grade caller sets, via `save()`'s `patch.objects[].style.fontFamily`, the
   string:
   ```
   Arial;background:url(https://attacker.example/beacon.png)
   ```
   Nothing rejects this — `objects` has no size/shape validation anywhere in `save()`
   (confirmed independently, matching `01b-backend-spec.md` §7's "no size cap" finding, and
   `style.fontFamily` specifically has no allow-list, unlike `colour`/`align`).
2. `textCss()` emits it verbatim through `esc()`:
   `font-family:Arial;background:url(https://attacker.example/beacon.png);`
   inside the object's `style="…"` attribute (`Doc_serializer.php:474`, then `:447`).
3. **Browser preview path** (`preview()`, no renderer involved): the browser parses this as
   TWO CSS declarations — `font-family` and `background` — and issues a real network
   request for the attacker's URL from inside the admin panel's origin, the moment anyone
   (the author, a reviewer, a colleague opening the template) previews it.
4. **PDF/proof path** (`proof_pdf()`): the same HTML is handed to `Mpdf::WriteHTML()`
   (`Doc_renderer.php:235`) — mPDF is documented, widely, to fetch remote images referenced
   anywhere in the CSS it processes, including `background-image`/`background: url(...)`,
   not only `<img src>`. **`Doc_renderer::guardImages()` — the function whose own doc-comment
   says "mPDF fetches remote images server-side, so an unvalidated src is an SSRF
   primitive" — only scans for `<img\b[^>]*\bsrc=` via regex
   (`Doc_renderer.php:391,403-425`). It never inspects `style` attributes or `<style>`
   blocks for `url(...)` references.** The one guard this module built specifically to
   prevent SSRF **does not cover this injection point at all.**
5. **Consequence**: on the PDF path, this is **server-side SSRF from the production
   PHP process** (the Ohio Lightsail box, per `CLAUDE.md`) to an attacker-chosen URL — not
   merely a browser-side beacon. An attacker-controlled URL reaching an AWS-hosted server
   can target the AWS instance metadata endpoint, internal network addresses, or simply
   confirm outbound reachability and fingerprint the server's egress IP. This is a
   materially more severe consequence than the browser-only exfiltration `guardSrc()`
   protects the `<img>` element against — it defeats the EXACT protection
   `Doc_renderer::guardImages()` exists to provide, through a sibling path the guard's
   author did not cover.
6. **Second site, same defect**: `repeatingTable()`'s `align` value (`:858,872`) is
   attacker-controlled via `content.columns[].align`, escaped the same wrong way,
   in the same `style="…"` context. Lower blast radius than `fontFamily` (an `align` value
   is more likely to be constrained by UI dropdown in practice, but nothing SERVER-SIDE
   enforces that — this endpoint accepts raw JSON), but the same defect class, and the file
   demonstrably HAS the correct fix pattern already (`align()`'s whitelist) sitting right
   next to the vulnerable call — an instance of this program's own catalogued
   *"sibling-path parity drift"* pattern (`_patterns.md`).

**Severity: rated P1.** Unlike the `docType`/`version` findings (§3), which are
same-tenant business-rule bypasses, this reaches OUTSIDE the tenant's own data — it is
server-side request forgery from the production host, and it defeats a control
(`guardImages()`) that was purpose-built against exactly this threat model. It requires
only `edit` grade (lower than `manage`), reachable via the already-wired, already-called
`save()`/`preview()`/`proof_pdf()` endpoints (no dead-code caveat, unlike `save_block`).

**Fix shape** (not applied — Gate A, review only): whitelist `fontFamily` against the
known-registered face list (`FONT_FACES` + a small Latin allow-list) the way `colour`/`align`
already are, reject/clamp `track` to a numeric-with-unit pattern, and extend
`Doc_renderer::guardImages()` to also scan `style="…"` attribute values (and any `<style>`
block) for `url(...)` references, not only `<img src>`.

### 7b · `data:` URI history check

This codebase has a documented history of CI3's `global_xss_filtering` mangling `data:`
URIs (per the mission brief). `Doc_serializer::guardSrc()` REJECTS any scheme-qualified
value outright, including `data:` (`:591-603`, the "any scheme at all" regex explicitly
calls out that `javascript:`/`data:` carry no `//` so a naive `scheme://` test would miss
them — the code already accounts for this). Since `data:` is rejected rather than merely
mangled, the CI3 XSS-filter interaction that broke other modules cannot recur here for
IMAGE src specifically. `[CONFIRMED, not applicable to this module's image path]`. The CSS-injection
finding above is a separate, un-mitigated bypass of the SAME underlying guard's intent, via
a different attribute the guard doesn't cover.

---

## 8 · CSRF

- **Independently confirmed**: `doc_templates` (and `certificates`) do not appear anywhere
  in `$config['csrf_exclude_uris']` — full array read at `application/config/config.php:184-213`,
  reproduced here rather than trusted from the controller's own doc-comment or from `01b`.
- Global CSRF is ON, `csrf_regenerate = FALSE`. Every mutating endpoint requires POST
  (`_require_post()`, `Doc_templates.php:146-153`), and CI3's CSRF middleware runs ahead of
  the controller for any non-excluded URI — a POST without a valid token 403s before
  `_remap()` is even reached.
- **Rotation/desync check**: because `csrf_regenerate = FALSE`, the token hash is constant
  for the life of the session — it does NOT rotate per request. `json_success()`/
  `json_error()` echoing a fresh `csrf_token` on every response (`MY_Controller.php:1095,1107`)
  is therefore not compensating for server-side rotation (there is none); it is there so the
  client always has a copy, which matters only if something ELSE (a session
  regenerate/logout-login cycle) changes the hash mid-session. **No desync scenario found**:
  multiple tabs, rapid double-submits, and long-idle tabs all read the SAME constant hash
  for the session's lifetime, so there is no race between "server rotated, client hasn't
  caught up yet" — that race only exists when `csrf_regenerate = TRUE`, which this app does
  not use. `[CONFIRMED — no lockout/abuse vector via CSRF rotation for this module]`

---

## Counts

| Item | Count |
|---|---|
| Endpoints with a tenant check reached before data return/write | 15 of 15 that touch a specific existing document (100%) |
| Endpoints with no per-doc tenant check needed (n/a — schoolId-scoped query, or no doc read) | 6 |
| Confirmed cross-tenant IDOR | 0 |
| Confirmed cross-tenant TOCTOU (narrow, currently UI-dead) | 1 (`save_block`, §1b/§3) |
| Confirmed same-tenant privilege-escalation shapes (unstripped field in `save()`) | 2 (`docType` — previously reproduced P2; `version` — new this pass, P2) |
| Fields checked in the full unstripped-field audit | 17 (of the 24-field head schema) |
| Of those, exploitable | 2 (`docType`, `version`) |
| Of those, considered and closed (no gating role found) | 4 (`contractRef`, `complianceBasis`, `complianceLayers`, `starterId`) |
| Client-trusted-field violations found (identity/tenant fields) | 0 |
| Upload checks defeated on paper | 0 of 4 attempted (polyglot, SVG, MIME-mismatch RCE) |
| Upload checks with an open question | 2 (decompression-scale memory exhaustion at RENDER time; downstream image-decoder fuzzing) |
| PII found in any render/log path | 0 (student/parent); logged ids only |
| New style-attribute CSS-injection sites found | 2 (`fontFamily`/`track` in `textCss()`; `align` in `repeatingTable()`, both in `Doc_serializer.php`) |
| `guardImages()` coverage gap | confirmed — regex targets `<img src>` only, no `style=`/`<style>` scanning |
| CSRF exclusion for this module | 0 (protected) |
| CSRF rotation desync scenarios found | 0 |

---

## Named gaps / `[UNKNOWN]`s and the test that would resolve each

1. **Real cross-tenant probe (§1c)** — needs a second real school's genuine template id to
   distinguish "tenant check fired" from "doc absent." Test: seed a fixture in a second
   tenant, replay every mutating+reading endpoint against it from School A's session,
   confirm identical refusal shape to a truly-absent id.
2. **`save_block` cross-tenant TOCTOU (§1b/§3)** — plausible from code, not reproduced live.
   Test: two authenticated sessions (different schools) racing an identical `blockId` POST
   within the same ~1-2s window; confirm which write wins and whose `schoolId` the surviving
   document carries.
3. **`version` field DoS via `get_versions()` (§3b)** — traced from code, not detonated
   live. Test: `save()` a template's `version` to a large integer, `proof_pdf()` +
   `publish()` it, then time a `get_versions()` call and observe request duration /
   Firestore read count.
4. **CSS-injection SSRF (§7a)** — the single highest-value test to run next. Test: set
   `style.fontFamily` on a template object to a payload containing `;background:url(...)`
   pointing at a request-logging endpoint the reviewer controls, call `preview()` (confirm
   browser-side fetch in devtools/network log) and `proof_pdf()` (confirm or rule out a
   server-side outbound request via the logging endpoint's own access log) against a
   controlled target, not a real internal address.
5. **Upload decompression/decoder exhaustion (§5)** — not traced into mPDF's own image
   decoder. Test: upload a crafted small-file/huge-declared-dimension PNG that passes
   `getimagesize()`, reference it on a template, call `proof_pdf()`, and observe memory/time
   against `Doc_renderer::MAX_MEMORY`/`MAX_SECONDS`.
6. **Asset-image hash-leak exposure (§6)** — no test designed here beyond noting the
   asymmetry versus proof PDFs; this is a design-posture question for QA-LEAD/product
   (should crest/signature images get the same `.htaccess`-block + gated-endpoint treatment
   proofs got?), not a live-reproducible bug on its own.
7. **`docTitle`/`name`/panel-UI escaping** — explicitly out of this agent's server-only
   scope; flagged for whichever agent covers `designer.js`'s own DOM rendering of these
   fields (does it use `textContent`/`innerText` or raw `innerHTML`?).
