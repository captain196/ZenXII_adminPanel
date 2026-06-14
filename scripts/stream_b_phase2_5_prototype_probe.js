#!/usr/bin/env node
/**
 * Phase II + Phase V prototype latency probe.
 *
 * Measures the EXACT op shape the new writer will use, without touching PHP code.
 * Uses probe-prefixed doc IDs that are cleaned up at exit. Read-mostly otherwise.
 *
 * Phase II path (W5 single-mark):
 *   1. get lock doc by composite ID (cold case: doc absent)
 *   2. get summary doc by composite ID (cold case: doc absent → treat dayWise='V'*31)
 *   3. set staffAttendance/{schoolId}_{date}_{staffId}
 *   4. set staffAttendanceSummary/{schoolId}_{staffId}_{monthKey} (with computed dayWise)
 *
 * Phase V path (W3 bulk_mark M staff):
 *   1. get lock doc by composite ID
 *   2. query F-SB-4: staffAttendanceSummary(schoolId, month) — returns existing M summaries
 *   3. WriteBatch(2M) — staffAttendance set per staff + summary set per staff, chunked at 400
 *
 * Measured for M ∈ {1, 10, 50, 100, 200} so we can extrapolate to 500/1000.
 *
 * Cleanup: every PROBE_* doc deleted on exit.
 */
const path = require('path');
const fs = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();
const FieldValue = admin.firestore.FieldValue;

const TENANT  = 'SCH_PROBE_STREAMB';   // synthetic tenant to avoid touching the live live-tenant data
const SESSION = '2026-27';
const MONTH   = '2026-04';
const PROBE_DATE = '2026-04-15';
const N_SAMPLES = 5;                   // smaller per-M cycle to cap test-tenant write volume
const cleanupTargets = [];

function pct(arr, p) {
    const s = [...arr].sort((a, b) => a - b);
    return s[Math.min(s.length - 1, Math.floor(s.length * p / 100))];
}

async function timed(fn) {
    const t0 = Date.now();
    try { await fn(); } catch (e) { /* counted */ }
    return Date.now() - t0;
}

function staffId(i) { return 'STAPROBE' + String(i).padStart(4, '0'); }

function dayWiseEmpty(len) { return 'V'.repeat(len); }

// ── Prototype Phase II — single-mark W5 ──────────────────────────────
async function probePhaseII(label) {
    const samples = [];
    for (let i = 0; i < N_SAMPLES; i++) {
        const sid = staffId(i);
        const summaryId = `${TENANT}_${sid}_${MONTH}`;
        const attDocId  = `${TENANT}_${PROBE_DATE}_${sid}`;
        const lockId    = `${TENANT}_${SESSION}_${MONTH}`;
        cleanupTargets.push(['staffAttendance', attDocId], ['staffAttendanceSummary', summaryId]);
        samples.push(await timed(async () => {
            // 1. lock check
            await fsdb.collection('staffAttendanceLocks').doc(lockId).get();
            // 2. summary get
            const sumSnap = await fsdb.collection('staffAttendanceSummary').doc(summaryId).get();
            const dayWise = (sumSnap.exists ? (sumSnap.data().dayWise || dayWiseEmpty(30)) : dayWiseEmpty(30));
            // 3. staffAttendance set
            await fsdb.collection('staffAttendance').doc(attDocId).set({
                schoolId: TENANT, session: SESSION, date: PROBE_DATE, staffId: sid,
                status: 'P', markedBy: 'PROBE', markedAt: new Date().toISOString(),
                source: 'manual', lateMinutes: 0,
                _createdAt: FieldValue.serverTimestamp(), _updatedAt: FieldValue.serverTimestamp(),
                _probe: true,
            });
            // 4. summary set
            const idx = 15 - 1; // day-of-month - 1
            const updated = dayWise.substring(0, idx) + 'P' + dayWise.substring(idx + 1);
            await fsdb.collection('staffAttendanceSummary').doc(summaryId).set({
                schoolId: TENANT, session: SESSION, staffId: sid, month: MONTH,
                year: 2026, monthNumber: 4, dayWise: updated, totalDays: 30,
                present: 1, absent: 0, leave: 0, holiday: 0, tardy: 0, void: 29,
                workingDays: 1, lateDays: 0, totalLateMinutes: 0,
                _computedAt: FieldValue.serverTimestamp(), _updatedAt: FieldValue.serverTimestamp(),
                _probe: true,
            }, { merge: true });
        }));
    }
    return { label, p50: pct(samples, 50), p95: pct(samples, 95), avg: Math.round(samples.reduce((a,b)=>a+b,0)/samples.length) };
}

