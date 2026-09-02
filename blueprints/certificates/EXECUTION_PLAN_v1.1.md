# ZenXii Certificate Designer — Execution Plan v1.1

**Status:** PLAN — nothing implemented, nothing committed, nothing deployed.
**Date:** 2026-08-17
**Architecture of record:** `IMPLEMENTATION_ARCHITECTURE_v1.1.md`
**Supporting:** `FINAL_BLUEPRINT.md` · `DOCUMENT_ENGINE_ARCHITECTURE.md` (ADR) ·
`SCHOOL_DOCUMENT_ECOSYSTEM_RESEARCH.md`

## Adjustments applied to v1.1

| # | Change | Reason |
|---|---|---|
| A1 | Audit **extends the existing `auditLogs` collection**, not a new `documentTemplateEvents` store | `AuditLogs.php` already exists with a viewer (`get_logs`, `filter_logs`, `get_user_activity`, `get_stats`, `archive_old`), doc ID `{schoolId}_{logId}`. A separate store means document events never appear in the Audit Log viewer. Alongside `academicAuditLog` / `attendanceAuditLog`, that would be a fourth fragment. |
| A2 | **No CSRF token rename.** Keep `csrf_token` / `csrf_token_cookie` | Config already has `csrf_protection = TRUE` and `csrf_regenerate = FALSE` with a comment explaining it is correct for AJAX. Renaming touches all 140 controllers and every existing form for zero gain. Adopt only "send the token, don't exclude the route". |
| A3 | Adopt the FINAL doc's **acceptance-criteria checklist** (§7), scoped to the Template Engine | Neither v1.1 nor v1.0 had a consolidated definition of done. |
| A4 | Issuance / verification / issued-register work stays **out**, per `CON-NO_PRINT_IMPL` and v1.1 §14 | The FINAL doc's issuance content is parked as input to the next engine. |

---

## 1. How to read this plan

- Tasks are `Gn.n` (gates) and `Pn.n` (phases). **Gates block everything.**
- `Accept:` is a binary, testable condition. If it cannot be tested, it is not written correctly.
- No task is "done" until its acceptance condition is demonstrated, not asserted.
- **Nothing deploys without explicit per-change permission.** Work on `yug_testing`;
  `yug_b1_t` is the live AWS branch and is never worked on directly.
- Rules and indexes deploy **separately from PHP**, and indexes go **first**.

---

## 2. GATE 0 — Pre-flight

**No production code is written until every gate passes.** These are throwaway harnesses. Their
only purpose is to find out whether the architecture survives contact with the deployed stack.

| # | Task | Depends | Accept |
|---|---|---|---|
| **G0.1** | `composer require mpdf/mpdf`. Throwaway script: fixed HTML → PDF, written to disk. | — | PDF produced, no fatal, no warning spew. mPDF version recorded. |
| **G0.2** | ✅ **PASS** — bundle **Lohit** per Indic script (OFL 1.1) + bundled DejaVu for Latin. Register via `fontdata`, `useOTL => 0xFF`. | G0.1 | ✅ 8/8 families load as **distinct** embedded subsets; cache builds once (0→98 cold, 98→98 warm); no fallback. |

> ### ✅ G0.2 RESOLVED (2026-08-17) — **Noto Sans is not usable with mPDF; the family is now Lohit.**
>
> All 16 current Google-Fonts Noto TTFs were fetched successfully and **every one fails mPDF
> 8.3.1's TTF parser** — including Latin `NotoSans-Regular`:
>
> | Font | mPDF verdict |
> |---|---|
> | NotoSans (Latin) | `GPOS Lookup Type 5, Format 3 not supported` |
> | NotoSansDevanagari | `GPOS Lookup Type 5, Format 3 not supported` |
> | NotoSansTamil | `contains MarkGlyphSets - Not tested yet` |
> | NotoSansTelugu / Gujarati | `GPOS Lookup Type 5, Format 3 not supported` |
>
> Thrown from `TTFontFile::_getCoverage()` via `_getGDEFtables()` — a **hard exception at
> font-registration time**, not a degraded render.
>
> **The parser is not the problem.** mPDF's own bundled fonts all parse cleanly, including
> **`Lohit-Kannada.ttf`** — a real Indic font with OTL tables — plus `FreeSerif`, `FreeSans`,
> `DejaVuSans`. So mPDF's Indic shaping capability stands; only the *font builds* are incompatible.
>
> **RESOLUTION ADOPTED — Lohit (OFL 1.1), operator decision 2026-08-17.**
> Fetched from the **Debian package pool** (`fonts-lohit-*`) after GitHub raw and pagure.io
> returned 429/503. Harness: `tests/doctemplates/gate0/fetch_lohit.sh`.
>
> Verified embedding — 7 distinct Indic subsets, no fallback collapse:
> `Lohit-Devanagari` · `Lohit-Tamil` · `Lohit-Telugu` · `Lohit-Gujarati` · `Lohit-Bengali` ·
> `Lohit-Kannada` · `Lohit-Malayalam`, plus `DejaVuSans` for Latin.
>
> ⚠ **Lohit ships Regular only — no true Bold face.** mPDF synthesises bold. If synthesised bold
> is unacceptable for Indic at UAT, a per-script bold source must be found before launch.
>
> **Architecture updated:** §6 Fonts in `IMPLEMENTATION_ARCHITECTURE.md` and S6.3/S9 in
> `FINAL_BLUEPRINT.md` now specify Lohit. `useOTL = 0xFF` and subsetting guidance are unaffected.
>
> **Consequence for G0.3:** unchanged in purpose. Shaping correctness is still the question; it is
> now tested with whichever compatible family is selected.
| **G0.3** | ✅ **PASS** — script proof matrix, 9 script/language rows × 6 case types, rasterised at 200 dpi via `pdftoppm` and inspected. | G0.2 | ✅ **All conjuncts and matras correct in every script.** Evidence below. |

> ### ✅ G0.3 PASS (2026-08-17) — mPDF + Lohit shapes every target script correctly
>
> Harness: `tests/doctemplates/gate0/g03_scripts.php` → `out/g03_scripts.pdf` →
> `out/g03_page-{1,2}.png` (200 dpi, poppler `pdftoppm` 26.08.0).
>
> **The decisive result.** `कि` (ka + i-matra) renders with the **ि to the LEFT of क**. The vowel
> sign is typed *after* the consonant and must render *before* it. This is the exact reordering
> dompdf gets wrong — the failure that forced the renderer change — and mPDF + Lohit gets it right.
> Same verified for Gujarati `કિ` and Bengali `কি`.
>
> | Script | Verified |
> |---|---|
> | Devanagari (Hindi) | `कि` matra reorder ✅ · `क्ष` ksha ligature ✅ · `स्थानांतरण` conjunct + anusvara ✅ · `शिक्षा` reorder **and** conjunct ✅ · ZWNJ shows explicit virama, **not** the ligature ✅ · `Class कक्षा 10` mixed Latin+Indic ✅ |
> | Devanagari (Marathi) | `विद्यार्थ्याचे` multi-conjunct + reph ✅ · `शिक्षण` ✅ |
> | Tamil | **`கொ` two-part o-matra splits to BOTH sides of the consonant** ✅ · `கி` ✅ · `க்ஷ` ✅ |
> | Telugu | `క్ష` subscript below base ✅ · `కి` ✅ |
> | Gujarati | `કિ` matra reorder ✅ · `ક્ષ` ✅ |
> | Bengali | `কি` matra reorder ✅ · `ক্ষ` ✅ |
> | Kannada | `ಕ್ಷ` subscript ✅ · `ಕಿ` ✅ |
> | Malayalam | `ക്ഷ` ✅ · `ന്ത` nta conjunct ✅ · `കി` ✅ |
>
> **Permanent CI tripwire added.** Each script is rendered twice — `useOTL=0xFF` and `useOTL=0x00`
> — and the emitted glyph sequences must **differ**. Identical sequences mean the shaper never
> engaged, which is the dompdf failure mode. PASS for all 8 families. This does not prove
> correctness (only the visual check does) but it is a zero-cost regression guard that would have
> caught the Noto breakage instantly. Feeds directly into **P9.1**.
>
> **Tooling:** `brew install poppler` (operator-approved). Local/CI only — production still does
> **not** depend on a rasteriser, per §8.6 of the architecture.
| **G0.4** | ✅ **PASS with a required correction.** Layout constructs all verified; the plan's *overflow-detection mechanism* was wrong and is replaced. §0 is **NOT** reversed. | G0.1 | ✅ All layout cases render as designed. ❌ `$mpdf->page` does **not** detect absolute-container overflow — see below. |

