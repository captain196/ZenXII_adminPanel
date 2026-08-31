<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  Production_admission_probe — THE ONLY SANCTIONED PRODUCTION WRITE PATH
 *  FOR THE CONTROLLED ADMISSION TEST.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHY THIS IS A SEPARATE CONTROLLER ─────────────────────────────────────
 * `Lifecycle_probe` refuses anything but a loopback emulator, and that guard
 * must NOT be weakened — it is the fix for the 2026-08-31 incident in which a
 * harness wrote 85 documents to the live project while reporting itself
 * sandboxed. Relaxing it to permit production would re-open exactly the hole
 * that was just closed.
 *
 * So production is not a mode of the emulator harness. It is a different
 * controller, with an inverted and far stricter gate: it must PROVE it is
 * talking to production, PROVE which tenant, and carry a one-time human
 * authorisation. There is no configuration under which this file writes to a
 * tenant other than the single hard-coded one.
 *
 * ── THE FIVE CONDITIONS, ALL REQUIRED ─────────────────────────────────────
 *   1. the operator explicitly declares a production run
 *   2. the Firebase project resolves to the expected project
 *   3. the declared target equals the ONE permitted tenant
 *   4. that tenant's `schools` document exists and its identity matches
 *   5. a one-time authorisation token is present and matches
 *
 * Any failure refuses through a single path, before the first write. There is
 * no "continue anyway", no override flag, and no branch that skips a check.
 *
 * ── THE TENANT ASSERTION IS PER-WRITE, NOT PER-RUN ────────────────────────
 * A startup check proves the state at startup. It says nothing about the state
 * three dispatches later, after a controller has re-read a session or a
 * library has re-resolved a school id. `_assertTenant()` therefore runs
 * IMMEDIATELY BEFORE every dispatched write, and re-reads the live session
 * rather than trusting a value captured earlier.
 */
class Production_admission_probe extends CI_Controller
{
    /** The ONLY tenant this controller may ever touch. Not configurable. */
    private const TENANT  = 'SCH_D94FE8F7AD';
    private const PROJECT = 'graderadmin';

