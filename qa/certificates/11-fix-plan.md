# FIXES APPLIED — 2026-09-04 · UNCOMMITTED / DEPLOY-PENDING

Authorised by the human ("decide what you think will be better to do first"). Applied to
findings that are **proven at the code level**, not to hypotheses — the stop gate exists to
stop fixes to things you only *think* are broken; each of these was traced link by link and
two were reproduced against the running server.

## The decision, and why this order

The findings looked like six separate defects. Four of them were **one defect**:
`save()` filtered its input with a **denylist of 7 names against a 24-field document**.
`docType` and `version` had already fallen through it. Adding those two names would have
closed two reports and left the shape intact for the next field anyone adds.

So the ordering was not "P0 first" — it was **root cause first**, which happened to take the
P0 with it.

## F1 · `save()` inverted from a denylist to an allowlist
`Doc_template_service.php` — a draft edit may change the DESIGN and nothing else:
`name · docTitle · page · header · footer · objects · languages · defaultLanguage ·
complianceLayers`. Everything else is dropped by default, present or future, known or
forgotten.

**Closes four findings at once:** P0 OV1 (link 1), P1 OV2 / L9 `docType` bypass,
A8-3 `version` read-amplification, A6-N9 hand-patched counter.

The save still **succeeds** — refusing outright would break any future client that
round-trips a whole template object through `save()`. But the dropped names are written to
the audit trail **and returned to the caller** as `rejectedFields`. Answering "saved" while
silently discarding part of the request is the phantom-success shape this codebase already
has a pattern entry for.

## F2 · `proof_pdf()` refuses to render onto a published version's file
`Doc_templates.php` — throws when `version <= publishedVersion`. F1 already breaks the P0
chain upstream; this exists because **a P0 should not depend on one allowlist staying
correct forever**, and because the invariant belongs where the write happens, not only where
the input is filtered. The P0 now has to be defeated twice.

## F3 · `publish()` is one atomic commit
Was two sequential writes: snapshot, then head. A failure between them left the snapshot in
place while the head never advanced — and the retry hit the create-only guard *before*
reaching the head, so the template could publish neither that version nor any later one. A
permanent dead end reachable by an ordinary network blip, with no self-service repair.

Both writes now go in one `commitBatch`, with `exists:false` on the snapshot (create-only
**at the database**, not merely guarded by a prior read) and `exists:true` on the head.
Same pattern `activate()` already used three methods away.

**This is why F3 shipped with F1 rather than later.** A11 observed that the unstripped
`version` field was the *only known escape* from the stranded state, and recommended a
repair tool. But that field was also the primitive behind the P0 — closing the P0 closes the
escape. Rather than build a repair tool for a state that should not exist, **the state is
made unreachable.** Fixing the cause removed the need for the workaround.

## Verification

