#!/usr/bin/env node
/**
 * Units A+B+C pre-flight probe.
 *
 * READ-ONLY. Verifies prerequisites for SSA-lifecycle Firestore-only migration:
 *
 *   PF-1: superAdmins/* Firestore covers every SUP in RTDB Users/Admin/Our Panel
 *   PF-2: security_events/* has ADMIN_LOGIN_SUCCESS events for active SSAs
 *
 * Usage: node scripts/unit_abc_preflight_probe.js
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
const TENANT = 'SCH_D94FE8F7AD';

(async function main() {
    console.log('═'.repeat(76));
    console.log('  UNIT A+B+C PRE-FLIGHT PROBE');
    console.log('  Time:', new Date().toISOString());
    console.log('═'.repeat(76));

    // ── PF-1: superAdmins/* coverage of Our Panel ────────────────────────
    console.log('\n[PF-1] superAdmins/* Firestore vs Users/Admin/Our Panel RTDB');
    const fsSup = new Map();
    try {
        const snap = await fsdb.collection('superAdmins').get();
        snap.forEach(doc => {
            const d = doc.data() || {};
            const sid = d.superAdminId || doc.id;
            if (sid) fsSup.set(sid, { name: d.name || d.Name || '', role: d.role || d.Role || '' });
        });
    } catch (e) { console.log('    ERR superAdmins read:', e.message); }
    console.log('    Firestore superAdmins/* count:', fsSup.size);
    fsSup.forEach((v, k) => console.log('      FS  ' + k + '  name="' + v.name + '"  role="' + v.role + '"'));

    const rtdbSup = new Map();
    try {
        const snap = await rtdb.ref('Users/Admin/Our Panel').once('value');
        const val = snap.val() || {};
        Object.entries(val).forEach(([k, v]) => {
            if (k && k !== '_Counter' && /^SUP\d+$/.test(k)) {
                rtdbSup.set(k, {
                    name: (v && (v.Name || v.name)) || '',
                    role: (v && (v.Role || v.role)) || '',
                });
            }
        });
    } catch (e) { console.log('    ERR Our Panel read:', e.message); }
    console.log('    RTDB Our Panel SUP* count:', rtdbSup.size);
    rtdbSup.forEach((v, k) => console.log('      RTDB ' + k + '  name="' + v.name + '"  role="' + v.role + '"'));

    const missingInFs = [];
    rtdbSup.forEach((v, k) => { if (!fsSup.has(k)) missingInFs.push(k); });
    const extraInFs = [];
    fsSup.forEach((v, k) => { if (!rtdbSup.has(k)) extraInFs.push(k); });

    const pf1Pass = (missingInFs.length === 0);
    console.log('\n    Coverage check:');
    console.log('      RTDB SUP* present in Firestore:', rtdbSup.size - missingInFs.length, '/', rtdbSup.size);
    if (missingInFs.length > 0) console.log('      ✗ MISSING in Firestore: [' + missingInFs.join(', ') + ']');
    if (extraInFs.length > 0)   console.log('      ~ Firestore-only      : [' + extraInFs.join(', ') + ']  (acceptable; superAdmins is canonical)');
    console.log('    PF-1 VERDICT:', pf1Pass ? '✓ PASS' : '✗ FAIL — backfill missing SUPs into superAdmins/* first');

    // ── PF-2: security_events ADMIN_LOGIN_SUCCESS coverage of active SSAs ──
    console.log('\n[PF-2] security_events/* ADMIN_LOGIN_SUCCESS coverage of active SSAs');
    // First, enumerate active SSAs from staff/*
    const activeSsas = [];
    try {
        const snap = await fsdb.collection('staff').where('schoolId', '==', TENANT).get();
        snap.forEach(doc => {
            const d = doc.data() || {};
            const status = (d.Status || d.status || '').toString();
            const role = (d.Role || d.role || '').toString();
            const sid = d.staffId || '';
            if (sid && /^SSA\d+$/.test(sid) && /school super admin/i.test(role) && /active/i.test(status)) {
                activeSsas.push({ sid, name: d.Name || d.name || '' });
            }
        });
    } catch (e) { console.log('    ERR staff/* read:', e.message); }
    console.log('    Active SSAs in tenant:', activeSsas.length);
    activeSsas.forEach(s => console.log('      ' + s.sid + '  name="' + s.name + '"'));

    // Query security_events for ADMIN_LOGIN_SUCCESS events
    const eventsBySsa = new Map();
    let totalLoginEvents = 0;
    try {
        const snap = await fsdb.collection('security_events')
            .where('event_type', '==', 'ADMIN_LOGIN_SUCCESS')
            .get();
        snap.forEach(doc => {
            const d = doc.data() || {};
            totalLoginEvents++;
            const aid = (d.detail && (d.detail.adminId || d.detail.admin_id)) || d.admin_id || d.adminId;
            if (aid && /^SSA\d+$/.test(aid)) {
                if (!eventsBySsa.has(aid)) eventsBySsa.set(aid, 0);
                eventsBySsa.set(aid, eventsBySsa.get(aid) + 1);
            }
        });
    } catch (e) { console.log('    ERR security_events query:', e.message); }
    console.log('    security_events ADMIN_LOGIN_SUCCESS total:', totalLoginEvents);
    console.log('    Distinct SSAs with login events:', eventsBySsa.size);
    eventsBySsa.forEach((cnt, sid) => console.log('      ' + sid + '  events=' + cnt));

    const ssasMissing = activeSsas.filter(s => !eventsBySsa.has(s.sid));
    const pf2Pass = (ssasMissing.length === 0 && activeSsas.length > 0);
    if (ssasMissing.length > 0) {
        console.log('    ✗ Active SSAs WITHOUT a login event in security_events:');
        ssasMissing.forEach(s => console.log('      ' + s.sid + '  ' + s.name));
    }
    console.log('    PF-2 VERDICT:', pf2Pass ? '✓ PASS' : (activeSsas.length === 0 ? '~ INCONCLUSIVE — no active SSAs to test' : '✗ FAIL — last-login replacement needs fallback or backfill'));

    console.log('\n' + '═'.repeat(76));
    console.log('  PF-1:', pf1Pass ? '✓ PASS' : '✗ FAIL');
    console.log('  PF-2:', pf2Pass ? '✓ PASS' : (activeSsas.length === 0 ? '~ INCONCLUSIVE' : '✗ FAIL'));
    console.log('═'.repeat(76));

    process.exit(0);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
