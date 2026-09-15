# Cross-surface wiring — issuer identity on `schools/{schoolId}`

Research only. **Nothing was edited, committed or deployed.** Repo `~/Desktop/Zennxii_adminPanel`,
branch `yug_testing` (unchanged). App repos read through `git -C`; both are independent repos nested
in `AndroidStudioProjects` and neither was touched.

Question answered: before we change how a school records the data entitling it to issue
certificates, **who else reads or writes that data**, and what must therefore land on more than one
surface at once.

Every claim below was verified by opening the file. Anything I could not confirm by reading source
is in **§6 UNVERIFIED**, not stated as fact.

---

## 1 · Summary

### 1.1 Surface × involvement

| surface | reads? | writes? | displays? | risk if changed one-sidedly |
|---|---|---|---|---|
| **Admin panel (PHP + views + JS)** | **yes — heavily** | **yes — THREE competing doors** | **yes — on statutory documents** | 🔴 **HIGH.** The only writer, the only validator, and the only renderer. All behaviour lives here. |
| **Cloud Functions (`functions/`)** | **no** | **no** | n/a | 🟢 **NONE.** Zero references. Safe to ignore. |
| **Firestore rules** | governs the doc | — | — | 🟡 **LOW-MED.** Client writes already impossible; the *instrument upload* is where rules matter (§3). |
| **Firestore indexes** | — | — | — | 🟢 **NONE today**, 🟡 **new index needed** if we ever query schools by `board`/`udiseCode` (§4). |
| **Storage rules** | — | — | — | 🔴 **HIGH for the planned upload.** Current rules make an affiliation instrument school-wide readable, and the panel's URL helper bypasses rules entirely (§3). |
| **Teacher app** | **no** | no | no | 🟢 **NONE** for issuer keys. Reads `schools/{id}` for `currentSession` only. |
| **Parent app** | **profile-side only** (`name`, `address`, `city`, `state`, `pincode`, `logoUrl`) | no | **yes — fee-receipt PDF header + school display name** | 🟡 **MED.** Not affected by issuer keys, but **hard-coupled to `name`** — see §7-B. |
| **Node auth backend (`project2`)** | **no** (live code) | one-off migration script only | no | 🟢 **NONE** on the live path. Historical origin of the key names. |
| **Custom claims** | — | — | — | 🟢 **NONE.** No issuer key participates in claims; no token refresh needed (§5). |

### 1.2 Key × where it actually lives

Checked against the writers, not against the design docs.

| key | on-doc location | writer | reader(s) |
|---|---|---|---|
| `affiliationBoard` | **top level** | **3 doors:** `save_issuer_identity`, `save_profile`, `Schools::edit_school` | `Issuer_identity::complianceBoard`, `Result.php`, `tc_print.php`, `Schools.php`, all 6 report-card templates |
| `affiliationNo` | **top level** | same three doors | same, plus every certificate contract via merge field `school.affiliationNo` |
| `board` | **top level** | `Firestore_service::saveSchool` line 1027 (only if caller passes `board`) — **no panel path actually passes it** | **legacy fallback only** — read at `Issuer_identity.php:396`, `Schools.php:260,487` |
| `udiseCode` | **top level** | `save_issuer_identity` only | `Issuer_identity::validate()` **only** — ⚠️ **not read by `levelOf()`, not rendered anywhere.** Captured, validated, then unused. |
| `registeredName` | **top level** | `save_issuer_identity` only | `Issuer_identity::levelOf` non-empty check only — **no renderer** |
| `headOfInstitution` | **top level** | `save_issuer_identity` only | `Issuer_identity::levelOf` non-empty check only — **no renderer**; `tc_print.php:695` prints a *static* "Principal" label |
| `headSince` | **top level** | `save_issuer_identity` only | **no reader found** beyond being stored |
| `reviewMonths` | **top level** | `save_issuer_identity` only | `Issuer_identity::levelOf` / `isStale` |
| `recognitionOrder.{number,date,authority}` | **top level map** | `save_issuer_identity` only | **no reader found** — stored, never read back by any logic or template |
| `verification.{verifiedOn,verifiedBy,evidencePath}` | **NESTED under `issuerIdentity.verification`** | `save_issuer_identity` (preserve/clear only — **never populates any subkey**) | re-flattened to top level before `levelOf()` at `School_config.php:216-217` and `Doc_templates.php:342-343` |
| `issuerIdentity.{level,updatedBy,updatedAt}` | **nested** | `save_issuer_identity` only | `School_config::get_config`, `Doc_templates::_school_context` |
| `name` / `schoolName` | top level, kept in sync | `saveSchool` lines 1019, 1048-1050 | panel everywhere **+ Parent app** (display name, receipt PDF) |
| `principal` | top level — **note: `principal`, not `principal_name`** | `saveSchool:1026` (maps from posted `principal_name`) | **Parent app** `ConductContactViewModel` |
| `state`, `city`, `pincode` | top level | `saveSchool:1021-1022,1028` | `Doc_templates::_school_context` (state gates certificate types) **+ Parent receipt PDF** |

### 1.3 The five things that matter most

1. **`verification` is nested under `issuerIdentity`, but every consumer flattens it to top level
   before use.** Two call sites do this by hand (`School_config.php:216`, `Doc_templates.php:342`).
   A third consumer written without that flattening step will silently compute
   `levelOf()` = `CLAIMED` forever.
2. **THREE panel doors write the same keys, with three different levels of validation, none
   locking.** `save_issuer_identity` validates through `Issuer_identity::validate()`;
   `save_profile` accepts free text with only a byte cap; `Schools::edit_school` accepts free text
   with nothing at all. Last save wins, silently, and only one of the three updates
   `issuerIdentity.level`.
3. **`verification.evidencePath` is the issuance gate and nothing in the codebase writes it.**
   Confirmed independently at `ISSUER_UX.md:32` and by repo-wide grep (§2.1). The planned
   instrument upload is precisely the missing writer — which makes §3 the load-bearing section.
4. **Level `VERIFIED` (3) is architecturally unreachable from this codebase.** Nothing writes
   `verification.verifiedOn` or `verification.verifiedBy` either — there is no "mark verified"
   endpoint, action or UI anywhere in the panel. `save_issuer_identity` only ever carries a prior
   `verification` forward or clears it to `[]` (`School_config.php:563-566`). So the top two rungs
   of a four-rung ladder both have no writer.
