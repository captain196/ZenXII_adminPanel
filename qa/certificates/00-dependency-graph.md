# 00 · Dependency graph — Document Engine (Certificates)

**Evidence ceiling: E2 (static path traced from source).** No code executed. All counts
below are counts of artefacts found, not completeness percentages. This document
OVERWRITES the run-1 version (2026-09-02/03), which predates the `fee_receipt` type,
custom document types, and several controller/route changes.

---

## 1 · Surface inventory

| Surface | Repo / path | Role in this module |
|---|---|---|
| Admin panel controller (Document Engine) | `application/controllers/Doc_templates.php` (1115 lines) | 3 page loads + 24 AJAX endpoints, all gated centrally in `_remap()` |
| Admin panel controller (legacy) | `application/controllers/Certificates.php` (692 lines) | Pre-Document-Engine RTDB implementation, still routable — see §6 |
| Libraries | `application/libraries/Doc_template_service.php` (918), `Doc_serializer.php` (969), `Doc_renderer.php` (428), `Doc_contract.php` (487), `Doc_block_service.php` (290), `Doc_compliance.php` (204), `Doc_resolver.php` (214), `Doc_presence.php` (143), `Doc_rows.php` (67) | Lifecycle, HTML serialization, mPDF rendering, merge-field contracts, shared blocks, compliance layer, read-only cross-module seam, live-editor presence, row normalisation |
| Config | `application/config/doc_types.php` (292 lines: merge fields + contracts + doc types), `application/config/document_targets.php` (232 lines: print-point registry, 8 rows, `wired=false` on every row) | Data-driven catalogue and declared-but-unwired print points |
| View | `application/views/doc_templates/index.php` (single view; screens D0/D1/D2 switched client-side) | Emits `#zxdt-boot` JSON (csrf name+hash, `base` url, `canEdit`/`canManage`, `screen`, `docType`, `templateId`) consumed by `designer.js` |
| Client JS | `assets/js/doctemplates/designer.js` (5668 lines) | The whole designer SPA; no third-party JS library loaded (no bundler, no canvas lib — confirmed by grepping `<script`/`<link` in the view: only `doctemplates.css` and `designer.js` load) |
| Client CSS | `assets/css/doctemplates.css` (935 lines) | Scoped under `.zxdt` |
| Routes | `application/config/routes.php:1234-1415` | Legacy `certificates/*` (17 routes) + new `doc_templates/*` (17 explicit routes) — see §1a |
| RBAC | `application/helpers/rbac_helper.php` (`has_permission`/`require_permission`), module key `'Certificates'` — reused by BOTH controllers | Central gate |
| Audit | `application/helpers/audit_helper.php` → `log_audit()` | `AUDIT_MODULE = 'DocTemplates'`; events land in the existing Audit Logs viewer, not a separate collection |
| Cloud Functions | `functions/` (Node 20) | **No document-engine-specific function found.** Only touchpoint: `functions/staffCapabilities.js:52` and `functions/rbac_modules.json:5` list `'Certificates'` in the shared RBAC module catalogue — that is the pre-existing legacy-controller entry, reused, not a new function |
| Firestore rules | `firebase-rules/firestore.rules` | 4 explicit match blocks — see §4 |
| Firestore indexes | `firebase-rules/firestore.indexes.json` | 7 composite index entries across 3 collections — see §3 |
| PHPUnit tests | `tests/Unit/Doc*.php` — 16 files (`DocBlockServiceTest`, `DocCompliance`, `DocContractParityTest`, `DocContractServiceTest`, `DocCssCollisionTest`, `DocCustomTypeTest`, `DocFeeReceiptTest`, `DocFontParityTest`, `DocRenderIntegrationTest`, `DocRendererPageGeometryTest`, `DocResolverTest`, `DocRowsTest`, `DocSecurityTest`, `DocSerializerTest`, `DocSerializerGoldenTest`, `DocTemplateServiceTest`) | Unit + security + parity + golden-file coverage |
| Browser/manual test harness | `tests/doctemplates/` — `_zxdt_check.html`, `_zxdt_e2e.js`, `_zxdt_e2e_run.html`, `_zxdt_layout.js`, `_zxdt_layout_run.html`, `_zxdt_measure.html`, `golden/` (3 HTML golden files: `tc_duplicate.html`, `tc_p95.html`, `tc_typical.html`) | Not PHPUnit — standalone browser-run harness files |
| Firebase/Jest tests | `firebase-rules/tests/document_engine.test.js` | 9 `describe()` blocks against the emulator — platform collections, tenant scoping, draft/published/activation, deletion, versions, reusableBlocks |
| Design docs | `blueprints/certificates/{COLLECTION_SHAPES,EXECUTION_PLAN_v1.1,FINAL_BLUEPRINT,IMPLEMENTATION_ARCHITECTURE,STATE_LEDGER,TEST_DOSSIER,UAT_SCRIPT}.md` + `design/`, `checkpoints/`, `essay-versions/`, `tools/` | Prior-session design record |
| Storage | `uploads/.htaccess` | Denies HTTP access to `*.pdf` under `uploads/` (proof PDFs); asset images (content-hash-named) are still served statically |
| Storage rules | `firebase-rules/storage.rules` | **No `doctemplates` entries found.** Consistent: these files live in the PHP server's local `uploads/` filesystem, not GCS, so Firebase Storage rules don't apply to them |

