#!/usr/bin/env node
'use strict';
const fs = require('fs');
const path = require('path');
const cfgLib = require('./lib/config');
const impact = require('./lib/impact');
const report = require('./lib/report');
const graph = require('./lib/graph');
const contractsRunner = require('./contracts');
const { modules, contracts } = require('./manifest');

// ── tiny arg parser ────────────────────────────────────────────────
function parseArgs(argv) {
  const out = { _: [], files: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--files') { while (argv[i + 1] && !argv[i + 1].startsWith('--')) out.files.push(argv[++i]); }
    else if (a === '--only') { out.only = (argv[++i] || '').split(',').filter(Boolean); }
    else if (a.startsWith('--')) {
      const key = a.slice(2);
      if (argv[i + 1] && !argv[i + 1].startsWith('--')) out[key] = argv[++i];
      else out[key] = true;
    } else out._.push(a);
  }
  return out;
}

// ── color ──────────────────────────────────────────────────────────
const noColor = process.env.NO_COLOR || !process.stdout.isTTY;
const c = (code, s) => noColor ? s : `\x1b[${code}m${s}\x1b[0m`;
const C = {
  dim: s => c(2, s), bold: s => c(1, s), red: s => c(31, s), green: s => c(32, s),
  yellow: s => c(33, s), cyan: s => c(36, s), mag: s => c(35, s)
};
const STATUS_COLOR = { pass: C.green, warn: C.yellow, fail: C.red, error: C.red, skip: C.dim };
const RISK_COLOR = { CRITICAL: C.red, HIGH: C.red, MEDIUM: C.yellow, LOW: C.green };

// ── resolve change source ──────────────────────────────────────────
function resolveSource(cfg, args) {
  if (args.files && args.files.length) return { files: args.files };
  const repo = args.repo || cfg.surfaces.teacher.gitRepo || cfg._root;
  let base = args.base;
  if (base === undefined) {
    // prefer origin/main, else main, else no base (working-tree diff)
    const g = require('./lib/git').git;
    if (g(repo, ['rev-parse', '--verify', 'origin/main'])) base = 'origin/main';
    else if (g(repo, ['rev-parse', '--verify', 'main'])) base = 'main';
    else base = null;
  }
  return { repo, base };
}

// ── commands ────────────────────────────────────────────────────────
function cmdImpact(cfg, args) {
  const src = resolveSource(cfg, args);
  const r = impact.analyze(cfg, src);
  if (args.json) { console.log(JSON.stringify(r, replacer, 2)); return 0; }

  const md = report.renderMarkdown(r);
  // write report artifact
  try {
    fs.mkdirSync(cfg.outputDir, { recursive: true });
    const out = args.out || path.join(cfg.outputDir, 'IMPACT_REPORT.md');
    fs.writeFileSync(out, md);
    printImpact(r, src);
    console.log(C.dim(`\n  report → ${out}`));
  } catch (e) { printImpact(r, src); }
  return 0;
}