5. **Five of the ten issuer fields are captured but never rendered on any document.**
   `udiseCode`, `registeredName`, `headOfInstitution`, `headSince` and `recognitionOrder.*` appear
   on no certificate, TC, report card or letterhead. `udiseCode` is not even read by `levelOf()`.
   Verified by opening every template that matched the grep. This is worth knowing before adding
   more fields to the same form.

---

## 2 · Per-surface detail

### 2.1 Admin panel — the only surface that matters

**Repo-wide grep** for `affiliationBoard|affiliationNo|udiseCode|registeredName|headOfInstitution|headSince|reviewMonths|recognitionOrder`,
excluding `vendor/`, `node_modules/`, `.git/`, `blueprints/`, returns exactly these files:

```
BUG_LEDGER.md
qa/certificates/_live-state.md
application/config/doc_starters.php
application/config/doc_types.php
application/controllers/Result.php
application/controllers/School_config.php
application/controllers/Schools.php
application/libraries/Doc_block_service.php
application/libraries/Doc_compliance.php
application/libraries/Firestore_service.php
application/libraries/Issuer_identity.php
application/views/school_config/index.php
application/views/sis/tc_print.php
assets/js/doctemplates/designer.js
tests/Unit/{IssuerIdentityTest,DocIssuerBasisTest,DocAuthorityCorpusTest,
            DocBlockServiceTest,DocComplianceTest,TcPrintAffiliationTest,
            DocSerializerGoldenTest}.php
```

#### The contract — `application/libraries/Issuer_identity.php` (424 lines, committed)

Four-rung ladder, `Issuer_identity.php:70-76`:

```php
const UNRECORDED = 0;  const CLAIMED = 1;  const EVIDENCED = 2;  const VERIFIED = 3;
const ISSUE_FROM = self::EVIDENCED;
```

- `validate(array $in)` — `Issuer_identity.php:204-307`. Accepts and normalises exactly our keys:
  `affiliationBoard` (209-215), `affiliationNo` (222-236, **board-dependent pattern**),
  `udiseCode` (240-252, **exactly 11 digits + state-prefix cross-check**),
  `registeredName` (257-262, ≤200), `headOfInstitution` (266-271, ≤200),
  `headSince` (275-280, `YYYY-MM-DD`), `recognitionOrder` (287-296),
  `reviewMonths` (303-304, **enum `{12,24,36}`**, default otherwise).
  It does **not** emit `verification` — that is written separately.
- `levelOf(array $id)` — `Issuer_identity.php:321-352`. **Reads `$id['verification']` at top level**
  (line 338), hence the flattening every caller must perform.
  `VERIFIED` needs non-stale `verifiedOn` + `verifiedBy` (344-347);
  `EVIDENCED` needs `verification.evidencePath` (348-350); otherwise `CLAIMED`.
- `mayIssue()` — `Issuer_identity.php:371-383`. Returns the "Attach it before issuing" reason string.
- `complianceBoard()` — `Issuer_identity.php:394-399`. `affiliationBoard ?? board`, uppercased,
  **returns `''` when unrecognised** so no board authority matches. Explicitly refuses `board_config`.

**Shape assumptions to preserve.** Three, all inside this file:
`BOARDS[$board]['pattern']` (a per-board regex on `affiliationNo`), `UDISE_PATTERN` (11 digits),
and `reviewMonths ∈ {12,24,36}`. Any change to the accepted shape of `affiliationNo` or the board
enum changes `levelOf()` for **already-stored** data — a school at `CLAIMED` can silently drop to
`UNRECORDED` on the next read, with no write having occurred.

#### Writer door 1 — `School_config::save_issuer_identity()`

`application/controllers/School_config.php:506-589`.

- RBAC: `_require_role(self::ADMIN_ROLES, …, 'Configuration', 'edit')` — line 508.
  **Note the module is `Configuration`, not `Certificates`** (see §7-D).
- Posts snake, stores camel: `affiliation_board → affiliationBoard`, etc. (lines 511-524).
- Validates via `Issuer_identity::validate()` (line 526); returns **422 with per-field errors**
  (537-546) rather than through `json_error()`.
- Read-modify-write: reads `$existing` (556), derives `$claimMoved` (558-561), **clears
  `verification` when the board or number moved** (563), writes
  `issuerIdentity.{verification,updatedBy,updatedAt,level}` (565-572), then
  `$this->fs->update('schools', $this->school_id, $doc)` (574).
- **No `_config_lock_acquire`, no `__updateTime` precondition** — matches the TOCTOU finding already
  recorded in `wiring/LEDGER_CONSTRAINTS.md` §1 (BUG-028 class).

#### Writer door 2 — `School_config::save_profile()`

`application/controllers/School_config.php:405-470+`.

- Same RBAC gate (`Configuration`/`edit`, line 407).
- `$allowed` (412-416) includes **`affiliation_board` and `affiliation_no`**, with only a
  byte-length cap (`affiliation_board` 100, `affiliation_no` 100 — lines 424-425).
- Writes via `$this->fs->saveSchool($data)` (line 461).

`Firestore_service::saveSchool()` — `application/libraries/Firestore_service.php:1015-1060+` —
normalises snake→camel and lands on **the same top-level keys**:

```php
'affiliationBoard' => $data['affiliation_board'] ?? $data['affiliationBoard'] ?? null,   // :1031
'affiliationNo'    => $data['affiliation_no']    ?? $data['affiliationNo']    ?? null,   // :1032
'board'            => $data['board'] ?? null,                                            // :1027
'principal'        => $data['principal_name'] ?? $data['principal'] ?? null,             // :1026
```

It also keeps `schoolName` synced to `name` (1048-1050) and `street` synced to `address` (1055-1057).

**The Profile tab still has its own free-text inputs for these two fields**, side by side with the
validated Issuer Identity tab:

```html
<!-- application/views/school_config/index.php:436-443 -->
<label>Affiliation Board</label>
<input type="text" id="pf_affiliation_board" maxlength="80" placeholder="e.g. CBSE">
<label>Affiliation / DISE No.</label>
<input type="text" id="pf_affiliation_no" maxlength="60" placeholder="Affiliation or registration number">
```

posted by `saveProfile()` at `school_config/index.php:1619-1631`. Note the second label names **two
different identifiers issued by two different authorities over one plain input** — the exact defect
`Issuer_identity.php:17-24` was written to answer, still live on the other tab.

**Consequence, verified by reading both:** the Profile tab can overwrite a validated affiliation with
an unvalidated one, and it does not touch `issuerIdentity.level`. The stored level then describes a
claim the document no longer carries. This is the collision `wiring/LEDGER_CONSTRAINTS.md` §1 names.

#### Writer door 3 — `Schools::edit_school()` (Super-Admin, no validation at all)

