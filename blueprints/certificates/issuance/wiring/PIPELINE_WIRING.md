# Issuer identity → rendered certificate: the actual wiring

Read-only investigation, 2026-09-15, branch `yug_testing`, repo `~/Desktop/Zennxii_adminPanel`.
No code was changed. Every claim below carries a `file:line`. Anything not confirmed by reading the
code is in §9, not in the body.

## 0 · The three headline answers

1. **Publish freezes NEITHER the issuer values NOR the board.** The snapshot written by
   `Doc_template_service::publish()` contains no school data at all — only the design, which carries
   `{"f":"school.affiliationNo"}` *bindings*. A corrected affiliation number would therefore be
   re-resolved at print time, not served stale. The one exception is `complianceLayers`, which IS
   frozen; and `complianceBasis`, which is declared, frozen, and **never written by any code**.
2. **Nothing enforces `mayIssue()`.** Confirmed, not merely suspected: `mayIssue` appears at exactly
   four call sites in the whole tree, two of which are its own definition and its test. It is
   computed, serialised to the client, and the client that receives it (`designer.js`) never reads
   it. No publish, activate, proof, preview, print or number-allocation path consults it or the
   issuer `level`.
3. **The three render paths do not agree, and the premise is wrong in two ways.** `Doc_renderer` is
   not an issuer-reading path at all — it takes HTML and emits PDF, field-blind. And there is a
   **fourth path that was not in the brief and is the only one producing a real certificate today**:
   `Sis::print_tc()` → `views/sis/tc_print.php`, which reads `schools/{id}` live and prints the
   affiliation number and board onto a numbered Transfer Certificate. Marksheets
   (`views/result/templates/cbse.php`) do the same.

---

## 1 · School doc → rendered certificate, hop by hop

There are two disjoint pipelines. Hops 1–12 are the **Document Engine** (designs templates; renders
only *sample* data; issues nothing). Hops A1–A6 are the **legacy SIS path** (issues real, numbered
TCs today).

### 1A · The Document Engine pipeline

| # | Hop | Citation |
|---|---|---|
| 1 | Issuer fields are written **flat onto the school doc** — `affiliationBoard`, `affiliationNo`, `udiseCode`, `registeredName`, `headOfInstitution`, `headSince`, `recognitionOrder{}`, `reviewMonths` — plus a nested `issuerIdentity.{verification, level, updatedBy, updatedAt}`. | `application/controllers/School_config.php:506-588`, write at `:574` |
| 1b | **A second, unvalidated door writes the same two keys.** `save_profile()` allowlists `affiliation_board` and `affiliation_no` with only a length cap, no pattern, no board context. | `School_config.php:405-437` (allowlist `:411-415`, caps `:419-426`) |
| 1c | `Firestore_service::saveSchool()` maps `affiliation_board → affiliationBoard`, `affiliation_no → affiliationNo` — the *same* keys the validated door judges. | `application/libraries/Firestore_service.php:1014-1041` (map at `:1031-1032`) |
| 2 | `Doc_templates::_school_context()` reads `schools/{schoolId}` once. | `application/controllers/Doc_templates.php:318-322` |
| 3 | It builds `$identity` = the flat school doc merged with `issuerIdentity.verification`, then computes `level = Issuer_identity::levelOf($identity)`. | `Doc_templates.php:340-345` |
| 4 | It returns `['name','state','board','stage','issuer'=>['level','mayIssue']]`, where `board = Issuer_identity::complianceBoard($doc)` — **`$doc`, the school doc, not a template doc.** The brief's `complianceBoard($doc)` reads as if it took a document; it takes the school. | `Doc_templates.php:347-356`; `Issuer_identity.php:394-398` |
| 5 | `complianceBoard()` upper-cases `affiliationBoard ?? board` and returns `''` for anything not in `BOARDS`. | `Issuer_identity.php:396-397`, `BOARDS` at `:86-122` |
| 6 | `get_types()` ships that whole block — issuer level and `mayIssue` included — to the browser as `data.school`. | `Doc_templates.php:242-260` |
| 7 | `designer.js` consumes **only `name`, `state`, `board`, `stage`.** The `issuer` block is dropped on the floor: `grep -n "issuer" designer.js` returns only `S.issuance.duplicate` hits, an unrelated preview toggle. | `assets/js/doctemplates/designer.js:6111-6180` (merge at `:6161-6166`); the five `issuance` hits are `:1953, :4269, :4391, :5427, :5428` |
| 8 | `S.school.board` feeds `resolveStack()`, which selects authorities by `appliesWhen(sc)` — `sc.board === "CBSE"` for the CBSE authority. | `designer.js:462-473`; CBSE predicate at `:234` |
| 9 | The selected authorities' `requiredKeys` become `prof().requiredKeys`. For CBSE TC this list contains **`school.affiliationNo`**. | `designer.js:477-481`, `:521-535`; CBSE `requiredKeys` at `:284-289` (`school.affiliationNo` first) |
| 10 | The designer binds that key into a run: the persisted shape is `content.i18n[lang].runs = [{f:"school.affiliationNo"}]`. Every shipped starter puts it on object `h_addr` in `objects` (not `header.objects`). | `application/config/doc_starters.php:138, 630, 643, 1265, 1278, 1839, 2285, 2298, 2732`; run shape asserted by counting `f` keys in `tc_cbse` → 14 runs with `f`, 0 with `field` |
| 11 | On the canvas, `chipHTML()` → `fieldValue()` resolves the key from the **client-side `CONTRACT` table's `sample`/`p95` literal** — `"3430006"`. Never the school's real number. | `designer.js:2043-2050`, `:1948-1955`, `CONTRACT` at `:40-110` (affiliationNo at `:42`) |
| 12 | Server-side, `Doc_serializer::render($tpl, [], $lang, ['sample'=>…])` resolves the same key from `application/config/doc_types.php`'s `sample`/`p95` — again `'3430006'`. **Both server render entry points pass `$data = []`.** | `Doc_serializer.php:92-133`, `resolve()` at `:696-727`; `doc_types.php:61`; callers `Doc_templates.php:1008` (preview) and `:1092` (proof_pdf) |
| 13 | `Doc_renderer::render(string $html, array $page, array $opts)` converts that HTML to PDF via mPDF. It never sees a merge key, a contract or a school. | `Doc_renderer.php:216` |

