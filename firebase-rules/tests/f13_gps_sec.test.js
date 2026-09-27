/**
 * F13.1 Live GPS — Firestore Rules unit tests.
 *
 * WHY THIS EXISTS
 * ---------------
 * `vehicleLivePositions` and `vehicleBreadcrumbs` are the most sensitive
 * collections added in the current working tree: they carry the live and
 * historical position of vehicles carrying children, and the driver client
 * writes them DIRECTLY — PHP never sees a position, so these rules are the
 * only enforcement point. Neither had any rules test.
 *
 * This file also closes a second untested gap in the same workstream: the D-8
 * `tripLogs` allowlist narrowing. D-8's stated guarantee is that "a future
 * client cannot reintroduce a second lifecycle writer, because Firestore will
 * reject the status transition outright" — a claim nothing verified. The
 * existing transport_triplogs_sec2.test.js references `status` only in its
 * seed data, so the whole suite stayed green both before and after the change.
 *
 * INTENT SOURCE
 * -------------
 * Every assertion below is taken from the access model documented inline in
 * firestore.rules itself (D-1, D-2, M2 fix-ordering, immutability, staff-only
 * breadcrumb reads). No business permission is invented here, and no
 * production rule was altered to make a test pass.
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

const SCHOOL = 'SCH_TEST_GPS_001';
const OTHER = 'SCH_TEST_GPS_002';

const DRIVER = 'STA_DRIVER_1';        // auth uid == staffId
const OTHER_DRIVER = 'STA_DRIVER_2';
const TEACHER = 'STA_TEACHER_1';
const STUDENT_OK = 'STU_ONBOARD_1';   // parent auth uid == student userId
const STUDENT_NOT = 'STU_OFFBOARD_1';

const VEH = 'VEH0001';
const VEH_OTHER = 'VEH0009';
const TRIP = 'TRIP0001';

let env;

const livePos = (school, veh) => `vehicleLivePositions/${school}_${veh}`;
const crumb   = (school, id)  => `vehicleBreadcrumbs/${school}_${id}`;
const tripLog = (school, id)  => `tripLogs/${school}_${id}`;

function ctx(uid, school, role) {
  return env.authenticatedContext(uid, { school_id: school, role, user_id: uid }).firestore();
}
const asDriver  = (uid = DRIVER, school = SCHOOL) => ctx(uid, school, 'Driver');
const asTeacher = (school = SCHOOL) => ctx(TEACHER, school, 'Teacher');
const asParent  = (uid, school = SCHOOL) => ctx(uid, school, 'Parent');
const asAnon    = () => env.unauthenticatedContext().firestore();

// Client metadata required by hasClientMetadata().
const META = { app_version: '1.0.0', client_platform: 'android', client_timestamp: 1 };

// A valid, in-window fix. Bounds: > stored, <= now+120s, >= now-300s.
const fix = (extra = {}) => ({
  lat: 26.51, lng: 80.23, speed_kmph: 34, heading: 180,
  accuracy_m: 8, recorded_at: new Date().toISOString(),
  recorded_at_ms: Date.now(), ...META, ...extra,
});

async function seedWorld() {
  await env.withSecurityRulesDisabled(async (c) => {
    const db = c.firestore();
    for (const s of [SCHOOL, OTHER]) {
      await db.doc(`schoolControl/${s}`).set({
        schoolId: s, lifecycle: { state: 'active', computedAt: Date.now(), reason: 'active' },
      });
      await db.doc(`schools/${s}`).set({ schoolId: s, name: `${s} Test` });
    }

    // Server-created live position (D-1): seeded by Transport_gps_service.
    // recorded_at_ms seeded at 0 so the first client fix of a session passes.
    await db.doc(livePos(SCHOOL, VEH)).set({
      schoolId: SCHOOL, vehicleId: VEH, tripId: TRIP,
      driver_staff_id: DRIVER, live_tracking_enabled: true,
      authorized_viewer_uids: [STUDENT_OK],
      recorded_at_ms: 0, lat: 0, lng: 0,
    });

    // Same vehicle doc in the other tenant, for cross-tenant probes.
    await db.doc(livePos(OTHER, VEH_OTHER)).set({
      schoolId: OTHER, vehicleId: VEH_OTHER, tripId: 'TRIP0009',
      driver_staff_id: OTHER_DRIVER, live_tracking_enabled: true,
      authorized_viewer_uids: [], recorded_at_ms: 0,
    });

    // A position whose kill switch is already off (D-2).
    await db.doc(livePos(SCHOOL, 'VEH_OFF')).set({
      schoolId: SCHOOL, vehicleId: 'VEH_OFF', tripId: TRIP,
      driver_staff_id: DRIVER, live_tracking_enabled: false,
      authorized_viewer_uids: [], recorded_at_ms: 0,
    });

    // A position with an already-recent fix, to test monotonic replay.
    await db.doc(livePos(SCHOOL, 'VEH_SEQ')).set({
      schoolId: SCHOOL, vehicleId: 'VEH_SEQ', tripId: TRIP,
      driver_staff_id: DRIVER, live_tracking_enabled: true,
      authorized_viewer_uids: [], recorded_at_ms: Date.now(),
    });

    // A pristine server-seeded doc that has NEVER carried client metadata,
    // used to test the metadata guard on the first write of a session. It
    // needs its own doc because hasClientMetadata() inspects the RESULTING
    // document, so any doc that has already taken one good write would keep
    // satisfying the check — see the stale-metadata test below.
    await db.doc(livePos(SCHOOL, 'VEH_META')).set({
      schoolId: SCHOOL, vehicleId: 'VEH_META', tripId: TRIP,
      driver_staff_id: DRIVER, live_tracking_enabled: true,
      authorized_viewer_uids: [], recorded_at_ms: 0,
    });

    await db.doc(crumb(SCHOOL, 'BC0001')).set({
      schoolId: SCHOOL, vehicleId: VEH, tripId: TRIP,
      driver_staff_id: DRIVER, lat: 26.5, lng: 80.2, recorded_at_ms: 1,
    });

    // tripLog for the D-8 allowlist tests.
    await db.doc(tripLog(SCHOOL, TRIP)).set({
      schoolId: SCHOOL, tripId: TRIP, driver_staff_id: DRIVER,
      status: 'InProgress', checkpoints: [], route_progress_pct: 0,
    });
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

// ────────────────────────────────────────────────────────────────
describe('vehicleLivePositions — read (who may watch a bus)', () => {
  test('same-school staff CAN read a live position', async () => {
    await assertSucceeds(asTeacher().doc(livePos(SCHOOL, VEH)).get());
  });

  test('an AUTHORIZED parent (onboard student) CAN read it', async () => {
    await assertSucceeds(asParent(STUDENT_OK).doc(livePos(SCHOOL, VEH)).get());
  });

  test('an UNAUTHORIZED parent (student not on this trip) CANNOT read it', async () => {
    await assertFails(asParent(STUDENT_NOT).doc(livePos(SCHOOL, VEH)).get());
  });

  test('cross-school staff CANNOT read another school\'s live position', async () => {
    await assertFails(asTeacher(OTHER).doc(livePos(SCHOOL, VEH)).get());
  });

  test('unauthenticated client CANNOT read a live position', async () => {
    await assertFails(asAnon().doc(livePos(SCHOOL, VEH)).get());
  });
});

describe('vehicleLivePositions — create/delete are server-only (D-1)', () => {
  test('a driver CANNOT create a live position doc', async () => {
    await assertFails(
      asDriver().doc(livePos(SCHOOL, 'VEH_NEW')).set({
        schoolId: SCHOOL, vehicleId: 'VEH_NEW', driver_staff_id: DRIVER,
        live_tracking_enabled: true, recorded_at_ms: Date.now(), ...META,
      })
    );
  });

  test('staff CANNOT delete a live position doc', async () => {
    await assertFails(asTeacher().doc(livePos(SCHOOL, VEH)).delete());
  });

  test('the assigned driver CANNOT delete their own live position doc', async () => {
    await assertFails(asDriver().doc(livePos(SCHOOL, VEH)).delete());
  });
});

describe('vehicleLivePositions — update is the real enforcement point', () => {
  test('the ASSIGNED driver CAN post a valid in-window fix', async () => {
    await assertSucceeds(asDriver().doc(livePos(SCHOOL, VEH)).update(fix()));
  });

  test('a DIFFERENT driver CANNOT post a fix for this vehicle', async () => {
    await assertFails(asDriver(OTHER_DRIVER).doc(livePos(SCHOOL, VEH)).update(fix()));
  });

  test('a non-driver staff member CANNOT post a fix', async () => {
    await assertFails(asTeacher().doc(livePos(SCHOOL, VEH)).update(fix()));
  });

  test('a parent CANNOT post a fix', async () => {
    await assertFails(asParent(STUDENT_OK).doc(livePos(SCHOOL, VEH)).update(fix()));
  });

  test('cross-school driver CANNOT post a fix to our vehicle', async () => {
    await assertFails(asDriver(DRIVER, OTHER).doc(livePos(SCHOOL, VEH)).update(fix()));
  });

  test('KILL SWITCH (D-2): no fix accepted once live_tracking_enabled is false', async () => {
    await assertFails(asDriver().doc(livePos(SCHOOL, 'VEH_OFF')).update(fix()));
  });

  test('REPLAY: a stale queued fix cannot overwrite a newer position', async () => {
    // The offline-flush case: a 20-minute-old fix must not move the bus backwards.
    await assertFails(
      asDriver().doc(livePos(SCHOOL, 'VEH_SEQ')).update(fix({ recorded_at_ms: Date.now() - 1200000 }))
    );
  });

  test('FUTURE CLOCK: a far-future fix is rejected', async () => {
    // Otherwise one skewed device could permanently block that vehicle.
    await assertFails(
      asDriver().doc(livePos(SCHOOL, VEH)).update(fix({ recorded_at_ms: Date.now() + 3600000 }))
    );
  });

  test('ANCIENT: a fix older than the 5-minute window is rejected', async () => {
    await assertFails(
      asDriver().doc(livePos(SCHOOL, VEH)).update(fix({ recorded_at_ms: Date.now() - 600000 }))
    );
  });

  test('missing client metadata is rejected on the first fix of a session', async () => {
    await assertFails(
      asDriver().doc(livePos(SCHOOL, 'VEH_META')).update({
        lat: 26.6, lng: 80.3, recorded_at_ms: Date.now(),
      })
    );
  });

  test('DOCUMENTED WEAKNESS: metadata is not re-validated on later fixes', async () => {
    // hasClientMetadata() inspects request.resource.data — on an update that is
    // the RESULTING document, so once one good write has stamped app_version /
    // client_platform / client_timestamp, later fixes may omit them and the
    // stale values keep satisfying the guard.
    //
    // Asserted as SUCCEEDS because that is the real current behaviour, and a
    // test that lies about it is worse than no test. Severity is low BY THE
    // RULE'S OWN DESIGN: the comment states these fields are "NOT used for
    // authz — they're operational telemetry". The consequence is therefore
    // stale diagnostics, not weakened authorization. Flagged in the readiness
    // report rather than silently "fixed" here, since changing a production
    // rule to satisfy a test is out of scope for a certification pass.
    await assertSucceeds(
      asDriver().doc(livePos(SCHOOL, VEH)).update({
        lat: 26.7, lng: 80.4, recorded_at_ms: Date.now(),
      })
    );
  });

  test('a field outside the allowlist cannot be written', async () => {
    await assertFails(
      asDriver().doc(livePos(SCHOOL, VEH)).update(fix({ authorized_viewer_uids: [STUDENT_NOT] }))
    );
  });

  test('the driver cannot flip their own kill switch back on', async () => {
    await assertFails(
      asDriver().doc(livePos(SCHOOL, 'VEH_OFF')).update(fix({ live_tracking_enabled: true }))
    );
  });
});

describe('vehicleBreadcrumbs — movement history is staff-only', () => {
  test('same-school staff CAN read a breadcrumb', async () => {
    await assertSucceeds(asTeacher().doc(crumb(SCHOOL, 'BC0001')).get());
  });

  test('an authorized parent CANNOT read breadcrumbs', async () => {
    // A trail is materially more sensitive than one current position.
    await assertFails(asParent(STUDENT_OK).doc(crumb(SCHOOL, 'BC0001')).get());
  });

  test('cross-school staff CANNOT read our breadcrumbs', async () => {
    await assertFails(asTeacher(OTHER).doc(crumb(SCHOOL, 'BC0001')).get());
  });

  test('a driver CAN append a breadcrumb attributed to themselves', async () => {
    await assertSucceeds(
      asDriver().doc(crumb(SCHOOL, 'BC_NEW_1')).set({
        schoolId: SCHOOL, vehicleId: VEH, tripId: TRIP,
        driver_staff_id: DRIVER, lat: 26.52, lng: 80.24,
        recorded_at_ms: Date.now(), ...META,
      })
    );
  });

  test('a driver CANNOT append a breadcrumb attributed to ANOTHER driver', async () => {
    await assertFails(
      asDriver().doc(crumb(SCHOOL, 'BC_SPOOF')).set({
        schoolId: SCHOOL, vehicleId: VEH, tripId: TRIP,
        driver_staff_id: OTHER_DRIVER, lat: 26.52, lng: 80.24,
        recorded_at_ms: Date.now(), ...META,
      })
    );
  });

  test('a driver CANNOT append a breadcrumb into another tenant', async () => {
    await assertFails(
      asDriver().doc(crumb(OTHER, 'BC_XT')).set({
        schoolId: OTHER, vehicleId: VEH_OTHER, driver_staff_id: DRIVER,
        lat: 1, lng: 1, recorded_at_ms: Date.now(), ...META,
      })
    );
  });

  test('a breadcrumb without client metadata is rejected', async () => {
    await assertFails(
      asDriver().doc(crumb(SCHOOL, 'BC_NOMETA')).set({
        schoolId: SCHOOL, vehicleId: VEH, driver_staff_id: DRIVER,
        lat: 1, lng: 1, recorded_at_ms: Date.now(),
      })
    );
  });

  test('a parent CANNOT create a breadcrumb', async () => {
    await assertFails(
      asParent(STUDENT_OK).doc(crumb(SCHOOL, 'BC_PARENT')).set({
        schoolId: SCHOOL, driver_staff_id: STUDENT_OK, lat: 1, lng: 1, ...META,
      })
    );
  });

  test('breadcrumbs are IMMUTABLE — no update', async () => {
    await assertFails(asDriver().doc(crumb(SCHOOL, 'BC0001')).update({ lat: 99 }));
  });

  test('breadcrumbs are IMMUTABLE — no delete', async () => {
    await assertFails(asDriver().doc(crumb(SCHOOL, 'BC0001')).delete());
  });
});

describe('tripLogs — D-8 ONE canonical lifecycle (previously untested)', () => {
  test('the driver CAN still mark a checkpoint', async () => {
    await assertSucceeds(
      asDriver().doc(tripLog(SCHOOL, TRIP)).update({
        checkpoints: [{ id: 'CP1', at: Date.now() }],
        route_progress_pct: 25, updated_at: new Date().toISOString(), ...META,
      })
    );
  });

  test('THE D-8 GUARANTEE: the driver CANNOT write trip status directly', async () => {
    // This is the enforcement behind "one canonical trip lifecycle": status
    // transitions must go through transport_driver_api → Transport_service, so
    // that re-validation, the CAS guard, audit, parent notification, route
    // denormalisation and the GPS session all run.
    await assertFails(
      asDriver().doc(tripLog(SCHOOL, TRIP)).update({
        status: 'Completed', updated_at: new Date().toISOString(), ...META,
      })
    );
  });

  test('the driver CANNOT write trip completion telemetry directly', async () => {
    await assertFails(
      asDriver().doc(tripLog(SCHOOL, TRIP)).update({
        actual_end_at: new Date().toISOString(), endOdometer: 12345,
        distance_km: 18.2, updated_at: new Date().toISOString(), ...META,
      })
    );
  });

  test('the driver CANNOT backdate the trip start', async () => {
    await assertFails(
      asDriver().doc(tripLog(SCHOOL, TRIP)).update({
        actual_start_at: new Date(Date.now() - 7200000).toISOString(), ...META,
      })
    );
  });

  /**
   * CLIENT-PAYLOAD COMPATIBILITY (pre-existing defect, found 2026-08-09).
   *
   * The allowlist is enforced with affectedKeys().hasOnly(), so it constrains
   * the ENTIRE key set — including diagnostic metadata. ClientMetadata.asMap()
   * emits two extras, device_sdk and device_model, which appear in NEITHER the
   * pre-D-8 allowlist nor the D-8 one. The Teacher app used asMap() on this
   * write, so checkpoint marking was rejected outright.
   *
   * These two tests pin both shapes so the fix (asMap() → required()) cannot
   * silently regress, and so anyone widening the allowlist has to decide about
   * the extras deliberately.
   */
  test('PAYLOAD: allowlisted fields + asMap() diagnostic extras are REJECTED', async () => {
    await assertFails(
      asDriver().doc(tripLog(SCHOOL, TRIP)).update({
        checkpoints: [{ stop_id: 'S2', checkpoint_state: 'Reached' }],
        route_progress_pct: 60, next_checkpoint_id: '',
        updated_at: new Date().toISOString(),
        ...META, device_sdk: 34, device_model: 'Pixel 7',
      })
    );
  });

  test('PAYLOAD: allowlisted fields + required() metadata are ACCEPTED', async () => {
    await assertSucceeds(
      asDriver().doc(tripLog(SCHOOL, TRIP)).update({
        checkpoints: [{ stop_id: 'S3', checkpoint_state: 'Reached' }],
        route_progress_pct: 75, next_checkpoint_id: '',
        updated_at: new Date().toISOString(), ...META,
      })
    );
  });
});
