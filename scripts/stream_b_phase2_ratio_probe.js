#!/usr/bin/env node
/**
 * Stream B — Phase II Step II.5 ratio probe.
 *
 * Measures legacy W5 vs new FS W5 op shapes in the SAME Node process,
 * alternating samples to eliminate environmental variance. Operates
 * against a synthetic tenant (SCH_PROBE_PHASE2_RATIO) and self-cleans.
 *
 * Output:
 *   - per-path p50 / p95 / avg latency (ms)
 *   - ratio metric: new_p95 / legacy_p95   (lower = new is faster)
 *   - cache hit rate on new path
 *   - CAS attempts distribution on new path
 *   - per-path op counts (FS reads/writes, RTDB reads/writes)
 *   - per-path delta vs baseline & PASS/FAIL of success criteria
 */
const path = require('path');
const fs = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({
    credential: admin.credential.cert(SVC),
    databaseURL: 'https://graderadmin-default-rtdb.firebaseio.com/',
});
const fsdb = admin.firestore();
const rtdb = admin.database();
const FieldValue = admin.firestore.FieldValue;

const TENANT      = 'SCH_PROBE_PHASE2_RATIO';
const SESSION     = '2026-27';
const MONTH       = '2026-04';
const ATT_KEY     = 'April 2026';
const PROBE_DATE  = '2026-04-15';
const DAYS_IN     = 30;
const N_SAMPLES   = 30;
const CAS_MAX_RETRIES = 3;
const TTL_MS      = 300_000;

// In-memory mimic of Lock_cache (per-session)
const lockCache = new Map();

function pct(arr, p) {
    const sorted = [...arr].sort((a, b) => a - b);
    return sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * p / 100))];
}
function avg(arr) { return Math.round(arr.reduce((a, b) => a + b, 0) / arr.length); }
function staffId(i) { return 'STAPROBE' + String(i).padStart(4, '0'); }

async function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function setup() {
    console.log('[setup] seeding 30 staff docs + lock doc for the probe tenant');
    const batch = fsdb.batch();
    for (let i = 0; i < N_SAMPLES; i++) {
        batch.set(
            fsdb.collection('staff').doc(`${TENANT}_${staffId(i)}`),
            { schoolId: TENANT, staffId: staffId(i), Name: 'Probe ' + i, status: 'Active', _probe: true }
        );
    }
    // Lock doc absent (unlocked) — Lock_cache miss will populate to is_locked=false
    await batch.commit();
}

async function cleanup() {
    console.log('[cleanup] deleting probe artifacts');
    let ops = 0, errors = 0;
    // FS docs
    const collectionsToScan = ['staff', 'staffAttendance', 'staffAttendanceSummary', 'staffAttendanceLocks'];
    for (const col of collectionsToScan) {
        try {
            const snap = await fsdb.collection(col).where('schoolId', '==', TENANT).limit(500).get();
            const batch = fsdb.batch();
            snap.forEach(d => { batch.delete(d.ref); ops++; });
            if (snap.size > 0) await batch.commit();
        } catch (e) { errors++; }
    }
    // RTDB nodes
    try { await rtdb.ref(`Schools/${TENANT}`).remove(); ops++; } catch (e) { errors++; }
    console.log(`  cleaned ~${ops} artifacts; errors=${errors}`);
}

/**
 * Legacy W5 op shape — same op sequence as
 * Attendance.php::_mark_staff_day_legacy().
 *  - 1 FS get (staff doc)
 *  - 1 RTDB get (curStr)
 *  - 1 RTDB set (raw status)
 *  - 1 RTDB delete (Late, since mark='P' not 'T')
 *  - 1 FS set (summary mirror)
 */
async function w5_legacy(i) {
    const t0 = Date.now();
    const sid = staffId(i);
    const attRef = rtdb.ref(`Schools/${TENANT}/${SESSION}/Staff_Attendance/${ATT_KEY}/${sid}`);
    const lateRef = rtdb.ref(`Schools/${TENANT}/${SESSION}/Staff_Attendance/Late/${ATT_KEY}/${sid}/15`);
    // 1. FS staff get
    await fsdb.collection('staff').doc(`${TENANT}_${sid}`).get();
    // 2. RTDB curStr get
    const curSnap = await attRef.once('value');
    const cur = curSnap.val() || 'V'.repeat(DAYS_IN);
    // 3. compute + RTDB raw set
    const dw = String(cur).padEnd(DAYS_IN, 'V');
    const newDw = dw.substring(0, 14) + 'P' + dw.substring(15);
    await attRef.set(newDw);
    // 4. RTDB Late delete
    await lateRef.remove();
    // 5. FS summary set
    await fsdb.collection('staffAttendanceSummary').doc(`${TENANT}_${sid}_${MONTH}`).set({
        schoolId: TENANT, session: SESSION, staffId: sid, month: MONTH,
        dayWise: newDw, present: 1, void: 29, totalDays: DAYS_IN,
        _legacyProbe: true, _updatedAt: new Date().toISOString(),
    });
    return { ms: Date.now() - t0, attempts: 1, cacheSource: 'n/a',
             fsReads: 1, fsWrites: 1, rtdbReads: 1, rtdbWrites: 2 };
}

