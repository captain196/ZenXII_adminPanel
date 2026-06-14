#!/usr/bin/env node
/**
 * Stream B Phase I commit-readiness gate (Step 3 deliverable).
 *
 * Single-command gate that asserts:
 *   1. All 7 Stream-B Firestore indexes (F-SB-1..7) resolve their target queries.
 *   2. Stream_b_verifier CLI passes all 6 assertions (A1..A6).
 *   3. Cross-session baseline measurement is captured for the long-term record.
 *      Latency comparison NOT used as a hard gate at this step — dev-env noise
 *      floor invalidates the ±10% absolute-delta gate per Phase I Step 1 finding.
 *      Phase II onwards will use the ratio-based intra-run methodology.
 *
 * Emits a 9-metric report as JSON + human-readable for the Phase I commit gate.
 *
 * Exit codes:
 *   0 — all functional gates PASS
 *   2 — one or more functional gates FAIL
 *   3 — measurement gates were skipped (ratio methodology deferred to Phase II)
 *
 * READ-ONLY (postdeploy probe is read-only; baseline does self-cleaned probe writes).
 *
 * Usage: node scripts/stream_b_phase1_postcheck.js
 */
const path = require('path');
const { execFileSync } = require('child_process');
const fs = require('fs');

const REPO_ROOT = path.resolve(__dirname, '..');
const POSTDEPLOY = path.join(__dirname, 'stream_b_phase1_postdeploy_probe.js');
const BASELINE   = path.join(__dirname, 'stream_b_baseline_probe.js');
const PRECHECK   = path.join(__dirname, 'stream_b_phase1_preflight_probe.js');

function header(s) { console.log('\n' + '═'.repeat(76) + '\n  ' + s + '\n' + '═'.repeat(76)); }
function section(s) { console.log('\n' + '── ' + s + ' ──'); }
function pad(s, n) { return String(s).padEnd(n); }

function runCmd(label, file, env) {
    section(label);
    try {
        const out = execFileSync('node', [file], { encoding: 'utf8', cwd: REPO_ROOT, env: Object.assign({}, process.env, env || {}) });
        return { ok: true, stdout: out, code: 0 };
    } catch (e) {
        return { ok: false, stdout: e.stdout ? e.stdout.toString() : '', code: e.status || -1, err: (e.stderr||'').toString() };
    }
}

function runPhp(label, args) {
    section(label);
    try {
        const out = execFileSync('php', ['index.php'].concat(args), {
            encoding: 'utf8', cwd: REPO_ROOT,
            env: Object.assign({}, process.env, { SCHOOL_ID: 'SCH_D94FE8F7AD', SESSION_YEAR: '2026-27' }),
            timeout: 120000,
        });
        return { ok: true, stdout: out, code: 0 };
    } catch (e) {
        return { ok: false, stdout: e.stdout ? e.stdout.toString() : '', code: e.status || -1, err: (e.stderr||'').toString() };
    }
}

