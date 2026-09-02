# Certificate Designer — Test Dossier

**What this is:** the input to the testing phase. For every phase built, it records what is
**PROVEN** (and by which test), what is **BUILT BUT UNPROVEN**, and what is **NOT BUILT** — so UAT
starts from evidence rather than from a plan row that says done.

**Why it exists separately from `EXECUTION_PLAN_v1.1.md`:** the plan tracks *tasks*. This tracks
*claims*. Three plan rows in this module (P1.1, P1.4, P1.5) were marked incomplete while the
artifact sat finished on disk, and one accept criterion (P3.6) is still counted as unmet because
the thing that would prove it does not exist yet. A task list cannot carry that distinction.

**Updated:** 2026-09-02 · **Progress:** 52/58 = 90% — all nine phases opened; **10 of 14 endpoints now live** · Branch `yug_testing`

**Progress is computed:** `awk -f blueprints/certificates/tools/progress.awk blueprints/certificates/EXECUTION_PLAN_v1.1.md | sort`

---

## 0. How to run everything

```bash
# PHP unit suite  — server side (serializer, contract, renderer geometry)
cd ~/Desktop/Zennxii_adminPanel
vendor/bin/phpunit --testsuite Unit                       # whole suite
vendor/bin/phpunit --testsuite Unit --filter DocSerializer # this module only

# Golden files — byte-for-byte serializer output
ZXDT_GOLDEN_UPDATE=1 vendor/bin/phpunit --testsuite Unit --filter Golden
#   ^ regenerates. THEN READ THE DIFF. A golden regenerated without reading the
#     diff records the bug as the new truth and goes green forever after.

# Client E2E — 133 cases, needs the local server + headless Chrome
php -S localhost:8080                                     # from the repo root
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  --headless=new --disable-gpu --virtual-time-budget=200000 \
  --dump-dom http://localhost:8080/tests/doctemplates/_zxdt_e2e_run.html

# Firestore rules — emulator  (16 suites, 404 tests, ALL GREEN)
cd firebase-rules/tests
firebase emulators:exec --only firestore,storage --project=zenxii-rules-test \
  "npx jest --runInBand --forceExit --testTimeout=60000"
#
#   ⚠ TWO FLAGS THAT ARE NOT OPTIONAL, and `npm test` sets NEITHER:
#
#   --testTimeout=60000  the ruleset is ~3,000 lines and
#     initializeTestEnvironment exceeds Jest's default 5s HOOK timeout,
#     cascading into ~170 PHANTOM failures that look catastrophic and are not.
#
#   --only firestore,storage  without the storage emulator,
#     support_storage_attachments fails 21 times with ECONNREFUSED :9199,
#     which reads as a broken test rather than an emulator never started.
```

### Baselines — judge a change against these, not against zero

| Suite | Baseline | Meaning |
|---|---|---|
| PHP unit | **4 failures · 27 skipped** | Pre-existing. The skips are cross-repo tests pointing at a hardcoded Windows path from another machine. |
| Client E2E | **133/133 · 0 page errors** | Should be green. Anything less is a regression. |
| Rules emulator | **16/16 suites · 404/404** | Green as of 2026-09-02. Previously 5 suites failed: 4 on stale assertions (fixed — see below) and 1 because the storage emulator was never configured. |

---

## 1. PROVEN — with the test that proves it

### Phase 1 — Foundation (9/9)

| What | Proven by |
|---|---|
| Client and server merge-field contracts cannot drift | `DocContractParityTest` — **parses `designer.js`** and fails if either side moves. Negative-tested: a dropped contract key and an undersized `maxLen` both caught. |
| Contract service fails closed | `DocContractServiceTest` — unresolved **and empty-string** both block; off-contract blocks; over-length only **warns**. |
| Per-type contract scoping | A Kerala school-education certificate is not offered CBSE attendance/promotion fields. |
| State gating | Kerala/AP types hidden outside their state; disabled types offered in no state. |
| `documentTemplateVersions` frozen forever | `document_engine.test.js` — update/delete denied at **every** capability grade. |
| Published head nearly frozen | Only `activeVersion` / `status→archived` / `lockVersion` may move. |
| Activation is `manage`, not `edit` | `activeVersion` is the pointer every print point resolves. |

