'use strict';
/**
 * Index Sentinel self-test.
 *
 * Two areas carry all the risk and both are asserted directly:
 *
 *  1. KEY NORMALISATION. The Firestore Admin API appends an implicit trailing
 *     `__name__` to every composite index; firestore.indexes.json omits it.
 *     Get this wrong and all 284 live indexes look different from all 183
 *     declared ones — the tool reports a catastrophe that does not exist.
 *
 *  2. INVERTED SEVERITY. For rules, live-has-what-git-lacks is the emergency.
 *     For indexes it is the reverse: a COMMITTED index that is not deployed
 *     means its query is failing in production right now, while a live index
 *     missing from git is a reproducibility defect that must NOT block a
 *     deploy. Blocking on 101 pre-existing ones would get the gate muted.
 */
const { indexKey, parseIndexFile, toIndexFile, describeKey } = require('../indexes/live');
const { fourWay, verdict, STATUS } = require('../indexes/diff');

const asc = (p) => ({ fieldPath: p, order: 'ASCENDING' });
const desc = (p) => ({ fieldPath: p, order: 'DESCENDING' });

/** Build a Map the way fourWay expects. */
const mapOf = (...recs) => {
  const m = new Map();
  for (const r of recs) m.set(r.key, r);
  return m;
};
const rec = (collectionGroup, fields, extra = {}) => ({
  key: indexKey(collectionGroup, 'COLLECTION', fields),
  collectionGroup, queryScope: 'COLLECTION', fields, ...extra,
});

