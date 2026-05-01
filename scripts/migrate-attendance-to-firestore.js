#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Attendance → Firestore Backfill (Phase 7f)
 *
 *  Copies existing RTDB attendance data into the new Firestore
 *  collections introduced by Phase 7 (a–e):
 *
 *    Schools/{school}/{session}/Staff_Attendance/{Month YYYY}/{staffId}
 *      → staffAttendanceSummary/{schoolId}_{staffId}_{Month_YYYY}
 *      (also re-derives present/absent/leave/holiday/tardy/vacation)
 *
 *    Schools/{school}/Config/Devices/{deviceId}
 *      → attendanceDevices/{schoolId}_{deviceId}
 *      → attendanceDeviceKeys/{api_key_hash}
 *
 *    Schools/{school}/{session}/Attendance/Punch_Log/{date}/{pushId}
 *      → attendancePunches/{schoolId}_{pushId}
 *
 *  IDEMPOTENT — uses set(merge:true). NON-DESTRUCTIVE — RTDB stays
 *  intact and continues to receive mirror writes from the admin panel.
 *
 *  Student attendance is NOT migrated here — it was already
 *  Firestore-first prior to Phase 7 (see attendance + attendanceSummary
 *  collections, written by save_student_attendance / mark_student_day).
 *
 *  Usage:
 *    node scripts/migrate-attendance-to-firestore.js                # dry-run
 *    node scripts/migrate-attendance-to-firestore.js --apply        # write
 *    node scripts/migrate-attendance-to-firestore.js --school Demo  # one school
 *    node scripts/migrate-attendance-to-firestore.js --apply --only staff
 *    node scripts/migrate-attendance-to-firestore.js --apply --only devices
 *    node scripts/migrate-attendance-to-firestore.js --apply --only punches
 * ═══════════════════════════════════════════════════════════════════
 */

const admin = require('firebase-admin');
const path = require('path');

const SERVICE_ACCOUNT_PATH = path.resolve(
  __dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
);
const DATABASE_URL = 'https://graderadmin-default-rtdb.firebaseio.com/';

const args = process.argv.slice(2);
const DRY_RUN = !args.includes('--apply');
const FILTER_SCHOOL = args.includes('--school')
  ? args[args.indexOf('--school') + 1]
  : null;
const ONLY = args.includes('--only')
  ? args[args.indexOf('--only') + 1]
  : null;

const serviceAccount = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({
  credential: admin.credential.cert(serviceAccount),
  databaseURL: DATABASE_URL,
});

const rtdb = admin.database();
const fs = admin.firestore();

const FIXED   = '\x1b[32m✔ COPIED  \x1b[0m';
const SKIPPED = '\x1b[90m⊘ SKIPPED \x1b[0m';
const ERROR   = '\x1b[31m✖ ERROR   \x1b[0m';
const DRY     = '\x1b[35m⬡ DRY-RUN \x1b[0m';
const BOLD    = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM     = (s) => `\x1b[2m${s}\x1b[0m`;
const CYAN    = (s) => `\x1b[36m${s}\x1b[0m`;

const stats = {
  schools: 0,
  staffSummaries: 0,
  devices: 0,
  deviceKeys: 0,
  punches: 0,
  errors: 0,
};

// Resolve schoolId from RTDB key (matches PHP MY_Controller resolution).
async function resolveSchoolId(schoolKey) {
  // Try System/Schools/{key}.school_id, fall back to the key itself.
  try {
    const snap = await rtdb.ref(`System/Schools/${schoolKey}/school_id`).once('value');
    if (snap.exists()) return String(snap.val());
  } catch (_) {}
  return schoolKey;
}

async function listSchools() {
  if (FILTER_SCHOOL) return [FILTER_SCHOOL];
  const snap = await rtdb.ref('Schools').once('value');
  const out = [];
  snap.forEach((c) => out.push(c.key));
  return out;
}

async function getActiveSession(schoolKey) {
  const snap = await rtdb.ref(`Schools/${schoolKey}/Config/ActiveSession`).once('value');
  if (snap.exists()) return String(snap.val());
  // Fall back to the latest session under the school root
  const root = await rtdb.ref(`Schools/${schoolKey}`).once('value');
  if (!root.exists()) return null;
  const keys = [];
  root.forEach((c) => { if (/^\d{4}-\d{4}$/.test(c.key)) keys.push(c.key); });
  keys.sort();
  return keys.length ? keys[keys.length - 1] : null;
}

