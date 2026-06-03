#!/usr/bin/env node
/**
 * Wave C — Step 0.B' : Canary signin verifier.
 *
 * READ-ONLY diagnostic — calls Firebase Identity Toolkit signInWithPassword.
 * Never echoes passwords. Reports PASS/REJECTED + errorCode only.
 *
 * Passwords are read from env vars (NEVER from args) so they don't show in
 * process listing:
 *   CANARY_PW_<adminIdUpper>=<password>
 *
 * Usage:
 *   CANARY_PW_SSA0001=... CANARY_PW_SUP0001=... CANARY_PW_SUP0002=... \
 *     node scripts/admin_canary_signin_c0.js SSA0001 SUP0001 SUP0002
 *
 * For each adminId, builds synthetic email `{adminId.lower}@schoolsync.app`
 * and attempts signInWithPassword. Reports per-admin verdict.
 *
 * Mirrors sa_check_signin.js pattern.
 */
const https = require('https');
const path = require('path');
const fs = require('fs');

const SA_PATH = path.resolve(__dirname, '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
// The web API key for Identity Toolkit — same one sa_check_signin.js uses
const API_KEY = 'AIzaSyBe0xmEw3ms6WWmnkj3-hFAspksx9v4CTQ';
const DOMAIN  = 'schoolsync.app';

const adminIds = process.argv.slice(2);
if (adminIds.length === 0) {
    console.error('usage: CANARY_PW_<ID>=<pw> ... node admin_canary_signin_c0.js <ID> [<ID>...]');
    process.exit(2);
}

function signIn(email, password) {
    return new Promise((resolve) => {
        const body = JSON.stringify({ email, password, returnSecureToken: true });
        const req = https.request({
            hostname: 'identitytoolkit.googleapis.com',
            path: `/v1/accounts:signInWithPassword?key=${API_KEY}`,
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) },
        }, (res) => {
            const c = [];
            res.on('data', x => c.push(x));
            res.on('end', () => {
                let parsed;
                try { parsed = JSON.parse(Buffer.concat(c).toString()); }
                catch { parsed = { raw: Buffer.concat(c).toString().substring(0, 200) }; }
                resolve({ status: res.statusCode, body: parsed });
            });
        });
        req.on('error', e => resolve({ status: 0, body: { error: { message: e.message } } }));
        req.write(body);
        req.end();
    });
}

(async function main() {
    console.log('═'.repeat(70));
    console.log(' WAVE C / STEP 0.B\' — CANARY SIGNIN VERIFIER (READ-ONLY)');
    console.log('═'.repeat(70));
    console.log(' Endpoint : Firebase Identity Toolkit signInWithPassword');
    console.log(' Email    : {adminId.lower}@' + DOMAIN);
    console.log(' Passwords: read from env vars CANARY_PW_<ID>, never echoed');
    console.log();

    const results = [];
    for (const id of adminIds) {
        const idUpper = id.toUpperCase();
        const email = id.toLowerCase() + '@' + DOMAIN;
        const pw = process.env[`CANARY_PW_${idUpper}`];

        if (!pw) {
            console.log(`  ${id} : SKIP — CANARY_PW_${idUpper} env var not set`);
            results.push({ id, verdict: 'SKIP', reason: 'no password env var' });
            continue;
        }

        const res = await signIn(email, pw);
        const status = res.status;
        const errCode = res.body && res.body.error && res.body.error.message;
        const uid     = res.body && res.body.localId;

        if (status === 200 && uid) {
            console.log(`  ${id} : ✅ ACCEPTED  email=${email}  uid=${uid}`);
            results.push({ id, verdict: 'ACCEPTED', email, uid });
        } else if (errCode) {
            console.log(`  ${id} : ❌ REJECTED  email=${email}  reason=${errCode}`);
            results.push({ id, verdict: 'REJECTED', email, errorCode: errCode });
        } else {
            console.log(`  ${id} : ⚠ UNKNOWN  email=${email}  status=${status}`);
            results.push({ id, verdict: 'UNKNOWN', email, status });
        }
    }

    console.log();
    console.log('═'.repeat(70));
    console.log(' SUMMARY');
    console.log('═'.repeat(70));
    const accepted = results.filter(r => r.verdict === 'ACCEPTED').length;
    const rejected = results.filter(r => r.verdict === 'REJECTED').length;
    const unknown  = results.filter(r => r.verdict === 'UNKNOWN').length;
    const skipped  = results.filter(r => r.verdict === 'SKIP').length;
    console.log(` Accepted : ${accepted}`);
    console.log(` Rejected : ${rejected}`);
    console.log(` Unknown  : ${unknown}`);
    console.log(` Skipped  : ${skipped}`);
    console.log();

    if (accepted === adminIds.length - skipped && rejected === 0) {
        console.log(' ✅ VERDICT: All tested credentials align with Firebase Auth. Cutover-safe.');
    } else if (rejected > 0) {
        console.log(' ⚠ VERDICT: One or more passwords do NOT match Firebase Auth. Cutover unsafe until reconciled.');
    }
    console.log();
    process.exit(0);
})().catch(err => {
    console.error('admin_canary_signin_c0.js failed:', err);
    process.exit(1);
});
