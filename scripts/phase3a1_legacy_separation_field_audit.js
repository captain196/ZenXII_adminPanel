#!/usr/bin/env node
/**
 * Phase 3A.1 — legacy separation-metadata residual-exposure preverification.
 * READ-ONLY. No writes, no migration, no data modification of any kind.
 *
 * Scans the FULL staff/* collection (platform-wide, not one tenant) for any
 * of the four fields Phase 3A.1 moved into staffPrivate — exitReason,
 * deactivationReason, deactivatedBy, reactivatedBy — still present on the
 * public doc (i.e. records last written BEFORE the set_status() fix landed),
 * cross-references each against staffPrivate for conflicts, and answers the
 * 15 preverification questions. Produces a report only; touches nothing.
 */
const path = require('path');
const fs = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();

const SENSITIVE_FIELDS = ['exitReason', 'deactivationReason', 'deactivatedBy', 'reactivatedBy'];

(async function main() {
    console.log('='.repeat(78));
    console.log('  Phase 3A.1 — Legacy Separation-Metadata Residual Exposure Preverification');
    console.log('  READ-ONLY — no writes issued by this script');
    console.log('='.repeat(78));

    // Q1/Q2/Q3 — full platform scan, no tenant filter.
    const staffSnap = await fsdb.collection('staff').get();
    console.log(`\n[Scope] staff/* total docs on platform: ${staffSnap.size}`);

    const bySchool = {};       // schoolId -> count of AFFECTED records
    const affected = [];       // full detail per affected record
    let totalInactive = 0;
    let totalAffected = 0;

    for (const doc of staffSnap.docs) {
        const d = doc.data();
        const schoolId = d.schoolId || '(missing schoolId)';
        const status = d.status || d.Status || '';
        if (String(status).toLowerCase() === 'inactive') totalInactive++;

        // Q4/Q5 — which of the 4 fields are PRESENT, and which are non-empty.
        const presentFields = {};
        let anyNonEmpty = false;
        for (const f of SENSITIVE_FIELDS) {
            if (Object.prototype.hasOwnProperty.call(d, f)) {
                const v = d[f];
                const nonEmpty = v !== null && v !== undefined && String(v).trim() !== '';
                presentFields[f] = { present: true, nonEmpty, value: nonEmpty ? String(v) : '(empty)' };
                if (nonEmpty) anyNonEmpty = true;
            }
        }
        if (Object.keys(presentFields).length === 0) continue; // no residual fields at all — not affected

        totalAffected++;
        bySchool[schoolId] = (bySchool[schoolId] || 0) + 1;
        affected.push({ docId: doc.id, staffId: d.staffId || '(missing)', schoolId, status, presentFields, anyNonEmpty });
    }

    console.log(`\n[Q1] Total staff records with ANY residual sensitive field on the public doc: ${totalAffected}`);
    console.log(`[Q2] Breakdown by school:`);
    for (const [sid, count] of Object.entries(bySchool).sort((a, b) => b[1] - a[1])) {
        console.log(`     ${sid}: ${count}`);
    }
    console.log(`[Q3] Of ALL staff records (not just affected), currently status=='Inactive': ${totalInactive}`);

    // Q6/Q7/Q8 — cross-reference staffPrivate for each affected record.
    console.log(`\n[Q6/Q7/Q8] Cross-referencing staffPrivate for each of the ${affected.length} affected records...`);
    let privateDocExists = 0, privateDocMissing = 0, conflicting = 0;
    const conflictDetail = [];

    for (const rec of affected) {
        const privId = `${rec.schoolId}_${rec.staffId}`;
        let privDoc = null;
        try {
            const snap = await fsdb.collection('staffPrivate').doc(privId).get();
            if (snap.exists) { privDoc = snap.data(); privateDocExists++; } else { privateDocMissing++; }
        } catch (e) {
            console.log(`     ERROR reading staffPrivate/${privId}: ${e.message}`);
            continue;
        }
        if (!privDoc) continue;

        const conflicts = [];
        for (const f of SENSITIVE_FIELDS) {
            const pubVal = rec.presentFields[f] && rec.presentFields[f].nonEmpty ? rec.presentFields[f].value : null;
            const privVal = privDoc[f] !== undefined && privDoc[f] !== null && String(privDoc[f]).trim() !== '' ? String(privDoc[f]) : null;
            if (pubVal !== null && privVal !== null && pubVal !== privVal) {
                conflicts.push({ field: f, publicValue: pubVal, privateValue: privVal });
            }
        }
        if (conflicts.length > 0) {
            conflicting++;
            conflictDetail.push({ docId: rec.docId, staffId: rec.staffId, schoolId: rec.schoolId, conflicts });
        }
    }
    console.log(`     staffPrivate doc already exists for: ${privateDocExists} of ${affected.length}`);
    console.log(`     staffPrivate doc MISSING for:        ${privateDocMissing} of ${affected.length}`);
    console.log(`     Records with CONFLICTING values between staff (public) and staffPrivate: ${conflicting}`);
    if (conflictDetail.length > 0) {
        console.log(`\n     Conflict detail:`);
        for (const c of conflictDetail) {
            console.log(`       ${c.schoolId}/${c.staffId} (${c.docId}):`);
            for (const cf of c.conflicts) {
                console.log(`         ${cf.field}: public="${cf.publicValue}" vs private="${cf.privateValue}"`);
            }
        }
    }

    // Q9 — UAT/test-school heuristic (schoolId patterns already known from
    // this session's memory to denote probe/test tenants, e.g. SCH_202512).
    console.log(`\n[Q9] Potential UAT/test-school affected records (heuristic: schoolId contains 'TEST'/'PROBE'/'202512' or a known non-production pattern):`);
    const testLike = affected.filter(r => /TEST|PROBE|DEMO|202512/i.test(r.schoolId));
    if (testLike.length === 0) {
        console.log('     None matched the heuristic — does not prove absence, only that no obviously-test schoolId pattern was found.');
    } else {
        for (const r of testLike) console.log(`     ${r.schoolId} / ${r.staffId}`);
    }
    console.log(`     Full list of DISTINCT affected schoolIds (for manual cross-check against known tenants):`);
    for (const sid of Object.keys(bySchool)) console.log(`       ${sid}`);

    // Full per-record detail dump for audit trail.
    console.log(`\n[Detail] Full affected-record listing (schoolId / staffId / status / fields present):`);
    for (const r of affected) {
        const fieldList = Object.entries(r.presentFields)
            .map(([f, v]) => `${f}=${v.nonEmpty ? 'NON-EMPTY' : 'empty'}`).join(', ');
        console.log(`     ${r.schoolId} / ${r.staffId} / status=${r.status} / ${fieldList}`);
    }

    console.log('\n' + '='.repeat(78));
    console.log('  Scan complete. No data was modified.');
    console.log('='.repeat(78));
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
