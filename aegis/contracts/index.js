'use strict';

/** All contract checks, in run order. */
const CHECKS = [
  require('./rules_deny_default'),
  require('./claim_key'),
  require('./push_mark_registry'),
  require('./session_scope'),
  require('./firestore_index'),
  require('./responsive_scroll')
];

const BY_ID = Object.fromEntries(CHECKS.map(c => [c.id, c]));

/**
 * Run checks. opts:
 *   changed  : classified changed-file list (ctx.changed)
 *   only     : array of check ids to run (default: all)
 *   blockingOnly : run only blocking checks (for the release gate)
 * Returns { results:[{id,title,status,findings,note,blocking}], failed, blocked, warned }.
 */
function run(cfg, opts = {}) {
  const ctx = { changed: opts.changed || [] };
  let list = CHECKS;
  if (opts.only && opts.only.length) list = CHECKS.filter(c => opts.only.includes(c.id));
  if (opts.blockingOnly) list = list.filter(c => c.blocking);

  const results = list.map(c => {
    let r;
    try { r = c.check(cfg, ctx); }
    catch (e) { r = { id: c.id, status: 'error', findings: [{ level: 'error', file: '', line: 0, msg: 'check threw: ' + e.message }] }; }
    return { id: c.id, title: c.title, blocking: c.blocking, status: r.status, findings: r.findings || [], note: r.note || '' };
  });

  const failed = results.filter(r => r.status === 'fail' || r.status === 'error');
  const blocked = failed.filter(r => r.blocking);
  const warned = results.filter(r => r.status === 'warn');
  return { results, failed, blocked, warned };
}

module.exports = { run, CHECKS, BY_ID };
