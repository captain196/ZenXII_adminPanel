#!/usr/bin/env node
/**
 * Schools/* Firestore canonical shape probe.
 *
 * READ-ONLY. Verifies the Firestore schools/* canonical collection holds
 * the school_code field and other school-profile fields needed by SSA flow.
 *
 * Usage: node scripts/schools_firestore_shape_probe.js
 */
const path = require('path');
const fs = require('fs');

const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SA_PATH = path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
const SVC = JSON.parse(fs.readFileSync(SA_PATH, 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });

const fsdb = admin.firestore();
const TENANT = 'SCH_D94FE8F7AD';

(async function main() {
    console.log('═'.repeat(72));
    console.log('  schools/* Firestore canonical shape probe');
    console.log('═'.repeat(72));

    // Read live tenant doc
    const snap = await fsdb.collection('schools').doc(TENANT).get();
    if (!snap.exists) {
        console.error('  ✗ schools/' + TENANT + ' does not exist');
        process.exit(1);
    }
    const d = snap.data() || {};
    console.log('\n[1] schools/' + TENANT + ' fields:');
    Object.keys(d).sort().forEach(k => {
        let v = d[k];
        if (v && typeof v === 'object' && !Array.isArray(v) && !(v._seconds !== undefined)) v = '<map>';
        else if (typeof v === 'string' && v.length > 60) v = v.substring(0, 57) + '...';
        else if (v && v._seconds !== undefined) v = 'Timestamp(' + v._seconds + ')';
        else if (Array.isArray(v)) v = 'array[' + v.length + ']';
        console.log('      ' + k + ': ' + v);
    });

    // Check Firestore school_code candidates
    console.log('\n[2] school_code candidates:');
    ['school_code', 'schoolCode', 'parentDbKey', 'school_id', 'schoolId'].forEach(k => {
        console.log('      ' + k + ': ' + (d[k] !== undefined ? d[k] : '<absent>'));
    });

    // Enumerate all schools in Firestore
    console.log('\n[3] schools/* Firestore collection enumeration:');
    const allSnap = await fsdb.collection('schools').get();
    console.log('    Total schools: ' + allSnap.size);
    const codeCoverage = { has_school_code: 0, has_schoolCode: 0, has_neither: 0 };
    allSnap.forEach(doc => {
        const dd = doc.data() || {};
        const hasSnake = !!dd.school_code;
        const hasCamel = !!dd.schoolCode;
        if (hasSnake) codeCoverage.has_school_code++;
        if (hasCamel) codeCoverage.has_schoolCode++;
        if (!hasSnake && !hasCamel) codeCoverage.has_neither++;
        console.log('      ' + doc.id + '  school_code=' + (dd.school_code || '?') + '  schoolCode=' + (dd.schoolCode || '?'));
    });
    console.log('\n    Coverage:');
    console.log('      school_code (snake)  : ' + codeCoverage.has_school_code + '/' + allSnap.size);
    console.log('      schoolCode (camel)   : ' + codeCoverage.has_schoolCode + '/' + allSnap.size);
    console.log('      neither              : ' + codeCoverage.has_neither + '/' + allSnap.size);

    // Compare against RTDB System/Schools
    console.log('\n[4] RTDB System/Schools enumeration (for comparison):');
    const rtdb = admin.database();
    try {
        const rtSnap = await rtdb.ref('System/Schools').once('value');
        const rt = rtSnap.val() || {};
        const rtIds = Object.keys(rt);
        console.log('    Total RTDB schools: ' + rtIds.length);
        rtIds.forEach(k => {
            const v = rt[k] || {};
            const profile = v.profile || {};
            console.log('      ' + k + '  profile.school_code=' + (profile.school_code || '?'));
        });
    } catch (e) { console.log('    ERR:', e.message); }

    console.log('\n' + '═'.repeat(72));
    process.exit(0);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
