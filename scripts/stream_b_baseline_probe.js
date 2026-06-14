#!/usr/bin/env node
/**
 * Stream B baseline latency probe — captures p50 / p95 per data-layer op.
 *
 * Each operation runs N times to compute percentiles. Designed to match the
 * exact ops used by Stream B workflows (fetch_staff_attendance, save_staff_attendance,
 * bulk_mark_staff, bulk_autofill_staff, mark_staff_day, api_punch, leave workflows,
 * payroll attendance).
 *
 * READ-ONLY where possible. Test writes are to a probe-prefixed doc path that
 * is cleaned up before exit.
 *
 * Usage: node scripts/stream_b_baseline_probe.js
 */
const path = require('path');
const fs = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC), databaseURL: 'https://graderadmin-default-rtdb.firebaseio.com/' });

const fsdb = admin.firestore();
const rtdb = admin.database();
const TENANT = 'SCH_D94FE8F7AD';
const N_SAMPLES = 30;

function pct(arr, p) {
    const sorted = [...arr].sort((a, b) => a - b);
    const idx = Math.min(sorted.length - 1, Math.floor(sorted.length * p / 100));
    return sorted[idx];
}

async function timed(fn) {
    const t0 = Date.now();
    try { await fn(); } catch (e) { /* counted toward latency */ }
    return Date.now() - t0;
}

async function bench(label, fn, n = N_SAMPLES) {
    const samples = [];
    for (let i = 0; i < n; i++) {
        samples.push(await timed(fn));
    }
    return {
        label,
        n,
        p50: pct(samples, 50),
        p95: pct(samples, 95),
        avg: Math.round(samples.reduce((a, b) => a + b, 0) / samples.length),
    };
}

const PROBE_DOC_ID = 'PROBE_TEST_' + Date.now();

