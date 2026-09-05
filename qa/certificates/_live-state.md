# LIVE STATE OBSERVATION — captured by QA-LEAD, 2026-09-04

**Evidence level E3.** This is instrumented output I captured myself from the running
application against the live Firestore project, via the authenticated
`get_templates` endpoint in the browser. It is not UAT evidence and certifies no row.
It exists so analysis agents reason against the data that ACTUALLY EXISTS rather than
against the shape the code implies.

School under observation: `SCH_B56BB9A401` ("vikrant public schoo"), state
`madhya pradesh`, `affiliationBoard` **empty**.

## Population

| | |
|---|---|
| Template head documents | **85** |
| `transfer_certificate` | 71 |
| `bonafide` | 6 |
| `character` | 5 |
| `custom:*` | 3 (`sports_day_participation`, `fee_concession_letter`, `sports_certificate`) |
| `fee_receipt` | **0** |
| status `draft` | 83 |
| status `archived` | 2 |
| status `published` | **0 — the value never occurs** |
| `publishedVersion != null` | 5 |
| `activeVersion != null` | 2 (TPL0001 TC v6, TPL0004 bonafide v1) |

## Observations that contradict what the code shape implies

**O1 · `status` never becomes `published`.** Five documents have `publishedVersion` set;
every one of them still reads `status: "draft"` (or `archived`). Publication is expressed
by `publishedVersion != null`, NOT by the status field. Any state machine drawn from
`status` alone is wrong. → A6 must model status and publication as orthogonal axes.

**O2 · The list endpoint returns FULL documents.** One `get_templates` call returned
**456 KB** across 85 documents — every template's complete `objects` array. Median
document 4.9 KB, largest 8.1 KB. There is no summary projection and no pagination
observed in the response. → A9: model this at 10×/100×.

**O3 · `lastProof` schema drifts.** Some proof records carry `pdfPaths` and
`perLanguage`; others carry neither, with no other distinguishing field. → A5: is the
proof record versioned, and can a consumer of `pdfPaths` be handed a proof without one?

**O4 · `docTitle` exists on 21 of 85 documents.** It was introduced for custom types.
Reads must tolerate its absence on the other 64. → A5/A7: field introduced without
backfill; who reads it and what do they do when it is missing?

**O5 · 80 of 85 templates are never-published drafts.** A large share was created by the
E2E harness (`tests/doctemplates/_zxdt_e2e.js`) exercising real server endpoints against
this school. → A9/A10: the module has no bulk cleanup, and the gallery lists all of them.
→ Also a process finding: the test harness writes to real tenant data.

**O6 · `activeVersion` is set on a document whose `status` is `draft`.** TPL0001 is the
live template for transfer certificates and simultaneously an editable draft at v6.
→ A6/A8: what stops an edit to the draft from changing what is currently being issued?
The version snapshot is the intended answer — that must be PROVEN, not assumed.

## Stored head-document keys

On all 85: `schoolId · templateId · docType · status · name · version · lockVersion ·
publishedVersion · activeVersion · page · header · footer · objects · languages ·
defaultLanguage · contractRef · complianceBasis · complianceLayers · starterId ·
createdBy · createdAt · updatedAt`
On some: `updatedBy` (6) · `lastProof` (5) · `docTitle` (21)
Added by the read layer, not stored: `_id`, `__updateTime`

## Read-only boundary probes (E3, captured by QA-LEAD)

Executed against the live server in an authenticated session for `SCH_B56BB9A401`.
No writes. Results:

| Probe | Result |
|---|---|
| `get_template` own id | ALLOWED (correct) |
| `get_template` `SCH_AAAAAAAAAA_TPL0001` | refused — "Template not found" |
| `get_template` `TPL0001` (short id, no school prefix) | refused — "Template not found" |
| `get_template` `../../schools/SCH_B56BB9A401` | refused — "Invalid characters in field: templateId" |
| `get_template` empty | refused — "Missing required field: templateId" |
| `get_versions` foreign id | refused — "Template not found" |

**This does NOT prove tenant isolation, and must not be reported as if it does.**
`SCH_AAAAAAAAAA_TPL0001` does not exist. "Not found" is therefore consistent with BOTH
"the tenant check fired" AND "the document is simply absent" — the probe cannot
distinguish them. Proving the boundary requires a template id that **exists in a
different school**, which this session does not have.