function printImpact(r, src) {
  const rc = RISK_COLOR[r.risk] || C.dim;
  console.log('');
  console.log(`  ${C.bold('🛡  Aegis Impact')}  ${rc(C.bold(r.risk))}  ${C.dim('score ' + r.score)}  ${C.dim('· ' + r.confidence)}`);
  console.log(C.dim(`  ${r.fileCount} files · surfaces: ${[...r.surfaces].join(', ') || '—'}${src.base ? ' · base ' + src.base : ''}`));
  if (r.presentationOnly) console.log(C.dim(`  presentation-only — blast limited to own screens; verify output escaping (XSS) + form fields`));
  console.log('');
  if (r.blocking.length) {
    console.log(`  ${C.red(C.bold('⛔ BLOCKING'))}`);
    r.blocking.forEach(k => console.log(`     ${C.red('•')} ${k} — ${(contracts[k] || {}).invariant || ''}`));
    console.log('');
  }
  console.log(`  ${C.cyan('blast radius')}  ${r.blast.modules.map(m => r.direct.includes(m) ? C.bold(m) : C.dim(m)).join(' · ') || '—'}`);
  if (r.contractsAtRisk.length)
    console.log(`  ${C.cyan('contracts')}     ${r.contractsAtRisk.map(k => (contracts[k] && contracts[k].on_break === 'BLOCK') ? C.red(k) : C.yellow(k)).join(' · ')}`);
  const smoke = r.suite.smoke.includes('*') ? 'ALL' : (r.suite.smoke.join(', ') || '—');
  console.log(`  ${C.cyan('verify')}        static ${tick(r.suite.static)} · contract ${tick(r.suite.contract)} · rules-unit ${tick(r.suite.rulesUnit)} · smoke: ${smoke}`);
  console.log(`  ${C.cyan('manual UAT')}    ${r.suite.manualUAT}`);
  if (Object.keys(r.priorRegressions).length) {
    console.log('');
    console.log(`  ${C.yellow('⚠ regression memory')}`);
    for (const [m, hits] of Object.entries(r.priorRegressions))
      console.log(`     ${C.yellow(m)}: ${hits[0]}`);
  }
  if (r.unmapped.length) {
    console.log('');
    console.log(`  ${C.mag('🕳 unmapped (impact unknown — review):')} ${r.unmapped.map(f => f.rel).join(', ')}`);
  }
}
const tick = b => b ? C.green('✓') : C.dim('—');

function cmdVerify(cfg, args) {
  const src = resolveSource(cfg, args);
  const r = impact.analyze(cfg, src);
  const res = contractsRunner.run(cfg, { changed: r.files, only: args.only });
  if (args.json) { console.log(JSON.stringify(res, replacer, 2)); return res.failed.length ? 1 : 0; }
  printChecks(res);
  const bad = res.failed.length;
  console.log('');
  console.log(bad
    ? `  ${C.red(C.bold('✗ verify FAILED'))} — ${bad} check(s) failed (${res.blocked.length} blocking)`
    : `  ${C.green(C.bold('✓ verify passed'))}${res.warned.length ? C.yellow(' · ' + res.warned.length + ' warning(s)') : ''}`);
  return bad ? 1 : 0;
}

function printChecks(res) {
  console.log('');
  for (const r of res.results) {
    const col = STATUS_COLOR[r.status] || C.dim;
    const badge = r.blocking ? C.dim('[block]') : C.dim('[warn ]');
    console.log(`  ${col(pad(r.status.toUpperCase(), 5))} ${badge} ${C.bold(r.id)}  ${C.dim(r.note)}`);
    r.findings.forEach(f => {
      const fc = f.level === 'error' ? C.red : f.level === 'warn' ? C.yellow : C.dim;
      const loc = f.file ? C.dim(`${path.basename(f.file)}${f.line ? ':' + f.line : ''}`) : '';
      console.log(`        ${fc('›')} ${f.msg} ${loc}`);
    });
  }
}

function cmdGate(cfg, args) {
  const src = resolveSource(cfg, args);
  console.log(C.bold('\n  🛡  Aegis Release Gate\n'));
  const r = impact.analyze(cfg, src);
  const res = contractsRunner.run(cfg, { changed: r.files });
  printChecks(res);

  const rulesTouched = r.files.some(f => f.kind === 'rules');
  console.log('');
  console.log(`  ${C.cyan('risk')} ${(RISK_COLOR[r.risk] || C.dim)(r.risk)}  ${C.cyan('blast')} ${r.blast.modules.length} modules`);
  if (rulesTouched) console.log(`  ${C.yellow('⚠ firestore.rules changed — ships whole file. Re-read + diff vs origin before deploy.')}`);

  const hardStop = res.blocked.length > 0;
  console.log('');
  if (hardStop) {
    console.log(`  ${C.red(C.bold('⛔ GATE BLOCKED'))} — ${res.blocked.map(b => b.id).join(', ')}`);
    console.log(C.dim('     Fix blocking contracts before deploy. This is the one hard-stop.'));
    return 1;
  }
  console.log(`  ${C.green(C.bold('✓ GATE PASSED'))}${res.warned.length ? C.yellow(' · ' + res.warned.length + ' warning(s) to review') : ''}`);
  if (r.risk === 'CRITICAL' || r.risk === 'HIGH')
    console.log(C.dim(`     ${r.risk} risk — run suggested smoke suite + manual UAT before shipping.`));
  return 0;
}

