# L2 · Legacy Certificate System — Data Model & UI Investigation

Scope: `application/views/certificates/index.php` (1187 lines), `application/controllers/Certificates.php` (692 lines, reference only), `firebase-rules/database.rules.json`. Evidence ceiling E2 — static analysis only, no runtime claims, no PASS verdicts. This is the LEGACY RTDB certificate system (`Schools/{schoolId}/{session}/Certificates/...`), distinct from the newer Firestore "Document Engine" (`blueprints/certificates/`, `assets/js/doctemplates/designer.js`) — that system is out of scope.

---

## A · RTDB Data Model

### A.1 Shape (as written by Certificates.php)

```
Schools/{schoolId}/{session}/Certificates/
├── Templates/
│   ├── Bonafide/                          [Certificates.php:257]
│   │   { name, title, body, type:"bonafide", updatedAt, updatedBy, createdAt? }
│   ├── Transfer/                          [Certificates.php:257]  (same shape, type:"transfer")
│   ├── Character/                         [Certificates.php:257]  (same shape, type:"character")
│   └── Custom/
│       ├── Counter: int                   [Certificates.php:244-245]
│       ├── TPL0001/                       [Certificates.php:246-248]
│       │   { name, title, body, type:"custom", updatedAt, updatedBy, createdAt }
│       └── TPLxxxx/ ...
├── Issued/
│   ├── CRT00001/                          [Certificates.php:519, id built at :450]
│   │   {
│   │     certificateNumber,      // "CERT-{year}-00001"                [:447]
│   │     certificateType,        // bonafide|transfer|character|custom [:500]
│   │     templateId,             // e.g. "Bonafide" or "Custom/TPL0001" [:501] — stored but NEVER dereferenced again (dead reference, see A.3)
│   │     studentId,              // = userId                          [:502]
│   │     studentName,                                                  [:503]
│   │     classKey, sectionKey,                                         [:504-505]
│   │     issueDate,              // server date('Y-m-d')                [:506]
│   │     issuedBy, issuedById,                                          [:507-508]
│   │     createdAt,              // date('c')                          [:509]
│   │     templateData: { title, body },   // FULLY RESOLVED text, baked in at issue time [:510-513]
│   │     placeholderValues: {...all 20 placeholder keys, resolved},     [:514]
│   │     pdfUrl: "",             // ALWAYS empty — dead field, see A.4  [:515]
│   │     revoked: false                                                [:516]
│   │     // on revoke, MERGED (update, not overwrite): revokedAt, revokedBy [:619-623]
│   │   }
│   └── CRTxxxxx/ ...
└── Counters/
    └── certificateNumber: int             [:443-445] shared read-increment-write counter, non-atomic (see finding F9)
```

External reads (outside the Certificates subtree, never written by this controller):
- `Schools/{school}/Config/Profile` — school Address/Phone/Email/Logo/State, read for print header [Certificates.php:478, 587, 635] and school-session-independent (shared across all sessions).
- `Users/Parents/{parent_db_key}/{userId}` — student source-of-truth (Name, Father Name, Mother Name, Class, Section, DOB, Admission Number, Gender, Nationality, Religion, Caste, Admission Date, Photo) [:370, :437] — read live only at issue time, snapshotted into `placeholderValues`.
- `Schools/{school}/{session}/{classKey}/{Section X}/Students/List` — roster for dropdowns [:323-345].

### A.2 THE CENTRAL QUESTION — durability of an issued certificate against later template edit/delete

**[CONFIRMED] An issued certificate is fully self-contained and immune to later template edits or deletion.**

Trace:
1. At issue time, `generate_certificate` loads the live template, resolves all `{placeholder}` tokens against a live student-profile snapshot, and writes the **resolved** output into `Issued/{certId}/templateData.{title,body}` — Certificates.php:428-435 (load template), :494-495 (resolve), :510-513 (store resolved text, not a template reference).
2. Every subsequent read of that certificate — `get_certificate()` (Certificates.php:572-600), which backs `CERT.issued.view()` (index.php:1070-1107), `CERT.issued.printById()` (index.php:1109-1127), and `CERT.issued.printCert()` (index.php:1129-1142) — reads **only** `Issued/{certId}`. None of these three render paths ever re-fetches `Templates/*` or re-runs placeholder substitution; they read `cert.templateData.title` / `cert.templateData.body` verbatim (index.php:1086-1088, :1116-1119, :1132-1136).
3. `save_template()` (:209-262) and `delete_template()` (:267-285) only ever touch `Templates/{type}` or `Templates/Custom/{cid}`. Neither writes to, nor is invoked from, any path under `Issued/`.
4. Therefore: editing a template's body/title after certificates were issued from it changes nothing about those certificates. Deleting the template (custom only — built-ins can't be deleted, :276-278) likewise leaves already-issued certificates fully renderable, since they never dereference `templateId` back into `Templates/`.