**Conclusion for 1A: the real `affiliationNo` never reaches a rendered artefact anywhere in the
Document Engine.** The only issuer value that influences output is the **board string**, and it
influences it *indirectly* — by selecting which authority's `requiredKeys` the client-side publish
gate demands, hop 8–9.

### 1B · The SIS pipeline — the one that actually issues

| # | Hop | Citation |
|---|---|---|
| A1 | `Sis::issue_tc()` — RBAC `SIS/manage`. Checks dues across fees, library, hostel, transport; disables the student's Firebase Auth account; removes them from the RTDB roster; writes `TC_ISSUED` history. | `application/controllers/Sis.php:1497-1680` |
| A2 | Allocates the TC number: `_get_tc_number()` → `Firestore_service::nextSchoolCounter('tc', …)`, atomic claim-doc CAS, mirrored to `schools.tcCounter`. Format `TC-{6 alnum of school name}-{year}-{4-digit}`. | `Sis.php:1544`, `:3374-3425` (format at `:3422-3424`) |
| A3 | `Sis::print_tc()` re-reads `schools/{schoolId}` **at print time, every time**. | `Sis.php:1751` |
| A4 | The view reads `$sp['affiliationNo']` and `$sp['affiliationBoard']` straight off that live document. | `application/views/sis/tc_print.php:32-33` |
| A5 | Same for marksheets: `Result::_school_info()` reads `affiliationNo` / `affiliationBoard ?? board` live. | `application/controllers/Result.php:1910-1931` (`:1922-1923`) |
| A6 | And prints them: `Aff. No: …` plus an "AFFILIATED" badge. | `application/views/result/templates/cbse.php:42-46`, `:51-56` |

No hop in 1B touches `Issuer_identity`, `levelOf`, `mayIssue`, a template, a contract or a snapshot.

---

## 2 · Three-render-path comparison

Corrected roster — the brief's third path does not read fields at all, so a fourth is added.

| | **Designer canvas** (`designer.js`) | **Doc_serializer** (HTML, both sinks) | **Doc_renderer** (mPDF) | **`tc_print.php`** (legacy, live) |
|---|---|---|---|---|
| Runs where | browser | server | server | server |
| Sees merge fields? | yes | yes | **no** — HTML in, PDF out (`Doc_renderer.php:216`) | n/a — direct PHP array reads |
| Field catalogue | `CONTRACT` literal, `designer.js:40-110` | `doc_types.php` `$config['doc_merge_fields']`, `:58-…` | — | — |
| How `school.affiliationNo` resolves | `f.sample` / `f.p95` literal (`:1954`) | `$def['sample']` / `$def['p95']` literal (`Doc_serializer.php:709-716`) | — | **real** `$sp['affiliationNo']` (`tc_print.php:32`) |
| Real school value ever rendered? | **never** | **never** (both callers pass `$data = []`) | — | **always** |
| **Missing value → ** | renders the field **label** in a grey chip; never errors (`chipHTML()` `:2044-2049`) | **throws** `RuntimeException` — "Refusing to print a blank where a field belongs" (`:719-724`) in real mode; **throws** "contract entry carries no sample value" in sample mode (`:713-716`) | — | prints **nothing**; the whole board/affiliation line is wrapped in `if ($schoolBoard \|\| $schoolAffNo)` (`tc_print.php:32-33`, cbse.php `:42`) |
| Off-contract key → | blocking finding, publish button disabled (`:4029`) | **throws** (`:700-706`) | — | n/a |
| Unknown key → | `"⟨unknown⟩"` from `fieldValue`, but `chipHTML` overrides it with the raw key as a label (`:1949`, `:2044`) | throws | — | n/a |

