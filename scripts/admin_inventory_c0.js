#!/usr/bin/env node
/**
 * Wave C — Step 0.A : School-admin inventory across RTDB + Firestore + Firebase Auth.
 *
 * PASSIVE / REVERSIBLE — mirror of sa_inventory_a0.js safe pattern:
 *   - Firebase access is strictly READ-ONLY (.once('value'), .get(), listUsers).
 *   - The ONLY writes are local JSON files under scripts/wave_c0_snapshots/ (delete to undo).
 *   - Zero mutation of RTDB / Firestore / Firebase Auth.
 *
 * Discovery-style — does NOT assume:
 *   - any particular email convention (reads each admin record's Email field directly)
 *   - the SUP* lowercase + @schoolsync.app convention applies to school admins
 *   - any particular hash format
 *
 * Produces:
 *   - Cross-system presence per admin (RTDB | Firebase Auth | Firestore staff)
 *   - Password-hash recoverability classification (bcrypt | other | absent)
 *   - Per-school admin counts
 *   - Firebase Auth lookup attempts via (a) record email (b) synthetic adminId@schoolsync.app
 *   - Local JSON snapshot under scripts/wave_c0_snapshots/<timestamp>.json
 *
 * Never prints password hashes — only presence + prefix + length (format, not secret).
 *
 * Excludes SAs (SUP*** at Users/Admin/Our Panel/*) — those are Wave A scope, not Wave C.
 *
 * Usage: node scripts/admin_inventory_c0.js
 */
const path = require('path');
const fs = require('fs');

let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }

const SA_PATH = path.resolve(__dirname, '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
const DB_URL  = process.env.RTDB_URL || 'https://graderadmin-default-rtdb.firebaseio.com/';
const SYNTHETIC_DOMAIN = 'schoolsync.app';

const SVC = JSON.parse(fs.readFileSync(SA_PATH, 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC), databaseURL: DB_URL });

const db   = admin.database();
const fsdb = admin.firestore();
const auth = admin.auth();

function syntheticEmail(adminId) {
    // Wave A SA convention — kept as one of MULTIPLE lookup strategies, not the only one.
    return String(adminId).toLowerCase() + '@' + SYNTHETIC_DOMAIN;
}

function hashFormat(pw) {
    if (!pw || typeof pw !== 'string') return { present: false, length: 0, prefix: '', bcrypt: false };
    const bcryptRe = /^\$2[abxy]\$/;
    return {
        present: true,
        length: pw.length,
        prefix: pw.substring(0, 4),
        bcrypt: bcryptRe.test(pw) && pw.length === 60,
    };
}

// Sniff the admin record for an Email field. Different writers used different field names
// over time; we try a few in priority order. No assumption is made about validity.
function extractEmail(rec) {
    if (!rec || typeof rec !== 'object') return '';
    const candidates = [
        rec.Email, rec.email,
        rec.LoginEmail, rec.login_email,
        rec.Credentials && rec.Credentials.Email,
        rec.Credentials && rec.Credentials.email,
        rec.Profile && rec.Profile.Email,
    ];
    for (const c of candidates) {
        if (c && typeof c === 'string' && c.trim().length > 0) return c.trim().toLowerCase();
    }
    return '';
}

