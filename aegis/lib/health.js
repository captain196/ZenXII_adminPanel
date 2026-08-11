'use strict';
/**
 * Aegis health snapshot (Layer §08) — computed from REAL local data:
 * doctor path checks, manifest graph integrity, contract results across every
 * repo's working diff, per-surface change risk, and per-module blast counts.
 *
 * This is the INTEGRITY/CHANGE health Aegis can see statically today. The
 * RUNTIME tiles (crash-free, API p95, delivery) light up once the telemetry CF
 * writes `aegis_metrics_daily` — see observability/dashboard.md. Until then the
 * dashboard renders them as "awaiting telemetry" rather than faking numbers.
 */
const fs = require('fs');
const { modules, contracts } = require('../manifest');
const { classify } = require('./classify');
const gitLib = require('./git');
const contractsRunner = require('../contracts');

function doctorChecks(cfg) {
  const checks = [];
  const add = (label, ok, detail) => checks.push({ label, ok: !!ok, detail: detail || '' });
  for (const [k, s] of Object.entries(cfg.surfaces)) add(`surface:${k}`, fs.existsSync(s.root), s.root);
  add('firestore.rules', fs.existsSync(cfg.firestoreRules));
  add('firestore.indexes', fs.existsSync(cfg.firestoreIndexes));
  add('BUG_LEDGER', fs.existsSync(cfg.bugLedger));
  add('contract checks', contractsRunner.CHECKS.length === 6, `${contractsRunner.CHECKS.length}/6`);
  return checks;
}

function graphIntegrity() {
  const names = new Set(Object.keys(modules));
  const dangling = [];
  for (const [m, def] of Object.entries(modules))
    for (const t of [...(def.regression_targets || []), ...(def.consumers || [])])
      if (t !== '*' && !names.has(t)) dangling.push(`${m}→${t}`);
  return { ok: dangling.length === 0, dangling, moduleCount: names.size, contractCount: Object.keys(contracts).length };
}

/** Gather changed files across every distinct gitRepo, classified. */
function changedAcrossRepos(cfg) {
  const repos = new Set();
  for (const s of Object.values(cfg.surfaces)) if (s.gitRepo) repos.add(s.gitRepo);
  const all = [];
  const perRepo = {};
  for (const repo of repos) {
    let base = null;
    if (gitLib.git(repo, ['rev-parse', '--verify', 'origin/main'])) base = 'origin/main';
    else if (gitLib.git(repo, ['rev-parse', '--verify', 'main'])) base = 'main';
    const files = gitLib.changedFiles(repo, base);
    perRepo[repo] = files.length;
    all.push(...files);
  }
  return { classified: classify(cfg, [...new Set(all)]), perRepo };
}

function build(cfg) {
  const checks = doctorChecks(cfg);
  const graph = graphIntegrity();
  const { classified, perRepo } = changedAcrossRepos(cfg);

  // contract results across the whole working set
  const contractRes = contractsRunner.run(cfg, { changed: classified });
  const contractTiles = contractRes.results.map(r => ({
    id: r.id, title: r.title, status: r.status, blocking: r.blocking,
    findings: r.findings.length, note: r.note
  }));

  // per-surface change risk
  const surfaceMap = {};
  for (const [k, s] of Object.entries(cfg.surfaces)) {
    surfaceMap[k] = { key: k, exists: fs.existsSync(s.root), changed: 0, mapped: 0 };
  }
  for (const f of classified) {
    const surf = surfaceMap[f.surface];
    if (surf) { surf.changed++; if (f.modules.length) surf.mapped++; }
  }
  const surfaces = Object.values(surfaceMap).map(s => ({
    ...s,
    health: !s.exists ? 0 : s.changed === 0 ? 100 : Math.max(60, 100 - Math.min(s.changed, 30))
  }));

  // per-module change counts + high-risk
  const changedByModule = {};
  for (const f of classified) for (const m of f.modules) changedByModule[m] = (changedByModule[m] || 0) + 1;
  const p0 = Object.entries(modules)
    .filter(([, d]) => /\bP0\b|CRITICAL/i.test(d.notes || ''))
    .map(([m]) => m);
  const highRiskSet = new Set(p0);
  // modules touched that carry a BLOCK contract
  for (const [m, count] of Object.entries(changedByModule)) {
    const cs = (modules[m] && modules[m].contracts) || [];
    if (cs.some(c => contracts[c] && contracts[c].on_break === 'BLOCK')) highRiskSet.add(m);
  }
  const highRisk = [...highRiskSet].map(m => ({
    module: m,
    changed: changedByModule[m] || 0,
    reason: p0.includes(m) ? 'P0 module' : 'touches a BLOCK contract',
    notes: (modules[m] && modules[m].notes) || ''
  })).sort((a, b) => b.changed - a.changed);

  // system integrity score
  let score = 100;
  const doctorFails = checks.filter(c => !c.ok).length;
  score -= doctorFails * 20;
  if (!graph.ok) score -= 15;
  score -= contractRes.blocked.length * 25;
  score -= contractRes.warned.length * 3;
  score = Math.max(0, Math.min(100, score));

  const deployReady = doctorFails === 0 && graph.ok && contractRes.blocked.length === 0;

  return {
    generatedAt: null, // stamped by caller
    system: { integrityHealth: score, deployReady, blocking: contractRes.blocked.map(b => b.id) },
    doctor: { ok: doctorFails === 0, checks },
    graph,
    contracts: contractTiles,
    surfaces,
    changeSet: { totalFiles: classified.length, perRepo },
    modules: { total: graph.moduleCount, changedByModule, highRisk },
    // SEAM: once observability/telemetry-cf is deployed, read the latest
    // aegis_metrics_daily doc via a connector and set runtime.modules here.
    // Kept out of the zero-dep core — a separate connector (with firebase-admin)
    // passes the data in, so lib/health.js stays dependency-free.
    runtime: { available: false, note: 'Runtime tiles (crash-free, API p95, notification delivery) light up once observability/telemetry-cf is deployed and writes aegis_metrics_daily. See its DEPLOY.md.' }
  };
}

module.exports = { build };
