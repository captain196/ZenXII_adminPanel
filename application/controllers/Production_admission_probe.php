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
 *   5. a SINGLE-USE authorisation ticket, minted on this machine, is
 *      present and is bound to this tenant, this stage and this plan
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
    private function _gate(string $stage): void
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

        // 5 — a SINGLE-USE authorisation TICKET, bound to tenant and stage.
        $this->_consumeTicket($stage);

        fwrite(STDERR, "[prod] gate passed — project=" . self::PROJECT
            . " destination={$root} tenant=" . self::TENANT
            . " school='" . (string) ($doc['schoolName'] ?? $doc['name'] ?? '?') . "'\n");
    }

    /**
     * ── CONDITION 5: THE SINGLE-USE AUTHORISATION TICKET ──────────────────
     *
     * The earlier design read a shared secret from `ZX_PRODUCTION_AUTHORIZATION`.
     * That is wrong in three ways: the secret has to travel to whoever runs the
     * command (so it gets pasted into a chat, a note, or a shell history), it
     * authorises EVERY stage rather than one, and it stays valid forever.
     *
     * Instead, the authorising act is a human running `tools/prod_ticket.php`
     * ON THE MACHINE THAT WILL DO THE WRITE. That command mints a ticket file
     * containing a locally-generated nonce. The nonce never has to be seen,
     * transmitted, quoted or stored anywhere else — nobody, including this
     * conversation, ever needs to know its value. There is nothing to leak.
     *
     * A ticket is:
     *   • BOUND to one tenant          — a ticket cannot move to another school
     *   • BOUND to one stage           — `admit` cannot authorise `collect`
     *   • BOUND to one plan digest     — the approved manifest, hashed; if the
     *                                    plan changes, every ticket is void
     *   • TIME-LIMITED                 — default 30 minutes
     *   • SINGLE-USE                   — consumed (deleted) BEFORE the write, so
     *                                    a crash, retry or re-run cannot reuse it
     *
     * Consume-before-write is deliberate. Deleting after a successful write
     * would leave a live ticket behind whenever the write fails midway, which
     * is exactly the moment a re-run is most tempting and least safe.
     */
    private function _consumeTicket(string $stage): void
    {
        $dir = $this->_ticketDir();
        $file = $dir . DIRECTORY_SEPARATOR . self::TENANT . '.' . $stage . '.ticket.json';

        if (!is_file($file)) {
            $this->_refuse("no authorisation ticket for stage '{$stage}'",
                'Mint one on this machine with: php tools/prod_ticket.php issue ' . $stage
                . '   (the ticket never leaves the machine; its value is never displayed'
                . ' in full and must never be quoted anywhere).');
        }

        $t = json_decode((string) @file_get_contents($file), true);
        if (!is_array($t)) {
            @unlink($file);
            $this->_refuse('the authorisation ticket is unreadable',
                'A malformed ticket is treated as forged. It has been discarded; mint a new one.');
        }

        // Bindings. Each is a refusal, never a warning.
        if ((string) ($t['tenant'] ?? '') !== self::TENANT) {
            $this->_refuse('ticket tenant = ' . (string) ($t['tenant'] ?? '(none)'),
                'The ticket authorises a different tenant. It will not be consumed.');
        }
        if ((string) ($t['stage'] ?? '') !== $stage) {
            $this->_refuse("ticket stage = '" . (string) ($t['stage'] ?? '(none)') . "', run stage = '{$stage}'",
                'Authorisation is per stage. One approval never covers a different operation.');
        }
        if ((string) ($t['planDigest'] ?? '') !== $this->_planDigest()) {
            $this->_refuse('ticket plan digest does not match the approved manifest',
                'The execution plan changed after this ticket was issued. Every outstanding '
                . 'ticket is void; the new plan must be approved and a new ticket minted.');
        }
        $exp = (int) ($t['expiresAt'] ?? 0);
        if ($exp <= 0 || $exp < time()) {
            @unlink($file);
            $this->_refuse('the authorisation ticket has expired',
                'Tickets are short-lived so an approval cannot be replayed later. Expired '
                . 'ticket discarded; mint a new one at the moment of execution.');
        }
        if (strlen((string) ($t['nonce'] ?? '')) < 32) {
            $this->_refuse('the authorisation ticket has no usable nonce',
                'Refusing rather than accepting a weak or hand-written ticket.');
        }

        // CONSUME FIRST. From this point the ticket cannot authorise anything again.
        if (!@unlink($file)) {
            $this->_refuse('the authorisation ticket could not be consumed',
                'A ticket that cannot be deleted could be replayed. Refusing before any write.');
        }

        // Append-only local ledger. Records THAT a ticket was used, never its value.
        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'consumed.log',
            sprintf("%s\ttenant=%s\tstage=%s\tissuedBy=%s\tticket=%s\n",
                gmdate('c'), self::TENANT, $stage,
                (string) ($t['issuedBy'] ?? '?'),
                substr(hash('sha256', (string) $t['nonce']), 0, 12)),
            FILE_APPEND
        );

        fwrite(STDERR, "[prod] ticket consumed — stage={$stage} issuedBy="
            . (string) ($t['issuedBy'] ?? '?') . " (single-use; a re-run needs a new ticket)\n");
    }

    /** Tickets live OUTSIDE the repository, so one can never be committed. */
    private function _ticketDir(): string
    {
        $home = getenv('USERPROFILE') ?: (getenv('HOME') ?: sys_get_temp_dir());
        return rtrim($home, "\\/") . DIRECTORY_SEPARATOR . '.zenxii'
            . DIRECTORY_SEPARATOR . 'prod_tickets';
    }

    /**
     * The digest of the approved execution plan.
     *
     * Binding tickets to this means an approval covers the plan that was
     * actually reviewed. Editing the manifest — adding a write, changing an
     * amount — invalidates every outstanding ticket automatically, instead of
     * relying on someone remembering to revoke them.
     */
    private function _planDigest(): string
    {
        $plan = FCPATH . 'PRODUCTION_ADMISSION_MANIFEST.md';
        return is_file($plan) ? hash('sha256', (string) file_get_contents($plan)) : 'NO_PLAN';
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
        $this->_gate('preflight');
        $this->_assertTenant('preflight');
        fwrite(STDOUT, json_encode([
            'status'  => 'gate_passed',
            'project' => self::PROJECT,
            'tenant'  => self::TENANT,
            'writes'  => 0,
            'note'    => 'Preflight only. No document was created, updated or deleted.',
        ]) . "\n");
    }

    // ══ EXECUTION STAGES ═══════════════════════════════════════════════════

    /**
     * Run exactly ONE approved stage, then stop.
     *
     * One stage per process, deliberately. `json_success()`/`json_error()` both
     * `exit`, so an in-process sequence would end at the first stage anyway —
     * but the stronger reason is that each stage must be independently
     * authorised, independently verified against persisted state, and reported
     * before the next one is even considered. A single process that ran the
     * whole chain could not be stopped between stages, which is precisely the
     * control this run exists to keep.
     *
     * Every stage re-runs the full five-condition gate (consuming its own
     * ticket) and re-asserts the tenant immediately before dispatching.
     */
    /**
     * THE ONE SOURCE OF STAGE PARAMETERS.
     *
     * `stage()` (which writes) and `verify()` (which does not) both build their
     * work from this method, so a dry run cannot drift from the real run: there
     * is no second copy of the plan to fall out of step. The values are fixed
     * here and are NOT readable from the environment — an approved plan that a
     * flag could alter is not an approved plan.
     *
     * @return array{controller:string,method:string,post:array}
     */
    private function _plan(string $stage): array
    {
        switch ($stage) {
            case 'seed_coa':
                return ['controller' => 'accounting', 'method' => 'seed_default_chart', 'post' => []];

            case 'add_section':
                return ['controller' => 'classes', 'method' => 'add_section', 'post' => [
                    'class_name'   => '5',
                    'section_name' => 'A',
                    'max_strength' => '40',
                ]];

            case 'fee_chart':
                $months = ['April','May','June','July','August','September',
                           'October','November','December','January','February','March'];
                $chart = [];
                foreach ($months as $m) {
                    $chart[$m] = ['Tuition Fee' => 1000, 'Transport Fee' => 500];
                }
                // The CANONICAL ordinal class name. `Classes::add_section`
                // normalises 5 to 'Class 5th', while `Fees::save_updated_fees`
                // stores what it is given, so a chart saved as 'Class 5' is
                // orphaned and the admission is then refused for having no fee
                // structure. Proven in rehearsal.
                return ['controller' => 'fees', 'method' => 'save_updated_fees', 'post' => [
                    'class'   => 'Class 5th',
                    'section' => 'Section A',
                    'fees'    => json_encode($chart),
                ]];

            case 'admit':
                return ['controller' => 'sis', 'method' => 'save_admission', 'post' => [
                    'name'         => self::MARKER . ' Controlled UAT',
                    'class'        => 'Class 5th',
                    'section'      => 'Section A',
                    // A date of birth is what makes the generated password reach
                    // Firebase's six-character minimum, so this is also what
                    // makes the Auth account real rather than silently refused.
                    'dob'          => '2015-04-12',
                    'phone_number' => '9000000190',
                    'phone'        => '9000000190',
                    'email'        => '',
                    // The genuine configured admission fee, collected in cash.
                    // Cash needs no bank ledger; a non-cash mode would also
                    // require an active bank account on the chart.
                    'admission_payment_mode' => 'cash',
                ]];
        }
        $this->_refuse("unknown stage '{$stage}'", 'There is no plan for it.');
        return [];
    }

    /** Stage names, in the order they must run. Nothing else is dispatchable. */
    private const STAGES = ['seed_coa', 'add_section', 'fee_chart', 'admit'];

    // ══ EXECUTION STAGES ═══════════════════════════════════════════════════

    /**
     * Run exactly ONE approved stage, then stop.
     *
     * One stage per process, deliberately. `json_success()`/`json_error()` both
     * `exit`, so an in-process sequence would end at the first stage anyway —
     * but the stronger reason is that each stage must be independently
     * authorised, independently verified against persisted state, and reported
     * before the next is even considered. A single process running the whole
     * chain could not be stopped between stages, which is precisely the control
     * this run exists to keep. That is also why a failing stage needs no
     * "halt" branch: the endpoint exits, the process ends, and the next stage
     * cannot start because it needs a fresh ticket only a human can mint.
     */
    public function stage($name = ''): void
    {
        $this->_orchestrate($name, true);
    }

    /**
     * Prove the production orchestration WITHOUT writing.
     *
     * Everything the real run does, runs: the same session seed, the same
     * five-condition gate, the same single-use ticket consumption, the same
     * per-write tenant re-assertion, the same stage table and the same
     * `_plan()`. Only the final call into the application is withheld, and the
     * plan is printed instead. That keeps the side-effect boundary — one line —
     * as the only difference between a proof and a run, rather than testing a
     * second implementation that could drift from it.
     *
     * It talks to PRODUCTION, deliberately: the destination proof, the tenant
     * document check and the ticket semantics are only meaningful against the
     * real project. It performs reads only.
     */
    public function verify($name = ''): void
    {
        $this->_orchestrate($name, false);
    }

    private function _orchestrate(string $name, bool $write): void
    {
        if (!in_array($name, self::STAGES, true)) {
            $this->_refuse("unknown stage '{$name}'",
                'Approved stages, in order: ' . implode(' -> ', self::STAGES) . '.');
        }

        $this->_seedSession();
        $this->_gate($name);          // five conditions, incl. this stage's single-use ticket
        $this->_assertTenant($name);  // re-reads the LIVE session, immediately before the write

        $plan = $this->_plan($name);

        if (!$write) {
            fwrite(STDOUT, json_encode([
                'mode'       => 'verify',
                'stage'      => $name,
                'order'      => array_search($name, self::STAGES, true) + 1,
                'of'         => count(self::STAGES),
                'tenant'     => self::TENANT,
                'project'    => self::PROJECT,
                'actor'      => (string) $this->session->userdata('admin_id'),
                'role'       => (string) $this->session->userdata('admin_role'),
                'session'    => (string) $this->session->userdata('session'),
                'planDigest' => substr($this->_planDigest(), 0, 12),
                'dispatch'   => $plan['controller'] . '::' . $plan['method'],
                'post'       => $plan['post'],
                'writes'     => 0,
                'note'       => 'Gate, ticket, tenant binding and plan all exercised. '
                              . 'The dispatch was withheld; nothing was written.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            return;
        }

        require_once APPPATH . 'libraries/Probe_dispatch.php';
        Probe_dispatch::run($plan['controller'], $plan['method'], $plan['post']);
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
