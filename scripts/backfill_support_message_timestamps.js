#!/usr/bin/env node
/**
 * Convert `supportMessages.createdAt` from a STRING to a Firestore Timestamp.
 *
 * ── Why ──────────────────────────────────────────────────────────────────────
 * The field had two writers producing two TYPES. The parent app writes a
 * Timestamp and cannot do otherwise — firestore.rules forces
 * `createdAt == request.time` on a client create. The panel wrote an ISO
 * string. Both look correct in isolation and both read back fine.
 *
 * Firestore orders a mixed-type field BY TYPE FIRST: every timestamp sorts
 * before every string, regardless of the clock. Both surfaces order this exact
 * field server-side — the Parent app ASC (SupportFirestoreRepository:183), the
 * panel DESC (Support.php:658) — so a thread rendered as "all parent messages,
 * then all staff messages", not as a conversation.
 *
 * Verified live 2026-08-31 on TKT_WDMAHQVKBSPP1F0B: a parent reply sent
 * 29 Aug 02:58 was returned SECOND of six, above three staff messages from
 * 28 Aug — the answer sitting above the question it answered.
 *
 * `Support.php::_ts_value()` fixes every NEW message. This fixes the old ones.
 * Both are required: fixing only the writer leaves every existing thread wrong,
 * and fixing only the data lets the next reply reintroduce it.
 *
 * ── Safety ───────────────────────────────────────────────────────────────────
 * Converts ONLY documents whose createdAt is currently a string, and only that
 * one field. Re-runnable: a converted document is skipped on the next pass, so
 * this doubles as a detector — a non-zero count after the writer fix means a
 * string writer still exists somewhere.
 *
 * A string that cannot be parsed is REPORTED AND SKIPPED, never guessed at.
 * Losing the true send time of a message is worse than leaving it mis-ordered:
 * for a module whose confidential lane carries POSH/POCSO reports, the
 * timestamp is the evidentiary part.
 *
 * USAGE:
 *   node scripts/backfill_support_message_timestamps.js                  # dry run
 *   node scripts/backfill_support_message_timestamps.js --school=SCH_X   # dry run, one school
 *   node scripts/backfill_support_message_timestamps.js --apply          # convert
 */
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const APPLY = process.argv.includes('--apply');
const eq = process.argv.find(a => a.startsWith('--school='));
const SCHOOL = eq ? eq.split('=').slice(1).join('=') : '';

(async () => {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  supportMessages.createdAt  string → Timestamp');
  console.log('  mode:', APPLY ? 'APPLY (writes)' : 'DRY RUN (no writes)');
  if (SCHOOL) console.log('  school filter:', SCHOOL);
  console.log('═══════════════════════════════════════════════════════════════');

  let q = db.collection('supportMessages');
  if (SCHOOL) q = q.where('schoolId', '==', SCHOOL);
  const snap = await q.get();

  const convert = [], already = [], unparsable = [], missing = [];
  for (const d of snap.docs) {
    const v = d.data().createdAt;
    if (v == null)                       { missing.push(d.id); continue; }
    if (typeof v.toDate === 'function')  { already.push(d.id); continue; }
    if (typeof v !== 'string')           { unparsable.push([d.id, typeof v]); continue; }
    const ms = Date.parse(v);
    if (!Number.isFinite(ms))            { unparsable.push([d.id, JSON.stringify(v)]); continue; }
    convert.push({ id: d.id, from: v, to: new Date(ms), ticketId: d.data().ticketId, senderType: d.data().senderType });
  }

  console.log(`\n  scanned              : ${snap.size}`);
  console.log(`  already Timestamp    : ${already.length}`);
  console.log(`  to convert           : ${convert.length}`);
  console.log(`  createdAt absent     : ${missing.length}`);
  console.log(`  UNPARSABLE (skipped) : ${unparsable.length}`);
  unparsable.forEach(([id, why]) => console.log(`      ⚠ ${id}  ${why}`));

  if (convert.length) {
    console.log('\n  conversions:');
    convert.forEach(c => console.log(
      `    ${c.ticketId}  ${String(c.senderType).padEnd(7)} ${c.from.padEnd(26)} → ${c.to.toISOString()}`));
  }

  if (!convert.length) { console.log('\n  Nothing to convert.'); await admin.app().delete(); return; }
  if (!APPLY) {
    console.log('\n  DRY RUN — nothing written. Re-run with --apply to convert.');
    await admin.app().delete(); return;
  }

  let n = 0;
  for (let i = 0; i < convert.length; i += 400) {
    const batch = db.batch();
    for (const c of convert.slice(i, i + 400)) {
      batch.update(db.collection('supportMessages').doc(c.id), {
        createdAt: admin.firestore.Timestamp.fromDate(c.to),
      });
      n++;
    }
    await batch.commit();
  }
  console.log(`\n  APPLIED — ${n} message(s) converted.`);
  await admin.app().delete();
})().catch(e => { console.error('  FAILED:', e && e.message ? e.message : e); process.exit(1); });