> ### ✅ G0.4 (2026-08-17) — §0 HOLDS. But P2.7's overflow gate does not work as specified.
>
> Harness: `tests/doctemplates/gate0/g04_layout.php` → `out/g04_*.pdf`, rasterised at 160 dpi.
>
> **§0's core claim is validated — Phase 0.1 stays removed.**
>
> | Case | Result |
> |---|---|
> | C1 block-flow inside absolute container | ✅ child C correctly sits below B's **grown** height |
> | C2 anchor chain 4 deep | ✅ displacement propagates through the whole chain |
> | C3 fixed + auto siblings in one chain | ✅ fixed holds 12 mm, auto grows, follower below both |
> | C5 flow region + repeating header/footer | ✅ paginates, 2 pages |
> | C6 table in flow region across page break | ✅ paginates, 2 pages |
> | C7 **table inside absolute container** | ✅ works — v1.1 §0.4 flagged this "fragile, confirm or scope out"; **confirmed** for single-page fixed use |
> | C8/C11 image scale + mixed bold/size/script inline | ✅ `normal · BOLD · 18pt · 6pt · कक्षा` on one line, conjunct correct inline |
>
> **❌ THE FAILURE — C4.** An absolutely-positioned container whose content far exceeds the page
> bottom reports **`$mpdf->page == 1`**. The identical content in normal flow paginates to 2
> (control C4b). **Absolute content does not paginate — it silently clips.**
>
> This means plan task **P2.7 as written cannot fire for the dangerous case**:
> *"`pageMode: single` + `$mpdf->page > 1` ⇒ throw `E_PAGE_OVERFLOW`"*.
> The exact scenario v1.1 §0.3 warned about — a TC with a long `tc.reasonForLeaving` losing its
> signature block — would ship undetected.
>
> ### ✅ FIX (probe-verified) — two-tier overflow detection
>
> **Tier 1 — flow region:** keep the `$mpdf->page` gate. Proven to work (C5, C6).
>
> **Tier 2 — absolute chains:** measure with the renderer itself.
>
> ```php
> // Render the chain in FLOW on a scratch doc with an un-paginatable page,
> // then read the y-delta. mPDF measures; we do not reimplement text metrics.
> $m  = new \Mpdf\Mpdf(['format' => [$widthMm + 20, 2000], /* … */]);
> $y0 = $m->y;  $m->WriteHTML($chainHtml);  $heightMm = $m->y - $y0;
> ```
>
> Verified monotonic and sane: 15.15 → 20.30 → 25.45 → 40.90 → 71.79 mm across 1/2/4/8/16 reps.
> Throw `E_PAGE_OVERFLOW` when `chain.topMm + heightMm > pageHeight − bottomMargin`.
>
> **This is not the measurement pass §0 removed.** §0 removed hand-computed *positioning* — that
> stays removed, and C1–C3 prove the renderer handles it. This uses mPDF purely as a **measuring
> device for validation**, only for chains containing auto-height content, and only at
> proof/publish time — never per keystroke.
>
> **Actions:** rewrite **P2.7** to the two-tier model; add `measureBlock()` to `Doc_serializer`
> (§5.3); v1.1 §0.3's P2 control is necessary but **insufficient alone** and must say so.
| **G0.5** | ✅ **PASS.** Divergence measured against real Chrome with font parity. **The tolerance model in this row was wrong and is replaced** — see below. | G0.3, G0.4 | ✅ **0.00 mm** divergence, 92/92 probes across 23 widths × 4 scripts. |

> ### ✅ G0.5 PASS (2026-08-17) — divergence is **0.00 mm**, but it is quantised, not continuous
>
> Harnesses: `g05_divergence.php` (single width) and `g05b_sweep.php` (23 widths × 4 scripts).
> Browser half measured in real Chrome via `getBoundingClientRect()`, fonts served over HTTP with
> `@font-face` parity to the identical TTFs.
>
> **Result: 92/92 probes agree exactly. Zero line-break disagreements.**
> Widths 60–170 mm, scripts Latin / Devanagari / Tamil / Bengali.
>
> **The "≤1.5 mm per block" target was the wrong model.** Both engines compute
> `height = lines × line-height`. When `line-height` is explicit they cannot drift continuously —
> they either pick the same line count (delta exactly 0) or differ by a **whole line box**
> (5.4328 mm at 11 pt / 1.4). You cannot be 1.5 mm out. Replace the tolerance with:
>
> - **Expected divergence: 0.00 mm.**
> - **Alarm threshold: any delta ≥ 1 line box.** There is no meaningful middle.
>
> ### ⛔ DERIVED HARD RULE — the serializer MUST always emit an explicit `line-height`
>
> With `line-height` omitted, each engine falls back to its own font-derived default leading and
> agreement collapses immediately — **in both directions**:
>
> | script | mPDF | Chrome | delta |
> |---|---|---|---|
> | Latin | 15.448 mm | 13.494 mm | **+1.95** |
> | Devanagari | 11.785 mm | 14.552 mm | **−2.77** |
> | **Tamil** | 18.028 mm | 9.525 mm | **+8.50** |
> | Bengali | 11.776 mm | 12.965 mm | −1.19 |
>
> Tamil is ~2× out **on a single block**, and the error compounds down an anchor chain. This is a
> **blocking serializer rule**, not a style preference: every text object emits an explicit
> `line-height`, and a template may not leave it unset.
>
> **R1 font parity confirmed achievable** — all four `@font-face` faces reported `loaded`. R1 is
> load-bearing: without identical TTFs the comparison is meaningless.
>
> **Honest limits.** Chrome only (not Safari/Firefox); one font size (11 pt); one line-height
> (1.4). The **P9.1** CI suite should sweep sizes and add a second browser before launch.
>
> **Tooling note:** the browser half needs the page served over **HTTP** — the Chrome extension
> refuses `file://`. Harness assumes a static server on the gate0 directory.
| **G0.6** | ✅ **PASS (demand measured; supply inferred).** Worst-case render: A4, 8 scripts / 8 font subsets, 22-row table, image, paginated flow region, ×5 reps. | G0.4 | ✅ **26 MB peak, 151 ms p95, zero memory drift.** Fits with large headroom — see below. |

