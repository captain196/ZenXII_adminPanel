/**
 * P20-F1 — SECTION DELETE EXPOSURE PROBE  (Phase 20.1 / decision P20-D9)
 *
 * ══════════════════════════════════════════════════════════════════════════
 * STRICTLY READ-ONLY. This file contains NO write API call of any kind —
 * no .set(), .update(), .delete(), .add(), .create(), no batch, no
 * transaction. It cannot mutate data even if invoked with wrong arguments,
 * and it takes no --commit / --apply flag because it has nothing to commit.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * PURPOSE
 * Establish whether the P20-F1 defect has real exposure on the live tenant.
 *
 * The two section-delete guards (School_config::bulk_save_sections:1743 and
 * School_config::delete_section:1425) decide "is this section empty?" with:
 *
 *     schoolWhere('students', [['sectionKey','==',$k], ['status','==','Active']])
 *
 * A Firestore equality predicate binds to ONE exact field name. The canonical
 * roster rule (MY_Controller::_filterSectionRoster:1258) instead matches on
 * className/section, reads BOTH field shapes, and treats an ABSENT status as
 * Active. So the guard can see zero students where the canonical rule sees
 * many — and delete a section that still holds children.
 *
 * This probe runs BOTH rules over the same data and reports divergence.
 *
 * USAGE
 *   node scripts/p20f1_section_delete_exposure_probe.js [SCHOOL_ID] [SESSION]
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

/** first non-empty of the listed keys — mirrors the PHP `?? ` chains */
const pick = (o, ...keys) => {
    for (const k of keys) {
        if (o[k] !== undefined && o[k] !== null && String(o[k]).trim() !== '') return String(o[k]).trim();
    }
    return '';
};
const lc = s => String(s == null ? '' : s).trim().toLowerCase();

/**
 * Faithful port of MY_Controller::_filterSectionRoster() (:1258-1293).
 * Any divergence here would invalidate the probe, so the rules are
 * transcribed line-for-line:
 *   :1277  id from studentId | userId | 'User Id'
 *   :1280  status from status | Status, DEFAULTING TO 'Active' when absent
 *   :1281  non-blank non-Active is excluded (case-insensitive)
 *   :1283  className | Class
 *   :1284  section   | Section
 *   :1288  blank session = legacy document, KEPT
 */
function canonicalRoster(students, classKey, sectionKey, session) {
    if (!classKey || !sectionKey) return [];
    const classCmp = lc(classKey), sectionCmp = lc(sectionKey), sessionCmp = lc(session);
    return students.filter(d => {
        const sid = pick(d, 'studentId', 'userId', 'User Id');
        if (sid === '') return false;

        const status = pick(d, 'status', 'Status') || 'Active';
        if (status !== '' && status.toLowerCase() !== 'active') return false;

        if (lc(pick(d, 'className', 'Class')) !== classCmp) return false;
        if (lc(pick(d, 'section', 'Section')) !== sectionCmp) return false;

        const rowSession = lc(pick(d, 'session'));
        if (sessionCmp !== '' && rowSession !== '' && rowSession !== sessionCmp) return false;

        return true;
    });
}

/**
 * Faithful port of the VULNERABLE guard. Deliberately literal: exact
 * camelCase field names, exact 'Active' value, NO session filter — because
 * that is exactly what the Firestore predicate does.
 */
function vulnerableGuard(students, compositeSectionKey) {
    return students.filter(d =>
        d.sectionKey === compositeSectionKey && d.status === 'Active');
}

