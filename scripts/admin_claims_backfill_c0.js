#!/usr/bin/env node
/**
 * Wave C — Step 0.B'' : Admin claims backfill (Mode X — additive).
 *
 * Adds canonical camelCase fields + roleLabel to Firebase Auth custom
 * claims for admins on Schema B (snake_case). PRESERVES all existing
 * snake_case fields so any legacy reader continues to work.
 *
 * Default mode is `dry_run` — produces a before/after report and exits
 * WITHOUT writing. `apply` mode requires an explicit confirmation env var
 * (so it can't be triggered by an accidental subcommand typo).
 *
 * Read sources:
 *   - latest scripts/wave_c0_snapshots/inventory_*.json (target list)
 *   - Firebase Auth (per-admin getUser → customClaims)
 *
 * Write surface (apply mode only):
 *   - Firebase Auth setCustomUserClaims (one call per admin needing backfill)
 *
 * Never echoes passwords (script doesn't touch passwords at all).
 *
 * Usage:
 *   node scripts/admin_claims_backfill_c0.js                    # dry_run (default)
 *   node scripts/admin_claims_backfill_c0.js dry_run            # same
 *   WAVE_C_BACKFILL_CONFIRM=YES_I_AUTHORIZE \
 *     node scripts/admin_claims_backfill_c0.js apply            # actually write
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

const MODE_RAW = (process.argv[2] || 'dry_run').toLowerCase();
const MODE     = MODE_RAW === 'apply' ? 'apply' : 'dry_run';

if (MODE_RAW !== 'apply' && MODE_RAW !== 'dry_run' && process.argv[2]) {
    console.error('usage: node admin_claims_backfill_c0.js [dry_run|apply]');
    process.exit(2);
}
if (MODE === 'apply' && process.env.WAVE_C_BACKFILL_CONFIRM !== 'YES_I_AUTHORIZE') {
    console.error('REFUSING apply: set env WAVE_C_BACKFILL_CONFIRM=YES_I_AUTHORIZE to confirm.');
    process.exit(2);
}

/** Derive a Title-Case display label from a role value. */
function deriveRoleLabel(role) {
    if (typeof role !== 'string' || role.length === 0) return '';
    // SA-class machine labels → known display strings
    if (role === 'school_super_admin') return 'School Super Admin';
    if (role === 'super_admin')        return 'Super Admin';
    if (role === 'teacher')            return 'Teacher';
    // Staff-class: legacy convention stored display string directly in `role` field
    // → use the role value verbatim as the label (it's already a display string).
    return role;
}

/**
 * Compute the additive set of claims to ADD (Mode X).
 * Returns an object mapping FIELD_NAME → VALUE for every field that
 * is currently absent and can be deterministically derived.
 */
function planAdditiveBackfill(current) {
    const c    = current || {};
    const adds = {};

    // schoolId (camelCase) ← school_id (snake_case)
    if (typeof c.schoolId !== 'string' || c.schoolId.length === 0) {
        if (typeof c.school_id === 'string' && c.school_id.length > 0) {
            adds.schoolId = c.school_id;
        }
    }
    // schoolCode (camelCase) ← school_code (snake_case)
    if (typeof c.schoolCode !== 'string' || c.schoolCode.length === 0) {
        if (typeof c.school_code === 'string' && c.school_code.length > 0) {
            adds.schoolCode = c.school_code;
        }
    }
    // parentDbKey (camelCase) ← parent_db_key (snake_case)
    if (typeof c.parentDbKey !== 'string' || c.parentDbKey.length === 0) {
        if (typeof c.parent_db_key === 'string' && c.parent_db_key.length > 0) {
            adds.parentDbKey = c.parent_db_key;
        }
    }
    // roleLabel ← derive from role
    if (typeof c.roleLabel !== 'string' || c.roleLabel.length === 0) {
        if (typeof c.role === 'string' && c.role.length > 0) {
            adds.roleLabel = deriveRoleLabel(c.role);
        }
    }
    return adds;
}

