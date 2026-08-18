'use strict';
const { contracts } = require('../manifest');

/** Map a numeric score to a risk band. */
function band(score) {
  if (score >= 22) return 'CRITICAL';
  if (score >= 13) return 'HIGH';
  if (score >= 6) return 'MEDIUM';
  return 'LOW';
}

/**
 * Weighted risk score (see blueprint §06). Inputs are pre-computed facts about the change.
 * Returns { score, band, factors } so the report can explain *why*.
 */
function scoreChange({ classified, blast, contractsAtRisk, churnLines, priorHits }) {
  const surfacesTouched = new Set();
  let rulesTouched = false, indexTouched = false, markTouched = false, unmapped = 0;

  for (const f of classified) {
    if (f.surface) surfacesTouched.add(f.surface);
    if (f.kind === 'rules') rulesTouched = true;
    if (f.kind === 'index') indexTouched = true;
    if (f.modules.includes('push')) markTouched = true;
    if (f.modules.length === 0 && !['doc', 'test', 'style'].includes(f.kind)) unmapped++;
  }

  const blockingContracts = contractsAtRisk.filter(c => contracts[c] && contracts[c].on_break === 'BLOCK');

  const factors = {
    cross_surface: Math.min(blast.surfaces.size, 4) * 3,
    contracts: contractsAtRisk.length * 4,
    blocking_contracts: blockingContracts.length * 6,
    rules_touched: rulesTouched ? 8 : 0,
    index_touched: indexTouched ? 4 : 0,
    push_marks: markTouched ? 4 : 0,
    churn: Math.min(Math.floor((churnLines || 0) / 50), 5),
    blast_size: Math.min(blast.modules.length, 8),
    history: Math.min(priorHits || 0, 3) * 2
  };

  const score = Object.values(factors).reduce((a, b) => a + b, 0);

  return {
    score,
    band: band(score),
    factors,
    blockingContracts,
    unmapped
  };
}

module.exports = { scoreChange, band };
