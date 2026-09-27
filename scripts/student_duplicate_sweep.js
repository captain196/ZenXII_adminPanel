/**
 * DEDUPE-SWEEP-1 (2026-09-20) — find duplicate STUDENT records across a roster.
 *
 * READ-ONLY. Writes nothing, ever. It reports; a human decides which copy is
 * real, and `php index.php student_dedupe retire <school> <ids> --commit`
 * retires the rest.
 *
 * ── WHY IT IS NEEDED ─────────────────────────────────────────────────────
 * Direct admission had no person-level duplicate check until ADM-DUP-1 — the
 * only guard that called itself one tested whether the ID GENERATOR had
 * collided. Anything admitted before that fix is unchecked. Two triples were
 * found by accident (they surfaced in the SR backfill); nobody has swept.
 *
 * Each duplicate holds a seat in section capacity, counts once in the roll and
 * in a UDISE+ return, and takes a permanent SR number.
 *
 * ── THE RULES ────────────────────────────────────────────────────────────
 * Reported separately, strongest first, because they carry different weight:
 *
 *   IDENTITY  name + date of birth + father's name
 *             The strongest signal available without documents. Two children
 *             sharing all three are almost never two children.
 *
 *   CONTACT   name + phone — the rule ADM-DUP-1 enforces at admission.
 *
 *   WEAK      name + class + section, with no DOB to corroborate.
 *             Reported but NOT treated as a finding: common names collide, and
 *             a sweep that cries wolf gets ignored.
 *
 * Siblings are safe from all of these: they share a phone and a father, never a
 * name. That is exactly why phone alone is never sufficient (P14-1: indexPhones
 * deliberately holds several students per number).
 *
 * ── WHAT IT SHOWS PER GROUP ──────────────────────────────────────────────
 * Dependants (fee demands, attendance) and SR numbers, because those decide
 * which copy is real. A record with money or marks against it is the one to
 * KEEP; a record already holding an SR number should usually be kept too, since
 * unwinding a register number is worse than retiring an unnumbered twin.
 *
 * USAGE
 *   node scripts/student_duplicate_sweep.js --school=SCH_B56BB9A401
 *   node scripts/student_duplicate_sweep.js                # every tenant
 */
const fs = require('fs');
const ROOT = 'C:/xampp/htdocs/ZenX/school';
const admin = require(ROOT + '/firebase-rules/tests/node_modules/firebase-admin');
const SVC = JSON.parse(fs.readFileSync(
    ROOT + '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json', 'utf8'));
if (!admin.apps.length) admin.initializeApp({ credential: admin.credential.cert(SVC) });
const db = admin.firestore();

const schoolArg = (process.argv.find(a => a.startsWith('--school=')) || '').split('=')[1] || '';

const norm = s => String(s == null ? '' : s).toLowerCase().replace(/\s+/g, ' ').trim();
const phone10 = raw => {
    let d = String(raw == null ? '' : raw).replace(/\D+/g, '');
    if (!d) return '';
    if (d.length > 10) d = d.slice(-10);
    return /^[6-9]\d{9}$/.test(d) ? d : '';
};

(async () => {
    let ref = db.collection('students');
    if (schoolArg) ref = ref.where('schoolId', '==', schoolArg);
    const snap = await ref.get();

    const rows = [];
    snap.forEach(doc => {
        const v = doc.data() || {};
        const status = String(v.status || v.Status || 'Active');
        // Only ACTIVE records can be duplicates in any way that costs anything —
        // a withdrawn or retired row is history, and flagging it would bury the
        // findings that matter.
        if (!/^active$/i.test(status)) return;

        rows.push({
            school:  String(v.schoolId || ''),
            id:      String(v.studentId || doc.id),
            name:    String(v.name || v.Name || ''),
            dob:     String(v.dob || v.DOB || ''),
            father:  String(v.fatherName || v['Father Name'] || ''),
            cls:     String(v.className || v.Class || ''),
            sec:     String(v.section || v.Section || ''),
            phone:   phone10(v.phone || v['Phone Number'] || ''),
            srNo:    String(v.srNo || ''),
        });
    });

    const group = keyFn => {
        const m = new Map();
        rows.forEach(r => {
            const k = keyFn(r);
            if (k === null) return;
            if (!m.has(k)) m.set(k, []);
            m.get(k).push(r);
        });
        return [...m.values()].filter(g => g.length > 1);
    };

    const identity = group(r =>
        (r.name && r.dob && r.father) ? `${r.school}|${norm(r.name)}|${r.dob}|${norm(r.father)}` : null);

    const contact = group(r =>
        (r.name && r.phone) ? `${r.school}|${norm(r.name)}|${r.phone}` : null);

    const weak = group(r =>
        (r.name && !r.dob) ? `${r.school}|${norm(r.name)}|${norm(r.cls)}|${norm(r.sec)}` : null);

    // A group already reported under a stronger rule is not repeated.
    const seen = new Set();
    const mark = g => g.forEach(r => seen.add(r.school + '|' + r.id));
    const fresh = groups => groups.filter(g => !g.every(r => seen.has(r.school + '|' + r.id)));

    const deps = async r => {
        const [d, a] = await Promise.all([
            db.collection('feeDemands').where('schoolId', '==', r.school)
              .where('studentId', '==', r.id).get().catch(() => ({ size: 0 })),
            db.collection('attendanceSummary').where('schoolId', '==', r.school)
              .where('studentId', '==', r.id).get().catch(() => ({ size: 0 })),
        ]);
        return { demands: d.size || 0, attendance: a.size || 0 };
    };

    const report = async (title, groups, actionable) => {
        console.log(`\n══ ${title} — ${groups.length} group(s) ══`);
        if (!groups.length) { console.log('   none'); return; }

        for (const g of groups) {
            console.log(`\n   ${g[0].name}  |  ${g[0].cls} ${g[0].sec}` +
                        (g[0].dob ? `  |  dob ${g[0].dob}` : '  |  (no dob)'));
            for (const r of g) {
                const d = await deps(r);
                const keepHint = (d.demands || d.attendance) ? '  <- HAS HISTORY, keep this one'
                               : (r.srNo ? '  <- holds SR ' + r.srNo : '');
                console.log(`      ${r.id.padEnd(9)} phone=${(r.phone || '—').padEnd(11)}` +
                            ` sr=${(r.srNo || '—').padEnd(4)}` +
                            ` demands=${d.demands} attendance=${d.attendance}${keepHint}`);
            }
            if (actionable) {
                const ids = g.slice(1).map(r => r.id).join(' ');
                console.log(`      retire the copies:  php index.php student_dedupe retire ${g[0].school} ${ids} --commit`);
                console.log('      (verify WHICH to keep first — the command above assumes the first listed is real)');
            }
        }
        mark(groups.flat());
    };

    console.log('DUPLICATE SWEEP (read-only)');
    console.log('active students scanned:', rows.length, schoolArg ? `(tenant ${schoolArg})` : '(all tenants)');

    await report('IDENTITY  name + dob + father', identity, true);
    await report('CONTACT   name + phone', fresh(contact), true);
    await report('WEAK      name + class + section, no dob (REVIEW ONLY, not a finding)', fresh(weak), false);

    console.log('\nNothing was written. Decide which copy is real before retiring anything:');
    console.log('a record carrying fee demands or attendance is the one to KEEP.');
    process.exit(0);
})().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
