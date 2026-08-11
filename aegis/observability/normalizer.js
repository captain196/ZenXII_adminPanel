'use strict';
/**
 * ZenXii Aegis — Telemetry Normalizer (Layer §02)
 *
 * Turns the project's existing structured log convention into one event envelope,
 * so PHP (`log_message`), Node, and Cloud Functions all feed one pipeline.
 *
 * Convention (extends PHASE_A_OBSERVABILITY's `[PREFIX] key=value` lines):
 *   [AEGIS.attendance] event=marked schoolId=SCH_000123 ms=42 ok=true
 *   [PAYROLL] event=run schoolId=SCH_000123 count=88
 *
 * This module is pure + dependency-free so it can run in a Cloud Function that
 * tails logs, or locally over a log file. The DESTINATION (Firestore
 * aegis_events / aegis_metrics_daily) is wired where you deploy it — see
 * dashboard.md. Here we only parse + shape.
 */

const LINE = /\[([A-Za-z0-9_.]+)\]\s+(.*)$/;

/** Parse one log line into an envelope, or null if it isn't a structured event. */
function parseLine(line, meta = {}) {
  const m = LINE.exec(line);
  if (!m) return null;
  const prefix = m[1];
  const rest = m[2];

  const kv = {};
  // key=value pairs; values may be quoted
  const re = /(\w+)=("([^"]*)"|'([^']*)'|(\S+))/g;
  let x;
  while ((x = re.exec(rest)) !== null) {
    kv[x[1]] = x[3] !== undefined ? x[3] : x[4] !== undefined ? x[4] : x[5];
  }

  const [ns, mod] = prefix.includes('.') ? prefix.split('.') : ['LOG', prefix.toLowerCase()];
  return {
    surface: meta.surface || 'unknown',
    namespace: ns,
    module: (mod || '').toLowerCase(),
    event: kv.event || null,
    schoolId: kv.schoolId || kv.school_id || null,
    ms: kv.ms !== undefined ? Number(kv.ms) : null,
    ok: kv.ok !== undefined ? (kv.ok === 'true' || kv.ok === '1') : null,
    level: /error|fail|denied|exception/i.test(rest) ? 'error' : 'info',
    kv,
    raw: line,
    ts: meta.ts || null // caller stamps time (scripts can't call Date.now here)
  };
}

/** Parse a whole log blob → array of envelopes (non-events dropped). */
function parseBlob(text, meta = {}) {
  return text.split('\n').map(l => parseLine(l, meta)).filter(Boolean);
}

/** Roll envelopes into a per-module daily metric summary (feeds the dashboard). */
function rollup(envelopes) {
  const out = {};
  for (const e of envelopes) {
    const k = e.module || 'unknown';
    const r = out[k] || (out[k] = { module: k, count: 0, errors: 0, msSum: 0, msN: 0 });
    r.count++;
    if (e.ok === false || e.level === 'error') r.errors++;
    if (typeof e.ms === 'number' && !Number.isNaN(e.ms)) { r.msSum += e.ms; r.msN++; }
  }
  return Object.values(out).map(r => ({
    module: r.module,
    count: r.count,
    errorRate: r.count ? +(r.errors / r.count).toFixed(4) : 0,
    avgMs: r.msN ? Math.round(r.msSum / r.msN) : null,
    health: r.count ? Math.round(100 * (1 - r.errors / r.count)) : 100
  }));
}

module.exports = { parseLine, parseBlob, rollup };

// CLI: `node observability/normalizer.js <logfile>` prints the rollup.
if (require.main === module) {
  const fs = require('fs');
  const file = process.argv[2];
  if (!file) { console.error('usage: normalizer.js <logfile>'); process.exit(1); }
  const env = parseBlob(fs.readFileSync(file, 'utf8'));
  console.log(JSON.stringify({ events: env.length, rollup: rollup(env) }, null, 2));
}
