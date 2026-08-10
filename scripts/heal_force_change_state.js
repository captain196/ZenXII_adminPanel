#!/usr/bin/env node
/**
 * Heal — repair incoherent forced-set-new-password state.
 *
 * Companion to diagnose_force_change_state.js. Fixes the two shapes of damage
 * left by the defects fixed on 2026-08-10:
 *
 *   A. STUCK  (claim=false, mirror=true)
 *      The app gates the user forever and every submit is refused. Two ways in:
 *      the mirror clear was misrouted to `admins` (first-login students), or a
 *      wholesale claim re-mint dropped a pending flag (staff).
 *
 *      HEAL = RE-ARM THE CLAIM, not clear the mirror.
 *      claim=false is ambiguous: the user may have completed their change, or a
 *      re-mint may have stripped the flag while they are STILL on the admin-set
 *      temporary password. Clearing the mirror would silently waive the forced
 *      change for the second group. Re-arming makes the state coherent
 *      (claim=true, mirror=true) and lets the now-fixed flow finish it — worst
 *      case a user sets their password once more.
 *
 *   B. Junk `admins/{schoolId}_STU####` docs
 *      Created only by the misrouted mirror clear. Inert (they carry no schoolId
 *      so no roster query returns them) but they are litter, and their existence
 *      is the audit trail of which students were affected.
 *
 * Dry-run by default. Nothing is written without BOTH `apply` and the confirm env.
 *
 * Usage:
 *   node scripts/heal_force_change_state.js                    # dry run
 *   MCP_BACKFILL_CONFIRM=YES_I_AUTHORIZE \
 *     node scripts/heal_force_change_state.js apply            # re-arm claims
 *   MCP_BACKFILL_CONFIRM=YES_I_AUTHORIZE \
 *     node scripts/heal_force_change_state.js apply --purge-junk   # + delete junk docs
 */
const path = require('path');
const fs   = require('fs');

let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }

const SA_PATH = path.resolve(__dirname, '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
const SVC = JSON.parse(fs.readFileSync(SA_PATH, 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const auth = admin.auth();
const db   = admin.firestore();

const MODE       = (process.argv[2] || 'dry_run').toLowerCase() === 'apply' ? 'apply' : 'dry_run';
const PURGE_JUNK = process.argv.includes('--purge-junk');

if (MODE === 'apply' && process.env.MCP_BACKFILL_CONFIRM !== 'YES_I_AUTHORIZE') {
    console.error('REFUSING apply: set MCP_BACKFILL_CONFIRM=YES_I_AUTHORIZE to confirm.');
    process.exit(2);
}

const truthy = v => v === true || v === 'true' || v === 1;

function collectionFor(uid) {
    if (/^STU\d+$/i.test(uid)) return 'students';
    if (/^STA\d+$/i.test(uid)) return 'staff';
    if (/^(ADM|SSA)\d+$/i.test(uid)) return 'admins';
    return null;
}

(async () => {
    console.log(`\nMODE: ${MODE}${PURGE_JUNK ? '  (+purge junk docs)' : ''}\n`);

    // ── Collect Auth users + their mirrors ────────────────────────────────
    const users = [];
    let pageToken;
    do {
        const page = await auth.listUsers(1000, pageToken);
        for (const u of page.users) {
            const c = u.customClaims || {};
            users.push({
                uid: u.uid,
                disabled: u.disabled,
                claims: c,
                schoolId: String(c.schoolId || c.school_id || ''),
                claim: truthy(c.must_change_password),
                coll: collectionFor(u.uid),
            });
        }
        pageToken = page.pageToken;
    } while (pageToken);

    const targets = users.filter(u => u.coll && u.schoolId && !u.disabled);
    const refs    = targets.map(u => db.collection(u.coll).doc(`${u.schoolId}_${u.uid}`));

    const snaps = [];
    for (let i = 0; i < refs.length; i += 300) {
        snaps.push(...await db.getAll(...refs.slice(i, i + 300)));
    }

    const stuck = [];
    targets.forEach((u, i) => {
        const s = snaps[i];
        const mirror = s && s.exists ? truthy(s.data().mustChangePassword) : null;
        if (!u.claim && mirror === true) stuck.push(u);
    });

    // ── A. Re-arm the claim on stuck accounts ─────────────────────────────
    console.log(`── STUCK accounts (claim=false, mirror=true): ${stuck.length}`);
    for (const u of stuck) {
        console.log(`   ${u.uid.padEnd(12)} ${u.coll}/${u.schoolId}_${u.uid}`);
        if (MODE === 'apply') {
            // ADDITIVE: preserve every existing claim, add only the reset keys.
            const next = Object.assign({}, u.claims, {
                must_change_password: true,
                password_reset_at: Math.floor(Date.now() / 1000),
                password_reset_by: 'heal_force_change_state',
            });
            await auth.setCustomUserClaims(u.uid, next);
            await auth.revokeRefreshTokens(u.uid);
            console.log(`      → claim re-armed + tokens revoked`);
        }
    }

    // ── B. Junk docs ──────────────────────────────────────────────────────
    const junkSnap = await db.collection('admins').get();
    const junk = junkSnap.docs.filter(d => /_STU\d+$/i.test(d.id));
    console.log(`\n── Junk admins/{school}_STU* docs: ${junk.length}`);
    for (const d of junk) {
        const keys = Object.keys(d.data());
        const safe = keys.length <= 3 && keys.every(k => ['mustChangePassword', 'updatedAt', 'schoolId'].includes(k));
        console.log(`   ${d.id}  [${keys.join('|')}]  ${safe ? 'safe to delete' : 'UNEXPECTED FIELDS — left alone'}`);
        if (MODE === 'apply' && PURGE_JUNK && safe) {
            await d.ref.delete();
            console.log('      → deleted');
        }
    }

    console.log(MODE === 'apply' ? '\nDone.\n' : '\nDry run — nothing written.\n');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
