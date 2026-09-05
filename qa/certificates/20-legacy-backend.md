# L1 · BACKEND-SPEC — Legacy Certificate System (`application/controllers/Certificates.php`)

Evidence ceiling: **E2 — static trace only.** No runtime execution performed. Every claim cites `file:line`.
File: `/Users/yuggi/Desktop/Zennxii_adminPanel/application/controllers/Certificates.php` (692 lines).

---

## 1. Endpoint contract table

`_certBase = "Schools/{school_name}/{session_year}/Certificates"` (`Certificates.php:64`), where `school_name`/`session_year` come from `MY_Controller` session state (`application/core/MY_Controller.php:296-298`), never from request input.

| # | Endpoint | Method | RBAC (`_require_role`) | Tenant scope | Key inputs validated | RTDB touched | Response |
|---|---|---|---|---|---|---|---|
| 1 | `index($tab)` | GET | VIEW_ROLES, mod `Certificates`, `view` (`:76`) | n/a (page shell) | `$tab` whitelisted against `['dashboard','templates','generate','issued']` (`:78-79`) | none | HTML view |
| 2 | `get_dashboard` | GET(AJAX) | VIEW_ROLES/`view` (`:107`) | `_certBase` | none | READ `Issued` (`:109`) | `json_success` stats+recent[10] |
| 3 | `get_templates` | GET(AJAX) | VIEW_ROLES/`view` (`:170`) | `_certBase` | none | READ `Templates` (`:172`) | `json_success` templates[]+PLACEHOLDERS |
| 4 | `save_template` | **POST** | MANAGE_ROLES/`manage` (`:212`) | `_certBase` | `type`∈CERT_TYPES, `name`/`body` non-empty (`:220-224`); custom `editId` → `safe_path_segment` (`:239`) | R-I-W `Templates/Custom/Counter` (new custom, `:244-245`); WRITE `Templates/Custom/{cid}` or `Templates/{Type}` (`:240,248,257`) | `json_success` {id} |
| 5 | `delete_template` | **POST** | MANAGE_ROLES/`manage` (`:270`) | `_certBase` | `id` must start `Custom/` (`:276-278`), `cid` → `safe_path_segment` (`:281`) | DELETE `Templates/Custom/{cid}` (`:283`) | `json_success` |
| 6 | `get_classes` | GET(AJAX) | VIEW_ROLES/`view` (`:296`) | via `_get_session_classes()` (not in this file) | none | (delegated) | `json_success` classes[] |
| 7 | `get_students` | **POST** | VIEW_ROLES/`view` (`:307`) | `Schools/{school}/{session}/...` built from session `school_name`/`session_year` (`:315-316`) | `classKey` required + `safe_path_segment` (`:312-313`); `sectionKey` → `safe_path_segment` if present (`:321`) | READ `.../{classKey}/Section {n}/Students/List` (one or, via `shallow_get`, all sections) (`:323-346`) | `json_success` students[] |
| 8 | `get_student_details` | **POST** | VIEW_ROLES/`view` (`:364`) | `Users/Parents/{this->parent_db_key}` — session-derived (`:370`) | `userId` required + `safe_path_segment` (`:368`) | READ `Users/Parents/{parent_db_key}/{userId}` (`:370`) | `json_success` student{} (19 mapped fields) |
| 9 | **`generate_certificate`** | **POST** | MANAGE_ROLES/`manage` (`:407`) | `_certBase` + `Users/Parents/{parent_db_key}` | `certType`∈CERT_TYPES, `templateId` required, `userId` required + `safe_path_segment`; `classKey`/`sectionKey` → `safe_path_segment` if present (`:417-425`) | READ template (`:431`), READ student (`:437`), R-I-W `Counters/certificateNumber` (`:444-445`), READ `Config/Profile` (`:478`), **WRITE `Issued/{certId}`** (`:519`) | `json_success` {certificateId, certificateNumber, certificate} |
| 10 | `get_issued` | GET(AJAX) | VIEW_ROLES/`view` (`:538`) | `_certBase` | none | READ `Issued` (`:540`) | `json_success` issued[] (includes revoked) |
| 11 | `get_certificate` | **POST** | VIEW_ROLES/`view` (`:575`) | `_certBase` | `certId` required + `safe_path_segment` (`:579`) | READ `Issued/{certId}` (`:581`), READ `Config/Profile` (`:587`) | `json_success` certificate{} + school{} |
| 12 | **`revoke_certificate`** | **POST** | MANAGE_ROLES/`manage` (`:608`) | `_certBase` | `certId` required + `safe_path_segment` (`:612`) | READ `Issued/{certId}` (`:614`), **UPDATE** `revoked/revokedAt/revokedBy` (`:619-623`) | `json_success` |
| 13 | `get_school_profile` | GET(AJAX) | VIEW_ROLES/`view` (`:633`) | `Schools/{school_name}/Config/Profile` — session-derived | none | READ `Config/Profile` (`:635`) | `json_success` school{} |
| — | `_resolveTemplatePath($id)` | private | n/a | n/a | whitelists `Bonafide/Transfer/Character` or regex `^TPL\d+$` under `Custom/` (`:658-664`); else `''` | none | string |
| — | `_replacePlaceholders($text,$data)` | private | n/a | n/a | none — raw `str_replace` (`:673-679`) | none | string |
| — | `_sanitizePlaceholderValues($data)` | private | n/a | n/a | `htmlspecialchars` per value (`:684-691`) | none | array — **defined, never called** (confirmed by repo-wide grep; only match is the definition at `:684`) |