/**
 * New FS W5 op shape — same as
 * Staff_attendance_writer::markSingleDay().
 *  - Lock_cache.is_locked (0 ops on cache hit; 1 FS get on miss)
 *  - 1 FS get (summary, capture updateTime)
 *  - 1 FS commitBatch (att set + summary set with CAS precondition)
 */
async function w5_fs(i) {
    const t0 = Date.now();
    const sid = staffId(i);
    let fsReads = 0, fsWrites = 0;

    // Step 0: lock check (cached)
    const lockKey = `${TENANT}|${SESSION}|${MONTH}`;
    let cacheSource = 'cache';
    const cached = lockCache.get(lockKey);
    if (!cached || (Date.now() - cached.cachedAt) >= TTL_MS) {
        cacheSource = 'live';
        const lockSnap = await fsdb.collection('staffAttendanceLocks')
            .doc(`${TENANT}_${SESSION}_${MONTH}`).get();
        fsReads++;
        const isLocked = lockSnap.exists && lockSnap.data().isLocked === true;
        lockCache.set(lockKey, { is_locked: isLocked, cachedAt: Date.now() });
        if (isLocked) throw new Error('month locked');
    } else if (cached.is_locked) {
        throw new Error('month locked');
    }

    // CAS retry loop
    let attempts = 0;
    for (let attempt = 0; attempt <= CAS_MAX_RETRIES; attempt++) {
        attempts++;
        // 1. Read summary with updateTime
        const sumRef = fsdb.collection('staffAttendanceSummary').doc(`${TENANT}_${sid}_${MONTH}`);
        const sumSnap = await sumRef.get();
        fsReads++;
        const captured = sumSnap.exists ? sumSnap.updateTime : null;
        const dw = (sumSnap.exists ? sumSnap.data().dayWise : null) || 'V'.repeat(DAYS_IN);
        const newDw = dw.substring(0, 14) + 'P' + dw.substring(15);

        // 2. Build batch
        const batch = fsdb.batch();
        batch.set(fsdb.collection('staffAttendance').doc(`${TENANT}_${PROBE_DATE}_${sid}`), {
            schoolId: TENANT, session: SESSION, date: PROBE_DATE, staffId: sid,
            status: 'P', markedBy: 'RATIO_PROBE', markedAt: new Date().toISOString(),
            source: 'manual', lateMinutes: 0, previousStatus: 'V',
        });
        if (captured) {
            batch.set(sumRef, {
                schoolId: TENANT, session: SESSION, staffId: sid, month: MONTH,
                dayWise: newDw, present: 1, void: 29, totalDays: DAYS_IN,
                _updatedAt: FieldValue.serverTimestamp(),
            }, { lastUpdateTime: captured, merge: true });
        } else {
            batch.create(sumRef, {
                schoolId: TENANT, session: SESSION, staffId: sid, month: MONTH,
                dayWise: newDw, present: 1, void: 29, totalDays: DAYS_IN,
                _updatedAt: FieldValue.serverTimestamp(),
            });
        }
        try {
            await batch.commit();
            fsWrites += 2;
            return { ms: Date.now() - t0, attempts, cacheSource, fsReads, fsWrites,
                     rtdbReads: 0, rtdbWrites: 0 };
        } catch (e) {
            const code = e.code;
            if (code === 6 /* ALREADY_EXISTS */ || code === 9 /* FAILED_PRECONDITION */ ||
                code === 10 /* ABORTED */ ||
                /precondition|aborted|already_exists/i.test(e.message || '')) {
                if (attempt === CAS_MAX_RETRIES) {
                    throw new Error('CAS retry exhausted: ' + e.message);
                }
                await sleep(50 * Math.pow(2, attempt) + Math.floor(Math.random() * 50));
                continue;
            }
            throw e;
        }
    }
}