**Do they agree? On the sample literals, yes — `DocContractParityTest` parses `designer.js` and
asserts `doc_types.php` matches it field for field (`doc_types.php:20-25`). On missing-value
behaviour, no, and the divergence is three-way:**

- canvas: **degrades silently** to a label chip;
- serializer: **fails closed**, loudly;
- legacy print view: **degrades silently** to an omitted line — a CBSE TC whose header is legally
  required to read `AFFILIATION NO. ____` (`designer.js:298-299`, SOP 04.02.2020) simply prints
  without it, and nothing anywhere records that it did.

That third behaviour is the consequential one, because it is the only path a parent actually
receives a document from.

---

## 3 · Publish snapshot semantics — **re-resolved, not stale**

### What is actually frozen

`Doc_template_service::publish()` builds the snapshot at `Doc_template_service.php:677-709`:

```
schemaless fields:  schoolId, templateId, docType, version
snapshot:           page, header, footer, objects, languages, defaultLanguage   (:681-688)
contractRef                                                                      (:690)
complianceBasis                                                                  (:691)
complianceLayers    "Frozen, not referenced: the layers that applied AT PUBLISH TIME"  (:692-695)
validationResult                                                                 (:696)
proofPdfHash, proofPdfPaths, proofPdfPerLanguage, fontManifest, mpdfVersion       (:697-706)
publishedBy, publishedAt                                                         (:707-708)
```

**There is no school data in that list. No name, no board, no affiliation number, no issuer level,
no `mayIssue` result.** The `objects` array carries `{"f":"school.affiliationNo"}` — the *binding*,
not the value.

### So: stale or re-resolved?

**Re-resolved. Plainly.** If a school corrects its affiliation number tomorrow, an already-published
template v3 would render the **new** number, because the snapshot only ever recorded the instruction
"put the affiliation number here", and nothing in the snapshot pins what that number was.

Three riders, all of which matter for the change being planned:

- **No print path exists yet to prove this.** `document_targets.php` carries `'wired' => false` on
  every one of its rows and there is no code that flips it (`document_targets.php:21-23`).
  `Doc_resolver` — the seam that would serve a print point — has **no production caller at all**; it
  is referenced only by `DocResolverTest`. So "re-resolved" is a statement about the design that is
  currently unexercised *within the Document Engine*.
- **The one artefact a published version can hand you is frozen — and it is a sample.**
  `version_pdf()` streams the proof PDF from disk, hash-checked against
  `proofPdfPerLanguage[lang].hash` recorded at publication, refusing on mismatch
  (`Doc_templates.php:618-682`, gate at `:647-679`). That file was rendered with `sample => 'p95'`
  (`:1094`), so it shows `3430006`, not the school's number. It is frozen bytes of fictional data.
- **The live SIS path is unambiguously re-resolved** — `print_tc()` re-reads `schools/{id}` on every
  print (`Sis.php:1751`). Reprint a TC issued three years ago and it carries today's affiliation
  number, silently.

### `version` vs `publishedVersion` in practice

- `version` — the **draft** counter on the head document. It is what an edit moves and what the next
  publish will freeze.
- `publishedVersion` — the **highest version already frozen**.
- On publish, the head is patched `publishedVersion = version; version = version + 1; status =
  'draft'` (`Doc_template_service.php:712-719`). So the head **immediately returns to being a draft
  one ahead of the newest snapshot**; it is never left sitting at `status: 'published'`.
- `activeVersion` is a third, independent pointer. **Publishing does not activate**
  (`:710-711`, controller note at `Doc_templates.php:1340-1341`). Rollback to an earlier published
  version is explicitly permitted (`Doc_template_service.php:806-815`).
- The invariant `version > publishedVersion` is load-bearing: `proof_pdf()` refuses to render when
  `version <= publishedVersion`, because the proof filename is derived from `version` and would
  otherwise overwrite a published version's recorded artefact (`Doc_templates.php:1074-1084`).
- Snapshots are create-only at the database, not merely guarded in PHP: the commit carries
  `'precondition' => ['exists' => false]` (`Doc_template_service.php:762`), and both writes land
  in one atomic commit or neither does (`:744-777`).

