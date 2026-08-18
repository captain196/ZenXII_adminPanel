'use strict';
const { modules } = require('../manifest');

const ALL = Object.keys(modules);

/** Resolve '*' consumer/target wildcards to the full module list. */
function expand(list) {
  if (!list) return [];
  const out = new Set();
  for (const m of list) {
    if (m === '*') ALL.forEach(x => out.add(x));
    else out.add(m);
  }
  return [...out];
}

/**
 * BFS the transitive closure of consumers + regression_targets from a seed set.
 * Returns { modules:[...], surfaces:Set, edges:[[from,to]...] }.
 */
function blastRadius(seed) {
  const seen = new Set(seed.filter(m => modules[m]));
  const queue = [...seen];
  const edges = [];

  while (queue.length) {
    const cur = queue.shift();
    const def = modules[cur];
    if (!def) continue;
    const next = new Set([...expand(def.consumers), ...expand(def.regression_targets)]);
    for (const n of next) {
      if (!modules[n]) continue;
      edges.push([cur, n]);
      if (!seen.has(n)) { seen.add(n); queue.push(n); }
    }
  }

  const surfaces = new Set();
  for (const m of seen) (modules[m].surfaces || []).forEach(s => surfaces.add(s));

  return { modules: [...seen], surfaces, edges };
}

/** Direct + transitive regression targets (the suite to run), de-duped. */
function regressionTargets(seed) {
  const set = new Set();
  for (const m of seed) {
    if (!modules[m]) continue;
    expand(modules[m].regression_targets).forEach(t => set.add(t));
  }
  return [...set];
}

/** Contracts touched by a seed set of modules. */
function contractsForModules(seed) {
  const set = new Set();
  for (const m of seed) {
    if (!modules[m]) continue;
    (modules[m].contracts || []).forEach(c => set.add(c));
  }
  return [...set];
}

module.exports = { blastRadius, regressionTargets, contractsForModules, expand, ALL };
