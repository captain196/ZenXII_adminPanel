#!/usr/bin/env node
/**
 * Behavioural self-test for projection_guard.
 *
 * The PHP suite can assert that the one-off scripts CALL the guard, but not
 * that the guard answers correctly — it is JavaScript. A guard that loaded
 * cleanly, was called everywhere, and returned false for everything would pass
 * every source-level check while protecting nothing, which is the worst
 * possible failure for a safety device.
 *
 * So this runs the real thing and is executed by TimetableScriptProvenanceGuardTest.
 * Exits 0 when every case holds, 1 with a message on the first failure.
 */

'use strict';

const path = require('path');
const guard = require(path.resolve(__dirname, 'projection_guard.js'));

let failures = 0;
function check(name, actual, expected) {
  const ok = JSON.stringify(actual) === JSON.stringify(expected);
  if (!ok) {
    failures++;
    console.error('FAIL  ' + name);
    console.error('        expected: ' + JSON.stringify(expected));
    console.error('        actual:   ' + JSON.stringify(actual));
  }
}

// ── isProjected ────────────────────────────────────────────────────────
check('a document written by the projection is owned',
  guard.isProjected({ projectedFromVersion: 'CAND_1' }), true);

check('a document with no stamp is not owned',
  guard.isProjected({ periods: [] }), false);

// Whitespace is not provenance. A field holding "   " says nothing about which
// version a document came from, and reading it as a stamp would protect a
// document nothing authored — the same rule Timetable_projection_service applies.
check('a whitespace-only stamp is not provenance',
  guard.isProjected({ projectedFromVersion: '   ' }), false);

check('an empty stamp is not provenance',
  guard.isProjected({ projectedFromVersion: '' }), false);

// A non-string must not be coerced into a stamp: `true` or `1` would otherwise
// protect every document that carried them.
check('a non-string stamp is not provenance',
  guard.isProjected({ projectedFromVersion: true }), false);

check('a missing document is not owned', guard.isProjected(null), false);
check('an undefined document is not owned', guard.isProjected(undefined), false);

// ── partition ──────────────────────────────────────────────────────────
const docs = [
  { id: 'free1',    data: () => ({ periods: [] }) },
  { id: 'owned1',   data: () => ({ projectedFromVersion: 'CAND_1' }) },
  { id: 'free2',    data: () => ({ projectedFromVersion: '  ' }) },
  { id: 'owned2',   data: () => ({ projectedFromVersion: 'CAND_2' }) },
];
const p = guard.partition(docs);

check('only unowned documents are allowed through',
  p.allowed.map(d => d.id), ['free1', 'free2']);
check('owned documents are refused, with the version named',
  p.refused, [{ id: 'owned1', version: 'CAND_1' },
              { id: 'owned2', version: 'CAND_2' }]);

// Plain {id, data} objects, not just Firestore snapshots.
check('a plain object works as well as a snapshot',
  guard.partition([{ id: 'x', data: { projectedFromVersion: 'V' } }]).refused,
  [{ id: 'x', version: 'V' }]);

check('an empty input partitions to nothing',
  guard.partition([]), { allowed: [], refused: [] });
check('a missing input does not throw',
  guard.partition(undefined), { allowed: [], refused: [] });

// ── report ─────────────────────────────────────────────────────────────
// Returns the count so a caller can fold it into its own exit status, and says
// nothing when there is nothing to say.
check('reporting nothing returns zero', guard.report([], 'deleted'), 0);
check('reporting returns how many were refused',
  guard.report(p.refused, 'deleted'), 2);

if (failures > 0) {
  console.error('\n' + failures + ' guard behaviour check(s) FAILED');
  process.exit(1);
}
console.log('projection_guard: all behaviour checks passed');
process.exit(0);