(async function main() {
    console.log('═'.repeat(76));
    console.log(' WAVE C / Step 0.B" — ADMIN CLAIMS BACKFILL (Mode X — additive)');
    console.log('═'.repeat(76));
    console.log(' Mode      : ' + MODE.toUpperCase() + (MODE === 'dry_run' ? ' (no writes)' : ' (will perform setCustomUserClaims)'));
    console.log(' Policy    : ADD missing camelCase + roleLabel. PRESERVE snake_case fields.');
    console.log(' Source    : latest inventory snapshot under scripts/wave_c0_snapshots/');
    console.log();

    // ── Load inventory snapshot ─────────────────────────────────────────
    const snapDir = path.resolve(__dirname, 'wave_c0_snapshots');
    if (!fs.existsSync(snapDir)) {
        console.error('FATAL: no snapshot dir; run admin_inventory_c0.js first.');
        process.exit(1);
    }
    const snapFiles = fs.readdirSync(snapDir).filter(f => f.startsWith('inventory_') && f.endsWith('.json'));
    if (snapFiles.length === 0) {
        console.error('FATAL: no inventory snapshot found; run admin_inventory_c0.js first.');
        process.exit(1);
    }
    const latest = snapFiles.sort().pop();
    const snap   = JSON.parse(fs.readFileSync(path.join(snapDir, latest), 'utf8'));
    console.log(' Snapshot  : ' + latest);

    const targets = []
        .concat((snap.buckets && snap.buckets.in_all_three) || [])
        .concat((snap.buckets && snap.buckets.in_rtdb_and_fbauth_only) || []);
    console.log(' Targets   : ' + targets.length + ' admin(s) (in_all_three + in_rtdb_and_fbauth_only)');
    console.log();

    const summary = { already_canonical: 0, planned: 0, applied: 0, failed: 0 };

    for (const t of targets) {
        const adminId = t.adminId;
        let user;
        try {
            user = await auth.getUser(adminId);
        } catch (e) {
            console.log(' ❌ ' + adminId + ' : getUser failed → ' + e.message);
            summary.failed++;
            continue;
        }
        const current = user.customClaims || {};
        const adds    = planAdditiveBackfill(current);
        const numAdds = Object.keys(adds).length;

        if (numAdds === 0) {
            console.log(' ✓ ' + adminId.padEnd(8) + ' already on canonical schema (no fields to add)');
            summary.already_canonical++;
            continue;
        }

        const next = Object.assign({}, current, adds);
        console.log(' ─ ' + adminId.padEnd(8) + ' (' + (t.role || '?') + ' @ ' + (t.schoolId || '?') + ')');
        console.log('     BEFORE : ' + JSON.stringify(current));
        console.log('     ADDS   : ' + JSON.stringify(adds));
        console.log('     AFTER  : ' + JSON.stringify(next));

        if (MODE === 'dry_run') {
            summary.planned++;
        } else {
            try {
                await auth.setCustomUserClaims(user.uid, next);
                console.log('     RESULT : ✅ APPLIED');
                summary.applied++;
            } catch (e) {
                console.log('     RESULT : ❌ FAILED → ' + e.message);
                summary.failed++;
            }
        }
        console.log();
    }

    console.log('═'.repeat(76));
    console.log(' SUMMARY');
    console.log('═'.repeat(76));
    console.log(' Already canonical : ' + summary.already_canonical);
    if (MODE === 'dry_run') {
        console.log(' Planned (would write) : ' + summary.planned);
    } else {
        console.log(' Applied (wrote) : ' + summary.applied);
    }
    console.log(' Failed            : ' + summary.failed);
    console.log();
    if (MODE === 'dry_run') {
        console.log(' DRY-RUN ONLY — no Firebase Auth writes performed.');
        console.log(' To apply (operator-gated):');
        console.log('   WAVE_C_BACKFILL_CONFIRM=YES_I_AUTHORIZE \\');
        console.log('     node scripts/admin_claims_backfill_c0.js apply');
    } else {
        console.log(' APPLY complete.');
    }
    process.exit(summary.failed > 0 ? 1 : 0);
})().catch(err => {
    console.error('admin_claims_backfill_c0.js failed:', err && err.stack ? err.stack : err);
    process.exit(1);
});