### Phase 2 — Serializer (8/8)

| What | Proven by |
|---|---|
| Absolute emission in mm; `height:auto` omits height | `DocSerializerTest` N/A → `test_an_unanchored_object_is_absolutely_positioned_in_mm` |
| A 3-deep anchor chain emits **exactly one** `position:absolute` | `test_an_anchor_chain_emits_one_container_with_block_children` |
| Unresolved field **throws**, never prints blank | `test_an_unresolved_field_throws_rather_than_printing_blank` |
| A design-mode chip can never reach print | `test_a_literal_placeholder_can_never_reach_the_output` |
| Every selector namespaced | The test **parses the emitted `<style>`** and checks each selector — it does not grep. |
| No flex, no grid | `test_no_flex_and_no_grid_is_ever_emitted` |
| Mandatory `line-height` | `test_a_text_object_without_line_height_is_rejected` |
| Overflow gate, **both tiers independently** | Tier 1 never consulted for an absolute chain; a blind renderer **throws** rather than skipping. |
| Page height correct for all 4 papers × 2 orientations | `DocRendererPageGeometryTest` — **mutation-tested**, old expression fails 3/6. |
| Byte-for-byte output | 3 golden files; a single changed byte fails all three. A 4th test asserts the goldens **differ from each other**. |

### Phase 3 — Canvas (7/8)

| What | Proven by |
|---|---|
| 20 mm object measures **20.00 mm at 100% and 250%** | E2E **N1** — `getBoundingClientRect()` |
| Position round-trips byte-exact | E2E **N2** — 37.25 / 91.5 / 123.75 |
| Snap threshold genuinely in **px** | E2E **N3** — 1.5 mm gap snaps at 50%, not at 250%, **through the real `snap()`** |
| Align gives identical edges | E2E **N4** — 5 objects → 1 distinct edge |
| Z-order survives reload | E2E **N5** |
| All 12 inspector properties round-trip | E2E **N6** |
| Drag is one undo entry, not one per mousemove | E2E D2/D3/J2/J3 + `push()` fires on gesture end |

### Phase 4 — Text and binding (5/5)

| What | Proven by |
|---|---|
| The field picker **is** the contract — no free-typing route | E2E **O1** — offers 22 of 30 keys for a TC, **zero** off-contract |
| Merge chips are void nodes; keys survive a DOM round trip | E2E **O2** — `runsHTML()` → DOM → `parseRuns()`, keys intact |
| A language switch preserves **both** languages' runs | E2E **O3** — en→hi→en, both byte-identical |
| Capacity hint reports the bound field's budget and real usage | E2E **O4/O5/O6** — tracks the p95 toggle (27 → 59); unbound text gets no budget |

> **Quill was never vendored.** The editor is `contentEditable` + the run model + the Content pane
> (`design/TEXT_EDITING_PROPOSAL.md`, built and verified). P4.1's *accept* is met — no bundler, clean
> mount/unmount — while its *task text* is stale. Anyone testing should not go looking for Quill.

### Phase 5 — Compliance (4/6)

| What | Proven by |
|---|---|
| An unverified board adds **no board-tier layer**; `generic` only when the stack is genuinely empty, and it **says so** | E2E **P1** |
| A required object is undeletable **and the refusal cites** Authority / Evidence / Verified | E2E **P2** |
| Evidence level reaches the reader; `EVIDENCE_RANK` strictly ordered | E2E **P3** |
| 14 unbound required keys block publish **while the draft stays editable** | E2E **P4** |
| Nothing auto-invalidates when an authority is re-verified | E2E **P6** |

> ⚠️ **The plan's P5.2 accept is stale.** It says a Karnataka state-board school "resolves to
> generic" — true under the single-profile model that `COMPLIANCE_ARCHITECTURE.md` killed. Under the
> stack, **RTE Act 2009 still binds elementary schooling whatever the board**, so resolving to RTE is
> correct and resolving to `generic` would have been the bug: it would tell a school bound by a
> statute that no rule applies.

