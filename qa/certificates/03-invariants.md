# 03 · Invariant catalogue — Document Engine (Certificates)

**Agent: A6 · MODELLER. Evidence ceiling E2** for code-derived claims (two rows cite E3/E4
live evidence from `_live-state.md`, marked explicitly). "Enforced" means *the code contains
the check*, never that it has been observed holding under real concurrency. State-machine
context is `02-state-machine.md`, rewritten alongside this file — read that first for the
`(status, publishedVersion, activeVersion, version, lockVersion)` tuple model.

The ecosystem invariants in `qa/_global-invariants.md` are in force additionally (tenant
isolation, dual-emitted claims, session-scoped queries, `{schoolId}_{entityId}` keys, one
push door, graded RBAC, split staff PII, rules-as-boundary). Row **N7** below is this
module's specific instance of global invariant #10 ("rules are the only real boundary,
not what git shows") — worth reading together.

---

## Highest-value finding first: snapshot self-containment — **CONFIRMED, not a pointer**

A5 flagged this as `[UNKNOWN]` and the single highest-value open question: does a published
version's frozen `objects` array **embed** referenced content, or hold a **live pointer**
back into `reusableBlocks` that a later render would have to re-resolve? If the latter, a
block edit could silently change an already-published, already-issued certificate's
rendered output without touching any document this analysis otherwise covers — which would
be P0.

**Answer: self-contained. Not a pointer. `[CONFIRMED]`**

Three independent facts, each sufficient alone, together closing the question:

1. **`publish()`'s snapshot construction is a plain in-process PHP array copy, not a
   resolution step.** `$snapshot['snapshot'] = ['page'=>$head['page']??[], 'header'=>...,
   'objects'=>$head['objects']??[], ...]` (`Doc_template_service.php:529-536`) reads directly
   from the already-loaded `$head` and writes it straight to Firestore. There is no function
   call, no store lookup, nothing that could dereference an id against `reusableBlocks` in
   this path at all.
2. **The only code that ever interprets what an `objects` entry *means* is
   `Doc_serializer::inner()`, and its type switch is closed and fail-closed.**
   (`Doc_serializer.php:482-571`) recognises exactly six object types — `text`, `pageNumber`,
   `shape`, `image`, `qr`, `table` — and the switch's final line is
   `throw new RuntimeException("Doc_serializer: unknown object type '$type' on '{$o['id']}'")`
   (`:571`). There is no `block`/`blockRef` case, no field read anywhere in this method named
   `blockId`/`blockRef`, and an unrecognised type does not degrade or skip silently — it
   throws. A snapshot containing a genuine block-pointer object could not render at all,
   let alone silently re-resolve one.
3. **`Doc_renderer.php` never touches `objects`.** Grepped the full 428-line file for
   `objects`, `Doc_serializer`, `serialize` — zero hits. The renderer's job (mPDF setup, font
   registration, page geometry, `guardImages()`) is downstream of the HTML that
   `Doc_serializer` already produced; it has no independent object-walking capability that
   could resolve a block reference either.

