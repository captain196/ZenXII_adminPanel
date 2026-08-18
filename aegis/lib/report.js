'use strict';
const { contracts, modules } = require('../manifest');

const ICON = { CRITICAL: '⛔', HIGH: '🔴', MEDIUM: '🟡', LOW: '🟢' };

/** Render the impact result to a markdown IMPACT_REPORT. */
function renderMarkdown(r) {
  const L = [];
  L.push(`# 🛡️ Aegis Impact Report`);
  L.push('');
  L.push(`**Risk:** ${ICON[r.risk] || ''} \`${r.risk}\`  ·  **Score:** ${r.score}  ·  **Confidence:** ${r.confidence}`);
  L.push(`**Files changed:** ${r.fileCount}  ·  **Surfaces:** ${[...r.surfaces].join(', ') || '—'}`);
  L.push('');

  if (r.blocking.length) {
    L.push(`## ⛔ BLOCKING — release gate will stop this`);
    r.blocking.forEach(c => {
      const def = contracts[c] || {};
      L.push(`- **${c}** — ${def.invariant || ''}`);
      if (def.why) L.push(`  - _why:_ ${def.why}`);
    });
    L.push('');
  }

  L.push(`## Blast radius (${r.blast.modules.length} modules)`);
  L.push(r.blast.modules.map(m => `\`${m}\``).join(' · ') || '_none mapped_');
  L.push('');

  if (r.contractsAtRisk.length) {
    L.push(`## Contracts at risk`);
    r.contractsAtRisk.forEach(c => {
      const def = contracts[c] || {};
      const tag = def.on_break === 'BLOCK' ? '⛔ BLOCK' : '⚠️ WARN';
      L.push(`- ${tag} **${c}** — ${def.invariant || ''}`);
    });
    L.push('');
  }

  L.push(`## Suggested test suite`);
  const s = r.suite;
  L.push(`- static: ${s.static ? '✓' : '—'}  ·  contract: ${s.contract ? '✓' : '—'}  ·  rules-unit: ${s.rulesUnit ? '✓' : '—'}`);
  L.push(`- smoke: ${fmtList(s.smoke)}`);
  L.push(`- e2e: ${fmtList(s.e2e)}`);
  L.push(`- manual UAT: **${s.manualUAT}**`);
  L.push('');

  if (Object.keys(r.priorRegressions).length) {
    L.push(`## ⚠️ Regression memory (BUG_LEDGER)`);
    for (const [mod, hits] of Object.entries(r.priorRegressions)) {
      L.push(`- **${mod}** touched before:`);
      hits.forEach(h => L.push(`  - ${h}`));
    }
    L.push('');
  }

  if (r.unmapped.length) {
    L.push(`## 🕳️ Unmapped files — impact unknown, review manually`);
    r.unmapped.forEach(f => L.push(`- \`${f.rel}\` (${f.kind}, ${f.surface})`));
    L.push('');
  }

  L.push(`## Risk factors`);
  L.push('```');
  for (const [k, v] of Object.entries(r.factors)) if (v) L.push(`${k.padEnd(20)} +${v}`);
  L.push(`${'TOTAL'.padEnd(20)} = ${r.score}  → ${r.risk}`);
  L.push('```');
  L.push('');
  L.push(`## Files`);
  r.files.forEach(f => L.push(`- \`${f.rel}\` — ${f.surface}/${f.kind}${f.modules.length ? ' → ' + f.modules.join(',') : ''}`));

  return L.join('\n');
}

function fmtList(x) {
  if (!x || !x.length) return '—';
  if (x.includes('*')) return '**ALL modules**';
  return x.map(m => `\`${m}\``).join(' ');
}

/** Compact one-line summary for hooks. */
function summaryLine(r) {
  const targets = r.suite.smoke.includes('*') ? 'ALL' : (r.suite.smoke.join(',') || 'none');
  return `${ICON[r.risk]} ${r.risk} (${r.score}) · ${r.blast.modules.length} modules · ` +
         `${r.blocking.length} blocking · verify: ${targets}`;
}

module.exports = { renderMarkdown, summaryLine, ICON };
