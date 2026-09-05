# STOP GATE A — Certificates / Document Engine · 2026-09-04

MODULE: Certificates / Document Engine   SURFACES: Admin Web (only). Teacher + Parent apps
confirmed to contain **zero** certificate code, independently, by two agents.

FILES MAPPED: 27 public endpoints · 9 libraries · 4 owned Firestore collections + 2 read ·
4 rules blocks · 7 indexes · 0 Cloud Functions · 8 print points (0 wired) · 16 PHPUnit files ·
1 legacy controller (692 lines, 17 routes, **0 tests**)

AGENTS: **12 spawned, 0 skipped.** A3 ran at reduced depth (absence-proving only), A7 was
re-aimed at internal divergences. A11 hit a session rate limit **after** writing both
deliverables; its verdicts were recovered from disk and its central finding independently
re-verified by QA-LEAD.

EVIDENCE CEILING: **E2**, except where explicitly marked. Nothing below is runtime-verified
UAT evidence. No row is PASS. Nothing is certified. Nothing has been fixed.

---

## TOP RISKS

1. **P0 · A published version's frozen certificate PDF can be overwritten in place by an
   `edit`-grade user.** `save({version:N})` + `proof_pdf()`. No race, no `manage` grade, two
   ordinary POSTs. `Doc_template_service.php:335-339` · `Doc_templates.php:839,857-860`
2. **P1 · CSS injection into the render `style` attribute → possible server-side SSRF.**
   Injection CONFIRMED (E3). `Doc_serializer.php:474,477,858,872`; the guard that exists for
   exactly this, `Doc_renderer::guardImages()`, matches only `<img src=` (`:391`)
3. **P1 · The `docType` state-gate bypass survives publish and freezes permanently.**
   `contentHash()` omits `docType`; `publish()` never calls the validator
4. **P1 · `publish()` is non-atomic** — partial failure strands a template unrecoverably
   `Doc_template_service.php:551,563,517-522`
5. **P1 · The frozen certificate PDFs exist only on one Lightsail instance's local disk**
   (`uploads/`, not in git, hand-rsynced on migration)
6. **P1 · The legacy `Certificates.php` issues real certificates today**, from the sidebar,
   untested, sharing the `Certificates` RBAC key with the engine

## IS/SHOULD DISCREPANCIES

- `save()`/`create()` **claim** compare-and-swap in their own comments; both are
  read-then-write with no Firestore precondition, while `activate()` does it correctly
- `create()`'s doc-comment claims its numbering "refuses to write over an existing id";
  `exists()` and `set()` are two unguarded calls
- The delete refusal instructs the user to "archive it instead"; **no UI performs archive**
- The client's `hydrateFromServer` comment says a failure "must not pass silently", then
  passes it silently (`console.warn`) — author: QA-LEAD, 2026-09-03
- `firestore.rules` models `status=='published'` as reachable; no code path ever writes it

## PARITY BREAKS

- Client and server mint **different custom-type ids for the same name** — EXECUTED (E3):
  `İstanbul Public School` → PHP `custom:stanbul_public_school`, JS `custom:i_stanbul_public_school`
- Two independent fee-receipt implementations (Parent app native PDF vs engine mPDF),
  different columns, different totals, different localisation policy
- `fee_receipt.statutory` explicit `false` in JS, absent in PHP — a live drift in a blind
  spot of `DocContractParityTest`

## ⚑ CONTESTED — code cannot settle these; test first

- C1 · Is the read-then-write lock exploitable under real concurrency?
- C2 · **Which certificate system is authoritative?** The engine issues nothing by
  constraint; the legacy one issues certificates today and owns the navigation
- C3 · Is the app's client-side fee receipt meant to be replaced by the engine's type?

## COVERAGE GAPS (named)

Legacy controller mapped but never modelled or attacked · only **manage** grade ever
exercised, so every permission row is unexecuted · **no second tenant obtainable**, so zero
cross-tenant evidence exists · production infrastructure unread · mPDF internals unread ·
client-side DOM escaping of `docTitle`/`name` handed off by A8 and picked up by nobody

## BLOCKERS FOR YOU

15 questions batched in `07-open-questions.md`. **Two gate certification:**
- **Q9 [H4]** — may I test whether mPDF dereferences an injected CSS url? Requires the
  production server to make an outbound request **to a host you control**. Without it, the
  top security finding stays P1-or-P0-unknown.
- **Q11 [H5]** — a real template id from a second school. Unblocks the most rows in the
  matrix and is the only way to prove tenant isolation.
Also: **Q12/Q13** — an `edit`-only and a `view`-only account. Without them, every RBAC row
is BLOCKED.

## UAT READY

**T0 = 30 · T1 = 56 · T2 = 72 · T3 = 20 · total 178 rows, every one `NOT TESTED`.**
Validated: 18 columns, no duplicate ids, no malformed rows, every T0 row names the
invariant it defends, nothing pre-filled.

**NEXT HUMAN ACTION: execute T0 in `/qa/certificates/06-uat-matrix.csv`. Start at T0-01.**
If T0 fails, the rest waits.