> ### ✅ G0.6 (2026-08-17) — rendering is not the resource risk
>
> Harness: `tests/doctemplates/gate0/g06_resources.php`.
>
> | Metric | Measured |
> |---|---|
> | Peak memory | **26 MB** |
> | p95 wall time | **151 ms** (303 ms first run = cold font cache) |
> | Memory drift over 5 renders | **0 MB — no accumulation** |
> | Output | 2 pages, 128 KB |
>
> **Demand is hardware-independent; only timing is optimistic.** PHP's memory figure transfers to
> any box. Wall time was measured on dev hardware and will be slower on a shared vCPU.
>
> **Supply, without needing server access.** `BUG_LEDGER.md` BUG-051 records a PHP
> `max_execution_time` timeout firing "after ~120 seconds" — so the server's limit is **120 s**.
> Against a 151 ms render that is ~800× headroom; even at a pessimistic 10× CPU penalty it is ~80×.
> Memory at 26 MB fits comfortably under any plausible `memory_limit` (128 M default ⇒ ~5×).
>
> **The OOM history is not ours.** Both the runbook's gRPC OOM and BUG-051's timeout are
> **Firestore/curl latency**, not rendering. mPDF does not share that risk profile.
>
> ### §10 resource limits — now derived from measurement, not guesswork
>
> | Limit | Value | Basis |
> |---|---|---|
> | Per-render memory cap | **96 MB** | 26 MB measured × ~3.7 headroom |
> | Per-render timeout | **15 s** | 151 ms × 10× CPU penalty ⇒ ~1.5 s; 10× again for safety |
> | Max pages per render | **20** | 2 pages for a worst-case TC; anything beyond is a runaway |
>
> ### ⚠ Derived requirement — bulk printing cannot be one HTTP request
>
> No memory accumulation means batch size is bounded by **wall time, not memory**. At a pessimistic
> ~1.5 s/document on the server, a 120 s limit allows only **~80 documents per request**. Bulk
> report-card or receipt printing must therefore be **chunked or queued** — never a single
> synchronous request. Bulk printing is v2 (S13.2), but the constraint belongs on record now.
>
> **Residual:** exact `memory_limit`, RAM and vCPU count remain unconfirmed. Given 26 MB, this is
> low risk; confirm opportunistically with
> `php -r 'echo ini_get("memory_limit");'` · `free -m` · `nproc`.
| **G0.7** | ✅ **PASS.** CSRF round-trip against the real CodeIgniter stack on `localhost:8080`. | — | ✅ Without token → **403**; with valid token → **404** (passed security, died at routing); wrong token → **403**. |

> ### ✅ G0.7 PASS (2026-08-17) — no endpoint needs CSRF exclusion
>
> **Method (safe by construction):** CI verifies CSRF in the Security library **before** any
> controller executes, so the probe POSTed to a non-existent, non-excluded route. No business
> logic ran, no login was attempted, nothing was written.
>
> | Probe | Status | Interpretation |
> |---|---|---|
> | POST, no token | **403** | CSRF active and blocking |
> | POST, valid token from cookie | **404** | **Passed security**, died at routing — the token was accepted |
> | POST, forged token | **403** | Forgery rejected |
>
> The 404 is the proof: only a request that clears the security layer reaches routing.
>
> **This closes adjustment A2 with evidence, and reverses the v1.0 error.** v1.0 instructed adding
> fifteen state-changing endpoints — including `publish` and `activate` — to `csrf_exclude_uris`,
> which would have disabled CSRF for the whole module and allowed an attacker to flip a school's
> active TC template via a forged cross-site POST. **Sending the token works against the existing
> config unchanged** (`csrf_token`, `csrf_regenerate = FALSE`); **no exclusion entry is needed, and
> none should be added.**
>
> Also confirms the "blank 403" trap in `CLAUDE.md` is exactly probe 1's response — real, but the
> fix is to send the token, not to exclude the route.
>
> **P1.6 consequence:** the controller wires CSRF normally. `api.js` must attach `csrf_token` to
> every POST body/FormData, and the `csrf_exclude_uris` list stays untouched.
| **G0.8** | ✅ **TRANSCRIBED from source** — ⚠ awaiting second-person review. | — | ✅ Verbatim field list at `tests/doctemplates/gate0/out/G0.8_annexure_I_transcription.md`, traceable to an archived source PDF. **Not** the OCR'd summary. |

> ### ✅ G0.8 (2026-08-17) — Annexure-I recovered from the primary text layer
>
> **The blocker was overstated.** Poppler (installed for G0.3) made the source reachable: the live
> `cbse.gov.in/Byelawsenglish.pdf` 404s, but Wayback snapshot `20230626042437` returns the full
> 68-page *Examination Bye-Laws 1995 (updated upto December 2004)* — **with a real text layer**, so
> `pdftotext -layout` produced a verbatim extract rather than OCR.
>
> Archived: `out/cbse_examination_byelaws_1995upd2004.pdf` (sha256 `67cac1f1…`, 190,082 b).
> **Annexure-I on printed pp. 57–58** (PDF 65–66), heading *"FORMAT OF TRANSFER CERTIFICATE"*.
> **22 fields confirmed.**
>
> **The plan was right to forbid the research summary.** It was substantially accurate but wrong in
> exactly the ways that break a prescribed form:
>
> | # | Summary | Source |
> |---|---|---|
> | 6 | "DOB per Admission Register" | **figures AND words** |
> | 7 | "last class" | **figures AND words** |
> | 10 | "subjects" | **exactly 5 slots** |
> | 11 | "promotion eligibility" | + target class in **figures AND words** |
> | 12 | "dues paid upto" | a **month**, not a date |
> | 19–20 | "dates of application and issue" | **two separate fields** |
>
> Signature block: `class teacher` → `Checked by (state full name and designation)` → `Principal`
> + **SEAL**. Pre-printed `Book No. / Sl. No. / Admission No.` confirmed as a second numbering axis.
>
> **Three caveats carried forward:** (1) 1995/2004 edition — a **January 2013 consolidated edition**
> exists and needs a currency check; (2) the countersignature footnote is **partly superseded** —
> circular `Coord/ROs/Admission-IX-XI/2019` (18.07.2019) removed it for **CBSE→CBSE**, so the
> profile models countersignature as **conditional**; (3) RTE s.5(3) overrides the TC precondition
> for classes I–VIII.
>
> ⚠ **Not fully closed until a human signs off** against pp. 57–58. Shipping a legally-prescribed
> form on a single reading is precisely what this gate exists to prevent.

**Gate exit:** G0.1–G0.8 all pass, or the architecture is revised and this plan re-cut.

---

## 3. Phase 1 — Foundation