**Residual gap [CONFIRMED — minor]:** `templateId` is stored on the issued record (:501) but is a dead field — nothing ever reads it back. It exists as an audit breadcrumb only; there is no "resync from live template" feature, so this is inert, not a hazard.

**Residual gap [CONFIRMED — moderate, security]:** `save_template()` trusts the client-supplied `type` field to route between the built-in branch (`Templates/{ucfirst(type)}`) and the custom branch, keyed only on whether `editId` starts with `"Custom/"` (:235-259). The UI disables the type `<select>` for built-ins client-side (index.php:724) but the server does not cross-check that a built-in `editId` (e.g. `"Transfer"`) still matches the submitted `type`. A crafted POST with `id=Transfer&type=bonafide` would `update("Templates/Bonafide", ...)` instead of `Templates/Transfer` — silently overwriting the *wrong* built-in template while leaving the intended one unedited. Client-side-only enforcement of an editability constraint.

### A.3 & A.4 Other data-model absences
- **[CONFIRMED] `pdfUrl` is a dead field.** Reserved in the schema (:515) but never populated anywhere in the controller — no PDF generation/upload code exists. "Print" is exclusively `window.print()` on client-rendered HTML (index.php:1161-1179); there is no server-authored PDF, no immutable artifact, no download-and-verify path. Absence noted per instructions.
- **[CONFIRMED] Certificate-number counter is non-atomic.** `Counters/certificateNumber` is read, incremented in PHP, then written back (:443-445) — a classic read-then-write race. Concurrent `generate_certificate` calls (two admins issuing at once) can read the same counter value before either write lands, producing duplicate `certificateNumber`/`certId` — RTDB has no transaction used here (no `runTransaction`/conditional write). [INFERRED — plausible under concurrent use, not runtime-verified per E2].

---

## B · RTDB Security Rules

**[CONFIRMED] `Schools/{schoolId}/{session}/Certificates/**` has no dedicated rule block — it inherits the ROOT rule.**

`firebase-rules/database.rules.json` (22 lines total):
```json
{
  "rules": {
    ".read": "auth != null",
    ".write": "auth != null",
    "Indexes": { "School_codes": {".read": true, ".write": "auth != null"}, "School_names": {...} },
    "School_ids": {".read": true, ".write": "auth != null"}
  }
}
```
There is no `"Schools"` key anywhere in this file. RTDB rules cascade to children with no override, so `Schools/{anySchoolId}/{anySession}/Certificates/Issued/{anyCertId}` is governed solely by the root `.read: auth != null` / `.write: auth != null`.

**Verdict: any authenticated Firebase user of the shared `graderadmin` project — from ANY school, in ANY role (parent, student, teacher) — can read AND write every school's entire `Certificates` tree**, including `Issued/*` records that contain student PII (name, DOB, father/mother name, religion, caste, nationality, admission number — all in `placeholderValues`). There is no `school_id`-scoping, no role check, no session check at the rules layer for this path at all.

- The panel is unaffected in practice (it uses a server-side Firebase Admin credential that bypasses rules), so this does not break the PHP UI being audited here.
- The **two Android apps use client SDKs** and are therefore directly exposed to this rule if either app ever reads/writes this RTDB path with a normal user's ID token — a cross-tenant certificate/PII leak, and a write path that lets any authenticated user fabricate or corrupt another school's issued-certificate records or counters.
- `firebase-rules/database.rules.json.rollback` is present in the same directory and is **even more open** — `{".read": "true", ".write": "true"}`, no auth required at all. Its filename suggests a saved prior/rollback state, not necessarily the live ruleset.

