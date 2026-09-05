# CERTIFICATION REPORT — Documents (Certificates) · 2026-09-05

## Verdict

**🔵 CERTIFIED WITH MONITORING — for the Documents module only**, with the named risks below.
This is proposed, not granted: only the operator accepts risk.

## Coverage

| Tier | Discharged | How |
|---|---|---|
| **T0** certification blockers | **27 / 30** | 14 executed live · 7 automated · 4 moot after the legacy retirement · 2 structurally closed |
| **T1** core journeys | **27 / 56** | real-session journeys against real Firestore, all passing, incl. the full lifecycle |
| **T2** negative & boundary | **44 / 72** | 32 automated · 11 answered earlier · 1 moot |
| **T3** surface & polish | **6 / 20** | the factual ones; the rest need human eyes and are honestly left open |
| | **104 / 178** | |

**Automated suite: 643 tests · 4 failures · 27 skipped — the standing baseline, unchanged
through roughly 45 changes.** Up from 524 at the start of this engagement: **+119 tests**,
of which 417 cover this module.

## What was fixed

**P0** — a published version's frozen PDF was overwritable in place by an `edit`-grade user,
no race, no `manage` grade. Re-run afterwards against the school's live active transfer
certificate: byte-identical.

**P1** — CSS injection reaching mPDF, which was proven to dereference `url()`, making it a
real server-side SSRF; `publish()` non-atomic and able to strand a template permanently;
compare-and-swap claimed in comments but not implemented in `save()` or `create()`.

**P2** — a state-gate bypass (reproduced live); an unreachable `archive` leaving published
templates in the gallery forever; a decompression bomb (17 KB → 549 MB against a 96 MB
ceiling); destructive keyboard shortcuts live under an open dialog; a read failure rendered
as an empty school; PHP and JS minting different type ids for one name; unbounded geometry
and type size.

**Also** — the legacy RTDB certificate system retired (692 lines, zero tests, zero
certificates ever issued across 8 schools); schools now seeded with their standard
documents; the module given a name and a place in the navigation it never had.

## Named risks requiring monitoring

1. **The hub takes 3–17 s to load** on a school with 86 templates. The payload projection
   (456 KB → 117 KB) does not touch the Firestore read, which is the real cost. Watch it as
   template counts grow; the fix is a server-side `select` or pagination.
2. **`create()` reads every template head** the school has ever created, and 94% of the
   current population is never-published harness debris with no bulk cleanup.
3. **Proof PDFs and uploaded assets are never deleted.** Disk grows monotonically.
4. **`templateSessions` presence rows are never cleaned up** (UR-14).
5. **The frozen PDFs live only on one Lightsail instance's local disk.** Whether snapshots
   include `uploads/` is unanswered (T0-08) and is the one open item that could lose data.

## Outside this module, and larger than it

**The live RTDB rules are `.read/.write: "auth != null"` with no `Schools` block.** On a
multi-tenant project that is satisfied by any parent of any school, granting read **and
write** across all 8 schools: fees and accounting, `Users/{Admin,Parents,Devices}`, 108
phone numbers, 108 exit records. Both shipped apps already read these paths.

This is **not** a Documents problem — Documents is pure Firestore — and retiring the
certificate system did not touch it. A scoped replacement is drafted
(`firebase-rules/database.rules.PROPOSED.json`) with the key-matching blocker resolved from
production evidence and every app path enumerated. **Not deployed**: RTDB has no simulator
here, and a denied read surfaces in these apps as an empty list rather than an error.

## What is NOT certified

- **74 UAT rows unexecuted**, chiefly T1 UI journeys and T3 judgement.
- **Is the interface understandable?** Untested. A machine can prove the lifecycle is
  correct; it cannot tell whether a clerk grasps "published but not active".
- **T0-08** (snapshot durability), **T0-15** (`save_block` race, currently UI-unreachable),
  **T0-29** (Firestore boundary beneath PHP) — need consoles or two sessions.
- **Nothing issues from this build.** `CON-NO_PRINT_IMPL` holds; the print seam is declared
  and inert. Issuance is designed but unbuilt: `Doc_resolver::activeVersion()` and
  `Sis::_get_tc_number()`'s atomic claim-doc numbering both exist and have never been joined.

## Confidence

| | | |
|---|---|---|
| Data integrity | **HIGH** | P0 re-run live; snapshot immutability proven three ways and reproduced |
| Tenant isolation | **HIGH (read)** · MEDIUM (write) | reads confirmed against a real second tenant; writes traced to one shared gate, not observed refusing live |
| Authorization | **HIGH** | all three grades exercised live with client guards bypassed |
| Concurrency | **MEDIUM** | database-arbitrated and unit-tested; never observed under genuine collision |
| Performance at scale | **LOW** | measured at 86 templates; never run at 860 |
| Usability | **UNTESTED** | outside what this pass could establish |
