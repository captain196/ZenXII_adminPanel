# ARBITRATION LEDGER — Certificates · run 2

QA-LEAD rulings under §10. Resolution is by **evidence, never by vote**:
reproducibility > full implementation trace > partial trace > reasoning. Agent seniority
counts for nothing. Two-opinion rule: every P0/P1 needs a second agent's independent trace
with separate citations.

Status of the round: discovery cell (A1–A5) closed and arbitrated below. Analysis cell
(A6, A7, A8, A9, A10) in flight. A11 has not yet attacked anything — **every ruling here
is provisional until it survives A11.**

---

## UPHELD — independently verified, two or more separate traces

### U1 · Version snapshots hold frozen content; editing a draft cannot change what is live
- **A5**, three independent layers: `activate()` writes only `activeVersion`/`lockVersion`/`updatedAt`, never content (`Doc_template_service.php:660-664`); `Doc_resolver::activeVersion()` resolves `{headId}_v{n}` from the frozen collection (`Doc_resolver.php:143-152`); that collection is create-only at the service layer (`:517-522`) **and** in rules (`allow update, delete: if false`, `firestore.rules:3207-3215`); `save()` strips lifecycle keys and never writes to it (`:336-339,346`).
- **QA-LEAD**, E3 reproduced: four versions of the live active template rendered four byte-different PDFs (`_live-state.md` L7).
- **RULING: UPHELD.** The module's most dangerous available failure does not occur on this path.
- **Residual now CLOSED. A6 ruling: self-containment CONFIRMED**, by structural absence — the strongest available form. `publish()` freezes `objects` as a plain array copy (`Doc_template_service.php:529-536`); the only code that interprets an object entry, `Doc_serializer::inner()` (`Doc_serializer.php:482-571`), is a closed switch over six literal types that **throws** on anything else (`:571`); no `block`/`blockRef` type exists anywhere; `Doc_renderer` never touches `objects` at all. No mechanism — working or broken — could make a snapshot depend on live block content. Not schema-enforced for the future, but true of this build.
- **QA-LEAD correction to my own evidence (§5.5 discipline).** I described the four-PDF experiment as "four mPDF renders". A9 traced `version_pdf` and it is `readfile()` on a pre-rendered artefact (`Doc_templates.php:454-489`) — four file streams, not four renders. The experiment still proves a **distinct frozen artefact per version**; my account of the mechanism was wrong and is corrected in `_live-state.md` L7 rather than amended away.

### U2 · `publish()` is two non-atomic writes; partial failure permanently strands a template
- **A4**: `set()` at `:551`, `update()` at `:563`, no `commit`; the `exists()` guard at `:517-522` then blocks every retry forever.
- **A5**, independently: same two writes, and contrasts them with `activate()`, which *does* use `store['commit']`.
- **RULING: UPHELD, P1.** Two separate traces. The severity rests on there being no self-service recovery — the head never advances, so the next attempt computes the same version id and hits the same guard.
- Failure *mechanism* is E2-confirmed; failure *frequency* is unknown and unknowable from source.

### U3 · `archive` is unreachable, and the delete refusal names it as the remedy
- **QA-LEAD** E2 grep + E3 (hit the refusal live, then archived only via console).
- **A2** independently: `srv.archive` defined `designer.js:1007`, zero call sites; delete-dialog copy at `2705-2708` promises archiving.
- **A5** independently, from the data side.
- **RULING: UPHELD.** Severity contested between agents (A2 says P3, A5 frames it as a permanent gallery-growth problem). **QA-LEAD rules P2**: not P3, because the consequence is unbounded and unrecoverable through the product; not P1, because nothing is lost or exposed — the list merely grows.

### U4 · Manage-graded actions render for every user
- **QA-LEAD** E3: `SRV.can = {edit,manage}` arrives; only 2 call sites consult it.
- **A2** independently with its own citations: Publish (`2136`), Make live / Deactivate (`2600-2611`), post-publish Set-active offer (`5309-5322`) — all ungated; Delete (`2611-2615`) and Rollback (`5388`) correctly gated.
- **RULING: UPHELD, P2.** Not a security defect — A4 confirms the server fail-closes via `_remap()`. It is a permission-surfacing defect, and notably the module already established the correct pattern and applied it to two of five.

