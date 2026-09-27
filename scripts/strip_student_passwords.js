/**
 * CRED-PLAIN-1 — remove the plaintext `Password` field from student documents.
 *
 * WHY
 * ---
 * `Firestore_service::saveStudent()` whitelisted `Password`, so the enrol-time
 * credential was written to the student document in clear text. The rule on that
 * collection is `allow read: if isSameSchool()` — any authenticated principal
 * carrying the school's id, which includes every student and every parent. One
 * parent could list `students` and harvest the whole school's credentials.
 *
 * The writer is fixed. This clears what the old writer already left behind.
 *
 * SAFETY
 * ------
 *  - DRY RUN BY DEFAULT. Pass --commit to write.
 *  - Only ever DELETES two fields (`Password`, `Credentials`). It never creates,
 *    moves, disables or otherwise touches a student.
 *  - Deleting them logs nobody out: Firebase Auth holds the real credential, and
 *    these fields were never consulted at sign-in. The only reader was
 *    Sis::repair_auth, which now mints a fresh password instead.
 *  - --school=SCH_XXXX limits the sweep to one tenant. Omit to sweep the fleet.
 *
 * USAGE
 *   node scripts/strip_student_passwords.js                          # dry run, all tenants
 *   node scripts/strip_student_passwords.js --school=SCH_B56BB9A401  # dry run, one tenant
 *   node scripts/strip_student_passwords.js --school=SCH_B56BB9A401 --commit
 */
const fs = require('fs');
const ROOT = 'C:/xampp/htdocs/ZenX/school';
const admin = require(ROOT + '/firebase-rules/tests/node_modules/firebase-admin');
const SVC = JSON.parse(fs.readFileSync(
    ROOT + '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json', 'utf8'));
if (!admin.apps.length) admin.initializeApp({ credential: admin.credential.cert(SVC) });
const db = admin.firestore();

const COMMIT = process.argv.includes('--commit');
const schoolArg = (process.argv.find(a => a.startsWith('--school=')) || '').split('=')[1] || '';

(async () => {
    console.log(COMMIT ? '*** COMMIT MODE — fields will be deleted ***'
                       : 'DRY RUN — nothing will be written. Add --commit to apply.');
    if (schoolArg) console.log('Tenant filter: ' + schoolArg);

    let ref = db.collection('students');
    if (schoolArg) ref = ref.where('schoolId', '==', schoolArg);
    const snap = await ref.get();

    const affected = [];
    snap.forEach(doc => {
        const d = doc.data() || {};
        const hasPw = Object.prototype.hasOwnProperty.call(d, 'Password') && d.Password !== null;
        const hasCr = Object.prototype.hasOwnProperty.call(d, 'Credentials') && d.Credentials !== null;
        if (hasPw || hasCr) {
            affected.push({ id: doc.id, school: d.schoolId || '?', student: d.studentId || '?', hasPw, hasCr });
        }
    });

    console.log('students scanned : ' + snap.size);
    console.log('carrying a secret: ' + affected.length);
    // The VALUES are never printed — printing them here would move the leak into
    // a terminal buffer and a shell history.
    affected.slice(0, 20).forEach(a => console.log(
        '  ' + a.id + '  school=' + a.school + '  Password=' + a.hasPw + '  Credentials=' + a.hasCr));
    if (affected.length > 20) console.log('  … and ' + (affected.length - 20) + ' more');

    if (!COMMIT || !affected.length) { console.log('\nNo writes performed.'); process.exit(0); }

    // Batched so a fleet-wide sweep does not depend on one enormous commit.
    let done = 0;
    for (let i = 0; i < affected.length; i += 400) {
        const batch = db.batch();
        affected.slice(i, i + 400).forEach(a => batch.update(db.collection('students').doc(a.id), {
            Password:    admin.firestore.FieldValue.delete(),
            Credentials: admin.firestore.FieldValue.delete(),
        }));
        await batch.commit();
        done += Math.min(400, affected.length - i);
        console.log('  committed ' + done + '/' + affected.length);
    }
    console.log('\nDone. ' + done + ' student documents no longer carry a stored credential.');
    process.exit(0);
})().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
