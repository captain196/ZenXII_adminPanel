# 05 · Risk register — Document Engine (Certificates) — POST-ADVERSARY

**Agent: A11 · ADVERSARY. Evidence ceiling E2** (static source read; two new exploit chains
below are traced to a complete, citable, deterministic call sequence — not executed, no
network request was made, nothing was written to any store). **This document OVERWRITES the
stale run-1 `05-risk-register.md`**, which cited findings (`Sis.php::issue_tc()`, a
document-only-TC design risk, `Fee_firestore_txn` counters) that do not exist anywhere in
this run's artefacts and predate the module's current shape. Do not carry its R-numbers
forward.

**Ceiling notice, per the mandate: I cannot execute anything. Every row below is authored,
not tested. Two rows are new exploit chains built entirely from citations already on record
plus two files I read directly to close the chain (`Doc_templates.php:820-886`,
`Doc_template_service.php:360-484`) — they are AS CONFIRMED AS AN E2 STATIC TRACE CAN BE, but
none of it is proven to occur on a live server. `PASS` appears nowhere in this document.**

---

## OVERTURNED — stated first, loudly

### OV1 · The frozen certificate PDF for an ALREADY-PUBLISHED version can be overwritten in place, by an `edit`-grade actor, with no `manage` grade and no race

This directly answers the question `_arbitration.md` posed and left open: *"Can `proof_pdf()`
overwrite the file for an already-published version? Trace the filename construction."*
**Yes, deterministically, no timing window required.**

**The chain, each link independently cited:**

