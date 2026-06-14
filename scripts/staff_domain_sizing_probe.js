#!/usr/bin/env node
/**
 * Production sizing probe — calibrates scalability estimates per Staff-domain surface.
 * READ-ONLY. No writes.
 */
const path = require('path');
const fs = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();
const TENANT = 'SCH_D94FE8F7AD';

(async function main() {
    console.log('═'.repeat(72));
    console.log('  Staff-domain sizing probe');
    console.log('═'.repeat(72));

    // schools/* count
    const schools = await fsdb.collection('schools').get();
    console.log(`\n[Tenants] schools/* total docs                       : ${schools.size}`);

    // staff/* per tenant
    const staffSnap = await fsdb.collection('staff').where('schoolId', '==', TENANT).get();
    console.log(`\n[Staff/SCH_D94FE8F7AD] staff/* docs                  : ${staffSnap.size}`);

    // staffAttendanceSummary count + per-month sample
    try {
        const sumSnap = await fsdb.collection('staffAttendanceSummary')
            .where('schoolId', '==', TENANT).limit(500).get();
        console.log(`[Staff/SCH_D94FE8F7AD] staffAttendanceSummary docs (capped 500): ${sumSnap.size}`);
    } catch (e) { console.log(`[Staff] staffAttendanceSummary error: ${e.message}`); }

    // Probe F5 index: query with (schoolId, month)
    try {
        const probeStart = Date.now();
        const idxProbe = await fsdb.collection('staffAttendanceSummary')
            .where('schoolId', '==', TENANT)
            .where('month', '==', '2026-04').get();
        console.log(`[F5 Probe] staffAttendanceSummary(schoolId, month) query returned ${idxProbe.size} docs in ${Date.now() - probeStart}ms`);
        console.log('  → If this succeeded, index is present OR auto-created');
    } catch (e) {
        console.log(`[F5 Probe] FAILED_PRECONDITION OR index issue: ${e.message}`);
    }

    // Probe F6 index: query (schoolId, status)
    try {
        const probeStart = Date.now();
        const idxProbe = await fsdb.collection('staff')
            .where('schoolId', '==', TENANT)
            .where('status', '==', 'Active').get();
        console.log(`[F6 Probe] staff(schoolId, status) query returned ${idxProbe.size} docs in ${Date.now() - probeStart}ms`);
    } catch (e) {
        console.log(`[F6 Probe] FAILED_PRECONDITION OR index issue: ${e.message}`);
    }

    // Probe a few existing index queries for baseline latency
    try {
        const t = Date.now();
        const a = await fsdb.collection('staff')
            .where('schoolId', '==', TENANT)
            .where('sessions', 'array-contains', '2026-27').get();
        console.log(`[Baseline] staff(schoolId, sessions array-contains) returned ${a.size} docs in ${Date.now() - t}ms`);
    } catch (e) { console.log(`[Baseline] error: ${e.message}`); }

    // userDevices/* probe (Device Registry canonical)
    try {
        const ud = await fsdb.collection('userDevices').limit(10).get();
        console.log(`\n[Device Registry] userDevices/* collection exists: ${ud.size > 0 ? 'YES — ' + ud.size + ' docs' : 'NO — empty/missing'}`);
    } catch (e) { console.log(`[Device Registry] userDevices/* error: ${e.message}`); }

    // hrJobs/* probe (ATS canonical)
    try {
        const hj = await fsdb.collection('hrJobs').limit(10).get();
        console.log(`[ATS] hrJobs/* collection: ${hj.size} docs`);
    } catch (e) { console.log(`[ATS] hrJobs/* error: ${e.message}`); }

    // schoolControl/* probe (Subscription Gate canonical)
    try {
        const sc = await fsdb.collection('schoolControl').limit(10).get();
        console.log(`[Subscription Gate] schoolControl/* collection: ${sc.size} docs (canonical lifecycle store)`);
    } catch (e) { console.log(`[Subscription Gate] schoolControl/* error: ${e.message}`); }

    process.exit(0);
})().catch(err => { console.error(err); process.exit(1); });
