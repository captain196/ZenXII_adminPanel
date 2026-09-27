/**
 * WHO MAY WRITE THE COLLECTION THE APPS READ — AFTER THE SWITCHOVER.
 *
 * `timetables` is the compatibility projection. Once the switchover happens it
 * has exactly one writer: Timetable_plan_store, through the Admin SDK, which
 * bypasses security rules entirely. Nothing else legitimately writes it —
 * both apps are read-only, and the legacy admin editors refuse in PHP before
 * they reach a write.
 *
 * Every client write the deployed rule admits is therefore illegitimate, and
 * TT-D1 is precisely that: any staff account, including a driver, a peon or a
 * security guard, can create, rewrite or DELETE a school's timetable through
 * the client SDK. After the switchover it would also fork the derived copy away
 * from the canonical version it is supposed to mirror.
 *
 * TT-D1 was CLOSED in the repository on 2026-08-31 (gate B). This file runs
 * BOTH rulesets against the real engine so the change is provable in each
 * direction:
 *
 *   • firestore.rules.tt_d1_before — the rules as they stood, frozen. This is
 *     what PRODUCTION still runs until the change is deployed, and it records
 *     the gap as executed evidence rather than as a claim.
 *   • firestore.rules — the repository rules, now denying every client write
 *     while both apps still read normally.
 *
 * The repository being fixed is not production being fixed. Until deployment,
 * the permissive suite below is the live behaviour.
 */
const { initializeTestEnvironment, assertSucceeds, assertFails } = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const CURRENT   = path.join(__dirname, '..', 'firestore.rules');
// The rules as they stood before gate B, frozen for regression evidence: it is
// what production still runs until this change is deployed.
const BEFORE = path.join(__dirname, '..', 'firestore.rules.tt_d1_before');

const A = 'SCH_A0001';
const B = 'SCH_B0002';
const SESSION = '2026-27';
const TEACHER = 'STA0001';
const PEON    = 'STA0003';
const ADMIN   = 'STA0009';

const ttId = (school, day = 'Monday') =>
    `${school}_${SESSION}_Class 9th_Section A_${day}`;

const ttDoc = (school) => ({
    schoolId: school, session: SESSION,
    className: 'Class 9th', section: 'Section A',
    sectionKey: 'Class 9th/Section A', day: 'Monday',
    periods: [{ periodNumber: 1, subject: 'Mathematics', teacherId: TEACHER,
                teacher: 'A Teacher', startTime: '09:00', endTime: '09:40',
                type: 'class' }],
    projectedFromPlan: 'PLAN1', projectedFromVersion: 'V1',
    projectedAt: '2026-09-01T00:00:00+00:00', version: 1,
});

function ctx(env, school, uid, role) {
    return env.authenticatedContext(uid, {
        school_id: school, schoolId: school, role, session: SESSION,
    }).firestore();
}

async function seed(env) {
    await env.withSecurityRulesDisabled(async (c) => {
        const db = c.firestore();
        for (const s of [A, B]) {
            await db.doc(`schoolControl/${s}`).set({ schoolId: s, lifecycle: { state: 'active' } });
            await db.doc(`schools/${s}`).set({ schoolId: s, name: s });
        }
        for (const [s, uid, admin] of [[A, TEACHER, false], [A, PEON, false],
                                       [A, ADMIN, true], [B, TEACHER, false]]) {
            await db.doc(`staff/${s}_${uid}`).set({
                schoolId: s, staffId: uid, name: uid, status: 'Active',
            });
            await db.doc(`staffCapabilities/${uid}`).set({
                schoolId: s, admin, modules: ['Academic'], levels: { Academic: 'manage' },
            });
        }
        await db.doc(`timetables/${ttId(A)}`).set(ttDoc(A));
    });
}

function suite(label, rulesPath, clientWrites) {
    describe(label, () => {
        let env;
        beforeAll(async () => {
            env = await initializeTestEnvironment({
                projectId: 'zenxii-rules-test',
                firestore: { rules: fs.readFileSync(rulesPath, 'utf8') },
            });
        });
        afterAll(async () => { if (env) await env.cleanup(); });
        beforeEach(async () => { await env.clearFirestore(); await seed(env); });

        // ── what BOTH rulesets must keep working ───────────────────────
        // The apps are read-only consumers; taking their read away would
        // empty every timetable screen in the school.

        test('a teacher may read the timetable', async () => {
            await assertSucceeds(ctx(env, A, TEACHER, 'Teacher').doc(`timetables/${ttId(A)}`).get());
        });

        test('a parent may read the timetable', async () => {
            await assertSucceeds(ctx(env, A, 'PAR001', 'Parent').doc(`timetables/${ttId(A)}`).get());
        });

        test('another school cannot read this school\'s timetable', async () => {
            await assertFails(ctx(env, B, TEACHER, 'Teacher').doc(`timetables/${ttId(A)}`).get());
        });

        test('another school cannot write into this school', async () => {
            await assertFails(
                ctx(env, B, TEACHER, 'Teacher').doc(`timetables/${ttId(A)}`)
                    .set({ periods: ['hijacked'] }, { merge: true }));
        });

        // ── the arms under test ────────────────────────────────────────

        test(clientWrites.create.name, async () => {
            const w = ctx(env, A, TEACHER, 'Teacher')
                .doc(`timetables/${ttId(A, 'Tuesday')}`).set(ttDoc(A));
            await clientWrites.create.assert(w);
        });

        test(clientWrites.update.name, async () => {
            const w = ctx(env, A, TEACHER, 'Teacher')
                .doc(`timetables/${ttId(A)}`).set({ periods: [] }, { merge: true });
            await clientWrites.update.assert(w);
        });

        test(clientWrites.del.name, async () => {
            const w = ctx(env, A, PEON, 'Peon').doc(`timetables/${ttId(A)}`).delete();
            await clientWrites.del.assert(w);
        });

        test(clientWrites.adminWrite.name, async () => {
            const w = ctx(env, A, ADMIN, 'Admin')
                .doc(`timetables/${ttId(A)}`).set({ periods: [] }, { merge: true });
            await clientWrites.adminWrite.assert(w);
        });
    });
}

const ONLY = process.env.RULES_SUITE || '';

if (ONLY === '' || ONLY === 'deployed') {
    // The gap, as executed evidence rather than a claim read off the file.
    suite('pre-gate-B rules — what production still runs', BEFORE, {
        create:      { name: 'ANY staff member can create a timetable document', assert: assertSucceeds },
        update:      { name: 'ANY staff member can rewrite a projected timetable', assert: assertSucceeds },
        del:         { name: 'a peon can DELETE a school\'s timetable', assert: assertSucceeds },
        adminWrite:  { name: 'an admin client can rewrite a projected timetable', assert: assertSucceeds },
    });
}

if (ONLY === '' || ONLY === 'candidate') {
    suite('repository rules — projection is the only writer', CURRENT, {
        create:      { name: 'no client can create a timetable document', assert: assertFails },
        update:      { name: 'no client can rewrite a projected timetable', assert: assertFails },
        del:         { name: 'no client can delete a timetable', assert: assertFails },
        adminWrite:  { name: 'not even an admin client — the projection writes through the Admin SDK', assert: assertFails },
    });
}