1. `Doc_template_service::save()`'s strip list is `['status','publishedVersion',
   'activeVersion','templateId','schoolId','updatedBy','createdBy']`
   (`Doc_template_service.php:335-339`, read directly this pass) — **`version` is not in it.**
   This is the already-catalogued finding I-10 / A6-N9 (`02-state-machine.md` §4,
   `_arbitration.md` provisional register). An `edit`-grade caller can therefore
   `save(docId, {version: 6}, lockVersion)` on a template whose `publishedVersion` is already
   `6`, resetting the head's mutable `version` field back to an already-frozen number. Nothing
   checks the new value against `publishedVersion`.
2. The same `save()` call can carry arbitrary edited `page`/`header`/`footer`/`objects` in the
   same patch — `save()` applies no shape/content validation to these fields
   (`01b-backend-spec.md` §7, re-confirmed: only lifecycle keys are stripped).
3. `Doc_templates::proof_pdf()` (`edit`-graded, `Doc_templates.php:820-886`, read in full this
   pass) reads the **live head**, not any frozen snapshot (`:826`), takes the filename version
   number directly from that live head — `$version = (int) ($tpl['version'] ?? 1);`
   (`:839`) — with **no comparison anywhere in the method against `publishedVersion` or
   `activeVersion`**, and writes: `$file = $dir.'/'.basename($id).'_v'.$version.'_'.$safeLang.'.pdf';`
   then `file_put_contents($file, $pdf)` (`:857-860`) — **unconditionally**, no existence
   check, no "is this version already published" guard, no `[UNKNOWN]` left in this trace: the
   full method body was read and no such check exists anywhere in it.
4. Because step 1 set `version` back to `6`, this write lands at the **exact same path**
   already recorded in the immutable `documentTemplateVersions/{id}_v6` snapshot's
   `proofPdfPaths` field (`Doc_template_service.php:544-545`, `Doc_templates.php:862`,
   identical naming-convention construction independently confirmed at `:471` for the
   fallback path). **The file is overwritten with content the attacker chose, rendered
   through the attacker's own edited design.**
5. `version_pdf` (`view`-graded — reachable by ANY staff, not just the actor,
   `Doc_templates.php:55`) subsequently `readfile()`s the tampered bytes for anyone who
   downloads "version 6" of this certificate, including anyone who trusted `_live-state.md`
   L7's own four-hash experiment as proof of durability.

**What survives and what does not.** The Firestore document
`documentTemplateVersions/{id}_v6` itself is untouched — its `objects` field is still exactly
what was frozen at publish time, and A5/A6's structural proof that nothing resolves a
mutable reference at render time (`03-invariants.md` N3) is **not contradicted** by this
attack. What is defeated is the thing a human actually receives: **QA-LEAD's own arbitration
note anticipated this precisely** — *"if that FILE is overwritten, the snapshot is honest
and the artefact still changed"* — and this pass proves it is reachable, not merely
theoretical, and reachable by `edit` grade alone, not `manage`.

**Ruling on U1: DOWNGRADED.** The Firestore-document-level immutability claim (U1's literal
text, and N2/N3's "a published version is never rewritten") is **UPHELD** — no code path
writes to `documentTemplateVersions` outside `publish()`'s single create-only `set()`, and I
found none either, having re-read `Doc_template_service.php:360-484` and `Doc_templates.php`
in full. The broader, human-relevant claim — "the artefact a school actually holds for an
issued certificate cannot be silently changed" — is **OVERTURNED**: it can, via a documented,
citable, two-call, no-race, `edit`-grade sequence.

**Severity: rated P1, with an explicit recommendation to QA-LEAD to consider P0.** Same-tenant,
no manage grade required, defeats a `manage`-graded, "legally consequential" guarantee (the
module's own vocabulary, `Doc_templates.php:983-986`) using only `edit` grade — this is an
RBAC privilege-boundary violation as much as an integrity one: an `edit`-grade actor can
retroactively undo what required `manage` grade to establish. It is contained today only by
`CON-NO_PRINT_IMPL` (nothing prints from this build) — but `version_pdf` downloads already
happen today (QA-LEAD used them for L7), so a school or auditor who already treats a
downloaded "v6 certificate" as the historical record is exposed **now**, not only once the
print seam is wired.

**Compounding note on I-10's own severity.** The unstripped `version` field was previously
the *only known escape* from the STRANDED `publish()` state (`02-state-machine.md` §6). This
pass shows the same field is also the **enabling primitive for OV1**. A blind fix (add
`version` to the strip list) closes OV1 but **removes the only known STRANDED-recovery path**
and must therefore ship together with a real repair tool for R-2/STRANDED, not as a bare
strip-list edit.

---

### OV2 · The `docType`/state-gate bypass survives `publish()` and is frozen permanently — the proof gate does not defend the dimension the business rule cares about

This attacks N4 ("the proof gate cannot be defeated") directly and extends the already-known
L9/I-11 `docType`-via-`save()` bypass past `01b-backend-spec.md` §6's own unresolved caveat:
*"not verified this pass whether every combination of old/new contract key sets would
actually be caught"* by `validate()`. **It is not caught — because `publish()` never calls
`validate()` at all, and the hash it does check does not cover `docType`.**

**The chain:**

1. `Doc_template_service::contentHash()` (`:372-382`, read in full this pass) hashes exactly
   six fields: `page, header, footer, objects, languages, defaultLanguage`.
   **`docType` is not one of them.** This is the exact field list `01b-backend-spec.md` §6
   already documented for the *content* it protects — this pass adds that the field list is
   also, by omission, everything the gate does **not** protect.
2. `create()` a `custom:qa_probe` template (always permitted, `Doc_contract::isCustom()`
   short-circuits state gating — `01b-backend-spec.md` §8). Design it. Call `proof_pdf()`:
   this renders and records `lastProof.contentHash = contentHash(head)` for the current
   design, under `docType = "custom:qa_probe"`.
3. Call `save(id, {docType: "study"}, lockVersion)` **without touching any of the six hashed
   fields.** `_safe_type()` — the fail-closed state-gate — is invoked only from `create()`
   and `index()`/`design()` (`Doc_templates.php:164,606`), never `save()`
   (`01b-backend-spec.md` §6/§8, re-confirmed). The write lands unconditionally. **Because
   `docType` is outside `contentHash()`'s field list, `contentHash(head)` is numerically
   IDENTICAL before and after this write.**
4. Call `publish()`. Read in full this pass (`Doc_template_service.php:481-568`). Its checks,
   in order: proof exists (`:488`); `proof.version === head.version` (`:499`) — unaffected,
   `save()` never touched `version` in this scenario; `proof.contentHash === contentHash(head)`
   (`:508`) — **still matches**, because step 3's mutation is invisible to the hash. **The
   gate passes.**
5. The snapshot written to the immutable `documentTemplateVersions` collection carries
   `'docType' => $head['docType'] ?? ''` (`:527`, read directly) — **`"study"`**, an
   Andhra-Pradesh-only statutory type this Madhya Pradesh school (per `_live-state.md`'s own
   observed tenant) was never entitled to create, now **frozen, create-only, permanent**, in
   the one collection this module's entire design treats as the unforgeable historical
   record.

**Ruling on N4 ("the proof gate cannot be defeated"): OVERTURNED for identity/authorization
tampering, UPHELD for content tampering.** The gate is exactly as sound as A4/A8 described
for the question they asked ("can the hash be supplied or influenced by the client, can a
mismatched design publish under a stale proof") — genuinely solid, independently re-traced
this pass and found sound on that axis. It was never asked, and does not survive being asked,
"does the gate protect every field the snapshot freezes, or only the ones it happens to
hash." `docType` is frozen by `publish()` (`:527`) but not defended by the one control whose
entire job is deciding what may become permanent.

**Severity: escalates L9/I-11 from P2 to P1, effective immediately, not only "the moment the
print seam is wired."** `_live-state.md` L9 and `_arbitration.md`'s provisional register both
rated the `docType` bypass P2 specifically *because* "nothing issues from a template today."
That containment argument assumed the worst case was a mislabeled **draft** — reversible,
inert. This chain shows the worst case is a mislabeled **published, immutable, undeletable**
snapshot (`delete()` refuses once `publishedVersion≠null`, `Doc_template_service.php:799-806`)
that **cannot be corrected by any code path found** — there is no "unpublish" or "amend a
published version" method anywhere in the 27 public controller methods. The exposure is
therefore not deferred to a future wiring event; it exists in the current build's permanent
storage today.

---

## Attack verdict table — the five load-bearing claims

| # | Claim | Verdict | One-line reason |
|---|---|---|---|
| 1 | "Version snapshots are immutable AND self-contained" (U1, A6 self-containment) | **DOWNGRADED** | Firestore-document immutability and object self-containment (no live block pointer) both hold, re-verified by direct read of `Doc_template_service.php:481-568`, `Doc_serializer.php`'s closed type-switch citation carried forward. The human-facing artefact guarantee is defeated by **OV1** — the on-disk PDF a published version's own snapshot points to is overwritable via the unstripped `version` field, no race required. |
| 2 | "Tenant isolation is clean; 15/15 endpoints checked" | **UPHELD for confirmed IDOR (0 found); DOWNGRADED from "clean" to "unresolved on read paths, and escalated on one write path"** | Re-traced `head()`, `get_template`, `get_versions`, `version_pdf`'s containment logic — no cross-tenant read/write defeat found beyond what A8 already logged. The live probe genuinely cannot distinguish "tenant check fired" from "doc absent" (§1c, unresolved — a real second-tenant fixture is still required). `save_block`'s TOCTOU (§1b) is re-rated **P3→P2**: it is not just a cross-tenant race, it is a **silent lost-update where BOTH racing callers receive HTTP 200** (see AR-NEW-2 below) — a materially worse shape than "narrow race, currently UI-dead" conveys. |
| 3 | "The proof gate cannot be defeated" (N4) | **OVERTURNED for `docType`/authorization tampering (OV2); UPHELD for content tampering** | See OV2. `contentHash()`'s own field list, read directly, omits `docType`; `publish()` never calls `validate()`/`validateBundle()` (confirmed independently, matching `04-parity-matrix.md` Axis 4's own finding); the snapshot copies `docType` from the live head unchecked. |
| 4 | "No document is issued from this build" (`CON-NO_PRINT_IMPL`, A7) | **UPHELD, narrowly — no code path crosses from the Document Engine into legacy issuance, shared numbering, or shared storage** | Independently re-checked: different database (RTDB vs. Firestore), different key scheme, zero shared collections (`00-dependency-graph.md` §6, `04-parity-matrix.md` Axis 2, both re-read this pass). **New, not previously stated this precisely**: the two systems share one RBAC key (`'Certificates'`) and have **zero numbering coordination** — the legacy controller's own read-increment-write counter (`Certificates.php:443-447`) and the Document Engine's complete absence of any counter (N12, `03-invariants.md`) are mutually unaware. The boundary holds today; the day the print seam is wired, two independently-numbered "official" certificate systems under one capability grant is a live collision risk nobody has designed against — recorded as a T0 row, not as a defeat of A7's claim. |
| 5a | "Phantom success is not present" (N2) | **UPHELD, narrowly** | Re-confirmed `api()`'s `r.ok` + `status!=='error'` gate and the exhaustive absence of a bypassing `fetch`/`XMLHttpRequest` call — the *specific* bug class ("denied/failed action reported as success via a client that doesn't check the response") genuinely does not occur. A **sibling, not identical**, class does: see AR-NEW-2 — `save_block`'s loser gets a genuine, honest HTTP 200 for a write that was then silently overwritten by someone else's write to the same id. Not phantom success in N2's tested sense (nothing was denied or failed); it is a silent lost-update wearing a success response, and it hits a collection N2's own audit scope (`designer.js`'s `api()` wrapper) never reaches, because `save_block` has zero client callers and was tested only server-side. |
| 5b | "The audit-actor pattern does not hit" (N1) | **UPHELD for `documentTemplates`; narrowed elsewhere** | QA-LEAD's live E3 check (0 of 6 edited docs lack `updatedBy`) stands, re-read and not contradicted. `Doc_block_service::save()` (`:94-129`, read this pass) also captures `$by` into `updatedBy` on every write — the mechanism looks sound by inspection — but **this was never independently checked against a live `reusableBlocks` population by any agent**, and `save_block` is only reachable via direct HTTP (zero UI callers), meaning if it were ever hit without a caller-supplied actor, nothing would notice. Recorded as an `[UNKNOWN]`, not an overturn. |

---

## Consolidated risk register

Ranked by (consequence × reachability × silence), per the module's own stated grading logic
in the prior run. *Silence* is weighted heavily: this module's stated purpose is a legal
record, and the failures that matter are the ones nobody notices.

| ID | Risk | Sev | Likelihood | Blast radius | Invariant at risk | Evidence + citation | Status after attack |
|---|---|---|---|---|---|---|---|
| **AR1** (=OV1) | Published-version proof PDF is overwritable in place via `save()`'s unstripped `version` field + `proof_pdf()`'s unguarded filename write | **P1 (recommend P0 review)** | High for a technical `edit`-grade insider — no race, no `manage` grade, two ordinary POSTs | Any published, immutable-in-name certificate PDF in the acting school's tenant; every downstream consumer of `version_pdf` (today: `_live-state.md`'s own trust experiment; tomorrow: the print seam) | U1 (immutability), N2 (never rewritten), N5/N5a (published = protected) | `Doc_template_service.php:335-339,514-522`; `Doc_templates.php:826,839,857-862` — all read directly this pass | **OVERTURNED at the artefact level** — new, unmitigated |
| **AR2** (=OV2) | `docType` state-gate bypass survives `publish()` and freezes permanently, undetected by the proof gate | **P1** (escalated from P2) | Deterministic, single `edit`-grade actor, no timing dependency | The permanent version-history record for a `(school,docType)` this school was never entitled to; compounds with `CON-NO_PRINT_IMPL` lifting the moment any print point is wired | N4 (proof gate), N9 (strip-list should be exhaustive), the module's own state-gating design (`Doc_contract::typeAvailable`) | `Doc_template_service.php:372-382,497-527`; `Doc_templates.php:1095-1114,606` | **OVERTURNED (N4's scope)** — escalates existing L9/I-11 |
| **AR3** | `publish()`'s two writes are non-atomic; partial failure permanently strands a template, with no designed recovery (I-10's `version`-bump escape is the only one, and closing AR1 removes it) | **P1** | Requires a real network/process failure between two sequential Firestore calls — not user-triggerable at will, but not rare at cross-region latency either | The specific template stuck; blocks every future publish of it forever via any designed path | Availability of the publish lifecycle | `Doc_template_service.php:514-522,551,563` (U2, re-confirmed) | **UPHELD, and now entangled with AR1's fix** — any fix must ship a real repair tool, not a bare strip-list edit |
| **AR4** | `save_block` cross-tenant TOCTOU is also a silent lost-update: BOTH racing callers receive HTTP 200 | **P2** (raised from P3) | Narrow (needs a simultaneous identical `blockId` from two schools) but realistically reachable by an automated, patient direct-HTTP actor targeting common names (`letterhead`, `seal`, `signature`) — not purely random-collision as originally framed | A shared letterhead/seal/signature block silently reassigned to a different school's tenant, with the true owner's client showing "Saved" | Tenant isolation on write; the "a write that succeeds should not silently vanish" contract this module's own `save()`/lockVersion mechanism honours everywhere else | `Doc_block_service.php:94-105` (no CAS, no lockVersion equivalent) | **UPHELD and sharpened** — matches `_patterns.md`'s "missing lock+CAS" pattern exactly, with a "reported success but lost" twist `_patterns.md`'s phantom-success entry does not cover |
| **AR5** | `version` field DoS via `get_versions()` — same unstripped field as AR1, different consequence | **P2** | Deterministic once `version` is inflated and a publish occurs (by the same or any colleague) | Any `view`-grade viewer of that template's history — third-party blast radius, not just the actor | Availability of `get_versions`; cost | `Doc_templates.php:381-421`, `Doc_template_service.php:497` (A8-3, re-confirmed, not independently re-derived beyond citation check) | UPHELD, unattacked — genuinely solid trace |
| **AR6** | CSS injection into `style=` attributes defeats `guardImages()`'s SSRF guard entirely | **P1 (P0 if mPDF dereferences — untested, H4)** | Deterministic given an `edit`-grade actor; consequence severity gated on an unresolved runtime fact | Production Ohio host's egress reachability; AWS metadata endpoint if reachable | The one control (`guardImages()`) purpose-built against exactly this threat | `Doc_serializer.php:474,477,858,872`; `Doc_renderer.php:391` (L13, re-confirmed, not independently re-derived) | UPHELD, unattacked — the single highest-value untested test in the whole program, per QA-LEAD's own framing |
| **AR7** | Published templates can never be removed from the gallery by any user action (`archive` has zero UI call sites; `delete` correctly refuses) | **P2** | Certain, already reproduced live (`_live-state.md` L3/L4) | Every ever-published template, forever, in every school | N5a, R-1 | `designer.js` grep zero hits outside definition; `Doc_templates.php`'s own refusal copy names the remedy it does not provide | UPHELD |
| **AR8** | Archived-but-published templates can be silently reactivated (`activate()` never checks `status`) — the mirror guard added to `archive()` was not added to `activate()` | **P2** | Certain given an archived+published template exists and someone calls `activate()` on it | The one hybrid state (`archived`+`activeVersion≠null`) nothing in the module expects or exits | N10 | `Doc_template_service.php:600-609` (I-9, re-confirmed) | UPHELD |
| **AR9** | Archived templates are never filtered from the gallery client-side, contradicting the product's own refusal-message promise | **P2** | Certain | Every school, every archived template | N11 | `designer.js:5606-5621,1623,2580`; `Doc_templates.php:328-344` (I-12, re-confirmed) | UPHELD |
| **AR10** | The legacy `Certificates.php` issues real, student-identified documents today, from the sidebar, zero tests, sharing the `Certificates` RBAC key with the untested Document Engine, and the two systems have **zero numbering coordination** | **P1** | Certain — it is live, in production, in the sidebar | Every school using the legacy issuer; every future school onboarded onto the Document Engine's print seam without a numbering reconciliation plan | Ecosystem-level: one legal-record system should not silently duplicate certificate serials with another | `Certificates.php:402-527,443-447`; `Doc_resolver.php:48-64` (A7, re-confirmed; numbering-collision framing new this pass) | UPHELD + new compound framing (see verdict table row 4) |
| **AR11** | Modal focus bleed — destructive canvas shortcuts (`⌫`/Backspace object-delete, `⌘A`, `⌘D`, `⌘Z`) remain live under every confirmation dialog except `askName` | **P2** | High — any confirmation dialog opened while a canvas object is selected | Silent, unconfirmed object deletion while a user believes they are answering an unrelated "are you sure" prompt; compounds with `S.undo` resetting on every load/create (no cross-session undo) | UX safety contract of "a confirmation dialog blocks unrelated destructive input" | `designer.js:4936-4941` (no focus management, no `role="dialog"`), `:4536-4615` (global keydown does not gate on `#scrim`) (05c-ux.md §4, re-confirmed) | UPHELD |
| **AR12** | `create()`'s numbering scan and `save_block`'s TOCTOU share the same read-then-write-with-no-precondition root cause as `save()`'s lockVersion check — C1's "code cannot settle exploitability under concurrency" undersells the danger | **P1 pending → substantiated for the sequential case** | AR1/AR2 above are **fully deterministic, single-actor** exploits of the identical missing-CAS architecture C1 flagged as merely "contested." No race is needed for the worst consequences. | Whole-module — this is the module's one structural weakness, hit four separate ways (`create()` numbering, `save()` lockVersion, `save_block`, and now AR1/AR2's field-level abuse of the same gap) | C1 (`save`/`create` CAS claimed, not implemented) | `01b-backend-spec.md` §4-5 (re-cited); AR1/AR2 above (new) | C1 **should not remain "contested pending runtime"** — the sequential, non-concurrent exploitability is now proven at E2 for two of its instances |

---

## Named residual `[UNKNOWN]`s this attack could not close

- Whether `proof_pdf()`'s overwrite (AR1) has ever actually happened on the live server — this
  pass is a static trace of a fully-specified, reproducible sequence, not a runtime
  reproduction. The recommended UAT row (AR-adversary-rows M1-08) would settle it in minutes
  against a disposable draft.
- Whether a second real tenant's template id would show identical refusal behaviour to a
  truly-absent one on every endpoint (`_live-state.md`'s own standing gap, not narrowed by
  this pass — still needs H1/H2).
- Whether mPDF actually dereferences a `background:url(...)` inside CSS it processes (AR6/H4)
  — deliberately not tested by any agent this run; remains the single highest-value untested
  row.
- Whether the legacy `Certificates.php`'s counter and the Document Engine's (nonexistent)
  counter would ever be reconciled by a future numbering design — a product question (AR10),
  not something code can answer.
