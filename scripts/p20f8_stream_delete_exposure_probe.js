/**
 * P20-F8 — STREAM DELETE EXPOSURE PROBE  (Phase 20.1b, Part 3)
 *
 * ══════════════════════════════════════════════════════════════════════════
 * STRICTLY READ-ONLY. Contains NO .set(), .update(), .delete(), .add(),
 * .create(), no batch, no transaction, no --commit/--apply flag.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * PURPOSE
 * School_config::delete_stream() blocks deletion using three dependency
 * checks (School_config.php:3079-3099):
 *
 *   sections            where streamKey == K  and session == S
 *   subjectAssignments  where stream    == K  and session == S
 *   students            where streamKey == K  and status  == 'Active'
 *
 * Source tracing for Phase 20.1b found NO writer anywhere in the application
 * that stamps `streamKey` onto a student document, and none that stamps it
 * onto a section document either (Firestore_service::saveSection does not,
 * Academic.php:847 does not, Classes.php:340/595 do not). Meanwhile `stream`
 * IS written to the `subjects` collection (School_config.php:2388) — which
 * the guard does not check at all.
 *
 * This probe establishes, from data rather than inference:
 *   - whether any student carries stream membership in ANY field shape
 *   - whether any section carries streamKey
 *   - which collections actually reference a stream
 *   - whether the delete guard can ever return non-zero
 *
 * USAGE
 *   node scripts/p20f8_stream_delete_exposure_probe.js [SCHOOL_ID] [SESSION]
 *   defaults: SCH_B56BB9A401  2026-27
 */

const fs = require('fs');
const ROOT = 'C:/xampp/htdocs/ZenX/school';
const admin = require(ROOT + '/firebase-rules/tests/node_modules/firebase-admin');
const SVC = JSON.parse(fs.readFileSync(
    ROOT + '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json', 'utf8'));

if (!admin.apps.length) admin.initializeApp({ credential: admin.credential.cert(SVC) });
const db = admin.firestore();

const TENANT  = process.argv[2] || 'SCH_B56BB9A401';
const SESSION = process.argv[3] || '2026-27';

const has = (o, k) => o[k] !== undefined && o[k] !== null && String(o[k]).trim() !== '';
const lc  = s => String(s == null ? '' : s).trim().toLowerCase();