**Corroborating, not load-bearing on its own:** the *entire* live block-linking mechanism —
`Doc_block_service::offersFor/acceptOffer/declineOffer`, the pin/offer model documented in
its own header comment (`Doc_block_service.php:11-27`) — is unreachable dead code with zero
controller wiring (`01c-data-spec.md` §11, `00-dependency-graph.md` §2) and, even if wired,
is broken by an unwrapped-envelope bug that would make `offersFor()` always return `[]` and
`acceptOffer()` always throw (`01c-data-spec.md` §11, `Doc_block_service.php:157-160,205-213`).
The client's own `BLOCKS`/`S.blockRefs` state is a **hardcoded, in-memory mock**
(`designer.js:456-462,872,2291,2440,2479`), never fetched from or written to
`reusableBlocks`. So even the *intended* design (block content is pinned/copied at design
time, per the header comment: "a template PINS the block version it was designed against...
publishing a new block version does NOT touch any template") is corroborated by the fact
that no live-pointer mechanism exists anywhere the data could flow through — not because the
feature was deliberately built and works, but because **nothing in this codebase, working or
broken, has ever had the capability to make a snapshot's content depend on `reusableBlocks`
at render time.** Whatever bytes sit in `objects` at the moment `publish()` runs are
categorically all that will ever render for that version, for as long as `Doc_serializer.php`
looks the way it does today.

**What is NOT proven by this:** that a *future* code change (e.g. finishing the offer/accept
wiring, or adding a 7th object type) couldn't introduce exactly the hazard A5 was worried
about. This is a statement about the current build, not a structural guarantee the schema
enforces going forward — there is no test (`DocSerializerTest`, `DocSerializerGoldenTest`,
per `00-dependency-graph.md` §1) asserting "no object type may reference an external mutable
collection" as a design rule; it holds today only because nobody has written the case that
would break it.

---

## Module invariants

| ID | Invariant | Enforcement point | Server/client | Strength | How it could be violated |
|---|---|---|---|---|---|
| **N1** | **Exactly one active template per `(school, docType)`.** | `activate()`'s single `commitBatch` names the winner AND nulls every other sibling's `activeVersion` in the same atomic write (`Doc_template_service.php:650-688,704`); refuses non-atomically if no `commit` callable exists (`:690-702`) | **Server**, genuinely atomic (`Firestore_rest_client.php:996-1059`, `:commit` REST endpoint) | Enforced, the one place in this module where atomicity is real, not conventional | Only by a writer that bypasses `Doc_template_service::activate()` entirely (a console edit, a future direct-client write once rules stop being inert — `qa/_global-invariants.md` #10) |
| **N1a** | Custom types (`custom:*`) trivially satisfy N1 because each one **mints its own `docType`** — `create()` requires a `docTitle` for any `Doc_contract::isCustom()` type (`Doc_template_service.php:187-192`) and the resulting `docType` string is derived from what was typed, so two custom templates for genuinely different purposes never share a `docType` and never contend for the same "exactly one active" slot. **This does NOT prevent two custom templates with the *same intended meaning* but differently-typed titles from each independently being "active"** — N1 holds per `docType` string, not per human-perceived purpose. | `Doc_contract::isCustom()` regex + `create()`'s title requirement | Server | Enforced for the literal invariant; a semantic gap, not a code gap | Two staff independently create `custom:fee_concession_letter` and `custom:fee concession` (different slugs) — both could be "active" simultaneously, and nothing detects the duplication |
| **N2** | **A published version is never rewritten.** | `publish()`'s `exists()` check refuses to overwrite (`Doc_template_service.php:517-522`); `firestore.rules:3207-3215` `allow update, delete: if false` (inert today, Admin SDK bypasses rules — N7) | **Server** (service-layer refusal is the one that actually runs) | Enforced | Only by a write that skips `Doc_template_service` entirely |
| **N3** | **Snapshot self-containment.** See above — answered first. | Structural absence of any block-resolution code path in `Doc_serializer.php`/`Doc_renderer.php` | Server (nothing to violate client-side; the client never renders the frozen snapshot at all — only `version_pdf`/`proof_pdf`, both server-rendered) | **Confirmed, not merely enforced-by-check** — there is no guard because there is no mechanism to guard against | A future object type or a wired `Doc_block_service` integration that resolves at render time |
| **N4** | **Nothing publishes without a server-rendered proof of the CURRENT design.** `publish()` checks, in order: a proof exists; `proof.version===head.version`; `proof.contentHash===contentHash(head)` **recomputed fresh**, never trusted from the stored record. | `Doc_template_service.php:487-513`; hash source is `Doc_templates::proof_pdf()`'s own rendered PDF bytes (`Doc_templates.php:864,873`), never client-supplied | **Server**, and the hash cannot be client-influenced — `proof_pdf()` takes no template body from the request, only a `templateId` (`Doc_templates.php:820-823`) | Enforced, traced end to end (`01b-backend-spec.md` §6) | Not found this pass — the one genuinely solid gate in the module |
| **N5** | **A template cannot be deleted once it has a published version.** | `delete()` refuses if `publishedVersion≠null` (`Doc_template_service.php:799-806`) | Server | Enforced for `delete()` specifically — **but see N5a** | — |
| **N5a** | **Corollary the module does not itself state: N5 does not mean a published template is safe from removal from view, or that it's easy to distinguish "protected because published" from "protected because still active."** `delete()`'s two guards (`activeVersion`, `publishedVersion`) are independent — a template can be non-active and published (deletable-if-not-for-N5, but archivable) with **no UI path to archive it** (`02-state-machine.md` §5 R-1) and no client-side hiding once archived (`02-state-machine.md` §4 I-12). | n/a — this is an absence, not a check | — | **Absence is the finding** | Gallery grows monotonically, forever, for every ever-published template — already flagged `01c-data-spec.md` §10, `_live-state.md` L3 |
| **N6** | **No document is issued from this build at all.** | `Doc_resolver.php` is structurally read-only — its store adapter wires only `get`/`query`, no `set`/`update`/`delete`/`commit` key exists at all (`Doc_resolver.php:48-64`); `document_targets.php`'s 8 rows are all `wired=>false` (`00-dependency-graph.md` §1,§9); zero controllers outside `Doc_*` reference any of this module's collections (`01b-backend-spec.md` §9) | Server, and **structurally** incapable, not merely unconfigured | Confirmed absence | N/A — this is `CON-NO_PRINT_IMPL`, the module's defining current-state constraint, not a defect |
| **N7** | **Tenant isolation on every read and write.** | PHP-layer checks inside `Doc_template_service::head()` (`:888-900`, whose own doc-comment says `save`/`publish`/`activate`/`archive` "used to have no tenant check at all" before a refactor) and `Doc_templates.php`'s explicit re-checks on `get_template`/`get_versions`/`version_pdf` (`:353,387,474-481`) | **Server, PHP-layer only.** `firestore.rules`'s four match blocks for this module (`documentTemplates`, `documentTemplateVersions`, `reusableBlocks`, `complianceAuthorities`) are **inert as enforcement today** — the panel writes via the Firebase Admin SDK, which is not subject to rules at all (`01c-data-spec.md` §8, rules file's own header: "TODAY NO CLIENT WRITES ANY OF THESE") | Enforced in the paths reviewed by A4/A8; **cross-tenant read isolation on `get_template`/`get_versions` was probed live and could NOT be proven** — the only foreign-id probe available was a *non-existent* id, so "Template not found" is consistent with either the tenant check firing or the doc simply being absent; distinguishing them needs a real second-tenant id (`_live-state.md`, Read-only boundary probes section) | `[CONFIRMED enforcement exists / UNKNOWN whether it's the thing actually stopping a live cross-tenant read]` — this is this module's direct instance of global invariant #1 |
| **N8** | **`templateSessions` (live-editor presence) has zero declared Firestore rules intent.** Falls through to the file's terminal catch-all `match /{document=**} { allow read, write: if false; }` — unlike the other three owned collections, which each have an explicit block. | n/a | Server, by omission | Not a defect today (Admin-SDK-only, same as N7's caveat) but **the one owned collection with no declared rules intent at all** | If a direct client ever touched Firestore, this collection is the one with no stated policy, not even a deny-by-name one — it inherits the file's global default rather than an intentional decision |

---

## New module invariants surfaced this pass (not in the prior `03-invariants.md`)

| ID | Invariant (as it should probably read) | Current enforcement | Status |
|---|---|---|---|
| **N9** | **A `save()` patch must not be able to move lifecycle-owned fields (`status`, `publishedVersion`, `activeVersion`, `templateId`, `schoolId`, `docType`, `version`) — the strip-list should be exhaustive.** | `Doc_template_service.php:336-339` strips 7 fields; **`docType` and `version` are both absent from that list.** `docType`: confirmed exploitable, E4-reproduced (`_live-state.md` L9). `version`: confirmed exploitable by this pass (`02-state-machine.md` §4 I-10), not previously reported. | **VIOLATED — two of seven intended lifecycle fields are unprotected**, same root cause, same pattern (`_patterns.md`'s "a guard exists on paper but isn't wired into every path that needs it"), hit twice in the same strip list |
| **N10** | **Archiving a template should make it unavailable for future activation** — the corollary of the 2026-09-03 fix that made archiving *refuse* while a template is active. | `archive()` now blocks archiving something active (`:843-850`) but **`activate()` never checks `status`**, so an archived-but-published template can still be reactivated (`02-state-machine.md` §4 I-9) | **VIOLATED** — the guard was added to one side of the transition pair and not the other |
| **N11** | **An archived template should no longer appear in the gallery listing**, as the product's own refusal message to the user promises ("Archive it instead — it disappears from the list"). | No `status` filter anywhere in `get_templates` (`Doc_templates.php:328-344`) or in the client's ingestion/render path (`designer.js:5606-5621,1623,2580`) | **VIOLATED** — confirmed by reading the full data path from server response to gallery render, zero exclusion found |

---

## Invariants declared but not yet enforceable (no issuance engine exists)

| ID | Invariant | Why not enforceable yet |
|---|---|---|
| N12 | A document serial is allocated once and never reallocated | No issuance engine — noted as a `note` on `document_targets.php:fee_receipt` |
| N13 | Distinct document types never share a numbering counter | Enforced only on the *registry* shape (`DocResolverTest`); no counter exists |
| N14 | A reprint is marked DUPLICATE | Serializer supports the mark; nothing issues, so nothing sets it |
| N15 | An issued document is reproducible from its frozen template | `Doc_resolver::activeVersion()` returns the snapshot correctly (proven, N3/N1), but no issued document ever records which snapshot it used, because none exist |

---

## Ecosystem invariants specifically at risk in this module (cross-reference to `qa/_global-invariants.md`)

- **#1 tenant isolation** — N7 above; partially unprovable live this pass.
- **#10 "rules are the only real boundary"** — this module is the *inverse* case: the rules
  exist and look correct, but are **inert** because the panel writes via the Admin SDK. The
  real boundary here is the PHP-layer checks in `Doc_template_service::head()`, which is a
  narrower and more recently-added surface (the class's own comment admits several lifecycle
  methods had *no* tenant check before a refactor). Anyone reasoning about this module by
  reading `firestore.rules` alone would draw the wrong conclusion about what's actually
  protecting it today.

---

## Counts

| Item | Count |
|---|---|
| Module invariants catalogued | 11 (N1-N8, with N1a/N5a as named sub-rows) |
| Of those, server-enforced | 7 fully (N1, N2, N4, N5, N6, and N7's PHP layer) |
| Of those, enforcement confirmed structurally absent-of-mechanism rather than checked (strongest form) | 1 (N3 — self-containment) |
| Of those, only client-side or absent entirely | 0 fully client-only; N5a/N8 are absences, not checks |
| New invariants surfaced this pass, currently VIOLATED | 3 (N9, N10, N11) |
| Declared-but-unenforceable (no issuance engine) | 4 (N12-N15) |
| `[UNKNOWN]`s carried into this file | 1 major (N7's live cross-tenant read proof — needs a second real tenant id, per `_live-state.md`) |

## Named `[UNKNOWN]`s

- **N7**: whether `get_template`/`get_versions`'s tenant check is what actually stops a
  cross-school read in production, or whether the probe available this pass (a non-existent
  foreign id) merely could not distinguish "refused" from "absent." Needs a second tenant's
  real template id or a seeded fixture — flagged in `_live-state.md` as a required H1/H2 row.
- Whether a future change to `Doc_serializer.php`'s object-type switch or a completed
  `Doc_block_service` wiring would reopen the N3 self-containment question — not a current
  violation, but the invariant is not schema-enforced going forward, only true of the
  present build (see N3's closing paragraph above).
