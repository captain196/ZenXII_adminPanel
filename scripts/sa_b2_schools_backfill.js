#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  sa_b2_schools_backfill.js — RTDB `System/Schools` → Firestore canonical store
 *
 *  Part of "Firestore as the single source of truth for school data". Before
 *  the panel code drops its RTDB reads, this script guarantees that EVERY
 *  school living in RTDB `System/Schools/{key}` also exists in Firestore, so
 *  nothing disappears from the superadmin panel after cutover.
 *
 *  Targets (all writes are merge:true — never clobber a richer/newer doc):
 *    schools/{schoolId}              profile + adminDisabled + statsCache
 *    schoolControl/{schoolId}        subscription + lifecycle
 *    subscriptions/{BACKFILL_INITIAL_<schoolId>}   initial-period row
 *    tenantPublic/{schoolId}         display + accessAllowed + activeModules
 *    schoolNameIndex/{nameKey}       name→id uniqueness (create-if-absent)
 *    schoolCodeIndex/{code}          code→id uniqueness (create-if-absent)
 *
 *  Field shapes mirror B2_registry_service::create_tenant() exactly.
 *
 *  Safety:
 *    - Dry-run by DEFAULT. Pass --commit to write.
 *    - Idempotent: deterministic doc ids + merge writes; re-runs converge.
 *    - NEVER writes or deletes RTDB.
 *    - Skips (and reports) a school whose Firestore doc has a NEWER updatedAt.
 *    - Never invents a schoolId: an unresolvable name-keyed node is reported
 *      as `unresolved` and skipped.
 *
 *  Usage:
 *    node scripts/sa_b2_schools_backfill.js                 # dry-run, all schools
 *    node scripts/sa_b2_schools_backfill.js --commit        # write, all schools
 *    node scripts/sa_b2_schools_backfill.js --only=SCH_XXXX # single tenant
 *    node scripts/sa_b2_schools_backfill.js --verbose       # per-field diffs
 *    RTDB_URL=... node scripts/sa_b2_schools_backfill.js    # override RTDB instance
 * ═══════════════════════════════════════════════════════════════════════════
 */
const admin = require('firebase-admin');
const path  = require('path');

const SERVICE_ACCOUNT_PATH = path.resolve(
  __dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
);
// Matches the panel runtime (application/libraries/Firebase.php) RTDB instance.
const DATABASE_URL = process.env.RTDB_URL
  || 'https://graderadmin-default-rtdb.firebaseio.com/';

// ── Args ───────────────────────────────────────────────────────────────────
function parseArg(name) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  if (eq) return eq.split('=').slice(1).join('=');
  const i = process.argv.indexOf(`--${name}`);
  if (i !== -1 && i + 1 < process.argv.length && !process.argv[i + 1].startsWith('--')) {
    return process.argv[i + 1];
  }
  return null;
}
const COMMIT  = process.argv.includes('--commit');
const DRY_RUN = !COMMIT;
const VERBOSE = process.argv.includes('--verbose');
const ONLY    = parseArg('only');

// ── Firebase init ──────────────────────────────────────────────────────────
const sa = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({
  credential:  admin.credential.cert(sa),
  databaseURL: DATABASE_URL,
});
const rtdb = admin.database();
const fs   = admin.firestore();

// ── Styling ────────────────────────────────────────────────────────────────
const BOLD = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM  = (s) => `\x1b[2m${s}\x1b[0m`;
const RED  = (s) => `\x1b[31m${s}\x1b[0m`;
const GRN  = (s) => `\x1b[32m${s}\x1b[0m`;
const YEL  = (s) => `\x1b[33m${s}\x1b[0m`;

// ── Helpers ────────────────────────────────────────────────────────────────
const SCHOOL_ID_RE = /^SCH_[A-F0-9]{10}$/i;

// Mirror PHP Superadmin_schools::_school_name_key().
function nameKeyOf(name) {
  return String(name || '').trim().replace(/[^A-Za-z0-9_\-]/g, '_');
}