// ── Staff attendance summary backfill ─────────────────────────────
function countMarks(str) {
  const c = { P: 0, A: 0, L: 0, H: 0, T: 0, V: 0 };
  for (let i = 0; i < str.length; i++) {
    const ch = str[i];
    if (c[ch] !== undefined) c[ch]++;
  }
  return c;
}

async function migrateStaffSummariesForSchool(schoolKey, schoolId) {
  if (ONLY && ONLY !== 'staff') return;
  const session = await getActiveSession(schoolKey);
  if (!session) return;

  // Pre-load staff names
  const teachersSnap = await rtdb.ref(`Schools/${schoolKey}/${session}/Teachers`).once('value');
  const teachers = teachersSnap.exists() ? (teachersSnap.val() || {}) : {};

  const root = `Schools/${schoolKey}/${session}/Staff_Attendance`;
  const snap = await rtdb.ref(root).once('value');
  if (!snap.exists()) return;

  const all = snap.val() || {};
  for (const [monthKey, byStaff] of Object.entries(all)) {
    if (!byStaff || typeof byStaff !== 'object') continue;
    const monthSafe = monthKey.replace(/\s+/g, '_'); // "March 2026" → "March_2026"
    for (const [staffId, attStr] of Object.entries(byStaff)) {
      if (typeof attStr !== 'string') continue;
      const counts = countMarks(attStr);
      const totalDays = attStr.length;
      const workingDays = totalDays - counts.H - counts.V;
      const pct = workingDays > 0 ? (counts.P + counts.T) / workingDays * 100 : 0;

      const teacher = teachers[staffId] || {};
      const name = teacher.Name || teacher.name || '';

      const docId = `${schoolId}_${staffId}_${monthSafe}`;
      const doc = {
        schoolId,
        session,
        staffId,
        staffName: name,
        type: 'staff',
        month: monthKey,
        dayWise: attStr,
        present: counts.P,
        absent: counts.A,
        leave: counts.L,
        holiday: counts.H,
        tardy: counts.T,
        late: counts.T,           // legacy alias — drop in 7g
        vacation: counts.V,
        totalDays,
        workingDays,
        percentage: pct,
        updatedAt: new Date().toISOString(),
      };

      if (DRY_RUN) {
        console.log(`${DRY}  ${CYAN(schoolKey)} ${DIM('staffAttendanceSummary/')}${docId}`);
        stats.staffSummaries += 1;
        continue;
      }
      try {
        await fs.collection('staffAttendanceSummary').doc(docId).set(doc, { merge: true });
        stats.staffSummaries += 1;
      } catch (e) {
        console.error(`${ERROR}  staffAttendanceSummary/${docId}: ${e.message}`);
        stats.errors += 1;
      }
    }
  }
}

// ── Device backfill ───────────────────────────────────────────────
async function migrateDevicesForSchool(schoolKey, schoolId) {
  if (ONLY && ONLY !== 'devices') return;

  const devSnap = await rtdb.ref(`Schools/${schoolKey}/Config/Devices`).once('value');
  if (devSnap.exists()) {
    const all = devSnap.val() || {};
    for (const [deviceId, dev] of Object.entries(all)) {
      if (!dev || typeof dev !== 'object') continue;
      const docId = `${schoolId}_${deviceId}`;
      const doc = {
        schoolId,
        deviceId,
        name:        dev.name || '',
        type:        dev.type || 'unknown',
        location:    dev.location || '',
        status:      dev.status || 'inactive',
        apiKeyHash:  dev.api_key_hash || '',
        createdAt:   dev.created_at || '',
        lastPing:    dev.last_ping || '',
      };
      if (DRY_RUN) {
        console.log(`${DRY}  ${CYAN(schoolKey)} ${DIM('attendanceDevices/')}${docId}`);
        stats.devices += 1;
      } else {
        try {
          await fs.collection('attendanceDevices').doc(docId).set(doc, { merge: true });
          stats.devices += 1;
        } catch (e) {
          console.error(`${ERROR}  attendanceDevices/${docId}: ${e.message}`);
          stats.errors += 1;
        }
      }

      if (dev.api_key_hash) {
        const keyDoc = {
          keyHash:    dev.api_key_hash,
          deviceId,
          schoolId,
          schoolName: schoolKey,
          createdAt:  dev.created_at || '',
        };
        if (DRY_RUN) {
          console.log(`${DRY}  ${CYAN(schoolKey)} ${DIM('attendanceDeviceKeys/')}${dev.api_key_hash}`);
          stats.deviceKeys += 1;
        } else {
          try {
            await fs.collection('attendanceDeviceKeys').doc(dev.api_key_hash).set(keyDoc, { merge: true });
            stats.deviceKeys += 1;
          } catch (e) {
            console.error(`${ERROR}  attendanceDeviceKeys/${dev.api_key_hash}: ${e.message}`);
            stats.errors += 1;
          }
        }
      }
    }
  }
}