Every method also passes through the constructor gate `require_permission('Certificates')` (`:63`, default level `view`) before any method body runs — a first, coarser layer beneath the per-method `_require_role` calls.

---

## 2. Issuance trace — `generate_certificate()` (`:402-527`), step by step

1. **Method + RBAC** (`:404-407`): POST required; `_require_role(MANAGE_ROLES, ..., 'Certificates', 'manage')`.
2. **Input capture** (`:409-414`): `certificateType`, `templateId`, `userId`, `classKey`, `sectionKey`, `extraData` (raw POST array, no shape validation).
3. **Validation** (`:417-425`): cert type whitelist, `templateId`/`userId` non-empty, all path-bound values pass `safe_path_segment` (blocks `/ . # $ [ ]` and non-alnum/space/`'`/`,`/`_`/`-` — `MY_Controller.php:1004-1006`).
4. **Template resolve+load** (`:428-434`): `_resolveTemplatePath` whitelists the ID shape (built-in name or `Custom/TPL\d+`), then `firebase->get()`. If `null`/non-array → `json_error('Template not found.')`. **Cannot distinguish "no such template" from "RTDB read failed"** (§8).
5. **Student load** (`:437-440`): `firebase->get("Users/Parents/{parent_db_key}/{userId}")`. Same ambiguity as above.
6. **Certificate number mint** (`:443-447`) — **the numbering path**:
   ```
   443  $counterPath = "{$this->_certBase}/Counters/certificateNumber";
   444  $counter = (int) ($this->firebase->get($counterPath) ?? 0) + 1;
   445  $this->firebase->set($counterPath, $counter);
   446  $year = date('Y');
   447  $certNumber = "CERT-{$year}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
   ```
   This is a **plain read-then-write, not a transaction** — no use of RTDB's `runTransaction`/`transactional update` API anywhere in `Firebase.php` (`get`/`set`/`update`/`delete`/`push` at `:283-336` are the entire write surface; none is transactional). Two concurrent `generate_certificate` requests can both `get()` the same counter value N, both compute N+1, and both `set()` N+1 — **one increment is lost**, and both requests derive the **same `$certNumber` and the same `$certId`** (`:450`, `'CRT' . str_pad($counter, 5, ...)`, from the *same* `$counter` value). The second `firebase->set("{$this->_certBase}/Issued/{$certId}", $issuedData)` (`:519`) then **silently overwrites** the first issued record at the same key — the first student's issued certificate is gone, no error, no log distinguishing this from a normal write.