`application/controllers/Schools.php:210-240`. A separate legacy Super-Admin screen:

```php
$fieldMap = [ …, 'Affiliated To' => 'affiliationBoard', 'Affiliation Number' => 'affiliationNo' ];  // :215-223
foreach ($fieldMap as $formKey => $fsKey) { if (…non-empty…) $patch[$fsKey] = $normalizedData[$formKey]; }
$this->fs->set('schools', $schoolId, $patch, true);   // :236
```

**Free text, no board enum, no pattern, no length cap, and no `issuerIdentity.level` recompute.**
It writes the same two top-level keys as the other two doors. It does **not** carry `city`, `state`,
`pincode` or `principal` (confirmed by reading `$fieldMap` in full at `:215-223`).

#### Writer door 4 — `B2_registry_service::create_tenant()` (disjoint, no issuer keys)

`application/libraries/B2_registry_service.php:1453-1470`. Tenant onboarding writes
`schoolId, schoolCode, schoolName, name, city, street, address, email, phone, logoUrl,
domainIdentifier` — **and no issuer key, no `state`, no `pincode`, no `principal`.**
So a freshly onboarded school has no board, no affiliation number and no state until a human opens
School Config and saves. Relevant because `state` gates which certificate types are even offered
(`Doc_templates::_school_context()`).

#### Dead code — do not be misled by it

- `application/libraries/Entity_firestore_sync.php:63-88` (`syncSchool()`) maps
  `profileData['board'] → board` and its docblock claims it runs after
  `School_config::save_profile()`. **It has zero callers** — grep for `syncSchool` across
  `application/` returns only its own definition and two comments in the same file. It is not a
  writer. Do not update it, and do not assume `board` is written from anywhere in this repo.
- `B2_registry_service::update_school_profile()` (`B2_registry_service.php:1117-1124`) is a
  **false-friend name**: despite "profile" it patches `schools/{schoolId}`, not
  `schools/{schoolId}_profile` (`firestoreUpdate('schools', $schoolId, $patch)` at `:1119`).

#### Readers that put these fields on paper

| file:line | what it renders |
|---|---|
| `application/controllers/Result.php:1922-1923` | marksheet merge vars `AffNo` ← `affiliationNo`, `Board` ← `affiliationBoard ?? board` |
| `application/views/sis/tc_print.php:32-33` | `$schoolAffNo` ← `affiliationNo`, `$schoolBoard` ← `affiliationBoard`; `:441-452` prints a board **only if recorded** (the fixed "Affiliated to C.B.S.E, New Delhi" hardcode) |
| `application/controllers/Schools.php:221-222, 260-261, 354-355, 487` | SA registry columns "Affiliated To" / "Affiliation Number", with `board` as legacy fallback |
| `assets/js/doctemplates/designer.js:42` | merge field `school.affiliationNo`, label "Affiliation number", **`maxLen:16`** |
| `assets/js/doctemplates/designer.js:119-134` | `school.affiliationNo` is a **required key** on `transfer_certificate`, `leaving_certificate_5a`, `school_education_certificate`, `bonafide`, `character` |
| `assets/js/doctemplates/designer.js:284, 572-573, 647-648` | letterhead block bound to `school.affiliationNo`, rendered EN + HI |
| `application/views/result/templates/_report_card_data.php:66-67` | `$schoolAffNo` ← `AffNo`, `$schoolBoard` ← `Board`; consumed by **all 6 report-card templates**, e.g. `result/templates/cbse.php:42-53` prints `"Affiliated to " . strtoupper($schoolBoard)` |
| `application/libraries/Doc_block_service.php:33, 255` | the shared **letterhead block** binds `school.affiliationNo` as a required merge key — every contract using that block inherits the requirement |

⚠️ **A shape assumption worth naming.** `tc_print.php:450-455` detects CBSE by **substring match**,
not by the canonical enum key:

```php
if (stripos($schoolBoard, 'cbse') !== false || stripos($schoolBoard, 'central board') !== false):
```

Because door 2 and door 3 accept free text, `$schoolBoard` is not guaranteed to be one of the five
`Issuer_identity::BOARDS` keys. A school whose board was typed on the Profile tab as, say,
"CBSE Delhi" still matches; one typed "C.B.S.E." does not. If the issuer work narrows the board to a
strict enum, this substring test should narrow with it — same file, same change.

#### The compliance seam — `Doc_templates::_school_context()`

`application/controllers/Doc_templates.php:318-356`. Reads `schools/{id}`, then:

- flattens `issuerIdentity.verification` → top-level `verification` (342-343),
- `$level = Issuer_identity::levelOf($identity)` (345),
- returns `['name','state','board' => complianceBoard($doc), 'stage', 'issuer' => ['level','mayIssue']]`.

`state` gates which certificate types are offered; `board` selects the statutory compliance layer.
So **`state` and `affiliationBoard` are load-bearing for which law a document is produced under.**

`Doc_templates::seed_standard()` passes `$school['board']` and `$school['state']` into
`doc_seeder::seed()` (`Doc_templates.php:292-297`) — a board change therefore changes which standard
templates a school is provisioned.

#### Two validation gaps that interact

1. **`udiseCode` is cross-checked against `state`** — `Issuer_identity::udiseStateMismatch()`
   (`Issuer_identity.php:168-188`) compares the first two digits of the UDISE code against a
   hardcoded census/GST state-code map (`Issuer_identity.php:149-162`), and
   `validate()` rejects a mismatch (`:249-252`).
2. **But `state` itself is unvalidated free text at the write boundary.** `save_profile()` caps it
   at 100 bytes (`School_config.php:420`) and nothing else. The Profile form renders it through the
   `india_geo` cascading dropdowns (`school_config/index.php:454-459`), but a direct POST can set
   any string, and `udiseStateMismatch()` returns `null` ("cannot tell") for a state it does not
   recognise — so the cross-check silently stops checking.

If UDISE is going to be load-bearing, `state` has to become an enum at the *server* boundary, not
just in the dropdown. That is a `save_profile()` change, i.e. door 2 again.

#### The school name is read from session, not Firestore, nearly everywhere

`Admin_login.php:695, 733-734` reads `schoolDoc['name'] ?? ['display_name'] ?? ['schoolName']` **once
at login** and caches it into `session['school_display_name']`. Dozens of controllers and
`views/include/header.php:306` then read `$this->school_display_name` from session rather than
Firestore. `School_config.php:470-471` re-seeds that session value on save so the header updates
without a re-login — **for the acting user only**; other signed-in admins see the old name until
their next login. Relevant to §7-B: the school name has a stale-cache path the issuer keys do not.

