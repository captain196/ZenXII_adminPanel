#!/usr/bin/env node
/**
 * Diagnostic — force-change-password state coherence.  READ ONLY. Never writes.
 *
 * The forced set-new-password flag lives in TWO places that must agree:
 *   1. Firebase Auth custom claim  must_change_password
 *   2. The profile doc mirror      students|staff|admins .mustChangePassword
 *
 * The Parent app gates on (doc OR claim); the Teacher app and both web panels
 * gate on the claim. So a user whose CLAIM is cleared but whose DOC still says
 * true is permanently stuck: the Parent app keeps showing the force-change
 * screen, and POST /auth/clear_must_change rejects every retry with
 * "No password change required for this account" (it requires the claim).
 *
 * This script classifies every account by that pair and reports the stuck set.
 *
 * It also counts junk docs at  admins/{schoolId}_STU*  — those can only be
 * created by Auth_api::_clear_firestore_flag misrouting a student's mirror
 * clear, so each one is direct evidence of a student who completed a
 * force-change in the app and got their flag written to the wrong collection.
 *
 * Usage:
 *   node scripts/diagnose_force_change_state.js
 *   node scripts/diagnose_force_change_state.js --csv > stuck.csv
 */
const path = require('path');
const fs   = require('fs');

let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }

const SA_PATH = path.resolve(__dirname, '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
const DB_URL  = process.env.RTDB_URL || 'https://graderadmin-default-rtdb.firebaseio.com/';

const SVC = JSON.parse(fs.readFileSync(SA_PATH, 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC), databaseURL: DB_URL });
const auth = admin.auth();
const db   = admin.firestore();

const AS_CSV = process.argv.includes('--csv');

const truthy = v => v === true || v === 'true' || v === 1;

/** Which profile collection SHOULD hold this uid's mirror, by id prefix. */
function collectionFor(uid) {
    if (/^STU\d+$/i.test(uid)) return 'students';
    if (/^STA\d+$/i.test(uid)) return 'staff';
    if (/^(ADM|SSA)\d+$/i.test(uid)) return 'admins';
    return null;   // SUP* and anything unrecognised — no per-school mirror
}

(async () => {
    // ── 1. Walk every Auth user, capture claim state ──────────────────────
    const users = [];
    let pageToken;
    do {
        const page = await auth.listUsers(1000, pageToken);
        for (const u of page.users) {
            const c        = u.customClaims || {};
            const schoolId = String(c.schoolId || c.school_id || '');
            users.push({
                uid:      u.uid,
                disabled: u.disabled,
                role:     String(c.role || ''),
                schoolId,
                claim:    truthy(c.must_change_password),
                coll:     collectionFor(u.uid),
            });
        }
        pageToken = page.pageToken;
    } while (pageToken);

    // ── 2. Read every mirror doc — batched via getAll, not one get per user ─
    const rows      = [];
    const needsDoc  = users.filter(u => u.coll && u.schoolId);
    const noMirror  = users.filter(u => !(u.coll && u.schoolId));
    const refs      = needsDoc.map(u => db.collection(u.coll).doc(`${u.schoolId}_${u.uid}`));

    const CHUNK = 300;                       // getAll caps the request size
    const snaps = [];
    for (let i = 0; i < refs.length; i += CHUNK) {
        snaps.push(...await db.getAll(...refs.slice(i, i + CHUNK)));
    }
    needsDoc.forEach((u, i) => {
        const snap = snaps[i];
        rows.push({ ...u, doc: snap && snap.exists ? truthy(snap.data().mustChangePassword) : null });
    });
    noMirror.forEach(u => rows.push({ ...u, doc: null, note: 'no mirror expected' }));

    // ── 3. Classify ───────────────────────────────────────────────────────
    const stuck   = rows.filter(r => !r.claim && r.doc === true && !r.disabled);
    const pending = rows.filter(r =>  r.claim && r.doc === true);
    const anomaly = rows.filter(r =>  r.claim && r.doc === false);
    const clean   = rows.filter(r => !r.claim && r.doc === false);
    const noDoc   = rows.filter(r => r.coll && r.doc === null);

    // ── 4. Junk docs from the misroute ────────────────────────────────────
    let junk = [];
    try {
        const snap = await db.collection('admins').get();
        junk = snap.docs
            .filter(d => /_STU\d+$/i.test(d.id))
            .map(d => ({ id: d.id, keys: Object.keys(d.data()).join('|') }));
    } catch (e) { junk = [{ id: 'READ FAILED: ' + e.message, keys: '' }]; }

    if (AS_CSV) {
        console.log('uid,role,schoolId,collection,claim,doc,state,disabled');
        const label = r => (!r.claim && r.doc === true) ? 'STUCK'
                        : ( r.claim && r.doc === true) ? 'PENDING'
                        : ( r.claim && r.doc === false) ? 'ANOMALY'
                        : (!r.claim && r.doc === false) ? 'CLEAN' : 'NO_DOC';
        for (const r of rows) {
            console.log([r.uid, r.role, r.schoolId, r.coll || '', r.claim, r.doc, label(r), r.disabled].join(','));
        }
        return;
    }

    const byPrefix = list => {
        const m = {};
        for (const r of list) { const p = (r.uid.match(/^[A-Z]+/i) || ['?'])[0].toUpperCase(); m[p] = (m[p] || 0) + 1; }
        return Object.entries(m).map(([k, v]) => `${k}:${v}`).join('  ') || '—';
    };

    console.log('\n══ FORCE-CHANGE STATE COHERENCE ══════════════════════════════');
    console.log(`Auth users scanned            : ${users.length}`);
    console.log(`  disabled                    : ${users.filter(u => u.disabled).length}`);
    console.log('');
    console.log(`STUCK   claim=false doc=true  : ${stuck.length}   ${byPrefix(stuck)}`);
    console.log('        └─ app shows force-change forever; every retry 400s');
    console.log(`PENDING claim=true  doc=true  : ${pending.length}   ${byPrefix(pending)}`);
    console.log('        └─ normal: created/reset, not yet changed');
    console.log(`ANOMALY claim=true  doc=false : ${anomaly.length}   ${byPrefix(anomaly)}`);
    console.log(`CLEAN   claim=false doc=false : ${clean.length}   ${byPrefix(clean)}`);
    console.log(`NO_DOC  mirror missing        : ${noDoc.length}   ${byPrefix(noDoc)}`);
    console.log('');
    console.log(`Junk admins/{school}_STU* docs: ${junk.length}`);
    console.log('        └─ each = a student whose mirror clear was misrouted');
    for (const j of junk.slice(0, 25)) console.log(`           ${j.id}   [${j.keys}]`);
    if (junk.length > 25) console.log(`           … ${junk.length - 25} more`);

    if (stuck.length) {
        console.log('\n── STUCK ACCOUNTS ────────────────────────────────────────────');
        for (const s of stuck.slice(0, 60)) {
            console.log(`  ${s.uid.padEnd(12)} role=${(s.role || '—').padEnd(10)} ${s.coll}/${s.schoolId}_${s.uid}`);
        }
        if (stuck.length > 60) console.log(`  … ${stuck.length - 60} more`);
    }
    console.log('');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
