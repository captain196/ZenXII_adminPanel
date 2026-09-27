/**
 * PHASE 20.3G — LIVE FIRESTORE CAS UAT
 *
 * Proves the load-bearing assumption of the atomic-capacity design against
 * REAL Firestore: that a stale `updateTime` precondition is rejected
 * definitively and distinguishably, and that exactly one of two competing
 * commits holding the same captured updateTime can win.
 *
 * This assumption has been carried unverified since Phase 20.3c. If it does
 * not hold, the whole Option-B design is invalid.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * TENANT: SCH_B56BB9A401 (Vikrant Public) — the authorized testing school.
 *
 * WRITE FOOTPRINT: this script writes ONE opaque field (`seatCasAt`, an ISO
 * timestamp) on ONE section document, and removes it again at the end.
 *   • touches NO student document
 *   • consumes NO SRNO, Student ID, Admission Number or APAAR value
 *   • creates NO fee demand
 *   • deletes nothing
 *   • never touches another school
 * Before/after snapshots of the section document are printed.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * USAGE
 *   node scripts/p203g_cas_live_uat.js            # dry run — reads only
 *   node scripts/p203g_cas_live_uat.js --commit   # performs the CAS writes
 */

const fs = require('fs');
const ROOT = 'C:/xampp/htdocs/ZenX/school';
const admin = require(ROOT + '/firebase-rules/tests/node_modules/firebase-admin');
const SVC = JSON.parse(fs.readFileSync(
    ROOT + '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json', 'utf8'));

if (!admin.apps.length) admin.initializeApp({ credential: admin.credential.cert(SVC) });
const db = admin.firestore();

const TENANT  = 'SCH_B56BB9A401';           // LOCKED — testing school
const SESSION = '2026-27';
const COMMIT  = process.argv.includes('--commit');

const line = (n = 78) => console.log('─'.repeat(n));