#### `schools/{schoolId}_profile` — confirmed a counter store

Doc id built at `application/libraries/Firestore_service.php:178-181` (`docId('profile')`).
Every read/write site was enumerated; **all of them are monotonic counters**:

| site | namespace |
|---|---|
| `Accounting.php:354,360` | `acctCounters.{voucher type}` |
| `Hr.php:1059,1079` · `Hr.php:1575,1620` | `hrCounters.{type}` |
| `Hr.php:1236-1240` | `acctCounters.Journal` |
| `Hr.php:2453,2457` | `commCounters.Circular` |
| `Org.php:388-389` | `hrCounters.Department` |
| `Communication.php:1661-1676` | `commCounters.Queue` |
| `Comm_counter_probe.php:36-51` (CLI) | `commCounters.Notice` |
| `Communication_verifier.php:972, 1650` (CLI) | `commCounters.QueueProbe` (test cleanup) |
| `Sis.php:5394` | read, TC numbering context |

**No issuer key, and no profile key, appears on `_profile`.** Every real profile field lives on
`schools/{schoolId}` itself. It is safely out of scope. (It does, however, have a rules consequence
— see §3.6.)

#### Test coverage already in place

`tests/Unit/IssuerIdentityTest.php`, `DocIssuerBasisTest.php`, `TcPrintAffiliationTest.php`,
`DocComplianceTest.php`, `DocBlockServiceTest.php`, `DocAuthorityCorpusTest.php`,
`DocSerializerGoldenTest.php` all reference these keys. **A change to the accepted shape of
`affiliationNo`, the board enum, or the `reviewMonths` enum will move these tests.** Judge against
the documented baseline (4 failures + 27 skipped), not against zero.

---

### 2.2 Cloud Functions — **no reader found, no writer found**

Grepped `functions/` (excluding `node_modules/`) across `*.js` and `*.json` for
`affiliationBoard|affiliationNo|udiseCode|udise|registeredName|headOfInstitution|headSince|reviewMonths|recognitionOrder`
— **zero matches**. A second grep for `principal_name|display_name|'board'|"board"|pincode` —
**zero matches**.

Only three functions touch the school document at all, and none reads our keys:

| file:line | what it reads from `schools/{schoolId}` |
|---|---|
| `functions/staffCapabilities.js:155-158` | `staffRoles`, `roles` — RBAC catalogue only |
| `functions/staffCapabilities.js:218-226` (`onSchoolRolesChanged`) | trigger on `schools/{schoolId}`, **early-returns unless `staffRoles` changed** (line 226) |
| `functions/recoveryContact.js:65-83` | `forget_password_details.{name,email,number}` and `schoolName ?? name` |

**`MARK_REGISTRY`** — `functions/index.js:52`, resolved at `:416`. Grep for
`schoolName|school.name|schoolDoc|schoolData` in `index.js` returns **zero matches**. The push
dispatcher does not read the school document.

**`functions/rbac_modules.json`** — the shared catalogue already contains **`"Certificates"`**
(and `"Configuration"`). No module needs to be added; but see §7-D on which one gates the issuer form.

**Important second-order effect:** `onSchoolRolesChanged` fires on *every* write to
`schools/{schoolId}`, including an issuer-identity save. It early-returns on the `staffRoles`
comparison at `staffCapabilities.js:226`, so the cost is one function invocation per save and no
capability rebuild. Not a blocker — worth knowing it is not free.

---

### 2.3 Firestore rules — `firebase-rules/firestore.rules`

The whole `schools` block is nine lines, `firestore.rules:400-408`:

```
match /schools/{docId} {
  allow read: if isAuth()
    && tenantActive(docId)
    && ( resource.data.schoolId == request.auth.token.school_id ||
         docId == request.auth.token.school_id );
  allow write: if isSuperAdmin();
}
```

**Findings:**

1. **No client can write these fields.** `isSuperAdmin()` (`firestore.rules:258-260`) matches only
   `role ∈ ['Super Admin','super_admin']` — a *platform* super-admin, not a school admin. Every real
   write is the panel through the Admin SDK, which bypasses rules entirely. **A school admin editing
   issuer identity never touches these rules.**
2. **No shape validation of any kind.** No `hasOnly()`, no type assertion, no field-level guard on
   the school doc. Nothing in rules constrains `affiliationNo` or `board`. All validation is
   PHP-side, in `Issuer_identity::validate()`.
3. **Read is same-school, tenant-gated.** Any authenticated same-school user — teacher, parent,
   student — can read the whole school document, including every issuer key. Confirmed by the rules
   tests at `firebase-rules/tests/h_lifecycle_l2.test.js:125`
   (`authedDb(SCHOOL_A,'parent').doc('schools/'+SCHOOL_A).get()` → `assertSucceeds`) and denied only
   when the lifecycle state disallows it (`:151, :181`).
   ⇒ **adding a field to this document publishes it to every app user in the school.** Treat
   `verification.verifiedBy` and any reviewer note accordingly.
4. **`tenantActive()`** (`firestore.rules:50-80`) reads `schoolControl/{id}.lifecycle.state`,
   `schools/{id}.adminDisabled.value` and `schools/{id}.institution.state`. A `CLOSED` school stays
   **readable** on purpose — "so parents can reach records and certificates"
   (`firestore.rules:60-64`). Relevant: a closed school's issuer identity remains visible.
5. **The certificate collections are already ruled, and they gate on a different module.**
   `documentTemplates` (`firestore.rules:3158-3205`), `documentTemplateVersions` (`:3207-3215`),
   `reusableBlocks` (`:3217-3227`) all gate on `hasCapabilityLevel('Certificates', …)`.
   `documentTypes` / `mergeFieldContracts` / `complianceAuthorities` (`:3141-3157`) are platform
   collections: `allow read: if isAuth() && tenantActive(...)`, `allow write: if false`.
6. `tenantPublic` (`firestore.rules:3094-3101`) is the thin client mirror. Its field skeleton is
   `application/config/b2_collections.php:55-61` — **`name`, `logoUrl`, `activeModules`,
   `accessAllowed`, `computedAt` only**. No issuer key. Neither app reads it (grep of both
   `app/src` trees: zero matches).

---

### 2.4 Teacher app — **no reader found for any issuer key**

`git -C ~/AndroidStudioProjects/ZenXII_Teacher` — package `com.schoolsync.teacher`.

