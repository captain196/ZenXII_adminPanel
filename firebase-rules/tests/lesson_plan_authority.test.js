/**
 * WHO MAY WRITE A LESSON PLAN, AT THE STORAGE BOUNDARY.
 *
 * The teacher app writes lessonPlans straight to Firestore. Its own repository
 * documented the authorization model as "Firestore rules should reject writes
 * by a teacher for a slot they aren't assigned to" — so the rules, not the app,
 * were the stated boundary. They do not contain any such condition.
 *
 * This file runs BOTH rulesets against the real engine:
 *
 *   • the deployed rules, to record the gap as executed evidence rather than
 *     as a claim read off a file;
 *   • the candidate rules (firestore.rules.lessonplan_candidate), to show the
 *     tightening refuses exactly the cross-teacher writes and nothing that
 *     either app legitimately does.
 *
 * The candidate is a separate file on purpose: firestore.rules carries another
 * programme's uncommitted work, and deploying is not this audit's to do.
 */
const { initializeTestEnvironment, assertSucceeds, assertFails } = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const CURRENT   = path.join(__dirname, '..', 'firestore.rules');
const CANDIDATE = path.join(__dirname, '..', 'firestore.rules.lessonplan_candidate');

const A = 'SCH_A0001';
const B = 'SCH_B0002';
const SESSION = '2026-27';
const T1 = 'STA0001';        // the teacher whose period it is
const T2 = 'STA0002';        // another teacher at the same school
const ADMIN = 'STA0009';

const planId = (school, teacher, date, p) => `${school}_${SESSION}_${teacher}_${date}_P${p}`;

const plan = (school, teacher, over = {}) => Object.assign({
    schoolId: school, session: SESSION,
    planId: planId(school, teacher, '2026-09-07', 2),
    version: 1,
    className: 'Class 9th', section: 'Section A',
    classSection: 'Class 9th/Section A', subject: 'Mathematics', subjectCode: '',
    teacherId: teacher, teacherName: 'A Teacher',
    date: '2026-09-07', dayOfWeek: 'Monday', periodIndex: 2, periodNumber: 3,
    topicId: '', topicTitle: '', curriculumDocId: '',
    notes: 'A lesson', status: 'planned', rescheduledTo: '', completedAt: '',
    createdAt: '2026-09-01T09:00:00+00:00', updatedAt: '2026-09-01T09:00:00+00:00',
}, over);

/** A staff member of `school`, with the Academic capability. */
function teacherCtx(env, school, uid) {
    return env.authenticatedContext(uid, {
        school_id: school, schoolId: school, role: 'Teacher', session: SESSION,
    }).firestore();
}
function adminCtx(env, school, uid) {
    return env.authenticatedContext(uid, {
        school_id: school, schoolId: school, role: 'Admin', session: SESSION,
    }).firestore();
}

async function seed(env) {
    await env.withSecurityRulesDisabled(async (ctx) => {
        const db = ctx.firestore();
        for (const s of [A, B]) {
            await db.doc(`schoolControl/${s}`).set({ schoolId: s, lifecycle: { state: 'active' } });
            await db.doc(`schools/${s}`).set({ schoolId: s, name: s });
        }
        for (const [s, uid, admin] of [[A, T1, false], [A, T2, false], [A, ADMIN, true], [B, T1, false]]) {
            await db.doc(`staff/${s}_${uid}`).set({
                schoolId: s, staffId: uid, name: uid, status: 'Active',
            });
            // hasCapability() reads `modules` as a LIST and calls hasAny on it;
            // a map here produces an evaluation error, which the engine reports
            // as a denial and would have been easy to read as a rule working.
            await db.doc(`staffCapabilities/${uid}`).set({
                schoolId: s, admin,
                modules: ['Academic'],
                levels: { Academic: 'manage' },
            });
        }
        // A plan already belonging to T1.
        await db.doc(`lessonPlans/${planId(A, T1, '2026-09-07', 2)}`).set(plan(A, T1));
        await db.doc(`subjectPlanProgress/${A}_${SESSION}_Class_9th_Section_A_Mathematics`)
            .set({ schoolId: A, session: SESSION, totalPlans: 20, completedCount: 12 });
        await db.doc(`dailyTeacherMonitoring/${A}_${SESSION}_2026-09-07_${T1}`)
            .set({ schoolId: A, session: SESSION, teacherId: T1, plansSaved: 4 });
    });
}

function suiteSkip() {}
jest.setTimeout(180000);   // the first connection pays the emulator warm-up

