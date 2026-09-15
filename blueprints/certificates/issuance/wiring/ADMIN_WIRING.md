# Admin-panel wiring of school issuer identity

Read-only research, branch `yug_testing`, 2026-09-15. **No code changed.** Every claim below is
cited `file:line` and was confirmed by reading the code; anything not confirmed is marked
**UNVERIFIED**.

Scope: `application/**`, `assets/js/**` (`application/cache/**` excluded — it holds serialized
Firestore snapshots that pollute every grep), plus a confirming sweep of `functions/`, `scripts/`,
`tools/`, and both Android apps.

**The headline:** there are **three** doors writing `affiliationBoard` / `affiliationNo`, not two.
The brief names the Profile and Issuer tabs; `Schools::edit_school` is a third, and it is the least
validated of the three.

**Companion document:** `LEDGER_CONSTRAINTS.md` in this same folder covers the lock/CAS and TOCTOU
angle (BUG-028's class) on the same two endpoints. This document is the wiring map; that one is the
concurrency analysis. They agree on §4.

---

## §0 · Blast radius, stated up front

| Surface | Reads these keys? | Evidence |
|---|---|---|
| Admin panel PHP + JS | **Yes — the whole blast radius lives here** | this document |
| Cloud Functions (`functions/`) | **No** | zero hits for all 9 keys |
| ZenXII_Teacher app | **No** | zero hits across `app/src` |
| ZenXII_Parent app | **No** | zero hits across `app/src` |
| Firestore rules | Not field-level | `firebase-rules/firestore.rules` — `match /schools/{docId}`: `allow write: if isSuperAdmin()`; panel writes use a service account and bypass rules entirely |

This is the single most plan-relevant fact in the document: **the merge is confined to one repo and
one surface.** No app rebuild, no Cloud Function deploy, no rules change is implied by it.

---

## §1 · Readers

### 1a · The two keys that matter (`affiliationBoard`, `affiliationNo`)

| # | file:line | What it does | Breaks on rename/merge? |
|---|---|---|---|
| R1 | `application/controllers/School_config.php:98-99` | `get_config` → `profile.affiliation_board` = `affiliationBoard ?? board`; `profile.affiliation_no` = `affiliationNo` | **Yes** — this is the Profile tab's read half of the defect |
| R2 | `application/controllers/School_config.php:222-223` | `get_config` → `issuer_identity.affiliationBoard` / `.affiliationNo`. Note: **no `?? board` fallback here** — the two tabs read the same key through different fallback rules | **Yes** |
| R3 | `application/libraries/Issuer_identity.php:323,330` | `levelOf()` — board must be a key of `BOARDS`; number must match that board's regex. Anything else ⇒ level 0 | **Yes — shape-sensitive.** A merge that widens the accepted board vocabulary silently drops every school to `UNRECORDED` |
| R4 | `application/libraries/Issuer_identity.php:396` | `complianceBoard()` = `strtoupper(trim(affiliationBoard ?? board))`, returns `''` if not in `BOARDS` | **Yes — highest consequence.** See §3 note |
| R5 | `application/controllers/Doc_templates.php:350` | `_school_context()['board']` ← `complianceBoard($doc)` — selects the statutory compliance layer | **Yes, transitively via R4** |
| R6 | `application/controllers/Result.php:1922-1923` | `_load_school_info_fs()` → `'AffNo' => $d['affiliationNo'] ?? ''`, `'Board' => $d['affiliationBoard'] ?? ($d['board'] ?? '')` | **Yes** |
| R7 | `application/views/result/templates/_report_card_data.php:66-67` | `$schoolAffNo = $schoolInfo['AffNo'] ?? $schoolInfo['affiliation_no'] ?? ''`; `$schoolBoard = $schoolInfo['Board'] ?? ''` | Yes — shared shim for all six report-card templates |
| R8 | `application/views/sis/tc_print.php:32-33` | `$schoolAffNo = $sp['affiliationNo'] ?? ''`; `$schoolBoard = $sp['affiliationBoard'] ?? ''` | **Yes** |
| R9 | `application/controllers/Schools.php:260-261` | SA registry rows: `'Affiliated To' => $fsSchool['affiliationBoard'] ?? $fsSchool['board'] ?? ''` | Yes |
| R10 | `application/controllers/Schools.php:487` | A second, separate build of the same two columns | Yes |
| R11 | `application/controllers/Schools.php:354-355` | `fieldMap` for the edit form — doubles as the read side of writer W3 | Yes |
| R12 | `application/views/school_config/index.php:1429,1434` | JS renders `ii.affiliationBoard` into the select, `ii.affiliationNo` into `ii_affiliation_no` | Yes |
| R13 | `application/views/school_config/index.php:1600-1607` | `renderProfile()` fills `pf_affiliation_board` / `pf_affiliation_no` from `profile.*` | Yes — **and this is the staleness carrier, see §4** |

**`_report_card_data.php:66` carries a dead alias.** `$schoolInfo['affiliation_no']` (snake) is never
emitted by `Result.php:1922`, which uses `'AffNo'`. Harmless, but it is a false lead for anyone
grepping for snake-case readers.

**`Result.php:1923` has a null-coalesce trap.** `$d['affiliationBoard'] ?? ($d['board'] ?? '')` — `??`
catches only `null`, not `''`. A school whose `affiliationBoard` is present-but-empty loses its
legacy `board` fallback and prints no board. Neither the Profile nor the Issuer door can currently
write `''` (both skip empty values), but `Schools::edit_school` (W3) also skips empties, so this is
latent rather than live. A merge that starts writing `''` to clear a field would activate it.

### 1b · The Issuer-only keys

`udiseCode`, `registeredName`, `headOfInstitution`, `headSince`, `reviewMonths`, `recognitionOrder`
have **exactly one reader each** — `School_config.php:224-229` (`get_config`) — plus
`Issuer_identity::levelOf()` for `registeredName` (`:335`), `headOfInstitution` (`:336`) and
`reviewMonths` (`:345`).

**None of them is printed anywhere.** See §3. This means the merge can reshape them almost freely;
the risk is entirely concentrated in `affiliationBoard` / `affiliationNo`.

### 1c · `verification` (nested `evidencePath` / `verifiedBy` / `verifiedOn`)

| file:line | Role |
|---|---|
| `School_config.php:216-217` | reads `issuerIdentity.verification` into the get_config payload |
| `School_config.php:231-232` | feeds `levelOf()` / `mayIssue()` |
| `Doc_templates.php:342-345` | same, for the certificate catalogue |
| `Issuer_identity.php:344-350` | the only consumer — gates levels 2 and 3 |

**There is no writer.** See D3 in §7.

`Doc_compliance.php:147,168,176` uses the identical names (`verifiedOn`, `reviewMonths`) on a
**different object** — a compliance-*authority* record from the corpus, with its own independent
`isStale()` at `Doc_compliance.php:166-178`. Two implementations, same field names, same semantics,
unrelated data. This is exactly the name-collision footgun CLAUDE.md warns about; see D6.

### 1d · Profile-side keys

| Key | Readers |
|---|---|
| `name` / `schoolName` | `School_config.php:97`, `Result.php:1917`, `tc_print.php:29`, `Doc_templates.php:348` |
| `principal` | `Result.php:1928` (`'Principal'`), `School_config.php:104`, and **`School_config.php:226` as the fallback for `headOfInstitution`** |
| `state` | **`Doc_templates.php:349`** — gates the state-specific certificate types (Kerala Form 5A, A.P. Study Certificate); `Result.php:1920`; `Issuer_identity.php:247` (see D2) |
| `city`, `pincode` | `School_config.php:100,106`, `Result.php:1918,1921`, `cbse.php:40` |
| `display_name` | **Not a Firestore key at all** — a POST field mapped to `name` at `Firestore_service.php:1018`, and a session key at `School_config.php:471`. `Admission_public.php:62,132,1404` reads `$schoolDoc['display_name']` defensively, for a key nothing ever writes |

---

## §2 · Writers

| # | file:line (write) | Keys | Validates? | Semantics | RBAC | CSRF |
|---|---|---|---|---|---|---|
| **W1** | `School_config.php:462` → `fs->saveSchool($data)` → `Firestore_service.php:1088` | `affiliationBoard`, `affiliationNo`, `name`, `schoolName`, `address`, `street`, `city`, `state`, `pincode`, `phone`, `email`, `website`, `principal`, `establishedYear` | **Length only.** `affiliation_board` ≤100, `affiliation_no` ≤100 (`School_config.php:422-427`). No board whitelist, no number regex | merge, skips empties (`Firestore_service.php:1036-1040`) — cannot clear a field | `_require_role(ADMIN_ROLES, …, 'Configuration', 'edit')` `:407` | CI3 cookie CSRF (not excluded) |
| **W2** | `School_config.php:574` → `fs->update('schools', …)` | `affiliationBoard`, `affiliationNo`, `udiseCode`, `registeredName`, `headOfInstitution`, `headSince`, `recognitionOrder`, `reviewMonths`, `issuerIdentity` | **Fully** — `Issuer_identity::validate()` `:205-307` | merge at top level; `recognitionOrder` and `issuerIdentity` are **whole-map replacements**, never deep-merged | `_require_role(ADMIN_ROLES, …, 'Configuration', 'edit')` `:508` | CI3 cookie CSRF |
| **W3** | `Schools.php:236` → `fs->set('schools', $schoolId, $patch, true)` | `affiliationBoard`, `affiliationNo`, `name`, `schoolName`, `address`, `street`, `phone`, `mobileNumber`, `email`, `website`, `logoUrl`, calendars | **NONE.** Only `!empty($newSchoolName)` `:156` and a per-field `!== ''` `:225`. Global XSS filter is the sole sanitation | merge, skips empties | raw role-string check `in_array($this->admin_role, ['Super Admin','School Super Admin'])` `:126` — **not** `_require_role`, so it bypasses the graded-RBAC deny logic | plain multipart form POST, CI3 CSRF |
| W4 | `B2_registry_service.php:1492` (`merge => false`) ← `Superadmin_schools.php:317` `onboard()` | `name`, `schoolName`, `city`, `street`, `address` — **no issuer keys, no `state`** | name charset, emails, plan/expiry/session formats; `city`/`street` raw | **full replace.** Safe only because uniqueness gates block re-entry (`:1420-1450`) | SA session only | `superadmin/schools(.*)` is in `csrf_exclude_uris` `config.php:186`; covered instead by `MY_Superadmin_Controller::_verify_csrf` `:148-161` |
| W5 | `Superadmin_schools.php:651` → `B2_registry_service.php:1121` | `city`, `address`, `street`, `email`, `phone`, `logoUrl` — no issuer keys | email/URL only; `city`/`street` raw | merge, **does not skip empties** — an empty city blanks `city` | SA session only | as W4 |
| W6 | `School_config.php:726` `upload_logo` → `saveSchool` | no scoped key; incidentally touches `schoolCode`, `status`, maybe `currentSession` | n/a | merge | `:670` | CI3 CSRF |
| W7 | `scripts/sa_b2_schools_backfill.js:300` | `name`, `schoolName`, `city`, `street` — no issuer keys | none, coercion only | `merge:true`, gap-fill only (`:263-273`), **dry-run unless `--commit`** | CLI + service account | n/a |
| — | `Entity_firestore_sync.php:112` | would write `name`, `city`, `state`, `principal`, **`board`** | none | merge | — | — |

**W-dead:** `Entity_firestore_sync::syncSchool` has **zero callers** repo-wide. Its doc-comment at
`:61` claims `School_config::save_profile()` and `Superadmin_schools::onboard()` call it; **that
comment is false.** It is the only writer of the legacy `board` key, which is therefore write-dead
and read-only-fallback everywhere.

**Merge semantics are uniform and worth stating once:** `Firestore_service::set(merge=true)` `:317`
and `::update()` `:336` both PATCH with an `updateMask` over **top-level** keys
(`Firestore_rest_client.php:1218-1221`, `:1280`). Nested maps are replaced wholesale. The only
full-document write to `schools` is W4.

---

## §3 · Printed to a human

### 3a · Report cards — all six templates, not just CBSE

Chain: `Result.php:1911` `fs->get('schools', $this->school_id)` → `:1922-1923` →
`_report_card_data.php:66-67` → each template.

| Template | Line | Exact output |
|---|---|---|
| `cbse.php` | 44 | `'Affiliated to ' . htmlspecialchars(strtoupper($schoolBoard))` |
| `cbse.php` | **45** | `'Aff. No: ' . htmlspecialchars($schoolAffNo)` |
| `cbse.php` | 53 | board badge — `<div class="cb-badge-name">…strtoupper($schoolBoard)…</div>` over `<div class="cb-badge-sub">AFFILIATED</div>` |
| `classic.php` | 60-61 | `$metaParts[] = htmlspecialchars($schoolBoard)` / `$metaParts[] = 'Aff. No: ' . htmlspecialchars($schoolAffNo)` |
| `minimal.php` | 42-43 | same two `$metaParts` lines |
| `modern.php` | 44-45 | `<span class="md-pill">…$schoolBoard…</span>` / `<span class="md-pill">Aff: …$schoolAffNo…</span>` |
| `elegant.php` | 55 | `<em>Affiliation No: <?= htmlspecialchars($schoolAffNo) ?></em>` |
| `professional.php` | 48 | `'Affiliated to ' . strtoupper($schoolBoard)` … `'Affiliation No: ' . $schoolAffNo` |

Empty behaviour: every template guards with `if ($schoolBoard || $schoolAffNo)` — an unrecorded
value prints **nothing**, never a placeholder and never a fallback literal. Good.

**The board badge is the sharp edge.** `cbse.php:51-54` (and the equivalents in `classic`, `minimal`,
`modern`, `elegant`) renders the raw board string inside a badge captioned `AFFILIATED`. Whatever
free text reaches `affiliationBoard` is presented to a family as a certified affiliation.

### 3b · Transfer Certificate

`Sis.php:1751` `fs->get('schools', $this->school_id)` → `$data['school_profile']` →
`tc_print.php:32-33`.

The file's own header comment (`tc_print.php:10-27`) documents that this block previously read
snake_case keys, so six of eight lookups silently missed — and that **the board used to fall back to
the literal `'C.B.S.E'`**, printing a false affiliation on a statutory document. That fallback was
removed deliberately:

> THE BOARD NO LONGER DEFAULTS. It used to fall back to the literal 'C.B.S.E', so a school that had
> never recorded a board printed a false affiliation on a statutory document. An unrecorded board now
> prints nothing, because a certificate that asserts an affiliation the school does not hold is worse
> than one that asserts none.

**This is precedent the merge must not undo.** Any "helpful" fallback reintroduced during the merge
re-creates a known, deliberately-fixed defect.

Output lines, `tc_print.php:450-465`:

| Line | Output | Notes |
|---|---|---|
| 453 | `<div class="school-affil">AFFILIATED TO CENTRAL BOARD OF SECONDARY EDUCATION</div>` | **A hardcoded literal — but correctly gated.** Reached only inside `if ($schoolBoard)` `:450` and `stripos($schoolBoard,'cbse') \|\| stripos($schoolBoard,'central board')` `:451-452`. The comment at `:445-448` justifies it: CBSE's SOP of 04.02.2020 I(b) *prescribes* that exact wording on a CBSE school's letterhead. Not the removed `'C.B.S.E'` default. |
| 455 | `AFFILIATION NO. <?= htmlspecialchars($schoolAffNo) ?>` | suppressed when empty `:454` — so a TC can assert CBSE affiliation **with no number** |
| 458 | `Affiliated to <?= htmlspecialchars($schoolBoard) ?>` | non-CBSE branch |
| 460 / 464 | `Affiliation No: <?= htmlspecialchars($schoolAffNo) ?>` | suppressed when empty |
| **482** | `<span>School Aff.No. <span class="dotted"><?= htmlspecialchars($schoolAffNo) ?></span></span>` | **unconditional** — an empty value still prints the label over a blank dotted rule |
| 695 | `<div class="sig-title">Principal</div>` | hardcoded literal; reads neither `principal` nor `headOfInstitution` |

**The `stripos` substring test at `:451` is the merge's sharpest dependency on the board *string*.**
Not on the key, on its value. Anything matching `cbse`/`central board` gets the statutory wording.
A merge that normalises board values to the `BOARDS` catalogue keys keeps `'CBSE'` matching; one that
blanks a non-conforming value like `"CBSE Delhi"` silently drops that school from the prescribed
wording to nothing. See §8 risk 3.

**And `tc_print.php:33` omits the legacy fallback that two other readers have.** It is
`$sp['affiliationBoard'] ?? ''` — no `?? $sp['board']`, unlike `Result.php:1923` and
`Schools.php:365`. A school carrying only the legacy `board` key prints an affiliation on its
marksheet and none on its TC. See D12.

### 3c · SA registry, school profile and edit form

Four separate renames of the same two keys, each with different empty-value behaviour:

| Screen | Build | Render | Empty shows |
|---|---|---|---|
| SA registry | `Schools.php:487` `(string) ($doc['affiliationBoard'] ?? $doc['board'] ?? '')` | `manage_school.php:135` `<td …><?= htmlspecialchars($school['Affiliated To'] ?? 'N/A') ?></td>` | **an empty cell, never `N/A`** — see D11 |
| SA registry | `Schools.php:484` `'School Principal'` | `manage_school.php:134` | same defect |
| School Profile | `Schools.php:354-355` `fieldMap`, legacy fallback `:365-367` | `schoolprofile.php:230-231` `<div class="sp-info-label">Affiliated To</div>` / `<div class="sp-info-val">…$affiliated…</div>`; `:236-237` `Affiliation Number`; `:119-120` hero tag | **`—`** (`schoolprofile.php:66-67` use `?: '—'`) |
| Edit form | `Schools.php:260-261` | `edit_school.php:61,68` `value="<?php echo isset($schooll['Affiliated To']) ? $schooll['Affiliated To'] : ''; ?>"` | blank input — **and unescaped, see D10** |

### 3c-bis · The config screen previews a false affiliation

`school_config/index.php:4020` — the report-card preview renders

```
<div class="rcp-sub">123 Education Road, City &nbsp;|&nbsp; Affiliated to CBSE</div>
```

It reads **no school key**. Every school, whatever its board, sees `Affiliated to CBSE` in the
preview on the very screen where the board is configured. Cosmetic, but actively misleading in
exactly the place the merge is meant to clarify.

### 3d · Certificates — declared but **not wired**

`application/config/doc_types.php:61` declares the binding token:

```
'school.affiliationNo' => ['label' => 'Affiliation number', 'sample' => '3430006', 'maxLen' => 16],
```

and `doc_starters.php` binds it in nine places (`:138, 630, 643, 1265, 1278, 1839, 2285, 2298, 2732`),
including the shared letterhead — `Doc_block_service.php:33,255` notes every doc type whose starter
uses that block therefore binds it. Five doc types require it (`doc_types.php:184-211`).

**The resolver exists, but no real-data path reaches it.** `Doc_serializer::resolve()`
`application/libraries/Doc_serializer.php:696-727` is the binder (**not** `Doc_renderer.php`, which
contains zero references to `school` or `affiliation` — I checked there first and was wrong):

```php
if ($sample !== false) {
    $def = $this->contract[$key] ?? null;
    ...
    $v = ($sample === 'p95') ? ($def['p95'] ?? $def['sample'] ?? null) : ($def['sample'] ?? null);
    ...
    return (string) $v;
}
if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
    throw new RuntimeException(
        "Doc_serializer: no value resolved for '$key' (object '{$o['id']}'). "
        . 'Refusing to print a blank where a field belongs.');
}
return (string) $data[$key];
```

The real-data branch reads `$data['school.affiliationNo']` and **throws rather than printing a
blank** — a good rule. But **both call sites pass `[]` as `$data` with a sample mode set**:

- `Doc_templates.php:1008` — `$this->docser->render($tpl, [], $lang, ['sample' => $mode, …])` (preview)
- `Doc_templates.php:1092` — `$this->docser->render($tpl, [], $lang, ['sample' => 'p95', …])` (proof PDF)

So today `school.affiliationNo` always resolves to the contract's literal sample, **`'3430006'`**
(`doc_types.php:61`). `Doc_templates::_school_context()` `:318-357` — the one function that *does*
read the school document — returns `name`, `state`, `board`, `stage`, `issuer` and **does not expose
`affiliationNo` at all**, so there is no half-finished wiring to complete. `designer.js:1954` does the
same thing client-side.

Two consequences, stated separately because they have different weight:

1. **The merge cannot break certificate output today** — no certificate reads the real key. Renaming
   it breaks nothing visible now, and everything later, at the moment the print point is wired. If
   the key is renamed, `doc_types.php:61`, the nine `doc_starters.php` bindings and `designer.js:42`
   must change in the same commit.
2. **Preview and proof are specimen renders by construction**, and `'p95'` is explicitly *"the hard
   case, not the flattering one"* (`Doc_templates.php:1093`). That is defensible for a specimen. It
   is worth flagging only because the proof PDF is written to
   `uploads/{schoolId}/doctemplates/_proofs/…` and hash-sealed into the published version record
   (`Doc_templates.php:1097-1103`) — so the seal attests to a document containing `3430006`. Whether
   that is correct depends on whether a proof is a specimen or an issued artefact. **UNVERIFIED** —
   `FINAL_BLUEPRINT.md` would settle it. Out of scope for the merge either way.

### 3e · Never printed — confirmed by exhaustive sweep

`udiseCode`, `registeredName`, `headOfInstitution`, `headSince`, `recognitionOrder`,
`verification` — **no print surface at all.**

Swept and clear: all six report-card templates; `views/result/{marks_sheet,class_result,cumulative,student_result,report_card,batch_report_cards}.php`;
`views/sis/{tc_list,documents,id_card}.php` (`Sis.php:2424-2430` builds the ID-card school block from
`name`/`address`/`logoUrl`/`phone` only, deliberately no affiliation); `views/fees/receipt.php`
(`:384` name, `:578` the literal `Principal / Head`); `views/events/circular.php`; `Hr.php` payslips
(`:2642`, `:6267` — name only); `Accounting.php` Excel/PDF exports (`:3938`, `:4010` — name only).

**No export carries affiliation data.** `Schools.php` has no `fputcsv`/PhpSpreadsheet/`text/csv` — the
"export" near `:487` is the array build feeding the HTML table. The one real school-identity CSV,
`Superadmin_analytics.php:637-654` (`fputcsv` at `:914-915`), emits School ID, Name, Code, Domain,
City, Region/State, Created At, Primary SSA, Plan Family, Plan Name, Lifecycle State, Admin Disabled,
Is Test Tenant — **no affiliation, UDISE, recognition, registeredName or head-of-institution column.**

Also swept clear: every `Recognition` hit in `Attendance.php` and `Play_integrity.php:73-74` is *face*
recognition, and every `Principal` hit in `Sis.php:31`, `Staff.php:30`, `Hr.php:27`,
`Accounting.php:34` and `rbac_helper.php` is the RBAC role string. False positives, all confirmed.

The Issuer tab tells the operator otherwise (`school_config/index.php:749-751`):

> Transfer &amp; other certificates &middot; result cards and marksheets &middot; the school registry

That is true of `affiliationBoard`/`affiliationNo` and false of everything else on the tab. See D5.

Note the asymmetry that matters most for the merge: **`principal` prints, `headOfInstitution` does
not.** `Result.php:1928` emits `'Principal' => $d['principal']`; the report cards' signature block
uses `$rcPrincipalName` from `reportCardConfig` (`_report_card_data.php:206`), falling back to the
literal `'Principal'`. `headOfInstitution` feeds only `levelOf()`. So the Profile tab owns the name
that actually appears on documents, while the Issuer tab owns the name that gates the ladder.

---

## §4 · The save-path collision — the key question

**Answer: yes. Both tabs write the same two keys on the same document, and the Profile tab wins
without validating. There is also a third door that is worse than either.**

### 4a · They are the same document

- `get_config` reads `fs->get('schools', $this->fs->schoolId())` `:57`
- `save_issuer_identity` reads and writes `fs->get/update('schools', $this->school_id)` `:556, :574`
- `save_profile` writes `fs->saveSchool()` → `set(SCHOOLS, $this->schoolId, …, merge=true)`
  `Firestore_service.php:1088`

`MY_Controller:310` calls `$this->fs->init($this->school_id, …)` and `Firestore_service:144` assigns
`$this->schoolId = $schoolId`. **`fs->schoolId() === $this->school_id` identically** — the mixed usage
is a style inconsistency, not a bug. One document, `schools/{schoolId}`.

### 4b · They write the same keys

`save_profile` posts `affiliation_board` / `affiliation_no`. `Firestore_service::saveSchool` maps
them at `:1031-1032`:

```php
'affiliationBoard'  => $data['affiliation_board'] ?? $data['affiliationBoard'] ?? null,
'affiliationNo'     => $data['affiliation_no'] ?? $data['affiliationNo'] ?? null,
```

— the exact camelCase keys `save_issuer_identity` validates and writes. **Confirmed collision, in
code, not by inference.**

### 4c · Last-writer-wins, and the Profile door has no rule

`save_profile` applies a 100-byte cap and nothing else (`School_config.php:422-427`). The form field
is `<input type="text" id="pf_affiliation_no" maxlength="60">` (`index.php:442`) labelled
`Affiliation / DISE No.` — one input named for two identifiers from two authorities. The Issuer
field is `maxlength="40"` behind a board-specific regex (`Issuer_identity.php:222-238`) and a
`<select>` restricted to the `BOARDS` catalogue (`index.php:677`).

So the Profile tab can write a value the Issuer tab would have refused, over the top of one it
accepted. **Saving the Profile tab silently overwrites what the Issuer tab validated.**

### 4d · The integrity guard is bypassable — this is the real defect

`save_issuer_identity:555-563` exists precisely to stop a claim drifting away from its verification:

```php
$claimMoved =
    (($existing['affiliationBoard'] ?? '') !== ($fields['affiliationBoard'] ?? …)) ||
    (($existing['affiliationNo']    ?? '') !== ($fields['affiliationNo']    ?? …));
$verification = $claimMoved ? [] : ($prior['verification'] ?? []);
```

with the comment *"A CHANGE TO THE CLAIM INVALIDATES WHAT VERIFIED IT."*

**`save_profile` performs neither half.** It does not clear `issuerIdentity.verification` and it does
not recompute `issuerIdentity.level`. Nor does `Schools::edit_school` (W3). A claim changed through
either of the other two doors keeps a verification badge attached to a claim nobody checked, and a
stored `level` that can disagree with the stored claim.

**Mitigating fact, verified:** the displayed level is recomputed live on every read —
`School_config.php:231` and `Doc_templates.php:345` both call `Issuer_identity::levelOf($identityDoc)`
against the current document. **Nothing reads the stored `issuerIdentity.level`.** So today the
stored value is write-only drift. The `verification` clobber-survival is the part with teeth — and,
per D3, it is currently unreachable because nothing ever sets `verification` in the first place.

### 4e · The silent-revert path (no bad intent required)

This one needs no one to type anything wrong.

1. `loadConfig()` calls `renderProfile(d.profile)` **and** `renderIssuerIdentity(d.issuer_identity)`
   (`index.php:1386-1387`) — both tabs are filled from the same payload, on the same page, at page
   load.
2. Operator fixes the affiliation on the Issuer tab and saves. `saveIssuerIdentity()`'s success
   handler (`index.php:1576-1578`) updates only `ii_msg` and the ladder. **It does not re-render the
   Profile tab and does not reload config.**
3. `pf_affiliation_board` / `pf_affiliation_no` still hold the page-load values.
4. Operator edits something unrelated on the Profile tab — a phone number — and saves.
   `saveProfile()` (`index.php:1624-1630`) posts **every** non-empty `pf_*` field, including the two
   stale ones.
5. The corrected affiliation is reverted. No error, no warning, nothing in the log.

Worked example using the data the repo itself cites (`git show 94de755`): a school storing
`09310113101` — a UP UDISE code — in the affiliation field. The operator moves it to `ii_udise_code`
and enters a real 7-digit CBSE number. One later Profile save puts `09310113101` back into
`affiliationNo`, and `levelOf()` `:330-333` drops the school to `UNRECORDED` because 11 digits fail
the CBSE 7-digit pattern.

### 4f · Neither door can clear a field

`save_profile` skips empties client-side (`index.php:1628`) and server-side
(`School_config.php:430`). `Issuer_identity::validate()` omits absent fields from `$out`, and `update`
only masks keys present in the payload. So **blanking an input saves nothing and the old value
reappears on reload.** Not a data-loss bug, but a confusing one, and the merge must decide
deliberately whether the single field gains the ability to clear.

---

## §5 · RBAC and CSRF

### RBAC — the two tabs are gated **identically**

| Path | Gate |
|---|---|
| constructor | `require_permission('Configuration')` `School_config.php:46` |
| `get_config` | `_require_role(ADMIN_ROLES, …, 'Configuration', 'view')` `:69` |
| `save_profile` | `_require_role(ADMIN_ROLES, …, 'Configuration', 'edit')` `:407` |
| `save_issuer_identity` | `_require_role(ADMIN_ROLES, …, 'Configuration', 'edit')` `:508` |

`ADMIN_ROLES = ['Super Admin','School Super Admin','Admin','Principal']` `:30`.
`_require_role` (`MY_Controller:1230-1268`) short-circuits for Super Admin / School Super Admin,
then consults `has_permission($module, $level)`, treating the graded map as authoritative on a
lower-level hold and falling back to the role-name list only for users who do not hold the module.

**Consequence for the plan: merging the two fields changes nothing about who can edit them.** Same
module, same level, same role list. There is no permission to reconcile.

**The exception is W3.** `Schools::edit_school:126` uses a raw
`in_array($this->admin_role, ['Super Admin','School Super Admin'])`, not `_require_role`, so it is
outside the graded-RBAC system entirely, and its constructor `require_permission('Configuration')`
(`Schools.php:31`) defaults to level `'view'` (`rbac_helper.php:294`). A different gate on the same
data.

### CSRF

`school_config/*` and `schools/*` are **not** in `csrf_exclude_uris` (`config.php:184-214`), so
standard CI3 cookie CSRF applies to W1, W2 and W3. **A merged endpoint under `school_config/` needs no
`csrf_exclude_uris` entry** — and must not be given one.

The shared helper `post()` (`index.php:1325-1330`) re-reads the token from the cookie on every call
and sends it both as a body field and as `X-CSRF-Token`, absorbing any rotated
`d.csrf_token` at `:1373`. `save_issuer_identity`'s hand-rolled 422 envelope correctly includes
`'csrf_token' => $this->security->get_csrf_hash()` (`:541`), and the helper's non-JSON/403 branches
(`:1355-1365`) handle the failure modes. **This path is sound** — no blank-403 trap here.

The SA panel is the asymmetry: `superadmin/schools(.*)` **is** excluded (`config.php:186`) and relies
solely on `MY_Superadmin_Controller::_verify_csrf` `:148-161`.

---

## §6 · Caching and staleness

| Layer | file:line | Behaviour | Merge risk |
|---|---|---|---|
| Per-request memo | `Firestore_rest_client.php:651-671` | in-process `_readCache`, cleared on every write (`:1212`, `:1282`) | none |
| **Cross-request file cache** | `Firestore_rest_client.php:94-98, 694-724` | `schools` is config-cached for **300 s** in `application/cache/fsdoc/{md5}.cache`; busted on `setDocument` `:1212` and `updateDocument` `:1282` | **Low but real.** Busting is per-machine and per-`APPPATH`. Only one prod box, so W1/W2/W3 all bust it. A write from outside this PHP install would be invisible for up to 5 minutes |
| Dashboard cache | `Firestore_service.php:353-395` | `schools` is on the watchlist; any write busts it | none |
| PHP session | `School_config.php:471` | `save_profile` sets `school_display_name` for the acting user only; other signed-in admins keep the old name until re-login | not issuer-related, but the same "only the actor's session updates" pattern would apply to anything the merge caches |
| **JS-side copy** | `index.php:1382` `CFG = d` | the whole `get_config` payload is held in a page-scoped global and never refreshed after a partial save | **This is the §4e revert mechanism.** The staleness that matters is not server-side |

`DISABLE_DOC_CACHE` (`:694`) turns the file cache off for debugging.

---

## §7 · Defects found

### D1 · A **third**, entirely unvalidated writer of the affiliation pair — **HIGH**

`Schools.php:236` writes `affiliationBoard` and `affiliationNo` from POST fields
`'Affiliated To'` / `'Affiliation Number'` (map at `:221-222`) with **no validation of any kind** —
no board whitelist, no number regex, no length cap, no UDISE detection. The only checks are
`!empty($newSchoolName)` `:156` and `!== ''` `:225`.

The rationale comment on `save_issuer_identity` (`School_config.php:495-505`) cites `Schools.php:354`
as a *reader* and does not notice that the same file is a *writer* 118 lines earlier. The brief
describes two doors; there are three, and the third is the least defended. It is also gated
differently (§5) and, unlike W1, has no length cap, so it can write a value longer than either tab
permits.

**Evidence:** `Schools.php:210-236`, read in full.

### D2 · The UDISE state cross-check is dead in production — **MEDIUM**

`Issuer_identity::validate()` `:247` cross-checks the UDISE state prefix against the school's
declared state:

```php
$other = self::udiseStateMismatch($udise, (string) ($in['state'] ?? ''));
```

**`save_issuer_identity` never puts `state` into `$in`.** The array is built at
`School_config.php:511-523` from ten POST fields; `state` is not among them, and it is not read from
the school document either. So `$declared === ''`, `udiseStateMismatch` `:186-188` returns `null`
("cannot tell"), and the branch at `:248-252` can never fire.

This is the entire subject of commit `94de755` *"UDISE state prefix: check the one identifier that
proves a school exists."* `git show --stat 94de755` shows it touched exactly two files:
`Issuer_identity.php` and `tests/Unit/IssuerIdentityTest.php`. **The controller was never wired.**

The tests are green because they call the library directly with the parameter the controller omits —
`IssuerIdentityTest.php:131`: `Issuer_identity::validate(['state' => 'Uttar Pradesh', 'udiseCode' => '09310113101'])`.
A green test over a dead feature.

**Good news for the fix, verified:** all 35 `STATE_CODES` values (`Issuer_identity.php:148-162`)
match `assets/data/india_geo.json` state names exactly under `strtolower`. Wiring `state` through
would work. **Caveat:** legacy schools predating the IndiaGeo cascade may hold free-text states
(`"UP"`, `"U.P."`), which would produce a **false-positive rejection** — `codeState = "uttar pradesh"`
vs `declared = "up"` ⇒ mismatch reported. Wire it defensively, or only when the stored state is one
of the 36 canonical names.

### D3 · `issuerIdentity.verification` has no writer — levels 2 and 3 are unreachable — **HIGH (design)**

Exhaustive grep for `evidencePath` / `verifiedBy` / `verifiedOn` across `application/` and
`assets/js/` returns, for the school-issuer object, **only readers**: `Issuer_identity.php:344-350`,
`School_config.php:216`, `Doc_templates.php:342`. (The `Doc_compliance` and `designer.js` hits are
the unrelated compliance-authority object — see D6.)

`save_issuer_identity:562` only ever *preserves* or *clears*:
`$verification = $claimMoved ? [] : ($prior['verification'] ?? []);` — and `$prior` can only be what
this same line wrote last time, i.e. `[]` forever. There is no evidence-upload endpoint:
`School_config::upload_document:751` restricts `doc_type` to
`['holidays_calendar', 'academic_calendar']`. The Verification card in the UI
(`index.php:735-755`) contains only the review-interval select and a static blurb — no upload, no
verify control.

Therefore `levelOf()` can never return `EVIDENCED(2)` or `VERIFIED(3)`; it tops out at `CLAIMED(1)`.
And since `ISSUE_FROM = EVIDENCED` (`:76`), **`mayIssue()` returns `allowed: false` for every school,
always.**

Nothing enforces it — `mayIssue` is only *reported*, at `School_config.php:232` and
`Doc_templates.php:354` — so the ladder is advisory everywhere and no one is blocked. But the
entitlement gate the certificates programme is being built on is, today, both permanently closed and
entirely decorative. Whichever of those is intended, it should be intended.

### D4 · Stored `issuerIdentity.level` can disagree with the stored claim — **LOW**

`save_profile` and `Schools::edit_school` change `affiliationBoard`/`affiliationNo` without
recomputing `issuerIdentity.level` (written only at `School_config.php:570`). Currently harmless
because every consumer recomputes live (`School_config.php:231`, `Doc_templates.php:345`) and
**nothing reads the stored value** — but it is a persisted field that is wrong, waiting for a future
reader.

### D5 · The Issuer tab overstates where its fields appear — **LOW**

`index.php:749-751` tells the operator these fields appear on *"Transfer &amp; other certificates ·
result cards and marksheets · the school registry."* True for `affiliationBoard`/`affiliationNo`;
false for `udiseCode`, `registeredName`, `headOfInstitution`, `headSince` and `recognitionOrder`,
which have no print surface anywhere (§3e). The card sits directly above those inputs.

### D6 · `reviewMonths` / `verifiedOn` name collision across two unrelated objects — **LOW**

`Issuer_identity::isStale(array $verification, int $reviewMonths)` `:356` and
`Doc_compliance::isStale(array $authority)` `:166` implement the same 30-day-month staleness
arithmetic over identically-named fields on **different** objects (a school's issuer verification vs
a compliance-corpus authority). Passing one to the other would produce a plausible, wrong answer with
no error. Exactly the collision class CLAUDE.md flags.

### D7 · `Entity_firestore_sync::syncSchool` is dead code with a lying comment — **LOW**

`Entity_firestore_sync.php:112` writes `name`, `city`, `state`, `principal` and `board` with zero
validation. **No callers exist repo-wide.** Its comment at `:61` names `School_config::save_profile()`
and `Superadmin_schools::onboard()` as callers; neither does. It is the only writer of `board`, which
is why `board` is read-fallback-only everywhere. A future maintainer wiring it up would add a fourth
unvalidated door.

### D8 · Onboarding never sets `state`, which D2's fix depends on — **LOW**

`B2_registry_service::create_tenant` `:1459-1489` writes no `state`. Only `save_profile` (W1) ever
does. So a freshly onboarded school has no state, and a wired-up UDISE cross-check would abstain
until someone saves the Profile tab. Fail-open, correct, but it means the check's coverage depends on
an unrelated form being filled in first.

### D9 · `Result.php:1923`'s `??` chain drops the legacy fallback on empty-string — **INFO**

See §1a. Latent today (no writer produces `''`), activated by any merge that gains the ability to
clear a field.

### D10 · Unvalidated write (W3) feeds an unescaped attribute echo — **MEDIUM**

`edit_school.php:61` and `:68` echo the two affiliation values straight into an HTML `value=""`
attribute with **no `htmlspecialchars`**:

```php
value="<?php echo isset($schooll['Affiliated To']) ? $schooll['Affiliated To'] : ''; ?>"
```

Every neighbouring view escapes (`manage_school.php:134-136`, `schoolprofile.php:230-237`,
all six report-card templates). These two do not. Chained with W3 (D1), which writes the same fields
with no validation, the write and the render are both unguarded on the same path.

**Severity held at MEDIUM, not HIGH:** `global_xss_filtering = TRUE` (`config.php:166`) runs CI3's
`xss_clean` over all `$this->input->post()` input, so the obvious script-injection vector is filtered
at the boundary. But `xss_clean` is a blocklist, not an HTML-attribute escaper — a value containing a
bare `"` still breaks out of the attribute. The correct fix is to escape at output regardless of the
input filter.

### D11 · Two SA-registry cells can never render their `N/A` fallback — **LOW**

`manage_school.php:134-135` write `htmlspecialchars($school['School Principal'] ?? 'N/A')` and
`… ($school['Affiliated To'] ?? 'N/A')`. But `Schools.php:484` and `:487` cast to `(string)`, so a
missing value arrives as `''`, not `null`. `??` does not fire on `''`. **The cells render empty**,
indistinguishable from a rendering failure, and the `'N/A'` text is unreachable.

Same shape as the dead `$schoolInfo['affiliation_no']` alias at `_report_card_data.php:66` (§1a) —
a fallback that reads as defensive and is not.

### D12 · Three readers of the same key, three different legacy-fallback rules — **LOW**

| Reader | Rule |
|---|---|
| `Result.php:1923` | `affiliationBoard ?? board` |
| `Schools.php:487`, `:365` | `affiliationBoard ?? board` |
| **`tc_print.php:33`** | `affiliationBoard` only — **no `board` fallback** |
| `School_config.php:98` (Profile tab) | `affiliationBoard ?? board` |
| **`School_config.php:222`** (Issuer tab) | `affiliationBoard` only |

A school carrying only the legacy `board` key therefore prints an affiliation on its marksheet and
on the SA registry, but **none on its Transfer Certificate** — and the Profile tab shows a board the
Issuer tab shows as unrecorded. Since `board` is write-dead (D7), this affects only schools whose
documents predate the `affiliationBoard` cutover. **UNVERIFIED how many** — I read no production
data. The merge should settle on one fallback rule for all five readers, or drop `board` entirely
after a backfill.

---

## §8 · What would break if the two doors are merged — ranked by likelihood

**1 — Near-certain: you merge two doors and leave a third open.**
`Schools::edit_school` (D1) writes the same two keys with no validation, a different RBAC gate, and no
length cap. A merge that unifies only `school_config`'s two tabs leaves the weakest door untouched and
produces a false sense of closure. *Mitigation: W3 must be in scope — either routed through
`Issuer_identity::validate()` or have the two fields removed from its `fieldMap` at `Schools.php:221-222`.*

**2 — Near-certain: existing data fails the stricter rule.**
The merged field will carry the Issuer tab's rule (board `<select>` + per-board regex, `maxlength=40`).
Live values were written by W1 (≤100 chars, free text), W3 (unbounded), or predate all of it. Values
like `"CBSE Delhi"`, `"C.B.S.E"`, or an 11-digit UDISE code in the number field will not round-trip:
the `<select>` shows blank, `levelOf()` `:323-333` returns `UNRECORDED`, and **the operator cannot
save the form without first correcting a field they may not know the correct value for.** *Mitigation:
survey the live distribution of `affiliationBoard`/`affiliationNo` before choosing the rule; decide
explicitly whether a non-conforming stored value blocks the save or is shown as a warning.*

**3 — Very likely: printed output changes on nine surfaces, and one of them is statutory.**
All six report-card templates (§3a) render the board inside a badge captioned `AFFILIATED`, and
`tc_print.php:451` decides the **statutorily-prescribed CBSE letterhead wording** by
`stripos($schoolBoard, 'cbse')` on the stored string. Normalising `"CBSE Delhi"` → `"CBSE"` keeps both
working; **blanking a non-conforming value silently downgrades the TC from the CBSE SOP wording to
nothing**, on a document a receiving school relies on. The `_report_card_data.php:66-67` shim means
one change hits all six report cards at once — which cuts both ways. Decide the normalisation rule
against `tc_print.php:451`, not just against the form.

**4 — Likely: the stale-DOM revert survives the merge.**
§4e is a client-side defect: two independently-saved forms sharing one page-scoped `CFG`. Merging the
two *fields* does not fix it unless the merge also collapses the two *save endpoints* or makes each
success handler re-render the other tab. If `save_profile` still posts any issuer key, the revert
remains.

**5 — Moderate: the TC's deliberately-removed fallback gets reintroduced.**
`tc_print.php:10-27` records that the board once defaulted to `'C.B.S.E'` and that this was removed on
principle. A merge adding a "sensible default" for a now-empty field re-creates a fixed defect on a
statutory document. *Treat that comment as a constraint.*

**6 — Moderate: the certificate token binding goes stale silently.**
`school.affiliationNo` is declared in `doc_types.php:61`, bound in nine `doc_starters.php` places and
required by five doc types (`:184-211`). `Doc_serializer::resolve()` *does* bind it — but only ever in
sample mode, because both call sites pass `[]` as the data array (§3d). Renaming the underlying key
breaks nothing visible now and everything later, at the moment the print point is wired. *If the key
is renamed, update `doc_types.php:61`, the nine `doc_starters.php` bindings and `designer.js:42` in
the same change.* Note that `Doc_serializer::resolve()` **throws** on a missing real value rather than
printing blank (`:721-724`) — so when the print point is wired, a school with no affiliation number
will fail loudly rather than emit a blank certificate. That is the right behaviour, and it means the
merge's decision about whether a field can be cleared (§4f) becomes an issuance-blocking decision.

**7 — Low: `headOfInstitution` vs `principal` remains a second, unmerged duplicate pair.**
The brief scopes the merge to the affiliation fields. But `principal` (Profile, printed via
`Result.php:1928`) and `headOfInstitution` (Issuer, feeds `levelOf()` only, printed nowhere) are the
same duplicate-door pattern one card lower on the same page, already half-linked by the
`headOfInstitution ?? principal` fallback at `School_config.php:226`. Merging one pair and not the
other leaves the defect class alive in the same form.

**8 — Low: nothing external breaks.**
No Cloud Function, no Teacher/Parent app code, and no Firestore rule reads any of these keys (§0). No
index is needed: the school document is **always fetched by id** (`fs->get('schools', <id>)`, ~25 call
sites) and **never queried**, so `querySchool()` / `querySchoolSession()` do not apply and the
"forgot the session filter" bug class cannot occur here (§7 of the brief).

**9 — Low: cache staleness.** `schools` is file-cached 300 s (`Firestore_rest_client.php:95`) but every
writer in this repo busts it. Only a write from outside this PHP install would go unseen.

**10 — Not a risk: RBAC and CSRF.** Both tabs carry the identical gate
(`Configuration`/`edit`, `ADMIN_ROLES`), so there is no permission to reconcile; and
`school_config/*` is correctly **absent** from `csrf_exclude_uris`, so the merged endpoint needs no
entry and must not be given one (§5).

---

## Appendix · What I did not verify

- **Live data distribution.** I read no production Firestore. Risk 2 above is a shape argument, not a
  census — I deliberately did not repeat the withdrawn-census mistake `Issuer_identity.php:31-35`
  documents. The one concrete value cited (`09310113101` in an affiliation field) comes from commit
  `94de755`'s own message, not from a read I performed.
- **Whether `Schools::edit_school` is reachable in the current UI.** I confirmed the controller and
  `application/views/edit_school.php`; I did not confirm which navigation still links to it. If it is
  orphaned, D1 drops from HIGH to LOW — worth checking before scoping the fix.
- **UNVERIFIED:** whether the absence of a `verification` writer (D3) is a deliberate
  not-yet-built or an unnoticed gap. I would need `blueprints/certificates/FINAL_BLUEPRINT.md` and
  `STATE_LEDGER.md` to tell.