Exhaustive grep of `app/src` for
`affiliationBoard|affiliationNo|udiseCode|udise|registeredName|headOfInstitution|headSince|reviewMonths|recognitionOrder|principal_name|display_name`
→ one hit only, and it is unrelated:
`data/local/TokenManager.kt:43` — `stringPreferencesKey("school_display_name")`.

Everywhere the Teacher app touches `schools/{schoolId}`:

| file:line | fields taken |
|---|---|
| `data/repository/firestore/SchoolFirestoreRepository.kt:39-42` | `getSchool()` → `SchoolDoc` |
| `data/repository/firestore/SchoolFirestoreRepository.kt:61` | `getSchoolConfig()` → raw map |
| `data/repository/firestore/SchoolFirestoreRepository.kt:91-111` | `observeSchool()` → **`currentSession` only** |
| `data/repository/AuthRepository.kt:491-492` | raw map → **`currentSession` only** |

`SchoolDoc.kt:11-39` deserialises `schoolId, name, schoolCode, city, email, phone, logoUrl, status,
currentSession, subscription{…}, board (from board_config: type/gradingPattern/gradeScale/passingMarks),
createdAt, updatedAt`.

⚠️ **The Teacher app's `board` is NOT the affiliation board.** It is `board_config`
(`SchoolDoc.kt:22-36`), consumed by `MarksScreen`/`MarksViewModel` for marks validation.
`Issuer_identity::complianceBoard()` explicitly refuses to read it
(`Doc_templates.php:334-338`), and the app never displays it as an affiliation.

- `util/Constants.kt:57` — `const val SCHOOLS = "schools"`. (`Constants.kt:11` has a *different*
  `object Firebase` with `SCHOOLS = "Schools"` — the legacy RTDB root, unrelated.) The whole
  `object Firestore` block (`Constants.kt:55-162`) contains **no** affiliation/UDISE/recognition
  constant.
- `school_display_name` (`TokenManager.kt:43,79,109`) is sourced from the **staff document**, not the
  school document: `AuthRepository.kt:142` reads `staffData["SchoolDisplayName"] ?? ["schoolDisplayName"]`
  from `staff/{schoolId}_{userId}` (`AuthRepository.kt:215-217`), RTDB fallback at `:228-230`.
- **No certificate, TC, report-card or ID-card generation exists in this app.** Grep for
  `transfer.?certificate|report.?card|id.?card|certificate|PdfGenerator|generatePdf` matched only
  exam-marks code.

**Verdict: the Teacher app is uninvolved.** No change of ours reaches it.

### 2.5 Parent app — no issuer keys, but hard-coupled to `name`

`git -C ~/AndroidStudioProjects/ZenXII_Parent` — package `com.schoolsync.parent`.

Same exhaustive grep → two hits, neither a real read:
`data/local/TokenManager.kt:42` (`school_display_name` preference) and
`ui/support/ConductContactViewModel.kt:65-68` — **`principal_name` appears only inside a doc
comment** recording that the key was checked and found absent in live data. The code actually reads
the key `"principal"` (`ConductContactViewModel.kt:149`), which is exactly what
`Firestore_service::saveSchool():1026` writes.

**No reader found** for `affiliationBoard`, `affiliationNo`, `udiseCode`, `registeredName`,
`headOfInstitution`, `headSince`, `reviewMonths`, `recognitionOrder.*`, `verification.*`.
`SchoolDoc.kt:14-27` in this app does not even carry a `board` field.

Where it *does* read `schools/{schoolId}`:

| file:line | fields taken | shown as |
|---|---|---|
| `data/repository/firestore/SchoolFirestoreRepository.kt:39-42, 61, 96-107` | `SchoolDoc`; `observeSchool` takes `currentSession` only | — |
| `data/repository/AuthRepository.kt:196-199` | **`name`** → `User.schoolDisplayName` | app-wide school name |
| `ui/dashboard/DashboardViewModel.kt:179-184` (`fetchSchoolName`) | `getString("name")` | dashboard header |
| `ui/dashboard/DashboardViewModel.kt:355-359` (`healUserProfileIfNeeded`) | `getString("name")` | self-heal |
| `ui/fees/ReceiptDetailViewModel.kt:365-386` (`loadSchoolMeta`) | **`name, address, city, state, pincode, phone, email, gstin, logoUrl ?? logo`** | **fee-receipt PDF header** |
| `ui/profile/ProfileScreen.kt:1247-1256` (`ContactSchoolContent`) | `phone/contactPhone/contact_phone`, `email/contactEmail/contact_email`, `address` | contact-school UI |
| `ui/support/ConductContactViewModel.kt:131-159` | `grievance_contact.{name,number,email}`, **`principal`**, `phone`, `email` | grievance-officer dialog |

- `util/Constants.kt:208` — `const val SCHOOLS = "schools"`. Full `object Firestore` block
  (`Constants.kt:206-330`) contains **no** affiliation/issuer constant.
- `school_display_name` **is** sourced from `schools/{schoolId}.name` here
  (`AuthRepository.kt:195-200`), unlike the Teacher app. RTDB fallback at `AuthRepository.kt:258`
  reads `Users/Parents/{parentDbKey}/{userId}.SchoolName`.
- The only document generator is `util/ReceiptPdfGenerator.kt:121-163` — renders `meta.name`,
  `meta.address`, `meta.gstin`, optional `meta.logoUrl`. **Commercial-invoice shaped: no board, no
  affiliation number, no UDISE.** It does not need issuer identity.

**Verdict: uninvolved for issuer keys — but see §7-B.** If the issuer work introduces
`registeredName` as *the* legal name and repoints or re-semanticises `name`, this app's display name
and its receipt PDF header both move, in a shipped, Play-distributed binary.

### 2.6 Node auth backend (`~/Desktop/project2`) — **no reader found on the live path**

Grep of `*.js` excluding `node_modules/` for our key set → three hits, all in **one one-off
migration script**:

`scripts/migrate-to-firestore.js:87-90` maps the retired RTDB profile into the school doc:

```js
principalName:    cleanString(profile.principal_name),
affiliationBoard: cleanString(profile.affiliation_board || board.type),
affiliationNo:    cleanString(profile.affiliation_no),
```

This is the historical origin of the camel key names. It is not on any request path.
(Note it writes `principalName`, while `Firestore_service::saveSchool():1026` writes `principal` —
a legacy divergence, out of scope here but recorded.)

**Login does not read the school document.** `src/services/authService.js:139-149` builds the user
payload entirely from the Mongo user record; `schoolDisplayName` comes from
`src/models/User.js:60`, a denormalised Mongo field — **not** from `schools/{schoolId}`.
`src/services/schoolService.js:36-47` creates a school doc at provisioning time with
`schoolCode, loginCode, schoolName, city, email, phone, status, plan, createdBy, createdAt` — **no
issuer key**.

