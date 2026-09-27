'use strict';
/**
 * Aegis AI Reviewer (Layer §14) — assembles the context pack and (optionally)
 * runs it through Claude Code. Advises + ranks; NEVER auto-merges or auto-deploys.
 *
 * Usage (via cli): node cli.js review [--repo p] [--base ref] [--files ...] [--run]
 *   without --run : writes .reports/AI_REVIEW_REQUEST.md (paste into Claude, or pipe)
 *   with    --run : pipes the prompt to `claude -p` and writes .reports/AI_REVIEW.md
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const impactLib = require('./../lib/impact');
const gitLib = require('./../lib/git');
const { contracts, modules } = require('./../manifest');

const DIFF_BUDGET = 120 * 1024; // cap total diff bytes sent to the model
const TEMPLATE = path.join(__dirname, 'review_prompt.md');

/** Rank changed files so the highest-signal diffs make it into the budget first. */
function rankFiles(files) {
  const weight = (f) => {
    if (f.kind === 'rules') return 100;
    if (f.modules.includes('auth') || f.modules.includes('push')) return 90;
    if (f.kind === 'index') return 80;
    if (f.modules.length) return 50 + f.modules.length;
    if (['doc', 'test', 'style'].includes(f.kind)) return 1;
    return 10;
  };
  return [...files].sort((a, b) => weight(b) - weight(a));
}

/** Collect per-file diffs (from the file's own repo) up to the byte budget. */
function collectDiff(files, base) {
  let total = 0;
  const parts = [];
  const dropped = [];
  for (const f of rankFiles(files)) {
    // find the repo that owns this file
    const repo = repoOf(f.path);
    const rel = repo ? path.relative(repo, f.path) : f.path;
    const d = repo ? gitLib.fileDiff(repo, rel, base) : '';
    if (!d) continue;
    if (total + d.length > DIFF_BUDGET) { dropped.push(rel); continue; }
    total += d.length;
    parts.push(d);
  }
  return { diff: parts.join('\n'), dropped, bytes: total };
}

function repoOf(absPath) {
  const out = spawnSync('git', ['-C', path.dirname(absPath), 'rev-parse', '--show-toplevel'],
    { encoding: 'utf8' });
  return out.status === 0 ? out.stdout.trim() : null;
}

/** Fill the {{slots}} in the prompt template from the impact result + diff. */
function buildPrompt(cfg, r, diffInfo) {
  const tpl = fs.readFileSync(TEMPLATE, 'utf8');
  // strip the human-facing header (everything before the SYSTEM section) so we send only the prompt
  const sys = tpl.indexOf('## SYSTEM');
  const body = sys >= 0 ? tpl.slice(sys) : tpl;

  const priorLines = Object.entries(r.priorRegressions)
    .map(([m, hits]) => `  - ${m}: ${hits.join('; ')}`).join('\n') || '  - (none on record)';
  const notes = r.direct.map(m => modules[m] && modules[m].notes ? `  - ${m}: ${modules[m].notes}` : null)
    .filter(Boolean).join('\n') || '  - (no special notes)';
  const invariants = r.contractsAtRisk.map(c => {
    const d = contracts[c] || {};
    return `  - ${c} [${d.on_break || 'WARN'}]: ${d.invariant || ''}${d.why ? ' — ' + d.why : ''}`;
  }).join('\n') || '  - (none)';

  const fill = {
    risk: r.risk, score: String(r.score),
    blast_modules: r.blast.modules.join(', ') || '(none)',
    contracts_at_risk: r.contractsAtRisk.join(', ') || '(none)',
    blocking: r.blocking.join(', ') || '(none)',
    prior_regressions: priorLines,
    module_notes: notes,
    contract_invariants: invariants,
    diff: diffInfo.diff || '(diff empty or too large — files: ' + r.files.map(f => f.rel).join(', ') + ')'
  };
  let out = body;
  for (const [k, v] of Object.entries(fill)) out = out.split('{{' + k + '}}').join(v);
  if (diffInfo.dropped.length) out += `\n\n> NOTE: ${diffInfo.dropped.length} lower-signal file diffs omitted for size: ${diffInfo.dropped.join(', ')}`;
  return out;
}

/** Main entry called by cli.js. Returns exit code. */
function run(cfg, src, opts, color) {
  const C = color || {};
  const dim = C.dim || (s => s);
  const r = impactLib.analyze(cfg, src);
  const diffInfo = collectDiff(r.files, src.base);
  const prompt = buildPrompt(cfg, r, diffInfo);

  fs.mkdirSync(cfg.outputDir, { recursive: true });
  const reqPath = path.join(cfg.outputDir, 'AI_REVIEW_REQUEST.md');
  fs.writeFileSync(reqPath, prompt);

  console.log('');
  console.log(`  🛡  Aegis AI review pack  ${dim('risk ' + r.risk + ' · ' + r.files.length + ' files · diff ' + Math.round(diffInfo.bytes / 1024) + 'KB')}`);
  console.log(dim(`  request → ${reqPath}`));

  if (!opts.run) {
    console.log('');
    console.log('  Not sent (no --run). To review now, either:');
    console.log(dim('    • paste ' + reqPath + ' into Claude Code, or'));
    console.log(dim('    • node cli.js review --run   (pipes to `claude -p`)'));
    return 0;
  }

  console.log(dim('  running `claude -p` …'));
  const res = spawnSync('claude', ['-p'], { input: prompt, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
  const stdout = (res.stdout || '').trim();
  if (stdout.length < 40) {
    // No usable output. Most common cause: ANTHROPIC_API_KEY set alongside a
    // claude.ai login (auth precedence conflict) — happens when run nested inside
    // a Claude Code session. In a normal terminal `claude -p` uses your login.
    const why = res.stderr ? res.stderr.trim().split('\n')[0] : (res.error ? res.error.message : 'no output');
    console.log('  ⚠ claude CLI returned no review: ' + dim(why));
    console.log(dim('    Fix: run in a terminal where `claude` uses your claude.ai login'));
    console.log(dim('    (unset ANTHROPIC_API_KEY if it conflicts), or paste the pack manually:'));
    console.log(dim('    ' + reqPath));
    return 1;
  }
  res.stdout = stdout;
  const outPath = path.join(cfg.outputDir, 'AI_REVIEW.md');
  fs.writeFileSync(outPath, res.stdout);
  console.log('');
  console.log(res.stdout.trim());
  console.log('');
  console.log(dim(`  review → ${outPath}`));
  return 0;
}

module.exports = { run, buildPrompt, collectDiff };
