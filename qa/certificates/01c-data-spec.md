# 01c · Data spec — Document Engine (Certificates)

**Agent: A5 · DATA-SPEC.** Evidence ceiling **E2** for everything derived from code
(`file:line`). The live-state file (`_live-state.md`, captured by QA-LEAD, 2026-09-04) is
**E3** but only for what it literally records — 85 real head documents in
`SCH_B56BB9A401`. Where code and live data disagree, the live data is what exists.
Classification: `[CONFIRMED]` code-proven fact · `[INFERRED]` reasoned from code but not
directly observed · `[UNKNOWN]` genuinely open · `[CONTESTED]` code/rules/data disagree.

---

## 1 · Collection and document model

### 1a — Collections this module owns

| Collection | Key scheme | Key contract? | Writer |
|---|---|---|---|
| `documentTemplates` | `{schoolId}_TPL####` | **Conforms** to `{schoolId}_{entityId}` | `Doc_template_service::create/save/publish/activate/deactivate/delete/archive` (`application/libraries/Doc_template_service.php:177-862`) |
| `documentTemplateVersions` | `{templateDocId}_v{n}` = `{schoolId}_{entityId}_v{n}` | **Deviation** — 3-part, not 2-part; `create`-only | `Doc_template_service::publish()` (`Doc_template_service.php:514-551`) |
| `reusableBlocks` | caller-supplied `blockId`, validated only for characters (`safe_path_segment`) | **Deviation** — no server-enforced `{schoolId}_` prefix on the key; ownership is asserted only via the `schoolId` *field*, not the id (`Doc_block_service.php:96-105`, `Doc_templates.php:966`) | `Doc_block_service::save()` |
| `templateSessions` | `{schoolId}_{shortTemplateId}_{userId}` | **Deviation** — 3-part composite | `Doc_presence::heartbeat/leave` (`Doc_presence.php:61-64`) |

[CONFIRMED] `documentTemplates` is the only collection that conforms exactly to the
repo-wide `{schoolId}_{entityId}` scheme. The other three all extend it with a suffix or,
for `reusableBlocks`, drop server-side enforcement of it entirely — `Doc_block_service::save()`
never prefixes or validates that `$docId` starts with the caller's `schoolId`; it only
refuses to let one school **overwrite** a block whose stored `schoolId` field differs
(`Doc_block_service.php:100-105`). Because `save_block` (the only wired writer) has zero
client callers (§8), this deviation is currently unreachable, not exploited.

### 1b — `documentTemplates` head document — full field set

From `Doc_template_service::create()` (`Doc_template_service.php:210-243`), cross-checked
against the **E3** live field list (all 85 docs):

| Field | Optional? | Note |
|---|---|---|
| `schoolId`, `templateId`, `docType`, `name`, `status`, `version`, `lockVersion`, `publishedVersion`, `activeVersion`, `page`, `header`, `footer`, `objects`, `languages`, `defaultLanguage`, `contractRef`, `complianceBasis`, `complianceLayers`, `starterId`, `createdBy`, `createdAt`, `updatedAt` | Required, present on all 85 [CONFIRMED E3] | — |
| `docTitle` | Present on all (create always writes it, even as `''`) but **truthy** on 21/85 [CONFIRMED E3] | Required by `create()` validation only for `Doc_contract::isCustom($docType)` types (`Doc_template_service.php:187-192`); empty string for built-ins |
| `updatedBy` | Only written by `save/activate/deactivate` when `$by !== ''`; absent until first authenticated edit — 6/85 have it [CONFIRMED E3] | |
| `lastProof` | Only after `recordProof()` runs — 5/85 have it [CONFIRMED E3] | See O3 |
| `blockRefs`, `blockIgnored` | **Never written anywhere in the PHP layer** — `Doc_template_service::create()` does not seed them, and no controller endpoint writes them either. Only `Doc_block_service::acceptOffer/declineOffer` write them, and those two methods have **zero callers** in `Doc_templates.php` (§8) | [CONFIRMED] Dead fields — the client (`designer.js`) maintains its own in-memory `S.blockRefs`/`S.blockIgnored` (seeded to hardcoded demo values, e.g. `{BLK0001:3}` at `designer.js:872,2291,2440,2479`) that is **never persisted** to or read from Firestore. |

### 1c — `documentTemplateVersions` snapshot document — full field set

From `publish()` (`Doc_template_service.php:524-550`): `schoolId`, `templateId`, `docType`,
`version`, `snapshot{page,header,footer,objects,languages,defaultLanguage}`, `contractRef`,
`complianceBasis`, `complianceLayers`, `validationResult`, `proofPdfHash`, `proofPdfPaths`,
`fontManifest`, `mpdfVersion`, `publishedBy`, `publishedAt`. No optional fields — all set
unconditionally, defaulting to `[]`/`null` (never *absent*) [CONFIRMED, code path]. This
collection was **not** separately profiled in `_live-state.md` (that pass hit
`get_templates`, i.e. heads only), so its live shape is [UNKNOWN — E3 gap]; L4 confirms via
runtime that `get_versions` dispatches and returns data for at least `TPL0001`, but the raw
document body was not captured.

