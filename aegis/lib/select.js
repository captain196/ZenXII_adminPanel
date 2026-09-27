'use strict';

/**
 * Risk-based test selection (blueprint §07). Returns the minimal suite that
 * covers the blast radius — the Google "affected-targets" technique.
 */
const MATRIX = {
  LOW:      { static: true, contract: true, rulesUnit: 'ifRules', smoke: 'none',     e2e: 'none',     manualUAT: 'none' },
  MEDIUM:   { static: true, contract: true, rulesUnit: true,       smoke: 'affected', e2e: 'none',     manualUAT: 'none' },
  HIGH:     { static: true, contract: true, rulesUnit: true,       smoke: 'affected', e2e: 'affected', manualUAT: 'suggested' },
  CRITICAL: { static: true, contract: true, rulesUnit: true,       smoke: 'all',      e2e: 'affected', manualUAT: 'required' }
};

function selectSuite(bandName, { rulesTouched, regressionTargets }) {
  const base = MATRIX[bandName] || MATRIX.LOW;
  const suite = {
    static: base.static,
    contract: base.contract,
    rulesUnit: base.rulesUnit === 'ifRules' ? !!rulesTouched : base.rulesUnit,
    smoke: base.smoke === 'affected' ? regressionTargets : (base.smoke === 'all' ? ['*'] : []),
    e2e: base.e2e === 'affected' ? regressionTargets : [],
    manualUAT: base.manualUAT
  };
  return suite;
}

module.exports = { selectSuite, MATRIX };
