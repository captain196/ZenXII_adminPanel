'use strict';
/**
 * Pending-push breakdown + PARTIAL-PUSH detection.
 *
 * Two questions, one view:
 *  1. "What modules ride along in this push?" → group unpushed work by module.
 *  2. "Did I leave part of a change behind?" → compare what's COMMITTED (ships on
 *     push) vs what's still UNCOMMITTED (stays local). If the same module has
 *     files in BOTH, you're likely shipping HALF a change — the dangerous case.
 */
const { classify } = require('./classify');
const { modules, contracts } = require('../manifest');
const gitLib = require('./git');

const PRESENTATION = new Set(['view', 'style', 'xml']);
const RISK_ORDER = { CRITICAL: 3, HIGH: 2, MEDIUM: 1, LOW: 0 };

function moduleRisk(logicCount, def) {
  const blockC = (def.contracts || []).filter(c => contracts[c] && contracts[c].on_break === 'BLOCK');
  const p0 = /\bP0\b|CRITICAL/i.test(def.notes || '');
  let level;
  if (logicCount > 0 && (blockC.length || p0)) level = 'CRITICAL';
  else if (logicCount > 0 && (def.contracts || []).length) level = 'HIGH';
  else if (logicCount > 0) level = 'MEDIUM';
  else level = 'LOW';
  return { level, blockContracts: blockC, p0 };
}

function analyze(cfg, { repo, base }) {
  const shipClassified = classify(cfg, gitLib.committedVsBase(repo, base)); // COMMITTED → ships on push
  const localClassified = classify(cfg, gitLib.uncommittedFiles(repo));      // UNCOMMITTED → left behind

  const byModule = {};
  const ensure = (m) => byModule[m] || (byModule[m] = {
    module: m, ship: 0, local: 0, logic: 0, kinds: new Set()
  });
  const unmappedShip = [], unmappedLocal = [];

  const ingest = (list, state, unmappedBucket) => {
    for (const f of list) {
      if (!f.modules.length) {
        if (!['doc', 'test'].includes(f.kind)) unmappedBucket.push(f);
        continue;
      }
      for (const m of f.modules) {
        const e = ensure(m);
        e[state]++;
        e.kinds.add(f.kind);
        if (!PRESENTATION.has(f.kind)) e.logic++;
      }
    }
  };
  ingest(shipClassified, 'ship', unmappedShip);
  ingest(localClassified, 'local', unmappedLocal);

  const rows = Object.values(byModule).map(e => {
    const def = modules[e.module] || {};
    const r = moduleRisk(e.logic, def);
    return {
      module: e.module,
      ship: e.ship,
      local: e.local,
      split: e.ship > 0 && e.local > 0,     // half a change shipping
      fileCount: e.ship + e.local,
      kinds: [...e.kinds].sort().join(','),
      contracts: def.contracts || [],
      blockContracts: r.blockContracts,
      p0: r.p0,
      level: r.level
    };
  }).sort((a, b) =>
    (b.split - a.split) ||                                     // partial pushes first
    (RISK_ORDER[b.level] - RISK_ORDER[a.level]) ||
    (b.fileCount - a.fileCount) ||
    a.module.localeCompare(b.module)
  );

  return {
    base: base || '(no base — nothing committed to compare)',
    shipFiles: shipClassified.length,
    localFiles: localClassified.length,
    moduleCount: rows.length,
    rows,
    splitModules: rows.filter(r => r.split),
    unmappedShip,
    unmappedLocal,
    highest: rows.length ? rows[0].level : 'LOW'
  };
}

module.exports = { analyze };
