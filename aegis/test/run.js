#!/usr/bin/env node
'use strict';
/**
 * Aegis self-test — who watches the watchman.
 * Zero-dependency. A check with a bug gives FALSE confidence, so every check is
 * asserted to (a) catch what it should and (b) pass what it should.
 *
 * Uses temp fixtures for file-based checks so it doesn't depend on the real
 * repos being in any particular state — plus a couple of integration assertions
 * against the real firestore.rules / manifest.
 *
 *   node test/run.js       (or: npm test)
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

// ── tiny test harness ────────────────────────────────────────────────
let pass = 0, fail = 0;
const fails = [];
function ok(name, cond, detail) {
  if (cond) { pass++; process.stdout.write('\x1b[32m.\x1b[0m'); }
  else { fail++; fails.push({ name, detail }); process.stdout.write('\x1b[31mF\x1b[0m'); }
}
function eq(name, a, b) { ok(name, JSON.stringify(a) === JSON.stringify(b), `got ${JSON.stringify(a)} want ${JSON.stringify(b)}`); }

// temp fixture dir
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'aegis-test-'));
function fixture(rel, content) {
  const p = path.join(TMP, rel);
  fs.mkdirSync(path.dirname(p), { recursive: true });
  fs.writeFileSync(p, content);
  return p;
}
function cleanup() { try { fs.rmSync(TMP, { recursive: true, force: true }); } catch (e) {} }

// ── modules under test ───────────────────────────────────────────────
const { load } = require('../lib/config');
const classify = require('../lib/classify');
const graph = require('../lib/graph');
const score = require('../lib/score');
const select = require('../lib/select');
const normalizer = require('../observability/normalizer');
const { modules } = require('../manifest');
const rulesCheck = require('../contracts/rules_deny_default');
const claimCheck = require('../contracts/claim_key');
const pushCheck = require('../contracts/push_mark_registry');

const cfg = load();

// ── 1. classify ──────────────────────────────────────────────────────
(function () {
  const c = (p) => classify.modulesForPath(p);
  ok('classify: Attendance.php → attendance', c('/x/controllers/Attendance.php').includes('attendance'));
  ok('classify: firestore boundary — no "stories" from firestore', !c('/x/firebase-rules/firestore.rules').includes('stories'));
  ok('classify: ExampleTest.kt is NOT exam (boundary)', !c('/x/app/ExampleInstrumentedTest.kt').includes('exam'));
  ok('classify: AttendanceRepository.kt → attendance (CamelCase boundary)', c('/x/data/AttendanceRepository.kt').includes('attendance'));
  eq('classify: kindOf rules', classify.kindOf('/x/firestore.rules'), 'rules');
  eq('classify: kindOf viewmodel', classify.kindOf('/x/ui/FooViewModel.kt'), 'viewmodel');
  const f = classify.classifyFile(cfg, '/x/firebase-rules/firestore.rules');
  eq('classify: rules file has empty modules (handled by contract)', f.modules, []);
})();

// ── 2. graph ─────────────────────────────────────────────────────────
(function () {
  const b = graph.blastRadius(['attendance']);
  ok('graph: attendance blast includes payroll', b.modules.includes('payroll'));
  ok('graph: attendance blast does NOT include all 20 (no wildcard leak)', b.modules.length < Object.keys(modules).length);
  const bp = graph.blastRadius(['push']);
  ok('graph: push (consumers:*) reaches everything', bp.modules.length === Object.keys(modules).length);
  // integrity: no dangling edges (same assertion as doctor)
  const names = new Set(Object.keys(modules));
  let dangling = 0;
  for (const def of Object.values(modules))
    for (const t of [...(def.regression_targets || []), ...(def.consumers || [])])
      if (t !== '*' && !names.has(t)) dangling++;
  eq('graph: manifest has zero dangling edges', dangling, 0);
})();

// ── 3. rules_deny_default ────────────────────────────────────────────
(function () {
  const worldOpen = fixture('open.rules', `service cloud.firestore {\n match /db/{d} {\n allow read, write: if true;\n }\n}`);
  const denied    = fixture('deny.rules', `service cloud.firestore {\n match /db/{d} {\n allow write: if false;\n allow read: if isSameSchool();\n }\n}`);
  const suspicious= fixture('sus.rules',  `service cloud.firestore {\n match /db/{d} {\n allow read: if someVar;\n }\n}`);

  eq('rules: `if true` → fail (BLOCK)', rulesCheck.check({ ...cfg, firestoreRules: worldOpen }).status, 'fail');
  eq('rules: `if false` + helper() → pass', rulesCheck.check({ ...cfg, firestoreRules: denied }).status, 'pass');
  eq('rules: bare variable → warn', rulesCheck.check({ ...cfg, firestoreRules: suspicious }).status, 'warn');
  ok('rules: check is marked blocking', rulesCheck.blocking === true);

  // integration: the REAL rules file passes clean
  const real = rulesCheck.check(cfg);
  ok('rules: real firestore.rules passes clean (0 errors)', real.status !== 'fail', real.note);
})();

// ── 4. claim_key ─────────────────────────────────────────────────────
(function () {
  // custom cfg pointing admin root at a fixture tree; manifest breakers are relative paths
  const adminRoot = path.join(TMP, 'adminA');
  fixture('adminA/application/core/MY_Controller.php',
    `<?php // $claims = ['school_id'=>$x]; setCustomUserClaims(...);\n`);
  const cfgMissing = JSON.parse(JSON.stringify(cfg));
  cfgMissing.surfaces.admin.root = adminRoot;
  eq('claim_key: writes school_id but NOT schoolId → fail', claimCheck.check(cfgMissing).status, 'fail');

  const adminRootOk = path.join(TMP, 'adminB');
  fixture('adminB/application/core/MY_Controller.php',
    `<?php // $claims = ['school_id'=>$x,'schoolId'=>$x]; setCustomUserClaims(...);\n`);
  const cfgOk = JSON.parse(JSON.stringify(cfg));
  cfgOk.surfaces.admin.root = adminRootOk;
  eq('claim_key: dual-emit both keys → pass', claimCheck.check(cfgOk).status, 'pass');

  const adminRootNone = path.join(TMP, 'adminC');
  fixture('adminC/application/core/MY_Controller.php', `<?php // reads session only, no claims\n`);
  const cfgNone = JSON.parse(JSON.stringify(cfg));
  cfgNone.surfaces.admin.root = adminRootNone;
  eq('claim_key: non-writer file → skip', claimCheck.check(cfgNone).status, 'skip');
})();

// ── 5. push_mark_registry (double-send detection) ────────────────────
(function () {
  const a = fixture('senderA.js', `function x(){ emit_push({ mark: 'STORY_POSTED' }); }`);
  const b = fixture('senderB.js', `function y(){ sendPush('STORY_POSTED'); }`);
  const ctx = { changed: [
    { path: a, kind: 'js' },
    { path: b, kind: 'js' }
  ] };
  const r = pushCheck.check(cfg, ctx);
  ok('push: same mark from 2 senders → warns double-send',
     r.findings.some(f => /DOUBLE-SEND/.test(f.msg)), JSON.stringify(r.findings));
})();

// ── 6. normalizer ────────────────────────────────────────────────────
(function () {
  const e = normalizer.parseLine('[AEGIS.attendance] event=marked schoolId=SCH_1 ms=42 ok=true');
  ok('normalizer: lowercase module prefix parses', e && e.module === 'attendance', JSON.stringify(e));
  eq('normalizer: numeric ms coerced', e && e.ms, 42);
  eq('normalizer: ok boolean coerced', e && e.ok, true);
  ok('normalizer: unstructured line → null', normalizer.parseLine('just a log') === null);
  const roll = normalizer.rollup([
    { module: 'push', ok: false, level: 'error', ms: 100 },
    { module: 'push', ok: true, level: 'info', ms: 200 }
  ]);
  const p = roll.find(r => r.module === 'push');
  eq('normalizer: rollup errorRate', p && p.errorRate, 0.5);
  eq('normalizer: rollup health', p && p.health, 50);
})();

// ── 7. score + select ────────────────────────────────────────────────
(function () {
  const classified = [{ surface: 'admin', kind: 'rules', modules: [] }];
  const blast = { modules: [], surfaces: new Set(['admin']) };
  const s = score.scoreChange({ classified, blast, contractsAtRisk: ['rules_deny_default'], churnLines: 0, priorHits: 0 });
  ok('score: touching rules yields HIGH+ band', ['HIGH', 'CRITICAL'].includes(s.band), s.band);
  ok('score: rules_deny_default counts as blocking', s.blockingContracts.includes('rules_deny_default'));

  const low = select.selectSuite('LOW', { rulesTouched: false, regressionTargets: [] });
  eq('select: LOW skips e2e', low.e2e, []);
  const crit = select.selectSuite('CRITICAL', { rulesTouched: true, regressionTargets: ['payroll'] });
  eq('select: CRITICAL requires manual UAT', crit.manualUAT, 'required');
  ok('select: CRITICAL smoke = ALL', crit.smoke.includes('*'));
})();

// ── 7b. presentation-only layer-awareness ────────────────────────────
(function () {
  const impact = require('../lib/impact');
  const view = fixture('adm/application/views/admission_crm/pipeline.php', '<?php // pipeline board UI ?>');
  const ctrl = fixture('adm/application/controllers/Admission_public.php', '<?php class Admission_public { function submit(){} } ?>');

  const vOnly = impact.analyze(cfg, { files: [view] });
  ok('presentation: views-only flagged presentationOnly', vOnly.presentationOnly === true);
  ok('presentation: views-only blast limited to own module (no consumer fan-out)', vOnly.blast.modules.length <= 2, JSON.stringify(vOnly.blast.modules));
  ok('presentation: views-only drops logic contracts (no payment_verify)', !vOnly.contractsAtRisk.includes('payment_verify'));
  ok('presentation: views-only not CRITICAL', vOnly.risk !== 'CRITICAL', vOnly.risk);

  const withCtrl = impact.analyze(cfg, { files: [view, ctrl] });
  ok('presentation: adding a controller turns dampener OFF', withCtrl.presentationOnly === false);
  ok('presentation: controller change re-exposes payment_verify', withCtrl.contractsAtRisk.includes('payment_verify'));
})();

// ── 7c. pending-push + PARTIAL-PUSH detection (real temp git repo) ───
(function () {
  const { execFileSync } = require('child_process');
  const pending = require('../lib/pending');
  const repo = path.join(TMP, 'gitrepo');
  fs.mkdirSync(path.join(repo, 'application/controllers'), { recursive: true });
  const g = (args) => { try { execFileSync('git', ['-C', repo, ...args], { stdio: ['ignore', 'pipe', 'ignore'] }); } catch (e) {} };
  g(['init', '-q']); g(['config', 'user.email', 't@t']); g(['config', 'user.name', 't']);
  fs.writeFileSync(path.join(repo, 'README.md'), 'x'); g(['add', '.']); g(['commit', '-q', '-m', 'base']);
  let base = '';
  try { base = execFileSync('git', ['-C', repo, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(); } catch (e) {}

  // SHIP: commit one attendance file
  fs.writeFileSync(path.join(repo, 'application/controllers/Attendance.php'), '<?php');
  g(['add', '.']); g(['commit', '-q', '-m', 'attendance part 1']);
  // LEFT BEHIND: leave another attendance file uncommitted
  fs.writeFileSync(path.join(repo, 'application/controllers/Attendance_helper.php'), '<?php');

  if (!base) { ok('partial: skipped (git unavailable)', true); return; }
  const p = pending.analyze(cfg, { repo, base });
  const att = p.rows.find(r => r.module === 'attendance');
  ok('partial: attendance row present', !!att);
  eq('partial: 1 committed (ships)', att && att.ship, 1);
  eq('partial: 1 uncommitted (left behind)', att && att.local, 1);
  ok('partial: flagged as SPLIT', att && att.split === true);
  ok('partial: splitModules surfaces attendance', p.splitModules.some(r => r.module === 'attendance'));
  ok('partial: split rows sort first', !p.rows.length || p.rows[0].split || p.splitModules.length === 0);
})();

// ── 8. health snapshot ───────────────────────────────────────────────
(function () {
  const health = require('../lib/health');
  const snap = health.build(cfg);
  ok('health: integrityHealth is 0–100', typeof snap.system.integrityHealth === 'number' && snap.system.integrityHealth >= 0 && snap.system.integrityHealth <= 100, String(snap.system.integrityHealth));
  ok('health: deployReady is boolean', typeof snap.system.deployReady === 'boolean');
  eq('health: one contract tile per check', snap.contracts.length, 6);
  ok('health: surfaces present', Array.isArray(snap.surfaces) && snap.surfaces.length > 0);
  ok('health: runtime marked unavailable (no telemetry CF yet)', snap.runtime.available === false);
})();

// ── rules sentinel ───────────────────────────────────────────────────
require('./rules.test')({ ok, eq });
require('./indexes.test')({ ok, eq });

// ── report ───────────────────────────────────────────────────────────
process.stdout.write('\n\n');
if (fail) {
  console.log('\x1b[31m✗ ' + fail + ' failed\x1b[0m, ' + pass + ' passed\n');
  fails.forEach(f => console.log('  \x1b[31m✗\x1b[0m ' + f.name + '\n    ' + (f.detail || '')));
  cleanup();
  process.exit(1);
} else {
  console.log('\x1b[32m✓ all ' + pass + ' assertions passed\x1b[0m');
  cleanup();
  process.exit(0);
}