(async () => {
    console.log('═'.repeat(78));
    console.log(' P20-F1 SECTION DELETE EXPOSURE PROBE  —  READ ONLY');
    console.log(' tenant=' + TENANT + '  session=' + SESSION);
    console.log('═'.repeat(78) + '\n');

    // ── sections ────────────────────────────────────────────────────────
    const secSnap = await db.collection('sections').where('schoolId', '==', TENANT).get();
    const allSections = [];
    secSnap.forEach(doc => allSections.push({ id: doc.id, ...(doc.data() || {}) }));
    const sections = allSections.filter(s => !s.session || s.session === SESSION);

    console.log('[1] SECTIONS');
    console.log('    total for tenant (all sessions) : ' + allSections.length);
    console.log('    in session ' + SESSION + ' (+ blank)   : ' + sections.length);
    const noCap = sections.filter(s => !(Number(s.maxStrength || s.max_strength || 0) > 0));
    console.log('    with configured maxStrength > 0 : ' + (sections.length - noCap.length));
    console.log('    WITHOUT capacity (resolver → 40): ' + noCap.length + '\n');

    // ── students ────────────────────────────────────────────────────────
    const stuSnap = await db.collection('students').where('schoolId', '==', TENANT).get();
    const students = [];
    stuSnap.forEach(doc => students.push({ __id: doc.id, ...(doc.data() || {}) }));

    console.log('[2] STUDENTS (tenant-wide, all sessions): ' + students.length);

    const camelStatus  = students.filter(d => d.status  !== undefined && d.status  !== null && String(d.status).trim()  !== '');
    const pascalStatus = students.filter(d => d.Status  !== undefined && d.Status  !== null && String(d.Status).trim()  !== '');
    const bothStatus   = students.filter(d => d.status !== undefined && d.Status !== undefined);
    const noStatus     = students.filter(d =>
        (d.status === undefined || d.status === null || String(d.status).trim() === '') &&
        (d.Status === undefined || d.Status === null || String(d.Status).trim() === ''));
    const withKey      = students.filter(d => d.sectionKey !== undefined && String(d.sectionKey || '').trim() !== '');
    const withoutKey   = students.filter(d => d.sectionKey === undefined || String(d.sectionKey || '').trim() === '');
    const statusNotExactlyActive = students.filter(d =>
        lc(pick(d, 'status', 'Status') || 'Active') === 'active' && d.status !== 'Active');

    console.log('    camelCase  `status` present   : ' + camelStatus.length);
    console.log('    PascalCase `Status` present   : ' + pascalStatus.length);
    console.log('    BOTH shapes present           : ' + bothStatus.length);
    console.log('    NEITHER (absent → canonical treats as Active) : ' + noStatus.length);
    console.log('    has `sectionKey`              : ' + withKey.length);
    console.log('    MISSING `sectionKey`          : ' + withoutKey.length);
    console.log('    active-by-canonical but status !== exact "Active" : ' + statusNotExactlyActive.length + '\n');

    // ── per-section comparison ──────────────────────────────────────────
    console.log('[3] PER-SECTION: vulnerable guard vs canonical roster\n');
    console.log('    ' + 'SECTION'.padEnd(30) + 'VULN'.padStart(6) + 'CANON'.padStart(7) + '   VERDICT');
    console.log('    ' + '-'.repeat(72));

    let falselyEmpty = 0, overBlocked = 0, divergent = 0, withStudents = 0;
    const falselyEmptyDetail = [];

    for (const s of sections) {
        const className = pick(s, 'className');
        const section   = pick(s, 'section');
        if (!className || !section) continue;

        const composite = className + '/' + section;
        const vuln  = vulnerableGuard(students, composite).length;
        const canon = canonicalRoster(students, className, section, SESSION).length;

        if (canon > 0) withStudents++;

        let verdict = 'ok';
        if (vuln === 0 && canon > 0) {
            verdict = '*** FALSELY EMPTY — DELETABLE WITH ' + canon + ' STUDENT(S) ***';
            falselyEmpty++;
            falselyEmptyDetail.push({ composite, canon });
        } else if (vuln > canon) {
            verdict = 'over-blocks (+' + (vuln - canon) + ', likely other-session)';
            overBlocked++;
        } else if (vuln !== canon) {
            verdict = 'divergent';
            divergent++;
        }

        if (verdict !== 'ok') {
            console.log('    ' + composite.padEnd(30) + String(vuln).padStart(6) + String(canon).padStart(7) + '   ' + verdict);
        }
    }
    if (falselyEmpty === 0 && overBlocked === 0 && divergent === 0) {
        console.log('    (no divergence on any section)');
    }

    // ── verdict ─────────────────────────────────────────────────────────
    console.log('\n' + '═'.repeat(78));
    console.log(' RESULT');
    console.log('═'.repeat(78));
    console.log('  sections examined                     : ' + sections.length);
    console.log('  sections holding students (canonical) : ' + withStudents);
    console.log('  FALSELY EMPTY (data-loss exposure)    : ' + falselyEmpty);
    console.log('  over-blocked (cross-session)          : ' + overBlocked);
    console.log('  otherwise divergent                   : ' + divergent);

    if (falselyEmpty > 0) {
        console.log('\n  *** LIVE EXPOSURE CONFIRMED ***');
        falselyEmptyDetail.forEach(x =>
            console.log('      ' + x.composite + ' — ' + x.canon + ' student(s) at risk'));
    } else {
        console.log('\n  No section on this tenant is currently falsely-empty.');
        console.log('  The defect remains real in code; current DATA does not trigger it.');
    }
    console.log('\n  (read-only probe — nothing was modified)');
    process.exit(0);
})().catch(e => { console.error('PROBE FAILED: ' + e.message); process.exit(1); });