---

## 2 · O1 — `status` never becomes `published`. [CONFIRMED, code + E3]

Traced every write to `status`:

| Writer | Value written |
|---|---|
| `create()` (`Doc_template_service.php:225`) | `'draft'` |
| `save()` | never touches `status` — explicitly stripped from the patch (`Doc_template_service.php:336-339`) |
| `publish()` (`Doc_template_service.php:556`) | **`'draft'`** — not `'published'` |
| `activate()` / `deactivate()` | never touches `status` |
| `archive()` (`Doc_template_service.php:853`) | `'archived'` |

`publish()` calls `assertTransition($head['status'] ?? 'draft', 'published')`
(`Doc_template_service.php:484`) — which validates that `draft → published` is a legal
transition per the `TRANSITIONS` const (`Doc_template_service.php:45-49`) — **but the write
that follows never sets `status` to `'published'`.** The head immediately re-opens as the
next draft (`status: 'draft', version: version+1`). `'published'` is asserted as a legal
*transition* by the state machine but is **never a legal resting value written by any code
path.** `publishedVersion` (an int/null field, not `status`) is the sole on-disk signal
that a publish happened.

Consequence: `TRANSITIONS['published'] => ['archived']` (`Doc_template_service.php:47`) is
**dead code** — no head document can ever be in state `'published'` for `assertTransition`
to be called against, since nothing ever writes that value.