---

## 4 · Enforcement audit — every path that could produce a real certificate

`mayIssue` appears at **four** places in the entire tree (`grep -rn "mayIssue" application assets
tests`, excluding cache): its definition, and three call sites. `levelOf` adds two more. That is the
whole population.

| Path | Entry point | Checks `mayIssue()`? | Checks issuer `level`? | Evidence |
|---|---|---|---|---|
| Template **save** (draft edit) | `Doc_templates::save()` | **no** | no | `Doc_templates.php:848-882`; service `Doc_template_service::save()` `:364-520` — allowlist is design fields only, `:413-414` |
| **Validate** (the "authoritative" gate) | `Doc_templates::validate()` | **no** | no | `:890-953`. Its four checks are listed at `:904-947`; none reads `$this->_school_context()`. Two of the four are dead — see §8 D1/D2 |
| **Preview** (browser HTML) | `Doc_templates::preview()` | **no** | no | `:988-1023` |
| **Proof PDF** (writes a real PDF to disk) | `Doc_templates::proof_pdf()` | **no** | no | `:1037-1130` |
| **Publish** (freezes the legal snapshot) | `Doc_templates::publish()` → `Doc_template_service::publish()` | **no** | no | Controller `:1348-1369`; service `:634-782`. The only gates are: a legal status transition (`:636`), a proof on record (`:639-647`), proof version == draft version (`:651-656`), proof `contentHash` == current design (`:659-665`), snapshot id unused (`:669-675`) |
| **Activate** (makes it the one that prints) | `Doc_templates::activate()` → `…::activate()` | **no** | no | Controller `:1371-1390`; service `:816-970`. Gates: not archived (`:824-832`), version is published, one-active-per-docType inside a transaction |
| **Deactivate / archive / delete** | `:1394-1441` | no | no | — |
| **Download a published version's PDF** | `Doc_templates::version_pdf()` | **no** | no | `:618-682`. Gates are tenant ownership (`:624-628`), path containment (`:643-649`), and the sha256 integrity check (`:647-679`) |
| **Print-point readiness** (the declared seam) | `Doc_resolver::readiness()` | **no** | no | `Doc_resolver.php:165-213`. Its five outcomes are `NO_TARGET`, `NO_ACTIVE_TEMPLATE`, `MISSING_SNAPSHOT`, `ISSUANCE_NOT_WIRED`, `READY`. Issuer identity is not among them, and the class has no production caller |
| **Seeding standard templates** | `Doc_templates::seed_standard()` | **no** — uses `board`/`state` only, to pick eligible starters | no | `:276-306`; `Doc_seeder::eligible()` `:119-145` |
| **SIS: issue a real TC** | `Sis::issue_tc()` | **no** | no | `Sis.php:1497-1680`. Gates are RBAC `SIS/manage` (`:1499`) and outstanding dues |
| **SIS: print / reprint a TC** | `Sis::print_tc()` | **no** | no | `Sis.php:1716-1761` |
| **Result: print a marksheet carrying `Aff. No`** | `Result` report-card render | **no** | no | `Result.php:1910-1931`; `views/result/templates/cbse.php:42-46` |

**Where `mayIssue()` IS called — both are display only:**

- `Doc_templates::_school_context()` `:354` → serialised to the client at `:258` → **discarded by
  `designer.js`** (§1 hop 7). It is not even displayed.
- `School_config::get_config()` `:232` → rendered as the coloured ladder in the Issuer Identity tab
  (`views/school_config/index.php:1522`, `:1579`).

**Verdict: the brief's expected answer is confirmed, and is worse than stated.** `mayIssue()` gates
nothing, and on the one surface it is shipped to (the designer) it is not even shown. `ISSUE_FROM =
EVIDENCED` (`Issuer_identity.php:77`) requires `verification.evidencePath`
(`Issuer_identity.php:349-351`) — a field no code in this repo writes and no form collects
(`grep -rn "evidencePath" application assets` → the library and its test only), so the gate is
simultaneously unreachable and unenforced.

---

## 5 · The compliance stack, and what `board === ''` does

### Where it lives

`resolveStack()` and the `AUTHORITIES` corpus exist **only in `assets/js/doctemplates/designer.js`**
(`:205` and `:462`). There is no server-side equivalent — `grep -rn "resolveStack\|AUTHORITIES"
application` returns two comments and nothing executable (`Issuer_identity.php:389`,
`Doc_templates.php:329`). **The compliance stack is computed entirely in the browser.**

### How it decides