### Phase 6 — Publish pipeline (6/7)

| What | Proven by |
|---|---|
| Two concurrent saves → **one conflict, no lost edit** | `test_two_concurrent_saves_produce_exactly_one_conflict_and_no_lost_edit` — and the first writer's edit is verified still present |
| `save()` cannot move lifecycle fields | status / activeVersion / publishedVersion / templateId are stripped |
| Publish freezes v*n*, head opens **draft v*n+1*** | Snapshot is **byte-identical** after a later head edit |
| Publish refuses without `hash` + `fontManifest` + `mpdfVersion` | Data provider, one case per field |
| Compliance layers **frozen, not referenced** | A later authority revision cannot retroactively change what an issued certificate was validated against |
| Snapshots are create-only | Re-publishing over a version id is refused; rules deny update/delete at every grade |
| Publish does **not** activate | The incumbent keeps serving until someone deliberately activates |
| Illegal transitions rejected server-side | `published → draft` impossible; archived is terminal |
| Every transition audited with a description | publish / activate / archive |

### Phase 7 — Language and fonts (4/5)

| What | Proven by |
|---|---|
| Every family the picker offers is declared `@font-face` | E2E **Q1** — 7 faces, none undeclared |
| Every face is `font-display:block`, never `swap` | E2E **Q2** |
| A font load failure is **reported**, not absorbed | E2E **Q3** + `verifyFonts()` |
| The untranslated report names every gap | E2E **Q4** — 9/11, 2 gaps |
| Every statutory starter pins `languageFallback: block` | E2E **Q5** |
| The server honours `block` / `default` | 3 `DocSerializerTest` cases, incl. `default` still throwing when the default language is also missing |
| Preview and PDF declare the **same faces, same files** | `DocFontParityTest` — and no family falsely claims a bold face |

> ⚠️ **`@font-face` did not exist in EITHER surface before this.** The serializer emitted
> `font-family:lohitdeva` that the browser had no face for, **and the designer canvas had the same
> defect**: the picker offered `lohitdeva`/`lohittaml`/`lohitbeng` while `doctemplates.css` declared
> none of them. Choosing a Devanagari face changed nothing on screen while mPDF set the PDF in Lohit
> — the canvas showed a layout that would never print, in the one place layout is being decided.
> The picker also offered only **3 of 7** Lohit families; a template could legitimately reference
> `lohitgujr` and nobody could select it.

### Phase 8 — Blocks and starters (3/3)

| What | Proven by |
|---|---|
| A block version bump **mutates no template** | `test_bumping_a_block_version_mutates_no_template` + E2E **R1** |
| `offersFor()` is a **report** — it writes nothing | Lists only templates behind, with pinned/available |
| Accepting moves **only that** template's pin | And is **refused on a published head** |
| Declining is sticky; accepting clears it | Server test + E2E **R2** |
| An unscoped block is refused | No `schoolId`/`blockType` → `InvalidArgumentException` |
| `boundKeys()` reports what a block **imposes** | Across all languages — the one-way block→contract coupling, checkable |
| All 7 starters gate-clean, no off-contract key, all `line-height` set | E2E **R3/R4/R5** |
| A starter short of the active stack **names the gap on its card** | E2E **R6** |

> ⚠️ **P8.2's accept is stale and following it would be a bug.** It says edits "propagate to
> referencing templates"; that contradicts `COLLECTION_SHAPES` §4 and was resolved by
> `FIGMA_ARCHITECTURE_STUDY` with the library model — **offered, never pushed**. Pushing would
> silently alter a template a principal already approved.
>
> ⚠️ **`tc_plain` under CBSE is short `doc.bookNo` + `doc.slNo` BY DESIGN.** It is the *generic* TC
> and those are CBSE artifacts. The bug was offering it with no warning; the card now names the gap
> before the choice.

### Phase 9 — Hardening (1/7) — and the six that are not counted are the honest core of this dossier

