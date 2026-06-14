#!/usr/bin/env node
/**
 * Stream B — CAS race-frequency + correctness simulation probe.
 *
 * Three scenarios:
 *
 *  A) Forced contention — N concurrent writes to the SAME (staff, day)
 *     measures the WORST-CASE race mechanism without CAS.
 *
 *  B) Realistic admin pattern — 100 W5 writes over 10 staff in 5 seconds
 *     (typical "admin scrolls and corrects" pattern). Measures
 *     race frequency in normal operation.
 *
 *  C) Cron + admin overlap — bulk_autofill of M staff fires concurrently
 *     with 5 manual W5 on overlapping staff. Measures the cron-vs-manual
 *     edge case.
 *
 * Tracks separately:
 *   - dayWise correctness (final string == expected last char)
 *   - present-count drift (over-count from concurrent FieldValue.increment)
 *
 * Uses synthetic tenant SCH_PROBE_CAS. Self-cleans on exit.
 */
const path = require('path');
const fs = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();
const FieldValue = admin.firestore.FieldValue;

const TENANT  = 'SCH_PROBE_CAS';
const MONTH   = '2026-04';
const DAYS_IN_MONTH = 30;
const cleanup = [];

function staffId(i) { return 'STACAS' + String(i).padStart(4, '0'); }
function summaryId(staff) { return `${TENANT}_${staff}_${MONTH}`; }
function attDocId(staff, dateISO) { return `${TENANT}_${dateISO}_${staff}`; }

async function resetSummary(staff) {
    const id = summaryId(staff);
    cleanup.push(['staffAttendanceSummary', id]);
    await fsdb.collection('staffAttendanceSummary').doc(id).set({
        schoolId: TENANT, staffId: staff, month: MONTH, year: 2026, monthNumber: 4,
        dayWise: 'V'.repeat(DAYS_IN_MONTH), totalDays: DAYS_IN_MONTH,
        present: 0, absent: 0, leave: 0, holiday: 0, tardy: 0, void: DAYS_IN_MONTH,
        _probe: true,
    });
}

/**
 * Simulates one W5 WITHOUT CAS — last-write-wins on dayWise,
 * FieldValue.increment on counts based on read-time delta.
 *
 * Returns: { ms, dayWiseSeenAtRead, deltaApplied }
 */
async function w5_no_cas(staff, dayOneBased, status) {
    const t0 = Date.now();
    const id = summaryId(staff);
    const snap = await fsdb.collection('staffAttendanceSummary').doc(id).get();
    const data = snap.data() || {};
    const dw = data.dayWise || 'V'.repeat(DAYS_IN_MONTH);
    const previous = dw[dayOneBased - 1] || 'V';
    const next = status;
    const newDw = dw.substring(0, dayOneBased - 1) + next + dw.substring(dayOneBased);
    // Delta based on previous→next status
    const counterUpdates = {};
    const prevField = { 'V': 'void', 'P': 'present', 'A': 'absent', 'L': 'leave', 'H': 'holiday', 'T': 'tardy' }[previous] || 'void';
    const nextField = { 'V': 'void', 'P': 'present', 'A': 'absent', 'L': 'leave', 'H': 'holiday', 'T': 'tardy' }[next] || 'void';
    if (prevField !== nextField) {
        counterUpdates[prevField] = FieldValue.increment(-1);
        counterUpdates[nextField] = FieldValue.increment(1);
    }
    // Write back WITHOUT precondition
    await fsdb.collection('staffAttendanceSummary').doc(id).update({
        dayWise: newDw,
        ...counterUpdates,
    });
    return { ms: Date.now() - t0, previous, next };
}

/**
 * Simulates one W5 WITH CAS — read + write inside transaction.
 * Returns: { ms, attempts, eventualSuccess }
 */
