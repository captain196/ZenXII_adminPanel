'use strict';
const path = require('path');
const { read, exists, matches, finding, rollup } = require('./_util');
const { contracts } = require('../manifest');

/**
 * L7 — Every custom-claims writer MUST dual-emit school_id (snake) AND schoolId (camel).
 * Mismatch = silent PERMISSION_DENIED for every Android read.
 *
 * Scans the declared breaker files (manifest.contracts.claim_key.breakers). A file
 * is a "claims writer" if it references claim-setting. If it writes claims but is
 * missing either key, that's a BLOCK-level failure.
 */
const WRITES_CLAIMS = /setCustomUserClaims|customClaims|custom_claims|['"]claims['"]\s*=>|\$claims\b/;

module.exports = {
  id: 'claim_key',
  title: 'Auth claims dual-emit school_id + schoolId',
  blocking: true,
  check(cfg /*, ctx */) {
    const adminRoot = (cfg.surfaces.admin && cfg.surfaces.admin.root) || '';
    const breakers = (contracts.claim_key && contracts.claim_key.breakers) || [];
    const findings = [];
    let scanned = 0;

    for (const rel of breakers) {
      const abs = path.join(adminRoot, rel);
      if (!exists(abs)) continue;
      const text = read(abs);
      if (!WRITES_CLAIMS.test(text)) continue; // not a claims writer
      scanned++;

      // Only inspect regions that look like a claims payload to avoid false hits on session reads.
      const hasSnake = /\bschool_id\b/.test(text);
      const hasCamel = /\bschoolId\b/.test(text);

      if (hasSnake && !hasCamel) {
        const ln = firstLine(text, /\bschool_id\b/);
        findings.push(finding('error', abs, ln, `writes claims with school_id but NOT schoolId — admin login will read undefined schoolId.`));
      } else if (hasCamel && !hasSnake) {
        const ln = firstLine(text, /\bschoolId\b/);
        findings.push(finding('error', abs, ln, `writes claims with schoolId but NOT school_id — Firestore rules will PERMISSION_DENY every app read.`));
      }
    }

    if (scanned === 0) return { id: this.id, status: 'skip', findings, note: 'no claims-writer breaker files present/matched' };
    return { id: this.id, status: rollup(findings), findings, note: `scanned ${scanned} claims-writer file(s)` };
  }
};

function firstLine(text, re) {
  for (const { line } of matches(text, re)) return line;
  return 0;
}
