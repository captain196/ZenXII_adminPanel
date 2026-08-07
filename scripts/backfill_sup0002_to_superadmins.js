#!/usr/bin/env node
/**
 * SUP0002 Firestore canonical backfill.
 *
 * Reads RTDB Users/Admin/Our Panel/SUP0002 (legacy).
 * Reads Firestore superAdmins/SUP0001 (canonical shape reference).
 * Writes Firestore superAdmins/SUP0002 mirroring the canonical shape.
 *
 * Objective: Firestore canonical contains all SUP identities so the
 * Superadmin_admins.php:109 legacy RTDB scan can be retired without
 * id-collision risk.
 *
 * The RTDB record is NOT modified. The existing Firestore SUP0001 is
 * NOT modified. Only superAdmins/SUP0002 is created/updated.
 *
 * Usage: node scripts/backfill_sup0002_to_superadmins.js
 *        node scripts/backfill_sup0002_to_superadmins.js --dry-run
 */
const path = require('path');
const fs = require('fs');

const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));

const SA_PATH = path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
const SVC = JSON.parse(fs.readFileSync(SA_PATH, 'utf8'));
const DB_URL = process.env.RTDB_URL || 'https://graderadmin-default-rtdb.firebaseio.com/';
admin.initializeApp({ credential: admin.credential.cert(SVC), databaseURL: DB_URL });

const fsdb = admin.firestore();
const rtdb = admin.database();
const DRY_RUN = process.argv.includes('--dry-run');

(async function main() {
    console.log('═'.repeat(72));
    console.log('  SUP0002 Firestore canonical backfill');
    console.log('  Mode:', DRY_RUN ? 'DRY RUN (no writes)' : 'LIVE');
    console.log('═'.repeat(72));

    // ── Read RTDB SUP0002 ──────────────────────────────────────────────
    const rtdbSnap = await rtdb.ref('Users/Admin/Our Panel/SUP0002').once('value');
    const rtdb0002 = rtdbSnap.val();
    if (!rtdb0002) {
        console.error('  ✗ SUP0002 not found in RTDB Users/Admin/Our Panel — nothing to backfill');
        process.exit(1);
    }
    console.log('\n[1] RTDB SUP0002 fields:');
    Object.keys(rtdb0002).sort().forEach(k => {
        let v = rtdb0002[k];
        if (typeof v === 'object') v = '<map>';
        else if (typeof v === 'string' && v.length > 60) v = v.substring(0, 57) + '...';
        console.log('      ' + k + ': ' + v);
    });

    // ── Read canonical SUP0001 Firestore doc as shape reference ────────
    const fsSnap = await fsdb.collection('superAdmins').doc('SUP0001').get();
    if (!fsSnap.exists) {
        console.error('  ✗ Canonical superAdmins/SUP0001 not found — no reference shape');
        process.exit(1);
    }
    const fs0001 = fsSnap.data() || {};
    console.log('\n[2] Canonical superAdmins/SUP0001 fields (shape reference):');
    Object.keys(fs0001).sort().forEach(k => {
        let v = fs0001[k];
        if (v && typeof v === 'object' && !Array.isArray(v) && !(v._seconds !== undefined)) v = '<map>';
        else if (typeof v === 'string' && v.length > 60) v = v.substring(0, 57) + '...';
        else if (v && v._seconds !== undefined) v = 'Timestamp(' + v._seconds + ')';
        console.log('      ' + k + ': ' + v);
    });

    // ── Map RTDB SUP0002 to canonical Firestore shape ──────────────────
    const profile = rtdb0002.Profile || {};
    const access = rtdb0002.AccessHistory || {};

    const fs0002 = {
        superAdminId: 'SUP0002',
        name: rtdb0002.Name || profile.name || 'Developer Super Admin',
        email: rtdb0002.Email || profile.email || '',
        role: 'super_admin',                                  // canonical Firestore role value
        status: rtdb0002.Status || 'Active',
        firebaseUid: rtdb0002.firebaseUid || profile.firebase_uid || 'SUP0002',
        backfilledAt: admin.firestore.FieldValue.serverTimestamp(),
        backfillSource: 'rtdb:Users/Admin/Our Panel/SUP0002',
    };

    // Preserve canonical fields present on SUP0001 if RTDB has them
    if (profile.phone) fs0002.phone = profile.phone;
    if (profile.created_at) fs0002.createdAt = profile.created_at;
    if (profile.created_by) fs0002.createdBy = profile.created_by;
    if (access.SA_LastLogin) fs0002.lastLoginAt = access.SA_LastLogin;
    if (access.SA_LastLoginIP) fs0002.lastLoginIp = access.SA_LastLoginIP;

    console.log('\n[3] Computed Firestore superAdmins/SUP0002 payload:');
    Object.keys(fs0002).sort().forEach(k => {
        let v = fs0002[k];
        if (v && typeof v === 'object' && !(v._methodName)) v = '<map>';
        else if (typeof v === 'string' && v.length > 60) v = v.substring(0, 57) + '...';
        else if (v && v._methodName) v = 'FieldValue.' + v._methodName;
        console.log('      ' + k + ': ' + v);
    });

    if (DRY_RUN) {
        console.log('\n  [DRY RUN] Skipping write. Re-run without --dry-run to commit.');
        process.exit(0);
    }

    // ── Write superAdmins/SUP0002 (merge:true to preserve existing fields if any) ──
    await fsdb.collection('superAdmins').doc('SUP0002').set(fs0002, { merge: true });
    console.log('\n  ✓ superAdmins/SUP0002 written successfully');

    // ── Verify by reading back ─────────────────────────────────────────
    const verifySnap = await fsdb.collection('superAdmins').doc('SUP0002').get();
    const verifyData = verifySnap.data() || {};
    console.log('\n[4] Verification — read-back of superAdmins/SUP0002:');
    Object.keys(verifyData).sort().forEach(k => {
        let v = verifyData[k];
        if (v && typeof v === 'object' && !(v._seconds !== undefined)) v = '<map>';
        else if (typeof v === 'string' && v.length > 60) v = v.substring(0, 57) + '...';
        else if (v && v._seconds !== undefined) v = 'Timestamp(' + v._seconds + ')';
        console.log('      ' + k + ': ' + v);
    });

    console.log('\n' + '═'.repeat(72));
    console.log('  ✓ BACKFILL COMPLETE');
    console.log('  Next: re-run PF-1 via scripts/unit_abc_preflight_probe.js');
    console.log('═'.repeat(72));
    process.exit(0);
})().catch(err => { console.error('FATAL:', err && err.stack || err); process.exit(1); });
