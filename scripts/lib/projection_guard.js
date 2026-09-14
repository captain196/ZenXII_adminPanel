/**
 * projection_guard — refuse to touch a `timetables` document that the canonical
 * lifecycle owns.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────
 *
 * `timetables` is no longer authored. Under the version architecture it is a
 * PROJECTION of whichever timetableVersion is published and in force, rebuilt
 * by Timetable_projection_service. Every document it writes carries
 * `projectedFromVersion` (and `projectedFromPlan`).
 *
 * A one-off script that edits or deletes such a document does damage that is
 * quiet in both directions:
 *
 *   - the edit bypasses validation and publishing entirely, so what teachers
 *     and parents see changes with no version recording that it happened;
 *   - the next projection rebuilds the document from the published version and
 *     destroys the edit, so whoever made it sees their change evaporate;
 *   - a DELETE is worse: the canonical version still says that timetable
 *     applies, so the school believes the class is covered while the apps show
 *     nothing. Nothing repairs it until a projection runs — and as of
 *     2026-09-12 nothing in this repository schedules one.
 *
 * Timetables_reconcile.php and Rollover_projection_guard.php already refuse on
 * exactly this stamp. This is the same rule for the Node scripts, which were
 * the only writers still missing it.
 *
 * Documents WITHOUT the stamp predate the canonical lifecycle and remain fair
 * game — that is what these scripts were written for.
 */

'use strict';

/** True when the canonical lifecycle owns this document. */
function isProjected(data) {
  return typeof (data || {}).projectedFromVersion === 'string'
      && String(data.projectedFromVersion).trim() !== '';
}

/**
 * Split docs into what a one-off script may touch and what it must not.
 *
 * `docs` is any array of objects exposing `.id` and `.data()` (a Firestore
 * QueryDocumentSnapshot), or `{id, data}` plain objects.
 */
function partition(docs) {
  const allowed = [];
  const refused = [];
  for (const d of docs || []) {
    const data = typeof d.data === 'function' ? d.data() : (d.data || {});
    if (isProjected(data)) {
      refused.push({ id: d.id, version: String(data.projectedFromVersion) });
    } else {
      allowed.push(d);
    }
  }
  return { allowed, refused };
}

/**
 * Print what was refused and why. Returns the refused count so a caller can
 * fold it into its own exit status.
 *
 * Reported loudly and always — a script that silently skipped half its targets
 * would report "clean" on a school it never examined.
 */
function report(refused, verb) {
  if (!refused || refused.length === 0) return 0;

  console.log('');
  console.log('  ⚠ REFUSED — ' + refused.length + ' document(s) belong to a published '
            + 'timetable and were NOT ' + (verb || 'changed') + ':');
  for (const r of refused) {
    console.log('      • ' + r.id + '   (projected from ' + r.version + ')');
  }
  console.log('');
  console.log('    These are owned by the canonical lifecycle. Changing one here '
            + 'would alter what');
  console.log('    teachers and parents see with no version recording it, and the '
            + 'next projection');
  console.log('    would overwrite it anyway. To change them, change the timetable '
            + 'and publish it.');
  return refused.length;
}

module.exports = { isProjected, partition, report };