**Verdict: uninvolved.**

---

## 3 · Rules + Storage for the planned instrument upload

This is where the change actually bites. `verification.evidencePath` is the field that lifts
`CLAIMED (1)` → `EVIDENCED (2)` = `ISSUE_FROM`, and **nothing writes it today**
(`Issuer_identity.php:348-350`; independently recorded at `ISSUER_UX.md:32`; repo-wide grep for
`evidencePath` outside `blueprints/` returns zero code hits).

### 3.1 The path convention is already fixed

The panel's canonical scheme, used at three places in `School_config.php`:

```php
$remotePath = "schools/{$school_id}/logos/"      . $info['file_name'];   // :703
$remotePath = "schools/{$this->school_id}/{$folder}/" . $info['file_name'];   // :780  ("Canonical Storage scheme: schools/{schoolId}/...")
$remotePath = "schools/{$this->school_id}/reportcard/{$slot}_" . $info['file_name'];   // :4673
```

⇒ an affiliation instrument belongs at **`schools/{schoolId}/<area>/<file>`**. Pick the `<area>`
segment deliberately — it is the only thing rules can key on (see 3.3).

### 3.2 What the current rules would allow

`firebase-rules/storage.rules`, three blocks apply to that prefix:

| block | line | effect on `schools/{schoolId}/affiliation/x.pdf` |
|---|---|---|
| broad write | `storage.rules:307-339` | **write allowed** to any same-school token whose `role` is not Student/Parent/Guardian, ≤50 MiB. No MIME check. |
| **L6a read** | `storage.rules:353-363` | **read allowed to ANY same-school authenticated user** — the only exclusion is `area.lower() != 'support'` |
| L6b read | `storage.rules:368-371` | read allowed for files directly under the school root |

**So as things stand: a parent or student of the same school could read the school's affiliation
instrument.** Storage rules OR together, so a narrower block added later cannot subtract this — the
comment at `storage.rules:317-320` says so explicitly, and it is why the support-attachment fix had
to *withdraw and re-issue* the broad read rather than add a narrower rule.

### 3.3 Two options, and the cost of each

**Option A — put it under `support`-style exclusion.** Rename nothing; instead widen the L6a
exclusion from `area.lower() != 'support'` to also exclude the instrument area. This is a **one-line
edit inside a single existing `match` block**, which is the concurrency-safe shape for
`firestore.rules`/`storage.rules` edits. Reads then reach the file only through a deliberate block
you add.

**Option B — do not make it web-reachable at all.** The panel already uploads through
`Firebase::uploadFile()` (`application/libraries/Firebase.php:440-470`), the Admin SDK — **which
bypasses Storage rules entirely**. If only panel staff ever view the instrument, no client read arm
is needed and the L6a exclusion is still required to stop the *inherited* grant.

Either way the write arm needs no change: the real writer is the Admin SDK.

### 3.4 ⚠️ The URL helper defeats whichever option you pick

`Firebase::getDownloadUrl()` — `application/libraries/Firebase.php:493-514` — does **not** mint a
signed, expiring URL. It returns:

```
https://firebasestorage.googleapis.com/v0/b/{bucket}/o/{path}?alt=media&token={firebaseStorageDownloadTokens}
```

That is a **permanent, unauthenticated, rules-bypassing** download token. There is no
`getSignedUrl`/`signedUrl` helper anywhere in `Firebase.php` (grep: zero matches). The existing
upload flow stores exactly this URL on the school doc — `School_config.php:788-791`:

```php
$url = $this->firebase->getDownloadUrl($remotePath);
$this->fs->update('schools', $this->fs->schoolId(), [$type => $url, 'updatedAt' => date('c')]);
```

**If the instrument follows that pattern, its URL lands on `schools/{schoolId}` — which §2.3 finding
3 established is readable by every parent, student and teacher in the school — and the URL itself
needs no auth at all.** The affiliation instrument is a document naming the school's registered
entity and its head; it is not logo-class material.