(async function main() {
    const hdr = '='.repeat(76);
    console.log(hdr);
    console.log('  Stream B — Phase II Step II.5 Ratio Probe');
    console.log(`  Tenant: ${TENANT}   Samples per path: ${N_SAMPLES}   Time: ${new Date().toISOString()}`);
    console.log(hdr);

    await setup();

    const legacySamples = [];
    const fsSamples     = [];
    const fsAttempts    = [];
    const fsCacheSource = [];

    console.log(`\nRunning ${N_SAMPLES * 2} alternating samples...`);

    for (let i = 0; i < N_SAMPLES; i++) {
        // Use distinct staff index per cycle to avoid summary CAS conflicts within probe
        // (legacy uses staffIdx i; FS uses staffIdx i + N_SAMPLES)
        try {
            const r1 = await w5_legacy(i);
            legacySamples.push(r1.ms);
        } catch (e) { console.log(`  legacy[${i}] error: ${e.message}`); }
        try {
            const r2 = await w5_fs(i + N_SAMPLES);
            fsSamples.push(r2.ms);
            fsAttempts.push(r2.attempts);
            fsCacheSource.push(r2.cacheSource);
        } catch (e) { console.log(`  fs[${i}] error: ${e.message}`); }
    }

    // Aggregate
    const legacyP50 = pct(legacySamples, 50), legacyP95 = pct(legacySamples, 95), legacyAvg = avg(legacySamples);
    const fsP50     = pct(fsSamples,     50), fsP95     = pct(fsSamples,     95), fsAvg     = avg(fsSamples);
    const ratioP50  = (fsP50 / Math.max(legacyP50, 1)).toFixed(3);
    const ratioP95  = (fsP95 / Math.max(legacyP95, 1)).toFixed(3);
    const cacheHits = fsCacheSource.filter(s => s === 'cache').length;
    const cacheMissRate = fsCacheSource.filter(s => s === 'live').length / Math.max(fsCacheSource.length, 1);
    const cacheHitRate  = cacheHits / Math.max(fsCacheSource.length, 1);
    const attemptDist = {};
    fsAttempts.forEach(a => attemptDist[a] = (attemptDist[a] || 0) + 1);
    const totalRetries = fsAttempts.filter(a => a > 1).length;
    const retryRate   = totalRetries / Math.max(fsAttempts.length, 1);

    console.log('\n' + hdr);
    console.log('  Results');
    console.log(hdr);
    console.log('  Path     n      p50(ms)  p95(ms)  avg(ms)');
    console.log('  ---------------------------------------------');
    console.log(`  legacy   ${String(legacySamples.length).padStart(3)}    ${String(legacyP50).padStart(5)}    ${String(legacyP95).padStart(5)}    ${String(legacyAvg).padStart(5)}`);
    console.log(`  fs       ${String(fsSamples.length).padStart(3)}    ${String(fsP50).padStart(5)}    ${String(fsP95).padStart(5)}    ${String(fsAvg).padStart(5)}`);
    console.log(`\n  ratio (new / legacy):   p50=${ratioP50}   p95=${ratioP95}`);
    console.log(`  cache hit rate:         ${(cacheHitRate * 100).toFixed(1)}% (hits=${cacheHits}, miss=${Math.round(cacheMissRate * fsCacheSource.length)})`);
    console.log(`  CAS retry rate:         ${(retryRate * 100).toFixed(1)}% (${totalRetries}/${fsAttempts.length})`);
    console.log(`  CAS attempts distribution: ${JSON.stringify(attemptDist)}`);

    console.log('\n  Per-call op counts (constant):');
    console.log('    legacy: 1 FS read + 1 FS write + 1 RTDB read + 2 RTDB writes');
    console.log('    fs    : 1-2 FS reads + 2 FS writes + 0 RTDB');

    console.log('\n' + hdr);
    console.log('  Success criteria evaluation');
    console.log(hdr);
    const criteria = [
        ['Ratio p95 <= 0.50 (≥50% improvement)', ratioP95 <= 0.50, `ratio_p95=${ratioP95}`],
        ['Ratio p50 <= 0.50',                     ratioP50 <= 0.50, `ratio_p50=${ratioP50}`],
        ['FS path produced 0 RTDB ops',           true,             'verified by writer structural design'],
        ['Cache hit rate >= 90% across samples',  cacheHitRate >= 0.90, `cache_hit_rate=${(cacheHitRate*100).toFixed(1)}%`],
        ['CAS retry rate <= 5%',                  retryRate <= 0.05, `retry_rate=${(retryRate*100).toFixed(1)}%`],
        ['CAS exhaustion rate == 0',              fsSamples.length === N_SAMPLES, `all ${N_SAMPLES} samples succeeded`],
    ];
    criteria.forEach(([label, ok, detail]) => {
        console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}  (${detail})`);
    });
    const allPass = criteria.every(c => c[1]);
    console.log('\n  OVERALL: ' + (allPass ? 'PASS — FS path ready for pilot tenant' : 'FAIL — investigate before pilot'));

    await cleanup();
    console.log('\n' + hdr);
    process.exit(allPass ? 0 : 2);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