// ── Punch log backfill ────────────────────────────────────────────
async function migratePunchesForSchool(schoolKey, schoolId) {
  if (ONLY && ONLY !== 'punches') return;
  const session = await getActiveSession(schoolKey);
  if (!session) return;

  const root = `Schools/${schoolKey}/${session}/Attendance/Punch_Log`;
  const snap = await rtdb.ref(root).once('value');
  if (!snap.exists()) return;

  const all = snap.val() || {};
  for (const [date, byPush] of Object.entries(all)) {
    if (!byPush || typeof byPush !== 'object') continue;
    for (const [pushId, p] of Object.entries(byPush)) {
      if (!p || typeof p !== 'object') continue;
      const docId = `${schoolId}_${pushId}`;
      const doc = {
        ...p,
        schoolId,
        session,
        date,
        punchId: pushId,
        createdAt: p.punch_time || new Date().toISOString(),
      };
      if (DRY_RUN) {
        console.log(`${DRY}  ${CYAN(schoolKey)} ${DIM('attendancePunches/')}${docId}`);
        stats.punches += 1;
        continue;
      }
      try {
        await fs.collection('attendancePunches').doc(docId).set(doc, { merge: true });
        stats.punches += 1;
      } catch (e) {
        console.error(`${ERROR}  attendancePunches/${docId}: ${e.message}`);
        stats.errors += 1;
      }
    }
  }
}

async function migrateOneSchool(schoolKey) {
  const schoolId = await resolveSchoolId(schoolKey);
  const exists = await rtdb.ref(`Schools/${schoolKey}`).once('value');
  if (!exists.exists()) {
    console.log(`${SKIPPED} ${CYAN(schoolKey)} ${DIM('(missing)')}`);
    return;
  }
  stats.schools += 1;
  console.log(`\n${BOLD(schoolKey)} ${DIM(`(schoolId=${schoolId})`)}`);
  await migrateStaffSummariesForSchool(schoolKey, schoolId);
  await migrateDevicesForSchool(schoolKey, schoolId);
  await migratePunchesForSchool(schoolKey, schoolId);
}

async function main() {
  console.log(BOLD('━━━ Attendance Firestore Backfill (Phase 7f) ━━━'));
  console.log(`Mode:    ${DRY_RUN ? 'DRY-RUN (no writes)' : 'APPLY'}`);
  console.log(`School:  ${FILTER_SCHOOL || '(all)'}`);
  console.log(`Only:    ${ONLY || '(all: staff, devices, punches)'}\n`);

  const schools = await listSchools();
  for (const school of schools) {
    await migrateOneSchool(school);
  }

  console.log('\n' + BOLD('━━━ Summary ━━━'));
  console.log(`Schools scanned:        ${stats.schools}`);
  console.log(`Staff summaries:        ${stats.staffSummaries}`);
  console.log(`Devices:                ${stats.devices}`);
  console.log(`Device key indexes:     ${stats.deviceKeys}`);
  console.log(`Punches:                ${stats.punches}`);
  if (stats.errors) console.log(`\x1b[31mErrors: ${stats.errors}\x1b[0m`);

  if (DRY_RUN) {
    console.log('\n' + DIM('Re-run with --apply to actually write to Firestore.'));
  }
  process.exit(stats.errors ? 1 : 0);
}

main().catch((e) => {
  console.error('Fatal:', e);
  process.exit(1);
});
