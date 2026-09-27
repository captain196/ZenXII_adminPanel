/**
 * staffEmploymentEvents / staffEmploymentTimeline — Firestore Rules unit
 * tests (Employment History Phase 1: Position/Department/EmploymentType).
 *
 * Locks down the two collections added for this phase: an immutable
 * append-only event log (no update/delete rule at all) and a derived
 * effective-dated timeline projection (update allowed to close an entry,
 * no delete). Both gated on the graded RBAC capability model
 * (hasCapabilityLevel('SIS','manage')) — never the legacy role array,
 * never Designation/Position/EmploymentType values themselves.
 *
 * Run: cd firebase-rules/tests && npm test
 */
const {
  initializeTestEnvironment,
  assertSucceeds,
  assertFails,
} = require('@firebase/rules-unit-testing');
const fs = require('fs');
const path = require('path');

const PROJECT_ID = 'zenxii-rules-test';
const RULES_PATH = path.join(__dirname, '..', 'firestore.rules');

const SCHOOL = 'SCH_TEST_EH_001';
const OTHER_SCHOOL = 'SCH_TEST_EH_002';

const ADMIN_SIS_MANAGE = 'admin-eh-manage';     // capability doc: SIS:manage
const ADMIN_SIS_VIEW   = 'admin-eh-view';       // capability doc: SIS:view (insufficient)
const ADMIN_NO_CAPS    = 'admin-eh-nocaps';     // no staffCapabilities doc — fails OPEN per hasCapabilityLevel
const PLAIN_TEACHER    = 'teacher-eh-1';        // not an admin at all

let env;

const eventDocOf    = (school, key) => `staffEmploymentEvents/${school}_${key}`;
const timelineDocOf = (school, staffId, attr, key) => `staffEmploymentTimeline/${school}_${staffId}_${attr}_${key}`;

