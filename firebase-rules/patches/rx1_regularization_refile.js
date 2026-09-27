/**
 * R-X1 — the exact, minimal `firestore.rules` patch that lets a staff member
 * re-file a regularization for a date they have already filed for.
 *
 * WHY THIS LIVES IN A SEPARATE FILE. `firestore.rules` is edited concurrently by
 * other sessions and a deploy ships the WHOLE file, so this programme does not
 * edit it. This module carries the change as data: the tests apply it to a copy
 * of the live rules in memory, so the patch is proven against the real ruleset
 * without touching it, and the deploy flow (pull first, then apply, then diff)
 * has an exact, reviewed string to paste.
 *
 * THE DEFECT
 *
 *   The Teacher app writes a DETERMINISTIC doc id, {schoolId}_{staffId}_{date},
 *   with merge = true, believing a re-submit overwrites the prior row (its own
 *   comment, TCH-H3). But in Firestore rules a merge onto an EXISTING document
 *   is an UPDATE, and this collection is `allow update, delete: if false`. So:
 *
 *     • a REJECTED day can never be re-filed — rejection is a dead end;
 *     • a typo in a claimed time can never be corrected;
 *     • and because submit is one atomic WriteBatch, a single already-filed
 *       date fails the whole batch, taking unrelated new dates down with it.
 *
 *   The teacher is shown a raw "PERMISSION_DENIED: Missing or insufficient
 *   permissions".
 *
 * THE SHAPE OF THE FIX
 *
 *   Not invented here: the SAME ruleset already grants owners a constrained
 *   update on `leaveApplications` — "clients may only CREATE a 'pending'
 *   request they own and CANCEL their own; APPROVE/REJECT is a same-school
 *   STAFF action on someone ELSE's request and can never pre-stamp attendance.
 *   All immutable identity/date fields are frozen across updates."
 *
 *   This applies that established local pattern, narrowed to the one transition
 *   the workflow actually needs.
 *
 * WHAT THE ARM PERMITS — exactly one transition:
 *
 *     (pending | rejected)  --owner-->  pending      (re-file)
 *      pending              --owner-->  cancelled    (withdraw)
 *
 * WHAT IT REFUSES, and why each clause is there:
 *
 *   • somebody else's request        resource.data.staffId == request.auth.uid
 *   • another tenant's request       isSameSchool() + schoolId frozen
 *   • an APPROVED record             status must currently be pending/rejected;
 *                                    an approved day is already stamped on the
 *                                    register and reversing it is an admin act
 *   • re-opening a cancelled or       neither may return to pending: a
 *     auto_rejected record           withdrawn request is filed afresh
 *   • changing WHOSE day it is       staffId frozen
 *   • changing WHICH day it is       date frozen — otherwise a rejected claim
 *                                    could be walked onto a different date,
 *                                    defeating the deterministic doc id
 *   • becoming a student record      personType frozen
 *   • forging an approval trail      reviewedBy and appliedMark must be empty,
 *                                    so a client can never name an approver or
 *                                    pre-set the pay-bearing mark
 *   • self-granting a paid mark      requestedStatus still in ['P', 'M'],
 *                                    identical to the create arm
 *   • deleting evidence              delete stays `if false`
 *
 * WITHDRAWAL (added 2026-08-23 after vendor research). An owner-cancel arm was
 * initially excluded for want of evidence that the workflow exists. greytHR
 * documents it precisely: Withdraw is offered on the My Regularizations
 * PENDING tab, and "once the manager approves attendance regularization
 * requests of employees, it cannot be canceled". That is an objective,
 * documented boundary rather than a guess, so the arm now permits exactly
 * pending -> cancelled, by the owner, and nothing else. An approved request
 * stays immutable, matching the same source.
 *
 * The Teacher app already renders a "Cancelled" chip but ships no withdraw
 * control; the rule makes the transition possible, the app must add the
 * button. See the gap matrix.
 *
 * DEPLOYMENT (pull-first — this programme must not deploy):
 *
 *   1. cd firebase-rules && git pull            # rules move under other sessions
 *   2. node patches/apply_rx1.js                # or paste NEW over OLD by hand
 *   3. git diff firestore.rules                 # MUST show only this block
 *   4. cd tests && npx firebase emulators:exec --only firestore \
 *        --project=zenxii-rules-test "npx jest regularization_refile --runInBand --forceExit"
 *   5. firebase deploy --only firestore:rules --project graderadmin
 *
 * No index is required: the collection is queried by equality only.
 */

/** The current last line of the attendanceRegularizations block. */
const OLD = `      allow update, delete: if false;   // PHP service account applies decisions`;

/** Its replacement. Read + create arms are untouched, byte for byte. */
const NEW = `      // ── R-X1 (2026-08-23) — owner may re-file, nothing more ──────
      // The app writes a deterministic id ({school}_{staff}_{date}) with
      // merge, so a re-submit is an UPDATE. With update closed outright, a
      // REJECTED day could never be re-filed, a mistyped time could never be
      // corrected, and — because submit is one atomic WriteBatch — a single
      // already-filed date failed every other date in the same request.
      //
      // Exactly one transition is opened: (pending | rejected) -> pending, by
      // the owner, with identity, subject and date frozen and no ability to
      // write a decision trail. An APPROVED record stays immutable: its day is
      // already stamped on the register, and reversing that is an admin act.
      // Mirrors the owner-update pattern used by leaveApplications below.
      allow update: if isStaff()
                    && isSameSchool()
                    && resource.data.staffId == request.auth.uid
                    // identity, subject and date are frozen across the update
                    && request.resource.data.schoolId   == resource.data.schoolId
                    && request.resource.data.staffId    == resource.data.staffId
                    && request.resource.data.date       == resource.data.date
                    && request.resource.data.personType == resource.data.personType
                    // a client can never name an approver or pre-set the mark
                    && request.resource.data.get('reviewedBy', '') == ''
                    && request.resource.data.get('appliedMark', '') == ''
                    && (
                         // (a) RE-FILE — an undecided or refused request goes back to
                         //     pending. An APPROVED record stays immutable: its day is
                         //     already stamped on the register, and reversing that is an
                         //     admin act.
                         ( resource.data.status in ['pending', 'rejected']
                           && request.resource.data.status == 'pending'
                           // same self-request ceiling as the create arm
                           && request.resource.data.requestedStatus in ['P', 'M'] )
                         // (b) WITHDRAW — the owner cancels a request that has not been
                         //     decided. Pending only: greytHR documents exactly this
                         //     boundary — withdraw is offered on the Pending tab, and
                         //     "once the manager approves attendance regularization
                         //     requests of employees, it cannot be canceled".
                         || ( resource.data.status == 'pending'
                              && request.resource.data.status == 'cancelled' )
                       );

      allow delete: if false;           // PHP service account applies decisions`;

/**
 * Apply the patch to a rules document.
 *
 * Refuses rather than half-applying: if the anchor is missing or ambiguous the
 * live rules have moved under us, and a silent no-op would let the tests
 * "prove" a patch that was never applied.
 */
function apply(rulesText) {
  const hits = rulesText.split(OLD).length - 1;
  if (hits !== 1) {
    throw new Error(
      `R-X1 patch anchor found ${hits} times, expected exactly 1. ` +
      'firestore.rules has changed — re-read the attendanceRegularizations block ' +
      'and update patches/rx1_regularization_refile.js before deploying.'
    );
  }
  return rulesText.replace(OLD, NEW);
}

module.exports = { OLD, NEW, apply };
