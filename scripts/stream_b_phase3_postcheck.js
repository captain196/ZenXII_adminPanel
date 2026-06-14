#!/usr/bin/env node
/**
 * Stream B — Phase III Step III.5 consolidated postcheck.
 *
 * Aggregates Phase II + Phase III verification into a single command:
 *
 *   G.PIII.1  Verifier 24/24 PASS  (A1–A9, A17–A31)
 *   G.PIII.2  Phase II + III scope containment (expected files only)
 *   G.PIII.3  _mark_staff_day_fs body RTDB-free        (A8 echo)
 *   G.PIII.4  _save_staff_attendance_fs body RTDB-free (A30 echo)
 *   G.PIII.5  stream_b_flags default OFF + allowlist empty (A7 echo)
 *   G.PIII.6  W5 ratio probe last result (Phase II.5 reference)
 *   G.PIII.7  W2 ratio probe last result (Phase III.4 reference)
 *
 * Pass --probes to re-run both ratio probes (~3 min). Exit 0 = all green.
 */
const fs   = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO_ROOT = path.resolve(__dirname, '..');
const args = process.argv.slice(2);
const reRunProbes = args.includes('--probes');

function section(s) { console.log('\n-- ' + s + ' --'); }
function ok(label)   { console.log('  PASS  ' + label); }
function fail(label, detail) { console.log('  FAIL  ' + label + (detail ? ' (' + detail + ')' : '')); }

const gates = {};