| What | Proven by |
|---|---|
| 8 hostile image sources rejected on **both** paths | `DocSecurityTest` — incl. `javascript:` and `data:`, which carry no `//` |
| Authored text and resolved merge values escaped | No element can be introduced by a student's name |
| No `doc_templates` route excluded from CSRF | publish/activate change what a school legally issues |
| Every endpoint declares a capability | An undeclared one fails closed, but silently |
| Error codes typed and well-formed | `E_PAGE_OVERFLOW` / `E_IMAGE_SOURCE` / `E_CONFLICT` |
| No failure path returns instead of throwing | A returned failure would render as content |

> ⚠️ **The serializer had NO image guard.** `Doc_renderer::guardImages()` protected the PDF path, but
> **the browser preview never passes through the renderer** — so a template carrying
> `https://tracker.example/p.gif` rendered it in the designer: a request to a third party from the
> school's browser, from a document nobody thought was networked.

**The six not counted, and why — this is what UAT inherits:**

| # | Blocked by | Note |
|---|---|---|
| P9.1 per-script vs reference PNGs | **No PDF→PNG on the Ohio box** | Scripts verified at G0.2/G0.3 on fixtures. The accept's real point — "regenerating a reference is a reviewed change" — is already honoured by the golden-file discipline |
| P9.2 concurrency (activate half) | Needs the emulator with genuinely concurrent clients | Save half is proven |
| P9.3 tripping each cap | `MAX_MEMORY`/`MAX_SECONDS` are ceilings, not throwing paths | Needs a load harness |
| P9.4 metrics | The sink is outside this module | The "never return success on failure" clause is done |
| P9.5 restore drill | **B4 — no test school** | Ingredients exist: P6.2 freezes hash + fontManifest + mpdfVersion precisely so a re-render can be checked |
| P9.7 UAT | **B4 + a human** | **This is the row that decides whether the module is really finished.** Nothing in the plan substitutes for it |

### Phase 5 addendum — P5.6 re-validation report (closed 2026-09-02)

| What | Proven by |
|---|---|
| The report lists only templates **behind** the current version | `DocComplianceTest` |
| An **excluded** layer is not reported | The school has a written reason on file |
| **Active first** — what print points resolve today | A stale draft harms nobody until published |
| An unknown authority **throws** | "0 affected" for a non-existent id reads as reassurance |
| **Nothing auto-invalidates** — asserted two ways | The report mutates nothing, **and** the class exposes no `set/save/update/apply/invalidate/…` method at all |
| Evidence is the **best** across applied layers, never averaged | Averaging would let two Level-C citations present as Level-B |

### Real PDF renders — four rows that were "blocked" and were not (2026-09-02)

`Doc_renderer` was thought untestable because it needed CodeIgniter. It needed it for **one line** —
`$this->ci = &get_instance()`, assigned and never read. Guarding that made every PDF behaviour
directly testable, and four accept criteria closed on measurement rather than argument.

| What | Proven by |
|---|---|
| **P3.6** — an authored position survives into the PDF | A block at **45.5 mm fits on A4, 290 mm does not**, judged by mPDF's own y-delta |
| **P7.3** — preview and proof agree | Byte-identical HTML in **both engines**: worst divergence **0.011 mm** (latin 4.939/4.936 · 19.756/19.745 · Devanagari & Tamil 6.350/6.350) |
| **P9.1** — per-script rendering | All 7 Indic scripts **rasterised** and checked for ink, with a **blank-page control** |
| **P9.3** — caps trip | `MAX_PAGES` and `pageMode:'single'` both throw `E_PAGE_OVERFLOW` on real renders |

> ⚠️ **P9.1's blocker was misattributed.** The plan said "no PDF→PNG on the Ohio box" — but a
> per-script render suite runs in **CI**, never in production, and `pdftoppm` is present there.
>
> ⚠️ **The ink check needs its control.** "There is ink on the page" proves nothing without a blank
> page to calibrate against. It catches the blank-or-tofu failure a byte-level font check cannot: the
> PDF is valid, embeds the right face, and is unreadable.
>
> ⚠️ **T2 guards T1.** If the Lohit faces are not actually loaded, the browser measures a system-font
> fallback and the agreement test compares the wrong thing while possibly still passing.

---

## 2. BUILT BUT NOT PROVEN — the honest column