function suite(label, rulesPath, expectations) {
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

        // ── things that must work in BOTH rulesets ─────────────────────
        test('a teacher may write a plan for their own period', async () => {
            const db = teacherCtx(env, A, T1);
            await assertSucceeds(
                db.doc(`lessonPlans/${planId(A, T1, '2026-09-08', 1)}`)
                  .set(plan(A, T1, { planId: planId(A, T1, '2026-09-08', 1), date: '2026-09-08', periodIndex: 1 })));
        });

        test('a teacher may update their own plan', async () => {
            const db = teacherCtx(env, A, T1);
            await assertSucceeds(
                db.doc(`lessonPlans/${planId(A, T1, '2026-09-07', 2)}`)
                  .set(plan(A, T1, { status: 'completed', version: 2 })));
        });

        test('another school cannot read this school\'s plans', async () => {
            const db = teacherCtx(env, B, T1);
            await assertFails(db.doc(`lessonPlans/${planId(A, T1, '2026-09-07', 2)}`).get());
        });

        test('another school cannot write into this school', async () => {
            const db = teacherCtx(env, B, T1);
            await assertFails(
                db.doc(`lessonPlans/${planId(A, T1, '2026-09-09', 0)}`).set(plan(A, T1)));
        });

        test('a parent of the school may read the day\'s lessons', async () => {
            const db = env.authenticatedContext('PAR001', {
                school_id: A, schoolId: A, role: 'Parent', session: SESSION,
            }).firestore();
            await assertSucceeds(db.doc(`lessonPlans/${planId(A, T1, '2026-09-07', 2)}`).get());
        });

        // ── the arms under test ────────────────────────────────────────
        test(expectations.crossTeacherCreate.name, async () => {
            const db = teacherCtx(env, A, T2);
            const write = db.doc(`lessonPlans/${planId(A, T1, '2026-09-10', 3)}`)
                .set(plan(A, T1, { planId: planId(A, T1, '2026-09-10', 3), date: '2026-09-10', periodIndex: 3 }));
            await expectations.crossTeacherCreate.assert(write);
        });

        test(expectations.crossTeacherUpdate.name, async () => {
            const db = teacherCtx(env, A, T2);
            const write = db.doc(`lessonPlans/${planId(A, T1, '2026-09-07', 2)}`)
                .set(plan(A, T1, { status: 'completed', notes: 'Rewritten by somebody else', version: 2 }));
            await expectations.crossTeacherUpdate.assert(write);
        });

        test(expectations.progressWrite.name, async () => {
            const db = teacherCtx(env, A, T1);
            const write = db.doc(`subjectPlanProgress/${A}_${SESSION}_Class_9th_Section_A_Mathematics`)
                .set({ schoolId: A, session: SESSION, totalPlans: 20, completedCount: 20,
                       percentComplete: 100 }, { merge: true });
            await expectations.progressWrite.assert(write);
        });

        test(expectations.monitoringWrite.name, async () => {
            const db = teacherCtx(env, A, T1);
            const write = db.doc(`dailyTeacherMonitoring/${A}_${SESSION}_2026-09-07_${T1}`)
                .set({ schoolId: A, session: SESSION, teacherId: T1, plansSaved: 99 }, { merge: true });
            await expectations.monitoringWrite.assert(write);
        });
    });
}

// What the DEPLOYED rules actually do today. These pass because the writes
// SUCCEED — that is the finding, recorded as evidence rather than asserted.
const ONLY = process.env.RULES_SUITE || '';
const runDeployed  = ONLY === '' || ONLY === 'deployed';
const runCandidate = ONLY === '' || ONLY === 'candidate';

(runDeployed ? suite : suiteSkip)('deployed rules — as they stand today', CURRENT, {
    crossTeacherCreate: {
        name: 'ANY staff member can create a plan in another teacher\'s name',
        assert: assertSucceeds,
    },
    crossTeacherUpdate: {
        name: 'ANY staff member can overwrite another teacher\'s plan',
        assert: assertSucceeds,
    },
    progressWrite: {
        name: 'ANY staff device can rewrite the progress a parent reads',
        assert: assertSucceeds,
    },
    monitoringWrite: {
        name: 'ANY staff device can rewrite the oversight counters',
        assert: assertSucceeds,
    },
});

(runCandidate ? suite : suiteSkip)('candidate rules — lessonplan_candidate', CANDIDATE, {
    crossTeacherCreate: {
        name: 'a teacher cannot create a plan in another teacher\'s name',
        assert: assertFails,
    },
    crossTeacherUpdate: {
        name: 'a teacher cannot overwrite another teacher\'s plan',
        assert: assertFails,
    },
    progressWrite: {
        name: 'no client can rewrite the progress a parent reads',
        assert: assertFails,
    },
    monitoringWrite: {
        name: 'no client can rewrite the oversight counters',
        assert: assertFails,
    },
});

// One more, only meaningful under the candidate: an admin-capability client is
// still allowed to plan on behalf of a teacher.
(runCandidate ? describe : describe.skip)('candidate rules — the admin exemption', () => {
    let env;
    beforeAll(async () => {
        env = await initializeTestEnvironment({
            projectId: 'zenxii-rules-test',
            firestore: { rules: fs.readFileSync(CANDIDATE, 'utf8') },
        });
    });
    afterAll(async () => { if (env) await env.cleanup(); });
    beforeEach(async () => { await env.clearFirestore(); await seed(env); });

    test('an admin may still write a plan for a teacher', async () => {
        const db = adminCtx(env, A, ADMIN);
        await assertSucceeds(
            db.doc(`lessonPlans/${planId(A, T1, '2026-09-11', 4)}`)
              .set(plan(A, T1, { planId: planId(A, T1, '2026-09-11', 4), date: '2026-09-11', periodIndex: 4 })));
    });

    test('a teacher cannot rename a plan onto themselves', async () => {
        const db = teacherCtx(env, A, T2);
        await assertFails(
            db.doc(`lessonPlans/${planId(A, T1, '2026-09-07', 2)}`)
              .set(plan(A, T2, { planId: planId(A, T1, '2026-09-07', 2), version: 2 })));
    });
});