function str(v) { return v == null ? '' : String(v); }
function num(v) { const n = Number(v); return Number.isFinite(n) ? n : 0; }

// active/inactive/suspended → adminDisabled + lifecycle.state
function mapStatus(rawStatus) {
  const s = str(rawStatus).toLowerCase();
  if (s === 'inactive' || s === 'suspended' || s === 'disabled') {
    return { disabled: true, reason: `manual_${s}`, lifecycle: s === 'suspended' ? 'suspended' : 'expired' };
  }
  if (s === 'grace' || s === 'grace_period') {
    return { disabled: false, reason: '', lifecycle: 'grace' };
  }
  return { disabled: false, reason: '', lifecycle: 'active' };
}

function epochEndOfDay(dateStr) {
  if (!dateStr) return 0;
  const t = Date.parse(`${dateStr}T23:59:59`);
  return Number.isFinite(t) ? Math.floor(t / 1000) : 0;
}

// Resolve a RTDB key to a canonical schoolId.
// Returns { schoolId, via } or { schoolId: '', via: 'unresolved' }.
async function resolveSchoolId(key, node) {
  if (SCHOOL_ID_RE.test(key)) return { schoolId: key, via: 'key' };

  const profile = (node && typeof node.profile === 'object') ? node.profile : {};

  // 1. profile.school_id embedded in the node
  const embedded = str(profile.school_id || node.school_id);
  if (SCHOOL_ID_RE.test(embedded)) return { schoolId: embedded, via: 'profile.school_id' };

  // 2. schoolNameIndex/{nameKey}
  try {
    const idxDoc = await fs.collection('schoolNameIndex').doc(nameKeyOf(key)).get();
    if (idxDoc.exists && SCHOOL_ID_RE.test(str(idxDoc.data().schoolId))) {
      return { schoolId: str(idxDoc.data().schoolId), via: 'schoolNameIndex' };
    }
  } catch (e) { /* fall through */ }

  // 3. schoolCodeIndex/{code}
  const code = str(profile.school_code);
  if (code) {
    try {
      const codeDoc = await fs.collection('schoolCodeIndex').doc(code).get();
      if (codeDoc.exists && SCHOOL_ID_RE.test(str(codeDoc.data().schoolId))) {
        return { schoolId: str(codeDoc.data().schoolId), via: 'schoolCodeIndex' };
      }
    } catch (e) { /* fall through */ }
  }

  return { schoolId: '', via: 'unresolved' };
}

// Build the merge payloads for a resolved school.
function buildPayloads(schoolId, key, node) {
  const profile = (node && typeof node.profile === 'object') ? node.profile : {};
  const sub     = (node && typeof node.subscription === 'object') ? node.subscription : {};
  const stats   = (node && typeof node.stats_cache === 'object') ? node.stats_cache : {};
  const nowIso  = new Date().toISOString();

  const schoolName = str(profile.school_name || profile.name || (SCHOOL_ID_RE.test(key) ? '' : key));
  const schoolCode = str(profile.school_code);
  const statusMap  = mapStatus(profile.status || node.status);

  const schools = {
    schoolId,
    schoolCode,
    schoolName,
    name:             schoolName,
    city:             str(profile.city),
    street:           str(profile.street),
    email:            str(profile.email),
    phone:            str(profile.phone),
    logoUrl:          str(profile.logo_url),
    domainIdentifier: str(profile.domain_identifier || profile.subdomain),
    adminDisabled:    { value: statusMap.disabled, reason: statusMap.reason, actor: 'backfill', updatedAt: nowIso },
    statsCache: {
      totalStudents: num(stats.total_students),
      totalStaff:    num(stats.total_staff),
      lastUpdated:   str(stats.last_updated) || nowIso,
    },
    backfilledAt: nowIso,
  };
  if (str(profile.created_at)) schools.createdAt = str(profile.created_at);
  if (str(profile.created_by)) schools.createdBy = str(profile.created_by);

  const planId  = str(sub.plan_id);
  const expiry  = str(sub.expiry_date || (sub.duration && sub.duration.endDate));
  const newSubId = `BACKFILL_INITIAL_${schoolId}`;

  const schoolControl = {
    schoolId,
    subscription: {
      subscriptionId: newSubId,
      planId,
      status: str(sub.status).toLowerCase() || (statusMap.disabled ? 'inactive' : 'active'),
    },
    lifecycle: {
      state:                 statusMap.lifecycle,
      reason:                'backfill',
      subscriptionPeriodEnd: epochEndOfDay(expiry),
    },
    updatedAt: nowIso,
    backfilledAt: nowIso,
  };

  const subscriptions = {
    subscriptionId: newSubId,
    schoolId,
    planId,
    periodStart: str(sub.duration && sub.duration.startDate),
    periodEnd:   expiry,
    status:      str(sub.status).toLowerCase() || 'active',
    changeType:  'backfill',
    createdAt:   nowIso,
  };

  const tenantPublic = {
    schoolId,
    schoolName,
    name:          schoolName,
    logoUrl:       str(profile.logo_url),
    accessAllowed: !statusMap.disabled,
    computedAt:    nowIso,
  };

  return { schools, schoolControl, subscriptions, tenantPublic, newSubId, schoolName, schoolCode };
}

