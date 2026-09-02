# 01 · Surface behaviour — Document Engine (Certificates)

**Evidence ceiling: E2 (source-read).** No runtime claims. Nothing here is PASS.

## Admin panel — the only surface the module exists on

**Navigation.** One view (`views/doc_templates/index.php`) hosting three screens
switched client-side: D0 document types → D1 gallery → D2 designer. Breadcrumb
is the only back-navigation. Routes `/doc_templates`, `/doc_templates/gallery/{type}`,
`/doc_templates/design/{id}` all render the same shell; `design/{id}` deep-links
via the `templateId` in the boot payload.

**Permissions.** RBAC module key `Certificates`, graded `view | edit | manage`,
enforced centrally in `_remap()`. An endpoint absent from `CAPABILITIES` is
**refused**, not defaulted — fail-closed. Reads are `view`; authoring is `edit`;
publish/activate/archive are `manage`.

**Endpoints — 14, all wired.**

| Endpoint | Cap | Notes |
|---|---|---|
| `get_types` | view | config-driven catalogue + one keyed school read |
| `get_templates` `get_template` `get_blocks` | view | `get_template` re-checks tenant in PHP (Admin SDK bypasses rules) |
| `create` | edit | server mints id create-only with retry; strips identity/lifecycle keys from the seed |
| `save` | edit | requires caller's `lockVersion`; a missing one is an error, never "wins" |
| `validate` | edit | authoritative; the client's copy is for live feedback only |
| `preview` | edit | same serializer as the PDF path — no second preview renderer |
| `proof_pdf` | edit | reads the template **from the store**; renders every declared language at p95; records the proof |
| `upload_asset` | edit | content-sniffed type; stored under its own content hash |
| `save_block` | edit | `schoolId` forced from session |
| `publish` `activate` `archive` | manage | audited `*_attempt` before the work |

**Client.** `designer.js` boots from `#zxdt-boot`; without it, runs OFFLINE on
built-in fixtures and says so in the console. Autosave debounced 1.5 s, never
while a modal is open; `beforeunload` guards the gap. All traffic through
`api()`, which throws unless `r.ok` **and** the body parses **and**
`status !== 'error'`.

**States the UI must express:** loading, empty (no templates), offline-harness,
save conflict (409), proof failed, publish blocked (no proof / stale proof),
nothing-active-for-this-type. *Whether each is actually rendered is a UAT
question, not a source question.*

## Teacher app — ABSENT

No certificate or document feature. `documentTemplates`, `documentTemplateVersions`,
`issuedDocuments`, `complianceAuthorities`: **not referenced anywhere in source**,
and absent from `object Firestore` in `util/Constants.kt`. The only "TC" hits are
a *student status* enum (`StudentRepository.kt:37,54`) used to filter withdrawn
students out of rosters — unrelated.

No fee-receipt capability either: `ui/fees/FeesTeacherScreen.kt` has zero
`receipt`/`pdf` matches.

Present and reusable: `util/AttachmentUrlValidator.kt` — allowlists
`firebasestorage.googleapis.com`, `https` only, then `ACTION_VIEW`. Its own
doc-comment defers inline PDF rendering and DownloadManager, so there is no
in-app PDF viewer; it hands off to an installed app.

## Parent app — ABSENT for documents, but **PRESENT for receipts**

No certificate/document feature and no Documents route
(`ui/navigation/NavGraph.kt:122-213`). No RBAC at all — by design; parents are
not staff, so there is no `ModuleGate.kt`.

**What does exist, and matters:** a complete fee-receipt document pipeline.
`Route.ReceiptDetail` (`receipt_detail/{receiptId}`, NavGraph.kt:213) →
`ui/fees/ReceiptDetailScreen.kt` → `util/ReceiptPdfGenerator.kt`, which builds a
PDF **on the device** with `android.graphics.pdf.PdfDocument`, shares via
`FileProvider` and saves to `Downloads/ZenXii/` via `MediaStore`. Backing model
`data/model/firestore/FeeReceiptDoc.kt`. English-only by deliberate choice,
because receipts must match paper and UPI records.

Certificate-adjacent, and currently the real process: Support Desk ticket
category `certificates` → "Certificates & Documents"
(`SupportFirestoreRepository.kt:80,508`). Parents request certificates by
raising a ticket that a human handles offline.

## The cross-surface fact that shapes everything

The Document Engine exists on **one** surface and issues **nothing**. Every
other surface either has no document capability at all, or — in the single case
of the Parent app's receipts — has one that predates the engine and does not
know it exists. See `04-parity-matrix.md` D1.
