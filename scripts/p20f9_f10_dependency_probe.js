/**
 * P20-F9 / P20-F10 — DEPENDENCY REALITY PROBE  (Phase 20.1c, Parts 2 & 5)
 *
 * ══════════════════════════════════════════════════════════════════════════
 * STRICTLY READ-ONLY. No .set(), .update(), .delete(), .add(), .create(),
 * no batch, no transaction, no --commit/--apply.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * PURPOSE — avoid repeating the Phase 20.1B mistake, where a field that
 * "looked like" a dependency (students.streamKey) turned out never to be
 * written at all. For every dependency field the delete guards rely on, this
 * probe reports whether real documents actually carry it, and with what
 * values.
 *
 * Covers:
 *   P20-F10 — what values does `subjects.stream` actually hold? Are they
 *             live stream keys, or a placeholder like "common"?
 *   P20-F9  — for delete_session()'s 9 dependency collections, does the
 *             `session` field exist on real documents?
 *
 * USAGE
 *   node scripts/p20f9_f10_dependency_probe.js [SCHOOL_ID] [SESSION]
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

(async () => {
    console.log('═'.repeat(78));
    console.log(' P20-F9 / P20-F10 DEPENDENCY REALITY PROBE  —  READ ONLY');
    console.log(' tenant=' + TENANT + '  session=' + SESSION);
    console.log('═'.repeat(78) + '\n');

    // ── P20-F10: what does subjects.stream actually contain? ────────────
    console.log('[A] P20-F10 — subjects.stream VALUE DISTRIBUTION');
    const subjSnap = await db.collection('subjects').where('schoolId', '==', TENANT).get();
    const subjects = [];
    subjSnap.forEach(d => subjects.push({ __id: d.id, ...(d.data() || {}) }));

    const dist = {};
    subjects.forEach(s => {
        const v = has(s, 'stream') ? String(s.stream).trim() : '(absent)';
        dist[v] = (dist[v] || 0) + 1;
    });
    console.log('    subjects total : ' + subjects.length);
    Object.keys(dist).sort().forEach(v =>
        console.log('      stream="' + v + '"' + ' '.repeat(Math.max(0, 18 - v.length)) + ': ' + dist[v]));

    const school = (await db.collection('schools').doc(TENANT).get()).data() || {};
    const liveStreams = Object.keys(school.streams || {});
    console.log('    live stream keys defined : ' + (liveStreams.length ? liveStreams.join(', ') : '(none)'));

    const referencing = Object.keys(dist).filter(v =>
        v !== '(absent)' && liveStreams.some(k => k.toLowerCase() === v.toLowerCase()));
    console.log('    values matching a LIVE stream : '
        + (referencing.length ? referencing.join(', ') : 'NONE'));
    if (subjects.length) {
        const sample = subjects[0];
        console.log('    sample doc shape : ' + JSON.stringify({
            classKey: sample.classKey, subjectCode: sample.subjectCode,
            name: sample.name, category: sample.category, stream: sample.stream,
        }));
    }
    console.log('');

    // ── P20-F9: does `session` exist on delete_session's 9 collections? ─
    console.log('[B] P20-F9 — delete_session() dependency collections\n');
    console.log('    ' + 'COLLECTION'.padEnd(22) + 'TOTAL'.padStart(7)
        + 'w/session'.padStart(11) + 'thisSess'.padStart(10) + '   FIELD REAL?');
    console.log('    ' + '-'.repeat(72));

    const COLS = ['students', 'sections', 'staff', 'attendance', 'marks',
                  'feeDemands', 'feeReceipts', 'timetables', 'subjectAssignments'];

    for (const c of COLS) {
        try {
            const snap = await db.collection(c).where('schoolId', '==', TENANT).limit(500).get();
            const rows = [];
            snap.forEach(d => rows.push(d.data() || {}));
            const withSess = rows.filter(r => has(r, 'session')).length;
            const thisSess = rows.filter(r => String(r.session || '') === SESSION).length;
            // NB: "absent on all sampled docs" does NOT prove the field is never
            // written — it may simply mean every sampled doc predates the
            // canonical shape. Always confirm against the writer before
            // concluding a check is inert (the P20-F8 lesson, applied in
            // reverse: attendance.session IS stamped by
            // Firestore_service::saveAttendance:1331, yet legacy docs lack it).
            const real = rows.length === 0
                ? 'no data'
                : (withSess > 0
                    ? (withSess === rows.length ? 'YES (all)' : 'PARTIAL — legacy docs lack it')
                    : 'absent on all sampled — CHECK WRITER');
            console.log('    ' + c.padEnd(22) + String(rows.length).padStart(7)
                + String(withSess).padStart(11) + String(thisSess).padStart(10) + '   ' + real);
        } catch (e) {
            console.log('    ' + c.padEnd(22) + '  query failed: ' + e.message);
        }
    }

    console.log('\n' + '═'.repeat(78));
    console.log(' NOTE: counts are capped at 500 docs/collection — they establish whether a');
    console.log(' field is WRITTEN AT ALL, not exhaustive totals.');
    console.log(' (read-only probe — nothing was modified)');
    process.exit(0);
})().catch(e => { console.error('PROBE FAILED: ' + e.message); process.exit(1); });
