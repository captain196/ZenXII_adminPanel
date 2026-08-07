#!/usr/bin/env node
/**
 * Device_management retirement — Firestore census.
 *
 * READ-ONLY. No mutations. Verifies that the Firestore `staff/*` collection
 * carries every SA / Admin / Teacher record that Device_management currently
 * surfaces via RTDB `Users/Admin/{parent_db_key}` reads.
 *
 * Proves:
 *   1. All SAs are represented in staff/*
 *   2. All Admin Users are represented in staff/*
 *   3. All Teachers are represented in staff/*
 *   4. Field coverage matches Device_management consumer needs
 *
 * Usage: node scripts/staff_firestore_census_device_management.js [SCH_XXXXXX]
 */
const path = require('path');
const fs = require('fs');

const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));

const SA_PATH = path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
const DB_URL = process.env.RTDB_URL || 'https://graderadmin-default-rtdb.firebaseio.com/';
const TENANT = process.argv[2] || 'SCH_D94FE8F7AD';

const SVC = JSON.parse(fs.readFileSync(SA_PATH, 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC), databaseURL: DB_URL });

const fsdb = admin.firestore();
const rtdb = admin.database();

// Fields Device_management consumes (per search_user + _collect_all_users):
const REQUIRED_FIELDS = [
    { name: 'userId',  variants: ['(doc id suffix)'] },
    { name: 'name',    variants: ['Name', 'name'] },
    { name: 'email',   variants: ['Email', 'email'] },
    { name: 'phone',   variants: ['Phone', 'phone', 'Phone Number'] },
    { name: 'status',  variants: ['Status', 'status'] },
];

function pickField(d, variants) {
    for (const k of variants) {
        if (d && typeof d === 'object' && d[k] !== undefined && d[k] !== null && String(d[k]).trim() !== '') {
            return { value: d[k], key: k };
        }
    }
    return null;
}

function classifyRole(d) {
    // Read role across casings — staff doc carries Role || role || primary_role
    const role = (d.Role || d.role || d.primary_role || '').toString().trim().toLowerCase();
    if (role.includes('super admin') || role === 'school_super_admin') return 'SSA';
    if (role.includes('admin')) return 'Admin';
    if (role.includes('teacher')) return 'Teacher';
    if (role.includes('principal')) return 'Principal';
    if (role) return `Other(${role})`;
    return 'Unspecified';
}