```js
function resolveStack(docType, sc){
  AUTHORITIES.forEach(a=>{
    if(!a.appliesWhen(sc)) return;       // ← the board string enters HERE
    const rule=a.docs[dt]; if(!rule) return;
    out.push({a, rule, off: !!S.layerOff[a.id]});
  });
}                                        // designer.js:462-473
```

Three tiers, resolved as a union (`national ∪ board ∪ state`, `:189-195`):

| authority | tier | `appliesWhen` | line |
|---|---|---|---|
| `rte` — RTE Act 2009 s.5(3) | national | `sc.stage !== "secondary"` | `:211` |
| `cbse` — Examination Bye-Laws Annexure-I | board | **`sc.board === "CBSE"`** | `:234` |
| (state authorities, e.g. Kerala) | state | on `sc.state` | `:476` |

`sc.board` is exactly the string `Issuer_identity::complianceBoard()` produced at
`Doc_templates.php:350`. That is the single point at which issuer identity enters the compliance
decision.

Downstream: `stackActive()` (`:474`) drops operator-excluded layers → `requiredKeysOf()` (`:477-481`)
unions every applicable `requiredKeys` → `prof()` (`:521-535`) → client `validate()` emits an
`unbound` blocking finding per missing key (`:3998`) → `openPublish()` disables the Publish button (`blocked` computed at `:5780`, applied at `:5789`).

### When the board is `''`

Confirmed: **the generic profile takes over and enforces nothing.**

- `complianceBoard()` returns `''` for an unrecorded or unrecognised board
  (`Issuer_identity.php:396-397`).
- The client refuses to substitute a default: `board: sc.board || ""` (`designer.js:6164`). This was
  a real defect — the old fallback silently asserted the demo fixture `CBSE · Jharkhand`
  (`SCHOOL_DEFAULT`, `:440`) — and `DocIssuerBasisTest` now pins the fix by regex against the
  source (`tests/Unit/DocIssuerBasisTest.php:51-69`).
- `appliesWhen: sc => sc.board === "CBSE"` then matches nothing, `resolveStack()` returns the RTE
  layer alone (or nothing, for a secondary-stage school), and `prof()` falls through to
  `PROFILES.generic` when the stack is empty (`:522-523`).
- `PROFILES.generic` is `requiredKeys: [], requiredSignatures: [], sealRequired: false`
  (`designer.js:180-186`), labelled *"Generic — no verified profile"*.

So a school with no recorded board can publish and activate **any** design, with zero required
fields, and the product tells it so honestly. The failure mode is not a false claim — it is that
**there is no floor**: nothing prevents publishing a Transfer Certificate with no student name on it.

One loose end worth recording: `PROFILES.cbse` (`:165-179`) still exists as a legacy single-profile
object whose `requiredKeys` **omit `school.affiliationNo`**, while `AUTHORITIES.cbse.docs
.transfer_certificate.requiredKeys` **include it** (`:284`). `prof()` only ever reaches
`PROFILES.generic` (`:523`), so `PROFILES.cbse` is dead — but it is a divergent second copy of the
CBSE field list sitting in the same file, and a future reader could revive the wrong one.

---

## 6 · `complianceLayers` and compliance exclusions

**Storage.** An array on the template head, one entry per authority, shaped
`{authorityId, applied: bool, version: int, reason: string}`
(`tests/Unit/DocComplianceTest.php:44-62`; consumer `Doc_compliance.php:101-112`).

**Write path.** The designer serialises `S.layerOff` + `S.overrideReason` into `complianceOverrides()`
and sends it in the save payload (`designer.js:1573`); the server allowlists `complianceLayers` as an
editable draft field (`Doc_template_service.php:414`); on reload the exclusions are restored into
`S.layerOff` (`designer.js:6335-6341`). This round trip was broken once — the server was ready and
the client never sent the key — and `DocCompliancePersistenceTest` now pins both halves
(`tests/Unit/DocCompliancePersistenceTest.php:52-54`, `:106`).

**Does publish freeze them? Yes, deliberately and with a comment saying why:**

```php
// Frozen, not referenced: the layers that applied AT PUBLISH TIME.
// A later authority revision must not retroactively change what a
// already-issued certificate was validated against.
'complianceLayers' => $head['complianceLayers'] ?? [],   // Doc_template_service.php:692-695
```

Asserted by `DocTemplateServiceTest.php:373`.

**Reporting, never auto-action.** `Doc_compliance::affectedByAuthority()` lists templates whose
applied layer version is behind the authority's current version, and explicitly skips layers the
school excluded — *"a revision to a rule you are documented as not following changes nothing"*
(`Doc_compliance.php:103-108`). The class has no method that mutates a template
(`Doc_compliance.php:22-25`).

