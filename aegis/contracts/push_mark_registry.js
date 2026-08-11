'use strict';
const path = require('path');
const fs = require('fs');
const { read, matches, finding, rollup } = require('./_util');

/**
 * L7 — Universal push: each mark emitted by EXACTLY ONE sender. Never dup, never drop.
 *
 * Strategy: locate the MARK_REGISTRY (the canonical list of 19 marks) by scanning
 * the functions dir. Then, for each CHANGED file that emits a push, check every
 * emitted mark is in the registry (unregistered mark = drift → warn) and flag a
 * mark emitted from more than one distinct sender file in the change set (double-send).
 */
const EMIT = /emit_push|sendPush|pushRequests|MARK_REGISTRY|['"]mark['"]\s*[:=]/;
const MARK_TOKEN = /\b([A-Z][A-Z0-9]+(?:_[A-Z0-9]+){1,4})\b/g; // e.g. ATTENDANCE_MARKED

function findRegistry(cfg) {
  const fdir = cfg.surfaces.functions && cfg.surfaces.functions.root;
  if (!fdir) return null;
  let files = [];
  try { files = fs.readdirSync(fdir).filter(f => f.endsWith('.js')); } catch (e) { return null; }
  for (const f of files) {
    const abs = path.join(fdir, f);
    const t = read(abs);
    if (/MARK_REGISTRY/.test(t)) {
      const marks = new Set();
      for (const { match } of matches(t, MARK_TOKEN)) if (match[1] !== 'MARK_REGISTRY') marks.add(match[1]);
      return { file: abs, marks };
    }
  }
  return null;
}

module.exports = {
  id: 'push_mark_registry',
  title: 'Push mark registry — exactly-once ownership',
  blocking: false, // registry drift is warn; genuine double-send is warn until registry is authoritative
  check(cfg, ctx) {
    const changed = (ctx && ctx.changed) || [];
    const emitters = changed.filter(f => {
      if (!/\.(js|php|kt)$/.test(f.path)) return false;
      const t = read(f.path);
      return EMIT.test(t) && f.kind !== 'test';
    });
    if (!emitters.length) return { id: this.id, status: 'skip', findings: [], note: 'no push emitters in change set' };

    const registry = findRegistry(cfg);
    const findings = [];
    const markToSenders = {};

    for (const f of emitters) {
      const t = read(f.path);
      const localMarks = new Set();
      // only consider mark tokens on lines that also mention an emit call
      t.split('\n').forEach((ln) => {
        if (!EMIT.test(ln)) return;
        for (const { match } of matches(ln, MARK_TOKEN)) {
          if (match[1] === 'MARK_REGISTRY') continue;
          localMarks.add(match[1]);
        }
      });
      for (const mk of localMarks) {
        (markToSenders[mk] = markToSenders[mk] || new Set()).add(f.path);
        if (registry && registry.marks.size && !registry.marks.has(mk)) {
          findings.push(finding('warn', f.path, 0, `emits '${mk}' which is NOT in MARK_REGISTRY — register it or fix the mark.`));
        }
      }
    }

    for (const [mk, senders] of Object.entries(markToSenders)) {
      if (senders.size > 1) {
        findings.push(finding('warn', [...senders][0], 0, `mark '${mk}' emitted from ${senders.size} changed senders — risk of DOUBLE-SEND. Exactly one owner allowed.`));
      }
    }

    const note = registry ? `registry: ${path.basename(registry.file)} (${registry.marks.size} marks)` : 'MARK_REGISTRY not found — drift check limited';
    return { id: this.id, status: rollup(findings), findings, note };
  }
};
