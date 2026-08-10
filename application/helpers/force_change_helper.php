<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * force_change_helper — pure decision logic for the forced set-new-password flow.
 *
 * Extracted so the two decisions that caused production lockouts are unit-testable
 * without a CodeIgniter bootstrap:
 *
 *   1. WHICH profile collection holds a user's `mustChangePassword` mirror.
 *      This used to be decided from the `role` CLAIM, but two writers disagree on
 *      what a student's role is — `Sis::_createFirebaseAuthStudent` mints
 *      'student', `Sis::reset_password` mints 'Parent'. A first-login student
 *      therefore matched neither branch and their mirror clear was written to
 *      `admins/{schoolId}_STU####`, leaving `students/{...}.mustChangePassword`
 *      true forever. Evidence in production: admins/SCH_D94FE8F7AD_STU0159.
 *      The id prefix is minted by Id_generator and never drifts, so route on it.
 *
 *   2. WHETHER a forced change is still pending. The apps gate on
 *      (mirror OR claim); the server used to require the CLAIM alone. Any failure
 *      after the claim was cleared — dropped response, app killed, 502, or a
 *      later claim re-mint that dropped the flag — left the user permanently
 *      stuck: the app kept showing the screen and every retry was rejected with
 *      "No password change required for this account". Server and client must
 *      agree on the predicate, so the server accepts either signal.
 */

if (!function_exists('force_change_truthy')) {
    /**
     * Firestore/JWT round-trips can turn a boolean into "true"/1, so normalise
     * before testing. Anything unrecognised is false — this decides whether a
     * password may be set, so it fails closed.
     */
    function force_change_truthy($value): bool
    {
        if (is_bool($value))   return $value;
        if (is_int($value))    return $value === 1;
        if (is_string($value)) return $value === '1' || strcasecmp(trim($value), 'true') === 0;
        return false;
    }
}

if (!function_exists('force_change_profile_collection')) {
    /**
     * Which Firestore collection holds this account's mustChangePassword mirror.
     *
     * Routes on the ID PREFIX, which is minted by Id_generator and is stable for
     * the life of the account. The role claim is only consulted for ids that
     * don't match a known prefix, and never overrides a recognised one.
     *
     * @param string $uid  Auth uid == the ZenXii id (STU0159, STA0067, SSA0001…)
     * @param string $role Role claim — fallback only, for unrecognised id shapes
     */
    function force_change_profile_collection(string $uid, string $role = ''): string
    {
        $id = strtoupper(trim($uid));

        if (preg_match('/^STU\d+$/', $id))       return 'students';
        if (preg_match('/^STA\d+$/', $id))       return 'staff';
        if (preg_match('/^(ADM|SSA)\d+$/', $id)) return 'admins';

        // Unrecognised id shape (legacy or hand-made account) — fall back to the
        // role label, accepting BOTH spellings a student account can carry.
        $r = strtolower(trim($role));
        if ($r === 'parent' || $r === 'student' || $r === 'guardian') return 'students';
        if ($r === 'super_admin')                                     return 'superAdmins';

        return 'admins';
    }
}

if (!function_exists('force_change_is_pending')) {
    /**
     * Is a forced password change still outstanding for this account?
     *
     * Mirrors what the apps gate on. Either signal alone is enough:
     *   - claim only  → mirror write failed, or was misrouted, or the account
     *                   predates the mirror
     *   - mirror only → the claim was cleared by a partially-completed attempt
     *                   or stripped by a later wholesale claim re-mint
     *
     * @param mixed $claim  must_change_password custom claim
     * @param mixed $mirror {staff|students|admins}.mustChangePassword, or null
     *                      when the doc is missing/unreadable
     */
    function force_change_is_pending($claim, $mirror): bool
    {
        return force_change_truthy($claim) || force_change_truthy($mirror);
    }
}