7. **Placeholder data assembly** (`:453-475`): 20 keys built from student fields, session, admin-entered `extraData` (only accepted if its bracketed key matches a name in `self::PLACEHOLDERS`, `:487`), school profile address (`:478-481`).
8. **Placeholder substitution** (`:494-495`): `_replacePlaceholders($template['title']…, $placeholderData)` and same for `body` — raw sequential `str_replace`, **not** run through `_sanitizePlaceholderValues` (see §3).
9. **Issued record write** (`:498-519`): single `firebase->set()` of a full record including `certificateNumber`, `templateId`, `studentId`, resolved `templateData.{title,body}`, the entire `placeholderValues` map, `pdfUrl: ''`, `revoked: false`. **No prior existence check** — no `firebase->get("Issued/{$certId}")` before the `set()`; nothing checks whether this student already has a certificate of this type.
10. **Response** (`:521-526`): returns the full `$issuedData` back to the client as `certificate`.

**No PDF is produced anywhere in this file.** `pdfUrl` is hard-set to `''` at write time (`:515`) and never populated elsewhere in the file (repo grep for `pdfUrl`/`dompdf`/`mpdf`/`TCPDF` in this controller returns only that one assignment). The document a family receives is generated **client-side**, in `application/views/certificates/index.php:1161-1179` (`CERT.printCertificate`), which builds an HTML fragment in `#certPrintArea` from the AJAX response and calls `window.print()` after a 200 ms timeout (`:1178`) — i.e. it's a browser print of DOM content, not a server-generated, storable artifact. There is no stored, retrievable PDF/file — only the RTDB `Issued` record.

### Numbering verdict
**[CONFIRMED]** `generate_certificate()`'s counter at `Counters/certificateNumber` is a bare read‑then‑write with **no transaction, no lock, no optimistic-concurrency check** (`Certificates.php:443-445`; `Firebase.php` exposes only non-transactional `get/set/update/delete`, `:283-326`). Under two concurrent issuances this yields (a) a lost increment and (b) two requests computing the **identical** `$certNumber`/`$certId`, so the second `set()` **overwrites** the first `Issued/{certId}` record. This is the same TOCTOU class as the template-counter at `:244-245`.

### Idempotency / duplicate-issuance
**[CONFIRMED — absence]** No code path checks for an existing certificate before writing. `generate_certificate` never queries `Issued` for `studentId + certificateType` (or any other key) before minting. A user can call `generate_certificate` twice in a row for the same student/type/template and get two separate `Issued/{certId}` records with two different certificate numbers — the "issue" action is fully repeatable with no dedupe, no idempotency key, no confirmation-of-uniqueness step.

---

## 3. Tenant isolation

