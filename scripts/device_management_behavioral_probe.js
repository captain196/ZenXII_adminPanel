#!/usr/bin/env node
/**
 * Device_management D1-D4 behavioral probe.
 *
 * Mirrors the migrated PHP controller logic in JavaScript and runs it against
 * the live Firestore staff/* collection. Verifies the 7 user-specified
 * requirements:
 *
 *   1. Existing SSA search
 *   2. Existing teacher search
 *   3. get_overview user assembly
 *   4. get_all_users_devices user assembly
 *   5. get_suspicious_activity user assembly
 *   6. Newly onboarded SSA visibility (with cleanup)
 *   7. No runtime errors
 *
 * READ-ONLY against existing staff/*. Writes ONE temporary test doc for
 * requirement 6 and deletes it before exit.
 *
 * Usage: node scripts/device_management_behavioral_probe.js
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

const TEST_STAFF_ID = 'TESTSSA' + Date.now();
const TEST_DOC_ID = TENANT + '_' + TEST_STAFF_ID;

// ── Mirror of PHP search_user post-D1 ─────────────────────────────────────
async function searchUser(query) {
    const snap = await fsdb.collection('staff').where('schoolId', '==', TENANT).get();
    const matches = [];
    for (const doc of snap.docs) {
        const d = doc.data() || {};
        let sid = d.staffId || '';
        if (!sid) {
            const pos = doc.id.lastIndexOf('_');
            if (pos >= 0) sid = doc.id.substring(pos + 1);
        }
        if (!sid) continue;
        const name = d.Name || d.name || '';
        const q = String(query).toLowerCase();
        if (String(sid).toLowerCase().includes(q) || String(name).toLowerCase().includes(q)) {
            matches.push({
                userId: sid,
                name: name,
                role: 'Teacher',
                email: d.Email || d.email || '',
                phone: d['Phone Number'] || d.phone || d.Phone || '',
                status: d.Status || d.status || 'active',
            });
        }
        if (matches.length >= 20) break;
    }
    return matches;
}

// ── Mirror of PHP _collect_all_users post-D2 ──────────────────────────────
async function collectAllUsers() {
    const snap = await fsdb.collection('staff').where('schoolId', '==', TENANT).get();
    const users = [];
    for (const doc of snap.docs) {
        const d = doc.data() || {};
        let sid = d.staffId || '';
        if (!sid) {
            const pos = doc.id.lastIndexOf('_');
            if (pos >= 0) sid = doc.id.substring(pos + 1);
        }
        if (!sid) continue;
        users.push({
            userId: sid,
            name: d.Name || d.name || sid,
            role: 'Teacher',
        });
    }
    return users;
}

const results = [];
function record(name, endpoint, expected, actual, pass, note) {
    results.push({ name, endpoint, expected, actual, pass, note: note || '' });
}

(async function main() {
    console.log('═'.repeat(76));
    console.log('  DEVICE_MANAGEMENT D1-D4 BEHAVIORAL PROBE');
    console.log('  Tenant:', TENANT);
    console.log('  Time  :', new Date().toISOString());
    console.log('═'.repeat(76));

    let errorCount = 0;

    // ── Test 1: Existing SSA search ─────────────────────────────────────
    try {
        const r = await searchUser('SSA0001');
        const expected = 'at least 1 match with userId "SSA0001" and role "Teacher"';
        const matched = r.find(x => x.userId === 'SSA0001');
        const pass = !!matched && matched.role === 'Teacher' && (matched.name || '').length > 0;
        record('1. Existing SSA search', 'search_user {query: "SSA0001"}',
            expected,
            `${r.length} matches, includes userId=SSA0001 with name="${matched ? matched.name : '<missing>'}"`,
            pass);
    } catch (e) { errorCount++; record('1. Existing SSA search', 'search_user', '', 'ERROR: ' + e.message, false); }

    // ── Test 1b: SSA search by name substring ───────────────────────────
    try {
        const r = await searchUser('Shivam');
        const expected = 'at least 1 match (SSA0001 — Shivam Rathore)';
        const matched = r.find(x => x.userId === 'SSA0001');
        const pass = !!matched;
        record('1b. SSA search by name', 'search_user {query: "Shivam"}',
            expected,
            `${r.length} matches, includes userId=SSA0001=${!!matched}`,
            pass);
    } catch (e) { errorCount++; record('1b. SSA search by name', 'search_user', '', 'ERROR: ' + e.message, false); }

    // ── Test 2: Existing teacher search ─────────────────────────────────
    try {
        const r = await searchUser('STA0001');
        const expected = 'at least 1 match with userId "STA0001"';
        const matched = r.find(x => x.userId === 'STA0001');
        const pass = !!matched && (matched.name || '').length > 0;
        record('2. Existing teacher search', 'search_user {query: "STA0001"}',
            expected,
            `${r.length} matches, includes userId=STA0001 with name="${matched ? matched.name : '<missing>'}", phone="${matched ? matched.phone : '<missing>'}"`,
            pass);
    } catch (e) { errorCount++; record('2. Existing teacher search', 'search_user', '', 'ERROR: ' + e.message, false); }

    // ── Test 2b: Teacher search by name substring ───────────────────────
    try {
        const r = await searchUser('Vipul');
        const expected = 'at least 1 match (STA0001 — Vipul TIwari)';
        const matched = r.find(x => x.userId === 'STA0001');
        const pass = !!matched;
        record('2b. Teacher search by name', 'search_user {query: "Vipul"}',
            expected,
            `${r.length} matches, includes userId=STA0001=${!!matched}`,
            pass);
    } catch (e) { errorCount++; record('2b. Teacher search by name', 'search_user', '', 'ERROR: ' + e.message, false); }

    // ── Test 3: get_overview — user assembly via _collect_all_users ────
    try {
        const users = await collectAllUsers();
        const expected = '>= 8 staff users (census proved 10 in Firestore, all RTDB ids covered)';
        const pass = users.length >= 8;
        record('3. get_overview staff list', '_collect_all_users()',
            expected,
            `${users.length} staff users assembled (sample: ${users.slice(0, 3).map(u => u.userId).join(', ')}...)`,
            pass);
    } catch (e) { errorCount++; record('3. get_overview staff list', '_collect_all_users', '', 'ERROR: ' + e.message, false); }

    // ── Test 4: get_all_users_devices — uses _collect_all_users ────────
    try {
        const users = await collectAllUsers();
        const requiredKeys = ['userId', 'name', 'role'];
        const missingShape = users.find(u => !requiredKeys.every(k => k in u));
        const expected = `each user has [userId, name, role]; role hardcoded "Teacher"`;
        const allRoleTeacher = users.every(u => u.role === 'Teacher');
        const pass = !missingShape && allRoleTeacher;
        record('4. get_all_users_devices shape', '_collect_all_users() → bulk table',
            expected,
            `${users.length} users, all have required keys=${!missingShape}, all role="Teacher"=${allRoleTeacher}`,
            pass);
    } catch (e) { errorCount++; record('4. get_all_users_devices shape', '_collect_all_users', '', 'ERROR: ' + e.message, false); }

    // ── Test 5: get_suspicious_activity — uses _collect_all_users ──────
    try {
        const users = await collectAllUsers();
        // get_suspicious_activity downstream calls auth_client->list_devices for each user;
        // the user-list assembly is what D2 changed. Verify the assembly works.
        const expected = 'same user list as Test 4; no shape regressions';
        const pass = users.length > 0 && users.every(u => u.userId && u.role === 'Teacher');
        record('5. get_suspicious_activity user list', '_collect_all_users() → alerts scan',
            expected,
            `${users.length} users ready for downstream auth_client->list_devices calls`,
            pass);
    } catch (e) { errorCount++; record('5. get_suspicious_activity user list', '_collect_all_users', '', 'ERROR: ' + e.message, false); }

    // ── Test 6: Newly onboarded SSA visibility ─────────────────────────
    let testDocCreated = false;
    try {
        // Write a temporary test SSA doc mimicking Superadmin_schools.php:452 shape
        const testDoc = {
            schoolId: TENANT,
            staffId: TEST_STAFF_ID,
            Name: 'Behavioral Probe Test SSA',
            name: 'Behavioral Probe Test SSA',
            Email: 'probe-test@example.invalid',
            email: 'probe-test@example.invalid',
            Role: 'School Super Admin',
            role: 'School Super Admin',
            Status: 'Active',
            status: 'Active',
            createdAt: new Date().toISOString(),
            _probe_artifact: true,
            _probe_created: Date.now(),
        };
        await fsdb.collection('staff').doc(TEST_DOC_ID).set(testDoc);
        testDocCreated = true;

        // Now run search and collect to verify visibility
        const r = await searchUser(TEST_STAFF_ID);
        const matched = r.find(x => x.userId === TEST_STAFF_ID);
        const users = await collectAllUsers();
        const inList = users.find(u => u.userId === TEST_STAFF_ID);

        const expected = 'newly onboarded SSA appears in search_user AND _collect_all_users';
        const pass = !!matched && !!inList;
        record('6. Newly onboarded SSA visibility',
            `staff/${TEST_DOC_ID} written → search + list queries`,
            expected,
            `search_user matches: ${!!matched}, _collect_all_users contains: ${!!inList}`,
            pass);
    } catch (e) {
        errorCount++;
        record('6. Newly onboarded SSA visibility', '', '', 'ERROR: ' + e.message, false);
    } finally {
        if (testDocCreated) {
            try {
                await fsdb.collection('staff').doc(TEST_DOC_ID).delete();
                console.log('\n  [cleanup] Deleted test doc ' + TEST_DOC_ID);
            } catch (e) {
                console.error('  [cleanup] FAILED to delete ' + TEST_DOC_ID + ': ' + e.message);
                errorCount++;
            }
        }
    }

    // ── Test 7: No runtime errors ──────────────────────────────────────
    record('7. No runtime errors', 'all of the above',
        '0 errors thrown',
        `${errorCount} errors thrown`,
        errorCount === 0);

    // ── Results report ─────────────────────────────────────────────────
    console.log('\n' + '═'.repeat(76));
    console.log('  RESULTS');
    console.log('═'.repeat(76));
    let allPass = true;
    for (const r of results) {
        const tag = r.pass ? '✓ PASS' : '✗ FAIL';
        if (!r.pass) allPass = false;
        console.log(`\n${tag}  ${r.name}`);
        console.log(`        endpoint  : ${r.endpoint}`);
        console.log(`        expected  : ${r.expected}`);
        console.log(`        actual    : ${r.actual}`);
        if (r.note) console.log(`        note      : ${r.note}`);
    }
    console.log('\n' + '═'.repeat(76));
    console.log('  VERDICT: ' + (allPass ? '✓ ALL PROBES PASS' : '✗ SOME PROBES FAILED'));
    console.log('═'.repeat(76));

    process.exit(allPass ? 0 : 1);
})().catch(err => {
    console.error('FATAL:', err && err.stack || err);
    process.exit(1);
});
