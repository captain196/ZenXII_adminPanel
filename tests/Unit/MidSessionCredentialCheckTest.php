<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Mid-session credential enforcement — regression suite.
 *
 * A staff member signs in on BOTH the Teacher app and the admin panel, so an
 * admin password reset has to end both sessions. It ended neither:
 *
 *   - Apps read `mustChangePassword` only in SplashViewModel, i.e. cold start.
 *   - The panel gate (MY_Controller) reads `must_change_password` from the CI
 *     SESSION, seeded once at login and never refreshed. revokeRefreshTokens
 *     cannot touch a session cookie, so a staff member already signed into the
 *     website kept full access INDEFINITELY after their password was reset.
 *
 * OWASP session management (ASVS V3.3) requires other sessions to be invalidated
 * on a credential change; an admin-forced reset is the strongest case, since it
 * is performed specifically to cut off whoever holds the account.
 *
 * These tests pin the panel half. The checks assert on EXECUTABLE code only —
 * both files legitimately describe the old behaviour in comments, so a raw grep
 * would report defects that cannot run.
 */
class MidSessionCredentialCheckTest extends TestCase
{
    private string $controller;
    private string $login;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', true);
        require_once __DIR__ . '/../../application/helpers/force_change_helper.php';
    }

    protected function setUp(): void
    {
        $this->controller = $this->executableCode(__DIR__ . '/../../application/core/MY_Controller.php');
        $this->login      = $this->executableCode(__DIR__ . '/../../application/controllers/Admin_login.php');
    }

    /** Strip comments and docblocks, leaving only code that actually runs. */
    private function executableCode(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /* ── The check exists at all ─────────────────────────────────────────── */

    public function test_panel_rechecks_the_mirror_mid_session(): void
    {
        $this->assertStringContainsString(
            'force_change_profile_collection(',
            $this->controller,
            'MY_Controller must re-read the mustChangePassword mirror mid-session; '
            . 'without it a reset never reaches an established web session.'
        );
    }

    /**
     * The reset endpoints route the mirror through admin_record_collection():
     * STA ids land in `staff`, but legacy ADM ids and SSAs land in `admins`.
     * Reading only `staff` silently missed the School Super Admin — the most
     * privileged school account.
     */
    public function test_mirror_collection_is_not_hardcoded_to_staff(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "/getEntity\(\s*'admins'\s*\)/",
            $this->controller,
            'Collection must be resolved, not hardcoded.'
        );
        // The resolved collection must feed the read.
        $this->assertMatchesRegularExpression(
            '/\$mirrorColl\s*=\s*force_change_profile_collection\(/',
            $this->controller,
            'The mid-session read must resolve its collection from the id prefix.'
        );
    }

    /**
     * PHP treats the STRING "false" as truthy, so !empty() on a Firestore field
     * that round-tripped as a string would force-logout a user whose flag is
     * actually clear. Must use the shared normaliser, which the mobile endpoint
     * also uses — so both surfaces agree on what the value means.
     */
    public function test_flag_is_normalised_not_tested_with_empty(): void
    {
        $this->assertStringContainsString(
            'force_change_truthy($mirrorDoc',
            $this->controller,
            'Mid-session flag must go through force_change_truthy().'
        );
        $this->assertStringNotContainsString(
            "!empty(\$mirrorDoc['mustChangePassword'])",
            $this->controller,
            '!empty() is unsafe here: !empty("false") === true.'
        );
    }

    /** Sanity-check the normaliser actually rejects the string that motivated it. */
    public function test_normaliser_rejects_the_string_false(): void
    {
        $this->assertFalse(force_change_truthy('false'));
        $this->assertFalse(force_change_truthy(''));
        $this->assertFalse(force_change_truthy(null));
        $this->assertTrue(force_change_truthy(true));
        $this->assertTrue(force_change_truthy('true'));
    }

    /**
     * Log out, don't confine. AdminUsers::change_my_password deliberately skips
     * current-password re-auth in the forced flow, so confining a hijacked
     * session to the change-password screen would let whoever holds it set a new
     * password and keep access — defeating the reset entirely.
     */
    public function test_mid_session_detection_forces_logout(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$docMustChange\s*&&\s*!\$this->session->userdata\(\s*.must_change_password.\s*\)/',
            $this->controller,
            'Must skip users who signed in WITH the flag — they are legitimately '
            . 'mid-force-change and must not be kicked out of it.'
        );
        $this->assertStringContainsString(
            '_force_logout(',
            $this->controller,
            'Mid-session detection must end the session, not confine it.'
        );
    }

    /**
     * SESSION_KEYS is documented as the single source of truth for ALL session
     * keys and is what _force_logout clears; omitting the gate flag left it
     * behind on logout.
     */
    public function test_session_keys_include_the_force_change_flag(): void
    {
        $this->assertMatchesRegularExpression(
            "/SESSION_KEYS\s*=\s*\[[^\]]*'must_change_password'/s",
            $this->login,
            'Admin_login::SESSION_KEYS must list must_change_password, or logout '
            . 'and force-logout leave a stale gate flag in the session.'
        );
    }

    /**
     * The mid-session read must reuse the staff doc already fetched for the
     * status check, so the common case costs no extra Firestore read — a
     * per-request check was previously rejected on page-load-smoothness grounds.
     */
    public function test_common_case_adds_no_extra_firestore_read(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$mirrorDoc\s*=\s*\(\$mirrorColl\s*===\s*.staff.\)\s*\?\s*\$staffDoc/',
            $this->controller,
            'For STA ids the mirror lives in `staff`, which was just read for the '
            . 'status check — reuse it rather than reading twice.'
        );
    }

    /**
     * Production layout of web-capable accounts:
     *   STA staff-only 86 | STA staff+admins 2 | ADM staff+admins 5
     *   SSA staff+admins 2 | SSA staff, NO admins 3
     * Those last 3 are why the check must consult BOTH docs: a routed read
     * alone finds nothing for them, so resetting the most privileged school
     * account stayed invisible to an open web session.
     */
    public function test_check_consults_both_staff_and_routed_mirror(): void
    {
        $this->assertMatchesRegularExpression(
            '/force_change_truthy\(\$staffDoc\[.mustChangePassword.\].*\|\|.*force_change_truthy\(\$mirrorDoc\[/s',
            $this->controller,
            'Must OR the staff doc with the routed mirror — 3 production SSAs have '
            . 'a staff doc but no admins doc.'
        );
    }

    /**
     * Ssa_reset used to write its mirror to admins/{id} "only if the doc
     * exists", so for those 3 SSAs it wrote NOWHERE and the reset left no
     * readable trace. The panel authenticates by CI session and cannot read
     * Firebase claims, so the mirror is its only signal.
     */
    public function test_ssa_reset_writes_the_staff_mirror_unconditionally(): void
    {
        $src = $this->executableCode(__DIR__ . '/../../application/libraries/Ssa_reset.php');

        $this->assertMatchesRegularExpression(
            "/set\(\s*'staff'\s*,\s*\\\$this->fs->docId\(\\\$ssa_id\)\s*,\s*\[\s*'mustChangePassword'\s*=>\s*true/",
            $src,
            'Ssa_reset must write the staff mirror unconditionally.'
        );
        // The legacy admins write may stay conditional, but staff must not be.
        $this->assertDoesNotMatchRegularExpression(
            "/if\s*\(!empty\(\\\$existingFs\)\)\s*\{\s*\\\$this->fs->set\(\s*'staff'/",
            $src,
            'The staff mirror write must not be behind a doc-exists guard.'
        );
    }

    /**
     * The SA panel had NO mid-session check at all: sa_must_change_password is
     * seeded once at login, and revokeRefreshTokens cannot touch a CI session
     * cookie, so a developer SA kept full cross-tenant access after a reset.
     */
    public function test_sa_panel_rechecks_mid_session(): void
    {
        $src = $this->executableCode(__DIR__ . '/../../application/core/MY_Superadmin_Controller.php');

        $this->assertStringContainsString(
            "firestoreGet('superAdmins'",
            $src,
            'SA panel must re-read superAdmins/{sa_id} mid-session.'
        );
        $this->assertStringContainsString(
            'sa_pw_check_ts',
            $src,
            'The SA re-check must be throttled — this panel has no periodic block to reuse.'
        );
        $this->assertStringContainsString(
            'force_change_truthy(',
            $src,
            'SA flag must use the shared normaliser, not !empty().'
        );
        // Force logout, not confine — the SA forced-change flow skips
        // current-password re-auth, so confining a hijacked session would let it
        // set a new password and keep cross-tenant access.
        // Whitespace-insensitive: assert the session keys are cleared, not the
        // exact indentation, so a reformat cannot fail this test spuriously.
        $this->assertMatchesRegularExpression(
            "/unset_userdata\(\s*\[\s*'sa_id'/s",
            $src,
            'Detection must clear the SA session (force logout), not merely gate it.'
        );
    }
}