| # | Task | Depends | Files | Accept |
|---|---|---|---|---|
| P1.1 | ✅ **DONE — and unmarked until 2026-08-27.** `COLLECTION_SHAPES.md`, 423 lines, covers all five: `documentTypes` §2 · `documentTemplates` §3 · `documentTemplateVersions` §4 · `mergeFieldContracts` §6 · `reusableBlocks` §7, plus the compliance stack §5. | G0 | `COLLECTION_SHAPES.md` | ✅ Keys follow `{schoolId}_{entityId}`; §0 records the twice-scoping rule (`schoolId` **and** session). |
| P1.2 | ✅ **DECLARED 2026-08-27 — the withdrawal is REVERSED.** All 7 are in `firestore.indexes.json`. The 2026-08-19 withdrawal rested on *"a deploy would offer to delete the live-only indexes"*, and **that is false: an index deploy only creates.** Aegis (restored the same day) confirms `mine 7 · dropped 0`. | P1.1 | `firestore.indexes.json` | ✅ Declared and verified with `aegis indexes status --fresh`. ⏳ **DEPLOY-PENDING** — deploying is a separate, permissioned step, and must happen **before** any query code, per the runbook. |
| P1.3 | ✅ **DONE 2026-08-27 — PHASE 1 IS NOW COMPLETE.** Six blocks: 3 platform (read-any-active-tenant, write `if false`), `documentTemplates` (published head nearly frozen; activation graded `manage`), `documentTemplateVersions` (create-only, `update, delete: if false` for everyone), `reusableBlocks` (edit to change, manage to remove). | P1.1 | `firestore.rules`, `tests/document_engine.test.js` | ✅ `aegis rules status` run **before** editing and blocks **leased**; `mine 6 · conflict 0 · theirs 55 unchanged`. **307 tests green / 11 suites**, 45 of them new, and **mutation-tested non-vacuous**. ⏳ **DEPLOY-PENDING** — 55 prod blocks are newer than this branch. |
| P1.4 | ✅ **DONE — was already done and the status did not say so (verified at source 2026-08-27).** `config/numbering.php:130-142` registers `doc_template` (`TPL`, padWidth 4 ⇒ `TPL0001`) and `doc_block` (`BLK`), both `gaplessClass INTERNAL`, both `enabled` in `_kindFlags`. | — | `config/numbering.php` | ✅ Matches the acceptance criterion exactly. `gaplessClass` remains **decorative — nothing enforces it**; that is recorded, not fixed. |
| P1.5 | ✅ **DONE — committed `1dada83`, table marker was stale (found 2026-09-02).** `Doc_renderer` — clean mPDF path. **Never** `Pdf_generator::render()`. Paper from template. | G0.1, G0.2 | `libraries/Doc_renderer.php` | Renders a fixture with **zero** report-card CSS inherited and correct paper size |
| P1.6 | ✅ **DONE.** `Doc_templates` controller + 17 routes + CSRF (no exclusions) + `_remap` capability map | G0.7 | `controllers/Doc_templates.php`, `config/routes.php` | ✅ Verified live: unauth GET → 307 to login; POST without token → **403**; POST with token → 303 to login (CSRF passed, auth took over). Endpoint missing from `CAPABILITIES` is refused — fails closed. |
| P1.7 | ✅ **DONE — simplified.** No `Doc_audit` library is needed: `application/helpers/audit_helper.php` already provides `log_audit($module,$action,$entityId,$description)` writing to `auditLogs` with a settled schema and non-blocking failure semantics. The controller calls it directly with module `DocTemplates`. | — | *(none — existing helper)* | ✅ Events land in the **existing** Audit Logs viewer. One fewer class, zero fragmentation. |
| P1.8 | ✅ **DONE 2026-08-27 — and wider than the row asked for.** `config/doc_types.php`: **30 merge fields · 6 per-type contracts · 8 document types** (all six enabled types, not only TC/Bonafide/Character). Every field carries `label`, `sample`, `maxLen`, and `p95` where a worst case exists. | G0.8 | `config/doc_types.php` | ✅ **Guarded by `tests/Unit/DocContractParityTest` (7 tests, 179 assertions), which parses `designer.js` and fails if the two copies drift.** Negative-tested: a dropped contract key and an undersized `maxLen` were both caught. |
| P1.9 | ✅ **DONE 2026-08-27.** `libraries/Doc_contract.php` — `get`, `keysFor`, `typesForState`, `typeAvailable`, `validateBundle`, `diff`, `sampleBundle($type,$p95)`. Constructor takes optional injected params so the class is unit-testable with **no CI3 bootstrap**; production passes nothing. | P1.8 | `libraries/Doc_contract.php` | ✅ `diff` returns `{added,removed,unchanged,breaking,impact}` and **throws on an undeclared key**. **20 behavioural tests** in `DocContractServiceTest`. |

---

## 4. Phase 2 — Serializer *(before the canvas)*

Built first because it is testable headlessly, it is what print points ultimately consume, and a
canvas built against an unproven serializer bakes in its mistakes.

| # | Task | Depends | Accept |
|---|---|---|---|
| P2.1 | ✅ **DONE 2026-09-02.** Absolute object emission in mm; `height:auto` omits height, `fixed` states it, `maxHMm` emits a ceiling. | P1.5 | ✅ `DocSerializerTest` pins position, width and both height modes. |
| P2.2 | ✅ **DONE 2026-09-02.** Chain → ONE absolute container, members block children carrying only `margin-top`. | P2.1, G0.4 | ✅ A 3-deep chain emits **exactly one** `position:absolute`. **Plus a bug the test found: a cycle gave every member a valid anchor, so none became a root and all were DROPPED SILENTLY** — now a hard error naming the lost ids. |
| P2.3 | ✅ **EMISSION DONE 2026-09-02** — a `flowRegion` chain is emitted in normal flow, not absolutely. | G0.4 | ✅ Asserted no `position:absolute`. ⏳ Multi-page pagination with repeating header/footer is unverified until P2.7 wires the renderer. |
| P2.4 | ✅ **DONE 2026-09-02.** `pageNumber` emits `{PAGENO}` inside the footer region — never an absolute object, since only the footer band repeats per page. | G0.4 | ✅ Asserted the token and the `zx-footer` placement. |
| P2.5 | ✅ **DONE 2026-09-02.** Every rule namespaced under `.zx-tpl-{id}`. | — | ✅ The test **parses the emitted `<style>` and asserts every selector starts with the namespace** — it does not grep for a string. Also asserts zero `display:flex` / `display:grid`, which mPDF cannot render. |
| P2.6 | ✅ **DONE 2026-09-02.** Three distinct failures, kept distinct because they have different owners: `offContract` (template binds a key its docType never declared), `unknown`, `unresolved` (contract fine, data missing). Sample and p95 modes resolve from the contract. | P1.9 | ✅ Unresolved **and empty-string** both throw; off-contract throws; a design-mode chip (`data-key`) can never reach output; p95 verified to actually lengthen the worst-case field. |
| P2.7 | ✅ **DONE 2026-09-02.** `Doc_serializer::overflowFindings()` / `assertFits()`, renderer **injected** so both tiers are testable without mPDF. **Tier 1** flow region via `Doc_renderer::pageCount()` (ADDED — it did not exist, so tier 1 was dead code); **tier 2** absolute chains via `wouldOverflow()`. | G0.4 | ✅ Both tiers asserted **independently**, plus: tier 1 is never consulted for an absolute chain (G0.4), a multi-page template may legitimately flow, and a renderer that cannot answer tier 1 **throws instead of skipping**. ⚠️ **Fixed a live bug in the tier-2 primitive** — see below. |
| P2.8 | ✅ **DONE 2026-09-02.** `tests/doctemplates/golden/` + `DocSerializerGoldenTest` — one fixture exercising every §5.4 rule at once, in typical / p95 / duplicate modes. Regenerate with `ZXDT_GOLDEN_UPDATE=1`. | P2.1 | ✅ Byte-for-byte. Mutation-tested: a single changed byte (`margin-top`→`margin-TOP`) fails all three. A fourth test asserts the three goldens **differ from each other**, so the sample machinery cannot silently stop switching while all three still pass. |

---

## 5. Phase 3 — Canvas core

| # | Task | Depends | Accept |
|---|---|---|---|
| P3.1 | ✅ **DONE — was already built; acceptance now proven.** Command stack is `{label, before, after}` **snapshot**-based, not the planned `{do, undo, coalesceKey}`. Different mechanism, same guarantee — and a snapshot cannot drift out of sync with the model the way a hand-written inverse can. | — | ✅ `push()` fires on gesture END with `drag.before` captured at start (`designer.js:2524`), so a drag is **one** command, never one per mousemove. E2E D2/D3/J2/J3 pin add/undo/redo, survival across a screen change, and the 80-entry bound. |
| P3.2 | ✅ **DONE.** `pxPerMm = S.zoom*96/25.4` — exactly the planned formula. | P3.1 | ✅ **E2E N1**: a 20 mm object measures **20.00 mm at 100% and 20.00 mm at 250%**, read from `getBoundingClientRect()`. If this drifts the proof PDF stops matching the canvas and every position becomes a guess. |
| P3.3 | ✅ **DONE.** | P3.2 | ✅ **E2E N2**: `37.25 / 91.5 / 123.75` mm survive a serialise→parse round trip byte-exact. Anything lost here is lost on save and invisible until reload. |
| P3.4 | ✅ **DONE.** `snap()` uses `TH = 6/pxPerMm()` — 6 **px**, converted. | P3.3 | ✅ **E2E N3** drives the real `snap()`: a 1.5 mm gap **snaps at 50% and does not at 250%**. ⚠️ The first version of this test recomputed `6/pxPerMm()` itself and would have passed even if the threshold were changed to millimetres — it was testing its own arithmetic, not the product. Rewritten. |
| P3.5 | ✅ **DONE.** | P3.3 | ✅ **E2E N4**: aligning 5 objects leaves **exactly 1 distinct left edge** — identical, not merely closer. |
| P3.6 | ✅ **DONE 2026-09-02 — the proof-PDF half is now measured.** Rulers, zoom/pan, grid and precise mm entry, and the authored position **survives into the PDF engine's own layout**. | P3.2 | ✅ E2E M3/M4 + N2/N6 (model round-trip) **and** `DocRenderIntegrationTest` — a block authored at **45.5 mm fits on A4 and one at 290 mm does not**, judged by `measureBlock()`, which renders on a scratch un-paginatable page and reads mPDF's own y-delta. |
| P3.7 | ✅ **DONE.** | P3.1 | ✅ **E2E N5**: bring-forward persists and is still top-most after a round trip, so z is stored rather than merely reflected in DOM order. |
| P3.8 | ✅ **DONE.** | P3.3 | ✅ **E2E N6**: all **12** inspector-editable properties round-trip — position, size, z, height mode, `maxHMm`, anchor gap, and 4 style fields. A property that silently fails to persist reads as the UI ignoring you. |