**[UNKNOWN]** Whether the committed `database.rules.json` (auth-gated-but-unscoped) or the `.rollback` file (fully public) — or something else entirely — is what's actually deployed to the live `graderadmin` RTDB instance right now. Unlike Firestore (`node aegis/cli.js rules status`), there is **no equivalent live-state sentinel for RTDB** in this repo; this finding is git-only. Given CLAUDE.md's own note that "this codebase has a catalogued history of production RTDB rules being wide open," the live state should be pulled directly from the Firebase console/Admin SDK before treating either file as authoritative.

---

## C · Interface Journey — screen by screen

| Screen | Loads via | Can fail | On failure, user sees |
|---|---|---|---|
| Dashboard | `GET certificates/get_dashboard` on tab activate [index.php:600-634] | permission, network, session-expiry | Graceful JSON error → inline message (:605). Transport failure (no `.fail()`) → stuck on static "Loading..." skeleton forever, no message. |
| Templates list | `GET certificates/get_templates` [:641-654] | same | Graceful → inline message (:645). Transport failure → stuck on skeleton forever. |
| Generate — Step 1 (Select Student) | Class dropdown from `_classes` (populated once, at page init, by `loadClasses()`) [:558-566, :1182]; Section from `_classes` client-side filter [:582-592]; Student list via `POST get_students` on class/section change [:826-842] | see §C.5 below (Class dropdown empty-on-failure is the reported bug) | Class/Section: none — dropdown silently stays at placeholder-only. Students: `get_students` failure → silent `return` (:833), dropdown left however it was — no message either. |
| Generate — Step 2 (Choose Template) | `_templates` (fetched once at `CERT.gen.init()` if not already cached, or already loaded by the Templates tab) [:805-816]; student details via `POST get_student_details` on "Next" [:857-873] | same | `get_templates` in `gen.init`: checked for `status==='success'` but **nothing rendered on failure at all** — no toast, no inline error (:809-816) — Template dropdown in Step 2 will just be empty with no explanation. `get_student_details` failure: **does** toast the message (:860). |
| Generate — Step 3 (Preview & Issue) | Entirely client-side render from cached `_student` + `_template` + form fields [:890-953] | no network call to build the preview | N/A — preview is synthesized in-browser, cannot itself "fail" to load, but see §C.3 for correctness gap |
| Issued list | `GET certificates/get_issued` [:1013-1024] | same | Graceful → inline message (:1017). Transport failure → skeleton forever. |
| View Certificate modal | `POST certificates/get_certificate` [:1070-1107] | same | Graceful → inline error in modal body (:1077). Transport failure → modal stuck on "Loading..." spinner forever. |
| Print (list) | `POST certificates/get_certificate` then `window.print()` [:1109-1127] | same | Graceful → `toast('Error loading certificate.')` (:1112). Transport failure → **nothing happens at all** — user clicks Print, no dialog, no error, no feedback. |

### C.1 fetch/$.ajax success-checking audit — every call site

13 distinct AJAX call sites, all via the shared `post()`/`get()` wrappers (index.php:504-515), which are thin `$.ajax(...)` wrappers with no built-in status/response validation of their own — each caller must check.

| # | Call | Method | Checks `r.status==='success'` before showing success? | Has `.fail()` (transport-level error handling)? |
|---|---|---|---|---|
| 1 | `get_classes` (loadClasses, :558-566) | GET | Yes, but silent no-op on failure (no message) | **No** |
| 2 | `get_dashboard` (:600-634) | GET | Yes, inline error | **No** |
| 3 | `get_templates` (tpl.load, :641-654) | GET | Yes, inline error | **No** |
| 4 | `save_template` (:754-780) | POST | Yes, toast | Yes (:778) |
| 5 | `delete_template` (:782-795) | POST | Yes, toast | Yes (:794) |
| 6 | `get_templates` (gen.init, :805-816) | GET | Yes, but **silent no-op on failure** (no message, no UI update) | **No** |
| 7 | `get_students` (:826-842) | POST | Yes, but **silent `return` on failure** (no message) | **No** |
| 8 | `get_student_details` (:857-873) | POST | Yes, toast | **No** |
| 9 | `generate_certificate` (:955-994) | POST | Yes, toast, comment explicitly says "Fail-closed" | Yes (:993) |
| 10 | `get_issued` (:1013-1024) | GET | Yes, inline error | **No** |
| 11 | `get_certificate` (issued.view, :1070-1107) | POST | Yes, inline error in modal | **No** |
| 12 | `get_certificate` (issued.printById, :1109-1127) | POST | Yes, toast | **No** |
| 13 | `revoke_certificate` (:1144-1157) | POST | Yes, toast | Yes (:1156) |

