#!/usr/bin/env node
/**
 * Stream B — Phase II pilot report (Day-N).
 *
 * Reads application/logs/stream_b_phase2_aggregated.json (produced by
 * stream_b_phase2_telemetry_aggregator.js) and emits a markdown
 * acceptance-gate report for a specified tenant.
 *
 * Usage:
 *   node scripts/stream_b_phase2_pilot_report.js SCH_XYZ
 *
 * Acceptance gates (from Phase II telemetry plan):
 *   PG1  ratio_p95 < 1.00            (fs strictly faster than legacy)
 *   PG2  fs.cache_hit_rate >= 0.90   (target 0.95)
 *   PG3  fs.cas_retry_rate <= 0.05
 *   PG4  fs.cas_exhaustion_rate == 0
 *   PG5  fs.rtdb_writes_observed == 0
 *   PG6  fs.error_rate <= 0.01
 */
const fs   = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '..');
const AGG_PATH  = path.join(REPO_ROOT, 'application/logs/stream_b_phase2_aggregated.json');

const tenant = (process.argv[2] || '').trim();
if (!tenant) {
    console.error('Usage: node stream_b_phase2_pilot_report.js <SCHOOL_ID>');
    process.exit(1);
}
if (!fs.existsSync(AGG_PATH)) {
    console.error('Aggregated file not found:', AGG_PATH);
    console.error('Run the aggregator first.');
    process.exit(1);
}
const agg = JSON.parse(fs.readFileSync(AGG_PATH, 'utf8'));
const t = agg.per_tenant[tenant];
if (!t) {
    console.error('Tenant not in aggregated data:', tenant);
    console.error('Available:', Object.keys(agg.per_tenant).join(', '));
    process.exit(1);
}

const fs_  = t.aggregate.per_path.fs;
const leg_ = t.aggregate.per_path.legacy;
const r    = t.aggregate.ratio;

function row(name, target, measured, ok) {
    return `| ${name} | ${target} | ${measured} | ${ok ? 'PASS' : 'FAIL'} |`;
}

const gates = [];
if (r) gates.push({ n: 'PG1 — ratio_p95 < 1.00 (fs faster than legacy)',
                    target: '< 1.00', measured: r.p95,
                    ok: r.p95 < 1.0 });
if (fs_) {
    gates.push({ n: 'PG2 — cache_hit_rate ≥ 0.90', target: '≥ 0.90',
                 measured: fs_.cache_hit_rate, ok: fs_.cache_hit_rate >= 0.90 });
    gates.push({ n: 'PG3 — cas_retry_rate ≤ 0.05', target: '≤ 0.05',
                 measured: fs_.cas_retry_rate, ok: fs_.cas_retry_rate <= 0.05 });
    gates.push({ n: 'PG4 — cas_exhaustion_rate == 0', target: '== 0',
                 measured: fs_.cas_exhaustion_rate, ok: fs_.cas_exhaustion_rate === 0 });
    gates.push({ n: 'PG5 — rtdb_writes_observed (fs) == 0', target: '== 0',
                 measured: fs_.rtdb_writes_observed, ok: fs_.rtdb_writes_observed === 0 });
    gates.push({ n: 'PG6 — error_rate ≤ 0.01', target: '≤ 0.01',
                 measured: fs_.error_rate, ok: fs_.error_rate <= 0.01 });
}
const allOk    = gates.every(g => g.ok);
const decision = allOk ? 'PROMOTE' : (gates.some(g => !g.ok && /PG[145]/.test(g.n)) ? 'ROLLBACK' : 'HOLD');

const lines = [];
lines.push(`# Phase II Pilot Report — ${tenant}`);
lines.push('');
lines.push(`Generated: ${new Date().toISOString()}`);
lines.push(`Aggregator output: ${path.relative(REPO_ROOT, AGG_PATH)}`);
lines.push(`Total samples: ${t.total_samples}`);
lines.push('');
lines.push('## Sample volumes');
lines.push('');
lines.push('| Code path | Samples | p50 (ms) | p95 (ms) | avg (ms) |');
lines.push('|---|---|---|---|---|');
if (fs_)  lines.push(`| fs     | ${fs_.samples}  | ${fs_.p50_ms}  | ${fs_.p95_ms}  | ${fs_.avg_ms}  |`);
if (leg_) lines.push(`| legacy | ${leg_.samples} | ${leg_.p50_ms} | ${leg_.p95_ms} | ${leg_.avg_ms} |`);
lines.push('');
if (r) {
    lines.push('## Ratio (fs / legacy)');
    lines.push('');
    lines.push(`p50 ratio: **${r.p50}**`);
    lines.push(`p95 ratio: **${r.p95}**`);
    lines.push('');
}
lines.push('## Acceptance gate evaluation');
lines.push('');
lines.push('| Gate | Target | Measured | Verdict |');
lines.push('|---|---|---|---|');
for (const g of gates) lines.push(row(g.n, g.target, g.measured, g.ok));
lines.push('');
lines.push(`## DECISION: ${decision}`);
lines.push('');
if (decision === 'PROMOTE') {
    lines.push('All acceptance gates green. The tenant is cleared for continued FS-path operation. Consider expanding pilot to additional tenants.');
} else if (decision === 'ROLLBACK') {
    lines.push('A critical gate (latency / RTDB-free / error rate) failed. **Roll back this tenant** by removing it from `stream_b_flags::enabled_for_schools[]`. Investigate before re-enabling.');
} else {
    lines.push('Non-critical gates failed (cache or CAS). Investigate root cause; tenant can continue under observation. Decide promote/rollback at next checkpoint.');
}

console.log(lines.join('\n'));
process.exit(0);