→ `[UNKNOWN]` · tenant read isolation on `get_template` / `get_versions`.
→ Becomes a T0 row requiring either a second tenant's real id or a seeded fixture (H1/H2).

One positive that IS established: the error message is identical for "foreign" and
"absent", so the endpoint does not leak an existence oracle across tenants.
Path traversal in `templateId` is rejected by character validation, not by lookup.

## Runtime findings QA-LEAD captured that static reading would under-weight

**L1 · The Document Engine is not in the navigation.** Every certificate link the rendered
sidebar carries points at the LEGACY controller:

```
/certificates            · Dashboard
/certificates/templates  · Templates
/certificates/generate   · Generate
/certificates/issued     · Issued
```

There is **no sidebar link to `/doc_templates`**. Observed in the live DOM. The Document
Engine is reachable only by typing the URL or following a bookmark, while the navigation
exposes a second, older certificate system with its own Templates / Generate / Issued
pages. Two certificate systems are simultaneously present and the discoverable one is not
the one under certification.
→ P1 candidate. → A1 must map the legacy controller; A7 must treat this as a divergence
axis; A10 owns the discoverability consequence. **Which system is authoritative is an
IS/SHOULD question the code cannot settle → likely H3.**

**L2 · Permission flags are shipped to the client and almost never used.**
`BOOT.canEdit` / `BOOT.canManage` arrive correctly (`SRV.can = {edit:true, manage:true}`
for this manage-level session). The client consults `SRV.can` in exactly **two** places:

```
assets/js/doctemplates/designer.js:2614   Delete button on a gallery row
assets/js/doctemplates/designer.js:5388   rollback control in version history
```

Nothing gates **Publish**, **Make live / activate**, **Deactivate**, **Archive**,
**Duplicate**, **Save**, or **New document**. `paintTopActions` does not reference either
flag.
→ Client gating is never a security boundary (§6 rule 11), so the P0 question is whether
the SERVER refuses each of these for a non-manage actor — A8 must trace every one.
→ Independently, a view-level user being shown Publish and Make live is a real UX and
permission-parity defect even if the server holds. Both become rows.
→ This session cannot test it: it holds manage rights, and entering another user's
credentials is not something I will do. **H2 — human-only runtime action.**

## L3 · The delete refusal names a remedy the product does not offer

**Static (E2).** `srv.archive` is defined at `assets/js/doctemplates/designer.js:1007`
and **called from nowhere** — grep for `srv.archive` returns exactly the definition.
`srv.validate` is not referenced at all. There is no Archive button, menu item, or
keyboard path anywhere in the client.

**Runtime (E3, captured by QA-LEAD).** Attempting to delete a published template returns:

> "this template has published version(s), and each one is the record of what a
> certificate issued from it actually said. Deleting it would delete that record.
> **Archive it instead** — it disappears from the list and the history survives."

I then archived both templates successfully — but only by calling `srv.archive()` from the
browser console. **A user has no way to perform the action the error message instructs
them to perform.**

Consequence: **a published template can never be removed from the gallery by any user
action.** Delete refuses it (correctly — the version record is the issuance record), and
the prescribed alternative has no UI. The gallery therefore grows monotonically and
forever for every template that was ever published.

The refusal itself is right. The dead end is the defect.
→ P1. → Row in T1 (the journey a user cannot complete) and T3 (the missing control).

## L4 · Runtime evidence closing A1's route-dispatch [UNKNOWN]

A1 found 7 endpoints with no explicit entry in `routes.php` and could not establish
whether CI3's default segment routing dispatches them. **I invoked four of them against
the live server this session and all four dispatched and executed correctly:**

| Endpoint | Runtime evidence |
|---|---|
| `delete` | deleted two never-published templates; refused two published ones with the message quoted above |
| `deactivate` | cleared `activeVersion` on two templates |
| `archive` | set `status: archived` on two templates |
| `get_versions` | returned the version list for `SCH_B56BB9A401_TPL0001` |

E3 for those four. **Still `[UNKNOWN]` for `version_pdf`, `presence`, `leave`, and
`duplicate`** — not exercised this session. Those remain UAT rows.

Note this cuts both ways: an endpoint reachable without an explicit route is reachable by
anyone who can guess the URL, so A8 must confirm each is capability-gated by `_remap()`
rather than by routing obscurity.

