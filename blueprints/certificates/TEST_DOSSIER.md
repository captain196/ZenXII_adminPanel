# Certificate Designer — Test Dossier

**What this is:** the input to the testing phase. For every phase built, it records what is
**PROVEN** (and by which test), what is **BUILT BUT UNPROVEN**, and what is **NOT BUILT** — so UAT
starts from evidence rather than from a plan row that says done.

**Why it exists separately from `EXECUTION_PLAN_v1.1.md`:** the plan tracks *tasks*. This tracks
*claims*. Three plan rows in this module (P1.1, P1.4, P1.5) were marked incomplete while the
artifact sat finished on disk, and one accept criterion (P3.6) is still counted as unmet because
the thing that would prove it does not exist yet. A task list cannot carry that distinction.

**Updated:** 2026-09-02 · **Progress:** 29/58 = 50% · Branch `yug_testing`

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

# Client E2E — 114 cases, needs the local server + headless Chrome
php -S localhost:8080                                     # from the repo root
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  --headless=new --disable-gpu --virtual-time-budget=200000 \
  --dump-dom http://localhost:8080/tests/doctemplates/_zxdt_e2e_run.html

# Firestore rules — emulator
cd firebase-rules/tests && npm test
#   ⚠ USE --testTimeout=60000. The ruleset is ~3,000 lines and
#     initializeTestEnvironment now exceeds Jest's default 5s hook timeout,
#     cascading into ~170 PHANTOM failures that look catastrophic and are not.
```

### Baselines — judge a change against these, not against zero

| Suite | Baseline | Meaning |
|---|---|---|
| PHP unit | **4 failures · 27 skipped** | Pre-existing. The skips are cross-repo tests pointing at a hardcoded Windows path from another machine. |
| Client E2E | **114/114 · 0 page errors** | Should be green. Anything less is a regression. |
| Rules emulator | **4 suites fail** | **Stale assertions**, verified: each asserts a *client* admin write that SEC-3 wave3/wave4 deliberately removed. Not a break. |

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

---

## 2. BUILT BUT NOT PROVEN — the honest column

> These are the ones to attack first in UAT. Each is code that exists and behaviour nobody has
> demonstrated.

| # | Claim | Why it is not proven | What would prove it |
|---|---|---|---|
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
6. **Judge against the baselines in §0**, not against zero.

---

## 5. Change log

| Date | Phase | Progress |
|---|---|---|
| 2026-09-02 | Phase 4 — picker/chip/i18n proven, **capacity hint built** | 29/58 = 50% |
| 2026-09-02 | Phase 3 acceptance proven (7/8) | 24/58 = 41% |
| 2026-09-02 | Phase 2 closed — serializer + overflow gate + goldens | 17/58 = 29% |
| 2026-08-28 | Phase 1 closed — P1.3 rules, six blocks + 45 tests | 9/58 = 16% |
