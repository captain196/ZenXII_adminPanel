#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  migrate_student_entitlements.js — Student Entitlements, Phase B
 *
 *  Maps the legacy per-student concession records into the unified
 *  `studentEntitlements` log defined in
 *  STUDENT_ENTITLEMENTS_ARCHITECTURE_BASELINE.md §Data Model.
 *
 *  Phase B is ADDITIVE AND PARALLEL. Nothing generates fees from
 *  `studentEntitlements` yet — that is Phase C (concessions) and Phase D
 *  (transport/hostel services). Running this with --commit does NOT change
 *  a single rupee of billing; it only populates the new log so Phase C has
 *  data to read and shadow-compare against legacy.
 *
 *  SCOPE — deliberately narrow, per the frozen baseline §Migration Strategy:
 *    • studentConcessions  → studentEntitlements (kind='concession')  [MAPPED]
 *    • services (transport/hostel/meals)                              [GREENFIELD —
 *      intentionally NOT imported. Baseline: "import-first for concessions,
 *      greenfield for services." Service entitlements are created fresh by
 *      Phase D; back-importing studentServiceEnrollments here would
 *      manufacture history that the billing engine never actually used.]
 *    • scholarshipAwards / exemptedFees / discountHeads               [REPORTED
 *      ONLY, never written. These are the "Family B" legacy sources; the
 *      baseline (§Migration Strategy) records their migration as an explicit
 *      OPEN DECISION, not a settled mapping. This script COUNTS them so the
 *      decision can be made against real numbers, and refuses to guess.]
 *
 *  SAFETY
 *    • DRY-RUN BY DEFAULT. Writes require --commit. (Repo convention.)
 *    • IDEMPOTENT. The target doc id is derived deterministically from the
 *      source concession id, so re-running never creates a second entitlement
 *      for the same source record. Re-running after a partial failure is safe.
 *    • NON-DESTRUCTIVE. Never deletes, never mutates studentConcessions.
 *      The legacy collection remains the live source for Phase C's shadow
 *      comparison; it is retired only after that comparison passes.
 *    • TENANT-SCOPED. Every read and write is filtered/stamped with schoolId.
 *
 *  USAGE
 *    node scripts/migrate_student_entitlements.js                     # DRY-RUN, all schools
 *    node scripts/migrate_student_entitlements.js --school=SCH_XXXX   # DRY-RUN, one tenant
 *    node scripts/migrate_student_entitlements.js --commit            # WRITE
 *    node scripts/migrate_student_entitlements.js --school=SCH_XXXX --commit
 * ═══════════════════════════════════════════════════════════════════════════
 */
const admin = require('firebase-admin');
const path  = require('path');