| Endpoint | Path built from | Client-influenced component? |
|---|---|---|
| All 13 public endpoints | `_certBase` = `Schools/{school_name}/{session_year}/Certificates` (`:64`) | `school_name`/`session_year` are session properties (`MY_Controller.php:296-298`), **not** read from request in this controller — none of the 13 endpoints accepts a `school`/`schoolId`/`session` POST param. |
| `get_students` | `Schools/{school}/{session}/{classKey}/Section {sectionKey}/Students/List` (`:315-346`) | `school`/`session` = session vars; `classKey`/`sectionKey` are client input but pass through `safe_path_segment` (character whitelist only, **not** a school-membership check) before interpolation — they can reference **any class/section key that exists under this school's own tree**, not another school's tree (base path is fixed). |
| `get_student_details`, `generate_certificate` (student load) | `Users/Parents/{parent_db_key}/{userId}` (`:370`, `:437`) | `parent_db_key` = `$this->school_code ?: $this->school_id` (session-derived, `MY_Controller.php:306`); `userId` is client input, `safe_path_segment`-validated only for character shape — **no ownership check that `userId` actually belongs to this school's roster.** A caller can supply any syntactically-valid `userId` string and the code will attempt `Users/Parents/{this school's parent_db_key}/{arbitrary userId}` — this stays inside the caller's own tenant (the parent_db_key segment is fixed), so it is **not** cross-tenant, but it **is** an unverified-ownership read/issuance target within the tenant (an admin could issue a certificate keyed to a `userId` that isn't actually enrolled, since `get_student_details`/`generate_certificate` only checks `is_array($student)`, not roster membership). |
| `save_template`/`delete_template` (`cid`), `get_certificate`/`revoke_certificate` (`certId`) | Interpolated under `_certBase` (session-fixed) | Client input only reaches the **leaf** segment, base is fixed and session-derived; `safe_path_segment`/regex-gated. |

**Verdict: [CONFIRMED]** No endpoint in this file accepts a school/session identifier from the client — `_certBase`'s tenant-defining prefix is 100% session-derived, so a request cannot redirect the path to another school's `Schools/{other}/...` subtree. This is **not** a P0 path-traversal defect.

**[INFERRED] No independent enforcement layer backs this.** `Firebase.php` connects via the Kreait **Admin SDK** with a service-account credential (`Firebase.php:44-96`), which always bypasses RTDB Security Rules — and in any case `firebase-rules/database.rules.json` sets only a blanket `".read"/".write": "auth != null"` at the root (`firebase-rules/database.rules.json:1-19`), with no per-school rule anywhere in the tree. So tenant isolation for every RTDB certificate path rests **entirely** on the PHP session values `school_name`/`session_year`/`parent_db_key` being correct and never sourced from the request — there is no second, independent gate (rules-level or otherwise) that would catch a bug in that assumption.

---

## 4. Placeholder substitution & injection analysis (§3 of mission)

- **`_replacePlaceholders()`** (`:673-679`): a plain loop of `str_replace($placeholder, $value, $text)` over the `$data` array, **in insertion order of `$placeholderData`** (built at `:454-475`, then `{school_address}` may be overwritten at `:480`, then `extraData` merged at `:484-491`). No escaping, no encoding — values are inserted into the template text as raw strings.
- **`_sanitizePlaceholderValues()`** (`:684-691`, `htmlspecialchars(..., ENT_QUOTES|ENT_HTML5, 'UTF-8')`) is **defined but has zero call sites** in this file — confirmed dead code, matching the Document Engine's own note. It is never invoked in the `generate_certificate` path or anywhere else.
- **Comment at `:493`** states: *"Resolve template content (XSS protection handled client-side by esc() function)"* — i.e. the escaping contract for this feature is deliberately pushed to the browser, not the server.
- **Client-side confirmation**: `application/views/certificates/index.php:545` defines `esc()` via `textContent`→`innerHTML` round-trip (safe HTML-encoding idiom), and every render path that displays `templateData.title`/`templateData.body` — the view modal (`index.php:1088,1096,1098`) and the print helper (`index.php:1168,1170`) — passes the value through `esc()` before writing to `innerHTML`. **[CONFIRMED]** Both retrieval/print surfaces this controller feeds do escape at render time, so **stored HTML in a placeholder value does not execute as markup in the certificate view/print UI** as currently wired.
- **Residual risk — [INFERRED], not exploitable via the audited render paths but present in stored data**: because the server never sanitises, the RTDB `Issued/{certId}` record permanently stores whatever the student profile or `extraData` contained (raw HTML/script text is preserved verbatim in `placeholderValues` and `templateData.{title,body}`, `:510-514`). Any *other* consumer of this data that renders it without an `esc()`-equivalent (a future admin view, a report export, a different app surface) would be exposed — the safety property is enforced by convention in one JS file, not by the data layer.
- **Second-order token bleed — [INFERRED]**: `_replacePlaceholders` re-scans the **entire accumulating `$text`** on every iteration (`:675-677`), not just the original template. If a student field (e.g. `Name`, `Father Name` — sourced from `Users/Parents/{parent_db_key}/{userId}`, `:455-471`, which is written by other SIS-side flows this controller doesn't control) itself contains a literal substring matching a placeholder token processed **later** in the loop (e.g. `{certificate_number}`, `{issue_date}`), that substring gets replaced too — cross-field content bleed into a certificate body, independent of HTML injection. Not verified exploitable end-to-end from this file alone (depends on what the SIS import/edit flows allow into `Name`/`Father Name` etc., out of scope of this file).
- **Template body itself is admin-authored** (`save_template`, `:209-262`) — no server-side sanitisation of `body`/`title` on save either (only non-empty and length via nothing — no max-length check at all).

---

## 5. `revoke_certificate()` (`:605-626`)

- **Effect**: `firebase->update("{$this->_certBase}/Issued/{$certId}", ['revoked'=>true,'revokedAt'=>date('c'),'revokedBy'=>$this->admin_id])` (`:619-623`) — a **flag**, not a delete. The full original record (student data, template content, certificate number) is preserved at the same key.
- **Read paths respect the flag inconsistently**: `get_dashboard` **excludes** revoked certs from stats/recent (`:122`, `if (!empty($cert['revoked'])) continue;`), but `get_issued` **includes** them and just reports the boolean (`:544-559`, no `revoked` filter — every issued cert, revoked or not, is returned), and `get_certificate` has **no revoked check at all** (`:572-600`) — a revoked certificate is still fully retrievable and still fully **printable** via `get_certificate`/`printById` (`index.php:1109-1127`), since the controller never inspects `cert['revoked']` before returning the record. **[CONFIRMED — gap]** revocation is enforced only in dashboard aggregation, not in the certificate-retrieval/print endpoint that actually produces the document.
- **Who can revoke**: MANAGE_ROLES / `manage` level on module `Certificates` (`:608`) — same grade as issuance.
- **No re-issue guard**: nothing prevents `generate_certificate` from being called again for the same student/type after a revoke — consistent with the "no idempotency anywhere" finding in §2.

---

## 6. `save_template` / `delete_template` — template lifecycle (`:209-285`)

- **Custom-template counter** (`:244-248`) — same TOCTOU shape as certificate numbering (§2 step 6): `get` counter → `+1` in PHP → `set` back, no transaction. Two concurrent "create new custom template" calls can collide on the same `TPL####` id, and the second `firebase->set()` **overwrites** the first template's data at that key silently.
- **Deleting a template that has already been used to issue certificates**: `delete_template` only removes `Templates/Custom/{cid}` (`:283`) — it never touches `Issued/*`. Because `generate_certificate` **denormalises** the resolved title/body into `Issued/{certId}.templateData` at issuance time (`:510-513`) and does not store a live reference beyond `templateId` (`:501`, a plain string), **already-issued certificates are unaffected by deleting their source template** — their content is a frozen copy. The only residual reference is the `templateId` string on the issued record, which becomes dangling (points to a template that `_resolveTemplatePath`/`get_templates` will no longer resolve/list) but nothing in this file dereferences it after issuance, so this does not break `get_certificate`/print. **[CONFIRMED]**
- **Built-in templates** (Bonafide/Transfer/Character) cannot be deleted at all (`:276-278`), only overwritten via `save_template`'s `update()` (`:257`) — which means editing a built-in template retroactively changes nothing on already-issued certs (same denormalisation reasoning above), but a future issuance from the "same" built-in slot will use the new body.

