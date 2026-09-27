'use strict';
const { read, exists, finding, rollup } = require('./_util');

/**
 * L5 — Composite queries need a matching index deployed BEFORE the code.
 * Heuristic (warn-only): if a changed file introduces a composite query
 * (2+ equality filters, or filter + orderBy), warn to confirm the index exists.
 * Missing index = query 500 in prod; indexes deploy separately + build async.
 */
const COMPOSITE_KT = /whereEqualTo\([^)]*\)\s*\.\s*(whereEqualTo|whereGreaterThan|whereLessThan|orderBy)\(/;
const COMPOSITE_JS = /\.where\([^)]*\)\s*\.\s*(where|orderBy)\(/;
const COMPOSITE_PHP = /->where\([^)]*\)\s*->\s*(where|orderBy)\(/;

module.exports = {
  id: 'firestore_index',
  title: 'Composite Firestore query index presence',
  blocking: false,
  check(cfg, ctx) {
    const changed = (ctx && ctx.changed) || [];
    const findings = [];
    let flagged = 0;

    for (const f of changed) {
      if (f.kind === 'test' || f.kind === 'doc') continue;
      const t = read(f.path);
      if (!t) continue;
      const isKt = f.path.endsWith('.kt');
      const isPhp = f.path.endsWith('.php');
      const re = isKt ? COMPOSITE_KT : isPhp ? COMPOSITE_PHP : COMPOSITE_JS;
      if (re.test(t)) {
        flagged++;
        findings.push(finding('warn', f.path, 0,
          `composite query changed — confirm a matching Firestore index is deployed (indexes ship separately, build async).`));
      }
    }

    if (!flagged) return { id: this.id, status: 'skip', findings, note: 'no composite queries in change set' };
    const idxNote = exists(cfg.firestoreIndexes) ? '' : ' (firestore.indexes.json not found at configured path)';
    return { id: this.id, status: rollup(findings), findings, note: `${flagged} composite query file(s)${idxNote}` };
  }
};
