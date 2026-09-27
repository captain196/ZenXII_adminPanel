/**
 * TT-D2 — THE TIMETABLE IS TENANT-SCOPED. ITS OVERLAY AND ITS SETTINGS WERE NOT.
 *
 * `timetables` reads are bound to the caller's own school. The two collections
 * that sit directly on top of it were not:
 *
 *   timetableSettings   allow read: if isAuth();
 *   substitutes         allow read: if isAuth();
 *
 * `isAuth()` is "any signed-in user of any school in the project". Both
 * collections are read by the Teacher and Parent apps to render the same screen
 * the timetable renders — settings supply periods-per-day and the bell times,
 * substitutes supply the cover overlay — so the effect was that the timetable
 * itself was tenant-scoped while the things drawn on top of it were readable
 * across every tenant in the platform.
 *
 * `substitutes` is not configuration. A row names the absent teacher, the
 * covering teacher, the date, and the class and section — an attendance-shaped
 * record about identifiable staff at another school, readable by anyone with an
 * account anywhere.
 *
 * This file runs BOTH rulesets against the real engine, so the change is
 * provable in each direction:
 *
 *   • firestore.rules.tt_d2_before — frozen. What production runs until this is
 *     deployed, and what records the leak as executed evidence.
 *   • firestore.rules — the repository rules, tenant-scoped, with every read
 *     both apps actually perform still succeeding.
 */
const { initializeTestEnvironment, assertSucceeds, assertFails } = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const CURRENT = path.join(__dirname, '..', 'firestore.rules');
const BEFORE  = path.join(__dirname, '..', 'firestore.rules.tt_d2_before');

const A = 'SCH_OVL_A001';
const B = 'SCH_OVL_B002';
const SESSION = '2026-27';

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
            await db.doc(`staff/${s}_STA0001`).set({ schoolId: s, staffId: 'STA0001', status: 'Active' });

            await db.doc(`timetableSettings/${s}_${SESSION}`).set({
                schoolId: s, session: SESSION, periodsPerDay: 8,
                periodTimes: [{ periodNumber: 1, startTime: '09:00', endTime: '09:40' }],
            });
            await db.doc(`substitutes/${s}_SUB_0001`).set({
                schoolId: s, session: SESSION, date: '2026-09-15',
                absentTeacherId: 'STA0001', absentTeacherName: 'A Teacher',
                coverTeacherId: 'STA0002', coverTeacherName: 'B Teacher',
                className: 'Class 9th', section: 'Section A', periodNumber: 3,
            });
        }
    });
}

