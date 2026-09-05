# 04 · Parity matrix — Document Engine (Certificates)

**Agent: A7 · DIFF-ANALYST. Evidence ceiling: E2 (static source read only — nothing executed,
nothing observed at runtime).** Every claim cites `file:line`. Classified `[CONFIRMED]` (the
exact code/config read), `[INFERRED]` (reasoned from adjacent code or documented language
semantics, not directly observed), `[UNKNOWN]` (not reached this pass), `[CONTESTED]`
(two artefacts disagree). This document OVERWRITES the prior `04-parity-matrix.md`.

**Mandate re-aim, stated up front:** the apps carry zero Document Engine code
(`01d-mobile-absence.md`), so classic cross-surface UI parity does not apply here. This pass
instead covers the three real divergence axes the module actually has: the client/server
contract-catalogue duplication, the two certificate *systems* sharing one RBAC key, and the two
independent fee-receipt implementations — plus a client/server validation table (Axis 4).

---

## AXIS 2 — the ISSUE question, answered first

**Does the legacy `Certificates.php` controller issue documents? Yes — today, live, from an
untested RTDB prototype.**

`Certificates::generate_certificate()` (`application/controllers/Certificates.php:402-527`)
resolves `{placeholder}` tokens against a real student profile read from
`Users/Parents/{parent_db_key}/{userId}` (`:437`), mints a certificate number via a
read-increment-write counter (`:443-447`, "best-effort atomicity" — the same TOCTOU shape as the
Document Engine's `create()` numbering, `01b-backend-spec.md §4`), and **writes a complete issued
record** — resolved title/body, certificate number, student identity, issuer identity — to
`Schools/{school}/{session}/Certificates/Issued/{certId}` (`:519`). This is a real write of a
real, student-identified document, gated at `manage` level (`self::MANAGE_ROLES`,
`:407`/`_require_role(..., 'manage')`), reachable via 4 explicit routes
(`routes.php:1234-1251`), with **zero PHPUnit tests** (`00-dependency-graph.md §6`, re-confirmed:
no `Certificates`/`CertificatesTest` class anywhere in `tests/`).

**This reframes the module's central claim.** `document_targets.php`'s `CON-NO_PRINT_IMPL` and
`Doc_resolver`'s structural inertness (`01b-backend-spec.md §9`) are both true *for the Document
Engine specifically* — but they describe only one of two live certificate-issuing paths in this
codebase. **The ecosystem issues certificates today.** It does so from the system that is (a) in
the sidebar (`_live-state.md` L1), (b) untested, and (c) the one *not* under certification.

**Consequence for RBAC.** Both controllers are gated by the same capability key, `'Certificates'`
(`Certificates.php:63` `require_permission('Certificates')`; `Doc_templates.php:32`
`const MODULE = 'Certificates'`). `_require_role()` correctly prefers the graded RBAC bridge over
its legacy role-name fallback when the module is held (`MY_Controller.php:1230-1265`,
`[CONFIRMED]` — not itself a gap). The practical effect: a staff member holding `Certificates:
manage` can, in the same session, (1) design, publish and activate templates in the untested
Document Engine that print nothing, **and** (2) generate and issue real certificates from the
legacy dashboard that the sidebar actually links to. A staff member holding only `Certificates:
edit` can reach the Document Engine's drafting surface but only the *view*-graded legacy
endpoints (dashboard/templates/issued reads) — `generate_certificate`/`save_template`/
`revoke_certificate` all require `manage` (`Certificates.php:212,407,608`).

### Axis 2 capability matrix

| Capability | Legacy `Certificates.php` | Document Engine `Doc_templates.php` |
|---|---|---|
| Issues a real, student-identified document | **present** — `generate_certificate()` (`:402-527`) | absent — `CON-NO_PRINT_IMPL`, `Doc_resolver` has no write callable (`01b §9`) |
| Storage | RTDB, `Schools/{school}/{session}/Certificates/*` — **session-scoped** | Firestore `documentTemplates`/`documentTemplateVersions` — **not session-scoped** (`01c-data-spec.md §1a`; a template persists across academic sessions, a behavioural divergence from every other session-scoped write in this codebase per `CLAUDE.md`'s "every query is scoped twice" rule) |
| Document types supported | 4, hardcoded: `bonafide, transfer, character, custom` (`Certificates.php:45`) | 8 declared (`transfer_certificate, bonafide, character, school_education_certificate, leaving_certificate_5a, study, migration[disabled], fee_receipt`) + unbounded `custom:*` |
| Field source at issue time | Live student record (`Users/Parents/...`) + admin-typed `extraData` | None — nothing resolves real data; `preview`/`validate` bind only `sampleBundle()` (`Doc_templates.php:724-726`) |
| Merge mechanism | Raw `str_replace` token substitution, no schema (`_replacePlaceholders`, `:673-679`) | Schema-driven `Doc_contract`/`Doc_serializer`, fail-closed on unresolved fields (by design; not traced end-to-end into `Doc_serializer.php`, `[UNKNOWN]` per `01c §13`) |
| Output escaping | `_sanitizePlaceholderValues()` defined (`:684-691`) but **dead code — zero callers** (`[CONFIRMED]`, grep); escaping instead happens ad hoc at each client render site via `esc()` (`application/views/certificates/index.php:545,1088,1168,1170` — sampled sites all escape) | mPDF renderer, not traced this pass |
| Tests | 0 PHPUnit files | 16 PHPUnit files + 9 Jest `describe()` blocks |
| Sidebar | **linked** (`header.php:666-669`) | not linked (`_live-state.md` L1) |
| RBAC module key | `'Certificates'` (shared) | `'Certificates'` (shared) |

**Named gap:** which system is authoritative — or whether the legacy issuer should be retired
before the Document Engine's print seam is ever wired — is a business decision the code cannot
settle (`H3` per `_live-state.md`). Not settled here.

---

## AXIS 1 — the three-place contract catalogue

### What `DocContractParityTest` actually covers

Read in full: `tests/Unit/DocContractParityTest.php` (417 lines, 9 test methods). It parses
`assets/js/doctemplates/designer.js`'s `CONTRACT`, `CONTRACTS`, `TYPES` arrays with regexes and
asserts them against `application/config/doc_types.php`'s `doc_merge_fields`/`doc_contracts`/
`doc_types`. Every item the mission asked to check by name **is, in fact, covered**:

| Mission's check | Covered? | Test | Citation |
|---|---|---|---|
| `requiresState` gating | **yes** | `test_document_type_sets_and_gating_match` | `:242-265` |
| `disabled` | **yes** | same test | `:254-258` |
| Per-type contract key **order** | **yes** — `assertSame($keys, $server[$type], …)` does not sort the value arrays, only the outer type-name list | `test_per_type_contracts_are_identical` | `:268-288` |
| `maxLen` | **yes**, two tests (agreement + p95-accommodation) | `test_maxlen_agrees_between_client_and_server`, `test_maxlen_accommodates_the_p95_sample` | `:208-239`, `:332-369` |
| List-field `itemFields` (columns + their own `maxLen`) | **yes** | `test_list_item_columns_agree_between_client_and_server` | `:379-402` |

**What the test does NOT cover** — the real gap, none of it hypothetical drift, all of it
currently-consistent-but-unguarded:

1. **`label`, `sample`, `p95`, `calc` values are never compared.** Only the merge-field **key
   set** is asserted identical (`test_merge_field_key_sets_are_identical`, `:183-198`); the
   *content* of each field definition beyond `maxLen` is unchecked. A label edited on one side
   only (e.g. `doc_types.php`'s "Reason for leaving" respelled without touching
   `designer.js:56`) would pass every test in the file.
2. **`type` (`text`/`int`/`enum`/`image`/`flag`/`list`) is only indirectly checked**, and only
   for two values: `test_image_and_flag_fields_declare_no_maxlen` (`:405-416`) asserts an
   image/flag field has no `maxLen`, which is a proxy, not a direct comparison of the `type`
   string itself. A field marked `type:"int"` client-side but left as default `text` server-side
   (or vice versa) is not caught — `Doc_contract::validateBundle()`'s int-format check
   (`Doc_contract.php:328-332`) would then diverge from the client's own numeric-input rendering
   with no test failure.
3. **`name`, `alias`, `statutory` on `doc_types` are never compared** — only `disabled` and
   `requiresState` (`:253-264`). A live example of exactly this untested gap exists today: JS's
   `TYPES` entry for `fee_receipt` carries `statutory:false` explicitly (`designer.js:1454`);
   PHP's `doc_types['fee_receipt']` carries **no `statutory` key at all**
   (`doc_types.php:288-291`). `Doc_contract::catalogue()`'s `!empty($t['statutory'])`
   (`Doc_contract.php:246`) makes this behaviourally equivalent (both resolve to `false`) — **not
   a live bug**, but it is unguarded drift the test would not catch if a future edit made it
   diverge for real (e.g. `statutory:true` added to one side only). `[CONFIRMED — drift exists;
   consequence currently benign]`
4. **`TYPES` array order is not checked** — `test_document_type_sets_and_gating_match` sorts
   both key lists before comparing (`:247-250`). The hub's display order could silently diverge
   from the config's declared order with no test failure (lower severity — a display concern,
   not a mail-merge one).
5. **The two hand-written function bodies — `customTypeFor()` and `isCustom()`/`isCustomType()` —
   are never touched by this test at all.** The parity test parses static array literals; it has
   no mechanism to compare two pieces of *logic*. This is the gap the mission specifically asked
   about, and it is real: see below.

### Slug-derivation comparison — `Doc_contract::customTypeFor()` vs `designer.js`'s `customTypeFor()`

```php
// Doc_contract.php:155-170
$slug = strtolower(trim($title));
$slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
$slug = trim((string) $slug, '_');
$slug = substr($slug, 0, 40);
$slug = trim($slug, '_');
```
```js
// designer.js:1564-1568
const slug = String(title||"").toLowerCase()
    .replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 40).replace(/^_+|_+$/g, "");
```

Both apply the same four operations in the same order: lowercase → collapse every run of
non-`[a-z0-9]` to one `_` → trim leading/trailing `_` → cut to 40 → trim `_` again. Because the
collapse step reduces *any* non-ASCII run (one byte or a hundred) to a single `_`, the two
engines agree on structure for almost every input — including pure emoji, pure Devanagari,
leading/trailing punctuation, and names past 40 characters, **because by the time truncation
runs, only `[a-z0-9_]` bytes remain, so PHP's byte-oriented `substr` and JS's UTF-16-unit
`slice` operate on an identical pure-ASCII string.** `[INFERRED — reasoned from the documented,
stable semantics of PHP `preg_replace`/`substr` (byte-oriented, no `/u` modifier) and ECMAScript
`String.prototype.toLowerCase`/`slice` (UTF-16 code-unit oriented); not executed this pass per
the E2 ceiling — a `php -r` / `node -e` one-liner would upgrade this to E3 and is recommended for
whichever agent can run it.]`

**One real divergence found by this reasoning, not a hypothetical one:** PHP's `strtolower()` is
ASCII-only — it does not touch multi-byte UTF-8 sequences at all, so a character like **Turkish
capital dotted I (U+0130, `İ`)** passes through unchanged and is then swallowed whole by the
non-ASCII collapse. JS's `toLowerCase()` uses the ECMAScript default (non-`tr`-locale) full
case-conversion table, in which U+0130 maps to **`i` + a combining dot above (U+0307)** — a
literal ASCII `i` survives the lowercase step and is no longer collapsed away, because it matches
`[a-z0-9]` on its own.

| Input | PHP `Doc_contract::customTypeFor()` | JS `designer.js customTypeFor()` | Agree? |
|---|---|---|---|
| `"Sports Day Participation"` | `custom:sports_day_participation` | `custom:sports_day_participation` | yes |
| `"!!! Fee Concession !!!"` | `custom:fee_concession` | `custom:fee_concession` | yes |
| `"🎉 Sports Day 🎉"` | `custom:sports_day` | `custom:sports_day` | yes |
| `"खेल दिवस"` (Devanagari, "Sports Day") | `""` → throws `InvalidArgumentException` (`:163-168`) | `""` → falsy, caller silently declines (`designer.js:2370,2380`) | **same end result (rejected), different mechanism** — PHP raises a catchable exception with an explanatory message; JS has no message at all, just a no-op |
| A 55-character plain-ASCII name | both truncate to the same 40-char slug (pure ASCII by the time truncation runs — byte count = UTF-16 unit count) | — | yes |
| `"İstanbul Public School"` (Turkish dotted capital I) | **`custom:stanbul_public_school`** — the leading letter is lost entirely | **`custom:i_stanbul_public_school`** — a literal `i` survives, plus a leading underscore before `stanbul` | **NO — genuine divergence** |

**Consequence of the `İ` divergence, traced:** `customTypeFor()` is the sole minting path for a
custom document type's identity (`Doc_templates.php:606` server-side via `create()`;
`designer.js:2274,2370` client-side for the *displayed* preview id before the create call is
even made). If the client ever pre-computed and displayed an id (e.g. for a duplicate-name
check) that the server then re-derives differently for the same title, the two would disagree
about which type a document belongs to — the exact failure class `Doc_contract.php`'s own header
comment warns against ("two copies of one contract drift silently... arriving through the back
door", `Doc_contract.php:14-19`), now shown to apply to the *identity-generating function itself*,
not just the field tables it documents. **Blast radius is narrow** (Turkish/Unicode-SpecialCasing
inputs specifically; ordinary Latin, Devanagari, and emoji titles are unaffected per the table
above) but the mechanism is real and untested by anything in `DocContractParityTest.php`, whose
scope is declared arrays only (`:59-64` "Parsers... The CONTRACT array's source").

**Verdict:** slug derivation agrees for the overwhelming majority of realistic inputs (the
collapsing design is genuinely robust to most Unicode noise) but is **not proven equivalent in
general**, and at least one concrete, named, reproducible input class (Unicode SpecialCasing
expansions to ASCII, of which `İ` is the textbook example) produces two different type identities
from the same typed title. `[INFERRED, high confidence, not runtime-verified]`

---

## AXIS 3 — two fee-receipt implementations, field by field

`ZenXII_Parent/.../util/ReceiptPdfGenerator.kt` (431 lines) generates from **live payment data**
in the `feeReceipts` Firestore collection (`FeeReceiptDoc.kt`, written by the panel's fee module —
confirmed a real, separate, unrelated collection: `grep` hits in `AccountingReconciler.php:66`,
`FeeWorker.php:50`, `Admin.php:313` — none of these are Document Engine files). The Document
Engine's `fee_receipt` doc type (`doc_types.php:288-291`, added 2026-09-03) is a **template
contract with no data source** — `Doc_resolver` reads only `schools`/`complianceAuthorities`
(`01c-data-spec.md §1b`), never `feeReceipts`; nothing in the Document Engine resolves a real
payment. Per the config's own comment: "DESIGNING a receipt is not ISSUING one"
(`doc_types.php:283-284`).

| Field / behaviour | Parent app (`ReceiptPdfGenerator.kt`) | Document Engine `fee_receipt` contract (`doc_types.php:223-229`) |
|---|---|---|
| Data source | Live `FeeReceiptDoc` from `feeReceipts` (real payment) | None — `sampleBundle()` only; nothing wires real data (`CON-NO_PRINT_IMPL`) |
| School identity | name, address, phone, **email**, **GSTIN**, optional logo (`PdfSchoolMeta`, `:37-44`) | `school.name`, `school.address` only — **no phone, email, or GSTIN merge field exists anywhere in `doc_merge_fields`**, so no fee-receipt template built on this engine could ever print them |
| Receipt number | `receipt.receiptNo` (fallback `receiptKey`), system-assigned by the fee-payment backend | `receipt.no` — a **typed merge field** in the contract (sample `"RCT/2026-27/004182"`), not bound to any counter or to `feeReceipts.receiptNo` |
| Date | `createdAt` (Firestore Timestamp), formatted `dd MMM yyyy, hh:mm a` — date **and time** | `receipt.date`, sample `"03/09/2026"` — date only, no time field in the contract |
| Student | Name, Student ID, Class+Section combined (`formatClass`/`formatSection`, `:189-197`) | `student.admissionNumber`, `student.fullName`, `tc.lastClassStudied` (a TC-borrowed field, "Class last studied", e.g. `"IX — B"`) — **no distinct section field**; student ID (Firestore doc/uid) is not a bound merge field at all |
| Itemised breakdown | `feeBreakdown` list: **head, amount, frequency** (e.g. "Monthly") (`:216-229`); falls back to a bare `feeMonths` list if empty | `receipt.items` list: **head, period, amount** (`item.head`/`item.period`/`item.amount`, `doc_types.php:117-121`) — **`period` (a date range like "Apr–Jun 2026") is a different concept from the app's `frequency` (a cadence label like "Monthly")**; neither system's column set maps onto the other's |
| Gross amount | not printed at all (only discount/fine/net are shown) | `receipt.gross` **defined in the merge-field universe but NOT included in the `fee_receipt` contract's key list** (`doc_types.php:223-229` omits it) — so neither system currently surfaces it, coincidentally |
| Discount | shown as a negative line when `> 0` (`:250`) | `receipt.discount` **defined but not in the `fee_receipt` contract** — a template built on this engine cannot bind it (`offContract` would reject the binding, `Doc_contract.php:283-288`) |
| Fine / late fee | shown when `> 0` (`:251`) | `receipt.fine` **defined but not in the `fee_receipt` contract** — same exclusion |
| Net amount paid | `receipt.netAmount`, always shown, emphasised (`:252`) | `receipt.netPaid` — **is** in the contract |
| Amount in words | **not printed** — no such field or logic anywhere in `ReceiptPdfGenerator.kt` | `receipt.amountInWords` — **is** in the contract; a formal touch the native app has never had |
| Transaction reference | `receipt.txnId`, "shown in receipt UI for trust + customer-support reference" (`FeeReceiptDoc.kt:44-47`) | `receipt.txnId` **defined in the merge-field universe but NOT in the `fee_receipt` contract** — cannot be bound |
| Payment mode | `paymentMode`, shown | `receipt.mode` — in contract |
| Collected by | not printed anywhere in `ReceiptPdfGenerator.kt` (no such field read) | `receipt.collectedBy` — in contract |
| Duplicate marking | **none** — no watermark, flag, or "DUPLICATE" logic found anywhere in the file | `doc.isDuplicate` — an explicit `type:"flag"` merge field, **in** the `fee_receipt` contract (`doc_types.php:224`) |
| Signatures | 3 blank signature lines (Cashier/Accountant/Principal), no actual signature image or seal (`:270-290`) | Generic template designer supports image objects/seals per-template (not fee-receipt-specific; not traced this pass) |
| Pagination / overflow | **Single fixed page, no overflow handling** — `PdfDocument.PageInfo` is created once (`:77`); a long `feeBreakdown` simply draws past the signature block and, if long enough, past the page bottom edge with no truncation, warning, or second page (`[CONFIRMED, absence]` — no `startPage`/pagination logic beyond the one call) | Engine-wide overflow gate (`maxLen` → P2.7 overflow measurement, `doc_types.php:34-39`) is purpose-built for exactly this failure mode — designed to catch it at design time, not print time |
| Localisation | **Deliberately English/Latin-digit only, by written policy** (`ReceiptPdfGenerator.kt:3-14`: "forwarded to employers... shown to auditors... keeps Indic font embedding out of the PDF path entirely") | Multilingual by design — `languages`/`defaultLanguage` are per-template fields shared with every other document type (`01c-data-spec.md §1b`); no fee-receipt-specific exception found |
| Renderer | Native `android.graphics.pdf.PdfDocument`, on-device, no deps | mPDF, server-side |
| Currency symbol | `"Rs. "` prefix (ASCII), not `₹` (`:224,246`) | Not traced — merge-field samples in `doc_types.php` (e.g. `'receipt.gross' => '17,100.00'`) carry no currency symbol at all; whatever prefix/suffix appears is a template-design choice, not contract-enforced |

**Direct answer to the mission's question — what a parent and a school would see differently for
the same payment, the day the print-point seam is wired without reconciling these:** the two
receipts would show **different columns** in the itemised table (period vs. frequency), the
engine's receipt could show gross/discount/fine/txnId **only if the contract is first extended**
(currently structurally forbidden), the engine's receipt could carry a duplicate watermark the
app's never can, the app's receipt would show phone/email/GSTIN the engine's never can, and only
the engine's receipt could ever be in a language other than English. `L6` in `_live-state.md`
already flags this pairing as `⚑ CONTESTED` / `H3`; this pass adds the field-level specifics that
make "these disagree" concrete rather than asserted.

---

## AXIS 4 — client-side vs. server-side validation

| Rule | Client site | Server site | Verdict |
|---|---|---|---|
| Template name — length | none (empty → "Untitled template" fallback only, `designer.js:2087`) | none found (`Doc_template_service.php` strips only lifecycle keys, `:336-339`) | **ABSENT both sides** — unbounded |
| Custom doc name — 60-char raw-title HTML cap | `maxlength="60"` attribute | none — but moot: the *slug* (not the raw title) is what's persisted, and both `customTypeFor()`s independently cap the slug at 40 chars by construction (Axis 1) | client cap **cosmetic only**; the value that actually matters (the slug) is bounded structurally on both sides — **not a real gap**, despite looking like one |
| Custom-type regex validity (`isCustom`) | `isCustomType()` (`designer.js:1561`) — identical pattern source to PHP | `Doc_contract::isCustom()`, `CUSTOM_PATTERN` (`Doc_contract.php:140`) | **present both sides, textually identical regex** |
| Page margins / object x·y·w·h (mm) | `evalMm()` rejects non-numeric only, **no range clamp** (`designer.js:1651-1661`) | none found in `Doc_template_service::save()` (`:314-346`) | **CLIENT-ONLY, and even the client check is non-range** (`01a-web-spec.md §7`) |
| Text font size | `type="number" min="4"` HTML attribute (`designer.js:2947`) | not independently re-verified | **LIKELY CLIENT-ONLY**, HTML `min` is trivially bypassed by a raw POST |
| Table column width % | HTML `min="5" max="100"`, but the write handler only enforces the `max` half (`Math.min(100,n)`, `designer.js:4806-4807`) — **the declared `min` is not even enforced client-side** | none found | **ABSENT server; PARTIALLY-ENFORCED-AND-INCOMPLETE client** |
| `objects` array size/count | none | none — `[CONFIRMED absence]`, grepped `save()`/`create()`/`duplicate()`/`validate()` and the service layer for `count(`/size bounds, zero hits (`01b-backend-spec.md §7`) | **ABSENT both sides** — bounded only by PHP `post_max_size`/Firestore's ~1MiB document ceiling, neither inspected this pass |
| Uploaded asset MIME/size | none beyond the browser's native file-picker `accept` | **present and strong** — 4 MiB cap, content-sniffed MIME allow-list (`finfo`, not extension), `getimagesize()` second check, content-hash filename closes path-traversal (`Doc_templates.php:896,930-939,946-947`) | **SERVER-ONLY, and done well** — the one rule in the module where client-absence is fine because the server twin is solid |
| Compliance-exclusion "reason" text | non-empty check with a specific message (`designer.js:3836`) | `Doc_compliance::affectedByAuthority()` reads only the `applied` boolean on a `complianceLayers` entry (`Doc_compliance.php:101-108`) — **the `reason` text itself is never validated or even read for non-emptiness anywhere server-side** (grepped `Doc_compliance.php` for `reason`/`exclu`, one hit, and it is a comment) | **CLIENT-ONLY, now CONFIRMED** (closes the `[UNKNOWN]` left open in `01a-web-spec.md §7`) |
| `docType` mutability after creation | not applicable (no client UI path attempts this) | **gap, confirmed live** — `_safe_type()` (the type-availability gate) is called only by `create()`/`index()`/`design()`, never by `save()` (`Doc_templates.php:1095-1114`, call sites at `:164,606`); `save()`'s strip-list omits `docType` (`Doc_template_service.php:336-339`). Reproduced at runtime by QA-LEAD: a custom type was minted, then `save()`'d into `docType:"study"` (Andhra-Pradesh-gated), and the mutation was accepted (`_live-state.md` L9) | **SERVER-SIDE GAP** — the one rule in this table that is a genuine authorization/business-rule bypass, not a missing client mirror. Rated P2 today only because nothing prints yet; becomes P1 the moment the seam is wired (per `_live-state.md` L9) |
| Merge-field bundle validation (unresolved / off-contract / over-length / list-shape) | **none** — `designer.js` never calls the `validate` endpoint (`srv.validate` has zero call sites, `00-dependency-graph.md §2`) | `Doc_contract::validateBundle()` (`Doc_contract.php:273-360`) is thorough and fail-closed **but is reachable only through the `validate` AJAX endpoint, which the shipped UI never calls, and is NOT invoked by `publish()`** (`Doc_template_service.php:481-568` calls only proof-hash checks, never `validateBundle`) | **Both sides effectively absent in the shipped product** — the richest validation layer in the codebase exists, is well-designed, and is unreachable from both the UI and the one lifecycle transition (`publish`) that would most need it |
| Proof-content hash / publish gate | none (server-only by design — proof is never client-supplied) | present, sound, traced end to end (`01b-backend-spec.md §6`) | **SERVER-ONLY by design, and correctly so** |
| CSRF | token attached on every POST (`designer.js:911,951,1246`) | `csrf_protection = TRUE`, `doc_templates/*` absent from `csrf_exclude_uris` (`01b §3`) | **present both sides** |

---

## Counts

| Item | Count |
|---|---|
| `DocContractParityTest` test methods | 9 |
| Mission-named checks (`requiresState`, `disabled`, contract-order, `maxLen`, `itemFields`) covered by the test | 5 of 5 |
| Merge-field sub-properties left uncompared by the test (`label`, `sample`, `p95`, `calc`) | 4 |
| `doc_types` sub-properties left uncompared (`name`, `alias`, `statutory`) | 3 of 5 (only `disabled`/`requiresState` compared) |
| Live (if currently benign) drift found in an untested field | 1 — `fee_receipt.statutory`: explicit `false` (JS) vs. absent (PHP), same resolved value |
| Slug-derivation inputs compared (worked table) | 6 |
| Slug-derivation inputs that diverge | 1 — Unicode SpecialCasing expansion (`İ`, Turkish dotted capital I) |
| Legacy `Certificates.php` issue path | 1 (`generate_certificate`), writes to RTDB, 0 tests |
| Document Engine issue paths | 0 (`CON-NO_PRINT_IMPL`, confirmed structurally inert) |
| Fee-receipt implementations found | 2 (native Kotlin PDF; Document Engine `fee_receipt` type) |
| Fee-receipt fields present in the native app but excluded from the engine's contract | 4 (`receipt.gross`, `receipt.discount`, `receipt.fine`, `receipt.txnId` — all defined as merge fields, none bound in `doc_contracts['fee_receipt']`) |
| Fee-receipt fields the engine can express that the native app never has | 2 (`doc.isDuplicate` watermark flag, `receipt.amountInWords`) |
| Validation rules inventoried (Axis 4 table) | 13 |
| ...client-only, no server twin | 4 (page margins/geometry, font size, column-width lower bound, compliance-reason text) |
| ...server-only by sound design | 3 (asset upload, proof/publish gate, `docType`-availability at `create()`) |
| ...absent on both sides | 2 (template-name length, `objects` array size) |
| ...present, working, on both sides | 3 (custom-type regex, 40-char slug cap, CSRF) |
| ...a genuine server-side authorization gap (not a client-mirroring gap) | 1 (`docType` mutable via `save()`) |
| ...the richest validation layer, unreachable in practice from either surface | 1 (`Doc_contract::validateBundle()` via the dead `validate` endpoint / unwired from `publish()`) |

---

## Named gaps

- **`Doc_serializer.php` (969 lines) was not read this pass** — whether a published snapshot's
  `objects` embeds a `reusableBlocks` reference by value or by pointer (raised as `[UNKNOWN]` in
  `01c-data-spec.md §6/§13`) would also determine whether a block's *slug/identity* mismatch
  (Axis 1) could reach an already-frozen document. Not traced.
- **The `İ`-class slug divergence is `[INFERRED]`, not runtime-verified**, per this agent's E2
  ceiling. A one-line `php -r` and `node -e` invocation of the two functions verbatim would
  settle it at E3; flagged for QA-LEAD or a runtime-capable agent (the same escalation path
  `_live-state.md` used for A4's `save()`-bypass finding, which started as `[INFERRED]` and was
  reproduced as `L9`).
- **Whether other Unicode SpecialCasing expansions beyond `İ` exist** (e.g. certain Cherokee,
  Armenian, or Lithuanian dotted-I sequences) was not exhaustively enumerated — `İ` is offered as
  a proof of existence, not a complete list of divergent inputs.
- **Currency symbol / formatting conventions for the engine's `fee_receipt` merge fields** are
  template-design choices, not contract-enforced — this pass did not trace whether any starter
  template or serializer default injects a `₹`/`Rs.` prefix, so the "same payment, different
  receipt" comparison in Axis 3 is field-presence/absence, not a pixel-level rendering diff.
- **Whether `Doc_compliance`'s silence on the `reason` field's content is intentional** (the
  written reason is presumably meant for human audit review, not machine validation) or an
  oversight was not determined — flagged as a product question, not asserted as a defect either
  way, beyond the factual finding that no server-side check exists.
- **Whether the legacy `Certificates.php`'s dead `_sanitizePlaceholderValues()` was ever wired
  and later orphaned, or never wired at all**, was not traced through version history — only its
  current dead-code status is confirmed.