## L5 · Pattern check — "audit trail with no actor identity" — NOT FOUND (negative result, recorded)

`_patterns.md` records a defect shape where an audit trail is written without the acting
identity. I checked it against the live population and it **does not hit here**:

| Check | Result |
|---|---|
| Templates changed after creation (`updatedAt != createdAt`) | 6 |
| …of those, with no `updatedBy` | **0** |
| Templates whose `version` advanced past 1 | 5 |
| …of those, with no `updatedBy` | **0** |
| `createdBy` blank | 1 of 85 — `TPL0001`, the oldest document, predating the actor plumbing |

A first, cruder count looked alarming — `updatedBy` is absent on 79 of 85 documents — but
`updatedAt` is stamped at CREATION as well as on edit, so a never-edited template
legitimately has no updater. 79 of these templates were created and never touched. The
defect signature is "changed, but no record of who", and it is **absent**.

Recorded so the team does not re-derive the alarming version of this count later.
The single blank `createdBy` on `TPL0001` — which happens to be the live active transfer
certificate — is a one-document historical gap, not a systemic one. Worth one T3 row.

## L6 · PARITY DIVERGENCE — the Parent app already generates fee receipts

A3 established (E2, `ZenXII_Parent/.../util/ReceiptPdfGenerator.kt`, 431 lines) that the
**Parent app already generates a fee-receipt PDF entirely client-side**, using native
`android.graphics.pdf.PdfDocument`, shared via `FileProvider`, and deliberately
non-localised (English/Latin digits only — the reasoning recorded in the file is that a
receipt is forwarded to employers and shown to auditors).

A `fee_receipt` document type was added to the Document Engine on 2026-09-03, with its own
itemised layout, its own contract, and its own renderer (mPDF).

**There are now two independent implementations of "a fee receipt" in this ecosystem, on
two surfaces, with different layout engines, different localisation policy, and no shared
contract.** Neither is aware of the other. When the print-point seam is eventually wired,
a parent's app-generated receipt and a school's engine-generated receipt for the same
payment will not agree.

This is the §7 "free oracle" — where two implementations of one rule disagree, at least
one is wrong. It is also an IS/SHOULD question the code cannot settle: which one is
authoritative, and is the app's client-side generation meant to be replaced?
→ **H3 — business decision.** → `⚑ CONTESTED` → T0.
→ Note this is NOT a defect today: the seam is unwired and the engine issues nothing.
It is a designed-in collision that becomes real the moment the seam is connected.

## L7 · O6 ANSWERED (partially) — version snapshots hold frozen content · E3, reproduced

**The scenario is live, not hypothetical.** `SCH_B56BB9A401_TPL0001` is the active transfer
certificate and is simultaneously an editable draft:

```
head.version = 7      publishedVersion = 6      activeVersion = 6      status = "draft"
```

Someone has edited the draft past what is being issued. This is exactly the condition
under which "editing the draft silently changes what is being issued" would show itself.

**Experiment (read-only).** `get_versions` returns metadata only — `version, pdfLangs,
publishedAt, publishedBy, proofPdfHash, mpdfVersion, fontManifest, active` — and **no
`objects`**, so the endpoint cannot answer the question. I instead rendered four versions
through `version_pdf` and hashed the bytes:

| Version | HTTP | Bytes | FNV-1a |
|---|---|---|---|
| 6 (active) | 200 | 1,003,107 | `2623fdca` |
| 5 | 200 | 1,003,079 | `b9897311` |
| 4 | 200 | 988,457 | `41690b7` |
| 1 | 200 | 24,162 | `5e379cd` |

**Four versions, four different documents.** v1 is 24 KB against ~1 MB for v4–v6 (an image
was introduced later); v5 and v6 differ by 28 bytes, consistent with a small text edit.
If every version rendered from the current head they would be byte-identical. They are not.

**CONFIRMED (E3):** rendering a published version uses that version's own frozen content,
not the current mutable draft. The most dangerous failure mode available to this
module — an edit to a draft silently changing the certificate a school is issuing today —
**does not occur on this path.**

**What this does NOT establish, and must not be reported as if it did:**
1. That the snapshot cannot be MUTATED after creation. Nothing here writes to a version;
   a code path that does would not show up in this experiment. → A5 must trace it.