function cmdPending(cfg, args) {
  const src = resolveSource(cfg, args);
  if (!src.repo) { console.log(C.red('  pending needs a git repo (use --repo)')); return 2; }
  const p = require('./lib/pending').analyze(cfg, src);
  if (args.json) { console.log(JSON.stringify(p, replacer, 2)); return 0; }

  console.log('');
  console.log(`  ${C.bold('🛡  Pending push')} ${C.dim('→ ' + p.base)}`);
  console.log(C.dim(`  ${p.shipFiles} committed (ship on push) · ${p.localFiles} uncommitted (stay local) · ${p.moduleCount} modules`));

  // THE partial-push warning — a module shipping SOME files but leaving others behind.
  if (p.splitModules.length) {
    console.log('');
    console.log(`  ${C.red(C.bold('⚠ PARTIAL PUSH — half a change may ship:'))}`);
    for (const r of p.splitModules) {
      console.log(`     ${C.red('•')} ${C.bold(r.module)}: ${C.green(r.ship + ' committed')} but ${C.yellow(r.local + ' still uncommitted')} — commit the rest or you ship a partial ${r.module} change.`);
    }
  }

  console.log('');
  const rc = { CRITICAL: C.red, HIGH: C.red, MEDIUM: C.yellow, LOW: C.green };
  console.log('  ' + C.dim('RISK      MODULE          SHIP  LOCAL  KINDS               CONTRACTS'));
  for (const r of p.rows) {
    const risk = (rc[r.level] || C.dim)(pad(r.level, 9));
    const split = r.split ? C.red(' ⚠SPLIT') : '';
    const flags = (r.p0 ? C.red(' P0') : '') + (r.blockContracts.length ? C.red(' ⛔' + r.blockContracts.join(',')) : '');
    const contractStr = r.contracts.length ? r.contracts.join(',') : C.dim('—');
    const ship = r.ship ? C.green(pad(r.ship, 5)) : C.dim(pad('0', 5));
    const local = r.local ? C.yellow(pad(r.local, 6)) : C.dim(pad('0', 6));
    console.log(`  ${risk} ${C.bold(pad(r.module, 15))} ${ship} ${local} ${pad(r.kinds, 19)} ${contractStr}${flags}${split}`);
  }
  const unmapped = [...(p.unmappedShip || []), ...(p.unmappedLocal || [])];
  if (unmapped.length) {
    console.log('');
    console.log(`  ${C.mag('🕳 ' + unmapped.length + ' unmapped file(s)')} ${C.dim('— review: ' + unmapped.slice(0, 5).map(f => require('path').basename(f.rel || f.path)).join(', ') + (unmapped.length > 5 ? '…' : ''))}`);
  }
  console.log('');
  console.log(C.dim(`  👉 ⚠SPLIT = you committed part of a module but left files uncommitted (partial push).`));
  console.log(C.dim(`     Green SHIP column = what actually leaves on 'git push'.`));
  return 0;
}

function cmdDashboard(cfg, args) {
  const health = require('./lib/health');
  const snap = health.build(cfg);
  snap.generatedAt = new Date().toISOString().replace('T', ' ').slice(0, 16) + ' UTC';

  fs.mkdirSync(cfg.outputDir, { recursive: true });
  const jsonPath = path.join(cfg.outputDir, 'aegis_health.json');
  fs.writeFileSync(jsonPath, JSON.stringify(snap, replacer, 2));

  const tpl = fs.readFileSync(path.join(cfg._root, 'observability', 'dashboard.template.html'), 'utf8');
  const html = tpl.replace('__AEGIS_DATA__', JSON.stringify(snap, replacer));
  const outPath = args.out || path.join(cfg.outputDir, 'dashboard.html');
  fs.writeFileSync(outPath, html);

  if (args.json) { console.log(JSON.stringify(snap, replacer, 2)); return 0; }
  const rc = RISK_COLOR[snap.system.integrityHealth >= 95 ? 'LOW' : snap.system.integrityHealth >= 85 ? 'MEDIUM' : 'HIGH'] || C.dim;
  console.log('');
  console.log(`  ${C.bold('🛡  Aegis Health')}  ${rc(C.bold(snap.system.integrityHealth + '%'))}  ${snap.system.deployReady ? C.green('deploy-ready') : C.red('NOT deploy-ready')}`);
  console.log(C.dim(`  ${snap.changeSet.totalFiles} changed files · ${snap.modules.highRisk.length} high-risk modules · ${snap.contracts.filter(c => c.status === 'warn').length} contract warnings`));
  if (snap.system.blocking.length) console.log(`  ${C.red('⛔ blocking: ' + snap.system.blocking.join(', '))}`);
  console.log('');
  console.log(C.dim(`  data → ${jsonPath}`));
  console.log(`  open → ${C.cyan('file://' + outPath)}`);
  return 0;
}

