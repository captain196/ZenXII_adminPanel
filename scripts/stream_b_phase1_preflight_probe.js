#!/usr/bin/env node
/**
 * Phase-I pre-flight READ-ONLY probe.
 *  - Counts current Firestore docs in Stream-B collections (storage / billing baseline)
 *  - Verifies each proposed-new index target query returns *without* FAILED_PRECONDITION
 *    (i.e., confirms whether index already auto-resolves or is needed)
 *  - Estimates index build burden from doc count
 *
 * No writes. No deletes. Exits clean.
 */
const path = require('path');
const fs = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();
const TENANT = 'SCH_D94FE8F7AD';

async function tryQuery(label, builder) {
    const t0 = Date.now();
    try {
        const snap = await builder();
        return { label, ok: true, docs: snap.size, ms: Date.now() - t0, code: null };
    } catch (e) {
        return { label, ok: false, docs: 0, ms: Date.now() - t0, code: e.code || 'UNKNOWN', msg: (e.message||'').slice(0,140) };
    }
}

(async function main() {
    console.log('═'.repeat(76));
    console.log('  Phase-I Pre-flight Probe (READ-ONLY)');
    console.log('  Time:', new Date().toISOString());
    console.log('═'.repeat(76));

    console.log('\n[Collection sizes — billing/storage baseline]');
    const sizes = {};
    for (const col of ['staff', 'staffAttendance', 'staffAttendanceSummary', 'staffAttendanceMeta']) {
        try {
            const snap = await fsdb.collection(col).limit(2000).get();
            sizes[col] = snap.size;
            console.log(`  ${col.padEnd(32)} ${snap.size} docs (capped @ 2000)`);
        } catch (e) { console.log(`  ${col}: ${e.message}`); }
    }

    console.log('\n[Proposed-index probe — does it work today?]');
    const probes = [];
    probes.push(await tryQuery(
        'staffAttendance(schoolId, date)',
        () => fsdb.collection('staffAttendance')
            .where('schoolId', '==', TENANT).where('date', '==', '2026-04-13').get()
    ));
    probes.push(await tryQuery(
        'staffAttendance(schoolId, staffId, date DESC)',
        () => fsdb.collection('staffAttendance')
            .where('schoolId', '==', TENANT).where('staffId', '==', 'STA0001')
            .orderBy('date', 'desc').limit(30).get()
    ));
    probes.push(await tryQuery(
        'staffAttendanceSummary(schoolId, month)',
        () => fsdb.collection('staffAttendanceSummary')
            .where('schoolId', '==', TENANT).where('month', '==', '2026-04').get()
    ));
    probes.push(await tryQuery(
        'staffAttendanceSummary(schoolId, staffId, month DESC)',
        () => fsdb.collection('staffAttendanceSummary')
            .where('schoolId', '==', TENANT).where('staffId', '==', 'STA0001')
            .orderBy('month', 'desc').limit(12).get()
    ));
    probes.push(await tryQuery(
        'staffAttendance(schoolId, date, status)',
        () => fsdb.collection('staffAttendance')
            .where('schoolId', '==', TENANT).where('date', '==', '2026-04-13')
            .where('status', '==', 'P').get()
    ));

    console.log('  ' + 'Query target'.padEnd(52) + 'Status      Docs   ms');
    console.log('  ' + '─'.repeat(82));
    probes.forEach(p => {
        const status = p.ok ? 'OK         ' : (p.code === 9 || /FAILED_PRECONDITION|index/i.test(p.msg||'') ? 'NEEDS INDEX' : 'ERR(' + p.code + ')');
        console.log('  ' + p.label.padEnd(52) + status.padEnd(12) + String(p.docs).padStart(4) + '   ' + String(p.ms).padStart(5));
        if (!p.ok) console.log('       ↳ ' + p.msg);
    });
    process.exit(0);
})().catch(err => { console.error(err); process.exit(1); });