(async () => {
    console.log('═'.repeat(78));
    console.log(' PHASE 20.3G — LIVE FIRESTORE CAS UAT');
    console.log(' tenant=' + TENANT + '  session=' + SESSION + '  mode=' + (COMMIT ? 'COMMIT' : 'DRY RUN'));
    console.log('═'.repeat(78) + '\n');

    // ── Guard: never run against any other tenant ───────────────────────
    if (TENANT !== 'SCH_B56BB9A401') {
        console.error('ABORT: this script is locked to the testing school.');
        process.exit(1);
    }

    // ── Pick a section to exercise ──────────────────────────────────────
    const snap = await db.collection('sections').where('schoolId', '==', TENANT).limit(50).get();
    const secs = [];
    snap.forEach(d => secs.push({ id: d.id, ...(d.data() || {}) }));
    const target = secs.find(s => s.session === SESSION);
    if (!target) { console.error('ABORT: no section found for that session.'); process.exit(1); }

    console.log('[1] TARGET SECTION (before)');
    console.log('    docId       : ' + target.id);
    console.log('    className   : ' + target.className);
    console.log('    section     : ' + target.section);
    console.log('    session     : ' + target.session);
    console.log('    maxStrength : ' + (target.maxStrength !== undefined ? target.maxStrength : '(not set)'));
    console.log('    seatCasAt   : ' + (target.seatCasAt !== undefined ? target.seatCasAt : '(absent)'));
    const hadMarker = target.seatCasAt !== undefined;
    console.log('');

    const ref = db.collection('sections').doc(target.id);
    const before = await ref.get();
    const t0 = before.updateTime;
    console.log('[2] CAPTURED updateTime (T0): ' + t0.toDate().toISOString());
    console.log('');

    if (!COMMIT) {
        console.log('DRY RUN — no writes performed. Re-run with --commit to exercise CAS.');
        process.exit(0);
    }

    // ── CASE H1 — commit with a CURRENT precondition → must SUCCEED ─────
    console.log('[3] CASE H1 — commit with CURRENT updateTime  → expect SUCCESS');
    let h1 = 'UNKNOWN';
    try {
        await ref.update({ seatCasAt: new Date().toISOString() }, { lastUpdateTime: t0 });
        h1 = 'SUCCESS';
    } catch (e) {
        h1 = 'FAILED: code=' + e.code + ' msg=' + e.message;
    }
    console.log('    result: ' + h1);
    line();

    const afterH1 = await ref.get();
    const t1 = afterH1.updateTime;
    console.log('    new updateTime (T1): ' + t1.toDate().toISOString());
    console.log('    T1 !== T0 (marker moved the doc): ' + (t1.toMillis() !== t0.toMillis()));
    console.log('');

    // ── CASE H2 — commit with the now-STALE T0 → must be REJECTED ───────
    console.log('[4] CASE H2 — commit with STALE updateTime (T0)  → expect REJECTION');
    let h2 = 'UNKNOWN', h2code = '', h2msg = '';
    try {
        await ref.update({ seatCasAt: new Date().toISOString() }, { lastUpdateTime: t0 });
        h2 = '*** UNEXPECTED SUCCESS — CAS DID NOT HOLD ***';
    } catch (e) {
        h2 = 'REJECTED';
        h2code = String(e.code);
        h2msg  = String(e.message);
    }
    console.log('    result   : ' + h2);
    console.log('    err.code : ' + h2code + '   (gRPC 9 = FAILED_PRECONDITION)');
    console.log('    err.msg  : ' + h2msg.slice(0, 160));
    line();
    console.log('');

    // ── CASE C — two competing writers holding the SAME updateTime ──────
    // The real concurrency case: both captured T1, both commit. Exactly one
    // may win. Issued without awaiting in between so they race in flight.
    console.log('[5] CASE C — TWO COMPETING COMMITS, SAME captured updateTime (T1)');
    const tC = (await ref.get()).updateTime;
    const results = await Promise.allSettled([
        ref.update({ seatCasAt: 'writerA-' + new Date().toISOString() }, { lastUpdateTime: tC }),
        ref.update({ seatCasAt: 'writerB-' + new Date().toISOString() }, { lastUpdateTime: tC }),
    ]);
    const won  = results.filter(r => r.status === 'fulfilled').length;
    const lost = results.filter(r => r.status === 'rejected').length;
    results.forEach((r, i) => {
        const who = i === 0 ? 'writerA' : 'writerB';
        if (r.status === 'fulfilled') console.log('    ' + who + ': SUCCESS');
        else console.log('    ' + who + ': REJECTED  code=' + r.reason.code
            + ' msg=' + String(r.reason.message).slice(0, 90));
    });
    console.log('    → winners=' + won + '  losers=' + lost);
    console.log('    → EXACTLY ONE WINNER: ' + (won === 1 && lost === 1 ? 'YES ✅' : 'NO ❌'));
    line();
    console.log('');

    // ── Restore ─────────────────────────────────────────────────────────
    console.log('[6] CLEANUP — restoring the section document');
    if (!hadMarker) {
        await ref.update({ seatCasAt: admin.firestore.FieldValue.delete() });
        console.log('    seatCasAt removed (it did not exist before this run)');
    } else {
        await ref.update({ seatCasAt: target.seatCasAt });
        console.log('    seatCasAt restored to its prior value');
    }

    const after = (await ref.get()).data() || {};
    console.log('');
    console.log('[7] TARGET SECTION (after)');
    console.log('    maxStrength : ' + (after.maxStrength !== undefined ? after.maxStrength : '(not set)'));
    console.log('    seatCasAt   : ' + (after.seatCasAt !== undefined ? after.seatCasAt : '(absent)'));
    console.log('    className/section unchanged: '
        + (after.className === target.className && after.section === target.section));

    console.log('\n' + '═'.repeat(78));
    console.log(' VERDICT');
    console.log('═'.repeat(78));
    console.log('  H1 current-precondition commit : ' + h1);
    console.log('  H2 stale-precondition commit   : ' + h2 + (h2code ? ' (code ' + h2code + ')' : ''));
    console.log('  C  two competing commits       : winners=' + won + ' losers=' + lost);
    console.log('');
    console.log('  NO student document was touched. No SRNO / Student ID /');
    console.log('  Admission Number / APAAR value was consumed. No fee demand created.');
    process.exit(0);
})().catch(e => { console.error('UAT FAILED: ' + e.message); process.exit(1); });
