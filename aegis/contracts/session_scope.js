'use strict';
const { read, finding, rollup } = require('./_util');

/**
 * L7 — Reads of session-scoped collections MUST filter by session_year.
 * Heuristic (warn-only): if a changed file belongs to a session-scoped module
 * and issues a read against one of those collections but contains NO session
 * token anywhere, flag it. Catches the "blank current-year / prior-year leak" class.
 */
const SCOPED_MODULES = new Set(['attendance', 'homework', 'exam', 'leave', 'payroll']);
const READ = /\.collection\(|->where\(|whereEqualTo|\.get\(\)|->get\(|firestoreGet|\.document\(/;
const SESSION = /session_year|sessionYear|session-year|['"]session['"]|academic_year|academicYear/;

module.exports = {
  id: 'session_scope',
  title: 'Session-year scoping on attendance/homework/exam/leave reads',
  blocking: false,
  check(cfg, ctx) {
    const changed = (ctx && ctx.changed) || [];
    const targets = changed.filter(f =>
      f.modules.some(m => SCOPED_MODULES.has(m)) &&
      /\.(php|kt|js)$/.test(f.path) && f.kind !== 'test'
    );
    if (!targets.length) return { id: this.id, status: 'skip', findings: [], note: 'no session-scoped module files changed' };

    const findings = [];
    for (const f of targets) {
      const t = read(f.path);
      if (READ.test(t) && !SESSION.test(t)) {
        findings.push(finding('warn', f.path, 0,
          `${f.modules.filter(m => SCOPED_MODULES.has(m)).join('/')} read with no session_year filter visible — confirm current-session scoping.`));
      }
    }
    return { id: this.id, status: rollup(findings), findings, note: `checked ${targets.length} file(s)` };
  }
};
