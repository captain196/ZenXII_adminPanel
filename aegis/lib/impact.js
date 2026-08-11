'use strict';
const path = require('path');
const { classify } = require('./classify');
const { blastRadius, regressionTargets, contractsForModules } = require('./graph');
const { scoreChange } = require('./score');
const { selectSuite } = require('./select');
const { priorRegressions } = require('./bug_ledger');
const gitLib = require('./git');
const { modules } = require('../manifest');

// Presentation-layer file kinds: render data, can't change logic/data/contracts.
const PRESENTATION_KINDS = new Set(['view', 'style', 'xml']);
// Invariants a pure presentation change cannot break (server/data logic).
const LOGIC_CONTRACTS = ['payment_verify', 'session_scope', 'claim_key', 'push_mark_registry', 'publish_gate', 'teacher_id_sync', 'force_set_password'];

/**
 * Full change-impact analysis (blueprint §06). Given a config and either an
 * explicit file list or a git repo+base, returns a structured impact result.
 */
function analyze(cfg, { files, repo, base }) {
  // 1. resolve the changed-file set
  let absFiles = [];
  if (files && files.length) {
    absFiles = files.map(f => path.isAbsolute(f) ? f : path.resolve(process.cwd(), f));
  } else if (repo) {
    absFiles = gitLib.changedFiles(repo, base);
  }
  absFiles = [...new Set(absFiles)];

  // 2. classify
  const classified = classify(cfg, absFiles);

  // 3. direct modules → blast radius
  const direct = [...new Set(classified.flatMap(f => f.modules))];

  // Layer-awareness: a PRESENTATION-only change (views/styles/xml) renders data —
  // it can't alter business logic, payment verification, or data written. So its
  // blast is its OWN screens, not downstream data consumers, and logic-invariant
  // contracts don't apply. (A form-view that changes submitted fields usually
  // rides with a controller change, which flips this off.)
  const relevant = classified.filter(f => f.modules.length && !['doc', 'test'].includes(f.kind));
  const presentationOnly = relevant.length > 0 && relevant.every(f => PRESENTATION_KINDS.has(f.kind));

  let blast, targets;
  if (presentationOnly) {
    const surfs = new Set();
    for (const m of direct) (modules[m] && modules[m].surfaces || []).forEach(s => surfs.add(s));
    blast = { modules: [...direct], surfaces: surfs, edges: [] };
    targets = [...direct];
  } else {
    blast = blastRadius(direct);
    targets = regressionTargets(direct);
  }

  // 4. contracts at risk (from modules + from touching rules directly)
  const contractSet = new Set(contractsForModules(direct));
  if (classified.some(f => f.kind === 'rules')) contractSet.add('rules_deny_default');
  if (classified.some(f => f.kind === 'index')) contractSet.add('firestore_index');
  if (classified.some(f => f.modules.includes('push'))) contractSet.add('push_mark_registry');
  if (presentationOnly) LOGIC_CONTRACTS.forEach(c => contractSet.delete(c));
  const contractsAtRisk = [...contractSet];

  // 5. regression memory
  const allPrior = priorRegressions(cfg);
  const priorForTouched = {};
  let priorHits = 0;
  for (const m of direct) if (allPrior[m]) { priorForTouched[m] = allPrior[m]; priorHits += allPrior[m].length; }

  // 6. churn
  let churnLines = 0;
  if (repo) churnLines = gitLib.churn(repo, base);

  // 7. score
  const scored = scoreChange({ classified, blast, contractsAtRisk, churnLines, priorHits });

  // 8. suite selection
  const rulesTouched = classified.some(f => f.kind === 'rules');
  const suite = selectSuite(scored.band, { rulesTouched, regressionTargets: targets });

  // 9. confidence — how much of the change we could actually map
  const mappable = classified.filter(f => !['doc', 'test', 'style'].includes(f.kind));
  const mapped = mappable.filter(f => f.modules.length > 0).length;
  const confidence = mappable.length === 0 ? 'n/a'
    : `${Math.round((mapped / mappable.length) * 100)}% mapped`;
  const unmapped = mappable.filter(f => f.modules.length === 0);

  return {
    risk: scored.band,
    score: scored.score,
    factors: scored.factors,
    confidence,
    fileCount: classified.length,
    files: classified,
    surfaces: blast.surfaces,
    blast,
    direct,
    contractsAtRisk,
    blocking: scored.blockingContracts,
    suite,
    presentationOnly,
    priorRegressions: priorForTouched,
    unmapped
  };
}

module.exports = { analyze };