### U5 · The Document Engine has no navigation entry; the sidebar points at a legacy system
- **QA-LEAD** E3 from the rendered DOM; **A1** independently from `header.php:666-669` plus a whole-views grep returning zero hits for `doc_templates`.
- **RULING: UPHELD.** Severity deferred — it depends on the IS/SHOULD question below, which is not mine to settle.

---

## OVERTURNED BY A11 — and QA-LEAD's ruling on severity

### OV1 · U1 is DOWNGRADED. The artefact a human receives IS silently changeable.

A11 attacked U1 and broke the half that matters. **I verified all four links myself before
accepting it** (the reassuring half of U1 was my own published conclusion, so it was mine
to re-examine):

| Link | Verified |
|---|---|
| `save()` strip list is `[status, publishedVersion, activeVersion, templateId, schoolId, updatedBy, createdBy]` — **`version` absent** | ✔ `Doc_template_service.php:335-339` |
| `proof_pdf()` takes the filename version from the **live head**: `$version = (int)($tpl['version'] ?? 1)` | ✔ `Doc_templates.php:839` |
| The write is **unconditional** — `file_put_contents($file,$pdf)`, no existence check | ✔ `Doc_templates.php:857-860` |
| **Zero** occurrences of `publishedVersion`/`activeVersion` in `proof_pdf()`'s whole body | ✔ grep count = 0 across `:820-886` |

**The chain.** An `edit`-grade user calls `save(id, {version: 6, objects: <edited>}, lock)` on
a template whose `publishedVersion` is already 6 — nothing compares the new `version` to
`publishedVersion`. They then call `proof_pdf()`, which renders their edited design and
writes it to `…_v6_en.pdf`: **the exact path recorded inside the immutable v6 snapshot's
`proofPdfPaths`.** `version_pdf` — `view`-graded, reachable by any staff member — then
streams the tampered bytes to anyone downloading "version 6".

**What survives.** The Firestore document `documentTemplateVersions/{id}_v6` is untouched.
A5's and A6's structural proofs stand: no code writes to that collection outside
`publish()`, and nothing resolves a live reference at render time. **U1's literal text is
UPHELD; U1's human meaning is OVERTURNED.** My own arbitration note had anticipated the
shape — *"if that FILE is overwritten, the snapshot is honest and the artefact still
changed"* — and A11 proved it reachable rather than theoretical, and reachable at `edit`
grade rather than `manage`.

**QA-LEAD severity ruling: P0.** A11 rated it P1 and asked me to consider P0. I rule **P0**:
- §13 lists *"mutation of a locked period"* as P0. A published version is this module's
  locked record — the delete refusal says so in the product's own words: *"each one is the
  record of what a certificate issued from it actually said."*
- It is also an **authorization-boundary violation**: `edit` grade retroactively undoes what
  required `manage` grade to establish. Not merely an integrity bug.
- It is deterministic. No race, no timing window, two ordinary POSTs.

**Stated against my own ruling, so the human can disagree with full information:** nothing is
issued from this build, and what is overwritten is a proof render of a *template* using
sample data, not a named student's certificate. If you judge that the artefact carries no
authority until the print seam is wired, P1 is defensible. I do not, because the module
already treats it as the record and `version_pdf` downloads happen today — I used them myself.

**Fix constraint A11 surfaced, which must not be lost.** The unstripped `version` field is
*also* the only known escape from the STRANDED partial-publish state (`02-state-machine.md`
§6). Adding `version` to the strip list closes OV1 **and removes the only recovery path for
a stranded template.** The fix must ship together with a real repair tool, never as a bare
strip-list edit. This is exactly the blast-radius reasoning §17.2 demands.

### OV2 · The proof gate does not defend `docType`
`contentHash()`'s field list omits `docType`; `publish()` never calls `validateBundle()`; the
snapshot copies `docType` from the live head unchecked. So the L9 state-gate bypass I
reproduced **survives publication and freezes permanently** into version history.
A11 escalates L9 from P2 → **P1**. **UPHELD.** My earlier P2 rating assumed the bypass stayed
in mutable draft state; it does not.

---

## CONTESTED — code cannot settle these · promoted to the top of T0

