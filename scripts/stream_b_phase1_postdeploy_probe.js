#!/usr/bin/env node
/**
 * Phase-I post-deploy verification probe.
 * Confirms all 7 Stream B indexes resolve their target queries.
 * Read-only.
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
        return { label, ok: true, docs: snap.size, ms: Date.now() - t0 };
    } catch (e) {
        return { label, ok: false, docs: 0, ms: Date.now() - t0, code: e.code || '?', msg: (e.message||'').slice(0,200) };
    }
}

(async function main() {
    console.log('═'.repeat(78));
    console.log('  Phase-I Post-Deploy Index Verification (7 indexes)');
    console.log('  Time:', new Date().toISOString());
    console.log('═'.repeat(78));

    const probes = [];

    probes.push(await tryQuery('F-SB-1 staffAttendance(schoolId, staffId, date DESC)',
        () => fsdb.collection('staffAttendance')
            .where('schoolId','==',TENANT).where('staffId','==','STA0001')
            .orderBy('date','desc').limit(30).get()));

    probes.push(await tryQuery('F-SB-2 staffAttendanceSummary(schoolId, staffId, month DESC)',
        () => fsdb.collection('staffAttendanceSummary')
            .where('schoolId','==',TENANT).where('staffId','==','STA0001')
            .orderBy('month','desc').limit(12).get()));

    probes.push(await tryQuery('F-SB-3 staffAttendance(schoolId, date)',
        () => fsdb.collection('staffAttendance')
            .where('schoolId','==',TENANT).where('date','==','2026-04-13').get()));

    probes.push(await tryQuery('F-SB-4 staffAttendanceSummary(schoolId, month)',
        () => fsdb.collection('staffAttendanceSummary')
            .where('schoolId','==',TENANT).where('month','==','2026-04').get()));

    probes.push(await tryQuery('F-SB-5 staffAttendance(schoolId, date, status)',
        () => fsdb.collection('staffAttendance')
            .where('schoolId','==',TENANT).where('date','==','2026-04-13').where('status','==','P').get()));

    probes.push(await tryQuery('F-SB-6 staffAttendance(schoolId, status, date range)',
        () => fsdb.collection('staffAttendance')
            .where('schoolId','==',TENANT).where('status','==','A')
            .where('date','>=','2026-04-01').where('date','<=','2026-04-30').get()));

    probes.push(await tryQuery('F-SB-7 staffAttendanceLocks(schoolId, month DESC)',
        () => fsdb.collection('staffAttendanceLocks')
            .where('schoolId','==',TENANT).orderBy('month','desc').limit(20).get()));

    console.log('\n  ' + 'Index probe'.padEnd(58) + 'Status  Docs   ms');
    console.log('  ' + '─'.repeat(78));
    let allReady = true;
    probes.forEach(p => {
        const status = p.ok ? '  OK  ' : 'FAILED';
        if (!p.ok) allReady = false;
        console.log('  ' + p.label.padEnd(58) + status + ' ' + String(p.docs).padStart(4) + '  ' + String(p.ms).padStart(5));
        if (!p.ok) console.log('       ↳ code=' + p.code + '  ' + p.msg);
    });

    console.log('\n' + '═'.repeat(78));
    console.log(allReady ? '  RESULT: ALL 7 INDEXES READY ✓' : '  RESULT: ONE OR MORE INDEXES NOT READY (likely still BUILDING)');
    console.log('═'.repeat(78));

    process.exit(allReady ? 0 : 2);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
