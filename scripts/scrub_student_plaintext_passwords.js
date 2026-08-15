#!/usr/bin/env node
/**
 * Scrub legacy plaintext passwords from students/{schoolId}_{studentId}.
 *
 * WHY
 * `firestore.rules` allows `read: if isSameSchool()` on the students collection,
 * and isSameSchool() carries NO role restriction — it admits any authenticated
 * user whose school_id claim matches, which includes every student/parent. So a
 * parent using the Parent app can read every other student's document in their
 * school, and 143 of those documents still carry a `Password` field in clear text.
 *
 * SCOPE — this is legacy residue, not an active leak:
 *   - Entity_firestore_sync::syncStudent (the write path for every creation
 *     route) does NOT map Password, so nothing writes it any more. Students
 *     created recently — STU0160/0161/0162/0163 — have no such field.
 *   - 123 of the values are 4 characters. Firebase Auth enforces a 6-character
 *     minimum, so those cannot be valid credentials; they predate Firebase Auth.
 *   - ~20 are 8 characters, matching the old "Ayu1503@" generator, and could
 *     still be live for a student who has never changed their password.
 *
 * The field is removed rather than hashed: nothing reads it as a credential.
 * repair_student_auth() prefers it when present but already falls back to
 * generate_temp_password(), which now produces a random value and is surfaced to
 * the operator through the enrollment credentials panel — so the repair flow is
 * strictly better off without a stale password to reuse.
 *
 * Mirrors the treatment `staff` and `admins` already received (both are at 0).
 *
 * Usage:
 *   node scripts/scrub_student_plaintext_passwords.js                  # dry run
 *   node scripts/scrub_student_plaintext_passwords.js --csv            # dry run + per-doc CSV
 *   MCP_BACKFILL_CONFIRM=YES_I_AUTHORIZE \
 *     node scripts/scrub_student_plaintext_passwords.js apply          # delete the field
 */
const path = require('path');
const fs   = require('fs');

let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }

const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..',
    'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const db = admin.firestore();

const MODE   = (process.argv[2] || 'dry_run').toLowerCase() === 'apply' ? 'apply' : 'dry_run';
const AS_CSV = process.argv.includes('--csv');

if (MODE === 'apply' && process.env.MCP_BACKFILL_CONFIRM !== 'YES_I_AUTHORIZE') {
    console.error('REFUSING apply: set MCP_BACKFILL_CONFIRM=YES_I_AUTHORIZE to confirm.');
    process.exit(2);
}

(async () => {
    const snap = await db.collection('students').get();

    const targets = [];
    snap.forEach(d => {
        const s = d.data();
        const hasUpper = Object.prototype.hasOwnProperty.call(s, 'Password');
        const hasLower = Object.prototype.hasOwnProperty.call(s, 'password');
        if (!hasUpper && !hasLower) return;
        const v = hasUpper ? s.Password : s.password;
        if (v === null || v === undefined || v === '') return;
        targets.push({
            id: d.id,
            ref: d.ref,
            field: hasUpper ? 'Password' : 'password',
            len: String(v).length,
            school: s.schoolId || '',
            name: s.name || s.Name || '',
        });
    });

    if (AS_CSV) {
        console.log('docId,school,field,length,name');
        targets.forEach(t => console.log([t.id, t.school, t.field, t.len, JSON.stringify(t.name || '')].join(',')));
        console.log('');
    }

    const byLen = targets.reduce((m, t) => { m[t.len] = (m[t.len] || 0) + 1; return m; }, {});
    const bySchool = targets.reduce((m, t) => { m[t.school] = (m[t.school] || 0) + 1; return m; }, {});

    console.log('\n══ STUDENT PLAINTEXT PASSWORD SCRUB ═══════════════════════════');
    console.log(`mode              : ${MODE}`);
    console.log(`students scanned  : ${snap.size}`);
    console.log(`carrying a value  : ${targets.length}`);
    console.log(`length histogram  : ${JSON.stringify(byLen)}   (<6 cannot be a valid Firebase password)`);
    console.log('per school        :');
    Object.entries(bySchool).sort((a, b) => b[1] - a[1])
        .forEach(([k, v]) => console.log(`   ${(k || '(none)').padEnd(20)} ${v}`));

    if (MODE !== 'apply') {
        console.log('\nDry run — nothing written. Re-run with `apply` + the confirm env to delete.');
        console.log('Each write removes ONLY the password field; no other field is touched.\n');
        return;
    }

    // Batched field deletes — 400 per batch, well inside Firestore's 500 limit.
    let done = 0;
    for (let i = 0; i < targets.length; i += 400) {
        const chunk = targets.slice(i, i + 400);
        const batch = db.batch();
        for (const t of chunk) {
            batch.update(t.ref, {
                [t.field]: admin.firestore.FieldValue.delete(),
                updatedAt: new Date().toISOString(),
            });
        }
        await batch.commit();
        done += chunk.length;
        console.log(`  committed ${done}/${targets.length}`);
    }
    console.log(`\nDone — removed the password field from ${done} student document(s).\n`);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