### C1 · `save()` and `create()` claim compare-and-swap and do not implement it
`Doc_template_service.php:27-28` and `:162-176` both assert the guarantee. A4 traced the
implementation: read-then-compare-in-PHP-then-unconditional-write, with no Firestore
precondition available on `setDocument`/`updateDocument` (`Firestore_rest_client.php:1166,1236`)
while `commitBatch` (`:996-1047`) supports one and is used by `activate()` alone.
**The conflict between comment and code IS the finding** (§4) and is recorded as such rather
than resolved in favour of either. Exploitability under real concurrency is exactly what
source cannot answer. → T0.

### C2 · Which certificate system is authoritative?
Two live systems: legacy RTDB (`Certificates.php`, in the sidebar, 0 tests) and the
Document Engine (Firestore, no sidebar link, 16 test files). Same RBAC key. No shared data.
**Not a defect — a product decision that has never been recorded.** → H3, and it reframes
scope: if the legacy system issues documents, then this ecosystem issues certificates today
from an untested prototype, and `CON-NO_PRINT_IMPL` describes only half the truth.
A7 is answering the issue question. → T0.

### C3 · Two independent fee-receipt implementations
Parent app `ReceiptPdfGenerator.kt` (431 lines, native PDF, deliberately English-only) vs
the Document Engine `fee_receipt` type (mPDF, multilingual, itemised). No shared contract,
mutually unaware. Harmless while the seam is unwired; a divergence the day it is not. → H3.

---

## RULED — claims examined and NOT upheld

### N1 · "Audit trail with no actor identity" — pattern does NOT hit here
`_patterns.md` names the Certificate Designer as a historical source of this shape.
Checked against live data: of 6 templates changed after creation, **0** lack `updatedBy`;
of 5 whose version advanced, **0**. One document (`TPL0001`, the oldest) has a blank
`createdBy`. **RULING: the pattern was fixed and the fix holds.** Recorded so a future run
does not re-raise it — and so the crude form of the count (79 of 85 lacking `updatedBy`,
which merely reflects 79 never-edited templates) is not mistaken for evidence.

### N2 · Phantom success — not present
A2 audited every call site exhaustively. `api()` checks `r.ok` **and** `{status:'error'}`;
no call site bypasses it; the only non-`api()` network calls are a `sendBeacon` and two
`<a href>` PDF navigations, none of which report a success state.
**RULING: the codebase's most notorious defect shape is genuinely defended here.**

---

## Provisional severity register

| ID | Finding | Sev | Basis |
|---|---|---|---|
| U2 | `publish()` partial failure strands a template unrecoverably | **P1** | two traces |
| C1 | CAS claimed, not implemented (`save`, `create`) | **P1 pending** | one trace; needs A11 + runtime |
| L9 | `docType` bypass via `save()` — **reproduced** | **P2** | E4, QA-LEAD |
| U3 | archive unreachable → gallery grows forever | **P2** | three traces |
| U4 | manage actions rendered to all users | **P2** | two traces |
| L8 | failed school-context read degrades silently | **P2** | A2 + author admission |
| U5 | no navigation entry | **defer** | depends on C2 |
| L11 | frozen certificate PDFs live only on one server's local disk | **P1 pending** | QA-LEAD trace + runbook; needs H1 |
| A6-N9 | `save()` strip list also omits `version` — hand-patchable counter | **P2 pending** | one trace; same shape as L9 |
| A6-N10 | `activate()` never checks `status` — an archived template can be reactivated | **P2 pending** | one trace |
| A6-N11 | archived templates are never filtered from the gallery | **P2 pending** | one trace |
| A9-2 | `layoutPage()` full DOM rebuild + forced reflow on every keystroke, unthrottled | **P1 pending** | one trace |
| A9-1 | `get_templates` unpaginated/unprojected on every hub load | **P1 pending** | one trace + E3 measurement |
| **L13** | **CSS injection into the render `style` attribute → possible server-side SSRF** | **P1 (P0 if mPDF dereferences)** | A8 trace + QA-LEAD E3 reproduction |
| **A7-2** | **The LEGACY controller issues real certificates today, from the sidebar, untested** | **P1 + reframes scope** | A7 trace; needs 2nd opinion |
| L12 | client and server mint different custom-type ids for the same name | **P2** | A7 inference + QA-LEAD E3 execution |
| A8-3 | unstripped `version` → numbering corruption + `get_versions` read amplification | **P2** | one trace |
| A8-5 | uploaded crest/signature images served statically, protected only by hash obscurity | **P3** | one trace |
