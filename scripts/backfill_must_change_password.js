#!/usr/bin/env node
/**
 * Backfill — force set-new-password for existing users.
 *
 * Flags every ACTIVE Firebase Auth user so they are required to set a new
 * password on their next login, matching the behaviour now applied to
 * freshly-created users and admin-reset users.
 *
 * What it writes (apply mode only):
 *   1. Firebase Auth custom claim  must_change_password = true   (ADDITIVE —
 *      preserves every existing claim; this is the signal the Teacher app and
 *      the web admin/SA login gates read).
 *   2. Firestore mirror, only where a consumer reads the doc field instead of
 *      the claim:
 *        - students/{schoolId}_{uid}.mustChangePassword = true   (Parent app)
 *        - superAdmins/{uid}.mustChangePassword         = true   (SA panel gate)
 *      Staff/admins are driven by the claim alone, so no doc write for them.
 *
 * Who is SKIPPED:
 *   - disabled Auth users (not "active")
 *   - the operator account(s): SUP0001 by default (+ $EXCLUDE_IDS csv)
 *   - users already carrying must_change_password === true (idempotent)
 *   - users with no usable claims (system / un-provisioned accounts)
 *
 * Never touches passwords.
 *
 * Usage:
 *   node scripts/backfill_must_change_password.js                 # dry-run (default, no writes)
 *   node scripts/backfill_must_change_password.js dry_run         # same
 *   MCP_BACKFILL_CONFIRM=YES_I_AUTHORIZE \
 *     node scripts/backfill_must_change_password.js apply         # actually write
 *
 * Optional env:
 *   EXCLUDE_IDS=SUP0001,SUP0002   extra uids to skip (merged with the default)
 *   RTDB_URL=...                  override RTDB url
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

const MODE_RAW = (process.argv[2] || 'dry_run').toLowerCase();
const MODE     = MODE_RAW === 'apply' ? 'apply' : 'dry_run';

if (MODE_RAW !== 'apply' && MODE_RAW !== 'dry_run' && process.argv[2]) {
    console.error('usage: node backfill_must_change_password.js [dry_run|apply]');
    process.exit(2);
}
if (MODE === 'apply' && process.env.MCP_BACKFILL_CONFIRM !== 'YES_I_AUTHORIZE') {
    console.error('REFUSING apply: set env MCP_BACKFILL_CONFIRM=YES_I_AUTHORIZE to confirm.');
    process.exit(2);
}

// Operator / excluded accounts — never lock these out mid-run.
const EXCLUDED = new Set(
    ['SUP0001']
        .concat((process.env.EXCLUDE_IDS || '').split(',').map(s => s.trim()).filter(Boolean))
        .map(s => s.toUpperCase())
);

/** Classify a user from its custom claims → where the Firestore mirror (if any) lives. */
function classify(claims) {
    const c = claims || {};
    const role = String(c.role || '').toLowerCase();
    const schoolId = String(c.schoolId || c.school_id || '');
    // A student is anyone carrying a student identity claim (Parent logs in AS
    // the student). role may be 'student' (creation) or 'Parent' (reset).
    const isStudent = (typeof c.student_id === 'string' && c.student_id.length > 0) || role === 'student';
    const isSuperAdmin = role === 'super_admin';
    if (isStudent)     return { type: 'student',    schoolId };
    if (isSuperAdmin)  return { type: 'super_admin', schoolId };
    if (schoolId)      return { type: 'staff_admin', schoolId }; // teacher / admin / SSA — claim-driven
    return { type: 'unclassified', schoolId };
}

const stats = {
    scanned: 0, flagged: 0, alreadyFlagged: 0, disabled: 0, excluded: 0,
    unclassified: 0, fsStudent: 0, fsSuperAdmin: 0, errors: 0,
    byType: { student: 0, super_admin: 0, staff_admin: 0 },
};
const sample = [];

