#!/usr/bin/env node
/**
 * END-TO-END test of the forced set-new-password flow — ALL account types.
 *
 * Drives real HTTP requests through a locally-running panel (localhost:8080)
 * against real Firebase, once per account class, because the mirror router
 * branches on the id prefix and each branch reaches a different collection:
 *
 *   STU… → students   (Parent app)   — the class that was broken in production
 *   STA… → staff      (Teacher app)
 *   ADM… → admins     (legacy admin ids)
 *
 * Students are run twice, under BOTH role spellings the codebase mints
 * ('student' at creation, 'Parent' at reset), since disagreement between those
 * two is what caused the original lockout.
 *
 * Every fixture uses a disposable uid inside a NON-EXISTENT tenant, so no real
 * roster, query or push fan-out can observe it. Cleanup is unconditional.
 *
 * Prerequisite:  php -S localhost:8080   (from the panel repo root)
 * Usage:         node scripts/e2e_force_change_test.js
 */
const path = require('path');
const fs   = require('fs');

let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }

const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..',
    'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const auth = admin.auth();
const db   = admin.firestore();

// Firebase WEB api key — public by design, ships in every client build.
const API_KEY = 'AIzaSyDK0gfLhV_WJGxEzxvH61KAXVtasZcc8Zs';
const BASE    = 'http://localhost:8080';
const SCHOOL  = 'SCH_ZZTEST0001';
const ALL_COLLECTIONS = ['students', 'staff', 'admins'];

const CASES = [
    { uid: 'STU0000', coll: 'students', role: 'student',    label: 'Parent app · student (creation mint)' },
    { uid: 'STU0000', coll: 'students', role: 'Parent',     label: 'Parent app · student (reset mint)' },
    { uid: 'STA0000', coll: 'staff',    role: 'Accountant', label: 'Teacher app · staff' },
    { uid: 'STA0000', coll: 'staff',    role: '9876543210', label: 'Teacher app · staff, junk role field' },
    { uid: 'ADM0000', coll: 'admins',   role: 'Admin',      label: 'legacy admin id' },
];

const PW1 = 'TempPass1', PW2 = 'NewPass123', PW3 = 'ThirdPass9';

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => {
    if (cond) { pass++; console.log(`    PASS  ${name}`); }
    else      { fail++; console.log(`    FAIL  ${name}${detail ? '  — ' + detail : ''}`); }
};

const emailFor = uid => `${uid.toLowerCase()}@schoolsync.app`;
const docFor   = uid => `${SCHOOL}_${uid}`;

async function signIn(uid, password) {
    const r = await fetch(`https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=${API_KEY}`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: emailFor(uid), password, returnSecureToken: true }),
    });
    const j = await r.json();
    if (!j.idToken) throw new Error('sign-in failed: ' + JSON.stringify(j));
    return j.idToken;
}