---

## 7. RBAC grade table

Every `_require_role()` call in this file supplies `($allowedRoleNames, $action, 'Certificates', $level)` — i.e. **all 13 public endpoints route through the graded `has_permission('Certificates', $level)` check first** (`MY_Controller.php:1253-1260`), and only fall back to the legacy role-name allow-list when the caller does **not** hold the `Certificates` module at all. Per the codebase's own documented defect shape ("a name-based role gate blocks custom roles"), that defect requires a call site to omit `$module`/`$level` — **none of the 14 call sites in this file do that** (verified against every `_require_role(` occurrence: `:76,107,170,212,270,296,307,364,407,538,575,608,633`, all pass `'Certificates'`). **[CONFIRMED]** This controller is *not* an instance of the Layer-2 name-gate defect — a custom role granted `Certificates` at the right level reaches every endpoint regardless of whether its name appears in `VIEW_ROLES`/`MANAGE_ROLES`.

| Endpoint | Required level | Mutating? | Reachable below `manage`? |
|---|---|---|---|
| `index`, `get_dashboard`, `get_templates`, `get_classes`, `get_students`, `get_student_details`, `get_issued`, `get_certificate`, `get_school_profile` | `view` | No | N/A (read-only by design) |
| `save_template` | `manage` | **Yes** | No — gated at `manage` |
| `delete_template` | `manage` | **Yes** | No |
| `generate_certificate` | `manage` | **Yes** (issues a document) | No |
| `revoke_certificate` | `manage` | **Yes** | No |

