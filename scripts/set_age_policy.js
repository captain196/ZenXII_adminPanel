/**
 * ADM-AGE-1 — activate the class-vs-age policy for Vikrant.
 *
 * MODE = warn, deliberately. A blocking policy on day one refuses the genuine
 * exceptions every school has — a child returning from abroad, a lateral entry,
 * an RTE age-appropriate placement that is deliberately off-band — and the front
 * office learns to route around the system. Warn shows the mismatch, records it,
 * and admits. Switch to block in Academic Setup once you have watched what it
 * flags for a few weeks.
 *
 * BANDS are the conventional Indian ladder with a 31-March cut-off: Class 1 at
 * 6+, each class one year on, and a +2 upper tolerance so a legitimately older
 * child is not flagged. They are a STARTING POINT chosen to match this school's
 * own class list — not a rule ZenX believes in. Education is a concurrent
 * subject: your state, board and cut-off decide the real numbers, and they are
 * editable in Academic Setup -> Classes -> Admission Age Policy.
 *
 * Writes the same document shape School_config::save_age_policy() writes, so the
 * editor loads, displays and re-saves it normally.
 */
const fs = require('fs');
const ROOT = 'C:/xampp/htdocs/ZenX/school';
const admin = require(ROOT + '/firebase-rules/tests/node_modules/firebase-admin');
const SVC = JSON.parse(fs.readFileSync(
    ROOT + '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json', 'utf8'));
if (!admin.apps.length) admin.initializeApp({ credential: admin.credential.cert(SVC) });
const db = admin.firestore();

const TENANT = 'SCH_B56BB9A401';
const COMMIT = process.argv.includes('--commit');

// min age as on the cut-off; max = min + 2 tolerance
const LADDER = {
    'Playgroup':  2,
    'Nursery':    3,
    'LKG':        4,
    'UKG':        5,
    'Class 1st':  6,
    'Class 2nd':  7,
    'Class 3rd':  8,
    'Class 4th':  9,
    'Class 5th':  10,
    'Class 6th':  11,
    'Class 7th':  12,
    'Class 8th':  13,
    'Class 9th':  14,
    'Class 10th': 15,
};

(async () => {
    const snap = await db.collection('schools').doc(TENANT).get();
    const school = snap.data() || {};
    const classes = (school.classes || []).filter(c => !c.deleted);

    const bands = {};
    const skipped = [];
    classes.forEach(c => {
        const label = String(c.label || c.name || '');
        // Band only what we can place on the ladder. A class we do not
        // recognise is left UNBANDED — exempt — rather than given a guessed
        // band that would flag every admission into it.
        if (LADDER[label] === undefined) { skipped.push(label); return; }
        bands[label] = { min: LADDER[label], max: LADDER[label] + 2 };
    });

    const policy = {
        mode:      'warn',
        cutoff:    '03-31',
        bands,
        updatedAt: new Date().toISOString(),
    };

    console.log(COMMIT ? '*** COMMIT — writing admissionAgePolicy ***'
                       : 'DRY RUN — add --commit to write.');
    console.log('tenant :', TENANT);
    console.log('mode   :', policy.mode, '  cut-off:', policy.cutoff, '(age as on 31 March)');
    console.log('bands  :', Object.keys(bands).length, 'classes');
    Object.entries(bands).forEach(([k, v]) => console.log(`   ${k.padEnd(12)} ${v.min}-${v.max}`));
    if (skipped.length) console.log('unbanded (exempt):', skipped.join(', '));

    if (!COMMIT) { console.log('\nNo writes performed.'); process.exit(0); }

    await db.collection('schools').doc(TENANT).update({
        admissionAgePolicy: policy,
        updatedAt: new Date().toISOString(),
    });

    const back = (await db.collection('schools').doc(TENANT).get()).data().admissionAgePolicy;
    console.log('\nread back — mode:', back.mode, ' cutoff:', back.cutoff,
                ' bands:', Object.keys(back.bands || {}).length);
    process.exit(0);
})().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