**Should an issuer-identity change invalidate them? Yes — and today nothing does.** The entire
`complianceLayers` array is a *consequence* of `sc.board`: the layers are the authorities
`resolveStack()` matched, and the matcher is `sc.board === "CBSE"`. Change the board from `CBSE` to
`STATE` and every `cbse` layer on every template of that school becomes an exclusion of an authority
that no longer applies, or an application of one that no longer applies — with no recomputation, no
report, and no flag. There is no equivalent of the verification-clearing logic that
`save_issuer_identity()` applies to `issuerIdentity.verification` (`School_config.php:553-573`). This
is the single most important consequence of the planned change and it is currently unhandled.

**Also broken today: `complianceBasis`.** `COLLECTION_SHAPES.md:79` and `:263` specify it as
`{board, state, stage}` — the frozen record of *which school circumstances* selected the layers.
Every reference in the code is a pass-through default (`Doc_template_service.php:256`, `:691`;
`Doc_templates.php:752`). **No code anywhere writes it.** So every published snapshot freezes
`complianceBasis: []`, and the one field that would let you answer "what board was this template
validated under?" is empty in every record. See §8 D3.

---

## 7 · Numbering and registers

**Inside the Document Engine: none.** `grep -rn "slNo\|bookNo\|serial\|allocat"` across
`application/libraries/Doc_*.php` and `Doc_templates.php` returns zero allocator code.
`doc.bookNo` / `doc.slNo` exist only as **merge-field keys with sample literals** (`"14"`, `"0207"` —
`doc_types.php:64-65`). `Doc_resolver` is explicitly forbidden from allocating, and
`DocResolverTest.php:86-102` asserts the class exposes no method that looks like issuing and
references no write.

**Declared but unbuilt:** `document_targets.php` names a distinct series per type — `tc`, `bonafide`,
`character`, `receipt`, `demand`, `staff_letter`, `payslip` (`:72, 99, 113, 135, 176, 194, 209`) —
with the warning that *"distinct series must never share a counter"* (`:52-55`). Every row is
`'wired' => false`.

**A real allocator exists, outside the engine.** `Sis::_get_tc_number()`
(`Sis.php:3374-3425`):

- `Firestore_service::nextSchoolCounter('tc', $current)` — atomic claim-doc CAS, seeded from the
  legacy `schools.tcCounter` mirror (`:3382`);
- returns `''` and **aborts issuance** rather than falling back to a possibly-stale mirror (`:3383-3398`);
- mirrors back to `schools.tcCounter`, monotonically, logging loudly on failure (`:3408-3421`);
- format: `TC-{6 alnum from school NAME}-{year}-{4-digit}` (`:3422-3424`).

**Does numbering depend on issuer identity? No — but it depends on the school's *display name*.**
`$schoolName` is the session school name, not `registeredName`. So the identifier baked into every
issued TC number is derived from an unvalidated, freely-editable profile field
(`School_config.php:411`), while `registeredName` — the field the Issuer Identity tab insists must be
recorded *"exactly as on the affiliation instrument"* — is used by nothing at all
(`grep -rn "registeredName" application assets` hits only `School_config.php` and the view). If a
school renames itself, its TC numbers change prefix mid-series and nothing notices.

The `document_targets.php` TC row already carries the collision warning in full (`:76-89`): a
document-only TC issued through a future Document Engine print point would either duplicate
`issue_tc()`'s side effects or — *"far worse, let a student be issued a Transfer Certificate while
remaining enrolled, authenticated and on the roster."*

---

## 8 · Defects found

Run on entry: `vendor/bin/phpunit --testsuite Unit --filter '(Doc|IssuerIdentity)'` →
**OK (506 tests, 1890 assertions)**. Every defect below is therefore invisible to the existing suite.
Standing baseline for the full run remains 4 failures + 27 skipped; not touched.

---

### D1 · `Doc_templates::_boundKeys()` reads a key name that does not exist — **HIGH**

```php
foreach ((array) ($o['content']['i18n'] ?? []) as $runs) {
    foreach ((array) $runs as $run) {
        if (!empty($run['field'])) { $keys[(string) $run['field']] = true; }
    }
}                                             // Doc_templates.php:955-967
```

The persisted run shape is `{f: "…"}`, not `{field: "…"}`. Proof:

- `Doc_serializer::runs()` reads `$r['f']` (`Doc_serializer.php:673-674`, and `:811-812` for tables);
- `designer.js` emits and reads `r.f` (`boundKeys()` `:1983-2003`, esp. `:1999`; `:2010`; `:2118`);
- `Doc_block_service::boundKeys()` — the correct implementation of the same idea, one file away —
  walks `$lang['runs']` then `$r['f']` (`Doc_block_service.php:261-278`);
- measured against the shipped starters: `tc_cbse` has **14 runs with `f`, 0 with `field`**.

