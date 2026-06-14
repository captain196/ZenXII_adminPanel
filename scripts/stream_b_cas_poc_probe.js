#!/usr/bin/env node
/**
 * Stream B — CAS proof-of-concept probe.
 *
 * Exercises the EXACT precondition semantics our Firestore_rest_client uses
 * via the same Firestore REST API endpoint (admin-SDK gRPC uses identical
 * server-side semantics; precondition enforcement is in the backend).
 *
 * Four tests:
 *  T1 — updateTime CAPTURE — verifies __updateTime is exposed on read
 *  T2 — precondition ENFORCEMENT — write with stale updateTime → FAILED_PRECONDITION
 *  T3 — retry SUCCESS path — re-read fresh updateTime → commit succeeds
 *  T4 — retry EXHAUSTION path — force 4 consecutive conflicts → exhausted
 *
 * All operations against synthetic doc `STREAM_B_CAS_POC/probe`. Self-cleans.
 */
const path = require('path');
const fs = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();

const COL = 'STREAM_B_CAS_POC';
const DOC = 'probe_' + Date.now();

function ok(label, val) { console.log('  ' + label + (val !== undefined ? ': ' + val : '')); }
function fail(label, err) { console.log('  FAIL ' + label + ': ' + (err && err.message || err)); }
function header(s) { console.log('\n-- ' + s + ' --'); }

async function setup() {
    const ref = fsdb.collection(COL).doc(DOC);
    await ref.set({
        schoolId: 'SCH_PROBE_CAS_POC',
        dayWise: 'V'.repeat(30),
        present: 0, absent: 0, leave: 0, holiday: 0, tardy: 0, void: 30,
        _probe: true, _setupAt: new Date().toISOString(),
    });
    return ref;
}

async function cleanup(ref) {
    try { await ref.delete(); } catch (_) {}
}

(async function main() {
    const hdr = '='.repeat(76);
    console.log(hdr);
    console.log('  Stream B - CAS Proof-of-Concept Probe');
    console.log('  Tenant: SCH_PROBE_CAS_POC   Time:', new Date().toISOString());
    console.log(hdr);

    const ref = await setup();
    const results = { T1: false, T2: false, T3: false, T4: false };

    // T1: updateTime CAPTURE
    header('T1 - updateTime CAPTURE on read');
    try {
        const snap = await ref.get();
        if (!snap.exists) throw new Error('doc missing');
        const updateTime = snap.updateTime;
        if (!updateTime) throw new Error('updateTime missing from snapshot');
        ok('snapshot.exists', 'true');
        ok('snapshot.updateTime captured', updateTime.toDate().toISOString());
        ok('snapshot.data.present', snap.data().present);
        results.T1 = true;
    } catch (e) { fail('T1 failed', e); }

    // T2: precondition ENFORCEMENT
    header('T2 - precondition ENFORCEMENT (stale updateTime should be rejected)');
    try {
        const snap1 = await ref.get();
        const captured = snap1.updateTime;

        // Move the doc out-of-band (simulating concurrent write)
        await ref.update({ present: 99 });

        // Attempt commit with the STALE captured updateTime
        let failedAsExpected = false;
        try {
            await ref.update({ present: 100 }, { lastUpdateTime: captured });
            fail('T2 expected failure but commit succeeded');
        } catch (e) {
            const code = e.code;
            const msg = (e.message || '').toLowerCase();
            if (code === 9 || code === 10 ||
                msg.includes('failed_precondition') ||
                msg.includes('aborted') ||
                msg.includes('precondition')) {
                ok('precondition correctly rejected stale updateTime');
                ok('error code', code);
                ok('error message', e.message.slice(0, 120));
                failedAsExpected = true;
            } else {
                fail('T2 unexpected error type', e);
            }
        }
        results.T2 = failedAsExpected;
    } catch (e) { fail('T2 setup failed', e); }

    // T3: retry SUCCESS path
    header('T3 - retry SUCCESS path');
    try {
        let attempts = 0;
        let succeeded = false;
        const maxRetries = 3;

        await ref.update({ absent: 1 });

        for (let i = 0; i <= maxRetries; i++) {
            attempts++;
            const snap = await ref.get();
            const captured = snap.updateTime;
            try {
                await ref.update({ present: 1, void: 29 }, { lastUpdateTime: captured });
                succeeded = true;
                break;
            } catch (e) {
                const backoff = 50 * Math.pow(2, i) + Math.floor(Math.random() * 50);
                await new Promise(r => setTimeout(r, backoff));
            }
        }
        if (succeeded) {
            ok('retry loop succeeded after attempts', attempts);
            const verify = await ref.get();
            ok('post-commit state present', verify.data().present);
            ok('post-commit state void', verify.data().void);
            results.T3 = true;
        } else {
            fail('T3 retry loop did NOT succeed', `attempts=${attempts}`);
        }
    } catch (e) { fail('T3 failed', e); }

    // T4: retry EXHAUSTION path
    header('T4 - retry EXHAUSTION (force conflict each attempt)');
    try {
        let attempts = 0;
        let exhausted = false;
        const maxRetries = 3;

        for (let i = 0; i <= maxRetries; i++) {
            attempts++;
            const snap = await ref.get();
            const captured = snap.updateTime;
            // Force out-of-band write before attempting commit
            await ref.update({ tardy: (snap.data().tardy || 0) + 1 });
            try {
                await ref.update({ present: 999 }, { lastUpdateTime: captured });
                console.log('  WARN commit succeeded unexpectedly at attempt ' + attempts);
                break;
            } catch (e) {
                if (i === maxRetries) {
                    exhausted = true;
                    ok('retries exhausted as expected after attempts', attempts);
                    ok('final error code', e.code);
                }
            }
            const backoff = 50 * Math.pow(2, i) + Math.floor(Math.random() * 50);
            await new Promise(r => setTimeout(r, backoff));
        }
        if (exhausted) results.T4 = true;
        else fail('T4 did not exhaust as expected');
    } catch (e) { fail('T4 failed', e); }

    // Summary
    console.log('\n' + hdr);
    console.log('  CAS PoC Summary');
    console.log(hdr);
    Object.entries(results).forEach(([k, v]) => {
        console.log('  ' + k + ': ' + (v ? 'PASS' : 'FAIL'));
    });
    const all = Object.values(results).every(Boolean);
    console.log('  ' + (all ? 'ALL 4 PATHS VERIFIED' : 'ONE OR MORE PATHS DID NOT VERIFY'));

    await cleanup(ref);
    console.log('  [cleanup] probe doc deleted');

    process.exit(all ? 0 : 2);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