| | |
|---|---|
| Unit suite | **548 tests · 4 failures · 27 skipped — exactly baseline** (the 4 are pre-existing, another engineer's) |
| New regression tests | 8, incl. shape-level guards that fail if a denylist is ever restored |
| **Tests proven to fail on the OLD code** | ✔ temporarily restored the denylist: 4 of the new tests failed, then passed again once reverted. A test that passes on both is worthless |
| Live end-to-end, real Firestore | create → proof → **publish via atomic commit** → snapshot v1 written, head advanced to v2/published 1 |
| Live attack replay | `save({docType:'study', version:99})` → docType **held**, version **held**, legitimate `name` edit still applied |
| Production adapter checked | `Firestore_rest_client::commitBatch` maps `precondition` onto set ops (`:1044-1047`), not only deletes — verified before trusting the test double |
| Probes cleaned up | created in Harshit Public School, archived/deleted; tenant left as found |

## NOT fixed — deliberately

- **CSS injection / possible SSRF (UR-1).** Still open. The injection is confirmed; whether
  mPDF dereferences it is not, and finding out needs your authorisation.
- **Durability** — frozen PDFs on one instance's local disk. Infrastructure, not code.
- **Legacy `Certificates.php` issuing certificates**, and the missing sidebar link. Both wait
  on your decision about which system is authoritative.
- **Seeding standard templates into every school.** Correctly sequenced *after* these fixes:
  seeding before the P0 was closed would have propagated it across every tenant.

---

# SECOND FIX PASS — 2026-09-04 · UNCOMMITTED / DEPLOY-PENDING

Working the risk register by severity. Each fix carries the finding it closes.

## Security

**F4 · CSS-context injection closed (P1, was the top open security finding).**
`style.fontFamily` and `style.track` were escaped with `htmlspecialchars()` — correct for
HTML text, wrong in a CSS attribute value, which does not escape `:` or `;`. A font family
of `Arial;background:url(http://169.254.169.254/…)` was emitted verbatim into HTML that
**mPDF renders server-side**, making it an outbound request from the production host.
`guardImages()` was written for that exact threat and inspects only `<img src=`.
Both values are now validated by SHAPE (`cssFontFamily()`, `cssLength()`) the way `align()`
and `colour()` beside them already were, and the repeating table's per-column alignment now
uses the existing `align()` whitelist instead of `esc()`. Re-ran the original probe: the
metadata URL and the semicolon are both gone. 5 regression tests.

**UR-12 · client-side XSS of `docTitle`/`name` — audited, NOT a defect.** A8 scoped itself
server-side and handed this off; nobody picked it up. Traced every sink: `modal()` sets
title and sub via `textContent`, `toast()` uses `textContent`, and every modal body,
card, breadcrumb and gallery row applies `esc()`. Recorded as a negative so it is not
re-raised.

**A8-5 · assets served by hash obscurity — already an accepted, documented risk.**
`uploads/.htaccess` denies `.pdf` and every executable extension, disables indexes, and
explains in its own text why assets are still served and what the better answer is. Left
alone rather than churned.

## State machine

**F5 · `activate()` refuses an archived template.** `archive()` refused to archive an ACTIVE
template, so the pair looked symmetrical — but `activate()` never read `status`, so a
template could be archived and then made live again, arriving in a state the gallery treats
as retired. Verified live: refused.

**F6 · Archived templates leave the gallery.** They were still listed, so the one remedy
offered for a published template appeared to do nothing. Verified live: server returns 5,
client shows 4.

**F7 · Archive is reachable (P2 — the dead end).** The delete refusal told the reader to
"archive it instead" and nothing performed it: `srv.archive` had zero callers, so a
published template could never leave the list. There is now an Archive control on every
published row, `manage`-graded, with a dialog that says what survives.

## Correctness

**F8 · Unicode type-id divergence (P2).** PHP's `strtolower()` is byte-only, JavaScript's
`toLowerCase()` is not, so `İstanbul Public School` minted `custom:stanbul_public_school` on
the server and `custom:i_stanbul_public_school` in the client — two identities from one
name, on the id every template and active slot is keyed by. `mb_strtolower(..., 'UTF-8')`
matches the client. Re-executed both runtimes: all five cases now agree. Test pins the
client's actual output.

**F9 · Server-side geometry bounds (P2).** Margins and object x/y/w/h had no range check on
either side — `evalMm()` rejects non-numbers and clamps nothing, and `save()` wrote the
patch through. A negative margin or a 90,000mm object could be saved and published.

## Data loss and honesty

**F10 · A dialog now owns the keyboard (P1).** Only Escape checked the scrim. `⌘A`, `⌘D`,
`⌘Z`, arrow-nudge and **Backspace — which deletes the selected object with no confirmation**
— all stayed live on the canvas under an open modal. Verified live: 16 objects unchanged
after Backspace and ⌘D with a dialog open.

**F11 · Modals announce themselves and take focus.** One of ~15 called `.focus()`; none set
`role="dialog"`. Verified live: `role=dialog`, `aria-modal=true`, primary action focused.

**F12 · A failed load no longer looks like an empty school (P2).** The catch set `S.lib={}`
and repainted the exact copy a genuinely empty school sees, behind a toast that cleared
itself in 3.2s. There is now a persistent, retryable banner that says the load failed and
that nothing was lost — and it clears only on a successful load, never on a timer. The
school-context read, whose own comment said it "must not pass silently" while doing exactly
that, now raises the same banner.

## Performance

**F13 · The list endpoint is projected (P1).** It returned every template's COMPLETE
document, `objects` included, on every hub load — **456 KB measured across 85 real
templates**. It now returns a summary plus geometry-only `shapes` for the thumbnail.
Measured after: **1,227 bytes per template against ~5,400 before, a 4.4× reduction**, with
no template's text, images or merge bindings leaving the server for a screen that draws
grey rectangles. The gallery now also draws each template's REAL geometry rather than its
starter's.

**F14 · Layout work is coalesced onto one animation frame (P1).** `layoutPage()` tears down
and rebuilds every object and forces a reflow per object; it was called on every keystroke
and every mousemove during a drag. The four demonstrably hot call sites — drag/resize,
zoom wheel, colour-picker input, window resize — now request a frame, collapsing a burst
into the single rebuild the browser was going to paint anyway. Call sites that must measure
immediately still call `layoutPage()` directly.

## Verification
- **PHPUnit: 578 tests · 4 failures · 27 skipped — baseline exactly.**
- Live verification of every fix in this pass, in a real browser, against real Firestore.

**F15 · `save()` performs a database-arbitrated compare-and-swap (closes ⚑ CONTESTED C1).**
The doc-comment always promised "the loser gets a conflict; nobody gets a lost edit". The
code read the head, compared `lockVersion` in PHP, then wrote **unconditionally** — so two
saves that both read lockVersion 7 both passed and the second silently overwrote the first,
with no error to either user. On this deployment a Firestore round trip is ~1.7–2.3s, so
the window is wide, not theoretical.

The write now carries a `currentDocument.updateTime` precondition built from the
`__updateTime` the read already returns — the same primitive `activate()` and the
fee-accounting loops use. Firestore, not PHP, decides who won.

**What is proven, and what is not.** Unit tests prove the precondition is *sent*, and that a
refused commit surfaces as `E_CONFLICT` rather than a silent no-op. Live against real
Firestore: an ordinary save still works, a stale save is refused, and the first writer's
data survived. **The live refusal came from the fast-path `lockVersion` check, because
sequential calls cannot race** — the `updateTime` precondition is the backstop for genuine
concurrency, and proving *that* fires needs two truly parallel requests. It remains
`UR-4`, now much narrower: the guard exists and is sent, but has not been observed
arbitrating a real collision.

`create()`'s id-numbering TOCTOU (UR-5) is **not** fixed — it needs a different shape
(a transactional counter or a create-only precondition per candidate id) and is recorded
rather than half-done.

---

# THIRD PASS — 2026-09-05 · closing the bar, not chasing a number

## F16 · `create()` is create-only AT THE DATABASE (closes UR-5)
`exists()` then `set()` were two calls with nothing between them: two concurrent creates
that both read the same max and both found `TPL0086` free would **both write it**, the
second silently overwriting the first school's brand-new template. The doc-comment claimed
the loop "refuses to write over an existing id" — `exists()` cannot deliver that, because
its answer is stale the moment it returns. The write now carries `precondition:
{exists:false}`; when the database refuses, the loop takes the next number and the comment
becomes true. 2 tests, one proving it ADVANCES rather than retrying the same id.

## F17 · The read path now detects a tampered frozen file (closes T0-02)
T0-02 asked: *does anything notice if a published version's PDF stops matching its record?*
The answer was **no**. The snapshot recorded `proofPdfHash` at publication and `version_pdf`
streamed the file on trust, never reading it back.

Two changes. `publish()` now freezes **per-language** digests (`proofPdfPerLanguage`) —
`proofPdfHash` is one digest over every language concatenated and can never verify a single
downloaded file. And `version_pdf` verifies the bytes it is about to serve against that
digest, refusing and logging a mismatch rather than serving it.

This is defence in depth, not a duplicate of F1/F2: those close the *route* the tamper took;
this notices tampering **however it arrived** — a bad backup restore, a compromised host, a
half-written file after a crash. A snapshot published before the digest was frozen is still
served, because refusing it would retire history nobody can re-render.

## Rows converted from human UAT to automated tests

Coverage that is not executed is zero coverage. Each of these was written as "craft a
request / open two sessions and see" — none actually needed a person, and as tests they run
on every commit instead of once in a session somebody has to schedule.

| Row | Now covered by |
|---|---|
| T0-10 · cross-tenant WRITE | `DocConcurrencyTest` — all 6 mutating calls refused against another school's template, document byte-identical after, and the refusal indistinguishable from "not found" |
| T0-11 · save() race | the losing writer is refused, and leaves the stored document untouched |
| T0-12 · create() race | `exists:false` precondition asserted; a lost race advances |
| T0-13 · double publish | refused; the frozen snapshot survives a second attempt |
| T0-14 · double activate | one commit carrying the whole assignment; a lost race changes nothing |
| T0-22 · proof-gate forgery | `DocIntegrityGateTest` — stale proof, forged hash, and no proof all refused |
| T0-25 · unknown object type | throws naming the type; all declared types still render |
| T0-02 · tamper detection | digest frozen per language; combined hash proven insufficient |

**T0-19 · unauthenticated access — EXECUTED, PASSES.** Bare curl, no session, five endpoints
including the file-streaming one: all HTTP 307 to `/admin_login`, zero bytes of template
data or PDF returned.

## Verification
- **605 tests · 4 failures · 27 skipped — baseline.** Document module: **379, all passing.**
- The integrity gate's end-to-end run against a real published file is **still outstanding** —
  the browser session expired mid-probe. The logic is unit-tested; the live confirmation is not.

---

# T0-18 — RBAC EXECUTED · E4 · 2026-09-05

The largest hole in the evidence, closed. Every prior session ran as `manage`, so all three
grades were unverified at runtime.

## What had to happen first — a finding in its own right

Neither test account held what it was believed to hold. **Both resolved to `manage`**, and
the reason is `rbac_helper.php:169` — `return $levels[$module] ?? 'manage'` — so **a module
held with no explicit level silently resolves to the HIGHEST grade.** An audit of the live
school found **all 6 roles holding `Certificates` had no level set**: Admin, Principal, Vice
Principal, Front Office, **Teacher**, UAT tester — all `manage`.

**A Teacher could therefore publish and activate statutory certificate templates**, deciding
what every print point in the school resolves to. The codebase had predicted exactly this:
`Rbac_levels_backfill.php:16-17` warns a Teacher holding `Certificates` "would gain
delete/moderate/issue power the legacy name-gates deny them."

The remedy already existed and had never been run. `Rbac_levels_backfill` — dry-run by
default, idempotent, additive, reversible, writing only `permissionLevels`. Dry-run reviewed,
then committed **with the operator's explicit authorisation**: 14 roles levelled on
`SCH_B56BB9A401`. Verified after: 6 of 6 roles now carry an explicit level.

This is a cross-module fix, not a Documents one. It closes the same escalation on Stories,
Red Flags, SIS and every other module on this tenant.

## edit grade — STA0011 (Teacher)

| Check | Result |
|---|---|
| Server-reported grade | `edit` · canEdit true, canManage **false** |
| Publish / Make live / Deactivate / Archive / Delete controls | **all hidden** |
| Those five called **directly, buttons bypassed** | **all refused by the server** |
| Save · Undo · Redo · History · Proof PDF | present |
| Open a template · 15 objects · 22 page elements | works |
| Edit → history recorded → save | **saved, lockVersion → 41** |

## view grade — STA0025 (UAT tester)

| Check | Result |
|---|---|
| Server-reported grade | `view` · canEdit **false**, canManage **false** |
| Templates readable | **84** |
| Top bar | **"Read only"** chip + History — no Save, no Undo, no Proof, no Publish |
| New document / blank canvas | hidden |
| Gallery row actions | **Open only** |
| **Can open and read a template** | **✔ 15 objects, 22 page elements** |
| Canvas guards | all four refused, each naming the grade |
| **Undo backstop** | **no history recorded at all** |
| Objects after four attempted mutations | **15, unchanged** |
| Server, all client guards bypassed | `save` · `create` · `duplicate` · `proof_pdf` · `seed_standard` · `publish` · `delete` — **all refused** |
| `get_template` · `get_versions` | **allowed** — a read grade genuinely reads |

## Why this evidence counts

The client guards were **bypassed in every probe** — each action was invoked directly, so the
refusal came from `_remap()` on the server, which is the only real boundary. Hiding a button
is presentation; this tested enforcement.

It also confirms the `design`-grade fix from earlier in this session. That endpoint was
graded `edit`, so a viewer could not open a template at all — a read grade that read nothing.
Both grades now open and render correctly.

---

# FOURTH PASS — 2026-09-05

## UR-1 CLOSED · mPDF **does** dereference CSS `url()` — measured

The open question was whether the CSS injection could become server-side SSRF. It can.

Established with a **purely local probe**, so no outbound request from production and no
authorisation needed: render the same div twice, `background:url()` pointing once at a file
that exists and once at one that does not. Identical markup in every other respect. Outputs
differed by **866 bytes** — mPDF fetched and embedded the real file. Had it ignored CSS
urls, both would have been byte-identical.

The injection vector was already closed earlier in this session (`cssFontFamily()`,
`cssLength()`). This does not reopen anything — it establishes that **the fix was necessary,
not precautionary**, and that the sanitisers are load-bearing rather than defence in depth.
Recorded in `DocSecurityTest` beside the tests that depend on it.

## F18 · Asset pixel cap (closes T0-28)

`upload_asset` capped bytes at 4 MB, sniffed MIME, and checked `getimagesize` — and **nothing
bounded the decompressed size.**

Measured, not estimated. A 12000x12000 PNG of one flat colour:

| | |
|---|---|
| on disk | **17,642 bytes** — inside the 4 MB cap |
| finfo MIME | `image/png` — on the allow-list |
| getimagesize | readable, 12000 x 12000 |
| decompressed | **549 MB** |
| `Doc_renderer` memory ceiling | **96 MB** |

Any `edit`-grade user could upload it and kill the PHP worker on every render that touched
it. `ASSET_MAX_PIXELS = 40,000,000` now rejects it before the file is written — verified the
guard sits at line 46 of the method, ahead of `move_uploaded_file` at line 62.

The cap costs legitimate users nothing: A4 at 300 dpi is 8.7 MP, A4 at **600** dpi is 34.8 MP,
a crest is 1.4 MP. The bomb is 144 MP. The refusal names the real constraint and says
plainly that file size is not it.

## Verification
- **608 tests · 4 failures · 27 skipped — baseline.**
- T0-28 and UR-1 both resolved without touching production.

---

# T0-20 — THE P0, RE-RUN LIVE AS AN EDIT-GRADE USER · E4 · 2026-09-05

Run against `SCH_B56BB9A401_TPL0001` — the school's **live active transfer certificate**,
head v7, published v6, active v6. The exact original attack chain, by the grade that could
actually reach it: an editor, not a viewer.

| Step | Result |
|---|---|
| Frozen v6 PDF before | 1,003,107 bytes · FNV `2623fdca` |
| `save({version: 6})` | accepted, **field dropped** — head stayed at v7 |
| `save({docType: 'study'})` | accepted, **field dropped** — still `transfer_certificate` |
| `proof_pdf()` | ran, and rendered at **v7**, not v6 |
| Frozen v6 PDF after | 1,003,107 bytes · FNV `2623fdca` — **byte-identical** |
| Legitimate `name` in the same patch | written normally |

**The P0 is dead.** The primitive it depended on — moving the head's version back onto an
already-published number — is gone, so `proof_pdf` can no longer be aimed at a frozen file.

## A defect this run found in my own fix

My first probe labelled the save "*** allowlist FAILED ***" because it did not throw. The
**probe** was wrong — the allowlist is deliberately non-fatal, so a client that round-trips a
whole template object is not broken by it. The version and docType both held.

But chasing that turned up a real gap. `Doc_template_service::save()` returns the names of
the fields it dropped; the **controller returned only `lockVersion`**, so the report died at
the boundary. The client was answered "saved" with no hint that part of its payload had been
discarded — the phantom-success shape this codebase has a pattern entry for, and no better
for the discarding being deliberate.

Fixed and verified live: the same call now returns `rejectedFields: ["version", "docType"]`
while still succeeding, still advancing `lockVersion`, and still writing the legitimate
`name`.

**The service was correct and the layer above it silently undid the honesty.** Static review
missed it because each layer reads correctly on its own; only an end-to-end call shows the
report vanishing.

---

# T1 CORE JOURNEYS — a harness, and 17 executed · E3 · 2026-09-05

T1 is 56 rows of "a person does the everyday thing and it works". Most do not need a
person — they need a browser with a real session. `tests/doctemplates/_zxdt_journeys.js`
is that, and it is deliberately different from the existing e2e harness: that one drives the
CLIENT STATE MACHINE with the server stubbed; this makes **real calls against real Firestore
in a real session**, so it exercises latency, tenant scoping, the capability gate, and every
layer boundary where a value can quietly be dropped — which is where the last two defects
were found.

**17 journeys, 17 passed, at `edit` grade.** Create → save round-trip → stale-lock refusal →
non-editable field dropped *and reported* → proof with a content hash → custom type minting →
state-gate refusal → fee-receipt repeating table and its anchor chain → gallery filter →
version history ordering → a published PDF that is a real PDF → reopening a template and
getting back what was stored → duplicate independence → presence.

## The harness's own first-run defect, and the fix

It created three templates and **could not delete them**: `create` needs `edit`, `delete` and
`archive` need `manage`. Three probes were left in a live school for somebody else to find
and wonder about. **A harness that dirties a real tenant is not a harness, it is a mess with
a progress bar.**

Cleaned up directly — with guards that refused anything older than an hour or carrying a
frozen version, which is why two passes were needed: the first correctly refused a
seeded template it could not prove was mine. Population back to 86, nothing else touched.

The harness now refuses to run write journeys when it cannot clean up, offering a read-only
run instead, and a left-behind probe is reported as `CLEANUP_OK: *** N PROBE(S) LEFT IN A
REAL SCHOOL ***` rather than buried in a list.

## Also verified while chasing that

`get_templates` returns the projection correctly at **1,365 bytes per template** against a
measured ~5,400 before — but see `_live-state.md` L15: the **Firestore read** still takes
3–17 s for 86 documents, and the projection does not touch that.

---

# THE FULL LIFECYCLE, WALKED · 27/27 · E4 · 2026-09-05

`STA0025` was granted `manage`, which unlocked the ten journeys nobody had ever executed
end to end. **27 journeys, 27 passed.**

| Journey | Result |
|---|---|
| J-20 · publish freezes a version and opens the next draft | published v1, head → v2, status stays `draft` |
| J-21 · publishing without a fresh proof | **refused** |
| J-22 · the frozen version carries its own PDF digest | present |
| J-23 · activate makes one version live | active = published |
| J-24 · **activating displaces the incumbent** | **exactly 1 active for the type** — the module's central invariant, verified against live data |
| J-25 · deactivate | type left with nothing live, failing closed by design |
| J-26 · deleting a published template | **refused, and names archiving as the remedy** |
| J-27 · archive is reachable and retires it | `status = archived` |
| J-28 · reactivating an archived template | **refused** |
| J-29 · a never-published draft | deletes cleanly |

Every one of these was previously a UAT row plus a unit test against a store double. They
are now confirmed against real Firestore, in a real session, at the grade that performs them.

## The harness caught its own defect, loudly — which was the point

The cleanup reporting added an hour earlier did exactly what it was built for:

```
CLEANUP_OK: *** 1 PROBE(S) LEFT IN A REAL SCHOOL ***
```

Cause: **J-27 deliberately archives a template, and archive is terminal.** The cleanup's
fallback then tried to archive it a second time, got `illegal transition 'archived' →
'archived'`, and reported an orphan for a template that had in fact been retired correctly.
The cleanup now asks what state a template is in before assuming it failed.

Had that reporting been a footnote rather than a top-level field, this would have been
missed — and the next run would have quietly left real debris.

## One probe remains, deliberately

`SCH_B56BB9A401_TPL0090` — "JOURNEY PROBE — safe to delete 2", published v1, **archived**.
It is hidden from the gallery and harmless.

It is **not** deleted because deleting a published template means deleting its version
snapshot, and that is precisely what the product refuses to do — the snapshot is the record
of what a certificate said. That rule does not really apply to a test probe that issued
nothing, but bypassing it needs the service account and a deliberate decision, so it is the
operator's call rather than mine. Population: 87.
