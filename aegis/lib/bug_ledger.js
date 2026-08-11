'use strict';
const fs = require('fs');
const { modules } = require('../manifest');

/**
 * Regression memory: scan BUG_LEDGER.md headings for module keywords so the
 * impact engine can cite prior regressions for a touched module.
 * Returns { moduleName: [ "heading line", ... ] }.
 */
function priorRegressions(cfg) {
  const out = {};
  let text = '';
  try { text = fs.readFileSync(cfg.bugLedger, 'utf8'); } catch (e) { return out; }

  const headings = text.split('\n').filter(l => /^#{1,4}\s/.test(l) || /\bB\d/.test(l));
  for (const [name, def] of Object.entries(modules)) {
    const kws = (def.match || []).map(k => k.toLowerCase());
    const hits = headings.filter(h => {
      const hl = h.toLowerCase();
      return kws.some(k => hl.includes(k));
    }).map(h => h.replace(/^#+\s*/, '').trim()).slice(0, 3);
    if (hits.length) out[name] = hits;
  }
  return out;
}

module.exports = { priorRegressions };