function cmdReview(cfg, args) {
  const src = resolveSource(cfg, args);
  const reviewer = require('./ai/review');
  return reviewer.run(cfg, src, { run: !!args.run }, C);
}

function cmdGraph(cfg, args) {
  const mod = args._[1];
  if (!mod) {
    console.log(C.bold('\n  Modules:\n'));
    for (const [name, def] of Object.entries(modules))
      console.log(`  ${C.cyan(pad(name, 12))} ${C.dim((def.surfaces || []).join(','))}  → ${(def.regression_targets || []).join(', ')}`);
    return 0;
  }
  if (!modules[mod]) { console.log(C.red(`  unknown module: ${mod}`)); return 1; }
  const b = graph.blastRadius([mod]);
  console.log(`\n  ${C.bold(mod)} blast radius: ${b.modules.length} modules across ${b.surfaces.size} surfaces`);
  console.log(`  ${C.cyan('modules')}   ${b.modules.join(' · ')}`);
  console.log(`  ${C.cyan('surfaces')}  ${[...b.surfaces].join(', ')}`);
  console.log(`  ${C.cyan('contracts')} ${(modules[mod].contracts || []).join(', ') || '—'}`);
  if (modules[mod].notes) console.log(`  ${C.dim(modules[mod].notes)}`);
  return 0;
}

function cmdDoctor(cfg) {
  console.log(C.bold('\n  🛡  Aegis Doctor\n'));
  let ok = true;
  const check = (label, cond, detail) => {
    console.log(`  ${cond ? C.green('✓') : C.red('✗')} ${pad(label, 22)} ${C.dim(detail || '')}`);
    if (!cond) ok = false;
  };
  // Where the config actually came from. Without this, a colleague debugging a
  // wrong path cannot tell whether their local override was even loaded.
  console.log(`  ${C.dim('config    ' + cfg._configPath)}`);
  console.log(`  ${C.dim('override  ' + (cfg._localConfigPath || 'none (using committed defaults)'))}`);
  const envKeys = Object.keys(process.env).filter(k => k.startsWith('AEGIS_'));
  if (envKeys.length) console.log(`  ${C.dim('env       ' + envKeys.join(', '))}`);
  console.log('');

  for (const [k, s] of Object.entries(cfg.surfaces)) check(`surface: ${k}`, fs.existsSync(s.root), s.root);
  check('firestore.rules', fs.existsSync(cfg.firestoreRules), cfg.firestoreRules);
  // The live axis is optional but its absence must be visible: without the key
  // Aegis silently degrades to git-only, which is the state it exists to fix.
  const sa = cfg.firebase && cfg.firebase.serviceAccount;
  check('firebase SA (live)', !!sa && fs.existsSync(sa),
    sa ? (fs.existsSync(sa) ? sa : sa + ' — MISSING: live rules unavailable') : 'not configured — live rules unavailable');
  check('firestore.indexes', fs.existsSync(cfg.firestoreIndexes), cfg.firestoreIndexes + (fs.existsSync(cfg.firestoreIndexes) ? '' : ' (optional)'));
  check('BUG_LEDGER', fs.existsSync(cfg.bugLedger), cfg.bugLedger);
  check('manifest modules', Object.keys(modules).length > 0, Object.keys(modules).length + ' modules');
  check('contracts', Object.keys(contracts).length > 0, Object.keys(contracts).length + ' contracts');
  check('contract checks', contractsRunner.CHECKS.length === 6, contractsRunner.CHECKS.length + ' checks loaded');
  // manifest integrity: every regression_target / consumer resolves to a real module (or '*')
  const names = new Set(Object.keys(modules));
  let dangling = [];
  for (const [m, def] of Object.entries(modules))
    for (const t of [...(def.regression_targets || []), ...(def.consumers || [])])
      if (t !== '*' && !names.has(t)) dangling.push(`${m}→${t}`);
  check('graph integrity', dangling.length === 0, dangling.length ? 'dangling: ' + dangling.join(', ') : 'all edges resolve');
  console.log('');
  console.log(ok ? C.green('  ✓ all systems nominal') : C.red('  ✗ issues found — fix paths in aegis.config.json'));
  return ok ? 0 : 1;
}