**[CONFIRMED — no gap]** No mutating endpoint is reachable at `view` or `edit`; all four require `manage`, consistently, both via the graded map and the legacy `MANAGE_ROLES` fallback list (`:39`). `Certificates` is one flat module with only two effective levels exercised here (`view`/`manage`) — `edit` is never checked, so a role granted `Certificates:edit` gets `view`-equivalent access only (cannot save/delete templates, generate, or revoke) — **[INFERRED]**, consistent with the module's binary view/manage design but means an `edit` grant is functionally identical to `view` in this controller.

---

## 8. CSRF / session posture

`application/config/config.php:184-213` (`$config['csrf_exclude_uris']`) — enumerated exclusions cover `superadmin/*`, two `fee_management` webhook routes, `admission/payment_webhook`, `auth/(.*)`, `staff_attendance/(.*)`, and two specific `attendance/*` POSTs. **`certificates/*` does not appear anywhere in this list** (confirmed by direct read of the array, `:184-213`). **[CONFIRMED]** Standard CodeIgniter CSRF protection applies unmodified to all `certificates/*` POST routes (`save_template`, `delete_template`, `get_students`, `get_student_details`, `generate_certificate`, `get_certificate`, `revoke_certificate`) — every mutating action requires the session CSRF token, and every `json_success`/`json_error` response refreshes it (`MY_Controller.php:1095`, `:1100-1108` region). No CSRF gap found in this file.

---

## 9. Error handling / read-failure taxonomy

`Firebase::get()` (`Firebase.php:283-288`) delegates to `_resilient()` (`:134-192`), which on **any** failure path — RTDB exception after retries, or the circuit breaker being open (`:137-141`) — returns the caller-supplied `$fallback`, which for `get()` is the default `null` (no `$fallback` arg passed, `:285-287`). **A real empty node** in RTDB also deserializes to `null` via the Admin SDK's `getValue()`. **[CONFIRMED — the codebase's catalogued pattern]**: `Firebase::get()` cannot distinguish "path has no data" from "read failed" — both produce `null`.

Every read site in this controller normalizes that `null` the same way — `if (!is_array($x)) $x = [];` (`get_dashboard:110`, `get_templates:173`, `get_issued:541`) — or treats it as a hard "not found" business error (`generate_certificate`: `if (!is_array($template))` → `'Template not found.'` `:432-434`; `if (!is_array($student))` → `'Student not found.'` `:438-440`; `get_certificate:582-584`; `revoke_certificate:615-617`).

Consequence: **[CONFIRMED]**
- On a dashboard/templates/issued-list transient RTDB outage, the endpoints silently render an **empty dashboard / empty template list / empty issued list** — indistinguishable in the response from "this school genuinely has zero certificates/templates," with no error surfaced to the operator.
- On a transient outage during `generate_certificate`, the student or template load can fail and the operator sees `"Student not found."` / `"Template not found."` — a **misleading, actionable-sounding error for what is actually an infrastructure fault**, indistinguishable in the UI from a real data problem. There is no retry prompt, no "try again" distinct message, no correlation ID surfaced.
- The one place a distinction *does* exist is in the server log (`log_message('error', "Firebase::{$op}() failed for [{$path}]...")`, `Firebase.php:188-189`) — but that is invisible to the operator experiencing the bug report this investigation was opened for.

---

## Counts