async function flagUser(u) {
    const claims = u.customClaims || {};
    const merged = Object.assign({}, claims, { must_change_password: true });

    if (MODE === 'apply') {
        await auth.setCustomUserClaims(u.uid, merged);
        // Revoke tokens so the flag takes effect on the very next request, not
        // after the current ID token's ~1h expiry.
        try { await auth.revokeRefreshTokens(u.uid); } catch (e) { /* best-effort */ }
    }

    const { type, schoolId } = classify(claims);
    stats.byType[type] = (stats.byType[type] || 0) + 1;

    if (type === 'student' && schoolId) {
        stats.fsStudent++;
        if (MODE === 'apply') {
            await db.collection('students').doc(`${schoolId}_${u.uid}`)
                .set({ mustChangePassword: true, updatedAt: new Date().toISOString() }, { merge: true });
        }
    } else if (type === 'super_admin') {
        stats.fsSuperAdmin++;
        if (MODE === 'apply') {
            await db.collection('superAdmins').doc(u.uid)
                .set({ mustChangePassword: true, updatedAt: new Date().toISOString() }, { merge: true });
        }
    }

    stats.flagged++;
    if (sample.length < 25) sample.push(`${u.uid} [${type}]`);
}

(async function main() {
    console.log('═'.repeat(76));
    console.log(' BACKFILL — force set-new-password for existing users');
    console.log('═'.repeat(76));
    console.log(' Mode     : ' + MODE.toUpperCase() + (MODE === 'dry_run' ? ' (NO writes)' : ' (WILL write claims + Firestore)'));
    console.log(' Excluded : ' + Array.from(EXCLUDED).join(', '));
    console.log(' Policy   : ADD must_change_password=true (preserve other claims); mirror students/superAdmins docs.');
    console.log();

    let pageToken;
    do {
        const res = await auth.listUsers(1000, pageToken);
        for (const u of res.users) {
            stats.scanned++;
            const claims = u.customClaims || {};

            if (u.disabled) { stats.disabled++; continue; }
            if (EXCLUDED.has(String(u.uid).toUpperCase())) { stats.excluded++; continue; }
            if (claims.must_change_password === true) { stats.alreadyFlagged++; continue; }

            const { type } = classify(claims);
            if (type === 'unclassified') { stats.unclassified++; continue; }

            try {
                await flagUser(u);
            } catch (e) {
                stats.errors++;
                console.error(`  ERROR flagging ${u.uid}: ${e.message}`);
            }
        }
        pageToken = res.pageToken;
    } while (pageToken);

    console.log();
    console.log('─'.repeat(76));
    console.log(' RESULT');
    console.log('─'.repeat(76));
    console.log(' Scanned          : ' + stats.scanned);
    console.log(' Flagged          : ' + stats.flagged + (MODE === 'dry_run' ? ' (WOULD flag)' : ''));
    console.log('   • student      : ' + stats.byType.student     + '  (+ ' + stats.fsStudent    + ' students-doc mirrors)');
    console.log('   • super_admin  : ' + stats.byType.super_admin + '  (+ ' + stats.fsSuperAdmin + ' superAdmins-doc mirrors)');
    console.log('   • staff/admin  : ' + stats.byType.staff_admin + '  (claim only)');
    console.log(' Already flagged  : ' + stats.alreadyFlagged + ' (skipped)');
    console.log(' Disabled         : ' + stats.disabled + ' (skipped)');
    console.log(' Excluded/operator: ' + stats.excluded + ' (skipped)');
    console.log(' Unclassified     : ' + stats.unclassified + ' (skipped — no usable claims)');
    console.log(' Errors           : ' + stats.errors);
    if (sample.length) {
        console.log();
        console.log(' Sample flagged   : ' + sample.join(', ') + (stats.flagged > sample.length ? ' …' : ''));
    }
    console.log();
    if (MODE === 'dry_run') {
        console.log(' DRY-RUN — nothing was written. Re-run with:');
        console.log('   MCP_BACKFILL_CONFIRM=YES_I_AUTHORIZE node scripts/backfill_must_change_password.js apply');
    } else {
        console.log(' APPLY complete. Flagged users will set a new password on their next login.');
    }
    process.exit(0);
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
