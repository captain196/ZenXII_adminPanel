#!/usr/bin/env node
/**
 * RETIRED 2026-09-12 — this file was a trap.
 *
 * Despite the name, it was a BYTE-IDENTICAL copy of
 * scripts/migrate_timetables_to_firestore.js: not an inspection at all, but the
 * RTDB → Firestore timetable migration, `--apply` flag and all. Its own header
 * said "Phase C-1 — Timetable Migration", which nobody reaching for a script
 * called `inspect_…` would expect to read before running it.
 *
 * Two things made that dangerous rather than merely untidy:
 *
 *   1. It wrote `timetables` with `set(payload, { merge: false })` — a FULL
 *      REPLACE — and had no check for `projectedFromVersion`. Run against a
 *      school whose timetable is published, it would overwrite what teachers
 *      and parents see with data the canonical lifecycle knows nothing about,
 *      and no version would record that it happened.
 *
 *   2. Its source is RTDB, which is retired. The reads now return nothing, so
 *      the "migration" it performs is a migration from an empty store.
 *
 * A name that hides a write is worse than a write, because it defeats the one
 * habit that protects people: reading what a script does before running it.
 *
 * Nothing is lost. The real migration still exists under its real name, and
 * git history holds every version of this file.
 */

'use strict';

console.error(`
  This script is retired and does nothing.

  It was a mis-named duplicate of the RTDB → Firestore timetable migration —
  a full-replace writer of the 'timetables' collection, not an inspection.

  If you want to LOOK at timetable data:
      node scripts/verify_timetable_tuple.js
      node scripts/list_timetable_conflicts.js
      php index.php timetables_healthcheck run     (read-only)

  If you genuinely want the RTDB migration (it reads a retired store and will
  find nothing):
      node scripts/migrate_timetables_to_firestore.js
`);

process.exit(2);