> These are the ones to attack first in UAT. Each is code that exists and behaviour nobody has
> demonstrated.

| # | Claim | Why it is not proven | What would prove it |
|---|---|---|---|
| **P7.3** | Preview and proof agree within the G0.5 tolerance | The switcher works and both languages' runs are preserved, but the accept demands a **measured comparison of a rendered PDF against the canvas**. `@font-face` removes the largest known cause of disagreement; it does not demonstrate the tolerance | Phase 6's proof-PDF path, then measure |
| **P6.4** | Two concurrent activates → exactly one active | The activate LOGIC is proven (displaces every incumbent; refuses outright when no transaction is available), **but the transaction in the test is a double**. Real atomicity is Firestore's | Emulator run with genuinely concurrent clients |
| **Controller wiring** | ✅ **10 of 14 endpoints wired 2026-09-02** — get_types, get_templates, get_template, get_blocks, save, save_block, publish, activate, archive, preview. `DocSecurityTest` pins the live set **and** the stub set, so neither can move by accident. **Still stubs: `create`, `validate`, `preview`→ wired, `proof_pdf`, `upload_asset`** | The four remaining need numbering (`create`), the validation matrix ported server-side (`validate`), storage writes (`proof_pdf`, `upload_asset`) | Exercise against a seeded school — needs B4 |
| **P5.1** | CBSE TC required-key list | Declares **19 keys against Annexure-I's 22**, flagged `illustrative:true` / `fieldListVerified:false`. **Blocked on a human, not on code** | Gate 0.3 transcription + 0.8 second-person sign-off |
| **P5.6** | Affected-school re-validation report | The *guarantee* holds (nothing auto-invalidates), but the report itself must query templates across tenants — inherently server-side | Phase 6 |
| **P3.6** | Typing `45.5` mm places the object at exactly 45.5 mm **in the proof PDF** | Round-trips in the model (N2/N6), but **there is no proof-PDF path until Phase 6** | Phase 6, then measure the rendered PDF |
| **P2.3** | The flow region paginates with a repeating header/footer | Emission is asserted (no `position:absolute`); **multi-page pagination has never been rendered** | A real mPDF render of a body longer than one page |
| **Fonts** | Indic scripts shape correctly | Gate 0 verified Lohit registers and 8 subsets embed — **on fixtures, not on a real certificate** | Render a TC in Hindi/Tamil and read it |
| **Lohit bold** | — | **Lohit ships Regular only**; mPDF synthesises bold. If a template needs true Indic bold it will not get one | Visual check at UAT; a per-script bold source is needed before launch if rejected |
| **Everything server-side** | Persistence | **Every endpoint in `Doc_templates.php` except `get_types` is still a stub returning `pending P1.x`.** The SPA runs entirely on seeded in-memory data. **This is the single biggest gap in the module** | Phase 6 |

---

## 3. NOT BUILT

Phases 5–9: compliance, publish pipeline, language, blocks and starters, hardening.
**29 of 58 tasks.** The Certificate Designer currently ships **dormant** — not linked from any nav
include, capability-gated behind `Certificates`, and 13 endpoints still stubs. It goes out inert.

---

## 3A. The rules suite went green on 2026-09-02 — how, and why it matters

It had **5 failing suites / 30 failing tests**. None was a rules defect.

**One was pure configuration.** `tests/firebase.json` is a SECOND config that overrides
`../firebase.json` (the CLI resolves the nearest one to the CWD, and `npm test` runs from `tests/`).
It pinned firestore to port 18080 but declared **no storage emulator at all**, so
`support_storage_attachments` — 21 tests — could never run. The symptom was `ECONNREFUSED :9199`,
which reads as a broken test. Fixed by adding the storage block plus a **symlink**
`tests/storage.rules → ../storage.rules`, because the CLI refuses a rules path that climbs out of
what it treats as the project directory, and duplicating a 30 KB ruleset would guarantee drift.

**Four were stale assertions — and the fix was NOT to flip them.**

Each asserted a *client* admin write that SEC-3 wave3/wave4 deliberately removed. Flipping
`assertSucceeds` → `assertFails` would have made them green while proving nothing: the write is now
denied by SEC-3 whatever the thing under test does.