**Recommendation to carry into implementation:** store `verification.evidencePath` as a **Storage
path, not a download URL**, and serve it through a panel endpoint that mints a short-lived signed
URL (the pattern `storage.rules:351-352` already describes for support attachments: *"Staff read
them through the panel, which uses the Admin SDK and hands out 5-minute signed URLs"*). That helper
does not exist in `Firebase.php` yet and would have to be added.

### 3.5 Firestore-rules side of the upload: **nothing to change**

The write lands on `schools/{schoolId}` through the Admin SDK. `allow write: if isSuperAdmin()`
(`firestore.rules:407`) is not consulted. No rules edit is required for the *Firestore* half.

### 3.6 One quiet asymmetry worth recording

`schools/{schoolId}_profile` matches the same `match /schools/{docId}` block, so its read arm calls
`tenantActive("{schoolId}_profile")` → `get(/schoolControl/{schoolId}_profile)` → missing →
`ctrl != null` is false → **deny**. The counter doc is therefore client-unreadable in its entirety.
Correct by accident rather than by design, but it holds, and it means `_profile` cannot leak anything
either. Do not "fix" this while doing issuer work.

---

## 4 · Indexes — `firebase-rules/firestore.indexes.json`

Parsed the file (309 composite indexes declared).

- **Indexes on `collectionGroup: "schools"`: zero.** Not one.
- **No declared index anywhere contains** `affiliationBoard`, `board`, `affiliationNo`, `udiseCode`,
  `registeredName`, `headOfInstitution`, `headSince`, `reviewMonths`, `recognitionOrder`,
  `verification`, `principal_name`, `display_name`, or `pincode` in any `fieldPath`.
  (`name` appears only on `eventParticipants`, `routes`, `visitors` — unrelated collections.)
- `fieldOverrides` contains only `viewers.userId` and `reactions.userId`.

**Why that is fine today:** every issuer read is a **single-document get by key**
(`$this->fs->get('schools', $this->school_id)`), and single-field equality is auto-indexed. Nothing
queries the `schools` collection by these fields.

**When you would need one:**

| planned query | index needed? |
|---|---|
| `schools where udiseCode == X` (uniqueness check) | **No** — single-field equality is auto-indexed |
| `schools where board == X` (one filter) | **No** — same |
| `schools where board == X order by name` | **Yes** — composite |
| `schools where board == X and issuerIdentity.level < 2` | **Yes** — composite (range on a second field) |
| a super-admin "who has not recorded a board" screen with sort/paging | **Yes** — composite |

⚠️ If any such screen is built, the index must be **deployed before the code that runs the query**
(`DEPLOY_RUNBOOK.md` ordering: indexes first, they take time to build). Note also that live Firestore
holds substantially more indexes than this file declares — see §6.

---

## 5 · Claims and token implications

**No issuer key participates in custom claims, and no token refresh is required.**

The canonical builder is `Firebase::buildCanonicalClaims()` —
`application/libraries/Firebase.php:826-880+`. The complete claim set it emits:

```
role, roleLabel,
school_id + schoolId,          // dual-cased tenant contract
school_code + schoolCode,
parent_db_key + parentDbKey,
student_id, student_ids        // student/parent tokens only
staffId, roleTier              // staff/admin tokens only
extra (must_change_password, password_reset_at/by, …)
```

`affiliationBoard`, `affiliationNo`, `udiseCode`, `registeredName`, `headOfInstitution`, `headSince`,
`reviewMonths`, `recognitionOrder.*`, `verification.*`, `name`, `principal`, `state`, `city`,
`pincode` — **none of them appear.** The dual-emission rule
(`Firebase.php:806-809, 840-849`) is about tenancy only.

**Therefore:**

- Editing issuer identity changes a **document**, not a token. It takes effect on the next read.
  **No re-login, no ID-token refresh, no claims backfill.**
- The apps read `schools/{schoolId}` live (`SchoolFirestoreRepository.observeSchool()` is a snapshot
  listener in both apps), so any field they *did* read would update in place.
- Nothing in `Auth_claims_backfill.php` or the ~15 mint sites needs touching.

**One genuine claim-adjacent dependency:** the *rules* read of the school document is keyed on
`request.auth.token.school_id` (`firestore.rules:404-405`) and Storage on the same
(`storage.rules:333, 355, 370`). That is the existing tenancy contract and our change does not move
it — but it does mean the instrument-upload read arm inherits the dual-emission requirement like
everything else. Nothing new to emit.

**Divergence noticed, recorded not fixed:** `~/Desktop/project2/src/services/authService.js:185-191`
mints a Firebase **custom token** with `role, userId, schoolId, loginCode, parentDbKey, schoolCode`
— **camel only, no snake `school_id`**. A session established through that path would fail every
`firestore.rules` tenancy check. It is a `createCustomToken` call, not `setCustomUserClaims`, and
whether any live client still uses it is **UNVERIFIED** (§6). Out of scope for issuer identity;
flagged because it sits on the same contract.

---

## 6 · UNVERIFIED register

| # | claim I could not confirm | what would settle it |
|---|---|---|
| U1 | **Whether the deployed `firestore.rules` / `storage.rules` match what is in this branch.** `aegis/` does not exist on `yug_testing` (it lives on branch `aegis`), and I was instructed not to switch branches, so `node aegis/cli.js rules status` could not run. Memory records "46 of 47 blocks are PROD-ONLY", which would mean git is **not** authoritative. | Run `node aegis/cli.js rules status` from the `aegis` branch checkout **before** any rules edit. A whole-file deploy ships other people's blocks. |
| U2 | **Whether live Firestore holds composite indexes on `schools` that the JSON does not declare.** §4 covers only `firestore.indexes.json`. Memory records 284 live vs 183 declared, so live is a superset. | `node aegis/cli.js indexes` (Firestore Admin API), or the Firebase console index list. |
| U3 | **Whether `project2`'s `createCustomToken` path (`authService.js:185-191`) is still used by any shipped client.** | Grep both app repos for a custom-token sign-in (`signInWithCustomToken`) and check whether the legacy REST login is still reachable. |
| U4 | **What the production school documents actually contain.** `qa/certificates/_live-state.md:1454-1456` reports 4/9 with `affiliationNo`, 3/9 with a board, **0/9 with UDISE**, none with a recognition number, none with a seal — but the same file's L31 note records that an earlier census was withdrawn as dummy data, and `Issuer_identity.php:30-35` says so explicitly. **Treat those counts as unverified.** | A read-only census against production Firestore. |
| U5 | **Whether `_config_lock_acquire` is safe to wrap around `save_issuer_identity`** and what lock name would not collide with the 7+ existing sites. | Read the lock sites in `School_config.php` and `wiring/LEDGER_CONSTRAINTS.md` §1 in full. |
| U6 | ~~Whether `Doc_block_service.php` / `Doc_compliance.php` / `doc_starters.php` / `doc_types.php` read these keys directly.~~ **RESOLVED.** `Doc_block_service.php:33,255` binds `school.affiliationNo` as a required *merge key* on the letterhead block — design-time coupling, not a Firestore accessor. `Doc_compliance.php:130,159-176` has its own `reviewMonths`/`verifiedOn`/`isStale()` but operates on the **`complianceAuthorities`** collection (`Doc_compliance.php:33`), a different document. **Do not conflate the two staleness mechanisms.** | — |
| U7 | **Whether any of the ~140 panel controllers reads these keys under a different spelling** (e.g. `affiliation_no` from a session or a legacy RTDB path). Greps covered the camel set plus `affiliation_no`/`affiliation_board`. | A second grep on the snake spellings repo-wide. |
| U8 | ~~Whether `headSince` and `recognitionOrder.*` truly have zero readers.~~ **RESOLVED — zero readers confirmed.** Both are stored and validated, read back only to re-populate their own form fields (`School_config.php:227, 229`). Neither is consumed by `levelOf()` nor rendered on any template. Same for `udiseCode` and `registeredName`. | — |
| U9 | **Where the legacy top-level `board` key originates.** No panel path writes it: `saveSchool():1027` only maps it if a caller passes `board`, and no caller does; `Entity_firestore_sync::syncSchool()` would, but is dead code. It is read as a fallback in four places. | Likely the retired `project2/scripts/migrate-to-firestore.js:89` or hand data-entry. A production census would settle it. |

---

## 7 · What must land on more than one surface at once

### The short answer

**Almost nothing does.** This change is **overwhelmingly single-surface — the admin panel.**
Cloud Functions, the Teacher app, the Node backend and custom claims are all cleanly uninvolved, and
Firestore indexes need nothing unless a new query is introduced. That is a better position than this
codebase usually offers.

There are exactly **two** genuine multi-surface couplings (**A** — storage rules + PHP, and **B** —
PHP + Parent app), plus three single-surface items (**C**, **D**, **E**) that must nonetheless land
atomically within the panel. Only **A** carries a hard deploy-ordering constraint.

---

### A · Instrument upload — **PHP + Storage rules, and rules go FIRST** 🔴

The only genuine two-surface landing.

| step | surface | why this order |
|---|---|---|
| 1 | **`storage.rules`** — extend the L6a exclusion (`storage.rules:362`) so the instrument area is not school-wide readable, and add whatever narrow read arm you intend | Storage rules deploy **separately** from PHP. Ship the upload first and every instrument uploaded before the rules land is readable by every parent in the school — and **that exposure is not retroactively closable**, because the files are already out. |
| 2 | **`firestore.rules`** — nothing to change (§3.5) | — |
| 3 | **PHP** — the file input in `school_config/index.php`, the upload handler, and the writer for `issuerIdentity.verification.evidencePath` | Only after the read boundary exists. |
| 4 | **PHP** — a signed-URL serving endpoint (helper does not exist yet, §3.4) | Needed before anyone is shown a link. |

**Before touching `storage.rules`: run `node aegis/cli.js rules status` (§6 U1) and re-read the file
immediately before editing.** Keep the edit inside the single L6a `match` block. A deploy ships the
whole file, including anyone else's half-finished work.

### B · School name semantics — **PHP + Parent app** 🟡

`registeredName` ("exactly as on the affiliation instrument") and `name` are different facts about
the same school. As long as `registeredName` stays a **new, additive** key, nothing moves.

But the Parent app reads `schools/{schoolId}.name` in **four** places —
`AuthRepository.kt:196-199`, `DashboardViewModel.kt:179-184`, `DashboardViewModel.kt:355-359`,
`ReceiptDetailViewModel.kt:365-386` — and one of those renders it into a **PDF handed to a family**
(`ReceiptPdfGenerator.kt:121-163`). `Firestore_service::saveSchool():1048-1050` additionally keeps
`schoolName` synced to `name`.

**Binding rule for the implementation: do not repoint, rename or re-semanticise `name`.**
If the design ever wants certificates to carry `registeredName` while the app carries `name`, that
is fine and needs no app change. If it wants them unified, that is a **Play-store release** on the
Parent app, and the app must ship first (tolerating both) before the panel changes the data.

### C · The three writer doors — **single surface, but must land together** 🔴

| door | file:line | validation | recomputes `issuerIdentity.level`? |
|---|---|---|---|
| Issuer Identity tab | `School_config.php:506-574` | full `Issuer_identity::validate()` | **yes** |
| Profile tab | `School_config.php:412-416, 461` → `Firestore_service.php:1031-1032` | byte cap only | **no** |
| SA edit-school | `Schools.php:215-236` | **none** | **no** |

Plus the second free-text input pair still live on the Profile form
(`school_config/index.php:436-443`).

Fixing one door without the others leaves the collision intact and merely moves it. Per
`wiring/LEDGER_CONSTRAINTS.md` §1 this is the BUG-028 class, and the canonical
`_config_lock_acquire → capture __updateTime → mutate → precondition write` pattern already exists
at 7+ sites in `School_config.php`.

**All three doors, the duplicate inputs, and the stored `issuerIdentity.level` must change in one
commit.** A stored level computed against a claim another door has since replaced is worse than no
level — and two of the three doors can replace it without anyone noticing.

### E · The unreachable top of the ladder — decide before building 🟡

Nothing writes `verification.verifiedOn`, `verification.verifiedBy` **or** `verification.evidencePath`
(§1.3 items 3-4). So rungs 2 (`EVIDENCED`) and 3 (`VERIFIED`) are both unreachable, and
`ISSUE_FROM = EVIDENCED` (`Issuer_identity.php:76`) means **no school can legitimately pass the
gate** — while `mayIssue()` is consulted in only two places
(`Doc_templates::_school_context()`, `School_config::get_config()`) and gates nothing, so everyone
issues anyway.

The instrument upload (§7-A) supplies the `evidencePath` writer and makes rung 2 reachable.
**Rung 3 needs a separate decision:** either a "verified against the board register" writer, or drop
`VERIFIED` from the ladder. Shipping rung 2 while rung 3 stays writer-less leaves a badge nobody can
ever earn — the same defect one level up. This is a single-surface (PHP) choice, but it should be
made *before* step 3 rather than discovered after.

### D · RBAC module choice — **PHP + `functions/rbac_modules.json` + `firestore.rules`** 🟡

Today the issuer form gates on **`Configuration`/`edit`** (`School_config.php:508`), while every
certificate collection in rules gates on **`Certificates`** (`firestore.rules:3163, 3183, 3213,
3222`). Both modules already exist in the shared catalogue (`functions/rbac_modules.json`), so **no
catalogue edit is needed** — but if the issuer form is moved under `Certificates`, that is a
three-surface change: the PHP gate, the rules gate, and the sidebar/ModuleGate mirrors. If it stays
under `Configuration`, nothing moves.

**Recommendation: leave it on `Configuration`.** Recording who the school *is* is configuration;
`Certificates` governs what it *produces*. Changing it buys nothing and costs three surfaces.

---

### Suggested landing order

```
0.  aegis rules status        (U1) — establish what production actually enforces
1.  DECIDE rung 3 (§7-E): build a verifiedOn/verifiedBy writer, or drop VERIFIED
2.  storage.rules  — L6a exclusion for the instrument area                      [DEPLOY]
3.  PHP  — close all THREE writer doors: lock + CAS, unify validation,
           retire the duplicate pf_affiliation_* inputs, make every door
           recompute issuerIdentity.level                                       [local]
4.  PHP  — instrument upload + evidencePath writer + signed-URL endpoint        [local]
5.  PHP  — make mayIssue() actually gate issuance (it is decorative today,
           ISSUER_UX.md:38-42) OR stop the ladder claiming a gate it
           does not operate                                                     [local]
6.  vendor/bin/phpunit --testsuite Unit   — 7 issuer/doc test classes will move;
           judge against the 4-fail/27-skip baseline, not zero
7.  user verifies on localhost:8080
8.  only then discuss deploying PHP to yug_b1_t
```

Steps 3-5 are local-first and touch one surface. **Step 2 is the only thing that must be deployed
ahead of code**, and it is the only step where getting the order wrong causes an exposure that
cannot be undone.

⚠️ Do **not** treat step 5 as optional tidying. Today the ladder tells every school it may not
issue, gives it an instruction it cannot follow, and then lets it issue anyway. Adding fields to
that form without fixing it makes the form longer and the signal no more real.

Nothing here needs a Cloud Functions deploy, a Firestore index deploy, a claims backfill, a token
refresh, or an app release.

---

*Research artefact. No code changed, nothing committed, nothing deployed.*
