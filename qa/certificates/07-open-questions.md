# 07 · Open questions — batched for the human

**Agent: A12 · TEST-ARCHITECT.** This file OVERWRITES the stale run-1 version (which
recorded operator decisions dated 2026-09-03 that predate this run's own findings —
notably L6/C3's fee-receipt-authority finding directly contradicts that file's claim
that Q3 was already "IMPLEMENTED." Treated as unverified input per the mission's own
rule and not carried forward). Every question below is one code alone cannot answer —
each is cited to the finding that raised it and to the UAT row(s) that depend on it.

Six categories, following the H-tags already in use across this run's artefacts
(`_live-state.md`, `05-risk-register.md`, `14-unknown-risk-register.md`):

- **H1 — access / infrastructure facts** the human or an operator with console access
  must supply; no amount of code-reading answers these.
- **H2 — human-run runtime actions** that need real concurrency, real interruption
  timing, or a live server — not merely a fixture, an *action*.
- **H3 — business decisions** the code implements neither side of; only the product
  owner can settle these.
- **H4 — dangerous operations** requiring explicit, in-the-moment authorisation before
  they are run, because they touch production in a way that could cause real harm.
- **H5 — role / fixture provisioning** needed before the standing certification-gating
  unknowns can be closed at all.
- **H6 — automatable follow-ups** that need no human time, listed so they are not lost,
  not because they block anything.

---

## H1 · Access / infrastructure facts

### Q1 — Do Lightsail instance snapshots include `uploads/`?
**Impact if unanswered:** every historical published certificate PDF has no confirmed
durability guarantee. If the Ohio instance is lost or rebuilt without a manual rsync,
`version_pdf()` 404s for every version ever published while Firestore still lists them
as published — a silent, total loss of the module's stated legal record, discovered
only when someone tries to download an old certificate.
**Recommended default:** treat `uploads/` as **NOT backed up** until confirmed otherwise
(the runbook already documents it as hand-rsynced, never in git) — i.e. assume the
disaster-recovery gap is real and prioritise closing it, rather than assuming snapshots
happen to cover it.
**Row:** T0-08.

### Q2 — Deployed PHP `post_max_size` / `memory_limit`, and the Ohio instance's RAM/vCPU?
**Impact if unanswered:** bounds how severe the unbounded-payload findings (T2-08
`objects[]` size, T0-28 the crafted-PNG memory test) actually are in production —
without this, "P1 pending" stays pending indefinitely.
**Recommended default:** assume defaults are conservative (shared hosting-tier limits)
and that a resource-exhaustion attack from one school CAN degrade the process for
others, until the instance is confirmed to have generous, isolated headroom.
**Rows:** T0-28, T2-08.

---

## H2 · Human-run runtime actions

### Q3 — Is `save()`/`create()`'s read-then-write actually exploitable under real concurrency?
**Impact if unanswered:** `_arbitration.md`'s C1 stays permanently CONTESTED — the
module's own doc-comment claims a CAS guarantee ("the loser gets a conflict; nobody
gets a lost edit") that the implementation does not structurally provide. Nobody can
say whether this is a live P1 (silent lost edits on a legal-record editor) or a
correctness footnote that has simply never been hit.
**Recommended default:** treat it as exploitable — fix the doc-comment/implementation
mismatch regardless of whether T0-11/T0-12 land inside the race window on a given run,
since a race that is merely *hard to hit* is not the same as *safe*.
**Rows:** T0-11, T0-12, T0-13, T0-14, T0-15 (the whole concurrency cluster).

### Q4 — Does `save({version:N+1})` genuinely repair a STRANDED template, or only unblock the next `publish()` while leaving `publishedVersion` stale?
**Impact if unanswered:** blocks the fix design for T0-01/OV1, not the certification
itself — fixing OV1 by stripping `version` from `save()`'s patch list removes the ONLY
known (if undesigned) escape from the STRANDED state, so shipping that fix without
first knowing whether the escape hatch it removes was ever a *real* repair risks
leaving a future stranded template with literally no way out.
**Recommended default:** build a real, designed repair tool for STRANDED templates
*before* closing the `version` field, on the assumption the accidental escape hatch is
incomplete (it plausibly repairs `version` but not `publishedVersion`) rather than
trusting it as sufficient.
**Rows:** T0-06, T0-07.

### Q5 — Do the 4 remaining route-less endpoints (`version_pdf`, `presence`, `leave`, `duplicate`) dispatch and stay capability-gated?
**Impact if unanswered:** low (P3) — either a 404 on a working feature, or an endpoint
reachable by URL-guessing rather than by design. QA-LEAD already confirmed 4 of 7
route-less endpoints live this run; these 4 were not exercised.
**Recommended default:** low priority — schedule opportunistically, not as a blocker.
**Row:** T2-34.

