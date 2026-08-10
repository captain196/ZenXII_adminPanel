<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Forced set-new-password flow — regression suite.
 *
 * Covers two production lockouts, both reported 2026-08-09:
 *
 *   SYMPTOM 1 "new user logs in, sets password, gets stuck / 'password change
 *              not required'"
 *     Auth_api::_clear_firestore_flag chose the mirror collection from the ROLE
 *     CLAIM. Sis mints 'student' at creation but 'Parent' on reset, so a
 *     first-login student matched neither branch and the clear was written to
 *     admins/{schoolId}_STU####. students/{...}.mustChangePassword stayed true,
 *     the Parent app re-gated on every cold start, and the retry was refused
 *     because the claim (correctly) had been cleared.
 *     Production evidence: admins/SCH_D94FE8F7AD_STU0159.
 *
 *   SYMPTOM 2 "password reset from the panel, then the same problem"
 *     setCanonicalClaims REPLACES the whole claim set. A re-mint after the reset
 *     dropped the pending must_change_password while the doc mirror stayed true,
 *     producing the same unrecoverable state.
 *     Production evidence: STA0078, STA0094 — claim-free, staff doc true.
 *
 * The fix routes on the id prefix (stable, minted by Id_generator) and makes the
 * server's "is a change pending?" predicate identical to the apps' — (claim OR
 * mirror) — which also makes the endpoint idempotent and self-healing.
 */