/**
 * `aegis setup` — onboard a machine that is not the one Aegis was written on.
 *
 * Detects where each repo actually lives, writes the gitignored local override,
 * and installs the subagent + hook from the tracked copies in `share/`.
 * `--force` overwrites an existing agent/hook; `--dry` shows the plan only.
 */
function cmdSetup(cfg, args) {
  const setup = require('./lib/setup');
  const dry = !!args.dry, force = !!args.force;
  console.log(C.bold('\n  🛡  Aegis Setup\n'));

  const detected = setup.detect();
  console.log(C.bold('  Detected repos'));
  for (const [key, dir] of Object.entries(detected.found)) {
    console.log(`  ${dir ? C.green('✓') : C.yellow('?')} ${pad(key, 10)} ${dir ? C.dim(dir) : C.yellow('not found — set it by hand below')}`);
  }
  console.log(`  ${detected.serviceAccount ? C.green('✓') : C.yellow('?')} ${pad('firebase SA', 10)} ${detected.serviceAccount ? C.dim(detected.serviceAccount) : C.yellow('not found — live rules will be unavailable')}`);

  const base = JSON.parse(fs.readFileSync(setup.CONFIG_PATH, 'utf8'));
  const local = setup.buildLocal(detected, base);

  console.log('');
  if (!Object.keys(local).length) {
    console.log(`  ${C.green('✓')} every path already matches the committed config — no local override needed.`);
  } else if (dry) {
    console.log(C.bold('  Would write ') + C.dim(setup.LOCAL_CONFIG_PATH));
    console.log(C.dim(JSON.stringify(local, null, 2).split('\n').map(l => '    ' + l).join('\n')));
  } else {
    const w = setup.writeLocal(local);
    console.log(`  ${C.green('✓')} wrote ${C.dim(w.path)} ${C.dim('(gitignored)')}`);
  }

  if (!dry) {
    const outerRepo = path.resolve(cfg._root, '..');
    const a = setup.installAgent(outerRepo, { force });
    console.log(a.ok
      ? `  ${C.green('✓')} subagent  ${C.dim(a.dest)}${a.skipped ? C.yellow('  (exists — kept; --force to overwrite)') : ''}`
      : `  ${C.red('✗')} subagent  ${a.reason}`);
    const h = setup.installHook({ force });
    console.log(h.ok
      ? `  ${C.green('✓')} hook      ${C.dim(h.dest)}${h.skipped ? C.yellow('  (exists — kept; --force to overwrite)') : ''}`
      : `  ${C.red('✗')} hook      ${h.reason}`);

    if (!setup.hookWired()) {
      console.log(`\n  ${C.yellow('⚠ the hook is installed but not wired.')} Add to ${C.dim('~/.claude/settings.json')}:`);
      console.log(C.dim(setup.HOOK_SNIPPET.split('\n').map(l => '    ' + l).join('\n')));
      console.log(C.dim('    (Aegis does not edit your settings.json — that file is yours.)'));
    } else {
      console.log(`  ${C.green('✓')} hook wired in ~/.claude/settings.json`);
    }
  }

  console.log(`\n  ${C.cyan('next')}  node cli.js doctor        ${C.dim('— confirm every path resolves')}`);
  console.log(`        node cli.js rules status  ${C.dim('— confirm live + remote are readable')}`);
  console.log('');
  return 0;
}