`firestore.rules:3164-3187` independently assumes the opposite: its update rule branches
on `resource.data.status != 'published'` and comments "A PUBLISHED head is nearly frozen".
**`[CONTESTED]`** — the rules author modeled `status: 'published'` as a real, reachable
state; the service code that is the only writer (Admin SDK, bypasses these rules per the
file's own header comment) never produces it. The branch is unreachable through the
current writer, but would matter the day any other writer (a Cloud Function, a future
direct-client write) sets `status: 'published'` literally — the rule is not wrong, just
addressed to a state nothing currently creates.

**Any state machine drawn from `status` alone is wrong** — publication is a second,
orthogonal axis (`publishedVersion != null`), exactly as O1 states, and this is fully
explained by the code: `publish()`'s own docblock (`Doc_template_service.php:352-354`)
titles the section "P6.1/P6.2/P6.3 — publish" but the head-mutation half of it is really
"close this version and reopen the next draft."

---

## 3 · O6 — activeVersion:6 on a status:draft head. **Answered first, as instructed.**

**The critical question: what stops an edit to the draft from changing what is currently
being issued?**

**[CONFIRMED from code] The immutable snapshot holds. Here is the trace:**

1. **`activate()` never copies content.** It writes exactly three scalar fields to the
   HEAD document: `activeVersion`, `lockVersion`, `updatedAt`
   (`Doc_template_service.php:660-664`). It does not read or write `page`/`header`/
   `footer`/`objects` at all.
2. **The pointer resolves to the FROZEN collection, not the head.** `Doc_resolver::activeVersion()`
   — the one seam a future print point would call — builds
   `$vid = $head['_id'] . '_v' . $head['activeVersion']` and reads it from
   `documentTemplateVersions`, explicitly documented as "The VERSION, never the head. The
   head is a live draft that moves" (`Doc_resolver.php:135-152`). So whatever "is currently
   being issued" is defined as, code-level, to mean the `documentTemplateVersions/{id}_v{n}`
   document — never `documentTemplates/{id}`.
3. **`documentTemplateVersions` is create-only, everywhere.**
   - Service level: `publish()` checks `exists()` first and throws rather than overwrite
     (`Doc_template_service.php:517-522`, "Snapshots are create-only — a published version
     is never rewritten").
   - Rules level (defense in depth, though the Admin SDK bypasses it today):
     `allow update, delete: if false;` (`firestore.rules:3207-...`).
   - No method anywhere in `Doc_template_service.php`, `Doc_resolver.php`, or
     `Doc_templates.php` writes to `documentTemplateVersions` except `publish()`'s single
     `set()` at creation.
4. **`save()` (the only way to edit a draft) cannot touch the pointer or the frozen
   collection.** It strips `status`, `publishedVersion`, `activeVersion`, `templateId`,
   `schoolId`, `updatedBy`, `createdBy` from every incoming patch before writing
   (`Doc_template_service.php:336-339`), and it writes **only** to `documentTemplates`
   (`Doc_template_service.php:346`) — never to `documentTemplateVersions`. It also refuses
   outright if `status !== 'draft'` — but per §2 (O1), status is *always* `'draft'` on a
   head with `publishedVersion` set, so that particular guard is not what is doing the
   protective work here; the collection-separation in point 3 is.

**Reconciling the numbers.** `publish()` unconditionally sets
`headPatch['version'] = $version + 1` (`Doc_template_service.php:558`) the moment a version
freezes. So immediately after publishing v6, the head's own `version` field becomes **7**,
not 6 — the editable draft is always numbered *one ahead* of the highest published/active
version, never equal to it. If TPL0001's head document's `version` field literally reads
`6` while `activeVersion` also reads `6` (as O6's prose states), that is `[CONTESTED]`
against this code path — it would mean either (a) O6's "v6" is shorthand for "the active
version is v6" rather than a literal read of the head's `version` field (plausible — the
UI's version badge is what a person calls "the draft", and the live-state capture did not
publish the raw JSON diff of `version` vs `activeVersion` side by side), or (b) some other
writer touched `version` outside this service. **This does not weaken the O6 proof** —
points 1-4 hold regardless of what number the mutable head's `version` field currently
carries, because the render/print seam never reads the head's content fields at all, only
the immutable snapshot named by `activeVersion`. Flagged `[UNKNOWN]` for the exact
`version` vs `activeVersion` numeric relationship on TPL0001 specifically — worth a direct
Firestore console read to settle, but immaterial to the safety proof.

**One caveat that is real, not theoretical:** nothing prints today.
`Doc_resolver::issuanceAvailable()` is hardcoded to check `document_targets.php`'s `wired`
flag, and every row there is `wired => false` (`Doc_resolver.php:95-103`,
`00-dependency-graph.md` §1, §8). So the immutability guarantee is **proven at the code
level** but has never been exercised by a real issuance flow — there is no code today that
actually reads `Doc_resolver::activeVersion()` to render a certificate. The proof holds for
what the module does today (protect the frozen record) and for what it is built to do
(feed a future print point); it has not been proven by any live document ever having been
printed from it.

**Verdict: O6 is answered — NOT ASSUMED.** The snapshot is genuinely immutable at three
independent layers (service refusal, Firestore rule, and collection separation), and the
resolver seam is wired to read from it, not from the head. No P0 here.

---

## 4 · O3 — `lastProof` schema drift (pdfPaths/perLanguage present on some, absent on others)

**[CONFIRMED, code + comment trail].** `recordProof()` (`Doc_template_service.php:438-479`)
today unconditionally writes `pdfPaths` and `perLanguage` — as empty arrays if not
supplied (`Doc_template_service.php:469-470`), never omitted. The **only** caller,
`Doc_templates.php::proof_pdf()`, always passes real values (`pdfPaths` at line 877,
`perLanguage` at line 878). So under the *current* code, a fresh `lastProof` record always
carries both keys, even if empty.

The divergence is explained by the code's own history, documented in-line:
`Doc_template_service.php:463-468` — *"proof_pdf() writes one PDF per language and passes
their paths, and this record **dropped them** — so publish() froze a snapshot with
`'proofPdfPaths' => []`, and a published version could never be shown to anybody."* This is
the author's own note that an earlier version of `recordProof()` did **not** persist
`pdfPaths`/`perLanguage` at all (field truly absent, not empty) — i.e. **O3 is schema
drift across a bug fix, with no backfill migration run on the pre-fix documents.** Given
only 5/85 documents have `lastProof` at all, at least one predates the fix.

**Can a consumer of `pdfPaths` be handed a proof lacking it? — No crash, by design fallback,
confirmed at both read sites:**
- `Doc_templates.php::_versionPdfLangs()` (line 438-451): reads `$doc['proofPdfPaths']`
  first, then **also** probes the filesystem by naming convention
  (`{id}_v{n}_{lang}.pdf`) and unions the two — "Older snapshots were frozen before the
  proof record kept its file paths, so fall back to the naming convention" (comment at
  line 445-447).
- `Doc_templates.php::version_pdf()` (line 465-469): `$rel = $snap['proofPdfPaths'][$lang] ?? null; if (!$rel) { $rel = '...naming convention...'; }` — explicit fallback, "Snapshot predates paths being recorded."

Both consumers were **specifically hardened against exactly this absence**, which is
strong evidence the absence was a known, already-hit production condition rather than a
theoretical one. The remaining risk is narrower: the fallback assumes the PDF file
actually sits at the conventional path on disk — true for anything rendered by the current
`proof_pdf()`, [UNKNOWN] for whatever produced the pre-fix records (if that older code path
also failed to *save* the file under this convention, the fallback would 404 rather than
crash — `version_pdf()` calls `show_404()` on a missing file, not an exception; fails
closed, not silently).

`lastProof` is a **head-only** field, unversioned in the sense that only the single latest
proof is kept (`update(['lastProof' => $rec])` overwrites, `Doc_template_service.php:476`)
— there is no history of prior proofs, only the one that gated the most recent publish.

---

## 5 · O4 — `docTitle` on 21/85 documents, no backfill

**[CONFIRMED, code].** `create()` always writes the key (`Doc_template_service.php:221`,
`(string)($seed['docTitle'] ?? '')`), so its presence is universal but its **truthiness**
is not — empty string for every built-in-type template, populated only for custom types
per the validation at line 187-192. 21/85 having a non-empty `docTitle` is consistent with
the live-state population table (`custom:*` = 3 templates, but O4 counts 21 — meaning most
`docTitle`-bearing rows are **not** the 3 custom-type heads themselves but something else,
or duplicates/starters cloned from them; this module's code does not explain the 21 vs 3
gap — `[UNKNOWN]`, worth a live re-check of which `docType` values carry non-empty
`docTitle`).

**Every reader, and its behavior on absence:**

| Reader | File:line | Behavior when `docTitle` empty/absent |
|---|---|---|
| Type-level gallery title | `designer.js:1580-1582` — `rows.find(r=>r.docTitle)` across all templates of a docType | Falls back to `customTitleOf(id)` — a slug-derived reconstruction. **Lossy**: `Doc_template_service.php:186-190`'s own validation comment says "the slug alone cannot reproduce the name that was typed" |
| Live-open-template title | `designer.js:1605` — `S.tpl.docTitle` ternary | Same `customTitleOf(id)` fallback |
| `get_templates`/`get_template` (PHP) | `Doc_templates.php:341,363` | Passes the field through **verbatim**, empty string included — no server-side fallback or omission; the client alone decides what to show |
| `create()` payload construction (client) | `designer.js:2293,2308,5611` | Only a *writer*, not a reader — sends `docTitle` on create, does not re-derive from server state |

No reader crashes or throws on absence — every read path degrades to a slug reconstruction,
which is functionally acceptable but semantically **not the string a user typed**, which is
the exact failure mode the `create()` validation was written to prevent for the *original*
document (`Doc_template_service.php:182-190`) but does not prevent for whatever produced
the 64 documents (or however many of the 85) that lack it after the field's introduction.

---

## 6 · Version snapshots — what is frozen, self-containment

**Frozen at publish time** (`Doc_template_service.php:524-550`): `page`, `header`, `footer`,
`objects`, `languages`, `defaultLanguage` (nested under `snapshot`), plus `contractRef`,
`complianceBasis`, `complianceLayers`, `validationResult`, `proofPdfHash`, `proofPdfPaths`,
`fontManifest`, `mpdfVersion`. **Never mutated after creation** — create-only at both the
service layer (exists-check refusal) and the rules layer (`allow update, delete: if false`)
[CONFIRMED].

**Self-containment: mixed.**
- Design content (`page/header/footer/objects/languages`) is **fully self-contained** — a
  deep copy of the head's values at publish time, not a reference.
- `complianceLayers` is explicitly frozen "not referenced" per its own comment
  (`Doc_template_service.php:539-541`) — "A later authority revision must not retroactively
  change what an already-issued certificate was validated against."
- **BUT `objects` can itself contain references to `reusableBlocks`** (via `blockRefs` on
  the head, and block content merged in at design time — see §8). Whether a snapshot's
  frozen `objects` array holds the block's *rendered content* (self-contained) or a
  *pointer* that a later render would have to re-resolve against the live
  `reusableBlocks` collection is **[UNKNOWN]** — not traced this pass; `Doc_serializer.php`
  (969 lines, not read in this pass) is where that would resolve. This is the one place
  self-containment is not proven either way, and it matters: if a snapshot's `objects`
  merely references a block by id rather than embedding its content, then a block edit —
  even though it can never silently mutate a *template* per the offer model (§8) — could
  still silently change what an *already-frozen, already-published version* renders as,
  which would be a second, independent way O6's guarantee could be undermined. Flagged for
  a re-check against `Doc_serializer.php` and `Doc_renderer.php`.

---

## 7 · Atomicity table

| Operation | Documents touched | Mechanism | Atomic? | What's stored on partial failure |
|---|---|---|---|---|
| `create()` | 1 (`documentTemplates`) | single `set()` after a create-only retry loop | Single-doc — atomic by definition | N/A |
| `save()` | 1 (`documentTemplates`) | single `update()` with lockVersion CAS-by-application-check (not a Firestore precondition) | Single-doc | Conflicting write refused before any write happens (read-then-compare-then-write, not a real Firestore CAS) — `[INFERRED]` a true race between two `save()` calls reading the same `lockVersion` and both passing the check before either writes is possible in principle, since the compare-and-set is enforced in PHP, not via a Firestore `currentDocument.updateTime` precondition the way `activate()`'s batch does it (`Doc_template_service.php:325-332` vs `commitBatch`'s precondition support at `Firestore_rest_client.php:1035-1048`, which `save()` does not use) |
| `recordProof()` | 1 (`documentTemplates`) | single `update()` | Single-doc | N/A |
| **`publish()`** | **2** (`documentTemplateVersions` create + `documentTemplates` update) | **two sequential, non-transactional calls**: `store['set']` then `store['update']` (`Doc_template_service.php:551,563`) — **not** run through `store['commit']` | **NOT ATOMIC** [CONFIRMED, code] | **If the process/network fails between the two calls**: the immutable snapshot `documentTemplateVersions/{id}_v{n}` exists and is permanently frozen (create-only), but the head still shows the OLD `status`/`publishedVersion`/`version` (as if publish never happened). **On retry**, `publish()` recomputes the same `$vid` from the still-unchanged head `version`, hits the `exists()` guard (`Doc_template_service.php:517-522`), and throws "version already exists" — **permanently refusing to publish this template again** until a human manually reconciles the head (this is not self-healing, unlike `activate()`, which is designed to heal a bad state via its "plural on purpose" siblings-displacement). This is the same failure class the module's own architecture notes call out for `activate()` and explicitly built `commit` to prevent — `publish()` was not given the same protection. **New finding, not present in `_live-state.md`.** |
| **`activate()`** | 1 + N siblings | `store['commit']` → Firestore `:commit` REST endpoint, single `writes` array, `currentDocument: {exists:true}` precondition on the winner | **ATOMIC** [CONFIRMED] — `Firestore_rest_client.php:996-1059`'s own doc comment: "All writes succeed together or fail together... Firestore guarantees single-transaction semantics inside `commit`" | Whichever ordering of two concurrent `activate()` calls, each commit is described as "a whole, self-consistent answer" (`Doc_template_service.php:642-644`) — refuses non-atomically entirely if no `commit` callable is available (`Doc_template_service.php:690-702`), rather than degrading |
| `deactivate()` | 1 | single `update()` | Single-doc | N/A |
| `delete()` | 1 (`documentTemplates` only) | single `delete()`; **never touches referenced files** (assets, proof PDFs) or `documentTemplateVersions` | Single-doc-atomic, but leaves orphans — see §9 | N/A |
| `archive()` | 1 | single `update()` | Single-doc | N/A |
| `Doc_block_service::save()` | 1 (`reusableBlocks`) | single `set()` | Single-doc | N/A |
| `Doc_block_service::acceptOffer/declineOffer` | 1 (`documentTemplates`) | single `update()` | Single-doc, but **dead code** — zero callers (§8) | N/A |