// ── PHASE 2 / F1 — REQUIRING THIS FILE MUST HAVE NO SIDE EFFECTS ────────────
// The pure mapping helpers (mapScope / VALUE_TYPE_MAP) are now unit-verified by
// tests/Unit/Phase2EntitlementSemanticsTest.php, which `require`s this module to
// execute the REAL mapping rather than a copy of it. That is only safe if a bare
// require reads no credentials, creates no Firebase app and runs no migration —
// so every side effect moved behind the `require.main === module` guard at the
// bottom of this file. Running the script from the CLI is unchanged.
let db = null;
function initDb() {
  if (db !== null) return db;
  const sa = require(path.join(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
  admin.initializeApp({ credential: admin.credential.cert(sa) });
  db = admin.firestore();
  return db;
}

const ARGV        = process.argv.slice(2);
const COMMIT      = ARGV.includes('--commit');
const SCHOOL_ARG  = (ARGV.find(a => a.startsWith('--school=')) || '').split('=')[1] || '';
const BATCH_LIMIT = 400;   // Firestore hard limit is 500; leave headroom.

/** Legacy concession `type` → baseline `valueType`. Unknown types are not guessed. */
const VALUE_TYPE_MAP = {
  percent:    'percent',
  fixed:      'fixed',
  fullexempt: 'fullExempt',
};

/**
 * Legacy scope → baseline scope string.
 *   head     + targetFeeHeadId → 'head:<id>'
 *   category + targetCategory  → 'category:<cat>'
 *   all                        → 'all'
 * Returns null when the legacy row is too malformed to map safely.
 *
 * NOTE (baseline invariant I3): scope 'all' NEVER nets against transport /
 * meals service heads. That is enforced by the GENERATOR at read time
 * (Phase C/D), not by rewriting the scope here — the migration preserves what
 * the record actually said, it does not silently narrow it.
 */
function mapScope(d) {
  const scope = String(d.scope || '').trim().toLowerCase();
  if (scope === 'all') return 'all';
  if (scope === 'head') {
    // PHASE 2 / F1 (2026-08-16) — NAME FALLBACK.
    //
    // The defect this closes: this returned null whenever `targetFeeHeadId`
    // was empty, so the row was SKIPPED. But the legacy reader
    // (Fee_concession_reader:73-74) honours such a concession by matching
    // `targetFeeHead` — the NAME — against the demand's fee head. Dropping it
    // therefore silently billed the student MORE at cutover, which is the one
    // outcome a migration must never produce.
    //
    // Why the name is safe in the same token: Fee_entitlement_reader::appliesTo
    // resolves `head:<token>` against the demand's feeHeadId OR its feeHead
    // name (`:120-127`), so one token covers both. Ids are minted as
    // `FH_` + 12 hex (Fee_firestore_sync:148-150), a shape a human-authored
    // head name cannot collide with, so the token stays unambiguous.
    //
    // Preference order is deliberate: a stable id is resolved first and only
    // falls back to the name when no id was ever recorded.
    const head = String(d.targetFeeHeadId || '').trim();
    if (head) return `head:${head}`;
    const headName = String(d.targetFeeHead || '').trim();
    return headName ? `head:${headName}` : null;
  }
  if (scope === 'category') {
    const cat = String(d.targetCategory || '').trim();
    return cat ? `category:${cat}` : null;
  }
  return null;
}

/**
 * Classify the legacy concession into the baseline's concessionType vocabulary
 * (sibling|staff_ward|rte_ews|scholarship|other). The legacy collection has no
 * such field, so this reads the free-text reason. Anything not confidently
 * recognised becomes 'other' — never a wrong specific type, because
 * concessionType drives real downstream behaviour (RTE/EWS alone carries the
 * govtReceivable obligation, invariant I4).
 */
function inferConcessionType(reason) {
  const r = String(reason || '').toLowerCase();
  if (/\brte\b|\bews\b/.test(r))                       return 'rte_ews';
  if (/sibling|second child|brother|sister/.test(r))   return 'sibling';
  if (/staff|teacher.?s (ward|child)|employee/.test(r))return 'staff_ward';
  if (/scholarship|merit|topper/.test(r))              return 'scholarship';
  return 'other';
}

/** Deterministic target id — makes re-runs idempotent. */
function targetId(schoolId, studentId, sourceId) {
  return `${schoolId}_${studentId}_MIG_${sourceId}`;
}

async function main() {
  initDb();
  console.log('════════════════════════════════════════════════════════════');
  console.log(`  studentConcessions → studentEntitlements  [Phase B]`);
  console.log(`  MODE: ${COMMIT ? '*** COMMIT (writing) ***' : 'DRY-RUN (no writes)'}`);
  console.log(`  SCOPE: ${SCHOOL_ARG || 'ALL SCHOOLS'}`);
  console.log('════════════════════════════════════════════════════════════\n');

  let q = db.collection('studentConcessions');
  if (SCHOOL_ARG) q = q.where('schoolId', '==', SCHOOL_ARG);
  const snap = await q.get();
  console.log(`Source rows in studentConcessions: ${snap.size}\n`);

  const stats = {
    mapped: 0, skippedExisting: 0, skippedMalformed: 0, written: 0,
    byType: {}, byStatus: {},
  };
  const problems = [];
  const pending  = [];

  for (const doc of snap.docs) {
    const d        = doc.data() || {};
    const schoolId = String(d.schoolId || '').trim();
    const studentId= String(d.studentId || '').trim();

    if (!schoolId || !studentId) {
      stats.skippedMalformed++;
      problems.push(`${doc.id}: missing schoolId/studentId — cannot tenant-scope, SKIPPED`);
      continue;
    }

    const valueType = VALUE_TYPE_MAP[String(d.type || '').trim().toLowerCase()];
    if (!valueType) {
      stats.skippedMalformed++;
      problems.push(`${doc.id}: unmappable type="${d.type}" — SKIPPED (not guessed)`);
      continue;
    }

    const scope = mapScope(d);
    if (!scope) {
      stats.skippedMalformed++;
      problems.push(`${doc.id}: unmappable scope="${d.scope}" target="${d.targetFeeHeadId || d.targetCategory || ''}" — SKIPPED`);
      continue;
    }

    const effectiveFrom = String(d.effectiveFrom || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(effectiveFrom)) {
      stats.skippedMalformed++;
      problems.push(`${doc.id}: bad effectiveFrom="${d.effectiveFrom}" — SKIPPED (an entitlement without a valid window cannot be billed safely)`);
      continue;
    }

    // Legacy status vocabulary is active|revoked; the baseline's is
    // proposed|pending_approval|active|rejected|discontinued.
    const legacyStatus = String(d.status || 'active').trim().toLowerCase();
    const status = legacyStatus === 'revoked' ? 'discontinued' : 'active';

    const concessionType = inferConcessionType(d.reason);
    const tid = targetId(schoolId, studentId, doc.id);

    const existing = await db.collection('studentEntitlements').doc(tid).get();
    if (existing.exists) {
      stats.skippedExisting++;
      continue;
    }

    const entitlement = {
      // identity / tenancy
      schoolId, studentId,
      kind:            'concession',
      serviceType:     null,
      concessionType,
      // what it does
      scope, valueType,
      value:           valueType === 'fullExempt' ? 0 : Number(d.value || 0),
      // No `frequency` — retired at the P2 contract freeze (I1). It was
      // emitted here only to satisfy a validation that no longer exists, and
      // it never affected what a migrated concession was worth. The billing
      // period lives on the demand.
      // window
      effectiveFrom,
      effectiveTo:     String(d.effectiveTo || '') || null,
      session:         d.session || null,
      // lifecycle
      status,
      approvalRequired: false,
      approvedBy:      d.approvedBy || null,
      approvedAt:      d.approvedAt || null,
      requestedBy:     d.createdBy  || 'MIGRATION',
      requestedAt:     d.createdAt  || null,
      reason:          String(d.reason || ''),
      // RTE/EWS receivable (invariant I4) — the legacy collection never
      // tracked it. Seeded as an explicitly UNKNOWN obligation rather than
      // silently null, so migrated RTE rows surface in the follow-up report
      // instead of looking complete.
      govtReceivable:  concessionType === 'rte_ews'
        ? { amountExpected: 0, status: 'unverified_migrated', lastFollowUp: null }
        : null,
      linkedRouteId:   null,
      linkedRoomId:    null,
      creditBalanceRef: null,
      // provenance — so Phase C's shadow comparison can prove where each row came from
      migratedFrom:    `studentConcessions/${doc.id}`,
      migratedAt:      new Date().toISOString(),
    };

    stats.mapped++;
    stats.byType[concessionType] = (stats.byType[concessionType] || 0) + 1;
    stats.byStatus[status]       = (stats.byStatus[status] || 0) + 1;
    pending.push({ id: tid, data: entitlement });
  }

  // ── Write (only with --commit), batched ──
  if (COMMIT && pending.length) {
    for (let i = 0; i < pending.length; i += BATCH_LIMIT) {
      const chunk = pending.slice(i, i + BATCH_LIMIT);
      const batch = db.batch();
      chunk.forEach(p => batch.set(db.collection('studentEntitlements').doc(p.id), p.data, { merge: true }));
      await batch.commit();
      stats.written += chunk.length;
      console.log(`  committed ${stats.written}/${pending.length}`);
    }
  }

  // ── Family B inventory — counted, never migrated (open decision) ──
  let scholarshipCount = 0;
  try {
    let sq = db.collection('scholarshipAwards');
    if (SCHOOL_ARG) sq = sq.where('schoolId', '==', SCHOOL_ARG);
    scholarshipCount = (await sq.get()).size;
  } catch (e) {
    scholarshipCount = -1;   // collection absent on this project
  }

  // ── Report ──
  console.log('\n─────────────────── RESULT ───────────────────');
  console.log(`mapped (ready to write) : ${stats.mapped}`);
  console.log(`already migrated (skip) : ${stats.skippedExisting}`);
  console.log(`malformed (skipped)     : ${stats.skippedMalformed}`);
  console.log(`ACTUALLY WRITTEN        : ${COMMIT ? stats.written : 0}${COMMIT ? '' : '   (dry-run)'}`);
  console.log(`\nby concessionType: ${JSON.stringify(stats.byType)}`);
  console.log(`by status        : ${JSON.stringify(stats.byStatus)}`);

  if (problems.length) {
    console.log(`\n──────────── ${problems.length} ROW(S) NEEDING A HUMAN ────────────`);
    problems.slice(0, 50).forEach(p => console.log('  ! ' + p));
    if (problems.length > 50) console.log(`  … and ${problems.length - 50} more`);
  }

  const rteMigrated = stats.byType.rte_ews || 0;
  if (rteMigrated) {
    console.log(`\n! ${rteMigrated} RTE/EWS entitlement(s) migrated with govtReceivable.status='unverified_migrated'.`);
    console.log(`  Invariant I4 requires a real receivable on every RTE entitlement — these need`);
    console.log(`  amountExpected set before Phase C goes live.`);
  }

  console.log('\n──────────── NOT MIGRATED (by design) ────────────');
  console.log(`  services (transport/hostel/meals) : greenfield per baseline §Migration Strategy`);
  console.log(`  scholarshipAwards rows            : ${scholarshipCount < 0 ? 'collection absent' : scholarshipCount} — REPORT ONLY.`);
  console.log(`    Family B migration is an OPEN DECISION in the baseline; this script`);
  console.log(`    counts them so the decision is made against real numbers, and does not guess.`);

  if (!COMMIT) {
    console.log('\nDRY-RUN — nothing was written. Re-run with --commit to apply.');
  }
  console.log('');
  process.exit(0);
}

// ── PHASE 2 / F1 — pure helpers exported for verification ──────────────────
// Exported so the unit test executes THIS mapping, not a transcription of it.
module.exports = { mapScope, VALUE_TYPE_MAP };

// Side effects run ONLY when invoked directly from the CLI.
if (require.main === module) {
  main().catch(e => {
    console.error('FATAL:', e && e.stack ? e.stack : e);
    process.exit(1);
  });
}