(async function main() {
    console.log('═'.repeat(72));
    console.log(' WAVE C / STEP 0.A — SCHOOL ADMIN INVENTORY (READ-ONLY · DISCOVERY)');
    console.log('═'.repeat(72));
    console.log(' RTDB                : ' + DB_URL);
    console.log(' Synthetic-email try : {adminId.lower}@' + SYNTHETIC_DOMAIN + '  (Wave A SA convention, one of two strategies)');
    console.log(' Record-email try    : record.Email / record.email / Credentials.Email');
    console.log(' Scope               : school-admins only (Users/Admin/{schoolId}/*)');
    console.log('                       SUPER admins (Users/Admin/Our Panel/*) are SKIPPED');
    console.log();

    // ── Step 1: RTDB enumeration ───────────────────────────────────────────
    console.log('Step 1: Reading RTDB Users/Admin entire subtree (single read)...');
    const rootSnap = await db.ref('Users/Admin').once('value');
    const root = rootSnap.val() || {};
    const allRtdbKeys = Object.keys(root);
    console.log('  RTDB top-level keys under Users/Admin : ' + allRtdbKeys.length);

    const rtdbAdmins = [];
    for (const key of allRtdbKeys) {
        if (/^our panel$/i.test(key)) {
            console.log('  SKIP : Users/Admin/' + key + ' (SA scope — Wave A, not Wave C)');
            continue;
        }
        const schoolId = key;
        const schoolBlock = root[schoolId] || {};
        for (const adminId of Object.keys(schoolBlock)) {
            const a = schoolBlock[adminId] || {};
            const credPw = (a && a.Credentials && a.Credentials.Password) || '';
            rtdbAdmins.push({
                schoolId: schoolId,
                adminId: adminId,
                role: (a && a.Role) || '',
                status: (a && a.Status) || '',
                emailFromRecord: extractEmail(a),
                hashInfo: hashFormat(credPw),
            });
        }
    }
    console.log('  Total RTDB admin records (school-admins) : ' + rtdbAdmins.length);

    // ── Step 2: Firebase Auth full enumeration ─────────────────────────────
    console.log();
    console.log('Step 2: Enumerating Firebase Auth users (paginated listUsers)...');
    const fbByEmail = new Map();
    const fbByUid   = new Map();
    let nextPage = undefined;
    let page = 0;
    while (true) {
        const res = await auth.listUsers(1000, nextPage);
        page++;
        for (const u of res.users) {
            const rec = {
                uid: u.uid,
                email: u.email || '',
                disabled: u.disabled,
                providerData: (u.providerData || []).map(p => p.providerId || ''),
            };
            if (u.email) fbByEmail.set(u.email.toLowerCase(), rec);
            fbByUid.set(u.uid, rec);
        }
        nextPage = res.pageToken;
        if (!nextPage) break;
    }
    console.log('  Firebase Auth users (total) : ' + fbByUid.size + ' (across ' + page + ' page(s))');
    let domainCt = 0;
    for (const [e] of fbByEmail) {
        if (e.endsWith('@' + SYNTHETIC_DOMAIN)) domainCt++;
    }
    console.log('  Firebase Auth users with @' + SYNTHETIC_DOMAIN + ' email : ' + domainCt);

    // ── Step 3: Firestore staff collection ─────────────────────────────────
    console.log();
    console.log('Step 3: Reading Firestore staff collection...');
    const staffSnap = await fsdb.collection('staff').get();
    const staffByDocId = new Map();
    staffSnap.forEach(doc => { staffByDocId.set(doc.id, doc.data() || {}); });
    console.log('  Firestore staff documents : ' + staffByDocId.size);

    // ── Step 4: Firestore schools (canonical school list, informational) ───
    console.log();
    console.log('Step 4: Reading Firestore schools (canonical school list)...');
    const schoolsSnap = await fsdb.collection('schools').get();
    const firestoreSchools = new Set();
    schoolsSnap.forEach(doc => { firestoreSchools.add(doc.id); });
    console.log('  Firestore schools : ' + firestoreSchools.size);

    // ── Step 5: Cross-reference per admin (multi-strategy Firebase lookup) ──
    console.log();
    console.log('═'.repeat(72));
    console.log(' CROSS-REFERENCE ANALYSIS (multi-strategy email lookup)');
    console.log('═'.repeat(72));

    const buckets = {
        in_all_three: [],
        in_rtdb_and_fbauth_only: [],
        in_rtdb_and_fs_only: [],
        in_rtdb_only: [],
        in_fbauth_only: [],
        in_fs_only: [],
    };
    let bcryptCt = 0, nonBcryptCt = 0, absentCt = 0;
    let fbMatchedByRecordEmail = 0;
    let fbMatchedBySyntheticEmail = 0;
    let fbMatchedByNeither = 0;

    const rtdbAdminRecordEmails = new Set();
    const rtdbAdminSyntheticEmails = new Set();
    const rtdbAdminDocIds = new Set();

    for (const a of rtdbAdmins) {
        const synth  = syntheticEmail(a.adminId);
        const fromRec = a.emailFromRecord;
        rtdbAdminSyntheticEmails.add(synth);
        if (fromRec) rtdbAdminRecordEmails.add(fromRec);
        const docId = a.schoolId + '_' + a.adminId;
        rtdbAdminDocIds.add(docId);

        // Try multiple Firebase Auth lookup strategies:
        let fb = null;
        let fbMatchStrategy = 'none';
        if (fromRec && fbByEmail.has(fromRec)) {
            fb = fbByEmail.get(fromRec);
            fbMatchStrategy = 'record_email';
            fbMatchedByRecordEmail++;
        } else if (fbByEmail.has(synth)) {
            fb = fbByEmail.get(synth);
            fbMatchStrategy = 'synthetic_email';
            fbMatchedBySyntheticEmail++;
        } else {
            fbMatchedByNeither++;
        }

        const inFbAuth = !!fb;
        const inFs     = staffByDocId.has(docId);

        let cat;
        if (inFbAuth && inFs)            cat = 'in_all_three';
        else if (inFbAuth && !inFs)      cat = 'in_rtdb_and_fbauth_only';
        else if (!inFbAuth && inFs)      cat = 'in_rtdb_and_fs_only';
        else                              cat = 'in_rtdb_only';

        if (a.hashInfo.bcrypt) bcryptCt++;
        else if (a.hashInfo.present) nonBcryptCt++;
        else absentCt++;

        buckets[cat].push({
            schoolId: a.schoolId,
            adminId: a.adminId,
            role: a.role,
            status: a.status,
            emailFromRecord: fromRec,
            emailSynthetic: synth,
            fbMatchStrategy: fbMatchStrategy,
            fbUid: fb ? fb.uid : '',
            fbDisabled: fb ? fb.disabled : null,
            hashPresent: a.hashInfo.present,
            hashLength: a.hashInfo.length,
            hashPrefix: a.hashInfo.prefix,
            bcrypt: a.hashInfo.bcrypt,
        });
    }

    // Orphans in Firebase Auth: emails matching NEITHER a record-email NOR a synthetic email
    // (and skipping SUP* SA accounts)
    for (const [email, u] of fbByEmail) {
        if (rtdbAdminRecordEmails.has(email))    continue;
        if (rtdbAdminSyntheticEmails.has(email)) continue;
        const local = email.split('@')[0];
        if (/^sup\d/i.test(local)) continue;
        buckets.in_fbauth_only.push({
            email,
            uid: u.uid,
            disabled: u.disabled,
            providerData: u.providerData,
        });
    }
    // Orphans in Firestore staff
    for (const [docId, data] of staffByDocId) {
        if (rtdbAdminDocIds.has(docId)) continue;
        buckets.in_fs_only.push({
            docId,
            schoolId: data.schoolId || (docId.split('_')[0] || ''),
            role: data.role || '',
            status: data.status || '',
        });
    }

    // ── Step 6: Summary ────────────────────────────────────────────────────
    console.log();
    console.log('CROSS-SYSTEM COUNTS:');
    console.log('  In all 3 stores (RTDB + Firebase Auth + Firestore staff) : ' + buckets.in_all_three.length);
    console.log('  RTDB + Firebase Auth (no Firestore staff)                : ' + buckets.in_rtdb_and_fbauth_only.length);
    console.log('  RTDB + Firestore (NO Firebase Auth — needs provisioning) : ' + buckets.in_rtdb_and_fs_only.length);
    console.log('  RTDB ONLY (no Firebase Auth, no Firestore staff)         : ' + buckets.in_rtdb_only.length);
    console.log('  Firebase Auth ONLY (no RTDB) — orphan                    : ' + buckets.in_fbauth_only.length);
    console.log('  Firestore ONLY (no RTDB) — orphan                        : ' + buckets.in_fs_only.length);

    console.log();
    console.log('FIREBASE AUTH MATCH STRATEGY (per RTDB admin):');
    console.log('  Matched by record\'s Email field   : ' + fbMatchedByRecordEmail);
    console.log('  Matched by synthetic {id}@' + SYNTHETIC_DOMAIN.padEnd(13) + ' : ' + fbMatchedBySyntheticEmail);
    console.log('  Not matched (no Firebase Auth user) : ' + fbMatchedByNeither);

    console.log();
    console.log('PASSWORD HASH CLASSIFICATION (Option A bcrypt-import viability):');
    console.log('  bcrypt ($2a/$2b/$2x/$2y, len=60)   : ' + bcryptCt);
    console.log('  Other format (non-bcrypt)          : ' + nonBcryptCt);
    console.log('  Absent (no Credentials.Password)   : ' + absentCt);

    // Per-school breakdown
    const perSchool = {};
    for (const a of rtdbAdmins) {
        perSchool[a.schoolId] = (perSchool[a.schoolId] || 0) + 1;
    }
    console.log();
    console.log('PER-SCHOOL ADMIN COUNT (from RTDB):');
    const sortedSchools = Object.keys(perSchool).sort();
    for (const sid of sortedSchools) {
        const inFsList = firestoreSchools.has(sid) ? '(in Firestore schools)' : '(MISSING from Firestore schools)';
        console.log('  ' + sid + ' : ' + perSchool[sid] + ' admin(s) ' + inFsList);
    }

    // Provisioning-needed list
    const needsProvisioning = rtdbAdmins.filter(a => {
        const synth  = syntheticEmail(a.adminId);
        const fromRec = a.emailFromRecord;
        return !(fromRec && fbByEmail.has(fromRec)) && !fbByEmail.has(synth);
    });
    console.log();
    console.log('ADMINS NEEDING FIREBASE AUTH PROVISIONING: ' + needsProvisioning.length);
    if (needsProvisioning.length > 0) {
        const sampleLimit = 30;
        const sample = needsProvisioning.slice(0, sampleLimit);
        console.log('  ' + (needsProvisioning.length <= sampleLimit ? 'All:' : 'Sample (first ' + sampleLimit + '):'));
        for (const a of sample) {
            console.log('    - ' + a.schoolId + ' | ' + a.adminId
                + ' | role=' + (a.role || '?')
                + ' | status=' + (a.status || '?')
                + ' | recordEmail=' + (a.emailFromRecord || '(none)')
                + ' | bcrypt=' + a.hashInfo.bcrypt
                + ' | hashLen=' + (a.hashInfo.length || '-'));
        }
    }

    // Specifically surface SSA0001 if present (operator-stated canary)
    const ssa0001 = rtdbAdmins.filter(a => a.adminId === 'SSA0001');
    console.log();
    console.log('CANARY: SSA0001 lookup');
    if (ssa0001.length === 0) {
        console.log('  ⚠ SSA0001 NOT FOUND in RTDB Users/Admin/{schoolId}/* (operator named this as known credential)');
        console.log('    Possible: stored elsewhere OR under a school the script did not see.');
    } else {
        for (const a of ssa0001) {
            const synth  = syntheticEmail(a.adminId);
            const fromRec = a.emailFromRecord;
            const fbBySynth = fbByEmail.get(synth);
            const fbByRec   = fromRec ? fbByEmail.get(fromRec) : null;
            console.log('  SSA0001 in RTDB under school ' + a.schoolId);
            console.log('    role            : ' + (a.role || '?'));
            console.log('    status          : ' + (a.status || '?'));
            console.log('    record email    : ' + (fromRec || '(none)'));
            console.log('    synthetic email : ' + synth);
            console.log('    hash bcrypt     : ' + a.hashInfo.bcrypt + ' (len=' + a.hashInfo.length + ' prefix=' + a.hashInfo.prefix + ')');
            console.log('    Firebase Auth by record email   : ' + (fbByRec ? 'FOUND uid=' + fbByRec.uid + ' disabled=' + fbByRec.disabled : 'NOT FOUND'));
            console.log('    Firebase Auth by synthetic email: ' + (fbBySynth ? 'FOUND uid=' + fbBySynth.uid + ' disabled=' + fbBySynth.disabled : 'NOT FOUND'));
        }
    }

    // ── Step 7: Verdict ────────────────────────────────────────────────────
    console.log();
    console.log('═'.repeat(72));
    console.log(' MIGRATION VERDICT (data-driven; no assumptions)');
    console.log('═'.repeat(72));
    const totalAdmins = rtdbAdmins.length;
    const pctMigrated = totalAdmins > 0 ? Math.round(((buckets.in_all_three.length + buckets.in_rtdb_and_fbauth_only.length) / totalAdmins) * 100) : 0;
    const pctBcrypt   = totalAdmins > 0 ? Math.round((bcryptCt / totalAdmins) * 100) : 0;

    console.log(' Total RTDB school-admin records           : ' + totalAdmins);
    console.log(' Already in Firebase Auth                  : ' + (buckets.in_all_three.length + buckets.in_rtdb_and_fbauth_only.length) + ' (' + pctMigrated + '%)');
    console.log(' Needs provisioning                        : ' + needsProvisioning.length);
    console.log(' Of needs-provisioning, bcrypt-importable  : ' + needsProvisioning.filter(a => a.hashInfo.bcrypt).length);
    console.log(' Of needs-provisioning, force-reset needed : ' + needsProvisioning.filter(a => !a.hashInfo.bcrypt).length);
    console.log();

    if (totalAdmins === 0) {
        console.log(' ⚠ NO RTDB SCHOOL-ADMIN RECORDS FOUND. Investigate RTDB structure / location.');
    } else if (pctMigrated === 100) {
        console.log(' ✅ All admins already in Firebase Auth. Wave C provisioning step may be a NO-OP.');
        console.log('    Verify by canary-signin (separate step) before declaring done.');
    } else if (needsProvisioning.length === 0) {
        console.log(' ✅ All admins matched in Firebase Auth via at least one email strategy.');
    } else if (pctBcrypt >= 80) {
        console.log(' ✅ VERDICT: Option A (bcrypt import) viable for majority. Option C fallback for ' + needsProvisioning.filter(a => !a.hashInfo.bcrypt).length + ' edge case(s).');
    } else {
        console.log(' ⚠ VERDICT: Most admins lack bcrypt-recoverable hash. Investigate hash sources OR shift to Option B (force reset).');
    }

    // ── Step 8: Save snapshot ──────────────────────────────────────────────
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    const outDir = path.resolve(__dirname, 'wave_c0_snapshots');
    if (!fs.existsSync(outDir)) fs.mkdirSync(outDir, { recursive: true });
    const outFile = path.join(outDir, 'inventory_' + ts + '.json');

    const snapshot = {
        timestamp: ts,
        rtdb_url: DB_URL,
        synthetic_email_domain: SYNTHETIC_DOMAIN,
        scope: 'school-admins only (SAs at Users/Admin/Our Panel are excluded)',
        counts: {
            total_rtdb_admins: totalAdmins,
            total_firestore_schools: firestoreSchools.size,
            total_firebase_auth: fbByUid.size,
            total_firebase_auth_with_domain: domainCt,
            total_firestore_staff: staffByDocId.size,
            in_all_three: buckets.in_all_three.length,
            in_rtdb_and_fbauth_only: buckets.in_rtdb_and_fbauth_only.length,
            in_rtdb_and_fs_only: buckets.in_rtdb_and_fs_only.length,
            in_rtdb_only: buckets.in_rtdb_only.length,
            in_fbauth_only: buckets.in_fbauth_only.length,
            in_fs_only: buckets.in_fs_only.length,
            bcrypt: bcryptCt,
            non_bcrypt: nonBcryptCt,
            hash_absent: absentCt,
            fb_matched_by_record_email: fbMatchedByRecordEmail,
            fb_matched_by_synthetic_email: fbMatchedBySyntheticEmail,
            fb_matched_by_neither: fbMatchedByNeither,
            already_migrated_pct: pctMigrated,
        },
        per_school: perSchool,
        admins_needing_provisioning: needsProvisioning.map(a => ({
            schoolId: a.schoolId,
            adminId: a.adminId,
            role: a.role,
            status: a.status,
            emailFromRecord: a.emailFromRecord,
            emailSynthetic: syntheticEmail(a.adminId),
            bcrypt: a.hashInfo.bcrypt,
            hashLength: a.hashInfo.length,
            hashPrefix: a.hashInfo.prefix,
        })),
        buckets: {
            in_all_three: buckets.in_all_three,
            in_rtdb_and_fbauth_only: buckets.in_rtdb_and_fbauth_only,
            in_rtdb_and_fs_only: buckets.in_rtdb_and_fs_only,
            in_rtdb_only: buckets.in_rtdb_only,
            in_fbauth_only: buckets.in_fbauth_only,
            in_fs_only: buckets.in_fs_only,
        },
    };
    fs.writeFileSync(outFile, JSON.stringify(snapshot, null, 2));
    console.log();
    console.log(' Local snapshot dir : ' + outDir);
    console.log(' Snapshot file      : ' + path.basename(outFile));
    console.log(' VFY-Admin-Inventory verdict : ' + (totalAdmins > 0 ? 'OK (admins found)' : 'EMPTY'));
    console.log();
    process.exit(0);
})().catch(err => {
    console.error('admin_inventory_c0.js failed:', err && err.stack ? err.stack : err);
    process.exit(1);
});