(async () => {
    console.log('═'.repeat(78));
    console.log(' P20-F8 STREAM DELETE EXPOSURE PROBE  —  READ ONLY');
    console.log(' tenant=' + TENANT + '  session=' + SESSION);
    console.log('═'.repeat(78) + '\n');

    // ── [1] stream definitions live on the school document ──────────────
    const schoolDoc = await db.collection('schools').doc(TENANT).get();
    const school    = schoolDoc.exists ? (schoolDoc.data() || {}) : {};
    const streams   = school.streams || {};
    const streamKeys = Object.keys(streams);

    console.log('[1] STREAM DEFINITIONS (schools/' + TENANT + '.streams)');
    console.log('    school doc exists : ' + schoolDoc.exists);
    console.log('    streams defined   : ' + streamKeys.length);
    streamKeys.forEach(k => {
        const s = streams[k] || {};
        console.log('      - ' + k + '  label=' + (s.label || '?') + '  enabled=' + s.enabled);
    });
    console.log('');

    // ── [2] students — every plausible stream field shape ───────────────
    const stuSnap = await db.collection('students').where('schoolId', '==', TENANT).get();
    const students = [];
    stuSnap.forEach(d => students.push({ __id: d.id, ...(d.data() || {}) }));

    const SHAPES = ['streamKey', 'stream', 'Stream', 'streamName', 'StreamKey', 'stream_key'];
    console.log('[2] STUDENTS: ' + students.length);
    let anyStreamField = 0;
    SHAPES.forEach(f => {
        const n = students.filter(d => has(d, f)).length;
        if (n > 0) anyStreamField += n;
        console.log('    with `' + f + '`'.padEnd(14) + ' : ' + n);
    });
    console.log('    camelCase `status`      : ' + students.filter(d => has(d, 'status')).length);
    console.log('    PascalCase `Status`     : ' + students.filter(d => has(d, 'Status')).length);
    console.log('    ANY stream field at all : ' + anyStreamField);
    console.log('');

    // ── [3] sections — does streamKey exist there? ──────────────────────
    const secSnap = await db.collection('sections').where('schoolId', '==', TENANT).get();
    const sections = [];
    secSnap.forEach(d => sections.push({ __id: d.id, ...(d.data() || {}) }));
    const secWithStream = sections.filter(s => has(s, 'streamKey') || has(s, 'stream'));

    console.log('[3] SECTIONS: ' + sections.length);
    console.log('    with `streamKey`        : ' + sections.filter(s => has(s, 'streamKey')).length);
    console.log('    with `stream`           : ' + sections.filter(s => has(s, 'stream')).length);
    // Stream-named sections ("Science A") are the documented stream-aware form.
    const streamNamed = sections.filter(s => /^Section\s+[A-Za-z]+\s+[A-Z]$/.test(String(s.section || '')));
    console.log('    stream-NAMED sections   : ' + streamNamed.length
        + (streamNamed.length ? '  e.g. ' + streamNamed.slice(0, 3).map(s => s.section).join(', ') : ''));
    console.log('');

    // ── [4] other collections the guard checks / should check ───────────
    for (const [coll, field] of [['subjectAssignments', 'stream'], ['subjects', 'stream']]) {
        try {
            const snap = await db.collection(coll).where('schoolId', '==', TENANT).get();
            const rows = [];
            snap.forEach(d => rows.push(d.data() || {}));
            const withStream = rows.filter(r => has(r, field));
            console.log('[4] ' + coll.padEnd(20) + ' total=' + String(rows.length).padEnd(5)
                + ' with `' + field + '`=' + withStream.length
                + (coll === 'subjects' ? '   << NOT checked by delete_stream()' : '   (checked)'));
        } catch (e) {
            console.log('[4] ' + coll.padEnd(20) + ' query failed: ' + e.message);
        }
    }
    console.log('');

    // ── [5] simulate the guard per stream ───────────────────────────────
    console.log('[5] PER-STREAM: what the delete_stream() guard would see\n');
    console.log('    ' + 'STREAM'.padEnd(16) + 'SECT'.padStart(5) + 'SUBJA'.padStart(7)
        + 'STUD'.padStart(6) + '   VERDICT');
    console.log('    ' + '-'.repeat(70));

    let inertStudentCheck = 0;
    for (const k of streamKeys) {
        const secN = sections.filter(s => s.streamKey === k && (!s.session || s.session === SESSION)).length;
        const stuN = students.filter(s => s.streamKey === k && s.status === 'Active').length;
        let sjaN = 0;
        try {
            const snap = await db.collection('subjectAssignments')
                .where('schoolId', '==', TENANT).get();
            snap.forEach(d => {
                const r = d.data() || {};
                if (r.stream === k && (!r.session || r.session === SESSION)) sjaN++;
            });
        } catch (e) { sjaN = -1; }

        // Canonical membership: a student belongs to a stream only through a
        // stream-named section, since no student-level stream field is written.
        const canonical = students.filter(s => {
            if (lc(s.status || s.Status || 'Active') !== 'active') return false;
            const sec = String(s.section || s.Section || '');
            return new RegExp('^Section\\s+' + k + '\\s+[A-Z]$', 'i').test(sec);
        }).length;

        let verdict = 'guard would block';
        if (secN === 0 && sjaN <= 0 && stuN === 0) {
            verdict = canonical > 0
                ? '*** FALSELY EMPTY — ' + canonical + ' student(s) via section ***'
                : 'deletable (nothing references it)';
        }
        if (stuN === 0 && canonical > 0) inertStudentCheck++;

        console.log('    ' + k.padEnd(16) + String(secN).padStart(5) + String(sjaN).padStart(7)
            + String(stuN).padStart(6) + '   ' + verdict);
    }
    if (streamKeys.length === 0) console.log('    (no streams defined on this tenant)');

    // ── verdict ─────────────────────────────────────────────────────────
    console.log('\n' + '═'.repeat(78));
    console.log(' RESULT');
    console.log('═'.repeat(78));
    console.log('  streams defined                          : ' + streamKeys.length);
    console.log('  students carrying ANY stream field       : ' + anyStreamField);
    console.log('  sections carrying streamKey              : '
        + sections.filter(s => has(s, 'streamKey')).length);
    console.log('  streams whose student-check is INERT     : ' + inertStudentCheck);

    if (anyStreamField === 0) {
        console.log('\n  *** STRUCTURAL FINDING ***');
        console.log('  No student document carries ANY stream field. The `students` check in');
        console.log('  delete_stream() therefore ALWAYS returns 0 — it is inert, not merely');
        console.log('  single-shape. Fixing only status/Status would not change its result.');
    }
    console.log('\n  (read-only probe — nothing was modified)');
    process.exit(0);
})().catch(e => { console.error('PROBE FAILED: ' + e.message); process.exit(1); });