async function w5_with_cas(staff, dayOneBased, status, maxRetries = 3) {
    const t0 = Date.now();
    const id = summaryId(staff);
    const ref = fsdb.collection('staffAttendanceSummary').doc(id);
    let attempts = 0;
    let succeeded = false;
    for (; attempts < maxRetries; attempts++) {
        try {
            await fsdb.runTransaction(async (tx) => {
                const snap = await tx.get(ref);
                const data = snap.data() || {};
                const dw = data.dayWise || 'V'.repeat(DAYS_IN_MONTH);
                const previous = dw[dayOneBased - 1] || 'V';
                const newDw = dw.substring(0, dayOneBased - 1) + status + dw.substring(dayOneBased);
                const updates = { dayWise: newDw };
                const prevField = { 'V': 'void', 'P': 'present', 'A': 'absent', 'L': 'leave', 'H': 'holiday', 'T': 'tardy' }[previous] || 'void';
                const nextField = { 'V': 'void', 'P': 'present', 'A': 'absent', 'L': 'leave', 'H': 'holiday', 'T': 'tardy' }[status] || 'void';
                if (prevField !== nextField) {
                    updates[prevField] = FieldValue.increment(-1);
                    updates[nextField] = FieldValue.increment(1);
                }
                tx.update(ref, updates);
            });
            succeeded = true;
            break;
        } catch (e) {
            // tx contention → retry with backoff
            if (/aborted|FAILED_PRECONDITION|contention|10 ABORTED/i.test(e.message || '')) {
                await new Promise(r => setTimeout(r, 50 * Math.pow(2, attempts)));
                continue;
            }
            throw e;
        }
    }
    return { ms: Date.now() - t0, attempts: attempts + (succeeded ? 1 : 0), succeeded };
}

async function readSummary(staff) {
    const snap = await fsdb.collection('staffAttendanceSummary').doc(summaryId(staff)).get();
    return snap.data() || {};
}

function expectedCounts(dayWise) {
    const tally = { V: 0, P: 0, A: 0, L: 0, H: 0, T: 0 };
    for (let i = 0; i < dayWise.length; i++) tally[dayWise[i]] = (tally[dayWise[i]] || 0) + 1;
    return {
        void: tally.V, present: tally.P, absent: tally.A, leave: tally.L,
        holiday: tally.H, tardy: tally.T,
    };
}

function reportSummaryHealth(label, summary) {
    const expected = expectedCounts(summary.dayWise || '');
    const actual = {
        void: summary.void, present: summary.present, absent: summary.absent,
        leave: summary.leave, holiday: summary.holiday, tardy: summary.tardy,
    };
    const drift = {};
    let hasDrift = false;
    for (const k of Object.keys(expected)) {
        const d = (actual[k] || 0) - expected[k];
        if (d !== 0) { drift[k] = d; hasDrift = true; }
    }
    return { label, expected, actual, drift, hasDrift };
}

async function runScenarioA(useCAS, N) {
    const staff = staffId(0);
    await resetSummary(staff);

    const tasks = [];
    for (let i = 0; i < N; i++) {
        const status = ['P', 'A', 'L'][i % 3];
        tasks.push(useCAS
            ? w5_with_cas(staff, 15, status, 5)
            : w5_no_cas(staff, 15, status));
    }
    const t0 = Date.now();
    const results = await Promise.all(tasks);
    const ms = Date.now() - t0;
    const sum = await readSummary(staff);
    const health = reportSummaryHealth(`Scenario A (${useCAS ? 'CAS' : 'no-CAS'}, N=${N})`, sum);
    let totalAttempts = 0;
    results.forEach(r => totalAttempts += (r.attempts || 1));
    return { ms, health, totalAttempts, results };
}

async function runScenarioB(useCAS) {
    // 100 W5 writes spread across 10 staff over ~5 seconds (10 marks/staff)
    // Different days per staff to model "admin scrolling and correcting"
    for (let s = 0; s < 10; s++) await resetSummary(staffId(s));
    const tasks = [];
    for (let s = 0; s < 10; s++) {
        for (let d = 1; d <= 10; d++) {
            const status = ['P', 'A', 'L'][d % 3];
            tasks.push((async () => {
                // Tiny random delay 0-50ms to spread arrival
                await new Promise(r => setTimeout(r, Math.floor(Math.random() * 50)));
                if (useCAS) return await w5_with_cas(staffId(s), d, status, 3);
                return await w5_no_cas(staffId(s), d, status);
            })());
        }
    }
    const t0 = Date.now();
    const results = await Promise.all(tasks);
    const ms = Date.now() - t0;
    let driftCount = 0;
    for (let s = 0; s < 10; s++) {
        const sum = await readSummary(staffId(s));
        const h = reportSummaryHealth(`B-staff-${s}`, sum);
        if (h.hasDrift) driftCount++;
    }
    return { ms, driftCount, totalTasks: tasks.length };
}