---

## 6. Phase 4 — Text and binding

| # | Task | Depends | Accept |
|---|---|---|---|
| P4.1 | ✅ **ACCEPT MET, TASK SUPERSEDED.** Quill was **never vendored** (0 refs in `designer.js`). The editor is `contentEditable` + the run model + the **Content pane**, per `design/TEXT_EDITING_PROPOSAL.md` — *"BUILT and verified in the browser"*, normative text in UX_SPEC §5A.4b. | P3.3 | ✅ Both halves of the criterion hold: **no bundler** was introduced (plain `<script>`, self-hosted), and the editor mounts/unmounts cleanly — E2E **D8** proves the pane refuses to repaint under a live edit, which is the unmount hazard that actually bites. Counted because the ACCEPT is met; the task text is stale. |
| P4.2 | ✅ **DONE — as a void `.mf` span, not a Quill blot.** `contenteditable="false"` + `data-key`. | P4.1 | ✅ **E2E O2**: chips are void nodes and their **keys survive a full `runsHTML()` → DOM → `parseRuns()` round trip intact**. A key corrupting into free text would bind to nothing. |
| P4.3 | ✅ **DONE.** The picker is built from `contractFor()` — the per-type contract, never the 30-key universe. | P1.9, P4.1 | ✅ **E2E O1**: the picker offers **22 of 30** keys for a TC and **zero** off-contract. Scoped, and no free-typing route exists. |
| P4.4 | ✅ **DONE — one model, `runs` per language, not a Delta swap.** | P4.1 | ✅ **E2E O3**: after an en→hi→en round trip **both** languages' runs are byte-identical. Losing the other language is invisible until someone opens it. |
| P4.5 | ✅ **BUILT 2026-09-02.** `capacityHint()` + an advisory line under each Content-pane row: *"≈120 chars fit · sample uses 27"*, turning `--seal` red when over. Budget comes from the bound field's `maxLen`; unbound text gets **no** budget. | P1.8, P2.7 | ✅ **E2E O4/O5/O6**. ⚠️ **A bug my own test caught:** the first version measured `contentPlain()`, which substitutes the placeholder `{School name}` — so the count was the LABEL's length and never moved with the p95 toggle, defeating the one mode whose purpose is showing the worst case. Now resolves through `fieldValue()`: typical **27** → p95 **59**. |

---

## 7. Phase 5 — Compliance

| # | Task | Depends | Accept |
|---|---|---|---|
| P5.1 | ⛔ **BLOCKED ON A HUMAN, NOT ON CODE.** The *shape* is met — every authority layer carries `authority`, `evidence`, `verifiedOn`, `owner`, `scopeNote`. But the CBSE TC list declares **19 required keys against Annexure-I's 22**, and is flagged `illustrative:true` / `fieldListVerified:false`. | G0.8 | ⛔ Needs the **gate 0.3 transcription and 0.8 second-person sign-off**. ✅ **E2E P5** at least proves the un-transcribed list is *flagged*, not presented as law — shipping 19 keys as though they were the statute is the real risk here. |
| P5.2 | ✅ **DONE — accept reinterpreted for the STACK.** | P5.1 | ✅ **E2E P1.** ⚠️ **The plan's accept is stale**: it says a Karnataka state-board school "resolves to `generic`", which was true under the single-profile model `COMPLIANCE_ARCHITECTURE.md` killed. Under the stack the **national layer still applies** — RTE binds elementary schooling whatever the board — so resolving to RTE is correct and resolving to `generic` would have been **the bug**. Tested instead: an unverified board contributes **no board-tier layer**, and `generic` is reached only when the stack is genuinely empty (secondary stage, no board match) and **says so**. |
| P5.3 | ✅ **DONE.** `tryDelete()` refuses; `openCite(key, refused)` shows the citation. | P5.1, P3.3 | ✅ **E2E P2** — the object survives **and** the refusal carries Authority, Evidence and Verified. A bare "you can't delete that" is indistinguishable from a bug. |
| P5.4 | ✅ **DONE.** | P5.1 | ✅ **E2E P3** — the citation surfaces `Level A–D` and `EVIDENCE_RANK` is strictly ordered, so a Level C item cannot render identically to a Level A one. Evidence level is typographic by design (`--seal`, dashed borders for provisional). |
| P5.5 | ✅ **DONE.** | P5.3 | ✅ **E2E P4** — a blank canvas raises **14 unbound required keys** that block publish, **and the draft stays fully editable**. Blocking draft edits would make an incomplete template impossible to complete, which is the state every template starts in. |
| P5.6 | ✅ **DONE 2026-09-02.** `Doc_compliance::affectedByAuthority()` — a REPORT across every school: which templates were validated against an older version, sorted **active first** because those are what print points resolve today. Plus `isStale()` (per-authority `reviewMonths`) and `bestEvidence()` (**best across applied layers, never averaged** — averaging would let two Level-C citations present as a Level-B fact). | P5.1 | ✅ 11 tests. **Nothing auto-invalidates**, asserted two ways: producing the report mutates nothing, *and* the class is asserted to expose **no method matching set/save/update/apply/invalidate/…** — a future "helpful" convenience method is exactly how a report becomes an auto-action. An **excluded** layer is not reported (the school has a written reason on file). An unknown authority **throws** rather than reporting "0 affected", which would read as reassurance. |

---

## 8. Phase 6 — Publish pipeline

| # | Task | Depends | Accept |
|---|---|---|---|
| P6.1 | ✅ **DONE.** No update/delete path exists in `Doc_template_service`, and `firestore.rules` denies both for **everyone** at every grade. Publishing over an existing version id is refused outright. | P1.3 | ✅ `DocTemplateServiceTest` — `test_publishing_over_an_existing_version_is_refused` + the rules suite's *"no grade unlocks it"* pair. |
| P6.2 | ✅ **DONE.** The snapshot records `proofPdfHash`, `fontManifest` and `mpdfVersion`, and publish **refuses** without all three. | P1.5, P2.* | ✅ A snapshot that cannot name the faces and engine that produced it cannot be re-rendered years later, which is the entire point of keeping it. Tested per-field via a data provider. |
| P6.3 | ✅ **DONE.** Publish freezes v*n* and the head opens **draft v*n+1***. | P6.1 | ✅ `test_editing_after_publish_touches_the_head_and_not_the_snapshot` — the frozen version is **byte-identical** after a later head edit. Compliance layers are **frozen, not referenced**, so a later authority revision cannot retroactively change what an issued certificate was validated against. |
| P6.4 | ⚠️ **LOGIC DONE, ATOMICITY UNPROVEN.** Runs inside `runTransaction()` and **refuses to run at all** when no transaction is available — a non-transactional activate looks identical when it works and yields two active templates when it races. Displaces **every** incumbent, so a past double-active heals rather than persisting. | P6.3 | ⚠️ 4 tests incl. *"displaces every incumbent, not just the first"*. **But the transaction is a DOUBLE.** The accept says *"two concurrent activates → exactly one active"*; real atomicity is Firestore's and needs the emulator with genuinely concurrent clients. **Not counted.** |
| P6.5 | ✅ **DONE.** | — | ✅ `test_two_concurrent_saves_produce_exactly_one_conflict_and_no_lost_edit` — the second writer is refused **and the first writer's edit is still there**. Deliberately a refusal, not a merge: two clerks editing a statutory template are not editing the same sentence by coincidence. `save()` also cannot move `status`/`activeVersion`/`publishedVersion`/`templateId`. |
| P6.6 | ✅ **DONE — server-side.** `draft→{published,archived}`, `published→archived`, `archived` terminal. | P6.3 | ✅ A published head **can never revert to draft**, or the snapshot would outlive its own head. Errors name both states and the legal set, so the log is diagnosable. |
| P6.7 | ✅ **DONE.** Every transition calls `log_audit('DocTemplates', …)` — the existing viewer, not a fourth audit store. | P1.7 | ✅ `test_every_lifecycle_action_is_audited` asserts publish/activate/archive all land **and that each carries a non-empty description**. |

