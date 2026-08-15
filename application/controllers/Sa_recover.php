<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Sa_recover — break-glass recovery for a developer Super Admin (SUP*).
 * CLI ONLY. Not web-routable (no routes.php entry, and is_cli() gates every
 * method), so it adds no network surface.
 *
 * WHY THIS EXISTS
 * SUP0001's "forgot password" flow was the one OTP path left in the system. It
 * posts to the Node auth backend at http://localhost:3000 (see
 * application/config/auth_api.php), and on the production server nothing listens
 * on that port — verified: no process, and AUTH_API_URL is absent from .env. So
 * the platform owner had NO working recovery: forget the password and you are
 * locked out permanently.
 *
 * The project has deliberately rejected self-service OTP/email everywhere else
 * (reset is hierarchical: a higher-privileged user resets a lower one), so
 * reviving that backend to serve one flow would be the wrong fix.
 *
 * There are two recovery routes now, in order of preference:
 *
 *   1. ANOTHER SUPER ADMIN. Superadmin_admins::reset_password() already permits
 *      any active SUP to reset any other, INCLUDING SUP0001 — unlike
 *      toggle_status()/delete(), it carries no isPrimary guard. If a second SUP
 *      account is available, use the Super Admins → Reset password UI. No server
 *      access required.
 *
 *   2. THIS SCRIPT, when no other SUP can be reached — the genuine break-glass.
 *      Requires SSH to the box plus the service-account credential, i.e. the
 *      same trust level as direct database access.
 *
 * USAGE
 *   php index.php sa_recover status SUP0001
 *   SA_RECOVER_CONFIRM=YES_I_AUTHORIZE php index.php sa_recover reset_password SUP0001
 *
 * The new password is read from STDIN, never from argv — an argument would be
 * captured in shell history and visible in `ps` to every user on the box.
 */
class Sa_recover extends CI_Controller
{
    private const CONFIRM_ENV = 'SA_RECOVER_CONFIRM';
    private const CONFIRM_VAL = 'YES_I_AUTHORIZE';