2. That the snapshot is SELF-CONTAINED. It may still reference things that can change
   underneath it — a reusable block, an uploaded asset, `doc_types.php` contract data,
   font files. A snapshot that renders correctly today because its dependencies happen to
   be unchanged is not immutable. → A5 must trace each reference. → T0 row either way.
3. Anything about the ISSUED-document path, which does not exist (`CON-NO_PRINT_IMPL`).

**CORRECTION (A9, upheld by QA-LEAD).** I originally wrote that each call "rendered a
~1 MB PDF on demand through mPDF". **That is wrong.** `version_pdf`
(`Doc_templates.php:454-489`) does not render anything: it reads
`$snap['proofPdfPaths'][$lang]` out of the version snapshot and `readfile()`s an
**already-rendered file from disk**. My four calls streamed four files; they were not four
renders. The only endpoint that invokes mPDF is `proof_pdf()`.

**What the experiment therefore does and does not prove, restated precisely.**
It proves a **distinct pre-rendered PDF artefact exists per version**, whose path is
recorded inside that version's own snapshot document. It does NOT, by itself, prove the
snapshot's `objects` are frozen — that is A5's and A6's code trace, and A6 has since
confirmed self-containment structurally. The three lines of evidence remain mutually
reinforcing; my description of the mechanism was simply wrong and is corrected here rather
than quietly amended.

**Two side observations from the same experiment:**
- `version_pdf` is gated at **`view`** (`Doc_templates.php:55`). Any view-level user can
  download any version's PDF → row.

## L11 · DURABILITY — the frozen record lives on one server's local disk

Following A9's correction to its conclusion surfaces something no agent framed.

The immutable artefact — the PDF of what a published version actually looked like — is a
file at `uploads/{schoolId}/doctemplates/_proofs/{templateId}_v{n}_{lang}.pdf`
(`Doc_templates.php:470-471`), on the **PHP server's local filesystem**. Not Firestore.
Not Cloud Storage. The snapshot document holds only a path to it.

`PATH_A_US_SERVER_RUNBOOK.md:60-62,117` records that `uploads/` are real user files that
must be **rsynced by hand** during a server migration, and that if you forget them
"logos/circulars 404". The same is now true of every historical certificate PDF.

So: the record of what a school issued has **no durability guarantee beyond one Lightsail
instance's disk** — no replication, no backup path documented for it, no object store, and
(per A9) no cleanup either, so it only ever grows. Lose or rebuild the instance without a
manual rsync and `version_pdf` 404s for every version ever published, while Firestore still
cheerfully lists them as published.

The containment check on the path is sound (`realpath()` + `str_starts_with()`,
`:474-481`) — this is not a security finding. It is a **durability and disaster-recovery
finding**, and it applies to the one artefact in this module whose whole purpose is to
survive.
→ **P1 candidate.** → `[UNKNOWN]`: whether the Lightsail instance is snapshotted, and
whether `uploads/` is included. That is an infrastructure fact I cannot read from the
repo → **H1 for the human.**


## L8 · A finding against yesterday's change, recorded by QA-LEAD against its own work

A2 flagged `hydrateFromServer`'s handling of a failed `srv.types()` call
(`designer.js:5591-5595`). The code is mine, written 2026-09-03, and its own comment
convicts it:

```
}catch(e){
  /* Not fatal — the fixture still renders a usable hub — but it must not pass
     silently, or the screen goes back to asserting a state nobody set. */
  console.warn("[zxdt] could not read the school's state and board; …", e);
}
```

It says the failure must not pass silently, and then passes it silently — a
`console.warn` is invisible to the person using the product. The consequence is the exact
defect the change was written to remove: on a failed load the hub falls back to the
built-in fixture (`CBSE · Jharkhand`) and states it as fact, **and that state gates which
statutory certificate types are offered to the school.** A school in Kerala whose type
lookup failed would be shown the wrong catalogue with no indication anything went wrong.

This is also a direct hit on a catalogued pattern: `_patterns.md` ·
*"A read failure is reported indistinguishable from a legitimate empty result."*
A2 found the same shape at two more sites (`srv.templates()` failure renders the same copy
a genuinely empty school sees; the toast self-clears in ~3.2s).

**Not fixed now.** Phase 8 is after Stop Gate B; implementing at Gate A would be crossing
a gate. Recorded as a defect candidate with a fix already understood, and as UAT rows —
a human must first confirm the behaviour at runtime, because "what a failed load looks
like on screen" is precisely an E3 claim I am not permitted to make from source.

