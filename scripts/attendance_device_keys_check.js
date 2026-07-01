#!/usr/bin/env node
const path = require('path');
const fs = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC), databaseURL: 'https://graderadmin-default-rtdb.firebaseio.com/' });
const fsdb = admin.firestore();
const rtdb = admin.database();

(async function main() {
    console.log('═══ attendanceDeviceKeys Firestore coverage ═══');
    const snap = await fsdb.collection('attendanceDeviceKeys').get();
    console.log(`  Firestore docs: ${snap.size}`);
    let withSchoolId = 0, withoutSchoolId = 0;
    snap.forEach(doc => {
        const d = doc.data() || {};
        const sid = d.schoolId || d.school_id;
        const hasSchoolId = !!sid;
        if (hasSchoolId) withSchoolId++; else withoutSchoolId++;
        console.log(`    ${doc.id.substring(0,16)}...  schoolName=${d.schoolName || '?'}  schoolId=${sid || '<EMPTY>'}`);
    });
    console.log(`\n  ✓ with schoolId: ${withSchoolId}/${snap.size}`);
    console.log(`  ✗ without schoolId: ${withoutSchoolId}/${snap.size}`);

    console.log('\n═══ Legacy RTDB System/API_Keys check ═══');
    const rtSnap = await rtdb.ref('System/API_Keys').once('value');
    const val = rtSnap.val() || {};
    const ids = Object.keys(val);
    console.log(`  RTDB legacy API key entries: ${ids.length}`);
    if (ids.length > 0) {
        ids.slice(0, 5).forEach(k => {
            const r = val[k] || {};
            console.log(`    ${k.substring(0,16)}...  school_name=${r.school_name || '?'}  has school_id=${!!r.school_id}`);
        });
    }
    process.exit(0);
})().catch(err => { console.error(err); process.exit(1); });