// ── Prototype Phase V — bulk W3 over M staff ─────────────────────────
async function probePhaseV(label, M) {
    const samples = [];
    for (let s = 0; s < N_SAMPLES; s++) {
        const cycleStaff = Array.from({length: M}, (_, i) => staffId(i + s * M));
        samples.push(await timed(async () => {
            const lockId = `${TENANT}_${SESSION}_${MONTH}`;
            await fsdb.collection('staffAttendanceLocks').doc(lockId).get();
            const existing = await fsdb.collection('staffAttendanceSummary')
                .where('schoolId', '==', TENANT).where('month', '==', MONTH).get();
            const sumByStaff = {};
            existing.forEach(d => { const x = d.data(); sumByStaff[x.staffId] = x.dayWise || dayWiseEmpty(30); });
            // Build all writes
            const ops = [];
            const idx = 15 - 1;
            for (const sid of cycleStaff) {
                const attDocId  = `${TENANT}_${PROBE_DATE}_${sid}`;
                const summaryId = `${TENANT}_${sid}_${MONTH}`;
                const dw = sumByStaff[sid] || dayWiseEmpty(30);
                const updated = dw.substring(0, idx) + 'P' + dw.substring(idx + 1);
                ops.push(['staffAttendance', attDocId, {
                    schoolId: TENANT, session: SESSION, date: PROBE_DATE, staffId: sid,
                    status: 'P', markedBy: 'PROBE_BULK', markedAt: new Date().toISOString(),
                    source: 'bulk', lateMinutes: 0, _probe: true,
                }]);
                ops.push(['staffAttendanceSummary', summaryId, {
                    schoolId: TENANT, session: SESSION, staffId: sid, month: MONTH,
                    year: 2026, monthNumber: 4, dayWise: updated, totalDays: 30,
                    present: 1, void: 29, _probe: true,
                }]);
                cleanupTargets.push(['staffAttendance', attDocId], ['staffAttendanceSummary', summaryId]);
            }
            // Commit in chunks of 400
            const CHUNK = 400;
            for (let i = 0; i < ops.length; i += CHUNK) {
                const batch = fsdb.batch();
                for (const [col, id, data] of ops.slice(i, i + CHUNK)) {
                    batch.set(fsdb.collection(col).doc(id), data, { merge: true });
                }
                await batch.commit();
            }
        }));
    }
    return {
        label, M, p50: pct(samples, 50), p95: pct(samples, 95),
        avg: Math.round(samples.reduce((a,b)=>a+b,0)/samples.length),
        batches: Math.ceil(2 * M / 400),
    };
}

(async function main() {
    const hdr = '═'.repeat(76);
    console.log(hdr); console.log('  Stream B Phase II + V Prototype Probe');
    console.log('  Synthetic tenant:', TENANT, ' Time:', new Date().toISOString());
    console.log(hdr);

    const results = { phaseII: null, phaseV: [] };

    console.log('\n[Phase II — single-day-mark W5 shape, n=' + N_SAMPLES + ']');
    results.phaseII = await probePhaseII('W5 4-op shape');
    console.log('  ' + results.phaseII.label.padEnd(20) + 'p50=' + String(results.phaseII.p50).padStart(5) +
                ' ms  p95=' + String(results.phaseII.p95).padStart(5) + ' ms  avg=' + results.phaseII.avg);

    console.log('\n[Phase V — bulk W3 shape, n=' + N_SAMPLES + ' per M]');
    console.log('  ' + 'M staff'.padEnd(10) + 'p50(ms) p95(ms) avg(ms) batches');
    for (const M of [1, 10, 50, 100, 200]) {
        const r = await probePhaseV('M=' + M, M);
        results.phaseV.push(r);
        console.log('  ' + ('M=' + M).padEnd(10) + String(r.p50).padStart(5) + '   ' +
                    String(r.p95).padStart(5) + '   ' + String(r.avg).padStart(5) + '   ' + r.batches);
    }

    // Extrapolation to 500/1000/2000 by linear fit on the batches dimension
    console.log('\n[Extrapolation by batch count]');
    const r200 = results.phaseV.find(x => x.M === 200);
    const r100 = results.phaseV.find(x => x.M === 100);
    if (r200 && r100) {
        // Linear extrapolation: latency_per_batch = (r200.p95 - r100.p95) / (r200.batches - r100.batches)
        const dl = r200.p95 - r100.p95;
        const db = r200.batches - r100.batches;
        const perBatch = db > 0 ? dl / db : (r200.p95 - r100.p95);
        const fixedOverhead = r100.p95 - r100.batches * perBatch;
        console.log('  estimated per-batch p95 delta:', Math.round(perBatch), 'ms');
        console.log('  estimated fixed overhead:    ', Math.round(fixedOverhead), 'ms');
        for (const M of [500, 1000, 2000]) {
            const batches = Math.ceil(2 * M / 400);
            const projP95 = Math.round(fixedOverhead + batches * perBatch);
            console.log(`  M=${M.toString().padStart(4)}  batches=${batches}  projected p95 ≈ ${projP95} ms`);
        }
    }

    // Cleanup
    console.log('\n[cleanup] deleting ' + cleanupTargets.length + ' probe docs...');
    let cleaned = 0, errors = 0;
    const seen = new Set();
    const uniq = cleanupTargets.filter(([c, id]) => { const k = c + ':' + id; if (seen.has(k)) return false; seen.add(k); return true; });
    const CHUNK = 400;
    for (let i = 0; i < uniq.length; i += CHUNK) {
        try {
            const batch = fsdb.batch();
            for (const [col, id] of uniq.slice(i, i + CHUNK)) batch.delete(fsdb.collection(col).doc(id));
            await batch.commit();
            cleaned += Math.min(CHUNK, uniq.length - i);
        } catch (e) { errors++; }
    }
    console.log('  cleaned ' + cleaned + ' docs, errors=' + errors);

    console.log('\n' + hdr); console.log('  Prototype probe COMPLETE.'); console.log(hdr);
    process.exit(0);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