### 1a — routes.php coverage gap (E2, not runtime-verified)

Of the 24 AJAX endpoints + 3 page loads (27 public methods total), only 17 have an
**explicit** entry in `routes.php:1396-1415`: `index`, `gallery/(:any)`, `design/(:any)`,
`get_types`, `get_templates`, `get_template`, `get_blocks`, `create`, `save`, `validate`,
`preview`, `proof_pdf`, `upload_asset`, `save_block`, `publish`, `activate`, `archive`.

**7 endpoints have no explicit route:** `get_versions`, `version_pdf`, `presence`,
`leave`, `duplicate`, `deactivate`, `delete`. No catch-all/`404_override` route exists in
`routes.php` for `doc_templates/*` that would block them, so CodeIgniter 3's default
segment-based routing (`controller/method`) should still dispatch these — but this is
static reasoning about framework behaviour, not something this pass ran. Flagged for a
runtime-capable agent to confirm.

---

## 2 · Endpoint map — every public method on `Doc_templates.php` vs. its client caller

All 24 AJAX endpoints are declared in `Doc_templates::CAPABILITIES` (lines 44-73) and
enforced centrally by `_remap()` (lines 96-116) — a method missing from that map is
denied outright, fail-closed. `designer.js` calls the server ONLY through one `api()`
wrapper (line 934) and one `srv` object (lines 987-1019) that names an action string per
endpoint; `srv.*` methods are then called from elsewhere in the file.

