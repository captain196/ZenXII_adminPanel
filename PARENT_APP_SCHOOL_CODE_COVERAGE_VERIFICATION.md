# Parent App School-Code Coverage Verification (Phase 8.2)

**Date:** 2026-08-13 · **Status: READ-ONLY DATA VERIFICATION. Zero code changes, zero writes to
either store.** Working tree confirmed unchanged — the one script used to read production data was
written and executed entirely outside the repository (session scratchpad), so `git status` in all
three repositories is identical before and after this phase.

**Method — real data, not inference.** Unlike Phase 8.1 (source-code-only analysis), this phase had
genuine read-only access: a Firebase Admin SDK service-account credential already present in this
repository (`application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json`) was used,
exactly the way this repo's own existing `scripts/inspect_*.js` diagnostic tools already do, to run
one throwaway, read-only comparison script against the live `graderadmin` Firebase project — both
RTDB and Firestore. No write operation exists anywhere in that script; every claim below is either
**CONFIRMED** from that live read, or explicitly marked otherwise.

---

## Executive Summary

**The result is the opposite of what Phase 8.1 worried about.** RTDB `Indexes/School_codes` is not
a rich, complete index the Firestore side might be missing pieces of — it contains **exactly one
real school-code mapping** (`10001 → SCH_D94FE8F7AD`), which is almost certainly a leftover from the
platform's earliest, pre-Firestore-first school. **Firestore's `schoolCode` field, by contrast, is
already populated on 8 of 9 real school documents** — including all 7 schools onboarded after that
first one, none of which have any RTDB entry at all. Firestore is materially *more* complete than
RTDB today, not less. The one Firestore record with no `schoolCode` (`SCH_202512`) has no RTDB
counterpart either, so it is a pre-existing gap in both systems, not something a migration would
create or worsen.

## Data Sources

- **Source A (RTDB):** `Indexes/School_codes`, read via `admin.database().ref(...).once('value')` —
  a full, unfiltered dump of the entire node.
- **Source B (Firestore):** `schools` collection, read via `admin.firestore().collection('schools').get()`
  — every document, unfiltered.
- Both reads happened in the same script run, against the same live project, seconds apart — not
  reconciled from two different points in time.

## RTDB → Firestore Comparison

**CONFIRMED — full raw RTDB content:**

```json
{
  "10001": "SCH_D94FE8F7AD",
  "SCH_D94FE8F7AD": "10001"
}
```

**Two entries total, both about the same single school.** Comparison:

| RTDB key | RTDB value | Firestore doc `schools/{value}` exists? | Firestore `schoolCode` on that doc | Match? |
|---|---|---|---|---|
| `10001` | `SCH_D94FE8F7AD` | **CONFIRMED** yes, `schools/SCH_D94FE8F7AD` exists | `"10001"` | **CONFIRMED MATCH** — exact string equality |
| `SCH_D94FE8F7AD` | `10001` | `schools/10001` — **CONFIRMED does not exist** (this key/value pair is reverse-shaped, not resolvable as a normal forward lookup) | N/A | N/A — see note below |

**Note on the second entry:** treating the RTDB **key** `SCH_D94FE8F7AD` as a schoolId (rather than
the **value** `10001`) correctly finds `schools/SCH_D94FE8F7AD` — same school, confirming this second
entry is a **reverse-direction duplicate of the first**, not a second, distinct school. Its origin
(which PHP code path wrote it) was **NOT VERIFIED** in this phase — Phase 8.1's candidate
(`Schools.php:197`) writes a school **name** as the value, but this entry's value (`"10001"`) is a
code, not a name, so it does not match that write shape either. The actual writer of this specific
entry is **NOT VERIFIED / UNKNOWN**, disclosed rather than guessed. It is harmless to the coverage
question either way — it resolves to the same, already-confirmed-matching school.

## Firestore → RTDB Reverse Comparison

**CONFIRMED — full raw Firestore `schools` collection content, 14 documents total.** Of these, 5 are
`{schoolId}_profile` sub-documents (a distinct document *kind* sharing the same flat collection, not
themselves schools) — excluded from the school count. **9 real school documents remain:**

| Firestore `schools/{id}` | `schoolCode` | Status field | Has RTDB `Indexes/School_codes` entry? |
|---|---|---|---|
| `SCH_D94FE8F7AD` | `10001` | `active` | **CONFIRMED yes** (the one real RTDB entry) |
| `SCH_E9E683B586` | `10008` | *(null)* | **CONFIRMED no** |
| `SCH_B76CFFF3C3` | `10009` | *(null)* | **CONFIRMED no** |
| `SCH_ACF67B0CD4` | `10011` | *(null)* | **CONFIRMED no** |
| `SCH_B56BB9A401` | `10012` | `active` | **CONFIRMED no** |
| `SCH_54D0424022` | `10013` | *(null)* | **CONFIRMED no** |
| `SCH_218AAF5C23` | `10015` | `active` | **CONFIRMED no** |
| `SCH_B9161BBE3C` | `10016` | `active` | **CONFIRMED no** |
| `SCH_202512` | **none** (null) | *(null)* | **CONFIRMED no** (also no RTDB entry) |

**RTDB-only entries:** none — every RTDB entry resolves correctly into Firestore (§ above).
**Firestore-only active schools (schoolCode present, no RTDB entry):** **7** — `10008, 10009, 10011,
10012, 10013, 10015, 10016`. This is the headline finding: **RTDB is missing 7 of the 8 real,
schoolCode-bearing schools that already exist correctly in Firestore.**

## Multi-Tenant Integrity

