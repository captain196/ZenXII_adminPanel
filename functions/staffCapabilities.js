/**
 * Staff capabilities index — Unified Staff RBAC (Phase A6).
 *
 * A SERVER-maintained entitlement doc that the Firestore rules consult via get()
 * to enforce per-module capability on client (app) writes — the staff-side analog
 * of storyAudience. Mirrors the PHP resolver resolve_role_access()
 * (application/helpers/rbac_helper.php): the UNION over staff.staff_roles[], with
 * the MAX level per module and permissionLevels defaulting to 'manage'.
 *
 *   staffCapabilities/{staffUid} = {
 *     schoolId,
 *     modules: ["Attendance","Stories", ...],   // rules: .modules.hasAny([module])
 *     levels:  {"Attendance":"edit", ...},        // for level-aware rules (later)
 *     updatedAt
 *   }
 *
 * Keyed by the staff member's Firebase uid (== staffId under synthetic-email
 * auth), which is exactly what rules compare to request.auth.uid. NEVER
 * client-writable (rules: allow write: if false).
 *
 * Write paths:
 *   1. refreshStaffCapabilities — staff-app callable (login / app-start), own auth.
 *   2. onStaffRolesChanged      — rebuild when a staff doc's roles change.
 *   3. onSchoolRolesChanged     — rebuild all staff in a school when its staffRoles
 *                                 catalogue (permissions / permissionLevels) changes.
 *
 * NOTE: RBAC_MODULES / LEVEL_RANK / BYPASS_ROLES below MUST stay in sync with
 * application/helpers/rbac_helper.php.
 */

const admin = require('firebase-admin');
const { onCall, HttpsError } = require('firebase-functions/v2/https');
const { onDocumentWritten } = require('firebase-functions/v2/firestore');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();
const REGION = 'us-central1';

// Keep in sync with rbac_helper.php.
const RBAC_MODULES = [
  'SIS', 'Fees', 'Accounting', 'Attendance', 'Examinations', 'Results', 'LMS',
  'Certificates', 'HR', 'Events', 'Communication', 'Operations', 'Library',
  'Transport', 'Hostel', 'Inventory', 'Assets', 'Academic', 'Reports',
  'Configuration', 'Admin Users', 'Stories', 'Homework', 'Red Flags',
];
const MODULE_SET = new Set(RBAC_MODULES);
const LEVEL_RANK = { view: 1, edit: 2, manage: 3 };
const BYPASS_ROLES = new Set(['Super Admin', 'School Super Admin', 'Admin']);

function maxLevel(a, b) {
  return (LEVEL_RANK[a] || 0) >= (LEVEL_RANK[b] || 0) ? (a || b) : b;
}

/**
 * Union of {modules, levels} for a set of role refs (labels OR ROLE_* ids)
 * against a school's staffRoles catalogue. Mirrors PHP resolve_role_access().
 */
function resolveCapabilities(staffRoles, roleRefs) {
  for (const r of roleRefs) {
    if (BYPASS_ROLES.has(r)) {
      const levels = {};
      RBAC_MODULES.forEach((m) => { levels[m] = 'manage'; });
      return { modules: [...RBAC_MODULES], levels };
    }
  }

  const modules = {};
  const levels = {};
  for (const ref of roleRefs) {
    let role = staffRoles[ref] || null;      // match by ROLE_* id
    if (!role) {                             // else by label
      for (const rid of Object.keys(staffRoles)) {
        const rr = staffRoles[rid];
        if (rr && String(rr.label || '') === ref) { role = rr; break; }
      }
    }
    if (!role) continue;

    const perms = Array.isArray(role.permissions) ? role.permissions : [];
    const plvls = (role.permissionLevels && typeof role.permissionLevels === 'object')
      ? role.permissionLevels : {};
    for (const m of perms) {
      if (!MODULE_SET.has(m)) continue;
      modules[m] = true;
      const lvl = LEVEL_RANK[plvls[m]] ? plvls[m] : 'manage';
      levels[m] = levels[m] ? maxLevel(levels[m], lvl) : lvl;
    }
  }
  return { modules: Object.keys(modules), levels };
}