- Public endpoints traced: **13** (14 named in the mission brief; `revoke_certificate` and `get_certificate` are both present and distinct — the 14th name in the brief, `generate_certificate`, is the same as #9; the mission's list of "14" resolves to exactly the 13 public methods + the page-shell `index` already enumerated, i.e. all named endpoints are accounted for).
- Private helpers traced: **3** (`_resolveTemplatePath`, `_replacePlaceholders`, `_sanitizePlaceholderValues`).
- Endpoints requiring `manage` level (mutating): **4** (`save_template`, `delete_template`, `generate_certificate`, `revoke_certificate`).
- Endpoints requiring only `view`: **9**.
- Read-increment-write (non-transactional) counters found: **2** (`Templates/Custom/Counter` at `:244-245`; `Counters/certificateNumber` at `:444-445`).
- Idempotency/duplicate-issuance guards found in `generate_certificate`: **0**.
- Calls to `_sanitizePlaceholderValues()` anywhere in the file: **0** (dead code, confirmed by grep).
- Endpoints reading `Firebase::get()` without distinguishing "no data" from "read failure": **all 8** read-dependent endpoints (`get_dashboard`, `get_templates`, `get_students`, `get_student_details`, `generate_certificate`(x3 reads), `get_issued`, `get_certificate`, `revoke_certificate`, `get_school_profile`).
- `certificates/*` routes present in `csrf_exclude_uris`: **0**.
- PDF-generation call sites in this controller: **0** (`pdfUrl` hardcoded to `''`, `:515`).
- RTDB `database.rules.json` rules specific to `Schools/*/Certificates/*`: **0** (blanket root-level `auth != null`, no per-school scoping — `firebase-rules/database.rules.json:1-19`).

---

## Named gaps

1. **Certificate-number/ID minting is a non-atomic read-increment-write** (`:443-447`) — concurrent issuance loses increments and can silently overwrite an already-issued `Issued/{certId}` record with a second student's data at the identical key.
2. **No idempotency/duplicate check in `generate_certificate`** — the same student/type/template can be issued arbitrarily many times with no dedupe.
3. **`revoke_certificate` is a flag, not enforced everywhere** — `get_certificate` (and therefore print) returns and prints revoked certificates unfiltered (`:572-600`); only `get_dashboard` excludes them (`:122`).
4. **`_sanitizePlaceholderValues()` is dead code** — zero call sites; server-side escaping does not happen anywhere in the issuance path; XSS protection is entirely delegated to a client `esc()` convention (comment at `:493`), which is honoured by the two current render sites but is not a data-layer guarantee.
5. **Template-custom-ID counter has the identical TOCTOU shape as the certificate counter** (`:244-245`).
6. **`Firebase::get()` cannot distinguish "no data" from "read failed"** (`Firebase.php:283-288`, `:134-192`), and this controller propagates that ambiguity into user-facing "not found" errors and silently-empty dashboards/lists.
7. **No independent tenant-scoping enforcement layer** — RTDB rules are a blanket `auth != null` (`firebase-rules/database.rules.json`) and are bypassed anyway by the Admin SDK credential (`Firebase.php:44-96`); all isolation depends on PHP session values never being attacker-influenced.
8. **`generate_certificate`/`get_student_details` do not verify the target `userId` is actually enrolled/on-roster** — only that `Users/Parents/{parent_db_key}/{userId}` resolves to an array (`:370`,`:437-440`); no cross-check against `get_students`' roster output.
9. **No PDF or durable file artifact is produced** — `pdfUrl` is always `''`; the printed document is a transient client-side `window.print()` of DOM content (`index.php:1161-1179`), not a stored, re-fetchable, or checksummable record.
10. **Second-order placeholder token bleed** in `_replacePlaceholders` (`:673-679`) — sequential `str_replace` re-scans already-substituted text, so a student field containing a literal later-processed placeholder token gets further substituted (content-bleed, not code-injection).
11. **`ENT_QUOTES` template body has no length or content limit on `save_template`** (`:214-262`) — no max-length validation on `body`/`title`.
12. **`extraData` merge is allow-listed by key but not type/length-checked** (`:484-491`) — accepted values are only `trim()`'d, no length cap, no character restriction beyond eventual client-side `esc()`.

---

## `[UNKNOWN]`s

- What writes to `Users/Parents/{parent_db_key}/{userId}` (the SIS/admission flow) and whether it constrains `Name`/`Father Name`/etc. content — out of scope of this file; determines real exploitability of gap #10 and the residual-storage risk in §4.
- What `_get_session_classes()` (called by `get_classes`, `:297`) does internally — not defined in this file, not traced.
- Whether any other surface (a report export, a future admin certificate list, the parent app) ever renders `Issued/*.templateData` or `.placeholderValues` **without** the `esc()` convention — would upgrade §4's residual risk from theoretical to a live XSS/stored-injection finding. Not checked in this pass (out of file scope).
- Whether `Config/Profile.Logo` (`:593`, rendered as an `<img src>` at `index.php:1091,1164` without protocol/URL validation) could carry a `javascript:`/data-URI value — not traced; `Config/Profile` is written elsewhere in the codebase, out of scope here.
