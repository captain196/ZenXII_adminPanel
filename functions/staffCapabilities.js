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

// SINGLE SOURCE: rbac_modules.json (bundled beside this file), the SAME catalogue
// the PHP resolver (rbac_helper.php) loads — so the CF caps mirror and the PHP
// resolver can never drift (that drift once lost Device Management/Message Monitor
// on the app side). Emergency inline fallback only if the JSON is unreadable.
let RBAC_MODULES;
try {
  RBAC_MODULES = require('./rbac_modules.json').modules;
  if (!Array.isArray(RBAC_MODULES) || RBAC_MODULES.length === 0) throw new Error('empty catalogue');
} catch (e) {
  logger.error('staffCapabilities: rbac_modules.json unreadable — using inline emergency fallback', e);
  RBAC_MODULES = [
    'SIS', 'Fees', 'Accounting', 'Attendance', 'Examinations', 'Results', 'LMS',
    'Certificates', 'HR', 'Events', 'Communication', 'Operations', 'Library',
    'Transport', 'Hostel', 'Inventory', 'Assets', 'Academic', 'Reports',
    'Configuration', 'Admin Users', 'Stories', 'Homework', 'Red Flags',
    'Device Management', 'Message Monitor',
  ];
}
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
function resolveCapabilities(staffRoles, roleRefs, legacyRoles) {
  // Admin-tier (drives staffCapabilities.admin → rules isAdmin() for folded
  // admins whose token role is now a nominal label): true if any role is a
  // bypass label, the ROLE_ADMIN id, or a role whose catalogue label is a bypass.
  let isAdmin = false;

  for (const r of roleRefs) {
    if (BYPASS_ROLES.has(r)) {
      const levels = {};
      RBAC_MODULES.forEach((m) => { levels[m] = 'manage'; });
      return { modules: [...RBAC_MODULES], levels, admin: true };
    }
  }

  const modules = {};
  const levels = {};
  for (const ref of roleRefs) {
    if (ref === 'ROLE_ADMIN') isAdmin = true;
    let role = staffRoles[ref] || null;      // match by ROLE_* id
    if (!role) {                             // else by label
      for (const rid of Object.keys(staffRoles)) {
        const rr = staffRoles[rid];
        if (rr && String(rr.label || '') === ref) { role = rr; break; }
      }
    }
    if (!role) {
      // FALLBACK: legacy schools.roles[label] (read-only) for un-folded tenants —
      // mirrors PHP resolve_role_access(); legacy carries no level signal → 'manage'.
      const legacy = legacyRoles && legacyRoles[ref];
      if (legacy && Array.isArray(legacy.permissions)) {
        for (const m of legacy.permissions) {
          if (!MODULE_SET.has(m)) continue;
          modules[m] = true;
          if (!levels[m]) levels[m] = 'manage';
        }
      }
      continue;
    }
    if (BYPASS_ROLES.has(String(role.label || ''))) isAdmin = true;

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
  return { modules: Object.keys(modules), levels, admin: isAdmin };
}

/**
 * Apply a staff member's PER-PERSON overrides (Unified Staff Access, tab 3) on top
 * of role-resolved caps. Mirrors PHP apply_access_overrides(): extra grants add a
 * module (raising level), deny removes it absolutely (wins over roles + extra).
 *   extra: { module: {level} }   deny: { module: {...} } | [module,...]
 */
function applyOverrides(caps, extra, deny) {
  const mods = new Set(caps.modules);
  const levels = Object.assign({}, caps.levels);
  for (const m of Object.keys(extra || {})) {
    if (!MODULE_SET.has(m)) continue;
    const lv = (extra[m] && extra[m].level) ? String(extra[m].level) : 'view';
    mods.add(m);
    levels[m] = levels[m] ? maxLevel(levels[m], lv) : lv;
  }
  const denyKeys = Array.isArray(deny) ? deny.map(String) : Object.keys(deny || {});
  for (const m of denyKeys) { mods.delete(m); delete levels[m]; }
  return { modules: [...mods], levels };
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
  const schoolData = (schoolSnap.exists && schoolSnap.data()) ? schoolSnap.data() : {};
  const staffRoles = (typeof schoolData.staffRoles === 'object' && schoolData.staffRoles) ? schoolData.staffRoles : {};
  const legacyRoles = (typeof schoolData.roles === 'object' && schoolData.roles) ? schoolData.roles : {};

  const base = resolveCapabilities(staffRoles, roleRefs, legacyRoles);
  // Per-person overrides (Unified Staff Access tab 3) — extra grants + absolute denies.
  const extra = (staff.extra && typeof staff.extra === 'object') ? staff.extra : {};
  const deny  = (staff.deny  && typeof staff.deny  === 'object') ? staff.deny  : {};
  const eff = applyOverrides({ modules: base.modules, levels: base.levels }, extra, deny);
  await db.collection('staffCapabilities').doc(staffUid).set({
    schoolId,
    modules: eff.modules,
    levels: eff.levels,
    admin: base.admin, // rules isAdmin() OR-arm for folded admins (nominal role claim)
    updatedAt: admin.firestore.FieldValue.serverTimestamp(),
  });
  return eff.modules;
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

    // Capability-relevant fields: roles AND per-person overrides (extra/deny). The
    // guard MUST include extra/deny — a ⛔ Prohibit or extra grant writes only those
    // fields, and if they were excluded the caps doc (which backs BOTH the app and
    // the Firestore rules) would never rebuild, leaving a revocation silently
    // ineffective until an unrelated role edit happened to fire this trigger.
    const relBefore = JSON.stringify([before.staff_roles || null, before.primary_role || null, before.role || null, before.extra || null, before.deny || null]);
    const relAfter = JSON.stringify([after.staff_roles || null, after.primary_role || null, after.role || null, after.extra || null, after.deny || null]);
    if (relBefore === relAfter) return; // no capability-relevant change

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
    // M4: cover BOTH tenant keyings. Some staff docs carry camel `schoolId`, others
    // only snake `school_id` (or just the `{schoolId}_` doc-id prefix). A single
    // `schoolId ==` filter silently skips the snake-only docs, so a catalogue change
    // never refreshes their caps → stale-grant window. Query both, then dedupe by
    // doc id so a doc carrying both keys isn't rebuilt twice.
    const [camelQ, snakeQ] = await Promise.all([
      db.collection('staff').where('schoolId', '==', schoolId).get(),
      db.collection('staff').where('school_id', '==', schoolId).get(),
    ]);
    const staffDocs = new Map();
    for (const doc of camelQ.docs) staffDocs.set(doc.id, doc);
    for (const doc of snakeQ.docs) staffDocs.set(doc.id, doc);
    logger.info(`[staff-caps] school-roles-change ${schoolId} → rebuilding ${staffDocs.size} staff`);
    for (const doc of staffDocs.values()) {
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