class ForceChangePasswordFlowTest extends TestCase
{
    private string $authApiSrc;
    private string $firebaseSrc;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', true);
        require_once __DIR__ . '/../../application/helpers/force_change_helper.php';
    }

    protected function setUp(): void
    {
        $this->authApiSrc  = (string) file_get_contents(__DIR__ . '/../../application/controllers/Auth_api.php');
        $this->firebaseSrc = (string) file_get_contents(__DIR__ . '/../../application/libraries/Firebase.php');
        $this->assertNotSame('', $this->authApiSrc);
        $this->assertNotSame('', $this->firebaseSrc);
    }

    /* ─────────────────────────────────────────────────────────────────────
       A. Mirror collection routing
       ───────────────────────────────────────────────────────────────────── */

    /**
     * THE regression. A student's mirror must land in `students` no matter which
     * of the two role spellings their claim happens to carry — or none at all.
     *
     * @dataProvider studentRoleVariants
     */
    public function test_student_id_always_routes_to_students(string $role): void
    {
        $this->assertSame(
            'students',
            force_change_profile_collection('STU0159', $role),
            "STU0159 with role '{$role}' must resolve to students — routing it to "
            . "admins is the production lockout (admins/SCH_D94FE8F7AD_STU0159)."
        );
    }

    public static function studentRoleVariants(): array
    {
        return [
            'creation mint (Sis::_createFirebaseAuthStudent)' => ['student'],
            'reset mint (Sis::reset_password)'                => ['Parent'],
            'claim absent entirely'                           => [''],
            'legacy capitalised'                              => ['Student'],
            'guardian'                                        => ['Guardian'],
            'unexpected label'                                => ['Accountant'],
        ];
    }

    /** A student id must NEVER resolve to the admins collection. */
    public function test_student_id_never_routes_to_admins(): void
    {
        foreach (['STU0001', 'STU0159', 'STU9999', 'stu0042'] as $id) {
            foreach (['student', 'Parent', '', 'Admin'] as $role) {
                $this->assertNotSame(
                    'admins',
                    force_change_profile_collection($id, $role),
                    "{$id}/{$role} routed to admins — that is the exact defect."
                );
            }
        }
    }

    public function test_staff_id_routes_to_staff(): void
    {
        $this->assertSame('staff', force_change_profile_collection('STA0067', 'Teacher'));
        $this->assertSame('staff', force_change_profile_collection('STA0078', 'Accountant'));
        // A staff member whose free-text role drifted into junk still routes right.
        $this->assertSame('staff', force_change_profile_collection('STA0094', '9876543210'));
    }

    public function test_legacy_admin_and_ssa_route_to_admins(): void
    {
        $this->assertSame('admins', force_change_profile_collection('ADM0003', 'Admin'));
        $this->assertSame('admins', force_change_profile_collection('SSA0001', 'school_super_admin'));
    }

    public function test_routing_is_case_insensitive(): void
    {
        // Auth uids are uppercase, but the synthetic email lowercases the id and
        // some call paths round-trip through it.
        $this->assertSame('students', force_change_profile_collection('stu0159', 'student'));
        $this->assertSame('staff',    force_change_profile_collection('sta0067', 'Teacher'));
    }

    public function test_unrecognised_id_falls_back_to_role_label(): void
    {
        $this->assertSame('students', force_change_profile_collection('LEGACY_1', 'Parent'));
        $this->assertSame('students', force_change_profile_collection('LEGACY_1', 'student'));
        $this->assertSame('admins',   force_change_profile_collection('LEGACY_1', 'Admin'));
        $this->assertSame('admins',   force_change_profile_collection('LEGACY_1', ''));
    }

    /* ─────────────────────────────────────────────────────────────────────
       B. "Is a forced change still pending?"
       ───────────────────────────────────────────────────────────────────── */

    public function test_pending_when_both_signals_set(): void
    {
        $this->assertTrue(force_change_is_pending(true, true), 'freshly created or reset account');
    }

    public function test_pending_when_only_the_claim_is_set(): void
    {
        // Mirror write failed, was misrouted, or the account predates the mirror.
        $this->assertTrue(force_change_is_pending(true, false));
        $this->assertTrue(force_change_is_pending(true, null));
    }

    /**
     * THE fix for both reported symptoms. Previously the server required the
     * claim, so this state returned 400 "No password change required" while the
     * app kept showing the force-change screen — an unrecoverable loop.
     */
    public function test_pending_when_only_the_mirror_is_set(): void
    {
        $this->assertTrue(
            force_change_is_pending(false, true),
            'claim cleared but mirror still true must remain actionable — this is '
            . 'both the misrouted-student case and the dropped-claim staff case, '
            . 'and it is what lets a retry self-heal the account.'
        );
    }

    public function test_not_pending_when_neither_signal_is_set(): void
    {
        $this->assertFalse(force_change_is_pending(false, false));
    }

    /**
     * Fails closed: an unreadable mirror is "no signal", not "pending". Otherwise
     * a Firestore blip would turn this into a generic password-change API for any
     * valid token.
     */
    public function test_unreadable_mirror_alone_does_not_authorise(): void
    {
        $this->assertFalse(force_change_is_pending(false, null));
    }

    /** @dataProvider truthyRoundTrips */
    public function test_flag_normalisation(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, force_change_truthy($value));
    }

    public static function truthyRoundTrips(): array
    {
        return [
            'bool true'        => [true,    true],
            'bool false'       => [false,   false],
            'string true'      => ['true',  true],
            'string TRUE'      => ['TRUE',  true],
            'padded string'    => [' true ', true],
            'string one'       => ['1',     true],
            'int one'          => [1,       true],
            'int zero'         => [0,       false],
            'string false'     => ['false', false],
            'empty string'     => ['',      false],
            'null'             => [null,    false],
            'junk string'      => ['yes',   false],
            'array'            => [[],      false],
        ];
    }

    /* ─────────────────────────────────────────────────────────────────────
       C. Structural guards — stop the defects being reintroduced
       ───────────────────────────────────────────────────────────────────── */

    /**
     * The old routing line. Its own comment claimed "route by uid prefix, not
     * role label" while the code branched on the label, which is how it survived
     * review — so assert on the code, not the comment.
     */
    public function test_mirror_collection_is_not_chosen_from_the_role_label(): void
    {
        $this->assertStringNotContainsString(
            "(\$role === 'Parent') ? 'students'",
            $this->authApiSrc,
            'Role-label routing reintroduced — a first-login student mints role '
            . "'student', so this branch sends their clear to admins/."
        );
        $this->assertStringContainsString(
            'force_change_profile_collection(',
            $this->authApiSrc,
            'Auth_api must resolve the mirror collection through the shared helper.'
        );
    }

    /** The endpoint must accept the mirror as evidence, not the claim alone. */
    public function test_endpoint_uses_the_same_predicate_as_the_apps(): void
    {
        $this->assertStringContainsString(
            'force_change_is_pending(',
            $this->authApiSrc,
            'clear_must_change must gate on (claim OR mirror); requiring the claim '
            . 'alone makes it non-idempotent and strands every interrupted attempt.'
        );
    }

    /**
     * A failed mirror write must not be reported as success. Swallowing it is
     * what leaves the claim cleared, the mirror true, and the user locked out.
     */
    public function test_failed_mirror_write_is_surfaced_not_swallowed(): void
    {
        $this->assertMatchesRegularExpression(
            '/private function _clear_firestore_flag\([^)]*\)\s*:\s*bool/',
            $this->authApiSrc,
            '_clear_firestore_flag must report success/failure so the caller can fail loud.'
        );
        $this->assertStringContainsString(
            'could not be finalised',
            $this->authApiSrc,
            'A failed mirror clear must return an actionable error, not 200 success.'
        );
    }

    /**
     * setCanonicalClaims replaces the whole claim set. It must carry a pending
     * forced change forward, or any unrelated re-mint silently strands the user.
     */
    public function test_claim_remint_preserves_a_pending_forced_change(): void
    {
        $this->assertMatchesRegularExpression(
            '/setCanonicalClaims.*?must_change_password/s',
            $this->firebaseSrc,
            'setCanonicalClaims must carry must_change_password forward — this is '
            . 'what stranded STA0078 and STA0094 in production.'
        );
        $this->assertStringContainsString(
            'getCustomClaimsAll',
            $this->firebaseSrc,
            'Carry-forward should read the existing claims in ONE round trip.'
        );
    }

    /**
     * An explicit value from the caller must still win, or a reset could no
     * longer re-arm the flag it just cleared.
     */
    public function test_explicit_extra_still_overrides_the_carry_forward(): void
    {
        $this->assertStringContainsString(
            "array_key_exists('must_change_password', \$extra)",
            $this->firebaseSrc,
            'Carry-forward must be skipped when the caller sets the flag explicitly '
            . '(every reset site does), which also keeps bulk creation paths free '
            . 'of an extra getUser round trip.'
        );
    }
}
