<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * School_recover — break-glass recovery for a tenant that has no active
 * academic session. CLI ONLY, not web-routable (no routes.php entry, is_cli()
 * gates every method).
 *
 * WHY THIS EXISTS
 * Admin_login is deliberately FAIL-CLOSED on the session: if
 * schools/{schoolId}.currentSession is absent or empty it wipes the partial
 * session and refuses the login (see _hydrate_admin_session_from_school —
 * "SC-Step10/G1 ... NO sessions[0] fallback"). That is the right call: silently
 * activating a non-canonical session would scope every query to the wrong year.
 *
 * But it creates a DEADLOCK. The only place that can set currentSession for an
 * existing school is School_config (school panel) — which requires a login that
 * the missing session blocks. Superadmin_schools mentions currentSession only in
 * onboarding comments; B2_registry_service::create_tenant seeds it once at
 * tenant creation and never again. So if a school's session is ever cleared,
 * EVERY user of that tenant is locked out and there is no in-product escape —
 * not for the school super admin, not for the platform owner.
 *
 * No tenant is in that state today. This exists so that if one ever is, recovery
 * is one command rather than a manual Firestore edit.
 *
 * USAGE
 *   php index.php school_recover status SCH_B56BB9A401
 *   SCHOOL_RECOVER_CONFIRM=YES_I_AUTHORIZE \
 *     php index.php school_recover set_session SCH_B56BB9A401 2026-27
 *
 * The session must already exist in the school's sessions[] array — this
 * ACTIVATES a known session, it never invents one, so it cannot introduce a
 * year that the rest of the system has no data for.
 */
class School_recover extends CI_Controller
{
    private const CONFIRM_ENV = 'SCHOOL_RECOVER_CONFIRM';
    private const CONFIRM_VAL = 'YES_I_AUTHORIZE';

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); return; }
        $this->load->library('firebase');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  status — read only.
    // ─────────────────────────────────────────────────────────────────────
    public function status($schoolId = '')
    {
        if (!is_cli()) { show_404(); return; }
        $schoolId = trim((string) $schoolId);
        if (!$this->_valid_id($schoolId)) return;

        $doc = $this->_load($schoolId);
        if ($doc === null) return;

        $current  = trim((string) ($doc['currentSession'] ?? ''));
        $sessions = is_array($doc['sessions'] ?? null) ? $doc['sessions'] : [];

        echo "=== {$schoolId} ===\n";
        echo "  name           : " . ($doc['name'] ?? $doc['schoolName'] ?? '(none)') . "\n";
        echo "  currentSession : " . ($current !== '' ? $current : '(EMPTY — all logins for this tenant are blocked)') . "\n";
        echo "  sessions[]     : " . (empty($sessions) ? '(none)' : implode(', ', $sessions)) . "\n";

        if ($current === '') {
            echo "\nThis tenant is DEADLOCKED: nobody can sign in, so nobody can fix it from the panel.\n";
            if (!empty($sessions)) {
                $suggest = $sessions; rsort($suggest);
                echo "Recover with:\n";
                echo '  ' . self::CONFIRM_ENV . '=' . self::CONFIRM_VAL
                     . " php index.php school_recover set_session {$schoolId} {$suggest[0]}\n";
            } else {
                echo "sessions[] is also empty — the tenant was never fully onboarded.\n";
                echo "Re-run onboarding rather than forcing a session here.\n";
            }
        } else {
            echo "\nHealthy — no action needed.\n";
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  set_session — activate an EXISTING session for the tenant.
    // ─────────────────────────────────────────────────────────────────────
    public function set_session($schoolId = '', $session = '')
    {
        if (!is_cli()) { show_404(); return; }

        if (getenv(self::CONFIRM_ENV) !== self::CONFIRM_VAL) {
            echo "REFUSING: set " . self::CONFIRM_ENV . '=' . self::CONFIRM_VAL . " to confirm.\n";
            return;
        }

        $schoolId = trim((string) $schoolId);
        $session  = trim((string) $session);
        if (!$this->_valid_id($schoolId)) return;
        if ($session === '' || !preg_match('/^[0-9]{4}-[0-9]{2,4}$/', $session)) {
            echo "REFUSING: session must look like 2026-27 (got '{$session}').\n";
            return;
        }

        $doc = $this->_load($schoolId);
        if ($doc === null) return;

        // Only ACTIVATE a session the tenant already knows about. Inventing one
        // would scope every subsequent query to a year with no data — worse than
        // the deadlock it is meant to fix.
        $sessions = is_array($doc['sessions'] ?? null) ? array_values($doc['sessions']) : [];
        if (!in_array($session, $sessions, true)) {
            echo "REFUSING: '{$session}' is not in this school's sessions[].\n";
            echo "  known: " . (empty($sessions) ? '(none)' : implode(', ', $sessions)) . "\n";
            echo "  Add the session through the panel first, or re-run onboarding.\n";
            return;
        }

        $before = trim((string) ($doc['currentSession'] ?? ''));
        try {
            $this->firebase->firestoreSet('schools', $schoolId, [
                'currentSession' => $session,
                'updatedAt'      => date('c'),
            ], true);
        } catch (\Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
            return;
        }

        log_message('info',
            "School_recover: currentSession for {$schoolId} set to '{$session}' (was '{$before}') via CLI break-glass");

        echo "OK — {$schoolId}.currentSession: '" . ($before !== '' ? $before : '(empty)') . "' -> '{$session}'\n";
        echo "Users of this tenant can sign in again.\n";
    }

    // =====================================================================

    private function _valid_id(string $schoolId): bool
    {
        if (!preg_match('/^SCH_[A-Za-z0-9]+$/', $schoolId)) {
            echo "Usage: php index.php school_recover <status|set_session> SCH_XXXXXXXX [session]\n";
            return false;
        }
        return true;
    }

    private function _load(string $schoolId): ?array
    {
        try {
            $doc = $this->firebase->firestoreGet('schools', $schoolId);
        } catch (\Throwable $e) {
            echo "ERROR reading schools/{$schoolId}: " . $e->getMessage() . "\n";
            return null;
        }
        if (!is_array($doc) || empty($doc)) {
            echo "NOT FOUND: schools/{$schoolId}\n";
            return null;
        }
        return $doc;
    }
}