**Headline atomicity finding: `activate()` is genuinely atomic and well-defended;
`publish()` — the operation that CREATES the very immutable record `activate()` depends on
— is not, and a failure between its two writes leaves the template permanently stuck.**
This is the mission's #4 question answered directly: yes, `activate` is atomic (proven);
no, it is not the only multi-document write in the lifecycle, and the other one (`publish`)
is not atomic.

---

## 8 · Firestore rules — reality check

Quoted match-block headers, `firebase-rules/firestore.rules`:

```
match /documentTemplates/{docId} {
  allow read: if isSameSchool();
  allow create: if isStaff() && isSameSchoolWrite()
                && hasCapabilityLevel('Certificates', 'edit')
                && request.resource.data.status == 'draft';
  allow update: if isStaff() && isSameSchool() && isSameSchoolWrite()
                && hasCapabilityLevel('Certificates', 'edit')
                && request.resource.data.templateId == resource.data.templateId
                && request.resource.data.docType == resource.data.docType
                && ( request.resource.data.activeVersion == resource.data.activeVersion
                     || hasCapabilityLevel('Certificates', 'manage') )
                && ( resource.data.status != 'published' || ...restricted-field-set... );
  allow delete: if false;
}                                                                    — firestore.rules:3158-3200
match /documentTemplateVersions/{docId} {
  allow read: if isSameSchool();
  allow create: if isStaff() && isSameSchoolWrite() && hasCapabilityLevel('Certificates', 'manage');
  allow update, delete: if false;
}                                                                    — firestore.rules:3207-3215
match /reusableBlocks/{docId} {
  allow read: if isSameSchool();
  allow create, update: if isStaff() && isSameSchoolWrite() && hasCapabilityLevel('Certificates', 'edit');
  allow delete: if isStaff() && isSameSchool() && hasCapabilityLevel('Certificates', 'manage');
}                                                                    — firestore.rules:3218-3227
match /complianceAuthorities/{authorityId} {
  allow read: if isAuth() && tenantActive(request.auth.token.school_id);
  allow write: if false;
}                                                                    — firestore.rules:3149-3152
```