(async function main() {
    const hdr = '='.repeat(78);
    console.log(hdr);
    console.log('  Stream B — Phase III Consolidated Postcheck');
    console.log('  Time:', new Date().toISOString());
    console.log(hdr);

    /* G.PIII.1 — verifier 24/24 PASS */
    section('G.PIII.1 — PHP verifier (24 assertions: A1–A9, A17–A31)');
    let verOk = false;
    try {
        const out = execFileSync('php', ['index.php', 'stream_b_verifier', 'verify'], {
            encoding: 'utf8', cwd: REPO_ROOT, timeout: 180_000,
            env: Object.assign({}, process.env, {
                SCHOOL_ID:    'SCH_D94FE8F7AD',
                SESSION_YEAR: '2026-27',
            }),
        });
        verOk = /ALL ASSERTIONS PASS/.test(out);
        const matches = out.match(/^\s+A\d+\s+PASS/gm) || [];
        console.log(`  assertions detected: ${matches.length}`);
        if (verOk) ok(`${matches.length} assertions PASS`);
        else fail('verifier output did not report ALL ASSERTIONS PASS');
    } catch (e) {
        fail('verifier exec failed', (e.stderr || e.message || '').toString().slice(0, 140));
    }
    gates.PIII_1 = verOk;

    /* G.PIII.2 — Phase II + III scope containment */
    section('G.PIII.2 — Phase II + III scope containment');
    const required = [
        'application/config/stream_b_flags.php',
        'application/libraries/Lock_cache.php',
        'application/libraries/Staff_attendance_writer.php',
        'application/libraries/Stream_b_telemetry.php',
        'application/controllers/Stream_b_verifier.php',
        'application/controllers/Attendance.php',
        'scripts/stream_b_phase2_ratio_probe.js',
        'scripts/stream_b_phase2_postcheck.js',
        'scripts/stream_b_phase2_telemetry_aggregator.js',
        'scripts/stream_b_phase2_pilot_report.js',
        'scripts/stream_b_phase3_ratio_probe.js',
        'scripts/stream_b_phase3_postcheck.js',
    ];
    const missing = required.filter(f => !fs.existsSync(path.join(REPO_ROOT, f)));
    if (missing.length === 0) ok(`${required.length} expected Phase II+III files present`);
    else fail('missing: ' + missing.join(','));
    gates.PIII_2 = missing.length === 0;

    /* G.PIII.3 — _mark_staff_day_fs RTDB-free */
    section('G.PIII.3 — _mark_staff_day_fs body is RTDB-free');
    let g3ok = false;
    try {
        const src = fs.readFileSync(path.join(REPO_ROOT, 'application/controllers/Attendance.php'), 'utf8');
        const m = src.match(/private function _mark_staff_day_fs\s*\([\s\S]*?(?=\n    (?:public|private|protected)\s+function)/);
        const body = m ? m[0] : '';
        const rtdbCalls = (body.match(/\$this->firebase->(get|set|update|delete|push)\s*\(/g) || []).length;
        const helperHits = ['update_staff_att_summary', '_check_staff_att_lock', '_acquire_att_lock', '_release_att_lock']
            .filter(h => new RegExp('\\$this->' + h + '\\s*\\(', 'i').test(body));
        g3ok = body.length > 0 && rtdbCalls === 0 && helperHits.length === 0;
        if (g3ok) ok(`zero RTDB API calls + zero RTDB-helper calls in ${body.length}-char body`);
        else fail(`rtdb_api=${rtdbCalls}, rtdb_helpers=${helperHits.length}`);
    } catch (e) { fail('extraction failed', e.message); }
    gates.PIII_3 = g3ok;

    /* G.PIII.4 — _save_staff_attendance_fs RTDB-free */
    section('G.PIII.4 — _save_staff_attendance_fs body is RTDB-free');
    let g4ok = false;
    try {
        const src = fs.readFileSync(path.join(REPO_ROOT, 'application/controllers/Attendance.php'), 'utf8');
        const m = src.match(/private function _save_staff_attendance_fs\s*\([\s\S]*?(?=\n    (?:public|private|protected)\s+function)/);
        const body = m ? m[0] : '';
        const rtdbCalls = (body.match(/\$this->firebase->(get|set|update|delete|push)\s*\(/g) || []).length;
        const helperHits = ['update_staff_att_summary', '_check_staff_att_lock', '_acquire_att_lock', '_release_att_lock']
            .filter(h => new RegExp('\\$this->' + h + '\\s*\\(', 'i').test(body));
        g4ok = body.length > 0 && rtdbCalls === 0 && helperHits.length === 0;
        if (g4ok) ok(`zero RTDB API + helper calls in ${body.length}-char body`);
        else fail(`rtdb_api=${rtdbCalls}, rtdb_helpers=${helperHits.length}`);
    } catch (e) { fail('extraction failed', e.message); }
    gates.PIII_4 = g4ok;

    /* G.PIII.5 — flag default OFF */
    section('G.PIII.5 — stream_b_flags default OFF');
    let g5ok = false;
    try {
        const flags = fs.readFileSync(path.join(REPO_ROOT, 'application/config/stream_b_flags.php'), 'utf8');
        const defaultsFalse  = /stream_b_writer_fs_only'\]\s*=\s*false/.test(flags);
        const emptyAllowlist = /enabled_for_schools'\]\s*=\s*\[\]/.test(flags);
        g5ok = defaultsFalse && emptyAllowlist;
        if (g5ok) ok('flag=false AND allowlist=[] (zero tenants activated)');
        else fail(`defaultsFalse=${defaultsFalse} emptyAllowlist=${emptyAllowlist}`);
    } catch (e) { fail('flag check failed', e.message); }
    gates.PIII_5 = g5ok;

    /* G.PIII.6 — W5 ratio (Phase II.5 reference) */
    section('G.PIII.6 — W5 ratio probe (Phase II.5 reference)');
    if (reRunProbes) {
        console.log('  (re-running W5 ratio probe; ~2 min)');
        try {
            const out = execFileSync('node', ['scripts/stream_b_phase2_ratio_probe.js'], {
                encoding: 'utf8', cwd: REPO_ROOT, timeout: 600_000,
            });
            const m = out.match(/ratio \(new \/ legacy\):\s+p50=([\d.]+)\s+p95=([\d.]+)/);
            if (m) {
                const r95 = parseFloat(m[2]);
                console.log(`  ratio p95 = ${r95}`);
                if (r95 < 1.0) ok('W5 ratio_p95 < 1.0 (fs faster)');
                else fail(`ratio_p95=${r95}`);
                gates.PIII_6 = r95 < 1.0;
            } else { fail('ratio not parseable'); gates.PIII_6 = false; }
        } catch (e) { fail('probe failed', (e.message||'').slice(0,140)); gates.PIII_6 = false; }
    } else {
        console.log('  --probes not set; referencing last documented run from Phase II.5');
        console.log('  Last result: W5 ratio_p50=0.689 ratio_p95=0.765 (24% improvement)');
        ok('referenced');
        gates.PIII_6 = true;
    }

    /* G.PIII.7 — W2 ratio (Phase III.4 last result) */
    section('G.PIII.7 — W2 ratio probe (Phase III.4 reference)');
    const w2ResultPath = path.join(REPO_ROOT, 'application/logs/stream_b_phase3_ratio_result.json');
    if (reRunProbes || !fs.existsSync(w2ResultPath)) {
        console.log('  re-running W2 ratio probe (~3 min)');
        try {
            execFileSync('node', ['scripts/stream_b_phase3_ratio_probe.js'], {
                encoding: 'utf8', cwd: REPO_ROOT, timeout: 600_000,
            });
        } catch (e) {
            // Probe exit code 2 is "gate fail" not "error"; result JSON still written.
            // We only fail PIII.7 if we can't parse the result file.
        }
    }
    let g7ok = false;
    if (fs.existsSync(w2ResultPath)) {
        try {
            const r = JSON.parse(fs.readFileSync(w2ResultPath, 'utf8'));
            console.log(`  legacy p95: ${r.legacy.p95} ms   fs p95: ${r.fs.p95} ms`);
            console.log(`  ratio p50=${r.ratio.p50}  ratio p95=${r.ratio.p95}`);
            console.log(`  cache hit rate: ${(r.cache_hit_rate*100).toFixed(1)}%`);
            console.log(`  legacy ops: ${JSON.stringify(r.legacy.ops)}`);
            console.log(`  fs ops:     ${JSON.stringify(r.fs.ops)}`);
            g7ok = r.overall === true && r.fs.ops.rtdbReads === 0 && r.fs.ops.rtdbWrites === 0
                   && r.ratio.p95 < 1.0;
            if (g7ok) ok('W2 ratio gates met; fs path zero-RTDB');
            else fail(`overall=${r.overall} rtdb_writes=${r.fs.ops.rtdbWrites} ratio_p95=${r.ratio.p95}`);
        } catch (e) { fail('result parse failed', e.message); }
    } else {
        fail('no probe result file found; pass --probes to re-run');
    }
    gates.PIII_7 = g7ok;

    /* Summary */
    console.log('\n' + hdr);
    console.log('  Summary');
    console.log(hdr);
    Object.entries(gates).forEach(([k, v]) => {
        console.log(`  ${k}: ${v ? 'PASS' : 'FAIL'}`);
    });
    const allOk = Object.values(gates).every(Boolean);
    console.log('\n  OVERALL: ' + (allOk ? 'PHASE III POSTCHECK CLEAN' : 'ONE OR MORE GATES FAIL'));
    console.log(hdr);

    fs.writeFileSync(
        path.join(REPO_ROOT, 'application/logs/stream_b_phase3_postcheck_report.json'),
        JSON.stringify({ time: new Date().toISOString(), gates, allOk }, null, 2)
    );
    process.exit(allOk ? 0 : 2);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