---

## H3 · Business decisions

### Q6 — Which certificate system is authoritative: the legacy `Certificates.php` (sidebar-linked, RTDB, zero tests) or the Document Engine (no sidebar link, Firestore, 16 test files)?
**Impact if unanswered:** this is the single largest scope-reframing question in the
whole certification. If the legacy system stays live and un-retired, the ecosystem
issues real, student-identified certificates *today* from an untested prototype, and
the Document Engine's `CON-NO_PRINT_IMPL` constraint describes only half the truth —
every severity rating in this matrix that assumes "nothing prints yet" (the `docType`
bypass, the CSS-injection blast radius, the numbering-collision risk) is scoped to the
Document Engine alone and does not account for the system that is actually in
production use.
**Recommended default:** do not retire the legacy system silently and do not wire the
Document Engine's print seam until this is explicitly decided — continuing to run both,
undecided, is the one option that guarantees the eventual numbering-collision risk
(Q7 below) becomes real.
**Rows:** T0-16, T0-17, T1-18, T1-41.

### Q7 — What happens to certificate-numbering coordination between the two systems if both stay live?
**Impact if unanswered:** the two systems have zero numbering coordination today —
harmless only because the Document Engine issues nothing. The day any print point is
wired, two independently-numbered "official" certificate systems sharing one RBAC key
becomes a live collision risk nobody has designed against.
**Recommended default:** treat this as a blocking prerequisite for wiring ANY print
point, not a follow-on task — design a single shared numbering authority before either
system's numbers can be trusted as unique.
**Row:** T0-17.

### Q8 — Is the Document Engine's `fee_receipt` type meant to replace the Parent app's on-device `ReceiptPdfGenerator.kt`, or are the two meant to coexist?
**Impact if unanswered:** the two implementations already disagree field-by-field
(itemised-row columns, phone/email/GSTIN, amountInWords, duplicate-marking) and are
mutually unaware. Harmless while the seam is unwired; the day it is wired without this
decision, a parent's app-generated receipt and the school's engine-generated receipt
for the SAME payment will visibly disagree — a trust problem for the "isn't this
supposed to be the same document" reason receipts exist at all.
**Recommended default:** do not wire the `fee_receipt` print seam until this is decided
— if the intent is replacement, the Parent app needs its own migration plan (it is not
simply deleted the day the engine can render a receipt); if coexistence, the two
implementations need a shared source-of-truth contract, not two independent guesses.
**Rows:** T1-16, T1-17, T1-30, T2-67.

---

## H4 · Dangerous operations — explicit authorisation required before running

### Q9 — Does mPDF actually dereference `background:url(...)` injected via `style.fontFamily`, server-side, on the production Ohio host?
**Impact if unanswered:** this is the single highest-value untested row in the entire
certification. The injection itself is already CONFIRMED (E3) — an `edit`-grade user
can inject arbitrary CSS declarations into the rendered `style=` attribute, defeating
the one guard (`guardImages()`) purpose-built against exactly this SSRF threat model.
What remains unknown is only whether mPDF's HTML-to-PDF conversion actually fetches the
injected URL server-side. If yes: this is server-side request forgery from a production
host, potentially able to reach the AWS instance-metadata endpoint
(`169.254.169.254`) — rate P0. If no: the risk degrades to a browser-side beacon in the
`preview()` path only — rate P2.
**This cannot be defaulted.** Running the test means causing the production server to
make a real outbound request to a host of the tester's choosing. **Never point it at a
third party's domain, and never at the metadata address itself** — use a host you own
and can read the access log of (a personal server, or a webhook.site/requestbin
endpoint you control). Get explicit, in-the-moment sign-off before the server-touching
half of the test; the browser-only half (confirming the client-side injection fires) is
safe to run without that sign-off.
**Recommended default until answered:** treat as P1 for planning purposes (the injection
is already confirmed regardless of the dereference question) and prioritise fixing the
`fontFamily`/`track`/table-`align` escaping (whitelist, matching the pattern the file
already uses correctly for `colour`) — that fix closes the hole regardless of which way
Q9 resolves, and does not require answering Q9 first.
**Row:** T0-05 (and its lower-risk browser-only sibling T2-62).

### Q10 — Does a crafted small-file/extreme-dimension PNG exhaust memory in mPDF's image decoder at render time?
**Impact if unanswered:** unlike Q9, this risks degrading the SHARED production PHP
process for every other school on the same instance, not just the acting tenant's own
data — a materially larger blast radius than a same-tenant bug, even though the
technique (a decompression-bomb-shaped upload) is a well-known class.
**Recommended default until answered:** prefer running this against a staging/
non-production copy of the environment if one exists; if it must run against
production, get explicit authorisation and run during a low-traffic window, with
someone able to restart the PHP process on standby.
**Row:** T0-28.