async function runScenarioC(useCAS) {
    // 20 staff. Cron fires 20 bulk_autofill (1 mark each) at t=0.
    // 5 admin W5 fire at t=50ms targeting staff 0-4 with different status.
    for (let s = 0; s < 20; s++) await resetSummary(staffId(s));
    const cronTasks = [];
    for (let s = 0; s < 20; s++) {
        cronTasks.push((async () => {
            if (useCAS) return await w5_with_cas(staffId(s), 1, 'P', 3);
            return await w5_no_cas(staffId(s), 1, 'P');
        })());
    }
    const adminTasks = [];
    for (let s = 0; s < 5; s++) {
        adminTasks.push((async () => {
            await new Promise(r => setTimeout(r, 50));
            if (useCAS) return await w5_with_cas(staffId(s), 1, 'L', 3);
            return await w5_no_cas(staffId(s), 1, 'L');
        })());
    }
    const t0 = Date.now();
    await Promise.all([...cronTasks, ...adminTasks]);
    const ms = Date.now() - t0;
    let driftCount = 0;
    const driftDetails = [];
    for (let s = 0; s < 20; s++) {
        const sum = await readSummary(staffId(s));
        const h = reportSummaryHealth(`C-staff-${s}`, sum);
        if (h.hasDrift) { driftCount++; driftDetails.push({ staff: s, drift: h.drift }); }
    }
    return { ms, driftCount, totalStaff: 20, driftDetails: driftDetails.slice(0, 5) };
}

(async function main() {
    const hdr = '═'.repeat(76);
    console.log(hdr); console.log('  Stream B — CAS Race-Frequency + Correctness Probe');
    console.log('  Tenant:', TENANT, '  Time:', new Date().toISOString());
    console.log(hdr);

    // ── SCENARIO A: forced contention ──────────────────────────────────
    console.log('\n[A] FORCED CONTENTION — N concurrent W5 on same (staff, day=15)');
    for (const N of [2, 5, 10]) {
        const noCAS = await runScenarioA(false, N);
        const withCAS = await runScenarioA(true, N);
        console.log(`  N=${N}`);
        console.log(`    no-CAS:    ${noCAS.ms}ms  drift=${JSON.stringify(noCAS.health.drift)}`);
        console.log(`    with-CAS:  ${withCAS.ms}ms  drift=${JSON.stringify(withCAS.health.drift)}  attempts=${withCAS.totalAttempts}`);
    }

    // ── SCENARIO B: realistic admin pattern ────────────────────────────
    console.log('\n[B] REALISTIC — 100 W5 over 10 staff, 0-50ms random delays');
    const bNoCAS = await runScenarioB(false);
    const bWithCAS = await runScenarioB(true);
    console.log(`  no-CAS:    ${bNoCAS.ms}ms total, staff_with_drift=${bNoCAS.driftCount}/10`);
    console.log(`  with-CAS:  ${bWithCAS.ms}ms total, staff_with_drift=${bWithCAS.driftCount}/10`);

    // ── SCENARIO C: cron + admin overlap ───────────────────────────────
    console.log('\n[C] CRON+ADMIN OVERLAP — 20 cron autofill + 5 admin W5 within 50ms');
    const cNoCAS = await runScenarioC(false);
    const cWithCAS = await runScenarioC(true);
    console.log(`  no-CAS:    ${cNoCAS.ms}ms  staff_with_drift=${cNoCAS.driftCount}/20`);
    if (cNoCAS.driftDetails.length > 0) {
        cNoCAS.driftDetails.forEach(d => console.log('    drift on staff', d.staff, ':', JSON.stringify(d.drift)));
    }
    console.log(`  with-CAS:  ${cWithCAS.ms}ms  staff_with_drift=${cWithCAS.driftCount}/20`);

    // ── Cleanup ────────────────────────────────────────────────────────
    console.log('\n[cleanup] deleting', cleanup.length, 'probe summary docs');
    const seen = new Set();
    const uniq = cleanup.filter(([c, id]) => { const k = c + ':' + id; if (seen.has(k)) return false; seen.add(k); return true; });
    for (const [col, id] of uniq) {
        try { await fsdb.collection(col).doc(id).delete(); } catch (_) {}
    }

    console.log('\n' + hdr); console.log('  CAS race probe COMPLETE.'); console.log(hdr);
    process.exit(0);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