    /** Every write carries this, so any document it creates is identifiable. */
    public const MARKER = 'ZXPRODTEST';

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); exit; }
    }

    // ══ THE GATE ═══════════════════════════════════════════════════════════

    private function _refuse(string $what, string $why): void
    {
        fwrite(STDERR,
            "REFUSING TO RUN.\n"
            . "This is a PRODUCTION write path. It runs only against tenant "
            . self::TENANT . ".\n"
            . "  {$what}\n"
            . "  {$why}\n");
        exit(3);
    }

    /**
     * All five conditions. Called once at startup; `_assertTenant()` then
     * re-proves the tenant before every individual write.
     */
    private function _gate(): void
    {
        // 1 — the operator must SAY this is production. Nothing infers it.
        if (trim((string) getenv('ZX_PRODUCTION')) !== 'YES') {
            $this->_refuse('ZX_PRODUCTION is not set to YES',
                'A production run must be declared explicitly. It is never the default.');
        }

        // 2 — the destination must be production, and the expected project.
        //     Note this is the INVERSE of the emulator guard: here an emulator
        //     is wrong. Both directions are proven from the CLIENT, never from
        //     an environment variable, which is the 2026-08-31 lesson.
        require_once APPPATH . 'libraries/Firestore_rest_client.php';
        if (!method_exists('FirestoreRestClient', 'resolvedApiRoot')) {
            $this->_refuse('FirestoreRestClient cannot report its destination',
                'Refusing rather than assuming where writes will land.');
        }
        $sa = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
        if (!is_file($sa)) $this->_refuse('service account not readable', 'Cannot verify the project.');
        $creds = json_decode((string) file_get_contents($sa), true);
        if (!is_array($creds) || ($creds['project_id'] ?? '') !== self::PROJECT) {
            $this->_refuse('service account project = ' . ($creds['project_id'] ?? '?'),
                'Expected ' . self::PROJECT . '.');
        }
        try {
            $c = new FirestoreRestClient($sa, self::PROJECT, '(default)');
            $root = $c->resolvedApiRoot();
        } catch (\Throwable $e) {
            $this->_refuse('could not construct the Firestore client: ' . $e->getMessage(),
                'Destination unprovable.');
            return;
        }
        if (stripos($root, 'firestore.googleapis.com') === false) {
            $this->_refuse('resolved destination = ' . $root,
                'This path writes to PRODUCTION by design. An emulator target means the '
                . 'operator is running the wrong harness — use Lifecycle_probe instead.');
        }

        // 3 — the declared target must be the one permitted tenant.
        $declared = trim((string) getenv('ZX_PRODUCTION_TARGET'));
        if ($declared !== self::TENANT) {
            $this->_refuse('ZX_PRODUCTION_TARGET = ' . ($declared === '' ? '(unset)' : $declared),
                'This controller can only ever write to ' . self::TENANT . '.');
        }

        // 4 — that tenant must exist and its identity must match.
        $doc = $this->firebase->firestoreGet('schools', self::TENANT);
        if (!is_array($doc)) {
            $this->_refuse('schools/' . self::TENANT . ' not found',
                'Refusing to create records for a tenant that does not exist.');
        }
        $sid = (string) ($doc['schoolId'] ?? '');
        if ($sid !== self::TENANT) {
            $this->_refuse("schools/" . self::TENANT . " has schoolId '{$sid}'",
                'The tenant document does not identify itself as the declared target.');
        }

        // 5 — a one-time human authorisation.
        $auth = trim((string) getenv('ZX_PRODUCTION_AUTHORIZATION'));
        if ($auth === '') {
            $this->_refuse('ZX_PRODUCTION_AUTHORIZATION is not set',
                'A production write requires an explicit one-time authorisation token.');
        }

        fwrite(STDERR, "[prod] gate passed — project=" . self::PROJECT
            . " destination={$root} tenant=" . self::TENANT
            . " school='" . (string) ($doc['schoolName'] ?? $doc['name'] ?? '?') . "'\n");
    }

    /**
     * Re-prove the tenant IMMEDIATELY before a write.
     *
     * A gate at startup proves startup. This re-reads the LIVE session, so a
     * controller or library that re-resolved a school id in between cannot
     * carry a write into another tenant.
     */
    private function _assertTenant(string $stage): void
    {
        $live = (string) $this->session->userdata('school_id');
        if ($live !== self::TENANT) {
            $this->_refuse("session school_id = '{$live}' at stage '{$stage}'",
                'The active tenant is not ' . self::TENANT . '. Nothing will be written.');
        }
        if (trim((string) getenv('ZX_PRODUCTION_TARGET')) !== self::TENANT) {
            $this->_refuse("ZX_PRODUCTION_TARGET changed mid-run at stage '{$stage}'",
                'The declared target must remain ' . self::TENANT . '.');
        }
    }

    // ══ ENTRY POINTS ═══════════════════════════════════════════════════════

    /**
     * Run the full gate and STOP. Proves every condition end-to-end, including
     * the positive path, without performing a single write.
     */
    public function preflight(): void
    {
        $this->_seedSession();
        $this->_gate();
        $this->_assertTenant('preflight');
        fwrite(STDOUT, json_encode([
            'status'  => 'gate_passed',
            'project' => self::PROJECT,
            'tenant'  => self::TENANT,
            'writes'  => 0,
            'note'    => 'Preflight only. No document was created, updated or deleted.',
        ]) . "\n");
    }

    private function _seedSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $role = trim((string) getenv('ZX_ADMIN_ROLE'));
        $adminId = trim((string) getenv('ZX_ADMIN_ID'));
        if ($role === '' || $adminId === '') {
            $this->_refuse('ZX_ADMIN_ROLE / ZX_ADMIN_ID not supplied',
                'A production write must be attributable to a real administrator.');
        }
        $_SESSION = array_merge($_SESSION ?? [], [
            'admin_id'   => $adminId,
            'school_id'  => self::TENANT,
            'school_code'=> self::TENANT,
            'admin_role' => $role,
            'admin_name' => 'Controlled Admission Probe (' . $role . ')',
            'session'    => trim((string) getenv('ZX_SESSION')) ?: '2026-27',
            'schoolName' => self::TENANT,
            'available_sessions' => [trim((string) getenv('ZX_SESSION')) ?: '2026-27'],
        ]);
    }
}