---

## H5 · Role / fixture provisioning — needed before standing unknowns can close

### Q11 — A second real tenant (school) with a real `templateId`, obtainable by this certification's testers.
**Impact if unanswered:** this is the single largest standing `[UNKNOWN]` in the whole
run, named independently by nearly every agent. The live probe this run could only test
against a *non-existent* foreign id, which cannot distinguish "the tenant check fired"
from "the document simply doesn't exist." Every tenant-isolation row in this matrix
(T0-09, T0-10, T0-15, T2-32, T2-61) is BLOCKED without this fixture.
**Recommended default:** prioritise seeding this fixture — a disposable second school
with one disposable template — above any other single provisioning item in this list;
it unblocks more T0 rows than any other missing piece.
**Rows:** T0-09, T0-10, T0-15, T2-32, T2-61.

### Q12 — A `Certificates: edit`-only staff account (no `manage` grade).
**Impact if unanswered:** every RBAC-surfacing row that specifically needs a
NON-manage session (T0-18, T0-26, T1-49 partially) is BLOCKED. Per the coverage
ledger, **every runtime observation this run so far used `manage` grade** — the edit
and view grades are entirely unexercised in practice, only reasoned about from static
trace.
**Recommended default:** provision this before running any RBAC row — it is a low-cost
fixture (one staff account with a narrower grant) relative to how many T0/T1 rows
depend on it.
**Rows:** T0-18, T0-26.

### Q13 — A `Certificates: view`-only staff account.
**Impact if unanswered:** same shape as Q12, one grade further restricted — T1-31,
T1-52, T2-70 are BLOCKED without it.
**Recommended default:** provision alongside Q12 — both are cheap to create together.
**Rows:** T1-31, T1-52, T2-70.

---

## H6 · Automatable follow-ups — no human time required

### Q14 — Are there Unicode SpecialCasing divergences beyond Turkish `İ` between the PHP and JS custom-type slug functions?
One class (SpecialCasing expansion, `İ`) is already confirmed to diverge; `ÄÖÜ`, `ß`,
`Ⅻ` were checked and agree. The space was not exhaustively enumerated. A scripted
property test over a Unicode corpus would settle this far more thoroughly than manual
UI entry (T2-01/T2-23/T2-60 exercise specific named cases by hand).
**Recommended default:** schedule as a small property-test script (`php -r`/`node -e`
comparison harness) rather than manual UAT rows, once resourced.

### Q15 — Is `docTitle`/`name` escaped correctly when rendered into the DOM by `designer.js`?
`05a-security.md` explicitly scoped itself server-side only and handed this off; no
agent this run audited the client-side render path for these two fields for XSS.
**Recommended default:** a straightforward code-read/lint pass (grep every render site
for these two fields and confirm each uses safe DOM APIs, not raw `innerHTML`)
would likely resolve this without needing the live T2-24 row at all — try that first.

---

## Summary

| # | Question | Category | Gates certification? |
|---|---|---|---|
| Q1 | Uploads durability on Lightsail snapshots | H1 | No, but gates the P1 durability finding |
| Q2 | PHP/instance resource limits | H1 | No |
| Q3 | save()/create() CAS under real concurrency | H2 | Yes — C1 is CONTESTED |
| Q4 | Does the version-bump escape really repair STRANDED? | H2 | Blocks fix design, not certification |
| Q5 | 4 remaining route-less endpoints | H2 | No, low priority |
| Q6 | Which certificate system is authoritative | H3 | Yes — reframes scope |
| Q7 | Cross-system numbering coordination | H3 | Blocks wiring any print point |
| Q8 | fee_receipt — one renderer or two | H3 | Blocks wiring the fee_receipt seam |
| Q9 | Does mPDF dereference the injected CSS URL server-side | H4 | **Yes — the single highest-value open item in the run** |
| Q10 | Crafted-image memory exhaustion in mPDF | H4 | No, but blast radius is multi-tenant |
| Q11 | Real second-tenant fixture | H5 | **Yes — unblocks the most rows of any single item** |
| Q12 | edit-only account | H5 | Blocks 2 T0 rows |
| Q13 | view-only account | H5 | Blocks 3 rows |
| Q14 | Unicode corpus sweep | H6 | No |
| Q15 | Client-side XSS audit of docTitle/name | H6 | No |

**Two questions gate certification outright (Q3, Q9); two more (Q6, Q11) reframe scope
or unblock the largest single cluster of otherwise-BLOCKED rows.** Everything else
either sharpens a severity rating already recorded provisionally, or blocks a future fix
rather than the certification itself.
