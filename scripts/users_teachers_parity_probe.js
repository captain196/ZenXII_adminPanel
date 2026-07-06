#!/usr/bin/env node
/**
 * R1 + Users/Teachers parity probe — pre-flight for Attendance/Ats retirement.
 *
 * For every live tenant:
 *   1. Enumerate Firestore staff/* where schoolId == X AND sessions array-contains <currentSession>
 *   2. Enumerate RTDB Schools/{tenantKey}/{currentSession}/Teachers
 *   3. Enumerate RTDB Users/Teachers/{tenantKey}/*
 *   4. Diff the staffId sets — surface any RTDB-only staffIds that the Firestore
 *      query would NOT return, since those would silently disappear from the
 *      attendance roster after retirement.
 *
 * READ-ONLY. No writes anywhere.
 *
 * Usage: node scripts/users_teachers_parity_probe.js
 */
const path = require('path');
const fs = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SA_PATH = path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
const SVC = JSON.parse(fs.readFileSync(SA_PATH, 'utf8'));
const DB_URL = process.env.RTDB_URL || 'https://graderadmin-default-rtdb.firebaseio.com/';
admin.initializeApp({ credential: admin.credential.cert(SVC), databaseURL: DB_URL });
const fsdb = admin.firestore();
const rtdb = admin.database();

function safeKeys(map) {
    if (!map || typeof map !== 'object') return [];
    return Object.keys(map).filter(k => k && k !== '_Counter');
}

(async function main() {
    console.log('═'.repeat(76));
    console.log('  R1 + Users/Teachers parity probe — pre-flight for retirement');
    console.log('  Time:', new Date().toISOString());
    console.log('═'.repeat(76));

    // ── Enumerate live tenants from Firestore schools/* ────────────────
    const schoolsSnap = await fsdb.collection('schools').get();
    const tenants = [];
    schoolsSnap.forEach(doc => {
        const d = doc.data() || {};
        if (doc.id.endsWith('_profile')) return; // skip junk docs
        tenants.push({
            schoolId: doc.id,
            schoolName: (d.schoolName || d.name || ''),
            schoolCode: String(d.schoolCode || d.school_code || ''),
            currentSession: (d.currentSession || ''),
            sessions: Array.isArray(d.sessions) ? d.sessions : [],
        });
    });
    console.log('\n[1] Live tenants enumerated:', tenants.length);
    tenants.forEach(t => console.log(`    ${t.schoolId} (code=${t.schoolCode}, session=${t.currentSession})`));

    // ── Per-tenant parity check ────────────────────────────────────────
    let totalDiscrepancies = 0;
    const reports = [];

    for (const t of tenants) {
        const report = {
            schoolId: t.schoolId,
            currentSession: t.currentSession,
            fs_count: 0,
            rtdb_roster_count: 0,
            rtdb_users_teachers_count: 0,
            rtdb_only_in_roster: [],
            rtdb_only_in_users_teachers: [],
            both_match: false,
        };

        if (!t.currentSession) {
            report.skip = 'no currentSession';
            reports.push(report);
            continue;
        }

        // 1. Firestore staff/* with sessions array-contains currentSession
        const fsIds = new Set();
        try {
            const snap = await fsdb.collection('staff')
                .where('schoolId', '==', t.schoolId)
                .where('sessions', 'array-contains', t.currentSession)
                .get();
            snap.forEach(doc => {
                const d = doc.data() || {};
                const sid = String(d.staffId || '');
                if (sid) fsIds.add(sid);
            });
        } catch (e) {
            report.fs_error = e.message;
        }
        report.fs_count = fsIds.size;

        // 2. RTDB Schools/{candidate}/{session}/Teachers — try multiple key candidates
        const rosterIds = new Set();
        const candidates = [t.schoolName, t.schoolId, t.schoolCode].filter(Boolean);
        for (const cand of candidates) {
            try {
                const snap = await rtdb.ref(`Schools/${cand}/${t.currentSession}/Teachers`).once('value');
                const val = snap.val();
                safeKeys(val).forEach(id => rosterIds.add(id));
            } catch (e) { /* ignore per-candidate */ }
        }
        report.rtdb_roster_count = rosterIds.size;
        report.rtdb_roster_key_candidates = candidates;

        // 3. RTDB Users/Teachers/{candidate}/*
        const usersTeachersIds = new Set();
        for (const cand of candidates) {
            try {
                const snap = await rtdb.ref(`Users/Teachers/${cand}`).once('value');
                const val = snap.val();
                safeKeys(val).forEach(id => usersTeachersIds.add(id));
            } catch (e) { /* ignore */ }
        }
        report.rtdb_users_teachers_count = usersTeachersIds.size;

        // 4. Diffs
        rosterIds.forEach(id => { if (!fsIds.has(id)) report.rtdb_only_in_roster.push(id); });
        usersTeachersIds.forEach(id => { if (!fsIds.has(id)) report.rtdb_only_in_users_teachers.push(id); });

        report.both_match = (report.rtdb_only_in_roster.length === 0 && report.rtdb_only_in_users_teachers.length === 0);
        totalDiscrepancies += report.rtdb_only_in_roster.length + report.rtdb_only_in_users_teachers.length;
        reports.push(report);
    }

    // ── Report ─────────────────────────────────────────────────────────
    console.log('\n[2] Per-tenant parity:');
    for (const r of reports) {
        console.log(`\n  ── ${r.schoolId} ──`);
        if (r.skip) { console.log(`    skip: ${r.skip}`); continue; }
        console.log(`    Firestore staff (sessions array-contains ${r.currentSession}): ${r.fs_count}`);
        console.log(`    RTDB Schools/{...}/${r.currentSession}/Teachers:                ${r.rtdb_roster_count}`);
        console.log(`    RTDB Users/Teachers/{...}:                                       ${r.rtdb_users_teachers_count}`);
        console.log(`    RTDB-roster keys tried:                                          [${r.rtdb_roster_key_candidates.join(', ')}]`);
        if (r.rtdb_only_in_roster.length > 0) {
            console.log(`    ✗ RTDB-only in Teachers roster (would be MISSED post-retirement):`);
            r.rtdb_only_in_roster.slice(0, 20).forEach(id => console.log(`        ${id}`));
            if (r.rtdb_only_in_roster.length > 20) console.log(`        ... and ${r.rtdb_only_in_roster.length - 20} more`);
        }
        if (r.rtdb_only_in_users_teachers.length > 0) {
            console.log(`    ✗ RTDB-only in Users/Teachers (would be MISSED post-retirement):`);
            r.rtdb_only_in_users_teachers.slice(0, 20).forEach(id => console.log(`        ${id}`));
            if (r.rtdb_only_in_users_teachers.length > 20) console.log(`        ... and ${r.rtdb_only_in_users_teachers.length - 20} more`);
        }
        if (r.both_match) console.log('    ✓ Firestore covers all RTDB staff ids');
    }

    console.log('\n' + '═'.repeat(76));
    console.log(`  R1 VERDICT: ${totalDiscrepancies === 0 ? '✓ PASS — Firestore is canonical superset of RTDB across all tenants' : '✗ FAIL — ' + totalDiscrepancies + ' RTDB-only staffIds would be missed'}`);
    console.log('═'.repeat(76));
    process.exit(totalDiscrepancies === 0 ? 0 : 1);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(2); });