## L9 · REPRODUCED (E4) — the type gate is bypassable via `save()`

A4 inferred this from source and asked for runtime verification. **I reproduced it against
the live server, then deleted the probe. Population before 85, after 85.**

| Step | Result |
|---|---|
| 1 · `create("custom:qa_probe_delete_me", {docTitle:"QA probe"})` | **allowed** — custom types are ungated by design |
| 2 · `create("study", …)` directly | **refused** — "'study' is not a document type this school can create. A state-specific form is offered only in the state that prescribes it" |
| 3 · `save(id, {docType:"study"}, lockVersion)` | **returned OK** |
| 4 · re-read the stored document | **`docType = "study"`** |

The school is in **Madhya Pradesh**. `study` is the Andhra Pradesh Study Certificate,
gated by `requiresState`. The endpoint that creates refuses it; the endpoint that saves
accepts it. Any `edit`-grade user can mint a custom document and patch it into any
state-gated statutory type the school is not entitled to.

**Root cause, and I own it.** `_safe_type()` was widened from a hardcoded list of three to
a catalogue lookup on 2026-09-03 — by me, in this session. I wired it to `create()` and to
`index()`, and never checked `save()`. `Doc_template_service::save()` strips seven
lifecycle keys from the incoming patch and **`docType` is not among them**
(`Doc_template_service.php:336-339`), so it is written straight through. The fix closed the
front door and left this one standing. This is the catalogued pattern
`_patterns.md · "A guard exists on paper but isn't actually wired into every path that
needs it"` — and a second instance of *"sibling-path parity drift"*, the library's
highest-leverage pattern, committed while the library was being written.

**Severity.** A4 rated it P2 on inference. With reproduction I hold it at **P2, not P1**:
it is a business-rule bypass by an already-authenticated `edit`-grade staff member within
their own tenant, not a tenant or auth breach, and nothing issues from a template today
(`CON-NO_PRINT_IMPL`). It becomes P1 the moment the print seam is wired, because the
document that prints would then be one the school is not entitled to issue.
Not fixed now — Phase 8 is after Gate B.

## L10 · A5's `docTitle` [UNKNOWN] — closed

A5 could not explain why `docTitle` appeared on 21 documents when only 3 custom types
exist. Resolved by separating key-presence from truthiness:

- `docTitle` **key present**: 22 · **non-empty**: 4

`create()` writes `'docTitle' => (string)($seed['docTitle'] ?? '')` unconditionally, so
every template created since the field was introduced carries the key with an empty string.
The four non-empty values are the three real custom types plus my probe. No drift, no
defect — a census counting key-presence, which is what my earlier pass reported.

## L12 · EXECUTED (E3) — client and server mint DIFFERENT type ids for the same name

A7 inferred a divergence between the two `customTypeFor()` implementations and correctly
flagged that its E2 ceiling stopped it from executing. I ran both real runtimes
(`php` 8.5, `node`) over the same inputs:

| Input | PHP `Doc_contract::customTypeFor` | JS `customTypeFor` | Agree |
|---|---|---|---|
| `İstanbul Public School` | `custom:stanbul_public_school` | `custom:i_stanbul_public_school` | **NO** |
| `İİİ` | *(empty — refused)* | `custom:i_i_i` | **NO** |
| `ÄÖÜ School` | `custom:school` | `custom:school` | yes |
| `ß Schule` | `custom:schule` | `custom:schule` | yes |
| `Ⅻ Class Certificate` | `custom:class_certificate` | `custom:class_certificate` | yes |
| `Sports Day` | `custom:sports_day` | `custom:sports_day` | yes |

**Cause.** PHP's `strtolower()` is byte-only and leaves `İ` (U+0130) untouched, so the
`[^a-z0-9]+` collapse swallows it. JavaScript's `toLowerCase()` is Unicode-aware and
expands `İ` to `i` + U+0307, so the `i` survives the collapse. Both then behave identically
on everything that is already ASCII, which is why every other case agrees.

**Consequence, which is worse than a cosmetic id difference.** The client mints the id and
sends it; the server independently derives nothing — it stores what it is given — but the
client's `customTypes()` discovery, its gallery keying, and the "exactly one active per
docType" invariant all key on that id. Two people naming the same document the same way on
two paths, or any future server-side re-derivation, produce two document types where the
user intended one. The `İİİ` row is sharper still: the **client accepts a name the server's
own validator refuses**, so the client would offer to create a document the server would
reject — the client-side guard and the server-side guard disagree about what a valid name
even is.