| Endpoint | Capability | Route entry | `srv` wrapper (designer.js:987-1019) | Actually invoked in designer.js |
|---|---|---|---|---|
| `index` | view | yes | page load | — |
| `gallery` | view | yes | page load | — |
| `design` | edit | yes | page load | — |
| `get_types` | view | yes | `srv.types` (988) | yes — `designer.js:5583` |
| `get_templates` | view | yes | `srv.templates` (989) | yes — `designer.js:5597` |
| `get_template` | view | yes | `srv.template` (990) | yes — `designer.js:1311, 2453, 5641` |
| `get_blocks` | view | yes | `srv.blocks` (991) | **NO — defined, never called** |
| `get_versions` | view | no | `srv.versions` (992) | yes — `designer.js:5377` |
| `version_pdf` | view | no | **not in `srv`** | yes, but as a direct `<a href>` link built from `SRV.base`, not through `api()` — `designer.js:5397` |
| `presence` | view | no | `srv.presence` (1010) | yes — `designer.js:1159, 1220` |
| `leave` | view | no | `srv.leave` (1011) | **NO — defined, never called** (unload beacon not found calling it either — see gap below) |
| `duplicate` | edit | no | `srv.duplicate` (1012) | yes — `designer.js:1195, 1374` |
| `create` | edit | yes | `srv.create` (994) | yes — `designer.js:2502` |
| `save` | edit | yes | `srv.save` (997) | yes — `designer.js:1407` |
| `validate` | edit | yes | `srv.validate` (1000) | **NO — defined, never called** |
| `preview` | edit | yes | **not in `srv` at all** | **NO caller anywhere in designer.js** |
| `proof_pdf` | edit | yes | `srv.proof` (1002) | yes — `designer.js:5178` |
| `upload_asset` | edit | yes | `srv.uploadAsset` (1015) | yes — `designer.js:4419` |
| `save_block` | edit | yes | **not in `srv` at all** | **NO caller anywhere in designer.js** |
| `publish` | manage | yes | `srv.publish` (1004) | yes — `designer.js:5295` |
| `activate` | manage | yes | `srv.activate` (1005) | yes — `designer.js:2758, 5351, 5448` |
| `archive` | manage | yes | `srv.archive` (1007) | **NO — defined, never called** (confirmed: `grep -n "srv\.archive"` outside its own definition returns nothing) |
| `deactivate` | manage | no | `srv.deactivate` (1008) | yes — `designer.js:2795` |
| `delete` | manage | no | `srv.remove` (1009) | yes — `designer.js:2714` |