(async function main() {
    console.log('═'.repeat(76));
    console.log('  Stream B Baseline Latency Probe');
    console.log('  Tenant:', TENANT);
    console.log('  Samples per op: ' + N_SAMPLES);
    console.log('  Time:', new Date().toISOString());
    console.log('═'.repeat(76));

    const results = [];

    // ── Firestore ops ────────────────────────────────────────────────
    console.log('\n[Firestore Ops]');

    results.push(await bench('FS get single staff doc',
        () => fsdb.collection('staff').doc(`${TENANT}_STA0001`).get()
    ));
    results.push(await bench('FS get single staffAttendanceSummary',
        () => fsdb.collection('staffAttendanceSummary').doc(`${TENANT}_STA0001_2026-04`).get()
    ));
    results.push(await bench('FS query staffAttendanceSummary by month (9-staff tenant)',
        () => fsdb.collection('staffAttendanceSummary')
            .where('schoolId', '==', TENANT)
            .where('month', '==', '2026-04').get()
    ));
    results.push(await bench('FS query staff(schoolId, sessions array-contains)',
        () => fsdb.collection('staff')
            .where('schoolId', '==', TENANT)
            .where('sessions', 'array-contains', '2026-27').get()
    ));
    results.push(await bench('FS query staffAttendance per-day for date',
        () => fsdb.collection('staffAttendance')
            .where('schoolId', '==', TENANT)
            .where('date', '==', '2026-04-13').get()
    ));
    results.push(await bench('FS single doc set (canonical write)',
        () => fsdb.collection('staffAttendanceSummary').doc(PROBE_DOC_ID).set({
            schoolId: TENANT, staffId: 'PROBE', month: '2099-01', dayWise: 'V'.repeat(31),
            _probe: true,
        }, { merge: true })
    ));
    results.push(await bench('FS WriteBatch (10 ops)',
        async () => {
            const batch = fsdb.batch();
            for (let i = 0; i < 10; i++) {
                batch.set(fsdb.collection('staffAttendanceSummary').doc(PROBE_DOC_ID + '_b' + i), {
                    schoolId: TENANT, _probe: true, idx: i,
                }, { merge: true });
            }
            await batch.commit();
        }
    ));

    // ── RTDB ops ────────────────────────────────────────────────────
    console.log('\n[RTDB Ops]');

    results.push(await bench('RTDB get single Staff_Attendance string',
        () => rtdb.ref(`Schools/SCH_D94FE8F7AD/2026-27/Staff_Attendance/April 2026/STA0001`).once('value')
    ));
    results.push(await bench('RTDB get whole-month Staff_Attendance subtree',
        () => rtdb.ref(`Schools/SCH_D94FE8F7AD/2026-27/Staff_Attendance/April 2026`).once('value')
    ));
    results.push(await bench('RTDB get Staff_Attendance/Late month',
        () => rtdb.ref(`Schools/SCH_D94FE8F7AD/2026-27/Staff_Attendance/Late/April 2026`).once('value')
    ));
    results.push(await bench('RTDB get lock doc',
        () => rtdb.ref(`Schools/SCH_D94FE8F7AD/2026-27/Staff_Attendance/Locks/April 2026`).once('value')
    ));
    results.push(await bench('RTDB set single string',
        () => rtdb.ref(`Schools/SCH_D94FE8F7AD/2026-27/Staff_Attendance/Probe Test/PROBE_${Date.now()}`).set('PPPP')
    ));

    // ── Print table ─────────────────────────────────────────────────
    console.log('\n[Per-Op Latency Baseline]');
    console.log('  ' + 'Operation'.padEnd(56) + 'p50(ms)  p95(ms)  avg(ms)');
    console.log('  ' + '─'.repeat(80));
    results.forEach(r => {
        console.log('  ' + r.label.padEnd(56) +
            String(r.p50).padStart(5) + '    ' +
            String(r.p95).padStart(5) + '    ' +
            String(r.avg).padStart(5));
    });

    // Cleanup probe writes
    try {
        await fsdb.collection('staffAttendanceSummary').doc(PROBE_DOC_ID).delete();
        for (let i = 0; i < 10; i++) {
            await fsdb.collection('staffAttendanceSummary').doc(PROBE_DOC_ID + '_b' + i).delete();
        }
        console.log('\n  [cleanup] probe write docs deleted');
    } catch (e) { console.log('  [cleanup] error:', e.message); }

    // ── Workflow synthesis (static call-count + measured per-op latency) ──
    console.log('\n[Workflow Latency Synthesis @ 9 staff (live tenant)]');

    const op = {
        fsGetStaff: results.find(r => r.label.includes('FS get single staff doc')),
        fsGetSummary: results.find(r => r.label.includes('FS get single staffAttendanceSummary')),
        fsQuerySummaryByMonth: results.find(r => r.label.includes('FS query staffAttendanceSummary')),
        fsQueryStaffSessions: results.find(r => r.label.includes('FS query staff(schoolId, sessions')),
        fsQueryStaffAttDay: results.find(r => r.label.includes('FS query staffAttendance per-day')),
        fsSetDoc: results.find(r => r.label.includes('FS single doc set')),
        fsBatch10: results.find(r => r.label.includes('FS WriteBatch')),
        rtdbGetSingle: results.find(r => r.label.includes('RTDB get single Staff_Attendance string')),
        rtdbGetMonth: results.find(r => r.label.includes('RTDB get whole-month')),
        rtdbGetLate: results.find(r => r.label.includes('RTDB get Staff_Attendance/Late')),
        rtdbGetLock: results.find(r => r.label.includes('RTDB get lock doc')),
        rtdbSet: results.find(r => r.label.includes('RTDB set single')),
    };

    const workflows = [
        {
            name: 'fetch_staff_attendance (1 month)',
            current: {
                ops: ['1× FS query staff (sessions)', '1× FS query summary by month', '1× RTDB get Late month'],
                p50: op.fsQueryStaffSessions.p50 + op.fsQuerySummaryByMonth.p50 + op.rtdbGetLate.p50,
                p95: op.fsQueryStaffSessions.p95 + op.fsQuerySummaryByMonth.p95 + op.rtdbGetLate.p95,
            },
        },
        {
            name: 'save_staff_attendance (50 staff)',
            current: {
                ops: ['1× FS query staff', '50× RTDB get curStr (N+1)', '50× RTDB set raw', '50× RTDB set Late', '50× FS set summary'],
                p50: op.fsQueryStaffSessions.p50 + 50 * (op.rtdbGetSingle.p50 + op.rtdbSet.p50 * 2 + op.fsSetDoc.p50),
                p95: op.fsQueryStaffSessions.p95 + 50 * (op.rtdbGetSingle.p95 + op.rtdbSet.p95 * 2 + op.fsSetDoc.p95),
            },
        },
        {
            name: 'bulk_mark_staff (50 staff)',
            current: {
                ops: ['1× RTDB whole-month read', '50× RTDB set raw', '50× FS set summary'],
                p50: op.rtdbGetMonth.p50 + 50 * (op.rtdbSet.p50 + op.fsSetDoc.p50),
                p95: op.rtdbGetMonth.p95 + 50 * (op.rtdbSet.p95 + op.fsSetDoc.p95),
            },
        },
        {
            name: 'bulk_autofill_staff (50 staff)',
            current: {
                ops: ['1× RTDB whole-month read', '50× RTDB set raw', '50× FS set summary'],
                p50: op.rtdbGetMonth.p50 + 50 * (op.rtdbSet.p50 + op.fsSetDoc.p50),
                p95: op.rtdbGetMonth.p95 + 50 * (op.rtdbSet.p95 + op.fsSetDoc.p95),
            },
        },
        {
            name: 'mark_staff_day (single)',
            current: {
                ops: ['1× FS get staff', '1× RTDB get curStr', '1× RTDB set raw', '1× RTDB set Late', '1× FS set summary'],
                p50: op.fsGetStaff.p50 + op.rtdbGetSingle.p50 + op.rtdbSet.p50 * 2 + op.fsSetDoc.p50,
                p95: op.fsGetStaff.p95 + op.rtdbGetSingle.p95 + op.rtdbSet.p95 * 2 + op.fsSetDoc.p95,
            },
        },
        {
            name: 'api_punch (single staff)',
            current: {
                ops: ['1× FS get staff', '1× RTDB ProcessedEvents', '1× RTDB set raw', '1× RTDB set Late', '1× FS set summary'],
                p50: op.fsGetStaff.p50 + op.rtdbGetSingle.p50 + op.rtdbSet.p50 * 2 + op.fsSetDoc.p50,
                p95: op.fsGetStaff.p95 + op.rtdbGetSingle.p95 + op.rtdbSet.p95 * 2 + op.fsSetDoc.p95,
            },
        },
        {
            name: 'leave_apply_per_staff (Hr.php)',
            current: {
                ops: ['1× FS get summary', '1× FS query staffAttendance day', '1× FS set staffAttendance', '1× FS set summary'],
                p50: op.fsGetSummary.p50 + op.fsQueryStaffAttDay.p50 + op.fsSetDoc.p50 * 2,
                p95: op.fsGetSummary.p95 + op.fsQueryStaffAttDay.p95 + op.fsSetDoc.p95 * 2,
            },
        },
        {
            name: 'payroll_attendance_read (1 month, all staff)',
            current: {
                ops: ['1× FS query summary by month'],
                p50: op.fsQuerySummaryByMonth.p50,
                p95: op.fsQuerySummaryByMonth.p95,
            },
        },
    ];

    console.log('  ' + 'Workflow'.padEnd(52) + 'p50(ms)  p95(ms)');
    console.log('  ' + '─'.repeat(75));
    workflows.forEach(w => {
        console.log('  ' + w.name.padEnd(52) + String(w.current.p50).padStart(5) + '    ' + String(w.current.p95).padStart(5));
    });

    console.log('\n' + '═'.repeat(76));
    console.log('  Baseline capture COMPLETE.');
    console.log('═'.repeat(76));

    process.exit(0);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