function asRole(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
function asAnon() {
  return env.unauthenticatedContext().firestore();
}

function baseEvent(overrides = {}) {
  return {
    schoolId: SCHOOL, staffId: 'STA_EH_1', eventType: 'DEPARTMENT_CHANGED',
    attribute: 'department', fromValue: 'Mathematics', toValue: 'Academics',
    effectiveFrom: '2026-08-17', changed_by: ADMIN_SIS_MANAGE, changed_at: '2026-08-17 10:00:00',
    reason: '', correctsEventRef: '', reversesEventRef: '', metadata: {},
    ...overrides,
  };
}
function baseTimelineEntry(overrides = {}) {
  return {
    schoolId: SCHOOL, staffId: 'STA_EH_1', attribute: 'department', value: 'Academics',
    effectiveFrom: '2026-08-17', effectiveTo: null, eventRef: 'evt_ref_1', setBy: ADMIN_SIS_MANAGE,
    reason: '',
    ...overrides,
  };
}

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (ctx) => {
    const db = ctx.firestore();
    for (const s of [SCHOOL, OTHER_SCHOOL]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test School` });
    }

    await db.doc(`staffCapabilities/${ADMIN_SIS_MANAGE}`).set({
      modules: ['SIS'], levels: { SIS: 'manage' }, admin: false,
    });
    await db.doc(`staffCapabilities/${ADMIN_SIS_VIEW}`).set({
      modules: ['SIS'], levels: { SIS: 'view' }, admin: false,
    });
    // ADMIN_NO_CAPS: deliberately no staffCapabilities doc.
    await db.doc(`staffCapabilities/${PLAIN_TEACHER}`).set({
      modules: [], levels: {}, admin: false,
    });

    // A pre-existing open timeline entry to close in the "update" tests.
    await db.doc(timelineDocOf(SCHOOL, 'STA_EH_1', 'department', 'evt_prior')).set(
      baseTimelineEntry({ value: 'Mathematics', eventRef: 'evt_prior' })
    );
    await db.doc(timelineDocOf(OTHER_SCHOOL, 'STA_EH_2', 'department', 'evt_prior_2')).set(
      baseTimelineEntry({ schoolId: OTHER_SCHOOL, staffId: 'STA_EH_2', value: 'Science', eventRef: 'evt_prior_2' })
    );
    await db.doc(eventDocOf(SCHOOL, 'evt_existing_1')).set(baseEvent());
    await db.doc(eventDocOf(OTHER_SCHOOL, 'evt_existing_2')).set(baseEvent({ schoolId: OTHER_SCHOOL, staffId: 'STA_EH_2' }));
  });
}

beforeAll(async () => {
  env = await initializeTestEnvironment({
    projectId: PROJECT_ID,
    firestore: { rules: fs.readFileSync(RULES_PATH, 'utf8'), host: '127.0.0.1', port: 18080 },
  });
  await seedWorld();
}, 120000);
afterAll(async () => { if (env) await env.cleanup(); });

describe('staffEmploymentEvents — create', () => {
  test('SAME-SCHOOL admin with SIS:manage CAN create an event', async () => {
    await assertSucceeds(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin')
        .doc(eventDocOf(SCHOOL, 'evt_new_ok'))
        .set(baseEvent())
    );
  });

  test('SAME-SCHOOL admin with only SIS:view CANNOT create an event (graded level enforced)', async () => {
    await assertFails(
      asRole(ADMIN_SIS_VIEW, SCHOOL, 'admin')
        .doc(eventDocOf(SCHOOL, 'evt_new_denied_view'))
        .set(baseEvent())
    );
  });

  test('a non-admin staff member CANNOT create an event even with SIS capability data present', async () => {
    await assertFails(
      asRole(PLAIN_TEACHER, SCHOOL, 'Teacher')
        .doc(eventDocOf(SCHOOL, 'evt_new_denied_nonadmin'))
        .set(baseEvent())
    );
  });

  test('CROSS-SCHOOL create is denied — an admin of school A cannot create an event for school B', async () => {
    await assertFails(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin')
        .doc(eventDocOf(OTHER_SCHOOL, 'evt_cross_school_denied'))
        .set(baseEvent({ schoolId: OTHER_SCHOOL }))
    );
  });

  test('unauthenticated write is denied', async () => {
    await assertFails(
      asAnon().doc(eventDocOf(SCHOOL, 'evt_anon_denied')).set(baseEvent())
    );
  });
});

describe('staffEmploymentEvents — immutability (append-only)', () => {
  test('UPDATE is denied outright — no update rule exists for this collection', async () => {
    await assertFails(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin')
        .doc(eventDocOf(SCHOOL, 'evt_existing_1'))
        .update({ toValue: 'Tampered' })
    );
  });

  test('DELETE is denied outright — no delete rule exists for this collection', async () => {
    await assertFails(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin')
        .doc(eventDocOf(SCHOOL, 'evt_existing_1'))
        .delete()
    );
  });
});

describe('staffEmploymentEvents — read isolation', () => {
  test('SAME-SCHOOL read succeeds', async () => {
    await assertSucceeds(
      asRole(PLAIN_TEACHER, SCHOOL, 'Teacher').doc(eventDocOf(SCHOOL, 'evt_existing_1')).get()
    );
  });

  test('CROSS-SCHOOL read is denied', async () => {
    await assertFails(
      asRole(PLAIN_TEACHER, SCHOOL, 'Teacher').doc(eventDocOf(OTHER_SCHOOL, 'evt_existing_2')).get()
    );
  });
});

describe('staffEmploymentTimeline — create/update', () => {
  test('SAME-SCHOOL admin with SIS:manage CAN open a new timeline entry', async () => {
    await assertSucceeds(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin')
        .doc(timelineDocOf(SCHOOL, 'STA_EH_1', 'position', 'evt_new_pos'))
        .set(baseTimelineEntry({ attribute: 'position', value: 'Senior Teacher', eventRef: 'evt_new_pos' }))
    );
  });

  test('SAME-SCHOOL admin with SIS:manage CAN close the currently-open entry (update, properly gated)', async () => {
    await assertSucceeds(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin')
        .doc(timelineDocOf(SCHOOL, 'STA_EH_1', 'department', 'evt_prior'))
        .update({ effectiveTo: '2026-08-17' })
    );
  });

  test('SAME-SCHOOL admin with only SIS:view CANNOT close an entry', async () => {
    await assertFails(
      asRole(ADMIN_SIS_VIEW, SCHOOL, 'admin')
        .doc(timelineDocOf(SCHOOL, 'STA_EH_1', 'department', 'evt_prior'))
        .update({ effectiveTo: '2026-08-17' })
    );
  });

  test('a non-admin staff member CANNOT open or close a timeline entry', async () => {
    await assertFails(
      asRole(PLAIN_TEACHER, SCHOOL, 'Teacher')
        .doc(timelineDocOf(SCHOOL, 'STA_EH_1', 'department', 'evt_prior'))
        .update({ effectiveTo: '2026-08-17' })
    );
  });

  test('CROSS-SCHOOL create is denied', async () => {
    await assertFails(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin')
        .doc(timelineDocOf(OTHER_SCHOOL, 'STA_EH_2', 'position', 'evt_cross_denied'))
        .set(baseTimelineEntry({ schoolId: OTHER_SCHOOL, staffId: 'STA_EH_2', attribute: 'position' }))
    );
  });
});

describe('staffEmploymentTimeline — delete rejection', () => {
  test('DELETE is denied — a timeline entry is closed, never deleted', async () => {
    await assertFails(
      asRole(ADMIN_SIS_MANAGE, SCHOOL, 'admin')
        .doc(timelineDocOf(SCHOOL, 'STA_EH_1', 'department', 'evt_prior'))
        .delete()
    );
  });
});

describe('staffEmploymentTimeline — read isolation', () => {
  test('SAME-SCHOOL read succeeds', async () => {
    await assertSucceeds(
      asRole(PLAIN_TEACHER, SCHOOL, 'Teacher')
        .doc(timelineDocOf(SCHOOL, 'STA_EH_1', 'department', 'evt_prior')).get()
    );
  });

  test('CROSS-SCHOOL read is denied', async () => {
    await assertFails(
      asRole(PLAIN_TEACHER, SCHOOL, 'Teacher')
        .doc(timelineDocOf(OTHER_SCHOOL, 'STA_EH_2', 'department', 'evt_prior_2')).get()
    );
  });
});

describe('staffEmploymentEvents/Timeline — fail-open on absent capability doc (documented, existing hasCapabilityLevel contract)', () => {
  test('an admin with NO staffCapabilities doc at all still succeeds (same fail-open semantics as every other hasCapabilityLevel gate)', async () => {
    await assertSucceeds(
      asRole(ADMIN_NO_CAPS, SCHOOL, 'admin')
        .doc(eventDocOf(SCHOOL, 'evt_nocaps_admin_ok'))
        .set(baseEvent())
    );
  });
});