**Finding: this file does NOT exhibit the codebase's classic "reports success on a failed request" defect shape.** All 13 call sites correctly gate the success path on `r && r.status === 'success'` — none of them show a success toast/state on a graceful JSON error. The 4 mutation endpoints (save/delete template, issue, revoke) are additionally hardened with explicit `.fail()` handlers and code comments naming the fail-closed intent.

**What WAS found instead — the inverse defect: silent stall/no-feedback on failure**, concentrated entirely in the 9 read/GET-shaped call sites (#1,2,3,6,7,10,11,12 lack `.fail()`; #8 lacks `.fail()` too though it does toast graceful errors). None of these 9 attach a `.fail()` handler, so any transport-level failure — a session-expired redirect returning an HTML login page instead of JSON (breaks `dataType:'json'` parsing), a dropped connection, a 500 with a non-JSON body — never reaches the `.done()` callback, is never surfaced, and the corresponding UI element is left exactly where it started: a "Loading..." skeleton, a placeholder-only dropdown, or (for Print) total silence.

### C.2 Loading / empty / error state coverage

- **Loading:** present as static skeleton markup for Dashboard, Templates, Issued, and View-Certificate-modal (`skel()` helper, :522-528, and the modal's own "Loading..." span). **Absent** for Generate Step 1's dropdowns (page ships with the final `<option>Select Class</option>` placeholder already in the DOM — no distinct "loading classes" state) and for Print (no loading indicator between click and print dialog).
- **Empty:** present for Dashboard recent list (:616), Templates list (:661), Issued list (:1039). **Absent** for Generate Step 1/2 dropdowns — an empty class/template list renders identically to a *failed* class/template load (both show only the placeholder option), so the user cannot distinguish "this school genuinely has no classes/templates yet" from "the request silently failed."
- **Error:** present (inline, distinguishable from empty) for Dashboard, Templates, Issued lists and the View-Certificate modal — but only for the *graceful JSON error* case, not the *transport failure* case (see C.1). **Absent entirely** for Generate Step 1's class/section/student dropdowns and for Print — these have no error state at all, graceful or otherwise, for the class/template fetches (#1, #6, #7 above never render anything on failure).

### C.3 Preview & Issue — is the preview what actually gets issued?

**[CONFIRMED] No — the preview and the issued certificate are built by two independent implementations, and can diverge.**

- **Preview (Step 3, `CERT.gen.toStep3()`, index.php:890-953)** is rendered **entirely client-side**: it reuses whatever `_template` object was already fetched into the page's JS state and whatever `_student` object was fetched at Step 2, then runs its own placeholder-substitution loop in JavaScript (:902-931, `body.split(k).join(replacements[k])`).
- **Issue (`generate_certificate`, Certificates.php:402-527)** re-fetches the template and the student profile **fresh from RTDB at submit time** (:428-437) and runs a **separate, server-side** PHP substitution (`_replacePlaceholders`, :673-679). It does not reuse anything the client already had.
- Concrete divergences found by comparing the two placeholder maps:
  - `{school_address}` is hard-coded to `''` in the client preview (:912) — the client never fetches the school profile. The server, however, does fetch `Schools/{school}/Config/Profile` and injects the real address (:478-481, :464→:480). **The previewed certificate is always missing the school address that the issued/printed one will show.**
  - `{certificate_number}` is the literal placeholder text `"(auto-generated)"` in the preview (:913) — the real number is assigned only by the server counter at issue time (:443-450). **The user can never preview the actual certificate number before committing to issue.**
  - `{issue_date}` in the preview is computed client-side as `new Date().toISOString().substring(0,10)` (:910) — i.e. **UTC** calendar date — while the server uses `date('Y-m-d')` in server-local time (Certificates.php:453). Near a UTC/local midnight boundary these can disagree by a day, and the preview cannot know which the server will actually stamp.
  - Because the server re-reads the template and student profile live rather than trusting anything from the client, a template edit or student-profile edit made in the window between "Preview" and clicking "Issue Certificate" changes what actually gets issued, silently, with no re-preview and no diff shown to the operator. The success toast on issue only echoes the certificate number (:982) — it never re-displays the final resolved text for comparison against what was previewed.
- The Print button *within* the preview step (`CERT.gen.print()`, :997-1005) prints the client-resolved `_resolvedBody`/`_resolvedTitle` — i.e., it prints the (incomplete, address-less, fake-numbered) preview text, not server output — consistent with it being a preview, but worth noting since it looks identical in layout to the post-issue print.

### C.4 Destructive actions

| Action | Confirmation | Actually irreversible? |
|---|---|---|
| Revoke certificate (:1144-1157) | Native `confirm('...This cannot be undone.')` | Data-level: no — `revoked` is just a boolean flag set via `update()` (Certificates.php:619-623), nothing is deleted. UI-level: yes — there is no "un-revoke" affordance anywhere in this file. |
| Delete custom template (:782-795) | Native `confirm('Delete this template?')` | Yes, at the data level (`firebase.delete`, Certificates.php:283) — but per §A.2 this is harmless to already-issued certificates. Built-in templates cannot be deleted at all (client :676, server :276-278) — consistently enforced both sides, unlike the type-switch gap in §A.2. |
| Issue certificate | No confirmation dialog at all — "Issue Certificate" (:382) fires immediately on click, no "are you sure" step, despite writing a permanent RTDB record and consuming a sequential certificate number. | Effectively irreversible in normal use (no delete-issued-certificate feature exists — only revoke). |

### C.5 Session-expiry mid-flow — the reported empty Class dropdown

**[CONFIRMED] Root cause traced.** `loadClasses()` (index.php:558-566) is called exactly once, at page init (:1182: `loadClasses(function() { loadTabData(ACTIVE_TAB); }); `), and populates the module-level `_classes` array that both `populateClassSelect('gen_classKey')` (Step 1's Class dropdown, invoked from `CERT.gen.init()` at :806) and `populateSectionSelect()` (Section dropdown) read from.

Two independent gaps converge on the same symptom:
1. **No `.fail()` handler on `get('certificates/get_classes')` (:559).** If the session has expired, CodeIgniter's auth/session guard (outside this file) will typically redirect an unauthenticated request rather than return the app's JSON envelope — with `dataType:'json'` set, jQuery cannot parse a login-page HTML response and rejects the promise. Since only `.done()` is attached, that rejection is swallowed silently: `cb()` never runs, so `loadTabData(ACTIVE_TAB)` **never executes at all** — not just the class dropdown but the entire active tab (Dashboard stats, Templates list, Issued list) is left on its initial static markup, with zero error message anywhere on the page.
2. **Even a graceful JSON failure produces the same visible symptom.** If `get_classes` instead returns `{status:'error', ...}` cleanly, `.done()` *does* fire, but the check at :560 (`if (r && r.status === 'success')`) simply skips populating `_classes` — leaving it `[]`. `cb()` still runs, `loadTabData()` still executes, `populateClassSelect()` renders only its own hard-coded `<option value="">Select Class</option>` (:571) — i.e. the exact same visually-empty dropdown as gap #1, again with no message anywhere.

**Every other place the same pattern occurs** (call sites without `.fail()`, enumerated fully in §C.1): `get_dashboard`, `get_templates` (both call sites), `get_students`, `get_student_details` (has a toast for the graceful case only, not transport failure), `get_issued`, `get_certificate` (both call sites — View modal and Print). A session expiring mid-flow anywhere in this module — not just at initial page load — produces the same class of failure: whatever screen/dropdown/modal was mid-fetch simply freezes on its loading/placeholder state, with **no message**, at 8 of the 9 read-call sites.

---

## D · Template editing (authoring UI)

- Authored via a single modal (`#templateModal`, index.php:425-463) shared for create and edit, opened by `CERT.tpl.openModal()`. Fields: Name (required), Type (`bonafide|transfer|character|custom` — disabled for built-ins, :724), Title (optional — server falls back to Name if blank, Certificates.php:228), Body (required, plain `<textarea>`, no rich-text/WYSIWYG).
- **No preview inside the editor itself** other than the separate read-only "Preview" eye-icon action in the Templates list (`CERT.tpl.preview()`, :739-752), which renders the raw unsubstituted `{placeholder}` tokens verbatim (no sample data) — so an author previewing a template sees literal `{student_name}` text, not a mock-filled certificate.
- **Placeholders** are a fixed, server-defined whitelist of 20 tokens (`Certificates::PLACEHOLDERS`, :48-56), delivered to the client via `get_templates`'s response (:200-203) and rendered as clickable chips (`renderPlaceholders()`, :685-696) in both the Templates tab reference panel and inside the edit modal. Clicking a chip inserts it at the textarea cursor if the body textarea is focused, else copies to clipboard (`copyPH()`, :698-712). There is no validation, on save or on issue, that the body text uses only whitelisted placeholders or that all brace-delimited tokens are recognized — an unrecognized `{foo}` token is simply never substituted and prints literally in the final certificate (confirmed by tracing `_replacePlaceholders`, Certificates.php:673-679: it only replaces keys present in `$data`, leaving anything else untouched) — **no author-facing warning for a typo'd placeholder**.
- Body content is free-text; the server explicitly defers all XSS protection to the client (`_replacePlaceholders`'s own doc-comment: "XSS protection handled client-side by esc() function", Certificates.php:671-672; `_sanitizePlaceholderValues()` at :684-691 exists but a repo-wide check shows it is **never called** from `generate_certificate()` or anywhere else — dead code). The view-side `esc()` (index.php:545) does correctly HTML-escape both `templateData.title`/`.body` wherever rendered (:1088, :1096, :1118, :1168, etc.), so display is safe in practice — but this makes the server-side sanitizer function pure dead code, and means the only XSS defense is client-side escaping applied at *render* time, never at *storage* time (defense-in-depth gap: a future render path that forgets `esc()` would be unprotected, since raw unescaped text is what's actually stored).

---

## Counts

- AJAX call sites: **13** total. **4** have `.fail()` transport-error handling (all 4 are write/mutation endpoints: save_template, delete_template, generate_certificate, revoke_certificate). **9** lack `.fail()` (all read/GET-shaped: get_classes, get_dashboard, get_templates ×2, get_students, get_student_details, get_issued, get_certificate ×2).
- Call sites that show a success state without checking `r.status==='success'` first: **0** (all 13 correctly gate).
- Call sites with a visible error/inline-message state for a *graceful* JSON failure: **8** of 13 (all but get_classes, get_templates-in-gen.init, get_students — those 3 are silent no-ops even on a clean error response).
- Call sites with no error state at all (graceful or transport): **3** — `get_classes` (:558-566), `get_templates` in `CERT.gen.init` (:805-816), `get_students` (:826-842).
- Screens with a distinct loading skeleton: **4** (Dashboard, Templates, Issued, View-Certificate modal). Screens without one: Generate Step 1 dropdowns, Print.
- Screens with a distinct empty-vs-error state: **4** (Dashboard, Templates, Issued lists; View-Certificate modal — graceful-failure branch only). Screens where empty and failed are visually identical: Generate Step 1/2 dropdowns (Class, Section, Student, Template).
- Destructive actions: **2** with a native `confirm()` (revoke, delete-custom-template); **1** with none at all (issue certificate — permanent, sequential-number-consuming, no confirmation step).
- Dead/unimplemented fields found: **2** — `pdfUrl` (always `''`, Certificates.php:515), `_sanitizePlaceholderValues()` (defined :684-691, never called).
- Certificate-content divergence points between Preview and Issue: **3** — `{school_address}` (always blank in preview), `{certificate_number}` (placeholder text in preview), `{issue_date}` (UTC-client vs local-server).

## Named gaps (absence findings)

1. No RTDB rule block exists for `Schools/**` at all — the entire multi-tenant certificate tree (and everything else under `Schools/`) runs on the bare root `auth != null` rule, with no per-school scoping. [CONFIRMED, static/git-only]
2. No RTDB-equivalent of the Firestore Rules Sentinel exists in this repo — live RTDB rule state for this path is genuinely unknown, only inferable from git. [UNKNOWN]
3. No confirmation step before issuing a certificate (only before revoke/delete). [CONFIRMED]
4. No preview of the true certificate number or school address before issue. [CONFIRMED]
5. No server-side re-validation that a built-in template's `type` matches its `id` on save — client-only enforcement. [CONFIRMED]
6. No transaction/atomic increment on the certificate-number counter — read-increment-write race. [CONFIRMED by code shape, not runtime-verified]
7. `pdfUrl` field and `_sanitizePlaceholderValues()` function are both dead code — reserved/half-built and never wired up. [CONFIRMED]