/** Rebuild one staff member's capability doc. Returns the module list or null. */
async function rebuildForStaff(schoolId, staffUid) {
  const staffSnap = await db.collection('staff').doc(`${schoolId}_${staffUid}`).get();
  if (!staffSnap.exists) return null;
  const staff = staffSnap.data() || {};
  if (String(staff.schoolId || staff.school_id || '') !== schoolId) return null; // tenant guard

  let roleRefs = Array.isArray(staff.staff_roles) ? staff.staff_roles.map(String).filter(Boolean) : [];
  if (staff.primary_role) roleRefs.push(String(staff.primary_role));
  if (staff.role) roleRefs.push(String(staff.role)); // label — bypass / legacy match
  roleRefs = [...new Set(roleRefs.filter(Boolean))];

  const schoolSnap = await db.collection('schools').doc(schoolId).get();
  const staffRoles = (schoolSnap.exists && schoolSnap.data()
    && typeof schoolSnap.data().staffRoles === 'object' && schoolSnap.data().staffRoles)
    ? schoolSnap.data().staffRoles : {};

  const { modules, levels } = resolveCapabilities(staffRoles, roleRefs);
  await db.collection('staffCapabilities').doc(staffUid).set({
    schoolId,
    modules,
    levels,
    updatedAt: admin.firestore.FieldValue.serverTimestamp(),
  });
  return modules;
}

// ─── Path 1: staff-app callable ────────────────────────────────────
exports.refreshStaffCapabilities = onCall({ region: REGION }, async (request) => {
  const auth = request.auth;
  if (!auth) throw new HttpsError('unauthenticated', 'Sign-in required.');
  const uid = auth.uid;
  const token = auth.token || {};
  const schoolId = String(token.school_id || token.schoolId || '');
  if (!schoolId) throw new HttpsError('failed-precondition', 'No school in token.');

  const modules = await rebuildForStaff(schoolId, uid);
  if (modules === null) throw new HttpsError('not-found', 'Staff record not found.');
  logger.info(`[staff-caps] refresh ${uid} → [${modules.join(',')}]`);
  return { modules };
});

// ─── Path 2: rebuild on a staff doc's role change ──────────────────
exports.onStaffRolesChanged = onDocumentWritten(
  { document: 'staff/{docId}', region: REGION },
  async (event) => {
    const after = event.data && event.data.after && event.data.after.exists ? event.data.after.data() : null;
    const before = event.data && event.data.before && event.data.before.exists ? event.data.before.data() : {};
    if (!after) return; // deletion — stale caps age out / next refresh fixes

    const rolesBefore = JSON.stringify([before.staff_roles || null, before.primary_role || null, before.role || null]);
    const rolesAfter = JSON.stringify([after.staff_roles || null, after.primary_role || null, after.role || null]);
    if (rolesBefore === rolesAfter) return; // no role-relevant change

    const schoolId = String(after.schoolId || after.school_id || '');
    const staffUid = String(after.staffId || after.userId || '');
    if (!schoolId || !staffUid) return;

    const modules = await rebuildForStaff(schoolId, staffUid);
    logger.info(`[staff-caps] staff-change ${staffUid} → [${(modules || []).join(',')}]`);
  }
);

// ─── Path 3: rebuild all staff when the school's role catalogue changes ──
exports.onSchoolRolesChanged = onDocumentWritten(
  { document: 'schools/{schoolId}', region: REGION },
  async (event) => {
    const after = event.data && event.data.after && event.data.after.exists ? event.data.after.data() : null;
    const before = event.data && event.data.before && event.data.before.exists ? event.data.before.data() : {};
    if (!after) return;
    if (JSON.stringify(before.staffRoles || null) === JSON.stringify(after.staffRoles || null)) return;

    const schoolId = event.params.schoolId;
    const q = await db.collection('staff').where('schoolId', '==', schoolId).get();
    logger.info(`[staff-caps] school-roles-change ${schoolId} → rebuilding ${q.size} staff`);
    for (const doc of q.docs) {
      const staff = doc.data() || {};
      const staffUid = String(staff.staffId || staff.userId || '');
      if (!staffUid) continue;
      try {
        await rebuildForStaff(schoolId, staffUid);
      } catch (e) {
        logger.warn(`[staff-caps] rebuild ${staffUid} failed: ${e.message}`);
      }
    }
  }
);