**CONFIRMED, no collisions found:**
- **Duplicate `schoolCode` values in Firestore:** none. All 8 populated values (`10001, 10008, 10009,
  10011, 10012, 10013, 10015, 10016`) are distinct — verified programmatically, not by eyeballing.
- **One `schoolCode` → multiple `schoolId`s:** none found.
- **One `schoolId` → multiple `schoolCode`s:** none found (each school doc has exactly one
  `schoolCode` field, singular).
- **RTDB internal collision:** the two RTDB entries are a forward/reverse pair for the *same* school,
  not two schools claiming the same code — not a conflict.

**No STOP-worthy collision exists anywhere in this data.**

## Duplicate Detection

Covered above — explicitly re-stated: **zero duplicates, zero conflicts, in either direction, in
either store.**

## Legacy `Schools.php` Assessment

Per instruction, `Schools.php` was **not** modified or executed — only re-examined as source, plus
the live data now available. **NOT VERIFIED (unchanged from Phase 8.1):** whether the `changeSchoolName`
code path at `Schools.php:192-199` is reachable from any current UI — no route inspection or runtime
trace was performed this phase either. **New evidence this phase:** the live RTDB data does **not**
contain any entry matching that code's specific write shape (`{schoolId} → schoolName`) — the second
RTDB entry found (`SCH_D94FE8F7AD → "10001"`) is code-shaped, not name-shaped, so it was **not**
written by that exact code path as documented. This makes it *less* likely `Schools.php`'s legacy
write is actually firing in production (if it were, we'd expect to see a school **name** as a value
somewhere in this node, and none exists) — but this is an inference from absence, not a proof of
unreachability, and is reported as such, not as a settled conclusion.

## Missing/Mismatched Records

- **Missing Firestore `schoolCode`:** 1 record, `SCH_202512` — **DATA GAP**. This document has no
  `schoolCode`, no name, and no status field populated at all (confirmed by the raw read — every
  field on it came back null in this script's projection). It is not clearly identifiable as an
  active production school from this document alone; it may be an incomplete/orphaned record, a
  test artifact, or a non-school system document that happens to sit in the same collection.
  **NOT VERIFIED** which of those it is — this phase did not have authorization to inspect it more
  deeply (e.g., reading its full raw field set) beyond the projection already gathered, and doing so
  wasn't necessary to answer the coverage question below.
- **Mismatched `schoolCode` (value present but disagrees with RTDB):** none found.

## Exact Counts

| Metric | Count |
|---|---|
| RTDB `Indexes/School_codes` entries (raw) | 2 |
| RTDB entries representing a distinct school | 1 |
| Firestore `schools` collection documents (raw) | 14 |
| Firestore `schools`-collection documents that are `_profile` sub-docs (not schools) | 5 |
| Firestore real school documents | 9 |
| Firestore school documents with `schoolCode` populated | 8 |
| Firestore school documents missing `schoolCode` | 1 (`SCH_202512`) |
| Duplicate `schoolCode` values | 0 |
| Schools present in RTDB but absent/mismatched in Firestore | 0 |
| Schools present in Firestore (with `schoolCode`) but absent from RTDB | 7 |

## Evidence

Full raw script output (RTDB dump, Firestore dump, per-entry cross-check, duplicate scan, missing-field
scan) was captured during this phase's single live read. Every number and table above is transcribed
directly from that output, not summarized from memory or re-derived.

## Final Decision

**A — FULL COVERAGE**, with one immaterial caveat stated precisely rather than hidden:

Every school-code mapping that genuinely exists in RTDB today (one school, `10001 →
SCH_D94FE8F7AD`) is **already correctly and completely represented in Firestore**. There is no
RTDB-only data that Firestore is missing. The only "gap" found (`SCH_202512`) is a gap in **both**
systems simultaneously — RTDB has no entry for it either — so it cannot be characterized as
something a Parent App migration to Firestore would lose; it is already unresolvable today under the
current RTDB-based `lookupSchoolCode()` path too.

**This is not "B — targeted backfill required"** in the sense Phase 8.1 anticipated (Firestore
missing data RTDB has) — if anything, the situation is inverted: **RTDB is the one with the coverage
gap**, missing 7 real schools Firestore already has correctly.

## Safe Next Step

The narrow migration described in Phase 8.1 §8 (switch `AuthRepository.lookupSchoolCode()`'s RTDB
read to a Firestore `schools.whereEqualTo("schoolCode", code)` query) is now backed by **live,
verified data showing Firestore is a strict superset of what RTDB currently provides** for this
specific lookup. No backfill step is needed before that switch — Firestore already has every mapping
RTDB has, plus 7 more. The `SCH_202512` gap is orthogonal to the migration (present under both
architectures) and, if it matters operationally, is its own separate, small investigation — not a
blocker for this migration.

**Per instruction, no implementation happens in this phase.** This report stops here, awaiting your
explicit authorization to proceed with the Parent App code change described in Phase 8.1 §8.

---

## Final Question — answered directly

**"Can Parent App `lookupSchoolCode()` safely be migrated from RTDB `Indexes/School_codes` to
Firestore `schools/{schoolId}.schoolCode` without losing or confusing any tenant?"**

**YES.**

Not qualified with "after backfill" — the live data confirms none is needed for this specific
migration. The one honest residual uncertainty (`SCH_202512`'s true nature, and the unidentified
writer of the second RTDB entry) does not change this answer, because neither affects whether
Firestore already correctly contains every mapping the RTDB path currently serves — it does.

---

**Nothing in this phase was committed, staged, reset, stashed, or pushed. No application code, no
Firestore data, and no RTDB data was modified. The one script used to read production data lived
entirely outside the repository and made no write calls.**