async function callClear(idToken, newPassword) {
    const r = await fetch(`${BASE}/auth/clear_must_change`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${idToken}`, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ new_password: newPassword }).toString(),
    });
    let body; try { body = await r.json(); } catch { body = { raw: 'non-json' }; }
    return { code: r.status, body };
}

async function cleanup(uid) {
    try { await auth.deleteUser(uid); } catch (_) {}
    for (const c of ALL_COLLECTIONS) {
        try { await db.collection(c).doc(docFor(uid)).delete(); } catch (_) {}
    }
}

async function runCase(tc) {
    const { uid, coll, role, label } = tc;
    const DOC = docFor(uid);
    const mirrorIn = async c => {
        const s = await db.collection(c).doc(DOC).get();
        return s.exists ? s.data().mustChangePassword : '(no doc)';
    };

    console.log(`\n── ${label}   [${uid} → ${coll}, role='${role}'] ──────────`);

    await cleanup(uid);
    await auth.createUser({ uid, email: emailFor(uid), password: PW1, displayName: 'E2E Test' });

    const claims = {
        role, roleLabel: role,
        school_id: SCHOOL, schoolId: SCHOOL,
        school_code: '99999', schoolCode: '99999',
        parent_db_key: '99999', parentDbKey: '99999',
        must_change_password: true,
    };
    if (coll === 'students') { claims.student_id = uid; claims.student_ids = [uid]; }
    else { claims.staffId = uid; }
    await auth.setCustomUserClaims(uid, claims);

    await db.collection(coll).doc(DOC).set({
        schoolId: SCHOOL, name: 'E2E Test', status: 'Active', mustChangePassword: true,
    });

    // 1 — the forced change itself
    const r1 = await callClear(await signIn(uid, PW1), PW2);
    ok('HTTP 200', r1.code === 200, `got ${r1.code} ${JSON.stringify(r1.body)}`);

    const claimAfter = ((await auth.getUser(uid)).customClaims || {}).must_change_password;
    ok('claim cleared', claimAfter === undefined || claimAfter === false);

    const m1 = await mirrorIn(coll);
    ok(`mirror cleared in ${coll}`, m1 === false, `${coll}/${DOC}.mustChangePassword = ${m1}`);

    // No write may land in any OTHER collection — that was the original defect.
    for (const other of ALL_COLLECTIONS.filter(c => c !== coll)) {
        const s = await db.collection(other).doc(DOC).get();
        ok(`no stray write to ${other}`, !s.exists, `${other}/${DOC} was created`);
    }
    ok('new password works', await signIn(uid, PW2).then(() => true).catch(() => false));

    // 2 — an already-stranded account heals instead of dead-ending
    const c2 = (await auth.getUser(uid)).customClaims || {};
    delete c2.must_change_password;
    await auth.setCustomUserClaims(uid, c2);
    await db.collection(coll).doc(DOC).set({ mustChangePassword: true }, { merge: true });

    const r2 = await callClear(await signIn(uid, PW2), PW3);
    ok('stranded account accepted (claim gone, mirror true)', r2.code === 200,
       `got ${r2.code} ${JSON.stringify(r2.body)}`);
    ok('mirror cleared on retry', (await mirrorIn(coll)) === false);

    // 3 — still refuses when genuinely nothing is pending
    const r3 = await callClear(await signIn(uid, PW3), 'YetAnother1');
    ok('refuses when nothing pending', r3.code === 400, `got ${r3.code}`);

    // 4 — password policy still enforced
    await db.collection(coll).doc(DOC).set({ mustChangePassword: true }, { merge: true });
    const r4 = await callClear(await signIn(uid, PW3), 'short1');
    ok('rejects too-short password', r4.code === 400, `got ${r4.code}`);
    const r5 = await callClear(await signIn(uid, PW3), 'alllowercase123');
    ok('rejects missing uppercase', r5.code === 400, `got ${r5.code}`);

    await cleanup(uid);
}

(async () => {
    // Guard — never touch anything real.
    for (const uid of [...new Set(CASES.map(c => c.uid))]) {
        try { await auth.getUser(uid); console.error(`ABORT: ${uid} already exists.`); process.exit(2); } catch (_) {}
        for (const c of ALL_COLLECTIONS) {
            if ((await db.collection(c).doc(docFor(uid)).get()).exists) {
                console.error(`ABORT: ${c}/${docFor(uid)} already exists.`); process.exit(2);
            }
        }
    }

    // Auth enforcement is account-independent — assert it once.
    console.log('\n── auth enforcement ───────────────────────────────────────');
    ok('rejects junk bearer token', (await callClear('junk.junk.junk', 'Whatever12')).code === 401);

    try {
        for (const tc of CASES) await runCase(tc);
    } finally {
        for (const uid of [...new Set(CASES.map(c => c.uid))]) await cleanup(uid);
        console.log('\n  cleaned up all test accounts + docs');
    }

    console.log(`\n══ RESULT ═════════════════════════════════════════════════`);
    console.log(`  ${pass} passed, ${fail} failed\n`);
    process.exit(fail === 0 ? 0 : 1);
})().catch(async e => {
    console.error('FATAL', e);
    for (const uid of [...new Set(CASES.map(c => c.uid))]) await cleanup(uid);
    process.exit(1);
});