**This is my code, from 2026-09-03.** I wrote `customTypeFor` twice, once per language, and
verified them against each other only on ASCII inputs. It is a third instance of the
library's top pattern, *sibling-path parity drift* — and `DocContractParityTest` is
structurally unable to catch it, because it compares static arrays and cannot compare two
function bodies (A7's finding 6).

**Severity: P2.** It needs a Unicode SpecialCasing character in a document name — unlikely
in this ecosystem, not impossible, and the failure is quiet rather than loud.
→ Row in T2 (boundary/Unicode) with these exact inputs.

## L13 · CONFIRMED (E3) — CSS injection into the PDF/preview style attribute

A8 found this by trace; I verified it by calling `Doc_serializer::render()` directly with a
crafted `style.fontFamily` and inspecting the **emitted HTML string only**. No external
request was made, and none should be until the human authorises it.

Emitted, verbatim:

```html
<div class="zx-o zx-text" style="…;font-family:Arial;background:url(http://169.254.169.254/latest/meta-data/);letter-spacing:0;x:url(http://example.invalid/beacon.png);">
```

`htmlspecialchars()` is correct for HTML *text* and wrong for a **CSS attribute-value**
context: it does not escape `:` or `;`, so the value breaks out of `font-family` and adds
arbitrary declarations. Both `style.fontFamily` (`Doc_serializer.php:474`) and `style.track`
(`:477`) are affected, and `repeatingTable()`'s `align` (`:858,872`) is the same shape.

`Doc_renderer::guardImages()` — a control written specifically because "mPDF fetches remote
images server-side… SSRF primitive" — matches only
`/<img\b[^>]*\bsrc\s*=\s*("|')(.*?)\1/i` (`Doc_renderer.php:391`). It never inspects
`style=`. The guard and the hole are in the same file.

**Reachability.** `save()` applies no shape validation to `objects`, so any **`edit`**-grade
user can persist this. `preview()` returns the HTML to a browser; `proof_pdf()` feeds it to
mPDF **on the production Ohio Lightsail instance**.

**What is CONFIRMED and what is not — the distinction matters for severity.**
- CONFIRMED (E3): the injection primitive. Attacker-controlled CSS reaches the renderer.
- `[UNKNOWN]`: whether mPDF actually dereferences `background:url(…)`. If it does, this is
  server-side request forgery from a host that can reach the cloud metadata endpoint. If it
  does not, it degrades to a browser-side beacon in `preview()` — still a defect, far less
  severe.
- **I have deliberately not tested the dereference.** Confirming it means causing the
  production server to make an outbound request to a host of my choosing, which is §2 **H4**
  — a dangerous operation requiring explicit human authorisation. The test is designed and
  waiting in the matrix; it is the single highest-value row in this run.

**Severity: P1, provisionally, pending that test.** It is P0 if mPDF dereferences and the
instance can reach `169.254.169.254`; it is P2 if mPDF ignores CSS urls entirely.

**Pattern.** `Doc_serializer` already contains the correct fix shape — `align()` whitelists
its input — sitting a few lines from a vulnerable call site. Fourth instance this run of
`_patterns.md · sibling-path parity drift`.

## L14 · TENANT ISOLATION — read paths CONFIRMED at E4 with a real second tenant

The human provided the missing resource (H5/Q11): an authenticated session for a genuinely
different school. **Harshit Public School · Uttar Pradesh · CBSE · 0 templates of its own.**

This is the test the earlier probe could not perform. The foreign ids used below **genuinely
exist**, are **published and active**, and belong to `SCH_B56BB9A401` (Vikrant Public School).
"Not found" is therefore no longer ambiguous.

### Read paths — all refused

| Probe | Foreign id | Exists? | Result |
|---|---|---|---|
| `get_templates` (list) | — | — | 0 rows; **no foreign document leaked into the list** |
| `get_template` | `…_TPL0001` (active TC, published v6) | **YES** | refused — "Template not found" |
| `get_template` | `…_TPL0004` (active bonafide, published v1) | **YES** | refused — "Template not found" |
| `get_template` | `…_TPL9999` | no | refused — "Template not found" |
| `get_versions` | `…_TPL0001` | **YES** | refused — "Template not found" |
| `get_versions` | `…_TPL9999` | no | refused — "Template not found" |
| `version_pdf` | `…_TPL0001` v6 | **YES** | HTTP **404**, 1130 bytes, not a PDF |
| `version_pdf` | `…_TPL0004` v1 | **YES** | HTTP **404**, 1130 bytes, not a PDF |
| `version_pdf` | `…_TPL9999` v1 | no | HTTP **404**, 1130 bytes, not a PDF |

**No cross-tenant read succeeded on any path.** `version_pdf` did not return a single byte
of PDF for a real foreign published version.

**No existence oracle.** Real-foreign and fake-foreign responses are indistinguishable —
identical error text on the JSON paths, and identical HTTP status *and byte length* (1130)
on `version_pdf`. An attacker cannot use this surface to learn whether a template id exists
in another school. The implementation does this deliberately, and says so
(`Doc_template_service.php:894-896`): *"Deliberately the SAME message as 'not found'.
Confirming that an id exists in another tenant is itself a disclosure."*

### Write paths — E2, one shared gate

A runtime cross-tenant **write** probe was blocked by the harness's own safety classifier. I
did not route around it. Verified statically instead: **every mutating lifecycle method
routes through the single `head()` gate** (`Doc_template_service.php:888-900`) —

`save` (:316) · `recordProof` (:441) · `publish` (:483) · `activate` (:602) ·
`deactivate` (:742) · `delete` (:788) · `archive` (:828)

One gate, seven callers, no bypass found. **This is E2, not E3** — it remains a UAT row.
I deliberately did NOT attempt `save`/`publish`/`activate`/`deactivate`/`delete` against
the other school: a success there would have damaged a live certificate belonging to a
tenant that did not consent to this test.

### Ruling

**UR-2 CLOSED for reads (E4, reproduced against real cross-tenant data).**
**UR-2 remains OPEN for writes at runtime (E2 traced, one shared gate, message parity
confirmed).** The ecosystem invariant *"no actor may read or write data outside their
tenant"* is **upheld on every path tested**, and this is the strongest positive result of
the run.

## L15 · The hub is not usable for 6–20 seconds after navigation · E3

Chased while trying to run T0-20. `S.lib` was empty on a school with 86 templates, which
looked like a defect in my own projection change. **It was not.** Hydrate had simply not
finished — every probe I fired at 9–10 seconds after navigation was arriving before the data.

Measured, same session, two runs:

| Leg | Fast run | From the server log |
|---|---|---|
| `get_types` | 2,492 ms | 2,307 ms |
| `get_templates` | 3,244 ms | **15,008 ms and 17,211 ms** |
| **Total to a usable hub** | **5.7 s** | **~17–19 s** |

The server log entries are the damning ones: `firebase_ops=1 firebase_ms=15002` — **a single
Firestore operation taking 15 seconds** for 86 documents, cross-region from Ohio to `nam5`.

### What my projection did and did not fix

The list projection (F13) cut the browser payload from **456 KB to 117 KB** — 1,365 bytes per
template, verified. That is real and it helps the browser.

**It does nothing for the Firestore read**, which is the actual bottleneck. The query still
retrieves complete documents; the projection only trims what is serialised to the client
afterwards. A9 named the payload as the breaking point; on this evidence the **read** is
worse, and it is the leg that leaves a clerk looking at an empty hub for up to 20 seconds.

A9's other finding stands and is now the load-bearing one: `create()` reads **every** template
head the school has ever created, and 94% of these 86 are never-published harness debris that
nobody can bulk-delete.

### Consequences
- **Not a code defect and not fixed.** Recorded honestly rather than papered over.
- The fix is a Firestore-side projection (`select` on the query) or pagination — the read has
  to return less, not just send less.
- It interacts with the seeding trigger: a school whose hydrate takes 17 s shows an empty hub
  first, and my per-type seeding check runs only after hydrate resolves, so nothing is
  double-seeded — verified, but only because the check is per type rather than "is it empty".

### A note on my own method
I spent several probes hunting a phantom because I assumed a fixed wait was long enough. The
honest lesson for the UAT rows: **wait on a condition, not a timer.** Every remaining live
probe in this engagement should poll for `S.loading === false` rather than sleeping.