module.exports = function runIndexTests({ ok, eq }) {
  // ── key normalisation ─────────────────────────────────────────────
  {
    // The exact live-vs-file shape, from graderadmin.
    const live = indexKey('transportEvents', 'COLLECTION',
      [asc('schoolId'), asc('event_type'), desc('event_date'), desc('__name__')]);
    const file = indexKey('transportEvents', 'COLLECTION',
      [asc('schoolId'), asc('event_type'), desc('event_date')]);
    eq('indexes/key: implicit trailing __name__ is stripped', live, file);
  }
  {
    // Firestore's implicit __name__ ECHOES the last explicit field's direction.
    // So [a ASC, __name__ ASC] is implicit and must be stripped, while
    // [a ASC, __name__ DESC] is a deliberate override and must be kept —
    // stripping it would merge two genuinely different indexes into one key.
    const explicit = indexKey('c', 'COLLECTION', [asc('a'), desc('__name__')]);
    const implicitAsc = indexKey('c', 'COLLECTION', [asc('a'), asc('__name__')]);
    const implicitDesc = indexKey('c', 'COLLECTION', [desc('a'), desc('__name__')]);
    ok('indexes/key: a __name__ that overrides direction is preserved', explicit.includes('__name__'));
    ok('indexes/key: an implicit ASC __name__ is dropped', !implicitAsc.includes('__name__'));
    ok('indexes/key: an implicit DESC __name__ is dropped', !implicitDesc.includes('__name__'));
    eq('indexes/key: the override does not collide with the implicit form',
      explicit === implicitAsc, false);
  }
  {
    eq('indexes/key: field ORDER is significant',
      indexKey('c', 'COLLECTION', [asc('a'), asc('b')]) === indexKey('c', 'COLLECTION', [asc('b'), asc('a')]),
      false);
    eq('indexes/key: direction is significant',
      indexKey('c', 'COLLECTION', [asc('a')]) === indexKey('c', 'COLLECTION', [desc('a')]), false);
    eq('indexes/key: query scope is significant',
      indexKey('c', 'COLLECTION', [asc('a')]) === indexKey('c', 'COLLECTION_GROUP', [asc('a')]), false);
    eq('indexes/key: arrayConfig is captured',
      indexKey('c', 'COLLECTION', [{ fieldPath: 'tags', arrayConfig: 'CONTAINS' }]).includes('tags:CONTAINS'), true);
    eq('indexes/key: missing queryScope defaults to COLLECTION',
      indexKey('c', undefined, [asc('a')]), indexKey('c', 'COLLECTION', [asc('a')]));
  }

  // ── file parsing ──────────────────────────────────────────────────
  {
    const f = parseIndexFile(JSON.stringify({
      indexes: [{ collectionGroup: 'homework', queryScope: 'COLLECTION', fields: [asc('schoolId'), desc('dueDate')] }],
      fieldOverrides: [{ collectionGroup: 'x' }],
    }));
    ok('indexes/parse: reads a well-formed file', f.ok && f.count === 1);
    eq('indexes/parse: keeps fieldOverrides', f.fieldOverrides.length, 1);
    const bad = parseIndexFile('{ not json');
    ok('indexes/parse: malformed JSON is reported, not thrown', bad.ok === false && bad.reason === 'invalid-json');
  }
  {
    // Round-trip: live shape -> file shape -> same key.
    const liveShape = [{ collectionGroup: 'marks', queryScope: 'COLLECTION',
      fields: [asc('schoolId'), desc('examId'), desc('__name__')] }];
    const file = toIndexFile(liveShape, []);
    const reparsed = parseIndexFile(JSON.stringify(file));
    eq('indexes/pull: round-trips to an identical key',
      [...reparsed.byKey.keys()][0], indexKey('marks', 'COLLECTION', [asc('schoolId'), desc('examId')]));
    ok('indexes/pull: strips the implicit __name__ from emitted files',
      !JSON.stringify(file).includes('__name__'));
  }

  // ── the state machine ─────────────────────────────────────────────
  const A = rec('homework', [asc('schoolId'), desc('dueDate')]);
  const B = rec('marks', [asc('schoolId'), asc('examId')]);

  {
    const r = fourWay({ live: mapOf(A), remote: mapOf(A), head: mapOf(A), work: mapOf(A) });
    eq('indexes/diff: present everywhere -> clean', r.indexes[0].status, STATUS.CLEAN);
    eq('indexes/diff: verdict ok', verdict(r).level, 'ok');
  }
  {
    // THE emergency: committed, pushed, but never deployed.
    const r = fourWay({ live: new Map(), remote: mapOf(A), head: mapOf(A), work: mapOf(A) });
    eq('indexes/diff: committed but not in production -> undeployed', r.indexes[0].status, STATUS.UNDEPLOYED);
    eq('indexes/diff: undeployed BLOCKS', verdict(r).level, 'block');
    ok('indexes/diff: and names the failure mode',
      /FAILED_PRECONDITION/.test(verdict(r).headline));
  }
  {
    // The 101: production has it, git does not.
    const r = fourWay({ live: mapOf(A), remote: new Map(), head: new Map(), work: new Map() });
    eq('indexes/diff: live but uncommitted -> live_only', r.indexes[0].status, STATUS.LIVE_ONLY);
    eq('indexes/diff: live_only WARNS, never blocks (inverted vs rules)', verdict(r).level, 'warn');
  }
  {
    const r = fourWay({ live: new Map(), remote: new Map(), head: new Map(), work: mapOf(A) });
    eq('indexes/diff: in my file only -> mine', r.indexes[0].status, STATUS.MINE);
    eq('indexes/diff: uncommitted index warns', verdict(r).level, 'warn');
  }
  {
    const r = fourWay({ live: mapOf(A), remote: mapOf(A, B), head: mapOf(A), work: mapOf(A) });
    const b = r.indexes.find(i => i.collectionGroup === 'marks');
    eq('indexes/diff: pushed by a teammate, not pulled -> behind', b.status, STATUS.BEHIND);
    ok('indexes/diff: behind is also flagged unpulled', b.unpulled === true);
  }
  {
    const r = fourWay({ live: mapOf(A), remote: mapOf(A), head: mapOf(A), work: new Map() });
    eq('indexes/diff: removed locally but still live -> dropped_locally',
      r.indexes[0].status, STATUS.DROPPED_LOCALLY);
  }
  {
    // Deployed but not serving yet — the query still fails.
    const building = { ...A, state: 'CREATING' };
    const r = fourWay({ live: mapOf(building), remote: mapOf(A), head: mapOf(A), work: mapOf(A) });
    eq('indexes/diff: live but not READY -> building', r.indexes[0].status, STATUS.BUILDING);
    eq('indexes/diff: building BLOCKS — the query fails until it finishes', verdict(r).level, 'block');
  }
  {
    // Severity ordering: an undeployed index must outrank 100 live-only ones.
    const many = Array.from({ length: 5 }, (_, n) => rec('c' + n, [asc('f')]));
    const r = fourWay({
      live: mapOf(...many), remote: mapOf(A), head: mapOf(A), work: mapOf(A),
    });
    eq('indexes/diff: the failing index sorts above the undocumented ones',
      r.indexes[0].status, STATUS.UNDEPLOYED);
    eq('indexes/diff: undeployed still blocks with live_only present', verdict(r).level, 'block');
  }

  // ── honesty ───────────────────────────────────────────────────────
  {
    const r = fourWay({ live: null, remote: mapOf(A), head: mapOf(A), work: mapOf(A) });
    ok('indexes/diff: no live source is reported, not assumed', r.liveAvailable === false);
    eq('indexes/diff: unreadable production -> unknown, NEVER ok', verdict(r).level, 'unknown');
    ok('indexes/diff: and says the claim is unverified', /unverified/.test(verdict(r).headline));
  }
  {
    const r = fourWay({ live: mapOf(A), remote: null, head: mapOf(A), work: mapOf(A) });
    ok('indexes/diff: no remote -> unpulled is null, not false', r.indexes[0].unpulled === null);
    ok('indexes/diff: remoteAvailable false', r.remoteAvailable === false);
  }

  // ── presentation ──────────────────────────────────────────────────
  {
    const d = describeKey(indexKey('homework', 'COLLECTION', [asc('schoolId'), desc('dueDate')]));
    eq('indexes/describe: recovers the collection group', d.collectionGroup, 'homework');
    ok('indexes/describe: renders direction arrows', d.fields.includes('↑') && d.fields.includes('↓'));
  }
};