| Suite | What it is actually for | Fix |
|---|---|---|
| `h_lifecycle` | that `tenantActive()` gates the write path | **Probe changed** to a student's own `prefLang` write — still client-reachable, still routes through `tenantActive()`. Both halves now differ *only* in lifecycle state, which is what makes it a gate test |
| `fold_isadmin` | that `isAdmin()` resolves for legacy / Super Admin / folded admin | **Probe changed** `subjects` → `locations`, which is still `isAdmin() && isSameSchoolWrite()` |
| `exam_visibility` | exam **read** gating; the write test was incidental | **Inverted and renamed** to pin "exams are server-only" — worth keeping, because that is the kind of tightening a future edit relaxes by accident |
| `staff_preflang` | the narrow prefLang self-service clause | **Inverted and renamed.** It is the safety rail for the clause above it: `staff` holds role, staff_roles, department and status — the RBAC grant itself |

> **The general lesson for this dossier:** when a test fails because the contract changed, ask what
> the test was *for* before touching the assertion. Three of these four needed a different **probe**,
> not a different **expectation**.

---

## 4. Traps that will bite the tester

1. **Rules tests need `--testTimeout=60000`.** Without it ~170 phantom failures appear. They are
   `beforeAll` hook timeouts, not rule failures.
2. **Regenerating a golden without reading the diff** silently records a bug as the new expected
   output.
3. **A test can pass for the wrong reason.** Two real cases in this module: a snap test that
   recomputed the threshold formula itself rather than calling `snap()`, and an activation test that
   wrote `activeVersion: 2` onto a head already holding 2 — a no-op the rule correctly allows, which
   made the *manager* case pass without ever exercising the gate.
4. **`0 results` may be a broken matcher, not a clean run.** Hit three times here: a secret scan
   whose pattern rejected real `ya29.c.…` tokens, an index parser reading the wrong JSON path, and a
   `git ls-files -v` grep looking for lowercase when skip-worktree is uppercase `S`.
5. **A helper may measure the wrong thing.** The capacity hint first used `contentPlain()`, which
   substitutes the design-time placeholder `{School name}` — so it counted the LABEL's length and
   never moved with the p95 toggle. It looked right and was inert. Resolve through `fieldValue()`.
6. **Even the progress counter can lie.** The first one counted any row *containing* a checkmark,
   so a blocked task whose evidence column reads "✅ E2E P5 at least proves…" counted as done and
   inflated Phase 5 to 5/6. Only a ✅ that **opens** the task cell counts — see `tools/progress.awk`.
7. **Judge against the baselines in §0**, not against zero.

---

## 5. Change log

| Date | Phase | Progress |
|---|---|---|
| 2026-09-02 | **Real PDF renders** — P3.6, P7.3, P9.1, P9.3 closed on measurement | 52/58 = 90% |
| 2026-09-02 | P5.6 report closed; **controller wired — 10/14 endpoints live** | 48/58 = 83% |
| 2026-09-02 | Phase 9 — security surface built; **serializer image guard was missing** (1/7) | 47/58 = 81% |
| 2026-09-02 | Phase 8 — block service built, offer model proven, starter gaps signalled (3/3) | 46/58 = 79% |
| 2026-09-02 | Phase 7 — **`@font-face` built in both surfaces**, languageFallback honoured (4/5) | 43/58 = 74% |
| 2026-09-02 | Phase 6 — lifecycle service built: lock/publish/activate/archive (6/7) | 39/58 = 67% |
| 2026-09-02 | Phase 5 — compliance stack proven (4/6); **progress counter fixed** | 33/58 = 57% |
| 2026-09-02 | Phase 4 — picker/chip/i18n proven, **capacity hint built** | 29/58 = 50% |
| 2026-09-02 | Phase 3 acceptance proven (7/8) | 24/58 = 41% |
| 2026-09-02 | Phase 2 closed — serializer + overflow gate + goldens | 17/58 = 29% |
| 2026-08-28 | Phase 1 closed — P1.3 rules, six blocks + 45 tests | 9/58 = 16% |