The nesting is wrong too: `$runs` is `{runs: [...]}`, so the inner loop iterates the literal `runs`
array as if it were a single run. And unlike the client's `boundKeys()`, it never looks at table rows
or `repeatOver` (`designer.js:1983-2003`).

**Effect: `$bound` is always `[]`.**

### D2 · …which makes two of `validate()`'s four checks dead — **HIGH**

`Doc_templates::validate()` is documented as *"THIS is the one publish is gated on"*
(`Doc_templates.php:882-888`). Two of its four checks do nothing:

**Check 1 (required fields unbound)** iterates a **list**, not a map:

```php
foreach ($this->_contract()->keysFor($docType) as $key => $def) {
    if (!empty($def['required']) && !in_array($key, $bound, true)) {   // :906-907
```

`keysFor()` returns a bare list of key strings (`Doc_contract.php:105-124`, `return
$this->contracts[$docType];`). So `$key` is an integer index and `$def` is a string. Verified on this
PHP (8.5.4): `$s = "school.name"; !empty($s['required'])` evaluates to `false` with no warning and
no error. **The condition can never be true.**

**Check 4 (off-contract keys)** passes `$bound` as the required set:

```php
$r = $this->_contract()->validateBundle($docType, $this->_contract()->sampleBundle($docType, true), $bound);  // :942-944
```

and `validateBundle()` does `$required = $boundKeys === null ? array_keys($contract) : $boundKeys;`
(`Doc_contract.php:384`). With `$bound === []` the loop body never executes — no `offContract`, no
`unresolved`, no over-length warnings.

**Combined effect: the server's authoritative validation enforces only the line-height check and the
image-source warning.** The required-field gate that would demand `school.affiliationNo` on a CBSE
Transfer Certificate exists **only in the browser**, as a disabled button (`designer.js:3998`,
`:5780-5789`). And `publish()` never calls `validate()` at all
(`Doc_template_service.php:634-782`) — so the browser gate is not merely weak, it is the whole gate.

### D3 · `complianceBasis` is specified, frozen, and never written — **MEDIUM**

Declared as `{board, state, stage}` (`COLLECTION_SHAPES.md:79`, `:263`), frozen into every snapshot
(`Doc_template_service.php:691`), and populated by nothing: all three code references are
`?? []` pass-throughs (`Doc_template_service.php:256`, `:691`; `Doc_templates.php:752`). Every
published version therefore records `complianceBasis: []`. **The field that would answer "which
board was this template validated under?" — the exact question an issuer-identity change makes
urgent — is empty in every record in production.**

### D4 · `validationResult` in every snapshot is a hardcoded "clean" — **MEDIUM**

`publish()` freezes `'validationResult' => $proof['validation'] ?? ['blocking'=>[], 'warnings'=>[]]`
(`Doc_template_service.php:696`). `recordProof()` applies the same default
(`Doc_template_service.php:624`). And `proof_pdf()` — the only caller — passes `hash`,
`fontManifest`, `mpdfVersion`, `pages`, `pdfPaths`, `perLanguage` and **no `validation` key**
(`Doc_templates.php:1113-1124`). So every snapshot ever written records *"no blocking findings, no
warnings"*, whether or not any validation ran.

### D5 · The unvalidated profile door can move the claim behind the ladder's back — **HIGH**

`save_issuer_identity()` is careful: a change to `affiliationBoard` or `affiliationNo` clears
`issuerIdentity.verification` and recomputes `issuerIdentity.level`
(`School_config.php:553-573`) — *"Editing the board or the number after a check would otherwise leave
a verification badge attached to a claim nobody checked."*

`save_profile()` writes **the same two Firestore keys** (`School_config.php:411-436` →
`Firestore_service.php:1031-1032`) and does **none** of that. It does not validate the number against
the board, does not clear `verification`, and does not recompute `level`.

So: reach `VERIFIED`, then change the affiliation number through the profile tab, and the school
keeps a green verified badge and a stale stored `level` against a number nobody ever checked. The
Issuer Identity tab is only aware of this because `_school_context()` and `get_config()` recompute
`levelOf()` live on read (`Doc_templates.php:345`, `School_config.php:231`) — the **stored**
`issuerIdentity.level` is the one that goes stale, and `Doc_compliance`-style consumers reading the
stored field would be wrong.

### D6 · An issuer field can be corrected but never cleared — **MEDIUM**

`Issuer_identity::validate()` copies only non-empty values into `$out`
(`Issuer_identity.php:214-305`), `save_issuer_identity()` writes `$doc = $fields`
(`School_config.php:564`) and merges it in (`:574`), and `Firestore_service::update` merges. `save_profile()` has the
same shape — *"Only include non-empty values to avoid overwriting existing data"*
(`Firestore_service.php:1035-1040`).

