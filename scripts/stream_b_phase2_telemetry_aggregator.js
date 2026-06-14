#!/usr/bin/env node
/**
 * Stream B — Phase II MVT telemetry aggregator.
 *
 * Reads application/logs/stream_b_phase2_telemetry.log (NDJSON) and emits
 * application/logs/stream_b_phase2_aggregated.json with per-tenant /
 * per-code_path metrics covering the 5 pilot acceptance criteria:
 *
 *   latency (p50, p95, avg)
 *   error_rate           (http_status >= 400, excluding 409 conflicts)
 *   cas_retry_rate       (attempts > 1)
 *   cas_exhaustion_rate  (final_outcome == 'exhausted')
 *   cache_hit_rate       (hit == true)
 *   rtdb_writes_observed (sum, expected 0 for fs path)
 *
 * Read-only. Idempotent. Safe to run anytime.
 */
const fs   = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '..');
const LOG_PATH  = path.join(REPO_ROOT, 'application/logs/stream_b_phase2_telemetry.log');
const OUT_PATH  = path.join(REPO_ROOT, 'application/logs/stream_b_phase2_aggregated.json');

function pct(arr, p) {
    if (arr.length === 0) return null;
    const s = [...arr].sort((a, b) => a - b);
    return s[Math.min(s.length - 1, Math.floor(s.length * p / 100))];
}
function avg(arr) {
    if (arr.length === 0) return null;
    return Math.round(arr.reduce((a, b) => a + b, 0) / arr.length);
}

function aggregateBucket(records) {
    // Split by code_path
    const byPath = { fs: [], legacy: [] };
    for (const r of records) {
        if (r.code_path === 'fs' || r.code_path === 'legacy') {
            byPath[r.code_path].push(r);
        }
    }
    const perPath = {};
    for (const cp of ['fs', 'legacy']) {
        const xs = byPath[cp];
        if (xs.length === 0) { perPath[cp] = null; continue; }
        const lat = xs.map(r => +r.t_total_ms || 0);
        const errs = xs.filter(r => (+r.http_status || 0) >= 400 && +r.http_status !== 409).length;
        const retries = xs.filter(r => (+r.cas_attempts || 1) > 1).length;
        const exhaust = xs.filter(r => r.cas_final_outcome === 'exhausted').length;
        const cacheH = xs.filter(r => r.cache_hit === true).length;
        const rtdbW  = xs.reduce((sum, r) => sum + (+r.rtdb_writes_count || 0), 0);
        perPath[cp] = {
            samples:               xs.length,
            p50_ms:                pct(lat, 50),
            p95_ms:                pct(lat, 95),
            avg_ms:                avg(lat),
            error_rate:            +(errs / xs.length).toFixed(4),
            cas_retry_rate:        +(retries / xs.length).toFixed(4),
            cas_exhaustion_rate:   +(exhaust / xs.length).toFixed(4),
            cache_hit_rate:        +(cacheH / xs.length).toFixed(4),
            rtdb_writes_observed:  rtdbW,
        };
    }
    // Ratio (fs / legacy) when both have data
    let ratio = null;
    if (perPath.fs && perPath.legacy && perPath.legacy.p95_ms > 0) {
        ratio = {
            p50: +(perPath.fs.p50_ms / perPath.legacy.p50_ms).toFixed(3),
            p95: +(perPath.fs.p95_ms / perPath.legacy.p95_ms).toFixed(3),
        };
    }
    return { per_path: perPath, ratio };
}

(async function main() {
    if (!fs.existsSync(LOG_PATH)) {
        console.error('Telemetry log not found:', LOG_PATH);
        console.error('Run `mark_staff_day` requests to generate telemetry first.');
        process.exit(1);
    }
    const text = fs.readFileSync(LOG_PATH, 'utf8');
    const lines = text.split('\n').filter(Boolean);
    const records = [];
    let badLines = 0;
    for (const ln of lines) {
        try { records.push(JSON.parse(ln)); }
        catch (e) { badLines++; }
    }
    console.log(`Parsed ${records.length} records (${badLines} unparseable)`);

    // Group by (school_id, hour_bucket)
    const byBucket = new Map();
    const byTenant = new Map();
    for (const r of records) {
        const tenant = r.school_id || 'UNKNOWN';
        const hour   = (r.ts || '').slice(0, 13); // YYYY-MM-DDTHH
        const key    = tenant + '|' + hour;
        if (!byBucket.has(key)) byBucket.set(key, []);
        byBucket.get(key).push(r);
        if (!byTenant.has(tenant)) byTenant.set(tenant, []);
        byTenant.get(tenant).push(r);
    }

    // Build output
    const output = {
        generated_at:   new Date().toISOString(),
        input_file:     LOG_PATH,
        total_records:  records.length,
        bad_lines:      badLines,
        per_tenant: {},
    };
    for (const [tenant, allRecs] of byTenant.entries()) {
        const tenantOut = aggregateBucket(allRecs);
        // Per-hour buckets for this tenant
        const hourly = {};
        for (const [key, bucketRecs] of byBucket.entries()) {
            const [t, h] = key.split('|');
            if (t !== tenant) continue;
            hourly[h] = aggregateBucket(bucketRecs);
        }
        output.per_tenant[tenant] = {
            total_samples: allRecs.length,
            aggregate:     tenantOut,
            hourly,
        };
    }

    fs.writeFileSync(OUT_PATH, JSON.stringify(output, null, 2));
    console.log('Aggregated output written to', OUT_PATH);
    console.log('Tenants observed:', Object.keys(output.per_tenant).join(', ') || '(none)');
    process.exit(0);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