`templateSessions` — **no match block anywhere**. Falls through to the file's terminal
catch-all `match /{document=**} { allow read, write: if false; }` [CONFIRMED,
`00-dependency-graph.md` §4, independently consistent with a full-file grep this pass did
not re-run but has no reason to doubt].

**Critical framing, stated plainly: the panel writes and reads every one of these four
collections through `Doc_templates.php` using the Firebase Admin SDK
(`$ci->fs` → `Firestore_service` → `Firestore_rest_client`), which authenticates as a
service account and is not subject to `firestore.rules` at all.** Every guarantee described
above — the `documentTemplates` update rule's field-diffing restriction on a `'published'`
head, the create-only lock on `documentTemplateVersions`, the capability grading — is
**enforced today only by PHP-layer checks inside `Doc_template_service`/`Doc_templates.php`
(§2-4, §7), not by these rules.** The rules currently protect against exactly one thing:
**a hypothetical direct client (mobile app or browser SDK) reading or writing Firestore
directly**, and per the dependency graph (§5), **no such client exists today** — zero
references in either Android app, and the panel's own `designer.js` talks to
`Doc_templates.php` over HTTP/AJAX, never to Firestore directly. So today, these rules are
**inert as an enforcement layer** and **live as a specification of future intent** — should
a mobile client ever read a school's active certificate template directly, or should a bug
ever cause the panel to embed a client SDK, this is what would gate it. This is explicitly
acknowledged in the rules file's own header comment (quoted in the dependency graph §4):
"TODAY NO CLIENT WRITES ANY OF THESE."