function help() {
  console.log(`
  ${C.bold('🛡  ZenXii Aegis')} — change-impact & regression-defense

  ${C.cyan('aegis setup')}    [--dry]        onboard this machine: detect repos, install agent + hook
  ${C.cyan('aegis doctor')}                 validate config paths + manifest integrity
  ${C.cyan('aegis impact')}  [opts]         analyze a change: risk, blast radius, suite
  ${C.cyan('aegis verify')}  [opts]         run contract checks on a change
  ${C.cyan('aegis gate')}    [opts]         release gate (hard-stops on blocking contracts)
  ${C.cyan('aegis pending')} [opts]         group ALL unpushed work by module (what ships on next push)
  ${C.cyan('aegis review')}  [opts] [--run] AI review pack; --run pipes it to Claude Code
  ${C.cyan('aegis dashboard')}              generate the health dashboard (HTML) from real repo state
  ${C.cyan('aegis graph')}   [module]       show a module's blast radius (or list all)
  ${C.cyan('aegis indexes')} <sub>          Firestore Index Sentinel (see: aegis indexes help)
                                live-vs-git composite indexes; not-deployed = failing queries
  ${C.cyan('aegis rules')}   <sub>          Firestore Rules Sentinel (see: aegis rules help)
                                live-vs-HEAD-vs-disk drift, block leases, deploy plan

  ${C.dim('options')}
    --repo <path>     git repo to diff (default: AndroidStudioProjects)
    --base <ref>      base ref (default: origin/main → main → working tree)
    --files a b c     analyze explicit files instead of a git diff
    --only id,id      verify: run only these contract checks
    --json            machine-readable output
    --out <path>      impact: write report to a path
`);
}

// ── helpers ─────────────────────────────────────────────────────────
function pad(s, n) { s = String(s); return s.length >= n ? s : s + ' '.repeat(n - s.length); }
function replacer(k, v) { return v instanceof Set ? [...v] : v; }

// ── main ────────────────────────────────────────────────────────────
async function main() {
  const args = parseArgs(process.argv.slice(2));
  const cmd = args._[0] || 'help';
  let cfg;
  try { cfg = cfgLib.load(); }
  catch (e) { console.error(C.red('  cannot load aegis.config.json: ' + e.message)); process.exit(2); }

  // `rules` owns its own flag grammar (targets, --note, --session) and is the
  // only async command — it reads the live ruleset over the network. Hand it
  // the raw argv rather than the pre-parsed shape.
  if (cmd === 'indexes') {
    const code = await require('./indexes').run(cfg, process.argv.slice(3));
    process.exitCode = code;
    return;
  }
  if (cmd === 'rules') {
    const code = await require('./rules').run(cfg, process.argv.slice(3));
    // NOT process.exit(): when stdout is a pipe, writes are async and exiting
    // immediately truncates them at the pipe buffer (~64KB). `rules ... --json`
    // is well over that, so the JSON arrived unparseable. Setting exitCode lets
    // node drain stdout and exit on its own.
    process.exitCode = code;
    return;
  }

  let code = 0;
  switch (cmd) {
    case 'impact': code = cmdImpact(cfg, args); break;
    case 'verify': code = cmdVerify(cfg, args); break;
    case 'gate':   code = cmdGate(cfg, args); break;
    case 'review': code = cmdReview(cfg, args); break;
    case 'pending': code = cmdPending(cfg, args); break;
    case 'dashboard': code = cmdDashboard(cfg, args); break;
    case 'graph':  code = cmdGraph(cfg, args); break;
    case 'doctor': code = cmdDoctor(cfg); break;
    case 'setup':  code = cmdSetup(cfg, args); break;
    case 'help': case '--help': case '-h': help(); break;
    default: console.error(C.red(`  unknown command: ${cmd}`)); help(); code = 2;
  }
  process.exit(code);
}
main().catch((e) => { console.error(C.red('  ' + (e && e.stack || e))); process.exit(2); });