---

## 9. Phase 7 — Language

| # | Task | Depends | Accept |
|---|---|---|---|
| P7.1 | ✅ **DONE.** 7 Lohit families registered in `Doc_renderer::fontData()`, all 7 TTFs present on disk; Latin resolves to mPDF's bundled DejaVu — 8 scripts. | G0.2 | ✅ `DocFontParityTest` pins families, per-family FILE, on-disk presence and size, **and that no family falsely claims a bold face** — Lohit ships Regular only and mPDF synthesises bold, so a declared `B` would fail at registration. ⚠️ *"Renders in a real template"* is still fixture-verified (G0.2/G0.3), not certificate-verified — carried in the dossier. |
| P7.2 | ✅ **BUILT 2026-09-02 — it did not exist, in EITHER surface.** `@font-face` now emitted by `Doc_serializer` **and** declared in `doctemplates.css`, all with `font-display:block`. | G0.5 | ✅ **Both clauses met.** E2E **Q1/Q2/Q3**: every family the picker offers is declared, every face is `block` never `swap`, and a load failure is **reported** (`verifyFonts()`) rather than absorbed. ⚠️ **The designer canvas had the same defect as the serializer** — the picker offered `lohitdeva`/`lohittaml`/`lohitbeng` and the stylesheet declared none of them, so choosing a Devanagari face changed nothing on screen while mPDF set the PDF in Lohit. The picker also offered only **3 of 7** families; now all 8. |
| P7.3 | ✅ **DONE 2026-09-02 — agreement MEASURED, not argued.** | P4.4 | ✅ Byte-identical HTML measured in **both engines**: latin-1line **4.939 vs 4.936**, latin-4line **19.756 vs 19.745**, Devanagari and Tamil **6.350 vs 6.350** — worst divergence **0.011 mm**, against a G0.5 tolerance that found up to **2×** divergence *without* an explicit line-height. Codified as E2E **T1** with mPDF's numbers as the committed baseline, plus **T2**, which asserts the Lohit faces are genuinely loaded first — otherwise T1 would compare a system-font fallback and could still pass. |
| P7.4 | ✅ **DONE.** `translationCoverage(lang)` reports done/total and the report names each gap. | P4.4 | ✅ E2E **Q4** — with 2 Hindi translations deleted it reports **9/11, 2 gaps**, and E2E **E15** covers the publish-time warning. An object pinned to another language is correctly not counted as untranslated. |
| P7.5 | ✅ **DONE — and `block` is the DEFAULT, not just the statutory setting.** `Doc_serializer` now honours `languageFallback`; it previously ignored it entirely. | P7.4 | ✅ E2E **Q5** — every statutory starter pins `block` — plus 3 server tests: a missing translation throws by default, `default` opts in to the fallback, and `default` **still throws** when the default language is also missing. Falling back silently prints a Hindi certificate containing English sentences and tells nobody, while it still carries the school's seal. |

---

## 10. Phase 8 — Blocks and starters

| # | Task | Depends | Accept |
|---|---|---|---|
| P8.1 | ✅ **BUILT 2026-09-02.** `Doc_block_service` — `listFor` (school-scoped, optionally by type), `get`, `save` (create = v1, edit = version bump), `boundKeys`. Injected store, as Phase 6. | P1.1 | ✅ 13 tests. A block without `schoolId`/`blockType` is **refused** — an unscoped block would be offered to every tenant. `boundKeys()` reports **what the block imposes across all languages**, so the one-way block→contract coupling is checkable rather than discovered at publish. |
| P8.2 | ✅ **DONE — as the OFFER model, which is the RESOLUTION of a contradiction in the plan itself.** | P8.1, P3.5 | ✅ **The accept is stale.** "Edits propagate to referencing templates" contradicts `COLLECTION_SHAPES` §4 (*"published versions: no update, no delete — ever"*); `FIGMA_ARCHITECTURE_STUDY` resolved it with Figma's library model — an update is **OFFERED**, never pushed, because pushing silently alters a template a principal already approved. Server: `offersFor()` is a **report** that writes nothing; `acceptOffer()` moves **only** that template's pin and is **refused on a published head**; declining is sticky and accepting clears it. Client: E2E **R1/R2** — a version bump leaves the template untouched and the pin held. |
| P8.3 | ✅ **DONE — 7 starters, more than the 6 the plan asked for** (adds `sec_ker`, `lc_5a`, `study`). | P5.1, P2.* | ✅ E2E **R3/R4/R5** across **every** starter: gate-clean, no off-contract key, and an explicit `line-height` on every text object. ⚠️ **One expected gap, now signalled:** `tc_plain` under CBSE is short `doc.bookNo` + `doc.slNo`. That is **correct** — it is the *generic* TC and those are CBSE artifacts; adding them would stop it being generic. What was wrong was offering it with no warning. The gallery card now names the gap **before** the choice (E2E **R6**), the same reasoning as "Set active" being disabled with *"Publish it first"*. |

---

## 11. Phase 9 — Hardening

| # | Task | Accept |
|---|---|---|
| P9.1 | ✅ **DONE 2026-09-02 — the blocker was misattributed.** The plan said *"no PDF→PNG on the Ohio box"*; a per-script render suite runs in **CI and on a developer machine**, never in production, and `pdftoppm` is present there. | ✅ All **7** Indic scripts rendered through the real serializer, rasterised, and checked for **ink on the page** — plus a **blank-page control**, because "there is ink" proves nothing without a threshold calibrated against nothing. This catches the blank-or-tofu failure a byte-level font check cannot: the PDF is valid and embeds the right face, and is unreadable to the reader. |
| P9.2 | ⚠️ **HALF DONE.** **Save is proven**: two concurrent saves produce exactly one conflict and the first writer's edit is verified intact (`DocTemplateServiceTest`). | ⚠️ **Activate is not.** Same blocker as P6.4 — the transaction in the test is a double, so *"exactly one active"* is proven as LOGIC (it displaces every incumbent, and refuses outright without a transaction) but not as ATOMICITY. That needs the emulator with genuinely concurrent clients. **Not counted.** |
| P9.3 | ✅ **DONE 2026-09-02.** Typed codes pinned (`E_PAGE_OVERFLOW`, `E_IMAGE_SOURCE`, `E_CONFLICT`, all `^E_[A-Z_]+$`), and the caps are now **tripped for real**. | ✅ `MAX_PAGES` trips on a 6-page document with `maxPages: 2`; `pageMode: 'single'` trips on the second page; a document within the cap renders normally. ⚠️ `MAX_MEMORY`/`MAX_SECONDS` remain `ini_set` ceilings rather than throwing paths — they are asserted **declared and sane** with their G0.6 derivation, which is the honest limit of a unit test. |
| P9.4 | ⚠️ **ONE CLAUSE DONE.** *"Critical failure never returns a success response"* is asserted: every failure path in the engine **throws**, none returns an empty string, a partial document, or a null a caller could mistake for output. | ⚠️ Latency, memory high-watermark, font-failure counters and correlation IDs are **not built** — they need the metrics sink, which is outside this module. **Not counted.** |
| P9.5 | ⛔ **BLOCKED ON B4.** A restore drill needs a real test school to back up and restore. | ⛔ The accept — *"a restored test school reproduces a proof PDF matching its recorded hash"* — is well-formed and the ingredients exist: P6.2 already freezes `proofPdfHash`, `fontManifest` and `mpdfVersion` into every snapshot precisely so a re-render can be checked. **The drill just needs a school.** |
| P9.6 | ✅ **DONE 2026-09-02 — and it found a real gap.** 27 tests. Both image paths guarded **separately**, authored text and resolved merge values escaped, CSRF exclusion asserted absent, every endpoint asserted to declare a capability. | ✅ **The serializer had NO image guard.** `Doc_renderer::guardImages()` protected the PDF path, but **the browser preview never passes through the renderer** — so a template carrying `https://tracker.example/p.gif` rendered it in the designer. 8 hostile sources rejected on both paths, incl. `javascript:` and `data:`, which carry no `//` and so slip past a `scheme://` test. |
| P9.7 | ⛔ **BLOCKED ON B4 + a human.** | ⛔ UAT signed off by the P1 (clerk) and P2 (principal) personas cannot be simulated. **This is the row that decides whether the module is really finished**, and nothing in the plan can substitute for it. |

