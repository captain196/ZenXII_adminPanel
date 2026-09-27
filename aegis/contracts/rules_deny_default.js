'use strict';
const { read, exists, matches, finding, rollup } = require('./_util');

/**
 * L6/L14 — firestore.rules stays deny-by-default.
 * Runs on the WHOLE rules file every time (rules ship as one file — one bad
 * block ships all).
 *
 * A rule condition is judged as:
 *   `if true`          → CRITICAL world-open (error, BLOCK)
 *   `if false`         → safest possible (explicit deny) — always OK
 *   calls a helper()   → the auth/tenant gate lives in the helper — OK
 *                        (isSameSchool(), isStaff(), isStaffOrOwnStudent(), …)
 *   references request.auth / resource.data / schoolId → OK
 *   otherwise          → no predicate AND no function call (e.g. `if 1==1`,
 *                        `if someVar`) → suspicious (warn)
 *
 * This is deliberately conservative: it exists to catch the ONE bug class you
 * hit (a world-open block), not to lint every helper-based rule into noise.
 */
const PRIMITIVE_SAFE = /request\.auth|request\.resource|resource\.data|schoolId|school_id/;
const CALLS_HELPER = /[A-Za-z_]\w*\s*\(/; // any function call in the condition

module.exports = {
  id: 'rules_deny_default',
  title: 'Firestore rules deny-by-default',
  blocking: true,
  check(cfg /*, ctx */) {
    const file = cfg.firestoreRules;
    if (!exists(file)) return { id: this.id, status: 'skip', findings: [], note: `rules file not found: ${file}` };
    const text = read(file);
    const findings = [];
    let allowCount = 0;

    // capture `allow <ops>: if <condition>;`  (condition may span lines until ';')
    const re = /allow\s+([a-z, ]+?)\s*:\s*if\s+([\s\S]*?);/g;
    for (const { match, line } of matches(text, re)) {
      allowCount++;
      const ops = match[1].trim();
      const cond = match[2].replace(/\s+/g, ' ').trim();

      if (cond === 'true') {
        findings.push(finding('error', file, line, `WORLD-OPEN: \`allow ${ops}: if true\` — grants public access to every tenant.`));
      } else if (cond === 'false') {
        continue; // explicit deny — the safest rule there is
      } else if (CALLS_HELPER.test(cond) || PRIMITIVE_SAFE.test(cond)) {
        continue; // access logic delegated to a helper / auth primitive
      } else {
        findings.push(finding('warn', file, line, `allow ${ops}: if ${cond.slice(0, 60)}… — condition has no auth predicate and calls no helper; confirm not world-open.`));
      }
    }

    return {
      id: this.id, status: rollup(findings), findings,
      note: `scanned ${allowCount} allow-rules; ${findings.length} flagged`
    };
  }
};