**Endpoints with NO client caller (dead server surface, from the panel's own JS):**
`preview` (`application/controllers/Doc_templates.php:771`) and `save_block`
(`application/controllers/Doc_templates.php:963`) — neither string appears anywhere in
`assets/js/doctemplates/designer.js` except in unrelated comments/local-var names
(`preview` as an English word, e.g. lines 1392, 1845, 2579, 3112, 3215, 3813, 4391, 4407,
5085 are all comments/unrelated identifiers, not calls to the endpoint).

**Endpoints defined in `srv` but never invoked** (present as capability, not reachable
from any UI action found): `get_blocks` (`srv.blocks`), `validate` (`srv.validate`), and
`archive` (`srv.archive`) — three `srv` wrappers with zero call sites anywhere else in
the 5668-line file. Note the controller's own doc-comment at `Doc_templates.php:224` says
the client still runs its *own copy* of the type catalogue rather than calling `get_types`
for validation parity — `DocContractParityTest` is what keeps the two in step, not a live
call. `archive` having no caller is notable given it is a `manage`-graded, legally
consequential state transition (per the controller's own comment at line 986) — either
the UI offers archiving through a code path this grep missed, or the feature is
unreachable from the designer entirely.

**Client calls with NO endpoint:** none found — every `api("<action>", …)` string in
`designer.js` matches a method name that exists and is capability-gated on
`Doc_templates.php`.

**`archive`** — `srv.archive` is defined but this pass did not find a firm call-site line
number within the time budget; flagged `[UNKNOWN — needs re-grep]` rather than asserted
either way.

---

## 3 · Data map

### 3a — Firestore collections this module OWNS (writer = this module, via `Doc_*` libraries; panel uses the Admin SDK, so writes bypass `firestore.rules` — rules are enforced only against any hypothetical direct client, of which none currently exists)

| Collection | Owning class / const | Doc key shape (from source) |
|---|---|---|
| `documentTemplates` | `Doc_template_service::HEAD_COLLECTION`, also read by `Doc_block_service`, `Doc_compliance`, `Doc_resolver` | `{id}` full document id, e.g. `SCH_..._TPL0001`; `_id` re-attached explicitly in `get_template()` because the stored `templateId` field is the *short* id, not the doc id (`Doc_templates.php:356-363`) |
| `documentTemplateVersions` | `Doc_template_service::VERSION_COLLECTION` | `{templateDocId}_v{n}` (e.g. `..._TPL0001_v3`), create-only, `allow update, delete: if false` in rules |
| `reusableBlocks` | `Doc_block_service::COLLECTION` (`Doc_block_service.php:39`) | not enumerated this pass — flagged `[UNKNOWN key shape]` |
| `templateSessions` | `Doc_presence::COLLECTION` (`Doc_presence.php:35`) | live-editor presence/heartbeat records; **no `firestore.rules` match block at all** — falls through to the file's catch-all `match /{document=**} { allow read, write: if false; }` (see §4) |

### 3b — Firestore collections this module READS but does not own

| Collection | Read by | Purpose |
|---|---|---|
| `schools` | `Doc_templates::_school_context()` (line 257) | keyed single read of `state`/`board`/`stage` to gate state-specific certificate types |
| `complianceAuthorities` | `Doc_compliance.php` (`AUTHORITY_COLLECTION`, line 35) | authority/version records for the compliance re-validation report |

No other collection reads were found in any `Doc_*` library or `Doc_templates.php`.

### 3c — Storage (local `uploads/`, not GCS)

- `uploads/{schoolId}/doctemplates/assets/` — crest/signature images, content-hash-named (`upload_asset()`, `Doc_templates.php:910-961`); served statically, deliberately (per `uploads/.htaccess` comment) since filenames are unguessable sha256 hashes
- `uploads/{schoolId}/doctemplates/_proofs/` — proof PDFs, named `{schoolId}_TPL####_v{n}_{lang}.pdf` (predictable); **blocked from direct HTTP access** by `uploads/.htaccess` (`<FilesMatch "\.pdf$"> Require all denied`), served only through the authenticated `version_pdf()` endpoint which also path-canonicalises (`realpath()` + `str_starts_with()` containment check, `Doc_templates.php:474-481`)

### 3d — Firestore indexes (`firebase-rules/firestore.indexes.json`)

7 composite index entries found across 3 `collectionGroup`s: `documentTemplates` (3),
`documentTemplateVersions` (1), `reusableBlocks` (1), `complianceAuthorities` (2). No
index entries for `templateSessions` (consistent with it having no rules block either —
it is written/read only server-side via the Admin SDK, which does not need a declared
index the way a rules-gated client query would).

---

## 4 · Firestore rules — match block headers (quoted, ≤5 lines each)

`firebase-rules/firestore.rules` (repo root's `firestore.rules` does not exist — the
CLAUDE.md repo map's "root" reference resolves in practice to `firebase-rules/`):

```
match /documentTemplates/{docId} {
  allow read: if isSameSchool();
  allow create: if isStaff() && isSameSchoolWrite()
                && hasCapabilityLevel('Certificates', 'edit')
                && request.resource.data.status == 'draft';
```
— `firestore.rules:3158`

```
match /documentTemplateVersions/{docId} {
  allow read: if isSameSchool();
  allow create: if isStaff() && isSameSchoolWrite()
                && hasCapabilityLevel('Certificates', 'manage');
```
— `firestore.rules:3207`

```
match /reusableBlocks/{docId} {
  allow read: if isSameSchool();
  allow create, update: if isStaff() && isSameSchoolWrite()
```
— `firestore.rules:3218`

```
match /complianceAuthorities/{authorityId} {
  allow read: if isAuth() && tenantActive(request.auth.token.school_id);
  allow write: if false;
```
— `firestore.rules:3149`

**`templateSessions` has NO match block anywhere in `firestore.rules`.** It falls through
to the file's final catch-all (`firestore.rules`, last 5 lines): `match /{document=**} {
allow read, write: if false; }`. Not necessarily a defect — the panel writes it only via
the Admin SDK, which bypasses rules entirely — but it means the collection has zero
declared rules intent, unlike the other three, which is worth a security pass noting.

No `functions/` Cloud Function reads or writes any of these 4 collections (grepped;
zero hits beyond the RBAC module-name string).

---

## 5 · App-surface check (Q3)

Grepped both Android repos for `documentTemplates`, `documentTemplateVersions`,
`reusableBlocks`, `templateSessions`, `complianceAuthorities`, `doc_templates`,
`DocTemplate`, and `Certificate`, across all `*.kt` files.

- **Teacher app (`ZenXII_Teacher`): zero references to any of the searched terms.**
- **Parent app (`ZenXII_Parent`): zero references to the Document Engine.** One incidental
  hit — `SupportFirestoreRepository.kt:552` — is an unrelated support-ticket category
  label string (`"certificates" -> "Certificates & Documents"`), not a reference to this
  module's data or endpoints.

Clean absence, consistent with `document_targets.php`'s `wired => false` on every row and
`CON-NO_PRINT_IMPL`.

---

## 6 · Legacy `Certificates.php` controller (Q6)

`application/controllers/Certificates.php` (692 lines) is the **pre-Document-Engine**
implementation. Its own doc-comment (`Doc_templates.php:14-17`) calls it an "RTDB
prototype whose counter is read-increment-write ('best-effort atomicity' in its own
comment)". Confirmed from `Certificates.php` itself:

- **Data model**: Realtime Database, not Firestore — `Schools/{school}/{session}/Certificates/{Templates|Issued|Counters}` (`Certificates.php:12-17`), entirely separate from `documentTemplates`/`documentTemplateVersions`. **No data is shared between the two controllers** — different database (RTDB vs. Firestore), different key scheme, different collections.
- **Still routable**: yes — `routes.php:1234-1251`, 17 explicit routes (`certificates`, `certificates/templates`, `certificates/generate`, `certificates/issued`, plus 10 AJAX actions: `get_dashboard`, `get_classes`, `get_templates`, `save_template`, `delete_template`, `get_students`, `get_student_details`, `generate_certificate`, `get_issued`, `get_certificate`, `revoke_certificate`, `get_school_profile`).
- **RBAC**: reuses the SAME module key, `'Certificates'` (`Certificates.php:63` `require_permission('Certificates')`), as `Doc_templates.php` (`Doc_templates.php:32` `const MODULE = 'Certificates'`) — a single capability grade gates both the legacy issuer and the new designer.
- **Types**: hardcoded `CERT_TYPES = ['bonafide', 'transfer', 'character', 'custom']` (`Certificates.php:45`) — a fixed, much smaller list than the Document Engine's config-driven catalogue in `doc_types.php`.
- **No PHPUnit tests found** for `Certificates.php` (grepped `tests/` for `class Certificates` / `CertificatesTest` — zero hits), unlike the 16-file `Doc*Test` suite for the new engine.
- **Navigation**: `application/views/include/header.php:666-669` links the sidebar's Certificates menu to the LEGACY controller only (`certificates`, `certificates/templates`, `certificates/generate`, `certificates/issued`) — see §7.

---

## 7 · Navigation / entry points (Q5)

- **Legacy `Certificates.php` IS in the sidebar** — `application/views/include/header.php:666-669`, 4 links (Dashboard/Templates/Generate/Issued).
- **`Doc_templates.php` (the new Document Engine) has NO sidebar or in-panel link anywhere.** Grepped every `application/views/*.php` for the literal string `doc_templates` outside its own view/controller/routes files — zero hits (only matches are application log files, not view code). It is reachable **only by typing the URL directly** (`/doc_templates`, `/doc_templates/gallery/{type}`, `/doc_templates/design/{id}`).
- No other module's view links to either controller.

---

## 8 · Cross-module consumers (Q2 continued)

- **`Doc_resolver.php`** is a deliberately read-only seam built FOR future cross-module use (its own doc-comment: "So that Accounts, Students, Staff and Payroll can be built against a stable question... before the answer can ever be 'here it is'"), enforced by `DocResolverTest` asserting no issue/render-named method exists on the class.
- **Grepped `application/` for every controller (outside `Doc_*`) referencing `Doc_resolver`, `documentTemplates`, `documentTemplateVersions`, `reusableBlocks`, `templateSessions`, or `complianceAuthorities`: zero hits.** Nothing currently calls the seam. `document_targets.php` names 8 intended future callers (`SIS` ×4 rows including a wildcard, `Fees` ×2, `HR` ×2) — all `wired => false`.

---

## Counts summary

| Item | Count |
|---|---|
| Public controller methods on `Doc_templates.php` (page loads + AJAX) | 27 (3 page loads + 24 AJAX) |
| AJAX endpoints with an explicit `routes.php` entry | 17 of 24 |
| AJAX endpoints relying on CI3 default routing (no explicit route found) | 7 (`get_versions`, `version_pdf`, `presence`, `leave`, `duplicate`, `deactivate`, `delete`) |
| Endpoints with zero client caller in `designer.js` | 2 with no `srv` wrapper at all (`preview`, `save_block`); 3 more with an `srv` wrapper defined but never called (`get_blocks`, `validate`, `archive`) — 5 of 24 AJAX endpoints unreachable from the shipped UI code |
| `Doc_*` PHP libraries | 9 |
| Config files | 2 (`doc_types.php`, `document_targets.php`) |
| Firestore collections owned | 4 (`documentTemplates`, `documentTemplateVersions`, `reusableBlocks`, `templateSessions`) |
| Firestore collections read-only (not owned) | 2 (`schools`, `complianceAuthorities`) |
| Firestore rules match blocks for owned/adjacent collections | 4 found; 1 owned collection (`templateSessions`) has none (catch-all deny) |
| Firestore composite indexes referencing these collections | 7, across 3 collection groups (none for `templateSessions`) |
| Cloud Functions specific to this module | 0 |
| Print-point registry rows (`document_targets.php`) | 8, all `wired => false` |
| PHPUnit test files | 16 (`tests/Unit/Doc*.php`) |
| Jest `describe()` blocks (`document_engine.test.js`) | 9 |
| Browser/manual harness files (`tests/doctemplates/`, non-PHPUnit) | 6 files + 3 golden HTML fixtures |
| Legacy `Certificates.php` routes | 17 total route lines (4 page/tab routes: `certificates`, `/templates`, `/generate`, `/issued`; 13 AJAX action routes) |
| Legacy `Certificates.php` PHPUnit tests | 0 |
| Teacher app references to module data | 0 |
| Parent app references to module data | 0 (1 unrelated string match excluded) |
| Sidebar links to `Doc_templates.php` | 0 |
| Sidebar links to legacy `Certificates.php` | 4 |

---

## Named gaps / areas not examined this pass

- **`reusableBlocks` document key shape** not traced into `Doc_block_service.php` body — only the collection constant was confirmed. `[UNKNOWN]`.
- **CI3 default-routing fallback for the 7 endpoints missing explicit routes** is asserted from static framework knowledge, not exercised — a runtime-capable agent (A4/A2) should hit these URLs directly to confirm they 200/401 rather than 404.
- **`Doc_contract.php`'s catalogue vs. `doc_types.php` vs. `designer.js`'s own client-side copy** — three-way duplication noted via doc-comments and flagged in `_mission.md` as A7's mandate; not independently re-verified line-by-line here.
- **Cloud Functions triggers on Firestore writes to `documentTemplates`/`documentTemplateVersions`** (e.g. an `onWrite`/`onCreate` trigger rather than a callable) were not separately searched by trigger syntax (`.document(...)`) — only by collection-name string grep. If a trigger exists under a different literal (e.g. built from a config constant), it would not have been caught. Confidence: high but not absolute.
- **`document_targets.php` row-by-row schema** (permission gate, entity type per row) was sampled, not fully enumerated line-by-line.
- Did not examine `blueprints/certificates/*.md` content in depth — inventoried as artefacts only, not read for architectural claims (that is prior-session material, explicitly untrusted per `_mission.md`).