---

## 12. Phase 10 — Legacy cutover

Per v1.1 §15. `Certificates.php` stays live and untouched throughout.

1. **Dual-run** behind a per-school feature flag, default **off**
2. **Parity set** — 5 most-issued types rendered through both paths on the same data; differences
   triaged, **not waived**
3. **Pilot** — 3 schools, one board each, one full session-quarter
4. **Migration** — legacy layouts are **re-authored by us as starters**, never auto-converted
   (auto-conversion of an untyped layout into a typed object model is a false economy)
5. **Freeze** — legacy read-only; issued documents unaffected
6. **Removal** — only after one full session with zero P1s; deletion is its own change with its own
   permission

---

## 13. Definition of done *(A3 — adapted from the FINAL doc, Template-Engine scope)*

Architecturally ready only when **all** are true:

1. Preview and production PDF use the same serializer output
2. Renderer proof tests pass for all 8 scripts, including conjuncts and matras
3. Measured preview↔proof divergence is within the published tolerance
4. **Page overflow throws; it never clips** a legal document
5. Published template content is immutable; version snapshots are create-only
6. Exactly one active template exists per (school, docType)
7. Concurrent edits cannot silently overwrite one another
8. Every material lifecycle action is auditable **in the existing Audit Logs viewer**
9. A snapshot records the exact fonts and engine version used
10. Template rollback never mutates historical versions
11. Renderer failures fail closed; unresolved merge fields never print
12. Tenant isolation enforced server-side **and** in Firestore rules
13. CSRF protection is **on** for every state-changing route
14. Backup/restore exercised successfully at least once
15. Critical integrity failures are observable and cannot be hidden as success responses

---

## 14. Stop conditions

Halt and re-plan if any occurs:

- **G0.4 fails** — block-flow-inside-absolute unreliable ⇒ §0 reverses, measurement pass returns,
  Phase 0.1 comes back and this plan is re-cut
- **G0.3 fails for any script** ⇒ `CON-MULTILINGUAL` cannot be met on mPDF; renderer decision reopens
- **G0.6 shows OOM risk** at realistic templates ⇒ resource limits or renderer strategy change
- Divergence (G0.5) cannot be brought within tolerance ⇒ preview model reconsidered

---

## 15. Still-open decisions

| # | Question | Needed by |
|---|---|---|
| Q1 | Does CBSE mandate field **order**, or only presence? | P5.1 |
| Q7 | Does block-flow-inside-absolute hold in the deployed mPDF? | **G0.4** |
| Q8 | Retention period (7 years assumed) — confirm against board/state rule | P9.5 |
| Q9 | `languageFallback` default — `block` or `default` | P7.5 |
| Q10 | Who owns compliance-profile authoring long-term? | P5.6 |
| — | **Does issuance enter this build or stay in the next engine?** Currently OUT (`CON-NO_PRINT_IMPL`) | Before Phase 6 |

---

### Phase 2 closed 2026-09-02 — and it fixed a live bug in Doc_renderer

`[FACT|OBSERVED]` **`Doc_renderer::wouldOverflow()` computed the page height wrongly for three of
the four supported papers.** It read:

```php
is_array($cfg['format']) ? $cfg['format'][1] : ($orientation === 'L' ? 210.0 : 297.0)  // A4 default
```

but `self::PAPER` maps names to **strings** (`'A4' => 'A4'`), so `is_array()` was **false for every
named size** and the A4 fallback ran every time:

| Paper | Real height | Gate used | Effect |
|---|---|---|---|
| A5 portrait | 210 mm | 297 mm | **87 mm TOO LENIENT** |
| Letter portrait | 279.4 mm | 297 mm | 17.6 mm too lenient |
| Legal portrait | 355.6 mm | 297 mm | 58 mm too strict — false positives |

**Too lenient is the dangerous direction.** This gate is the only thing stopping an over-long field
clipping the signature block off a Transfer Certificate, and on A5 it was passing content 87 mm past
the end of the page. Fixed with a `PAPER_MM` table beside `PAPER` so the two cannot drift, plus
orientation handling the old array branch never had. `DocRendererPageGeometryTest` pins all four
papers in both orientations and is **mutation-tested** — re-injecting the old expression fails 3 of 6.

**Two more defects the tests caught on their first run**, both silent by nature:
1. **An anchor cycle dropped objects entirely.** In `a→b→a` every member has a valid anchor, so none
   becomes a root, the walk never visits them, and they vanish from the document — on a certificate,
   a statutory field quietly not printing. Now a hard error naming the unreachable ids.
2. **Tier 1 was dead code.** `overflowFindings()` guarded on `method_exists($renderer,'pageCount')`
   and `Doc_renderer` had no such method, so the tier silently never ran while the gate still
   reported "no findings". `pageCount()` added; the guard now **throws** rather than skipping.

**Phase 2 test surface:** `DocSerializerTest` 28 · `DocSerializerGoldenTest` 4 · `DocRendererPageGeometryTest` 6.
Suite **302 tests, 4 failures, 27 skipped** — the repo's standing baseline, unchanged.

---

## 16. Status

Gate 0 complete. Committed so far: `Doc_renderer` (`1dada83`), `Doc_templates` controller
(`eb44518`), SPA shell (`00e39df`), scoped CSS (`e223caf`).

**Uncommitted / deploy-pending:** the UI port's Content pane (UX_SPEC §5A.4b), the responsive pass of
2026-08-25, the P1.8/P1.9 contract layer of 2026-08-27, and the two fixes below.
**No rules edited, no indexes declared, nothing deployed.**

### Responsive pass 2026-08-25 — was never recorded here

`[VERIFIED — re-run independently 2026-08-27, not inherited from the prior session]` The session that
did this work was killed mid-verification, so its result was recovered from the run artifact and then
**re-derived from scratch against the current tree**.

- Drawers fixed (`transform:none`, `visibility:visible`, both close again).
- The layout audit itself was **over-reporting** and was corrected: a status bar that *scrolls* is not
  clipped. An audit that cries wolf gets ignored, so the check was made honest before the last defect
  was chased.
- **760 px topbar overflow fixed** — 847 px of controls in a 760 px viewport, resolved by dropping
  decoration and making the remainder scrollable, plus an `#inspBtn` drawer toggle.
  **No control was removed**; that was the constraint the fix had to satisfy.
- Layout audit **clean at 1000 / 900 / 760**.
- **E2E re-run 2026-08-27: 102 / 102 passed, 0 failures, 0 page errors** — the tabstrip click-handler
  change caused no regression.

### P1.8 + P1.9 — the contract layer, 2026-08-27

The client state machine was complete and green while **every endpoint in `Doc_templates.php` was
still a stub returning `pending P1.x`**. The gap closed first is the one that fails silently.