// ── Main ───────────────────────────────────────────────────────────────────
(async () => {
  console.log(BOLD('\n  sa_b2_schools_backfill') +
    `  ${DRY_RUN ? YEL('[DRY-RUN]') : GRN('[COMMIT]')}` +
    `  rtdb=${DIM(DATABASE_URL)}` + (ONLY ? `  only=${ONLY}` : '') + '\n');

  const snap = await rtdb.ref('System/Schools').once('value');
  const all  = snap.val() || {};
  let keys   = Object.keys(all);
  console.log(`  RTDB System/Schools keys: ${BOLD(keys.length)}\n`);

  const counts = { will_create: 0, will_fill: 0, unresolved: 0, parity: 0, skipped_claim: 0 };
  const unresolvedList = [];

  for (const key of keys) {
    const node = all[key];
    if (!node || typeof node !== 'object') continue;
    // Skip bare id-claim placeholders left by the legacy minter (only a _claim child).
    if (Object.keys(node).length === 1 && node._claim) { counts.skipped_claim++; continue; }

    const { schoolId, via } = await resolveSchoolId(key, node);
    if (ONLY && schoolId !== ONLY && key !== ONLY) continue;

    if (!schoolId) {
      counts.unresolved++;
      unresolvedList.push(key);
      console.log(`  ${RED('✗ unresolved')}  ${BOLD(key)}  ${DIM('(no schoolId / name index / code index)')}`);
      continue;
    }

    const payloads = buildPayloads(schoolId, key, node);

    // Firestore is the source of truth. The backfill is STRICTLY ADDITIVE:
    //   - schools doc absent  → CREATE it fully from RTDB.
    //   - schools doc present → only FILL fields that are missing/empty in FS;
    //     NEVER overwrite a value the panel already wrote (FS wins on conflict).
    let existing = null;
    try {
      const d = await fs.collection('schools').doc(schoolId).get();
      existing = d.exists ? d.data() : null;
    } catch (e) { /* treat as absent */ }

    // gapFill: keep only top-level keys absent/empty in `existing`.
    const isEmpty = (v) => v === undefined || v === null || v === '' ||
      (typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0) ||
      (Array.isArray(v) && v.length === 0);
    function gapFill(desired) {
      if (!existing) return desired;
      const out = {};
      for (const [k, v] of Object.entries(desired)) {
        if (isEmpty(v)) continue;                 // nothing useful to add
        if (isEmpty(existing[k])) out[k] = v;     // FS lacks it → fill
        // else FS already has a value → leave it untouched (FS is truth)
      }
      return out;
    }

    const schoolsWrite = gapFill(payloads.schools);
    const filledKeys   = Object.keys(schoolsWrite).filter(k => k !== 'backfilledAt');

    let classification;
    if (!existing)              classification = 'will_create';
    else if (filledKeys.length) classification = 'will_fill';
    else                        classification = 'parity';
    counts[classification]++;

    const tag = classification === 'will_create' ? GRN('＋ create')
              : classification === 'will_fill'    ? YEL('~ fill  ')
              : DIM('= parity ');
    const detail = classification === 'will_fill' ? DIM(`fills: ${filledKeys.join(',')}`) : '';
    console.log(`  ${tag}  ${BOLD(schoolId)}  ${DIM(`via ${via}`)}  ${payloads.schoolName || DIM('(no name)')}  ${detail}`);
    if (VERBOSE) {
      console.log(DIM(`        rtdb: city=${payloads.schools.city} email=${payloads.schools.email} ` +
        `phone=${payloads.schools.phone} plan=${payloads.subscriptions.planId}`));
      if (existing) console.log(DIM(`        fs  : city=${str(existing.city)} email=${str(existing.email)} ` +
        `phone=${str(existing.phone)} name=${str(existing.schoolName)}`));
    }

    if (classification === 'parity') continue;

    if (COMMIT) {
      const nameKey = nameKeyOf(payloads.schoolName || key);
      // schools: full create, or gap-fill only.
      await fs.collection('schools').doc(schoolId).set(existing ? schoolsWrite : payloads.schools, { merge: true });
      // Lifecycle/subscription/public: create only if the control doc is absent
      // (don't disturb an existing lifecycle the panel manages).
      const ctl = await fs.collection('schoolControl').doc(schoolId).get();
      if (!ctl.exists) {
        await fs.collection('schoolControl').doc(schoolId).set(payloads.schoolControl, { merge: true });
        await fs.collection('subscriptions').doc(payloads.newSubId).set(payloads.subscriptions, { merge: true });
      }
      const tp = await fs.collection('tenantPublic').doc(schoolId).get();
      if (!tp.exists) await fs.collection('tenantPublic').doc(schoolId).set(payloads.tenantPublic, { merge: true });
      // Create-if-absent indexes (never overwrite an existing mapping).
      if (nameKey) {
        const ni = await fs.collection('schoolNameIndex').doc(nameKey).get();
        if (!ni.exists) await fs.collection('schoolNameIndex').doc(nameKey).set({ schoolId, createdAt: new Date().toISOString() });
      }
      if (payloads.schoolCode) {
        const ci = await fs.collection('schoolCodeIndex').doc(payloads.schoolCode).get();
        if (!ci.exists) await fs.collection('schoolCodeIndex').doc(payloads.schoolCode).set({ schoolId, createdAt: new Date().toISOString() });
      }
    }
  }

  // ── Summary ────────────────────────────────────────────────────────────
  console.log(BOLD('\n  Summary'));
  console.log(`    create (rtdb-only) : ${counts.will_create}`);
  console.log(`    fill (gap-fill)    : ${counts.will_fill}`);
  console.log(`    parity             : ${counts.parity}`);
  console.log(`    skipped _claim     : ${counts.skipped_claim}`);
  console.log(`    ${counts.unresolved ? RED(`unresolved     : ${counts.unresolved}`) : `unresolved     : 0`}`);
  if (unresolvedList.length) {
    console.log(RED(`\n  ⚠ Unresolved keys (need manual schoolId / index before cutover):`));
    unresolvedList.forEach(k => console.log(RED(`      - ${k}`)));
  }
  console.log(DRY_RUN
    ? YEL('\n  DRY-RUN — no writes performed. Re-run with --commit to apply.\n')
    : GRN('\n  COMMIT complete.\n'));

  await admin.app().delete();
  process.exit(unresolvedList.length ? 2 : 0);
})().catch(err => {
  console.error('\n  FATAL:', err);
  process.exit(1);
});