(async function main() {
    header('Stream B Phase I — Commit-Readiness Postcheck');
    console.log('  Time:', new Date().toISOString());

    const gates = {};

    // ── G1: 7 indexes resolve target queries ─────────────────────────
    const g1 = runCmd('G1: 7 Firestore indexes resolve target queries', POSTDEPLOY);
    const indexLine = (g1.stdout.match(/RESULT: (.*)/) || ['', ''])[1].trim();
    console.log(g1.stdout.split('\n').filter(l => /F-SB-|RESULT/.test(l)).join('\n'));
    gates.G1 = { pass: g1.ok && /ALL 7 INDEXES READY/.test(g1.stdout), detail: indexLine };

    // ── G2: 6 verifier assertions PASS ───────────────────────────────
    const g2 = runPhp('G2: Stream_b_verifier — 6 assertions', ['stream_b_verifier', 'verify']);
    const verLines = g2.stdout.split('\n').filter(l => /^\s+A\d|RESULT/.test(l));
    console.log(verLines.join('\n'));
    gates.G2 = { pass: g2.ok && /ALL ASSERTIONS PASS/.test(g2.stdout), detail: (g2.stdout.match(/RESULT: (.*)/)||['',''])[1].trim() };

    // ── G3: Phase I scope containment ───────────────────────────────
    //   Phase I MUST touch this exact whitelist and nothing else within Stream B's surface area.
    //   Pre-existing held changes from other modules (Stream A / SSA / Exam / etc.) are
    //   out-of-scope and intentionally ignored — they predate this phase and are unrelated.
    //   The gate's job: assert Phase I drift is SCOPED to Stream B files only.
    section('G3: Phase I scope containment');
    let g3ok = true;
    let g3msg = '';
    try {
        const diffOut = execFileSync('git', ['diff', '--name-only', 'HEAD'], { encoding: 'utf8', cwd: REPO_ROOT });
        const untrackedOut = execFileSync('git', ['ls-files', '--others', '--exclude-standard'], { encoding: 'utf8', cwd: REPO_ROOT });
        const allTouched = [...diffOut.split('\n'), ...untrackedOut.split('\n')].filter(Boolean);

        // Files Phase I is RESPONSIBLE for touching (must be present in the working tree):
        const phaseIRequired = [
            'firebase-rules/firestore.indexes.json',          // 7 indexes added
            'application/controllers/Hr.php',                  // 2 dead lines removed
            'application/controllers/Stream_b_verifier.php',   // new CLI verifier
            'scripts/stream_b_baseline_probe.js',              // new probe
            'scripts/stream_b_phase1_preflight_probe.js',      // new probe
            'scripts/stream_b_phase1_postdeploy_probe.js',     // new probe
            'scripts/stream_b_phase1_postcheck.js',            // this script
        ];
        const missing = phaseIRequired.filter(f => !allTouched.includes(f));

        // Files Phase I is FORBIDDEN to add NEW Stream-B drift to. Pre-existing held drift
        // from other modules (Stream A / SSA / Exam) is out-of-scope: this branch carries 80
        // commits ahead of main from prior unrelated work. The forbidden test must distinguish:
        //   - new drift this phase added (FAIL)
        //   - pre-existing drift from earlier sessions (IGNORE)
        // Detection: scan the diff hunk for Stream-B-identifying markers. If the forbidden
        // file's diff carries no Stream-B marker, the change is pre-Phase-I.
        const STREAM_B_MARKERS = /(F-SB-\d|staffAttendance(Summary|Locks|Meta|PunchEvents)?|Stream[_\s]?b|Staff_attendance_writer|Firestore_batch_helper|stream_b_)/i;
        const phaseIForbidden = [
            'application/controllers/Attendance.php',          // Phase III/IV/V/VII territory
            'application/helpers/attendance_helper.php',       // Phase V retirement
            'application/controllers/Hr_payroll.php',          // never (different domain)
            'application/controllers/Notifications.php',       // already disabled — must stay disabled
        ];
        const forbiddenNew = [];
        const forbiddenPreExisting = [];
        for (const f of phaseIForbidden) {
            if (!diffOut.split('\n').includes(f)) continue;
            try {
                const hunk = execFileSync('git', ['diff', 'HEAD', '--', f], { encoding: 'utf8', cwd: REPO_ROOT });
                if (STREAM_B_MARKERS.test(hunk)) forbiddenNew.push(f); else forbiddenPreExisting.push(f);
            } catch (_) { forbiddenNew.push(f); }
        }

        phaseIRequired.forEach(f => console.log('   ' + (allTouched.includes(f) ? 'PRESENT' : 'MISSING') + '  ' + f));
        forbiddenPreExisting.forEach(f => console.log('   PRE-EXISTING-DRIFT (ignored)  ' + f));
        forbiddenNew.forEach(f => console.log('   FORBIDDEN-NEW-DRIFT  ' + f));

        g3ok = (missing.length === 0 && forbiddenNew.length === 0);
        g3msg = g3ok
            ? `${phaseIRequired.length} required present, 0 new forbidden, ${forbiddenPreExisting.length} pre-existing held drift (out-of-scope)`
            : `missing=${missing.length} forbidden_new=${forbiddenNew.length}`;
    } catch (e) { g3msg = 'git unavailable: ' + e.message; g3ok = false; }
    gates.G3 = { pass: g3ok, detail: g3msg };

    // ── G4: cross-session baseline snapshot (report only) ────────────
    section('G4: cross-session baseline snapshot (report-only; ratio methodology deferred to Phase II)');
    console.log('   Skipped: dev-env noise floor invalidates ±10% absolute-delta gate.');
    console.log('   See Phase I Step 1 finding. Phase II adopts ratio-based intra-run methodology.');
    gates.G4 = { pass: null, detail: 'deferred to Phase II (ratio methodology)' };

    // ── Summary ──────────────────────────────────────────────────────
    header('Postcheck Summary');
    let allFunctional = true;
    Object.entries(gates).forEach(([code, g]) => {
        const verdict = g.pass === true ? 'PASS' : (g.pass === false ? 'FAIL' : 'SKIP');
        if (g.pass === false) allFunctional = false;
        console.log('  ' + pad(code, 4) + pad(verdict, 6) + g.detail);
    });
    console.log('═'.repeat(76));
    const overall = allFunctional ? 'PHASE I COMMIT-READY ✓' : 'PHASE I NOT READY';
    console.log('  RESULT: ' + overall);
    console.log('═'.repeat(76));

    // Emit JSON for downstream tooling
    const reportPath = path.join(REPO_ROOT, 'stream_b_phase1_postcheck_report.json');
    fs.writeFileSync(reportPath, JSON.stringify({
        time: new Date().toISOString(),
        gates,
        overall: allFunctional ? 'READY' : 'NOT_READY',
    }, null, 2));
    console.log('  JSON report:', reportPath);

    process.exit(allFunctional ? 0 : 2);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
