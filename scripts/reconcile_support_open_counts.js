#!/usr/bin/env node
/**
 * Reconcile `supportCounters/{schoolId}_{reporterId}.openCount` against the
 * tickets that are actually open.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * `openCount` is the denormal the RULES cap reads: a parent may create a ticket
 * only while `openCount < 5` (firestore.rules, `underCap()`). It is maintained
 * by Cloud Function triggers, and for a period two triggers both owned it — the
 * message trigger and the status trigger each incremented on a single reopen —
 * so every resolve→reopen cycle netted +1 permanently.
 *
 * That double-count was fixed in `functions/supportDesk.js` (the reopen path no
 * longer increments; the status trigger owns the transition). But the fix is
 * NOT retroactive: a parent whose counter already drifted stays drifted, and
 * keeps walking toward a cap they can never come back under. At openCount >= 5
 * they simply cannot raise a ticket — with no error a parent or the school
 * could act on. This script is how an already-drifted tenant is repaired.
 *
 * ── Why a reconciler and not a one-shot ──────────────────────────────────────
 * Deliberately re-runnable and idempotent, because it doubles as a DETECTOR.
 * `openCount` has had two owners once already; any future trigger change can
 * reintroduce drift, and a dry run is then the cheapest way to see it. Run it
 * after any change to the Support triggers.
 *
 * ── Source of truth ──────────────────────────────────────────────────────────
 * `supportTickets.status` ∈ ['open','assigned','reopened'] — the same ACTIVE set
 * `functions/supportDesk.js` uses. `resolved` is NOT active: it is a ticket
 * awaiting the reopen window, and closeStaleTickets retires it later.
 * The set is duplicated here on purpose rather than imported: this script must
 * keep reporting the truth even if the functions bundle is mid-edit.
 *
 * USAGE:
 *   node scripts/reconcile_support_open_counts.js                     # dry run, all schools
 *   node scripts/reconcile_support_open_counts.js --school=SCH_X      # dry run, one school
 *   node scripts/reconcile_support_open_counts.js --apply             # commit corrections
 *
 * Writes ONLY openCount, and only where it differs. Never deletes a counter
 * document: an orphan counter is corrected to 0, not removed, so the doc keeps
 * its place for the triggers that merge into it.
 */
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();

const APPLY = process.argv.includes('--apply');
function arg(name, dflt) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  return eq ? eq.split('=').slice(1).join('=') : dflt;
}
const SCHOOL_FILTER = arg('school', '');

/** The ACTIVE set from functions/supportDesk.js. Keep in step with it. */
const ACTIVE = ['open', 'assigned', 'reopened'];

/** The cap enforced by firestore.rules `underCap()`. Reported, never written. */
const CAP = 5;

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  supportCounters.openCount reconciliation');
  console.log('  mode:', APPLY ? 'APPLY (writes)' : 'DRY RUN (no writes)');
  if (SCHOOL_FILTER) console.log('  school filter:', SCHOOL_FILTER);
  console.log('  active states:', ACTIVE.join(', '), '| cap:', CAP);
  console.log('═══════════════════════════════════════════════════════════════');

  // ── 1. count genuinely-active tickets per (school, reporter) ──────────────
  let q = fs.collection('supportTickets');
  if (SCHOOL_FILTER) q = q.where('schoolId', '==', SCHOOL_FILTER);
  const tickets = await q.get();

  const actual = new Map();   // key -> count of ACTIVE tickets
  const seen   = new Map();   // key -> total tickets (for context in the report)
  for (const doc of tickets.docs) {
    const d = doc.data() || {};
    const schoolId   = String(d.schoolId   || '');
    const reporterId = String(d.reporterId || '');
    if (!schoolId || !reporterId) continue;           // nothing to key on
    const key = `${schoolId}_${reporterId}`;
    seen.set(key, (seen.get(key) || 0) + 1);
    if (ACTIVE.includes(String(d.status || ''))) {
      actual.set(key, (actual.get(key) || 0) + 1);
    } else if (!actual.has(key)) {
      actual.set(key, 0);
    }
  }

  // ── 2. read every counter, including ones with no tickets at all ──────────
  let cq = fs.collection('supportCounters');
  if (SCHOOL_FILTER) cq = cq.where('schoolId', '==', SCHOOL_FILTER);
  const counters = await cq.get();
  const stored = new Map();
  for (const doc of counters.docs) {
    stored.set(doc.id, Number((doc.data() || {}).openCount || 0));
  }

  // Union: a reporter may have tickets and no counter, or the reverse.
  const keys = new Set([...actual.keys(), ...stored.keys()]);

  const drifted = [];
  let ok = 0;
  for (const key of [...keys].sort()) {
    const want = actual.get(key) || 0;
    const have = stored.has(key) ? stored.get(key) : null;
    if (have === want) { ok++; continue; }
    drifted.push({ key, have, want, tickets: seen.get(key) || 0 });
  }

  console.log(`\n  reporters examined : ${keys.size}`);
  console.log(`  already correct    : ${ok}`);
  console.log(`  drifted            : ${drifted.length}\n`);

  if (!drifted.length) {
    console.log('  Nothing to correct.');
    await admin.app().delete();
    return;
  }

  for (const d of drifted) {
    const lockedOut = d.have !== null && d.have >= CAP && d.want < CAP;
    console.log(
      `  ${d.key}\n` +
      `      stored=${d.have === null ? '(no counter doc)' : d.have}` +
      `  actual_open=${d.want}  total_tickets=${d.tickets}` +
      (lockedOut ? '   ⚠ AT/OVER CAP — this parent cannot raise a ticket' : '')
    );
  }

  if (!APPLY) {
    console.log('\n  DRY RUN — nothing written. Re-run with --apply to correct.');
    await admin.app().delete();
    return;
  }

  // ── 3. write, in batches, only the openCount field ────────────────────────
  let written = 0;
  for (let i = 0; i < drifted.length; i += 400) {
    const batch = fs.batch();
    for (const d of drifted.slice(i, i + 400)) {
      const [schoolId, ...rest] = d.key.split('_');
      // reporter ids contain no underscore today, but rebuild defensively
      // rather than assuming a two-part key.
      const reporterId = rest.join('_');
      batch.set(
        fs.collection('supportCounters').doc(d.key),
        { schoolId, reporterId, openCount: d.want },
        { merge: true }
      );
      written++;
    }
    await batch.commit();
  }
  console.log(`\n  APPLIED — ${written} counter(s) corrected.`);
  await admin.app().delete();
})().catch(e => {
  console.error('  FAILED:', e && e.message ? e.message : e);
  process.exit(1);
});