---

## 9 · Index coverage

Every query issued by this module's `Doc_*` libraries and `Doc_templates.php` is
**equality-only** (`grep` for `orderBy`/range operators inside `Doc_*.php` and
`Doc_templates.php` returns zero hits — checked this pass):

| Query | Fields | Needs a composite index? |
|---|---|---|
| `create()` numbering scan | `schoolId ==` | No — single-field |
| `activate()` sibling scan | `schoolId ==`, `docType ==` | No — Firestore auto-satisfies equality-only compound queries without a declared composite index (has done since ~2020/2021) |
| `Doc_resolver::activeTemplate()` | `schoolId ==`, `docType ==` | No |
| `Doc_block_service::listFor()` | `schoolId ==`, optional `blockType ==` | No |
| `get_templates` (controller) | `schoolId ==`, optional `docType ==` | No |
| `get_versions` (controller) | N direct `get()`s by constructed id, no query | N/A |
| `Doc_compliance` (`complianceAuthorities`) | `scope.state ==`/`scope.board ==`, `tier ==` | No (equality-only) but matches declared indexes anyway |

**Declared composite indexes** (`firebase-rules/firestore.indexes.json`), 7 total across 3
collection groups relevant to this module:

- `documentTemplates`: `(schoolId, docType, status)`, `(schoolId, docType, activeVersion)`, `(schoolId, updatedAt DESC)`
- `documentTemplateVersions`: `(templateId ASC, version DESC)`
- `reusableBlocks`: `(schoolId, blockType)`
- `complianceAuthorities`: `(scope.state, tier)`, `(scope.board, tier)`