    private const MIN_PW = 8;
    private const MAX_PW = 72;

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); return; }
        $this->load->library('firebase');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  status — read only. Confirms the account exists and is recoverable.
    // ─────────────────────────────────────────────────────────────────────
    public function status($saId = '')
    {
        if (!is_cli()) { show_404(); return; }
        $saId = strtoupper(trim((string) $saId));
        if (!$this->_valid_id($saId)) return;

        $doc = $this->_load_doc($saId);
        if ($doc === null) return;

        echo "=== {$saId} ===\n";
        echo "  name        : " . ($doc['name'] ?? '(none)') . "\n";
        echo "  email       : " . ($doc['email'] ?? '(none)') . "\n";
        echo "  role        : " . ($doc['role'] ?? '(none)') . "\n";
        echo "  status      : " . ($doc['status'] ?? '(none)') . "\n";
        echo "  isPrimary   : " . (!empty($doc['isPrimary']) ? 'yes' : 'no') . "\n";

        try {
            $user = $this->firebase->getFirebaseUser($saId);
            if ($user === null) {
                echo "  firebase    : NO AUTH ACCOUNT — cannot recover by password reset.\n";
            } else {
                $claims = (array) ($user->customClaims ?? []);
                echo "  firebase    : present (uid={$saId})\n";
                echo "  claim role  : " . ($claims['role'] ?? '(none)') . "\n";
            }
        } catch (\Throwable $e) {
            echo "  firebase    : ERROR " . $e->getMessage() . "\n";
        }

        echo "\nTo reset:\n";
        echo "  " . self::CONFIRM_ENV . '=' . self::CONFIRM_VAL
             . " php index.php sa_recover reset_password {$saId}\n";
        echo "Prefer resetting from another Super Admin's panel if one is reachable.\n";
    }

    // ─────────────────────────────────────────────────────────────────────
    //  reset_password — the break-glass action.
    // ─────────────────────────────────────────────────────────────────────
    public function reset_password($saId = '')
    {
        if (!is_cli()) { show_404(); return; }

        if (getenv(self::CONFIRM_ENV) !== self::CONFIRM_VAL) {
            echo "REFUSING: set " . self::CONFIRM_ENV . '=' . self::CONFIRM_VAL . " to confirm.\n";
            return;
        }

        $saId = strtoupper(trim((string) $saId));
        if (!$this->_valid_id($saId)) return;

        $doc = $this->_load_doc($saId);
        if ($doc === null) return;

        // Only ever act on a genuine super-admin record.
        if (strtolower((string) ($doc['role'] ?? '')) !== 'super_admin') {
            echo "REFUSING: superAdmins/{$saId}.role is not 'super_admin'.\n";
            return;
        }
        if ($this->firebase->getFirebaseUser($saId) === null) {
            echo "REFUSING: {$saId} has no Firebase Auth account — nothing to reset.\n";
            return;
        }

        // STDIN, never argv: an argument lands in shell history and `ps`.
        $pw1 = $this->_prompt_hidden("New password for {$saId}: ");
        $pw2 = $this->_prompt_hidden("Confirm password           : ");
        if ($pw1 !== $pw2) { echo "REFUSING: passwords do not match.\n"; return; }

        $err = $this->_validate_pw($pw1);
        if ($err !== null) { echo "REFUSING: {$err}\n"; return; }

        $updated = $this->firebase->updateFirebaseUser($saId, ['password' => $pw1]);
        if ($updated === null) {
            echo "FAILED: Firebase Auth rejected the password update.\n";
            return;
        }

        // Boot every other live session for this account — a recovery implies the
        // old credential is no longer trusted.
        try { $this->firebase->revokeRefreshTokens($saId); } catch (\Throwable $e) {
            echo "WARNING: token revoke failed: " . $e->getMessage() . "\n";
        }

        // Deliberately NOT setting mustChangePassword: the operator has just
        // chosen this password themselves, and setting the flag would drop them
        // onto the forced-change gate in MY_Superadmin_Controller for no reason.

        try {
            $this->firebase->firestoreSet('superAdmins', $saId, [
                'accessHistory' => ['lastPasswordRecoveryAt' => date('c')],
                'updatedAt'     => date('c'),
            ], true);
        } catch (\Throwable $e) {
            echo "WARNING: audit stamp failed: " . $e->getMessage() . "\n";
        }

        log_message('info', "Sa_recover: break-glass password reset performed for {$saId} (CLI)");

        echo "\nOK — password reset for {$saId}.\n";
        echo "All existing sessions for this account have been revoked.\n";
        echo "Sign in at /superadmin/login with the new password.\n";
    }

    // =====================================================================
    //  helpers
    // =====================================================================

    private function _valid_id(string $saId): bool
    {
        if (!preg_match('/^SUP\d+$/', $saId)) {
            echo "Usage: php index.php sa_recover <status|reset_password> SUP0001\n";
            return false;
        }
        return true;
    }

    /** Load superAdmins/{id}. SUP docs are flat — never school-prefixed. */
    private function _load_doc(string $saId): ?array
    {
        try {
            $doc = $this->firebase->firestoreGet('superAdmins', $saId);
        } catch (\Throwable $e) {
            echo "ERROR reading superAdmins/{$saId}: " . $e->getMessage() . "\n";
            return null;
        }
        if (!is_array($doc) || empty($doc)) {
            echo "NOT FOUND: superAdmins/{$saId}\n";
            return null;
        }
        return $doc;
    }

    /** Same policy the SA panel enforces (Superadmin::change_my_password). */
    private function _validate_pw(string $pw): ?string
    {
        if (strlen($pw) < self::MIN_PW || strlen($pw) > self::MAX_PW) {
            return 'password must be ' . self::MIN_PW . '-' . self::MAX_PW . ' characters.';
        }
        if (!preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
            return 'password needs an uppercase letter, a lowercase letter and a digit.';
        }
        return null;
    }

    /**
     * Read a line from STDIN with terminal echo suppressed where possible.
     *
     * Every shell string here is a fixed literal — nothing is interpolated. An
     * earlier draft restored the terminal with `stty $(stty -g)`, which fed
     * command output back into a shell string; it is replaced with the fixed
     * `stty echo` so no dynamic value ever reaches the shell.
     */
    private function _prompt_hidden(string $label): string
    {
        echo $label;
        $canHide = (stripos(PHP_OS, 'WIN') !== 0) && (bool) @shell_exec('command -v stty');
        if ($canHide) { @shell_exec('stty -echo'); }

        $line = fgets(STDIN);

        if ($canHide) { @shell_exec('stty echo'); echo "\n"; }
        return rtrim((string) $line, "\r\n");
    }
}