`designer.js` already held the contract (`CONTRACT`, `CONTRACTS`, `TYPES`). Seeding a server copy
*independently* is precisely how the two drift, so the server file was derived from the client's and
**pinned to it by a test** rather than by a comment:

- **`config/doc_types.php`** — 30 fields, 6 contracts, 8 types.
- **`libraries/Doc_contract.php`** — the only sanctioned server reader of it.
- **`tests/Unit/DocContractParityTest`** — parses `designer.js` and fails the build if either side
  moves. Each parser asserts it found something first, so a broken parser cannot make the suite pass
  vacuously.
- **`tests/Unit/DocContractServiceTest`** — 20 behavioural tests.

**The severity boundary this layer establishes, and it is the part most likely to be broken later:**
an **unresolved** field is an **ERROR** (a blank on a statutory document is a defective document),
while an **over-length** field is only a **WARNING** — `maxLen` is our own **Level D** rendering
estimate, so blocking on it would reject lawful data. Over-length is handed to the **P2.7 overflow
gate**, which measures the actually-rendered block. Collapsing those two severities breaks the module
in one direction or the other.

`[FACT|OBSERVED]` Unit suite after this work: **243 tests, 4 failures, 27 skipped** — the repo's
standing baseline, unchanged. The 4 failures and 27 skips are pre-existing and were not touched.

### P1.1 / P1.2 / first live endpoint — 2026-08-27, continued

**P1.1 was already done and unmarked.** `COLLECTION_SHAPES.md` covers all five collections.

**P1.2 stays WITHDRAWN, and that was re-verified rather than assumed.** Recomputed from the cached
live snapshot using Aegis's own canonical index key: **284 live · 193 declared in the working tree ·
183 in both · 101 live-only · 10 declared-only.** A desired-state deploy would still offer to delete
101 indexes covering Transport, CRM, campus access and visitor passes. **Zero indexes exist — live or
declared — for any of the six Document Engine collections**, so P1.2 is genuinely undone rather than
half-done. Full working in `COLLECTION_SHAPES.md` §8.1.

> 🔴 **AND THE TOOL THAT WAS MEANT TO CHECK THIS IS GONE.** `aegis/` now contains only `.state/` and
> `.reports/` — **`aegis/cli.js` does not exist**, while the repo `CLAUDE.md` still instructs
> `node aegis/cli.js indexes` (and `rules status`) as the verification step. The figures above come
> from a **cached snapshot dated 2026-08-15 — twelve days stale.** That is *why* this drift number
> keeps being quoted instead of re-measured, and quoting it is how a stale number becomes a fact.
> **Restoring Aegis is a prerequisite for closing P1.2 and for P1.3, not an unrelated chore.**

**First endpoint off the stubs: `get_types`.** It is config-driven plus **one keyed read** of the
school doc — no composite query — so it is not blocked by P1.2 or P1.3 the way the template reads
are. It returns the school context and **the full catalogue, including types this school cannot use,
each with a reason** (`Doc_contract::catalogue()`); a type that silently disappears reads as "this
product does not support my state", which is wrong and unactionable. A missing contract fails loudly
rather than rendering as an empty catalogue.

`[FACT|OBSERVED]` Unauthenticated `GET /doc_templates/get_types` → **HTTP 307** to login: the G0.7
capability gate still holds after the change. No route added, no entry added to `CAPABILITIES`, so
the fail-closed property is untouched. Unit suite: **250 tests, 4 failures, 27 skipped** — baseline.

⚠️ **The SPA still runs on its own copy of the catalogue.** Shipping this endpoint does not switch the
client over; that is a later step. Until then `DocContractParityTest` is the only thing keeping the
two identical.

**Next, in order:** restore Aegis → reconcile `firestore.indexes.json` to live (a repo-wide hazard,
not this module's) → P1.2 declare → P1.3 rules, `rules status` **before** any edit → then
`get_templates` / `get_template`, which are the first genuinely Firestore-backed reads and the point
at which the SPA can stop running on seeded in-memory data.

### Port defect found 2026-08-23 — `assets/js/doctemplates/assets.js` deleted

The UI port committed in `00e39df` created `assets.js` as a second script alongside `designer.js`.
Three faults, each hiding the next:

1. A comment block had lost its opening `/*`, so the file was a **syntax error** and never
   executed. That masked the other two.
2. **Every** top-level declaration in it (`OK_TYPES`, `readAsset`, `applyAsset`, `openSignaturePad`,
   `pickFile`, `TOOLKEY`, `modal`, `openProof`, `openPublish`, `openHistory`, `openConflict`, …)
   was a **byte-identical duplicate** of a region already present in `designer.js`. Nothing in the
   file was unique.
3. The view loads `assets.js` **before** `designer.js`, so once it parsed it threw
   `OK_TYPES has already been declared` — which **kills `designer.js` entirely**. It also called
   `$()` at load, before `designer.js` defines it.

Net effect: on the real page the Certificate Designer would have been **completely dead**, and only
the syntax error was preventing it. Fixed by deleting `assets.js` and its two `<script>` tags
(`application/views/doc_templates/index.php`, local harness). `designer.js` is self-contained.

**Rule for the rest of the port: one script, `designer.js`.** Do not split it without removing the
original copy, and syntax-check every ported file (`node --check`) — a parse error presents as a
silently missing feature, not as an error anyone sees.

### End-to-end client suite 2026-08-23 — 102 tests, all passing

`_zxdt_e2e.js` (untracked, in the webroot) drives hub → type → gallery → classic starter **and**
blank canvas → design → validate → proof → publish → activate → history, for every enabled document
type in every state that gates one. Run it headlessly with no dependencies:

```bash
php -S localhost:8080                        # from the repo root
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  --headless=new --disable-gpu --virtual-time-budget=180000 \
  --dump-dom http://localhost:8080/_zxdt_e2e_run.html
```

Groups: boot/hub (6) · gallery (9) · creation (8) · editing (13) · **validation matrix (15)** ·
proof (2) · **publish (10)** · activation (7) · history/compare/conflict (4) · cross-cutting (6) ·
**full lifecycle per certificate type (10)** · compliance stack (8) · page/tools (4).
Result: **102 passed, 0 failed, 0 page errors.**

*Scope limit:* every endpoint in `Doc_templates.php` is still a stub returning `pending P1.x`, so
the suite asserts the client state machine only. Nothing about persistence is proven yet.

**Three defects it found, all fixed:**

1. **Form 5A was permanently unpublishable.** `boundKeys()` scanned *every* language present on an
   object, not just those the template declares. The Kerala Form 5A starter declares `en` only and
   rewrites its English run to `sec.outcome`, but the inherited Hindi run still bound
   `tc.reasonForLeaving` — a key the 5A contract does not declare. The template therefore blocked
   publish with an `offContract` error naming a field that **could not be seen or removed from the
   UI**, in a language the template did not have. Fixed at the root (`boundKeys()` now ignores
   undeclared languages) plus `pruneLanguages()`, applied to every starter on the way out so the
   dead runs never survive the build. Two more starters (`sec_ker`, `conduct`) carried the same
   stale Hindi runs and are now clean. Guarded by K91/K92.
2. **The publish button lied.** It read *"Publish and set active"* while the handler deliberately
   does not activate — §9.2c is explicit that publish ≠ activate, and the very next modal asks for
   activation separately. On a legally consequential action the label promised something it did not
   do. Now reads `Publish v{n}`.
3. **The blank-canvas card over-promised.** *"required objects pre-placed"* — but the filter keeps
   only objects carrying `region` or `requiredKey`, which drops the particulars table, the
   declaration, all three prescribed signatures, the seal and the duplicate mark, leaving 14 of 19
   required fields unbound. Copy corrected to *"letterhead and page furniture only"*. The behaviour
   is right — a blank canvas should be blank, and the publish gate blocks and names every gap
   (verified by H5b/K90) — the copy was the part that was wrong.