**Consequence: submitting an empty field is a no-op.** A school that typed a UDISE code into
`affiliationNo` — the exact misfile the whole library exists to catch — cannot remove it. It can only
be overwritten with another value that passes the board's pattern. For a school that is genuinely
`UNAFFILIATED`, that is impossible: `validate()` rejects any number for that board
(`Issuer_identity.php:226-227`), so the wrong number stays on the document forever. And it prints:
`views/result/templates/cbse.php:46`.

### D7 · The board is the *only* gate on which law applies, and it is free text from an unvalidated form — **HIGH (pre-existing, restated)**

This is the pathology `Issuer_identity`'s own docblock describes (`:15-35`). Traced end to end in §1:
`save_profile` (no pattern check, `:411`) → `affiliationBoard` → `complianceBoard()` (`:396`) →
`sc.board` (`designer.js:6164`) → `appliesWhen` (`:238`) → `requiredKeys` → the publish gate. A
one-character typo in `affiliation_board` on the profile tab silently drops a CBSE school to
`PROFILES.generic` and zero required fields, with no signal anywhere. `school_config/index.php:441`
is the input in question.

### D8 · Client `boundKeys()` ignores `header.objects` / `footer.objects` — **LOW (latent)**

`designer.js:1983-2003` walks `S.tpl.objects` only. `Doc_templates::_objects()` merges
`objects + header.objects + footer.objects` (`:969-977`), and `publish()` freezes all three
(`Doc_template_service.php:683-685`). Every shipped starter puts its letterhead in `objects` — the
`school.affiliationNo` binding is on object `h_addr` in `objects` for all nine starters that carry it
— so the divergence is latent. The moment anyone uses the header region, the client gate will report
required fields as unbound that are in fact bound.

### D9 · TC numbers are derived from the editable display name, not `registeredName` — **MEDIUM**

`Sis::_get_tc_number()` builds the prefix from `substr($schoolName, 0, 6)`
(`Sis.php:3422-3423`). `registeredName` — the field the Issuer Identity tab demands *"exactly as on
the affiliation instrument"* — is read by no code outside `School_config` and its view. A profile
rename silently re-prefixes an in-flight statutory number series.

---

## 9 · UNVERIFIED

Recorded rather than asserted. Each line names what would have to be read to close it.

1. **Whether Firestore security rules gate any of this.** Not examined. `firestore.rules` /
   `firebase-rules/` were out of scope for this pass, and per CLAUDE.md production can hold rules no
   checkout has. Would need `node aegis/cli.js rules status` plus the `documentTemplates` /
   `documentTemplateVersions` / `schools` match blocks. **This is the only remaining place a
   `mayIssue`-equivalent could be enforced, and it is unchecked.**
2. **Whether the Teacher or Parent apps render anything carrying issuer identity.** Not examined —
   they are separate repos (`AndroidStudioProjects/ZenXII_Teacher`, `ZenXII_Parent`). Would need a
   grep for `affiliation` / `documentTemplate` in both.
3. **Whether Cloud Functions write `affiliationBoard` / `affiliationNo` or emit them in claims.**
   `~/Desktop/Zennxii_adminPanel/functions` not read. A third writer would change D5's blast radius.
4. **Live data.** No Firestore read was performed. `DocIssuerBasisTest`'s docblock asserts *"Six of
   the nine schools in this project have no recorded board"* (observed on `SCH_B56BB9A401`), which if
   still true means §5's empty-board path is the majority case — but that is a claim in a comment,
   not something this pass measured.
5. **Whether `Doc_templates::validate()` is reachable at all from the client.** The client has its own
   `validate()` (`designer.js:3986`) and I found no `srv.validate(...)` call. So D2's dead checks may
   be dead code in an endpoint nobody calls — which changes the *severity* but not the *fact*, since
   the endpoint is the documented server-side gate. Would need a grep of `srv.*` definitions around
   `designer.js:5600-5700`.
6. **Whether `uploads/.htaccess` actually denies `.pdf` in production.** `version_pdf()`'s security
   model depends on it (`Doc_templates.php:596-599`); `.htaccess` is not in git per
   `PATH_A_US_SERVER_RUNBOOK.md`.
7. **The `qa/certificates` UAT harness and `tests/doctemplates/_zxdt_e2e.js`.** Skimmed only for
   `AUTHORITIES` references. Whether E2E coverage would catch D1/D2 is unestablished.
8. **`Doc_presence`, `Doc_seeder`'s starter-gating beyond `eligible()`, and the custom-doctype path
   (`Doc_contract::isCustom`).** Read only as far as needed for the issuer trace.
