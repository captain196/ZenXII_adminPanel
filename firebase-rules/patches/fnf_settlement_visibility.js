/**
 * FNF-6 — employee visibility for `staffSettlements`.
 *
 * WHY A PATCH FILE AND NOT AN EDIT. `firestore.rules` is edited concurrently by
 * other sessions and a deploy ships the WHOLE file, so this programme does not
 * touch it. The tests apply this to a copy of the live rules in memory, which
 * proves the change against the real ruleset without modifying it.
 *
 * THE GAP
 *
 *   `staffSettlements` has no rule block at all, so it falls to the default-deny
 *   catch-all (`match /{document=**} { allow read, write: if false; }`). The
 *   admin panel is unaffected — PHP writes through the service account, which
 *   bypasses rules entirely — but a leaver cannot see their own settlement from
 *   the Teacher app, because nothing can read it.
 *
 * THE SHAPE
 *
 *   Modelled on `attendanceRegularizations`, which solves the identical problem:
 *   an owner reads their own record by `resource.data.staffId == request.auth.uid`,
 *   admins read any in their school, and the client never writes because the
 *   service account applies every decision.
 *
 *   Writes stay CLOSED — every transition passes the state machine's guards on
 *   the server. A client-writable settlement would let a leaver move their own
 *   settlement to `approved`.
 *
 * ── A PRODUCT DECISION THIS PATCH MAKES VISIBLE ────────────────────────────
 *
 *   The settlement document carries `rejectNote` and `cancelledReason` — free
 *   text an administrator wrote ABOUT the employee. Opening an owner-read arm
 *   makes those readable by that employee.
 *
 *   That is arguably correct: knowing WHY a settlement was rejected is exactly
 *   what a leaver needs, and this ruleset already shows employees the admin
 *   `remarks` on their own regularization. But it is a decision, not a
 *   side-effect, and this file is where it is recorded rather than discovered
 *   later.
 *
 *   If a school wants the lifecycle visible but the commentary private, the fix
 *   is NOT a rules change — rules cannot filter fields. The commentary would
 *   have to move to a sibling document that carries no owner-read arm. That is
 *   a schema change and is deliberately NOT bundled here.
 *
 *   Note the amounts are NOT in this document: they live on the payroll slip,
 *   under its own rules. Opening this arm exposes lifecycle and policy
 *   metadata, not money.
 *
 * DEPLOYMENT (pull-first — this programme must not deploy):
 *
 *   1. cd firebase-rules && git pull
 *   2. node patches/apply_fnf_visibility.js
 *   3. git diff firestore.rules            # MUST show only this block
 *   4. cd tests && npx firebase emulators:exec --only firestore \
 *        --project=zenxii-rules-test "npx jest fnf_settlement_visibility --runInBand --forceExit"
 *   5. firebase deploy --only firestore:rules --project graderadmin
 *
 * No index is required: the app queries by equality on staffId.
 */

/** Anchor — the block this one is inserted before. Chosen because it is stable. */
const ANCHOR = `    // ── Audit Log (Phase 3 — admin only; never writable by clients) ─`;

/** The block to insert. */
const BLOCK = `    // ── F&F settlements (FNF-6) ───────────────────────────────────
    //
    // A leaver may read their OWN settlement; same-school admins may read any.
    // Clients NEVER write: every transition passes Fnf_state_machine's guards
    // on the server (separation of duties, clearance, one-open-slot), and a
    // client-writable settlement would let a leaver approve their own.
    //
    // Same idiom as attendanceRegularizations above. The amounts are not here —
    // they live on the payroll slip under its own rules; this document carries
    // lifecycle, policy version and payment references.
    //
    // NOTE: rejectNote / cancelledReason are administrator commentary and become
    // readable by the employee through the owner arm. That is a deliberate
    // choice — see patches/fnf_settlement_visibility.js.
    match /staffSettlements/{docId} {
      allow read:  if (isAdmin() && isSameSchool())
                   || (isStaff() && isSameSchool() && resource.data.staffId == request.auth.uid);
      allow write: if false;   // PHP service account applies every transition
    }

`;

/**
 * Apply the patch to a rules document.
 *
 * Refuses rather than half-applying: a silent no-op would let the tests
 * "prove" a patch that was never applied.
 */
function apply(rulesText) {
  if (rulesText.includes('match /staffSettlements/{docId}')) {
    throw new Error('FNF-6 appears to be applied already — staffSettlements is rule-covered.');
  }

  const hits = rulesText.split(ANCHOR).length - 1;
  if (hits !== 1) {
    throw new Error(
      `FNF-6 anchor found ${hits} times, expected exactly 1. firestore.rules has moved — ` +
      're-read it and update patches/fnf_settlement_visibility.js before deploying.'
    );
  }

  return rulesText.replace(ANCHOR, BLOCK + ANCHOR);
}

module.exports = { ANCHOR, BLOCK, apply };