(async function main() {
    console.log('═'.repeat(72));
    console.log('  DEVICE_MANAGEMENT FIRESTORE CENSUS');
    console.log('  Tenant:', TENANT);
    console.log('  Time  :', new Date().toISOString());
    console.log('═'.repeat(72));

    // ── 1. Firestore staff/* enumeration ────────────────────────────────
    console.log('\n[1] Firestore staff/* enumeration (schoolId == ' + TENANT + ')');
    const fsSnap = await fsdb.collection('staff').where('schoolId', '==', TENANT).get();
    const fsByRole = {};
    const fsByDoc = [];
    fsSnap.forEach(doc => {
        const d = doc.data() || {};
        const role = classifyRole(d);
        fsByRole[role] = (fsByRole[role] || 0) + 1;
        fsByDoc.push({ docId: doc.id, data: d, role });
    });

    // schoolCode-keyed staff docs (some writers used schoolCode instead of schoolId)
    const fsSnap2 = await fsdb.collection('staff').where('schoolCode', '==', TENANT).get();
    fsSnap2.forEach(doc => {
        if (fsByDoc.find(x => x.docId === doc.id)) return; // dedupe
        const d = doc.data() || {};
        const role = classifyRole(d);
        fsByRole[role] = (fsByRole[role] || 0) + 1;
        fsByDoc.push({ docId: doc.id, data: d, role });
    });

    console.log('    Total staff docs:', fsByDoc.length);
    console.log('    Breakdown by role:');
    Object.entries(fsByRole).sort((a, b) => b[1] - a[1]).forEach(([r, n]) => {
        console.log('      ' + r.padEnd(20) + n);
    });

    // ── 2. RTDB Users/Admin/{schoolCode} enumeration (to compare) ────────
    // Look up the school's RTDB key. Firestore schoolControl/{schoolId} typically holds it.
    let schoolCode = TENANT;
    try {
        const sc = await fsdb.collection('schoolControl').doc(TENANT).get();
        if (sc.exists) {
            const scd = sc.data();
            if (scd && scd.schoolCode) schoolCode = scd.schoolCode;
        }
    } catch (e) {}
    // Also try schools/{schoolId}.school_code
    try {
        const s = await fsdb.collection('schools').doc(TENANT).get();
        if (s.exists) {
            const sd = s.data();
            if (sd && sd.school_code) schoolCode = sd.school_code;
            else if (sd && sd.schoolCode) schoolCode = sd.schoolCode;
        }
    } catch (e) {}

    console.log('\n[2] RTDB Users/Admin/' + schoolCode + ' enumeration (current Device_management source)');
    const rtdbSnap = await rtdb.ref('Users/Admin/' + schoolCode).once('value');
    const rtdbVal = rtdbSnap.val() || {};
    const rtdbIds = Object.keys(rtdbVal).filter(k => k && k !== '_Counter');
    console.log('    Total RTDB admin entries:', rtdbIds.length);

    const rtdbByRole = {};
    rtdbIds.forEach(id => {
        const d = rtdbVal[id] || {};
        const role = classifyRole(d);
        rtdbByRole[role] = (rtdbByRole[role] || 0) + 1;
    });
    console.log('    Breakdown by role:');
    Object.entries(rtdbByRole).sort((a, b) => b[1] - a[1]).forEach(([r, n]) => {
        console.log('      ' + r.padEnd(20) + n);
    });

    // ── 3. Coverage check — is every RTDB id present in Firestore? ───────
    console.log('\n[3] Coverage check — RTDB → Firestore presence');
    const fsIdSet = new Set();
    fsByDoc.forEach(x => {
        // staff doc id is {schoolId}_{staffId} OR {schoolCode}_{staffId}
        const parts = x.docId.split('_');
        const staffId = parts[parts.length - 1];
        fsIdSet.add(staffId);
        // Also store staffId from doc data if available
        if (x.data.staffId) fsIdSet.add(x.data.staffId);
    });
    const missingInFs = [];
    rtdbIds.forEach(id => {
        if (!fsIdSet.has(id)) missingInFs.push(id);
    });
    console.log('    RTDB ids present in Firestore staff/*:', rtdbIds.length - missingInFs.length, '/', rtdbIds.length);
    if (missingInFs.length > 0) {
        console.log('    ✗ MISSING in Firestore (' + missingInFs.length + '):');
        missingInFs.slice(0, 10).forEach(id => {
            const d = rtdbVal[id] || {};
            console.log('        ' + id + '  role=' + (d.Role || d.role || '?') + '  name=' + (d.Name || d.name || '?'));
        });
        if (missingInFs.length > 10) console.log('        ... and ' + (missingInFs.length - 10) + ' more');
    } else {
        console.log('    ✓ ALL RTDB admin ids are present in Firestore staff/*');
    }

    // ── 4. Field coverage check ──────────────────────────────────────────
    console.log('\n[4] Field coverage in Firestore staff docs (Device_management consumer needs)');
    const fieldCounts = {};
    REQUIRED_FIELDS.forEach(f => fieldCounts[f.name] = 0);
    fsByDoc.forEach(x => {
        REQUIRED_FIELDS.forEach(f => {
            if (f.name === 'userId') {
                if (x.docId && x.docId.includes('_')) fieldCounts[f.name]++;
            } else {
                const got = pickField(x.data, f.variants);
                if (got) fieldCounts[f.name]++;
            }
        });
    });
    REQUIRED_FIELDS.forEach(f => {
        const pct = fsByDoc.length > 0 ? Math.round((fieldCounts[f.name] / fsByDoc.length) * 100) : 0;
        const flag = pct === 100 ? '✓' : (pct >= 80 ? '~' : '✗');
        console.log('    ' + flag + ' ' + f.name.padEnd(10) + fieldCounts[f.name] + '/' + fsByDoc.length + ' (' + pct + '%)  variants tried: ' + f.variants.join(' || '));
    });

    // ── 5. Sample document shapes (first 3) ──────────────────────────────
    console.log('\n[5] Sample Firestore staff doc shapes (first 3):');
    fsByDoc.slice(0, 3).forEach((x, i) => {
        console.log('    --- doc[' + i + '] id=' + x.docId + ' role=' + x.role + ' ---');
        const keys = Object.keys(x.data).sort();
        keys.forEach(k => {
            let v = x.data[k];
            if (typeof v === 'object') v = '<' + (Array.isArray(v) ? 'array[' + v.length + ']' : 'map') + '>';
            else if (typeof v === 'string' && v.length > 50) v = v.substring(0, 47) + '...';
            console.log('      ' + k + ': ' + v);
        });
    });

    // ── 6. Summary verdict ───────────────────────────────────────────────
    console.log('\n' + '═'.repeat(72));
    console.log('  CENSUS VERDICT');
    console.log('═'.repeat(72));
    const sasInFs = fsByRole['SSA'] || 0;
    const teachersInFs = fsByRole['Teacher'] || 0;
    const adminsInFs = fsByRole['Admin'] || 0;
    const allFieldsCovered = REQUIRED_FIELDS.every(f => fieldCounts[f.name] === fsByDoc.length);
    console.log('    SAs in Firestore       : ' + sasInFs);
    console.log('    Admins in Firestore    : ' + adminsInFs);
    console.log('    Teachers in Firestore  : ' + teachersInFs);
    console.log('    Coverage (RTDB → FS)   : ' + (missingInFs.length === 0 ? '✓ COMPLETE' : '✗ GAPS (' + missingInFs.length + ' missing)'));
    console.log('    Field coverage         : ' + (allFieldsCovered ? '✓ ALL fields present 100%' : '~ partial — see [4]'));
    console.log('═'.repeat(72));

    process.exit(0);
})().catch(err => {
    console.error('ERROR:', err && err.stack || err);
    process.exit(1);
});