**Finding: none of the current module code issues a query that requires any of the three
declared `documentTemplates` composite indexes, nor the `documentTemplateVersions` one.**
Every actual query in the code is equality-only on 1-2 fields, which Firestore serves
without a declared index. The declared indexes read as **provisioned ahead of queries the
code does not yet issue** — plausibly for a future status-filtered gallery view or an
`updatedAt`-sorted listing (`(schoolId, updatedAt DESC)` is exactly the shape a "recently
edited" sort would need) — `[INFERRED]`, not confirmed against any commit history or design
doc this pass. **This is not a gap in the dangerous direction** (a query with no index
fails at runtime per the mission's own framing) — it is the opposite: indexes exist for
queries that don't exist yet, which costs nothing except index-maintenance overhead, not
correctness. **No under-provisioned query was found this pass** — i.e. no evidence of the
"declared-but-missing" failure mode for this module specifically.

`templateSessions` has no declared index and needs none — the `Doc_presence::others()`
query (`schoolId ==`, `templateId ==`) is equality-only and it has no rules block either,
consistent with server-only access (§8).

---

## 10 · Orphans, drift, and cleanup — inventory

| Artifact | Ever cleaned up? | Evidence |
|---|---|---|
| `documentTemplateVersions` docs whose head was deleted | **Cannot happen via this code** — `delete()` refuses if `publishedVersion !== null` (`Doc_template_service.php:799-806`), and only a template with `publishedVersion === null` has zero version docs to begin with. So this specific orphan class is structurally prevented by the code [CONFIRMED] — but only for deletions that go through `Doc_template_service::delete()`; a head removed by any other path (Firestore console, a migration script) would orphan its versions with nothing to detect it |
| Proof PDFs on disk (`uploads/{schoolId}/doctemplates/_proofs/`) | **No.** Zero `unlink()`/deletion calls found anywhere in `Doc_templates.php` (grepped this pass). A re-proofed template leaves its prior proof's PDF file on disk forever — only the Firestore `lastProof` pointer moves, the old file is simply unreferenced | [CONFIRMED, absence] |
| Uploaded asset images (`uploads/{schoolId}/doctemplates/assets/`) | **No.** Same grep, zero hits. Content-hash-named, so removing an image from a template's `objects` array leaves the file orphaned on disk indefinitely | [CONFIRMED, absence] |
| `templateSessions` rows | **Best-effort only.** `leave()` fires on a page-unload beacon, which "browsers are free to drop" (`Doc_presence.php:130-134`, its own comment). Stale rows are filtered **at read time** (`others()` excludes anything older than 90s, `Doc_presence.php:116-118`) but **never deleted** — a session document from a browser that crashed 6 months ago is still a live Firestore document, just one that is never returned by `others()` | [CONFIRMED] Read-side filtering, not storage-side cleanup — the collection grows monotonically with every heartbeat×user×template combination ever recorded |
| `documentTemplates` drafts never published | **No bulk path** — confirmed both by code (no "delete all drafts" or "bulk archive" endpoint found in `Doc_templates.php`'s 27 public methods, per the dependency graph §2) and by live data: **80/85 = 94% of all templates in the observed school are never-published drafts**, largely from a test harness (`_zxdt_e2e.js`) writing to real tenant data (O5, E3) | [CONFIRMED E3 count + CONFIRMED E2 absence of bulk tooling] |
| Published templates stuck in the gallery forever | **Structural dead end**, not just missing tooling — see `_live-state.md` L3: `delete()` correctly refuses a published template and names `archive` as the remedy, but `archive`'s only client entry point (`srv.archive`) has zero call sites in `designer.js` — there is no button, menu item, or keyboard path to reach it. QA-LEAD confirmed by calling `srv.archive()` from the browser console (E3) that the server accepts it; **no UI affordance reaches it.** [CONFIRMED, code — cross-referenced from `_live-state.md` L3] |

---

## 11 · Reusable blocks — the offer/adopt model is dead code, not merely unused

Mission item #8 asks what happens to templates referencing a shared block when it changes.
**Answer: nothing observably happens, because the mechanism that would surface and apply
the change is unreachable from two independent directions.**

**Direction 1 — the offer/accept methods have zero callers.** `Doc_block_service::offersFor()`,
`acceptOffer()`, `declineOffer()` (`Doc_block_service.php:142-247`) are never referenced by
`Doc_templates.php` — grepped this pass, zero hits beyond `_blocks()` itself, which is only
used for `get_blocks`/`save_block` (`Doc_templates.php:294-299,581-586,963-980`). **There is
no controller endpoint for offer/accept/decline at all.** These three methods, and the whole
"offered, never pushed" design documented at length in the class's own header
(`Doc_block_service.php:11-27`), are unreachable from any HTTP entry point.

**Direction 2 — the query-shape bug that would ALSO break it if it were wired.**
`Doc_block_service`'s injected store (`Doc_block_service.php:59-64`) sets
`'query' => fn($c,$w) => $fs->schoolWhere($c,$w)` — **not** wrapped in `Doc_rows::map()`,
unlike every other consumer of `schoolWhere()` in this module (`Doc_template_service.php:103`,
`Doc_resolver.php:62` both wrap it). `Firestore_service::schoolWhere()` (via `where()`,
`Firestore_service.php:427-445`) returns the **envelope list** shape,
`[['id'=>docId,'data'=>[...fields]], ...]` — its own docblock says so explicitly (line 425).
`Doc_rows.php`'s header comment (`Doc_rows.php:14-21`) describes this *exact* bug class
happening once already in this module: "`$row['activeVersion']` is simply absent on an
envelope... No error, no log — the wrong answer, confidently." **`Doc_block_service` was not
given the fix.** Tracing the consequence:
- `listFor()` returns raw envelopes to `offersFor()` and `acceptOffer()`.
- `offersFor()` does `foreach ($templates as $id => $t)` — `$id` is a numeric array index
  (envelopes are a list), so `is_string($id)` is false, falls to
  `(string)($t['_id'] ?? '')` — an envelope has no `_id` key (only `id`/`data`) — so
  `$id === ''` and every row is skipped by the `if ($id === '' ...) continue;` guard
  (`Doc_block_service.php:157-160`). **`offersFor()` would return `[]` on every call,
  regardless of how out of date any template's pinned block version actually is.**
- `acceptOffer()` calls `listFor()` and does
  `foreach ($blocks as $b) { if ((string)($b['blockId'] ?? '') === $blockId) ... }`
  (`Doc_block_service.php:205-209`) — `$b` is an envelope, `$b['blockId']` is always null,
  so `$available` stays `null` and the method **always** throws
  `"no pending update for '$blockId'"` (`Doc_block_service.php:211-213`), even immediately
  after a real block edit.

**So even setting Direction 1 aside, the propagation model is doubly broken: nothing would
ever surface an offer, and any client that tried to accept one would always be told there
is nothing to accept.** This is `[CONFIRMED]` from code (both the missing controller
wiring and the unwrapped-envelope shape bug are directly readable, not inferred).

**What the client shows instead:** `designer.js` maintains its own **entirely local, mock**
block-version state — `BLOCKS[0].version++` (`designer.js:4771`), hardcoded
`blockRefs:{BLK0001:...}` seeded at three separate places (`designer.js:872,2291,2440,2479`)
— a pending-update badge is computed client-side against this fake array
(`designer.js:3894-3895`) and "accepting" an update only mutates `S.blockRefs` in memory
(`designer.js:5016`), never calling any server endpoint. **The offer/adopt UI a user sees
is a demo, not connected to the `reusableBlocks` collection or to `Doc_block_service` at
all.**

**Direct answer to mission #8's question — "if a shared block changes, what happens to
templates referencing it, including published ones?":** A block CAN be edited via
`save_block` (server-side reachable, though also client-unwired — §8 of the dependency
graph). Editing it bumps its `version` field. **Nothing then happens to any referencing
template — published or draft — because no code path ever reads the new version against
any template's pin.** No propagation, no notification, no badge tied to real data, no
accept path that functions even if invoked directly. The "offered, never pushed" safety
design is real in intent and would correctly protect published templates from
retroactive changes **if it worked** — but as shipped, "never pushed" is true for the
wrong reason: nothing is offered either.

---

## 12 · Counts

| Item | Count | Evidence |
|---|---|---|
| Collections owned by this module | 4 | E2 |
| Collections conforming exactly to `{schoolId}_{entityId}` | 1 of 4 (`documentTemplates`) | E2 |
| Collections deviating from that key scheme | 3 of 4 | E2 |
| Head documents live | 85 | E3 |
| `status` values observed | 2 distinct (`draft`, `archived`); `published` = 0 occurrences | E3 |
| Documents with `publishedVersion != null` | 5 | E3 |
| Documents with `activeVersion != null` | 2 | E3 |
| Never-published drafts | 80 of 85 (94%) | E3 |
| Documents with `lastProof` | 5 of 85 | E3 |
| Documents with truthy `docTitle` | 21 of 85 | E3 |
| Documents with `updatedBy` | 6 of 85 | E3 |
| Multi-document write operations in the lifecycle | 2 (`publish`, `activate`) | E2 |
| Of those, genuinely atomic | 1 (`activate`, via real Firestore `:commit`) | E2 |
| Of those, NOT atomic | 1 (`publish` — two sequential single-doc writes) | E2 — new finding |
| `Doc_block_service` methods with a controller endpoint | 2 of 5 (`listFor`→`get_blocks`, `save`→`save_block`) | E2 |
| Of those 2, reachable from the shipped client | 0 of 2 | E2 |
| `Doc_block_service` methods with zero controller wiring at all | 3 of 5 (`offersFor`, `acceptOffer`, `declineOffer`) | E2 |
| Firestore rules match blocks for this module's collections | 4 of 4 owned collections... only 3 have one; `templateSessions` has none | E2 |
| Composite indexes declared for this module's collections | 7 | E2 |
| Of those 7, actually required by a current query in the code | 0 | E2 — every query is equality-only |
| File-cleanup calls (`unlink`) for proofs/assets found in the controller | 0 | E2 — absence |

---

## 13 · Named gaps

- **[UNKNOWN]** Whether the head's literal `version` field ever numerically equals
  `activeVersion` on a live document (the O6 prose implies TPL0001 shows both as 6; the
  code's `publish()` always advances `version` to one past whatever it just froze, which
  would put them at 6 and 7 respectively, not 6 and 6). Does not affect the O6 safety
  proof, which rests on collection separation, not on this numbering coincidence. A direct
  read of TPL0001's raw head JSON would settle it.
- **[UNKNOWN]** Whether a snapshot's frozen `objects` array embeds a referenced
  `reusableBlocks`' rendered content or a live pointer re-resolved at render time. Not
  traced into `Doc_serializer.php`/`Doc_renderer.php` this pass. If it's a pointer, a block
  edit could alter an already-published, already-frozen version's *rendered output*
  without touching any Firestore document this report covers — a second and independent
  way the "immutable snapshot" promise could be undermined, separate from and worse than
  the already-confirmed dead offer/accept model in §11.
- **[UNKNOWN]** Why O4 counts 21 documents with a truthy `docTitle` when only 3 custom-type
  heads exist in the population table — the code only requires/expects `docTitle` on
  custom types. Either starters/clones of custom types also copy `docTitle` forward
  (plausible, not traced), or some built-in-type templates carry a stray non-empty
  `docTitle` for a reason this pass didn't find.
- **[UNKNOWN]** The raw stored shape of `documentTemplateVersions` documents was not
  captured live (the E3 pass only queried `get_templates`, i.e. heads). Everything in §3,
  §6 about that collection is E2 code-trace only.
- **[UNKNOWN]** Whether the pre-fix `lastProof` records (missing `pdfPaths`) actually have
  a corresponding PDF file on disk at the naming-convention path the fallback expects, or
  whether that combination 404s in practice — not exercised this pass (would require a
  live `version_pdf()` call against one of the affected 5 documents, which are all drafts
  with `publishedVersion == null` per current data — meaning none of them have reached
  `version_pdf` at all, since that reads `documentTemplateVersions`, not `lastProof`
  directly; low real-world exposure today).
- **`Doc_serializer.php` (969 lines) and `Doc_renderer.php` (428 lines) were not read this
  pass** — flagged by the dependency graph as unexamined by prior passes too. Any data-shape
  claim about what actually renders from `objects` (versus what is merely stored) is
  therefore [UNKNOWN] beyond what's stated in §6.