function suite(label, rulesPath, crossTenant) {
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

        // ── what both rulesets must keep working ───────────────────────
        // These are the reads the Teacher and Parent apps make to draw a
        // timetable. Taking any of them away empties a real screen.

        test('a teacher reads their OWN school settings', async () => {
            await assertSucceeds(
                ctx(env, A, 'STA0001', 'Teacher').doc(`timetableSettings/${A}_${SESSION}`).get());
        });

        test('a parent reads their OWN school settings', async () => {
            await assertSucceeds(
                ctx(env, A, 'PAR001', 'Parent').doc(`timetableSettings/${A}_${SESSION}`).get());
        });

        test('a teacher reads their OWN school substitutes', async () => {
            await assertSucceeds(
                ctx(env, A, 'STA0001', 'Teacher').doc(`substitutes/${A}_SUB_0001`).get());
        });

        test('a parent reads their OWN school substitutes', async () => {
            await assertSucceeds(
                ctx(env, A, 'PAR001', 'Parent').doc(`substitutes/${A}_SUB_0001`).get());
        });

        // ── THE QUERY, NOT A DOCUMENT GET ──────────────────────────────
        //
        // This is the assertion that decides whether the tightening is safe to
        // ship, and a `.doc().get()` cannot stand in for it. Firestore
        // evaluates a LIST against the rule as a whole: a query the rule
        // cannot prove is confined to the caller's own tenant is denied
        // outright, whatever the documents happen to contain. So the exact
        // query shape both apps issue has to be exercised.
        //
        //   Teacher  TimetableFirestoreRepository / DashboardViewModel
        //   Parent   TimetableFirestoreRepository
        //     whereEqualTo("schoolId", mySchool).whereEqualTo("date", today)
        //
        // The unscoped form below is what the currently-RELEASED Teacher
        // dashboard build sends. It is asserted in both directions on purpose:
        // it is the query that must keep working until that build rolls out,
        // and the reason this rule cannot be deployed before the apps.

        test('the apps\' scoped substitutes query works', async () => {
            await assertSucceeds(
                ctx(env, A, 'STA0001', 'Teacher').collection('substitutes')
                    .where('schoolId', '==', A).where('date', '==', '2026-09-15').get());
        });

        test('a parent\'s scoped substitutes query works', async () => {
            await assertSucceeds(
                ctx(env, A, 'PAR001', 'Parent').collection('substitutes')
                    .where('schoolId', '==', A).where('date', '==', '2026-09-15').get());
        });

        test(crossTenant.foreignScopedQuery.name, async () => {
            await crossTenant.foreignScopedQuery.assert(
                ctx(env, B, 'STA0001', 'Teacher').collection('substitutes')
                    .where('schoolId', '==', A).where('date', '==', '2026-09-15').get());
        });

        test(crossTenant.unscopedQuery.name, async () => {
            await crossTenant.unscopedQuery.assert(
                ctx(env, A, 'STA0001', 'Teacher').collection('substitutes')
                    .where('date', '==', '2026-09-15').get());
        });

        // Writes were already admin+tenant bound and must stay that way.
        test('a teacher cannot write settings', async () => {
            await assertFails(
                ctx(env, A, 'STA0001', 'Teacher').doc(`timetableSettings/${A}_${SESSION}`)
                    .set({ periodsPerDay: 99 }, { merge: true }));
        });

        test("an admin cannot write another school's substitutes", async () => {
            await assertFails(
                ctx(env, A, 'STA0009', 'Admin').doc(`substitutes/${B}_SUB_0001`)
                    .set({ coverTeacherId: 'HIJACK' }, { merge: true }));
        });

        // ── the arm under test ─────────────────────────────────────────

        test(crossTenant.settings.name, async () => {
            await crossTenant.settings.assert(
                ctx(env, B, 'STA0001', 'Teacher').doc(`timetableSettings/${A}_${SESSION}`).get());
        });

        test(crossTenant.substitutes.name, async () => {
            await crossTenant.substitutes.assert(
                ctx(env, B, 'STA0001', 'Teacher').doc(`substitutes/${A}_SUB_0001`).get());
        });

        test(crossTenant.parentSubstitutes.name, async () => {
            await crossTenant.parentSubstitutes.assert(
                ctx(env, B, 'PAR001', 'Parent').doc(`substitutes/${A}_SUB_0001`).get());
        });
    });
}

const ONLY = process.env.RULES_SUITE || '';

if ((ONLY === '' || ONLY === 'deployed') && fs.existsSync(BEFORE)) {
    suite('pre-TT-D2 rules — what production still runs', BEFORE, {
        settings: {
            name: "ANY signed-in user reads another school's timetable settings",
            assert: assertSucceeds,
        },
        substitutes: {
            name: "ANY signed-in staff member reads another school's substitute cover, "
                + 'naming its absent and covering teachers',
            assert: assertSucceeds,
        },
        parentSubstitutes: {
            name: "ANY signed-in PARENT reads another school's substitute cover",
            assert: assertSucceeds,
        },
        foreignScopedQuery: {
            name: "a teacher can name ANOTHER school in the filter and get its rows",
            assert: assertSucceeds,
        },
        unscopedQuery: {
            name: "the released dashboard build's UNSCOPED query is allowed — every "
                + "school's substitute rows for today reach the handset",
            assert: assertSucceeds,
        },
    });
}

if (ONLY === '' || ONLY === 'candidate') {
    suite('repository rules — the overlay is tenant-scoped too', CURRENT, {
        settings: {
            name: "another school's timetable settings are not readable",
            assert: assertFails,
        },
        substitutes: {
            name: "another school's substitute cover is not readable",
            assert: assertFails,
        },
        parentSubstitutes: {
            name: "a parent cannot read another school's substitute cover either",
            assert: assertFails,
        },
        foreignScopedQuery: {
            name: "naming another school in the filter is denied",
            assert: assertFails,
        },
        unscopedQuery: {
            name: "an UNSCOPED query is denied outright — which is exactly why the "
                + "app builds must ship before this rule is deployed",
            assert: assertFails,
        },
    });
}
